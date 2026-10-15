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

$arguments = array_slice($argv, 1);
$apply = in_array('--apply', $arguments, true);
if (array_values(array_diff($arguments, ['--apply']))) {
    fwrite(STDERR, "Usage: php prepare_mixed_route_schema.php [--apply]\n");
    exit(1);
}

$columnStmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'direct_zayavki_ids'");
$columnBefore = $columnStmt ? ($columnStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
if (is_array($columnBefore)) {
    echo "PASS: flights.direct_zayavki_ids already exists. No changes required.\n";
    exit(0);
}

echo "Checked: flights.direct_zayavki_ids is absent.\n";
if (!$apply) {
    echo "DRY RUN complete. No data changed. Run again with --apply to add the nullable column.\n";
    exit(0);
}

$schemaStmt = $pdo->query('SHOW CREATE TABLE flights');
$schemaRow = $schemaStmt ? ($schemaStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
if (!is_array($schemaRow)) {
    fwrite(STDERR, "Cannot read flights schema. No changes made.\n");
    exit(1);
}

$userHomeDirectory = getenv('HOME');
if (!is_string($userHomeDirectory) || $userHomeDirectory === '') {
    $userHomeDirectory = getenv('USERPROFILE');
}
if (!is_string($userHomeDirectory) || $userHomeDirectory === '') {
    fwrite(STDERR, "User home directory is unavailable. No changes made.\n");
    exit(1);
}

$backupDirectory = rtrim($userHomeDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fregat-feo-backups';
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    fwrite(STDERR, "Cannot create backup directory. No changes made.\n");
    exit(1);
}
@chmod($backupDirectory, 0700);

$payload = [
    'format_version' => 1,
    'created_at' => gmdate('c'),
    'purpose' => 'Before adding flights.direct_zayavki_ids',
    'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
    'flights_schema' => $schemaRow,
    'direct_zayavki_column_before' => null,
];
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "Cannot encode schema backup. No changes made.\n");
    exit(1);
}

$backupPath = $backupDirectory . DIRECTORY_SEPARATOR . 'mixed_route_schema_before_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
$temporaryPath = $backupPath . '.tmp';
if (file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Cannot write schema backup. No changes made.\n");
    exit(1);
}
@chmod($temporaryPath, 0600);
if (!rename($temporaryPath, $backupPath)) {
    @unlink($temporaryPath);
    fwrite(STDERR, "Cannot finalize schema backup. No changes made.\n");
    exit(1);
}
@chmod($backupPath, 0600);
$backupHash = hash_file('sha256', $backupPath);
if (!is_string($backupHash)
    || file_put_contents($backupPath . '.sha256', $backupHash . '  ' . basename($backupPath) . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Cannot write schema backup checksum. No changes made.\n");
    exit(1);
}
@chmod($backupPath . '.sha256', 0600);

echo "Backup: {$backupPath}\n";
echo "SHA256: {$backupHash}\n";

$pdo->exec("ALTER TABLE flights ADD COLUMN direct_zayavki_ids TEXT NULL DEFAULT NULL AFTER zayavki_ids");
$verifyStmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'direct_zayavki_ids'");
$columnAfter = $verifyStmt ? ($verifyStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
if (!is_array($columnAfter) || strtolower((string)($columnAfter['Type'] ?? '')) !== 'text') {
    fwrite(STDERR, "FAIL: column verification failed. Backup: {$backupPath}\n");
    exit(1);
}

echo 'PASS: ' . json_encode($columnAfter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
