<?php
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function utilizerJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizeUtilizerColor($value, string $fallback): string
{
    $value = trim((string)$value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }
    $exists = $pdo->query("SHOW TABLES LIKE 'utilizers'");
    if (!$exists || !$exists->fetchColumn()) {
        utilizerJson(['success' => true, 'utilizers' => [], 'table_missing' => true]);
    }
    $columns = $pdo->query('SHOW COLUMNS FROM utilizers')->fetchAll(PDO::FETCH_COLUMN);
    $hasTextColor = in_array('color_text', $columns, true);
    $textColorSelect = $hasTextColor ? 'color_text' : 'color_dark AS color_text';
    $stmt = $pdo->query(
        'SELECT id, full_name, short_name, address, fkko_type, dangerous_cargo, latitude, longitude,
                color_main, color_dark, ' . $textColorSelect . '
         FROM utilizers
         WHERE is_active = 1
         ORDER BY sort_order ASC, short_name ASC, id ASC'
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $result = [];
    foreach ((array)$rows as $row) {
        $lat = is_numeric($row['latitude'] ?? null) ? (float)$row['latitude'] : null;
        $lon = is_numeric($row['longitude'] ?? null) ? (float)$row['longitude'] : null;
        if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) continue;
        $dark = normalizeUtilizerColor($row['color_dark'] ?? '', '#7f4125');
        $result[] = [
            'id' => (int)$row['id'],
            'full_name' => trim((string)$row['full_name']),
            'short_name' => trim((string)$row['short_name']),
            'address' => trim((string)$row['address']),
            'fkko_type' => trim((string)$row['fkko_type']),
            'dangerous_cargo' => trim((string)$row['dangerous_cargo']),
            'latitude' => $lat,
            'longitude' => $lon,
            'color_main' => normalizeUtilizerColor($row['color_main'] ?? '', '#f4b183'),
            'color_dark' => $dark,
            'color_text' => normalizeUtilizerColor($row['color_text'] ?? '', $dark),
        ];
    }
    utilizerJson(['success' => true, 'utilizers' => $result]);
} catch (Throwable $e) {
    if (function_exists('mapError')) mapError('get_utilizers failed', ['error' => $e->getMessage()]);
    utilizerJson(['success' => false, 'message' => 'Ошибка загрузки утилизаторов'], 500);
}
