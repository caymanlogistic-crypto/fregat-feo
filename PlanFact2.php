<?php
require_once 'config.php';
session_start();

// Подключение к БД
try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// Проверяем существование таблицы plan
try {
    $pdo->query("SELECT 1 FROM plan LIMIT 1");
} catch (PDOException $e) {
    die("Таблица 'plan' не найдена. Ошибка: " . $e->getMessage());
}

// ==========================================
// 1. ПОЛУЧЕНИЕ СПИСКА ДОСТУПНЫХ ID ДЛЯ КНОПОК
// ==========================================
$available_plan_ids = [];
try {
    // 🔹 Исправленный запрос: явно выбираем оба поля дат и block_date
    $stmt = $pdo->query("SELECT id, дата_с, дата_по, block_date FROM plan WHERE id != 2 ORDER BY дата_с DESC");
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $available_plan_ids[$row['id']] = $row;
    }
} catch (PDOException $e) {
    try {
        // Fallback: если поля block_date нет, всё равно выбираем даты
        $stmt = $pdo->query("SELECT id, дата_с, дата_по FROM plan WHERE id != 2 ORDER BY дата_с DESC");
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $available_plan_ids[$row['id']] = array_merge($row, ['block_date' => null]);
        }
    } catch (PDOException $e2) {
        // Игнорируем, если даже базовый запрос не проходит
    }
}

// Определяем выбранный ID (из GET или первый доступный)
$selected_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
if ($selected_id === 0 || !array_key_exists($selected_id, $available_plan_ids)) {
    $selected_id = !empty($available_plan_ids) ? array_key_first($available_plan_ids) : 1;
}

// 2. ПОЛУЧЕНИЕ ПЛАНОВ ДЛЯ ВЫБРАННОГО ID
$plans = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM plan WHERE id = ? ORDER BY дата_с DESC");
    $stmt->execute([$selected_id]);
    $plans = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Ошибка получения планов: " . $e->getMessage());
}

// Получаем все рейсы с заявками (включая unload_type)
$flights = [];
try {
    $stmt = $pdo->query("
        SELECT *
        FROM flights
        WHERE actual_start_date IS NOT NULL OR planned_start_date IS NOT NULL OR planned_start_date_from IS NOT NULL
    ");
    $flights = $stmt->fetchAll();
} catch (PDOException $e) {
    $flights = [];
}

// Функция для получения массива заявок из строки
function parseZayavki($zayavki_str) {
    if (empty($zayavki_str)) return [];
    $zayavki = preg_split('/[\s,;]+/', trim($zayavki_str));
    return array_filter($zayavki, function($item) {
        return !empty(trim($item));
    });
}

// Рейсы, которые стартуют со склада, не считаем в блоках
// "ВЫВЕЗЕНО ПО ПЛАНУ" и "ДОП. ВЫВОЗ".
function isWarehouseStartFlight($flight, $zayavka_id = null) {
    // Главный и точный параметр новой системы:
    // flights.route_type:
    // - generator_to_utilizer   — НЕ складский старт, учитываем
    // - generator_to_warehouse  — НЕ складский старт, учитываем
    // - warehouse_to_warehouse  — старт со склада, НЕ учитываем в ВЫВЕЗЕНО ПО ПЛАНУ / ДОП. ВЫВОЗ
    // - warehouse_to_utilizer   — старт со склада, НЕ учитываем в ВЫВЕЗЕНО ПО ПЛАНУ / ДОП. ВЫВОЗ
    // - mixed_to_utilizer       — учитываем только прямые заявки; складскую часть исключаем
    $route_type = strtolower(trim((string)($flight['route_type'] ?? '')));

    if ($route_type !== '') {
        if ($route_type === 'mixed_to_utilizer') {
            if ($zayavka_id === null) {
                return false;
            }
            $direct_ids = array_map('strval', parseZayavki($flight['direct_zayavki_ids'] ?? ''));
            return !in_array((string)$zayavka_id, $direct_ids, true);
        }
        return in_array($route_type, ['warehouse_to_warehouse', 'warehouse_to_utilizer'], true);
    }

    // Запасной параметр для старых/неполных рейсов, где route_type не внесён.
    // ВАЖНО: unload_type показывает только тип разгрузки (SKLAD / OO),
    // поэтому он не может на 100% отличить generator_to_utilizer от warehouse_to_utilizer.
    // По безопасной логике SKLAD считаем складским рейсом, чтобы не завышать статистику вывоза.
    $unload_type = strtoupper(trim((string)($flight['unload_type'] ?? '')));
    return $unload_type === 'SKLAD';
}

// Функция для вывода номера рейса по заявке в формате #123
function getFlightLabelForZayavka($zayavka_id, $period_start, $period_end, $flights, $date_fields = ['actual_start_date'], $unload_type = null, $exclude_warehouse_start = false) {
    foreach ($flights as $flight) {
        if ($exclude_warehouse_start && isWarehouseStartFlight($flight, $zayavka_id)) {
            continue;
        }
        if ($unload_type !== null && ($flight['unload_type'] ?? null) !== $unload_type) {
            continue;
        }
        $date_matches = false;
        foreach ((array)$date_fields as $field) {
            if (empty($flight[$field])) {
                continue;
            }
            $flight_date = $flight[$field];
            if ($flight_date >= $period_start && $flight_date <= $period_end) {
                $date_matches = true;
                break;
            }
        }
        if (!$date_matches) {
            continue;
        }
        $flight_zayavki = parseZayavki($flight['zayavki_ids'] ?? '');
        if (in_array((string)$zayavka_id, array_map('strval', $flight_zayavki), true)) {
            return '<span class="flight-number">#' . htmlspecialchars((string)$flight['id']) . '</span>';
        }
    }
    return '<span class="no-flight">—</span>';
}

// Функция для вывода списка рейсов по блоку заявок в формате #123, #124
function getFlightLabelsForZayavkiBlock($zayavki_ids, $period_start, $period_end, $flights, $date_fields = ['actual_start_date'], $unload_type = null, $exclude_warehouse_start = false) {
    $labels = [];
    $zayavki_ids = array_map('strval', (array)$zayavki_ids);
    if (empty($zayavki_ids)) {
        return '<span class="no-flight">—</span>';
    }

    foreach ($flights as $flight) {
        if ($unload_type !== null && ($flight['unload_type'] ?? null) !== $unload_type) {
            continue;
        }

        $date_matches = false;
        foreach ((array)$date_fields as $field) {
            if (empty($flight[$field])) {
                continue;
            }
            $flight_date = $flight[$field];
            if ($flight_date >= $period_start && $flight_date <= $period_end) {
                $date_matches = true;
                break;
            }
        }
        if (!$date_matches) {
            continue;
        }

        $flight_zayavki = array_map('strval', parseZayavki($flight['zayavki_ids'] ?? ''));
        if ($exclude_warehouse_start) {
            $flight_zayavki = array_values(array_filter(
                $flight_zayavki,
                static fn($id) => !isWarehouseStartFlight($flight, $id)
            ));
        }
        if (!empty(array_intersect($zayavki_ids, $flight_zayavki))) {
            $labels[(string)$flight['id']] = '<span class="flight-number">#' . htmlspecialchars((string)$flight['id']) . '</span>';
        }
    }

    if (empty($labels)) {
        return '<span class="no-flight">—</span>';
    }

    ksort($labels, SORT_NATURAL);
    return implode(', ', $labels);
}

// Кеш для заявок
$zayavki_cache = [];
function getZayavkiInfoBatch($zayavki_ids, $pdo) {
    global $zayavki_cache;
    $missing_ids = [];
    foreach ($zayavki_ids as $id) {
        if (!isset($zayavki_cache[$id])) {
            $missing_ids[] = $id;
        }
    }
    if (!empty($missing_ids)) {
        $placeholders = implode(',', array_fill(0, count($missing_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT zayavka_id, mass_netto, naim_oo_gruzootpravitel, mno_adres_pogruzki, mno_sh, mno_d
            FROM feo
            WHERE zayavka_id IN ({$placeholders})
        ");
        $stmt->execute($missing_ids);
        while ($row = $stmt->fetch()) {
            $zayavki_cache[$row['zayavka_id']] = $row;
        }
    }
    $result = [];
    foreach ($zayavki_ids as $id) {
        $result[$id] = $zayavki_cache[$id] ?? null;
    }
    return $result;
}


// Функция для безопасного ограничения текста первыми 50 символами
function limitText50($value) {
    $text = (string)($value ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 50, 'UTF-8');
    }
    return substr($text, 0, 50);
}

// Функция для получения веса заявки в КГ
function getZayavkaWeightKg($zayavka_id, $pdo) {
    $info = getZayavkiInfoBatch([$zayavka_id], $pdo);
    $weight_tonn = $info[$zayavka_id]['mass_netto'] ?? 0;
    return floatval($weight_tonn) * 1000;
}

// Функция для получения полной информации о заявке
function getZayavkaInfo($zayavka_id, $pdo) {
    $info = getZayavkiInfoBatch([$zayavka_id], $pdo);
    return $info[$zayavka_id];
}

// Функция для проверки, вывезена ли заявка в указанном периоде (по actual_start_date)
function isZayavkaCompletedInPeriod($zayavka_id, $period_start, $period_end, $flights, $exclude_warehouse_start = false) {
    foreach ($flights as $flight) {
        if ($exclude_warehouse_start && isWarehouseStartFlight($flight, $zayavka_id)) continue;
        if (empty($flight['actual_start_date'])) continue;
        $actual_date = $flight['actual_start_date'];
        if ($actual_date < $period_start || $actual_date > $period_end) continue;
        $flight_zayavki = parseZayavki($flight['zayavki_ids']);
        if (in_array($zayavka_id, $flight_zayavki)) {
            return true;
        }
    }
    return false;
}

// Функция для проверки, находится ли заявка в пути
function isZayavkaInTransit($zayavka_id, $period_start, $period_end, $flights) {
    foreach ($flights as $flight) {
        if (empty($flight['actual_start_date'])) continue;
        $actual_start = $flight['actual_start_date'];
        if ($actual_start < $period_start || $actual_start > $period_end) continue;
        if (empty($flight['actual_end_date'])) {
            $flight_zayavki = parseZayavki($flight['zayavki_ids']);
            if (in_array($zayavka_id, $flight_zayavki)) {
                return true;
            }
        }
    }
    return false;
}

// Функция для проверки, доставлена ли заявка (actual_end_date есть И unload_type = 'OO')
function isZayavkaDeliveredInPeriod($zayavka_id, $period_start, $period_end, $flights) {
    foreach ($flights as $flight) {
        if (empty($flight['actual_end_date'])) continue;
        if ($flight['unload_type'] !== 'OO') continue;
        $actual_date = $flight['actual_end_date'];
        if ($actual_date < $period_start || $actual_date > $period_end) continue;
        $flight_zayavki = parseZayavki($flight['zayavki_ids']);
        if (in_array($zayavka_id, $flight_zayavki)) {
            return true;
        }
    }
    return false;
}

// Функция для проверки, заявка на складе (actual_end_date есть И unload_type = 'SKLAD')
function isZayavkaInWarehouse($zayavka_id, $period_start, $period_end, $flights) {
    foreach ($flights as $flight) {
        if (empty($flight['actual_end_date'])) continue;
        if ($flight['unload_type'] !== 'SKLAD') continue;
        $actual_date = $flight['actual_end_date'];
        if ($actual_date < $period_start || $actual_date > $period_end) continue;
        $flight_zayavki = parseZayavki($flight['zayavki_ids']);
        if (in_array($zayavka_id, $flight_zayavki)) {
            return true;
        }
    }
    return false;
}

// Функция для получения заявок, вывезенных в периоде, но не входящих в план
function getExtraCompletedZayavki($period_start, $period_end, $planned_zayavki, $flights, $exclude_warehouse_start = false) {
    $extra = [];
    foreach ($flights as $flight) {
        if (empty($flight['actual_start_date'])) continue;
        $actual_date = $flight['actual_start_date'];
        if ($actual_date < $period_start || $actual_date > $period_end) continue;
        $flight_zayavki = parseZayavki($flight['zayavki_ids']);
        if ($exclude_warehouse_start) {
            $flight_zayavki = array_values(array_filter(
                $flight_zayavki,
                static fn($id) => !isWarehouseStartFlight($flight, $id)
            ));
        }
        foreach ($flight_zayavki as $z_id) {
            if (!in_array($z_id, $planned_zayavki) && !in_array($z_id, $extra)) {
                $extra[] = $z_id;
            }
        }
    }
    return $extra;
}

// Функция для получения заявок, находящихся в пути
function getInTransitZayavki($period_start, $period_end, $flights) {
    $in_transit = [];
    foreach ($flights as $flight) {
        if (empty($flight['actual_start_date'])) continue;
        $actual_start = $flight['actual_start_date'];
        if ($actual_start < $period_start || $actual_start > $period_end) continue;
        if (empty($flight['actual_end_date'])) {
            $flight_zayavki = parseZayavki($flight['zayavki_ids']);
            foreach ($flight_zayavki as $z_id) {
                if (!in_array($z_id, $in_transit)) {
                    $in_transit[] = $z_id;
                }
            }
        }
    }
    return $in_transit;
}

// Функция для получения доставленных заявок (unload_type = 'OO')
function getDeliveredZayavki($period_start, $period_end, $flights) {
    $delivered = [];
    foreach ($flights as $flight) {
        if (empty($flight['actual_end_date'])) continue;
        if ($flight['unload_type'] !== 'OO') continue;
        $actual_date = $flight['actual_end_date'];
        if ($actual_date < $period_start || $actual_date > $period_end) continue;
        $flight_zayavki = parseZayavki($flight['zayavki_ids']);
        foreach ($flight_zayavki as $z_id) {
            if (!in_array($z_id, $delivered)) {
                $delivered[] = $z_id;
            }
        }
    }
    return $delivered;
}

// Функция для получения заявок на складе (unload_type = 'SKLAD')
function getWarehouseZayavki($period_start, $period_end, $flights) {
    $warehouse = [];
    foreach ($flights as $flight) {
        if (empty($flight['actual_end_date'])) continue;
        if ($flight['unload_type'] !== 'SKLAD') continue;
        $actual_date = $flight['actual_end_date'];
        if ($actual_date < $period_start || $actual_date > $period_end) continue;
        $flight_zayavki = parseZayavki($flight['zayavki_ids']);
        foreach ($flight_zayavki as $z_id) {
            if (!in_array($z_id, $warehouse)) {
                $warehouse[] = $z_id;
            }
        }
    }
    return $warehouse;
}

// Функция для ГЕНЕРАЦИИ плана на следующую неделю из рейсов
function generateNextWeekPlanFromFlights($period_end, $flights) {
    $next_week_start = date('Y-m-d', strtotime($period_end . ' +1 day'));
    $next_week_end = date('Y-m-d', strtotime($period_end . ' +7 days'));
    $planned_for_next_week = [];
    foreach ($flights as $flight) {
        if (!empty($flight['planned_start_date'])) {
            $planned_date = $flight['planned_start_date'];
            if ($planned_date >= $next_week_start && $planned_date <= $next_week_end) {
                $flight_zayavki = parseZayavki($flight['zayavki_ids']);
                foreach ($flight_zayavki as $z_id) {
                    if (!in_array($z_id, $planned_for_next_week)) {
                        $planned_for_next_week[] = $z_id;
                    }
                }
                continue;
            }
        }
        if (!empty($flight['planned_start_date_from'])) {
            $planned_from = $flight['planned_start_date_from'];
            if ($planned_from >= $next_week_start && $planned_from <= $next_week_end) {
                $flight_zayavki = parseZayavki($flight['zayavki_ids']);
                foreach ($flight_zayavki as $z_id) {
                    if (!in_array($z_id, $planned_for_next_week)) {
                        $planned_for_next_week[] = $z_id;
                    }
                }
            }
        }
    }
    return [
        'start' => $next_week_start,
        'end' => $next_week_end,
        'zayavki' => $planned_for_next_week,
        'is_generated' => true
    ];
}

// Функция для ПОЛУЧЕНИЯ плана на следующую неделю (сначала из БД, если нет - генерируем)
function getNextWeekPlan($period_end, $flights, $pdo) {
    $next_week_start = date('Y-m-d', strtotime($period_end . ' +1 day'));
    $next_week_end = date('Y-m-d', strtotime($period_end . ' +7 days'));
    try {
        $stmt = $pdo->prepare("SELECT * FROM plan WHERE дата_с = ? AND дата_по = ? LIMIT 1");
        $stmt->execute([$next_week_start, $next_week_end]);
        $existing_plan = $stmt->fetch();
        if ($existing_plan) {
            $zayavki = parseZayavki($existing_plan['Заявки']);
            return [
                'start' => $next_week_start,
                'end' => $next_week_end,
                'zayavki' => $zayavki,
                'is_generated' => false,
                'plan_id' => $existing_plan['id']
            ];
        }
    } catch (Exception $e) {
        // Ошибка при поиске
    }
    return generateNextWeekPlanFromFlights($period_end, $flights);
}

// Функция для СОХРАНЕНИЯ плана в таблицу plan
function savePlanToDatabase($period_start, $period_end, $zayavki_list, $pdo) {
    try {
        $zayavki_string = implode(', ', $zayavki_list);
        $stmt = $pdo->prepare("INSERT INTO plan (дата_с, дата_по, Заявки) VALUES (?, ?, ?)");
        $stmt->execute([$period_start, $period_end, $zayavki_string]);
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        return false;
    }
}

// Обработка POST запроса для подтверждения плана
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_plan'])) {
    header('Content-Type: application/json');
    try {
        $period_start = $_POST['period_start'] ?? '';
        $period_end = $_POST['period_end'] ?? '';
        $zayavki_string = $_POST['zayavki_list'] ?? '';
        if (empty($period_start) || empty($period_end) || empty($zayavki_string)) {
            throw new Exception('Недостаточно данных для сохранения плана');
        }
        $stmt = $pdo->prepare("SELECT id FROM plan WHERE дата_с = ? AND дата_по = ?");
        $stmt->execute([$period_start, $period_end]);
        if ($stmt->fetch()) {
            throw new Exception('План на этот период уже существует в базе данных');
        }
        $stmt = $pdo->prepare("INSERT INTO plan (дата_с, дата_по, Заявки) VALUES (?, ?, ?)");
        $stmt->execute([$period_start, $period_end, $zayavki_string]);
        echo json_encode(['success' => true, 'message' => 'План успешно сохранен', 'plan_id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Подсчет веса для списка заявок в КГ
function calculateTotalWeightKg($zayavki_list, $pdo) {
    if (empty($zayavki_list)) return 0;
    $total_weight_kg = 0;
    $infos = getZayavkiInfoBatch($zayavki_list, $pdo);
    foreach ($zayavki_list as $z_id) {
        $weight_tonn = isset($infos[$z_id]['mass_netto']) ? floatval($infos[$z_id]['mass_netto']) : 0;
        $total_weight_kg += $weight_tonn * 1000;
    }
    return $total_weight_kg;
}

// Глобальная статистика (только 3 блока)
$global_stats = [
    'total_planned_count' => 0,
    'total_planned_weight_kg' => 0,
    'total_actual_count' => 0,
    'total_actual_weight_kg' => 0
];
foreach ($plans as $plan) {
    $period_start = $plan['дата_с'];
    $period_end = $plan['дата_по'];
    $planned_zayavki = parseZayavki($plan['Заявки']);
    $global_stats['total_planned_count'] += count($planned_zayavki);
    $global_stats['total_planned_weight_kg'] += calculateTotalWeightKg($planned_zayavki, $pdo);
    foreach ($planned_zayavki as $z_id) {
        if (isZayavkaCompletedInPeriod($z_id, $period_start, $period_end, $flights, true)) {
            $global_stats['total_actual_count']++;
            $global_stats['total_actual_weight_kg'] += getZayavkaWeightKg($z_id, $pdo);
        }
    }
    $extra_zayavki = getExtraCompletedZayavki($period_start, $period_end, $planned_zayavki, $flights, true);
    $global_stats['total_actual_count'] += count($extra_zayavki);
    $global_stats['total_actual_weight_kg'] += calculateTotalWeightKg($extra_zayavki, $pdo);
}

// Подготовим данные для каждого плана
$plansData = [];
foreach ($plans as $plan) {
    $period_start = $plan['дата_с'];
    $period_end = $plan['дата_по'];
    $planned_zayavki = parseZayavki($plan['Заявки']);
    $completed_zayavki = [];
    $missing_zayavki = [];
    foreach ($planned_zayavki as $z_id) {
        if (isZayavkaCompletedInPeriod($z_id, $period_start, $period_end, $flights, true)) {
            $completed_zayavki[] = $z_id;
        } else {
            $missing_zayavki[] = $z_id;
        }
    }
    $extra_zayavki = getExtraCompletedZayavki($period_start, $period_end, $planned_zayavki, $flights, true);
    $in_transit_zayavki = getInTransitZayavki($period_start, $period_end, $flights);
    $delivered_zayavki = getDeliveredZayavki($period_start, $period_end, $flights);
    $warehouse_zayavki = getWarehouseZayavki($period_start, $period_end, $flights);
    $next_week = getNextWeekPlan($period_end, $flights, $pdo);
    $plansData[] = [
        'period_start' => $period_start,
        'period_end' => $period_end,
        'planned_count' => count($planned_zayavki),
        'planned_weight_kg' => calculateTotalWeightKg($planned_zayavki, $pdo),
        'completed' => $completed_zayavki,
        'completed_weight_kg' => calculateTotalWeightKg($completed_zayavki, $pdo),
        'missing' => $missing_zayavki,
        'missing_weight_kg' => calculateTotalWeightKg($missing_zayavki, $pdo),
        'extra' => $extra_zayavki,
        'extra_weight_kg' => calculateTotalWeightKg($extra_zayavki, $pdo),
        'in_transit' => $in_transit_zayavki,
        'in_transit_weight_kg' => calculateTotalWeightKg($in_transit_zayavki, $pdo),
        'delivered' => $delivered_zayavki,
        'delivered_weight_kg' => calculateTotalWeightKg($delivered_zayavki, $pdo),
        'warehouse' => $warehouse_zayavki,
        'warehouse_weight_kg' => calculateTotalWeightKg($warehouse_zayavki, $pdo),
        'next_week' => $next_week,
        'next_week_weight_kg' => calculateTotalWeightKg($next_week['zayavki'], $pdo)
    ];
}

// Генерация токена для защиты от повторной отправки
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$current_token = $_SESSION['form_token'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Планы вывоза - Аналитика выполнения</title>
<link rel="stylesheet" href="style.css">
<style>
.confirm-plan-btn { margin-top: 20px; text-align: right; }
.btn-confirm { background: #28a745; color: white; border: none; padding: 10px 24px; font-size: 14px; font-weight: 600; border-radius: 6px; cursor: pointer; transition: all 0.2s ease; }
.btn-confirm:hover { background: #218838; transform: translateY(-1px); box-shadow: 0 2px 8px rgba(40,167,69,0.3); }
.btn-confirm:disabled { background: #6c757d; cursor: not-allowed; transform: none; }
.plan-source-badge { font-size: 11px; padding: 2px 8px; border-radius: 12px; margin-left: 10px; }
.plan-source-db { background: #d4edda; color: #155724; }
.plan-source-generated { background: #fff3cd; color: #856404; }
.plan-buttons-wrapper { margin: 15px 0; display: flex; flex-wrap: wrap; gap: 10px; padding: 12px; background: #f4f6f9; border-radius: 8px; border: 1px solid #e2e8f0; }
.plan-btn { display: inline-block; padding: 8px 16px; border: 2px solid #2a5298; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.2s ease; }
.plan-btn.active { background: #2a5298; color: #ffffff; }
.plan-btn:not(.active) { background: #ffffff; color: #2a5298; }
.plan-btn:hover { background: #e2e8f0; color: #1e3a70; }
.plan-btn.active:hover { background: #1e3a70; }
.flight-number { font-weight: 700; color: #2a5298; white-space: nowrap; }
.no-flight { color: #94a3b8; }
.flights-summary-th { width: 260px !important; min-width: 260px !important; }
.flights-summary-cell {
    width: 260px !important;
    min-width: 260px !important;
    max-width: none !important;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
    word-break: normal !important;
    overflow-wrap: normal !important;
    line-height: 1.5;
}
.flights-summary-cell .flight-number,
.flights-summary-cell .no-flight {
    display: inline-block;
    margin: 0 2px 2px 0;
}

</style>
</head>
<body>
<?php include 'menuadmin.php'; ?>
<div class="app">
    <div class="toolbar">
        <h1>Аналитика взаимодействия с ФРЕГАТ за выбранный период v.5</h1>
    </div>

    <!-- КНОПКИ ВЫБОРА ID ПЛАНА -->
    <?php if (!empty($available_plan_ids)): ?>
    <div class="plan-buttons-wrapper">
        <span style="font-weight: 600; color: #475569; align-self: center; margin-right: 8px;">Выберите план:</span>
        <?php foreach ($available_plan_ids as $id => $data): 
            $btn_text = !empty($data['block_date']) ? $data['block_date'] : (date('d.m.Y', strtotime($data['дата_с'] ?? date('Y-m-d'))) . ' — ' . date('d.m.Y', strtotime($data['дата_по'] ?? date('Y-m-d'))));
            $is_active = ($id == $selected_id);
        ?>
            <a href="?plan_id=<?= $id ?>" class="plan-btn <?= $is_active ? 'active' : '' ?>">
                ID <?= $id ?>: <?= htmlspecialchars($btn_text) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Глобальная статистика -->
    <?php if (!empty($plansData)): ?>
    <div class="stats">
        <div class="stat-item">
            <div class="stat-value"><?= $global_stats['total_planned_count'] ?></div>
            <div class="stat-label">Запланировано заявок</div>
            <div class="stat-value" style="font-size: 18px;"><?= number_format($global_stats['total_planned_weight_kg'], 0, '.', ' ') ?> кг</div>
            <div class="stat-label">Плановый вес</div>
        </div>
        <div class="stat-item">
            <div class="stat-value" style="color: #28a745;"><?= $global_stats['total_actual_count'] ?></div>
            <div class="stat-label">ФАКТИЧЕСКИ ВЫВЕЗЕНО</div>
            <div class="stat-value" style="font-size: 18px; color: #28a745;"><?= number_format($global_stats['total_actual_weight_kg'], 0, '.', ' ') ?> кг</div>
            <div class="stat-label">Фактический вес</div>
        </div>
        <div class="stat-item">
            <div class="stat-value" style="color: #0969da;"><?= $global_stats['total_planned_count'] > 0 ? round($global_stats['total_actual_count'] / $global_stats['total_planned_count'] * 100, 1) : 0 ?>%</div>
            <div class="stat-label">Выполнение по кол-ву</div>
            <div class="stat-value" style="font-size: 18px; color: #0969da;"><?= $global_stats['total_planned_weight_kg'] > 0 ? round($global_stats['total_actual_weight_kg'] / $global_stats['total_planned_weight_kg'] * 100, 1) : 0 ?>%</div>
            <div class="stat-label">Выполнение по весу</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($plansData)): ?>
        <div class="card"><div style="text-align: center; padding: 40px;"><p>Нет данных о планах вывоза для выбранного ID</p></div></div>
    <?php else: ?>
    <?php foreach ($plansData as $idx => $plan): ?>
    <div class="card" style="margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #eaeef2;">
            <div>
                <strong style="font-size: 16px;">📅 Период:</strong>
                <span style="background: #2a5298; color: white; padding: 5px 12px; border-radius: 20px; margin-left: 10px; font-size: 13px;">
                    <?= date('d.m.Y', strtotime($plan['period_start'])) ?>
                </span>
                <span style="margin: 0 8px;">→</span>
                <span style="background: #6c757d; color: white; padding: 5px 12px; border-radius: 20px; font-size: 13px;">
                    <?= date('d.m.Y', strtotime($plan['period_end'])) ?>
                </span>
            </div>
        </div>

        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 200px;">ТИП</th>
                        <th>ИНФОРМАЦИЯ</th>
                        <th class="flights-summary-th" style="width: 260px; min-width: 260px; text-align: center;">РЕЙСЫ</th>
                        <th style="width: 100px; text-align: center;">КОЛ-ВО</th>
                        <th style="width: 120px; text-align: center;">ВЕС, КГ</th>
                        <th style="width: 100px; text-align: center;">ДЕЙСТВИЯ</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Блок 1: ВЫВЕЗЕНО ПО ПЛАНУ -->
                    <tr class="group-header" onclick="toggleBlock('completed_<?= $idx ?>', event)">
                        <td style="background: #d4edda;"><strong style="color: #155724;">ВЫВЕЗЕНО ПО ПЛАНУ</strong></td>
                        <td>Заявки, которые были вывезены в указанный период согласно плану</td>
                        <td class="flights-summary-cell" style="text-align: center;"><?= getFlightLabelsForZayavkiBlock($plan['completed'], $plan['period_start'], $plan['period_end'], $flights, ['actual_start_date'], null, true) ?></td>
                        <td style="text-align: center;"><?= count($plan['completed']) ?></td>
                        <td style="text-align: right;"><?= number_format($plan['completed_weight_kg'], 0, '.', ' ') ?></td>
                        <td style="text-align: center;">▶</td>
                    </tr>
                    <tr id="completed_<?= $idx ?>" style="display: none;">
                        <td colspan="6" style="padding: 0;">
                            <div class="block-details">
                                <table class="details-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">ID ЗАЯВКИ</th>
                                            <th style="width: 90px;">МАССА, КГ</th>
                                            <th style="width: 250px;">ГРУЗООТПРАВИТЕЛЬ</th>
                                            <th>АДРЕС ПОГРУЗКИ</th>
                                            <th style="width: 100px;">РЕЙС</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($plan['completed'])): ?>
                                        <?php
                                        $completed_infos = getZayavkiInfoBatch($plan['completed'], $pdo);
                                        foreach ($plan['completed'] as $z_id):
                                            $info = $completed_infos[$z_id];
                                            $mass_kg = $info ? floatval($info['mass_netto']) * 1000 : 0;
                                            $flight_number = getFlightLabelForZayavka($z_id, $plan['period_start'], $plan['period_end'], $flights, ['actual_start_date'], null, true);
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($z_id) ?></strong></td>
                                            <td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['naim_oo_gruzootpravitel'] ?? '')) ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['mno_adres_pogruzki'] ?? '')) ?></td>
                                            <td class="text-center"><?= $flight_number ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">Нет вывезенных заявок</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>

                    <!-- Блок 2: ВЫВЕЗЕНО ДОПОЛНИТЕЛЬНО -->
                    <tr class="group-header" onclick="toggleBlock('extra_<?= $idx ?>', event)">
                        <td style="background: #d1ecf1;"><strong style="color: #0c5460;">ДОП. ВЫВОЗ</strong></td>
                        <td>Заявки, которые были вывезены, но не были запланированы</td>
                        <td class="flights-summary-cell" style="text-align: center;"><?= getFlightLabelsForZayavkiBlock($plan['extra'], $plan['period_start'], $plan['period_end'], $flights, ['actual_start_date'], null, true) ?></td>
                        <td style="text-align: center;"><?= count($plan['extra']) ?></td>
                        <td style="text-align: right;"><?= number_format($plan['extra_weight_kg'], 0, '.', ' ') ?></td>
                        <td style="text-align: center;">▶</td>
                    </tr>
                    <tr id="extra_<?= $idx ?>" style="display: none;">
                        <td colspan="6" style="padding: 0;">
                            <div class="block-details">
                                <table class="details-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">ID ЗАЯВКИ</th>
                                            <th style="width: 90px;">МАССА, КГ</th>
                                            <th style="width: 250px;">ГРУЗООТПРАВИТЕЛЬ</th>
                                            <th>АДРЕС ПОГРУЗКИ</th>
                                            <th style="width: 100px;">РЕЙС</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($plan['extra'])): ?>
                                        <?php
                                        $extra_infos = getZayavkiInfoBatch($plan['extra'], $pdo);
                                        foreach ($plan['extra'] as $z_id):
                                            $info = $extra_infos[$z_id];
                                            $mass_kg = $info ? floatval($info['mass_netto']) * 1000 : 0;
                                            $flight_number = getFlightLabelForZayavka($z_id, $plan['period_start'], $plan['period_end'], $flights, ['actual_start_date'], null, true);
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($z_id) ?></strong></td>
                                            <td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['naim_oo_gruzootpravitel'] ?? '')) ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['mno_adres_pogruzki'] ?? '')) ?></td>
                                            <td class="text-center"><?= $flight_number ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">Нет дополнительных вывозов</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>

                    <tr style="background: #f8f9fa; height: 30px;"><td colspan="6" style="padding: 0px; border-bottom: 0px solid #FFFFFF;">&nbsp;</td></tr>

                    <!-- Блок 3: НЕ ВЫВЕЗЕНО -->
                    <tr class="group-header" onclick="toggleBlock('missing_<?= $idx ?>', event)">
                        <td style="background: #f8d7da;"><strong style="color: #721c24;">НЕ ВЫВЕЗЕНО</strong></td>
                        <td>Заявки, которые были запланированы, но не вывезены</td>
                        <td class="flights-summary-cell" style="text-align: center;"><span class="no-flight">—</span></td>
                        <td style="text-align: center;"><?= count($plan['missing']) ?></td>
                        <td style="text-align: right;"><?= number_format($plan['missing_weight_kg'], 0, '.', ' ') ?></td>
                        <td style="text-align: center;">▶</td>
                    </tr>
                    <tr id="missing_<?= $idx ?>" style="display: none;">
                        <td colspan="6" style="padding: 0;">
                            <div class="block-details">
                                <table class="details-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">ID ЗАЯВКИ</th>
                                            <th style="width: 90px;">МАССА, КГ</th>
                                            <th style="width: 250px;">ГРУЗООТПРАВИТЕЛЬ</th>
                                            <th>АДРЕС ПОГРУЗКИ</th>
                                            <th style="width: 100px;">РЕЙС</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($plan['missing'])): ?>
                                        <?php
                                        $missing_infos = getZayavkiInfoBatch($plan['missing'], $pdo);
                                        foreach ($plan['missing'] as $z_id):
                                            $info = $missing_infos[$z_id];
                                            $mass_kg = $info ? floatval($info['mass_netto']) * 1000 : 0;
                                            $flight_number = '<span class="no-flight">—</span>';
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($z_id) ?></strong></td>
                                            <td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['naim_oo_gruzootpravitel'] ?? '')) ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['mno_adres_pogruzki'] ?? '')) ?></td>
                                            <td class="text-center"><?= $flight_number ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">Нет невывезенных заявок</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>

                    <tr style="background: #f8f9fa; height: 30px;"><td colspan="6" style="padding: 0px; border-bottom: 0px solid #FFFFFF;">&nbsp;</td></tr>

                    <!-- Блок 4: ЗАЯВКИ В ПУТИ -->
                    <tr class="group-header" onclick="toggleBlock('intransit_<?= $idx ?>', event)">
                        <td style="background: #d4d4d4;"><strong style="color: #000000;">ЗАЯВКИ В ПУТИ</strong></td>
                        <td>Заявки, вывоз которых начался, но еще не завершен</td>
                        <td class="flights-summary-cell" style="text-align: center;"><?= getFlightLabelsForZayavkiBlock($plan['in_transit'], $plan['period_start'], $plan['period_end'], $flights, ['actual_start_date']) ?></td>
                        <td style="text-align: center;"><?= count($plan['in_transit']) ?></td>
                        <td style="text-align: right;"><?= number_format($plan['in_transit_weight_kg'], 0, '.', ' ') ?></td>
                        <td style="text-align: center;">▶</td>
                    </tr>
                    <tr id="intransit_<?= $idx ?>" style="display: none;">
                        <td colspan="6" style="padding: 0;">
                            <div class="block-details">
                                <table class="details-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">ID ЗАЯВКИ</th>
                                            <th style="width: 90px;">МАССА, КГ</th>
                                            <th style="width: 250px;">ГРУЗООТПРАВИТЕЛЬ</th>
                                            <th>АДРЕС ПОГРУЗКИ</th>
                                            <th style="width: 100px;">РЕЙС</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($plan['in_transit'])): ?>
                                        <?php
                                        $intransit_infos = getZayavkiInfoBatch($plan['in_transit'], $pdo);
                                        foreach ($plan['in_transit'] as $z_id):
                                            $info = $intransit_infos[$z_id];
                                            $mass_kg = $info ? floatval($info['mass_netto']) * 1000 : 0;
                                            $flight_number = getFlightLabelForZayavka($z_id, $plan['period_start'], $plan['period_end'], $flights, ['actual_start_date'], null, true);
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($z_id) ?></strong></td>
                                            <td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['naim_oo_gruzootpravitel'] ?? '')) ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['mno_adres_pogruzki'] ?? '')) ?></td>
                                            <td class="text-center"><?= $flight_number ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">Нет заявок в пути</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>

                    <!-- Блок 5: ЗАЯВКИ НА СКЛАДЕ -->
                    <tr class="group-header" onclick="toggleBlock('warehouse_<?= $idx ?>', event)">
                        <td style="background: #d4d4d4;"><strong style="color: #000000;">ЗАЯВКИ НА СКЛАДЕ</strong></td>
                        <td>Заявки, доставленные на склад консолидации</td>
                        <td class="flights-summary-cell" style="text-align: center;"><?= getFlightLabelsForZayavkiBlock($plan['warehouse'], $plan['period_start'], $plan['period_end'], $flights, ['actual_end_date'], 'SKLAD') ?></td>
                        <td style="text-align: center;"><?= count($plan['warehouse']) ?></td>
                        <td style="text-align: right;"><?= number_format($plan['warehouse_weight_kg'], 0, '.', ' ') ?></td>
                        <td style="text-align: center;">▶</td>
                    </tr>
                    <tr id="warehouse_<?= $idx ?>" style="display: none;">
                        <td colspan="6" style="padding: 0;">
                            <div class="block-details">
                                <table class="details-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">ID ЗАЯВКИ</th>
                                            <th style="width: 90px;">МАССА, КГ</th>
                                            <th style="width: 250px;">ГРУЗООТПРАВИТЕЛЬ</th>
                                            <th>АДРЕС ПОГРУЗКИ</th>
                                            <th style="width: 100px;">РЕЙС</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($plan['warehouse'])): ?>
                                        <?php
                                        $warehouse_infos = getZayavkiInfoBatch($plan['warehouse'], $pdo);
                                        foreach ($plan['warehouse'] as $z_id):
                                            $info = $warehouse_infos[$z_id];
                                            $mass_kg = $info ? floatval($info['mass_netto']) * 1000 : 0;
                                            $flight_number = getFlightLabelForZayavka($z_id, $plan['period_start'], $plan['period_end'], $flights, ['actual_end_date'], 'SKLAD');
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($z_id) ?></strong></td>
                                            <td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['naim_oo_gruzootpravitel'] ?? '')) ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['mno_adres_pogruzki'] ?? '')) ?></td>
                                            <td class="text-center"><?= $flight_number ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">Нет заявок на складе</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>

                    <!-- Блок 6: ЗАЯВОК ДОСТАВЛЕНО -->
                    <tr class="group-header" onclick="toggleBlock('delivered_<?= $idx ?>', event)">
                        <td style="background: #d4d4d4;"><strong style="color: #000000;">ЗАЯВОК ДОСТАВЛЕНО</strong></td>
                        <td>Заявки, доставленные утилизатору</td>
                        <td class="flights-summary-cell" style="text-align: center;"><?= getFlightLabelsForZayavkiBlock($plan['delivered'], $plan['period_start'], $plan['period_end'], $flights, ['actual_end_date'], 'OO') ?></td>
                        <td style="text-align: center;"><?= count($plan['delivered']) ?></td>
                        <td style="text-align: right;"><?= number_format($plan['delivered_weight_kg'], 0, '.', ' ') ?></td>
                        <td style="text-align: center;">▶</td>
                    </tr>
                    <tr id="delivered_<?= $idx ?>" style="display: none;">
                        <td colspan="6" style="padding: 0;">
                            <div class="block-details">
                                <table class="details-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">ID ЗАЯВКИ</th>
                                            <th style="width: 90px;">МАССА, КГ</th>
                                            <th style="width: 250px;">ГРУЗООТПРАВИТЕЛЬ</th>
                                            <th>АДРЕС ПОГРУЗКИ</th>
                                            <th style="width: 100px;">РЕЙС</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($plan['delivered'])): ?>
                                        <?php
                                        $delivered_infos = getZayavkiInfoBatch($plan['delivered'], $pdo);
                                        foreach ($plan['delivered'] as $z_id):
                                            $info = $delivered_infos[$z_id];
                                            $mass_kg = $info ? floatval($info['mass_netto']) * 1000 : 0;
                                            $flight_number = getFlightLabelForZayavka($z_id, $plan['period_start'], $plan['period_end'], $flights, ['actual_end_date'], 'OO');
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($z_id) ?></strong></td>
                                            <td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['naim_oo_gruzootpravitel'] ?? '')) ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['mno_adres_pogruzki'] ?? '')) ?></td>
                                            <td class="text-center"><?= $flight_number ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">Нет доставленных заявок</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>

                    <tr style="background: #f8f9fa; height: 30px;"><td colspan="6" style="padding: 0px; border-bottom: 0px solid #FFFFFF;">&nbsp;</td></tr>

                    <!-- Блок 7: ПЛАНЫ НА СЛЕДУЮЩУЮ НЕДЕЛЮ -->
                    <tr class="group-header" onclick="toggleBlock('nextweek_<?= $idx ?>', event)">
                        <td style="background: #fff3cd;">
                            <strong style="color: #856404;">📅 ПЛАНЫ НА СЛЕД. НЕДЕЛЮ</strong>
                            <?php if (!$plan['next_week']['is_generated']): ?>
                                <span class="plan-source-badge plan-source-db">📁 из БД</span>
                            <?php else: ?>
                                <span class="plan-source-badge plan-source-generated">⚡ сгенерирован</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            Заявки, запланированные к вывозу на следующей неделе<br>
                            <small><?= date('d.m.Y', strtotime($plan['next_week']['start'])) ?> — <?= date('d.m.Y', strtotime($plan['next_week']['end'])) ?></small>
                            <?php if ($plan['next_week']['is_generated']): ?>
                                <br><small style="color: #856404;">⚠️ План сгенерирован автоматически. Нажмите "Подтвердить план" для сохранения.</small>
                            <?php endif; ?>
                        </td>
                        <td class="flights-summary-cell" style="text-align: center;"><?= getFlightLabelsForZayavkiBlock($plan['next_week']['zayavki'], $plan['next_week']['start'], $plan['next_week']['end'], $flights, ['planned_start_date', 'planned_start_date_from']) ?></td>
                        <td style="text-align: center;"><?= count($plan['next_week']['zayavki']) ?></td>
                        <td style="text-align: right;"><?= number_format($plan['next_week_weight_kg'], 0, '.', ' ') ?></td>
                        <td style="text-align: center;">▶</td>
                    </tr>
                    <tr id="nextweek_<?= $idx ?>" style="display: none;">
                        <td colspan="6" style="padding: 0;">
                            <div class="block-details">
                                <table class="details-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">ID ЗАЯВКИ</th>
                                            <th style="width: 90px;">МАССА, КГ</th>
                                            <th style="width: 250px;">ГРУЗООТПРАВИТЕЛЬ</th>
                                            <th>АДРЕС ПОГРУЗКИ</th>
                                            <th style="width: 100px;">РЕЙС</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($plan['next_week']['zayavki'])): ?>
                                        <?php
                                        $nextweek_infos = getZayavkiInfoBatch($plan['next_week']['zayavki'], $pdo);
                                        foreach ($plan['next_week']['zayavki'] as $z_id):
                                            $info = $nextweek_infos[$z_id];
                                            $mass_kg = $info ? floatval($info['mass_netto']) * 1000 : 0;
                                            $flight_number = getFlightLabelForZayavka($z_id, $plan['next_week']['start'], $plan['next_week']['end'], $flights, ['planned_start_date', 'planned_start_date_from']);
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($z_id) ?></strong></td>
                                            <td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['naim_oo_gruzootpravitel'] ?? '')) ?></td>
                                            <td style="text-align: left;"><?= htmlspecialchars(limitText50($info['mno_adres_pogruzki'] ?? '')) ?></td>
                                            <td class="text-center"><?= $flight_number ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">Нет запланированных рейсов</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Кнопка ПОДТВЕРДИТЬ ПЛАН -->
        <?php if ($plan['next_week']['is_generated'] && !empty($plan['next_week']['zayavki'])): ?>
        <div class="confirm-plan-btn">
            <button type="button" class="btn-confirm" onclick="confirmPlan('<?= $plan['next_week']['start'] ?>', '<?= $plan['next_week']['end'] ?>', '<?= htmlspecialchars(implode(', ', $plan['next_week']['zayavki'])) ?>', this)">
                ✅ ПОДТВЕРДИТЬ ПЛАН НА СЛЕДУЮЩУЮ НЕДЕЛЮ
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Модальное окно подтверждения -->
<div id="confirmModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Подтверждение плана</h3>
            <span class="close" onclick="closeConfirmModal()">&times;</span>
        </div>
        <div id="confirmModalContent">
            <p>Вы уверены, что хотите сохранить этот план в базу данных?</p>
            <p><strong>Период:</strong> <span id="confirmPeriod"></span></p>
            <p><strong>Количество заявок:</strong> <span id="confirmCount"></span></p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeConfirmModal()">Отмена</button>
                <button class="btn btn-primary" id="confirmSaveBtn">Сохранить</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentConfirmData = null;
let currentButton = null;

function confirmPlan(startDate, endDate, zayavkiList, button) {
    const zayavkiArray = zayavkiList.split(', ').filter(z => z);
    document.getElementById('confirmPeriod').innerText = startDate + ' → ' + endDate;
    document.getElementById('confirmCount').innerText = zayavkiArray.length;
    currentConfirmData = { period_start: startDate, period_end: endDate, zayavki_list: zayavkiList };
    currentButton = button;
    document.getElementById('confirmModal').classList.add('show');
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('show');
    currentConfirmData = null;
    currentButton = null;
}

document.getElementById('confirmSaveBtn').addEventListener('click', function() {
    if (!currentConfirmData) return;
    const saveBtn = this;
    saveBtn.disabled = true;
    saveBtn.textContent = 'Сохранение...';
    const formData = new FormData();
    formData.append('confirm_plan', '1');
    formData.append('period_start', currentConfirmData.period_start);
    formData.append('period_end', currentConfirmData.period_end);
    formData.append('zayavki_list', currentConfirmData.zayavki_list);
    formData.append('form_token', '<?= $current_token ?>');
    fetch('', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ План успешно сохранен! Страница будет обновлена.');
            location.reload();
        } else {
            alert('❌ Ошибка: ' + data.message);
            saveBtn.disabled = false;
            saveBtn.textContent = 'Сохранить';
        }
    })
    .catch(error => {
        alert('Ошибка при сохранении: ' + error.message);
        saveBtn.disabled = false;
        saveBtn.textContent = 'Сохранить';
    });
});

window.onclick = function(event) {
    const modal = document.getElementById('confirmModal');
    if (event.target === modal) closeConfirmModal();
}

function toggleBlock(blockId, event) {
    if (event) event.stopPropagation();
    const block = document.getElementById(blockId);
    const headerRow = block.previousElementSibling;
    const arrow = headerRow.querySelector('td:last-child');
    if (block.style.display === 'none' || block.style.display === '') {
        block.style.display = 'table-row';
        if (arrow) arrow.innerHTML = '▼';
    } else {
        block.style.display = 'none';
        if (arrow) arrow.innerHTML = '▶';
    }
}
</script>
</body>
</html>
