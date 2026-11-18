import {cidr, ipv4, ipString, integer, contains} from './model.js?ver=1.4.0-rc.4';
import {maskPrefix, update} from './cli-common.js?ver=1.4.0-rc.4';
import {winAlias, winAdapter, windowsResolver} from './windows-config.js?ver=1.4.0-rc.4';
import {winNeed} from './windows-parser.js?ver=1.4.0-rc.4';
import {windowsConnections, windowsMask, windowsGateway, selectWindowsAdapters} from './windows-query.js?ver=1.4.0-rc.4';
import {renewWindows, releaseWindows} from './windows-admin.js?ver=1.4.0-rc.4';
import {WINDOWS_HELP} from './windows-help.js?ver=1.4.0-rc.4';
function ipconfig(e, n, all) { return 'Windows IP Configuration\n' + (all ? `\n   Host Name . . . . . . . . . . . . : ${n.name}\n   IP Routing Enabled. . . . . . . . : No\n` : '') + n.interfaces.map(f => {
    const a = winAdapter(n, f), up = e.portUp(n, f);
    return `\nEthernet adapter ${winAlias(n, f)}:\n${!up ? '   Media State . . . . . . . . . . . : Media disconnected\n' : ''}${all ? `   Physical Address. . . . . . . . . : ${f.mac.replace(/:/g, '-').toUpperCase()}\n   DHCP Enabled. . . . . . . . . . . : ${a.dhcp ? 'Yes' : 'No'}\n` : ''}   IPv4 Address. . . . . . . . . . . : ${f.address ? cidr(f.address).address : ''}\n   Subnet Mask . . . . . . . . . . . : ${windowsMask(f)}\n   Default Gateway . . . . . . . . . : ${windowsGateway(n, f)}${all ? `\n   DNS Servers . . . . . . . . . . . : ${a.dns}` : ''}`;
}).join('\n'); }
export function cmdRoutes(e, n) { return `Interface List\n${n.interfaces.map((f, i) => `${i + 1} ... ${f.mac.replace(/:/g, ' ')} ... ${winAlias(n, f)}`).join('\n')}\n\nIPv4 Route Table\nActive Routes:\nNetwork Destination  Netmask  Gateway  Interface  Metric\n${e.routes(n).map(r => { const c = cidr(r.dest), f = n.interfaces.find(f => f.name === r.dev); return `${ipString(c.network)}  ${ipString(c.mask)}  ${r.via || 'On-link'}  ${f.address ? cidr(f.address).address : '-'}  ${r.metric + winAdapter(n, f).metric}`; }).join('\n')}`; }
export function windowsCmd(e, n, s, tokens, previous, raw, files) {
    const t = tokens, low = t.map(x => x.toLowerCase()), command = low[0];
    if (['help', '?'].includes(command) || (low.length === 2 && low[1] === '/?')) return {text: WINDOWS_HELP};
    if (command === 'ipconfig') {
        winNeed(t.length <= 3); const option = low[1] || '';
        if (!option || option === '/all') { winNeed(t.length <= 2); return {text: ipconfig(e, n, option === '/all')}; }
        if (option === '/flushdns') { winNeed(t.length === 2); e.rt(n).dns.clear(); return {text: 'Windows IP Configuration\nSuccessfully flushed the DNS Resolver Cache.'}; }
        if (option === '/displaydns') { winNeed(t.length === 2); return {text: [...e.rt(n).dns.entries()].filter(([, v]) => v.until > e.now).map(([name, v]) => `${name}\n    Record Type : 1\n    Time To Live : ${Math.floor((v.until - e.now) / 1000)}\n    A (Host) Record : ${v.value}`).join('\n\n') || 'Windows IP Configuration\nDNS Resolver Cache is empty.'}; }
        if (option === '/renew' || option === '/release') { const selected = t[2] ? selectWindowsAdapters(n, {interfacealias: t[2]}) : n.interfaces.filter(f => winAdapter(n, f).dhcp && (option === '/release' || f.up)); if (option === '/renew') renewWindows(e, n, selected); else releaseWindows(e, n, selected); return {text: option === '/release' ? 'Windows IP Configuration\nDHCP address released.' : 'Windows IP Configuration\nRenewing DHCP lease... (가상)'}; }
        throw new Error('지원하지 않는 ipconfig 옵션입니다.');
    }
    if (command === 'ping' || command === 'tracert') {
        let target = '', count = 4, hops = 8; const seen = new Set();
        for (let i = 1; i < t.length; i++) { const key = low[i].replace(/^\//, '-'); if (key.startsWith('-')) { winNeed(!seen.has(key), '중복 옵션입니다.'); seen.add(key); if (command === 'ping' && key === '-n') count = integer(Number(t[++i]), 1, 4); else if (command === 'tracert' && key === '-h') hops = integer(Number(t[++i]), 1, 8); else winNeed(key === '-4' || (command === 'tracert' && key === '-d'), '지원하지 않는 Windows 진단 옵션입니다.'); } else { winNeed(!target); target = t[i]; } }
        winNeed(target); e.start(n, command === 'ping' ? 'ping' : 'trace', target, {windows: command === 'ping' ? 'cmd-ping' : 'cmd-trace', count, hops}); return {rows: []};
    }
    if (command === 'nslookup') { winNeed(t.length === 2 || t.length === 3); winNeed(/^[a-z0-9][a-z0-9.-]{0,100}$/i.test(t[1])); const j = e.startDNS(n, t[1], t[2] || windowsResolver(n, f => e.portUp(n, f))); j.windows = 'cmd-dns'; return {rows: []}; }
    if (command === 'arp') {
        if (low[1] === '-a' && t.length === 2) return {text: n.interfaces.map(f => { const rows = [...e.rt(n).arp.values()].filter(a => a.port === f.name && a.until > e.now); return rows.length ? `Interface: ${f.address ? cidr(f.address).address : '0.0.0.0'} --- ${n.interfaces.indexOf(f) + 1}\n  Internet Address      Physical Address      Type\n${rows.map(a => `  ${a.ip}    ${a.mac.replace(/:/g, '-')}    dynamic`).join('\n')}` : ''; }).filter(Boolean).join('\n\n') || 'No ARP Entries Found.'};
        winNeed(low[1] === '-d' && t.length === 3, 'arp -a 또는 arp -d IP|* 형식입니다.'); if (t[2] !== '*') ipv4(t[2]); for (const [key, entry] of e.rt(n).arp) if (t[2] === '*' || entry.ip === t[2]) e.rt(n).arp.delete(key); return {rows: []};
    }
    if (command === 'route') {
        const args = t.slice(1); if (['-4', '/4'].includes(args[0]?.toLowerCase())) args.shift(); const op = args[0]?.toLowerCase();
        if (op === 'print' && args.length === 1) return {text: cmdRoutes(e, n)};
        if (op === 'delete' && args.length === 2) { ipv4(args[1]); winNeed(n.routes.some(r => cidr(r.dest).address === args[1]), '삭제할 정적 경로가 없습니다.'); update(e, n, x => x.routes = x.routes.filter(r => cidr(r.dest).address !== args[1])); return {text: 'OK!'}; }
        winNeed(op === 'add' && args.length >= 5 && args[2].toLowerCase() === 'mask', 'route add 망 mask 마스크 게이트웨이 [metric N] [if N] 형식입니다. -p·영구 경로·재부팅은 미지원입니다.');
        const bits = maskPrefix(args[3]), dest = `${args[1]}/${bits}`, net = cidr(dest); winNeed(net.ip === net.network, '경로에는 네트워크 주소를 지정하세요.'); ipv4(args[4]); let metric = 0, index = 0; const flags = new Set();
        for (let i = 5; i < args.length; i += 2) { const key = args[i].toLowerCase(); winNeed(['metric', 'if'].includes(key) && !flags.has(key) && args[i + 1] !== undefined); flags.add(key); if (key === 'metric') metric = integer(Number(args[i + 1]), 0, 65535); else index = integer(Number(args[i + 1]), 1, n.interfaces.length); }
        const f = index ? n.interfaces[index - 1] : n.interfaces.filter(f => f.address && contains(f.address, args[4])).sort((a, b) => winAdapter(n, a).metric - winAdapter(n, b).metric)[0];
        winNeed(f, '게이트웨이에 연결된 인터페이스가 없습니다. if 번호를 지정하세요.'); winNeed(!n.routes.some(r => r.dest === dest && r.dev === f.name && r.via === args[4]), '경로가 이미 있습니다.'); update(e, n, x => x.routes.push({dest, via: args[4] === '0.0.0.0' ? '' : args[4], dev: f.name, metric})); return {text: 'OK!'};
    }
    if (command === 'netstat') {
        winNeed(t.slice(1).every(s => /^-[anro]+$/i.test(s)), 'netstat은 -a -n -o -r 조합만 지원합니다.'); const flags = low.slice(1).join(''); if (flags.includes('r')) return {text: cmdRoutes(e, n)}; const withPid = flags.includes('o'); const rows = windowsConnections(e, n, flags.includes('a'));
        return {text: `Active Connections (가상)\n  Proto  Local Address  Foreign Address  State${withPid ? '  PID' : ''}\n${rows.map(r => `  TCP  ${r.LocalAddress}:${r.LocalPort}  ${r.RemoteAddress}:${r.RemotePort}  ${r.State === 'Listen' ? 'LISTENING' : r.State.toUpperCase()}${withPid ? '  -' : ''}`).join('\n')}${withPid ? '\nPID는 실제 OS 프로세스가 없어 표시하지 않습니다.' : ''}`};
    }
    if (command === 'getmac') { winNeed(t.length === 1); return {text: `Physical Address     Interface\n${n.interfaces.map(f => `${f.mac.replace(/:/g, '-').toUpperCase()}    ${winAlias(n, f)}`).join('\n')}`}; }
    if (command === 'hostname') { winNeed(t.length === 1); return {text: n.name}; }
    if (command === 'ver') { winNeed(t.length === 1); return {text: 'Microsoft Windows network simulation · IPv4 학습 부분집합 (실제 Windows OS 아님)'}; }
    if (command === 'cls') { winNeed(t.length === 1); e.say(n, '', true); return {rows: []}; }
    if (command === 'echo') return {text: raw.slice(t[0].length).trimStart().replace(/%errorlevel%/gi, String(previous)) || 'ECHO is on.'};
    if (command === 'cd' || command === 'chdir') { winNeed(t.length <= 2); if (t[1]) s.cwd = files.directory(t[1]); return {text: s.cwd || 'C:\\Lab'}; }
    if (command === 'dir') { winNeed(t.length <= 2); return {text: `Directory of ${t[1] || files.cwd()}\n${files.entries(t[1]).map(f => `${f.Mode.startsWith('d') ? '<DIR>' : f.Length}  ${f.Name}`).join('\n')}`}; }
    if (command === 'type') { winNeed(t.length === 2); return {text: files.read(t[1]).replace(/\n$/, '')}; }
    return null;
}
