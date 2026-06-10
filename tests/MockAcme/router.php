<?php

$stateFile = getenv('MOCK_ACME_STATE');
$baseUrl = rtrim(getenv('MOCK_ACME_BASE_URL') ?: 'http://127.0.0.1:0', '/');

if (!$stateFile || !file_exists($stateFile)) {
    http_response_code(500);
    echo 'Mock ACME state file not configured';
    exit;
}

function readState(string $stateFile): array
{
    return json_decode(file_get_contents($stateFile), true) ?: [];
}

function writeState(string $stateFile, array $state): void
{
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
}

function sendJson(int $status, array $body, array $extraHeaders = []): void
{
    header('Content-Type: application/json');
    header('Replay-Nonce: ' . newNonce());
    foreach ($extraHeaders as $header) {
        header($header);
    }
    http_response_code($status);
    echo json_encode($body);
}

function sendPem(int $status, string $body, array $extraHeaders = []): void
{
    header('Content-Type: application/pem-certificate-chain');
    header('Replay-Nonce: ' . newNonce());
    foreach ($extraHeaders as $header) {
        header($header);
    }
    http_response_code($status);
    echo $body;
}

function newNonce(): string
{
    return base64_encode(random_bytes(16));
}

function domainSlug(string $domain): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $domain));
}

function orderBody(string $baseUrl, array $order): array
{
    $authorizations = [];
    foreach ($order['domains'] as $domain) {
        $authorizations[] = $baseUrl . '/acme/authz/' . $order['id'] . '/' . domainSlug($domain);
    }

    $body = [
        'status' => $order['status'],
        'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        'identifiers' => array_map(fn ($domain) => ['type' => 'dns', 'value' => $domain], $order['domains']),
        'authorizations' => $authorizations,
        'finalize' => $baseUrl . '/acme/finalize/' . $order['id'],
    ];

    if (!empty($order['certificateUrl'])) {
        $body['certificate'] = $order['certificateUrl'];
    }

    return $body;
}

function authorizationBody(string $baseUrl, array $auth): array
{
    $token = $auth['token'];

    return [
        'identifier' => ['type' => 'dns', 'value' => $auth['domain']],
        'status' => $auth['status'],
        'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        'challenges' => [
            [
                'type' => 'http-01',
                'status' => $auth['challengeStatus'],
                'url' => $baseUrl . '/acme/challenge/http/' . $token,
                'token' => $token,
            ],
            [
                'type' => 'dns-01',
                'status' => $auth['challengeStatus'],
                'url' => $baseUrl . '/acme/challenge/dns/' . $token,
                'token' => $token,
            ],
        ],
    ];
}

function allAuthorizationsValid(array $state, string $orderId): bool
{
    foreach ($state['authorizations'] as $auth) {
        if ($auth['orderId'] === $orderId && $auth['status'] !== 'valid') {
            return false;
        }
    }

    return true;
}

function updateOrderStatusFromAuthorizations(array &$state, string $orderId): void
{
    if (!isset($state['orders'][$orderId])) {
        return;
    }

    $order = &$state['orders'][$orderId];
    if (in_array($order['status'], ['valid', 'processing'], true)) {
        return;
    }

    if (allAuthorizationsValid($state, $orderId)) {
        $order['status'] = 'ready';
    }
}

function createOrder(array &$state, string $baseUrl, array $identifiers): array
{
    $orderId = 'order-' . (count($state['orders']) + 1);
    $domains = array_map(fn ($ident) => $ident['value'], $identifiers);

    $order = [
        'id' => $orderId,
        'url' => $baseUrl . '/acme/order/' . $orderId,
        'domains' => $domains,
        'status' => 'pending',
        'certificateUrl' => $baseUrl . '/acme/cert/' . $orderId,
    ];

    $state['orders'][$orderId] = $order;

    foreach ($domains as $domain) {
        $authKey = $orderId . '-' . domainSlug($domain);
        $state['authorizations'][$authKey] = [
            'orderId' => $orderId,
            'domain' => $domain,
            'status' => 'pending',
            'challengeStatus' => 'pending',
            'token' => 'token-' . domainSlug($domain),
        ];
    }

    return $order;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state = readState($stateFile);

if ($method === 'GET' && $uri === '/directory') {
    sendJson(200, [
        'newNonce' => $baseUrl . '/acme/new-nonce',
        'newAccount' => $baseUrl . '/acme/new-account',
        'newOrder' => $baseUrl . '/acme/new-order',
        'revokeCert' => $baseUrl . '/acme/revoke-cert',
        'keyChange' => $baseUrl . '/acme/key-change',
    ]);
    exit;
}

if ($method === 'HEAD' && $uri === '/acme/new-nonce') {
    header('Replay-Nonce: ' . newNonce());
    http_response_code(200);
    exit;
}

if ($method === 'POST' && $uri === '/acme/new-account') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $inner = json_decode(base64_decode(strtr($payload['payload'] ?? '', '-_', '+/')), true);

    if (!empty($inner['onlyReturnExisting'])) {
        if (empty($state['accounts'])) {
            sendJson(200, ['status' => 'valid']);
            exit;
        }

        $account = end($state['accounts']);
        sendJson(200, ['status' => 'valid'], ['Location: ' . $account['url']]);
        exit;
    }

    $accountId = 'account-' . (count($state['accounts']) + 1);
    $accountUrl = $baseUrl . '/acme/account/' . $accountId;
    $state['accounts'][$accountId] = [
        'url' => $accountUrl,
        'contact' => $inner['contact'] ?? ['mailto:test@example.com'],
    ];
    writeState($stateFile, $state);

    sendJson(201, ['status' => 'valid'], ['Location: ' . $accountUrl]);
    exit;
}

if ($method === 'POST' && preg_match('#^/acme/account/([^/]+)$#', $uri, $matches)) {
    $accountId = $matches[1];
    if (!isset($state['accounts'][$accountId])) {
        sendJson(404, ['type' => 'about:blank', 'detail' => 'Account not found']);
        exit;
    }

    $account = $state['accounts'][$accountId];
    sendJson(200, [
        'status' => 'valid',
        'contact' => $account['contact'],
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'key' => ['kty' => 'RSA', 'n' => 'test', 'e' => 'AQAB'],
    ]);
    exit;
}

if ($method === 'POST' && $uri === '/acme/new-order') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $inner = json_decode(base64_decode(strtr($payload['payload'] ?? '', '-_', '+/')), true);
    $order = createOrder($state, $baseUrl, $inner['identifiers'] ?? []);
    writeState($stateFile, $state);

    sendJson(201, orderBody($baseUrl, $order), ['Location: ' . $order['url']]);
    exit;
}

if ($method === 'POST' && preg_match('#^/acme/order/([^/]+)$#', $uri, $matches)) {
    $orderId = $matches[1];
    if (!isset($state['orders'][$orderId])) {
        sendJson(404, ['type' => 'about:blank', 'detail' => 'Order not found']);
        exit;
    }

    sendJson(200, orderBody($baseUrl, $state['orders'][$orderId]));
    exit;
}

if ($method === 'POST' && preg_match('#^/acme/authz/([^/]+)/([^/]+)$#', $uri, $matches)) {
    $authKey = $matches[1] . '-' . $matches[2];
    if (!isset($state['authorizations'][$authKey])) {
        sendJson(404, ['type' => 'about:blank', 'detail' => 'Authorization not found']);
        exit;
    }

    sendJson(200, authorizationBody($baseUrl, $state['authorizations'][$authKey]));
    exit;
}

if ($method === 'POST' && preg_match('#^/acme/challenge/(http|dns)/([^/]+)$#', $uri, $matches)) {
    $token = $matches[2];

    foreach ($state['authorizations'] as $key => &$auth) {
        if ($auth['token'] === $token) {
            $auth['challengeStatus'] = 'valid';
            $auth['status'] = 'valid';
            updateOrderStatusFromAuthorizations($state, $auth['orderId']);
            writeState($stateFile, $state);
            sendJson(200, ['type' => $matches[1] . '-01', 'status' => 'valid']);
            exit;
        }
    }

    sendJson(404, ['type' => 'about:blank', 'detail' => 'Challenge not found']);
    exit;
}

if ($method === 'POST' && preg_match('#^/acme/finalize/([^/]+)$#', $uri, $matches)) {
    $orderId = $matches[1];
    if (!isset($state['orders'][$orderId])) {
        sendJson(404, ['type' => 'about:blank', 'detail' => 'Order not found']);
        exit;
    }

    $state['orders'][$orderId]['status'] = 'valid';
    writeState($stateFile, $state);

    sendJson(200, orderBody($baseUrl, $state['orders'][$orderId]));
    exit;
}

if ($method === 'POST' && preg_match('#^/acme/cert/([^/]+)$#', $uri, $matches)) {
    $leaf = file_exists(dirname(__DIR__) . '/fixtures/certificates/leaf.pem')
        ? file_get_contents(dirname(__DIR__) . '/fixtures/certificates/leaf.pem')
        : "-----BEGIN CERTIFICATE-----\nMIIBkTCB+wIJAKexample\n-----END CERTIFICATE-----\n";

    $chain = file_exists(dirname(__DIR__) . '/fixtures/certificates/chain.pem')
        ? file_get_contents(dirname(__DIR__) . '/fixtures/certificates/chain.pem')
        : $leaf;

    $rootPath = dirname(__DIR__) . '/fixtures/certificates/root.pem';
    $root = file_exists($rootPath) ? file_get_contents($rootPath) : '';

    $preferred = isset($_GET['preferred']) && $_GET['preferred'] === '1';
    $headers = [];
    if (!$preferred) {
        $headers[] = 'Link: <' . $baseUrl . '/acme/cert/' . $matches[1] . '?preferred=1>;rel="alternate"';
    }

    $chainPem = trim($leaf) . "\n" . trim($chain) . "\n";
    if ($root !== '') {
        $chainPem .= trim($root) . "\n";
    }

    sendPem(200, $chainPem, $headers);
    exit;
}

if ($method === 'POST' && $uri === '/acme/revoke-cert') {
    sendJson(200, []);
    exit;
}

if ($method === 'POST' && $uri === '/acme/key-change') {
    sendJson(200, []);
    exit;
}

if ($method === 'GET' && $uri === '/error/500') {
    sendJson(500, ['type' => 'about:blank', 'detail' => 'Forced error']);
    exit;
}

http_response_code(404);
echo 'Not found: ' . $uri;
