#!/usr/bin/env bash
# End-to-end run of the plugin inside sylius/test-application against a mock Bosta API
# (tests/E2E/mock_bosta.php) that follows the documented contract and plays Bosta's webhooks.
#
#   tests/E2E/run.sh                       # every scenario
#   SCENARIOS="cod retry" tests/E2E/run.sh
#   KEEP=1 tests/E2E/run.sh                # leave the containers running
set -euo pipefail
cd "$(dirname "$0")/../.."
ROOT=$PWD
E2E=$ROOT/tests/E2E
IMG=ghcr.io/sylius/sylius-php:8.3-alpine
NET=bosta-e2e
DB_URL="mysql://root:root@bosta-e2e-db:3306/bosta_e2e?serverVersion=8.4"
ENVS=(-e APP_ENV=test -e APP_DEBUG=1 -e DATABASE_URL="$DB_URL" -e SYLIUS_TEST_APP_CONFIGS_TO_IMPORT=/e2e/app_config.yaml
      -e SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=sync:// -e SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN=sync://
      -e MESSENGER_TRANSPORT_DSN=sync:// -e SYLIUS_MESSENGER_TRANSPORT_MAIN_DSN=sync://)
VOLS=(-v "$ROOT:/app" -v "$E2E:/e2e")

cleanup() { [[ "${KEEP:-}" == 1 ]] || { docker rm -f bosta-app bosta-mock bosta-e2e-db >/dev/null 2>&1; docker network rm $NET >/dev/null 2>&1; } || true; }
trap cleanup EXIT
app() { docker run --rm --network $NET -u "$(id -u):$(id -g)" "${VOLS[@]}" "${ENVS[@]}" -w /app --entrypoint sh $IMG -c "$*"; }

docker network create $NET >/dev/null 2>&1 || true
docker run -d --name bosta-e2e-db --network $NET -e MYSQL_ROOT_PASSWORD=root mysql:8.4 >/dev/null
docker run -d --name bosta-mock --network $NET -e PHP_CLI_SERVER_WORKERS=4 -v "$E2E:/e2e" -w /e2e --entrypoint php $IMG -S 0.0.0.0:8002 mock_bosta.php >/dev/null
until docker exec bosta-e2e-db mysqladmin -uroot -proot ping 2>/dev/null | grep -q alive; do sleep 2; done

# The shop pages only need the Encore entrypoint files to exist.
for b in shop admin app/shop app/admin; do
    mkdir -p vendor/sylius/test-application/public/build/$b
    [[ -f vendor/sylius/test-application/public/build/$b/entrypoints.json ]] || \
        echo '{"entrypoints":{"shop-entry":{},"admin-entry":{},"admin-product-entry":{},"app-shop-entry":{},"app-admin-entry":{},"plugin-shop-entry":{},"plugin-admin-entry":{}}}' > vendor/sylius/test-application/public/build/$b/entrypoints.json
done

app 'vendor/bin/console cache:clear -q; vendor/bin/console doctrine:database:create -n -q; vendor/bin/console doctrine:migrations:migrate -n -q;
     vendor/bin/console sylius:fixtures:load default -n -q; [ -f vendor/sylius/test-application/config/encryption/key ] || vendor/bin/console sylius:payment:generate-key -q;
     php /e2e/setup.php'
docker run -d --name bosta-app --network $NET -e PHP_CLI_SERVER_WORKERS=4 -u "$(id -u):$(id -g)" "${VOLS[@]}" "${ENVS[@]}" -w /app/vendor/sylius/test-application/public \
    --entrypoint php $IMG -d variables_order=EGPCS -S 0.0.0.0:8000 index.php >/dev/null
sleep 2

docker run --rm --network $NET -v "$E2E:/e2e" --entrypoint php $IMG /e2e/admin_create_method.php

drive() { rm -f "$E2E/mock_state.json"; docker run --rm --network $NET -u "$(id -u):$(id -g)" "${VOLS[@]}" "${ENVS[@]}" -w /app --entrypoint php $IMG /e2e/drive.php "$1"; }
for scenario in ${SCENARIOS:-cod prepaid retry lost recreate forged}; do drive "$scenario"; done
[[ -n "${SCENARIOS:-}" ]] && exit 0
docker run --rm --network $NET -v "$E2E:/e2e" --entrypoint php $IMG /e2e/admin_order.php
logo=$(app 'php /e2e/console.php render-checkout-choice bosta' 2>/dev/null)
other=$(app 'php /e2e/console.php render-checkout-choice ups' 2>/dev/null)
if grep -q 'aria-label="Bosta"' <<<"$logo" && ! grep -q 'aria-label="Bosta"' <<<"$other"; then echo '  ✔ checkout shows the Bosta logo on the Bosta method only'; else echo '  ✘ checkout logo'; exit 1; fi
