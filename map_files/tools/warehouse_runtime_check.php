<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }

/**
 * Warehouse Runtime Check Tool — read-only diagnostic script.
 *
 * Usage:
 *   /usr/bin/php8.4 map_files/tools/warehouse_runtime_check.php 187
 */

if ($argc < 2 || !preg_match('/^\d+$/', $argv[1])) {
    fwrite(STDERR, "Usage: php warehouse_runtime_check.php <flight_id>\n");
    exit(1);
}

$flightId = (int)$argv[1];

require_once __DIR__ . '/../bootstrap.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "FATAL: No database connection.\n");
    exit(1);
}

function p(string $line): void
{
    echo $line . "\n";
}

function hr(): void
{
    echo str_repeat('=', 72) . "\n";
}

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute($params)) {
        return null;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute($params)) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function safeStr($value): string
{
    return trim((string)($value ?? ''));
}

// ──────────────────────────────────────────────────────
// SECTION 1 — FLIGHT
// ──────────────────────────────────────────────────────
hr();
p('SECTION 1 — FLIGHT #' . $flightId);
hr();

$flight = fetchOne($pdo, 'SELECT id, status, route_type, unload_type, source_warehouse_id, destination_warehouse_id, zayavki_ids, direct_zayavki_ids, zayavki_count, planned_start_date_from, planned_start_date_to, actual_start_date, actual_end_date, comment, cost, driver_id FROM flights WHERE id = :id LIMIT 1', [':id' => $flightId]);

if (!$flight) {
    p('FAIL: Рейс #' . $flightId . ' не найден в таблице flights.');
    exit(1);
}

foreach ($flight as $k => $v) {
    printf("  %-30s = %s\n", $k, ($v === null ? 'NULL' : (string)$v));
}

p('');

$zayavkiIds = [];
foreach (explode(',', (string)($flight['zayavki_ids'] ?? '')) as $id) {
    $id = trim($id);
    if ($id !== '' && preg_match('/^\d+$/', $id)) {
        $zayavkiIds[] = (int)$id;
    }
}
$zayavkiIdsCount = count($zayavkiIds);
$directZayavkiIds = [];
foreach (explode(',', (string)($flight['direct_zayavki_ids'] ?? '')) as $id) {
    $id = (int)trim($id);
    if ($id > 0) $directZayavkiIds[$id] = $id;
}
$warehouseZayavkiIds = array_values(array_diff($zayavkiIds, array_values($directZayavkiIds)));
$warehouseZayavkiIdsCount = count($warehouseZayavkiIds);

$routeType = strtolower(safeStr($flight['route_type'] ?? ''));
$status = safeStr($flight['status'] ?? '');
$sourceWid = (int)($flight['source_warehouse_id'] ?? 0);
$destWid = (int)($flight['destination_warehouse_id'] ?? 0);
$readinessMissingIds = [];

// ──────────────────────────────────────────────────────
// SECTION 2 — WAREHOUSES
// ──────────────────────────────────────────────────────
hr();
p('SECTION 2 — WAREHOUSES');
hr();

$whIds = [];
if ($sourceWid > 0) $whIds[] = $sourceWid;
if ($destWid > 0) $whIds[] = $destWid;
$whIds = array_unique($whIds);

if (empty($whIds)) {
    p('  Склады не указаны в рейсе.');
} else {
    $placeholders = implode(',', array_fill(0, count($whIds), '?'));
    $whRows = fetchAll($pdo, "SELECT id, name, full_address, is_active FROM warehouses WHERE id IN ({$placeholders})", $whIds);
    if (empty($whRows)) {
        p('  WARN: Склады не найдены в таблице warehouses.');
    }
    foreach ($whRows as $wh) {
        foreach ($wh as $k => $v) {
            printf("  %-30s = %s\n", $k, ($v === null ? 'NULL' : (string)$v));
        }
        p('');
    }
    foreach ($whIds as $whId) {
        $found = false;
        foreach ($whRows as $wh) {
            if ((int)$wh['id'] === $whId) { $found = true; break; }
        }
        if (!$found) {
            p("  FAIL: warehouse_id={$whId} не найден в таблице warehouses.");
        }
    }
}

// ──────────────────────────────────────────────────────
// SECTION 3 — FEO REQUESTS
// ──────────────────────────────────────────────────────
hr();
p('SECTION 3 — FEO REQUESTS (zayavki_ids)');
hr();

if (empty($zayavkiIds)) {
    p('  WARN: zayavki_ids пуст.');
} else {
    $feoPlaceholders = implode(',', array_fill(0, $zayavkiIdsCount, '?'));
    $feoRows = fetchAll($pdo, "SELECT zayavka_id, naim_otkhoda_fkko, mass_netto, mass_brutto, summarnyy_obem, mno_region, mno_adres_pogruzki FROM feo WHERE zayavka_id IN ({$feoPlaceholders})", $zayavkiIds);
    p('  Found ' . count($feoRows) . ' / ' . $zayavkiIdsCount . ' requests.');
    p('');
    foreach ($feoRows as $feo) {
        foreach ($feo as $k => $v) {
            printf("    %-30s = %s\n", $k, ($v === null ? 'NULL' : (string)$v));
        }
        p('');
    }
    $foundIds = [];
    foreach ($feoRows as $feo) { $foundIds[(int)$feo['zayavka_id']] = true; }
    foreach ($zayavkiIds as $zid) {
        if (!isset($foundIds[$zid])) {
            p("  FAIL: zayavka_id={$zid} не найден в feo.");
        }
    }
}

// ──────────────────────────────────────────────────────
// SECTION 3B — SOURCE WAREHOUSE READINESS
// ──────────────────────────────────────────────────────
if (!in_array($status, ['started', 'completed'], true)
    && in_array($routeType, ['warehouse_to_warehouse', 'warehouse_to_utilizer', 'mixed_to_utilizer'], true)
    && $sourceWid > 0
) {
    hr();
    p('SECTION 3B — SOURCE WAREHOUSE READINESS');
    hr();
    $readinessIds = $routeType === 'mixed_to_utilizer' ? $warehouseZayavkiIds : $zayavkiIds;
    $availableIds = [];
    if ($readinessIds) {
        $readinessPlaceholders = implode(',', array_fill(0, count($readinessIds), '?'));
        $availabilityRows = fetchAll(
            $pdo,
            "SELECT zayavka_id,
                    SUM(CASE WHEN movement_type IN ('receipt','transfer_in') THEN COALESCE(mass_netto,0)
                             WHEN movement_type IN ('issue','transfer_out') THEN -COALESCE(mass_netto,0)
                             ELSE 0 END) AS available_netto
             FROM warehouse_movements
             WHERE status = 'active' AND warehouse_id = ?
               AND zayavka_id IN ({$readinessPlaceholders})
             GROUP BY zayavka_id
             HAVING available_netto > 0.0001",
            array_merge([$sourceWid], $readinessIds)
        );
        foreach ($availabilityRows as $availabilityRow) {
            $availableIds[(int)$availabilityRow['zayavka_id']] = true;
        }
    }
    $readinessMissingIds = array_values(array_filter(
        $readinessIds,
        static fn(int $id): bool => !isset($availableIds[$id])
    ));
    p('  Нужны со склада: ' . count($readinessIds) . '.');
    p('  Уже доступны: ' . count($availableIds) . '.');
    p('  Отсутствуют: ' . ($readinessMissingIds ? implode(', ', $readinessMissingIds) : 'нет') . '.');

    if ($readinessMissingIds) {
        $incomingRows = fetchAll(
            $pdo,
            "SELECT id, status, route_type, unload_type, zayavki_ids, actual_end_date
             FROM flights
             WHERE destination_warehouse_id = ?
               AND (route_type IN ('generator_to_warehouse','warehouse_to_warehouse')
                    OR (unload_type = 'SKLAD' AND (route_type IS NULL OR route_type = '')))
             ORDER BY id DESC",
            [$sourceWid]
        );
        $missingMap = array_fill_keys($readinessMissingIds, true);
        foreach ($incomingRows as $incomingRow) {
            $matched = [];
            foreach (explode(',', (string)($incomingRow['zayavki_ids'] ?? '')) as $incomingIdRaw) {
                $incomingId = (int)trim($incomingIdRaw);
                if ($incomingId > 0 && isset($missingMap[$incomingId])) {
                    $matched[$incomingId] = $incomingId;
                }
            }
            if ($matched) {
                p(
                    '  Ожидаются через рейс #' . (int)$incomingRow['id']
                    . ' [' . safeStr($incomingRow['status'] ?? '') . ']'
                    . ': ' . implode(', ', array_values($matched))
                    . '; завершён: ' . (safeStr($incomingRow['actual_end_date'] ?? '') ?: 'нет') . '.'
                );
            }
        }
    }
}

// ──────────────────────────────────────────────────────
// SECTION 4 — WAREHOUSE MOVEMENTS
// ──────────────────────────────────────────────────────
hr();
p('SECTION 4 — WAREHOUSE MOVEMENTS FOR FLIGHT #' . $flightId);
hr();

try {
    $wmTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'warehouse_movements'")->fetchColumn();
} catch (Throwable $e) {
    p('  FAIL: Не удалось проверить таблицу warehouse_movements: ' . $e->getMessage());
    $wmTableExists = false;
}

if (!$wmTableExists) {
    p('  FAIL: Таблица warehouse_movements не найдена.');
} else {
    $wmRows = fetchAll($pdo, 'SELECT id, movement_type, warehouse_id, source_warehouse_id, destination_warehouse_id, flight_id, zayavka_id, fkko_code, ROUND(mass_netto, 3) AS mass_netto, ROUND(mass_brutto, 3) AS mass_brutto, ROUND(volume, 3) AS volume, movement_date, status, comment, created_at FROM warehouse_movements WHERE flight_id = :fid ORDER BY id', [':fid' => $flightId]);
    p('  Всего движений: ' . count($wmRows));
    p('');
    if (empty($wmRows)) {
        p('  Движений нет.');
    }
    foreach ($wmRows as $wm) {
        foreach ($wm as $k => $v) {
            printf("    %-28s = %s\n", $k, ($v === null ? 'NULL' : (string)$v));
        }
        p('');
    }
}

// ──────────────────────────────────────────────────────
// SECTION 5 — DUPLICATES
// ──────────────────────────────────────────────────────
hr();
p('SECTION 5 — DUPLICATES CHECK');
hr();

if (!$wmTableExists) {
    p('  SKIP: таблица warehouse_movements отсутствует.');
} else {
    $dupRows = fetchAll($pdo, 'SELECT flight_id, movement_type, warehouse_id, zayavka_id, COUNT(*) AS cnt FROM warehouse_movements WHERE flight_id = :fid GROUP BY flight_id, movement_type, warehouse_id, zayavka_id HAVING COUNT(*) > 1', [':fid' => $flightId]);
    if (empty($dupRows)) {
        p('  PASS: Дублей не найдено.');
    } else {
        p('  FAIL: Найдены дубли:');
        foreach ($dupRows as $dup) {
            printf("    flight_id=%s movement_type=%s warehouse_id=%s zayavka_id=%s count=%d\n",
                $dup['flight_id'], $dup['movement_type'], $dup['warehouse_id'], $dup['zayavka_id'], $dup['cnt']);
        }
    }
}

// ──────────────────────────────────────────────────────
// SECTION 6 — STOCK AGGREGATE
// ──────────────────────────────────────────────────────
hr();
p('SECTION 6 — STOCK AGGREGATE BY WAREHOUSE / FKKO (active only)');
hr();

if (!$wmTableExists) {
    p('  SKIP: таблица warehouse_movements отсутствует.');
} else {
    $stockRows = fetchAll($pdo, "SELECT warehouse_id, fkko_code, SUM(CASE WHEN movement_type IN ('receipt','transfer_in','adjustment') THEN mass_netto WHEN movement_type IN ('issue','transfer_out') THEN -mass_netto ELSE 0 END) AS stock_netto, SUM(CASE WHEN movement_type IN ('receipt','transfer_in','adjustment') THEN mass_brutto WHEN movement_type IN ('issue','transfer_out') THEN -mass_brutto ELSE 0 END) AS stock_brutto, SUM(CASE WHEN movement_type IN ('receipt','transfer_in','adjustment') THEN volume WHEN movement_type IN ('issue','transfer_out') THEN -volume ELSE 0 END) AS stock_volume FROM warehouse_movements WHERE status = 'active' GROUP BY warehouse_id, fkko_code ORDER BY warehouse_id, fkko_code");
    if (empty($stockRows)) {
        p('  Нет активных остатков.');
    } else {
        foreach ($stockRows as $sr) {
            printf("  wh=%s fkko=%s netto=%.3f brutto=%.3f vol=%.3f\n",
                $sr['warehouse_id'], safeStr($sr['fkko_code']), (float)$sr['stock_netto'], (float)$sr['stock_brutto'], (float)$sr['stock_volume']);
        }
    }
}

// ──────────────────────────────────────────────────────
// SECTION 7 — EXPECTED RESULT
// ──────────────────────────────────────────────────────
hr();
p('SECTION 7 — EXPECTED RESULT (by route_type)');
hr();

p('  route_type = ' . ($routeType ?: '(empty)'));
p('  status     = ' . ($status ?: '(empty)'));

$expectedType = '';
$expectedCount = 0;

if ($routeType === 'generator_to_utilizer') {
    p('  Ожидание: складских движений не требуется.');
} elseif ($routeType === 'generator_to_warehouse') {
    p('  Ожидание: ' . $zayavkiIdsCount . ' записей receipt.');
    p('  warehouse_id должен = destination_warehouse_id (' . $destWid . ').');
    $expectedType = 'receipt';
    $expectedCount = $zayavkiIdsCount;
} elseif ($routeType === 'warehouse_to_warehouse') {
    p('  Ожидание: ' . ($zayavkiIdsCount * 2) . ' записей (transfer_out + transfer_in на каждую заявку).');
} elseif ($routeType === 'warehouse_to_utilizer') {
    p('  Ожидание: ' . $zayavkiIdsCount . ' записей issue.');
    p('  warehouse_id должен = source_warehouse_id (' . $sourceWid . ').');
    $expectedType = 'issue';
    $expectedCount = $zayavkiIdsCount;
} elseif ($routeType === 'mixed_to_utilizer') {
    p('  Ожидание: ' . $warehouseZayavkiIdsCount . ' записей issue только для складской группы.');
    p('  Прямые заявки без складских движений: ' . count($directZayavkiIds) . '.');
    p('  warehouse_id должен = source_warehouse_id (' . $sourceWid . ').');
    $expectedType = 'issue';
    $expectedCount = $warehouseZayavkiIdsCount;
} else {
    p('  FAIL: неизвестный route_type.');
}

// ──────────────────────────────────────────────────────
// SECTION 8 — PASS / WARN / FAIL
// ──────────────────────────────────────────────────────
hr();
p('SECTION 8 — VERDICT');
hr();

$verdict = 'PASS';
$messages = [];

// Check route_type for #187
if ($flightId === 187 && $routeType !== 'generator_to_warehouse') {
    $verdict = 'FAIL';
    $messages[] = 'Тестовый рейс #187: route_type не generator_to_warehouse. Настройте в UI: Маршрут груза = ОО → Временный склад.';
}

// Before completion checks
if ($status !== 'completed') {
    if (!$wmTableExists || empty($wmRows)) {
        $messages[] = 'BEFORE COMPLETION: движений нет — OK.';
    } else {
        $verdict = ($verdict === 'PASS') ? 'WARN' : $verdict;
        $messages[] = 'BEFORE COMPLETION: движения уже есть, хотя статус не completed.';
    }
    if ($routeType === 'generator_to_warehouse' && $destWid <= 0) {
        $verdict = 'FAIL';
        $messages[] = 'BEFORE COMPLETION: destination_warehouse_id не заполнен.';
    }
    if ($readinessMissingIds) {
        $verdict = 'FAIL';
        $messages[] = 'START BLOCKED: на складе отсутствуют заявки ' . implode(', ', $readinessMissingIds) . '.';
    } elseif (in_array($routeType, ['warehouse_to_warehouse', 'warehouse_to_utilizer', 'mixed_to_utilizer'], true)) {
        $messages[] = 'START READY: все складские заявки доступны.';
    }
}

// After completion checks
if ($status === 'completed') {
    if ($routeType === 'generator_to_utilizer') {
        if (!$wmTableExists || empty($wmRows)) {
            $messages[] = 'AFTER COMPLETION (generator_to_utilizer): движений нет — OK.';
        } else {
            $verdict = ($verdict === 'PASS') ? 'WARN' : $verdict;
            $messages[] = 'AFTER COMPLETION: есть движения, хотя route_type=generator_to_utilizer (не ожидалось).';
        }
    } elseif ($routeType === 'generator_to_warehouse') {
        $receiptCount = 0;
        $receiptErrors = [];
        if ($wmTableExists && !empty($wmRows)) {
            foreach ($wmRows as $wm) {
                if ($wm['movement_type'] === 'receipt') {
                    $receiptCount++;
                    if ((int)$wm['warehouse_id'] !== $destWid) {
                        $receiptErrors[] = 'zayavka_id=' . $wm['zayavka_id'] . ' warehouse_id=' . $wm['warehouse_id'] . ' (ожидалось=' . $destWid . ')';
                    }
                }
            }
        }
        if ($receiptCount === 0) {
            $verdict = 'FAIL';
            $messages[] = 'AFTER COMPLETION: receipt-движения отсутствуют.';
        } elseif ($receiptCount !== $zayavkiIdsCount) {
            $verdict = ($verdict === 'PASS') ? 'WARN' : $verdict;
            $messages[] = "AFTER COMPLETION: receipt count={$receiptCount}, ожидалось={$zayavkiIdsCount}.";
        } else {
            $messages[] = "AFTER COMPLETION: receipt count={$receiptCount} — OK.";
        }
        foreach ($receiptErrors as $err) {
            $messages[] = 'FAIL: warehouse_id mismatch — ' . $err;
            $verdict = 'FAIL';
        }
    } elseif (in_array($routeType, ['warehouse_to_utilizer', 'mixed_to_utilizer'], true)) {
        $expectedIssueIds = $routeType === 'mixed_to_utilizer' ? $warehouseZayavkiIds : $zayavkiIds;
        $expectedIssueMap = array_fill_keys(array_map('intval', $expectedIssueIds), true);
        $directMap = array_fill_keys(array_map('intval', $directZayavkiIds), true);
        $activeIssueCount = 0;
        foreach (($wmRows ?? []) as $wm) {
            if (($wm['movement_type'] ?? '') !== 'issue' || ($wm['status'] ?? '') !== 'active') {
                continue;
            }
            $activeIssueCount++;
            $requestId = (int)($wm['zayavka_id'] ?? 0);
            if ((int)($wm['warehouse_id'] ?? 0) !== $sourceWid) {
                $verdict = 'FAIL';
                $messages[] = "FAIL: issue заявки {$requestId} создан не на складе {$sourceWid}.";
            }
            if (!isset($expectedIssueMap[$requestId])) {
                $verdict = 'FAIL';
                $messages[] = isset($directMap[$requestId])
                    ? "FAIL: для прямой заявки {$requestId} создано активное складское списание."
                    : "FAIL: активное issue содержит постороннюю заявку {$requestId}.";
            }
        }
        if ($activeIssueCount !== count($expectedIssueIds)) {
            $verdict = 'FAIL';
            $messages[] = "AFTER COMPLETION: active issue count={$activeIssueCount}, ожидалось=" . count($expectedIssueIds) . '.';
        } else {
            $messages[] = "AFTER COMPLETION: active issue count={$activeIssueCount} — OK.";
        }
    }
}

// Duplicate check in verdict
if ($wmTableExists && !empty($dupRows)) {
    $verdict = 'FAIL';
    $messages[] = 'Обнаружены дубли в warehouse_movements.';
}

foreach ($messages as $m) {
    p('  ' . $m);
}
p('');
p('  FINAL: ' . $verdict);
hr();
