<?php

namespace Reach\ResrvPaymentMollie\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Reach\ResrvPaymentMollie\Http\Payment\MolliePaymentGateway;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('resrv-config.currency_isoCode', 'EUR');

        // Registered under a key different from name() so configKey() is exercised for real:
        // the gateway must resolve its config key through PaymentGatewayManager, not name().
        $app['config']->set('resrv-config.payment_gateways', [
            'mollie' => [
                'class' => MolliePaymentGateway::class,
                'label' => 'Mollie',
            ],
        ]);
    }

    protected function defineRoutes($router): void
    {
        // The gateway bakes this named route into every Mollie payment as its webhookUrl.
        $router->post('resrv/webhook/{gateway}', fn () => '')->name('resrv.webhook.gateway.store');
    }
}
