<?php
// E2E driver. Usage: php drive.php <cod|prepaid|retry|lost|recreate|forged>
$scenario = $argv[1] ?? 'cod';
const APP = 'http://bosta-app:8000';
const MOCK = 'http://bosta-mock:8002';
$jar = tempnam('/tmp', 'jar');
function req(string $method, string $url, $body = null, array $headers = []): array
{
    global $jar;
    if (str_starts_with($url, '/')) { $url = APP . $url; }
    $url = (string) preg_replace('#^https?://localhost(:\d+)?#', APP, $url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => array_merge(['Host: localhost', 'Accept: application/ld+json'], $headers)]);
    if (null !== $body) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body)); }
    $raw = (string) curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    preg_match('/^location:\s*(.+)$/mi', substr($raw, 0, $hs), $m);

    return ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'location' => isset($m[1]) ? trim($m[1]) : null, 'json' => json_decode(substr($raw, $hs), true), 'body' => substr($raw, $hs)];
}
function api(string $method, string $path, $body = null): array
{
    $r = req($method, $path, $body, ['Content-Type: ' . ('PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json')]);
    if ($r['code'] >= 400) { fwrite(STDERR, "API $method $path -> {$r['code']} " . substr($r['body'], 0, 500) . "\n"); exit(1); }

    return $r['json'];
}
function mock(string $path, $body = null): array
{
    $ch = curl_init(MOCK . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body ?? new stdClass()), CURLOPT_RETURNTRANSFER => true]);

    return json_decode((string) curl_exec($ch), true) ?? [];
}
function db(string $sql): array { return (new PDO('mysql:host=bosta-e2e-db;dbname=bosta_e2e', 'root', 'root'))->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
function step(string $s): void { echo "→ $s\n"; }
function check(bool $ok, string $what): void { echo ($ok ? '  ✔ ' : '  ✘ '), $what, "\n"; if (!$ok) { echo "FAIL\n"; exit(1); } }
function waitFor(callable $condition, int $seconds = 10): bool { for ($i = 0; $i < $seconds * 4; ++$i) { if ($condition()) { return true; } usleep(250000); } return false; }

// --- checkout via Shop API
$payment = 'prepaid' === $scenario ? 'bank_transfer' : 'cash_on_delivery';
$variant = db("SELECT code FROM sylius_product_variant WHERE enabled = 1 ORDER BY id LIMIT 1")[0]['code'];
$order = api('POST', '/api/v2/shop/orders', ['localeCode' => 'en_US']);
$token = $order['tokenValue'];
api('POST', "/api/v2/shop/orders/$token/items", ['productVariant' => "/api/v2/shop/product-variants/$variant", 'quantity' => 2]);
$order = api('PUT', "/api/v2/shop/orders/$token", ['email' => "aya.$scenario@example.com", 'billingAddress' => [
    'firstName' => 'Aya', 'lastName' => 'Hassan', 'street' => '12 Abbas El Akkad St', 'city' => 'Nasr City', 'provinceName' => 'Cairo',
    'postcode' => '11371', 'countryCode' => 'EG', 'phoneNumber' => '+20 100 123 4567']]);
$shipmentId = $order['shipments'][0]['id'];
$methods = api('GET', "/api/v2/shop/orders/$token/shipments/$shipmentId/methods");
$codes = array_map(fn ($m) => $m['code'] ?? basename((string) ($m['shippingMethod'] ?? '')), $methods['hydra:member'] ?? $methods['member'] ?? []);
check(in_array('bosta', $codes, true), 'Bosta offered for an Egyptian address (' . implode(', ', $codes) . ')');
api('PATCH', "/api/v2/shop/orders/$token/shipments/$shipmentId", ['shippingMethod' => '/api/v2/shop/shipping-methods/bosta']);
$order = api('GET', "/api/v2/shop/orders/$token");
check(6000 === $order['shippingTotal'], "shipping fee from the Bosta calculator: {$order['shippingTotal']}");
api('PATCH', "/api/v2/shop/orders/$token/payments/{$order['payments'][0]['id']}", ['paymentMethod' => "/api/v2/shop/payment-methods/$payment"]);

if ('retry' === $scenario) { mock('/_mock/fail_next_create', ['count' => 1]); }
if ('lost' === $scenario) { mock('/_mock/lose_next_create'); }

$order = api('PATCH', "/api/v2/shop/orders/$token/complete", ['notes' => 'ring twice']);
step("order {$order['number']} placed ({$payment}), total {$order['total']}");

$delivery = fn () => db("SELECT d.*, s.tracking, s.state shipment_state FROM storkplus_bosta_delivery d JOIN sylius_shipment s ON s.id = d.shipment_id JOIN sylius_order o ON o.id = s.order_id WHERE o.token_value = '$token'")[0] ?? null;
$orderRow = fn () => db("SELECT state, payment_state, shipping_state FROM sylius_order WHERE token_value = '$token'")[0];
$mockDelivery = fn (string $tn) => mock('/_mock/state')['deliveries'][$tn] ?? null;

if ('prepaid' === $scenario) {
    usleep(1500000);
    check(null === $delivery(), 'unpaid online order is not sent to Bosta yet');
    $paymentId = db("SELECT p.id FROM sylius_payment p JOIN sylius_order o ON o.id = p.order_id WHERE o.token_value = '$token'")[0]['id'];
    // The merchant confirms the bank transfer: the order becomes paid, then Bosta is called.
    $out = shell_exec("php /e2e/console.php complete-payment $paymentId 2>&1");
    step('payment completed by the admin: ' . trim((string) $out));
    check(waitFor(fn () => 'created' === ($delivery()['status'] ?? null)), 'delivery created once the order is paid');
    $tn = $delivery()['tracking_number'];
    check(0 == $mockDelivery($tn)['cod'], 'prepaid order sent with COD 0');
    echo "PASS $scenario\n";
    exit(0);
}

if ('retry' === $scenario) {
    check(waitFor(fn () => 'failed' === ($delivery()['status'] ?? null)), 'Bosta outage recorded as failed: ' . ($delivery()['last_error'] ?? ''));
    check(null === $delivery()['tracking'], 'no tracking number while failed');
    $out = shell_exec("php /e2e/console.php admin-action create {$delivery()['shipment_id']} 2>&1");
    step('admin clicks Retry: ' . trim((string) $out));
    check('created' === $delivery()['status'] && '2' == $delivery()['attempts'], 'retry created the delivery on the 2nd attempt');
    check(1 === count(mock('/_mock/state')['deliveries']), 'exactly one delivery at Bosta');
    echo "PASS $scenario\n";
    exit(0);
}

check(waitFor(fn () => 'created' === ($delivery()['status'] ?? null)), 'Bosta delivery created right after checkout');
$d = $delivery();
$tn = $d['tracking_number'];
check($d['tracking'] === $tn, "Sylius shipment tracking = Bosta tracking number ($tn)");
$sent = $mockDelivery($tn);

if ('lost' === $scenario) {
    check(1 === count(mock('/_mock/state')['deliveries']) && '1' == $d['attempts'], 'lost create response: existing delivery adopted, no duplicate');
    echo "PASS $scenario\n";
    exit(0);
}

check(10 === $sent['type'] && 'Cairo' === $sent['dropOffAddress']['city'] && !empty($sent['dropOffAddress']['districtId']), 'payload: SEND to Cairo with a resolved district');
check(abs($sent['cod'] - $order['total'] / 100) < 0.01, "COD = order total ({$sent['cod']} EGP)");
check('01001234567' === $sent['receiver']['phone'], 'receiver phone normalised to 01XXXXXXXXX');
check(str_ends_with($sent['webhookUrl'], '/shipping/bosta/webhook') && 48 === strlen($sent['webhookCustomHeaders']['X-Bosta-Secret'] ?? ''), 'per-delivery webhook URL + secret header');
check(str_contains($sent['uniqueBusinessReference'], $order['number']), "reference {$sent['uniqueBusinessReference']}");

if ('forged' === $scenario) {
    $r = mock("/_mock/webhook/$tn", ['state' => 45, 'headers' => ['X-Bosta-Secret' => 'guess']]);
    check(401 === $r['code'], 'webhook with a wrong secret rejected (401)');
    check('ready' === $delivery()['shipment_state'] && 'awaiting_payment' === $orderRow()['payment_state'], 'order untouched');
    echo "PASS $scenario\n";
    exit(0);
}

if ('recreate' === $scenario) {
    $out = shell_exec("php /e2e/console.php admin-action recreate {$d['shipment_id']} 2>&1");
    step('admin clicks Recreate: ' . trim((string) $out));
    $new = $delivery();
    check($new['tracking_number'] !== $tn && $new['tracking'] === $new['tracking_number'], "new tracking number {$new['tracking_number']} on the shipment");
    check(48 === $mockDelivery($tn)['state']['code'], 'previous Bosta delivery terminated');
    check(str_ends_with($mockDelivery($new['tracking_number'])['uniqueBusinessReference'], '-R2'), 'new delivery under a new reference');
    mock('/_mock/webhook/' . $new['tracking_number'], ['state' => 21]);
    $out = shell_exec("php /e2e/console.php admin-action recreate {$d['shipment_id']} 2>&1");
    check(str_contains((string) $out, 'can no longer be replaced'), 'picked-up delivery cannot be recreated: ' . trim((string) $out));
    echo "PASS $scenario\n";
    exit(0);
}

// --- cod: the full Bosta lifecycle
$r = mock("/_mock/webhook/$tn", ['state' => 24]);
check(200 === $r['code'] && 'shipped' === $delivery()['shipment_state'], 'webhook "Received at warehouse" → shipment shipped');
$r = mock("/_mock/webhook/$tn", ['state' => 47, 'exceptionReason' => 'Customer is not answering.', 'exceptionCode' => 7]);
check('Customer is not answering.' === $delivery()['exception_reason'], 'exception reason recorded');
$r = mock("/_mock/webhook/$tn", ['state' => 45, 'cod' => $sent['cod']]);
$o = $orderRow();
check('paid' === $o['payment_state'] && 'shipped' === $o['shipping_state'] && 'fulfilled' === $o['state'], "delivered → COD paid, order fulfilled ({$o['state']}/{$o['payment_state']}/{$o['shipping_state']})");

$r = req('GET', "/en_US/order/$token");
check(str_contains($r['body'], "bosta.co/tracking-shipments?shipment-number=$tn"), 'customer order page links the Bosta tracking page');
file_put_contents('/e2e/last_order_token', $token);
echo "PASS $scenario\n";
