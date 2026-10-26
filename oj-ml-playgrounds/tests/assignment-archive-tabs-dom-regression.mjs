import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const frontend = process.env.OJ_ML_UPSTREAM_FRONTEND;
assert(frontend, 'Set OJ_ML_UPSTREAM_FRONTEND to the local test runtime.');
const { JSDOM } = createRequire(path.join(frontend, 'package.json'))('jsdom');
const dom = new JSDOM('<!doctype html><main class="oj-ml-app"></main>', {
    url: 'https://fixture.example/assignments', runScripts: 'outside-only',
});
const { window } = dom;
window.TextEncoder = TextEncoder;
window.HTMLElement.prototype.scrollIntoView = function () {};
window.fetch = () => assert.fail('No real requests in DOM tests.');
const confirmations = [];
window.OJP = { confirm: async value => { confirmations.push(value); return true; } };
const root = window.document.querySelector('main');
Object.assign(root.dataset, { consoleResultsUrl: '/results?assignment_id={assignment_id}', platformLocalNav: '1' });
const source = readFileSync(new URL('../resources/js/addon.js', import.meta.url), 'utf8');
const end = source.lastIndexOf('}());');
window.eval(source.slice(0, end) + 'window.renderArchiveTest = renderConsoleAssignments;\n' + source.slice(end));
const base = { contextType: 'group', contextId: 'g1', taskType: 'classification', revision: 1, maxAttempts: 3 };
let records = Array.from({ length: 102 }, (_, index) => ({ ...base, id: 'live-' + (index + 1), title: '일반 과제 ' + (index + 1), status: 'published' }));
records.push({ ...base, id: 'archive-1', title: '오래된 보관 과제', status: 'archived' });
const calls = [];
let hold = null, failView = null;
const plain = value => JSON.parse(JSON.stringify(value));
const api = {
    async get(route) {
        calls.push({ method: 'GET', route });
        if (route.startsWith('console/contexts?')) return { items: [{ type: 'group', id: 'g1', label: '실습 그룹', lifecycleState: 'active' }], nextCursor: '' };
        assert(route.startsWith('console/assignments?'));
        const query = new URL(route, 'https://fixture.example/').searchParams;
        const view = query.get('status'), page = Number(query.get('page')), perPage = Number(query.get('per_page'));
        assert(['unarchived', 'archived'].includes(view), 'Both views must use a server-side filter.');
        if (view === failView) throw new Error('목록 연결 실패');
        const filtered = records.filter(item => view === 'archived' ? item.status === 'archived' : item.status !== 'archived');
        const response = { items: plain(filtered.slice((page - 1) * perPage, page * perPage)), total: filtered.length, page, per_page: perPage };
        if (hold && hold.view === view) return new Promise((resolve, reject) => { hold.resolve = () => resolve(response); hold.reject = reject; });
        return response;
    },
    async patch(route, payload) {
        calls.push({ method: 'PATCH', route, payload: plain(payload) });
        const record = records.find(item => route.endsWith('/' + item.id));
        assert(record); assert.equal(record.revision, payload.expectedRevision);
        record.status = payload.status; record.revision++;
        return { assignment: plain(record) };
    },
    async post(route, payload) {
        calls.push({ method: 'POST', route, payload: plain(payload) });
        assert(route.endsWith('/duplicate'));
        const record = records.find(item => route.endsWith('/' + item.id + '/duplicate'));
        records.unshift({ ...record, id: 'copy-1', title: '복제된 초안', status: 'draft', revision: 1 });
        return {};
    },
};
const settle = async () => { for (let i = 0; i < 4; i++) await new Promise(resolve => setImmediate(resolve)); };
const tab = view => root.querySelector('[data-assignment-view="' + view + '"]');
const list = () => root.querySelector('[data-oj-ml-assignment-list]');
async function click(selector) { const control = root.querySelector(selector); assert(control, selector); control.click(); await settle(); }
function selected(view) {
    assert.equal(tab(view).getAttribute('aria-selected'), 'true');
    assert.equal(tab(view).tabIndex, 0);
    assert.equal(list().getAttribute('aria-labelledby'), tab(view).id);
}
const writes = () => calls.filter(call => call.method !== 'GET');
try {
    await window.renderArchiveTest(root, api);
    selected('unarchived');
    assert.equal(writes().length, 0);
    assert.equal(list().querySelectorAll('tbody > tr').length, 100);
    assert.doesNotMatch(list().textContent, /오래된 보관/);
    assert.match(list().textContent, /102개/);
    root.querySelector('[name="title"]').value = '아직 저장하지 않은 제목';

    await click('[data-assignment-page="next"]');
    assert.equal(list().querySelectorAll('tbody > tr').length, 2);
    await click('[data-archive-id="live-101"]');
    assert.equal(list().querySelectorAll('tbody > tr').length, 1);
    await click('[data-archive-id="live-102"]');
    assert.equal(list().querySelectorAll('tbody > tr').length, 100, 'After archiving the last row, clamp to the remaining last page.');
    assert.doesNotMatch(list().textContent, /일반 과제 101|일반 과제 102/);
    assert.equal(root.querySelector('[name="title"]').value, '아직 저장하지 않은 제목');

    const beforeTabs = writes().length;
    await click('[data-assignment-view="archived"]');
    selected('archived');
    assert.equal(list().querySelectorAll('tbody > tr').length, 3);
    assert.match(list().textContent, /오래된 보관/);
    assert.equal(list().querySelector('[data-edit-id]'), null);
    assert.equal(list().querySelector('[data-publish-id]'), null);
    assert.equal(list().querySelector('[data-archive-id]'), null);
    assert(list().querySelector('[data-transition-status="closed"]'));
    assert.equal(writes().length, beforeTabs, 'Changing tabs is read-only.');
    await click('[data-transition-id="archive-1"]');
    assert.doesNotMatch(list().textContent, /오래된 보관/);
    assert.match(root.querySelector('[data-oj-ml-status]').textContent, /과제 탭/);
    await click('[data-duplicate-id="live-101"]');
    selected('unarchived');
    assert.match(list().textContent, /복제된 초안/);

    const beforeRace = writes().length;
    hold = { view: 'archived' };
    tab('archived').click(); await settle();
    assert.equal(list().getAttribute('aria-busy'), 'true');
    assert.doesNotMatch(list().textContent, /복제된 초안/, 'Old tab rows must not remain under the new label during loading.');
    tab('unarchived').click(); await settle();
    assert.match(list().textContent, /복제된 초안/);
    hold.resolve(); hold = null; await settle();
    selected('unarchived');
    assert.match(list().textContent, /복제된 초안/, 'Late archived responses cannot overwrite the selected view.');
    assert.equal(list().getAttribute('aria-busy'), 'false');

    hold = { view: 'archived' };
    tab('archived').click(); await settle();
    tab('unarchived').click(); await settle();
    hold.reject(new Error('오래된 요청 오류')); hold = null; await settle();
    assert.match(list().textContent, /복제된 초안/);
    assert.equal(root.querySelector('[data-oj-ml-status]').dataset.kind, 'success', 'A stale failed response must not report an error on the current tab.');

    failView = 'archived';
    await click('[data-assignment-view="archived"]');
    selected('archived');
    assert.match(list().textContent, /다시 눌러/);
    assert.equal(list().getAttribute('aria-busy'), 'false');
    failView = null;
    await click('[data-assignment-view="archived"]');
    assert.equal(list().querySelectorAll('tbody > tr').length, 2, 'The selected tab can be clicked again to retry.');
    tab('archived').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Home', bubbles: true })); await settle();
    selected('unarchived'); assert.equal(window.document.activeElement, tab('unarchived'));
    tab('unarchived').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true })); await settle();
    selected('archived'); assert.equal(window.document.activeElement, tab('archived'));
    assert.equal(writes().length, beforeRace);
    assert.equal(root.querySelector('[name="title"]').value, '아직 저장하지 않은 제목', 'Tab changes preserve the unfinished form.');

    records = records.filter(item => item.status !== 'archived');
    await click('[data-assignment-view="archived"]');
    assert.match(list().textContent, /보관한 과제가 없습니다/);
    assert.equal(list().querySelectorAll('table').length, 0);
    assert.equal(confirmations.length, 4, 'Only archive, restore and duplicate actions require confirmation.');
    console.log('assignment-archive-tabs-dom-regression passed');
} finally { dom.window.close(); }
