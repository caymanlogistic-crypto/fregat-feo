<?php

$baseUrl = 'https://platform-api.max.ru';
$token = trim((string) (getenv('MAX_API_TOKEN') ?: ''));
$chatId = '-70991778594913';

$secretKey = trim((string) (getenv('MAX_NOTIFY_KEY') ?: ''));

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    $args = array_slice($argv, 1);
    $message = trim(implode(' ', $args));
} else {
    $key = isset($_GET['key']) ? (string)$_GET['key'] : '';

    if ($key !== $secretKey) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'success' => false,
            'message' => 'Forbidden'
        ));
        exit;
    }

    $message = isset($_GET['text']) ? trim((string)$_GET['text']) : '';
}

if ($message === '') {
    $message = 'Codex: требуется внимание пользователя.';
}

$url = rtrim($baseUrl, '/') . '/messages?chat_id=' . rawurlencode($chatId);

$payload = array(
    'text' => $message
);

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: ' . $token,
    'Content-Type: application/json'
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

$success = ($response !== false && $httpCode >= 200 && $httpCode < 300);

if ($isCli) {
    if (!$success) {
        fwrite(STDERR, "MAX notify failed. HTTP={$httpCode}. Error={$error}. Response={$response}\n");
        exit(1);
    }

    echo "MAX notify sent. HTTP={$httpCode}\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');

echo json_encode(array(
    'success' => $success,
    'http_code' => $httpCode,
    'curl_error' => $error,
    'response' => $response
));