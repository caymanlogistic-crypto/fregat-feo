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

function mixedMigrationTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    return $stmt && $stmt->execute([$table]) && (bool)$stmt->fetchColumn();
}

function mixedMigrationWriteBackup(string $directory, array $payload): array
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create backup directory.');
    }
    @chmod($directory, 0700);

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Cannot encode backup.');
    }

    $suffix = bin2hex(random_bytes(4));
    $path = $directory . DIRECTORY_SEPARATOR . 'mixed_route_before_' . gmdate('Ymd_His') . '_' . $suffix . '.json';
    $temporaryPath = $path . '.tmp';
    if (file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write backup.');
    }
    @chmod($temporaryPath, 0600);
    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Cannot finalize backup.');
    }
    @chmod($path, 0600);

    $hash = hash_file('sha256', $path);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Cannot calculate backup checksum.');
    }
    $checksumPath = $path . '.sha256';
    if (file_put_contents($checksumPath, $hash . '  ' . basename($path) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write backup checksum.');
    }
    @chmod($checksumPath, 0600);

    return [$path, $hash];
}

$arguments = array_slice($argv, 1);
$apply = in_array('--apply', $arguments, true);
$unknownArguments = array_values(array_diff($arguments, ['--apply']));
if ($unknownArguments) {
    fwrite(STDERR, 'Unknown arguments: ' . implode(', ', $unknownArguments) . PHP_EOL);
    exit(1);
}

// Из старых завершённых рейсов в новый формат переводится только #269.
// #351 и #353 — текущие рейсы, ради которых добавлена смешанная схема.
$targets = [
    269 => [306474, 309026, 309808, 310330, 310865, 310875, 310884, 312762, 312821, 313029, 315058],
    351 => [291382],
    353 => [312015],
];

$columnStmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'direct_zayavki_ids'");
$columnExists = $columnStmt && $columnStmt->fetch(PDO::FETCH_ASSOC);

$flightIds = array_keys($targets);
$placeholders = implode(',', array_fill(0, count($flightIds), '?'));
$backupStmt = $pdo->prepare("SELECT * FROM flights WHERE id IN ({$placeholders}) ORDER BY id");
$backupStmt->execute($flightIds);
$beforeRows = $backupStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($beforeRows) !== count($targets)) {
    $found = array_map(static fn(array $row): int => (int)$row['id'], $beforeRows);
    $missing = array_values(array_diff($flightIds, $found));
    fwrite(STDERR, 'Migration stopped: flights not found: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

foreach ($beforeRows as $row) {
    $flightId = (int)$row['id'];
    $allIds = array_values(array_filter(array_map('intval', explode(',', (string)($row['zayavki_ids'] ?? '')))));
    $outside = array_values(array_diff($targets[$flightId], $allIds));
    if ($outside) {
        fwrite(STDERR, "Migration stopped: flight #{$flightId} does not contain requests: " . implode(', ', $outside) . PHP_EOL);
        exit(1);
    }
    if ((int)($row['source_warehouse_id'] ?? 0) <= 0) {
        fwrite(STDERR, "Migration stopped: flight #{$flightId} has no source warehouse.\n");
        exit(1);
    }
    echo "Checked flight #{$flightId}: direct requests " . implode(',', $targets[$flightId]) . ".\n";
}

if (!$apply) {
    echo "DRY RUN complete. No data changed. Run again with --apply to create a backup and apply the migration.\n";
    exit(0);
}

$warehouseMovementsTableExists = mixedMigrationTableExists($pdo, 'warehouse_movements');
$warehouseMovementRows = [];
if ($warehouseMovementsTableExists) {
    $movementStmt = $pdo->prepare("SELECT * FROM warehouse_movements WHERE flight_id IN ({$placeholders}) ORDER BY id");
    $movementStmt->execute($flightIds);
    $warehouseMovementRows = $movementStmt->fetchAll(PDO::FETCH_ASSOC);
}

$schemaStmt = $pdo->query('SHOW CREATE TABLE flights');
$schemaRow = $schemaStmt ? ($schemaStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
$userHomeDirectory = getenv('HOME');
if (!is_string($userHomeDirectory) || $userHomeDirectory === '') {
    $userHomeDirectory = getenv('USERPROFILE');
}
if (!is_string($userHomeDirectory) || $userHomeDirectory === '') {
    fwrite(STDERR, "Migration stopped: user home directory is unavailable.\n");
    exit(1);
}

$backupPayload = [
    'format_version' => 1,
    'created_at' => gmdate('c'),
    'purpose' => 'Before mixed route migration for flights 269, 351 and 353',
    'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
    'direct_zayavki_column_existed_before' => (bool)$columnExists,
    'flights_schema' => $schemaRow,
    'targets' => $targets,
    'flights' => $beforeRows,
    'warehouse_movements_table_existed' => $warehouseMovementsTableExists,
    'warehouse_movements' => $warehouseMovementRows,
];

try {
    [$backupPath, $backupHash] = mixedMigrationWriteBackup(
        rtrim($userHomeDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fregat-feo-backups',
        $backupPayload
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration stopped before changes: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
echo "Backup: {$backupPath}\n";
echo "SHA256: {$backupHash}\n";
echo 'Backed up warehouse movements: ' . count($warehouseMovementRows) . PHP_EOL;

if (!$columnExists) {
    $pdo->exec("ALTER TABLE flights ADD COLUMN direct_zayavki_ids TEXT NULL DEFAULT NULL AFTER zayavki_ids");
    echo "Added flights.direct_zayavki_ids.\n";
}

$pdo->beginTransaction();
try {
    $update = $pdo->prepare(
        "UPDATE flights
         SET route_type = 'mixed_to_utilizer',
             unload_type = 'OO',
             direct_zayavki_ids = :direct_ids
         WHERE id = :id
         LIMIT 1"
    );
    foreach ($targets as $flightId => $directIds) {
        $update->execute([
            ':direct_ids' => implode(',', $directIds),
            ':id' => $flightId,
        ]);
        if ($update->rowCount() > 1) {
            throw new RuntimeException("Unexpected update count for flight #{$flightId}.");
        }
    }

    $verify = $pdo->prepare(
        "SELECT id, status, route_type, unload_type, source_warehouse_id, zayavki_ids, direct_zayavki_ids
         FROM flights
         WHERE id IN ({$placeholders})
         ORDER BY id"
    );
    $verify->execute($flightIds);
    $afterRows = $verify->fetchAll(PDO::FETCH_ASSOC);
    foreach ($afterRows as $row) {
        $flightId = (int)$row['id'];
        if (($row['route_type'] ?? '') !== 'mixed_to_utilizer'
            || ($row['unload_type'] ?? '') !== 'OO'
            || (string)($row['direct_zayavki_ids'] ?? '') !== implode(',', $targets[$flightId])) {
            throw new RuntimeException("Verification failed for flight #{$flightId}.");
        }
    }
    if (count($afterRows) !== count($targets)) {
        throw new RuntimeException('Verification returned an unexpected number of flights.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Backup for recovery: {$backupPath}\n");
    exit(1);
}

echo 'Migration applied and verified: ' . json_encode($afterRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
