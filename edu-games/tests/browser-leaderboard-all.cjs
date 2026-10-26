/* Actual ranking renderers/modules; isolated server-paginated HTTP fixtures. */
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const { spawnSync } = require('node:child_process');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || '../../_archived/readcontents-library/node_modules/playwright');
const root = path.resolve(__dirname, '..'), out = path.resolve(root, '../_notes/tmp/edu-games-ranking-all');
fs.mkdirSync(out, { recursive: true });
const rows = Array.from({ length: 180 }, (_, i) => ({ rank: i + 1, userId: i + 1, playerName: `학생 ${i + 1}`, score: i === 179 ? 0 : 1000 - i, correctCount: 2, durationSeconds: 30, accuracy: 80.5 }));
const teams = Array.from({ length: 60 }, (_, i) => ({ rank: i + 1, teamId: i + 1, teamName: `팀 ${i + 1}`, memberCount: 60, averageScore: 500 }));
let checks = 0;
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const json = data => { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(data)); };
    const paged = (items, field) => {
        const perPage = Number(url.searchParams.get('perPage') || 20), totalPages = Math.max(1, Math.ceil(items.length / perPage));
        assert([20, 50, 100].includes(perPage));
        const page = Math.max(1, Math.min(totalPages, Number(url.searchParams.get('page') || 1)));
        return { [field]: items.slice((page - 1) * perPage, page * perPage), pagination: { page, perPage, total: items.length, totalPages }, myRank: 7 };
    };
    if (url.pathname.includes('/leaderboards/game/')) {
        return json(paged(rows, 'leaderboard'));
    }
    if (url.pathname.includes('/leaderboards/team/')) return json(paged(rows.slice(0, 60), 'leaderboard'));
    if (url.pathname.endsWith('/leaderboards/teams')) return json(paged(teams, 'standings'));
    if (url.pathname.endsWith('/teams/mine')) return json({ team: { id: 1, name: '검증 팀', memberCount: 60, inviteCode: 'ABC123' } });
    if (url.pathname.startsWith('/blocks/') || url.pathname.startsWith('/assets/rankings/')) {
        const file = path.resolve(root, '.' + url.pathname);
        if (!file.startsWith(root + path.sep)) { res.statusCode = 404; res.end(); return; }
        res.setHeader('Content-Type', file.endsWith('.js') ? 'application/javascript' : 'text/css'); res.end(fs.readFileSync(file)); return;
    }
    const game = url.searchParams.get('game') || 'game-leaderboard';
    const p = spawnSync(process.env.PHP_BINARY || 'D:/php/php.exe', ['tests/browser-fixture.php'], { cwd: root, input: JSON.stringify({ game, action: 'html' }), encoding: 'utf8', maxBuffer: 20e6 });
    if (p.status !== 0) { res.statusCode = 500; res.end(p.stderr); return; }
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{margin:12px}main{max-width:1120px;margin:auto}</style><link rel="stylesheet" href="/blocks/${game}/style.css"><link rel="stylesheet" href="/assets/rankings/pager.css"><main>${p.stdout}</main><script src="/assets/rankings/pager.js"></script><script type="module" src="/blocks/${game}/view.js"></script></html>`);
});
async function run() {
    await new Promise(r => server.listen(0, '127.0.0.1', r));
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const page = await browser.newPage(), errors = []; page.on('pageerror', e => errors.push(e.message));
    try {
        for (const game of ['game-leaderboard', 'group-leaderboard']) {
            await page.goto(`http://127.0.0.1:${server.address().port}/?game=${game}`);
            const rootNode = page.locator(`[data-${game}]`), dataRows = rootNode.locator('.eg-board-row:not(.eg-board-head)');
            await page.waitForFunction(selector => document.querySelector(selector).querySelectorAll('.eg-board-row:not(.eg-board-head)').length === 20, `[data-${game}]`);
            assert.equal(await dataRows.count(), 20); assert.match(await dataRows.last().textContent(), /학생 20/);
            assert.match(await rootNode.locator('[data-field=status]').textContent(), /180명/); checks += 3;
            const board = rootNode.locator('[data-field=leaderboard]'), nav = board.locator('.edu-rank-pagination');
            const visible = board.locator('[data-rank-row]:visible');
            async function expectPage(number, size, total) {
                await page.waitForFunction(({ selector, expected }) => document.querySelector(selector).querySelector('.edu-rank-pagination output')?.textContent === expected, { selector: `[data-${game}]`, expected: `${number} / ${Math.ceil(total / size)}` });
                assert.equal(await visible.count(), Math.min(size, total - (number - 1) * size));
                assert.equal(await visible.first().locator('strong').textContent(), String((number - 1) * size + 1));
                assert.equal(await nav.locator('output').textContent(), `${number} / ${Math.ceil(total / size)}`);
                assert.equal(await nav.locator('[data-rank-page=first]').isDisabled(), number === 1);
                assert.equal(await nav.locator('[data-rank-page=last]').isDisabled(), number === Math.ceil(total / size)); checks += 5;
            }
            await expectPage(1, 20, 180);
            await nav.locator('[data-rank-page=next]').click(); await expectPage(2, 20, 180);
            await nav.locator('[data-rank-page=last]').click(); await expectPage(9, 20, 180);
            assert.match(await visible.last().textContent(), /학생 180/); checks++;
            await nav.locator('[data-rank-page=previous]').press('Enter'); await expectPage(8, 20, 180);
            await nav.locator('[data-rank-page=first]').press('Space'); await expectPage(1, 20, 180);
            await nav.locator('select').selectOption('50'); await expectPage(1, 50, 180);
            await nav.locator('[data-rank-page=last]').click(); await expectPage(4, 50, 180);
            await nav.locator('select').selectOption('100'); await expectPage(1, 100, 180);
            await nav.locator('[data-rank-page=last]').click(); await expectPage(2, 100, 180);
            // A new game resets the page, but retains the selected page size.
            await rootNode.locator('[data-game-id]:not(.is-active)').first().click();
            await page.waitForFunction(selector => document.querySelector(selector).querySelector('.edu-rank-pagination output')?.textContent === '1 / 2', `[data-${game}]`);
            await expectPage(1, 100, 180);
            await nav.locator('select').selectOption('20');
            for (const width of [320, 360, 768, 1280]) {
                await page.setViewportSize({ width, height: 900 });
                assert(await rootNode.evaluate(r => r.scrollWidth <= r.clientWidth + 1), `${game} width ${width}`); checks++;
                assert(await nav.evaluate(r => r.scrollWidth <= r.clientWidth + 1), `${game} pager width ${width}`); checks++;
                if (width === 360 || width === 1280) { await nav.scrollIntoViewIfNeeded(); await page.screenshot({ path: path.join(out, `${game}-${width}.png`) }); }
            }
            if (game === 'group-leaderboard') {
                for (const scope of ['teams', 'team']) {
                    await rootNode.locator(`[data-scope=${scope}]`).click();
                    await page.waitForFunction(() => document.querySelector('[data-group-leaderboard] .edu-rank-pagination output')?.textContent === '1 / 3');
                    assert.equal(await dataRows.count(), 20); assert.match(await rootNode.locator('[data-field=status]').textContent(), /60/); checks += 2;
                    await expectPage(1, 20, 60);
                    assert.match(await nav.locator('.edu-rank-range').textContent(), scope === 'teams' ? /60팀/ : /60명/); checks++;
                    await nav.locator('[data-rank-page=last]').click(); await expectPage(3, 20, 60);
                }
            }
            const latest = await rootNode.evaluate(async (root, game) => {
                const item = { rank: 1, playerName: '최신 결과', score: 100, teamName: '최신 결과', teamId: 1 };
                const pending = [];
                if (game === 'game-leaderboard') {
                    const instance = root.__GameLeaderboard, original = window.fetch;
                    window.fetch = () => new Promise(resolve => pending.push(resolve));
                    try {
                        instance.currentGame = 'slow'; const old = instance.load();
                        instance.currentGame = 'latest'; const fresh = instance.load();
                        pending[1]({ ok: true, json: async () => ({ leaderboard: [item] }) }); await fresh;
                        pending[0]({ ok: false, json: async () => ({ message: '이전 요청 오류' }) }); await old;
                    } finally { window.fetch = original; }
                } else {
                    const instance = root.__GroupLeaderboard, original = instance.api;
                    instance.api = () => new Promise(resolve => pending.push(resolve));
                    try {
                        instance.scope = 'overall'; instance.loadLeaderboard();
                        instance.scope = 'teams'; instance.loadLeaderboard();
                        pending[1]({ standings: [item] }); await Promise.resolve();
                        pending[0]({ leaderboard: [{ ...item, playerName: '이전 결과' }] }); await Promise.resolve();
                    } finally { instance.api = original; }
                }
                return root.querySelector('[data-field=leaderboard]').textContent;
            }, game);
            assert.match(latest, /최신 결과/); assert.doesNotMatch(latest, /이전 요청 오류|이전 결과/); checks += 2;
        }
        assert.deepEqual(errors, []); checks++;
        console.log(`PASS: ${checks} browser ranking checks; all 180 participants, pagination, 60 teams/members, desktop/mobile. ${out}`);
    } finally { await browser.close(); await new Promise(r => server.close(r)); }
}
run().catch(e => { console.error(e); server.close(); process.exitCode = 1; });
