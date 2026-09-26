<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function outDrivers(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function extractPlateOnly(string $value): string
{
    $value = mb_strtoupper(trim($value), 'UTF-8');
    $latinToCyr = [
        'A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н',
        'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р', 'T' => 'Т',
        'X' => 'Х', 'Y' => 'У',
    ];
    $value = strtr($value, $latinToCyr);
    if (preg_match('/([АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2}\d{2,3})/u', $value, $m)) {
        return $m[1];
    }
    return '';
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    $stmt = $pdo->query("SELECT id, full_name, vehicle_make_plate FROM drivers ORDER BY full_name ASC");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $drivers = [];
    foreach ((array)$rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $fullName = trim((string)($row['full_name'] ?? ''));
        $plateRaw = trim((string)($row['vehicle_make_plate'] ?? ''));
        $plate = extractPlateOnly($plateRaw);
        $label = trim(($fullName !== '' ? $fullName : '') . (($fullName !== '' && $plate !== '') ? ' — ' : '') . ($plate !== '' ? $plate : ''));
        if ($label === '') {
            $label = 'Водитель #' . $id;
        }
        $drivers[] = [
            'id' => $id,
            'full_name' => $fullName,
            'vehicle_make_plate' => $plateRaw,
            'vehicle_plate_only' => $plate,
            'label' => $label,
        ];
    }

    outDrivers([
        'success' => true,
        'drivers' => $drivers,
    ]);
} catch (Throwable $e) {
    if (function_exists('mapError')) {
        mapError('get_drivers failed', ['error' => $e->getMessage()]);
    }
    outDrivers([
        'success' => false,
        'message' => 'Ошибка загрузки списка водителей.',
    ]);
}
