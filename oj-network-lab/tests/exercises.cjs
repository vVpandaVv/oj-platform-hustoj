const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const http = require('node:http'), fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process'), {pathToFileURL} = require('node:url'), {randomUUID} = require('node:crypto');
const root = path.resolve(__dirname, '..'), output = process.env.OJNL_TEST_OUTPUT || path.resolve(root, '../_notes/tmp/oj-network-lab-exercises');
let html, writes = 0, browser;
const server = http.createServer((req, res) => {
    const pathname = new URL(req.url, 'http://localhost').pathname;
    if (pathname === '/') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>실습 링크 검증</title><link rel="stylesheet" href="/resources/css/network-lab.css"><style>body{margin:16px;font-family:Arial,sans-serif}main{max-width:1550px;margin:auto}</style><main>${html}</main><script src="/resources/js/app.js"></script></html>`); return;
    }
    if (pathname.startsWith('/api/')) {
        res.setHeader('Content-Type', 'application/json');
        if (req.headers['x-wp-nonce'] !== 'test-nonce') { res.writeHead(403); res.end('{}'); return; }
        if (req.method === 'GET') { res.end('{"projects":[]}'); return; }
        writes++; res.writeHead(503); res.end('{"message":"Offline fixture: export or local recovery"}'); return;
    }
    const file = path.resolve(root, '.' + decodeURIComponent(pathname));
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404); res.end('missing'); return; }
    res.setHeader('Content-Type', file.endsWith('.js') ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8'); res.end(fs.readFileSync(file));
});

async function drafts(page, additions) {
    return page.evaluate(entries => new Promise((resolve, reject) => {
        const config = JSON.parse(document.querySelector('[data-network-lab]').dataset.config);
        const request = indexedDB.open(`oj-network-lab-${config.siteKey}-${config.userId}`, 1);
        request.onerror = () => reject(request.error);
        request.onsuccess = () => {
            const db = request.result, tx = db.transaction('drafts', entries ? 'readwrite' : 'readonly'), store = tx.objectStore('drafts'), rows = [];
            if (entries) for (const {key, value} of entries) store.put(value, key);
            const cursor = store.openCursor(); cursor.onsuccess = () => { const c = cursor.result; if (c) { rows.push({key: c.key, value: c.value}); c.continue(); } };
            tx.oncomplete = () => { db.close(); resolve(rows); }; tx.onerror = () => { db.close(); reject(tx.error); };
        };
    }), additions);
}
async function saved(page, context, title) {
    await page.waitForFunction(async ({context, title}) => {
        const config = JSON.parse(document.querySelector('[data-network-lab]').dataset.config);
        return new Promise(resolve => {
            const request = indexedDB.open(`oj-network-lab-${config.siteKey}-${config.userId}`, 1);
            request.onsuccess = () => { const db = request.result, tx = db.transaction('drafts'), read = tx.objectStore('drafts').getAll(); tx.oncomplete = () => { db.close(); resolve(read.result.some(r => r.context === context && r.project.title === title)); }; };
        });
    }, {context, title});
}
async function project(page) {
    const pending = page.waitForEvent('download'); await page.click('[data-action="export"]');
    const download = await pending, stream = await download.createReadStream(), chunks = [];
    for await (const chunk of stream) chunks.push(chunk);
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}
async function title(page, text) {
    await page.locator('[data-title]').fill(text); await page.locator('[data-title]').press('Tab');
    await page.locator('[data-action="lesson"]:not(:disabled)').waitFor();
}
async function clearLocal(page) {
    await page.click('[data-action="local-clear"]'); await page.locator('[data-dialog] button[value="ok"]').click();
    await page.waitForFunction(() => document.querySelector('[data-local]').textContent.includes('삭제했습니다'));
}

(async () => {
    const {CURRICULUM} = await import(pathToFileURL(path.join(root, 'resources/js/curriculum.js')));
    const {scenario, LESSONS} = await import(pathToFileURL(path.join(root, 'resources/js/scenarios.js')));
    const {validateProject} = await import(pathToFileURL(path.join(root, 'resources/js/model.js')));
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    html = execFileSync(process.env.PHP_BINARY || 'D:/php/php.exe', [path.join(__dirname, 'render.php')], {encoding: 'utf8', env: {...process.env, OJNL_TEST_ORIGIN: origin}});
    browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}}), errors = [], external = [];
    context.setDefaultTimeout(12000);
    context.on('page', p => p.on('pageerror', e => errors.push(e.message)));
    await context.route('**/*', route => { const url = route.request().url(); if (!url.startsWith(origin + '/') && !url.startsWith('blob:')) { external.push(url); return route.abort(); } return route.continue(); });
    const page = await context.newPage(); page.setDefaultTimeout(12000);
    async function open(p, query = '') { await p.goto(origin + '/' + query); await p.locator('[data-action="lesson"]:not(:disabled)').waitFor(); }
    const generic = {project: scenario('routing'), meta: {id: 0, revision: 0, clientId: randomUUID()}, dirty: false, at: 100};
    generic.project.title = '일반 초안 보존'; generic.project.engine = '1.3.0-rc.2';
    await open(page, '?exercise=l02');
    await drafts(page, [{key: 'current', value: generic}, {key: 'backup:legacy', value: {...generic, project: {...generic.project, title: '기존 일반 복구본'}}}]);
    const genericBefore = (await drafts(page)).filter(r => !r.key.startsWith('exercise:'));
    for (const exercise of CURRICULUM) {
        await open(page, `?exercise=${exercise.id}`);
        assert.equal(await page.locator('[data-scenario]').inputValue(), exercise.scenario);
        assert.deepEqual(await project(page), validateProject(scenario(exercise.scenario)), `${exercise.id}: fresh preset`);
        const goal = await page.locator('[data-goal]').textContent();
        assert(goal.includes(exercise.id.toUpperCase()) && goal.includes(exercise.title) && goal.includes(exercise.goal));
        assert.equal(await page.locator('[data-goal] details').evaluate(el => el.open), false, 'preset advice is separate from the exercise task');
        assert.equal(await page.locator('[data-terminal-node]').inputValue(), LESSONS.find(l => l.id === exercise.scenario).node);
    }
    for (const lesson of LESSONS) {
        await open(page, `?scenario=${lesson.id}`);
        assert.deepEqual(await project(page), validateProject(scenario(lesson.id)), `${lesson.id}: legacy URL`);
        assert.equal(await page.locator('[data-goal]').textContent(), lesson.goal);
    }
    console.log(`Fresh presets: ${CURRICULUM.length} exercise and ${LESSONS.length} scenario URLs passed.`);
    for (const query of ['?exercise=unknown', '?exercise=', '?exercise=L01', '?exercise=l24', '?exercise=__proto__', '?exercise=l02&exercise=l03', '?exercise=l02&exercise=l02', '?scenario=bad', '?scenario=lan&scenario=dhcp', '?exercise=%3Cimg%20src=x%20onerror=alert(1)%3E&title=%3Csvg%20onload=alert(1)%3E']) {
        await open(page, query);
        assert.equal(await page.locator('[data-title]').inputValue(), generic.project.title, query);
        assert((await page.locator('[data-status]').textContent()).includes('일반 실습'));
        assert.equal(await page.locator('[data-goal] img, [data-goal] svg, [data-status] img').count(), 0);
    }
    await open(page, '?exercise=l02&scenario=broken'); assert.deepEqual(await project(page), validateProject(scenario('lan')));
    await open(page, '?exercise=bad&scenario=dhcp'); assert.deepEqual(await project(page), validateProject(scenario('dhcp')));
    await open(page, '?exercise=l02&exercise=l03&scenario=broken'); assert.deepEqual(await project(page), validateProject(scenario('broken')));
    assert.equal(writes, 0, 'opening explicit links never creates server projects');
    assert.deepEqual(await drafts(page), genericBefore, 'opening links does not write drafts');
    console.log('URL fallback, injection and initial-draft protection passed.');

    await open(page, '?exercise=l02');
    const second = await context.newPage(); await open(second, '?exercise=l03');
    await page.locator('[data-node-form] input[name="address-0"]').fill('192.168.10.77/24');
    await page.locator('[data-node-form] button[type="submit"]').click(); await page.locator('[data-command]:not(:disabled)').waitFor();
    const draftTitle = 'L02 draft <img src=x onerror=alert(1)>';
    await title(page, draftTitle); await saved(page, 'l02', draftTitle);
    await title(second, 'L03 별도 초안'); await saved(second, 'l03', 'L03 별도 초안');
    const edited = await project(page), twoContexts = await drafts(page);
    assert.equal(twoContexts.filter(r => r.value.context === 'l02').length, 1);
    assert.equal(twoContexts.filter(r => r.value.context === 'l03').length, 1);
    assert.deepEqual(twoContexts.filter(r => !r.key.startsWith('exercise:')), genericBefore);

    await open(page, '?exercise=l02'); assert.deepEqual(await project(page), validateProject(scenario('lan')), 'reopen is fresh, not restored');
    await page.click('[data-action="projects"]');
    await page.locator('[data-backups]').evaluate(el => el.parentElement.open = true);
    await page.locator('[data-backups]').locator('button').first().waitFor();
    assert((await page.locator('[data-backups]').textContent()).includes(draftTitle));
    assert(!(await page.locator('[data-backups]').textContent()).includes('L03'));
    assert.equal(await page.locator('[data-backups] img').count(), 0, 'draft titles are escaped');
    await page.locator('[data-local-index]').first().click(); await page.locator('[data-command]:not(:disabled)').waitFor();
    assert.deepEqual(await project(page), edited, 'restore only the selected exercise draft');
    assert((await page.locator('[data-goal]').textContent()).startsWith('L02'));
    await page.selectOption('[data-scenario]', 'dhcp'); await page.click('[data-action="lesson"]'); await page.locator('[data-command]:not(:disabled)').waitFor();
    assert.equal((await project(page)).title, scenario('dhcp').title);
    assert((await page.locator('[data-goal]').textContent()).includes('L02'));
    await page.locator('[data-goal] summary').click(); assert(await page.locator('[data-goal] details').evaluate(el => el.open));
    assert((await page.locator('[data-goal] details').textContent()).includes(LESSONS.find(l => l.id === 'dhcp').goal));
    await page.click('[data-action="help"]'); assert(await page.locator('[data-help]').evaluate(el => el.open));
    await saved(page, 'l02', scenario('dhcp').title);
    const beforeCancel = await drafts(second);
    await second.click('[data-action="local-clear"]'); await second.locator('[data-dialog] button[value="cancel"]').click();
    await second.locator('[data-dialog]').waitFor({state: 'hidden'}); assert.deepEqual(await drafts(second), beforeCancel, 'cancel preserves drafts');
    await clearLocal(second);
    const afterClear = await drafts(page);
    assert.equal(afterClear.filter(r => r.value.context === 'l03').length, 0);
    assert.equal(afterClear.filter(r => r.value.context === 'l02').length, 2);
    assert.deepEqual(afterClear.filter(r => !r.key.startsWith('exercise:')), genericBefore);
    console.log('Separate tabs, restore, preset/help, delete confirmation and scoped deletion passed.');

    const entries = Array.from({length: 22}, (_, i) => ({key: `exercise:l02:backup:seed-${i}`, value: {...generic, context: 'l02', at: i + 1}}));
    entries.push({key: 'exercise:l18:backup:retained', value: {...generic, context: 'l18', at: 1}});
    await drafts(page, entries); await title(page, 'L02 retention'); await saved(page, 'l02', 'L02 retention');
    // Wait for the pruning transaction after the draft write.
    for (let i = 0; i < 100 && (await drafts(page)).filter(r => r.value.context === 'l02').length !== 20; i++) await page.waitForTimeout(20);
    assert.equal((await drafts(page)).filter(r => r.value.context === 'l02').length, 20);
    assert((await drafts(page)).some(r => r.key === 'exercise:l18:backup:retained'));
    assert.deepEqual((await drafts(page)).filter(r => !r.key.startsWith('exercise:')), genericBefore);
    await open(page); assert.equal(await page.locator('[data-title]').inputValue(), generic.project.title, 'generic menu still restores its own draft');
    const exerciseBefore = (await drafts(page)).filter(r => r.key.startsWith('exercise:'));
    await clearLocal(page); assert.deepEqual(await drafts(page), exerciseBefore, 'generic clear preserves exercise recovery');

    fs.mkdirSync(output, {recursive: true});
    await open(page, '?exercise=l02'); await page.screenshot({path: path.join(output, 'exercise-desktop.png'), fullPage: true});
    for (const id of ['l14', 'l20']) {
        await page.setViewportSize({width: 390, height: 844}); await open(page, `?exercise=${id}`);
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${id}: mobile overflow`);
        const fits = await page.locator('[data-goal]').evaluate(el => { const next = el.nextElementSibling.getBoundingClientRect(); return el.scrollWidth <= el.clientWidth && el.getBoundingClientRect().bottom <= next.top; });
        assert(fits, `${id}: goal wraps without overlap`);
        await page.locator('[data-goal] summary').click();
        assert(await page.locator('[data-goal]').evaluate(el => el.scrollWidth <= el.clientWidth && el.getBoundingClientRect().bottom <= el.nextElementSibling.getBoundingClientRect().top), `${id}: expanded preset advice fits mobile`);
        if (id === 'l20') assert(await page.locator('.nl-empty-label').evaluate(el => { const box = el.getBoundingClientRect(), viewport = el.closest('.nl-map-scroll').getBoundingClientRect(); return box.left >= viewport.left && box.right <= viewport.right; }), 'empty-state label stays in the mobile viewport');
        await page.screenshot({path: path.join(output, `exercise-mobile-${id}.png`), fullPage: true});
    }
    assert.deepEqual(errors, []); assert.deepEqual(external, []);
    console.log('Exercise browser checks passed: 23 fresh presets, 15 scenario URLs, invalid/duplicate/conflicting inputs, generic draft protection, same-preset isolation, scoped restore/prune/delete, help and mobile layout.');
    console.log('Screenshots:', output);
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => { await browser?.close(); server.close(); });
