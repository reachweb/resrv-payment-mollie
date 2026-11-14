<?php

namespace Reach\ResrvPaymentMollie\Tests\Feature;

use Mollie\Api\Exceptions\MollieException;
use Reach\ResrvPaymentMollie\Tests\Support\FakeMollieApi;
use Reach\ResrvPaymentMollie\Tests\TestCase;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;

class CancelAndRetrieveTest extends TestCase
{
    public function test_retrieve_maps_mollie_statuses_to_the_stripe_style_vocabulary()
    {
        // paid/pending are money moving; authorized is a hold that still needs capturing
        // (Step 13's requires_capture); canceled/expired/failed are dead at Mollie.
        $expected = [
            'open' => 'open',
            'paid' => 'succeeded',
            'pending' => 'processing',
            'authorized' => 'requires_capture',
            'canceled' => 'canceled',
            'expired' => 'canceled',
            'failed' => 'canceled',
        ];

        foreach ($expected as $mollieStatus => $resrvStatus) {
            $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['status' => $mollieStatus])]);

            $intent = $this->gateway()->retrievePaymentIntent('tr_test1', $this->makeReservation());

            $this->assertSame($resrvStatus, $intent->status, "Mollie status [{$mollieStatus}]");
        }
    }

    public function test_retrieve_returns_the_checkout_url_of_a_payable_payment()
    {
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment()]);

        $intent = $this->gateway()->retrievePaymentIntent('tr_test1', $this->makeReservation());

        $this->assertSame('tr_test1', $intent->id);
        $this->assertSame('https://www.mollie.com/checkout/select-method/tr_test1', $intent->redirectTo);
    }

    public function test_retrieve_reports_an_open_payment_without_a_checkout_url_instead_of_null()
    {
        // Null would let Resrv mint a replacement without voiding this still-open payment.
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['_links' => ['checkout' => null]])]);

        $intent = $this->gateway()->retrievePaymentIntent('tr_test1', $this->makeReservation());

        $this->assertSame('open', $intent->status);
        $this->assertNull($intent->redirectTo);
    }

    public function test_retrieve_returns_null_only_when_the_payment_is_gone()
    {
        $this->mollie->on('GET', '/v2/payments/tr_gone', FakeMollieApi::error(404, 'No payment exists with token tr_gone.'));

        $this->assertNull($this->gateway()->retrievePaymentIntent('tr_gone', $this->makeReservation()));
    }

    public function test_retrieve_propagates_a_transient_failure()
    {
        $this->mollie->on('GET', '/v2/payments/tr_test1', FakeMollieApi::error(503, 'Service unavailable'));

        $this->expectException(MollieException::class);

        $this->gateway()->retrievePaymentIntent('tr_test1', $this->makeReservation());
    }

    public function test_cancel_cancels_a_cancelable_payment_and_leaves_others_to_expire()
    {
        $reservation = $this->makeReservation();
        $this->mollie->on('GET', '/v2/payments/tr_a', [200, FakeMollieApi::payment(['id' => 'tr_a', 'isCancelable' => true])]);
        $this->mollie->on('DELETE', '/v2/payments/tr_a', [200, FakeMollieApi::payment(['id' => 'tr_a', 'status' => 'canceled'])]);
        $this->mollie->on('GET', '/v2/payments/tr_b', [200, FakeMollieApi::payment(['id' => 'tr_b'])]);

        $this->gateway()->cancelPaymentIntent('tr_a', $reservation);
        $this->gateway()->cancelPaymentIntent('tr_b', $reservation);

        $this->assertSame(
            ['GET /v2/payments/tr_a', 'DELETE /v2/payments/tr_a', 'GET /v2/payments/tr_b'],
            array_map(fn ($request) => $request['method'].' '.$request['path'], $this->mollie->sent),
        );
    }

    public function test_cancel_releases_an_authorized_payment()
    {
        // An authorization is voided through release-authorization, not DELETE.
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['status' => 'authorized', '_links' => ['checkout' => null]])]);
        $this->mollie->on('POST', '/v2/payments/tr_test1/release-authorization', [202, null]);

        $this->gateway()->cancelPaymentIntent('tr_test1', $this->makeReservation());

        $this->assertCount(1, $this->mollie->requests('POST', '/v2/payments/tr_test1/release-authorization'));
    }

    public function test_cancel_swallows_mollie_errors()
    {
        $this->mollie->on('GET', '/v2/payments/tr_test1', FakeMollieApi::error(500, 'Internal error'));

        $this->gateway()->cancelPaymentIntent('tr_test1', $this->makeReservation());

        $this->assertCount(1, $this->mollie->sent);
    }

    public function test_cancelling_a_manual_reservation_keeps_the_reference_of_a_payment_mollie_cannot_cancel()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment()]);

        app(ReservationRefundProcessor::class)->cancelWithoutRefund($reservation, cancelOpenIntent: true);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::CANCELLED->value, $fresh->status);
        $this->assertSame('tr_test1', $fresh->payment_id);
        $this->assertTrue((bool) $fresh->payment_unresolved);
    }

    public function test_cancelling_a_manual_reservation_drops_the_reference_of_a_payment_it_cancelled()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value]);
        $cancelled = false;
        $this->mollie->on('GET', '/v2/payments/tr_test1', function () use (&$cancelled) {
            return [200, FakeMollieApi::payment($cancelled ? ['status' => 'canceled', '_links' => ['checkout' => null]] : ['isCancelable' => true])];
        });
        $this->mollie->on('DELETE', '/v2/payments/tr_test1', function () use (&$cancelled) {
            $cancelled = true;

            return [200, FakeMollieApi::payment(['status' => 'canceled', '_links' => ['checkout' => null]])];
        });

        app(ReservationRefundProcessor::class)->cancelWithoutRefund($reservation, cancelOpenIntent: true);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::CANCELLED->value, $fresh->status);
        $this->assertSame('', $fresh->payment_id);
        $this->assertFalse((bool) $fresh->payment_unresolved);
    }
}
