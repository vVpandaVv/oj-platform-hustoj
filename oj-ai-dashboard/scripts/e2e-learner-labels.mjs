import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const artifacts = path.resolve('tests/artifacts');
await mkdir(artifacts, { recursive: true });
const fixture = name => pathToFileURL(path.resolve(`tests/fixtures/${name}-visual.html`)).href;
try {
  for (const [label, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(fixture('submission-evaluation-review-workbench'));
    await page.waitForFunction(() => document.querySelectorAll('[data-review-field="official-points"]').length === 3);
    const mode = page.locator('[data-learner-label-mode]');
    const detailName = page.locator('.oj-ai-submission-review__detail-header [data-learner-label]');
    await page.locator('[data-review-field="official-points"]').first().fill('3');
    await page.evaluate(() => {
      window.__scoreInput = document.querySelector('[data-review-field="official-points"]');
      window.__selected = document.querySelector('[data-review-action="select"][aria-current="true"]');
    });
    for (const [value, expected] of [['management_name', '김하늘 (1반)'], ['login', 'student10101'], ['display_name', '김하늘']]) {
      await mode.selectOption(value);
      assert.ok((await detailName.textContent()).startsWith(expected));
      assert.equal(await page.locator('[data-review-action="select"][aria-current="true"] strong').textContent(), expected);
      assert.equal(await page.evaluate(() => window.__scoreInput === document.querySelector('[data-review-field="official-points"]') && window.__scoreInput.value === '3' && window.__selected === document.querySelector('[data-review-action="select"][aria-current="true"]')), true, 'Changing names must not rebuild grading controls or selection.');
    }
    await mode.selectOption('login');
    await page.locator('[data-review-field="search"]').fill('student10101');
    await page.waitForFunction(() => document.querySelectorAll('[data-review-action="select"]').length === 1);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'No horizontal overflow.');
    await page.screenshot({ path: path.join(artifacts, `learner-labels-evaluation-${label}.png`), fullPage: true });
    await page.reload();
    await page.waitForFunction(() => document.querySelectorAll('[data-review-field="official-points"]').length === 3);
    assert.equal(await mode.inputValue(), 'login', 'Preference survives reload.');

    await page.goto(fixture('assignment-lifecycle'));
    await page.waitForFunction(() => document.querySelector('[data-field="context-filter"]')?.options.length === 2);
    await page.selectOption('[data-field="context-filter"]', '0');
    await page.getByRole('button', { name: '분수 비교 활동지', exact: true }).click();
    await page.getByRole('button', { name: '제출 현황', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('[data-role="submission-rows"] tr').length === 3);
    const rows = page.locator('[data-role="submission-rows"] [data-learner-label]');
    await mode.selectOption('management_name');
    assert.equal(await rows.first().textContent(), '김하늘 (1반)');
    await mode.selectOption('login');
    assert.equal(await rows.first().textContent(), 'student10101');
    assert.equal(await rows.nth(1).textContent(), '이바다', 'Students without optional names remain visible.');
    await page.screenshot({ path: path.join(artifacts, `learner-labels-submissions-${label}.png`), fullPage: true });
    await page.locator('[data-action="view-submission"]').first().click();
    await page.waitForFunction(() => !document.querySelector('[data-role="submission-detail"]')?.hidden);
    assert.equal(await page.locator('[data-submission="student"]').textContent(), 'student10101');
    const answerBefore = await page.locator('[data-role="submission-answers"]').textContent();
    await mode.selectOption('management_name');
    assert.equal(await page.locator('[data-submission="student"]').textContent(), '김하늘 (1반)');
    assert.equal(await page.locator('[data-role="submission-answers"]').textContent(), answerBefore);
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS ${label}: evaluation/list/detail labels, unsaved scores, search, reload, submission status/detail, screenshots`);
  }
} finally {
  await browser.close();
}
