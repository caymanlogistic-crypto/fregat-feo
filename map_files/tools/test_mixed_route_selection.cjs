'use strict';

// CLI-only DOM regression checks: synthetic requests, no HTTP or database access.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {execFileSync} = require('node:child_process');
const source = fs.readFileSync(path.join(__dirname, '../assets/maptest.js'), 'utf8');

function runtime(info, basis = 'mass_brutto') {
    function element() {
        return {style: {}, dataset: {}, value: '', textContent: '', innerHTML: '', children: [],
            appendChild(child) { this.children.push(child); }, setAttribute() {}, querySelector() { return null; }};
    }
    const elements = Object.fromEntries(['selection-panel', 'selected-list', 'total-weight', 'stats-box', 'route-input', 'calc-route-btn', 'saveRouteBtn', 'route-info-container', 'plannedRoutesList', 'foundRoutesList', 'startedRoutesList'].map(id => [id, element()]));
    const context = vm.createContext({
        window: {MAP_BOOTSTRAP: {massBasis: basis, requestInfoById: info}},
        document: {getElementById: id => elements[id] || null, createElement: element, addEventListener() {}, querySelectorAll: () => []},
        ymaps: {ready() {}}, URLSearchParams, console,
    });
    vm.runInContext(source, context);
    return {context, elements, run: script => vm.runInContext(script, context)};
}
function kg(text) { return Number(text.match(/:\s*([\d\s\u00a0\u202f]+)/)[1].replace(/\s/g, '')); }

async function main() {
    const php = `
        $_SERVER['SCRIPT_NAME'] = 'map2.php';
        $routeInfoMap = [1 => ['mass_brutto' => 1.2, 'mass_netto' => 1.0, 'mno_adres_pogruzki' => "\\xFF"]];
        $routeCardsMeta = $foundRoutesData = $statusNames = $processedGroups = $flightStatusList = $customLayers = [];
        $trackersJson = $allTrackersJson = $foundRoutesJson = $driversForSelectJson = '[]';
        $routeCardsMetaJson = '{}'; $routeCardsBuildError = '';
        require 'map_files/Views/scripts.php';`;
    const rendered = execFileSync('php', ['-r', php], {cwd: path.join(__dirname, '../..'), encoding: 'utf8'});
    const bootstrap = {window: {}};
    vm.runInNewContext(rendered.match(/<script>([^]*?)<\/script>/)[1], bootstrap);
    assert.equal(bootstrap.window.MAP_BOOTSTRAP.massBasis, 'mass_brutto');
    assert.deepEqual(JSON.parse(JSON.stringify(bootstrap.window.MAP_BOOTSTRAP.requestInfoById)), {'1': {mass_netto: 1, mass_brutto: 1.2}});
    console.log('PASS: PHP bootstrap supplies both weights without exposing addresses or breaking JSON on legacy text');

    const info = {};
    const weights = [...Array(35).fill(390), 424, 502, 345, 502, 500, 5, 520, 1080, 780, 2302];
    weights.forEach((weight, index) => { info[index + 1] = {mass_brutto: weight / 1000, mass_netto: weight / 2000}; });
    const r = runtime(info);
    r.run(`selectedOrder = Array.from({length: 45}, (_, i) => String(i + 1));
        groupsData = [{points: [43,44,45].map(id => ({zayavka_id: id, mno_adres_pogruzki: 'TEST address ' + id}))}];
        weightById = Object.fromEntries(Array.from({length: 36}, (_, i) => [String(i + 1), ${JSON.stringify(weights)}[i]]));
        updateSelectionUI();`);
    assert.equal(kg(r.elements['total-weight'].textContent), 20610);
    assert.match(r.elements['stats-box'].textContent, /45.*3$/);
    assert.equal(r.elements['selected-list'].children.length, 45);
    console.log('PASS: all 45 requests weigh 20610 kg, including six pending inbound and three direct; three OO addresses');

    r.run("selectedOrder = ['37', '43']; updateSelectionUI();");
    assert.equal(kg(r.elements['total-weight'].textContent), 1582);
    console.log('PASS: removing requests recalculates the current selection, not the saved flight total');

    const net = runtime(info, 'mass_netto');
    net.run("selectedOrder = ['37', '43']; updateSelectionUI();");
    assert.equal(kg(net.elements['total-weight'].textContent), 791);
    console.log('PASS: netto view uses netto for requests without map points or stock');

    const zero = runtime({'1': {mass_brutto: 0}});
    zero.run("weightById = {'1': 999, '2': 321}; selectedOrder = ['1','2']; updateSelectionUI();");
    assert.equal(kg(zero.elements['total-weight'].textContent), 321);
    console.log('PASS: authoritative zero beats stale stock mass; requests outside saved routes retain fallback weights');

    r.context.fetch = async () => ({json: async () => ({success: true, routes: [], found_routes: [], started_routes: [], request_info: {'37': {mass_brutto: 1}, '43': {mass_brutto: 2}}})});
    r.run('renderTrackersByMode = () => {};');
    await r.run('loadPlannedRoutes()');
    assert.equal(kg(r.elements['total-weight'].textContent), 3000);
    console.log('PASS: refreshing saved routes refreshes selected weights without reloading the page');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
