/*
 * Code Arena — projector board for a class game (read-only).
 * A 1920×1080 stage scaled to the window.
 */
(function (window, document) {
    'use strict';

    var Net = window.ArenaNet;
    var Look = window.ArenaLook;
    var root = document.querySelector('[data-oca-board]');
    if (!root || !Net || !Look) return;
    var cfg = JSON.parse(root.getAttribute('data-config') || '{}');
    var sessionId = Number(cfg.session || new URLSearchParams(window.location.search).get('session'));

    var arena = null;
    var board = null;
    var at = 0;
    var feed = [];
    var soundOn = false;
    var lastSec = -1;

    function h(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function $(sel) { return root.querySelector(sel); }
    function clock(ms) {
        var s = Math.max(0, Math.ceil(ms / 1000));
        return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    }

    root.innerHTML =
        '<div class="ocb-frame"><div class="ocb-stage" data-ocb-stage>' +
        '<header class="ocb-head"><span class="ocb-logo">CODE<b>ARENA</b></span><span class="ocb-title" data-ocb-title></span>' +
        '<span class="ocb-flags" data-ocb-flags></span><span class="ocb-timer" data-ocb-timer>--:--</span></header>' +
        '<div class="ocb-body" data-ocb-body><p class="ocb-wait">연결하는 중…</p></div>' +
        '<aside class="ocb-feed"><h3>전장 소식</h3><ul data-ocb-feed></ul></aside>' +
        '<div class="ocb-banner" data-ocb-banner hidden></div>' +
        '</div>' +
        '<div class="ocb-tools"><button type="button" data-ocb-sound>소리 켜기</button><button type="button" data-ocb-full>전체 화면</button></div>' +
        '</div>';

    function fit() {
        var stage = $('[data-ocb-stage]');
        var frame = $('.ocb-frame');
        var w = frame.clientWidth;
        var hgt = document.fullscreenElement ? window.innerHeight : Math.max(320, window.innerHeight - frame.getBoundingClientRect().top - 12);
        var scale = Math.min(w / 1920, hgt / 1080);
        stage.style.transform = 'scale(' + scale + ')';
        frame.style.height = (1080 * scale) + 'px';
    }
    window.addEventListener('resize', fit);
    document.addEventListener('fullscreenchange', fit);

    function cellsHtml(t) {
        if (!t.cells) return '<div class="ocb-mini ocb-mini--empty">참가자 없음</div>';
        var out = '';
        for (var i = 0; i < t.cells.length; i++) out += '<i class="c' + t.cells[i] + '"></i>';
        return '<div class="ocb-mini" style="--cols:' + (t.cols || 12) + '">' + out + '</div>';
    }

    function render() {
        if (!arena) return;
        $('[data-ocb-title]').textContent = arena.title + (arena.context ? ' · ' + arena.context : '');
        var body = $('[data-ocb-body]');
        var playing = arena.phase === 'playing' && board;
        var flags = [];
        if (board && board.attacks) flags.push('<span class="ocb-flag ocb-flag--atk">공격 ON</span>');
        if (board && board.paused) flags.push('<span class="ocb-flag">멈춤</span>');
        $('[data-ocb-flags]').innerHTML = flags.join('');

        if (!playing) {
            var teams = arena.teams.map(function (t) {
                return '<div class="ocb-wteam"><h4>' + t.no + '팀</h4><div class="ocb-faces">' +
                    t.members.map(function (m) { return '<span>' + Look.svg(m.avatar, m.color, 44) + '<em>' + h(m.name) + '</em></span>'; }).join('') + '</div></div>';
            }).join('');
            var last = arena.lastResult ? '<div class="ocb-last"><h3>지난 경기</h3><ol>' + arena.lastResult.teams.map(function (t) {
                return '<li><b>' + t.rank + '위</b> ' + t.team + '팀 · ' + (t.cleared ? '완성 ' + clock(t.ms) : t.pct + '%') + '</li>';
            }).join('') + '</ol></div>' : '';
            body.innerHTML = '<div class="ocb-lobby"><div class="ocb-join"><p>접속 주소</p><strong>' + h(cfg.joinHint || '') + '</strong>' +
                '<p class="ocb-small">메뉴의 [코드 아레나] → [' + h(arena.title) + '] 입장</p></div>' +
                '<div class="ocb-wteams">' + teams + '</div>' + last + '</div>';
            return;
        }
        var order = {};
        (board.standings || []).forEach(function (s) { order[s.team] = s; });
        var cards = board.teams.filter(function (t) { return t.cells; }).sort(function (a, b) {
            var ra = order[a.team] ? order[a.team].rank : 99;
            var rb = order[b.team] ? order[b.team].rank : 99;
            return ra - rb || a.team - b.team;
        }).map(function (t) {
            var st = order[t.team] || {};
            var fx = [];
            if (t.boss) fx.push('<span class="ocb-fx ocb-fx--boss">' + (t.boss === 'gather' ? '모여라' : '보스') + '</span>');
            if (t.fx && t.fx.ice > 0) fx.push('<span class="ocb-fx ocb-fx--ice">얼음</span>');
            if (t.fx && t.fx.fog > 0) fx.push('<span class="ocb-fx">안개</span>');
            if (t.fx && t.fx.mirror > 0) fx.push('<span class="ocb-fx">거울</span>');
            if (t.fx && t.fx.stall > 0) fx.push('<span class="ocb-fx ocb-fx--bad">정전</span>');
            if (t.shields) fx.push('<span class="ocb-fx ocb-fx--shield">방패 ' + t.shields + '</span>');
            var over = t.done === 'clear' ? '<div class="ocb-over ocb-over--clear">' + st.rank + '위 완성!</div>' : (t.done ? '<div class="ocb-over">끝</div>' : '');
            return '<div class="ocb-card' + (t.done === 'clear' ? ' is-clear' : '') + '"><div class="ocb-card-head"><b class="ocb-rank">' + (st.rank || '-') + '</b><h4>' + t.team + '팀</h4>' +
                '<span class="ocb-pct">' + (t.pct || 0) + '%</span></div>' + cellsHtml(t) +
                '<div class="ocb-bar"><i style="width:' + (t.pct || 0) + '%"></i></div>' +
                '<div class="ocb-card-foot"><span class="ocb-faces ocb-faces--small">' + t.members.map(function (m) { return Look.svg(m.avatar, m.color, 26); }).join('') + '</span>' + fx.join('') + '</div>' + over + '</div>';
        }).join('');
        body.innerHTML = '<div class="ocb-cards ocb-cards--' + Math.min(8, board.teams.filter(function (t) { return t.cells; }).length) + '">' + cards + '</div>';
    }

    function drawFeed() {
        $('[data-ocb-feed]').innerHTML = feed.slice(-8).reverse().map(function (f) { return '<li class="k-' + h(f.kind) + '">' + h(f.text) + '</li>'; }).join('');
    }

    function bannerShow(html, kind) {
        var el = $('[data-ocb-banner]');
        el.className = 'ocb-banner ocb-banner--' + kind;
        el.innerHTML = html;
        el.hidden = false;
        clearTimeout(bannerShow.t);
        bannerShow.t = setTimeout(function () { el.hidden = true; }, 2600);
    }

    function tick() {
        var el = $('[data-ocb-timer]');
        if (!board || !arena || arena.phase !== 'playing') { el.textContent = arena ? '대기' : '--:--'; return; }
        var gone = board.paused ? 0 : performance.now() - at;
        var startLeft = board.startMs - gone;
        if (startLeft > 0) { el.textContent = '준비 ' + Math.ceil(startLeft / 1000); return; }
        var left = board.remainMs - Math.max(0, gone - board.startMs);
        el.textContent = board.paused ? '멈춤' : clock(left);
        el.classList.toggle('is-hurry', left < 30000);
        var sec = Math.ceil(left / 1000);
        if (soundOn && !board.paused && sec <= 10 && sec > 0 && sec !== lastSec) { lastSec = sec; Look.sfx('tick'); }
    }
    setInterval(tick, 200);

    var conn = new Net.Connection({
        cfg: cfg,
        ticket: function (force) { return Net.rest(cfg, 'POST', '/sessions/' + sessionId + '/ticket', { role: 'board', force: force }); },
        onStatus: function (state, detail) {
            if (state === 'failed' || state === 'closed') $('[data-ocb-body]').innerHTML = '<p class="ocb-wait">' + h(Net.why(detail)) + '</p>';
        },
        onMessage: function (m) {
            if (m.t === 'arena') { arena = m; if (!feed.length && m.feed) feed = m.feed.slice(); drawFeed(); render(); }
            else if (m.t === 'board') { board = m; at = performance.now(); render(); }
            else if (m.t === 'feed') { feed.push(m); drawFeed(); }
            else if (m.t === 'attack') {
                bannerShow('<b>' + m.from + '팀</b> → <b>' + m.to + '팀</b> ' + h(m.item.name) + '!', 'attack');
                if (soundOn) Look.sfx('hit');
            } else if (m.t === 'clear') {
                bannerShow('<b>' + m.team + '팀</b> ' + m.rank + '위로 완성!', 'clear');
                if (soundOn) Look.sfx('win');
            } else if (m.t === 'round_result') {
                if (arena) arena.lastResult = m.result;
                if (soundOn) Look.sfx('win');
            }
        },
    });
    conn.start();

    root.addEventListener('click', function (e) {
        if (e.target.closest('[data-ocb-sound]')) {
            soundOn = !soundOn;
            if (soundOn) { Look.setMuted(false); Look.unlock(); Look.sfx('start'); }
            e.target.textContent = soundOn ? '소리 끄기' : '소리 켜기';
        }
        if (e.target.closest('[data-ocb-full]')) {
            var el = $('.ocb-frame');
            if (document.fullscreenElement) document.exitFullscreen();
            else if (el.requestFullscreen) el.requestFullscreen();
        }
    });

    fit();
})(window, document);
