import assert from 'node:assert/strict';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(import.meta.dirname, '..');
const fixture = pathToFileURL(path.join(root, 'tests/fixtures/worksheet-evaluation-workbench-visual.html')).href;
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
try {
  for (const width of [1280, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(`${fixture}?no_findings=1`);
    await page.getByRole('heading', { name: '지적 0건', exact: true }).waitFor();
    const detail = page.locator('[data-role="evaluation-detail"]');
    assert.equal(await detail.getByText('교사 검토 대기', { exact: true }).count(), 2);
    assert.equal(await detail.getByText('AI 판정', { exact: true }).count(), 1);
    assert.equal(await detail.getByText('양호', { exact: true }).count(), 1);
    assert.equal(await detail.locator('.oj-ai-evaluation__dimension').count(), 6);
    assert.equal(await detail.locator('.oj-ai-evaluation__dimension [data-tone="success"]').count(), 6);
    assert.equal(await page.evaluate(() => window.__emptyReviewRequest), undefined, 'Reading a positive result must not complete teacher review');
    await page.screenshot({ path: path.join(root, `tests/artifacts/worksheet-review-status-${width}.png`), fullPage: true });
    assert.equal(await page.getByRole('button', { name: '검토 저장', exact: true }).count(), 0);
    await page.getByRole('button', { name: '검토 완료', exact: true }).click();
    await page.waitForFunction(() => window.__emptyReviewRequest && document.querySelector('.oj-ai-evaluation__review-status')?.textContent.includes('완료'));
    const request = await page.evaluate(() => window.__emptyReviewRequest);
    assert.deepEqual(request.payload, { item_decisions: [], complete_review: true });
    assert.equal(request.expected.expected_state, 'review_required');
    assert.equal(request.expected.expected_review_no, 0);
    assert.equal(await detail.getByText('교사 검토 대기', { exact: true }).count(), 0);
    assert.equal(await detail.getByText('교사 검토 완료', { exact: true }).count(), 1);
    assert.equal(await detail.getByText('양호', { exact: true }).count(), 1);
    assert.deepEqual(errors, []);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    await page.goto(`${fixture}?no_findings=1&can_review=0`);
    await page.getByRole('heading', { name: '지적 0건', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: '검토 완료', exact: true }).count(), 0);
    assert.equal(await detail.getByText('교사 검토 대기', { exact: true }).count(), 1);
    assert.equal(await detail.getByText('AI 판정', { exact: true }).count(), 1);
    await page.close();
    console.log(`PASS zero findings ${width}: all dimensions meet, AI verdict separate from teacher review, explicit completion, empty decisions, review number, permission guard`);
  }
} finally {
  await browser.close();
}
