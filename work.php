<?php
require_once 'config.php';
session_start();

// Функция для получения данных из Google Sheets
include ("include/googleSheetsHelper.php");
$googleData = getGoogleSheetsData();
///////////////////////////////////////


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

// Загружаем статусы из таблицы status
$statuses = [];
$statusMap = [];
try {
    $stmt = $pdo->query("SELECT * FROM status ORDER BY id");
    $statuses = $stmt->fetchAll();
    
    // Создаем массив для быстрого доступа по ключу 'статус'
    foreach ($statuses as $s) {
        $statusMap[$s['статус']] = $s;
    }
} catch (Exception $e) {
    // Если таблицы нет, создаем ее
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `status` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `статус` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
            `наименование` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `color` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Цвет статуса',
            `style` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название стиля',
            PRIMARY KEY (`id`),
            UNIQUE KEY `статус` (`статус`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Вставляем стандартные статусы
    $pdo->exec("
        INSERT INTO `status` (`статус`, `наименование`, `color`, `style`) VALUES
        ('search', 'Поиск подрядчика', '#FFA459', '.status-search'),
        ('found', 'Подрядчик определен', '#17a2b8', '.status-found'),
        ('started', 'Вывоз начался', '#28a745', '.status-started'),
        ('completed', 'Груз сдан', '#6c757d', '.status-completed')
        ON DUPLICATE KEY UPDATE `наименование` = VALUES(`наименование`)
    ");
    
    $stmt = $pdo->query("SELECT * FROM status ORDER BY id");
    $statuses = $stmt->fetchAll();
    
    foreach ($statuses as $s) {
        $statusMap[$s['статус']] = $s;
    }
}

// Генерируем CSS стили для статусов из БД
$dynamicStatusCSS = '';
foreach ($statuses as $s) {
    $styleClass = ltrim($s['style'], '.');
    $dynamicStatusCSS .= "
        .{$styleClass} {
            display: inline-block;
            width: 140px;
            text-align: center;
            padding: 4px 12px;
            background: {$s['color']};
            color: #ffffff;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
    ";
}

// Создаем таблицу flights, если её нет
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `flights` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `block_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `zayavki_ids` text NOT NULL COMMENT 'ID заявок через запятую',
        `zayavki_count` int(11) NOT NULL DEFAULT 0,
        `total_mass_tonn` decimal(10,3) DEFAULT NULL,
        `unload_type` enum('OO','SKLAD') NOT NULL DEFAULT 'OO' COMMENT 'Выгрузка: ОО или СКЛАД',
        `status` varchar(50) NOT NULL DEFAULT 'search' COMMENT 'Статус рейса',
        `contractor_id` int(11) DEFAULT NULL COMMENT 'ID подрядчика',
        `driver_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID водителя',
        `cost` int(11) DEFAULT NULL COMMENT 'Стоимость',
        `payment_terms` varchar(500) DEFAULT NULL COMMENT 'Условия оплаты',
        `comment` varchar(500) DEFAULT NULL COMMENT 'Комментарий',
        PRIMARY KEY (`id`),
        KEY `block_date` (`block_date`),
        KEY `status` (`status`),
        KEY `contractor_id` (`contractor_id`),
        KEY `driver_id` (`driver_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Проверяем структуру таблицы и добавляем недостающие колонки
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'payment_terms'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `flights` ADD COLUMN `payment_terms` varchar(500) DEFAULT NULL COMMENT 'Условия оплаты' AFTER `cost`");
    }
    
    $stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'comment'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `flights` ADD COLUMN `comment` varchar(500) DEFAULT NULL COMMENT 'Комментарий' AFTER `payment_terms`");
    }
    
    $stmt = $pdo->query("SHOW COLUMNS FROM flights WHERE Field = 'id'");
    $id_column = $stmt->fetch();
    if ($id_column && strpos($id_column['Extra'], 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE `flights` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT");
    }
    
    // Изменяем тип поля status с enum на varchar, если еще не изменено
    $stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'status'");
    $status_column = $stmt->fetch();
    if ($status_column && strpos($status_column['Type'], 'enum') !== false) {
        $pdo->exec("ALTER TABLE `flights` MODIFY `status` varchar(50) NOT NULL DEFAULT 'search' COMMENT 'Статус рейса'");
    }
} catch (Exception $e) {
    // Таблица может еще не существовать, игнорируем ошибку
}

// Получаем данные из Google Sheets

$zayavkiInfo = [];

// Индексируем данные по номеру заявки (row[0])
foreach ($googleData as $index => $row) {
    if ($index > 0 && isset($row[0]) && is_numeric(trim($row[0]))) {
        $requestNumber = trim($row[0]);
        $zayavkiInfo[$requestNumber] = [
            'route' => isset($row[8]) ? trim($row[8]) : '',
            'comments' => isset($row[11]) ? trim($row[11]) : '',
            'driver_info' => isset($row[12]) ? trim($row[12]) : '',
            'prep_time' => isset($row[13]) ? trim($row[13]) : ''
        ];
    }
}

// Функция для удаления дубликатов из массива контактов
function uniqueContacts($items) {
    $unique = [];
    foreach ($items as $item) {
        $item = trim($item);
        if (!empty($item) && !in_array($item, $unique)) {
            $unique[] = $item;
        }
    }
    return $unique;
}

// Получаем рейсы
$flights = [];

try {
    $stmt = $pdo->query("
        SELECT f.*, 
               c.name as contractor_name,
               d.full_name as driver_name,
               d.vehicle_make_plate
        FROM flights f
        LEFT JOIN contractors c ON f.contractor_id = c.id
        LEFT JOIN drivers d ON f.driver_id = d.id
        ORDER BY f.block_date DESC 
        LIMIT 50
    ");
    $flights = $stmt->fetchAll();
    
    $processedFlights = [];
    
    foreach ($flights as $flight) {
        $zayavki_ids = array_filter(explode(',', $flight['zayavki_ids']));
        $flight['zayavki_ids_array'] = $zayavki_ids;
        
        if (!empty($zayavki_ids)) {
            $placeholders = implode(',', array_fill(0, count($zayavki_ids), '?'));
            $stmt2 = $pdo->prepare("
                SELECT zayavka_id, mass_netto, naim_oo_gruzootpravitel, mno_adres_pogruzki, mno_sh, mno_d, 
                       kontakt_tel, tel_dopolnitelnyy, kontakt_email
                FROM feo 
                WHERE zayavka_id IN ({$placeholders})
            ");
            $stmt2->execute($zayavki_ids);
            $zayavkiData = $stmt2->fetchAll();
            
            // Добавляем информацию из Google Sheets к каждой заявке
            $enrichedZayavkiData = [];
            foreach ($zayavkiData as $zayavka) {
                $zayavkaId = $zayavka['zayavka_id'];
                $enrichedZayavka = $zayavka;
                
                if (isset($zayavkiInfo[$zayavkaId])) {
                    $enrichedZayavka['prep_time'] = $zayavkiInfo[$zayavkaId]['prep_time'];
                    $enrichedZayavka['comments'] = $zayavkiInfo[$zayavkaId]['comments'];
                    $enrichedZayavka['driver_info'] = $zayavkiInfo[$zayavkaId]['driver_info'];
                    $enrichedZayavka['route'] = $zayavkiInfo[$zayavkaId]['route'];
                } else {
                    $enrichedZayavka['prep_time'] = '';
                    $enrichedZayavka['comments'] = '';
                    $enrichedZayavka['driver_info'] = '';
                    $enrichedZayavka['route'] = '';
                }
                
                $enrichedZayavkiData[] = $enrichedZayavka;
            }
            
            $flight['zayavki'] = $enrichedZayavkiData;
            
            $actual_mass_kg = 0;
            foreach ($flight['zayavki'] as $zayavka) {
                $actual_mass_kg += floatval($zayavka['mass_netto'] ?? 0) * 1000;
            }
            
            $flight['total_mass_kg'] = $actual_mass_kg;
            
            $actual_mass_tonn = $actual_mass_kg / 1000;
            if (abs($actual_mass_tonn - floatval($flight['total_mass_tonn'])) > 0.001) {
                $update_stmt = $pdo->prepare("UPDATE flights SET total_mass_tonn = ? WHERE id = ?");
                $update_stmt->execute([$actual_mass_tonn, $flight['id']]);
                $flight['total_mass_tonn'] = $actual_mass_tonn;
            }
        } else {
            $flight['zayavki'] = [];
            $flight['total_mass_kg'] = 0;
        }
        
        $processedFlights[] = $flight;
    }
    
    $flights = $processedFlights;
    
} catch (Exception $e) {
    $flights = [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Рейсы</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Базовые стили для статусов */
        .status-badge {
            display: inline-block;
            width: 140px;
            text-align: center;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            color: #ffffff;
        }
        
        <?= $dynamicStatusCSS ?>
        
        .unload-oo {
            display: inline-block;
            width: 80px;
            text-align: center;
            padding: 2px 8px;
            background: #e7f3ff;
            color: #0066cc;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .unload-sklad {
            display: inline-block;
            width: 80px;
            text-align: center;
            padding: 2px 8px;
            background: #fff3cd;
            color: #856404;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .toggle-arrow {
            cursor: pointer;
            user-select: none;
            transition: transform 0.2s;
        }
        
        .toggle-arrow:hover {
            transform: scale(1.2);
        }
        
        .details-table {
            width: 100%;
            border-collapse: collapse;
            background: #f9f9f9;
            table-layout: fixed;
        }
        
        .details-table th {
            background: #e9ecef;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
            border-bottom: 1px solid #dee2e6;
            text-align: center;
            word-wrap: break-word;
        }
        
        .details-table td {
            padding: 8px 12px;
            font-size: 13px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
            word-wrap: break-word;
            word-break: break-word;
            white-space: normal;
        }
        
        .block-details {
            padding: 12px 20px;
            background: #f5f5f5;
            border-top: 1px solid #dee2e6;
            border-bottom: 1px solid #dee2e6;
        }
        
        .days-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 12px;
            background: #e8f5e9;
            color: #2e7d32;
            text-align: center;
        }
        
        .days-badge.high {
            background: #ffebee;
            color: #c62828;
        }
        
        .comments-cell {
            white-space: pre-wrap;
            word-wrap: break-word;
            word-break: break-word;
            font-size: 12px;
            line-height: 1.4;
            text-align: left !important;
        }
        
        .contacts-cell {
            white-space: pre-wrap;
            word-wrap: break-word;
            word-break: break-word;
            font-size: 12px;
            line-height: 1.4;
            text-align: left;
        }
        
        .route-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            background: #e3f2fd;
            color: #1565c0;
            font-weight: 600;
            font-size: 11px;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-muted {
            color: #6c757d;
        }
        
        .empty-value {
            color: #9e9e9e;
            font-style: italic;
            font-size: 11px;
        }
        
        .details-table th:first-child,
        .details-table td:first-child {
            text-align: center;
        }
        
        /* Фиксированная ширина столбцов */
        .details-table th:nth-child(1),
        .details-table td:nth-child(1) {
            width: 80px;
        }
        
        .details-table th:nth-child(2),
        .details-table td:nth-child(2) {
            width: 80px;
        }
        
        .details-table th:nth-child(3),
        .details-table td:nth-child(3) {
            width: 300px;
        }
        
        .details-table th:nth-child(4),
        .details-table td:nth-child(4) {
            width: 400px;
        }
        
        .details-table th:nth-child(5),
        .details-table td:nth-child(5) {
            width: 150px;
        }
        
        .details-table th:nth-child(6),
        .details-table td:nth-child(6) {
            width: 100px;
        }
    </style>
</head>
<body>
<?php include 'menu2.php'; ?>
    <div class="app">
        <div class="toolbar">
            <h1>Рейсы</h1>
        </div>
        
        <?php if (!empty($flights)): ?>
        <div class="card">
            <div class="table-wrapper">
                <table class="data-table" id="flightsTable">
                    <thead>
                        <tr>
                            <th style="width: 30px;"></th>
                            <th style="width: 40px;">ID</th>
                            <th style="width: 250px;">ОПИСАНИЕ РЕЙСА</th>
                            <th style="text-align: center;">ВЫГРУЗКА</th>
                            <th style="text-align: center;">СТАТУС</th>
                            <th style="text-align: center;">КОЛ-ВО ЗАЯВОК</th>
                            <th style="text-align: center;">ОБЩИЙ ВЕС, КГ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($flights as $flight): ?>
                        <tr class="group-header" onclick="toggleFlight('details_<?= $flight['id'] ?>', event)">
                            <td class="toggle-arrow" style="text-align: center;">▶</td>
                            <td><strong>#<?= $flight['id'] ?></strong></td>
                            <td><?= !empty($flight['comment']) ? htmlspecialchars($flight['comment']) : '<span style="color:#999;">—</span>' ?></td>
                            <td style="text-align: center;">
                                <?php if ($flight['unload_type'] === 'OO'): ?>
                                 Утилизатор
                                <?php else: ?>
                                 Склад
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php 
                                $statusCode = $flight['status'];
                                $statusData = $statusMap[$statusCode] ?? null;
                                if ($statusData): 
                                    $styleClass = ltrim($statusData['style'], '.');
                                ?>
                                    <span class="status-badge <?= htmlspecialchars($styleClass) ?>">
                                        <?= htmlspecialchars($statusData['наименование']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge" style="background: #6c757d;">
                                        <?= htmlspecialchars($flight['status']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;"><?= $flight['zayavki_count'] ?></td>
                            <td style="text-align: right;"><?= number_format($flight['total_mass_kg'] ?? 0, 0, '.', ' ') ?> кг</td>
                         </tr>
                        
                        <tr id="details_<?= $flight['id'] ?>" style="display: none;">
                            <td colspan="7" style="padding: 0;">
                                <div class="block-details">
                                    <table class="details-table">
                                        <thead>
                                            <tr>
                                                <th>ID ЗАЯВКИ</th>
                                                <th>МАССА, КГ</th>
                                                <th>ГРУЗООТПРАВИТЕЛЬ</th>
                                                <th>АДРЕС ПОГРУЗКИ</th>
                                                <th>КОНТАКТЫ</th>
                                                <th>ПРОПУСК</th>
                                                <th>КОММЕНТАРИИ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($flight['zayavki'])): ?>
                                                <?php foreach ($flight['zayavki'] as $zayavkaItem): 
                                                    $mass_kg = floatval($zayavkaItem['mass_netto'] ?? 0) * 1000;
                                                    $prep_time = $zayavkaItem['prep_time'] ?? '';
                                                    $comments = $zayavkaItem['comments'] ?? '';
                                                    $daysClass = (is_numeric($prep_time) && $prep_time > 3) ? 'high' : '';
                                                    
                                                    // Формируем контактную информацию с удалением дубликатов
                                                    $contacts = [];
                                                    
                                                    // Обработка телефонов (kontakt_tel)
                                                    if (!empty($zayavkaItem['kontakt_tel'])) {
                                                        $phones = explode('|', $zayavkaItem['kontakt_tel']);
                                                        $phones = uniqueContacts($phones);
                                                        foreach ($phones as $phone) {
                                                            $contacts[] = htmlspecialchars($phone);
                                                        }
                                                    }
                                                    
                                                    // Обработка дополнительных телефонов (tel_dopolnitelnyy)
                                                    if (!empty($zayavkaItem['tel_dopolnitelnyy'])) {
                                                        $extraPhones = explode('|', $zayavkaItem['tel_dopolnitelnyy']);
                                                        $extraPhones = uniqueContacts($extraPhones);
                                                        foreach ($extraPhones as $phone) {
                                                            $contacts[] = htmlspecialchars($phone);
                                                        }
                                                    }
                                                    
                                                    // Обработка email (kontakt_email)
                                                    if (!empty($zayavkaItem['kontakt_email'])) {
                                                        $emails = explode('|', $zayavkaItem['kontakt_email']);
                                                        $emails = uniqueContacts($emails);
                                                        foreach ($emails as $email) {
                                                            $contacts[] = htmlspecialchars($email);
                                                        }
                                                    }
                                                    
                                                    $contactsHtml = !empty($contacts) ? implode('<br>', $contacts) : '<span class="empty-value">—</span>';
                                                ?>
                                                <tr>
                                                    <td class="text-center"><strong><?= htmlspecialchars($zayavkaItem['zayavka_id']) ?></strong></td>
                                                    <td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
                                                    <td style="text-align: left;"><?= htmlspecialchars($zayavkaItem['naim_oo_gruzootpravitel'] ?? '') ?></td>
                                                    <td style="text-align: left;"><?= htmlspecialchars($zayavkaItem['mno_adres_pogruzki'] ?? '') ?></td>
                                                    <td class="contacts-cell"><?= $contactsHtml ?></td>
                                                    <td class="text-center">
                                                        <?php if (!empty($prep_time)): ?>
                                                            <span class="days-badge <?= $daysClass ?>"><?= htmlspecialchars($prep_time) ?></span>
                                                        <?php else: ?>
                                                            <span class="empty-value">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="comments-cell">
                                                        <?php if (!empty($comments)): ?>
                                                            <?= nl2br(htmlspecialchars($comments)) ?>
                                                        <?php else: ?>
                                                            <span class="empty-value">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted">Данные заявок не найдены</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                         </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <p class="text-center text-muted" style="padding: 40px;">Нет рейсов</p>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    function toggleFlight(flightId, event) {
        if (event) event.stopPropagation();
        
        const flightRow = document.getElementById(flightId);
        const headerRow = flightRow.previousElementSibling;
        const arrow = headerRow.querySelector('.toggle-arrow');
        
        if (flightRow.style.display === 'none') {
            flightRow.style.display = 'table-row';
            arrow.textContent = '▼';
        } else {
            flightRow.style.display = 'none';
            arrow.textContent = '▶';
        }
    }
    </script>
</body>
</html>