import assert from 'node:assert/strict';
import path from 'node:path';
import { mkdir } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
await mkdir('tests/artifacts', { recursive: true });
try {
  for (const [name, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    const page = await browser.newPage({ viewport });
    page.on('dialog', dialog => dialog.accept());
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const fixture = pathToFileURL(path.resolve('tests/fixtures/submission-evaluation-review-workbench-visual.html'));
    fixture.search = '?roster&missing_grade&membership_unavailable';
    await page.goto(fixture.href);
    await page.locator('[data-review-role="content"]').waitFor({ state: 'visible' });
    assert.match(await page.locator('[data-role="submission-summary"]').innerText(), /기존 배포 기록 5명/);
    assert.match(await page.locator('[data-review-role="notice"]').innerText(), /현재 명단 연동 지연/);
    assert.equal(await page.locator('[data-item-id]').count(), 5);
    assert.equal(await page.getByText('현재 그룹·수업 명단에서 제외됨', { exact: true }).count(), 0);
    assert.equal(await page.getByText('현재 명단 확인 불가', { exact: true }).count(), 5);
    await page.locator('[data-item-id="01K5A000000000000000000001"]').click();
    await page.locator('[data-review-role="detail"]').getByText('AI 평가 요약', { exact: true }).waitFor();
    await page.screenshot({ path: `tests/artifacts/roster-recovery-${name}.png`, fullPage: true });
    await page.locator('[data-item-id="target-missing"]').click();
    const form = page.locator('[data-target-grade="target-missing"]');
    await form.locator('input[name="category"][value="not_submitted"]').check();
    assert.equal(await form.locator('[name="score"]').inputValue(), '0');
    assert.equal(await page.evaluate(() => window.__targetGradeRequests.length + window.__evaluationRequests.length), 0, 'Opening recovery views must not grade or enqueue AI.');
    await page.locator('[data-review-field="submission-status"]').selectOption('not_submitted');
    await page.waitForFunction(() => document.querySelectorAll('[data-item-id]').length === 2);
    assert.equal(await page.locator('[data-item-id]').count(), 2);
    fixture.search = '?roster&roster_error';
    await page.goto(fixture.href);
    await page.locator('[data-review-role="notice"]').getByText(/학생 명단을 불러오지 못했습니다/).waitFor();
    assert.equal(await page.locator('[data-review-role="content"]').isVisible(), false, 'A failed stored-target read must not invent a complete list.');
    assert.deepEqual(errors, []);
    await page.close();
  }
} finally {
  await browser.close();
}
console.log('PASS desktop/mobile roster recovery: stored results, missing-student grading, scoped counts, unknown membership, no implicit writes, stored-data failure.');
