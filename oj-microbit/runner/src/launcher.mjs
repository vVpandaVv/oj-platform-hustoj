import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { StringDecoder } from 'node:string_decoder';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const DEFAULT_IMAGE = 'oj-microbit-worker:1.0.0-rc.9';

export function dockerJobArguments(image, name) {
  // Preserve the image's independent timeout ENTRYPOINT; do not override it.
  return ['run', '--pull=never', '--rm', '--interactive', '--name', name, '--network=none', '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--pids-limit=64', '--memory=384m', '--cpus=1', '--user=10001:10001', '--tmpfs=/tmp:rw,noexec,nosuid,size=16m', image, 'node', '--max-old-space-size=256', 'src/job.mjs'];
}

export function executeJob(payload, options = {}) {
  const mode = options.mode || 'docker';
  if (!['docker', 'process'].includes(mode)) throw new TypeError('Unknown sandbox mode.');
  const name = `oj-microbit-job-${randomUUID()}`;
  const image = options.image || DEFAULT_IMAGE;
  if (!/^[A-Za-z0-9][A-Za-z0-9_./:@-]{0,255}$/.test(image)) throw new TypeError('Invalid worker image name.');
  const command = mode === 'docker' ? 'docker' : process.execPath;
  const args = mode === 'docker'
    ? dockerJobArguments(image, name)
    : ['--max-old-space-size=256', resolve(root, 'src/job.mjs')];
  return new Promise((resolveJob, rejectJob) => {
    const env = mode === 'docker' ? { PATH: process.env.PATH, SystemRoot: process.env.SystemRoot, DOCKER_HOST: process.env.DOCKER_HOST, DOCKER_CONFIG: process.env.DOCKER_CONFIG, USERPROFILE: process.env.USERPROFILE, HOME: process.env.HOME }
      : { PATH: process.env.PATH, SystemRoot: process.env.SystemRoot, NODE_ENV: 'test' };
    const child = spawn(command, args, { cwd: root, env, shell: false, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
    let output = '', bytes = 0, finished = false;
    const decoder = new StringDecoder('utf8');
    const finish = (error, value) => {
      if (finished) return;
      finished = true;
      clearTimeout(timer);
      if (error) rejectJob(error); else resolveJob(value);
    };
    const terminate = (message, code) => {
      child.kill('SIGKILL');
      if (mode === 'docker') {
        const cleanup = spawn('docker', ['rm', '--force', name], { env, windowsHide: true, shell: false, stdio: 'ignore' });
        cleanup.on('error', () => {});
        cleanup.unref();
      }
      const error = new Error(message); error.code = code; finish(error);
    };
    const timer = setTimeout(() => terminate('Execution time limit exceeded.', 'execution_timeout'), options.timeoutMs || 40000);
    child.on('error', () => { const error = new Error('Sandbox unavailable.'); error.code = 'sandbox_unavailable'; finish(error); });
    child.stdout.on('data', chunk => {
      bytes += chunk.length;
      output += decoder.write(chunk);
      if (bytes > 4194304) terminate('Execution output limit exceeded.', 'output_limit');
    });
    child.stderr.on('data', () => {});
    child.on('close', code => {
      if (finished) return;
      output += decoder.end();
      try {
        const envelope = JSON.parse(output);
        if (envelope.error) {
          const error = new Error(envelope.error.message);
          error.code = envelope.error.code;
          error.exitCode = Number.isInteger(code) ? code : null;
          finish(error);
        } else if (code === 0 && envelope.result) finish(null, envelope.result);
        else { const error = new Error('The isolated worker did not return a result.'); error.code = 'worker_failed'; error.exitCode = Number.isInteger(code) ? code : null; finish(error); }
      } catch { const error = new Error('The isolated worker failed.'); error.code = 'worker_failed'; error.exitCode = Number.isInteger(code) ? code : null; finish(error); }
    });
    child.stdin.on('error', () => {});
    child.stdin.end(JSON.stringify(payload));
  });
}

export function inspectImage(image = DEFAULT_IMAGE) {
  return new Promise(resolveInspect => {
    const child = spawn('docker', ['image', 'inspect', image, '--format', '{{.Id}}'], { windowsHide: true, shell: false, stdio: ['ignore', 'ignore', 'ignore'] });
    const timer = setTimeout(() => { child.kill('SIGKILL'); resolveInspect(false); }, 3000);
    child.on('error', () => { clearTimeout(timer); resolveInspect(false); });
    child.on('close', code => { clearTimeout(timer); resolveInspect(code === 0); });
  });
}
