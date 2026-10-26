const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path');

module.exports = async ({ page, url, out, ready, state }) => {
    const platform = process.env.OJ_PLATFORM_ROOT || path.resolve(__dirname, '../../oj-integration-rebuild/oj-platform');
    const css = fs.readFileSync(path.join(platform, 'resources/ui/css/oj-ui-system.css'), 'utf8');
    const js = fs.readFileSync(path.join(platform, 'resources/ui/js/oj-ui.js'), 'utf8');
    const games = ['outlier-detection','missing-values','data-visualization','data-extraction','data-structure-lab','ai-classifier-lab','ai-model-lab','binary-arcade','character-code','code-typing','device-matching','image-data','keyword-rain'];
    let checks = 0;
    const scheme = async (value, system = 'light') => {
        await page.emulateMedia({ colorScheme: system });
        await page.locator(`[data-ojp-scheme-choice="${value}"]`).click();
        assert.equal(await page.locator('html').getAttribute('data-ojp-scheme'), value);
        checks++;
    };
    const surface = async (selector, dark) => {
        const colors = await page.locator(selector).first().evaluate(el => {
            const c = getComputedStyle(el);
            return { bg: c.backgroundColor, ink: c.color };
        });
        assert.equal(colors.bg, dark ? 'rgb(28, 28, 30)' : 'rgb(255, 255, 255)', selector + ' surface');
        assert.equal(colors.ink, dark ? 'rgb(245, 245, 247)' : 'rgb(29, 29, 31)', selector + ' text');
        checks += 2;
    };
    for (const width of [1280,390]) for (const game of games) {
        await page.setViewportSize({ width, height: width === 1280 ? 720 : 844 });
        await page.goto(url + '/?game=' + game);
        await page.waitForSelector('.edu-game[data-shell-ready=true]');
        if (games.indexOf(game) < 7) await ready(page);
        await page.addStyleTag({ content: css });
        await page.evaluate(() => {
            document.documentElement.dataset.ojpScheme = 'light';
            const controls = document.createElement('div');
            controls.innerHTML = ['auto','light','dark'].map(value => `<button type="button" data-ojp-scheme-choice="${value}">${value}</button>`).join('');
            document.body.prepend(controls);
        });
        await page.addScriptTag({ content: js });
        await scheme('dark'); await surface('.edu-game', true);
        await scheme('light','dark'); await surface('.edu-game', false);
        await scheme('auto','dark'); await surface('.edu-game', true);
        await page.emulateMedia({ colorScheme:'light' }); await surface('.edu-game', false);
        await scheme('dark');
        if (games.indexOf(game) < 7) { await page.locator('[data-begin]').click(); await ready(page); }
        if (game === 'device-matching') await page.locator('[data-action=start]').click();
        if (game === 'binary-arcade') await page.locator('[data-action=practice]').first().click();
        await page.locator('.edu-game').evaluate(root => root.__GameViewport.open());
        await surface('.eg-focus-dialog', true); await surface('.edu-game', true);
        const before = games.indexOf(game) < 7 ? (await state(page)).revision : null;
        await page.evaluate(() => document.documentElement.dataset.ojpScheme = 'light');
        await surface('.eg-focus-dialog', false); await surface('.edu-game', false);
        await page.evaluate(() => document.documentElement.dataset.ojpScheme = 'dark');
        await surface('.eg-focus-dialog', true);
        if (before !== null) { assert.equal((await state(page)).revision, before, 'switching theme does not submit a game move'); checks++; }
        if (game === 'data-visualization') {
            const canvas = page.locator('[data-rescue-chart],[data-visual-chart]').first();
            await page.waitForFunction(() => { const c = document.querySelector('[data-rescue-chart],[data-visual-chart]'); return window.Chart.getChart(c).options.color === '#f5f5f7'; });
            assert.equal(await canvas.evaluate(c => window.Chart.getChart(c).options.scales.y.ticks.color), '#f5f5f7'); checks++;
        }
        if (game === 'image-data') {
            assert.equal(await page.locator('[data-pixel="0"]').evaluate(e => getComputedStyle(e).backgroundColor), 'rgb(255, 255, 255)', 'white pixels are data, not UI theme'); checks++;
            await page.locator('[data-color="2"]').click(); await page.locator('[data-pixel="0"]').click();
            const painted = await page.locator('[data-pixel="0"]').evaluate(e => getComputedStyle(e).backgroundColor);
            await page.evaluate(() => document.documentElement.dataset.ojpScheme = 'light');
            assert.equal(await page.locator('[data-pixel="0"]').evaluate(e => getComputedStyle(e).backgroundColor), painted, 'theme switches preserve painted colors'); checks++;
            await page.evaluate(() => document.documentElement.dataset.ojpScheme = 'dark');
        }
        if (game === 'device-matching') {
            const textId = await page.locator('.edu-game').evaluate(r => r.__DeviceMatching.state.cards.find(c => c.type === 'text').id);
            await page.locator(`[data-card-id="${textId}"]`).click();
        }
        if (['missing-values','data-visualization','device-matching','code-typing','image-data'].includes(game)) await page.screenshot({ path:path.join(out,`theme-${game}-${width}.png`) });
        await page.getByRole('button',{ name:'집중 화면 나가기',exact:true }).click();
        assert.equal(await page.locator('.eg-focus-dialog').count(),0); checks++;
        console.log(`${game} ${width}: light, dark, system and focused mode passed`);
    }
    console.log(`PASS: ${checks} screen-mode checks across all 13 games.`);
};
