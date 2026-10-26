const assert = require('node:assert/strict');
const path = require('node:path');

module.exports = async ({ page, out }) => {
    let layouts = 0, photos = 0;
    await page.locator('[data-action=start]').click();
    await page.waitForSelector('.eg-focus-dialog');
    await page.locator('.edu-game').evaluate(root => root.__GameViewport.close());
    const stages = await page.locator('.edu-game').evaluate(root => root.__DeviceMatching.rules.stages);
    for (const viewport of [{ width: 1280, height: 720 }, { width: 390, height: 844 }, { width: 768, height: 1024 }]) {
        await page.setViewportSize(viewport);
        for (const focused of [false, true]) {
            await page.locator('.edu-game').evaluate((root, focused) => root.__GameViewport[focused ? 'open' : 'close'](), focused);
            for (let stage = 0; stage < stages.length; stage++) {
                const pairs = stages[stage].pairs;
                const total = await page.locator('.edu-game').evaluate(root => root.__DeviceMatching.rules.devices.length);
                for (let offset = 0; offset < total; offset += pairs) {
                    await page.locator('.edu-game').evaluate((root, { stage, offset, pairs }) => {
                        const game = root.__DeviceMatching;
                        game.state.stageIndex = stage;
                        game.state.openedIds = [];
                        game.state.cards = Array.from({ length: pairs }, (_, i) => game.rules.devices[(offset + i) % game.rules.devices.length])
                            .flatMap((device, i) => ['image', 'text'].map(type => ({ id: `photo-test-${stage}-${offset}-${i}-${type}`, pairId: device.id, type, device, faceUp: true, matched: false })));
                        game.render();
                        game.fields.deck.querySelectorAll('img').forEach(img => { img.loading = 'eager'; });
                        root.__GameViewport.fit();
                    }, { stage, offset, pairs });
                    await page.locator('.oj-device-photo-wrap img').evaluateAll(images => Promise.all(images.map(img => img.decode())));
                    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                    const result = await page.locator('.oj-device-photo-wrap img').evaluateAll(images => {
                        const inside = (a, b) => a.left >= b.left - 1 && a.top >= b.top - 1 && a.right <= b.right + 1 && a.bottom <= b.bottom + 1;
                        return images.map(img => {
                            const photo = img.parentElement, front = photo.parentElement, chip = front.querySelector('.oj-device-chip');
                            const imageRect = img.getBoundingClientRect(), photoRect = photo.getBoundingClientRect(), frontRect = front.getBoundingClientRect(), chipRect = chip.getBoundingClientRect();
                            return { name: img.alt, visible: imageRect.width > 0 && imageRect.height > 0 && inside(imageRect, photoRect) && inside(photoRect, frontRect) && inside(chipRect, frontRect) && photoRect.bottom <= chipRect.top + 1, fit: getComputedStyle(img).objectFit };
                        });
                    });
                    const label = `${viewport.width}×${viewport.height}, ${focused ? 'focused' : 'page'}, ${pairs * 2} cards, offset ${offset}`;
                    assert.deepEqual(result.filter(photo => !photo.visible || photo.fit !== 'contain'), [], 'whole photos and labels fit: ' + label);
                    if (focused) {
                        assert(await page.locator('.eg-focus-area').evaluate(area => area.scrollWidth <= area.clientWidth + 1 && area.scrollHeight <= area.clientHeight + 1), 'focused board fits: ' + label);
                    }
                    if (offset === 0 && [0, stages.length - 1].includes(stage) && viewport.width !== 768) {
                        await page.screenshot({ path: path.join(out, `card-photos-${focused ? 'focus' : 'page'}-${viewport.width}-${pairs * 2}.png`), fullPage: true });
                    }
                    photos += result.length;
                    layouts++;
                }
            }
        }
    }
    console.log(`PASS: ${photos} whole-photo checks across ${layouts} card layouts.`);
};
