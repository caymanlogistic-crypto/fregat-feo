<?php
require_once __DIR__ . '/bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

$response = [
    'success' => false,
    'active_trackers' => [],
    'all_trackers' => [],
    'counts' => ['active' => 0, 'all' => 0],
    'generated_at' => date(DATE_ATOM),
];

try {
    $slitexClient = new SlitexClient($slitexBaseUrl, $slitexApiToken);
    $trackerService = new TrackerService($slitexClient);
    $transportMatcherService = new TransportMatcherService();
    $transportVisibilityService = new TransportVisibilityService($transportMatcherService);
    $flightRepository = new FlightRepository($pdo);
    $driverRepository = new DriverRepository($pdo);

    $allTrackers = $trackerService->getTrackers();
    if (!is_array($allTrackers)) {
        throw new RuntimeException('TrackerService returned non-array data');
    }

    $relevantFlights = $flightRepository->getTransportRelevantFlights();
    if (!is_array($relevantFlights)) {
        $relevantFlights = [];
    }

    $driverIds = [];
    foreach ($relevantFlights as $flight) {
        if (!is_array($flight)) {
            continue;
        }
        $driverId = (int)($flight['driver_id'] ?? 0);
        if ($driverId > 0) {
            $driverIds[$driverId] = $driverId;
        }
    }

    $drivers = [];
    if (!empty($driverIds)) {
        if (method_exists($driverRepository, 'getByIds')) {
            $driversRaw = $driverRepository->getByIds(array_values($driverIds));
            if (is_array($driversRaw)) {
                $drivers = $driversRaw;
            }
        } elseif ($pdo instanceof PDO) {
            $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
            $stmt = $pdo->prepare("SELECT id, full_name, vehicle_make_plate FROM drivers WHERE id IN ({$placeholders})");
            if ($stmt && $stmt->execute(array_values($driverIds))) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    $drivers = $rows;
                }
            }
        }
    }

    $activeTrackers = $transportVisibilityService->filterTrackers($allTrackers, $relevantFlights, $drivers);
    if (!is_array($activeTrackers)) {
        $activeTrackers = [];
    }

    $response = [
        'success' => true,
        'active_trackers' => array_values($activeTrackers),
        'all_trackers' => array_values($allTrackers),
        'counts' => [
            'active' => count($activeTrackers),
            'all' => count($allTrackers),
        ],
        'generated_at' => date(DATE_ATOM),
    ];
} catch (Throwable $e) {
    mapError('Transport JSON endpoint failed', ['error' => $e->getMessage()]);
    http_response_code(503);
    $response['message'] = 'Не удалось обновить данные транспорта';
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
