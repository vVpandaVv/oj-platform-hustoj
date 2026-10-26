/*
 * Code Arena — problem bank editor (shared by every class game and free room).
 */
(function (window, document) {
    'use strict';

    var Net = window.ArenaNet;
    var Bank = window.ArenaBank;
    var Grade = window.ArenaGrade;
    var root = document.querySelector('[data-oca-bank]');
    if (!root || !Net || !Bank || !Grade) return;
    var cfg = JSON.parse(root.getAttribute('data-config') || '{}');

    var items = [];
    var tags = [];
    var filter = { kind: '', tag: '', q: '', off: false };
    var page = 1;
    var PER = 50;
    var editing = null;
    var preview = null;
    var operator = false;

    function h(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function $(sel) { return root.querySelector(sel); }

    // Success goes to the platform toast when it exists; errors stay on the page.
    function notice(text, kind) {
        if (text && kind === 'ok' && window.OJP && typeof window.OJP.toast === 'function') {
            window.OJP.toast(text);
            $('[data-oca-notice]').innerHTML = '';
            return;
        }
        var tone = { ok: 'success', error: 'danger' }[kind] || 'info';
        $('[data-oca-notice]').innerHTML = text ? '<div class="ojp-notice ojp-notice--' + tone + '" role="' + (kind === 'error' ? 'alert' : 'status') + '"><p>' + h(text) + '</p></div>' : '';
    }

    root.innerHTML =
        '<div class="oca-console">' +
        '<header class="ojp-page-header"><div><p class="ojp-eyebrow">코드 아레나</p><h1>문제은행</h1>' +
        '<p>일반 문제는 한 줄 코드(블록), 보스 문제는 출력 예측이나 빈칸(___) 채우기입니다. 자판 전환 때문에 한글은 쓸 수 없습니다.</p></div>' +
        '<div class="ojp-heading-actions">' +
        '<button type="button" class="ojp-button ojp-button--primary" data-act="new-normal">일반 문제 추가</button>' +
        '<button type="button" class="ojp-button ojp-button--tinted" data-act="new-boss">보스 문제 추가</button>' +
        '</div></header>' +
        '<div data-oca-notice></div>' +
        '<section class="oca-card-box"><div class="oca-bank-tools">' +
        '<select class="ojp-select" data-f="kind" aria-label="종류"><option value="">전체</option><option value="normal">일반</option><option value="boss">보스</option></select>' +
        '<select class="ojp-select" data-f="tag" aria-label="태그"><option value="">모든 태그</option></select>' +
        '<input class="ojp-input" type="search" data-f="q" placeholder="코드·제목 검색" aria-label="코드·제목 검색">' +
        '<label class="ojp-toggle-row"><input type="checkbox" data-f="off"><span>꺼진 문제만</span></label>' +
        '<span class="oca-spacer"></span>' +
        '<button type="button" class="ojp-button" data-act="import">붙여넣어 가져오기</button>' +
        '<button type="button" class="ojp-button" data-act="export">내보내기</button>' +
        '</div><div data-oca-editor></div><p class="oca-muted" data-oca-count></p><div data-oca-table></div><div class="oca-pager" data-oca-pager></div></section>' +
        '</div>';

    function load() {
        return Net.rest(cfg, 'GET', '/bank').then(function (data) {
            items = data.items || [];
            tags = data.tags || [];
            operator = !!data.operator;
            var sel = root.querySelector('[data-f="tag"]');
            var cur = sel.value;
            sel.innerHTML = '<option value="">모든 태그</option>' + tags.map(function (t) { return '<option' + (t === cur ? ' selected' : '') + '>' + h(t) + '</option>'; }).join('');
            draw();
        }).catch(function (err) { notice(err.message, 'error'); });
    }

    function visible() {
        var q = filter.q.toLowerCase();
        return items.filter(function (p) {
            if (filter.kind && p.kind !== filter.kind) return false;
            if (filter.tag && (p.tags || []).indexOf(filter.tag) < 0) return false;
            if (filter.off && p.enabled) return false;
            if (q && (p.code + ' ' + (p.title || '')).toLowerCase().indexOf(q) < 0) return false;
            return true;
        });
    }

    function draw() {
        var list = visible();
        var pages = Math.max(1, Math.ceil(list.length / PER));
        page = Math.min(page, pages);
        var normal = items.filter(function (p) { return p.kind === 'normal' && p.enabled; }).length;
        var boss = items.filter(function (p) { return p.kind === 'boss' && p.enabled; }).length;
        $('[data-oca-count]').textContent = '사용 중: 일반 ' + normal + '개 · 보스 ' + boss + '개 / 검색 결과 ' + list.length + '개';
        var rows = list.slice((page - 1) * PER, page * PER).map(function (p) {
            return '<tr class="' + (p.enabled ? '' : 'oca-row-off') + '"><td><span class="ojp-status-badge ojp-status-badge--' + (p.kind === 'boss' ? 'danger' : 'neutral') + '">' + (p.kind === 'boss' ? '보스' : '일반') + '</span></td>' +
                '<td>' + (p.kind === 'boss' ? '<strong>' + h(p.title) + '</strong><br>' : '') + '<span class="oca-code-cell">' + h(p.code) + '</span>' +
                (p.kind === 'boss' ? '<br><span class="oca-muted">정답: ' + h((p.answers || []).join(' | ')) + (p.input ? ' · 입력: ' + h(p.input) : '') + '</span>' : '') + '</td>' +
                '<td>' + (p.tags || []).map(function (t) { return '<span class="ojp-tag">' + h(t) + '</span>'; }).join('') + '</td>' +
                (p.editable
                    ? '<td><label class="ojp-toggle-row"><input type="checkbox" data-act="toggle" data-id="' + p.id + '"' + (p.enabled ? ' checked' : '') + '><span>사용</span></label></td>' +
                      '<td><div class="oca-actions"><button type="button" class="ojp-button ojp-button--small" data-act="edit" data-id="' + p.id + '">편집</button>' +
                      '<button type="button" class="ojp-button ojp-button--small ojp-button--danger" data-act="delete" data-id="' + p.id + '">삭제</button></div></td></tr>'
                    : '<td>' + (p.enabled ? '사용' : '꺼짐') + '</td><td class="oca-muted" title="기본 문제와 다른 선생님의 문제는 운영자만 편집할 수 있습니다">공용</td></tr>');
        }).join('');
        $('[data-oca-table]').innerHTML = rows
            ? '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr><th>종류</th><th>문제</th><th>태그</th><th>사용</th><th><span class="ojp-visually-hidden">작업</span></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
            : '<div class="ojp-empty-state"><div><p>조건에 맞는 문제가 없습니다.</p></div></div>';
        $('[data-oca-pager]').innerHTML = pages > 1
            ? '<button type="button" class="ojp-button ojp-button--small" data-act="page" data-p="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>이전</button><span>' + page + ' / ' + pages + '</span>' +
              '<button type="button" class="ojp-button ojp-button--small" data-act="page" data-p="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>다음</button>' : '';
    }

    function openEditor(p) {
        editing = p;
        var boss = p.kind === 'boss';
        $('[data-oca-editor]').innerHTML =
            '<form class="oca-card-box oca-editor oca-stack" data-oca-form>' +
            '<h3>' + (p.id ? '문제 편집' : (boss ? '새 보스 문제' : '새 일반 문제')) + '</h3>' +
            (boss ? '<label class="ojp-field"><span>제목</span><input class="ojp-input" name="title" maxlength="80" required value="' + h(p.title || '') + '"></label>' : '') +
            '<label class="ojp-field"><span>' + (boss ? '코드 (빈칸 문제는 ___ 한 곳)' : '코드 한 줄') + '</span>' + (boss
                ? '<textarea class="ojp-textarea" name="code" rows="7" required>' + h(p.code || '') + '</textarea>'
                : '<input class="ojp-input oca-code-input" name="code" maxlength="200" required value="' + h(p.code || '') + '">') + '</label>' +
            (boss ? '<label class="ojp-field"><span>입력값 (선택, 학생에게 보여 줍니다)</span><textarea class="ojp-textarea" name="input" rows="2">' + h(p.input || '') + '</textarea></label>' +
                '<label class="ojp-field"><span>정답 (한 줄에 하나, 여러 개 가능)</span><textarea class="ojp-textarea" name="answers" rows="3" required>' + h((p.answers || []).join('\n')) + '</textarea></label>' : '') +
            '<label class="ojp-field"><span>태그 (쉼표로 구분)</span><input class="ojp-input" name="tags" value="' + h((p.tags || []).join(', ')) + '" list="oca-tag-list"></label>' +
            '<datalist id="oca-tag-list">' + tags.map(function (t) { return '<option value="' + h(t) + '">'; }).join('') + '</datalist>' +
            '<div class="oca-tester"><strong>채점 시험</strong><input class="ojp-input" name="try" placeholder="학생이 입력할 답을 넣어 봅니다" aria-label="채점 시험 답"><span data-oca-verdict class="oca-muted">따옴표 밖의 공백은 무시하고 \' 와 " 는 같게 봅니다.' + (boss ? ' 출력 예측은 모든 공백을 무시합니다.' : '') + '</span></div>' +
            '<div class="oca-row"><button class="ojp-button ojp-button--primary">저장</button><button type="button" class="ojp-button" data-act="cancel">취소</button></div>' +
            '</form>';
        $('[data-oca-form] [name=' + (boss ? 'title' : 'code') + ']').focus();
    }

    function formProblem(form) {
        var d = new FormData(form);
        var p = {
            kind: editing.kind,
            title: String(d.get('title') || '').trim(),
            code: String(d.get('code') || '').replace(/\s+$/, ''),
            input: String(d.get('input') || ''),
            answers: String(d.get('answers') || '').split('\n').map(function (a) { return a.trim(); }).filter(Boolean),
            tags: String(d.get('tags') || '').split(',').map(function (t) { return t.trim(); }).filter(Boolean),
        };
        if (p.kind === 'normal') p.code = p.code.trim();
        p.blank = p.code.indexOf('___') >= 0;
        return p;
    }

    function validate(p) {
        return p.kind === 'boss' ? Bank.checkBoss(p) : Bank.checkNormal(p.code);
    }

    function openImport() {
        preview = null;
        $('[data-oca-editor]').innerHTML =
            '<div class="oca-card-box oca-editor oca-stack">' +
            '<h3>붙여넣어 가져오기</h3>' +
            '<p class="oca-muted"><code>@일반 태그1, 태그2</code> 아래에 한 줄씩, <code>@보스 제목 | 태그</code> 아래에 코드와 <code>&lt;- 입력</code>(선택), <code>-&gt; 정답 || 다른 정답</code>을 씁니다. <code>;;</code>로 시작하는 줄은 메모입니다.</p>' +
            '<textarea class="ojp-textarea oca-mono" rows="12" aria-label="가져올 문제" data-oca-import placeholder="@일반 출력\nprint(\'hi\')\n\n@보스 합계 | 반복문\ns = 0\nfor i in range(3):\n    s += i\nprint(s)\n-> 3"></textarea>' +
            '<div class="oca-row"><button type="button" class="ojp-button" data-act="preview">미리 보기</button>' +
            (operator ? '<label class="ojp-toggle-row"><input type="checkbox" data-oca-replace><span>기존 문제를 모두 지우고 바꾸기</span></label>' : '') +
            '<button type="button" class="ojp-button ojp-button--primary" data-act="apply" disabled>가져오기</button><button type="button" class="ojp-button" data-act="cancel">취소</button></div>' +
            '<div class="oca-preview" data-oca-preview></div></div>';
    }

    function showPreview() {
        var text = $('[data-oca-import]').value;
        preview = Bank.parse(text);
        var normal = preview.items.filter(function (p) { return p.kind === 'normal'; }).length;
        var boss = preview.items.length - normal;
        $('[data-oca-preview]').innerHTML = '<p>일반 ' + normal + '개 · 보스 ' + boss + '개를 읽었습니다.' + (preview.errors.length ? ' 문제 있는 줄 ' + preview.errors.length + '개는 빼고 가져옵니다.' : '') + '</p>' +
            (preview.errors.length ? '<ul>' + preview.errors.slice(0, 30).map(function (e) { return '<li class="is-error">' + e.line + '번째 줄: ' + h(e.text) + '</li>'; }).join('') + '</ul>' : '');
        root.querySelector('[data-act="apply"]').disabled = !preview.items.length;
    }

    function download(name, text) {
        var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    root.addEventListener('input', function (e) {
        var f = e.target.getAttribute('data-f');
        if (f === 'q') { filter.q = e.target.value; page = 1; draw(); }
        if (e.target.name === 'try' && editing) {
            var form = e.target.form;
            var p = formProblem(form);
            var v = form.querySelector('[data-oca-verdict]');
            if (!e.target.value) { v.className = 'oca-muted'; v.textContent = ''; return; }
            var ok = Grade.check(p.kind === 'boss' ? p : { code: p.code }, e.target.value);
            v.className = ok ? 'oca-verdict--ok' : 'oca-verdict--no';
            v.textContent = ok ? '정답으로 채점됩니다' : '오답으로 채점됩니다';
        }
    });

    root.addEventListener('change', function (e) {
        var f = e.target.getAttribute('data-f');
        if (f === 'kind' || f === 'tag') { filter[f] = e.target.value; page = 1; draw(); }
        if (f === 'off') { filter.off = e.target.checked; page = 1; draw(); }
        if (e.target.getAttribute('data-act') === 'toggle') {
            var id = Number(e.target.getAttribute('data-id'));
            Net.rest(cfg, 'PUT', '/bank/' + id, { enabled: e.target.checked }).then(function (res) {
                items = items.map(function (p) { return p.id === id ? res.item : p; });
                draw();
            }).catch(function (err) { notice(err.message, 'error'); e.target.checked = !e.target.checked; });
        }
    });

    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn || btn.type === 'checkbox') return;
        var act = btn.getAttribute('data-act');
        var id = Number(btn.getAttribute('data-id'));
        if (act === 'new-normal') return openEditor({ kind: 'normal', tags: filter.tag ? [filter.tag] : [] });
        if (act === 'new-boss') return openEditor({ kind: 'boss', tags: filter.tag ? [filter.tag] : [], answers: [] });
        if (act === 'edit') return openEditor(items.filter(function (p) { return p.id === id; })[0]);
        if (act === 'cancel') { editing = null; $('[data-oca-editor]').innerHTML = ''; return; }
        if (act === 'import') return openImport();
        if (act === 'preview') return showPreview();
        if (act === 'page') { page = Number(btn.getAttribute('data-p')); draw(); return; }
        if (act === 'export') return download('code-arena-bank.txt', Bank.serialize(items.filter(function (p) { return p.enabled; })));
        if (act === 'delete') {
            var remove = function () {
                Net.rest(cfg, 'DELETE', '/bank/' + id).then(function () {
                    items = items.filter(function (p) { return p.id !== id; });
                    draw();
                    notice('문제를 삭제했습니다.', 'ok');
                }).catch(function (err) { notice(err.message, 'error'); });
            };
            if (window.OJP && typeof window.OJP.confirm === 'function') {
                window.OJP.confirm({ title: '이 문제를 삭제할까요?', message: '삭제한 문제는 되돌릴 수 없습니다. 잠시 빼 두려면 [사용]을 끕니다.', confirmLabel: '삭제', destructive: true })
                    .then(function (ok) { if (ok) remove(); });
                return;
            }
            // Fallback without the platform sheet: press again within 3 s.
            if (btn.getAttribute('data-armed') !== '1') {
                btn.setAttribute('data-armed', '1');
                btn.classList.add('is-armed');
                btn.textContent = '한 번 더 누르면 삭제';
                setTimeout(function () { btn.removeAttribute('data-armed'); btn.classList.remove('is-armed'); btn.textContent = '삭제'; }, 3000);
                return;
            }
            remove();
            return;
        }
        if (act === 'apply' && preview) {
            var box = root.querySelector('[data-oca-replace]');
            var replace = !!(box && box.checked);
            Net.rest(cfg, 'POST', '/bank/import', { items: preview.items, replace: replace }).then(function (res) {
                notice(res.added + '개를 가져왔습니다' + (res.skipped ? ' (같은 문제 ' + res.skipped + '개는 건너뜀)' : '') + '.', 'ok');
                $('[data-oca-editor]').innerHTML = '';
                return load();
            }).catch(function (err) { notice(err.message, 'error'); });
        }
    });

    root.addEventListener('submit', function (e) {
        if (!e.target.hasAttribute('data-oca-form')) return;
        e.preventDefault();
        var p = formProblem(e.target);
        var err = validate(p);
        if (err) return notice(err, 'error');
        var req = editing.id ? Net.rest(cfg, 'PUT', '/bank/' + editing.id, p) : Net.rest(cfg, 'POST', '/bank', p);
        req.then(function () {
            notice('문제를 저장했습니다.', 'ok');
            editing = null;
            $('[data-oca-editor]').innerHTML = '';
            return load();
        }).catch(function (er) { notice(er.message, 'error'); });
    });

    load();
})(window, document);
