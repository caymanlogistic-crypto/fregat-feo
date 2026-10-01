<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/warehouse_movements.php';

$apply = in_array('--apply', $argv, true);
$warehouseId = 9;
$targets = [
    309 => [
        287156, 293334, 295036, 295146, 295590, 296555, 300690, 302366,
        302367, 302368, 303698, 303925, 305033, 306980, 308062, 308571,
        308610, 310313, 310863, 311706, 313832, 313876, 314098, 314683,
    ],
    310 => [
        276175, 283264, 283478, 291759, 292024, 292025, 297106,
        297110, 299941, 300727, 303927, 305799, 309305,
    ],
];

function repairPrint(string $label, $value): void
{
    if (is_array($value)) {
        echo $label . ': ' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }
    echo $label . ': ' . (string)$value . PHP_EOL;
}

function repairIds(string $raw): array
{
    $ids = [];
    foreach (explode(',', $raw) as $value) {
        $id = (int)trim($value);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    sort($ids, SORT_NUMERIC);
    return array_values(array_unique($ids));
}

function repairStockSummary(PDO $pdo, int $warehouseId): array
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS request_count,
            COALESCE(SUM(stock_netto), 0) AS mass_netto,
            COALESCE(SUM(stock_brutto), 0) AS mass_brutto
         FROM (
            SELECT zayavka_id,
                SUM(CASE WHEN movement_type IN ('receipt','transfer_in') THEN COALESCE(mass_netto,0)
                         WHEN movement_type IN ('issue','transfer_out') THEN -COALESCE(mass_netto,0)
                         ELSE 0 END) AS stock_netto,
                SUM(CASE WHEN movement_type IN ('receipt','transfer_in') THEN COALESCE(mass_brutto,0)
                         WHEN movement_type IN ('issue','transfer_out') THEN -COALESCE(mass_brutto,0)
                         ELSE 0 END) AS stock_brutto
            FROM warehouse_movements
            WHERE status = 'active' AND warehouse_id = :warehouse_id
            GROUP BY zayavka_id
            HAVING stock_netto > 0.0001 OR stock_brutto > 0.0001
         ) stock"
    );
    $stmt->execute([':warehouse_id' => $warehouseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'request_count' => (int)($row['request_count'] ?? 0),
        'mass_netto' => round((float)($row['mass_netto'] ?? 0), 3),
        'mass_brutto' => round((float)($row['mass_brutto'] ?? 0), 3),
    ];
}

function repairIssueRows(PDO $pdo, array $flightIds): array
{
    $placeholders = implode(',', array_fill(0, count($flightIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, flight_id, zayavka_id, movement_type, warehouse_id,
                ROUND(mass_netto, 3) AS mass_netto,
                ROUND(mass_brutto, 3) AS mass_brutto,
                movement_date, status
         FROM warehouse_movements
         WHERE flight_id IN ({$placeholders})
         ORDER BY flight_id, zayavka_id, id"
    );
    $stmt->execute($flightIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function repairAssertClose(float $actual, float $expected, string $label): void
{
    if (abs($actual - $expected) > 0.0001) {
        throw new RuntimeException("{$label}: получено {$actual}, ожидалось {$expected}");
    }
}

$flightIds = array_keys($targets);
$expectedMovementCount = array_sum(array_map('count', $targets));

try {
    foreach ($targets as $flightId => $expectedIds) {
        $stmt = $pdo->prepare(
            'SELECT id, status, route_type, source_warehouse_id, destination_warehouse_id, zayavki_ids, actual_start_date
             FROM flights WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $flightId]);
        $flight = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($flight)) {
            throw new RuntimeException("Рейс #{$flightId} не найден");
        }
        if ((string)$flight['status'] !== 'started') {
            throw new RuntimeException("Рейс #{$flightId}: статус не started");
        }
        if ((string)$flight['route_type'] !== ROUTE_TYPE_WAREHOUSE_TO_UTILIZER) {
            throw new RuntimeException("Рейс #{$flightId}: маршрут не Склад → Утилизатор");
        }
        if ((int)$flight['source_warehouse_id'] !== $warehouseId) {
            throw new RuntimeException("Рейс #{$flightId}: склад отправления не #{$warehouseId}");
        }
        $actualIds = repairIds((string)$flight['zayavki_ids']);
        sort($expectedIds, SORT_NUMERIC);
        if ($actualIds !== $expectedIds) {
            throw new RuntimeException("Рейс #{$flightId}: состав заявок изменился, запись остановлена");
        }
    }

    $beforeRows = repairIssueRows($pdo, $flightIds);
    $beforeSummary = repairStockSummary($pdo, $warehouseId);
    if (!in_array(count($beforeRows), [0, $expectedMovementCount], true)) {
        throw new RuntimeException('Обнаружено частичное состояние движений; автоматическая запись запрещена');
    }

    repairPrint('MODE', $apply ? 'APPLY' : 'DRY-RUN');
    repairPrint('TARGET_FLIGHTS', $flightIds);
    repairPrint('EXPECTED_ISSUE_ROWS', $expectedMovementCount);
    repairPrint('BEFORE_ISSUE_ROWS', count($beforeRows));
    repairPrint('BEFORE_STOCK', $beforeSummary);

    if (!$apply) {
        repairPrint('PLAN', 'Создать issue для рейсов #309 и #310 и подтвердить остаток склада #9');
        exit(0);
    }

    $pdo->beginTransaction();
    foreach ($flightIds as $flightId) {
        $result = createWarehouseMovementsForStartedFlight($pdo, (int)$flightId);
        repairPrint("FLIGHT_{$flightId}_RESULT", $result);
        if (empty($result['success'])) {
            throw new RuntimeException("Не удалось создать списания по рейсу #{$flightId}");
        }
    }

    $afterRows = repairIssueRows($pdo, $flightIds);
    if (count($afterRows) !== $expectedMovementCount) {
        throw new RuntimeException('Количество созданных движений не совпало с ожидаемым');
    }

    $duplicateStmt = $pdo->prepare(
        "SELECT flight_id, movement_type, warehouse_id, zayavka_id, COUNT(*) AS cnt
         FROM warehouse_movements
         WHERE flight_id IN (?, ?)
         GROUP BY flight_id, movement_type, warehouse_id, zayavka_id
         HAVING COUNT(*) > 1"
    );
    $duplicateStmt->execute($flightIds);
    $duplicates = $duplicateStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($duplicates) {
        throw new RuntimeException('После записи обнаружены дубли движений');
    }

    $afterSummary = repairStockSummary($pdo, $warehouseId);
    if ($afterSummary['request_count'] !== 23) {
        throw new RuntimeException('После записи количество заявок на складе не равно 23');
    }
    repairAssertClose((float)$afterSummary['mass_netto'], 19.685, 'Остаток нетто');
    repairAssertClose((float)$afterSummary['mass_brutto'], 19.970, 'Остаток брутто');

    $pdo->commit();

    repairPrint('AFTER_ISSUE_ROWS', $afterRows);
    repairPrint('DUPLICATES', $duplicates);
    repairPrint('AFTER_STOCK', $afterSummary);
    repairPrint('RESULT', 'PASS');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
