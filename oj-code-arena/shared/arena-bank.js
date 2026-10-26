/*
 * Code Arena problem-bank text format (parse + serialize). Used by the
 * bank editor in the browser and by tools/build-bank.js.
 *
 *   ;; comment
 *   @일반 출력, 변수          ← following lines: one normal problem each
 *   print('hello')
 *
 *   @보스 합계 구하기 | 반복문  ← a boss problem: title | tags
 *   s = 0
 *   for i in range(4):
 *       s += i
 *   print(s)
 *   <- 3                     ← optional stdin line(s) shown to the student
 *   -> 6                     ← answers, "||" between alternatives (ends it)
 *
 * A boss whose code has ___ is fill-in-the-blank (answers are code);
 * otherwise the student predicts the output.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.ArenaBank = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var HANGUL = /[ᄀ-ᇿ㄰-㆏가-힯]/;
    var LIMITS = { normal: 200, boss: 1200, answer: 300, title: 80, input: 400, tag: 20 };

    function splitTags(s) {
        return String(s || '').split(',').map(function (t) { return t.trim(); })
            .filter(Boolean).map(function (t) { return t.slice(0, LIMITS.tag); });
    }

    // Returns problems that are individually valid (or an error string).
    function checkNormal(code) {
        if (!code.trim()) return '빈 줄';
        if (code.length > LIMITS.normal) return LIMITS.normal + '자를 넘어요';
        if (HANGUL.test(code)) return '한글은 쓸 수 없어요 (자판 전환 때문에)';
        return '';
    }

    function checkBoss(p) {
        if (!p.code.trim()) return '코드가 없어요';
        if (p.code.length > LIMITS.boss) return '코드가 ' + LIMITS.boss + '자를 넘어요';
        if (!p.answers.length) return '정답(-> 줄)이 없어요';
        if ((p.code.match(/___/g) || []).length > 1) return '빈칸(___)은 하나만 둘 수 있어요';
        for (var i = 0; i < p.answers.length; i++) {
            if (HANGUL.test(p.answers[i])) return '정답에 한글은 쓸 수 없어요';
            if (p.answers[i].length > LIMITS.answer) return '정답이 너무 길어요';
        }
        if (HANGUL.test(p.code)) return '코드에 한글은 쓸 수 없어요';
        return '';
    }

    function parse(text) {
        var lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
        var items = [];
        var errors = [];
        var mode = null;
        var tags = [];
        var boss = null;

        function closeBoss(lineNo) {
            if (!boss) return;
            var problem = {
                kind: 'boss', title: boss.title, tags: boss.tags,
                code: boss.code.join('\n').replace(/\s+$/, ''),
                input: boss.input.join('\n'), answers: boss.answers,
            };
            problem.blank = problem.code.indexOf('___') >= 0;
            var err = boss.done ? checkBoss(problem) : '정답(-> 줄)이 없어요';
            if (err) errors.push({ line: boss.line, text: '보스 "' + boss.title + '": ' + err });
            else items.push(problem);
            boss = null;
        }

        for (var n = 0; n < lines.length; n++) {
            var raw = lines[n];
            var line = raw.trim();
            var lineNo = n + 1;
            if (line.indexOf(';;') === 0) continue;
            var head = line.match(/^@(일반|보스)\s*(.*)$/);
            if (head) {
                closeBoss(lineNo);
                if (head[1] === '일반') {
                    mode = 'normal';
                    tags = splitTags(head[2]);
                } else {
                    mode = 'boss';
                    var parts = head[2].split('|');
                    boss = {
                        line: lineNo, title: (parts[0] || '').trim().slice(0, LIMITS.title) || '보스 문제',
                        tags: splitTags(parts[1]), code: [], input: [], answers: [], done: false,
                    };
                }
                continue;
            }
            if (mode === 'normal') {
                if (!line) continue;
                var err = checkNormal(line);
                if (err) errors.push({ line: lineNo, text: err + ': ' + line.slice(0, 40) });
                else items.push({ kind: 'normal', code: line, tags: tags.slice() });
                continue;
            }
            if (mode === 'boss') {
                if (!boss) {
                    if (line) errors.push({ line: lineNo, text: '보스 문제 밖의 줄이에요 (@보스 를 먼저 쓰세요)' });
                    continue;
                }
                if (boss.done) {
                    if (line) errors.push({ line: lineNo, text: '정답(->) 뒤에는 다음 @보스 가 와야 해요' });
                    continue;
                }
                if (/^<-\s?/.test(line)) { boss.input.push(line.replace(/^<-\s?/, '')); continue; }
                if (/^->\s?/.test(line)) {
                    boss.answers = line.replace(/^->\s?/, '').split('||').map(function (a) { return a.trim(); }).filter(Boolean);
                    boss.done = true;
                    continue;
                }
                boss.code.push(raw.replace(/\s+$/, ''));
                continue;
            }
            if (line) errors.push({ line: lineNo, text: '@일반 이나 @보스 줄이 먼저 와야 해요' });
        }
        closeBoss(lines.length);
        return { items: items, errors: errors };
    }

    function serialize(items) {
        var out = [';; 코드 아레나 문제은행', ''];
        var groups = {};
        var order = [];
        items.filter(function (p) { return p.kind === 'normal'; }).forEach(function (p) {
            var key = (p.tags || []).join(', ');
            if (!groups[key]) { groups[key] = []; order.push(key); }
            groups[key].push(p.code);
        });
        order.forEach(function (key) {
            out.push('@일반 ' + key);
            out.push.apply(out, groups[key]);
            out.push('');
        });
        items.filter(function (p) { return p.kind === 'boss'; }).forEach(function (p) {
            out.push('@보스 ' + p.title + ((p.tags || []).length ? ' | ' + p.tags.join(', ') : ''));
            out.push(p.code);
            String(p.input || '').split('\n').filter(Boolean).forEach(function (l) { out.push('<- ' + l); });
            out.push('-> ' + (p.answers || []).join(' || '));
            out.push('');
        });
        return out.join('\n');
    }

    return { parse: parse, serialize: serialize, checkNormal: checkNormal, checkBoss: checkBoss, LIMITS: LIMITS, HANGUL: HANGUL };
});
