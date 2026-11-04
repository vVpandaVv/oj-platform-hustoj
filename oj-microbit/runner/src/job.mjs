import { grade } from './grade.mjs';

let bytes = 0;
const chunks = [];
try {
  for await (const chunk of process.stdin) {
    bytes += chunk.length;
    if (bytes > 2097152) throw new TypeError('Request is too large.');
    chunks.push(chunk);
  }
  const payload = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  const result = await grade(payload);
  process.stdout.write(JSON.stringify({ result }));
} catch (error) {
  process.stdout.write(JSON.stringify({ error: { code: error instanceof TypeError || error instanceof SyntaxError ? 'invalid_request' : 'execution_failed', message: String(error.message || error).slice(0, 1000) } }));
  process.exitCode = 1;
}
