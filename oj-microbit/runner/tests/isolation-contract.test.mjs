import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dockerJobArguments } from '../src/launcher.mjs';

test('Docker jobs retain independent image timeout and least-privilege launch settings', () => {
  const dockerfile = readFileSync(new URL('../Dockerfile', import.meta.url), 'utf8');
  const entrypoint = JSON.parse(dockerfile.match(/^ENTRYPOINT (.+)$/m)[1]);
  assert.deepEqual(entrypoint, ['/usr/bin/timeout', '--signal=KILL', '40s']);
  assert.match(dockerfile, /test -x \/usr\/bin\/timeout/);
  const args = dockerJobArguments('oj-microbit-worker:fixture', 'oj-microbit-job-fixture');
  assert.equal(args.includes('--entrypoint'), false);
  for (const option of ['--pull=never', '--rm', '--network=none', '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--pids-limit=64', '--memory=384m', '--cpus=1', '--user=10001:10001']) assert.ok(args.includes(option), option);
  assert.equal(args.some(option => ['--env', '-e', '--env-file', '--volume', '-v', '--mount', '--privileged'].includes(option)), false);
  assert.deepEqual(args.slice(args.indexOf('oj-microbit-worker:fixture') + 1), ['node', '--max-old-space-size=256', 'src/job.mjs']);
});
