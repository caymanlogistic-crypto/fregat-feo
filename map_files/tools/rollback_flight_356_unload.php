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

const INCIDENT_FLIGHT_ID = 356;
const DEPENDENT_FLIGHT_ID = 353;

function incidentFetchFlight(PDO $pdo, int $flightId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM flights WHERE id = :id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $flightId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function incidentFetchMovements(PDO $pdo, array $flightIds, bool $forUpdate = false): array
{
    $placeholders = implode(',', array_fill(0, count($flightIds), '?'));
    $sql = "SELECT * FROM warehouse_movements WHERE flight_id IN ({$placeholders}) ORDER BY id";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($flightIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function incidentIds(string $raw): array
{
    $ids = [];
    foreach (explode(',', $raw) as $value) {
        $id = (int)trim($value);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    sort($ids, SORT_NUMERIC);
    return array_values($ids);
}

function incidentAssertCurrentState(array $flights, array $movements): array
{
    $flight356 = $flights[INCIDENT_FLIGHT_ID] ?? null;
    $flight353 = $flights[DEPENDENT_FLIGHT_ID] ?? null;
    if (!is_array($flight356) || !is_array($flight353)) {
        throw new RuntimeException('Flights #356 and #353 must both exist.');
    }

    $expected356Requests = [321391, 321828, 322245];
    $expected353Requests = [310867, 312015];
    $expected353Direct = [312015];

    if ((string)($flight356['status'] ?? '') !== 'completed'
        || (string)($flight356['route_type'] ?? '') !== 'generator_to_warehouse'
        || (int)($flight356['destination_warehouse_id'] ?? 0) !== 10
        || incidentIds((string)($flight356['zayavki_ids'] ?? '')) !== $expected356Requests
        || trim((string)($flight356['actual_end_date'] ?? '')) === ''
    ) {
        throw new RuntimeException('Flight #356 no longer matches the reviewed completed warehouse-arrival state.');
    }

    if ((string)($flight353['status'] ?? '') !== 'started'
        || (string)($flight353['route_type'] ?? '') !== 'mixed_to_utilizer'
        || (int)($flight353['source_warehouse_id'] ?? 0) !== 10
        || incidentIds((string)($flight353['zayavki_ids'] ?? '')) !== $expected353Requests
        || incidentIds((string)($flight353['direct_zayavki_ids'] ?? '')) !== $expected353Direct
    ) {
        throw new RuntimeException('Flight #353 no longer matches the reviewed current composition.');
    }

    $deleteIds = [];
    $keptIds = [];
    foreach ($movements as $row) {
        $flightId = (int)($row['flight_id'] ?? 0);
        $movementId = (int)($row['id'] ?? 0);
        $requestId = (int)($row['zayavka_id'] ?? 0);
        $type = (string)($row['movement_type'] ?? '');
        $status = (string)($row['status'] ?? '');
        $warehouseId = (int)($row['warehouse_id'] ?? 0);

        if ($flightId === INCIDENT_FLIGHT_ID
            && $type === 'receipt'
            && $status === 'active'
            && $warehouseId === 10
            && in_array($requestId, $expected356Requests, true)
        ) {
            $deleteIds[$movementId] = $movementId;
            continue;
        }

        if ($flightId === DEPENDENT_FLIGHT_ID
            && $type === 'issue'
            && $status === 'active'
            && $warehouseId === 10
            && in_array($requestId, $expected356Requests, true)
        ) {
            $deleteIds[$movementId] = $movementId;
            continue;
        }

        if ($flightId === DEPENDENT_FLIGHT_ID
            && $type === 'issue'
            && $status === 'active'
            && $warehouseId === 10
            && $requestId === 310867
        ) {
            $keptIds[$movementId] = $movementId;
            continue;
        }

        throw new RuntimeException("Unexpected warehouse movement #{$movementId} for flights #356/#353.");
    }

    sort($deleteIds, SORT_NUMERIC);
    sort($keptIds, SORT_NUMERIC);
    if (count($deleteIds) !== 6) {
        throw new RuntimeException('Expected exactly six erroneous movements to remove, found ' . count($deleteIds) . '.');
    }
    if (count($keptIds) !== 1) {
        throw new RuntimeException('Expected exactly one valid #353 movement to keep, found ' . count($keptIds) . '.');
    }

    return [array_values($deleteIds), array_values($keptIds)];
}

function incidentWriteBackup(string $directory, array $payload): array
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create backup directory.');
    }
    @chmod($directory, 0700);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Cannot encode backup.');
    }
    $path = $directory . DIRECTORY_SEPARATOR . 'flight_356_before_unload_rollback_'
        . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
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

function incidentLoadBackup(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Backup file not found.');
    }
    $checksumPath = $path . '.sha256';
    if (!is_file($checksumPath)) {
        throw new RuntimeException('Backup checksum file not found.');
    }
    $expectedHash = strtok(trim((string)file_get_contents($checksumPath)), " \t");
    $actualHash = hash_file('sha256', $path);
    if (!is_string($expectedHash) || !is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
        throw new RuntimeException('Backup checksum mismatch.');
    }
    $payload = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)
        || ($payload['purpose'] ?? '') !== 'Before rollback of erroneous unload for flight #356'
        || (int)($payload['format_version'] ?? 0) !== 1
    ) {
        throw new RuntimeException('Unsupported backup payload.');
    }
    return $payload;
}

function incidentQuoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

$arguments = array_slice($argv, 1);
$apply = in_array('--apply', $arguments, true);
$restorePath = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--restore=')) {
        $restorePath = substr($argument, strlen('--restore='));
        continue;
    }
    if ($argument !== '--apply') {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(1);
    }
}

try {
    if ($restorePath !== null) {
        $payload = incidentLoadBackup($restorePath);
        $current356 = incidentFetchFlight($pdo, INCIDENT_FLIGHT_ID);
        $current353 = incidentFetchFlight($pdo, DEPENDENT_FLIGHT_ID);
        $currentMovements = incidentFetchMovements($pdo, [DEPENDENT_FLIGHT_ID, INCIDENT_FLIGHT_ID]);
        if (!is_array($current356) || !is_array($current353)
            || (string)($current356['status'] ?? '') !== 'started'
            || trim((string)($current356['actual_end_date'] ?? '')) !== ''
            || count($currentMovements) !== 1
            || (int)($currentMovements[0]['flight_id'] ?? 0) !== DEPENDENT_FLIGHT_ID
            || (int)($currentMovements[0]['zayavka_id'] ?? 0) !== 310867
        ) {
            throw new RuntimeException('Current data no longer matches the immediate post-rollback state; automatic restore refused.');
        }
        echo "RESTORE CHECK: backup and current state are valid.\n";
        if (!$apply) {
            echo "DRY RUN complete. No data changed. Add --apply to restore the backup.\n";
            exit(0);
        }

        $backupFlights = [];
        foreach ((array)($payload['flights'] ?? []) as $row) {
            $backupFlights[(int)($row['id'] ?? 0)] = $row;
        }
        if (!isset($backupFlights[INCIDENT_FLIGHT_ID])) {
            throw new RuntimeException('Backup does not contain flight #356.');
        }

        $pdo->beginTransaction();
        try {
            incidentFetchFlight($pdo, INCIDENT_FLIGHT_ID, true);
            incidentFetchFlight($pdo, DEPENDENT_FLIGHT_ID, true);
            incidentFetchMovements($pdo, [DEPENDENT_FLIGHT_ID, INCIDENT_FLIGHT_ID], true);
            $delete = $pdo->prepare('DELETE FROM warehouse_movements WHERE flight_id IN (?, ?)');
            $delete->execute([DEPENDENT_FLIGHT_ID, INCIDENT_FLIGHT_ID]);

            foreach ((array)($payload['warehouse_movements'] ?? []) as $row) {
                $columns = array_keys($row);
                $quoted = array_map('incidentQuoteIdentifier', $columns);
                $placeholders = array_fill(0, count($columns), '?');
                $insert = $pdo->prepare(
                    'INSERT INTO warehouse_movements (' . implode(',', $quoted) . ') VALUES (' . implode(',', $placeholders) . ')'
                );
                $insert->execute(array_values($row));
            }

            $flightBefore = $backupFlights[INCIDENT_FLIGHT_ID];
            $update = $pdo->prepare(
                'UPDATE flights SET status = :status, actual_start_date = :actual_start, actual_end_date = :actual_end WHERE id = :id LIMIT 1'
            );
            $update->execute([
                ':status' => $flightBefore['status'],
                ':actual_start' => $flightBefore['actual_start_date'],
                ':actual_end' => $flightBefore['actual_end_date'],
                ':id' => INCIDENT_FLIGHT_ID,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        echo "RESTORE APPLIED: flight #356 and movements for #356/#353 restored from backup.\n";
        exit(0);
    }

    $flights = [];
    foreach ([INCIDENT_FLIGHT_ID, DEPENDENT_FLIGHT_ID] as $flightId) {
        $flight = incidentFetchFlight($pdo, $flightId);
        if (is_array($flight)) {
            $flights[$flightId] = $flight;
        }
    }
    $movements = incidentFetchMovements($pdo, [DEPENDENT_FLIGHT_ID, INCIDENT_FLIGHT_ID]);
    [$deleteIds, $keptIds] = incidentAssertCurrentState($flights, $movements);
    echo 'CHECK: remove movements ' . implode(',', $deleteIds) . '; keep movements ' . implode(',', $keptIds) . ".\n";
    echo "CHECK: flight #356 will become started and actual_end_date will be cleared.\n";
    if (!$apply) {
        echo "DRY RUN complete. No data changed. Run again with --apply to create a backup and apply the correction.\n";
        exit(0);
    }

    $userHomeDirectory = getenv('HOME');
    if (!is_string($userHomeDirectory) || $userHomeDirectory === '') {
        $userHomeDirectory = getenv('USERPROFILE');
    }
    if (!is_string($userHomeDirectory) || $userHomeDirectory === '') {
        throw new RuntimeException('User home directory is unavailable.');
    }
    [$backupPath, $backupHash] = incidentWriteBackup(
        rtrim($userHomeDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fregat-feo-backups',
        [
            'format_version' => 1,
            'created_at' => gmdate('c'),
            'purpose' => 'Before rollback of erroneous unload for flight #356',
            'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
            'flights' => array_values($flights),
            'warehouse_movements' => $movements,
            'delete_movement_ids' => $deleteIds,
            'keep_movement_ids' => $keptIds,
        ]
    );
    echo "Backup: {$backupPath}\n";
    echo "SHA256: {$backupHash}\n";

    $pdo->beginTransaction();
    try {
        $lockedFlights = [];
        foreach ([INCIDENT_FLIGHT_ID, DEPENDENT_FLIGHT_ID] as $flightId) {
            $flight = incidentFetchFlight($pdo, $flightId, true);
            if (is_array($flight)) {
                $lockedFlights[$flightId] = $flight;
            }
        }
        $lockedMovements = incidentFetchMovements($pdo, [DEPENDENT_FLIGHT_ID, INCIDENT_FLIGHT_ID], true);
        [$lockedDeleteIds] = incidentAssertCurrentState($lockedFlights, $lockedMovements);
        if ($lockedDeleteIds !== $deleteIds) {
            throw new RuntimeException('Movement set changed after backup; correction refused.');
        }

        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $delete = $pdo->prepare("DELETE FROM warehouse_movements WHERE id IN ({$placeholders})");
        $delete->execute($deleteIds);
        if ($delete->rowCount() !== count($deleteIds)) {
            throw new RuntimeException('Unexpected number of deleted warehouse movements.');
        }

        $update = $pdo->prepare(
            "UPDATE flights SET status = 'started', actual_end_date = NULL WHERE id = :id AND status = 'completed' LIMIT 1"
        );
        $update->execute([':id' => INCIDENT_FLIGHT_ID]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Flight #356 status update did not affect exactly one row.');
        }

        $afterFlight = incidentFetchFlight($pdo, INCIDENT_FLIGHT_ID);
        $afterMovements = incidentFetchMovements($pdo, [DEPENDENT_FLIGHT_ID, INCIDENT_FLIGHT_ID]);
        if (!is_array($afterFlight)
            || (string)($afterFlight['status'] ?? '') !== 'started'
            || trim((string)($afterFlight['actual_end_date'] ?? '')) !== ''
            || count($afterMovements) !== 1
            || (int)($afterMovements[0]['flight_id'] ?? 0) !== DEPENDENT_FLIGHT_ID
            || (int)($afterMovements[0]['zayavka_id'] ?? 0) !== 310867
        ) {
            throw new RuntimeException('Post-change verification failed.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    echo "APPLIED: flight #356 is started; erroneous receipts and orphan #353 issues were removed.\n";
    echo "Restore dry-run: /usr/bin/php8.4 map_files/tools/rollback_flight_356_unload.php --restore={$backupPath}\n";
    echo "Restore apply: /usr/bin/php8.4 map_files/tools/rollback_flight_356_unload.php --restore={$backupPath} --apply\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'STOPPED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
