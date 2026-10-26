import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const stored = new Map();
const window = { localStorage: { getItem: key => stored.get(key), setItem: (key, value) => stored.set(key, value) } };
vm.runInNewContext(await readFile('resources/js/learner-labels.js', 'utf8'), { window });
const api = window.OJAILearnerLabels;
const student = { display_name: 'Snapshot', labels: { management_name: 'Management', login: 'student10101', display_name: 'Display' } };
for (const mode of ['management_name', 'login', 'display_name']) assert.equal(api.label(student, mode), student.labels[mode]);
assert.equal(api.label(student, 'unexpected'), 'Display');
assert.equal(api.label({ display_name: 'Snapshot' }, 'login'), 'Snapshot');
assert.equal(api.label({ labels: { management_name: '', display_name: 'Fallback' } }, 'management_name'), 'Fallback');
assert.equal(api.label(null, 'login'), '학생');
assert.ok(api.searchText(student).includes('management student10101 display snapshot'));

function surface(actor = '7') {
  const listeners = {};
  const select = { value: '', addEventListener: (name, fn) => { listeners[name] = fn; } };
  const elements = [];
  const root = { dataset: { actorId: actor }, querySelector: () => select, querySelectorAll: () => elements };
  const view = api.create(root);
  return { view, select, elements, change(mode) { select.value = mode; listeners.change(); } };
}
const page = surface();
const title = { dataset: {}, textContent: '' };
const unsubmitted = { dataset: {}, textContent: '' };
page.elements.push(title, unsubmitted);
page.view.bind(title, student, ' · 09:20');
page.view.bind(unsubmitted, student, ' · 미제출');
assert.equal(title.textContent, 'Display · 09:20');
page.change('management_name');
assert.equal(title.textContent, 'Management · 09:20');
assert.equal(unsubmitted.textContent, 'Management · 미제출');
assert.equal(surface().select.value, 'management_name', 'Both screens and reloads share only the teacher preference.');
assert.equal(surface('8').select.value, 'display_name', 'Another account must not inherit the preference.');
assert.deepEqual([...stored.values()], ['management_name'], 'Never persist student identities.');
page.change('login');
assert.equal(title.textContent, 'student10101 · 09:20');
const late = { dataset: {}, textContent: '' };
page.view.bind(late, student);
assert.equal(late.textContent, 'student10101', 'Newly loaded list/detail entries use the selected mode.');
window.localStorage.getItem = () => { throw new Error('blocked'); };
window.localStorage.setItem = () => { throw new Error('blocked'); };
const blocked = surface();
blocked.change('login');
assert.equal(blocked.view.label(student), 'student10101');

for (const name of ['Assignment_Workbench_Page_Handler', 'Worksheet_Evaluation_Workbench_Page_Handler']) {
  const source = await readFile(`src/Platform/${name}.php`, 'utf8');
  assert.match(source, /data-learner-label-mode/);
  assert.match(source, /data-actor-id/);
}
const manifest = await readFile('src/Platform/Addon_Manifest.php', 'utf8');
assert.ok(manifest.indexOf('resources/js/learner-labels.js') < manifest.indexOf('resources/js/assignment-workbench.js'));
console.log('PASS learner labels: modes, fallbacks, search, reload, teacher scope, privacy, blocked storage, asset registration');
