'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { decode, write } = require('../src/setup');
const { plan, installSettings, readEnv } = require('../src/install-nginx');

const env = { ARENA_SECRET: 'k'.repeat(48), WP_API: 'https://oj.test/wp-json/oj-code-arena/v1', ALLOWED_ORIGINS: 'https://oj.test', PORT: '3200', HOST: '127.0.0.1', WS_PATH: '/arena' };
const code = (value) => 'oca1.' + Buffer.from(JSON.stringify({ v: 1, env: value })).toString('base64url');
const snippet = '/etc/nginx/snippets/oj-code-arena.conf';
const site = `# server { listen 443; server_name ignored; }
server { listen 80; server_name oj.test; return 301 https://$host$request_uri; }
server { listen 443 ssl; server_name other.test; location / { return 200 "other { site }"; } }
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name oj.test www.oj.test;
    include /etc/nginx/snippets/oj-realtime-hub.conf;
    location / { try_files $uri $uri/ /index.php?$args; }
}
`;

test('same-site installer targets the matching TLS vhost and keeps Teaching Tools intact', () => {
    const result = plan(env, site, snippet);
    assert.ok(result.site.startsWith(site.slice(0, site.lastIndexOf('}'))));
    assert.equal(result.site.match(/oj-code-arena\.conf/g).length, 1);
    assert.match(result.site, /include \/etc\/nginx\/snippets\/oj-realtime-hub.conf;/);
    assert.match(result.snippet, /location = \/arena/);
    assert.match(result.snippet, /location \^~ \/arena\//);
    assert.match(result.snippet, /127\.0\.0\.1:3200/);
    assert.doesNotMatch(result.snippet, /3210|classroom-live/);
    assert.deepEqual(plan(env, result.site, snippet), result);
});

test('legacy relative include is reused and proxy settings can be updated', () => {
    const text = site.replace('server_name oj.test www.oj.test;', 'server_name oj.test www.oj.test; include snippets/oj-code-arena.conf;');
    const result = plan({ ...env, PORT: '3250', WS_PATH: '/games/arena' }, text, snippet);
    assert.equal(result.site, text);
    assert.match(result.snippet, /127\.0\.0\.1:3250/);
    assert.match(result.snippet, /location = \/games\/arena/);
});

test('HTTP default server supported, unrelated or ambiguous sites fail closed', () => {
    const http = { ...env, ALLOWED_ORIGINS: 'http://oj.test', WP_API: 'http://oj.test/wp-json/oj-code-arena/v1' };
    assert.match(plan(http, 'server { listen 80 default_server; server_name _; }', snippet).site, /include/);
    assert.throws(() => plan(env, 'server { listen 443 ssl; server_name other.test; }', snippet), /identify/);
    assert.throws(() => plan(env, site + 'server { listen 443 ssl; server_name oj.test; }', snippet), /identify/);
    assert.throws(() => plan(env, 'server { listen 443 ssl;', snippet), /Unclosed/);
});

test('conflicting inline proxy, unsafe path, remote listen, or inconsistent site is rejected', () => {
    assert.throws(() => plan(env, site.replace('server_name oj.test www.oj.test;', 'server_name oj.test; location /arena { proxy_pass http://old; }'), snippet), /inline location/);
    for (const change of [
        { WS_PATH: '/arena;include' }, { WS_PATH: '/' }, { WS_PATH: '/a/../b' }, { HOST: '0.0.0.0' },
        { PORT: '65536' }, { PORT: '3200x' }, { PORT: '443' }, { WP_API: 'https://other.test/api' },
        { ALLOWED_ORIGINS: 'https://oj.test,https://other.test' },
    ]) assert.throws(() => installSettings({ ...env, ...change }));
    assert.throws(() => plan(env, site, '/etc/nginx/snippets/a;bad.conf'), /snippet path/);
    assert.throws(() => decode(code({ ...env, PORT: '65536' })), /PORT/);
    assert.throws(() => decode(code({ ...env, ARENA_SECRET: 'a\n' + 'k'.repeat(48) })), /ARENA_SECRET/);
});

test('setup update keeps tuning options, backs up old credentials, and refuses another site', (context) => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'oca-install-test-'));
    context.after(() => fs.rmSync(dir, { recursive: true, force: true }));
    write(env, dir);
    fs.appendFileSync(path.join(dir, '.env'), 'MAX_SESSIONS=42\n');
    const before = fs.readFileSync(path.join(dir, '.env'), 'utf8');
    write({ ...env, ARENA_SECRET: 'n'.repeat(48) }, dir);
    assert.equal(fs.readFileSync(path.join(dir, '.env.bak'), 'utf8'), before);
    assert.equal(readEnv(path.join(dir, '.env')).MAX_SESSIONS, '42');
    assert.equal(readEnv(path.join(dir, '.env')).ARENA_SECRET, 'n'.repeat(48));
    const helper = path.join(__dirname, '../src/install-nginx.js');
    assert.equal(spawnSync(process.execPath, [helper, '--existing', path.join(dir, '.env'), code(env)]).status, 0);
    assert.equal(spawnSync(process.execPath, [helper, '--existing', path.join(dir, '.env'), code({ ...env, WP_API: 'https://another.test/api' })]).status, 1);
});

test('installer bundles its own module and common runtime without requiring Teaching Tools', () => {
    const root = path.resolve(__dirname, '../..');
    const installer = fs.readFileSync(path.join(root, 'game-server/install.sh'), 'utf8');
    assert.match(installer, /common-server/);
    assert.doesNotMatch(installer, /teaching-tools/);
    const addon = JSON.parse(fs.readFileSync(path.join(root, 'realtime-addon.json'), 'utf8'));
    for (const file of addon.include) assert.ok(fs.existsSync(path.join(root, file)), file);
    assert.equal(addon.app, 'oj-code-arena');
    const shared = path.resolve(root, '../_shared/oj-realtime-hub');
    const common = fs.readFileSync(path.join(shared, 'install.sh'), 'utf8');
    assert.match(common, /trap rollback EXIT/);
    assert.match(common, /nginx -t/);
    assert.match(common, /--ignore-scripts/);
    assert.match(common, /src\/prepare.js" data/);
    const unit = fs.readFileSync(path.join(shared, 'deploy/oj-realtime-hub.service'), 'utf8');
    assert.match(unit, /ReadWritePaths=\/opt\/oj-realtime-hub\/modules/);
    assert.match(unit, /User=www-data/);
});
