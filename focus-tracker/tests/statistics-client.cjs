'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../resources/js/statistics.js'), 'utf8');

class Element {
    constructor(tag) {
        this.tagName = tag.toLowerCase(); this.attributes = new Map(); this.children = [];
        this.parentNode = null; this.listeners = new Map(); this.className = ''; this.value = '';
        this.disabled = false; this._text = ''; this.hidden = false;
    }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    set textContent(value) { this._text = String(value); this.children = []; }
    set innerHTML(value) { throw new Error('Statistics text must not be interpreted as HTML: ' + value); }
    setAttribute(name, value) { this.attributes.set(name, String(value)); if (name === 'class') this.className = String(value); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    removeAttribute(name) { this.attributes.delete(name); }
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
    replaceChildren(...children) { this.children = []; this._text = ''; children.forEach(child => this.appendChild(child)); }
    addEventListener(type, handler) { const list = this.listeners.get(type) || []; list.push(handler); this.listeners.set(type, list); }
    fire(type, event = {}) { if (type !== 'click' || !this.disabled) (this.listeners.get(type) || []).forEach(handler => handler(event)); }
    matches(selector) {
        return selector.split(',').some(value => {
            const part = value.trim(); const tag = part.match(/^[a-z]+/);
            if (tag && tag[0] !== this.tagName) return false;
            for (const match of part.split('[')[0].matchAll(/\.([a-zA-Z0-9_-]+)/g)) if (!this.className.split(/\s+/).includes(match[1])) return false;
            for (const match of part.matchAll(/\[([a-zA-Z0-9_-]+)(?:="([^"]*)")?\]/g)) {
                const actual = this.getAttribute(match[1]);
                if (actual === null || (match[2] !== undefined && actual !== match[2])) return false;
            }
            return true;
        });
    }
    querySelectorAll(selector) {
        const found = []; const walk = parent => parent.children.forEach(child => { if (child.matches(selector)) found.push(child); walk(child); });
        walk(this); return found;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}

function totals(overrides = {}) {
    return { activeSeconds: 180, observedSeconds: 420, focusRatio: 3 / 7, editCount: 5, runCount: 2, submitCount: 1,
        checks: { correct: 1, incorrect: 1, unknown: 0, skipped: 1, unanswered: 2, pending: 0, invalidated: 0 },
        status: 'incomplete', hasObservationGaps: true, hasDeliveryGaps: false, coverageUnknown: false, ...overrides };
}
function data(overrides = {}) {
    const missing = { activeSeconds: null, observedSeconds: null, focusRatio: null, editCount: null, runCount: null, submitCount: null, status: 'no_data' };
    return {
        period: 'week', startDate: '2026-10-19', endDate: '2026-10-25', today: '2026-10-24', timeZone: 'Asia/Seoul', provisional: true,
        userId: 1, scope: { type: 'self', id: '' }, totals: totals(),
        days: [
            { date: '2026-10-19', ...totals({ activeSeconds: 120, observedSeconds: 240, focusRatio: 0.5, status: 'complete' }) },
            { date: '2026-10-20', ...missing },
            { date: '2026-10-21', ...totals({ activeSeconds: 0, observedSeconds: 120, focusRatio: 0, status: 'complete' }) },
            { date: '2026-10-22', ...totals({ activeSeconds: 60, observedSeconds: 60, focusRatio: 1 }) },
            { date: '2026-10-23', ...missing, status: 'unavailable' },
            { date: '2026-10-24', ...missing }, { date: '2026-10-25', ...missing },
        ],
        previous: { startDate: '2026-10-12', endDate: '2026-10-18', totals: totals() },
        delta: { activeSeconds: 60, observedSeconds: 180, focusRatio: 3 / 7 - 0.5 }, affectsFocusScore: false,
        ...overrides,
    };
}

function runtime(options = {}) {
    const root = new Element('section'); root.className = 'ftk-statistics';
    root.setAttribute('data-ftk-statistics', ''); root.setAttribute('data-mode', options.teacher ? 'teacher' : 'self');
    root.setAttribute('data-config', JSON.stringify({ restUrl: '/wp-json/focus-tracker/v1', nonce: 'nonce-value' }));
    const handlers = new Map(); const requests = []; const timers = new Map(); let taskId = 0; let elapsed = 0;
    let responder = options.responder || (() => Promise.resolve({ ok: true, json: async () => data() }));
    const document = {
        readyState: options.loading ? 'loading' : 'complete',
        querySelectorAll: selector => root.matches(selector) ? [root] : root.querySelectorAll(selector),
        createElement: tag => new Element(tag), createElementNS: (namespace, tag) => new Element(tag),
        addEventListener(type, handler) { const list = handlers.get(type) || []; list.push(handler); handlers.set(type, list); },
    };
    const window = { fetch(url, init) { const request = { url, ...init }; requests.push(request); return responder(request); } };
    const context = vm.createContext({ document, window, Number, Object, String, Array, Date, Math, JSON, Promise, encodeURIComponent,
        setTimeout(fn, ms) { const id = ++taskId; timers.set(id, { fn, at: elapsed + ms }); return id; }, clearTimeout: id => timers.delete(id),
    });
    vm.runInContext(source, context, { filename: 'statistics.js' });
    const settle = async () => { for (let count = 0; count < 25; count += 1) await Promise.resolve(); };
    return {
        root, requests, settle,
        event(type, detail) { (handlers.get(type) || []).forEach(handler => handler({ detail })); },
        change(name, value) { const element = root.querySelector(`[data-stat="${name}"]`); element.value = value; element.fire('change'); },
        click(name) { root.querySelector(`[data-stat="${name}"]`).fire('click'); },
        again() { vm.runInContext(source, context); }, respondWith(fn) { responder = fn; },
        async advance(ms) {
            const end = elapsed + ms;
            for (const [id, task] of [...timers].sort((a, b) => a[1].at - b[1].at)) {
                if (task.at <= end) { elapsed = task.at; timers.delete(id); task.fn(); await settle(); }
            }
            elapsed = end; await settle();
        },
    };
}
const response = value => Promise.resolve({ ok: true, json: async () => value });

test('self view fetches week using site-default date and nonce; ratios use fractions and checks stay separate', async () => {
    const app = runtime(); await app.settle();
    assert.equal(app.requests[0].url, '/wp-json/focus-tracker/v1/me/statistics?period=week');
    assert.equal(app.requests[0].headers['X-WP-Nonce'], 'nonce-value');
    assert.equal(app.requests[0].credentials, 'same-origin');
    assert.match(app.root.textContent, /42\.9%/);
    assert.match(app.root.textContent, /화면 활성 시간 \+1\.0분/);
    assert.match(app.root.textContent, /화면 활성률 -7\.1%p/);
    assert.match(app.root.textContent, /정답 확인 1건/);
    assert.match(app.root.textContent, /집중 점수와 별개/);
    assert.match(app.root.textContent, /일부 누락/);
    assert.equal(app.root.querySelector('[data-stat="date"]').value, '2026-10-24', 'site-local today anchors initial date rather than Monday');
    assert.equal(app.root.querySelector('[data-stat="next"]').disabled, true);
    app.change('date', '2026-10-25');
    assert.equal(app.requests.length, 1, 'typing a future date cannot change the displayed report');
    assert.equal(app.root.querySelector('[data-stat="date"]').value, '2026-10-24');
});

test('SVG and accessible table preserve missing days and actual zero values; line breaks at gaps', async () => {
    const app = runtime(); await app.settle();
    assert.equal(app.root.querySelectorAll('.ftk-stat-bar').length, 3, 'only observed days have bars');
    assert.equal(app.root.querySelectorAll('.ftk-stat-point').length, 3);
    assert.equal(app.root.querySelectorAll('.ftk-stat-line').length, 1, 'only adjacent Wednesday/Thursday form a segment');
    assert.equal(app.root.querySelector('.ftk-stat-line').getAttribute('points').split(' ').length, 2);
    const rows = app.root.querySelectorAll('[data-stat-day]');
    assert.equal(rows.length, 7);
    assert.match(rows[1].textContent, /———/);
    assert.match(rows[2].textContent, /0\.0분/);
    assert.match(rows[2].textContent, /0\.0%/);
    assert.match(rows[4].textContent, /상세 기록 확인 불가/);
    assert.equal(app.root.querySelector('svg').getAttribute('role'), 'img');
    assert.match(app.root.querySelector('svg').getAttribute('aria-label'), /관측 자료가 없는 날은 연결하지 않습니다/);
    assert.equal(app.root.querySelectorAll('caption').length, 1);
});

test('calendar month switching uses selected site date, previous month clamps to first day, and future controls stay disabled', async () => {
    const app = runtime(); await app.settle();
    app.respondWith(request => {
        const september = request.url.includes('2026-09-01');
        return response(data({ period: 'month', startDate: september ? '2026-09-01' : '2026-10-01', endDate: september ? '2026-09-30' : '2026-10-31' }));
    });
    app.change('period', 'month'); await app.settle();
    assert.match(app.requests.at(-1).url, /period=month&date=2026-10-24$/);
    assert.equal(app.root.querySelector('[data-stat="previous"]').textContent, '이전 달');
    const length = app.requests.length;
    app.click('next'); await app.settle();
    assert.equal(app.requests.length, length, 'future navigation cannot be triggered through a disabled control');
    app.click('previous'); await app.settle();
    assert.match(app.requests.at(-1).url, /date=2026-09-01$/);
    assert.equal(app.root.querySelector('[data-stat="next"]').disabled, false, 'navigation back into the current month is available');
});

test('teacher waits for an explicit selection and sends the current opaque context and student ID', async () => {
    const app = runtime({ teacher: true }); await app.settle();
    assert.equal(app.requests.length, 0);
    app.event('ftk:student-statistics', { userId: 2, displayName: '<img src=x>', contextType: 'group', contextId: 'group:한글/2' });
    await app.settle();
    const url = new URL(app.requests[0].url, 'https://local.test');
    assert.equal(url.pathname, '/wp-json/focus-tracker/v1/statistics');
    assert.equal(url.searchParams.get('context_id'), 'group:한글/2');
    assert.equal(url.searchParams.get('user_id'), '2');
    assert.equal(url.searchParams.get('context_type'), 'group');
    assert.match(app.root.querySelector('[data-stat="title"]').textContent, /<img src=x>/);
    assert.equal(app.root.querySelectorAll('img').length, 0);
});

test('self widget ignores teacher selections; malformed selection cannot issue unauthorized queries', async () => {
    const self = runtime(); await self.settle();
    self.event('ftk:student-statistics', { userId: 99, displayName: 'Other', contextType: 'class', contextId: 'class:1' });
    await self.settle(); assert.equal(self.requests.length, 1);
    const teacher = runtime({ teacher: true });
    for (const detail of [{}, { userId: 0, contextType: 'class', contextId: 'class:1' }, { userId: 2, contextType: 'contest', contextId: '1' }]) teacher.event('ftk:student-statistics', detail);
    await teacher.settle(); assert.equal(teacher.requests.length, 0);
});

test('changing student clears previous data immediately and stale responses cannot overwrite the selected student', async () => {
    const pending = []; const app = runtime({ teacher: true, responder: () => new Promise(resolve => pending.push(resolve)) });
    app.event('ftk:student-statistics', { userId: 1, displayName: 'One', contextType: 'group', contextId: 'group:1' });
    app.event('ftk:student-statistics', { userId: 2, displayName: 'Two', contextType: 'group', contextId: 'group:2' });
    assert.equal(app.root.querySelector('[data-stat="results"]').textContent, '');
    pending[1]({ ok: true, json: async () => data({ totals: totals({ editCount: 22 }) }) }); await app.settle();
    pending[0]({ ok: true, json: async () => data({ totals: totals({ editCount: 999 }) }) }); await app.settle();
    assert.match(app.root.textContent, /22회/);
    assert.ok(!app.root.textContent.includes('999회'));
    assert.match(app.root.querySelector('[data-stat="title"]').textContent, /Two/);
});

test('unavailable and archived evidence use dashes for missing counts and never manufacture percentages', async () => {
    const app = runtime({ responder: () => response(data({ totals: totals({ status: 'archived', activeSeconds: null, observedSeconds: null, focusRatio: null,
        editCount: null, runCount: null, submitCount: null, coverageUnknown: true }), delta: { activeSeconds: null, focusRatio: null } })) });
    await app.settle();
    assert.match(app.root.querySelector('[data-stat="message"]').textContent, /최종 집계만 보관/);
    assert.match(app.root.textContent, /비교할 관측 자료가 부족/);
    assert.equal(app.root.querySelectorAll('strong').filter(item => item.textContent === '—').length, 6);
});

test('HTTP errors and timeouts clear previous student results, report a recoverable failure and support refresh', async () => {
    const app = runtime(); await app.settle();
    app.respondWith(() => Promise.resolve({ ok: false, status: 403 }));
    app.click('refresh'); await app.settle();
    assert.equal(app.root.querySelector('[data-stat="results"]').textContent, '');
    assert.match(app.root.querySelector('[data-stat="message"]').textContent, /권한과 연결 상태/);
    app.respondWith(() => new Promise(() => {})); app.click('refresh'); await app.advance(15000);
    assert.match(app.root.querySelector('[data-stat="message"]').textContent, /불러오지 못했습니다/);
    app.respondWith(() => response(data())); app.click('refresh'); await app.settle();
    assert.equal(app.root.querySelectorAll('svg').length, 1);
    assert.equal(app.root.getAttribute('aria-busy'), null);
});

test('duplicate script execution and DOM readiness bind each widget once', async () => {
    const app = runtime({ loading: true }); app.again();
    assert.equal(app.requests.length, 0);
    app.event('DOMContentLoaded'); await app.settle();
    assert.equal(app.requests.length, 1);
    app.again(); await app.settle(); assert.equal(app.requests.length, 1);
});
