<?php
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/max_notify.php';
require_once __DIR__ . '/Support/planned_route_retention.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не разрешён'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }
    $result = plannedRouteRetentionProcess($pdo, true);
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    mapError('process_planned_route_retention failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Не удалось обработать сроки планируемых рейсов'], JSON_UNESCAPED_UNICODE);
}
