'use strict';

// Real Chromium, current OJ Platform renderer/Ace/Blockly, production check
// client/controller/generator/repository. WordPress authentication and platform
// gateway data are test infrastructure supplied by http-fixture.php.
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
const db = path.join(temporary, `check-browser-${process.pid}-${Date.now()}.sqlite`);
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:/php/php.exe' : 'php');
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
let server;
let browser;
let base;
let serverOutput = '';
let assertions = 0;
const errors = [];

function check(condition, message) { assert.ok(condition, message); assertions += 1; }
async function request(url, payload) {
    const response = await fetch(base + url, payload === undefined ? {} : {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    });
    const text = await response.text();
    assert.ok(response.ok, `${url}: ${response.status} ${text.slice(0, 1000)}`);
    return JSON.parse(text);
}
async function until(predicate, message, timeout = 12000) {
    const end = Date.now() + timeout;
    do {
        const result = await predicate();
        if (result) return result;
        await delay(60);
    } while (Date.now() < end);
    throw new Error(message);
}
async function configure(scenario, extra = {}) {
    await request('/__test/reset', {});
    await request('/__test/check-config', {
        reset: true, scenario, initialDelayMs: 1200, pollMs: 1000,
        cooldownSeconds: 60, dailyLimit: 20, userId: 1, ...extra,
    });
    await request('/__test/activity', { name: '자동 확인 허용 시간', startOffsetMs: -1000, endOffsetMs: 60000 });
}
async function pageFor(scenario, { url = '/problems/123', source = '', config = {}, intercept = null } = {}) {
    await configure(scenario, config);
    const context = await browser.newContext();
    const page = await context.newPage();
    const log = { requests: [], next: [], responses: [], events: [] };
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', item => {
        if (!item.postData()) return;
        if (item.url().includes('/checks/')) log.requests.push({ url: item.url(), body: JSON.parse(item.postData()) });
        if (item.url().includes('/events')) log.events.push(item.postData());
    });
    page.on('response', async response => {
        var entry;
        if (response.url().includes('/checks/next')) {
            entry = { code: response.status(), body: {} };
            log.next.push(entry);
        } else if (/\/checks\/[^/]+\/response/.test(response.url())) {
            entry = { code: response.status(), body: {} };
            log.responses.push(entry);
        }
        if (!entry) return;
        try {
            entry.body = await response.json();
        } catch (_) { /* A rejected/cancelled body's HTTP status remains observable. */ }
    });
    if (intercept) await intercept(page, log);
    await page.goto(base + url);
    if (!url.includes('mode=completion') && !url.includes('mode=blockly')) {
        await page.locator('select[name="language_id"]').selectOption('6');
        await page.locator('[data-oj-ace-editor][data-mounted="true"]').waitFor();
        await page.evaluate(value => {
            const surface = document.querySelector('[data-oj-ace-surface]');
            const editor = window.ace.edit(surface);
            editor.setValue(value, -1);
            editor.focus();
            window.__checkEditorBefore = document.activeElement;
        }, source);
    }
    return { context, page, log };
}
async function question(app) {
    try {
        await app.page.locator('[data-ftk-check]').waitFor({ timeout: 12000 });
    } catch (error) {
        console.error('Question diagnostics:', JSON.stringify({ next: app.log.next, requests: app.log.requests,
            editor: await app.page.evaluate(() => ({ source: document.querySelector('[data-oj-source-editor]')?.value,
                blockly: document.querySelector('[data-oj-blockly-code]')?.textContent,
                status: document.querySelector('[data-oj-blockly-status]')?.textContent,
                statusClass: document.querySelector('[data-oj-blockly-status]')?.className,
                config: document.querySelector('#focus-tracker-config')?.textContent })) }));
        throw error;
    }
    return until(() => app.log.next.find(item => item.body.status === 'ready')?.body.check,
        'The real next endpoint must issue a verified question.');
}
function publicCorrectChoice(value) {
    if (value.sourceKind === 'problem') {
        const result = value.choices.find(choice => choice.label.trim() === '7');
        assert.ok(result, 'Fixture public sample output must be a choice.');
        return result.id;
    }
    const snippet = value.codeSnippet;
    const assignment = snippet.match(/(?:^|\n)(?:int )?([A-Za-z_]\w*)\s*=\s*(-?\d+);?(?:\n|$)/);
    const expression = snippet.trim().split('\n').at(-1).trim();
    assert.ok(assignment, `Public question must show assigned integers: ${snippet}`);
    const comparison = expression.match(/^([A-Za-z_]\w*|-?\d+)\s*(<=|>=|==|!=|<|>)\s*([A-Za-z_]\w*|-?\d+)$/);
    assert.ok(comparison, `Public question must show a supported comparison: ${snippet}`);
    const numeric = token => token === assignment[1] ? Number(assignment[2]) : Number(token);
    const a = numeric(comparison[1]);
    const b = numeric(comparison[3]);
    const truth = ({ '<': () => a < b, '<=': () => a <= b, '>': () => a > b, '>=': () => a >= b, '==': () => a === b, '!=': () => a !== b })[comparison[2]]();
    const choice = value.choices.find(item => item.label === (truth ? '참' : '거짓'));
    assert.ok(choice, 'The visible choices must contain the mathematically verified answer.');
    return choice.id;
}
async function respond(app, question, outcome) {
    const id = publicCorrectChoice(question);
    if (outcome === 'correct') await app.page.locator(`[data-ftk-answer="${id}"]`).click();
    else if (outcome === 'incorrect') await app.page.locator(`[data-ftk-answer="${question.choices.find(choice => choice.id !== id).id}"]`).click();
    else await app.page.getByRole('button', { name: outcome === 'unknown' ? '모르겠음' : '나중에', exact: true }).click();
    return until(() => app.log.responses.find(item => item.body.status === outcome), `The actual response endpoint must persist ${outcome}.`);
}
async function savedScore() {
    const activity = await request('/__test/activity', { name: 'Checks never change this score', startOffsetMs: -100000, endOffsetMs: -50000 });
    const start = Date.parse(activity.startsAt);
    const end = Date.parse(activity.endsAt);
    const events = [
        { type: 'session_start', occurred_at: start, meta: { visible: true, focused: true } },
        { type: 'activity', occurred_at: start + 1000, meta: { subtype: 'code_edit' } },
        { type: 'heartbeat', occurred_at: start + 15000, meta: { visible: true, focused: true } },
        { type: 'heartbeat', occurred_at: start + 30000, meta: { visible: true, focused: true } },
        { type: 'heartbeat', occurred_at: start + 45000, meta: { visible: true, focused: true } },
        { type: 'session_end', occurred_at: end, meta: { visible: true, focused: true } },
    ];
    await request('/wp-json/focus-tracker/v1/events?_wpnonce=focus-test-nonce', {
        user_id: 1, problem_id: 123, session_uid: 'check-score-' + activity.id,
        session_started_at: start, is_final: true, events: events.map((event, index) => ({ ...event, seq: index + 1 })),
    });
    const url = `/wp-json/focus-tracker/v1/activities/${activity.id}/scores`;
    const before = (await request(url)).rows[0];
    check(before.finalized, 'Focus-score comparison uses a finalized observed activity.');
    return async () => {
        const after = (await request(url)).rows[0];
        assert.deepEqual(after, before, 'A learning-check result must leave every finalized focus metric unchanged.');
        assertions += 1;
    };
}

async function draftAndOutcomes() {
    for (const outcome of ['correct', 'incorrect', 'unknown', 'skipped']) {
        console.log(`Checking draft outcome: ${outcome}`);
        const app = await pageFor('draft', { source: '# PRIVATE_TRANSIENT_DRAFT\nvalue = 8\nif value <= 10:\n    print(value)\n' });
        const verifyScore = await savedScore();
        const value = await question(app);
        check(value.sourceKind === 'draft', 'A pre-submission draft can generate its own verified check.');
        const sent = app.log.requests.find(item => item.url.includes('/checks/next')).body;
        check(sent.draft?.source.includes('PRIVATE_TRANSIENT_DRAFT') && Number(sent.draft.language_id) === 6, 'The synchronized Ace draft, with the actual language, reaches the checks endpoint.');
        check(!app.log.events.some(item => item.includes('PRIVATE_TRANSIENT_DRAFT')), 'Ordinary tracking payloads still exclude source code.');
        check(!JSON.stringify(value).match(/correctAnswer|proof|explanation|answer_id/), 'The issued question contains no server answer key, proof, or explanation.');
        check(await app.page.evaluate(() => document.activeElement === window.__checkEditorBefore), 'Automatic question display preserves the active editor and typing focus.');
        check(await app.page.locator('[data-ftk-check-announcer][aria-live="polite"]').count() === 1
            && (await app.page.locator('[data-ftk-check-announcer]').textContent()).includes('1문항이 준비'),
        'A permanent polite live region announces a ready question without moving focus.');
        check(await app.page.locator('[data-ftk-check][role="region"]').count() === 1 && await app.page.locator('dialog[open], [role="dialog"]').count() === 0,
            'Questions are inline and accessible without a blocking modal.');
        if (outcome === 'correct') {
            await app.page.setViewportSize({ width: 390, height: 480 });
            await app.page.evaluate(() => window.scrollTo(0, 0));
            const position = await app.page.evaluate(() => {
                const panel = document.querySelector('[data-ftk-check]').getBoundingClientRect();
                const launcher = document.querySelector('[data-ftk-check-launcher]').getBoundingClientRect();
                return { panelTop: panel.top, viewportHeight: innerHeight, viewportWidth: innerWidth,
                    left: launcher.left, right: launcher.right, top: launcher.top, bottom: launcher.bottom };
            });
            check(position.panelTop >= position.viewportHeight, 'The mobile test exercises a question below the editor viewport.');
            check(position.left >= 0 && position.right <= position.viewportWidth && position.top >= 0 && position.bottom <= position.viewportHeight,
                'A compact visible entry point stays inside a narrow mobile viewport.');
            await app.page.getByRole('button', { name: '학습 확인 1문항 보기', exact: true }).click();
            check(await app.page.locator('[data-ftk-check]').evaluate(item => item.getBoundingClientRect().top < innerHeight),
                'Only explicit entry-point activation scrolls the question into view.');
            check(await app.page.locator('.ftk-check-title').evaluate(item => item === document.activeElement), 'Explicit launcher activation gives keyboard users the question heading.');
        }
        await respond(app, value, outcome);
        await app.page.getByRole('button', { name: '닫기', exact: true }).waitFor();
        check(await app.page.getByRole('button', { name: '닫기', exact: true }).evaluate(item => item === document.activeElement),
            'The replaced response controls retain keyboard focus on the result close action.');
        check(await app.page.locator('[data-ftk-check-launcher]').count() === 0, 'Recording a response removes the compact entry point.');
        if (outcome !== 'correct') check((await app.page.locator('[data-ftk-check]').textContent()).includes('미집중으로 판단하지 않습니다'), 'Incorrect, unknown and skipped answers are never labeled inattentive.');
        await verifyScore();
        const count = app.log.requests.length;
        await delay(1300);
        check(app.log.requests.length === count, 'No fresh question appears during the response cooldown.');
        const data = await request('/__test/state');
        check(!JSON.stringify(data).includes('PRIVATE_TRANSIENT_DRAFT'), 'Raw transient source is not stored in focus-tracker tables.');
        await app.page.getByRole('button', { name: '닫기', exact: true }).click();
        check(await app.page.evaluate(() => document.activeElement === window.__checkEditorBefore), 'Closing a result returns keyboard focus to the original Ace input.');
        await app.context.close();
    }
}

async function fallbacks() {
    console.log('Checking submission/problem/unavailable fallbacks');
    const submitted = await pageFor('submission');
    const submittedQuestion = await question(submitted);
    check(submittedQuestion.sourceKind === 'submission', 'Own submitted source is used when the current editor is empty.');
    check(!submitted.log.requests[0].body.draft, 'An empty draft does not suppress submitted-source fallback.');
    await respond(submitted, submittedQuestion, 'correct');
    await submitted.context.close();

    const problem = await pageFor('problem');
    const problemQuestion = await question(problem);
    check(problemQuestion.sourceKind === 'problem' && problemQuestion.input === '3 4', 'With no draft or submission, the public problem sample provides verified evidence.');
    check((await problem.page.locator('[data-ftk-check]').textContent()).includes('3 4'), 'The sample question shows all input needed to answer it.');
    await respond(problem, problemQuestion, 'correct');
    const self = await problem.context.newPage();
    self.on('pageerror', error => errors.push(error.message));
    await self.goto(base + '/__test/self');
    const summary = self.locator('[data-field="summary"]');
    await summary.getByText('자동 학습 확인 · 최근 24시간', { exact: true }).waitFor();
    await summary.getByText('정답 확인 1건', { exact: false }).waitFor();
    check(await summary.getByText('정답 확인 1건', { exact: false }).count() === 1,
        'A learner sees the automatic check summary during the scheduled activity without teacher question authoring or grading.');
    await problem.context.close();

    const unavailable = await pageFor('unavailable', { source: 'if this_is_incomplete' });
    await until(() => unavailable.log.next.some(item => item.body.status === 'unavailable'), 'An unsupported draft without public evidence must decline to issue a question.');
    check(await unavailable.page.locator('[data-ftk-check]').count() === 0, 'Unverifiable source never creates a question or an invented answer.');
    check((await unavailable.page.locator('[data-ftk-check-availability]').textContent()).includes('자료를 확인하지 못해'),
        'A source access failure is explained separately from an incorrect answer or a no-candidate result.');
    await unavailable.context.close();
}

async function completionAndBlockly() {
    console.log('Checking real completion and Blockly editors');
    const completion = await pageFor('problem', { url: '/submit?problem_id=123&mode=completion' });
    await completion.page.locator('textarea[name="completion_answers[answer]"]').fill('if (value <= 10) { return 7; }');
    await completion.page.locator('input[name="completion_answers[expression]"]').fill('0');
    await question(completion);
    const draft = completion.log.requests.find(item => item.url.includes('/checks/next')).body.draft;
    check(draft && draft.source.includes('if (value <= 10) { return 7; }') && !draft.source.includes('{{OJ_BLANK:'), 'Complete blank inputs are assembled with the visible current platform template.');
    check(Number(draft.language_id) === 0, 'The actual completion language is retained.');
    await completion.context.close();

    // Blockly loads several real feature scripts before its model is ready.
    // Leave enough settling time so this test checks a connected draft rather
    // than consuming the first automatic poll while the editor is loading.
    const blockly = await pageFor('draft', { url: '/problems/123?mode=blockly', config: { initialDelayMs: 8000 } });
    await blockly.page.locator('[data-oj-blockly-editor][data-mounted="true"]').waitFor();
    await blockly.page.evaluate(() => {
        const workspace = Blockly.common.getAllWorkspaces().find(item => !item.isFlyout && item.getParentSvg()?.closest('[data-oj-blockly-editor]'));
        if (!workspace) throw new Error('The actual platform Blockly workspace is missing.');
        const make = type => { const item = workspace.newBlock(type); item.initSvg(); item.render(); return item; };
        const start = workspace.getBlocksByType('oj_start', false)[0];
        const condition = make('controls_if');
        const comparison = make('logic_compare');
        comparison.setFieldValue('LTE', 'OP');
        const variable = workspace.createVariable('value');
        const get = make('variables_get');
        get.setFieldValue(variable.getId(), 'VAR');
        const number = make('math_number');
        number.setFieldValue('10', 'NUM');
        comparison.getInput('A').connection.connect(get.outputConnection);
        comparison.getInput('B').connection.connect(number.outputConnection);
        condition.getInput('IF0').connection.connect(comparison.outputConnection);
        start.getInput('DO').connection.connect(condition.previousConnection);
    });
    const blocklyQuestion = await question(blockly);
    const generated = blockly.log.requests.find(item => item.url.includes('/checks/next')).body.draft;
    check(generated && generated.language_id === 6 && generated.source.includes('value <= 10'), 'Active valid Blockly generated Python is collected without reading stale direct code.');
    check(blocklyQuestion.sourceKind === 'draft', 'Verified checks also work before a Blockly submission.');
    await respond(blockly, blocklyQuestion, 'correct');
    await blockly.context.close();
}

async function retryAndXss() {
    console.log('Checking response replay and safe text rendering');
    let discarded = false;
    const app = await pageFor('draft', {
        source: 'value = 8\nif value <= 10:\n    print(value)',
        intercept: async page => {
            await page.route('**/checks/next', async route => {
                const response = await route.fetch();
                const body = await response.json();
                if (body.check) {
                    body.check.prompt += '\n<img src=x onerror="window.__checkXss=1">';
                    body.check.codeSnippet += '\n<script>window.__checkXss=1</script>';
                }
                await route.fulfill({ response, json: body });
            });
            await page.route('**/checks/*/response', async route => {
                if (!discarded) {
                    discarded = true;
                    await route.fetch(); // Server commits; the browser loses its acknowledgement.
                    await route.abort('failed');
                } else await route.continue();
            });
        },
    });
    const value = await question(app);
    check(await app.page.locator('[data-ftk-check] img, [data-ftk-check] script').count() === 0 && !await app.page.evaluate(() => window.__checkXss),
        'Returned prompt and code render as text even when they contain executable HTML.');
    // The test deliberately added harmless markup to the snippet; derive the
    // known comparison using only its original, visible first two lines.
    const original = { ...value, codeSnippet: value.codeSnippet.split('\n<script>')[0] };
    await respond(app, original, 'correct');
    const responses = app.log.requests.filter(item => /\/checks\/[^/]+\/response/.test(item.url));
    check(responses.length >= 2 && responses.every(item => JSON.stringify(item.body) === JSON.stringify(responses[0].body)),
        'A lost acknowledgement retries the identical response UID and answer against the real idempotent endpoint.');
    const data = await request('/__test/state');
    check(Array.isArray(data.checks) && data.checks.length === 1, 'A response retry leaves one issued question in storage.');
    await app.context.close();
}

async function expiryAndAccount() {
    console.log('Checking expiry and account switch');
    const unanswered = await pageFor('problem', { intercept: async page => {
        // Accelerate only the client expiry display; the issued question and
        // its verified answer still come from the production generator/API.
        await page.route('**/checks/next', async route => {
            const response = await route.fetch();
            const body = await response.json();
            if (body.check) body.check.expiresAtMs = Date.now() + 1500;
            await route.fulfill({ response, json: body });
        });
    } });
    await question(unanswered);
    const verifyScore = await savedScore();
    await unanswered.page.locator('[data-ftk-check]').getByText('응답이 없어 학습 참여 여부를 확인하지 못했습니다. 미집중으로 판단하지 않습니다.', { exact: true }).waitFor();
    check(unanswered.log.responses.length === 0, 'Question expiry never fabricates an answer submission.');
    check(await unanswered.page.locator('[data-ftk-check-launcher]').count() === 0, 'An unanswered expired check also clears the compact entry point.');
    await verifyScore();
    await unanswered.context.close();

    const expired = await pageFor('problem');
    const expiredQuestion = await question(expired);
    await request('/__test/check-config', { expirePending: true });
    await expired.page.locator(`[data-ftk-answer="${publicCorrectChoice(expiredQuestion)}"]`).click();
    await until(() => expired.log.responses.some(item => ['unanswered', 'expired'].includes(item.body.status)),
        'The real backend must refuse to grade a question after its stored expiry.');
    check((await expired.page.locator('[data-ftk-check]').textContent()).includes('미집중으로 판단하지 않습니다'), 'Server-expired checks are never marked inattentive.');
    await expired.context.close();

    const stale = await pageFor('problem');
    const value = await question(stale);
    await request('/__test/check-config', { userId: 2 });
    await stale.page.locator(`[data-ftk-answer="${publicCorrectChoice(value)}"]`).click();
    try {
        await until(() => stale.log.responses.some(item => item.code === 403), 'An old page must not submit its previous student response after account switching.');
    } catch (error) {
        console.error('Account diagnostics:', JSON.stringify({ requests: stale.log.requests, responses: stale.log.responses,
            state: await request('/__test/state'), text: await stale.page.locator('[data-ftk-check]').textContent() }));
        throw error;
    }
    const prior = stale.log.requests.length;
    await delay(2200);
    check(stale.log.requests.length === prior, 'Account mismatch disables automatic retries from the stale page.');
    check((await stale.page.locator('[data-ftk-check]').textContent()).includes('로그인 상태를 확인'), 'The stale page explains its account state without a focus penalty.');
    await stale.context.close();
}

(async () => {
    const port = await new Promise(resolve => {
        const reservation = net.createServer();
        reservation.listen(0, '127.0.0.1', () => { const port = reservation.address().port; reservation.close(() => resolve(port)); });
    });
    base = `http://127.0.0.1:${port}`;
    server = spawn(php, ['-d', 'extension=pdo_sqlite', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'http-fixture.php')], {
        cwd: root, env: { ...process.env, FOCUS_TRACKER_TEST_DB: db }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
    });
    server.stdout.on('data', data => { serverOutput += data; });
    server.stderr.on('data', data => { serverOutput += data; });
    await until(async () => { try { return (await fetch(base + '/__test/state')).ok; } catch (_) { return false; } }, 'PHP check fixture did not start.');
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH,
        process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe'),
    ].find(candidate => candidate && fs.existsSync(candidate));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    const phases = { draft: draftAndOutcomes, fallbacks, editors: completionAndBlockly, retry: retryAndXss, expiry: expiryAndAccount };
    for (const [name, phase] of Object.entries(phases)) {
        if (!process.env.FOCUS_CHECK_BROWSER_PHASE || process.env.FOCUS_CHECK_BROWSER_PHASE === name) await phase();
    }
    check(errors.length === 0, `The actual browser has no uncaught errors: ${errors.join('; ')}`);
    console.log(`Learning checks browser: ${assertions} checks passed (real Chromium, platform editors, verified API/storage, retries, no focus-score effects).`);
})().catch(error => {
    console.error(error.stack || error);
    console.error(serverOutput.slice(-16000));
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
