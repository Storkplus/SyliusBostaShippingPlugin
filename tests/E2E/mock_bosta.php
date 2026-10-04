<?php
// Mock of the Bosta v2 API (documented contract) + control endpoints that play Bosta's webhooks.
$db = '/e2e/mock_state.json'; // git-ignored
$state = is_file($db) ? json_decode(file_get_contents($db), true) : ['deliveries' => [], 'log' => [], 'fail_next_create' => 0, 'lose_next_create' => 0, 'next' => 0];
if (0 === $state['next']) { $state['next'] = 61000000 + (int) (microtime(true) * 10) % 1000000 * 10; } // unique across resets
$save = function () use (&$state, $db) { file_put_contents($db, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); };
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input') ?: 'null', true);
$state['log'][] = "$method $path";
header('Content-Type: application/json');
$json = function ($code, $data) use ($save) { $save(); http_response_code($code); echo json_encode($data); exit; };
$err = fn ($code, $msg, $errorCode = null) => $json($code, ['success' => false, 'message' => $msg, 'errorCode' => $errorCode, 'data' => null]);

if (str_starts_with($path, '/_mock/')) {
    $parts = explode('/', trim($path, '/'));
    if ('state' === $parts[1]) { $json(200, $state); }
    if ('fail_next_create' === $parts[1]) { $state['fail_next_create'] = (int) ($body['count'] ?? 1); $json(200, ['ok' => true]); }
    if ('lose_next_create' === $parts[1]) { $state['lose_next_create'] = 1; $json(200, ['ok' => true]); }
    if ('webhook' === $parts[1]) { // play Bosta: POST the state change to the delivery's webhookUrl with its custom headers
        $d = &$state['deliveries'][$parts[2]];
        $d['state'] = ['code' => (int) $body['state'], 'value' => 'state ' . $body['state']];
        $payload = ['_id' => $d['_id'], 'trackingNumber' => (int) $d['trackingNumber'], 'state' => (int) $body['state'], 'type' => 'SEND', 'timeStamp' => time() * 1000, 'businessReference' => $d['businessReference'], 'numberOfAttempts' => 0] + array_intersect_key($body, ['exceptionReason' => 1, 'exceptionCode' => 1, 'cod' => 1]);
        $headers = ['Content-Type: application/json', 'Host: localhost'];
        foreach ($body['headers'] ?? $d['webhookCustomHeaders'] ?? [] as $k => $v) { $headers[] = "$k: $v"; }
        // The test channel is "localhost"; inside the docker network the shop is bosta-app:8000.
        $ch = curl_init((string) preg_replace('#^https?://localhost(:\d+)?#', 'http://bosta-app:8000', $d['webhookUrl']));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $state['log'][] = "WEBHOOK {$parts[2]} state={$body['state']} -> $code";
        $json(200, ['code' => $code, 'body' => $resp]);
    }
    $json(400, ['error' => 'unknown control']);
}

if ('GET' === $method && '/api/v2/cities/getAllDistricts' === $path) {
    $json(200, ['success' => true, 'message' => 'Done successfully.', 'data' => json_decode(file_get_contents('/e2e/districts.json'), true)]);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ('bosta-test-key' !== $auth) { $err(401, 'Unauthorized'); }

if ('POST' === $method && '/api/v2/deliveries' === $path) {
    foreach (['type', 'cod', 'dropOffAddress', 'receiver'] as $required) { if (!array_key_exists($required, $body ?? [])) { $err(400, "$required is required"); } }
    if (strlen($body['dropOffAddress']['firstLine'] ?? '') <= 5) { $err(400, 'firstLine must be more than 5 characters'); }
    if (empty($body['dropOffAddress']['districtId']) && empty($body['dropOffAddress']['districtName'])) { $err(400, 'city, zoneId, or districtId is required', 3009); }
    if ($state['fail_next_create'] > 0) { $state['fail_next_create']--; $err(500, 'Internal Server Error'); }
    foreach ($state['deliveries'] as $d) { if ($d['uniqueBusinessReference'] === $body['uniqueBusinessReference']) { $err(400, 'uniqueBusinessReference already exists', 1061); } }
    $tn = (string) $state['next']++;
    $d = ['_id' => 'dlv_' . $tn, 'trackingNumber' => $tn, 'state' => ['code' => 10, 'value' => 'Pickup requested'], 'creationSrc' => 'API'] + $body;
    $state['deliveries'][$tn] = $d;
    if ($state['lose_next_create']) { $state['lose_next_create'] = 0; $err(504, 'Gateway Timeout'); }
    $json(200, ['success' => true, 'message' => 'Done successfully', 'data' => array_intersect_key($d, ['_id' => 1, 'trackingNumber' => 1, 'businessReference' => 1, 'state' => 1, 'creationSrc' => 1])]);
}
if ('POST' === $method && '/api/v2/deliveries/search' === $path) {
    $found = array_values(array_filter($state['deliveries'], fn ($d) => ($d['businessReference'] ?? null) === ($body['businessReference'] ?? '')));
    $json(200, ['success' => true, 'data' => ['deliveries' => $found, 'count' => count($found)]]);
}
if (preg_match('#^/api/v2/deliveries/business/(\d+)/terminate$#', $path, $m) && 'DELETE' === $method) {
    if (!isset($state['deliveries'][$m[1]])) { $err(404, 'Not found'); }
    if (($state['deliveries'][$m[1]]['state']['code'] ?? 10) > 20) { $err(400, 'Delivery can not be terminated in its current state'); }
    $state['deliveries'][$m[1]]['state'] = ['code' => 48, 'value' => 'Terminated'];
    $json(200, ['success' => true, 'message' => 'Delivery has been terminated successfully.', 'data' => ['_id' => $state['deliveries'][$m[1]]['_id']]]);
}
if (preg_match('#^/api/v2/deliveries/business/(\d+)$#', $path, $m) && 'GET' === $method) {
    isset($state['deliveries'][$m[1]]) ? $json(200, ['success' => true, 'data' => $state['deliveries'][$m[1]]]) : $err(404, 'Not found');
}
if ('POST' === $method && '/api/v2/deliveries/mass-awb' === $path) {
    $json(200, ['success' => true, 'data' => base64_encode("%PDF-1.4\n% AWB " . $body['trackingNumbers'] . "\n%%EOF")]);
}
if ('GET' === $method && '/api/v2/pricing/shipment/calculator' === $path) {
    $json(200, ['success' => true, 'data' => ['priceBeforeVat' => 55, 'priceAfterVat' => 62.7]]);
}
$err(404, "No route $method $path");
