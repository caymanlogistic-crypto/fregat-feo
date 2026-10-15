<?php
/**
 * GET get_warehouse_stock_map.php
 * Read-only эндпоинт для получения текущих складских остатков в JSON.
 * Используется frontend'ом карты для отображения остатков в попапе склада.
 *
 * Response: { success: true, warehouses: { "6": { warehouse_id, name, address,
 *   request_count, mass_netto_sum, mass_brutto_sum, volume_sum,
 *   requests: [...], inbound_routes: [...], outbound_routes: [...] } } }
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/warehouse_movements.php';

header('Content-Type: application/json; charset=utf-8');

function warehouseStockParseIds(string $value): array
{
    $ids = [];
    foreach (preg_split('/[\s,;]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
        if (preg_match('/^\d+$/', $id) && (int)$id > 0) {
            $ids[(int)$id] = true;
        }
    }
    return $ids;
}

function warehouseStockWarehouseRequestIds(array $flight): array
{
    $requestIds = warehouseStockParseIds((string)($flight['current_zayavki_ids'] ?? ''));
    if ((string)($flight['route_type'] ?? '') !== 'mixed_to_utilizer') {
        return $requestIds;
    }

    foreach (array_keys(warehouseStockParseIds((string)($flight['current_direct_zayavki_ids'] ?? ''))) as $requestId) {
        unset($requestIds[$requestId]);
    }
    return $requestIds;
}

function warehouseStockSequenceAt(array $movement): string
{
    $createdAt = trim((string)($movement['created_at'] ?? ''));
    return $createdAt !== '' ? $createdAt : (string)($movement['movement_date'] ?? '');
}

function warehouseStockLoadRequestMasses(PDO $pdo, array $ids): array
{
    if (empty($ids)) {
        return [];
    }

    $result = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 300) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT zayavka_id, mass_netto, mass_brutto, summarnyy_obem FROM feo WHERE zayavka_id IN ({$placeholders})");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['zayavka_id']] = [
                'mass_netto' => (float)($row['mass_netto'] ?? 0),
                'mass_brutto' => (float)($row['mass_brutto'] ?? 0),
                'volume' => (float)($row['summarnyy_obem'] ?? 0),
            ];
        }
    }
    return $result;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit;
}

try {
    // ── Адаптивная загрузка складов (колонки могут отличаться) ──
    $whMap = [];
    $stmtCols = $pdo->query('SHOW COLUMNS FROM warehouses');
    $columns = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];
    $colMap = [];
    foreach ((array)$columns as $c) { $colMap[(string)$c] = true; }

    $nameCol = 'id';
    foreach (['name', 'title', 'warehouse_name', 'label'] as $cand) {
        if (isset($colMap[$cand])) { $nameCol = $cand; break; }
    }
    $addrCol = null;
    foreach (['address', 'full_address', 'location', 'addr'] as $cand) {
        if (isset($colMap[$cand])) { $addrCol = $cand; break; }
    }
    $hasLat = isset($colMap['latitude']);
    $hasLon = isset($colMap['longitude']);

    $sql = 'SELECT id, ' . (function($n) { return '`'.str_replace('`','``',$n).'`'; })($nameCol) . ' AS name';
    $sql .= $hasLat ? ', latitude' : ', NULL AS latitude';
    $sql .= $hasLon ? ', longitude' : ', NULL AS longitude';
    if ($addrCol !== null) {
        $sql .= ', ' . (function($n) { return '`'.str_replace('`','``',$n).'`'; })($addrCol) . ' AS address';
    } else {
        $sql .= ', NULL AS address';
    }
    $sql .= ' FROM warehouses ORDER BY id ASC';

    $stmt = $pdo->query($sql);
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $wid = (int)$row['id'];
        $whMap[$wid] = [
            'warehouse_id' => $wid,
            'name'         => trim((string)($row['name'] ?? ('Склад #' . $wid))),
            'address'      => trim((string)($row['address'] ?? '')),
            'latitude'     => $row['latitude'] ?? null,
            'longitude'    => $row['longitude'] ?? null,
            'request_count'   => 0,
            'mass_netto_sum'  => 0.0,
            'mass_brutto_sum' => 0.0,
            'volume_sum'      => 0.0,
            'requests'        => [],
            'inbound_routes'  => [],
            'outbound_routes' => [],
        ];
    }

    // ── Проверяем наличие таблицы warehouse_movements ──
    if (!wmTableExists($pdo)) {
        echo json_encode(['success' => true, 'warehouses' => $whMap], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Остатки и маршруты пересчитываются по актуальному составу flights.zayavki_ids.
    // warehouse_movements остаётся журналом фактических операций, но удалённые из
    // рейса заявки больше не участвуют в текущем остатке.
    $movementSql = "SELECT
            wm.id,
            wm.warehouse_id,
            wm.flight_id,
            wm.movement_type,
            wm.zayavka_id,
            wm.mass_netto,
            wm.mass_brutto,
            wm.volume,
            wm.movement_date,
            wm.created_at,
            f.id AS current_flight_id,
            COALESCE(f.status, '') AS flight_status,
            COALESCE(f.route_type, '') AS route_type,
            COALESCE(f.zayavki_ids, '') AS current_zayavki_ids,
            COALESCE(f.direct_zayavki_ids, '') AS current_direct_zayavki_ids,
            f.source_warehouse_id,
            f.destination_warehouse_id,
            f.actual_start_date,
            f.actual_end_date
        FROM warehouse_movements wm
        LEFT JOIN flights f ON f.id = wm.flight_id
        WHERE wm.status = 'active'
          AND wm.movement_type IN ('receipt', 'transfer_in', 'issue', 'transfer_out')
        ORDER BY wm.movement_date ASC, wm.id ASC";
    $movementRows = $pdo->query($movementSql)->fetchAll(PDO::FETCH_ASSOC);

    $flightSql = "SELECT
            id AS flight_id,
            status AS flight_status,
            route_type,
            zayavki_ids AS current_zayavki_ids,
            COALESCE(direct_zayavki_ids, '') AS current_direct_zayavki_ids,
            source_warehouse_id,
            destination_warehouse_id,
            actual_start_date,
            actual_end_date
        FROM flights
        WHERE source_warehouse_id IS NOT NULL
           OR destination_warehouse_id IS NOT NULL";
    $flightMeta = [];
    foreach ($pdo->query($flightSql)->fetchAll(PDO::FETCH_ASSOC) as $flightRow) {
        $flightId = (int)$flightRow['flight_id'];
        $flightMeta[$flightId] = $flightRow;
        $flightMeta[$flightId]['current_requests'] = warehouseStockWarehouseRequestIds($flightRow);
    }

    $receiptDates = [];
    $recordedReceipts = [];
    $alreadyIssued = [];
    foreach ($movementRows as $row) {
        $warehouseId = (int)$row['warehouse_id'];
        $flightId = (int)$row['flight_id'];
        if (!isset($flightMeta[$flightId]) && !empty($row['current_flight_id'])) {
            $flightMeta[$flightId] = $row;
            $flightMeta[$flightId]['current_requests'] = warehouseStockWarehouseRequestIds($row);
        }
        $requestId = (int)$row['zayavka_id'];
        if (in_array((string)$row['movement_type'], ['receipt', 'transfer_in'], true)) {
            $receiptDates[$warehouseId][$flightId] = max(
                $receiptDates[$warehouseId][$flightId] ?? '',
                (string)$row['movement_date']
            );
            $recordedReceipts[$warehouseId][$flightId][$requestId] = true;
        } else {
            $alreadyIssued[$warehouseId][$flightId][$requestId] = true;
        }
    }

    $addedIds = [];
    foreach ($receiptDates as $warehouseId => $warehouseFlights) {
        foreach ($warehouseFlights as $flightId => $receiptDate) {
            foreach (array_keys($flightMeta[$flightId]['current_requests'] ?? []) as $requestId) {
                if (!isset($recordedReceipts[$warehouseId][$flightId][$requestId])) {
                    $addedIds[$requestId] = $requestId;
                }
            }
        }
    }
    $requestMasses = warehouseStockLoadRequestMasses($pdo, array_values($addedIds));

    foreach ($receiptDates as $warehouseId => $warehouseFlights) {
        foreach ($warehouseFlights as $flightId => $receiptDate) {
            foreach (array_keys($flightMeta[$flightId]['current_requests'] ?? []) as $requestId) {
                if (isset($recordedReceipts[$warehouseId][$flightId][$requestId])) {
                    continue;
                }
                $mass = $requestMasses[$requestId] ?? ['mass_netto' => 0.0, 'mass_brutto' => 0.0, 'volume' => 0.0];
                $movementRows[] = array_merge($flightMeta[$flightId], $mass, [
                    'id' => PHP_INT_MAX,
                    'warehouse_id' => $warehouseId,
                    'flight_id' => $flightId,
                    'movement_type' => 'receipt',
                    'zayavka_id' => $requestId,
                    'movement_date' => $receiptDate,
                    'current_flight_id' => $flightId,
                    'derived_receipt' => true,
                ]);
            }
        }
    }

    foreach ($flightMeta as $flightId => $meta) {
        $warehouseId = (int)($meta['source_warehouse_id'] ?? 0);
        if ($warehouseId <= 0
            || !isset($whMap[$warehouseId])
            || !in_array((string)($meta['flight_status'] ?? ''), ['started', 'in_progress', 'completed'], true)) {
            continue;
        }
        $movementDate = (string)($meta['actual_start_date'] ?? '')
            ?: (string)($meta['actual_end_date'] ?? '')
            ?: date('Y-m-d H:i:s');
        foreach (array_keys($meta['current_requests'] ?? []) as $requestId) {
            if (isset($alreadyIssued[$warehouseId][$flightId][$requestId])) {
                continue;
            }
            $movementRows[] = array_merge($meta, [
                'id' => PHP_INT_MAX,
                'warehouse_id' => $warehouseId,
                'flight_id' => $flightId,
                'movement_type' => 'issue',
                'zayavka_id' => $requestId,
                'mass_netto' => null,
                'mass_brutto' => null,
                'volume' => null,
                'movement_date' => $movementDate,
                'current_flight_id' => $flightId,
                'derived_issue' => true,
            ]);
        }
    }

    usort($movementRows, static function (array $left, array $right): int {
        $bySequence = strcmp(warehouseStockSequenceAt($left), warehouseStockSequenceAt($right));
        return $bySequence !== 0 ? $bySequence : ((int)$left['id'] <=> (int)$right['id']);
    });

    $stockByWarehouse = [];
    $routeEvents = [];
    foreach ($movementRows as $row) {
        $warehouseId = (int)$row['warehouse_id'];
        if (!isset($whMap[$warehouseId])) {
            continue;
        }
        $flightId = (int)$row['flight_id'];
        $requestId = (int)$row['zayavka_id'];
        $movementType = (string)$row['movement_type'];
        $isInbound = in_array($movementType, ['receipt', 'transfer_in'], true);
        $currentRequests = $flightMeta[$flightId]['current_requests'] ?? null;
        $excluded = is_array($currentRequests) && !isset($currentRequests[$requestId]);

        if (!isset($stockByWarehouse[$warehouseId][$requestId])) {
            $stockByWarehouse[$warehouseId][$requestId] = [
                'mass_netto' => 0.0,
                'mass_brutto' => 0.0,
                'volume' => 0.0,
            ];
        }
        $before = $stockByWarehouse[$warehouseId][$requestId];
        $isDerivedIssue = !empty($row['derived_issue']);
        if ($isDerivedIssue && (float)$before['mass_netto'] <= 0.0001 && (float)$before['mass_brutto'] <= 0.0001) {
            continue;
        }

        $massNetto = $isDerivedIssue ? max(0.0, (float)$before['mass_netto']) : abs((float)($row['mass_netto'] ?? 0));
        $massBrutto = $isDerivedIssue ? max(0.0, (float)$before['mass_brutto']) : abs((float)($row['mass_brutto'] ?? 0));
        $volume = $isDerivedIssue ? max(0.0, (float)$before['volume']) : abs((float)($row['volume'] ?? 0));
        $direction = $isInbound ? 'in' : 'out';
        $eventKey = $warehouseId . '|' . $flightId . '|' . $direction;
        if (!isset($routeEvents[$eventKey])) {
            $routeEvents[$eventKey] = [
                'warehouse_id' => $warehouseId,
                'flight_id' => $flightId,
                'movement_type' => $movementType,
                'movement_date' => (string)$row['movement_date'],
                'sequence_at' => warehouseStockSequenceAt($row),
                'request_ids' => [],
                'mass_netto_sum' => 0.0,
                'mass_brutto_sum' => 0.0,
                'volume_sum' => 0.0,
            ];
        }
        if ((string)$row['movement_date'] > (string)$routeEvents[$eventKey]['movement_date']) {
            $routeEvents[$eventKey]['movement_date'] = (string)$row['movement_date'];
        }
        if (warehouseStockSequenceAt($row) > (string)$routeEvents[$eventKey]['sequence_at']) {
            $routeEvents[$eventKey]['sequence_at'] = warehouseStockSequenceAt($row);
        }
        if ($excluded) {
            continue;
        }

        $sign = $isInbound ? 1 : -1;
        $stockByWarehouse[$warehouseId][$requestId]['mass_netto'] += $sign * $massNetto;
        $stockByWarehouse[$warehouseId][$requestId]['mass_brutto'] += $sign * $massBrutto;
        $stockByWarehouse[$warehouseId][$requestId]['volume'] += $sign * $volume;
        $routeEvents[$eventKey]['request_ids'][$requestId] = true;
        $routeEvents[$eventKey]['mass_netto_sum'] += $massNetto;
        $routeEvents[$eventKey]['mass_brutto_sum'] += $massBrutto;
        $routeEvents[$eventKey]['volume_sum'] += $volume;
    }

    foreach ($stockByWarehouse as $warehouseId => $requests) {
        foreach ($requests as $requestId => $stock) {
            $netto = round((float)$stock['mass_netto'], 4);
            $brutto = round((float)$stock['mass_brutto'], 4);
            $volume = round((float)$stock['volume'], 4);
            if ($netto <= 0.0001 && $brutto <= 0.0001) {
                continue;
            }
            $whMap[$warehouseId]['request_count']++;
            $whMap[$warehouseId]['mass_netto_sum'] += $netto;
            $whMap[$warehouseId]['mass_brutto_sum'] += $brutto;
            $whMap[$warehouseId]['volume_sum'] += $volume;
            $whMap[$warehouseId]['requests'][] = [
                'zayavka_id' => (int)$requestId,
                'fkko_name' => '',
                'mass_netto' => $netto,
                'mass_brutto' => $brutto,
                'volume' => $volume,
            ];
        }
    }

    foreach ($routeEvents as $event) {
        $warehouseId = (int)$event['warehouse_id'];
        $route = [
            'flight_id' => (int)$event['flight_id'],
            'movement_type' => $event['movement_type'],
            'movement_label' => moveLabel($event['movement_type']),
            'movement_date' => $event['movement_date'],
            'sequence_at' => $event['sequence_at'],
            'request_count' => count($event['request_ids']),
            'mass_netto_sum' => round((float)$event['mass_netto_sum'], 4),
            'mass_brutto_sum' => round((float)$event['mass_brutto_sum'], 4),
            'volume_sum' => round((float)$event['volume_sum'], 4),
        ];
        if (in_array((string)$event['movement_type'], ['receipt', 'transfer_in'], true)) {
            $whMap[$warehouseId]['inbound_routes'][] = $route;
        } else {
            $whMap[$warehouseId]['outbound_routes'][] = $route;
        }
    }

    // Сортируем inbound/outbound по фактической последовательности проводок (новые первыми).
    foreach ($whMap as &$wh) {
        usort($wh['inbound_routes'], function($a, $b) { return strcmp($b['sequence_at'] ?? '', $a['sequence_at'] ?? ''); });
        usort($wh['outbound_routes'], function($a, $b) { return strcmp($b['sequence_at'] ?? '', $a['sequence_at'] ?? ''); });
    }
    unset($wh);

    echo json_encode(['success' => true, 'warehouses' => $whMap], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

} catch (Throwable $e) {
    if (function_exists('mapError')) {
        mapError('get_warehouse_stock_map failed', ['error' => $e->getMessage()]);
    }
    echo json_encode(['success' => false, 'message' => 'Ошибка загрузки складских данных']);
}
