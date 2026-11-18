import {cidr, ipv4, ipString, integer} from './model.js?ver=1.4.0-rc.4';
import {need, update, wildcard, subnet, maskOf, findPort} from './cli-common.js?ver=1.4.0-rc.4';
import {portLabel} from './device-config.js?ver=1.4.0-rc.4';
import {ospfPort} from './routing.js?ver=1.4.0-rc.4';

export const ROUTING_SHOW = ['show ip ospf neighbor', 'show ip ospf database', 'show ip ospf interface', 'show ip bgp summary', 'show ip bgp'];
export const ROUTING_GLOBAL = ['router ospf <process>', 'no router ospf <process>', 'router bgp <as>', 'no router bgp <as>'];
export function routingPatterns(s) {
    const common = ['exit', 'end', ...ROUTING_SHOW.map(p => 'do ' + p), 'do show ip route'];
    return s.mode === 'ospf' ? ['router-id <id>', 'network <network> <wildcard> area <area>', 'no network <network> <wildcard> area <area>', 'passive-interface <interface>', 'no passive-interface <interface>', ...common] : ['bgp router-id <id>', 'neighbor <ip> remote-as <as>', 'neighbor <ip> weight <weight>', 'neighbor <ip> shutdown', 'no neighbor <ip> shutdown', 'no neighbor <ip>', 'network <network> mask <mask>', 'no network <network> mask <mask>', ...common];
}
export function routingShow(e, n, key) {
    const rt = e.rt(n);
    if (key === 'show ip ospf neighbor') return ['Neighbor ID      State    Address          Interface   Area / 진단', ...rt.ospf.neighbors.map(p => `${p.id.padEnd(16)} ${p.state.padEnd(8)} ${p.ip.padEnd(16)} ${portLabel(n, findPort(n, p.dev))} ${p.area} ${p.reason}`), !rt.ospf.neighbors.length ? '(OSPF 이웃 없음: network, 포트, 반대편 설정을 확인)' : ''].filter(Boolean).join('\n');
    if (key === 'show ip ospf database') return ['OSPF Router/Stub Link 요약 LSDB (단일 영역 교육용 모델)', ...rt.ospf.database.map(p => `Area ${p.area} Router ${p.routerId} Prefix ${p.prefix} Cost ${p.cost}`)].join('\n');
    if (key === 'show ip ospf interface') return n.interfaces.map(f => { const p = ospfPort(n, f); return p ? `${portLabel(n, f)} Area ${p.area} Cost ${p.cost} ${e.portUp(n, f) ? 'UP' : 'DOWN'} ${p.passive ? 'Passive' : 'Point-to-point model'}` : ''; }).filter(Boolean).join('\n') || '(OSPF 활성 포트 없음)';
    if (key === 'show ip bgp summary') return [`BGP router identifier ${n.bgp?.routerId || '(없음)'}, local AS ${n.bgp?.asn || '(없음)'}`, 'Neighbor         AS         State         PfxRcd / 진단', ...rt.bgp.neighbors.map(p => `${p.ip.padEnd(16)} ${String(p.asn).padEnd(10)} ${p.state.padEnd(13)} ${p.received} ${p.reason}`)].join('\n');
    if (key === 'show ip bgp') return ['BGP IPv4 유니캐스트 (* valid, > best; Weight는 로컬 정책)', '   Network             Next Hop        Weight Path', ...rt.bgp.paths.map(p => `*${p.best ? '>' : ' '} ${p.dest.padEnd(19)} ${(p.via || '0.0.0.0').padEnd(15)} ${String(p.weight).padEnd(6)} ${p.path.join(' ')} i`)].join('\n');
    return null;
}
export function routingCommand(e, n, t, s) {
    const no = t[0] === 'no', a = no ? t.slice(1) : t;
    if (s.mode === 'global' && a[0] === 'router' && ['ospf', 'bgp'].includes(a[1])) {
        need(n.type === 'router' && a.length === 3, 'OSPF/BGP는 IOS 라우터 프로필에서 지원합니다.');
        const type = a[1], value = integer(Number(a[2]), 1, type === 'ospf' ? 65535 : 4294967294), key = type === 'ospf' ? 'process' : 'asn';
        const routerId = n.interfaces.filter(f => f.address).map(f => cidr(f.address).address).sort((a, b) => ipv4(b) - ipv4(a))[0] || '1.1.1.1';
        update(e, n, x => { need(!x[type] || x[type][key] === value, '기존 프로세스를 no router 명령으로 먼저 제거하세요.'); x[type] = no ? null : x[type] || (type === 'ospf' ? {process: value, routerId, networks: [], ports: []} : {asn: value, routerId, networks: [], neighbors: []}); });
        if (!no) s.mode = type; return '';
    }
    if (s.mode === 'ospf') {
        if (!no && a[0] === 'router-id' && a.length === 2) { ipv4(a[1]); update(e, n, x => x.ospf.routerId = a[1]); return ''; }
        if (a[0] === 'network' && a.length === 5 && a[3] === 'area') {
            const prefix = wildcard(a[1], a[2]), area = /^\d+\.\d+\.\d+\.\d+$/.test(a[4]) ? ipv4(a[4]) : integer(Number(a[4]), 0, 4294967295);
            update(e, n, x => { x.ospf.networks = x.ospf.networks.filter(r => r.prefix !== prefix || no && r.area !== area); if (!no) x.ospf.networks.push({prefix, area}); }); return '';
        }
        if (a[0] === 'passive-interface' && a.length === 2) { const dev = findPort(n, a[1]).name; update(e, n, x => { let p = x.ospf.ports.find(p => p.dev === dev); if (!p) x.ospf.ports.push(p = {dev, cost: 10, passive: false}); p.passive = !no; }); return ''; }
    }
    if (s.mode === 'interface' && a.slice(0, 3).join(' ') === 'ip ospf cost') {
        need(n.ospf && (no && a.length === 3 || !no && a.length === 4), '먼저 router ospf를 설정하세요.');
        const cost = no ? 10 : integer(Number(a[3]), 1, 65535);
        update(e, n, x => { let p = x.ospf.ports.find(p => p.dev === s.port); if (!p) x.ospf.ports.push(p = {dev: s.port, cost: 10, passive: false}); p.cost = cost; }); return '';
    }
    if (s.mode === 'bgp') {
        if (!no && a.slice(0, 2).join(' ') === 'bgp router-id' && a.length === 3) { ipv4(a[2]); update(e, n, x => x.bgp.routerId = a[2]); return ''; }
        if (a[0] === 'network' && a.length === 4 && a[2] === 'mask') { const net = subnet(a[1], a[3]); update(e, n, x => { x.bgp.networks = x.bgp.networks.filter(r => r !== net); if (!no) x.bgp.networks.push(net); }); return ''; }
        if (a[0] === 'neighbor') {
            ipv4(a[1]);
            if (no && a.length === 2) { update(e, n, x => x.bgp.neighbors = x.bgp.neighbors.filter(p => p.ip !== a[1])); return ''; }
            need((!no && a.length === 4 && ['remote-as', 'weight'].includes(a[2])) || (a.length === 3 && a[2] === 'shutdown'));
            update(e, n, x => { let p = x.bgp.neighbors.find(p => p.ip === a[1]);
                if (!p) { need(a[2] === 'remote-as', '먼저 neighbor IP remote-as를 설정하세요.'); x.bgp.neighbors.push(p = {ip: a[1], asn: Number(a[3]), weight: 0, shutdown: false}); }
                if (a[2] === 'remote-as') p.asn = Number(a[3]);
                else if (a[2] === 'weight') p.weight = Number(a[3]);
                else p.shutdown = !no;
            }); return '';
        }
    }
    return null;
}
export function routingConfig(n) {
    const out = [];
    if (n.ospf) {
        out.push(`router ospf ${n.ospf.process}`, ` router-id ${n.ospf.routerId}`);
        for (const r of n.ospf.networks) { const c = cidr(r.prefix); out.push(` network ${ipString(c.network)} ${ipString((~c.mask) >>> 0)} area ${r.area}`); }
        for (const p of n.ospf.ports.filter(p => p.passive)) out.push(` passive-interface ${portLabel(n, findPort(n, p.dev))}`);
        out.push(' exit');
        for (const p of n.ospf.ports.filter(p => p.cost !== 10)) out.push(`interface ${portLabel(n, findPort(n, p.dev))}`, ` ip ospf cost ${p.cost}`, ' exit');
    }
    if (n.bgp) {
        out.push(`router bgp ${n.bgp.asn}`, ` bgp router-id ${n.bgp.routerId}`);
        for (const p of n.bgp.neighbors) { out.push(` neighbor ${p.ip} remote-as ${p.asn}`); if (p.weight) out.push(` neighbor ${p.ip} weight ${p.weight}`); if (p.shutdown) out.push(` neighbor ${p.ip} shutdown`); }
        for (const r of n.bgp.networks) { const c = cidr(r); out.push(` network ${ipString(c.network)} mask ${maskOf(c.bits)}`); } out.push(' exit');
    }
    return out;
}
