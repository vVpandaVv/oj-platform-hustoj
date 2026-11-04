// Local browser acceptance of latest-click execution and output clearing.
// Actual Ace and Pyodide run against disposable in-memory REST; no production site.
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const assert = require('node:assert/strict'), { spawn } = require('node:child_process');
const playwright = require(process.env.OCL_PLAYWRIGHT || 'playwright');
const engine = process.env.OCL_BROWSER_ENGINE || 'chromium';
if (!['chromium', 'firefox', 'webkit'].includes(engine)) throw new Error('Unsupported browser engine');
const root = path.resolve(__dirname, '..');
const port = Number(process.env.OCL_EDITOR_PORT || 33439);
const origin = 'http://127.0.0.1:' + port, url = origin + '/?real=1';
const sourceFiles = [
    'resources/js/lab.js', 'resources/js/worker-runtime.js', 'resources/js/pyodide-worker.js',
    'resources/js/cell-editor.js', 'resources/js/text-cell.js', 'resources/js/markdown.js',
    'resources/vendor/markdown-it/markdown-it.js', 'resources/css/lab.css', 'resources/css/markdown.css',
];
const hash = file => crypto.createHash('sha256').update(fs.readFileSync(path.join(root, file))).digest('hex');
const report = {
    status: 'running', engine, hostPlatform: process.platform, fixture: url,
    scope: 'Local real-Ace/Pyodide execution, input and output-clear acceptance; in-memory REST only, no WordPress/DB/production/device acceptance.',
    instrumentation: 'Native Worker subclass records job posts and termination; execution and message delivery remain native.',
    checks: [], errors: [], warnings: [], requestedRuntimeAssets: [], sourceSha256: {},
};
for (const file of sourceFiles) report.sourceSha256[file] = hash(file);
const server = spawn(process.execPath, [path.join(__dirname, 'editor-browser-server.cjs')], {
    cwd: root, env: { ...process.env, OCL_EDITOR_PORT: String(port) },
    windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
});
let browser;
const pass = label => { report.checks.push(label); console.log('PASS [' + engine + '] ' + label); };
const cell = (page, id) => page.locator('[data-cell-id="' + id + '"]');
const output = (page, id) => cell(page, id).locator('.ocl-output');
const input = (page, id) => cell(page, id).locator('.ocl-input-request input');
const flush = page => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));

async function configure(page) {
    page.on('pageerror', error => report.errors.push(error.message));
    page.on('console', message => {
        if (['warning', 'error'].includes(message.type())) report.warnings.push(message.type() + ': ' + message.text() + ' (' + message.location().url + ')');
    });
    page.on('request', request => { if (/pyodide|worker-runtime/.test(request.url())) report.requestedRuntimeAssets.push(request.url()); });
    page.on('dialog', dialog => dialog.accept());
    await page.addInitScript(() => {
        const NativeWorker = window.Worker;
        window.__oclExecutionProbe = { workers: [] };
        window.Worker = class extends NativeWorker {
            constructor(workerUrl, options) {
                super(workerUrl, options);
                if (String(workerUrl).includes('pyodide-worker')) {
                    this.probe = { jobs: [], terminated: false };
                    window.__oclExecutionProbe.workers.push(this.probe);
                }
            }
            postMessage(message, ...rest) {
                if (this.probe && ['run', 'debug', 'writeFile'].includes(message.type)) {
                    this.probe.jobs.push({ type: message.type, code: message.code, id: message.id });
                }
                return super.postMessage(message, ...rest);
            }
            terminate() {
                if (this.probe) this.probe.terminated = true;
                return super.terminate();
            }
        };
    });
}
async function edit(page, id, code) {
    const row = cell(page, id);
    await row.scrollIntoViewIfNeeded();
    await row.locator('.ace_content').waitFor();
    await row.locator('.ace_text-input').focus();
    await page.keyboard.press('Control+A');
    if (engine === 'firefox') await page.keyboard.type(code);
    else await page.keyboard.insertText(code);
    assert.equal(await row.locator('.ocl-code').inputValue(), code);
}
async function clickRun(page, id) { await cell(page, id).locator('.ocl-btn-run').click(); }
async function done(page, id, marker) {
    await output(page, id).filter({ hasText: marker }).waitFor({ timeout: 90000 });
    await page.waitForFunction(() => !document.querySelector('.ocl-output-running') && !document.querySelector('.ocl-input-request'), null, { timeout: 20000 });
    await flush(page);
}
async function run(page, id, code, marker) { await edit(page, id, code); await clickRun(page, id); await done(page, id, marker); }
async function waitInput(page, id, prompt) {
    await input(page, id).waitFor({ timeout: 90000 });
    await output(page, id).filter({ hasText: prompt }).waitFor({ timeout: 20000 });
    assert.equal(await page.locator('.ocl-input-request').count(), 1);
}
async function probe(page) { return page.evaluate(() => window.__oclExecutionProbe.workers); }
async function save(page) {
    const pending = page.waitForResponse(response => response.url() === origin + '/api/notebook' && response.request().method() === 'PUT');
    await page.locator('[data-action="save"]').click();
    const response = await pending; await response.finished();
    assert.equal(response.status(), 200);
    const payload = response.request().postDataJSON();
    assert.equal(payload.formatVersion, 2);
    await flush(page);
    return payload;
}
async function clear(page, id) { await cell(page, id).locator('.ocl-btn-clear-output').click(); }

(async () => {
    try {
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Fixture startup timed out')), 15000);
            server.stdout.once('data', () => { clearTimeout(timer); resolve(); });
            server.once('error', reject);
            server.once('exit', code => { if (code) reject(new Error('Fixture exited ' + code)); });
            server.stderr.on('data', data => process.stderr.write(data));
        });
        const options = { headless: true };
        if (process.env.OCL_BROWSER_EXECUTABLE) options.executablePath = process.env.OCL_BROWSER_EXECUTABLE;
        browser = await playwright[engine].launch(options); report.browser = browser.version();
        const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
        const page = await context.newPage(); await configure(page); await page.goto(url);
        await cell(page, 'a').locator('.ocl-btn-clear-output').waitFor();
        assert.equal(await page.evaluate(() => crossOriginIsolated), true);
        const note = await cell(page, 'b').locator('.ocl-text').inputValue();

        await run(page, 'a', 'shared_value = 41\nprint("IDLE_FIRST", shared_value)', 'IDLE_FIRST 41');
        const initialWorkers = (await probe(page)).length;
        await run(page, 'c', 'shared_value += 1\nprint("IDLE_SECOND", shared_value)', 'IDLE_SECOND 42');
        assert.equal((await probe(page)).length, initialWorkers);
        pass('completed cells execute sequentially without resetting their shared Python variables');

        const savedCode = await cell(page, 'a').locator('.ocl-code').inputValue();
        await clear(page, 'a');
        assert.equal(await output(page, 'a').innerText(), '');
        assert.equal(await cell(page, 'a').locator('.ocl-code').inputValue(), savedCode);
        assert.equal(await cell(page, 'b').locator('.ocl-text').inputValue(), note);
        assert.match(await output(page, 'c').innerText(), /IDLE_SECOND 42/);
        await run(page, 'c', 'print("AFTER_CLEAR_VALUE", shared_value)', 'AFTER_CLEAR_VALUE 42');
        assert.equal((await probe(page)).length, initialWorkers);
        const cleared = await save(page);
        assert.deepEqual(cleared.cells.find(item => item.id === 'a').outputs, []);
        await page.reload(); await cell(page, 'a').locator('.ocl-btn-clear-output').waitFor();
        assert.equal(await output(page, 'a').innerText(), '');
        assert.equal(await cell(page, 'a').locator('.ocl-code').inputValue(), savedCode);
        pass('clearing a completed output preserves code, other cells and variables and persists across reload');

        // Seed ordinary saved output types through the disposable REST fixture.
        const stored = await (await page.request.get(origin + '/api/notebook')).json();
        stored.cells.find(item => item.id === 'a').outputs = [
            { type: 'stream', text: 'SAVED_STREAM\n' }, { type: 'error', text: 'SAVED_ERROR' },
            { type: 'image', image: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' },
        ];
        const seed = await page.request.put(origin + '/api/notebook', { data: stored }); assert.equal(seed.status(), 200);
        await page.reload(); await cell(page, 'a').locator('.ocl-output-image').waitFor();
        await clear(page, 'a');
        assert.equal(await output(page, 'a').locator('.ocl-output-text, .ocl-output-error, .ocl-output-image').count(), 0);
        assert.deepEqual((await save(page)).cells.find(item => item.id === 'a').outputs, []);
        pass('clear removes and saves removal of text, errors and saved image outputs');

        const rapidCodes = [
            'answer = input("RAPID_FIRST: ")\nprint("OLD_FIRST", answer)',
            'answer = input("RAPID_SECOND: ")\nprint("OLD_SECOND", answer)',
            'answer = input("RAPID_FINAL: ")\nprint("RAPID_DONE", answer)',
        ];
        for (const code of rapidCodes) { await edit(page, 'a', code); await clickRun(page, 'a'); }
        await waitInput(page, 'a', 'RAPID_FINAL:');
        let workers = await probe(page);
        assert(workers.length >= 3);
        assert(workers.slice(-3, -1).every(worker => worker.terminated));
        assert.deepEqual(workers.at(-1).jobs.map(job => job.code), [rapidCodes[2]]);
        await input(page, 'a').fill('마지막'); await input(page, 'a').press('Enter');
        await done(page, 'a', 'RAPID_DONE 마지막');
        assert.doesNotMatch(await output(page, 'a').innerText(), /OLD_FIRST|OLD_SECOND/);
        pass('rapid same-cell runs during initialization terminate earlier workers and submit only the latest source');

        await edit(page, 'a', 'answer = input("OLD_WAIT: ")\nprint("OLD_RESULT", answer)');
        await clickRun(page, 'a'); await waitInput(page, 'a', 'OLD_WAIT:');
        const oldForm = await cell(page, 'a').locator('.ocl-input-request').elementHandle();
        await input(page, 'a').fill('폐기할 입력');
        await edit(page, 'a', 'answer = input("NEW_WAIT: ")\nprint("NEW_RESULT", answer)');
        await clickRun(page, 'a'); await waitInput(page, 'a', 'NEW_WAIT:');
        assert.equal(await oldForm.evaluate(node => node.isConnected), false);
        assert.equal(await input(page, 'a').inputValue(), '');
        await input(page, 'a').fill('새 입력'); await input(page, 'a').press('Enter');
        await done(page, 'a', 'NEW_RESULT 새 입력');
        assert.doesNotMatch(await output(page, 'a').innerText(), /OLD_WAIT|OLD_RESULT|폐기할 입력/);
        assert.equal((await probe(page)).at(-1).jobs.length, 1);
        pass('rerunning a cell waiting for input replaces its old form and requires only one new answer');

        await edit(page, 'a', 'input("OTHER_OLD_WAIT: ")\nprint("SHOULD_NOT_FINISH")');
        await clickRun(page, 'a'); await waitInput(page, 'a', 'OTHER_OLD_WAIT:');
        await run(page, 'c', 'print("LATEST_OTHER_CELL")', 'LATEST_OTHER_CELL');
        assert.equal(await cell(page, 'a').locator('.ocl-input-request').count(), 0);
        assert.doesNotMatch(await output(page, 'a').innerText(), /SHOULD_NOT_FINISH/);
        assert.match(await cell(page, 'c').locator('.ocl-execution-notice').innerText(), /중단|초기화/);
        pass('running another cell cancels an earlier input wait and keeps a visible replacement notice on the selected cell');

        await edit(page, 'a', 'print("LOOP_STARTED")\nwhile True: pass');
        await clickRun(page, 'a'); await output(page, 'a').filter({ hasText: 'LOOP_STARTED' }).waitFor({ timeout: 90000 });
        await run(page, 'c', 'print("LOOP_REPLACED")', 'LOOP_REPLACED');
        assert.equal(await page.locator('.ocl-input-request').count(), 0);
        pass('a newly selected cell replaces an infinite Python loop without needing a separate stop click');

        await edit(page, 'a', 'input("ALL_CANCEL_WAIT: ")\nprint("ALL_OLD_A")');
        await edit(page, 'c', 'print("ALL_MANUAL_LAST")');
        await page.locator('[data-action="run-all"]').click(); await waitInput(page, 'a', 'ALL_CANCEL_WAIT:');
        const beforeManual = (await probe(page)).length;
        await clickRun(page, 'c'); await done(page, 'c', 'ALL_MANUAL_LAST');
        workers = await probe(page);
        assert.equal(workers.length, beforeManual + 1);
        assert.deepEqual(workers.at(-1).jobs.map(job => job.code), ['print("ALL_MANUAL_LAST")']);
        assert.doesNotMatch(await output(page, 'a').innerText(), /ALL_OLD_A/);
        pass('a manual run cancels an input-blocked Run All including its not-yet-started successor');

        await edit(page, 'a', 'sequence = [input("ALL_LATEST_WAIT: ")]\nprint("ALL_FIRST_DONE")');
        await edit(page, 'c', 'sequence.append("second")\nprint("ALL_FINAL", ",".join(sequence))');
        await page.locator('[data-action="run-all"]').click(); await waitInput(page, 'a', 'ALL_LATEST_WAIT:');
        const oldAllForm = await cell(page, 'a').locator('.ocl-input-request').elementHandle();
        await page.locator('[data-action="run-all"]').click(); await waitInput(page, 'a', 'ALL_LATEST_WAIT:');
        assert.equal(await oldAllForm.evaluate(node => node.isConnected), false);
        await input(page, 'a').fill('last'); await input(page, 'a').press('Enter');
        await done(page, 'c', 'ALL_FINAL last,second');
        assert.deepEqual((await probe(page)).at(-1).jobs.map(job => job.type), ['run', 'run']);
        assert.equal((await probe(page)).at(-1).jobs[0].code.startsWith('sequence ='), true);
        pass('repeating Run All replaces the earlier sequence and executes the final ordered cells exactly once');

        await edit(page, 'a', 'input("MUST_NOT_START: ")');
        await edit(page, 'c', 'print("IMMEDIATE_LATEST")');
        const priorJobCount = (await probe(page)).reduce((sum, worker) => sum + worker.jobs.length, 0);
        await page.locator('[data-action="run-all"]').evaluate(button => {
            button.click();
            document.querySelector('[data-cell-id="c"] .ocl-btn-run').click();
        });
        await done(page, 'c', 'IMMEDIATE_LATEST');
        const allJobs = (await probe(page)).flatMap(worker => worker.jobs);
        assert.deepEqual(allJobs.slice(priorJobCount).map(job => job.code), ['print("IMMEDIATE_LATEST")']);
        pass('a newer run invalidates Run All before its first promise continuation can enqueue a cell');

        await edit(page, 'a', 'print("ERASE_OLD_HISTORY")\nanswer = input("ERASE_PROMPT: ")\nprint("AFTER_INPUT_CLEAR", answer)');
        await clickRun(page, 'a'); await waitInput(page, 'a', 'ERASE_PROMPT:');
        const preservedForm = await cell(page, 'a').locator('.ocl-input-request').elementHandle();
        const preservedInput = await input(page, 'a').elementHandle();
        await input(page, 'a').fill('작성 중인 값');
        const beforeClear = (await probe(page)).length;
        await clear(page, 'a'); await clear(page, 'a');
        assert.equal(await preservedForm.evaluate(node => node.isConnected), true);
        assert.equal(await preservedInput.evaluate(node => node.isConnected), true);
        assert.equal(await input(page, 'a').inputValue(), '작성 중인 값');
        assert.equal(await page.locator('.ocl-input-request').count(), 1);
        assert.doesNotMatch(await output(page, 'a').innerText(), /ERASE_OLD_HISTORY/);
        assert.equal((await probe(page)).length, beforeClear);
        await input(page, 'a').press('Enter'); await done(page, 'a', 'AFTER_INPUT_CLEAR 작성 중인 값');
        const afterInput = (await save(page)).cells.find(item => item.id === 'a').outputs;
        assert(afterInput.some(item => item.type === 'input' && item.text.includes('작성 중인 값')));
        assert(!afterInput.some(item => (item.text || '').includes('ERASE_OLD_HISTORY')));
        assert(afterInput.some(item => item.type === 'stream' && item.text.includes('AFTER_INPUT_CLEAR')));
        pass('clearing inline input output preserves the same typed form and records its answer plus later output in fresh saved history');

        await edit(page, 'a', 'debug_value = 73\nprint("DEBUG_AFTER_CLEAR", debug_value)');
        await cell(page, 'a').locator('.ocl-btn-debug').click();
        await cell(page, 'a').locator('.ocl-debug-over').waitFor();
        await page.waitForFunction(() => { const button = document.querySelector('[data-cell-id="a"] .ocl-debug-over'); return button && !button.disabled; });
        const debugPanel = await cell(page, 'a').locator('.ocl-debug-panel').elementHandle();
        await clear(page, 'a');
        assert.equal(await debugPanel.evaluate(node => node.isConnected), true);
        await cell(page, 'a').locator('.ocl-debug-continue').click(); await done(page, 'a', 'DEBUG_AFTER_CLEAR 73');
        await cell(page, 'a').locator('.ocl-debug-stop').click();
        await run(page, 'c', 'print("DEBUG_VALUE_SURVIVES", debug_value)', 'DEBUG_VALUE_SURVIVES 73');
        pass('clearing a paused debugger output preserves its panel, resume command and resulting Python variables');

        assert(report.requestedRuntimeAssets.some(asset => asset.includes('pyodide.asm.wasm')));
        assert.deepEqual(report.errors, []); assert.deepEqual(report.warnings, []);
        pass('real Python WASM loaded with no uncaught page errors or console warnings');
        report.status = 'passed';
    } catch (error) {
        report.status = 'failed'; report.failure = String(error && error.stack || error);
        console.error(report.failure); process.exitCode = 1;
    } finally {
        if (browser) await browser.close();
        if (!server.killed) server.kill();
        await new Promise(resolve => server.exitCode !== null ? resolve() : server.once('exit', resolve));
        report.cleanup = { browserClosed: true, ownedFixtureStopped: true };
        report.checkedAt = new Date().toISOString();
        report.requestedRuntimeAssets = [...new Set(report.requestedRuntimeAssets)];
        for (const file of sourceFiles) {
            if (hash(file) !== report.sourceSha256[file]) {
                report.status = 'failed'; report.sourceChangedDuringRun = file; process.exitCode = 1;
            }
        }
        if (process.env.OCL_BROWSER_REPORT) fs.writeFileSync(process.env.OCL_BROWSER_REPORT, JSON.stringify(report, null, 2) + '\n', 'utf8');
        console.log(JSON.stringify(report));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
