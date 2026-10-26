/*
 * Code Arena — teacher console: create class games, then run them live
 * (teams, bots, start/pause/stop, attacks, kick) through the game server.
 */
(function (window, document) {
    'use strict';

    var Net = window.ArenaNet;
    var Look = window.ArenaLook;
    var root = document.querySelector('[data-oca-teacher]');
    if (!root || !Net || !Look) return;
    var cfg = JSON.parse(root.getAttribute('data-config') || '{}');

    var sessions = [];
    var defaults = {};
    var contexts = [];
    var current = null;     // selected session (REST row)
    var conn = null;
    var live = null;        // latest arena detail
    var standings = [];
    var remain = { ms: 0, at: 0, paused: false };
    var confirmAt = {};
    var feed = [];
    var LOADING = '<p class="oca-muted">불러오는 중…</p>';
    var recordsHtml = LOADING;
    var renderTimer = 0;

    var STATE = { online: '대기', offline: '접속 끊김', playing: '이동 중', typing: '입력 중', boss: '보스 전투' };
    var REASON = { done: '모든 팀 종료', stop: '선생님이 종료', time: '시간 종료' };

    function h(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function $(sel, el) { return (el || root).querySelector(sel); }
    function clock(ms) {
        var s = Math.max(0, Math.ceil(ms / 1000));
        return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    }

    function empty(text) {
        return '<div class="ojp-empty-state"><div><p>' + h(text) + '</p></div></div>';
    }

    // Success goes to the platform toast when it exists; errors stay on the page.
    function notice(text, kind) {
        var box = $('[data-oca-notice]');
        if (!box) return;
        if (text && kind === 'ok' && window.OJP && typeof window.OJP.toast === 'function') {
            window.OJP.toast(text);
            box.innerHTML = '';
            return;
        }
        var tone = { ok: 'success', error: 'danger' }[kind] || 'info';
        box.innerHTML = text ? '<div class="ojp-notice ojp-notice--' + tone + '" role="' + (kind === 'error' ? 'alert' : 'status') + '"><p>' + h(text) + '</p></div>' : '';
        if (text && kind !== 'error') setTimeout(function () { if (box.textContent === text) box.innerHTML = ''; }, 4000);
    }

    // Ask before an action that cannot be undone: the platform sheet
    // (OJP.confirm) when present, otherwise a second press within 3 s.
    function ask(key, btn, opts, run) {
        if (window.OJP && typeof window.OJP.confirm === 'function') {
            window.OJP.confirm({ title: opts.title, message: opts.message || '', confirmLabel: opts.confirmLabel, destructive: true })
                .then(function (ok) { if (ok) run(); });
            return;
        }
        if (confirmed(key, btn, opts.armLabel)) run();
    }

    // Fallback: buttons that need a second press within 3 s.
    function confirmed(key, btn, label) {
        var t = Date.now();
        if (confirmAt[key] && t - confirmAt[key] < 3000) { confirmAt[key] = 0; return true; }
        confirmAt[key] = t;
        var old = btn.textContent;
        btn.textContent = label || '한 번 더 누르면 실행합니다';
        btn.classList.add('is-armed');
        setTimeout(function () { btn.textContent = old; btn.classList.remove('is-armed'); }, 3000);
        return false;
    }

    // ── layout ───────────────────────────────────────────────

    function shell() {
        root.innerHTML =
            '<div class="oca-console">' +
            '<header class="ojp-page-header"><div><p class="ojp-eyebrow">코드 아레나</p><h1>수업 게임</h1>' +
            '<p>반이나 그룹을 골라 게임을 만듭니다. 학생이 [코드 아레나] 메뉴로 들어오면 팀을 나눠 시작합니다.</p></div></header>' +
            '<div data-oca-notice></div>' +
            '<div class="oca-console-grid">' +
            '<aside class="oca-console-side">' +
            '  <section class="oca-card-box"><h2>내 수업 게임</h2><ul class="oca-session-list" data-oca-list><li class="oca-muted">불러오는 중…</li></ul></section>' +
            '  <section class="oca-card-box"><h2>새 수업 게임</h2><form data-oca-new class="oca-stack"></form></section>' +
            '</aside>' +
            '<div class="oca-console-main" data-oca-live>' + empty('왼쪽에서 수업 게임을 고르거나 새로 만듭니다.') + '</div>' +
            '</div></div>';
    }

    function settingsFields(s, prefix) {
        s = s || {};
        var sel = function (name, opts, val, fmt) {
            return '<select class="ojp-select" name="' + name + '">' + opts.map(function (o) {
                return '<option value="' + o + '"' + (String(o) === String(val) ? ' selected' : '') + '>' + (fmt ? fmt(o) : o) + '</option>';
            }).join('') + '</select>';
        };
        var tags = (cfg.tags || []).map(function (t) {
            return '<label class="ojp-toggle-row"><input type="checkbox" name="tags" value="' + h(t) + '"' + ((s.tags || []).indexOf(t) >= 0 ? ' checked' : '') + '><span>' + h(t) + '</span></label>';
        }).join('');
        var field = function (label, control) { return '<label class="ojp-field"><span>' + label + '</span>' + control + '</label>'; };
        return '<div class="oca-fields">' +
            field('팀 수', sel('teams', [2, 3, 4, 5, 6, 7, 8], s.teams || 4)) +
            field('경기 시간', sel('limitMin', [3, 4, 5, 6, 7, 8, 10, 12, 15, 20], s.limitMin || 6, function (v) { return v + '분'; })) +
            field('블록 수', sel('blocks', [0, 24, 36, 48, 60, 72, 84, 96, 120, 144], s.blocks || 0, function (v) { return v ? v + '개' : '자동 (1인당 24)'; })) +
            field('블록 점유 시간', sel('occSec', [20, 30, 45, 60], s.occSec || 30, function (v) { return v + '초'; })) +
            field('보스 간격', sel('bossEverySec', [15, 20, 30, 45, 60], s.bossEverySec || 20, function (v) { return v + '초마다'; })) +
            field('보스 기다림', sel('bossWaitSec', [5, 8, 12, 20], s.bossWaitSec || 8, function (v) { return v + '초'; })) +
            field('보스 풀이 시간', sel('bossLimitSec', [20, 30, 45, 60, 90], s.bossLimitSec || 30, function (v) { return v + '초'; })) +
            field('봇 속도', sel('botPace', ['slow', 'normal', 'fast'], s.botPace || 'normal', function (v) { return { slow: '느림', normal: '보통', fast: '빠름' }[v]; })) +
            '</div>' +
            '<div class="oca-toggles">' +
            '<label class="ojp-toggle-row"><input type="checkbox" name="selfPick"' + (s.selfPick !== false ? ' checked' : '') + '><span>학생이 팀 고르기</span></label>' +
            '<label class="ojp-toggle-row"><input type="checkbox" name="penalty"' + (s.penalty !== false ? ' checked' : '') + '><span>벌칙 아이템(정전)</span></label>' +
            '<label class="ojp-toggle-row"><input type="checkbox" name="attacks"' + (s.attacks ? ' checked' : '') + '><span>팀끼리 공격 아이템</span></label>' +
            '</div>' +
            '<fieldset class="ojp-fieldset"><legend>문제 단원 (비우면 전체)</legend><div class="oca-tagset">' + (tags || '<span class="oca-muted">문제은행에 태그가 없습니다.</span>') + '</div></fieldset>';
    }

    function readSettings(form) {
        var d = new FormData(form);
        return {
            teams: Number(d.get('teams')), limitMin: Number(d.get('limitMin')), blocks: Number(d.get('blocks')),
            occSec: Number(d.get('occSec')), bossEverySec: Number(d.get('bossEverySec')), bossWaitSec: Number(d.get('bossWaitSec')),
            bossLimitSec: Number(d.get('bossLimitSec')), botPace: d.get('botPace'),
            selfPick: !!d.get('selfPick'), penalty: !!d.get('penalty'), attacks: !!d.get('attacks'), tags: d.getAll('tags'),
        };
    }

    function renderNewForm() {
        var form = $('[data-oca-new]');
        var ctx = '<option value="">명단 없이 (참가 권한이 있는 모든 학생)</option>' + contexts.map(function (c) {
            return '<option value="' + h(c.type + '|' + c.id) + '">' + (c.type === 'class' ? '반' : '그룹') + ' · ' + h(c.label) + '</option>';
        }).join('');
        form.innerHTML =
            '<label class="ojp-field"><span>제목</span><input class="ojp-input" name="title" maxlength="80" required placeholder="예: 3반 반복문 대항전"></label>' +
            '<label class="ojp-field"><span>참가 대상</span><select class="ojp-select" name="context">' + ctx + '</select></label>' +
            '<details><summary>게임 설정</summary>' + settingsFields(defaults) + '</details>' +
            '<div><button class="ojp-button ojp-button--tinted">만들기</button></div>';
    }

    function renderList() {
        var box = $('[data-oca-list]');
        if (!sessions.length) {
            box.innerHTML = '<li class="oca-muted">아직 만든 수업 게임이 없습니다.</li>';
            return;
        }
        box.innerHTML = sessions.map(function (s) {
            return '<li><button type="button" class="oca-session' + (current && current.id === s.id ? ' is-on' : '') + '" data-act="open" data-id="' + s.id + '">' +
                '<strong>' + h(s.title) + '</strong><span>' + h(s.context || '명단 없음') + '</span></button></li>';
        }).join('');
    }

    function load() {
        return Promise.all([Net.rest(cfg, 'GET', '/sessions'), Net.rest(cfg, 'GET', '/contexts').catch(function () { return { contexts: [] }; })])
            .then(function (res) {
                sessions = res[0].sessions || [];
                defaults = res[0].defaults || {};
                if (res[0].tags) cfg.tags = res[0].tags;
                contexts = res[1].contexts || [];
                renderList();
                renderNewForm();
            })
            .catch(function (err) { notice(err.message, 'error'); });
    }

    // ── live control ─────────────────────────────────────────

    function openSession(id) {
        current = sessions.filter(function (s) { return s.id === id; })[0];
        if (!current) return;
        renderList();
        if (conn) conn.stop();
        live = null;
        standings = [];
        feed = [];
        recordsHtml = LOADING;
        loadRecords();
        $('[data-oca-live]').innerHTML = empty('게임 서버에 연결하는 중…');
        conn = new Net.Connection({
            cfg: cfg,
            ticket: function () { return Net.rest(cfg, 'POST', '/sessions/' + id + '/ticket', { role: 'teacher' }); },
            onStatus: function (state, detail) {
                var dot = $('[data-oca-net]');
                if (dot) dot.className = 'oca-net oca-net--' + state;
                if (state === 'failed') $('[data-oca-live]').innerHTML = '<div class="ojp-notice ojp-notice--danger" role="alert"><strong>' + h(Net.why(detail)) + '</strong><p>사이트 관리자나 설정 권한을 받은 교사가 교사 메뉴 › 코드 아레나 › 설정에서 게임 서버 주소와 비밀키를 확인해야 합니다.</p></div>';
                if (state === 'closed') { notice('수업 게임이 닫혔습니다.', 'info'); }
            },
            onMessage: onMessage,
        });
        conn.start();
    }

    function onMessage(m) {
        if (m.t === 'arena') {
            live = m;
            if (m.round) remain = { ms: m.round.remainMs, at: performance.now(), paused: m.round.paused, startMs: m.round.startMs };
            if (!document.querySelector('[data-oca-teams-ready]')) renderLive();
            else scheduleRender();
        } else if (m.t === 'standings') {
            standings = m.list;
            remain = { ms: m.remainMs, at: performance.now(), paused: remain.paused, startMs: 0 };
            drawStandings();
        } else if (m.t === 'feed') {
            feed.push(m);
            if (feed.length > 30) feed.shift();
            drawFeed();
        } else if (m.t === 'round_result') {
            if (live) live.lastResult = m.result;
            renderLive();
            setTimeout(loadRecords, 1500); // WordPress saves the round asynchronously
        }
    }

    function teamOptions(selected) {
        var out = '<option value="0"' + (selected === 0 ? ' selected' : '') + '>미배정</option>';
        for (var n = 1; n <= live.settings.teams; n++) out += '<option value="' + n + '"' + (n === selected ? ' selected' : '') + '>' + n + '팀</option>';
        return out;
    }

    function memberRow(m, team) {
        if (m.bot) {
            return '<li class="oca-member is-bot">' + Look.svg(m.avatar, m.color, 22) + '<span>' + h(m.name) + '</span><small>봇</small>' +
                '<button type="button" class="oca-x" data-act="bot-remove" data-id="' + h(m.id) + '" aria-label="' + h(m.name) + ' 빼기">빼기</button></li>';
        }
        return '<li class="oca-member' + (m.online ? '' : ' is-off') + '">' + Look.svg(m.avatar, m.color, 22) + '<span>' + h(m.name) + '</span>' +
            '<small class="oca-state oca-state--' + h(m.state) + '">' + h(STATE[m.state] || '') + (live.phase === 'playing' && team ? ' · ' + m.solved : '') + '</small>' +
            '<select class="ojp-select" data-act="move" data-uid="' + m.uid + '" aria-label="' + h(m.name) + ' 팀 옮기기">' + teamOptions(team) + '</select>' +
            '<button type="button" class="oca-x" data-act="kick" data-uid="' + m.uid + '" title="내보내기">내보내기</button></li>';
    }

    function renderLive() {
        if (!live || !current) return;
        var playing = live.phase === 'playing';
        var people = live.teams.reduce(function (n, t) { return n + t.members.filter(function (m) { return !m.bot; }).length; }, 0) + live.waiting.length;
        var online = live.teams.concat([{ members: live.waiting }]).reduce(function (n, t) { return n + t.members.filter(function (m) { return !m.bot && m.online; }).length; }, 0);
        var teams = live.teams.map(function (t) {
            var st = standings.filter(function (s) { return s.team === t.no; })[0];
            return '<div class="oca-team-box">' +
                '<h3>' + t.no + '팀 <span class="oca-muted">' + t.members.length + '명</span>' +
                (st ? '<span class="oca-team-pct">' + (st.cleared ? st.rank + '위 완성' : st.pct + '%') + '</span>' : '') + '</h3>' +
                (st ? '<div class="oca-bar"><i style="width:' + st.pct + '%"></i></div>' : '') +
                '<ul>' + (t.members.map(function (m) { return memberRow(m, t.no); }).join('') || '<li class="oca-muted">아직 팀원이 없습니다.</li>') + '</ul>' +
                '<div><button type="button" class="ojp-button ojp-button--small ojp-button--plain" data-act="bot-add" data-team="' + t.no + '">봇 추가</button></div>' +
                '</div>';
        }).join('');
        var waiting = live.waiting.length
            ? '<section class="oca-card-box"><h3>팀 없는 학생 (' + live.waiting.length + ')</h3><ul class="oca-waiting">' + live.waiting.map(function (m) { return memberRow(m, 0); }).join('') + '</ul></section>' : '';

        var controls = playing
            ? '<button type="button" class="ojp-button" data-act="pause">' + (live.round && live.round.paused ? '다시 시작' : '잠시 멈춤') + '</button>' +
              '<button type="button" class="ojp-button ojp-button--danger" data-act="stop">경기 끝내기</button>'
            : '<button type="button" class="ojp-button" data-act="auto">팀 자동 배정</button>' +
              '<button type="button" class="ojp-button" data-act="bot-fill">봇으로 인원 맞추기</button>' +
              '<button type="button" class="ojp-button" data-act="bot-clear">봇 모두 빼기</button>' +
              '<button type="button" class="ojp-button ojp-button--primary ojp-button--large" data-act="start">경기 시작</button>';

        // Keep the settings form the teacher may be editing.
        var keep = !playing ? $('[data-oca-settings-box]') : null;
        $('[data-oca-live]').innerHTML =
            '<section class="oca-card-box oca-live-head">' +
            '<div class="oca-row"><h2>' + h(current.title) + '</h2><span class="ojp-status-badge ojp-status-badge--info">' + h(current.context || '명단 없음') + '</span>' +
            '<span class="oca-net oca-net--open" data-oca-net title="게임 서버 연결"></span>' +
            '<span class="oca-live-timer" data-oca-timer>' + (playing ? '' : '대기 중') + '</span></div>' +
            '<p class="oca-muted">접속 ' + online + '명 / 참가 ' + people + '명' + (live.rosterSize ? ' · 명단 ' + live.rosterSize + '명' : '') + ' · ' + (live.roundNo ? live.roundNo + '번째 경기까지 진행' : '아직 경기 전') + '</p>' +
            '<div class="oca-row oca-controls">' + controls +
            '<label class="ojp-toggle-row"><input type="checkbox" data-act="attacks"' + (live.attacks ? ' checked' : '') + '><span>공격 아이템</span></label>' +
            '<span class="oca-spacer"></span>' +
            '<a class="ojp-button" href="' + h(cfg.boardUrl + (cfg.boardUrl.indexOf('?') >= 0 ? '&' : '?') + 'session=' + current.id) + '" target="_blank" rel="noopener">전광판 열기</a>' +
            '<button type="button" class="ojp-button ojp-button--danger" data-act="end-session">수업 게임 닫기</button>' +
            '</div></section>' +
            (playing ? '' :
                '<section class="oca-card-box" data-oca-settings-box><details' + (live.roundNo ? '' : ' open') + '><summary>게임 설정 바꾸기</summary><form data-oca-settings class="oca-stack">' + settingsFields(live.settings) +
                '<label class="ojp-field"><span>제목</span><input class="ojp-input" name="title" maxlength="80" value="' + h(current.title) + '"></label>' +
                '<div><button class="ojp-button ojp-button--tinted">설정 저장</button></div></form></details></section>') +
            '<div class="oca-team-grid" data-oca-teams-ready>' + teams + '</div>' + waiting +
            '<div class="oca-two">' +
            '<section class="oca-card-box"><h3>소식</h3><ul class="oca-feed-list" data-oca-feed></ul></section>' +
            '<section class="oca-card-box"><h3>최근 결과</h3><div data-oca-result>' + resultTable(live.lastResult) + '</div></section>' +
            '</div>' +
            '<section class="oca-card-box"><h3>이 수업의 기록</h3><div data-oca-records>' + recordsHtml + '</div></section>';
        var fresh = $('[data-oca-settings-box]');
        if (keep && fresh) fresh.replaceWith(keep);
        drawFeed();
        tick();
    }

    // Re-render at most every 600 ms, and not while the teacher has a
    // team drop-down open.
    function scheduleRender() {
        if (renderTimer) return;
        renderTimer = setTimeout(function () {
            renderTimer = 0;
            var active = document.activeElement;
            if (active && active.tagName === 'SELECT' && root.contains(active)) return scheduleRender();
            renderLive();
        }, 600);
    }

    function drawStandings() {
        if (live && live.phase === 'playing') scheduleRender();
    }

    function drawFeed() {
        var box = $('[data-oca-feed]');
        if (!box) return;
        var list = feed.length ? feed : (live && live.feed) || [];
        box.innerHTML = list.slice(-12).reverse().map(function (f) { return '<li class="oca-feed--' + h(f.kind) + '">' + h(f.text) + '</li>'; }).join('') || '<li class="oca-muted">아직 소식이 없습니다.</li>';
    }

    function resultTable(r) {
        if (!r) return '<p class="oca-muted">아직 결과가 없습니다.</p>';
        return '<p class="oca-muted">' + h(r.round) + '번째 경기 · ' + h(REASON[r.reason] || r.reason) + '</p><div class="ojp-table-wrap"><table class="ojp-table"><thead><tr><th>순위</th><th>팀</th><th>진행</th><th>시간</th><th>보스</th><th>공격(보냄/받음/막음)</th></tr></thead><tbody>' +
            r.teams.map(function (t) {
                return '<tr><td>' + t.rank + '위</td><td>' + t.team + '팀</td><td>' + (t.cleared ? '완성' : t.pct + '%') + '</td><td>' + clock(t.ms) + '</td><td>' + t.bosses + '</td><td>' +
                    t.attacks.sent + ' / ' + t.attacks.got + ' / ' + t.attacks.blocked + '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    function loadRecords() {
        if (!current) return;
        var id = current.id;
        Net.rest(cfg, 'GET', '/sessions/' + id + '/records').then(function (data) {
            var box = $('[data-oca-records]');
            if (!box || !current || current.id !== id) return;
            var rows = data.rounds || [];
            if (!rows.length) { recordsHtml = box.innerHTML = '<p class="oca-muted">저장된 경기가 없습니다.</p>'; return; }
            recordsHtml = box.innerHTML = '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr><th>경기</th><th>끝난 시각</th><th>팀 순위</th></tr></thead><tbody>' + rows.map(function (r) {
                var when = r.ended_at_label || new Date((r.endedAt || r.ended_at) * 1000).toLocaleString('ko-KR');
                var teams = (r.teams || []).map(function (t) { return t.rank + '위 ' + t.team + '팀(' + (t.cleared ? '완성' : t.pct + '%') + ')'; }).join(', ');
                return '<tr><td>' + h(r.round || r.round_no) + '</td><td>' + h(when) + '</td><td>' + h(teams) + '</td></tr>';
            }).join('') + '</tbody></table></div>';
        }).catch(function () { /* records are optional here */ });
    }

    function tick() {
        var el = $('[data-oca-timer]');
        if (!el || !live || live.phase !== 'playing') return;
        var elapsed = remain.paused ? 0 : performance.now() - remain.at;
        var startLeft = (remain.startMs || 0) - elapsed;
        if (remain.paused) el.textContent = '멈춤 ' + clock(remain.ms);
        else if (startLeft > 0) el.textContent = '준비 ' + Math.ceil(startLeft / 1000);
        else el.textContent = clock(remain.ms - Math.max(0, elapsed - (remain.startMs || 0)));
    }
    setInterval(tick, 250);

    function send(t, data) {
        if (!conn) return Promise.resolve({ ok: false });
        return conn.send(t, data).then(function (r) {
            if (r && !r.ok) notice(Net.why(r.why), 'error');
            return r;
        });
    }

    // ── events ───────────────────────────────────────────────

    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn || btn.tagName === 'SELECT' || btn.type === 'checkbox') return;
        var act = btn.getAttribute('data-act');
        if (act === 'open') return openSession(Number(btn.getAttribute('data-id')));
        if (act === 'start') return send('start');
        if (act === 'pause') return send('pause', { on: !(live.round && live.round.paused) });
        if (act === 'stop') {
            ask('stop', btn, { title: '경기를 끝낼까요?', message: '지금까지의 진행으로 순위를 정하고 기록을 저장합니다.', confirmLabel: '경기 끝내기', armLabel: '한 번 더 누르면 끝냅니다' }, function () { send('stop'); });
            return;
        }
        if (act === 'auto') return send('auto');
        if (act === 'bot-fill') return send('bot_fill');
        if (act === 'bot-clear') {
            ask('botclear', btn, { title: '봇을 모두 뺄까요?', confirmLabel: '모두 빼기', armLabel: '한 번 더 누르면 모두 뺍니다' }, function () { send('bot_clear'); });
            return;
        }
        if (act === 'bot-add') return send('bot_add', { team: Number(btn.getAttribute('data-team')) });
        if (act === 'bot-remove') return send('bot_remove', { id: btn.getAttribute('data-id') });
        if (act === 'kick') {
            var uid = Number(btn.getAttribute('data-uid'));
            ask('kick' + uid, btn, { title: '이 학생을 내보낼까요?', message: '내보낸 학생은 이 수업 게임의 소식을 더 받지 못합니다.', confirmLabel: '내보내기', armLabel: '확인' }, function () { send('kick', { uid: uid }); });
            return;
        }
        if (act === 'end-session') {
            ask('end', btn, { title: '수업 게임을 닫을까요?', message: '학생은 더 들어올 수 없습니다. 끝난 경기 기록은 [경기 기록]에 남습니다.', confirmLabel: '닫기', armLabel: '한 번 더 누르면 닫습니다' }, function () {
                var id = current.id;
                Net.rest(cfg, 'DELETE', '/sessions/' + id).then(function () {
                    if (conn) conn.stop();
                    conn = null;
                    current = null;
                    live = null;
                    $('[data-oca-live]').innerHTML = empty('수업 게임을 닫았습니다. 기록은 [경기 기록]에서 볼 수 있습니다.');
                    return load();
                }).catch(function (err) { notice(err.message, 'error'); });
            });
        }
    });

    root.addEventListener('change', function (e) {
        var el = e.target;
        if (el.getAttribute('data-act') === 'move') send('move', { uid: Number(el.getAttribute('data-uid')), team: Number(el.value) });
        if (el.getAttribute('data-act') === 'attacks') send('attacks', { on: el.checked });
    });

    root.addEventListener('submit', function (e) {
        var form = e.target;
        e.preventDefault();
        if (form.hasAttribute('data-oca-new')) {
            var d = new FormData(form);
            var ctx = String(d.get('context') || '').split('|');
            var label = form.querySelector('[name=context]').selectedOptions[0];
            Net.rest(cfg, 'POST', '/sessions', {
                title: d.get('title'), context_type: ctx[0] || '', context_id: ctx[1] || '',
                settings: readSettings(form),
            }).then(function (res) {
                notice('수업 게임을 만들었습니다. 학생에게 [코드 아레나] 메뉴로 들어오라고 안내합니다.', 'ok');
                return load().then(function () { openSession(res.session.id); });
            }).catch(function (err) { notice(err.message, 'error'); });
            void label;
        } else if (form.hasAttribute('data-oca-settings')) {
            var title = new FormData(form).get('title');
            Net.rest(cfg, 'PUT', '/sessions/' + current.id, { title: title, settings: readSettings(form) }).then(function (res) {
                current = res.session || current;
                sessions = sessions.map(function (s) { return s.id === current.id ? current : s; });
                renderList();
                notice('설정을 저장했습니다.', 'ok');
            }).catch(function (err) { notice(err.message, 'error'); });
        }
    });

    shell();
    load().then(function () {
        var want = Number(new URLSearchParams(window.location.search).get('session'));
        if (want) openSession(want);
    });
})(window, document);
