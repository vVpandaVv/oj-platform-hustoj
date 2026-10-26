'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../resources/js/checks.js'), 'utf8');

class Element {
    constructor(tag) {
        this.tagName = tag.toLowerCase();
        this.attributes = new Map();
        this.children = [];
        this.listeners = new Map();
        this.parentNode = null;
        this.className = '';
        this._text = '';
        this.value = '';
        this.disabled = false;
        this.checked = false;
        this.scrollCalls = [];
        this.classList = { contains: name => this.className.split(/\s+/).includes(name) };
    }
    get hidden() { return this.attributes.has('hidden'); }
    set hidden(value) { if (value) this.attributes.set('hidden', ''); else this.attributes.delete('hidden'); }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    set textContent(value) { this._text = String(value); this.children = []; }
    set innerHTML(value) { throw new Error(`Unsafe HTML assignment: ${value}`); }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
    insertBefore(child, reference) {
        child.parentNode = this;
        const position = this.children.indexOf(reference);
        if (position < 0) this.children.push(child); else this.children.splice(position, 0, child);
    }
    get nextSibling() {
        if (!this.parentNode) return null;
        return this.parentNode.children[this.parentNode.children.indexOf(this) + 1] || null;
    }
    replaceChildren(...children) { this.children = []; this._text = ''; children.forEach(child => this.appendChild(child)); }
    remove() {
        if (this.parentNode) this.parentNode.children.splice(this.parentNode.children.indexOf(this), 1);
        this.parentNode = null;
    }
    matches(selector) {
        return selector.split(',').some(raw => {
            let part = raw.trim();
            if (part.endsWith(':checked')) {
                if (!this.checked) return false;
                part = part.slice(0, -8);
            }
            const tag = part.match(/^[a-z]+/);
            if (tag && this.tagName !== tag[0]) return false;
            const prefix = part.split('[')[0];
            for (const match of prefix.matchAll(/\.([a-zA-Z0-9_-]+)/g)) {
                if (!this.classList.contains(match[1])) return false;
            }
            for (const match of part.matchAll(/\[([a-zA-Z0-9_-]+)(?:(\^?=)"([^"]*)")?\]/g)) {
                const value = this.getAttribute(match[1]);
                if (value === null) return false;
                if (match[2] === '=' && value !== match[3]) return false;
                if (match[2] === '^=' && !value.startsWith(match[3])) return false;
            }
            return true;
        });
    }
    querySelectorAll(selector) {
        const result = [];
        const walk = item => item.children.forEach(child => { if (child.matches(selector)) result.push(child); walk(child); });
        walk(this);
        return result;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    closest(selector) {
        let node = this;
        while (node) { if (node.matches(selector)) return node; node = node.parentNode; }
        return null;
    }
    addEventListener(type, fn) { const handlers = this.listeners.get(type) || []; handlers.push(fn); this.listeners.set(type, handlers); }
    fire(type, event = {}) { if (type !== 'click' || !this.disabled) (this.listeners.get(type) || []).forEach(fn => fn(event)); }
    contains(target) { return this === target || this.children.some(child => child.contains(target)); }
    focus() { if (this.testDocument) this.testDocument.activeElement = this; }
    scrollIntoView(options) { this.scrollCalls.push(options); }
}

function node(tag, attributes = {}, value) {
    const element = new Element(tag);
    for (const [name, text] of Object.entries(attributes)) {
        if (name === 'class') element.className = text;
        else element.setAttribute(name, text);
    }
    if (value !== undefined) element.value = value;
    return element;
}

function form(options = {}) {
    const root = node('form', { 'data-oj-submission-form': '' });
    root.appendChild(node('input', { type: 'hidden', name: 'problem_id' }, options.problemId ?? '7'));
    const mode = root.appendChild(node('input', { type: options.mode === 'code_completion' ? 'hidden' : 'radio', name: 'submission_mode' }, options.mode || 'code'));
    mode.checked = options.mode !== 'code_completion';
    const panel = root.appendChild(node('section', { 'data-oj-mode-panel': 'code' }));
    const language = panel.appendChild(node('select', { name: 'language_id' }, String(options.language ?? 6)));
    const editor = panel.appendChild(node('textarea', { name: 'source', 'data-oj-source-editor': '' }, options.source ?? 'value = 3\nprint(value + 1)'));
    for (const kind of ['class', 'group', 'contest']) {
        if (options[kind]) root.appendChild(node('input', { type: 'hidden', name: kind + '_id' }, String(options[kind])));
    }
    if (options.hidden) root.hidden = true;
    return { root, panel, editor, language, mode };
}

function ready(overrides = {}) {
    return { status: 'ready', check: {
        id: 'check-1', prompt: '출력은 무엇일까요?', sourceKind: 'draft', sourceLabel: '현재 작성 중인 초안',
        codeSnippet: 'value = 3\nprint(value + 1)', assignments: { value: 3 },
        choices: [{ id: 'a', label: '4' }, { id: 'b', label: '3' }], expiresAtMs: 1600000,
        ...overrides,
    } };
}

function runtime(options = {}) {
    let elapsed = 0;
    let taskId = 0;
    let uuid = 0;
    let gateState = { active: options.active !== false, activeUntilMs: options.gateEnd ?? 4600000,
        validUntilMs: options.gateLease ?? 4600000, windowStartsAtMs: 1000000, windowEndsAtMs: options.gateEnd ?? 4600000,
        activityIds: [1], runKey: 'run-1', ...(options.gate || {}) };
    const gateListeners = [];
    const tasks = new Map();
    const documentHandlers = new Map();
    const windowHandlers = new Map();
    const requests = [];
    const body = node('body');
    const main = body.appendChild(node('main'));
    const submission = options.noForm ? null : (options.form || form());
    if (submission) main.appendChild(submission.root);
    const config = {
        userId: 11, problemId: 7, serverNowMs: 1000000, nonce: 'fresh',
        checks: { enabled: true, nextUrl: '/checks/next', responseUrl: '/checks/{id}/response', initialDelayMs: 45000, pollMs: 60000, maxDraftBytes: 65536, ...options.checks },
        ...options.config,
    };
    const document = {
        body, activeElement: submission ? submission.editor : body, visibilityState: options.hidden ? 'hidden' : 'visible', readyState: options.loading ? 'loading' : 'complete',
        getElementById(id) { return !options.legacy && id === 'focus-tracker-config' ? { textContent: JSON.stringify(config) } : null; },
        createElement: tag => { const item = node(tag); item.testDocument = document; return item; }, querySelector: selector => body.querySelector(selector),
        querySelectorAll: selector => body.querySelectorAll(selector),
        addEventListener(type, fn) { const handlers = documentHandlers.get(type) || []; handlers.push(fn); documentHandlers.set(type, handlers); },
    };
    const attachDocument = item => { item.testDocument = document; item.children.forEach(attachDocument); };
    attachDocument(body);
    let responder = options.responder || (() => Promise.resolve({ ok: true, json: async () => ready() }));
    const window = {
        performance: { now: () => elapsed },
        crypto: { randomUUID: () => 'response-' + (++uuid) },
        fetch(url, request) { const recorded = { url, ...request, payload: JSON.parse(request.body) }; requests.push(recorded); return responder(recorded); },
        addEventListener(type, fn) { const handlers = windowHandlers.get(type) || []; handlers.push(fn); windowHandlers.set(type, handlers); },
        ...(options.legacy ? { FocusTrackerConfig: config } : {}),
        get localStorage() { throw new Error('Check source and answers must not be persisted'); },
        get sessionStorage() { throw new Error('Check source and answers must not be persisted'); },
    };
    if (!options.noGate) {
        window.FocusTrackerActivityGate = {
            state: () => gateState, now: () => 1000000 + elapsed,
            subscribe(fn) { gateListeners.push(fn); fn(gateState); return () => {}; },
            refresh() { gateState = { ...gateState, active: false }; gateListeners.forEach(fn => fn(gateState)); },
        };
    }
    const context = vm.createContext({ document, window, Date: { now: () => 1000000 + elapsed }, Promise, Math, Number, Object, Array, JSON, encodeURIComponent,
        setTimeout(fn, ms) { const id = ++taskId; tasks.set(id, { fn, at: elapsed + ms }); return id; },
        clearTimeout(id) { tasks.delete(id); },
    });
    vm.runInContext(source, context, { filename: 'checks.js' });
    const settle = async () => { for (let turn = 0; turn < 25; turn += 1) await Promise.resolve(); };
    async function advance(ms) {
        await settle();
        const end = elapsed + ms;
        let iterations = 0;
        while (true) {
            const task = [...tasks].filter(([, task]) => task.at <= end).sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
            if (!task) break;
            assert.ok(++iterations < 10000, 'timers settle');
            elapsed = task[1].at;
            tasks.delete(task[0]);
            task[1].fn();
            await settle();
        }
        elapsed = end;
        await settle();
    }
    return {
        body, main, submission, config, document, window, requests, advance, settle,
        dispatch(target, type, event = {}) { (target === 'document' ? documentHandlers : windowHandlers).get(type)?.forEach(fn => fn(event)); },
        runAgain() { vm.runInContext(source, context); },
        respondWith(fn) { responder = fn; },
        gate(value) { gateState = { ...gateState, ...value }; gateListeners.forEach(fn => fn(gateState)); },
        panel() { return body.querySelector('[data-ftk-check]'); },
        answer(id) { body.querySelector(`[data-ftk-answer="${id}"]`).fire('click'); },
        click(text) { const button = body.querySelectorAll('button').find(item => item.textContent === text); assert.ok(button, text); button.fire('click'); },
    };
}

const success = result => Promise.resolve({ ok: true, json: async () => result });

test('permanent announcements and keyboard focus survive response replacement and schedule closure without taking editor focus', async () => {
    const app = runtime({ responder: request => success(request.url.endsWith('/next') ? ready() : { status: 'correct' }) });
    const announcer = app.body.querySelector('[data-ftk-check-announcer]');
    assert.equal(announcer.textContent, '');
    await app.advance(45000);
    assert.equal(app.document.activeElement, app.submission.editor, 'automatic question keeps editor focus');
    assert.match(announcer.textContent, /1문항이 준비/);
    app.click('학습 확인 1문항');
    assert.equal(app.document.activeElement, app.panel().querySelector('.ftk-check-title'));
    const answer = app.panel().querySelector('[data-ftk-answer="a"]');
    answer.focus(); app.answer('a'); await app.settle();
    const close = app.panel().querySelector('button');
    assert.equal(app.document.activeElement, close, 'focused answer becomes focused close button');
    assert.match(announcer.textContent, /정답을 확인한 기록/);
    close.fire('click');
    assert.equal(app.document.activeElement, app.submission.editor, 'closing returns to original editor');
    assert.equal(app.body.querySelector('[data-ftk-check-announcer]'), announcer, 'live region is never replaced');

    const ending = runtime(); await ending.advance(45000);
    ending.panel().querySelector('[data-ftk-answer="a"]').focus();
    ending.gate({ active: false });
    assert.equal(ending.document.activeElement, ending.submission.editor);
    const editing = runtime(); await editing.advance(45000); editing.gate({ active: false });
    assert.equal(editing.document.activeElement, editing.submission.editor, 'stop never steals ongoing editor focus');
});

test('late response acknowledges without moving focus away from an editor the student returned to', async () => {
    let acknowledge;
    const app = runtime({ responder: request => request.url.endsWith('/next') ? success(ready())
        : new Promise(resolve => { acknowledge = resolve; }) });
    await app.advance(45000); app.panel().querySelector('[data-ftk-answer="a"]').focus(); app.answer('a');
    app.submission.editor.focus(); acknowledge({ ok: true, json: async () => ({ status: 'incorrect' }) }); await app.settle();
    assert.equal(app.document.activeElement, app.submission.editor);
});

test('normal no-candidate, unsupported, cooldown and network failures have distinct nonjudgmental notices', async () => {
    for (const [reason, text] of [['no_candidate', /검증할 수 있는 확인 문항이 없습니다/], ['unsupported', /지원하지 않습니다/], ['cooldown', /기다리는 중/], ['access', /자료를 확인하지 못해/]]) {
        const app = runtime({ responder: () => success({ status: reason === 'cooldown' ? 'cooldown' : 'unavailable', reason }) });
        await app.advance(45000);
        const notice = app.body.querySelector('[data-ftk-check-availability]');
        assert.equal(notice.hidden, false); assert.match(notice.textContent, text);
        assert.match(notice.textContent, /응답 유무는 집중 점수에 반영하지 않습니다/);
        assert.equal(app.panel(), null); app.gate({ active: false }); assert.equal(notice.hidden, true);
    }
    const app = runtime({ responder: () => Promise.reject(new Error('offline')) }); await app.advance(45000);
    assert.match(app.body.querySelector('[data-ftk-check-availability]').textContent, /연결 문제/);
    const limited = runtime({ responder: () => Promise.resolve({ ok: false, status: 429, json: async () => ({ data: { retryAfterSeconds: 120 } }) }) });
    await limited.advance(45000); assert.match(limited.body.querySelector('[data-ftk-check-availability]').textContent, /기다리는 중/);
    await limited.advance(119999); assert.equal(limited.requests.length, 1);
    await limited.advance(1); assert.equal(limited.requests.length, 2);
});

test('automatically requests a draft-based check after settling without submitting and scopes identity and context', async () => {
    const app = runtime({ form: form({ class: 3, group: 4, contest: 5 }) });
    await app.advance(44999);
    assert.equal(app.requests.length, 0);
    await app.advance(1);
    assert.equal(app.requests.length, 1);
    assert.deepEqual(app.requests[0].payload, {
        user_id: 11, problem_id: 7, context_type: 'contest', context_id: '5',
        draft: { source: 'value = 3\nprint(value + 1)', language_id: 6 },
    });
    assert.equal(app.requests[0].headers['X-WP-Nonce'], 'fresh');
    assert.equal(app.requests[0].credentials, 'same-origin');
    assert.match(app.panel().textContent, /정답 여부는 집중 점수에 반영하지 않습니다/);
    await app.advance(120000);
    assert.equal(app.requests.length, 1, 'one visible check does not create duplicate checks');
});

test('problem fallback omits empty drafts, unrelated inputs, other problem forms and disabled or hidden modes', async () => {
    for (const options of [
        { noForm: true }, { form: form({ source: '' }) }, { form: form({ problemId: 99 }) }, { form: form({ hidden: true }) },
    ]) {
        const app = runtime(options);
        await app.advance(45000);
        assert.ok(!('draft' in app.requests[0].payload));
        assert.equal(app.requests[0].payload.context_type, 'general');
    }
    for (const disabled of ['editor', 'language']) {
        const submission = form();
        submission[disabled].disabled = true;
        const app = runtime({ form: submission });
        await app.advance(45000);
        assert.ok(!('draft' in app.requests[0].payload));
    }
    const submission = form();
    submission.panel.hidden = true;
    const app = runtime({ form: submission });
    await app.advance(45000);
    assert.ok(!('draft' in app.requests[0].payload));
});

test('context precedence and current problem matching exclude unrelated private contexts', async () => {
    for (const [input, kind, id] of [[{ class: 9, group: 8 }, 'class', '9'], [{ group: 8 }, 'group', '8']]) {
        const app = runtime({ form: form(input) });
        await app.advance(45000);
        assert.equal(app.requests[0].payload.context_type, kind);
        assert.equal(app.requests[0].payload.context_id, id);
    }
});

test('completed code blanks assemble visible template and values; incomplete blanks fall back to problem', async () => {
    for (const filled of [false, true]) {
        const submission = form({ mode: 'code_completion' });
        submission.panel.remove();
        submission.root.appendChild(node('input', { type: 'hidden', name: 'language_id' }, '0'));
        const completion = submission.root.appendChild(node('div', { 'data-oj-completion-editor': '' }));
        const prefix = completion.appendChild(node('span', { class: 'ojp-completion-code' }));
        prefix.textContent = 'int main() { return ';
        const blank = completion.appendChild(node('label', { class: 'ojp-completion-blank' }));
        blank.appendChild(node('input', { name: 'completion_answers[expression]' }, filled ? '7' : ''));
        const suffix = completion.appendChild(node('span', { class: 'ojp-completion-code' }));
        suffix.textContent = '; }';
        const app = runtime({ form: submission });
        await app.advance(45000);
        if (filled) assert.deepEqual(app.requests[0].payload.draft, { source: 'int main() { return 7; }', language_id: 0 });
        else assert.ok(!('draft' in app.requests[0].payload));
    }
});

test('Blockly reads only mounted active generated Python, rejecting disconnected and loading states', async () => {
    for (const invalid of [false, true, 'loading', 'hidden']) {
        const submission = form({ mode: 'blockly', source: 'stale hidden code' });
        submission.panel.hidden = true;
        const blockPanel = submission.root.appendChild(node('section', { 'data-oj-mode-panel': 'blockly' }));
        const root = blockPanel.appendChild(node('div', { 'data-oj-blockly-editor': '', 'data-mounted': invalid === 'loading' ? 'false' : 'true' }));
        const code = root.appendChild(node('code', { 'data-oj-blockly-code': '' }));
        code.textContent = 'print(7)\n';
        const status = root.appendChild(node('p', { 'data-oj-blockly-status': '', class: invalid === true ? 'is-error' : '' }));
        if (invalid === 'hidden') blockPanel.hidden = true;
        const app = runtime({ form: submission });
        await app.advance(45000);
        if (!invalid) assert.deepEqual(app.requests[0].payload.draft, { source: 'print(7)\n', language_id: 6 });
        else assert.ok(!('draft' in app.requests[0].payload));
        assert.ok(!JSON.stringify(app.requests).includes('stale hidden code'));
        assert.equal(status.textContent, '', 'editor content and status are not changed');
    }
});

test('draft byte bound uses UTF-8 bytes and never truncates source', async () => {
    for (const [code, included] of [['가'.repeat(21845), true], ['가'.repeat(21846), false], ['😀'.repeat(16384), true], ['😀'.repeat(16385), false]]) {
        const app = runtime({ form: form({ source: code }) });
        await app.advance(45000);
        assert.equal('draft' in app.requests[0].payload, included);
        if (included) assert.equal(app.requests[0].payload.draft.source, code);
    }
});

test('all server text uses textContent, assignments are visible, and no answer key is rendered or transmitted', async () => {
    const app = runtime({ responder: () => success(ready({
        prompt: '<img src=x onerror=alert(1)>', codeSnippet: '<script>throw 1</script>',
        choices: [{ id: 'opaque-a', label: '<b>4</b>' }, { id: 'opaque-b', label: '3' }], correct_answer: 'private-key',
    })) });
    await app.advance(45000);
    const panel = app.panel();
    assert.match(panel.textContent, /<img src=x onerror=alert\(1\)>/);
    assert.match(panel.textContent, /value = 3/);
    assert.equal(panel.querySelectorAll('script, img, b').length, 0);
    assert.ok(!panel.textContent.includes('private-key'));
    assert.ok(!JSON.stringify(app.requests).includes('private-key'));
    assert.equal(panel.getAttribute('role'), 'region');
    assert.equal(panel.getAttribute('aria-labelledby'), 'ftk-check-title');
    assert.equal(app.body.querySelector('[data-ftk-check-announcer]').getAttribute('aria-live'), 'polite');
    assert.ok(panel.querySelectorAll('button').every(item => item.type === 'button'));
});

test('problem-based sample check displays the exact published input required to answer', async () => {
    const app = runtime({ noForm: true, responder: () => success(ready({
        sourceKind: 'problem', sourceLabel: '현재 문제의 공개 예제',
        codeSnippet: '', assignments: '', input: '3 4\n<script>5</script>\n',
    })) });
    await app.advance(45000);
    assert.match(app.panel().textContent, /예제 입력3 4\n<script>5<\/script>/);
    assert.equal(app.panel().querySelectorAll('script').length, 0);
    assert.ok(!('draft' in app.requests[0].payload));
});

test('incorrect, unknown, skipped and expired responses never label the student inattentive or submit tracking events', async () => {
    for (const [status, action] of [['incorrect', 'a'], ['unknown', '모르겠음'], ['skipped', '나중에'], ['expired', 'a'], ['invalidated', 'a']]) {
        const app = runtime({ responder: request => success(request.url.endsWith('/next') ? ready() : { status }) });
        await app.advance(45000);
        if (action === 'a') app.answer('a'); else app.click(action);
        await app.settle();
        assert.match(app.panel().textContent, /미집중으로 판단하지 않습니다/);
        assert.equal(app.requests[1].payload.user_id, 11);
        assert.equal(app.requests[1].payload.response_uid, 'response-1');
        assert.equal(app.requests[1].url, '/checks/check-1/response');
        if (action === 'a') assert.equal(app.requests[1].payload.answer_id, 'a');
        else assert.equal(app.requests[1].payload.action, action === '모르겠음' ? 'unknown' : 'skip');
        assert.ok(app.requests.every(request => !request.url.includes('/events')));
        await app.advance(299999);
        assert.equal(app.requests.length, 2, 'post-response cooldown limits interruption');
        app.click('닫기');
        assert.equal(app.panel(), null);
    }
});

test('unanswered check expires without inventing an answer or focus penalty', async () => {
    const app = runtime({ responder: () => success(ready({ expiresAtMs: 1060000 })) });
    await app.advance(60000);
    assert.match(app.panel().textContent, /응답이 없어 학습 참여 여부를 확인하지 못했습니다/);
    assert.match(app.panel().textContent, /미집중으로 판단하지 않습니다/);
    assert.equal(app.requests.length, 1);
    assert.equal(app.panel().querySelectorAll('[data-ftk-answer]').length, 0);
    assert.equal(app.body.querySelector('[data-ftk-check-launcher]'), null);
});

test('a compact check entry point appears without scrolling and only explicit activation scrolls to the question', async () => {
    const app = runtime({ responder: request => success(request.url.endsWith('/next') ? ready() : { status: 'unknown' }) });
    await app.advance(45000);
    const panel = app.panel();
    const launcher = app.body.querySelector('[data-ftk-check-launcher]');
    assert.ok(launcher);
    assert.equal(launcher.getAttribute('aria-controls'), panel.id);
    assert.equal(launcher.getAttribute('aria-label'), '학습 확인 1문항 보기');
    assert.equal(panel.scrollCalls.length, 0, 'automatic appearance never moves the viewport');
    app.click('학습 확인 1문항');
    assert.equal(panel.scrollCalls.length, 1, 'only the student activation scrolls');
    assert.equal(panel.scrollCalls[0].block, 'start');
    app.click('모르겠음');
    await app.settle();
    assert.equal(app.body.querySelector('[data-ftk-check-launcher]'), null, 'successful response clears the entry point');
});

test('HTTP failures retain exactly the same response UID and selected answer until acknowledgement', async () => {
    const app = runtime({ responder: request => request.url.endsWith('/next') ? success(ready()) : Promise.reject(new Error('offline')) });
    await app.advance(45000);
    app.answer('a');
    await app.settle();
    const first = app.requests[1].payload;
    assert.match(app.panel().textContent, /다시 전송합니다/);
    app.respondWith(() => success({ status: 'correct' }));
    app.dispatch('window', 'online');
    await app.settle();
    assert.deepEqual(app.requests[2].payload, first);
    assert.match(app.panel().textContent, /정답을 확인한 기록을 남겼습니다/);
    assert.equal(app.requests.length, 3);
});

test('hung requests time out, disabled account/nonce responses stop further requests, and invalid ready data stays hidden', async () => {
    const hung = runtime({ responder: () => new Promise(() => {}) });
    await hung.advance(120000);
    assert.equal(hung.requests.length, 2);
    for (const status of [401, 403]) {
        const denied = runtime({ responder: () => Promise.resolve({ ok: false, status }) });
        await denied.advance(180000);
        assert.equal(denied.requests.length, 1);
        assert.equal(denied.panel(), null);
    }
    for (const check of [ready({ expiresAtMs: 1 }), ready({ choices: [] }), ready({ choices: [{ id: 'a', label: '1' }, { id: 'a', label: '2' }] })]) {
        const malformed = runtime({ responder: () => success(check) });
        await malformed.advance(45000);
        assert.equal(malformed.panel(), null);
    }
});

test('DOMContentLoaded, duplicate scripts, hidden tabs, cooldown and BFCache never duplicate the question', async () => {
    const app = runtime({ loading: true, hidden: true });
    app.runAgain();
    await app.advance(60000);
    assert.equal(app.requests.length, 0);
    app.dispatch('document', 'DOMContentLoaded');
    await app.advance(45000);
    assert.equal(app.requests.length, 0);
    app.document.visibilityState = 'visible';
    app.dispatch('document', 'visibilitychange');
    await app.settle();
    assert.equal(app.requests.length, 1);
    app.dispatch('window', 'pagehide');
    await app.advance(120000);
    assert.equal(app.requests.length, 1);
    app.dispatch('window', 'pageshow', { persisted: true });
    await app.advance(1000);
    assert.equal(app.requests.length, 1);
    assert.equal(app.body.querySelectorAll('[data-ftk-check]').length, 0, 'suspension removes the old question');
    await app.advance(44000);
    assert.equal(app.body.querySelectorAll('[data-ftk-check]').length, 1, 'fresh allowed period starts its delay again');
});

test('unavailable/cooldown do not open a question and backend retry interval is respected', async () => {
    const app = runtime({ responder: () => success({ status: 'cooldown', retryAfterMs: 300000 }) });
    await app.advance(344999);
    assert.equal(app.requests.length, 1);
    assert.equal(app.panel(), null);
    await app.advance(1);
    assert.equal(app.requests.length, 2);
    const unavailable = runtime({ responder: () => success({ status: 'unavailable' }) });
    await unavailable.advance(45000);
    assert.equal(unavailable.panel(), null);
});

test('disabled feature, missing identity/problem and missing fetch never start checks; legacy config works', async () => {
    for (const config of [{ userId: 0 }, { problemId: null }, { checks: { enabled: false } }]) {
        const app = runtime({ config });
        await app.advance(90000);
        assert.equal(app.requests.length, 0);
    }
    const legacy = runtime({ legacy: true });
    await legacy.advance(45000);
    assert.equal(legacy.requests.length, 1);
});

test('no active schedule means no draft reads or check requests; activation starts without reloading', async () => {
    const submission = form();
    let reads = 0;
    Object.defineProperty(submission.editor, 'value', { get() { reads += 1; return 'if value > 1:\n    pass'; } });
    const app = runtime({ active: false, form: submission });
    await app.advance(600000);
    assert.equal(reads, 0);
    assert.equal(app.requests.length, 0);
    app.gate({ active: true, runKey: 'new-allowed-run' });
    await app.advance(44999);
    assert.equal(reads, 0, 'delay is relative to allowed activation');
    await app.advance(1);
    assert.equal(reads, 1);
    assert.equal(app.requests.length, 1);
});

test('missing shared gate fails closed even when check configuration says enabled', async () => {
    const app = runtime({ noGate: true });
    await app.advance(600000);
    assert.equal(app.requests.length, 0);
    assert.equal(app.panel(), null);
    const noLease = runtime({ gate: { validUntilMs: undefined } });
    await noLease.advance(600000);
    assert.equal(noLease.requests.length, 0, 'an active boolean without a verified lease is insufficient');
});

test('natural deadline independently stops checks exactly and short windows issue nothing', async () => {
    const short = runtime({ gateEnd: 1040000 });
    await short.advance(120000);
    assert.equal(short.requests.length, 0, 'window shorter than initial delay reads no draft');
    const app = runtime({ gateEnd: 1050000 });
    await app.advance(49999);
    assert.ok(app.panel());
    await app.advance(1);
    assert.equal(app.panel(), null, 'end is exclusive even if cached gate state still says active');
    assert.equal(app.body.querySelector('[data-ftk-check-launcher]'), null);
    await app.advance(180000);
    assert.equal(app.requests.length, 1);
});

test('lease renewals preserve the initial delay and question while an expired lease stops everything', async () => {
    const app = runtime({ gateLease: 1030000 });
    await app.advance(20000);
    app.gate({ validUntilMs: 1100000 });
    await app.advance(24999);
    assert.equal(app.requests.length, 0);
    await app.advance(1);
    assert.equal(app.requests.length, 1, 'ordinary renewal does not restart the 45 second delay');
    const panel = app.panel();
    app.gate({ validUntilMs: 1105000 });
    assert.equal(app.panel(), panel, 'ordinary renewal preserves same pending question');
    await app.advance(60000);
    assert.equal(app.panel(), null, 'expired lease immediately clears question');
});

test('schedule stop discards queued answers and an old retry cannot revive in the next run', async () => {
    const app = runtime({ responder: request => request.url.endsWith('/next') ? success(ready()) : Promise.reject(new Error('offline')) });
    await app.advance(45000);
    app.answer('a');
    await app.settle();
    assert.equal(app.requests.length, 2);
    app.gate({ active: false });
    assert.equal(app.panel(), null);
    app.dispatch('window', 'online');
    await app.advance(60000);
    assert.equal(app.requests.length, 2, 'stopped period sends no queued response');
    app.gate({ active: true, runKey: 'run-2', windowStartsAtMs: 1105000 });
    app.respondWith(() => success(ready({ id: 'check-2' })));
    await app.advance(45000);
    assert.equal(app.requests.length, 3);
    assert.equal(app.requests[2].url, '/checks/next', 'new period never sends old queued answer');
});

test('late network completions after schedule stop cannot recreate questions or acknowledgement UI', async () => {
    let resolveNext;
    const app = runtime({ responder: () => new Promise(resolve => { resolveNext = resolve; }) });
    await app.advance(45000);
    app.gate({ active: false });
    resolveNext({ ok: true, json: async () => ready() });
    await app.settle();
    assert.equal(app.panel(), null);
    let resolveAnswer;
    const answering = runtime({ responder: request => request.url.endsWith('/next') ? success(ready()) : new Promise(resolve => { resolveAnswer = resolve; }) });
    await answering.advance(45000);
    answering.answer('a');
    answering.gate({ active: false });
    resolveAnswer({ ok: true, json: async () => ({ status: 'correct' }) });
    await answering.settle();
    assert.equal(answering.panel(), null);
    assert.equal(answering.body.querySelector('[data-ftk-check-launcher]'), null);
});
