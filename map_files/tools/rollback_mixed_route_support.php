<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection is not initialized.\n");
    exit(1);
}

function mixedRollbackNormalizeIds($raw): string
{
    $ids = [];
    foreach (explode(',', (string)$raw) as $value) {
        $id = (int)trim($value);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return implode(',', array_values($ids));
}

function mixedRollbackTableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $columns = [];
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }
    return $columns;
}

function mixedRollbackWriteSafetyBackup(string $sourcePath, array $payload): array
{
    $directory = dirname($sourcePath);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Cannot encode pre-rollback backup.');
    }
    $suffix = bin2hex(random_bytes(4));
    $path = $directory . DIRECTORY_SEPARATOR . 'mixed_route_before_rollback_' . gmdate('Ymd_His') . '_' . $suffix . '.json';
    $temporaryPath = $path . '.tmp';
    if (file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write pre-rollback backup.');
    }
    @chmod($temporaryPath, 0600);
    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Cannot finalize pre-rollback backup.');
    }
    @chmod($path, 0600);
    $hash = hash_file('sha256', $path);
    if (!is_string($hash) || file_put_contents($path . '.sha256', $hash . '  ' . basename($path) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write pre-rollback checksum.');
    }
    @chmod($path . '.sha256', 0600);
    return [$path, $hash];
}

$arguments = array_slice($argv, 1);
$apply = in_array('--apply', $arguments, true);
$restoreMovements = in_array('--restore-movements', $arguments, true);
$paths = array_values(array_filter($arguments, static fn(string $argument): bool => !str_starts_with($argument, '--')));
$unknownOptions = array_values(array_filter(
    $arguments,
    static fn(string $argument): bool => str_starts_with($argument, '--')
        && !in_array($argument, ['--apply', '--restore-movements'], true)
));
if (count($paths) !== 1 || $unknownOptions) {
    fwrite(STDERR, "Usage: php rollback_mixed_route_support.php <backup.json> [--apply] [--restore-movements]\n");
    exit(1);
}

$backupPath = realpath($paths[0]);
if (!is_string($backupPath) || !is_file($backupPath)) {
    fwrite(STDERR, "Backup file not found.\n");
    exit(1);
}
$checksumPath = $backupPath . '.sha256';
if (!is_file($checksumPath)) {
    fwrite(STDERR, "Checksum file not found: {$checksumPath}\n");
    exit(1);
}
$checksumText = trim((string)file_get_contents($checksumPath));
if (!preg_match('/^([a-f0-9]{64})\b/i', $checksumText, $matches)) {
    fwrite(STDERR, "Invalid checksum file.\n");
    exit(1);
}
$actualHash = hash_file('sha256', $backupPath);
if (!is_string($actualHash) || !hash_equals(strtolower($matches[1]), strtolower($actualHash))) {
    fwrite(STDERR, "Backup checksum mismatch. Rollback stopped.\n");
    exit(1);
}

$payload = json_decode((string)file_get_contents($backupPath), true);
if (!is_array($payload)
    || (int)($payload['format_version'] ?? 0) !== 1
    || !is_array($payload['targets'] ?? null)
    || !is_array($payload['flights'] ?? null)) {
    fwrite(STDERR, "Unsupported or damaged backup format.\n");
    exit(1);
}

$backupDatabase = (string)($payload['database'] ?? '');
$currentDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($backupDatabase === '' || $backupDatabase !== $currentDatabase) {
    fwrite(STDERR, "Database mismatch: backup={$backupDatabase}, current={$currentDatabase}.\n");
    exit(1);
}

$targets = [];
foreach ($payload['targets'] as $flightIdRaw => $directIdsRaw) {
    $flightId = (int)$flightIdRaw;
    if ($flightId <= 0 || !is_array($directIdsRaw)) {
        fwrite(STDERR, "Invalid target definition in backup.\n");
        exit(1);
    }
    $targets[$flightId] = array_values(array_map('intval', $directIdsRaw));
}
$expectedTargets = [
    269 => [306474, 309026, 309808, 310330, 310865, 310875, 310884, 312762, 312821, 313029, 315058],
    351 => [291382],
    353 => [312015],
];
if ($targets !== $expectedTargets) {
    fwrite(STDERR, "Rollback stopped: backup targets do not match the approved flights.\n");
    exit(1);
}
$flightIds = array_keys($targets);
$placeholders = implode(',', array_fill(0, count($flightIds), '?'));
$columnStmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'direct_zayavki_ids'");
if (!$columnStmt || !$columnStmt->fetch(PDO::FETCH_ASSOC)) {
    fwrite(STDERR, "Rollback stopped: flights.direct_zayavki_ids does not exist.\n");
    exit(1);
}

$currentStmt = $pdo->prepare("SELECT * FROM flights WHERE id IN ({$placeholders}) ORDER BY id");
$currentStmt->execute($flightIds);
$currentRows = $currentStmt->fetchAll(PDO::FETCH_ASSOC);
if (count($currentRows) !== count($targets)) {
    fwrite(STDERR, "Rollback stopped: one or more target flights are missing.\n");
    exit(1);
}
foreach ($currentRows as $row) {
    $flightId = (int)$row['id'];
    $expectedDirect = mixedRollbackNormalizeIds(implode(',', $targets[$flightId]));
    $currentDirect = mixedRollbackNormalizeIds($row['direct_zayavki_ids'] ?? '');
    if (($row['route_type'] ?? '') !== 'mixed_to_utilizer' || $currentDirect !== $expectedDirect) {
        fwrite(STDERR, "Rollback stopped: flight #{$flightId} was changed after migration. Manual review is required.\n");
        exit(1);
    }
}

$beforeById = [];
foreach ($payload['flights'] as $row) {
    if (is_array($row) && (int)($row['id'] ?? 0) > 0) {
        $beforeById[(int)$row['id']] = $row;
    }
}
if (count(array_intersect($flightIds, array_keys($beforeById))) !== count($flightIds)) {
    fwrite(STDERR, "Rollback stopped: backup does not contain all target flights.\n");
    exit(1);
}

echo "Backup verified: {$backupPath}\n";
echo "SHA256: {$actualHash}\n";
echo 'Flights to restore: ' . implode(', ', $flightIds) . PHP_EOL;
echo 'Warehouse movements snapshot rows: ' . count((array)($payload['warehouse_movements'] ?? [])) . PHP_EOL;
if (!$apply) {
    echo "DRY RUN complete. No data changed. Add --apply to restore route fields.\n";
    if (!$restoreMovements) {
        echo "Warehouse movements will remain unchanged unless --restore-movements is also specified.\n";
    }
    exit(0);
}

$currentMovementRows = [];
$movementColumns = [];
if ($restoreMovements && empty($payload['warehouse_movements_table_existed'])) {
    fwrite(STDERR, "Rollback stopped: the original backup has no warehouse_movements table snapshot.\n");
    exit(1);
}
if (!empty($payload['warehouse_movements_table_existed'])) {
    $movementColumns = mixedRollbackTableColumns($pdo, 'warehouse_movements');
    if (!$movementColumns) {
        fwrite(STDERR, "Rollback stopped: warehouse_movements table is missing.\n");
        exit(1);
    }
    $movementStmt = $pdo->prepare("SELECT * FROM warehouse_movements WHERE flight_id IN ({$placeholders}) ORDER BY id");
    $movementStmt->execute($flightIds);
    $currentMovementRows = $movementStmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    [$safetyBackupPath, $safetyBackupHash] = mixedRollbackWriteSafetyBackup($backupPath, [
        'format_version' => 1,
        'created_at' => gmdate('c'),
        'purpose' => 'Safety backup before mixed route rollback',
        'database' => $currentDatabase,
        'flights' => $currentRows,
        'warehouse_movements' => $currentMovementRows,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Rollback stopped before changes: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
echo "Pre-rollback backup: {$safetyBackupPath}\n";
echo "Pre-rollback SHA256: {$safetyBackupHash}\n";

$pdo->beginTransaction();
try {
    $restoreFlight = $pdo->prepare(
        'UPDATE flights
         SET route_type = :route_type,
             unload_type = :unload_type,
             direct_zayavki_ids = :direct_zayavki_ids
         WHERE id = :id
         LIMIT 1'
    );
    foreach ($flightIds as $flightId) {
        $before = $beforeById[$flightId];
        $restoreFlight->execute([
            ':route_type' => $before['route_type'] ?? null,
            ':unload_type' => $before['unload_type'] ?? null,
            ':direct_zayavki_ids' => array_key_exists('direct_zayavki_ids', $before) ? $before['direct_zayavki_ids'] : null,
            ':id' => $flightId,
        ]);
    }

    if ($restoreMovements) {
        $deleteMovements = $pdo->prepare("DELETE FROM warehouse_movements WHERE flight_id IN ({$placeholders})");
        $deleteMovements->execute($flightIds);
        foreach ((array)($payload['warehouse_movements'] ?? []) as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid warehouse movement row in backup.');
            }
            $restoreRow = array_intersect_key($row, $movementColumns);
            foreach (array_keys($restoreRow) as $column) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                    throw new RuntimeException('Invalid warehouse movement column in backup.');
                }
            }
            if (!$restoreRow) {
                continue;
            }
            $columns = array_keys($restoreRow);
            $quotedColumns = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
            $valuePlaceholders = array_map(static fn(string $column): string => ':' . $column, $columns);
            $insert = $pdo->prepare(
                'INSERT INTO warehouse_movements (' . implode(',', $quotedColumns) . ')
                 VALUES (' . implode(',', $valuePlaceholders) . ')'
            );
            $params = [];
            foreach ($restoreRow as $column => $value) {
                $params[':' . $column] = $value;
            }
            $insert->execute($params);
        }
    }

    $verifyStmt = $pdo->prepare("SELECT id, route_type, unload_type, direct_zayavki_ids FROM flights WHERE id IN ({$placeholders}) ORDER BY id");
    $verifyStmt->execute($flightIds);
    $verifiedRows = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($verifiedRows as $row) {
        $flightId = (int)$row['id'];
        $before = $beforeById[$flightId];
        $expectedDirect = array_key_exists('direct_zayavki_ids', $before) ? (string)$before['direct_zayavki_ids'] : '';
        if ((string)($row['route_type'] ?? '') !== (string)($before['route_type'] ?? '')
            || (string)($row['unload_type'] ?? '') !== (string)($before['unload_type'] ?? '')
            || (string)($row['direct_zayavki_ids'] ?? '') !== $expectedDirect) {
            throw new RuntimeException("Post-rollback verification failed for flight #{$flightId}.");
        }
    }
    if (count($verifiedRows) !== count($flightIds)) {
        throw new RuntimeException('Post-rollback verification returned an unexpected number of flights.');
    }
    if ($restoreMovements) {
        $movementVerifyStmt = $pdo->prepare("SELECT * FROM warehouse_movements WHERE flight_id IN ({$placeholders}) ORDER BY id");
        $movementVerifyStmt->execute($flightIds);
        $verifiedMovements = $movementVerifyStmt->fetchAll(PDO::FETCH_ASSOC);
        $expectedMovements = array_values((array)($payload['warehouse_movements'] ?? []));
        if (count($verifiedMovements) !== count($expectedMovements)) {
            throw new RuntimeException('Post-rollback warehouse movement count mismatch.');
        }
        foreach ($expectedMovements as $index => $expectedMovement) {
            foreach (array_intersect_key((array)$expectedMovement, $movementColumns) as $column => $expectedValue) {
                if ((string)($verifiedMovements[$index][$column] ?? '') !== (string)$expectedValue) {
                    throw new RuntimeException(
                        'Post-rollback warehouse movement verification failed at row '
                        . $index . ', column ' . $column . '.'
                    );
                }
            }
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Rollback failed: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Pre-rollback backup: {$safetyBackupPath}\n");
    exit(1);
}

echo "Rollback applied and verified.\n";
echo $restoreMovements
    ? "Warehouse movements restored from the original snapshot.\n"
    : "Warehouse movements were not changed.\n";
echo "The direct_zayavki_ids column was intentionally kept for safe forward compatibility.\n";
