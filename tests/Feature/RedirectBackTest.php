<?php

namespace Reach\ResrvPaymentMollie\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Reach\ResrvPaymentMollie\Tests\Support\FakeMollieApi;
use Reach\ResrvPaymentMollie\Tests\TestCase;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Livewire\ReservationPayment;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Facades\Entry;

class RedirectBackTest extends TestCase
{
    /** The redirectUrl the gateway gave Mollie for this reservation (checkout-complete, or the pay page). */
    protected function returnUrlFor(Reservation $reservation): string
    {
        $this->mollie->on('POST', '/v2/payments', [201, FakeMollieApi::payment(['id' => 'tr_created'])]);

        $base = $reservation->status === ReservationStatus::AWAITING_PAYMENT->value
            ? $reservation->customerPaymentUrl().'&resrv_gateway=mollie'
            : Entry::find(config('resrv-config.checkout_completed_entry'))->absoluteUrl();

        $this->gateway()->paymentIntent($reservation->amountDue(), $reservation, collect(), $base);

        return $this->mollie->lastBody('POST', '/v2/payments')['redirectUrl'];
    }

    /** What the checkout-complete page ({{ resrv_checkout_redirect }}) gets back for this URL. */
    protected function checkoutCompleteResult(string $url): array
    {
        $this->app->instance('request', Request::create($url));

        return $this->gateway()->handleRedirectBack();
    }

    protected function payPage(string $url)
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return Livewire::withQueryParams($query)->test(ReservationPayment::class);
    }

    public function test_the_checkout_complete_page_shows_a_paid_reservation()
    {
        $reservation = $this->makeReservation();
        $url = $this->returnUrlFor($reservation);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);

        $result = $this->checkoutCompleteResult($url);

        $this->assertTrue($result['status']);
        $this->assertSame($reservation->reference, $result['reservation']['reference']);
    }

    public function test_the_checkout_complete_page_reports_an_open_payment_as_not_paid_after_a_failed_attempt()
    {
        // After a failed card attempt Mollie shows its method page again and the payment stays
        // open with no webhook. A customer who comes back from there has not paid, whichever
        // method they tried (or none yet), and must be told to try again.
        $url = $this->returnUrlFor($this->makeReservation());

        foreach (['creditcard', 'ideal', null] as $method) {
            $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['method' => $method])]);

            $this->assertFalse($this->checkoutCompleteResult($url)['status'], 'Method ['.($method ?? 'none').']');
        }
    }

    public function test_the_checkout_complete_page_waits_for_an_open_payment_only_on_the_first_return()
    {
        // Two more looks, 1.5 s apart, before reporting not paid. A refresh, or anyone repeating
        // the signed request, reads once: the page must not hold a worker for seconds each time.
        $url = $this->returnUrlFor($this->makeReservation());
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['method' => 'creditcard'])]);

        $this->assertFalse($this->checkoutCompleteResult($url)['status']);
        $this->assertCount(3, $this->mollie->requests('GET', '/v2/payments/tr_test1'));
        Sleep::assertSequence([Sleep::for(1500)->milliseconds(), Sleep::for(1500)->milliseconds()]);

        $this->assertFalse($this->checkoutCompleteResult($url)['status']);
        $this->assertCount(4, $this->mollie->requests('GET', '/v2/payments/tr_test1'));
        Sleep::assertSleptTimes(2);
    }

    public function test_the_checkout_complete_page_reports_a_failed_second_look_as_not_paid()
    {
        $url = $this->returnUrlFor($this->makeReservation());
        $reads = 0;
        $this->mollie->on('GET', '/v2/payments/tr_test1', function () use (&$reads) {
            return ++$reads === 1 ? [200, FakeMollieApi::payment(['method' => 'creditcard'])] : FakeMollieApi::error(503, 'Service unavailable');
        });

        $this->assertFalse($this->checkoutCompleteResult($url)['status']);
    }

    public function test_the_checkout_complete_page_checks_an_open_payment_again_before_reporting_it_unpaid()
    {
        // A PayPal customer who clicks "Return to Merchant" can arrive while the payment still
        // reads open. A second look a moment later finds it paid.
        $url = $this->returnUrlFor($this->makeReservation());
        $reads = 0;
        $this->mollie->on('GET', '/v2/payments/tr_test1', function () use (&$reads) {
            return [200, ++$reads === 1 ? FakeMollieApi::payment(['method' => 'paypal']) : FakeMollieApi::paid(['method' => 'paypal'])];
        });

        $this->assertTrue($this->checkoutCompleteResult($url)['status']);
        Sleep::assertSequence([Sleep::for(1500)->milliseconds()]);
    }

    public function test_the_checkout_complete_page_shows_an_open_bank_transfer_as_pending()
    {
        // Bank transfer, Multibanco and Pay by Bank complete at the customer's bank or an ATM
        // after they leave Mollie: the payment can stay open meanwhile, and the webhook confirms
        // the reservation once the money arrives. There is nothing to wait for on the return.
        $url = $this->returnUrlFor($this->makeReservation());

        foreach (['banktransfer', 'multibanco', 'paybybank'] as $method) {
            $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['method' => $method])]);

            $this->assertSame('pending', $this->checkoutCompleteResult($url)['status'], "Method [{$method}]");
        }

        Sleep::assertNeverSlept();
    }

    public function test_the_checkout_complete_page_shows_a_pending_or_authorized_payment_as_pending()
    {
        $url = $this->returnUrlFor($this->makeReservation());

        foreach (['pending', 'authorized'] as $status) {
            $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['status' => $status, 'method' => 'creditcard', '_links' => ['checkout' => null]])]);

            $this->assertSame('pending', $this->checkoutCompleteResult($url)['status'], "Status [{$status}]");
        }

        Sleep::assertNeverSlept();
    }

    public function test_the_checkout_complete_page_shows_a_canceled_payment_as_failed()
    {
        $url = $this->returnUrlFor($this->makeReservation());
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['status' => 'canceled', '_links' => ['checkout' => null]])]);

        $this->assertFalse($this->checkoutCompleteResult($url)['status']);
    }

    public function test_a_reservation_id_without_proof_of_ownership_reveals_nothing()
    {
        // The id is sequential: the return leg must not show any reservation to whoever asks.
        $victim = $this->makeReservation(['status' => ReservationStatus::CONFIRMED->value]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);

        $forgedUrl = Entry::find(config('resrv-config.checkout_completed_entry'))->absoluteUrl().'?id='.$victim->id.'&resrv_gateway=mollie';
        $result = $this->checkoutCompleteResult($forgedUrl);

        $this->assertFalse($result['status']);
        $this->assertSame([], $result['reservation']);
        $this->assertCount(0, $this->mollie->sent);
    }

    public function test_a_tampered_signature_reveals_nothing()
    {
        $reservation = $this->makeReservation();
        $other = $this->makeReservation(['payment_id' => 'tr_other']);
        $url = str_replace('id='.$reservation->id.'&', 'id='.$other->id.'&', $this->returnUrlFor($reservation));

        $result = $this->checkoutCompleteResult($url);

        $this->assertFalse($result['status']);
        $this->assertSame([], $result['reservation']);
    }

    public function test_an_unsigned_return_is_accepted_for_the_reservation_in_this_checkout_session()
    {
        // Payments created before 2.0.1 carry no signature; the browser that started the
        // checkout still holds the reservation in its session.
        $reservation = $this->makeReservation();
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);
        session(['resrv_reservation' => $reservation->id]);

        $result = $this->checkoutCompleteResult(Entry::find(config('resrv-config.checkout_completed_entry'))->absoluteUrl().'?id='.$reservation->id.'&resrv_gateway=mollie');

        $this->assertTrue($result['status']);
    }

    public function test_the_pay_page_offers_payment_again_when_the_customer_comes_back_without_paying()
    {
        // The payment is still open: Pay resumes this same Mollie payment, so offering it again
        // cannot create a second chargeable payment.
        $reservation = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value]);
        $url = $this->returnUrlFor($reservation);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment()]);

        $this->payPage($url)
            ->assertDontSee(trans('statamic-resrv::frontend.paymentProcessing'))
            ->assertSee(trans('statamic-resrv::frontend.pay'))
            ->call('pay')
            ->assertRedirect('https://www.mollie.com/checkout/select-method/tr_test1');

        $this->assertCount(1, $this->mollie->requests('POST', '/v2/payments'), 'Only the payment created to build the return URL.');
    }

    public function test_the_pay_page_shows_processing_for_a_paid_payment()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value]);
        $url = $this->returnUrlFor($reservation);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::paid()]);

        $this->payPage($url)->assertSee(trans('statamic-resrv::frontend.paymentProcessing'));

        $this->assertSame(ReservationStatus::AWAITING_PAYMENT->value, $reservation->fresh()->status, 'Only the webhook confirms.');
    }

    public function test_the_pay_page_shows_processing_for_a_bank_transfer_awaiting_the_customers_transfer()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value]);
        $url = $this->returnUrlFor($reservation);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['method' => 'banktransfer'])]);

        $this->payPage($url)->assertSee(trans('statamic-resrv::frontend.paymentProcessing'));
    }

    public function test_the_pay_page_offers_payment_again_after_a_canceled_payment()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value]);
        $url = $this->returnUrlFor($reservation);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['status' => 'canceled', '_links' => ['checkout' => null]])]);

        $this->payPage($url)->assertSee(trans('statamic-resrv::frontend.pay'));
    }
}
