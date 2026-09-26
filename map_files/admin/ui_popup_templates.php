<?php
/**
 * UI Popup Templates Admin
 * Управление шаблонами confirm/alert/popup для карты
 */
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ui_popup_templates_lib.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection error';
    exit;
}

// ── Auth check ──
if (!maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)maxAdminPost('action') === 'login') {
    if (maxAdminPasswordConst() !== '' && hash_equals(maxAdminPasswordConst(), (string)maxAdminPost('password'))) {
        $_SESSION['max_admin_auth'] = 1;
        header('Location: ui_popup_templates.php');
        exit;
    }
}

if (maxAdminIsAuthed() && (string)($_GET['logout'] ?? '') === '1') {
    unset($_SESSION['max_admin_auth']);
    session_destroy();
    header('Location: ui_popup_templates.php');
    exit;
}

$isAuthed = maxAdminIsAuthed();

// ── Fetch all templates with auto-seed ──
$templates = [];
if ($isAuthed) {
    try {
        uiPopupEnsureTable($pdo);
        uiPopupEnsureDefaultTemplates($pdo);
        $templates = uiPopupFetchAll($pdo);
    } catch (Exception $e) {
        $templates = [];
    }
}

$activeKey = trim((string)($_GET['key'] ?? ''));

?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UI Popup Templates Admin</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f6fa; color: #2c3e50; }
.admin-wrap { max-width: 1200px; margin: 0 auto; padding: 20px; }

.admin-nav { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 24px; padding: 12px 0; border-bottom: 2px solid #e0e0e0; }
.btn { display: inline-flex; align-items: center; padding: 8px 16px; background: #3498db; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; text-decoration: none; transition: background 0.2s; }
.btn:hover { background: #2980b9; }
.btn-secondary { background: #95a5a6; }
.btn-secondary:hover { background: #7f8c8d; }
.btn-success { background: #27ae60; }
.btn-success:hover { background: #219a52; }
.btn-danger { background: #e74c3c; }
.btn-danger:hover { background: #c0392b; }
.btn-small { padding: 4px 10px; font-size: 12px; }
.admin-nav-active { background: #2c3e50; font-weight: bold; }

.auth-box { max-width: 400px; margin: 60px auto; padding: 30px; background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); }
.auth-box h2 { margin-bottom: 16px; }
.auth-box input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; margin-bottom: 12px; }

.layout { display: flex; gap: 20px; }
.sidebar { width: 280px; flex-shrink: 0; }
.sidebar-list { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); overflow: hidden; }
.sidebar-item { display: block; padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #eee; font-size: 13px; transition: background 0.15s; text-decoration: none; color: inherit; }
.sidebar-item:hover { background: #eef2ff; }
.sidebar-item.active { background: #3498db; color: #fff; font-weight: bold; }
.sidebar-item .badge { float: right; font-size: 11px; padding: 1px 8px; border-radius: 10px; }
.badge-on { background: #27ae60; color: #fff; }
.badge-off { background: #e74c3c; color: #fff; }

.main-panel { flex: 1; min-width: 0; }
.card { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); padding: 24px; margin-bottom: 16px; }
.card h2 { margin-bottom: 12px; font-size: 20px; }
.card label { display: block; font-weight: 600; margin: 12px 0 4px; font-size: 13px; color: #555; }
.card input[type="text"],
.card textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; font-family: inherit; }
.card textarea { min-height: 140px; resize: vertical; }
.card .help { font-size: 12px; color: #888; margin-top: 2px; }

.toggle-wrap { display: flex; align-items: center; gap: 10px; margin: 12px 0; }
.toggle-switch { position: relative; width: 44px; height: 24px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #ccc; border-radius: 24px; transition: 0.3s; }
.toggle-slider::before { content: ''; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s; }
.toggle-switch input:checked + .toggle-slider { background: #27ae60; }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

.preview-box { background: #fffbe6; border: 1px solid #f0d060; border-radius: 6px; padding: 16px; white-space: pre-wrap; font-size: 14px; line-height: 1.6; margin-top: 12px; }
.preview-title { font-weight: bold; font-size: 15px; margin-bottom: 4px; color: #c0392b; }

.actions { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; }

.toast { position: fixed; top: 20px; right: 20px; padding: 12px 20px; border-radius: 6px; color: #fff; font-size: 14px; z-index: 9999; opacity: 0; transition: opacity 0.3s; }
.toast.show { opacity: 1; }
.toast-success { background: #27ae60; }
.toast-error { background: #e74c3c; }

.empty-state { text-align: center; padding: 40px; color: #888; }
</style>
</head>
<body>
<div class="admin-wrap">

<?php renderAdminNav('popup_templates'); ?>

<?php if (!$isAuthed): ?>
<div class="auth-box">
    <h2>Вход в админку</h2>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <input type="password" name="password" placeholder="Пароль" autofocus>
        <button type="submit" class="btn">Войти</button>
    </form>
</div>
<?php else: ?>

<div class="layout">
    <div class="sidebar">
        <div class="sidebar-list">
            <?php foreach ($templates as $tpl): ?>
            <a class="sidebar-item<?= ($activeKey === $tpl['popup_key']) ? ' active' : '' ?>" href="?key=<?= maxAdminHtml($tpl['popup_key']) ?>">
                <?= maxAdminHtml($tpl['title']) ?>
                <span class="badge <?= $tpl['is_enabled'] ? 'badge-on' : 'badge-off' ?>"><?= $tpl['is_enabled'] ? 'ON' : 'OFF' ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:12px; padding: 0 1px;">
            <button class="btn btn-secondary btn-small" onclick="seedTemplates()" style="width:100%;">Добавить недостающие стандартные шаблоны</button>
        </div>
    </div>

    <div class="main-panel">
        <?php
        $currentTemplate = null;
        if ($activeKey !== '') {
            foreach ($templates as $tpl) {
                if ($tpl['popup_key'] === $activeKey) {
                    $currentTemplate = $tpl;
                    break;
                }
            }
        }
        ?>
        <?php if ($currentTemplate): ?>
        <div class="card" id="edit-card">
            <h2>Редактирование: <?= maxAdminHtml($currentTemplate['title']) ?></h2>
            <div style="font-size:12px;color:#888;margin-bottom:8px;">Ключ: <code><?= maxAdminHtml($currentTemplate['popup_key']) ?></code></div>
            <input type="hidden" id="popup_key" value="<?= maxAdminHtml($currentTemplate['popup_key']) ?>">

            <label for="tpl_title">Название</label>
            <input type="text" id="tpl_title" value="<?= maxAdminHtml($currentTemplate['title']) ?>">

            <div class="toggle-wrap">
                <label class="toggle-switch">
                    <input type="checkbox" id="tpl_enabled" <?= $currentTemplate['is_enabled'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
                <span>Включён</span>
            </div>

            <label for="tpl_text">Текст шаблона</label>
            <textarea id="tpl_text"><?= maxAdminHtml($currentTemplate['template_text']) ?></textarea>
            <div class="help">Доступные переменные: {route_id}, {zayavki_count}, {driver_compact}, {period}, {cost}, {actual_start_date}, {actual_end_date}, {driver_full}, {route_type_label}, {status_label}</div>

            <?php if ($currentTemplate['description']): ?>
            <div class="help" style="margin-top:4px;">Описание: <?= maxAdminHtml($currentTemplate['description']) ?></div>
            <?php endif; ?>

            <div class="actions">
                <button class="btn btn-success" onclick="saveTemplate()">Сохранить</button>
                <button class="btn btn-secondary" onclick="resetTemplate()">Вернуть по умолчанию</button>
                <button class="btn" onclick="togglePreview()">Превью</button>
            </div>

            <div class="preview-box" id="preview-box" style="display:none;">
                <div class="preview-title">Превью (тестовые данные):</div>
                <div id="preview-content"></div>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="empty-state">
                <p>Выберите шаблон слева для редактирования.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>

<div id="toast" class="toast"></div>

<script>
function toast(msg, isError) {
    var el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'toast ' + (isError ? 'toast-error' : 'toast-success') + ' show';
    setTimeout(function(){ el.className = 'toast'; }, 2500);
}

function saveTemplate() {
    var form = new FormData();
    form.append('action', 'save');
    form.append('popup_key', document.getElementById('popup_key').value);
    form.append('title', document.getElementById('tpl_title').value);
    form.append('template_text', document.getElementById('tpl_text').value);
    form.append('is_enabled', document.getElementById('tpl_enabled').checked ? 1 : 0);

    fetch('ui_popup_templates_actions.php', { method: 'POST', body: form })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                toast('Сохранено', false);
                setTimeout(function(){ location.reload(); }, 600);
            } else {
                toast(data.error || 'Ошибка', true);
            }
        })
        .catch(function() { toast('Ошибка сети', true); });
}

function resetTemplate() {
    if (!confirm('Сбросить шаблон к значениям по умолчанию?')) return;
    var form = new FormData();
    form.append('action', 'reset');
    form.append('popup_key', document.getElementById('popup_key').value);

    fetch('ui_popup_templates_actions.php', { method: 'POST', body: form })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                toast(data.message || 'Сброшено', false);
                setTimeout(function(){ location.reload(); }, 600);
            } else {
                toast(data.error || 'Ошибка', true);
            }
        })
        .catch(function() { toast('Ошибка сети', true); });
}

function seedTemplates() {
    if (!confirm('Добавить только недостающие стандартные шаблоны? Существующие шаблоны не будут изменены.')) return;
    var form = new FormData();
    form.append('action', 'seed');

    fetch('ui_popup_templates_actions.php', { method: 'POST', body: form })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                toast(data.message || 'Готово', false);
                setTimeout(function(){ location.reload(); }, 600);
            } else {
                toast(data.error || 'Ошибка', true);
            }
        })
        .catch(function() { toast('Ошибка сети', true); });
}

function togglePreview() {
    var box = document.getElementById('preview-box');
    var content = document.getElementById('preview-content');
    if (box.style.display === 'none' || box.style.display === '') {
        var text = document.getElementById('tpl_text').value;
        var ctx = {
            route_id: 220,
            zayavki_count: 2,
            driver_compact: 'А123АА12(Спугов)',
            period: '28.05.2026 — 29.05.2026',
            cost: '1 ₽',
            actual_start_date: '28.05.2026',
            actual_end_date: '29.05.2026'
        };
        var rendered = text.replace(/\{(\w+)\}/g, function(m, key) {
            return ctx.hasOwnProperty(key) ? ctx[key] : '[' + key + ']';
        });
        content.textContent = rendered;
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}
</script>
</body>
</html>
