import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const workflowSource = readFileSync(new URL('../resources/js/teacher-workflow.js', import.meta.url), 'utf8');
const addonSource = readFileSync(new URL('../resources/js/addon.js', import.meta.url), 'utf8');
const manifestSource = readFileSync(new URL('../src/Platform/Addon_Manifest.php', import.meta.url), 'utf8');
const browser = { window: {}, document: { addEventListener() {} }, TextEncoder };
const context = vm.createContext(browser);
vm.runInContext(workflowSource, context, { filename: 'teacher-workflow.js' });
const workflow = browser.window.OJMLTeacherWorkflow;
assert.ok(workflow, 'The real helper must register its public API.');

// Load the real wrapper as well: HTML parts must still pass its two-level permission gate.
const closureEnd = addonSource.lastIndexOf('}());');
assert.ok(closureEnd > 0, 'The wrapper closure must be found for test-only exports.');
vm.runInContext(addonSource.slice(0, closureEnd)
    + 'window.workflowTest = { resultDetailsRowHtml: resultDetailsRowHtml };\n'
    + addonSource.slice(closureEnd), context, { filename: 'addon.js' });
const renderDetails = browser.window.workflowTest.resultDetailsRowHtml;
const plain = value => JSON.parse(JSON.stringify(value));
const attemptFor = status => ({
    id: 'fixture-attempt', attemptNo: 2, status, receivedAt: '2026-09-07T08:00:00Z',
    artifact: { bytes: 1048576, sha256: 'a'.repeat(64), originalName: 'private-name.mlpx',
        download: { url: 'https://example.test/download', action: 'download', attempt: 'fixture-attempt', nonce: 'private-download-nonce' } },
    evaluation: { status: 'passed', evaluator: 'fixture-inspector', findings: [], facts: { projectName: 'sample' } },
    review: { version: 1, score: 8, maxScore: 10, feedback: 'private-draft-feedback', status: 'draft' },
});

assert.equal(workflow.guidance(null, false).step, 0);
for (const status of ['not_submitted', 'started', 'unknown']) {
    const info = workflow.guidance({ status }, false);
    assert.equal(info.tone, 'neutral');
    assert.match(info.message, /학생 브라우저/);
}
const rejected = attemptFor('needs_resubmit');
rejected.failure = { code: 'OJ_ML_MLPX_NOT_ZIP' };
const rejectedInfo = workflow.guidance(rejected, true);
assert.equal(rejectedInfo.tone, 'danger');
assert.match(rejectedInfo.message, /단정할 수 없습니다/);
assert.match(rejectedInfo.next, /학생별 재제출 설정/);
for (const code of ['OJ_ML_POLICY_TASK_TYPE', 'OJ_ML_POLICY_ALGORITHM']) {
    const policyRejected = attemptFor('needs_resubmit');
    policyRejected.failure = { code };
    const unchanged = plain(policyRejected);
    const info = workflow.guidance(policyRejected, true);
    assert.equal(info.tone, 'danger');
    assert.equal(info.step, 1);
    assert.match(info.title, /과제 조건과 실습 기록/);
    assert.match(info.next, /허용 조건과 현재 선택·이전 실험 기록/);
    assert.match(info.next, /같은 파일을 다시 제출하거나 재검토해도 조건 불일치는 해결되지 않습니다/);
    assert.match(info.next, /원본 프로젝트와 포트폴리오를 보존/);
    assert.match(info.next, /새 프로젝트에서 허용 유형·모델로 실습/);
    assert.match(info.next, /학생별 재제출 설정/);
    assert.doesNotMatch(info.next, /과제.{0,8}(편집|수정)|기록.{0,8}삭제/);
    if (code === 'OJ_ML_POLICY_TASK_TYPE') {
        assert.match(info.message, /학습 유형이 미선택이거나.*허용 유형과 다릅니다/);
        assert.match(info.message, /제출 유형·허용 유형/);
    } else {
        assert.match(info.message, /실행 기록에 허용되지 않은 모델/);
        assert.match(info.message, /과거 실험과 실패·취소 실행/);
        assert.match(info.next, /현재 모델 선택만 바꾸어도 이전 실행 기록은 남습니다/);
    }
    const rendered = renderDetails({ attempt: policyRejected, canEvaluate: true }, true);
    assert.match(rendered, /원본 프로젝트와 포트폴리오를 보존/);
    assert.doesNotMatch(rendered, /data-oj-ml-preview|data-oj-ml-review-form|data-reinspect-id/);
    assert.deepEqual(plain(policyRejected), unchanged, 'Policy guidance must not mutate the original project or submission.');
}
const combinedPolicy = attemptFor('needs_resubmit');
combinedPolicy.failure = { code: 'OJ_ML_POLICY_TASK_TYPE' };
combinedPolicy.evaluation.findings = [{ severity: 'error', code: 'OJ_ML_POLICY_ALGORITHM', message: 'private-untrusted-message' }];
const combinedInfo = workflow.guidance(combinedPolicy, true);
assert.match(combinedInfo.message, /학습 유형이 미선택/);
assert.match(combinedInfo.message, /실행 기록에 허용되지 않은 모델/);
assert.doesNotMatch(JSON.stringify(combinedInfo), /private-untrusted-message/);
const laterPolicy = attemptFor('needs_resubmit');
laterPolicy.failure = { code: 'OJ_ML_POLICY_PORTFOLIO' };
laterPolicy.evaluation.findings = combinedPolicy.evaluation.findings;
assert.match(workflow.guidance(laterPolicy, true).message, /실행 기록에 허용되지 않은 모델/, 'A policy rejection must remain actionable even if another finding is the first failure.');
laterPolicy.evaluation.findings = [{ severity: 'warning', code: 'OJ_ML_POLICY_ALGORITHM' }, null];
assert.match(workflow.guidance(laterPolicy, true).message, /검사 기준/, 'Warnings alone must not imply a policy rejection.');
for (const status of ['quarantined', 'system_pending', 'uploading']) {
    const info = workflow.guidance({ status }, true);
    assert.equal(info.tone, 'warning');
    assert.match(info.next, /중복 제출을 요구하지 말고/);
}
for (const status of ['accepted', 'under_review', 'graded']) {
    assert.equal(workflow.guidance({ status }, true).tone, 'success');
    assert.match(workflow.guidance({ status }, true).next, /비공개/);
    assert.match(workflow.guidance({ status }, false).next, /결과 요약만/);
}

const approved = attemptFor('accepted');
const authorizedDetail = renderDetails({ attempt: approved, canEvaluate: true }, true);
assert.match(authorizedDetail, /data-oj-ml-preview/);
assert.match(authorizedDetail, /data-oj-ml-review-form/);
assert.match(authorizedDetail, /private-download-nonce/);
assert.match(authorizedDetail, /private-draft-feedback/);
assert.match(authorizedDetail, /class="oj-ml-technical"/);
assert.ok(authorizedDetail.indexOf('oj-ml-workflow-banner') < authorizedDetail.indexOf('SHA-256'), 'Action guidance must precede technical metadata.');
const normalBanner = workflow.banner(approved, true);
assert.match(normalBanner, /<p>검사 통과 · 평가 저장은 비공개입니다\.<\/p>/);
assert.match(normalBanner, /<details class="oj-ml-help"><summary>평가 안내<\/summary>/);
assert.doesNotMatch(normalBanner, /<details[^>]+open|<h4>|<strong>다음 작업/);
assert.match(normalBanner, /서버가 재계산한 점수가 아니므로/);
assert.match(workflow.banner({ ...approved, review: { status: 'released' } }, true), /검사 통과 · 평가를 학생에게 공개했습니다/,
    'A compact banner must not incorrectly label an already published review private.');
assert.match(workflow.banner(approved, false), /검사 통과 · 결과 요약만 볼 수 있습니다/);
const summaryText = /class="oj-ml-result-workbench"><summary>(.*?)<\/summary>/.exec(authorizedDetail)?.[1];
assert(summaryText);
assert.match(summaryText, /2차 제출$/);
assert.doesNotMatch(summaryText, /제출 내용을 확인할 수 있습니다|제출 열기/);
for (const status of ['needs_resubmit', 'system_pending']) {
    const riskBanner = workflow.banner(attemptFor(status), true);
    assert.match(riskBanner, /<strong>다음 작업<\/strong>/);
    assert.doesNotMatch(riskBanner, /<details/, 'Blocking findings and recovery guidance must not be hidden as optional help.');
}
for (const [globalPermission, contextPermission] of [[false, true], [true, false], [false, false]]) {
    const limited = renderDetails({ attempt: approved, canEvaluate: contextPermission }, globalPermission);
    assert.doesNotMatch(limited, /data-oj-ml-preview|data-oj-ml-review-form|data-reinspect-id|private-download-nonce|private-draft-feedback|private-name/);
    assert.match(limited, /결과 요약만/);
}
assert.equal(renderDetails({ attempt: null }, true), '');
for (const status of ['quarantined', 'system_pending']) {
    const pending = renderDetails({ attempt: attemptFor(status), canEvaluate: true }, true);
    assert.match(pending, /data-reinspect-id/);
    assert.doesNotMatch(pending, /data-oj-ml-preview|data-oj-ml-review-form/);
    assert.match(pending, /검사를 마치지 못해/);
}
const rejectedDetail = renderDetails({ attempt: rejected, canEvaluate: true }, true);
assert.doesNotMatch(rejectedDetail, /data-oj-ml-preview|data-oj-ml-review-form|data-reinspect-id/);
assert.match(rejectedDetail, /학생별 재제출 설정/);
assert.doesNotMatch(rejectedDetail, /제출 내용 요약/, 'Rejected files must not imply an available or verified project summary.');
const firstIds = [...authorizedDetail.matchAll(/<input id="([^"]+)"/g)].map(match => match[1]);
const secondIds = [...renderDetails({ attempt: approved, canEvaluate: true }, true).matchAll(/<input id="([^"]+)"/g)].map(match => match[1]);
assert.equal(new Set([...firstIds, ...secondIds]).size, firstIds.length + secondIds.length, 'Current submission and history must not duplicate review input IDs.');

const scriptAttack = '<img src=x onerror="globalThis.pwned=1"><script>globalThis.pwned=1</script>';
const hostile = attemptFor('needs_resubmit');
hostile.id = scriptAttack;
hostile.failure = { code: scriptAttack };
hostile.artifact.sha256 = scriptAttack;
hostile.evaluation.findings = [{ message: scriptAttack, code: scriptAttack }];
const escapedDetail = renderDetails({ attempt: hostile, canEvaluate: true }, true);
assert.doesNotMatch(escapedDetail, /<img|<script/);
assert.match(escapedDetail, /&lt;img/);
assert.equal(browser.pwned, undefined);

const learner = { attemptsUsed: 3, allowance: { maxAttempts: 4, dueAt: null } };
const published = { maxAttempts: 3, status: 'published' };
const toolHtml = workflow.tools(learner, published, true, () => '');
assert.match(toolHtml, /data-history-panel/);
assert.match(toolHtml, /data-allowance-panel/);
assert.match(toolHtml, /data-allowance-form/);
assert.match(toolHtml, /남은 1회/);
assert.match(toolHtml, /name="maxAttempts" min="4" max="100" value="4"/);
assert.match(toolHtml, /추가할 횟수가 아니라 총 횟수/);
for (const status of ['closed', 'archived']) {
    const closed = workflow.tools(learner, { ...published, status }, true, () => '');
    assert.doesNotMatch(closed, /data-allowance-form/);
    assert.match(closed, /다시 연 뒤/);
}
const noManagement = workflow.tools(learner, published, false, () => '');
assert.doesNotMatch(noManagement, /data-allowance-form/);
assert.match(noManagement, /과제 관리 권한/);
const usedAll = workflow.tools({ attemptsUsed: 100, allowance: {} }, { ...published, maxAttempts: 100 }, true, () => '');
assert.match(usedAll, /남은 0회/);
assert.doesNotMatch(usedAll, /data-allowance-form/);
assert.match(usedAll, /최대 100회를 모두 사용/);
assert.match(usedAll, /새 과제로 안내/);
const escapedTools = workflow.tools(learner, published, true, () => '"><img src=x>');
assert.doesNotMatch(escapedTools, /<img/);

const stats = workflow.pageStats(['accepted', 'graded', 'needs_resubmit', 'system_pending', 'quarantined', null].map(status => ({ attempt: status ? { status } : null })));
assert.match(stats, /현재 페이지 · 6명/);
assert.match(stats, /aria-label="현재 페이지 요약"/);
assert.doesNotMatch(stats, /전체 과제 통계/);
assert.match(stats, /내용 확인 가능<\/span><strong>2명/);
assert.match(stats, /재제출 확인<\/span><strong>1명/);
assert.match(stats, /검사 대기<\/span><strong>2명/);
assert.match(stats, /미접수<\/span><strong>1명/);
assert.match(workflow.pageStats([]), /현재 페이지 · 0명/);

const navigationDataset = {
    canManage: '0', canReport: '0', canSettings: '0',
    consoleAssignmentsUrl: 'https://example.test/assignments',
    consoleResultsUrl: 'https://example.test/results?assignment_id={assignment_id}',
    consoleSettingsUrl: 'https://example.test/settings',
};
assert.doesNotMatch(workflow.navigation({ dataset: navigationDataset }), /href=/, 'Known URLs alone must not grant navigation visibility.');
const managerNavigation = workflow.navigation({ dataset: { ...navigationDataset, canManage: '1' } });
assert.match(managerNavigation, /과제·양식 설정/);
assert.doesNotMatch(managerNavigation, /학생 제출·평가|>설정</, 'Assignment management must not imply reporting or settings rights.');
const reporterNavigation = workflow.navigation({ dataset: { ...navigationDataset, canReport: '1' } });
assert.match(reporterNavigation, /href="https:\/\/example\.test\/results"/);
assert.doesNotMatch(reporterNavigation, /assignment_id|과제·양식 설정|>설정</);
const operatorNavigation = workflow.navigation({ dataset: { ...navigationDataset, canSettings: '1' } });
assert.match(operatorNavigation, /href="https:\/\/example\.test\/settings">설정</);
assert.doesNotMatch(operatorNavigation, /wp-admin/);
const noSettingsUrlNavigation = workflow.navigation({ dataset: { ...navigationDataset, canSettings: '1', consoleSettingsUrl: '', operationsUrl: 'https://example.test/wp-admin/tools.php?page=oj-ml-operations' } });
assert.doesNotMatch(noSettingsUrlNavigation, /href=|wp-admin/, 'The removed wp-admin Tools page must not be offered as a settings fallback.');
assert.equal(workflow.navigation({ dataset: { ...navigationDataset, canManage: '1', canReport: '1', canSettings: '1', platformLocalNav: '1' } }), '', 'The platform local navigation replaces the wrapper navigation.');
const escapedNavigation = workflow.navigation({ dataset: { ...navigationDataset, canManage: '1', consoleAssignmentsUrl: 'https://example.test/?name="quoted"&a=1' } });
assert.match(escapedNavigation, /&quot;quoted&quot;&amp;a=1/);

const questions = [
    { id: 'topic', title: '주제와 문제 정의', prompt: '  무엇을 해결하나요?\n둘째 줄  ' },
    { id: 'model_2', title: '모델 선택', prompt: '비교한 모델과 이유' },
];
const expectedQuestions = questions.map(question => ({ ...question, prompt: question.prompt.trim() }));
assert.deepEqual(plain(workflow.parse(workflow.markdown(questions))), expectedQuestions, 'Question IDs, titles and prompts must round-trip.');
assert.deepEqual(plain(workflow.parse(workflow.markdown(questions).replace(/\n/g, '\r\n'))), expectedQuestions);
assert.deepEqual(plain(workflow.parse('')), []);
assert.deepEqual(plain(workflow.parse('# 문서 제목\n\n## 첫 문항\n작성 안내')), [{ title: '첫 문항', prompt: '작성 안내' }], 'Unmarked questions must remain unmarked instead of inventing IDs.');
assert.deepEqual(plain(workflow.parse('##공백없는 문항\n작성 안내')), [{ title: '공백없는 문항', prompt: '작성 안내' }]);
assert.deepEqual(plain(workflow.parse('##제목 {#stable-id}\n작성 안내')), [{ id: 'stable-id', title: '제목', prompt: '작성 안내' }]);
assert.deepEqual(plain(workflow.parse('<!--\n## 숨겨진 문항 {#hidden}\n--><!--메모-->\n## 표시 문항 {#visible}\n작성<!-- 숨김 --> 안내')), [{ id: 'visible', title: '표시 문항', prompt: '작성 안내' }], 'HTML comments must follow the embedded app parser and never become questions.');
assert.deepEqual(plain(workflow.parse('##질문\n### 본문 소제목\n본문\n#### 더 작은 제목')), [{ title: '질문', prompt: '### 본문 소제목\n본문\n#### 더 작은 제목' }], 'Third-level headings belong to the current prompt.');
const unmarkedQuestions = [{ title: 'ID 없는 문항', prompt: '### 안내 소제목\n답변 안내' }];
assert.deepEqual(plain(workflow.parse(workflow.markdown(unmarkedQuestions))), unmarkedQuestions);
assert.doesNotMatch(workflow.markdown(unmarkedQuestions), /\{#|undefined/, 'Serializing an unmarked question must not manufacture an ID.');
const hostileQuestions = [{ id: 'xss-safe-id', title: scriptAttack, prompt: scriptAttack }];
const hostileMarkdown = workflow.markdown(hostileQuestions);
assert.equal(workflow.parse(hostileMarkdown)[0].title, scriptAttack, 'The parser must preserve data without executing it.');
assert.equal(browser.pwned, undefined);

// Exercise the real editor's state machine without replacing it with a mock implementation.
const editorEvents = {};
const editorEnabled = { checked: false };
const editorFields = { hidden: true };
const editorTitle = { value: '', hasAttribute() { return false; } };
const editorList = { innerHTML: '' };
const editorNodes = {
    '[data-template-enabled]': editorEnabled,
    '[data-template-fields]': editorFields,
    '[data-template-title]': editorTitle,
    '[data-template-questions]': editorList,
    '[data-template-min-answer]': { value: '1' },
};
const editorPanel = {
    innerHTML: '',
    querySelector(selector) { assert.ok(editorNodes[selector], 'Unexpected editor selector: ' + selector); return editorNodes[selector]; },
    querySelectorAll() { return [editorEnabled, editorTitle]; },
    addEventListener(type, handler) { editorEvents[type] = handler; },
};
browser.document.createElement = () => editorPanel;
const templateEditor = workflow.templateEditor({ querySelector() { return { before(panel) { assert.equal(panel, editorPanel); } }; } });
assert.match(editorPanel.innerHTML, /<details class="oj-ml-help"><summary>양식 안내<\/summary>/);
assert.match(editorPanel.innerHTML, /기존 학생 답변은 덮어쓰지 않습니다/);
assert.doesNotMatch(editorPanel.innerHTML, /<legend>포트폴리오 양식<\/legend><p>/, 'Template authoring begins with its actual control, not repeated introductory paragraphs.');
const legacyMarkdown = '<!--원문 주석\r\n## 주석의 가짜 문항\r\n-->\r\n# 교사 문서 제목\r\n\r\n##공백없는 문항\r\n### 설명 소제목\r\n  기존 안내  \r\n\r\n##두 번째 {#stable-2}\r\n둘째 안내\r\n';
const originalTemplate = { title: '원래 양식', markdown: legacyMarkdown };
templateEditor.load(originalTemplate);
assert.deepEqual(plain(templateEditor.value()), originalTemplate, 'Loading and saving an unchanged template must preserve its exact Markdown.');
editorTitle.value = '  이름만 변경  ';
editorEvents.input({ target: editorTitle });
assert.deepEqual(plain(templateEditor.value()), { title: '이름만 변경', markdown: legacyMarkdown }, 'Renaming the template must preserve comments, CRLF, heading spacing and unmarked IDs byte-for-byte.');
editorEnabled.checked = false;
editorEnabled.onchange();
assert.equal(templateEditor.value(), null);
editorEnabled.checked = true;
editorEnabled.onchange();
assert.equal(templateEditor.value().markdown, legacyMarkdown, 'Toggling template use off and on must not rewrite questions.');
templateEditor.lock(true);
assert.equal(editorTitle.disabled, true);
assert.equal(editorEnabled.disabled, true);
templateEditor.lock(false);
assert.equal(editorTitle.disabled, false);
editorEvents.input({ target: { value: '### 변경한 안내 소제목\n새 안내', dataset: { questionPrompt: '0' }, hasAttribute(name) { return name === 'data-question-prompt'; } } });
const questionEdited = plain(templateEditor.value());
assert.notEqual(questionEdited.markdown, legacyMarkdown, 'Editing a question intentionally regenerates Markdown rather than promising byte preservation.');
assert.deepEqual(plain(workflow.parse(questionEdited.markdown)), [
    { title: '공백없는 문항', prompt: '### 변경한 안내 소제목\n새 안내' },
    { id: 'stable-2', title: '두 번째', prompt: '둘째 안내' },
], 'Question edits must keep explicit IDs and retain the absence of unmarked IDs.');
editorTitle.value = ' ';
assert.throws(() => templateEditor.value(), /양식 이름/);
templateEditor.load(null);
assert.equal(templateEditor.value(), null);

let insertedPanel;
browser.document.createElement = () => ({ innerHTML: '', querySelector() { return {}; } });
workflow.attachStudentTemplate({ querySelector() { return { before(panel) { insertedPanel = panel; } }; } }, {
    submissionPolicy: { portfolioTemplate: { title: scriptAttack, markdown: hostileMarkdown } },
});
assert.ok(insertedPanel, 'Assigned templates must appear in the student workspace.');
assert.doesNotMatch(insertedPanel.innerHTML, /<img|<script/);
assert.match(insertedPanel.innerHTML, /&lt;img/);
assert.match(insertedPanel.innerHTML, /필수 표시 문항만 제출 시 답변 길이/);
assert.match(insertedPanel.innerHTML, /현재 프로젝트에 양식 적용/);
assert.match(insertedPanel.innerHTML, /기존 답변은 보존합니다/);
assert.doesNotMatch(insertedPanel.innerHTML, /미리보기를 확인하고 적용/, 'The import instructions must not invent a confirmation step absent from the embedded app.');
insertedPanel = null;
workflow.attachStudentTemplate({ querySelector() { throw new Error('No template must not touch the workspace DOM.'); } }, {});
assert.equal(insertedPanel, null);

const reviewStart = addonSource.indexOf("container.querySelectorAll('[data-oj-ml-review-form]')");
const reviewEnd = addonSource.indexOf("container.querySelectorAll('[data-reinspect-id]')", reviewStart);
assert.ok(reviewStart >= 0 && reviewEnd > reviewStart);
const reviewBinding = addonSource.slice(reviewStart, reviewEnd);
const reviewCatch = reviewBinding.slice(reviewBinding.lastIndexOf('catch (error)'));
assert.match(reviewCatch, /입력한 점수와 피드백은 유지했습니다/);
assert.doesNotMatch(reviewCatch, /await refresh\(|\.reset\(|innerHTML\s*=/, 'Failure handling must not replace or reset the entered review.');
assert.match(reviewCatch, /finally\s*\{[\s\S]*previousDisabled\.forEach\(function \(entry\) \{ entry\[0\]\.disabled = entry\[1\]; \}\)/, 'Restore each control\'s prior disabled state on failure and success; behavioral pending/save/release checks live in review-save-lock-dom-regression.mjs.');
assert.match(reviewBinding, /querySelectorAll\('input, textarea, select, button'\)/, 'Lock all review inputs while a save is pending, not just buttons.');
assert.match(reviewBinding, /form\.elements\.expectedVersion\.value = String\(savedReview\.version\)/, 'A successful draft save followed by failed release must retain its new version.');
assert.ok(manifestSource.indexOf("'resources/js/teacher-workflow.js'") < manifestSource.indexOf("'resources/js/addon.js'"), 'The release manifest must load the helper before the wrapper.');
assert.match(manifestSource, /'resources\/js\/teacher-workflow\.js'/);
assert.match(addonSource, /portfolioTemplate: portfolioEditor \? portfolioEditor\.value\(\)/);
assert.match(addonSource, /attachStudentTemplate\(content, assignment\)/);

console.log('OJ ML teacher workflow regression passed.');
