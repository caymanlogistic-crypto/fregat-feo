<?php
require_once 'config.php';

try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}

// ==================== СОЗДАНИЕ ТАБЛИЦЫ ====================
$createTableSQL = "
CREATE TABLE IF NOT EXISTS `zayavki_costs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `zayavka_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
    `cost` decimal(15,2) NOT NULL,
    `dogovor_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `block_id` int(11) DEFAULT NULL,
    `price_per_kg` decimal(15,6) DEFAULT NULL,
    `weight_kg` decimal(15,3) DEFAULT NULL,
    `calculated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_zayavka_block` (`zayavka_id`, `block_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$pdo->exec($createTableSQL);

// ==================== ФУНКЦИЯ ОБНОВЛЕНИЯ ====================
function updateCalculations($pdo) {
    $stmt = $pdo->prepare("
        SELECT id, zayavki_ids, cost 
        FROM status_blocks 
        WHERE status_type = 'marshrut' AND cost IS NOT NULL AND cost > 0 AND zayavki_ids != ''
    ");
    $stmt->execute();
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalProcessed = 0;
    
    foreach ($blocks as $block) {
        $blockId = $block['id'];
        $zayavkiIds = explode(',', $block['zayavki_ids']);
        $zayavkiIds = array_map('trim', $zayavkiIds);
        $zayavkiIds = array_filter($zayavkiIds);
        $blockCost = floatval($block['cost']);
        
        if (empty($zayavkiIds)) continue;
        
        // Получаем веса заявок из feo
        $placeholders = implode(',', array_fill(0, count($zayavkiIds), '?'));
        $stmtFeo = $pdo->prepare("
            SELECT zayavka_id, mass_netto, nomer_dogovora 
            FROM feo 
            WHERE zayavka_id IN ($placeholders)
        ");
        $stmtFeo->execute($zayavkiIds);
        $zayavkiData = $stmtFeo->fetchAll(PDO::FETCH_ASSOC);
        
        $zayavkiWeights = [];
        foreach ($zayavkiData as $row) {
            $weightKg = floatval($row['mass_netto']) * 1000;
            $zayavkiWeights[$row['zayavka_id']] = [
                'weight_kg' => $weightKg,
                'dogovor' => $row['nomer_dogovora'] ?? null
            ];
        }
        
        // Формируем массив валидных заявок
        $totalWeightKg = 0;
        $validZayavki = [];
        foreach ($zayavkiIds as $zId) {
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
        
        // === НОВОЕ УМНОЕ ОКРУГЛЕНИЕ: ВСЕ ЗАЯВКИ БЕЗ КОПЕЕК ===
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
        
        // Рассчитываем стоимость для ВСЕХ заявок (кроме самой большой) - только целые рубли
        foreach ($validZayavki as $idx => $item) {
            if ($idx == $maxWeightIndex) {
                // Самую большую пока пропускаем
                continue;
            }
            
            // Точная стоимость
            $exactCost = ($item['weight_kg'] / $totalWeightKg) * $blockCost;
            // Округляем ВНИЗ до целого рубля (отбрасываем копейки)
            $integerCost = floor($exactCost);
            
            $integerCosts[] = [
                'zayavka_id' => $item['zayavka_id'],
                'weight_kg' => $item['weight_kg'],
                'dogovor' => $item['dogovor'],
                'cost' => $integerCost,
                'exact_cost' => $exactCost
            ];
            $sumInteger += $integerCost;
        }
        
        // Стоимость для САМОЙ БОЛЬШОЙ заявки = остаток
        $maxCost = $blockCost - $sumInteger;
        $maxCost = round($maxCost, 2); // Может быть с копейками или без
        
        // Добавляем самую большую заявку
        $maxItem = $validZayavki[$maxWeightIndex];
        $integerCosts[] = [
            'zayavka_id' => $maxItem['zayavka_id'],
            'weight_kg' => $maxItem['weight_kg'],
            'dogovor' => $maxItem['dogovor'],
            'cost' => $maxCost,
            'exact_cost' => $maxCost
        ];
        
        // Сохраняем результаты
        $pricePerKg = $blockCost / $totalWeightKg;
        
        foreach ($integerCosts as $item) {
            $stmtInsert = $pdo->prepare("
                INSERT INTO zayavki_costs 
                (zayavka_id, cost, dogovor_number, block_id, price_per_kg, weight_kg, calculated_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    cost = VALUES(cost),
                    dogovor_number = VALUES(dogovor_number),
                    block_id = VALUES(block_id),
                    price_per_kg = VALUES(price_per_kg),
                    weight_kg = VALUES(weight_kg),
                    calculated_at = NOW()
            ");
            
            $stmtInsert->execute([
                $item['zayavka_id'],
                $item['cost'],
                $item['dogovor'],
                $blockId,
                $pricePerKg,
                $item['weight_kg']
            ]);
            
            $totalProcessed++;
        }
    }
    
    file_put_contents('last_update.txt', date('Y-m-d H:i:s'));
    return $totalProcessed;
}

// ==================== ОБРАБОТКА ЗАПРОСА ====================
$message = '';
if (isset($_POST['update_costs'])) {
    try {
        $count = updateCalculations($pdo);
        $message = "Обновлено! Обработано заявок: $count";
    } catch (Exception $e) {
        $message = "Ошибка: " . $e->getMessage();
    }
}

$lastUpdate = 'Никогда';
if (file_exists('last_update.txt')) {
    $lastUpdate = file_get_contents('last_update.txt');
}

$stmt = $pdo->query("
    SELECT zc.id, zc.zayavka_id, zc.cost, zc.dogovor_number, 
           zc.block_id, zc.price_per_kg, zc.weight_kg, zc.calculated_at,
           sb.status_value as marshrut_name
    FROM zayavki_costs zc
    LEFT JOIN status_blocks sb ON zc.block_id = sb.id
    ORDER BY zc.calculated_at DESC, zc.id DESC
");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Расчёт стоимости заявок</title>
</head>
<body>

<h2>Расчёт стоимости заявок по блокам (цена за 1 кг)</h2>

<?php if ($message): ?>
    <p><strong><?php echo htmlspecialchars($message); ?></strong></p>
<?php endif; ?>

<form method="post" style="margin-bottom: 10px;">
    <button type="submit" name="update_costs">ОБНОВИТЬ</button>
</form>

<p>Последнее обновление: <?php echo htmlspecialchars($lastUpdate); ?></p>

<table border="1" cellpadding="5" cellspacing="0">
    <thead>
        <tr>
            <th>ID записи</th>
            <th>Заявка (6 цифр)</th>
            <th>Стоимость (руб)</th>
            <th>Договор</th>
            <th>ID блока</th>
            <th>Маршрут</th>
            <th>Цена за 1 кг (руб)</th>
            <th>Вес (кг)</th>
            <th>Дата расчёта</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($data)): ?>
            <tr>
                <td colspan="9" style="text-align: center;">Нет данных. Нажмите "ОБНОВИТЬ" для расчёта.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($data as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                    <td><?php echo htmlspecialchars($row['zayavka_id']); ?></td>
                    <td><?php echo number_format($row['cost'], 2, ',', ''); ?></td>
                    <td><?php echo htmlspecialchars($row['dogovor_number'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['block_id']); ?></td>
                    <td><?php echo htmlspecialchars($row['marshrut_name'] ?? '-'); ?></td>
                    <td><?php echo number_format($row['price_per_kg'], 6, ',', ''); ?></td>
                    <td><?php echo number_format($row['weight_kg'], 3, ',', ''); ?></td>
                    <td><?php echo htmlspecialchars($row['calculated_at']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

</body>
</html>