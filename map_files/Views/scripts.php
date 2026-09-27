<?php
$entryPoint = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
$displayMassField = $entryPoint === 'map2.php' ? 'mass_brutto' : 'mass_netto';
if ($displayMassField === 'mass_brutto' && isset($routeInfoMap) && is_array($routeInfoMap)) {
    $applyGrossMassToRouteCard = static function (array $card) use ($routeInfoMap): array {
        $sumTons = 0.0;
        foreach (explode(',', (string)($card['zayavki_ids'] ?? '')) as $zid) {
            $zid = trim($zid);
            if ($zid !== '' && isset($routeInfoMap[$zid]) && is_array($routeInfoMap[$zid])) {
                $sumTons += (float)($routeInfoMap[$zid]['mass_brutto'] ?? 0);
            }
        }
        $card['total_kg'] = (int)round($sumTons * 1000);

        $hasExplicitTitle = trim((string)($card['name'] ?? '')) !== ''
            || trim((string)($card['comment'] ?? '')) !== ''
            || trim((string)($card['route_name'] ?? '')) !== ''
            || trim((string)($card['direction'] ?? '')) !== '';
        if (!$hasExplicitTitle && $sumTons > 0) {
            $title = trim((string)($card['route_title'] ?? $card['title'] ?? ''));
            $prefix = trim((string)preg_replace('/\s*\d+(?:[.,]\d+)?\s*тн$/ui', '', $title));
            $tonsRounded = round($sumTons, 1);
            $tonsText = rtrim(rtrim(number_format($tonsRounded, 1, '.', ''), '0'), '.') . 'тн';
            $title = trim($prefix . ' ' . $tonsText);
            $card['route_title'] = $title;
            $card['title'] = $title;
        }
        return $card;
    };

    foreach ($routeCardsMeta as $routeId => $card) {
        if (is_array($card)) {
            $routeCardsMeta[$routeId] = $applyGrossMassToRouteCard($card);
        }
    }
    foreach ($foundRoutesData as $index => $card) {
        if (is_array($card)) {
            $foundRoutesData[$index] = $applyGrossMassToRouteCard($card);
        }
    }
    $routeCardsMetaJson = json_encode($routeCardsMeta, JSON_UNESCAPED_UNICODE) ?: '{}';
    $foundRoutesJson = json_encode($foundRoutesData, JSON_UNESCAPED_UNICODE) ?: '[]';
}

$jsPath = __DIR__ . '/../assets/maptest.js';
$jsVersion = @filemtime($jsPath);
if ($jsVersion === false) {
    $jsVersion = time();
}
$requestMarkersV2Path = __DIR__ . '/../assets/request_markers_v2.js';
$requestMarkersV2Version = @filemtime($requestMarkersV2Path);
if ($requestMarkersV2Version === false) {
    $requestMarkersV2Version = time();
}
$warehouseMarkersV2Path = __DIR__ . '/../assets/warehouse_markers_v2.js';
$warehouseMarkersV2Version = @filemtime($warehouseMarkersV2Path);
if ($warehouseMarkersV2Version === false) {
    $warehouseMarkersV2Version = time();
}
$layerFiltersV3Path = __DIR__ . '/../assets/layer_filters_v3.js';
$layerFiltersV3Version = @filemtime($layerFiltersV3Path);
if ($layerFiltersV3Version === false) {
    $layerFiltersV3Version = time();
}
$transportControlsV2Path = __DIR__ . '/../assets/transport_controls_v2.js';
$transportControlsV2Version = @filemtime($transportControlsV2Path);
if ($transportControlsV2Version === false) {
    $transportControlsV2Version = time();
}
$transportMarkersV5Path = __DIR__ . '/../assets/transport_markers_v5.js';
$transportMarkersV5Version = @filemtime($transportMarkersV5Path);
if ($transportMarkersV5Version === false) {
    $transportMarkersV5Version = time();
}
$utilizersJsPath = __DIR__ . '/../assets/utilizers.js';
$utilizersJsVersion = @filemtime($utilizersJsPath);
if ($utilizersJsVersion === false) {
    $utilizersJsVersion = time();
}
$utilizersBuildVersion = '20260806_2043';
?>
<script>
window.MAP_BOOTSTRAP = {
    initialTrackers: <?= $trackersJson ?>,
    allTrackers: <?= $allTrackersJson ?>,
    routeCardsMeta: <?= $routeCardsMetaJson ?>,
    foundRoutesData: <?= $foundRoutesJson ?>,
    driversForSelect: <?= $driversForSelectJson ?>,
    routeCardsBuildError: <?= json_encode($routeCardsBuildError, JSON_UNESCAPED_UNICODE) ?>,
    statusNames: <?= json_encode($statusNames, JSON_UNESCAPED_UNICODE) ?>,
    uiTexts: <?= json_encode($uiTexts ?? [], JSON_UNESCAPED_UNICODE) ?>,
    uiPopupTemplates: <?= json_encode($uiPopupTemplates ?? [], JSON_UNESCAPED_UNICODE) ?>,
    massBasis: <?= json_encode($displayMassField) ?>,
    groupsData: <?= json_encode($processedGroups, JSON_UNESCAPED_UNICODE) ?>,
    flightStatusList: <?= json_encode($flightStatusList, JSON_UNESCAPED_UNICODE) ?>,
    customLayers: <?= json_encode($customLayers, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="map_files/assets/maptest.js?v=<?= (int)$jsVersion ?>"></script>
<script src="map_files/assets/request_markers_v2.js?v=<?= (int)$requestMarkersV2Version ?>"></script>
<script src="map_files/assets/warehouse_markers_v2.js?v=<?= (int)$warehouseMarkersV2Version ?>"></script>
<script src="map_files/assets/layer_filters_v3.js?v=<?= (int)$layerFiltersV3Version ?>"></script>
<script src="map_files/assets/transport_controls_v2.js?v=<?= (int)$transportControlsV2Version ?>"></script>
<script src="map_files/assets/transport_markers_v5.js?v=<?= (int)$transportMarkersV5Version ?>"></script>
<script src="map_files/assets/utilizers.js?v=<?= rawurlencode($utilizersBuildVersion) ?>_<?= (int)$utilizersJsVersion ?>"></script>
