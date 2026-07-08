<?php

namespace Reach\ResrvPaymentMollie\Tests\Unit;

use Mockery;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Resources\Payment;
use Mollie\Laravel\Facades\Mollie;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentMollie\Http\Payment\MolliePaymentGateway;
use Reach\ResrvPaymentMollie\Tests\TestCase;
use Reach\StatamicResrv\Models\Reservation;

class MolliePaymentGatewayTest extends TestCase
{
    protected MolliePaymentGateway $gateway;

    protected $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = Mockery::mock();

        // Mollie::api() returns the API client whose ->payments endpoint the gateway uses;
        // a plain stub is enough since the gateway never type-checks the client.
        $client = new class
        {
            public $payments;

            public function api()
            {
                return $this;
            }
        };
        $client->payments = $this->payments;

        Mollie::swap($client);

        $this->gateway = new MolliePaymentGateway;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_appends_return_params_with_an_ampersand_when_the_return_url_already_has_a_query(): void
    {
        // Step 12: the pay-by-link surface passes a $returnUrl that already ends in its
        // ?ref=…&hash=… customer-authentication pair. A bare '?' append would produce a
        // malformed double-? URL and break the customer's return leg — the built redirectUrl
        // must contain exactly one '?' and keep the original params intact.
        $redirectUrl = $this->createPaymentAndCaptureRedirectUrl('https://example.com/pay?ref=RSV-1&hash=abc123');

        $this->assertSame(1, substr_count($redirectUrl, '?'));

        [$base, $query] = explode('?', $redirectUrl);
        parse_str($query, $params);

        $this->assertSame('https://example.com/pay', $base);
        $this->assertSame('RSV-1', $params['ref']);
        $this->assertSame('abc123', $params['hash']);
        $this->assertSame('123', $params['id']);
        $this->assertSame('mollie', $params['resrv_gateway']);
    }

    #[Test]
    public function it_appends_return_params_with_a_question_mark_when_the_return_url_has_no_query(): void
    {
        $redirectUrl = $this->createPaymentAndCaptureRedirectUrl('https://example.com/pay');

        $this->assertSame(1, substr_count($redirectUrl, '?'));

        [$base, $query] = explode('?', $redirectUrl);
        parse_str($query, $params);

        $this->assertSame('https://example.com/pay', $base);
        $this->assertSame('123', $params['id']);
        $this->assertSame('mollie', $params['resrv_gateway']);
    }

    #[Test]
    public function it_resumes_a_still_payable_payment_with_its_checkout_url(): void
    {
        // Step 13: a still-payable ('open') payment must come back with ->redirectTo (Mollie's
        // hosted checkout URL) so Resrv can forward a returning customer to it instead of
        // voiding the intent and minting a fresh one on every resume.
        $this->payments->shouldReceive('get')
            ->once()
            ->with('tr_123')
            ->andReturn($this->molliePayment('open', 'https://mollie.test/checkout/tr_123'));

        $intent = $this->gateway->retrievePaymentIntent('tr_123', $this->mockReservation());

        $this->assertSame('tr_123', $intent->id);
        $this->assertSame('https://mollie.test/checkout/tr_123', $intent->redirectTo);
        // 'open' must not map onto the magic statuses — it is what marks the intent resumable.
        $this->assertNotContains($intent->status, ['succeeded', 'processing', 'requires_capture', 'canceled']);
    }

    #[Test]
    public function it_maps_mollie_statuses_to_the_stripe_style_vocabulary(): void
    {
        // paid/pending/authorized mean money is moving — Resrv must not mint another intent.
        // canceled/expired are dead. failed is terminal and non-retryable at Mollie (a new
        // payment is required), so it must read as canceled — reporting it payable would strand
        // the customer on an intent Mollie will never let them complete.
        $expectations = [
            'paid' => 'succeeded',
            'pending' => 'processing',
            'authorized' => 'processing',
            'canceled' => 'canceled',
            'expired' => 'canceled',
            'failed' => 'canceled',
        ];

        foreach ($expectations as $mollieStatus => $expected) {
            $this->payments->shouldReceive('get')
                ->once()
                ->with('tr_123')
                ->andReturn($this->molliePayment($mollieStatus, null));

            $intent = $this->gateway->retrievePaymentIntent('tr_123', $this->mockReservation());

            $this->assertSame($expected, $intent->status, "Mollie status [{$mollieStatus}] should map to [{$expected}].");
        }
    }

    #[Test]
    public function it_reports_an_open_payment_without_a_checkout_url_instead_of_null(): void
    {
        // Not "definitively gone": returning null would let Resrv mint a replacement without
        // voiding this one, leaving it payable at Mollie. Returning it with redirectTo = null
        // makes Resrv void it first, then mint the replacement.
        $this->payments->shouldReceive('get')
            ->once()
            ->with('tr_123')
            ->andReturn($this->molliePayment('open', null));

        $intent = $this->gateway->retrievePaymentIntent('tr_123', $this->mockReservation());

        $this->assertNotNull($intent);
        $this->assertSame('open', $intent->status);
        $this->assertNull($intent->redirectTo);
    }

    #[Test]
    public function it_returns_null_when_the_payment_is_definitively_gone(): void
    {
        $this->payments->shouldReceive('get')
            ->once()
            ->with('tr_gone')
            ->andThrow($this->apiException(404));

        $this->assertNull($this->gateway->retrievePaymentIntent('tr_gone', $this->mockReservation()));
    }

    #[Test]
    public function it_propagates_transient_retrieve_failures_instead_of_returning_null(): void
    {
        // Swallowing a 5xx as null would let Resrv replace a still-live payment on the strength
        // of a failed read — the transient failure must reach the caller.
        $this->payments->shouldReceive('get')
            ->once()
            ->with('tr_123')
            ->andThrow($this->apiException(500));

        $this->expectException(ApiException::class);

        $this->gateway->retrievePaymentIntent('tr_123', $this->mockReservation());
    }

    protected function createPaymentAndCaptureRedirectUrl(string $returnUrl): string
    {
        $price = Mockery::mock();
        $price->shouldReceive('format')->andReturn('100.00');

        $molliePayment = Mockery::mock(Payment::class);
        $molliePayment->id = 'tr_123';
        $molliePayment->shouldReceive('getCheckoutUrl')->andReturn('https://mollie.test/checkout/tr_123');

        $captured = null;
        $this->payments->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($args) use (&$captured) {
                $captured = $args;

                return true;
            }))
            ->andReturn($molliePayment);

        $this->gateway->paymentIntent($price, $this->mockReservation(), [], $returnUrl);

        $this->assertIsString($captured['redirectUrl'] ?? null);

        return $captured['redirectUrl'];
    }

    protected function molliePayment(string $status, ?string $checkoutUrl)
    {
        $payment = Mockery::mock(Payment::class);
        $payment->id = 'tr_123';
        $payment->status = $status;
        $payment->shouldReceive('getCheckoutUrl')->andReturn($checkoutUrl);

        return $payment;
    }

    protected function apiException(int $statusCode): ApiException
    {
        $exception = Mockery::mock(ApiException::class);
        $exception->shouldReceive('getStatusCode')->andReturn($statusCode);

        return $exception;
    }

    protected function mockReservation(): Reservation
    {
        $entry = new \stdClass;
        $entry->title = 'Test Reservation';

        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();
        $reservation->shouldReceive('getAttribute')->with('id')->andReturn(123);
        $reservation->shouldReceive('entry')->andReturn($entry);

        return $reservation;
    }
}
