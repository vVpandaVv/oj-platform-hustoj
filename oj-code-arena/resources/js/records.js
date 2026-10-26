/*
 * Code Arena — round records for the teacher (per round: teams and
 * every student's solved blocks), with CSV export.
 */
(function (window, document) {
    'use strict';

    var Net = window.ArenaNet;
    var root = document.querySelector('[data-oca-records]');
    if (!root || !Net) return;
    var cfg = JSON.parse(root.getAttribute('data-config') || '{}');
    var rounds = [];
    var sessionFilter = '';

    var REASON = { done: '모든 팀 종료', stop: '선생님이 종료', idle: '입력 없음', time: '시간 종료' };

    function h(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function $(sel) { return root.querySelector(sel); }
    function clock(ms) {
        var s = Math.max(0, Math.ceil((ms || 0) / 1000));
        return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    }
    function when(r) {
        return r.ended_label || new Date((r.ended_at || r.endedAt || 0) * 1000).toLocaleString('ko-KR');
    }

    root.innerHTML =
        '<div class="oca-console">' +
        '<header class="ojp-page-header"><div><p class="ojp-eyebrow">코드 아레나</p><h1>경기 기록</h1>' +
        '<p>수업 게임에서 끝난 경기마다 팀 순위와 학생별 푼 블록·보스 수를 저장합니다. 봇은 기록하지 않습니다.</p></div>' +
        '<div class="ojp-heading-actions"><button type="button" class="ojp-button" data-act="csv">CSV 내보내기</button></div></header>' +
        '<div data-oca-notice></div>' +
        '<div class="oca-bank-tools"><select class="ojp-select" data-oca-session aria-label="수업 게임"><option value="">모든 수업 게임</option></select></div>' +
        '<div class="oca-records" data-oca-list><p class="oca-muted">불러오는 중…</p></div></div>';

    function visible() {
        return rounds.filter(function (r) { return !sessionFilter || String(r.session_id) === sessionFilter; });
    }

    function draw() {
        var list = visible();
        if (!list.length) { $('[data-oca-list]').innerHTML = '<div class="ojp-empty-state"><div><p>아직 끝난 경기 기록이 없습니다.</p></div></div>'; return; }
        $('[data-oca-list]').innerHTML = list.map(function (r) {
            var teams = (r.teams || []).map(function (t) {
                var players = (t.players || []).filter(function (p) { return !p.bot; }).map(function (p) {
                    return '<tr><td>' + h(p.name) + '</td><td>' + p.solved + '</td><td>' + p.bosses + '</td></tr>';
                }).join('');
                return '<div class="oca-team-box"><h3>' + t.rank + '위 · ' + t.team + '팀 <span class="oca-team-pct">' + (t.cleared ? '완성 ' + clock(t.ms) : t.pct + '%') + '</span></h3>' +
                    '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr><th>학생</th><th>블록</th><th>보스</th></tr></thead><tbody>' + (players || '<tr><td colspan="3" class="oca-muted">봇만 있었습니다.</td></tr>') + '</tbody></table></div></div>';
            }).join('');
            return '<details class="oca-card-box"><summary><strong>' + h(r.session_title || r.title || '수업 게임') + '</strong> · ' + h(r.round) + '번째 경기 · ' + h(when(r)) +
                ' · ' + h(REASON[r.reason] || r.reason) + '</summary><div class="oca-team-grid">' + teams + '</div></details>';
        }).join('');
    }

    function csv() {
        var lines = [['수업 게임', '경기', '끝난 시각', '팀', '팀 순위', '팀 진행(%)', '완성', '학생', '푼 블록', '보스']];
        visible().forEach(function (r) {
            (r.teams || []).forEach(function (t) {
                (t.players || []).filter(function (p) { return !p.bot; }).forEach(function (p) {
                    lines.push([r.session_title || r.title || '', r.round, when(r), t.team, t.rank, t.pct, t.cleared ? 'O' : '', p.name, p.solved, p.bosses]);
                });
            });
        });
        var text = '﻿' + lines.map(function (row) {
            return row.map(function (v) {
                var s = String(v == null ? '' : v);
                if (/^[=+\-@\t\r]/.test(s)) s = "'" + s; // keep spreadsheets from running it as a formula
                return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
            }).join(',');
        }).join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
        a.download = 'code-arena-records.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    root.addEventListener('change', function (e) {
        if (e.target.hasAttribute('data-oca-session')) { sessionFilter = e.target.value; draw(); }
    });
    root.addEventListener('click', function (e) {
        if (e.target.closest('[data-act="csv"]')) csv();
    });

    Net.rest(cfg, 'GET', '/records').then(function (data) {
        rounds = data.rounds || [];
        var seen = {};
        var opts = rounds.filter(function (r) { if (seen[r.session_id]) return false; seen[r.session_id] = 1; return true; })
            .map(function (r) { return '<option value="' + h(r.session_id) + '">' + h(r.session_title || r.title || '수업 게임') + '</option>'; }).join('');
        $('[data-oca-session]').insertAdjacentHTML('beforeend', opts);
        draw();
    }).catch(function (err) {
        $('[data-oca-notice]').innerHTML = '<div class="ojp-notice ojp-notice--danger" role="alert"><p>' + h(err.message) + '</p></div>';
    });
})(window, document);
