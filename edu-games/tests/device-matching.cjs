/* Production cards/rules/assets; HTTP and WordPress are isolated test doubles. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const http = require('node:http');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { chromium, webkit } = require(process.env.PLAYWRIGHT_PATH || '../../_archived/readcontents-library/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const out = path.resolve(root, '../_notes/tmp/device-expansion-20260911/verification'+(process.env.PLAY_BROWSER==='webkit'?'-webkit':''));
const php = (args, input) => {
    const result = spawnSync(process.env.PHP_BINARY || 'D:/php/php.exe', args, { cwd: root, input: input && JSON.stringify(input), encoding: 'utf8', maxBuffer: 20e6 });
    assert.equal(result.status, 0, result.stderr);
    return result.stdout;
};
const rules = JSON.parse(php(['tests/bridge.php', 'rules']))['device-matching'].rules;
const additions = JSON.parse(fs.readFileSync(path.join(root, 'assets/device-matching/expanded-sources.json'), 'utf8'));
let checks = 0;
const check = (condition, message) => { assert(condition, message); checks++; };
check(rules.devices.length === 38, '38 device types');
check(new Set(rules.devices.map(d => d.id)).size === 38, 'unique IDs');
check(rules.devices.filter(d => d.category === 'sensor').length === 11, '11 sensors');
check(rules.devices.filter(d => d.category === 'actuator').length === 7, '7 actuators');
check(additions.length === 14, '14 new photographs');
for (const d of rules.devices) {
    const bytes = fs.readFileSync(path.join(root, 'assets/device-matching/images', `${d.id}.jpg`));
    check(bytes[0] === 0xff && bytes[1] === 0xd8, `${d.id} JPEG signature`);
    check(d.name && d.description && d.author && d.license && d.sourceUrl.startsWith('https://commons.wikimedia.org/wiki/File:'), `${d.id} metadata`);
}
for (const entry of additions) {
    const d = rules.devices.find(d => `${d.id}.jpg` === entry.file);
    const bytes = fs.readFileSync(path.join(root, 'assets/device-matching/images', entry.file));
    check(crypto.createHash('sha256').update(bytes).digest('hex') === entry.sha256, `${entry.file} hash`);
    check(`File:${d.sourceFile}` === entry.title && d.license === entry.metadata.LicenseShortName.value, `${entry.file} provenance`);
}
const context = { document: { readyState: 'loading', addEventListener() {} } };
const source = fs.readFileSync(path.join(root, 'blocks/device-matching/view.js'), 'utf8');
vm.runInNewContext(source.replace('const init = () => {', 'globalThis.Game = DeviceMatchingGame; const init = () => {'), context);
const game = Object.create(context.Game.prototype);
game.rules = rules;
game.render = () => {};
const observed = new Set();
for (let run = 0; run < 100; run++) {
    game.state = game.initialState();
    for (let stage = 0; stage < 4; stage++) {
        game.state.stageIndex = stage;
        game.prepareStage();
        check(game.state.cards.length === rules.stages[stage].pairs * 2, 'stage card count');
        const pairs = new Map();
        for (const c of game.state.cards) {
            pairs.set(c.pairId, [...(pairs.get(c.pairId) || []), c.type]);
            observed.add(c.pairId);
        }
        check([...pairs.values()].every(types => types.length === 2 && new Set(types).size === 2), 'one photo and one text');
    }
    check(game.state.seenDeviceIds.length === 36, 'no repeated devices in one run');
}
check(observed.size === 38, 'entire expanded pool reachable');
game.rules = { ...rules, devices: rules.devices.slice(0, 24) };
game.state = game.initialState();
for (let stage = 0; stage < 4; stage++) {
    game.state.stageIndex = stage;
    game.prepareStage();
    check(new Set(game.state.cards.map(c => c.pairId)).size === rules.stages[stage].pairs, 'smaller legacy pool fills each stage without duplicate pairs');
}

const html = php(['tests/browser-fixture.php'], { game: 'device-matching', action: 'html' });
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.startsWith('/plugins/edu-games/')) {
        const file = path.resolve(root, decodeURIComponent(url.pathname.slice('/plugins/edu-games/'.length)));
        if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) { res.writeHead(404).end(); return; }
        res.setHeader('Content-Type', file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'image/jpeg');
        res.end(fs.readFileSync(file));
    } else if (url.pathname === '/') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>장치 매칭 검증</title><style>body{margin:0;padding:16px;font-family:Arial,sans-serif}main{max-width:1120px;margin:auto}button{font:inherit}</style><link rel="stylesheet" href="/plugins/edu-games/blocks/device-matching/style.css"><link rel="stylesheet" href="/plugins/edu-games/assets/shell/shell.css"><main>${html}</main><script src="/plugins/edu-games/assets/rankings/pager.js"></script><script src="/plugins/edu-games/blocks/device-matching/view.js"></script><script src="/plugins/edu-games/assets/shell/shell.js"></script></html>`);
    } else {
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ leaderboard: [], sessionToken: 'test-token' }));
    }
});
async function main() {
    fs.mkdirSync(out, { recursive: true });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await (process.env.PLAY_BROWSER === 'webkit' ? webkit.launch({ headless: true }) : chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true }));
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        await require('./browser-device-turns.cjs')({ page, out });
        check(errors.length === 0, errors.join('\n'));
        if (process.env.DEVICE_TURNS_ONLY) return;
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        await require('./browser-device-images.cjs')({ page, out });
        check(errors.length === 0, errors.join('\n'));
        if (process.env.DEVICE_IMAGES_ONLY) return;
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        check(await page.locator('.oj-device-credits li').count() === 38, 'all credits rendered');
        await page.locator('[data-action="start"]').click();
        await page.waitForSelector('.eg-focus-dialog');await page.locator('.edu-game').evaluate(e=>e.__GameViewport.close());
        check(await page.locator('[data-card-id]').count() === 12, 'starts with six pairs');
        check(await page.locator('.oj-device-deck').evaluate(deck => getComputedStyle(deck).gridTemplateColumns.split(' ').length === 4), '12 cards use four columns on desktop');
        async function checkFullRows(label) {
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            const layout = await page.locator('.oj-device-deck').evaluate(deck => {
                const rows = {};
                for (const card of deck.querySelectorAll('[data-card-id]')) {
                    const top = Math.round(card.getBoundingClientRect().top);
                    rows[top] = (rows[top] || 0) + 1;
                }
                return { rows: Object.values(rows), columns: getComputedStyle(deck).gridTemplateColumns.split(' ').length };
            });
            check(layout.rows.length > 0 && layout.rows.every(count => count === layout.columns), `${label}: no partial row`);
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${label}: no page overflow`);
        }
        // Solve through the actual click handler and real delayed match/mismatch transitions.
        for (let n = 0; n < 8; n++) {
            const wrong = await page.evaluate(() => {
                const cards = document.querySelector('[data-device-matching]').__DeviceMatching.state.cards;
                return [cards[0].id, cards.find(c => c.pairId !== cards[0].pairId).id];
            });
            for (const id of wrong) await page.locator(`[data-card-id="${id}"]`).click();
            await page.waitForFunction(() => !document.querySelector('[data-device-matching]').__DeviceMatching.state.locked);
        }
        check(await page.evaluate(() => document.querySelector('[data-device-matching]').__DeviceMatching.state.status === 'playing'), 'no failure limit');
        await page.locator('[data-action="start"]').click();
        const runIds = new Set();
        for (let stage = 0; stage < 4; stage++) {
            for (const width of [320, 360, 768, 1280]) {
                await page.setViewportSize({ width, height: 900 });
                await checkFullRows(`stage ${stage + 1}, viewport ${width}`);
                if (stage === 0 && [360, 1280].includes(width)) {
                    await page.locator('.oj-device-deck').screenshot({ path: path.join(out, `filled-grid-${width}.png`) });
                }
            }
            for (const width of [640, 900, 1120]) {
                await page.locator('main').first().evaluate((main, width) => { main.style.maxWidth = `${width}px`; }, width);
                await checkFullRows(`stage ${stage + 1}, container ${width}`);
            }
            const pairs = await page.evaluate(() => {
                const cards = document.querySelector('[data-device-matching]').__DeviceMatching.state.cards;
                return cards.filter(c => c.type === 'image').map(c => ({ pair: c.pairId, ids: [c.id, cards.find(t => t.type === 'text' && t.pairId === c.pairId).id] }));
            });
            for (const pair of pairs) {
                check(!runIds.has(pair.pair), 'new device across stages');
                runIds.add(pair.pair);
                const positions = await page.locator('[data-card-id]').evaluateAll(cards => cards.map(card => [card.dataset.cardId, card.offsetLeft, card.offsetTop]));
                for (const id of pair.ids) await page.locator(`[data-card-id="${id}"]`).click();
                await page.waitForFunction(id => document.querySelector(`[data-card-id="${id}"]`).disabled, pair.ids[0]);
                assert.deepEqual(await page.locator('[data-card-id]').evaluateAll(cards => cards.map(card => [card.dataset.cardId, card.offsetLeft, card.offsetTop])), positions, 'matching preserves card positions'); checks++;
            }
            if (stage < 3) await page.locator('[data-action="next-stage"]').click();
        }
        check(await page.evaluate(() => {
            const s = document.querySelector('[data-device-matching]').__DeviceMatching.state;
            return s.status === 'completed' && s.totalMatches === 36 && s.totalStars === 12;
        }), '36 pairs / 12 stars completion');
        for (const width of [320, 360, 768, 1280, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            // Render every production face to check even cards omitted by this random run.
            await page.evaluate(() => {
                const g = document.querySelector('[data-device-matching]').__DeviceMatching;
                g.fields.deck.innerHTML = g.rules.devices.flatMap(device => ['image', 'text'].map(type => g.renderCard({ id: `${device.id}-${type}`, type, device, faceUp: true, matched: false }))).join('');
                g.fields.deck.querySelectorAll('img').forEach(img => img.loading = 'eager');
            });
            await page.locator('.oj-device-deck img').evaluateAll(imgs => Promise.all(imgs.map(img => img.decode())));
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `page width ${width}`);
            const clipped = await page.locator('.oj-device-text-card').evaluateAll(cards => cards.filter(card => {
                const parent = card.getBoundingClientRect();
                return [...card.children].some(child => { const r = child.getBoundingClientRect(); return r.bottom > parent.bottom + 1 || r.right > parent.right + 1 || r.top < parent.top - 1; });
            }).map(card => card.textContent));
            assert.deepEqual(clipped, [], `clipped text at ${width}`); checks++;
            check(await page.locator('.oj-device-deck img').evaluateAll(imgs => imgs.every(img => img.naturalWidth >= 200 && img.naturalHeight >= 100)), 'nonblank readable assets');
            if ([360, 1280].includes(width)) {
                await page.evaluate(ids => {
                    document.querySelectorAll('[data-card-id]').forEach(card => { if (!ids.some(id => card.dataset.cardId === `${id}-image` || card.dataset.cardId === `${id}-text`)) card.remove(); });
                }, additions.map(e => e.file.slice(0, -4)));
                await page.locator('.oj-device-deck').screenshot({ path: path.join(out, `new-cards-${width}.png`) });
            }
        }
        check(errors.length === 0, errors.join('\n'));
        console.log(`PASS: ${checks} device catalog, provenance, selection, matching and responsive browser checks.`);
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
