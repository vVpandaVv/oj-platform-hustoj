'use strict';

// Real WordPress cookies/nonces, real Chromium targets and native REST traffic.
// No successful response substitution, clock override, visibility/focus property
// replacement or relaxed production timer. Headless visibility is measured,
// never assumed to model a physically hidden tab. DB audit is a separate phase.
const assert = require('node:assert/strict');
const fs = require('node:fs'); const path = require('node:path'); const os = require('node:os');
const { nativeCookieLogin, dashboardConfig } = require('./native-cookie-client.cjs');
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
const popupTabs = process.env.FOCUS_MYSQL_LOAD_POPUP_TABS === '1';
assert.ok(config.baseUrl && config.teacher && config.students?.length && config.material?.url, 'The native load fixture is incomplete.');
const base = config.baseUrl.replace(/\/$/, ''); const origin = new URL(base).origin;
assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname), 'Use the disposable loopback native site only.');
const groupValues = Array.isArray(config.groups) ? config.groups : Object.values(config.groups || {});
const groups = [groupValues.find(value => value.key === 'A') || groupValues[0] || config.group,
    groupValues.find(value => value.key === 'B') || groupValues[1]];
assert.ok(groups[0]?.id && groups[1]?.id, 'Two real groups are required.');
const stageSizes = (process.env.FOCUS_MYSQL_LOAD_STAGES || '10,30,60').split(',').map(Number);
assert.ok(stageSizes.every(value => Number.isInteger(value) && value > 0 && value <= config.students.length && value <= 60), 'The fixture must contain every requested synthetic student.');
const artifacts = path.resolve(process.env.FOCUS_MYSQL_LOAD_ARTIFACTS || path.join(path.dirname(path.resolve(configPath)), 'browser-load-' + Date.now()));
fs.mkdirSync(artifacts, { recursive: true });
const secrets = [config.teacher, ...config.students].flatMap(value => [value.password, value.username]).filter(value => typeof value === 'string' && value.length > 3);
function sanitize(value) { let text = String(value); for (const secret of secrets) text = text.split(secret).join('[redacted]');
    return text.replace(/([?&]_wpnonce=)[^&\s]+/g, '$1[redacted]'); }
function safeFailure(error) {
    return sanitize(String(error.message || error).split(/\r?\n/)[0] + '\n' + String(error.stack || '').split(/\r?\n/)
        .filter(line => /^\s+at /.test(line) && line.includes(__filename)).join('\n'));
}
const report = { startedAt: new Date().toISOString(), baseUrl: base, mode: 'real-chromium-cookie-load', requestedStages: stageSizes,
    targetVersion: process.env.FOCUS_MYSQL_LOAD_VERSION || null, phpWorkers: Number(process.env.FOCUS_MYSQL_LOAD_PHP_WORKERS) || null,
    mysqlBufferPoolMiB: Number(process.env.FOCUS_MYSQL_LOAD_MYSQL_BUFFER_MIB) || 512,
    teacherMode: teacherApiOnly ? 'Native cookie/nonce REST only; teacher UI is outside this run.' : 'Actual teacher Chrome UI creation and native cookie/nonce controls.',
    tabCreationMode: popupTabs ? 'A real window.open popup from each learner first page; browser chooses native renderer allocation.' : 'Independent context.newPage calls.',
    productionTimersUnchanged: true, removedAutomationFlags: ['--disable-background-timer-throttling', '--disable-backgrounding-occluded-windows', '--disable-renderer-backgrounding'],
    focusEmulationEnabled: false, visibilityBoundary: 'Actual headless Chromium visibility/focus values are recorded. Simultaneous foreground users or true OS-hidden tabs are not fabricated.',
    memorySafetyBoundary: 'Stop before adding a student context when OS free memory is below max(2048 MiB,10% total); do not close unrelated applications.',
    acceptance: { allowedAcknowledgedRecordLoss: 0, duplicateStoredEvents: 0, outsideScopeOrDeadlineStoredEvents: 0,
        steadyStateHttp5xx: 0, steadyStateHttp429: 0, steadyStateTimeouts: 0, gateP99MustBeBelowMs: 5000, gateP99ReferenceGoalMs: 1000,
        exclusions: 'Cleanup/navigation cancellations and any explicitly labeled security/privacy probes are reported separately. This runner sends no security/privacy refusal probes.' },
    stages: [], expectedEvents: [], passedClientCriteria: false, passedClientCriteriaWithinObservedScope: false,
    achievedRequestedStage: false, omittedLargerStages: [], requiresReadOnlyDatabaseAudit: true };
const expected = new Map(); const networkObserved = new Set(); const tokenMetadata = new Map(); const contexts = []; const nativeRequests = [];
let browser; let teacher; let activeStage; let samplingTimer;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
function route(value) { const url = new URL(value); return (url.searchParams.get('rest_route') || url.pathname.replace(/^.*\/wp-json/, '')).replace(/\/$/, ''); }
function percentile(values, fraction) { const sorted = values.filter(Number.isFinite).sort((a, b) => a - b); return sorted.length ? sorted[Math.min(sorted.length - 1, Math.ceil(sorted.length * fraction) - 1)] : null; }
function distribution(values) { return { count: values.length, p50Ms: percentile(values, 0.5), p95Ms: percentile(values, 0.95), p99Ms: percentile(values, 0.99), maxMs: values.length ? Math.max(...values) : null }; }
function resourceSample(stage) { const value = { at: Date.now(), freeMiB: Math.round(os.freemem() / 1048576), totalMiB: Math.round(os.totalmem() / 1048576), nodeRssMiB: Math.round(process.memoryUsage().rss / 1048576) }; stage.resources.push(value); return value; }
function save() { report.expectedEvents = [...expected.values()]; report.updatedAt = new Date().toISOString(); fs.writeFileSync(path.join(artifacts, 'browser-report.json'), JSON.stringify(report, null, 2) + '\n', 'utf8'); }
async function until(fn, message, timeout = 30000) { const end = Date.now() + timeout;
    do { const value = await fn(); if (value) return value; await sleep(100); } while (Date.now() < end); throw new Error(message); }
async function pool(values, count, fn) { let next = 0; const result = new Array(values.length);
    let firstError;
    await Promise.all(Array.from({ length: Math.min(count, values.length) }, async () => {
        while (next < values.length && !firstError) {
            const index = next++;
            try { result[index] = await fn(values[index], index); } catch (error) { firstError ||= error; }
        }
    }));
    if (firstError) throw firstError;
    return result;
}
function pageMetadata(token, fallback) {
    if (tokenMetadata.has(token)) return tokenMetadata.get(token);
    // These are public server-issued claims, not a client-created classification.
    // The independent native audit verifies the signature and stored metadata.
    try { const value = JSON.parse(Buffer.from(String(token).split('.')[0], 'base64url').toString('utf8'));
        if (typeof value.category === 'string' && typeof value.key === 'string') return value;
    } catch (_) { /* Unsigned legacy data remains visibly unresolved. */ }
    return fallback;
}
function addEvents(payload, actor, stage, fallback = {}, source = 'network') {
    if (!payload?.events || !Array.isArray(payload.events)) return [];
    const metadata = pageMetadata(payload.page_context, fallback);
    return payload.events.map(event => {
        const key = actor.userId + '|' + payload.session_uid + '|' + event.seq;
        if (source === 'network') {
            if (networkObserved.has(key)) stage.retransmittedEventObservations += 1;
            networkObserved.add(key);
        }
        const value = { userId: actor.userId, sessionUid: payload.session_uid, sessionStartedAt: Number(payload.session_started_at), seq: Number(event.seq),
            occurredAt: Number(event.occurred_at), type: event.type, problemId: payload.problem_id || null,
            pageKey: metadata.key || null, pageCategory: metadata.category || null, pageSubtype: metadata.subtype || null,
            contextType: payload.context_type || metadata.contextType || 'general', contextId: payload.context_id ?? metadata.contextId ?? null, stageStudents: stage.studentCount,
            visible: event.meta?.visible, focused: event.meta?.focused };
        if (expected.has(key)) {
            const prior = expected.get(key); if (prior.occurredAt !== value.occurredAt || prior.type !== value.type) stage.sequenceCollisions.push({ userId: actor.userId, sessionUid: payload.session_uid, seq: event.seq });
            if (!prior.pageKey && value.pageKey) Object.assign(prior, { pageKey: value.pageKey, pageCategory: value.pageCategory, pageSubtype: value.pageSubtype });
        } else expected.set(key, value);
        return key;
    });
}
function watch(page, actor, stage, tab) {
    const records = new WeakMap();
    page.on('pageerror', error => stage.pageErrors.push({ userId: actor.userId, tab, message: sanitize(error.message) }));
    page.on('crash', () => stage.pageErrors.push({ userId: actor.userId, tab, message: 'renderer crashed' }));
    page.on('request', request => {
        const endpoint = route(request.url()); if (!endpoint.startsWith('/focus-tracker/v1/')) return;
        const sample = { endpoint: endpoint.replace(/\/activities\/\d+/g, '/activities/:id').replace(/\/checks\/\d+/g, '/checks/:id'),
            phase: stage.phase, userId: actor.userId, tab, startedAt: Date.now(), method: request.method() };
        stage.requests.push(sample); records.set(request, sample);
        if (endpoint.endsWith('/events')) {
            let body; try { body = request.postDataJSON(); } catch (_) { body = null; }
            if (body) { sample.eventKeys = addEvents(body, actor, stage); sample.clientSessionUid = body.session_uid; }
            else { sample.unreadableBody = true; sample.resourceType = request.resourceType(); stage.unreadableEventRequests += 1; }
        }
        if (endpoint.endsWith('/checks/next')) {
            let body; try { body = request.postDataJSON(); } catch (_) { body = null; }
            // Measure the real wire request without retaining its source text.
            sample.draftBytes = typeof body?.draft?.source === 'string' ? Buffer.byteLength(body.draft.source, 'utf8') : 0;
        }
    });
    page.on('response', async response => {
        const sample = records.get(response.request()); if (!sample) return;
        sample.status = response.status();
        let data; try { data = await response.json(); } catch (_) { data = {}; }
        sample.code = typeof data.code === 'string' ? data.code : undefined;
        sample.result = typeof data.status === 'string' ? data.status : undefined;
        if (sample.endpoint.endsWith('/tracking/state') && Number.isFinite(data.validUntilMs) && Number.isFinite(data.serverNowMs)) sample.serverLeaseMs = data.validUntilMs - data.serverNowMs;
        if (sample.endpoint.endsWith('/events')) {
            sample.acknowledged = data.acknowledged === true; sample.stored = Number(data.stored) || 0;
            sample.policyDropped = Number(data.policyDropped) || 0; sample.discarded = data.discarded === true;
            if (sample.acknowledged && sample.eventKeys) sample.eventKeys.forEach(key => stage.acknowledgedEventKeys.add(key));
        }
        await response.finished(); const timing = response.request().timing();
        sample.durationMs = timing.responseEnd >= 0 ? timing.responseEnd : Date.now() - sample.startedAt;
    });
    page.on('requestfailed', request => { const sample = records.get(request); if (!sample) return;
        sample.failure = sanitize(request.failure()?.errorText || 'network failure'); sample.durationMs = Date.now() - sample.startedAt; });
}
async function restoreNativeFocus(context, page) { const cdp = await context.newCDPSession(page); await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: false }); await cdp.detach(); }
async function observe(context, actor, stage) {
    await context.exposeBinding('__focusLoadObserve', (source, value) => {
        if (source.frame !== source.page.mainFrame()) return;
        const tab = actor.pages.indexOf(source.page); if (tab < 0) return;
        if (value.kind === 'outbox-created') {
            stage.nativeStorageEventObservations += 1;
            addEvents({ ...value.entry, events: [value.entry.event] }, actor, stage, { key: value.entry.page_key }, 'outbox');
            return;
        }
        if (value.kind === 'gate') { actor.latestGates[tab] = value; if (value.active) actor.seenActive[tab] = true; }
        const prior = actor.observations.at(-1);
        if (!prior || value.kind !== 'gate' || prior.active !== value.active || prior.reason !== value.reason || prior.tab !== tab) actor.observations.push({ ...value, tab });
    });
    await context.addInitScript(() => {
        if (window !== window.top) return;
        function emit(kind, extra) {
            const value = { kind, at: Date.now(), visible: document.visibilityState, focused: document.hasFocus(), ...extra };
            window.__focusLoadObserve(value).catch(() => {});
        }
        document.addEventListener('ftk:activity-gate', event => emit('gate', { active: event.detail.active, reason: event.detail.reason,
            serverNowMs: event.detail.nowMs, activeUntilMs: event.detail.activeUntilMs, validUntilMs: event.detail.validUntilMs, activityIds: event.detail.activityIds }));
        document.addEventListener('visibilitychange', () => emit('visibility'));
        window.addEventListener('focus', () => emit('focus')); window.addEventListener('blur', () => emit('blur'));
        // A native storage event exposes an actual write from the other tab.
        // Neither Storage methods nor product timers/state are replaced.
        window.addEventListener('storage', event => {
            if (!event.key?.startsWith('focus-tracker-outbox-v2:') || !event.newValue) return;
            try {
                const entry = JSON.parse(event.newValue);
                if (entry?.event && Number.isInteger(entry.event.seq)) {
                    window.__focusLoadObserve({ kind: 'outbox-created', entry }).catch(() => {});
                }
            } catch (_) { /* Unrelated malformed origin storage is ignored. */ }
        });
        document.addEventListener('DOMContentLoaded', () => emit('dom-ready'), { once: true });
    });
}
async function login(context, account, stage) {
    const page = await context.newPage(); const at = Date.now(); page.setDefaultNavigationTimeout(90000);
    await restoreNativeFocus(context, page); await page.goto(base + '/login');
    const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="oj_login"]') });
    await form.locator('[name="user_login"]').fill(account.username); await form.locator('[name="password"]').fill(account.password);
    await form.getByRole('button', { name: '로그인', exact: true }).click();
    await until(async () => (await context.cookies(base)).some(cookie => cookie.name.startsWith('wordpress_logged_in_')), 'Native WordPress cookie login failed for actor ' + account.userId, 60000);
    await page.waitForLoadState('domcontentloaded');
    stage.authDurationsMs.push(Date.now() - at); return page;
}
function resource(group, problem) {
    const value = new URL(problem ? (config.problem.problemUrl || config.problem.url || '/problems/' + config.problem.id) : config.material.url, base);
    assert.equal(value.origin, origin); value.searchParams.set('group_id', String(group.id)); return value.href;
}
async function prepareStudent(account, index, stage) {
    const resourceState = resourceSample(stage);
    if (resourceState.freeMiB < Math.max(2048, resourceState.totalMiB * 0.1)) {
        const error = new Error('Host memory safety boundary reached before adding another synthetic client.');
        error.code = 'HOST_MEMORY_BOUNDARY'; throw error;
    }
    const context = await browser.newContext({ timezoneId: 'Asia/Seoul' }); contexts.push(context);
    const actor = { userId: Number(account.userId), context, pages: [], configs: [], observations: [], latestGates: [], seenActive: [false, false] };
    await observe(context, actor, stage);
    const first = await login(context, account, stage); actor.pages.push(first); watch(first, actor, stage, 0);
    async function loadTab(page, tab) {
        const response = await page.goto(resource(groups[tab], tab === 1 && Boolean(config.problem?.id)));
        assert.equal(response?.status(), 200, 'The native learner page must render successfully: ' + new URL(page.url()).pathname);
        const cfg = await page.locator('#focus-tracker-config').evaluate(element => JSON.parse(element.textContent)); actor.configs[tab] = cfg;
        assert.equal(Number(cfg.userId), actor.userId, 'Native bootstrap identity must match the cookie account.');
        assert.equal(Number(cfg.activityGate.pollMs), 5000); assert.equal(Number(cfg.heartbeatMs), 15000); assert.equal(Number(cfg.flushMs), 7000);
        assert.ok(cfg.activityGate.timeoutMs === undefined || Number(cfg.activityGate.timeoutMs) === 5000, 'The production five-second gate fetch deadline is unchanged.');
        if (cfg.checks?.enabled) { assert.equal(Number(cfg.checks.initialDelayMs), 45000); assert.equal(Number(cfg.checks.pollMs), 60000); }
        assert.ok(cfg.page?.token && cfg.nonce !== 'focus-test-nonce');
        tokenMetadata.set(cfg.page.token, { ...cfg.page, contextType: 'group', contextId: groups[tab].contextId || 'group:' + groups[tab].id });
    }
    await loadTab(first, 0);
    let second;
    if (popupTabs) {
        const popup = first.waitForEvent('popup', { timeout: 15000 });
        await first.evaluate(() => { window.open('about:blank', '_blank'); });
        second = await popup; assert.equal(await second.opener(), first, 'The second target must be a real popup of this learner page.');
    } else second = await context.newPage();
    actor.pages.push(second); watch(second, actor, stage, 1); await restoreNativeFocus(context, second);
    await loadTab(second, 1); actor.popupOpenerAfterNavigation = popupTabs ? await second.opener() === first : null;
    await first.bringToFront(); actor.index = index;
    const after = resourceSample(stage);
    if (after.freeMiB < Math.max(2048, after.totalMiB * 0.1)) {
        await context.close();
        const error = new Error('The latest two-target context crossed the host memory boundary and was closed before scheduling.');
        error.code = 'HOST_MEMORY_BOUNDARY'; throw error;
    }
    return actor;
}
function apiUrl(cfg, suffix) {
    const url = new URL(cfg.restUrl); const [part, query] = suffix.split('?');
    if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/events$/, '').replace(/\/$/, '') + part);
    else url.pathname = url.pathname.replace(/\/events$/, '').replace(/\/$/, '') + part;
    for (const [key, value] of new URLSearchParams(query || '')) url.searchParams.set(key, value); return url.href;
}
async function api(actor, suffix, method = 'GET', body) {
    const cfg = actor.config || actor.configs[0];
    if (actor.request) {
        const at = performance.now(); const response = await actor.request.fetch(apiUrl(cfg, suffix), { method, timeout: method === 'GET' ? 60000 : 15000,
            headers: { 'X-WP-Nonce': cfg.nonce, ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) }, ...(body === undefined ? {} : { data: body }) });
        let data; try { data = await response.json(); } catch (_) { data = {}; } return { status: response.status(), data, durationMs: performance.now() - at };
    }
    const page = actor.page || actor.pages[0];
    return page.evaluate(async input => {
        const at = performance.now(); const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), input.method === 'GET' ? 60000 : 15000);
        try {
            const response = await fetch(input.url, { method: input.method, credentials: 'same-origin', signal: controller.signal, headers: {
                'X-WP-Nonce': input.nonce, ...(input.body === undefined ? {} : { 'Content-Type': 'application/json' }),
            }, body: input.body === undefined ? undefined : JSON.stringify(input.body) });
            let data; try { data = await response.json(); } catch (_) { data = {}; }
            return { status: response.status, data, durationMs: performance.now() - at };
        } catch (error) { return { status: 0, data: {}, durationMs: performance.now() - at, failure: error.name }; }
        finally { clearTimeout(timer); }
    }, { url: apiUrl(cfg, suffix), nonce: cfg.nonce, method, body });
}
async function createActivity(stage, name, group, startMs, endMs) {
    if (teacherApiOnly) {
        const response = await api(teacher, '/activities', 'POST', { name, context_type: 'group', context_id: group.contextId || 'group:' + group.id,
            problem_ids: [], starts_at: new Date(startMs).toISOString(), ends_at: new Date(endMs).toISOString() });
        assert.ok(response.status >= 200 && response.status < 300 && response.data.id, 'The native cookie teacher must create the requested activity.');
        stage.activities.push(response.data); stage.teacherActions.push({ name: 'create ' + name, activityId: response.data.id,
            status: response.status, at: Date.now(), durationMs: response.durationMs, mode: 'native REST' }); return response.data;
    }
    const form = teacher.page.locator('[data-field="create-form"]');
    await form.locator('[data-field="name"]').fill(name); await form.locator('[data-field="context"]').selectOption('group:' + (group.contextId || 'group:' + group.id));
    const local = await teacher.page.evaluate(values => values.map(ms => { const d = new Date(ms); const p = value => String(value).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); }), [startMs, endMs]);
    await form.locator('[data-field="starts-at"]').fill(local[0]); await form.locator('[data-field="ends-at"]').fill(local[1]);
    const pending = teacher.page.waitForResponse(response => response.request().method() === 'POST' && route(response.url()) === '/focus-tracker/v1/activities');
    await form.getByRole('button', { name: '활동 만들기', exact: true }).click(); const response = await pending;
    assert.ok(response.ok(), 'Actual teacher activity creation failed.'); const activity = await response.json(); stage.activities.push(activity);
    await response.finished(); stage.teacherActions.push({ name: 'create ' + name, activityId: activity.id, status: response.status(),
        at: Date.now(), durationMs: response.request().timing().responseEnd });
    await teacher.page.locator(`[data-activity-id="${activity.id}"]`).waitFor();
    return activity;
}
async function mutation(stage, activity, body, name) {
    const at = Date.now(); const response = await api(teacher, '/activities/' + activity.id, 'PATCH', body);
    stage.teacherActions.push({ name, activityId: activity.id, at, status: response.status, durationMs: response.durationMs });
    assert.equal(response.status, 200, 'Actual teacher schedule mutation failed: ' + name);
}
async function sampleVisibility(clients, name) {
    return (await pool(clients, 6, async actor => Promise.all(actor.pages.map(async (page, tab) => ({ userId: actor.userId, tab, name,
        ...(await page.evaluate(() => ({ visible: document.visibilityState, focused: document.hasFocus(),
            questionCount: document.querySelectorAll('[data-ftk-check]').length,
            launcherCount: document.querySelectorAll('[data-ftk-check-launcher]').length,
            gateLabel: document.querySelector('[data-ftk-gate-label]')?.textContent || null }))) }))))).flat();
}
function authorizationCheckpoint(stage, clients, name) {
    const at = Date.now(); const issues = [];
    for (const actor of clients) for (const tab of [0, 1]) {
        const end = stage.plannedStartMs + (tab === 0 ? 90000 : 80000);
        const expectedActive = at >= stage.plannedStartMs + 10000 && at < end - 1000 ? true : (at > end + 1000 ? false : null);
        if (expectedActive === null) continue;
        const observed = actor.latestGates[tab];
        if (!observed || observed.active !== expectedActive || at - observed.at > 12000) issues.push({ userId: actor.userId, tab,
            expectedActive, observedActive: observed?.active, observedAt: observed?.at, reason: observed?.reason });
    }
    stage.authorizationCheckpoints.push({ name, at, issues });
}
async function readPending(actor, stage) {
    const values = await actor.pages[0].evaluate(() => Object.keys(localStorage).filter(key => key.startsWith('focus-tracker-outbox-v2:')).map(key => {
        try { const value = JSON.parse(localStorage.getItem(key)); return { session_uid: value.session_uid, session_started_at: value.session_started_at,
            problem_id: value.problem_id, context_type: value.context_type, context_id: value.context_id, page_key: value.page_key,
            page_context: value.page_context, event: value.event }; } catch (_) { return null; }
    }).filter(Boolean));
    for (const value of values) addEvents({ ...value, events: [value.event] }, actor, stage, { key: value.page_key }, 'outbox');
    return values.length;
}
async function verifyStatistics(actor, stage) {
    const result = { userId: actor.userId };
    for (const period of ['week', 'month']) {
        const value = await api(actor, '/me/statistics?period=' + period); const data = value.data;
        if (value.status !== 200 || Number(data.userId) !== actor.userId || !Array.isArray(data.pageCategories)) throw new Error('Native self statistics/identity rejected for actor ' + actor.userId);
        const sum = key => data.pageCategories.reduce((total, row) => total + (Number(row[key]) || 0), 0);
        result[period] = { status: value.status, durationMs: value.durationMs, activeSeconds: data.totals.activeSeconds, observedSeconds: data.totals.observedSeconds,
            categoryActiveSeconds: sum('activeSeconds'), categoryObservedSeconds: sum('observedSeconds'), dataStatus: data.totals.status };
        if (Math.abs(sum('activeSeconds') - data.totals.activeSeconds) > 0.01 || Math.abs(sum('observedSeconds') - data.totals.observedSeconds) > 0.01) stage.statisticsMismatches.push({ userId: actor.userId, period });
    }
    return result;
}
function summarize(stage, clients) {
    const statistics = {};
    for (const sample of stage.requests) {
        const key = sample.phase + ':' + sample.endpoint; const target = statistics[key] ||= { durations: [], statuses: {}, failures: 0, aborted: 0, probableFetchTimeouts: 0, incomplete: 0 };
        if (Number.isFinite(sample.durationMs)) target.durations.push(sample.durationMs); else target.incomplete += 1;
        if (sample.status) target.statuses[sample.status] = (target.statuses[sample.status] || 0) + 1;
        if (sample.failure) { target.failures += 1;
            if (/abort|cancel/i.test(sample.failure)) target.aborted += 1;
            if (/TIMED_OUT|TIMEOUT/i.test(sample.failure) || (/abort/i.test(sample.failure)
                && ((sample.endpoint.endsWith('/tracking/state') && sample.durationMs >= 4500)
                    || (sample.endpoint.endsWith('/events') && sample.durationMs >= 14500)))) target.probableFetchTimeouts += 1;
        }
    }
    stage.metrics = Object.fromEntries(Object.entries(statistics).map(([key, value]) => [key, { ...distribution(value.durations), statuses: value.statuses,
        failures: value.failures, aborted: value.aborted, probableFetchTimeouts: value.probableFetchTimeouts, incomplete: value.incomplete }]));
    stage.teacherControlLatency = distribution(stage.teacherActions.map(value => value.durationMs));
    const measured = stage.requests.filter(sample => ['waiting', 'active', 'drain'].includes(sample.phase));
    stage.steadyStateGateLatency = distribution(measured.filter(sample => sample.endpoint.endsWith('/tracking/state')).map(sample => sample.durationMs).filter(Number.isFinite));
    const ledger = [...expected.entries()].filter(([, value]) => value.stageStudents === stage.studentCount);
    stage.expectedEventCount = ledger.length; stage.logicalSessionCount = new Set(ledger.map(([, value]) => value.userId + ':' + value.sessionUid)).size;
    stage.expectedLogicalSessionsFromGate = 0;
    for (const actor of clients) for (const tab of [0, 1]) {
        let active = false;
        for (const observation of actor.observations.filter(value => value.kind === 'gate' && value.tab === tab)) {
            if (observation.active && !active) stage.expectedLogicalSessionsFromGate += 1;
            active = observation.active;
        }
    }
    stage.heartbeatCadence = [];
    const sessions = new Map();
    for (const [, value] of ledger) { const key = value.userId + ':' + value.sessionUid; const items = sessions.get(key) || []; items.push(value); sessions.set(key, items); }
    for (const items of sessions.values()) {
        items.sort((a, b) => a.occurredAt - b.occurredAt); const gaps = items.slice(1).map((value, index) => value.occurredAt - items[index].occurredAt);
        const duration = items.at(-1).occurredAt - items[0].occurredAt;
        stage.heartbeatCadence.push({ userId: items[0].userId, sessionUid: items[0].sessionUid,
            idealHeartbeatCount: Math.max(0, Math.floor((duration - 1) / 15000)), observedHeartbeatCount: items.filter(value => value.type === 'heartbeat').length,
            maxSignalGapMs: gaps.length ? Math.max(...gaps) : 0 });
    }
    stage.unacknowledgedExpectedEvents = ledger.filter(([key]) => !stage.acknowledgedEventKeys.has(key)).map(([, value]) => ({ userId: value.userId, sessionUid: value.sessionUid, seq: value.seq }));
    stage.clients = clients.map(actor => ({ userId: actor.userId, seenActive: actor.seenActive, latestGates: actor.latestGates,
        observations: actor.observations, popupOpenerAfterNavigation: actor.popupOpenerAfterNavigation,
        pageCategories: actor.configs.map(cfg => ({ category: cfg.page.category, subtype: cfg.page.subtype, key: cfg.page.key })) }));
    stage.criteria = { everyTabActivated: clients.length === stage.studentCount && clients.every(actor => actor.seenActive.every(Boolean)),
        everyTabStopped: clients.every(actor => actor.latestGates.length === 2 && actor.latestGates.every(value => !value.active)),
        sequenceCollisionFree: stage.sequenceCollisions.length === 0, expectedEventsAcknowledged: stage.unacknowledgedExpectedEvents.length === 0,
        logicalSessionCountMatches: stage.logicalSessionCount === stage.expectedLogicalSessionsFromGate && stage.logicalSessionCount >= stage.tabCount,
        noUnexplainedSignalGap: stage.heartbeatCadence.every(value => value.maxSignalGapMs <= 45000),
        authorizedScopeIdsMatch: clients.every(actor => actor.observations.filter(value => value.kind === 'gate' && value.active).every(value =>
            (value.activityIds || []).every(id => stage.activities.filter(activity => activity.contextId === (groups[value.tab].contextId || 'group:' + groups[value.tab].id)).some(activity => Number(activity.id) === Number(id))))),
        completeSignedMetadata: ledger.every(([, value]) => value.pageKey && value.pageCategory && value.contextType === 'group'),
        noPreScheduleLearningEvents: ledger.every(([, value]) => value.occurredAt >= stage.plannedStartMs),
        noCheckRequestsOutsideSchedules: stage.requests.filter(sample => sample.endpoint.endsWith('/checks/next')).every(sample =>
            sample.startedAt >= stage.plannedStartMs && sample.startedAt < stage.plannedStartMs + (sample.tab === 0 ? 90000 : 80000)),
        queuesDrained: stage.remainingOutboxEvents === 0, normalHttpErrorsZero: measured.every(sample => !sample.status || sample.status < 400),
        noUnexpectedPolicyDrops: measured.filter(sample => sample.endpoint.endsWith('/events') && sample.status === 200).every(sample => sample.policyDropped === 0),
        normalNetworkFailuresZero: measured.every(sample => !sample.failure), normalRequestsComplete: measured.every(sample => Number.isFinite(sample.durationMs)),
        gateP99WithinProductionFetchDeadline: stage.steadyStateGateLatency.p99Ms !== null && stage.steadyStateGateLatency.p99Ms < 5000,
        serverLeaseNeverExtended: measured.filter(sample => sample.endpoint.endsWith('/tracking/state') && sample.status === 200).every(sample => sample.serverLeaseMs <= 10000),
        authorizationMatchesOverlappingSchedules: stage.authorizationCheckpoints.length >= 3 && stage.authorizationCheckpoints.every(value => value.issues.length === 0),
        questionsClosedOutsideSchedule: stage.visibilitySamples.filter(value => ['before-window', 'after-window'].includes(value.name))
            .every(value => value.questionCount === 0 && value.launcherCount === 0),
        javascriptErrorsZero: stage.pageErrors.length === 0,
        statisticsMatch: stage.statisticsMismatches.length === 0 && stage.statistics.length === stage.studentCount };
    stage.passedClientCriteria = Object.values(stage.criteria).every(Boolean);
}
async function stageRun(size) {
    const stage = { requestedStudentCount: size, studentCount: size, tabCount: size * 2, phase: 'setup', startedAt: new Date().toISOString(), requests: [], authDurationsMs: [], resources: [],
        teacherActions: [], activities: [], sequenceCollisions: [], pageErrors: [], visibilitySamples: [], authorizationCheckpoints: [], statistics: [], statisticsMismatches: [],
        retransmittedEventObservations: 0, nativeStorageEventObservations: 0, unreadableEventRequests: 0, acknowledgedEventKeys: new Set(), remainingOutboxEvents: null };
    activeStage = stage; report.stages.push(stage);
    // Keep the live Set usable by late ACK handlers and serialize its keys.
    stage.toJSON = function () { const value = { ...this }; value.acknowledgedEventKeys = this.acknowledgedEventKeys instanceof Set ? [...this.acknowledgedEventKeys] : this.acknowledgedEventKeys; return value; };
    samplingTimer = setInterval(() => { resourceSample(stage); console.log(`Load actual ${stage.studentCount} students/${stage.tabCount} targets (requested ${size}): ${stage.phase}; API requests ${stage.requests.length}; expected events ${[...expected.values()].filter(value => value.stageStudents === stage.studentCount).length}`); save(); }, 10000);
    const clients = [];
    try {
        console.log(`Preparing ${size} native student accounts with two real Chromium targets each.`);
        // Sequential preparation makes the host-memory boundary observable
        // before another two-target browser context is added.
        try {
            await pool(config.students.slice(0, size), Number(process.env.FOCUS_MYSQL_LOAD_PREPARE_CONCURRENCY) || 1, async (account, index) => {
                clients[index] = await prepareStudent(account, index, stage);
                stage.preparedStudents = clients.filter(Boolean).length;
            });
        } catch (error) {
            if (error.code !== 'HOST_MEMORY_BOUNDARY') throw error;
            // Complete the genuine browser acceptance at the achieved capacity;
            // never report this as the originally requested larger stage.
            stage.memoryLimited = true; stage.studentCount = clients.filter(Boolean).length; stage.tabCount = stage.studentCount * 2;
            clients.splice(0, clients.length, ...clients.filter(Boolean));
            console.log(`Host memory boundary: requested ${size}, actually prepared ${stage.studentCount} students/${stage.tabCount} targets. Larger Chrome stages will not run.`);
            if (stage.studentCount <= 1) throw new Error('The memory-safe capacity is below two students. No activity was created; report the environment boundary before changing the PHP worker pool.');
        }
        stage.visibilitySamples.push(...await sampleVisibility(clients, 'before-window'));
        stage.phase = 'waiting';
        if (!teacherApiOnly) {
            await teacher.page.goto(base + '/oj-console/addons/focus-tracker');
            teacher.config = await teacher.page.locator('[data-ftk-dashboard]').evaluate(node => JSON.parse(node.getAttribute('data-config')));
            await until(async () => await teacher.page.locator('[data-field="context"] option').count() > 1, 'Native teacher group list did not load.');
        }
        const start = Math.ceil((Date.now() + 45000) / 1000) * 1000;
        stage.plannedStartMs = start; stage.plannedEndMs = start + 90000;
        const main = await createActivity(stage, `MySQL load ${size} A natural`, groups[0], start, start + 90000);
        const overlap = await createActivity(stage, `MySQL load ${size} A overlap`, groups[0], start, start + 120000);
        const secondary = await createActivity(stage, `MySQL load ${size} B`, groups[1], start, start + 120000);
        while (Date.now() < start) await sleep(Math.min(1000, start - Date.now()));
        stage.phase = 'active';
        const actions = [
            { offset: 15000, run: async () => { authorizationCheckpoint(stage, clients, 'both groups active before changes');
                await pool(clients.filter((_, index) => index % 2 === 0), 4, actor => actor.pages[1].bringToFront()); } },
            { offset: 25000, run: () => mutation(stage, overlap, { ends_at: new Date(start + 100000).toISOString() }, 'shorten overlapping A schedule') },
            { offset: 35000, run: async () => {
                if (config.problem?.id) await pool(clients, 4, async actor => {
                    const page = actor.pages[1]; if (!await page.locator('[data-oj-ace-surface]').count()) return;
                    await page.bringToFront(); await page.locator('[data-oj-ace-surface]').click({ position: { x: 90, y: 60 } });
                    await page.keyboard.press('ControlOrMeta+End'); await page.keyboard.insertText('\n# native load observation ' + actor.userId + '\n');
                });
                stage.visibilitySamples.push(...await sampleVisibility(clients, 'mid-window'));
            } },
            { offset: 45000, run: () => mutation(stage, overlap, { status: 'closed' }, 'early-close overlapping A while A natural remains') },
            { offset: 55000, run: () => mutation(stage, secondary, { ends_at: new Date(start + 80000).toISOString() }, 'shorten B to its natural end') },
            { offset: 65000, run: async () => authorizationCheckpoint(stage, clients, 'A persists after overlap close; B remains active before new end') },
            { offset: 85000, run: async () => authorizationCheckpoint(stage, clients, 'B stopped at edited end while A remains active') },
        ];
        for (const action of actions) { while (Date.now() < start + action.offset) await sleep(Math.min(500, start + action.offset - Date.now())); await action.run(); }
        while (Date.now() < start + 90000) await sleep(Math.min(500, start + 90000 - Date.now()));
        stage.phase = 'drain';
        await until(() => clients.every(actor => actor.latestGates.length === 2 && actor.latestGates.every(value => !value.active)), 'Some real browser targets remained active beyond the schedule and ten-second lease.', 15000);
        await until(async () => { const pending = await pool(clients, 6, actor => readPending(actor, stage)); stage.remainingOutboxEvents = pending.reduce((a, b) => a + b, 0); return stage.remainingOutboxEvents === 0; }, 'Eligible browser outboxes did not receive durable ACKs within the drain window.', 90000);
        stage.visibilitySamples.push(...await sampleVisibility(clients, 'after-window'));
        stage.phase = 'verification'; stage.statistics = await pool(clients, 4, actor => verifyStatistics(actor, stage));
        await until(() => stage.requests.filter(sample => ['waiting', 'active', 'drain'].includes(sample.phase)).every(sample => Number.isFinite(sample.durationMs)), 'Some measured native requests never completed.', 12000);
        stage.actualHiddenTargetSamples = stage.visibilitySamples.filter(value => value.visible === 'hidden').length;
        stage.actualHiddenTargetsObserved = new Set(stage.visibilitySamples.filter(value => value.visible === 'hidden').map(value => value.userId + '|' + value.tab)).size;
        stage.actualFocusedTargetSamples = stage.visibilitySamples.filter(value => value.focused).length;
        stage.authLatency = distribution(stage.authDurationsMs); summarize(stage, clients);
        console.log(`Stage actual ${stage.studentCount} students (requested ${size}): client criteria ${stage.passedClientCriteria ? 'PASS; native SQL audit still required' : 'FAIL'}.`);
        if (!stage.passedClientCriteria) throw new Error('Native browser load acceptance failed at ' + size + ' students; see measured criteria and separate SQL audit.');
    } finally {
        clearInterval(samplingTimer);
        if (!stage.passedClientCriteria && teacher.config) {
            stage.phase = 'cleanup'; stage.cleanupActions = [];
            const hasRecordedEvents = [...expected.values()].some(row => row.stageStudents === stage.studentCount)
                || stage.requests.some(row => row.endpoint.endsWith('/events')) || clients.some(actor => actor.seenActive.some(Boolean));
            try {
                stage.cleanup = await cleanupFailedActivities({ activities: stage.activities, hasRecordedEvents, teacherUserId: teacher.userId,
                    identity: async () => {
                        const request = teacher.request || teacher.context.request;
                        const response = await request.get(base + '/wp-json/wp/v2/users/me', { headers: { 'X-WP-Nonce': teacher.config.nonce }, timeout: 15000 });
                        assert.equal(response.status(), 200); return (await response.json()).id;
                    }, request: (suffix, method, body) => api(teacher, suffix, method, body) });
                stage.cleanupActions = stage.cleanup.actions;
            } catch (error) { stage.cleanupError = safeFailure(error); }
        }
        stage.phase = 'closing';
        stage.actualObservedStudentCount = clients.filter(Boolean).length;
        await pool(clients.filter(Boolean), 4, actor => actor.context.close().catch(() => {}));
        stage.finishedAt = new Date().toISOString(); save();
    }
}
(async () => {
    report.hostResourcesBeforeBrowserLaunch = { at: Date.now(), freeMiB: Math.round(os.freemem() / 1048576), totalMiB: Math.round(os.totalmem() / 1048576) };
    if (report.hostResourcesBeforeBrowserLaunch.freeMiB < Math.max(2048, report.hostResourcesBeforeBrowserLaunch.totalMiB * 0.1)) {
        report.preflightSafetyStop = true; throw new Error('The host is below the 2 GiB browser safety boundary before any login or browser launch.');
    }
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH,
        process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')].find(value => value && fs.existsSync(value));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}), ignoreDefaultArgs: report.removedAutomationFlags });
    report.browserVersion = browser.version();
    if (teacherApiOnly) {
        const request = await nativeCookieLogin(playwright(), base, config.teacher); nativeRequests.push(request);
        const response = await request.get(new URL('/oj-console/addons/focus-tracker', base).href); assert.equal(response.status(), 200);
        teacher = { userId: Number(config.teacher.userId), request, config: dashboardConfig(await response.text()) };
    } else {
        const teacherContext = await browser.newContext({ timezoneId: 'Asia/Seoul' }); contexts.push(teacherContext);
        const authStage = { authDurationsMs: [] }; teacher = { userId: Number(config.teacher.userId), context: teacherContext };
        teacher.page = await login(teacherContext, config.teacher, authStage);
    }
    for (const size of stageSizes) { await stageRun(size); if (report.stages.at(-1).memoryLimited) break; }
    report.passedClientCriteria = report.stages.every(value => value.passedClientCriteria);
    report.passedClientCriteriaWithinObservedScope = report.passedClientCriteria;
    report.requestedCapacityReached = report.stages.length === stageSizes.length && report.stages.every(value => !value.memoryLimited);
    report.achievedRequestedStage = report.requestedCapacityReached;
    report.omittedLargerStages = stageSizes.slice(report.stages.length);
})().catch(error => { report.failure = safeFailure(error); console.error(report.failure); process.exitCode = 1; })
    .finally(async () => { clearInterval(samplingTimer); report.finishedAt = new Date().toISOString(); save();
        for (const context of contexts) await context.close().catch(() => {});
        for (const request of nativeRequests) await request.dispose().catch(() => {}); if (browser) await browser.close();
        console.log('Native browser load report: ' + path.join(artifacts, 'browser-report.json'));
    });
