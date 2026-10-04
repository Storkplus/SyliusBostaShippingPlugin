<?php
// Admin side of the cod order: Bosta card, links, refresh + airway bill through the real controller (CSRF).
const APP = 'http://bosta-app:8000';
$jar = '/tmp/admin-order.jar';
function req(string $m, string $u, ?array $data = null): array
{
    global $jar;
    $ch = curl_init(APP . $u);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ['Host: localhost', 'Origin: http://localhost'], CURLOPT_FOLLOWLOCATION => false]);
    if (null !== $data) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $b = (string) curl_exec($ch);

    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $b, (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
}
function check(bool $ok, string $what): void { echo ($ok ? '  ✔ ' : '  ✘ '), $what, "\n"; if (!$ok) { exit(1); } }

[, $b] = req('GET', '/admin/login');
$fields = ['_username' => 'sylius', '_password' => 'sylius'];
preg_match_all('/<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"/', $b, $hidden, PREG_SET_ORDER);
foreach ($hidden as $h) { $fields[$h[1]] = html_entity_decode($h[2]); }
req('POST', '/admin/login-check', $fields);

$pdo = new PDO('mysql:host=bosta-e2e-db;dbname=bosta_e2e', 'root', 'root');
$token = trim((string) file_get_contents('/e2e/last_order_token'));
$row = $pdo->query("SELECT o.id, d.tracking_number, s.id shipment_id FROM sylius_order o JOIN sylius_shipment s ON s.order_id = o.id JOIN storkplus_bosta_delivery d ON d.shipment_id = s.id WHERE o.token_value = '$token'")->fetch(PDO::FETCH_ASSOC);

[$c, $b] = req('GET', "/admin/orders/{$row['id']}");
check(200 === $c, 'admin order page renders');
check(str_contains($b, 'id="bosta"'), 'Bosta card on the order page');
check(str_contains($b, 'https://business.bosta.co/orders/' . $row['tracking_number']), 'link to the shipment on Bosta');
check(str_contains($b, 'aria-label="Bosta"'), 'Bosta logo on the card');

preg_match('#action="(/admin/bosta/shipments/\d+/refresh)".*?name="_csrf_token" value="([^"]+)"#s', $b, $m);
check(isset($m[2]), 'Refresh button with CSRF token');
[$c, , $loc] = req('POST', $m[1], ['_csrf_token' => $m[2]]);
check(302 === $c && str_contains($loc, '#bosta'), 'Refresh status handled, back to the order');
[$c] = req('POST', $m[1], ['_csrf_token' => 'forged']);
check(403 === $c, 'admin action without a valid CSRF token refused');

[$c, $pdf, , $type] = req('GET', "/admin/bosta/shipments/{$row['shipment_id']}/airway-bill");
check(200 === $c && str_starts_with($pdf, '%PDF') && str_contains($type, 'application/pdf'), 'airway bill PDF served');
