<?php

namespace Reach\ResrvPaymentMollie\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Reach\ResrvPaymentMollie\Tests\Support\FakeMollieApi;
use Reach\ResrvPaymentMollie\Tests\TestCase;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;

class WebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ReservationConfirmed::class]);
    }

    protected function webhook(string $paymentId = 'tr_test1')
    {
        return $this->post('/resrv/api/webhook/mollie', ['id' => $paymentId]);
    }

    public function test_a_paid_payment_confirms_a_checkout_reservation()
    {
        $reservation = $this->makeReservation();
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);

        $this->webhook()->assertOk();

        $this->assertSame(ReservationStatus::CONFIRMED->value, $reservation->fresh()->status);
        Event::assertDispatched(ReservationConfirmed::class, fn ($event) => $event->via === ReservationConfirmed::VIA_WEBHOOK
            && $event->payment === ['gateway' => 'mollie', 'payment_id' => 'tr_test1']);
    }

    public function test_a_paid_payment_confirms_an_awaiting_payment_manual_reservation()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);

        $this->webhook()->assertOk();

        $this->assertSame(ReservationStatus::CONFIRMED->value, $reservation->fresh()->status);
        Event::assertDispatched(ReservationConfirmed::class);
    }

    public function test_an_authorized_payment_waits_and_the_booking_confirms_once_mollie_captures_it()
    {
        // Mollie captures an automatic-capture authorization itself and calls the webhook again
        // with status paid; an authorization alone is not money and must not confirm.
        $reservation = $this->makeReservation();
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['status' => 'authorized', 'method' => 'klarna', '_links' => ['checkout' => null]])]);

        $this->webhook()->assertOk();

        $this->assertSame(ReservationStatus::PENDING->value, $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);

        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid(['method' => 'klarna'])]);

        $this->webhook()->assertOk();

        $this->assertSame(ReservationStatus::CONFIRMED->value, $reservation->fresh()->status);
    }

    public function test_a_paid_payment_on_a_lapsed_hold_is_not_confirmed()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::CANCELLED->value, 'payment_unresolved' => true]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);

        $this->webhook()->assertOk();

        $this->assertSame(ReservationStatus::CANCELLED->value, $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
    }

    public function test_a_paid_amount_that_does_not_match_the_reservation_is_not_confirmed()
    {
        $reservation = $this->makeReservation();
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid(['amount' => ['value' => '50.00']])]);

        $this->webhook()->assertOk();

        $this->assertSame(ReservationStatus::PENDING->value, $reservation->fresh()->status);
    }

    public function test_a_canceled_or_expired_payment_expires_a_checkout_reservation_but_not_a_manual_one()
    {
        foreach (['canceled', 'expired'] as $status) {
            $pending = $this->makeReservation(['payment_id' => 'tr_pending_'.$status]);
            $awaiting = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value, 'payment_id' => 'tr_awaiting_'.$status]);

            foreach (['tr_pending_'.$status, 'tr_awaiting_'.$status] as $id) {
                $this->mollie->on('GET', '/v2/payments/'.$id, [200, FakeMollieApi::payment(['id' => $id, 'status' => $status, '_links' => ['checkout' => null]])]);
                $this->webhook($id)->assertOk();
            }

            $this->assertSame(ReservationStatus::EXPIRED->value, $pending->fresh()->status, $status);
            $this->assertSame(ReservationStatus::AWAITING_PAYMENT->value, $awaiting->fresh()->status, $status);
        }
    }

    public function test_a_failed_payment_leaves_the_reservation_payable()
    {
        $reservation = $this->makeReservation();
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['status' => 'failed', '_links' => ['checkout' => null]])]);

        $this->webhook()->assertOk();

        $this->assertSame(ReservationStatus::PENDING->value, $reservation->fresh()->status);
    }

    public function test_a_paid_payment_the_customer_abandoned_is_not_confirmed()
    {
        // Checkout cleared payment_id before cancelling: only the metadata still points here.
        $reservation = $this->makeReservation(['payment_id' => '', 'payment_gateway' => '']);
        $this->mollie->on('GET', '/v2/payments/tr_old', [200, FakeMollieApi::paid(['id' => 'tr_old', 'metadata' => ['reservation_id' => $reservation->id]])]);

        $this->webhook('tr_old')->assertOk();

        $this->assertSame(ReservationStatus::PENDING->value, $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
    }

    public function test_a_canceled_payment_the_customer_abandoned_does_not_expire_the_reservation()
    {
        $reservation = $this->makeReservation(['payment_id' => 'tr_new']);
        $this->mollie->on('GET', '/v2/payments/tr_old', [200, FakeMollieApi::payment(['id' => 'tr_old', 'status' => 'canceled', 'metadata' => ['reservation_id' => $reservation->id]])]);

        $this->webhook('tr_old')->assertOk();

        $this->assertSame(ReservationStatus::PENDING->value, $reservation->fresh()->status);
    }

    public function test_a_refund_event_on_a_refunded_reservation_is_acknowledged()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::REFUNDED->value]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid([
            'amountRefunded' => ['value' => '55.00'],
            '_links' => ['refunds' => ['href' => 'https://api.mollie.com/v2/payments/tr_test1/refunds', 'type' => 'application/hal+json']],
        ])]);

        $this->webhook()->assertOk();

        $this->assertSame(ReservationStatus::REFUNDED->value, $reservation->fresh()->status);
    }

    public function test_a_payment_mollie_does_not_know_answers_404_so_mollie_retries()
    {
        $this->withExceptionHandling();
        $this->mollie->on('GET', '/v2/payments/tr_unknown', FakeMollieApi::error(404, 'No payment exists with token tr_unknown.'));

        $this->webhook('tr_unknown')->assertNotFound();
    }

    public function test_a_request_without_a_payment_id_is_rejected()
    {
        $this->withExceptionHandling();

        $this->post('/resrv/api/webhook/mollie')->assertStatus(400);
        $this->assertSame([], $this->mollie->sent);
    }
}
