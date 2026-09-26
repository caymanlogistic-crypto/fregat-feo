<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection error');
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS utilizers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            full_name VARCHAR(255) NOT NULL,
            short_name VARCHAR(80) NOT NULL,
            address TEXT NOT NULL,
            fkko_type TEXT NULL,
            dangerous_cargo ENUM('Да','Нет') NOT NULL DEFAULT 'Нет',
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            color_main CHAR(7) NOT NULL DEFAULT '#f4b183',
            color_dark CHAR(7) NOT NULL DEFAULT '#7f4125',
            color_text CHAR(7) NOT NULL DEFAULT '#7f4125',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_utilizers_full_name_address (full_name, address(180)),
            KEY idx_utilizers_active_sort (is_active, sort_order),
            KEY idx_utilizers_coordinates (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = $pdo->query("SHOW COLUMNS FROM utilizers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('color_main', $columns, true)) {
            $pdo->exec("ALTER TABLE utilizers ADD COLUMN color_main CHAR(7) NOT NULL DEFAULT '#f4b183' AFTER longitude");
        }
        if (!in_array('color_dark', $columns, true)) {
            $pdo->exec("ALTER TABLE utilizers ADD COLUMN color_dark CHAR(7) NOT NULL DEFAULT '#7f4125' AFTER color_main");
        }
        if (!in_array('color_text', $columns, true)) {
            $pdo->exec("ALTER TABLE utilizers ADD COLUMN color_text CHAR(7) NOT NULL DEFAULT '#7f4125' AFTER color_dark");
            $pdo->exec("UPDATE utilizers SET color_text = color_dark");
        }

        $pdo->exec("DELETE u1 FROM utilizers u1
            INNER JOIN utilizers u2
                ON LOWER(TRIM(u1.short_name)) = LOWER(TRIM(u2.short_name))
               AND u1.id > u2.id");

        $indexes = $pdo->query("SHOW INDEX FROM utilizers")->fetchAll(PDO::FETCH_ASSOC);
        $hasShortNameUnique = false;
        foreach ($indexes as $index) {
            if (($index['Key_name'] ?? '') === 'uq_utilizers_short_name' && (int)($index['Non_unique'] ?? 1) === 0) {
                $hasShortNameUnique = true;
                break;
            }
        }
        if (!$hasShortNameUnique) {
            $pdo->exec("ALTER TABLE utilizers ADD UNIQUE KEY uq_utilizers_short_name (short_name)");
        }

        $rows = [
            ['АО "НПП "КУБАНЬЦВЕТМЕТ"', 'КУБАНЬЦВЕТМЕТ', 'Краснодарский край, Абинский район, ст. Холмская, территория металлургического завода', 'бой стеклянный ртутных ламп и термометров с остатками ртути', 'Нет', 44.848046, 38.389234, 10],
            ['ООО "ЛИМПЭК"', 'ЛИМПЭК', 'Ярославская область, Тутаевский район, п. Чебаково, ул. Дорожная, д.3', 'отходы растворителей на основе тетрахлорметана', 'Да', 57.756054, 39.537039, 20],
            ['ООО "МЕРКОМ"', 'МЕРКОМ', 'Московская обл, г Лыткарино, тер промзона Тураево, стр 8, НИИП', 'отходы термометров ртутных', 'Нет', 55.571250, 37.947012, 30],
            ['ООО "НЭК - ИННОВАЦИИ"', 'НЭК', 'Ярославская область, г.Ярославль, пр-кт Октября, д. 78, 1 этаж № 49-51, 54-57; 2 этаж № 32-49, кадастровый номер 76:23:050102:1402', 'одиночные гальванические элементы (батарейки) никель-кадмиевые неповрежденные отработанные', 'Нет', 57.653353, 39.862345, 40],
            ['ООО "ПК "ЭКО+"', 'ЭКО+', 'Астраханская область, Икрянинский Муниципальный Район, Городское Поселение Рабочий Поселок Ильинка, Ильинка рабочий поселок, территория Промышленный участок 3', 'смесь галогенсодержащих органических веществ', 'Да', 46.235181, 47.901944, 50],
            ['ООО "ПРОМХИМЛАБ"', 'ПРОМХИМЛАБ', '656904, Алтайский край, г. Барнаул, Южный проезд, д. 21А', 'жидкие отходы, содержащие хлорорганические соединения', 'Да', 53.312297, 83.643331, 60],
            ['ООО "ФИРМА ЛИПЕЦКЭКОХИМ"', 'ЛИПЕЦКЭКОХИМ', '398041, Липецкая область, г. Липецк, ул. Передельческая, д.5, литер "И"', 'кислота аккумуляторная серная отработанная', 'Да', 52.569024, 39.678775, 70],
            ['ООО "ФРЕГАТ"', 'ФРЕГАТ', 'Московская обл, г. Воскресенск, ул. Кирова, д. 3 в', 'аккумуляторы', 'Нет', 55.295473, 38.705073, 80],
        ];

        $stmt = $pdo->prepare("INSERT INTO utilizers
            (full_name, short_name, address, fkko_type, dangerous_cargo, latitude, longitude, color_main, color_dark, color_text, is_active, sort_order)
            VALUES (:full_name, :short_name, :address, :fkko_type, :dangerous_cargo, :latitude, :longitude, '#f4b183', '#7f4125', '#7f4125', 1, :sort_order)
            ON DUPLICATE KEY UPDATE
                full_name=VALUES(full_name),
                address=VALUES(address),
                fkko_type=VALUES(fkko_type),
                dangerous_cargo=VALUES(dangerous_cargo),
                latitude=VALUES(latitude),
                longitude=VALUES(longitude),
                sort_order=VALUES(sort_order)");
        foreach ($rows as $row) {
            $stmt->execute([
                ':full_name'=>$row[0], ':short_name'=>$row[1], ':address'=>$row[2], ':fkko_type'=>$row[3],
                ':dangerous_cargo'=>$row[4], ':latitude'=>$row[5], ':longitude'=>$row[6], ':sort_order'=>$row[7],
            ]);
        }

        $count = (int)$pdo->query('SELECT COUNT(*) FROM utilizers')->fetchColumn();
        $message = "Таблица utilizers обновлена. Добавлен color_text, удалены дубли и включена защита от повторного создания. Записей: {$count}.";
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Создание таблицы утилизаторов</title>
<style>body{margin:0;background:#101820;color:#d9e2ec;font-family:Segoe UI,Arial,sans-serif}.wrap{max-width:900px;margin:0 auto;padding:18px}.panel{background:#162331;border:1px solid #274056;border-radius:8px;padding:16px}.btn{height:36px;border:1px solid #3f6079;background:#0f7f75;color:#fff;border-radius:6px;padding:0 14px;cursor:pointer}.ok{color:#8ee6b5}.err{color:#ff9c9c}.admin-nav{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}.admin-nav .btn{display:inline-flex;align-items:center;text-decoration:none;background:#23384a}</style></head>
<body><div class="wrap"><?php renderAdminNav('utilizers'); ?><div class="panel"><h2>Создание/обновление таблицы утилизаторов</h2><p>Скрипт добавляет <code>color_text</code>, удаляет дубли по сокращённому имени и запрещает их повторное создание. Настроенные цвета существующих записей не перезаписываются.</p>
<?php if ($message): ?><p class="ok"><?= maxAdminHtml($message) ?></p><?php endif; ?><?php if ($error): ?><p class="err"><?= maxAdminHtml($error) ?></p><?php endif; ?>
<form method="post"><button class="btn" type="submit">Создать/обновить таблицу</button></form><p><a style="color:#8fd3ff" href="utilizers_admin.php">Перейти в управление утилизаторами</a></p></div></div></body></html>
