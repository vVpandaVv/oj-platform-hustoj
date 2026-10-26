'use strict';
// Local real-browser check only. No live OJ data or external calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.OJ_ML_PLAYWRIGHT || 'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const plugin = path.resolve(__dirname, '..');
const output = path.join(plugin, '../_notes/tmp/oj-ml-grade-adjustment-ui');
const asset = name => fs.readFileSync(path.join(plugin, 'resources', name), 'utf8');
const errors = [], requests = [], checks = [];
async function capture(page, name, width) {
    const bounds = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    assert(bounds.document <= width + 1, name + ': no page overflow');
    await page.screenshot({ path: path.join(output, name + '-' + width + '.png') });
}
(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] });
    try {
        for (const width of [1280, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 1050 } });
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => { requests.push(route.request().url()); route.abort(); });
            await page.setContent('<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;padding:16px;font-family:Arial,Malgun Gothic,sans-serif"><main class="oj-ml-app" style="max-width:900px;margin:auto"></main></body></html>');
            await page.addStyleTag({ content: asset('css/addon.css') });
            await page.addScriptTag({ content: asset('js/rubric-tools.js') });
            await page.evaluate(() => {
                const rubric = { version: 2, criteria: [{ id: 'data', title: '데이터 이해·전처리', description: '데이터 특성에 따른 전처리 선택의 근거', maxScore: 40, levels: [
                    { id: 'excellent', label: '충분히 충족', score: 40, description: '결측률과 분포를 확인하고 데이터 특성에 맞는 결측치 처리 방법과 선택 이유를 근거로 설명했습니다.' },
                    { id: 'proficient', label: '대체로 충족', score: 32, description: '데이터와 전처리 방법을 설명했으나 일부 선택 이유를 뒷받침하는 근거가 부족합니다.' },
                    { id: 'developing', label: '일부 충족', score: 16, description: '처리 방법을 기록했으나 데이터 통계나 선택 이유의 설명이 부족합니다.' },
                    { id: 'missing', label: '미충족', score: 0, description: '데이터를 이해하거나 전처리 방법을 선택한 근거를 확인할 수 없습니다.' },
                ] }] };
                const review = { status: 'released', details: { rubricSnapshot: rubric, criteriaScores: [{ id: 'data', levelId: 'proficient', score: 32, feedback: '전처리 방법의 설명은 좋습니다. 결측률과 연결하여 선택 이유를 보완하세요.' }] } };
                document.querySelector('main').innerHTML = '<h2>학생 제출 평가</h2><form class="oj-ml-form">' + window.OJMLRubric.reviewFields(rubric, review) + '<div class="oj-ml-actions"><button type="submit">비공개 저장</button><button type="button">저장하고 공개</button></div></form>';
                window.saves = 0; document.querySelector('form').addEventListener('submit', event => { event.preventDefault(); window.saves++; });
                window.OJMLRubric.bindReview(document.querySelector('form'));
            });
            const trigger = page.locator('[data-rubric-grade-adjust]');
            assert.equal(await page.locator('[data-rubric-level]').isVisible(), false);
            assert.equal(await trigger.textContent(), '32 / 40점점수 조정');
            await capture(page, 'current', width);
            await trigger.focus(); await page.keyboard.press('Enter');
            const picker = page.locator('[data-rubric-grade-picker]');
            assert.equal(await picker.isVisible(), true);
            assert.equal(await page.evaluate(() => document.activeElement.dataset.rubricGradeChoice), 'proficient');
            await capture(page, 'choices', width);
            await page.evaluate(() => {
                const form = document.querySelector('form'); form.dataset.reviewSaving = '1';
                form.querySelectorAll('button').forEach(button => { button.disabled = true; });
                document.querySelector('[data-rubric-grade-choice="excellent"]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
                document.querySelector('[data-rubric-grade-clear]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
            });
            await page.keyboard.press('Enter'); await page.keyboard.press('Space'); await page.keyboard.press('Escape');
            assert.equal(await picker.isVisible(), true);
            assert.equal(await page.locator('[data-rubric-level]').inputValue(), 'proficient');
            await page.evaluate(() => { delete document.querySelector('form').dataset.reviewSaving; document.querySelectorAll('button').forEach(button => { button.disabled = false; }); });
            await page.locator('[data-rubric-grade-choice="excellent"]').focus(); await page.keyboard.press('Space');
            assert.equal(await picker.isVisible(), false);
            assert.equal(await page.locator('[data-rubric-level]').inputValue(), 'excellent');
            assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-rubric-grade-adjust')), true);
            assert.equal(await trigger.textContent(), '40 / 40점점수 조정');
            assert.match(await page.locator('[data-rubric-selected-level]').textContent(), /충분히 충족 · 40점/);
            assert.equal(await page.evaluate(() => window.saves), 0);
            await capture(page, 'adjusted', width);
            await trigger.press('Enter'); await page.keyboard.press('Escape');
            assert.equal(await picker.isVisible(), false);
            assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
            await trigger.press('Enter'); await page.locator('[data-rubric-grade-clear]').click();
            assert.equal(await page.locator('[data-rubric-level]').inputValue(), '');
            assert.equal(await page.locator('[data-rubric-selected-level]').isVisible(), false);
            assert.equal(await page.evaluate(() => window.saves), 0);
            checks.push({ width, keyboard: true, gradeAdjustment: true, clear: true, localOnly: true, overflow: false });
            await context.close();
        }
        assert.deepEqual(errors, []); assert.deepEqual(requests, []);
        console.log(JSON.stringify({ browser: await browser.version(), checks, pageErrors: errors.length, externalRequests: requests.length, screenshots: 6, output }, null, 2));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
