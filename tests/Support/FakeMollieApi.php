<?php

namespace Reach\ResrvPaymentMollie\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Psr\Http\Message\RequestInterface;

/**
 * Fakes only the network: the gateway builds its real MollieApiClient (middleware, idempotency
 * keys, retries, hydration, exceptions) on the Guzzle client it resolves from the container,
 * and this handler answers that client's requests from registered routes.
 */
class FakeMollieApi
{
    /** @var array<int, array{method: string, path: string, headers: array<string, string>, body: string, options: array}> */
    public array $sent = [];

    /** @var array<string, array|callable|string> */
    protected array $routes = [];

    public static function install(Application $app): self
    {
        $fake = new self;

        $app->bind(Client::class, fn ($app, array $parameters = []) => new Client(
            array_merge($parameters['config'] ?? [], ['handler' => $fake])
        ));

        return $fake;
    }

    /**
     * @param  array|callable|string  $response  [status, body], a callable returning one, or 'network'
     *                                           to fail the attempt with a connection error.
     */
    public function on(string $method, string $path, array|callable|string $response): self
    {
        $this->routes[$method.' '.$path] = $response;

        return $this;
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->sent[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'headers' => array_map(fn ($values) => implode(',', $values), $request->getHeaders()),
            'body' => (string) $request->getBody(),
            'options' => $options,
        ];

        $response = $this->routes[$request->getMethod().' '.$request->getUri()->getPath()]
            ?? throw new \RuntimeException('Unexpected Mollie request: '.$request->getMethod().' '.$request->getUri()->getPath());

        if (is_callable($response)) {
            $response = $response($request);
        }

        if ($response === 'network') {
            return Create::rejectionFor(new ConnectException('Connection timed out', $request));
        }

        [$status, $body] = $response;

        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/hal+json'], $body === null ? '' : json_encode($body)));
    }

    /** Requests sent so far, optionally filtered by method and path. */
    public function requests(?string $method = null, ?string $path = null): array
    {
        return array_values(array_filter($this->sent, fn ($request) => ($method === null || $request['method'] === $method)
            && ($path === null || $request['path'] === $path)));
    }

    public function lastBody(string $method, string $path): array
    {
        $requests = $this->requests($method, $path);

        return json_decode(end($requests)['body'], true);
    }

    public static function payment(array $overrides = []): array
    {
        $id = $overrides['id'] ?? 'tr_test1';

        $payment = array_replace_recursive([
            'resource' => 'payment',
            'id' => $id,
            'mode' => 'test',
            'status' => 'open',
            'amount' => ['value' => '55.00', 'currency' => 'EUR'],
            'description' => 'Sea view room',
            'metadata' => ['reservation_id' => 1],
            'isCancelable' => false,
            'createdAt' => '2026-11-14T10:00:00+00:00',
            '_links' => [
                'self' => ['href' => 'https://api.mollie.com/v2/payments/'.$id, 'type' => 'application/hal+json'],
                'checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/'.$id, 'type' => 'text/html'],
            ],
        ], $overrides);

        // A null link removes it, as Mollie omits links that don't apply.
        $payment['_links'] = array_filter($payment['_links'], fn ($link) => $link !== null);

        return $payment;
    }

    public static function paid(array $overrides = []): array
    {
        return self::payment(array_replace_recursive([
            'status' => 'paid',
            'method' => 'creditcard',
            'paidAt' => '2026-11-14T10:01:00+00:00',
            'amountRemaining' => ['value' => '55.00', 'currency' => 'EUR'],
            'amountRefunded' => ['value' => '0.00', 'currency' => 'EUR'],
            '_links' => ['checkout' => null],
        ], $overrides));
    }

    public static function refund(array $overrides = []): array
    {
        return array_replace_recursive([
            'resource' => 'refund',
            'id' => 're_test1',
            'status' => 'pending',
            'amount' => ['value' => '55.00', 'currency' => 'EUR'],
            'paymentId' => 'tr_test1',
        ], $overrides);
    }

    public static function error(int $status, string $detail): array
    {
        return [$status, ['status' => $status, 'title' => 'Error', 'detail' => $detail]];
    }
}
