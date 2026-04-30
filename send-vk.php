<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$localConfigPath = __DIR__ . '/config.local.php';
$localConfig = file_exists($localConfigPath) ? require $localConfigPath : [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$vkToken = (string)($localConfig['VK_BOT_TOKEN'] ?? getenv('VK_BOT_TOKEN') ?: '');
$vkPeerId = (string)($localConfig['VK_PEER_ID'] ?? getenv('VK_PEER_ID') ?: '');
$vkGroupId = (string)($localConfig['VK_GROUP_ID'] ?? getenv('VK_GROUP_ID') ?: '238127506');

if ($vkToken === '' || $vkPeerId === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'VK credentials are not configured']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$name = trim((string)($payload['name'] ?? ''));
$phone = trim((string)($payload['phone'] ?? ''));
$message = trim((string)($payload['message'] ?? ''));
$createdAt = trim((string)($payload['createdAt'] ?? ''));
$leadId = trim((string)($payload['id'] ?? ''));

if ($name === '' || $phone === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Name and phone are required']);
    exit;
}

$leadDate = $createdAt !== ''
    ? date('d.m.Y H:i', strtotime($createdAt))
    : date('d.m.Y H:i');

$lead = [
    'id' => $leadId !== '' ? $leadId : uniqid('lead-', true),
    'name' => $name,
    'phone' => $phone,
    'message' => $message,
    'status' => 'new',
    'note' => '',
    'createdAt' => $createdAt !== '' ? $createdAt : date('c'),
];

$dataDir = __DIR__ . '/data';
$leadsPath = $dataDir . '/leads.json';

if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Cannot create data directory']);
    exit;
}

$leads = [];

if (file_exists($leadsPath)) {
    $storedLeads = json_decode((string)file_get_contents($leadsPath), true);
    $leads = is_array($storedLeads) ? $storedLeads : [];
}

array_unshift($leads, $lead);

if (file_put_contents($leadsPath, json_encode($leads, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Cannot save lead']);
    exit;
}

$vkMessage = implode("\n", [
    'Новая заявка с сайта',
    'Интегративный психотерапевт Алёна Писарева',
    'Сообщество: https://vk.com/club' . $vkGroupId,
    '',
    'Имя и фамилия клиента: ' . $name,
    'Номер телефона: ' . $phone,
    'Краткое описание проблемы: ' . ($message !== '' ? $message : 'не указано'),
    'Дата: ' . $leadDate,
]);

$recipientParam = ctype_digit($vkPeerId) ? 'peer_id' : 'domain';

$request = [
    'access_token' => $vkToken,
    'v' => '5.199',
    $recipientParam => $vkPeerId,
    'random_id' => random_int(1, PHP_INT_MAX),
    'message' => $vkMessage,
];

$ch = curl_init('https://api.vk.com/method/messages.send');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => http_build_query($request),
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $curlError ?: 'VK request failed']);
    exit;
}

$decodedResponse = json_decode($response, true);

if (isset($decodedResponse['error'])) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $decodedResponse['error']['error_msg'] ?? 'VK API error',
    ]);
    exit;
}

echo json_encode(['ok' => true]);
