/* Real ranking render methods with isolated browser/HTTP data, not live WordPress. */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || '../../_archived/readcontents-library/node_modules/playwright');
const root = path.resolve(__dirname, '..');
let checks = 0;
function method(file, name) {
    const lines = fs.readFileSync(path.join(root, file), 'utf8').split(/\r?\n/);
    const start = lines.findIndex(line => new RegExp(`^\\s+(async )?${name}\\(`).test(line));
    assert(start >= 0, `${file}: ${name}`);
    const indent = lines[start].match(/^\s*/)[0];
    const end = lines.findIndex((line, index) => index > start && line === indent + '}');
    return lines.slice(start, end + 1).join('\n');
}
async function run() {
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const page = await browser.newPage(), errors = [];
    page.on('pageerror', error => errors.push(error.message));
    try {
        await page.setContent('<!doctype html><html lang="ko"><main></main></html>');
        await page.addStyleTag({ path: path.join(root, 'assets/rankings/pager.css') });
        await page.addScriptTag({ path: path.join(root, 'assets/rankings/pager.js') });
        await page.addScriptTag({ path: path.join(root, 'assets/strategy/board-views.js') });
        const games = ['ai-classifier-lab', 'binary-arcade', 'character-code', 'code-typing', 'data-extraction', 'data-structure-lab', 'data-visualization', 'device-matching', 'keyword-rain', 'missing-values', 'outlier-detection'];
        for (const game of games) {
            await page.evaluate(({ source, game }) => {
                const host = document.querySelector('main'); host.innerHTML = '<p data-status></p><div data-board></div>';
                const rows = Array.from({ length: 43 }, (_, i) => ({ rank: i === 20 ? 20 : i + 1, userId: i + 1, playerName: i === 0 ? '<img src=x>' : `학생 ${i + 1}`, score: 1000 - i }));
                const render = new Function('escapeHtml', `return ({${source}}).renderLeaderboard;`)(window.EduStrategyUI.esc);
                const fields = { leaderboard: host.querySelector('[data-board]'), rankStatus: host.querySelector('[data-status]') };
                window.testRanking = { rows, render, context: { fields, config: { gameId: game, currentUserId: 21 }, leaderboard: rows, state: { leaderboard: rows, myRank: 20 }, setFieldText: (key, value) => { fields[key].textContent = value; } } };
                render.call(window.testRanking.context);
            }, { source: method(`blocks/${game}/view.js`, 'renderLeaderboard'), game });
            const board = page.locator('[data-board]'), visible = board.locator('[data-rank-row]:visible');
            assert.equal(await visible.count(), 20, `${game}: first page size`); assert.equal(await board.locator('img').count(), 0); checks += 2;
            await board.locator('[data-rank-page=next]').click();
            assert.equal(await visible.first().locator('strong').textContent(), '20'); checks++;
            await board.locator('[data-rank-page=last]').click(); assert.equal(await visible.count(), 3); checks++;
            await page.evaluate(() => testRanking.render.call(testRanking.context));
            assert.equal(await board.locator('output').textContent(), '3 / 3'); checks++;
            // A shrinking result clamps the page; an empty refresh removes the pager.
            await page.evaluate(() => { testRanking.rows.splice(21); testRanking.render.call(testRanking.context); });
            assert.equal(await board.locator('output').textContent(), '2 / 2'); assert.equal(await visible.count(), 1); checks += 2;
            await page.evaluate(() => { testRanking.rows.splice(0); testRanking.render.call(testRanking.context); });
            assert.equal(await board.locator('.edu-rank-pagination').count(), 0); checks++;
        }
        for (const game of ['strategy', 'warehouse', 'learning']) {
            await page.evaluate(async ({ game, source, tableSource }) => {
                const host = document.querySelector('main'); host.innerHTML = '<div data-role="collection"></div><div data-role="map"></div><div data-role="personal"></div><div data-role="leaders"></div><div data-role="history"></div>';
                const rows = Array.from({ length: 43 }, (_, i) => ({ rank: i === 20 ? 20 : i + 1, name: `학생 ${i + 1}`, score: i === 20 ? 981 : 1000 - i }));
                const levels = { a: { id: 'a', number: 1, title: 'A', unlocked: true, score: 0, stars: 0 }, b: { id: 'b', number: 2, title: 'B', unlocked: true, score: 0, stars: 0 } };
                const result = { level: 'a', stars: 0, maxStars: 6, levels, leaderboard: rows, history: Array.from({ length: 12 }, () => ({ started_at: '2026-09-12', mode: 'assessment', level: 'a', completed_at: true, summary: { firstCorrect: 1, total: 1, finalCorrect: 1 } })) };
                const { esc, stars, table } = window.EduStrategyUI;
                const name = game === 'learning' ? 'history' : 'summary';
                const render = new Function('esc', 'escape', 'stars', 'table', 'modes', 'levels', `return ({${source}}).${name};`)(esc, esc, stars, table, { assessment: '평가' }, { a: 'A', b: 'B' });
                const context = { root: host, config: { game: 'data-extraction', gameId: 'data-extraction' }, level: { value: 'a', options: [{ value: 'a' }, { value: 'b' }] }, field: key => host.querySelector(`[data-role="${key}"]`), message: text => { throw Error(text); }, api: async (action, query) => ({ ...result, leaderboard: rows.slice((query.page-1)*query.perPage,query.page*query.perPage), pagination: { page: query.page, perPage: query.perPage, total: rows.length, totalPages: Math.ceil(rows.length/query.perPage) } }) };
                context[name] = () => render.call(context);
                if (tableSource) context.table = new Function('escape', `return ({${tableSource}}).table;`)(esc);
                window.testTableRanking = { render, context, result }; await render.call(context);
            }, { game, source: method(`assets/${game}/view.js`, game === 'learning' ? 'history' : 'summary'), tableSource: game === 'learning' ? method('assets/learning/view.js', 'table') : null });
            const board = page.locator(game === 'learning' ? '[data-assessment-ranking]' : '[data-role=leaders]');
            const pager = page.locator(game === 'learning' ? '[data-role=history]' : '[data-role=leaders]');
            assert.equal(await board.locator('tbody tr:visible').count(), 20); checks++;
            await pager.locator('[data-rank-page=next]').click();
            await page.waitForFunction(() => document.querySelector('.edu-rank-pagination output')?.textContent === '2 / 3');
            assert.equal(await board.locator('tbody tr:visible').first().locator('td').first().textContent(), '20'); checks++;
            await pager.locator('[data-rank-page=last]').click();
            await page.waitForFunction(() => document.querySelector('.edu-rank-pagination output')?.textContent === '3 / 3');
            assert.equal(await board.locator('tbody tr:visible').count(), 3); checks++;
            if (game === 'learning') { assert.equal(await page.locator('[data-role=history] > section > .edu-learn-table-wrap tbody tr:visible').count(), 12); checks++; }
            else {
                await page.evaluate(async () => { await testTableRanking.render.call(testTableRanking.context); });
                assert.equal(await board.locator('output').textContent(), '3 / 3'); checks++;
                await page.evaluate(async () => { testTableRanking.context.level.value = 'b'; await testTableRanking.render.call(testTableRanking.context); });
                assert.equal(await board.locator('output').textContent(), '1 / 3'); checks++;
            }
        }
        // Independent pagers, duplicate initialization, native keyboard activation, and small totals.
        await page.evaluate(() => {
            const host = document.querySelector('main'); host.innerHTML = '<div id="one"></div><div id="two"></div>';
            for (const id of ['one', 'two']) {
                const container = document.getElementById(id);
                container.innerHTML = Array.from({ length: id === 'one' ? 21 : 1 }, (_, i) => `<div data-rank-row>${i + 1}</div>`).join('');
                EduGamesRankPager.mount(container, id); EduGamesRankPager.mount(container, id);
            }
        });
        await page.locator('#one [data-rank-page=next]').press('Enter');
        assert.equal(await page.locator('#one [data-rank-row]:visible').textContent(), '21');
        assert.equal(await page.locator('#two [data-rank-row]:visible').textContent(), '1');
        assert.equal(await page.locator('#one .edu-rank-pagination').count(), 1);
        assert.equal(await page.locator('#two [data-rank-page=next]').isDisabled(), true);
        assert.equal(await page.evaluate(() => document.activeElement.matches('#one [data-rank-size]')), true); checks += 5;
        assert.deepEqual(errors, []); checks++;
        console.log(`PASS: ${checks} shared pagination checks; 11 inline games, strategy/warehouse/assessment, ties, empty/shrinking results, focus, independent instances.`);
    } finally { await browser.close(); }
}
run().catch(error => { console.error(error); process.exitCode = 1; });
