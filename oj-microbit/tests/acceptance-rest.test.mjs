import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { LIMITS, canonicalBaseUrl, validateConfig, validatePrivateStat, runAcceptance, summarizeMetrics } from '../scripts/acceptance/rest-check.mjs';

const aliases = ['teacherA', 'teacherB', 'studentA', 'studentB'];
const users = { teacherA: 10, teacherB: 11, studentA: 20, studentB: 21 };
function accounts(nonces = true) { return Object.fromEntries(aliases.map(alias => [alias, { username: 'acceptance_user_' + alias, applicationPassword: 'very-private-password-' + alias, ...(nonces ? { restNonce: 'nonce_value_' + alias } : {}) }])); }
function config(baseUrl = 'https://trial.example/wordpress/') { return { baseUrl, testServer: true, accounts: accounts(), classIds: { teacherA: [7, 8], teacherB: [], studentA: [7, 8], studentB: [8] }, groupIds: { teacherA: [7], studentA: [7], studentB: [] }, load: { rps: 5, concurrency: 3, totalRequests: 6 }, timeoutMs: 2000, gradeTimeoutMs: 3000 }; }
const clone = value => JSON.parse(JSON.stringify(value));
const digest = value => createHash('sha256').update(JSON.stringify(value)).digest('hex');
function projectHash(project) { return digest({ name: project.name, files: Object.fromEntries(Object.entries(project.files).sort(([a], [b]) => Buffer.compare(Buffer.from(a), Buffer.from(b)))) }); }
function fingerprint(body) { const candidate = clone(body); delete candidate.publicationReview; return digest(candidate); }

async function fixture(options = {}) {
  const observed = [], records = new Map([[500, { id: 500, title: 'existing real exercise', ownerId: 10, revision: 9, status: 'published', audience: { mode: 'site', classIds: [] }, scenarios: [] }]]), projects = new Map(), lessonProjects = new Map(), assignments = new Map([[700, { id: 700, classId: 7, exerciseId: 500, exerciseRevision: 9, title: 'existing real assignment', ownerId: 10, revision: 9, archived: false }]]), attempts = [];
  let baseUrl, nextId = 1000, nextAssignmentId = 2000, gradeCalls = 0, busyAt = 0;
  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost'), path = url.pathname.replace(/^\/wp\//, ''), now = Date.now();
    let raw = ''; for await (const chunk of request) raw += chunk; const body = raw ? JSON.parse(raw) : null;
    const credentials = request.headers.authorization ? Buffer.from(request.headers.authorization.slice(6), 'base64').toString() : '';
    const alias = aliases.find(key => credentials === accounts()[key].username + ':' + accounts()[key].applicationPassword);
    observed.push({ path, method: request.method, alias, nonce: request.headers['x-wp-nonce'], authorization: Boolean(request.headers.authorization), body, at: now });
    const send = (status, data, headers = {}) => { response.writeHead(status, { 'Content-Type': 'application/json', ...headers }); response.end(JSON.stringify(data)); };
    const deny = status => send(status, { code: status === 401 ? 'rest_not_logged_in' : 'oj_microbit_access_denied', message: 'safe', data: { status } });
    if (path === 'wp-content/oj-microbit-acceptance.json') {
      if (options.markerDelay) { await new Promise(done => setTimeout(done, options.markerDelay)); }
      if (options.markerRedirect) { send(302, {}, { Location: options.markerRedirect }); return; }
      if (options.malformedMarker) { response.writeHead(200, { 'Content-Type': 'text/html' }); response.end('<html>not a trial marker</html>'); return; }
      send(options.noMarker ? 404 : 200, { testServer: true, marker: 'oj-microbit-acceptance-v1', baseUrl: options.badMarker ? 'https://production.example/' : baseUrl }); return;
    }
    if (url.pathname === '/judge/status.php') { send(200, { safe: true }); return; }
    if (!alias || options.unauthorized === alias) { deny(401); return; }
    if (path === 'wp-json/wp/v2/users/me') { send(200, { id: users[alias], capabilities: { manage_options: options.admin === alias } }); return; }
    if (request.headers['x-wp-nonce'] !== accounts()[alias].restNonce || options.expiredNonce === alias) { send(403, { code: 'oj_microbit_nonce_expired', message: 'secret must not be copied', data: { status: 403 } }); return; }
    const route = path.replace(/^wp-json\/oj-microbit\/v1\//, ''), teacher = alias.startsWith('teacher');
    if (route === 'status') { send(200, { ready: true, runtimeVersion: 'pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/3' }); return; }
    if (route.startsWith('teacher/') && !teacher) { deny(403); return; }
    if (route === 'teacher/exercises/review') {
      if (options.reviewFailure) { send(400, { code: 'oj_microbit_invalid_input', data: { status: 400 } }); return; }
      const candidate = clone(body); delete candidate.publicationReview;
      send(200, { fingerprint: fingerprint(body), studentPreview: { ...candidate, scenarios: candidate.scenarios.filter(row => row.visible) } }); return;
    }
    if (route === 'teacher/exercises' && request.method === 'POST') {
      if (!body.id && body.audience?.mode && body.audience.mode !== 'site') { send(400, { code: 'oj_microbit_invalid_input', data: { status: 400 } }); return; }
      if (body.id && records.get(body.id)?.ownerId !== users[alias]) { deny(403); return; }
      if (body.status === 'published' && (!body.publicationReview?.confirmed || body.publicationReview.fingerprint !== fingerprint(body))) { send(400, { code: 'oj_microbit_invalid_input', data: { status: 400 } }); return; }
      const id = body.id || nextId++, saved = { ...clone(body), id, ownerId: users[alias], revision: (records.get(id)?.revision || 0) + 1 }; delete saved.publicationReview; records.set(id, saved);
      send(200, options.returnExistingId && !body.id ? { ...saved, id: 500 } : saved); return;
    }
    if (route === 'teacher/exercises' || route === 'exercises' || route === 'public-exercises' || route === 'teacher/public-exercises') { send(200, { items: [...records.values()].filter(row => !row.archived && (route === 'teacher/exercises' || row.audience.mode === 'site' || route === 'exercises' && alias !== 'studentB')).slice(0, Number(url.searchParams.get('limit') || 20)).map(row => ({ id: row.id, title: row.title, status: row.status, revision: row.revision })), nextBeforeId: null }); return; }
    const createAssignment = /^teacher\/classes\/(\d+)\/assignments$/.exec(route);
    if (createAssignment && request.method === 'POST') {
      const classId = Number(createAssignment[1]), record = records.get(body.exerciseId);
      if (alias !== 'teacherA' || ![7, 8].includes(classId) || !record || record.archived || record.status !== 'published' || record.audience.mode !== 'site') { deny(403); return; }
      if ([...assignments.values()].some(row => !row.archived && row.classId === classId && row.exerciseId === record.id)) { send(409, { code: 'oj_microbit_conflict', data: { status: 409 } }); return; }
      const saved = { id: nextAssignmentId++, classId, exerciseId: record.id, exerciseRevision: record.revision, title: record.title, ownerId: users[alias], revision: 1, archived: false, updatedAt: '2026-11-03' };
      assignments.set(saved.id, saved); send(200, options.returnExistingAssignmentId ? { ...clone(saved), id: 700 } : clone(saved)); return;
    }
    const lessonMatch = /^(teacher\/)?assignments\/(\d+)(.*)$/.exec(route), match = /^(teacher\/)?exercises\/(\d+)(.*)$/.exec(route);
    if (!match && !lessonMatch) { send(404, {}); return; }
    const assignment = lessonMatch ? assignments.get(Number(lessonMatch[2])) : null;
    const asTeacher = Boolean((lessonMatch || match)[1]), suffix = (lessonMatch || match)[3];
    if (lessonMatch && (!assignment || assignment.archived || asTeacher && alias !== 'teacherA' || !asTeacher && alias === 'studentB' && assignment.classId === 7)) { deny(403); return; }
    const record = records.get(assignment ? assignment.exerciseId : Number(match[2])); if (!record || record.archived) { deny(403); return; }
    if (!assignment && (asTeacher && record.ownerId !== users[alias] || !asTeacher && ['classes', 'groups'].includes(record.audience.mode) && alias === 'studentB')) { deny(403); return; }
    if (assignment && !suffix) {
      if (request.method === 'DELETE') { if (!asTeacher) { deny(403); return; } assert.equal(body.revision, assignment.revision); assignment.archived = true; assignment.revision++; send(200, { deleted: true }); return; }
      send(200, { assignment: clone(assignment), exercise: { ...record, scenarios: record.scenarios.filter(row => row.visible) }, sourceAvailable: true }); return;
    }
    if (assignment && suffix === '/results') {
      if (!asTeacher) { deny(403); return; }
      const roster = assignment.classId === 7 ? [20] : [20, 21];
      const items = roster.map(userId => { const latest = attempts.filter(row => (row.assignmentId === assignment.id || options.inheritOtherLessonResult) && row.userId === userId).at(-1); return { userId, displayName: 'fixture learner', managementName: 'fixture name', latest: latest ? { id: latest.id, exerciseRevision: record.revision, result: { status: latest.result.status, passed: latest.result.passed, score: latest.result.score }, createdAt: '2026-11-03' } : null }; });
      send(200, { assignment: clone(assignment), items, nextCursor: null, contextRevision: 'context-revision-1', rosterRevision: 'roster-revision-1', classTitle: 'fixture lesson' }); return;
    }
    if (!suffix && request.method === 'DELETE') { if (options.cleanupFailure) { send(503, { code: 'oj_microbit_unavailable', data: { status: 503 } }); return; } assert.equal(body.revision, record.revision); record.archived = true; send(200, { deleted: true }); return; }
    if (!suffix) { send(200, asTeacher ? record : { ...record, scenarios: record.scenarios.filter(row => row.visible) }); return; }
    if (suffix === '/project') {
      const storage = assignment ? lessonProjects : projects, key = (assignment ? assignment.id : record.id) + ':' + alias, existing = storage.get(key);
      if (request.method === 'POST') { if ((existing?.revision || 0) !== body.revision) { send(409, { code: 'oj_microbit_conflict', data: { status: 409 } }); return; } storage.set(key, { project: clone(body.project), revision: body.revision + 1 }); }
      send(200, storage.get(key) || { project: record.starterProject, revision: 0 }); return;
    }
    const learnerResult = result => ({ ...result, scenarios: result.scenarios.map(row => row.id === 'hidden' ? { id: row.id, name: '비공개 테스트', passed: row.passed } : row) });
    if (suffix === '/run') {
      if (!assignment && body.assignmentId !== undefined) { send(400, { code: 'oj_microbit_invalid_input', data: { status: 400 } }); return; }
      gradeCalls++;
      if (options.busyLoad && gradeCalls === 6) { busyAt = Date.now(); send(429, { code: 'oj_microbit_busy', message: accounts().teacherA.applicationPassword, data: { status: 429, retryAfterSeconds: 1 } }); return; }
      if (options.gradeDelay) await new Promise(done => setTimeout(done, options.gradeDelay));
      const correct = body.project.files['main.ts'].includes('basic.forever'), result = { status: 'graded', score: correct ? 100 : 25, passed: correct, projectHash: projectHash(body.project), runtimeVersion: 'pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/3',
        scenarios: record.scenarios.map(row => ({ id: row.id, name: row.name, passed: correct, assertions: row.assertions })), trace: [{ atMs: 0, pin: 2, signal: 'pwm', value: 0, kind: 'write' }, { atMs: 280, pin: 2, signal: 'pwm', value: 800, kind: 'write' }], traceScenarioId: 'public', i2cTrace: [], diagnostics: [] };
      const id = attempts.length + 800; attempts.push({ id, exerciseId: record.id, assignmentId: assignment?.id || 0, userId: users[alias], result }); send(200, { ...(options.leakHidden ? result : learnerResult(result)), id }); return;
    }
    if (suffix === '/attempts') { send(200, { items: attempts.filter(row => row.exerciseId === record.id && (row.assignmentId === (assignment?.id || 0) || !assignment && options.leakLessonIntoGeneral) && (asTeacher || row.userId === users[alias])).map(row => ({ id: row.id, userId: row.userId, result: { status: row.result.status, score: row.result.score, passed: row.result.passed } })), nextBeforeId: null }); return; }
    if (/^\/attempts\/\d+$/.test(suffix)) { const attempt = attempts.find(row => row.id === Number(suffix.split('/').at(-1)) && row.exerciseId === record.id && row.assignmentId === (assignment?.id || 0)); if (!attempt || !asTeacher && attempt.userId !== users[alias]) { send(404, {}); return; } send(200, { ...attempt, result: asTeacher ? attempt.result : learnerResult(attempt.result) }); return; }
    send(404, {});
  });
  await new Promise(done => server.listen(0, '127.0.0.1', done)); const origin = 'http://127.0.0.1:' + server.address().port; baseUrl = origin + '/wp/';
  return { server, baseUrl, observed, records, assignments, attempts, busyAt: () => busyAt, config: () => ({ ...config(baseUrl), hustojProbe: { url: origin + '/judge/status.php', allowReadOnlyGet: true, baselineRequests: 2 } }), close: () => new Promise(done => { server.close(done); server.closeAllConnections(); }) };
}

test('config rejects unsafe origins, unbounded settings and non-test execution', () => {
  assert.equal(canonicalBaseUrl('https://trial.example/wordpress'), 'https://trial.example/wordpress/');
  for (const baseUrl of ['http://production.example/', 'http://127.0.0.1.evil/', 'https://user:password@trial.example/', 'https://trial.example/?secret=value']) assert.throws(() => validateConfig({ ...config(), baseUrl }));
  for (const load of [{ rps: 6 }, { concurrency: 5 }, { totalRequests: 61 }]) assert.throws(() => validateConfig({ ...config(), load }));
  assert.throws(() => validateConfig({ ...config(), testServer: false }));
  for (const ids of [[7, 7], [0], ['7'], Array.from({ length: 51 }, (_, index) => index + 1)]) assert.throws(() => validateConfig({ ...config(), groupIds: { teacherA: ids } }));
  const legacy = validateConfig({ ...config(), groupIds: { teacherA: [8] }, classIds: { teacherA: [7] } }); assert.deepEqual(legacy.groupIds.teacherA, [8]); assert.deepEqual(legacy.classIds.teacherA, [7]);
  for (const url of ['https://other.example/status', 'https://trial.example/submit.php', 'https://trial.example/rejudge.php', 'https://trial.example/admin/', 'https://trial.example/status?token=secret']) assert.throws(() => validateConfig({ ...config(), hustojProbe: { url, allowReadOnlyGet: true } }));
});
test('private config requires a regular UID-owned single-link 0600 POSIX file', () => {
  const stat = { uid: 1000, mode: 0o100600, size: 100, nlink: 1, isFile: () => true }; validatePrivateStat(stat, 1000, 'linux');
  for (const change of [{ mode: 0o100644 }, { uid: 1001 }, { nlink: 2 }, { size: 40000 }, { isFile: () => false }]) assert.throws(() => validatePrivateStat({ ...stat, ...change }, 1000, 'linux'));
  assert.throws(() => validatePrivateStat(stat, 1000, 'win32'));
});
test('bad or redirected test marker sends no account credentials and follows no redirects', async () => {
  const sink = await fixture(), wrong = await fixture({ markerRedirect: sink.baseUrl + 'stolen' });
  try { const report = await runAcceptance(wrong.config()); assert.equal(report.overall, 'FAIL'); assert(wrong.observed.every(row => !row.authorization)); assert.equal(sink.observed.length, 0); assert(report.checks.some(row => row.code === 'redirect_blocked'), JSON.stringify(report.checks)); }
  finally { await wrong.close(); await sink.close(); }
  const mismatch = await fixture({ badMarker: true }); try { const report = await runAcceptance(mismatch.config()); assert.equal(mismatch.observed.length, 1); assert.equal(mismatch.observed[0].authorization, false); assert(report.checks.some(row => row.code === 'test_server_marker_mismatch')); } finally { await mismatch.close(); }
  const malformed = await fixture({ malformedMarker: true }); try { const report = await runAcceptance(malformed.config()); assert(malformed.observed.every(row => !row.authorization)); assert(report.checks.some(row => row.code === 'invalid_json_response')); } finally { await malformed.close(); }
});
test('missing sessionless nonces stay PENDING and read mode cannot mutate records', async () => {
  const f = await fixture(); try { const input = f.config(); input.accounts = accounts(false); const report = await runAcceptance(input); assert.equal(report.overall, 'PENDING'); assert.equal(report.checks.filter(row => row.code === 'sessionless_rest_nonce_required').length, 4); assert(f.observed.every(row => row.method === 'GET')); assert.equal(f.records.size, 1); assert.equal(report.cleanup.length, 0); } finally { await f.close(); }
});
test('HTTP authentication 401 and nonce-expired 403 retain distinct safe error codes', async () => {
  const f = await fixture({ unauthorized: 'teacherB', expiredNonce: 'studentA' }); try { const report = await runAcceptance(f.config()); assert(report.checks.some(row => row.name === 'identity_teacherB' && row.code === 'http_401')); assert(report.checks.some(row => row.name === 'rest_studentA' && row.code === 'oj_microbit_nonce_expired')); assert(f.observed.every(row => row.method === 'GET')); } finally { await f.close(); }
});
test('full trial fixture checks public practice, lesson scopes, latest results and bounded parallel load', async () => {
  const f = await fixture({ busyLoad: true, gradeDelay: 230 }), checkpoints = [];
  try {
    const report = await runAcceptance(f.config(), { allowTestWrites: true, load: true, onCheckpoint: value => checkpoints.push(clone(value)) });
    for (const name of ['publication_review_and_ownership', 'project_restore_cas_and_owner_isolation', 'real_grade_hidden_history_and_tamper_rejection', 'public_practice_outside_lesson_allowed', 'new_public_problems_cannot_be_restricted', 'lesson_assignment_and_outside_access', 'lesson_project_and_latest_result_are_separate_from_general', 'two_lessons_and_unsubmitted_results_isolation', 'bounded_load']) assert.equal(report.checks.find(row => row.name === name)?.status, 'PASS', name);
    assert.equal(report.overall, 'PENDING'); assert.equal(report.cleanupMode, 'archived_only'); assert.equal(report.recordsRetained, true); assert.equal(report.cleanup.length, 1); assert.equal(report.assignmentCleanup.length, 2); assert(report.assignmentCleanup.every(row => row.archived)); assert(report.cleanup.every(row => row.archived)); assert.deepEqual(report.cleanupPending, []); assert(report.requestCount <= LIMITS.requests); assert(report.maximumActiveRequests <= 3); assert(report.maximumActiveRequests >= 2);
    assert.equal(report.metrics.grade.busy429, 1); assert.equal(f.records.get(500).revision, 9); assert.equal(f.records.get(500).title, 'existing real exercise'); assert.equal(f.assignments.get(700).revision, 9); assert.equal(f.assignments.get(700).archived, false);
    assert(!f.observed.some(row => row.method !== 'GET' && (row.body?.id === 500 || /exercises\/500/.test(row.path))));
    const starts = f.observed.map(row => row.at); for (let index = 1; index < starts.length; index++) assert(starts[index] - starts[index - 1] >= 170, 'RPS gate spacing');
    const afterBusy = f.observed.find(row => row.at > f.busyAt() + 10); assert(afterBusy.at - f.busyAt() >= 900, 'Retry-After applies before new requests');
    assert(f.observed.filter(row => row.path === 'judge/status.php').every(row => !row.authorization));
    assert(checkpoints.some(row => row.cleanup.length === 1 && !row.cleanup[0].archived));
    const serialized = JSON.stringify(report); for (const account of Object.values(f.config().accounts)) { assert(!serialized.includes(account.username)); assert(!serialized.includes(account.applicationPassword)); assert(!serialized.includes(account.restNonce)); }
    assert(!serialized.includes('basic.forever')); assert(!serialized.includes('private-' + report.runId)); assert(report.checks.some(row => row.name === 'hustoj_real_judging_parallel' && row.status === 'PENDING'));
    assert(f.observed.some(row => row.method === 'POST' && /teacher\/classes\/7\/assignments$/.test(row.path)));
    assert(f.observed.some(row => row.method === 'POST' && /teacher\/classes\/8\/assignments$/.test(row.path)));
    assert(report.checks.some(row => row.name === 'lesson_membership_revocation' && row.status === 'PENDING'));
  } finally { await f.close(); }
});
test('archive failure remains FAIL with only this invocation created IDs pending', async () => {
  const f = await fixture({ reviewFailure: true, cleanupFailure: true }); try { const report = await runAcceptance(f.config(), { allowTestWrites: true }); assert.equal(report.overall, 'FAIL'); assert.deepEqual(report.cleanupPending, [1000]); assert.equal(report.cleanup[0].archived, false); assert.equal(f.records.get(500).revision, 9); assert(report.checks.some(row => row.name === 'cleanup_archive_1000' && row.status === 'FAIL')); } finally { await f.close(); }
});

test('missing lesson IDs never invent scoped acceptance from legacy group IDs', async () => {
  const f = await fixture();
  try {
    const input = f.config(); delete input.classIds;
    const report = await runAcceptance(input, { allowTestWrites: true });
    assert.equal(report.checks.find(row => row.name === 'public_practice_outside_lesson_allowed')?.status, 'PASS');
    assert.equal(report.checks.find(row => row.name === 'lesson_assignment_and_outside_access')?.status, 'PENDING');
    assert(!f.observed.some(row => /classes\/\d+\/assignments$/.test(row.path)));
    assert.deepEqual(report.assignmentCleanup, []); assert.equal(report.cleanup.length, 1); assert(report.cleanup.every(row => row.archived));
  } finally { await f.close(); }
});
test('lesson-scoped acceptance detects general-history leakage and inherited unsubmitted results', async () => {
  const f = await fixture({ leakLessonIntoGeneral: true, inheritOtherLessonResult: true });
  try {
    const report = await runAcceptance(f.config(), { allowTestWrites: true });
    assert.equal(report.checks.find(row => row.name === 'lesson_project_and_latest_result_are_separate_from_general')?.code, 'general_history_contains_lesson_attempts');
    assert.equal(report.checks.find(row => row.name === 'two_lessons_and_unsubmitted_results_isolation')?.code, 'unsubmitted_lesson_inherited_general_or_other_results');
    assert.equal(report.overall, 'FAIL'); assert(report.assignmentCleanup.every(row => row.archived));
  } finally { await f.close(); }
});
test('an unverified assignment response never archives an existing lesson assignment', async () => {
  const f = await fixture({ returnExistingAssignmentId: true });
  try {
    const report = await runAcceptance(f.config(), { allowTestWrites: true });
    assert.equal(report.overall, 'FAIL'); assert.equal(report.creationOutcomeUncertain, true); assert.deepEqual(report.assignmentCleanup, []);
    assert.equal(f.assignments.get(700).revision, 9); assert.equal(f.assignments.get(700).archived, false);
    assert(!f.observed.some(row => row.method !== 'GET' && /assignments\/700(?:$|\?)/.test(row.path)));
  } finally { await f.close(); }
});
test('an unverified create response never permits update or archive of an existing exercise', async () => {
  const f = await fixture({ returnExistingId: true }); try { const report = await runAcceptance(f.config(), { allowTestWrites: true }); assert.equal(report.overall, 'FAIL'); assert.equal(report.creationOutcomeUncertain, true); assert.deepEqual(report.cleanup, []); assert(!f.observed.some(row => row.method !== 'GET' && (row.body?.id === 500 || /exercises\/500/.test(row.path)))); assert.equal(f.records.get(500).revision, 9); } finally { await f.close(); }
});
test('request timeout blocks authenticated work; load cannot silently authorize writes', async () => {
  const f = await fixture({ markerDelay: 250 }); try { const input = f.config(); input.timeoutMs = 100; const report = await runAcceptance(input); assert(report.checks.some(row => row.code === 'request_timeout')); assert(f.observed.every(row => !row.authorization)); await assert.rejects(runAcceptance(f.config(), { load: true }), /load_requires_write_authorization/); } finally { await f.close(); }
});
test('wall-clock budget ends the run without starting requests after its normal-work cutoff', async () => {
  const f = await fixture(); try { const input = f.config(); input.durationMs = 1000; const started = Date.now(), report = await runAcceptance(input, { allowTestWrites: true }); assert(Date.now() - started < 1500); assert(report.checks.some(row => row.code === 'time_budget_exhausted')); assert(f.observed.every(row => row.method === 'GET')); assert(report.requestCount <= LIMITS.requests); } finally { await f.close(); }
});
test('an already cancelled run never sends credentials or starts any HTTP request', async () => {
  const f = await fixture(); try { const controller = new AbortController(); controller.abort(); const report = await runAcceptance(f.config(), { signal: controller.signal, allowTestWrites: true }); assert.equal(report.overall, 'FAIL'); assert.equal(f.observed.length, 0); assert(report.checks.some(row => row.name === 'run_interrupted')); } finally { await f.close(); }
});
test('metric percentiles and CLI invalid arguments never reflect credential-like argv', () => {
  assert.deepEqual(summarizeMetrics([{ label: 'get', ms: 10, status: 200 }, { label: 'get', ms: 20, status: 429 }, { label: 'get', ms: 30, status: 503 }]).get, { requests: 3, p50Ms: 20, p95Ms: 30, busy429: 1, errors: 1 });
  const secret = 'password-should-never-be-printed', result = spawnSync(process.execPath, [fileURLToPath(new URL('../scripts/acceptance/rest-check.mjs', import.meta.url)), '--application-password=' + secret], { encoding: 'utf8' }); assert.equal(result.status, 1); assert(!result.stdout.includes(secret)); assert.equal(JSON.parse(result.stdout).code, 'invalid_arguments');
});
