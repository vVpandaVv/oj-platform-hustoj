/*
 * Code Arena grading rules — shared by the game server (authoritative) and
 * the problem-bank editor (preview only).
 *
 *  - code: whitespace outside string literals is ignored, string contents
 *    must match exactly, and ' / " are interchangeable.
 *  - output: all whitespace is ignored.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.ArenaGrade = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var STR_OPEN = '\u0001';
    var STR_CLOSE = '\u0002';

    // Returns the canonical form of a line of code, or null when a string
    // literal is left open (or spans a line break).
    function canonical(text) {
        var src = String(text == null ? '' : text);
        var out = '';
        var i = 0;
        while (i < src.length) {
            var ch = src[i];
            if (ch === '\'' || ch === '"') {
                var triple = src.substr(i, 3) === ch + ch + ch;
                var q = triple ? ch + ch + ch : ch;
                var j = i + q.length;
                var body = '';
                var closed = false;
                while (j < src.length) {
                    if (src[j] === '\\' && j + 1 < src.length) {
                        var nx = src[j + 1];
                        body += (nx === '\'' || nx === '"') ? nx : '\\' + nx;
                        j += 2;
                        continue;
                    }
                    if (src.substr(j, q.length) === q) {
                        closed = true;
                        j += q.length;
                        break;
                    }
                    if (!triple && (src[j] === '\n' || src[j] === '\r')) return null;
                    body += src[j];
                    j++;
                }
                if (!closed) return null;
                out += STR_OPEN + body + STR_CLOSE;
                i = j;
                continue;
            }
            if (!/\s/.test(ch)) out += ch;
            i++;
        }
        return out;
    }

    function sameCode(input, expected) {
        var a = canonical(input);
        var b = canonical(expected);
        return a !== null && b !== null && a === b;
    }

    function squash(text) {
        return String(text == null ? '' : text).replace(/\s+/g, '');
    }

    // problem: { code, answers?, blank? }  — normal problems pass just code.
    function check(problem, input) {
        if (!problem) return false;
        var answers = Array.isArray(problem.answers) && problem.answers.length ? problem.answers : null;
        if (!answers) return sameCode(input, problem.code);
        if (problem.blank) {
            for (var i = 0; i < answers.length; i++) {
                if (sameCode(input, answers[i])) return true;
            }
            return false;
        }
        var got = squash(input);
        if (got === '') return false;
        for (var k = 0; k < answers.length; k++) {
            if (got === squash(answers[k])) return true;
        }
        return false;
    }

    return { canonical: canonical, sameCode: sameCode, squash: squash, check: check };
});
