<?php
declare(strict_types=1);

/**
 * 🤖 MAX Bot API — Полный набор примеров отправки сообщений
 * ✅ Совместимо с PHP 8.0 - 8.4+
 * 🔐 После тестов отзовите токен в https://dev.max.ru
 */

$token = trim((string) (getenv('MAX_API_TOKEN') ?: ''));
$chatId = -72040763572741;
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
$result = sendMaxMessage($base, $token, $chatId, "✅ Привет! ТЕСТ.\nВремя: " . date('H:i:s'));
printResult("Простой текст", $result);

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