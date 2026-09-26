<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function outJson(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function quoteIdent(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function pickManagerDisplayName(array $row): string
{
    $lastName = trim((string)($row['Фамилия'] ?? ''));
    $firstName = trim((string)($row['Имя'] ?? ''));
    $fullRu = trim($lastName . ' ' . $firstName);
    if ($fullRu !== '') {
        return $fullRu;
    }

    foreach (['full_name', 'name'] as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    foreach (['username', 'login'] as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return 'Менеджер #' . (int)($row['id'] ?? 0);
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    $columnsStmt = $pdo->query('SHOW COLUMNS FROM users');
    $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $columnsMap = [];
    foreach ((array)$columns as $col) {
        $columnsMap[(string)$col] = true;
    }

    if (!isset($columnsMap['id'])) {
        throw new RuntimeException('Users table does not contain id column');
    }

    $roleColumn = null;
    foreach (['Роль', 'role', 'user_role', 'type'] as $candidate) {
        if (isset($columnsMap[$candidate])) {
            $roleColumn = $candidate;
            break;
        }
    }
    if ($roleColumn === null) {
        throw new RuntimeException('Users role column not found');
    }

    $selectColumns = ['id'];
    foreach (['Фамилия', 'Имя', 'full_name', 'name', 'username', 'login'] as $field) {
        if (isset($columnsMap[$field])) {
            $selectColumns[] = $field;
        }
    }
    $selectColumns[] = $roleColumn;

    $quotedSelect = array_map('quoteIdent', array_unique($selectColumns));
    $sql = "SELECT " . implode(', ', $quotedSelect) .
        " FROM users WHERE LOWER(TRIM(" . quoteIdent($roleColumn) . ")) = 'logist'";

    $stmt = $pdo->query($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to query managers');
    }

    $managers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }

        $id = isset($row['id']) ? (int)$row['id'] : 0;
        if ($id <= 0) {
            continue;
        }

        $managers[] = [
            'id' => $id,
            'name' => pickManagerDisplayName($row),
        ];
    }

    usort($managers, static function (array $a, array $b): int {
        return strcmp((string)$a['name'], (string)$b['name']);
    });

    outJson(['success' => true, 'managers' => $managers]);
} catch (Throwable $e) {
    mapError('get_managers failed', ['error' => $e->getMessage()]);
    outJson(['success' => false, 'message' => 'Не удалось загрузить менеджеров']);
}
