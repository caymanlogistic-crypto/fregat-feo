<?php
/**
 * UI Popup Templates — CRUD API (JSON endpoint)
 * Доступ: admin (через maxAdminIsAuthed)
 * GET  ?action=list         — все шаблоны (auth)
 * GET  ?action=list_public  — только включённые (публичный, без авторизации)
 * POST ?action=save         — сохранить/обновить шаблон (auth)
 * POST ?action=reset        — сбросить шаблон к дефолту (auth)
 * POST ?action=seed         — добавить недостающие дефолтные шаблоны (auth)
 */

session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ui_popup_templates_lib.php';

header('Content-Type: application/json; charset=utf-8');

$action = trim((string)($_GET['action'] ?? ($_POST['action'] ?? '')));

// ── Публичный эндпоинт (без авторизации) ──
if ($action === 'list_public') {
    uiPopupEnsureTable($pdo);
    uiPopupEnsureDefaultTemplates($pdo);
    $templates = uiPopupFetchEnabled($pdo);
    echo json_encode(['success' => true, 'templates' => $templates], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Все остальные действия требуют авторизации ──
maxAdminRequireAuthJson();

uiPopupEnsureTable($pdo);
uiPopupEnsureDefaultTemplates($pdo);

switch ($action) {
    case 'list':
        $templates = uiPopupFetchAll($pdo);
        echo json_encode(['success' => true, 'templates' => $templates], JSON_UNESCAPED_UNICODE);
        break;

    case 'save':
        $popupKey = trim((string)($_POST['popup_key'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $templateText = trim((string)($_POST['template_text'] ?? ''));
        $isEnabled = isset($_POST['is_enabled']) ? (int)$_POST['is_enabled'] : 1;
        $description = trim((string)($_POST['description'] ?? ''));
        $placeholders = trim((string)($_POST['placeholders'] ?? ''));

        if ($popupKey === '') {
            echo json_encode(['success' => false, 'error' => 'popup_key обязателен'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $existing = uiPopupFindByKey($pdo, $popupKey);
        if ($existing) {
            uiPopupUpdate($pdo, $popupKey, $title, $templateText, $isEnabled, $description, $placeholders);
        } else {
            uiPopupInsert($pdo, $popupKey, $title, $templateText, $isEnabled, $description, $placeholders);
        }
        echo json_encode(['success' => true, 'message' => 'Шаблон сохранён'], JSON_UNESCAPED_UNICODE);
        break;

    case 'reset':
        $popupKey = trim((string)($_POST['popup_key'] ?? ''));
        if ($popupKey === '') {
            echo json_encode(['success' => false, 'error' => 'popup_key обязателен'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $default = uiPopupGetDefaultTemplate($popupKey);
        if ($default) {
            $existing = uiPopupFindByKey($pdo, $popupKey);
            if ($existing) {
                uiPopupUpdate($pdo, $popupKey, $default['title'], $default['template_text'], 1, $default['description'], $default['placeholders']);
            } else {
                uiPopupInsert($pdo, $popupKey, $default['title'], $default['template_text'], 1, $default['description'], $default['placeholders']);
            }
            echo json_encode(['success' => true, 'message' => 'Шаблон сброшен к значениям по умолчанию'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'error' => 'Нет дефолтного шаблона для ключа ' . $popupKey], JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'seed':
        $count = uiPopupSeedDefaultTemplates($pdo);
        echo json_encode(['success' => true, 'message' => "Добавлено недостающих шаблонов: {$count}"], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие: ' . $action], JSON_UNESCAPED_UNICODE);
}
