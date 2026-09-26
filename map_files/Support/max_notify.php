<?php

if (!function_exists('mapError')) {
    function mapError(string $message, array $context = []): void
    {
        error_log('[map:max_notify] ' . $message . (empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE)));
    }
}

function mapGetNotifyConfig(): array
{
    $configPath = dirname(__DIR__) . '/config/max_notify.php';
    $cfg = [];
    if (is_file($configPath)) {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $cfg = $loaded;
        }
    }

    return [
        'key' => isset($cfg['key']) ? trim((string)$cfg['key']) : '',
        'base_url' => isset($cfg['base_url']) ? trim((string)$cfg['base_url']) : '',
        'token' => isset($cfg['token']) ? trim((string)$cfg['token']) : '',
        'chat_id' => isset($cfg['chat_id']) ? trim((string)$cfg['chat_id']) : '',
    ];
}

function mapGetNotifyKey(): string
{
    $cfg = mapGetNotifyConfig();
    if ($cfg['key'] !== '') {
        return $cfg['key'];
    }

    $envKey = getenv('MAX_NOTIFY_KEY');
    if (is_string($envKey) && trim($envKey) !== '') {
        return trim($envKey);
    }
    if (!empty($_SERVER['MAX_NOTIFY_KEY'])) {
        return (string)$_SERVER['MAX_NOTIFY_KEY'];
    }
    if (!empty($_ENV['MAX_NOTIFY_KEY'])) {
        return (string)$_ENV['MAX_NOTIFY_KEY'];
    }
    global $maxNotifyKey;
    if (!empty($maxNotifyKey)) {
        return (string)$maxNotifyKey;
    }
    return '';
}

function mapGetDirectMaxConfig(): array
{
    $cfg = mapGetNotifyConfig();
    $baseUrl = $cfg['base_url'];
    $token = $cfg['token'];
    $chatId = $cfg['chat_id'];

    if ($baseUrl === '') {
        $baseUrl = trim((string)(getenv('MAX_API_BASE_URL') ?: ($_SERVER['MAX_API_BASE_URL'] ?? '')));
    }
    if ($token === '') {
        $token = trim((string)(getenv('MAX_API_TOKEN') ?: ($_SERVER['MAX_API_TOKEN'] ?? '')));
    }
    if ($chatId === '') {
        $chatId = trim((string)(getenv('MAX_CHAT_ID') ?: ($_SERVER['MAX_CHAT_ID'] ?? '')));
    }

    return [
        'base_url' => rtrim($baseUrl, '/'),
        'token' => $token,
        'chat_id' => rtrim($chatId, '; '),
    ];
}

function normalizeUtf8Message(string $text): string
{
    $message = preg_replace('/^\xEF\xBB\xBF/u', '', $text ?? '');
    if (!is_string($message)) {
        $message = (string)$text;
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($message, 'UTF-8')) {
        $converted = @mb_convert_encoding($message, 'UTF-8', 'Windows-1251');
        if (is_string($converted) && $converted !== '') {
            $message = $converted;
        }
    }
    return preg_replace('/^\xEF\xBB\xBF/u', '', $message);
}

function mapAdminTablesReady(PDO $pdo): bool
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }
    try {
        $required = ['max_settings', 'max_groups', 'max_message_templates', 'max_send_log'];
        foreach ($required as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $table) . "'");
            if (!$stmt || !$stmt->fetchColumn()) {
                $checked = false;
                return false;
            }
        }
        $checked = true;
        return true;
    } catch (Throwable $e) {
        mapError('MAX admin tables check failed', ['error' => $e->getMessage()]);
        $checked = false;
        return false;
    }
}

function mapEventCenterTablesReady(PDO $pdo): bool
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }
    try {
        $required = ['max_event_templates', 'max_groups', 'max_logs', 'max_pending_queue', 'system_runtime_settings'];
        foreach ($required as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $table) . "'");
            if (!$stmt || !$stmt->fetchColumn()) {
                $checked = false;
                return false;
            }
        }
        $checked = true;
        return true;
    } catch (Throwable $e) {
        mapError('MAX event center tables check failed', ['error' => $e->getMessage()]);
        $checked = false;
        return false;
    }
}

function mapLoadTableColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];
        foreach ((array)$rows as $row) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
        return $columns;
    } catch (Throwable $e) {
        mapError('MAX load table columns failed', ['table' => $table, 'error' => $e->getMessage()]);
        return [];
    }
}

function mapFirstExistingValue(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $value = trim((string)$row[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return $default;
}

function mapRuntimeSettings(PDO $pdo): array
{
    if (!mapEventCenterTablesReady($pdo)) {
        return [];
    }
    $columns = mapLoadTableColumns($pdo, 'system_runtime_settings');
    if (empty($columns)) {
        return [];
    }
    $keyCol = isset($columns['setting_key']) ? 'setting_key' : (isset($columns['key_name']) ? 'key_name' : (isset($columns['key']) ? 'key' : ''));
    $valueCol = isset($columns['setting_value']) ? 'setting_value' : (isset($columns['value']) ? 'value' : (isset($columns['setting_val']) ? 'setting_val' : ''));
    if ($keyCol === '' || $valueCol === '') {
        return [];
    }
    try {
        $stmt = $pdo->query("SELECT `{$keyCol}` AS k, `{$valueCol}` AS v FROM system_runtime_settings");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $settings = [];
        foreach ((array)$rows as $row) {
            $key = trim((string)($row['k'] ?? ''));
            if ($key !== '') {
                $settings[$key] = (string)($row['v'] ?? '');
            }
        }
        return $settings;
    } catch (Throwable $e) {
        mapError('MAX runtime settings load failed', ['error' => $e->getMessage()]);
        return [];
    }
}

function mapWriteMaxLog(PDO $pdo, array $data): void
{
    if (!mapEventCenterTablesReady($pdo)) {
        return;
    }
    $columns = mapLoadTableColumns($pdo, 'max_logs');
    if (empty($columns)) {
        return;
    }
    try {
        $insert = [];
        $params = [];
        $set = static function (string $column, $value) use (&$insert, &$params, $columns): void {
            if (!isset($columns[$column])) {
                return;
            }
            $insert[] = "`{$column}` = :{$column}";
            $params[":{$column}"] = $value;
        };
        $set('event_key', (string)($data['event_key'] ?? ''));
        $set('status', (string)($data['status'] ?? ''));
        $set('group_id', (string)($data['group_id'] ?? ''));
        $set('message_text', normalizeUtf8Message((string)($data['message_text'] ?? '')));
        $set('response_text', (string)($data['response_text'] ?? ''));
        $set('error_text', (string)($data['error_text'] ?? ''));
        $set('context_json', json_encode($data['context'] ?? [], JSON_UNESCAPED_UNICODE));
        $set('success', !empty($data['success']) ? 1 : 0);
        if (empty($insert)) {
            return;
        }
        $sql = 'INSERT INTO max_logs SET ' . implode(', ', $insert);
        $stmt = $pdo->prepare($sql);
        if ($stmt) {
            $stmt->execute($params);
        }
    } catch (Throwable $e) {
        mapError('MAX logs write failed', ['error' => $e->getMessage()]);
    }
}

function mapQueuePendingMessage(PDO $pdo, array $payload): void
{
    if (!mapEventCenterTablesReady($pdo)) {
        return;
    }
    $columns = mapLoadTableColumns($pdo, 'max_pending_queue');
    if (empty($columns)) {
        return;
    }
    try {
        $insert = [];
        $params = [];
        $set = static function (string $column, $value) use (&$insert, &$params, $columns): void {
            if (!isset($columns[$column])) {
                return;
            }
            $insert[] = "`{$column}` = :{$column}";
            $params[":{$column}"] = $value;
        };
        $setFirst = static function (array $names, $value) use (&$set, $columns): void {
            foreach ($names as $name) {
                if (isset($columns[$name])) {
                    $set($name, $value);
                    return;
                }
            }
        };

        $eventKey = (string)($payload['event_key'] ?? '');
        $groupId = (string)($payload['group_id'] ?? '');
        $messageText = normalizeUtf8Message((string)($payload['message_text'] ?? ''));
        $format = (string)($payload['format'] ?? 'markdown');
        $contextJson = json_encode($payload['context'] ?? [], JSON_UNESCAPED_UNICODE);
        $payloadJson = json_encode([
            'event_key' => $eventKey,
            'group_id' => $groupId,
            'message_text' => $messageText,
            'format' => $format,
            'context' => $payload['context'] ?? [],
        ], JSON_UNESCAPED_UNICODE);

        $setFirst(['event_key', 'event_name', 'event'], $eventKey);
        $setFirst(['group_id', 'chat_id', 'group_chat_id'], $groupId);
        $setFirst(['message_text', 'message', 'text'], $messageText);
        $setFirst(['format', 'parse_mode'], $format);
        $setFirst(['status', 'queue_status'], 'pending');
        $setFirst(['context_json', 'context'], $contextJson);
        $setFirst(['payload_json', 'payload'], $payloadJson);
        $setFirst(['scheduled_for', 'send_at', 'planned_for'], date('Y-m-d H:i:s'));

        if (isset($columns['created_at'])) {
            $set('created_at', date('Y-m-d H:i:s'));
        } elseif (isset($columns['queued_at'])) {
            $set('queued_at', date('Y-m-d H:i:s'));
        }

        if (empty($insert) && isset($columns['payload_json'])) {
            $set('payload_json', $payloadJson);
        } elseif (empty($insert) && isset($columns['payload'])) {
            $set('payload', $payloadJson);
        }

        if (empty($insert)) {
            return;
        }
        $sql = 'INSERT INTO max_pending_queue SET ' . implode(', ', $insert);
        $stmt = $pdo->prepare($sql);
        if ($stmt) {
            $stmt->execute($params);
        }
    } catch (Throwable $e) {
        // Legacy fallback for installations where queue status enum does not include `pending`.
        try {
            if (isset($params[':status'])) {
                $params[':status'] = 'queued';
            }
            $stmtFallback = $pdo->prepare($sql);
            if ($stmtFallback) {
                $stmtFallback->execute($params);
                return;
            }
        } catch (Throwable $inner) {
            mapError('MAX queue write fallback failed', ['error' => $inner->getMessage()]);
        }
        mapError('MAX queue write failed', ['error' => $e->getMessage()]);
    }
}

function mapIsWithinQuietHours(string $start, string $end): bool
{
    $start = trim($start);
    $end = trim($end);
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
        return false;
    }
    $now = date('H:i');
    if ($start === $end) {
        return false;
    }
    if ($start < $end) {
        return ($now >= $start && $now < $end);
    }
    return ($now >= $start || $now < $end);
}

function mapEventKeyCandidates(string $eventKey): array
{
    $eventKey = trim($eventKey);
    if ($eventKey === '') {
        return [''];
    }
    $aliases = [
        'route_planned_to_found' => ['planned_to_found'],
        'planned_to_found' => ['route_planned_to_found'],
        'route_found_to_started' => ['found_to_started'],
        'found_to_started' => ['route_found_to_started'],
        'route_started_to_found_rollback' => ['started_to_found_rollback'],
        'started_to_found_rollback' => ['route_started_to_found_rollback'],
        'route_found_to_planned_rollback' => ['found_to_planned_rollback'],
        'found_to_planned_rollback' => ['route_found_to_planned_rollback'],
        'route_found_updated' => ['route_diff_found'],
        'route_diff_found' => ['route_found_updated'],
        'route_started_updated' => ['route_diff_started'],
        'route_diff_started' => ['route_started_updated'],
    ];
    $result = [$eventKey];
    foreach (($aliases[$eventKey] ?? []) as $alias) {
        if (!in_array($alias, $result, true)) {
            $result[] = $alias;
        }
    }
    return $result;
}

function mapResolveEventCenterOverride(PDO $pdo, string $eventKey, string $fallbackMessage, string $format, array $context): ?array
{
    if (!mapEventCenterTablesReady($pdo)) {
        return null;
    }
    try {
        $runtime = mapRuntimeSettings($pdo);
        $enabled = $runtime['max_enabled'] ?? ($runtime['max_notifications_enabled'] ?? '1');
        if ((string)$enabled === '0') {
            mapWriteMaxLog($pdo, [
                'event_key' => $eventKey,
                'group_id' => '',
                'message_text' => $fallbackMessage,
                'success' => 1,
                'status' => 'disabled',
                'response_text' => 'Skipped by global max_enabled=0',
                'context' => $context,
            ]);
            return ['skip' => true];
        }

        $template = null;
        if ($eventKey !== '') {
            foreach (mapEventKeyCandidates($eventKey) as $candidateKey) {
                $stmt = $pdo->prepare('SELECT * FROM max_event_templates WHERE event_key = :event_key LIMIT 1');
                if ($stmt && $stmt->execute([':event_key' => $candidateKey])) {
                    $template = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($template)) {
                        $eventKey = $candidateKey;
                        break;
                    }
                }
            }
        }

        if (is_array($template)) {
            $isEnabled = mapFirstExistingValue($template, ['is_enabled', 'enabled', 'active'], '1');
            if ((string)$isEnabled === '0') {
                mapWriteMaxLog($pdo, [
                    'event_key' => $eventKey,
                    'group_id' => '',
                    'message_text' => $fallbackMessage,
                    'success' => 1,
                    'status' => 'disabled',
                    'response_text' => 'Skipped by event template disabled',
                    'context' => $context,
                ]);
                return ['skip' => true];
            }
        }

        $templateText = (is_array($template) && !empty($template))
            ? mapFirstExistingValue($template, ['template_text', 'message_template', 'body_template'], '')
            : '';
        $messageToSend = $templateText !== '' ? mapAdminRenderTemplate($templateText, $context) : $fallbackMessage;
        if (trim($messageToSend) === '') {
            $messageToSend = $fallbackMessage;
        }

        $groupChatId = '';
        $templateGroupId = is_array($template)
            ? (int)mapFirstExistingValue($template, ['group_ref_id', 'group_id', 'max_group_id'], '0')
            : 0;
        if ($templateGroupId > 0) {
            $stmtGroup = $pdo->prepare('SELECT group_id FROM max_groups WHERE id = :id AND is_active = 1 LIMIT 1');
            if ($stmtGroup && $stmtGroup->execute([':id' => $templateGroupId])) {
                $groupChatId = trim((string)$stmtGroup->fetchColumn());
            }
        }
        if ($groupChatId === '') {
            $defaultGroupId = (int)($runtime['default_group_id'] ?? 0);
            if ($defaultGroupId > 0) {
                $stmtDefault = $pdo->prepare('SELECT group_id FROM max_groups WHERE id = :id AND is_active = 1 LIMIT 1');
                if ($stmtDefault && $stmtDefault->execute([':id' => $defaultGroupId])) {
                    $groupChatId = trim((string)$stmtDefault->fetchColumn());
                }
            }
        }
        if ($groupChatId === '') {
            $stmtAny = $pdo->query('SELECT group_id FROM max_groups WHERE is_active = 1 AND is_default = 1 ORDER BY id DESC LIMIT 1');
            $groupChatId = trim((string)($stmtAny ? $stmtAny->fetchColumn() : ''));
        }

        $quietEnabled = mapFirstExistingValue(is_array($template) ? $template : [], ['quiet_hours_enabled', 'quiet_enabled'], '');
        if ($quietEnabled === '') {
            $quietEnabled = (string)($runtime['quiet_hours_enabled'] ?? $runtime['max_quiet_hours_enabled'] ?? '0');
        }
        $quietStart = mapFirstExistingValue(is_array($template) ? $template : [], ['quiet_hours_start', 'quiet_start'], '');
        if ($quietStart === '') {
            $quietStart = (string)($runtime['quiet_hours_start'] ?? $runtime['max_quiet_hours_start'] ?? '22:00');
        }
        $quietEnd = mapFirstExistingValue(is_array($template) ? $template : [], ['quiet_hours_end', 'quiet_end'], '');
        if ($quietEnd === '') {
            $quietEnd = (string)($runtime['quiet_hours_end'] ?? $runtime['max_quiet_hours_end'] ?? '08:00');
        }

        if ((string)$quietEnabled === '1' && mapIsWithinQuietHours($quietStart, $quietEnd)) {
            mapQueuePendingMessage($pdo, [
                'event_key' => $eventKey,
                'group_id' => $groupChatId,
                'message_text' => $messageToSend,
                'format' => $format,
                'context' => $context,
            ]);
            mapWriteMaxLog($pdo, [
                'event_key' => $eventKey,
                'group_id' => $groupChatId,
                'message_text' => $messageToSend,
                'success' => 1,
                'status' => 'queued',
                'response_text' => 'Queued by quiet hours',
                'context' => $context,
            ]);
            return ['skip' => true, 'queued' => true];
        }

        return [
            'skip' => false,
            'message' => $messageToSend,
            'chat_id' => $groupChatId !== '' ? $groupChatId : null,
            'format' => $format,
            'context' => $context,
        ];
    } catch (Throwable $e) {
        mapError('MAX event center override failed', ['event_key' => $eventKey, 'error' => $e->getMessage()]);
        return null;
    }
}

function mapAdminLoadSettings(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM max_settings');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $settings = [];
        foreach ((array)$rows as $row) {
            $k = trim((string)($row['setting_key'] ?? ''));
            if ($k !== '') {
                $settings[$k] = (string)($row['setting_value'] ?? '');
            }
        }
        return $settings;
    } catch (Throwable $e) {
        mapError('MAX admin settings load failed', ['error' => $e->getMessage()]);
        return [];
    }
}

function mapAdminRenderTemplate(string $template, array $context): string
{
    if ($template === '') {
        return '';
    }
    return (string)preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static function ($m) use ($context) {
        $key = $m[1] ?? '';
        if ($key === '' || !array_key_exists($key, $context)) {
            return '';
        }
        $value = $context[$key];
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return trim((string)$value) === '' ? '' : (string)$value;
    }, $template);
}

function mapAdminResolveOverride(PDO $pdo, string $eventKey, string $fallbackMessage): array
{
    if (!mapAdminTablesReady($pdo)) {
        return ['enabled' => true, 'message' => $fallbackMessage, 'chat_id' => null];
    }

    $settings = mapAdminLoadSettings($pdo);
    if (isset($settings['max_enabled']) && (string)$settings['max_enabled'] === '0') {
        return ['enabled' => false, 'message' => $fallbackMessage, 'chat_id' => null];
    }

    $templateMessage = '';
    if ($eventKey !== '') {
        try {
            foreach (mapEventKeyCandidates($eventKey) as $candidateKey) {
                $stmtTpl = $pdo->prepare('SELECT template_text, is_enabled FROM max_message_templates WHERE event_key = :event_key LIMIT 1');
                if ($stmtTpl && $stmtTpl->execute([':event_key' => $candidateKey])) {
                    $rowTpl = $stmtTpl->fetch(PDO::FETCH_ASSOC);
                    if (is_array($rowTpl)) {
                        if ((int)($rowTpl['is_enabled'] ?? 0) !== 1) {
                            return ['enabled' => false, 'message' => $fallbackMessage, 'chat_id' => null];
                        }
                        $templateMessage = trim((string)($rowTpl['template_text'] ?? ''));
                        $eventKey = $candidateKey;
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            mapError('MAX template resolve failed', ['event_key' => $eventKey, 'error' => $e->getMessage()]);
        }
    }

    $chatId = null;
    try {
        $defaultGroupId = isset($settings['default_group_id']) ? (int)$settings['default_group_id'] : 0;
        if ($defaultGroupId > 0) {
            $stmtGroup = $pdo->prepare('SELECT group_id FROM max_groups WHERE id = :id AND is_active = 1 LIMIT 1');
            if ($stmtGroup && $stmtGroup->execute([':id' => $defaultGroupId])) {
                $chatId = trim((string)$stmtGroup->fetchColumn());
            }
        }
        if ($chatId === null || $chatId === '') {
            $stmtDefault = $pdo->query('SELECT group_id FROM max_groups WHERE is_active = 1 AND is_default = 1 ORDER BY id DESC LIMIT 1');
            $chatId = trim((string)($stmtDefault ? $stmtDefault->fetchColumn() : ''));
        }
    } catch (Throwable $e) {
        mapError('MAX group resolve failed', ['error' => $e->getMessage()]);
        $chatId = null;
    }

    return [
        'enabled' => true,
        'message' => $templateMessage !== '' ? $templateMessage : $fallbackMessage,
        'chat_id' => ($chatId !== '' ? $chatId : null),
    ];
}

function mapAdminWriteLog(PDO $pdo, array $log): void
{
    if (!mapAdminTablesReady($pdo)) {
        return;
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO max_send_log (event_key, group_id, message_text, success, response_text, error_text) VALUES (:event_key, :group_id, :message_text, :success, :response_text, :error_text)');
        if ($stmt) {
            $stmt->execute([
                ':event_key' => (string)($log['event_key'] ?? ''),
                ':group_id' => (string)($log['group_id'] ?? ''),
                ':message_text' => (string)($log['message_text'] ?? ''),
                ':success' => !empty($log['success']) ? 1 : 0,
                ':response_text' => (string)($log['response_text'] ?? ''),
                ':error_text' => (string)($log['error_text'] ?? ''),
            ]);
        }
    } catch (Throwable $e) {
        mapError('MAX send log failed', ['error' => $e->getMessage()]);
    }
}

function sendMaxNotify(string $message, string $format = 'markdown', array $meta = []): array
{
    $eventKey = trim((string)($meta['event_key'] ?? ''));
    $context = is_array($meta['context'] ?? null) ? $meta['context'] : [];
    if (!array_key_exists('message', $context)) {
        $context['message'] = $message;
    }

    $override = [
        'enabled' => true,
        'message' => $message,
        'chat_id' => null,
    ];

    $eventCenterOverride = null;
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $eventCenterOverride = mapResolveEventCenterOverride(
            $GLOBALS['pdo'],
            $eventKey,
            (string)$message,
            (string)$format,
            $context
        );
    }
    if (is_array($eventCenterOverride) && !empty($eventCenterOverride['skip'])) {
        return ['success' => true, 'error' => null, 'skipped' => true, 'queued' => !empty($eventCenterOverride['queued'])];
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $override = mapAdminResolveOverride($GLOBALS['pdo'], $eventKey, $message);
    }

    if (!$override['enabled']) {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            mapAdminWriteLog($GLOBALS['pdo'], [
                'event_key' => $eventKey,
                'group_id' => '',
                'message_text' => (string)$message,
                'success' => 1,
                'response_text' => 'Skipped: disabled by admin settings',
                'error_text' => '',
            ]);
        }
        return ['success' => true, 'error' => null, 'skipped' => true];
    }

    $messageToSend = (string)$override['message'];
    if (is_array($eventCenterOverride) && isset($eventCenterOverride['message'])) {
        $messageToSend = (string)$eventCenterOverride['message'];
    }
    if ($messageToSend !== $message) {
        $hasPlaceholders = (bool)preg_match('/\{[a-zA-Z0-9_]+\}/', $messageToSend);
        if ($hasPlaceholders && count($context) <= 1) {
            $messageToSend = $message;
        }
        $messageToSend = mapAdminRenderTemplate($messageToSend, $context);
        if (trim($messageToSend) === '' || $messageToSend === '-') {
            $messageToSend = $message;
        }
    }

    $api = mapGetDirectMaxConfig();
    if (!empty($override['chat_id'])) {
        $api['chat_id'] = (string)$override['chat_id'];
    }
    if (is_array($eventCenterOverride) && !empty($eventCenterOverride['chat_id'])) {
        $api['chat_id'] = (string)$eventCenterOverride['chat_id'];
    }
    if ($api['base_url'] === '' || $api['token'] === '' || $api['chat_id'] === '') {
        mapError('MAX notify: API config is not configured', [
            'has_base_url' => $api['base_url'] !== '',
            'has_token' => $api['token'] !== '',
            'has_chat_id' => $api['chat_id'] !== '',
        ]);
        return ['success' => false, 'error' => 'MAX notify API config is not configured'];
    }

    $payload = ['text' => normalizeUtf8Message($messageToSend)];
    $normalizedFormat = strtolower(trim($format));
    if ($normalizedFormat === 'markdown' || $normalizedFormat === 'html') {
        $payload['format'] = $normalizedFormat;
    }

    $url = $api['base_url'] . '/messages?chat_id=' . rawurlencode($api['chat_id']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $api['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = (string)curl_error($ch);

    $ok = ($resp !== false && $httpCode >= 200 && $httpCode < 300);
    if (!$ok) {
        mapError('MAX notify failed', [
            'url' => $url,
            'http_code' => $httpCode,
            'curl_error' => $curlErr,
            'response' => $resp,
        ]);

        $notifyKey = mapGetNotifyKey();
        if ($notifyKey !== '') {
            $fallbackQuery = http_build_query([
                'key' => $notifyKey,
                'text' => normalizeUtf8Message($messageToSend),
                'format' => $normalizedFormat === 'html' ? 'html' : 'markdown',
            ], '', '&', PHP_QUERY_RFC3986);
            $fallbackUrl = 'http://spugovxsim.temp.swtest.ru/fregat/feo/notify_max.php?' . $fallbackQuery;
            $fallbackResp = @file_get_contents($fallbackUrl);
            if (is_string($fallbackResp) && $fallbackResp !== '') {
                $decoded = json_decode($fallbackResp, true);
                if (is_array($decoded) && !empty($decoded['success'])) {
                    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                        mapAdminWriteLog($GLOBALS['pdo'], [
                            'event_key' => $eventKey,
                            'group_id' => (string)$api['chat_id'],
                            'message_text' => normalizeUtf8Message($messageToSend),
                            'success' => 1,
                            'response_text' => (string)$fallbackResp,
                            'error_text' => '',
                        ]);
                    }
                    return ['success' => true, 'error' => null];
                }
            }
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            mapAdminWriteLog($GLOBALS['pdo'], [
                'event_key' => $eventKey,
                'group_id' => (string)$api['chat_id'],
                'message_text' => normalizeUtf8Message($messageToSend),
                'success' => 0,
                'response_text' => (string)$resp,
                'error_text' => $curlErr !== '' ? $curlErr : 'MAX notify request failed',
            ]);
            mapWriteMaxLog($GLOBALS['pdo'], [
                'event_key' => $eventKey,
                'group_id' => (string)$api['chat_id'],
                'message_text' => normalizeUtf8Message($messageToSend),
                'success' => 0,
                'status' => 'error',
                'response_text' => (string)$resp,
                'error_text' => $curlErr !== '' ? $curlErr : 'MAX notify request failed',
                'context' => $context,
            ]);
        }

        return ['success' => false, 'error' => 'MAX notify request failed'];
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        mapAdminWriteLog($GLOBALS['pdo'], [
            'event_key' => $eventKey,
            'group_id' => (string)$api['chat_id'],
            'message_text' => normalizeUtf8Message($messageToSend),
            'success' => 1,
            'response_text' => (string)$resp,
            'error_text' => '',
        ]);
        mapWriteMaxLog($GLOBALS['pdo'], [
            'event_key' => $eventKey,
            'group_id' => (string)$api['chat_id'],
            'message_text' => normalizeUtf8Message($messageToSend),
            'success' => 1,
            'status' => 'success',
            'response_text' => (string)$resp,
            'error_text' => '',
            'context' => $context,
        ]);
    }
    return ['success' => true, 'error' => null];
}

function notifyEvent(string $eventKey, array $context = [], string $fallbackMessage = '', string $format = 'markdown'): array
{
    $message = trim($fallbackMessage);
    if ($message === '') {
        $message = (string)mapAdminRenderTemplate('{message}', $context);
        if (trim($message) === '' && isset($context['message'])) {
            $message = (string)$context['message'];
        }
        if (trim($message) === '') {
            $message = $eventKey;
        }
    }
    return sendMaxNotify($message, $format, [
        'event_key' => $eventKey,
        'context' => $context,
    ]);
}
