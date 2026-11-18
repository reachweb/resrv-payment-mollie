<?php

namespace Reach\ResrvPaymentMollie\Http\Payment;

use Composer\CaBundle\CaBundle;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\MollieException;
use Mollie\Api\Http\LinearRetryStrategy;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;

class MolliePaymentGateway implements PaymentInterface
{
    use HandlesStatamicQueries;

    public function name(): string
    {
        return 'mollie';
    }

    public function label(): string
    {
        return 'Mollie';
    }

    public function paymentView(): string
    {
        // Redirect gateway — this view is never rendered.
        return 'statamic-resrv::livewire.checkout-payment';
    }

    public function supportsManualConfirmation(): bool
    {
        return false;
    }

    public function supportsAutomaticRefunds(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function redirectsForPayment(): bool
    {
        return true;
    }

    /**
     * Every Mollie call goes through a fresh client with short timeouts and no automatic retries.
     * refund() runs inside the REFUNDED transition's row lock, and mollie-api-php's defaults
     * (5 retries with growing sleeps, on top of a 30 s HTTP timeout through laravel-mollie) could
     * hold that lock for minutes. A fresh client per call also keeps a per-request idempotency
     * key from outliving the request it was set for. Mirrors StripePaymentGateway::getClient().
     */
    protected function client(?Reservation $reservation = null): MollieApiClient
    {
        $client = new MollieApiClient(app(Client::class, ['config' => [
            RequestOptions::VERIFY => CaBundle::getBundledCaBundlePath(),
            RequestOptions::TIMEOUT => 15,
            RequestOptions::CONNECT_TIMEOUT => 5,
            RequestOptions::HTTP_ERRORS => false,
        ]]));

        $client->setRetryStrategy(new LinearRetryStrategy(0, 0));

        if ($key = $this->getSecretKey($reservation)) {
            $client->setToken($key);
        }

        return $client;
    }

    public function paymentIntent($payment, Reservation $reservation, $data, ?string $returnUrl = null)
    {
        $molliePayment = $this->client($reservation)->payments->create([
            'amount' => [
                'currency' => config('resrv-config.currency_isoCode'),
                // Mollie expects a string with the currency's exact number of decimals
                // and no thousands separator — exactly what Price::format() produces.
                'value' => $payment->format(),
            ],
            'description' => $this->paymentDescription($reservation),
            // $returnUrl lets non-checkout surfaces (the manual-reservation pay-by-link page)
            // send the customer back to their own page; null falls back to the checkout-complete
            // entry, so normal checkout is unchanged. See UPGRADE-PAYMENT-GATEWAYS.md Step 12.
            'redirectUrl' => $this->redirectBackUrl($reservation, $returnUrl),
            'webhookUrl' => route('resrv.webhook.gateway.store', ['gateway' => $this->configKey()]),
            'metadata' => [
                // Critical for the stale-intent fallback in verifyPayment(): payment_id is
                // cleared before cancelPaymentIntent() runs, so a racing webhook can only
                // find the reservation through this.
                'reservation_id' => $reservation->id,
            ],
        ]);

        $paymentIntent = new \stdClass;
        $paymentIntent->id = $molliePayment->id;
        $paymentIntent->client_secret = '';
        $paymentIntent->redirectTo = $molliePayment->getCheckoutUrl();

        return $paymentIntent;
    }

    /**
     * Fetch a previously created payment so the pay-by-link page can resume it instead of
     * minting (and risking double-charging) a second one. Returns null only when the payment
     * is definitely gone (404) — the caller then creates a fresh intent. Every transient
     * failure (timeout, 429, 5xx, auth) propagates so a brownout on this read can never
     * orphan a still-payable Mollie payment behind a replacement.
     *
     * The returned object mirrors the intent shape the resume flow reads: a Stripe-style
     * ->status (see normalizeIntentStatus) plus ->redirectTo (the hosted checkout URL) so a
     * resumable payment can send the customer back to Mollie.
     */
    public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
    {
        try {
            $payment = $this->client($reservation)->payments->get($paymentId);
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }

        $intent = new \stdClass;
        $intent->id = $payment->id;
        $intent->status = $this->normalizeIntentStatus($payment->status);
        $intent->client_secret = '';
        // A resumable payment that Mollie no longer exposes a checkout URL for is NOT "gone" —
        // returning null here would let Resrv mint a replacement without voiding this one,
        // leaving it payable at Mollie. Reporting it with redirectTo = null makes Resrv void
        // it first and then mint the replacement (see resumedIntentIsMountable in core).
        $intent->redirectTo = $payment->getCheckoutUrl();

        return $intent;
    }

    /**
     * Map Mollie's payment status onto the Stripe-style vocabulary Resrv reads (Step 13):
     * 'succeeded'/'processing' mean money was captured or is settling, 'requires_capture' is
     * an authorization that holds money without collecting it (Resrv voids it instead of
     * refunding it), 'canceled' means dead (remint), anything else (i.e. 'open') is resumable.
     */
    protected function normalizeIntentStatus(string $status): string
    {
        return match ($status) {
            'paid' => 'succeeded',
            'pending' => 'processing',
            'authorized' => 'requires_capture',
            'canceled', 'expired', 'failed' => 'canceled',
            default => $status, // 'open' — resumable via the hosted checkout URL
        };
    }

    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
    {
        try {
            $client = $this->client($reservation);
            $payment = $client->payments->get($paymentId);

            // An authorization is voided by releasing it; the payment then becomes canceled.
            // Most open Mollie payments are not cancelable at all (they simply expire), so cancel
            // only when the API says we can. Best-effort: a webhook race is handled by the
            // stale-intent fallback in verifyPayment().
            if ($payment->status === 'authorized') {
                $client->payments->releaseAuthorization($paymentId);
            } elseif ($payment->isCancelable) {
                $client->payments->cancel($paymentId);
            }
        } catch (MollieException $e) {
            Log::warning('Failed to cancel Mollie payment: '.$e->getMessage(), [
                'payment_id' => $paymentId,
                'reservation_id' => $reservation->id,
            ]);
        }
    }

    public function refund($reservation)
    {
        $client = $this->client($reservation);

        try {
            $payment = $client->payments->get($reservation->payment_id);

            // A retry after a dropped connection — or an out-of-band dashboard refund —
            // finds the charge already fully refunded: the money is where the caller wants
            // it, so reconcile to success instead of failing the transition forever.
            if ($payment->amountRefunded !== null && $payment->amountRefunded->value === $payment->amount->value) {
                return true;
            }

            if (! $payment->canBeRefunded()) {
                throw new RefundFailedException('This payment cannot be refunded.');
            }

            // Stable per-(reservation, payment) idempotency key: if the connection drops
            // after Mollie processed the refund, a retry within Mollie's idempotency window
            // replays the original response instead of issuing a second refund. Past that
            // window the full-refund reconciliation above takes over.
            $client->setIdempotencyKey('resrv-refund-'.$reservation->id.'-'.$reservation->payment_id);

            // Refund the full charge (payment + surcharge) in the currency it was made in —
            // customer self-cancellation promises the customer exactly this amount.
            return $client->payments->refund($payment, [
                'amount' => [
                    'currency' => $payment->amount->currency,
                    'value' => $payment->amount->value,
                ],
            ]);
        } catch (MollieException $exception) {
            // Every Mollie failure mode (invalid request, connection, auth, rate limit) must
            // surface as RefundFailedException so callers roll back the REFUNDED transition
            // and show their refund-failed message instead of a 500.
            throw new RefundFailedException($exception->getMessage());
        }
    }

    public function handleRedirectBack(): array
    {
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        $reservationId = request()->input('id');

        // The id is sequential and comes from the URL: only a signed return URL (or the browser
        // whose checkout session holds this reservation) may see the reservation or its status.
        $reservation = is_scalar($reservationId) && $this->returnIsAuthorized((string) $reservationId)
            ? Reservation::find($reservationId)
            : null;

        // Reservation gone, or its intent was cleared by Checkout::cancelActiveIntent
        // (abandoned checkout, gateway switch) — show the failure message; the webhook
        // owns reconciliation of any charge that still lands.
        if (! $reservation || empty($reservation->payment_id)) {
            return [
                'status' => false,
                'reservation' => [],
            ];
        }

        try {
            $client = $this->client($reservation);
            $payment = $client->payments->get($reservation->payment_id);

            // A payment the customer has just completed can still read open for a moment, as
            // when a PayPal customer clicks "Return to Merchant". Mollie advises checking again
            // after a short interval, so look twice more before reporting it as not paid. Only
            // the first return for a payment waits: a refresh, or anyone repeating the request,
            // must not hold a worker for seconds every time.
            if ($this->leftUnpaid($payment) && Cache::add('resrv-mollie-return-recheck:'.$payment->id, true, now()->addMinutes(15))) {
                for ($recheck = 0; $recheck < 2 && $this->leftUnpaid($payment); $recheck++) {
                    Sleep::for(1500)->milliseconds();
                    $payment = $client->payments->get($reservation->payment_id);
                }
            }
        } catch (MollieException $e) {
            Log::warning('Mollie redirect back: unable to fetch payment: '.$e->getMessage(), [
                'payment_id' => $reservation->payment_id,
                'reservation_id' => $reservation->id,
            ]);

            return [
                'status' => false,
                'reservation' => $reservation->toArray(),
            ];
        }

        if ($payment->isPaid()) {
            return [
                'status' => true,
                'reservation' => $reservation->toArray(),
            ];
        }

        // Still open: the customer left Mollie without paying. Report it as not paid so they can
        // try again; on the pay-by-link page, Pay resumes this same payment. The payment stays
        // payable until Mollie expires it. If it is still paid, verifyPayment() confirms the
        // reservation while its hold lasts and reports an orphaned payment once it has expired.
        if ($this->leftUnpaid($payment)) {
            return [
                'status' => false,
                'reservation' => $reservation->toArray(),
            ];
        }

        // The payment is still underway (a payment made at the customer's bank or an ATM, a
        // pending payment, or an authorization) — confirmation arrives via webhook.
        if (in_array($payment->status, ['open', 'pending', 'authorized'], true)) {
            return [
                'status' => 'pending',
                'reservation' => $reservation->toArray(),
            ];
        }

        return [
            'status' => false,
            'reservation' => $reservation->toArray(),
        ];
    }

    /**
     * Whether an open payment means the customer left Mollie without paying. A payment created
     * without a fixed method stays open after a failed or canceled attempt: Mollie sends the
     * customer back to its method page and calls no webhook. That holds for every method that
     * completes while the customer is at Mollie (cards, iDEAL, PayPal…) and for a payment with
     * no method chosen yet. Bank transfer, Multibanco and Pay by Bank complete at the
     * customer's bank or an ATM after they leave Mollie, so their payments can still be open.
     */
    protected function leftUnpaid(Payment $payment): bool
    {
        return $payment->status === 'open'
            && ! in_array($payment->method, ['banktransfer', 'multibanco', 'paybybank'], true);
    }

    public function handlePaymentPending(): bool|array
    {
        if (! request()->has('payment_pending')) {
            return false;
        }

        $reservation = Reservation::find(request()->input('payment_pending'));

        return [
            'status' => 'pending',
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }

    public function verifyPayment($request)
    {
        $paymentId = $request->input('id');

        if (! is_string($paymentId) || $paymentId === '') {
            abort(400);
        }

        // Mollie webhooks carry no payload or signature — only the payment id. Authenticity
        // comes from fetching the payment from the API; everything below acts on that.
        try {
            $payment = $this->client()->payments->get($paymentId);
        } catch (MollieException $e) {
            Log::warning('Mollie webhook: unable to fetch payment: '.$e->getMessage(), [
                'payment_id' => $paymentId,
            ]);

            // Non-2xx makes Mollie retry later — right for transient API failures, harmless
            // for bogus ids (which Mollie never sent in the first place).
            abort(404);
        }

        $reservation = Reservation::findByPaymentId($payment->id)->first();

        // Checkout::cancelActiveIntent clears payment_id before the provider-side cancel, so
        // a racing webhook can only find the reservation via the metadata stashed at creation.
        // (order_id covers payments created by pre-v2 releases of this addon.)
        $isStaleIntent = false;
        if (! $reservation) {
            $metadata = (array) ($payment->metadata ?? []);
            $metadataReservationId = $metadata['reservation_id'] ?? $metadata['order_id'] ?? null;

            if ($metadataReservationId) {
                $reservation = Reservation::find($metadataReservationId);
                $isStaleIntent = (bool) $reservation;
            }
        }

        if (! $reservation) {
            Log::info('Mollie webhook: no reservation found for payment '.$payment->id);

            return response()->json([], 200);
        }

        // Mollie fires this same webhook when a refund or chargeback is created. Refunds are
        // initiated and reconciled by Resrv itself (CP or customer self-cancellation), so
        // acknowledge without acting — otherwise the isPaid() branch below would treat a
        // legitimately refunded reservation as an orphaned charge.
        if ($payment->hasRefunds() || $payment->hasChargebacks()) {
            Log::info('Mollie webhook: refund/chargeback event acknowledged.', [
                'payment_id' => $payment->id,
                'reservation_id' => $reservation->id,
            ]);

            return response()->json([], 200);
        }

        // Stale intent: the customer moved on (back button, gateway switch) before this payment
        // completed. Confirming would attach a charge to an amount or gateway the reservation is
        // no longer tied to — notify admins for manual refund instead. A booking another payment
        // has confirmed since makes this one a second charge, so it alerts too.
        if ($payment->isPaid() && $isStaleIntent) {
            Log::warning('Mollie payment succeeded after being abandoned by the customer — manual reconciliation may be required.', [
                'reservation_id' => $reservation->id,
                'payment_id' => $payment->id,
                'current_payment_id' => $reservation->payment_id,
                'current_payment_gateway' => $reservation->payment_gateway,
            ]);

            OrphanedPaymentNotification::dispatchFor($reservation, $payment->id);

            return response()->json([], 200);
        }

        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            return response()->json([], 200);
        }

        if ($payment->isPaid()) {
            // Terminal reservation (expired/refunded/partner): the charge can't attach to a
            // live booking — notify and stop.
            if (OrphanedPaymentNotification::notifyIfOrphaned($reservation, $payment->id)) {
                return response()->json([], 200);
            }

            // Defense-in-depth: refuse to confirm if the charge no longer matches what's owed.
            $expectedAmount = $reservation->totalToCharge();
            $expectedCurrency = config('resrv-config.currency_isoCode');

            if ($payment->amount->value !== $expectedAmount || $payment->amount->currency !== $expectedCurrency) {
                Log::warning('Mollie paid webhook amount does not match the reservation total; not confirming.', [
                    'reservation_id' => $reservation->id,
                    'payment_id' => $payment->id,
                    'amount_paid' => $payment->amount->currency.' '.$payment->amount->value,
                    'expected_amount' => $expectedCurrency.' '.$expectedAmount,
                ]);

                OrphanedPaymentNotification::dispatchFor($reservation, $payment->id);

                return response()->json([], 200);
            }

            if ($reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true)) {
                ReservationConfirmed::dispatch($reservation, ReservationConfirmed::VIA_WEBHOOK, [
                    'gateway' => $reservation->payment_gateway ?: $this->name(),
                    'payment_id' => $payment->id,
                ]);

                return response()->json([], 200);
            }

            // Lost the confirm race (e.g. ExpireReservations flipped the row under the lock):
            // the captured charge may now be orphaned — surface it for manual refund.
            $reservation->refresh();
            OrphanedPaymentNotification::notifyIfOrphaned($reservation, $payment->id);

            return response()->json([], 200);
        }

        if (in_array($payment->status, ['canceled', 'expired'], true)) {
            // Stale intents were cancelled by us — ignore so we don't cascade-cancel a
            // reservation that moved on to a new intent.
            // A genuinely dead payment is EXPIRED, not REFUNDED: no money moved. expire()
            // no-ops unless the reservation is still PENDING.
            if (! $isStaleIntent && $reservation->status === ReservationStatus::PENDING->value) {
                $reservation->expire();
            }

            return response()->json([], 200);
        }

        // 'authorized': the customer approved a hold, but no money is collected yet. These
        // payments are created with Mollie's default automatic capture, so Mollie captures the
        // hold itself and calls this webhook again with status 'paid', which confirms above.
        // 'failed' (and anything else): a failed attempt is retryable — leave the reservation
        // PENDING so the customer can try again; ExpireReservations reclaims the hold if abandoned.
        Log::info('Mollie webhook: payment not paid; leaving reservation unconfirmed.', [
            'reservation_id' => $reservation->id,
            'payment_id' => $payment->id,
            'payment_status' => $payment->status,
        ]);

        return response()->json([], 200);
    }

    public function verifyWebhook()
    {
        return true;
    }

    public function getPublicKey($reservation)
    {
        // Mollie has no publishable key; Checkout::$publicKey is a typed string property.
        return '';
    }

    public function getSecretKey($reservation)
    {
        return config('mollie.key');
    }

    public function getWebhookSecret($reservation)
    {
        // Mollie webhooks are not signed — authenticity is established in verifyPayment()
        // by fetching the payment from the API.
        return null;
    }

    /**
     * The key this gateway is registered under in resrv-config.payment_gateways. Needed at
     * intent-creation time (webhook/redirect URLs) because reservation.payment_gateway has
     * not been persisted yet when paymentIntent() runs.
     */
    protected function configKey(): string
    {
        foreach (app(PaymentGatewayManager::class)->all() as $key => $gateway) {
            if ($gateway instanceof static) {
                return $key;
            }
        }

        return $this->name();
    }

    protected function redirectBackUrl(Reservation $reservation, ?string $returnUrl = null): string
    {
        // Honour the caller's base (e.g. the authenticated pay-by-link page); fall back to the
        // checkout-complete entry so normal checkout is byte-identical (Step 12).
        $base = $returnUrl ?? $this->getCheckoutCompleteEntry()->absoluteUrl();

        // The pay-by-link base already carries ?ref=&hash=, so pick the separator dynamically
        // rather than hard-coding '?'. resrv_gateway lets the redirect-back handler resolve this
        // gateway even when the session is unavailable on the way back from Mollie; Resrv
        // already adds it to the pay-by-link base. sig proves the return URL was issued for
        // this reservation (see returnIsAuthorized()).
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.http_build_query([
            'id' => $reservation->id,
            // http_build_query() drops a null value.
            'resrv_gateway' => str_contains($base, 'resrv_gateway=') ? null : $this->configKey(),
            'sig' => $this->returnSignature((string) $reservation->id),
        ]);
    }

    /**
     * An HMAC of the reservation id under the app key, scoped to this return URL. It only lets
     * the bearer see the return page for that reservation, unlike the customer lookup hash,
     * which also opens the status page (and its cancellation) and must not ride in a URL
     * Mollie and the browser history keep.
     */
    protected function returnSignature(string $reservationId): string
    {
        return hash_hmac('sha256', 'resrv-payment-mollie:return:'.$reservationId, (string) config('app.key'));
    }

    /**
     * A valid signature, or — for payments created before return URLs were signed — the
     * browser whose checkout session holds this reservation.
     */
    protected function returnIsAuthorized(string $reservationId): bool
    {
        $signature = request()->input('sig');

        if (is_string($signature) && hash_equals($this->returnSignature($reservationId), $signature)) {
            return true;
        }

        $sessionReservationId = session('resrv_reservation');

        return is_scalar($sessionReservationId) && (string) $sessionReservationId === $reservationId;
    }

    protected function paymentDescription(Reservation $reservation): string
    {
        $title = trim((string) ($reservation->entry()->title ?? ''));

        if ($title === '') {
            $title = 'Reservation #'.$reservation->id;
        }

        // Mollie caps the description at 255 characters.
        return mb_substr($title, 0, 255);
    }
}
