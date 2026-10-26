'use strict';

// Local-only visual audit. The existing fixture intercepts every URL, rejects
// external origins, and renders real plugin assets with synthetic school data.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const workspace = path.resolve(__dirname, '../..');
const fixture = require('./browser-workflow-smoke.cjs');
const { chromium, assets, assignment, attempt, makePage, report } = fixture;
const phase = process.argv[2] || 'after';
assert(/^[a-z0-9-]+$/.test(phase));
const output = path.join(workspace, '_notes/tmp/oj-ml-ui-redesign-audit', phase);
fs.mkdirSync(output, { recursive: true });
const hostCss = fs.readFileSync(path.join(workspace, 'oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css'), 'utf8');
assets['/addon.css'][1] = hostCss + '\n' + assets['/addon.css'][1];
const fonts = path.join(workspace, 'oj-integration-rebuild/oj-platform/resources/ui/fonts/pretendard');
for (const name of fs.readdirSync(fonts)) if (name.endsWith('.woff2')) assets['/fonts/pretendard/' + name] = ['font/woff2', fs.readFileSync(path.join(fonts, name))];
const configuration = {
  maxArtifactBytes: 32 * 1048576, siteQuotaBytes: 12 * 1073741824, minimumFreeBytes: 768 * 1048576,
  inspectionSlots: 3, retentionDays: 365, maintenanceMode: false,
  labFocusOnStart: true, labGuidanceCollapsed: true, retentionHold: true,
  allowPersonalErasure: false, privacyUrl: '',
};
const rubric = { version: 2, criteria: [
  { id: 'question', title: '문제 정의', description: '탐구 목표와 데이터 선택의 근거', maxScore: 30, levels: [
    { id: 'excellent', label: '충분히 충족', score: 30, description: '해결할 문제와 데이터의 관련성을 구체적인 근거로 설명합니다.' },
    { id: 'developing', label: '일부 충족', score: 20, description: '탐구 목표는 있지만 데이터 선택의 근거가 부족합니다.' },
    { id: 'missing', label: '근거 없음', score: 0, description: '문제 정의를 확인할 근거가 제출되지 않았습니다.' },
  ] },
  { id: 'analysis', title: '실험 비교와 해석', description: '실험 설정·결과·개선 방향', maxScore: 70, levels: [
    { id: 'excellent', label: '충분히 충족', score: 70, description: '조건이 다른 실험을 비교하고 결과의 원인과 한계를 설명합니다.' },
    { id: 'developing', label: '일부 충족', score: 40, description: '실험 결과는 기록했으나 비교나 한계 설명이 부족합니다.' },
    { id: 'missing', label: '근거 없음', score: 0, description: '실험 비교와 해석을 확인할 수 없습니다.' },
  ] },
] };
const current = { ...assignment, contextType: 'group', contextId: 'group-1', rubric,
  attemptsUsed: 1, studentStatus: 'open',
  title: '붓꽃 데이터로 시작하는 분류 탐구', description: '모델의 결과를 비교하고, 가장 적절한 선택의 근거를 설명해 보세요.' };
const archived = { ...current, id: 'archived-assignment-1', status: 'archived', studentStatus: 'archived',
  title: '지난 학기 보관된 회귀 탐구', taskType: 'regression', revision: 7 };
const released = { status: 'released', version: 2, score: 70, maxScore: 100,
  feedback: '실험 조건을 비교한 과정이 좋습니다. 다음 실험에서는 데이터 분할의 영향을 살펴보세요.',
  details: { rubricSnapshot: rubric, criteriaScores: [
    { id: 'question', levelId: 'excellent', score: 30, feedback: '질문과 데이터의 관계가 명확합니다.' },
    { id: 'analysis', levelId: 'developing', score: 40, feedback: '실험 결과 차이의 원인을 더 설명해 주세요.' },
  ] } };
const dimensions = [];

async function api(req) {
  if (req.path === 'console/contexts') return { data: { items: [
    { id: 'group-1', type: 'group', label: '1학년 A반 · 머신러닝', lifecycleState: 'active' },
    { id: 'group-2', type: 'group', label: '1학년 B반 · 머신러닝', lifecycleState: 'active' },
  ], nextCursor: null } };
  if (req.path === 'console/assignments' && req.method === 'GET') {
    const status = req.url.searchParams.get('status');
    assert(['unarchived', 'archived'].includes(status), 'Assignment tabs must request an explicit server status filter.');
    return { data: { items: [status === 'archived' ? archived : current], total: 1, page: 1, perPage: 100 } };
  }
  if (req.path === 'console/template-library' || req.path === 'console/datasets') return { data: { items: [] } };
  if (req.path === 'console/settings') return { data: { settings: configuration } };
  if (req.path === 'console/health') return { data: { health: { ok: true, version: 'local-visual-fixture', schemaVersion: 7,
    runtime: { state: 'ready', code: '' }, metrics: { stored_bytes: 2097152, pending: 0, needs_operator: 0 },
    checks: [{ label: 'PHP ZIP', status: 'ok', message: '사용 가능' }, { label: '비공개 저장소', status: 'ok', message: '쓰기 가능' }] } } };
  if (req.path.endsWith('/ai-review')) return { data: { available: false, code: 'OJ_ML_AI_UNAVAILABLE', result: null } };
  if (req.path === 'attempts/accepted-1') return { data: { attempt: { ...attempt('accepted-1', 'accepted'), status: 'graded', rubric, review: released } } };
  if (req.path === 'assignments/assignment-1/history') return { data: { items: [], total: 0, perPage: 20 } };
}

async function capture(page, name, locator) {
  await page.evaluate(() => document.fonts.ready);
  if (locator) await locator.scrollIntoViewIfNeeded();
  else {
    await page.evaluate(() => window.scrollTo({ top: 0, left: 0, behavior: 'instant' }));
    await page.waitForFunction(() => window.scrollY === 0);
  }
  await page.screenshot({ path: path.join(output, name + '.png'), fullPage: false });
  const bounds = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth }));
  dimensions.push({ name, ...bounds });
  if (bounds.document > bounds.viewport + 1 || bounds.body > bounds.viewport + 1) {
    const overflowing = await page.locator('.oj-ml-app *').evaluateAll(nodes => nodes.flatMap(element => {
      const box = element.getBoundingClientRect();
      if (!box.width || box.right <= innerWidth + 1) return [];
      return [{ tag: element.tagName, classes: element.className, text: element.textContent.slice(0, 80), width: box.width, left: box.left, right: box.right }];
    }));
    console.error(JSON.stringify({ name, overflowing: overflowing.slice(0, 25) }, null, 2));
    assert.fail(name + ': horizontal overflow ' + JSON.stringify(bounds));
  }
  const bad = await page.locator('.oj-ml-app input:not([type="hidden"]), .oj-ml-app textarea, .oj-ml-app select, .oj-ml-app button').evaluateAll(elements => elements.flatMap(element => {
    const box = element.getBoundingClientRect(), style = getComputedStyle(element);
    if (!box.width || !box.height || style.visibility === 'hidden') return [];
    const scrollArea = element.closest('.oj-ml-table-wrap');
    if (scrollArea && scrollArea.scrollWidth > scrollArea.clientWidth && ['auto', 'scroll'].includes(getComputedStyle(scrollArea).overflowX)) return [];
    if (box.left >= -1 && box.right <= innerWidth + 1) return [];
    return [{ tag: element.tagName, text: element.textContent.slice(0, 40), left: box.left, right: box.right }];
  }));
  assert.deepEqual(bad, [], name + ': controls exceed viewport');
}

async function runPage(browser, viewport, type) {
  const reads = [];
  const env = await makePage(browser, 'chrome', viewport, type, { assignment: current, api: req => {
    reads.push({ path: req.path, method: req.method, status: req.url.searchParams.get('status') });
    return api(req);
  } });
  const { page, context } = env;
  try {
    // The host tokens and styles are real; this only applies the host root class.
    await page.evaluate(() => {
      document.body.classList.add('ojp-app');
      const root = document.querySelector('.oj-ml-app');
      root.dataset.privacySource = 'platform';
      root.dataset.privacyUrl = '/privacy';
    });
    const prefix = type + '-' + viewport.width;
    if (type === 'console-assignments') {
      const form = page.locator('[data-oj-ml-assignment-form]');
      await form.locator('[name="context"] option[value="0"]').waitFor({ state: 'attached' });
      await form.locator('[name="context"]').selectOption('0');
      await form.locator('[name="title"]').fill('붓꽃 데이터로 시작하는 분류 탐구');
      await capture(page, prefix + '-overview');
      await form.locator('[data-assignment-section="rubric"] > summary').evaluate(element => { element.parentElement.open = true; });
      const rubricEditor = form.locator('[data-rubric-editor]');
      const add = rubricEditor.locator('[data-rubric-add]');
      assert.equal(await add.count(), 1, 'One discoverable criterion-add control is sufficient.');
      assert(await add.isVisible(), 'Criterion add must be visible in the top toolbar.');
      assert(await add.evaluate(button => {
        const rows = button.closest('[data-rubric-editor]').querySelector('[data-rubric-rows]');
        return !!rows && !!(button.compareDocumentPosition(rows) & Node.DOCUMENT_POSITION_FOLLOWING);
      }), 'Criterion add is before the rows, not only at the bottom of a long editor.');
      for (const openCard of await rubricEditor.locator('[data-rubric-item][open]').all()) await openCard.locator(':scope > summary').click();
      assert.equal(await rubricEditor.locator('[data-rubric-item][open]').count(), 0);
      const initialCount = await rubricEditor.locator('[data-rubric-item]').count();
      const firstRow = rubricEditor.locator('.oj-ml-rubric-row').first();
      const remove = firstRow.locator(':scope > [data-rubric-remove]');
      assert(await remove.isVisible(), 'A collapsed criterion has a visible sibling delete control.');
      assert.equal(await firstRow.locator('summary [data-rubric-remove]').count(), 0, 'Delete is not nested inside the disclosure summary.');
      assert(await remove.evaluate(button => !!(button.getAttribute('aria-label') || button.textContent).trim()), 'Delete retains an accessible name.');
      await capture(page, prefix + '-rubric-collapsed', rubricEditor);
      const removedId = await firstRow.locator('[data-rubric-item]').getAttribute('data-criterion-id');
      await remove.focus();
      await page.keyboard.press('Enter');
      await page.waitForFunction(count => document.querySelectorAll('[data-rubric-editor] [data-rubric-item]').length === count, initialCount - 1);
      assert.equal(await rubricEditor.locator('[data-criterion-id="' + removedId + '"]').count(), 0);
      assert.equal(await rubricEditor.locator('[data-rubric-item][open]').count(), 0, 'Deleting a collapsed criterion does not toggle another card.');
      await add.focus();
      await page.keyboard.press('Space');
      await page.waitForFunction(count => document.querySelectorAll('[data-rubric-editor] [data-rubric-item]').length === count, initialCount);
      const addedCard = rubricEditor.locator('[data-rubric-item]').last();
      assert(await addedCard.evaluate(element => element.open), 'Keyboard addition opens the new criterion.');
      assert(await addedCard.locator('[data-rubric-title]').evaluate(input => document.activeElement === input), 'Keyboard addition focuses the new criterion title.');
      await addedCard.locator('[data-rubric-title]').fill('추가한 평가 항목');
      const card = rubricEditor.locator('[data-rubric-item]').first();
      if (await card.evaluate(element => element.tagName === 'DETAILS' && !element.open)) await card.locator(':scope > summary').click();
      await capture(page, prefix + '-rubric', card);
      const list = page.locator('[data-oj-ml-assignment-list]');
      const activeTab = page.locator('[data-assignment-view="unarchived"]');
      const archivedTab = page.locator('[data-assignment-view="archived"]');
      await list.getByText(current.title, { exact: true }).waitFor();
      assert.equal(await list.getAttribute('role'), 'tabpanel');
      assert.equal(await activeTab.getAttribute('role'), 'tab');
      assert.equal(await archivedTab.getAttribute('role'), 'tab');
      assert.equal(await activeTab.getAttribute('aria-selected'), 'true');
      assert.equal(await archivedTab.getAttribute('aria-selected'), 'false');
      assert.equal(await list.getByText(archived.title, { exact: true }).count(), 0);
      assert.equal(reads.filter(item => item.path === 'console/assignments').at(-1).status, 'unarchived');
      await capture(page, prefix + '-active-list', activeTab);
      await activeTab.focus();
      await page.keyboard.press('ArrowRight');
      assert(await archivedTab.evaluate(tab => document.activeElement === tab), 'Right arrow focuses the archive tab.');
      if (await archivedTab.getAttribute('aria-selected') !== 'true') await page.keyboard.press('Enter');
      await list.getByText(archived.title, { exact: true }).waitFor();
      assert.equal(await archivedTab.getAttribute('aria-selected'), 'true');
      assert.equal(await activeTab.getAttribute('aria-selected'), 'false');
      assert.equal(await activeTab.getAttribute('tabindex'), '-1');
      assert.equal(await archivedTab.getAttribute('tabindex'), '0');
      assert.equal(await list.getByText(current.title, { exact: true }).count(), 0, 'Archived assignments are not just mixed into the current list.');
      assert.equal(reads.filter(item => item.path === 'console/assignments').at(-1).status, 'archived');
      assert.equal(await list.getAttribute('aria-labelledby'), await archivedTab.getAttribute('id'));
      await capture(page, prefix + '-archived-list', archivedTab);
      await archivedTab.focus();
      await page.keyboard.press('ArrowLeft');
      assert(await activeTab.evaluate(tab => document.activeElement === tab), 'Left arrow focuses the current assignment tab.');
      if (await activeTab.getAttribute('aria-selected') !== 'true') await page.keyboard.press('Space');
      await list.getByText(current.title, { exact: true }).waitFor();
      await activeTab.focus();
      await page.keyboard.press('End');
      assert(await archivedTab.evaluate(tab => document.activeElement === tab), 'End focuses the last tab.');
      if (await archivedTab.getAttribute('aria-selected') !== 'true') await page.keyboard.press('Enter');
      await list.getByText(archived.title, { exact: true }).waitFor();
      await archivedTab.focus();
      await page.keyboard.press('Home');
      assert(await activeTab.evaluate(tab => document.activeElement === tab), 'Home focuses the first tab.');
      if (await activeTab.getAttribute('aria-selected') !== 'true') await page.keyboard.press('Enter');
      await list.getByText(current.title, { exact: true }).waitFor();
      assert(reads.filter(item => item.path === 'console/assignments').every(item => item.method === 'GET'), 'Tab changes only read data.');
      await page.screenshot({ path: path.join(output, prefix + '-full.png'), fullPage: true });
    } else if (type === 'console-results') {
      await page.locator('[data-result-open="view"]').first().waitFor();
      await capture(page, prefix + '-overview');
      await page.locator('[data-result-open="view"]').first().click();
      await page.locator('[data-oj-ml-preview][data-preview-state="loaded"]').waitFor();
      const form = page.locator('[data-oj-ml-review-form][data-attempt-id="accepted-1"]');
      await form.locator('[data-rubric-grade-adjust]').first().click();
      await form.locator('[data-rubric-score-item]').first().locator('[data-rubric-grade-choice="excellent"]').click();
      await form.locator('[data-rubric-grade-adjust]').nth(1).click();
      await form.locator('[data-rubric-score-item]').nth(1).locator('[data-rubric-grade-choice="missing"]').click();
      await capture(page, prefix + '-grading', form);
      await page.screenshot({ path: path.join(output, prefix + '-full.png'), fullPage: true });
    } else if (type === 'console-settings') {
      await page.locator('[data-settings]').waitFor();
      await capture(page, prefix + '-overview');
      await page.locator('[data-settings-section="capacity"] > summary').click();
      await capture(page, prefix + '-capacity', page.locator('[data-settings-section="capacity"]'));
    } else {
      await page.locator('.oj-ml-rubric-results').waitFor();
      await capture(page, prefix + '-released');
    }
  } finally { await context.close(); }
}

(async () => {
  const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true,
    args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] });
  try {
    for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
      for (const type of ['console-assignments', 'console-results', 'console-settings', 'result']) await runPage(browser, viewport, type);
    }
    assert.deepEqual(report.pageErrors, [], 'No browser exceptions or unexpected external requests');
    assert.deepEqual(report.console, [], 'No browser console warnings/errors');
    console.log(JSON.stringify({ kind: 'Local synthetic UI only; no live server validation', browser: browser.version(), output, dimensions, requests: report.requests.length }, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); console.error(JSON.stringify({ pageErrors: report.pageErrors, console: report.console }, null, 2)); process.exitCode = 1; });
