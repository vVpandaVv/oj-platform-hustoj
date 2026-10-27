(function () {
    'use strict';

    function init() {
        var root = document.querySelector('.oj-addon-starter');
        var configElement = document.getElementById('oj-addon-starter-config');
        if (!root || !configElement) return;

        var form = root.querySelector('[data-starter-form]');
        var fields = root.querySelector('[data-starter-fields]');
        var input = root.querySelector('[name="note"]');
        var status = root.querySelector('[data-starter-status]');
        var config;
        try {
            config = JSON.parse(configElement.textContent);
            if (typeof config.endpoint !== 'string' || typeof config.nonce !== 'string') throw new Error();
        } catch (error) {
            status.textContent = '화면 설정을 읽지 못했습니다. 새로고침해 주세요.';
            status.dataset.error = 'true';
            fields.disabled = true;
            return;
        }

        async function request(method, payload) {
            var controller = new AbortController();
            var timeout = setTimeout(function () { controller.abort(); }, 15000);
            var options = {
                method: method,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' },
                signal: controller.signal
            };
            if (payload !== undefined) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(payload);
            }
            try {
                var response = await fetch(config.endpoint, options);
                var data = await response.json();
                if (!response.ok) {
                    throw new Error(typeof data.message === 'string' ? data.message : '요청을 처리하지 못했습니다.');
                }
                if (typeof data.note !== 'string') throw new Error('메모 응답 형식이 올바르지 않습니다.');
                return data;
            } finally {
                clearTimeout(timeout);
            }
        }

        async function run(method, payload) {
            fields.disabled = true;
            status.dataset.error = 'false';
            status.textContent = method === 'GET' ? '메모를 불러오는 중입니다.' : '저장 중입니다.';
            try {
                var data = await request(method, payload);
                input.value = data.note;
                status.textContent = method === 'GET' ? '저장한 메모를 불러왔습니다.' : '저장했습니다.';
            } catch (error) {
                status.dataset.error = 'true';
                status.textContent = error.name === 'AbortError'
                    ? '응답이 지연되고 있습니다. 새로고침해 저장 상태를 확인해 주세요.'
                    : (error.message || '연결을 확인하고 다시 시도해 주세요.');
            } finally {
                fields.disabled = false;
            }
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!fields.disabled) run('POST', { note: input.value });
        });
        run('GET');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
