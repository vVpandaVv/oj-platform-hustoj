import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/assignment-workbench.js', import.meta.url), 'utf8');
const markup = await readFile(new URL('../../src/Platform/Assignment_Workbench_Page_Handler.php', import.meta.url), 'utf8');
assert.match(markup, /data-action="add-targets"/);
assert.match(markup, /<dialog[^>]+data-role="target-dialog"[^>]+aria-labelledby="oj-ai-target-title"/);
assert.ok(markup.indexOf('data-action="add-targets"') < markup.indexOf('data-role="target-list"'));
function node(tag = '', className = '', textContent = '') {
  return {
    tag, className, textContent, dataset: {}, children: [], checked: false, disabled: false, open: false,
    append(...children) { this.children.push(...children); },
    replaceChildren(...children) { this.children = children; },
    showModal() { this.open = true; }, close() { this.open = false; }, focus() {},
  };
}
const nodes = new Map();
const find = key => { if (!nodes.has(key)) nodes.set(key, node()); return nodes.get(key); };
const boxes = () => find('[data-role="target-candidates"]').children.map(label => label.children[0]);
const context = { type: 'group', id: 'group:1', revision: 'context-r1' };
const calls = [];
let behavior;
let reloads = 0;
const expected = { lock_version: 2, target_snapshot_hash: 'a'.repeat(64), roster_revision: 'roster-r2' };
const candidates = { data: { expected, items: [{ learner_user_id: 11, display_name: '<img onerror=alert(1)>' }, { learner_user_id: 12, display_name: 'New learner 12' }] } };
const scope = vm.createContext({
  targetAddition: null, contexts: [context], activeAssignment: { id: 'assignment-test', title: 'Existing assignment' },
  find, all: selector => selector === '[data-field="target-learner"]' ? boxes() : [], create: node,
  text: (key, value) => { find(key).textContent = value; }, selected: () => 0,
  contextScopeQuery: c => new URLSearchParams({ context_type: c.type, context_id: c.id, context_revision: c.revision }).toString(),
  commandToken: (() => { let i = 0; return () => `token-${++i}`; })(),
  request: async (url, options = {}) => { calls.push({ url, ...options }); return behavior(url, options); },
  loadAssignments: async () => { reloads += 1; }, loadDetail: async () => { reloads += 1; },
  assignmentById: () => ({ id: 'assignment-test' }),
});
vm.runInContext(source.slice(source.indexOf('  const targetSelectionChanged ='), source.indexOf('  const submissionContextQuery =')), scope);
const run = code => vm.runInContext(code, scope);
const settle = () => new Promise(resolve => setImmediate(resolve));

behavior = async () => candidates;
run('openTargetAddition(null)'); await settle();
assert.equal(find('[data-role="target-dialog"]').open, true);
assert.equal(boxes().length, 2);
assert.equal(boxes()[0].checked, false, 'No learner is selected without teacher action.');
assert.equal(find('[data-role="target-candidates"]').children[0].children[1].textContent, '<img onerror=alert(1)>', 'Names use textContent, never HTML.');
assert.equal(find('[data-action="confirm-targets"]').disabled, true);
assert.equal(calls.filter(call => call.method === 'POST').length, 0, 'Preview is read-only.');
boxes()[1].checked = true; run('targetSelectionChanged()');
assert.equal(find('[data-role="target-selected"]').textContent, '1명 선택');
assert.equal(find('[data-field="target-all"]').indeterminate, true);
assert.equal(find('[data-action="confirm-targets"]').disabled, false);

let release;
behavior = () => new Promise(resolve => { release = resolve; });
const first = run('confirmTargetAddition()');
assert.equal(find('[data-action="confirm-targets"]').disabled, true);
assert.equal(boxes()[1].disabled, true);
await run('confirmTargetAddition()');
run('closeTargetAddition()');
assert.equal(find('[data-role="target-dialog"]').open, true, 'Cannot dismiss an in-flight write.');
const post = calls.at(-1);
assert.deepEqual(JSON.parse(post.body), { expected, learner_user_ids: [12] });
assert.match(post.url, /commands\/add-targets\?context_type=group&context_id=group%3A1/);
assert.equal(calls.filter(call => call.method === 'POST').length, 1, 'Double click does not send a second command.');
release({ data: { added_count: 1 } }); await first;
assert.equal(find('[data-role="target-dialog"]').open, false);
assert.equal(reloads, 2, 'Both the list and detail are refreshed after commit.');
assert.equal(find('[data-role="notice"]').textContent, '1명을 추가했습니다.');

behavior = async () => candidates;
run('openTargetAddition(null)'); await settle();
boxes()[0].checked = true;
behavior = async () => { throw new Error('Network failure'); };
await run('confirmTargetAddition()');
const failed = calls.at(-1);
assert.equal(find('[data-action="confirm-targets"]').disabled, false);
await run('confirmTargetAddition()');
assert.equal(calls.at(-1).headers['Idempotency-Key'], failed.headers['Idempotency-Key'], 'Uncertain retries reuse the exact key.');
assert.equal(calls.at(-1).body, failed.body);
behavior = async () => { throw Object.assign(new Error('Roster changed'), { status: 409 }); };
await run('confirmTargetAddition()');
assert.equal(find('[data-action="confirm-targets"]').disabled, true, 'Conflict requires a new roster preview.');
assert.equal(find('[data-action="refresh-targets"]').disabled, false);
behavior = async () => ({ data: { expected, items: [] } });
await run('refreshTargetCandidates()');
assert.equal(find('[data-role="target-notice"]').textContent, '추가할 학생이 없습니다.');
assert.equal(find('[data-field="target-all"]').disabled, true);
run('closeTargetAddition()');

behavior = () => new Promise(resolve => { release = resolve; });
run('openTargetAddition(null)');
scope.contexts = [{ ...context, id: 'group:2' }];
release(candidates); await settle();
assert.equal(boxes().length, 0, 'An old-context response cannot expose names in a new context.');
assert.equal(find('[data-action="confirm-targets"]').disabled, true);
run('closeTargetAddition()');
scope.contexts = [context];
behavior = () => new Promise(resolve => { release = resolve; });
run('openTargetAddition(null)');
run('closeTargetAddition()');
assert.equal(find('[data-role="target-dialog"]').open, false, 'A pending read can be dismissed.');
release(candidates); await settle();
assert.equal(boxes().length, 0, 'A dismissed preview cannot populate a later dialog.');
console.log('PASS late-joiner UI: visible entry, native dialog, selection, escaped names, read-only preview, duplicate-click guard, exact retry, conflict recovery and refresh.');
