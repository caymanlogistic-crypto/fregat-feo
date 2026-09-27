<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection error';
    exit;
}

define('MAX_ADMIN_PASSWORD', trim((string)(getenv('MAX_ADMIN_PASSWORD') ?: '')));

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function isAuthed(): bool { return !empty($_SESSION['max_admin_auth']); }
function post($k, $d='') { return $_POST[$k] ?? $d; }

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

function getSettings(PDO $pdo): array {
    $res = [];
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM max_settings');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ((array)$rows as $r) $res[(string)$r['setting_key']] = (string)$r['setting_value'];
    return $res;
}

function upsertSetting(PDO $pdo, string $k, string $v): void {
    $stmt = $pdo->prepare('INSERT INTO max_settings(setting_key, setting_value) VALUES(:k,:v) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    if ($stmt) $stmt->execute([':k'=>$k, ':v'=>$v]);
}

function templateCatalog(): array {
    return [
        'planned_to_found' => [
            'title' => 'Планируемый маршрут → Рейс сформирован',
            'description' => 'Отправляется при переводе рейса: Планируемый маршрут → Рейс сформирован',
            'template' => "**РЕЙС СФОРМИРОВАН**\n#{route_id} {route_title}\nНачало вывоза: {planned_range}\n{meta_line}\n{route_type_line}\n{driver}\nРейс закреплен: {manager}\n> 💡 *Просим подготовить товаросопроводительные документы на заявленные дату и водителя.*",
        ],
        'found_to_started' => [
            'title' => 'Рейс сформирован → Вывоз начался',
            'description' => 'Отправляется при начале вывоза: Рейс сформирован → Вывоз начался',
            'template' => "**✅ ВЫВОЗ НАЧАЛСЯ**\n#{route_id} {route_title}\n{route_type_line}\nВодитель: {driver}\nСтарт: {actual_start_short}\nЗаявки: {requests_count}\nВес: {weight}\nРейс закреплен: {manager}\n> 💡 *Включено слежение за состоянием трекера.*",
        ],
        'started_to_found_rollback' => [
            'title' => 'Вывоз начался → Рейс сформирован',
            'description' => 'Отправляется при откате рейса: Вывоз начался → Рейс сформирован',
            'template' => "**⚠️ ПРЕОСТАНОВКА ВЫПОЛНЯЕМОГО РЕЙСА ⚠️**\n#{route_id} {route_title}\n{route_type_line}\nВодитель: {driver}\nСтарт: {actual_start_short}\nЗаявки: {requests_count}\nВес: {weight}\nРейс закреплен: {manager}\n> 💡 *ВНИМАНИЕ. Статус рейса изменён с «Выполняемые» на «Сформированные». В связи с этим вероятна корректировка перечня вывозимых заявок либо замена подрядчика.*",
        ],
        'found_to_planned_rollback' => [
            'title' => 'Рейс сформирован → Планируемый маршрут',
            'description' => 'Отправляется при откате рейса: Рейс сформирован → Планируемый маршрут',
            'template' => "**#{route_id} {route_title}**\nвозвращён в «Планируемый»\n{route_type_line}\nРейс закреплен: {manager}\n> 💡 *Подготовку документов приостановить до переформирования рейса.*",
        ],
        'planned_date_update' => [
            'title' => 'Обновление плановых дат/планового рейса',
            'description' => 'Отправляется при добавлении или изменении плановых дат в плановом рейсе',
            'template' => "{message}",
        ],
        'route_diff_found' => [
            'title' => 'Изменение в сформированном рейсе',
            'description' => 'Отправляется при изменении данных рейса в статусе «Рейс сформирован»',
            'template' => "**⚠️ ИЗМЕНЕНИЕ В СФОРМИРОВАННОМ РЕЙСЕ ⚠️**\n#{route_id} {route_title}\nВодитель: {driver_before} → {driver_after}\nДаты: {planned_range_before} → {planned_range_after}\nЗаявки: {requests_count_before} → {requests_count_after}\nВес: {weight_before} → {weight_after}\n{removed_ids_line}\n{added_ids_line}\nРейс закреплен: {manager}",
        ],
        'route_diff_started' => [
            'title' => 'Изменение в выполняемом рейсе',
            'description' => 'Отправляется при изменении данных рейса в статусе «Вывоз начался»',
            'template' => "Изменён рейс #{route_id} во время выполнения\n{route_title} | {manager}\n{changes_block}\nРейс находится в выполнении. Проверьте корректность изменений.",
        ],
        'route_deleted' => [
            'title' => 'Удаление маршрута',
            'description' => 'Отправляется при удалении планируемого маршрута',
            'template' => "#{route_id} {route_title} - Удален из системы\n{route_type_line}\nРейс закреплен: {manager}",
        ],
        'route_completed' => [
            'title' => 'Вывоз начался → Груз сдан',
            'description' => 'Отправляется при завершении рейса и переводе в «Груз сдан»',
            'template' => "ТС ПРИБЫЛО НА РАЗГРУЗКУ\n───────────────────\n{route_type_line}\n{driver}\n#{route_id} — {route_title}\n> 💡 *Напоминаю: для оплаты подрядчику нужен полный пакет документов (диагностическая карта, путевой лист и т.д.). Прошу не затягивать с предоставлением.*",
        ],
        'route_control_cron' => [
            'title' => 'Cron-контроль рейсов',
            'description' => 'Отправляется cron-контролем рейсов (утро/вечер) для проблемных рейсов на сегодня',
            'template' => "{message}",
        ],
        'test_message' => [
            'title' => 'Тестовое сообщение',
            'description' => 'Отправляется вручную из админки для проверки доставки и форматирования',
            'template' => "Тест MAX уведомления\n> 💡 *Проверка markdown-цитаты.*",
        ],
        'driver_new_tracker_configured' => [
            'title' => 'Новый водитель с трекером',
            'description' => 'Отправляется после успешного назначения нового SLITEX-трекера',
            'template' => "Настройки для нового водителя:\n{driver}\nДля водителя: {tracker_uniqueid}\nДля ФЭО: {feo_params}",
        ],
        'driver_retranslation_requested' => [
            'title' => 'Ретрансляция для водителя',
            'description' => 'Отправляется при запросе ретрансляции GPS',
            'template' => "Настройки для нового водителя:\nОжидается id треккера для ретрансляции.\nДля ФЭО можно вносить не дожидаясь id:\n{feo_params}",
        ],
        'warehouse_created' => [
            'title' => 'Создан новый склад',
            'description' => 'Отправляется при создании нового склада',
            'template' => "Создан новый склад\n#{warehouse_id} {warehouse_name}\nАдрес: {warehouse_address}",
        ],
        'warehouse_geocode' => [
            'title' => 'Определение координат склада',
            'description' => 'Отправляется при успешном геокодировании адреса склада',
            'template' => "Координаты склада определены\nАдрес: {address}\nКоординаты: {latitude}, {longitude}",
        ],
        'static_text_saved' => [
            'title' => 'Сохранение статического текста',
            'description' => 'Отправляется при сохранении ключа в Static Text Center',
            'template' => "Static Text обновлён\nКлюч: {text_key}\nКатегория: {category}\nИспользование: {used_in}",
        ],
        'max_event_template_saved' => [
            'title' => 'Сохранение MAX шаблона',
            'description' => 'Отправляется при сохранении шаблона события в MAX Event Center',
            'template' => "MAX шаблон обновлён\nСобытие: {event_key}\nКатегория: {category}",
        ],
    ];
}

function demoContext(): array {
    return [
        'route_id' => '165',
        'route_title' => 'ТЕСТОВЫЙ МАРШРУТ',
        'planned_range' => '19.05–20.05',
        'planned_range_before' => '19.05–21.05',
        'planned_range_after' => '18.05–21.05',
        'actual_start_short' => '19.05',
        'meta_line' => '2 заяв. • 468 кг брутто',
        'driver' => 'К769СТ134(Брюхнов)',
        'driver_before' => 'М139МО774(Иванов)',
        'driver_after' => 'К769СТ134(Петров)',
        'manager' => 'Карина',
        'requests_count' => '2',
        'requests_count_before' => '2',
        'requests_count_after' => '1',
        'weight' => '468 кг брутто',
        'weight_before' => '468 кг брутто',
        'weight_after' => '153 кг брутто',
        'removed_ids_line' => 'Исключенные заявки: 267619',
        'added_ids_line' => 'Добавленные заявки: 123456',
        'changes_block' => 'Заявки: 2 → 1\nВес: 468 кг брутто → 153 кг брутто\nВодитель: М139МО774(Иванов) → К769СТ134(Петров)',
        'route_type_line' => 'Вывоз на склад: Склад Феодосия',
        'message' => 'Тестовое сообщение MAX',
    ];
}

$flash = '';
$flashType = 'ok';
$catalog = templateCatalog();

if (!isAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && post('action') === 'login') {
    if (MAX_ADMIN_PASSWORD !== '' && hash_equals(MAX_ADMIN_PASSWORD, (string)post('password'))) {
        $_SESSION['max_admin_auth'] = 1;
        header('Location: max_admin.php');
        exit;
    }
    $flash = 'Неверный пароль';
    $flashType = 'err';
}

if (isAuthed() && ($_GET['logout'] ?? '') === '1') {
    unset($_SESSION['max_admin_auth']);
    session_destroy();
    header('Location: max_admin.php');
    exit;
}

if (isAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)post('action');
    try {
        if ($action === 'save_global') {
            upsertSetting($pdo, 'max_enabled', post('max_enabled','0') === '1' ? '1' : '0');
            upsertSetting($pdo, 'default_group_id', (string)((int)post('default_group_id', '0')));
            $flash = 'Глобальные настройки сохранены';
        } elseif ($action === 'add_group') {
            $title = trim((string)post('title'));
            $groupId = trim((string)post('group_id'));
            if ($title === '' || $groupId === '') {
                throw new RuntimeException('Укажите название и group_id');
            }
            $stmt = $pdo->prepare('INSERT INTO max_groups(title, group_id, is_active, is_default) VALUES(:t,:g,:a,0)');
            $stmt->execute([':t'=>$title, ':g'=>$groupId, ':a'=>post('is_active','0') === '1' ? 1 : 0]);
            $flash = 'Группа добавлена';
        } elseif ($action === 'save_group') {
            $id = (int)post('id');
            $stmt = $pdo->prepare('UPDATE max_groups SET title=:t, group_id=:g, is_active=:a WHERE id=:id');
            $stmt->execute([':t'=>trim((string)post('title')), ':g'=>trim((string)post('group_id')), ':a'=>post('is_active','0') === '1' ? 1 : 0, ':id'=>$id]);
            $flash = 'Группа обновлена';
        } elseif ($action === 'delete_group') {
            $id = (int)post('id');
            $stmt = $pdo->prepare('DELETE FROM max_groups WHERE id=:id');
            $stmt->execute([':id'=>$id]);
            $flash = 'Группа удалена';
        } elseif ($action === 'set_default_group') {
            $id = (int)post('id');
            $pdo->exec('UPDATE max_groups SET is_default=0');
            $stmt = $pdo->prepare('UPDATE max_groups SET is_default=1 WHERE id=:id');
            $stmt->execute([':id'=>$id]);
            upsertSetting($pdo, 'default_group_id', (string)$id);
            $flash = 'Группа по умолчанию изменена';
        } elseif ($action === 'save_template') {
            $id = (int)post('id');
            $stmt = $pdo->prepare('UPDATE max_message_templates SET title=:title, template_text=:template_text, is_enabled=:is_enabled, description=:description WHERE id=:id');
            $stmt->execute([
                ':title'=>trim((string)post('title')),
                ':template_text'=>(string)post('template_text'),
                ':is_enabled'=>post('is_enabled','0') === '1' ? 1 : 0,
                ':description'=>trim((string)post('description')),
                ':id'=>$id,
            ]);
            $flash = 'Шаблон обновлен';
        } elseif ($action === 'seed_templates') {
            $stmt = $pdo->prepare(
                'INSERT INTO max_message_templates(event_key,title,template_text,is_enabled,description) VALUES(:event_key,:title,:template_text,1,:description)
                 ON DUPLICATE KEY UPDATE
                   title=VALUES(title),
                   description=VALUES(description),
                   template_text=CASE WHEN template_text = "{message}" OR template_text = "" THEN VALUES(template_text) ELSE template_text END'
            );
            foreach ($catalog as $eventKey => $cfg) {
                $stmt->execute([
                    ':event_key' => $eventKey,
                    ':title' => $cfg['title'],
                    ':template_text' => $cfg['template'],
                    ':description' => $cfg['description'],
                ]);
            }
            $flash = 'Production-шаблоны обновлены';
        } elseif ($action === 'send_test') {
            $eventKey = trim((string)post('event_key'));
            $message = trim((string)post('message'));
            if ($message === '') {
                throw new RuntimeException('Укажите текст тестового сообщения');
            }
            $res = sendMaxNotify($message, 'markdown', ['event_key' => $eventKey, 'context' => ['message' => $message]]);
            if (empty($res['success'])) {
                throw new RuntimeException('Ошибка отправки: ' . (string)($res['error'] ?? 'unknown'));
            }
            $flash = 'Тестовое сообщение отправлено';
        } elseif ($action === 'test_template') {
            $eventKey = trim((string)post('event_key'));
            $templateText = (string)post('template_text');
            if ($eventKey === '' || $templateText === '') {
                throw new RuntimeException('Не удалось протестировать шаблон: пустое событие или текст');
            }
            $demo = demoContext();
            $rendered = mapAdminRenderTemplate($templateText, $demo);
            $res = sendMaxNotify($rendered, 'markdown', [
                'event_key' => '',
                'context' => ['message' => $rendered],
            ]);
            if (empty($res['success'])) {
                throw new RuntimeException('Ошибка отправки теста шаблона: ' . (string)($res['error'] ?? 'unknown'));
            }
            $flash = 'Тест шаблона отправлен в MAX';
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'err';
    }
}

$ready = adminTablesReady($pdo);
$settings = $ready ? getSettings($pdo) : [];
$groups = [];
$templates = [];
$logs = [];
if ($ready) {
    $g = $pdo->query('SELECT * FROM max_groups ORDER BY is_default DESC, id DESC');
    $groups = $g ? $g->fetchAll(PDO::FETCH_ASSOC) : [];
    $t = $pdo->query('SELECT * FROM max_message_templates ORDER BY event_key ASC');
    $templates = $t ? $t->fetchAll(PDO::FETCH_ASSOC) : [];
    $l = $pdo->query('SELECT * FROM max_send_log ORDER BY id DESC LIMIT 50');
    $logs = $l ? $l->fetchAll(PDO::FETCH_ASSOC) : [];
}
?><!doctype html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Администрирование MAX</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f6f8;color:#1e293b;margin:0;padding:16px}
.wrap{max-width:1240px;margin:0 auto}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.card{background:#fff;border:1px solid #d9e0e7;border-radius:8px;padding:12px;margin-bottom:10px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
label{font-size:13px;color:#334155}input[type=text],input[type=password],select,textarea{border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;width:100%;box-sizing:border-box}
textarea{min-height:96px}.btn{border:1px solid #94a3b8;background:#eef2f7;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:13px;height:32px;display:inline-flex;align-items:center}
.btn.primary{background:#0ea5b7;color:#fff;border-color:#0b7285}.btn.danger{background:#fdf2f2;border-color:#f1b3b3;color:#b42318}
.small{font-size:12px;color:#64748b}.ok{background:#ecfdf3;border-color:#b7e4c7}.err{background:#fef2f2;border-color:#fecaca;color:#b42318}
table{width:100%;border-collapse:collapse}th,td{font-size:12px;border-bottom:1px solid #e2e8f0;padding:6px;text-align:left;vertical-align:top}
th{background:#f8fafc}.mono{font-family:Consolas,monospace;white-space:pre-wrap}.hint{font-size:12px;color:#334155;background:#f8fafc;border-left:3px solid #0ea5b7;padding:6px 8px;border-radius:4px}
.tpl-grid{display:grid;grid-template-columns:360px 1fr;gap:10px;align-items:start}
.tpl-left{display:grid;gap:6px}
.tpl-right{display:grid;gap:6px}
.tpl-actions{display:flex;gap:8px;align-items:center}
textarea.tpl-text{min-height:0;height:auto}
.event-key{font-size:12px;color:#94a3b8}
.tpl-actions .btn{min-width:156px;justify-content:center}
@media (max-width:1000px){.grid{grid-template-columns:1fr}}
</style></head><body><div class="wrap">
<div class="top"><h2 style="margin:0">Администрирование MAX</h2><?php renderAdminNav('max_admin'); ?></div>

<?php if ($flash !== ''): ?><div class="card <?= $flashType === 'err' ? 'err' : 'ok' ?>"><?= h($flash) ?></div><?php endif; ?>

<?php if (!isAuthed()): ?>
<div class="card" style="max-width:420px"><form method="post"><input type="hidden" name="action" value="login"><label>Пароль доступа</label><input type="password" name="password" required><div style="height:8px"></div><button class="btn primary" type="submit">Войти</button></form></div>
<?php else: ?>
<?php if (!$ready): ?><div class="card err">Таблицы MAX Admin не найдены. Выполните SQL: <span class="mono">map_files/admin/sql/max_admin_tables.sql</span></div><?php endif; ?>

<div class="grid">
<div class="card" id="global-card"><h3 style="margin:0 0 8px">Глобальные настройки</h3>
<form method="post" class="js-admin-ajax"><input type="hidden" name="action" value="save_global">
<div class="row"><label><input type="checkbox" name="max_enabled" value="1" <?= (($settings['max_enabled'] ?? '1') === '1') ? 'checked' : '' ?>> Отправка MAX включена</label></div>
<div class="row"><label>Группа по умолчанию</label><select name="default_group_id"><option value="0">Legacy fallback</option><?php foreach($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= ((int)($settings['default_group_id'] ?? 0) === (int)$g['id']) ? 'selected' : '' ?>><?= h($g['title']) ?> (<?= h($g['group_id']) ?>)</option><?php endforeach; ?></select></div>
<button class="btn primary" type="submit">Сохранить</button><span class="small js-status"></span></form></div>

<div class="card"><h3 style="margin:0 0 8px">Тестовая отправка</h3>
<form method="post" class="js-admin-ajax"><input type="hidden" name="action" value="send_test"><label>event_key</label><input type="text" name="event_key" value="test_message"><div style="height:6px"></div><label>Текст</label><textarea name="message">Тест MAX уведомления
> 💡 *Проверка markdown-цитаты.*</textarea><div style="height:8px"></div><button class="btn primary" type="submit">Отправить тест</button><span class="small js-status"></span></form></div>
</div>

<div class="card" id="groups-card"><h3 style="margin:0 0 8px">Группы</h3>
<table><thead><tr><th>Название</th><th>group_id</th><th>Активна</th><th>Default</th><th>Действия</th></tr></thead><tbody>
<?php foreach($groups as $g): ?><tr><td><form method="post" class="row js-admin-ajax"><input type="hidden" name="action" value="save_group"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><input type="text" name="title" value="<?= h($g['title']) ?>"></td><td><input type="text" name="group_id" value="<?= h($g['group_id']) ?>"></td><td><label><input type="checkbox" name="is_active" value="1" <?= (int)$g['is_active']===1?'checked':'' ?>></label></td><td><?= (int)$g['is_default']===1?'Да':'Нет' ?></td><td><button class="btn" type="submit">Сохранить</button><span class="small js-status"></span></form><form method="post" class="js-admin-ajax" style="display:inline"><input type="hidden" name="action" value="set_default_group"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="btn" type="submit">Сделать default</button><span class="small js-status"></span></form><form method="post" class="js-admin-ajax" style="display:inline" onsubmit="return confirm('Удалить группу?')"><input type="hidden" name="action" value="delete_group"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="btn danger" type="submit">Удалить</button><span class="small js-status"></span></form></td></tr><?php endforeach; ?></tbody></table>
<div style="height:8px"></div>
<form method="post" class="row js-admin-ajax"><input type="hidden" name="action" value="add_group"><input type="text" name="title" placeholder="Название группы" style="max-width:280px"><input type="text" name="group_id" placeholder="group_id/chat_id" style="max-width:320px"><label><input type="checkbox" name="is_active" value="1" checked> Активна</label><button class="btn primary" type="submit">Добавить группу</button><span class="small js-status"></span></form>
</div>

<div class="card" id="templates-card"><div class="row" style="justify-content:space-between"><h3 style="margin:0">Шаблоны событий</h3><form method="post" class="js-admin-ajax"><input type="hidden" name="action" value="seed_templates"><button class="btn" type="submit">Обновить default шаблоны из production</button><span class="small js-status"></span></form></div>
<?php foreach($templates as $t): $eventKey = (string)($t['event_key'] ?? ''); $desc = trim((string)($t['description'] ?? '')); if ($desc === '' && isset($catalog[$eventKey]['description'])) { $desc = $catalog[$eventKey]['description']; } ?>
<form method="post" class="card js-admin-ajax" style="margin:8px 0;padding:10px;background:#f8fafc">
<input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
<input type="hidden" name="title" value="<?= h($t['title']) ?>">
<input type="hidden" name="description" value="<?= h($desc) ?>">
<div class="tpl-grid">
  <div class="tpl-left">
    <div class="hint"><?= h($desc !== '' ? $desc : ('Отправляется при событии: ' . $eventKey)) ?></div>
    <div class="event-key mono"><?= h($eventKey) ?></div>
    <div class="row"><label><input type="checkbox" name="is_enabled" value="1" <?= (int)$t['is_enabled']===1?'checked':'' ?>> Включено</label></div>
    <div class="small">Плейсхолдеры: {route_id}, {route_title}, {driver}, {planned_range}, {actual_start_short}, {meta_line}, {manager}, {route_type_line}, {requests_count}, {weight}, {message}</div>
    <div class="small">Форматирование MAX (markdown): <code>**жирный текст**</code>, переносы строк, markdown-цитаты через <code>&gt; текст</code>.</div>
  </div>
  <div class="tpl-right">
    <textarea class="tpl-text" name="template_text" rows="6"><?= h($t['template_text']) ?></textarea>
    <div class="tpl-actions">
      <button class="btn" type="submit" name="action" value="save_template">Сохранить шаблон</button>
      <button class="btn primary" type="submit" name="action" value="test_template">Тест</button>
      <input type="hidden" name="event_key" value="<?= h($eventKey) ?>">
      <span class="small js-status"></span>
    </div>
  </div>
</div>
</form>
<?php endforeach; ?>
</div>

<div class="card" id="logs-card"><h3 style="margin:0 0 8px">Лог отправок (последние 50)</h3>
<table><thead><tr><th>Дата</th><th>Событие</th><th>Группа</th><th>Успех</th><th>Ошибка/ответ</th></tr></thead><tbody>
<?php foreach($logs as $log): ?><tr><td><?= h($log['created_at'] ?? '') ?></td><td class="mono"><?= h($log['event_key'] ?? '') ?></td><td class="mono"><?= h($log['group_id'] ?? '') ?></td><td><?= (int)($log['success'] ?? 0)===1?'OK':'ERR' ?></td><td class="mono"><?= h(trim((string)($log['error_text'] ?? '')) !== '' ? $log['error_text'] : mb_substr((string)($log['response_text'] ?? ''),0,220)) ?></td></tr><?php endforeach; ?></tbody></table>
</div>
<?php endif; ?>
</div>
<?php if (isAuthed()): ?>
<script>
(() => {
  const endpoint = 'max_admin_actions.php';
  const forms = document.querySelectorAll('form.js-admin-ajax');
  const status = (form, text, isError=false) => {
    const el = form.querySelector('.js-status');
    if (!el) return;
    el.textContent = text;
    el.style.color = isError ? '#b42318' : '#0f766e';
  };
  const lock = (btn, locked, text='') => {
    if (!btn) return;
    if (locked) {
      btn.dataset.originalText = btn.textContent;
      btn.textContent = text || 'Выполняется...';
      btn.disabled = true;
    } else {
      btn.disabled = false;
      if (btn.dataset.originalText) btn.textContent = btn.dataset.originalText;
    }
  };

  forms.forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const submitter = e.submitter || form.querySelector('button[type="submit"]');
      lock(submitter, true, 'Выполняется...');
      status(form, 'Подождите...');
      try {
        const fd = new FormData(form);
        if (submitter && submitter.name && submitter.value) fd.set(submitter.name, submitter.value);
        const resp = await fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Ошибка');
        status(form, data.message || 'Сохранено');
      } catch (err) {
        status(form, 'Ошибка: ' + (err.message || 'unknown'), true);
      } finally {
        lock(submitter, false);
      }
    });
  });
})();
</script>
<?php endif; ?>
</body></html>
