import {budget, compilePattern} from './text-pattern.js?ver=1.4.0-rc.4';
import {bounded, linesOf, linesText} from './text-utils.js?ver=1.4.0-rc.4';
import {windowsWords, psArguments, winNeed, winList, wildcard} from './windows-parser.js?ver=1.4.0-rc.4';
import {winProperty, winText, formatWindows} from './windows-format.js?ver=1.4.0-rc.4';
import {firewallFilterRows} from './windows-admin.js?ver=1.4.0-rc.4';
const rowsOf = value => { winNeed(Array.isArray(value.rows), '이 필터는 객체 입력을 받습니다. 텍스트 검색에는 Select-String을 사용하세요.'); return value.rows; };
export function windowsResult(value, meter = budget()) {
    const text = value.text !== undefined ? value.text : value.matches ? value.rows.map(r => r.Path === 'InputStream' ? r.Line : `${r.Path}:${r.LineNumber}:${r.Line}`).join('\n') : formatWindows(value.rows || [], value.list, value.properties);
    bounded(text, meter); return {text, status: value.status || 0};
}
export function findstrFilter(tokens, meter, read) {
    const flags = new Set(), patterns = [], files = []; let explicit = false, positional = false;
    for (let i = 1; i < tokens.length; i++) {
        const arg = tokens[i], c = /^\/c:(.*)$/i.exec(arg);
        if (c) { patterns.push(c[1]); explicit = true; }
        else if (arg.startsWith('/')) { winNeed(/^\/[ivnxlr]$/i.test(arg), `지원하지 않는 findstr 옵션: ${arg}`); flags.add(arg[1].toLowerCase()); }
        else if (!explicit && !positional) { patterns.push(...arg.split(/ +/)); positional = true; }
        else files.push(arg);
    }
    winNeed(patterns.length && patterns.length <= 16 && files.length <= 8 && !(flags.has('l') && flags.has('r')), 'findstr [/I /V /N /X /L /R] [/C:패턴] 패턴 [가상파일] 형식입니다.');
    const tests = patterns.map(p => {
        // FINDSTR has no ERE groups, alternation, +, ? or intervals.
        const source = p.replace(/[()+?|{}]/g, c => '\\' + c).replace(/\\[<>]/g, '\\b');
        const fixed = flags.has('l') || (explicit && !flags.has('r'));
        return compilePattern(fixed ? p : source, {fixed, extended: true, whole: flags.has('x'), insensitive: flags.has('i')});
    });
    return input => {
        winNeed(files.length || input !== undefined, 'findstr은 가상 파일 또는 파이프 입력이 필요합니다. 대화형 stdin은 미지원입니다.');
        const sources = files.length ? files.map(name => ({name, text: read(name)})) : [{name: '', text: input.text !== undefined ? input.text : windowsResult(input, meter).text}], output = []; let found = false;
        for (const {name, text} of sources) for (const [i, line] of linesOf(bounded(!text || text.endsWith('\n') ? text : text + '\n', meter)).entries()) if (tests.some(t => t(line, meter)) !== flags.has('v')) { found = true; output.push(`${sources.length > 1 ? name + ':' : ''}${flags.has('n') ? i + 1 + ':' : ''}${line}`); }
        return {text: output.join('\n'), status: found ? 0 : 1};
    };
}
function propertyNames(value) { const names = winList(value); winNeed(names.every(s => /^[A-Za-z][A-Za-z0-9_ ()-]{0,63}$/.test(s)), '속성 이름을 확인하세요.'); return names; }
export function powershellFilter(stage, meter, node) {
    const tokens = windowsWords(stage, 'powershell'), command = tokens[0].toLowerCase();
    if (['where-object', 'where', '?'].includes(command)) {
        let property, operator, expected, literal = false;
        const block = /^(?:Where-Object|where|\?)\s+\{\s*\$_\.([A-Za-z][A-Za-z0-9_]*)\s+-(eq|ne|gt|ge|lt|le|like|notlike|match|notmatch)\s+(.+?)\s*\}$/i.exec(stage);
        if (block) { const args = windowsWords(block[3], 'powershell'); winNeed(args.length === 1, 'Where-Object는 단일 속성 비교만 지원합니다.'); [, property, operator] = block; expected = args[0]; literal = !!args.literal[0]; }
        else { const flags = ['eq', 'ne', 'gt', 'ge', 'lt', 'le', 'like', 'notlike', 'match', 'notmatch'], args = [...tokens]; args.literal = [...tokens.literal]; const index = args.findIndex(s => flags.includes(s.replace(/^-/, '').toLowerCase())); if (index > 0 && args[index + 1] !== undefined && !args[index + 1].startsWith('-') && !args.some(s => s.toLowerCase() === '-value')) { args.splice(index + 1, 0, '-Value'); args.literal.splice(index + 1, 0, false); } const a = psArguments(args, ['property', 'value'], flags, ['property']); const selected = flags.filter(k => a[k]); winNeed(selected.length === 1 && a.property !== undefined && a.value !== undefined, 'Where-Object -Property 이름 -EQ -Value 값 또는 { $_.이름 -eq 값 } 형식입니다.'); property = a.property; operator = selected[0]; expected = a.value; literal = a.literals.value; }
        winNeed(literal || !expected.includes('$') || /^\$(true|false)$/i.test(expected), 'Where-Object는 일반 변수·식 평가를 지원하지 않습니다.');
        operator = operator.toLowerCase(); const test = operator.includes('like') ? wildcard(expected) : operator.includes('match') ? compilePattern(expected, {extended: true, insensitive: true}) : null;
        return value => ({rows: rowsOf(value).filter(row => { meter.spend(); const actual = row[winProperty(row, property)]; if (test) { const yes = test(winText(actual), meter); return operator.startsWith('not') ? !yes : yes; } const b = !literal && /^\$(true|false)$/i.test(expected) ? expected.toLowerCase() === '$true' : expected; const numeric = typeof actual === 'number'; const left = numeric ? actual : winText(actual).toLowerCase(), right = numeric ? Number(b) : winText(b).toLowerCase(); winNeed(!numeric || Number.isFinite(right), '숫자 속성은 숫자와 비교하세요.'); return ({eq: () => left === right, ne: () => left !== right, gt: () => left > right, ge: () => left >= right, lt: () => left < right, le: () => left <= right})[operator](); })});
    }
    if (['select-object', 'select'].includes(command)) {
        const a = psArguments(tokens, ['property', 'first', 'last', 'expandproperty'], ['unique'], ['property']);
        winNeed(!(a.first && a.last) && !(a.property && a.expandproperty), '선택 옵션을 확인하세요.');
        const names = a.property ? propertyNames(a.property) : null; const count = a.first ?? a.last;
        if (count !== undefined) winNeed(/^\d+$/.test(count) && Number(count) <= 4000, '선택 개수는 0~4000입니다.');
        return value => { let rows = rowsOf(value); if (names) rows = rows.map(row => Object.fromEntries(names.map(p => { const key = winProperty(row, p); return [key, row[key]]; }))); if (a.expandproperty) rows = rows.flatMap(row => row[winProperty(row, a.expandproperty)] ?? []); if (a.unique) { const seen = new Set(); rows = rows.filter(r => { const key = JSON.stringify(r); meter.spend(key.length + 1); if (seen.has(key)) return false; seen.add(key); return true; }); } if (a.first !== undefined) rows = rows.slice(0, Number(a.first)); if (a.last !== undefined) rows = Number(a.last) ? rows.slice(-Number(a.last)) : []; return {rows}; };
    }
    if (['sort-object', 'sort'].includes(command)) {
        const a = psArguments(tokens, ['property'], ['descending', 'unique'], ['property']); winNeed(a.property, 'Sort-Object -Property 이름 형식입니다.'); const names = propertyNames(a.property);
        return value => { const rows = [...rowsOf(value)]; const compare = (aRow, bRow) => { meter.spend(names.length); for (const p of names) { const aValue = aRow[winProperty(aRow, p)], bValue = bRow[winProperty(bRow, p)]; const left = typeof aValue === 'number' ? aValue : winText(aValue).toLowerCase(), right = typeof bValue === 'number' ? bValue : winText(bValue).toLowerCase(); if (left !== right) return (left < right ? -1 : 1) * (a.descending ? -1 : 1); } return 0; }; rows.sort(compare); return {rows: a.unique ? rows.filter((r, i) => !i || compare(r, rows[i - 1]) !== 0) : rows}; };
    }
    if (['measure-object', 'measure'].includes(command)) {
        const a = psArguments(tokens, ['property'], ['sum', 'minimum', 'maximum', 'average']); winNeed(!['sum', 'minimum', 'maximum', 'average'].some(k => a[k]) || a.property, '집계할 숫자 속성이 필요합니다.');
        return value => { const rows = rowsOf(value), out = {Count: rows.length}; if (a.property) { const numbers = rows.map(r => { meter.spend(); const x = r[winProperty(r, a.property)]; winNeed(typeof x === 'number' && Number.isFinite(x), '집계 속성은 숫자여야 합니다.'); return x; }); if (a.sum) out.Sum = numbers.reduce((x, y) => x + y, 0); if (a.minimum) out.Minimum = numbers.length ? Math.min(...numbers) : null; if (a.maximum) out.Maximum = numbers.length ? Math.max(...numbers) : null; if (a.average) out.Average = numbers.length ? numbers.reduce((x, y) => x + y, 0) / numbers.length : null; } return {rows: [out], list: true}; };
    }
    if (['format-table', 'ft', 'format-list', 'fl'].includes(command)) {
        const a = psArguments(tokens, ['property'], ['autosize'], ['property']), names = a.property ? propertyNames(a.property) : null;
        return value => { const rows = rowsOf(value); const properties = names && rows.length ? names.map(p => winProperty(rows[0], p)) : null; return {text: formatWindows(rows, ['format-list', 'fl'].includes(command), properties)}; };
    }
    if (command === 'out-string') { psArguments(tokens, [], ['stream']); return value => ({text: windowsResult(value, meter).text}); }
    if (['get-netfirewallportfilter', 'get-netfirewalladdressfilter'].includes(command)) { psArguments(tokens); return value => { winNeed(node, 'Windows 장비가 필요합니다.'); const rules = rowsOf(value).map(row => { const name = row[winProperty(row, 'Name')], rule = node.windows.rules.find(r => r.name === name); winNeed(rule, '이 장비의 방화벽 규칙 객체가 필요합니다.'); return rule; }); return {rows: firewallFilterRows(node, rules, command === 'get-netfirewalladdressfilter')}; }; }
    if (['select-string', 'sls'].includes(command)) {
        const a = psArguments(tokens, ['pattern'], ['simplematch', 'casesensitive', 'notmatch', 'quiet', 'list'], ['pattern']); winNeed(a.pattern !== undefined, 'Select-String -Pattern 패턴 형식입니다.'); const test = compilePattern(a.pattern, {fixed: !!a.simplematch, extended: true, insensitive: !a.casesensitive});
        return value => { winNeed(value.text !== undefined || value.rows?.every(r => typeof r === 'string'), '객체는 먼저 Out-String으로 변환하거나 Where-Object로 검색하세요.'); const text = value.text ?? linesText(value.rows), lines = linesOf(!text || text.endsWith('\n') ? text : text + '\n'), rows = []; for (const [i, line] of lines.entries()) if (test(line, meter) !== !!a.notmatch) { rows.push({LineNumber: i + 1, Line: line, Pattern: a.pattern, Path: value.path || 'InputStream'}); if (a.list) break; } return a.quiet ? {rows: [!!rows.length]} : {rows, matches: true}; };
    }
    throw new Error('지원하지 않는 PowerShell 파이프 명령입니다. 객체 조회와 지원 필터만 연결하세요.');
}
export function runWindowsPipeline(parts, shell, source, read, node) {
    const meter = budget(), filters = parts.slice(1).map(stage => { const tokens = windowsWords(stage, shell); if (tokens[0].toLowerCase() === 'findstr') return findstrFilter(tokens, meter, read); winNeed(shell === 'powershell', 'CMD 파이프에는 findstr을 지원합니다.'); return powershellFilter(stage, meter, node); });
    let result = source(); windowsResult(result, meter);
    for (const filter of filters) { result = filter(result); winNeed(!result.rows || result.rows.length <= 4000, '객체는 최대 4000개입니다.'); windowsResult(result, meter); }
    return windowsResult(result, meter);
}
