<?php
/**
 * Вспомогательные функции для работы с Google Sheets
 */

/**
 * Получает данные из Google Sheets в виде CSV
 * 
 * @param string $spreadsheetId ID Google таблицы
 * @param int $gid ID листа (по умолчанию 0 - первый лист)
 * @return array Двумерный массив с данными таблицы
 */
function getGoogleSheetsData($spreadsheetId = '1e-iA425O2mhvF12WDnH_lpd6Z3HbCgBOtYtWuF-F6Z4', $gid = 1200010467) {
    $csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid={$gid}";
    
    $csvData = file_get_contents($csvUrl);
    
    if ($csvData === false) {
        return [];
    }
    
    // Парсинг CSV
    $rows = [];
    $inQuote = false;
    $currentRow = [];
    $currentField = '';
    
    $length = strlen($csvData);
    for ($i = 0; $i < $length; $i++) {
        $char = $csvData[$i];
        $nextChar = ($i < $length - 1) ? $csvData[$i + 1] : '';
        
        if ($char === '"' && !$inQuote) {
            $inQuote = true;
        } elseif ($char === '"' && $inQuote && $nextChar === '"') {
            $currentField .= '"';
            $i++;
        } elseif ($char === '"' && $inQuote) {
            $inQuote = false;
        } elseif ($char === ',' && !$inQuote) {
            $currentRow[] = $currentField;
            $currentField = '';
        } elseif ($char === "\n" && !$inQuote) {
            $currentRow[] = $currentField;
            if (!empty(array_filter($currentRow))) {
                $rows[] = $currentRow;
            }
            $currentRow = [];
            $currentField = '';
        } else {
            $currentField .= $char;
        }
    }
    
    if (!empty($currentField) || !empty($currentRow)) {
        $currentRow[] = $currentField;
        if (!empty(array_filter($currentRow))) {
            $rows[] = $currentRow;
        }
    }
    
    // Очищаем поля от лишних пробелов
    foreach ($rows as &$row) {
        foreach ($row as &$field) {
            $field = trim($field);
        }
    }
    
    return $rows;
}

/**
 * Получает данные с ассоциативными ключами (первая строка - заголовки)
 * 
 * @param string $spreadsheetId ID Google таблицы
 * @param int $gid ID листа
 * @return array Массив с ассоциативными ключами
 */
function getGoogleSheetsDataAssoc($spreadsheetId = '1e-iA425O2mhvF12WDnH_lpd6Z3HbCgBOtYtWuF-F6Z4', $gid = 0) {
    $data = getGoogleSheetsData($spreadsheetId, $gid);
    
    if (empty($data)) {
        return [];
    }
    
    $headers = array_shift($data); // Первая строка - заголовки
    $result = [];
    
    foreach ($data as $row) {
        $assocRow = [];
        foreach ($headers as $index => $header) {
            $assocRow[$header] = $row[$index] ?? '';
        }
        $result[] = $assocRow;
    }
    
    return $result;
}
?>