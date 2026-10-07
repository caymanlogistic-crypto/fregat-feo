<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/planned_route_retention.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection is not initialized.\n");
    exit(1);
}

function retentionMigrationTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    return $stmt && $stmt->execute([$table]) && (bool)$stmt->fetchColumn();
}

function retentionMigrationColumns(PDO $pdo, string $table): array
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

function retentionMigrationSeedTemplate(PDO $pdo, string $table, array $config): string
{
    if (!retentionMigrationTableExists($pdo, $table)) {
        return 'table-missing';
    }
    $columns = retentionMigrationColumns($pdo, $table);
    if (!isset($columns['event_key'])) {
        return 'event-key-missing';
    }
    $select = $pdo->prepare("SELECT * FROM `{$table}` WHERE event_key = :event_key LIMIT 1");
    $select->execute([':event_key' => $config['event_key']]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);

    $values = [
        'event_key' => $config['event_key'],
        'title' => $config['title'],
        'category' => $config['category'],
        'description' => $config['description'],
        'when_sent' => $config['description'],
        'template_text' => $config['template'],
        'default_template_text' => $config['template'],
        'placeholders' => $config['placeholders'],
        'enabled' => 1,
        'is_enabled' => 1,
    ];
    if (!is_array($existing)) {
        $set = [];
        $params = [];
        foreach ($values as $column => $value) {
            if (!isset($columns[$column])) {
                continue;
            }
            $set[] = "`{$column}` = :{$column}";
            $params[":{$column}"] = $value;
        }
        if ($set) {
            $insert = $pdo->prepare("INSERT INTO `{$table}` SET " . implode(', ', $set));
            $insert->execute($params);
        }
        return 'created';
    }

    $set = [];
    $params = [':event_key' => $config['event_key']];
    foreach (['title', 'category', 'description', 'when_sent', 'placeholders'] as $column) {
        if (isset($columns[$column])) {
            $set[] = "`{$column}` = :{$column}";
            $params[":{$column}"] = $values[$column];
        }
    }
    if (isset($columns['default_template_text'])) {
        $set[] = '`default_template_text` = :default_template_text';
        $params[':default_template_text'] = $config['template'];
    }
    $templateCurrent = trim((string)($existing['template_text'] ?? ''));
    $defaultCurrent = trim((string)($existing['default_template_text'] ?? ''));
    if (isset($columns['template_text']) && ($templateCurrent === '' || ($defaultCurrent !== '' && $templateCurrent === $defaultCurrent))) {
        $set[] = '`template_text` = :template_text';
        $params[':template_text'] = $config['template'];
    }
    if ($set) {
        $update = $pdo->prepare("UPDATE `{$table}` SET " . implode(', ', $set) . ' WHERE event_key = :event_key');
        $update->execute($params);
    }
    return 'updated';
}

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS planned_route_retention (
    flight_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    warning_sent_at DATETIME NULL DEFAULT NULL,
    extended_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (flight_id),
    KEY idx_planned_route_retention_expires (expires_at),
    KEY idx_planned_route_retention_warning (warning_sent_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$now = plannedRouteRetentionDateTime();
$select = $pdo->query("SELECT f.id, f.block_date FROM flights f LEFT JOIN planned_route_retention prr ON prr.flight_id = f.id WHERE f.status = 'planned_route' AND prr.flight_id IS NULL ORDER BY f.id");
$rows = $select ? $select->fetchAll(PDO::FETCH_ASSOC) : [];
$inserted = 0;
$protected = 0;

$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $flightId = (int)($row['id'] ?? 0);
        if ($flightId <= 0) {
            continue;
        }
        $createdAt = plannedRouteRetentionCreationDate($row['block_date'] ?? '', $now);
        $originalExpiry = plannedRouteRetentionAddBusinessHours($createdAt, PLANNED_ROUTE_RETENTION_HOURS);
        if ($originalExpiry <= $now) {
            $protected++;
        }
        plannedRouteRetentionProtectAndSchedule($pdo, $flightId, $createdAt, $now);
        $inserted++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$template = [
    'event_key' => 'route_auto_delete_warning',
    'category' => 'routes',
    'title' => 'Предупреждение об автоматическом удалении',
    'description' => 'Однократно отправляется менее чем за 24 рабочих часа до автоматического удаления планируемого рейса',
    'placeholders' => '{route_id}, {route_title}, {requests}, {request_ids}, {delete_at}, {expires_at}, {manager}, {responsible}',
    'template' => "АВТ. УДАЛЕНИЕ\n#{route_id} {route_title}\nЗаявки: {requests}\nбудет удалён\n{delete_at}\nПри необходимости продлите хранение\nРейс закреплён: {manager}",
];

$eventCenterStatus = retentionMigrationSeedTemplate($pdo, 'max_event_templates', $template);
$legacyStatus = retentionMigrationSeedTemplate($pdo, 'max_message_templates', $template);

echo "planned_route_retention is ready.\n";
echo "Inserted retention rows: {$inserted}.\n";
echo "Protected expired planned routes (+24 business hours): {$protected}.\n";
echo "MAX Event Center template: {$eventCenterStatus}.\n";
echo "MAX legacy template: {$legacyStatus}.\n";
