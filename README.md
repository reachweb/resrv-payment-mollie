# Resrv Payment Gateway for Mollie

This add-on adds a [Mollie](https://www.mollie.com) payment gateway to [Statamic Resrv](https://github.com/reachweb/statamic-resrv).

## Requirements

- Statamic 6 and Statamic Resrv 6.5 or later
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

## How Mollie payments behave

- **Bank transfer outlives the checkout hold.** A Mollie bank transfer stays open for 12 days or more until the customer's money arrives, but a normal checkout holds the reservation only for `minutes_to_hold`. The reservation therefore expires first, and a transfer that arrives later is not attached to it: admins get an orphaned-payment email and must refund it in Mollie. Disable bank transfer on the Mollie website profile you use with Resrv unless you accept that. On the pay-by-link page for manual reservations, a transfer confirms the booking as long as the booking is still awaiting payment when the money arrives.
- **Most open payments cannot be cancelled.** When a reservation is cancelled or its payment is replaced, the gateway cancels the Mollie payment only when Mollie allows it (`isCancelable`), which most methods don't. Such a payment stays payable at Mollie until it expires (15 minutes for cards and iDEAL, up to 12 days for bank transfer). Resrv keeps its reference on the reservation, and if the customer still pays it, admins get an orphaned-payment email so they can refund it. Marking a manual reservation as paid while such a payment is open sends the same kind of alert.
- **A customer who comes back without paying is asked to try again.** After a failed attempt, such as a declined card, Mollie shows its method page again and the payment stays open without calling the webhook. A customer who leaves it for your site waits a few seconds while the gateway checks the payment again, then sees the checkout-complete page's payment-failed message. A bank transfer, Multibanco or Pay by Bank payment, which completes at the customer's bank or an ATM, still shows as pending. The open payment stays payable until Mollie expires it: if the customer pays it anyway, the booking confirms while its hold lasts; after that, admins get an orphaned-payment email.
- **Authorized payments confirm once Mollie captures them.** Payments are created with Mollie's default automatic capture. A method that authorizes first (cards with a hold, Klarna, Billie) reaches `authorized`, Mollie captures it, and the booking confirms when the payment becomes `paid`. Methods that only support manual capture (Riverty, Billink) are not supported; don't enable them for this website profile.
- **Return links are signed.** The link Mollie sends the customer back to carries a signature, so the checkout-complete page shows a reservation only to the customer who paid for it. Payments created by v2.0.0 have unsigned links; those still work from the browser that started the checkout.

## Documentation

Please refer to the [Resrv documentation](https://resrv.eu/mollie.html) to learn more about how to install and configure this add-on.
