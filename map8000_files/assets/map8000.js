'use strict';

const bootstrap = window.MAP8000_BOOTSTRAP || {};
const apiUrl = bootstrap.api || 'map8000_files/api.php';
const csrfToken = bootstrap.csrf || '';

const destinationCatalog = {
    novosibirsk: {label: 'Новосибирск', coords: [55.030204, 82.920430]},
    krasnoyarsk: {label: 'Красноярск', coords: [56.015283, 92.893248]},
    irkutsk: {label: 'Иркутск', coords: [52.286351, 104.280655]},
    ekaterinburg: {label: 'Екатеринбург', coords: [56.838011, 60.597465]}
};

let requests = [];
let requestById = new Map();
let selected = [];
let routes = [];
let editingRouteId = null;
let editingRequestId = null;
let routeDirty = true;
let currentMetrics = null;
let map = null;
let objectManager = null;
let routeObjects = [];
let filterTimer = null;
let currentFiltered = [];
let rectangleMode = false;
let rectangleStart = null;

document.addEventListener('DOMContentLoaded', () => {
    bindUi();
    loadInitialData();
    if (typeof ymaps !== 'undefined') {
        ymaps.ready(initMap);
    } else {
        setMapMessage('Не удалось загрузить Яндекс.Карты.');
    }
});

function bindUi() {
    ['search-input', 'region-filter', 'category-filter', 'operator-filter'].forEach(id => {
        document.getElementById(id)?.addEventListener(id === 'search-input' ? 'input' : 'change', scheduleFilter);
    });
    document.getElementById('destination')?.addEventListener('change', markRouteDirty);
    document.getElementById('optimize-btn')?.addEventListener('click', () => optimizeAndDraw(true));
    document.getElementById('save-route-btn')?.addEventListener('click', saveRoute);
    document.getElementById('new-route-btn')?.addEventListener('click', resetRouteForm);
    document.getElementById('rectangle-select-btn')?.addEventListener('click', toggleRectangleMode);
    document.getElementById('clear-selection-btn')?.addEventListener('click', clearSelectedRequests);
    document.getElementById('close-edit-modal')?.addEventListener('click', closeEditModal);
    document.getElementById('cancel-edit-request')?.addEventListener('click', closeEditModal);
    document.getElementById('save-edit-request')?.addEventListener('click', saveRequestEdits);
    document.getElementById('edit-modal')?.addEventListener('click', event => {
        if (event.target.id === 'edit-modal') closeEditModal();
    });
    document.addEventListener('click', event => {
        const addButton = event.target.closest('[data-add-request]');
        if (addButton) addRequest(addButton.dataset.addRequest);
        const addGroupButton = event.target.closest('[data-add-group]');
        if (addGroupButton) addGroup(addGroupButton.dataset.addGroup);
        const removeButton = event.target.closest('[data-remove-request]');
        if (removeButton) removeRequest(removeButton.dataset.removeRequest);
        const editButton = event.target.closest('[data-edit-request]');
        if (editButton) openEditModal(editButton.dataset.editRequest);
        const loadButton = event.target.closest('[data-load-route]');
        if (loadButton) loadRoute(Number(loadButton.dataset.loadRoute));
        const deleteButton = event.target.closest('[data-delete-route]');
        if (deleteButton) deleteRoute(Number(deleteButton.dataset.deleteRoute));
    });
}

async function loadInitialData() {
    try {
        const [requestPayload, routePayload] = await Promise.all([
            getJson(`${apiUrl}?action=requests`),
            getJson(`${apiUrl}?action=routes`)
        ]);
        requests = Array.isArray(requestPayload.requests) ? requestPayload.requests : [];
        requestById = new Map(requests.map(request => [String(request.id), request]));
        routes = Array.isArray(routePayload.routes) ? routePayload.routes : [];
        populateFilters();
        applyFilters();
        renderRoutes();
        const stats = requestPayload.stats || {};
        document.getElementById('source-status').textContent = `${formatNumber(requests.length)} заявок из ${escapeText(stats.source_file || 'Excel')}`;
    } catch (error) {
        showToast(error.message || 'Не удалось загрузить данные.', true);
        document.getElementById('source-status').textContent = 'Ошибка загрузки Excel';
    }
}

function initMap() {
    map = new ymaps.Map('map', {
        center: [55.0, 82.9],
        zoom: 5,
        controls: ['zoomControl', 'fullscreenControl', 'typeSelector']
    });
    objectManager = new ymaps.ObjectManager({
        clusterize: true,
        gridSize: 64,
        clusterDisableClickZoom: false,
        geoObjectOpenBalloonOnClick: true
    });
    objectManager.objects.options.set({preset: 'islands#blueCircleDotIcon'});
    objectManager.clusters.options.set({preset: 'islands#blueClusterIcons'});
    map.geoObjects.add(objectManager);
    initRectangleSelection();
    setMapMessage('');
    applyFilters();
}

function populateFilters() {
    fillSelect('region-filter', uniqueValues(requests.map(item => item.region)), 'Все регионы');
    fillSelect('category-filter', uniqueValues(requests.map(item => item.category)), 'Все категории');
    fillSelect('operator-filter', uniqueValues(requests.map(item => item.operator)), 'Все ОП');
}

function fillSelect(id, values, emptyLabel) {
    const select = document.getElementById(id);
    if (!select) return;
    select.innerHTML = `<option value="">${escapeHtml(emptyLabel)}</option>` + values
        .map(value => `<option value="${escapeAttribute(value)}">${escapeHtml(value)}</option>`)
        .join('');
}

function uniqueValues(values) {
    return [...new Set(values.map(value => String(value || '').trim()).filter(Boolean))]
        .sort((a, b) => a.localeCompare(b, 'ru'));
}

function scheduleFilter() {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(applyFilters, 180);
}

function applyFilters() {
    const search = String(document.getElementById('search-input')?.value || '').trim().toLocaleLowerCase('ru');
    const region = document.getElementById('region-filter')?.value || '';
    const category = document.getElementById('category-filter')?.value || '';
    const operator = document.getElementById('operator-filter')?.value || '';
    const filtered = requests.filter(request => {
        if (region && request.region !== region) return false;
        if (category && request.category !== category) return false;
        if (operator && request.operator !== operator) return false;
        if (!search) return true;
        return [request.id, request.region, request.municipality, request.address, request.operator, request.waste_type, request.fkko_code]
            .some(value => String(value || '').toLocaleLowerCase('ru').includes(search));
    });
    currentFiltered = filtered;
    renderRequestList(filtered);
    renderMarkers(filtered);
    document.getElementById('filter-summary').textContent = `Найдено: ${formatNumber(filtered.length)} из ${formatNumber(requests.length)}. В списке показаны первые 300.`;
}

function initRectangleSelection() {
    const overlay = document.getElementById('selection-overlay');
    if (!overlay) return;
    overlay.addEventListener('pointerdown', event => {
        if (!rectangleMode || event.button !== 0) return;
        const bounds = overlay.getBoundingClientRect();
        rectangleStart = {
            x: Math.max(0, Math.min(bounds.width, event.clientX - bounds.left)),
            y: Math.max(0, Math.min(bounds.height, event.clientY - bounds.top)),
            pageX: event.pageX,
            pageY: event.pageY
        };
        overlay.setPointerCapture(event.pointerId);
        updateSelectionBox(rectangleStart.x, rectangleStart.y, rectangleStart.x, rectangleStart.y);
        event.preventDefault();
    });
    overlay.addEventListener('pointermove', event => {
        if (!rectangleStart) return;
        const bounds = overlay.getBoundingClientRect();
        const x = Math.max(0, Math.min(bounds.width, event.clientX - bounds.left));
        const y = Math.max(0, Math.min(bounds.height, event.clientY - bounds.top));
        updateSelectionBox(rectangleStart.x, rectangleStart.y, x, y);
    });
    overlay.addEventListener('pointerup', event => {
        if (!rectangleStart) return;
        const start = rectangleStart;
        rectangleStart = null;
        document.getElementById('selection-box').classList.add('hidden');
        selectRequestsInRectangle(start.pageX, start.pageY, event.pageX, event.pageY);
    });
    overlay.addEventListener('pointercancel', () => {
        rectangleStart = null;
        document.getElementById('selection-box').classList.add('hidden');
    });
}

function toggleRectangleMode() {
    rectangleMode = !rectangleMode;
    const overlay = document.getElementById('selection-overlay');
    const button = document.getElementById('rectangle-select-btn');
    overlay.classList.toggle('is-active', rectangleMode);
    button.classList.toggle('is-active', rectangleMode);
    button.textContent = rectangleMode ? 'Рамка включена' : 'Выделить рамкой';
    if (map) {
        if (rectangleMode) map.behaviors.disable('drag');
        else map.behaviors.enable('drag');
    }
}

function updateSelectionBox(startX, startY, endX, endY) {
    const box = document.getElementById('selection-box');
    box.classList.remove('hidden');
    box.style.left = `${Math.min(startX, endX)}px`;
    box.style.top = `${Math.min(startY, endY)}px`;
    box.style.width = `${Math.abs(endX - startX)}px`;
    box.style.height = `${Math.abs(endY - startY)}px`;
}

function selectRequestsInRectangle(pageX1, pageY1, pageX2, pageY2) {
    if (!map) return;
    const projection = map.options.get('projection');
    const zoom = map.getZoom();
    const geo1 = projection.fromGlobalPixels(map.converter.pageToGlobal([pageX1, pageY1]), zoom);
    const geo2 = projection.fromGlobalPixels(map.converter.pageToGlobal([pageX2, pageY2]), zoom);
    const minLat = Math.min(geo1[0], geo2[0]);
    const maxLat = Math.max(geo1[0], geo2[0]);
    const minLon = Math.min(geo1[1], geo2[1]);
    const maxLon = Math.max(geo1[1], geo2[1]);
    const existing = new Set(selected.map(request => String(request.id)));
    let added = 0;
    currentFiltered.forEach(request => {
        const latitude = Number(request.latitude);
        const longitude = Number(request.longitude);
        if (latitude >= minLat && latitude <= maxLat && longitude >= minLon && longitude <= maxLon && !existing.has(String(request.id))) {
            selected.push(structuredCloneSafe(request));
            existing.add(String(request.id));
            added++;
        }
    });
    if (added > 0) markRouteDirty();
    renderSelected();
    applyFilters();
    showToast(added ? `Рамкой добавлено заявок: ${added}.` : 'В выделенной области нет новых видимых заявок.');
}

function clearSelectedRequests() {
    if (!selected.length) return;
    if (!confirm('Очистить все выбранные заявки текущего рейса?')) return;
    selected = [];
    markRouteDirty();
    renderSelected();
    applyFilters();
}

function renderRequestList(filtered) {
    const container = document.getElementById('request-list');
    if (!container) return;
    if (!filtered.length) {
        container.innerHTML = '<div class="empty-state">Заявки не найдены.</div>';
        return;
    }
    container.innerHTML = filtered.slice(0, 300).map(request => {
        const alreadySelected = selected.some(item => String(item.id) === String(request.id));
        return `<article class="request-card ${alreadySelected ? 'is-selected' : ''}">
            <div class="request-card-main">
                <strong>${escapeHtml(request.address || 'Адрес не указан')}</strong>
                <span>${escapeHtml(request.region || '')}${request.municipality ? ` · ${escapeHtml(request.municipality)}` : ''}</span>
                <span>${escapeHtml(request.operator || 'ОП не указан')} · ${formatWeight(request.weight_t)}</span>
            </div>
            <button class="small-btn" type="button" data-add-request="${escapeAttribute(request.id)}" ${alreadySelected ? 'disabled' : ''}>${alreadySelected ? 'Добавлено' : 'Добавить'}</button>
        </article>`;
    }).join('');
}

function renderMarkers(filtered) {
    if (!objectManager) return;
    const groups = new Map();
    filtered.forEach(request => {
        const key = `${Number(request.latitude).toFixed(6)},${Number(request.longitude).toFixed(6)}`;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(request);
    });
    const features = [];
    let featureId = 1;
    groups.forEach((items, key) => {
        const first = items[0];
        const shown = items.slice(0, 50);
        const body = shown.map(item => {
            const isSelected = selected.some(selectedItem => String(selectedItem.id) === String(item.id));
            return `<div class="balloon-request"><div><strong>${escapeHtml(item.id)}</strong> · ${formatWeight(item.weight_t)}<br>${escapeHtml(item.address)}</div><button type="button" data-add-request="${escapeAttribute(item.id)}" ${isSelected ? 'disabled' : ''}>${isSelected ? 'Добавлено' : 'Добавить'}</button></div>`;
        }).join('');
        features.push({
            type: 'Feature',
            id: featureId++,
            geometry: {type: 'Point', coordinates: [Number(first.latitude), Number(first.longitude)]},
            properties: {
                iconCaption: items.length > 1 ? String(items.length) : '',
                balloonContentHeader: `${escapeHtml(first.address)} · ${items.length} заявок`,
                balloonContentBody: `<button class="balloon-add-all" type="button" data-add-group="${escapeAttribute(key)}">Добавить все заявки точки</button>${body}${items.length > shown.length ? `<div class="muted">Ещё ${items.length - shown.length} заявок доступны через поиск.</div>` : ''}`
            }
        });
    });
    objectManager.removeAll();
    objectManager.add({type: 'FeatureCollection', features});
}

function addGroup(groupKey) {
    const [lat, lon] = String(groupKey).split(',').map(Number);
    requests.filter(request => Number(request.latitude).toFixed(6) === lat.toFixed(6)
        && Number(request.longitude).toFixed(6) === lon.toFixed(6)).forEach(request => addRequest(request.id, false));
    renderSelected();
    applyFilters();
}

function addRequest(id, rerender = true) {
    const request = requestById.get(String(id));
    if (!request || selected.some(item => String(item.id) === String(id))) return;
    selected.push(structuredCloneSafe(request));
    markRouteDirty();
    if (rerender) {
        renderSelected();
        applyFilters();
    }
}

function removeRequest(id) {
    selected = selected.filter(request => String(request.id) !== String(id));
    markRouteDirty();
    renderSelected();
    applyFilters();
}

function renderSelected() {
    const container = document.getElementById('selected-list');
    const totalWeight = selected.reduce((sum, request) => sum + Number(request.weight_t || 0), 0);
    document.getElementById('selection-summary').textContent = selected.length
        ? `${selected.length} заявок · ${formatWeight(totalWeight)}${routeDirty ? ' · требуется пересчёт' : ''}`
        : 'Заявки не выбраны';
    if (!selected.length) {
        container.innerHTML = '<div class="empty-state">Добавьте заявки из списка или с карты.</div>';
        return;
    }
    container.innerHTML = selected.map((request, index) => `<div class="selected-item">
        <span class="order-number">${index + 1}</span>
        <div><strong>${escapeHtml(request.address || request.id)}</strong><span>${formatWeight(request.weight_t)}${index === 0 ? ' · начало маршрута' : ''}</span></div>
        <button class="icon-btn" type="button" title="Изменить" data-edit-request="${escapeAttribute(request.id)}">✎</button>
        <button class="icon-btn danger" type="button" title="Удалить" data-remove-request="${escapeAttribute(request.id)}">×</button>
    </div>`).join('');
}

async function optimizeAndDraw(showMessages = false) {
    if (!selected.length) {
        showToast('Добавьте хотя бы одну заявку.', true);
        return null;
    }
    const destination = document.getElementById('destination').value;
    setBusy(true, 'Оптимизация…');
    try {
        const result = await postJson('optimize_route', {destination, requests: selected});
        selected = result.requests;
        routeDirty = false;
        renderSelected();
        const estimatedDistance = Number(result.estimated_distance_km || 0);
        currentMetrics = {
            distance_km: estimatedDistance,
            duration_min: 0,
            distance_source: 'Геодезическая оценка'
        };
        renderMetrics();
        try {
            const roadMetrics = await drawRoadRoute(selected, result.destination_coords);
            if (roadMetrics) {
                currentMetrics = roadMetrics;
                renderMetrics();
            }
        } catch (routeError) {
            if (showMessages) showToast('Порядок оптимизирован. Яндекс не вернул дорожное расстояние, сохранена геодезическая оценка.', false);
        }
        return result;
    } catch (error) {
        showToast(error.message || 'Не удалось построить маршрут.', true);
        return null;
    } finally {
        setBusy(false);
    }
}

async function drawRoadRoute(routeRequests, destinationCoords) {
    clearRouteObjects();
    if (!map || typeof ymaps === 'undefined') return null;
    const points = routeRequests.map(request => [Number(request.latitude), Number(request.longitude)]);
    points.push(destinationCoords.map(Number));
    if (points.length < 2) return null;

    const chunks = [];
    for (let start = 0; start < points.length - 1; start += 8) {
        chunks.push(points.slice(start, Math.min(points.length, start + 9)));
    }

    let totalDistance = 0;
    let totalDuration = 0;
    for (const chunk of chunks) {
        const metrics = await createRouteSegment(chunk);
        totalDistance += metrics.distance;
        totalDuration += metrics.duration;
    }
    if (routeObjects.length) {
        const bounds = map.geoObjects.getBounds();
        if (bounds) map.setBounds(bounds, {checkZoomRange: true, zoomMargin: 30});
    }
    return {
        distance_km: totalDistance / 1000,
        duration_min: totalDuration / 60,
        distance_source: 'Яндекс.Маршруты'
    };
}

function createRouteSegment(points) {
    return new Promise((resolve, reject) => {
        const multiRoute = new ymaps.multiRouter.MultiRoute({
            referencePoints: points,
            params: {routingMode: 'auto', results: 1}
        }, {
            boundsAutoApply: false,
            routeActiveStrokeWidth: 5,
            routeActiveStrokeColor: '#2563eb',
            wayPointStartVisible: false,
            wayPointFinishVisible: false,
            viaPointVisible: false
        });
        routeObjects.push(multiRoute);
        map.geoObjects.add(multiRoute);
        const timeout = setTimeout(() => reject(new Error('Тайм-аут маршрутизации')), 30000);
        multiRoute.model.events.add('requestsuccess', () => {
            clearTimeout(timeout);
            const route = multiRoute.getRoutes().get(0);
            if (!route) return reject(new Error('Маршрут не найден'));
            resolve({
                distance: Number(route.properties.get('distance')?.value || 0),
                duration: Number(route.properties.get('duration')?.value || 0)
            });
        });
        multiRoute.model.events.add('requestfail', () => {
            clearTimeout(timeout);
            reject(new Error('Ошибка Яндекс.Маршрутов'));
        });
    });
}

function clearRouteObjects() {
    if (map) routeObjects.forEach(object => map.geoObjects.remove(object));
    routeObjects = [];
}

function markRouteDirty() {
    routeDirty = true;
    currentMetrics = null;
    clearRouteObjects();
    renderSelected();
    renderMetrics();
}

function renderMetrics() {
    const container = document.getElementById('route-metrics');
    if (!currentMetrics) {
        container.innerHTML = '';
        return;
    }
    const duration = Number(currentMetrics.duration_min || 0);
    container.innerHTML = `<strong>${Number(currentMetrics.distance_km || 0).toLocaleString('ru-RU', {maximumFractionDigits: 2})} км</strong>
        ${duration > 0 ? `<span>${formatDuration(duration)}</span>` : ''}
        <span>${escapeHtml(currentMetrics.distance_source || '')}</span>`;
}

async function saveRoute() {
    const name = String(document.getElementById('route-name').value || '').trim();
    if (!name) {
        showToast('Укажите название рейса.', true);
        return;
    }
    if (!selected.length) {
        showToast('Добавьте хотя бы одну заявку.', true);
        return;
    }
    if (routeDirty || !currentMetrics) {
        const optimized = await optimizeAndDraw(false);
        if (!optimized) return;
    }

    setBusy(true, 'Сохранение…');
    try {
        const result = await postJson('save_route', {
            id: editingRouteId || 0,
            name,
            date: document.getElementById('route-date').value,
            destination: document.getElementById('destination').value,
            vehicle: document.getElementById('route-vehicle').value,
            driver: document.getElementById('route-driver').value,
            notes: document.getElementById('route-notes').value,
            requests: selected,
            ...currentMetrics
        });
        showToast(`Рейс №${result.route.id} сохранён.`);
        await reloadRoutes();
        loadRoute(result.route.id);
    } catch (error) {
        showToast(error.message || 'Не удалось сохранить рейс.', true);
    } finally {
        setBusy(false);
    }
}

async function reloadRoutes() {
    const payload = await getJson(`${apiUrl}?action=routes`);
    routes = Array.isArray(payload.routes) ? payload.routes : [];
    renderRoutes();
}

function renderRoutes() {
    const container = document.getElementById('routes-list');
    if (!routes.length) {
        container.innerHTML = '<div class="empty-state">Сохранённых рейсов пока нет.</div>';
        return;
    }
    container.innerHTML = routes.map(route => `<article class="route-card ${Number(route.id) === editingRouteId ? 'is-active' : ''}">
        <div class="route-card-main" data-load-route="${Number(route.id)}">
            <strong>№${Number(route.id)} ${escapeHtml(route.name || '')}</strong>
            <span>${escapeHtml(route.destination_label || '')} · ${Number(route.requests_count || route.requests?.length || 0)} заявок · ${formatWeight(route.total_weight_t)}</span>
            <span>${Number(route.distance_km || 0).toLocaleString('ru-RU', {maximumFractionDigits: 2})} км${route.date ? ` · ${escapeHtml(route.date)}` : ''}</span>
        </div>
        <button class="icon-btn danger" type="button" title="Удалить" data-delete-route="${Number(route.id)}">×</button>
    </article>`).join('');
}

function loadRoute(id) {
    const route = routes.find(item => Number(item.id) === Number(id));
    if (!route) return;
    editingRouteId = Number(route.id);
    selected = structuredCloneSafe(Array.isArray(route.requests) ? route.requests : []);
    document.getElementById('route-name').value = route.name || '';
    document.getElementById('route-date').value = route.date || '';
    document.getElementById('destination').value = route.destination || 'novosibirsk';
    document.getElementById('route-vehicle').value = route.vehicle || '';
    document.getElementById('route-driver').value = route.driver || '';
    document.getElementById('route-notes').value = route.notes || '';
    document.getElementById('route-form-title').textContent = 'Редактирование рейса';
    const badge = document.getElementById('editing-badge');
    badge.textContent = `Рейс №${route.id}`;
    badge.classList.remove('hidden');
    routeDirty = false;
    currentMetrics = {
        distance_km: Number(route.distance_km || 0),
        duration_min: Number(route.duration_min || 0),
        distance_source: route.distance_source || 'Геодезическая оценка'
    };
    clearRouteObjects();
    renderSelected();
    renderMetrics();
    renderRoutes();
    applyFilters();
}

async function deleteRoute(id) {
    if (!confirm(`Удалить рейс №${id}?`)) return;
    try {
        await postJson('delete_route', {id});
        if (editingRouteId === id) resetRouteForm();
        await reloadRoutes();
        showToast(`Рейс №${id} удалён.`);
    } catch (error) {
        showToast(error.message || 'Не удалось удалить рейс.', true);
    }
}

function resetRouteForm() {
    editingRouteId = null;
    selected = [];
    routeDirty = true;
    currentMetrics = null;
    ['route-name', 'route-date', 'route-vehicle', 'route-driver', 'route-notes'].forEach(id => {
        const element = document.getElementById(id);
        if (element) element.value = '';
    });
    document.getElementById('destination').value = 'novosibirsk';
    document.getElementById('route-form-title').textContent = 'Новый рейс';
    document.getElementById('editing-badge').classList.add('hidden');
    clearRouteObjects();
    renderSelected();
    renderMetrics();
    renderRoutes();
    applyFilters();
}

function openEditModal(id) {
    const request = selected.find(item => String(item.id) === String(id));
    if (!request) return;
    editingRequestId = String(id);
    document.getElementById('edit-request-id').value = request.id || '';
    document.getElementById('edit-weight').value = Number(request.weight_t || 0);
    document.getElementById('edit-latitude').value = Number(request.latitude || 0);
    document.getElementById('edit-longitude').value = Number(request.longitude || 0);
    const fields = request.all_fields && typeof request.all_fields === 'object' ? request.all_fields : {};
    document.getElementById('all-fields-editor').innerHTML = Object.entries(fields).map(([name, value]) => `<label>${escapeHtml(name)}<textarea class="control" rows="2" data-all-field="${escapeAttribute(name)}">${escapeHtml(value ?? '')}</textarea></label>`).join('');
    document.getElementById('edit-modal').classList.remove('hidden');
}

function closeEditModal() {
    editingRequestId = null;
    document.getElementById('edit-modal').classList.add('hidden');
}

function saveRequestEdits() {
    const index = selected.findIndex(item => String(item.id) === String(editingRequestId));
    if (index < 0) return;
    const latitude = Number(document.getElementById('edit-latitude').value);
    const longitude = Number(document.getElementById('edit-longitude').value);
    const weight = Number(document.getElementById('edit-weight').value);
    if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90 || !Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
        showToast('Проверьте координаты заявки.', true);
        return;
    }
    if (!Number.isFinite(weight) || weight < 0) {
        showToast('Проверьте вес заявки.', true);
        return;
    }
    const request = selected[index];
    request.latitude = latitude;
    request.longitude = longitude;
    request.weight_t = weight;
    request.coordinate_source = 'Изменено в рейсе';
    const allFields = {};
    document.querySelectorAll('[data-all-field]').forEach(element => {
        allFields[element.dataset.allField] = element.value;
    });
    request.all_fields = allFields;
    const mapping = {
        'Регион МНО': 'region', 'МО, МНО': 'municipality', 'Адрес МНО': 'address',
        'Код ФККО': 'fkko_code', 'Вид отхода': 'waste_type', 'Категория ФККО': 'category',
        'Признак ТДТ': 'tdt', 'График вывоза': 'schedule', 'ОП': 'operator', 'Адрес ОП': 'operator_address',
        'Качество определения': 'geocode_quality', 'Точность Яндекса (precision)': 'geocode_precision',
        'Адрес, найденный Яндексом': 'geocoded_address', 'Тип объекта': 'object_type',
        'Статус геокодирования': 'geocode_status', 'Дата геокодирования': 'geocode_date',
        'Источник / описание точности': 'source_note'
    };
    Object.entries(mapping).forEach(([header, property]) => {
        if (Object.prototype.hasOwnProperty.call(allFields, header)) request[property] = allFields[header];
    });
    if (Object.prototype.hasOwnProperty.call(allFields, '2-х годовой объем')) {
        allFields['2-х годовой объем'] = weight;
    }
    selected[index] = request;
    closeEditModal();
    markRouteDirty();
    renderSelected();
}

async function getJson(url) {
    const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store'});
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) throw new Error(payload?.message || `HTTP ${response.status}`);
    return payload;
}

async function postJson(action, payload) {
    const response = await fetch(`${apiUrl}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify(payload)
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result?.success) throw new Error(result?.message || `HTTP ${response.status}`);
    return result;
}

function setBusy(busy, text = '') {
    ['optimize-btn', 'save-route-btn'].forEach(id => {
        const button = document.getElementById(id);
        if (button) button.disabled = busy;
    });
    if (busy && text) document.getElementById('route-metrics').textContent = text;
}

function setMapMessage(message) {
    const element = document.getElementById('map-message');
    element.textContent = message;
    element.classList.toggle('hidden', !message);
}

function showToast(message, isError = false) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.toggle('is-error', isError);
    toast.classList.remove('hidden');
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => toast.classList.add('hidden'), 4500);
}

function formatWeight(value) {
    return `${Number(value || 0).toLocaleString('ru-RU', {maximumFractionDigits: 3})} т`;
}

function formatNumber(value) {
    return Number(value || 0).toLocaleString('ru-RU');
}

function formatDuration(minutes) {
    const rounded = Math.round(Number(minutes || 0));
    const hours = Math.floor(rounded / 60);
    const remainder = rounded % 60;
    return hours ? `${hours} ч ${remainder} мин` : `${remainder} мин`;
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[character]));
}

function escapeAttribute(value) {
    return escapeHtml(value).replace(/`/g, '&#96;');
}

function escapeText(value) {
    return String(value ?? '');
}

function structuredCloneSafe(value) {
    return typeof structuredClone === 'function' ? structuredClone(value) : JSON.parse(JSON.stringify(value));
}
