'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createHash } = require('node:crypto');
const root = path.resolve(__dirname, '..');
const freeze = JSON.parse(fs.readFileSync(path.join(root, 'docs/feature-freeze-1.0.0-rc.9.json'), 'utf8'));
const sha = value => createHash('sha256').update(value).digest('hex');

test('reviewed public-problem and lesson files match the rc.9 freeze', () => {
  assert.ok(freeze.files.length >= 51);
  for (const entry of freeze.files) {
    assert.equal(sha(fs.readFileSync(path.join(root, entry.path))), entry.sha256, 'Frozen product file changed: ' + entry.path);
  }
});
test('product entrypoints differ only in release version metadata', () => {
  for (const entry of freeze.versionOnlyFiles) {
    const source = fs.readFileSync(path.join(root, entry.path), 'utf8').replaceAll('\r\n', '\n').replaceAll(freeze.candidateVersion, '<release-version>');
    assert.equal(sha(source), entry.normalizedSha256, 'Unreviewed product change outside version metadata: ' + entry.path);
  }
});
test('candidate metadata, schema and frozen grading dependencies stay coherent', () => {
  const version = freeze.candidateVersion;
  assert.equal(version, '1.0.0-rc.9');
  const main = fs.readFileSync(path.join(root, 'oj-microbit.php'), 'utf8');
  assert.ok(main.includes('Version: ' + version));
  assert.ok(main.includes("define('OJ_MICROBIT_VERSION', '" + version + "')"));
  const packageInfo = JSON.parse(fs.readFileSync(path.join(root, 'runner/package.json'), 'utf8'));
  const lock = JSON.parse(fs.readFileSync(path.join(root, 'runner/package-lock.json'), 'utf8'));
  assert.equal(packageInfo.version, version); assert.equal(lock.version, version); assert.equal(lock.packages[''].version, version);
  assert.deepEqual(packageInfo.dependencies, { 'pxt-microbit': '9.1.1', 'pxt-core': '13.0.1' });
  assert.ok(fs.readFileSync(path.join(root, 'runner/src/launcher.mjs'), 'utf8').includes("'oj-microbit-worker:" + version + "'"));
  assert.equal(freeze.databaseSchema, 5); assert.equal(freeze.gradingContract, 'oj-gpio/3');
  assert.equal(freeze.requiresPlatform, '2.5.9'); assert.equal(freeze.requiresAddonApi, '1.9.0');
  assert.ok(fs.readFileSync(path.join(root, 'plugin-package.php'), 'utf8').includes('oj-platform:^2.5.9'));
  assert.ok(fs.readFileSync(path.join(root, 'src/Addon/Registration.php'), 'utf8').includes("'^1.9'"));
  assert.ok(fs.readFileSync(path.join(root, 'src/Storage/Installer.php'), 'utf8').includes("SCHEMA_VERSION = '5'"));
});
test('previous RC freeze manifests are preserved and simulator changes remain excluded', () => {
  const previousBytes = fs.readFileSync(path.join(root, 'docs/feature-freeze-1.0.0-rc.8.json'));
  assert.equal(sha(previousBytes), freeze.previousRelease.freezeManifestSha256);
  const previous = JSON.parse(previousBytes);
  assert.equal(previous.candidateVersion, '1.0.0-rc.8'); assert.equal(previous.databaseSchema, 5);
  const earlierBytes = fs.readFileSync(path.join(root, 'docs/feature-freeze-1.0.0-rc.7.json'));
  assert.equal(sha(earlierBytes), previous.previousRelease.freezeManifestSha256);
  const files = new Map(freeze.files.map(entry => [entry.path, entry]));
  for (const entry of previous.files) {
    const candidate = files.get(entry.path);
    assert.ok(candidate, 'Frozen file removed without migration: ' + entry.path);
    if (candidate.sha256 !== entry.sha256) {
      assert.equal(candidate.previousSha256, entry.sha256);
      assert.ok(['design_fidelity', 'release_metadata'].includes(candidate.reviewScope), entry.path);
    } else { assert.equal(candidate.previousSha256, undefined); }
    if (entry.path.startsWith('runner/src/') || entry.path.startsWith('resources/vendor/')) {
      assert.equal(candidate.sha256, entry.sha256, 'Runtime change exceeds requested publication/lesson scope: ' + entry.path);
    }
  }
  for (const entry of freeze.files.filter(item => !previous.files.some(old => old.path === item.path))) {
    assert.ok(['design_fidelity', 'release_metadata'].includes(entry.reviewScope), 'Unreviewed product addition: ' + entry.path);
  }
});
