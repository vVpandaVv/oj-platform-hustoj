import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests', 'fixtures', 'submission-feedback-workbench-visual.html')).href;
const artifactDir = path.join(root, 'tests', 'artifacts');
const errors = [];

await mkdir(artifactDir, { recursive: true });

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') pageErrors.push(message.text());
  });
  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await page.waitForFunction(() => document.querySelector('[data-review-role="detail"] h2')?.textContent === '우리 동네 생태 관찰');
  await page.waitForSelector('[data-feedback-field="overall"]');

  const layout = await page.evaluate(() => {
    const rect = (element) => {
      if (!element) return null;
      const value = element.getBoundingClientRect();
      return { left: value.left, right: value.right, top: value.top, bottom: value.bottom, width: value.width, height: value.height };
    };
    const rootElement = document.querySelector('#oj-ai-submission-review-app');
    const detail = document.querySelector('.oj-ai-submission-review__detail');
    const editor = document.querySelector('.oj-ai-submission-review__feedback-editor');
    const criterion = [...document.querySelectorAll('[data-feedback-binding-key]')];
    const firstLabels = criterion[0] ? [...criterion[0].querySelectorAll('label')].map(rect) : [];
    return {
      root: rect(rootElement),
      detail: rect(detail),
      editor: rect(editor),
      criterionCount: criterion.length,
      feedbackFieldCount: document.querySelectorAll('[data-feedback-field]').length,
      feedbackActionCount: detail?.querySelectorAll('[data-feedback-action]').length || 0,
      rootTextLength: rootElement?.textContent.trim().length || 0,
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      rootOverflow: rootElement ? rootElement.scrollWidth > rootElement.clientWidth + 1 : true,
      detailOverflow: detail ? detail.scrollWidth > detail.clientWidth + 1 : true,
      criterionOverflow: criterion.some((item) => item.scrollWidth > item.clientWidth + 1),
      firstLabels,
    };
  });

  if (!layout.root || layout.root.width < 250 || layout.root.height < 900 || layout.rootTextLength < 350) errors.push(`${label}: feedback workbench is blank`);
  if (!layout.detail || !layout.editor || layout.criterionCount !== 2 || layout.feedbackFieldCount !== 7 || layout.feedbackActionCount !== 2) {
    errors.push(`${label}: initial feedback editor is incomplete`);
  }
  if (layout.horizontalOverflow || layout.rootOverflow || layout.detailOverflow || layout.criterionOverflow) {
    errors.push(`${label}: horizontal overflow detected`);
  }
  if (label === 'desktop' && layout.firstLabels.length === 2 && Math.abs(layout.firstLabels[0].top - layout.firstLabels[1].top) > 2) {
    errors.push(`${label}: criterion feedback fields should align in two columns`);
  }
  if (label === 'mobile' && layout.firstLabels.length === 2 && layout.firstLabels[1].top < layout.firstLabels[0].bottom - 1) {
    errors.push(`${label}: criterion feedback fields do not stack`);
  }

  if (label === 'desktop') {
    await page.locator('[data-feedback-action="generate"]').click();
    await page.waitForFunction(() => window.__feedbackRequests.length === 1
      && document.querySelector('.oj-ai-submission-review__feedback-header')?.textContent.includes('AI 초안'));
    const generateRequest = await page.evaluate(() => window.__feedbackRequests[0]);
    try {
      assert.equal(generateRequest.kind, 'generate');
      assert.deepEqual(Object.keys(generateRequest.body.expected).sort(), ['expected_official_review_hash', 'expected_official_review_id']);
      assert.deepEqual(generateRequest.body.payload, {});
      assert.equal(generateRequest.body.expected.expected_official_review_hash, '8'.repeat(64));
      assert.equal(generateRequest.headers['X-WP-Nonce'], 'submission-feedback-s04-nonce');
      assert.ok(generateRequest.headers['Idempotency-Key']);
    } catch (error) {
      errors.push(`${label}: C35 request drifted: ${error.message}`);
    }

    const rejectTrigger = page.locator('[data-feedback-action="reject"]');
    await rejectTrigger.click();
    await page.waitForSelector('[data-feedback-role="reject-dialog"][open]');
    if (!(await page.locator('[data-feedback-field="reject-reason"]').isVisible())) errors.push(`${label}: feedback rejection reason is missing`);
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('[data-feedback-role="reject-dialog"]')?.hasAttribute('open'));
    const rejectFocusReturned = await page.evaluate(() => (
      document.activeElement?.matches('[data-feedback-action="reject"]') === true
    ));
    if (!rejectFocusReturned) {
      errors.push(`${label}: feedback rejection dialog did not return focus to the trigger`);
    }

    await page.locator('[data-feedback-field="overall"]').fill('관찰 근거와 설명이 잘 연결되었습니다. 비교 관찰로 생각을 더 넓혀 보세요.');
    await page.locator('[data-feedback-binding-key]').first().locator('[data-feedback-field="strength"]').fill('관찰 장소와 대상을 구체적으로 적었습니다.');
    await page.locator('[data-feedback-binding-key]').first().locator('[data-feedback-field="criterion-next-step"]').fill('관찰한 시간도 함께 적어 보세요.');
    await page.locator('[data-feedback-field="next-steps"]').fill('관찰 시간 기록하기\n다른 장소와 비교하기');
    page.once('dialog',dialog=>dialog.dismiss());
    assert.equal(await page.evaluate(()=>document.dispatchEvent(new CustomEvent('ojai:before-navigation',{cancelable:true}))),false,'Unsaved report must block a cancelled navigation');
    assert.equal(await page.locator('[data-feedback-field="next-steps"]').inputValue(),'관찰 시간 기록하기\n다른 장소와 비교하기');
    await page.locator('[data-feedback-action="save"]').click();
    await page.waitForFunction(() => window.__feedbackRequests.length === 2
      && document.querySelector('.oj-ai-submission-review__feedback-header')?.textContent.includes('검토 중'));
    const reviewRequest = await page.evaluate(() => window.__feedbackRequests[1]);
    try {
      assert.equal(reviewRequest.kind, 'review');
      assert.equal(reviewRequest.body.payload.decision, 'teacher_draft');
      assert.equal(reviewRequest.body.payload.source_mode, 'edit');
      assert.equal(reviewRequest.body.payload.reason, null);
      assert.equal(reviewRequest.body.payload.feedback.criterion_feedback.length, 2);
      assert.equal(reviewRequest.body.payload.feedback.next_steps.length, 2);
      assert.equal(Object.hasOwn(reviewRequest.body.payload.feedback, 'safety_flags'), false);
      assert.equal(reviewRequest.body.expected.expected_feedback_revision_no, 1);
      assert.equal(reviewRequest.body.expected.expected_feedback_revision_hash, 'a'.repeat(64));
    } catch (error) {
      errors.push(`${label}: C36 request drifted: ${error.message}`);
    }

    const readsBeforeConflict = await page.evaluate(() => window.__feedbackDetailReads);
    await page.evaluate(() => { window.__feedbackConflictOnce = true; });
    await page.locator('[data-feedback-action="approve"]').click();
    await page.waitForFunction(
      (before) => window.__feedbackDetailReads > before
        && document.querySelector('[data-review-role="notice"]')?.textContent.includes('피드백이 변경되었습니다. 다시 확인해 주세요.'),
      readsBeforeConflict
    );
    const conflictState = await page.evaluate(() => ({
      requests: window.__feedbackRequests.length,
      history: document.querySelectorAll('.oj-ai-submission-review__feedback-history li').length,
      canApprove: Boolean(document.querySelector('[data-feedback-action="approve"]')),
    }));
    if (conflictState.requests !== 3 || conflictState.history !== 2 || !conflictState.canApprove) {
      errors.push(`${label}: 409 feedback reload or immutable history drifted`);
    }

    await page.locator('[data-feedback-action="approve"]').click();
    await page.waitForFunction(() => window.__feedbackRequests.length === 4
      && document.querySelector('.oj-ai-submission-review__feedback-approved')?.textContent.includes('공개 상태: 미공개'));
    const approval = await page.evaluate(() => ({
      request: window.__feedbackRequests[3],
      history: document.querySelectorAll('.oj-ai-submission-review__feedback-history li').length,
      controls: document.querySelectorAll('[data-review-role="detail"] [data-feedback-action]').length,
      actions: [...document.querySelectorAll('[data-review-role="detail"] [data-feedback-action]')].map(element => element.dataset.feedbackAction),
      editors: document.querySelectorAll('[data-review-role="detail"] [data-feedback-field]').length,
      approvedText: document.querySelector('.oj-ai-submission-review__feedback-approved')?.textContent || '',
    }));
    try {
      assert.equal(approval.request.kind, 'approve');
      assert.deepEqual(approval.request.body.payload, {});
      assert.equal(approval.request.body.expected.expected_feedback_revision_no, 2);
      assert.equal(approval.request.body.expected.expected_feedback_revision_hash, 'b'.repeat(64));
      assert.equal(approval.history, 3);
      assert.equal(approval.controls, 1);
      assert.deepEqual(approval.actions, ['withdraw-approval']);
      assert.equal(approval.editors, 0);
      assert.ok(approval.approvedText.includes('공개 상태: 미공개'));
    } catch (error) {
      errors.push(`${label}: C37 approval or approved-state UI drifted: ${error.message}`);
    }
  }

  await page.screenshot({
    path: path.join(artifactDir, `submission-feedback-workbench-${label}.png`),
    fullPage: true,
  });
  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.close();
  console.log(`[OK] submission feedback ${label} ${JSON.stringify(layout)}`);
}

async function inspectPermissionRevocation(browser) {
  const page = await browser.newPage({ viewport: { width: 1200, height: 900 } });
  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await page.waitForSelector('[data-feedback-action="generate"]');
  await page.evaluate(() => { window.__feedbackPermissionDenied = true; });
  await page.locator('[data-feedback-action="generate"]').click();
  await page.waitForFunction(() => document.querySelector('#oj-ai-submission-review-app')?.dataset.canReview === '0'
    && document.querySelector('[data-review-role="notice"]')?.textContent.includes('권한이 없습니다.'));
  const revoked = await page.evaluate(() => ({
    requests: window.__feedbackRequests.length,
    feedbackControls: document.querySelectorAll('[data-review-role="detail"] [data-feedback-action]').length,
    scoreControls: document.querySelectorAll('[data-review-field="official-points"]').length,
    dialogOpen: document.querySelector('[data-feedback-role="reject-dialog"]')?.hasAttribute('open') || false,
  }));
  if (revoked.requests !== 1 || revoked.feedbackControls !== 0 || revoked.scoreControls !== 0 || revoked.dialogOpen) {
    errors.push('permission revocation: 403 did not remove all teacher mutation controls');
  }
  await page.close();
}

let browser;
try {
  const playwright = await loadPlaywright();
  browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1440, height: 1000 }, 'desktop');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await inspectPermissionRevocation(browser);
} catch (error) {
  errors.push(`Playwright submission feedback check failed: ${error.stack || error.message}`);
} finally {
  await browser?.close();
}

if (errors.length > 0) {
  console.error('Student submission feedback visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log('Student submission feedback desktop/mobile, C35-C37, 409 reload and 403 revocation checks passed.');
