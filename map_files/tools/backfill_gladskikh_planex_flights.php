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

const TARGET_MANAGER_SURNAME = 'Гладских';
const TARGET_MIN_FLIGHT_ID = 244;
const TARGET_LEGAL_ENTITY_CODE = 'planex';

function selectTargetFlights(PDO $pdo): array
{
    $stmt = $pdo->prepare(<<<'SQL'
SELECT
    f.id,
    f.assigned_manager_id,
    CONCAT_WS(' ', NULLIF(TRIM(u.`Фамилия`), ''), NULLIF(TRIM(u.`Имя`), '')) AS manager_name,
    f.legal_entity_code
FROM flights f
INNER JOIN users u ON u.id = f.assigned_manager_id
WHERE f.id >= :min_id
  AND TRIM(u.`Фамилия`) = :surname
ORDER BY f.id
SQL);
    $stmt->execute([
        ':min_id' => TARGET_MIN_FLIGHT_ID,
        ':surname' => TARGET_MANAGER_SURNAME,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function selectFullTargetFlights(PDO $pdo): array
{
    $stmt = $pdo->prepare(<<<'SQL'
SELECT f.*
FROM flights f
INNER JOIN users u ON u.id = f.assigned_manager_id
WHERE f.id >= :min_id
  AND TRIM(u.`Фамилия`) = :surname
ORDER BY f.id
SQL);
    $stmt->execute([
        ':min_id' => TARGET_MIN_FLIGHT_ID,
        ':surname' => TARGET_MANAGER_SURNAME,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function selectTargetManagers(PDO $pdo): array
{
    $stmt = $pdo->prepare(<<<'SQL'
SELECT DISTINCT u.id, u.`Фамилия`, u.`Имя`
FROM users u
INNER JOIN flights f ON f.assigned_manager_id = u.id
WHERE f.id >= :min_id
  AND TRIM(u.`Фамилия`) = :surname
ORDER BY u.id
SQL);
    $stmt->execute([
        ':min_id' => TARGET_MIN_FLIGHT_ID,
        ':surname' => TARGET_MANAGER_SURNAME,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function createBackup(PDO $pdo): array
{
    $homeDirectory = getenv('HOME');
    if (!is_string($homeDirectory) || $homeDirectory === '') {
        throw new RuntimeException('HOME is not available.');
    }

    $backupDirectory = rtrim($homeDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'fregat-feo-backups';
    if (!is_dir($backupDirectory)
        && !mkdir($backupDirectory, 0700, true)
        && !is_dir($backupDirectory)) {
        throw new RuntimeException('Cannot create backup directory.');
    }
    chmod($backupDirectory, 0700);

    $schemaStmt = $pdo->query('SHOW CREATE TABLE flights');
    $schema = $schemaStmt ? $schemaStmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$schema) {
        throw new RuntimeException('Cannot read flights schema.');
    }

    $backup = [
        'created_at' => gmdate('c'),
        'purpose' => 'Before assigning planex to Gladskikh flights with id >= 244',
        'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
        'selector' => [
            'manager_surname' => TARGET_MANAGER_SURNAME,
            'min_flight_id' => TARGET_MIN_FLIGHT_ID,
        ],
        'flights_schema' => $schema,
        'flights' => selectFullTargetFlights($pdo),
        'managers' => selectTargetManagers($pdo),
    ];

    $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Cannot encode backup.');
    }

    $filename = 'gladskikh_planex_before_' . gmdate('Ymd_His')
        . '_' . bin2hex(random_bytes(4)) . '.json';
    $path = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
    $temporaryPath = $path . '.tmp';

    if (file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write backup.');
    }
    chmod($temporaryPath, 0600);
    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Cannot finalize backup.');
    }
    chmod($path, 0600);

    return [
        'path' => $path,
        'sha256' => hash_file('sha256', $path),
        'flight_count' => count($backup['flights']),
        'manager_count' => count($backup['managers']),
    ];
}

function printSelectProof(string $label, array $rows): void
{
    echo $label . ': ' . json_encode(
        $rows,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
}

$apply = in_array('--apply', $argv, true);
$before = selectTargetFlights($pdo);
printSelectProof('SELECT before', $before);

if (!$apply) {
    echo "Dry run only. Re-run with --apply to create a backup and update rows.\n";
    exit(0);
}

try {
    $backup = createBackup($pdo);
    echo 'Backup path: ' . $backup['path'] . PHP_EOL;
    echo 'Backup SHA256: ' . $backup['sha256'] . PHP_EOL;
    echo 'Backup flights: ' . $backup['flight_count'] . PHP_EOL;
    echo 'Backup managers: ' . $backup['manager_count'] . PHP_EOL;

    $pdo->beginTransaction();
    $update = $pdo->prepare(<<<'SQL'
UPDATE flights f
INNER JOIN users u ON u.id = f.assigned_manager_id
SET f.legal_entity_code = :legal_entity_code
WHERE f.id >= :min_id
  AND TRIM(u.`Фамилия`) = :surname
  AND (f.legal_entity_code IS NULL OR f.legal_entity_code <> :legal_entity_code_check)
SQL);
    $update->execute([
        ':legal_entity_code' => TARGET_LEGAL_ENTITY_CODE,
        ':min_id' => TARGET_MIN_FLIGHT_ID,
        ':surname' => TARGET_MANAGER_SURNAME,
        ':legal_entity_code_check' => TARGET_LEGAL_ENTITY_CODE,
    ]);
    $updatedRows = $update->rowCount();

    $after = selectTargetFlights($pdo);
    foreach ($after as $row) {
        if (($row['legal_entity_code'] ?? null) !== TARGET_LEGAL_ENTITY_CODE) {
            throw new RuntimeException('Verification failed for flight #' . ($row['id'] ?? '?'));
        }
    }
    if (count($after) !== count($before)) {
        throw new RuntimeException('Target row count changed during update.');
    }

    $pdo->commit();
    echo 'Updated rows: ' . $updatedRows . PHP_EOL;
    printSelectProof('SELECT after', $after);
    echo "Verification PASS.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
