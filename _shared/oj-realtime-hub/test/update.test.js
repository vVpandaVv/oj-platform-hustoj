'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { prepare, copy, refreshData } = require('../src/prepare');
const { existing, update } = require('../src/update');
const { createHost } = require('../src/host');
const { signature } = require('../src/protocol');
const { once } = require('node:events');

const repo = path.resolve(__dirname, '../../..');
const plugin = path.join(repo, 'teaching-tools');
const fixturePlugin = path.join(__dirname, 'fixtures/synthetic-addon');
const env = { HOST: '127.0.0.1', PORT: '3210', WS_PATH: '/classroom-live', ALLOWED_ORIGINS: 'https://school.test', REALTIME_SECRET: 't'.repeat(48) };
const code = (app, values) => 'ojhub1.' + Buffer.from(JSON.stringify({ v: 1, app, env: values })).toString('base64url');
function workspace(context) {
    const parent = path.join(repo, '_notes/tmp');
    fs.mkdirSync(parent, { recursive: true });
    const root = fs.mkdtempSync(path.join(parent, 'hub-update-'));
    context.after(() => fs.rmSync(root, { recursive: true, force: true }));
    return root;
}

test('noninteractive update preserves credentials, custom ports, tuning and other addon data', async (context) => {
    const root = workspace(context);
    const fixture = path.join(root, 'fixture'); fs.mkdirSync(fixture);
    prepare(fixture, path.join(root, 'absent'), fixturePlugin, code('fixture-addon', {
        HOST: '127.0.0.1', PORT: '3210', WS_PATH: '/fixture', ALLOWED_ORIGINS: env.ALLOWED_ORIGINS,
        FIXTURE_SECRET: 'a'.repeat(48),
    }));
    const installed = path.join(root, 'installed'); fs.mkdirSync(installed);
    const before = prepare(installed, fixture, plugin, code('teaching-tools', env));
    const old = before.apps.find((app) => app.id === 'teaching-tools');
    old.version = '1.0.0';
    old.path = '/custom-board';
    old.env.WS_PATH = '/custom-board';
    old.env.MAX_CONNECTIONS = '321';
    old.env.PORT = '4321';
    before.port = 4321;
    fs.writeFileSync(path.join(installed, 'config.json'), JSON.stringify(before));
    const data = path.join(installed, 'modules/fixture-addon/state/pending-results.jsonl');
    fs.writeFileSync(data, 'pending\n');
    const previousCode = fs.readFileSync(path.join(installed, 'config.json'));
    const stage = path.join(root, 'update'); fs.mkdirSync(stage);
    const next = update(stage, installed, plugin);
    const manifest = JSON.parse(fs.readFileSync(path.join(plugin, 'realtime-addon.json')));
    assert.deepEqual(next, { ...before, apps: before.apps.map((app) => app.id === old.id ? { ...app, version: manifest.version, adapter: manifest.adapter } : app) });
    assert.deepEqual(fs.readFileSync(path.join(installed, 'config.json')), previousCode, 'Staging must not change the active configuration');
    const source = 'modules/teaching-tools/realtime-server/src/hub.js';
    assert.deepEqual(fs.readFileSync(path.join(stage, source)), fs.readFileSync(path.join(plugin, 'realtime-server/src/hub.js')));
    fs.appendFileSync(data, 'later\n');
    refreshData(stage, installed);
    assert.equal(fs.readFileSync(path.join(stage, 'modules/fixture-addon/state/pending-results.jsonl'), 'utf8'), 'pending\nlater\n');
    const port = spawnSync(process.execPath, [path.join(__dirname, '../src/update.js'), 'port', installed, plugin], { encoding: 'utf8' });
    assert.equal(port.status, 0);
    assert.equal(port.stdout.trim(), '4321');
    assert.equal(port.stderr, '');
    assert(!port.stdout.includes(old.secret));
    const host = createHost(next, { root: stage });
    try {
        host.server.listen(0, '127.0.0.1');
        await once(host.server, 'listening');
        const time = String(Math.floor(Date.now() / 1000));
        const response = await fetch(`http://127.0.0.1:${host.server.address().port}${old.path}/status`, { headers: {
            'x-oj-realtime-time': time, 'x-oj-realtime-signature': signature(old.secret, 'GET', '/status', time),
        } });
        assert.equal(response.status, 200);
        const status = await response.json();
        assert.equal(status.authenticated, true);
        assert.equal(status.appVersion, manifest.version);
    } finally { await host.close(); }
});

test('legacy Teaching Tools env can update without reentering a secret', (context) => {
    const root = workspace(context);
    const installed = path.join(root, 'legacy'); fs.mkdirSync(installed);
    copy(path.join(plugin, 'realtime-server/src'), path.join(installed, 'src'));
    copy(path.join(plugin, 'realtime-server/package.json'), path.join(installed, 'package.json'));
    const values = { ...env, PORT: '4322', MAX_ROOMS: '27' };
    fs.writeFileSync(path.join(installed, '.env'), Object.entries(values).map(([key, value]) => key + '=' + value).join('\n'));
    const stage = path.join(root, 'updated'); fs.mkdirSync(stage);
    const next = update(stage, installed, plugin);
    assert.equal(next.port, 4322);
    assert.equal(next.apps[0].secret, env.REALTIME_SECRET);
    assert.deepEqual(next.apps[0].env, values);
    assert.equal(next.apps[0].adapter, 'realtime-server/src/realtime-adapter.js');
});

test('update fails closed for absent registration or changed data layout', (context) => {
    const root = workspace(context);
    assert.throws(() => existing(path.join(root, 'missing'), plugin), /initial installer/);
    const installed = path.join(root, 'installed'); fs.mkdirSync(installed);
    const config = prepare(installed, path.join(root, 'absent'), plugin, code('teaching-tools', env));
    config.apps[0].dataPath = 'private-data';
    fs.writeFileSync(path.join(installed, 'config.json'), JSON.stringify(config));
    assert.throws(() => existing(installed, plugin), /data layout/);
});
