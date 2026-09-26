<?php
/**
 * Временный гостевой доступ к карте.
 *
 * ВАЖНО:
 * - файл предназначен только для просмотра;
 * - меню ERP отключено;
 * - любые POST/PUT/PATCH/DELETE запросы блокируются;
 * - доступ разрешён только по секретному ключу в ссылке;
 * - удаление этого файла полностью отзывает гостевую ссылку.
 */

define('MAPTEST_NO_MENU2', true);
define('MAPTEST_GUEST_MODE', true);
define('MAPTEST_READ_ONLY', true);

$mapGuestKey = trim((string) (getenv('MAP_GUEST_KEY') ?: ''));

$providedGuestKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if ($providedGuestKey === '' || $mapGuestKey === '' || !hash_equals($mapGuestKey, $providedGuestKey)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Страница не найдена';
    exit;
}

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'Гостевой доступ работает только в режиме просмотра',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    header('Referrer-Policy: no-referrer', true);
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
    header('Pragma: no-cache', true);
    header('Expires: 0', true);
    header('X-Content-Type-Options: nosniff', true);
}

require_once __DIR__ . '/map_files/bootstrap.php';
require_once __DIR__ . '/map_files/Support/ui_texts.php';

// Гостевая версия: серверные действия изменения рейсов намеренно отсутствуют.

$mapDataService = new MapDataService(
    new FlightRepository($pdo),
    new StatusBlockRepository($pdo),
    new FeoRepository($pdo),
    $statusColors,
    $statusNames
);

$slitexClient = new SlitexClient($slitexBaseUrl, $slitexApiToken);
$trackerService = new TrackerService($slitexClient);
$transportMatcherService = new TransportMatcherService();
$transportVisibilityService = new TransportVisibilityService($transportMatcherService);
$transportFlightRepository = new FlightRepository($pdo);
$driverRepository = new DriverRepository($pdo);
$routeCardsFlightRepository = new FlightRepository($pdo);
$routeCardsFeoRepository = new FeoRepository($pdo);

$trackers = [];
try {
    $trackers = $trackerService->getTrackers();
    if (!is_array($trackers)) {
        $trackers = [];
    }
} catch (Throwable $e) {
    mapError('TrackerService failed', ['error' => $e->getMessage()]);
}
$allTrackers = []; // В гостевом режиме полный нефильтрованный список не отдаём.

$rawTrackersCount = count($trackers);
try {
    $relevantFlights = $transportFlightRepository->getTransportRelevantFlights();
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
    if (method_exists($driverRepository, 'getByIds')) {
        $driversRaw = $driverRepository->getByIds(array_values($driverIds));
        if (is_array($driversRaw)) {
            $drivers = $driversRaw;
        }
    } elseif (!empty($driverIds) && isset($pdo) && $pdo instanceof PDO) {
        $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
        $sqlDrivers = "SELECT id, full_name, vehicle_make_plate FROM drivers WHERE id IN ({$placeholders})";
        try {
            $stmtDrivers = $pdo->prepare($sqlDrivers);
            if ($stmtDrivers && $stmtDrivers->execute(array_values($driverIds))) {
                $rowsDrivers = $stmtDrivers->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rowsDrivers)) {
                    $drivers = $rowsDrivers;
                }
            }
        } catch (Throwable $e) {
            mapError('Route cards drivers fallback failed', ['error' => $e->getMessage()]);
        }
    }

    $trackers = $transportVisibilityService->filterTrackers($trackers, $relevantFlights, $drivers);
    if (!is_array($trackers)) {
        $trackers = [];
    }

    $visibleTrackerNames = [];
    foreach ($trackers as $t) {
        if (is_array($t)) {
            $visibleTrackerNames[] = (string)($t['name'] ?? '');
        }
    }
    mapLog('Transport visibility filter applied', [
        'raw_trackers' => $rawTrackersCount,
        'filtered_trackers' => count($trackers),
        'visible_trackers' => $visibleTrackerNames,
    ]);
} catch (Throwable $e) {
    mapError('Transport visibility filter failed', ['error' => $e->getMessage()]);
}

// Гостю доступны только уже отфильтрованные трекеры.
$allTrackers = $trackers;

$trackersJson = json_encode($trackers, JSON_UNESCAPED_UNICODE);
if ($trackersJson === false) {
    mapError('Failed to encode trackers JSON', ['error' => json_last_error_msg()]);
    $trackersJson = '[]';
}

$allTrackersJson = json_encode($allTrackers, JSON_UNESCAPED_UNICODE);
if ($allTrackersJson === false) {
    mapError('Failed to encode allTrackers JSON', ['error' => json_last_error_msg()]);
    $allTrackersJson = '[]';
}

$routeCardsMeta = [];
$foundRoutesData = [];
$driversForSelect = [];
$routeCardsBuildError = null;
try {
    $blockFlights = [];
    if (method_exists($routeCardsFlightRepository, 'getFlightsForRouteBlocks')) {
        $blockFlightsRaw = $routeCardsFlightRepository->getFlightsForRouteBlocks();
        if (is_array($blockFlightsRaw)) {
            $blockFlights = $blockFlightsRaw;
        }
    } else {
        mapError('Route cards: FlightRepository::getFlightsForRouteBlocks is missing, using direct SQL fallback');
    }

    if (empty($blockFlights) && isset($pdo) && $pdo instanceof PDO) {
        $sqlFallback = "
            SELECT
                id,
                status,
                driver_id,
                zayavki_ids,
                comment AS name,
                comment,
                comment AS route_name,
                comment AS direction,
                cost,
                planned_start_date_from,
                planned_start_date_to,
                unload_type,
                route_type,
                source_warehouse_id,
                destination_warehouse_id
            FROM flights
            WHERE status IN ('planned_route', 'found')
            ORDER BY id DESC
        ";
        try {
            $stmt = $pdo->query($sqlFallback);
            if ($stmt) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    $blockFlights = $rows;
                }
            }
        } catch (Throwable $e) {
            mapError('Route cards direct SQL fallback failed', ['error' => $e->getMessage()]);
        }
    }

    $allZayavkaIds = [];
    $driverIds = [];
    foreach ($blockFlights as $flight) {
        if (!is_array($flight)) {
            continue;
        }
        foreach (explode(',', (string)($flight['zayavki_ids'] ?? '')) as $zid) {
            $zid = trim($zid);
            if ($zid !== '' && preg_match('/^\d+$/', $zid)) {
                $allZayavkaIds[$zid] = $zid;
            }
        }
        $driverId = (int)($flight['driver_id'] ?? 0);
        if ($driverId > 0) {
            $driverIds[$driverId] = $driverId;
        }
    }

    $routeInfoMap = [];
    $massMap = [];
    $zidList = array_values($allZayavkaIds);

    if (method_exists($routeCardsFeoRepository, 'getRouteInfoByZayavkaIds')) {
        $routeInfoMapRaw = $routeCardsFeoRepository->getRouteInfoByZayavkaIds($zidList);
        if (is_array($routeInfoMapRaw)) {
            $routeInfoMap = $routeInfoMapRaw;
        }
    } else {
        mapError('Route cards: FeoRepository::getRouteInfoByZayavkaIds is missing, using mass fallback');
    }

    foreach ($routeInfoMap as $zid => $routeInfoRow) {
        if (!is_array($routeInfoRow)) {
            continue;
        }
        $massMap[(string)$zid] = isset($routeInfoRow['mass_netto']) ? (float)$routeInfoRow['mass_netto'] : 0.0;
    }

    if (empty($massMap) && !empty($zidList)) {
        $massMapRaw = $routeCardsFeoRepository->getMassByZayavkaIds($zidList);
        if (is_array($massMapRaw)) {
            $massMap = $massMapRaw;
        }
    }

    $drivers = $driverRepository->getByIds(array_values($driverIds));
    if (!is_array($drivers)) {
        $drivers = [];
    }

    $driversById = [];
    foreach ($drivers as $driver) {
        if (!is_array($driver)) {
            continue;
        }
        $did = (int)($driver['id'] ?? 0);
        if ($did > 0) {
            $driversById[$did] = $driver;
        }
    }

    $driversForSelectRaw = [];
    if (method_exists($driverRepository, 'getAllForSelect')) {
        $raw = $driverRepository->getAllForSelect();
        if (is_array($raw)) {
            $driversForSelectRaw = $raw;
        }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        try {
            $stmtAllDrivers = $pdo->query("SELECT id, full_name, vehicle_make_plate FROM drivers ORDER BY full_name ASC");
            if ($stmtAllDrivers) {
                $rowsAllDrivers = $stmtAllDrivers->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rowsAllDrivers)) {
                    $driversForSelectRaw = $rowsAllDrivers;
                }
            }
        } catch (Throwable $e) {
            mapError('Route cards getAllForSelect fallback failed', ['error' => $e->getMessage()]);
        }
    }

    if (is_array($driversForSelectRaw)) {
        foreach ($driversForSelectRaw as $driver) {
            if (!is_array($driver)) {
                continue;
            }

            $did = (int)($driver['id'] ?? 0);
            if ($did <= 0) {
                continue;
            }

            $fullName = trim((string)mapNormalizeText((string)($driver['full_name'] ?? '')));
            $plate = trim((string)mapNormalizeText((string)($driver['vehicle_make_plate'] ?? '')));
            $surname = trim((string)explode(' ', $fullName)[0]);

            $shortFio = $surname;
            $parts = preg_split('/\s+/u', $fullName);
            if (is_array($parts) && isset($parts[1])) {
                $n1 = function_exists('mb_substr') ? mb_substr((string)$parts[1], 0, 1, 'UTF-8') : substr((string)$parts[1], 0, 1);
                $n2 = isset($parts[2]) ? (function_exists('mb_substr') ? mb_substr((string)$parts[2], 0, 1, 'UTF-8') : substr((string)$parts[2], 0, 1)) : '';
                if ($n1 !== '' || $n2 !== '') {
                    $shortFio = trim($surname . ' ' . ($n1 !== '' ? $n1 . '.' : '') . ($n2 !== '' ? $n2 . '.' : ''));
                }
            }

            $label = trim($shortFio . ($plate !== '' ? ' — ' . $plate : ''));
            if ($label === '') {
                $label = 'Водитель #' . $did;
            }

            $driversForSelect[] = [
                'id' => $did,
                'label' => $label,
                'full_name' => $fullName,
                'vehicle_make_plate' => $plate,
            ];
        }
    }

    foreach ($blockFlights as $flight) {
        if (!is_array($flight)) {
            continue;
        }

        $flightId = (int)($flight['id'] ?? 0);
        if ($flightId <= 0) {
            continue;
        }

        $status = (string)($flight['status'] ?? '');
        $zayavkiIdsRaw = (string)($flight['zayavki_ids'] ?? '');

        $zayavkiIds = [];
        foreach (explode(',', $zayavkiIdsRaw) as $zid) {
            $zid = trim($zid);
            if ($zid !== '' && preg_match('/^\d+$/', $zid)) {
                $zayavkiIds[] = $zid;
            }
        }

        $sumTons = 0.0;
        foreach ($zayavkiIds as $zid) {
            $sumTons += isset($massMap[$zid]) ? (float)$massMap[$zid] : 0.0;
        }
        $totalKg = (int)round($sumTons * 1000);

        $driverLabel = 'Водитель не указан';
        $driverId = (int)($flight['driver_id'] ?? 0);
        if ($driverId > 0 && isset($driversById[$driverId])) {
            $driver = $driversById[$driverId];
            $plateRaw = trim((string)mapNormalizeText((string)($driver['vehicle_make_plate'] ?? '')));
            $fullName = trim((string)mapNormalizeText((string)($driver['full_name'] ?? '')));
            $surname = trim((string)explode(' ', $fullName)[0]);
            // Extract gosnumber from vehicle_make_plate
            $plateOnly = '';
            if (preg_match('/([А-ЯЁA-Z]\d{3}\s*[А-ЯЁA-Z]{2}\s*\d{2,3})/u', $plateRaw, $m)) {
                $plateOnly = preg_replace('/\s+/', '', $m[1]);
            }
            if ($plateOnly !== '' && $surname !== '') {
                $driverLabel = $plateOnly . '(' . $surname . ')';
            } elseif ($plateOnly !== '') {
                $driverLabel = $plateOnly;
            } elseif ($surname !== '') {
                $driverLabel = $surname;
            }
        }

        $nameValue = trim((string)mapNormalizeText((string)($flight['name'] ?? '')));
        $commentValue = trim((string)mapNormalizeText((string)($flight['comment'] ?? '')));
        $routeNameValue = trim((string)mapNormalizeText((string)($flight['route_name'] ?? '')));
        $directionValue = trim((string)mapNormalizeText((string)($flight['direction'] ?? '')));

        $routeTitle = '';
        foreach ([$nameValue, $routeNameValue, $commentValue, $directionValue] as $candidateTitle) {
            $candidateTitle = trim((string)$candidateTitle);
            if ($candidateTitle === '') {
                continue;
            }
            if (preg_match('/^Рейс\\s*#\\d+$/u', $candidateTitle)) {
                continue;
            }
            $routeTitle = $candidateTitle;
            break;
        }

        if ($routeTitle === '') {
            $city = '';
            foreach ($zayavkiIds as $zid) {
                if (!isset($routeInfoMap[$zid]) || !is_array($routeInfoMap[$zid])) {
                    continue;
                }
                $addr = trim((string)mapNormalizeText((string)($routeInfoMap[$zid]['mno_adres_pogruzki'] ?? '')));
                if ($addr !== '' && preg_match('/(?:^|,\\s*)г\\.?\\s*([^,]+)/ui', $addr, $m) && !empty($m[1])) {
                    $city = trim((string)$m[1]);
                    break;
                }
            }

            $tonsText = '';
            if ($sumTons > 0) {
                $tonsRounded = round($sumTons, 1);
                $tonsText = rtrim(rtrim(number_format($tonsRounded, 1, '.', ''), '0'), '.') . 'тн';
            }

            if ($city !== '' && $tonsText !== '') {
                $routeTitle = $city . ' ' . $tonsText;
            } elseif ($city !== '') {
                $routeTitle = $city;
            } elseif ($tonsText !== '') {
                $routeTitle = $tonsText;
            }
        }

        $card = [
            'id' => $flightId,
            'name' => $nameValue,
            'route_title' => $routeTitle,
            'title' => $routeTitle,
            'comment' => $commentValue,
            'route_name' => $routeNameValue,
            'direction' => $directionValue,
            'zayavki_ids' => implode(',', $zayavkiIds),
            'zayavki_count' => count($zayavkiIds),
            'cost' => $flight['cost'] ?? null,
            'total_kg' => $totalKg,
            'driver_label' => $driverLabel,
            'driver_id' => (int)($flight['driver_id'] ?? 0),
            'planned_start_date_from' => $flight['planned_start_date_from'] ?? null,
            'planned_start_date_to' => $flight['planned_start_date_to'] ?? null,
            'status' => $status,
            'unload_type' => (string)($flight['unload_type'] ?? 'OO'),
            'route_type' => (string)($flight['route_type'] ?? ''),
            'source_warehouse_id' => (int)($flight['source_warehouse_id'] ?? 0),
            'destination_warehouse_id' => (int)($flight['destination_warehouse_id'] ?? 0),
        ];

        if ($status === 'planned_route') {
            $routeCardsMeta[(string)$flightId] = $card;
        } elseif ($status === 'found') {
            $foundRoutesData[] = $card;
        }
    }
} catch (Throwable $e) {
    $routeCardsBuildError = $e->getMessage();
    mapError('Route cards data build failed', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    $routeCardsMeta = [];
    $foundRoutesData = [];
    $driversForSelect = [];
}

$routeCardsMetaJson = json_encode($routeCardsMeta, JSON_UNESCAPED_UNICODE);
if ($routeCardsMetaJson === false) {
    mapError('Failed to encode routeCardsMeta JSON', ['error' => json_last_error_msg()]);
    $routeCardsMetaJson = '{}';
}

$foundRoutesJson = json_encode($foundRoutesData, JSON_UNESCAPED_UNICODE);
if ($foundRoutesJson === false) {
    mapError('Failed to encode foundRoutesData JSON', ['error' => json_last_error_msg()]);
    $foundRoutesJson = '[]';
}

$driversForSelect = []; // Гостю не отдаём справочник выбора водителей.
$driversForSelectJson = json_encode($driversForSelect, JSON_UNESCAPED_UNICODE);
if ($driversForSelectJson === false) {
    mapError('Failed to encode driversForSelect JSON', ['error' => json_last_error_msg()]);
    $driversForSelectJson = '[]';
}

$mapData = [
    'processedGroups' => [],
    'flightStatusList' => [],
    'customLayers' => [],
    'hasDefault' => false,
];

$uiTexts = [];
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $uiTexts = mapLoadUiTexts($pdo);
    }
} catch (Throwable $e) {
    $uiTexts = [];
}

$uiPopupTemplates = [];
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ui_popup_templates` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `popup_key` VARCHAR(100) NOT NULL UNIQUE,
            `title` VARCHAR(255) NOT NULL,
            `template_text` TEXT NOT NULL,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `description` TEXT NULL,
            `placeholders` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $stmt = $pdo->query("SELECT `popup_key`, `title`, `template_text`, `is_enabled` FROM `ui_popup_templates` WHERE `is_enabled` = 1");
        $tplRows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ((array)$tplRows as $row) {
            $uiPopupTemplates[(string)$row['popup_key']] = [
                'title' => (string)$row['title'],
                'template_text' => (string)$row['template_text'],
                'is_enabled' => (bool)$row['is_enabled'],
            ];
        }
    }
} catch (Throwable $e) {
    $uiPopupTemplates = [];
}

try {
    $builtData = $mapDataService->build();
    if (is_array($builtData)) {
        $mapData = array_merge($mapData, $builtData);
    }
} catch (Throwable $e) {
    mapError('MapDataService failed', ['error' => $e->getMessage()]);
}

$processedGroups = is_array($mapData['processedGroups'] ?? null) ? $mapData['processedGroups'] : [];
$flightStatusList = is_array($mapData['flightStatusList'] ?? null) ? $mapData['flightStatusList'] : [];
$customLayers = is_array($mapData['customLayers'] ?? null) ? $mapData['customLayers'] : [];
$hasDefault = (bool)($mapData['hasDefault'] ?? false);

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

require_once __DIR__ . '/map_files/Views/map-page.php';
