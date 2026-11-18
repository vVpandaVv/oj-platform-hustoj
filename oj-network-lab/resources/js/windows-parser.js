import {compilePattern} from './text-pattern.js?ver=1.4.0-rc.4';
export const winNeed = (ok, message = '지원하는 Windows 명령 문법을 확인하세요. help 또는 Get-Help를 사용하세요.') => { if (!ok) throw new Error(message); };
export function windowsParts(line, shell) {
    winNeed(typeof line === 'string' && line.length <= 2048 && !/[\r\n\x00]/.test(line), '명령은 한 줄 2048자 이내입니다.');
    const out = []; let quote = '', start = 0, brace = 0;
    for (let i = 0; i < line.length; i++) {
        const c = line[i];
        if (quote) { if (c === quote) { if (line[i + 1] === quote) i++; else quote = ''; } continue; }
        if (c === '"' || (shell === 'powershell' && c === "'")) { quote = c; continue; }
        winNeed(!';&<>`'.includes(c) && !(c === '$' && line[i + 1] === '('), '셸 제어·명령 치환·리다이렉션·이스케이프 실행은 지원하지 않습니다.');
        if (c === '{') { brace++; winNeed(shell === 'powershell' && brace === 1 && /^(?:where-object|where|\?)\s*$/i.test(line.slice(start, i).trimStart()), '제한된 Where-Object 조건 블록만 지원합니다.'); }
        if (c === '}') { brace--; winNeed(brace === 0, '조건 블록을 확인하세요.'); }
        if (c === '|') { winNeed(!brace, '조건 블록 안의 파이프는 미지원입니다.'); out.push(line.slice(start, i).trim()); start = i + 1; }
    }
    winNeed(!quote && !brace, '따옴표·조건 블록을 닫아 주세요.'); out.push(line.slice(start).trim());
    winNeed(out.length <= 8 && out.every(Boolean), '명령·필터 합계 8단계까지 지원하며 빈 파이프는 사용할 수 없습니다.'); return out;
}
export function windowsWords(line, shell) {
    const out = [], literals = []; let value = '', active = false, quote = '', literal = false;
    for (let i = 0; i < line.length; i++) {
        const c = line[i];
        if (quote) { if (c === quote) { if (line[i + 1] === quote) { value += c; i++; } else quote = ''; } else { if (quote === "'" && c === '$') literal = true; value += c; } }
        else if (c === '"' || (shell === 'powershell' && c === "'")) { quote = c; active = true; }
        else if (/\s/.test(c)) { if (active) { out.push(value); literals.push(literal); value = ''; active = false; literal = false; } }
        else { value += c; active = true; }
    }
    winNeed(!quote, '따옴표를 닫아 주세요.'); if (active) { out.push(value); literals.push(literal); } out.literal = literals; return out;
}
export function psArguments(tokens, valued = [], switches = [], positional = []) {
    const values = Object.create(null), rest = [], literals = Object.create(null); Object.defineProperty(values, 'literals', {value: literals});
    for (let i = 1; i < tokens.length; i++) {
        const token = tokens[i], m = /^-([A-Za-z][A-Za-z0-9]*)(?::(.*))?$/.exec(token);
        if (!m) { rest.push({value: token, index: i}); continue; }
        const key = m[1].toLowerCase(); winNeed(values[key] === undefined, `중복 매개변수: -${m[1]}`);
        if (key === 'confirm') { winNeed(m[2]?.toLowerCase() === '$false', '-Confirm:$false만 지원합니다.'); values[key] = false; continue; }
        if (switches.includes(key)) { winNeed(m[2] === undefined, '이 스위치는 값을 받지 않습니다.'); values[key] = true; }
        else { winNeed(valued.includes(key), `지원하지 않는 매개변수: -${m[1]}`); const value = m[2] ?? tokens[++i]; winNeed(value !== undefined && !/^-[A-Za-z]/.test(value), `-${m[1]} 값이 필요합니다.`); values[key] = value; literals[key] = !!tokens.literal?.[i]; }
    }
    winNeed(rest.length <= positional.length, '추가 위치 인수를 지원하지 않습니다.');
    rest.forEach(({value, index}, i) => { winNeed(values[positional[i]] === undefined, '매개변수를 중복 지정했습니다.'); values[positional[i]] = value; literals[positional[i]] = !!tokens.literal?.[index]; }); return values;
}
export const winEnum = (value, choices) => { const selected = choices.find(s => s.toLowerCase() === String(value).toLowerCase()); winNeed(selected, `지원 값: ${choices.join(', ')}`); return selected; };
export const winBool = value => { const s = String(value).toLowerCase(); winNeed(['true', 'false', '$true', '$false'].includes(s), 'True/False 또는 $true/$false가 필요합니다.'); return s === 'true' || s === '$true'; };
export const winList = value => { winNeed(typeof value === 'string' && value.length <= 512, '쉼표로 구분한 값이 필요합니다.'); const a = value.split(',').map(s => s.trim()); winNeed(a.length <= 16 && a.every(Boolean), '목록은 비어 있지 않은 값 16개 이내입니다.'); return a; };
export function wildcard(pattern, insensitive = true) { winNeed(pattern.length <= 256, '패턴은 256자 이내입니다.'); const source = Array.from(pattern).map(c => c === '*' ? '.*' : c === '?' ? '.' : /[.\\^$+()|{}\[\]]/.test(c) ? '\\' + c : c).join(''); return compilePattern('^' + source + '$', {extended: true, insensitive}); }
