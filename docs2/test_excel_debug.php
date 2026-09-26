<?php
// test_excel_debug.php

$placesDescription = "(0.4×0.3×0.5) - 9 шт (0.4×0.29×0.5) - 1 шт (0.3×0.47×0.42) - 1 шт (0.34×0.22×0.54) - 3 шт (0.3×0.3×0.4) - 1 шт (0.42×0.22×0.42) - 1 шт (0.35×0.25×0.35) - 2 шт (0.26×0.12×0.37) - 1 шт (0.16×0.12×0.4) - 1 шт (0.23×0.2×0.48) - 3 шт (0.18×0.44×0.7) - 16 шт (0.16×0.22×0.37) - 11 шт (0.12×0.17×0.31) - 7 шт (0.43×0.34×0.67) - 2 шт (0.14×0.17×0.37) - 1 шт";

echo "Длина строки: " . strlen($placesDescription) . " байт\n";
echo "Длина в UTF-8 символах: " . mb_strlen($placesDescription) . "\n\n";

// Разбиваем на символы и проверяем
$chars = preg_split('//u', $placesDescription, -1, PREG_SPLIT_NO_EMPTY);

$problemPositions = [];
foreach ($chars as $pos => $char) {
    $ord = mb_ord($char, 'UTF-8');
    // Разрешенные символы: цифры, буквы, пробел, точка, скобки, дефис, ×(215), русские буквы
    if ($ord < 32) {
        $problemPositions[] = "POS $pos: управляющий символ (код $ord)";
    }
    if ($ord === 0xFFFD) {
        $problemPositions[] = "POS $pos: ЗАМЕНЯЮЩИЙ СИМВОЛ (код 65533) - это проблема!";
    }
    if ($ord > 0xFFFF) {
        $problemPositions[] = "POS $pos: 4-байтовый символ $char (код $ord)";
    }
}

if (!empty($problemPositions)) {
    echo "⚠️ НАЙДЕНЫ ПРОБЛЕМНЫЕ СИМВОЛЫ:\n";
    foreach ($problemPositions as $p) {
        echo "  $p\n";
    }
} else {
    echo "✅ Символы в норме\n";
}

// Очистка строки
$cleaned = preg_replace('/[^\x{0000}-\x{FFFF}]/u', '?', $placesDescription);
$cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleaned);

echo "\nОчищенная строка:\n";
echo $cleaned . "\n";

// Сохраняем HEX дамп
$hex = bin2hex($placesDescription);
file_put_contents('debug_hex.txt', $hex);
echo "\nHEX дамп сохранён в debug_hex.txt";