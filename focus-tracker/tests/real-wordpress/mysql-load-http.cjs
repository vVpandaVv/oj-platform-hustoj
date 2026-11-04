'use strict';

// Actual WordPress form login, cookie authentication, signed page descriptors
// and HTTP REST requests. These are protocol clients, not rendered browser tabs.
// The visible/focused fields below are synthetic protocol inputs, not evidence
// of OS visibility. Server time, app intervals and success responses are native.
const assert = require('node:assert/strict');
const fs = require('node:fs'); const path = require('node:path'); const os = require('node:os');
const { performance } = require('node:perf_hooks'); const { randomUUID } = require('node:crypto');
const { execFile } = require('node:child_process');
const { nativeCookieLogin, bootstrap, dashboardConfig } = require('./native-cookie-client.cjs');
const { cleanupFailedActivities } = require('./native-activity-cleanup.cjs');
function playwright() {
    for (const name of [process.env.PLAYWRIGHT_MODULE_PATH, 'playwright', process.env.USERPROFILE && path.join(process.env.USERPROFILE,
        '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')].filter(Boolean)) {
        try { return require(name); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; }
    }
    throw new Error('Playwright is required.');
}
const configPath = process.env.FOCUS_MYSQL_LOAD_CONFIG;
assert.ok(configPath, 'Set FOCUS_MYSQL_LOAD_CONFIG to the private native MySQL fixture manifest.');
const config = JSON.parse(fs.readFileSync(path.resolve(configPath), 'utf8'));
const teacherApiOnly = process.env.FOCUS_MYSQL_TEACHER_API_ONLY === '1';
const base = config.baseUrl.replace(/\/$/, ''); const origin = new URL(base).origin;
assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname), 'Use the dedicated disposable native site.');
const groups = Array.isArray(config.groups) ? config.groups : Object.values(config.groups || {});
assert.ok(groups[0]?.id && groups[1]?.id && config.students?.length && config.material?.url, 'The native 2-group fixture is required.');
const sizes = (process.env.FOCUS_MYSQL_HTTP_STAGES || '60').split(',').map(Number);
assert.ok(sizes.every(size => Number.isInteger(size) && size > 0 && size <= 60 && size <= config.students.length));
const artifacts = path.resolve(process.env.FOCUS_MYSQL_HTTP_ARTIFACTS || path.join(path.dirname(path.resolve(configPath)), 'http-load-' + Date.now()));
fs.mkdirSync(artifacts, { recursive: true });
const secrets = [config.teacher, ...config.students].flatMap(account => [account.username, account.password]).filter(value => typeof value === 'string' && value.length > 3);
function clean(value) { let text = String(value); for (const secret of secrets) text = text.split(secret).join('[redacted]'); return text.replace(/([?&]_wpnonce=)[^&\s]+/g, '$1[redacted]'); }
function safeFailure(error) {
    return clean(String(error.message || error).split(/\r?\n/)[0] + '\n' + String(error.stack || '').split(/\r?\n/)
        .filter(line => /^\s+at /.test(line) && line.includes(__filename)).join('\n'));
}
const report = { startedAt: new Date().toISOString(), mode: 'real-http-cookie-protocol-load', baseUrl: base,
    targetVersion: process.env.FOCUS_MYSQL_LOAD_VERSION || null, phpWorkers: Number(process.env.FOCUS_MYSQL_LOAD_PHP_WORKERS) || null,
    mysqlBufferPoolMiB: Number(process.env.FOCUS_MYSQL_LOAD_MYSQL_BUFFER_MIB) || 512,
    teacherMode: teacherApiOnly ? 'Native cookie/nonce REST; teacher UI is outside this run.' : 'Native teacher UI creation, then cookie/nonce REST controls.',
    productionIntervals: { gatePollMs: 5000, gateFetchTimeoutMs: 5000, maximumLeaseMs: 10000, heartbeatMs: 15000, flushMs: 7000, ingestTimeoutMs: 15000 },
    scope: 'Native HTTP WordPress login/cookies/REST; student clients do not render browser pages or measure OS visibility. '
        + (teacherApiOnly ? 'Teacher controls are native cookie/nonce REST; teacher UI is outside this run. ' : 'Teacher creates schedules in actual Chrome UI, which is then closed. ')
        + 'Student state metadata is labeled synthetic.',
    syntheticStateByChannel: [{ visible: true, focused: true }, { visible: false, focused: false }],
    harnessSafety: { minimumFreeMiB: 1024, maximumNormalGateTimeoutsBeforeStop: 20, maximumUnexpectedHttpErrorsBeforeStop: 10, samplingMs: 1000 },
    acceptance: { allowedAcknowledgedRecordLoss: 0, duplicateStoredEvents: 0, outsideScopeOrDeadlineStoredEvents: 0,
        normalHttp5xx: 0, normalHttp429: 0, normalTimeouts: 0, gateP99MustBeBelowMs: 5000, gateP99ReferenceGoalMs: 1000 },
    stages: [], expectedEvents: [], passedClientCriteria: false, requiresReadOnlyDatabaseAudit: true };
const expected = new Map(); const actorsToDispose = []; let teacher; let browser; let progressTimer;
async function ownedResourceSnapshot(label) {
    const value = { label, at: Date.now(), freeMiB: Math.round(os.freemem() / 1048576), nodeRssMiB: Math.round(process.memoryUsage().rss / 1048576) };
    if (browser && process.platform === 'win32') {
        try {
            const cdp = await browser.newBrowserCDPSession(); const result = await cdp.send('SystemInfo.getProcessInfo'); await cdp.detach();
            const ids = result.processInfo.map(info => Number(info.id)).filter(id => Number.isInteger(id) && id > 0);
            // Explicit IDs from this browser's CDP; never enumerate unrelated apps.
            const command = 'Get-Process -Id @(' + ids.join(',') + ') -ErrorAction SilentlyContinue | Select-Object Id,ProcessName,WorkingSet64 | ConvertTo-Json -Compress';
            const output = await new Promise((resolve, reject) => execFile('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', command],
                { windowsHide: true, encoding: 'utf8', timeout: 5000 }, (error, stdout) => error ? reject(error) : resolve(stdout)));
            value.ownedBrowserProcesses = [].concat(JSON.parse(output)).map(info => ({ pid: info.Id, name: info.ProcessName, rssMiB: Math.round(info.WorkingSet64 / 1048576) }));
            value.ownedBrowserRssMiB = value.ownedBrowserProcesses.reduce((total, info) => total + info.rssMiB, 0);
        } catch (error) { value.browserResourceProbe = String(error.name || 'unavailable'); }
    }
    (report.ownedResourceSnapshots ||= []).push(value); return value;
}
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
function save() { report.expectedEvents = [...expected.values()]; report.updatedAt = new Date().toISOString(); fs.writeFileSync(path.join(artifacts, 'browser-report.json'), JSON.stringify(report, null, 2) + '\n', 'utf8'); }
async function until(fn, message, timeout = 30000) { const end = Date.now() + timeout; do { if (await fn()) return; await sleep(100); } while (Date.now() < end); throw new Error(message); }
function ensureSafe(stage) { if (stage.safetyStop) throw new Error(stage.safetyStop.reason); }
async function waitTo(stage, at) { while (Date.now() < at) { ensureSafe(stage); await sleep(Math.min(500, at - Date.now())); } ensureSafe(stage); }
async function pool(values, count, fn) { let next = 0; let failure; const result = new Array(values.length);
    await Promise.all(Array.from({ length: Math.min(count, values.length) }, async () => { while (next < values.length && !failure) {
        const index = next++; try { result[index] = await fn(values[index], index); } catch (error) { failure ||= error; }
    } })); if (failure) throw failure; return result;
}
function percentile(values, fraction) { const sorted = values.filter(Number.isFinite).sort((a, b) => a - b); return sorted.length ? sorted[Math.min(sorted.length - 1, Math.ceil(sorted.length * fraction) - 1)] : null; }
function distribution(values) { return { count: values.length, p50Ms: percentile(values, 0.5), p95Ms: percentile(values, 0.95), p99Ms: percentile(values, 0.99), maxMs: values.length ? Math.max(...values) : null }; }
function route(value) { const url = new URL(value); return (url.searchParams.get('rest_route') || url.pathname.replace(/^.*\/wp-json/, '')).replace(/\/$/, ''); }
function endpoint(cfg, suffix) { const url = new URL(cfg.restUrl); const [part, query] = suffix.split('?');
    if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/events$/, '').replace(/\/$/, '') + part);
    else url.pathname = url.pathname.replace(/\/events$/, '').replace(/\/$/, '') + part;
    for (const [key, value] of new URLSearchParams(query || '')) url.searchParams.set(key, value); return url.href;
}
async function loginHttp(account, stage) {
    const at = performance.now(); const request = await nativeCookieLogin(playwright(), base, account); actorsToDispose.push(request);
    stage.authDurationsMs.push(performance.now() - at);
    return { userId: Number(account.userId), request };
}
async function nativeRequest(actor, cfg, suffix, method, body, stage, channel, timeoutMs = 15000) {
    const sample = { endpoint: suffix.split('?')[0].replace(/\/activities\/\d+/g, '/activities/:id'), method,
        phase: stage.phase, userId: actor.userId, channel, startedAt: Date.now() }; stage.requests.push(sample);
    const at = performance.now();
    try {
        const url = new URL(endpoint(cfg, suffix));
        // Production tracker.js uses the explicit query nonce for both fetch
        // and sendBeacon; the ingest controller deliberately requires it.
        if (suffix === '/events') url.searchParams.set('_wpnonce', cfg.nonce);
        const response = await actor.request.fetch(url.href, { method, headers: { 'X-WP-Nonce': cfg.nonce, ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) },
            ...(body === undefined ? {} : { data: body }), timeout: timeoutMs, maxRetries: 0 });
        sample.status = response.status(); let data; try { data = await response.json(); } catch (_) { data = {}; }
        sample.durationMs = performance.now() - at; sample.code = typeof data.code === 'string' ? data.code : undefined;
        sample.result = typeof data.status === 'string' ? data.status : undefined;
        if (suffix === '/tracking/state' && Number.isFinite(data.validUntilMs)) sample.serverLeaseMs = data.validUntilMs - data.serverNowMs;
        if (suffix === '/events') { sample.stored = Number(data.stored) || 0; sample.policyDropped = Number(data.policyDropped) || 0; sample.acknowledged = data.acknowledged === true; }
        return { status: sample.status, data, durationMs: sample.durationMs, sample };
    } catch (error) {
        // Playwright's full HTTP call log may contain cookie/nonce headers.
        // Keep only the network error's first line; never persist that call log.
        sample.durationMs = performance.now() - at; sample.failure = clean(String(error.message).split(/\r?\n/)[0]); sample.timeout = /timeout|timed.out/i.test(error.message);
        return { status: 0, data: {}, durationMs: sample.durationMs, sample };
    }
}
class Channel {
    constructor(actor, cfg, index, stage) {
        this.actor = actor; this.cfg = cfg; this.index = index; this.stage = stage; this.group = groups[index]; this.active = false; this.stopped = false;
        this.epoch = Number(cfg.serverNowMs) || Date.now(); this.mono = performance.now(); this.queue = []; this.sessions = []; this.states = [];
        this.gateBusy = false; this.flushBusy = false; this.nextAllowedFlush = 0; this.failures = 0; this.gate = null;
        this.lastProbeAt = -Infinity; this.nextProbeAt = 0; this.lastRoundTripMs = 0;
        this.pageToken = cfg.page.token; this.observedActive = false; this.syntheticState = report.syntheticStateByChannel[index];
    }
    now() { return Math.round(this.epoch + performance.now() - this.mono); }
    publish(active, reason) {
        const prior = this.active; this.active = active;
        const priorState = this.states.at(-1);
        if (!priorState || priorState.active !== active || priorState.reason !== reason) this.states.push({ at: Date.now(), serverNowMs: this.now(), active, reason,
            activityIds: active ? this.gate.activityIds || [] : [], activeUntilMs: this.gate?.activeUntilMs || null });
        if (active && !prior) this.start(); else if (!active && prior) this.end();
    }
    expire() { if (this.active && (this.now() >= this.validUntil || this.now() >= this.activeUntil)) this.publish(false, this.deadlineReason || 'lease_expired'); }
    armGate(delay = 5000) {
        clearTimeout(this.gateTimer); if (this.stopped) return;
        const remaining = this.active ? Math.max(0, Math.min(this.validUntil, this.activeUntil) - this.now()) : Infinity;
        if (this.gateBusy) { if (this.active) this.gateTimer = setTimeout(() => { this.expire(); if (this.gateBusy && this.active) this.armGate(0); }, remaining); return; }
        let wait = Math.min(Math.max(0, delay), Math.max(0, this.nextProbeAt - performance.now()));
        if (this.active) {
            const lead = 5250;
            wait = Math.min(wait, Math.max(0, remaining - lead));
        }
        wait = Math.max(wait, Math.max(0, 500 - (performance.now() - this.lastProbeAt)));
        wait = Math.min(wait, remaining);
        if (this.gate?.nextStartsAtMs > this.now()) wait = Math.min(wait, this.gate.nextStartsAtMs - this.now());
        this.gateTimer = setTimeout(() => { this.expire(); void this.probe(); }, Math.max(0, wait));
    }
    async probe() {
        if (this.stopped || this.gateBusy) return;
        if (performance.now() - this.lastProbeAt < 500) { this.armGate(500 - (performance.now() - this.lastProbeAt)); return; }
        this.expire(); this.gateBusy = true;
        this.lastProbeAt = performance.now(); this.nextProbeAt = this.lastProbeAt + 5000;
        this.armGate();
        const result = await nativeRequest(this.actor, this.cfg, '/tracking/state', 'POST', { user_id: this.actor.userId, problem_id: this.cfg.problemId || null,
            context_type: 'group', context_id: this.group.contextId || 'group:' + this.group.id, page_context: this.pageToken }, this.stage, this.index, 5000);
        try {
            if (this.stopped) return;
            const data = result.data;
            if (result.status !== 200 || typeof data.active !== 'boolean' || !Array.isArray(data.windows) || !Number.isFinite(data.serverNowMs) || !Number.isFinite(data.validUntilMs)) {
                this.nextProbeAt = performance.now() + 5000;
                this.publish(false, 'unavailable'); return;
            }
            this.epoch = data.serverNowMs; this.mono = performance.now(); this.expire(); this.gate = data;
            if (data.pageContextToken) this.pageToken = data.pageContextToken;
            this.validUntil = Math.floor(Math.min(data.validUntilMs, data.serverNowMs + 10000) - result.durationMs);
            this.activeUntil = Number(data.activeUntilMs || data.windowEndsAtMs);
            this.lastRoundTripMs = result.durationMs;
            this.deadlineReason = this.activeUntil && Math.min(data.validUntilMs, data.serverNowMs + 10000) >= this.activeUntil ? 'ended' : 'unavailable';
            const active = data.active && this.validUntil > this.now() && this.activeUntil > this.now()
                && data.windows.some(window => window.startMs <= this.now() && this.now() < window.endMs);
            if (active) { this.allowedUntil = Math.min(this.validUntil, this.activeUntil); this.observedActive = true; }
            this.publish(Boolean(active), active ? 'active' : data.active ? 'unavailable' : data.reason || 'outside_schedule');
        } finally { this.gateBusy = false; this.armGate(); }
    }
    event(type, at = this.now()) {
        if (this.stopped || (!this.active && type !== 'session_end') || at < this.startedAt || (type === 'session_end' ? at > this.allowedUntil : at >= this.allowedUntil)) return;
        const event = { type, occurred_at: at, seq: ++this.seq, meta: { ...this.syntheticState } };
        this.queue.push({ uid: this.uid, startedAt: this.startedAt, token: this.pageToken, event });
        const key = this.actor.userId + '|' + this.uid + '|' + event.seq;
        expected.set(key, { userId: this.actor.userId, sessionUid: this.uid, sessionStartedAt: this.startedAt, seq: event.seq, occurredAt: at, type,
            problemId: this.cfg.problemId || null, pageKey: this.cfg.page.key, pageCategory: this.cfg.page.category, pageSubtype: this.cfg.page.subtype || null,
            contextType: 'group', contextId: this.group.contextId || 'group:' + this.group.id, stageStudents: this.stage.studentCount,
            syntheticProtocolState: true, visible: this.syntheticState.visible, focused: this.syntheticState.focused });
        return event;
    }
    start() {
        if (this.stopped) return;
        this.uid = randomUUID(); this.startedAt = this.now(); this.seq = 0;
        const session = { uid: this.uid, sourceStartedAt: this.startedAt, startedAt: this.startedAt };
        this.sessions.push(session); const firstSignal = this.event('session_start');
        if (firstSignal) session.startedAt = firstSignal.occurred_at;
        this.flushTimer = setInterval(() => void this.flush(), 7000);
        this.heartbeatTimer = setInterval(() => { this.expire(); if (this.active) this.event('heartbeat'); }, 15000);
        void this.flush();
    }
    end() {
        const endedAt = Math.max(this.startedAt, Math.min(this.now(), this.allowedUntil));
        this.sessions.at(-1).endedAt = endedAt; this.event('session_end', endedAt);
        clearInterval(this.flushTimer); clearInterval(this.heartbeatTimer); void this.flush();
    }
    async flush() {
        if (this.stopped || this.flushBusy || !this.queue.length) return;
        if (Date.now() < this.nextAllowedFlush) { clearTimeout(this.retryTimer); this.retryTimer = setTimeout(() => void this.flush(), this.nextAllowedFlush - Date.now()); return; }
        this.flushBusy = true;
        const first = this.queue[0]; const uid = first.uid; const startedAt = first.startedAt;
        const queued = this.queue.filter(entry => entry.uid === uid && entry.token === first.token).slice(0, 100);
        const events = queued.map(entry => entry.event);
        const result = await nativeRequest(this.actor, this.cfg, '/events', 'POST', { user_id: this.actor.userId, session_uid: uid,
            session_started_at: startedAt, problem_id: this.cfg.problemId || null, context_type: 'group', context_id: this.group.contextId || 'group:' + this.group.id,
            page_context: first.token, is_final: events.some(event => event.type === 'session_end'), events }, this.stage, this.index, 15000);
        result.sample.eventKeys = events.map(event => this.actor.userId + '|' + uid + '|' + event.seq);
        if (result.status === 200 && result.data.acknowledged === true) {
            this.queue = this.queue.filter(entry => !queued.includes(entry)); result.sample.eventKeys.forEach(key => this.stage.acknowledgedEventKeys.add(key));
            this.failures = 0; this.nextAllowedFlush = 0;
        } else { this.failures += 1; this.nextAllowedFlush = Date.now() + Math.min(60000, 1000 * 2 ** Math.min(this.failures, 6)); }
        this.flushBusy = false;
        if (this.queue.length && !this.stopped) { clearTimeout(this.retryTimer); this.retryTimer = setTimeout(() => void this.flush(), Math.max(0, this.nextAllowedFlush - Date.now())); }
    }
    stop() { this.stopped = true; clearTimeout(this.gateTimer); clearTimeout(this.retryTimer); clearInterval(this.flushTimer); clearInterval(this.heartbeatTimer); }
}
async function prepare(account, stage) {
    const actor = await loginHttp(account, stage); actor.channels = [];
    for (const [index, group] of groups.slice(0, 2).entries()) {
        const url = new URL(index === 1 && config.problem?.id ? (config.problem.problemUrl || config.problem.url) : config.material.url, base); url.searchParams.set('group_id', group.id);
        assert.equal(url.origin, origin); const response = await actor.request.get(url.href);
        assert.equal(response.status(), 200, 'The native learner page must render successfully: ' + url.pathname + ' HTTP ' + response.status());
        const cfg = bootstrap(await response.text()); assert.equal(Number(cfg.userId), actor.userId);
        assert.equal(Number(cfg.activityGate.pollMs), 5000); assert.equal(Number(cfg.heartbeatMs), 15000); assert.equal(Number(cfg.flushMs), 7000);
        assert.ok(cfg.page?.token && cfg.nonce !== 'focus-test-nonce'); actor.channels.push(new Channel(actor, cfg, index, stage));
    }
    return actor;
}
async function loginTeacher() {
    if (teacherApiOnly) {
        const actor = await loginHttp(config.teacher, { authDurationsMs: [] });
        const response = await actor.request.get('/oj-console/addons/focus-tracker'); assert.equal(response.status(), 200);
        actor.cfg = dashboardConfig(await response.text()); return actor;
    }
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH,
        process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')].find(value => value && fs.existsSync(value));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    await ownedResourceSnapshot('after-browser-launch-before-teacher-login');
    const context = await browser.newContext({ timezoneId: 'Asia/Seoul' }); const page = await context.newPage(); page.setDefaultNavigationTimeout(90000);
    await page.goto(base + '/login'); const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="oj_login"]') });
    await form.locator('[name="user_login"]').fill(config.teacher.username); await form.locator('[name="password"]').fill(config.teacher.password);
    await form.getByRole('button', { name: '로그인', exact: true }).click();
    await until(async () => (await context.cookies(base)).some(cookie => cookie.name.startsWith('wordpress_logged_in_')), 'Native teacher cookie login failed.', 60000);
    await page.waitForLoadState('domcontentloaded'); await ownedResourceSnapshot('after-teacher-login'); return { context, page, userId: Number(config.teacher.userId) };
}
async function teacherApi(suffix, body, stage, name) {
    if (teacher.request) {
        const value = await nativeRequest(teacher, teacher.cfg, suffix, 'PATCH', body, stage, 'teacher-control', 15000);
        stage.teacherActions.push({ name, at: Date.now(), status: value.status, durationMs: value.durationMs });
        assert.equal(value.status, 200, 'Native teacher schedule action failed.'); return;
    }
    const value = await teacher.page.evaluate(async input => {
        const at = performance.now(); const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(input.url, { method: 'PATCH', credentials: 'same-origin', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': input.nonce }, body: JSON.stringify(input.body) });
            return { status: response.status, durationMs: performance.now() - at };
        } catch (error) { return { status: 0, durationMs: performance.now() - at, failure: error.name }; }
        finally { clearTimeout(timeout); }
    }, { url: endpoint(teacher.cfg, suffix), nonce: teacher.cfg.nonce, body });
    stage.teacherActions.push({ name, at: Date.now(), ...value }); assert.equal(value.status, 200, 'Native teacher schedule action failed.');
}
async function createActivity(stage, group, name, start, end) {
    if (teacherApiOnly) {
        const value = await nativeRequest(teacher, teacher.cfg, '/activities', 'POST', { name, context_type: 'group',
            context_id: group.contextId || 'group:' + group.id, problem_ids: [], starts_at: new Date(start).toISOString(), ends_at: new Date(end).toISOString() }, stage, 'teacher-control', 15000);
        assert.ok(value.status >= 200 && value.status < 300 && value.data.id, 'The real cookie/nonce teacher must create a schedule.');
        stage.activities.push(value.data); stage.teacherActions.push({ name: 'create ' + name, at: Date.now(), status: value.status, durationMs: value.durationMs, mode: 'native REST' }); return value.data;
    }
    const form = teacher.page.locator('[data-field="create-form"]'); await form.locator('[data-field="name"]').fill(name);
    await form.locator('[data-field="context"]').selectOption('group:' + (group.contextId || 'group:' + group.id));
    const dates = await teacher.page.evaluate(values => values.map(ms => { const date = new Date(ms); const p = n => String(n).padStart(2, '0');
        return date.getFullYear() + '-' + p(date.getMonth() + 1) + '-' + p(date.getDate()) + 'T' + p(date.getHours()) + ':' + p(date.getMinutes()) + ':' + p(date.getSeconds()); }), [start, end]);
    await form.locator('[data-field="starts-at"]').fill(dates[0]); await form.locator('[data-field="ends-at"]').fill(dates[1]);
    const pending = teacher.page.waitForResponse(response => response.request().method() === 'POST' && route(response.url()) === '/focus-tracker/v1/activities');
    await form.getByRole('button', { name: '활동 만들기', exact: true }).click(); const response = await pending; assert.ok(response.ok());
    const activity = await response.json(); stage.activities.push(activity); await response.finished();
    stage.teacherActions.push({ name: 'create ' + name, at: Date.now(), status: response.status(), durationMs: response.request().timing().responseEnd });
    await teacher.page.locator(`[data-activity-id="${activity.id}"]`).waitFor(); return activity;
}
function checkpoint(stage, actors, name) { const at = Date.now(); const issues = [];
    for (const actor of actors) for (const channel of actor.channels) {
        const end = stage.plannedStartMs + (channel.index === 0 ? 90000 : 80000);
        const active = at >= stage.plannedStartMs + 10000 && at < end - 1000 ? true : at > end + 1000 ? false : null;
        if (active !== null && channel.active !== active) issues.push({ userId: actor.userId, channel: channel.index, expectedActive: active, observedActive: channel.active });
    }
    stage.authorizationCheckpoints.push({ at, name, issues });
}
async function statistics(actor, stage) { const result = { userId: actor.userId };
    for (const period of ['week', 'month']) {
        const value = await nativeRequest(actor, actor.channels[0].cfg, '/me/statistics?period=' + period, 'GET', undefined, stage, 0, 60000);
        assert.equal(value.status, 200); assert.equal(Number(value.data.userId), actor.userId); assert.ok(Array.isArray(value.data.pageCategories));
        const sum = key => value.data.pageCategories.reduce((total, row) => total + (Number(row[key]) || 0), 0);
        const totals = value.data.totals; result[period] = { startDate: value.data.startDate, endDate: value.data.endDate,
            activeSeconds: totals.activeSeconds, observedSeconds: totals.observedSeconds,
            categoryActiveSeconds: sum('activeSeconds'), categoryObservedSeconds: sum('observedSeconds') };
        assert.ok(Math.abs(sum('activeSeconds') - totals.activeSeconds) < 0.01 && Math.abs(sum('observedSeconds') - totals.observedSeconds) < 0.01, 'Weekly/monthly category seconds must exactly partition the native totals.');
    } return result;
}
function unionSeconds(ranges) {
    const sorted = ranges.filter(range => Number.isFinite(range.startedAt) && Number.isFinite(range.endedAt)).sort((a, b) => a.startedAt - b.startedAt);
    let length = 0; let start; let end;
    for (const range of sorted) {
        if (start === undefined) { start = range.startedAt; end = range.endedAt; }
        else if (range.startedAt <= end) end = Math.max(end, range.endedAt);
        else { length += end - start; start = range.startedAt; end = range.endedAt; }
    }
    return (length + (start === undefined ? 0 : end - start)) / 1000;
}
function verifyPeriodDeltas(stage, actors) {
    stage.periodDeltaChecks = [];
    for (const actor of actors) {
        const after = stage.statistics.find(value => value.userId === actor.userId); const before = actor.baselineStatistics;
        const activeSeconds = unionSeconds(actor.channels.filter(channel => channel.syntheticState.visible && channel.syntheticState.focused).flatMap(channel => channel.sessions));
        const observedSeconds = unionSeconds(actor.channels.flatMap(channel => channel.sessions));
        for (const period of ['week', 'month']) {
            const sameBounds = before[period].startDate === after[period].startDate && before[period].endDate === after[period].endDate;
            const actualActiveDelta = Number(after[period].activeSeconds) - Number(before[period].activeSeconds);
            const actualObservedDelta = Number(after[period].observedSeconds) - Number(before[period].observedSeconds);
            stage.periodDeltaChecks.push({ userId: actor.userId, period, sameCalendarBounds: sameBounds,
                expectedActiveDeltaSeconds: activeSeconds, actualActiveDeltaSeconds: actualActiveDelta,
                expectedObservedDeltaSeconds: observedSeconds, actualObservedDeltaSeconds: actualObservedDelta,
                passed: sameBounds && Math.abs(actualActiveDelta - activeSeconds) < 0.01 && Math.abs(actualObservedDelta - observedSeconds) < 0.01 });
        }
    }
}
function summarize(stage, actors) {
    const metrics = {}; for (const sample of stage.requests) {
        const key = sample.phase + ':' + sample.endpoint; const value = metrics[key] ||= { durations: [], statuses: {}, errors: 0, timeouts: 0, incomplete: 0 };
        if (Number.isFinite(sample.durationMs)) value.durations.push(sample.durationMs); else value.incomplete += 1;
        if (sample.status) value.statuses[sample.status] = (value.statuses[sample.status] || 0) + 1;
        if (sample.failure) value.errors += 1; if (sample.timeout) value.timeouts += 1;
    }
    stage.metrics = Object.fromEntries(Object.entries(metrics).map(([key, value]) => [key, { ...distribution(value.durations), statuses: value.statuses,
        errors: value.errors, timeouts: value.timeouts, incomplete: value.incomplete }]));
    const normal = stage.requests.filter(value => ['waiting', 'active', 'drain'].includes(value.phase));
    stage.normalFailures = { http4xx: normal.filter(sample => sample.status >= 400 && sample.status < 500).length,
        http5xx: normal.filter(sample => sample.status >= 500).length, timeouts: normal.filter(sample => sample.timeout).length,
        networkFailures: normal.filter(sample => sample.failure).length };
    stage.gateLatency = distribution(normal.filter(value => value.endpoint === '/tracking/state').map(value => value.durationMs).filter(Number.isFinite));
    stage.teacherControlLatency = distribution(stage.teacherActions.map(value => value.durationMs)); stage.authLatency = distribution(stage.authDurationsMs);
    const ledger = [...expected.entries()].filter(([, value]) => value.stageStudents === stage.studentCount); stage.expectedEventCount = ledger.length;
    stage.eventTypeCounts = {}; const sessions = new Map();
    for (const [, event] of ledger) {
        stage.eventTypeCounts[event.type] = (stage.eventTypeCounts[event.type] || 0) + 1;
        const key = event.userId + '|' + event.sessionUid; const values = sessions.get(key) || []; values.push(event); sessions.set(key, values);
    }
    stage.heartbeatCadence = [...sessions.values()].map(values => {
        values.sort((a, b) => a.occurredAt - b.occurredAt); const gaps = values.slice(1).map((event, index) => event.occurredAt - values[index].occurredAt);
        return { userId: values[0].userId, sessionUid: values[0].sessionUid,
            idealHeartbeatCount: Math.max(0, Math.floor((values.at(-1).occurredAt - values[0].occurredAt - 1) / 15000)),
            observedHeartbeatCount: values.filter(event => event.type === 'heartbeat').length, maxSignalGapMs: gaps.length ? Math.max(...gaps) : 0 };
    });
    stage.unacknowledgedExpectedEvents = ledger.filter(([key]) => !stage.acknowledgedEventKeys.has(key)).map(([, value]) => ({ userId: value.userId, sessionUid: value.sessionUid, seq: value.seq }));
    stage.channels = actors.flatMap(actor => actor.channels.map(channel => ({ userId: actor.userId, channel: channel.index,
        category: channel.cfg.page.category, pageKey: channel.cfg.page.key, states: channel.states, sessions: channel.sessions, remainingQueue: channel.queue.length })));
    const pending = stage.channels.reduce((total, channel) => total + channel.remainingQueue, 0);
    stage.criteria = { everyChannelActivated: actors.length === stage.studentCount && actors.every(actor => actor.channels.every(channel => channel.observedActive)),
        everyChannelStopped: actors.every(actor => actor.channels.every(channel => !channel.active)),
        noPreScheduleLearningEvents: ledger.every(([, value]) => value.occurredAt >= stage.plannedStartMs),
        allGeneratedEventsAcknowledged: stage.unacknowledgedExpectedEvents.length === 0, queuesDrained: pending === 0,
        exactlyOneContinuousSessionPerChannel: stage.channels.every(channel => channel.sessions.length === 1),
        noUnexplainedSignalGap: stage.heartbeatCadence.every(value => value.maxSignalGapMs <= 45000),
        normalHttpErrorsZero: normal.every(sample => sample.status >= 200 && sample.status < 400),
        unexpectedHttp4xxZero: stage.normalFailures.http4xx === 0, http5xxZero: stage.normalFailures.http5xx === 0, normalTimeoutsZero: stage.normalFailures.timeouts === 0,
        noUnexpectedPolicyDrops: normal.filter(sample => sample.endpoint === '/events' && sample.status === 200).every(sample => sample.policyDropped === 0),
        normalNetworkFailuresZero: normal.every(sample => !sample.failure), normalRequestsComplete: normal.every(sample => Number.isFinite(sample.durationMs)),
        gateP99WithinProductionFetchDeadline: stage.gateLatency.p99Ms !== null && stage.gateLatency.p99Ms < 5000,
        serverLeaseNeverExtended: normal.filter(sample => sample.endpoint === '/tracking/state' && sample.status === 200).every(sample => sample.serverLeaseMs <= 10000),
        authorizedScopeIdsMatch: stage.channels.every(channel => channel.states.filter(value => value.active).every(value => value.activityIds.every(id =>
            stage.activities.some(activity => Number(activity.id) === Number(id) && activity.contextId === (groups[channel.channel].contextId || 'group:' + groups[channel.channel].id))))),
        overlappingScheduleTransitionsMatch: stage.authorizationCheckpoints.length >= 3 && stage.authorizationCheckpoints.every(value => value.issues.length === 0),
        statisticsMatch: stage.statistics.length === stage.studentCount,
        existingWeeklyMonthlyTotalsPreserved: stage.periodDeltaChecks?.length === stage.studentCount * 2 && stage.periodDeltaChecks.every(value => value.passed) };
    stage.passedClientCriteria = Object.values(stage.criteria).every(Boolean);
}
async function runStage(size) {
    const stage = { studentCount: size, channelCount: size * 2, phase: 'setup', startedAt: new Date().toISOString(), requests: [], activities: [],
        teacherActions: [], authDurationsMs: [], resources: [], authorizationCheckpoints: [], statistics: [], acknowledgedEventKeys: new Set() };
    stage.toJSON = function () { return { ...this, acknowledgedEventKeys: [...this.acknowledgedEventKeys] }; }; report.stages.push(stage);
    const actors = [];
    let samplingCount = 0;
    progressTimer = setInterval(() => {
        const resource = { at: Date.now(), freeMiB: Math.round(os.freemem() / 1048576), nodeRssMiB: Math.round(process.memoryUsage().rss / 1048576) }; stage.resources.push(resource);
        const timeouts = stage.requests.filter(sample => sample.endpoint === '/tracking/state' && sample.timeout && ['waiting', 'active', 'drain'].includes(sample.phase)).length;
        const httpErrors = stage.requests.filter(sample => sample.status >= 400 && ['waiting', 'active', 'drain'].includes(sample.phase)).length;
        if (!stage.safetyStop && (resource.freeMiB < report.harnessSafety.minimumFreeMiB || timeouts >= report.harnessSafety.maximumNormalGateTimeoutsBeforeStop || httpErrors >= report.harnessSafety.maximumUnexpectedHttpErrorsBeforeStop)) {
            stage.safetyStop = { at: resource.at, freeMiB: resource.freeMiB, gateTimeouts: timeouts, unexpectedHttpErrors: httpErrors,
                reason: resource.freeMiB < report.harnessSafety.minimumFreeMiB ? 'The host approached its 1 GiB free-memory safety boundary.'
                    : timeouts >= report.harnessSafety.maximumNormalGateTimeoutsBeforeStop ? 'Repeated five-second gate timeouts make this requested load a failed capacity stage.'
                        : 'Unexpected HTTP rejection makes this requested load a failed stage.' };
            for (const actor of actors.filter(Boolean)) for (const channel of actor.channels) channel.stop();
            if (browser) void browser.close().catch(() => {});
            console.log('HTTP safety stop: ' + stage.safetyStop.reason); save();
        }
        if (++samplingCount % 10 === 0) { console.log(`HTTP ${size} students/${size * 2} channels: ${stage.phase}; requests ${stage.requests.length}; events ${[...expected.values()].filter(value => value.stageStudents === size).length}`); save(); }
    }, 1000);
    try {
        if (!teacher || (!teacher.page && !teacherApiOnly)) teacher = await loginTeacher();
        await pool(config.students.slice(0, size), 4, async (account, index) => { ensureSafe(stage); actors[index] = await prepare(account, stage); }); ensureSafe(stage);
        await ownedResourceSnapshot('after-' + size + '-http-students-prepared');
        stage.phase = 'baseline';
        stage.baselineStatistics = await pool(actors, 4, async actor => { ensureSafe(stage); actor.baselineStatistics = await statistics(actor, stage); return actor.baselineStatistics; }); ensureSafe(stage);
        if (!teacherApiOnly) {
            await teacher.page.goto(base + '/oj-console/addons/focus-tracker'); teacher.cfg = await teacher.page.locator('[data-ftk-dashboard]').evaluate(node => JSON.parse(node.getAttribute('data-config')));
            const storageState = await teacher.context.storageState(); const userAgent = await teacher.page.evaluate(() => navigator.userAgent);
            teacher.request = await playwright().request.newContext({ baseURL: base, storageState, userAgent, timeout: 15000 }); actorsToDispose.push(teacher.request);
            await until(async () => await teacher.page.locator('[data-field="context"] option').count() > 1, 'The actual teacher group list failed.');
        }
        stage.phase = 'waiting';
        const start = Math.ceil((Date.now() + 45000) / 1000) * 1000; stage.plannedStartMs = start; stage.plannedEndMs = start + 90000;
        await createActivity(stage, groups[0], `HTTP load ${size} A natural`, start, start + 90000);
        const overlap = await createActivity(stage, groups[0], `HTTP load ${size} A overlap`, start, start + 120000);
        const secondary = await createActivity(stage, groups[1], `HTTP load ${size} B`, start, start + 120000);
        // Retain genuine teacher UI creation, then release its renderers before
        // loading the server. Later controls retain the same cookies/nonce/UA.
        if (!teacherApiOnly) { await teacher.context.close(); teacher.page = null; teacher.context = null; await browser.close(); browser = null; }
        stage.teacherControlMode = report.teacherMode;
        await ownedResourceSnapshot(teacherApiOnly ? 'api-only-no-teacher-chrome-before-channel-load' : 'teacher-chrome-closed-before-channel-load');
        fs.writeFileSync(path.join(artifacts, 'channel-start-' + size + '.json'), JSON.stringify({ at: Date.now(), plannedStartMs: start, studentCount: size, channels: size * 2 }) + '\n', 'utf8');
        console.log(`HTTP ${size} students/${size * 2} channels: schedules created; native gate probes starting; planned UTC ${new Date(start).toISOString()}`);
        for (const actor of actors) for (const channel of actor.channels) void channel.probe();
        await waitTo(stage, start); stage.phase = 'active';
        const actions = [
            [15000, async () => checkpoint(stage, actors, 'Both native groups active')],
            [25000, () => teacherApi('/activities/' + overlap.id, { ends_at: new Date(start + 100000).toISOString() }, stage, 'shorten overlapping A')],
            [45000, () => teacherApi('/activities/' + overlap.id, { status: 'closed' }, stage, 'early-close overlap while A natural continues')],
            [55000, () => teacherApi('/activities/' + secondary.id, { ends_at: new Date(start + 80000).toISOString() }, stage, 'shorten B to natural end')],
            [65000, async () => checkpoint(stage, actors, 'A persists after overlap close; B active before edited end')],
            [85000, async () => checkpoint(stage, actors, 'B stopped at edited end while A stays active')],
        ];
        for (const [offset, action] of actions) { await waitTo(stage, start + offset); await action(); }
        await waitTo(stage, start + 90000); stage.phase = 'drain';
        await until(() => { ensureSafe(stage); return actors.every(actor => actor.channels.every(channel => !channel.active)); }, 'A channel retained authorization after the natural deadline/lease.', 15000);
        await until(() => { ensureSafe(stage); return actors.every(actor => actor.channels.every(channel => channel.queue.length === 0 && !channel.flushBusy)); }, 'Native HTTP queues failed to receive ACKs.', 90000);
        await until(() => { ensureSafe(stage); return stage.requests.every(sample => Number.isFinite(sample.durationMs)); }, 'A native HTTP request remained incomplete.', 15000);
        stage.phase = 'verification'; stage.statistics = await pool(actors, 4, actor => { ensureSafe(stage); return statistics(actor, stage); }); ensureSafe(stage); verifyPeriodDeltas(stage, actors); summarize(stage, actors);
        console.log(`HTTP stage ${size}: ${stage.passedClientCriteria ? 'client PASS; independent native SQL audit required' : 'FAIL'}.`);
        if (!stage.passedClientCriteria) throw new Error('HTTP protocol load criteria failed.');
    } finally {
        clearInterval(progressTimer); stage.phase = 'cleanup';
        for (const actor of actors.filter(Boolean)) for (const channel of actor.channels) channel.stop();
        fs.writeFileSync(path.join(artifacts, 'channel-stop-' + size + '.json'), JSON.stringify({ at: Date.now(), safetyStop: stage.safetyStop || null }) + '\n', 'utf8');
        if (!stage.passedClientCriteria && teacher.cfg) {
            const hasRecordedEvents = [...expected.values()].some(row => row.stageStudents === size)
                || actors.filter(Boolean).some(actor => actor.channels.some(channel => channel.observedActive));
            try {
                stage.cleanup = await cleanupFailedActivities({ activities: stage.activities, hasRecordedEvents, teacherUserId: teacher.userId,
                    identity: async () => {
                        const response = await teacher.request.get(base + '/wp-json/wp/v2/users/me', { headers: { 'X-WP-Nonce': teacher.cfg.nonce }, timeout: 15000 });
                        assert.equal(response.status(), 200); return (await response.json()).id;
                    }, request: async (suffix, method, body) => {
                        const value = await nativeRequest(teacher, teacher.cfg, suffix, method, body, stage, 'teacher-control', 15000);
                        stage.teacherActions.push({ name: 'failure cleanup own schedule', method, at: Date.now(), status: value.status, durationMs: value.durationMs });
                        return value;
                    } });
            } catch (error) { stage.cleanupError = safeFailure(error); }
        }
        await until(() => actors.filter(Boolean).every(actor => actor.channels.every(channel => !channel.gateBusy && !channel.flushBusy)), 'Stopped HTTP clients still had requests in flight.', 16000).catch(error => { stage.cleanupWarning = safeFailure(error); });
        if (!stage.passedClientCriteria) summarize(stage, actors.filter(Boolean));
        stage.finishedAt = new Date().toISOString(); save();
        await pool(actors.filter(Boolean), 4, actor => actor.request.dispose().catch(() => {}));
    }
}
(async () => {
    const before = await ownedResourceSnapshot(teacherApiOnly ? 'before-native-http-login-no-browser' : 'before-browser-launch');
    if (before.freeMiB < report.harnessSafety.minimumFreeMiB) {
        report.preflightSafetyStop = { at: before.at, freeMiB: before.freeMiB, reason: 'The host is below 1 GiB free before any login or channel traffic.' };
        throw new Error(report.preflightSafetyStop.reason);
    }
    teacher = await loginTeacher(); for (const size of sizes) await runStage(size); report.passedClientCriteria = report.stages.every(stage => stage.passedClientCriteria);
})()
    .catch(error => { report.failure = safeFailure(error); console.error(report.failure); process.exitCode = 1; })
    .finally(async () => { clearInterval(progressTimer); for (const request of actorsToDispose) await request.dispose().catch(() => {});
        if (browser) await browser.close(); report.finishedAt = new Date().toISOString(); save(); console.log('Native HTTP protocol report: ' + path.join(artifacts, 'browser-report.json')); });
