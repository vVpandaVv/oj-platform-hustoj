'use strict';
// Isolated real Chrome fixture: synthetic content only, all external requests blocked.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.OJ_ML_PLAYWRIGHT || 'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const plugin = path.resolve(__dirname, '..');
const output = path.join(plugin, '../_notes/tmp/oj-ml-data-evidence-ui');
const asset = file => fs.readFileSync(path.join(plugin, 'resources', file), 'utf8');
const addon = asset('js/addon.js'), closing = addon.lastIndexOf('}());');
const script = addon.slice(0, closing) + 'window.dataUiTest = { renderConsoleAssignments, submissionPreviewHtml, aiReviewBodyHtml };\n' + addon.slice(closing);
const errors = [], requests = [], checks = [];
async function capture(page, name, width) {
    const bounds = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    assert(bounds.document <= width + 1, name + ': no horizontal page overflow');
    await page.screenshot({ path: path.join(output, name + '-' + width + '.png') });
}
(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] });
    try {
        for (const width of [1280, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => { requests.push(route.request().url()); route.abort(); });
            await page.setContent('<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;padding:16px"><main class="oj-ml-app" style="max-width:1120px;margin:auto"></main></body></html>');
            await page.addStyleTag({ content: asset('css/addon.css') });
            for (const name of ['teacher-workflow.js', 'rubric-tools.js', 'assignment-policy-tools.js']) await page.addScriptTag({ content: asset('js/' + name) });
            await page.addScriptTag({ content: script });
            await page.evaluate(async () => {
                await window.dataUiTest.renderConsoleAssignments(document.querySelector('main'), {
                    async get(route) { return route.startsWith('console/contexts?') ? { items: [{ id: 'g1', type: 'group', label: '합성 테스트 그룹', lifecycleState: 'active' }] } : { items: [], total: 0 }; },
                });
            });
            const summary = page.locator('[data-dataset-description] > summary');
            await summary.focus(); await page.keyboard.press('Enter');
            await page.locator('[name="datasetDescription"]').fill('공개 기상 관측 자료입니다. 기온·습도·풍속으로 다음 날 강수를 예측합니다.');
            await page.locator('[data-dataset-description-add]').focus(); await page.keyboard.press('Space');
            await assert.doesNotReject(() => page.getByText('선택 문항을 추가했습니다.', { exact: false }).waitFor());
            await summary.scrollIntoViewIfNeeded();
            await capture(page, 'authoring', width);
            await page.locator('[data-assignment-section="rubric"] > summary').click();
            const first = page.locator('[data-rubric-item]').first();
            await first.locator('[data-rubric-evidence] > summary').focus(); await page.keyboard.press('Enter');
            const check = first.locator('[data-rubric-evidence-key="experimentComparison"]');
            await check.focus(); await page.keyboard.press('Space');
            assert.equal(await check.isChecked(), true);
            await first.locator('[data-rubric-evidence]').scrollIntoViewIfNeeded();
            await capture(page, 'requirements', width);
            await page.evaluate(() => {
                const profiles = { status: 'complete', datasets: [{ profileId: 'd1', status: 'complete', rowCount: 8760, columnCount: 14, rowsAnalyzed: 8760, columns: [
                    { index: 0, type: 'numeric', missingCount: 40, missingRate: 40 / 8760, distinctCount: 128, numeric: { mean: 17.5, stdDev: 9.2 } },
                    { index: 1, type: 'categorical', missingCount: 0, missingRate: 0, distinctCount: 2, distribution: { counts: [6000, 2760], suppressed: false } },
                ] }], bindings: [{ role: 'experiment', experimentIndex: 0, usage: 'training', profileId: 'd1', featureColumns: [0], targetColumn: 1 }] };
                document.querySelector('main').innerHTML = window.dataUiTest.submissionPreviewHtml({ project: { name: '강수 예측 실습' }, datasetProfiles: profiles,
                    datasetContext: { teacherDescription: '기온·습도·풍속과 강수 관측 자료', studentDescription: '공개 관측 자료에서 강수 여부를 분류합니다.' } });
            });
            await page.locator('.oj-ml-dataset-profiles summary').click();
            await page.locator('.oj-ml-dataset-profiles').scrollIntoViewIfNeeded();
            await capture(page, 'statistics', width);
            const table = page.locator('.oj-ml-dataset-profiles .oj-ml-table-wrap');
            assert.equal(await table.evaluate(element => ['auto', 'scroll'].includes(getComputedStyle(element).overflowX)), true);
            await page.evaluate(() => {
                document.querySelector('main').innerHTML = window.dataUiTest.aiReviewBodyHtml({ available: true,
                    evidenceReadiness: [{ id: 'data', title: '데이터 이해·전처리', status: 'needs_review', reasons: ['데이터 출처와 변수 의미에 관한 설명이 없습니다.'] }],
                    result: { state: 'needs_review', criteriaScores: [{ id: 'data', title: '데이터 이해·전처리', reviewStatus: 'needs_review', reviewReasons: ['전처리 전후의 변화는 교사가 확인해야 합니다.'], feedback: '제출 통계에서 결측치를 확인할 수 있지만 선택 이유는 추가 설명이 필요합니다.' }] } });
                window.scrollTo(0, 0);
            });
            await capture(page, 'review', width);
            const bounds = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
            assert(bounds.document <= width + 1, 'No horizontal page overflow at ' + width);
            assert.equal(await page.locator('[data-oj-ml-ai-action="apply"]').count(), 0);
            checks.push({ width, keyboard: true, statistics: true, needsReview: true, overflow: false });
            await context.close();
        }
        assert.deepEqual(errors, []); assert.deepEqual(requests, []);
        console.log(JSON.stringify({ browser: await browser.version(), checks, pageErrors: errors.length, externalRequests: requests.length, screenshots: 8, output }, null, 2));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
