<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection error');
}

// Палитра привязана к фактическим ID из текущей админки, поэтому
// изменение/сокращение short_name больше не мешает применению цветов.
$palette = [
    1 => ['name' => 'КУБАНЬЦВЕТ', 'colors' => ['#8D6E63', '#4E342E', '#FFFFFF']],
    2 => ['name' => 'ЛИМПЭК',      'colors' => ['#F9A825', '#8D5B00', '#111111']],
    3 => ['name' => 'МЕРКОМ',      'colors' => ['#43A047', '#145A1F', '#FFFFFF']],
    4 => ['name' => 'НЭК',         'colors' => ['#8E24AA', '#4A005F', '#FFFFFF']],
    5 => ['name' => 'ЭКО+',        'colors' => ['#00ACC1', '#006064', '#FFFFFF']],
    6 => ['name' => 'ПРОМХИМ',     'colors' => ['#1E88E5', '#0D47A1', '#FFFFFF']],
    7 => ['name' => 'ЛИПЕЦКЭКО',   'colors' => ['#D81B60', '#880E4F', '#FFFFFF']],
    8 => ['name' => 'ФРЕГАТ',      'colors' => ['#546E7A', '#263238', '#FFFFFF']],
];

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM utilizers')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['color_main', 'color_dark', 'color_text'] as $column) {
            if (!in_array($column, $columns, true)) {
                throw new RuntimeException('Сначала обновите таблицу utilizers: отсутствует поле ' . $column);
            }
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE utilizers SET color_main=:main, color_dark=:dark, color_text=:text WHERE id=:id');
        foreach ($palette as $id => $item) {
            $colors = $item['colors'];
            $stmt->execute([
                ':main' => $colors[0],
                ':dark' => $colors[1],
                ':text' => $colors[2],
                ':id' => $id,
            ]);
        }

        $ids = implode(',', array_map('intval', array_keys($palette)));
        $rows = $pdo->query("SELECT id, short_name, color_main, color_dark, color_text FROM utilizers WHERE id IN ($ids) ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== count($palette)) {
            throw new RuntimeException('Не найдены все 8 утилизаторов. Найдено: ' . count($rows));
        }

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $expected = $palette[$id]['colors'];
            if (
                strcasecmp((string)$row['color_main'], $expected[0]) !== 0 ||
                strcasecmp((string)$row['color_dark'], $expected[1]) !== 0 ||
                strcasecmp((string)$row['color_text'], $expected[2]) !== 0
            ) {
                throw new RuntimeException('Проверка цветов не пройдена для ID ' . $id);
            }
        }

        $pdo->commit();
        $message = 'Рекомендуемая палитра применена и проверена: 8 из 8 утилизаторов.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Палитра утилизаторов</title>
<style>
body{margin:0;background:#101820;color:#d9e2ec;font-family:Segoe UI,Arial,sans-serif}.wrap{max-width:1050px;margin:0 auto;padding:18px}.panel{background:#162331;border:1px solid #274056;border-radius:8px;padding:16px;margin-bottom:14px}.btn{min-height:36px;border:1px solid #0f9d90;background:#0f7f75;color:#fff;border-radius:6px;padding:8px 14px;cursor:pointer;font-weight:700}.ok{color:#8ee6b5}.err{color:#ff9c9c}.palette{display:grid;gap:8px}.row{display:grid;grid-template-columns:55px 150px 160px 90px 90px 90px;gap:10px;align-items:center;padding:8px;border-bottom:1px solid #2b4356}.marker{display:inline-flex;align-items:center;justify-content:center;min-width:130px;height:28px;padding:2px 8px 0;border-radius:6px;font-size:12px;font-weight:700;box-sizing:border-box}.swatch{width:34px;height:26px;border:1px solid #000;border-radius:4px}@media(max-width:760px){.row{grid-template-columns:1fr 1fr}.row>*{min-width:0}}
</style>
</head>
<body><div class="wrap">
<?php renderAdminNav('utilizers'); ?>
<div class="panel">
<h2>Рекомендуемая палитра утилизаторов</h2>
<p>Обновление выполняется по ID 1–8 и после записи автоматически проверяет все три цветовых поля. Красный цвет из тестовой палитры удалён.</p>
<?php if ($message): ?><p class="ok"><?= maxAdminHtml($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="err"><?= maxAdminHtml($error) ?></p><?php endif; ?>
<form method="post" onsubmit="return confirm('Применить рекомендуемые цвета ко всем восьми утилизаторам?')"><button class="btn" type="submit">Применить рекомендуемую палитру</button></form>
</div>
<div class="panel palette">
<?php foreach ($palette as $id => $item): $colors = $item['colors']; ?>
<div class="row">
<span>#<?= (int)$id ?></span>
<strong><?= maxAdminHtml($item['name']) ?></strong>
<span class="marker" style="background:<?= maxAdminHtml($colors[0]) ?>;border:2px solid <?= maxAdminHtml($colors[1]) ?>;color:<?= maxAdminHtml($colors[2]) ?>"><?= maxAdminHtml($item['name']) ?></span>
<span class="swatch" title="Основной" style="background:<?= maxAdminHtml($colors[0]) ?>"></span>
<span class="swatch" title="Обводка" style="background:<?= maxAdminHtml($colors[1]) ?>"></span>
<span class="swatch" title="Текст" style="background:<?= maxAdminHtml($colors[2]) ?>"></span>
</div>
<?php endforeach; ?>
</div>
<p><a style="color:#8fd3ff" href="utilizers_admin.php">Вернуться в управление утилизаторами</a></p>
</div></body></html>
