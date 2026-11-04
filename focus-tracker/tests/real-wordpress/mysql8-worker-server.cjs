'use strict';

// A local PHP worker pool forwarding real WordPress requests unchanged.
// Bodies, cookies, nonces, permissions, clocks and application responses are not substituted.
const http = require('node:http');
const net = require('node:net');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { performance } = require('node:perf_hooks');
const root = path.resolve(__dirname, '../../..');
const run = path.join(root, '_notes/tmp/focus-tracker-mysql8');
const config = JSON.parse(fs.readFileSync(path.join(run, 'config.json'), 'utf8'));
if (config.home !== 'http://127.0.0.1:33918' || config.dbHost !== '127.0.0.1:33917'
    || config.dbName !== 'focus_acceptance_mysql8_20261028' || path.resolve(config.wpPath) !== path.join(run, 'wordpress')) throw Error('Unexpected disposable instance.');
const count = Number(process.env.FOCUS_MYSQL8_WORKERS || 12);
if (!Number.isInteger(count) || count < 2 || count > 16) throw Error('Expected 2..16 local PHP workers.');
const php = path.join(root, '_notes/tmp/oj-ml-1.0-integration/php83/php.exe');
const ext = path.join(path.dirname(php), 'ext');
const phpOptions = ['-n', '-d', 'extension_dir=' + ext, ...['mysqli','mbstring','openssl','curl','zip','intl','fileinfo','sodium'].flatMap(name => ['-d', 'extension=' + name]),
    '-d', 'zend_extension=' + path.join(ext, 'php_opcache.dll'), '-d', 'opcache.enable_cli=1', '-d', 'opcache.memory_consumption=128'];
const workers = [];
const observations = fs.createWriteStream(path.join(run, 'http-observations.jsonl'), { flags: 'a', encoding: 'utf8' });
const phpLog = fs.openSync(path.join(run, 'php-workers.log'), 'a');
let stopping = false;
let completed = 0;
const proxy = http.createServer((req, res) => {
    const worker = workers.reduce((best, item) => item.active < best.active || item.active === best.active && item.requests < best.requests ? item : best);
    worker.active++; worker.requests++;
    const started = performance.now(); const simultaneous = worker.active; let doneAlready = false;
    const done = (status, error) => {
        if (doneAlready) return;
        doneAlready = true; worker.active--; completed++;
        observations.write(JSON.stringify({ at: new Date().toISOString(), method: req.method, path: new URL(req.url, config.home).pathname,
            status, durationMs: Number((performance.now() - started).toFixed(2)), workerPort: worker.port, workerInflightAtDispatch: simultaneous,
            ...(error ? { error } : {}) }) + '\n');
    };
    const upstream = http.request({ hostname: '127.0.0.1', port: worker.port, method: req.method, path: req.url,
        headers: { ...req.headers, host: '127.0.0.1:33918', connection: 'close' } }, response => {
        res.writeHead(response.statusCode, response.headers); response.pipe(res);
        response.on('end', () => done(response.statusCode));
        response.on('error', error => done(502, error.code || error.name));
    });
    upstream.on('error', error => { done(502, error.code || error.name); if (!res.headersSent) res.writeHead(502); res.end(); });
    res.on('close', () => { if (!res.writableEnded) { done(499, 'client_closed'); upstream.destroy(); } });
    req.pipe(upstream);
});
function metadata() {
    fs.writeFileSync(path.join(run, 'http-workers.json'), JSON.stringify({ proxyPid: process.pid, workers: workers.map(item => ({ port: item.port, pid: item.child.pid,
        requests: item.requests, active: item.active })), opcache: true, opcacheMemoryMiBPerWorker: 128, completed, stopping }, null, 2) + '\n', 'utf8');
}
async function stop() {
    if (stopping) return;
    stopping = true; metadata();
    await new Promise(resolve => proxy.close(resolve));
    for (const worker of workers) worker.child.kill();
    fs.closeSync(phpLog);
    await new Promise(resolve => observations.end(resolve));
    console.log('Owned native MySQL8 HTTP pool stopped.');
}
process.on('SIGINT', () => stop().then(() => process.exit(0)));
process.on('SIGTERM', () => stop().then(() => process.exit(0)));
process.stdin.setEncoding('utf8');
process.stdin.on('data', data => { if (data.trim() === 'stop') stop().then(() => process.exit(0)); });
process.on('exit', () => { for (const worker of workers) if (worker.child.exitCode === null) worker.child.kill(); });
async function freePort(port) {
    await new Promise((resolve, reject) => {
        const socket = net.createServer(); socket.once('error', reject);
        socket.listen(port, '127.0.0.1', () => socket.close(resolve));
    });
}
async function warm(worker) {
    for (let attempt = 0; attempt < 50; attempt++) {
        if (worker.child.exitCode !== null) throw Error('A native PHP worker exited before readiness.');
        try {
            const response = await fetch('http://127.0.0.1:' + worker.port + '/login', { headers: { Host: '127.0.0.1:33918' }, redirect: 'manual', signal: AbortSignal.timeout(15000) });
            await response.arrayBuffer(); if (response.status === 200) return;
        } catch (_) { /* Wait for this owned server to finish binding/warming. */ }
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw Error('A native PHP worker did not become ready.');
}
(async () => {
    await Promise.all([33918, ...Array.from({ length: count }, (_, index) => 33919 + index)].map(freePort));
    for (let index = 0; index < count; index++) {
        const port = 33919 + index;
        const child = spawn(php, [...phpOptions, '-S', '127.0.0.1:' + port, '-t', config.wpPath, path.join(__dirname, 'router.php')],
            { cwd: root, windowsHide: true, stdio: ['ignore', phpLog, phpLog] });
        workers.push({ port, child, active: 0, requests: 0 });
    }
    await Promise.all(workers.map(warm));
    await new Promise(resolve => proxy.listen(33918, '127.0.0.1', resolve)); metadata();
    console.log('Native WordPress + Oracle MySQL8 HTTP ready: ' + count + ' PHP 8.3 workers, OPcache; real application/authentication.');
})().catch(error => { console.error(error.message); for (const worker of workers) worker.child.kill(); observations.end(); fs.closeSync(phpLog); process.exit(1); });
