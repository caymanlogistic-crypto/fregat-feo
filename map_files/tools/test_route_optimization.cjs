'use strict';

// CLI-only regression checks: synthetic coordinates, no HTTP, DB, or saved flights.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const optimizer = require('../assets/route_optimization.js');
const source = fs.readFileSync(path.join(__dirname, '../assets/maptest.js'), 'utf8');
let passed = 0;
function check(name, fn) { fn(); passed++; console.log('PASS ' + name); }
function plain(value) { return JSON.parse(JSON.stringify(value)); }
function bruteMinimum(matrix, order = [], left = matrix.slice(1).map((_, i) => i + 1), roundTrip = false) {
    if (!left.length) return optimizer.routeLength(order, matrix, roundTrip);
    return Math.min(...left.map(next => bruteMinimum(matrix, order.concat(next), left.filter(id => id !== next), roundTrip)));
}

check('fixed origin, free finish; first stop need not be nearest', () => {
    const matrix = [[0, 2, 3], [Infinity, 0, 100], [Infinity, 1, 0]];
    const result = optimizer.orderStops(matrix);
    assert.deepEqual(result, {order: [2, 1], exact: true});
    assert.equal(optimizer.routeLength(result.order, matrix), 4);
});
check('directed-road minimum agrees with independent exhaustive reference for 2..7 stops', () => {
    for (let size = 2; size <= 7; size++) {
        const matrix = Array.from({length: size + 1}, (_, from) =>
            Array.from({length: size + 1}, (_, to) => from === to ? 0 : to === 0 ? Infinity : 1 + ((from * 29 + to * 47 + from * to * 7) % 113)));
        const result = optimizer.orderStops(matrix);
        assert.equal(optimizer.routeLength(result.order, matrix), bruteMinimum(matrix));
        assert.deepEqual(result.order.slice().sort((a, b) => a - b), Array.from({length: size}, (_, i) => i + 1));
    }
});
check('17-stop result is explicitly approximate and retains every stop', () => {
    const matrix = Array.from({length: 18}, (_, from) => Array.from({length: 18}, (_, to) => Math.abs(from - to)));
    const result = optimizer.orderStops(matrix);
    assert.equal(result.exact, false);
    assert.equal(new Set(result.order).size, 17);
    assert.equal(optimizer.routeLength(result.order, matrix), 17);
});
check('API batches cover every required directed pair exactly once; no forced return', () => {
    for (const count of [1, 2, 7, 16, 49]) {
        const seen = new Set();
        for (const batch of optimizer.roadBatches(count)) {
            assert.ok(batch.length >= 2 && batch.length <= 9);
            for (let i = 1; i < batch.length; i++) {
                assert.notEqual(batch[i], 0);
                const key = batch[i - 1] + ':' + batch[i];
                assert.ok(!seen.has(key)); seen.add(key);
            }
        }
        assert.equal(seen.size, count * count);
    }
});
check('missing roads fail explicitly instead of using straight-line distances', () => {
    assert.throws(() => optimizer.orderStops([[0, 1, Infinity], [Infinity, 0, 1], [Infinity, 1, 0]]), /дорогу/);
});
check('closed directed-road minimum agrees with independent exhaustive reference for 2..7 stops', () => {
    for (let size = 2; size <= 7; size++) {
        const matrix = Array.from({length:size+1}, (_,from) => Array.from({length:size+1}, (_,to) => from === to ? 0 : 1 + ((from*31+to*43+from*to*7)%113)));
        const result = optimizer.orderStops(matrix, true);
        assert.equal(optimizer.routeLength(result.order, matrix, true), bruteMinimum(matrix, [], matrix.slice(1).map((_,i)=>i+1), true));
    }
});
check('closed API batches cover all directed pairs, including roads back to the warehouse', () => {
    for (const count of [1,2,7,16,49]) {
        const seen = new Set();
        for (const batch of optimizer.roadBatches(count, true)) {
            assert.ok(batch.length >= 2 && batch.length <= 9);
            if (batch.at(-1) === 0) assert.equal(batch.length, 2, 'return legs must be separate direct requests');
            for (let i=1;i<batch.length;i++) {
                const key=batch[i-1]+':'+batch[i];
                assert.ok(!seen.has(key)); seen.add(key);
            }
        }
        assert.equal(seen.size, count*(count+1));
        for(let i=1;i<=count;i++) assert.ok(seen.has(i+':0'));
    }
});

function makeRuntime(sharedCache = new Map()) {
    const spinner = {hidden: true};
    const elements = {'route-return-to-warehouse': {checked: false}, 'route-destination': {value: '1'}, 'calc-route-btn': {disabled: false, setAttribute(name, value) { this[name] = value; }, querySelector() { return spinner; }}, 'route-info-container': {textContent: '', innerHTML: '', dataset: {}}};
    const runtime = {
        selectedOrder: ['3', '1', '2', '4'], editingRouteId: null,
        warehousesData: [{id: 1, name: 'Воскресенск', address: 'Test warehouse', latitude: 55, longitude: 37}],
        groupsData: [{points: [
            {zayavka_id: 1, mno_d: '55.1', mno_sh: '37.1'},
            {zayavka_id: 2, mno_d: '55.2', mno_sh: '37.2'},
            {zayavka_id: 3, mno_d: '55.3', mno_sh: '37.3'},
            {zayavka_id: 4, mno_d: '55.1', mno_sh: '37.1'}
        ]}],
        routeCalculationRunning: false, routeCalculationGeneration: 0, routeSelectionSignature: '', multiRoute: null,
        FregatRouteOptimization: optimizer, UI: {ruler: 'Distance', km: 'km', clock: 'Time', hourShort: 'h', min: 'min'},
        document: {getElementById: id => elements[id]},
        setTimeout, clearTimeout, AbortController, calls: [], objects: [], alerts: [], geocodeCalls: 0,
        fetch: async (url, options) => {
            assert.equal(url, 'map_files/route_cache.php');
            const payload = JSON.parse(options.body);
            const key = JSON.stringify([payload.destination, payload.direction, payload.return_to_warehouse]) + ':' + payload.points.map(point => point.map(Number).map(value => value.toFixed(7)).join(',')).sort().join(';');
            let record = sharedCache.get(key);
            let data;
            if (payload.action === 'claim') {
                if (record?.result) data = {status: 'hit', result: record.result};
                else { record = {token: 'test-lease'}; sharedCache.set(key, record); data = {status: 'compute', token: record.token}; }
            } else if (payload.action === 'save') {
                assert.equal(payload.token, record.token);
                record.result = payload.result;
                data = {status: 'saved', result: record.result};
            } else { sharedCache.delete(key); data = {status: 'released'}; }
            return {ok: true, json: async () => ({success: true, ...data})};
        },
        updateSelectionUI() {}, refreshMarkerStyles() {}, alert(message) { runtime.alerts.push(message); },
        map: {geoObjects: {add(object) { runtime.objects.push(object); }, remove(object) { runtime.objects = runtime.objects.filter(item => item !== object); }}, setBounds() {}},
        ymaps: {
            geocode: async () => { runtime.geocodeCalls++; return {geoObjects: {get: () => ({geometry: {getCoordinates: () => [55, 37]}})}}; },
            multiRouter: {MultiRoute: function (model) {
                runtime.calls.push(model);
                const routes = [2, 1].map(multiplier => {
                    const paths = model.referencePoints.slice(1).map((to, index) => {
                        const from = model.referencePoints[index];
                        const distance = (runtime.legDistance ? runtime.legDistance(from, to) : Math.round(Math.abs(to[0] - from[0]) * 10000)) * multiplier;
                        return {properties: {get: name => name === 'coordinates' ? [from, [from[0], to[1]], to] : {value: distance}}};
                    });
                    return {getPaths: () => ({each: callback => paths.forEach(callback)}), properties: {get: () => ({value: paths.reduce((sum, leg) => sum + leg.properties.get('distance').value, 0)})}};
                });
                this.model = {events: {add(event, callback) { if (event === 'requestsuccess') queueMicrotask(callback); }}, destroy() {}};
                this.getRoutes = () => ({each: callback => routes.forEach(callback)});
            }},
            GeoObjectCollection: function () { this.items = []; this.add = object => this.items.push(object); this.getBounds = () => null; },
            Polyline: function (coordinates) { this.coordinates = coordinates; },
            Placemark: function (coords, properties) { this.coords = coords; this.properties = properties; }
        }
    };
    vm.createContext(runtime);
    vm.runInContext(source.match(/^const UNLOAD_ADDRESS = [^\r\n]+/m)[0], runtime);
    for (const name of ['routeDestinationWarehouses', 'routeCalculationSignature', 'updateRouteCalculationButton', 'selectedRouteDestination', 'collectSelectedRouteStops', 'requestYandexRoadRoutes', 'routeCachePointKey', 'postRouteCache', 'claimRouteCache', 'calculateUncachedRoadRoute', 'calculateRoute']) {
        const match = source.match(new RegExp('^(?:async )?function ' + name + '\\([^]*?^}', 'm'));
        assert.ok(match, name + ' exists'); vm.runInContext(match[0], runtime);
    }
    runtime.elements = elements; runtime.spinner = spinner;
    return runtime;
}

async function main() {
    const runtime = makeRuntime();
    check('same coordinates become one stop without losing request IDs', () => {
        const stops = plain(runtime.collectSelectedRouteStops());
        assert.equal(stops.length, 3);
        assert.deepEqual(stops.flatMap(stop => stop.ids).sort(), ['1', '2', '3', '4']);
    });
    const calculating = runtime.calculateRoute();
    check('spinner is shown and button disabled while awaiting the shared cache', () => {
        assert.equal(runtime.spinner.hidden, false);
        assert.equal(runtime.elements['calc-route-btn'].disabled, true);
    });
    await calculating;
    check('integration starts at the selected warehouse, visits all requests, and has no return leg', () => {
        const refs = runtime.calls.at(-1).referencePoints;
        assert.deepEqual(plain(refs), [[55, 37], [55.1, 37.1], [55.2, 37.2], [55.3, 37.3]]);
        assert.deepEqual(plain(runtime.selectedOrder), ['1', '4', '2', '3']);
        assert.equal(runtime.calls[0].params.routingMode, 'auto');
        assert.equal(runtime.calls[0].params.avoidTrafficJams, false);
        assert.equal(runtime.calls[0].params.results, 3);
    });
    check('distance and drawn geometry use the shorter alternative, not first returned route', () => {
        assert.match(runtime.elements['route-info-container'].innerHTML, /3\.00 km/);
        assert.equal(runtime.objects.length, 1);
        assert.equal(runtime.objects[0].items.length, 5);
        assert.equal(runtime.elements['calc-route-btn'].disabled, false);
        assert.equal(runtime.spinner.hidden, true);
    });
    const one = makeRuntime(); one.selectedOrder = ['1']; await one.calculateRoute();
    check('single selected request is routable from the selected warehouse', () => assert.equal(one.calls.at(-1).referencePoints.length, 2));
    const missing = makeRuntime(); missing.selectedOrder = ['999']; await missing.calculateRoute();
    check('missing request coordinates abort without partial route or API calls', () => {
        assert.equal(missing.calls.length, 0);
        assert.match(missing.elements['route-info-container'].textContent, /999/);
    });
    const failing = makeRuntime();
    failing.ymaps.multiRouter.MultiRoute = function () { this.model = {destroy() {}, events: {add(event, callback) { if (event === 'requestfail') queueMicrotask(callback); }}}; };
    await failing.calculateRoute();
    check('Yandex failure restores controls and never displays estimated kilometres', () => {
        assert.equal(failing.objects.length, 0);
        assert.equal(failing.elements['calc-route-btn'].disabled, false);
        assert.match(failing.elements['route-info-container'].textContent, /Яндекс не построил/);
        assert.deepEqual(plain(failing.selectedOrder), ['3', '1', '2', '4']);
    });
    let cancel = false, loads = 0;
    await assert.rejects(() => optimizer.roadDistances([[0, 0], [1, 1], [2, 2]], async () => { loads++; cancel = true; return [[1]]; }, () => {}, () => cancel), /отменён/);
    check('selection cancellation stops further API batches', () => assert.equal(loads, 1));
    let maxCalls = 0;
    for (const roundTrip of [false, true]) for (const geocode of [false, true]) for (let size = 1; size <= 49; size++) {
        const budgeted = makeRuntime();
        budgeted.elements['route-return-to-warehouse'].checked = roundTrip;
        if (geocode) budgeted.warehousesData[0].latitude = null;
        budgeted.selectedOrder = Array.from({length: size}, (_, i) => String(i + 1));
        budgeted.groupsData = [{points: budgeted.selectedOrder.map(id => ({
            zayavka_id: id, mno_d: String(55 + Number(id) / 100), mno_sh: String(37 + Number(id) / 200)
        }))}];
        await budgeted.calculateRoute();
        const used = budgeted.calls.length + budgeted.geocodeCalls;
        assert.ok(used <= 10, `${size} stops exceeded 10 calls: ${used}`);
        assert.ok(budgeted.calls.every(call => call.referencePoints.length <= 9 && call.params.reverseGeocoding === false));
        assert.equal(new Set(budgeted.selectedOrder).size, size);
        assert.equal(budgeted.objects.length, 1, `no displayed route for ${size} stops`);
        const html = budgeted.elements['route-info-container'].innerHTML;
        assert.equal((html.match(/class="route-info-item"/g) || []).length, 2);
        assert.ok(html.endsWith('</div></div>'));
        assert.doesNotMatch(html, /Старт:|Кратчайший|Абсолютный|Запросов Яндексу:/);
        maxCalls = Math.max(maxCalls, used);
    }
    check('open and closed routes for 1..49 stops stay within 10 calls, with or without geocoding', () => assert.ok(maxCalls <= 10));
    check('20 daily calculations cost at most 200 explicit API calls', () => assert.ok(maxCalls * 20 <= 200));
    const excessive = makeRuntime();
    excessive.selectedOrder = Array.from({length: 50}, (_, i) => String(i + 1));
    excessive.groupsData = [{points: excessive.selectedOrder.map(id => ({zayavka_id: id, mno_d: 55 + Number(id) / 100, mno_sh: 37}))}];
    await excessive.calculateRoute();
    check('oversized selection is rejected before spending a single API call', () => {
        assert.equal(excessive.geocodeCalls + excessive.calls.length, 0);
        assert.match(excessive.elements['route-info-container'].textContent, /49/);
    });
    const shared = new Map(), firstUser = makeRuntime(shared), secondUser = makeRuntime(shared);
    await firstUser.calculateRoute();
    secondUser.selectedOrder.reverse();
    await secondUser.calculateRoute();
    check('another browser/user reuses the shared result despite different input order, with zero Yandex calls', () => {
        assert.ok(firstUser.calls.length > 0);
        assert.equal(secondUser.calls.length + secondUser.geocodeCalls, 0);
        assert.equal(secondUser.elements['route-info-container'].dataset.routeCache, 'hit');
        assert.equal(secondUser.elements['route-info-container'].dataset.yandexRequests, '0');
        assert.equal(secondUser.elements['route-info-container'].innerHTML, firstUser.elements['route-info-container'].innerHTML);
        const stopOrder = user => [...new Set(user.selectedOrder.map(id => {
            const point = user.groupsData[0].points.find(point => String(point.zayavka_id) === String(id));
            return point.mno_d + ',' + point.mno_sh;
        }))];
        assert.deepEqual(plain(stopOrder(secondUser)), plain(stopOrder(firstUser)));
        assert.deepEqual(plain(secondUser.selectedOrder).sort(), plain(firstUser.selectedOrder).sort());
    });
    const unavailable = makeRuntime();
    unavailable.fetch = async () => ({ok: false, json: async () => ({success: false, message: 'Кэш недоступен'})});
    await unavailable.calculateRoute();
    check('cache failure cannot silently spend fresh Yandex requests', () => {
        assert.equal(unavailable.calls.length + unavailable.geocodeCalls, 0);
        assert.equal(unavailable.elements['route-info-container'].textContent, 'Кэш недоступен');
    });
    const other = makeRuntime(shared);
    other.warehousesData[0] = {id: 2, address: 'Other warehouse', latitude: 56, longitude: 38};
    other.elements['route-destination'].value = '2';
    await other.calculateRoute();
    check('another warehouse has a separate cache and is the first Yandex point', () => {
        assert.ok(other.calls.length > 0);
        assert.deepEqual(plain(other.calls.at(-1).referencePoints[0]), [56, 38]);
        assert.equal(other.elements['route-info-container'].dataset.routeCache, 'saved');
    });
    const directed = makeRuntime();
    const costs = [[0,90,90,90],[1,0,2,50],[90,50,0,2],[90,50,50,0]];
    directed.legDistance = (from, to) => costs[Math.round((from[0]-55)*10)][Math.round((to[0]-55)*10)];
    await directed.calculateRoute();
    check('asymmetric roads are optimized from the selected warehouse in actual driving direction', () => {
        assert.deepEqual(plain(directed.selectedOrder), ['1','4','2','3']);
        assert.match(directed.elements['route-info-container'].innerHTML, /94/);
        assert.deepEqual(plain(directed.objects[0].items[0].coordinates[0]), [55,37]);
    });
    const closed = makeRuntime(shared);
    closed.elements['route-return-to-warehouse'].checked = true;
    closed.legDistance = directed.legDistance;
    await closed.calculateRoute();
    check('checkbox includes return road in optimization, distance, and drawn geometry', () => {
        assert.ok(closed.calls.length > 0, 'closed route cannot reuse the open route cache');
        assert.deepEqual(plain(closed.selectedOrder), ['2','3','1','4']);
        assert.match(closed.elements['route-info-container'].innerHTML, /143/);
        const coords = closed.objects[0].items[0].coordinates;
        assert.deepEqual(plain(coords[0]), [55,37]);
        assert.deepEqual(plain(coords.at(-1)), [55,37]);
        assert.deepEqual(plain(closed.calls.at(-1).referencePoints.at(-1)), [55,37]);
    });
    const repeatClosed = makeRuntime(shared);
    repeatClosed.elements['route-return-to-warehouse'].checked = true;
    await repeatClosed.calculateRoute();
    check('closed route is shared across users without any additional Yandex calls', () => {
        assert.equal(repeatClosed.calls.length + repeatClosed.geocodeCalls, 0);
        assert.equal(repeatClosed.elements['route-info-container'].innerHTML, closed.elements['route-info-container'].innerHTML);
    });
    const fallback = makeRuntime();
    fallback.warehousesData = [{id: 5, name: 'Other', address: 'Other warehouse', latitude: 56, longitude: 38}];
    fallback.elements['route-destination'].value = '-1';
    await fallback.calculateRoute();
    check('Voskresensk remains available by default when absent from the warehouse database', () => {
        assert.equal(fallback.geocodeCalls, 1);
        assert.equal(fallback.objects.length, 1);
    });
    const geocoded = makeRuntime();
    geocoded.warehousesData[0].latitude = null;
    await geocoded.calculateRoute();
    check('warehouse without coordinates geocodes its address within the same ten-call budget', () => {
        assert.equal(geocoded.geocodeCalls, 1);
        assert.ok(geocoded.calls.length + geocoded.geocodeCalls <= 10);
    });
    console.log(`${passed} checks passed`);
}
main().catch(error => { console.error(error); process.exitCode = 1; });
