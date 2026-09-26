<?php
declare(strict_types=1);

/**
 * 🤖 MAX Bot API — Полный набор примеров отправки сообщений
 * ✅ Совместимо с PHP 8.0 - 8.4+
 * 🔐 После тестов отзовите токен в https://dev.max.ru
 */

$token = trim((string) (getenv('MAX_API_TOKEN') ?: ''));
$chatId = -70991778594913;
$base   = 'https://platform-api.max.ru';

/**
 * Отправка сообщения в чат MAX
 * @param ?string $format - явно nullable для совместимости с PHP 8.4+
 */
function sendMaxMessage(
    string $base, 
    string $token, 
    int $chatId, 
    string $text, 
    array $attachments = [], 
    ?string $format = null
): array {
    $url = $base . '/messages?chat_id=' . $chatId;
    
    $payload = ['text' => $text];
    
    if (!empty($attachments)) {
        $payload['attachments'] = $attachments;
    }
    
    if ($format !== null && in_array($format, ['html', 'markdown'], true)) {
        $payload['format'] = $format;
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => json_decode($response, true) ?: $response
    ];
}

/**
 * Вспомогательная функция для красивого вывода результата
 */
function printResult(string $label, array $result): void {
    echo "\n" . str_repeat("─", 60) . "\n";
    echo "📦 {$label}\n";
    echo "→ HTTP {$result['code']}\n";
    if ($result['code'] === 200 || $result['code'] === 201) {
        $msgId = $result['body']['message_id'] ?? 'N/A';
        echo "✅ Отправлено! (ID: {$msgId})\n";
    } else {
        echo "❌ Ошибка: " . json_encode($result['body'], JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "🚀 Запуск примеров отправки сообщений в MAX...\n";
echo "📦 Chat ID: {$chatId}\n";

// ============================================================================
// 🔤 БАЗОВЫЕ ПРИМЕРЫ
// ============================================================================

// 1. Простое текстовое сообщение
echo "\n🔹 1. Простой текст";
$result = sendMaxMessage($base, $token, $chatId, "✅ Привет! Это простое текстовое сообщение от бота Бэрримор.\nВремя: " . date('H:i:s'));
printResult("Простой текст", $result);

// 2. Сообщение с переносами и эмодзи
echo "\n🔹 2. Текст с эмодзи и переносами";
$result = sendMaxMessage($base, $token, $chatId, "🎉 Ура!\n✨ Всё работает!\n🤖 Бот отправляет сообщения в группу MAX\n\n📅 " . date('d.m.Y'));
printResult("Эмодзи + переносы", $result);

// ============================================================================
// 🎨 ФОРМАТИРОВАНИЕ: HTML
// ============================================================================

// 3. HTML: жирный, курсив, подчёркнутый
echo "\n🔹 3. HTML: базовое форматирование";
$html1 = "<b>Жирный текст</b>, <i>курсив</i> и <u>подчёркнутый</u>";
$result = sendMaxMessage($base, $token, $chatId, $html1, [], 'html');
printResult("HTML: b/i/u", $result);

// 4. HTML: заголовки и списки
echo "\n🔹 4. HTML: заголовки и списки";
$html2 = "<b>📋 Список задач:</b>\n" .
         "<ul>" .
         "<li>✅ Настроить бота</li>" .
         "<li>✅ Отправить сообщение</li>" .
         "<li>⏳ Добавить кнопки</li>" .
         "</ul>";
$result = sendMaxMessage($base, $token, $chatId, $html2, [], 'html');
printResult("HTML: списки", $result);

// 5. HTML: код и цитаты
echo "\n🔹 5. HTML: код и цитаты";
$html3 = "Пример кода:\n" .
         "<pre><code class=\"language-php\">&lt;?php\necho \"Hello MAX!\";\n?&gt;</code></pre>\n\n" .
         "<blockquote>💡 Цитата: Хороший код — это код, который работает с первого раза.</blockquote>";
$result = sendMaxMessage($base, $token, $chatId, $html3, [], 'html');
printResult("HTML: код + цитата", $result);

// 6. HTML: ссылки в тексте
echo "\n🔹 6. HTML: ссылки в тексте";
$html4 = "Полезные ссылки:\n" .
         "• <a href=\"https://dev.max.ru\">📚 Документация MAX</a>\n" .
         "• <a href=\"https://max.ru\">🌐 Официальный сайт</a>\n" .
         "• <a href=\"https://t.me/max_support\">💬 Поддержка</a>";
$result = sendMaxMessage($base, $token, $chatId, $html4, [], 'html');
printResult("HTML: ссылки", $result);

// ============================================================================
// 🎨 ФОРМАТИРОВАНИЕ: MARKDOWN
// ============================================================================

// 7. Markdown: базовое форматирование
echo "\n🔹 7. Markdown: базовое форматирование";
$md1 = "**Жирный текст**, *курсив* и __подчёркнутый__";
$result = sendMaxMessage($base, $token, $chatId, $md1, [], 'markdown');
printResult("Markdown: bold/italic", $result);

// 8. Markdown: код и цитаты
echo "\n🔹 8. Markdown: код и цитаты";
$md2 = "Пример кода:\n" .
       "```\n<?php\necho \"Hello MAX!\";\n?>\n```\n\n" .
       "> 💡 Совет: Используйте форматирование для улучшения читаемости";
$result = sendMaxMessage($base, $token, $chatId, $md2, [], 'markdown');
printResult("Markdown: код + цитата", $result);

// 9. Markdown: списки
echo "\n🔹 9. Markdown: списки";
$md3 = "📋 План работ:\n" .
       "1. ✅ Создать бота\n" .
       "2. ✅ Получить токен\n" .
       "3. ✅ Написать скрипт\n" .
       "4. ⏳ Запустить в продакшен\n\n" .
       "- [x] Тест 1 пройден\n" .
       "- [ ] Тест 2 в процессе";
$result = sendMaxMessage($base, $token, $chatId, $md3, [], 'markdown');
printResult("Markdown: списки", $result);

// ============================================================================
// 🔘 КНОПКИ: INLINE_KEYBOARD
// ============================================================================

// 10. Одна кнопка-ссылка
echo "\n🔹 10. Кнопка: одна ссылка";
$btn1 = [[
    'type' => 'inline_keyboard',
    'payload' => [
        'buttons' => [[
            ['type' => 'link', 'text' => '🌐 Открыть сайт', 'url' => 'https://example.com']
        ]]
    ]
]];
$result = sendMaxMessage($base, $token, $chatId, "Нажмите кнопку ниже 👇", $btn1);
printResult("Кнопка: ссылка", $result);

// 11. Две кнопки в ряд
echo "\n🔹 11. Кнопки: две в ряд";
$btn2 = [[
    'type' => 'inline_keyboard',
    'payload' => [
        'buttons' => [
            [
                ['type' => 'link', 'text' => '🔗 Сайт 1', 'url' => 'https://site1.com'],
                ['type' => 'link', 'text' => '🔗 Сайт 2', 'url' => 'https://site2.com']
            ]
        ]
    ]
]];
$result = sendMaxMessage($base, $token, $chatId, "Выберите ресурс:", $btn2);
printResult("Кнопки: два в ряд", $result);

// 12. Кнопки: несколько рядов + разные типы
echo "\n🔹 12. Кнопки: много рядов + callback";
$btn3 = [[
    'type' => 'inline_keyboard',
    'payload' => [
        'buttons' => [
            [
                ['type' => 'link', 'text' => '🌐 Веб-сайт', 'url' => 'https://example.com'],
                ['type' => 'link', 'text' => '📱 Telegram', 'url' => 'https://t.me/example']
            ],
            [
                ['type' => 'callback', 'text' => '✅ Подтвердить', 'callback_data' => 'confirm_action'],
                ['type' => 'callback', 'text' => '❌ Отмена', 'callback_data' => 'cancel_action']
            ],
            [
                ['type' => 'link', 'text' => '❓ Помощь', 'url' => 'https://example.com/help']
            ]
        ]
    ]
]];
$result = sendMaxMessage($base, $token, $chatId, "Действия:", $btn3);
printResult("Кнопки: сложные", $result);

// ============================================================================
// 🎯 КОМБИНИРОВАННЫЕ ПРИМЕРЫ
// ============================================================================

// 13. Текст + HTML + кнопки
echo "\n🔹 13. Комбо: HTML + кнопки";
$combo1 = "<b>🎉 Поздравляем!</b>\nВаш заказ <code>#12345</code> оформлен.\n\n" .
          "<i>Статус:</i> <u>в обработке</u>";
$comboBtn = [[
    'type' => 'inline_keyboard',
    'payload' => [
        'buttons' => [
            [
                ['type' => 'link', 'text' => '📦 Отследить', 'url' => 'https://example.com/track/12345'],
                ['type' => 'callback', 'text' => '📞 Поддержка', 'callback_data' => 'support_12345']
            ]
        ]
    ]
]];
$result = sendMaxMessage($base, $token, $chatId, $combo1, $comboBtn, 'html');
printResult("Комбо: HTML + кнопки", $result);

// 14. Markdown + кнопки + отключение превью
echo "\n🔹 14. Комбо: Markdown + кнопки + no preview";
// Для отключения превью ссылок добавляем параметр в URL
function sendMaxMessageNoPreview(string $base, string $token, int $chatId, string $text, array $attachments = [], ?string $format = null): array {
    $url = $base . '/messages?chat_id=' . $chatId . '&disable_link_preview=true';
    $payload = ['text' => $text];
    if (!empty($attachments)) $payload['attachments'] = $attachments;
    if ($format !== null && in_array($format, ['html', 'markdown'], true)) $payload['format'] = $format;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true) ?: $resp];
}

$mdCombo = "**📢 Важное объявление**\n" .
           "Ссылка: https://example.com\n" .
           "_Превью ссылки отключено_";
$mdBtn = [[
    'type' => 'inline_keyboard',
    'payload' => [
        'buttons' => [[
            ['type' => 'link', 'text' => '🔗 Подробнее', 'url' => 'https://example.com']
        ]]
    ]
]];
$result = sendMaxMessageNoPreview($base, $token, $chatId, $mdCombo, $mdBtn, 'markdown');
printResult("Markdown + кнопки + no preview", $result);

// ============================================================================
// 🧪 ТЕСТОВЫЕ / СЛУЖЕБНЫЕ СООБЩЕНИЯ
// ============================================================================

// 15. Системное сообщение (без форматирования)
echo "\n🔹 15. Системное сообщение";
$result = sendMaxMessage($base, $token, $chatId, "🔧 [SYSTEM] Heartbeat OK | Uptime: " . round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2) . "s");
printResult("Системное", $result);

// 16. Сообщение с таймстампом и отключённым уведомлением
echo "\n🔹 16. Тихое сообщение (notify: false)";
// Для notify=false нужно добавить в payload
function sendMaxMessageQuiet(string $base, string $token, int $chatId, string $text, ?string $format = null): array {
    $url = $base . '/messages?chat_id=' . $chatId;
    $payload = [
        'text' => $text,
        'notify' => false  // 🔕 Не уведомлять участников
    ];
    if ($format !== null && in_array($format, ['html', 'markdown'], true)) $payload['format'] = $format;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true) ?: $resp];
}

$result = sendMaxMessageQuiet($base, $token, $chatId, "🔕 Тихое обновление: данные синхронизированы");
printResult("Тихое (notify: false)", $result);

// ============================================================================
// 📊 ИТОГИ
// ============================================================================

echo "\n" . str_repeat("═", 60);
echo "\n✨ Все примеры отправлены!\n";
echo "\n📋 Шпаргалка по форматированию:\n";
echo "┌─────────────────────────────────────────┐\n";
echo "│ 🔤 HTML:                                │\n";
echo "│   <b>жирный</b> <i>курсив</i> <u>подчёрк</u>  │\n";
echo "│   <a href=\"url\">ссылка</a> <pre>код</pre>   │\n";
echo "│   <ul><li>список</li></ul>              │\n";
echo "│                                         │\n";
echo "│ 📝 Markdown:                            │\n";
echo "│   **жирный** *курсив* __подчёрк__      │\n";
echo "│   [ссылка](url) `код` ```блок```       │\n";
echo "│   > цитата  1. нумерованный            │\n";
echo "└─────────────────────────────────────────┘\n";
echo "\n🔘 Типы кнопок:\n";
echo "   • link  → открывает URL\n";
echo "   • callback → отправляет callback_data боту (нужен вебхук)\n";
echo "\n⚙️ Дополнительные параметры:\n";
echo "   • disable_link_preview=true → в query string\n";
echo "   • notify=false → в теле запроса (не уведомлять)\n";
echo "\n🔐 НАПОМИНАНИЕ: отзовите токен после тестов!\n";
echo "   https://dev.max.ru → Чат-боты → Ваш бот → Перевыпустить токен\n";
?>