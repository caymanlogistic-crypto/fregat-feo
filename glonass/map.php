<?php
/**
 * map.php — Интерактивная карта трекеров (Яндекс.Карты)
 * Открывайте в браузере: https://ваш-сервер/map.php
 */

$dataFile = __DIR__ . '/trackers_data.json';
$trackers = [];
$lastUpdate = null;
$serverUrl = '';

if (file_exists($dataFile)) {
    $json = json_decode(file_get_contents($dataFile), true);
    $trackers = $json['data'] ?? [];
    $lastUpdate = $json['generated_at'] ?? null;
    $serverUrl = $json['server'] ?? '';
}

function formatTimeForJS($timeStr) {
    if (!$timeStr) return null;
    $ts = strtotime($timeStr);
    return $ts ? date('c', $ts) : null;
}

// Подготовка данных для JS
$mapData = [];
foreach ($trackers as $imei => $t) {
    if (!$t['lat'] || !$t['lon']) continue;
    
    $lastTime = $t['time'] ? strtotime($t['time']) : 0;
    $minutesAgo = $lastTime ? floor((time() - $lastTime) / 60) : 999;
    
    if ($minutesAgo <= 15 && $t['valid']) {
        $status = 'online'; $color = '#22c55e'; $icon = '🟢';
    } elseif ($minutesAgo <= 60) {
        $status = 'recent'; $color = '#f59e0b'; $icon = '🟡';
    } else {
        $status = 'offline'; $color = '#ef4444'; $icon = '🔴';
    }
    
    $mapData[] = [
        'lat' => (float)$t['lat'], 'lon' => (float)$t['lon'],
        'name' => htmlspecialchars($t['name']), 'imei' => $imei,
        'speed' => $t['speed'], 'course' => $t['course'],
        'address' => htmlspecialchars($t['address']), 'time' => $t['time'],
        'timeIso' => formatTimeForJS($t['time']), 'minutesAgo' => $minutesAgo,
        'status' => $status, 'color' => $color, 'icon' => $icon,
        'satellites' => $t['satellites'], 'battery' => $t['battery'],
        'gsm_level' => $t['gsm_level'], 'valid' => !empty($t['valid']),
    ];
}

$center = !empty($mapData) ? ['lat' => $mapData[0]['lat'], 'lon' => $mapData[0]['lon']] : ['lat' => 55.751244, 'lon' => 37.618423];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚛 Трекеры ГЛОНАССсофт — Карта</title>
    <script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU" type="text/javascript"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1e293b; }
        .header { background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
        .header h1 { font-size: 1.25rem; font-weight: 600; }
        .header-info { display: flex; gap: 1.5rem; font-size: 0.875rem; opacity: 0.9; }
        .controls { padding: 1rem 2rem; background: white; border-bottom: 1px solid #e2e8f0; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
        .filter-btn { padding: 0.5rem 1rem; border: 2px solid #cbd5e1; background: white; border-radius: 9999px; cursor: pointer; font-size: 0.875rem; transition: all 0.2s; display: flex; align-items: center; gap: 0.5rem; }
        .filter-btn:hover, .filter-btn.active { border-color: #3b82f6; background: #eff6ff; color: #1d4ed8; }
        .filter-btn.online { border-color: #22c55e; }
        .filter-btn.recent { border-color: #f59e0b; }
        .filter-btn.offline { border-color: #ef4444; }
        .filter-btn.all.active { background: #1e40af; color: white; border-color: #1e40af; }
        .refresh-btn { margin-left: auto; padding: 0.5rem 1.25rem; background: #3b82f6; color: white; border: none; border-radius: 0.5rem; cursor: pointer; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; }
        .refresh-btn:hover { background: #2563eb; }
        .refresh-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        #map { width: 100%; height: calc(100vh - 140px); min-height: 500px; }
        .balloon { min-width: 280px; max-width: 320px; font-size: 0.875rem; }
        .balloon-header { display: flex; align-items: center; gap: 0.75rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e2e8f0; margin-bottom: 0.75rem; }
        .balloon-icon { font-size: 1.5rem; }
        .balloon-title { font-weight: 600; color: #1e293b; }
        .balloon-status { font-size: 0.75rem; padding: 0.25rem 0.5rem; border-radius: 9999px; font-weight: 500; }
        .balloon-status.online { background: #dcfce7; color: #166534; }
        .balloon-status.recent { background: #fef3c7; color: #92400e; }
        .balloon-status.offline { background: #fee2e2; color: #991b1b; }
        .balloon-row { display: flex; justify-content: space-between; padding: 0.25rem 0; color: #475569; }
        .balloon-row strong { color: #1e293b; }
        .balloon-address { margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #e2e8f0; color: #475569; font-size: 0.8125rem; line-height: 1.4; }
        .balloon-links { margin-top: 0.75rem; display: flex; gap: 0.5rem; }
        .balloon-link { font-size: 0.75rem; color: #3b82f6; text-decoration: none; padding: 0.25rem 0.5rem; background: #eff6ff; border-radius: 0.25rem; }
        .balloon-link:hover { background: #dbeafe; }
        .legend { position: absolute; bottom: 20px; right: 20px; background: white; padding: 0.75rem 1rem; border-radius: 0.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-size: 0.75rem; z-index: 1000; }
        .legend-item { display: flex; align-items: center; gap: 0.5rem; margin: 0.25rem 0; }
        .legend-dot { width: 12px; height: 12px; border-radius: 50%; }
        .loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(255,255,255,0.95); padding: 1rem 2rem; border-radius: 0.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 0.75rem; z-index: 2000; }
        .spinner { width: 20px; height: 20px; border: 3px solid #e2e8f0; border-top-color: #3b82f6; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (max-width: 768px) { .header { flex-direction: column; align-items: flex-start; } .header-info { flex-wrap: wrap; } .controls { flex-wrap: wrap; } .refresh-btn { margin-left: 0; width: 100%; justify-content: center; } #map { height: calc(100vh - 180px); } }
    </style>
</head>
<body>
    <div class="header">
        <h1>🚛 Трекеры ГЛОНАССсофт</h1>
        <div class="header-info">
            <span>📊 Трекеров: <strong><?= count($mapData) ?></strong></span>
            <span>🕐 Обновлено: <strong id="lastUpdate"><?= $lastUpdate ? date('H:i:s', strtotime($lastUpdate)) : '—' ?></strong></span>
            <span>🌐 Сервер: <strong><?= parse_url($serverUrl, PHP_URL_HOST) ?: '—' ?></strong></span>
        </div>
    </div>
    
    <div class="controls">
        <button class="filter-btn all active" data-filter="all">📋 Все (<?= count($mapData) ?>)</button>
        <button class="filter-btn online" data-filter="online">🟢 Онлайн (<?= count(array_filter($mapData, fn($t) => $t['status'] === 'online')) ?>)</button>
        <button class="filter-btn recent" data-filter="recent">🟡 Недавно (<?= count(array_filter($mapData, fn($t) => $t['status'] === 'recent')) ?>)</button>
        <button class="filter-btn offline" data-filter="offline">🔴 Офлайн (<?= count(array_filter($mapData, fn($t) => $t['status'] === 'offline')) ?>)</button>
        <button class="refresh-btn" id="refreshBtn" onclick="refreshData()">🔄 Обновить</button>
    </div>
    
    <div id="map"></div>
    
    <div class="legend">
        <div style="font-weight: 600; margin-bottom: 0.5rem;">Статус связи</div>
        <div class="legend-item"><span class="legend-dot" style="background:#22c55e"></span> 🟢 Онлайн (&lt;15 мин)</div>
        <div class="legend-item"><span class="legend-dot" style="background:#f59e0b"></span> 🟡 Недавно (15-60 мин)</div>
        <div class="legend-item"><span class="legend-dot" style="background:#ef4444"></span> 🔴 Офлайн (&gt;60 мин)</div>
    </div>
    
    <div class="loading" id="loading" style="display:none;">
        <div class="spinner"></div>
        <span>Обновление данных...</span>
    </div>

    <script>
        const trackers = <?= json_encode($mapData, JSON_UNESCAPED_UNICODE) ?>;
        const center = <?= json_encode($center) ?>;
        let myMap, objectManager, currentFilter = 'all';
        
        ymaps.ready(init);
        
        function init() {
            myMap = new ymaps.Map('map', {
                center: [center.lat, center.lon],
                zoom: trackers.length > 1 ? 9 : 12,
                controls: ['zoomControl', 'fullscreenControl', 'typeSelector']
            });
            objectManager = new ymaps.ObjectManager({ clusterize: true, gridSize: 32 });
            myMap.geoObjects.add(objectManager);
            addMarkers(trackers);
            
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    currentFilter = btn.dataset.filter;
                    applyFilter();
                });
            });
            setInterval(refreshData, 60000);
        }
        
        function addMarkers(data) {
            const features = data.map((t, index) => ({
                type: 'Feature', id: index,
                geometry: { type: 'Point', coordinates: [t.lat, t.lon] },
                properties: {
                    balloonContentHeader: `
                        <div class="balloon">
                            <div class="balloon-header">
                                <span class="balloon-icon">${t.icon}</span>
                                <div>
                                    <div class="balloon-title">${t.name}</div>
                                    <div style="font-size:0.75rem;color:#64748b">IMEI: ${t.imei}</div>
                                </div>
                                <span class="balloon-status ${t.status}">${getStatusText(t.status)}</span>
                            </div>
                            <div class="balloon-row"><strong>🕐 Последний раз:</strong> ${formatTimeAgo(t.minutesAgo, t.time)}</div>
                            <div class="balloon-row"><strong>🚀 Скорость:</strong> ${t.speed !== null ? t.speed + ' км/ч' : '—'}</div>
                            <div class="balloon-row"><strong>🧭 Курс:</strong> ${t.course !== null ? t.course + '°' : '—'}</div>
                            <div class="balloon-row"><strong>🛰️ Спутники:</strong> ${t.satellites ?? '—'}</div>
                            ${t.battery !== null ? `<div class="balloon-row"><strong>🔋 Батарея:</strong> ${t.battery}%</div>` : ''}
                            ${t.gsm_level !== null ? `<div class="balloon-row"><strong>📶 GSM:</strong> ${t.gsm_level} dBm</div>` : ''}
                            <div class="balloon-address">📍 ${t.address}</div>
                            <div class="balloon-links">
                                <a class="balloon-link" href="https://www.google.com/maps?q=${t.lat},${t.lon}" target="_blank">🗺️ Google</a>
                                <a class="balloon-link" href="https://yandex.ru/maps/?pt=${t.lon},${t.lat}&z=17&l=map" target="_blank">🗺️ Яндекс</a>
                                <a class="balloon-link" href="https://2gis.ru/geo/${t.lon},${t.lat}" target="_blank">🗺️ 2GIS</a>
                            </div>
                        </div>
                    `,
                    hintContent: `${t.name}\n${t.address}\n🕐 ${formatTimeAgo(t.minutesAgo, t.time)}`,
                    status: t.status,
                },
                options: {
                    preset: 'islands#circleIcon',
                    iconColor: t.color,
                    iconCaption: t.name.length > 20 ? t.name.substring(0, 17) + '...' : t.name,
                }
            }));
            objectManager.add({ type: 'FeatureCollection', features: features });
        }
        
        function applyFilter() {
            objectManager.setFilter(feature => currentFilter === 'all' || feature.properties.status === currentFilter);
        }
        
        function formatTimeAgo(minutes, timeStr) {
            if (!timeStr) return 'нет данных';
            const date = new Date(timeStr);
            const local = date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            if (minutes < 1) return 'только что';
            if (minutes < 60) return `${minutes} мин. назад (${local})`;
            const hours = Math.floor(minutes / 60);
            if (hours < 24) return `${hours} ч. назад (${local})`;
            const days = Math.floor(hours / 24);
            return `${days} дн. назад (${local})`;
        }
        
        function getStatusText(status) {
            return { 'online': '● онлайн', 'recent': '● недавно', 'offline': '● офлайн' }[status] || status;
        }
        
        async function refreshData() {
            const btn = document.getElementById('refreshBtn');
            const loading = document.getElementById('loading');
            btn.disabled = true;
            loading.style.display = 'flex';
            
            try {
                const response = await fetch('trackers_data.json?' + Date.now());
                const json = await response.json();
                trackers.length = 0;
                const newMapData = [];
                
                for (const [imei, t] of Object.entries(json.data || {})) {
                    if (!t.lat || !t.lon) continue;
                    const lastTime = t.time ? new Date(t.time).getTime() : 0;
                    const minutesAgo = lastTime ? Math.floor((Date.now() - lastTime) / 60000) : 999;
                    
                    let status, color, icon;
                    if (minutesAgo <= 15 && t.valid) { status = 'online'; color = '#22c55e'; icon = '🟢'; }
                    else if (minutesAgo <= 60) { status = 'recent'; color = '#f59e0b'; icon = '🟡'; }
                    else { status = 'offline'; color = '#ef4444'; icon = '🔴'; }
                    
                    newMapData.push({ lat: parseFloat(t.lat), lon: parseFloat(t.lon), name: t.name, imei, speed: t.speed, course: t.course, address: t.address, time: t.time, minutesAgo, status, color, icon, satellites: t.satellites, battery: t.battery, gsm_level: t.gsm_level, valid: t.valid });
                }
                objectManager.removeAll();
                addMarkers(newMapData);
                updateFilterCounts(newMapData);
                const updateEl = document.getElementById('lastUpdate');
                if (json.generated_at) { const d = new Date(json.generated_at); updateEl.textContent = d.toLocaleTimeString('ru-RU'); }
            } catch (e) { console.error('❌ Ошибка обновления:', e); }
            finally { btn.disabled = false; loading.style.display = 'none'; }
        }
        
        function updateFilterCounts(data) {
            const counts = { all: data.length, online: 0, recent: 0, offline: 0 };
            data.forEach(t => counts[t.status]++);
            document.querySelectorAll('.filter-btn').forEach(btn => {
                const filter = btn.dataset.filter;
                const text = btn.childNodes[0]?.nodeValue?.trim() || '';
                const emoji = text.match(/^[📋🟢🟡🔴]/)?.[0] || '';
                const names = { all: 'Все', online: 'Онлайн', recent: 'Недавно', offline: 'Офлайн' };
                btn.firstChild.nodeValue = `${emoji} ${names[filter]} (${counts[filter]}) `;
            });
        }
    </script>
</body>
</html>