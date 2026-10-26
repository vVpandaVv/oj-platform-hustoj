const assert = require('node:assert/strict');
const path = require('node:path');

module.exports = async ({ page, out }) => {
    const url = page.url();
    let checks = 0;
    for (const viewport of [{ width: 1280, height: 720 }, { width: 390, height: 844 }]) {
        for (const focused of [true, false]) {
            await page.setViewportSize(viewport);
            await page.goto(url);
            await page.locator('[data-action=start]').click();
            await page.waitForSelector('.eg-focus-dialog');
            if (!focused) await page.locator('.edu-game').evaluate(root => root.__GameViewport.close());
            const setup = await page.locator('.edu-game').evaluate(root => {
                const game = root.__DeviceMatching, photo = game.state.cards.find(card => card.type === 'image');
                const text = game.state.cards.find(card => card.type === 'text' && card.pairId !== photo.pairId);
                const partner = game.state.cards.find(card => card.type === 'text' && card.pairId === photo.pairId);
                root.__turnNodes = [...game.fields.deck.children];
                root.__turnEvents = [];
                game.fields.deck.addEventListener('animationstart', event => {
                    if (event.animationName.startsWith('oj-device-') && event.target.matches('.oj-device-card-inner')) root.__turnEvents.push(event.target.parentElement.dataset.cardId);
                });
                return { wrong: [photo.id, text.id], partner: partner.id, revealMs: game.rules.mismatchRevealMs };
            });
            for (const id of setup.wrong) await page.locator(`[data-card-id="${id}"]`).click();
            await page.locator(`[data-card-id="${setup.partner}"]`).click();
            assert.equal(await page.locator('.edu-game').evaluate(root => root.__DeviceMatching.state.openedIds.length), 2, 'third selection is blocked during reveal');
            checks++;
            await page.waitForTimeout(300);
            await page.screenshot({ path: path.join(out, `mismatch-open-${viewport.width}-${focused}.png`) });
            await page.waitForFunction(() => !document.querySelector('.edu-game').__DeviceMatching.state.locked);
            await page.waitForTimeout(300);
            const closed = await page.locator('.edu-game').evaluate(root => ({
                open: root.__DeviceMatching.state.openedIds.length,
                visible: root.querySelectorAll('.oj-device-card.is-face-up:not(.is-matched)').length,
                fronts: [...root.querySelectorAll('.oj-device-card-front')].filter(front => front.textContent.trim() || front.querySelector('img')).length,
                sameNodes: root.__turnNodes.every((node, i) => node === root.__DeviceMatching.fields.deck.children[i]),
                transitions: root.__turnEvents.length,
                mistakes: root.__DeviceMatching.state.stageMistakes,
                backs: [...root.querySelectorAll('.oj-device-card-back')].every(back => getComputedStyle(back).visibility === 'visible'),
                hidden: [...root.querySelectorAll('.oj-device-card-front')].every(front => getComputedStyle(front).visibility === 'hidden'),
            }));
            await page.screenshot({ path: path.join(out, `mismatch-closed-${viewport.width}-${focused}.png`) });
            assert.equal(closed.open, 0, 'open selections reset');
            assert.equal(closed.visible, 0, 'wrong cards show their backs');
            assert.equal(closed.fronts, 0, 'closed fronts reveal no answers');
            assert.equal(closed.mistakes, 1, 'one mistake per turn');
            assert(closed.backs && closed.hidden, 'face visibility agrees with closed state');
            assert(closed.sameNodes, 'card nodes survive opening and closing');
            assert(closed.transitions >= 4, 'both cards animate open and closed');
            checks += 7;
            for (const id of [setup.wrong[0], setup.partner]) await page.locator(`[data-card-id="${id}"]`).click();
            await page.waitForFunction(() => !document.querySelector('.edu-game').__DeviceMatching.state.locked);
            assert.equal(await page.locator('.oj-device-card.is-matched').count(), 2, 'matching continues after a miss');
            checks++;
            const pending = await page.locator('.edu-game').evaluate(root => {
                const cards = root.__DeviceMatching.state.cards.filter(card => !card.matched);
                return [cards[0].id, cards.find(card => card.pairId !== cards[0].pairId).id];
            });
            for (const id of pending) await page.locator(`[data-card-id="${id}"]`).click();
            assert(await page.locator('.edu-game').evaluate(root => root.__DeviceMatching.state.locked), 'restart happens during a pending mismatch');
            await page.locator('[data-action=start]').click();
            const freshIds = await page.locator('.edu-game').evaluate(root => root.__DeviceMatching.state.cards.map(card => card.id));
            await page.waitForTimeout(setup.revealMs + 300);
            assert(await page.locator('.edu-game').evaluate((root, ids) => {
                const game = root.__DeviceMatching;
                return game.state.stageMistakes === 0 && !game.state.locked && game.state.cards.every((card, i) => card.id === ids[i] && !card.faceUp && !card.matched);
            }, freshIds), 'restart cancels the previous turn');
            checks++;
        }
    }
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(url);
    await page.locator('[data-action=start]').click();
    const wrong = await page.locator('.edu-game').evaluate(root => {
        const cards = root.__DeviceMatching.state.cards;
        return [cards[0].id, cards.find(card => card.pairId !== cards[0].pairId).id];
    });
    for (const id of wrong) await page.locator(`[data-card-id="${id}"]`).click();
    await page.waitForFunction(() => !document.querySelector('.edu-game').__DeviceMatching.state.locked);
    assert.equal(await page.locator('.oj-device-card.is-face-up').count(), 0, 'reduced-motion closes wrong cards');
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    checks++;
    for (const viewport of [{ width: 834, height: 1194 }, { width: 1194, height: 834 }]) {
        const context = await page.context().browser().newContext({ viewport, hasTouch: true, isMobile: true, deviceScaleFactor: 2 });
        try {
            const tablet = await context.newPage(), errors = [];
            tablet.on('pageerror', error => errors.push(error.message));
            await tablet.goto(url);
            await tablet.locator('[data-action=start]').tap();
            await tablet.waitForSelector('.eg-focus-dialog');
            const pairs = await tablet.locator('.edu-game').evaluate(root => {
                const game = root.__DeviceMatching;
                game.state.stageIndex = game.rules.stages.length - 1;
                game.prepareStage();
                const first = game.state.cards[0];
                return { first: first.id, wrong: game.state.cards.find(c => c.pairId !== first.pairId).id, partner: game.state.cards.find(c => c.id !== first.id && c.pairId === first.pairId).id };
            });
            await tablet.locator(`[data-card-id="${pairs.first}"]`).tap();
            await tablet.locator(`[data-card-id="${pairs.wrong}"]`).tap();
            await tablet.setViewportSize({ width: viewport.height, height: viewport.width });
            await tablet.waitForFunction(() => !document.querySelector('.edu-game').__DeviceMatching.state.locked);
            assert(await tablet.locator('.edu-game').evaluate(root => root.__DeviceMatching.state.cards.length === 24 && !root.querySelector('.oj-device-card.is-face-up') && [...root.querySelectorAll('.oj-device-card-back')].every(e => getComputedStyle(e).visibility === 'visible')), 'iPad touch mismatch closes all faces after rotation');
            checks++;
            for (const id of [pairs.first, pairs.partner]) await tablet.locator(`[data-card-id="${id}"]`).tap();
            await tablet.waitForFunction(() => !document.querySelector('.edu-game').__DeviceMatching.state.locked);
            assert.equal(await tablet.locator('.oj-device-card.is-matched').count(), 2, 'iPad touch can match after rotation and mismatch');
            assert.deepEqual(errors, [], 'iPad-sized touch context has no browser errors');
            checks += 2;
            await tablet.screenshot({ path: path.join(out, `tablet-touch-${viewport.width}.png`) });
        } finally { await context.close(); }
    }
    console.log(`PASS: ${checks} card turn and mismatch recovery checks.`);
};
