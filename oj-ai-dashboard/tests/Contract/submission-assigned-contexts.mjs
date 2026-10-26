import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const window = {};
const document = { querySelector: () => null, querySelectorAll: () => [], addEventListener: () => {} };
vm.runInNewContext(await readFile('resources/js/submission-evaluation-review-workbench.js', 'utf8'), { window, document, URLSearchParams });
vm.runInNewContext(await readFile('resources/js/worksheet-workflow.js', 'utf8'), {
  window, document, URL, URLSearchParams, location: { search: '', href: 'https://example.test/assessments' },
});
const { loadAssignedContexts } = window.OJAISubmissionEvaluationReviewWorkbench;
const { allPages } = window.OJAIWorkflow;
const contexts = Array.from({ length: 9 }, (_, i) => ({
  id: `${i === 7 ? 'class' : 'group'}:${i}`, type: i === 7 ? 'class' : 'group', revision: `revision-${i}`,
  selectable: i !== 8, label: `context ${i}`,
}));
const before = JSON.stringify(contexts);
const reads = [];
let active = 0;
let maximum = 0;
const request = async path => {
  reads.push(path);
  active += 1; maximum = Math.max(active, maximum);
  await new Promise(resolve => setTimeout(resolve, 2));
  active -= 1;
  const query = new URL('https://example.test' + path).searchParams;
  const index = Number(query.get('context_id').split(':')[1]);
  assert.equal(query.get('context_revision'), `revision-${index}`);
  assert.equal(query.get('context_type'), contexts[index].type);
  assert.equal(query.get('limit'), '100');
  if (index === 6) throw Object.assign(new Error('denied'), { status: 403 });
  const second = query.has('cursor');
  const items = index === 0 && !second ? [{ id: 'unrelated', worksheet_id: 'other' }]
    : [0, 3, 7].includes(index) ? [{ id: `assignment-${index}`, worksheet_id: 'selected', state: index === 7 ? 'closed' : 'open' }]
      : index === 1 ? [{ id: 'other-assignment', worksheet_id: 'other', state: 'scheduled' }] : [];
  return { data: { items }, meta: { next_cursor: index === 0 && !second ? 'second' : '' } };
};
const matches = await loadAssignedContexts(contexts, 'selected', request, allPages);
assert.deepEqual(Array.from(matches, row => row.context.id), ['group:0', 'group:3', 'class:7']);
assert.equal(matches[0].context, contexts[0], 'Preserve exact context/revision identity for the picker.');
assert.equal(matches[0].assignments.length, 1, 'Only this worksheet belongs in the assignment picker.');
assert.equal(matches[2].assignments[0].state, 'closed', 'Closed distributions remain available for review.');
assert.equal(reads.length, 9, 'All selectable contexts plus the second assignment page.');
assert.ok(maximum <= 4 && maximum > 1, 'Bound concurrent context reads.');
assert.equal(JSON.stringify(contexts), before, 'Do not mutate source contexts.');
assert.ok(!reads.some(path => path.includes('group%3A8')), 'Non-selectable contexts are never queried.');
const all = await loadAssignedContexts(contexts, '', request, allPages);
assert.deepEqual(Array.from(all, row => row.context.id), ['group:0', 'group:1', 'group:3', 'class:7']);
assert.equal(all[0].assignments.length, 2);
assert.equal((await loadAssignedContexts(contexts, 'missing', request, allPages)).length, 0);
assert.equal((await loadAssignedContexts([], '', request, allPages)).length, 0);
for (const status of [401, 409, 500, 503]) {
  await assert.rejects(() => loadAssignedContexts(contexts, '', async () => { throw Object.assign(new Error('unavailable'), { status }); }, allPages), error => error.status === status);
}
await assert.rejects(() => loadAssignedContexts(contexts, '', async () => { throw new TypeError('network failed'); }, allPages), /network/);
await assert.rejects(() => loadAssignedContexts(contexts, '', async () => ({ data: {} }), allPages), /목록/);
await assert.rejects(() => loadAssignedContexts(contexts, '', async () => ({ data: { items: [] }, meta: { next_cursor: 'same' } }), allPages), /목록/);
console.log('Assigned context contracts passed: worksheet scope, pagination, closed/legacy distribution, bounded reads, permissions, fail-closed errors.');
