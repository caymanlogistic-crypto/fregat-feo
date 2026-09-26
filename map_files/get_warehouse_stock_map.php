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

    // ── Остатки по заявкам (netto > 0) ──
    $stockSql = "SELECT wm.warehouse_id, wm.zayavka_id,
        SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.mass_netto, 0) ELSE 0 END) AS in_netto,
        SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.mass_netto, 0) ELSE 0 END) AS out_netto,
        SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.mass_brutto, 0) ELSE 0 END) AS in_brutto,
        SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.mass_brutto, 0) ELSE 0 END) AS out_brutto,
        SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.volume, 0) ELSE 0 END) AS in_vol,
        SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.volume, 0) ELSE 0 END) AS out_vol
    FROM warehouse_movements wm
    WHERE wm.status = 'active'
    GROUP BY wm.warehouse_id, wm.zayavka_id
    HAVING (SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.mass_netto, 0) ELSE 0 END) - SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.mass_netto, 0) ELSE 0 END)) > 0.0001
        OR (SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.mass_brutto, 0) ELSE 0 END) - SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.mass_brutto, 0) ELSE 0 END)) > 0.0001";

    $stockRows = $pdo->query($stockSql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stockRows as $r) {
        $wid = (int)$r['warehouse_id'];
        $netto = round((float)$r['in_netto'] - (float)$r['out_netto'], 4);
        $brutto = round((float)$r['in_brutto'] - (float)$r['out_brutto'], 4);
        $vol = round((float)$r['in_vol'] - (float)$r['out_vol'], 4);
        if ($netto <= 0.0001 && $brutto <= 0.0001) continue;

        if (!isset($whMap[$wid])) continue;
        $whMap[$wid]['request_count']++;
        $whMap[$wid]['mass_netto_sum'] += $netto;
        $whMap[$wid]['mass_brutto_sum'] += $brutto;
        $whMap[$wid]['volume_sum'] += $vol;
        $whMap[$wid]['requests'][] = [
            'zayavka_id'  => (int)$r['zayavka_id'],
            'fkko_name'   => '',
            'mass_netto'  => $netto,
            'mass_brutto' => $brutto,
            'volume'      => $vol,
        ];
    }

    // ── Входящие рейсы (receipt + transfer_in) ──
    $inSql = "SELECT wm.warehouse_id, wm.flight_id, wm.movement_type, MAX(wm.movement_date) AS movement_date,
        COUNT(DISTINCT wm.zayavka_id) AS request_count,
        SUM(COALESCE(wm.mass_netto,0)) AS mass_netto_sum,
        SUM(COALESCE(wm.mass_brutto,0)) AS mass_brutto_sum,
        SUM(COALESCE(wm.volume,0)) AS volume_sum
    FROM warehouse_movements wm
    WHERE wm.status = 'active' AND wm.movement_type IN ('receipt','transfer_in')
    GROUP BY wm.warehouse_id, wm.flight_id, wm.movement_type
    ORDER BY wm.warehouse_id, MAX(wm.movement_date) DESC";

    $inRows = $pdo->query($inSql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inRows as $r) {
        $wid = (int)$r['warehouse_id'];
        if (!isset($whMap[$wid])) continue;
        $whMap[$wid]['inbound_routes'][] = [
            'flight_id'        => (int)$r['flight_id'],
            'movement_type'    => $r['movement_type'],
            'movement_label'   => moveLabel($r['movement_type']),
            'movement_date'    => $r['movement_date'],
            'request_count'    => (int)$r['request_count'],
            'mass_netto_sum'   => round((float)$r['mass_netto_sum'], 4),
            'mass_brutto_sum'  => round((float)$r['mass_brutto_sum'], 4),
            'volume_sum'       => round((float)$r['volume_sum'], 4),
        ];
    }

    // ── Исходящие рейсы (issue + transfer_out) ──
    $outSql = "SELECT wm.warehouse_id, wm.flight_id, wm.movement_type, MAX(wm.movement_date) AS movement_date,
        COUNT(DISTINCT wm.zayavka_id) AS request_count,
        SUM(COALESCE(wm.mass_netto,0)) AS mass_netto_sum,
        SUM(COALESCE(wm.mass_brutto,0)) AS mass_brutto_sum,
        SUM(COALESCE(wm.volume,0)) AS volume_sum
    FROM warehouse_movements wm
    WHERE wm.status = 'active' AND wm.movement_type IN ('issue','transfer_out')
    GROUP BY wm.warehouse_id, wm.flight_id, wm.movement_type
    ORDER BY wm.warehouse_id, MAX(wm.movement_date) DESC";

    $outRows = $pdo->query($outSql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($outRows as $r) {
        $wid = (int)$r['warehouse_id'];
        if (!isset($whMap[$wid])) continue;
        $whMap[$wid]['outbound_routes'][] = [
            'flight_id'        => (int)$r['flight_id'],
            'movement_type'    => $r['movement_type'],
            'movement_label'   => moveLabel($r['movement_type']),
            'movement_date'    => $r['movement_date'],
            'request_count'    => (int)$r['request_count'],
            'mass_netto_sum'   => round((float)$r['mass_netto_sum'], 4),
            'mass_brutto_sum'  => round((float)$r['mass_brutto_sum'], 4),
            'volume_sum'       => round((float)$r['volume_sum'], 4),
        ];
    }

    // Сортируем inbound/outbound по дате (новые первыми)
    foreach ($whMap as &$wh) {
        usort($wh['inbound_routes'], function($a, $b) { return strcmp($b['movement_date'] ?? '', $a['movement_date'] ?? ''); });
        usort($wh['outbound_routes'], function($a, $b) { return strcmp($b['movement_date'] ?? '', $a['movement_date'] ?? ''); });
    }
    unset($wh);

    echo json_encode(['success' => true, 'warehouses' => $whMap], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

} catch (Throwable $e) {
    if (function_exists('mapError')) {
        mapError('get_warehouse_stock_map failed', ['error' => $e->getMessage()]);
    }
    echo json_encode(['success' => false, 'message' => 'Ошибка загрузки складских данных']);
}
