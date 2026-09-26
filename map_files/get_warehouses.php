<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function quoteIdent(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    $stmtCols = $pdo->query('SHOW COLUMNS FROM warehouses');
    $columns = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];
    if (!is_array($columns) || empty($columns)) {
        out(['success' => true, 'warehouses' => []]);
    }

    $map = [];
    foreach ($columns as $column) {
        $map[(string)$column] = true;
    }

    $nameColumn = null;
    foreach (['name', 'title', 'warehouse_name', 'label'] as $candidate) {
        if (isset($map[$candidate])) {
            $nameColumn = $candidate;
            break;
        }
    }
    if ($nameColumn === null) {
        $nameColumn = 'id';
    }

    $addressColumn = null;
    foreach (['address', 'full_address', 'location', 'addr'] as $candidate) {
        if (isset($map[$candidate])) {
            $addressColumn = $candidate;
            break;
        }
    }

    $activeColumn = null;
    foreach (['is_active', 'active', 'enabled', 'status'] as $candidate) {
        if (isset($map[$candidate])) {
            $activeColumn = $candidate;
            break;
        }
    }

    $hasLat = isset($map['latitude']);
    $hasLon = isset($map['longitude']);

    $sql = 'SELECT id, ' . quoteIdent($nameColumn) . ' AS name';
    $sql .= $hasLat ? ', latitude' : ', NULL AS latitude';
    $sql .= $hasLon ? ', longitude' : ', NULL AS longitude';
    if ($addressColumn !== null) {
        $sql .= ', ' . quoteIdent($addressColumn) . ' AS address';
    } else {
        $sql .= ', NULL AS address';
    }
    $sql .= ' FROM warehouses WHERE 1=1';

    if ($activeColumn !== null) {
        if ($activeColumn === 'status') {
            $sql .= " AND LOWER(TRIM(" . quoteIdent($activeColumn) . ")) IN ('1','active','enabled')";
        } else {
            $sql .= ' AND ' . quoteIdent($activeColumn) . ' = 1';
        }
    }

    $sql .= ' ORDER BY id ASC';

    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $result = [];

    foreach ((array)$rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $lat = null;
        if ($row['latitude'] !== null && $row['latitude'] !== '') {
            $latParsed = (float)$row['latitude'];
            if (is_finite($latParsed)) {
                $lat = $latParsed;
            }
        }
        $lon = null;
        if ($row['longitude'] !== null && $row['longitude'] !== '') {
            $lonParsed = (float)$row['longitude'];
            if (is_finite($lonParsed)) {
                $lon = $lonParsed;
            }
        }

        $result[] = [
            'id' => $id,
            'name' => trim((string)($row['name'] ?? ('Склад #' . $id))),
            'address' => trim((string)($row['address'] ?? '')),
            'latitude' => $lat,
            'longitude' => $lon,
        ];
    }

    out(['success' => true, 'warehouses' => $result]);
} catch (Throwable $e) {
    if (function_exists('mapError')) {
        mapError('get_warehouses failed', ['error' => $e->getMessage()]);
    }
    out(['success' => false, 'message' => 'Ошибка загрузки складов']);
}
