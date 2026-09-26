<?php
$apiKeyYandex = trim((string) (getenv('YANDEX_MAPS_API_KEY') ?: ''));

// Пример входных данных - координаты точек (широта, долгота)
// Можно передать через GET/POST или задать здесь
$points = [
    ['lat' => 55.751244, 'lon' => 37.618423], // Москва, Красная площадь
    ['lat' => 55.758538, 'lon' => 37.617635], // Москва, Театральная площадь
    ['lat' => 55.771326, 'lon' => 37.608956], // Москва, Белорусский вокзал
];

// Если есть передача через POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['points'])) {
    $points = json_decode($_POST['points'], true);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Маршрут на Яндекс Картах</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            font-family: Arial, sans-serif;
        }
        #map {
            width: 100%;
            height: 600px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .info {
            background: #f5f5f5;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .info h2 {
            margin-top: 0;
            color: #333;
        }
        .route-info {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .route-item {
            background: white;
            padding: 10px 20px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .route-item strong {
            color: #007bff;
            font-size: 1.2em;
        }
        .error {
            color: #dc3545;
            background: #f8d7da;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
    </style>
</head>
<body>

<div class="info">
    <h2>📍 Информация о маршруте</h2>
    <div class="route-info">
        <div class="route-item">
            Точек в маршруте: <strong id="pointsCount"><?= count($points) ?></strong>
        </div>
        <div class="route-item">
            Расстояние: <strong id="distance">Загрузка...</strong>
        </div>
        <div class="route-item">
            Время в пути: <strong id="duration">Загрузка...</strong>
        </div>
    </div>
</div>

<div id="map"></div>

<script src="https://api-maps.yandex.ru/2.1/?apikey=<?= $apiKeyYandex ?>&lang=ru_RU" type="text/javascript"></script>

<script>
    // Точки маршрута из PHP
    const routePoints = <?= json_encode($points) ?>;
    
    ymaps.ready(init);
    
    function init() {
        // Создание карты
        const myMap = new ymaps.Map("map", {
            center: [routePoints[0].lat, routePoints[0].lon],
            zoom: 10,
            controls: ['zoomControl', 'fullscreenControl', 'trafficControl']
        });
        
        // Добавление меток точек
        routePoints.forEach((point, index) => {
            const placemark = new ymaps.Placemark(
                [point.lat, point.lon],
                {
                    hintContent: `Точка ${index + 1}`,
                    balloonContent: `Точка маршрута #${index + 1}`
                },
                {
                    preset: index === 0 ? 'islands#greenCircleIcon' : 
                            index === routePoints.length - 1 ? 'islands#redCircleIcon' : 
                            'islands#blueCircleIcon'
                }
            );
            myMap.geoObjects.add(placemark);
        });
        
        // Построение маршрута
        const multiRoute = new ymaps.multiRouter.MultiRoute({
            referencePoints: routePoints.map(p => [p.lat, p.lon]),
            routingMode: 'auto', // auto, pedestrian, bicycle
            avoidTrafficJams: true // избегать пробок
        }, {
            boundsAutoApply: true,
            routeActiveStrokeWidth: 5,
            routeActiveStrokeColor: "#007bff"
        });
        
        myMap.geoObjects.add(multiRoute);
        
        // Получение информации о маршруте
        multiRoute.model.events.add('requestsuccess', function() {
            const routes = multiRoute.getRoutes();
            const route = routes.get(0);
            
            if (route) {
                const distance = route.properties.get('distance');
                const duration = route.properties.get('duration');
                
                // Форматирование расстояния
                let distanceText;
                if (distance.value >= 1000) {
                    distanceText = (distance.value / 1000).toFixed(2) + ' км';
                } else {
                    distanceText = distance.value + ' м';
                }
                
                // Форматирование времени
                const hours = Math.floor(duration.value / 3600);
                const minutes = Math.floor((duration.value % 3600) / 60);
                let durationText = '';
                if (hours > 0) durationText += hours + ' ч ';
                durationText += minutes + ' мин';
                
                document.getElementById('distance').textContent = distanceText;
                document.getElementById('duration').textContent = durationText;
                
                // Вывод деталей в консоль
                console.log('Маршрут построен:', {
                    distance: distance.text,
                    duration: duration.text,
                    bounds: route.properties.get('bounds')
                });
            }
        });
        
        multiRoute.model.events.add('requestfail', function(e) {
            console.error('Ошибка построения маршрута:', e);
            alert('Ошибка построения маршрута. Проверьте координаты точек.');
        });
    }
</script>

</body>
</html>