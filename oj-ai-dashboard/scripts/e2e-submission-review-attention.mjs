import assert from 'node:assert/strict';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { mkdir } from 'node:fs/promises';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
await mkdir('tests/artifacts', { recursive: true });
try {
  for (const [name, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    const page = await browser.newPage({ viewport });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(pathToFileURL(path.resolve('tests/fixtures/submission-evaluation-review-workbench-visual.html')).href);
    const summary = page.locator('[data-review-role="attention-summary"]');
    const selectedRow = page.locator('[data-review-action="select"][aria-current="true"]');
    await summary.waitFor();
    assert.equal(await summary.locator('[data-review-action="attention-item"]').count(), 2);
    assert.match(await summary.innerText(), /설명과 추론/);
    assert.match(await summary.innerText(), /관찰에서 확인한 요소/);
    assert.match(await summary.innerText(), /안전 확인/);
    assert.match(await summary.innerText(), /사유·메모/);
    await page.getByRole('button', { name: '교사 확인 항목 2개 보기', exact: true }).click();
    assert.equal(await summary.evaluate(element => document.activeElement === element), true);
    await page.screenshot({ path: `tests/artifacts/submission-review-attention-${name}.png`, fullPage: true });
    const inboxScroll = await page.locator('.oj-ai-submission-review__inbox').evaluate(element => element.scrollTop);
    const third = page.locator('[data-binding-key]').nth(2);
    await third.locator('[data-review-field="official-points"]').fill('1');
    await third.locator('[data-review-field="reason-code"]').selectOption('evidence_checked');
    await third.locator('[data-review-field="reason-note"]').fill('안전한 실천 내용 확인');
    await summary.getByRole('button', { name: '안전한 실천 점수와 근거 확인', exact: true }).click();
    assert.equal(await third.evaluate(element => document.activeElement === element), true);
    assert.equal(await third.locator('[data-review-field="official-points"]').inputValue(), '1');
    assert.equal(await third.locator('[data-review-field="reason-note"]').inputValue(), '안전한 실천 내용 확인');
    assert.equal(await page.locator('.oj-ai-submission-review__inbox').evaluate(element => element.scrollTop), inboxScroll);
    assert.equal(await page.evaluate(() => window.__reviewRequests.length + window.__evaluationRequests.length), 0, 'Opening attention items is not approval or an AI request.');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    assert.equal(await summary.evaluate(element => element.scrollWidth > element.clientWidth + 1), false);

    await page.evaluate(() => { window.__reviewPersistTransitions = true; });
    await page.getByRole('button', { name: '선택 점수 확정', exact: true }).click();
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).waitFor();
    assert.equal(await summary.count(), 0, 'Approved scores must not still be shown as pending attention.');
    assert.equal(await page.locator('[data-review-action="attention-summary"]').count(), 0);
    assert.equal(await selectedRow.locator('.oj-ai-submission-review__priority').count(), 0, 'The student list must clear approved attention immediately.');
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).click();
    await page.locator('[data-review-field="reject-reason"]').fill('채점 근거 재확인');
    await page.locator('[data-review-action="confirm-reject"]').click();
    await summary.waitFor();
    assert.equal(await summary.locator('[data-review-action="attention-item"]').count(), 2);
    assert.match(await selectedRow.innerText(), /안전 확인/);

    await page.evaluate(() => {
      const detail = window.__reviewFixture;
      Object.assign(detail.evaluation.candidate, { score: '8.0000', safety_flagged: false, abstained: false });
      detail.evaluation.priority.review_needed_count = 2;
      Object.assign(detail.selected_result, { candidate_score: '8.0000', safety_flagged: false, abstained: false });
      detail.selected_result.items[0].flags = ['teacher_review_required'];
      detail.selected_result.items[1].flags = ['response_ambiguous'];
      Object.assign(detail.selected_result.items[2], { candidate_points: '1.5000', decision: 'score', abstain_reason: null, flags: [] });
    });
    await page.getByRole('button', { name: '평가 새로고침', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-review-action="select"][aria-current="true"]')?.textContent.includes('확인 필요 · 평가 항목 2개'));
    assert.equal(await summary.locator('[data-review-action="attention-item"]').count(), 2);
    assert.match(await summary.innerText(), /교사 확인.*응답 확인/s);
    await page.getByRole('button', { name: '교사 확인 항목 2개 보기', exact: true }).click();
    await page.screenshot({ path: `tests/artifacts/submission-review-list-attention-${name}.png`, fullPage: true });

    await page.evaluate(() => {
      const result = window.__reviewFixture.selected_result;
      result.provider_status = 'not_requested';
      result.items.forEach(item => { item.flags = []; item.candidate_points = null; item.abstain_reason = 'not_requested'; });
    });
    await page.getByRole('button', { name: '평가 새로고침', exact: true }).click();
    await page.waitForFunction(() => [...document.querySelectorAll('.oj-ai-submission-review__metric')].some(item => item.textContent.includes('채점 필요')));
    assert.equal(await summary.count(), 0, 'Manual grading must not imply an AI warning.');
    assert.match(await selectedRow.innerText(), /교사 채점 대기/);
    assert.deepEqual(errors, []);
    await page.close();
    console.log(`PASS ${name}: attention list, focus/scroll, preserved inputs, approval/withdrawal, manual grading and layout.`);
  }
} finally { await browser.close(); }
