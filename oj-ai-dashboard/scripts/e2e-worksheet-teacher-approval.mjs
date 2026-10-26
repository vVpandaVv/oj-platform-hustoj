import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixture = pathToFileURL(path.join(root, 'tests/fixtures/worksheet-workbench-visual.html')).href;
const artifacts = path.join(root, 'tests/artifacts');
await mkdir(artifacts, { recursive: true });
const playwright = await loadPlaywright();
const browser = await playwright.chromium.launch(browserLaunchOptions());
try {
  for (const [name, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844]]) {
    const page = await browser.newPage({ viewport: { width, height } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => { window.__teacherOnly = true; });
    await page.goto(fixture, { waitUntil: 'load' });
    await page.waitForFunction(() => document.querySelector('#ws-plate-template-builder-root')?.getAttribute('aria-busy') === 'false');
    await page.waitForFunction(() => document.querySelector('#ws-template-title')?.value === '우리 동네 환경 조사');
    assert.equal(await page.locator('[data-action="approve"]').isDisabled(), true, 'Approval must await document validation.');
    for (const [code, label] of [
      ['worksheet_evaluation_not_run', 'AI 점검 미실행'],
      ['worksheet_evaluation_failed', 'AI 점검 실패'],
      ['worksheet_evaluation_cancelled', 'AI 점검 취소됨'],
    ]) {
      await page.evaluate(value => { window.__approvalWarning = value; }, code);
      await page.locator('.oj-ai-workbench__more > summary').click();
      await page.getByRole('button', { name: '문서 검증', exact: true }).click();
      await page.locator('.oj-ai-workbench__more > summary').click();
      await page.waitForFunction(expected => document.querySelector('[data-role="release-message"]')?.textContent === expected, `${label} · 교사 검토 승인 가능`);
      assert.equal(await page.getByRole('button', { name: '교사 검토 승인', exact: true }).isEnabled(), true);
    }
    const controls = page.locator('[data-role="release-controls"]');
    await controls.scrollIntoViewIfNeeded();
    assert.equal(await controls.evaluate(element => {
      const rect = element.getBoundingClientRect();
      return rect.left >= 0 && rect.right <= innerWidth && element.scrollWidth <= element.clientWidth + 1;
    }), true, 'Teacher approval controls must fit the viewport.');
    await controls.screenshot({ path: path.join(artifacts, `worksheet-teacher-approval-${name}.png`) });
    await page.getByRole('button', { name: '교사 검토 승인', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-role="notice"]')?.textContent === '교사 검토가 승인되었습니다.');
    assert.equal(await page.locator('[data-action="approve"]').isDisabled(), true, 'Approved revision must not be approved twice.');
    await page.getByRole('button', { name: '발행', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: '배포용 버전 발행', exact: true });
    await dialog.getByRole('button', { name: '발행하기', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-role="release-title"]')?.textContent === '발행됨');
    const result = await page.evaluate(() => ({
      aiRequests: window.__unexpectedAiRequests || 0,
      approval: window.__lastApprovalRequest,
      publication: window.__lastPublicationRequest,
      publicationCount: window.__publicationRequestCount,
    }));
    assert.equal(result.aiRequests, 0);
    assert.equal(result.publicationCount, 1);
    assert.deepEqual(result.approval.payload, {});
    assert.equal(result.publication.expected.expected_state, 'approved');
    assert.equal(result.approval.expected.expected_approval_decision_hash, result.publication.expected.expected_approval_decision_hash);
    assert.deepEqual(errors, []);
    await page.close();
    console.log(`PASS teacher worksheet approval ${name}: AI query denied, not-run/failed/cancelled, validation, explicit approval/publication, no AI requests.`);
  }
} finally {
  await browser.close();
}
