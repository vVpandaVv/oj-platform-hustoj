export function normalizeRouting(raw, node, v) {
    const {array, integer, ipv4, cidr, ipString} = v;
    const unique = (items, key) => { if (new Set(items.map(key)).size !== items.length) throw new Error('동적 라우팅 설정이 중복됩니다.'); return items; };
    const id = x => { ipv4(x); if (x === '0.0.0.0') throw new Error('0이 아닌 라우터 ID가 필요합니다.'); return x; };
    const net = value => { const c = cidr(value); return `${ipString(c.network)}/${c.bits}`; };
    if ((raw.ospf || raw.bgp) && !(node.profile === 'ios' && node.type === 'router')) throw new Error('OSPF/BGP는 IOS 라우터 프로필에서 지원합니다.');
    node.ospf = raw.ospf ? {
        process: integer(raw.ospf.process, 1, 65535), routerId: id(raw.ospf.routerId),
        networks: unique(array(raw.ospf.networks, 32).map(r => ({prefix: net(r.prefix), area: integer(r.area, 0, 4294967295)})), r => r.prefix),
        ports: unique(array(raw.ospf.ports || [], 10).map(p => {
            if (!node.interfaces.some(f => f.name === p.dev && f.kind === 'ethernet')) throw new Error('OSPF 물리 포트가 없습니다.');
            return {dev: p.dev, cost: integer(p.cost ?? 10, 1, 65535), passive: p.passive === true};
        }), p => p.dev)
    } : null;
    node.bgp = raw.bgp ? {
        asn: integer(raw.bgp.asn, 1, 4294967294), routerId: id(raw.bgp.routerId),
        networks: unique(array(raw.bgp.networks, 32).map(net), x => x),
        neighbors: unique(array(raw.bgp.neighbors, 16).map(p => ({ip: (ipv4(p.ip), p.ip), asn: integer(p.asn, 1, 4294967294), weight: integer(p.weight ?? 0, 0, 65535), shutdown: p.shutdown === true})), p => p.ip)
    } : null;
}
