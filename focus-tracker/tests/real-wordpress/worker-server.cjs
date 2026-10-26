'use strict';

// Real HTTP -> native PHP/WordPress workers -> shared MySQL. No mock responses,
// authentication bypass, request-body inspection, timer overrides or retries.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '../../..');
const run = path.join(root, '_notes/tmp/focus-tracker-real-wp');
const config = JSON.parse(fs.readFileSync(path.join(run, 'config.json'), 'utf8'));
if (config.home !== 'http://127.0.0.1:33918' || path.resolve(config.wpPath) !== path.join(run, 'wordpress')) throw Error('Refusing a non-disposable site.');
const php = path.join(root, '_notes/tmp/oj-ml-1.0-integration/php83/php.exe');
const ext = path.join(path.dirname(php), 'ext');
const workers = [];
const observations = [];
const phpOptions = ['-n', '-d', 'extension_dir=' + ext, ...['mysqli','mbstring','openssl','curl','zip','intl','fileinfo','sodium'].flatMap(name => ['-d', 'extension=' + name]),
    '-d', 'zend_extension=' + path.join(ext, 'php_opcache.dll'), '-d', 'opcache.enable_cli=1', '-d', 'opcache.memory_consumption=256'];
const log = fs.openSync(path.join(run, 'php-workers.log'), 'a');
for (let index = 0; index < 4; index++) {
    const port = 33919 + index;
    const child = spawn(php, [...phpOptions, '-S', '127.0.0.1:' + port, '-t', config.wpPath, path.join(__dirname, 'router.php')], { cwd: root, windowsHide: true, stdio: ['ignore', log, log] });
    workers.push({ port, child, active: 0, requests: 0 });
}
function record(started, req, status, worker, error) {
    observations.push({ at: new Date().toISOString(), method: req.method, path: new URL(req.url, config.home).pathname,
        status, durationMs: Date.now() - started, workerPort: worker.port, ...(error ? { error } : {}) });
    fs.writeFileSync(path.join(run, 'http-observations.json'), JSON.stringify({ workers: workers.map(item => ({ port: item.port, pid: item.child.pid, requests: item.requests })),
        opcache: true, observations }, null, 2) + '\n', 'utf8');
}
const proxy = http.createServer((req, res) => {
    const worker = workers.reduce((best, item) => item.active < best.active || item.active === best.active && item.requests < best.requests ? item : best);
    worker.active++; worker.requests++;
    const started = Date.now(); let completed = false;
    const done = (status, error) => { if (completed) return; completed = true; worker.active--; record(started, req, status, worker, error); };
    const upstream = http.request({ hostname: '127.0.0.1', port: worker.port, method: req.method, path: req.url,
        headers: { ...req.headers, host: '127.0.0.1:33918', connection: 'close' } }, response => {
        res.writeHead(response.statusCode, response.headers);
        response.pipe(res);
        response.on('end', () => done(response.statusCode));
    });
    upstream.on('error', error => { done(502, error.code || error.name); if (!res.headersSent) res.writeHead(502); res.end(); });
    res.on('close', () => { if (!res.writableEnded) { done(499, 'client_closed'); upstream.destroy(); } });
    req.pipe(upstream);
});
let stopping = false;
function stop() { if (stopping) return; stopping = true; proxy.close(); for (const worker of workers) worker.child.kill(); fs.closeSync(log); }
process.on('SIGINT', () => { stop(); process.exit(0); });
process.on('SIGTERM', () => { stop(); process.exit(0); });
process.on('exit', stop);
async function warm(worker) {
    for (let attempt = 0; attempt < 40; attempt++) {
        try { const response = await fetch('http://127.0.0.1:' + worker.port + '/login', { headers: { Host: '127.0.0.1:33918' }, redirect: 'manual' });
            await response.arrayBuffer(); if (response.status === 200) return; }
        catch (_) { /* A real worker may still be binding its loopback socket. */ }
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw Error('Native PHP worker did not become ready: ' + worker.port);
}
Promise.all(workers.map(warm)).then(() => proxy.listen(33918, '127.0.0.1', () => console.log('Native WordPress HTTP ready: 4 PHP 8.3 workers, OPcache, unchanged cookies/nonces, shared MariaDB.'))).catch(error => { console.error(error.message); stop(); process.exit(1); });
