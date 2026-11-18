// Thompson NFA: user patterns never execute in JavaScript's backtracking RegExp engine.
const fail = message => { throw new Error(`검색 패턴: ${message}`); };
export function budget(limit = 8000000) { return {remaining: limit, spend(n = 1) { this.remaining -= n; if (this.remaining < 0) throw new Error('분석 연산 한도에 도달했습니다. 입력이나 패턴을 줄이세요.'); }}; }
const word = c => !!c && /[\p{L}\p{N}_]/u.test(c);
const POSIX = {digit: c => c >= '0' && c <= '9', alpha: c => /[A-Za-z]/.test(c), alnum: c => /[A-Za-z0-9]/.test(c), lower: c => c >= 'a' && c <= 'z', upper: c => c >= 'A' && c <= 'Z', space: c => /\s/u.test(c), blank: c => c === ' ' || c === '\t', xdigit: c => /[A-Fa-f0-9]/.test(c), word};
function basicToExtended(pattern) {
    let out = '', inClass = false, start = true;
    for (let i = 0; i < pattern.length; i++) {
        const c = pattern[i];
        if (c === '\\') { const next = pattern[++i]; if (next === undefined) fail('끝의 역슬래시를 확인하세요.'); out += !inClass && '()+?|{}'.includes(next) ? next : '\\' + next; start = !inClass && (next === '(' || next === '|'); }
        else { if (!inClass && c === '^') out += start ? '^' : '\\^'; else if (!inClass && c === '$') out += i === pattern.length - 1 || (pattern[i + 1] === '\\' && [')', '|'].includes(pattern[i + 2])) ? '$' : '\\$'; else { if (c === '[') inClass = true; if (c === ']') inClass = false; out += !inClass && '()+?|{}'.includes(c) ? '\\' + c : c; } start = false; }
    }
    return out;
}
export function compilePattern(pattern, {extended = false, fixed = false, insensitive = false, whole = false, words = false} = {}) {
    if (typeof pattern !== 'string' || Array.from(pattern).length > 256 || /[\r\n\x00]/.test(pattern)) fail('한 줄 256자 이내로 지정하세요.');
    const lower = c => insensitive ? c.toLowerCase() : c;
    if (fixed) return (line, meter) => {
        meter.spend(line.length + pattern.length + 1); const target = lower(line), needle = lower(pattern);
        if (whole) return target === needle;
        let at = target.indexOf(needle);
        const previous = p => p > 0 ? target.slice(p >= 2 && target.charCodeAt(p - 1) >= 0xdc00 && target.charCodeAt(p - 1) <= 0xdfff ? p - 2 : p - 1, p) : '';
        const next = p => p < target.length ? String.fromCodePoint(target.codePointAt(p)) : '';
        while (at !== -1) { if (!words || (!word(previous(at)) && !word(next(at + needle.length)))) return true; meter.spend(needle.length + 1); if (at === target.length) break; at = target.indexOf(needle, at + 1); }
        return false;
    };
    const chars = Array.from(extended ? pattern : basicToExtended(pattern)); let i = 0, depth = 0, nodes = 0;
    const node = x => { if (++nodes > 512) fail('패턴이 너무 복잡합니다.'); return x; };
    const literal = value => ({kind: 'char', test: c => lower(c) === lower(value)});
    function escaped() {
        const c = chars[i++]; if (c === undefined) fail('끝의 역슬래시를 확인하세요.');
        if (/[1-9]/.test(c)) fail('역참조는 지원하지 않습니다.');
        if (c === 'w') return {kind: 'char', test: word}; if (c === 'W') return {kind: 'char', test: c => !word(c)};
        if (c === 's') return {kind: 'char', test: c => /\s/u.test(c)}; if (c === 'S') return {kind: 'char', test: c => !/\s/u.test(c)};
        if (c === 'b' || c === 'B') return {kind: 'assert', test: (s, p) => (word(s[p - 1]) !== word(s[p])) === (c === 'b')};
        return literal(c);
    }
    function charClass() {
        let negate = false; if (chars[i] === '^') { negate = true; i++; } const tests = []; let first = true, closed = false;
        while (i < chars.length) {
            if (chars[i] === ']' && !first) { i++; closed = true; break; }
            first = false;
            if (chars[i] === '[' && ['.', '='].includes(chars[i + 1])) fail('로케일 정렬·등가 문자 클래스는 지원하지 않습니다.');
            if (chars[i] === '[' && chars[i + 1] === ':') { i += 2; let name = ''; while (i < chars.length && !(chars[i] === ':' && chars[i + 1] === ']')) name += chars[i++]; if (!POSIX[name] || i >= chars.length) fail('지원하지 않는 POSIX 문자 클래스입니다.'); i += 2; const test = POSIX[name]; tests.push(c => test(c) || (insensitive && (test(lower(c)) || test(c.toUpperCase())))); continue; }
            let a = chars[i++]; if (a === '\\') { a = chars[i++]; if (a === undefined) fail('문자 클래스를 확인하세요.'); }
            if (chars[i] === '-' && chars[i + 1] !== ']' && chars[i + 1] !== undefined) { i++; let b = chars[i++]; if (b === '\\') b = chars[i++]; if (b === undefined || lower(a).codePointAt(0) > lower(b).codePointAt(0)) fail('문자 범위를 확인하세요.'); tests.push(c => lower(c).codePointAt(0) >= lower(a).codePointAt(0) && lower(c).codePointAt(0) <= lower(b).codePointAt(0)); }
            else tests.push(c => lower(c) === lower(a));
        }
        if (!closed || !tests.length) fail('닫히지 않았거나 빈 문자 클래스입니다.');
        return {kind: 'char', weight: tests.length, test: c => tests.some(test => test(c)) !== negate};
    }
    function atom() {
        const c = chars[i++]; let result;
        if (c === '(') { if (++depth > 32) fail('그룹 중첩은 32단계까지 지원합니다.'); result = expression(); if (chars[i++] !== ')') fail('그룹을 닫아 주세요.'); depth--; }
        else if (c === '[') result = charClass();
        else if (c === '\\') result = escaped();
        else if (c === '.') result = {kind: 'char', test: () => true};
        else if (c === '^' || c === '$') result = {kind: 'assert', test: (s, p) => c === '^' ? p === 0 : p === s.length};
        else { if (c === undefined || '*+?{}'.includes(c)) fail('반복 연산자의 위치를 확인하세요.'); result = literal(c); }
        result = node(result);
        const q = chars[i]; let min, max;
        if (q === '*' || q === '+' || q === '?') { i++; min = q === '+' ? 1 : 0; max = q === '?' ? 1 : Infinity; }
        else if (q === '{') {
            i++; let a = '', b = ''; while (/\d/.test(chars[i] || 'x')) a += chars[i++];
            if (!a) fail('반복 횟수가 필요합니다.'); min = Number(a); max = min;
            if (chars[i] === ',') { i++; while (/\d/.test(chars[i] || 'x')) b += chars[i++]; max = b ? Number(b) : Infinity; }
            if (chars[i++] !== '}' || min > 64 || (max !== Infinity && (max > 64 || max < min))) fail('반복 범위는 0~64입니다.');
        }
        return min === undefined ? result : node({kind: 'repeat', item: result, min, max});
    }
    function sequence() { const items = []; while (i < chars.length && chars[i] !== ')' && chars[i] !== '|') items.push(atom()); return node({kind: 'seq', items}); }
    function expression() { const items = [sequence()]; while (chars[i] === '|') { i++; items.push(sequence()); } return items.length === 1 ? items[0] : node({kind: 'or', items}); }
    let ast = expression(); if (i !== chars.length) fail('예상하지 못한 닫는 그룹입니다.');
    if (whole || words) ast = {kind: 'seq', items: [{kind: 'assert', test: (s, p) => whole ? p === 0 : !word(s[p - 1])}, ast, {kind: 'assert', test: (s, p) => whole ? p === s.length : !word(s[p])}]};
    const states = []; const add = s => { if (states.length >= 512) fail('NFA 상태 한도 512개를 초과했습니다.'); states.push(s); return states.length - 1; };
    const patch = (outs, target) => { for (const [id, key] of outs) states[id][key] = target; };
    const join = (a, b) => { patch(a.outs, b.start); return {start: a.start, outs: b.outs}; };
    const empty = () => { const id = add({kind: 'split', out: null, alt: null}); return {start: id, outs: [[id, 'out']]}; };
    function build(a) {
        if (a.kind === 'char' || a.kind === 'assert') { const id = add({...a, out: null}); return {start: id, outs: [[id, 'out']]}; }
        if (a.kind === 'seq') return a.items.reduce((all, item) => join(all, build(item)), empty());
        if (a.kind === 'or') return a.items.map(build).reduce((left, right) => ({start: add({kind: 'split', out: left.start, alt: right.start}), outs: [...left.outs, ...right.outs]}));
        let result = empty(); for (let n = 0; n < a.min; n++) result = join(result, build(a.item));
        if (a.max === Infinity) { const body = build(a.item), id = add({kind: 'split', out: body.start, alt: null}); patch(body.outs, id); result = join(result, {start: id, outs: [[id, 'alt']]}); }
        else for (let n = a.min; n < a.max; n++) { const body = build(a.item), id = add({kind: 'split', out: body.start, alt: null}); result = join(result, {start: id, outs: [...body.outs, [id, 'alt']]}); }
        return result;
    }
    const fragment = build(ast), end = add({kind: 'match'}); patch(fragment.outs, end);
    return (line, meter) => {
        const input = Array.from(line); let active = new Set();
        function closure(start, position, out, visited) {
            const stack = [start]; while (stack.length) { const id = stack.pop(); if (id === null || id === undefined || visited.has(id)) continue; visited.add(id); meter.spend(); const state = states[id]; if (state.kind === 'split') stack.push(state.out, state.alt); else if (state.kind === 'assert') { if (state.test(input, position)) stack.push(state.out); } else out.add(id); }
        }
        closure(fragment.start, 0, active, new Set()); if (active.has(end)) return true;
        for (let p = 0; p < input.length; p++) { const next = new Set(), visited = new Set(); for (const id of active) { const state = states[id]; meter.spend(state.weight || 1); if (state.kind === 'char' && state.test(input[p])) closure(state.out, p + 1, next, visited); } closure(fragment.start, p + 1, next, visited); if (next.has(end)) return true; active = next; }
        return false;
    };
}
