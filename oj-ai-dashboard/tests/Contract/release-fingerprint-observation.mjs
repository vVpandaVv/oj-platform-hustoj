import assert from 'node:assert/strict';
import { copyFile, mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const fixture = await mkdtemp(path.join(os.tmpdir(), 'ojai-fingerprint-'));
const platform = path.join(fixture, 'platform');
const script = path.join(fixture, 'scripts/fingerprint-release-boundaries.mjs');
try {
  for (const directory of ['scripts', 'src/Platform', 'src/Security', 'src/Database', 'src/Rest', 'platform/src/AddonApi/V1']) {
    await mkdir(path.join(fixture, directory), { recursive: true });
  }
  await copyFile(path.join(root, 'scripts/fingerprint-release-boundaries.mjs'), script);
  for (const file of ['src/Platform/Bridge.php', 'src/Security/Addon_Capabilities.php', 'src/Database/Schema.php', 'src/Rest/Controller.php', 'platform/src/AddonApi/V1/Contract.php']) {
    await writeFile(path.join(fixture, file), '<?php\n');
  }
  await writeFile(path.join(platform, 'oj-platform.php'), "<?php\ndefine('OJ_PLATFORM_ADDON_API_VERSION', '1.1.0');\n");
  async function version(value, header = value) {
    await writeFile(path.join(fixture, 'package.json'), JSON.stringify({ version: value }));
    await writeFile(path.join(fixture, 'oj-ai-dashboard.php'), `<?php\n/**\n * Version: ${header}\n */\ndefine( 'OJ_AI_DASHBOARD_VERSION', '${value}' );\n`);
  }
  function run(...args) {
    const result = spawnSync(process.execPath, [script, '--platform-root', platform, ...args], { encoding: 'utf8', timeout: 30000 });
    if (result.error) throw result.error;
    return result;
  }
  await version('1.2.3');
  const released = run();
  assert.equal(released.status, 0, released.stderr);
  const manifest = path.join(fixture, 'approved.json');
  await writeFile(manifest, released.stdout);
  assert.equal(run('--verify', manifest).status, 0);
  await version('1.2.4-rc.7');
  const candidate = run();
  assert.equal(candidate.status, 0, candidate.stderr);
  assert.equal(JSON.parse(candidate.stdout).addon_version, '1.2.4-rc.7');
  const rejected = run('--verify', manifest);
  assert.equal(rejected.status, 1);
  assert.match(rejected.stderr, /fingerprint drifted/);
  await version('1.2.3');
  await writeFile(path.join(fixture, 'src/Rest/Controller.php'), '<?php\n// Changed route contract.\n');
  const drifted = run('--verify', manifest);
  assert.equal(drifted.status, 1);
  assert.match(drifted.stderr, /rest_routes/);
  await version('1.2.3', '1.2.4');
  assert.match(run().stderr, /versions do not match/);
  for (const invalid of ['1.2', '1.2.3-rc', '1.2.3-rc.x', '1.2.3\n']) {
    await version(invalid);
    assert.notEqual(run().status, 0);
  }
  console.log('Release fingerprint observation PASS: stable and RC observation, version mismatch rejection, unapproved RC and route drift remain blocked.');
} finally {
  const parent = path.resolve(os.tmpdir());
  const target = path.resolve(fixture);
  assert.equal(path.dirname(target), parent);
  assert.ok(path.basename(target).startsWith('ojai-fingerprint-'));
  await rm(target, { recursive: true, force: true });
}
