<?php
// Mock x402 facilitator. Records every request to $MOCK_LOG (JSON lines), answers per $MODE:
//   ok (default) | reject (verify fails) | notx (settle succeeds without a transaction hash)
$mode = getenv('MODE') ?: 'ok';
$log = getenv('MOCK_LOG') ?: sys_get_temp_dir() . '/ppc-mock.log';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true);
file_put_contents($log, json_encode(['path' => $path, 'body' => $body]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
if ($path === '/supported') {
    echo json_encode(['kinds' => [['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:8453']]]);
    exit;
}
$from = $body['paymentPayload']['payload']['authorization']['from'] ?? '0x0';
if ($path === '/verify') {
    echo json_encode($mode === 'reject' ? ['isValid' => false, 'invalidReason' => 'insufficient_funds'] : ['isValid' => true, 'payer' => $from]);
    exit;
}
if ($path === '/settle') {
    echo json_encode($mode === 'notx'
        ? ['success' => true, 'transaction' => '']
        : ['success' => true, 'transaction' => '0x' . str_repeat('ab', 32), 'network' => $body['paymentRequirements']['network'], 'payer' => $from]);
    exit;
}
http_response_code(404);
echo '{}';
