import {clone, cidr, contains, integer, ipv4, ipString, token, text} from './model.js?ver=1.4.0-rc.4';
import {configSnapshot, portLabel} from './device-config.js?ver=1.4.0-rc.4';
import {need, lex, expand, suggestions, maskPrefix, maskOf, subnet, wildcard, findPort, virtualPort, update, saveStartup, reboot, runningDirty, portNumber} from './cli-common.js?ver=1.4.0-rc.4';
import {ROUTING_SHOW, ROUTING_GLOBAL, routingPatterns, routingCommand, routingShow, routingConfig} from './cli-routing.js?ver=1.4.0-rc.4';

const USER = ['enable', 'show version', 'show interfaces', 'show interfaces <interface>', 'show ip interface brief', 'show ip route', 'show ip arp', 'show vlan brief', 'show mac address-table', 'ping <address>', 'traceroute <address>', 'exit'];
const PRIV = [...USER, 'disable', 'configure terminal', 'show running-config', 'show startup-config', 'show access-lists', 'show ip nat translations', 'copy running-config startup-config', 'write memory', 'reload', 'clear arp-cache', 'clear mac address-table dynamic'];
const GLOBAL = ['hostname <name>', 'interface <interface>', 'vlan <vlan>', 'no vlan <vlan>', 'ip routing', 'no ip routing', 'ip route <network> <mask> <gateway>', 'no ip route <network> <mask> <gateway>', 'ip access-list standard <name>', 'ip access-list extended <name>', 'no ip access-list standard <name>', 'no ip access-list extended <name>', 'access-list <number> permit <source>', 'access-list <number> deny <source>', 'ip nat inside source list <acl> interface <interface> overload', 'no ip nat inside source list <acl> interface <interface> overload', 'ip nat inside source static tcp <local> <port> <global> <port>', 'ip nat inside source static udp <local> <port> <global> <port>', 'exit', 'end', 'do show running-config', 'do show ip route'];
const INTERFACE = ['description <text>', 'no description', 'shutdown', 'no shutdown', 'ip address <address> <mask>', 'no ip address', 'switchport', 'no switchport', 'switchport mode access', 'switchport mode trunk', 'switchport access vlan <vlan>', 'switchport trunk native vlan <vlan>', 'switchport trunk allowed vlan <list>', 'switchport trunk allowed vlan add <list>', 'switchport trunk allowed vlan remove <list>', 'encapsulation dot1Q <vlan>', 'ip access-group <acl> in', 'ip access-group <acl> out', 'no ip access-group <acl> in', 'no ip access-group <acl> out', 'ip nat inside', 'ip nat outside', 'no ip nat inside', 'no ip nat outside', 'exit', 'end', 'do show ip interface brief'];
export function iosPatterns(s) { return ['ospf', 'bgp'].includes(s.mode) ? routingPatterns(s) : s.mode === 'user' ? [...USER, ...ROUTING_SHOW] : s.mode === 'priv' ? [...PRIV, ...ROUTING_SHOW] : s.mode === 'interface' ? [...INTERFACE, 'ip ospf cost <cost>', 'no ip ospf cost'] : s.mode === 'vlan' ? ['name <name>', 'exit', 'end'] : s.mode === 'acl' ? ['permit <source>', 'deny <source>', '<sequence> permit <source>', '<sequence> deny <source>', 'no <sequence>', 'exit', 'end'] : [...GLOBAL, ...ROUTING_GLOBAL, ...ROUTING_SHOW.map(p => 'do ' + p)]; }
export function iosPrompt(n, s) { return `${n.name}${s.mode === 'user' ? '>' : s.mode === 'priv' ? '#' : s.mode === 'global' ? '(config)#' : ['ospf', 'bgp'].includes(s.mode) ? '(config-router)#' : s.mode === 'interface' ? '(config-if)#' : s.mode === 'acl' ? '(config-std-nacl)#'.replace('std', s.aclType === 'extended' ? 'ext' : 'std') : '(config-vlan)#'}`; }
const ruleText = r => `${r.seq} ${r.action} ${r.protocol.toLowerCase()} ${r.src} ${r.dst}${r.port ? ` eq ${r.port}` : ''}`;
function configText(n) {
    const d = n.device, lines = [`hostname ${n.name}`, n.forwarding ? 'ip routing' : 'no ip routing'];
    for (const v of d.vlans.filter(v => v.id !== 1 || v.name !== 'default')) lines.push(`vlan ${v.id}`, ` name ${v.name}`, ' exit');
    const vlanEnd = lines.length;
    for (const f of n.interfaces) {
        lines.push(`interface ${portLabel(n, f)}`);
        if (f.description) lines.push(` description ${f.description}`);
        if (f.kind === 'subinterface' && f.encapsulated) lines.push(` encapsulation dot1Q ${f.vlanId}`);
        if (n.type === 'switch' && f.kind === 'ethernet' && f.switchport === false) lines.push(' no switchport');
        if (f.address) { const c = cidr(f.address); lines.push(` ip address ${c.address} ${maskOf(c.bits)}`); }
        if (n.type === 'switch' && f.kind === 'ethernet') lines.push(...(f.switchport !== false ? [` switchport mode ${f.mode}`, ` switchport ${f.mode === 'access' ? 'access vlan' : 'trunk native vlan'} ${f.vlan}`, ...(f.mode === 'trunk' ? [` switchport trunk allowed vlan ${f.trunkAll ? 'all' : f.allowedVlans.length ? f.allowedVlans.join(',') : 'none'}`] : [])] : []));
        for (const b of d.bindings.filter(b => b.port === f.name)) lines.push(` ip access-group ${b.acl} ${b.direction}`);
        if (d.inside.includes(f.name)) lines.push(' ip nat inside'); if (d.outside.includes(f.name)) lines.push(' ip nat outside');
        lines.push(f.up ? ' no shutdown' : ' shutdown', ' exit');
    }
    for (const r of n.routes) { const c = cidr(r.dest); lines.push(`ip route ${ipString(c.network)} ${maskOf(c.bits)} ${r.via || portLabel(n, n.interfaces.find(f => f.name === r.dev))}`); }
    const addr = s => { const c = cidr(s); return c.bits === 0 ? 'any' : c.bits === 32 ? `host ${c.address}` : `${ipString(c.network)} ${ipString((~cidr(`0.0.0.0/${c.bits}`).mask) >>> 0)}`; };
    const aclLines = [];
    for (const a of d.acls) { aclLines.push(`ip access-list ${a.type} ${a.name}`); for (const r of a.entries.slice().sort((a, b) => a.seq - b.seq)) aclLines.push(` ${r.seq} ${r.action} ${a.type === 'extended' ? `${r.protocol.toLowerCase()} ` : ''}${addr(r.src)}${a.type === 'extended' ? ' ' + addr(r.dst) : ''}${r.port ? ` eq ${r.port}` : ''}`); aclLines.push(' exit'); }
    lines.splice(vlanEnd, 0, ...aclLines);
    if (d.nat) lines.push(`ip nat inside source list ${d.nat.acl} interface ${portLabel(n, n.interfaces.find(f => f.name === d.nat.port))} overload`);
    for (const x of d.staticNat) lines.push(`ip nat inside source static ${x.protocol.toLowerCase()} ${x.local} ${x.localPort} ${x.global} ${x.globalPort}`);
    return [...lines, ...routingConfig(n), 'end'].join('\n');
}
function show(e, n, t, privileged) {
    const key = t.join(' '), rt = e.rt(n);
    const routed = routingShow(e, n, key); if (routed !== null) return routed;
    if (key === 'show version') return 'OJ Network Lab · IOS XE 17.12 문법 부분집합\n가상 장비 · 실제 Cisco 펌웨어가 아닙니다.';
    if (['show running-config', 'show startup-config'].includes(key)) { need(privileged, '% 권한 모드에서 실행하세요.'); return configText(key.includes('startup') ? {...n, ...n.startup} : n); }
    if (key === 'show ip interface brief') return ['Interface                  IP-Address       Status              Protocol', ...n.interfaces.map(f => `${portLabel(n, f).padEnd(26)} ${(f.address ? cidr(f.address).address : 'unassigned').padEnd(16)} ${(!f.up ? 'administratively down' : e.portUp(n, f) ? 'up' : 'down').padEnd(19)} ${e.portUp(n, f) ? 'up' : 'down'}`)].join('\n');
    if (key === 'show interfaces' || (t[1] === 'interfaces' && t.length === 3)) return n.interfaces.filter(f => t.length === 2 || findPort(n, t[2]).name === f.name).map(f => `${portLabel(n, f)} is ${!f.up ? 'administratively down' : e.portUp(n, f) ? 'up' : 'down'}, line protocol is ${e.portUp(n, f) ? 'up' : 'down'}\n  Hardware is virtual Ethernet, address is ${f.mac}\n  ${f.address ? `Internet address is ${f.address}` : 'No IPv4 address'}${f.description ? `\n  Description: ${f.description}` : ''}`).join('\n');
    if (key === 'show ip route') return ['Codes: C - connected, S - static, O - OSPF, B - BGP (시뮬레이션)', ...e.routes(n).map(r => `${r.connected ? 'C' : r.source || 'S'} ${r.dest} ${r.source ? `[${r.distance}/${r.metric}] ` : ''}${r.via ? `via ${r.via}, ` : 'is directly connected, '}${portLabel(n, n.interfaces.find(f => f.name === r.dev))}`)].join('\n');
    if (key === 'show ip arp') return [...rt.arp.values()].filter(a => a.until > e.now).map(a => `Internet ${a.ip} ${a.mac} ARPA ${portLabel(n, findPort(n, a.port))}`).join('\n') || 'ARP 테이블이 비어 있습니다.';
    if (key === 'show vlan brief') { need(n.type === 'switch'); return ['VLAN Name                             Status   Ports', ...n.device.vlans.map(v => `${v.id} ${v.name.padEnd(32)} active   ${n.interfaces.filter(f => f.kind === 'ethernet' && f.switchport !== false && f.mode === 'access' && f.vlan === v.id).map(f => portLabel(n, f)).join(', ')}`)].join('\n'); }
    if (key === 'show mac address-table') { need(n.type === 'switch'); return [...rt.fdb.values()].filter(f => f.until > e.now).map(f => `${f.vlan} ${f.mac} DYNAMIC ${portLabel(n, findPort(n, f.port))}`).join('\n') || 'MAC 주소 테이블이 비어 있습니다.'; }
    if (key === 'show access-lists') { need(privileged); return n.device.acls.map(a => `${a.type} IP access list ${a.name}\n${a.entries.map(r => ` ${ruleText(r)} (${rt.hits.get(`acl:${a.name}:${r.seq}`) || 0} matches)`).join('\n')}`).join('\n') || '설정된 ACL이 없습니다.'; }
    if (key === 'show ip nat translations') { need(privileged); return rt.nat.filter(b => b.until > e.now).map(b => `${b.before.protocol} ${b.before.src}:${b.before.sport} → ${b.after.src}:${b.after.sport} dst ${b.before.dst}:${b.before.dport}`).join('\n') || '활성 NAT 변환이 없습니다.'; }
    throw new Error('% Invalid input detected. 지원하는 show 명령은 show ?로 확인하세요.');
}
function addressArgs(t, index) { if (t[index] === 'any') return ['0.0.0.0/0', index + 1]; if (t[index] === 'host') { ipv4(t[index + 1]); return [t[index + 1] + '/32', index + 2]; } return [wildcard(t[index], t[index + 1]), index + 2]; }
function aclEntry(acl, raw) {
    const t = [...raw]; let seq = /^\d+$/.test(t[0]) ? Number(t.shift()) : (Math.max(0, ...acl.entries.map(r => r.seq)) + 10);
    integer(seq, 1, 2147483647); const action = t.shift(); need(['permit', 'deny'].includes(action));
    const protocol = acl.type === 'extended' ? (t.shift() || '').toUpperCase() : 'IP'; need(['IP', 'ICMP', 'TCP', 'UDP', 'OSPF'].includes(protocol));
    let src, dst = '0.0.0.0/0', i; [src, i] = addressArgs(t, 0); if (acl.type === 'extended') [dst, i] = addressArgs(t, i);
    let port = 0; if (t[i] === 'eq' && ['TCP', 'UDP'].includes(protocol)) { port = portNumber(t[i + 1]); i += 2; } need(i === t.length);
    return {seq, action, protocol, src, dst, port};
}
function vlanList(value) { const result = new Set(); for (const chunk of value.split(',')) { const [a, b = a] = chunk.split('-').map(Number); integer(a, 1, 4094); integer(b, a, 4094); need(b - a < 64, '한 번에 VLAN 64개까지 지원합니다.'); for (let v = a; v <= b; v++) result.add(v); } need(result.size <= 64); return [...result].sort((a, b) => a - b); }
export function iosCommand(e, n, line, s) {
    if (s.pending) {
        const answer = line.trim().toLowerCase();
        if (s.pending === 'save') { need(['yes', 'no'].includes(answer), 'yes 또는 no로 답하세요.'); if (answer === 'yes') saveStartup(e, n); s.pending = 'reload'; return 'Proceed with reload? [confirm]'; }
        if (s.pending === 'reload') { s.pending = ''; if (answer === '' || answer === 'yes') { reboot(e, n); return '장비가 시작 설정으로 재부팅되었습니다.'; } return 'Reload cancelled.'; }
    }
    if (line.trim() === 'help' || line.trim().endsWith('?')) return iosHelp(n, s, line.trim() === 'help' ? '' : line.trim().slice(0, -1));
    const original = lex(line); if (!original.length || original[0] === '!') return '';
    const t = expand(original, iosPatterns(s)), key = t.join(' '), priv = s.mode !== 'user';
    if (key === 'end' && !['user', 'priv'].includes(s.mode)) { s.mode = 'priv'; return ''; }
    if (key === 'exit') { s.mode = ['interface', 'vlan', 'acl', 'ospf', 'bgp'].includes(s.mode) ? 'global' : s.mode === 'global' ? 'priv' : 'user'; return ''; }
    if (t[0] === 'do' && !['user', 'priv'].includes(s.mode)) return show(e, n, t.slice(1), true);
    const routed = routingCommand(e, n, t, s); if (routed !== null) return routed;
    if (['user', 'priv'].includes(s.mode)) {
        if (key === 'enable') { s.mode = 'priv'; return ''; }
        if (key === 'disable' && priv) { s.mode = 'user'; return ''; }
        if (t[0] === 'show') return show(e, n, t, priv);
        if (t.length === 2 && ['ping', 'traceroute'].includes(t[0])) { e.start(n, t[0] === 'ping' ? 'ping' : 'trace', t[1], {count: 1}); return ''; }
        need(priv, '% 이 명령은 enable 후 실행하세요.');
        if (key === 'configure terminal') { s.mode = 'global'; return ''; }
        if (key === 'copy running-config startup-config' || key === 'write memory') { saveStartup(e, n); return 'Building configuration...\n[OK]'; }
        if (key === 'reload') { s.pending = runningDirty(n) ? 'save' : 'reload'; return s.pending === 'save' ? 'System configuration has been modified. Save? [yes/no]:' : 'Proceed with reload? [confirm]'; }
        if (key === 'clear arp-cache') { e.rt(n).arp.clear(); return ''; }
        if (key === 'clear mac address-table dynamic') { e.rt(n).fdb.clear(); return ''; }
    } else if (s.mode === 'global') {
        if (t[0] === 'hostname' && t.length === 2) { need(/^[A-Za-z][A-Za-z0-9_-]{0,31}$/.test(t[1])); update(e, n, x => x.name = t[1]); return ''; }
        if (t[0] === 'interface' && t.length === 2) {
            let f; try { f = findPort(n, t[1]); } catch {
                const svi = /^vlan(\d+)$/i.exec(t[1]), sub = /^(gi(?:gabitethernet)?\d+\/\d+(?:\/\d+)?)\.(\d+)$/i.exec(t[1]);
                if (svi && n.type === 'switch') { const id = integer(Number(svi[1]), 1, 4094); const next = update(e, n, x => x.interfaces.push(virtualPort(x, `vlan${id}`, 'vlan', {vlanId: id}))); f = findPort(next, `vlan${id}`); }
                else if (sub && n.type === 'router') { const parent = findPort(n, sub[1]), unit = integer(Number(sub[2]), 1, 4094); const name = `${parent.name}_${unit}`; const next = update(e, n, x => x.interfaces.push(virtualPort(x, name, 'subinterface', {parent: parent.name, unit, vlanId: unit}))); f = findPort(next, name); }
                else throw new Error('% 인터페이스가 없습니다.');
            }
            s.mode = 'interface'; s.port = f.name; return '';
        }
        const no = t[0] === 'no', a = no ? t.slice(1) : t;
        if (a[0] === 'vlan' && a.length === 2) { need(n.type === 'switch'); const id = integer(Number(a[1]), 1, 4094); update(e, n, x => { if (no) { need(id !== 1, '기본 VLAN 1은 삭제할 수 없습니다.'); x.device.vlans = x.device.vlans.filter(v => v.id !== id); } else if (!x.device.vlans.some(v => v.id === id)) x.device.vlans.push({id, name: `VLAN${id}`}); }); if (!no) { s.mode = 'vlan'; s.vlan = id; } return ''; }
        if (a.join(' ') === 'ip routing') { update(e, n, x => x.forwarding = !no); return ''; }
        if (a[0] === 'ip' && a[1] === 'route' && a.length === 5) {
            const dest = subnet(a[2], a[3]); let via = '', dev = ''; try { ipv4(a[4]); via = a[4]; } catch { dev = findPort(n, a[4]).name; }
            update(e, n, x => { x.routes = x.routes.filter(r => !(r.dest === dest && r.via === via && r.dev === dev)); if (!no) x.routes.push({dest, via, dev, metric: 1}); }); return '';
        }
        if (a[0] === 'ip' && a[1] === 'access-list' && a.length === 4) {
            need(['standard', 'extended'].includes(a[2])); const name = /^\d+$/.test(a[3]) ? String(integer(Number(a[3]), a[2] === 'standard' ? 1 : 100, a[2] === 'standard' ? 99 : 199)) : token(a[3]);
            update(e, n, x => { if (no) x.device.acls = x.device.acls.filter(z => z.name !== name); else { const old = x.device.acls.find(z => z.name === name); need(!old || old.type === a[2]); if (!old) x.device.acls.push({name, type: a[2], entries: []}); } });
            if (!no) { s.mode = 'acl'; s.acl = name; s.aclType = a[2]; } return '';
        }
        if (!no && a[0] === 'access-list' && a.length >= 4) { const id = integer(Number(a[1]), 1, 199); need(id <= 99 || id >= 100); const name = a[1], type = id < 100 ? 'standard' : 'extended'; update(e, n, x => { let acl = x.device.acls.find(a => a.name === name); if (!acl) x.device.acls.push(acl = {name, type, entries: []}); const r = aclEntry(acl, a.slice(2)); acl.entries = [...acl.entries.filter(z => z.seq !== r.seq), r]; }); return ''; }
        if (a.slice(0, 5).join(' ') === 'ip nat inside source list') { need(a.length === 9 && a[6] === 'interface' && a[8] === 'overload'); const port = findPort(n, a[7]); update(e, n, x => x.device.nat = no ? null : {acl: a[5], port: port.name}); return ''; }
        if (!no && a.slice(0, 5).join(' ') === 'ip nat inside source static') { need(a.length === 10 && ['tcp', 'udp'].includes(a[5])); ipv4(a[6]); ipv4(a[8]); const rule = {protocol: a[5].toUpperCase(), local: a[6], localPort: portNumber(a[7]), global: a[8], globalPort: portNumber(a[9])}; update(e, n, x => { x.device.staticNat = x.device.staticNat.filter(r => !(r.protocol === rule.protocol && r.global === rule.global && r.globalPort === rule.globalPort)); x.device.staticNat.push(rule); }); return ''; }
    } else if (s.mode === 'interface') {
        const no = t[0] === 'no', a = no ? t.slice(1) : t; const f = findPort(n, s.port);
        if (a.join(' ') === 'shutdown') { update(e, n, x => findPort(x, s.port).up = no); return ''; }
        if (a[0] === 'description' && (no || a.length >= 2)) { const value = no ? '' : text(a.slice(1).join(' '), 80); update(e, n, x => findPort(x, s.port).description = value); return ''; }
        if (a[0] === 'ip' && a[1] === 'address') { need((no && a.length === 2) || (!no && a.length === 4)); need(!(n.type === 'switch' && f.kind === 'ethernet' && f.switchport !== false), '% 먼저 no switchport 또는 interface Vlan을 사용하세요.'); const value = no ? '' : `${(ipv4(a[2]), a[2])}/${maskPrefix(a[3])}`; update(e, n, x => findPort(x, s.port).address = value); return ''; }
        if (a[0] === 'encapsulation' && a[1]?.toLowerCase() === 'dot1q' && a.length === 3 && !no) { need(f.kind === 'subinterface'); const id = integer(Number(a[2]), 1, 4094); update(e, n, x => { const p = findPort(x, s.port); p.vlanId = id; p.encapsulated = true; }); return ''; }
        if (a[0] === 'switchport') {
            need(n.type === 'switch' && f.kind === 'ethernet');
            update(e, n, x => { const p = findPort(x, s.port); if (a.length === 1) { p.switchport = !no; if (!no) p.address = ''; }
                else { need(!no && p.switchport !== false); if (a[1] === 'mode' && a.length === 3) { need(['access', 'trunk'].includes(a[2])); p.mode = a[2]; }
                    else if (a[1] === 'access' && a[2] === 'vlan' && a.length === 4) { p.vlan = integer(Number(a[3]), 1, 4094); if (!x.device.vlans.some(v => v.id === p.vlan)) x.device.vlans.push({id: p.vlan, name: `VLAN${p.vlan}`}); }
                    else if (a.slice(1, 4).join(' ') === 'trunk native vlan' && a.length === 5) p.vlan = integer(Number(a[4]), 1, 4094);
                    else if (a.slice(1, 4).join(' ') === 'trunk allowed vlan') { const op = ['add', 'remove'].includes(a[4]) ? a[4] : 'replace', value = a[op === 'replace' ? 4 : 5]; need(a.length === (op === 'replace' ? 5 : 6)); if (value === 'all' && op === 'replace') p.trunkAll = true; else { need(!p.trunkAll || op === 'replace', 'all 상태에서는 먼저 명시적인 VLAN 목록을 지정하세요.'); const ids = value === 'none' && op === 'replace' ? [] : vlanList(value); p.trunkAll = false; p.allowedVlans = op === 'replace' ? ids : op === 'add' ? [...new Set([...p.allowedVlans, ...ids])] : p.allowedVlans.filter(v => !ids.includes(v)); } }
                    else need(false);
                } }); return '';
        }
        if (a[0] === 'ip' && a[1] === 'access-group' && a.length === 4) { need(['in', 'out'].includes(a[3])); update(e, n, x => { x.device.bindings = x.device.bindings.filter(b => !(b.port === s.port && b.direction === a[3])); if (!no) x.device.bindings.push({port: s.port, acl: a[2], direction: a[3]}); }); return ''; }
        if (a[0] === 'ip' && a[1] === 'nat' && a.length === 3 && ['inside', 'outside'].includes(a[2])) { update(e, n, x => { x.device[a[2]] = x.device[a[2]].filter(p => p !== s.port); if (!no) { x.device[a[2] === 'inside' ? 'outside' : 'inside'] = x.device[a[2] === 'inside' ? 'outside' : 'inside'].filter(p => p !== s.port); x.device[a[2]].push(s.port); } }); return ''; }
    } else if (s.mode === 'vlan') { if (t[0] === 'name' && t.length >= 2) { const name = text(t.slice(1).join(' '), 32); update(e, n, x => x.device.vlans.find(v => v.id === s.vlan).name = name); return ''; } }
    else if (s.mode === 'acl') {
        if (t[0] === 'no' && t.length === 2) { const seq = integer(Number(t[1]), 1, 2147483647); update(e, n, x => x.device.acls.find(a => a.name === s.acl).entries = x.device.acls.find(a => a.name === s.acl).entries.filter(r => r.seq !== seq)); return ''; }
        update(e, n, x => { const a = x.device.acls.find(a => a.name === s.acl); const r = aclEntry(a, t); a.entries = [...a.entries.filter(z => z.seq !== r.seq), r]; }); return '';
    }
    throw new Error('% Invalid input detected. 현재 모드의 ?를 확인하세요.');
}
export function iosHelp(n, s, line = '') { const result = suggestions(n, line, iosPatterns(s)); return `IOS XE 학습 프로필 · 현재 모드: ${s.mode}\n${result.choices.join('\n') || '(지원하는 문법 없음)'}\n전체 범위는 장비 명령 안내를 확인하세요.`; }
export {configText as iosConfig};
