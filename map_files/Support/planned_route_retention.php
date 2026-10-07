<?php
declare(strict_types=1);

const PLANNED_ROUTE_RETENTION_TABLE = 'planned_route_retention';
const PLANNED_ROUTE_RETENTION_HOURS = 72;
const PLANNED_ROUTE_EXTENSION_HOURS = 24;
const PLANNED_ROUTE_WARNING_HOURS = 24;

function plannedRouteRetentionTimezone(): DateTimeZone
{
    static $timezone = null;
    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('Europe/Moscow');
    }
    return $timezone;
}

function plannedRouteRetentionDateTime($value = 'now'): DateTimeImmutable
{
    if ($value instanceof DateTimeImmutable) {
        return $value->setTimezone(plannedRouteRetentionTimezone());
    }
    if ($value instanceof DateTimeInterface) {
        return (new DateTimeImmutable($value->format('Y-m-d H:i:s'), $value->getTimezone()))
            ->setTimezone(plannedRouteRetentionTimezone());
    }
    return new DateTimeImmutable((string)$value, plannedRouteRetentionTimezone());
}

function plannedRouteRetentionCreationDate($value, ?DateTimeImmutable $fallback = null): DateTimeImmutable
{
    $fallback = ($fallback ?? plannedRouteRetentionDateTime())->setTimezone(plannedRouteRetentionTimezone());
    $raw = trim((string)$value);
    if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
        return $fallback;
    }
    try {
        return plannedRouteRetentionDateTime($raw);
    } catch (Throwable $e) {
        return $fallback;
    }
}

function plannedRouteRetentionMoveFromWeekend(DateTimeImmutable $date): DateTimeImmutable
{
    $weekday = (int)$date->format('N');
    if ($weekday === 6) {
        return $date->modify('+2 days');
    }
    if ($weekday === 7) {
        return $date->modify('+1 day');
    }
    return $date;
}

function plannedRouteRetentionAddBusinessHours(DateTimeImmutable $start, int $hours): DateTimeImmutable
{
    $current = plannedRouteRetentionMoveFromWeekend($start->setTimezone(plannedRouteRetentionTimezone()));
    for ($i = 0; $i < max(0, $hours); $i++) {
        $current = plannedRouteRetentionMoveFromWeekend($current->modify('+1 hour'));
    }
    return $current;
}

function plannedRouteRetentionBusinessSecondsBetween(DateTimeImmutable $start, DateTimeImmutable $end): int
{
    $start = $start->setTimezone(plannedRouteRetentionTimezone());
    $end = $end->setTimezone(plannedRouteRetentionTimezone());
    if ($end <= $start) {
        return 0;
    }

    $seconds = 0;
    $cursor = $start->setTime(0, 0, 0);
    $lastDay = $end->setTime(0, 0, 0);
    while ($cursor <= $lastDay) {
        $nextDay = $cursor->modify('+1 day');
        if ((int)$cursor->format('N') <= 5) {
            $segmentStart = $start > $cursor ? $start : $cursor;
            $segmentEnd = $end < $nextDay ? $end : $nextDay;
            if ($segmentEnd > $segmentStart) {
                $seconds += $segmentEnd->getTimestamp() - $segmentStart->getTimestamp();
            }
        }
        $cursor = $nextDay;
    }
    return $seconds;
}

function plannedRouteRetentionFormat($value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    try {
        return plannedRouteRetentionDateTime($raw)->format('d.m.Y в H:i');
    } catch (Throwable $e) {
        return $raw;
    }
}

function plannedRouteRetentionTableExists(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
        return $stmt && $stmt->execute([':table_name' => PLANNED_ROUTE_RETENTION_TABLE]) && (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function plannedRouteRetentionSchedule(PDO $pdo, int $flightId, ?DateTimeImmutable $createdAt = null, bool $reset = false): ?string
{
    if ($flightId <= 0 || !plannedRouteRetentionTableExists($pdo)) {
        return null;
    }
    $createdAt = ($createdAt ?? plannedRouteRetentionDateTime())->setTimezone(plannedRouteRetentionTimezone());
    $expiresAt = plannedRouteRetentionAddBusinessHours($createdAt, PLANNED_ROUTE_RETENTION_HOURS);
    $sql = $reset
        ? 'INSERT INTO planned_route_retention (flight_id, created_at, expires_at, warning_sent_at, extended_count) VALUES (:flight_id, :created_at, :expires_at, NULL, 0) ON DUPLICATE KEY UPDATE created_at = VALUES(created_at), expires_at = VALUES(expires_at), warning_sent_at = NULL, extended_count = 0'
        : 'INSERT IGNORE INTO planned_route_retention (flight_id, created_at, expires_at, warning_sent_at, extended_count) VALUES (:flight_id, :created_at, :expires_at, NULL, 0)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':flight_id' => $flightId,
        ':created_at' => $createdAt->format('Y-m-d H:i:s'),
        ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);
    return $expiresAt->format('Y-m-d H:i:s');
}

function plannedRouteRetentionProtectAndSchedule(PDO $pdo, int $flightId, DateTimeImmutable $createdAt, ?DateTimeImmutable $now = null): ?string
{
    if ($flightId <= 0 || !plannedRouteRetentionTableExists($pdo)) {
        return null;
    }
    $now = ($now ?? plannedRouteRetentionDateTime())->setTimezone(plannedRouteRetentionTimezone());
    $createdAt = $createdAt->setTimezone(plannedRouteRetentionTimezone());
    $expiresAt = plannedRouteRetentionAddBusinessHours($createdAt, PLANNED_ROUTE_RETENTION_HOURS);
    if ($expiresAt <= $now) {
        $expiresAt = plannedRouteRetentionAddBusinessHours($now, PLANNED_ROUTE_EXTENSION_HOURS);
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO planned_route_retention (flight_id, created_at, expires_at, warning_sent_at, extended_count) VALUES (:flight_id, :created_at, :expires_at, NULL, 0)');
    $stmt->execute([
        ':flight_id' => $flightId,
        ':created_at' => $createdAt->format('Y-m-d H:i:s'),
        ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);
    return $expiresAt->format('Y-m-d H:i:s');
}

function plannedRouteRetentionSyncMissing(PDO $pdo, ?DateTimeImmutable $now = null): int
{
    if (!plannedRouteRetentionTableExists($pdo)) {
        return 0;
    }
    $now = $now ?? plannedRouteRetentionDateTime();
    $stmt = $pdo->query("SELECT f.id, f.block_date FROM flights f LEFT JOIN planned_route_retention prr ON prr.flight_id = f.id WHERE f.status = 'planned_route' AND prr.flight_id IS NULL ORDER BY f.id");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $created = 0;
    foreach ($rows as $row) {
        $flightId = (int)($row['id'] ?? 0);
        if ($flightId <= 0) {
            continue;
        }
        try {
            $createdAt = plannedRouteRetentionCreationDate($row['block_date'] ?? '', $now);
            plannedRouteRetentionProtectAndSchedule($pdo, $flightId, $createdAt, $now);
            $created++;
        } catch (Throwable $e) {
            if (function_exists('mapError')) {
                mapError('planned route retention sync failed', ['flight_id' => $flightId, 'error' => $e->getMessage()]);
            }
        }
    }
    return $created;
}

function plannedRouteRetentionExtend(PDO $pdo, int $flightId, ?DateTimeImmutable $now = null): ?string
{
    if ($flightId <= 0 || !plannedRouteRetentionTableExists($pdo)) {
        return null;
    }
    $now = ($now ?? plannedRouteRetentionDateTime())->setTimezone(plannedRouteRetentionTimezone());
    $stmt = $pdo->prepare("SELECT prr.expires_at FROM planned_route_retention prr INNER JOIN flights f ON f.id = prr.flight_id WHERE prr.flight_id = :flight_id AND f.status = 'planned_route' LIMIT 1");
    $stmt->execute([':flight_id' => $flightId]);
    $currentRaw = (string)$stmt->fetchColumn();
    if ($currentRaw === '') {
        return null;
    }
    $current = plannedRouteRetentionDateTime($currentRaw);
    $base = $current > $now ? $current : $now;
    $expiresAt = plannedRouteRetentionAddBusinessHours($base, PLANNED_ROUTE_EXTENSION_HOURS);
    $update = $pdo->prepare('UPDATE planned_route_retention SET expires_at = :expires_at, warning_sent_at = NULL, extended_count = extended_count + 1 WHERE flight_id = :flight_id');
    $update->execute([
        ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ':flight_id' => $flightId,
    ]);
    return $expiresAt->format('Y-m-d H:i:s');
}

function plannedRouteRetentionDeleteState(PDO $pdo, int $flightId): void
{
    if ($flightId <= 0 || !plannedRouteRetentionTableExists($pdo)) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM planned_route_retention WHERE flight_id = :flight_id');
    $stmt->execute([':flight_id' => $flightId]);
}

function plannedRouteRetentionManagerName(PDO $pdo, int $managerId): string
{
    if ($managerId <= 0) {
        return 'Менеджер не указан';
    }
    try {
        $stmt = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(`Фамилия`, ''), ' ', COALESCE(`Имя`, ''))) FROM users WHERE id = :id LIMIT 1");
        if ($stmt && $stmt->execute([':id' => $managerId])) {
            $name = trim((string)$stmt->fetchColumn());
            if ($name !== '') {
                return $name;
            }
        }
    } catch (Throwable $e) {
        if (function_exists('mapError')) {
            mapError('planned route retention manager lookup failed', ['manager_id' => $managerId, 'error' => $e->getMessage()]);
        }
    }
    return 'Менеджер #' . $managerId;
}

function plannedRouteRetentionProcess(PDO $pdo, bool $sendWarnings = true, ?DateTimeImmutable $now = null): array
{
    $result = [
        'available' => false,
        'synced' => 0,
        'warning_due' => 0,
        'warning_sent' => 0,
        'warning_failed' => 0,
        'deleted' => 0,
    ];
    if (!plannedRouteRetentionTableExists($pdo)) {
        return $result;
    }
    $result['available'] = true;
    $now = ($now ?? plannedRouteRetentionDateTime())->setTimezone(plannedRouteRetentionTimezone());

    $lockStmt = $pdo->query("SELECT GET_LOCK('fregat_planned_route_retention', 0)");
    if (!$lockStmt || (int)$lockStmt->fetchColumn() !== 1) {
        $result['locked'] = true;
        return $result;
    }

    try {
        $result['synced'] = plannedRouteRetentionSyncMissing($pdo, $now);

        $warningStmt = $pdo->prepare(<<<'SQL'
SELECT f.id, f.comment, f.zayavki_ids, f.assigned_manager_id, prr.expires_at
FROM planned_route_retention prr
INNER JOIN flights f ON f.id = prr.flight_id
WHERE f.status = 'planned_route'
  AND prr.warning_sent_at IS NULL
  AND prr.expires_at > :now
ORDER BY prr.expires_at, f.id
SQL);
        $warningStmt->execute([':now' => $now->format('Y-m-d H:i:s')]);
        foreach ($warningStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $expiresAt = plannedRouteRetentionDateTime($row['expires_at'] ?? '');
            $remaining = plannedRouteRetentionBusinessSecondsBetween($now, $expiresAt);
            if ($remaining <= 0 || $remaining >= PLANNED_ROUTE_WARNING_HOURS * 3600) {
                continue;
            }
            $result['warning_due']++;
            if (!$sendWarnings || !function_exists('notifyEvent')) {
                continue;
            }

            $flightId = (int)($row['id'] ?? 0);
            $title = trim((string)($row['comment'] ?? ''));
            if ($title === '') {
                $title = 'Рейс #' . $flightId;
            }
            $requests = implode(', ', array_values(array_filter(array_map('trim', explode(',', (string)($row['zayavki_ids'] ?? ''))))));
            if ($requests === '') {
                $requests = 'не указаны';
            }
            $manager = plannedRouteRetentionManagerName($pdo, (int)($row['assigned_manager_id'] ?? 0));
            $deleteAt = plannedRouteRetentionFormat($expiresAt->format('Y-m-d H:i:s'));
            $fallback = "АВТ. УДАЛЕНИЕ\n#{$flightId} {$title}\nЗаявки: {$requests}\nбудет удалён\n{$deleteAt}\nПри необходимости продлите хранение\nРейс закреплён: {$manager}";
            $notify = notifyEvent('route_auto_delete_warning', [
                'route_id' => (string)$flightId,
                'route_title' => $title,
                'requests' => $requests,
                'request_ids' => $requests,
                'delete_at' => $deleteAt,
                'expires_at' => $deleteAt,
                'manager' => $manager,
                'responsible' => $manager,
                'message' => $fallback,
            ], $fallback);
            if (!empty($notify['success'])) {
                $mark = $pdo->prepare('UPDATE planned_route_retention SET warning_sent_at = :sent_at WHERE flight_id = :flight_id AND warning_sent_at IS NULL');
                $mark->execute([
                    ':sent_at' => $now->format('Y-m-d H:i:s'),
                    ':flight_id' => $flightId,
                ]);
                $result['warning_sent']++;
            } else {
                $result['warning_failed']++;
            }
        }

        $pdo->beginTransaction();
        try {
            $expiredStmt = $pdo->prepare("SELECT f.id FROM flights f INNER JOIN planned_route_retention prr ON prr.flight_id = f.id WHERE f.status = 'planned_route' AND prr.expires_at <= :now FOR UPDATE");
            $expiredStmt->execute([':now' => $now->format('Y-m-d H:i:s')]);
            $expiredIds = array_values(array_filter(array_map('intval', $expiredStmt->fetchAll(PDO::FETCH_COLUMN))));
            if ($expiredIds) {
                $placeholders = implode(',', array_fill(0, count($expiredIds), '?'));
                $deleteFlights = $pdo->prepare("DELETE FROM flights WHERE status = 'planned_route' AND id IN ({$placeholders})");
                $deleteFlights->execute($expiredIds);
                $deleteState = $pdo->prepare("DELETE FROM planned_route_retention WHERE flight_id IN ({$placeholders})");
                $deleteState->execute($expiredIds);
                $result['deleted'] = $deleteFlights->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('fregat_planned_route_retention')");
    }

    return $result;
}
