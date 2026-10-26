'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const gateSource = fs.readFileSync(path.join(__dirname, '../resources/js/activity-gate.js'), 'utf8');
const trackerSource = fs.readFileSync(path.join(__dirname, '../resources/js/tracker.js'), 'utf8');

class StatusElement {
    constructor(tag) { this.tagName = tag; this.children = []; this.attributes = {}; this._text = ''; this.hidden = false; }
    setAttribute(key, value) { this.attributes[key] = String(value); }
    getAttribute(key) { return this.attributes[key] || null; }
    appendChild(child) { this.children.push(child); child.parentNode = this; return child; }
    set textContent(value) { this._text = value; }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    querySelector(selector) { const key = selector.slice(1, -1); return this.children.find(child => key in child.attributes) || this.children.map(child => child.querySelector(selector)).find(Boolean) || null; }
}

function form(problemId, fields = {}) {
    return {
        closest: () => null,
        querySelector(selector) {
            if (selector === 'input[name="problem_id"]') return { value: String(problemId) };
            for (const [kind, value] of Object.entries(fields)) if (selector === `input[name="${kind}_id"]`) return { value: String(value) };
            if (selector.includes('source')) throw new Error('Gate must never read source contents');
            return null;
        },
    };
}
function control(kind = 'source') {
    return { closest(selector) {
        if (selector === '[hidden]') return null;
        if (kind === 'source' && selector.includes('textarea[name="source"]')) return this;
        if (kind === 'form' && selector.includes('form[data-oj-submission-form]')) return this;
        return null;
    }, get value() { throw new Error('Tracking must not inspect draft text'); } };
}
function runtime(options = {}) {
    let elapsed = 0; let wall = options.wall ?? 1000000; let taskId = 0; let uuid = 0;
    const serverBase = options.serverBase ?? 1000000;
    const tasks = new Map(); const requests = []; const beacons = []; const storage = new Map();
    const handlers = { document: new Map(), window: new Map() };
    const windows = options.windows || [{ activityId: 1, startMs: serverBase, endMs: serverBase + 60000, contextType: 'class', contextId: 'class:1' }];
    function policy() {
        const clock = serverBase + elapsed;
        const activeWindows = windows.filter(item => item.startMs <= clock && clock < item.endMs);
        const end = activeWindows.length ? Math.max(...activeWindows.map(item => item.endMs)) : null;
        const future = windows.map(item => item.startMs).filter(value => value > clock);
        const next = future.length ? Math.min(...future) : null;
        return { serverNowMs: clock, active: activeWindows.length > 0, windows, nextStartsAtMs: next,
            activeUntilMs: end, validUntilMs: Math.min(clock + 10000, end || Infinity, next || Infinity),
            windowStartsAtMs: activeWindows.length ? Math.min(...activeWindows.map(item => item.startMs)) : null,
        };
    }
    let responder = options.responder || (() => Promise.resolve({ ok: true, json: async () => policy() }));
    const config = { userId: 11, problemId: 7, serverNowMs: options.initialServer ?? serverBase,
        restUrl: '/events', nonce: 'nonce-value', activityGate: { url: '/tracking/state', pollMs: 5000, timeoutMs: 5000 }, noticeText: null,
        ...options.config };
    function listen(target, type, fn) { const list = handlers[target].get(type) || []; list.push(fn); handlers[target].set(type, list); }
    const document = {
        readyState: options.loading ? 'loading' : 'complete', visibilityState: options.hidden ? 'hidden' : 'visible',
        focused: true, hasFocus() { return this.focused; },
        getElementById(id) { return id === 'focus-tracker-config' ? { textContent: JSON.stringify(config) } : null; },
        querySelectorAll(selector) { return selector === 'form[data-oj-submission-form]' ? options.forms || [form(7, { class: 1 })] : []; },
        querySelector() { return null; }, addEventListener(type, fn) { listen('document', type, fn); },
    };
    if (options.dom) { document.body = new StatusElement('body'); document.createElement = tag => new StatusElement(tag); }
    const window = {
        performance: { now: () => elapsed }, crypto: { randomUUID: () => 'session-' + (++uuid) },
        localStorage: {
            get length() { return storage.size; }, key(index) { return [...storage.keys()][index] || null; },
            getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key),
        },
        addEventListener(type, fn) { listen('window', type, fn); },
        fetch(url, init) {
            const request = { url, ...init, payload: JSON.parse(init.body) }; requests.push(request);
            return url === '/tracking/state' ? responder(request) : Promise.resolve({ ok: true, json: async () => ({ acknowledged: true }) });
        },
    };
    class TestDate extends Date { static now() { return wall + elapsed; } }
    const context = vm.createContext({ document, window, Date: TestDate, Intl, Promise, Object, Array, Number, Math, JSON, String, encodeURIComponent,
        navigator: { sendBeacon(url, body) { beacons.push({ url, payload: JSON.parse(body.body) }); return true; } },
        Blob: class { constructor(parts) { this.body = parts.join(''); } },
        setTimeout(fn, ms) { const id = ++taskId; tasks.set(id, { fn, at: elapsed + ms, repeat: false }); return id; },
        clearTimeout: id => tasks.delete(id),
        setInterval(fn, ms) { const id = ++taskId; tasks.set(id, { fn, at: elapsed + ms, repeat: ms }); return id; },
        clearInterval: id => tasks.delete(id),
    });
    vm.runInContext(gateSource, context, { filename: 'activity-gate.js' });
    if (options.tracker) vm.runInContext(trackerSource, context, { filename: 'tracker.js' });
    const settle = async () => { for (let count = 0; count < 30; count += 1) await Promise.resolve(); };
    return {
        config, window, document, requests, beacons, policy, settle,
        gate: window.FocusTrackerActivityGate,
        respondWith(fn) { responder = fn; }, shiftWall(ms) { wall += ms; },
        dispatch(target, type, event = {}) { (handlers[target].get(type) || []).forEach(fn => fn({ defaultPrevented: false, ...event })); },
        async advance(ms) {
            await settle(); const end = elapsed + ms; let iterations = 0;
            while (true) {
                const due = [...tasks].filter(([, task]) => task.at <= end).sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
                if (!due) break; assert.ok(++iterations < 100000, 'timers must not loop at an expired boundary');
                elapsed = due[1].at;
                if (due[1].repeat) due[1].at += due[1].repeat; else tasks.delete(due[0]);
                due[1].fn(); await settle();
            }
            elapsed = end; await settle();
        },
        events() {
            const values = new Map();
            [...requests, ...beacons].filter(item => item.url === '/events' || item.url.startsWith('/events?')).forEach(item => {
                item.payload.events.forEach(event => values.set(item.payload.session_uid + ':' + event.seq, { ...event, session: item.payload.session_uid }));
            }); return [...values.values()];
        },
    };
}
const response = data => Promise.resolve({ ok: true, json: async () => data });

test('persistent activity status distinguishes no schedule, waiting, recording, ended and connection failure without focus operations', async () => {
    const none = runtime({ dom: true, windows: [] }); await none.settle();
    const emptyStatus = none.document.body.querySelector('[data-ftk-activity-status]');
    assert.equal(emptyStatus.getAttribute('role'), 'status');
    assert.equal(emptyStatus.getAttribute('aria-live'), 'polite');
    assert.match(emptyStatus.textContent, /활동 없음/);
    const app = runtime({ dom: true, windows: [{ activityId: 1, startMs: 1002000, endMs: 1008000 }] }); await app.settle();
    const status = app.document.body.querySelector('[data-ftk-activity-status]');
    assert.match(status.textContent, /시작 대기/); assert.match(status.textContent, /다음 시작/);
    await app.advance(2000); assert.match(status.textContent, /기록 중/); assert.match(status.textContent, /예정 종료/);
    await app.advance(6000); assert.match(status.textContent, /활동 종료/); assert.doesNotMatch(status.textContent, /연결 오류/);
    assert.equal(app.document.body.children.length, 1, 'gate state survives independent of any dismissible privacy notice');
    const offline = runtime({ dom: true }); await offline.settle();
    offline.respondWith(() => Promise.reject(new Error('offline'))); await offline.advance(5000);
    assert.match(offline.document.body.querySelector('[data-ftk-activity-status]').textContent, /연결 오류로 중단/);
});

test('initial state fails closed, waits for DOM context and sends only matching problem/scope identity', async () => {
    const app = runtime({ loading: true, forms: [form(99, { contest: 99 }), form(7, { group: 2, class: 3, contest: 4 })] });
    const changes = []; app.gate.subscribe(value => changes.push(value));
    assert.equal(changes[0].active, false); assert.equal(app.requests.length, 0);
    app.dispatch('document', 'DOMContentLoaded'); await app.settle();
    assert.deepEqual(app.requests[0].payload, { user_id: 11, problem_id: 7, context_type: 'contest', context_id: '4' });
    assert.equal(app.requests[0].headers['X-WP-Nonce'], 'nonce-value');
    assert.equal(app.gate.state().active, true);
    assert.equal(app.gate.state().contextType, 'contest');
    assert.ok(!JSON.stringify(app.requests).includes('source'));
});

test('a cached future schedule never opens collection until a fresh start probe; a known end closes exactly without waiting for polling', async () => {
    const app = runtime({ tracker: true, windows: [{ activityId: 1, startMs: 1002000, endMs: 1008000 }] });
    await app.settle(); app.dispatch('document', 'input', { target: control() });
    await app.advance(1999); assert.equal(app.gate.state().active, false); assert.equal(app.events().length, 0);
    await app.advance(1); assert.equal(app.gate.state().active, true);
    assert.equal(app.events()[0].occurred_at, 1002000);
    app.dispatch('document', 'input', { target: control() });
    await app.advance(6000); assert.equal(app.gate.state().active, false);
    const end = app.events().find(event => event.type === 'session_end');
    assert.equal(end.occurred_at, 1008000);
    const prior = app.events().length;
    app.dispatch('document', 'input', { target: control() }); app.dispatch('document', 'submit', { target: control('form') });
    await app.advance(20000); assert.equal(app.events().length, prior);
    assert.ok(app.events().filter(event => event.type !== 'session_end').every(event => event.occurred_at >= 1002000 && event.occurred_at < 1008000));
});

test('ordinary short-lease renewals preserve one continuous run and one tracker session', async () => {
    const app = runtime({ tracker: true }); await app.settle(); const run = app.gate.state().runKey;
    await app.advance(22000);
    assert.equal(app.gate.state().active, true); assert.equal(app.gate.state().runKey, run);
    assert.equal(app.events().filter(event => event.type === 'session_start').length, 1);
    assert.ok(app.events().some(event => event.type === 'heartbeat'));
    assert.ok(app.requests.filter(item => item.url === '/tracking/state').length >= 4);
});

test('a failed renewal closes the gate, and a hung renewal cannot survive the ten-second lease', async () => {
    const failed = runtime({ tracker: true }); await failed.settle();
    failed.respondWith(() => Promise.reject(new Error('offline'))); await failed.advance(5000);
    assert.equal(failed.gate.state().active, false);
    const hung = runtime({ tracker: true }); await hung.settle();
    hung.respondWith(() => new Promise(() => {})); await hung.advance(10000);
    assert.equal(hung.gate.state().active, false);
    assert.ok(hung.events().filter(event => event.type !== 'session_end').every(event => event.occurred_at < 1010000));
    assert.ok(hung.events().some(event => event.type === 'session_end' && event.occurred_at <= 1010000));
});

test('authorized hidden observations remain measurable; visible/online/BFCache resume require a fresh lease and logical session', async () => {
    const app = runtime({ tracker: true }); await app.settle(); const oldRun = app.gate.state().runKey;
    app.document.visibilityState = 'hidden'; app.dispatch('document', 'visibilitychange');
    assert.equal(app.gate.state().active, true); const before = app.requests.length;
    await app.advance(16000);
    assert.ok(app.requests.filter(item => item.url === '/tracking/state').length >= 4);
    assert.ok(app.events().some(event => event.type === 'hidden' && event.meta.visible === false));
    app.dispatch('document', 'input', { target: control() });
    assert.ok(!app.events().some(event => event.type === 'activity'), 'typing while hidden does not become a learning action');
    let accept; app.respondWith(() => new Promise(resolve => { accept = resolve; }));
    app.document.visibilityState = 'visible'; app.dispatch('document', 'visibilitychange'); await app.settle();
    assert.equal(app.gate.state().active, false, 'visibility alone cannot resurrect a cached lease');
    accept({ ok: true, json: async () => app.policy() }); await app.settle();
    assert.equal(app.gate.state().active, true); assert.notEqual(app.gate.state().runKey, oldRun);
    assert.ok(app.requests.length > before);
    app.dispatch('window', 'pagehide'); await app.advance(1000);
    app.dispatch('window', 'pageshow', { persisted: true }); await app.settle();
    assert.equal(app.gate.state().active, false);
    accept({ ok: true, json: async () => app.policy() }); await app.settle();
    assert.equal(app.events().filter(event => event.type === 'session_start').length, 3);
    app.dispatch('window', 'online'); assert.equal(app.gate.state().active, false);
});

test('wall-clock skew and adjustments cannot extend the server window or lease', async () => {
    const app = runtime({ tracker: true, wall: 864000000, initialServer: 900000000 }); await app.settle();
    assert.equal(app.gate.now(), 1000000);
    await app.advance(1000); app.shiftWall(999999999);
    assert.equal(app.gate.now(), 1001000);
    app.dispatch('document', 'input', { target: control() }); await app.advance(1000);
    assert.ok(app.events().every(event => event.occurred_at >= 1000000 && event.occurred_at <= 1002000));
});

test('a response delayed beyond its authorization cannot revive the gate after a known deadline', async () => {
    let accept;
    const app = runtime({ tracker: true, windows: [{ activityId: 1, startMs: 1000000, endMs: 1002000 }],
        responder: () => new Promise(resolve => { accept = resolve; }) });
    const stale = app.policy(); await app.advance(3000);
    accept({ ok: true, json: async () => stale }); await app.settle();
    assert.equal(app.gate.state().active, false);
    assert.equal(app.events().length, 0);
});

test('a stale in-flight response cannot reactivate after page suspension, while a resume immediately requests fresh state', async () => {
    const pending = []; const app = runtime({ responder: () => new Promise(resolve => pending.push(resolve)) });
    const stale = app.policy(); app.dispatch('window', 'pagehide');
    app.dispatch('window', 'pageshow', { persisted: true });
    pending[0]({ ok: true, json: async () => stale }); await app.settle();
    assert.equal(app.gate.state().active, false); assert.equal(pending.length, 2);
    pending[1]({ ok: true, json: async () => app.policy() }); await app.settle();
    assert.equal(app.gate.state().active, true);
});

test('malformed, unauthorized and unavailable responses fail closed without collecting or repeatedly retrying a stale account', async () => {
    for (const value of [{}, { serverNowMs: 1000000, active: true, validUntilMs: 1010000, windows: [] }]) {
        const app = runtime({ tracker: true, responder: () => response(value) }); await app.settle();
        assert.equal(app.gate.state().active, false); assert.equal(app.events().length, 0);
    }
    const denied = runtime({ tracker: true, responder: () => Promise.resolve({ ok: false, status: 403 }) }); await denied.advance(20000);
    assert.equal(denied.requests.length, 1); assert.equal(denied.events().length, 0);
    denied.dispatch('window', 'online'); await denied.advance(10000); assert.equal(denied.requests.length, 1);
});
