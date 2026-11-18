import {integer, ipv4} from './model.js?ver=1.4.0-rc.4';
import {windowsResolver} from './windows-config.js?ver=1.4.0-rc.4';
import {windowsParts, windowsWords, psArguments, winNeed, winEnum} from './windows-parser.js?ver=1.4.0-rc.4';
import {powershellNetwork, powershellFirewall} from './windows-admin.js?ver=1.4.0-rc.4';
import {windowsCmd} from './windows-cmd.js?ver=1.4.0-rc.4';
import {windowsFiles} from './windows-files.js?ver=1.4.0-rc.4';
import {powershellFilter, findstrFilter, runWindowsPipeline} from './windows-pipeline.js?ver=1.4.0-rc.4';
import {budget} from './text-pattern.js?ver=1.4.0-rc.4';
import {linesOf} from './text-utils.js?ver=1.4.0-rc.4';
import {WINDOWS_HELP} from './windows-help.js?ver=1.4.0-rc.4';
const GET = ['Get-NetAdapter', 'Get-NetIPConfiguration', 'Get-NetIPAddress', 'Get-NetIPInterface', 'Get-NetRoute', 'Get-NetNeighbor', 'Get-NetTCPConnection', 'Get-DnsClientServerAddress', 'Get-NetConnectionProfile', 'Get-NetFirewallProfile', 'Get-NetFirewallRule', 'Get-NetFirewallPortFilter', 'Get-NetFirewallAddressFilter', 'Get-Content', 'Get-ChildItem', 'Get-Location', 'Get-History', 'Get-Help'];
const SET = ['New-NetIPAddress', 'Set-NetIPAddress', 'Remove-NetIPAddress', 'Set-NetIPInterface', 'Enable-NetAdapter', 'Disable-NetAdapter', 'New-NetRoute', 'Set-NetRoute', 'Remove-NetRoute', 'Set-DnsClientServerAddress', 'Set-NetConnectionProfile', 'New-NetFirewallRule', 'Set-NetFirewallRule', 'Enable-NetFirewallRule', 'Disable-NetFirewallRule', 'Remove-NetFirewallRule', 'Set-NetFirewallProfile', 'Resolve-DnsName', 'Test-NetConnection', 'Set-Location', 'Select-String', 'Write-Output', 'Clear-Host'];
const CMD = ['ipconfig', 'ping', 'tracert', 'arp', 'route', 'netstat', 'nslookup', 'hostname', 'getmac', 'ver', 'cls', 'echo', 'dir', 'cd', 'type', 'findstr', 'help', 'cmd', 'powershell', 'pwsh', 'exit', 'lab'];
const FILTERS = ['Where-Object', 'Select-Object', 'Sort-Object', 'Measure-Object', 'Format-Table', 'Format-List', 'Out-String', 'Select-String', 'Get-NetFirewallPortFilter', 'Get-NetFirewallAddressFilter', 'findstr'];
function readOnly(tokens, shell) {
    const t = tokens.map(s => s.toLowerCase()), first = t[0];
    if (shell === 'powershell' && [...GET.map(s => s.toLowerCase()), 'gc', 'cat', 'ls', 'pwd', 'echo', 'write-output', 'select-string', 'sls'].includes(first)) return true;
    return ['hostname', 'getmac', 'ver', 'echo', 'dir', 'type', 'help'].includes(first) || (first === 'ipconfig' && (t.length === 1 || (t.length === 2 && ['/all', '/displaydns'].includes(t[1])))) || (first === 'arp' && t[1] === '-a') || (first === 'route' && (t[1] === 'print' || (['-4', '/4'].includes(t[1]) && t[2] === 'print'))) || first === 'netstat' || first === 'findstr' || (first === 'cd' && t.length === 1);
}
function powershellSource(e, n, s, tokens, previous, files) {
    const command = tokens[0].toLowerCase();
    if (['get-help', 'help', '?'].includes(command)) { winNeed(tokens.length <= 2); return {text: tokens[1] ? WINDOWS_HELP.split('\n').filter(line => line.toLowerCase().includes(tokens[1].toLowerCase())).join('\n') || '지원 명령을 찾지 못했습니다. Get-Help를 사용하세요.' : WINDOWS_HELP}; }
    if (['clear-host', 'cls', 'clear'].includes(command)) { winNeed(tokens.length === 1); e.say(n, '', true); return {rows: []}; }
    if (['get-content', 'gc', 'cat', 'type'].includes(command)) { const a = psArguments(tokens, ['path', 'literalpath'], ['raw'], ['path']); winNeed(!!a.path !== !!a.literalpath, '가상 파일 경로 하나를 지정하세요.'); const text = files.read(a.path || a.literalpath); return a.raw ? {text} : {rows: linesOf(text)}; }
    if (['get-childitem', 'dir', 'ls'].includes(command)) { const a = psArguments(tokens, ['path', 'literalpath'], [], ['path']); winNeed(!(a.path && a.literalpath)); return {rows: files.entries(a.path || a.literalpath)}; }
    if (['get-location', 'pwd'].includes(command)) { psArguments(tokens); return {rows: [{Path: files.cwd()}]}; }
    if (['set-location', 'cd', 'chdir'].includes(command)) { const a = psArguments(tokens, ['path', 'literalpath'], [], ['path']); winNeed(!!a.path !== !!a.literalpath, '이동할 가상 경로가 필요합니다.'); s.cwd = files.directory(a.path || a.literalpath); return {rows: []}; }
    if (command === 'get-history') { const a = psArguments(tokens, ['count']); const count = integer(Number(a.count ?? 100), 0, 100); return {rows: (count ? s.history.slice(-count) : []).map((line, i) => ({Id: Math.max(0, s.history.length - count) + i + 1, CommandLine: line}))}; }
    if (['write-output', 'echo'].includes(command)) {
        return {rows: tokens.slice(1).flatMap((value, i) => { if (tokens.literal?.[i + 1]) return [value]; if (value === '$?') return [previous === 0]; if (/^\$true$/i.test(value)) return [true]; if (/^\$false$/i.test(value)) return [false]; if (/^\$null$/i.test(value)) return []; winNeed(!value.includes('$'), '일반 변수·문자열 보간은 미지원입니다. 리터럴 $는 작은따옴표로 보호하세요.'); return [value]; })};
    }
    if (['select-string', 'sls'].includes(command)) {
        const a = psArguments(tokens, ['path', 'literalpath', 'pattern'], ['simplematch', 'casesensitive', 'notmatch', 'quiet', 'list'], ['pattern']); winNeed(!!a.path !== !!a.literalpath, 'Select-String을 단독으로 실행하면 가상 Path가 필요합니다.');
        const options = ['Select-String', '-Pattern', a.pattern]; for (const key of ['simplematch', 'casesensitive', 'notmatch', 'quiet', 'list']) if (a[key]) options.push('-' + key);
        const stage = options.map(t => '"' + String(t).replace(/"/g, '""') + '"').join(' '); return powershellFilter(stage, budget(), n)({text: files.read(a.path || a.literalpath), path: a.path || a.literalpath});
    }
    if (command === 'resolve-dnsname') { const a = psArguments(tokens, ['name', 'server', 'type'], [], ['name']); winNeed(a.name && /^[a-z0-9][a-z0-9.-]{0,100}$/i.test(a.name)); if (a.type) winNeed(a.type.toLowerCase() === 'a', 'DNS A 레코드만 지원합니다.'); const j = e.startDNS(n, a.name, a.server || windowsResolver(n, f => e.portUp(n, f))); j.windows = 'ps-dns'; return {rows: []}; }
    if (command === 'test-netconnection') {
        const a = psArguments(tokens, ['computername', 'port', 'hops', 'informationlevel'], ['traceroute'], ['computername']); winNeed(a.computername, '실습 대상 ComputerName 또는 IPv4 주소를 지정하세요.'); winNeed(!(a.port && a.traceroute) && (!a.hops || a.traceroute), 'Port와 TraceRoute는 별도 검사입니다. Hops는 TraceRoute에서만 사용합니다.'); const options = {windows: 'ps-test', computer: a.computername, count: 1, quiet: a.informationlevel ? winEnum(a.informationlevel, ['Quiet', 'Detailed']) === 'Quiet' : false}; if (a.port !== undefined) options.port = integer(Number(a.port), 1, 65535); if (a.hops !== undefined) options.hops = integer(Number(a.hops), 1, 8); e.start(n, a.port ? 'tcp' : a.traceroute ? 'trace' : 'ping', a.computername, options); return {rows: []};
    }
    return powershellNetwork(e, n, tokens) || powershellFirewall(e, n, tokens);
}
export function windowsCommand(e, n, s, line, previous) {
    if (!line.trim()) return {text: '', status: 0};
    const parts = windowsParts(line, s.shell || 'cmd'), tokens = windowsWords(parts[0], s.shell || 'cmd');
    if (!tokens.length) return {text: '', status: 0};
    if (/^(?:cmd|powershell|pwsh|ipconfig|ping|tracert|arp|route|netstat|nslookup|hostname|getmac|findstr)\.exe$/i.test(tokens[0])) tokens[0] = tokens[0].slice(0, -4);
    const key = tokens[0].toLowerCase(), files = windowsFiles(e, n, s, previous);
    if (['cmd', 'powershell', 'pwsh', 'exit'].includes(key)) { winNeed(parts.length === 1 && tokens.length === 1, '셸 전환은 인수·스크립트 없이 실행합니다.'); winNeed(key !== 'exit' || s.shell === 'powershell', 'CMD 종료는 미지원입니다. 장비를 선택해 콘솔을 전환하세요.'); s.shell = key === 'cmd' || key === 'exit' ? 'cmd' : 'powershell'; return {text: `${s.shell === 'cmd' ? 'Command Prompt' : 'PowerShell'} · Windows 네트워크 학습 부분집합`, status: 0}; }
    if (s.shell === 'powershell') winNeed(tokens.every((value, i) => tokens.literal?.[i] || !value.includes('$') || /^\$(?:true|false|null|\?)$/i.test(value) || /^-Confirm:\$false$/i.test(value)), '일반 변수·식·문자열 보간·명령 치환은 미지원입니다.');
    if (parts.length > 1) winNeed(readOnly(tokens, s.shell), '파이프는 동기 조회만 지원합니다. 설정 변경·셸 전환·통신 명령은 단독 실행하세요.');
    const result = runWindowsPipeline(parts, s.shell, () => {
        if (key === 'findstr') return findstrFilter(tokens, budget(), files.read)(undefined);
        const native = s.shell === 'powershell' ? powershellSource(e, n, s, tokens, previous, files) : null;
        const value = native || windowsCmd(e, n, s, tokens, previous, parts[0], files);
        winNeed(value, `지원하지 않는 ${s.shell === 'powershell' ? 'PowerShell' : 'CMD'} 명령입니다. help로 지원 목록을 확인하세요.`); return value;
    }, files.read, n);
    return result;
}
export function windowsComplete(n, s, line) {
    const split = line.lastIndexOf('|'), prefix = split < 0 ? '' : line.slice(0, split + 1) + ' ', fragment = split < 0 ? line.trimStart() : line.slice(split + 1).trimStart(), words = fragment.split(/\s+/), current = words.pop() || ''; let choices = [];
    if (!words.length) choices = (split >= 0 ? s.shell === 'powershell' ? FILTERS : ['findstr'] : [...CMD, ...(s.shell === 'powershell' ? [...GET, ...SET] : [])]).filter(p => p.toLowerCase().startsWith(current.toLowerCase()));
    else if (current.startsWith('-') && s.shell === 'powershell') choices = ['-InterfaceAlias', '-InterfaceIndex', '-AddressFamily', '-IPAddress', '-PrefixLength', '-DefaultGateway', '-DestinationPrefix', '-NextHop', '-RouteMetric', '-Name', '-DisplayName', '-Profile', '-Direction', '-Action', '-Protocol', '-LocalPort', '-RemotePort', '-LocalAddress', '-RemoteAddress', '-Enabled', '-Property', '-Value', '-First', '-Descending', '-ServerAddresses', '-Dhcp', '-NetworkCategory', '-ComputerName', '-Port', '-InformationLevel', '-WhatIf'].filter(p => p.toLowerCase().startsWith(current.toLowerCase()));
    else if (/^-interfacealias$|^-name$/i.test(words.at(-1) || '')) choices = n.interfaces.map((_, i) => i ? `"Ethernet ${i + 1}"` : 'Ethernet').filter(p => p.toLowerCase().startsWith(current.toLowerCase()));
    else if (/^ipconfig$/i.test(words[0]) && current.startsWith('/')) choices = ['/all', '/renew', '/release', '/flushdns', '/displaydns'].filter(p => p.startsWith(current.toLowerCase()));
    return {line: choices.length === 1 ? prefix + [...words, choices[0]].join(' ') + ' ' : line, choices};
}
