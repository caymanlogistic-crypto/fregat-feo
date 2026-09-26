<?php
// tracker_map.php - Карта трекеров с автообновлением

error_reporting(E_ALL);
ini_set('display_errors', 1);

$apiToken = trim((string) (getenv('SLITEX_API_TOKEN') ?: ''));
$baseUrl = 'https://slitex.online';
$yandexApiKey = trim((string) (getenv('YANDEX_MAPS_API_KEY') ?: ''));

function fetchSlitexData(string $url, string $token): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["X-API-Token: {$token}"],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}

function utcToMoscow(string $utcTime): string {
    if (empty($utcTime)) return '';
    $dt = new DateTime($utcTime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Europe/Moscow'));
    return $dt->format('Y-m-d H:i:s');
}

function getTimeDiff(string $moscowTime): array {
    if (empty($moscowTime)) return ['text' => 'Нет данных', 'minutes' => 9999];
    $now = new DateTime('now', new DateTimeZone('Europe/Moscow'));
    $then = new DateTime($moscowTime, new DateTimeZone('Europe/Moscow'));
    $interval = $now->diff($then);
    $totalMin = ($interval->days * 1440) + ($interval->h * 60) + $interval->i;
    
    if ($interval->d > 0) $text = $interval->d . 'д ' . $interval->h . 'ч ' . $interval->i . 'м';
    elseif ($interval->h > 0) $text = $interval->h . 'ч ' . $interval->i . 'м';
    else $text = $interval->i . 'м';
    
    return ['text' => $text, 'minutes' => $totalMin];
}

function getShortName(string $fullName): string {
    if (preg_match('/\(([^)]+)\)/', $fullName, $matches)) {
        return $matches[1];
    }
    return $fullName;
}

$devices = fetchSlitexData("{$baseUrl}/api/external/devices", $apiToken);
$adminFields = fetchSlitexData("{$baseUrl}/api/external/devices/admin-fields", $apiToken);

$merged = [];
foreach ($devices as $d) { $merged[$d['uniqueid']] = $d; }
foreach ($adminFields as $f) {
    $uid = $f['uniqueid'];
    if (isset($merged[$uid])) $merged[$uid]['admin_fields'] = $f['fields'] ?? [];
    else $merged[$uid] = ['uniqueid' => $uid, 'name' => $f['name'] ?? null, 'admin_fields' => $f['fields'] ?? []];
}

$trackers = [];
foreach ($merged as $t) {
    $name = $t['name'] ?? '';
    if (preg_match('/^\d{6}$/', $name)) continue;
    if (empty($t['lat']) || empty($t['lon'])) continue;
    
    $lastupdateMSK = utcToMoscow($t['lastupdate'] ?? '');
    $diff = getTimeDiff($lastupdateMSK);
    
    $trackers[] = [
        'uniqueid' => $t['uniqueid'],
        'name' => $name,
        'short_name' => getShortName($name),
        'lat' => (float)$t['lat'],
        'lon' => (float)$t['lon'],
        'lastupdate_msk' => $lastupdateMSK,
        'time_diff_text' => $diff['text'],
        'time_diff_minutes' => $diff['minutes'],
        'status' => $t['status'] ?? 'unknown'
    ];
}

usort($trackers, fn($a, $b) => $a['time_diff_minutes'] - $b['time_diff_minutes']);
$trackersJson = json_encode($trackers);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Карта трекеров</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 100%; height: 100%; overflow: hidden; }
        #map { width: 100%; height: 100%; }
        
        /* Прозрачная кнопка обновления в правом нижнем углу */
        .refresh-btn {
            position: absolute;
            bottom: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 40px;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            transition: all 0.2s ease;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .refresh-btn:hover {
            background: rgba(0, 0, 0, 0.8);
            border-color: rgba(255, 255, 255, 0.4);
            transform: scale(1.02);
        }
        .refresh-btn:active {
            transform: scale(0.98);
        }
    </style>
    <script src="https://api-maps.yandex.ru/2.1/?apikey=<?= $yandexApiKey ?>&lang=ru_RU"></script>
</head>
<body>
    <div id="map"></div>
    <button class="refresh-btn" id="refreshBtn">🔄 Обновить</button>

    <script>
        let map;
        let placemarks = [];
        let updateInterval;
        let isUpdating = false;
        
        function getPreset(minutes) {
            if (minutes < 10) return 'islands#blueStretchyIcon';
            if (minutes < 60) return 'islands#greenStretchyIcon';
            if (minutes < 180) return 'islands#orangeStretchyIcon';
            return 'islands#redStretchyIcon';
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        function updateMarkers(newTrackers) {
            placemarks.forEach(pm => map.geoObjects.remove(pm));
            placemarks = [];
            
            newTrackers.forEach((tracker) => {
                const preset = getPreset(tracker.time_diff_minutes);
                
                const placemark = new ymaps.Placemark(
                    [tracker.lat, tracker.lon],
                    {
                        iconContent: tracker.short_name,
                        hintContent: `${tracker.name} | ${tracker.time_diff_text} назад`,
                        balloonContent: `<div style="padding:12px">
                            <b>🚛 ${escapeHtml(tracker.name)}</b><br>
                            🆔 ${tracker.uniqueid}<br>
                            ⏱️ ${tracker.time_diff_text} назад<br>
                            📍 ${tracker.lat.toFixed(4)}, ${tracker.lon.toFixed(4)}
                        </div>`
                    },
                    {
                        preset: preset,
                        iconImageScale: 0.7,
                        iconContentOffset: [0, -7]
                    }
                );
                
                placemarks.push(placemark);
                map.geoObjects.add(placemark);
            });
        }
        
        async function fetchTrackers() {
            if (isUpdating) return;
            isUpdating = true;
            
            const btn = document.getElementById('refreshBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '⏳ ...';
            btn.disabled = true;
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const html = await response.text();
                const match = html.match(/const initialTrackers = (\[[\s\S]*?\]);/);
                if (match) {
                    const newTrackers = JSON.parse(match[1]);
                    updateMarkers(newTrackers);
                }
            } catch(e) {
                console.error('Ошибка:', e);
            } finally {
                isUpdating = false;
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
        
        function initMap(initialTrackers) {
            let center = [55.751574, 37.573856];
            if (initialTrackers.length) {
                let sumLat = 0, sumLon = 0;
                initialTrackers.forEach(t => { sumLat += t.lat; sumLon += t.lon; });
                center = [sumLat / initialTrackers.length, sumLon / initialTrackers.length];
            }
            
            map = new ymaps.Map('map', { 
                center: center, 
                zoom: 10, 
                controls: ['zoomControl', 'fullscreenControl', 'geolocationControl']
            });
            
            initialTrackers.forEach((tracker) => {
                const preset = getPreset(tracker.time_diff_minutes);
                const placemark = new ymaps.Placemark(
                    [tracker.lat, tracker.lon],
                    {
                        iconContent: tracker.short_name,
                        hintContent: `${tracker.name} | ${tracker.time_diff_text} назад`,
                        balloonContent: `<div style="padding:12px">
                            <b>🚛 ${escapeHtml(tracker.name)}</b><br>
                            🆔 ${tracker.uniqueid}<br>
                            ⏱️ ${tracker.time_diff_text} назад<br>
                            📍 ${tracker.lat.toFixed(4)}, ${tracker.lon.toFixed(4)}
                        </div>`
                    },
                    {
                        preset: preset,
                        iconImageScale: 0.7,
                        iconContentOffset: [0, -7]
                    }
                );
                placemarks.push(placemark);
                map.geoObjects.add(placemark);
            });
            
            if (placemarks.length) {
                try {
                    const bounds = map.geoObjects.getBounds();
                    if (bounds) map.setBounds(bounds, { zoomMargin: 50 });
                } catch(e) {}
            }
        }
        
        const initialTrackers = <?= $trackersJson ?>;
        
        ymaps.ready(() => {
            initMap(initialTrackers);
            updateInterval = setInterval(() => fetchTrackers(), 60000);
        });
        
        window.addEventListener('beforeunload', () => {
            if (updateInterval) clearInterval(updateInterval);
        });
        
        document.getElementById('refreshBtn').addEventListener('click', fetchTrackers);
    </script>
</body>
</html>