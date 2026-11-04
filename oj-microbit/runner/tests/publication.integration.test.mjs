import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { grade } from '../src/grade.mjs';

test('the server-generated blank starter compiles in PXT without distributing a working answer', async () => {
  const pluginRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
  const input = {
    title: '공개 전 빈 틀 검사', description: '', status: 'published', publicationReview: { starterMode: 'blank' },
    starterProject: { name: '학생 빈 틀', files: { 'main.ts': 'pins.digitalWritePin(DigitalPin.P1, 1)',
      'pxt.json': JSON.stringify({ name: '학생 빈 틀', dependencies: { core: '*' }, files: ['main.ts'] }) } },
    connections: [{ pin: 1, direction: 'output', signal: 'digital', device: 'led', label: 'LED' }],
    scenarios: [{ id: 'visible', name: 'LED 켜기', visible: true, durationMs: 500, inputs: [],
      assertions: [{ pin: 1, signal: 'digital', expected: 1, afterMs: 0, withinMs: 100, holdMs: 100, tolerance: 0 }] }],
  };
  const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:/php/php.exe' : 'php');
  const envelope = JSON.parse(execFileSync(php, [resolve(pluginRoot, 'tests/schema-bridge.php'), '--stdin'], {
    input: JSON.stringify({ mode: 'publicationReview', data: input }), encoding: 'utf8', timeout: 10000,
  }));
  assert.equal(envelope.ok, true, envelope.message);
  const exercise = envelope.data.studentPreview;
  assert.equal(exercise.starterProject.files['main.ts'], '');
  assert.ok(exercise.starterProject.files['main.blocks'].includes('<xml'));
  const result = await grade({ project: exercise.starterProject, exercise });
  assert.equal(result.status, 'graded');
  assert.equal(result.score, 0, 'The blank distribution must not contain the teacher answer.');
  assert.equal(result.passed, false);
  assert.equal(result.trace.length, 0, 'An unwritten initial pin state is not an output command.');
});
