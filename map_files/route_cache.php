<?php
declare(strict_types=1);

// Shared cache only: no database access and no calls to Yandex.
require_once __DIR__ . '/Services/RouteCalculationCache.php';
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new InvalidArgumentException('Метод не поддерживается');
    }
    $source = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    $sourceHost = strtolower((string)parse_url($source, PHP_URL_HOST));
    $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    if ($sourceHost === '' || $sourceHost !== $host) {
        http_response_code(403);
        throw new InvalidArgumentException('Откройте расчёт на странице карты');
    }
    $raw = file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024 + 1);
    if ($raw === false || strlen($raw) > 12 * 1024 * 1024) {
        http_response_code(413);
        throw new InvalidArgumentException('Результат расчёта слишком большой');
    }
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['points'] ?? null)) throw new InvalidArgumentException('Не переданы адреса расчёта');
    $action = (string)($payload['action'] ?? '');
    if (!in_array($action, ['claim', 'save', 'release'], true)) throw new InvalidArgumentException('Неизвестное действие');
    $cache = new RouteCalculationCache(dirname(__DIR__) . '/storage/route-calculations');
    echo json_encode(['success' => true] + $cache->handle($action, $payload['points'], $payload), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error instanceof JsonException ? 'Некорректные данные расчёта' : $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Общее хранилище расчётов временно недоступно'], JSON_UNESCAPED_UNICODE);
}
