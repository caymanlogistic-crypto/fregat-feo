<?php
/**
 * price.php
 * Скрипт для получения стоимости заявок (БЕЗ сохранения в БД)
 * Использование: require_once 'price.php';
 * 
 * Режим отладки: price.php?d=1 - выводит все массивы в таблицах
 * 
 * Доступные массивы:
 * - $price - массив [zayavka_id => cost]
 * - $price_with_details - массив с деталями [zayavka_id => [
 *      'cost' => стоимость,
 *      'dogovor_number' => номер договора,
 *      'weight_kg' => вес в кг,
 *      'price_per_kg' => цена за 1 кг,
 *      'route_id' => ID маршрута
 * ]]
 */

// Предотвращаем повторное подключение
if (defined('PRICE_SCRIPT_LOADED')) {
    return;
}
define('PRICE_SCRIPT_LOADED', true);

/**
 * Функция для получения стоимости по одной заявке
 */
function getCostByZayavkaId($pdo, $zayavkaId) {
    $costs = getAllCosts($pdo);
    return isset($costs[$zayavkaId]) ? $costs[$zayavkaId]['cost'] : null;
}

/**
 * Функция для получения массива цен по списку заявок
 */
function getCostsByZayavkaIds($pdo, array $zayavkaIds) {
    if (empty($zayavkaIds)) {
        return [];
    }
    
    $allCosts = getAllCosts($pdo);
    $filtered = [];
    
    foreach ($zayavkaIds as $zId) {
        if (isset($allCosts[$zId])) {
            $filtered[$zId] = $allCosts[$zId];
        }
    }
    
    return $filtered;
}

/**
 * ОСНОВНАЯ ФУНКЦИЯ: Рассчитывает стоимость на основе блоков
 * Без сохранения в БД, только возвращает массивы
 */
function calculateCostsFromBlocks($pdo) {
    // Получаем все блоки с маршрутами и стоимостью (без поля name)
    $stmt = $pdo->prepare("
        SELECT id, zayavki_ids, cost 
        FROM status_blocks 
        WHERE status_type = 'marshrut' AND cost IS NOT NULL AND cost > 0 AND zayavki_ids != ''
    ");
    $stmt->execute();
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Результаты: [zayavka_id => ['cost' => ..., 'weight_kg' => ..., 'dogovor' => ..., 'route_id' => ...]]
    $results = [];
    $debugBlocks = [];
    
    foreach ($blocks as $block) {
        $blockId = $block['id'];
        $zayavkiIdsBlock = explode(',', $block['zayavki_ids']);
        $zayavkiIdsBlock = array_map('trim', $zayavkiIdsBlock);
        $zayavkiIdsBlock = array_filter($zayavkiIdsBlock);
        $blockCost = floatval($block['cost']);
        
        if (empty($zayavkiIdsBlock)) continue;
        
        // Получаем веса и договоры заявок из feo
        $placeholders = implode(',', array_fill(0, count($zayavkiIdsBlock), '?'));
        $stmtFeo = $pdo->prepare("
            SELECT zayavka_id, mass_netto, nomer_dogovora 
            FROM feo 
            WHERE zayavka_id IN ($placeholders)
        ");
        $stmtFeo->execute($zayavkiIdsBlock);
        $zayavkiData = $stmtFeo->fetchAll(PDO::FETCH_ASSOC);
        
        // Формируем массив весов
        $zayavkiWeights = [];
        foreach ($zayavkiData as $row) {
            $weightKg = floatval($row['mass_netto']) * 1000;
            $zayavkiWeights[$row['zayavka_id']] = [
                'weight_kg' => $weightKg,
                'dogovor' => $row['nomer_dogovora'] ?? null
            ];
        }
        
        // Собираем только валидные заявки (с весом > 0)
        $totalWeightKg = 0;
        $validZayavki = [];
        foreach ($zayavkiIdsBlock as $zId) {
            if (isset($zayavkiWeights[$zId]) && $zayavkiWeights[$zId]['weight_kg'] > 0) {
                $weight = $zayavkiWeights[$zId]['weight_kg'];
                $totalWeightKg += $weight;
                $validZayavki[] = [
                    'zayavka_id' => $zId,
                    'weight_kg' => $weight,
                    'dogovor' => $zayavkiWeights[$zId]['dogovor']
                ];
            }
        }
        
        if ($totalWeightKg <= 0 || empty($validZayavki)) continue;
        
        // Распределяем стоимость пропорционально весу
        $integerCosts = [];
        $sumInteger = 0;
        
        // Находим заявку с максимальным весом
        $maxWeightIndex = 0;
        $maxWeight = 0;
        foreach ($validZayavki as $idx => $item) {
            if ($item['weight_kg'] > $maxWeight) {
                $maxWeight = $item['weight_kg'];
                $maxWeightIndex = $idx;
            }
        }
        
        // Рассчитываем cost для всех, кроме заявки с макс. весом
        foreach ($validZayavki as $idx => $item) {
            if ($idx == $maxWeightIndex) {
                continue;
            }
            
            $exactCost = ($item['weight_kg'] / $totalWeightKg) * $blockCost;
            $integerCost = floor($exactCost);
            
            $integerCosts[] = [
                'zayavka_id' => $item['zayavka_id'],
                'weight_kg' => $item['weight_kg'],
                'dogovor' => $item['dogovor'],
                'cost' => $integerCost,
                'block_id' => $blockId,
                'route_id' => $blockId,
                'price_per_kg' => $blockCost / $totalWeightKg
            ];
            $sumInteger += $integerCost;
        }
        
        // Остаток отдаем заявке с макс. весом
        $maxCost = $blockCost - $sumInteger;
        $maxCost = round($maxCost, 2);
        
        $maxItem = $validZayavki[$maxWeightIndex];
        $integerCosts[] = [
            'zayavka_id' => $maxItem['zayavka_id'],
            'weight_kg' => $maxItem['weight_kg'],
            'dogovor' => $maxItem['dogovor'],
            'cost' => $maxCost,
            'block_id' => $blockId,
            'route_id' => $blockId,
            'price_per_kg' => $blockCost / $totalWeightKg
        ];
        
        $debugBlocks[$blockId] = [
            'total_cost' => $blockCost,
            'total_weight_kg' => $totalWeightKg,
            'route_id' => $blockId,
            'distributions' => $integerCosts
        ];
        
        // Агрегируем результаты
        foreach ($integerCosts as $item) {
            $zId = $item['zayavka_id'];
            
            if (!isset($results[$zId])) {
                $results[$zId] = [
                    'cost' => 0,
                    'weight_kg' => 0,
                    'dogovor_number' => $item['dogovor'],
                    'price_per_kg' => $item['price_per_kg'],
                    'route_id' => (string)$item['route_id'],
                    'blocks' => []
                ];
            } else {
                // Если заявка в нескольких блоках, объединяем ID маршрутов
                $results[$zId]['route_id'] .= ', ' . $item['route_id'];
            }
            
            $results[$zId]['cost'] += $item['cost'];
            $results[$zId]['weight_kg'] += $item['weight_kg'];
            $results[$zId]['blocks'][] = $item['block_id'];
            
            // Обновляем price_per_kg (средневзвешенная)
            if (count($results[$zId]['blocks']) > 1) {
                $results[$zId]['price_per_kg'] = $results[$zId]['cost'] / $results[$zId]['weight_kg'];
            }
        }
    }
    
    return ['results' => $results, 'debug_blocks' => $debugBlocks];
}

/**
 * Функция для получения всех цен
 */
function getAllCosts($pdo) {
    $data = calculateCostsFromBlocks($pdo);
    return $data['results'];
}

/**
 * Отладочная функция
 */
function debugOutput($pdo) {
    $data = calculateCostsFromBlocks($pdo);
    $results = $data['results'];
    $debugBlocks = $data['debug_blocks'];
    
    echo '<!DOCTYPE html>';
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>Отладка price.php</title>';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }';
    echo 'h1, h2 { color: #333; }';
    echo 'h3 { color: #666; margin-top: 20px; }';
    echo 'table { border-collapse: collapse; width: 100%; margin-bottom: 20px; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }';
    echo 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
    echo 'th { background-color: #4CAF50; color: white; }';
    echo 'tr:nth-child(even) { background-color: #f2f2f2; }';
    echo 'tr:hover { background-color: #ddd; }';
    echo '.summary { background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 20px 0; }';
    echo '.cost { font-weight: bold; color: #d32f2f; }';
    echo '.weight { font-weight: bold; color: #1976d2; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    echo '<h1>Отладка price.php</h1>';
    echo '<div class="summary">';
    echo '<strong>Информация:</strong><br>';
    echo 'Всего заявок с расчётом: ' . count($results) . '<br>';
    echo 'Всего блоков обработано: ' . count($debugBlocks) . '<br>';
    echo 'Время расчёта: ' . date('Y-m-d H:i:s');
    echo '</div>';
    
    // Таблица 1: Простой массив $price
    echo '<h2>1. Простой массив $price [zayavka_id => cost]</h2>';
    echo '<table border="1">';
    echo '气垫<th>zayavka_id</th><th>cost</th></tr>';
    foreach ($results as $zayavkaId => $details) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($zayavkaId) . '</td>';
        echo '<td class="cost">' . number_format($details['cost'], 2, '.', '') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    // Таблица 2: Детальный массив $price_with_details
    echo '<h2>2. Детальный массив $price_with_details</h2>';
    echo '<table border="1">';
    echo ' |
<th>zayavka_id</th>';
    echo '<th>cost</th>';
    echo '<th>weight_kg</th>';
    echo '<th>price_per_kg</th>';
    echo '<th>dogovor_number</th>';
    echo '<th>route_id</th>';
    echo '</tr>';
    
    foreach ($results as $zayavkaId => $details) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($zayavkaId) . '</td>';
        echo '<td class="cost">' . number_format($details['cost'], 2, '.', '') . '</td>';
        echo '<td class="weight">' . number_format($details['weight_kg'], 0, '.', '') . '</td>';
        echo '<td>' . number_format($details['price_per_kg'], 2, '.', '') . '</td>';
        echo '<td>' . htmlspecialchars($details['dogovor_number'] ?? '—') . '</td>';
        echo '<td>' . htmlspecialchars($details['route_id'] ?? '—') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    // Таблица 3: Детализация по блокам
    echo '<h2>3. Детализация по блокам</h2>';
    foreach ($debugBlocks as $blockId => $blockData) {
        echo '<h3>Блок (маршрут) #' . htmlspecialchars($blockId) . '</h3>';
        echo '<table border="1">';
        echo ' |
<th>zayavka_id</th>';
        echo '<th>weight_kg</th>';
        echo '<th>percent</th>';
        echo '<th>cost</th>';
        echo '<th>price_per_kg</th>';
        echo '<th>dogovor_number</th>';
        echo '</tr>';
        
        foreach ($blockData['distributions'] as $dist) {
            $percentage = ($dist['weight_kg'] / $blockData['total_weight_kg']) * 100;
            echo '<tr>';
            echo '<td>' . htmlspecialchars($dist['zayavka_id']) . '</td>';
            echo '<td>' . number_format($dist['weight_kg'], 0, '.', '') . '</td>';
            echo '<td>' . number_format($percentage, 1) . '%</td>';
            echo '<td class="cost">' . number_format($dist['cost'], 2, '.', '') . '</td>';
            echo '<td>' . number_format($dist['price_per_kg'], 2, '.', '') . '</td>';
            echo '<td>' . htmlspecialchars($dist['dogovor'] ?? '—') . '</td>';
            echo '</tr>';
        }
        
        echo '<tr style="background: #e8f5e9; font-weight: bold;">';
        echo '<td colspan="2">ИТОГО:</td>';
        echo '<td>100%</td>';
        echo '<td class="cost">' . number_format($blockData['total_cost'], 2, '.', '') . '</td>';
        echo '<td colspan="2"></td>';
        echo '</tr>';
        echo '</table>';
    }
    
    echo '</body>';
    echo '</html>';
}

// ==================== ОСНОВНАЯ ЛОГИКА ====================

// Подключаемся к БД
if (!isset($pdo) && !isset($GLOBALS['pdo'])) {
    require_once 'config.php';
    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
            $dbConfig['username'],
            $dbConfig['password']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        if (isset($_GET['d']) && $_GET['d'] == 1) {
            die("Ошибка подключения: " . $e->getMessage());
        }
        die("Ошибка подключения к БД");
    }
} elseif (isset($GLOBALS['pdo'])) {
    $pdo = $GLOBALS['pdo'];
}

// РЕЖИМ ОТЛАДКИ
if (isset($_GET['d']) && $_GET['d'] == 1) {
    debugOutput($pdo);
    exit;
}

// Генерируем данные
$allCostsData = getAllCosts($pdo);

// Формируем массив $price
$price = [];
foreach ($allCostsData as $zayavkaId => $details) {
    $price[$zayavkaId] = $details['cost'];
}

// Формируем массив $price_with_details с новыми полями
$price_with_details = [];
foreach ($allCostsData as $zayavkaId => $details) {
    $price_with_details[$zayavkaId] = [
        'cost' => $details['cost'],               // стоимость
        'weight_kg' => $details['weight_kg'],     // вес в кг
        'price_per_kg' => $details['price_per_kg'], // цена за 1 кг
        'dogovor_number' => $details['dogovor_number'], // номер договора
        'route_id' => $details['route_id']        // ID маршрута (блока)
    ];
}

// Массив для цены за кг
$price_per_kg = [];
foreach ($allCostsData as $zayavkaId => $details) {
    $price_per_kg[$zayavkaId] = $details['price_per_kg'] ?? null;
}

// Функция-помощник для быстрого получения цены
function getPrice($zayavkaId, $default = 0) {
    global $price;
    return isset($price[$zayavkaId]) ? $price[$zayavkaId] : $default;
}

// Функция для получения ID маршрута по ID заявки
function getRouteId($zayavkaId, $default = '') {
    global $price_with_details;
    return isset($price_with_details[$zayavkaId]['route_id']) ? $price_with_details[$zayavkaId]['route_id'] : $default;
}
?>