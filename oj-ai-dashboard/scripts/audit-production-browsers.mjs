import { spawnSync } from 'node:child_process';
import { mkdirSync, readdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const output = path.join(root, '_notes/production-browser-acceptance');
mkdirSync(output, { recursive: true });
const tests = readdirSync(path.join(root, 'scripts')).filter((name) => /^e2e-.+\.mjs$/.test(name)).sort();
tests.push('audit-student-runtime.mjs');
const results = [];
for (const name of tests) {
  const start = Date.now();
  const result = spawnSync(process.execPath, [path.join(root, 'scripts', name)], {
    cwd: root,
    env: process.env,
    encoding: 'utf8',
    timeout: 180000,
    maxBuffer: 16 * 1024 * 1024,
    windowsHide: true,
  });
  const entry = {
    test: name,
    status: result.status === 0 ? 'passed' : result.error?.code === 'ETIMEDOUT' ? 'timed_out' : 'failed',
    exitCode: result.status,
    durationMs: Date.now() - start,
    error: result.error?.message || null,
  };
  writeFileSync(path.join(output, `${name}.log`), `${result.stdout || ''}${result.stderr || ''}${entry.error || ''}`);
  results.push(entry);
  writeFileSync(path.join(output, 'results.json'), `${JSON.stringify({ generatedAt: new Date().toISOString(), results }, null, 2)}\n`);
  console.log(`${entry.status.toUpperCase()} ${name} (${entry.durationMs}ms)`);
}
console.log(JSON.stringify({ total: results.length, passed: results.filter((entry) => entry.status === 'passed').length, failed: results.filter((entry) => entry.status !== 'passed') }, null, 2));
process.exitCode = results.some((entry) => entry.status !== 'passed') ? 1 : 0;
