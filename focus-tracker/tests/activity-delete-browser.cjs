'use strict';

// Real dashboard, activity DELETE/controller and SQLite-backed repositories.
// Network failure and delayed ACK tests interrupt delivery, never fabricate a
// successful API response. WordPress cookie authentication remains a fixture.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
function playwright() {
    const choices = [process.env.PLAYWRIGHT_MODULE_PATH, 'playwright', process.env.USERPROFILE && path.join(process.env.USERPROFILE, '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')];
    for (const item of choices.filter(Boolean)) { try { return require(item); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; } }
    throw new Error('Playwright is required.');
}
const root = path.resolve(__dirname, '../..');
const temporary = path.join(root, '_notes/tmp/focus-tracker-tests');
fs.mkdirSync(temporary, { recursive: true });
const db = path.join(temporary, `activity-delete-browser-${process.pid}-${Date.now()}.sqlite`);
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:/php/php.exe' : 'php');
let browser; let server; let base; let output = ''; let assertions = 0;
const errors = [];
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function check(value, message) { assert.ok(value, message); assertions += 1; }
async function until(fn, message) {
    const end = Date.now() + 15000;
    do { const result = await fn(); if (result) return result; await delay(50); } while (Date.now() < end);
    throw new Error(message);
}
async function api(url, body, method = 'POST', allowFailure = false) {
    const response = await fetch(base + url, { method: body === undefined && method === 'POST' ? 'GET' : method,
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': 'focus-test-nonce' }, body: body === undefined ? undefined : JSON.stringify(body) });
    const text = await response.text();
    if (!allowFailure) assert.ok(response.ok, `${url}: ${response.status} ${text.slice(0, 1500)}`);
    return { status: response.status, body: JSON.parse(text) };
}
async function setup() {
    await api('/__test/reset', {});
    await api('/__test/check-config', { reset: true, scenario: 'unavailable' });
    return (await api('/__test/statistics-seed', {})).body;
}
async function open(options = {}) {
    const context = await browser.newContext(options); const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', async dialog => { errors.push('Unexpected native dialog: ' + dialog.message()); await dialog.dismiss(); });
    const requests = [];
    page.on('request', request => { if (request.method() === 'DELETE') requests.push({ url: request.url(), headers: request.headers() }); });
    await page.goto(base + '/__test/dashboard');
    await page.locator('[data-activity-id]').first().waitFor();
    return { context, page, requests };
}
function row(app, id) { return app.page.locator(`[data-activity-id="${id}"]`); }
function confirmation(app) { return app.page.locator('[data-field="delete-confirmation"]'); }
async function begin(app, id) {
    await row(app, id).locator('[data-action="delete-activity"]').click();
    await confirmation(app).waitFor();
}
async function remove(app, id) {
    await begin(app, id);
    const acknowledged = app.page.waitForResponse(response => response.request().method() === 'DELETE' && response.url().endsWith('/activities/' + id));
    await confirmation(app).locator('[data-action="confirm-delete"]').click();
    const response = await acknowledged; assert.ok(response.ok(), 'Production delete endpoint must accept the fixture activity.');
    await row(app, id).waitFor({ state: 'detached' });
}
async function statesCancelAndDuplicate() {
    const seed = await setup();
    const future = (await api('/__test/activity', { name: '예정 활동 삭제', startOffsetMs: 60000, endOffsetMs: 120000 })).body;
    const active = (await api('/__test/activity', { name: '진행 활동 삭제', startOffsetMs: -1000, endOffsetMs: 60000 })).body;
    const app = await open();
    for (const [id, expected] of [[future.id, '예정'], [active.id, '진행 중'], [seed.activityIds[1], '종료']]) {
        check(await row(app, id).locator('.ojp-status-badge').textContent() === expected
            && await row(app, id).locator('[data-action="delete-activity"]').isEnabled(), `${expected} activities all expose deletion.`);
    }
    await begin(app, future.id);
    check(await confirmation(app).getByRole('group').count() === 1, 'Deletion confirmation is an accessible inline group.');
    check(await confirmation(app).locator('[data-action="cancel-delete"]').evaluate(node => node === document.activeElement), 'Opening confirmation focuses the safe cancel action.');
    check((await confirmation(app).textContent()).includes('기존 학습 기록과 주간·월간 누적 통계는 보관기간에 따라 유지'), 'Confirmation states preserved learning history and cumulative statistics.');
    check((await confirmation(app).textContent()).includes('삭제한 활동은 복구할 수 없습니다'), 'Confirmation explains the lack of activity restoration.');
    await confirmation(app).locator('[data-action="cancel-delete"]').click();
    check(app.requests.length === 0 && await confirmation(app).count() === 0, 'Cancel performs no DELETE request.');
    check(await row(app, future.id).locator('[data-action="delete-activity"]').evaluate(node => node === document.activeElement), 'Cancel restores the initiating deletion button.');

    let release; const held = new Promise(resolve => { release = resolve; }); let calls = 0;
    await app.page.route('**/activities/' + future.id, async route => {
        if (route.request().method() !== 'DELETE') return route.continue();
        calls += 1; await held; await route.continue();
    });
    await begin(app, future.id); await confirmation(app).locator('[data-action="confirm-delete"]').click();
    await until(() => calls === 1, 'The first DELETE should be held in flight.');
    check(await confirmation(app).locator('[data-action="confirm-delete"]').isDisabled()
        && await confirmation(app).locator('[data-action="cancel-delete"]').isDisabled(), 'Pending deletion disables repeated confirmation and cancellation.');
    check(await confirmation(app).getByRole('group').getAttribute('aria-busy') === 'true', 'Pending deletion has an accessible busy state.');
    await confirmation(app).locator('[data-action="confirm-delete"]').evaluate(node => { node.dispatchEvent(new MouseEvent('click', { bubbles: true })); node.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
    check(calls === 1, 'Duplicate handlers cannot issue a second in-flight DELETE.');
    release(); await row(app, future.id).waitFor({ state: 'detached' });
    check(await app.page.locator('[data-field="activity-context-filter"]').evaluate(node => node === document.activeElement), 'Deletion focuses the surviving activity-list filter after its row disappears.');
    check(app.requests[0].headers['x-wp-nonce'] === 'focus-test-nonce', 'DELETE uses the authenticated dashboard nonce.');
    const repeated = await api('/wp-json/focus-tracker/v1/activities/' + future.id, undefined, 'DELETE');
    check(repeated.body.deleted === true && Number(repeated.body.id) === Number(future.id), 'A replayed DELETE is idempotently acknowledged.');
    await remove(app, active.id); await remove(app, seed.activityIds[1]);
    const list = (await api('/wp-json/focus-tracker/v1/activities')).body;
    check(!list.some(item => [future.id, active.id, seed.activityIds[1]].map(Number).includes(Number(item.id))), 'All deleted activity states disappear from the production list.');
    check(await row(app, seed.activityIds[2]).count() === 1, 'Other activities remain visible.');
    await app.context.close();
}
async function failuresAndMobile() {
    const seed = await setup(); const app = await open({ viewport: { width: 390, height: 720 } }); const id = seed.activityIds[1];
    await begin(app, id);
    const width = await confirmation(app).evaluate(node => {
        const box = node.querySelector('.ftk-delete-confirmation').getBoundingClientRect();
        const console = document.querySelector('.ftk-console');
        return { viewport: innerWidth, left: box.left, right: box.right, rootWidth: console.clientWidth, rootScroll: console.scrollWidth };
    });
    check(width.left >= -1 && width.right <= width.viewport + 1 && width.rootScroll <= width.rootWidth + 1,
        'Inline confirmation is readable within the mobile viewport while its activity table scrolls independently: ' + JSON.stringify(width));
    await api('/__test/statistics-config', { denyContext: true });
    const rejected = app.page.waitForResponse(response => response.request().method() === 'DELETE');
    await confirmation(app).locator('[data-action="confirm-delete"]').click();
    check((await rejected).status() === 403, 'Revoked context access is rejected by the real delete handler.');
    await until(async () => await confirmation(app).locator('[data-field="delete-error"]').textContent(), 'A delete failure should appear in the same confirmation.');
    check(await row(app, id).count() === 1 && await confirmation(app).locator('[data-action="confirm-delete"]').isEnabled(), 'Failure retains the activity and makes retry available.');
    check(await confirmation(app).locator('[data-action="confirm-delete"]').evaluate(node => node === document.activeElement), 'Failed acknowledgement returns keyboard focus to retry.');
    await api('/__test/statistics-config', { denyContext: false });
    let first = true;
    await app.page.route('**/activities/' + id, route => {
        if (route.request().method() === 'DELETE' && first) { first = false; return route.abort('failed'); }
        return route.continue();
    });
    await confirmation(app).locator('[data-action="confirm-delete"]').click();
    await until(async () => (await confirmation(app).locator('[data-field="delete-error"]').textContent()).includes('연결 상태'), 'Network failures have an actionable Korean retry message.');
    check(await row(app, id).count() === 1, 'A failed delivery does not optimistically remove the activity.');
    await confirmation(app).locator('[data-action="confirm-delete"]').click();
    await row(app, id).waitFor({ state: 'detached' });
    check((await api('/wp-json/focus-tracker/v1/activities')).body.every(item => Number(item.id) !== Number(id)), 'The same confirmation succeeds after permission and connection recovery.');
    await app.context.close();
}
async function preserveHistoryAndInvalidateReports() {
    const seed = await setup();
    const active = (await api('/__test/activity', { name: '보존 정책 검증 활동', context_type: 'group', context_id: 'group:1', startOffsetMs: -60000, endOffsetMs: 60000 })).body;
    const id = active.id;
    const end = Date.now() - 1000; const start = end - 3000;
    await api('/wp-json/focus-tracker/v1/events', { user_id: 1, problem_id: 123, context_type: 'group', context_id: 'group:1',
        session_uid: 'delete-history', session_started_at: start, is_final: true, events: [
            { type: 'session_start', occurred_at: start, seq: 1, meta: { visible: true, focused: true } },
            { type: 'activity', occurred_at: start + 1000, seq: 2, meta: { subtype: 'code_edit' } },
            { type: 'session_end', occurred_at: end, seq: 3, meta: { visible: true, focused: true } },
        ] });
    await api('/__test/check-config', { scenario: 'problem' });
    const issued = (await api('/wp-json/focus-tracker/v1/checks/next', { user_id: 1, problem_id: 123, context_type: 'group', context_id: '1' })).body;
    assert.equal(issued.status, 'ready', 'History preservation starts with a real verified check.');
    await api('/wp-json/focus-tracker/v1/checks/' + issued.check.id + '/response', { user_id: 1, response_uid: 'delete-history-check', action: 'unknown' });
    const app = await open();
    const before = (await api('/__test/state')).body;
    check(before.checks.length > 0 && before.checks.some(item => item.status === 'unknown'), 'Preservation test includes an actual completed learning-check response.');
    const statistics = '/wp-json/focus-tracker/v1/me/statistics?period=week&date=' + seed.date;
    const total = (await api(statistics)).body;
    await row(app, id).locator('[data-action="edit-activity"]').click();
    await row(app, id).locator('[data-action="view-scores"]').click();
    await app.page.locator('[data-field="scores-body"] [data-action="view-statistics"]').first().click();
    await app.page.locator('#ftk-teacher-statistics').waitFor();
    let release; const held = new Promise(resolve => { release = resolve; }); let captured = false;
    await app.page.route('**/activities/' + id + '/scores', async route => {
        const response = await route.fetch(); captured = true; await held; await route.fulfill({ response });
    });
    await row(app, id).locator('[data-action="view-scores"]').click();
    await until(() => captured, 'The actual score result should be held before delivery.');
    await remove(app, id); release(); await delay(200);
    check(await app.page.locator('[data-field="edit-panel"]').isHidden(), 'Deleting the edited activity closes its editor.');
    check(await app.page.locator('[data-field="scores-panel"]').isHidden()
        && await app.page.locator('#ftk-teacher-statistics').isHidden(), 'Deletion hides activity results and any selected cumulative report.');
    check((await app.page.locator('[data-field="scores-body"]').textContent()).includes('불러오는 중'), 'A late score response cannot replace the invalidated loading state.');
    const after = (await api('/__test/state')).body;
    check(JSON.stringify(after.events) === JSON.stringify(before.events) && JSON.stringify(after.participations) === JSON.stringify(before.participations), 'Soft deletion preserves original learning events and participation history.');
    check(JSON.stringify(after.checks) === JSON.stringify(before.checks), 'Existing learning-check responses are preserved.');
    const retained = (await api(statistics)).body;
    check(retained.totals.activeSeconds === total.totals.activeSeconds && retained.totals.editCount === total.totals.editCount, 'Student weekly cumulative statistics retain the same original observations.');
    check((await api('/wp-json/focus-tracker/v1/activities/' + id + '/scores', undefined, 'GET', true)).status === 404, 'Deleted activity results cannot be fetched again.');

    const second = seed.activityIds[2];
    await row(app, second).locator('[data-action="view-scores"]').click();
    await app.page.locator('[data-field="scores-body"] [data-action="view-timeline"]').first().waitFor();
    let resume; const pause = new Promise(resolve => { resume = resolve; }); let timelineCaptured = false;
    await app.page.route('**/activities/' + second + '/participants/*/timeline', async route => {
        const response = await route.fetch(); timelineCaptured = true; await pause; await route.fulfill({ response });
    });
    await app.page.locator('[data-field="scores-body"] [data-action="view-timeline"]').first().click();
    await until(() => timelineCaptured, 'The actual timeline should be held before delivery.');
    await remove(app, second); resume(); await delay(200);
    check(await app.page.locator('[data-field="timeline-panel"]').isHidden(), 'Deletion hides a timeline whose response is still in flight.');
    check((await app.page.locator('[data-field="timeline-chart"]').textContent()) === '불러오는 중…', 'Late timeline delivery cannot recreate the deleted activity display.');
    await app.context.close();
}

async function filteredPendingFailure() {
    const seed = await setup(); const app = await open();
    let resume; const held = new Promise(resolve => { resume = resolve; }); let rejected = false;
    await app.page.route('**/activities/' + seed.activityIds[1], async route => {
        if (route.request().method() !== 'DELETE') return route.continue();
        const response = await route.fetch();
        assert.equal(response.status(), 403, 'The permission failure is returned by production authorization.');
        rejected = true; await held; await route.fulfill({ response });
    });
    await begin(app, seed.activityIds[1]); await api('/__test/statistics-config', { denyContext: true });
    await confirmation(app).locator('[data-action="confirm-delete"]').click();
    await until(() => rejected, 'A real denied DELETE should wait before its response reaches the browser.');
    await api('/__test/statistics-config', { denyContext: false });
    const filter = app.page.locator('[data-field="activity-context-filter"]');
    await filter.focus();
    await filter.selectOption('group:group:2');
    const other = row(app, seed.activityIds[2]); await other.waitFor();
    check(await other.locator('[data-action="delete-activity"]').isDisabled(), 'A rerendered filtered list preserves the pending deletion lock.');
    resume();
    await until(async () => await other.locator('[data-action="delete-activity"]').isEnabled(), 'A failed request must unlock deletion controls in the current filtered list.');
    check(await filter.evaluate(node => node === document.activeElement), 'A late failure does not take focus away from the newly selected filter.');
    await begin(app, seed.activityIds[2]);
    check(await confirmation(app).locator('[data-action="cancel-delete"]').isEnabled(), 'Deletion remains usable after a failure in another filtered group.');
    await app.context.close();
}

async function openLearnerStopsAfterDelete() {
    await api('/__test/reset', {});
    await api('/__test/check-config', { reset: true, scenario: 'problem', initialDelayMs: 50, pollMs: 1000, gatePollMs: 500, userId: 1 });
    const lesson = (await api('/__test/activity', { name: '열린 학생 화면 종료', startOffsetMs: -1000, endOffsetMs: 60000 })).body;
    const context = await browser.newContext(); const learner = await context.newPage();
    learner.on('pageerror', error => errors.push(error.message));
    const requests = [];
    learner.on('request', item => { if (item.method() === 'POST' && /\/(events|checks\/next|checks\/\d+\/response)(?:\?|$)/.test(item.url())) requests.push(item.url()); });
    await learner.goto(base + '/problems/123');
    await learner.locator('[data-oj-ace-editor][data-mounted="true"]').waitFor();
    await learner.locator('[data-ftk-check]').waitFor();
    await learner.evaluate(() => { const editor = ace.edit(document.querySelector('[data-oj-ace-surface]')); editor.setValue('# BEFORE_DELETE\nvalue = 8', -1); editor.focus(); });
    const original = await until(async () => { const data = (await api('/__test/state')).body; return data.events.some(item => item.event_type === 'activity') ? data : null; }, 'Eligible editing must be stored before activity deletion.');
    const teacher = await open(); await remove(teacher, lesson.id);
    await learner.waitForFunction(() => window.FocusTrackerActivityGate.state().active === false);
    check(await learner.locator('[data-ftk-check], [data-ftk-check-launcher]').count() === 0, 'Deleting the only current activity closes an already-open learner question.');
    check(await learner.locator('[data-ftk-gate-label]').textContent() === '활동 종료', 'The already-open learner is shown the activity-ended state.');
    await delay(700); const count = requests.length;
    await learner.evaluate(() => { const editor = ace.edit(document.querySelector('[data-oj-ace-surface]')); editor.setValue('# AFTER_DELETE_PRIVATE\nvalue = 9', -1); editor.focus(); });
    await delay(1500);
    check(requests.length === count, 'Off-time editing after deletion sends no new event, draft/question or answer requests.');
    const retained = (await api('/__test/state')).body;
    check(original.events.every(item => retained.events.some(row => row.id === item.id)), 'Deletion and gate shutdown retain observations collected before deletion.');
    await teacher.context.close(); await context.close();
}
(async () => {
    const port = await new Promise(resolve => { const socket = net.createServer(); socket.listen(0, '127.0.0.1', () => { const port = socket.address().port; socket.close(() => resolve(port)); }); });
    base = `http://127.0.0.1:${port}`;
    server = spawn(php, ['-d', 'extension=pdo_sqlite', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'http-fixture.php')], { cwd: root, env: { ...process.env, FOCUS_TRACKER_TEST_DB: db }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    server.stdout.on('data', data => { output += data; }); server.stderr.on('data', data => { output += data; });
    await until(async () => { try { return (await fetch(base + '/__test/state')).ok; } catch (_) { return false; } }, 'PHP delete fixture did not start.');
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH, process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')].find(item => item && fs.existsSync(item));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    await statesCancelAndDuplicate(); await failuresAndMobile(); await preserveHistoryAndInvalidateReports(); await filteredPendingFailure(); await openLearnerStopsAfterDelete();
    check(errors.length === 0, 'No uncaught browser errors or native confirmation dialogs: ' + errors.join('; '));
    console.log(`Activity deletion browser: ${assertions} checks passed (real DELETE, all states, cancellation, duplicate clicks, focus, recovery, stale reports and preserved history).`);
})().catch(error => { console.error(error.stack || error); console.error(output.slice(-10000)); process.exitCode = 1; }).finally(async () => {
    if (browser) await browser.close();
    if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
    for (const suffix of ['', '-journal', '-wal', '-shm']) { try { fs.unlinkSync(db + suffix); } catch (error) { if (error.code !== 'ENOENT') throw error; } }
});
