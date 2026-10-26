const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../resources/js/preview.js'), 'utf8');

async function scenario(fetchResult, loading = false, messages = {}) {
    let click;
    let ready;
    let mutation;
    let fetchCount = 0;
    const timers = new Map();
    let nextTimer = 0;
    const status = { textContent: '' };
    const newWindow = { hidden: true, href: '' };
    const container = { children: [], replaceChildren() { this.children = []; }, appendChild(frame) { this.children.push(frame); } };
    const root = {
        dataset: { endpoint: 'https://school.test/wp-admin/admin-post.php', materialId: '7', nonce: 'nonce', ...messages },
        isConnected: true,
        querySelector(selector) { return selector === '.lmt-preview-status' ? status : selector === '.lmt-preview-open' ? button : selector === '.lmt-preview-new-window' ? newWindow : container; }
    };
    const button = { disabled: false, closest() { return root; } };
    const context = {
        document: {
            readyState: loading ? 'loading' : 'complete',
            documentElement: {},
            querySelectorAll() { return [root]; },
            addEventListener(type, handler) { if (type === 'click') click = handler; else ready = handler; },
            createElement() { return {
                listeners: {}, attributes: {}, contentWindow: { location: { href: 'about:blank' } },
                setAttribute(name, value) { this.attributes[name] = value; },
                addEventListener(type, handler) { this.listeners[type] = handler; }
            }; }
        },
        URL, URLSearchParams, AbortController, Date,
        MutationObserver: class { constructor(callback) { mutation = callback; } observe() {} },
        setTimeout(handler, delay) { timers.set(++nextTimer, { handler, delay }); return nextTimer; },
        clearTimeout(id) { timers.delete(id); },
        async fetch(url, options) {
            fetchCount++;
            assert.equal(options.method, 'POST');
            assert.equal(options.credentials, 'same-origin');
            assert.equal(options.body.get('nonce'), 'nonce');
            if (fetchResult instanceof Error) throw fetchResult;
            return fetchResult;
        }
    };
    vm.runInNewContext(source, context);
    const event = { target: { closest(selector) { return selector.includes('diagnostic') ? null : button; } } };
    if (loading) {
        assert.equal(fetchCount, 0);
        ready();
    }
    await new Promise(setImmediate);
    assert.equal(fetchCount, 1, 'automatically starts without clicking');
    mutation([{ addedNodes: [{}] }]);
    assert.equal(fetchCount, 1, 'DOM changes do not duplicate requests');
    return { status, container, button, newWindow, timers, root, retry: async () => { await click(event); await new Promise(setImmediate); } };
}

(async () => {
    const success = { ok: true, json: async () => ({ success: true, data: {
        viewer_url: 'https://docs.google.com/gview?embedded=true&url=https%3A%2F%2Fschool.test%2Ffile',
        expires_at: Math.floor(Date.now() / 1000) + 600
    } }) };
    const state = await scenario(success);
    assert.equal(state.container.children.length, 1);
    assert.equal(state.container.children[0].referrerPolicy, 'no-referrer');
    assert.equal(new URL(state.container.children[0].src).searchParams.get('embedded'), 'true');
    assert.ok(state.container.children[0].attributes.sandbox.includes('allow-scripts'));
    assert.ok(!state.container.children[0].attributes.sandbox.includes('allow-downloads'));
    assert.equal(state.button.disabled, false);
    assert.equal(state.newWindow.hidden, false);
    assert.equal(state.newWindow.href, state.container.children[0].src);
    const initialFrame = state.container.children[0];
    initialFrame.listeners.load();
    assert.ok(state.timers.has(state.root.previewTimer), 'blank load must not cancel retry');
    state.timers.get(state.root.previewTimer).handler();
    assert.notEqual(state.container.children[0], initialFrame);
    assert.equal(state.container.children[0].src, initialFrame.src, 'retry reuses signed URL');
    state.timers.get(state.root.previewTimer).handler();
    state.timers.get(state.root.previewTimer).handler();
    assert.match(state.status.textContent, /원본.*다운로드/);
    await state.retry();
    assert.equal(state.container.children.length, 1);
    state.container.children[0].listeners.error();
    state.container.children[0].listeners.error();
    state.container.children[0].listeners.error();
    assert.equal(state.container.children.length, 1);
    assert.match(state.status.textContent, /불러오지 못했습니다/);
    const loaded = await scenario(success, true);
    Object.defineProperty(loaded.container.children[0].contentWindow, 'location', {
        get() { const error = new Error('cross-origin'); error.name = 'SecurityError'; throw error; }
    });
    loaded.container.children[0].listeners.load();
    assert.equal(loaded.status.textContent, '');
    assert.equal(loaded.timers.has(loaded.root.previewTimer), false);
    for (const viewer of ['https://docs.google.com/document/d/test_123/preview',
        'https://docs.google.com/presentation/d/test_123/embed',
        'https://docs.google.com/spreadsheets/d/test_123/preview?gid=42',
        'https://drive.google.com/file/d/test_123/preview',
        'https://docs.google.com/document/d/e/test_123/pub?embedded=true']) {
        const shared = await scenario({ ok: true, json: async () => ({ success: true, data: { viewer_url: viewer } }) });
        assert.equal(shared.container.children[0].src, viewer);
    }
    for (const viewer of ['https://evil.test/gview', 'https://docs.google.com.evil.test/gview',
        'javascript:alert(1)', 'https://docs.google.com/forms/d/abc/edit']) {
        const rejected = await scenario({ ok: true, json: async () => ({ success: true, data: { viewer_url: viewer } }) });
        assert.equal(rejected.container.children.length, 0);
    }
    const linkFailed = await scenario(new Error('network'), false, { failureMessage: 'Google에서 열어 주세요.' });
    assert.equal(linkFailed.status.textContent, 'Google에서 열어 주세요.');
    const detached = await scenario(success);
    const detachedFrame = detached.container.children[0];
    detached.root.isConnected = false;
    detached.timers.get(detached.root.previewTimer).handler();
    assert.equal(detached.container.children[0], detachedFrame, 'detached pages must not retry');
    for (const result of [new Error('network'), { ok: false, json: async () => ({ success: false }) },
        { ok: true, json: async () => { throw new Error('login HTML'); } }]) {
        const failed = await scenario(result);
        assert.equal(failed.container.children.length, 0);
        assert.equal(failed.button.disabled, false);
        assert.match(failed.status.textContent, /원본.*다운로드/);
    }
    console.log('Preview UI: automatic load, DOM readiness, duplicate prevention, retry, timeout, iframe error and request failures passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
