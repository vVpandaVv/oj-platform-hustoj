'use strict';

// Native disposable WordPress + actual installed add-ons + MySQL/MariaDB.
// Full recording flow uses no fixture/bootstrap, response interception, capability
// changes or clock overrides. Screenshot mode has one labeled display-only GET replay.
const assert = require('node:assert/strict'); const fs = require('node:fs'); const path = require('node:path');
function playwright() {
    for (const name of [process.env.PLAYWRIGHT_MODULE_PATH, 'playwright', process.env.USERPROFILE && path.join(process.env.USERPROFILE,
        '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')].filter(Boolean)) {
        try { return require(name); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; }
    }
    throw new Error('Playwright is required.');
}
const manifestPath = process.env.FOCUS_REAL_CATEGORY_CONFIG;
assert.ok(manifestPath, 'Set FOCUS_REAL_CATEGORY_CONFIG to the private native account/resource manifest.');
const config = JSON.parse(fs.readFileSync(path.resolve(manifestPath), 'utf8'));
assert.ok(config.baseUrl && config.teacher && config.students?.length && config.group?.id && config.material?.url && config.gameUrl && config.mlUrl, 'The native manifest is incomplete.');
const base = config.baseUrl.replace(/\/$/, '');
const artifacts = path.resolve(process.env.FOCUS_REAL_ARTIFACTS || path.join(path.dirname(path.resolve(manifestPath)), 'native-page-categories'));
fs.mkdirSync(artifacts, { recursive: true });
const report = { startedAt: new Date().toISOString(), baseUrl: base, targetVersion: process.env.FOCUS_REAL_VERSION || null,
    checks: [], passed: false, activity: null, gateEvidence: [], iframeVerified: false };
const contexts = []; const errors = []; const requests = []; const gates = []; let browser; let teacher; let student;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function check(value, message) { assert.ok(value, message); report.checks.push(message); }
async function until(fn, message, timeout = 30000) { const end = Date.now() + timeout;
    do { const value = await fn(); if (value) return value; await delay(80); } while (Date.now() < end); throw new Error(message); }
function endpoint(value) { const parsed = new URL(value); return parsed.searchParams.get('rest_route') || parsed.pathname.replace(/^.*\/wp-json/, ''); }
function restUrl(actor, suffix) {
    const parsed = new URL(actor.config.restUrl); const [route, query] = suffix.split('?');
    if (parsed.searchParams.has('rest_route')) parsed.searchParams.set('rest_route', parsed.searchParams.get('rest_route').replace(/\/events$/, '').replace(/\/$/, '') + route);
    else parsed.pathname = parsed.pathname.replace(/\/events$/, '').replace(/\/$/, '') + route;
    for (const [key, value] of new URLSearchParams(query || '')) parsed.searchParams.set(key, value);
    return parsed.href;
}
async function api(actor, suffix) {
    return actor.page.evaluate(async input => {
        const response = await fetch(input.url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': input.nonce } });
        let data; try { data = await response.json(); } catch (_) { data = {}; }
        return { status: response.status, data };
    }, { url: restUrl(actor, suffix), nonce: actor.config.nonce });
}
async function login(account, description) {
    const context = await browser.newContext({ timezoneId: 'Asia/Seoul' }); contexts.push(context);
    const page = await context.newPage(); page.setDefaultNavigationTimeout(60000); page.on('pageerror', error => errors.push(error.message));
    console.log(description + ': actual platform login'); await page.goto(base + '/login');
    const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="oj_login"]') });
    await form.locator('[name="user_login"]').fill(account.username); await form.locator('[name="password"]').fill(account.password);
    await form.getByRole('button', { name: '로그인', exact: true }).click();
    await until(async () => (await context.cookies(base)).some(cookie => cookie.name.startsWith('wordpress_logged_in_')), description + ' needs a real WordPress login cookie.');
    await page.waitForLoadState('domcontentloaded');
    check(!(await page.locator('input[name="action"][value="oj_login"]').count()), description + ' passes native cookie login.');
    return { account, context, page };
}
async function loadPage(route, expected, subtype = null) {
    const target = new URL(route, base); assert.equal(target.origin, new URL(base).origin, 'Only the disposable native site is used.');
    target.searchParams.set('group_id', String(config.group.id));
    await student.page.goto(target.href);
    student.config = await student.page.locator('#focus-tracker-config').evaluate(element => JSON.parse(element.textContent));
    check(student.config.problemId === null && student.config.page.category === expected && (student.config.page.subtype || null) === subtype,
        'The native ' + expected + (subtype ? '/' + subtype : '') + ' resource receives the correct signed descriptor.');
    check(student.config.nonce !== 'focus-test-nonce' && typeof student.config.page.token === 'string', 'The descriptor and REST nonce are issued by the actual WordPress page.');
}
async function gate(active) {
    await student.page.waitForFunction(value => window.FocusTrackerActivityGate?.state().active === value, active, { timeout: 60000 });
}
async function createActivity() {
    await teacher.page.goto(base + '/oj-console/addons/focus-tracker');
    teacher.config = await teacher.page.locator('[data-ftk-dashboard]').evaluate(element => JSON.parse(element.getAttribute('data-config')));
    const form = teacher.page.locator('[data-field="create-form"]'); const contextId = config.group.contextId || 'group:' + config.group.id;
    await until(async () => await form.locator('[data-field="context"] option').count() > 1, 'The native teacher must see the actual group.');
    await form.locator('[data-field="context"]').selectOption('group:' + contextId);
    await form.locator('[data-field="name"]').fill('실제 페이지 범주 검증 ' + Date.now());
    const times = await teacher.page.evaluate(() => {
        const now = Date.now(); const local = ms => { const d = new Date(ms); const p = value => String(value).padStart(2, '0');
            return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); };
        return { start: local(now + 30000), end: local(now + 120000) };
    });
    await form.locator('[data-field="starts-at"]').fill(times.start); await form.locator('[data-field="ends-at"]').fill(times.end);
    if (config.problem?.id) await form.locator('[data-field="problem-ids"]').fill(String(config.problem.id));
    const pending = teacher.page.waitForResponse(response => response.request().method() === 'POST' && endpoint(response.url()) === '/focus-tracker/v1/activities');
    await form.getByRole('button', { name: '활동 만들기', exact: true }).click(); const response = await pending;
    check(response.ok(), 'The actual group-manager teacher creates the scheduled activity through the production UI.');
    report.activity = await response.json();
    check(Date.parse(report.activity.endsAt) - Date.parse(report.activity.startsAt) === 90000, 'The activity uses ninety real seconds without a clock override.');
    console.log('Native group activity scheduled; pages remain open before its real start.');
}
async function run() {
    teacher = await login(config.teacher, 'Group manager'); student = await login(config.students[0], 'Learner');
    student.page.on('request', request => { if (request.method() === 'POST' && /\/(events|checks\/next)$/.test(endpoint(request.url()))) requests.push({ endpoint: endpoint(request.url()), body: request.postDataJSON() }); });
    student.page.on('response', async response => { if (!endpoint(response.url()).endsWith('/tracking/state')) return;
        try { const data = await response.json(); gates.push({ status: response.status(), reason: data.reason, code: data.code, active: data.active, elapsedMs: response.request().timing().responseEnd }); } catch (_) { /* Failure retained by UI checks. */ } });
    await loadPage(config.material.url, 'material'); await until(() => gates.some(row => row.status === 200 && !row.active), 'An actual inactive server lease is required.');
    await delay(1000); check(requests.length === 0, 'Before any schedule the actual material page sends neither events nor drafts.');
    await createActivity(); check(Date.now() < Date.parse(report.activity.startsAt) && requests.length === 0, 'The already-open native material page remains idle before the future start.');
    await gate(true); await delay(6000);
    await loadPage(config.mlUrl, 'practice', 'ml'); await gate(true);
    const frames = student.page.locator('iframe:visible');
    if (await frames.count()) {
        await frames.first().click({ position: { x: 8, y: 8 } });
        check(await student.page.evaluate(() => document.activeElement?.tagName === 'IFRAME' && document.hasFocus()), 'Actual embedded-tool focus remains inside the native learning document.');
        report.iframeVerified = true;
    }
    await delay(22000);
    await loadPage(config.gameUrl, 'game'); await gate(true); await delay(6000);
    await loadPage(config.material.url, 'material'); await gate(true);
    await gate(false); await delay(2500);
    check(await student.page.locator('[data-ftk-gate-label]').textContent() === '활동 종료', 'The actual learner is automatically stopped at the group schedule end.');
    const baseline = await api(teacher, '/statistics?context_type=group&context_id=' + encodeURIComponent(report.activity.contextId) + '&user_id=' + student.account.userId + '&period=week');
    check(baseline.status === 200, 'The non-admin group teacher reads the student report with a real cookie and nonce.');
    await loadPage(config.gameUrl, 'game'); await gate(false); await delay(1500);
    const after = await api(teacher, '/statistics?context_type=group&context_id=' + encodeURIComponent(report.activity.contextId) + '&user_id=' + student.account.userId + '&period=week');
    check(after.status === 200 && after.data.totals.observedSeconds === baseline.data.totals.observedSeconds, 'Native category navigation after the end cannot add observed learning time.');
    const timeline = await api(teacher, '/activities/' + report.activity.id + '/participants/' + student.account.userId + '/timeline');
    check(timeline.status === 200 && ['material', 'practice', 'game'].every(name => timeline.data.categorySpans.some(span => span.category === name)), 'The native stored timeline includes the actual material, ML and game categories.');
    const scores = await api(teacher, '/activities/' + report.activity.id + '/scores'); const row = scores.data.rows.find(value => Number(value.userId) === Number(student.account.userId));
    check(scores.status === 200 && row.activityEligible === false && row.activityWindowRatio === null && row.scoreActivity === null, 'Actual viewing/tool pages remain unassessed for unsupported editing activity, not zero-graded.');
    for (const period of ['week', 'month']) {
        const value = await api(student, '/me/statistics?period=' + period); check(value.status === 200, 'The learner reads the authorized native ' + period + ' self report.');
        const bins = value.data.pageCategories; const find = (category, subtype = null) => bins.find(bin => bin.category === category && (bin.subtype || null) === subtype);
        check(find('material').observedSeconds > 0 && find('practice', 'ml').observedSeconds > 0 && find('game').observedSeconds > 0, period + ' category bins include the actual native pages.');
        report[period] = { totals: value.data.totals, pageCategories: bins };
    }
    check(requests.every(request => !request.endpoint.endsWith('/checks/next')), 'No actual non-problem page requests a code question or draft analysis.');
    const events = requests.filter(request => request.body?.events).flatMap(request => request.body.events);
    check(events.length > 0 && events.every(event => Number(event.occurred_at) >= Date.parse(report.activity.startsAt) && Number(event.occurred_at) <= Date.parse(report.activity.endsAt)), 'All readable native event payloads remain inside the actual schedule.');
    await student.page.goto(base + '/oj-addons/focus-tracker/me'); await student.page.locator('[data-stat="category-chart"] svg').waitFor();
    check(await student.page.locator('[data-stat-category="practice:ml"]').count() === 1, 'The actual student self-view renders a separate ML practice row.');
    await teacher.page.locator(`[data-activity-id="${report.activity.id}"] [data-action="view-scores"]`).click();
    const learner = teacher.page.locator('[data-field="scores-body"] tr').filter({ hasText: row.displayName }).first();
    await learner.locator('[data-action="view-timeline"]').click(); await teacher.page.locator('[data-category-transitions]').waitFor({ state: 'attached' });
    check(await teacher.page.locator('[data-timeline-category="practice:ml"]').count() > 0, 'The actual group teacher timeline displays ML category transitions.');
    check(gates.every(value => value.status === 200), 'Every automatic native category authorization request succeeds.');
    check(errors.length === 0, 'No uncaught native page JavaScript errors: ' + errors.join('; '));
    report.passed = true; console.log(`Native page categories: ${report.checks.length} checks passed (actual WP cookies, installed add-ons and MySQL).`);
}
async function captureScreenshots() {
    const previous = JSON.parse(fs.readFileSync(path.resolve(process.env.FOCUS_REAL_CATEGORY_CAPTURE), 'utf8'));
    assert.ok(previous.passed && previous.activity?.id && previous.baseUrl === base, 'Screenshots require a successful native run at this site.');
    report.mode = 'read-only-screenshots'; report.activity = previous.activity; report.screenshots = [];
    teacher = await login(config.teacher, 'Group manager'); student = await login(config.students[0], 'Learner');
    await teacher.page.goto(base + '/oj-console/addons/focus-tracker');
    teacher.config = await teacher.page.locator('[data-ftk-dashboard]').evaluate(element => JSON.parse(element.getAttribute('data-config')));
    const scores = await api(teacher, '/activities/' + report.activity.id + '/scores');
    check(scores.status === 200, 'The actual teacher can reopen the stored activity without creating another schedule.');
    const person = scores.data.rows.find(row => Number(row.userId) === Number(student.account.userId));
    await teacher.page.locator(`[data-activity-id="${report.activity.id}"] [data-action="view-scores"]`).click();
    const row = teacher.page.locator('[data-field="scores-body"] tr').filter({ hasText: person.displayName }).first();
    await row.locator('[data-action="view-timeline"]').click();
    await teacher.page.locator('[data-category-transitions]').waitFor({ state: 'attached' });
    await teacher.page.locator('.ftk-category-transitions summary').click();
    await teacher.page.locator('.ftk-page-visits summary').click();
    const resourceKeys = await teacher.page.locator('[data-timeline-page]').evaluateAll(rows => rows.map(row => row.cells[1].textContent));
    check(new Set(resourceKeys).size >= 3, 'The actual native page-history table displays at least three distinct internal resources.');
    check((await teacher.page.locator('.ftk-page-visits').textContent()).includes('직접 합산하면 중복'), 'Native tab history explicitly warns against summing overlaps.');
    var screenshotChrome = 'header.ojp-public-header, header.ojp-console-header, a.ojp-skip-link';
    report.screenshotPresentation = { selector: screenshotChrome, css: 'visibility: hidden', reason: 'Hide global navigation and its skip link only while capturing a scrolled element; product CSS is unchanged.', pages: [] };
    async function hideScreenshotNavigation(page, name) {
        if (await page.locator(screenshotChrome).count()) {
            await page.addStyleTag({ content: screenshotChrome + ' { visibility: hidden; }' });
            report.screenshotPresentation.pages.push(name);
        }
    }
    await hideScreenshotNavigation(teacher.page, 'teacher');
    const timelinePath = path.join(artifacts, 'teacher-category-timeline.png');
    await teacher.page.locator('[data-field="timeline-panel"]').screenshot({ path: timelinePath }); report.screenshots.push(timelinePath);
    await student.page.goto(base + '/oj-addons/focus-tracker/me'); const widget = student.page.locator('[data-ftk-statistics]');
    await widget.locator('[data-stat="category-chart"] svg').waitFor();
    await hideScreenshotNavigation(student.page, 'student');
    const weekPath = path.join(artifacts, 'student-week-categories.png'); await widget.screenshot({ path: weekPath }); report.screenshots.push(weekPath);
    await widget.locator('[data-stat="period"]').selectOption('month');
    await until(async () => await widget.getAttribute('aria-busy') === null, 'The actual monthly widget must finish rendering.');
    const monthPath = path.join(artifacts, 'student-month-categories.png'); await widget.screenshot({ path: monthPath }); report.screenshots.push(monthPath);
    const replayPath = '**/participants/' + student.account.userId + '/timeline';
    await teacher.page.route(replayPath, async route => {
        const response = await route.fetch(); const data = await response.json();
        await route.fulfill({ response, json: { ...data, pageRecordsTruncated: true } });
    });
    await row.locator('[data-action="view-timeline"]').click();
    await teacher.page.locator('[data-page-records-truncated]').waitFor({ state: 'attached' });
    check((await teacher.page.locator('[data-page-records-truncated]').textContent()).includes('최대 500개'), 'Display-only GET replay verifies the native renderer truncation notice; backend records are unchanged.');
    check(report.screenshots.length === 3, 'Actual teacher timeline and student weekly/monthly screenshot evidence is saved.');
    check(errors.length === 0, 'Read-only screenshot pages have no uncaught errors.');
    report.passed = true; console.log('Native category screenshots: 3 actual UI images saved without creating any activity.');
}
(async () => {
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH,
        process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')].find(value => value && fs.existsSync(value));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    if (process.env.FOCUS_REAL_CATEGORY_CAPTURE) await captureScreenshots(); else await run();
})().catch(async error => { report.failure = error.message; console.error(error.stack || error);
    for (const [index, actor] of [teacher, student].filter(Boolean).entries()) try { await actor.page.screenshot({ path: path.join(artifacts, 'failure-' + index + '.png'), fullPage: true }); } catch (_) { /* Keep primary failure. */ }
    process.exitCode = 1;
}).finally(async () => {
    report.finishedAt = new Date().toISOString(); report.gateEvidence = gates;
    fs.writeFileSync(path.join(artifacts, 'browser-result.json'), JSON.stringify(report, null, 2) + '\n', 'utf8');
    for (const context of contexts) await context.close(); if (browser) await browser.close();
});
