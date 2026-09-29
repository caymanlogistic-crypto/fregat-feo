<?php
declare(strict_types=1);

require_once __DIR__ . '/DataService.php';
require_once __DIR__ . '/RouteStore.php';
require_once __DIR__ . '/XlsxExporter.php';

session_start();

const MAP8000_DESTINATIONS = [
    'novosibirsk' => ['label' => 'Новосибирск', 'coords' => [55.030204, 82.920430]],
    'krasnoyarsk' => ['label' => 'Красноярск', 'coords' => [56.015283, 92.893248]],
    'irkutsk' => ['label' => 'Иркутск', 'coords' => [52.286351, 104.280655]],
    'ekaterinburg' => ['label' => 'Екатеринбург', 'coords' => [56.838011, 60.597465]],
];

$dataDirectory = dirname(__DIR__) . '/map8000_data';
$xlsxPath = $dataDirectory . '/requests.xlsx';
$cachePath = $dataDirectory . '/requests-cache.json';
$routesPath = $dataDirectory . '/routes.json';
$action = trim((string)($_GET['action'] ?? ''));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'requests') {
        $payload = (new Map8000DataService($xlsxPath, $cachePath))->load();
        map8000Json(['success' => true] + $payload);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'routes') {
        map8000Json([
            'success' => true,
            'routes' => (new Map8000RouteStore($routesPath))->all(),
            'destinations' => MAP8000_DESTINATIONS,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export') {
        $routes = (new Map8000RouteStore($routesPath))->all();
        $exportPath = (new Map8000XlsxExporter())->create($routes);
        $fileName = 'map8000_routes_' . date('Y-m-d_H-i') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($exportPath));
        header('Cache-Control: no-store, max-age=0');
        readfile($exportPath);
        @unlink($exportPath);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        map8000Json(['success' => false, 'message' => 'Метод не поддерживается.'], 405);
    }

    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!isset($_SESSION['map8000_csrf']) || !hash_equals((string)$_SESSION['map8000_csrf'], $token)) {
        map8000Json(['success' => false, 'message' => 'Сессия устарела. Обновите страницу.'], 403);
    }

    $raw = file_get_contents('php://input');
    if (strlen((string)$raw) > 20 * 1024 * 1024) {
        map8000Json(['success' => false, 'message' => 'Запрос слишком большой.'], 413);
    }
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
        map8000Json(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    if ($action === 'optimize_route') {
        $destinationKey = map8000DestinationKey($payload['destination'] ?? '');
        $requests = map8000NormalizeRequests($payload['requests'] ?? []);
        $optimized = map8000Optimize($requests, MAP8000_DESTINATIONS[$destinationKey]['coords']);
        map8000Json([
            'success' => true,
            'requests' => $optimized,
            'estimated_distance_km' => map8000PathDistance($optimized, MAP8000_DESTINATIONS[$destinationKey]['coords']),
            'destination' => $destinationKey,
            'destination_label' => MAP8000_DESTINATIONS[$destinationKey]['label'],
            'destination_coords' => MAP8000_DESTINATIONS[$destinationKey]['coords'],
        ]);
    }

    if ($action === 'save_route') {
        $destinationKey = map8000DestinationKey($payload['destination'] ?? '');
        $requests = map8000NormalizeRequests($payload['requests'] ?? []);
        $optimized = map8000Optimize($requests, MAP8000_DESTINATIONS[$destinationKey]['coords']);
        $distanceCalculated = filter_var($payload['distance_calculated'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $distanceKm = 0.0;
        $durationMin = 0.0;
        $distanceSource = 'Не рассчитывалось';
        if ($distanceCalculated) {
            $distanceKm = max(0.0, min(1000000.0, (float)($payload['distance_km'] ?? 0)));
            $durationMin = max(0.0, min(10000000.0, (float)($payload['duration_min'] ?? 0)));
            if ($distanceKm <= 0) {
                $distanceKm = map8000PathDistance($optimized, MAP8000_DESTINATIONS[$destinationKey]['coords']);
            }
            $distanceSource = in_array(($payload['distance_source'] ?? ''), ['Яндекс.Маршруты', 'Геодезическая оценка'], true)
                ? (string)$payload['distance_source']
                : 'Геодезическая оценка';
        }

        $route = [
            'id' => max(0, (int)($payload['id'] ?? 0)),
            'name' => map8000Text($payload['name'] ?? '', 200),
            'date' => map8000Date($payload['date'] ?? ''),
            'destination' => $destinationKey,
            'destination_label' => MAP8000_DESTINATIONS[$destinationKey]['label'],
            'destination_coords' => MAP8000_DESTINATIONS[$destinationKey]['coords'],
            'vehicle' => map8000Text($payload['vehicle'] ?? '', 200),
            'driver' => map8000Text($payload['driver'] ?? '', 200),
            'notes' => map8000Text($payload['notes'] ?? '', 4000),
            'distance_km' => round($distanceKm, 3),
            'duration_min' => round($durationMin, 1),
            'distance_source' => $distanceSource,
            'requests_count' => count($optimized),
            'total_weight_t' => round(array_reduce(
                $optimized,
                static fn(float $sum, array $request): float => $sum + (float)$request['weight_t'],
                0.0
            ), 6),
            'requests' => $optimized,
        ];
        if ($route['name'] === '') {
            throw new InvalidArgumentException('Укажите название рейса.');
        }
        $saved = (new Map8000RouteStore($routesPath))->save($route);
        map8000Json(['success' => true, 'route' => $saved]);
    }

    if ($action === 'delete_route') {
        $deleted = (new Map8000RouteStore($routesPath))->delete((int)($payload['id'] ?? 0));
        map8000Json([
            'success' => $deleted,
            'message' => $deleted ? 'Рейс удалён.' : 'Рейс не найден.',
        ], $deleted ? 200 : 404);
    }

    map8000Json(['success' => false, 'message' => 'Неизвестное действие.'], 404);
} catch (InvalidArgumentException $exception) {
    map8000Json(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('map8000: ' . $exception->getMessage());
    map8000Json(['success' => false, 'message' => 'Ошибка map8000: ' . $exception->getMessage()], 500);
}

function map8000Json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function map8000DestinationKey(mixed $value): string
{
    $key = trim((string)$value);
    if (!array_key_exists($key, MAP8000_DESTINATIONS)) {
        throw new InvalidArgumentException('Выберите конечный город.');
    }
    return $key;
}

function map8000NormalizeRequests(mixed $source): array
{
    if (!is_array($source) || $source === []) {
        throw new InvalidArgumentException('Добавьте хотя бы одну заявку.');
    }
    if (count($source) > 1000) {
        throw new InvalidArgumentException('В одном рейсе допускается не более 1000 заявок.');
    }

    $result = [];
    $seen = [];
    foreach ($source as $request) {
        if (!is_array($request)) {
            continue;
        }
        $id = map8000Text($request['id'] ?? '', 80);
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        $latitude = (float)($request['latitude'] ?? 0);
        $longitude = (float)($request['longitude'] ?? 0);
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Некорректные координаты заявки ' . $id . '.');
        }
        $seen[$id] = true;
        $allFields = [];
        if (is_array($request['all_fields'] ?? null)) {
            foreach ($request['all_fields'] as $key => $value) {
                $fieldName = map8000Text($key, 200);
                if ($fieldName !== '') {
                    $allFields[$fieldName] = map8000Scalar($value, 4000);
                }
            }
        }
        $result[] = [
            'id' => $id,
            'source_row' => max(0, (int)($request['source_row'] ?? 0)),
            'region' => map8000Text($request['region'] ?? '', 500),
            'municipality' => map8000Text($request['municipality'] ?? '', 500),
            'address' => map8000Text($request['address'] ?? '', 1000),
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'coordinate_source' => map8000Text($request['coordinate_source'] ?? '', 100),
            'fkko_code' => map8000Text($request['fkko_code'] ?? '', 100),
            'waste_type' => map8000Text($request['waste_type'] ?? '', 2000),
            'category' => map8000Text($request['category'] ?? '', 300),
            'weight_t' => round(max(0.0, min(1000000000.0, (float)($request['weight_t'] ?? 0))), 6),
            'tdt' => map8000Text($request['tdt'] ?? '', 300),
            'schedule' => map8000Text($request['schedule'] ?? '', 1000),
            'operator' => map8000Text($request['operator'] ?? '', 1000),
            'operator_address' => map8000Text($request['operator_address'] ?? '', 1000),
            'geocode_quality' => map8000Text($request['geocode_quality'] ?? '', 500),
            'geocode_precision' => map8000Text($request['geocode_precision'] ?? '', 200),
            'geocoded_address' => map8000Text($request['geocoded_address'] ?? '', 1000),
            'object_type' => map8000Text($request['object_type'] ?? '', 300),
            'geocode_status' => map8000Text($request['geocode_status'] ?? '', 300),
            'geocode_date' => map8000Text($request['geocode_date'] ?? '', 100),
            'source_note' => map8000Text($request['source_note'] ?? '', 2000),
            'all_fields' => $allFields,
        ];
    }
    if ($result === []) {
        throw new InvalidArgumentException('В рейсе нет корректных заявок.');
    }
    return $result;
}

function map8000Optimize(array $requests, array $destination): array
{
    if (count($requests) <= 1) {
        return $requests;
    }

    $ordered = [array_shift($requests)];
    while ($requests !== []) {
        $last = $ordered[array_key_last($ordered)];
        $bestIndex = 0;
        $bestDistance = INF;
        foreach ($requests as $index => $candidate) {
            $distance = map8000Distance($last, $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }
        $ordered[] = $requests[$bestIndex];
        array_splice($requests, $bestIndex, 1);
    }

    $count = count($ordered);
    for ($pass = 0; $pass < 4; $pass++) {
        $improved = false;
        for ($i = 1; $i < $count - 1; $i++) {
            for ($k = $i + 1; $k < $count; $k++) {
                $before = $ordered[$i - 1];
                $start = $ordered[$i];
                $end = $ordered[$k];
                $after = $k + 1 < $count ? $ordered[$k + 1] : ['latitude' => $destination[0], 'longitude' => $destination[1]];
                $current = map8000Distance($before, $start) + map8000Distance($end, $after);
                $swapped = map8000Distance($before, $end) + map8000Distance($start, $after);
                if ($swapped + 0.0001 < $current) {
                    $segment = array_reverse(array_slice($ordered, $i, $k - $i + 1));
                    array_splice($ordered, $i, $k - $i + 1, $segment);
                    $improved = true;
                }
            }
        }
        if (!$improved) {
            break;
        }
    }
    return $ordered;
}

function map8000PathDistance(array $requests, array $destination): float
{
    $sum = 0.0;
    for ($i = 1, $count = count($requests); $i < $count; $i++) {
        $sum += map8000Distance($requests[$i - 1], $requests[$i]);
    }
    if ($requests !== []) {
        $sum += map8000Distance($requests[array_key_last($requests)], [
            'latitude' => $destination[0],
            'longitude' => $destination[1],
        ]);
    }
    return round($sum, 3);
}

function map8000Distance(array $a, array $b): float
{
    $lat1 = deg2rad((float)$a['latitude']);
    $lon1 = deg2rad((float)$a['longitude']);
    $lat2 = deg2rad((float)$b['latitude']);
    $lon2 = deg2rad((float)$b['longitude']);
    $dLat = $lat2 - $lat1;
    $dLon = $lon2 - $lon1;
    $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
    return 6371.0088 * 2 * atan2(sqrt($h), sqrt(max(0.0, 1 - $h)));
}

function map8000Text(mixed $value, int $maxLength): string
{
    $text = trim((string)$value);
    return mb_substr($text, 0, $maxLength, 'UTF-8');
}

function map8000Scalar(mixed $value, int $maxLength): string|float|int|bool|null
{
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }
    return map8000Text($value, $maxLength);
}

function map8000Date(mixed $value): string
{
    $date = trim((string)$value);
    if ($date === '') {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Некорректная дата рейса.');
    }
    return $date;
}
