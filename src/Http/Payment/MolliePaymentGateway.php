<?php

namespace Reach\ResrvPaymentMollie\Http\Payment;

use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\MollieException;
use Mollie\Laravel\Facades\Mollie;
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

    public function paymentIntent($payment, Reservation $reservation, $data)
    {
        $molliePayment = Mollie::api()->payments->create([
            'amount' => [
                'currency' => config('resrv-config.currency_isoCode'),
                // Mollie expects a string with the currency's exact number of decimals
                // and no thousands separator — exactly what Price::format() produces.
                'value' => $payment->format(),
            ],
            'description' => $this->paymentDescription($reservation),
            'redirectUrl' => $this->redirectBackUrl($reservation),
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

    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
    {
        try {
            $payment = Mollie::api()->payments->get($paymentId);

            // Most Mollie payment methods are not cancelable (open payments simply expire);
            // cancel only when the API says we can. Best-effort: a webhook race is handled
            // by the stale-intent fallback in verifyPayment().
            if ($payment->isCancelable) {
                Mollie::api()->payments->cancel($paymentId);
            }
        } catch (ApiException|MollieException $e) {
            Log::warning('Failed to cancel Mollie payment: '.$e->getMessage(), [
                'payment_id' => $paymentId,
                'reservation_id' => $reservation->id,
            ]);
        }
    }

    public function refund($reservation)
    {
        $api = Mollie::api();

        try {
            $payment = $api->payments->get($reservation->payment_id);

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
            $api->setIdempotencyKey('resrv-refund-'.$reservation->id.'-'.$reservation->payment_id);

            // Refund the full charge (payment + surcharge) in the currency it was made in —
            // customer self-cancellation promises the customer exactly this amount.
            return $payment->refund([
                'amount' => [
                    'currency' => $payment->amount->currency,
                    'value' => $payment->amount->value,
                ],
            ]);
        } catch (ApiException|MollieException $exception) {
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

        $reservation = is_scalar($reservationId) ? Reservation::find($reservationId) : null;

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
            $payment = Mollie::api()->payments->get($reservation->payment_id);
        } catch (ApiException|MollieException $e) {
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

        // The payment is still underway (e.g. bank transfer) — confirmation arrives via webhook.
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
            $payment = Mollie::api()->payments->get($paymentId);
        } catch (ApiException|MollieException $e) {
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

        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            return response()->json([], 200);
        }

        if ($payment->isPaid()) {
            // Stale intent: the customer moved on (back button, gateway switch) before this
            // payment completed. Confirming would attach a charge to an amount or gateway the
            // reservation is no longer tied to — notify admins for manual refund instead.
            if ($isStaleIntent) {
                Log::warning('Mollie payment succeeded after being abandoned by the customer — manual reconciliation may be required.', [
                    'reservation_id' => $reservation->id,
                    'payment_id' => $payment->id,
                    'current_payment_id' => $reservation->payment_id,
                    'current_payment_gateway' => $reservation->payment_gateway,
                ]);

                OrphanedPaymentNotification::dispatchFor($reservation, $payment->id);

                return response()->json([], 200);
            }

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

        // 'failed' (and anything else): a failed attempt is retryable — leave the reservation
        // PENDING so the customer can try again; ExpireReservations reclaims the hold if abandoned.
        Log::info('Mollie webhook: payment not paid; leaving reservation PENDING.', [
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

    protected function redirectBackUrl(Reservation $reservation): string
    {
        // resrv_gateway lets the {{ resrv_checkout_redirect }} tag resolve this gateway even
        // when the session is unavailable on the way back from Mollie.
        return $this->getCheckoutCompleteEntry()->absoluteUrl().'?'.http_build_query([
            'id' => $reservation->id,
            'resrv_gateway' => $this->configKey(),
        ]);
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
