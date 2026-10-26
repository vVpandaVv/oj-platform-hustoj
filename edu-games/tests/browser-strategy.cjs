/* Real PHP board rules/renderers and browser assets; isolated HTTP/storage doubles. */
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { spawnSync } = require('node:child_process');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || '../../_archived/readcontents-library/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const out = path.resolve(root, '../_notes/tmp/edu-games-strategy-v2-preview');
fs.mkdirSync(out, { recursive: true });
const run = (file, input) => {
    const p = spawnSync(process.env.PHP_BINARY || 'D:/php/php.exe', [file], { cwd: root, input: JSON.stringify(input), encoding: 'utf8', maxBuffer: 20e6 });
    if (p.status !== 0) throw Error(p.stderr || p.stdout);
    return p.stdout;
};
const fixture = input => JSON.parse(run('tests/strategy-fixture.php', input));
const games = ['data-extraction', 'missing-values', 'outlier-detection', 'data-visualization', 'ai-classifier-lab', 'ai-model-lab'];
const catalogs = Object.fromEntries(games.map(game => [game, fixture({ game, action: 'catalog' })]));
const sessions = new Map(), best = {}, summaries = {};
const learningSessions = new Map();
let id = 0, fault = null, checks = 0;
const server = http.createServer(async (req, res) => {
    const reply = (data, status = 200) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(data)); };
    try {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname.startsWith('/plugins/edu-games/')) {
            const file = path.resolve(root, decodeURIComponent(url.pathname.slice('/plugins/edu-games/'.length)));
            if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) return reply({}, 404);
            res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'application/javascript' : 'text/css' }); res.end(fs.readFileSync(file)); return;
        }
        if (req.method === 'GET') {
            const game = url.searchParams.get('game') || games[0];
            const html = run('tests/browser-fixture.php', { game, action: 'html', primary: true });
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            res.end(`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>전략 보드 검증</title><style>body{margin:0;padding:12px;font-family:Arial,sans-serif}main{max-width:1120px;margin:auto}button,input,select{font:inherit}</style><link rel="stylesheet" href="/plugins/edu-games/assets/strategy/style.css"><link rel="stylesheet" href="/plugins/edu-games/assets/learning/style.css"><link rel="stylesheet" href="/plugins/edu-games/assets/rankings/pager.css"><main>${html}</main><script src="/plugins/edu-games/assets/rankings/pager.js"></script><script src="/plugins/edu-games/assets/vendor/chartjs/chart.umd.min.js"></script><script src="/plugins/edu-games/assets/strategy/board-views.js"></script><script src="/plugins/edu-games/assets/strategy/view.js"></script><script src="/plugins/edu-games/assets/learning/view.js"></script></html>`); return;
        }
        let raw = ''; for await (const chunk of req) raw += chunk;
        const input = JSON.parse(raw || '{}');
        const learning = url.pathname.match(/\/learning\/([a-z-]+)\/(start|resume|summary)$/);
        if (learning) {
            const [, game, action] = learning;
            if (action === 'summary') return reply({ history: [], leaderboard: [], level: input.level });
            let record;
            if (action === 'start') {
                const data = JSON.parse(run('tests/browser-fixture.php', { game, action, mode: input.mode, level: input.level }));
                record = { ...data.view, mode: input.mode, level: input.level, token: `learning-${++id}`, revision: 0 }; learningSessions.set(record.token, record);
            } else record = learningSessions.get(input.token);
            return record ? reply(record) : reply({ message: '연습 기록 만료' }, 404);
        }
        const match = url.pathname.match(/\/strategy\/([a-z-]+)\/(start|update|resume|summary|archive)$/);
        if (!match) return reply({});
        const [, game, action] = match;
        if (action === 'archive') return reply({ records: [{ version: 'strategy-2026-09-v1', level: 'board-01', score: 980, attempts: 2 }] });
        if (action === 'summary') {
            summaries[game] = (summaries[game] || 0) + 1;
            let open = true, stars = 0;
            const levels = Object.fromEntries(Object.entries(catalogs[game].levels).map(([key, d], i) => {
                const score = best[`${game}:${key}`] || 0, count = score >= 975 ? 3 : score >= 850 ? 2 : score > 0 ? 1 : 0;
                const level = { id: key, number: i + 1, title: d.title, score, stars: count, unlocked: open };
                open = open && score > 0; stars += count; return [key, level];
            }));
            return reply({ levels, stars, maxStars: Object.keys(levels).length * 3, leaderboard: [], level: input.level });
        }
        let record;
        if (action === 'start') {
            const data = fixture({ game, action, level: input.level });
            record = { ...data, game, token: String(++id).padStart(48, '0'), revision: 0 }; sessions.set(record.token, record);
        } else {
            record = sessions.get(input.token);
            if (!record || record.game !== game) return reply({ message: '도전이 만료되었습니다.' }, 404);
            if (action === 'update') {
                if (fault === 'before') { fault = null; return reply({ message: '일시 저장 오류' }, 503); }
                if (record.last !== input.requestId) {
                    if (record.revision !== input.revision) return reply({ message: '다른 창에서 진행되었습니다.' }, 409);
                    const data = fixture({ game, action, state: record.state, move: input.move });
                    if (data.error) return reply({ message: data.error }, 400);
                    Object.assign(record, data, { revision: record.revision + 1, last: input.requestId });
                    if (record.state.status === 'won') { const key = `${game}:${record.state.level}`; best[key] = Math.max(best[key] || 0, record.state.score); }
                }
                if (fault === 'after') { fault = null; return reply({ message: '저장 응답 연결 오류' }, 503); }
            }
        }
        reply({ ...record.view, token: record.token, revision: record.revision });
    } catch (e) { reply({ message: e.message }, 500); }
});
async function ready(page) { await page.waitForFunction(() => document.querySelector('[data-strategy]').getAttribute('aria-busy') === 'false'); }
async function state(page) { return page.locator('[data-strategy]').evaluate(r => r.__Strategy.state); }
async function reveal(control) { await control.evaluate(element => { for(let node=element.parentElement;node;node=node.parentElement)if(node.tagName==='DETAILS')node.open=true; }); }
async function move(page, m) {
    const before = await state(page) || { revision:-1, moves:0 };
    if(m.record !== undefined && m.type !== 'mark') await page.locator(`[data-record="${m.record}"]`).first().click();
    if (before.plots?.length > 1) await page.locator(`[data-panel="${m.panel || 0}"]`).click();
    if (m.type === 'place') {
        await page.locator(`[data-pick="${m.row}"]`).click();
        if (m.x !== undefined) await page.locator(`[data-point-x="${m.x}"][data-point-y="${m.y}"]`).click();
        else await page.locator(`[data-place="${m.slot}"]`).click();
    } else {
        const buttons = page.locator('[data-move]'); let found = false;
        for (let i = 0; i < await buttons.count(); i++) {
            if (require('node:util').isDeepStrictEqual(JSON.parse(await buttons.nth(i).getAttribute('data-move')), m)) {
                await reveal(buttons.nth(i));
                if (m.type === 'load' && before.moves === 0) { await buttons.nth(i).focus(); await buttons.nth(i).press('Enter'); }
                else await buttons.nth(i).click();
                found = true; break;
            }
        }
        if (!found) {
            const forms = page.locator(`form[data-type="${m.type}"]`);
            for (let i = 0; i < await forms.count(); i++) {
                const f = forms.nth(i), params = JSON.parse(await f.getAttribute('data-params'));
                if (!Object.entries(params).every(([k, v]) => m[k] === v)) continue;
                await reveal(f);
                for (const [key, value] of Object.entries(m)) {
                    if (key === 'type' || key in params) continue;
                    const field = f.locator(`[name="${key}"]`);
                    if (await field.evaluate(e => e.tagName === 'SELECT')) await field.selectOption(String(value));
                    else if (await field.getAttribute('type') === 'range') { await field.focus(); await field.press('Home'); for (let n = 1; n < value; n++) await field.press('ArrowRight'); }
                    else await field.fill(String(value));
                }
                await f.locator('[type=submit]').click(); found = true; break;
            }
        }
        assert(found, `UI control missing ${JSON.stringify(m)}`);
    }
    await page.waitForFunction(revision => (document.querySelector('[data-strategy]').__Strategy.state?.revision ?? -1) > revision, before.revision);
    await ready(page); checks++;
    if (m.type === 'load' && before.moves === 0) { assert(await page.evaluate(() => !!document.activeElement.closest('.es-slot')), 'keyboard focus remains on work slot'); checks++; }
}
async function layout(page, game, phase) {
    for (const width of (process.env.STRATEGY_UX ? [360,1280] : [320, 360, 768, 1280, 1440])) {
        await page.setViewportSize({ width, height: 900 });
        await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${game} ${phase} overflow ${width}`);
        assert.deepEqual(await page.locator('[data-strategy]').evaluate(root => [...root.querySelectorAll('button')].filter(e => e.offsetWidth && (e.scrollWidth > e.clientWidth + 2 || e.scrollHeight > e.clientHeight + 2)).map(e => e.textContent)), [], `${game} button clipping`);
        if (width === 360 || width === 1280) await page.screenshot({ path: path.join(out, `${game}-${phase}-${width}.png`), fullPage: true });
        checks += 2;
    }
}
async function main() {
    await new Promise(resolve => server.listen(process.env.STRATEGY_PREVIEW ? Number(process.env.STRATEGY_PREVIEW_PORT || 0) : 0, '127.0.0.1', resolve));
    if(process.env.STRATEGY_PREVIEW){console.log(`PREVIEW http://127.0.0.1:${server.address().port}/?game=outlier-detection`);return;}
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage(), errors = [];
    page.on('pageerror', e => errors.push(e.message)); page.on('dialog', d => d.accept());
    const base = `http://127.0.0.1:${server.address().port}`;
    try {
        if(process.env.STRATEGY_UX) {
            for(const game of games) {
                console.log(`Intuitive play: ${game}`);
                await page.goto(`${base}/?game=${game}`);await page.waitForFunction(()=>document.querySelector('[data-strategy]').__Strategy.progress);
                assert.equal(await state(page),null);checks++;
                await layout(page,game,'intuitive-preview');
                if(game==='ai-classifier-lab'){
                    const cut=page.locator('form[data-type=boundary] [name=cut]').first();await cut.fill('7');await cut.dispatchEvent('input');
                    assert.match(await page.locator('[data-boundary-preview]').textContent(),/7/);assert.equal(await state(page),null);checks+=2;
                }
                for(const action of catalogs[game].solutions['board-01'])await move(page,action);
                assert.equal((await state(page)).score,1000);checks++;
                await layout(page,game,'intuitive-won');
            }
            await page.goto(`${base}/?game=outlier-detection`);await page.waitForFunction(()=>document.querySelector('[data-strategy]').__Strategy.progress);
            await move(page,{type:'mark',record:3});await move(page,{type:'mark',record:5});await move(page,{type:'investigate'});
            await move(page,{type:'inspect',record:3,evidence:'context'});
            assert.equal((await state(page)).reviews[3].supported,false);checks++;
            assert(await page.locator('.es-decision.keep').isDisabled());checks++;
            await move(page,{type:'inspect',record:3,evidence:'unit'});
            await move(page,{type:'route',record:3,route:'keep'});
            assert.equal((await state(page)).reviews[3].state,'recheck');checks++;
            assert.match(await page.locator('[data-verdict]').textContent(),/오류가 확인/);checks++;
            await layout(page,'outlier-detection','evidence-feedback');
            await move(page,{type:'route',record:3,route:'quarantine'});
            await move(page,{type:'inspect',record:5,evidence:'context'});
            await move(page,{type:'route',record:5,route:'quarantine'});
            assert.equal((await state(page)).reviews[5].state,'recheck');checks++;
            await move(page,{type:'route',record:5,route:'keep'});
            assert.equal((await state(page)).reviews[5].state,'settled');checks++;
            await move(page,{type:'finish'});assert((await state(page)).score<1000);checks++;
            await page.goto(`${base}/?game=outlier-detection`);
            for(const action of catalogs['outlier-detection'].solutions['board-01'])await move(page,action.type==='inspect'?{...action,evidence:'repeat'}:action);
            assert.equal((await state(page)).score,880,'two measurements cannot earn perfect score');checks++;
            await layout(page,'outlier-detection','measurement-assisted');
            await page.goto(`${base}/?game=outlier-detection`);
            await move(page,{type:'investigate'});await move(page,{type:'inspect',record:0,evidence:'repeat'});await move(page,{type:'inspect',record:1,evidence:'repeat'});
            await page.locator('[data-record="2"]').click();
            assert(await page.locator('[data-move]').filter({hasText:'다시 측정'}).isDisabled());checks++;
            for(const level of Object.keys(catalogs['outlier-detection'].levels))best[`outlier-detection:${level}`]=1000;
            await page.goto(`${base}/?game=outlier-detection`);await page.waitForFunction(()=>document.querySelector('[data-strategy]').__Strategy.progress);
            await page.locator('[data-strategy] [data-role=level]').selectOption('board-10');
            await move(page,{type:'investigate'});await move(page,{type:'finish'});
            assert.equal((await state(page)).score,1000,'context-aware empty candidate set');checks++;
            const touch=await browser.newContext({viewport:{width:360,height:800},isMobile:true,hasTouch:true}),p=await touch.newPage();
            await p.goto(`${base}/?game=outlier-detection`);await p.locator('[data-mark="3"]').tap();await p.waitForFunction(()=>document.querySelector('[data-strategy]').__Strategy.state?.revision===1);await ready(p);
            assert.deepEqual((await state(p)).marked,[3]);assert.equal((await state(p)).spent,0);checks+=2;
            await move(p,{type:'investigate'});await p.locator('[data-record="3"]').tap();
            assert(await p.locator('[data-case-heading]').evaluate(e=>e===document.activeElement));checks++;
            await move(p,{type:'inspect',record:3,evidence:'unit'});
            assert.equal((await state(p)).spent,1);checks++;
            await touch.close();assert.deepEqual(errors,[]);
            console.log(`PASS: ${checks} intuitive-play checks; six opening boards, live evidence, boundary preview, desktop/mobile/touch.`);return;
        }
        if (process.env.STRATEGY_FOCUS) for (const game of games) for (const level of Object.keys(catalogs[game].levels)) best[`${game}:${level}`] = 1000;
        for (const game of (process.env.STRATEGY_FOCUS ? [] : games)) {
            console.log(`Browser: ${game}`); await page.goto(`${base}/?game=${game}`);
            await page.waitForFunction(() => document.querySelector('[data-strategy]').__Strategy.progress);
            assert.equal(await state(page),null,'preview has no saved session');
            assert(await page.locator('[data-level="board-02"]').isDisabled(), 'locked board'); checks += 2;
            await layout(page, game, 'preview');
            for (const [level, solution] of Object.entries(catalogs[game].solutions)) {
                console.log(`  ${level}`);
                await page.locator('[data-strategy] [data-role=level]').selectOption(level); await page.locator('[data-strategy] [data-command=start]').click(); await ready(page);
                for (const m of solution) {
                    try { await move(page, m); }
                    catch (e) { console.error({ game, level, move: m, state: await state(page), message: await page.locator('[data-strategy] [data-role=message]').textContent() }); throw e; }
                }
                assert.equal((await state(page)).status, 'won');
                assert.equal((await state(page)).score, 1000, `${game}/${level} perfect target reachable`);
                await page.locator('.es-result').waitFor();
                await page.waitForFunction(() => document.querySelector('[data-role=personal]').textContent.includes('내 최고'));
                assert.match(await page.locator('[data-role=personal]').textContent(), /내 최고/); checks += 2;
                if (['board-06', 'board-10', 'board-12', 'board-16', 'board-18'].includes(level)) await layout(page, game, level);
                if (level === 'board-01') {
                    await layout(page, game, 'won');
                    if (game === 'data-visualization') {
                        assert(await page.locator('[data-chart]').evaluate(c => {
                            const a = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
                            let green = 0; for (let i = 0; i < a.length; i += 4) if (a[i + 1] > a[i] + 15 && a[i + 1] > a[i + 2] + 10 && a[i + 3] > 100) green++;
                            return green > 500;
                        }), 'actual chart bars present'); checks++;
                    }
                    if (game === 'ai-classifier-lab') { assert.equal(await page.locator('.es-plane circle,.es-plane rect[stroke="white"]').count(), 16); checks++; }
                    assert.equal(await page.locator('[data-learning]').count(), 0, 'no appended question activity');
                    assert.equal(await page.locator('.edu-learning-supplement').count(), 0, 'no supplement below the game'); checks += 2;
                }
            }
            const max = Object.keys(catalogs[game].levels).length * 3;
            assert((await page.locator('[data-role=collection]').textContent()).includes(`${max} / ${max}`)); checks++;
            await reveal(page.locator('[data-command=archive]')); await page.locator('[data-command=archive]').click();
            await page.locator('[data-role=archive] table').waitFor();
            assert.match(await page.locator('[data-role=archive]').textContent(), /strategy-2026-09-v1/); checks++;
        }
        if (process.env.STRATEGY_FOCUS) {
            for (const [game, levels] of [['data-extraction', ['board-05', 'board-12']], ['data-visualization', ['board-06', 'board-11', 'board-13', 'board-18']], ['ai-classifier-lab', ['board-12']]]) {
                await page.goto(`${base}/?game=${game}`); await page.waitForFunction(() => document.querySelector('[data-strategy]').__Strategy.progress);
                for (const level of levels) {
                    console.log(`Focused: ${game}/${level}`);
                    await page.locator('[data-strategy] [data-role=level]').selectOption(level); await page.locator('[data-strategy] [data-command=start]').click(); await ready(page);
                    for (const m of catalogs[game].solutions[level]) {
                        if (game === 'data-visualization' && level === 'board-13' && m.type === 'place' && m.row === 0) {
                            await page.locator('[data-pick="0"]').click();
                            const cell = page.locator('[data-point-x="0"][data-point-y="0"]'); await cell.focus();
                            await page.keyboard.press('ArrowRight'); await page.keyboard.press('ArrowUp'); await page.keyboard.press('ArrowUp');
                            assert.equal(await page.evaluate(() => document.activeElement.getAttribute('aria-label')), 'X 1, Y 2');
                            const previous = (await state(page)).revision; await page.keyboard.press('Enter');
                            await page.waitForFunction(r => document.querySelector('[data-strategy]').__Strategy.state.revision > r, previous); await ready(page);
                            assert.equal(await page.locator('[data-point-x="1"][data-point-y="2"]').getAttribute('tabindex'), '0'); checks += 2;
                        } else await move(page, m);
                    }
                    assert.equal((await state(page)).status, 'won'); assert.equal((await state(page)).score, 1000); checks += 2;
                    if (game === 'data-visualization') {
                        if (level === 'board-18') { const revision = (await state(page)).revision; await page.locator('[data-panel="1"]').click(); assert.equal((await state(page)).revision, revision); assert.equal(await page.evaluate(() => document.activeElement.dataset.panel), '1'); checks += 2; }
                        const chart = await page.locator('[data-strategy]').evaluate(r => ({ type: r.__Strategy.chart.config.type, data: r.__Strategy.chart.data.datasets[0].data, x: r.__Strategy.chart.options.scales.x?.type }));
                        if (level === 'board-06') { assert.equal(chart.x, 'linear'); assert.deepEqual(chart.data.map(p => p.x), [0, 1, 3, 6, 10, 15]); }
                        if (level === 'board-11') { assert.equal(chart.type, 'pie'); assert.deepEqual(chart.data, [30, 20, 10, 20, 10, 10]); }
                        if (level === 'board-13' || level === 'board-18') { assert.equal(chart.type, 'scatter'); assert.equal(chart.data.length, 6); }
                        assert(await page.locator('[data-chart]').evaluate(c => { const pixels = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let count = 0; for (let i = 0; i < pixels.length; i += 4) if (pixels[i + 3] > 100 && pixels[i + 1] > pixels[i] + 10) count++; return count > 200; }), 'chart colored pixels'); checks += 3;
                    }
                    await layout(page, game, `focused-${level}`);
                    await page.locator('[data-strategy] [data-role=board]').screenshot({ path: path.join(out, `${game}-${level}-board.png`) });
                }
            }
            await page.goto(`${base}/?game=data-visualization`); await page.waitForFunction(() => document.querySelector('[data-strategy]').__Strategy.progress);
            await page.locator('[data-strategy] [data-role=level]').selectOption('board-13'); await page.locator('[data-strategy] [data-command=start]').click(); await ready(page);
            for (const m of catalogs['data-visualization'].solutions['board-13'].filter(m => m.type === 'configure')) await move(page, m);
            await page.setViewportSize({ width: 320, height: 900 });
            const beforeGrid = await page.locator('.es-coordinate-board').boundingBox();
            for (let row = 0; row < 6; row++) await move(page, { type: 'place', row, x: 0, y: 0 });
            const afterGrid = await page.locator('.es-coordinate-board').boundingBox();
            assert.equal(afterGrid.height, beforeGrid.height); assert.equal(afterGrid.width, beforeGrid.width);
            assert.equal(await page.locator('[data-point-x="0"][data-point-y="0"]').textContent(), '6'); checks += 3;
            await layout(page, 'data-visualization', 'overlapping-points');
        }
        await page.goto(`${base}/?game=data-extraction`); await page.locator('[data-strategy] [data-command=start]').click(); await ready(page);
        const load = page.locator('[data-move]').filter({ hasText: '원자료 입고' }).first();
        fault = 'after'; await load.click(); await ready(page); assert((await state(page)).revision === 0); assert(await page.locator('[data-strategy] [data-command=retry]').isVisible());
        await page.locator('[data-strategy] [data-command=retry]').click(); await ready(page); assert.equal((await state(page)).revision, 1); assert.equal((await state(page)).spent, 1); checks += 4;
        await page.reload(); await page.locator('[data-strategy] [data-command=resume]').click(); await ready(page); assert.equal((await state(page)).spent, 1); checks++;
        const s = await state(page), record = sessions.get(s.token); record.revision++;
        await page.locator('[data-move]').filter({ hasText: '원자료 입고' }).nth(1).click(); await ready(page); assert(await page.locator('[data-strategy] [data-command=retry]').isVisible());
        await page.locator('[data-strategy] [data-command=resume]').click(); await ready(page); assert.equal((await state(page)).revision, 2); checks += 2;
        sessions.delete(s.token); await page.locator('[data-strategy] [data-command=resume]').click(); await ready(page); assert.equal(await state(page), null); assert(await page.locator('[data-strategy] [data-command=resume]').isHidden()); checks += 2;
        const count = Object.values(summaries).reduce((a, b) => a + b, 0);
        await page.goto(`${base}/?game=strategy-report`); assert.equal(Object.values(summaries).reduce((a, b) => a + b, 0), count);
        await page.locator('details > summary').first().click(); await page.waitForFunction(() => document.querySelector('[data-strategy]').__Strategy.progress); checks += 2;
        const touch = await browser.newContext({ viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true }); const p = await touch.newPage();
        await p.goto(`${base}/?game=data-visualization`); await p.locator('[data-strategy] [data-command=start]').tap(); await ready(p); await p.locator('[data-pick="0"]').tap(); await p.locator('[data-place="0"]').tap(); await ready(p); assert.deepEqual((await state(p)).slots[0], [0]); checks++;
        if (process.env.STRATEGY_FOCUS) {
            await p.reload(); await p.waitForFunction(() => document.querySelector('[data-strategy]').__Strategy.progress);
            await p.locator('[data-strategy] [data-role=level]').selectOption('board-13'); await p.locator('[data-strategy] [data-command=start]').tap(); await ready(p);
            for (const m of catalogs['data-visualization'].solutions['board-13'].filter(m => m.type === 'configure')) await move(p, m);
            await p.locator('[data-pick="0"]').tap(); await p.locator('[data-point-x="1"][data-point-y="2"]').tap();
            await p.waitForFunction(() => document.querySelector('[data-strategy]').__Strategy.state.plots[0].positions[0]?.x === 1); await ready(p);
            assert.deepEqual((await state(p)).plots[0].positions[0], { x: 1, y: 2 }); checks++;
        }
        await touch.close();
        assert.deepEqual(errors, [], 'browser errors');
        console.log(`PASS: ${checks} browser checks, ${process.env.STRATEGY_FOCUS ? 'expansion interactions' : '84 boards'}, five widths, retry/resume/report/touch/archive. Screenshots: ${out}`);
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
}
main().catch(e => { console.error(e); server.close(); process.exitCode = 1; });
