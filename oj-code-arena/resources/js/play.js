/*
 * Code Arena — learner page: class sessions, free-room lobby, waiting
 * room and the game itself. The server decides everything; this file
 * renders and sends keys (moves are predicted with ArenaMoves).
 */
(function (window, document) {
    'use strict';

    var Net = window.ArenaNet;
    var Look = window.ArenaLook;
    var Moves = window.ArenaMoves;

    var root = document.querySelector('[data-oca-play]');
    if (!root || !Net || !Look || !Moves) return;
    var cfg = JSON.parse(root.getAttribute('data-config') || '{}');

    var HANGUL = /[ᄀ-ᇿ㄰-㆏가-힯]/g;
    var REASON = { clear: '판을 모두 풀었어요!', time: '시간이 끝났어요', stop: '선생님이 경기를 끝냈어요', idle: '입력이 없어 끝났어요', done: '경기 끝' };
    var STATE_LABEL = { online: '대기', offline: '접속 끊김', playing: '이동 중', typing: '입력 중', boss: '보스 전투' };

    var conn = null;
    var view = 'home';
    var me = null;           // { uid, name, look }
    var arena = null;        // latest arena detail
    var rooms = [];
    var G = null;            // game state
    var timers = [];
    var lastTickSec = -1;

    // ── helpers ─────────────────────────────────────────────

    function h(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function $(sel, el) { return (el || root).querySelector(sel); }
    function $all(sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); }
    function now() { return performance.now(); }

    function clock(ms) {
        var s = Math.max(0, Math.ceil(ms / 1000));
        var m = Math.floor(s / 60);
        s = s % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function toast(text, kind) {
        var box = $('[data-oca-toasts]');
        if (!box) return;
        var el = document.createElement('div');
        el.className = 'oca-toast oca-toast--' + (kind || 'info');
        el.textContent = text;
        box.appendChild(el);
        setTimeout(function () { el.classList.add('is-out'); }, 2600);
        setTimeout(function () { el.remove(); }, 3100);
    }

    function look(p) {
        return Look.svg(p.avatar, p.color, 22);
    }

    function guardInput(input) {
        ['paste', 'drop', 'dragover', 'cut', 'copy', 'contextmenu'].forEach(function (ev) {
            input.addEventListener(ev, function (e) { e.preventDefault(); });
        });
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('autocorrect', 'off');
        input.setAttribute('autocapitalize', 'off');
        input.setAttribute('spellcheck', 'false');
        input.addEventListener('input', function () {
            if (HANGUL.test(input.value)) {
                input.value = input.value.replace(HANGUL, '');
                toast('한/영 키를 눌러 영문으로 바꿔 주세요', 'warn');
            }
            HANGUL.lastIndex = 0;
        });
    }

    // ── shell ────────────────────────────────────────────────

    function shell() {
        root.innerHTML =
            '<div class="oca-app" data-view="home">' +
            '  <div class="oca-screen" data-oca-screen></div>' +
            '  <div class="oca-toasts" data-oca-toasts aria-live="polite"></div>' +
            '  <p class="oca-credit">코드 아레나 · 팀 블록 점령 코딩 게임</p>' +
            '</div>';
    }

    function setView(name, html) {
        view = name;
        $('.oca-app').setAttribute('data-view', name);
        $('[data-oca-screen]').innerHTML = html;
    }

    // ── home ─────────────────────────────────────────────────

    function renderHome() {
        stopConnection();
        setView('home',
            '<section class="oca-hero">' +
            '  <div><h1 class="oca-logo">CODE<span>ARENA</span></h1>' +
            '  <p>팀원과 한 판을 나눠 블록을 차지하고, 코드를 정확히 입력해 판을 모두 풀어 보세요.</p></div>' +
            '  <div class="oca-looks" data-oca-looks></div>' +
            '</section>' +
            '<section class="oca-panel"><h2>수업 게임</h2><div data-oca-sessions><p class="oca-muted">불러오는 중…</p></div></section>' +
            (cfg.free ? '<section class="oca-panel"><h2>자유 대전</h2><p class="oca-muted">친구들과 방을 만들어 연습해요. 기록은 내 기록에만 남아요.</p>' +
                '<button type="button" class="oca-btn oca-btn--primary" data-act="free">자유 대전 로비로</button></section>' : '') +
            '<section class="oca-panel"><h2>내 기록</h2><div data-oca-records><p class="oca-muted">불러오는 중…</p></div></section>' +
            '<section class="oca-panel oca-keys-help"><h2>조작법</h2>' + keyHelp() + '</section>');
        renderLookPicker();
        loadSessions();
        loadRecords();
    }

    function currentLook() {
        var l = { avatar: 0, color: 0 };
        try {
            var saved = JSON.parse(window.localStorage.getItem('oca.look') || 'null');
            if (saved) l = saved;
        } catch (e) { /* storage off */ }
        return l;
    }

    function renderLookPicker() {
        var box = $('[data-oca-looks]');
        if (!box) return;
        var l = currentLook();
        var html = '<p class="oca-muted">내 캐릭터</p><div class="oca-look-row">';
        for (var a = 0; a < Look.count; a++) {
            html += '<button type="button" class="oca-look' + (a === l.avatar ? ' is-on' : '') + '" data-avatar="' + a + '" title="' + h(Look.NAMES[a]) + '">' + Look.svg(a, l.color, 34) + '</button>';
        }
        html += '</div><div class="oca-look-row">';
        for (var c = 0; c < Look.COLORS.length; c++) {
            html += '<button type="button" class="oca-swatch' + (c === l.color ? ' is-on' : '') + '" data-color="' + c + '" style="background:' + Look.COLORS[c] + '" aria-label="색 ' + (c + 1) + '"></button>';
        }
        box.innerHTML = html + '</div>';
    }

    function saveLook(l) {
        try { window.localStorage.setItem('oca.look', JSON.stringify(l)); } catch (e) { /* storage off */ }
        if (conn && conn.ready) conn.send('look', l);
    }

    function loadSessions() {
        Net.rest(cfg, 'GET', '/play').then(function (data) {
            var box = $('[data-oca-sessions]');
            if (!box) return;
            var list = data.sessions || [];
            if (!list.length) {
                box.innerHTML = '<p class="oca-muted">지금 열린 수업 게임이 없어요. 선생님이 게임을 열면 여기에 나타나요.</p>';
                return;
            }
            box.innerHTML = '<ul class="oca-cards">' + list.map(function (s) {
                return '<li class="oca-card"><div><strong>' + h(s.title) + '</strong><span class="oca-muted">' + h(s.context || '모든 학생') + '</span></div>' +
                    '<button type="button" class="oca-btn oca-btn--primary" data-act="join-session" data-id="' + s.id + '">입장</button></li>';
            }).join('') + '</ul>';
        }).catch(function (err) {
            var box = $('[data-oca-sessions]');
            if (box) box.innerHTML = '<p class="oca-error">' + h(err.message) + '</p>';
        });
    }

    function loadRecords() {
        Net.rest(cfg, 'GET', '/me/records').then(function (data) {
            var box = $('[data-oca-records]');
            if (!box) return;
            var rows = data.rounds || [];
            if (!rows.length) {
                box.innerHTML = '<p class="oca-muted">아직 기록이 없어요.</p>';
                return;
            }
            box.innerHTML = '<div class="oca-table-wrap"><table class="oca-table"><thead><tr><th>날짜</th><th>게임</th><th>팀 순위</th><th>팀 진행</th><th>푼 블록</th><th>보스</th></tr></thead><tbody>' +
                rows.map(function (r) {
                    return '<tr><td>' + h(r.date) + '</td><td>' + h(r.title) + (r.mode === 'free' ? ' <span class="oca-chip">자유</span>' : '') + '</td><td>' + h(r.team_rank) + '위' + (r.cleared ? ' ✓' : '') + '</td><td>' + h(r.team_pct) + '%</td><td>' + h(r.solved) + '</td><td>' + h(r.bosses) + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }).catch(function () {
            var box = $('[data-oca-records]');
            if (box) box.innerHTML = '<p class="oca-muted">기록을 불러오지 못했어요.</p>';
        });
    }

    function keyHelp() {
        return '<ul class="oca-keys">' +
            '<li><kbd>← ↑ → ↓</kbd> <kbd>Tab</kbd> 이동 · <kbd>Ctrl</kbd>+방향키 빠르게</li>' +
            '<li><kbd>Home</kbd>/<kbd>End</kbd> 줄 처음·끝 · <kbd>Ctrl</kbd>+<kbd>Home</kbd>/<kbd>End</kbd> 판 처음·끝</li>' +
            '<li><kbd>Enter</kbd> 블록 차지 → 코드 입력 → <kbd>Enter</kbd> 제출 · <kbd>Esc</kbd> 놓기</li>' +
            '<li>보스 왼쪽에서 <kbd>Delete</kbd>, 오른쪽에서 <kbd>Backspace</kbd> 로 도전</li>' +
            '<li><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>1</kbd>~<kbd>5</kbd> 아이템 · <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>9</kbd> 방패</li>' +
            '</ul>';
    }

    // ── connection ───────────────────────────────────────────

    function stopConnection() {
        if (conn) conn.stop();
        conn = null;
        arena = null;
        G = null;
        timers.forEach(clearInterval);
        timers = [];
    }

    function connect(sessionId) {
        stopConnection();
        setView('connecting', '<div class="oca-center"><div class="oca-spinner"></div><p data-oca-status>게임 서버에 연결하는 중…</p>' +
            '<button type="button" class="oca-btn" data-act="home">돌아가기</button></div>');
        conn = new Net.Connection({
            cfg: cfg,
            ticket: function (force) { return Net.rest(cfg, 'POST', '/play/ticket', { session: sessionId, force: force }); },
            onStatus: onStatus,
            onHello: function (ack) {
                me = ack.me;
                var l = currentLook();
                conn.send('look', l);
                if (sessionId === 0 && view === 'connecting') renderFreeLobby();
            },
            onMessage: onMessage,
        });
        conn.start();
        timers.push(setInterval(frame, 200));
    }

    function onStatus(state, detail) {
        var dot = $('[data-oca-net]');
        if (dot) {
            dot.className = 'oca-net oca-net--' + state;
            dot.title = state === 'open' ? '실시간 연결됨' : '다시 연결하는 중';
        }
        if (state === 'failed' || state === 'closed') {
            var text = Net.why(detail);
            if (view === 'game' && detail === 'ended') return showClosed(text);
            setView('closed', '<div class="oca-center"><p class="oca-big">' + h(text) + '</p><button type="button" class="oca-btn oca-btn--primary" data-act="home">처음 화면으로</button></div>');
        } else if (state === 'retry') {
            var s = $('[data-oca-status]');
            if (s) s.textContent = '다시 연결하는 중… (' + detail + ')';
            if (view === 'game') toast('연결이 끊겨 다시 연결하는 중이에요', 'warn');
        }
    }

    function showClosed(text) {
        overlay('<h2>' + h(text) + '</h2><button type="button" class="oca-btn oca-btn--primary" data-act="home">처음 화면으로</button>');
    }

    // ── free lobby ───────────────────────────────────────────

    function renderFreeLobby() {
        setView('free',
            '<section class="oca-panel"><div class="oca-row"><h2>자유 대전 로비</h2><span class="oca-net" data-oca-net></span>' +
            '<button type="button" class="oca-btn" data-act="home">나가기</button></div>' +
            '<form class="oca-form" data-oca-create>' +
            '  <label>방 이름 <input name="title" maxlength="30" placeholder="' + h((me && me.name) || '') + '의 방"></label>' +
            '  <label>시간(분) <input name="limitMin" type="number" min="1" max="30" value="5"></label>' +
            '  <label>최대 인원 <input name="maxPlayers" type="number" min="2" max="12" value="6"></label>' +
            '  <fieldset class="oca-tags"><legend>문제 단원 (비우면 전체)</legend>' + tagChecks([]) + '</fieldset>' +
            '  <button class="oca-btn oca-btn--primary">방 만들기</button>' +
            '</form></section>' +
            '<section class="oca-panel"><h2>열린 방</h2><div data-oca-rooms></div></section>');
        renderRooms();
    }

    function tagChecks(selected) {
        return (cfg.tags || []).map(function (t) {
            return '<label class="oca-check"><input type="checkbox" name="tags" value="' + h(t) + '"' + (selected.indexOf(t) >= 0 ? ' checked' : '') + '> ' + h(t) + '</label>';
        }).join('');
    }

    function renderRooms() {
        var box = $('[data-oca-rooms]');
        if (!box) return;
        if (!rooms.length) {
            box.innerHTML = '<p class="oca-muted">열린 방이 없어요. 방을 만들어 보세요!</p>';
            return;
        }
        box.innerHTML = '<ul class="oca-cards">' + rooms.map(function (r) {
            var faces = (r.members || []).map(function (m) { return Look.svg(m.avatar, m.color, 20); }).join('');
            var state = r.phase === 'playing' ? '경기 중 ' + r.pct + '%' : '대기 중';
            var can = r.phase !== 'playing' && r.count < r.max;
            return '<li class="oca-card"><div><strong>' + h(r.title) + '</strong><span class="oca-muted">' + state + ' · ' + r.count + '/' + r.max + '명 · ' + r.limitMin + '분' +
                (r.tags && r.tags.length ? ' · ' + h(r.tags.join(', ')) : '') + '</span><span class="oca-faces">' + faces + '</span></div>' +
                '<button type="button" class="oca-btn' + (can ? ' oca-btn--primary' : '') + '" data-act="join-room" data-id="' + h(r.id) + '"' + (can ? '' : ' disabled') + '>들어가기</button></li>';
        }).join('') + '</ul>';
    }

    // ── waiting room ─────────────────────────────────────────

    function renderWait() {
        var a = arena;
        var host = a.you && a.you.host;
        var isClass = a.mode === 'class';
        var s = a.settings;
        var teams = a.teams.map(function (t) {
            var mine = a.you && a.you.team === t.no;
            var members = t.members.map(function (m) {
                return '<li class="' + (m.online ? '' : 'is-off') + '">' + look(m) + '<span>' + h(m.name) + '</span>' +
                    (m.bot && host ? ' <button type="button" class="oca-x" data-act="bot-remove" data-id="' + h(m.id) + '" aria-label="봇 빼기">×</button>' : '') + '</li>';
            }).join('');
            return '<div class="oca-team-card' + (mine ? ' is-mine' : '') + '"><h3>' + (isClass ? t.no + '팀' : '참가자') + ' <span class="oca-muted">' + t.members.length + '명</span></h3>' +
                '<ul class="oca-members">' + (members || '<li class="oca-muted">아직 없어요</li>') + '</ul>' +
                (isClass && s.selfPick && !mine ? '<button type="button" class="oca-btn oca-btn--small" data-act="pick-team" data-no="' + t.no + '">이 팀으로</button>' : '') +
                '</div>';
        }).join('');
        var waiting = isClass && a.waiting.length
            ? '<p class="oca-muted">팀을 정하지 않은 학생: ' + a.waiting.map(function (m) { return h(m.name); }).join(', ') + '</p>' : '';
        var intro = isClass
            ? (a.you && a.you.team ? '<p class="oca-lead">' + a.you.team + '팀이에요. 선생님이 시작할 때까지 기다려 주세요.</p>'
                : '<p class="oca-lead">' + (s.selfPick ? '들어갈 팀을 고르세요.' : '선생님이 팀을 정해 줄 때까지 기다려 주세요.') + '</p>')
            : (host ? '<p class="oca-lead">방장이에요. 준비되면 시작하세요.</p>' : '<p class="oca-lead">방장이 시작할 때까지 기다려 주세요.</p>');

        var hostTools = !isClass && host
            ? '<div class="oca-row oca-host">' +
              '<button type="button" class="oca-btn" data-act="bot-add">연습봇 추가</button>' +
              '<label>시간 <select data-oca-setting="limitMin">' + [3, 5, 7, 10, 15].map(function (v) { return '<option' + (v === s.limitMin ? ' selected' : '') + '>' + v + '</option>'; }).join('') + '</select>분</label>' +
              '<button type="button" class="oca-btn oca-btn--primary" data-act="start">시작!</button></div>' : '';

        setView('wait',
            '<section class="oca-panel">' +
            '<div class="oca-row"><h2>' + h(a.title || '코드 아레나') + '</h2>' + (a.context ? '<span class="oca-chip">' + h(a.context) + '</span>' : '') +
            '<span class="oca-net oca-net--open" data-oca-net></span>' +
            '<button type="button" class="oca-btn" data-act="' + (isClass ? 'home' : 'leave-room') + '">나가기</button></div>' +
            intro +
            '<p class="oca-muted">' + h(settingsLine(s)) + '</p>' +
            hostTools + waiting +
            '<div class="oca-teams">' + teams + '</div></section>' +
            (a.lastResult ? '<section class="oca-panel"><h2>지난 경기 결과</h2>' + resultTable(a.lastResult) + '</section>' : '') +
            '<section class="oca-panel oca-keys-help"><h2>조작법</h2>' + keyHelp() + '</section>');
    }

    function settingsLine(s) {
        return [s.limitMin + '분', s.blocks ? s.blocks + '블록' : '블록 수 자동', '점유 ' + s.occSec + '초', '보스 ' + s.bossEverySec + '초마다',
            s.tags && s.tags.length ? '단원: ' + s.tags.join(', ') : '전체 단원', s.attacks ? '공격 아이템 켬' : ''].filter(Boolean).join(' · ');
    }

    function resultTable(r) {
        var mineTeam = arena && arena.you ? arena.you.team : 0;
        return '<div class="oca-table-wrap"><table class="oca-table"><thead><tr><th>순위</th><th>팀</th><th>진행</th><th>시간</th><th>보스</th><th>팀원 (푼 블록)</th></tr></thead><tbody>' +
            r.teams.map(function (t) {
                return '<tr class="' + (t.team === mineTeam ? 'is-mine' : '') + '"><td>' + t.rank + '위</td><td>' + (r.mode === 'class' ? t.team + '팀' : '우리 팀') + '</td><td>' +
                    (t.cleared ? '완성!' : t.pct + '%') + '</td><td>' + clock(t.ms) + '</td><td>' + t.bosses + '</td><td>' +
                    t.players.map(function (p) { return h(p.name) + ' (' + p.solved + ')'; }).join(', ') + '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    // ── game ─────────────────────────────────────────────────

    function enterGame(st) {
        var t = now();
        G = {
            me: st.me, team: st.team, cols: st.cols, rows: st.rows,
            cells: st.cells, players: {}, occ: {}, boss: null,
            fx: {}, hintUntil: t + (st.hintMs || 0), deck: st.deck || { slots: [], shields: 0 },
            incoming: [], startAt: t + st.startMs, endAt: t + st.endMs, pausedAt: st.paused ? t : 0,
            ended: st.ended, attacks: st.attacks, seq: 0, limits: st.limits, question: null, gather: null,
        };
        st.players.forEach(function (p) { G.players[p.id] = p; });
        st.occ.forEach(function (o) { G.occ[o.i] = { by: o.by, until: t + o.ms }; });
        setBoss(st.boss);
        Object.keys(st.fx).forEach(function (k) { G.fx[k] = t + st.fx[k]; });
        st.incoming.forEach(function (inc) { G.incoming.push({ id: inc.id, item: inc.item, from: inc.from, until: t + inc.ms }); });
        G.grid = {
            cols: G.cols, rows: G.rows,
            isSolved: function (i) { return !!G.cells[i].solved; },
            isBlocked: function (i) { return !!G.boss && G.boss.i === i; },
        };
        renderGame();
        if (st.question) openBoss(st.question);
        if (st.ended) showMatchEnd(st.ended);
        if (st.startMs > 0) Look.sfx('start');
    }

    function setBoss(b) {
        if (!b || b.i < 0) { G.boss = null; return; }
        G.boss = { i: b.i, phase: b.phase, by: b.by, title: b.title, until: now() + (b.ms || 0), got: b.got, need: b.need };
    }

    function mePlayer() { return G && G.players[G.me]; }

    function renderGame() {
        var a = arena || { title: '', mode: 'class', settings: { tags: [] } };
        var modeLabel = a.mode === 'class' ? (G.team + '팀') : '자유 대전';
        setView('game',
            '<div class="oca-game">' +
            '<aside class="oca-side">' +
            '  <div class="oca-box oca-mine" data-oca-mine></div>' +
            '  <div class="oca-box"><h3>우리 팀 <span data-oca-teamcount></span></h3><ul class="oca-roster" data-oca-roster></ul></div>' +
            (a.mode === 'class' ? '  <div class="oca-box"><h3>팀 순위</h3><ol class="oca-standings" data-oca-standings></ol></div>' : '') +
            '  <div class="oca-box oca-feedbox"><h3>전장 소식</h3><ul class="oca-feed" data-oca-feed></ul></div>' +
            '</aside>' +
            '<main class="oca-main">' +
            '  <header class="oca-top">' +
            '    <span class="oca-logo oca-logo--small">CODE<span>ARENA</span></span>' +
            '    <span class="oca-badge">' + h(modeLabel) + '</span>' +
            (a.settings.tags || []).slice(0, 4).map(function (t) { return '<span class="oca-tag">#' + h(t) + '</span>'; }).join('') +
            '    <span class="oca-timer" data-oca-timer>--:--</span>' +
            '    <span class="oca-fxbar" data-oca-fx></span>' +
            '    <button type="button" class="oca-btn oca-btn--small" data-act="mute">' + (Look.muted() ? '소리 켜기' : '소리 끄기') + '</button>' +
            '    <span class="oca-net oca-net--open" data-oca-net title="실시간 연결됨"></span>' +
            '    <button type="button" class="oca-btn oca-btn--small" data-act="fullscreen">전체 화면</button>' +
            '  </header>' +
            '  <div class="oca-banner" data-oca-banner hidden></div>' +
            '  <div class="oca-board-wrap"><div class="oca-grid" data-oca-grid style="--cols:' + G.cols + '"></div>' +
            '    <div class="oca-typer" data-oca-typer hidden><input type="text" maxlength="300" data-oca-input aria-label="코드 입력"><div class="oca-typer-bar"><i data-oca-occbar></i></div></div>' +
            '  </div>' +
            '  <footer class="oca-bottom">' +
            '    <div class="oca-deck" data-oca-deck></div>' +
            '    <div class="oca-shields" data-oca-shields></div>' +
            '  </footer>' +
            '  <div class="oca-keyline">' +
            '<span><kbd>←↑→↓</kbd><kbd>Tab</kbd> 이동</span><span><kbd>Ctrl</kbd>+방향키 빠르게</span><span><kbd>Home</kbd>/<kbd>End</kbd> 줄 끝</span>' +
            '<span><kbd>Enter</kbd> 차지·제출</span><span><kbd>Esc</kbd> 놓기</span><span><kbd>Del</kbd>/<kbd>⌫</kbd> 보스</span><span><kbd>Ctrl+Shift+1~5</kbd> 아이템</span></div>' +
            '</main>' +
            '<div class="oca-overlay" data-oca-overlay hidden></div>' +
            '</div>');
        var input = $('[data-oca-input]');
        guardInput(input);
        input.addEventListener('keydown', onTyperKey);
        input.addEventListener('blur', function () {
            setTimeout(function () { if (isHolding()) input.focus(); }, 0);
        });
        drawGrid();
        drawSide();
        drawDeck();
        drawFeed();
        if (arena && arena.feed) arena.feed.forEach(function (f) { addFeed(f.text, f.kind, true); });
        frame();
    }

    function cellHtml(i) {
        var c = G.cells[i];
        var cls = ['oca-cell'];
        var occ = G.occ[i];
        var crown = '';
        if (c.solved) {
            cls.push('is-solved');
            if (c.crown) { cls.push('is-crown'); crown = '<em class="oca-crown">♛ ' + h(c.crown) + '</em>'; }
        }
        if (occ) cls.push(occ.by === G.me ? 'is-mine' : 'is-taken');
        if (G.boss && G.boss.i === i) cls.push('is-boss', 'is-boss-' + G.boss.phase);
        var label = G.boss && G.boss.i === i
            ? '<b class="oca-boss-face">' + (G.boss.phase === 'gather' ? '모여라!' : 'BOSS') + '</b>'
            : '<code>' + h(c.code) + '</code>';
        var holder = occ && occ.by !== G.me && G.players[occ.by] ? '<span class="oca-holder">' + h(G.players[occ.by].name) + '</span>' : '';
        return '<div class="' + cls.join(' ') + '" data-i="' + i + '">' + label + crown + holder + '<span class="oca-pins"></span></div>';
    }

    function drawGrid() {
        var grid = $('[data-oca-grid]');
        if (!grid) return;
        var html = '';
        for (var i = 0; i < G.cells.length; i++) html += cellHtml(i);
        grid.innerHTML = html;
        drawPins();
        placeTyper();
    }

    function redrawCell(i) {
        var el = $('[data-oca-grid] [data-i="' + i + '"]');
        if (!el) return;
        var tmp = document.createElement('div');
        tmp.innerHTML = cellHtml(i);
        el.replaceWith(tmp.firstChild);
        drawPinsAt(i);
    }

    function drawPins() {
        $all('[data-oca-grid] .oca-pins').forEach(function (el) { el.innerHTML = ''; });
        Object.keys(G.players).forEach(function (id) { drawPinsAt(G.players[id].r * G.cols + G.players[id].c); });
        var meP = mePlayer();
        $all('[data-oca-grid] .is-cursor').forEach(function (el) { el.classList.remove('is-cursor'); });
        if (meP) {
            var cur = $('[data-oca-grid] [data-i="' + (meP.r * G.cols + meP.c) + '"]');
            if (cur) cur.classList.add('is-cursor');
        }
    }

    function drawPinsAt(i) {
        var cell = $('[data-oca-grid] [data-i="' + i + '"]');
        if (!cell) return;
        var pins = cell.querySelector('.oca-pins');
        var html = '';
        Object.keys(G.players).forEach(function (id) {
            var p = G.players[id];
            if (p.r * G.cols + p.c !== i || !p.online) return;
            html += '<span class="oca-pin' + (id === G.me ? ' is-me' : '') + '" title="' + h(p.name) + '">' + Look.svg(p.avatar, p.color, 18) + '</span>';
        });
        pins.innerHTML = html;
        var meP = mePlayer();
        cell.classList.toggle('is-cursor', !!meP && meP.r * G.cols + meP.c === i);
    }

    function movePin(p, r, c) {
        var before = p.r * G.cols + p.c;
        p.r = r;
        p.c = c;
        drawPinsAt(before);
        drawPinsAt(r * G.cols + c);
    }

    function isHolding() {
        var p = mePlayer();
        return !!p && Object.keys(G.occ).some(function (i) { return G.occ[i].by === G.me; });
    }

    function heldCell() {
        var keys = Object.keys(G.occ);
        for (var k = 0; k < keys.length; k++) if (G.occ[keys[k]].by === G.me) return Number(keys[k]);
        return -1;
    }

    function placeTyper() {
        var typer = $('[data-oca-typer]');
        if (!typer) return;
        var i = heldCell();
        if (i < 0) {
            typer.hidden = true;
            var focused = $('[data-oca-input]');
            if (focused && document.activeElement === focused) focused.blur();
            return;
        }
        var cell = $('[data-oca-grid] [data-i="' + i + '"]');
        if (!cell) return;
        var wrap = $('.oca-board-wrap');
        var cr = cell.getBoundingClientRect();
        var wr = wrap.getBoundingClientRect();
        typer.hidden = false;
        var left = cr.left - wr.left + wrap.scrollLeft - 40;
        typer.style.left = Math.max(4, Math.min(left, wrap.clientWidth - 260)) + 'px';
        typer.style.top = (cr.bottom - wr.top + wrap.scrollTop + 4) + 'px';
        var input = $('[data-oca-input]');
        if (document.activeElement !== input) { input.value = ''; input.focus(); }
    }

    function drawSide() {
        if (!G) return;
        var p = mePlayer();
        var solved = G.cells.filter(function (c) { return c.solved; }).length;
        var pct = Math.floor((solved * 100) / G.cells.length);
        var mine = $('[data-oca-mine]');
        if (mine && p) {
            mine.innerHTML = '<div class="oca-mine-head">' + Look.svg(p.avatar, p.color, 30) + '<div><strong>' + h(p.name) + '</strong><span>' + p.solved + '블록 · 보스 ' + (p.bosses || 0) + '</span></div></div>' +
                '<div class="oca-pct"><b>' + pct + '%</b><span>남은 블록 ' + (G.cells.length - solved) + '</span></div>' +
                '<div class="oca-bar"><i style="width:' + pct + '%"></i></div>';
        }
        var roster = $('[data-oca-roster]');
        if (roster) {
            var ids = Object.keys(G.players).sort(function (a, b) { return G.players[b].solved - G.players[a].solved; });
            $('[data-oca-teamcount]').textContent = ids.length + '명';
            var states = {};
            if (arena) arena.teams.forEach(function (t) { t.members.forEach(function (m) { states[m.id] = m.state; }); });
            roster.innerHTML = ids.map(function (id) {
                var q = G.players[id];
                var st = q.bot ? '봇' : (STATE_LABEL[states[id]] || (q.online ? '' : '접속 끊김'));
                return '<li class="' + (q.online ? '' : 'is-off') + (id === G.me ? ' is-me' : '') + '">' + look(q) + '<span class="oca-name">' + h(q.name) + '</span><span class="oca-score">' + q.solved + '</span><small>' + h(st) + '</small></li>';
            }).join('');
        }
    }

    function drawStandings(list) {
        var box = $('[data-oca-standings]');
        if (!box || !list) return;
        box.innerHTML = list.map(function (s) {
            return '<li class="' + (G && s.team === G.team ? 'is-mine' : '') + '"><span>' + s.rank + '위</span><b>' + s.team + '팀</b><span class="oca-bar oca-bar--thin"><i style="width:' + s.pct + '%"></i></span><em>' + (s.cleared ? '완성' : s.pct + '%') + '</em></li>';
        }).join('');
    }

    function drawDeck() {
        var box = $('[data-oca-deck]');
        if (!box || !G) return;
        var slots = (G.deck && G.deck.slots) || [];
        var html = '<span class="oca-deck-label">내 아이템</span>';
        for (var k = 0; k < 5; k++) {
            var it = slots[k];
            html += '<button type="button" class="oca-slot' + (it ? ' oca-slot--' + it.kind : '') + (it && it.off ? ' is-off' : '') + '" data-act="item" data-slot="' + (k + 1) + '" title="' + h(it ? it.name + ' — ' + it.desc : '빈 칸') + '">' +
                '<span class="oca-slot-key">Ctrl+Shift+' + (k + 1) + '</span>' + (it ? '<b>' + h(it.name) + '</b>' : '') + '</button>';
        }
        box.innerHTML = html;
        var sh = $('[data-oca-shields]');
        var n = (G.deck && G.deck.shields) || 0;
        sh.innerHTML = n ? '<span class="oca-shield" title="Ctrl+Shift+9 로 공격 막기">방패 ×' + n + ' <kbd>Ctrl+Shift+9</kbd></span>' : '';
    }

    var feedItems = [];
    function addFeed(text, kind, quiet) {
        feedItems.push({ text: text, kind: kind || 'info' });
        if (feedItems.length > 30) feedItems.shift();
        drawFeed();
        if (!quiet && (kind === 'attack' || kind === 'clear')) toast(text, kind);
    }

    function drawFeed() {
        var box = $('[data-oca-feed]');
        if (!box) return;
        box.innerHTML = feedItems.slice(-10).reverse().map(function (f) { return '<li class="oca-feed--' + h(f.kind) + '">' + h(f.text) + '</li>'; }).join('');
    }

    function banner(html, kind) {
        var el = $('[data-oca-banner]');
        if (!el) return;
        if (!html) { el.hidden = true; return; }
        el.hidden = false;
        el.className = 'oca-banner oca-banner--' + (kind || 'info');
        el.innerHTML = html;
    }

    function overlay(html) {
        var el = $('[data-oca-overlay]');
        if (!el) {
            setView('closed', '<div class="oca-center">' + html + '</div>');
            return;
        }
        if (!html) { el.hidden = true; el.innerHTML = ''; return; }
        el.hidden = false;
        el.innerHTML = '<div class="oca-modal">' + html + '</div>';
    }

    // ── per-frame clock ──────────────────────────────────────

    function frame() {
        if (view !== 'game' || !G) return;
        var t = G.pausedAt || now();
        var timer = $('[data-oca-timer]');
        var startLeft = G.startAt - t;
        if (timer) {
            if (G.ended) timer.textContent = '끝';
            else if (G.pausedAt) timer.textContent = '멈춤';
            else if (startLeft > 0) timer.textContent = '준비 ' + Math.ceil(startLeft / 1000);
            else timer.textContent = clock(G.endAt - t);
            var left = G.endAt - t;
            timer.classList.toggle('is-hurry', !G.ended && startLeft <= 0 && left < 30000);
            var sec = Math.ceil(left / 1000);
            if (!G.ended && !G.pausedAt && startLeft <= 0 && sec <= 10 && sec > 0 && sec !== lastTickSec) { lastTickSec = sec; Look.sfx('tick'); }
        }
        // countdown overlay
        var cd = $('[data-oca-countdown]');
        if (startLeft > 0 && !G.pausedAt) {
            if (!cd) {
                cd = document.createElement('div');
                cd.className = 'oca-countdown';
                cd.setAttribute('data-oca-countdown', '');
                $('.oca-board-wrap').appendChild(cd);
            }
            cd.textContent = Math.ceil(startLeft / 1000);
        } else if (cd) {
            cd.remove();
        }
        // effects
        var fx = [];
        var labels = { stall: '정전', ice: '얼음', fog: '안개', mirror: '거울' };
        Object.keys(labels).forEach(function (k) {
            var left = (G.fx[k] || 0) - t;
            if (left > 0) fx.push('<span class="oca-fx oca-fx--' + k + '">' + labels[k] + ' ' + Math.ceil(left / 1000) + '</span>');
        });
        var hintLeft = G.hintUntil - t;
        if (hintLeft > 0) fx.push('<span class="oca-fx oca-fx--hint">자동 완성 ' + Math.ceil(hintLeft / 1000) + '</span>');
        var fxEl = $('[data-oca-fx]');
        if (fxEl) fxEl.innerHTML = fx.join('');
        var app = $('.oca-game');
        if (app) {
            app.classList.toggle('is-frozen', (G.fx.stall || 0) > t || (G.fx.ice || 0) > t);
            app.classList.toggle('is-fog', (G.fx.fog || 0) > t);
            app.classList.toggle('is-mirror', (G.fx.mirror || 0) > t);
        }
        // my occupation bar
        var held = heldCell();
        var bar = $('[data-oca-occbar]');
        if (bar && held >= 0) {
            var occ = G.occ[held];
            bar.style.width = Math.max(0, Math.min(100, ((occ.until - t) / G.limits.occMs) * 100)) + '%';
        }
        // boss / incoming banner
        if (G.incoming.length) {
            var inc = G.incoming[0];
            var ms = inc.until - t;
            banner('<b>' + h(inc.from) + '팀의 ' + h(inc.item.name) + ' 공격!</b> ' + Math.max(0, ms / 1000).toFixed(1) + '초 안에 <kbd>Ctrl+Shift+9</kbd> 로 막으세요' +
                (G.deck.shields ? '' : ' (방패 없음)'), 'attack');
        } else if (G.boss && !G.ended) {
            var bl = Math.max(0, Math.ceil((G.boss.until - t) / 1000));
            if (G.boss.phase === 'wait') banner('<b>보스 등장!</b> ' + h(G.boss.title || '') + ' — 보스 왼쪽 칸에서 <kbd>Delete</kbd>, 오른쪽 칸에서 <kbd>Backspace</kbd> (' + bl + '초)', 'boss');
            else if (G.boss.phase === 'fight') banner('<b>' + h((G.players[G.boss.by] || {}).name || '') + '</b> 님이 보스와 싸우는 중 (' + bl + '초)', 'boss');
            else banner('<b>모여라 보스!</b> 팀원 모두 보스 바로 옆 칸(같은 줄)으로 모이세요 — ' + (G.gather ? G.gather.got + '/' + G.gather.need : '') + ' (' + bl + '초)', 'gather');
        } else {
            banner('');
        }
        var q = $('[data-oca-qtime]');
        if (q && G.question) q.textContent = Math.max(0, Math.ceil((G.question.until - t) / 1000)) + '초';
    }

    // ── keyboard ─────────────────────────────────────────────

    function sendMove(cmd) {
        var p = mePlayer();
        if (!p || G.ended) return;
        var t = now();
        var frozen = (G.fx.stall || 0) > t || (G.fx.ice || 0) > t;
        if (frozen || G.pausedAt || G.startAt > t || heldCell() >= 0 || (G.boss && G.boss.by === G.me)) return;
        G.seq++;
        var next = Moves.move(G.grid, p.r, p.c, cmd, (G.fx.mirror || 0) > t);
        if (next.r !== p.r || next.c !== p.c) {
            movePin(p, next.r, next.c);
            Look.sfx('move');
        }
        conn.fire('move', Object.assign({ seq: G.seq }, cmd));
    }

    function onGameKey(e) {
        if (view !== 'game' || !G) return;
        Look.unlock();
        var target = e.target;
        var inTyper = target && target.hasAttribute && target.hasAttribute('data-oca-input');
        var inBoss = target && target.hasAttribute && target.hasAttribute('data-oca-boss-input');
        if (e.ctrlKey && e.shiftKey && /^Digit[1-59]$/.test(e.code)) {
            e.preventDefault();
            if (e.code === 'Digit9') return defend();
            return useItem(Number(e.code.slice(5)));
        }
        if (inTyper || inBoss) return;
        if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT')) return;
        var map = { ArrowLeft: 'L', ArrowRight: 'R', ArrowUp: 'U', ArrowDown: 'D' };
        if (map[e.key]) {
            e.preventDefault();
            conn.fire('poke');
            return sendMove({ dir: map[e.key], jump: e.ctrlKey || e.metaKey });
        }
        if (e.key === 'Tab') {
            e.preventDefault();
            return sendMove({ dir: e.shiftKey ? 'L' : 'R' });
        }
        if (e.key === 'Home' || e.key === 'End') {
            e.preventDefault();
            var to = e.key === 'Home' ? (e.ctrlKey ? 'first' : 'home') : (e.ctrlKey ? 'last' : 'end');
            return sendMove({ to: to });
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            return occupy();
        }
        if (e.key === 'Delete' || e.key === 'Backspace') {
            e.preventDefault();
            return grab(e.key);
        }
    }

    function occupy() {
        conn.send('occupy').then(function (r) {
            if (r.ok) { Look.sfx('occupy'); return; }
            if (r.why === 'taken') toast(r.by + ' 님이 입력하고 있는 블록이에요', 'warn');
            else if (r.why === 'solved') toast('이미 푼 블록이에요', 'info');
            else if (r.why === 'boss') toast('보스는 옆 칸에서 Delete / Backspace 로 도전해요', 'info');
            else if (r.why !== 'countdown' && r.why !== 'paused') toast(Net.why(r.why), 'warn');
        });
    }

    function onTyperKey(e) {
        var input = e.target;
        if (e.key === 'Enter') {
            e.preventDefault();
            var text = input.value;
            conn.send('submit', { text: text }).then(function (r) {
                if (!r.ok) return toast(Net.why(r.why), 'warn');
                if (r.correct) { Look.sfx('right'); }
                else { Look.sfx('wrong'); toast('틀렸어요! 다시 차지해서 입력해 보세요', 'warn'); }
            });
            input.value = '';
        } else if (e.key === 'Escape') {
            e.preventDefault();
            conn.fire('release');
            input.value = '';
        } else if (e.key === 'Tab') {
            e.preventDefault();
            if (G.hintUntil > now()) {
                var i = heldCell();
                var code = i >= 0 ? G.cells[i].code : '';
                if (code && code.indexOf(input.value) === 0) input.value = code;
            }
        }
    }

    function grab(key) {
        if (!G.boss || G.boss.phase !== 'wait') return;
        conn.send('grab', { key: key }).then(function (r) {
            if (r.ok) { Look.sfx('boss'); openBoss(r.q); }
            else if (r.why === 'not-adjacent') toast('보스 바로 왼쪽(Delete)이나 오른쪽(Backspace) 칸에서 눌러요', 'info');
            else if (r.why !== 'no-boss') toast(Net.why(r.why), 'warn');
        });
    }

    function openBoss(q) {
        G.question = { until: now() + q.ms };
        overlay('<div class="oca-boss-card"><p class="oca-chip oca-chip--boss">BOSS · <span data-oca-qtime></span></p><h2>' + h(q.title) + '</h2>' +
            '<p class="oca-muted">' + (q.blank ? '빈칸(___)에 들어갈 코드를 입력하세요.' : '이 코드를 실행하면 무엇이 출력될까요? (띄어쓰기·줄바꿈은 상관없어요)') + '</p>' +
            '<pre class="oca-code">' + h(q.code) + '</pre>' +
            (q.input ? '<p class="oca-muted">입력값</p><pre class="oca-code oca-code--input">' + h(q.input) + '</pre>' : '') +
            '<input type="text" class="oca-boss-input" data-oca-boss-input maxlength="300" placeholder="' + (q.blank ? '빈칸 코드' : '출력 결과') + '">' +
            '<div class="oca-row"><button type="button" class="oca-btn" data-act="giveup">포기 (Esc)</button><button type="button" class="oca-btn oca-btn--primary" data-act="boss-submit">제출 (Enter)</button></div>' +
            '<p class="oca-muted">기회는 한 번이에요!</p></div>');
        var input = $('[data-oca-boss-input]');
        guardInput(input);
        input.focus();
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); bossSubmit(); }
            if (e.key === 'Escape') { e.preventDefault(); giveUp(); }
        });
    }

    function bossSubmit() {
        var input = $('[data-oca-boss-input]');
        if (!input) return;
        conn.send('boss_submit', { text: input.value }).then(function (r) {
            G.question = null;
            overlay('');
            if (!r.ok) return toast(Net.why(r.why), 'warn');
            if (r.correct) {
                Look.sfx('win');
                toast('보스를 물리쳤어요!' + (r.item ? ' ' + r.item.name + ' 획득' : ''), 'clear');
            } else {
                Look.sfx('wrong');
                toast('아쉬워요, 보스가 사라졌어요', 'warn');
            }
        });
    }

    function giveUp() {
        conn.fire('giveup');
        G.question = null;
        overlay('');
    }

    function useItem(slot) {
        conn.send('item', { slot: slot }).then(function (r) {
            if (r.ok) Look.sfx('item');
            else if (r.why === 'empty') toast(slot + '번 칸이 비었어요', 'info');
            else toast(Net.why(r.why), 'warn');
        });
    }

    function defend() {
        conn.send('defend').then(function (r) {
            if (r.ok) toast('방패로 ' + r.blocked.name + ' 공격을 막았어요!', 'shield');
            else if (r.why === 'no-shield') toast('방패가 없어요', 'warn');
            else if (r.why === 'nothing') toast('막을 공격이 없어요', 'info');
        });
    }

    function showMatchEnd(res) {
        var title = REASON[res.reason] || '경기 끝';
        overlay('<h2>' + h(title) + '</h2><p class="oca-big">' + (res.cleared ? '판 완성!' : res.pct + '% 진행') + ' · ' + clock(res.ms) + '</p>' +
            '<ol class="oca-podium">' + res.players.filter(function (p) { return !p.bot || true; }).map(function (p) {
                return '<li>' + Look.svg(p.avatar, p.color, 22) + ' ' + h(p.name) + ' <b>' + p.solved + '</b>블록' + (p.bosses ? ' · 보스 ' + p.bosses : '') + '</li>';
            }).join('') + '</ol>' +
            (arena && arena.mode === 'class' ? '<p class="oca-muted">다른 팀이 끝나면 전체 순위가 나와요.</p>' : ''));
    }

    // ── server messages ──────────────────────────────────────

    function onMessage(m) {
        switch (m.t) {
            case 'rooms':
                rooms = m.rooms || [];
                if (view === 'free') renderRooms();
                return;
            case 'arena':
                arena = m;
                // In the game view the player leaves through the result
                // overlay ("대기실로"); elsewhere the waiting room follows the
                // latest detail. A "state" message switches to the game.
                if (view === 'game') { drawSide(); return; }
                if (m.phase === 'playing' && m.you && m.you.team) return;
                renderWait();
                return;
            case 'state':
                resultShown = false;
                enterGame(m);
                return;
            case 'feed':
                addFeed(m.text, m.kind);
                return;
            case 'standings':
                drawStandings(m.list);
                return;
            case 'round_result':
                resultShown = true;
                if (arena) arena.lastResult = m.result;
                if (view === 'game') overlay('<h2>경기 결과</h2>' + resultTable(m.result) + '<div class="oca-row"><button type="button" class="oca-btn oca-btn--primary" data-act="back-wait">대기실로</button></div>');
                else if (arena) renderWait();
                return;
        }
        if (!G) return;
        var t = now();
        switch (m.t) {
            case 'pos': {
                var p = G.players[m.id];
                if (!p) return;
                if (m.id === G.me && m.seq >= 0 && m.seq !== G.seq) return; // newer local prediction pending
                movePin(p, m.r, m.c);
                return;
            }
            case 'occ':
                var wasMine = heldCell() === m.i;
                if (m.by) G.occ[m.i] = { by: m.by, until: t + (m.ms || G.limits.occMs) };
                else delete G.occ[m.i];
                redrawCell(m.i);
                placeTyper();
                if (wasMine && !m.by && m.why === 'timeout') toast('입력 시간이 끝나 블록을 놓쳤어요', 'warn');
                drawSide();
                return;
            case 'cell':
                Object.assign(G.cells[m.i], { solved: m.solved, by: m.by, crown: m.crown });
                if (m.solved) delete G.occ[m.i];
                redrawCell(m.i);
                placeTyper();
                drawSide();
                return;
            case 'code':
                G.cells[m.i].code = m.code;
                redrawCell(m.i);
                return;
            case 'stat':
                if (G.players[m.id]) { G.players[m.id].solved = m.solved; G.players[m.id].bosses = m.bosses; }
                drawSide();
                return;
            case 'player':
                G.players[m.p.id] = m.p;
                drawPinsAt(m.p.r * G.cols + m.p.c);
                drawSide();
                return;
            case 'left':
                if (G.players[m.id]) {
                    var lp = G.players[m.id];
                    delete G.players[m.id];
                    drawPinsAt(lp.r * G.cols + lp.c);
                    drawSide();
                }
                return;
            case 'online':
                if (G.players[m.id]) {
                    G.players[m.id].online = m.on;
                    drawPinsAt(G.players[m.id].r * G.cols + G.players[m.id].c);
                    drawSide();
                }
                return;
            case 'boss': {
                var old = G.boss ? G.boss.i : -1;
                if (m.i < 0) {
                    setBoss(null);
                    G.gather = null;
                    if (m.end === 'escaped') addFeed('보스가 도망갔어요', 'info');
                    if (m.end === 'scattered') addFeed('모여라 보스 실패… 다음엔 모두 모여요', 'info');
                    if (m.end === 'gather') { addFeed('모두 모여 보스를 물리쳤어요!', 'clear'); Look.sfx('win'); }
                    if (m.by === G.me && G.question) { G.question = null; overlay(''); }
                } else {
                    var fresh = !G.boss;
                    setBoss(m);
                    if (fresh) Look.sfx('boss');
                }
                if (old >= 0) redrawCell(old);
                if (G.boss) redrawCell(G.boss.i);
                return;
            }
            case 'gather':
                G.gather = { got: m.got, need: m.need };
                return;
            case 'deck':
                G.deck = { slots: m.slots, shields: m.shields };
                drawDeck();
                return;
            case 'hint':
                G.hintUntil = t + m.ms;
                toast('자동 완성! 입력 칸에서 앞 글자를 치고 Tab', 'item');
                return;
            case 'item': {
                var it = m.item || {};
                if (m.kind === 'get') addFeed(m.by + ' 님이 ' + it.name + (m.lost ? '을(를) 얻었지만 자리가 없어 버렸어요' : '을(를) 얻었어요'), 'item');
                else if (m.kind === 'use') addFeed(m.by + ' 님이 ' + it.name + '을(를) 썼어요', 'item');
                else if (m.kind === 'penalty') { addFeed(m.by + ' 님이 ' + it.name + '에 걸렸어요! 팀 전체가 잠시 멈춰요', 'attack'); Look.sfx('hit'); }
                return;
            }
            case 'fx':
                G.fx[m.type] = t + m.ms;
                if (m.type !== 'stall') Look.sfx('hit');
                return;
            case 'incoming':
                G.incoming.push({ id: m.id, item: m.item, from: m.from, until: t + m.ms });
                Look.sfx('hit');
                return;
            case 'incoming_end':
                G.incoming = G.incoming.filter(function (x) { return x.id !== m.id; });
                if (m.blocked) addFeed(m.by + ' 님이 방패로 공격을 막았어요', 'shield');
                return;
            case 'attack_result':
                addFeed(m.to + '팀에 보낸 ' + m.item.name + (m.blocked ? ' 공격이 막혔어요' : ' 공격 성공!'), 'attack');
                return;
            case 'pause':
                if (m.on) G.pausedAt = t;
                else G.pausedAt = 0;
                G.endAt = t + m.endMs;
                G.startAt = t + (m.startMs || 0);
                if (m.on) overlay('<h2>잠시 멈춤</h2><p>선생님이 경기를 멈췄어요.</p>');
                else if (!G.question) overlay('');
                return;
            case 'idle':
                if (m.warn) banner('<b>입력이 없어요!</b> ' + Math.round(m.ms / 1000) + '초 안에 아무 키나 누르지 않으면 방이 닫혀요', 'attack');
                return;
            case 'team_done':
                Look.sfx('win');
                return;
            case 'end':
                G.ended = m.result;
                G.question = null;
                G.occ = {};
                drawGrid();
                if (!resultShown) showMatchEnd(m.result);
                return;
        }
    }

    var resultShown = false;

    // ── events ───────────────────────────────────────────────

    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act], [data-avatar], [data-color]');
        if (!btn) return;
        Look.unlock();
        if (btn.hasAttribute('data-avatar') || btn.hasAttribute('data-color')) {
            var l = currentLook();
            if (btn.hasAttribute('data-avatar')) l.avatar = Number(btn.getAttribute('data-avatar'));
            else l.color = Number(btn.getAttribute('data-color'));
            saveLook(l);
            renderLookPicker();
            return;
        }
        var act = btn.getAttribute('data-act');
        if (act === 'home') return renderHome();
        if (act === 'free') return connect(0);
        if (act === 'join-session') return connect(Number(btn.getAttribute('data-id')));
        if (act === 'join-room') {
            conn.send('room_join', { id: btn.getAttribute('data-id') }).then(function (r) { if (!r.ok) toast(Net.why(r.why), 'warn'); });
            return;
        }
        if (act === 'leave-room') {
            conn.send('leave').then(function () { renderFreeLobby(); });
            return;
        }
        if (act === 'pick-team') {
            conn.send('team', { no: Number(btn.getAttribute('data-no')) }).then(function (r) { if (!r.ok) toast(Net.why(r.why), 'warn'); });
            return;
        }
        if (act === 'bot-add') { conn.send('bot', { op: 'add' }).then(function (r) { if (!r.ok) toast(Net.why(r.why), 'warn'); }); return; }
        if (act === 'bot-remove') { conn.send('bot', { op: 'remove', id: btn.getAttribute('data-id') }); return; }
        if (act === 'start') { conn.send('start').then(function (r) { if (!r.ok) toast(Net.why(r.why), 'warn'); }); return; }
        if (act === 'item') return useItem(Number(btn.getAttribute('data-slot')));
        if (act === 'boss-submit') return bossSubmit();
        if (act === 'giveup') return giveUp();
        if (act === 'back-wait') { resultShown = false; G = null; renderWait(); return; }
        if (act === 'mute') {
            Look.setMuted(!Look.muted());
            btn.textContent = Look.muted() ? '소리 켜기' : '소리 끄기';
            return;
        }
        if (act === 'fullscreen') {
            var el = $('.oca-app');
            if (document.fullscreenElement) document.exitFullscreen();
            else if (el.requestFullscreen) el.requestFullscreen();
        }
    });

    root.addEventListener('change', function (e) {
        var sel = e.target.closest('[data-oca-setting]');
        if (sel && conn) {
            var s = {};
            s[sel.getAttribute('data-oca-setting')] = Number(sel.value);
            conn.send('settings', { settings: s }).then(function (r) { if (!r.ok) toast(Net.why(r.why), 'warn'); });
        }
    });

    root.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-oca-create]');
        if (!form) return;
        e.preventDefault();
        var data = new FormData(form);
        conn.send('room_create', {
            title: data.get('title'),
            settings: { limitMin: Number(data.get('limitMin')), maxPlayers: Number(data.get('maxPlayers')), tags: data.getAll('tags') },
        }).then(function (r) { if (!r.ok) toast(Net.why(r.why), 'warn'); });
    });

    document.addEventListener('keydown', onGameKey);
    window.addEventListener('resize', function () { if (view === 'game' && G) placeTyper(); });
    window.addEventListener('beforeunload', function (e) {
        if (view === 'game' && G && !G.ended) { e.preventDefault(); e.returnValue = ''; }
    });

    shell();
    renderHome();
})(window, document);
