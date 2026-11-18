// Windows host configuration. Shell, current directory and transient jobs are runtime state.
export const WIN_PROFILES = ['Domain', 'Private', 'Public'];
export const winAlias = (n, f) => { const i = n.interfaces.findIndex(p => p.name === f.name); return i ? `Ethernet ${i + 1}` : 'Ethernet'; };
export const winAdapter = (n, f) => n.windows.adapters.find(a => a.name === f.name);
export const windowsResolver = (n, operational = f => f.up) => n.windows.adapters.filter(a => a.dns && n.interfaces.some(f => f.name === a.name && f.address && operational(f))).sort((a, b) => a.metric - b.metric)[0]?.dns || '';
export function windowsDefaults(n) {
    return {adapters: n.interfaces.map(f => ({name: f.name, dhcp: !f.address, category: 'Public', metric: 25, dns: n.resolver || '', dnsAutomatic: !n.resolver, leaseDns: ''})),
        profiles: WIN_PROFILES.map(name => ({name, enabled: true, inbound: 'Block', outbound: 'Allow'})),
        rules: [{name: 'CoreNet-DHCP-In', displayName: 'DHCP Client (UDP-In)', enabled: true, direction: 'Inbound', action: 'Allow', profiles: ['Any'], protocol: 'UDP', localAddress: ['Any'], remoteAddress: ['Any'], localPort: ['68'], remotePort: ['67'], icmpType: ['Any'], interfaces: ['Any']}]};
}
export function normalizeWindows(raw, n, v) {
    const {array, integer, text, oneOf, ipv4, cidr} = v, d = raw || windowsDefaults(n);
    const unique = (a, key) => { if (new Set(a.map(key)).size !== a.length) throw new Error('중복 Windows 설정입니다.'); return a; };
    const bool = x => { if (typeof x !== 'boolean') throw new Error('Windows 설정은 true/false가 필요합니다.'); return x; };
    const named = (value, max) => { text(value, max); if (!value.trim() || /[\r\n]/.test(value)) throw new Error('Windows 이름을 확인하세요.'); return value; };
    const port = value => { if (!n.interfaces.some(f => f.name === value)) throw new Error('Windows 인터페이스가 없습니다.'); return value; };
    const list = (value, check, max = 16) => { const out = [...new Set(array(value, max).map(check))]; if (!out.length || (out.length > 1 && out.includes('Any'))) throw new Error('Windows 조건 목록을 확인하세요.'); return out; };
    const address = s => { if (s === 'Any' || s === 'LocalSubnet') return s; if (typeof s !== 'string') throw new Error('Windows 주소 조건을 확인하세요.'); if (s.includes('/')) cidr(s); else ipv4(s); return s; };
    const ports = s => { if (s === 'Any') return s; if (typeof s !== 'string' || !/^\d+(?:-\d+)?$/.test(s)) throw new Error('포트는 Any·숫자·시작-끝 형식입니다.'); const [a, b = a] = s.split('-').map(Number); integer(a, 1, 65535); integer(b, a, 65535); return s; };
    const ip = s => s ? (ipv4(s), s) : '';
    const adapters = unique(array(d.adapters, 10).map(a => ({name: port(a.name), dhcp: bool(a.dhcp), category: oneOf(a.category, ['Public', 'Private']), metric: integer(a.metric, 0, 65535), dns: ip(a.dns), dnsAutomatic: bool(a.dnsAutomatic ?? a.dhcp), leaseDns: ip(a.leaseDns)})), a => a.name);
    if (adapters.length !== n.interfaces.length) throw new Error('각 Windows 인터페이스의 설정이 필요합니다.');
    const profiles = unique(array(d.profiles, 3).map(p => ({name: oneOf(p.name, WIN_PROFILES), enabled: bool(p.enabled), inbound: oneOf(p.inbound, ['Allow', 'Block']), outbound: oneOf(p.outbound, ['Allow', 'Block'])})), p => p.name);
    if (profiles.length !== 3) throw new Error('Windows 방화벽 프로필 3개가 필요합니다.');
    const rules = unique(array(d.rules, 64).map(r => {
        const rule = {name: named(r.name, 64), displayName: named(r.displayName, 80), enabled: bool(r.enabled), direction: oneOf(r.direction, ['Inbound', 'Outbound']), action: oneOf(r.action, ['Allow', 'Block']), profiles: list(r.profiles, p => oneOf(p, ['Any', ...WIN_PROFILES]), 3), protocol: oneOf(r.protocol, ['Any', 'TCP', 'UDP', 'ICMPv4']),
            localAddress: list(r.localAddress, address), remoteAddress: list(r.remoteAddress, address), localPort: list(r.localPort, ports), remotePort: list(r.remotePort, ports), icmpType: list(r.icmpType, s => s === 'Any' ? s : String(integer(Number(s), 0, 255))), interfaces: list(r.interfaces, s => s === 'Any' ? s : port(s), 10)};
        if (!['TCP', 'UDP'].includes(rule.protocol) && (rule.localPort[0] !== 'Any' || rule.remotePort[0] !== 'Any')) throw new Error('포트 조건은 TCP·UDP에서만 지원합니다.');
        if (rule.protocol !== 'ICMPv4' && rule.icmpType[0] !== 'Any') throw new Error('ICMP 형식 조건은 ICMPv4에서만 지원합니다.');
        return rule;
    }), r => r.name.toLowerCase());
    return {adapters, profiles, rules};
}
