import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../resources/js/assignment-policy-tools.js', import.meta.url), 'utf8');
const panels = [];
const html = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const window = { OJMLTeacherWorkflow: { html } };
const document = { createElement() { return { textContent: 'policy', innerHTML: '', querySelector: () => null }; } };
vm.runInNewContext(source, { window, document });
const content = { querySelector() { return { before(panel) { panels.push(panel.innerHTML); } }; } };
window.OJMLAssignmentPolicy.student(content, {}, {
    submissionPolicy: { allowedTaskTypes: ['classification'], allowedAlgorithms: ['knn', 'decision_tree'] },
});
assert.match(panels[0], /허용 학습 유형:<\/strong> 분류/);
assert.match(panels[0], /제출 허용 알고리즘:<\/strong> K-최근접 이웃, 의사결정나무/);
assert.match(panels[0], /모든 과거 실험 기록을 제출 전에 확인/);
assert.match(panels[0], /자동 삭제하지 않습니다/);
window.OJMLAssignmentPolicy.student(content, {}, {
    submissionPolicy: { allowedTaskTypes: ['<img src=x onerror=alert(1)>'], allowedAlgorithms: ['<script>bad</script>'] },
});
assert.doesNotMatch(panels[1], /<img|<script/);
assert.match(panels[1], /&lt;img/);
console.log('OJ ML assignment-policy UI regression passed (allowed labels, historical-run notice, HTML escaping).');
