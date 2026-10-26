// Local browser acceptance of the shipped Markdown editor, teacher viewer and
// actual Pyodide Worker. REST persistence is an in-memory fixture, not WordPress.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const playwright = require(process.env.OCL_PLAYWRIGHT || 'playwright');
const engine = process.env.OCL_BROWSER_ENGINE || 'chromium';
if (!['chromium', 'firefox', 'webkit'].includes(engine)) throw new Error('Unsupported browser engine');
const root = path.resolve(__dirname, '..');
const port = Number(process.env.OCL_EDITOR_PORT || 33438);
const origin = 'http://127.0.0.1:' + port;
const url = origin + '/?real=1';
const screenshotDirectory = path.resolve(process.env.OCL_SCREENSHOT_DIR || path.join(root, '..', '_notes'));
const sourceFiles = [
    'resources/vendor/markdown-it/markdown-it.js',
    'resources/js/markdown.js', 'resources/js/text-cell.js',
    'resources/js/lab.js', 'resources/js/dashboard.js', 'resources/js/cell-editor.js',
    'resources/js/worker-runtime.js', 'resources/js/pyodide-worker.js',
    'resources/css/markdown.css', 'resources/css/lab.css', 'resources/css/console.css',
];
const report = {
    status: 'running', engine, hostPlatform: process.platform, fixture: url,
    authentication: 'none; loopback-only memory REST fixture',
    storage: 'formatVersion 2 with in-memory revision compare-and-swap; no WordPress or database acceptance',
    runtime: 'shipped worker-runtime.js and pyodide-worker.js; pinned Pyodide CDN',
    themeScope: 'shipped Markdown CSS with representative platform dark color tokens; no full platform theme acceptance',
    appleSafariTested: false, checks: [], errors: [], warnings: [],
    requestedRuntimeAssets: [], sourceSha256: {}, screenshots: [],
};
const hash = file => crypto.createHash('sha256').update(fs.readFileSync(path.join(root, file))).digest('hex');
for (const file of sourceFiles) report.sourceSha256[file] = hash(file);
const server = spawn(process.execPath, [path.join(__dirname, 'editor-browser-server.cjs')], {
    cwd: root, env: { ...process.env, OCL_EDITOR_PORT: String(port) },
    windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
});
let browser;
const markdown = [
    '# 마크다운 실습',
    '',
    '**굵은 글씨**와 *기울인 글씨*, ~~취소선~~, `print("안녕")`',
    '',
    '> 인용구 추가',
    '',
    '- 첫 번째 항목',
    '- 두 번째 항목',
    '',
    '1. 순서 하나',
    '2. 순서 둘',
    '',
    '```python',
    'print("마크다운 속 코드는 실행하지 않습니다")',
    '```',
    '',
    '| 이름 | 점수 |',
    '| --- | ---: |',
    '| 판다 | 100 |',
    '',
    '[참고 링크](https://example.com/lesson)',
    '',
    '![실습 이미지](' + origin + '/test-image.png)',
    '',
    '---',
    '',
    '다음 줄에도 기록합니다.',
].join('\n');
const attack = [
    '<script>window.__oclMarkdownExecuted = 1</script>',
    '<img src=x onerror="window.__oclMarkdownExecuted = 2">',
    '<svg onload="window.__oclMarkdownExecuted = 3"></svg>',
    '<iframe srcdoc="<script>parent.__oclMarkdownExecuted=4</script>"></iframe>',
    '[실행 링크](javascript:window.__oclMarkdownExecuted=5)',
    '[데이터 링크](data:text/html,unsafe)',
    '![위험 이미지](data:image/svg+xml;base64,PHN2Zy8+)',
    '[파일 링크](file:///etc/passwd)',
].join('\n\n');
const pass = label => { report.checks.push(label); console.log('PASS [' + engine + '] ' + label); };
const flush = page => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));

async function configure(page) {
    page.on('pageerror', error => report.errors.push(error.message));
    page.on('console', message => {
        if (['warning', 'error'].includes(message.type())) {
            report.warnings.push(message.type() + ': ' + message.text() + ' (' + message.location().url + ')');
        }
    });
    page.on('request', request => {
        if (/pyodide|worker-runtime/.test(request.url())) report.requestedRuntimeAssets.push(request.url());
    });
    page.on('dialog', dialog => dialog.accept());
    await page.addInitScript(() => { window.__oclMarkdownExecuted = 0; });
    await page.route(origin + '/test-image.png', route => route.fulfill({
        status: 200, contentType: 'image/png',
        headers: { 'Cross-Origin-Resource-Policy': 'same-origin' },
        body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=', 'base64'),
    }));
}

async function editCode(page, id, value) {
    const cell = page.locator('[data-cell-id="' + id + '"]');
    await cell.scrollIntoViewIfNeeded();
    await cell.locator('.ace_content').waitFor();
    await cell.locator('.ace_text-input').focus();
    await page.keyboard.press('Control+A');
    if (engine === 'firefox') await page.keyboard.type(value);
    else await page.keyboard.insertText(value);
    assert.equal(await cell.locator('.ocl-code').inputValue(), value);
}

async function save(page) {
    const expected = await page.locator('.ocl-cell-input').evaluateAll(nodes => nodes.map(node => node.value));
    const pending = page.waitForResponse(response =>
        response.url() === origin + '/api/notebook' && response.request().method() === 'PUT');
    await page.locator('[data-action="save"]').click();
    const response = await pending;
    await response.finished();
    assert.equal(response.status(), 200);
    const payload = response.request().postDataJSON();
    assert.equal(payload.formatVersion, 2);
    assert.deepEqual(payload.cells.map(cell => cell.code), expected);
    await flush(page);
    return payload;
}

async function safePreview(page, preview) {
    assert.equal(await page.evaluate(() => window.__oclMarkdownExecuted), 0);
    assert.equal(await preview.locator('script, iframe, object, embed, svg, [onerror], [onload]').count(), 0);
    const urls = await preview.locator('a[href], img[src]').evaluateAll(nodes =>
        nodes.map(node => node.getAttribute(node.tagName === 'A' ? 'href' : 'src')));
    for (const target of urls) assert.match(target, /^(https?:|mailto:|#)/i);
    assert.match(await preview.innerText(), /<script>window\.__oclMarkdownExecuted/);
    assert.match(await preview.innerText(), /javascript:window\.__oclMarkdownExecuted=5/);
}

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
        browser = await playwright[engine].launch(options);
        report.browser = browser.version();
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const page = await context.newPage();
        await configure(page);
        await page.goto(url);
        const legacy = page.locator('[data-cell-id="b"]');
        const source = legacy.locator('.ocl-text');
        const preview = legacy.locator('.ocl-markdown');
        await preview.waitFor();
        const initial = await (await page.request.get(origin + '/api/notebook')).json();
        const legacyText = initial.cells.find(cell => cell.id === 'b').code;
        assert.equal(await source.inputValue(), legacyText);
        assert.equal(await source.isVisible(), false);
        assert.equal(await preview.locator('tag').count(), 0);
        assert.match(await preview.innerText(), /<tag>도 글자 그대로 보존합니다/);
        pass('legacy text opens as a safe preview without changing its original source');

        await legacy.locator('.ocl-md-edit').click();
        await source.fill('도구막대 선택');
        await source.press('Control+A');
        await legacy.locator('.ocl-md-bold').click();
        assert.equal(await source.inputValue(), '**도구막대 선택**');
        assert.equal(await preview.locator('strong').innerText(), '도구막대 선택');
        await source.press('Control+Z');
        assert.equal(await source.inputValue(), '도구막대 선택');
        pass('toolbar bold wraps the selected text and native Undo restores the source');

        await source.fill('단축키 굵게');
        await source.press('Control+A'); await source.press('Control+B');
        assert.equal(await source.inputValue(), '**단축키 굵게**');
        await source.fill('단축키 기울임');
        await source.press('Control+A'); await source.press('Control+I');
        assert.equal(await source.inputValue(), '*단축키 기울임*');
        await source.fill('단축키 링크');
        await source.press('Control+A'); await source.press('Control+K');
        await page.keyboard.insertText('https://example.com/keyboard');
        assert.equal(await source.inputValue(), '[단축키 링크](https://example.com/keyboard)');
        await source.press('Control+Enter');
        assert.equal(await source.isVisible(), false);
        await legacy.locator('.ocl-md-edit').click();
        await source.fill('두 번 클릭하여 편집');
        await legacy.locator('.ocl-md-close').click();
        await preview.dblclick();
        assert.equal(await source.isVisible(), true);
        pass('Ctrl+B/I/K, Ctrl+Enter, the close button and double-click editing work');

        await source.fill(markdown);
        assert.equal(await preview.locator('h1').innerText(), '마크다운 실습');
        assert.equal(await preview.locator('strong').innerText(), '굵은 글씨');
        assert.equal(await preview.locator('em').innerText(), '기울인 글씨');
        assert.equal(await preview.locator('s').innerText(), '취소선');
        assert.equal(await preview.locator('blockquote').innerText(), '인용구 추가');
        assert.equal(await preview.locator('ul li').count(), 2);
        assert.equal(await preview.locator('ol li').count(), 2);
        assert.match(await preview.locator('pre code').innerText(), /print\("마크다운 속 코드는 실행하지 않습니다"\)/);
        assert.equal(await preview.locator('table th').count(), 2);
        assert.equal(await preview.locator('table td').last().innerText(), '100');
        assert.equal(await preview.locator('a').getAttribute('href'), 'https://example.com/lesson');
        assert.equal(await preview.locator('img').getAttribute('src'), origin + '/test-image.png');
        assert.equal(await preview.locator('hr').count(), 1);
        await preview.locator('img').scrollIntoViewIfNeeded();
        await page.waitForFunction(() => {
            const image = document.querySelector('[data-cell-id="b"] .ocl-markdown img');
            return image && image.complete && image.naturalWidth > 0;
        });
        pass('live preview renders headings, emphasis, lists, quotes, fenced code, tables, links and images');

        fs.mkdirSync(screenshotDirectory, { recursive: true });
        const desktopShot = path.join(screenshotDirectory, 'oj-code-lab-markdown-desktop-20261020.png');
        // Keep the full cell inside the viewport so the unrelated sticky global
        // toolbar does not cover the editor in a locator screenshot.
        await page.setViewportSize({ width: 1280, height: 1600 });
        await source.evaluate(node => { node.scrollTop = 0; });
        await page.evaluate(() => window.scrollTo(0, 0));
        await legacy.screenshot({ path: desktopShot });
        report.screenshots.push(desktopShot);
        const desktop = await legacy.locator('.ocl-markdown-body').evaluate(node => {
            const sourceBox = node.querySelector('.ocl-markdown-source').getBoundingClientRect();
            const previewBox = node.querySelector('.ocl-markdown-preview-pane').getBoundingClientRect();
            return { sourceTop: sourceBox.top, sourceRight: sourceBox.right, previewTop: previewBox.top, previewLeft: previewBox.left };
        });
        assert(Math.abs(desktop.sourceTop - desktop.previewTop) < 2);
        assert(desktop.previewLeft >= desktop.sourceRight - 1);
        pass('desktop editing presents source and live preview side by side');

        // Exercise the actual stylesheet with platform-style dark color tokens.
        await page.addStyleTag({ content: '.ocl-lab{--ojp-color-ink:rgb(237,242,248);--ojp-color-surface:rgb(23,30,42);--ojp-color-subtle:rgb(31,41,55);--ojp-color-fill:rgb(39,51,67);--ojp-color-line:rgb(75,85,99);--ojp-color-muted:rgb(173,186,204);--ojp-color-primary:rgb(139,188,255);--ojp-color-accent-bg:rgb(32,49,73)}' });
        const colors = await source.evaluate(node => ({ text: getComputedStyle(node).color, background: getComputedStyle(node).backgroundColor }));
        assert.equal(colors.text, 'rgb(237, 242, 248)');
        assert.equal(colors.background, 'rgb(23, 30, 42)');
        assert.equal(await preview.evaluate(node => getComputedStyle(node).color), 'rgb(237, 242, 248)');
        await page.setViewportSize({ width: 390, height: 844 });
        await source.evaluate(node => { node.scrollTop = 0; });
        await flush(page);
        const mobile = await legacy.locator('.ocl-markdown-body').evaluate(node => {
            const sourceBox = node.querySelector('.ocl-markdown-source').getBoundingClientRect();
            const previewBox = node.querySelector('.ocl-markdown-preview-pane').getBoundingClientRect();
            const editor = node.closest('.ocl-markdown-editor');
            return { sourceBottom: sourceBox.bottom, previewTop: previewBox.top,
                documentWidth: document.documentElement.scrollWidth, viewport: innerWidth,
                editorWidth: editor.clientWidth, editorScroll: editor.scrollWidth };
        });
        assert(mobile.previewTop >= mobile.sourceBottom - 1);
        assert(mobile.documentWidth <= mobile.viewport + 1);
        assert(mobile.editorScroll <= mobile.editorWidth + 1);
        const mobileShot = path.join(screenshotDirectory, 'oj-code-lab-markdown-mobile-20261020.png');
        await legacy.screenshot({ path: mobileShot });
        report.screenshots.push(mobileShot);
        pass('dark color tokens remain readable and a mobile viewport stacks panes without page overflow');
        await page.setViewportSize({ width: 1280, height: 900 });

        await editCode(page, 'a', 'token = 41\nname = input("이름: ")\nprint("MD_INPUT", token + 1, name)');
        await page.locator('[data-cell-id="a"] .ocl-btn-run').click();
        const stdin = page.locator('[data-cell-id="a"] .ocl-input-request input');
        await stdin.waitFor({ timeout: 100000 });
        await stdin.fill('기록 중');
        const stdinHandle = await stdin.elementHandle();
        await page.locator('.ocl-btn-insert-text').nth(1).click();
        const inserted = page.locator('.ocl-text-cell').first();
        const insertedSource = inserted.locator('.ocl-text');
        assert.equal(await insertedSource.isVisible(), true);
        const insertedText = '## 셀 사이의 새 기록\n\n**입력 보존**\n\n`raise Exception("TEXT_MUST_NOT_RUN")`';
        await insertedSource.fill(insertedText);
        assert.equal(await source.inputValue(), markdown);
        assert.equal(await page.locator('[data-cell-id="c"] .ocl-code').inputValue(), initial.cells.find(cell => cell.id === 'c').code);
        assert.equal(await stdin.evaluate((node, original) => node === original && original.isConnected, stdinHandle), true);
        assert.equal(await stdin.inputValue(), '기록 중');
        await inserted.locator('.ocl-md-close').click();
        await stdin.press('Enter');
        await page.locator('[data-cell-id="a"] .ocl-output').filter({ hasText: 'MD_INPUT 42 기록 중' }).waitFor({ timeout: 30000 });
        await page.waitForFunction(() => !document.querySelector('.ocl-output-running'));
        assert.equal(await page.locator('.ocl-text-cell .ocl-btn-run').count(), 0);
        pass('inserting and editing a Markdown cell preserves other cells and the live Python input node');
        await editCode(page, 'c', 'print("MD_SHARED", token, name)');
        await page.locator('[data-cell-id="c"] .ocl-btn-run').click();
        await page.locator('[data-cell-id="c"] .ocl-output').filter({ hasText: 'MD_SHARED 41 기록 중' }).waitFor({ timeout: 30000 });
        await page.waitForFunction(() => !document.querySelector('.ocl-output-running'));
        pass('Python variables and output remain usable after Markdown editing');

        const textCellCount = await page.locator('.ocl-text-cell').count();
        await page.locator('.ocl-btn-insert-text').last().click();
        const temporary = page.locator('.ocl-text-cell').last();
        const temporaryId = await temporary.getAttribute('data-cell-id');
        await temporary.locator('.ocl-text').fill('# 삭제할 기록\n\n' + '긴 마크다운 기록\n'.repeat(600));
        await temporary.locator('.ocl-btn-danger').click();
        await page.locator('[data-cell-id="' + temporaryId + '"]').waitFor({ state: 'detached' });
        assert.equal(await page.locator('.ocl-text-cell').count(), textCellCount);
        assert.equal(await source.inputValue(), markdown);
        assert.equal(await insertedSource.inputValue(), insertedText);
        pass('deleting a large text cell removes its editor without changing other notes');

        const payload = await save(page);
        const reopened = await context.newPage();
        await configure(reopened);
        await reopened.goto(url);
        await reopened.locator('[data-cell-id="b"] .ocl-markdown h1').waitFor();
        assert.deepEqual(await reopened.locator('.ocl-cell-input').evaluateAll(nodes => nodes.map(node => node.value)), payload.cells.map(cell => cell.code));
        assert.equal(await reopened.locator('[data-cell-id="b"] .ocl-text').isVisible(), false);
        assert.equal(await reopened.locator('[data-cell-id="b"] .ocl-markdown h1').innerText(), '마크다운 실습');
        const pendingDownload = reopened.waitForEvent('download');
        await reopened.locator('[data-action="download"]').click();
        const download = await pendingDownload;
        assert.equal(download.suggestedFilename(), 'oj-code-lab-notebook.json');
        const downloaded = JSON.parse(fs.readFileSync(await download.path(), 'utf8'));
        assert.equal(downloaded.formatVersion, 2);
        assert.deepEqual(downloaded.cells, payload.cells);
        assert.equal(downloaded.cells.find(cell => cell.id === 'b').code, markdown);
        pass('save, reopen and download preserve exact raw Markdown, outputs and formatVersion 2');
        await reopened.close();

        await source.fill(markdown + '\n\n' + attack);
        await safePreview(page, preview);
        const secured = await save(page);
        assert.equal(secured.cells.find(cell => cell.id === 'b').code, markdown + '\n\n' + attack);
        pass('student preview escapes raw HTML and rejects executable or data URL content without altering stored notes');

        const teacher = await context.newPage();
        await configure(teacher);
        await teacher.goto(origin + '/teacher');
        await teacher.locator('[data-field="context"] option[value="class:local"]').waitFor({ state: 'attached' });
        await teacher.locator('[data-field="context"]').selectOption('class:local');
        await teacher.locator('[data-action="view"]').click();
        const teacherPreview = teacher.locator('.ocl-viewer-text-cell .ocl-markdown').nth(1);
        await teacherPreview.locator('h1').waitFor();
        assert.equal(await teacherPreview.locator('h1').innerText(), '마크다운 실습');
        assert.equal(await teacherPreview.locator('table td').last().innerText(), '100');
        assert.equal(await teacher.locator('[data-field="viewer-cells"] textarea, [data-field="viewer-cells"] .ocl-btn-run, [data-field="viewer-cells"] .ocl-md-edit').count(), 0);
        await safePreview(teacher, teacherPreview);
        assert.match(await teacher.locator('.ocl-code-view').last().innerText(), /MD_SHARED/);
        pass('teacher viewer renders the same saved Markdown read-only with identical unsafe-content protection');
        await teacher.close();

        const fallback = await context.newPage();
        await configure(fallback);
        await fallback.route(origin + '/vendor/markdown-it.js', route => route.fulfill({ status: 200, contentType: 'application/javascript', body: '/* Simulate an unavailable renderer vendor. */' }));
        await fallback.goto(origin + '/');
        const fallbackCell = fallback.locator('[data-cell-id="b"]');
        await fallbackCell.locator('.ocl-markdown').waitFor();
        assert.equal(await fallbackCell.locator('.ocl-markdown').textContent(), markdown + '\n\n' + attack);
        await fallbackCell.locator('.ocl-md-edit').click();
        assert.equal(await fallbackCell.locator('.ocl-text').inputValue(), markdown + '\n\n' + attack);
        assert.equal(await fallback.evaluate(() => window.__oclMarkdownExecuted), 0);
        pass('a missing Markdown vendor falls back to safe plain text with the full editable source intact');
        await fallback.close();

        assert(report.requestedRuntimeAssets.some(asset => asset.includes('pyodide.asm.wasm')));
        assert.deepEqual(report.errors, []);
        assert.deepEqual(report.warnings, []);
        pass('actual Python WASM was loaded with no uncaught page errors or console warnings');
        report.status = 'passed';
    } catch (error) {
        report.status = 'failed';
        report.failure = String(error && error.stack || error);
        console.error(report.failure);
        process.exitCode = 1;
    } finally {
        if (browser) await browser.close();
        if (!server.killed) server.kill();
        await new Promise(resolve => server.exitCode !== null ? resolve() : server.once('exit', resolve));
        report.cleanup = { browserClosed: true, ownedFixtureStopped: true };
        report.checkedAt = new Date().toISOString();
        report.requestedRuntimeAssets = [...new Set(report.requestedRuntimeAssets)];
        for (const file of sourceFiles) {
            if (hash(file) !== report.sourceSha256[file]) {
                report.status = 'failed';
                report.sourceChangedDuringRun = file;
                process.exitCode = 1;
            }
        }
        if (process.env.OCL_BROWSER_REPORT) fs.writeFileSync(process.env.OCL_BROWSER_REPORT, JSON.stringify(report, null, 2) + '\n', 'utf8');
        console.log(JSON.stringify(report));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
