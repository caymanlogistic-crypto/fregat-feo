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

function backupTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    return $stmt && $stmt->execute([$table]) && (bool)$stmt->fetchColumn();
}

function backupRowsByEventKey(PDO $pdo, string $table, array $eventKeys): array
{
    if (!backupTableExists($pdo, $table)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($eventKeys), '?'));
    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE event_key IN ({$placeholders}) ORDER BY event_key");
    $stmt->execute($eventKeys);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

$eventKeys = [
    'route_planned_to_found',
    'planned_to_found',
    'route_found_to_started',
    'found_to_started',
    'route_started_to_found_rollback',
    'started_to_found_rollback',
    'route_found_to_planned_rollback',
    'found_to_planned_rollback',
    'route_deleted',
    'route_completed',
    'route_found_updated',
    'route_diff_found',
    'route_started_updated',
    'route_diff_started',
];

$schemaStmt = $pdo->query('SHOW CREATE TABLE flights');
$schemaRow = $schemaStmt ? $schemaStmt->fetch(PDO::FETCH_ASSOC) : false;
if (!$schemaRow) {
    fwrite(STDERR, "Cannot read flights schema.\n");
    exit(1);
}

$flightCount = (int)$pdo->query('SELECT COUNT(*) FROM flights')->fetchColumn();
$columnStmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'legal_entity_code'");
$legalEntityColumnBefore = $columnStmt ? ($columnStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;

$backup = [
    'created_at' => gmdate('c'),
    'purpose' => 'Before flights.legal_entity_code and MAX template migration',
    'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
    'flights_schema' => $schemaRow,
    'flights_row_count' => $flightCount,
    'legal_entity_column_before' => $legalEntityColumnBefore,
    'max_event_templates' => backupRowsByEventKey($pdo, 'max_event_templates', $eventKeys),
    'max_message_templates' => backupRowsByEventKey($pdo, 'max_message_templates', $eventKeys),
];

$json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "Cannot encode backup.\n");
    exit(1);
}

$suffix = bin2hex(random_bytes(4));
$filename = 'legal_entity_before_' . gmdate('Ymd_His') . '_' . $suffix . '.json';
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
echo 'Flights rows: ' . $flightCount . PHP_EOL;
echo 'MAX event templates: ' . count($backup['max_event_templates']) . PHP_EOL;
echo 'MAX legacy templates: ' . count($backup['max_message_templates']) . PHP_EOL;
