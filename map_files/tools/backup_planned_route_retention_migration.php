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

function retentionBackupTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    return $stmt && $stmt->execute([$table]) && (bool)$stmt->fetchColumn();
}

$homeDirectory = getenv('HOME');
if (!is_string($homeDirectory) || $homeDirectory === '') {
    fwrite(STDERR, "HOME is not available.\n");
    exit(1);
}

$backupDirectory = rtrim($homeDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fregat-feo-backups';
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    fwrite(STDERR, "Cannot create backup directory.\n");
    exit(1);
}
chmod($backupDirectory, 0700);

$plannedStmt = $pdo->query("SELECT id, block_date FROM flights WHERE status = 'planned_route' ORDER BY id");
$plannedRows = $plannedStmt ? $plannedStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$retentionSchema = null;
$retentionRows = [];
if (retentionBackupTableExists($pdo, 'planned_route_retention')) {
    $schemaStmt = $pdo->query('SHOW CREATE TABLE planned_route_retention');
    $retentionSchema = $schemaStmt ? ($schemaStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    $rowsStmt = $pdo->query('SELECT flight_id, created_at, expires_at, warning_sent_at, extended_count, updated_at FROM planned_route_retention ORDER BY flight_id');
    $retentionRows = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$eventRows = [];
foreach (['max_event_templates', 'max_message_templates'] as $table) {
    if (!retentionBackupTableExists($pdo, $table)) {
        continue;
    }
    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE event_key = :event_key");
    $stmt->execute([':event_key' => 'route_auto_delete_warning']);
    $eventRows[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$backup = [
    'created_at' => gmdate('c'),
    'purpose' => 'Before planned route retention migration',
    'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
    'planned_flights' => $plannedRows,
    'retention_schema' => $retentionSchema,
    'retention_rows' => $retentionRows,
    'max_template_rows' => $eventRows,
];

$json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "Cannot encode backup.\n");
    exit(1);
}

$filename = 'planned_route_retention_before_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
$path = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
$temporaryPath = $path . '.tmp';
if (file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Cannot write backup.\n");
    exit(1);
}
chmod($temporaryPath, 0600);
if (!rename($temporaryPath, $path)) {
    @unlink($temporaryPath);
    fwrite(STDERR, "Cannot finalize backup.\n");
    exit(1);
}
chmod($path, 0600);

echo 'Backup path: ' . $path . PHP_EOL;
echo 'SHA256: ' . hash_file('sha256', $path) . PHP_EOL;
echo 'Planned flights: ' . count($plannedRows) . PHP_EOL;
echo 'Existing retention rows: ' . count($retentionRows) . PHP_EOL;
