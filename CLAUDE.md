# CLAUDE.md

Bosta (Egypt) shipping plugin for Sylius 2.1+. Package `storkplus/sylius-bosta-shipping-plugin`, namespace
`Storkplus\SyliusBostaShippingPlugin`, shipping calculator `bosta`. Started from `Sylius/PluginSkeleton` (2.2).

## Commands

Run tooling in the Sylius PHP image (host PHP lacks extensions):
`docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app --entrypoint sh ghcr.io/sylius/sylius-php:8.3-alpine -c '<cmd>'`

- `vendor/bin/phpunit --testsuite unit`, `vendor/bin/phpstan analyse -c phpstan.neon` (max, src), `vendor/bin/ecs check`
- `tests/E2E/run.sh` — full flow against `tests/E2E/mock_bosta.php`

## Architecture

- `Bosta` constants (state codes, config keys); `BostaConfiguration` typed view of the shipping method config.
- `Api/BostaClient` — raw API key in `Authorization` (no "Bearer"); `{success:false,message,errorCode}` errors;
  `citiesWithDistricts()` is public and cached a day in `cache.app` (global data, safe to share across tenants).
- `Address/BostaAddressResolver` — free-text Sylius address → Bosta city + district (aliases, ISO codes, Arabic,
  glued "El" prefixes, zone names). Unknown district → `districtName` + `cityId`.
- `Manager/BostaDeliveryManager` — create / retry / recreate / refresh; never throws for Bosta errors (stored on
  `Entity/BostaDelivery`); adopts a delivery found by reference after an ambiguous failure.
- `Manager/DeliveryStateApplier` — Bosta state → shipment `ship`, COD payment `complete`. Never backwards.
- `EventListener/AutoCreateDeliveryListener` — queues on checkout complete (COD) / order paid; runs on terminate.
- `Controller/WebhookAction` (static `/shipping/bosta/webhook`, `X-Bosta-Secret`), `Controller/AdminDeliveryController`.

## Rules

- Bosta does not sign webhooks: authenticate only by the per-method secret header.
- Keep the reference stable across retries; only `recreate` bumps the sequence.
- Translations in both `*.en.yaml` and `*.ar.yaml`.
