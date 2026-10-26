import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const artifacts = new URL('../tests/artifacts/', import.meta.url);
await mkdir(artifacts, { recursive: true });
try {
  for (const [name, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    const page = await browser.newPage({ viewport });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const url = new URL('../tests/fixtures/submission-feedback-workbench-visual.html', import.meta.url);
    url.search = '?student_note=1';
    await page.goto(url.href);
    const summary = page.locator('[data-student-note-field="summary"]');
    await summary.waitFor();
    const warning = page.locator('[data-report-writing="school-record"] [data-writing-check]');
    assert.match(await warning.textContent(), /명사형/);
    assert.match(await warning.textContent(), /1인칭/);
    assert.equal(await summary.inputValue(), '나는 관찰 내용을 기록했습니다.', 'Legacy text must not be rewritten.');
    await summary.fill('관찰 대상을 기록하고 환경과 생물의 관계를 근거와 연결하여 설명함.');
    assert.equal(await warning.isHidden(), true);
    await page.locator('[data-student-note-field="strengths"]').fill('');
    await page.locator('[data-student-note-field="support-suggestions"]').fill('');
    await page.locator('[data-student-note-action="save"]').click();
    const approve = page.locator('[data-student-note-action="approve"]');
    await approve.waitFor();
    assert.deepEqual(await page.evaluate(() => window.__noteRequests[0].body.payload.note.strengths), []);
    assert.deepEqual(await page.evaluate(() => window.__noteRequests[0].body.payload.note.support_suggestions), []);
    await summary.fill('관찰 내용을 비교하여 설명함.');
    await approve.click();
    assert.match(await page.locator('[data-review-role="notice"]').textContent(), /먼저 저장/);
    assert.equal(await page.evaluate(() => window.__noteRequests.length), 1, 'Unsaved edits must never approve an older revision.');
    await page.locator('[data-student-note-field="strengths"]').fill('환경과 생물의 관계를 설명함.');
    await page.locator('[data-student-note-field="support-suggestions"]').fill('비교 관찰을 추가하는 지도가 필요함.');
    await page.locator('[data-student-note-action="save"]').click();
    await page.waitForFunction(() => window.__noteRequests.length === 2 && !document.querySelector('[data-student-note-action="approve"]')?.disabled);
    page.once('dialog', async (dialog) => { assert.match(dialog.message(), /실제 관찰 근거/); await dialog.dismiss(); });
    await approve.click();
    assert.equal(await page.evaluate(() => window.__noteRequests.length), 2, 'Cancelled teacher confirmation must not approve.');
    const guidance = page.getByText('학생부 특기사항 작성요령', { exact: true });
    await guidance.click();
    await guidance.scrollIntoViewIfNeeded();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    const editorOverflow = await page.locator('[data-report-writing="school-record"]').evaluate((element) => element.scrollWidth > element.clientWidth + 1);
    assert.equal(editorOverflow, false);
    await page.screenshot({ path: fileURLToPath(new URL(`report-writing-${name}.png`, artifacts)) });
    await summary.scrollIntoViewIfNeeded();
    await page.screenshot({ path: fileURLToPath(new URL(`report-writing-editor-${name}.png`, artifacts)) });
    page.once('dialog', (dialog) => dialog.accept());
    await approve.click();
    await page.waitForSelector('[data-student-note-action="include"]');
    assert.equal(await page.evaluate(() => window.__noteRequests.length), 3);
    assert.match(await page.locator('[data-review-role="detail"]').textContent(), /지도 참고사항 \(학생부 기록과 별도\)/);
    assert.deepEqual(errors, []);
    await page.close();
    const failurePage = await browser.newPage({ viewport });
    failurePage.on('pageerror', (error) => errors.push(error.message));
    for (const failure of ['legacy', 'evidence']) {
      url.search = `?student_note=1&note_failure=${failure}`;
      await failurePage.goto(url.href);
      const retry = failurePage.locator('[data-student-note-action="generate"]');
      await retry.waitFor();
      const notice = failurePage.locator('.oj-ai-submission-review__feedback-warning').filter({ hasText: '이번 초안은 저장하지 않았습니다' });
      await notice.waitFor();
      const text = await notice.textContent();
      assert.match(text, failure === 'legacy' ? /세부 원인이 기록되지 않았습니다/ : /근거 문구/);
      assert.match(text, /기존 점수와 승인 기록/);
      assert.equal(await retry.isEnabled(), true);
      await notice.scrollIntoViewIfNeeded();
      assert.equal(await notice.evaluate((element) => element.scrollWidth > element.clientWidth + 1), false);
      await failurePage.screenshot({ path: fileURLToPath(new URL(`student-note-failure-${name}-${failure}.png`, artifacts)) });
    }
    assert.deepEqual(errors, []);
    await failurePage.close();
  }
  console.log('Report writing browser checks passed: desktop/mobile, live style warnings, save/approve protection, teacher confirmation and preserved history.');
} finally {
  await browser.close();
}
