'use strict';

// Real Chromium + checked-in platform renderer/editors + production tracker,
// REST controllers/repositories/scorer. Only WordPress/MySQL infrastructure is
// supplied by http-fixture.php; successful delivery is never mocked here.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');

function playwright() {
    const candidates = [process.env.PLAYWRIGHT_MODULE_PATH, 'playwright'];
    if (process.env.USERPROFILE) {
        candidates.push(path.join(process.env.USERPROFILE, '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));
    }
    for (const candidate of candidates.filter(Boolean)) {
        try { return require(candidate); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; }
    }
    throw new Error('Install Playwright or set PLAYWRIGHT_MODULE_PATH to its module directory.');
}

const root = path.resolve(__dirname, '../..');
const temporary = path.join(root, '_notes/tmp/focus-tracker-tests');
fs.mkdirSync(temporary, { recursive: true });
const db = path.join(temporary, `browser-${process.pid}-${Date.now()}.sqlite`);
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:/php/php.exe' : 'php');
let checks = 0;
let server;
let browser;
let serverOutput = '';
let base;
const pageErrors = [];
const payloads = [];
function check(condition, message) { assert.ok(condition, message); checks += 1; }
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

async function request(url, data) {
    const response = await fetch(base + url, data === undefined ? {} : {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
    const text = await response.text();
    assert.ok(response.ok, `${url}: ${response.status} ${text.slice(0, 1000)}`);
    try { return JSON.parse(text); } catch (error) { throw new Error(`${url} returned invalid JSON: ${text.slice(0, 1000)}`); }
}

async function until(predicate, message, timeout = 12000) {
    const end = Date.now() + timeout;
    do {
        const result = await predicate();
        if (result) return result;
        await delay(100);
    } while (Date.now() < end);
    throw new Error(message);
}

async function state() { return request('/__test/state'); }
function edits(data, subtype) {
    return data.events.filter(event => event.event_type === 'activity'
        && (!subtype || JSON.parse(event.meta || '{}').subtype === subtype));
}

async function newPage(context) {
    const page = await context.newPage();
    page.on('pageerror', error => pageErrors.push(error.message));
    page.on('request', item => {
        if (item.url().includes('/wp-json/focus-tracker/v1/events') && item.postData()) {
            payloads.push(item.postData());
        }
    });
    return page;
}

async function authorized(page) {
    await page.waitForFunction(() => window.FocusTrackerActivityGate?.state().active === true);
}

async function ace(page, url = '/problems/123', active = true) {
    await page.goto(base + url);
    await page.locator('[data-oj-ace-editor][data-mounted="true"]').waitFor();
    await page.locator('.ace_text-input').focus();
    check(await page.evaluate(() => window.__focusTrackerStarted === true), 'Tracker actually executes inside the platform document.');
    if (active) await authorized(page);
}

async function submit(page) {
    await Promise.all([
        page.waitForURL('**/problems/123?submitted=1'),
        page.getByRole('button', { name: '제출하기', exact: true }).click(),
    ]);
}

async function ingestHistory(activity, { gap = false, includeHidden = false, sessionUid } = {}) {
    const start = Date.parse(activity.startsAt);
    const end = Math.min(Date.parse(activity.endsAt), Date.now() - 1000);
    const signals = [{ type: 'session_start', occurred_at: start, meta: { visible: true, focused: true, queue_dropped: gap } }];
    signals.push({ type: 'activity', occurred_at: start + 1000, meta: { subtype: 'code_edit' } });
    if (includeHidden) {
        signals.push({ type: 'hidden', occurred_at: start + 4000, meta: { visible: false, focused: false } });
        signals.push({ type: 'visible', occurred_at: end - 4000, meta: { visible: true, focused: true } });
    } else {
        for (let at = start + 15000; at < end; at += 15000) {
            signals.push({ type: 'heartbeat', occurred_at: at, meta: { visible: true, focused: true } });
        }
    }
    signals.push({ type: 'session_end', occurred_at: end, meta: { visible: true, focused: true } });
    const response = await request('/wp-json/focus-tracker/v1/events?_wpnonce=focus-test-nonce', {
        session_uid: sessionUid || `quality-${activity.id}-${Date.now()}`, user_id: 1, problem_id: 123,
        session_started_at: start, is_final: true,
        events: signals.map((event, index) => ({ ...event, seq: index + 1 })),
    });
    assert.equal(response.acknowledged, true, 'Quality-state setup uses acknowledged production ingestion.');
    return { start, end };
}

async function dashboard(page, activityName, expectedStatus) {
    await page.goto(base + '/__test/dashboard');
    const activity = page.locator('[data-field="activity-list"] tr').filter({ has: page.locator('strong', { hasText: activityName }) });
    await activity.locator('[data-action="view-scores"]').click();
    const row = page.locator('[data-field="scores-body"] tr').first();
    await until(async () => (await row.locator('[data-f="status"]').count())
        && (await row.locator('[data-f="status"]').textContent()) === expectedStatus, `Dashboard must display ${expectedStatus}.`);
    return row;
}

async function scheduleBoundaryUi() {
    await request('/__test/reset', {});
    const lesson = await request('/__test/activity', { name: 'Boundary presentation', startOffsetMs: 2000, endOffsetMs: 5000 });
    const context = await browser.newContext();
    const page = await newPage(context);
    const mutations = []; const reads = [];
    page.on('request', item => {
        if (item.url().includes('/wp-json/focus-tracker/v1/activities')) {
            (item.method() === 'GET' ? reads : mutations).push(item.url());
        }
    });
    await page.goto(base + '/__test/dashboard');
    const row = page.locator(`[data-activity-id="${lesson.id}"]`);
    await row.waitFor();
    check(await row.locator('.ojp-status-badge').textContent() === '예정', 'Teacher schedule initially shows the future activity as scheduled.');
    check(await row.locator('[data-action="end-activity"]').isHidden(), 'Early end is hidden before the start.');
    await until(async () => await row.locator('.ojp-status-badge').textContent() === '진행 중', 'Teacher badge must update at the start without reloading.');
    check(await row.locator('[data-action="end-activity"]').isVisible(), 'Early end automatically becomes available while active.');
    await until(async () => await row.locator('.ojp-status-badge').textContent() === '종료', 'Teacher badge must update at the end without reloading.');
    check(await row.locator('[data-action="end-activity"]').isHidden(), 'Early end disappears automatically at the deadline.');
    check(mutations.length === 0 && reads.length === 1, 'Known schedule boundaries update presentation without mutation or repeated activity queries.');
    check((await state()).events.length === 0, 'Teacher schedule rendering never creates learner observations.');
    check((await page.locator('.ojp-page-header').textContent()).includes('활동이 끝나면 기록과 문항 제시를 중단'), 'Teacher policy explains that schedule time gates recording and questions.');
    await context.close();
}

async function reportQualityUi() {
    const context = await browser.newContext();
    const page = await newPage(context);

    await request('/__test/reset', {});
    await request('/__test/activity', { name: 'No observation' });
    let row = await dashboard(page, 'No observation', '관측 자료 없음');
    check((await row.locator('td').nth(1).textContent()) === '—' && (await row.locator('td').nth(5).textContent()) === '—', 'No-data dashboard values are dashes, not zero grades.');
    check((await row.locator('.ftk-review-badge').textContent()) === '검토 필요', 'Missing observation is explicitly marked for teacher review.');
    await row.locator('[data-action="view-timeline"]').click();
    await page.getByText('관측 자료가 없습니다. 이탈로 단정할 수 없습니다.', { exact: true }).waitFor();
    check(await page.locator('[data-field="timeline-chart"] svg').count() === 0, 'No-data timeline has an explanation and no fabricated away bar.');
    await page.goto(base + '/__test/self');
    await page.getByText('아직 기록된 활동이 없습니다', { exact: true }).waitFor();
    check(await page.locator('[data-f="total"]').count() === 0, 'A student without observations sees an empty state, not an invented score.');

    await request('/__test/reset', {});
    const incomplete = await request('/__test/activity', { name: 'Incomplete evidence', startOffsetMs: -12000, endOffsetMs: 60000 });
    await ingestHistory(incomplete, { gap: true });
    row = await dashboard(page, 'Incomplete evidence', '기록 일부 누락');
    check((await row.locator('td').nth(5).textContent()) === '—', 'Incomplete evidence does not present a numerical grade in the teacher table.');
    await row.locator('[data-action="view-timeline"]').click();
    await page.getByText('기록 일부가 누락됐습니다', { exact: true }).waitFor();
    check(await page.locator('[data-field="timeline-chart"] svg').count() === 1, 'Incomplete timeline labels its quality and shows only available evidence.');
    await page.goto(base + '/__test/self');
    await until(async () => (await page.locator('[data-f="total"]').count()) && (await page.locator('[data-f="total"]').textContent()) === '—', 'Student self-view must suppress an incomplete score.');
    check((await page.locator('[data-f="status"]').textContent()).includes('기록 일부가 누락'), 'Student self-view explains incomplete evidence.');

    await request('/__test/reset', {});
    const hidden = await request('/__test/activity', { name: 'Known hidden interval', startOffsetMs: -22000, endOffsetMs: -5000 });
    const hiddenWindow = await ingestHistory(hidden, { includeHidden: true });
    const metrics = (await request(`/wp-json/focus-tracker/v1/activities/${hidden.id}/scores`)).rows[0];
    const timeline = await request(`/wp-json/focus-tracker/v1/activities/${hidden.id}/participants/1/timeline`);
    check(timeline.spans.length === 2, 'A known hide/return pair splits the actual API timeline into two visible intervals.');
    const observedActiveMs = timeline.spans.reduce((sum, span) => sum + span.endedAt - span.startedAt, 0);
    check(observedActiveMs === 8000 && metrics.totalActiveSeconds === 8, 'Scoring and timeline both exclude the entire hidden interval.');
    row = await dashboard(page, 'Known hidden interval', '진행 중');
    await row.locator('[data-action="view-timeline"]').click();
    await page.locator('[data-field="timeline-chart"] svg').waitFor();
    const activeWidth = await page.locator('[data-field="timeline-chart"] .ftk-tl-active').evaluateAll(nodes => nodes.reduce((sum, node) => sum + Number(node.getAttribute('width')), 0));
    const drawnRatio = activeWidth / 800 * 100;
    const expectedRatio = 8000 / (hiddenWindow.end - hiddenWindow.start) * 100;
    check(Math.abs(drawnRatio - expectedRatio) < 0.001 && Math.abs(drawnRatio - metrics.focusRatio) < 0.01, 'Rendered SVG active width agrees with the persisted focus metric.');

    await request('/__test/reset', {});
    const archived = await request('/__test/activity', { name: 'Archived evidence', startOffsetMs: -100000, endOffsetMs: -50000 });
    await ingestHistory(archived);
    await request('/__test/age-records', { days: 2 });
    const beforeRetention = (await request(`/wp-json/focus-tracker/v1/activities/${archived.id}/scores`)).rows[0];
    check(beforeRetention.finalized, 'Archive UI test starts from an actual finalized score.');
    await request('/__test/retention', { retention_days: 1 });
    const retained = await state();
    check(retained.events.length === 0 && retained.scores.length === 1, 'Actual retention removes raw evidence and preserves its aggregate.');
    row = await dashboard(page, 'Archived evidence', '최종 집계 보관');
    check((await row.locator('td').nth(5).textContent()) === Number(beforeRetention.scoreTotal).toFixed(1), 'Archived teacher view preserves the previously finalized aggregate.');
    await row.locator('[data-action="view-timeline"]').click();
    await page.getByText('보관 기간이 끝나 최종 집계만 남아 있습니다.', { exact: true }).waitFor();
    check(await page.locator('[data-field="timeline-chart"] svg').count() === 0, 'Archived timeline explains retention without drawing a fabricated red bar.');
    await page.goto(base + '/__test/self');
    await until(async () => (await page.locator('[data-f="status"]').count()) && (await page.locator('[data-f="status"]').textContent()).includes('최종 집계만 남아'), 'Student self-view must label the archived aggregate.');
    check((await page.locator('[data-f="total"]').textContent()) === Number(beforeRetention.scoreTotal).toFixed(1), 'Archived student view agrees with the teacher aggregate.');
    await context.close();
}

async function preopenedActivity() {
    await request('/__test/reset', {});
    const context = await browser.newContext();
    const page = await newPage(context);
    await ace(page, '/problems/123', false);
    await delay(1000);
    const initial = await state();
    check(initial.participations.length === 0 && initial.events.length === 0, 'A page opened outside activity time collects no participation or raw events.');
    const activity = await request('/__test/activity', { name: 'Opens after page load', startOffsetMs: 3000, endOffsetMs: 30000 });
    await authorized(page);
    await delay(3500); // Observe enough active time for the existing more-than-half-window rubric.
    await page.locator('.ace_text-input').pressSequentially('LATE_ACTIVITY_INPUT', { delay: 40 });
    await submit(page);
    const linked = await until(async () => {
        const data = await state();
        return data.activity_links.some(link => Number(link.activity_id) === activity.id)
            && edits(data, 'code_edit').length > 0 && data.events.some(event => event.event_type === 'submit') ? data : null;
    }, 'A scheduled start must activate collection on the already-open page.');
    check(linked.activity_links.some(link => Number(link.activity_id) === activity.id), 'A preopened page is associated when observations cross the activity start.');
    check(linked.events.every(event => Number(event.occurred_at_ms) >= Date.parse(activity.startsAt)), 'Preopened pages persist no event before the authorized activity start.');
    const score = (await request(`/wp-json/focus-tracker/v1/activities/${activity.id}/scores`)).rows[0];
    check(score.activityWindowRatio > 0, 'Later activity includes edits performed on a page opened beforehand.');
    await context.close();
}

(async () => {
    const port = await new Promise(resolve => {
        const reservation = net.createServer();
        reservation.listen(0, '127.0.0.1', () => {
            const value = reservation.address().port;
            reservation.close(() => resolve(value));
        });
    });
    base = `http://127.0.0.1:${port}`;
    server = spawn(php, ['-d', 'extension=pdo_sqlite', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'http-fixture.php')], {
        cwd: root, env: { ...process.env, FOCUS_TRACKER_TEST_DB: db }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
    });
    server.stdout.on('data', data => { serverOutput += data; });
    server.stderr.on('data', data => { serverOutput += data; });
    await until(async () => { try { return (await fetch(base + '/__test/state')).ok; } catch (_) { return false; } }, 'PHP fixture did not start.');
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH,
        process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe'),
    ].find(candidate => candidate && fs.existsSync(candidate));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });

    await request('/__test/reset', {});
    const activityA = await request('/__test/activity', { name: 'Overlapping A' });
    const activityB = await request('/__test/activity', { name: 'Overlapping B' });
    const context = await browser.newContext();
    const page = await newPage(context);
    await ace(page);
    await page.locator('.ace_text-input').pressSequentially('SENSITIVE_SOURCE_DO_NOT_LOG', { delay: 90 });
    await submit(page);
    const afterCode = await until(async () => {
        const data = await state();
        const submitted = data.events.find(event => event.event_type === 'submit');
        return submitted && edits(data, 'code_edit').length >= 3
            && data.events.some(event => event.event_type === 'session_end' && event.participation_id === submitted.participation_id) ? data : null;
    }, 'Real Ace edits and form submission must reach persistent REST storage.');
    check(edits(afterCode, 'code_edit').length >= 3, 'Continuous editing produces signals before a typing pause.');
    const firstParticipation = afterCode.participations[0].id;
    const linkedActivities = afterCode.activity_links.filter(link => Number(link.participation_id) === Number(firstParticipation)).map(link => Number(link.activity_id));
    check(linkedActivities.includes(activityA.id) && linkedActivities.includes(activityB.id), 'One page session is associated with both overlapping activities.');
    for (const activity of [activityA, activityB]) {
        const result = await request(`/wp-json/focus-tracker/v1/activities/${activity.id}/scores`);
        check(result.rows.length === 1 && result.rows[0].activityWindowRatio > 0, 'Persisted real editor signals contribute to each activity score.');
    }
    const summary = await request('/wp-json/focus-tracker/v1/me/summary');
    check(summary.hasActivity && summary.activityWindowRatio > 0, 'Student self-view reads the same persisted evidence.');

    const priorCompletion = edits(afterCode, 'code_edit').length;
    await page.goto(base + '/submit?problem_id=123&mode=completion');
    await authorized(page);
    await page.locator('textarea[name="completion_answers[answer]"]').fill('return 7;');
    await page.locator('input[name="completion_answers[expression]"]').fill('0');
    await submit(page);
    const afterCompletion = await until(async () => {
        const data = await state();
        return edits(data, 'code_edit').length > priorCompletion && data.events.filter(event => event.event_type === 'submit').length >= 2 ? data : null;
    }, 'Completion inputs and query-string problem selection must be tracked.');
    check(afterCompletion.participations.every(row => Number(row.problem_id) === 123), 'Query-string submit routes retain their problem identity.');

    await page.goto(base + '/problems/123?mode=blockly');
    await page.locator('[data-oj-blockly-editor][data-mounted="true"]').waitFor();
    await authorized(page);
    await delay(1200); // Allow the tracker to discover the lazily mounted workspace.
    await page.evaluate(() => {
        const workspace = Blockly.common.getAllWorkspaces().find(item => !item.isFlyout && item.getParentSvg()?.closest('[data-oj-blockly-editor]'));
        if (!workspace) throw new Error('Actual platform Blockly workspace is absent.');
        const block = workspace.newBlock('math_number');
        block.initSvg();
        block.render();
        block.setFieldValue('7', 'NUM');
    });
    await delay(250);
    await page.goto(base + '/problems/123');
    const afterBlockly = await until(async () => {
        const data = await state();
        return edits(data, 'blockly_edit').length > 0 ? data : null;
    }, 'Actual Blockly change events must reach persistent storage.');
    check(edits(afterBlockly, 'blockly_edit').length > 0, 'Lazily loaded Blockly edits are counted.');

    await page.locator('[data-oj-ace-editor][data-mounted="true"]').waitFor();
    await authorized(page);
    const beforeOffline = edits(await state(), 'code_edit').length;
    await context.setOffline(true);
    await page.locator('.ace_text-input').focus();
    await page.locator('.ace_text-input').pressSequentially('OFFLINE_PRIVATE_EDIT', { delay: 50 });
    await delay(7300);
    const queued = await page.evaluate(() => Object.keys(localStorage).filter(key => key.startsWith('focus-tracker-outbox-v2:')).length);
    check(queued > 0, 'A failed REST request retains durable pending events.');
    await context.setOffline(false);
    const recovered = await until(async () => {
        const data = await state();
        return edits(data, 'code_edit').length > beforeOffline ? data : null;
    }, 'Offline activity must be retried after connectivity returns.', 20000);
    check(edits(recovered, 'code_edit').length > beforeOffline, 'Retried browser events are persisted after recovery.');
    const identities = recovered.events.filter(event => event.sequence_number !== null).map(event => `${event.participation_id}:${event.sequence_number}`);
    check(new Set(identities).size === identities.length, 'Fetch/beacon retry paths do not double-count event sequence numbers.');
    check(!payloads.some(payload => payload.includes('SENSITIVE_SOURCE_DO_NOT_LOG') || payload.includes('OFFLINE_PRIVATE_EDIT') || payload.includes('page_url')), 'Real browser requests contain no source text or visited URL.');
    await context.close();
    await delay(250);
    await reportQualityUi();
    await delay(250);
    await preopenedActivity();
    await scheduleBoundaryUi();
    check(pageErrors.length === 0, `Platform/browser execution has no uncaught errors: ${pageErrors.join('; ')}`);
    console.log(`Platform browser: ${checks} checks passed (real Chromium/editors, REST persistence, score/timeline quality UI and preopened activities).`);
})().catch(error => {
    console.error(error.stack || error);
    console.error(serverOutput.slice(-14000));
    process.exitCode = 1;
}).finally(async () => {
    if (browser) await browser.close();
    if (server && server.exitCode === null) {
        server.kill();
        await new Promise(resolve => server.once('exit', resolve));
    }
    for (const suffix of ['', '-journal', '-wal', '-shm']) {
        try { fs.unlinkSync(db + suffix); } catch (error) { if (error.code !== 'ENOENT') throw error; }
    }
});
