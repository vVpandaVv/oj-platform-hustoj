import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(process.argv[2] || new URL('../../resources/js/worksheet-evaluation-workbench.js', import.meta.url), 'utf8');
const worksheetId = '01K00000000000000000000001';
const revisionId = '01K00000000000000000000002';
const settle = () => new Promise(resolve => setImmediate(resolve));

function fixture(initiallyHidden, holdList = false) {
  let hidden = initiallyHidden;
  let modalCalls = 0;
  let finishList;
  const requests = [];
  class Element extends EventTarget {
    constructor() { super(); this.dataset = {}; this.children = []; this.value = ''; this.checked = false; this.open = false; this.hidden = false; }
    appendChild(child) { this.children.push(child); child.parentElement = this; return child; }
    replaceChildren(...children) { this.children = []; children.forEach(child => this.appendChild(child)); }
    setAttribute(name, value) { if (name === 'open') this.open = true; this[name] = value; }
    removeAttribute(name) { if (name === 'open') this.open = false; else delete this[name]; }
    querySelector() { return new Element(); }
    after() {}
    focus() {}
    showModal() { this.open = true; modalCalls += 1; }
    close() { this.open = false; this.dispatchEvent(new Event('close')); }
  }
  const root = new Element();
  root.dataset = { restRoot: '/fixture', consentRecorded: '0', canReview: '1' };
  const nodes = new Map();
  root.closest = selector => selector === '[hidden]' && hidden ? { hidden: true } : null;
  root.querySelector = selector => {
    if (!nodes.has(selector)) { const node = new Element(); node.parentElement = root; nodes.set(selector, node); }
    return nodes.get(selector);
  };
  const document = new EventTarget();
  document.querySelector = selector => selector === '#oj-ai-worksheet-evaluation-app' ? root : null;
  document.createElement = () => new Element();
  document.createElementNS = () => new Element();
  const response = data => ({ ok: true, json: async () => ({ data, meta: {} }) });
  const window = {
    location: { search: '?worksheet_id=' + worksheetId },
    fetch: async (url, options) => {
      requests.push({ url, method: options.method });
      if (url.includes('/worksheet-evaluations?')) {
        if (holdList) return new Promise(resolve => { finishList = () => resolve(response({ items: [] })); });
        return response({ items: [] });
      }
      if (url.includes('/settings/external-ai-consent')) return response({ external_ai_consent: { consent: { state: 'missing' } } });
      if (url.includes('/worksheet-revisions/')) return response({ revision: { id: revisionId, document_hash: 'a'.repeat(64), revision_no: 1 } });
      if (url.includes('/worksheets/')) return response({ worksheet: { id: worksheetId, title: 'Synthetic worksheet', current_revision_id: revisionId } });
      throw new Error('Unexpected request: ' + url);
    },
  };
  vm.runInNewContext(source, { window, document, URLSearchParams, Intl, console }, { timeout: 2000 });
  return {
    requests,
    dialog: nodes.get('[data-role="new-evaluation-dialog"]'),
    notice: nodes.get('[data-role="notice"]'),
    modalCalls: () => modalCalls,
    finishList: () => finishList(),
    setHidden(value) {
      hidden = value;
      const event = new Event('ojai:assessment-tab-change');
      event.detail = { tab: value ? 'submission' : 'worksheet' };
      document.dispatchEvent(event);
    },
  };
}

const hidden = fixture(true);
await settle();
assert.equal(hidden.requests.length, 0, 'A hidden worksheet review must not load in the student grading stage.');
assert.equal(hidden.modalCalls(), 0, 'A hidden worksheet dialog must never lock the entire page.');
hidden.setHidden(false);
await settle();
assert.equal(hidden.modalCalls(), 1, 'The visible worksheet tab must still open its requested empty review.');
assert.equal(hidden.dialog.open, true);
assert.equal(hidden.notice.textContent, '', 'Visible review must load without a caught rendering error.');
hidden.setHidden(true);
assert.equal(hidden.dialog.open, false, 'Switching away must release modal input blocking.');
hidden.setHidden(false);
await settle();
assert.equal(hidden.modalCalls(), 1, 'Returning to the tab must not repeatedly reopen the initial dialog.');
assert.ok(hidden.requests.every(request => request.method === 'GET'), 'Visibility must never start an AI job.');

const raced = fixture(false, true);
raced.setHidden(true);
raced.finishList();
await settle();
assert.equal(raced.modalCalls(), 0, 'A late empty-list response after leaving the tab must not reopen a hidden modal.');
assert.equal(raced.dialog.open, false);
const reviewSource = readFileSync(new URL('../../resources/js/submission-evaluation-review-workbench.js', import.meta.url), 'utf8');
const inactivePanel = { dataset: { assessmentPanel: 'worksheet' }, hidden: false };
const openDialog = { open: true, close() { assert.equal(inactivePanel.hidden, false, 'Close the modal before hiding its parent.'); this.open = false; } };
inactivePanel.querySelectorAll = () => [openDialog];
const activePanel = { dataset: { assessmentPanel: 'submission' }, hidden: false, querySelectorAll: () => [] };
const tabs = ['submission', 'worksheet'].map(name => ({ dataset: { assessmentTab: name }, setAttribute() {} }));
const shell = { dataset: { defaultTab: 'submission' }, querySelectorAll: selector => selector === '[data-assessment-tab]' ? tabs : [activePanel, inactivePanel], addEventListener() {} };
let activated = '';
vm.runInNewContext(reviewSource, {
  window: {}, URLSearchParams, Intl, CustomEvent,
  document: {
    querySelector: selector => selector === '[data-oj-ai-assessment-shell]' ? shell : null,
    dispatchEvent(event) { activated = event.detail.tab; },
  },
}, { timeout: 2000 });
assert.equal(openDialog.open, false);
assert.equal(inactivePanel.hidden, true);
assert.equal(activated, 'submission');
console.log('PASS worksheet review visibility: hidden startup, visible activation, modal close, one-shot opening, late-response race, read-only requests');
