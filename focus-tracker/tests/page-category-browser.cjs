'use strict';

// Real Chromium navigation/frame focus and production signing, gate, ingest,
// scoring and report APIs. WordPress/auth/roster use the existing SQLite fixture;
// only the material/game/frame HTML bodies are benign fixtures, not full add-ons.
// Recording/report checks never replace API success, focus or visibility. The
// final labeled UI-only GET replay checks a truncation flag and hostile key text.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
function playwright() {
    for (const name of [process.env.PLAYWRIGHT_MODULE_PATH, 'playwright', process.env.USERPROFILE && path.join(process.env.USERPROFILE,
        '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')].filter(Boolean)) {
        try { return require(name); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; }
    }
    throw new Error('Playwright is required.');
}
const root = path.resolve(__dirname, '../..');
const temporary = path.join(root, '_notes/tmp/focus-tracker-tests'); fs.mkdirSync(temporary, { recursive: true });
const db = path.join(temporary, `page-category-browser-${process.pid}-${Date.now()}.sqlite`);
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:/php/php.exe' : 'php');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let server; let browser; let base; let output = ''; let checks = 0; let lastPage;
const errors = []; const requests = []; const gates = [];
function check(value, message) { assert.ok(value, message); checks += 1; }
async function until(fn, message, timeout = 15000) {
    const deadline = Date.now() + timeout;
    do { const value = await fn(); if (value) return value; await delay(60); } while (Date.now() < deadline);
    throw new Error(message);
}
async function api(url, body, method = 'POST') {
    const response = await fetch(base + url, body === undefined ? {} : { method,
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': 'focus-test-nonce' }, body: JSON.stringify(body) });
    const text = await response.text(); assert.ok(response.ok, `${url}: ${response.status} ${text.slice(0, 1200)}`);
    return JSON.parse(text);
}
const state = () => api('/__test/state');
const category = (report, name, subtype = null) => report.pageCategories.find(row => row.category === name && (row.subtype || null) === subtype);
async function config(page) { return page.locator('#focus-tracker-config').evaluate(element => JSON.parse(element.textContent)); }
async function gate(page, active) {
    await page.waitForFunction(value => window.FocusTrackerActivityGate?.state().active === value, active, { timeout: 60000 });
}
function watch(page) {
    lastPage = page;
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
        if (request.method() === 'POST' && /\/(events|checks\/next)(?:\?|$)/.test(request.url())) requests.push({ url: request.url(), body: request.postDataJSON() });
    });
    page.on('response', async response => {
        if (!response.url().includes('/tracking/state')) return;
        try { gates.push({ status: response.status(), body: await response.json() }); } catch (_) { /* Keep the primary browser assertion. */ }
    });
}
async function navigationAndFrame() {
    console.log('Checking signed category navigation and native iframe focus');
    await api('/__test/reset', {});
    await api('/__test/check-config', { reset: true, scenario: 'unavailable', gatePollMs: 500, userId: 1 });
    await api('/__test/statistics-config', { userId: 1, denyContext: false });
    const context = await browser.newContext(); const page = await context.newPage(); watch(page);
    await page.goto(base + '/oj-addons/learning-materials/1?class_id=1');
    const material = await config(page);
    check(material.problemId === null && material.page.category === 'material' && typeof material.page.token === 'string', 'Material pages receive a server-signed page descriptor without a problem ID.');
    await until(() => gates.some(row => row.status === 200 && row.body.active === false), 'No schedule must be confirmed.');
    await page.getByRole('heading', { level: 1 }).click(); await delay(700);
    check((await state()).events.length === 0 && requests.length === 0, 'Outside a group/class schedule, material visits and clicks send no learning event or draft.');
    const lesson = await api('/__test/activity', { name: '페이지 이동 수업', context_type: 'class', context_id: 'class:1', problem_ids: [123],
        weight_focus: 0, weight_selfreg: 0, weight_activity: 6, startOffsetMs: 2000, endOffsetMs: 45000 });
    await gate(page, true);
    await until(async () => (await state()).participations.some(row => row.page_category === 'material'), 'Material collection must begin automatically under the real gate.');
    await delay(1800);
    await page.getByRole('link', { name: 'ML 실습', exact: true }).click(); await gate(page, true);
    const ml = await config(page);
    check(ml.page.category === 'practice' && ml.page.subtype === 'ml' && ml.problemId === null, 'The actual edu-games AI lab route is classified as ML practice before the game fallback.');
    check(ml.page.token !== material.page.token, 'Page navigation changes the signed resource descriptor.');
    await page.frameLocator('#category-learning-frame').locator('#frame-action').click();
    check(await page.evaluate(() => document.activeElement?.id === 'category-learning-frame' && document.hasFocus()), 'Clicking inside the real iframe leaves focus within the learning page.');
    await delay(22000);
    let snapshot = await state(); const mlIds = snapshot.participations.filter(row => row.page_category === 'practice').map(row => Number(row.id));
    const frameHeartbeats = snapshot.events.filter(row => mlIds.includes(Number(row.participation_id)) && row.event_type === 'heartbeat');
    check(frameHeartbeats.length > 0 && frameHeartbeats.every(row => JSON.parse(row.meta).focused === true), 'Persisted heartbeats retain focus inside an embedded ML tool.');
    check(!snapshot.events.some(row => mlIds.includes(Number(row.participation_id)) && row.event_type === 'activity'), 'Frame focus and ordinary tool clicks do not fabricate scored learning actions.');
    await page.getByRole('link', { name: '교육게임', exact: true }).click(); await gate(page, true); await delay(1800);
    check((await config(page)).page.category === 'game', 'The real binary-conversion route remains a game.');
    await page.getByRole('link', { name: '질문과 토론', exact: true }).click(); await gate(page, true); await delay(1800);
    check((await config(page)).page.category === 'discussion', 'The board thread route receives a discussion descriptor.');
    await gate(page, false); await delay(1000); snapshot = await state();
    const expected = ['material', 'practice', 'game', 'discussion'];
    check(expected.every(name => snapshot.participations.some(row => row.page_category === name && Number(row.page_context_version) === 1)), 'Navigation persists all four canonical signed categories with separate page sessions.');
    check(snapshot.participations.every(row => row.problem_id === null), 'Non-problem pages remain independent of the scheduled problem filter.');
    check(snapshot.events.every(row => Number(row.occurred_at_ms) >= Date.parse(lesson.startsAt) && Number(row.occurred_at_ms) <= Date.parse(lesson.endsAt)), 'Every persisted page event remains inside the authorized schedule boundary.');
    check(snapshot.checks.length === 0 && requests.every(row => !row.url.includes('/checks/next')), 'Non-problem pages never send draft requests or issue code questions.');
    const count = snapshot.events.length; const sessions = snapshot.participations.length;
    await page.getByRole('link', { name: '학습자료', exact: true }).click(); await gate(page, false); await delay(1000);
    const stopped = await state(); check(stopped.events.length === count && stopped.participations.length === sessions, 'Category navigation after the end cannot start another collection session.');
    await context.close(); return lesson;
}
async function reports(lesson) {
    console.log('Checking real weekly/monthly reports and student charts');
    const weekly = await api('/wp-json/focus-tracker/v1/me/statistics?period=week');
    const monthly = await api('/wp-json/focus-tracker/v1/me/statistics?period=month');
    for (const report of [weekly, monthly]) {
        check(category(report, 'material').observedSeconds > 0 && category(report, 'practice', 'ml').activeSeconds > 0
            && category(report, 'game').observedSeconds > 0 && category(report, 'discussion').observedSeconds > 0,
        `${report.period} reports preserve the actual material/ML/game/discussion visits.`);
        const sum = key => report.pageCategories.reduce((total, row) => total + (Number(row[key]) || 0), 0);
        check(Math.abs(sum('activeSeconds') - report.totals.activeSeconds) < 0.01 && Math.abs(sum('observedSeconds') - report.totals.observedSeconds) < 0.01,
            `${report.period} category times partition the overall union instead of double-counting tabs.`);
    }
    const scores = await api('/wp-json/focus-tracker/v1/activities/' + lesson.id + '/scores');
    const score = scores.rows.find(row => Number(row.userId) === 1);
    check(score.activityEligible === false && score.activityWindowRatio === null && score.scoreActivity === null, 'Viewing materials and embedded tools does not become a zero activity score when their semantic actions are unsupported.');
    check(score.scoreEligible === false && score.scoreTotal === null, 'If only unsupported editing carries weight, the total remains unassessed rather than zero.');
    const timeline = await api('/wp-json/focus-tracker/v1/activities/' + lesson.id + '/participants/1/timeline');
    check(['material', 'practice', 'game', 'discussion'].every(name => timeline.categorySpans.some(row => row.category === name)), 'The actual timeline exposes category transitions from persisted evidence.');
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } }); const page = await context.newPage(); watch(page);
    await page.goto(base + '/__test/self');
    const widget = page.locator('[data-ftk-statistics]'); await widget.locator('[data-stat="category-chart"] svg').waitFor();
    check(await widget.locator('[data-stat-category="practice:ml"]').count() === 1 && (await widget.textContent()).includes('ML 실습'), 'The student weekly chart and accessible category table label ML as practice.');
    check((await page.locator('[data-field="summary"]').textContent()).includes('미평가'), 'Pure viewing/tool sessions display activity as unassessed rather than zero percent.');
    check(await page.locator('[data-field="summary"] [data-f="total"]').textContent() === '미평가', 'The student total preserves an explicit unassessed score.');
    await widget.locator('[data-stat="period"]').selectOption('month');
    await until(async () => (await widget.locator('[data-stat="message"]').textContent()).includes(monthly.startDate), 'The monthly category report must render.');
    check((await widget.textContent()).includes('페이지 범주를 옮겼다는 이유로 감점'), 'The monthly widget explains that category changes are not focus penalties.');
    check(await widget.evaluate(element => element.getBoundingClientRect().right <= innerWidth + 1), 'The narrow-screen category widget stays inside the viewport with internal chart/table scrolling.');
    await context.close();
    console.log('Checking teacher category timeline');
    const teacher = await browser.newContext(); const consolePage = await teacher.newPage(); watch(consolePage);
    await consolePage.goto(base + '/__test/dashboard');
    const row = consolePage.locator(`[data-activity-id="${lesson.id}"]`); await row.locator('[data-action="view-scores"]').click();
    const learner = consolePage.locator('[data-field="scores-body"] tr').filter({ has: consolePage.locator('[data-action="view-timeline"]') }).first();
    await learner.locator('[data-action="view-timeline"]').click();
    await consolePage.locator('[data-category-transitions]').waitFor({ state: 'attached' });
    check(await consolePage.locator('[data-timeline-category="material"]').count() > 0 && await consolePage.locator('[data-timeline-category="practice:ml"]').count() > 0,
        'The teacher timeline provides an accessible category transition list alongside the activation bar.');
    await consolePage.locator('.ftk-page-visits summary').click();
    check(await consolePage.locator('[data-timeline-page]').count() >= 4 && (await consolePage.locator('[data-page-visits]').textContent()).includes(timeline.pages[0].pageKey),
        'The actual teacher page history renders canonical resource keys and separate tab/page lifetimes.');
    check((await consolePage.locator('.ftk-page-visits').textContent()).includes('직접 합산하면 중복'), 'Individual tab lifetimes explicitly warn against summing overlapping records.');
    check((await learner.textContent()).includes('미평가'), 'Teacher results preserve the unassessed activity distinction.');
    check(await learner.locator('td').nth(5).textContent() === '미평가', 'The teacher total never coerces an unassessed null into zero.');
    console.log('Checking display-only page-history truncation and hostile text contract');
    const hostileKey = '<img src=x onerror=window.__pageRecordXss=true>';
    await consolePage.route('**/participants/1/timeline', async route => {
        const response = await route.fetch(); const data = await response.json();
        await route.fulfill({ response, json: { ...data, pageRecordsTruncated: true, pages: [{ ...data.pages[0], pageKey: hostileKey }, ...data.pages.slice(1)] } });
    });
    await learner.locator('[data-action="view-timeline"]').click();
    await consolePage.locator('[data-page-records-truncated]').waitFor({ state: 'attached' });
    check((await consolePage.locator('[data-page-records-truncated]').textContent()).includes('최대 500개'), 'UI-only replay labels a server-truncated history instead of claiming all visits are shown.');
    check((await consolePage.locator('[data-page-visits]').textContent()).includes(hostileKey)
        && await consolePage.locator('[data-page-visits] img').count() === 0 && !(await consolePage.evaluate(() => window.__pageRecordXss)),
    'UI-only hostile-key replay is rendered as text and cannot execute markup.');
    await teacher.close();
}
(async () => {
    const port = await new Promise(resolve => { const socket = net.createServer(); socket.listen(0, '127.0.0.1', () => { const port = socket.address().port; socket.close(() => resolve(port)); }); });
    base = `http://127.0.0.1:${port}`;
    server = spawn(php, ['-d', 'extension=pdo_sqlite', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'http-fixture.php')], {
        cwd: root, env: { ...process.env, FOCUS_TRACKER_TEST_DB: db }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
    });
    server.stdout.on('data', chunk => { output += chunk; }); server.stderr.on('data', chunk => { output += chunk; });
    await until(async () => { try { return (await fetch(base + '/__test/state')).ok; } catch (_) { return false; } }, 'PHP fixture did not start.');
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH,
        process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')].find(name => name && fs.existsSync(name));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    await reports(await navigationAndFrame());
    check(errors.length === 0, 'No uncaught browser errors: ' + errors.join('; '));
    check(gates.every(row => row.status === 200), 'Every automatic page authorization response succeeds.');
    console.log(`Page categories browser: ${checks} checks passed (real navigation/iframe focus, signed gate/ingest, weekly/monthly reports and neutral scores).`);
})().catch(async error => {
    console.error(error.stack || error); console.error('Browser errors:', errors);
    if (lastPage && !lastPage.isClosed()) {
        try {
            console.error('Last UI:', (await lastPage.locator('body').textContent()).slice(-10000));
            await lastPage.screenshot({ path: path.join(temporary, 'page-category-failure.png'), fullPage: true });
        } catch (_) { /* Preserve the original failure. */ }
    }
    console.error(output.slice(-4000)); process.exitCode = 1;
})
    .finally(async () => {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        for (const suffix of ['', '-journal', '-wal', '-shm']) { try { fs.unlinkSync(db + suffix); } catch (error) { if (error.code !== 'ENOENT') throw error; } }
    });
