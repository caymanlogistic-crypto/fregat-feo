<?php
// reports.php - Единый отчет по дубликатам заявок
// Блоки: ДОСТУПНО (available.php), МАРШРУТЫ (order.php), РЕЙСЫ OO (flights.php OO), РЕЙСЫ СКЛАД (flights.php SKLAD)

require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

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
    die("Ошибка подключения: " . $e->getMessage());
}

// ==================== БЛОК 1: ДОСТУПНО К ВЫВОЗУ (status_blocks, type=dostupno) ====================
$stmt = $pdo->prepare("
    SELECT id, zayavki_ids, status_value, comment, additional_comment,
           DATE_FORMAT(block_date, '%d.%m.%Y %H:%i') as block_date,
           zayavki_count, total_mass_tonn, cost
    FROM status_blocks 
    WHERE status_type = 'dostupno'
    ORDER BY block_date DESC
");
$stmt->execute();
$dostupno_blocks = $stmt->fetchAll();

$dostupno_zayavka_to_blocks = [];
foreach ($dostupno_blocks as $block) {
    $zayavki = array_filter(explode(',', $block['zayavki_ids']));
    foreach ($zayavki as $zayavka_id) {
        $zayavka_id = trim($zayavka_id);
        if (empty($zayavka_id)) continue;
        $dostupno_zayavka_to_blocks[$zayavka_id][] = [
            'block_id' => $block['id'],
            'block_date' => $block['block_date'],
            'comment' => $block['comment'],
            'status_value' => $block['status_value']
        ];
    }
}

$dostupno_duplicates = [];
foreach ($dostupno_zayavka_to_blocks as $zayavka_id => $blocks_list) {
    if (count($blocks_list) > 1) {
        $dostupno_duplicates[$zayavka_id] = $blocks_list;
    }
}

// ==================== БЛОК 2: МАРШРУТЫ (status_blocks, type=marshrut) ====================
$stmt = $pdo->prepare("
    SELECT id, zayavki_ids, status_value, comment, additional_comment,
           DATE_FORMAT(block_date, '%d.%m.%Y %H:%i') as block_date,
           zayavki_count, total_mass_tonn, cost
    FROM status_blocks 
    WHERE status_type = 'marshrut'
    ORDER BY block_date DESC
");
$stmt->execute();
$marshrut_blocks = $stmt->fetchAll();

$marshrut_zayavka_to_blocks = [];
foreach ($marshrut_blocks as $block) {
    $zayavki = array_filter(explode(',', $block['zayavki_ids']));
    foreach ($zayavki as $zayavka_id) {
        $zayavka_id = trim($zayavka_id);
        if (empty($zayavka_id)) continue;
        $marshrut_zayavka_to_blocks[$zayavka_id][] = [
            'block_id' => $block['id'],
            'block_date' => $block['block_date'],
            'comment' => $block['comment'],
            'status_value' => $block['status_value']
        ];
    }
}

$marshrut_duplicates = [];
foreach ($marshrut_zayavka_to_blocks as $zayavka_id => $blocks_list) {
    if (count($blocks_list) > 1) {
        $marshrut_duplicates[$zayavka_id] = $blocks_list;
    }
}

// ==================== БЛОК 3: РЕЙСЫ OO (flights, unload_type = 'OO') ====================
$stmt = $pdo->prepare("
    SELECT id, zayavki_ids, unload_type, status, comment,
           DATE_FORMAT(block_date, '%d.%m.%Y %H:%i') as block_date,
           zayavki_count, total_mass_tonn, cost,
           contractor_id, driver_id
    FROM flights 
    WHERE unload_type = 'OO'
    ORDER BY block_date DESC
");
$stmt->execute();
$flights_oo = $stmt->fetchAll();

$flights_oo_zayavka_to_flights = [];
foreach ($flights_oo as $flight) {
    $zayavki = array_filter(explode(',', $flight['zayavki_ids']));
    foreach ($zayavki as $zayavka_id) {
        $zayavka_id = trim($zayavka_id);
        if (empty($zayavka_id)) continue;
        $flights_oo_zayavka_to_flights[$zayavka_id][] = [
            'flight_id' => $flight['id'],
            'block_date' => $flight['block_date'],
            'comment' => $flight['comment'],
            'status' => $flight['status']
        ];
    }
}

$flights_oo_duplicates = [];
foreach ($flights_oo_zayavka_to_flights as $zayavka_id => $flights_list) {
    if (count($flights_list) > 1) {
        $flights_oo_duplicates[$zayavka_id] = $flights_list;
    }
}

// ==================== БЛОК 4: РЕЙСЫ СКЛАД (flights, unload_type = 'SKLAD') ====================
$stmt = $pdo->prepare("
    SELECT id, zayavki_ids, unload_type, status, comment,
           DATE_FORMAT(block_date, '%d.%m.%Y %H:%i') as block_date,
           zayavki_count, total_mass_tonn, cost,
           contractor_id, driver_id
    FROM flights 
    WHERE unload_type = 'SKLAD'
    ORDER BY block_date DESC
");
$stmt->execute();
$flights_sklad = $stmt->fetchAll();

$flights_sklad_zayavka_to_flights = [];
foreach ($flights_sklad as $flight) {
    $zayavki = array_filter(explode(',', $flight['zayavki_ids']));
    foreach ($zayavki as $zayavka_id) {
        $zayavka_id = trim($zayavka_id);
        if (empty($zayavka_id)) continue;
        $flights_sklad_zayavka_to_flights[$zayavka_id][] = [
            'flight_id' => $flight['id'],
            'block_date' => $flight['block_date'],
            'comment' => $flight['comment'],
            'status' => $flight['status']
        ];
    }
}

$flights_sklad_duplicates = [];
foreach ($flights_sklad_zayavka_to_flights as $zayavka_id => $flights_list) {
    if (count($flights_list) > 1) {
        $flights_sklad_duplicates[$zayavka_id] = $flights_list;
    }
}

// ==================== СТАТИСТИКА ====================
$dostupno_total_blocks = count($dostupno_blocks);
$dostupno_total_zayavok = count($dostupno_zayavka_to_blocks);
$dostupno_total_duplicates = count($dostupno_duplicates);
$dostupno_total_mass_kg = array_sum(array_map(function($b) { return floatval($b['total_mass_tonn'] ?? 0) * 1000; }, $dostupno_blocks));
$dostupno_total_cost = array_sum(array_column($dostupno_blocks, 'cost'));

$marshrut_total_blocks = count($marshrut_blocks);
$marshrut_total_zayavok = count($marshrut_zayavka_to_blocks);
$marshrut_total_duplicates = count($marshrut_duplicates);
$marshrut_total_mass_kg = array_sum(array_map(function($b) { return floatval($b['total_mass_tonn'] ?? 0) * 1000; }, $marshrut_blocks));
$marshrut_total_cost = array_sum(array_column($marshrut_blocks, 'cost'));

$flights_oo_total = count($flights_oo);
$flights_oo_total_zayavok = count($flights_oo_zayavka_to_flights);
$flights_oo_total_duplicates = count($flights_oo_duplicates);
$flights_oo_total_mass_kg = array_sum(array_map(function($f) { return floatval($f['total_mass_tonn'] ?? 0) * 1000; }, $flights_oo));
$flights_oo_total_cost = array_sum(array_column($flights_oo, 'cost'));

$flights_sklad_total = count($flights_sklad);
$flights_sklad_total_zayavok = count($flights_sklad_zayavka_to_flights);
$flights_sklad_total_duplicates = count($flights_sklad_duplicates);
$flights_sklad_total_mass_kg = array_sum(array_map(function($f) { return floatval($f['total_mass_tonn'] ?? 0) * 1000; }, $flights_sklad));
$flights_sklad_total_cost = array_sum(array_column($flights_sklad, 'cost'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчеты по дубликатам заявок</title>
    <link rel="stylesheet" href="style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
        }
        
        h1 {
            font-size: 28px;
            margin-bottom: 10px;
            color: #1a1a2e;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .report-section {
            background: white;
            border-radius: 16px;
            margin-bottom: 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .report-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .report-header h2 {
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .report-badge {
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .badge-dostupno { background: #10b981; color: white; }
        .badge-marshrut { background: #8b5cf6; color: white; }
        .badge-oo { background: #3b82f6; color: white; }
        .badge-sklad { background: #f59e0b; color: white; }
        
        .stats-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .stat-mini {
            background: #f8fafc;
            padding: 10px 18px;
            border-radius: 10px;
            text-align: center;
            min-width: 100px;
        }
        
        .stat-mini-number {
            font-size: 24px;
            font-weight: bold;
        }
        
        .stat-mini-label {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        
        .stat-mini-number.success { color: #10b981; }
        .stat-mini-number.error { color: #ef4444; }
        
        .status-ok {
            background: #dcfce7;
            border-left: 4px solid #22c55e;
            padding: 15px 20px;
            margin: 0 25px 20px 25px;
            border-radius: 10px;
        }
        
        .status-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px 20px;
            margin: 0 25px 20px 25px;
            border-radius: 10px;
        }
        
        .duplicate-list {
            padding: 0 25px 25px 25px;
        }
        
        .duplicate-card {
            background: #fef2f2;
            border-radius: 12px;
            margin-bottom: 15px;
            overflow: hidden;
            border: 1px solid #fecaca;
        }
        
        .duplicate-card-header {
            background: #fee2e2;
            padding: 12px 20px;
            font-weight: 600;
            color: #991b1b;
        }
        
        .duplicate-row {
            display: flex;
            padding: 10px 20px;
            border-bottom: 1px solid #fecaca;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
            font-size: 14px;
        }
        
        .duplicate-row:last-child {
            border-bottom: none;
        }
        
        .item-id {
            background: #e5e7eb;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            min-width: 80px;
            text-align: center;
        }
        
        .item-date {
            color: #666;
            min-width: 140px;
        }
        
        .item-comment {
            color: #555;
            flex: 2;
            word-break: break-word;
        }
        
        .footer {
            margin-top: 30px;
            padding: 20px;
            background: #f1f5f9;
            border-radius: 12px;
            text-align: center;
            color: #666;
            font-size: 14px;
        }
        
        .section-note {
            font-size: 12px;
            color: #888;
            margin-left: 25px;
            margin-bottom: 10px;
        }
        
        @media (max-width: 768px) {
            .report-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .duplicate-row {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<?php include 'menuadmin.php'; ?>
<div class="container">
    <h1>Отчеты по дубликатам заявок</h1>
    <div class="subtitle">Проверка: одна заявка не может быть в нескольких блоках/рейсах одного типа</div>
    
    <!-- ==================== БЛОК 1: ДОСТУПНО К ВЫВОЗУ ==================== -->
    <div class="report-section">
        <div class="report-header">
            <h2>
                Доступно к вывозу
                <span class="report-badge badge-dostupno">available.php</span>
            </h2>
            <div class="stats-row">
                <div class="stat-mini"><div class="stat-mini-number"><?= $dostupno_total_blocks ?></div><div class="stat-mini-label">блоков</div></div>
                <div class="stat-mini"><div class="stat-mini-number"><?= $dostupno_total_zayavok ?></div><div class="stat-mini-label">заявок</div></div>
                <div class="stat-mini"><div class="stat-mini-number <?= $dostupno_total_duplicates > 0 ? 'error' : 'success' ?>"><?= $dostupno_total_duplicates ?></div><div class="stat-mini-label">дубликатов</div></div>
                
            </div>
        </div>
        
        <?php if ($dostupno_total_duplicates == 0): ?>
            <div class="status-ok">✅ <strong>Проблем нет!</strong> Ни одна заявка не повторяется в нескольких блоках "Доступно к вывозу".</div>
        <?php else: ?>
            <div class="status-warning">⚠️ <strong>Найдено <?= $dostupno_total_duplicates ?> заявок с дубликатами!</strong></div>
            <div class="duplicate-list">
                <?php foreach ($dostupno_duplicates as $zayavka_id => $blocks_list): ?>
                <div class="duplicate-card">
                    <div class="duplicate-card-header">🔴 Заявка #<?= htmlspecialchars($zayavka_id) ?> — в <?= count($blocks_list) ?> блоках</div>
                    <?php foreach ($blocks_list as $block): ?>
                    <div class="duplicate-row">
                        <span class="item-id">Блок #<?= $block['block_id'] ?></span>
                        <span class="item-date">📅 <?= $block['block_date'] ?></span>
                        <span class="item-comment">📝 <?= !empty($block['comment']) ? htmlspecialchars($block['comment']) : '<span style="color:#999;">без названия</span>' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- ==================== БЛОК 2: МАРШРУТЫ ==================== -->
    <div class="report-section">
        <div class="report-header">
            <h2>
                Маршруты
                <span class="report-badge badge-marshrut">order.php</span>
            </h2>
            <div class="stats-row">
                <div class="stat-mini"><div class="stat-mini-number"><?= $marshrut_total_blocks ?></div><div class="stat-mini-label">блоков</div></div>
                <div class="stat-mini"><div class="stat-mini-number"><?= $marshrut_total_zayavok ?></div><div class="stat-mini-label">заявок</div></div>
                <div class="stat-mini"><div class="stat-mini-number <?= $marshrut_total_duplicates > 0 ? 'error' : 'success' ?>"><?= $marshrut_total_duplicates ?></div><div class="stat-mini-label">дубликатов</div></div>
                
            </div>
        </div>
        
        <?php if ($marshrut_total_duplicates == 0): ?>
            <div class="status-ok">✅ <strong>Проблем нет!</strong> Ни одна заявка не повторяется в нескольких маршрутах.</div>
        <?php else: ?>
            <div class="status-warning">⚠️ <strong>Найдено <?= $marshrut_total_duplicates ?> заявок с дубликатами!</strong></div>
            <div class="duplicate-list">
                <?php foreach ($marshrut_duplicates as $zayavka_id => $blocks_list): ?>
                <div class="duplicate-card">
                    <div class="duplicate-card-header">🔴 Заявка #<?= htmlspecialchars($zayavka_id) ?> — в <?= count($blocks_list) ?> маршрутах</div>
                    <?php foreach ($blocks_list as $block): ?>
                    <div class="duplicate-row">
                        <span class="item-id">Маршрут #<?= $block['block_id'] ?></span>
                        <span class="item-date">📅 <?= $block['block_date'] ?></span>
                        <span class="item-comment">🗺️ <?= htmlspecialchars($block['status_value']) ?> | 📝 <?= !empty($block['comment']) ? htmlspecialchars($block['comment']) : '<span style="color:#999;">—</span>' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- ==================== БЛОК 3: РЕЙСЫ OO ==================== -->
    <div class="report-section">
        <div class="report-header">
            <h2>
                Рейсы (ОО)
                <span class="report-badge badge-oo">flights.php | unload_type = OO</span>
            </h2>
            <div class="stats-row">
                <div class="stat-mini"><div class="stat-mini-number"><?= $flights_oo_total ?></div><div class="stat-mini-label">рейсов</div></div>
                <div class="stat-mini"><div class="stat-mini-number"><?= $flights_oo_total_zayavok ?></div><div class="stat-mini-label">заявок</div></div>
                <div class="stat-mini"><div class="stat-mini-number <?= $flights_oo_total_duplicates > 0 ? 'error' : 'success' ?>"><?= $flights_oo_total_duplicates ?></div><div class="stat-mini-label">дубликатов</div></div>
            </div>
        </div>
        
        <?php if ($flights_oo_total_duplicates == 0): ?>
            <div class="status-ok">✅ <strong>Проблем нет!</strong> Ни одна заявка не повторяется в нескольких OO-рейсах.</div>
        <?php else: ?>
            <div class="status-warning">⚠️ <strong>Найдено <?= $flights_oo_total_duplicates ?> заявок с дубликатами!</strong></div>
            <div class="duplicate-list">
                <?php foreach ($flights_oo_duplicates as $zayavka_id => $flights_list): ?>
                <div class="duplicate-card">
                    <div class="duplicate-card-header">🔴 Заявка #<?= htmlspecialchars($zayavka_id) ?> — в <?= count($flights_list) ?> OO-рейсах</div>
                    <?php foreach ($flights_list as $flight): ?>
                    <div class="duplicate-row">
                        <span class="item-id">Рейс #<?= $flight['flight_id'] ?></span>
                        <span class="item-date">📅 <?= $flight['block_date'] ?></span>
                        <span class="item-comment">📝 <?= !empty($flight['comment']) ? htmlspecialchars($flight['comment']) : '<span style="color:#999;">без описания</span>' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- ==================== БЛОК 4: РЕЙСЫ СКЛАД ==================== -->
    <div class="report-section">
        <div class="report-header">
            <h2>
                Рейсы (СКЛАД)
                <span class="report-badge badge-sklad">flights.php | unload_type = SKLAD</span>
            </h2>
            <div class="stats-row">
                <div class="stat-mini"><div class="stat-mini-number"><?= $flights_sklad_total ?></div><div class="stat-mini-label">рейсов</div></div>
                <div class="stat-mini"><div class="stat-mini-number"><?= $flights_sklad_total_zayavok ?></div><div class="stat-mini-label">заявок</div></div>
                <div class="stat-mini"><div class="stat-mini-number <?= $flights_sklad_total_duplicates > 0 ? 'error' : 'success' ?>"><?= $flights_sklad_total_duplicates ?></div><div class="stat-mini-label">дубликатов</div></div>
                
            </div>
        </div>
        
        <?php if ($flights_sklad_total_duplicates == 0): ?>
            <div class="status-ok">✅ <strong>Проблем нет!</strong> Ни одна заявка не повторяется в нескольких СКЛАД-рейсах.</div>
        <?php else: ?>
            <div class="status-warning">⚠️ <strong>Найдено <?= $flights_sklad_total_duplicates ?> заявок с дубликатами!</strong></div>
            <div class="duplicate-list">
                <?php foreach ($flights_sklad_duplicates as $zayavka_id => $flights_list): ?>
                <div class="duplicate-card">
                    <div class="duplicate-card-header">🔴 Заявка #<?= htmlspecialchars($zayavka_id) ?> — в <?= count($flights_list) ?> СКЛАД-рейсах</div>
                    <?php foreach ($flights_list as $flight): ?>
                    <div class="duplicate-row">
                        <span class="item-id">Рейс #<?= $flight['flight_id'] ?></span>
                        <span class="item-date">📅 <?= $flight['block_date'] ?></span>
                        <span class="item-comment">📝 <?= !empty($flight['comment']) ? htmlspecialchars($flight['comment']) : '<span style="color:#999;">без описания</span>' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="footer">
        🔒 <strong>Режим только для просмотра</strong> — база данных не изменяется<br>
        ✅ Зеленая статистика = проблем нет | 🔴 Красная статистика = есть дубликаты<br>
        📌 Рейсы разделены по типам выгрузки: <strong>OO</strong> и <strong>СКЛАД</strong> — проверяются отдельно
    </div>
</div>
</body>
</html>