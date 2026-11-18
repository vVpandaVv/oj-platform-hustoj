/* Real renderer/client with isolated WordPress, REST and score validation fixtures. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'D:/php/php.exe';
const out = path.resolve(root, '../_notes/tmp/character-code-ui');
const posts = [];
function fixture(file, input, args = []) {
    const p = spawnSync(php, [path.join(root, file), ...args], { cwd: root, input: JSON.stringify(input), encoding: 'utf8', maxBuffer: 20e6 });
    assert.equal(p.status, 0, p.stderr || p.stdout);
    return p.stdout;
}
const server = http.createServer(async (req, res) => {
    const json = (data, status = 200) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(data)); };
    try {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname.startsWith('/plugins/edu-games/')) {
            const file = path.resolve(root, decodeURIComponent(url.pathname.slice('/plugins/edu-games/'.length)));
            const ext = path.extname(file);
            if (!file.startsWith(root + path.sep) || !['.js', '.css', '.svg'].includes(ext) || !fs.existsSync(file)) return json({}, 404);
            res.writeHead(200, { 'Content-Type': { '.js': 'application/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' }[ext] });
            return res.end(fs.readFileSync(file));
        }
        if (url.pathname.includes('/progress/')) return json({ records: {}, bestScore: 1200 });
        if (url.pathname.includes('/leaderboards/')) return json({ leaderboard: [], pagination: { page: 1, perPage: 20, total: 0 } });
        if (url.pathname.endsWith('/session')) return json({ sessionToken: 'character-ui-fixture' });
        if (url.pathname.endsWith('/scores')) {
            let body = ''; for await (const chunk of req) body += chunk;
            const payload = JSON.parse(body);
            const score = JSON.parse(fixture('tests/bridge.php', { game: 'character-code', data: payload }, ['validate']));
            if (score.code) return json(score, 400);
            posts.push(payload);
            return json({ score, rank: 1 });
        }
        const html = fixture('tests/browser-fixture.php', { game: 'character-code', action: 'html' });
        const css = ['blocks/character-code/style.css', 'assets/rankings/pager.css', 'assets/shell/shell.css'];
        const js = ['assets/rankings/pager.js', 'assets/learning/progress.js', 'blocks/character-code/view.js', 'assets/shell/shell.js'];
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end('<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>문자 코드 게임 미리보기</title><style>body{margin:16px;font-family:system-ui,sans-serif}main{max-width:1120px;margin:auto}</style>' + css.map(f => `<link rel="stylesheet" href="/plugins/edu-games/${f}">`).join('') + '<main>' + html + '</main>' + js.map(f => `<script src="/plugins/edu-games/${f}"></script>`).join('') + '</html>');
    } catch (error) { json({ message: error.message }, 500); }
});

async function main() {
    await new Promise(resolve => server.listen(Number(process.env.CHARACTER_CODE_PORT || 0), '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    if (process.env.CHARACTER_CODE_PREVIEW === '1') { console.log(base + '/?game=character-code'); return; }
    const { chromium } = require(process.env.PLAYWRIGHT_PATH || '../../_archived/readcontents-library/node_modules/playwright');
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const page = await browser.newPage();
    const errors = [], layouts = [];
    let checks = 0;
    const check = (condition, label) => { assert(condition, label); checks++; };
    page.on('pageerror', e => errors.push(e.message));
    fs.mkdirSync(out, { recursive: true });
    const go = async () => {
        await page.goto(base);
        await page.waitForSelector('.edu-game[data-shell-ready="true"]');
        await page.waitForFunction(() => document.querySelector('[data-character-code]').__CharacterCode?.account?.synced);
    };
    const setMode = async mode => { await page.locator(`[data-mode="${mode}"]`).click(); };
    const target = async () => page.locator('[data-character-code]').evaluate(r => {
        const g = r.__CharacterCode;
        return { text: g.state.challenge.text, codes: g.state.challenge.codes.join(' '), missing: g.state.challenge.usedSymbols.map(s => g.state.table[s]) };
    });
    async function solve(mode, answer) {
        if (mode === 'rebuild') {
            for (let i = 0; i < answer.missing.length; i++) {
                const input = page.locator(`[data-rebuild-index="${i}"]`);
                await input.fill(answer.missing[i]);
                await input.press('Enter');
            }
        } else {
            await page.locator('[data-answer-input]').fill(mode === 'decode' ? answer.text : answer.codes);
            await page.locator('[data-answer-input]').press('Enter');
        }
    }
    try {
        for (const size of [{ width: 1366, height: 768 }, { width: 1024, height: 768 }, { width: 768, height: 1024 }, { width: 390, height: 844 }, { width: 812, height: 375 }]) {
            await page.setViewportSize(size); await go();
            const table = await page.locator('.oj-code-table').innerText();
            check(await page.locator('.oj-code-table-cell').count() === 37, 'all 37 entries');
            for (const mode of ['decode', 'encode', 'rebuild']) {
                await setMode(mode);
                const answer = await target();
                await page.locator('.edu-game').evaluate(r => r.__GameViewport.open());
                const dimensions = await page.locator('.eg-focus-area').evaluate(area => ({ overflow: area.scrollHeight > area.clientHeight + 2, horizontal: document.documentElement.scrollWidth > innerWidth + 1, board: area.querySelector('.oj-code-board').getBoundingClientRect().height, table: area.querySelector('.oj-code-table-panel').getBoundingClientRect().height,
                    ...(area.scrollHeight > area.clientHeight + 2 ? { area: [area.clientHeight, area.scrollHeight], children: [...area.querySelector('.oj-code-board').children].map(el => [el.className, el.getBoundingClientRect().height, getComputedStyle(el).padding, getComputedStyle(el).margin]) } : {}) }));
                layouts.push({ ...size, mode, ...dimensions });
                check(!dimensions.horizontal, 'no horizontal overflow');
                if (size.width >= 768) check(!dimensions.overflow, 'desktop/tablet focused area fits: ' + JSON.stringify({ ...size, mode, ...dimensions }));
                check(await page.locator('.oj-code-table-panel').isVisible(), 'reference stays visible in rebuild');
                await solve(mode, answer);
                check(await page.locator('[data-character-code]').evaluate(r => r.__CharacterCode.state.solved), mode + ' submitted');
                check(/^\+\d+$/.test(await page.locator('[data-field="score-burst"]').textContent()), 'score delta appears');
                check((await page.locator('[data-field="score-detail"]').textContent()).includes('기본·길이'), 'score explanation appears');
                if (size.width === 1366) {
                    await page.locator('[data-field="score-burst"]').evaluate(el => Promise.all(el.getAnimations().map(a => a.finished)));
                    await page.screenshot({ path: path.join(out, mode + '-correct.png'), fullPage: true });
                }
                await page.locator('.edu-game').evaluate(r => r.__GameViewport.close());
            }
            await setMode('decode');
            check(await page.locator('.oj-code-table').innerText() === table, 'reference remains fixed');
            await page.locator('[data-answer-input]').fill('!'); await page.locator('[data-answer-input]').press('Enter');
            check(await page.locator('.is-wrong[data-code-index]').count() > 0, 'wrong position highlighted');
            check(await page.locator('[data-answer-input]').getAttribute('aria-invalid') === 'true', 'error accessible');
            await page.locator('[data-answer-input]').fill('A');
            check(await page.locator('[data-answer-input]').getAttribute('aria-invalid') === null, 'typing clears error');
            await page.locator('.oj-code-input').focus();
            await page.locator('[data-action="new-round"]').click();
            check(await page.locator('[data-answer-input]').evaluate(el => el === document.activeElement), 'new round focuses answer');
        }
        for (const size of [{ width: 1024, height: 768 }, { width: 768, height: 1024 }]) {
            await page.setViewportSize(size); await go();
            await page.locator('[data-field="difficulty"]').selectOption('hard');
            await page.locator('[data-character-code]').evaluate(r => {
                r.__CharacterCode.pickPhrase = phrases => phrases.reduce((a,b) => a.length > b.length ? a : b);
            });
            for (const mode of ['decode', 'encode', 'rebuild']) {
                await setMode(mode);
                await page.locator('.edu-game').evaluate(r => r.__GameViewport.open());
                check(await page.locator('.eg-focus-area').evaluate(el => el.scrollHeight <= el.clientHeight + 2), 'long hard ' + mode + ' fits tablet ' + size.width);
                await solve(mode, await target());
                check(await page.locator('[data-character-code]').evaluate(r => r.__CharacterCode.state.solved), 'hard ' + mode + ' remains playable');
                await page.locator('.edu-game').evaluate(r => r.__GameViewport.close());
            }
        }
        await page.setViewportSize({ width: 1024, height: 768 }); await go();
        const map = await page.locator('[data-character-code]').evaluate(r => r.__CharacterCode.state.table);
        check(map.A === '01' && map.Z === '26' && map['0'] === '27' && map['9'] === '36' && map[' '] === '00', 'fixed mapping matches module');
        const platform = process.env.OJ_PLATFORM_ROOT || path.resolve(root, '../oj-integration-rebuild/oj-platform');
        await page.addStyleTag({ content: fs.readFileSync(path.join(platform, 'resources/ui/css/oj-ui-system.css'), 'utf8') });
        await page.evaluate(() => { document.documentElement.dataset.ojpScheme = 'dark'; });
        check(await page.locator('.oj-code-board').evaluate(el => getComputedStyle(el).backgroundColor !== 'rgb(245, 245, 247)'), 'dark mode follows platform tokens');
        await page.screenshot({ path: path.join(out, 'dark.png'), fullPage: true });
        await page.locator('[data-action="start-ranked"]').click();
        await page.waitForSelector('.eg-focus-dialog');
        const mode = await page.locator('[data-character-code]').evaluate(r => r.__CharacterCode.state.currentMode);
        await solve(mode, await target());
        await page.waitForFunction(() => document.querySelector('[data-character-code]').__CharacterCode.state.round === 2);
        check(await page.locator('[data-answer-input],[data-rebuild-index]').first().evaluate(el => el === document.activeElement), 'ranked advances with focus');
        await page.locator('[data-answer-input],[data-rebuild-index]').first().fill('1');
        const pendingAnswer = await page.locator('[data-character-code]').evaluate(r => r.__CharacterCode.captureAnswer());
        await page.waitForTimeout(1000);
        await page.locator('[data-character-code]').evaluate(r => {
            const g = r.__CharacterCode;
            g.state.roundStartedAt = performance.now() - g.state.timeLimitSeconds * 1000 - 100;
            g.updateTimeLeft();
        });
        await page.waitForFunction(() => document.querySelector('[data-character-code]').__CharacterCode.state.status === 'finished');
        await page.waitForFunction(() => document.querySelector('[data-field="feedback"]').textContent.includes('저장되었습니다'));
        check(posts.length === 1 && posts[0].correctCount === 1, 'score saves through validator once');
        check(await page.locator('.oj-code-last-answer').isVisible(), 'finished attempt retained');
        check(await page.locator('.oj-code-last-answer code').first().textContent() === pendingAnswer, 'timeout review keeps current partial answer, not previous round');
        check(await page.locator('[data-shell-section="records"]').getAttribute('open') === null, 'rankings folded');
        await page.locator('.edu-game').evaluate(r => r.__GameViewport.close());
        await page.setViewportSize({ width: 390, height: 844 });
        await page.route('**/leaderboards/game/character-code?*', route => route.fulfill({ json: { leaderboard: [{ rank: 1, userId: 7, playerName: '검증 학생', score: 1200, deleteUrl: '/edu-games/v1/leaderboards/game/character-code/records/score/91' }], pagination: { page: 1, perPage: 20, total: 1 } } }));
        await page.locator('.edu-game').evaluate(r => r.__CharacterCode.loadLeaderboard());
        await page.locator('[data-shell-section="records"] > summary').click();
        check(await page.locator('.edu-rank-delete').evaluate(button => {
            const row = button.closest('[data-rank-row]'), b = button.getBoundingClientRect(), r = row.getBoundingClientRect(), score = row.querySelector('b').getBoundingClientRect();
            return b.right <= r.right && b.left >= score.right;
        }), 'admin deletion remains separate from score on mobile');
        check(errors.length === 0, errors.join('\n'));
        console.log(JSON.stringify({ checks, layouts, scorePosts: posts.length, errors }, null, 2));
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
}
main().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
