'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const pluginRoot = path.resolve(__dirname, '..');
const workspace = path.resolve(pluginRoot, '..');
const installer = path.join(pluginRoot, 'runner', 'install.sh');
const harness = path.join(__dirname, 'lib', 'installer-harness.sh');
const candidates = [process.env.OJ_MICROBIT_TEST_BASH, ...(process.platform === 'win32'
  ? ['C:/Program Files/Git/bin/bash.exe', 'C:/msys64/usr/bin/bash.exe']
  : ['/bin/bash', '/usr/bin/bash'])].filter(Boolean);
const bash = candidates.find(candidate => fs.existsSync(candidate));
assert.ok(bash, 'Bash is required for installer syntax and non-privileged mock integration tests.');
const posix = value => value.replaceAll('\\', '/');

test('release version parsing accepts stable and explicit RC versions without unsafe suffixes', () => {
  for (const version of ['0.5.1', '1.0.0-rc.1', '10.20.30-rc.12']) {
    const result = runBash('source "$1"; valid_release_version "$2"', [version]);
    assert.equal(result.status, 0, version + result.stderr);
  }
  for (const version of ['1.0.0-alpha.1', '1.0.0-rc', '1.0.0-rc.01', '01.0.0', '1.0.0-rc.1;id', '1.0.0-rc.1/../escape']) {
    assert.notEqual(runBash('source "$1"; valid_release_version "$2"', [version]).status, 0, version);
  }
});

test('actual source validation keeps RC text in protected release paths and image tags', () => withFixture(root => {
  fs.copyFileSync(installer, path.join(root, 'source/install.sh'));
  fs.writeFileSync(path.join(root, 'source/package.json'), JSON.stringify({ version: '1.0.0-rc.1' }, null, 2) + '\n');
  const result = runBash('source "$2/source/install.sh"; INSTALL_ROOT="$2/opt/oj-microbit"; check_source; [[ $VERSION == 1.0.0-rc.1 && $IMAGE == oj-microbit-worker:1.0.0-rc.1 && $RUNNER_DIR == *"/1.0.0-rc.1/runner" ]]', [posix(root)]);
  assert.equal(result.status, 0, result.stderr);
}));

function runBash(body, args = []) {
  const result = spawnSync(bash, ['-c', body, 'installer-test', posix(installer), ...args], {
    cwd: workspace, encoding: 'utf8', timeout: 20000,
    env: { ...process.env, MSYS_NO_PATHCONV: '1', MSYS2_ARG_CONV_EXCL: '*' },
  });
  assert.ifError(result.error);
  return result;
}

function fixture() {
  const temporary = path.join(workspace, '_notes', 'tmp'); fs.mkdirSync(temporary, { recursive: true });
  const root = fs.mkdtempSync(path.join(temporary, 'microbit-install-test-'));
  for (const folder of ['source/src', 'source/install-tools', 'opt/node/bin', 'etc/systemd/system', 'wp', 'hustoj/docker-root']) { fs.mkdirSync(path.join(root, folder), { recursive: true }); }
  for (const [name, content] of Object.entries({ 'package.json': '{"version":"0.4.0"}', 'package-lock.json': '{}', Dockerfile: 'FROM fixture\n', '.dockerignore': 'node_modules\n', 'src/server.mjs': '// fixture source\n', 'install-tools/configure-wordpress.php': '<?php\n', 'install-tools/WordPressConfigurator.php': '<?php\n', 'install-tools/runner-check.mjs': '// fixture probe\n' })) {
    fs.writeFileSync(path.join(root, 'source', name), content);
  }
  fs.writeFileSync(path.join(root, 'version'), '0.4.0');
  fs.writeFileSync(path.join(root, 'opt/node/bin/node'), `#!/usr/bin/env bash\nif [[ $1 == --version ]]; then printf 'v24.22.1\\n'; else exec '${posix(process.execPath).replaceAll("'", "'\\''")}' "$@"; fi\n`, { mode: 0o755 });
  fs.writeFileSync(path.join(root, 'opt/node/bin/npm'), '#!/usr/bin/env bash\nif [[ ${FAIL_STAGE:-} == npm ]]; then exit 44; fi\nmkdir -p node_modules/pxt-microbit/built\nprintf "{}" > node_modules/pxt-microbit/built/target.json\n', { mode: 0o755 });
  return root;
}
function runFixture(root, action = 'install', fail = '', environment = {}) {
  const result = spawnSync(bash, [posix(harness), posix(installer), posix(root), posix(process.execPath), action], {
    cwd: workspace, encoding: 'utf8', timeout: 30000,
    env: { ...process.env, ...environment, FAIL_STAGE: fail, MSYS_NO_PATHCONV: '1', MSYS2_ARG_CONV_EXCL: '*' },
  });
  assert.ifError(result.error); return result;
}
const read = (root, name) => fs.readFileSync(path.join(root, name), 'utf8');
const exists = (root, name) => fs.existsSync(path.join(root, name));
function withFixture(operation) {
  const root = fixture();
  try { return operation(root); }
  finally {
    const actual = fs.realpathSync(root);
    const allowed = fs.realpathSync(path.join(workspace, '_notes', 'tmp'));
    assert.equal(fs.lstatSync(root).isSymbolicLink(), false, 'Fixture cleanup refuses symlinks.');
    assert.equal(path.dirname(actual), allowed, 'Fixture cleanup must remain inside the workspace temporary directory.');
    assert.ok(path.basename(actual).startsWith('microbit-install-test-'), 'Fixture cleanup accepts only this test prefix.');
    fs.rmSync(actual, { recursive: true, force: true });
  }
}

function seedHustoj(root) {
  const state = {
    'docker-root/containers/hustoj-web.json': { id: 'hustoj-web-123', image: 'hustoj/web:stable', running: true, restartCount: 0 },
    'docker-root/containers/hustoj-judge.json': { id: 'hustoj-judge-456', image: 'hustoj/judge:stable', running: true, restartCount: 0 },
    'docker-root/images/hustoj-web.json': { id: 'sha256:hustoj-web', tags: ['hustoj/web:stable'] },
    'docker-root/volumes/hustoj-db/_data/database.sql': 'HUSTOJ user submissions and problems\n',
    'docker-root/networks/hustoj-default.json': { id: 'hustoj-default-789', members: ['hustoj-web-123', 'hustoj-judge-456'] },
    'etc/docker/daemon.json': { 'live-restore': true, 'data-root': '/var/lib/docker' },
    'etc/apt/sources.list.d/docker.list': 'existing administrator-selected Docker repository\n',
    'etc/apt/keyrings/docker.asc': 'existing Docker repository key\n',
    'systemd/docker.state': { active: true, enabled: true, pid: 7777 },
  };
  for (const [name, content] of Object.entries(state)) {
    const target = path.join(root, 'hustoj', name); fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, typeof content === 'string' ? content : JSON.stringify(content));
  }
}
function hustojSnapshot(root) {
  const base = path.join(root, 'hustoj'); const snapshot = {};
  function visit(directory) {
    for (const name of fs.readdirSync(directory).sort()) {
      const target = path.join(directory, name); const stat = fs.lstatSync(target);
      assert.equal(stat.isSymbolicLink(), false);
      if (stat.isDirectory()) { visit(target); continue; }
      snapshot[path.relative(base, target)] = { hash: require('node:crypto').createHash('sha256').update(fs.readFileSync(target)).digest('hex'), mode: stat.mode, size: stat.size, mtime: stat.mtimeMs };
    }
  }
  visit(base); return snapshot;
}
function assertCoexistence(root, before) {
  assert.deepEqual(hustojSnapshot(root), before, 'Existing HUSTOJ containers/images/volumes/network, daemon configuration, repository and service state remain byte-for-byte unchanged.');
  const log = exists(root, 'commands.log') ? read(root, 'commands.log') : '';
  assert.doesNotMatch(log, /FORBIDDEN:|apt-get:|download:|docker:(?:stop|rm|rmi|restart|kill|prune|volume|network|compose|system)\b/);
  for (const line of log.split('\n').filter(item => item.startsWith('docker:'))) {
    assert.ok(/^docker:info(?:\s|$)/.test(line) || (/^docker:image inspect\b/.test(line) && /oj-microbit-worker:\d+\.\d+\.\d+$/.test(line)) || (/^docker:build\b/.test(line) && /--tag oj-microbit-worker:\d+\.\d+\.\d+\s/.test(line)), 'Docker command is restricted to daemon read-only info or the plugin-owned worker image: ' + line);
  }
  for (const line of log.split('\n').filter(item => item.startsWith('systemctl:'))) {
    assert.ok(line === 'systemctl:daemon-reload' || line.includes('oj-microbit-runner.service') || /^systemctl:is-active --quiet (?:docker\.service|docker\.socket|snap\.docker\.dockerd\.service)$/.test(line), 'Service operations target only the plugin service; Docker unit status is read-only: ' + line);
  }
  return log;
}

test('installer has valid Bash syntax and importing it has no side effects', () => {
  const syntax = spawnSync(bash, ['-n', posix(installer)], { cwd: workspace, encoding: 'utf8' });
  assert.ifError(syntax.error);
  assert.equal(syntax.status, 0, syntax.stderr);
  const imported = runBash('set -euo pipefail; source "$1"; printf "source-only-ok\\n"');
  assert.equal(imported.status, 0, imported.stderr);
  assert.equal(imported.stdout.trim(), 'source-only-ok');
});

test('help does not invoke any host mutation command', () => {
  const result = runBash(`
    set -euo pipefail
    source "$1"
    for name in apt-get curl docker systemctl mkdir install cp mv rm ln chmod chown useradd usermod; do
      eval "$name() { printf 'UNEXPECTED-MUTATION\\n' >&2; return 97; }"
    done
    main --help
  `);
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /--dry-run/);
  assert.match(result.stdout, /--check/);
  assert.doesNotMatch(result.stdout + result.stderr, /UNEXPECTED-MUTATION/);
});

test('unknown and incomplete flags fail before any host mutation', () => {
  for (const args of [['--unknown'], ['--port'], ['--wp-root'], ['--wp-user'], ['--dry-run', '--check']]) {
    const result = runBash(`
      set -euo pipefail
      source "$1"; shift
      for name in apt-get curl docker systemctl mkdir install cp mv rm ln chmod chown useradd usermod; do
        eval "$name() { printf 'UNEXPECTED-MUTATION\\n' >&2; return 97; }"
      done
      main "$@"
    `, args);
    assert.notEqual(result.status, 0, JSON.stringify(args));
    assert.doesNotMatch(result.stdout + result.stderr, /UNEXPECTED-MUTATION/, JSON.stringify(args));
  }
});

test('platform and strict argument validation reject unsupported hosts and shell-like values', () => {
  const platforms = runBash('source "$1"; supported_platform ubuntu 24.04 amd64 && ! supported_platform ubuntu 22.04 amd64 && ! supported_platform debian 24.04 amd64 && ! supported_platform ubuntu 24.04 arm64');
  assert.equal(platforms.status, 0, platforms.stderr);
  for (const args of [['--port', '0'], ['--port', '80'], ['--port', '65536'], ['--port', '3037; echo SHOULD_NOT_RUN'], ['--wp-root', '/var/www\nsite'], ['--wp-user', 'www-data;echo SHOULD_NOT_RUN']]) {
    const result = runBash('source "$1"; shift; parse_args "$@"', args);
    assert.notEqual(result.status, 0, JSON.stringify(args));
    assert.doesNotMatch(result.stdout, /SHOULD_NOT_RUN/);
  }
  const spaces = runBash('source "$1"; parse_args --wp-root "/var/www/a site" --wp-user www-data --port 4040; [[ $WP_ROOT == "/var/www/a site" && $PORT == 4040 ]]');
  assert.equal(spaces.status, 0, spaces.stderr);
});

test('dry-run never creates a lock, prerequisites, credentials, release, or WordPress settings', () => withFixture(root => {
  const result = runFixture(root, 'dry-run');
  assert.equal(result.status, 0, result.stderr);
  assert.equal(exists(root, 'commands.log'), false);
  for (const name of ['run', 'opt/oj-microbit', 'etc/oj-microbit', 'wordpress-setting']) { assert.equal(exists(root, name), false, name); }
}));

test('main clears inherited credential export attributes and disables allexport before any new key assignment', () => {
  const result = runBash(`
    set -Eeuo pipefail
    source "$1"
    export TOKEN=INHERITED_DUMMY_CREDENTIAL_111111111111111111111111
    export MICROBIT_RUNNER_TOKEN=INHERITED_DUMMY_CREDENTIAL_222222222222222222222222
    set -a
    main --dry-run
    [[ $- != *a* ]]
    TOKEN=REPLACEMENT_DUMMY_CREDENTIAL_333333333333333333333333
    "$2" -e 'if(Object.hasOwn(process.env,"TOKEN")||Object.hasOwn(process.env,"MICROBIT_RUNNER_TOKEN"))process.exit(1);process.stdout.write("credential-env-clean\\n")'
  `, [posix(process.execPath)]);
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /credential-env-clean/);
  assert.doesNotMatch(result.stdout + result.stderr, /INHERITED_DUMMY_CREDENTIAL|REPLACEMENT_DUMMY_CREDENTIAL/);
});

test('new installation stages source, gates on actual canary, and configures WordPress last', () => withFixture(root => {
  const result = runFixture(root);
  assert.equal(result.status, 0, result.stderr);
  const log = read(root, 'commands.log');
  assert.ok(log.indexOf('probe:canary') < log.indexOf('bridge:configure'));
  assert.match(read(root, 'etc/oj-microbit/runner.env'), /MICROBIT_RUNNER_HOST=127\.0\.0\.1/);
  assert.match(read(root, 'etc/oj-microbit/runner.env'), /MICROBIT_RUNNER_MODE=docker/);
  assert.match(read(root, 'etc/systemd/system/oj-microbit-runner.service'), /User=oj-microbit/);
  assert.match(read(root, 'etc/systemd/system/oj-microbit-runner.service'), /DOCKER_HOST=unix:\/\/\/var\/run\/docker\.sock/);
  assert.match(read(root, 'etc/systemd/system/oj-microbit-runner.service'), /Environment=PATH=.*\/usr\/local\/bin.*\/snap\/bin/);
  assert.doesNotMatch(read(root, 'etc/systemd/system/oj-microbit-runner.service'), /^Requires=.*docker\.service/m, 'Starting the plugin must not implicitly start another Docker service on snap/manual-daemon hosts.');
  assert.equal(read(root, 'wordpress-setting').trim(), 'http://127.0.0.1:3037');
  assert.equal(exists(root, 'opt/oj-microbit/0.4.0/runner/node_modules/pxt-microbit/built/target.json'), true);
  assert.doesNotMatch(log + result.stdout + result.stderr, /a{64}/, 'Credential must not appear in argv logs or installer output');
  assert.deepEqual(fs.readdirSync(path.join(root, 'opt/oj-microbit')).filter(name => name.startsWith('.install.')), []);
}));

test('rerun reuses credentials and immutable source, while an explicit port change is applied', () => withFixture(root => {
  assert.equal(runFixture(root).status, 0);
  const before = read(root, 'etc/oj-microbit/runner.env');
  const rerun = runFixture(root); assert.equal(rerun.status, 0, rerun.stderr); assert.match(rerun.stdout, /Reusing protected release/);
  assert.equal(read(root, 'etc/oj-microbit/runner.env'), before);
  const changed = runFixture(root, 'port'); assert.equal(changed.status, 0, changed.stderr);
  const tokenBefore = before.match(/MICROBIT_RUNNER_TOKEN=(.+)/)[1];
  assert.equal(read(root, 'etc/oj-microbit/runner.env').match(/MICROBIT_RUNNER_TOKEN=(.+)/)[1], tokenBefore);
  assert.equal(read(root, 'wordpress-setting').trim(), 'http://127.0.0.1:4040');
  fs.appendFileSync(path.join(root, 'source/src/server.mjs'), '// different source\n');
  const refused = runFixture(root); assert.notEqual(refused.status, 0); assert.match(refused.stderr, /different source/);
  assert.doesNotMatch(read(root, 'opt/oj-microbit/0.4.0/runner/src/server.mjs'), /different source/);
}));

test('a new version keeps the old release and a restorable environment/unit/settings backup', () => withFixture(root => {
  assert.equal(runFixture(root).status, 0);
  const oldUnit = read(root, 'etc/systemd/system/oj-microbit-runner.service');
  const oldEnv = read(root, 'etc/oj-microbit/runner.env');
  fs.writeFileSync(path.join(root, 'version'), '0.4.1'); fs.writeFileSync(path.join(root, 'source/package.json'), '{"version":"0.4.1"}');
  const result = runFixture(root); assert.equal(result.status, 0, result.stderr);
  assert.match(read(root, 'etc/systemd/system/oj-microbit-runner.service'), /0\.4\.1\/runner/);
  assert.equal(exists(root, 'opt/oj-microbit/0.4.0/runner/src/server.mjs'), true);
  const backups = fs.readdirSync(path.join(root, 'opt/oj-microbit/backups')).map(name => 'opt/oj-microbit/backups/' + name);
  const prior = backups.find(name => exists(root, name + '/runner.env') && read(root, name + '/runner.env') === oldEnv);
  assert.ok(prior); assert.equal(read(root, prior + '/oj-microbit-runner.service'), oldUnit);
  assert.equal(exists(root, prior + '/wordpress-option.json'), true);
}));

test('failed prerequisites/build/canary/WordPress connection preserve the prior configuration', () => {
  for (const fail of ['npm', 'build', 'restart', 'canary', 'wordpress']) { withFixture(root => {
    assert.equal(runFixture(root).status, 0);
    const beforeEnv = read(root, 'etc/oj-microbit/runner.env'); const beforeUnit = read(root, 'etc/systemd/system/oj-microbit-runner.service');
    fs.writeFileSync(path.join(root, 'version'), '0.4.1');
    fs.writeFileSync(path.join(root, 'source/package.json'), '{"version":"0.4.1"}');
    const result = runFixture(root, 'install', fail);
    assert.notEqual(result.status, 0, fail);
    assert.equal(read(root, 'etc/oj-microbit/runner.env'), beforeEnv, fail);
    assert.equal(read(root, 'etc/systemd/system/oj-microbit-runner.service'), beforeUnit, fail);
    assert.equal(exists(root, 'active'), true, fail);
    assert.equal(read(root, 'wordpress-setting').trim(), 'http://127.0.0.1:3037', fail);
    assert.equal(exists(root, 'opt/oj-microbit/0.4.0/runner/src/server.mjs'), true, fail);
    assert.deepEqual(fs.readdirSync(path.join(root, 'opt/oj-microbit')).filter(name => name.startsWith('.install.')), [], fail);
    if (['restart', 'canary', 'wordpress'].includes(fail)) { assert.match(result.stdout, /Restoring the previous/); }
  }); }
});

test('failed first activation removes its new config and never configures WordPress', () => withFixture(root => {
  const result = runFixture(root, 'install', 'canary'); assert.notEqual(result.status, 0);
  for (const name of ['etc/oj-microbit/runner.env', 'etc/systemd/system/oj-microbit-runner.service', 'wordpress-setting', 'active', 'enabled']) { assert.equal(exists(root, name), false, name); }
  assert.match(result.stdout, /Restoring the previous/);
  assert.doesNotMatch(read(root, 'commands.log'), /bridge:configure/);
}));

test('an existing worker tag with unknown source is preserved and refused', () => withFixture(root => {
  fs.writeFileSync(path.join(root, 'image-0.4.0'), 'unknown-source');
  const result = runFixture(root); assert.notEqual(result.status, 0); assert.match(result.stderr, /image tag already exists/);
  assert.equal(read(root, 'image-0.4.0'), 'unknown-source');
  assert.equal(exists(root, 'etc/oj-microbit/runner.env'), false);
  assert.doesNotMatch(read(root, 'commands.log'), /docker:build|bridge:configure/);
}));

test('installer lock contention fails before prerequisites or configuration writes', () => withFixture(root => {
  const result = runFixture(root, 'install', 'lock'); assert.notEqual(result.status, 0);
  assert.match(result.stderr, /already running/);
  const log = read(root, 'commands.log'); assert.doesNotMatch(log, /tools|docker:build|bridge:configure/);
  assert.equal(exists(root, 'opt/oj-microbit'), false);
}));

test('read-only check performs no canary, lock, WordPress configuration, or dependency installation', () => withFixture(root => {
  assert.equal(runFixture(root).status, 0); fs.writeFileSync(path.join(root, 'commands.log'), '');
  const before = read(root, 'etc/oj-microbit/runner.env');
  const result = runFixture(root, 'check'); assert.equal(result.status, 0, result.stderr);
  const log = read(root, 'commands.log');
  assert.match(log, /probe:health/); assert.match(log, /bridge:check/);
  assert.doesNotMatch(log, /flock|probe:canary|bridge:prepare|bridge:configure|tools|docker:build|systemctl:restart/);
  assert.equal(read(root, 'etc/oj-microbit/runner.env'), before);
}));

test('printable punctuation credentials round-trip without evaluation or argv exposure', () => withFixture(root => {
  const token = 'A'.repeat(40) + '"\\#/$`dummy`';
  fs.mkdirSync(path.join(root, 'etc/oj-microbit'), { recursive: true });
  fs.writeFileSync(path.join(root, 'etc/oj-microbit/runner.env'), 'MICROBIT_RUNNER_HOST=127.0.0.1\nMICROBIT_RUNNER_PORT=3037\nMICROBIT_RUNNER_TOKEN=' + JSON.stringify(token) + '\n');
  const first = runFixture(root); assert.equal(first.status, 0, first.stderr);
  const encoded = read(root, 'etc/oj-microbit/runner.env').split('\n').find(line => line.startsWith('MICROBIT_RUNNER_TOKEN=')).slice('MICROBIT_RUNNER_TOKEN='.length);
  assert.equal(JSON.parse(encoded), token);
  const second = runFixture(root); assert.equal(second.status, 0, second.stderr);
  assert.doesNotMatch(read(root, 'commands.log') + first.stdout + first.stderr + second.stdout + second.stderr, /A{40}/);
}));

test('real bridge and runner probe functions send secrets only on stdin', () => withFixture(root => {
  const token = 'B'.repeat(40) + '"\\#/$`dummy`';
  const checker = path.join(root, 'probe/install-tools'); fs.mkdirSync(checker, { recursive: true });
  fs.writeFileSync(path.join(checker, 'runner-check.mjs'), 'import crypto from "node:crypto"; let input=""; for await (const chunk of process.stdin) input+=chunk; console.log(JSON.stringify({args:process.argv.slice(2),hash:crypto.createHash("sha256").update(input).digest("hex")}));\n');
  const result = runBash(`
    set -Eeuo pipefail
    source "$1"
    fixture_root=$(cd "$2" && pwd -P)
    NODE_BIN=$3
    TOKEN=$4
    WP_ROOT="$fixture_root/wp root"
    WP_USER=wordpress
    URL=http://127.0.0.1:3037
    BRIDGE_PATH="$fixture_root/configure-wordpress.php"
    EXPECTED_SETTINGS_HASH=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
    ACTIVATING=true COMMITTED=false
    runuser() { printf '%s\\n' "$@" > "$fixture_root/bridge-argv"; cat > "$fixture_root/bridge-stdin"; printf '{"ok":true}'; }
    run_wp_bridge check
    [[ $COMMITTED == false ]]
    run_wp_bridge configure
    [[ $COMMITTED == true && $ACTIVATING == false ]]
    systemctl() { [[ $1 == is-active && $* == *docker.service* ]]; }
    probe_runner "$2/probe" health
  `, [posix(root), posix(process.execPath), token]);
  assert.equal(result.status, 0, result.stderr);
  const input = JSON.parse(read(root, 'bridge-stdin'));
  assert.equal(input.token, token); assert.equal(input.mode, 'configure'); assert.match(input.wpRoot, /wp root$/);
  assert.doesNotMatch(read(root, 'bridge-argv'), /B{40}/); assert.match(read(root, 'bridge-argv'), /wordpress/);
  const probe = JSON.parse(result.stdout);
  assert.equal(probe.hash, require('node:crypto').createHash('sha256').update(token).digest('hex'));
  assert.equal(probe.args[0], 'health'); assert.equal(probe.args[1], 'http://127.0.0.1:3037');
  assert.doesNotMatch(result.stdout + result.stderr, /B{40}/);
}));

test('bridge failure diagnostics allow only known-shaped codes and never dump output or credentials', () => {
  for (const output of ['{}', '{"error":"bad code CREDENTIAL-CONTENT"}', '{not-json', '{"error":"settings_changed"}']) {
    const result = runBash(`
      set -Eeuo pipefail
      source "$1"
      NODE_BIN=$2 TOKEN=CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC WP_ROOT=/fixture WP_USER=wordpress
      URL=http://127.0.0.1:3037 PHP_BIN=/fixture/php BRIDGE_PATH=/fixture/bridge.php
      response=$3
      runuser() { cat >/dev/null; printf '%s' "$response"; return 7; }
      run_wp_bridge check
    `, [posix(process.execPath), output]);
    assert.notEqual(result.status, 0);
    assert.doesNotMatch(result.stdout + result.stderr, /CREDENTIAL-CONTENT|C{40}|TypeError|ERR_INVALID_ARG_TYPE/);
    assert.match(result.stderr, output.includes('settings_changed') ? /settings_changed/ : /unknown_bridge_error/);
  }
});

test('real Docker reuse/tool policy preserves HUSTOJ through install, rerun, update, check, dry-run and rollback', () => withFixture(root => {
  seedHustoj(root); const before = hustojSnapshot(root);
  for (const action of ['install', 'install', 'check', 'dry-run']) {
    const result = runFixture(root, 'coexist-' + action); assert.equal(result.status, 0, result.stderr);
    assertCoexistence(root, before);
  }
  fs.writeFileSync(path.join(root, 'version'), '0.4.1'); fs.writeFileSync(path.join(root, 'source/package.json'), '{"version":"0.4.1"}');
  const update = runFixture(root, 'coexist-install'); assert.equal(update.status, 0, update.stderr);
  assertCoexistence(root, before);
  const priorEnv = read(root, 'etc/oj-microbit/runner.env');
  fs.writeFileSync(path.join(root, 'version'), '0.4.2'); fs.writeFileSync(path.join(root, 'source/package.json'), '{"version":"0.4.2"}');
  const failed = runFixture(root, 'coexist-install', 'canary'); assert.notEqual(failed.status, 0);
  assert.match(failed.stdout, /Restoring the previous/); assert.equal(read(root, 'etc/oj-microbit/runner.env'), priorEnv);
  const log = assertCoexistence(root, before);
  assert.match(log, /socket:local-docker/); assert.match(log, /ca-bundle:read-only/);
  assert.match(log, /docker:info/); assert.match(log, /df:/);
  assert.doesNotMatch(log, /dpkg-query:/, 'Existing working Docker does not enter the APT package-install branch.');
}));

test('missing prerequisites, unavailable Docker or missing local socket stop without changing the HUSTOJ daemon', () => {
  for (const [fail, environment, message] of [
    ['', { MISSING_TOOL: 'curl' }, /required tool 'curl' is missing/],
    ['ca-bundle', {}, /system CA bundle is missing/],
    ['docker-info', {}, /Existing Docker is not available/],
    ['socket', {}, /local Docker Engine socket is required/],
    ['', { MISSING_DOCKER_CLI: '1' }, /Existing or conflicting container packages/],
  ]) { withFixture(root => {
    seedHustoj(root); const before = hustojSnapshot(root);
    const result = runFixture(root, 'coexist-install', fail, environment);
    assert.notEqual(result.status, 0); assert.match(result.stderr, message);
    const log = assertCoexistence(root, before);
    assert.equal(exists(root, 'etc/oj-microbit/runner.env'), false);
    assert.doesNotMatch(log, /docker:build|systemctl:restart|bridge:configure/);
  }); }
});

test('foreign, unidentified and mixed listeners are refused without stopping HUSTOJ or the existing runner', () => {
  for (const owner of ['foreign', 'unknown']) { withFixture(root => {
    seedHustoj(root); const before = hustojSnapshot(root);
    const result = runFixture(root, 'coexist-install', '', { PORT_OWNER: owner });
    assert.notEqual(result.status, 0); assert.match(result.stderr, /Port 3037 .*in use|unidentified listener/);
    const log = assertCoexistence(root, before); assert.doesNotMatch(log, /bridge:configure|docker:build|systemctl:restart/);
  }); }
  withFixture(root => {
    seedHustoj(root); const before = hustojSnapshot(root);
    const installed = runFixture(root, 'coexist-install'); assert.equal(installed.status, 0, installed.stderr);
    const own = runFixture(root, 'coexist-install', '', { PORT_OWNER: 'self' }); assert.equal(own.status, 0, own.stderr);
    const env = read(root, 'etc/oj-microbit/runner.env'); const unit = read(root, 'etc/systemd/system/oj-microbit-runner.service');
    fs.writeFileSync(path.join(root, 'commands.log'), '');
    const mixed = runFixture(root, 'coexist-install', '', { PORT_OWNER: 'mixed' }); assert.notEqual(mixed.status, 0);
    assert.match(mixed.stderr, /belongs to another process/);
    const log = assertCoexistence(root, before); assert.doesNotMatch(log, /systemctl:restart|docker:build/);
    assert.equal(read(root, 'etc/oj-microbit/runner.env'), env); assert.equal(read(root, 'etc/systemd/system/oj-microbit-runner.service'), unit);
  });
  withFixture(root => {
    seedHustoj(root); const before = hustojSnapshot(root);
    const raced = runFixture(root, 'coexist-install', '', { PORT_OWNER: 'race' }); assert.notEqual(raced.status, 0);
    assert.match(raced.stderr, /Port 3037 .*in use/);
    const log = assertCoexistence(root, before); assert.match(log, /docker:build/);
    assert.doesNotMatch(log, /systemctl:restart|bridge:configure/);
    assert.equal(read(root, 'port-checks'), '2', 'Port ownership is checked again immediately before activation.');
    assert.equal(exists(root, 'etc/oj-microbit/runner.env'), false);
  });
});

test('unreadable port state and insufficient disk space fail before worker build and retain HUSTOJ data', () => {
  for (const fail of ['ss', 'disk', 'docker-disk']) { withFixture(root => {
    seedHustoj(root); const before = hustojSnapshot(root);
    const result = runFixture(root, 'coexist-install', fail); assert.notEqual(result.status, 0);
    assert.match(result.stderr, fail === 'ss' ? /Cannot inspect the runner port/ : /At least 4 GiB/);
    const log = assertCoexistence(root, before); assert.doesNotMatch(log, /docker:build|systemctl:restart|bridge:configure/);
    if (fail !== 'ss') {
      assert.equal(log.split('\n').filter(line => line.includes('DockerRootDir')).length, 1);
      assert.equal(log.split('\n').filter(line => line.startsWith('df:')).length, fail === 'disk' ? 1 : 2, 'Only the two filesystem summaries are read; storage trees are never scanned.');
    }
  }); }
});

test('rollback restores a legacy unit without restarting its Docker dependency when the daemon becomes unavailable', () => withFixture(root => {
  seedHustoj(root); const before = hustojSnapshot(root);
  const installed = runFixture(root, 'coexist-install'); assert.equal(installed.status, 0, installed.stderr);
  const unitPath = path.join(root, 'etc/systemd/system/oj-microbit-runner.service');
  const legacyUnit = read(root, 'etc/systemd/system/oj-microbit-runner.service').replace('[Unit]\n', '[Unit]\nRequires=docker.service\n');
  fs.writeFileSync(unitPath, legacyUnit); const oldEnv = read(root, 'etc/oj-microbit/runner.env');
  fs.writeFileSync(path.join(root, 'version'), '0.4.1'); fs.writeFileSync(path.join(root, 'source/package.json'), '{"version":"0.4.1"}');
  fs.writeFileSync(path.join(root, 'commands.log'), '');
  const failed = runFixture(root, 'coexist-install', 'legacy-docker-unavailable'); assert.notEqual(failed.status, 0);
  assert.match(failed.stdout, /Restoring the previous/);
  assert.equal(read(root, 'etc/systemd/system/oj-microbit-runner.service'), legacyUnit);
  assert.equal(read(root, 'etc/oj-microbit/runner.env'), oldEnv);
  const log = assertCoexistence(root, before);
  assert.equal(log.split('\n').filter(line => line === 'systemctl:restart oj-microbit-runner.service').length, 1, 'Activation may restart the new plugin service; rollback must not start the legacy Requires dependency.');
  assert.equal(exists(root, 'active'), false, 'A failed replacement runner is stopped while the restored legacy configuration waits for manual recovery.');
  assert.doesNotMatch(log, /bridge:configure/);
}));

test('a dormant socket-activated Docker daemon is not awakened by installer preflight', () => withFixture(root => {
  seedHustoj(root);
  fs.writeFileSync(path.join(root, 'hustoj/systemd/docker.state'), JSON.stringify({ active: false, enabled: true, pid: 0, socketActive: true }));
  const before = hustojSnapshot(root);
  const result = runFixture(root, 'coexist-install', '', { DORMANT_DOCKER: '1' }); assert.notEqual(result.status, 0);
  const log = assertCoexistence(root, before);
  assert.match(log, /systemctl:is-active --quiet docker\.socket/);
  assert.doesNotMatch(log, /docker:info|docker:build|systemctl:restart|bridge:configure/);
  assert.equal(exists(root, 'etc/oj-microbit/runner.env'), false);
}));

test('Docker activation status errors fail closed, while a running manual daemon with inactive units is reused', () => {
  for (const [fail, environment] of [['docker-status', {}], ['socket-status', { DOCKER_GENERIC_INACTIVE: '1' }]]) { withFixture(root => {
    seedHustoj(root); const before = hustojSnapshot(root);
    const result = runFixture(root, 'coexist-install', fail, environment); assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Cannot safely inspect Docker/);
    const log = assertCoexistence(root, before); assert.doesNotMatch(log, /docker:info|docker:build|systemctl:restart|bridge:configure/);
  }); }
  withFixture(root => {
    seedHustoj(root); const before = hustojSnapshot(root);
    const result = runFixture(root, 'coexist-install', '', { DOCKER_GENERIC_INACTIVE: '1' }); assert.equal(result.status, 0, result.stderr);
    const log = assertCoexistence(root, before); assert.match(log, /docker:info/);
  });
});
