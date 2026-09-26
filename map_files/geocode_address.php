<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/max_notify.php';
header('Content-Type: application/json; charset=utf-8');

function geocodeOut(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function readGeocodeInput(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function readYandexApiKey(): string
{
    $fromGlobal = isset($GLOBALS['apiKeyYandex']) ? trim((string)$GLOBALS['apiKeyYandex']) : '';
    if ($fromGlobal !== '') {
        return $fromGlobal;
    }
    $fromVar = isset($GLOBALS['api_key_yandex']) ? trim((string)$GLOBALS['api_key_yandex']) : '';
    if ($fromVar !== '') {
        return $fromVar;
    }
    $env = trim((string)(getenv('YANDEX_GEOCODER_API_KEY') ?: getenv('YANDEX_API_KEY') ?: ''));
    return $env;
}

function fetchGeocodeJson(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response) || $response === '' || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        return $response;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 12,
            'method' => 'GET',
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if (!is_string($response) || trim($response) === '') {
        return null;
    }
    return $response;
}

try {
    $input = readGeocodeInput();
    $address = trim((string)($input['address'] ?? ''));
    if ($address === '') {
        geocodeOut(['success' => false, 'error' => 'Укажите полный адрес для определения координат.']);
    }

    $apiKey = readYandexApiKey();
    if ($apiKey === '') {
        geocodeOut(['success' => false, 'error' => 'Не настроен API-ключ Яндекс.Карт.']);
    }

    $url = 'https://geocode-maps.yandex.ru/1.x/?format=json&apikey='
        . rawurlencode($apiKey)
        . '&geocode='
        . rawurlencode($address);

    $jsonRaw = fetchGeocodeJson($url);
    if ($jsonRaw === null) {
        geocodeOut(['success' => false, 'error' => 'Ошибка запроса к сервису геокодирования Яндекс.']);
    }

    $decoded = json_decode($jsonRaw, true);
    if (!is_array($decoded)) {
        geocodeOut(['success' => false, 'error' => 'Сервис геокодирования вернул некорректный ответ.']);
    }

    $geoObject = $decoded['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'] ?? null;
    if (!is_array($geoObject)) {
        geocodeOut(['success' => false, 'error' => 'Адрес не найден. Уточните адрес и попробуйте снова.']);
    }

    $pos = trim((string)($geoObject['Point']['pos'] ?? ''));
    if ($pos === '') {
        geocodeOut(['success' => false, 'error' => 'Для указанного адреса не удалось получить координаты.']);
    }

    $parts = preg_split('/\s+/', $pos);
    if (!is_array($parts) || count($parts) < 2) {
        geocodeOut(['success' => false, 'error' => 'Некорректный формат координат от Яндекс.Карт.']);
    }

    $longitude = (float)$parts[0];
    $latitude = (float)$parts[1];
    if (!is_finite($latitude) || !is_finite($longitude)) {
        geocodeOut(['success' => false, 'error' => 'Не удалось распознать координаты от Яндекс.Карт.']);
    }

    $normalizedAddress = trim((string)($geoObject['metaDataProperty']['GeocoderMetaData']['text'] ?? ''));
    if ($normalizedAddress === '') {
        $normalizedAddress = $address;
    }

    if (function_exists('notifyEvent')) {
        notifyEvent('warehouse_geocode', [
            'address' => $normalizedAddress,
            'latitude' => (string)$latitude,
            'longitude' => (string)$longitude,
        ], "Координаты склада определены: {$normalizedAddress}");
    }

    geocodeOut([
        'success' => true,
        'address' => $normalizedAddress,
        'latitude' => $latitude,
        'longitude' => $longitude,
    ]);
} catch (Throwable $e) {
    if (function_exists('mapError')) {
        mapError('geocode_address failed', ['error' => $e->getMessage()]);
    }
    geocodeOut(['success' => false, 'error' => 'Не удалось определить координаты.']);
}
