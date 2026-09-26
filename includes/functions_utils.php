<?php
declare(strict_types=1);

// =============================================================================
// БАЗОВЫЕ УТИЛИТЫ
// =============================================================================

function safe_str($val): string {
    return ($val === null || $val === false) ? '' : (string) $val;
}

function h($val): string {
    return htmlspecialchars(safe_str($val), ENT_QUOTES, 'UTF-8');
}

function attr_escape($val): string {
    return htmlspecialchars(safe_str($val), ENT_QUOTES, 'UTF-8');
}

/**
 * АГРЕССИВНАЯ НОРМАЛИЗАЦИЯ ТЕКСТА ИЗ PDF
 */
function normalize_text(string $text): string {
    if ($text === '') return '';
    
    // 1. Заменяем переносы строк на пробелы
    $text = preg_replace('/[\r\n]+/', ' ', $text);
    
    // 2. Убираем пробелы вокруг дефиса
    $text = preg_replace('/\s*-\s*/u', '-', $text);
    
    // 3. Склеиваем буквы, между которыми есть пробелы (Уло жен ы -> Уложены)
    $prev = '';
    while ($prev !== $text) {
        $prev = $text;
        $text = preg_replace('/([а-яёА-ЯЁa-zA-Z])\s+([а-яёА-ЯЁa-zA-Z])/u', '$1$2', $text);
    }
    
    // 4. Схлопываем множественные пробелы
    $text = preg_replace('/\s+/', ' ', $text);
    
    return trim($text);
}