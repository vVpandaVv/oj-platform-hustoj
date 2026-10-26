import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const frontend = process.env.OJ_ML_UPSTREAM_FRONTEND;
assert(frontend, 'Set OJ_ML_UPSTREAM_FRONTEND to the installed local frontend test runtime.');
const { JSDOM } = createRequire(path.join(frontend, 'package.json'))('jsdom');
const source = readFileSync(new URL('../resources/js/addon.js', import.meta.url), 'utf8');
const workflow = readFileSync(new URL('../resources/js/teacher-workflow.js', import.meta.url), 'utf8');
const closing = source.lastIndexOf('}());');
assert(closing > 0);
const exposed = source.slice(0, closing) + 'window.settingsTest = { renderConsoleSettings };\n' + source.slice(closing);
const plain = value => JSON.parse(JSON.stringify(value));
const flush = () => new Promise(resolve => setImmediate(resolve));
const settings = {
    maxArtifactBytes: 32 * 1048576, siteQuotaBytes: 12 * 1073741824, minimumFreeBytes: 768 * 1048576,
    inspectionSlots: 3, retentionDays: 365, maintenanceMode: false,
    labFocusOnStart: true, labGuidanceCollapsed: false, retentionHold: true,
    allowPersonalErasure: false, privacyUrl: 'https://school.example/privacy',
};
const health = { ok: true, version: 'test', schemaVersion: 7, runtime: { state: 'ready', code: '' },
    checks: [{ label: 'PHP ZIP', status: 'ok', message: '사용 가능' }], metrics: { stored_bytes: 2097152, pending: 0, needs_operator: 0 } };

async function fixture({ canManage = true, overrides = {}, healthOverrides = {}, localNav = false } = {}) {
    const dom = new JSDOM('<!doctype html><body><main id="test-root"></main></body>', {
        runScripts: 'outside-only', url: 'https://school.example/oj-console/addons/oj-ml-playgrounds/settings',
    });
    const { window } = dom;
    const root = window.document.querySelector('main');
    Object.assign(root.dataset, { canManage: canManage ? '1' : '0', canReport: '0', canSettings: '1',
        consoleAssignmentsUrl: '/assignments', consoleResultsUrl: '/results', consoleSettingsUrl: '/settings',
        platformLocalNav: localNav ? '1' : '0', privacySource: 'platform', privacyUrl: 'https://school.example/site-policy' });
    const calls = [], confirmations = [], toasts = [];
    const state = { allow: true, fail: false, settings: { ...settings, ...overrides } };
    window.OJP = {
        confirm: async value => { confirmations.push(plain(value)); return state.allow; },
        toast: message => { toasts.push(message); },
    };
    const api = {
        async get(endpoint) {
            calls.push({ method: 'GET', endpoint });
            if (endpoint === 'console/health') return { health: { ...health, ...healthOverrides } };
            if (endpoint === 'console/settings') return { settings: state.settings };
            throw new Error('Unexpected GET ' + endpoint);
        },
        async post(endpoint, payload) {
            calls.push({ method: 'POST', endpoint, payload: plain(payload) });
            if (state.fail) throw new Error('설정 저장 실패');
            assert.equal(endpoint, 'console/settings');
            return { settings: plain(payload) };
        },
    };
    window.eval(workflow);
    window.eval(exposed);
    await window.settingsTest.renderConsoleSettings(root, api);
    return { dom, window, root, calls, confirmations, toasts, state,
        form: root.querySelector('[data-settings]'), status: root.querySelector('[data-oj-ml-status]') };
}

async function submit(fx) {
    fx.form.dispatchEvent(new fx.window.Event('submit', { bubbles: true, cancelable: true }));
    await flush();
}

const fixtures = [];
try {
    const fx = await fixture(); fixtures.push(fx);
    const { root, form } = fx;
    assert.deepEqual(fx.calls.map(call => call.endpoint), ['console/health', 'console/settings']);
    assert.equal(root.querySelectorAll('form').length, 1, 'Settings sections must share one save form, not nested forms.');
    assert.equal(root.querySelector('[data-settings-section="health"]').open, false);
    for (const key of ['capacity', 'retention', 'maintenance']) {
        assert.equal(root.querySelector(`[data-settings-section="${key}"]`).open, false, 'Non-urgent sections start collapsed: ' + key);
    }
    assert.match(form.querySelector('legend').textContent, /실습 화면 기본 보기/);
    assert.match(root.textContent, /그룹·일정·평가 기준·포트폴리오는 각 과제/);
    assert.match(root.textContent, /AI 연결 없이도 교사 직접 평가/);
    const help = form.querySelector('.oj-ml-help');
    assert(help && !help.open, 'Long operating instructions stay in optional help, not above everyday settings.');
    assert.match(help.textContent, /AI 연결 없이도 교사 직접 평가/);
    assert(root.querySelector('.oj-ml-settings-intro').textContent.length < 70, 'Settings heading must stay concise.');
    assert(root.querySelector('.oj-ml-settings-intro a[href="/assignments"]'));
    assert.equal(form.elements.maxArtifactMiB.value, '32');
    assert.equal(form.elements.siteQuotaGiB.value, '12');
    assert.equal(form.elements.minimumFreeMiB.value, '768');
    assert.equal(form.elements.inspectionSlots.value, '3');
    assert.equal(form.elements.retentionDays.value, '365');
    for (const key of ['maintenanceMode', 'labFocusOnStart', 'labGuidanceCollapsed', 'retentionHold', 'allowPersonalErasure']) {
        assert.equal(form.elements[key].checked, settings[key], 'Existing boolean value must remain unchanged: ' + key);
    }
    assert.equal(form.elements.privacyUrl.value, settings.privacyUrl);
    assert.match(root.querySelector('[data-settings-section="retention"]').textContent, /사이트 개인정보 처리방침/);
    assert.match(root.querySelector('[data-settings-section="retention"]').textContent, /https:\/\/school.example\/site-policy/);
    await submit(fx);
    assert.deepEqual(fx.calls.at(-1), { method: 'POST', endpoint: 'console/settings', payload: settings },
        'Reorganized sections must preserve every field and byte-unit conversion on an unchanged save.');
    assert.equal(fx.confirmations.length, 0, 'Retention hold with erasure disabled is not a destructive change.');
    assert.equal(fx.status.dataset.kind, 'success');
    assert.equal(form.querySelector('button[type="submit"]').disabled, false);
    assert.equal(fx.toasts.length, 1);

    const requestCount = fx.calls.length;
    const capacity = root.querySelector('[data-settings-section="capacity"]');
    capacity.open = false;
    form.elements.inspectionSlots.value = '9';
    await submit(fx);
    assert.equal(fx.calls.length, requestCount, 'Invalid hidden input must stop API submission.');
    assert.equal(capacity.open, true, 'Validation must reveal the collapsed section containing the invalid control.');
    form.elements.inspectionSlots.value = '3';
    const retention = root.querySelector('[data-settings-section="retention"]');
    retention.open = false;
    form.elements.privacyUrl.value = 'invalid-url';
    await submit(fx);
    assert.equal(fx.calls.length, requestCount);
    assert.equal(retention.open, true, 'Hidden privacy input errors must also become reachable.');
    form.elements.privacyUrl.value = settings.privacyUrl;

    fx.state.allow = false;
    form.elements.retentionHold.checked = false;
    form.elements.retentionDays.value = '90';
    await submit(fx);
    assert.equal(fx.calls.length, requestCount, 'Cancelling retention-hold removal must not save or invoke deletion.');
    assert.equal(form.elements.retentionHold.checked, false, 'Cancelled confirmation preserves editable unsaved choices.');
    assert.equal(form.elements.retentionDays.value, '90');
    assert.equal(fx.confirmations.at(-1).destructive, true);
    assert.match(fx.confirmations.at(-1).message, /보존 정책과 백업/);
    assert.equal(form.querySelector('button[type="submit"]').disabled, false);
    form.elements.retentionHold.checked = true;
    form.elements.allowPersonalErasure.checked = true;
    await submit(fx);
    assert.equal(fx.calls.length, requestCount, 'Enabling personal erasure also requires an affirmative confirmation.');

    fx.state.allow = true;
    form.elements.labFocusOnStart.checked = false;
    form.elements.labGuidanceCollapsed.checked = true;
    form.elements.maintenanceMode.checked = true;
    await submit(fx);
    assert.deepEqual(fx.calls.at(-1).payload, { ...settings, retentionDays: 90,
        allowPersonalErasure: true, labFocusOnStart: false, labGuidanceCollapsed: true, maintenanceMode: true });
    fx.state.fail = true;
    form.elements.retentionDays.value = '120';
    await submit(fx);
    assert.equal(form.elements.retentionDays.value, '120', 'Failed saves preserve typed settings.');
    assert.equal(form.querySelector('button[type="submit"]').disabled, false);
    assert.equal(fx.status.dataset.kind, 'error');
    assert.match(fx.status.textContent, /설정 저장 실패/);

    const restricted = await fixture({ canManage: false }); fixtures.push(restricted);
    assert.equal(restricted.root.querySelector('a[href="/assignments"]'), null,
        'Settings-only users must not see an unauthorized assignment creation link in either intro or navigation.');
    assert.equal(restricted.root.querySelector('a[href="/results"]'), null);
    assert(restricted.root.querySelector('a[href="/settings"]'));
    const urgent = await fixture({ localNav: true, overrides: { maintenanceMode: true }, healthOverrides: {
        ok: false, checks: [{ label: '<img src=x>', status: 'error', message: '<script>bad()</script>' }],
    } }); fixtures.push(urgent);
    assert.equal(urgent.root.querySelector('[data-settings-section="health"]').open, true);
    assert.equal(urgent.root.querySelector('[data-settings-section="maintenance"]').open, true);
    assert.equal(urgent.root.querySelector('.oj-ml-workflow-nav'), null, 'Platform local navigation must not be duplicated.');
    assert.equal(urgent.root.querySelector('img, script'), null, 'Health diagnostics must be escaped in the new table.');
    assert.equal(urgent.status.dataset.kind, 'error');
    console.log('console-settings-dom-regression passed');
} finally {
    fixtures.forEach(fx => fx.dom.window.close());
}
