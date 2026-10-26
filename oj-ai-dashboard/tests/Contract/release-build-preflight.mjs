import assert from 'node:assert/strict';
import { copyFile, mkdir, mkdtemp, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const fixture = await mkdtemp(path.join(os.tmpdir(), 'ojai-release-preflight-'));
try {
  await mkdir(path.join(fixture, 'scripts'));
  await copyFile(path.join(root, 'scripts/build-release.ps1'), path.join(fixture, 'scripts/build-release.ps1'));
  async function version(value, packageVersion = value) {
    await writeFile(path.join(fixture, 'oj-ai-dashboard.php'), `<?php\n/**\n * Version: ${value}\n */\ndefine( 'OJ_AI_DASHBOARD_VERSION', '${value}' );\n`);
    await writeFile(path.join(fixture, 'package.json'), JSON.stringify({ version: packageVersion }));
  }
  function run(...args) {
    const result = spawnSync('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.join(fixture, 'scripts/build-release.ps1'), ...args], { encoding: 'utf8', timeout: 30000 });
    if (result.error) throw result.error;
    assert.notEqual(result.status, 0, 'Invalid release unexpectedly packaged.');
    return result.stdout + result.stderr;
  }
  await version('1.0.0-rc.1');
  assert.match(run(), /Production packages require a stable version/);
  await version('1.0.0', '0.9.0');
  assert.match(run(), /Package version must match package.json/);
  for (const invalid of [null, ['1.0.0'], { version: '1.0.0' }]) {
    await version('1.0.0', invalid);
    assert.match(run(), /Package version must match package.json/);
  }
  await version('1.0.0');
  assert.match(run('-ValidationPackage'), /Validation packages require a distinct/);
  for (const value of ['1.0', '1.0.0-beta.1', '1.0.0+build', 'release']) {
    await version(value);
    assert.match(run(), /Production packages require a stable version/);
  }
  await assert.rejects(stat(path.join(fixture, 'dist')), { code: 'ENOENT' });
  await version('1.0.0');
  await writeFile(path.join(fixture, 'scripts/fingerprint-release-boundaries.mjs'), 'console.error("fixture boundary drift"); process.exit(1);\n');
  for (const args of [
    ['init', '-q'], ['add', '.'],
    ['-c', 'user.name=Release Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'Isolated preflight fixture'],
  ]) {
    const git = spawnSync('git', ['-C', fixture, ...args], { encoding: 'utf8', timeout: 30000 });
    assert.equal(git.status, 0, git.stderr);
  }
  assert.match(run(), /Production packaging requires the exact approved release boundary fingerprint/);
  await assert.rejects(stat(path.join(fixture, 'dist')), { code: 'ENOENT' });
  const builder = await readFile(path.join(root, 'scripts/build-release.ps1'), 'utf8');
  const verify = builder.indexOf("--verify 'resources/contracts/release-boundary-fingerprint-v1.json'");
  assert.ok(verify > 0 && verify < builder.indexOf('$stagingRoot ='), 'Fingerprint must be checked before changing dist.');
  assert.ok(builder.includes('Production packaging requires the exact approved release boundary fingerprint.'));
  console.log('Release preflight PASS: RC production rejection, metadata mismatch, stable/validation separation and no staging side effects.');
} finally {
  assert.equal(path.dirname(fixture), path.resolve(os.tmpdir()));
  assert.ok(path.basename(fixture).startsWith('ojai-release-preflight-'));
  await rm(fixture, { recursive: true, force: true });
}
