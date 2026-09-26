<?php
require_once 'config.php';

$flightId = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;

if (!$flightId) {
    die('Ошибка: не передан ID рейса');
}

try {
    $pdo = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4", $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

$stmt = $pdo->prepare("SELECT zayavki_ids FROM flights WHERE id = ?");
$stmt->execute([$flightId]);
$flight = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flight) {
    die('Рейс не найден');
}

$zayavkiIds = array_map('trim', explode(',', $flight['zayavki_ids']));
if (empty($zayavkiIds) || $zayavkiIds[0] === '') {
    die('В рейсе нет заявок');
}

$placeholders = implode(',', array_fill(0, count($zayavkiIds), '?'));
$stmt = $pdo->prepare("SELECT zayavka_id, mass_netto, naim_oo_gruzootpravitel, mno_adres_pogruzki, mno_sh, mno_d, zakaz_s_ot_status 
                        FROM feo 
                        WHERE zayavka_id IN ($placeholders) 
                        AND mno_sh IS NOT NULL 
                        AND mno_d IS NOT NULL
                        AND mno_sh != 0 
                        AND mno_d != 0");
$stmt->execute($zayavkiIds);
$points = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($points)) {
    die('Нет заявок с корректными координатами');
}

$grouped = [];
foreach ($points as $p) {
    $key = $p['mno_sh'] . '|' . $p['mno_d'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'mno_sh' => $p['mno_sh'],
            'mno_d' => $p['mno_d'],
            'address' => $p['mno_adres_pogruzki'],
            'org' => $p['naim_oo_gruzootpravitel'],
            'points' => [],
            'total_mass' => 0,
            'count' => 0
        ];
    }
    $grouped[$key]['points'][] = $p;
    $grouped[$key]['total_mass'] += $p['mass_netto'];
    $grouped[$key]['count']++;
}
$groupedData = array_values($grouped);

$totalWeight = 0;
foreach ($groupedData as $g) {
    $totalWeight += round($g['total_mass'] * 1000);
}

$minLat = 999;
$maxLat = -999;
$minLng = 999;
$maxLng = -999;

foreach ($groupedData as $g) {
    $lat = floatval($g['mno_d']);
    $lng = floatval($g['mno_sh']);
    if ($lat != 0 && $lng != 0) {
        if ($lat < $minLat) $minLat = $lat;
        if ($lat > $maxLat) $maxLat = $lat;
        if ($lng < $minLng) $minLng = $lng;
        if ($lng > $maxLng) $maxLng = $lng;
    }
}

$centerLat = ($minLat + $maxLat) / 2;
$centerLng = ($minLng + $maxLng) / 2;

if ($minLat == 999) {
    $centerLat = 55.751574;
    $centerLng = 37.573856;
    $zoom = 10;
} else {
    $latDiff = $maxLat - $minLat;
    $lngDiff = $maxLng - $minLng;
    $maxDiff = max($latDiff, $lngDiff);
    
    if ($maxDiff < 0.01) $zoom = 15;
    elseif ($maxDiff < 0.05) $zoom = 13;
    elseif ($maxDiff < 0.1) $zoom = 12;
    elseif ($maxDiff < 0.5) $zoom = 11;
    elseif ($maxDiff < 1) $zoom = 10;
    elseif ($maxDiff < 2) $zoom = 9;
    elseif ($maxDiff < 5) $zoom = 8;
    elseif ($maxDiff < 10) $zoom = 7;
    else $zoom = 6;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Рейс #<?= $flightId ?></title>
    <script src="https://api-maps.yandex.ru/2.1/?apikey=<?= $apiKeyYandex ?>&lang=ru_RU"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { width: 100%; height: 100%; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        #map { width: 100%; height: 65%; }
        .info-panel {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-radius: 20px 20px 0 0;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
            padding: 16px 12px;
            z-index: 1000;
            max-height: 35%;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .flight-title { font-size: 20px; font-weight: 700; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 2px solid #e0e0e0; color: #1a1a1a; }
        .stats { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .stat { background: #f0f0f0; padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: 500; color: #333; }
        .points-list { display: flex; flex-direction: column; gap: 8px; }
        .point-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.2s;
            gap: 10px;
        }
        .point-item:active { background: #e9ecef; }
        .point-info { flex: 1; min-width: 0; }
        .point-numbers { font-weight: 700; color: #1976d2; font-size: 14px; margin-bottom: 4px; word-break: break-word; }
        .warning-badge { background: #ffc107; color: #856404; font-size: 10px; padding: 3px 8px; border-radius: 12px; display: inline-block; margin: 4px 0; font-weight: 500; }
        .point-org { font-size: 12px; color: #666; margin-top: 4px; line-height: 1.3; word-break: break-word; }
        .point-address { font-size: 11px; color: #888; margin-top: 4px; line-height: 1.3; word-break: break-word; }
        .point-weight { color: #d32f2f; font-weight: 700; font-size: 14px; white-space: nowrap; flex-shrink: 0; padding-top: 2px; }
        .total-weight { background: #e3f2fd; border-radius: 12px; padding: 12px; text-align: center; font-weight: 700; font-size: 14px; color: #1976d2; margin-top: 12px; }
        .footer { text-align: center; font-size: 11px; color: #aaa; margin-top: 12px; padding-top: 10px; border-top: 1px solid #eee; }
        @media (max-width: 480px) {
            .info-panel { padding: 12px 10px; max-height: 40%; }
            .flight-title { font-size: 18px; }
            .stat { font-size: 11px; padding: 4px 10px; }
            .point-item { padding: 10px; }
            .point-numbers { font-size: 13px; }
            .point-weight { font-size: 13px; }
            .point-org, .point-address { font-size: 10px; }
        }
    </style>
</head>
<body>
<div id="map"></div>

<div class="info-panel">
    <div class="flight-title">🚚 Рейс #<?= $flightId ?></div>
    <div class="stats">
        <span class="stat">📦 Заявок: <?= count($points) ?></span>
        <span class="stat">📍 Адресов: <?= count($groupedData) ?></span>
        <span class="stat">⚖️ Вес: <?= number_format($totalWeight, 0, ',', ' ') ?> кг</span>
    </div>
    <div class="points-list">
        <?php foreach ($groupedData as $idx => $group): 
            $weight = round($group['total_mass'] * 1000);
            $ids = implode(', ', array_column($group['points'], 'zayavka_id'));
            $isMultiple = $group['count'] > 1;
        ?>
        <div class="point-item" data-lat="<?= $group['mno_d'] ?>" data-lng="<?= $group['mno_sh'] ?>" data-idx="<?= $idx ?>">
            <div class="point-info">
                <div class="point-numbers">Заявка<?= $isMultiple ? 'и' : '' ?> #<?= $ids ?></div>
                <?php if ($isMultiple): ?>
                    <div class="warning-badge">⚠️ <?= $group['count'] ?> заявки в одном месте</div>
                <?php endif; ?>
                <div class="point-org"><?= htmlspecialchars(mb_substr($group['org'] ?? 'Не указан', 0, 60)) ?></div>
                <div class="point-address">📍 <?= htmlspecialchars(mb_substr($group['address'] ?? 'Адрес не указан', 0, 80)) ?></div>
            </div>
            <div class="point-weight"><?= number_format($weight, 0, ',', ' ') ?> кг</div>
        </div>
        <?php endforeach; ?>
        <div class="total-weight">📊 Общий вес: <?= number_format($totalWeight, 0, ',', ' ') ?> кг</div>
    </div>
    <div class="footer">🔒 Нажмите на маркер или пункт списка</div>
</div>

<script>
var map, placemarks = [];
var groupedData = <?= json_encode($groupedData) ?>;

function createMarkerSVG(weight, isMultiple) {
    var bgColor = isMultiple ? '#FFD700' : '#FFFFFF';
    var svg = '<svg width="50" height="45" viewBox="0 0 50 45" xmlns="http://www.w3.org/2000/svg">' +
        '<rect x="5" y="12" width="40" height="25" rx="6" ry="6" fill="' + bgColor + '" stroke="#000000" stroke-width="2"/>' +
        '<text x="25" y="30" font-family="Arial, sans-serif" font-size="11" fill="#000000" text-anchor="middle" font-weight="bold">' + weight + '</text>' +
        '<polygon points="20,37 25,44 30,37" fill="#000000"/>' +
        '</svg>';
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        return m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;');
    });
}

function createBalloonContent(group) {
    var totalWeightKg = Math.round(group.total_mass * 1000);
    var isMultiple = group.count > 1;
    var points = group.points;
    var pointsHtml = '';
    
    for (var i = 0; i < points.length; i++) {
        var p = points[i];
        var weightKg = Math.round(p.mass_netto * 1000);
        pointsHtml += '<div style="border-top: 1px solid #eee; padding: 6px 0;' + (i === 0 ? 'border:none;padding-top:0;' : '') + '">' +
            '<b>Заявка #' + p.zayavka_id + '</b> — ' + weightKg.toLocaleString() + ' кг<br>' +
            '<span style="font-size:11px;color:#555;">📦 ' + escapeHtml(p.zakaz_s_ot_status || 'Без статуса') + '</span>' +
            '</div>';
    }
    
    var idsStr = '';
    for (var i = 0; i < points.length; i++) {
        idsStr += (i > 0 ? ', #' : '#') + points[i].zayavka_id;
    }
    
    var warningHtml = isMultiple ? '<div style="background:#ffc107;padding:5px;border-radius:6px;margin-bottom:8px;font-size:12px;">⚠️ ' + group.count + ' заявки в одном месте</div>' : '';
    
    return '<div style="padding:10px;max-width:300px;font-family:-apple-system, BlinkMacSystemFont, sans-serif;">' +
        warningHtml +
        '<b>📍 Заявк' + (isMultiple ? 'и' : 'а') + ' ' + idsStr + '</b><br>' +
        '<b>Вес:</b> ' + totalWeightKg.toLocaleString() + ' кг<br>' +
        '<b>Грузоотправитель:</b><br>' + escapeHtml(group.org || 'Не указан') + '<br>' +
        '<b>Адрес:</b><br>' + escapeHtml(group.address || 'Не указан') + '<br>' +
        '<div style="margin-top:8px;"><b>Состав:</b></div>' +
        pointsHtml +
        '<div style="margin-top:8px;font-size:10px;color:#888;">🚚 Рейс #<?= $flightId ?></div>' +
        '</div>';
}

ymaps.ready(function() {
    map = new ymaps.Map('map', {
        center: [<?= $centerLat ?>, <?= $centerLng ?>],
        zoom: <?= $zoom ?>,
        controls: ['zoomControl', 'fullscreenControl']
    });
    
    if (window.innerWidth < 768) {
        map.controls.get('zoomControl').options.set('size', 'small');
    }
    
    placemarks = [];
    
    for (var i = 0; i < groupedData.length; i++) {
        var group = groupedData[i];
        var lat = parseFloat(group.mno_d);
        var lng = parseFloat(group.mno_sh);
        
        if (isNaN(lat) || isNaN(lng) || lat == 0 || lng == 0) {
            continue;
        }
        
        var weight = Math.round(group.total_mass * 1000);
        var isMultiple = group.count > 1;
        
        var mark = new ymaps.Placemark([lat, lng], {
            balloonContent: createBalloonContent(group),
            hintContent: isMultiple ? group.count + ' заявки, ' + weight + ' кг' : 'Заявка #' + group.points[0].zayavka_id + ', ' + weight + ' кг'
        }, {
            iconLayout: 'default#image',
            iconImageHref: createMarkerSVG(weight, isMultiple),
            iconImageSize: [50, 45],
            iconImageOffset: [-25, -45],
            hideIconOnBalloonOpen: false
        });
        
        // ПРАВИЛЬНЫЙ ОБРАБОТЧИК С ЗАМЫКАНИЕМ
        (function(marker) {
            marker.events.add('click', function() {
                marker.balloon.open();
            });
        })(mark);
        
        placemarks.push(mark);
        map.geoObjects.add(mark);
    }
});

// Клик по пункту списка
var pointItems = document.querySelectorAll('.point-item');
for (var i = 0; i < pointItems.length; i++) {
    (function(idx) {
        var el = pointItems[idx];
        var handler = function() {
            var lat = parseFloat(el.dataset.lat);
            var lng = parseFloat(el.dataset.lng);
            if (!isNaN(lat) && !isNaN(lng) && map && placemarks[idx]) {
                map.setCenter([lat, lng], 14);
                setTimeout(function() {
                    placemarks[idx].balloon.open();
                }, 50);
            }
        };
        el.addEventListener('click', handler);
        el.addEventListener('touchstart', handler);
    })(i);
}
</script>
</body>
</html>