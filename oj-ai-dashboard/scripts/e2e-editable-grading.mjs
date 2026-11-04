import assert from 'node:assert/strict';
import path from 'node:path';
import { mkdir } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const fixture = pathToFileURL(path.resolve('tests/fixtures/submission-evaluation-review-workbench-visual.html')).href;
const artifacts = path.resolve('tests/artifacts');
await mkdir(artifacts, { recursive: true });
const playwright = await loadPlaywright();
const browser = await playwright.chromium.launch(browserLaunchOptions());
const failures = [];

async function finishAi(page) {
  await page.evaluate(() => {
    const target = window.__aiFixture;
    const manual = window.__manualFixture;
    target.source = structuredClone(manual.source);
    target.selected_result = structuredClone(manual.selected_result);
    Object.assign(target.selected_result, { provider_status: 'succeeded', candidate_score: '10.0000', abstained: false, safety_flagged: false });
    target.selected_result.items.forEach(item => Object.assign(item, { candidate_points: item.maximum_points, decision: 'score', abstain_reason: null }));
    target.review_precondition = { ...manual.review_precondition, expected_review_no: 0 };
    target.evaluation.candidate = { ...manual.evaluation.candidate, id: target.selected_result.id, score: '10.0000' };
    target.evaluation.state = 'review_required';
    Object.assign(target.evaluation.latest_run, { state: 'succeeded', operation: 'score' });
    target.runs = [{ ...target.evaluation.latest_run, result_id: target.selected_result.id }];
  });
}

async function inspectFilterPreservation(page, label) {
  await page.goto(fixture);
  await page.waitForFunction(() => document.querySelectorAll('[data-review-field="official-points"]').length === 3);
  await page.evaluate(() => { window.__reviewPersistTransitions = true; });
  const filter = page.locator('[data-review-field="state"]');
  const criteria = page.locator('[data-binding-key]');
  await criteria.first().locator('[data-review-field="official-points"]').fill('3');
  await criteria.first().locator('[data-review-field="reason-code"]').selectOption('evidence_checked');
  await criteria.nth(2).locator('[data-review-field="official-points"]').fill('1');
  await criteria.nth(2).locator('[data-review-field="reason-code"]').selectOption('manual_assessment');
  await criteria.nth(2).locator('[data-review-field="reason-note"]').fill('학생 답변과 평가 기준을 확인함');
  await page.getByRole('button', { name: '채점 저장', exact: true }).click();
  await page.waitForFunction(() => window.__originalFixture.reviews.length === 1 && !document.querySelector('[data-review-action="save-score"]')?.disabled);
  assert.equal(await filter.inputValue(), '', 'Draft saving preserves the All filter');
  await page.getByRole('button', { name: '선택 점수 확정', exact: true }).click();
  await page.getByRole('button', { name: '점수 승인 회수', exact: true }).waitFor();
  assert.equal(await filter.inputValue(), '', 'Confirmation must not switch All to Reviewed');

  const withdraw = async () => {
    await page.getByRole('button', { name: '점수 승인 회수', exact: true }).click();
    await page.locator('[data-review-field="reject-reason"]').fill('평가 기준을 다시 확인함');
    await page.getByRole('button', { name: '승인 회수', exact: true }).click();
  };
  await withdraw();
  await page.locator('[data-review-field="official-points"]').first().waitFor();
  assert.equal(await filter.inputValue(), '', 'Withdrawal preserves All');
  await filter.selectOption('review_required');
  await page.getByRole('button', { name: '선택 점수 확정', exact: true }).waitFor();
  await page.getByRole('button', { name: '선택 점수 확정', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-evaluation-id="01K5A000000000000000000002"]')?.getAttribute('aria-current') === 'true');
  assert.equal(await filter.inputValue(), 'review_required', 'Confirmation preserves the pending-only filter');
  assert.equal(await page.locator('[data-evaluation-id="01K5A000000000000000000001"]').count(), 0, 'Confirmed student leaves the pending-only list');

  await filter.selectOption('reviewed');
  await page.getByRole('button', { name: '점수 승인 회수', exact: true }).waitFor();
  await withdraw();
  await page.waitForFunction(() => document.querySelector('[data-review-role="detail"]').textContent.includes('조건에 맞는 학생이 없습니다.'));
  assert.equal(await filter.inputValue(), 'reviewed', 'Empty results must not reset the filter');
  await filter.selectOption('review_required');
  await page.locator('[data-review-action="retry-evaluation"]').waitFor();
  await page.locator('[data-review-action="retry-evaluation"]').click();
  await page.waitForFunction(() => window.__retryRequests?.length === 1 && document.querySelector('[data-evaluation-id="01K5A000000000000000000002"]')?.getAttribute('aria-current') === 'true');
  assert.equal(await filter.inputValue(), 'review_required', 'AI regrading preserves the active filter');
  console.log(`[OK] ${label}: filter preservation after draft, confirmation, withdrawal, AI regrade and empty results`);
}

try {
  for (const [label, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    const page = await browser.newPage({ viewport });
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => failures.push(error.message));
    await page.goto(fixture);
    await page.locator('[data-item-type="candidate"]').click();
    await page.getByRole('button', { name: '채점 저장', exact: true }).waitFor();
    await page.evaluate(() => { window.__reviewPersistTransitions = true; });
    assert.equal(await page.evaluate(() => window.__writeRequests.length), 0, 'Selecting a student must be read-only');
    assert.match(await page.locator('[data-review-role="detail"]').innerText(), /평가 전/);
    assert.equal(await page.getByRole('button', { name: '채점 저장', exact: true }).count(), 1);
    await page.getByRole('button', { name: '제출 새로고침', exact: true }).click();
    await page.getByRole('button', { name: '채점 저장', exact: true }).waitFor();
    await page.locator('[data-evaluation-id="01K5A000000000000000000001"]').click();
    await page.getByRole('button', { name: '선택 점수 확정', exact: true }).waitFor();
    await page.locator('[data-item-type="candidate"]').click();
    await page.getByRole('button', { name: '채점 저장', exact: true }).waitFor();
    assert.equal(await page.evaluate(() => window.__writeRequests.length), 0, 'Refresh and repeated selection must not create grades or history');
    assert.equal(await page.evaluate(() => window.__evaluationRequests.length), 0, 'Opening a rubric must not call AI');
    assert.equal(await page.locator('[data-review-field="rubric-level"]:checked').count(), 0);
    const criteria = page.locator('[data-binding-key]');
    for (let i = 0; i < 3; i++) await criteria.nth(i).locator('[data-review-field="rubric-level"]').first().check();
    assert.equal(await page.evaluate(() => window.__manualRequests.length), 0, 'Rubric choices alone are not saved');
    await page.screenshot({ path: path.join(artifacts, `rubric-readonly-${label}.png`), fullPage: true });
    await page.evaluate(() => window.__manualFixture.selected_result.items.forEach(item => {
      item.maximum_points = String(Number(item.maximum_points));
      item.levels = item.levels.map(level => ({ points: String(Number(level.points)), label: level.label }));
    }));
    await page.evaluate(() => { window.__prepareUnavailable = true; });
    await page.getByRole('button', { name: '채점 저장', exact: true }).click();
    await page.waitForFunction(() => window.__manualRequests.length === 1 && !document.querySelector('[data-review-action="save-score"]')?.disabled);
    assert.equal(await page.locator('[data-review-field="rubric-level"]:checked').count(), 3, 'Preparation failure retains unsaved scores');
    assert.equal(await page.evaluate(() => window.__manualFixture.reviews.length), 0);
    await page.evaluate(() => { window.__prepareUnavailable = false; window.__reviewSaveUnavailable = true; });
    await page.getByRole('button', { name: '채점 저장', exact: true }).click();
    await page.waitForFunction(() => window.__reviewRequests.length === 1 && !document.querySelector('[data-review-action="save-score"]')?.disabled);
    assert.equal(await page.locator('[data-review-field="rubric-level"]:checked').count(), 3, 'First review failure retains unsaved scores');
    await page.evaluate(() => { window.__reviewSaveUnavailable = false; window.__reviewRequests = []; });
    await page.getByRole('button', { name: '채점 저장', exact: true }).click();
    await page.waitForFunction(() => window.__manualFixture.reviews.length === 1 && !document.querySelector('[data-review-action="save-score"]')?.disabled);
    assert.equal(await page.evaluate(() => window.__manualFixture.evaluation.state), 'review_required');
    await page.locator('[data-evaluation-id="01K5A00000000000000000000R"] [aria-label="저장 점수 · 미확정: 10 / 10"]').waitFor();
    assert.equal(await page.getByRole('button', { name: '점수 승인 회수', exact: true }).count(), 0);
    await page.getByRole('button', { name: '평가 새로고침', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('[data-review-field="rubric-level"]:checked').length === 3);
    assert.equal(await page.locator('[data-review-role="official-total"]').innerText(), '10점');
    await criteria.first().locator('[data-review-field="rubric-level"][value="0"]').check();

    await page.evaluate(() => { window.__reviewSaveUnavailable = true; });
    await page.getByRole('button', { name: 'AI 평가 시작', exact: true }).click();
    await page.waitForFunction(() => window.__reviewRequests.length === 2 && !document.querySelector('[data-review-action="save-score"]')?.disabled);
    assert.equal(await page.evaluate(() => window.__evaluationRequests.length), 0, 'Failed save must block AI enqueue');
    assert.equal(await criteria.first().locator('[data-review-field="rubric-level"][value="0"]').isChecked(), true, 'Failed save retains edited choices');
    await page.evaluate(() => { window.__reviewSaveUnavailable = false; });
    await page.getByRole('button', { name: 'AI 평가 시작', exact: true }).click();
    await page.waitForFunction(() => window.__evaluationRequests.length === 1 && document.querySelector('[data-review-role="detail"]').textContent.includes('대기열'));
    assert.deepEqual(await page.evaluate(() => window.__manualFixture.reviews.map(r => [r.decision, r.official_score, r.is_official])), [['draft', '10', false], ['draft', '5', false]]);
    assert.equal(await page.evaluate(() => window.__evaluationRequests[0].body.expected.expected_official_revision_hash), '7'.repeat(64));

    await finishAi(page);
    await page.getByRole('button', { name: '평가 새로고침', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('[data-review-field="rubric-level"]:checked').length === 3);
    assert.equal(await page.getByRole('button', { name: 'AI 재채점', exact: true }).count(), 1, 'Successful AI result can be requested again');
    await criteria.first().locator('[data-review-field="rubric-level"][value="0"]').check();
    await criteria.first().locator('[data-review-field="reason-code"]').selectOption('evidence_checked');
    await page.getByRole('button', { name: 'AI 재채점', exact: true }).click();
    await page.waitForFunction(() => window.__retryRequests?.length === 1 && document.querySelector('[data-review-role="detail"]').textContent.includes('대기열'));
    assert.equal(await page.evaluate(() => window.__aiFixture.reviews[0].decision), 'draft', 'AI adjustments are saved before regrading');
    assert.equal(await page.evaluate(() => window.__manualFixture.reviews.length), 2, 'Switching to AI preserves teacher history');
    assert.equal(await page.locator('[data-review-field="rubric-level"]:enabled').count(), 0, 'In-flight run cannot finalize stale candidate');
    await page.screenshot({ path: path.join(artifacts, `editable-grading-${label}.png`), fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    await page.close();

    const replay = await browser.newPage({ viewport });
    replay.setDefaultTimeout(10000);
    await replay.goto(fixture);
    await replay.locator('[data-item-type="candidate"]').click();
    await replay.getByRole('button', { name: 'AI 평가 시작', exact: true }).waitFor();
    await finishAi(replay);
    await replay.evaluate(() => { window.__evaluationStarted = true; window.__reviewPersistTransitions = true; });
    await replay.locator('[data-binding-key]').nth(1).locator('[data-review-field="rubric-level"][value="0"]').check();
    await replay.getByRole('button', { name: 'AI 평가 시작', exact: true }).click();
    await replay.waitForFunction(() => window.__retryRequests?.length === 1);
    assert.equal(await replay.evaluate(() => window.__reviewRequests.length), 1, 'Partially selected manual rubric is saved before requesting AI');
    assert.equal(await replay.evaluate(() => window.__reviewRequests[0].body.payload.item_decisions.length), 1, 'Unselected rubric levels must not invent scores');
    await replay.close();
    const directAi = await browser.newPage({ viewport });
    directAi.setDefaultTimeout(10000);
    await directAi.goto(fixture);
    await directAi.locator('[data-item-type="candidate"]').click();
    await directAi.getByRole('button', { name: 'AI 평가 시작', exact: true }).click();
    await directAi.waitForFunction(() => window.__evaluationRequests.length === 1 && document.querySelector('[data-review-role="detail"]').textContent.includes('대기열'));
    assert.equal(await directAi.evaluate(() => window.__manualRequests.length + window.__reviewRequests.length), 0, 'AI without edited scores must not create a teacher grade');
    await directAi.close();

    const conflict = await browser.newPage({ viewport });
    conflict.setDefaultTimeout(10000);
    await conflict.goto(fixture);
    await conflict.locator('[data-item-type="candidate"]').click();
    await conflict.locator('[data-review-field="rubric-level"]').first().check();
    await conflict.evaluate(() => { window.__manualFixture.review_precondition.expected_review_no = 1; });
    await conflict.getByRole('button', { name: '채점 저장', exact: true }).click();
    await conflict.waitForFunction(() => window.__manualRequests.length === 1 && !document.querySelector('[data-review-action="save-score"]')?.disabled);
    assert.equal(await conflict.evaluate(() => window.__reviewRequests.length), 0, 'First save must not overwrite another teacher review');
    assert.equal(await conflict.locator('[data-review-field="rubric-level"]:checked').count(), 1, 'Concurrent save conflict retains local choices');
    await conflict.close();
    const filtered = await browser.newPage({ viewport });
    filtered.setDefaultTimeout(10000);
    filtered.on('pageerror', error => failures.push(error.message));
    await inspectFilterPreservation(filtered, label);
    await filtered.close();
    console.log(`[OK] ${label}: read-only rubric selection, explicit save, editable draft, failed save preservation, manual-to-AI, AI regrade, replay and history`);
  }
  assert.deepEqual(failures, []);
} finally { await browser.close(); }
