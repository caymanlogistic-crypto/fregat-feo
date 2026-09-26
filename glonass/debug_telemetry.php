<?php
// debug_telemetry.php
$config = require __DIR__ . '/working_config.php';
$testId = 23047; // ID первого трекера из вашего списка
$token = $config['api_token'];

$url = $config['api_base_url'] . '/api/v3/objects/' . $testId . '/lastdata';

echo "🔍 Тест запроса телеметрии для ID: $testId\n";
echo "URL: $url\n\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Auth: ' . $token
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
curl_close($ch);

echo "📥 Ответ сервера:\n";
echo "HTTP Code: $httpCode\n";
echo "--- HEADERS ---\n$headers\n";
echo "--- BODY ---\n$body\n\n";

$result = json_decode($body, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ Ошибка парсинга JSON: " . json_last_error_msg() . "\n";
} else {
    echo "📦 Распарсенный JSON:\n";
    print_r($result);
}