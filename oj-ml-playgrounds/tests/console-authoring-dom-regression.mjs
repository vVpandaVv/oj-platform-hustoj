import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const frontend = process.env.OJ_ML_UPSTREAM_FRONTEND;
assert(frontend, 'Set OJ_ML_UPSTREAM_FRONTEND to the installed local frontend test runtime.');
const requirePackage = createRequire(path.join(frontend, 'package.json'));
const { JSDOM } = requirePackage('jsdom');
const plain = value => JSON.parse(JSON.stringify(value));
const source = name => readFileSync(new URL('../resources/js/' + name, import.meta.url), 'utf8');
const addon = source('addon.js');
const closureEnd = addon.lastIndexOf('}());');
assert(closureEnd > 0, 'Expose the real authoring renderer only inside the test harness.');
const dom = new JSDOM('<!doctype html><body><main class="oj-ml-app"></main></body>', {
    url: 'https://fixture.example.test/oj-console/addons/oj-ml-playgrounds/assignments',
    runScripts: 'outside-only',
});
const { window } = dom;
window.TextEncoder = TextEncoder;
window.confirm = () => true;
window.alert = message => assert.fail('Unexpected alert: ' + message);
window.fetch = () => assert.fail('Tests use local API doubles; no network or AI request is allowed.');
const scrolled = [];
window.HTMLElement.prototype.scrollIntoView = function () { scrolled.push(this); };
for (const name of ['teacher-workflow.js', 'rubric-tools.js', 'assignment-policy-tools.js']) window.eval(source(name));
window.eval(addon.slice(0, closureEnd) + 'window.authoringTest = { renderConsoleAssignments: renderConsoleAssignments };\n' + addon.slice(closureEnd));
const rubricDefinition = { version: 1, criteria: [
    { id: 'saved_experiment', title: '기존 실험 비교', description: '설정과 지표의 차이를 설명합니다.', maxScore: 80.25 },
    { id: 'saved_reflection', title: '기존 성찰', description: '개선 근거를 제시합니다.', maxScore: 19.75 },
] };
const contexts = [
    { type: 'class', id: 'legacy-class', label: '보이면 안 되는 전체 수업', lifecycleState: 'active' },
    { type: 'group', id: 'group-1', label: '1반 실습 그룹', lifecycleState: 'active' },
    { type: 'group', id: 'group-inactive', label: '종료된 그룹', lifecycleState: 'archived' },
];
const base = {
    title: '기존 과제', description: '기존 설명', status: 'draft', taskType: 'classification', revision: 3,
    opensAt: '2026-10-15T00:00:00Z', dueAt: '2026-10-20T00:00:00Z', maxAttempts: 4,
    policy: { maxRows: 8760, maxFeatures: 14, maxArtifactBytes: 26214400, allowedAlgorithms: ['decision_tree'],
        allowedFormatVersions: [2], allowedDataTypes: ['tabular'], allowedTaskTypes: ['classification'],
        requireIntegrityManifest: true, allowLateSubmission: false, preserveExtraPolicy: 'kept',
        portfolioTemplate: { title: '기존 포트폴리오', markdown: '## 탐구 질문 {#question}\n\n탐구 질문을 적으세요.\n' },
        portfolioRequirements: { requiredSectionIds: ['question'], minAnswerChars: 12 },
    },
};
const assignments = [
    { ...plain(base), id: 'class-draft', contextType: 'class', contextId: 'legacy-class', rubric: plain(rubricDefinition) },
    { ...plain(base), id: 'class-published', title: '게시된 수업 과제', status: 'published', contextType: 'class', contextId: 'legacy-class', rubric: [] },
    { ...plain(base), id: 'group-total', title: '총점 방식 과제', contextType: 'group', contextId: 'group-1', rubric: { version: 1, criteria: [] } },
];
const calls = [];
const api = {
    async get(route) {
        calls.push({ method: 'GET', route });
        if (route.startsWith('console/contexts?')) return { items: plain(contexts), nextCursor: '' };
        if (route.startsWith('console/assignments?')) return { items: plain(assignments), total: assignments.length };
        assert.fail('Unexpected read: ' + route);
    },
    async post(route, payload) {
        calls.push({ method: 'POST', route, payload: plain(payload) });
        if (route === 'console/assignments') {
            const assignment = { ...plain(payload), id: 'created-' + assignments.length, status: 'draft', revision: 1 };
            assignments.push(assignment);
            return { assignment: plain(assignment) };
        }
        assert.fail('Unexpected create action: ' + route);
    },
    async patch(route, payload) { calls.push({ method: 'PATCH', route, payload: plain(payload) }); return {}; },
    async remove() { assert.fail('Authoring tests never remove server records.'); },
    async upload() { assert.fail('Authoring tests never upload files.'); },
};
const root = window.document.querySelector('main');
root.dataset.consoleAssignmentsUrl = '/oj-console/addons/oj-ml-playgrounds/assignments';
root.dataset.consoleResultsUrl = '/oj-console/addons/oj-ml-playgrounds/results?assignment_id={assignment_id}';
root.dataset.consoleSettingsUrl = '/oj-console/addons/oj-ml-playgrounds/settings';
const mutations = () => calls.filter(call => call.method !== 'GET');
const form = () => root.querySelector('[data-oj-ml-assignment-form]');
const field = name => form().querySelector('[name="' + name + '"]');
const section = key => form().querySelector('[data-assignment-section="' + key + '"]');
const rubric = () => form().querySelector('[data-rubric-editor]');
const settle = async () => { await new Promise(resolve => setImmediate(resolve)); await new Promise(resolve => setImmediate(resolve)); };
async function click(selector) {
    const button = root.querySelector(selector);
    assert(button, 'Expected authoring control: ' + selector);
    button.click();
    await settle();
}
function edit(name, value) {
    field(name).value = value;
    field(name).dispatchEvent(new window.Event('input', { bubbles: true }));
}
async function submit() {
    assert(form().checkValidity(), 'Fill all required native form fields before submitting.');
    form().requestSubmit();
    await settle();
    assert.equal(root.querySelector('[data-oj-ml-status]').dataset.kind, 'success', root.querySelector('[data-oj-ml-status]').textContent);
}

try {
    await window.authoringTest.renderConsoleAssignments(root, api);
    assert.equal(window.OJP, undefined, 'Authoring remains usable without optional platform/AI JavaScript.');
    assert.equal(mutations().length, 0, 'Opening the authoring console performs no writes.');
    assert.equal(field('context').querySelector('[data-context-type="class"]'), null, 'Even an older API returning classes cannot expose them in new assignment choices.');
    assert.equal(field('context').querySelectorAll('[data-context-type="group"]').length, 2);
    assert.equal(field('context').querySelector('[data-context-id="group-inactive"]').disabled, true);
    assert.doesNotMatch(field('context').textContent, /보이면 안 되는/);
    assert.equal(form().querySelector('button[type="submit"]').disabled, false);
    let definition = plain(window.OJMLRubric.readEditor(rubric()));
    assert.equal(definition.criteria.length, 4);
    assert.equal(definition.criteria.reduce((sum, item) => sum + item.maxScore, 0), 100);

    assert.equal(form().querySelectorAll('[data-assignment-section]').length, 6);
    for (const [name, key] of Object.entries({ context: 'basic', title: 'basic', description: 'basic', taskType: 'practice',
        maxAttempts: 'practice', opensAt: 'practice', dueAt: 'practice', allowLateSubmission: 'practice',
        maxRows: 'limits', maxFeatures: 'limits', maxArtifactMiB: 'limits' })) {
        assert.equal(field(name).closest('[data-assignment-section]').dataset.assignmentSection, key, name + ' has one deliberate section.');
        assert.equal(form().querySelectorAll('[name="' + name + '"]').length, 1, 'Moving controls must not clone or lose form values.');
    }
    assert.equal(rubric().closest('[data-assignment-section]'), section('rubric'));
    assert.equal(form().querySelector('.oj-ml-template-editor').closest('[data-assignment-section]'), section('portfolio'));
    assert.equal(form().querySelector('[data-algorithm-choices]').closest('[data-assignment-section]'), section('policy'));
    assert.equal(form().querySelector('.oj-ml-form-navigation, [data-assignment-jump]'), null, 'Native disclosure rows replace a second six-button navigation.');
    assert.equal(section('basic').open, true);
    for (const key of ['practice', 'rubric', 'portfolio', 'policy', 'limits']) {
        assert.equal(section(key).open, false, 'Only basic information starts expanded: ' + key);
        assert(section(key).querySelector(':scope > summary .oj-ml-section-heading'));
        assert(section(key).querySelector(':scope > summary .oj-ml-section-caption'));
        assert(section(key).querySelector(':scope > summary').textContent.length < 30, 'Disclosure summaries stay short and scannable.');
    }
    assert.deepEqual([...form().querySelectorAll('[data-assignment-section]')].map(node => node.dataset.assignmentSection),
        ['basic', 'practice', 'rubric', 'portfolio', 'policy', 'limits']);
    assert.equal(section('basic').querySelector('.oj-ml-form-section__body > p'), null, 'Basic fields do not repeat instructions already conveyed by their labels.');
    const practiceHelp = section('practice').querySelector('.oj-ml-help');
    assert.equal(practiceHelp.open, false);
    assert.match(practiceHelp.textContent, /시간대 기준/);
    await click('[data-assignment-section="portfolio"] > summary');
    assert.equal(section('portfolio').open, true);
    assert.equal(mutations().length, 0, 'Expanding a form section does not write settings or fetch AI.');
    section('rubric').open = false;
    const rubricTitle = rubric().querySelector('[data-rubric-title]');
    const beforeTitle = rubricTitle.value;
    rubricTitle.value = '';
    assert.equal(rubricTitle.checkValidity(), false);
    assert.equal(section('rubric').open, true, 'Native validation opens the section before focusing its invalid field.');
    rubricTitle.value = beforeTitle;
    section('limits').open = false;
    edit('maxRows', '0');
    assert.equal(field('maxRows').checkValidity(), false);
    assert.equal(section('limits').open, true);
    edit('maxRows', '20000');
    section('rubric').open = false;
    const nestedRubric = rubricTitle.closest('details');
    if (nestedRubric && nestedRubric !== section('rubric')) nestedRubric.open = false;
    rubricTitle.value = '';
    assert.equal(rubricTitle.checkValidity(), false);
    assert.equal(section('rubric').open, true);
    if (nestedRubric) assert.equal(nestedRubric.open, true, 'Validation reveals nested and outer disclosures before focusing a hidden invalid field.');
    rubricTitle.value = beforeTitle;

    edit('context', '0');
    edit('title', '새 그룹 ML 과제');
    rubric().querySelector('[data-rubric-title]').value = '교사가 수정한 항목';
    rubric().querySelector('[data-rubric-description]').value = '실험 설정과 실행 기록을 근거로 평가합니다.';
    definition = plain(window.OJMLRubric.readEditor(rubric()));
    await submit();
    const created = mutations().at(-1);
    assert.equal(created.method, 'POST');
    assert.equal(created.route, 'console/assignments');
    assert.equal(created.payload.contextType, 'group');
    assert.equal(created.payload.contextId, 'group-1');
    assert.deepEqual(created.payload.rubric, definition, 'The authored criteria—not merely the total—reach the create payload.');
    assert.equal(created.payload.title, '새 그룹 ML 과제');
    assert.equal(created.payload.policy.portfolioTemplate, null);
    assert.deepEqual(created.payload.policy.allowedAlgorithms, []);
    assert.equal(created.payload.policy.requireIntegrityManifest, true);
    assert.equal(plain(window.OJMLRubric.readEditor(rubric())).criteria.length, 4, 'After save, a new empty form receives a fresh starter rubric.');
    assert.equal(section('basic').open, true);
    assert.equal(section('rubric').open, false, 'Returning to new authoring keeps secondary sections collapsed.');

    await click('[data-edit-id="group-total"]');
    assert.equal(rubric().dataset.rubricMode, 'total');
    assert.equal(rubric().querySelectorAll('[data-rubric-item]').length, 0, 'Editing an existing total-score assignment never auto-migrates it.');
    edit('title', '총점 과제 설명만 편집');
    await submit();
    const totalEdit = mutations().at(-1);
    assert.equal(totalEdit.method, 'PATCH');
    assert.equal(totalEdit.route, 'console/assignments/group-total');
    assert.deepEqual(totalEdit.payload.rubric, { version: 1, criteria: [] });
    assert.equal(totalEdit.payload.expectedRevision, 3);
    assert.equal(totalEdit.payload.policy.preserveExtraPolicy, 'kept');

    await click('[data-edit-id="class-draft"]');
    assert.equal(field('context').value, 'editing');
    assert.equal(field('context').selectedOptions[0].dataset.contextType, 'class');
    assert.equal(field('context').querySelectorAll('[data-context-type="class"]').length, 1, 'Only the exact existing class context is restored for editing.');
    assert.deepEqual(plain(window.OJMLRubric.readEditor(rubric())), rubricDefinition);
    assert.equal(section('portfolio').open, false, 'An existing template does not expand a long editing form automatically.');
    const templateHelp = form().querySelector('.oj-ml-template-editor > .oj-ml-help');
    assert.equal(templateHelp.open, false);
    assert.match(templateHelp.textContent, /게시 전까지만 변경/);
    assert.match(form().querySelector('[data-template-fields]').textContent, /기존 학생 답변은 덮어쓰지 않습니다/);
    edit('description', '기존 수업 과제 편집');
    await submit();
    const classEdit = mutations().at(-1);
    assert.equal(classEdit.method, 'PATCH');
    assert.equal(classEdit.route, 'console/assignments/class-draft');
    assert.equal(classEdit.payload.contextType, 'class');
    assert.equal(classEdit.payload.contextId, 'legacy-class');
    assert.deepEqual(classEdit.payload.rubric, rubricDefinition);
    assert.equal(classEdit.payload.policy.portfolioTemplate.markdown, base.policy.portfolioTemplate.markdown);
    assert.equal(field('context').querySelector('[data-context-type="class"]'), null, 'Reset removes the legacy edit-only choice.');

    await click('[data-edit-id="class-published"]');
    assert.equal(field('context').disabled, true);
    assert.equal(rubric().disabled, true);
    assert.equal(rubric().dataset.rubricMode, 'total');
    edit('title', '게시된 수업 과제 제목 수정');
    await submit();
    const publishedEdit = mutations().at(-1);
    assert.equal(publishedEdit.route, 'console/assignments/class-published');
    assert.equal(publishedEdit.method, 'PATCH');
    assert.equal(publishedEdit.payload.expectedRevision, 3);
    assert.equal(Object.hasOwn(publishedEdit.payload, 'contextType'), false);
    assert.equal(Object.hasOwn(publishedEdit.payload, 'rubric'), false, 'Published criteria/scores are never rewritten by metadata edits.');
    assert.equal(Object.hasOwn(publishedEdit.payload, 'policy'), false);

    const beforeCopy = mutations().length;
    await click('[data-duplicate-id="class-draft"]');
    assert.equal(mutations().length, beforeCopy, 'Copying a legacy class opens a form; it must not call the class duplicate route.');
    assert.equal(field('context').value, '');
    assert.equal(field('context').querySelector('[data-context-type="class"]'), null);
    assert.equal(field('opensAt').value, '');
    assert.equal(field('dueAt').value, '');
    assert.match(field('title').value, /복사/);
    assert.equal(field('context').disabled, false);
    assert.deepEqual(plain(window.OJMLRubric.readEditor(rubric())), rubricDefinition);
    assert.equal(form().querySelector('[data-template-enabled]').checked, true);
    assert.match(root.querySelector('[data-oj-ml-assignment-form-note]').textContent, /기존 학생 명단·제출·평가는 복사하지 않습니다/);
    assert.equal(form().checkValidity(), false, 'A group must be explicitly selected before copying.');
    edit('context', '0');
    await submit();
    const copied = mutations().at(-1);
    assert.equal(copied.method, 'POST');
    assert.equal(copied.route, 'console/assignments');
    assert.equal(copied.payload.contextType, 'group');
    assert.equal(copied.payload.contextId, 'group-1');
    assert.deepEqual(copied.payload.rubric, rubricDefinition);
    assert.deepEqual(copied.payload.policy.allowedAlgorithms, ['decision_tree']);
    assert.equal(copied.payload.policy.preserveExtraPolicy, 'kept');
    assert.deepEqual(copied.payload.policy.portfolioTemplate, base.policy.portfolioTemplate);
    assert.deepEqual(copied.payload.policy.portfolioRequirements, base.policy.portfolioRequirements);
    assert.equal(copied.payload.opensAt, null);
    assert.equal(copied.payload.dueAt, null);
    for (const name of ['id', 'expectedRevision', 'students', 'submissions', 'reviews']) assert.equal(Object.hasOwn(copied.payload, name), false);
    assert.equal(calls.some(call => /\/duplicate$/.test(call.route)), false);
    assert.equal(calls.some(call => /ai|assessment/i.test(call.route)), false, 'Create/edit/copy does not depend on AI services.');

    await click('[data-duplicate-id="class-published"]');
    assert.equal(rubric().dataset.rubricMode, 'total', 'Copying an existing total-score assignment does not silently replace its evaluation method.');
    assert.equal(rubric().disabled, false, 'The copied draft may be intentionally reconfigured before publication.');
    assert.equal(form().querySelector('[data-template-title]').disabled, false);
    await click('[data-oj-ml-edit-cancel]');
    assert.equal(field('context').querySelector('[data-edit-context]'), null);
    assert.equal(rubric().dataset.rubricMode, 'criteria', 'Cancelling a copy returns to the new-assignment starter.');

    for (const itemCount of [3, 5]) {
        assert.equal(plain(window.OJMLRubric.readEditor(rubric())).criteria.length, 4, 'Every new scenario starts from the four-item default.');
        section('rubric').open = true;
        const starter = plain(window.OJMLRubric.readEditor(rubric()));
        if (itemCount === 3) {
            const closed = rubric().querySelectorAll('[data-rubric-row]')[2];
            assert.equal(closed.querySelector('[data-rubric-item]').open, false);
            await click('[data-rubric-row]:nth-child(3) > [data-rubric-remove]');
            assert.deepEqual(plain(window.OJMLRubric.readEditor(rubric())).criteria, starter.criteria.filter((_, index) => index !== 2),
                'Deleting a collapsed starter item preserves all remaining grade IDs, descriptions and points.');
        } else {
            await click('.oj-ml-rubric-toolbar > [data-rubric-add]');
            const added = rubric().querySelector('[data-rubric-rows]').lastElementChild;
            const title = added.querySelector('[data-rubric-title]');
            assert.equal(window.document.activeElement, title);
            title.value = '교사가 추가한 재현성';
            title.dispatchEvent(new window.Event('input', { bubbles: true }));
            added.querySelector('[data-rubric-description]').value = '분할 비율과 설정·실행 기록의 일치를 확인합니다.';
            added.querySelector('[data-rubric-level-description]').value = '분할 비율과 매개변수 설정 및 실행 결과를 모두 재현 가능하게 기록했습니다.';
            assert.deepEqual(plain(window.OJMLRubric.readEditor(rubric())).criteria.slice(0, 4), starter.criteria,
                'Adding a fifth item preserves every original starter criterion.');
        }
        const authored = plain(window.OJMLRubric.readEditor(rubric()));
        assert.equal(authored.version, 2);
        assert.equal(authored.criteria.length, itemCount);
        edit('context', '0');
        edit('title', itemCount + '개 평가 항목 보존 과제');
        const beforeSave = mutations().length;
        await submit();
        assert.equal(mutations().length, beforeSave + 1, 'Creating one assignment sends exactly one write.');
        const saved = mutations().at(-1);
        assert.equal(saved.method, 'POST');
        assert.equal(saved.route, 'console/assignments');
        assert.deepEqual(saved.payload.rubric, authored, 'POST stores the chosen count and full v2 definitions, not the original four-item template.');
        const stored = assignments.find(item => item.title === saved.payload.title);
        assert(stored, 'The local persistence double retains the created assignment.');
        const beforeReload = mutations().length;
        await window.authoringTest.renderConsoleAssignments(root, api);
        await click('[data-edit-id="' + stored.id + '"]');
        assert.equal(mutations().length, beforeReload, 'Reloading and opening a saved assignment are read-only.');
        assert.deepEqual(plain(window.OJMLRubric.readEditor(rubric())), authored,
            itemCount + '-item criteria retain their IDs, level criteria and points when fetched into a fresh editor.');
        assert.equal(rubric().querySelectorAll('[data-rubric-item]').length, itemCount);
        await click('[data-oj-ml-edit-cancel]');
    }

    const noGroupsApi = { ...api, async get(route) {
        if (route.startsWith('console/contexts?')) return { items: plain(contexts.filter(item => item.type === 'class')), nextCursor: '' };
        return api.get(route);
    } };
    await window.authoringTest.renderConsoleAssignments(root, noGroupsApi);
    assert.equal(form().querySelector('button[type="submit"]').disabled, true, 'An active class alone cannot enable new group creation.');
    assert.match(root.querySelector('[data-oj-ml-status]').textContent, /활성 그룹이 없습니다/);
    assert.equal(field('context').options.length, 1);
    await click('[data-edit-id="class-draft"]');
    assert.equal(form().querySelector('button[type="submit"]').disabled, false, 'Existing class assignments remain editable without active groups.');
    assert.equal(field('context').selectedOptions[0].dataset.contextType, 'class');
    await click('[data-oj-ml-edit-cancel]');
    assert.equal(form().querySelector('button[type="submit"]').disabled, true);
    console.log('console-authoring-dom-regression passed');
} finally {
    window.close();
}
