/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
/**
 * Operator Kaggle credentials screen behind [data-ocl-settings]
 * (Addon\Console_Settings_Page_Handler). The key field is always blank on
 * load — the server never redisplays a stored key, only whether one exists.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-ocl-settings]');
        if (!root) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');

        var messagesEl = root.querySelector('[data-field="messages"]');
        var statusEl = root.querySelector('[data-field="status"]');
        var form = root.querySelector('[data-field="settings-form"]');
        var usernameInput = form.querySelector('[data-field="username"]');
        var keyInput = form.querySelector('[data-field="key"]');

        // Completed actions use the platform toast where it exists (OJ
        // Platform 2.1+); errors and older platforms keep an inline notice.
        var NOTICE_TONE = { info: 'info', success: 'success', error: 'danger' };
        function showMessage(text, type) {
            var ojp = 'undefined' !== typeof window ? window.OJP : null;
            messagesEl.innerHTML = '';
            if ('success' === type && ojp && 'function' === typeof ojp.toast) {
                ojp.toast(text);
                return;
            }
            var item = document.createElement('div');
            item.className = 'ojp-notice ojp-notice--' + (NOTICE_TONE[type] || 'info');
            item.textContent = text;
            messagesEl.appendChild(item);
        }

        function renderStatus(configured, username) {
            statusEl.textContent = configured
                ? ('연동되어 있습니다. Kaggle 사용자 이름: ' + username)
                : '아직 Kaggle 연동이 설정되지 않았습니다.';
        }

        usernameInput.value = config.username || '';
        renderStatus(config.configured, config.username);

        function confirmClear() {
            var ojp = window.OJP;
            if (!form.querySelector('[data-field="clear"]').checked || !ojp || 'function' !== typeof ojp.confirm) {
                return Promise.resolve(true);
            }
            return ojp.confirm({
                title: '저장된 Kaggle 연동 정보를 삭제할까요?',
                message: '삭제하면 Kaggle 검색과 가져오기를 쓸 수 없습니다. 다시 쓰려면 사용자 이름과 API 키를 새로 입력해야 합니다.',
                confirmLabel: '삭제',
                destructive: true,
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            confirmClear().then(function (ok) {
                if (ok) { saveSettings(); }
            });
        });

        function saveSettings() {
            fetch(config.restUrl + '/settings/kaggle', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': config.nonce,
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    username: usernameInput.value.trim(),
                    key: keyInput.value,
                    clear: form.querySelector('[data-field="clear"]').checked,
                }),
            })
                .then(function (response) { return response.json().then(function (data) { if (!response.ok) { throw new Error(data.message || '설정 저장 실패'); } return data; }); })
                .then(function (data) {
                    keyInput.value = '';
                    renderStatus(data.configured, data.username);
                    showMessage('저장했습니다', 'success');
                })
                .catch(function (error) {
                    showMessage(error.message || '저장하지 못했습니다.', 'error');
                });
        }
    });
})();
