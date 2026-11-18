// Configuration only. Console modes, counters and sessions never enter saved projects.
import {windowsDefaults, winAlias} from './windows-config.js?ver=1.4.0-rc.4';
export const PROFILES = Object.freeze({linux: 'Linux', windows: 'Windows CMD / PowerShell 학습 프로필', ios: 'IOS XE 학습 프로필', fortios: 'FortiOS 학습 프로필'});
export const copy = value => JSON.parse(JSON.stringify(value));
export const CONFIG_KEYS = ['name', 'forwarding', 'forwarding6', 'resolver', 'interfaces', 'routes', 'routes6', 'ospf', 'bgp', 'tables', 'chains', 'rules', 'services', 'device', 'windows'];
export function configSnapshot(node) { return Object.fromEntries(CONFIG_KEYS.filter(k => node[k] !== undefined).map(k => [k, copy(node[k])])); }
export function deviceDefaults() {
    return {vlans: [{id: 1, name: 'default'}], acls: [], bindings: [], inside: [], outside: [], nat: null, staticNat: [],
        addresses: [], services: [], policies: [], phase1: [], phase2: [], saveMode: 'automatic'};
}
export function normalizeDevice(raw, node, v) {
    const {array, integer, text, oneOf, cidr, ipv4, token} = v, d = {...deviceDefaults(), ...(raw || {})};
    const name = s => { text(s, 40); if (!/^[A-Za-z0-9_][A-Za-z0-9_. -]*$/.test(s)) throw new Error('장비 객체 이름을 확인하세요.'); return s; };
    const port = s => { if (!node.interfaces.some(f => f.name === s)) throw new Error('장비 설정의 인터페이스가 없습니다.'); return s; };
    const net = s => (cidr(s), s);
    const unique = (a, key) => { if (new Set(a.map(key)).size !== a.length) throw new Error('중복 장비 설정입니다.'); return a; };
    const out = {vlans: unique(array(d.vlans, 64).map(x => ({id: integer(x.id, 1, 4094), name: text(x.name, 32)})), x => x.id)};
    out.acls = unique(array(d.acls, 16).map(a => ({name: name(a.name), type: oneOf(a.type, ['standard', 'extended']), entries: unique(array(a.entries, 64).map(r => ({seq: integer(r.seq, 1, 2147483647), action: oneOf(r.action, ['permit', 'deny']), protocol: oneOf(r.protocol, ['IP', 'ICMP', 'TCP', 'UDP', 'OSPF']), src: net(r.src), dst: net(r.dst), port: integer(r.port || 0, 0, 65535)})), r => r.seq)})), a => a.name);
    const acl = s => { if (!out.acls.some(a => a.name === s)) throw new Error('참조한 ACL이 없습니다.'); return s; };
    out.bindings = unique(array(d.bindings, 32).map(b => ({port: port(b.port), acl: acl(b.acl), direction: oneOf(b.direction, ['in', 'out'])})), b => b.port + '/' + b.direction);
    out.inside = [...new Set(array(d.inside, 10).map(port))]; out.outside = [...new Set(array(d.outside, 10).map(port))];
    if (out.inside.some(p => out.outside.includes(p))) throw new Error('NAT inside와 outside는 구분해야 합니다.');
    out.nat = d.nat ? {acl: acl(d.nat.acl), port: port(d.nat.port)} : null;
    out.staticNat = unique(array(d.staticNat, 16).map(x => ({protocol: oneOf(x.protocol, ['TCP', 'UDP']), local: (ipv4(x.local), x.local), localPort: integer(x.localPort, 1, 65535), global: (ipv4(x.global), x.global), globalPort: integer(x.globalPort, 1, 65535)})), x => `${x.protocol}/${x.global}/${x.globalPort}`);
    out.addresses = unique(array(d.addresses, 32).map(x => ({name: name(x.name), subnet: net(x.subnet)})), x => x.name);
    out.services = unique(array(d.services, 32).map(x => ({name: name(x.name), protocol: oneOf(x.protocol, ['TCP', 'UDP']), from: integer(x.from, 1, 65535), to: integer(x.to, x.from, 65535)})), x => x.name);
    const builtin = ['ALL', 'PING', 'HTTP', 'HTTPS', 'DNS', 'SSH'];
    out.policies = unique(array(d.policies, 64).map(p => ({id: integer(p.id, 1, 2147483647), name: text(p.name || '', 40), srcintf: p.srcintf === 'any' ? 'any' : port(p.srcintf), dstintf: p.dstintf === 'any' ? 'any' : port(p.dstintf),
        srcaddr: array(p.srcaddr, 16).map(s => { if (s !== 'all' && !out.addresses.some(a => a.name === s)) throw new Error('출발 주소 객체가 없습니다.'); return s; }),
        dstaddr: array(p.dstaddr, 16).map(s => { if (s !== 'all' && !out.addresses.some(a => a.name === s)) throw new Error('도착 주소 객체가 없습니다.'); return s; }),
        service: array(p.service, 16).map(s => { if (!builtin.includes(s) && !out.services.some(a => a.name === s)) throw new Error('서비스 객체가 없습니다.'); return s; }),
        action: oneOf(p.action, ['accept', 'deny']), nat: p.nat === true, status: p.status !== false, logtraffic: p.logtraffic === true})), p => p.id);
    if (out.policies.some(p => !p.srcaddr.length || !p.dstaddr.length || !p.service.length)) throw new Error('정책의 주소와 서비스가 필요합니다.');
    out.phase1 = unique(array(d.phase1, 4).map(p => ({name: token(p.name), interface: port(p.interface), remote: (ipv4(p.remote), p.remote), psk: text(p.psk, 64), proposal: oneOf(p.proposal, ['aes256-sha256', 'aes128-sha256']), dhgrp: oneOf(p.dhgrp, [14, 19]), ikeversion: oneOf(p.ikeversion, [2]), lifetime: integer(p.lifetime || 28800, 60, 86400)})), p => p.name);
    out.phase2 = unique(array(d.phase2, 8).map(p => ({name: token(p.name), phase1: token(p.phase1), src: net(p.src), dst: net(p.dst), proposal: oneOf(p.proposal, ['aes256-sha256', 'aes128-sha256']), pfs: p.pfs !== false, lifetime: integer(p.lifetime || 3600, 60, 86400)})), p => p.name);
    if (new Set(out.phase1.map(p => `${p.interface}/${p.remote}`)).size !== out.phase1.length || new Set(out.phase2.map(p => p.phase1)).size !== out.phase2.length) throw new Error('IPsec 학습 프로필은 피어당 터널 하나·선택자 한 쌍을 지원합니다.');
    if (out.phase1.some(p => !node.interfaces.some(f => f.kind === 'ipsec' && f.name === p.name))) throw new Error('phase1에 대응하는 IPsec 인터페이스가 없습니다.');
    if (out.phase2.some(p => !out.phase1.some(x => x.name === p.phase1))) throw new Error('phase2가 참조한 phase1이 없습니다.');
    out.saveMode = oneOf(d.saveMode, ['automatic', 'manual']);
    return out;
}
export function assignProfile(node, profile) {
    if (!Object.hasOwn(PROFILES, profile) || (profile === 'windows' && !['pc', 'server'].includes(node.type)) || (profile === 'ios' && !['router', 'switch'].includes(node.type)) || (profile === 'fortios' && node.type !== 'firewall')) throw new Error('장비 종류에 맞는 CLI 프로필을 선택하세요.');
    delete node.windows;
    node.profile = profile; node.device = deviceDefaults(); node.startup = null;
    if (profile !== 'linux') { node.forwarding6 = false; node.routes6 = []; for (const f of node.interfaces) f.ipv6 = []; }
    if (profile !== 'ios') { node.ospf = null; node.bgp = null; }
    node.interfaces = node.interfaces.filter(f => f.kind === 'ethernet');
    for (const f of node.interfaces) { delete f.switchport; delete f.trunkAll; delete f.allowPing; }
    node.routes = node.routes.filter(r => !r.dev || node.interfaces.some(f => f.name === r.dev));
    if (profile !== 'linux') {
        node.name = node.name.replace(/[^A-Za-z0-9_-]/g, '-').slice(0, 32) || 'Device';
        if (profile === 'windows' && !/^[A-Za-z]/.test(node.name)) node.name = ('WIN-' + node.name).slice(0, 32);
        node.tables = []; node.chains = []; node.rules = [];
        if (profile === 'ios') for (const f of node.interfaces) { if (node.type === 'switch' && f.kind === 'ethernet') { f.switchport = true; f.trunkAll = true; } for (const id of [f.vlan, ...f.allowedVlans]) if (!node.device.vlans.some(v => v.id === id) && node.device.vlans.length < 64) node.device.vlans.push({id, name: `VLAN${id}`}); }
        if (profile === 'windows') { delete node.device; node.forwarding = false; node.windows = windowsDefaults(node); }
        else node.startup = configSnapshot(node);
    }
    return node;
}
export function portLabel(node, f) {
    if (node.profile === 'linux') return f.name;
    if (node.profile === 'windows') return winAlias(node, f);
    if (f.kind === 'vlan') return `Vlan${f.vlanId}`;
    if (f.kind === 'subinterface') return `${portLabel(node, node.interfaces.find(p => p.name === f.parent))}.${f.unit}`;
    if (f.kind === 'ipsec') return f.name;
    const i = node.interfaces.filter(p => p.kind === 'ethernet').findIndex(p => p.name === f.name);
    return node.profile === 'fortios' ? `port${i + 1}` : node.type === 'switch' ? `GigabitEthernet1/0/${i + 1}` : `GigabitEthernet0/${i}`;
}
