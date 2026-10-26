'use strict';

// Real Chromium, platform Ace editor, activity authorization, ingestion and
// check APIs. Failure tests interrupt the network; successful REST responses
// are never replaced. History navigation exercises fresh authorization on
// either a normal load or a browser BFCache restoration.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');

function playwright() {
    const candidates = [process.env.PLAYWRIGHT_MODULE_PATH, 'playwright'];
    if (process.env.USERPROFILE) candidates.push(path.join(process.env.USERPROFILE, '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));
    for (const candidate of candidates.filter(Boolean)) {
        try { return require(candidate); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; }
    }
    throw new Error('Install Playwright or set PLAYWRIGHT_MODULE_PATH.');
}
const root = path.resolve(__dirname, '../..');
const temporary = path.join(root, '_notes/tmp/focus-tracker-tests');
fs.mkdirSync(temporary, { recursive: true });
const db = path.join(temporary, `activity-gate-browser-${process.pid}-${Date.now()}.sqlite`);
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:/php/php.exe' : 'php');
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
let server; let browser; let base; let serverOutput = ''; let checks = 0;
const errors = [];
function check(value, message) { assert.ok(value, message); checks += 1; }
async function until(predicate, message, timeout = 15000) {
    const end = Date.now() + timeout;
    do { const result = await predicate(); if (result) return result; await delay(50); } while (Date.now() < end);
    throw new Error(message);
}
async function api(url, payload, method = 'POST') {
    const response = await fetch(base + url, payload === undefined ? {} : { method,
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': 'focus-test-nonce' }, body: JSON.stringify(payload) });
    const text = await response.text(); assert.ok(response.ok, `${url}: ${response.status} ${text.slice(0, 1200)}`);
    return JSON.parse(text);
}
async function configure() {
    await api('/__test/reset', {});
    await api('/__test/check-config', { reset: true, scenario: 'problem', initialDelayMs: 50, pollMs: 1000,
        gatePollMs: 500, cooldownSeconds: 60, dailyLimit: 20, userId: 1 });
    await api('/__test/statistics-config', { userId: 1, denyContext: false });
}
async function activity(extra = {}) {
    return api('/__test/activity', { name: '시간에 맞춘 수업', context_type: 'class', context_id: 'class:1', problem_ids: [123],
        startOffsetMs: -1000, endOffsetMs: 15000, ...extra });
}
async function open(url = '/problems/123', context = null) {
    const ownContext = context || await browser.newContext();
    const page = await ownContext.newPage();
    const requests = []; const stateResponses = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', item => {
        if (item.postData() && /\/(events|tracking\/state|checks\/next|checks\/\d+\/response)(?:\?|$)/.test(item.url())) {
            requests.push({ url: item.url(), body: JSON.parse(item.postData()) });
        }
    });
    page.on('response', async response => {
        if (!response.url().includes('/tracking/state')) return;
        const entry = { status: response.status(), body: {} }; stateResponses.push(entry);
        try { entry.body = await response.json(); } catch (_) { /* Preserve failed status. */ }
    });
    await page.goto(base + url);
    await page.locator('[data-oj-ace-editor][data-mounted="true"]').waitFor();
    await page.locator('select[name="language_id"]').selectOption('6');
    await edit(page, '# PRIVATE_GATED_DRAFT\nvalue = 8\nif value <= 10:\n    print(value)\n');
    return { page, context: ownContext, requests, stateResponses };
}
async function edit(page, value) {
    await page.evaluate(value => { const editor = window.ace.edit(document.querySelector('[data-oj-ace-surface]')); editor.setValue(value, -1); editor.focus(); }, value);
}
function requestCount(app, expression) { return app.requests.filter(item => expression.test(item.url)).length; }
async function state() { return api('/__test/state'); }
async function gate(app, active) {
    await until(() => app.page.evaluate(active => window.FocusTrackerActivityGate && window.FocusTrackerActivityGate.state().active === active, active),
        `The real activity gate must become ${active}.`);
}
function edits(data) { return data.events.filter(event => event.event_type === 'activity'); }
function eventTime(event) { return Number(event.occurred_at_ms); }

async function noSchedule() {
    console.log('Checking no-schedule privacy and collection stop');
    await configure(); const app = await open();
    await until(() => app.stateResponses.some(item => item.status === 200 && item.body.active === false), 'No schedule must be confirmed by the production gate.');
    await edit(app.page, '# PRIVATE_GATED_DRAFT\nvalue = 9\nif value <= 10:\n    print(value)');
    await delay(1700);
    const data = await state();
    check(data.events.length === 0 && data.participations.length === 0 && data.checks.length === 0,
        'Without an active authorized schedule there are no tracking sessions, events or questions in storage.');
    check(requestCount(app, /\/events(?:\?|$)/) === 0 && requestCount(app, /\/checks\//) === 0,
        'No event or draft/question endpoint is called before a schedule exists.');
    check(app.requests.filter(item => item.url.includes('/tracking/state')).every(item => !JSON.stringify(item.body).includes('source')),
        'Schedule probes contain identity and page context, never source code.');
    check(await app.page.locator('[data-ftk-check], [data-ftk-check-launcher]').count() === 0, 'No inactive question or launcher is displayed.');
    check(await app.page.locator('[data-ftk-gate-label]').textContent() === '활동 없음', 'No authorized activity has an explicit student state.');
    const dismiss = app.page.getByRole('button', { name: '안내 닫기', exact: true });
    if (await dismiss.count()) { await dismiss.click(); }
    check(await app.page.locator('[data-ftk-activity-status]').count() === 1, 'Dismissing the privacy notice never removes live activity status.');
    await app.context.close();
}

async function futureEndAndResume() {
    console.log('Checking scheduled start/end and a separate session after an off-time gap');
    await configure(); const app = await open();
    const first = await activity({ name: '첫 시간', startOffsetMs: 2000, endOffsetMs: 7500 });
    const start = Date.parse(first.startsAt); const end = Date.parse(first.endsAt);
    await until(async () => await app.page.locator('[data-ftk-gate-label]').textContent() === '시작 대기', 'A future schedule shows a waiting state.');
    check((await app.page.locator('[data-ftk-gate-time]').textContent()).includes('다음 시작'), 'Waiting students see the scheduled start time.');
    await gate(app, true);
    check(await app.page.locator('[data-ftk-gate-label]').textContent() === '기록 중'
        && (await app.page.locator('[data-ftk-gate-time]').textContent()).includes('예정 종료'), 'Authorized recording and its planned end are visible.');
    await edit(app.page, '# PRIVATE_GATED_DRAFT\nvalue = 7\nif value <= 10:\n    print(value)');
    await app.page.locator('[data-ftk-check]').waitFor();
    check(requestCount(app, /\/checks\/next/) > 0, 'A page already open before the schedule starts can receive a verified question without reloading.');
    await app.page.evaluate(() => { window.__oldCheckAnswer = document.querySelector('[data-ftk-answer]'); });
    await app.page.locator('[data-ftk-answer]').first().focus();
    await gate(app, false);
    await until(async () => (await state()).events.some(event => event.event_type === 'session_end'), 'The known deadline must flush its final eligible session boundary.');
    const finished = await state();
    check(finished.events.every(event => eventTime(event) >= start && (event.event_type === 'session_end' ? eventTime(event) <= end : eventTime(event) < end)),
        'Every stored learning event lies inside the scheduled interval and the final control boundary is clamped to its end.');
    check(await app.page.locator('[data-ftk-check], [data-ftk-check-launcher]').count() === 0, 'The scheduled deadline removes the question and launcher immediately.');
    check(await app.page.locator('[data-ftk-gate-label]').textContent() === '활동 종료', 'A scheduled deadline is shown as ended, not a connection failure.');
    check(await app.page.locator('.ace_text-input').evaluate(item => item === document.activeElement), 'A focused question returns to the editor when its activity ends.');
    const beforeOff = edits(finished).length;
    const responseCount = requestCount(app, /\/checks\/\d+\/response/);
    await edit(app.page, '# OFF_TIME_SOURCE\nvalue = 1\nif value <= 10:\n    print(value)');
    await app.page.evaluate(() => window.__oldCheckAnswer && window.__oldCheckAnswer.click());
    await delay(1200);
    check(edits(await state()).length === beforeOff, 'Off-time editing does not add observations after the deadline.');
    check(requestCount(app, /\/checks\/\d+\/response/) === responseCount, 'An old detached answer callback cannot submit a response after the schedule ends.');
    const second = await activity({ name: '다음 시간', startOffsetMs: 1500, endOffsetMs: 6000 });
    await gate(app, true);
    await edit(app.page, 'value = 5\nif value <= 10:\n    print(value)');
    await gate(app, false);
    const resumed = await until(async () => {
        const data = await state(); return data.participations.length >= 2 && data.events.filter(event => event.event_type === 'session_end').length >= 2 ? data : null;
    }, 'A separate authorized interval must persist a new logical session.');
    check(new Set(resumed.participations.map(item => item.session_uid)).size >= 2, 'A later schedule resumes using a separate logical session.');
    const secondStart = Date.parse(second.startsAt);
    check(resumed.events.every(event => eventTime(event) <= end || eventTime(event) >= secondStart), 'The off-time gap contains no stored learning events.');
    const report = await api('/wp-json/focus-tracker/v1/me/statistics?period=week');
    check(report.totals.activeSeconds !== null && report.totals.activeSeconds <= (end - start + Date.parse(second.endsAt) - secondStart) / 1000 + 0.01,
        'Cumulative statistics cannot add active time outside the two authorized intervals.');
    await app.context.close();
}

async function wrongScope() {
    console.log('Checking other-group and other-problem exclusion');
    await configure(); await activity({ context_type: 'group', context_id: 'group:1', endOffsetMs: 20000 });
    const otherGroup = await open('/problems/123?group_id=2');
    await until(() => otherGroup.stateResponses.some(item => item.status === 200), 'The selected other group must be evaluated.');
    await delay(1000);
    check(otherGroup.requests.find(item => item.url.includes('/tracking/state')).body.context_type === 'group'
        && otherGroup.requests.find(item => item.url.includes('/tracking/state')).body.context_id === '2', 'The actual platform group form sends an explicit group scope.');
    check(requestCount(otherGroup, /\/(events|checks\/)/) === 0, 'A schedule in another group cannot authorize collection or questions.');
    await otherGroup.context.close();
    const otherProblem = await open('/problems/124?group_id=1');
    await until(() => otherProblem.stateResponses.some(item => item.status === 200), 'The other problem must be evaluated.');
    await delay(1000);
    check(requestCount(otherProblem, /\/(events|checks\/)/) === 0, 'A schedule limited to a different problem cannot authorize this page.');
    await otherProblem.context.close();
}

async function earlyEndAndFailure() {
    console.log('Checking early end and schedule-network failure');
    await configure(); const lesson = await activity({ context_type: 'group', context_id: 'group:1', endOffsetMs: 60000 });
    const app = await open('/problems/123?group_id=1'); await gate(app, true);
    await app.page.locator('[data-ftk-check]').waitFor();
    const closedAt = Date.now(); await api('/wp-json/focus-tracker/v1/activities/' + lesson.id, { status: 'closed' }, 'PATCH');
    await gate(app, false);
    check(Date.now() - closedAt < 2500, 'Early termination is discovered within the short configured lease poll.');
    check(await app.page.locator('[data-ftk-check], [data-ftk-check-launcher]').count() === 0, 'Early end removes the displayed check immediately on revocation.');
    await delay(400); const before = edits(await state()).length;
    await edit(app.page, 'value = 4\nif value <= 10:\n    print(value)'); await delay(800);
    check(edits(await state()).length === before, 'No new editing is captured after early end has revoked the gate.');
    await app.context.close();

    await configure(); await activity({ endOffsetMs: 60000 }); const offline = await open(); await gate(offline, true);
    await offline.page.route('**/tracking/state', route => route.abort('failed'));
    const failedAt = Date.now(); await gate(offline, false);
    check(Date.now() - failedAt <= 10500, 'An unavailable schedule service stops authorization within the maximum ten-second lease.');
    check(await offline.page.locator('[data-ftk-gate-label]').textContent() === '연결 오류로 중단', 'Network authorization failure is distinct from a scheduled end.');
    await delay(400); const old = edits(await state()).length;
    await edit(offline.page, 'value = 3\nif value <= 10:\n    print(value)'); await delay(800);
    check(edits(await state()).length === old, 'Network failure cannot leave source tracking active.');
    await offline.context.close();
}

async function hiddenAndHistory() {
    console.log('Checking authorized hidden observations and return after the deadline');
    await configure(); const lesson = await activity({ endOffsetMs: 7000 }); const app = await open(); await gate(app, true);
    // Dispatch the real DOM lifecycle event with a controlled visibility value;
    // the authorization, event storage and scores still use production APIs.
    await app.page.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' }); document.dispatchEvent(new Event('visibilitychange')); });
    await until(async () => (await state()).events.some(event => event.event_type === 'hidden'), 'A hidden transition must be persisted inside an authorized interval.');
    const beforeHidden = edits(await state()).length;
    await edit(app.page, 'value = 2\nif value <= 10:\n    print(value)'); await delay(1300);
    check(edits(await state()).length === beforeHidden, 'Hidden-page input does not become an active learning signal.');
    const metrics = await api('/wp-json/focus-tracker/v1/me/statistics?period=week');
    check(metrics.totals.focusRatio !== null && metrics.totals.focusRatio < 0.9, 'Authorized tab-away time remains in observations rather than inflating cumulative focus to 100%.');
    await gate(app, false);
    check((await state()).events.every(event => eventTime(event) <= Date.parse(lesson.endsAt)), 'A hidden page still stops at its scheduled deadline.');
    const sessions = (await state()).participations.length;
    await app.page.goto(base + '/__test/self');
    await app.page.goBack();
    await gate(app, false); await delay(1000);
    check((await state()).participations.length === sessions, 'History/BFCache return after the deadline cannot restore an expired collection session.');
    check(await app.page.locator('[data-ftk-check], [data-ftk-check-launcher]').count() === 0, 'Return after the deadline cannot restore an old check.');
    await app.context.close();
}

(async () => {
    const port = await new Promise(resolve => { const socket = net.createServer(); socket.listen(0, '127.0.0.1', () => { const value = socket.address().port; socket.close(() => resolve(value)); }); });
    base = `http://127.0.0.1:${port}`;
    server = spawn(php, ['-d', 'extension=pdo_sqlite', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'http-fixture.php')], {
        cwd: root, env: { ...process.env, FOCUS_TRACKER_TEST_DB: db }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
    });
    server.stdout.on('data', value => { serverOutput += value; }); server.stderr.on('data', value => { serverOutput += value; });
    await until(async () => { try { return (await fetch(base + '/__test/state')).ok; } catch (_) { return false; } }, 'PHP activity-gate fixture did not start.');
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH,
        process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')].find(value => value && fs.existsSync(value));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    const phases = { none: noSchedule, scheduled: futureEndAndResume, scope: wrongScope, failure: earlyEndAndFailure, hidden: hiddenAndHistory };
    for (const [name, phase] of Object.entries(phases)) if (!process.env.FOCUS_GATE_BROWSER_PHASE || process.env.FOCUS_GATE_BROWSER_PHASE === name) await phase();
    check(errors.length === 0, 'No uncaught browser errors: ' + errors.join('; '));
    console.log(`Activity gate browser: ${checks} checks passed (real Chromium, authorized schedules, drafts/questions/events off outside windows, scopes, revocation and recovery).`);
})().catch(error => { console.error(error.stack || error); console.error(serverOutput.slice(-16000)); process.exitCode = 1; })
    .finally(async () => {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        for (const suffix of ['', '-journal', '-wal', '-shm']) { try { fs.unlinkSync(db + suffix); } catch (error) { if (error.code !== 'ENOENT') throw error; } }
    });
