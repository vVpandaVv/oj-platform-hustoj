import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests/fixtures/submission-evaluation-review-workbench-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];

await mkdir(artifactDir, { recursive: true });

function intersects(a, b) {
  return a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
}

async function prepareAdjustedReview(page) {
  await page.waitForFunction(() => document.querySelectorAll('[data-review-field="official-points"]').length === 3);
  const criteria = page.locator('[data-binding-key]');
  await criteria.nth(0).locator('[data-review-field="official-points"]').fill('3');
  await criteria.nth(0).locator('[data-review-field="reason-code"]').selectOption('evidence_checked');
  await criteria.nth(2).locator('[data-review-field="official-points"]').fill('1');
  await criteria.nth(2).locator('[data-review-field="reason-code"]').selectOption('manual_assessment');
  await criteria.nth(2).locator('[data-review-field="reason-note"]').fill('학생 답변과 현장 안전 수칙을 확인했습니다.');
}

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') pageErrors.push(message.text());
  });
  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await page.waitForFunction(() => document.querySelector('[data-review-role="detail"] h2')?.textContent === '우리 동네 생태 관찰');
  await page.waitForFunction(() => document.querySelectorAll('[data-binding-key]').length === 3);

  const initialList = await page.evaluate(() => ({
    stateFilter: document.querySelector('[data-review-field="state"]')?.value,
    itemCount: document.querySelectorAll('[data-review-action="select"]').length,
  }));
  if (initialList.stateFilter !== '' || initialList.itemCount !== 3) {
    errors.push(`${label}: default student evaluation list must show all states`);
  }

  const layout = await page.evaluate(() => {
    const rect = (selector) => {
      const element = document.querySelector(selector);
      if (!element) return null;
      const value = element.getBoundingClientRect();
      return { left: value.left, right: value.right, top: value.top, bottom: value.bottom, width: value.width, height: value.height };
    };
    const rootElement = document.querySelector('#oj-ai-submission-review-app');
    const list = document.querySelector('.oj-ai-submission-review__inbox');
    const detail = document.querySelector('.oj-ai-submission-review__detail');
    const criteria = [...document.querySelectorAll('[data-binding-key]')];
    return {
      root: rect('#oj-ai-submission-review-app'),
      header: rect('.oj-ai-submission-review__header'),
      headerTitle: rect('.oj-ai-submission-review__header > div:first-child'),
      headerAction: rect('.oj-ai-submission-review__header > button'),
      filters: rect('.oj-ai-submission-review__filters'),
      list: rect('.oj-ai-submission-review__inbox'),
      detail: rect('.oj-ai-submission-review__detail'),
      reviewActions: rect('.oj-ai-submission-review__review-actions'),
      criterionCount: criteria.length,
      responseCount: document.querySelectorAll('.oj-ai-submission-review__response').length,
      scoreInputCount: document.querySelectorAll('[data-review-field="official-points"]').length,
      rootTextLength: rootElement?.textContent.trim().length || 0,
      acceptDisabled: document.querySelector('[data-review-action="accept"]')?.disabled === true,
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      rootOverflow: rootElement ? rootElement.scrollWidth > rootElement.clientWidth + 1 : true,
      listOverflow: list ? list.scrollWidth > list.clientWidth + 1 : true,
      detailOverflow: detail ? detail.scrollWidth > detail.clientWidth + 1 : true,
      criterionOverflow: criteria.some((item) => item.scrollWidth > item.clientWidth + 1),
    };
  });

  if (!layout.root || layout.root.width < 250 || layout.root.height < 700 || layout.rootTextLength < 300) errors.push(`${label}: student review root is blank`);
  if (!layout.list || !layout.detail || !layout.filters || !layout.reviewActions) errors.push(`${label}: main review layout is incomplete`);
  if (layout.criterionCount !== 3 || layout.responseCount !== 4 || layout.scoreInputCount !== 3) errors.push(`${label}: responses, evidence, or score controls are incomplete`);
  if (!layout.acceptDisabled) errors.push(`${label}: unsafe AI candidate must not be directly accepted`);
  if (layout.horizontalOverflow || layout.rootOverflow || layout.listOverflow || layout.detailOverflow || layout.criterionOverflow) errors.push(`${label}: horizontal overflow detected`);
  if (label === 'desktop' && layout.headerTitle && layout.headerAction && intersects(layout.headerTitle, layout.headerAction)) errors.push(`${label}: header title overlaps refresh action`);
  if (label === 'desktop' && layout.list && layout.detail && intersects(layout.list, layout.detail)) errors.push(`${label}: inbox overlaps detail`);
  if (label === 'mobile' && layout.list && layout.detail && layout.detail.top < layout.list.bottom - 1) errors.push(`${label}: mobile detail does not stack below the inbox`);

  if (label === 'desktop') {
    await page.getByRole('tab', { name: '활동지 자체 점검', exact: true }).click();
    if (!(await page.locator('#oj-ai-assessment-panel-submission').isHidden())) errors.push(`${label}: submission panel did not hide after tab switch`);
    await page.getByRole('tab', { name: '학생 제출', exact: true }).click();

    await page.locator('[data-item-type="candidate"]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-role="detail"] h2')?.textContent === '저작권 보호하기');
    const candidateState = await page.evaluate(() => ({
      responses: document.querySelectorAll('.oj-ai-submission-review__response').length,
      answers: document.querySelector('[data-review-role="detail"]')?.textContent || '',
      action: Boolean(document.querySelector('[data-review-action="evaluate-submission"]')),
    }));
    if (candidateState.responses !== 2 || !candidateState.answers.includes('출처표시-예시.png') || !candidateState.action) {
      errors.push(label + ': unevaluated submission detail is incomplete');
    }
    await page.getByRole('button', { name: 'AI 평가 시작', exact: true }).click();
    await page.waitForFunction(() => window.__evaluationRequests.length === 1
      && document.querySelector('[data-review-role="detail"] h2')?.textContent === '저작권 보호하기'
      && document.querySelector('[data-review-role="detail"]')?.textContent.includes('평가 작업이 서버 대기열에 등록되어 있습니다.'));
    const evaluationRequest = await page.evaluate(() => window.__evaluationRequests[0]);
    try {
      assert.equal(evaluationRequest.body.expected.expected_official_revision_id, '01K5A00000000000000000000K');
      assert.equal(evaluationRequest.body.expected.expected_official_revision_hash, '7'.repeat(64));
      assert.equal(evaluationRequest.headers['X-WP-Nonce'], 'submission-review-visual-nonce');
      assert.ok(evaluationRequest.headers['Idempotency-Key']);
    } catch (error) {
      errors.push(label + ': unevaluated submission enqueue request drifted: ' + error.message);
    }
    await page.locator('[data-evaluation-id="01K5A000000000000000000001"]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-review-field="official-points"]').length === 3);

    await prepareAdjustedReview(page);
    const total = await page.locator('[data-review-role="official-total"]').textContent();
    if (total?.trim() !== '6.5점') errors.push(`${label}: adjusted total did not update`);
    await page.getByRole('button', { name: '선택 점수 확정', exact: true }).click();
    await page.waitForFunction(() => window.__reviewRequests.length === 1);
    const request = await page.evaluate(() => window.__reviewRequests[0]);
    try {
      assert.equal(request.body.payload.decision, 'adjusted');
      assert.equal(request.body.payload.item_decisions.length, 3);
      assert.equal(request.body.payload.item_decisions.filter((item) => item.source === 'teacher_assigned').length, 2);
      assert.equal(request.body.expected.expected_review_no, 0);
      assert.equal(request.headers['X-WP-Nonce'], 'submission-review-visual-nonce');
      assert.ok(request.headers['Idempotency-Key']);
    } catch (error) {
      errors.push(`${label}: C34 request drifted: ${error.message}`);
    }

    await page.waitForFunction(() => document.querySelector('[data-review-action="adjust"]')?.disabled === false);
    const readsBeforeConflict = await page.evaluate(() => window.__reviewDetailReads);
    await page.evaluate(() => { window.__reviewConflictOnce = true; });
    await prepareAdjustedReview(page);
    await page.getByRole('button', { name: '선택 점수 확정', exact: true }).click();
    await page.waitForFunction(
      (before) => window.__reviewDetailReads > before
        && document.querySelector('[data-review-role="notice"]')?.textContent.includes('최신 내용을 다시 불러왔습니다.'),
      readsBeforeConflict
    );
    const conflictState = await page.evaluate(() => ({
      requests: window.__reviewRequests.length,
      reads: window.__reviewDetailReads,
      notice: document.querySelector('[data-review-role="notice"]')?.textContent || '',
      actionVisible: Boolean(document.querySelector('[data-review-action="adjust"]')),
    }));
    if (conflictState.requests !== 2 || conflictState.reads <= readsBeforeConflict) errors.push(`${label}: 409 did not refetch the current review source`);
    if (!conflictState.notice.includes('최신 내용을 다시 불러왔습니다.') || !conflictState.actionVisible) errors.push(`${label}: 409 recovery state drifted`);

    await page.getByRole('button', { name: '반려', exact: true }).first().click();
    await page.waitForSelector('[data-review-role="reject-dialog"][open]');
    if (!(await page.locator('[data-review-field="reject-reason"]').isVisible())) errors.push(`${label}: reject reason control is missing`);
    await page.keyboard.press('Escape');

    await page.evaluate(() => { window.__reviewPermissionDenied = true; });
    await prepareAdjustedReview(page);
    await page.getByRole('button', { name: '선택 점수 확정', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-review-role="notice"]')?.textContent.includes('권한이 없습니다.'));
    const revokedState = await page.evaluate(() => ({
      requests: window.__reviewRequests.length,
      controls: document.querySelectorAll('[data-review-field="official-points"]').length,
      actions: document.querySelectorAll('[data-review-action="accept"], [data-review-action="adjust"], [data-review-action="reject"]').length,
      canReview: document.querySelector('#oj-ai-submission-review-app')?.dataset.canReview,
    }));
    if (revokedState.requests !== 3 || revokedState.controls !== 0 || revokedState.actions !== 0 || revokedState.canReview !== '0') {
      errors.push(`${label}: 403 permission revocation did not remove mutation controls`);
    }
  }

  await page.screenshot({
    path: path.join(artifactDir, `submission-evaluation-review-workbench-${label}.png`),
    fullPage: true,
  });
  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.close();
  console.log(`[OK] submission review ${label} ${JSON.stringify(layout)}`);
}

async function inspectEntryPoint(browser) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error') pageErrors.push(message.text()); });
  const requestedEvaluation = '01K5A000000000000000000002';
  const entryUrl = `${fixtureUrl}?type=submission&context_type=class&context_id=class-5-2&context_revision=membership-17&evaluation_id=${requestedEvaluation}`;
  await page.goto(entryUrl, { waitUntil: 'load' });
  await page.waitForFunction((id) => document.querySelector(`[data-evaluation-id="${id}"]`)?.getAttribute('aria-current') === 'true', requestedEvaluation);
  const entryState = await page.evaluate((id) => ({
    context: document.querySelector('[data-review-field="context"]')?.value,
    state: document.querySelector('[data-review-field="state"]')?.value,
    selected: document.querySelector(`[data-evaluation-id="${id}"]`)?.getAttribute('aria-current'),
    learner: document.querySelector('.oj-ai-submission-review__detail-header p')?.textContent || '',
  }), requestedEvaluation);
  if (entryState.context !== '0' || entryState.state !== '' || entryState.selected !== 'true' || !entryState.learner.includes('이도윤')) {
    errors.push(`entry-point: context or evaluation deep link drifted ${JSON.stringify(entryState)}`);
  }
  pageErrors.forEach((message) => errors.push(`entry-point: browser error: ${message}`));
  await page.screenshot({ path: path.join(artifactDir, 'submission-evaluation-review-entry-point.png'), fullPage: true });
  await page.close();
}

async function inspectApprovalLifecycle(browser, viewport, label) {
  const page = await browser.newPage({ viewport });
  page.on('pageerror', error => errors.push(`${label} approval: ${error.message}`));
  try {
    await page.goto(fixtureUrl, { waitUntil: 'load' });
    await page.waitForFunction(() => window.__reviewFixture && document.querySelector('[data-review-action="adjust"]'));
    await page.evaluate(() => { window.__reviewPersistTransitions = true; });
    await prepareAdjustedReview(page);
    await page.getByRole('button', { name: '선택 점수 확정', exact: true }).click();
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).waitFor();
    assert.equal(await page.locator('[data-review-action="accept"], [data-review-action="adjust"], [data-review-action="reject"], [data-review-field="official-points"]').count(), 0);
    assert.ok((await page.locator('[data-review-role="detail"]').innerText()).includes('조정 점수 확정 완료'));
    await page.getByRole('button', { name: '평가 새로고침', exact: true }).click();
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).waitFor();
    assert.equal(await page.locator('[data-review-field="official-points"]').count(), 0, 'Refresh must preserve approved read-only state');
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).click();
    await page.keyboard.press('Escape');
    assert.equal(await page.evaluate(() => window.__reviewRequests.length), 1, 'Cancel must not send withdrawal');
    await page.screenshot({ path: path.join(artifactDir, `submission-score-approved-${label}.png`), fullPage: true });
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).click();
    await page.getByRole('button', { name: '승인 회수', exact: true }).click();
    assert.ok((await page.locator('[data-review-role="reject-status"]').innerText()).includes('회수 사유'));
    await page.locator('[data-review-field="reject-reason"]').fill('학생 답변을 다시 확인하여 점수 조정');
    await page.evaluate(() => { window.__reviewConflictOnce = true; });
    await page.getByRole('button', { name: '승인 회수', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-review-role="notice"]')?.textContent.includes('최신 내용을 다시 불러왔습니다.'));
    assert.equal(await page.locator('[data-review-field="official-points"]').count(), 0, 'Failed withdrawal must keep approved state');
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).click();
    await page.locator('[data-review-field="reject-reason"]').fill('학생 답변을 다시 확인하여 점수 조정');
    await page.getByRole('button', { name: '승인 회수', exact: true }).click();
    await page.locator('[data-review-field="official-points"]').first().waitFor();
    assert.equal(await page.locator('[data-review-action="withdraw-score"]').count(), 0);
    assert.equal(Number(await page.locator('[data-review-field="official-points"]').first().inputValue()), 3, 'Previous adjustment must be available for re-editing');
    const withdrawal = await page.evaluate(() => window.__reviewRequests.at(-1).body);
    assert.equal(withdrawal.payload.decision, 'withdrawn');
    assert.ok(withdrawal.expected.expected_official_review_id);
    assert.equal(withdrawal.expected.expected_review_no, 1);
    await page.locator('[data-review-field="official-points"]').first().fill('2');
    await page.getByRole('button', { name: '선택 점수 확정', exact: true }).click();
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).waitFor();
    assert.deepEqual(await page.evaluate(() => window.__reviewFixture.reviews.map(item => item.decision)), ['adjusted', 'withdrawn', 'adjusted']);
    assert.equal(await page.locator('[data-review-field="official-points"]').count(), 0);
    assert.ok((await page.locator('[data-review-role="detail"]').innerText()).includes('확정 점수 5.5 / 10'));
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);

    // A normal, complete AI result follows the same locked approval lifecycle.
    await page.evaluate(() => {
      const detail = window.__reviewFixture;
      detail.reviews = []; detail.evaluation.state = 'review_required';
      detail.evaluation.review = { official_scope: 'none', official_id: null };
      Object.assign(detail.review_precondition, { expected_review_no: 0, expected_official_review_id: null, expected_official_review_hash: null });
      detail.selected_result.safety_flagged = false;
      detail.selected_result.items.forEach(item => { item.flags = []; item.candidate_points ??= '1.0000'; });
    });
    await page.getByRole('button', { name: '평가 새로고침', exact: true }).click();
    await page.getByRole('button', { name: 'AI 점수 승인', exact: true }).click();
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).waitFor();
    assert.equal(await page.locator('[data-review-action="accept"]').count(), 0);
    assert.ok((await page.locator('[data-review-role="detail"]').innerText()).includes('AI 점수 승인 완료'));
    console.log(`[OK] ${label} approval, refresh, withdrawal, conflict, adjustment, reapproval`);
  } finally { await page.close(); }
}

async function inspectRubricScoring(browser, viewport, label, manual) {
  const page = await browser.newPage({ viewport });
  page.setDefaultTimeout(12000);
  page.on('pageerror', error => errors.push(`${label} rubric: ${error.message}`));
  try {
    await page.goto(fixtureUrl + (manual ? '?manual_only=1' : ''), { waitUntil: 'load' });
    await page.waitForFunction(() => window.__reviewFixture && document.querySelector('[data-review-action="adjust"]'));
    await page.evaluate(() => { window.__reviewPersistTransitions = true; });
    if (manual) {
      await page.locator('[data-item-type="candidate"]').click();
      assert.equal(await page.locator('[data-review-action="evaluate-submission"]').count(), 0, 'Reviewer-only users do not need AI permission');
      await page.getByRole('button', { name: '교사 직접 채점', exact: true }).click();
      await page.getByRole('button', { name: '채점 점수 확정', exact: true }).waitFor();
      await page.waitForFunction(() => document.querySelector('[data-review-field="rubric-level"]')?.disabled === false);
      assert.equal(await page.locator('[data-review-field="rubric-level"]:checked').count(), 0, 'Manual score starts blank, not zero');
      assert.equal(await page.locator('[data-review-action="accept"], [data-review-action="retry-score"]').count(), 0);
      await page.getByRole('button', { name: '채점 점수 확정', exact: true }).click();
      assert.equal(await page.evaluate(() => window.__reviewRequests.length), 0, 'Incomplete manual scores must not be saved');
      const request = await page.evaluate(() => window.__manualRequests[0]);
      assert.equal(request.body.expected.expected_official_revision_id, '01K5A00000000000000000000K');
      assert.equal(request.headers['X-WP-Nonce'], 'submission-review-visual-nonce');
    } else {
      await page.evaluate(() => {
        const result = window.__reviewFixture.selected_result;
        result.safety_flagged = false; result.abstained = false;
        result.items.forEach(item => {
          item.candidate_points ??= '1.0000'; item.flags = []; item.decision = 'score';
          item.levels = [
            { label: '관찰한 사실과 환경의 관계를 구체적인 근거를 들어 설명하고 실천 방안을 논리적으로 제시함.', points: item.candidate_points },
            { label: '평가 기준에 해당하는 응답이 없음.', points: '0.0000' },
          ];
        });
      });
      await page.getByRole('button', { name: '평가 새로고침', exact: true }).click();
      await page.waitForFunction(() => document.querySelectorAll('[data-review-field="rubric-level"]:checked').length === 3);
      assert.equal(await page.getByText('AI 선택', { exact: true }).count(), 3);
    }
    const criteria = page.locator('[data-binding-key]');
    await criteria.nth(0).locator('[data-review-field="rubric-level"][value="0"]').check();
    if (!manual) {
      assert.equal(await page.getByRole('button', { name: 'AI 점수 승인', exact: true }).isDisabled(), true, 'Adjusted radio must not silently accept the AI score');
      await criteria.nth(0).locator('[data-review-field="reason-code"]').selectOption('evidence_checked');
    } else {
      await criteria.nth(1).locator('[data-review-field="rubric-level"][value="3"]').check();
      await criteria.nth(2).locator('[data-review-field="rubric-level"][value="1"]').check();
    }
    assert.equal(Number(await criteria.nth(0).locator('[data-review-field="official-points"]').inputValue()), 0, 'Zero is an intentional rubric selection');
    assert.equal(await page.locator('[data-review-role="official-total"]').innerText(), manual ? '4점' : '3.5점');
    await criteria.first().scrollIntoViewIfNeeded();
    assert.equal(await page.evaluate(() => [...document.querySelectorAll('.oj-ai-submission-review__rubric, .oj-ai-submission-review__rubric-level')].some(el => el.scrollWidth > el.clientWidth + 1)), false, 'Full rubric text must fit');
    await page.screenshot({ path: path.join(artifactDir, `rubric-${manual ? 'manual' : 'ai'}-${label}.png`), fullPage: true });
    await page.getByRole('button', { name: manual ? '채점 점수 확정' : '선택 점수 확정', exact: true }).click();
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).waitFor();
    const review = await page.evaluate(() => window.__reviewRequests.at(-1).body.payload);
    assert.equal(review.decision, 'adjusted');
    assert.equal(Number(review.item_decisions[0].official_points), 0);
    assert.equal(review.item_decisions[0].source, 'teacher_assigned');
    if (manual) {
      assert.ok(review.item_decisions.every(item => item.source === 'teacher_assigned' && item.reason_code === 'manual_assessment'));
      assert.equal(await page.evaluate(() => window.__evaluationRequests.length), 0, 'Manual grading must not enqueue AI');
      assert.ok((await page.locator('[data-review-role="detail"]').innerText()).includes('교사 채점 확정 완료'));
    }
    assert.equal(await page.locator('[data-review-field="rubric-level"]:enabled').count(), 0, 'Approved rubric is locked');
    await page.getByRole('button', { name: '평가 새로고침', exact: true }).click();
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).waitFor();
    assert.equal(await page.locator('[data-review-field="rubric-level"]:checked').count(), 3, 'Refresh retains confirmed rubric selections');
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).click();
    await page.locator('[data-review-field="reject-reason"]').fill('학생 제출 내용을 다시 확인함');
    await page.getByRole('button', { name: '승인 회수', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('[data-review-field="rubric-level"]:enabled').length > 0);
    assert.equal(await page.locator('[data-review-field="rubric-level"]:checked').count(), 3);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    console.log(`[OK] ${label} ${manual ? 'offline teacher-first' : 'AI recommended'} rubric selection, zero score, confirmation, refresh and withdrawal`);
  } finally { await page.close(); }
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1440, height: 1000 }, 'desktop');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await inspectEntryPoint(browser);
  await inspectApprovalLifecycle(browser, { width: 1440, height: 1000 }, 'desktop');
  await inspectApprovalLifecycle(browser, { width: 390, height: 844 }, 'mobile');
  for (const manual of [false, true]) {
    await inspectRubricScoring(browser, { width: 1440, height: 1000 }, 'desktop', manual);
    await inspectRubricScoring(browser, { width: 390, height: 844 }, 'mobile', manual);
  }
  await browser.close();
} catch (error) {
  errors.push(`Playwright submission review check failed: ${error.message}`);
}

if (errors.length > 0) {
  console.error('Student submission review visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log('Student submission review desktop/mobile visual checks passed.');
