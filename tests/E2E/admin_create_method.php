<?php
// Creates the Bosta shipping method through the admin form, like a tenant admin would.
const APP = 'http://bosta-app:8000';
$jar = '/tmp/admin.jar';
function req(string $m, string $u, ?array $data = null): array
{
    global $jar;
    $ch = curl_init(APP . $u);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ['Host: localhost', 'Origin: http://localhost'], CURLOPT_FOLLOWLOCATION => false]);
    if (null !== $data) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $b = (string) curl_exec($ch);

    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $b, (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
}
function check(bool $ok, string $what): void { echo ($ok ? '  ✔ ' : '  ✘ '), $what, "\n"; if (!$ok) { exit(1); } }

[, $b] = req('GET', '/admin/login');
$fields = ['_username' => 'sylius', '_password' => 'sylius'];
preg_match_all('/<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"/', $b, $hidden, PREG_SET_ORDER);
foreach ($hidden as $h) { $fields[$h[1]] = html_entity_decode($h[2]); }
[$c, , $loc] = req('POST', '/admin/login-check', $fields);
check(302 === $c && !str_contains($loc, 'login'), "admin logged in ($c $loc)");

// The create page with the Bosta calculator chosen (what the live component renders once "Bosta" is picked).
[$c, $b] = req('GET', '/admin/shipping-methods/new');
check(200 === $c, 'shipping method create page renders');
check(str_contains($b, 'value="bosta"'), 'Bosta is offered as a shipping calculator');

preg_match_all('/<(?:input|select|textarea)[^>]*name="(sylius_admin_shipping_method\[[^"]+)"[^>]*>/', $b, $tags, PREG_SET_ORDER);
$all = [];
foreach ($tags as $tag) { preg_match('/value="([^"]*)"/', $tag[0], $v); $all[] = [$tag[0], $tag[1], $v[1] ?? '']; }
$form = [];
foreach ($all as $f) { if (!str_contains($f[1], '[configuration]') && !str_contains($f[1], '[rules]') && !str_contains($f[1], '__name__')) { $form[$f[1]] = html_entity_decode($f[2] ?? ''); } }
foreach (array_keys($form) as $k) { if (preg_match('/\[(channels|translations)\]/', $k) && !str_contains($k, '[translations]')) { unset($form[$k]); } }
$form['sylius_admin_shipping_method[code]'] = 'bosta';
$form['sylius_admin_shipping_method[zone]'] = 'EGYPT';
$form['sylius_admin_shipping_method[calculator]'] = 'bosta';
$form['sylius_admin_shipping_method[enabled]'] = '1';
$form['sylius_admin_shipping_method[channels][]'] = 'FASHION_WEB';
$form['sylius_admin_shipping_method[translations][en_US][name]'] = 'Bosta';
$form['sylius_admin_shipping_method[configuration][api_key]'] = 'bosta-test-key';
$form['sylius_admin_shipping_method[configuration][pickup_city]'] = 'Cairo';
$form['sylius_admin_shipping_method[configuration][package_size]'] = 'SMALL';
$form['sylius_admin_shipping_method[configuration][fee_mode]'] = 'flat';
$form['sylius_admin_shipping_method[configuration][channels][FASHION_WEB][amount]'] = '60';
$form['sylius_admin_shipping_method[configuration][cod_payment_methods][]'] = 'cash_on_delivery';
$form['sylius_admin_shipping_method[configuration][auto_create]'] = '1';
$form['sylius_admin_shipping_method[configuration][complete_cod_on_delivery]'] = '1';

// Bosta chosen but no API key yet: the create form re-renders with the Bosta configuration,
// exactly what the admin sees after picking "Bosta" as calculator.
$incomplete = $form;
$incomplete['sylius_admin_shipping_method[configuration][api_key]'] = '';
[$c, $b] = req('POST', '/admin/shipping-methods/new', $incomplete);
check(422 === $c && str_contains($b, 'Enter your Bosta API key.'), 'API key required');
check(str_contains($b, 'value="http://localhost/shipping/bosta/webhook"'), 'webhook URL is shown on the create form, before the method exists');
preg_match('/<input[^>]*name="sylius_admin_shipping_method\[_token\]"[^>]*>/', $b, $tag);
preg_match('/value="([^"]+)"/', $tag[0] ?? '', $t);
if (isset($t[1])) { $form['sylius_admin_shipping_method[_token]'] = $t[1]; }
preg_match('/<input[^>]*name="sylius_admin_shipping_method\[configuration\]\[webhook_secret\]"[^>]*>/', $b, $tag);
preg_match('/value="([^"]+)"/', $tag[0] ?? '', $sec);
check(isset($sec[1]) && strlen($sec[1]) >= 32, 'webhook secret pre-generated');
$form['sylius_admin_shipping_method[configuration][webhook_secret]'] = $sec[1];

[$c, $b] = req('POST', '/admin/shipping-methods/new', $form);
if (false) {
    check(str_contains($b, '/shipping/bosta/webhook'), 'webhook URL is shown on the create form, before the method exists (HTTP ' . $c . ')');
    preg_match('/<input[^>]*name="sylius_admin_shipping_method\[configuration\]\[webhook_secret\]"[^>]*>/', $b, $tag);
    preg_match('/value="([^"]+)"/', $tag[0] ?? '', $m);
    check(isset($m[1]) && strlen($m[1]) >= 32, 'webhook secret pre-generated');
    preg_match('/<input[^>]*name="sylius_admin_shipping_method\[_token\]"[^>]*>/', $b, $tag);
    preg_match('/value="([^"]+)"/', $tag[0] ?? '', $t);
    if (isset($t[1])) { $form['sylius_admin_shipping_method[_token]'] = $t[1]; }
    $form['sylius_admin_shipping_method[configuration][webhook_secret]'] = $m[1];
    [$c, $b, $loc] = req('POST', '/admin/shipping-methods/new', $form);
}
if (302 !== $c) { preg_match_all('/invalid-feedback[^>]*>([^<]+)/', $b, $errs); echo implode("\n", $errs[1] ?? []), "\n"; }
check(302 === $c, 'shipping method saved through the admin form');

$pdo = new PDO('mysql:host=bosta-e2e-db;dbname=bosta_e2e', 'root', 'root');
$method = $pdo->query("SELECT id, configuration FROM sylius_shipping_method WHERE code = 'bosta'")->fetch(PDO::FETCH_ASSOC);
$config = json_decode($method['configuration'], true) ?: unserialize($method['configuration']);
check('bosta-test-key' === ($config['api_key'] ?? null) && $sec[1] === ($config['webhook_secret'] ?? null) && 6000 === ($config['channels']['FASHION_WEB']['amount'] ?? null), 'configuration stored (key, secret, flat amount 60.00)');
[$c, $b] = req('GET', '/admin/shipping-methods/' . $method['id'] . '/edit');
check(str_contains($b, 'http://localhost/shipping/bosta/webhook'), 'webhook URL shown on the edit form');
