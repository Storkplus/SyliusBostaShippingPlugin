# Sylius Bosta Shipping Plugin

[Bosta](https://bosta.co) (Egypt) shipping for Sylius 2.1+:

- **`bosta` shipping calculator**: flat fee per channel, or Bosta's fee estimate for the customer's city with the
  flat fee as fallback. Configured on the shipping method (API key, pickup city, package size, COD payment
  methods...).
- **Automatic delivery creation**: at checkout for cash-on-delivery orders, when the order is paid for online
  payments. Runs after the response is sent, so checkout never waits on Bosta and a Bosta outage never breaks it.
- **Same tracking number everywhere**: the Sylius shipment tracking code is the Bosta tracking number.
- **Webhooks** on a static per-store URL keep the order in step: picked up / in transit → shipment *shipped*,
  delivered → cash-on-delivery payment *completed* → order *fulfilled*. Exceptions, returns, loss are shown to the
  merchant.
- **Fault tolerant**: every failure is stored with its reason; the admin can *Retry*, *Recreate* (cancels the
  pending Bosta delivery and creates a new one), *Refresh status* and print the *Airway bill* from the order page.
  Retries never create duplicates (stable `uniqueBusinessReference`; a lost response is recovered by reference).
- Bosta logo on the checkout shipping step; Bosta links on the admin order page (merchant view) and the customer
  order pages (public tracking).
- English and Arabic.

## Requirements

PHP 8.2+, Sylius 2.1 to 2.3, MySQL/MariaDB.

## Installation

```bash
composer config repositories.sylius-bosta-shipping-plugin vcs https://github.com/Storkplus/SyliusBostaShippingPlugin
composer require storkplus/sylius-bosta-shipping-plugin
```

```php
// config/bundles.php
Storkplus\SyliusBostaShippingPlugin\StorkplusSyliusBostaShippingPlugin::class => ['all' => true],
```

```yaml
# config/packages/storkplus_sylius_bosta_shipping.yaml
imports:
    - { resource: "@StorkplusSyliusBostaShippingPlugin/config/config.yaml" }

# config/routes/storkplus_sylius_bosta_shipping.yaml
storkplus_sylius_bosta_shipping:
    resource: "@StorkplusSyliusBostaShippingPlugin/config/routes.yaml"
```

Database: `bin/console doctrine:migrations:migrate` (adds `storkplus_bosta_delivery`). The SQL is also available as
`Storkplus\SyliusBostaShippingPlugin\Migrations\Version20261005000000::CREATE_TABLE` for setups that manage schemas
by hand.

Optional safety net, e.g. every 15 minutes: `bin/console storkplus:bosta:sync` retries failed creations and
refreshes deliveries whose webhook never arrived.

## Setting up the shipping method

1. In Bosta: **Settings → API Integration → Create API key** with *Read/Write* access.
2. In Sylius admin: **Configuration → Shipping methods → Create**, calculator **Bosta**, a zone covering Egypt.
   The form shows the store's webhook URL, `https://<store>/shipping/bosta/webhook`, and a generated secret.
3. Fill in the API key, pickup city, the flat amount per channel and which payment methods are cash on delivery.
4. Optional: in Bosta **API Integration → Webhook**, add the webhook URL with key name `X-Bosta-Secret` and the
   secret as value. Not required for deliveries created by the store: each one is created with that URL and header.

Addresses: Bosta needs one of its governorates (city) and, ideally, a district. The plugin reads the governorate
from the address province (name, Arabic name or ISO `EG-*` code) or city, and the district from the city/street
(e.g. "Nasr City", "Maadi", "6th of October"). If Bosta still refuses an address, the delivery is marked failed with
Bosta's message; fix the address and click *Retry*.

## How a shipment flows

```
order placed (COD) / order paid (online)
  └─ kernel.terminate → POST /deliveries  (uniqueBusinessReference = <STORE>-<order>-<shipment>)
       ok      → tracking number on the shipment, Bosta card shows "Sent to Bosta"
       failed  → reason stored, admin Retry / bin/console storkplus:bosta:sync
Bosta webhook POST /shipping/bosta/webhook  (X-Bosta-Secret)
  21/24/30/41 picked up, in transit  → shipment "ship" (customer gets the shipping email with tracking)
  45 delivered                       → COD payment "complete" → order fulfilled
  46/48/49/100/101                   → shown in red on the order for the merchant
```

## Development

```bash
composer install
vendor/bin/phpunit --testsuite unit
vendor/bin/phpstan analyse -c phpstan.neon
vendor/bin/ecs check

# End to end (Docker): test application + MySQL + a mock Bosta API playing webhooks.
tests/E2E/run.sh
```

Logs: Monolog channel `bosta`.
