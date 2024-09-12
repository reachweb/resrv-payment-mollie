<?php

namespace Reach\ResrvPaymentMollie\Http\Payment;

use Illuminate\Support\Facades\Log;
use Mollie\Laravel\Facades\Mollie;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Models\Reservation;

class MolliePaymentGateway implements PaymentInterface
{
    use HandlesStatamicQueries;

    public function paymentIntent($payment, Reservation $reservation, $data)
    {
        $payment = Mollie::api()->payments->create([
            'amount' => [
                'currency' => config('resrv-config.currency_isoCode'),
                'value' => $payment->format(),
            ],
            'description' => $reservation->entry()->title,
            'redirectUrl' => $this->getCheckoutCompleteEntry()->absoluteUrl().'?id='.$reservation->id,
            'webhookUrl' => route('resrv.webhook.store'),
            'metadata' => [
                'order_id' => $reservation->id,
            ],
        ]);

        $paymentIntent = new \stdClass;
        $paymentIntent->id = $payment->id;
        $paymentIntent->client_secret = '';
        $paymentIntent->redirectTo = $payment->getCheckoutUrl();

        return $paymentIntent;
    }

    public function refund($reservation)
    {
        $payment = Mollie::api()->payments->get($reservation->payment_id);

        try {
            if ($payment->canBeRefunded()) {
                $refund = $payment->refund([
                    'amount' => [
                        'currency' => config('resrv-config.currency_isoCode'),
                        'value' => $reservation->payment->format(),
                    ],
                ]);
            } else {
                throw new RefundFailedException('This payment could not be refunded.');
            }
        } catch (\Mollie\Api\Exceptions\ApiException $exception) {
            throw new RefundFailedException($exception->getMessage());
        }

        return $refund;
    }

    public function getPublicKey($reservation) {}

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function redirectsForPayment(): bool
    {
        return true;
    }

    public function handleRedirectBack(): array
    {
        $id = request()->input('id');

        $reservation = Reservation::findOrFail($id);

        $payment = Mollie::api()->payments->get($reservation->payment_id);

        if ($payment->status === 'pending') {
            return [
                'status' => 'pending',
                'reservation' => $reservation ? $reservation->toArray() : [],
            ];
        }

        if ($payment->isPaid()) {
            return [
                'status' => true,
                'reservation' => $reservation ? $reservation->toArray() : [],
            ];
        }

        return [
            'status' => false,
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }

    public function handlePaymentPending(): bool|array
    {
        return false;
    }

    public function verifyPayment($request)
    {
        $paymentIntent = request()->input('id');

        $reservation = Reservation::findByPaymentId($paymentIntent)->first();

        if (! $reservation) {
            Log::info('Reservation not found for id '.$paymentIntent);

            return response()->json([], 200);
        }

        if ($reservation->status === ReservationStatus::CONFIRMED) {
            return response()->json([], 200);
        }

        $payment = Mollie::api()->payments->get($paymentIntent);

        if ($payment->isPaid()) {
            ReservationConfirmed::dispatch($reservation);

            return response()->json([], 200);
        } else {
            ReservationCancelled::dispatch($reservation);

            return response()->json([], 200);
        }
    }

    public function verifyWebhook()
    {
        return true;
    }
}
