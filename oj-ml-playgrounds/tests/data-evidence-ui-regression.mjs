import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const frontend = process.env.OJ_ML_UPSTREAM_FRONTEND;
assert(frontend, 'Set OJ_ML_UPSTREAM_FRONTEND to the installed local frontend test runtime.');
const { JSDOM } = createRequire(path.join(frontend, 'package.json'))('jsdom');
const read = name => readFileSync(new URL('../resources/js/' + name, import.meta.url), 'utf8');
const plain = value => JSON.parse(JSON.stringify(value));
const dom = new JSDOM('<!doctype html><main class="oj-ml-app"></main>', { url: 'https://fixture.example.test/console', runScripts: 'outside-only' });
const { window } = dom;
window.TextEncoder = TextEncoder;
window.confirm = () => true;
window.HTMLElement.prototype.scrollIntoView = function () {};
window.fetch = () => assert.fail('Local fixtures never send data externally.');
for (const name of ['teacher-workflow.js', 'rubric-tools.js', 'assignment-policy-tools.js']) window.eval(read(name));
const source = read('addon.js'), end = source.lastIndexOf('}());');
window.eval(source.slice(0, end) + 'window.dataUiTest = { renderConsoleAssignments, submissionPreviewHtml, aiReviewBodyHtml, studentDatasetDescriptionHtml };\n' + source.slice(end));
const ui = window.dataUiTest, root = window.document.querySelector('main');
const settle = async () => { await new Promise(resolve => setImmediate(resolve)); await new Promise(resolve => setImmediate(resolve)); };
const assignments = [], calls = [];
const api = {
    async get(route) {
        if (route.startsWith('console/contexts?')) return { items: [{ id: 'group', type: 'group', label: '합성 그룹', lifecycleState: 'active' }] };
        if (route.startsWith('console/assignments?')) return { items: plain(assignments), total: assignments.length };
        throw new Error('Unexpected route: ' + route);
    },
    async post(route, payload) { assert.equal(route, 'console/assignments'); calls.push(plain(payload)); assignments.push({ ...plain(payload), id: 'a1', revision: 1, status: 'draft' }); return {}; },
    async patch(route, payload) { calls.push(plain(payload)); assignments[0] = { ...assignments[0], ...plain(payload) }; return {}; },
};
const form = () => root.querySelector('[data-oj-ml-assignment-form]');
const field = name => form().querySelector('[name="' + name + '"]');
const click = async selector => { const node = root.querySelector(selector); assert(node, selector); node.click(); await settle(); };
try {
    await ui.renderConsoleAssignments(root, api);
    assert.equal(root.querySelector('[data-dataset-description]').open, false);
    assert.equal(field('datasetDescription').maxLength, 4000);
    assert.equal(field('datasetDescriptionSectionId').value, 'dataset_description');
    assert.equal(calls.length, 0);
    field('datasetDescriptionSectionId').value = 'bad id';
    assert.equal(field('datasetDescriptionSectionId').checkValidity(), false);
    assert.equal(root.querySelector('[data-dataset-description]').open, true, 'Native validation reveals the collapsed field.');
    field('datasetDescriptionSectionId').value = 'dataset_description';
    await click('[data-dataset-description-add]');
    assert.equal(root.querySelectorAll('[data-question-title]').length, 1);
    assert.equal(root.querySelector('[data-question-required]').checked, false, 'Description is optional and never rejects a submission automatically.');
    await click('[data-dataset-description-add]');
    assert.equal(root.querySelectorAll('[data-question-title]').length, 1, 'Repeated append never creates a duplicate section ID.');
    assert.match(root.querySelector('[data-dataset-description-note]').textContent, /이미 있습니다/);
    assert.equal(calls.length, 0, 'Template insertion is local until explicit save.');
    const rubric = root.querySelector('[data-rubric-editor]');
    const criteria = plain(window.OJMLRubric.readEditor(rubric)).criteria;
    assert.deepEqual(criteria[0].evidenceRequirements, ['datasetDescription', 'datasetStatistics']);
    assert(criteria[2].evidenceRequirements.includes('experimentComparison'));
    const first = rubric.querySelector('[data-rubric-item]');
    first.querySelectorAll('[data-rubric-evidence-key]').forEach(input => { input.checked = false; input.dispatchEvent(new window.Event('change', { bubbles: true })); });
    assert.deepEqual(plain(window.OJMLRubric.readEditor(rubric)).criteria[0].evidenceRequirements, []);
    field('context').value = '0'; field('title').value = '데이터 설명 평가'; field('datasetDescription').value = '공개 기상 관측. 열 1 온도, 열 2 강수. 내일 강수 예측.';
    form().requestSubmit(); await settle();
    assert.equal(calls.length, 1);
    assert.match(calls[0].policy.portfolioTemplate.markdown, /\{#dataset_description\}/);
    assert.deepEqual(calls[0].policy.portfolioRequirements, []);
    assert.equal(calls[0].policy.datasetDescriptionSectionId, 'dataset_description');
    assert.deepEqual(calls[0].rubric.criteria[0].evidenceRequirements, []);
    await click('[data-edit-id="a1"]');
    assert.equal(field('datasetDescription').value, calls[0].policy.datasetDescription);
    assert.equal(field('datasetDescriptionSectionId').value, 'dataset_description');
    const originalMarkdown = assignments[0].policy.portfolioTemplate.markdown;
    field('datasetDescriptionSectionId').value = 'other_description';
    await click('[data-dataset-description-add]');
    form().requestSubmit(); await settle();
    assert(calls[1].policy.portfolioTemplate.markdown.startsWith(originalMarkdown), 'Existing template text remains unchanged when appending.');
    assert.match(calls[1].policy.portfolioTemplate.markdown, /\{#other_description\}/);
    assignments[0].status = 'published';
    await ui.renderConsoleAssignments(root, api); await click('[data-edit-id="a1"]');
    for (const selector of ['[name="datasetDescription"]', '[name="datasetDescriptionSectionId"]', '[data-dataset-description-add]']) assert.equal(root.querySelector(selector).disabled, true);

    const old = { version: 1, criteria: [{ id: 'old', title: '기존 기준', description: '설명', maxScore: 10 }] };
    const holder = window.document.createElement('div');
    window.document.body.appendChild(holder);
    holder.innerHTML = window.OJMLRubric.editorHtml(old); window.OJMLRubric.bindEditor(holder);
    assert.deepEqual(plain(window.OJMLRubric.readEditor(holder)), old, 'Opening an older rubric does not silently rewrite its snapshot.');
    assert.equal(holder.querySelectorAll('[data-rubric-evidence-key]:checked').length, 2, 'Unknown legacy requirements default conservatively.');
    const toggle = holder.querySelector('[data-rubric-evidence-key="datasetStatistics"]');
    toggle.click();
    assert.deepEqual(plain(window.OJMLRubric.readEditor(holder)).criteria[0].evidenceRequirements, ['datasetDescription']);

    const attack = '<img src=x onerror="pwn()">';
    assert.equal(ui.studentDatasetDescriptionHtml({}), '');
    const studentDescription = ui.studentDatasetDescriptionHtml({ datasetDescription: attack });
    assert.match(studentDescription, /data-student-dataset-description/);
    assert.match(studentDescription, /&lt;img/); assert.doesNotMatch(studentDescription, /<img| open/);
    const profile = { status: 'partial', rawRows: ['SECRET_ROW'], samples: ['SECRET_EXAMPLE'], datasets: [{ profileId: 'd1', status: 'complete', rowCount: 20, columnCount: 2, rowsAnalyzed: 20,
        columns: [{ index: 0, type: 'numeric', missingCount: 2, missingRate: 0.1, distinctCount: 10, numeric: { mean: 7.25, stdDev: 1.5 }, sampleValues: ['SECRET_VALUE'] },
            { index: 1, type: 'categorical', missingCount: 0, missingRate: 0, distinctCount: 3, distribution: { counts: [12, 6, 2], labels: ['SECRET_CATEGORY'], suppressed: true } }] }],
        bindings: [{ role: 'experiment', experimentIndex: 0, usage: 'training', profileId: 'd1', featureColumns: [0], targetColumn: 1 }] };
    const preview = ui.submissionPreviewHtml({ project: { name: attack }, datasetProfiles: profile, datasetContext: { teacherDescription: attack, studentDescription: '제출 설명' } });
    assert.match(preview, /10\.0%/); assert.match(preview, /7\.25/); assert.match(preview, /12, 6/); assert.match(preview, /타깃 열 2/);
    assert.match(preview, /교사 확인이 필요/); assert.match(preview, /전처리 전/);
    assert.doesNotMatch(preview, /SECRET_ROW|SECRET_EXAMPLE|SECRET_VALUE|SECRET_CATEGORY|<img|onerror="/);
    assert.match(preview, /&lt;img/);
    const readiness = [{ id: 'data', title: '데이터 이해', status: 'needs_review', reasons: [attack, '데이터 설명이 없습니다.'] }];
    const beforeRequest = ui.aiReviewBodyHtml({ available: true, evidenceReadiness: readiness });
    assert.match(beforeRequest, /교사 확인 필요 1항목/); assert.match(beforeRequest, /데이터 설명·통계 요약/);
    assert.doesNotMatch(beforeRequest, /<img|onerror="/);
    const flagged = ui.aiReviewBodyHtml({ available: true, result: { state: 'ready', score: 10, maxScore: 10,
        criteriaScores: [{ id: 'data', title: '데이터 이해', score: 10, levelLabel: '임의 고득점', levelDescription: '임의 기준', reviewStatus: 'needs_review', reviewReasons: [attack] }] } });
    assert.match(flagged, /교사 확인 필요/);
    assert.doesNotMatch(flagged, /제안 10점|제안 합계|임의 고득점|임의 기준|data-oj-ml-ai-action="apply"|<img/);
    const unavailable = ui.aiReviewBodyHtml({ available: false, evidenceReadiness: readiness });
    assert.match(unavailable, /기존 교사 평가는 계속/);
    assert.match(unavailable, /교사 확인 필요/);
    console.log('Data evidence UI regression passed: authoring roundtrip, template preservation, legacy defaults, privacy whitelist, per-criterion review, escaping and native controls.');
} finally { dom.window.close(); }
