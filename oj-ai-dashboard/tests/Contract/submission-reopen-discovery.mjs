import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/assignment-workbench.js', import.meta.url), 'utf8');
const markup = await readFile(new URL('../../src/Platform/Assignment_Workbench_Page_Handler.php', import.meta.url), 'utf8');
assert.equal(markup.match(/data-role="submission-actions"/g)?.length, 1);
assert.ok(markup.indexOf('data-role="submission-actions"') < markup.indexOf('data-role="submission-answers"'), 'Controls must precede long answers.');
assert.match(markup, /id="oj-ai-submission-reopen-status"[^>]+role="status" hidden/);

function node(tag = '', className = '', textContent = '') {
  return {
    tag, className, textContent, dataset: {}, attributes: {}, children: [], disabled: false,
    append(...children) { this.children.push(...children); },
    replaceChildren(...children) { this.children = children; },
    setAttribute(name, value) { this.attributes[name] = value; },
  };
}
const actions = node();
const status = node();
const summary = node();
const now = Date.parse('2026-09-15T03:00:00Z');
const nodes = {
  '[data-role="submission-actions"]': actions,
  '[data-role="submission-reopen-status"]': status,
  '[data-role="submission-control-summary"]': summary,
};
const scope = vm.createContext({
  Date: class extends Date { static now() { return now; } },
  canManageSubmissions: true, canAssessSubmissions: true,
  find: selector => nodes[selector], create: node,
  learnerLabels: { bind: () => {} },
  submissionPdfKey: submission => submission.official_revision_id || '',
  submissionPdfJobs: new Map(),
});
vm.runInContext(source.slice(source.indexOf('  const submissionReopenBlockReason ='), source.indexOf('  const updateSubmissionPdf =')), scope);
const base = {
  assignment_state: 'open', state: 'submitted', official_revision_id: 'synthetic-revision', official_attempt_no: 2,
  schedule: { opens_at_utc: '2026-09-15 02:00:00', closes_at_utc: '2026-09-15 04:00:00' },
};
function render(overrides = {}) {
  scope.submission = { ...base, ...overrides };
  vm.runInContext('renderSubmissionActions(submission)', scope);
  return actions.children.find(child => child.dataset.command === 'reopen');
}
for (const state of ['submitted', 'auto_submitted', 'proxy_submitted', 'locked']) {
  const button = render({ state });
  assert.equal(actions.children[0], button, 'Reopen is the first action.');
  assert.equal(button.textContent, '추가 제출 1회 허용');
  assert.equal(button.disabled, false);
  assert.equal(status.hidden, true);
  assert.equal(actions.children.filter(child => child.dataset.command === 'reopen').length, 1);
  assert.ok(actions.children.some(child => child.dataset.action === 'evaluate-submission'));
  assert.ok(actions.children.some(child => child.dataset.action === 'export-submission-pdf'));
}
for (const [overrides, message] of [
  [{ assignment_state: 'closed' }, /배포 재개/],
  [{ assignment_state: 'withdrawn' }, /진행 중/],
  [{ assignment_state: 'scheduled' }, /진행 중/],
  [{ state: 'draft', official_revision_id: null }, /제출이 완료/],
  [{ state: 'reopened' }, /이미 추가 제출이 허용/],
  [{ schedule: { closes_at_utc: '2026-09-15 03:00:00' } }, /일정 변경/],
  [{ schedule: { closes_at_utc: '2026-09-15T02:59:59Z' } }, /일정 변경/],
  [{ state: 'reopened', schedule: { closes_at_utc: '2026-09-15T02:59:59Z' } }, /일정 변경/],
  [{ schedule: { opens_at_utc: '2026-09-15T03:00:01Z' } }, /시작 시각/],
]) {
  const button = render(overrides);
  assert.ok(button, 'An unavailable action must not disappear.');
  assert.equal(button.disabled, true);
  assert.equal(button.attributes['aria-describedby'], 'oj-ai-submission-reopen-status');
  assert.equal(status.hidden, false);
  assert.match(status.textContent, message);
}
assert.equal(render({ schedule: { opens_at_utc: '2026-09-15T03:00:00Z', closes_at_utc: null } }).disabled, false);
assert.equal(status.textContent, '', 'Switching to an eligible student clears the previous reason.');
scope.canManageSubmissions = false;
render();
assert.equal(actions.children.some(child => child.dataset.action === 'submission-control'), false);
assert.equal(status.hidden, true);

let dialogOpened = false;
scope.text = () => { dialogOpened = true; };
scope.submissionControlNotice = () => {};
vm.runInContext(source.slice(source.indexOf('  const openSubmissionControl ='), source.indexOf('  const closeSubmissionControl =')), scope);
scope.activeSubmission = base;
vm.runInContext("openSubmissionControl('reopen', null)", scope);
assert.equal(dialogOpened, false, 'No management permission must block the dialog.');
scope.canManageSubmissions = true;
scope.activeSubmission = { ...base, schedule: { closes_at_utc: '2026-09-15T03:00:00Z' } };
vm.runInContext("openSubmissionControl('reopen', null)", scope);
assert.equal(dialogOpened, false, 'Recheck the clock when the teacher clicks, before opening a stale action.');
assert.match(status.textContent, /일정 변경/);
scope.activeSubmission = base;
scope.activeSubmissionName = 'Synthetic student';
vm.runInContext("openSubmissionControl('reopen', null)", scope);
assert.equal(dialogOpened, true, 'An eligible submission still opens the existing reason dialog.');
assert.equal(summary.hidden, false);
assert.equal(summary.textContent, '현재 제출 2회 · 추가 제출 1회 허용');
vm.runInContext("openSubmissionControl('lock', null)", scope);
assert.equal(summary.hidden, true, 'A lock confirmation must not claim to grant another attempt.');
assert.equal(summary.textContent, '');

const studentSource = await readFile(new URL('../../resources/js/student-submission-autosave.js', import.meta.url), 'utf8');
const submitButton = node();
const submitLabel = node();
const studentScope = vm.createContext({
  ensureStatusUi() {}, isObject: value => value !== null && typeof value === 'object',
  root: { querySelector: selector => selector === '[data-action="submit-assignment"]' ? submitButton : submitLabel },
  state: { submitting: false, submission: { state: 'reopened', actions: ['submission.submit'] } },
});
vm.runInContext(studentSource.slice(studentSource.indexOf('  const updateSubmitUi ='), studentSource.indexOf('  const setSaveState =')), studentScope);
vm.runInContext('updateSubmitUi()', studentScope);
assert.equal(submitButton.hidden, false);
assert.equal(submitLabel.textContent, '교사가 추가 제출 1회를 허용했습니다');
studentScope.state.submission = { state: 'submitted', actions: [] };
vm.runInContext('updateSubmitUi()', studentScope);
assert.equal(submitButton.hidden, true);
assert.equal(submitLabel.textContent, '제출이 완료되었습니다');

const studentPageSource = await readFile(new URL('../../resources/js/student-assignments.js', import.meta.url), 'utf8');
let studentStatus;
const pageScope = vm.createContext({
  root: { dataset: {}, querySelector: selector => selector === '[data-role="submission-save"]' ? { after: value => { studentStatus = value; } } : null },
  create: node, finalSubmission: () => false, formatDate: value => value,
});
vm.runInContext(studentPageSource.slice(studentPageSource.indexOf('  const renderSubmissionStatus ='), studentPageSource.indexOf('  const containsResponseNode =')), pageScope);
for (const [state, used, remaining, expectedSummary, granted] of [
  ['reopened', 1, 1, '1 / 2회', false],
  ['reopened', 2, 1, '2회 (기본 2회)', true],
  ['submitted', 3, 0, '3회 (기본 2회)', false],
  ['locked', 2, 0, '2 / 2회', false],
]) {
  pageScope.submission = { state, official_attempt_no: used, resubmission: { max_attempts: 2, remaining_attempts: remaining }, actions: [] };
  vm.runInContext('renderSubmissionStatus(submission)', pageScope);
  const details = studentStatus.children.find(child => child.tag === 'dl');
  const rows = Object.fromEntries(details.children.reduce((pairs, child, index, children) => index % 2 === 0 ? [...pairs, [child.textContent, children[index + 1].textContent]] : pairs, []));
  assert.equal(rows['제출 횟수'], expectedSummary);
  assert.equal(rows['남은 제출'], `${remaining}회`);
  assert.equal(rows['교사 추가 허용'], granted ? '1회' : undefined);
}
console.log('PASS submission reopen discovery: action placement, eligible states, unavailable reasons, schedule boundaries, permission and click-time guards.');
