import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {resolveLaunch} from '../resources/js/curriculum.js';
import {LESSONS, scenario} from '../resources/js/scenarios.js';
import {validateProject} from '../resources/js/model.js';

const source = fs.readFileSync(new URL('../resources/js/app.js', import.meta.url), 'utf8');
function extract(pattern) { const match = source.match(pattern); assert(match, String(pattern)); return match[0]; }
function functionSource(name) { return extract(new RegExp(`^        (?:async )?function ${name}\\([^]*?^        }`, 'm')); }

// Run the actual goal/load handlers with a small DOM double, without a browser or worker.
class Element {
    children = [];
    value = '';
    listeners = {};
    set textContent(value) { this.text = value; this.children = []; }
    get textContent() { return (this.text || '') + this.children.map(child => child.textContent).join(''); }
    append(...children) { this.children.push(...children); }
    addEventListener(name, handler) { this.listeners[name] = handler; }
}
const elements = new Map(['goal', 'scenario', 'file', 'backups'].map(name => [`[data-${name}]`, new Element()]));
const goal = elements.get('[data-goal]'), select = elements.get('[data-scenario]');
const bgp = LESSONS.find(lesson => lesson.id === 'bgp'), ipv6 = LESSONS.find(lesson => lesson.id === 'ipv6');
const l23 = resolveLaunch('?exercise=l23').exercise;
let savedProject;
const context = vm.createContext({
    linkedExercise: l23, currentLesson: '', loadingServer: false, snapshot: null,
    $: selector => { assert(elements.has(selector), selector); return elements.get(selector); },
    root: new Element(), scenario,
    document: {createElement: () => new Element(), createTextNode: text => Object.assign(new Element(), {textContent: text})},
    freshMeta: () => ({id: 0, revision: 0, clientId: 'fresh'}),
    begin: async project => { context.snapshot = {project: validateProject(structuredClone(project))}; },
    api: async () => ({project: savedProject, id: 21, revision: 1, clientId: 'saved'}),
    controls: () => {}, send: () => {}, status: () => {}, renderTerminal: () => {},
});
for (const name of ['showGoal', 'openLesson', 'loadServer']) vm.runInContext(functionSource(name), context);
vm.runInContext(extract(/^        \$\('\[data-file\]'\)\.addEventListener\('change',.*$/m), context);
vm.runInContext(extract(/^        root\.addEventListener\('click', event => \{[^]*?^        \}\);/m), context);

for (const exercise of [l23, resolveLaunch('?exercise=l21').exercise, null]) {
    context.linkedExercise = exercise;
    for (const current of ['', 'bgp', 'ipv6']) for (const preset of [false, true]) {
        context.currentLesson = current;
        context.showGoal('detail', preset);
        const linked = preset && exercise && current === exercise.scenario;
        assert.equal(goal.textContent, linked ? `${exercise.id.toUpperCase()} · ${exercise.title}\n${exercise.goal}기본 예제 참고detail` : 'detail');
        assert.equal(goal.children.length, linked ? 1 : 0, 'generic guidance removes prior reference details');
    }
}
context.linkedExercise = l23;
await context.openLesson(bgp, false);
assert(goal.textContent.startsWith('L23 · '), 'the initial linked example keeps its exercise goal');
select.value = 'ipv6';
assert.equal(context.currentLesson, 'bgp', 'selection is only the next example to open');
assert(goal.textContent.startsWith('L23 · '));
await context.openLesson(ipv6, true);
assert.equal(goal.textContent, ipv6.goal, 'a different example shows only its own preset goal');
assert.equal(context.currentLesson, 'ipv6');
assert.equal(goal.children.length, 0);
await context.openLesson(bgp, true);
assert(goal.textContent.startsWith('L23 · '), 'explicitly reopening the matching example restores linked guidance');

// Even a matching saved topology or title cannot establish curriculum provenance.
for (const id of ['ipv6', 'bgp']) {
    await context.openLesson(bgp, true);
    savedProject = scenario(id);
    savedProject.engine = '1.4.0-rc.3';
    savedProject.title = 'L23 BGP';
    await context.loadServer(21);
    assert.equal(goal.textContent, '저장한 네트워크 설정을 불러왔습니다. 실행 상태는 새로 시작합니다.');
    assert.deepEqual(context.snapshot.project, validateProject(savedProject));
    assert.equal(context.currentLesson, '');
    assert.equal(select.value, 'bgp', 'restoring a project does not infer the next preset');
}
await context.openLesson(bgp, true);
const imported = scenario('ipv6'), fileInput = {files: [{size: 1024, text: async () => JSON.stringify(imported)}], value: 'network.json'};
imported.engine = '1.4.0-rc.3';
await elements.get('[data-file]').listeners.change({target: fileInput});
assert.equal(goal.textContent, '파일에서 가져온 네트워크입니다. 서버 저장을 누르면 내 프로젝트로 보관합니다.');
assert.deepEqual(context.snapshot.project, validateProject(imported));
assert.equal(context.currentLesson, '');
assert.equal(fileInput.value, '');
await context.openLesson(bgp, true);
elements.get('[data-backups]')._records = [{project: imported, meta: {id: 21}}];
context.root.listeners.click({target: {closest: selector => selector === '[data-local-index]' ? {dataset: {localIndex: '0'}} : null}});
await new Promise(setImmediate);
assert.equal(goal.textContent, '선택한 로컬 복구 사본을 열었습니다. 실행 상태는 새로 시작합니다.');
assert.deepEqual(context.snapshot.project, validateProject(imported));
assert.equal(context.currentLesson, '');
assert.equal(goal.children.length, 0);

assert.match(source, /<label>다음에 열 예제 <select data-scenario>/);
assert.doesNotMatch(source, /\$\('\[data-scenario\]'\)\.addEventListener/);
assert.match(source, /await begin\(restored\.project, restored\.meta, restored\.dirty\); currentLesson = ''; \$\('\[data-goal\]'\)\.textContent = '이 브라우저의 마지막 초안을 복원했습니다\./);
assert.match(source, /backup = backup \|\| !!linkedLesson;/, 'linked tabs keep the main draft protected');
assert.match(source, /if \(snapshot && dirty\) await saveLocal\(true\);/, 'switching projects retains dirty backups');
assert.match(source, /context: localContext/, 'backup records keep the launch context');
assert.match(source, /\.filter\(r => \(r.context \|\| ''\) === localContext\)/, 'backup lists stay context-scoped');
assert.match(source, /if \(inLocalContext\(cursor.key\)\) cursor.delete\(\)/, 'deletion stays context-scoped');
const localScope = extract(/^        const localContext = .*\r?\n        const inLocalContext = .*$/m);
for (const exercise of [l23, resolveLaunch('?exercise=l21').exercise, null]) {
    const scope = vm.runInNewContext(`${localScope}\n({localContext, backupPrefix, inLocalContext})`, {linkedExercise: exercise});
    assert.equal(scope.localContext, exercise?.id || '');
    for (const key of ['current', 'backup:test', 'exercise:l21:backup:test', 'exercise:l23:backup:test']) {
        assert.equal(scope.inLocalContext(key), exercise ? key.startsWith(`exercise:${exercise.id}:backup:`) : key === 'current' || key === 'backup:test', key);
    }
}
console.log('Goal checks passed: linked/mismatched presets, server/file/local restore, no provenance inference, next-example label, draft isolation.');
