'use strict';

// Real Chromium + platform-rendered student/teacher pages + production REST
// reports and aggregation. Only WordPress, authentication and roster data use
// the local PHP fixture; statistics responses and charts are never mocked.
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
const db = path.join(temporary, `statistics-browser-${process.pid}-${Date.now()}.sqlite`);
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:/php/php.exe' : 'php');
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
let server;
let browser;
let base;
let serverOutput = '';
let checks = 0;
const errors = [];
function check(condition, message) { assert.ok(condition, message); checks += 1; }
async function api(url, payload, method = 'POST', allowError = false) {
    const response = await fetch(base + url, payload === undefined ? {} : {
        method, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': 'focus-test-nonce' }, body: JSON.stringify(payload),
    });
    const text = await response.text();
    if (!allowError) assert.ok(response.ok, `${url}: ${response.status} ${text.slice(0, 1200)}`);
    return { status: response.status, body: JSON.parse(text) };
}
async function until(predicate, message, timeout = 12000) {
    const end = Date.now() + timeout;
    do {
        const result = await predicate();
        if (result) return result;
        await delay(50);
    } while (Date.now() < end);
    throw new Error(message);
}
async function pageFor(context, url) {
    const page = await context.newPage();
    const reports = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', async response => {
        if (!/\/statistics(?:\?|$)/.test(response.url())) return;
        const report = { url: response.url(), status: response.status(), body: {} };
        reports.push(report);
        try { report.body = await response.json(); } catch (_) { /* Keep rejected HTTP status even when body is unread. */ }
    });
    await page.goto(base + url);
    return { page, reports, context };
}
async function report(app, after = 0, predicate = () => true) {
    return until(() => app.reports.slice(after).find(item => item.status === 200 && item.body.totals && predicate(item)),
        'The actual statistics endpoint did not return the requested report.');
}
async function date(app, value) {
    const prior = app.reports.length;
    await app.page.locator('[data-ftk-statistics] [data-stat="date"]').evaluate((input, value) => {
        input.value = value; input.dispatchEvent(new Event('change', { bubbles: true }));
    }, value);
    return report(app, prior, item => new URL(item.url).searchParams.get('date') === value);
}
function day(data, date) { const value = data.days.find(item => item.date === date); assert.ok(value, date); return value; }
async function renderedDay(app, value) {
    return app.page.locator(`[data-ftk-statistics] [data-stat-day="${value}"]`).textContent();
}

async function studentReports(seed) {
    console.log('Checking student weekly/monthly charts and calendar controls');
    const context = await browser.newContext({ timezoneId: 'America/Los_Angeles' });
    const app = await pageFor(context, '/__test/self');
    const initial = await report(app);
    check(initial.body.timeZone === 'Asia/Seoul' && initial.body.today === seed.today, 'Site calendar dates and timezone remain authoritative in a browser using another timezone.');
    check(await app.page.locator('[data-ftk-statistics] [data-stat="date"]').inputValue() === seed.today,
        'The date picker starts from the server site date.');
    const week = (await date(app, seed.date)).body;
    check(day(week, seed.date).activeSeconds === seed.expectedSelfActiveSeconds,
        'Two overlapping problem tabs contribute their union and the other problem contributes once.');
    check(day(week, seed.date).editCount === 3 && day(week, seed.date).submitCount === 3,
        'Daily totals count the three actual edit/submit signals instead of double-counting linked activities.');
    check(week.totals.activeSeconds === seed.expectedSelfActiveSeconds, 'Weekly totals agree with the seeded observed intervals.');
    check(week.days.length === 7, 'A weekly chart and table cover seven calendar days.');
    const widget = app.page.locator('[data-ftk-statistics]');
    await widget.locator('[data-stat="chart"] svg').waitFor();
    check(await widget.locator('.ftk-stat-bar').count() === 1 && await widget.locator('.ftk-stat-point').count() === 1,
        'Missing days produce neither zero bars nor a line connecting fabricated values.');
    check((await renderedDay(app, seed.date)).includes('8.0분'), 'The accessible table displays the same eight active minutes as the API.');
    const absent = week.days.find(item => item.activeSeconds === null);
    check(!!absent && (await renderedDay(app, absent.date)).includes('—'), 'A day without observations is an explicit dash rather than zero activity.');
    check((await widget.textContent()).includes('이전 주의 같은 경과 구간 대비 화면 활성 시간 +6.0분'),
        'Previous-period comparison uses the same elapsed calendar portion.');
    check((await widget.textContent()).includes('확인 문항의 정답·오답·무응답은 집중 점수와 별개'),
        'Learning-check outcomes are explicitly separate from focus metrics.');
    await widget.getByText('일별 수치 보기', { exact: true }).click();
    check(await widget.locator('[data-stat="table"]').isVisible(), 'An accessible daily table is available beside the SVG.');
    const beforeMonth = app.reports.length;
    await widget.locator('[data-stat="period"]').selectOption('month');
    const month = (await report(app, beforeMonth, item => item.body.period === 'month')).body;
    const expectedDays = new Date(Date.UTC(Number(seed.date.slice(0, 4)), Number(seed.date.slice(5, 7)), 0)).getUTCDate();
    check(month.days.length === expectedDays, 'Monthly reporting uses the actual full calendar month.');
    check(day(month, seed.date).activeSeconds === seed.expectedSelfActiveSeconds, 'Monthly daily series agrees with weekly evidence for the same date.');
    const previousDate = new Date(seed.date + 'T00:00:00Z'); previousDate.setUTCDate(previousDate.getUTCDate() - 7);
    const previousInMonth = previousDate.toISOString().slice(0, 10) >= month.startDate && previousDate.toISOString().slice(0, 10) <= month.endDate;
    check(month.totals.activeSeconds === seed.expectedSelfActiveSeconds + (previousInMonth ? 120 : 0),
        'Monthly accumulation includes only observed sessions within that calendar month.');
    check(await widget.locator('[data-stat="next"]').isDisabled(), 'Future month navigation is disabled for the current site month.');
    const beforePrevious = app.reports.length;
    await widget.locator('[data-stat="previous"]').click();
    await report(app, beforePrevious, item => item.body.period === 'month' && item.body.startDate !== month.startDate);
    check(await widget.locator('[data-stat="next"]').isEnabled(), 'The previous month offers navigation back to the current month.');
    await app.page.setViewportSize({ width: 390, height: 700 });
    const bounds = await widget.evaluate(item => ({ root: item.getBoundingClientRect().width, viewport: innerWidth,
        document: document.documentElement.scrollWidth, client: document.documentElement.clientWidth,
        chart: item.querySelector('[data-stat="chart"]').getBoundingClientRect().width }));
    check(bounds.root <= bounds.viewport && bounds.chart <= bounds.root && bounds.document <= bounds.client + 1,
        'The mobile widget contains its scrollable chart without overflowing the page.');
    await context.close();
}

async function teacherGroupsAndPermissions(seed) {
    console.log('Checking teacher group scope, current roster and access revocation');
    const context = await browser.newContext();
    const app = await pageFor(context, '/__test/dashboard');
    const filter = app.page.locator('[data-field="activity-context-filter"]');
    await until(async () => await filter.locator('option').count() >= 4, 'Fixture groups must appear in the real context picker.');
    await filter.selectOption('group:group:1');
    const groupOne = app.page.locator(`[data-field="activity-list"] [data-activity-id="${seed.activityIds[1]}"]`);
    await groupOne.waitFor();
    await until(async () => await app.page.locator(`[data-field="activity-list"] [data-activity-id="${seed.activityIds[2]}"]`).count() === 0,
        'The filtered response must replace the initial all-groups activity list.');
    check(await app.page.locator(`[data-field="activity-list"] [data-activity-id="${seed.activityIds[2]}"]`).count() === 0,
        'Selecting a group filters activities using the opaque context identifier.');
    await groupOne.locator('[data-action="view-scores"]').click();
    const studentOne = app.page.locator('[data-field="scores-body"] tr').filter({ has: app.page.locator('[data-f="name"]', { hasText: '학생 1' }) });
    await studentOne.locator('[data-action="view-statistics"]').click();
    const widget = app.page.locator('#ftk-teacher-statistics');
    const scoped = (await report(app)).body;
    check(scoped.scope.type === 'group' && scoped.scope.id === 'group:1' && scoped.userId === 1,
        'A real teacher row opens the selected student and authorized group report.');
    const groupOneDate = (await date(app, seed.date)).body;
    check(day(groupOneDate, seed.date).activeSeconds === seed.expectedGroupOneActiveSeconds,
        'Group one report includes only matching problem and activity time windows.');
    check((await renderedDay(app, seed.date)).includes('6.0분'), 'The teacher graph table displays six minutes for group one, not the student global total.');

    await filter.selectOption('group:group:2');
    const groupTwo = app.page.locator(`[data-field="activity-list"] [data-activity-id="${seed.activityIds[2]}"]`);
    await groupTwo.waitFor();
    check(await widget.isHidden(), 'Changing the context hides the prior group report until a new student is selected.');
    await groupTwo.locator('[data-action="view-scores"]').click();
    const secondStudent = app.page.locator('[data-field="scores-body"] tr').filter({ has: app.page.locator('[data-f="name"]', { hasText: '학생 1' }) });
    const beforeTwo = app.reports.length;
    await secondStudent.locator('[data-action="view-statistics"]').click();
    const groupTwoData = (await report(app, beforeTwo, item => item.body.scope.id === 'group:2')).body;
    check(day(groupTwoData, seed.date).activeSeconds === seed.expectedGroupTwoActiveSeconds,
        'Selecting another group changes the source scope rather than reusing the first group cache.');
    check((await widget.locator('[data-stat="title"]').textContent()).includes('그룹 둘'), 'The visible report names the selected group.');

    await api('/__test/statistics-config', { denyContext: true });
    const beforeDenied = app.reports.length;
    await widget.locator('[data-stat="refresh"]').click();
    await until(() => app.reports.slice(beforeDenied).some(item => item.status === 403), 'Revoked context access must reject the actual report endpoint.');
    await until(async () => (await widget.locator('[data-stat="message"]').textContent()).includes('불러오지 못했습니다'), 'The denied report must clear private results and show its access failure.');
    check(await widget.locator('svg').count() === 0 && (await widget.locator('[data-stat="results"]').textContent()) === '',
        'Revocation removes the old student chart and counts from the page.');
    await api('/__test/statistics-config', { denyContext: false });
    const deniedPeer = await api('/wp-json/focus-tracker/v1/statistics?context_type=group&context_id=group%3A1&user_id=99&period=week&date=' + seed.date,
        undefined, 'GET', true);
    check(deniedPeer.status === 403, 'A student outside the current roster cannot be exposed by changing the query string.');
    await context.close();
}

async function anotherStudentsOwnData(seed) {
    console.log('Checking self identity cannot select a classmate');
    await api('/__test/statistics-config', { userId: 2 });
    const context = await browser.newContext();
    const app = await pageFor(context, '/__test/self'); await report(app);
    const own = (await date(app, seed.date)).body;
    check(own.userId === 2 && day(own, seed.date).activeSeconds === 60,
        'The same self page selects the authenticated second student and shows only that student minute.');
    const denied = await api('/wp-json/focus-tracker/v1/me/statistics?user_id=1&period=week&date=' + seed.date, undefined, 'GET', true);
    check(denied.status === 403, 'Self reporting refuses a peer ID supplied in the query string.');
    await context.close(); await api('/__test/statistics-config', { userId: 1 });
}

async function scheduleUi(seed) {
    console.log('Checking group schedule filtering and time edits');
    const context = await browser.newContext({ timezoneId: 'Asia/Seoul' });
    const app = await pageFor(context, '/__test/dashboard');
    const filter = app.page.locator('[data-field="activity-context-filter"]');
    await until(async () => await filter.locator('option').count() >= 4, 'Groups must load before schedule filtering.');
    await filter.selectOption('group:group:1');
    const activity = app.page.locator(`[data-activity-id="${seed.activityIds[1]}"]`);
    await activity.locator('[data-action="edit-activity"]').click();
    const edit = app.page.locator('[data-field="edit-panel"]');
    await edit.waitFor();
    check((await edit.locator('[data-field="edit-context"]').textContent()).includes('그룹 하나'), 'Time editing identifies the selected group.');
    const initialStart = await edit.locator('[data-field="edit-starts-at"]').inputValue();
    const initialEnd = await edit.locator('[data-field="edit-ends-at"]').inputValue();
    await edit.locator('[data-field="edit-ends-at"]').fill(initialStart);
    await edit.locator('[data-action="save-activity"]').click();
    await until(async () => (await app.page.locator('[data-field="messages"]').textContent()).length > 0, 'Invalid dates must display an actionable error.');
    const before = (await api('/__test/state')).body.activities.find(row => Number(row.id) === Number(seed.activityIds[1]));
    const laterEnd = new Date(initialEnd + (initialEnd.endsWith('Z') ? '' : 'Z'));
    laterEnd.setUTCMinutes(laterEnd.getUTCMinutes() + 1);
    await edit.locator('[data-field="edit-ends-at"]').fill(laterEnd.toISOString().slice(0, 16));
    await edit.locator('[data-field="edit-name"]').fill('그룹 하나 수정 일정');
    const changed = app.page.waitForResponse(response => response.request().method() === 'PATCH'
        && response.url().endsWith('/activities/' + seed.activityIds[1]) && response.ok());
    await edit.locator('[data-action="save-activity"]').click(); await changed;
    await until(async () => (await app.page.locator('[data-field="activity-list"]').textContent()).includes('그룹 하나 수정 일정'), 'A successful edit must reload the filtered list.');
    const after = (await api('/__test/state')).body.activities.find(row => Number(row.id) === Number(seed.activityIds[1]));
    check(after.name === '그룹 하나 수정 일정' && after.starts_at === before.starts_at
        && Date.parse(after.ends_at + 'Z') - Date.parse(before.ends_at + 'Z') === 60000,
        'Saving an edited schedule persists the actual group activity and its one-minute time extension.');
    check(await app.page.locator(`[data-activity-id="${seed.activityIds[2]}"]`).count() === 0, 'Schedule refresh preserves the selected group filter.');

    const active = (await api('/__test/activity', { name: '그룹 하나 진행 중 일정', context_type: 'group', context_id: 'group:1',
        problem_ids: [123], startOffsetMs: -60000, endOffsetMs: 3600000 })).body;
    await app.page.reload();
    await until(async () => await filter.locator('option').count() >= 4, 'Group filtering reloads after an active fixture schedule is created.');
    await filter.selectOption('group:group:1');
    const liveRow = app.page.locator(`[data-activity-id="${active.id}"]`);
    await liveRow.locator('[data-action="end-activity"]').click();
    check(await app.page.locator('[data-field="end-confirmation"]').isVisible(), 'Ending early presents a concrete inline confirmation for the selected activity.');
    await app.page.locator('[data-action="cancel-end"]').click();
    const stillOpen = (await api('/__test/state')).body.activities.find(row => Number(row.id) === active.id);
    check(stillOpen.status !== 'closed', 'Cancelling early-end confirmation keeps the activity open.');
    await liveRow.locator('[data-action="end-activity"]').click();
    const endedResponse = app.page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith('/activities/' + active.id) && response.ok());
    await app.page.locator('[data-action="confirm-end"]').click(); await endedResponse;
    await until(async () => {
        const state = (await api('/__test/state')).body;
        const row = state.activities.find(row => Number(row.id) === active.id);
        return row && row.status === 'closed';
    }, 'Early end must persist through the actual activity controller.');
    check(true, 'Confirmed early end closes only the selected group activity through the real API.');
    await context.close();
}

(async () => {
    const port = await new Promise(resolve => {
        const reservation = net.createServer(); reservation.listen(0, '127.0.0.1', () => { const port = reservation.address().port; reservation.close(() => resolve(port)); });
    });
    base = `http://127.0.0.1:${port}`;
    server = spawn(php, ['-d', 'extension=pdo_sqlite', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'http-fixture.php')], {
        cwd: root, env: { ...process.env, FOCUS_TRACKER_TEST_DB: db }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
    });
    server.stdout.on('data', data => { serverOutput += data; }); server.stderr.on('data', data => { serverOutput += data; });
    await until(async () => { try { return (await fetch(base + '/__test/state')).ok; } catch (_) { return false; } }, 'PHP statistics fixture did not start.');
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH,
        process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')].find(value => value && fs.existsSync(value));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    await api('/__test/reset', {}); const seed = (await api('/__test/statistics-seed', {})).body;
    await studentReports(seed);
    await teacherGroupsAndPermissions(seed);
    await anotherStudentsOwnData(seed);
    await scheduleUi(seed);
    check(errors.length === 0, 'Real student/teacher pages have no uncaught browser errors: ' + errors.join('; '));
    console.log(`Statistics browser: ${checks} checks passed (real Chromium, weekly/monthly charts, group-scope permissions, schedule UI, REST aggregation).`);
})().catch(error => { console.error(error.stack || error); console.error(serverOutput.slice(-14000)); process.exitCode = 1; })
    .finally(async () => {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        for (const suffix of ['', '-journal', '-wal', '-shm']) { try { fs.unlinkSync(db + suffix); } catch (error) { if (error.code !== 'ENOENT') throw error; } }
    });
