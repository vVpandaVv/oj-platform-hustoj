/*
 * 코드 아레나 설정 → 부하 시험 (built-in engine). Runs on the OJ console
 * settings page (교사 메뉴 › 코드 아레나 › 설정).
 * Virtual learners poll relay.php like real browsers (about every 0.4 s),
 * move, occupy and type the right code; the page reports latency.
 */
(function (window, document) {
    'use strict';

    var root = document.getElementById('oca-loadtest');
    if (!root) return;
    var cfg = JSON.parse(root.getAttribute('data-config') || '{}');
    var out = root.querySelector('[data-lt-out]');
    var btn = root.querySelector('[data-lt-start]');
    var DIRS = ['L', 'R', 'U', 'D'];

    function rest(method, route, body) {
        return fetch(cfg.restUrl + route, {
            method: method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: body ? JSON.stringify(body) : undefined,
        }).then(function (r) {
            return r.json().then(function (d) {
                if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status));
                return d;
            });
        });
    }

    function pct(list, p) {
        if (!list.length) return 0;
        var s = list.slice().sort(function (a, b) { return a - b; });
        return Math.round(s[Math.min(s.length - 1, Math.floor(s.length * p))]);
    }

    function run(count, minutes) {
        var stats = { lat: [], errors: 0, requests: 0, solved: 0, started: 0, joined: 0, lastError: '' };
        var stop = false;
        var url = '';
        var info = null;

        function post(body) {
            var t0 = performance.now();
            stats.requests++;
            return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (d) {
                    stats.lat.push(performance.now() - t0);
                    if (!d.ok) { stats.errors++; stats.lastError = d.why || 'error'; }
                    return d;
                })
                .catch(function (e) {
                    stats.errors++;
                    stats.lastError = e.message;
                    if (url === info.relay && info.rest) url = info.rest; // relay.php blocked here
                    return null;
                });
        }

        function bot(ticket, k) {
            var b = { ticket: ticket, cursor: 0, me: '', cells: [], occ: {}, r: 0, c: 0, cols: 12, rows: 2, holding: -1, boss: -1, playing: false, teamSent: false, rid: 0 };
            function step() {
                if (stop) return;
                var cmds = [];
                if (b.cursor && !b.teamSent) { cmds.push({ t: 'team', no: (k % info.teams) + 1, rid: ++b.rid }); b.teamSent = true; stats.joined++; }
                if (b.playing) {
                    var i = b.r * b.cols + b.c;
                    if (b.holding >= 0) cmds.push({ t: 'submit', text: b.cells[b.holding].code, rid: ++b.rid });
                    else if (b.cells[i] && !b.cells[i].solved && !b.occ[i] && b.boss !== i) cmds.push({ t: 'occupy', rid: ++b.rid });
                    else cmds.push({ t: 'move', dir: DIRS[Math.floor(Math.random() * 4)], seq: -1 });
                }
                post({ ticket: b.ticket, cursor: b.cursor, cmds: cmds }).then(function (d) {
                    if (d && d.ok) {
                        if (d.ticket) b.ticket = d.ticket;
                        b.cursor = d.cursor;
                        (d.events || []).forEach(function (m) {
                            if (m.t === 'state') {
                                b.playing = !m.ended; b.me = m.me; b.cols = m.cols; b.rows = m.rows; b.cells = m.cells; b.occ = {};
                                m.players.forEach(function (p) { if (p.id === b.me) { b.r = p.r; b.c = p.c; } });
                                b.boss = m.boss ? m.boss.i : -1;
                            } else if (m.t === 'pos' && m.id === b.me) { b.r = m.r; b.c = m.c; }
                            else if (m.t === 'occ') {
                                if (m.by) b.occ[m.i] = m.by; else delete b.occ[m.i];
                                if (m.by === b.me) b.holding = m.i; else if (b.holding === m.i) b.holding = -1;
                            } else if (m.t === 'cell' && b.cells[m.i]) { b.cells[m.i].solved = m.solved; if (m.solved && b.holding === m.i) b.holding = -1; }
                            else if (m.t === 'code' && b.cells[m.i]) b.cells[m.i].code = m.code;
                            else if (m.t === 'boss') b.boss = m.i;
                            else if (m.t === 'end') b.playing = false;
                        });
                        (d.acks || []).forEach(function (a) { if (a.correct) stats.solved++; });
                    }
                    setTimeout(step, 350 + Math.random() * 100);
                });
            }
            setTimeout(step, Math.random() * 400);
        }

        function teacher() {
            var t = { ticket: info.teacher, cursor: 0, rid: 0, startAt: performance.now() + 3000, sent: false };
            var endAt = performance.now() + minutes * 60000 + 8000;
            function step() {
                if (stop) return;
                var cmds = [];
                if (!t.sent && performance.now() > t.startAt && stats.joined >= count) { cmds.push({ t: 'start', rid: ++t.rid }); t.sent = true; stats.started = performance.now(); }
                var done = performance.now() > endAt;
                if (done) cmds.push({ t: 'stop', rid: ++t.rid });
                post({ ticket: t.ticket, cursor: t.cursor, cmds: cmds }).then(function (d) {
                    if (d && d.ok) { t.cursor = d.cursor; if (d.ticket) t.ticket = d.ticket; }
                    if (done) return finish();
                    setTimeout(step, 800);
                });
            }
            step();
        }

        function finish() {
            stop = true;
            clearInterval(timer);
            draw(true);
            rest('DELETE', '/admin/loadtest/' + info.session).catch(function () { /* best effort */ });
            btn.disabled = false;
        }

        function draw(final) {
            var secs = stats.started ? (performance.now() - stats.started) / 1000 : 0;
            var recent = stats.lat.slice(-600);
            out.innerHTML =
                '<div class="ojp-table-wrap oca-loadtest-table"><table class="ojp-table"><tbody>' +
                '<tr><th>상태</th><td>' + (final ? '끝 (시험 게임과 기록은 지웠어요)' : (stats.started ? '경기 중 ' + Math.round(secs) + '초' : '입장 중 ' + stats.joined + '/' + count)) + '</td></tr>' +
                '<tr><th>요청</th><td>' + stats.requests + '건 · 초당 약 ' + (secs ? Math.round(stats.requests / Math.max(1, secs + 3)) : '-') + '건</td></tr>' +
                '<tr><th>응답 시간 (최근)</th><td>중앙값 ' + pct(recent, 0.5) + 'ms · 95% ' + pct(recent, 0.95) + 'ms · 최대 ' + pct(recent, 1) + 'ms</td></tr>' +
                '<tr><th>응답 시간 (전체)</th><td>중앙값 ' + pct(stats.lat, 0.5) + 'ms · 95% ' + pct(stats.lat, 0.95) + 'ms</td></tr>' +
                '<tr><th>오류</th><td>' + stats.errors + (stats.lastError ? ' (마지막: ' + String(stats.lastError).replace(/[<>&]/g, '') + ')' : '') + '</td></tr>' +
                '<tr><th>푼 블록</th><td>' + stats.solved + '</td></tr>' +
                '<tr><th>통신 경로</th><td>' + (url === info.relay ? 'relay.php (가벼운 입구)' : 'REST (대체 경로)') + '</td></tr>' +
                '</tbody></table></div>' +
                (final ? '<p>' + verdict(pct(stats.lat, 0.95), stats.errors, stats.requests) + '</p>' : '');
        }

        function verdict(p95, errors, total) {
            if (errors > total * 0.02) return '오류가 많아요. 호스팅 사양을 확인하거나 Node 게임 서버를 쓰는 것을 권해요.';
            if (p95 > 1500) return '응답이 느려요(95% ' + p95 + 'ms). 이 인원으로는 반응이 늦게 느껴질 수 있어요.';
            if (p95 > 600) return '보통이에요(95% ' + p95 + 'ms). 수업은 할 수 있지만 인원을 늘리면 느려질 수 있어요.';
            return '좋아요(95% ' + p95 + 'ms). 이 인원은 내장 엔진으로 충분해요.';
        }

        var timer = setInterval(function () { if (info) draw(false); }, 1000);
        out.textContent = '시험 게임을 만드는 중…';
        rest('POST', '/admin/loadtest', { count: count, minutes: minutes }).then(function (data) {
            info = data;
            url = data.relay;
            data.tickets.forEach(bot);
            teacher();
        }).catch(function (e) {
            clearInterval(timer);
            out.textContent = '시작하지 못했어요: ' + e.message;
            btn.disabled = false;
        });
    }

    btn.addEventListener('click', function () {
        var count = Math.max(1, Math.min(60, parseInt(root.querySelector('[data-lt-count]').value, 10) || 30));
        var minutes = Math.max(1, Math.min(5, parseInt(root.querySelector('[data-lt-minutes]').value, 10) || 1));
        btn.disabled = true;
        run(count, minutes);
    });
})(window, document);
