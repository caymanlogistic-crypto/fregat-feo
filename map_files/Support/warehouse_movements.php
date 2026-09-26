<?php

if (!function_exists('normalizeRouteType')) {
function normalizeRouteType($routeTypeRaw, $unloadTypeRaw = 'OO'): string
{
    $routeType = strtolower(trim((string)$routeTypeRaw));
    $allowed = [
        ROUTE_TYPE_GENERATOR_TO_UTILIZER,
        ROUTE_TYPE_GENERATOR_TO_WAREHOUSE,
        ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE,
        ROUTE_TYPE_WAREHOUSE_TO_UTILIZER,
    ];
    if (in_array($routeType, $allowed, true)) {
        return $routeType;
    }
    $unload = strtoupper(trim((string)$unloadTypeRaw));
    if ($unload === 'SKLAD') {
        return ROUTE_TYPE_GENERATOR_TO_WAREHOUSE;
    }
    return ROUTE_TYPE_GENERATOR_TO_UTILIZER;
}
} // close function_exists('normalizeRouteType')

if (!defined('WM_MOVEMENT_RECEIPT')) { define('WM_MOVEMENT_RECEIPT', 'receipt'); }
if (!defined('WM_MOVEMENT_ISSUE')) { define('WM_MOVEMENT_ISSUE', 'issue'); }
if (!defined('WM_MOVEMENT_TRANSFER_OUT')) { define('WM_MOVEMENT_TRANSFER_OUT', 'transfer_out'); }
if (!defined('WM_MOVEMENT_TRANSFER_IN')) { define('WM_MOVEMENT_TRANSFER_IN', 'transfer_in'); }
if (!defined('WM_STATUS_ACTIVE')) { define('WM_STATUS_ACTIVE', 'active'); }

if (!defined('ROUTE_TYPE_GENERATOR_TO_UTILIZER')) { define('ROUTE_TYPE_GENERATOR_TO_UTILIZER', 'generator_to_utilizer'); }
if (!defined('ROUTE_TYPE_GENERATOR_TO_WAREHOUSE')) { define('ROUTE_TYPE_GENERATOR_TO_WAREHOUSE', 'generator_to_warehouse'); }
if (!defined('ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE')) { define('ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE', 'warehouse_to_warehouse'); }
if (!defined('ROUTE_TYPE_WAREHOUSE_TO_UTILIZER')) { define('ROUTE_TYPE_WAREHOUSE_TO_UTILIZER', 'warehouse_to_utilizer'); }

if (!function_exists('resolveUnloadTypeByRouteType')) {
function resolveUnloadTypeByRouteType(string $routeType): string
{
    return ($routeType === ROUTE_TYPE_GENERATOR_TO_UTILIZER || $routeType === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER) ? 'OO' : 'SKLAD';
}
}

/**
 * Человекочитаемая метка типа складского движения.
 */
function moveLabel(string $t): string
{
    return [
        WM_MOVEMENT_RECEIPT      => 'Поступление на склад',
        WM_MOVEMENT_TRANSFER_OUT => 'Перемещение со склада',
        WM_MOVEMENT_TRANSFER_IN  => 'Перемещение на склад',
        WM_MOVEMENT_ISSUE        => 'Вывоз со склада',
    ][$t] ?? $t;
}

/**
 * Возвращает массив zayavka_id, которые имеют положительный остаток на складе.
 *
 * @param PDO   $pdo
 * @param int   $warehouseId
 * @param int[] $zayavkaIds  — список заявок для проверки
 * @return array [zayavka_id => ['mass_netto' => float, ...], ...]
 */
function getWarehouseAvailableZayavki(PDO $pdo, int $warehouseId, array $zayavkaIds): array
{
    $result = [];
    if (empty($zayavkaIds) || $warehouseId <= 0) {
        return $result;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($zayavkaIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT wm.zayavka_id,
                SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.mass_netto,0)
                         WHEN wm.movement_type IN ('issue','transfer_out') THEN -COALESCE(wm.mass_netto,0)
                         ELSE 0 END) AS available_netto,
                SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.mass_brutto,0)
                         WHEN wm.movement_type IN ('issue','transfer_out') THEN -COALESCE(wm.mass_brutto,0)
                         ELSE 0 END) AS available_brutto,
                SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.volume,0)
                         WHEN wm.movement_type IN ('issue','transfer_out') THEN -COALESCE(wm.volume,0)
                         ELSE 0 END) AS available_vol
            FROM warehouse_movements wm
            WHERE wm.status = 'active' AND wm.warehouse_id = ?
              AND wm.zayavka_id IN ({$placeholders})
            GROUP BY wm.zayavka_id
            HAVING available_netto > 0.0001"
        );
        $stmt->execute(array_merge([$warehouseId], $zayavkaIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ((array)$rows as $row) {
            $result[(int)$row['zayavka_id']] = [
                'zayavka_id'      => (int)$row['zayavka_id'],
                'mass_netto'      => round((float)$row['available_netto'], 4),
                'mass_brutto'     => round((float)$row['available_brutto'], 4),
                'volume'          => round((float)$row['available_vol'], 4),
            ];
        }
    } catch (Throwable $e) {
        if (function_exists('mapError')) {
            mapError('getWarehouseAvailableZayavki failed', ['error' => $e->getMessage()]);
        }
    }
    return $result;
}

function warehouseMoveTypeLabel(string $routeType): string
{
    $map = [
        ROUTE_TYPE_GENERATOR_TO_UTILIZER => 'ОО → Утилизатор',
        ROUTE_TYPE_GENERATOR_TO_WAREHOUSE => 'ОО → Временный склад',
        ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE => 'Склад → Склад',
        ROUTE_TYPE_WAREHOUSE_TO_UTILIZER => 'Склад → Утилизатор',
    ];
    return $map[$routeType] ?? $routeType;
}

function wmTableExists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'warehouse_movements'");
        $exists = (bool)($stmt && $stmt->fetchColumn());
        return $exists;
    } catch (Throwable $e) {
        $exists = false;
        return false;
    }
}

function wmMovementExists(PDO $pdo, int $flightId, string $movementType, int $warehouseId, int $zayavkaId): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM warehouse_movements WHERE flight_id = :flight_id AND movement_type = :movement_type AND warehouse_id = :warehouse_id AND zayavka_id = :zayavka_id LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->execute([
            ':flight_id' => $flightId,
            ':movement_type' => $movementType,
            ':warehouse_id' => $warehouseId,
            ':zayavka_id' => $zayavkaId,
        ]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        mapError('wmMovementExists failed', ['error' => $e->getMessage()]);
        return false;
    }
}

function wmInsertMovement(PDO $pdo, array $row): ?string
{
    $columns = [
        'movement_type', 'warehouse_id', 'source_warehouse_id', 'destination_warehouse_id',
        'flight_id', 'zayavka_id', 'fkko_code', 'mass_netto', 'mass_brutto', 'volume',
        'movement_date', 'status', 'comment',
    ];
    $placeholders = [];
    $params = [];
    foreach ($columns as $col) {
        $placeholders[] = ":{$col}";
        $params[":{$col}"] = array_key_exists($col, $row) ? $row[$col] : null;
    }
    try {
        $sql = 'INSERT INTO warehouse_movements (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (' . implode(', ', $placeholders) . ', NOW(), NOW())';
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            return 'Failed to prepare INSERT';
        }
        $stmt->execute($params);
        return null;
    } catch (Throwable $e) {
        mapError('wmInsertMovement failed', ['error' => $e->getMessage(), 'row' => json_encode($row, JSON_UNESCAPED_UNICODE)]);
        return $e->getMessage();
    }
}

function validateWarehouseRequirementsForCompleted(array $flight): ?string
{
    $routeType = normalizeRouteType($flight['route_type'] ?? '', $flight['unload_type'] ?? 'OO');
    $sourceId = (int)($flight['source_warehouse_id'] ?? 0);
    $destId = (int)($flight['destination_warehouse_id'] ?? 0);

    if ($routeType === ROUTE_TYPE_GENERATOR_TO_UTILIZER) {
        return null;
    }
    if ($routeType === ROUTE_TYPE_GENERATOR_TO_WAREHOUSE && $destId <= 0) {
        return 'Для маршрута "ОО → Временный склад" выберите склад назначения.';
    }
    if ($routeType === ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE) {
        if ($sourceId <= 0 && $destId <= 0) {
            return 'Для маршрута "Склад → Склад" выберите склад отправления и склад назначения.';
        }
        if ($sourceId <= 0) {
            return 'Для маршрута "Склад → Склад" выберите склад отправления.';
        }
        if ($destId <= 0) {
            return 'Для маршрута "Склад → Склад" выберите склад назначения.';
        }
    }
    if ($routeType === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER && $sourceId <= 0) {
        return 'Для маршрута "Склад → Утилизатор" выберите склад отправления.';
    }
    return null;
}

function createWarehouseMovementsForCompletedFlight(PDO $pdo, int $flightId): array
{
    $result = [
        'success' => true,
        'created' => 0,
        'skipped' => 0,
        'errors' => [],
        'messages' => [],
    ];

    if (!wmTableExists($pdo)) {
        $result['success'] = false;
        $result['errors'][] = 'Таблица warehouse_movements не найдена';
        return $result;
    }

    try {
        $stmt = $pdo->prepare('SELECT id, status, route_type, unload_type, source_warehouse_id, destination_warehouse_id, zayavki_ids, actual_end_date FROM flights WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $flightId]);
        $flight = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($flight)) {
            $result['success'] = false;
            $result['errors'][] = 'Рейс не найден';
            return $result;
        }
    } catch (Throwable $e) {
        $result['success'] = false;
        $result['errors'][] = 'Ошибка загрузки рейса: ' . $e->getMessage();
        return $result;
    }

    if ((string)($flight['status'] ?? '') !== 'completed') {
        $result['messages'][] = 'Рейс не в статусе completed, движения не создаются';
        return $result;
    }

    $routeType = normalizeRouteType($flight['route_type'] ?? '', $flight['unload_type'] ?? 'OO');

    if ($routeType === ROUTE_TYPE_GENERATOR_TO_UTILIZER) {
        $result['messages'][] = 'Для маршрута ОО → Утилизатор складские движения не требуются';
        return $result;
    }

    $zayavkiIdsRaw = (string)($flight['zayavki_ids'] ?? '');
    $zayavkiIds = [];
    foreach (explode(',', $zayavkiIdsRaw) as $id) {
        $id = (int)trim($id);
        if ($id > 0) {
            $zayavkiIds[] = $id;
        }
    }
    if (empty($zayavkiIds)) {
        $result['messages'][] = 'Нет заявок для создания движений';
        return $result;
    }

    $feoRows = [];
    try {
        $placeholders = implode(',', array_fill(0, count($zayavkiIds), '?'));
        $stmtFeo = $pdo->prepare("SELECT zayavka_id, naim_otkhoda_fkko, mass_netto, mass_brutto, summarnyy_obem FROM feo WHERE zayavka_id IN ({$placeholders})");
        $stmtFeo->execute($zayavkiIds);
        $allRows = $stmtFeo->fetchAll(PDO::FETCH_ASSOC);
        foreach ((array)$allRows as $row) {
            $feoRows[(int)$row['zayavka_id']] = $row;
        }
    } catch (Throwable $e) {
        $result['success'] = false;
        $result['errors'][] = 'Ошибка загрузки данных заявок: ' . $e->getMessage();
        return $result;
    }

    $movementDate = !empty($flight['actual_end_date'])
        ? trim((string)$flight['actual_end_date'])
        : date('Y-m-d H:i:s');

    $sourceWarehouseId = (int)($flight['source_warehouse_id'] ?? 0);
    $destinationWarehouseId = (int)($flight['destination_warehouse_id'] ?? 0);

    // ── Для складских маршрутов с source_warehouse: проверяем, есть ли заявка на складе ──
    $availableOnSource = [];
    if (($routeType === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER || $routeType === ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE)
        && $sourceWarehouseId > 0 && !empty($zayavkiIds)
    ) {
        $availableOnSource = getWarehouseAvailableZayavki($pdo, $sourceWarehouseId, $zayavkiIds);
    }
    $skippedNotOnSource = [];

    foreach ($zayavkiIds as $zayavkaId) {
        $feoRow = $feoRows[$zayavkaId] ?? null;
        if (!is_array($feoRow)) {
            $result['skipped']++;
            continue;
        }

        $fkkoCode = trim((string)($feoRow['naim_otkhoda_fkko'] ?? ''));
        $massNetto = (float)($feoRow['mass_netto'] ?? 0);
        $massBrutto = (float)($feoRow['mass_brutto'] ?? 0);
        $volume = (float)($feoRow['summarnyy_obem'] ?? 0);

        $baseRow = [
            'flight_id' => $flightId,
            'zayavka_id' => $zayavkaId,
            'fkko_code' => $fkkoCode,
            'mass_netto' => $massNetto,
            'mass_brutto' => $massBrutto,
            'volume' => $volume,
            'movement_date' => $movementDate,
            'status' => WM_STATUS_ACTIVE,
        ];

        if ($routeType === ROUTE_TYPE_GENERATOR_TO_WAREHOUSE) {
            $row = array_merge($baseRow, [
                'movement_type' => WM_MOVEMENT_RECEIPT,
                'warehouse_id' => $destinationWarehouseId,
                'source_warehouse_id' => null,
                'destination_warehouse_id' => $destinationWarehouseId,
                'comment' => "Поступление на временный склад по рейсу #{$flightId}",
            ]);
            if (wmMovementExists($pdo, $flightId, WM_MOVEMENT_RECEIPT, $destinationWarehouseId, $zayavkaId)) {
                $result['skipped']++;
                continue;
            }
            $err = wmInsertMovement($pdo, $row);
            if ($err !== null) {
                $result['errors'][] = "Заявка {$zayavkaId}: {$err}";
            } else {
                $result['created']++;
            }
        } elseif ($routeType === ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE) {
            // Списываем только заявки, реально находящиеся на source_warehouse
            if (!isset($availableOnSource[$zayavkaId])) {
                $skippedNotOnSource[] = $zayavkaId;
                $result['skipped']++;
                continue;
            }
            $transferOut = array_merge($baseRow, [
                'movement_type' => WM_MOVEMENT_TRANSFER_OUT,
                'warehouse_id' => $sourceWarehouseId,
                'source_warehouse_id' => $sourceWarehouseId,
                'destination_warehouse_id' => $destinationWarehouseId,
                'comment' => "Выбытие со склада для перемещения по рейсу #{$flightId}",
            ]);
            $transferIn = array_merge($baseRow, [
                'movement_type' => WM_MOVEMENT_TRANSFER_IN,
                'warehouse_id' => $destinationWarehouseId,
                'source_warehouse_id' => $sourceWarehouseId,
                'destination_warehouse_id' => $destinationWarehouseId,
                'comment' => "Поступление на склад после перемещения по рейсу #{$flightId}",
            ]);

            if (!wmMovementExists($pdo, $flightId, WM_MOVEMENT_TRANSFER_OUT, $sourceWarehouseId, $zayavkaId)) {
                $err = wmInsertMovement($pdo, $transferOut);
                if ($err !== null) {
                    $result['errors'][] = "Заявка {$zayavkaId} (transfer_out): {$err}";
                } else {
                    $result['created']++;
                }
            } else {
                $result['skipped']++;
            }

            if (!wmMovementExists($pdo, $flightId, WM_MOVEMENT_TRANSFER_IN, $destinationWarehouseId, $zayavkaId)) {
                $err = wmInsertMovement($pdo, $transferIn);
                if ($err !== null) {
                    $result['errors'][] = "Заявка {$zayavkaId} (transfer_in): {$err}";
                } else {
                    $result['created']++;
                }
            } else {
                $result['skipped']++;
            }
        } elseif ($routeType === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER) {
            // Списываем только заявки, реально находящиеся на source_warehouse
            if (!isset($availableOnSource[$zayavkaId])) {
                $skippedNotOnSource[] = $zayavkaId;
                $result['skipped']++;
                continue;
            }
            $row = array_merge($baseRow, [
                'movement_type' => WM_MOVEMENT_ISSUE,
                'warehouse_id' => $sourceWarehouseId,
                'source_warehouse_id' => $sourceWarehouseId,
                'destination_warehouse_id' => null,
                'comment' => "Вывоз со склада на утилизатор по рейсу #{$flightId}",
            ]);
            if (wmMovementExists($pdo, $flightId, WM_MOVEMENT_ISSUE, $sourceWarehouseId, $zayavkaId)) {
                $result['skipped']++;
                continue;
            }
            $err = wmInsertMovement($pdo, $row);
            if ($err !== null) {
                $result['errors'][] = "Заявка {$zayavkaId}: {$err}";
            } else {
                $result['created']++;
            }
        }
    }

    if (!empty($skippedNotOnSource)) {
        $result['skipped_not_on_source_warehouse'] = $skippedNotOnSource;
        $result['messages'][] = "Пропущено заявок (не находятся на складе отправления #{$sourceWarehouseId}): " . count($skippedNotOnSource);
    }

    if (empty($result['errors']) && $result['created'] > 0) {
        $result['messages'][] = "Создано движений: {$result['created']}";
    }
    if ($result['skipped'] > 0) {
        $result['messages'][] = "Пропущено (дубли): {$result['skipped']}";
    }

    return $result;
}
