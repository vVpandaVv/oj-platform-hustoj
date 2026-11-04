'use strict';

// Native one-off HTTP diagnostics, never a production-timer acceptance run.
// Cookies/nonces/signed descriptors remain in memory. Every next wave and the
// final owner-only cleanup require an explicit control marker after server drain.
const assert = require('node:assert/strict');
const fs = require('node:fs'); const path = require('node:path'); const os = require('node:os');
const { randomUUID } = require('node:crypto'); const { performance } = require('node:perf_hooks');
const { nativeCookieLogin, bootstrap, dashboardConfig } = require('./native-cookie-client.cjs');
function playwright() {
    for (const candidate of [process.env.PLAYWRIGHT_MODULE_PATH, 'playwright', process.env.USERPROFILE && path.join(process.env.USERPROFILE,
        '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')].filter(Boolean)) {
        try { return require(candidate); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; }
    }
    throw new Error('Playwright is required.');
}
const fixturePath = process.env.FOCUS_MYSQL_LOAD_CONFIG;
assert.ok(fixturePath, 'Set the private native fixture path.');
const fixture = JSON.parse(fs.readFileSync(path.resolve(fixturePath), 'utf8'));
assert.equal(new URL(fixture.baseUrl).origin, 'http://127.0.0.1:33918', 'Use only the isolated native site.');
assert.ok(fixture.students.length >= 60 && fixture.material?.url && fixture.problem?.id);
assert.equal(new Set(fixture.students.slice(0, 60).map(row => Number(row.userId))).size, 60);
const groups = Array.isArray(fixture.groups) ? fixture.groups : Object.values(fixture.groups || {});
assert.ok(groups[0]?.id && groups[1]?.id);
const directory = path.resolve(process.env.FOCUS_MYSQL_DIAGNOSTIC_ARTIFACTS || path.join(path.dirname(path.resolve(fixturePath)), 'gate-diagnostic-' + Date.now()));
assert.ok(!fs.existsSync(directory), 'Diagnostic output directories are unique; never reuse a previous control marker.');
fs.mkdirSync(directory, { recursive: true });
const runId = randomUUID(); const actors = []; let teacher; let stopRequested = false;
const secrets = [fixture.teacher, ...fixture.students].flatMap(account => [account.username, account.password]).filter(value => typeof value === 'string' && value.length > 3);
function failure(error) {
    let message = String(error.message || error).split(/\r?\n/)[0];
    for (const secret of secrets) message = message.split(secret).join('[redacted]');
    return message.replace(/([?&]_wpnonce=)[^&\s]+/g, '$1[redacted]');
}
const report = { runId, startedAt: new Date().toISOString(), mode: 'native-one-off-gate-diagnostic', acceptanceRun: false,
    diagnosticRequestTimeoutMs: 15000, productionFetchDeadlineMs: 5000, productTimersUnchanged: true,
    phpWorkers: Number(process.env.FOCUS_MYSQL_LOAD_PHP_WORKERS) || 12, mysqlBufferPoolMiB: Number(process.env.FOCUS_MYSQL_LOAD_MYSQL_BUFFER_MIB) || 128,
    scope: 'Native WordPress form login/cookies/nonces/signed-page gate requests. One-off waves with a 15-second observation deadline; no browser tabs, polling, events, draft or production-five-second acceptance claim.',
    preparedStudents: 0, verifiedSignedPages: 0, activities: [], waves: [], cleanupRequired: false };
function save() { report.updatedAt = new Date().toISOString(); fs.writeFileSync(path.join(directory, 'diagnostic-report.json'), JSON.stringify(report, null, 2) + '\n', 'utf8'); }
function marker(name, value) { fs.writeFileSync(path.join(directory, name), JSON.stringify({ runId, at: Date.now(), ...value }, null, 2) + '\n', 'utf8'); }
function memorySafety(phase) {
    const sample = { phase, at: Date.now(), freeMiB: Math.round(os.freemem() / 1048576) };
    (report.resources ||= []).push(sample); assert.ok(sample.freeMiB >= 1024, 'The diagnostic is below the one-GiB HTTP memory safety boundary.');
}
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
async function pool(values, count, fn) {
    let next = 0; let firstError;
    await Promise.all(Array.from({ length: Math.min(count, values.length) }, async () => {
        while (next < values.length && !firstError && !stopRequested) {
            const index = next++;
            try { await fn(values[index], index); } catch (error) { firstError ||= error; }
        }
    }));
    if (firstError) throw firstError;
    assert.ok(!stopRequested, 'The diagnostic was cancelled.');
}
function endpoint(config, suffix) {
    const url = new URL(config.restUrl);
    if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/events$/, '').replace(/\/$/, '') + suffix);
    else url.pathname = url.pathname.replace(/\/events$/, '').replace(/\/$/, '') + suffix;
    return url.href;
}
async function teacherApi(suffix, method = 'GET', body) {
    const response = await teacher.request.fetch(endpoint(teacher.config, suffix), { method, timeout: 15000,
        headers: { 'X-WP-Nonce': teacher.config.nonce }, ...(body === undefined ? {} : { data: body }) });
    const data = await response.json(); assert.equal(response.status(), 200, 'The real teacher control request must succeed.'); return data;
}
async function identity(request, nonce, expected) {
    const response = await request.get(new URL('/wp-json/wp/v2/users/me', fixture.baseUrl).href, { headers: { 'X-WP-Nonce': nonce }, timeout: 15000 });
    assert.equal(response.status(), 200); assert.equal(Number((await response.json()).id), Number(expected));
}
async function waitControl(name, wave) {
    console.log('Diagnostic waiting for ' + name + '; upstream drain and observer coordination required.');
    const expires = Date.now() + 30 * 60000;
    while (Date.now() < expires) {
        assert.ok(!stopRequested, 'The diagnostic was cancelled.');
        const file = path.join(directory, name);
        if (fs.existsSync(file)) {
            const control = JSON.parse(fs.readFileSync(file, 'utf8'));
            assert.equal(control.runId, runId, 'A control marker must belong to this unique diagnostic run.');
            assert.equal(control.allow, true);
            if (wave !== undefined) assert.equal(Number(control.wave), wave);
            return;
        }
        await sleep(250);
    }
    throw new Error('The diagnostic control marker timed out.');
}
async function prepare() {
    memorySafety('before-native-login');
    const request = await nativeCookieLogin(playwright(), fixture.baseUrl, fixture.teacher);
    teacher = { request };
    const response = await request.get(new URL('/oj-console/addons/focus-tracker', fixture.baseUrl).href); assert.equal(response.status(), 200);
    teacher.config = dashboardConfig(await response.text()); await identity(request, teacher.config.nonce, fixture.teacher.userId);
    await pool(fixture.students.slice(0, 60), 4, async account => {
        memorySafety('before-native-student-login');
        const request = await nativeCookieLogin(playwright(), fixture.baseUrl, account);
        const actor = { request, userId: Number(account.userId), channels: [] }; actors.push(actor);
        for (const index of [0, 1]) {
            const url = new URL(index === 0 ? fixture.material.url : (fixture.problem.problemUrl || fixture.problem.url), fixture.baseUrl);
            assert.equal(url.origin, new URL(fixture.baseUrl).origin); url.searchParams.set('group_id', groups[index].id);
            const page = await request.get(url.href); assert.equal(page.status(), 200, 'Every native signed resource must render.');
            const config = bootstrap(await page.text()); assert.equal(Number(config.userId), actor.userId);
            assert.equal(Number(config.activityGate.pollMs), 5000); assert.ok(config.nonce && config.page?.token);
            actor.channels[index] = { config, group: groups[index], index };
            report.verifiedSignedPages += 1;
        }
        report.preparedStudents += 1; save();
    });
    actors.sort((a, b) => a.userId - b.userId);
    const start = Math.ceil((Date.now() + 3600000) / 1000) * 1000;
    for (const [index, group] of groups.slice(0, 2).entries()) {
        const activity = await teacherApi('/activities', 'POST', { name: 'Gate diagnostic ' + runId.slice(0, 8) + ' ' + (index === 0 ? 'A' : 'B'),
            context_type: 'group', context_id: group.contextId || 'group:' + group.id, problem_ids: [], starts_at: new Date(start).toISOString(), ends_at: new Date(start + 900000).toISOString() });
        report.activities.push(activity); report.cleanupRequired = true; save();
    }
    const own = await teacherApi('/activities');
    for (const activity of report.activities) {
        const match = own.find(row => Number(row.id) === Number(activity.id)); assert.ok(match, 'The new schedule must be in the authenticated owner-only listing.');
        for (const key of ['name', 'contextType', 'contextId', 'startsAt', 'endsAt']) assert.equal(match[key], activity[key]);
    }
    report.ownerIdentityAndListingVerified = true; report.preparedAt = new Date().toISOString(); save();
    marker('prepared.json', { students: 60, signedPages: 120, activityIds: report.activities.map(row => row.id),
        controls: ['allow-wave-1.json', 'allow-wave-2.json', 'allow-wave-3.json', 'allow-wave-4.json', 'allow-cleanup.json'] });
    console.log('Diagnostic prepared: actual cookie students 60, signed pages 120, future owner schedules 2; no gate wave started. Run ' + runId);
}
function percentile(values, ratio) { const sorted = values.filter(Number.isFinite).sort((a, b) => a - b); return sorted.length ? sorted[Math.min(sorted.length - 1, Math.ceil(sorted.length * ratio) - 1)] : null; }
async function wave(number, size) {
    await waitControl('allow-wave-' + number + '.json', number);
    memorySafety('before-wave-' + size);
    assert.ok(report.activities.every(row => Date.now() + 15000 < Date.parse(row.startsAt)), 'A diagnostic wave must finish before the future activity starts.');
    const selected = size === 120 ? actors.flatMap(actor => actor.channels.map(channel => ({ actor, channel })))
        : actors.slice(0, size).map(actor => ({ actor, channel: actor.channels[0] }));
    assert.equal(selected.length, size);
    const result = { number, channelCount: size, distinctStudents: new Set(selected.map(row => row.actor.userId)).size, startedAt: new Date().toISOString(), requests: [] };
    report.waves.push(result); marker('wave-' + number + '-started.json', { number, channels: size });
    await Promise.all(selected.map(async ({ actor, channel }) => {
        const config = channel.config; const url = new URL(config.activityGate.url); assert.equal(url.origin, new URL(fixture.baseUrl).origin);
        const item = { userId: actor.userId, channel: channel.index, pageCategory: config.page.category, startedAtMs: Date.now() }; result.requests.push(item);
        const started = performance.now();
        try {
            const response = await actor.request.post(url.href, { timeout: 15000, maxRetries: 0,
                headers: { 'X-WP-Nonce': config.nonce, 'X-Focus-Diagnostic': 'wave-' + size },
                data: { user_id: actor.userId, problem_id: config.problemId || null, context_type: 'group',
                    context_id: channel.group.contextId || 'group:' + channel.group.id, page_context: config.page.token } });
            item.httpStatus = response.status();
            let data; try { data = await response.json(); } catch (_) { data = {}; }
            item.code = typeof data.code === 'string' ? data.code : undefined; item.active = typeof data.active === 'boolean' ? data.active : null;
            item.reason = typeof data.reason === 'string' ? data.reason : undefined;
            item.serverLeaseMs = Number.isFinite(data.validUntilMs) && Number.isFinite(data.serverNowMs) ? data.validUntilMs - data.serverNowMs : null;
        } catch (error) { item.error = failure(error); item.timeout = /timeout/i.test(String(error.message)); }
        finally { item.durationMs = performance.now() - started; }
    }));
    const durations = result.requests.map(row => row.durationMs);
    result.summary = { requests: size, p50Ms: percentile(durations, 0.5), p95Ms: percentile(durations, 0.95), p99Ms: percentile(durations, 0.99), maxMs: Math.max(...durations),
        http200: result.requests.filter(row => row.httpStatus === 200).length, http429: result.requests.filter(row => row.httpStatus === 429).length,
        http5xx: result.requests.filter(row => row.httpStatus >= 500).length, timeouts: result.requests.filter(row => row.timeout).length,
        overProductionDeadline: result.requests.filter(row => row.durationMs >= 5000).length, unexpectedActive: result.requests.filter(row => row.active === true).length };
    result.finishedAt = new Date().toISOString(); save(); marker('wave-' + number + '-complete.json', { number, summary: result.summary });
    console.log('Diagnostic wave ' + number + ': ' + JSON.stringify(result.summary));
}
async function cleanup() {
    await waitControl('allow-cleanup.json');
    await identity(teacher.request, teacher.config.nonce, fixture.teacher.userId);
    const before = await teacherApi('/activities'); const ids = new Set(report.activities.map(row => Number(row.id)));
    for (const activity of report.activities) {
        const match = before.find(row => Number(row.id) === Number(activity.id)); assert.ok(match);
        for (const key of ['name', 'contextType', 'contextId', 'startsAt', 'endsAt']) assert.equal(match[key], activity[key]);
    }
    report.cleanup = [];
    for (const activity of report.activities) {
        const value = await teacherApi('/activities/' + activity.id, 'DELETE');
        assert.equal(Number(value.id), Number(activity.id)); assert.equal(value.deleted, true);
        report.cleanup.push({ id: activity.id, deleted: true, httpStatus: 200 });
    }
    const after = await teacherApi('/activities');
    assert.deepEqual(after.map(row => Number(row.id)).sort((a, b) => a - b), before.filter(row => !ids.has(Number(row.id))).map(row => Number(row.id)).sort((a, b) => a - b));
    report.cleanupRequired = false; report.otherActivityIdsPreserved = true; save();
}
process.on('SIGINT', () => { stopRequested = true; });
(async () => {
    await prepare();
    for (const [index, size] of [1, 12, 60, 120].entries()) await wave(index + 1, size);
    await cleanup(); report.completedDiagnostic = true;
})().catch(error => { report.failure = failure(error); process.exitCode = 1; })
    .finally(async () => {
        for (const actor of actors) await actor.request.dispose().catch(() => {});
        if (teacher) await teacher.request.dispose().catch(() => {});
        report.finishedAt = new Date().toISOString(); save();
        console.log('Diagnostic report: ' + path.join(directory, 'diagnostic-report.json'));
    });
