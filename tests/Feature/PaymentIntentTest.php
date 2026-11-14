<?php

namespace Reach\ResrvPaymentMollie\Tests\Feature;

use Livewire\Livewire;
use Reach\ResrvPaymentMollie\Tests\Support\FakeMollieApi;
use Reach\ResrvPaymentMollie\Tests\TestCase;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Livewire\ReservationPayment;
use Statamic\Facades\Entry;

class PaymentIntentTest extends TestCase
{
    public function test_checkout_creates_a_payment_for_the_full_charge_and_redirects_to_its_checkout()
    {
        $reservation = $this->makeReservation(['payment_id' => '']);
        $this->mollie->on('POST', '/v2/payments', [201, FakeMollieApi::payment(['id' => 'tr_new'])]);
        $checkoutComplete = Entry::find(config('resrv-config.checkout_completed_entry'))->absoluteUrl();

        // The arguments Checkout::initializePayment() passes.
        $intent = $this->gateway()->paymentIntent($reservation->amountDue(), $reservation, $reservation->customerData, $checkoutComplete);

        $body = $this->mollie->lastBody('POST', '/v2/payments');
        $this->assertSame(['currency' => 'EUR', 'value' => '55.00'], $body['amount']);
        $this->assertSame('Sea view room', $body['description']);
        $this->assertSame('http://localhost/resrv/api/webhook/mollie', $body['webhookUrl']);
        $this->assertSame($reservation->id, $body['metadata']['reservation_id']);
        $this->assertStringStartsWith($checkoutComplete.'?id='.$reservation->id.'&resrv_gateway=mollie', $body['redirectUrl']);

        $this->assertSame('tr_new', $intent->id);
        $this->assertSame('https://www.mollie.com/checkout/select-method/tr_new', $intent->redirectTo);
    }

    public function test_the_return_url_appends_to_a_base_that_already_has_a_query()
    {
        // Step 12: the pay-by-link base ends in its ?ref=…&hash=… pair; a bare '?' append would
        // produce a double-? URL and break the customer's way back.
        [$base, $params] = $this->createPaymentAndParseRedirectUrl('https://example.com/pay?ref=RSV-1&hash=abc123');

        $this->assertSame('https://example.com/pay', $base);
        $this->assertSame('RSV-1', $params['ref']);
        $this->assertSame('abc123', $params['hash']);
        $this->assertSame('mollie', $params['resrv_gateway']);
    }

    public function test_the_return_url_starts_a_query_when_the_base_has_none()
    {
        [$base, $params] = $this->createPaymentAndParseRedirectUrl('https://example.com/pay');

        $this->assertSame('https://example.com/pay', $base);
        $this->assertSame('mollie', $params['resrv_gateway']);
    }

    public function test_the_pay_page_creates_a_payment_that_returns_to_the_pay_page()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value, 'payment_id' => '']);
        $this->mollie->on('POST', '/v2/payments', [201, FakeMollieApi::payment(['id' => 'tr_new'])]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertRedirect('https://www.mollie.com/checkout/select-method/tr_new');

        $body = $this->mollie->lastBody('POST', '/v2/payments');
        parse_str(parse_url($body['redirectUrl'], PHP_URL_QUERY), $params);
        $this->assertSame($reservation->reference, $params['ref']);
        $this->assertSame($reservation->customerLookupHash(), $params['hash']);
        $this->assertSame('mollie', $params['resrv_gateway']);
        $this->assertSame('55.00', $body['amount']['value']);
        $this->assertSame('tr_new', $reservation->fresh()->payment_id);
    }

    public function test_the_pay_page_resumes_an_open_payment_instead_of_creating_another()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment()]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertRedirect('https://www.mollie.com/checkout/select-method/tr_test1');

        $this->assertCount(0, $this->mollie->requests('POST'));
    }

    public function test_the_pay_page_replaces_an_expired_payment()
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::AWAITING_PAYMENT->value]);
        $this->mollie->on('GET', '/v2/payments/tr_test1', [200, FakeMollieApi::payment(['status' => 'expired', '_links' => ['checkout' => null]])]);
        $this->mollie->on('POST', '/v2/payments', [201, FakeMollieApi::payment(['id' => 'tr_new'])]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertRedirect('https://www.mollie.com/checkout/select-method/tr_new');

        $this->assertSame('tr_new', $reservation->fresh()->payment_id);
    }

    protected function createPaymentAndParseRedirectUrl(string $returnUrl): array
    {
        $reservation = $this->makeReservation(['payment_id' => '']);
        $this->mollie->on('POST', '/v2/payments', [201, FakeMollieApi::payment(['id' => 'tr_new'])]);

        $this->gateway()->paymentIntent($reservation->amountDue(), $reservation, collect(), $returnUrl);

        $redirectUrl = $this->mollie->lastBody('POST', '/v2/payments')['redirectUrl'];
        $this->assertSame(1, substr_count($redirectUrl, '?'));

        [$base, $query] = explode('?', $redirectUrl);
        parse_str($query, $params);
        $this->assertSame((string) $reservation->id, $params['id']);

        return [$base, $params];
    }
}
