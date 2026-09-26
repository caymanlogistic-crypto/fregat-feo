<?php
// API конфигурация
define('DADATA_API_KEY', getenv('DADATA_API_TOKEN') ?: '');
define('DADATA_API_URL', 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party');

function getCompanyByINN($inn) {
    $options = [
        'http' => [
            'header' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Token ' . DADATA_API_KEY
            ],
            'method' => 'POST',
            'content' => json_encode(['query' => preg_replace('/[^0-9]/', '', $inn)])
        ]
    ];
    
    $result = @file_get_contents(DADATA_API_URL, false, stream_context_create($options));
    if (!$result) return null;
    
    $response = json_decode($result, true);
    if (empty($response['suggestions'][0])) return null;
    
    $c = $response['suggestions'][0]['data'];
    
    // Статус организации
    $statusMap = [
        'ACTIVE' => 'Действует',
        'LIQUIDATING' => 'В стадии ликвидации',
        'LIQUIDATED' => 'Ликвидировано',
        'BANKRUPT' => 'Банкротство',
        'REORGANIZING' => 'Реорганизуется'
    ];
    $statusCode = $c['state']['status'] ?? '';
    $status = $statusMap[$statusCode] ?? ($statusCode ?: '—');
    
    // Тип организации (юрлицо или ИП)
    $type = ($c['type'] ?? '') == 'LEGAL' ? 'Юридическое лицо' : 'Индивидуальный предприниматель';
    
    // ОПФ (организационно-правовая форма)
    $opf = ($c['opf']['full_name'] ?? '') ?: ($c['opf']['short_name'] ?? '—');
    
    // Руководитель
    $managementName = $c['management']['name'] ?? '—';
    $managementPost = $c['management']['post'] ?? '—';
    $management = $managementName . ' (' . $managementPost . ')';
    
    // Адрес (полный)
    $address = $c['address']['value'] ?? '—';
    $addressUnrestricted = $c['address']['unrestricted_value'] ?? '—';
    $addressData = $c['address']['data'] ?? [];
    
    // Формат адреса
    $addressPostal = $addressData['postal_code'] ?? '';
    $addressFull = ($addressPostal ? $addressPostal . ', ' : '') . $address;
    
    // Координаты
    $geoLon = $addressData['geo_lon'] ?? null;
    $geoLat = $addressData['geo_lat'] ?? null;
    $geoCoords = ($geoLat && $geoLon) ? $geoLat . ', ' . $geoLon : '—';
    
    // Налоговые органы
    $taxOffice = $c['tax_office'] ?? null;
    $taxOfficeName = '—';
    if ($taxOffice) {
        $taxOfficeName = 'ФНС №' . $taxOffice . ' (' . ($c['tax_office_name'] ?? '—') . ')';
    }
    
    // Регистрация в ФНС
    $registrationDate = $c['state']['registration_date'] ?? null;
    $registrationDateFormatted = $registrationDate ? date('d.m.Y', strtotime($registrationDate)) : '—';
    
    // Дата ликвидации (если есть)
    $liquidationDate = $c['state']['liquidation_date'] ?? null;
    $liquidationDateFormatted = $liquidationDate ? date('d.m.Y', strtotime($liquidationDate)) : '—';
    
    // Коды статистики
    $okato = $addressData['okato'] ?? '—';
    $oktmo = $addressData['oktmo'] ?? '—';
    $okogu = $c['okogu'] ?? '—';
    $okfs = $c['okfs'] ?? '—';
    $okved = $c['okved'] ?? '—';
    $okvedType = $c['okved_type'] ?? '—';
    
    // Филиалы и представительства
    $branchesType = $c['branches']['type'] ?? '—';
    $branchesCount = $c['branches']['count'] ?? 0;
    $branchesInfo = $branchesCount > 0 ? $branchesType . ' (' . $branchesCount . ')' : 'Нет филиалов';
    
    // Лицензии
    $licensesRaw = $c['licenses'] ?? [];
    $licensesList = [];
    if (!empty($licensesRaw)) {
        foreach ($licensesRaw as $license) {
            $licensesList[] = [
                'name' => $license['name'] ?? '—',
                'number' => $license['license_number'] ?? '—',
                'date' => isset($license['issue_date']) ? date('d.m.Y', strtotime($license['issue_date'])) : '—',
                'authority' => $license['license_authority'] ?? '—'
            ];
        }
    }
    
    // Контактные данные
    $phonesRaw = $c['phones'] ?? [];
    $emailsRaw = $c['emails'] ?? [];
    $phonesStr = !empty($phonesRaw) ? implode(', ', $phonesRaw) : '—';
    $emailsStr = !empty($emailsRaw) ? implode(', ', $emailsRaw) : '—';
    
    // Сведения о капитале
    $capitalValue = $c['capital']['value'] ?? null;
    $capitalType = $c['capital']['type'] ?? null;
    $capitalStr = $capitalValue ? number_format($capitalValue, 0, ',', ' ') . ' ₽' : '—';
    
    // Количество работников
    $employeesCount = $c['employees_count'] ?? '—';
    
    // Учредители (первые 5)
    $foundersRaw = $c['founders'] ?? [];
    $foundersList = [];
    if (!empty($foundersRaw)) {
        foreach (array_slice($foundersRaw, 0, 5) as $founder) {
            $founderName = $founder['name'] ?? '—';
            $founderInn = $founder['inn'] ?? '—';
            $foundersList[] = $founderName . ' (' . $founderInn . ')';
        }
    }
    
    // Флаг, что организация ИП
    $isIndividual = ($c['type'] ?? '') == 'INDIVIDUAL';
    
    // ОГРН дата
    $ogrnDate = isset($c['ogrn_date']) ? date('d.m.Y', strtotime($c['ogrn_date'])) : '—';
    
    // Полное наименование
    $fullName = $c['name']['full_with_opf'] ?? '—';
    $shortName = $c['name']['short_with_opf'] ?? '—';
    $fullNameOriginal = $c['name']['full'] ?? '—';
    
    return [
        // Основные реквизиты
        'full_name' => $fullName,
        'short_name' => $shortName,
        'full_name_original' => $fullNameOriginal,
        'inn' => $c['inn'] ?? '—',
        'kpp' => $c['kpp'] ?? '—',
        'ogrn' => $c['ogrn'] ?? '—',
        'ogrn_date' => $ogrnDate,
        'type' => $type,
        'opf' => $opf,
        'status' => $status,
        'status_code' => $statusCode ?: '—',
        'registration_date' => $registrationDateFormatted,
        'liquidation_date' => $liquidationDateFormatted,
        
        // Руководство
        'management' => $management,
        'management_name' => $managementName,
        'management_post' => $managementPost,
        
        // Адрес и геоданные
        'address' => $addressFull,
        'address_raw' => $address,
        'address_unrestricted' => $addressUnrestricted,
        'address_postal' => $addressPostal ?: '—',
        'geo_coords' => $geoCoords,
        'geo_lat' => $geoLat,
        'geo_lon' => $geoLon,
        
        // Коды
        'okato' => $okato,
        'oktmo' => $oktmo,
        'okogu' => $okogu,
        'okfs' => $okfs,
        'okved' => $okved,
        'okved_type' => $okvedType,
        
        // Налоговые органы
        'tax_office' => $taxOffice,
        'tax_office_name' => $taxOfficeName,
        'tax_system' => $c['tax_system'] ?? '—',
        
        // Филиалы
        'branches_info' => $branchesInfo,
        'branches_type' => $branchesType,
        'branches_count' => $branchesCount,
        
        // Лицензии
        'licenses' => $licensesList,
        'has_licenses' => !empty($licensesList),
        
        // Контакты
        'phones' => $phonesStr,
        'emails' => $emailsStr,
        
        // Капитал и сотрудники
        'capital' => $capitalStr,
        'capital_type' => $capitalType,
        'employees_count' => $employeesCount,
        
        // Учредители
        'founders' => $foundersList,
        'founders_count' => count($foundersRaw),
        
        // Флаг ИП
        'is_individual' => $isIndividual,
        
        // Дата актуальности
        'update_date' => date('d.m.Y H:i:s'),
        
        // Полный объект на всякий случай
        '_raw' => $c
    ];
}

// Обработка запроса
$result = null;
$inn_input = $_POST['inn'] ?? '7707083893'; // Сбер по умолчанию
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['inn'])) {
    $result = getCompanyByINN($inn_input);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Полная информация о компании по ИНН | DaData</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        
        body {
            background: #f0f2f5;
            padding: 8px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }
        
        .search-form {
            background: white;
            padding: 12px 16px;
            margin-bottom: 16px;
            border-radius: 8px;
            display: flex;
            gap: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: sticky;
            top: 8px;
            z-index: 100;
        }
        
        .inn-input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #d0d7de;
            border-radius: 6px;
            font-size: 14px;
            height: 40px;
        }
        
        .submit-btn {
            padding: 8px 20px;
            background: #0969da;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }
        
        .submit-btn:hover {
            background: #0a58ca;
        }
        
        .section {
            background: white;
            border-radius: 8px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .section-header {
            background: #f8fafc;
            padding: 10px 16px;
            border-bottom: 1px solid #eaeef2;
            font-weight: 600;
            font-size: 15px;
            color: #1f2328;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .info-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #eaeef2;
            vertical-align: top;
            line-height: 1.4;
        }
        
        .info-table tr:last-child td {
            border-bottom: none;
        }
        
        .info-table td:first-child {
            width: 200px;
            background: #fafbfc;
            font-weight: 600;
            color: #24292f;
            white-space: nowrap;
        }
        
        .info-table td:last-child {
            word-break: break-word;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .badge-active {
            background: #dafbe1;
            color: #1a7f37;
        }
        
        .badge-liquidated {
            background: #ffebe9;
            color: #cf222e;
        }
        
        .badge-warning {
            background: #fff8c5;
            color: #9a6700;
        }
        
        .licenses-list, .founders-list {
            list-style: none;
            padding-left: 0;
        }
        
        .licenses-list li, .founders-list li {
            padding: 8px 12px;
            border-bottom: 1px solid #eaeef2;
        }
        
        .licenses-list li:last-child, .founders-list li:last-child {
            border-bottom: none;
        }
        
        .error-message {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .json-toggle {
            background: #f6f8fa;
            padding: 8px 12px;
            text-align: right;
            border-top: 1px solid #eaeef2;
            font-size: 12px;
        }
        
        .json-toggle button {
            background: none;
            border: none;
            color: #0969da;
            cursor: pointer;
            font-size: 12px;
        }
        
        .json-data {
            display: none;
            background: #f6f8fa;
            padding: 12px;
            font-family: monospace;
            font-size: 11px;
            white-space: pre-wrap;
            word-break: break-all;
            border-top: 1px solid #eaeef2;
        }
        
        .json-data.show {
            display: block;
        }
        
        .subnote {
            font-size: 11px;
            color: #57606a;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <form method="POST" class="search-form">
            <input type="text" 
                   name="inn" 
                   class="inn-input" 
                   placeholder="ИНН (10 или 12 цифр)" 
                   value="<?= htmlspecialchars($inn_input) ?>"
                   pattern="[0-9]{10,12}"
                   maxlength="12"
                   autocomplete="off"
                   required>
            <button type="submit" class="submit-btn">Найти компанию</button>
        </form>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <?php if ($result): ?>
                <!-- Основная информация -->
                <div class="section">
                    <div class="section-header">📋 Основные реквизиты</div>
                    <table class="info-table">
                        <tr><td>Полное наименование</td><td><?= htmlspecialchars($result['full_name']) ?></td></tr>
                        <tr><td>Краткое наименование</td><td><?= htmlspecialchars($result['short_name']) ?></td></tr>
                        <tr><td>ИНН / КПП</td><td><strong><?= $result['inn'] ?></strong> / <strong><?= $result['kpp'] ?></strong></td></tr>
                        <tr><td>ОГРН</td><td><?= $result['ogrn'] ?> (<?= $result['ogrn_date'] ?>)</td></tr>
                        <tr><td>Тип организации</td><td><?= $result['type'] ?> (<?= $result['opf'] ?>)</td></tr>
                        <tr><td>Статус</td><td>
                            <?php if ($result['status_code'] == 'ACTIVE'): ?>
                                <span class="badge badge-active"><?= $result['status'] ?></span>
                            <?php elseif (in_array($result['status_code'], ['LIQUIDATED', 'LIQUIDATING'])): ?>
                                <span class="badge badge-liquidated"><?= $result['status'] ?></span>
                            <?php else: ?>
                                <span class="badge badge-warning"><?= $result['status'] ?></span>
                            <?php endif; ?>
                        </td></tr>
                        <tr><td>Дата регистрации</td><td><?= $result['registration_date'] ?></td></tr>
                        <?php if ($result['liquidation_date'] != '—'): ?>
                        <tr><td>Дата ликвидации</td><td><?= $result['liquidation_date'] ?></td></tr>
                        <?php endif; ?>
                        <tr><td>Руководитель</td><td><?= htmlspecialchars($result['management']) ?></td></tr>
                    </table>
                </div>

                <!-- Адрес и геоданные -->
                <div class="section">
                    <div class="section-header">📍 Адрес и геолокация</div>
                    <table class="info-table">
                        <tr><td>Юридический адрес</td><td><?= htmlspecialchars($result['address']) ?></td></tr>
                        <tr><td>Почтовый индекс</td><td><?= $result['address_postal'] ?></td></tr>
                        <tr><td>Координаты</td><td><?= $result['geo_coords'] ?></td></tr>
                        <?php if ($result['geo_lat'] && $result['geo_lon']): ?>
                        <tr><td>Карта</td><td><a href="https://maps.google.com/?q=<?= $result['geo_lat'] ?>,<?= $result['geo_lon'] ?>" target="_blank">Открыть на карте →</a></td></tr>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- Коды и статистика -->
                <div class="section">
                    <div class="section-header">📊 Коды статистики</div>
                    <table class="info-table">
                        <tr><td>ОКВЭД</td><td><strong><?= htmlspecialchars($result['okved']) ?></strong><br><span class="subnote">Тип: <?= htmlspecialchars($result['okved_type']) ?></span></td></tr>
                        <tr><td>ОКАТО</td><td><?= $result['okato'] ?></td></tr>
                        <tr><td>ОКТМО</td><td><?= $result['oktmo'] ?></td></tr>
                        <tr><td>ОКОГУ</td><td><?= $result['okogu'] ?></td></tr>
                        <tr><td>ОКФС</td><td><?= $result['okfs'] ?></td></tr>
                    </table>
                </div>

                <!-- Налоговая и филиалы -->
                <div class="section">
                    <div class="section-header">🏢 Налоговые органы и филиалы</div>
                    <table class="info-table">
                        <tr><td>Налоговый орган</td><td><?= htmlspecialchars($result['tax_office_name']) ?></td></tr>
                        <tr><td>Система налогообложения</td><td><?= $result['tax_system'] ?></td></tr>
                        <tr><td>Филиалы/представительства</td><td><?= $result['branches_info'] ?></td></tr>
                        <?php if ($result['employees_count'] != '—'): ?>
                        <tr><td>Численность сотрудников</td><td><?= $result['employees_count'] ?></td></tr>
                        <?php endif; ?>
                        <?php if ($result['capital'] != '—'): ?>
                        <tr><td>Уставной капитал</td><td><?= $result['capital'] ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- Контакты -->
                <?php if ($result['phones'] != '—' || $result['emails'] != '—'): ?>
                <div class="section">
                    <div class="section-header">📞 Контактные данные</div>
                    <table class="info-table">
                        <tr><td>Телефоны</td><td><?= htmlspecialchars($result['phones']) ?></td></tr>
                        <tr><td>E-mail</td><td><?= htmlspecialchars($result['emails']) ?></td></tr>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Лицензии -->
                <?php if ($result['has_licenses']): ?>
                <div class="section">
                    <div class="section-header">📜 Лицензии (<?= count($result['licenses']) ?>)</div>
                    <ul class="licenses-list">
                        <?php foreach ($result['licenses'] as $license): ?>
                        <li>
                            <strong><?= htmlspecialchars($license['name']) ?></strong><br>
                            №<?= htmlspecialchars($license['number']) ?> от <?= $license['date'] ?><br>
                            <span class="subnote">Выдана: <?= htmlspecialchars($license['authority']) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Учредители -->
                <?php if (!empty($result['founders'])): ?>
                <div class="section">
                    <div class="section-header">👥 Учредители (<?= $result['founders_count'] ?>)</div>
                    <ul class="founders-list">
                        <?php foreach ($result['founders'] as $founder): ?>
                        <li><?= htmlspecialchars($founder) ?></li>
                        <?php endforeach; ?>
                        <?php if ($result['founders_count'] > 5): ?>
                        <li class="subnote" style="padding: 8px 12px;">...и ещё <?= $result['founders_count'] - 5 ?> учредитель(ей)</li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Служебная информация -->
                <div class="section">
                    <div class="section-header">ℹ️ Информация об источнике</div>
                    <table class="info-table">
                        <tr><td>API источник</td><td>DaData.ru (suggestions.dadata.ru)</td></tr>
                        <tr><td>Версия API</td><td>4.1</td></tr>
                        <tr><td>Дата запроса</td><td><?= $result['update_date'] ?></td></tr>
                    </table>
                </div>

                <!-- Полный JSON (для разработчиков) -->
                <div class="section">
                    <div class="section-header">🔧 Технические данные</div>
                    <div class="json-toggle">
                        <button onclick="toggleJSON()">📄 Показать сырые данные JSON</button>
                    </div>
                    <div id="raw-json" class="json-data"><?= htmlspecialchars(json_encode($result['_raw'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></div>
                </div>

                <script>
                function toggleJSON() {
                    const el = document.getElementById('raw-json');
                    el.classList.toggle('show');
                    const btn = event.target;
                    btn.textContent = el.classList.contains('show') ? '📄 Скрыть JSON' : '📄 Показать сырые данные JSON';
                }
                </script>

            <?php else: ?>
                <div class="error-message">
                    ❌ Компания с ИНН <strong><?= htmlspecialchars($inn_input) ?></strong> не найдена.<br>
                    Проверьте правильность ввода или попробуйте другой ИНН.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>