(function () {
    'use strict';

    var root = document.querySelector('[data-htt-realtime-settings]');
    if (!root) {
        return;
    }

    var config = JSON.parse(root.getAttribute('data-config') || '{}');
    var result = root.querySelector('[data-htt-realtime-result]');

    function show(kind, text) {
        result.innerHTML = '';
        var notice = document.createElement('div');
        notice.className = 'ojp-notice ojp-notice--' + kind;
        var paragraph = document.createElement('p');
        paragraph.textContent = text;
        notice.appendChild(paragraph);
        result.appendChild(notice);
    }

    root.addEventListener('click', function (event) {
        var selectArea = event.target.closest('[data-htt-select]');
        if (selectArea) {
            selectArea.select();
            return;
        }

        var button = event.target.closest('[data-htt-realtime-status]');
        if (!button) {
            return;
        }

        button.disabled = true;
        root.querySelector('[data-htt-update-needed]').hidden = true;
        root.querySelectorAll('[data-htt-version]').forEach(function (element) { element.textContent = '확인 중'; });
        show('info', '확인하는 중...');
        var abort = new AbortController();
        var timeout = window.setTimeout(function () { abort.abort(); }, 10000);
        fetch(config.restUrl + '/realtime/status', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': config.nonce },
            signal: abort.signal,
            cache: 'no-store',
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok) {
                    throw new Error(data.message || '서버 상태를 확인하지 못했습니다.');
                }
                return data;
            });
        }).then(function (data) {
            var versions = data.versions || {};
            var labels = { current: '일치', newer: '동봉 버전보다 높음', outdated: '업데이트 필요', unknown: '비교 불가' };
            ['module', 'core'].forEach(function (key) {
                var item = versions[key] || {};
                root.querySelector('[data-htt-version="' + key + '"]').textContent = (item.installed || '버전 정보 없음') + ' (' + (labels[item.status] || labels.unknown) + ')';
            });
            root.querySelector('[data-htt-update-needed]').hidden = !versions.updateRequired;
            show(versions.updateRequired || !versions.verified ? 'warning' : 'success',
                (versions.updateRequired ? '서버 업데이트가 필요합니다.' : (versions.verified ? '동봉 버전 기준으로 업데이트가 필요하지 않습니다.' : '서버 버전을 비교하지 못했습니다.'))
                + ' 등록·인증 정상 · 방 ' + data.rooms + '개 · 연결 ' + data.connections + '개');
        }).catch(function (error) {
            root.querySelectorAll('[data-htt-version]').forEach(function (element) { element.textContent = '연결 실패 · 최신 여부 알 수 없음'; });
            show('danger', (error.name === 'AbortError' ? '서버 응답 시간이 초과되었습니다.' : error.message) + ' 서버 설치·복구에서 설치 상태와 연결 설정을 확인하세요.');
        }).then(function () {
            window.clearTimeout(timeout);
            button.disabled = false;
        });
    });
    if (config.checkOnOpen) {
        var statusButton = root.querySelector('[data-htt-realtime-status]');
        if (statusButton) { statusButton.click(); }
    }
})();
