import {cidr, contains, ipString, ipv4} from './model.js?ver=1.4.0-rc.4';
import {iosACL} from './device-traffic.js?ver=1.4.0-rc.4';

const addr = f => f?.address ? cidr(f.address).address : '';
const prefix = f => { const c = cidr(f.address); return `${ipString(c.network)}/${c.bits}`; };
const ospfPort = (n, f) => {
    if (!n.ospf || !f.address || !f.up || f.kind !== 'ethernet') return null;
    const net = n.ospf.networks.filter(r => contains(r.prefix, addr(f))).sort((a, b) => cidr(b.prefix).bits - cidr(a.prefix).bits)[0];
    return net ? {...net, cost: 10, passive: false, ...n.ospf.ports.find(p => p.dev === f.name)} : null;
};
function directlyConnected(e, n, f) {
    const link = e.project.links.find(l => l.up && l.loss < 100 && ((l.a.node === n.id && l.a.port === f.name) || (l.b.node === n.id && l.b.port === f.name)));
    if (!link || !f.up || !f.address) return null;
    const end = link.a.node === n.id ? link.b : link.a, peer = e.node(end.node), remote = peer.interfaces.find(f => f.name === end.port);
    if (!remote?.up || !remote.address || !contains(f.address, addr(remote)) || !contains(remote.address, addr(f))) return null;
    return {peer, remote, link};
}
function permitted(e, n, f, peer, remote, protocol) {
    const a = {protocol, src: addr(f), dst: addr(remote), sport: protocol === 'TCP' ? 179 : 0, dport: protocol === 'TCP' ? 179 : 0};
    const b = {...a, src: a.dst, dst: a.src};
    return iosACL(e, n, 'out', f.name, a) && iosACL(e, peer, 'in', remote.name, a) && iosACL(e, peer, 'out', remote.name, b) && iosACL(e, n, 'in', f.name, b);
}
export function refreshRouting(e, force = false) {
    const stamp = JSON.stringify([e.project.links, e.project.nodes.map(n => [n.id, n.interfaces, n.routes, n.ospf, n.bgp, n.device?.acls, n.device?.bindings])]);
    if (!force && e.routingStamp === stamp) return;
    const active = e.project.nodes.some(n => n.ospf || n.bgp || e.rt(n)?.dynamic.length);
    e.routingStamp = stamp;
    if (active) {
        const epoch = ++e.routingEpoch;
        for (const n of e.project.nodes) if (n.ospf || n.bgp) e.log(n, 'control', {protocol: n.ospf ? 'OSPF' : 'BGP'}, '설정 변화 감지: 500ms 교육용 수렴 사건 예약');
        e.schedule(500, 'routingConverge', epoch);
    }
}
export function convergeRouting(e, epoch) {
    if (epoch !== e.routingEpoch) return;
    const old = new Map(e.project.nodes.map(n => [n.id, JSON.stringify(e.rt(n).dynamic)]));
    for (const n of e.project.nodes) { const r = e.rt(n); r.dynamic = []; r.ospf = {neighbors: [], database: []}; r.bgp = {neighbors: [], paths: []}; }
    ospf(e); bgp(e);
    for (const n of e.project.nodes) if (old.get(n.id) !== JSON.stringify(e.rt(n).dynamic)) {
        e.log(n, 'control', {protocol: n.ospf ? 'OSPF' : 'BGP'}, `경로 재계산·철회 완료: 동적 경로 ${e.rt(n).dynamic.length}개`);
    }
}
function ospf(e) {
    const nodes = e.project.nodes.filter(n => n.ospf), edges = [], ads = [];
    const duplicate = id => nodes.filter(n => n.ospf.routerId === id).length > 1;
    for (const n of nodes) for (const f of n.interfaces) {
        const p = ospfPort(n, f); if (!p || !e.portUp(n, f)) continue;
        if (!duplicate(n.ospf.routerId)) ads.push({node: n.id, routerId: n.ospf.routerId, area: p.area, prefix: prefix(f), cost: p.cost});
        if (p.passive) continue;
        const next = directlyConnected(e, n, f); if (!next?.peer.ospf) continue;
        const {peer, remote, link} = next, other = ospfPort(peer, remote);
        let reason = '';
        if (!other) reason = '상대 인터페이스가 OSPF network에 포함되지 않음';
        else if (other.passive) reason = '상대 passive-interface';
        else if (duplicate(n.ospf.routerId) || duplicate(peer.ospf.routerId)) reason = '중복 Router ID';
        else if (other.area !== p.area) reason = 'Area mismatch';
        else if (cidr(f.address).bits !== cidr(remote.address).bits) reason = 'Subnet mask mismatch';
        else if (f.mtu !== remote.mtu) reason = 'MTU mismatch';
        else if (!permitted(e, n, f, peer, remote, 'OSPF')) reason = 'OSPF ACL 차단';
        const neighbor = {id: peer.ospf.routerId, ip: addr(remote), dev: f.name, area: p.area, cost: p.cost, state: reason ? 'Down' : 'Full', reason};
        e.rt(n).ospf.neighbors.push(neighbor);
        e.log(n, 'control', {protocol: 'OSPF', src: addr(f), dst: addr(remote)}, `${neighbor.state}: ${reason || 'Hello 조건 일치, LSDB 동기화 모델'}`, f.name, link.id);
        if (!reason) edges.push({from: n.id, to: peer.id, area: p.area, cost: p.cost, dev: f.name, via: addr(remote)});
    }
    for (const source of nodes) {
        const results = new Map(), database = new Map();
        for (const area of new Set(ads.filter(a => a.node === source.id).map(a => a.area))) {
            const distance = new Map([[source.id, 0]]), first = new Map(), done = new Set();
            while (true) {
                const current = [...distance].filter(([id]) => !done.has(id)).sort((a, b) => a[1] - b[1] || a[0].localeCompare(b[0]))[0];
                if (!current) break; const [id, cost] = current; done.add(id);
                for (const edge of edges.filter(x => x.from === id && x.area === area)) {
                    if (cost + edge.cost >= (distance.get(edge.to) ?? Infinity)) continue;
                    distance.set(edge.to, cost + edge.cost); first.set(edge.to, id === source.id ? edge : first.get(id));
                }
            }
            for (const ad of ads.filter(a => a.area === area && distance.has(a.node))) {
                database.set(`${ad.node}/${ad.area}/${ad.prefix}`, ad);
                if (ad.node === source.id || e.routes(source, false).some(r => r.connected && r.dest === ad.prefix)) continue;
                const hop = first.get(ad.node), cost = distance.get(ad.node) + ad.cost, previous = results.get(ad.prefix);
                if (!hop || previous && previous.metric <= cost) continue;
                results.set(ad.prefix, {dest: ad.prefix, via: hop.via, dev: hop.dev, metric: cost, distance: 110, source: 'O', area});
            }
        }
        e.rt(source).ospf.database = [...database.values()]; e.rt(source).dynamic.push(...results.values());
    }
}
function bgp(e) {
    const nodes = e.project.nodes.filter(n => n.bgp), sessions = new Map(), origins = new Map();
    for (const n of nodes) {
        const established = [], rt = e.rt(n);
        for (const cfg of n.bgp.neighbors) {
            const f = n.interfaces.find(f => f.up && f.address && contains(f.address, cfg.ip)), next = f && directlyConnected(e, n, f);
            const peer = next?.peer, other = peer?.bgp?.neighbors.find(p => p.ip === addr(f));
            let reason = '';
            if (cfg.shutdown) reason = 'Administratively shutdown';
            else if (!next || addr(next.remote) !== cfg.ip) reason = '직접 연결 피어 도달 불가';
            else if (!peer.bgp || !other) reason = '상대 BGP 또는 역방향 neighbor 미설정';
            else if (other.shutdown) reason = '상대 neighbor shutdown';
            else if (cfg.asn !== peer.bgp.asn || other.asn !== n.bgp.asn) reason = 'Remote AS mismatch';
            else if (n.bgp.asn === peer.bgp.asn) reason = 'iBGP 미지원: 서로 다른 AS가 필요함';
            else if (!permitted(e, n, f, peer, next.remote, 'TCP')) reason = 'TCP 179 ACL 차단';
            const item = {ip: cfg.ip, asn: cfg.asn, state: reason ? cfg.shutdown ? 'Idle' : 'Active' : 'Established', reason, received: 0};
            rt.bgp.neighbors.push(item);
            e.log(n, 'control', {protocol: 'BGP', src: addr(f), dst: cfg.ip}, `${item.state}: ${reason || '양방향 OPEN 조건 일치, UPDATE 계산 모델'}`, f?.name || '');
            if (!reason) established.push({peer: peer.id, cfg, f, status: item});
        }
        sessions.set(n.id, established);
        const local = [...e.routes(n, false), ...rt.dynamic.filter(r => r.source === 'O')];
        origins.set(n.id, n.bgp.networks.filter(net => local.some(r => r.dest === net)).map(dest => ({dest, path: [], via: '', dev: '', weight: 32768, local: true})));
    }
    // A path-vector fixed point. Only the selected path is advertised; own-AS paths are rejected.
    let best = new Map(nodes.map(n => [n.id, origins.get(n.id)])), all = new Map(), stable = false;
    const rank = (a, b) => b.weight - a.weight || a.path.length - b.path.length || ipv4(a.via || '0.0.0.0') - ipv4(b.via || '0.0.0.0');
    for (let round = 0; round <= nodes.length * nodes.length + 1; round++) {
        const next = new Map(); all = new Map();
        for (const n of nodes) {
            const candidates = [...origins.get(n.id)];
            for (const s of sessions.get(n.id)) for (const remote of best.get(s.peer) || []) {
                const path = [e.node(s.peer).bgp.asn, ...remote.path]; if (path.includes(n.bgp.asn)) continue;
                candidates.push({dest: remote.dest, path, via: s.cfg.ip, dev: s.f.name, weight: s.cfg.weight, local: false});
            }
            if (candidates.length > 4096) { e.pausedReason = 'BGP 후보 4096개 한도: 광고 접두사 또는 피어 수를 줄이세요.'; return; }
            const chosen = new Map(); for (const p of candidates.slice().sort(rank)) if (!chosen.has(p.dest)) chosen.set(p.dest, p);
            if (chosen.size > 256) { e.pausedReason = 'BGP 접두사 256개 한도: 광고 접두사 수를 줄이세요.'; return; }
            next.set(n.id, [...chosen.values()].sort((a, b) => a.dest.localeCompare(b.dest))); all.set(n.id, candidates);
        }
        if (JSON.stringify([...next]) === JSON.stringify([...best])) { best = next; stable = true; break; } best = next;
    }
    for (const n of nodes) {
        const rt = e.rt(n);
        if (!stable) { e.log(n, 'control', {protocol: 'BGP'}, '정책으로 안정 경로에 도달하지 못함: BGP 경로를 설치하지 않습니다.'); continue; }
        rt.bgp.paths = (all.get(n.id) || []).map(p => ({...p, best: best.get(n.id).some(b => b.dest === p.dest && b.via === p.via)}));
        for (const s of sessions.get(n.id)) s.status.received = rt.bgp.paths.filter(p => p.via === s.cfg.ip).length;
        rt.dynamic.push(...best.get(n.id).filter(p => !p.local).map(p => ({dest: p.dest, via: p.via, dev: p.dev, metric: 0, distance: 20, source: 'B', path: p.path, weight: p.weight})));
    }
}
export {ospfPort};
