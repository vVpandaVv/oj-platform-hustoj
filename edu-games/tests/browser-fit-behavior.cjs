const assert = require('node:assert/strict');

module.exports = async ({ page, url }) => {
    const checks = [], check = (name, ok) => checks.push({ name, ok });
    const go = async game => {
        await page.goto(url + '/?game=' + game);
        await page.waitForSelector('.edu-game[data-shell-ready=true]');
    };
    for (const viewport of [{ width: 1280, height: 720 }, { width: 390, height: 844 }, { width: 834, height: 1194 }, { width: 1194, height: 834 }]) {
        await page.setViewportSize(viewport);
        const label = viewport.width + 'px';
        await go('binary-arcade');
        for (let i = 0; i < 8; i++) await page.locator('[data-action=practice]').first().click();
        const inputId = await page.locator('.edu-game').evaluate(root => {
            const game = root.__BinaryArcade;
            const row = game.state.problems.find(p => p.mode === 'bin-to-dec' && p.decimal > 0) || game.state.problems[0];
            row.mode = 'bin-to-dec';
            if (!row.decimal) { row.decimal = 1; row.binary = '1'.padStart(row.bitLength, '0'); }
            game.renderAll();
            return row.id;
        });
        const answer = page.locator(`[data-answer-input][data-row-id="${inputId}"]`);
        await answer.fill('0');
        await page.setViewportSize({ width: viewport.width + 20, height: viewport.height });
        await page.waitForTimeout(60);
        check(label + ' binary input retains focus and text on resize', await answer.evaluate(input => document.activeElement === input && input.value === '0'));
        check(label + ' binary controls fit eight rows', await page.locator('.oj-binary-row').evaluateAll(rows => rows.every(row => {
            const r = row.getBoundingClientRect();
            return [...row.querySelectorAll('[data-bit-index],[data-answer-input],.oj-binary-answer-output')].every(control => { const c = control.getBoundingClientRect(); return c.top >= r.top - 1 && c.bottom <= r.bottom + 1; });
        })));
        await page.locator('.edu-game').evaluate((root, id) => {
            root.querySelector(`[data-answer-input][data-row-id="${id}"]`).focus();
            root.__GameViewport.close();
        }, inputId);
        await page.waitForTimeout(60);
        check(label + ' binary exit retains input focus', await answer.evaluate(input => document.activeElement === input && input.value === '0'));

        await go('keyword-rain');
        await page.locator('[data-action=start-ranked]').click();
        await page.locator('.edu-game').evaluate(root => {
            const game = root.__KeywordRain;
            game.stopLoop();
            const text = [...game.getWords()].sort((a, b) => b.length - a.length)[0];
            game.state.drops = [5, 89].map((x, i) => ({ id: i, text, x, y: 45 + i * 40, color: '#167d48', fallMs: 10000 }));
            game.renderDrops();
        });
        await page.waitForTimeout(60);
        check(label + ' rain words fit both edges', await page.locator('.oj-rain-arena').evaluate(arena => {
            const a = arena.getBoundingClientRect();
            return [...arena.querySelectorAll('.oj-rain-drop')].every(word => { const r = word.getBoundingClientRect(); return r.left >= a.left && r.right <= a.right; });
        }));
        const word = await page.locator('.oj-rain-drop').first().innerText();
        await page.locator('[data-field=input]').fill(word);
        await page.locator('[data-field=input]').press('Enter');
        check(label + ' rain still accepts a displayed word', await page.locator('.edu-game').evaluate(root => root.__KeywordRain.state.correctCount > 0));

        await go('code-typing');
        const sample = await page.locator('.edu-game').evaluate(root => root.__CodeTyping.currentSample().content);
        const editor = page.locator('[data-field=input]');
        await editor.focus();
        await editor.pressSequentially(sample.slice(0, 2));
        await page.waitForSelector('.eg-focus-dialog');
        check(label + ' first typing characters survive focus entry', await editor.inputValue() === sample.slice(0, 2) && await editor.evaluate(input => document.activeElement === input));
        await page.setViewportSize({ width: viewport.width + 20, height: viewport.height });
        check(label + ' typing text survives resize', await editor.inputValue() === sample.slice(0, 2));
        await editor.fill(sample); await editor.press('Enter');
        check(label + ' typing result remains after completion', await page.locator('.edu-game').evaluate(root => root.__CodeTyping.state.status === 'finished') && await editor.inputValue() === sample);
        await editor.press('Space');
        check(label + ' next typing example waits for first character', await page.locator('.edu-game').evaluate(root => root.__CodeTyping.state.status === 'idle') && await editor.inputValue() === '');

        await page.setViewportSize(viewport);
        await go('image-data');
        await page.locator('[data-mode=draw]').click();
        await page.locator('.edu-game').evaluate(root => root.__GameViewport.open());
        await page.locator('[data-color="2"]').click();
        const first = await page.locator('[data-pixel="0"]').boundingBox(), third = await page.locator('[data-pixel="2"]').boundingBox();
        await page.mouse.move(first.x + first.width / 2, first.y + first.height / 2); await page.mouse.down();
        await page.mouse.move(third.x + third.width / 2, third.y + third.height / 2, { steps: 8 }); await page.mouse.up();
        await page.locator('[data-pixel="3"]').hover();
        check(label + ' image drag stops on release', await page.locator('.edu-game').evaluate(root => root.__ImageData.state.pixels.slice(0, 4).join('') === '2220'));
        check(label + ' image and code rows align', await page.locator('.edu-game').evaluate(root => [...root.querySelectorAll('.oj-image-data-row')].every((row, i) => {
            const code = row.getBoundingClientRect(), cell = root.querySelector(`[data-pixel="${i * 16}"]`).getBoundingClientRect();
            return Math.abs(code.top - cell.top) < 2 && Math.abs(code.height - cell.height) < 2;
        })));
        await page.locator('[data-action=undo]').click();
        check(label + ' image undo remains functional', await page.locator('.edu-game').evaluate(root => root.__ImageData.state.pixels.slice(0, 3).every(p => p === '0')));

        for (const mode of ['decode', 'encode', 'rebuild']) {
            await go('character-code');
            await page.locator(`[data-mode="${mode}"]`).click();
            await page.locator('.edu-game').evaluate(root => root.__GameViewport.open());
            const target = await page.locator('.edu-game').evaluate(root => {
                const game = root.__CharacterCode;
                return { text: game.state.challenge.text, codes: game.state.challenge.codes.join(' '), missing: game.state.challenge.usedSymbols.map(symbol => game.state.table[symbol]) };
            });
            if (mode === 'rebuild') for (let i = 0; i < target.missing.length; i++) await page.locator(`[data-rebuild-index="${i}"]`).fill(target.missing[i]);
            else await page.locator('[data-answer-input]').fill(mode === 'decode' ? target.text : target.codes);
            await page.locator('[data-action=submit]').click();
            check(label + ' character ' + mode + ' submits in focus', await page.locator('.edu-game').evaluate(root => root.__CharacterCode.state.solved));
        }
    }
    assert.deepEqual(checks.filter(item => !item.ok), [], 'game interactions after fitting');
    console.log(`PASS: ${checks.length} resized-game interaction checks.`);
};
