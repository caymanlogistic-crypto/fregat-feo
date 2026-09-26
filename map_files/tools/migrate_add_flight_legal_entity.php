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

$stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'legal_entity_code'");
if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "flights.legal_entity_code already exists.\n";
} else {
    $pdo->exec("ALTER TABLE flights ADD COLUMN legal_entity_code VARCHAR(32) NULL DEFAULT NULL AFTER route_type");
    echo "Added flights.legal_entity_code.\n";
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    return $stmt && $stmt->execute([$table]) && (bool)$stmt->fetchColumn();
}

function tableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $columns = [];
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $columns[(string)($row['Field'] ?? '')] = true;
    }
    return $columns;
}

function addLegalEntityLine(string $template): string
{
    if ($template === '' || str_contains($template, '{legal_entity_line}')) {
        return $template;
    }
    if (str_contains($template, '{route_type_line}')) {
        return str_replace('{route_type_line}', "{route_type_line}\n{legal_entity_line}", $template);
    }
    if (str_contains($template, 'Рейс закреплен:')) {
        return str_replace('Рейс закреплен:', "{legal_entity_line}\nРейс закреплен:", $template);
    }
    if (str_contains($template, '{driver}')) {
        return str_replace('{driver}', "{legal_entity_line}\n{driver}", $template);
    }
    return rtrim($template) . "\n{legal_entity_line}";
}

function appendPlaceholders(string $current, array $required): string
{
    $parts = array_values(array_filter(array_map('trim', explode(',', $current))));
    foreach ($required as $placeholder) {
        if (!in_array($placeholder, $parts, true)) {
            $parts[] = $placeholder;
        }
    }
    return implode(', ', $parts);
}

if (!tableExists($pdo, 'max_event_templates')) {
    echo "max_event_templates does not exist; skipped MAX template migration.\n";
} else {
    $columns = tableColumns($pdo, 'max_event_templates');
$lifecycleEvents = [
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
];
$diffEvents = ['route_found_updated', 'route_diff_found', 'route_started_updated', 'route_diff_started'];
$allEvents = array_merge($lifecycleEvents, $diffEvents);
$selectFields = ['event_key'];
foreach (['template_text', 'default_template_text', 'placeholders'] as $field) {
    if (isset($columns[$field])) $selectFields[] = $field;
}
$quotedEvents = implode(',', array_fill(0, count($allEvents), '?'));
$select = $pdo->prepare('SELECT ' . implode(', ', $selectFields) . " FROM max_event_templates WHERE event_key IN ({$quotedEvents})");
$select->execute($allEvents);
$rows = $select->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;

foreach ($rows as $row) {
    $eventKey = (string)($row['event_key'] ?? '');
    $set = [];
    $params = [':event_key' => $eventKey];
    if (in_array($eventKey, $lifecycleEvents, true)) {
        foreach (['template_text', 'default_template_text'] as $field) {
            if (!isset($columns[$field])) continue;
            $value = addLegalEntityLine((string)($row[$field] ?? ''));
            if ($value !== (string)($row[$field] ?? '')) {
                $set[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = $value;
            }
        }
    }
    if (isset($columns['placeholders'])) {
        $required = ['{legal_entity}', '{legal_entity_code}', '{legal_entity_line}'];
        if (in_array($eventKey, $diffEvents, true)) {
            $required[] = '{legal_entity_old}';
            $required[] = '{legal_entity_new}';
        }
        $value = appendPlaceholders((string)($row['placeholders'] ?? ''), $required);
        if ($value !== (string)($row['placeholders'] ?? '')) {
            $set[] = '`placeholders` = :placeholders';
            $params[':placeholders'] = $value;
        }
    }
    if ($set) {
        $update = $pdo->prepare('UPDATE max_event_templates SET ' . implode(', ', $set) . ' WHERE event_key = :event_key');
        $update->execute($params);
        $updated++;
    }
}

    echo "Updated MAX templates: {$updated}.\n";
}

if (tableExists($pdo, 'max_message_templates')) {
    $legacyColumns = tableColumns($pdo, 'max_message_templates');
    if (isset($legacyColumns['event_key'], $legacyColumns['template_text'])) {
        $legacySelect = $pdo->prepare('SELECT event_key, template_text FROM max_message_templates WHERE event_key IN (' . $quotedEvents . ')');
        $legacySelect->execute($allEvents);
        $legacyUpdated = 0;
        foreach ($legacySelect->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $eventKey = (string)($row['event_key'] ?? '');
            if (!in_array($eventKey, $lifecycleEvents, true)) continue;
            $before = (string)($row['template_text'] ?? '');
            $after = addLegalEntityLine($before);
            if ($after === $before) continue;
            $update = $pdo->prepare('UPDATE max_message_templates SET template_text = :template_text WHERE event_key = :event_key');
            $update->execute([':template_text' => $after, ':event_key' => $eventKey]);
            $legacyUpdated++;
        }
        echo "Updated legacy MAX templates: {$legacyUpdated}.\n";
    }
}
