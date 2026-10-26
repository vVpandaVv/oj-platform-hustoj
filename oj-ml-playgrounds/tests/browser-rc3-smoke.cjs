'use strict';

// Real local parent UI in Chrome/Edge; all REST and iframe acknowledgements below
// are isolated fixtures. Embedded merge/storage logic has its own upstream suite.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const crypto = require('node:crypto');
const {
  chromium, assignment, attempt, template, makePage, check, noOverflow,
  mobileBounds, viewportShot, report, shots, origin,
} = require('./browser-workflow-smoke.cjs');
const rubric = { version: 1, criteria: [
  { id: 'problem', title: '문제 정의', description: '목표의 구체성', maxScore: 30 },
  { id: 'analysis', title: '실험 해석', description: '결과와 한계의 해석', maxScore: 70 },
] };
const csv = Buffer.from('feature,label\n1,a\n2,b\n', 'utf8');
const dataset = { id: 'dataset-1', name: '수업 데이터.csv', bytes: csv.length, sha256: crypto.createHash('sha256').update(csv).digest('hex'), rowCount: 2, columnCount: 2, canonicalizerVersion: '0.12.1' };
const enriched = { ...assignment, rubric, submissionPolicy: { ...assignment.submissionPolicy, portfolioRequirements: { requiredSectionIds: ['topic'], minAnswerChars: 10 }, allowedAlgorithms: ['decision_tree', 'knn'], datasetResource: dataset } };

async function editorScenario(browser, name, viewport) {
  let drafts = [], library = [], datasets = [dataset];
  const env = await makePage(browser, name, viewport, 'console-assignments', { api: async req => {
    if (req.path === 'console/template-library') {
      if (req.method === 'POST') library.push(req.body);
      return { data: { items: library } };
    }
    if (req.path.startsWith('console/template-library/')) { library = library.filter(item => item.id !== req.path.split('/').pop()); return { data: { items: library } }; }
    if (req.path === 'console/datasets' && req.method === 'GET') return { data: { items: datasets } };
    if (req.path === 'console/datasets' && req.method === 'POST') { datasets.push({ ...dataset, id: 'dataset-uploaded', name: '교사 업로드.csv' }); return { data: { dataset: datasets.at(-1) } }; }
    if (req.path.startsWith('console/datasets/') && req.method === 'DELETE') { datasets = datasets.filter(item => item.id !== req.path.split('/').pop()); return { data: { ok: true } }; }
    if (req.path === 'console/assignments' && req.method === 'GET') return { data: { items: drafts, total: drafts.length, page: 1, perPage: 100 } };
    if (req.path === 'console/assignments' && req.method === 'POST') {
      const created = { ...structuredClone(req.body), id: 'draft-1', revision: 1, status: 'draft' };
      if (created.policy.datasetResource) created.policy.datasetResource = datasets.find(item => item.id === created.policy.datasetResource.id);
      drafts.push(created); return { data: { assignment: created } };
    }
    if (req.path === 'console/assignments/draft-1' && req.method === 'PATCH') {
      drafts[0] = { ...drafts[0], ...req.body, revision: drafts[0].revision + 1 };
      return { data: { assignment: drafts[0] } };
    }
    if (req.path.endsWith('/duplicate')) { const clone = structuredClone(drafts[0]); clone.id = 'clone-1'; clone.title += ' 사본'; clone.revision = 1; drafts.push(clone); return { data: { assignment: clone } }; }
  } });
  const { page, context, requests, label } = env;
  try {
    const form = page.locator('[data-oj-ml-assignment-form]');
    await form.locator('select[name="context"] option[value="0"]').waitFor({ state: 'attached' });
    await form.locator('[name="context"]').selectOption('0');
    await form.locator('[name="title"]').fill('전체 설정 탐구 과제');
    await form.locator('[data-template-enabled]').check();
    await form.locator('[data-template-preset]').click();
    await form.locator('[data-template-title]').fill('보관할 필수 양식');
    await form.locator('[data-question-required="0"]').check();
    await form.locator('[data-template-min-answer]').fill('10');
    await page.getByText('내 양식 보관함 (최대 20개)', { exact: true }).click();
    await form.locator('[data-library-save]').click();
    await form.locator('[data-library-status]').getByText(/내 보관함 1개/).waitFor();
    const stored = requests.find(item => item.path === 'console/template-library' && item.method === 'POST');
    check(label + ' template library saves explicit required IDs and minimum', stored.body.requirements.requiredSectionIds[0] === 'topic' && stored.body.requirements.minAnswerChars === 10);
    await form.locator('[data-question-title="0"]').fill('임시 변경');
    await form.locator('[data-question-required="0"]').uncheck();
    await form.locator('[data-library-list]').selectOption(library[0].id);
    await form.locator('[data-library-load]').click();
    await form.locator('[data-library-status]').getByText('편집기에 복사했습니다. 과제 저장을 눌러 적용하세요.', { exact: true }).waitFor();
    check(label + ' library copy restores saved question text', await form.locator('[data-question-title="0"]').inputValue() !== '임시 변경');
    check(label + ' library copy restores required flags', await form.locator('[data-question-required="0"]').isChecked());
    await form.locator('[data-rubric-preset]').click();
    check(label + ' rubric preset creates four criteria', await form.locator('[data-rubric-item]').count() === 4);
    await form.locator('[data-rubric-max]').first().fill('12.5');
    await form.locator('[data-rubric-title]').first().fill('맞춤 문제 정의');
    await form.locator('[data-rubric-total]').getByText(/총 배점 87.5점/).waitFor();
    await mobileBounds(page, form.locator('[data-rubric-item] input,[data-rubric-item] textarea'), label + ':rubric');
    await viewportShot(page, form.locator('[data-rubric-editor]'), 'rc3-' + name + '-' + viewport.width + '-rubric-editor');
    await form.locator('[data-allowed-algorithm][value="decision_tree"]').check();
    await form.locator('[data-allowed-algorithm][value="knn"]').check();
    await form.locator('[data-dataset-mode]').selectOption('library');
    await form.locator('[data-dataset-refresh]').click();
    await form.locator('[data-dataset-select] option[value="dataset-1"]').waitFor({ state: 'attached' });
    await form.locator('[data-dataset-select]').selectOption('dataset-1');
    await form.locator('[data-dataset-file]').setInputFiles({ name: '교사 업로드.csv', mimeType: 'text/csv', buffer: csv });
    await form.locator('[data-dataset-upload]').click();
    await form.locator('[data-dataset-status]').getByText(/CSV를 저장했습니다/).waitFor();
    check(label + ' dataset upload uses multipart file API', requests.some(item => item.path === 'console/datasets' && item.method === 'POST' && typeof item.body === 'string' && item.body.includes('name="dataset"')));
    check(label + ' uploaded dataset selected', await form.locator('[data-dataset-select]').inputValue() === 'dataset-uploaded');
    await noOverflow(page, label + ':policy');
    await viewportShot(page, form.locator('[data-dataset-mode]'), 'rc3-' + name + '-' + viewport.width + '-dataset-policy');
    await form.locator('button[type="submit"]').click();
    await page.getByText('과제 초안을 만들었습니다.', { exact: true }).waitFor();
    const created = requests.find(item => item.path === 'console/assignments' && item.method === 'POST');
    check(label + ' assignment carries required answer policy', created.body.policy.portfolioRequirements.requiredSectionIds[0] === 'topic' && created.body.policy.portfolioRequirements.minAnswerChars === 10);
    check(label + ' assignment carries editable rubric', created.body.rubric.criteria.length === 4 && created.body.rubric.criteria[0].maxScore === 12.5 && created.body.rubric.criteria[0].title === '맞춤 문제 정의');
    check(label + ' assignment carries selected algorithms and server dataset reference', created.body.policy.allowedAlgorithms.join(',') === 'decision_tree,knn' && created.body.policy.datasetResource.id === 'dataset-uploaded');
    await page.locator('[data-edit-id="draft-1"]').click();
    check(label + ' edit restores all new settings', await form.locator('[data-question-required="0"]').isChecked() && await form.locator('[data-rubric-max]').first().inputValue() === '12.5' && await form.locator('[data-allowed-algorithm][value="knn"]').isChecked());
    await form.locator('[data-template-min-answer]').fill('20');
    await form.locator('button[type="submit"]').click();
    await page.getByText('과제 초안을 수정했습니다.', { exact: true }).waitFor();
    const edited = requests.find(item => item.path === 'console/assignments/draft-1' && item.method === 'PATCH');
    check(label + ' revision-protected update preserves rubric and changes required minimum', edited.body.expectedRevision === 1 && edited.body.policy.portfolioRequirements.minAnswerChars === 20 && edited.body.rubric.criteria[0].id === 'problem');
    await page.locator('[data-duplicate-id="draft-1"]').click();
    await page.locator('[data-edit-id="clone-1"]').waitFor();
    check(label + ' clone calls existing server duplication without client-authored policy', requests.some(item => item.path === 'console/assignments/draft-1/duplicate' && JSON.stringify(item.body) === '{}'));
    await page.locator('[data-edit-id="clone-1"]').click();
    check(label + ' cloned assignment shows independent required-policy and rubric copies', await form.locator('[data-template-min-answer]').inputValue() === '20' && await form.locator('[data-rubric-max]').first().inputValue() === '12.5');
    await form.locator('[data-library-list]').selectOption(library[0].id);
    await form.locator('[data-library-delete]').click();
    await form.locator('[data-library-status]').getByText(/내 보관함 0개/).waitFor();
    check(label + ' deleting saved template does not remove assignment copy', await form.locator('[data-question-title]').count() === 8 && await form.locator('[data-question-required="0"]').isChecked());
    await form.locator('[data-dataset-hide]').click();
    await form.locator('[data-dataset-status]').getByText(/내 목록에서 숨겼습니다/).waitFor();
    check(label + ' dataset hide uses soft-archive endpoint', requests.some(item => item.path === 'console/datasets/dataset-uploaded' && item.method === 'DELETE'));
    check(label + ' hidden current dataset stays attached to the draft', await form.locator('[data-dataset-select]').inputValue() === 'dataset-uploaded');
  } finally { await context.close(); }
}

async function rubricScenario(browser, name, viewport) {
  let saved = null, reject = true;
  const api = async req => {
    if (req.path.endsWith('/review')) {
      if (reject) return { status: 503, raw: JSON.stringify({ message: '의도적인 항목 평가 저장 실패' }) };
      saved = { version: 1, status: 'draft', score: 82.5, maxScore: 100, feedback: req.body.feedback, details: { rubricSnapshot: rubric, criteriaScores: req.body.criteriaScores } };
      return { data: { review: saved } };
    }
    if (req.path.endsWith('/release')) { saved.status = 'released'; saved.version = 2; req.rows[0].attempt.review = saved; return { data: { review: saved } }; }
    if (req.path === 'attempts/accepted-1') return { data: { attempt: { ...attempt('accepted-1', 'graded'), review: saved } } };
    if (req.path === 'assignments/assignment-1/history') return { data: { items: [attempt('accepted-1', 'graded')], total: 1, perPage: 20 } };
  };
  const env = await makePage(browser, name, viewport, 'console-results', { assignment: enriched, api });
  const { page, context, requests, label } = env;
  try {
    await page.locator('[data-result-open="view"]').first().click();
    const form = page.locator('[data-oj-ml-review-form][data-attempt-id="accepted-1"]');
    await form.locator('[data-rubric-review]').waitFor();
    check(label + ' teacher sees criterion scoring instead of editable global totals', await form.locator('[data-rubric-score]').count() === 2 && await form.locator('[name="score"]').count() === 0);
    await form.locator('[data-rubric-score]').nth(0).fill('25.5');
    await form.locator('[data-review-action="save"]').click();
    await page.locator('[data-oj-ml-status]').getByText('모든 항목에 점수를 입력하거나, 모두 비워 피드백만 저장하세요.', { exact: true }).waitFor();
    check(label + ' partial criterion scores blocked before API', !requests.some(item => item.path.endsWith('/review')));
    await form.locator('[data-rubric-score]').nth(1).fill('57');
    await form.locator('[data-rubric-feedback]').nth(0).fill('목표가 구체적입니다.');
    await form.locator('[name="feedback"]').fill('다음 실험에서는 표본을 늘려 보세요.');
    await form.locator('[data-rubric-review-total]').getByText(/합계 82.5 \/ 100점/).waitFor();
    await form.locator('[data-review-action="save"]').click();
    await page.getByText(/의도적인 항목 평가 저장 실패.*입력한 점수와 피드백은 유지했습니다/).waitFor();
    check(label + ' server refusal preserves item score and feedback', await form.locator('[data-rubric-score]').nth(0).inputValue() === '25.5' && await form.locator('[data-rubric-feedback]').nth(0).inputValue() === '목표가 구체적입니다.');
    const posted = requests.find(item => item.path.endsWith('/review')).body;
    check(label + ' only individual scores sent for server recomputation', posted.score === null && posted.maxScore === null && posted.expectedVersion === 0 && posted.criteriaScores[0].id === 'problem' && posted.criteriaScores[0].score === 25.5);
    await noOverflow(page, label + ':criterion-review');
    await mobileBounds(page, form.locator('input:not([type="hidden"]),textarea,button'), label + ':criterion-controls');
    await viewportShot(page, form.locator('[data-rubric-review]'), 'rc3-' + name + '-' + viewport.width + '-criterion-review');
    reject = false;
    await form.locator('[data-review-action="release"]').click();
    await page.getByText('평가를 저장하고 학생에게 공개했습니다.', { exact: true }).waitFor();
    check(label + ' release uses saved review version', requests.find(item => item.path.endsWith('/release')).body.expectedVersion === 1);
  } finally { await context.close(); }
  const learner = await makePage(browser, name, viewport, 'result', { assignment: enriched, api });
  try {
    await learner.page.getByRole('heading', { name: '공개된 항목별 평가' }).waitFor();
    check(name + ':' + viewport.width + ' learner sees released criterion feedback and fixed rubric snapshot', await learner.page.getByText('목표가 구체적입니다.', { exact: true }).isVisible() && await learner.page.getByText('목표의 구체성', { exact: true }).isVisible());
    check(name + ':' + viewport.width + ' learner has no editable rubric controls', await learner.page.locator('[data-rubric-score],[data-oj-ml-review-form]').count() === 0);
    await noOverflow(learner.page, name + ':released-rubric');
    await viewportShot(learner.page, learner.page.locator('.oj-ml-rubric-results'), 'rc3-' + name + '-' + viewport.width + '-released-rubric');
  } finally { await learner.context.close(); }
}

async function bridgeScenario(browser, name, viewport) {
  let datasetResponse = csv;
  const env = await makePage(browser, name, viewport, 'lab', { assignment: enriched, api: req => {
    if (req.path === 'assignments/assignment-1/dataset') return { contentType: 'text/csv; charset=utf-8', raw: datasetResponse };
  } });
  const { page, context, label } = env;
  try {
    const apply = page.locator('[data-student-template-apply]'), note = page.locator('[data-template-bridge-status]');
    await apply.waitFor();
    check(label + ' no project or trusted frame means apply disabled', await apply.isDisabled());
    await page.locator('.oj-ml-student-template summary').click();
    check(label + ' learner sees required question minimum', await page.getByRole('heading', { name: '문제 정의 · 필수 10자 이상' }).isVisible());
    await page.evaluate(async () => {
      const root = document.querySelector('.oj-ml-app'), frame = document.createElement('iframe');
      frame.className = 'oj-ml-playground-frame';
      frame.srcdoc = '<!doctype html><p>격리된 메시지 시험용 작업 창</p><script>window.received=[];addEventListener("message",e=>{if(e.data.type==="oj-ml:portfolio-template-apply")received.push(e.data)})<\/script>';
      const loaded = new Promise(resolve => frame.onload = resolve);
      root.querySelector('[data-oj-ml-frame]').append(frame); root.querySelector('[data-oj-ml-workspace]').hidden = false;
      await loaded;
      root.dispatchEvent(new CustomEvent('oj-ml:trusted-frame', { detail: { frame, frameNonce: 'a'.repeat(32), assignmentId: 'assignment-1', storageSession: 'synthetic-storage-session' } }));
    });
    const frame = page.frames().find(item => item.parentFrame());
    const base = { version: 2, frameNonce: 'a'.repeat(32), assignmentId: 'assignment-1', storageSession: 'synthetic-storage-session' };
    async function send(data) { await frame.evaluate(({ data, origin }) => parent.postMessage(data, origin), { data: { ...base, ...data }, origin }); }
    const state = { type: 'oj-ml:portfolio-project', projectId: 'project-1', projectToken: 'token-project-1', projectName: '학생의 현재 프로젝트' };
    await page.evaluate(data => {
      const source = document.querySelector('.oj-ml-playground-frame').contentWindow;
      window.dispatchEvent(new MessageEvent('message', { data, source, origin: 'https://wrong-origin.test' }));
      window.dispatchEvent(new MessageEvent('message', { data, source: window, origin: location.origin }));
    }, { ...base, ...state });
    check(label + ' incorrect message origin/source cannot enable apply', await apply.isDisabled());
    await send({ ...state, frameNonce: 'b'.repeat(32) });
    await send({ ...state, storageSession: 'wrong-session' });
    check(label + ' forged nonce/session cannot enable apply', await apply.isDisabled());
    await send(state);
    await note.getByText('적용 대상: 학생의 현재 프로젝트', { exact: true }).waitFor();
    check(label + ' trusted current project enables apply', await apply.isEnabled());
    check(label + ' project state never triggers automatic application', await frame.evaluate(() => received.length) === 0);
    await apply.click();
    await page.waitForFunction(() => document.querySelector('[data-student-template-apply]').disabled);
    await frame.waitForFunction(() => received.length === 1);
    const request = await frame.evaluate(() => received[0]);
    check(label + ' explicit button sends bound question-only request', request.projectId === state.projectId && request.projectToken === state.projectToken && request.template.sections.length === 2 && !('required' in request.template.sections[0]) && !('answers' in request.template));
    await send({ ...state, projectId: 'project-2', projectToken: 'token-project-2', projectName: '다른 프로젝트' });
    await note.getByText('적용 대상: 다른 프로젝트', { exact: true }).waitFor();
    await send({ ...request, type: 'oj-ml:portfolio-template-result', state: 'applied', added: 2 });
    check(label + ' stale prior-project acknowledgement is ignored', await note.textContent() === '적용 대상: 다른 프로젝트');
    await apply.click();
    await frame.waitForFunction(() => received.length === 2);
    const second = await frame.evaluate(() => received[1]);
    await send({ ...second, requestId: 'wrong_request_id_123456', type: 'oj-ml:portfolio-template-result', state: 'applied', added: 2 });
    check(label + ' wrong request acknowledgement does not clear busy state', await apply.isDisabled());
    await send({ ...second, type: 'oj-ml:portfolio-template-result', state: 'error', added: 0, code: 'OJ_PORTFOLIO_SAVE_FAILED' });
    await note.getByText(/양식 저장에 실패했습니다/).waitFor();
    check(label + ' actionable save failure allows explicit retry', await apply.isEnabled());
    await apply.click();
    await frame.waitForFunction(() => received.length === 3);
    const third = await frame.evaluate(() => received[2]);
    check(label + ' retry has a new correlated request ID', third.requestId !== second.requestId);
    await send({ ...third, type: 'oj-ml:portfolio-template-result', state: 'unchanged', added: 0 });
    await note.getByText(/양식을 저장했습니다.*기존 답변은 유지됩니다/).waitFor();
    const downloadWait = page.waitForEvent('download');
    await page.locator('[data-student-dataset-download]').click();
    const download = await downloadWait;
    const stream = await download.createReadStream(); const chunks = []; for await (const chunk of stream) chunks.push(chunk);
    check(label + ' learner dataset download verifies hash and preserves exact CSV', Buffer.concat(chunks).equals(csv));
    await page.locator('[data-dataset-download-status]').getByText(/CSV 무결성을 확인하고 내려받았습니다/).waitFor();
    let unexpectedDownloads = 0;
    page.on('download', () => { unexpectedDownloads += 1; });
    datasetResponse = Buffer.from(csv); datasetResponse[0] ^= 1;
    await page.locator('[data-student-dataset-download]').click();
    await page.locator('[data-dataset-download-status]').getByText(/자료 무결성 또는 로그인 상태를 확인하지 못했습니다/).waitFor();
    check(label + ' same-size tampered CSV is rejected before download', unexpectedDownloads === 0);
    await noOverflow(page, label + ':template-bridge');
    await viewportShot(page, page.locator('.oj-ml-student-template'), 'rc3-' + name + '-' + viewport.width + '-oneclick-portfolio');
    await page.evaluate(() => { document.querySelector('.oj-ml-app').dataset.publicPcLocked = '1'; });
    const before = await frame.evaluate(() => received.length);
    check(label + ' session lock hides private apply controls', !(await apply.isVisible()));
    await apply.dispatchEvent('click');
    check(label + ' session lock stops further apply messages', await frame.evaluate(() => received.length) === before);
    const lockedProject = { ...state, projectId: 'project-while-locked', projectToken: 'token-while-locked', projectName: '잠금 확인 중 열린 프로젝트' };
    await send(lockedProject);
    await note.getByText('적용 대상: 잠금 확인 중 열린 프로젝트', { exact: true }).waitFor({ state: 'attached' });
    check(label + ' temporary lock retains correlated project notification without enabling action', await apply.isDisabled());
    await apply.dispatchEvent('click');
    check(label + ' retained project state never applies while locked', await frame.evaluate(() => received.length) === before);
    await page.evaluate(() => { document.querySelector('.oj-ml-app').dataset.publicPcLocked = '0'; });
    await page.waitForFunction(() => !document.querySelector('[data-student-template-apply]').disabled);
    check(label + ' unlocking enables retained current project without another notification', await apply.isVisible() && await apply.isEnabled());
    await apply.click();
    await frame.waitForFunction(count => received.length === count, before + 1);
    const unlockedRequest = await frame.evaluate(() => received.at(-1));
    check(label + ' post-unlock explicit request targets latest locked-time project token', unlockedRequest.projectId === lockedProject.projectId && unlockedRequest.projectToken === lockedProject.projectToken);
    await send({ ...unlockedRequest, type: 'oj-ml:portfolio-template-result', state: 'unchanged', added: 0 });
    await note.getByText(/양식을 저장했습니다.*기존 답변은 유지됩니다/).waitFor();
    await page.evaluate(() => { document.querySelector('.oj-ml-app').dataset.publicPcCovered = '1'; });
    await page.waitForFunction(() => document.querySelector('[data-student-template-apply]').disabled);
    check(label + ' privacy cover immediately disables apply', await apply.isDisabled());
    await apply.dispatchEvent('click');
    check(label + ' privacy cover blocks dispatched clicks', await frame.evaluate(() => received.length) === before + 1);
    await page.evaluate(() => { document.querySelector('.oj-ml-app').dataset.publicPcCovered = '0'; });
    await page.waitForFunction(() => !document.querySelector('[data-student-template-apply]').disabled);
    check(label + ' removing temporary cover restores current project action', await apply.isEnabled());
    await apply.click();
    await frame.waitForFunction(count => received.length === count, before + 2);
    const revokedRequest = await frame.evaluate(() => received.at(-1));
    await page.evaluate(() => { document.querySelector('.oj-ml-app').dataset.publicPcTerminal = '1'; });
    await page.waitForFunction(() => document.querySelector('[data-student-template-apply]').disabled);
    const terminalNote = await note.textContent();
    await send({ ...revokedRequest, type: 'oj-ml:portfolio-template-result', state: 'applied', added: 2 });
    await send({ ...state, projectId: 'project-after-terminal', projectToken: 'token-after-terminal' });
    check(label + ' terminal revocation rejects pending acknowledgement and project state', await apply.isDisabled() && await note.textContent() === terminalNote);
    await page.evaluate(() => { document.querySelector('.oj-ml-app').dataset.publicPcTerminal = '0'; });
    await send(lockedProject);
    await apply.dispatchEvent('click');
    check(label + ' terminal-cleared identity never revives on attribute reset or old project notification', await apply.isDisabled() && await frame.evaluate(() => received.length) === before + 2);
    // An independent binding exercises legacy ID-less titles mixed with an
    // explicit suffix ID; generated IDs must match upstream Markdown import.
    await page.evaluate(() => {
      const root = document.querySelector('.oj-ml-app'), panel = document.createElement('section');
      panel.setAttribute('data-collision-bridge-fixture', '');
      panel.innerHTML = '<button data-student-template-apply>충돌 양식 적용 시험</button><p data-template-bridge-status></p>';
      root.append(panel);
      window.OJMLPortfolioBridge.bind(panel, root, { id: 'assignment-1' }, [
        { title: 'A', prompt: '첫 번째 문항' },
        { id: 'a-2', title: 'B', prompt: '명시적 ID 문항' },
        { title: 'A', prompt: '중복 제목 문항' },
      ]);
      root.dispatchEvent(new CustomEvent('oj-ml:trusted-frame', { detail: { frame: root.querySelector('.oj-ml-playground-frame'), frameNonce: 'a'.repeat(32), assignmentId: 'assignment-1', storageSession: 'synthetic-storage-session' } }));
    });
    await send(state);
    const collisionPanel = page.locator('[data-collision-bridge-fixture]');
    await collisionPanel.locator('[data-template-bridge-status]').getByText('적용 대상: 학생의 현재 프로젝트', { exact: true }).waitFor();
    await collisionPanel.locator('[data-student-template-apply]').click();
    await frame.waitForFunction(count => received.length === count, before + 3);
    const collisionRequest = await frame.evaluate(() => received.at(-1));
    check(label + ' title collisions with explicit suffix ID match upstream import normalization', collisionRequest.template.sections.map(section => section.id).join(',') === 'a,a-2,a-2-2');
    await send({ ...collisionRequest, type: 'oj-ml:portfolio-template-result', state: 'unchanged', added: 0 });
  } finally { await context.close(); }
}

(async () => {
  fs.mkdirSync(shots, { recursive: true });
  for (const [name, executablePath] of [['chrome', process.env.OJ_ML_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe'], ['edge', process.env.OJ_ML_EDGE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']]) {
    assert.ok(fs.existsSync(executablePath), name + ' executable must exist');
    const browser = await chromium.launch({ executablePath, headless: true, args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] });
    try {
      report.browsers.push({ name, version: browser.version() });
      for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
        await editorScenario(browser, name, viewport);
        await rubricScenario(browser, name, viewport);
        await bridgeScenario(browser, name, viewport);
      }
    } finally { await browser.close(); }
  }
  check('rc3 no uncaught browser errors', report.pageErrors.length === 0);
  check('rc3 no unexpected browser console errors', report.console.filter(item => !item.message.includes('503 (Service Unavailable)')).length === 0);
  process.stdout.write(JSON.stringify({ kind: 'rc3 real local UI + mocked REST/iframe; no live server or student content', browsers: report.browsers, count: report.checks.length, pageErrors: report.pageErrors, screenshots: shots, requests: report.requests.length }, null, 2) + '\n');
  process.stdout.write('RC3 browser workflow smoke tests passed.\n');
})().catch(error => { process.stderr.write(error.stack + '\n' + JSON.stringify(process.env.OJ_ML_SMOKE_VERBOSE ? report : { browsers: report.browsers, checks: report.checks.slice(-8), pageErrors: report.pageErrors, console: report.console }, null, 2) + '\n'); process.exitCode = 1; });
