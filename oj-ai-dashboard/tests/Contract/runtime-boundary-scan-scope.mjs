import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, writeFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const fixture = await mkdtemp(path.join(os.tmpdir(), 'ojai-scan-scope-'));
try {
  for (const name of ['check-worksheet-approval-command-s02.ps1', 'check-worksheet-publication-s03.ps1', 'check-submission-assessment-input-g4-imp-01-s02.ps1']) {
    const source = await readFile(path.join(root, 'scripts', name), 'utf8');
    const files = [...source.matchAll(/Join-Path \$root '([^']+\.php)'/g)].map((match) => match[1].replaceAll('\\', '/'));
    assert.ok(files.some((file) => file.startsWith('src/')) && files.some((file) => file.startsWith('tests/')));
    for (const file of files) {
      await mkdir(path.dirname(path.join(fixture, file)), { recursive: true });
      await writeFile(path.join(fixture, file), file.startsWith('tests/') ? '<?php // Negative controls mention $wpdb->prefix and wp_oj_ai_.\n' : '<?php\n');
    }
    const script = path.join(fixture, 'scripts', name);
    await mkdir(path.dirname(script), { recursive: true });
    await writeFile(script, source);
    const run = () => spawnSync('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', script], { encoding: 'utf8', timeout: 30000, windowsHide: true });
    const valid = run();
    assert.equal(valid.status, 0, valid.stderr || valid.stdout);
    const runtime = path.join(fixture, files.find((file) => file.startsWith('src/')));
    await writeFile(runtime, '<?php // forbidden runtime reference: $wpdb->prefix\n');
    const rejected = run();
    assert.notEqual(rejected.status, 0);
    assert.match(rejected.stderr + rejected.stdout, /Forbidden add-on table prefix/);
  }
  console.log('Runtime boundary scope PASS: three gates accept test negative controls and reject runtime prefix violations.');
} finally {
  assert.equal(path.dirname(fixture), path.resolve(os.tmpdir()));
  assert.ok(path.basename(fixture).startsWith('ojai-scan-scope-'));
  await rm(fixture, { recursive: true, force: true });
}
