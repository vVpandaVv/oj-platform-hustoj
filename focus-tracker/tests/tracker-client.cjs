'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../resources/js/tracker.js'), 'utf8');
let runtimeId = 0;

class Storage {
    constructor() { this.values = new Map(); }
    get length() { return this.values.size; }
    key(index) { return [...this.values.keys()][index] ?? null; }
    getItem(key) { return this.values.get(key) ?? null; }
    setItem(key, value) { this.values.set(key, String(value)); }
    removeItem(key) { this.values.delete(key); }
}

function control(kind, options = {}) {
    return {
        kind,
        disabled: false,
        // The tracker must never need to inspect code/answers.
        get value() { throw new Error('Source contents must not be read'); },
        closest(selector) {
            if (selector === '[hidden]') { return options.hidden ? this : null; }
            if (selector === '[data-oj-blockly-editor]') { return kind === 'blockly' ? this : null; }
            if (kind === 'source' && selector.includes('textarea[name="source"]')) { return this; }
            if (kind === 'completion' && selector.includes('completion_answers[')) { return this; }
            if (kind === 'form' && selector.includes('form[data-oj-submission-form]')) { return this; }
            if (kind === 'run' && selector.includes('[data-action="run"]')) { return this; }
            return null;
        },
    };
}

function runtime(options = {}) {
    const id = ++runtimeId;
    let elapsed = 0;
    let wall = options.wall ?? 1000000;
    let nextTask = 0;
    let uuid = 0;
    const tasks = new Map();
    const handlers = { document: new Map(), window: new Map() };
    const requests = [];
    const beacons = [];
    const storage = options.storage || new Storage();
    const config = {
        restUrl: '/wp-json/focus-tracker/v1/events',
        nonce: 'fresh-nonce',
        userId: 11,
        problemId: 7,
        serverNowMs: options.serverNow ?? wall,
        noticeText: null,
        ...options.config,
    };
    let gateValue = options.gate ? { ...options.gate } : null;
    const gateListeners = [];
    if (gateValue) config.activityGate = { url: '/tracking/state' };
    const gate = gateValue ? {
        state() { return { ...gateValue, nowMs: (options.serverNow ?? wall) + elapsed }; },
        now() { return (options.serverNow ?? wall) + elapsed; },
        subscribe(listener) { gateListeners.push(listener); listener(this.state()); return () => {}; },
    } : null;
    let responder = options.responder || (() => Promise.resolve({ ok: true, json: async () => ({ acknowledged: true }) }));
    function listen(target, type, fn) {
        const list = handlers[target].get(type) || [];
        list.push(fn);
        handlers[target].set(type, list);
    }
    function schedule(fn, ms, repeat) {
        const key = ++nextTask;
        tasks.set(key, { fn, ms, at: elapsed + ms, repeat });
        return key;
    }
    const document = {
        readyState: options.readyState || 'complete',
        visibilityState: options.hidden ? 'hidden' : 'visible',
        focused: !options.hidden && options.focused !== false,
        hasFocus() { return this.focused; },
        getElementById(name) {
            return !options.legacy && name === 'focus-tracker-config' ? { textContent: JSON.stringify(config) } : null;
        },
        querySelector() { return null; },
        addEventListener(type, fn) { listen('document', type, fn); },
    };
    const window = {
        localStorage: storage,
        crypto: { randomUUID() { return `tab-${id}-session-${++uuid}`; } },
        performance: { now() { return elapsed; } },
        addEventListener(type, fn) { listen('window', type, fn); },
        fetch(url, request) {
            const recorded = { url, ...request, payload: JSON.parse(request.body) };
            requests.push(recorded);
            return responder(recorded);
        },
        ...(options.legacy ? { FocusTrackerConfig: config } : {}),
        ...(options.window || {}),
        ...(gate ? { FocusTrackerActivityGate: gate } : {}),
    };
    const context = vm.createContext({
        document,
        window,
        navigator: { sendBeacon(url, body) {
            beacons.push({ url, payload: JSON.parse(body.body) });
            return options.beaconResult !== false;
        } },
        Blob: class { constructor(parts) { this.body = parts.join(''); } },
        Date: { now() { return wall + elapsed; } },
        setInterval(fn, ms) { return schedule(fn, ms, true); },
        setTimeout(fn, ms) { return schedule(fn, ms, false); },
        clearInterval(key) { tasks.delete(key); },
        clearTimeout(key) { tasks.delete(key); },
        Promise, Number, Object, Math, JSON, encodeURIComponent,
    });
    vm.runInContext(source, context, { filename: 'tracker.js' });
    function dispatch(target, type, event = {}) {
        for (const fn of handlers[target].get(type) || []) { fn({ defaultPrevented: false, ...event }); }
    }
    async function settle() {
        for (let tick = 0; tick < 20; tick += 1) { await Promise.resolve(); }
    }
    async function advance(ms) {
        await settle();
        const end = elapsed + ms;
        let iterations = 0;
        while (true) {
            const due = [...tasks].filter(([, task]) => task.at <= end)
                .sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
            if (!due) { break; }
            if (++iterations > 100000) { throw new Error('Timer did not settle'); }
            const [key, task] = due;
            elapsed = task.at;
            if (task.repeat) { task.at += task.ms; } else { tasks.delete(key); }
            task.fn();
            await settle();
        }
        elapsed = end;
        await settle();
    }
    function events() {
        const seen = new Map();
        for (const request of [...requests, ...beacons]) {
            for (const event of request.payload.events) {
                seen.set(`${request.payload.session_uid}:${event.seq}`, { ...event, session: request.payload.session_uid });
            }
        }
        return [...seen.values()];
    }
    return {
        config, document, window, storage, requests, beacons, dispatch, advance, settle, events,
        respondWith(fn) { responder = fn; },
        shiftWall(ms) { wall += ms; },
        setGate(value) {
            gateValue = { ...gateValue, ...value };
            gateListeners.forEach(listener => listener(gate.state()));
        },
        pending() { return [...storage.values.entries()].filter(([key]) => key.startsWith('focus-tracker-outbox-v2:')); },
    };
}

test('site-asset JSON config starts tracking and legacy config remains supported', async () => {
    for (const legacy of [false, true]) {
        const app = runtime({ legacy });
        await app.settle();
        assert.equal(app.requests[0].payload.problem_id, 7);
        assert.equal(app.requests[0].payload.user_id, 11, 'server can reject requests from a stale page after an account switch');
        assert.equal(app.requests[0].payload.events[0].type, 'session_start');
        assert.match(app.requests[0].url, /_wpnonce=fresh-nonce$/);
        assert.equal(app.pending().length, 0, 'acknowledged initial event is removed');
    }
});

test('configured schedule gating fails closed before authorization and while the shared gate is missing', async () => {
    const missing = runtime({ config: { activityGate: { url: '/tracking/state' } } });
    missing.dispatch('document', 'input', { target: control('source') });
    missing.dispatch('document', 'submit', { target: control('form') });
    missing.dispatch('document', 'click', { target: control('run') });
    await missing.advance(70000);
    assert.equal(missing.requests.length, 0);
    assert.equal(missing.events().length, 0);
    const app = runtime({ gate: { active: false } });
    app.dispatch('document', 'input', { target: control('source') });
    await app.advance(20000);
    assert.equal(app.events().length, 0);
    app.setGate({ active: true, activeUntilMs: 1100000, validUntilMs: 1030000, contextType: 'group', contextId: '2' });
    await app.settle();
    assert.equal(app.events()[0].type, 'session_start');
    assert.equal(app.events()[0].occurred_at, 1020000, 'collection starts at confirmed activation, not page opening');
    assert.equal(app.requests[0].payload.context_type, 'group');
    assert.equal(app.requests[0].payload.context_id, '2');
});

test('schedule end commits eligible trailing input and an exact control boundary, then stops all collection until a fresh session', async () => {
    const app = runtime({ gate: { active: true, activeUntilMs: 1005000, validUntilMs: 1005000, contextType: 'class', contextId: '1' } });
    await app.advance(4500);
    app.dispatch('document', 'input', { target: control('source') });
    await app.advance(200);
    app.dispatch('document', 'input', { target: control('source') });
    await app.advance(300);
    app.setGate({ active: false, reason: 'expired' });
    await app.advance(1);
    const original = app.events();
    assert.equal(original.at(-1).type, 'session_end');
    assert.equal(original.at(-1).occurred_at, 1005000);
    assert.ok(original.some(event => event.type === 'activity' && event.occurred_at === 1004700), 'the final eligible edit retains its observation time');
    for (let count = 0; count < 10; count += 1) {
        app.dispatch('document', 'input', { target: control('source') });
        app.dispatch('document', 'submit', { target: control('form') });
        app.dispatch('document', 'click', { target: control('run') });
        await app.advance(1000);
    }
    assert.equal(app.events().length, original.length);
    app.setGate({ active: true, activeUntilMs: 1100000, validUntilMs: 1030000 });
    await app.settle();
    const starts = app.events().filter(event => event.type === 'session_start');
    assert.equal(starts.length, 2);
    assert.notEqual(starts[0].session, starts[1].session, 'resuming never extends the old physical/logical session across an unauthorized gap');
});

test('inactive windows still retry an eligible outbox with stable context and policy discard receipts do not mark delivery gaps', async () => {
    const app = runtime({ gate: { active: true, activeUntilMs: 1003000, validUntilMs: 1003000, contextType: 'group', contextId: '9' },
        responder: () => Promise.reject(new Error('offline')) });
    await app.settle();
    app.dispatch('document', 'input', { target: control('source') });
    await app.advance(1000);
    app.setGate({ active: false });
    const snapshot = app.pending().map(([, raw]) => JSON.parse(raw));
    assert.ok(snapshot.length > 0);
    assert.ok(snapshot.every(entry => entry.context_type === 'group' && entry.context_id === '9'));
    app.respondWith(() => Promise.resolve({ ok: true, json: async () => ({ acknowledged: true, discarded: 'outside_schedule' }) }));
    await app.advance(10000);
    assert.equal(app.pending().length, 0, 'a server policy receipt removes only its acknowledged eligible/filtered batch');
    app.setGate({ active: true, activeUntilMs: 1100000, validUntilMs: 1030000 });
    await app.settle();
    assert.ok(app.events().filter(event => event.type === 'session_start').every(event => !event.meta.queue_dropped));
    const replayed = app.requests.filter(request => request.payload.context_id === '9');
    assert.ok(replayed.length >= 2);
    assert.ok(replayed.every(request => request.payload.context_type === 'group'));
});

test('continuous input covers every 30-second window and pending final edit survives pagehide', async () => {
    const app = runtime();
    const field = control('source');
    for (let input = 0; input < 120; input += 1) {
        app.dispatch('document', 'input', { target: field });
        await app.advance(500);
    }
    app.dispatch('document', 'input', { target: field });
    await app.advance(100);
    app.dispatch('window', 'pagehide');
    const edits = app.events().filter(event => event.type === 'activity');
    assert.ok(edits.length >= 60, 'typing is sampled throughout the session');
    assert.ok(edits.some(event => event.occurred_at >= 1030000 && event.occurred_at < 1060000));
    assert.equal(Math.max(...edits.map(event => event.occurred_at)), 1060000, 'final input keeps its own timestamp');
    assert.equal(app.beacons.at(-1).payload.is_final, true);
    assert.ok(app.beacons.at(-1).payload.events.some(event => event.type === 'session_end'));
});

test('current platform forms, completion answers, dynamic source fields and run controls are tracked', async () => {
    const app = runtime({ readyState: 'loading' });
    app.dispatch('document', 'DOMContentLoaded');
    app.dispatch('document', 'input', { target: control('completion') });
    await app.advance(900);
    app.dispatch('document', 'input', { target: control('source') });
    app.dispatch('document', 'submit', { target: control('form') });
    await app.settle();
    app.dispatch('document', 'click', { target: control('run') });
    await app.advance(1);
    const types = app.events().map(event => event.type);
    assert.equal(types.filter(type => type === 'activity').length, 2);
    assert.ok(types.includes('submit'));
    assert.ok(types.includes('run'));
    assert.ok(!JSON.stringify(app.requests).includes('page_url'));
    assert.ok(!JSON.stringify(app.requests).includes('len_delta'));
});

test('inactive controls and cancelled form submissions do not produce learning events', async () => {
    const app = runtime();
    app.dispatch('document', 'input', { target: control('source', { hidden: true }) });
    app.dispatch('document', 'input', { target: control('other') });
    app.dispatch('document', 'submit', { target: control('form'), defaultPrevented: true });
    const button = control('run');
    button.disabled = true;
    app.dispatch('document', 'click', { target: button });
    await app.advance(8000);
    assert.deepEqual(app.events().map(event => event.type), ['session_start']);
});

test('an initially hidden tab and blurred visible window retain correct state on heartbeats', async () => {
    const hidden = runtime({ hidden: true });
    await hidden.advance(22000);
    for (const event of hidden.events()) {
        assert.equal(event.meta.visible, false);
        assert.equal(event.meta.focused, false);
    }
    assert.ok(hidden.events().some(event => event.type === 'heartbeat'));
    const app = runtime();
    app.document.focused = false;
    app.dispatch('window', 'blur');
    await app.advance(22000);
    const heartbeat = app.events().find(event => event.type === 'heartbeat');
    assert.deepEqual(heartbeat.meta, { visible: true, focused: false });
    app.dispatch('document', 'input', { target: control('source') });
    app.document.visibilityState = 'hidden';
    app.dispatch('document', 'visibilitychange');
    app.dispatch('window', 'focus');
    await app.advance(8000);
    assert.ok(!app.events().some(event => event.type === 'focus'));
    assert.ok(!app.events().some(event => event.type === 'activity'));
});

test('BFCache restoration starts a fresh session and suspends timers during page absence', async () => {
    const app = runtime();
    await app.advance(1000);
    app.dispatch('window', 'pagehide');
    const before = app.requests.length;
    await app.advance(100000);
    assert.equal(app.requests.length, before);
    app.dispatch('window', 'pageshow', { persisted: true });
    await app.advance(22000);
    const starts = app.events().filter(event => event.type === 'session_start');
    assert.equal(starts.length, 2);
    assert.notEqual(starts[0].session, starts[1].session);
    const oldEnd = app.events().find(event => event.type === 'session_end');
    assert.ok(app.events().filter(event => event.session === oldEnd.session).every(event => event.occurred_at <= oldEnd.occurred_at));
});

test('HTTP rejection and missing acknowledgement retain stable sequence IDs until retry succeeds', async () => {
    for (const response of [
        () => Promise.reject(new Error('offline')),
        () => Promise.resolve({ ok: false, json: async () => ({ acknowledged: true }) }),
        () => Promise.resolve({ ok: true, json: async () => ({ stored: 1 }) }),
    ]) {
        const app = runtime({ responder: response });
        await app.settle();
        assert.equal(app.pending().length, 1);
        const initial = app.requests[0].payload.events[0];
        app.respondWith(() => Promise.resolve({ ok: true, json: async () => ({ acknowledged: true }) }));
        await app.advance(2100);
        assert.deepEqual(app.requests[1].payload.events[0], initial);
        assert.equal(app.pending().length, 0);
    }
});

test('an acknowledgement only removes its snapshot while later events remain deliverable', async () => {
    let resolve;
    const app = runtime({ responder: () => new Promise(done => { resolve = done; }) });
    app.dispatch('document', 'input', { target: control('source') });
    assert.equal(app.pending().length, 2);
    app.respondWith(() => Promise.resolve({ ok: true, json: async () => ({ acknowledged: true }) }));
    resolve({ ok: true, json: async () => ({ acknowledged: true }) });
    await app.advance(1);
    assert.equal(app.requests[1].payload.events[0].seq, 2);
    assert.equal(app.requests[1].payload.events[0].type, 'activity');
    assert.equal(app.pending().length, 0);
});

test('a queued beacon is replayed after navigation and outboxes are isolated between users', async () => {
    const storage = new Storage();
    const first = runtime({ storage });
    await first.settle();
    first.dispatch('document', 'input', { target: control('source') });
    first.dispatch('window', 'pagehide');
    assert.equal(first.pending().length, 2, 'beacon acceptance must not delete unacknowledged events');
    const otherUser = runtime({ storage, config: { userId: 22 } });
    await otherUser.advance(1);
    assert.ok(otherUser.requests.every(request => request.payload.session_uid !== first.beacons[0].payload.session_uid));
    assert.equal(first.pending().length, 2);
    const nextPage = runtime({ storage });
    await nextPage.advance(1);
    assert.ok(nextPage.requests.some(request => request.payload.session_uid === first.beacons[0].payload.session_uid && request.payload.is_final));
    assert.equal(first.pending().length, 0);
});

test('server epoch and monotonic time prevent browser clock skew or adjustments from changing event timestamps', async () => {
    const app = runtime({ wall: 86400000, serverNow: 1000000 });
    await app.advance(5000);
    app.shiftWall(999999999);
    app.dispatch('document', 'submit', { target: control('form') });
    await app.advance(1);
    assert.equal(app.events().find(event => event.type === 'session_start').occurred_at, 1000000);
    assert.equal(app.events().find(event => event.type === 'submit').occurred_at, 1005000);
});

test('pending queue is bounded and subsequent state events disclose dropped samples', async () => {
    const app = runtime({ config: { heartbeatMs: 1, flushMs: 100000 }, responder: () => new Promise(() => {}) });
    await app.advance(1100);
    assert.equal(app.pending().length, 1000);
    assert.ok(app.pending().some(([, raw]) => JSON.parse(raw).event.meta.queue_dropped === true));
    const expired = new Storage();
    const prefix = 'focus-tracker-outbox-v2:' + encodeURIComponent('/wp-json/focus-tracker/v1/events') + ':11:';
    expired.setItem(prefix + 'expired:1', JSON.stringify({
        session_uid: 'expired', problem_id: 1, session_started_at: 1,
        queued_at: 1000000 - 86400001, event: { seq: 1, type: 'heartbeat', occurred_at: 1 },
    }));
    const fresh = runtime({ storage: expired });
    await fresh.settle();
    assert.ok(fresh.requests.every(request => request.payload.session_uid !== 'expired'));
});

test('blocked local storage still permits in-memory tracking and hung requests time out before retry', async () => {
    const blocked = { get length() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() {} };
    const app = runtime({ storage: blocked });
    app.dispatch('document', 'input', { target: control('source') });
    app.dispatch('window', 'pagehide');
    assert.ok(app.beacons[0].payload.events.some(event => event.type === 'activity'));
    const hung = runtime({ responder: () => new Promise(() => {}) });
    await hung.advance(18000);
    assert.equal(hung.requests.length, 2);
    assert.equal(hung.requests[0].payload.events[0].seq, hung.requests[1].payload.events[0].seq);
});

test('Blockly model changes count, while UI-only changes and flyout workspaces do not', async () => {
    let modelChange;
    const root = control('blockly');
    const workspace = { getParentSvg() { return root; }, addChangeListener(fn) { modelChange = fn; } };
    const flyout = { isFlyout: true, getParentSvg() { return root; }, addChangeListener() { throw new Error('flyout must not bind'); } };
    const app = runtime({ window: { Blockly: { common: { getAllWorkspaces() { return [workspace, flyout]; } } } } });
    modelChange({ isUiEvent: true, type: 'selected' });
    modelChange({ isUiEvent: false, type: 'finished_loading' });
    await app.advance(8000);
    assert.equal(app.events().filter(event => event.type === 'activity').length, 0);
    modelChange({ isUiEvent: false, type: 'change' });
    await app.advance(8000);
    assert.equal(app.events().find(event => event.type === 'activity').meta.subtype, 'blockly_edit');
});
