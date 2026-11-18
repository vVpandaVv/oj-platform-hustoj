const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const workspace = path.resolve(__dirname, '../..');
const output = path.join(workspace, '_notes/verification/2026-11-16/teaching-tools');
fs.mkdirSync(output, { recursive: true });
const php = process.env.PHP_BINARY || 'D:/php/php.exe';
let database = { rows: [], sequence: 0 }, failHistory = false, lastSession = null;
let saved = null, confirmations = 0;
const students = Array.from({ length: 31 }, (_, i) => ({ id: i + 10, displayName: '학생' + (i + 1), managementName: '3반 ' + (i + 1) + '번' }));
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/seating') {
        const result = spawnSync(php, [path.join(__dirname, 'render-fixture.php'), 'seating']);
        assert.equal(result.status, 0, result.stderr.toString());
        res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(result.stdout); return;
    }
    if (url.pathname.startsWith('/api/')) {
        const chunks = [];
        req.on('data', chunk => chunks.push(chunk));
        req.on('end', () => {
            try {
                const body = Buffer.concat(chunks).toString();
                const params = body ? JSON.parse(body) : {};
                let result = {};
                if (url.pathname.startsWith('/api/seating-history')) {
                    const method = url.pathname.endsWith('/query') ? 'query' : 'confirm';
                    const value = spawnSync(php, [path.join(__dirname, 'seating-history.php'), '--request', Buffer.from(JSON.stringify({ method, params, db: database, fail: failHistory })).toString('base64')]);
                    assert.equal(value.status, 0, value.stdout.toString() + value.stderr.toString());
                    const data = JSON.parse(value.stdout.toString());
                    database = data.db; result = data.result; res.statusCode = data.status;
                    if (method === 'confirm') confirmations++;
                } else if (url.pathname === '/api/contexts') { result = { items: [{ type: 'group', id: '3', revision: 'r1', label: '3반' }] }; }
                else if (url.pathname.includes('/roster')) { result = { students }; }
                else if (url.pathname === '/api/sessions' && req.method === 'POST') {
                    assert(!('history' in params.state), 'Private history must not enter student sessions');
                    lastSession = params; result = { joinCode: 'SEAT01' };
                } else if (url.pathname === '/api/sessions/SEAT01/state') { lastSession.state = params.state; }
                else if (url.pathname === '/api/seating-layouts') {
                    if (req.method === 'POST') { saved = params; result = { id: 1 }; }
                    else { result = { items: saved ? [{ id: 1, ...saved }] : [] }; }
                } else if (url.pathname === '/api/seating-layouts/1') { result = saved; }
                res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(result));
            } catch (error) { res.statusCode = 500; res.end(JSON.stringify({ message: error.message })); }
        });
        return;
    }
    const file = path.resolve(workspace, '.' + url.pathname);
    if (!file.startsWith(workspace + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.statusCode = 404; res.end(); return; }
    res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript'); res.end(fs.readFileSync(file));
});
const field = name => '[data-field="' + name + '"]';
const action = name => '[data-action="' + name + '"]';
const results = [];
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;
    for (const [label, executablePath] of [['chrome', 'C:/Program Files/Google/Chrome/Application/chrome.exe'], ['edge', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']]) {
        const browser = await chromium.launch({ executablePath, headless: true });
        try {
            const page = await browser.newPage({ viewport: { width: 1366, height: 1000 } });
            const errors = []; page.on('pageerror', error => errors.push(error.message));
            await page.goto(origin + '/seating');
            await page.waitForFunction(() => document.querySelector('[data-field="context-select"]').options.length === 2);
            await page.selectOption(field('context-select'), { label: '3반' });
            await page.click(action('load-roster'));
            await page.waitForFunction(() => document.querySelector('[data-field="students"]').value.split('\n').length === 31);
            await page.fill(field('rows'), '6');
            await page.fill(field('history-group'), label + '-class');
            await page.locator('details.htt-collapsible summary').click();
            await page.fill(field('front-names'), students.slice(0, 10).map(s => '#' + s.id).join('\n'));
            let repeatedSeats = 0, repeatedPairs = 0;
            const prior = [];
            for (let round = 0; round < 8; round++) {
                await page.click(action('arrange'));
                await page.locator(field('result')).waitFor({ state: 'visible' });
                assert.equal(lastSession.state.seats.filter(s => s.student).length, 31);
                for (const seat of lastSession.state.seats) if (seat.student && seat.student.id < 20) assert(seat.row < 2);
                const map = new Map(lastSession.state.seats.filter(s => s.student).map(s => [s.student.id, s]));
                const pairs = new Set(lastSession.state.seats.filter(s => s.student && s.col % 2 === 0).flatMap(s => {
                    const mate = lastSession.state.seats.find(t => t.row === s.row && t.col === s.col + 1);
                    return mate?.student ? [[s.student.id, mate.student.id].sort((a, b) => a - b).join(':')] : [];
                }));
                for (const old of prior) {
                    for (const [id, seat] of map) if (old.map.get(id)?.id === seat.id) repeatedSeats++;
                    for (const pair of pairs) if (old.pairs.has(pair)) repeatedPairs++;
                }
                prior.unshift({ map, pairs }); prior.splice(5);
                const before = database.rows.length;
                assert((await page.textContent(field('history-status'))).includes('미확정'));
                if (round === 0) {
                    failHistory = true;
                    await page.click(action('confirm-arrangement'));
                    await page.waitForFunction(() => document.querySelector('[data-field="history-status"]').textContent.includes('저장하지 못했습니다'));
                    assert.equal(await page.locator(action('confirm-arrangement')).isEnabled(), true);
                    assert.equal(database.rows.length, before);
                    failHistory = false;
                }
                await page.click(action('confirm-arrangement'));
                await page.waitForFunction(() => document.querySelector('[data-action="confirm-arrangement"]').textContent === '확정됨');
                assert(database.rows.length >= before);
                assert.equal(await page.locator(action('confirm-arrangement')).isDisabled(), true);
                if (round === 7) break;
                await page.click(action('new-arrangement')); await page.locator(field('setup')).waitFor({ state: 'visible' });
            }
            assert.equal(database.rows.length, label === 'chrome' ? 5 : 10);
            assert.equal(repeatedSeats, 0); assert.equal(repeatedPairs, 0);
            const beforeEdit = database.rows.length;
            await page.locator(field('result') + ' ' + field('student-name-mode')).selectOption('management');
            assert((await page.textContent(field('board'))).includes('3반'));
            assert.equal(await page.textContent(action('confirm-arrangement')), '확정됨', 'Relabeling must not create a new round');
            const cells = page.locator(field('board') + ' .htt-seat:not(.is-empty)');
            await cells.nth(0).click(); await cells.nth(1).click();
            assert.equal(await page.textContent(action('confirm-arrangement')), '배치 확정');
            await page.click(action('confirm-arrangement'));
            await page.waitForFunction(() => document.querySelector('[data-action="confirm-arrangement"]').textContent === '확정됨');
            assert.equal(database.rows.length, beforeEdit, 'Same draft edit must update one history entry');
            await page.fill(field('layout-name'), label + ' layout');
            await page.click(action('save-layout'));
            await page.waitForFunction(() => document.querySelector('[data-field="save-layout-status"]').textContent.includes('저장했습니다'));
            await page.screenshot({ path: path.join(output, label + '-seating-desktop.png'), fullPage: true });
            await page.setViewportSize({ width: 390, height: 844 });
            await page.screenshot({ path: path.join(output, label + '-seating-mobile.png'), fullPage: true });
            const horizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
            assert.equal(horizontalOverflow, false, 'Mobile page overflow');
            await page.reload();
            await page.waitForFunction(() => document.querySelector('[data-field="layout-select"]').options.length === 2);
            await page.fill(field('history-group'), label + '-class');
            await page.selectOption(field('layout-select'), '1');
            const confirmedBeforeLoad = confirmations;
            await page.click(action('load-layout')); await page.locator(field('result')).waitFor({ state: 'visible' });
            assert.equal(confirmations, confirmedBeforeLoad, 'Loading a layout must not auto-confirm');
            assert((await page.textContent(field('history-status'))).includes('5회'));
            await page.click(action('new-arrangement')); await page.locator(field('setup')).waitFor({ state: 'visible' });
            await page.fill(field('students'), '직접입력 가\n직접입력 나');
            failHistory = true;
            await page.click(action('arrange'));
            await page.waitForFunction(() => document.querySelector('[data-field="messages"]').textContent.includes('읽지 못했습니다'));
            assert.equal(await page.locator(field('setup')).isVisible(), true, 'History failure must not silently generate an unprotected draw');
            assert.equal(await page.locator(action('arrange')).isEnabled(), true);
            failHistory = false;
            await page.click(action('arrange')); await page.locator(field('result')).waitFor({ state: 'visible' });
            await page.click(action('confirm-arrangement'));
            await page.waitForFunction(() => document.querySelector('[data-action="confirm-arrangement"]').textContent === '확정됨');
            await page.reload();
            await page.fill(field('students'), '직접입력 가\n직접입력 나');
            await page.fill(field('history-group'), label + '-class');
            await page.click(action('arrange')); await page.locator(field('result')).waitFor({ state: 'visible' });
            assert((await page.textContent(field('history-status'))).includes('5회'), 'History must survive reload and new manual IDs');
            assert.deepEqual(errors, []);
            results.push({ browser: label, rounds: 8, repeatedSeats, repeatedPairs, frontendErrors: errors });
        } finally { await browser.close(); }
    }
    fs.writeFileSync(path.join(output, 'browser-seating.json'), JSON.stringify(results, null, 2) + '\n');
    console.log(JSON.stringify(results, null, 2));
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => server.close());
