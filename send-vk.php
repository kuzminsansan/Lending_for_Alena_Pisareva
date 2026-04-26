<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$vkToken = getenv('VK_BOT_TOKEN') ?: 'PASTE_VK_GROUP_TOKEN_HERE';
$vkPeerId = getenv('VK_PEER_ID') ?: 'PASTE_ALENA_PEER_ID_HERE';

if (
    $vkToken === 'PASTE_VK_GROUP_TOKEN_HERE'
    || $vkPeerId === 'PASTE_ALENA_PEER_ID_HERE'
) {
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

if ($name === '' || $phone === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Name and phone are required']);
    exit;
}

$leadDate = $createdAt !== ''
    ? date('d.m.Y H:i', strtotime($createdAt))
    : date('d.m.Y H:i');

$vkMessage = implode("\n", [
    'Новая заявка с сайта Алёны Писаревой',
    '',
    'Имя: ' . $name,
    'Телефон: ' . $phone,
    'Комментарий: ' . ($message !== '' ? $message : 'не указан'),
    'Дата: ' . $leadDate,
]);

$request = [
    'access_token' => $vkToken,
    'v' => '5.199',
    'peer_id' => $vkPeerId,
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
