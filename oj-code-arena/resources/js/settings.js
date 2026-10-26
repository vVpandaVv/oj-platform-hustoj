/*
 * Code Arena — OJ console settings page (교사 메뉴 › 코드 아레나 › 설정):
 * engine status check and click-to-select for the read-only setup text.
 */
(function (window, document) {
    'use strict';

    var root = document.querySelector('[data-oca-settings-page]');
    if (!root) return;
    var cfg = JSON.parse(root.getAttribute('data-config') || '{}');

    function h(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function show(kind, text) {
        var out = root.querySelector('[data-oca-status-result]');
        out.innerHTML = '<div class="ojp-notice ojp-notice--' + kind + '"><p>' + h(text) + '</p></div>';
    }

    root.addEventListener('click', function (e) {
        var area = e.target.closest('[data-oca-select]');
        if (area) { area.select(); return; }
        var btn = e.target.closest('[data-oca-status]');
        if (!btn) return;
        btn.disabled = true;
        show('info', '확인하는 중…');
        fetch(cfg.restUrl + '/admin/status', { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (x) {
                if (x.ok) {
                    show('success', x.d.service === 'oj-realtime-hub'
                        ? '공통 실시간 서버 ' + x.d.version + ' · 아레나 ' + x.d.appVersion + ' 등록·인증 정상 · 방 ' + x.d.rooms + '개 · 연결 ' + x.d.connections + '개'
                        : '정상입니다. 엔진 ' + x.d.version + ', 열린 수업 게임 ' + x.d.sessions + '개, 자유 대전 방 ' + x.d.rooms + '개, 지금 접속 ' + x.d.players + '명');
                } else {
                    show('danger', '확인하지 못했습니다: ' + (x.d.message || '알 수 없는 오류') + ' 서버 설치·복구에서 설치 상태와 연결 설정을 확인하세요.');
                }
            })
            .catch(function (err) { show('danger', '확인하지 못했습니다: ' + err.message + ' 서버 설치·복구에서 설치 상태와 연결 설정을 확인하세요.'); })
            .then(function () { btn.disabled = false; });
    });
    if (cfg.checkOnOpen) {
        var statusButton = root.querySelector('[data-oca-status]');
        if (statusButton) { statusButton.click(); }
    }
})(window, document);
