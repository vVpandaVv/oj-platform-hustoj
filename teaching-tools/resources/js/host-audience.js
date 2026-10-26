(function () {
    'use strict';

    window.HttAudience = {
        completed: function (root, path, data) {
            var control = root.querySelector('[data-htt-audience]');
            if (!control) { return; }
            if (path === '/sessions' && data.joinCode) { control.hidden = true; }
            if (/^\/sessions\/[A-Za-z0-9]{6}\/end$/.test(path)) { control.hidden = false; }
        },
        prepare: function (root, path, method, body) {
            if (path !== '/sessions' || method !== 'POST' || !body) {
                return body;
            }
            var mode = root.querySelector('[data-field="audience-mode"]');
            if (!mode || mode.value !== 'context') {
                return body;
            }
            var select = root.querySelector('[data-field="audience-context"]');
            if (!select || !select.value) {
                throw new Error('자동 연결할 학급이나 그룹을 선택하세요.');
            }
            var context = JSON.parse(select.value);
            if (body.context && (body.context.type !== context.type || String(body.context.id) !== String(context.id))) {
                throw new Error('명단을 불러온 학급·그룹과 자동 연결 대상을 같게 선택하세요.');
            }
            var autoOpen = root.querySelector('[data-field="audience-auto-open"]');
            return Object.assign({}, body, { mode: 'live', context: context, autoOpen: Boolean(autoOpen && autoOpen.checked) });
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-host]');
        var control = root && root.querySelector('[data-htt-audience]');
        if (!control) {
            return;
        }
        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var mode = control.querySelector('[data-field="audience-mode"]');
        var target = control.querySelector('[data-field="audience-target"]');
        var select = control.querySelector('[data-field="audience-context"]');
        var status = control.querySelector('[data-field="audience-status"]');
        var refresh = control.querySelector('[data-action="audience-refresh"]');
        var openOption = control.querySelector('[data-field="audience-open-option"]');

        function loadContexts() {
            var previous = select.value;
            status.textContent = '학급·그룹을 불러오고 있습니다.';
            return fetch(config.restUrl + '/contexts', {
                headers: { 'X-WP-Nonce': config.nonce }, credentials: 'same-origin',
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) { throw new Error(data.message || '대상 목록을 불러오지 못했습니다.'); }
                    select.innerHTML = '';
                    select.add(new Option('학급·그룹 선택', ''));
                    (data.items || []).forEach(function (item) {
                        select.add(new Option(item.label, JSON.stringify(item)));
                    });
                    select.value = previous;
                    status.textContent = '';
                });
            }).catch(function (error) { status.textContent = error.message; });
        }

        function selectLiveMode() {
            Array.prototype.forEach.call(root.querySelectorAll('input[type="radio"][value="solo"]'), function (input) {
                input.disabled = mode.value === 'context';
            });
            Array.prototype.forEach.call(root.querySelectorAll('input[type="radio"][value="live"]'), function (input) {
                input.disabled = false;
                input.checked = true;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        mode.addEventListener('change', function () {
            target.hidden = refresh.hidden = mode.value !== 'context';
            openOption.hidden = mode.value !== 'context';
            if (mode.value === 'context') {
                selectLiveMode();
                if (select.options.length < 2) { loadContexts(); }
            } else {
                Array.prototype.forEach.call(root.querySelectorAll('input[type="radio"][value="solo"]'), function (input) { input.disabled = false; });
                status.textContent = '';
            }
        });
        select.addEventListener('change', function () {
            if (!select.value) { return; }
            selectLiveMode();
            var selected = JSON.parse(select.value);
            var rosterSelect = root.querySelector('[data-field="context-select"]');
            var rosterButton = root.querySelector('[data-action="load-roster"]');
            if (rosterSelect && rosterButton) {
                var option = Array.prototype.find.call(rosterSelect.options, function (item) {
                    if (!item.value) { return false; }
                    var value = JSON.parse(item.value);
                    return value.type === selected.type && String(value.id) === String(selected.id);
                });
                if (!option) {
                    option = new Option(selected.label, JSON.stringify(selected));
                    rosterSelect.add(option);
                }
                rosterSelect.value = option.value;
                rosterButton.click();
            }
        });
        refresh.addEventListener('click', loadContexts);
    });
}());
