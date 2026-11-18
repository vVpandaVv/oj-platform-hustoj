import {compilePattern} from './text-pattern.js?ver=1.4.0-rc.4';
import {TOOLS_HELP} from './text-help.js?ver=1.4.0-rc.4';
export const UTILITIES = ['grep', 'head', 'tail', 'wc', 'sort', 'uniq', 'cut', 'cat', 'ls', 'pwd', 'echo', 'hostname', 'history', 'diff'];
export const requireText = (ok, message) => { if (!ok) throw new Error(message); };
export function bounded(text, meter) { requireText(typeof text === 'string' && text.length <= 200000, '분석 텍스트는 200,000자 이내입니다.'); meter.spend(text.length + 1); requireText(text.split('\n').length <= 4001, '분석 텍스트는 4000줄 이내입니다.'); return text; }
export const linesOf = text => text === '' ? [] : text.replace(/\r\n/g, '\n').split('\n').slice(0, text.endsWith('\n') ? -1 : undefined);
export const linesText = lines => lines.length ? lines.join('\n') + '\n' : '';
export function toolWords(line) {
    requireText(typeof line === 'string' && line.length <= 2048 && !/[\r\n\x00]/.test(line), '명령은 한 줄 2048자 이내입니다.');
    const words = [], expandable = []; let value = '', quote = '', active = false, expands = true;
    for (let i = 0; i < line.length; i++) {
        const c = line[i];
        if (quote === "'") { if (c === "'") quote = ''; else { if (c === '$') expands = false; value += c; } }
        else if (quote === '"') { if (c === '"') quote = ''; else if (c === '\\' && ['\\', '"', '$', '`'].includes(line[i + 1])) { if (line[i + 1] === '$') expands = false; value += line[++i]; } else value += c; }
        else if (c === '"' || c === "'") { quote = c; active = true; }
        else if (c === '\\') { requireText(i + 1 < line.length, '끝의 역슬래시를 확인하세요.'); if (line[i + 1] === '$') expands = false; value += line[++i]; active = true; }
        else if (/\s/.test(c)) { if (active) { words.push(value); expandable.push(expands); value = ''; active = false; expands = true; } }
        else { value += c; active = true; }
    }
    requireText(!quote, '따옴표를 닫아 주세요.'); if (active) { words.push(value); expandable.push(expands); } words.expandable = expandable; return words;
}
const number = (value, max = 4000) => { requireText(/^\d+$/.test(String(value)) && Number(value) <= max, `정수 0~${max} 범위가 필요합니다.`); return Number(value); };
function streams(files, input, ctx) {
    requireText(files.length <= 8, '파일은 한 번에 8개까지 읽을 수 있습니다.');
    if (!files.length) { requireText(input !== undefined, '가상 파일 또는 파이프 입력이 필요합니다. 대화형 stdin은 지원하지 않습니다.'); return [{name: '', text: bounded(input, ctx.meter)}]; }
    let size = 0, count = 0;
    return files.map(name => { if (name === '-') requireText(input !== undefined, '파이프 입력이 없습니다.'); const text = bounded(name === '-' ? input : ctx.read(name), ctx.meter); size += text.length; count += linesOf(text).length; requireText(size <= 200000 && count <= 4000, '입력 합계는 200,000자·4000줄 이내입니다.'); return {name: name === '-' ? '' : name, text}; });
}
export function configContext(lines, selected, meter) {
    const stack = [], owners = [], blocks = [];
    for (let i = 0; i < lines.length; i++) {
        const text = lines[i].trim();
        if (/^(config|edit)\s/.test(text)) { const block = {kind: text.split(' ')[0], start: i, end: lines.length - 1, parent: stack.at(-1)}; blocks.push(block); stack.push(block); }
        owners[i] = stack.at(-1);
        if (text === 'next') { if (stack.at(-1)?.kind === 'edit') stack.pop().end = i; }
        if (text === 'end') { while (stack.length) { const b = stack.pop(); b.end = i; if (b.kind === 'config') break; } }
    }
    const result = new Set(), chosen = new Set(); for (const i of selected) { if (owners[i]) chosen.add(owners[i]); else result.add(i); }
    for (const b of chosen) { let nested = false; for (let a = b.parent; a; a = a.parent) { meter?.spend(); if (chosen.has(a)) { nested = true; break; } } if (nested) continue; for (let j = b.start; j <= b.end; j++) { meter?.spend(); result.add(j); } for (let a = b.parent; a; a = a.parent) { meter?.spend(); result.add(a.start); result.add(a.end); } }
    return result;
}
function grep(tokens, ctx, forti) {
    const options = {before: 0, after: 0, max: Infinity}, patterns = [], patternFiles = [], files = []; let positional = false, explicit = false, end = false;
    for (let i = 1; i < tokens.length; i++) {
        const arg = tokens[i]; if (!end && arg === '--') { end = true; continue; }
        if (!end && arg.startsWith('-') && arg !== '-') {
            for (let j = 1; j < arg.length; j++) {
                const flag = arg[j]; requireText((forti ? 'invfcABC' : 'ivncxwqEFHhefmABC').includes(flag), `지원하지 않는 grep 옵션: -${flag}`);
                const param = () => { const v = arg.slice(j + 1) || tokens[++i]; requireText(v !== undefined, `-${flag} 값이 필요합니다.`); j = arg.length; return v; };
                if (flag === 'f' && forti) options.context = true;
                else if (flag === 'e') { patterns.push(param()); explicit = true; }
                else if (flag === 'f') { patternFiles.push(param()); explicit = true; }
                else if ('ABCm'.includes(flag)) { const n = number(param(), flag === 'm' ? 4000 : 100); if (flag === 'm') options.max = n; else options.hasContext = true; if (flag === 'A' || flag === 'C') options.after = n; if (flag === 'B' || flag === 'C') options.before = n; }
                else { options[flag] = true; if (flag === 'H') options.h = false; if (flag === 'h') options.H = false; }
            }
        } else if (!positional && !explicit) { patterns.push(arg); positional = true; } else files.push(arg);
    }
    requireText(!(options.E && options.F), '-E와 -F는 함께 사용할 수 없습니다.');
    for (const path of patternFiles) patterns.push(...linesOf(bounded(ctx.read(path), ctx.meter)));
    requireText(patterns.length > 0 || patternFiles.length > 0, '검색 패턴이 필요합니다.'); requireText(patterns.length <= 32, '패턴은 32개까지 지원합니다.');
    requireText(!forti || !files.length, '장비 grep은 파이프 입력만 받습니다. 가상 파일은 lab grep으로 읽으세요.');
    requireText(!options.context || !(options.v || options.c || options.hasContext), 'FortiOS -f는 -v/-c/줄 문맥 옵션과 별도로 사용하세요.');
    const compiled = patterns.map(p => compilePattern(p, {extended: !!options.E, fixed: !!options.F, insensitive: !!options.i, whole: !!options.x, words: !!options.w}));
    return input => {
        const sources = streams(files, input, ctx), output = []; let any = false;
        for (const source of sources) {
            const lines = linesOf(source.text), matches = new Set();
            for (let i = 0; i < lines.length && matches.size < options.max; i++) { const match = compiled.some(test => test(lines[i], ctx.meter)); if (match !== !!options.v) matches.add(i); }
            any ||= matches.size > 0; const named = options.H || (sources.length > 1 && !options.h), label = source.name || '(standard input)';
            if (options.q) continue;
            if (options.c) { output.push(`${named ? label + ':' : ''}${matches.size}`); continue; }
            let selected = options.context ? configContext(lines, matches, ctx.meter) : new Set(matches);
            let tailEnd = lines.length - 1;
            if (options.hasContext && options.after && Number.isFinite(options.max) && matches.size && matches.size === options.max) {
                const last = [...matches].at(-1); tailEnd = Math.min(tailEnd, last + options.after);
                for (let j = last + 1; j <= tailEnd; j++) if (compiled.some(test => test(lines[j], ctx.meter)) !== !!options.v) { tailEnd = j - 1; break; }
            }
            if (options.hasContext) for (const i of matches) for (let j = Math.max(0, i - options.before); j <= Math.min(tailEnd, i + options.after); j++) { ctx.meter.spend(); selected.add(j); }
            let previous = -2;
            for (const i of [...selected].sort((a, b) => a - b)) { if (options.hasContext && previous >= 0 && i > previous + 1) output.push('--'); const separator = matches.has(i) || options.context ? ':' : '-'; output.push(`${named ? label + separator : ''}${options.n ? i + 1 + separator : ''}${lines[i]}`); previous = i; }
        }
        return {text: bounded(linesText(output), ctx.meter), status: any ? 0 : 1};
    };
}
function simpleArgs(tokens, allowed, valued = []) {
    const flags = {}, files = []; let end = false;
    for (let i = 1; i < tokens.length; i++) { const t = tokens[i]; if (!end && t === '--') { end = true; continue; } if (!end && t.startsWith('-') && t !== '-') { for (let j = 1; j < t.length; j++) { const flag = t[j]; requireText(allowed.includes(flag), `지원하지 않는 옵션: -${flag}`); if (valued.includes(flag)) { flags[flag] = t.slice(j + 1) || tokens[++i]; requireText(flags[flag] !== undefined, `-${flag} 값이 필요합니다.`); break; } flags[flag] = true; } } else files.push(t); }
    return {flags, files};
}
export function utility(tokens, ctx, {forti = false} = {}) {
    const command = tokens[0]; requireText(UTILITIES.includes(command), '지원하지 않는 분석 도구입니다. lab help를 확인하세요.');
    if (tokens.length === 2 && tokens[1] === '--help') return () => ({text: TOOLS_HELP + '\n', status: 0});
    if (command === 'grep') return grep(tokens, ctx, forti);
    if (['echo', 'pwd', 'hostname', 'history', 'ls'].includes(command)) {
        if (command === 'echo') { let args = tokens.slice(1), offset = 1, newline = true; if (args[0] === '-n') { newline = false; args = args.slice(1); offset++; } requireText(!['-e', '-E'].includes(args[0]), 'echo는 -n만 지원하며 이스케이프 확장은 하지 않습니다.'); const value = args.map((s, i) => s === '$?' && tokens.expandable?.[i + offset] !== false ? String(ctx.previous) : s).join(' '); return () => ({text: bounded(value + (newline ? '\n' : ''), ctx.meter), status: 0}); }
        if (command === 'ls') { const {flags, files} = simpleArgs(tokens, '1'); requireText(files.length <= 1, 'ls는 가상 경로 하나를 받습니다.'); return () => ({text: linesText(ctx.list(files[0] || '/')), status: 0}); }
        requireText(tokens.length === 1, `${command}는 추가 옵션을 지원하지 않습니다.`);
        return () => ({text: command === 'pwd' ? '/\n' : command === 'hostname' ? ctx.hostname + '\n' : ctx.read('/history/commands.txt'), status: 0});
    }
    if (command === 'diff') {
        const {flags, files} = simpleArgs(tokens, 'u'); requireText(files.length === 2, 'diff [-u] 가상파일 가상파일 형식입니다.');
        return () => { const a = linesOf(bounded(ctx.read(files[0]), ctx.meter)), b = linesOf(bounded(ctx.read(files[1]), ctx.meter)); let start = 0; while (start < a.length && start < b.length && a[start] === b[start]) start++; if (start === a.length && start === b.length) return {text: '', status: 0}; let end = 0; while (end < a.length - start && end < b.length - start && a[a.length - 1 - end] === b[b.length - 1 - end]) end++; const removed = a.slice(start, a.length - end), added = b.slice(start, b.length - end); const result = [`--- ${files[0]}`, `+++ ${files[1]}`, `@@ -${removed.length ? start + 1 : start},${removed.length} +${added.length ? start + 1 : start},${added.length} @@`, ...removed.map(s => '-' + s), ...added.map(s => '+' + s)]; return {text: bounded(linesText(result), ctx.meter), status: 1}; };
    }
    if (command === 'cat') { const {flags, files} = simpleArgs(tokens, 'n'); return input => { const text = streams(files, input, ctx).map(s => s.text).join(''); return {text: bounded(flags.n ? linesText(linesOf(text).map((s, i) => `${String(i + 1).padStart(6)}\t${s}`)) : text, ctx.meter), status: 0}; }; }
    if (command === 'head' || command === 'tail') {
        let args = tokens; if (/^-\d+$/.test(args[1] || '')) args = [command, '-n', args[1].slice(1), ...args.slice(2)];
        const {flags, files} = simpleArgs(args, 'n', ['n']); requireText(files.length <= 1, '파일은 하나만 지정하세요.'); const from = command === 'tail' && String(flags.n || '').startsWith('+'), count = number(from ? flags.n.slice(1) : flags.n ?? 10); if (from) requireText(count > 0, 'tail의 시작 줄은 1 이상입니다.');
        return input => { const lines = linesOf(streams(files, input, ctx)[0].text), selected = command === 'head' ? lines.slice(0, count) : from ? lines.slice(count - 1) : count === 0 ? [] : lines.slice(-count); return {text: linesText(selected), status: 0}; };
    }
    if (command === 'wc') {
        const {flags, files} = simpleArgs(tokens, 'lwmc'); requireText(files.length <= 1, 'wc 파일은 하나만 지정하세요.'); const selected = Object.keys(flags).length ? ['l', 'w', 'm', 'c'].filter(k => flags[k]) : ['l', 'w', 'c'];
        return input => { const source = streams(files, input, ctx)[0], text = source.text, values = {l: text.split('\n').length - 1, w: text.trim() ? text.trim().split(/\s+/u).length : 0, m: Array.from(text).length, c: new TextEncoder().encode(text).length}; return {text: selected.map(k => String(values[k]).padStart(selected.length > 1 ? 7 : 1)).join(' ') + (source.name ? ' ' + source.name : '') + '\n', status: 0}; };
    }
    if (command === 'sort') {
        const {flags, files} = simpleArgs(tokens, 'rnu'); requireText(files.length <= 1, 'sort 파일은 하나만 지정하세요.');
        return input => { let lines = linesOf(streams(files, input, ctx)[0].text); const compare = (a, b) => { ctx.meter.spend(a.length + b.length + 1); const x = flags.n ? parseFloat(a) || 0 : 0, y = flags.n ? parseFloat(b) || 0 : 0; return (x < y ? -1 : x > y ? 1 : flags.n && flags.u ? 0 : a < b ? -1 : a > b ? 1 : 0) * (flags.r ? -1 : 1); }; lines.sort(compare); if (flags.u) lines = lines.filter((s, i) => !i || (flags.n ? (parseFloat(s) || 0) !== (parseFloat(lines[i - 1]) || 0) : s !== lines[i - 1])); return {text: linesText(lines), status: 0}; };
    }
    if (command === 'uniq') {
        const {flags, files} = simpleArgs(tokens, 'cdu'); requireText(files.length <= 1 && !(flags.d && flags.u), 'uniq는 파일 하나와 -d 또는 -u를 사용합니다.');
        return input => { const groups = []; for (const line of linesOf(streams(files, input, ctx)[0].text)) { const last = groups.at(-1); if (last?.line === line) last.count++; else groups.push({line, count: 1}); } return {text: linesText(groups.filter(g => (!flags.d || g.count > 1) && (!flags.u || g.count === 1)).map(g => `${flags.c ? String(g.count).padStart(7) + ' ' : ''}${g.line}`)), status: 0}; };
    }
    const {flags, files} = simpleArgs(tokens, 'dfs', ['d', 'f']); requireText(files.length <= 1 && typeof flags.f === 'string', 'cut -d 구분자 -f 필드목록 [-s] [파일] 형식입니다.'); const delimiter = flags.d ?? '\t'; requireText(Array.from(delimiter).length === 1, '구분자는 문자 하나입니다.');
    const ranges = flags.f.split(',').map(part => { requireText(/^\d+(?:-\d*)?$|^-\d+$/.test(part), '필드 목록은 1,3-5,7- 형식입니다.'); if (!part.includes('-')) { const n = number(part); requireText(n > 0, '필드 번호는 1 이상입니다.'); return [n, n]; } const [a, b] = part.split('-'); const low = a ? number(a) : 1, high = b ? number(b) : Infinity; requireText(low > 0 && high >= low, '필드 범위를 확인하세요.'); return [low, high]; });
    return input => { const output = []; for (const line of linesOf(streams(files, input, ctx)[0].text)) { if (!line.includes(delimiter)) { if (!flags.s) output.push(line); } else { const fields = line.split(delimiter); ctx.meter.spend(fields.length * ranges.length); output.push(fields.filter((_, i) => ranges.some(([a, b]) => i + 1 >= a && i + 1 <= b)).join(delimiter)); } } return {text: linesText(output), status: 0}; };
}
export function iosFilter(name, pattern, ctx) {
    const test = compilePattern(pattern, {extended: true});
    return input => { const lines = linesOf(bounded(input, ctx.meter)), hits = lines.map(s => test(s, ctx.meter)); let result;
        if (name === 'include' || name === 'exclude') result = lines.filter((_, i) => hits[i] === (name === 'include'));
        else if (name === 'begin') { const start = hits.indexOf(true); result = start < 0 ? [] : lines.slice(start); }
        else { const blocks = []; for (let i = 0; i < lines.length; i++) { if (!blocks.length || /^\S/.test(lines[i])) blocks.push([]); blocks.at(-1).push(i); } const selected = new Set(blocks.filter(b => b.some(i => hits[i])).flat()); result = lines.filter((_, i) => selected.has(i)); }
        return {text: linesText(result), status: 0};
    };
}
