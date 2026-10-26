'use strict';

/*
 * Runs every check before a release:
 *   node tools/test-all.js [path/to/oj-platform]
 * (default oj-platform path: ../oj-integration-rebuild/oj-platform)
 */
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const platform = process.argv[2] || path.join(root, '..', 'oj-integration-rebuild', 'oj-platform');
const results = [];

function run(label, cmd, args, cwd) {
    const r = spawnSync(cmd, args, { cwd: cwd || root, encoding: 'utf8', shell: process.platform === 'win32' && cmd === 'npm' });
    const ok = r.status === 0;
    results.push([ok, label]);
    console.log((ok ? 'ok   ' : 'FAIL ') + label);
    if (!ok) console.log(String(r.stdout || '').split('\n').filter((l) => /FAIL|not ok|✖|Error|error/.test(l)).slice(0, 15).join('\n'), r.stderr || '');
}

function walk(dir, ext, out = []) {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        if (e.name === 'node_modules' || e.name.startsWith('.')) continue;
        const p = path.join(dir, e.name);
        if (e.isDirectory()) walk(p, ext, out);
        else if (p.endsWith(ext)) out.push(p);
    }
    return out;
}

// syntax
let bad = walk(root, '.php').filter((f) => spawnSync('php', ['-l', f], { encoding: 'utf8' }).status !== 0);
results.push([!bad.length, 'php -l (all files)']);
console.log((bad.length ? 'FAIL ' : 'ok   ') + 'php -l (all files)' + (bad.length ? ': ' + bad.join(', ') : ''));
bad = walk(root, '.js').filter((f) => {
    try { new (require('node:vm').Script)(fs.readFileSync(f, 'utf8')); return false; } catch { return true; }
});
results.push([!bad.length, 'js syntax (all files)']);
console.log((bad.length ? 'FAIL ' : 'ok   ') + 'js syntax (all files)' + (bad.length ? ': ' + bad.join(', ') : ''));

// tests
run('node engine (game-server npm test)', 'npm', ['test'], path.join(root, 'game-server'));
run('php validators, tickets, .env', 'php', ['tests/php/run.php']);
run('php built-in engine + relay', 'php', ['tests/php/engine.php']);
run('shared rule scenarios (php)', 'php', ['tests/php/scenarios.php']);
run('Edu Games link', 'php', ['tests/php/integration.php']);
if (fs.existsSync(path.join(platform, 'src', 'AddonApi', 'V1'))) {
    run('manifest vs real Addon API', 'php', ['tests/php/manifest.php', platform]);
} else {
    results.push([false, 'manifest vs real Addon API']);
    console.log('FAIL manifest vs real Addon API: oj-platform not found at ' + platform);
}
run('default bank builds', 'node', ['tools/build-bank.js']);

const failed = results.filter((r) => !r[0]).length;
console.log(failed ? `\n${failed} check(s) failed` : '\nall checks passed');
process.exit(failed ? 1 : 0);
