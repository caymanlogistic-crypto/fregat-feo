<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';
require_once __DIR__ . '/common.php';

maxAdminRequireAuthJson();
if (!isset($pdo) || !($pdo instanceof PDO)) {
    maxAdminJsonOut(['success' => false, 'error' => 'Database connection error']);
}

function ecColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    $key = $table;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $map = [];
        foreach ((array)$rows as $row) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $map[$field] = true;
            }
        }
        $cache[$key] = $map;
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function ecTableReady(PDO $pdo, string $table): bool
{
    return !empty(ecColumns($pdo, $table));
}

function ecText(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $v = trim((string)$row[$key]);
        if ($v !== '') {
            return $v;
        }
    }
    return $default;
}

function ecBool(array $row, array $keys, int $default = 1): int
{
    $value = ecText($row, $keys, (string)$default);
    return ((string)$value === '0' || strtolower((string)$value) === 'false') ? 0 : 1;
}

function ecDefaultCatalog(): array
{
    return [
        'route_planned_to_found' => [
            'category' => 'routes',
            'title' => 'Планируемый маршрут → Рейс сформирован',
            'when' => 'Отправляется при переводе рейса: Планируемый маршрут → Рейс сформирован',
            'placeholders' => '{route_id}, {route_title}, {planned_range}, {actual_range}, {driver}, {manager}, {responsible}, {route_type}, {route_type_line}, {warehouse_line}, {meta_line}, {requests_count}, {weight}, {cost}, {status_from}, {status_to}, {changed_fields_text}',
            'template' => "**РЕЙС СФОРМИРОВАН**\n#{route_id} {route_title}\nНачало вывоза: {planned_range}\n{meta_line}\n{route_type_line}\n{driver}\nРейс закреплен: {manager}\n> 💡 *Просим подготовить товаросопроводительные документы на заявленные дату и водителя.*",
        ],
        'route_found_to_started' => [
            'category' => 'routes',
            'title' => 'Рейс сформирован → Вывоз начался',
            'when' => 'Отправляется при начале вывоза: Рейс сформирован → Вывоз начался',
            'placeholders' => '{route_id}, {route_title}, {planned_range}, {actual_range}, {driver}, {manager}, {responsible}, {route_type}, {route_type_line}, {warehouse_line}, {meta_line}, {requests_count}, {weight}, {cost}, {status_from}, {status_to}, {changed_fields_text}',
            'template' => "**✅ ВЫВОЗ НАЧАЛСЯ**\n#{route_id} {route_title}\n{route_type_line}\nВодитель: {driver}\nСтарт: {actual_start_short}\nЗаявки: {requests_count}\nВес: {weight}\nРейс закреплен: {manager}\n> 💡 *Включено слежение за состоянием трекера.*",
        ],
        'route_found_updated' => [
            'category' => 'routes',
            'title' => 'Изменения сформированного рейса',
            'when' => 'Отправляется при нажатии «Сохранить изменения» в сформированном рейсе, только если есть фактические изменения.',
            'placeholders' => '{route_id}, {route_title}, {planned_range}, {actual_range}, {driver}, {manager}, {responsible}, {route_type}, {route_type_line}, {warehouse_line}, {meta_line}, {requests_count}, {weight}, {cost}, {status_from}, {status_to}, {changed_fields}, {changed_fields_text}, {driver_old}, {driver_new}, {date_from_old}, {date_from_new}, {date_to_old}, {date_to_new}, {cost_old}, {cost_new}, {requests_old}, {requests_new}, {requests_added}, {requests_removed}, {route_type_old}, {route_type_new}, {source_warehouse_old}, {source_warehouse_new}, {destination_warehouse_old}, {destination_warehouse_new}',
            'template' => "Изменения в сформированном рейсе\n#{route_id} {route_title}\n\n{changed_fields_text}",
        ],
        'route_started_updated' => [
            'category' => 'routes',
            'title' => 'Изменения рейса в работе',
            'when' => 'Отправляется при нажатии «Сохранить изменения» в рейсе со статусом «Вывоз начался», только если есть фактические изменения.',
            'placeholders' => '{route_id}, {route_title}, {planned_range}, {actual_range}, {driver}, {manager}, {responsible}, {route_type}, {route_type_line}, {warehouse_line}, {meta_line}, {requests_count}, {weight}, {cost}, {status_from}, {status_to}, {changed_fields}, {changed_fields_text}, {driver_old}, {driver_new}, {date_from_old}, {date_from_new}, {date_to_old}, {date_to_new}, {cost_old}, {cost_new}, {requests_old}, {requests_new}, {requests_added}, {requests_removed}, {route_type_old}, {route_type_new}, {source_warehouse_old}, {source_warehouse_new}, {destination_warehouse_old}, {destination_warehouse_new}',
            'template' => "Изменения в рейсе «Вывоз начался»\n#{route_id} {route_title}\n\n{changed_fields_text}",
        ],
        'route_started_to_found_rollback' => [
            'category' => 'routes',
            'title' => 'Вывоз начался → Рейс сформирован',
            'when' => 'Отправляется при откате рейса: Вывоз начался → Рейс сформирован',
            'placeholders' => '{route_id}, {route_title}, {driver}, {planned_range}, {manager}, {status_from}, {status_to}',
            'template' => "⚠️ ПРИОСТАНОВКА ВЫПОЛНЯЕМОГО РЕЙСА\n#{route_id} {route_title}\n\nСтатус: Вывоз начался → Рейс сформирован\nВодитель: {driver}\nПериод: {planned_range}\nРейс закреплен: {manager}",
        ],
        'route_found_to_planned_rollback' => [
            'category' => 'routes',
            'title' => 'Рейс сформирован → Планируемый маршрут',
            'when' => 'Отправляется при откате рейса: Рейс сформирован → Планируемый маршрут',
            'placeholders' => '{route_id}, {route_title}, {driver}, {planned_range}, {manager}, {status_from}, {status_to}',
            'template' => "⚠️ РЕЙС ВЕРНУТ В ПЛАНИРОВАНИЕ\n#{route_id} {route_title}\n\nСтатус: Рейс сформирован → Планируемый маршрут\nВодитель: {driver}\nПериод: {planned_range}\nРейс закреплен: {manager}",
        ],
        'route_deleted' => [
            'category' => 'routes',
            'title' => 'Удаление маршрута',
            'when' => 'Отправляется при удалении маршрута',
            'placeholders' => '{route_id}, {route_title}, {manager}',
            'template' => "#{route_id} {route_title} - Удален из системы\nРейс закреплен: {manager}",
        ],
        'route_completed' => [
            'category' => 'routes',
            'title' => 'Груз сдан',
            'when' => 'Отправляется при завершении рейса (Груз сдан)',
            'placeholders' => '{driver}, {route_id}, {route_title}',
            'template' => "ТС ПРИБЫЛО НА РАЗГРУЗКУ\n───────────────────\n{driver}\n#{route_id} — {route_title}\n> 💡 *Напоминаю: для оплаты подрядчику нужен полный пакет документов (диагностическая карта, путевой лист и т.д.). Прошу не затягивать с предоставлением.*",
        ],
        'driver_new_tracker_configured' => [
            'category' => 'drivers',
            'title' => 'Новый водитель с трекером',
            'when' => 'Отправляется после успешного назначения нового SLITEX-трекера',
            'placeholders' => '{driver}, {tracker_uniqueid}, {feo_params}, {outID}, {outIP}, {outPort}, {outProtocol}',
            'template' => "Настройки для нового водителя:\n{driver}\nДля водителя: {tracker_uniqueid}\nДля ФЭО: {feo_params}",
        ],
        'driver_retranslation_requested' => [
            'category' => 'drivers',
            'title' => 'Ретрансляция для водителя',
            'when' => 'Отправляется вручную при запросе ретрансляции',
            'placeholders' => '{driver}, {tracker_uniqueid}, {feo_params}, {outID}, {outIP}, {outPort}, {outProtocol}',
            'template' => "Настройки для нового водителя:\nОжидается id треккера для ретрансляции.\nДля ФЭО можно вносить не дожидаясь id:\n{feo_params}",
        ],
        'warehouse_created' => [
            'category' => 'warehouses',
            'title' => 'Создан новый склад',
            'when' => 'Отправляется при нажатии кнопки сохранения нового склада',
            'placeholders' => '{warehouse_id}, {warehouse_name}, {warehouse_address}',
            'template' => "Создан новый склад\n#{warehouse_id} {warehouse_name}\nАдрес: {warehouse_address}",
        ],
        'warehouse_geocode' => [
            'category' => 'warehouses',
            'title' => 'Определение координат склада',
            'when' => 'Отправляется при успешном определении координат склада',
            'placeholders' => '{address}, {latitude}, {longitude}',
            'template' => "Координаты склада определены\nАдрес: {address}\nКоординаты: {latitude}, {longitude}",
        ],
        'driver_create' => [
            'category' => 'drivers',
            'title' => 'Создание водителя',
            'when' => 'Отправляется при успешном создании водителя',
            'placeholders' => '{driver}, {gps_connection_type}',
            'template' => "Создан водитель: {driver}\nТип GPS: {gps_connection_type}",
        ],
        'static_text_saved' => [
            'category' => 'admin',
            'title' => 'Сохранение статического текста',
            'when' => 'Отправляется при сохранении ключа в Static Text Center',
            'placeholders' => '{text_key}, {category}, {used_in}',
            'template' => "Static Text обновлён\nКлюч: {text_key}\nКатегория: {category}\nИспользование: {used_in}",
        ],
        'max_event_template_saved' => [
            'category' => 'admin',
            'title' => 'Сохранение MAX шаблона',
            'when' => 'Отправляется при сохранении шаблона события в MAX Event Center',
            'placeholders' => '{event_key}, {category}',
            'template' => "MAX шаблон обновлён\nСобытие: {event_key}\nКатегория: {category}",
        ],
        'route_control_cron' => [
            'category' => 'system',
            'title' => 'Cron-контроль рейсов',
            'when' => 'Отправляется cron-контролем рейсов (утро/вечер)',
            'placeholders' => '{message}',
            'template' => '{message}',
        ],
    ];
}

function ecDemoContext(string $eventKey = ''): array
{
    return [
        'event_key' => $eventKey,
        'route_id' => '165',
        'actual_range' => '19.05-20.05',
        'responsible' => 'Карина',
        'route_type' => 'warehouse_to_warehouse',
        'warehouse_line' => 'Склады: Краснодар → КРЫМ',
        'cost' => '15 000',
        'status_from' => 'found',
        'status_to' => 'started',
        'changed_fields_text' => "Тип рейса: Отходообразователь → Утилизатор → Склад → Склад\nСклад отправления: не выбран → Краснодар\nСклад назначения: не выбран → КРЫМ",
        'route_title' => 'ТЕСТОВЫЙ МАРШРУТ',
        'planned_range' => '19.05–20.05',
        'actual_start_short' => '19.05',
        'meta_line' => '2 заяв. • 468 кг',
        'driver' => 'К769СТ134(Брюхнов)',
        'manager' => 'Карина',
        'requests_count' => '2',
        'weight' => '468 кг',
        'route_type_line' => 'Вывоз на склад: Склад Феодосия',
        'tracker_uniqueid' => '425252',
        'feo_params' => '887766550425252 31.41.245.15:10364 Wialon',
        'outID' => '887766550425252',
        'outIP' => '31.41.245.15',
        'outPort' => '10364',
        'outProtocol' => 'Wialon',
        'tracker_id' => '[ID ТРЕККЕРА УТОЧНИТЬ]',
        'wialon' => '31.207.74.35:5039',
        'message' => 'Тестовое MAX сообщение',
    ];
}

function ecLoadRuntime(PDO $pdo): array
{
    $defaults = [
        'max_enabled' => '1',
        'quiet_hours_enabled' => '0',
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '08:00',
        'default_group_id' => '0',
    ];
    if (!ecTableReady($pdo, 'system_runtime_settings')) {
        return $defaults;
    }
    $cols = ecColumns($pdo, 'system_runtime_settings');
    $keyCol = isset($cols['setting_key']) ? 'setting_key' : (isset($cols['key']) ? 'key' : (isset($cols['key_name']) ? 'key_name' : ''));
    $valueCol = isset($cols['setting_value']) ? 'setting_value' : (isset($cols['value']) ? 'value' : (isset($cols['setting_val']) ? 'setting_val' : ''));
    if ($keyCol === '' || $valueCol === '') {
        return $defaults;
    }
    $stmt = $pdo->query("SELECT `{$keyCol}` AS k, `{$valueCol}` AS v FROM system_runtime_settings");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ((array)$rows as $row) {
        $k = trim((string)($row['k'] ?? ''));
        if ($k !== '' && array_key_exists($k, $defaults)) {
            $defaults[$k] = (string)($row['v'] ?? $defaults[$k]);
        }
    }
    return $defaults;
}

function ecSaveRuntime(PDO $pdo, array $settings): void
{
    $cols = ecColumns($pdo, 'system_runtime_settings');
    $keyCol = isset($cols['setting_key']) ? 'setting_key' : (isset($cols['key']) ? 'key' : (isset($cols['key_name']) ? 'key_name' : ''));
    $valueCol = isset($cols['setting_value']) ? 'setting_value' : (isset($cols['value']) ? 'value' : (isset($cols['setting_val']) ? 'setting_val' : ''));
    if ($keyCol === '' || $valueCol === '') {
        throw new RuntimeException('Неподдерживаемая структура system_runtime_settings');
    }
    $stmt = $pdo->prepare("INSERT INTO system_runtime_settings(`{$keyCol}`, `{$valueCol}`) VALUES(:k,:v) ON DUPLICATE KEY UPDATE `{$valueCol}`=VALUES(`{$valueCol}`)");
    foreach ($settings as $k => $v) {
        if ($stmt) {
            $stmt->execute([':k' => $k, ':v' => (string)$v]);
        }
    }
}

function ecLoadGroups(PDO $pdo): array
{
    if (!ecTableReady($pdo, 'max_groups')) {
        return [];
    }
    $stmt = $pdo->query('SELECT * FROM max_groups ORDER BY is_default DESC, is_active DESC, id ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $result = [];
    foreach ((array)$rows as $row) {
        $result[] = [
            'id' => (int)($row['id'] ?? 0),
            'title' => ecText($row, ['title', 'name'], ''),
            'group_id' => ecText($row, ['group_id', 'chat_id'], ''),
            'is_active' => ecBool($row, ['is_active', 'active'], 1),
            'is_default' => ecBool($row, ['is_default', 'default_group'], 0),
        ];
    }
    return $result;
}

function ecSeedEvents(PDO $pdo): array
{
    if (!ecTableReady($pdo, 'max_event_templates')) {
        throw new RuntimeException('Таблица max_event_templates не найдена');
    }
    $cols = ecColumns($pdo, 'max_event_templates');
    $catalog = ecDefaultCatalog();
    $created = 0;
    $updated = 0;
    foreach ($catalog as $eventKey => $cfg) {
        if ($eventKey === 'driver_retranslation_requested') {
            $cfg['template'] = "Настройки для нового водителя:\nОжидается id треккера для ретрансляции.\nДля ФЭО можно вносить не дожидаясь id:\n{feo_params}";
            $cfg['placeholders'] = '{driver}, {tracker_uniqueid}, {feo_params}, {outID}, {outIP}, {outPort}, {outProtocol}';
        }
        $stmt = $pdo->prepare('SELECT * FROM max_event_templates WHERE event_key = :event_key LIMIT 1');
        $stmt->execute([':event_key' => $eventKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $set = [];
        $params = [];
        $assign = static function(string $column, $value) use (&$set, &$params, $cols): void {
            if (!isset($cols[$column])) return;
            $set[] = "`{$column}` = :{$column}";
            $params[":{$column}"] = $value;
        };

        $assign('event_key', $eventKey);
        $assign('title', $cfg['title']);
        $assign('category', $cfg['category']);
        $assign('description', $cfg['when']);
        $assign('when_sent', $cfg['when']);
        $assign('template_text', $cfg['template']);
        $assign('default_template_text', $cfg['template']);
        $assign('placeholders', $cfg['placeholders']);
        $assign('is_enabled', 1);

        if (!is_array($row)) {
            if (!empty($set)) {
                $sql = 'INSERT INTO max_event_templates SET ' . implode(', ', $set);
                $ins = $pdo->prepare($sql);
                $ins->execute($params);
                $created++;
            }
            continue;
        }

        $updateSet = [];
        $updateParams = [':id' => (int)($row['id'] ?? 0)];
        $addUpdate = static function (string $column, $value) use (&$updateSet, &$updateParams, $cols): void {
            if (!isset($cols[$column])) return;
            $updateSet[] = "`{$column}` = :{$column}";
            $updateParams[":{$column}"] = $value;
        };
        $addUpdate('title', $cfg['title']);
        $addUpdate('category', $cfg['category']);
        $addUpdate('description', $cfg['when']);
        $addUpdate('when_sent', $cfg['when']);
        $addUpdate('placeholders', $cfg['placeholders']);
        $templateCurrent = trim((string)($row['template_text'] ?? ''));
        $defaultCurrent = trim((string)($row['default_template_text'] ?? ''));
        if (($templateCurrent === '' || ($defaultCurrent !== '' && $templateCurrent === $defaultCurrent)) && isset($cols['template_text'])) {
            $addUpdate('template_text', $cfg['template']);
        }
        if (isset($cols['default_template_text'])) {
            $addUpdate('default_template_text', $cfg['template']);
        }
        if (!empty($updateSet) && (int)($row['id'] ?? 0) > 0) {
            $sql = 'UPDATE max_event_templates SET ' . implode(', ', $updateSet) . ' WHERE id = :id';
            $upd = $pdo->prepare($sql);
            $upd->execute($updateParams);
            $updated++;
        }
    }

    $stmtLegacy = $pdo->prepare('SELECT * FROM max_event_templates WHERE event_key = :event_key LIMIT 1');
    if ($stmtLegacy && $stmtLegacy->execute([':event_key' => 'route_status_rollback'])) {
        $legacyRow = $stmtLegacy->fetch(PDO::FETCH_ASSOC);
        if (is_array($legacyRow) && (int)($legacyRow['id'] ?? 0) > 0) {
            $flags = [];
            foreach (['enabled', 'is_enabled', 'active', 'is_active'] as $flagCol) {
                if (isset($cols[$flagCol])) {
                    $flags[] = "`{$flagCol}` = 0";
                }
            }
            if (!empty($flags)) {
                $updLegacy = $pdo->prepare('UPDATE max_event_templates SET ' . implode(', ', $flags) . ' WHERE id = :id');
                if ($updLegacy) {
                    $updLegacy->execute([':id' => (int)$legacyRow['id']]);
                }
            }
        }
    }

    return ['created' => $created, 'updated' => $updated];
}

function ecLoadEvents(PDO $pdo): array
{
    if (!ecTableReady($pdo, 'max_event_templates')) {
        return [];
    }
    $stmt = $pdo->query('SELECT * FROM max_event_templates ORDER BY COALESCE(category, ""), event_key ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $events = [];
    foreach ((array)$rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $eventKey = ecText($row, ['event_key'], '');
        if ($eventKey === 'route_status_rollback') {
            continue;
        }
        $events[] = [
            'id' => $id,
            'event_key' => $eventKey,
            'title' => ecText($row, ['title'], ''),
            'category' => ecText($row, ['category'], 'system'),
            'description' => ecText($row, ['description', 'when_sent'], ''),
            'when_sent' => ecText($row, ['when_sent', 'description'], ''),
            'is_enabled' => ecBool($row, ['is_enabled', 'enabled', 'is_active', 'active'], 1),
            'group_ref_id' => (int)ecText($row, ['group_ref_id', 'group_id', 'max_group_id'], '0'),
            'quiet_hours_enabled' => ecBool($row, ['quiet_hours_enabled', 'quiet_enabled'], 0),
            'quiet_hours_start' => ecText($row, ['quiet_hours_start', 'quiet_start'], ''),
            'quiet_hours_end' => ecText($row, ['quiet_hours_end', 'quiet_end'], ''),
            'template_text' => ecText($row, ['template_text', 'message_template'], ''),
            'default_template_text' => ecText($row, ['default_template_text'], ''),
            'placeholders' => ecText($row, ['placeholders'], ''),
        ];
    }
    return $events;
}

function ecLoadLogs(PDO $pdo, string $statusFilter = ''): array
{
    if (!ecTableReady($pdo, 'max_logs')) {
        return [];
    }
    $where = '';
    $params = [];
    if ($statusFilter !== '' && in_array($statusFilter, ['success', 'error', 'queued'], true)) {
        $where = 'WHERE status = :status';
        $params[':status'] = $statusFilter;
    }
    $stmt = $pdo->prepare("SELECT * FROM max_logs {$where} ORDER BY id DESC LIMIT 80");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $logs = [];
    foreach ((array)$rows as $row) {
        $logs[] = [
            'id' => (int)($row['id'] ?? 0),
            'created_at' => ecText($row, ['created_at', 'created'], ''),
            'event_key' => ecText($row, ['event_key'], ''),
            'status' => ecText($row, ['status'], ((int)($row['success'] ?? 0) === 1 ? 'success' : 'error')),
            'group_id' => ecText($row, ['group_id'], ''),
            'message_text' => ecText($row, ['message_text'], ''),
            'response_text' => ecText($row, ['response_text'], ''),
            'error_text' => ecText($row, ['error_text'], ''),
        ];
    }
    return $logs;
}

function ecLoadPendingQueue(PDO $pdo): array
{
    if (!ecTableReady($pdo, 'max_pending_queue')) {
        return [];
    }
    $stmt = $pdo->query('SELECT * FROM max_pending_queue ORDER BY id DESC LIMIT 30');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $list = [];
    foreach ((array)$rows as $row) {
        $list[] = [
            'id' => (int)($row['id'] ?? 0),
            'created_at' => ecText($row, ['created_at', 'created', 'queued_at'], ''),
            'event_key' => ecText($row, ['event_key', 'event_name', 'event'], ''),
            'status' => ecText($row, ['status', 'queue_status'], ''),
            'group_id' => ecText($row, ['group_id', 'chat_id', 'group_chat_id'], ''),
            'message_text' => ecText($row, ['message_text', 'message', 'text', 'payload_json', 'payload'], ''),
        ];
    }
    return $list;
}

function ecDescribeTable(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function ecSaveEvent(PDO $pdo, array $input): void
{
    $cols = ecColumns($pdo, 'max_event_templates');
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Некорректный ID события');
    }
    $set = [];
    $params = [':id' => $id];
    $assign = static function(string $column, $value) use (&$set, &$params, $cols): void {
        if (!isset($cols[$column])) return;
        $set[] = "`{$column}` = :{$column}";
        $params[":{$column}"] = $value;
    };
    $assign('title', trim((string)($input['title'] ?? '')));
    $assign('description', trim((string)($input['description'] ?? '')));
    $assign('when_sent', trim((string)($input['when_sent'] ?? '')));
    $assign('category', trim((string)($input['category'] ?? 'system')));
    $assign('template_text', (string)($input['template_text'] ?? ''));
    $enabledValue = !empty($input['is_enabled']) ? 1 : 0;
    $assign('is_enabled', $enabledValue);
    $assign('enabled', $enabledValue);
    $assign('is_active', $enabledValue);
    $assign('active', $enabledValue);
    $assign('group_ref_id', (int)($input['group_ref_id'] ?? 0));
    $assign('group_id', (int)($input['group_ref_id'] ?? 0));
    $assign('max_group_id', (int)($input['group_ref_id'] ?? 0));
    $assign('quiet_hours_enabled', !empty($input['quiet_hours_enabled']) ? 1 : 0);
    $assign('quiet_enabled', !empty($input['quiet_hours_enabled']) ? 1 : 0);
    $assign('quiet_hours_start', trim((string)($input['quiet_hours_start'] ?? '')));
    $assign('quiet_hours_end', trim((string)($input['quiet_hours_end'] ?? '')));
    $assign('quiet_start', trim((string)($input['quiet_hours_start'] ?? '')));
    $assign('quiet_end', trim((string)($input['quiet_hours_end'] ?? '')));
    $assign('placeholders', trim((string)($input['placeholders'] ?? '')));

    if (empty($set)) {
        throw new RuntimeException('Нет полей для обновления');
    }
    $idColumn = isset($cols['id']) ? 'id' : (isset($cols['event_id']) ? 'event_id' : 'id');
    $stmt = $pdo->prepare('UPDATE max_event_templates SET ' . implode(', ', $set) . ' WHERE `' . $idColumn . '` = :id');
    $stmt->execute($params);
}

$action = trim((string)maxAdminPost('action'));
if ($action === '') {
    maxAdminJsonOut(['success' => false, 'error' => 'Пустое действие']);
}

if (!ecTableReady($pdo, 'max_event_templates') || !ecTableReady($pdo, 'max_groups') || !ecTableReady($pdo, 'max_logs') || !ecTableReady($pdo, 'max_pending_queue') || !ecTableReady($pdo, 'system_runtime_settings')) {
    maxAdminJsonOut(['success' => false, 'error' => 'Не найдены обязательные таблицы event center']);
}

try {
    if ($action === 'seed_events') {
        $result = ecSeedEvents($pdo);
        maxAdminJsonOut(['success' => true, 'message' => 'События синхронизированы', 'data' => $result]);
    }

    if ($action === 'load_state') {
        $statusFilter = trim((string)maxAdminPost('status_filter', ''));
        maxAdminJsonOut([
            'success' => true,
            'data' => [
                'runtime' => ecLoadRuntime($pdo),
                'groups' => ecLoadGroups($pdo),
                'events' => ecLoadEvents($pdo),
                'logs' => ecLoadLogs($pdo, $statusFilter),
                'pending_queue' => ecLoadPendingQueue($pdo),
                'categories' => [
                    ['key' => 'routes', 'title' => 'Рейсы'],
                    ['key' => 'drivers', 'title' => 'Водители'],
                    ['key' => 'warehouses', 'title' => 'Склады'],
                    ['key' => 'slitex', 'title' => 'SLITEX'],
                    ['key' => 'system', 'title' => 'Системные'],
                ],
            ],
        ]);
    }

    if ($action === 'debug_queue_schema') {
        maxAdminJsonOut([
            'success' => true,
            'data' => [
                'columns' => ecDescribeTable($pdo, 'max_pending_queue'),
                'rows' => ecLoadPendingQueue($pdo),
            ],
        ]);
    }

    if ($action === 'save_runtime') {
        ecSaveRuntime($pdo, [
            'max_enabled' => maxAdminPost('max_enabled', '0') === '1' ? '1' : '0',
            'quiet_hours_enabled' => maxAdminPost('quiet_hours_enabled', '0') === '1' ? '1' : '0',
            'quiet_hours_start' => trim((string)maxAdminPost('quiet_hours_start', '22:00')),
            'quiet_hours_end' => trim((string)maxAdminPost('quiet_hours_end', '08:00')),
            'default_group_id' => (string)((int)maxAdminPost('default_group_id', 0)),
        ]);
        maxAdminJsonOut(['success' => true, 'message' => 'Runtime настройки сохранены']);
    }

    if ($action === 'save_event') {
        $savedEventKey = trim((string)maxAdminPost('event_key', ''));
        ecSaveEvent($pdo, [
            'id' => (int)maxAdminPost('id', 0),
            'title' => maxAdminPost('title', ''),
            'description' => maxAdminPost('description', ''),
            'when_sent' => maxAdminPost('when_sent', ''),
            'category' => maxAdminPost('category', 'system'),
            'template_text' => maxAdminPost('template_text', ''),
            'is_enabled' => maxAdminPost('is_enabled', '0') === '1',
            'group_ref_id' => (int)maxAdminPost('group_ref_id', 0),
            'quiet_hours_enabled' => maxAdminPost('quiet_hours_enabled', '0') === '1',
            'quiet_hours_start' => maxAdminPost('quiet_hours_start', ''),
            'quiet_hours_end' => maxAdminPost('quiet_hours_end', ''),
            'placeholders' => maxAdminPost('placeholders', ''),
        ]);
        if ($savedEventKey !== '' && function_exists('notifyEvent')) {
            notifyEvent('max_event_template_saved', [
                'event_key' => $savedEventKey,
                'category' => (string)maxAdminPost('category', 'system'),
            ], "MAX шаблон обновлён: {$savedEventKey}");
        }
        maxAdminJsonOut(['success' => true, 'message' => 'Событие сохранено']);
    }

    if ($action === 'render_event') {
        $eventKey = trim((string)maxAdminPost('event_key', ''));
        $template = (string)maxAdminPost('template_text', '');
        $render = mapAdminRenderTemplate($template, ecDemoContext($eventKey));
        maxAdminJsonOut(['success' => true, 'data' => ['render' => $render]]);
    }

    if ($action === 'test_event') {
        $eventKey = trim((string)maxAdminPost('event_key', ''));
        $template = (string)maxAdminPost('template_text', '');
        $render = mapAdminRenderTemplate($template, ecDemoContext($eventKey));
        $response = sendMaxNotify($render, 'markdown', [
            'event_key' => $eventKey,
            'context' => array_merge(ecDemoContext($eventKey), ['message' => $render]),
        ]);
        if (empty($response['success'])) {
            maxAdminJsonOut(['success' => false, 'error' => 'Ошибка отправки: ' . (string)($response['error'] ?? 'unknown')]);
        }
        maxAdminJsonOut(['success' => true, 'message' => 'Тест отправлен']);
    }

    if ($action === 'restore_default') {
        $eventKey = trim((string)maxAdminPost('event_key', ''));
        if ($eventKey === '') {
            throw new RuntimeException('event_key обязателен');
        }
        $defaults = ecDefaultCatalog();
        $template = isset($defaults[$eventKey]) ? (string)$defaults[$eventKey]['template'] : '';
        if ($template === '') {
            $stmt = $pdo->prepare('SELECT default_template_text FROM max_event_templates WHERE event_key = :event_key LIMIT 1');
            $stmt->execute([':event_key' => $eventKey]);
            $template = trim((string)$stmt->fetchColumn());
        }
        if ($template === '') {
            throw new RuntimeException('Для события нет default template');
        }
        $stmt = $pdo->prepare('UPDATE max_event_templates SET template_text = :template WHERE event_key = :event_key');
        $stmt->execute([':template' => $template, ':event_key' => $eventKey]);
        maxAdminJsonOut(['success' => true, 'message' => 'Шаблон восстановлен', 'data' => ['template_text' => $template]]);
    }

    if ($action === 'retry_log') {
        $logId = (int)maxAdminPost('log_id', 0);
        if ($logId <= 0) {
            throw new RuntimeException('Некорректный log_id');
        }
        $stmt = $pdo->prepare('SELECT * FROM max_logs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Лог не найден');
        }
        $eventKey = ecText($row, ['event_key'], '');
        $message = ecText($row, ['message_text'], '');
        if ($message === '') {
            throw new RuntimeException('В логе нет текста сообщения');
        }
        $res = sendMaxNotify($message, 'markdown', [
            'event_key' => $eventKey,
            'context' => ['message' => $message],
        ]);
        if (empty($res['success'])) {
            throw new RuntimeException('Ошибка повтора: ' . (string)($res['error'] ?? 'unknown'));
        }
        maxAdminJsonOut(['success' => true, 'message' => 'Повторная отправка выполнена']);
    }

    maxAdminJsonOut(['success' => false, 'error' => 'Неизвестное действие']);
} catch (Throwable $e) {
    maxAdminJsonOut(['success' => false, 'error' => $e->getMessage()]);
}
