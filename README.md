# Resrv Payment Gateway for Mollie

This add-on adds a [Mollie](https://www.mollie.com) payment gateway to [Statamic Resrv](https://github.com/reachweb/statamic-resrv).

## Requirements

- Statamic 6 and Statamic Resrv 6
- A Mollie account and API key

> For Resrv 5 or earlier, use [v1.0.0](https://github.com/reachweb/resrv-payment-mollie/releases/tag/v1.0.0) of this add-on.

## Installation

```bash
composer require reachweb/resrv-payment-mollie
```

Add your Mollie API key to `.env`:

```
MOLLIE_KEY=live_xxxxxxxxxxxxxxxxxxxxxxxxxx
```

## Configuration

Register the gateway in `config/resrv-config.php` under `payment_gateways`:

```php
'payment_gateways' => [
    'mollie' => [
        'class' => \Reach\ResrvPaymentMollie\Http\Payment\MolliePaymentGateway::class,
        'label' => 'Mollie', // optional, shown in the checkout gateway picker
    ],
],
```

The config key (`'mollie'`) is stored on each reservation and used to resolve webhooks, refunds and redirect callbacks — don't change it after reservations have been created with it.

## Webhooks

The gateway sets the webhook URL (`/resrv/api/webhook/<config-key>`) on every payment automatically, so nothing needs to be configured in the Mollie dashboard. Mollie must be able to reach your site, though — for local development use a public tunnel (e.g. ngrok or Expose), since Mollie rejects payments with an unreachable webhook URL.

## Documentation

Please refer to the [Resrv documentation](https://resrv.eu/mollie.html) to learn more about how to install and configure this add-on.
