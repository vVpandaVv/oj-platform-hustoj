const assert = require('node:assert/strict');
const path = require('node:path');

module.exports = async ({page, url, out, ready, state, sessions}) => {
    let checks = 0;
    const check = (ok, label) => { assert(ok, label); checks++; };
    const games = ['binary-arcade','device-matching','keyword-rain','code-typing','image-data','outlier-detection','missing-values','data-visualization','data-extraction','data-structure-lab','ai-classifier-lab','ai-model-lab'];
    const go = async game => {
        await page.goto(url + '/?game=' + game);
        await page.waitForSelector('.eg-arcade');
        if (games.indexOf(game) >= 5) await ready(page);
    };
    const act = async selector => {
        const revision = (await state(page))?.revision ?? -1;
        await page.locator(selector).click();
        await page.waitForFunction(old => (document.querySelector('[data-play]').__Play.state?.revision ?? -1) > old, revision);
        await ready(page);
    };
    for (const width of [1280,390]) {
        await page.setViewportSize({width,height:width === 1280 ? 800 : 844});
        for (const game of games) {
            await go(game);
            check(await page.locator('.eg-objective').count() === 1, game + ' has one objective');
            check(await page.locator('.eg-score-help').getAttribute('open') === null, game + ' score explanation starts closed');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), game + ' no horizontal overflow');
            const actions = await page.locator('.eg-action-receipt').textContent();
            check(actions === '', game + ' never invents points on entry');
            await page.locator('.edu-game').evaluate(root => root.__GameViewport.open());
            await page.waitForTimeout(50);
            check(await page.locator('.eg-objective').isVisible(), game + ' goal remains in focused play');
            await page.screenshot({path:path.join(out,'board-ui-' + game + '-' + width + '.png')});
        }
    }
    await page.setViewportSize({width:1280,height:800});
    await go('outlier-detection'); await act('[data-begin]');
    let s = await state(page), domain = sessions.get(s.token).state;
    for (const id of domain.truth) await page.locator('.eo-point[data-id="' + id + '"]').click();
    await act('[data-do=scan]'); s = await state(page);
    check((await page.locator('.eg-action-receipt strong').textContent()) === '+' + s.score + '점', 'receipt uses actual server score');
    check(await page.locator('.eg-action-receipt').getAttribute('data-tone') === 'success', 'correct separation is success');
    await page.locator('.edu-game').evaluate(root => root.__GameViewport.close());
    await page.locator('[data-role=settings]>summary').click();
    page.once('dialog', dialog => dialog.accept());
    await page.locator('[data-command=new]').first().click();
    await act('[data-begin]'); await act('[data-do=scan]');
    check((await state(page)).score === 0, 'missed values do not award points');
    check(await page.locator('.eg-action-receipt').getAttribute('data-tone') === 'miss', 'zero-point wrong answer is not success');
    check((await page.locator('.eg-action-receipt').innerText()).includes('놓침'), 'receipt explains missed values');

    await go('device-matching'); await page.locator('[data-action=start]').click();
    const pair = await page.locator('[data-device-matching]').evaluate(root => {
        const cards = root.__DeviceMatching.state.cards;
        return cards.filter(c => c.pairId === cards[0].pairId).map(c => c.id);
    });
    for (const id of pair) await page.locator('[data-card-id="' + id + '"]').click();
    const expected = await page.locator('[data-device-matching]').evaluate(root => root.__DeviceMatching.state.score);
    check((await page.locator('.eg-action-receipt strong').textContent()) === '+' + expected + '점', 'card match receipt uses actual awarded points');
    await page.waitForTimeout(550);
    const wrong = await page.locator('[data-device-matching]').evaluate(root => {
        const cards = root.__DeviceMatching.state.cards.filter(c => !c.matched);
        return [cards[0].id,cards.find(c => c.pairId !== cards[0].pairId).id];
    });
    for (const id of wrong) await page.locator('[data-card-id="' + id + '"]').click();
    check(await page.locator('.eg-action-receipt').getAttribute('data-tone') === 'miss', 'mismatch is visibly different');
    await page.waitForFunction(ids => {
        const game = document.querySelector('[data-device-matching]').__DeviceMatching;
        return !game.state.locked && ids.every(id => !game.state.cards.find(c => c.id === id).faceUp);
    }, wrong);
    check(true, 'mismatch still flips back and unlocks');

    await go('image-data');
    check(await page.locator('[data-color]').count() === 16, 'all 16 palette codes remain');
    const initial = await page.locator('[data-image-data]').evaluate(root => root.__ImageData?.state?.pixels?.length || root.querySelectorAll('[data-pixel]').length);
    check(initial > 0, 'real pixel board remains');
    await page.locator('[data-action=check]').click();
    check(await page.locator('.eg-action-receipt').getAttribute('data-tone') === 'miss', 'pixel errors are reported without fake points');
    check(await page.locator('.eg-action-receipt strong').count() === 0, 'pixel activity does not invent a score');

    await go('code-typing');
    const code = await page.locator('[data-code-typing]').evaluate(root => root.__CodeTyping.currentSample().content);
    await page.locator('[data-field=input]').fill(code); await page.locator('[data-field=input]').press('Enter');
    await page.waitForFunction(() => document.querySelector('[data-code-typing]').__CodeTyping.state.status === 'finished');
    check(await page.locator('[data-field=input]').inputValue() === code, 'typing result stays intact');
    check((await page.locator('.eg-action-receipt').innerText()).includes('CPM'), 'typing shows completed speed and accuracy');
    check(await page.locator('[data-field=speed]').isVisible() && await page.locator('[data-field=accuracy]').isVisible(), 'speed and accuracy stay in the main HUD');
    console.log(checks + ' board-first UI and real-action feedback checks passed');
};
