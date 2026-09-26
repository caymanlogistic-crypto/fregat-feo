<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['max_admin_auth']) || (int)$_SESSION['max_admin_auth'] !== 1) {
    echo json_encode(['success' => false, 'error' => 'Не авторизовано'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['success' => false, 'error' => 'Database connection error'], JSON_UNESCAPED_UNICODE);
    exit;
}

function post($k, $d = '') { return $_POST[$k] ?? $d; }
function out(array $p): void { echo json_encode($p, JSON_UNESCAPED_UNICODE); exit; }

function adminTablesReady(PDO $pdo): bool {
    try {
        foreach (['max_settings','max_groups','max_message_templates','max_send_log'] as $t) {
            $s = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $t) . "'");
            if (!$s || !$s->fetchColumn()) return false;
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
function upsertSetting(PDO $pdo, string $k, string $v): void {
    $stmt = $pdo->prepare('INSERT INTO max_settings(setting_key, setting_value) VALUES(:k,:v) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    if ($stmt) $stmt->execute([':k'=>$k, ':v'=>$v]);
}
function demoContext(): array {
    return [
        'route_id' => '165','route_title' => 'ТЕСТОВЫЙ МАРШРУТ','planned_range' => '19.05–20.05',
        'planned_range_before' => '19.05–21.05','planned_range_after' => '18.05–21.05','actual_start_short' => '19.05',
        'meta_line' => '2 заяв. • 468 кг','driver' => 'К769СТ134(Брюхнов)','driver_before' => 'М139МО774(Иванов)',
        'driver_after' => 'К769СТ134(Петров)','manager' => 'Карина','requests_count' => '2','requests_count_before' => '2',
        'requests_count_after' => '1','weight' => '468 кг','weight_before' => '468 кг','weight_after' => '153 кг',
        'removed_ids_line' => 'Исключенные заявки: 267619','added_ids_line' => 'Добавленные заявки: 123456',
        'changes_block' => "Заявки: 2 → 1\nВес: 468 кг → 153 кг\nВодитель: М139МО774(Иванов) → К769СТ134(Петров)",
        'route_type_line' => 'Вывоз на склад: Склад Феодосия','message' => 'Тестовое сообщение MAX',
    ];
}
function templateCatalog(): array {
    return [
        'planned_to_found' => ['title' => 'Планируемый маршрут → Рейс сформирован','description' => 'Отправляется при переводе рейса: Планируемый маршрут → Рейс сформирован','template' => "**РЕЙС СФОРМИРОВАН**\n#{route_id} {route_title}\nНачало вывоза: {planned_range}\n{meta_line}\n{route_type_line}\n{driver}\nРейс закреплен: {manager}\n> 💡 *Просим подготовить товаросопроводительные документы на заявленные дату и водителя.*"],
        'found_to_started' => ['title' => 'Рейс сформирован → Вывоз начался','description' => 'Отправляется при начале вывоза: Рейс сформирован → Вывоз начался','template' => "**✅ ВЫВОЗ НАЧАЛСЯ**\n#{route_id} {route_title}\n{route_type_line}\nВодитель: {driver}\nСтарт: {actual_start_short}\nЗаявки: {requests_count}\nВес: {weight}\nРейс закреплен: {manager}\n> 💡 *Включено слежение за состоянием трекера.*"],
        'started_to_found_rollback' => ['title' => 'Вывоз начался → Рейс сформирован','description' => 'Отправляется при откате рейса: Вывоз начался → Рейс сформирован','template' => "**⚠️ ПРЕОСТАНОВКА ВЫПОЛНЯЕМОГО РЕЙСА ⚠️**\n#{route_id} {route_title}\n{route_type_line}\nВодитель: {driver}\nСтарт: {actual_start_short}\nЗаявки: {requests_count}\nВес: {weight}\nРейс закреплен: {manager}\n> 💡 *ВНИМАНИЕ. Статус рейса изменён с «Выполняемые» на «Сформированные». В связи с этим вероятна корректировка перечня вывозимых заявок либо замена подрядчика.*"],
        'found_to_planned_rollback' => ['title' => 'Рейс сформирован → Планируемый маршрут','description' => 'Отправляется при откате рейса: Рейс сформирован → Планируемый маршрут','template' => "**#{route_id} {route_title}**\nвозвращён в «Планируемый»\n{route_type_line}\nРейс закреплен: {manager}\n> 💡 *Подготовку документов приостановить до переформирования рейса.*"],
        'planned_date_update' => ['title' => 'Обновление плановых дат/планового рейса','description' => 'Отправляется при добавлении или изменении плановых дат в плановом рейсе','template' => '{message}'],
        'route_diff_found' => ['title' => 'Изменение в сформированном рейсе','description' => 'Отправляется при изменении данных рейса в статусе «Рейс сформирован»','template' => "**⚠️ ИЗМЕНЕНИЕ В СФОРМИРОВАННОМ РЕЙСЕ ⚠️**\n#{route_id} {route_title}\nВодитель: {driver_before} → {driver_after}\nДаты: {planned_range_before} → {planned_range_after}\nЗаявки: {requests_count_before} → {requests_count_after}\nВес: {weight_before} → {weight_after}\n{removed_ids_line}\n{added_ids_line}\nРейс закреплен: {manager}"],
        'route_diff_started' => ['title' => 'Изменение в выполняемом рейсе','description' => 'Отправляется при изменении данных рейса в статусе «Вывоз начался»','template' => "Изменён рейс #{route_id} во время выполнения\n{route_title} | {manager}\n{changes_block}\nРейс находится в выполнении. Проверьте корректность изменений."],
        'route_deleted' => ['title' => 'Удаление маршрута','description' => 'Отправляется при удалении планируемого маршрута','template' => "#{route_id} {route_title} - Удален из системы\n{route_type_line}\nРейс закреплен: {manager}"],
        'route_completed' => ['title' => 'Вывоз начался → Груз сдан','description' => 'Отправляется при завершении рейса и переводе в «Груз сдан»','template' => "ТС ПРИБЫЛО НА РАЗГРУЗКУ\n───────────────────\n{route_type_line}\n{driver}\n#{route_id} — {route_title}\n> 💡 *Напоминаю: для оплаты подрядчику нужен полный пакет документов (диагностическая карта, путевой лист и т.д.). Прошу не затягивать с предоставлением.*"],
        'route_control_cron' => ['title' => 'Cron-контроль рейсов','description' => 'Отправляется cron-контролем рейсов (утро/вечер) для проблемных рейсов на сегодня','template' => '{message}'],
        'test_message' => ['title' => 'Тестовое сообщение','description' => 'Отправляется вручную из админки для проверки доставки и форматирования','template' => "Тест MAX уведомления\n> 💡 *Проверка markdown-цитаты.*"],
        'driver_new_tracker_configured' => ['title' => 'Новый водитель с трекером','description' => 'Отправляется после успешного назначения нового SLITEX-трекера','template' => "Настройки для нового водителя:\n{driver}\nДля водителя: {tracker_uniqueid}\nДля ФЭО: {feo_params}"],
        'driver_retranslation_requested' => ['title' => 'Ретрансляция для водителя','description' => 'Отправляется при запросе ретрансляции GPS','template' => "Настройки для нового водителя:\nОжидается id треккера для ретрансляции.\nДля ФЭО можно вносить не дожидаясь id:\n{feo_params}"],
        'warehouse_created' => ['title' => 'Создан новый склад','description' => 'Отправляется при создании нового склада','template' => "Создан новый склад\n#{warehouse_id} {warehouse_name}\nАдрес: {warehouse_address}"],
        'warehouse_geocode' => ['title' => 'Определение координат склада','description' => 'Отправляется при успешном геокодировании адреса склада','template' => "Координаты склада определены\nАдрес: {address}\nКоординаты: {latitude}, {longitude}"],
        'static_text_saved' => ['title' => 'Сохранение статического текста','description' => 'Отправляется при сохранении ключа в Static Text Center','template' => "Static Text обновлён\nКлюч: {text_key}\nКатегория: {category}\nИспользование: {used_in}"],
        'max_event_template_saved' => ['title' => 'Сохранение MAX шаблона','description' => 'Отправляется при сохранении шаблона события в MAX Event Center','template' => "MAX шаблон обновлён\nСобытие: {event_key}\nКатегория: {category}"],
    ];
}

if (!adminTablesReady($pdo)) {
    out(['success' => false, 'error' => 'Таблицы MAX Admin не найдены']);
}

$action = trim((string)post('action'));
if ($action === '') out(['success' => false, 'error' => 'Пустое действие']);

try {
    if ($action === 'save_global') {
        upsertSetting($pdo, 'max_enabled', post('max_enabled','0') === '1' ? '1' : '0');
        upsertSetting($pdo, 'default_group_id', (string)((int)post('default_group_id', '0')));
        out(['success' => true, 'message' => 'Глобальные настройки сохранены']);
    }
    if ($action === 'add_group') {
        $title = trim((string)post('title')); $groupId = trim((string)post('group_id'));
        if ($title === '' || $groupId === '') out(['success' => false, 'error' => 'Укажите название и group_id']);
        $stmt = $pdo->prepare('INSERT INTO max_groups(title, group_id, is_active, is_default) VALUES(:t,:g,:a,0)');
        $stmt->execute([':t'=>$title, ':g'=>$groupId, ':a'=>post('is_active','0') === '1' ? 1 : 0]);
        out(['success' => true, 'message' => 'Группа добавлена', 'data' => ['id' => (int)$pdo->lastInsertId()]]);
    }
    if ($action === 'save_group') {
        $id = (int)post('id');
        $stmt = $pdo->prepare('UPDATE max_groups SET title=:t, group_id=:g, is_active=:a WHERE id=:id');
        $stmt->execute([':t'=>trim((string)post('title')), ':g'=>trim((string)post('group_id')), ':a'=>post('is_active','0') === '1' ? 1 : 0, ':id'=>$id]);
        out(['success' => true, 'message' => 'Группа обновлена']);
    }
    if ($action === 'delete_group') {
        $id = (int)post('id');
        $stmt = $pdo->prepare('DELETE FROM max_groups WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        out(['success' => true, 'message' => 'Группа удалена']);
    }
    if ($action === 'set_default_group') {
        $id = (int)post('id');
        $pdo->exec('UPDATE max_groups SET is_default=0');
        $stmt = $pdo->prepare('UPDATE max_groups SET is_default=1 WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        upsertSetting($pdo, 'default_group_id', (string)$id);
        out(['success' => true, 'message' => 'Группа по умолчанию изменена']);
    }
    if ($action === 'save_template') {
        $id = (int)post('id');
        $stmt = $pdo->prepare('UPDATE max_message_templates SET title=:title, template_text=:template_text, is_enabled=:is_enabled, description=:description WHERE id=:id');
        $stmt->execute([':title'=>trim((string)post('title')), ':template_text'=>(string)post('template_text'), ':is_enabled'=>post('is_enabled','0') === '1' ? 1 : 0, ':description'=>trim((string)post('description')), ':id'=>$id]);
        out(['success' => true, 'message' => 'Шаблон обновлен']);
    }
    if ($action === 'seed_templates') {
        $catalog = templateCatalog();
        $stmt = $pdo->prepare('INSERT INTO max_message_templates(event_key,title,template_text,is_enabled,description) VALUES(:event_key,:title,:template_text,1,:description) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),template_text=CASE WHEN template_text = "{message}" OR template_text = "" THEN VALUES(template_text) ELSE template_text END');
        foreach ($catalog as $eventKey => $cfg) {
            $stmt->execute([':event_key'=>$eventKey, ':title'=>$cfg['title'], ':template_text'=>$cfg['template'], ':description'=>$cfg['description']]);
        }
        out(['success' => true, 'message' => 'Production-шаблоны обновлены']);
    }
    if ($action === 'send_test') {
        $lastAt = (int)($_SESSION['max_admin_last_test_at'] ?? 0);
        if ($lastAt > 0 && (time() - $lastAt) < 4) out(['success' => false, 'error' => 'Подождите 4 сек перед повторным тестом']);
        $eventKey = trim((string)post('event_key'));
        $message = trim((string)post('message'));
        if ($message === '') out(['success' => false, 'error' => 'Укажите текст тестового сообщения']);
        $res = sendMaxNotify($message, 'markdown', ['event_key' => $eventKey, 'context' => ['message' => $message]]);
        if (empty($res['success'])) out(['success' => false, 'error' => 'Ошибка отправки: ' . (string)($res['error'] ?? 'unknown')]);
        $_SESSION['max_admin_last_test_at'] = time();
        out(['success' => true, 'message' => 'Тестовое сообщение отправлено']);
    }
    if ($action === 'test_template') {
        $lastAt = (int)($_SESSION['max_admin_last_test_at'] ?? 0);
        if ($lastAt > 0 && (time() - $lastAt) < 4) out(['success' => false, 'error' => 'Подождите 4 сек перед повторным тестом']);
        $templateText = (string)post('template_text');
        if ($templateText === '') out(['success' => false, 'error' => 'Пустой template_text']);
        $rendered = mapAdminRenderTemplate($templateText, demoContext());
        $res = sendMaxNotify($rendered, 'markdown', ['event_key' => '', 'context' => ['message' => $rendered]]);
        if (empty($res['success'])) out(['success' => false, 'error' => 'Ошибка отправки теста шаблона: ' . (string)($res['error'] ?? 'unknown')]);
        $_SESSION['max_admin_last_test_at'] = time();
        out(['success' => true, 'message' => 'Тест шаблона отправлен в MAX']);
    }

    out(['success' => false, 'error' => 'Неизвестное действие']);
} catch (Throwable $e) {
    out(['success' => false, 'error' => $e->getMessage()]);
}
