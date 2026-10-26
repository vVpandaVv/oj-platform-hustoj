'use strict';

// Role/capability data and WordPress authentication are fixture infrastructure.
// The role migration, platform renderer, teacher activity creation, tracking
// gate, ingestion and verified-check handlers are production implementations.
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
const db = path.join(temporary, `student-defaults-browser-${process.pid}-${Date.now()}.sqlite`);
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:/php/php.exe' : 'php');
let browser; let server; let base; let output = ''; let assertions = 0;
const errors = [];
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function check(value, message) { assert.ok(value, message); assertions += 1; }
async function until(fn, message, timeout = 18000) {
    const end = Date.now() + timeout;
    do { const result = await fn(); if (result) return result; await delay(60); } while (Date.now() < end);
    throw new Error(message);
}
async function api(url, body, method = 'POST', allowFailure = false) {
    const response = await fetch(base + url, { method: body === undefined && method === 'POST' ? 'GET' : method,
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': 'focus-test-nonce' }, body: body === undefined ? undefined : JSON.stringify(body) });
    const text = await response.text();
    if (!allowFailure) assert.ok(response.ok, `${url}: ${response.status} ${text.slice(0, 1500)}`);
    return { status: response.status, body: JSON.parse(text) };
}
async function role(config) { return (await api('/__test/student-role-config', config)).body; }
async function snapshot() { return (await api('/__test/state')).body; }
async function pageIn(context, url) {
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + url); return page;
}
async function createByTeacher(name) {
    await role({ actorId: 3 });
    const context = await browser.newContext({ timezoneId: 'Asia/Seoul' });
    const page = await pageIn(context, '/__test/dashboard');
    const form = page.locator('[data-field="create-form"]');
    await until(async () => await form.locator('[data-field="context"] option').count() >= 4, 'Teacher group picker must load authorized contexts.');
    await form.locator('[data-field="name"]').fill(name);
    await form.locator('[data-field="context"]').selectOption('group:group:1');
    const clock = (await snapshot()).nowMs;
    const values = await page.evaluate(now => {
        const local = ms => { const date = new Date(ms); const pad = value => String(value).padStart(2, '0');
            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds()); };
        return { start: local(now + 10000), end: local(now + 20000) };
    }, clock);
    await form.locator('[data-field="starts-at"]').fill(values.start);
    await form.locator('[data-field="ends-at"]').fill(values.end);
    const created = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/activities'));
    await form.getByRole('button', { name: '활동 만들기', exact: true }).click();
    const response = await created;
    assert.ok(response.ok(), 'Teacher activity creation must use the actual accepted API.');
    const activity = await response.json();
    const payload = response.request().postDataJSON();
    check(payload.context_type === 'group' && payload.context_id === 'group:1' && payload.problem_ids === null,
        'Teacher creates the activity by selecting its group and times, without student capability or per-problem configuration.');
    check(!Object.keys(payload).some(key => /capabilit|permission|student|user_ids/.test(key)), 'Teacher activity creation carries no student permission grants.');
    await context.close(); return activity;
}
async function edit(page, marker) {
    await page.evaluate(marker => { const editor = ace.edit(document.querySelector('[data-oj-ace-surface]'));
        editor.setValue('# ' + marker + '\nvalue = 8\nif value <= 10:\n    print(value)', -1); editor.focus();
    }, marker);
}
async function automaticWindow(studentId, description) {
    console.log('Checking role default and automatic participation: ' + description);
    const activity = await createByTeacher(description + ' 자동 활동');
    const rights = await role({ actorId: studentId });
    check(rights.canTrack === true, description + ' inherits tracking permission without a per-user grant.');
    const key = rights.participateCapability;
    assert.ok(typeof key === 'string' && rights.roleCaps && rights.roleCaps.subscriber && rights.userCaps, 'Fixture exposes persisted role and user capability evidence.');
    check(rights.roleCaps.subscriber[key] === true, 'The public mapped capability is supplied by the subscriber role.');
    check(!Object.prototype.hasOwnProperty.call(rights.userCaps, key), 'No explicit student capability grant was written.');
    const context = await browser.newContext(); const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    const requests = []; const gates = [];
    page.on('request', item => {
        if (item.method() === 'POST' && /\/(events|checks\/next|checks\/\d+\/response)(?:\?|$)/.test(item.url())) requests.push({ url: item.url(), body: item.postDataJSON() });
    });
    page.on('response', async response => {
        if (!response.url().includes('/tracking/state')) return;
        try { gates.push({ status: response.status(), body: await response.json() }); } catch (_) { /* Preserve the UI failure if no lease arrives. */ }
    });
    await page.goto(base + '/problems/123?group_id=1');
    await page.locator('[data-oj-ace-editor][data-mounted="true"]').waitFor();
    await page.locator('select[name="language_id"]').selectOption('6');
    await edit(page, 'PRIVATE_BEFORE_START_' + studentId);
    await until(() => gates.some(item => item.status === 200 && item.body.active === false), 'Student page must be confirmed inactive before its scheduled start.');
    check(Date.now() < Date.parse(activity.startsAt), 'The normal problem page was already open before the start.');
    check(requests.length === 0, 'Before the start there are no event, question, answer or draft requests.');
    check((await snapshot()).participations.filter(item => Number(item.user_id) === studentId).length === 0, 'Before the start no student participation row exists.');
    await page.waitForFunction(() => window.FocusTrackerActivityGate?.state().active === true);
    await page.locator('[data-ftk-check]').waitFor();
    await edit(page, 'PRIVATE_DURING_ACTIVITY_' + studentId);
    const observed = await until(async () => { const data = await snapshot();
        const ids = data.participations.filter(item => Number(item.user_id) === studentId).map(item => Number(item.id));
        return data.events.some(item => ids.includes(Number(item.participation_id)) && item.event_type === 'activity') ? data : null;
    }, 'An automatically enrolled student edit must reach actual event storage.');
    check(requests.some(item => item.url.includes('/checks/next') && Number(item.body.user_id) === studentId), 'Verified questions start automatically for the inherited student permission.');
    check(observed.activity_links.some(link => Number(link.activity_id) === Number(activity.id)), 'The automatically collected session is linked to the teacher-created group activity.');
    await page.waitForFunction(() => window.FocusTrackerActivityGate?.state().active === false, undefined, { timeout: 25000 });
    check(await page.locator('[data-ftk-check], [data-ftk-check-launcher]').count() === 0, 'The scheduled end removes the question without teacher action.');
    check(await page.locator('[data-ftk-gate-label]').textContent() === '활동 종료', 'The student sees the scheduled activity-ended state.');
    await delay(700); const count = requests.length;
    await edit(page, 'PRIVATE_AFTER_END_' + studentId); await delay(1300);
    check(requests.length === count, 'After the end further editor input sends no event, draft or question request.');
    const ended = await snapshot();
    const ids = ended.participations.filter(item => Number(item.user_id) === studentId).map(item => Number(item.id));
    check(ended.events.filter(item => ids.includes(Number(item.participation_id))).every(item => Number(item.occurred_at_ms) >= Date.parse(activity.startsAt)
        && (item.event_type === 'session_end' ? Number(item.occurred_at_ms) <= Date.parse(activity.endsAt) : Number(item.occurred_at_ms) < Date.parse(activity.endsAt))),
    'Every stored learning signal is confined to the scheduled window.');
    await context.close();
}
async function deniedPage(actorId, description, extra = {}) {
    const rights = await role({ actorId, ...extra });
    check(rights.canTrack === false, description + ' does not receive implicit tracking permission.');
    const context = await browser.newContext(); const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    const requests = [];
    page.on('request', item => { if (item.method() === 'POST' && /\/(events|tracking\/state|checks\/)/.test(item.url())) requests.push(item.url()); });
    await page.goto(base + '/problems/123?group_id=1');
    await page.locator('[data-oj-ace-editor][data-mounted="true"]').waitFor(); await edit(page, 'PRIVATE_DENIED_DRAFT'); await delay(1000);
    check(requests.length === 0, description + ' does not start the tracker, schedule probes or draft checks.');
    check(await page.locator('#focus-tracker-config').count() === 0, description + ' receives no tracking bootstrap configuration.');
    const blocked = await api('/wp-json/focus-tracker/v1/tracking/state', { user_id: actorId, problem_id: 123, context_type: 'group', context_id: '1' }, 'POST', true);
    check([401, 403].includes(blocked.status), description + ' is rejected by production tracking authorization as well as asset eligibility.');
    await context.close();
}
async function run() {
    await api('/__test/reset', {});
    await api('/__test/check-config', { reset: true, scenario: 'problem', initialDelayMs: 50, pollMs: 1000, gatePollMs: 500, dailyLimit: 20, cooldownSeconds: 60 });
    await api('/__test/statistics-config', { userId: 1, denyContext: false });
    const initial = await role({ reset: true, enabled: true, actorId: 1 });
    check(initial.canTrack === false, 'The existing subscriber begins without a manually granted addon capability.');
    await automaticWindow(1, '기존 학생');
    const absent = await role({ actorId: 2 });
    check(absent.canTrack === false, 'The new learner identity has no role before account creation.');
    const created = await role({ newStudentId: 2, actorId: 2 });
    check(created.canTrack === true, 'Creating a subscriber after migration immediately inherits the existing role capability.');
    await automaticWindow(2, '신규 학생');
    await deniedPage(3, '비학생 교사');
    await deniedPage(0, '게스트');
    const rights = await role({ actorId: 1 });
    assert.ok(rights.participateCapability, 'Role fixture must expose the actual public capability mapping.');
    await deniedPage(1, '개별 추적 권한을 명시적으로 거부한 학생', { users: { 1: { roles: ['subscriber'], caps: { [rights.participateCapability]: false } } } });
    await deniedPage(1, '기본 부여 후 역할 권한을 회수한 학생', { users: { 1: { roles: ['subscriber'], caps: {} } }, roleTracking: null });
    const revoked = await role({ actorId: 1 });
    check(!Object.prototype.hasOwnProperty.call(revoked.roleCaps.subscriber, rights.participateCapability), 'Ordinary problem-page initialization never restores a role grant removed after migration.');
    await role({ reset: true, enabled: true, actorId: 1, roleTracking: false });
    await deniedPage(1, '첫 적용 전 역할에서 명시적으로 거부한 학생');
    const denied = await role({ actorId: 1 });
    check(denied.roleCaps.subscriber[rights.participateCapability] === false && denied.migration.owned === false,
        'An existing role-level denial is preserved and is never claimed as an addon-owned grant.');
}
(async () => {
    const port = await new Promise(resolve => { const socket = net.createServer(); socket.listen(0, '127.0.0.1', () => { const port = socket.address().port; socket.close(() => resolve(port)); }); });
    base = `http://127.0.0.1:${port}`;
    server = spawn(php, ['-d', 'extension=pdo_sqlite', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'http-fixture.php')], { cwd: root, env: { ...process.env, FOCUS_TRACKER_TEST_DB: db }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    server.stdout.on('data', data => { output += data; }); server.stderr.on('data', data => { output += data; });
    await until(async () => { try { return (await fetch(base + '/__test/state')).ok; } catch (_) { return false; } }, 'PHP student-role fixture did not start.');
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH, process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')].find(item => item && fs.existsSync(item));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    await run(); check(errors.length === 0, 'No browser errors: ' + errors.join('; '));
    console.log(`Student defaults browser: ${assertions} checks passed (role-backed permissions, existing/new students, teacher group schedule, automatic start/end, negative identities).`);
})().catch(error => { console.error(error.stack || error); console.error(output.slice(-10000)); process.exitCode = 1; }).finally(async () => {
    if (browser) await browser.close();
    if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
    for (const suffix of ['', '-journal', '-wal', '-shm']) { try { fs.unlinkSync(db + suffix); } catch (error) { if (error.code !== 'ENOENT') throw error; } }
});
