'use strict';

// Small native wire-contract check before a timed load run. Cookie/nonces,
// signed descriptors, gate, inserts and dedup are real. State inputs are
// synthetic protocol values, not rendered browser visibility evidence.
const assert = require('node:assert/strict');
const fs = require('node:fs'); const path = require('node:path'); const os = require('node:os');
const { randomUUID } = require('node:crypto'); const { performance } = require('node:perf_hooks');
const { nativeCookieLogin, bootstrap, dashboardConfig } = require('./native-cookie-client.cjs');
const playwright = require(process.env.PLAYWRIGHT_MODULE_PATH || path.join(process.env.USERPROFILE,
    '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));
const config = JSON.parse(fs.readFileSync(process.env.FOCUS_MYSQL_LOAD_CONFIG, 'utf8'));
const base = config.baseUrl; assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname));
const output = path.resolve(process.env.FOCUS_MYSQL_WIRE_ARTIFACTS || path.join(path.dirname(process.env.FOCUS_MYSQL_LOAD_CONFIG), 'wire-smoke-' + Date.now()));
fs.mkdirSync(output, { recursive: true });
const report = { mode: 'native-http-wire-smoke', startedAt: new Date().toISOString(), passed: false, syntheticProtocolState: true,
    scope: 'Actual WordPress form cookies, signed material page, native REST insert/dedup and real elapsed time. No browser or automatic timer simulation.',
    expectedEvents: [], stages: [{ acknowledgedEventKeys: [] }], requests: [], checks: [] };
const sessions = []; let teacher; let activity; let closed = false;
const group = config.groups[0];
function check(value, name) { assert.ok(value, name); report.checks.push(name); }
function url(cfg, suffix) { const value = new URL(cfg.restUrl);
    if (value.searchParams.has('rest_route')) value.searchParams.set('rest_route', value.searchParams.get('rest_route').replace(/\/events$/, '').replace(/\/$/, '') + suffix);
    else value.pathname = value.pathname.replace(/\/events$/, '').replace(/\/$/, '') + suffix;
    if (suffix === '/events') value.searchParams.set('_wpnonce', cfg.nonce); return value.href;
}
async function call(actor, suffix, method, body) {
    const at = performance.now(); const response = await actor.request.fetch(url(actor.cfg, suffix), { method, timeout: suffix === '/tracking/state' ? 5000 : 15000,
        maxRetries: 0, headers: { 'X-WP-Nonce': actor.cfg.nonce, 'Content-Type': 'application/json' }, data: body });
    let data; try { data = await response.json(); } catch (_) { data = {}; }
    report.requests.push({ endpoint: suffix.replace(/\/activities\/\d+/, '/activities/:id'), method, status: response.status(),
        durationMs: performance.now() - at, code: typeof data.code === 'string' ? data.code : undefined,
        stored: Number.isFinite(data.stored) ? data.stored : undefined, acknowledged: data.acknowledged === true });
    check(response.status() >= 200 && response.status() < 300, 'Native ' + suffix.replace(/\d+/g, ':id') + ' returns 2xx.'); return data;
}
async function login(account) {
    const request = await nativeCookieLogin(playwright, base, account); sessions.push(request); return { userId: Number(account.userId), request };
}
function record(actor, uid, startedAt, events) {
    for (const event of events) report.expectedEvents.push({ userId: actor.userId, sessionUid: uid, sessionStartedAt: startedAt,
        seq: event.seq, occurredAt: event.occurred_at, type: event.type, problemId: null, pageKey: actor.cfg.page.key,
        pageCategory: actor.cfg.page.category, pageSubtype: actor.cfg.page.subtype || null, contextType: 'group',
        contextId: group.contextId || 'group:' + group.id, syntheticProtocolState: true, visible: true, focused: true });
}
async function send(actor, payload, expectedStored, label) {
    const data = await call(actor, '/events', 'POST', payload);
    check(data.acknowledged === true && data.stored === expectedStored && Number(data.policyDropped) === 0, label);
    for (const event of payload.events) {
        const key = actor.userId + '|' + payload.session_uid + '|' + event.seq;
        if (!report.stages[0].acknowledgedEventKeys.includes(key)) report.stages[0].acknowledgedEventKeys.push(key);
    }
}
(async () => {
    report.freeMiBBefore = Math.round(os.freemem() / 1048576); check(report.freeMiBBefore >= 1024, 'The host has at least 1 GiB free before wire traffic.');
    teacher = await login(config.teacher);
    const dashboard = await teacher.request.get(new URL('/oj-console/addons/focus-tracker', base).href); check(dashboard.status() === 200, 'The actual teacher dashboard permits access.');
    teacher.cfg = dashboardConfig(await dashboard.text());
    const actors = [];
    for (const account of config.students.slice(0, 2)) {
        const actor = await login(account); const value = new URL(config.material.url); value.searchParams.set('group_id', group.id);
        const response = await actor.request.get(value.href); check(response.status() === 200, 'The actual learner material page renders.');
        actor.cfg = bootstrap(await response.text()); check(Number(actor.cfg.userId) === actor.userId && actor.cfg.page.token && actor.cfg.nonce !== 'focus-test-nonce', 'Native bootstrap matches the real learner cookie.'); actors.push(actor);
    }
    const now = Date.now(); activity = await call(teacher, '/activities', 'POST', { name: 'Native wire insert/dedup smoke', context_type: 'group',
        context_id: group.contextId || 'group:' + group.id, problem_ids: [], starts_at: new Date(now - 1000).toISOString(), ends_at: new Date(now + 30000).toISOString() });
    report.activity = { id: activity.id, startsAt: activity.startsAt, endsAt: activity.endsAt };
    for (const actor of actors) {
        const gate = await call(actor, '/tracking/state', 'POST', { user_id: actor.userId, problem_id: null,
            context_type: 'group', context_id: group.contextId || 'group:' + group.id, page_context: actor.cfg.page.token });
        check(gate.active === true && gate.activityIds.includes(activity.id), 'The native gate authorizes this exact activity.');
        const uid = randomUUID(); const startedAt = gate.serverNowMs; const origin = performance.now();
        const events = [{ type: 'session_start', occurred_at: startedAt, seq: 1, meta: { visible: true, focused: true } }];
        const payload = { user_id: actor.userId, session_uid: uid, session_started_at: startedAt, problem_id: null, context_type: 'group',
            context_id: group.contextId || 'group:' + group.id, page_context: actor.cfg.page.token, is_final: false, events };
        record(actor, uid, startedAt, events); await send(actor, payload, 2, 'A new native packet receives ACK with one real event and the reserved server start control.');
        await send(actor, payload, 0, 'Exact packet retransmission receives ACK with zero new events.');
        await new Promise(resolve => setTimeout(resolve, 1250));
        const at = Math.round(startedAt + performance.now() - origin);
        const finalEvents = [{ type: 'heartbeat', occurred_at: at, seq: 2, meta: { visible: true, focused: true } },
            { type: 'session_end', occurred_at: at, seq: 3, meta: { visible: true, focused: true } }];
        record(actor, uid, startedAt, finalEvents); await send(actor, { ...payload, events: finalEvents, is_final: true }, 2, 'Real elapsed heartbeat and session end receive ACK with two stored events.');
        await send(actor, { ...payload, events: finalEvents, is_final: true }, 0, 'Final packet retransmission remains idempotent.');
    }
    // Close in a later real second so retrospective close clipping does not
    // exclude an already accepted millisecond from the independent SQL audit.
    await new Promise(resolve => setTimeout(resolve, 1250));
    await call(teacher, '/activities/' + activity.id, 'PATCH', { status: 'closed' }); closed = true;
    for (const actor of actors) {
        const gate = await call(actor, '/tracking/state', 'POST', { user_id: actor.userId, problem_id: null,
            context_type: 'group', context_id: group.contextId || 'group:' + group.id, page_context: actor.cfg.page.token });
        check(gate.active === false, 'The actual teacher close revokes a fresh learner gate.');
    }
    report.passed = true;
})().catch(error => { report.failure = String(error.message || error).split(/\r?\n/)[0].replace(/([?&]_wpnonce=)[^&\s]+/g, '$1[redacted]'); process.exitCode = 1; })
    .finally(async () => {
        if (teacher?.cfg && activity?.id && !closed) { try { await call(teacher, '/activities/' + activity.id, 'PATCH', { status: 'closed' }); report.failureCleanupClosed = true; } catch (_) { report.failureCleanupClosed = false; } }
        for (const request of sessions) await request.dispose(); report.finishedAt = new Date().toISOString();
        fs.writeFileSync(path.join(output, 'browser-report.json'), JSON.stringify(report, null, 2) + '\n', 'utf8');
        console.log(JSON.stringify({ mode: report.mode, passed: report.passed, checks: report.checks.length, expectedEvents: report.expectedEvents.length,
            report: path.join(output, 'browser-report.json'), failure: report.failure }));
    });
