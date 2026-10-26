'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { once } = require('node:events');
const WebSocket = require('ws');
const { prepare, refreshData, nginxPlan, copy } = require('../src/prepare');
const { register, validateConfig } = require('../src/registration');
const { signature } = require('../src/protocol');
const { createHost } = require('../src/host');
const { issueTicket } = require('./fixtures/synthetic-addon/src/adapter');

const repo = path.resolve(__dirname, '../../..');
const origin = 'https://oj.test';
const tt = 'teaching-tools';
const fixture = 'fixture-addon';
const envs = {
    [tt]: { HOST: '127.0.0.1', PORT: '3210', WS_PATH: '/classroom-live', ALLOWED_ORIGINS: origin, REALTIME_SECRET: 't'.repeat(48) },
    [fixture]: { HOST: '127.0.0.1', PORT: '3210', WS_PATH: '/fixture', ALLOWED_ORIGINS: origin, FIXTURE_SECRET: 'a'.repeat(48) },
};
const code = (app, env = envs[app]) => 'ojhub1.' + Buffer.from(JSON.stringify({ v: 1, app, env })).toString('base64url');
const plugins = { [tt]: path.join(repo, tt), [fixture]: path.join(__dirname, 'fixtures/synthetic-addon') };
const manifest = (app) => JSON.parse(fs.readFileSync(path.join(plugins[app], 'realtime-addon.json'), 'utf8'));
function workspace(context) {
    const parent = path.join(repo, '_notes/tmp');
    fs.mkdirSync(parent, { recursive: true });
    const root = fs.mkdtempSync(path.join(parent, 'hub-test-'));
    context.after(() => fs.rmSync(root, { recursive: true, force: true }));
    return root;
}
function install(root, order) {
    let previous = path.join(root, 'absent');
    for (const [index, app] of order.entries()) {
        const stage = path.join(root, 'stage-' + index);
        fs.mkdirSync(stage);
        prepare(stage, previous, plugins[app], code(app));
        previous = stage;
    }
    return previous;
}
function message(socket, type) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => { socket.off('message', receive); reject(new Error('Timeout: ' + type)); }, 3000);
        function receive(raw) {
            const value = JSON.parse(raw.toString());
            if (value.t !== type) return;
            clearTimeout(timer); socket.off('message', receive); resolve(value);
        }
        socket.on('message', receive);
    });
}
async function connect(port, app, claims = {}, secret = null) {
    const socket = new WebSocket(`ws://127.0.0.1:${port}${envs[app].WS_PATH}`, { origin });
    await once(socket, 'open');
    const response = message(socket, app === tt ? 'ready' : 'ack');
    const now = Math.floor(Date.now() / 1000);
    const ticket = issueTicket(secret || envs[app][manifest(app).secretKey], {
        v: 1, uid: 1, role: 'host', app,
        channel: app === tt ? 'whiteboard' : 'sample', room: 'c12', sid: 12,
        iat: now, exp: now + 120, ...claims,
    });
    socket.send(JSON.stringify({ t: 'hello', rid: 1, ticket }));
    return { socket, response: await response };
}
async function request(port, app, route = '/status', body = null, signingSecret = null) {
    const time = String(Math.floor(Date.now() / 1000));
    const method = body === null ? 'GET' : 'POST';
    const raw = body === null ? '' : JSON.stringify(body);
    const headers = { 'content-type': 'application/json' };
    const prefix = 'X-OJ-Realtime';
    headers[prefix + '-Time'] = time;
    headers[prefix + '-Signature'] = signature(signingSecret || envs[app][manifest(app).secretKey], method, route, time, raw);
    const response = await fetch(`http://127.0.0.1:${port}${envs[app].WS_PATH}${route}`, { method, headers, ...(body === null ? {} : { body: raw }) });
    return { status: response.status, body: await response.json() };
}

for (const order of [[tt], [fixture], [tt, fixture], [fixture, tt]]) {
    test('independent runtime install and WebSocket operation: ' + order.join(' -> '), async (context) => {
        const root = workspace(context);
        const runtime = install(root, order);
        const config = JSON.parse(fs.readFileSync(path.join(runtime, 'config.json'), 'utf8'));
        assert.deepEqual(config.apps.map((app) => app.id).sort(), [...order].sort());
        assert.deepEqual(fs.readdirSync(path.join(runtime, 'modules')).sort(), [...order].sort());
        const host = createHost(config, { root: runtime });
        try {
            host.server.listen(0, '127.0.0.1');
            await once(host.server, 'listening');
            const port = host.server.address().port;
            for (const app of order) {
                const health = await request(port, app);
                assert.equal(health.status, 200);
                assert.equal(health.body.app, app);
                assert.equal(health.body.authenticated, true);
                assert.equal((await request(port, app, '/status', null, 'wrong-secret')).status, 401);
                if (app === fixture) {
                    const result = await request(port, app, '/api/rooms', { id: 12, title: '코드 수업', roster: [{ uid: 2, name: '학생' }] });
                    assert.equal(result.body.ok, true);
                }
                const client = await connect(port, app);
                assert.ok(client.response.role === 'host' || client.response.ok === true);
            }
            if (order.includes(tt)) {
                const hostClient = await connect(port, tt, { room: 'c13' });
                const viewer = await connect(port, tt, { uid: 2, role: 'viewer', room: 'c13' });
                const received = message(viewer.socket, 'snapshot');
                hostClient.socket.send(JSON.stringify({ t: 'snapshot', state: { text: '독립 판서' } }));
                assert.equal((await received).state.text, '독립 판서');
                await connect(port, tt, { channel: 'poll', room: 'c12' });
                assert.equal(host.rooms.channel(tt, 'whiteboard').size, 2);
                assert.equal(host.rooms.channel(tt, 'poll').size, 1);
            }
            if (order.length === 2) {
                assert.equal(host.rooms.channel(fixture, 'sample').size, 1);
                assert.notEqual(host.rooms.channel(tt, 'whiteboard').get('c12'), host.rooms.channel(fixture, 'sample').get('c12'));
                assert.equal((await request(port, tt, '/status', null, envs[fixture].FIXTURE_SECRET)).status, 401);
                const wrong = await connect(port, fixture, { app: tt });
                assert.equal(wrong.response.ok, false);
                assert.equal(wrong.response.why, 'bad-namespace');
            }
            const before = await fetch(`http://127.0.0.1:${port}/not-registered/status`);
            assert.equal(before.status, 404);
        } finally { await host.close(); }
    });
}

test('registration is order independent, isolated and rejects conflicting or unsafe configurations', () => {
    const a = register(register(null, manifest(tt), code(tt)), manifest(fixture), code(fixture));
    const b = register(register(null, manifest(fixture), code(fixture)), manifest(tt), code(tt));
    assert.deepEqual(a, b);
    assert.throws(() => register(a, manifest(fixture), code(tt)), /mismatch/);
    assert.throws(() => register(a, manifest(fixture), code(fixture, { ...envs[fixture], WS_PATH: '/classroom-live/fixture' })), /collision/);
    assert.throws(() => register(a, manifest(fixture), code(fixture, { ...envs[fixture], ALLOWED_ORIGINS: 'https://another.test' })), /different site/);
    for (const dataPath of ['../state', '/state', 'src/../state', 'src']) {
        const invalid = structuredClone(a); invalid.apps[0].dataPath = dataPath;
        assert.throws(() => validateConfig(invalid), /Invalid/);
    }
});

test('reinstall preserves other addon credentials, tuning and pending result files', (context) => {
    const root = workspace(context);
    const runtime = install(root, [fixture, tt]);
    const configFile = path.join(runtime, 'config.json');
    const config = JSON.parse(fs.readFileSync(configFile, 'utf8'));
    config.apps.find((app) => app.id === fixture).env.MAX_ITEMS = '42';
    fs.writeFileSync(configFile, JSON.stringify(config));
    const data = path.join(runtime, 'modules/fixture-addon/state');
    fs.writeFileSync(path.join(data, 'pending-results.jsonl'), 'pending\n');
    const stage = path.join(root, 'reinstall'); fs.mkdirSync(stage);
    const next = prepare(stage, runtime, plugins[tt], code(tt, { ...envs[tt], REALTIME_SECRET: 'n'.repeat(48) }));
    assert.deepEqual(next.apps.find((app) => app.id === fixture), config.apps.find((app) => app.id === fixture));
    assert.equal(next.apps.find((app) => app.id === tt).secret, 'n'.repeat(48));
    fs.appendFileSync(path.join(data, 'pending-results.jsonl'), 'later\n');
    refreshData(stage, runtime);
    assert.equal(fs.readFileSync(path.join(stage, 'modules/fixture-addon/state/pending-results.jsonl'), 'utf8'), 'pending\nlater\n');
    const third = path.join(root, 'fixture-update'); fs.mkdirSync(third);
    const updated = prepare(third, stage, plugins[fixture], code(fixture));
    assert.equal(updated.apps.find((app) => app.id === fixture).env.MAX_ITEMS, '42');
    assert.equal(updated.apps.find((app) => app.id === tt).secret, 'n'.repeat(48));
});

test('both nginx routes use the same service and rerunning is idempotent', (context) => {
    const root = workspace(context);
    const runtime = install(root, [fixture, tt]);
    const site = path.join(root, 'site');
    fs.writeFileSync(site, 'server { listen 443 ssl; server_name oj.test; location / { try_files $uri $uri/ /index.php; } }');
    const result = nginxPlan(runtime, site, '/etc/nginx/snippets');
    assert.equal(result.snippets.length, 2);
    for (const item of result.snippets) assert.match(item.text, /127\.0\.0\.1:3210/);
    fs.writeFileSync(site, result.site);
    assert.deepEqual(nginxPlan(runtime, site, '/etc/nginx/snippets'), result);
});

test('old Teaching Tools service migrates without needing its WordPress plugin during synthetic addon install', async (context) => {
    const root = workspace(context);
    const legacy = path.join(root, 'legacy'); fs.mkdirSync(legacy);
    copy(path.join(repo, tt, 'realtime-server/src'), path.join(legacy, 'src'));
    copy(path.join(repo, tt, 'realtime-server/package.json'), path.join(legacy, 'package.json'));
    fs.writeFileSync(path.join(legacy, '.env'), Object.entries(envs[tt]).map(([key, value]) => key + '=' + value).join('\n'));
    const stage = path.join(root, 'migrated'); fs.mkdirSync(stage);
    const config = prepare(stage, legacy, plugins[fixture], code(fixture));
    assert.equal(config.apps.find((app) => app.id === tt).adapter, 'legacy-relay.js');
    const host = createHost(config, { root: stage });
    try {
        host.server.listen(0, '127.0.0.1'); await once(host.server, 'listening');
        const port = host.server.address().port;
        assert.equal((await connect(port, tt)).response.role, 'host');
        assert.equal((await request(port, fixture)).status, 200);
    } finally { await host.close(); }
});

test('split UTF-8 HTTP chunks preserve the signed synthetic addon request', async (context) => {
    const root = workspace(context);
    const runtime = install(root, [fixture]);
    const config = JSON.parse(fs.readFileSync(path.join(runtime, 'config.json'), 'utf8'));
    const host = createHost(config, { root: runtime });
    try {
        host.server.listen(0, '127.0.0.1'); await once(host.server, 'listening');
        const raw = Buffer.from(JSON.stringify({ id: 12, title: '학생 수업' }));
        const time = String(Math.floor(Date.now() / 1000));
        const result = await new Promise((resolve, reject) => {
            const req = http.request({ host: '127.0.0.1', port: host.server.address().port, path: '/fixture/api/rooms', method: 'POST', headers: {
                'X-OJ-Realtime-Time': time, 'X-OJ-Realtime-Signature': signature(envs[fixture].FIXTURE_SECRET, 'POST', '/api/rooms', time, raw.toString()),
            } }, (res) => { let body = ''; res.on('data', (part) => { body += part; }); res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(body) })); });
            req.on('error', reject);
            const split = raw.indexOf(Buffer.from('학')) + 1;
            req.write(raw.subarray(0, split));
            setTimeout(() => req.end(raw.subarray(split)), 10);
        });
        assert.equal(result.status, 200);
        assert.equal(result.body.ok, true);
        assert.equal(host.rooms.channel(fixture, 'sample').get('c12').title, '학생 수업');
    } finally { await host.close(); }
});
