'use strict';

// Actual installed WordPress, native login cookies and wp_rest nonces only.
// This runner neither starts a fixture server nor intercepts API responses,
// changes capabilities, overrides time, or writes directly to any database.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

function playwright() {
    const choices = [process.env.PLAYWRIGHT_MODULE_PATH, 'playwright', process.env.USERPROFILE && path.join(process.env.USERPROFILE, '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')];
    for (const item of choices.filter(Boolean)) { try { return require(item); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; } }
    throw new Error('Playwright is required.');
}
const configPath = process.env.FOCUS_REAL_CONFIG;
assert.ok(configPath, 'Set FOCUS_REAL_CONFIG to the temporary native-site account manifest.');
const config = JSON.parse(fs.readFileSync(path.resolve(configPath), 'utf8'));
assert.ok(config.baseUrl && config.teacher && Array.isArray(config.students) && config.students.length >= 2 && Number(config.group?.id) > 0 && Number(config.problem?.id) > 0,
    'Native-site manifest must include URL, teacher, two students, group and problem IDs.');
const base = config.baseUrl.replace(/\/$/, '');
const artifacts = path.resolve(process.env.FOCUS_REAL_ARTIFACTS || config.artifactsDir || path.join(path.dirname(path.resolve(configPath)), 'browser-artifacts'));
fs.mkdirSync(artifacts, { recursive: true });
const previousReport = process.env.FOCUS_REAL_CONTINUE_REPORT ? JSON.parse(fs.readFileSync(path.resolve(process.env.FOCUS_REAL_CONTINUE_REPORT), 'utf8')) : null;
const report = { startedAt: new Date().toISOString(), baseUrl: base, targetVersion: process.env.FOCUS_REAL_VERSION || null,
    mode: previousReport ? 'persisted-follow-up' : 'full-scheduled-browser',
    transport: 'native WordPress cookie authentication', checks: [], students: [], activity: null, passed: false };
const errors = [];
const contexts = [];
let browser; let teacher; let students = []; let deniedStudent;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function check(value, message) { assert.ok(value, message); report.checks.push(message); }
function url(route) { return base + route; }
function restPath(value) {
    const parsed = new URL(value);
    return (parsed.searchParams.get('rest_route') || parsed.pathname.replace(/^.*\/wp-json/, '')).replace(/\/$/, '');
}
function isApi(value, suffix) { return restPath(value) === '/focus-tracker/v1/' + suffix; }
function summaryUrl(student) {
    const endpoint = new URL(student.config.checks.nextUrl);
    if (endpoint.searchParams.has('rest_route')) endpoint.searchParams.set('rest_route', endpoint.searchParams.get('rest_route').replace(/\/next$/, '/summary'));
    else endpoint.pathname = endpoint.pathname.replace(/\/next$/, '/summary');
    return endpoint.href;
}
async function until(fn, message, timeout = 20000) {
    const end = Date.now() + timeout;
    do { const result = await fn(); if (result) return result; await delay(80); } while (Date.now() < end);
    throw new Error(message);
}
function watch(page) { page.on('pageerror', error => errors.push(error.message)); }
async function login(account, description) {
    assert.ok(account.username && account.password && Number(account.userId) > 0, description + ' credentials are incomplete.');
    const context = await browser.newContext({ timezoneId: 'Asia/Seoul' }); contexts.push(context);
    const page = await context.newPage(); watch(page); page.setDefaultNavigationTimeout(60000);
    console.log(description + ': opening the native login page.');
    await page.goto(url('/login'));
    const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="oj_login"]') });
    await form.locator('input[name="user_login"]').fill(account.username);
    await form.locator('input[name="password"]').fill(account.password);
    await form.getByRole('button', { name: '로그인', exact: true }).click();
    await until(async () => (await context.cookies(base)).some(cookie => cookie.name.startsWith('wordpress_logged_in_')), description + ' did not receive a native WordPress login cookie.');
    await page.waitForLoadState('domcontentloaded');
    check(!(await page.locator('input[name="action"][value="oj_login"]').count()), description + ' passes the actual platform login action.');
    console.log(description + ': native login cookie received.');
    return { account, context, page };
}
async function browserRest(actor, target, method = 'GET', body, nonce) {
    return actor.page.evaluate(async input => {
        const response = await fetch(input.target, { method: input.method, credentials: 'same-origin', headers: {
            'X-WP-Nonce': input.nonce, ...(input.body === undefined ? {} : { 'Content-Type': 'application/json' }),
        }, body: input.body === undefined ? undefined : JSON.stringify(input.body) });
        let data; try { data = await response.json(); } catch (_) { data = { code: 'non_json_response' }; }
        return { status: response.status, data };
    }, { target, method, body, nonce: nonce === undefined ? actor.config.nonce : nonce });
}
async function teacherDashboard() {
    await teacher.page.goto(url('/oj-console/addons/focus-tracker'));
    await teacher.page.locator('[data-ftk-dashboard]').waitFor();
    teacher.config = await teacher.page.locator('[data-ftk-dashboard]').evaluate(node => JSON.parse(node.getAttribute('data-config')));
    check(typeof teacher.config.nonce === 'string' && teacher.config.nonce !== 'focus-test-nonce', 'Teacher REST nonce comes from the installed WordPress page.');
    await until(async () => await teacher.page.locator('[data-field="context"] option').count() > 1, 'Native teacher contexts did not load.');
}
async function openDeniedStudent() {
    if (!config.deniedStudent) return;
    deniedStudent = await login(config.deniedStudent, 'Explicitly denied student');
    deniedStudent.focusRequests = [];
    deniedStudent.page.on('request', request => {
        if (restPath(request.url()).startsWith('/focus-tracker/v1/')) deniedStudent.focusRequests.push(restPath(request.url()));
    });
    await deniedStudent.page.goto(url('/problems/' + config.problem.id + '?group_id=' + config.group.id));
    await deniedStudent.page.getByRole('heading', { level: 1 }).waitFor();
    check(await deniedStudent.page.locator('#focus-tracker-config').count() === 0
        && !(await deniedStudent.page.evaluate(() => Boolean(window.FocusTrackerActivityGate))),
    'An explicitly denied native subscriber receives no tracker or question bootstrap.');
}
async function createActivity() {
    await teacherDashboard();
    const form = teacher.page.locator('[data-field="create-form"]');
    const contextId = config.group.contextId || 'group:' + config.group.id;
    await form.locator('[data-field="name"]').fill('실제 WordPress 자동 참여 검증 ' + Date.now());
    await form.locator('[data-field="context"]').selectOption('group:' + contextId);
    const dates = await teacher.page.evaluate(() => {
        // The page bootstrap timestamp can be old after real asset/context loads.
        // Use the actual current browser clock at the moment the teacher fills the form.
        const now = Date.now();
        const local = ms => { const date = new Date(ms); const pad = value => String(value).padStart(2, '0');
            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds()); };
        return { start: local(now + 30000), end: local(now + 105000) };
    });
    await form.locator('[data-field="starts-at"]').fill(dates.start);
    await form.locator('[data-field="ends-at"]').fill(dates.end);
    const pending = teacher.page.waitForResponse(response => response.request().method() === 'POST' && isApi(response.url(), 'activities'));
    await form.getByRole('button', { name: '활동 만들기', exact: true }).click();
    const response = await pending;
    check(response.ok(), 'Native teacher cookie and nonce authorize activity creation through the actual dashboard UI.');
    const activity = await response.json();
    const body = response.request().postDataJSON();
    check(body.context_type === 'group' && body.context_id === contextId && body.problem_ids === null,
        'Teacher selects only the group and schedule without per-student tracking grants.');
    check(Date.parse(activity.endsAt) - Date.parse(activity.startsAt) >= 75000, 'The real activity lasts at least seventy-five seconds.');
    report.activity = { id: activity.id, startsAt: activity.startsAt, endsAt: activity.endsAt, contextId };
    console.log('Teacher created the actual timed activity through the dashboard.');
    return activity;
}
async function setDraft(student, marker) {
    await student.page.evaluate(marker => {
        const editor = ace.edit(document.querySelector('[data-oj-ace-surface]'));
        editor.setValue('# ' + marker + '\nvalue = 8\nif value <= 10:\n    print(value)', -1);
        editor.focus();
    }, marker);
}
async function openProblem(student) {
    student.requests = []; student.gates = []; student.acks = []; student.next = []; student.responses = []; student.gateFailures = [];
    student.page.on('requestfailed', request => {
        if (isApi(request.url(), 'tracking/state')) student.gateFailures.push({ error: request.failure()?.errorText || 'unknown', elapsedMs: request.timing().responseEnd });
    });
    student.page.on('request', request => {
        if (request.method() !== 'POST') return;
        if (isApi(request.url(), 'events') || isApi(request.url(), 'checks/next') || /\/focus-tracker\/v1\/checks\/\d+\/response$/.test(restPath(request.url()))) {
            student.requests.push({ endpoint: restPath(request.url()), method: request.method(), contentType: request.headers()['content-type'] || '',
                kind: request.resourceType(), body: request.postDataJSON() });
        }
    });
    student.page.on('response', async response => {
        const endpoint = restPath(response.url());
        if (!endpoint.startsWith('/focus-tracker/v1/')) return;
        let data; try { data = await response.json(); } catch (_) { return; }
        const item = { status: response.status(), data, elapsedMs: response.request().timing().responseEnd };
        if (endpoint.endsWith('/tracking/state')) student.gates.push(item);
        else if (endpoint.endsWith('/events')) student.acks.push(item);
        else if (endpoint.endsWith('/checks/next')) student.next.push(item);
        else if (/\/checks\/\d+\/response$/.test(endpoint)) student.responses.push(item);
    });
    await student.page.goto(url('/problems/' + config.problem.id + '?group_id=' + config.group.id));
    await student.page.getByRole('link', { name: '제출하기', exact: true }).first().click();
    check(new URL(student.page.url()).pathname === '/submit/' + config.problem.id,
        student.account.kind + ' reaches the standard draft editor through the actual problem-page link without submitting.');
    await student.page.locator('[data-oj-ace-editor][data-mounted="true"]').waitFor();
    await student.page.locator('select[name="language_id"]').selectOption('6');
    student.config = await student.page.locator('#focus-tracker-config').evaluate(node => JSON.parse(node.textContent));
    check(Number(student.config.userId) === Number(student.account.userId) && student.config.nonce !== 'focus-test-nonce', student.account.kind + ' has a server-issued bootstrap tied to the real login account.');
    check(Number(student.config.checks.initialDelayMs) === 45000 && Number(student.config.activityGate.pollMs) === 5000,
        student.account.kind + ' uses production check delay and lease polling without timer overrides.');
    const initialSummary = await browserRest(student, summaryUrl(student));
    check(initialSummary.status === 200 && initialSummary.data.available === true && initialSummary.data.counts && typeof initialSummary.data.counts === 'object',
        student.account.kind + ' reads the existing check counts before the new activity without issuing a question.');
    student.initialCheckCounts = initialSummary.data.counts;
    await setDraft(student, 'REAL_WP_BEFORE_START_' + student.account.userId);
    await until(() => student.gates.find(item => item.status === 200 && item.data.active === false), 'A real inactive lease is required before the start.');
    check(student.requests.length === 0, student.account.kind + ' sends no event or draft/question while no activity is scheduled.');
    const details = await student.page.evaluate(() => ({ visible: document.visibilityState, focused: document.hasFocus() }));
    check(details.visible === 'visible', student.account.kind + ' remains visible for automatic question presentation.');
}
async function confirmBeforeStart(student) {
    check(await student.page.evaluate(() => !window.FocusTrackerActivityGate.state().active)
        && Date.now() < Date.parse(report.activity.startsAt) && student.requests.length === 0,
    student.account.kind + ' stays in the already-open editor before the future schedule with no event or draft request.');
}
function correctChoice(question) {
    if (question.sourceKind === 'problem') {
        const value = question.choices.find(item => item.label.trim() === String(config.problem.expectedOutput || '7').trim());
        assert.ok(value, 'Published sample output must be visible among the choices.'); return value.id;
    }
    const snippet = question.codeSnippet;
    const assignment = snippet.match(/(?:^|\n)([A-Za-z_]\w*)\s*=\s*(-?\d+);?(?:\n|$)/);
    const comparison = snippet.trim().split('\n').at(-1).trim().match(/^([A-Za-z_]\w*|-?\d+)\s*(<=|>=|==|!=|<|>)\s*([A-Za-z_]\w*|-?\d+)$/);
    assert.ok(assignment && comparison, 'Draft question must contain self-contained visible assigned integers.');
    const numeric = token => token === assignment[1] ? Number(assignment[2]) : Number(token);
    const a = numeric(comparison[1]); const b = numeric(comparison[3]);
    const truth = ({ '<': () => a < b, '<=': () => a <= b, '>': () => a > b, '>=': () => a >= b, '==': () => a === b, '!=': () => a !== b })[comparison[2]]();
    const value = question.choices.find(item => item.label === (truth ? '참' : '거짓'));
    assert.ok(value, 'Visible choices must agree with the independent arithmetic check.'); return value.id;
}
async function exerciseStudent(student, index) {
    await student.page.waitForFunction(() => window.FocusTrackerActivityGate?.state().active === true, undefined, { timeout: 45000 });
    await setDraft(student, 'REAL_WP_ACTIVE_' + student.account.userId);
    await until(() => student.acks.some(item => item.status === 200 && item.data.acknowledged === true && Number(item.data.stored) > 0)
        && student.requests.some(item => item.endpoint.endsWith('/events') && item.body?.events?.some(event => event.type === 'activity')),
    'Native cookie-auth editor activity delivery must receive a durable server ACK.');
    check(student.requests.some(item => item.endpoint.endsWith('/events') && item.body?.events?.some(event => event.type === 'activity')),
        student.account.kind + ' records actual editor activity without manual enrollment.');
    await student.page.locator('[data-ftk-check]').waitFor({ timeout: 65000 });
    const question = await until(() => student.next.find(item => item.status === 200 && item.data.status === 'ready')?.data.check, 'The real platform learning-check gateway must issue a verified question.');
    check(question.affectsFocusScore === false && !JSON.stringify(question).match(/correctAnswer|answer_id|proof|expected_answer/),
        student.account.kind + ' receives a verified public question without an answer key or focus penalty.');
    check(Number(question.expiresAtMs) === Date.parse(report.activity.endsAt),
        student.account.kind + ' caps the automatic question at the actual activity deadline.');
    if (index === 0) {
        const correct = correctChoice(question);
        const wrong = question.choices.find(item => item.id !== correct);
        await student.page.locator('[data-ftk-answer="' + wrong.id + '"]').click();
        const response = await until(() => student.responses.find(item => item.status === 200 && item.data.status === 'incorrect'), 'The actual response endpoint must acknowledge the intentionally incorrect answer.');
        check(response.data.affectsFocusScore === false && (await student.page.locator('[data-ftk-check]').textContent()).includes('미집중으로 판단하지 않습니다'),
            'An incorrect answer is explicitly separate from focus and is not labeled inattention.');
    }
    await student.page.waitForFunction(() => window.FocusTrackerActivityGate?.state().active === false, undefined, { timeout: 50000 });
    check(await student.page.locator('[data-ftk-check], [data-ftk-check-launcher]').count() === 0 && await student.page.locator('[data-ftk-gate-label]').textContent() === '활동 종료',
        student.account.kind + ' closes the question and reports the scheduled end without teacher intervention.');
    await delay(1500); const count = student.requests.length;
    await setDraft(student, 'REAL_WP_AFTER_END_' + student.account.userId); await delay(2500);
    check(student.requests.length === count, student.account.kind + ' sends no fresh event, answer or draft/question after the end.');
    const eventRequests = student.requests.filter(item => item.endpoint.endsWith('/events'));
    const events = eventRequests.flatMap(item => Array.isArray(item.body?.events) ? item.body.events : []);
    check(events.length > 0 && events.every(event => Number(event.occurred_at) >= Date.parse(report.activity.startsAt)
        && (event.type === 'session_end' ? Number(event.occurred_at) <= Date.parse(report.activity.endsAt) : Number(event.occurred_at) < Date.parse(report.activity.endsAt))),
    student.account.kind + ' confines every readable transmitted learning event to the actual activity window.');
    check(!JSON.stringify(student.requests.filter(item => item.endpoint.endsWith('/events'))).includes('REAL_WP_'), 'Readable ordinary event payloads never carry the editor source marker.');
    const summary = await browserRest(student, summaryUrl(student));
    check(summary.status === 200 && summary.data.affectsFocusScore === false, student.account.kind + ' check history remains separate from focus scores.');
    if (index !== 0) {
        check(student.responses.length === 0 && Number(summary.data.counts?.invalidated || 0) === Number(student.initialCheckCounts.invalidated || 0) + 1
            && Number(summary.data.counts?.unanswered || 0) === Number(student.initialCheckCounts.unanswered || 0),
        'This unanswered question is newly invalidated at the activity boundary without fabricating an answer or an inattention penalty.');
    } else check(Number(summary.data.counts?.incorrect || 0) === Number(student.initialCheckCounts.incorrect || 0) + 1,
        'The intentionally incorrect answer is newly persisted in the check history.');
    report.students.push({ userId: student.account.userId, kind: student.account.kind, eventRequests: student.requests.filter(item => item.endpoint.endsWith('/events')).length,
        acknowledgedStored: student.acks.filter(item => item.status === 200).reduce((sum, item) => sum + (Number(item.data.stored) || 0), 0),
        unreadableEventRequests: eventRequests.filter(item => !item.body).map(item => ({ method: item.method, contentType: item.contentType, kind: item.kind })),
        sourceKind: question.sourceKind, response: index === 0 ? 'incorrect' : 'no_response', checkCounts: summary.data.counts });
}
async function inspectPersistedActivity() {
    assert.ok(previousReport.activity?.id && previousReport.baseUrl === base, 'Follow-up requires the actual earlier run at this native site.');
    report.activity = previousReport.activity;
    report.previousRun = { startedAt: previousReport.startedAt, finishedAt: previousReport.finishedAt, passed: previousReport.passed, failure: previousReport.failure || null };
    await teacherDashboard();
    for (const [index, student] of students.entries()) {
        await student.page.goto(url('/submit/' + config.problem.id));
        student.config = await student.page.locator('#focus-tracker-config').evaluate(node => JSON.parse(node.textContent));
        const summary = await browserRest(student, summaryUrl(student));
        check(summary.status === 200 && summary.data.affectsFocusScore === false, student.account.kind + ' reads the real persisted check summary with a native cookie and nonce.');
        check(index === 0 ? Number(summary.data.counts?.incorrect || 0) >= 1 : Number(summary.data.counts?.invalidated || 0) >= 1,
        student.account.kind + ' retains the actual incorrect or unanswered outcome separately from focus after the window.');
        report.students.push({ userId: student.account.userId, kind: student.account.kind, checkCounts: summary.data.counts });
    }
}
async function nativeSecurityAndPersistence() {
    const student = students[0];
    const payload = { user_id: student.account.userId, problem_id: Number(config.problem.id), context_type: 'group', context_id: String(config.group.id) };
    const invalid = await browserRest(student, student.config.activityGate.url, 'POST', payload, 'invalid-native-acceptance-nonce');
    check(invalid.status === 403 && invalid.data.code === 'rest_cookie_invalid_nonce', 'Actual WordPress cookie authentication rejects an invalid REST nonce before addon authorization.');
    const mismatch = await browserRest(student, student.config.activityGate.url, 'POST', { ...payload, user_id: students[1].account.userId });
    check(mismatch.status === 403 && mismatch.data.code === 'focus_tracker_account_changed', 'A real student cookie cannot impersonate the other student with a body ID.');
    const scores = await browserRest(teacher, teacher.config.restUrl + '/activities/' + report.activity.id + '/scores');
    check(scores.status === 200 && students.every(item => scores.data.rows.some(row => Number(row.userId) === Number(item.account.userId))),
        'Teacher cookie-auth REST reads both actual group learners from persisted activity records.');
    for (const item of students) {
        const timeline = await browserRest(teacher, teacher.config.restUrl + '/activities/' + report.activity.id + '/participants/' + item.account.userId + '/timeline');
        check(timeline.status === 200 && Array.isArray(timeline.data.spans) && Array.isArray(timeline.data.markers)
            && timeline.data.spans.length + timeline.data.markers.length > 0,
            item.account.kind + ' has a persisted native database timeline readable through authorized REST.');
    }
    if (deniedStudent) check(deniedStudent.focusRequests.length === 0,
        'Explicit denial remains effective throughout the real group schedule without any gate, event or draft request.');
}
(async () => {
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH, process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')].find(item => item && fs.existsSync(item));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    teacher = await login(config.teacher, 'Teacher');
    students = [];
    for (const [index, account] of config.students.slice(0, 2).entries()) students.push(await login({ ...account, kind: account.kind || (index === 0 ? 'existing' : 'new') }, 'Student ' + (index + 1)));
    if (previousReport) await inspectPersistedActivity();
    else {
        await openDeniedStudent();
        await Promise.all(students.map(openProblem));
        report.baselineCounts = students.map(student => ({ userId: student.account.userId, counts: student.initialCheckCounts }));
        await createActivity();
        await Promise.all(students.map(confirmBeforeStart));
        console.log('Native WordPress students are waiting for the real scheduled activity.');
        await Promise.all(students.map(exerciseStudent));
    }
    await nativeSecurityAndPersistence();
    check(errors.length === 0, 'No uncaught browser errors during the native-site run.');
    report.passed = true;
    console.log(`Real WordPress browser: ${report.checks.length} checks passed (${report.mode}, native cookies/nonces and persisted REST results).`);
})().catch(async error => {
    report.failure = error.message;
    console.error(error.stack || error);
    for (const [index, actor] of [teacher, ...students, deniedStudent].filter(Boolean).entries()) {
        try { await actor.page.screenshot({ path: path.join(artifacts, 'failure-' + index + '.png'), fullPage: true }); } catch (_) { /* Browser may already have exited. */ }
    }
    process.exitCode = 1;
}).finally(async () => {
    report.finishedAt = new Date().toISOString();
    report.gateEvidence = students.map(student => ({ userId: student.account.userId, failures: student.gateFailures || [],
        responses: (student.gates || []).slice(-20).map(item => ({ status: item.status, elapsedMs: item.elapsedMs, active: item.data.active,
            reason: item.data.reason, code: item.data.code, serverNowMs: item.data.serverNowMs, validUntilMs: item.data.validUntilMs })) }));
    fs.writeFileSync(path.join(artifacts, 'browser-result.json'), JSON.stringify(report, null, 2) + '\n', 'utf8');
    for (const context of contexts) await context.close();
    if (browser) await browser.close();
});
