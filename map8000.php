<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['map8000_csrf'])) {
    $_SESSION['map8000_csrf'] = bin2hex(random_bytes(24));
}

$apiKeyYandex = '23e55c05-eb08-48c8-8387-7def1ba87cf5';

$cssVersion = (int)(@filemtime(__DIR__ . '/map8000_files/assets/map8000.css') ?: time());
$jsVersion = (int)(@filemtime(__DIR__ . '/map8000_files/assets/map8000.js') ?: time());
?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Планирование рейсов — Excel</title>
    <link rel="stylesheet" href="map8000_files/assets/map8000.css?v=<?= $cssVersion ?>">
    <script src="https://api-maps.yandex.ru/2.1/?apikey=<?= rawurlencode($apiKeyYandex) ?>&lang=ru_RU"></script>
</head>
<body>
<header class="topbar">
    <div>
        <h1>Планирование рейсов</h1>
        <div id="source-status" class="source-status">Загрузка Excel…</div>
    </div>
    <div class="topbar-actions">
        <button id="new-route-btn" class="btn btn-secondary" type="button">Новый рейс</button>
        <a class="btn btn-success" href="map8000_files/api.php?action=export">Выгрузить все рейсы в Excel</a>
    </div>
</header>

<?php if ($apiKeyYandex === ''): ?>
    <div class="global-warning">Ключ Яндекс.Карт не настроен. Заявки и рейсы доступны, но карта и дорожное расстояние работать не будут.</div>
<?php endif; ?>

<main class="workspace">
    <section class="map-panel">
        <div id="map" class="map"></div>
        <div class="map-toolbar">
            <button id="rectangle-select-btn" class="btn btn-primary" type="button">Выделить рамкой</button>
            <button id="clear-selection-btn" class="btn btn-secondary" type="button">Очистить выбор</button>
        </div>
        <div id="selection-overlay" class="selection-overlay">
            <div id="selection-box" class="selection-box hidden"></div>
        </div>
        <div id="route-color-legend" class="route-color-legend"></div>
        <div id="map-message" class="map-message">Загрузка карты…</div>
    </section>

    <aside class="right-panel panel">
        <section>
            <div class="section-title-row">
                <h2 id="route-form-title">Новый рейс</h2>
                <span id="editing-badge" class="badge hidden"></span>
            </div>
            <label>Название рейса<input id="route-name" class="control" maxlength="200"></label>
            <div class="two-columns">
                <label>Дата<input id="route-date" class="control" type="date"></label>
                <label>Конечный город
                    <select id="destination" class="control">
                        <option value="novosibirsk">Новосибирск</option>
                        <option value="krasnoyarsk">Красноярск</option>
                        <option value="irkutsk">Иркутск</option>
                        <option value="ekaterinburg">Екатеринбург</option>
                    </select>
                </label>
            </div>
            <div class="two-columns">
                <label>Транспорт<input id="route-vehicle" class="control" maxlength="200"></label>
                <label>Водитель<input id="route-driver" class="control" maxlength="200"></label>
            </div>
            <label>Примечание<textarea id="route-notes" class="control" rows="2" maxlength="4000"></textarea></label>

            <div id="selection-summary" class="selection-summary">Заявки не выбраны</div>
            <div class="route-actions">
                <button id="optimize-btn" class="btn btn-primary" type="button">Рассчитать расстояние</button>
                <button id="save-route-btn" class="btn btn-success" type="button">Сохранить рейс</button>
            </div>
            <div class="muted distance-hint">Расстояние не обязательно: рейс можно сохранить сразу.</div>
            <div id="route-metrics" class="route-metrics"></div>
        </section>

        <section class="saved-routes-section">
            <h2>Сохранённые рейсы</h2>
            <div id="routes-list" class="routes-list"></div>
        </section>
    </aside>
</main>

<div id="edit-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
    <div class="modal-card">
        <div class="section-title-row">
            <h2 id="edit-modal-title">Редактирование заявки</h2>
            <button id="close-edit-modal" class="icon-btn" type="button" aria-label="Закрыть">×</button>
        </div>
        <div class="modal-grid">
            <label>ID заявки<input id="edit-request-id" class="control" disabled></label>
            <label>Вес, т<input id="edit-weight" class="control" type="number" min="0" step="0.001"></label>
            <label>Широта<input id="edit-latitude" class="control" type="number" min="-90" max="90" step="0.000001"></label>
            <label>Долгота<input id="edit-longitude" class="control" type="number" min="-180" max="180" step="0.000001"></label>
        </div>
        <div id="all-fields-editor" class="all-fields-editor"></div>
        <div class="modal-actions">
            <button id="cancel-edit-request" class="btn btn-secondary" type="button">Отмена</button>
            <button id="save-edit-request" class="btn btn-success" type="button">Сохранить изменения</button>
        </div>
    </div>
</div>

<div id="toast" class="toast hidden"></div>

<script>
window.MAP8000_BOOTSTRAP = <?= json_encode([
    'csrf' => $_SESSION['map8000_csrf'],
    'api' => 'map8000_files/api.php',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="map8000_files/assets/map8000.js?v=<?= $jsVersion ?>"></script>
</body>
</html>
