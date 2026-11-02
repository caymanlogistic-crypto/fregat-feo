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
function bruteMinimum(matrix, order = [], left = matrix.slice(1).map((_, i) => i + 1)) {
    if (!left.length) return optimizer.routeLength(order, matrix);
    return Math.min(...left.map(next => bruteMinimum(matrix, order.concat(next), left.filter(id => id !== next))));
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

function makeRuntime() {
    const elements = {'calc-route-btn': {disabled: false}, 'route-info-container': {textContent: '', innerHTML: ''}};
    const runtime = {
        selectedOrder: ['3', '1', '2', '4'], editingRouteId: null,
        groupsData: [{points: [
            {zayavka_id: 1, mno_d: '55.1', mno_sh: '37.1'},
            {zayavka_id: 2, mno_d: '55.2', mno_sh: '37.2'},
            {zayavka_id: 3, mno_d: '55.3', mno_sh: '37.3'},
            {zayavka_id: 4, mno_d: '55.1', mno_sh: '37.1'}
        ]}],
        routeCalculationRunning: false, routeCalculationGeneration: 0, routeSelectionSignature: '', multiRoute: null,
        FregatRouteOptimization: optimizer, UI: {ruler: 'Distance', km: 'km', clock: 'Time', hourShort: 'h', min: 'min'},
        document: {getElementById: id => elements[id]},
        setTimeout, clearTimeout, calls: [], objects: [], alerts: [], geocodeCalls: 0,
        updateSelectionUI() {}, refreshMarkerStyles() {}, alert(message) { runtime.alerts.push(message); },
        map: {geoObjects: {add(object) { runtime.objects.push(object); }, remove(object) { runtime.objects = runtime.objects.filter(item => item !== object); }}, setBounds() {}},
        ymaps: {
            geocode: async () => { runtime.geocodeCalls++; return {geoObjects: {get: () => ({geometry: {getCoordinates: () => [55, 37]}})}}; },
            multiRouter: {MultiRoute: function (model) {
                runtime.calls.push(model);
                const routes = [2, 1].map(multiplier => {
                    const paths = model.referencePoints.slice(1).map((to, index) => {
                        const from = model.referencePoints[index];
                        const distance = Math.round(Math.abs(to[0] - from[0]) * 10000) * multiplier;
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
    for (const name of ['collectSelectedRouteStops', 'requestYandexRoadRoutes', 'calculateRoute']) {
        const match = source.match(new RegExp('^(?:async )?function ' + name + '\\([^]*?^}', 'm'));
        assert.ok(match, name + ' exists'); vm.runInContext(match[0], runtime);
    }
    runtime.elements = elements;
    return runtime;
}

async function main() {
    const runtime = makeRuntime();
    check('same coordinates become one stop without losing request IDs', () => {
        const stops = plain(runtime.collectSelectedRouteStops());
        assert.equal(stops.length, 3);
        assert.deepEqual(stops.flatMap(stop => stop.ids).sort(), ['1', '2', '3', '4']);
    });
    await runtime.calculateRoute();
    check('integration starts in Voskresensk, visits all requests, and has no return leg', () => {
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
    });
    const one = makeRuntime(); one.selectedOrder = ['1']; await one.calculateRoute();
    check('single selected request is routable from Voskresensk', () => assert.equal(one.calls.at(-1).referencePoints.length, 2));
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
    for (let size = 1; size <= 49; size++) {
        const budgeted = makeRuntime();
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
    check('all 1..49 address counts stay within 10 calls including geocoding and chunking', () => assert.ok(maxCalls <= 10));
    check('20 daily calculations cost at most 200 explicit API calls', () => assert.ok(maxCalls * 20 <= 200));
    const excessive = makeRuntime();
    excessive.selectedOrder = Array.from({length: 50}, (_, i) => String(i + 1));
    excessive.groupsData = [{points: excessive.selectedOrder.map(id => ({zayavka_id: id, mno_d: 55 + Number(id) / 100, mno_sh: 37}))}];
    await excessive.calculateRoute();
    check('oversized selection is rejected before spending a single API call', () => {
        assert.equal(excessive.geocodeCalls + excessive.calls.length, 0);
        assert.match(excessive.elements['route-info-container'].textContent, /49/);
    });
    console.log(`${passed} checks passed`);
}
main().catch(error => { console.error(error); process.exitCode = 1; });
