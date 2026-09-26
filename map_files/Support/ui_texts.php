<?php

if (!function_exists('mapUiTextTableReady')) {
    function mapUiTextTableReady(PDO $pdo): bool
    {
        static $checked = null;
        if ($checked !== null) {
            return $checked;
        }
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'ui_static_texts'");
            $checked = (bool)($stmt && $stmt->fetchColumn());
            return $checked;
        } catch (Throwable $e) {
            $checked = false;
            return false;
        }
    }
}

if (!function_exists('mapUiTextColumns')) {
    function mapUiTextColumns(PDO $pdo): array
    {
        static $columns = null;
        if (is_array($columns)) {
            return $columns;
        }
        $columns = [];
        if (!mapUiTextTableReady($pdo)) {
            return $columns;
        }
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM ui_static_texts');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ((array)$rows as $row) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $columns;
    }
}

if (!function_exists('mapLoadUiTexts')) {
    function mapLoadUiTexts(PDO $pdo): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $cache = [];
        $columns = mapUiTextColumns($pdo);
        if (empty($columns)) {
            return $cache;
        }

        $keyCol = isset($columns['text_key']) ? 'text_key' : (isset($columns['key_name']) ? 'key_name' : (isset($columns['key']) ? 'key' : ''));
        $valueCol = isset($columns['text_value']) ? 'text_value' : (isset($columns['text']) ? 'text' : '');
        if ($keyCol === '' || $valueCol === '') {
            return $cache;
        }

        try {
            $stmt = $pdo->query("SELECT `{$keyCol}` AS text_key, `{$valueCol}` AS text_value FROM ui_static_texts");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ((array)$rows as $row) {
                $key = trim((string)($row['text_key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $cache[$key] = (string)($row['text_value'] ?? '');
            }
        } catch (Throwable $e) {
            return [];
        }

        return $cache;
    }
}

if (!function_exists('ui_text')) {
    function ui_text(string $key, string $fallback = ''): string
    {
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            return $fallback;
        }
        $texts = mapLoadUiTexts($pdo);
        if (!array_key_exists($key, $texts)) {
            return $fallback;
        }
        $value = trim((string)$texts[$key]);
        return $value !== '' ? $value : $fallback;
    }
}
