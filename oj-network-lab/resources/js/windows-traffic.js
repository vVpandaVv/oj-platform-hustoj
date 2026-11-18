import {contains, cidr} from './model.js?ver=1.4.0-rc.4';
import {winAdapter, winAlias} from './windows-config.js?ver=1.4.0-rc.4';
import {formatWindows} from './windows-format.js?ver=1.4.0-rc.4';
const tuple = p => `${p.protocol}/${p.src}/${p.sport || 0}/${p.dst}/${p.dport || 0}`;
const back = p => tuple({...p, src: p.dst, dst: p.src, sport: p.dport, dport: p.sport});
export function windowsFirewall(e, n, hook, p, ingress, egress) {
    const f = n.interfaces.find(f => f.name === (hook === 'input' ? ingress : egress)), category = f ? winAdapter(n, f).category : 'Public', profile = n.windows.profiles.find(p => p.name === category), rt = e.rt(n);
    if (hook === 'forward') { e.log(n, 'drop', p, 'Windows 호스트 라우팅은 미지원입니다.'); return false; }
    const outbound = hook === 'output', direction = outbound ? 'Outbound' : 'Inbound';
    const addr = (values, ip) => values.some(v => v === 'Any' || (v === 'LocalSubnet' ? n.interfaces.some(i => i.address && contains(i.address, ip)) : contains(v.includes('/') ? v : `${v}/32`, ip)));
    const port = (values, value) => values.some(v => { if (v === 'Any') return true; const [a, b = a] = v.split('-').map(Number); return value >= a && value <= b; });
    const type = p.icmp === 'echo' ? 8 : p.icmp === 'reply' ? 0 : p.reason === 'time-exceeded' ? 11 : 3;
    const matches = r => r.enabled && r.direction === direction && (r.profiles.includes('Any') || r.profiles.includes(category)) && (r.interfaces.includes('Any') || r.interfaces.includes(f?.name)) && (r.protocol === 'Any' || (r.protocol === 'ICMPv4' ? p.protocol === 'ICMP' : p.protocol === r.protocol)) && addr(r.localAddress, outbound ? p.src : p.dst) && addr(r.remoteAddress, outbound ? p.dst : p.src) && port(r.localPort, outbound ? p.sport : p.dport) && port(r.remotePort, outbound ? p.dport : p.sport) && (r.icmpType.includes('Any') || r.icmpType.includes(String(type)));
    const deny = profile.enabled && n.windows.rules.find(r => r.action === 'Block' && matches(r)), allow = profile.enabled && n.windows.rules.find(r => r.action === 'Allow' && matches(r));
    const permitted = entry => entry?.winAllowed && entry.until > e.now;
    const related = p.icmp === 'error' && p.quoted && (permitted(rt.conn.get(tuple(p.quoted))) || permitted(rt.conn.get(back(p.quoted))));
    const established = permitted(rt.conn.get(tuple(p))) || permitted(rt.conn.get(back(p))) || related;
    const allowed = !profile.enabled || (!deny && (established || !!allow || profile[outbound ? 'outbound' : 'inbound'] === 'Allow'));
    const rule = deny || (!established && allow);
    if (rule) rt.hits.set(`windows:${rule.name}`, (rt.hits.get(`windows:${rule.name}`) || 0) + 1);
    const reason = !profile.enabled ? 'disabled' : deny ? `Block ${deny.name}` : established ? 'stateful reply/connection' : allow ? `Allow ${allow.name}` : `default ${profile[outbound ? 'outbound' : 'inbound']}`;
    e.log(n, allowed ? 'allow' : 'drop', p, `Windows Firewall ${category} ${direction}: ${reason}`, f?.name || '');
    if (allowed) { const own = rt.conn.get(tuple(p)); if (own) own.winAllowed = true; }
    return allowed;
}
export function windowsJobHeader(type, dest, options) {
    if (!options?.windows) return null;
    if (options.windows === 'cmd-ping') return `Pinging ${dest} with 32 bytes of data (가상):`;
    if (options.windows === 'cmd-trace') return `Tracing route to ${dest} over a maximum of ${options.hops || 8} hops (가상, 홉당 1회):`;
    return '';
}
export function finishWindowsJob(e, n, j) {
    if (j.type === 'dns' && !j.value && j.after?.options?.windows) {
        const options = j.after.options, mode = options.windows;
        e.say(n, mode === 'ps-test' ? options.quiet ? 'False' : formatWindows([{ComputerName: options.computer || j.name, RemoteAddress: '', ...(j.after.type === 'tcp' ? {RemotePort: options.port, TcpTestSucceeded: false} : {PingSucceeded: false})}], true) : `Unable to resolve target name ${j.name}.`); return true;
    }
    const mode = j.options?.windows || j.windows;
    if (!mode) return false;
    let output;
    if (mode === 'cmd-ping') {
        const lost = j.count - j.results.length;
        output = [...j.results.map(r => `Reply from ${r.ip}: bytes=32 time=${r.time}ms TTL=${r.ttl || 64} (가상)`), ...j.errors, ...Array.from({length: lost}, () => 'Request timed out.'), `\nPing statistics for ${j.dest}:`, `    Packets: Sent = ${j.count}, Received = ${j.results.length}, Lost = ${lost} (${Math.round(lost / j.count * 100)}% loss)`].join('\n');
    } else if (mode === 'cmd-trace') {
        const end = j.results.find(r => r.ok)?.probe || j.count;
        output = Array.from({length: end}, (_, i) => { const r = j.results.find(r => r.probe === i + 1); return `${i + 1}  ${r ? `${r.time} ms  ${r.ip}` : '*  Request timed out.'}`; }).join('\n') + '\nTrace complete. (가상)';
    } else if (mode === 'cmd-dns') output = `Server: ${j.dest}\nAddress: ${j.dest}\n\n${j.value ? `Name: ${j.name}\nAddress: ${j.value}` : j.answered ? `Non-existent domain: ${j.name}` : 'DNS request timed out.'}`;
    else if (mode === 'ps-dns') output = j.value ? formatWindows([{Name: j.name, Type: 'A', TTL: 60, IPAddress: j.value}]) : j.answered ? `Resolve-DnsName: ${j.name} : DNS name does not exist` : `Resolve-DnsName: ${j.name} : DNS request timed out`;
    else if (mode === 'ps-test') {
        const success = j.type === 'tcp' ? j.connected === true : j.results.some(r => r.ok), route = e.route(n, j.dest), f = n.interfaces.find(f => f.name === route?.dev);
        const value = {ComputerName: j.options.computer || j.dest, RemoteAddress: j.dest, ...(j.type === 'tcp' ? {RemotePort: j.port} : {}), InterfaceAlias: j.dest === '127.0.0.1' ? 'Loopback' : f ? winAlias(n, f) : '', SourceAddress: j.dest === '127.0.0.1' ? '127.0.0.1' : f?.address ? cidr(f.address).address : '', [j.type === 'tcp' ? 'TcpTestSucceeded' : 'PingSucceeded']: success, ...(j.type === 'trace' ? {TraceRoute: j.results.map(r => r.ip)} : {})};
        output = j.options.quiet ? success ? 'True' : 'False' : formatWindows([value], true);
    } else if (mode === 'cmd-dhcp') output = j.value ? `Windows IP Configuration\nDHCP lease acquired: ${j.value}` : 'An error occurred while renewing: unable to contact your DHCP server.';
    else return false;
    e.say(n, output); return true;
}
