import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const window = {};
const document = { querySelector: () => null, querySelectorAll: () => [], addEventListener: () => {} };
vm.runInNewContext(await readFile('resources/js/submission-evaluation-review-workbench.js', 'utf8'), { window, document, URLSearchParams });
vm.runInNewContext(await readFile('resources/js/worksheet-workflow.js', 'utf8'), {
  window, document, URL, URLSearchParams, location: { search: '', href: 'https://example.test/assessments' },
});
const { scoreDownloadRow, scoreDownloadCsv, scoreDownloadFilename, readScoreDownload } = window.OJAISubmissionEvaluationReviewWorkbench;
const { allPages } = window.OJAIWorkflow;
const context = { type: 'group', id: 'group:1', revision: 'members-1', label: '1학년 1반' };
const assignment = { id: 'assignment-1', title: '디지털 사회' };
const label = learner => learner.labels.management_name;
const options = { context, assignment, label };
const clone = value => JSON.parse(JSON.stringify(value));
function fixture(n = 1) {
  const learner = { user_id: n, display_name: `학생 ${n}`, labels: { management_name: `학생 ${String(n).padStart(3, '0')}`, login: `001${n}`, display_name: `표시 ${n}` } };
  const roster = { id: `target-${n}`, learner, assigned: true, current_member: true, status: 'submitted', submission: { id: `submission-${n}`, official_revision_id: `revision-${n}` } };
  const evaluation = { id: `evaluation-${n}`, state: 'review_required', assignment, context, learner,
    requested_at_utc: '2026-10-14 01:00:00',
    submission: { id: `submission-${n}`, state: 'submitted', source_revision_id: `revision-${n}`, is_source_current: true, submitted_at_utc: '2026-10-13 12:00:00' },
    candidate: { id: `result-${n}`, score: '14', maximum_score: '20', abstained: false, safety_flagged: false },
    review: { official_scope: 'none', official_id: null }, priority: { review_needed_count: 0 } };
  return { item: { ...clone(evaluation), roster }, detail: { evaluation, selected_result: { id: `result-${n}` }, reviews: [], source: { private_answer: 'NEVER_EXPORT_ANSWER' } } };
}
function makeOfficial(f, score = '18') {
  f.detail.evaluation.state = 'reviewed';
  f.detail.evaluation.review = { official_scope: 'this_evaluation', official_id: 'official-1' };
  f.detail.reviews = [{ id: 'official-1', is_official: true, decision: 'adjusted', official_score: score, result_id: f.detail.selected_result.id, review_no: 1 }];
  return f;
}
{
  const f = fixture();
  const before = JSON.stringify(f);
  let row = scoreDownloadRow(f.item, f.detail, options);
  assert.equal(row.length, 12);
  assert.equal(row[2], '학생 001');
  assert.equal(row[4], '0011');
  assert.deepEqual(Array.from(row.slice(6, 10)), ['14.0000', '', '20.0000', '검토 대기']);
  assert.equal(scoreDownloadRow(f.item, f.detail, { ...options, onlyOfficial: true }), null);
  assert.equal(JSON.stringify(f), before);
  makeOfficial(f);
  row = scoreDownloadRow(f.item, f.detail, { ...options, onlyOfficial: true });
  assert.deepEqual(Array.from(row.slice(6, 10)), ['14.0000', '18.0000', '20.0000', '확정']);
  f.detail.evaluation.candidate.score = '0';
  f.detail.reviews[0].official_score = '0';
  assert.deepEqual(Array.from(scoreDownloadRow(f.item, f.detail, options).slice(6, 8)), ['0.0000', '0.0000']);
  f.detail.evaluation.review.official_scope = 'other_evaluation';
  assert.equal(scoreDownloadRow(f.item, f.detail, { ...options, onlyOfficial: true }), null);
}
for (const change of [
  f => { f.detail.evaluation.candidate.abstained = true; },
  f => { f.detail.evaluation.candidate.safety_flagged = true; },
  f => { f.detail.selected_result.abstained = true; },
  f => { f.detail.selected_result.safety_flagged = true; },
  f => { f.detail.evaluation.candidate.score = null; },
  f => { f.detail.reviews = [{ result_id: 'result-1', decision: 'rejected', review_no: 1 }]; },
]) {
  const f = fixture(); change(f);
  assert.equal(scoreDownloadRow(f.item, f.detail, options), null, 'Unsafe/unscored AI result is excluded.');
}
for (const flag of ['abstained', 'safety_flagged']) {
  const f = makeOfficial(fixture()); f.detail.evaluation.candidate[flag] = true;
  assert.deepEqual(Array.from(scoreDownloadRow(f.item, f.detail, options).slice(6, 8)), ['', '18.0000']);
}
{
  const f = makeOfficial(fixture(), '0');
  f.detail.selected_result.provider_status = 'not_requested';
  f.detail.selected_result.abstained = true;
  f.detail.evaluation.candidate.score = null;
  f.detail.evaluation.candidate.abstained = true;
  assert.deepEqual(Array.from(scoreDownloadRow(f.item, f.detail, { ...options, onlyOfficial: true }).slice(6, 10)), ['', '0.0000', '20.0000', '확정'], 'Offline teacher score exports without fabricating an AI score.');
  const official = { ...f.detail.evaluation, latest_run: { operation: 'manual_prepare' } };
  const newerAi = { ...clone(official), id: 'newer-ai', requested_at_utc: '2026-10-23 01:00:00', review: { official_scope: 'other_evaluation' } };
  const rows = window.OJAISubmissionEvaluationReviewWorkbench.buildRosterItems([f.item.roster], [newerAi, official], [], assignment);
  assert.equal(rows[0].id, official.id, 'The inbox must retain the approved teacher score while a separate AI evaluation exists.');
}
for (const state of ['queued', 'running', 'failed', 'cancelled']) {
  const f = fixture(); f.detail.evaluation.state = state;
  assert.equal(scoreDownloadRow(f.item, f.detail, options), null);
}
for (const change of [
  f => { f.detail.evaluation.submission.is_source_current = false; },
  f => { f.detail.evaluation.submission.state = 'reopened'; },
  f => { f.detail.evaluation.submission.source_revision_id = 'old'; },
]) {
  const f = fixture(); change(f); assert.equal(scoreDownloadRow(f.item, f.detail, options), null);
}
for (const change of [
  f => { f.detail.evaluation.context = { ...context, id: 'group:2' }; },
  f => { f.detail.evaluation.context = { ...context, revision: 'new' }; },
  f => { f.detail.evaluation.context = { ...context, type: 'class' }; },
  f => { f.detail.evaluation.assignment = { id: 'other' }; },
  f => { f.detail.evaluation.learner.user_id = 999; },
  f => { f.detail.evaluation.submission.id = 'other'; },
  f => { f.detail.evaluation.id = 'other'; },
  f => { f.detail.selected_result.id = 'other'; },
]) {
  const f = fixture(); change(f); assert.throws(() => scoreDownloadRow(f.item, f.detail, options));
}
for (const score of ['-1', '21', 'NaN', '1e2', '', '=SUM(1)', '0.00001']) {
  const f = fixture(); f.detail.evaluation.candidate.score = score;
  assert.throws(() => scoreDownloadRow(f.item, f.detail, options));
}
{
  const row = scoreDownloadRow(fixture().item, fixture().detail, options);
  for (const prefix of ['=1+1', '+1', '-1', '@SUM(1)', '\t=1', '\n=1', '  =1', '\u200b=1', '＝1', '＋1', '－1', '＠SUM(1)']) {
    const hostile = [...row]; hostile[2] = prefix;
    assert.ok(scoreDownloadCsv([hostile]).includes(`"'${prefix}"`), 'Formula prefix must be escaped: ' + prefix);
  }
  row[2] = '학생, "하나"\n둘';
  const csv = scoreDownloadCsv([row]);
  assert.ok(csv.includes('"학생, ""하나""\n둘"'));
  assert.notEqual(csv.charCodeAt(0), 0xfeff);
  assert.ok(csv.endsWith('\r\n'));
  assert.ok(!csv.includes('NEVER_EXPORT_ANSWER'));
  assert.throws(() => scoreDownloadCsv([]));
  assert.throws(() => scoreDownloadCsv([['bad shape']]));
  const filename = scoreDownloadFilename({ label: '../반:1' }, { title: '평가/*?' }, new Date('2026-10-14T01:02:03Z'));
  assert.match(filename, /^점수_.*_20261014T010203\.csv$/);
  assert.ok(!/[<>:"/\\|?*]/.test(filename));
}
function server(mode = 'normal') {
  const fixtures = Array.from({ length: 105 }, (_, i) => fixture(i + 1));
  const roster = fixtures.map(f => f.item.roster);
  fixtures[0].detail.evaluation.state = 'failed';
  fixtures[1].detail.evaluation.submission.is_source_current = false;
  roster[2].status = 'reopened';
  roster[3].assigned = false;
  roster[4].submission.official_revision_id = null;
  makeOfficial(fixtures[5], '0');
  const evaluations = fixtures.map(f => f.detail.evaluation);
  const older = clone(evaluations[0]); older.id = 'older-success'; older.state = 'review_required'; older.requested_at_utc = '2026-10-13 00:00:00';
  evaluations.unshift(older);
  const other = clone(evaluations[7]); other.assignment.id = 'other'; evaluations.push(other);
  const calls = []; let active = 0; let peak = 0; let current = true;
  const request = async path => {
    calls.push(path);
    const url = new URL(path, 'https://example.test');
    assert.equal(url.searchParams.get('context_type'), context.type);
    assert.equal(url.searchParams.get('context_id'), context.id);
    assert.equal(url.searchParams.get('context_revision'), context.revision);
    if (url.pathname === '/submission-evaluation-roster') return { data: { items: roster } };
    if (url.pathname === '/submission-evaluations') {
      assert.equal(url.searchParams.get('assignment_id'), assignment.id);
      const start = Number(url.searchParams.get('cursor') || 0);
      if (mode === 'bad-page' && start) return { data: {} };
      return { data: { items: evaluations.slice(start, start + 100) }, meta: { next_cursor: start ? '' : '100' } };
    }
    active += 1; peak = Math.max(peak, active);
    await new Promise(resolve => setTimeout(resolve, 1));
    active -= 1;
    if (mode === 'denied') throw Object.assign(new Error('denied'), { status: 403 });
    if (mode === 'cancel') current = false;
    const f = fixtures.find(f => path.includes('/' + f.detail.evaluation.id + '?'));
    assert.ok(f, 'Only current results may be read.');
    return { data: clone(f.detail) };
  };
  return { request, calls, peak: () => peak, isCurrent: () => current };
}
{
  const s = server();
  const progress = [];
  const result = await readScoreDownload({ ...options, ...s, allPages, onProgress: (done, total) => progress.push([done, total]) });
  assert.equal(result.rows.length, 100);
  assert.equal(result.excluded, 5);
  assert.equal(s.peak(), 4);
  assert.equal(progress.length, 101);
  assert.ok(s.calls.some(path => path.includes('cursor=100')));
  assert.equal(new Set(result.rows.map(row => row[4])).size, 100);
  const officialServer = server();
  const official = await readScoreDownload({ ...options, ...officialServer, allPages, onlyOfficial: true });
  assert.equal(official.rows.length, 1);
  assert.equal(official.rows[0][7], '0.0000');
}
for (const mode of ['bad-page', 'denied', 'cancel']) {
  const s = server(mode);
  await assert.rejects(() => readScoreDownload({ ...options, ...s, allPages }));
  if (mode !== 'bad-page') assert.ok(s.calls.filter(path => path.startsWith('/submission-evaluations/')).length <= 4);
}
console.log('PASS score download: scope, latest/current result, official/AI separation, zero, exclusions, CSV safety, 105-student pagination, bounded reads, denial and cancellation.');
