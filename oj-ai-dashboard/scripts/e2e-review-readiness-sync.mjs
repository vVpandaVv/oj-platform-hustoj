import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixture = (name) => pathToFileURL(path.join(root, 'tests/fixtures', name)).href;
const worksheetId = '01HZY3M4N5P6Q7R8S9T0V1W2X3';
const revisionId = '01HZY3M4N5P6Q7R8S9T0V1W2X4';
const playwright = await loadPlaywright();
const browser = await playwright.chromium.launch(browserLaunchOptions());
const errors = [];
try {
  const review = await browser.newPage();
  review.on('pageerror', (error) => errors.push(error.message));
  await review.goto(`${fixture('worksheet-evaluation-workbench-visual.html')}?no_findings=1`);
  await review.getByRole('button', { name: '검토 완료', exact: true }).waitFor();
  await review.evaluate(() => {
    window.__reviewEvents = [];
    document.addEventListener('ojai:worksheet-review-completed', (event) => window.__reviewEvents.push(event.detail));
    const fetch = window.fetch;
    window.__rejectReview = true;
    window.fetch = async (url, options) => {
      if (String(url).endsWith('/commands/review') && window.__rejectReview) {
        return { ok: false, status: 409, json: async () => ({ message: 'Review conflict', data: { status: 409 } }) };
      }
      const response = await fetch(url, options);
      const payload = structuredClone(await response.json());
      // Model a list snapshot that is older than the detail response.
      if (String(url).includes('/worksheet-evaluations?')) payload.data.items[0].state = 'queued';
      return { ...response, json: async () => payload };
    };
  });
  await review.getByRole('button', { name: '검토 완료', exact: true }).click();
  await review.getByText('Review conflict', { exact: true }).waitFor();
  assert.deepEqual(await review.evaluate(() => window.__reviewEvents), []);
  await review.evaluate(() => { window.__rejectReview = false; });
  await review.getByRole('button', { name: '검토 완료', exact: true }).click();
  await review.waitForFunction(() => window.__reviewEvents.length === 1);
  assert.deepEqual(await review.evaluate(() => window.__reviewEvents[0]), {
    worksheet_id: worksheetId, revision_id: revisionId,
  });
  await review.waitForFunction(() => document.querySelector('[data-action="select-evaluation"][aria-current="true"]')?.textContent.includes('검토 완료'));
  await review.close();

  const editor = await browser.newPage();
  editor.on('pageerror', (error) => errors.push(error.message));
  await editor.goto(fixture('worksheet-workbench-visual.html'));
  await editor.waitForFunction(() => !document.querySelector('[data-action="save"]')?.disabled);
  await editor.evaluate(() => {
    const fetch = window.fetch;
    window.__reviewValidationCalls = 0;
    window.__denyReviewValidation = false;
    window.__holdReviewValidation = false;
    window.fetch = async (url, options) => {
      if (String(url).endsWith('/commands/validate')) {
        window.__reviewValidationCalls += 1;
        if (window.__holdReviewValidation) {
          window.__holdReviewValidation = false;
          await new Promise((resolve) => { window.__finishReviewValidation = resolve; });
        }
        if (window.__denyReviewValidation) return {
          ok: false, status: 503, json: async () => ({ message: 'Validation unavailable' }),
        };
      }
      return fetch(url, options);
    };
  });
  const completed = async (detail) => editor.evaluate((value) => {
    document.dispatchEvent(new CustomEvent('ojai:worksheet-review-completed', { detail: value }));
  }, detail);
  await completed({ worksheet_id: 'other-worksheet', revision_id: revisionId });
  await completed({ worksheet_id: worksheetId, revision_id: 'old-revision' });
  assert.equal(await editor.evaluate(() => window.__reviewValidationCalls), 0);
  await completed({ worksheet_id: worksheetId, revision_id: revisionId });
  await editor.waitForFunction(() => !document.querySelector('[data-action="approve"]')?.disabled);
  assert.equal(await editor.evaluate(() => window.__reviewValidationCalls), 1);
  await editor.evaluate(() => { window.__holdReviewValidation = true; });
  await completed({ worksheet_id: worksheetId, revision_id: revisionId });
  await editor.waitForFunction(() => window.__reviewValidationCalls === 2);
  await completed({ worksheet_id: worksheetId, revision_id: revisionId });
  assert.equal(await editor.evaluate(() => window.__reviewValidationCalls), 2);
  await editor.evaluate(() => window.__finishReviewValidation());
  await editor.waitForFunction(() => window.__reviewValidationCalls === 3
    && !document.querySelector('[data-action="approve"]')?.disabled);
  await editor.evaluate(() => { window.__denyReviewValidation = true; });
  await completed({ worksheet_id: worksheetId, revision_id: revisionId });
  await editor.getByText('Validation unavailable', { exact: true }).waitFor();
  assert.equal(await editor.locator('[data-action="approve"]').isDisabled(), true);
  await editor.locator('#ws-template-title').fill('Unsaved teacher changes');
  await completed({ worksheet_id: worksheetId, revision_id: revisionId });
  assert.equal(await editor.evaluate(() => window.__reviewValidationCalls), 4);
  assert.equal(await editor.locator('#ws-template-title').inputValue(), 'Unsaved teacher changes');
  assert.equal(await editor.locator('[data-action="approve"]').isDisabled(), true);
  await editor.close();
  assert.deepEqual(errors, []);
  console.log('Review readiness sync passed: stale list, completion event, conflict, identity, server revalidation, in-flight refresh, failure, unsaved changes.');
} finally {
  await browser.close();
}
