// Loopback, memory-only HTTP fixture. The browser uses the shipped UI and real Microsoft editor.
import http from 'node:http';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { grade } from '../runner/src/grade.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(import.meta.url);
const { sampleExercise, servoExercise, i2cExercise, sequenceExercise } = require('../resources/js/microbit.js');
export function phpSchema(data, mode = 'project') {
  const binary = process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:\\php\\php.exe' : 'php');
  const output = execFileSync(binary, [resolve(root, 'tests/schema-bridge.php'), '--stdin'], { input: JSON.stringify({ mode, data }), encoding: 'utf8', timeout: 10000, maxBuffer: 1048576 });
  const result = JSON.parse(output); if (!result.ok) throw new Error(result.message + (result.cause ? ': ' + result.cause : '')); return result.data;
}
export function fixture(preset = 'gpio', options = {}) {
  const create = preset === 'sequence' ? sequenceExercise : preset === 'servo' ? servoExercise : preset === 'i2c' ? i2cExercise : sampleExercise;
  let exercise = { ...create(), id: 1, revision: 1, ownerId: 1, status: 'published', updatedAt: new Date().toISOString() };
  let saved = { project: exercise.starterProject, revision: 0, exerciseRevision: 1 };
  const attempts = [];
  for (let index = 1; index <= (options.attemptCount || 0); index++) attempts.push({ id: index, exerciseRevision: 1, exerciseId: 1, userId: 1, createdAt: '2026-10-28 00:00:00', result: { status: 'graded', score: 100, passed: true, scenarios: [], trace: Array.from({ length: 1000 }, (_, atMs) => ({ atMs, pin: 1, signal: 'digital', value: 1, kind: 'write' })) } });
  const requests = [];
  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    const send = (value, status = 200, type = 'application/json') => {
      response.writeHead(status, { 'Content-Type': type + '; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(type === 'application/json' ? JSON.stringify(value) : value);
    };
    try {
      if (url.pathname === '/favicon.ico') { send('', 204, 'text/plain'); return; }
      if (url.pathname === '/') {
        const config = JSON.stringify({ mode: url.searchParams.get('mode') || 'student', restUrl: '/api/', nonce: 'fixture-nonce',
          userId: 1, isAdmin: true, runnerReady: true, editorUrl: 'https://makecode.microbit.org/' }).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
        send('<!doctype html><html lang="ko"><meta charset="utf-8"><title>OJ Microbit browser fixture</title>'
          + '<style>body{font:16px system-ui;margin:20px;background:#f5f7fa}*{box-sizing:border-box}:root{--ojp-color-ink:#152333;--ojp-color-ink-2:#345;--ojp-color-primary:#2765c4;--ojp-color-surface:#fff;--ojp-color-elevated:#fff;--ojp-color-subtle:#eef2f7;--ojp-color-muted:#536273;--ojp-color-line:#cbd5df;--ojp-color-line-strong:#a6b4c5;--ojp-color-fill-strong:#dce4ee;--ojp-color-warning:#e0a200;--ojp-color-warning-bg:#fff2bd;--ojp-color-danger:#a21c32;--ojp-radius-sm:6px;--ojp-radius-md:10px;--ojp-radius-lg:14px;--ojp-radius-pill:999px;--ojp-text-subhead:14px;--ojp-font-mono:monospace}.ojp-button{padding:9px 14px;cursor:pointer}.ojp-input,.ojp-select,.ojp-textarea{padding:8px;width:100%}.ojp-field{display:grid;gap:6px}.ojp-table{width:100%;border-collapse:collapse}.ojp-table td,.ojp-table th{padding:8px;text-align:left}.ojp-table-wrap{overflow:auto}</style>'
          + '<link rel="stylesheet" href="/resources/css/microbit.css"><div class="ojmicrobit" data-oj-microbit data-config="' + config + '"></div>'
          + '<script src="/resources/vendor/microbit-usb.js"></script><script src="/resources/js/microbit.js"></script></html>', 200, 'text/html'); return;
      }
      if (['/resources/css/microbit.css', '/resources/js/microbit.js', '/resources/vendor/microbit-usb.js'].includes(url.pathname)) {
        send(readFileSync(resolve(root, '.' + url.pathname)), 200, url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript'); return;
      }
      if (!url.pathname.startsWith('/api/')) { send({ error: 'not_found' }, 404); return; }
      if (request.headers['x-wp-nonce'] !== 'fixture-nonce') { send({ message: 'nonce required' }, 403); return; }
      let body = '';
      for await (const chunk of request) { body += chunk; if (body.length > 1048576) throw new Error('Request limit'); }
      const input = body ? JSON.parse(body) : {};
      const path = url.pathname.slice(5);
      requests.push({ path, query: url.search, method: request.method });
      const injected = typeof options.respond === 'function' ? options.respond({ path, method: request.method, input, url }) : null;
      if (injected) { send(injected.body, injected.status || 200); return; }
      if (path === 'status') { send({ ready: true, message: '실제 PXT 테스트 실행기', runtimeVersion: 'pxt-microbit@9.1.1' }); return; }
      if (path === 'teacher/classes') {
        const classes = options.classes || [{ id: 7, contextId: 'class:7', title: '로봇 1반', revision: 'fixture-7', lifecycleState: 'active' },
          { id: 8, contextId: 'class:8', title: '로봇 2반', revision: 'fixture-8', lifecycleState: 'active' }];
        const offset = url.searchParams.get('cursor') ? Number(url.searchParams.get('cursor')) : 0;
        const limit = Number(url.searchParams.get('limit') || 20), items = classes.slice(offset, offset + limit);
        send({ available: options.classesAvailable !== false, items: options.classesAvailable === false ? [] : items,
          nextCursor: offset + items.length < classes.length ? String(offset + items.length) : null }); return;
      }
      if (path === 'teacher/exercises/review' && request.method === 'POST') { send(phpSchema(input, 'publicationReview')); return; }
      if (path === 'teacher/exercises' && request.method === 'POST') {
        if (input.id && input.revision !== exercise.revision) { send({ code: 'oj_microbit_conflict', message: '과제가 변경되었습니다.', data: { status: 409, requestId: '0123456789abcdef0123456789abcdef' } }, 409); return; }
        exercise = { ...phpSchema(input, 'publicationSave'), id: input.id || 1, ownerId: 1, revision: exercise.revision + 1 }; send(exercise); return;
      }
      const page = rows => { const before = Number(url.searchParams.get('beforeId') || 0), limit = Number(url.searchParams.get('limit') || 20), selected = rows.filter(row => !before || row.id < before).sort((a, b) => b.id - a.id), items = selected.slice(0, limit); return { items, nextBeforeId: selected.length > limit ? items.at(-1).id : null }; };
      if (path === 'teacher/exercises' || path === 'exercises') { send(page(Array.from({ length: options.exerciseCount || 1 }, (_, index) => ({ id: index + 1, title: exercise.title + (options.exerciseCount ? ' ' + (index + 1) : ''), status: exercise.status, revision: exercise.revision, ownerId: exercise.ownerId, updatedAt: exercise.updatedAt })))); return; }
      if (/^(teacher\/)?exercises\/1$/.test(path)) { send(path.startsWith('teacher/') ? exercise : { ...exercise, scenarios: exercise.scenarios.filter(item => item.visible) }); return; }
      if (path === 'exercises/1/project') {
        if (request.method === 'POST') {
          if (input.revision !== saved.revision) { send({ message: '프로젝트가 변경되었습니다.' }, 409); return; }
          saved = { project: phpSchema(input.project), revision: saved.revision + 1, exerciseRevision: exercise.revision };
        }
        send({ ...saved, project: phpSchema(saved.project) }); return;
      }
      if (/^exercises\/1\/(run|preview)$/.test(path)) {
        const spec = path.endsWith('preview') ? { ...exercise, status: 'draft', scenarios: [{ id: 'preview', name: '직접 실험', visible: true, durationMs: input.durationMs,
          inputs: input.inputs, assertions: [], i2cInputs: input.i2cInputs || [], i2cAssertions: [] }] } : exercise;
        const project = phpSchema(input.project), normalizedExercise = phpSchema(spec, 'exercise');
        const rawResult = await grade({ project, exercise: normalizedExercise });
        const result = { ...phpSchema({ project, exercise: normalizedExercise, result: rawResult }, 'result'),
          _visibility: Object.fromEntries(normalizedExercise.scenarios.map(item => [item.id, item.visible])) };
        if (path.endsWith('run')) attempts.push({ id: attempts.length + 1, userId: 1, exerciseRevision: exercise.revision, result, createdAt: new Date().toISOString() });
        send({ ...phpSchema(result, 'learnerResult'), id: attempts.length }); return;
      }
      if (/^(teacher\/)?exercises\/1\/attempts$/.test(path)) { send(page(attempts.map(row => ({ id: row.id, userId: row.userId, exerciseRevision: row.exerciseRevision, createdAt: row.createdAt, result: { status: row.result.status, score: row.result.score, passed: row.result.passed } })))); return; }
      if (/^(teacher\/)?exercises\/1\/attempts\/\d+$/.test(path)) { const record = attempts.find(row => row.id === Number(path.split('/').at(-1))); if (!record) { send({ message: 'Not found' }, 404); return; } send(path.startsWith('teacher/') ? record : { ...record, result: phpSchema(record.result, 'learnerResult') }); return; }
      send({ message: 'Not found' }, 404);
    } catch (error) {
      const match = /^(exercise(?:\.[A-Za-z0-9_]+)*|project(?:\.[A-Za-z0-9_]+)*|pxt\.json(?:\.[A-Za-z0-9_]+)*):/.exec(String(error.message));
      send({ code: match ? 'oj_microbit_invalid' : 'oj_microbit_unavailable', message: match ? '입력값과 시간 조건을 확인해 주세요.' : '테스트 실행을 완료하지 못했습니다.',
        data: { status: match ? 400 : 503, requestId: '0123456789abcdef0123456789abcdef', ...(match ? { fieldPath: match[1] } : {}) } }, match ? 400 : 503);
    }
  });
  return { server, saved: () => saved, exercise: () => exercise, attempts, requests };
}
