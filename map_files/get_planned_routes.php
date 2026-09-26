<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function safeJson(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function parseZayavkiIds(string $raw): array
{
    $ids = [];
    foreach (explode(',', $raw) as $chunk) {
        $id = (int)trim($chunk);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function normalizeRouteType($routeTypeRaw, $unloadTypeRaw = 'OO'): string
{
    $routeType = strtolower(trim((string)$routeTypeRaw));
    $allowed = [
        'generator_to_utilizer',
        'generator_to_warehouse',
        'warehouse_to_warehouse',
        'warehouse_to_utilizer',
    ];
    if (in_array($routeType, $allowed, true)) {
        return $routeType;
    }
    return strtoupper(trim((string)$unloadTypeRaw)) === 'SKLAD'
        ? 'generator_to_warehouse'
        : 'generator_to_utilizer';
}

function getTotalKgByIds(PDO $pdo, array $ids): float
{
    if (empty($ids)) {
        return 0.0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(mass_netto, 0) * 1000), 0) AS total_kg FROM feo WHERE zayavka_id IN ($placeholders)");
    if (!$stmt) {
        return 0.0;
    }

    if (!$stmt->execute($ids)) {
        return 0.0;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return isset($row['total_kg']) ? (float)$row['total_kg'] : 0.0;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    $managerId = isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 0;

    $columnsStmt = $pdo->query('SHOW COLUMNS FROM flights');
    $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $columnsMap = [];
    foreach ((array)$columns as $column) {
        $columnsMap[(string)$column] = true;
    }

    $warehouseNameColumn = null;
    try {
        $wColsStmt = $pdo->query('SHOW COLUMNS FROM warehouses');
        $wCols = $wColsStmt ? $wColsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $wMap = [];
        foreach ((array)$wCols as $column) {
            $wMap[(string)$column] = true;
        }
        foreach (['name', 'title', 'warehouse_name', 'label'] as $candidate) {
            if (isset($wMap[$candidate])) {
                $warehouseNameColumn = $candidate;
                break;
            }
        }
    } catch (Throwable $e) {
        $warehouseNameColumn = null;
    }

    $managerColumn = null;
    foreach (['assigned_manager_id', 'manager_id'] as $candidate) {
        if (isset($columnsMap[$candidate])) {
            $managerColumn = $candidate;
            break;
        }
    }

    $managerSelect = $managerColumn ? "f.$managerColumn AS assigned_manager_id" : 'NULL AS assigned_manager_id';

    $warehouseNameSelectSource = $warehouseNameColumn ? ('ws.`' . str_replace('`', '``', $warehouseNameColumn) . '`') : 'NULL';
    $warehouseNameSelectDest = $warehouseNameColumn ? ('wd.`' . str_replace('`', '``', $warehouseNameColumn) . '`') : 'NULL';

    $sql = "
        SELECT f.id,
               f.status,
               f.comment AS name,
               f.cost,
               f.unload_type,
               f.route_type,
               f.source_warehouse_id,
               f.destination_warehouse_id,
               f.zayavki_ids,
               f.zayavki_count,
               f.driver_id,
               f.planned_start_date_from,
               f.planned_start_date_to,
               f.actual_start_date,
               f.actual_end_date,
               $managerSelect,
               CONCAT(COALESCE(d.vehicle_make_plate, ''), CASE WHEN d.full_name IS NOT NULL AND d.full_name <> '' THEN CONCAT(' (', d.full_name, ')') ELSE '' END) AS driver_label,
               {$warehouseNameSelectSource} AS source_warehouse_name,
               {$warehouseNameSelectDest} AS destination_warehouse_name
        FROM flights f
        LEFT JOIN drivers d ON d.id = f.driver_id
        LEFT JOIN warehouses ws ON ws.id = f.source_warehouse_id
        LEFT JOIN warehouses wd ON wd.id = f.destination_warehouse_id
        WHERE f.status IN ('planned_route', 'found', 'started')
    ";

    $params = [];
    if ($managerId > 0 && $managerColumn) {
        $sql .= " AND f.$managerColumn = :manager_id";
        $params[':manager_id'] = $managerId;
    }

    $sql .= ' ORDER BY f.block_date DESC';

    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute($params)) {
        throw new RuntimeException('Failed to execute routes query');
    }

    $plannedRoutes = [];
    $foundRoutes = [];
    $startedRoutes = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }

        $flightId = isset($row['id']) ? (int)$row['id'] : 0;
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            $name = 'Рейс #' . $flightId;
        }

        $zayIds = parseZayavkiIds((string)($row['zayavki_ids'] ?? ''));
        $unloadType = strtoupper(trim((string)($row['unload_type'] ?? 'OO')));
        if ($unloadType !== 'SKLAD') {
            $unloadType = 'OO';
        }
        $routeType = normalizeRouteType($row['route_type'] ?? '', $unloadType);

        $normalized = [
            'id' => $flightId,
            'status' => (string)($row['status'] ?? ''),
            'name' => $name,
            'route_title' => $name,
            'cost' => $row['cost'] ?? null,
            'unload_type' => $unloadType,
            'route_type' => $routeType,
            'source_warehouse_id' => isset($row['source_warehouse_id']) ? (int)$row['source_warehouse_id'] : null,
            'destination_warehouse_id' => isset($row['destination_warehouse_id']) ? (int)$row['destination_warehouse_id'] : null,
            'source_warehouse_name' => trim((string)($row['source_warehouse_name'] ?? '')),
            'destination_warehouse_name' => trim((string)($row['destination_warehouse_name'] ?? '')),
            'zayavki_ids' => (string)($row['zayavki_ids'] ?? ''),
            'zayavki_count' => isset($row['zayavki_count']) ? (int)$row['zayavki_count'] : count($zayIds),
            'driver_id' => isset($row['driver_id']) ? (int)$row['driver_id'] : null,
            'driver_label' => trim((string)($row['driver_label'] ?? '')),
            'planned_start_date_from' => $row['planned_start_date_from'] ?? null,
            'planned_start_date_to' => $row['planned_start_date_to'] ?? null,
            'actual_start_date' => $row['actual_start_date'] ?? null,
            'actual_end_date' => $row['actual_end_date'] ?? null,
            'assigned_manager_id' => isset($row['assigned_manager_id']) ? (int)$row['assigned_manager_id'] : null,
            'total_kg' => getTotalKgByIds($pdo, $zayIds),
        ];

        if ($normalized['status'] === 'found') {
            $foundRoutes[] = $normalized;
        } elseif ($normalized['status'] === 'started') {
            $startedRoutes[] = $normalized;
        } else {
            $plannedRoutes[] = $normalized;
        }
    }

    safeJson([
        'success' => true,
        'routes' => $plannedRoutes,
        'found_routes' => $foundRoutes,
        'started_routes' => $startedRoutes,
        'manager_id' => $managerId > 0 ? $managerId : null,
    ]);
} catch (Throwable $e) {
    mapError('get_planned_routes failed', ['error' => $e->getMessage()]);
    safeJson(['success' => false, 'message' => 'Ошибка загрузки маршрутов']);
}
