<?php
$cssPath = __DIR__ . '/../assets/map.css';
$cssVersion = @filemtime($cssPath);
if ($cssVersion === false) {
    $cssVersion = time();
}
?>
<link rel="stylesheet" href="stylemap.css">
<link rel="stylesheet" href="map_files/assets/map.css?v=<?= (int)$cssVersion ?>">
<script src="https://api-maps.yandex.ru/2.1/?apikey=<?= $apiKeyYandex ?>&lang=ru_RU" type="text/javascript"></script>
