import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import vm from 'node:vm';

const require = createRequire(new URL('../../resources/plate/whole-editor/package.json', import.meta.url));
const postcss = require('postcss');
const source = await readFile(new URL('../../resources/js/submission-evaluation-review-workbench.js', import.meta.url), 'utf8');
const css = postcss.parse(await readFile(new URL('../../resources/css/submission-evaluation-review-workbench.css', import.meta.url), 'utf8'));
const window = {};
vm.runInNewContext(source, { window, document: { querySelector: () => null }, URLSearchParams });
const { preserveScrollPosition } = window.OJAISubmissionEvaluationReviewWorkbench;

function styles(selector, width) {
  const result = {};
  css.walkRules(rule => {
    if (!rule.selectors.includes(selector)) return;
    for (let parent = rule.parent; parent.type !== 'root'; parent = parent.parent) {
      if (parent.type !== 'atrule' || parent.name !== 'media') continue;
      const maxWidth = /max-width:\s*(\d+)px/.exec(parent.params);
      if (!maxWidth) throw new Error('Unexpected media query: ' + parent.params);
      if (width > Number(maxWidth[1])) return;
    }
    rule.nodes.filter(node => node.type === 'decl').forEach(decl => { result[decl.prop] = decl.value; });
  });
  return result;
}

for (const width of [1440, 1024, 800, 760, 390]) {
  const layout = styles('.oj-ai-submission-review__layout', width);
  const inbox = styles('.oj-ai-submission-review__inbox', width);
  const detail = styles('.oj-ai-submission-review__detail', width);
  for (const panel of [inbox, detail]) {
    assert.equal(panel['min-height'], '0');
    assert.equal(panel['overflow-y'], 'auto', 'Both panels must own their vertical scrollbar.');
    assert.equal(panel['overscroll-behavior-y'], 'contain', 'A pane boundary must not scroll its parent.');
    assert.equal(panel['scrollbar-gutter'], 'stable');
  }
  if (width > 760) {
    assert.equal(layout.display, 'grid');
    assert.equal(layout.height, '72dvh');
    assert.equal(layout['max-height'], '56rem');
    assert.equal(layout['grid-template-rows'], 'minmax(0, 1fr)');
    assert.equal(layout.overflow, 'hidden');
  } else {
    assert.equal(layout.display, 'block');
    assert.equal(layout.height, 'auto');
    assert.equal(layout['max-height'], 'none');
    assert.equal(inbox['max-height'], 'min(32dvh, 20rem)');
    assert.equal(detail.height, '72dvh');
  }
}
for (const path of ['src/Platform/Worksheet_Evaluation_Workbench_Page_Handler.php', 'tests/fixtures/submission-evaluation-review-workbench-visual.html']) {
  const markup = await readFile(new URL('../../' + path, import.meta.url), 'utf8');
  for (const pane of ['inbox', 'detail']) {
    assert.match(markup, new RegExp(`<[^>]+class="oj-ai-submission-review__${pane}"[^>]+aria-label="[^"]+"[^>]+tabindex="0"`));
  }
}

const inbox = { scrollTop: 1680, scrollLeft: 0 };
const detail = { scrollTop: 730, scrollLeft: 16, replaceChildren() { this.scrollTop = 0; this.scrollLeft = 0; } };
preserveScrollPosition(inbox, () => { inbox.scrollTop = 0; });
assert.equal(inbox.scrollTop, 1680);
assert.equal(detail.scrollTop, 730, 'Refreshing the student list must not move the detail pane.');
preserveScrollPosition(detail, () => detail.replaceChildren());
assert.equal(detail.scrollTop, 730);
assert.equal(detail.scrollLeft, 16);
assert.equal(inbox.scrollTop, 1680);

// Run the production replacement path, including selection reset and input baseline.
const state = { selectedType: 'evaluation', selectedId: 'A' };
const scope = vm.createContext({
  state, elements: { detail }, preserveScrollPosition,
  detailInputs: () => 'unchanged-inputs',
});
const replaceSource = source.slice(source.indexOf('  function replaceDetail('), source.indexOf("  document.addEventListener('ojai:before-navigation'"));
vm.runInContext("let detailBaseline = null; let detailScrollSelection = '';" + replaceSource, scope);
vm.runInContext('replaceDetail({})', scope);
assert.equal(detail.scrollTop, 0, 'The first selected student starts at the top.');
detail.scrollTop = 890;
vm.runInContext('replaceDetail({})', scope);
assert.equal(detail.scrollTop, 890, 'Polling the same result preserves the reading position.');
state.selectedId = 'B';
vm.runInContext('replaceDetail({})', scope);
assert.equal(detail.scrollTop, 0, 'Another student must not open in the middle of their answer.');
assert.equal(inbox.scrollTop, 1680, 'Selecting a student must preserve the roster position.');
assert.equal(vm.runInContext('detailBaseline', scope), 'unchanged-inputs');

const requests = [];
const loadState = { selectedType: 'evaluation', selectedId: 'A', detail: { evaluation: { id: 'A' } }, items: [], assignments: [], detailRequest: 0, draftPollToken: 0 };
const loader = vm.createContext({
  state: loadState, elements: { detail }, scorePoller: { stop() {}, start() {} },
  currentContext: () => ({ type: 'group', id: 'synthetic', revision: 'r1' }),
  contextQuery: () => 'context_type=group', encodeURIComponent, window: {},
  renderList: () => {}, renderEmptyDetail: () => detail.replaceChildren(),
  renderDetail: () => detail.replaceChildren(), renderCandidateDetail: () => detail.replaceChildren(),
  refreshStudentNote() {}, problemMessage: error => error.message,
  request: url => new Promise(resolve => requests.push({ url, resolve })),
});
vm.runInContext(source.slice(source.indexOf('  async function loadDetail('), source.indexOf('  async function findStudentNoteSummary(')), loader);
detail.scrollTop = 1100;
const refresh = vm.runInContext("loadDetail('A')", loader);
assert.equal(detail.scrollTop, 0, 'Loading placeholder simulates a temporarily shorter panel.');
requests.shift().resolve({ data: { evaluation: { id: 'A' } } });
await refresh;
assert.equal(detail.scrollTop, 1100, 'Manual refresh must restore the position after the loading placeholder.');
const oldRefresh = vm.runInContext("loadDetail('A')", loader);
const oldRequest = requests.shift();
const selection = vm.runInContext("loadDetail('B')", loader);
requests.shift().resolve({ data: { evaluation: { id: 'B' } } });
await selection;
assert.equal(detail.scrollTop, 0);
detail.scrollTop = 260;
oldRequest.resolve({ data: { evaluation: { id: 'A' } } });
await oldRefresh;
assert.equal(detail.scrollTop, 260, 'A stale request must not move another student\'s pane.');
loadState.selectedType = 'candidate'; loadState.selectedId = 'C'; detail.scrollTop = 470;
const candidateRefresh = vm.runInContext("loadCandidateDetail('C')", loader);
requests.shift().resolve({ data: { submission: { id: 'C' } } });
await candidateRefresh;
assert.equal(detail.scrollTop, 470);
assert.match(source, /preserveScrollPosition\(elements\.list\.parentElement, renderListEntries\)/);
console.log('PASS independent scroll: responsive CSS boundaries, keyboard focus, retained list/detail position, selection reset, loading refresh and stale-response isolation. Browser rendering was not exercised.');
