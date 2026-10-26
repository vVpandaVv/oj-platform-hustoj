import assert from 'node:assert/strict';
import net from 'node:net';
import { spawn } from 'node:child_process';
import { performance } from 'node:perf_hooks';
import { readFile } from 'node:fs/promises';

const php = process.env.OJAI_PHP || 'php';
const onSocketError = (error) => {
  // The client intentionally closes rejected/truncated responses; Windows may send RST.
  if (error.code !== 'ECONNRESET') throw error;
};
const run = (endpoint, mode = 'probe') => new Promise((resolve, reject) => {
  const child = spawn(php, ['tests/fixtures/clamav-probe.php', endpoint, mode], { windowsHide: true });
  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (chunk) => { stdout += chunk; });
  child.stderr.on('data', (chunk) => { stderr += chunk; });
  child.on('error', reject);
  child.on('close', (code) => {
    if (code !== 0) reject(new Error(`Probe fixture failed: ${stderr}`));
    else {
      try { resolve(JSON.parse(stdout)); } catch (error) { reject(error); }
    }
  });
});

const cases = [
  ['responding', (socket) => socket.end('PONG\0'), true],
  ['fragmented', (socket) => { socket.write('PO'); setTimeout(() => socket.end('NG\0'), 30); }, true],
  ['invalid reply', (socket) => socket.end('ERROR\0'), false],
  ['truncated reply', (socket) => socket.end('PON'), false],
  ['missing terminator', (socket) => socket.end('PONG'), false],
  ['timeout', () => {}, false],
  ['slow trickle', (socket) => {
    [...'PONG\0'].forEach((byte, index) => {
      const timer = setTimeout(() => { if (!socket.destroyed) socket.write(byte); }, index * 700);
      timer.unref();
    });
  }, false],
];
for (const [name, respond, expected] of cases) {
  const requests = [];
  const sockets = new Set();
  const server = net.createServer((socket) => {
    sockets.add(socket);
    socket.on('error', onSocketError);
    socket.on('close', () => sockets.delete(socket));
    let command = Buffer.alloc(0);
    socket.on('data', (bytes) => {
      command = Buffer.concat([command, bytes]);
      if (command.includes(0)) { requests.push(command.toString()); respond(socket); }
    });
  });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  try {
    const start = performance.now();
    assert.deepEqual(await run(`tcp://127.0.0.1:${server.address().port}`), { responding: expected }, name);
    assert.deepEqual(requests, ['zPING\0'], `${name}: no file contents, paths, or scan commands`);
    assert.ok(performance.now() - start < 4000, `${name}: bounded response time`);
    console.log(`PASS scanner probe: ${name}`);
  } finally {
    for (const socket of sockets) socket.destroy();
    await new Promise((resolve) => server.close(resolve));
  }
}
for (const endpoint of ['https://127.0.0.1', 'tcp://example.invalid:3310', 'tcp://0.0.0.0:3310', 'unix:///tmp/../clamd.sock', 'tcp://127.0.0.1:0']) {
  assert.deepEqual(await run(endpoint), { responding: false });
}
console.log('PASS scanner probe: invalid and non-local endpoints rejected. This is a protocol fixture, not a real malware scan.');

const scanCases = [
  ['clean', (socket) => socket.end('stream: OK\0'), 'clean'],
  ['infected', (socket) => socket.end('stream: Test.Signature FOUND\0'), 'infected'],
  ['fragmented clean', (socket) => { socket.write('stream: O'); setTimeout(() => socket.end('K\0'), 30); }, 'clean'],
  ['missing terminator', (socket) => socket.end('stream: OK'), 'unavailable'],
  ['wrong prefix', (socket) => socket.end('error: OK\0'), 'unavailable'],
  ['scanner error', (socket) => socket.end('INSTREAM size limit exceeded. ERROR\0'), 'unavailable'],
  ['timeout', () => {}, 'unavailable'],
  ['slow trickle', (socket) => {
    [...'stream: OK\0'].forEach((byte, index) => {
      const timer = setTimeout(() => { if (!socket.destroyed) socket.write(byte); }, index * 250);
      timer.unref();
    });
  }, 'unavailable'],
];
const fixtureBytes = await readFile('tests/fixtures/clamav-probe.php');
for (const [name, respond, expected] of scanCases) {
  const sockets = new Set();
  let scanned = null;
  const server = net.createServer((socket) => {
    sockets.add(socket);
    socket.on('error', onSocketError);
    socket.on('close', () => sockets.delete(socket));
    let input = Buffer.alloc(0);
    socket.on('data', (bytes) => {
      input = Buffer.concat([input, bytes]);
      if (input.length < 10) return;
      assert.equal(input.subarray(0, 10).toString(), 'zINSTREAM\0');
      let offset = 10;
      const chunks = [];
      while (offset + 4 <= input.length) {
        const length = input.readUInt32BE(offset);
        if (offset + 4 + length > input.length) return;
        offset += 4;
        if (length === 0) { scanned = Buffer.concat(chunks); respond(socket); return; }
        chunks.push(input.subarray(offset, offset + length));
        offset += length;
      }
    });
  });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  try {
    const start = performance.now();
    assert.deepEqual(await run(`tcp://127.0.0.1:${server.address().port}`, 'scan'), { state: expected }, name);
    assert.deepEqual(scanned, fixtureBytes, `${name}: scans only this synthetic fixture`);
    assert.ok(performance.now() - start < 4000, `${name}: bounded scan duration`);
    console.log(`PASS scanner INSTREAM: ${name}`);
  } finally {
    for (const socket of sockets) socket.destroy();
    await new Promise((resolve) => server.close(resolve));
  }
}
