'use strict';

// CLI-only. Local synthetic GPS server, no production requests or credentials.
const assert = require('node:assert/strict');
const http = require('node:http');
const {execFile} = require('node:child_process');
const {promisify} = require('node:util');
const path = require('node:path');
const run = promisify(execFile);
let mode = 'no-devices', requests = [];
const server = http.createServer((req, res) => {
    requests.push(req.url);
    const fields = req.url.endsWith('admin-fields');
    const send = () => {
        if (res.destroyed) return;
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify(fields ? [{uniqueid:'test', fields:[]}] : [{uniqueid:'test', name:'Test', lat:55, lon:37}]));
    };
    if (mode === 'no-devices' || (mode === 'slow-fields' && fields)) {
        const timer = setTimeout(send, 10000);
        res.on('close', () => clearTimeout(timer));
    } else if (mode === 'slow-fields') {
        setTimeout(send, 700);
    } else send();
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;
    const root = path.resolve(__dirname, '..').replaceAll('\\','/');
    const php = `error_reporting(E_ALL & ~E_DEPRECATED); function mapError($m,$c=[]){} function utcToMoscow($v){return $v;} function getTimeDiff($v){return ['text'=>'test','minutes'=>0];} function getShortName($v){return $v;}
require '${root}/Services/SlitexClient.php'; require '${root}/Services/TrackerService.php';
$start=microtime(true); $trackers=(new TrackerService(new SlitexClient('http://127.0.0.1:${port}','synthetic-test-token')))->getTrackers();
echo json_encode(['elapsed'=>microtime(true)-$start,'count'=>count($trackers)]);`;
    try {
        for (const scenario of ['no-devices', 'slow-fields', 'healthy']) {
            mode = scenario; requests = [];
            const {stdout, stderr} = await run('php', ['-r', php], {timeout:7000, windowsHide:true, env:{...process.env, NO_PROXY:'127.0.0.1,localhost', no_proxy:'127.0.0.1,localhost'}});
            assert.equal(stderr, '');
            const result = JSON.parse(stdout);
            assert.ok(result.elapsed < 3.6, `${scenario} exceeded total deadline: ${result.elapsed}`);
            assert.equal(requests.length, scenario === 'no-devices' ? 1 : 2);
            assert.equal(result.count, scenario === 'no-devices' ? 0 : 1);
            if (scenario !== 'healthy') assert.ok(result.elapsed >= 2.7);
            console.log(`PASS ${scenario}: ${result.elapsed.toFixed(3)}s, ${requests.length} requests, ${result.count} trackers`);
        }
    } finally { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode=1; server.close(); });
