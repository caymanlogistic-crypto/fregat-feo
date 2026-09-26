<?php
/*
 * Общий каркас административных страниц карты.
 * Авторизация отключена по требованию владельца проекта.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SESSION['max_admin_auth'] = 1;

define('FREGAT_MAP_BUILD_VERSION', 'U-2026.08.06.2');

if (!function_exists('maxAdminIsPublicUtilizerPage')) {
    function maxAdminIsPublicUtilizerPage(): bool
    {
        return true;
    }
}

if (!function_exists('maxAdminIsAuthed')) {
    function maxAdminIsAuthed(): bool
    {
        return true;
    }
}

if (!function_exists('maxAdminRequireAuthJson')) {
    function maxAdminRequireAuthJson(): void
    {
        return;
    }
}

if (!function_exists('maxAdminPasswordConst')) {
    function maxAdminPasswordConst(): string
    {
        return '';
    }
}

if (!function_exists('maxAdminHtml')) {
    function maxAdminHtml($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('maxAdminPost')) {
    function maxAdminPost(string $key, $default = '')
    {
        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('maxAdminJsonOut')) {
    function maxAdminJsonOut(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('renderAdminUiStyles')) {
    function renderAdminUiStyles(): void
    {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        echo <<<'HTML'
<style id="unified-admin-ui">
:root{
 --adm-bg:#0d1620;--adm-card:#152433;--adm-card2:#1a2d3d;--adm-line:#29475c;
 --adm-text:#e8eef3;--adm-muted:#9cb0bf;--adm-accent:#168b7d;--adm-danger:#8d3a3a;
 --adm-radius:10px;--adm-shadow:0 12px 30px rgba(0,0,0,.2)
}
*{box-sizing:border-box}
html,body{min-height:100%;background:var(--adm-bg)!important;color:var(--adm-text)!important}
body{margin:0!important;font-family:Inter,"Segoe UI",Arial,sans-serif!important;font-size:14px!important;line-height:1.45!important}
a{color:#91d5ff}
.wrap,.container,.admin-container,main{width:min(1600px,calc(100% - 32px))!important;max-width:none!important;margin:0 auto!important;padding:20px 0 34px!important}
.top,.page-header,.admin-header,.header{display:flex!important;align-items:flex-start!important;justify-content:space-between!important;gap:18px!important;flex-wrap:wrap!important;margin:0 0 18px!important}
h1,h2,h3,h4{color:var(--adm-text)!important}h1,h2{margin-top:0!important}h2{font-size:24px!important}h3{font-size:17px!important}
p,.muted,.hint,.small,small{color:var(--adm-muted)!important}
.panel,.card,.box,.section,fieldset,.login-box,.auth-box{background:var(--adm-card)!important;border:1px solid var(--adm-line)!important;border-radius:var(--adm-radius)!important;color:var(--adm-text)!important;box-shadow:none!important}
.panel,.card,.box,.section,.login-box,.auth-box{padding:18px!important}
fieldset{padding:14px!important}legend{color:var(--adm-text)!important;padding:0 6px!important}
.admin-nav-shell{position:sticky;top:8px;z-index:1000;width:100%;background:rgba(13,22,32,.96)!important;border:1px solid var(--adm-line)!important;border-radius:12px!important;padding:10px!important;box-shadow:var(--adm-shadow)!important;backdrop-filter:blur(8px)}
.admin-nav{display:flex!important;gap:8px!important;align-items:center!important;flex-wrap:wrap!important}
.admin-version{margin-top:8px;color:#9cb0bf;font-size:11px;font-weight:700;letter-spacing:.04em;text-align:right}
.admin-nav-group{display:flex!important;gap:8px!important;align-items:center!important;flex-wrap:wrap!important}.admin-nav-spacer{flex:1 1 20px}
.admin-nav a,.admin-nav .btn{min-height:36px!important;height:auto!important;padding:8px 12px!important;border-radius:8px!important;background:var(--adm-card2)!important;border:1px solid var(--adm-line)!important;color:var(--adm-text)!important;text-decoration:none!important;font-size:13px!important;font-weight:600!important;display:inline-flex!important;align-items:center!important;justify-content:center!important}
.admin-nav a:hover{background:#254154!important;border-color:#47718c!important}.admin-nav .admin-nav-active{background:var(--adm-accent)!important;border-color:var(--adm-accent)!important;color:#fff!important}
button,.btn,input[type=submit],input[type=button]{min-height:36px!important;height:auto!important;border-radius:8px!important;border:1px solid var(--adm-line)!important;background:var(--adm-card2)!important;color:var(--adm-text)!important;padding:8px 13px!important;font-weight:600!important;cursor:pointer!important}
button:hover,.btn:hover,input[type=submit]:hover,input[type=button]:hover{background:#274257!important;border-color:#47718c!important}
.btn.primary,.primary,button.primary,input.primary{background:var(--adm-accent)!important;border-color:var(--adm-accent)!important;color:#fff!important}
.btn.danger,.danger,button.danger{background:var(--adm-danger)!important;border-color:#b35a5a!important;color:#fff!important}
input[type=text],input[type=password],input[type=number],input[type=email],input[type=url],input[type=date],input[type=time],textarea,select{
 width:100%!important;min-height:38px!important;background:#0f1c27!important;color:var(--adm-text)!important;border:1px solid #35556b!important;border-radius:8px!important;padding:9px 11px!important;font:inherit!important;outline:none!important
}
textarea{min-height:94px!important;resize:vertical!important}input:focus,textarea:focus,select:focus{border-color:#4aa99d!important;box-shadow:0 0 0 3px rgba(22,139,125,.18)!important}
input[type=checkbox],input[type=radio]{accent-color:var(--adm-accent)!important}input[type=color]{height:42px!important;min-width:54px!important;padding:3px!important;background:#0f1c27!important;border:1px solid #35556b!important;border-radius:8px!important}
label{color:var(--adm-muted)!important;font-size:12px!important;font-weight:600!important}.field,.form-group{display:grid!important;gap:6px!important;margin-bottom:14px!important}
.two,.grid-2,.form-row{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:12px!important}.grid{display:grid!important;grid-template-columns:minmax(340px,460px) minmax(0,1fr)!important;gap:16px!important;align-items:start!important}
.table-wrap,.table-container{overflow:auto!important;border-radius:var(--adm-radius)!important}table{width:100%!important;border-collapse:separate!important;border-spacing:0!important;background:transparent!important;color:var(--adm-text)!important;font-size:13px!important}
thead th{position:sticky!important;top:0!important;z-index:2!important;background:#11202c!important;color:#b8c8d4!important;font-size:11px!important;text-transform:uppercase!important;letter-spacing:.04em!important;border-bottom:1px solid var(--adm-line)!important;padding:10px!important;white-space:nowrap!important}
tbody td{background:transparent!important;color:var(--adm-text)!important;border-bottom:1px solid #22394b!important;padding:11px 10px!important;vertical-align:top!important}tbody tr:hover{background:#1a3040!important}
.flash,.alert,.message,.notice{border-radius:9px!important;padding:12px 14px!important;margin-bottom:14px!important;background:#163026!important;border:1px solid #377761!important;color:#dff8eb!important}.flash.err,.alert.error,.error{background:#351e22!important;border-color:#8f444d!important;color:#ffd9de!important}
.actions,.form-actions{display:flex!important;gap:8px!important;align-items:center!important;flex-wrap:wrap!important}
hr{border:0!important;border-top:1px solid #22394b!important;margin:18px 0!important}pre,code{background:#0b141d!important;color:#dce8f1!important;border-color:#22394b!important}
form:has(input[name="password"]):has(input[name="action"][value="login"]),form:has(input[type="password"]){display:none!important}
@media(max-width:1100px){.grid{grid-template-columns:1fr!important}.admin-nav-spacer{display:none!important}}
@media(max-width:760px){.wrap,.container,.admin-container,main{width:calc(100% - 20px)!important;padding-top:12px!important}.panel,.card,.box,.section{padding:14px!important}.two,.grid-2,.form-row{grid-template-columns:1fr!important}.admin-nav a{flex:1 1 calc(50% - 8px)!important}h2{font-size:21px!important}}
</style>
HTML;
    }
}

if (!function_exists('renderAdminNav')) {
    function renderAdminNav(string $active = ''): void
    {
        renderAdminUiStyles();
        $pages = [
            'max_admin'             => ['label' => 'MAX Admin — уведомления', 'url' => 'max_admin.php'],
            'max_event_center'      => ['label' => 'MAX Event — шаблоны сообщений', 'url' => 'max_event_center.php'],
            'popup_templates'       => ['label' => 'UI Popup — экранные попапы', 'url' => 'ui_popup_templates.php'],
            'static_text'           => ['label' => 'Статические тексты', 'url' => 'static_text_center.php'],
            'utilizers'             => ['label' => 'Утилизаторы', 'url' => 'utilizers_admin.php'],
            'warehouse'             => ['label' => 'Складская разметка', 'url' => 'warehouse_route_reclassifier.php'],
            'stock'                 => ['label' => 'Складские остатки', 'url' => 'warehouse_stock_report.php'],
            'slitex_tracker_rename' => ['label' => 'SLITEX трекеры', 'url' => 'slitex_tracker_rename.php'],
            'map'                   => ['label' => 'Карта', 'url' => '../maptest.php'],
        ];
        echo '<nav class="admin-nav-shell" aria-label="Административное меню"><div class="admin-nav">';
        foreach ($pages as $key => $page) {
            $cls = $key === $active ? 'btn admin-nav-active' : 'btn';
            echo '<a class="' . $cls . '" href="' . maxAdminHtml($page['url']) . '">' . maxAdminHtml($page['label']) . '</a>';
        }
        echo '</div><div class="admin-version">Версия: ' . maxAdminHtml(FREGAT_MAP_BUILD_VERSION) . '</div></nav>';
    }
}
