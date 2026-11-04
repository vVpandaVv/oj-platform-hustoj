#!/usr/bin/env node
import { open } from 'node:fs/promises';
import { constants } from 'node:fs';
import { randomUUID, createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';

const ALIASES = ['teacherA', 'teacherB', 'studentA', 'studentB'];
export const LIMITS = Object.freeze({ requests: 200, cleanupReserve: 12, concurrency: 4, rps: 5, loadRequests: 60, durationMs: 300000, responseBytes: 2097152 });
const RUNTIME = 'pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/3';
const API_CODES = new Set(['unauthorized', 'access_denied', 'forbidden', 'nonce_expired', 'busy', 'unavailable', 'connection_failed', 'timeout', 'version_mismatch', 'isolation_mismatch', 'invalid_response', 'runner_auth_failed', 'invalid_input', 'conflict', 'not_found', 'not_configured', 'rejected'].map(code => 'oj_microbit_' + code));
function apiCode(data, status) { return API_CODES.has(data?.code) ? data.code : 'http_' + status; }
class CheckError extends Error { constructor(code) { super(code); this.code = code; } }
function requireThat(value, code) { if (!value) throw new CheckError(code); }
function integer(value, fallback, minimum, maximum) { value ??= fallback; requireThat(Number.isInteger(value) && value >= minimum && value <= maximum, 'invalid_config_limit'); return value; }
function safeCode(error) { return error instanceof CheckError ? error.code : 'unexpected_failure'; }

export function canonicalBaseUrl(value) {
  requireThat(typeof value === 'string' && value.length <= 2048, 'invalid_base_url');
  let url; try { url = new URL(value); } catch { throw new CheckError('invalid_base_url'); }
  const loopback = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
  requireThat((url.protocol === 'https:' || url.protocol === 'http:' && loopback) && !url.username && !url.password && !url.search && !url.hash, 'unsafe_base_url');
  if (!url.pathname.endsWith('/')) url.pathname += '/'; return url.href;
}
export function validateConfig(input) {
  requireThat(input && typeof input === 'object' && input.testServer === true, 'test_server_confirmation_required');
  const baseUrl = canonicalBaseUrl(input.baseUrl), accounts = {};
  for (const alias of ALIASES) {
    const account = input.accounts?.[alias];
    requireThat(account && typeof account.username === 'string' && account.username.length > 0 && account.username.length <= 120 && !/[\x00-\x1f\x7f:]/.test(account.username), 'invalid_account_config');
    requireThat(typeof account.applicationPassword === 'string' && account.applicationPassword.length >= 8 && account.applicationPassword.length <= 256 && !/[\x00-\x1f\x7f]/.test(account.applicationPassword), 'invalid_account_config');
    requireThat(account.restNonce === undefined || typeof account.restNonce === 'string' && /^[A-Za-z0-9_-]{8,64}$/.test(account.restNonce), 'invalid_rest_nonce');
    accounts[alias] = { username: account.username, applicationPassword: account.applicationPassword, restNonce: account.restNonce };
  }
  requireThat(new Set(ALIASES.map(alias => accounts[alias].username)).size === 4, 'four_distinct_test_accounts_required');
  const classIds = {}, groupIds = {};
  for (const [field, destination] of [['classIds', classIds], ['groupIds', groupIds]]) {
    for (const alias of ALIASES) {
      const ids = input[field]?.[alias];
      requireThat(ids === undefined || Array.isArray(ids) && ids.length <= 50 && ids.every(id => Number.isSafeInteger(id) && id > 0) && new Set(ids).size === ids.length, field === 'groupIds' ? 'invalid_group_ids' : 'invalid_class_ids');
      destination[alias] = ids;
    }
  }
  const load = input.load || {}, rps = load.rps ?? 2;
  requireThat(typeof rps === 'number' && Number.isFinite(rps) && rps >= 0.2 && rps <= LIMITS.rps, 'invalid_config_limit');
  let hustojProbe = null;
  if (input.hustojProbe) {
    const probe = input.hustojProbe; let url; try { url = new URL(probe.url, baseUrl); } catch { throw new CheckError('unsafe_hustoj_probe'); }
    requireThat(probe.allowReadOnlyGet === true && ['https:', 'http:'].includes(url.protocol) && url.hostname === new URL(baseUrl).hostname && !url.username && !url.password && !url.hash && !url.search && (url.pathname === '/' || /\/(?:status|problemset|index)\.php$/i.test(url.pathname)), 'unsafe_hustoj_probe');
    requireThat(probe.maxP95Ratio === undefined || typeof probe.maxP95Ratio === 'number' && probe.maxP95Ratio >= 1 && probe.maxP95Ratio <= 10, 'invalid_probe_threshold');
    hustojProbe = { url: url.href, baselineRequests: integer(probe.baselineRequests, 3, 1, 10), maxP95Ratio: probe.maxP95Ratio };
  }
  return { baseUrl, testServer: true, accounts, classIds, groupIds, hustojProbe,
    timeoutMs: integer(input.timeoutMs, 15000, 100, 20000), gradeTimeoutMs: integer(input.gradeTimeoutMs, 70000, 100, 80000),
    durationMs: integer(input.durationMs, LIMITS.durationMs, 1000, LIMITS.durationMs),
    load: { concurrency: integer(load.concurrency, 2, 1, LIMITS.concurrency), rps, totalRequests: integer(load.totalRequests, 20, 1, LIMITS.loadRequests) } };
}
export function validatePrivateStat(stat, uid = process.getuid?.(), platform = process.platform, minimumSize = 1) {
  requireThat(platform !== 'win32' && Number.isInteger(uid), 'posix_private_config_required');
  requireThat(stat.isFile() && stat.nlink === 1 && stat.uid === uid && (stat.mode & 0o777) === 0o600 && stat.size >= minimumSize && stat.size <= 32768, 'private_config_permissions_required');
}
export async function readPrivateConfig(path) {
  let handle;
  try { handle = await open(path, constants.O_RDONLY | (constants.O_NOFOLLOW || 0)); validatePrivateStat(await handle.stat()); return validateConfig(JSON.parse(await handle.readFile('utf8'))); }
  catch (error) { if (error instanceof CheckError) throw error; throw new CheckError('private_config_unreadable'); }
  finally { await handle?.close(); }
}
function percentile(values, fraction) { if (!values.length) return null; const sorted = values.slice().sort((a, b) => a - b); return Math.round(sorted[Math.max(0, Math.ceil(sorted.length * fraction) - 1)] * 100) / 100; }
export function summarizeMetrics(metrics) {
  const result = {};
  for (const label of new Set(metrics.map(item => item.label))) {
    const rows = metrics.filter(item => item.label === label), times = rows.map(item => item.ms);
    result[label] = { requests: rows.length, p50Ms: percentile(times, 0.5), p95Ms: percentile(times, 0.95), busy429: rows.filter(item => item.status === 429).length, errors: rows.filter(item => item.error || item.status >= 400 && item.status !== 429).length };
  }
  return result;
}
class Client {
  constructor(config, options) { this.config = config; this.options = options; this.fetch = options.fetch || globalThis.fetch; this.started = Date.now(); this.nextSlot = 0; this.pauseUntil = 0; this.pacing = Promise.resolve(); this.count = 0; this.metrics = []; this.markerAccepted = false; this.active = 0; this.maxActive = 0; this.cancelled = options.signal?.aborted === true; this.controllers = new Set();
    options.signal?.addEventListener('abort', () => { this.cancelled = true; for (const item of this.controllers) if (!item.cleanup) item.controller.abort(); }, { once: true });
  }
  async pace(cutoff, cleanup) {
    const turn = this.pacing.then(async () => {
      while (true) {
        requireThat(cleanup || !this.cancelled, 'run_cancelled');
        const now = Date.now(), slot = Math.max(this.nextSlot, cleanup ? 0 : this.pauseUntil);
        requireThat(Math.max(now, slot) < cutoff, 'time_budget_exhausted');
        if (slot <= now) break;
        try { await sleep(slot - now, undefined, { signal: cleanup ? undefined : this.options.signal }); } catch { throw new CheckError('run_cancelled'); }
      }
      this.nextSlot = Date.now() + 1000 / this.config.load.rps;
    });
    this.pacing = turn.catch(() => {}); await turn;
  }
  async request(path, { alias, method = 'GET', body, label = 'plugin_get', cleanup = false, probe = false } = {}) {
    requireThat(cleanup || !this.cancelled, 'run_cancelled');
    requireThat(method === 'GET' || this.options.allowTestWrites === true, 'writes_not_authorized');
    requireThat(!alias || this.markerAccepted, 'marker_guard');
    requireThat(this.count < LIMITS.requests - (cleanup ? 0 : LIMITS.cleanupReserve), 'request_budget_exhausted');
    const cutoff = this.started + this.config.durationMs - (cleanup ? 0 : Math.min(30000, this.config.durationMs / 4));
    requireThat(Date.now() < cutoff, 'time_budget_exhausted');
    const url = probe ? new URL(path) : new URL(path, this.config.baseUrl), base = new URL(this.config.baseUrl);
    requireThat(probe ? !alias && method === 'GET' && this.config.hustojProbe?.url === url.href : url.origin === base.origin && url.pathname.startsWith(base.pathname), 'origin_guard');
    this.count++; await this.pace(cutoff, cleanup);
    requireThat(cleanup || !this.cancelled, 'run_cancelled'); requireThat(Date.now() < cutoff, 'time_budget_exhausted');
    const headers = { Accept: 'application/json' };
    if (alias) { const account = this.config.accounts[alias]; headers.Authorization = 'Basic ' + Buffer.from(account.username + ':' + account.applicationPassword).toString('base64'); if (account.restNonce) headers['X-WP-Nonce'] = account.restNonce; }
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const controller = new AbortController(), timerItem = { controller, cleanup }; this.controllers.add(timerItem);
    const timeout = setTimeout(() => controller.abort(), Math.min(cutoff - Date.now(), label === 'grade' ? this.config.gradeTimeoutMs : this.config.timeoutMs)), started = performance.now();
    this.active++; this.maxActive = Math.max(this.maxActive, this.active); let status = 0, errorCode = null;
    try {
      const response = await this.fetch(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), redirect: 'manual', signal: controller.signal }); status = response.status;
      requireThat(status < 300 || status >= 400, 'redirect_blocked');
      const chunks = []; let bytes = 0;
      if (response.body) for await (const chunk of response.body) { bytes += chunk.length; requireThat(bytes <= LIMITS.responseBytes, 'response_too_large'); if (!probe) chunks.push(Buffer.from(chunk)); }
      let data = null; if (!probe) { try { data = JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { throw new CheckError('invalid_json_response'); } }
      if (status >= 400) errorCode = apiCode(data, status);
      const retry = data?.data?.retryAfterSeconds ?? Number(response.headers.get('retry-after'));
      if (!cleanup && [429, 503].includes(status) && Number.isInteger(retry) && retry >= 1 && retry <= 300) this.pauseUntil = Math.max(this.pauseUntil, Date.now() + retry * 1000);
      return { status, data, requestId: /^[a-f0-9]{32}$/i.test(data?.requestId || data?.data?.requestId || '') ? data.requestId || data.data.requestId : null };
    } catch (error) { errorCode = error instanceof CheckError ? error.code : controller.signal.aborted ? 'request_timeout' : 'connection_failed'; throw new CheckError(errorCode); }
    finally { clearTimeout(timeout); this.controllers.delete(timerItem); this.active--; this.metrics.push({ label, alias: alias || null, status, error: errorCode, ms: Math.round((performance.now() - started) * 100) / 100 }); }
  }
  api(path, alias, method, body, extra = {}) { return this.request('wp-json/oj-microbit/v1/' + path, { alias, method, body, ...extra }); }
}
function testExercise(runId, audience = { mode: 'site', classIds: [] }) {
  const name = 'OJMB acceptance ' + runId, source = 'basic.forever(function () {\n    let value = pins.analogReadPin(AnalogPin.P0)\n    pins.digitalWritePin(DigitalPin.P1, value >= 512 ? 1 : 0)\n    pins.analogWritePin(AnalogPin.P2, value)\n    basic.pause(20)\n})\n';
  const project = { name, files: { 'main.ts': source, 'pxt.json': JSON.stringify({ name, dependencies: { core: '*' }, files: ['main.ts'], preferredEditor: 'tsprj' }) } };
  const scenario = (id, visible, values) => ({ id, name: visible ? 'public' : 'private-' + runId, visible, durationMs: 500,
    inputs: values.map(([atMs, value]) => ({ atMs, pin: 0, signal: 'analog', value })),
    assertions: values.flatMap(([afterMs, expected]) => [{ pin: 1, signal: 'digital', expected: expected >= 512 ? 1 : 0, afterMs, withinMs: 100, holdMs: 50, tolerance: 0 }, { pin: 2, signal: 'pwm', expected, afterMs, withinMs: 100, holdMs: 50, tolerance: 0 }]), i2cInputs: [], i2cAssertions: [], outputSequences: [] });
  return { title: name, description: 'Temporary acceptance exercise; archive after the run.', status: 'draft', audience,
    connections: [{ pin: 0, direction: 'input', signal: 'analog', device: 'potentiometer', label: 'sensor' }, { pin: 1, direction: 'output', signal: 'digital', device: 'led', label: 'led' }, { pin: 2, direction: 'output', signal: 'pwm', device: 'motor', label: 'pwm' }],
    i2cDevices: [], scenarios: [scenario('public', true, [[0, 0], [250, 800]]), scenario('hidden', false, [[0, 731]])], starterProject: project };
}
function projectHash(project) { const files = Object.fromEntries(Object.entries(project.files).sort(([a], [b]) => Buffer.compare(Buffer.from(a), Buffer.from(b)))); return createHash('sha256').update(JSON.stringify({ name: project.name, files })).digest('hex'); }
function requireStatus(response, statuses = [200]) { requireThat(statuses.includes(response.status), apiCode(response.data, response.status)); return response.data; }
function requireHiddenSafe(result, runId, detail = false) {
  requireThat(!JSON.stringify(result).includes('private-' + runId), 'hidden_details_exposed');
  if (!detail) { requireThat(result.scenarios?.every(row => row.id !== 'hidden'), 'hidden_scenario_exposed'); return; }
  const hidden = result.scenarios?.find(row => row.id === 'hidden');
  requireThat(hidden && Object.keys(hidden).every(key => ['id', 'name', 'passed'].includes(key)) && !result._visibility, 'hidden_details_exposed');
  requireThat(result.traceScenarioId === 'public' && (result.trace || []).every(row => row.value !== 731), 'hidden_trace_exposed');
}
export async function runAcceptance(input, options = {}) {
  const config = validateConfig(input); requireThat(!options.load || options.allowTestWrites === true, 'load_requires_write_authorization');
  const client = new Client(config, options), runId = randomUUID().replaceAll('-', ''), checks = [], created = [], createdAssignments = [], identities = new Map();
  const add = (name, status, code) => checks.push({ name, status, ...(code ? { code } : {}) });
  const check = async (name, work) => { try { await work(); add(name, 'PASS'); return true; } catch (error) { add(name, 'FAIL', safeCode(error)); return false; } };
  let activeExercise = null, normalizedProject = null, creationOutcomeUncertain = false;
  const checkpoint = () => options.onCheckpoint?.({ format: 'oj-microbit-acceptance-v1', runId, overall: 'PENDING', mode: options.allowTestWrites ? 'writes-in-progress' : 'read-only-in-progress', cleanup: created, assignmentCleanup: createdAssignments, creationOutcomeUncertain });
  const marker = await check('test_server_marker', async () => { const response = await client.request('wp-content/oj-microbit-acceptance.json', { label: 'marker' }), data = requireStatus(response); requireThat(data?.testServer === true && data.marker === 'oj-microbit-acceptance-v1' && data.baseUrl === config.baseUrl, 'test_server_marker_mismatch'); client.markerAccepted = true; });
  const usable = new Set();
  if (marker) for (const alias of ALIASES) {
    const identified = await check('identity_' + alias, async () => { const data = requireStatus(await client.request('wp-json/wp/v2/users/me?context=edit', { alias, label: 'identity' })); requireThat(Number.isSafeInteger(data.id) && data.id > 0 && data.capabilities && typeof data.capabilities === 'object', 'invalid_account_identity'); requireThat(!data.capabilities.manage_options, 'test_account_must_not_be_administrator'); requireThat(![...identities.values()].includes(data.id), 'test_accounts_not_distinct'); identities.set(alias, data.id); });
    if (!identified) continue;
    if (!config.accounts[alias].restNonce) { add('rest_' + alias, 'PENDING', 'sessionless_rest_nonce_required'); continue; }
    const authorized = await check('rest_' + alias, async () => { const data = requireStatus(await client.api(alias.startsWith('teacher') ? 'teacher/exercises?limit=20' : 'public-exercises?limit=20', alias)); requireThat(Array.isArray(data.items) && data.items.length <= 20 && data.items.every(row => !row.starterProject && !row.scenarios), 'invalid_list_contract'); usable.add(alias); });
    if (authorized) await check('teacher_capability_' + alias, async () => { requireStatus(await client.api('teacher/exercises?limit=1', alias), alias.startsWith('teacher') ? [200] : [403]); });
  }
  else add('authenticated_rest', 'PENDING', 'marker_guard_no_credentials_sent');
  if (usable.has('studentA')) await check('runner_readiness', async () => { const data = requireStatus(await client.api('status', 'studentA')); requireThat(data.ready === true && data.runtimeVersion === RUNTIME, 'runner_not_ready_or_version_mismatch'); });
  else add('runner_readiness', 'PENDING', 'usable_student_account_required');
  try {
    if (!options.allowTestWrites) add('test_record_writes', 'SKIP', 'explicit_write_authorization_required');
    else if (!ALIASES.every(alias => usable.has(alias))) add('test_record_writes', 'PENDING', 'four_usable_test_accounts_required');
    else {
      const create = async audience => {
        const draft = testExercise(runId, audience); creationOutcomeUncertain = true; await checkpoint();
        const response = await client.api('teacher/exercises', 'teacherA', 'POST', draft);
        if (response.status >= 400) creationOutcomeUncertain = false;
        const saved = requireStatus(response);
        requireThat(Number.isSafeInteger(saved.id) && saved.id > 0 && saved.title === draft.title && saved.ownerId === identities.get('teacherA'), 'created_record_identity_invalid');
        const verified = requireStatus(await client.api('teacher/exercises/' + saved.id, 'teacherA'));
        requireThat(verified.id === saved.id && verified.title === draft.title && verified.ownerId === identities.get('teacherA') && verified.status === 'draft', 'created_record_identity_invalid');
        created.push({ id: saved.id, ownerAlias: 'teacherA', archived: false }); creationOutcomeUncertain = false; await checkpoint();
        const candidate = { ...saved, status: 'published' }, review = requireStatus(await client.api('teacher/exercises/review', 'teacherA', 'POST', { ...candidate, publicationReview: { starterMode: 'current' } }));
        requireThat(/^[a-f0-9]{64}$/.test(review.fingerprint || ''), 'review_fingerprint_invalid'); requireHiddenSafe(review.studentPreview, runId);
        requireStatus(await client.api('teacher/exercises', 'teacherA', 'POST', { ...candidate, publicationReview: { starterMode: 'current', confirmed: false, fingerprint: review.fingerprint } }), [400]);
        requireStatus(await client.api('teacher/exercises', 'teacherA', 'POST', { ...candidate, description: 'changed after review', publicationReview: { starterMode: 'current', confirmed: true, fingerprint: review.fingerprint } }), [400]);
        return requireStatus(await client.api('teacher/exercises', 'teacherA', 'POST', { ...candidate, publicationReview: { starterMode: 'current', confirmed: true, fingerprint: review.fingerprint } }));
      };
      await check('publication_review_and_ownership', async () => {
        activeExercise = await create({ mode: 'site', classIds: [] });
        requireThat(activeExercise.status === 'published' && activeExercise.revision >= 2, 'publication_failed');
        requireStatus(await client.api('teacher/exercises/' + activeExercise.id, 'teacherB'), [403]);
        requireStatus(await client.api('teacher/exercises', 'teacherB', 'POST', { ...activeExercise, status: 'draft', title: activeExercise.title }), [403]);
        requireHiddenSafe(requireStatus(await client.api('exercises/' + activeExercise.id, 'studentA')), runId);
        requireHiddenSafe(requireStatus(await client.api('exercises/' + activeExercise.id, 'studentB')), runId);
        const publicList = requireStatus(await client.api('public-exercises?limit=50', 'studentB'));
        requireThat(publicList.items?.some(row => row.id === activeExercise.id), 'public_problem_not_globally_visible');
      });
      if (activeExercise) {
        await check('project_restore_cas_and_owner_isolation', async () => {
          const path = 'exercises/' + activeExercise.id + '/project', initial = requireStatus(await client.api(path, 'studentA')); requireThat(initial.revision === 0, 'new_test_project_not_empty');
          const saved = requireStatus(await client.api(path, 'studentA', 'POST', { project: activeExercise.starterProject, revision: 0 })); normalizedProject = saved.project;
          requireThat(saved.revision === 1 && normalizedProject?.files?.['main.ts'], 'project_save_failed');
          requireStatus(await client.api(path, 'studentA', 'POST', { project: normalizedProject, revision: 0 }), [409]);
          const otherInitial = requireStatus(await client.api(path, 'studentB')); requireThat(otherInitial.revision === 0, 'cross_user_project_visible');
          const other = structuredClone(normalizedProject); other.files['main.ts'] += '\n// separate test account';
          requireStatus(await client.api(path, 'studentB', 'POST', { project: other, revision: 0, userId: identities.get('studentA') }));
          const restored = requireStatus(await client.api(path, 'studentA')); requireThat(restored.revision === 1 && projectHash(restored.project) === projectHash(normalizedProject), 'project_owner_isolation_failed');
        });
        if (normalizedProject) await check('real_grade_hidden_history_and_tamper_rejection', async () => {
          const path = 'exercises/' + activeExercise.id, wrong = structuredClone(normalizedProject); wrong.files['main.ts'] = 'pins.digitalWritePin(DigitalPin.P1, 0)\npins.analogWritePin(AnalogPin.P2, 0)\n';
          const rejected = requireStatus(await client.api(path + '/run', 'studentA', 'POST', { project: wrong, passed: true, score: 100, scenarios: [] }, { label: 'grade' })); requireThat(rejected.status === 'graded' && rejected.passed === false && rejected.score < 100, 'client_grade_tampering_accepted'); requireHiddenSafe(rejected, runId, true);
          const result = requireStatus(await client.api(path + '/run', 'studentA', 'POST', { project: normalizedProject }, { label: 'grade' }));
          requireThat(result.status === 'graded' && result.score === 100 && result.passed === true && result.runtimeVersion === RUNTIME && result.projectHash === projectHash(normalizedProject) && Number.isSafeInteger(result.id), 'real_grade_not_verified'); requireHiddenSafe(result, runId, true);
          const summaries = requireStatus(await client.api(path + '/attempts?limit=20', 'studentA')); requireThat(Array.isArray(summaries.items) && summaries.items.every(row => row.result && Object.keys(row.result).every(key => ['status', 'passed', 'score'].includes(key))), 'history_summary_contains_payload');
          requireHiddenSafe(requireStatus(await client.api(path + '/attempts/' + result.id, 'studentA')).result, runId, true);
          requireStatus(await client.api(path + '/attempts/' + result.id, 'studentB'), [403, 404]); requireStatus(await client.api('teacher/' + path + '/attempts/' + result.id, 'teacherB'), [403]);
          const ownerResult = requireStatus(await client.api('teacher/' + path + '/attempts/' + result.id, 'teacherA')).result; requireThat(ownerResult.scenarios?.some(row => row.name === 'private-' + runId && Array.isArray(row.assertions)), 'teacher_hidden_detail_unavailable');
        });
        await check('public_practice_outside_lesson_allowed', async () => {
          const publicResult = requireStatus(await client.api('exercises/' + activeExercise.id + '/run', 'studentB', 'POST', { project: activeExercise.starterProject }, { label: 'grade' }));
          requireThat(publicResult.status === 'graded' && publicResult.score === 100 && publicResult.passed === true, 'public_practice_outside_lesson_failed');
          requireHiddenSafe(publicResult, runId, true);
        });
        await check('new_public_problems_cannot_be_restricted', async () => {
          for (const audience of [{ mode: 'groups', groupIds: [7] }, { mode: 'classes', classIds: [7] }]) {
            requireStatus(await client.api('teacher/exercises', 'teacherA', 'POST', testExercise(runId, audience)), [400]);
          }
        });
        const managedLessons = config.classIds.teacherA || [], insideLessons = config.classIds.studentA || [], outsideLessons = config.classIds.studentB;
        const primaryClass = managedLessons.find(id => insideLessons.includes(id) && Array.isArray(outsideLessons) && !outsideLessons.includes(id));
        const secondaryClass = managedLessons.find(id => id !== primaryClass && insideLessons.includes(id));
        if (!primaryClass) add('lesson_assignment_and_outside_access', 'PENDING', 'managed_inside_and_outside_lesson_ids_required');
        else {
          let firstAssignment = null, secondAssignment = null;
          const assign = async classId => {
            creationOutcomeUncertain = true; await checkpoint();
            const response = await client.api('teacher/classes/' + classId + '/assignments', 'teacherA', 'POST', { exerciseId: activeExercise.id, revision: 0 });
            if (response.status >= 400) creationOutcomeUncertain = false;
            const saved = requireStatus(response);
            requireThat(Number.isSafeInteger(saved.id) && saved.id > 0 && saved.classId === classId && saved.exerciseId === activeExercise.id && saved.title === activeExercise.title && saved.ownerId === identities.get('teacherA'), 'created_assignment_identity_invalid');
            const verified = requireStatus(await client.api('teacher/assignments/' + saved.id, 'teacherA')).assignment;
            requireThat(verified?.id === saved.id && verified.exerciseId === activeExercise.id && verified.classId === classId && verified.ownerId === identities.get('teacherA'), 'created_assignment_identity_invalid');
            createdAssignments.push({ id: saved.id, classId, exerciseId: activeExercise.id, ownerAlias: 'teacherA', archived: false }); creationOutcomeUncertain = false; await checkpoint(); return saved;
          };
          await check('lesson_assignment_and_outside_access', async () => {
            firstAssignment = await assign(primaryClass);
            const path = 'assignments/' + firstAssignment.id, opened = requireStatus(await client.api(path, 'studentA'));
            requireThat(opened.assignment?.id === firstAssignment.id && opened.exercise?.id === activeExercise.id, 'lesson_assignment_source_mismatch');
            requireHiddenSafe(opened.exercise, runId);
            for (const endpoint of ['', '/project', '/attempts?limit=20']) requireStatus(await client.api(path + endpoint, 'studentB'), [403, 404]);
            for (const endpoint of ['/project', '/run', '/preview']) requireStatus(await client.api(path + endpoint, 'studentB', 'POST', { project: activeExercise.starterProject, revision: 0, inputs: [], durationMs: 500 }), [403, 404]);
            requireStatus(await client.api('exercises/' + activeExercise.id + '/run', 'studentA', 'POST', { project: activeExercise.starterProject, assignmentId: firstAssignment.id }), [400]);
            if (!(config.classIds.teacherB || []).includes(primaryClass)) requireStatus(await client.api('teacher/' + path + '/results?limit=20', 'teacherB'), [403, 404]);
          });
          if (firstAssignment && normalizedProject) {
            await check('lesson_project_and_latest_result_are_separate_from_general', async () => {
              const path = 'assignments/' + firstAssignment.id;
              const initial = requireStatus(await client.api(path + '/project', 'studentA')); requireThat(initial.revision === 0, 'lesson_project_inherited_general_work');
              const project = structuredClone(normalizedProject); project.files['main.ts'] += '\n// lesson-only draft';
              const saved = requireStatus(await client.api(path + '/project', 'studentA', 'POST', { project, revision: 0 })); requireThat(saved.revision === 1, 'lesson_project_save_failed');
              requireStatus(await client.api(path + '/project', 'studentA', 'POST', { project, revision: 0 }), [409]);
              const general = requireStatus(await client.api('exercises/' + activeExercise.id + '/project', 'studentA'));
              requireThat(projectHash(general.project) === projectHash(normalizedProject), 'lesson_project_overwrote_general_work');
              const correct = requireStatus(await client.api(path + '/run', 'studentA', 'POST', { project }, { label: 'grade' })); requireThat(correct.passed === true && correct.score === 100 && Number.isSafeInteger(correct.id), 'lesson_correct_grade_failed'); requireHiddenSafe(correct, runId, true);
              const wrong = structuredClone(project); wrong.files['main.ts'] = 'pins.digitalWritePin(DigitalPin.P1, 0)\n';
              const latest = requireStatus(await client.api(path + '/run', 'studentA', 'POST', { project: wrong }, { label: 'grade' })); requireThat(latest.passed === false && latest.score < 100 && latest.id !== correct.id, 'lesson_latest_grade_failed');
              const report = requireStatus(await client.api('teacher/' + path + '/results?limit=20', 'teacherA'));
              const student = report.items?.find(row => row.userId === identities.get('studentA'));
              requireThat(student?.latest?.id === latest.id && student.latest.result?.score === latest.score, 'lesson_latest_result_projection_failed');
              requireThat(typeof report.contextRevision === 'string' && typeof report.rosterRevision === 'string', 'lesson_roster_revision_missing');
              const scoped = requireStatus(await client.api(path + '/attempts?limit=20', 'studentA'));
              requireThat(scoped.items?.length === 2 && scoped.items.some(row => row.id === correct.id) && scoped.items.some(row => row.id === latest.id), 'lesson_history_scope_failed');
              const generalHistory = requireStatus(await client.api('exercises/' + activeExercise.id + '/attempts?limit=20', 'studentA'));
              requireThat(generalHistory.items?.length === 2 && generalHistory.items.every(row => row.id !== correct.id && row.id !== latest.id), 'general_history_contains_lesson_attempts');
              firstAssignment.latestAttemptId = latest.id;
            });
            if (!secondaryClass) add('two_lessons_and_unsubmitted_results_isolation', 'PENDING', 'second_managed_inside_lesson_required');
            else await check('two_lessons_and_unsubmitted_results_isolation', async () => {
              secondAssignment = await assign(secondaryClass); const path = 'assignments/' + secondAssignment.id;
              const project = requireStatus(await client.api(path + '/project', 'studentA')); requireThat(project.revision === 0, 'second_lesson_inherited_other_project');
              const report = requireStatus(await client.api('teacher/' + path + '/results?limit=20', 'teacherA'));
              const student = report.items?.find(row => row.userId === identities.get('studentA')); requireThat(student && student.latest === null, 'unsubmitted_lesson_inherited_general_or_other_results');
              const history = requireStatus(await client.api(path + '/attempts?limit=20', 'studentA')); requireThat(Array.isArray(history.items) && history.items.length === 0, 'second_lesson_history_contains_other_attempts');
              if (firstAssignment.latestAttemptId) {
                requireStatus(await client.api(path + '/attempts/' + firstAssignment.latestAttemptId, 'studentA'), [403, 404]);
                requireStatus(await client.api('teacher/' + path + '/attempts/' + firstAssignment.latestAttemptId, 'teacherA'), [403, 404]);
              }
            });
          }
        }
      }
    }
    if (!options.load) add('bounded_load', 'SKIP', 'explicit_load_option_required');
    else if (!activeExercise || !normalizedProject) add('bounded_load', 'PENDING', 'created_verified_test_exercise_required');
    else {
      if (config.hustojProbe) await check('hustoj_read_baseline', async () => { for (let index = 0; index < config.hustojProbe.baselineRequests; index++) requireStatus(await client.request(config.hustojProbe.url, { probe: true, label: 'hustoj_baseline' })); });
      else add('hustoj_read_baseline', 'PENDING', 'explicit_same_host_get_probe_required');
      await check('bounded_load', async () => {
        const tasks = Array.from({ length: config.load.totalRequests }, (_, index) => async () => {
          if (index % 2) return client.api('public-exercises?limit=20', index % 4 === 1 ? 'studentA' : 'studentB', 'GET', undefined, { label: 'load_get' });
          return client.api('exercises/' + activeExercise.id + '/run', index % 4 === 0 ? 'studentA' : 'studentB', 'POST', { project: normalizedProject }, { label: 'grade' });
        });
        if (config.hustojProbe) for (let index = 0; index < config.hustojProbe.baselineRequests; index++) tasks.splice(Math.min(tasks.length, (index + 1) * 3), 0, () => client.request(config.hustojProbe.url, { probe: true, label: 'hustoj_parallel' }));
        let cursor = 0; const failures = [];
        await Promise.all(Array.from({ length: config.load.concurrency }, async () => { while (cursor < tasks.length) { const task = tasks[cursor++]; try { const response = await task(); requireStatus(response, [200, 429]); } catch (error) { failures.push(safeCode(error)); } } }));
        requireThat(failures.length === 0 && client.maxActive <= LIMITS.concurrency && client.count <= LIMITS.requests, 'bounded_load_request_failed');
      });
      const metrics = summarizeMetrics(client.metrics), baseline = metrics.hustoj_baseline, parallel = metrics.hustoj_parallel;
      if (baseline && parallel && config.hustojProbe.maxP95Ratio) { if (!baseline.errors && !parallel.errors && parallel.p95Ms <= baseline.p95Ms * config.hustojProbe.maxP95Ratio) add('hustoj_get_latency_threshold', 'PASS'); else add('hustoj_get_latency_threshold', 'FAIL', 'hustoj_get_latency_threshold_exceeded'); }
      else add('hustoj_get_latency_threshold', 'PENDING', 'operator_threshold_required');
    }
  } finally {
    for (const record of createdAssignments) await check('cleanup_assignment_' + record.id, async () => {
      const path = 'teacher/assignments/' + record.id, latest = requireStatus(await client.api(path, record.ownerAlias, 'GET', undefined, { cleanup: true })).assignment;
      requireThat(latest?.id === record.id && latest.exerciseId === record.exerciseId && latest.classId === record.classId && latest.ownerId === identities.get(record.ownerAlias) && Number.isSafeInteger(latest.revision), 'cleanup_assignment_identity_guard');
      const archived = requireStatus(await client.api(path, record.ownerAlias, 'DELETE', { revision: latest.revision }, { cleanup: true })); requireThat(archived?.deleted === true, 'cleanup_assignment_not_confirmed'); record.archived = true; await checkpoint();
    });
    for (const record of created) await check('cleanup_archive_' + record.id, async () => {
      const path = 'teacher/exercises/' + record.id, latest = requireStatus(await client.api(path, record.ownerAlias, 'GET', undefined, { cleanup: true }));
      requireThat(latest.id === record.id && latest.title === 'OJMB acceptance ' + runId && latest.ownerId === identities.get(record.ownerAlias) && Number.isSafeInteger(latest.revision), 'cleanup_identity_guard');
      const archived = requireStatus(await client.api(path, record.ownerAlias, 'DELETE', { revision: latest.revision }, { cleanup: true })); requireThat(archived?.deleted === true, 'cleanup_archive_not_confirmed'); record.archived = true; await checkpoint();
    });
  }
  for (const scope of ['publication_review_and_ownership', 'project_restore_cas_and_owner_isolation', 'real_grade_hidden_history_and_tamper_rejection', 'public_practice_outside_lesson_allowed', 'lesson_assignment_and_outside_access', 'lesson_project_and_latest_result_are_separate_from_general', 'two_lessons_and_unsubmitted_results_isolation']) if (!checks.some(row => row.name === scope)) add(scope, options.allowTestWrites ? 'PENDING' : 'SKIP', options.allowTestWrites ? 'prerequisite_not_verified' : 'explicit_write_authorization_required');
  if (creationOutcomeUncertain) add('unknown_creation_outcome', 'PENDING', 'manual_search_unique_run_name_required');
  if (client.cancelled) add('run_interrupted', 'FAIL', 'run_cancelled');
  add('lesson_membership_revocation', 'PENDING', 'manual_existing_lesson_membership_change_required');
  add('hustoj_real_judging_parallel', 'PENDING', 'manual_concurrent_hustoj_submission_required');
  add('mysql_explain_and_host_resource_approval', 'PENDING', 'manual_database_and_host_metrics_required');
  add('retained_test_records_cleanup', 'PENDING', 'manual_snapshot_restore_or_privacy_batch_completion_required');
  const overall = checks.some(row => row.status === 'FAIL') ? 'FAIL' : checks.some(row => ['PENDING', 'SKIP'].includes(row.status)) ? 'PENDING' : 'PASS';
  return { format: 'oj-microbit-acceptance-v1', runId, overall, startedAt: new Date(client.started).toISOString(), finishedAt: new Date().toISOString(), elapsedMs: Date.now() - client.started, nodeVersion: process.versions.node, mode: options.allowTestWrites ? options.load ? 'writes-and-load' : 'writes' : 'read-only', checks,
    metrics: summarizeMetrics(client.metrics), requestCount: client.count, maximumActiveRequests: client.maxActive, limits: LIMITS, cleanupMode: 'archived_only', recordsRetained: true, cleanup: created, assignmentCleanup: createdAssignments, assignmentCleanupPending: createdAssignments.filter(row => !row.archived).map(row => row.id), cleanupPending: created.filter(row => !row.archived).map(row => row.id), creationOutcomeUncertain,
    notes: ['Only exercises and lesson assignments created in this invocation are eligible for archival.', 'Archival preserves related test projects and attempts under the configured retention policy.', 'Read-only HUSTOJ HTTP latency does not verify HUSTOJ judging throughput.', 'No source, tokens, account names, hidden conditions, response bodies, or credentials are included in this report.'] };
}
function parseArgs(argv) {
  const result = { allowTestWrites: false, load: false };
  for (let index = 0; index < argv.length; index++) { const value = argv[index]; if (value === '--allow-test-writes') result.allowTestWrites = true; else if (value === '--load') result.load = true; else if (value === '--help') result.help = true;
    else if (['--config', '--report'].includes(value) && argv[index + 1] && !argv[index + 1].startsWith('--')) result[value.slice(2)] = argv[++index]; else throw new CheckError('invalid_arguments'); }
  requireThat(result.help || result.config && result.report && (!result.load || result.allowTestWrites), 'invalid_arguments'); return result;
}
export async function main(argv = process.argv.slice(2)) {
  let reportFile;
  try {
    const options = parseArgs(argv); if (options.help) { process.stdout.write('Usage: node scripts/acceptance/rest-check.mjs --config PRIVATE_0600_JSON --report NEW_PRIVATE_JSON [--allow-test-writes] [--load]\nA matching test-server marker and four separate non-admin test accounts are required. Application Passwords also require sessionless restNonce values prepared by wp-nonce.php. Default mode is read-only; --load also requires --allow-test-writes. No credentials are accepted in argv or environment. Caps: 200 requests, concurrency 4, 5 requests/second, 60 load requests, 5 minutes. Missing manual scopes remain PENDING. Run only on a trial server.\n'); return 0; }
    const config = await readPrivateConfig(options.config);
    try { reportFile = await open(options.report, 'wx', 0o600); validatePrivateStat(await reportFile.stat(), process.getuid?.(), process.platform, 0); } catch { throw new CheckError('private_report_write_failed'); }
    const persist = async report => { try { await reportFile.truncate(0); await reportFile.write(JSON.stringify(report, null, 2) + '\n', 0, 'utf8'); await reportFile.sync(); } catch { throw new CheckError('private_report_write_failed'); } };
    await persist({ format: 'oj-microbit-acceptance-v1', overall: 'PENDING', code: 'run_in_progress' });
    const interrupted = new AbortController(), stop = () => interrupted.abort(); process.once('SIGINT', stop); process.once('SIGTERM', stop);
    let report; try { report = await runAcceptance(config, { ...options, signal: interrupted.signal, onCheckpoint: persist }); } finally { process.removeListener('SIGINT', stop); process.removeListener('SIGTERM', stop); }
    await persist(report);
    process.stdout.write(JSON.stringify({ overall: report.overall, checks: report.checks.length, requests: report.requestCount, archived: report.cleanup.filter(row => row.archived).length }) + '\n'); return report.overall === 'FAIL' ? 1 : 0;
  } catch (error) { process.stdout.write(JSON.stringify({ overall: 'FAIL', code: safeCode(error) }) + '\n'); return 1; }
  finally { try { await reportFile?.close(); } catch { /* The report was fsynced; never print filesystem internals. */ } }
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) process.exitCode = await main();
