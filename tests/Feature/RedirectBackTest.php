<?php

namespace Reach\ResrvPaymentMollie\Tests\Feature;

use Illuminate\Http\Request;
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

    public function test_the_checkout_complete_page_shows_an_open_payment_as_pending()
    {
        // Mollie's guidance for an open payment on return is a processing screen: the customer
        // may have chosen bank transfer, and the webhook settles it either way.
        $url = $this->returnUrlFor($this->makeReservation());
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment()]);

        $this->assertSame('pending', $this->checkoutCompleteResult($url)['status']);
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
