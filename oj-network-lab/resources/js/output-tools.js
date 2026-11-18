import {budget} from './text-pattern.js?ver=1.4.0-rc.4';
import {diagnosticContext, TOOLS_HELP, textFile} from './diagnostics.js?ver=1.4.0-rc.4';
import {UTILITIES, bounded, toolWords, utility, iosFilter, requireText, linesText} from './text-utils.js?ver=1.4.0-rc.4';
import {iosPatterns} from './cli-ios.js?ver=1.4.0-rc.4';
import {expand} from './cli-common.js?ver=1.4.0-rc.4';
const IOS_FILTERS = ['include', 'exclude', 'begin', 'section'];
const LAB = ['help', 'files', 'logs', 'history', 'stats', 'status', 'diff', ...UTILITIES];
export function pipelineParts(line, strict = true) {
    requireText(typeof line === 'string' && line.length <= 2048 && !/[\r\n\x00]/.test(line), '명령은 한 줄 2048자 이내입니다.');
    const parts = []; let quote = '', start = 0;
    for (let i = 0; i < line.length; i++) {
        const c = line[i];
        if (quote === "'") { if (c === "'") quote = ''; continue; }
        if (c === '\\') { i++; requireText(i < line.length, '끝의 역슬래시를 확인하세요.'); continue; }
        if (c === quote && quote) { quote = ''; continue; }
        if (!quote && (c === '"' || c === "'")) { quote = c; continue; }
        if (strict && (c === '`' || (c === '$' && line[i + 1] === '('))) throw new Error('명령 치환·셸 실행은 지원하지 않습니다.');
        if (quote) continue;
        if (strict && (';&<>'.includes(c) || (c === '|' && line[i + 1] === '|'))) throw new Error('셸 제어·리다이렉션은 지원하지 않습니다. 출력 파이프만 사용하세요.');
        if (c === '|') { parts.push(line.slice(start, i).trim()); start = i + 1; }
    }
    requireText(!quote, '따옴표를 닫아 주세요.'); parts.push(line.slice(start).trim()); return parts;
}
function readonly(profile, tokens) {
    const t = tokens.map(s => s.toLowerCase()), key = t.join(' ');
    if (profile === 'ios') return t[0] === 'show' || (t[0] === 'do' && t[1] === 'show');
    if (profile === 'fortios') return t[0] === 'show' || ['get system status', 'get router info routing-table all', 'diagnose sys session list', 'diagnose vpn tunnel list', 'diagnose vpn ike gateway list', 'execute log display'].includes(key);
    if (['help', '?', 'nft list ruleset', 'conntrack -l', 'bridge vlan show'].includes(key)) return true;
    if (t[0] === 'ip' && ['addr', 'address', 'link', 'route', 'neigh'].includes(t[1]) && (t.length === 2 || (t.length === 3 && t[2] === 'show'))) return true;
    if (t[0] === 'ip' && t[1] === '-6' && ['addr', 'address', 'route', 'neigh'].includes(t[2]) && (t.length === 3 || t.length === 4 && t[3] === 'show')) return true;
    if (t[0] === 'bridge' && t[1] === 'fdb' && (t.length === 2 || key === 'bridge fdb show')) return true;
    if (t[0] === 'ss' && (t.length === 1 || key === 'ss -tan')) return true;
    return t[0] === 'wg' && t[1] === 'show' && t.length <= 3;
}
function sourceTool(tokens, ctx) {
    if (tokens[0] !== 'lab') return utility(tokens, ctx);
    const t = tokens.slice(1), key = t[0]; t.expandable = tokens.expandable?.slice(1); requireText(key, 'lab help로 분석 도구를 확인하세요.');
    if (['help', 'files', 'stats', 'status'].includes(key)) { requireText(t.length === 1, `lab ${key}는 추가 인수를 받지 않습니다.`); return () => ({text: textFile(key === 'help' ? TOOLS_HELP : key === 'files' ? ctx.paths.join('\n') : key === 'stats' ? ctx.stats() : String(ctx.previous)), status: 0}); }
    if (key === 'logs' || key === 'history') { requireText(t.length === 1, `lab ${key}는 추가 인수를 받지 않습니다.`); return utility(['cat', key === 'logs' ? '/var/log/network.log' : '/history/commands.txt'], ctx); }
    if (key === 'diff' && t.length === 1) return utility(['diff', '-u', '/config/startup.conf', '/config/running.conf'], ctx);
    return utility(t, ctx);
}
export function outputCommand(e, n, s, line, native, previous) {
    const first = line.trim().split(/\s+/)[0], common = first === 'lab' || (n.profile === 'linux' && UTILITIES.includes(first));
    if (!common && !line.includes('|')) return null;
    let parts = pipelineParts(line, common);
    if (!common && parts.length === 1) return null;
    parts = pipelineParts(line, true);
    if (n.profile === 'ios') {
        const merged = [parts[0]];
        for (let part of parts.slice(1)) { const head = part.split(/\s+/)[0].toLowerCase(), matches = IOS_FILTERS.filter(f => f.startsWith(head)); if (matches.length === 1) part = matches[0] + part.slice(head.length); const prior = merged.at(-1); if (merged.length > 1 && IOS_FILTERS.includes(prior.split(/\s+/)[0].toLowerCase()) && ![...IOS_FILTERS, 'lab'].includes(part.split(/\s+/)[0].toLowerCase())) merged[merged.length - 1] += '|' + part; else merged.push(part); }
        parts = merged;
    }
    requireText(parts.length <= 8 && parts.every(Boolean), '빈 파이프는 사용할 수 없으며 명령·필터 합계 8단계까지 지원합니다.');
    const meter = budget(), ctx = diagnosticContext(e, n, s, previous, meter), sourceTokens = toolWords(parts[0]);
    if (!common) requireText(readonly(n.profile, n.profile === 'ios' ? expand(sourceTokens, iosPatterns(s)) : sourceTokens), '파이프는 동기 조회 명령에만 사용할 수 있습니다. 설정 변경·ping·traceroute·실시간 캡처는 단독 실행하세요.');
    const filters = parts.slice(1).map(part => {
        const tokens = toolWords(part), name = tokens[0].toLowerCase();
        if (n.profile === 'ios' && IOS_FILTERS.includes(name)) { const raw = part.trim().slice(tokens[0].length).trim(); requireText(raw !== '', '출력 필터의 패턴이 필요합니다.'); const pattern = (raw.startsWith('"') || raw.startsWith("'")) && tokens.length === 2 ? tokens[1] : raw; return iosFilter(name, pattern, ctx); }
        if (name === 'lab') { const rest = tokens.slice(1); rest.expandable = tokens.expandable?.slice(1); return utility(rest, ctx); }
        if (n.profile === 'fortios' && !common && name === 'grep') return utility(tokens, ctx, {forti: true});
        requireText(common || n.profile === 'linux', '이 장비의 고유 필터를 사용하거나 추가 분석 도구에 lab 접두사를 붙이세요.');
        requireText(['grep', 'head', 'tail', 'wc', 'sort', 'uniq', 'cut', 'cat'].includes(tokens[0]), '파이프 뒤에는 지원하는 텍스트 필터만 사용할 수 있습니다.'); return utility(tokens, ctx);
    });
    // All filter syntax and regular expressions are validated before invoking the source.
    const source = common ? sourceTool(sourceTokens, ctx) : () => ({text: textFile(native(parts[0])), status: 0});
    let result = source(undefined); bounded(result.text, meter);
    for (const filter of filters) { result = filter(result.text); bounded(result.text, meter); }
    return result;
}
export function outputComplete(e, n, s, line) {
    const first = line.trimStart().split(/\s+/)[0], hasPipe = line.includes('|'), common = first === 'lab' || (n.profile === 'linux' && UTILITIES.includes(first));
    if (!hasPipe && !common) { if (n.profile === 'linux') { const choices = ['help', 'lab', ...UTILITIES].filter(x => x.startsWith(first)); return {line: choices.length === 1 ? choices[0] + ' ' : line, choices}; } return null; }
    const split = line.lastIndexOf('|'), prefix = split >= 0 ? line.slice(0, split + 1) + ' ' : '', fragment = split >= 0 ? line.slice(split + 1).trimStart() : line.trimStart();
    const words = fragment.split(/\s+/), current = words.pop() || ''; let choices;
    if (current.startsWith('/')) choices = diagnosticContext(e, n, s, s.status || 0, budget()).paths.filter(p => p.startsWith(current));
    else if (current.startsWith('-')) {
        const command = words[0] === 'lab' ? words[1] : words[0], nativeForti = n.profile === 'fortios' && !common && words[0] !== 'lab';
        const flags = {grep: nativeForti ? ['-i', '-n', '-v', '-f', '-c', '-A', '-B', '-C'] : ['-i', '-n', '-v', '-F', '-E', '-f', '-e', '-c', '-w', '-x', '-q', '-A', '-B', '-C', '-m', '--help'], head: ['-n', '--help'], tail: ['-n', '--help'], wc: ['-l', '-w', '-m', '-c', '--help'], sort: ['-n', '-r', '-u', '--help'], uniq: ['-c', '-d', '-u', '--help'], cut: ['-d', '-f', '-s', '--help'], cat: ['-n', '--help']};
        choices = (flags[command] || []).filter(f => f.startsWith(current));
    }
    else if (!words.length) choices = (hasPipe && !common ? n.profile === 'ios' ? [...IOS_FILTERS, 'lab'] : n.profile === 'fortios' ? ['grep', 'lab'] : UTILITIES : ['lab', ...UTILITIES]).filter(p => p.startsWith(current));
    else if (words.length === 1 && words[0] === 'lab') choices = [...new Set(LAB)].filter(p => p.startsWith(current));
    else choices = [];
    return {line: choices.length === 1 ? prefix + [...words, choices[0]].join(' ') + ' ' : line, choices};
}
