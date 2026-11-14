<?php

namespace Reach\ResrvPaymentMollie\Tests\Feature;

use Reach\ResrvPaymentMollie\Tests\Support\FakeMollieApi;
use Reach\ResrvPaymentMollie\Tests\TestCase;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;

class RefundTest extends TestCase
{
    protected function refund($reservation): bool
    {
        return app(ReservationRefundProcessor::class)->refund($reservation);
    }

    public function test_a_cp_refund_returns_the_full_charge_with_a_stable_idempotency_key()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::CONFIRMED->value]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);
        $this->mollie->on('POST', '/v2/payments/tr_test1/refunds', [201, FakeMollieApi::refund()]);

        $this->assertTrue($this->refund($reservation));

        $this->assertSame(ReservationStatus::REFUNDED->value, $reservation->fresh()->status);
        $post = $this->mollie->requests('POST', '/v2/payments/tr_test1/refunds')[0];
        $this->assertSame(['currency' => 'EUR', 'value' => '55.00'], json_decode($post['body'], true)['amount']);
        $this->assertSame('resrv-refund-'.$reservation->id.'-tr_test1', $post['headers']['Idempotency-Key']);
    }

    public function test_a_customer_self_cancellation_refunds_through_mollie()
    {
        config(['resrv-config.enable_customer_cancellations' => true]);
        $reservation = $this->makeReservation([
            'status' => ReservationStatus::CONFIRMED->value,
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 2,
        ]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);
        $this->mollie->on('POST', '/v2/payments/tr_test1/refunds', [201, FakeMollieApi::refund()]);

        $this->assertTrue($reservation->canCancelWithRefund());
        $this->assertTrue(app(ReservationRefundProcessor::class)->cancelByCustomer($reservation));

        $this->assertSame(ReservationStatus::REFUNDED->value, $reservation->fresh()->status);
        $this->assertCount(1, $this->mollie->requests('POST', '/v2/payments/tr_test1/refunds'));
    }

    public function test_a_payment_already_refunded_at_mollie_reconciles_without_refunding_again()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::CONFIRMED->value]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid([
            'amountRefunded' => ['value' => '55.00'],
            'amountRemaining' => ['value' => '0.00'],
        ])]);

        $this->assertTrue($this->refund($reservation));

        $this->assertSame(ReservationStatus::REFUNDED->value, $reservation->fresh()->status);
        $this->assertCount(0, $this->mollie->requests('POST'));
    }

    public function test_a_refused_refund_rolls_back_and_surfaces_as_refund_failed()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::CONFIRMED->value]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);
        $this->mollie->on('POST', '/v2/payments/tr_test1/refunds', FakeMollieApi::error(422, 'Insufficient balance'));

        try {
            $this->refund($reservation);
            $this->fail('Expected a RefundFailedException.');
        } catch (RefundFailedException) {
        }

        $this->assertSame(ReservationStatus::CONFIRMED->value, $reservation->fresh()->status);
    }

    public function test_a_failed_refund_does_not_leave_its_idempotency_key_for_the_next_request()
    {
        // Mollie answers 400 when a key is reused for another endpoint, so a refund key that
        // outlives a failed refund would break the next payment created in the same process.
        $reservation = $this->makeReservation(['status' => ReservationStatus::CONFIRMED->value]);
        $other = $this->makeReservation(['payment_id' => '']);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);
        $this->mollie->on('POST', '/v2/payments/tr_test1/refunds', FakeMollieApi::error(422, 'Insufficient balance'));
        $this->mollie->on('POST', '/v2/payments', [201, FakeMollieApi::payment(['id' => 'tr_new'])]);

        try {
            $this->refund($reservation);
        } catch (RefundFailedException) {
        }

        $this->gateway()->paymentIntent($other->amountDue(), $other, collect(), null);

        $create = $this->mollie->requests('POST', '/v2/payments')[0];
        $this->assertNotSame('resrv-refund-'.$reservation->id.'-tr_test1', $create['headers']['Idempotency-Key'] ?? null);
    }

    public function test_an_unreachable_mollie_fails_the_refund_after_one_bounded_attempt()
    {
        // refund() runs inside the REFUNDED transition's row lock: like the Stripe gateway, each
        // call has a 15 s timeout and a 5 s connect timeout, and nothing retries inside the lock.
        $reservation = $this->makeReservation(['status' => ReservationStatus::CONFIRMED->value]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);
        $this->mollie->on('POST', '/v2/payments/tr_test1/refunds', 'network');

        $started = microtime(true);

        try {
            $this->refund($reservation);
            $this->fail('Expected a RefundFailedException.');
        } catch (RefundFailedException) {
        }

        $this->assertLessThan(1, microtime(true) - $started, 'No retry sleeps inside the row lock.');
        $this->assertSame(ReservationStatus::CONFIRMED->value, $reservation->fresh()->status);

        $posts = $this->mollie->requests('POST', '/v2/payments/tr_test1/refunds');
        $this->assertCount(1, $posts);
        $this->assertSame(15, $posts[0]['options']['timeout'] ?? null);
        $this->assertSame(5, $posts[0]['options']['connect_timeout'] ?? null);
    }

    public function test_refunding_a_paid_out_of_band_booking_releases_a_leftover_authorization_instead_of_refunding_it()
    {
        // Marked as paid in the CP while a Mollie authorization was still open: there is no
        // captured charge to refund, so Resrv voids the hold and returns the money by hand.
        $reservation = $this->makeReservation(['status' => ReservationStatus::CONFIRMED->value, 'payment_unresolved' => true]);
        $released = false;
        $this->mollie->on('GET', '/v2/payments/tr_test1', function () use (&$released) {
            return [200, FakeMollieApi::payment(['status' => $released ? 'canceled' : 'authorized', '_links' => ['checkout' => null]])];
        });
        $this->mollie->on('POST', '/v2/payments/tr_test1/release-authorization', function () use (&$released) {
            $released = true;

            return [202, null];
        });

        $this->assertTrue($this->refund($reservation));

        $this->assertSame(ReservationStatus::REFUNDED->value, $reservation->fresh()->status);
        $this->assertTrue($released);
        $this->assertCount(0, $this->mollie->requests('POST', '/v2/payments/tr_test1/refunds'));
    }
}
