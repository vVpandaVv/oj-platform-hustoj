const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../resources/js/preview.js'), 'utf8');

async function run(response) {
    let click;
    const output = { textContent: '', replaceChildren(list) { this.list = list; } };
    const root = { dataset: { endpoint: 'https://school.test/wp-admin/admin-post.php', materialId: '1', nonce: 'diagnostic-nonce' },
        isConnected: true, querySelector() { return output; },
        closest() { return { querySelector() { return { lastExpiresAt: 123 }; } }; } };
    const button = { disabled: false, closest() { return root; } };
    let requests = 0;
    vm.runInNewContext(source, {
        document: { readyState: 'complete', documentElement: {}, querySelectorAll() { return []; },
            addEventListener(type, fn) { if (type === 'click') click = fn; },
            createElement() { return { children: [], appendChild(child) { this.children.push(child); } }; } },
        MutationObserver: class { observe() {} }, AbortController, URL, URLSearchParams, Date,
        setTimeout() { return 1; }, clearTimeout() {},
        async fetch(url, options) {
            requests++;
            assert.equal(options.body.get('action'), 'learning_materials_diagnose');
            assert.equal(options.body.get('nonce'), 'diagnostic-nonce');
            assert.equal(options.body.get('expires_at'), '123');
            assert.equal(options.credentials, 'same-origin');
            if (response instanceof Error) throw response;
            return response;
        }
    });
    const event = { target: { closest() { return button; } } };
    click(event);
    click(event);
    await new Promise(setImmediate);
    assert.equal(requests, 1, 'diagnostics must not duplicate pending requests');
    assert.equal(button.disabled, false);
    return output;
}
(async () => {
    const output = await run({ ok: true, json: async () => ({ success: true, data: { checks: [
        { status: 'ok', label: '원본', message: '정상' }, { status: 'error', label: '<script>', message: '오류' }
    ] } }) });
    assert.equal(output.list.children.length, 2);
    assert.match(output.list.children[1].textContent, /<script>/);
    assert.equal(output.list.children[1].innerHTML, undefined, 'render text, not server HTML');
    for (const response of [new Error('network'), { ok: false, json: async () => ({ success: false }) }]) {
        assert.match((await run(response)).textContent, /진단하지 못했습니다/);
    }
    console.log('Diagnostics UI: request credentials, duplicate prevention, safe rendering and failures passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
