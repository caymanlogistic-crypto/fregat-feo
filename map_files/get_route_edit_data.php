<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$routeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($routeId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Некорректный id рейса'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $sql = "
        SELECT
            f.id,
            f.status,
            f.comment,
            f.comment AS title,
            f.driver_id,
            f.planned_start_date_from,
            f.planned_start_date_to,
            f.actual_start_date,
            f.actual_end_date,
            f.cost,
            f.zayavki_ids,
            f.route_type,
            f.unload_type,
            f.source_warehouse_id,
            f.destination_warehouse_id
        FROM flights f
        WHERE f.id = :id
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $routeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Рейс не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => true, 'route' => $row], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Не удалось загрузить данные рейса'], JSON_UNESCAPED_UNICODE);
}

