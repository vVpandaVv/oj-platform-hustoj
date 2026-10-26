import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Real wrapper handlers with synthetic DOM/API objects only; no live server or student data.
const source = readFileSync(new URL('../resources/js/addon.js', import.meta.url), 'utf8');
const closureEnd = source.lastIndexOf('}());');
assert.ok(closureEnd > 0);

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

function node() {
    const handlers = {};
    return {
        handlers, textContent: '', innerHTML: '', dataset: {}, isConnected: true,
        addEventListener(type, handler) { handlers[type] = handler; },
        setAttribute() {},
    };
}

async function flush() {
    for (let i = 0; i < 8; i += 1) await Promise.resolve();
}

async function fixture({ newAttempt = false, creationError = false } = {}) {
    const root = node();
    root.dataset = {
        assignmentId: 'fixture-assignment', publicPcMode: '1',
        playgroundUrl: '/fixture-playground', restRoot: '/fixture-rest',
    };
    const selectors = Object.fromEntries([
        'start', 'workspace', 'submit', 'frame', 'upload-form', 'receipt', 'result-link', 'attempt-counts',
    ].map(name => [`[data-oj-ml-${name}]`, node()]));
    const status = node();
    const counts = selectors['[data-oj-ml-attempt-counts]'];
    const frameHolder = selectors['[data-oj-ml-frame]'];
    const form = selectors['[data-oj-ml-upload-form]'];
    const uploadButton = node();
    const fileInput = { files: [{ name: 'synthetic.mlpx', size: 100 }] };
    form.querySelector = selector => selector === 'button[type="submit"]' ? uploadButton : fileInput;
    const frames = [];
    frameHolder.appendChild = frame => frames.push(frame);
    let contentWrites = 0;
    let html = '';
    const content = {
        querySelector: selector => selectors[selector] || null,
        set innerHTML(value) {
            contentWrites += 1;
            html = value;
            counts.textContent = value.match(/<span data-oj-ml-attempt-counts>([^<]+)<\/span>/)?.[1] || '';
        },
        get innerHTML() { return html; },
    };
    root.querySelector = selector => selector === '[data-oj-ml-content]' ? content
        : selector === '[data-oj-ml-status]' ? status : null;
    const window = node();
    window.location = { origin: 'https://fixture.test' };
    window.OjMlPublicPcReady = async () => true;
    const document = {
        addEventListener() {},
        createElement(tag) {
            assert.equal(tag, 'iframe', 'Refreshing counts must not create unrelated DOM.');
            return { ...node(), contentWindow: { postMessage() {} } };
        },
    };
    const context = vm.createContext({ window, document, FormData: class { append() {} } });
    vm.runInContext(source.slice(0, closureEnd)
        + 'window.labTest = { renderLab: renderLab };\n' + source.slice(closureEnd), context);
    const initial = {
        id: 'fixture-assignment', title: '합성 실습', status: 'published',
        maxAttempts: 3, attemptsUsed: newAttempt ? 0 : 1, activeAttempt: newAttempt ? null : { id: 'attempt-1' },
    };
    const reads = [];
    const uploads = [];
    const creations = [];
    const api = {
        get(path) {
            assert.equal(path, 'assignments/fixture-assignment');
            const pending = deferred();
            reads.push(pending);
            if (reads.length === 1) pending.resolve({ assignment: initial });
            return pending.promise;
        },
        async upload(path) {
            uploads.push(path);
            return { attempt: { id: 'manual-attempt' }, receipt: receipt(8) };
        },
        async post(path) {
            assert.equal(newAttempt, true, 'An existing attempt must not be recreated.');
            assert.equal(path, 'assignments/fixture-assignment/attempts');
            creations.push(path);
            if (creationError) throw new Error('Synthetic creation failure');
            return { attempt: { id: 'new-attempt' } };
        },
    };
    await window.labTest.renderLab(root, api);
    assert.equal(counts.textContent, `최대 3회 제출 (사용 ${newAttempt ? 0 : 1}회)`);
    await selectors['[data-oj-ml-start]'].handlers.click();
    assert.equal(frames.length, creationError ? 0 : 1);
    const originalFrame = frames[0];
    function direct(payload = submitted()) {
        window.handlers.message({
            origin: window.location.origin, source: originalFrame.contentWindow,
            data: { type: 'oj-ml:submission-status', version: 1, ...payload },
        });
    }
    function stableWorkspace() {
        assert.equal(contentWrites, 1, 'Successful submissions must never rerender the lab.');
        assert.equal(frames.length, 1, 'Successful submissions must never remount the iframe.');
        assert.equal(frames[0], originalFrame);
        assert.equal(originalFrame.src, '/fixture-playground');
    }
    return { root, window, selectors, counts, status, reads, uploads, creations, form, uploadButton, direct, stableWorkspace };
}

function receipt(attemptNo = 7) {
    return { attemptNo, receivedAt: '2026-09-10T03:00:00Z', bytes: 100, sha256: 'a'.repeat(64) };
}

function submitted() {
    return { state: 'submitted', attempt: { id: 'direct-attempt' }, receipt: receipt() };
}

const lab = await fixture();
assert.equal(lab.reads.length, 1, 'Resuming an existing attempt must not refresh/increment counts.');
assert.equal(lab.creations.length, 0);
lab.direct({ state: 'submitting' });
lab.direct({ state: 'error' });
lab.window.handlers.message({ origin: 'https://other.test', data: { type: 'oj-ml:submission-status', version: 1, ...submitted() } });
assert.equal(lab.reads.length, 1, 'Only confirmed trusted submissions may refresh counts.');
lab.direct();
assert.equal(lab.reads.length, 2);
assert.match(lab.counts.textContent, /확인하는 중/);
lab.reads[1].resolve({ assignment: { maxAttempts: 3, attemptsUsed: 2 } });
await flush();
assert.match(lab.counts.textContent, /최대 3회 제출 \(사용 2회\)/, 'Receipt attempt number 7 must not become the consumed allowance.');
assert.match(lab.selectors['[data-oj-ml-receipt]'].innerHTML, /시도: 7/);
lab.stableWorkspace();

// The same receipt can be delivered again; never increment locally or apply an older GET last.
lab.direct();
lab.direct();
lab.reads[3].resolve({ assignment: { maxAttempts: 4, attemptsUsed: 2 } });
await flush();
lab.reads[2].resolve({ assignment: { maxAttempts: 3, attemptsUsed: 1 } });
await flush();
assert.match(lab.counts.textContent, /최대 4회 제출 \(사용 2회\)/);
lab.direct();
lab.reads[4].resolve({ maxAttempts: 4, attemptsUsed: 1 });
await flush();
assert.match(lab.counts.textContent, /사용 1회/, 'A fresh server count, including refunded allowance, is authoritative.');

await lab.form.handlers.submit({ preventDefault() {} });
assert.deepEqual(lab.uploads, ['attempts/direct-attempt/submit']);
lab.reads[5].resolve({ assignment: { maxAttempts: 4, attemptsUsed: 3 } });
await flush();
assert.match(lab.counts.textContent, /최대 4회 제출 \(사용 3회\)/, 'Manual upload success shares the authoritative refresh.');
assert.match(lab.selectors['[data-oj-ml-receipt]'].innerHTML, /시도: 8/);
lab.stableWorkspace();

lab.root.handlers['oj-ml:trusted-submission-status']({ detail: submitted() });
const acceptedStatus = lab.status.textContent;
const acceptedReceipt = lab.selectors['[data-oj-ml-receipt]'].innerHTML;
lab.reads[6].reject(new Error('Synthetic offline failure'));
await flush();
assert.match(lab.counts.textContent, /불러오지 못했습니다.*제출 결과/);
assert.doesNotMatch(lab.counts.textContent, /사용 3회/, 'A failed refresh must not silently present the old count as current.');
assert.equal(lab.status.textContent, acceptedStatus, 'Read failure must not turn a successful submission into a failed upload.');
assert.equal(lab.selectors['[data-oj-ml-receipt]'].innerHTML, acceptedReceipt);
lab.direct();
lab.reads[7].resolve({ assignment: { maxAttempts: 4, attemptsUsed: 3 } });
await flush();
assert.match(lab.counts.textContent, /사용 3회/, 'A later duplicate/retry can recover from a failed read.');

for (const malformed of [null, {}, { maxAttempts: 4 }, { maxAttempts: 4, attemptsUsed: -1 }, { maxAttempts: 0, attemptsUsed: 1 }, { maxAttempts: 4, attemptsUsed: '<img src=x>' }]) {
    lab.direct();
    lab.reads.at(-1).resolve({ assignment: malformed });
    await flush();
    assert.match(lab.counts.textContent, /불러오지 못했습니다/);
    assert.doesNotMatch(lab.counts.textContent, /<img/);
}

// An older failed read must not overwrite the newest successful one either.
lab.direct();
const oldRead = lab.reads.at(-1);
lab.direct();
lab.reads.at(-1).resolve({ assignment: { maxAttempts: 4, attemptsUsed: 3 } });
await flush();
oldRead.reject(new Error('Late old failure'));
await flush();
assert.match(lab.counts.textContent, /사용 3회/);
lab.stableWorkspace();

lab.direct();
lab.root.dataset.publicPcTerminal = '1';
lab.counts.textContent = '공용 PC 보호로 종료됨';
lab.reads.at(-1).resolve({ assignment: { maxAttempts: 4, attemptsUsed: 4 } });
await flush();
assert.equal(lab.counts.textContent, '공용 PC 보호로 종료됨');
const terminalReads = lab.reads.length;
lab.direct();
lab.root.handlers['oj-ml:trusted-submission-status']({ detail: submitted() });
await lab.form.handlers.submit({ preventDefault() {} });
assert.equal(lab.reads.length, terminalReads);
assert.equal(lab.uploads.length, 1);
lab.stableWorkspace();

const disconnected = await fixture();
disconnected.direct();
disconnected.counts.isConnected = false;
disconnected.counts.textContent = 'detached';
disconnected.reads.at(-1).resolve({ assignment: { maxAttempts: 3, attemptsUsed: 2 } });
await flush();
assert.equal(disconnected.counts.textContent, 'detached');

const guarded = await fixture();
guarded.window.OjMlPublicPcReady = async () => false;
await guarded.form.handlers.submit({ preventDefault() {} });
assert.equal(guarded.uploads.length, 0, 'Count refresh must not bypass public-PC upload readiness.');
assert.equal(guarded.reads.length, 1);
guarded.stableWorkspace();

const created = await fixture({ newAttempt: true });
assert.equal(created.creations.length, 1);
assert.equal(created.reads.length, 2, 'Successful attempt creation consumes allowance before submission.');
assert.equal(created.uploads.length, 0);
assert.match(created.counts.textContent, /확인하는 중/);
created.reads[1].resolve({ assignment: { maxAttempts: 3, attemptsUsed: 1 } });
await flush();
assert.match(created.counts.textContent, /사용 1회/);
await created.selectors['[data-oj-ml-start]'].handlers.click();
assert.equal(created.creations.length, 1, 'Opening the same workspace again must not create another attempt.');
assert.equal(created.reads.length, 2);
created.stableWorkspace();

const failedCreation = await fixture({ newAttempt: true, creationError: true });
assert.equal(failedCreation.creations.length, 1);
assert.equal(failedCreation.reads.length, 1, 'Failed creation must not infer allowance consumption or refresh a success state.');
assert.match(failedCreation.counts.textContent, /사용 0회/);
assert.equal(failedCreation.status.dataset.kind, 'error');

console.log('OJ ML lab attempt-counts regression passed (creation, direct/manual, duplicates, ordering, failure, terminal guards, iframe continuity).');
