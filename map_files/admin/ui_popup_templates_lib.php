<?php
/**
 * UI Popup Templates — Shared Library
 * Общие функции для ui_popup_templates.php (админка) и ui_popup_templates_actions.php (API).
 * Подключается из bootstrap. Не содержит action-логики, не выводит заголовки.
 */

/**
 * CREATE TABLE IF NOT EXISTS ui_popup_templates
 */
function uiPopupEnsureTable(PDO $pdo): void
{
    $sql = "CREATE TABLE IF NOT EXISTS `ui_popup_templates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `popup_key` VARCHAR(100) NOT NULL UNIQUE,
        `title` VARCHAR(255) NOT NULL,
        `template_text` TEXT NOT NULL,
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `description` TEXT NULL,
        `placeholders` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
}

function uiPopupFindByKey(PDO $pdo, string $popupKey): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM `ui_popup_templates` WHERE `popup_key` = :key LIMIT 1");
    $stmt->execute(['key' => $popupKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function uiPopupFetchAll(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM `ui_popup_templates` ORDER BY `popup_key` ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function uiPopupFetchEnabled(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM `ui_popup_templates` WHERE `is_enabled` = 1 ORDER BY `popup_key` ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function uiPopupInsert(PDO $pdo, string $popupKey, string $title, string $templateText, int $isEnabled, string $description, string $placeholders): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO `ui_popup_templates` (`popup_key`, `title`, `template_text`, `is_enabled`, `description`, `placeholders`, `created_at`)
         VALUES (:popup_key, :title, :template_text, :is_enabled, :description, :placeholders, NOW())"
    );
    $stmt->execute([
        'popup_key'     => $popupKey,
        'title'         => $title,
        'template_text' => $templateText,
        'is_enabled'    => $isEnabled,
        'description'   => $description,
        'placeholders'  => $placeholders,
    ]);
}

function uiPopupUpdate(PDO $pdo, string $popupKey, string $title, string $templateText, int $isEnabled, string $description, string $placeholders): void
{
    $stmt = $pdo->prepare(
        "UPDATE `ui_popup_templates`
         SET `title` = :title, `template_text` = :template_text, `is_enabled` = :is_enabled,
             `description` = :description, `placeholders` = :placeholders, `updated_at` = NOW()
         WHERE `popup_key` = :popup_key"
    );
    $stmt->execute([
        'title'         => $title,
        'template_text' => $templateText,
        'is_enabled'    => $isEnabled,
        'description'   => $description,
        'placeholders'  => $placeholders,
        'popup_key'     => $popupKey,
    ]);
}

/**
 * Auto-seed: вставляет ТОЛЬКО отсутствующие popup_key, не трогает существующие.
 */
function uiPopupEnsureDefaultTemplates(PDO $pdo): int
{
    $count = 0;
    $existing = uiPopupFetchAll($pdo);
    $existingKeys = array_column($existing, 'popup_key');

    foreach (uiPopupAllDefaultTemplates() as $key => $def) {
        if (in_array($key, $existingKeys, true)) {
            continue;
        }
        uiPopupInsert($pdo, $key, $def['title'], $def['template_text'], 1, $def['description'], $def['placeholders']);
        $count++;
    }
    return $count;
}

function uiPopupSeedDefaultTemplates(PDO $pdo): int
{
    return uiPopupEnsureDefaultTemplates($pdo);
}

function uiPopupGetDefaultTemplate(string $popupKey): ?array
{
    $all = uiPopupAllDefaultTemplates();
    return $all[$popupKey] ?? null;
}

function uiPopupAllDefaultTemplates(): array
{
    return [
        'route_planned_to_found_confirm' => [
            'title'         => 'Сформировать рейс #{route_id}?',
            'template_text' => "Будут зафиксированы водитель, даты, стоимость и заявки.\nВ MAX будет отправлено уведомление.\n\nЗаявок: {zayavki_count}\nВодитель: {driver_compact}\nПериод: {period}\nСтоимость: {cost}",
            'description'   => 'Подтверждение при переводе рейса из планируемого в сформированный',
            'placeholders'  => '{route_id}, {zayavki_count}, {driver_compact}, {period}, {cost}',
        ],
        'route_unsaved_before_transition_confirm' => [
            'title'         => 'В рейсе есть несохранённые изменения',
            'template_text' => 'Сохранить изменения и выполнить переход статуса?',
            'description'   => 'Запрос на сохранение изменений перед переходом статуса',
            'placeholders'  => '',
        ],
        'route_start_confirm' => [
            'title'         => 'Подтвердить начало вывоза?',
            'template_text' => "Рейс #{route_id} будет переведён в статус «Вывоз начался».\n\nВодитель: {driver_compact}\nНачало вывоза: {actual_start_date}",
            'description'   => 'Подтверждение перевода рейса в статус started',
            'placeholders'  => '{route_id}, {driver_compact}, {actual_start_date}',
        ],
        'route_complete_confirm' => [
            'title'         => 'Подтвердить сдачу груза?',
            'template_text' => "Рейс #{route_id} будет переведён в статус «Груз сдан».\nДля складских маршрутов будут созданы складские движения.\n\nДата сдачи: {actual_end_date}",
            'description'   => 'Подтверждение перевода рейса в статус completed',
            'placeholders'  => '{route_id}, {actual_end_date}',
        ],
        'route_rollback_to_found_confirm' => [
            'title'         => 'Вернуть рейс в сформированные?',
            'template_text' => "Рейс #{route_id} будет возвращён в статус «Сформированный рейс».",
            'description'   => 'Подтверждение возврата рейса из started в found',
            'placeholders'  => '{route_id}',
        ],
        'route_rollback_to_planned_confirm' => [
            'title'         => 'Вернуть рейс в планируемые?',
            'template_text' => "Рейс #{route_id} будет возвращён в планируемые маршруты.",
            'description'   => 'Подтверждение возврата рейса из found в planned',
            'placeholders'  => '{route_id}',
        ],
        'route_delete_confirm' => [
            'title'         => 'Удалить маршрут?',
            'template_text' => "Маршрут #{route_id} будет удалён без возможности восстановления.",
            'description'   => 'Подтверждение удаления маршрута',
            'placeholders'  => '{route_id}',
        ],
        'route_transition_failed' => [
            'title'         => 'Переход статуса отменён',
            'template_text' => 'Не удалось сохранить изменения рейса. Переход статуса отменён.',
            'description'   => 'Сообщение при неудаче автосохранения перед переходом статуса',
            'placeholders'  => '',
        ],
        'route_save_failed' => [
            'title'         => 'Ошибка сохранения',
            'template_text' => 'Не удалось сохранить изменения рейса.',
            'description'   => 'Сообщение при неудаче сохранения рейса',
            'placeholders'  => '',
        ],
    ];
}
