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

$table = $pdo->query("SHOW TABLES LIKE 'overdue_blocks'");
if (!$table || !$table->fetchColumn()) {
    fwrite(STDERR, "overdue_blocks does not exist.\n");
    exit(1);
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

$schemaStmt = $pdo->query('SHOW CREATE TABLE overdue_blocks');
$schema = $schemaStmt ? ($schemaStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
$rowsStmt = $pdo->query('SELECT * FROM overdue_blocks ORDER BY id ASC');
$rows = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$backup = [
    'created_at' => gmdate('c'),
    'purpose' => 'Before overdue_blocks.collect_by_date migration',
    'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
    'schema' => $schema,
    'rows' => $rows,
];

$json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "Cannot encode backup.\n");
    exit(1);
}

$filename = 'overdue_collect_date_before_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
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
echo 'Rows: ' . count($rows) . PHP_EOL;
