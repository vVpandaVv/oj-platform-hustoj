import {contains} from './model.js?ver=1.4.0-rc.4';
import {aclMatch} from './cli-common.js?ver=1.4.0-rc.4';
import {windowsFirewall} from './windows-traffic.js?ver=1.4.0-rc.4';
export const flowKey = p => `${p.protocol}/${p.src}/${p.sport || 0}/${p.dst}/${p.dport || 0}`;
const backKey = p => flowKey({...p, src: p.dst, dst: p.src, sport: p.dport, dport: p.sport});
export function iosACL(e, n, direction, port, p) {
    if (n.profile !== 'ios') return true;
    const binding = n.device.bindings.find(b => b.port === port && b.direction === direction); if (!binding) return true;
    const rule = aclMatch(n.device.acls.find(a => a.name === binding.acl), p), allowed = rule?.action === 'permit';
    if (rule) { const key = `acl:${binding.acl}:${rule.seq}`; e.rt(n).hits.set(key, (e.rt(n).hits.get(key) || 0) + 1); }
    e.log(n, allowed ? 'allow' : 'drop', p, `ACL ${binding.acl} ${direction} ${rule ? `sequence ${rule.seq}: ${rule.action}` : 'implicit deny'}`, port); return allowed;
}
function service(n, name, p) {
    if (name === 'ALL') return true;
    if (name === 'PING') return p.protocol === 'ICMP';
    const builtin = {HTTP: ['TCP', 80], HTTPS: ['TCP', 443], DNS: ['UDP', 53], SSH: ['TCP', 22]}[name];
    if (builtin) return p.protocol === builtin[0] && p.dport === builtin[1];
    const custom = n.device.services.find(s => s.name === name); return custom && custom.protocol === p.protocol && p.dport >= custom.from && p.dport <= custom.to;
}
export function deviceFilter(e, n, hook, p, ingress, egress) {
    if (n.profile === 'windows') return windowsFirewall(e, n, hook, p, ingress, egress);
    if (n.profile === 'ios') return hook === 'input' || iosACL(e, n, 'out', egress, p);
    if (n.profile !== 'fortios') return true;
    const rt = e.rt(n), own = rt.conn.get(flowKey(p)), back = rt.conn.get(backKey(p));
    if (hook === 'output') { if (own) Object.assign(own, {allowed: true, policy: 'local', ingress: '', egress}); return true; }
    if (p.icmp === 'error' && p.quoted) {
        const quoted = rt.conn.get(flowKey(p.quoted));
        if (quoted?.allowed && quoted.until > e.now && quoted.egress === ingress && (hook === 'input' ? quoted.ingress === '' : quoted.ingress === egress)) { e.log(n, 'allow', p, '허용 세션과 연관된 ICMP 오류', ingress); return true; }
    }
    if (hook === 'input') {
        if (p.ipsec) return n.device.phase1.some(v => v.interface === ingress && v.remote === p.src && (p.protocol === 'ESP' || (p.protocol === 'UDP' && p.dport === 500)));
        if (back?.allowed && back.until > e.now && back.egress === ingress) return true;
        const allowed = p.protocol === 'ICMP' && p.icmp === 'echo' && n.interfaces.find(f => f.name === ingress)?.allowPing;
        if (!allowed) e.log(n, 'drop', p, 'local-in 관리 접근 차단', ingress); return !!allowed;
    }
    if ((own?.allowed && own.until > e.now && own.ingress === ingress && own.egress === egress) || (back?.allowed && back.until > e.now && back.egress === ingress && back.ingress === egress)) { e.log(n, 'allow', p, '기존 허용 세션', ingress); return true; }
    const addr = (names, ip) => names.some(name => name === 'all' || contains(n.device.addresses.find(a => a.name === name).subnet, ip));
    const policy = n.device.policies.find(r => r.status && (r.srcintf === 'any' || r.srcintf === ingress) && (r.dstintf === 'any' || r.dstintf === egress) && addr(r.srcaddr, p.src) && addr(r.dstaddr, p.dst) && r.service.some(s => service(n, s, p)));
    const allowed = policy?.action === 'accept';
    if (policy) { const key = `policy:${policy.id}`; rt.hits.set(key, (rt.hits.get(key) || 0) + 1); }
    e.log(n, allowed ? 'allow' : 'drop', p, `FortiOS policy ${policy?.id || 0}: ${policy?.action || 'implicit deny'}${policy?.logtraffic ? ' · log' : ''}`, ingress);
    if (allowed && own) Object.assign(own, {allowed: true, policy: policy.id, nat: policy.nat, ingress, egress});
    return allowed;
}
export function deviceNAT(e, n, hook, p, ingress, egress) {
    if (n.profile === 'ios') {
        const d = n.device;
        if (hook === 'prerouting' && d.outside.includes(ingress)) { const rule = d.staticNat.find(r => r.global === p.dst && r.globalPort === p.dport && r.protocol === p.protocol); if (rule) return {action: 'dnat', to: `${rule.local}:${rule.localPort}`, handle: 'IOS static'}; }
        if (hook === 'postrouting' && d.nat?.port === egress && d.inside.includes(ingress) && d.outside.includes(egress) && aclMatch(d.acls.find(a => a.name === d.nat.acl), p)?.action === 'permit') return {action: 'masquerade', to: '', handle: 'IOS overload'};
    }
    if (n.profile === 'fortios' && hook === 'postrouting') { const entry = e.rt(n).conn.get(flowKey(p)); if (entry?.allowed && entry.nat && entry.ingress === ingress && entry.egress === egress) return {action: 'masquerade', to: '', handle: `policy ${entry.policy}`}; }
    return null;
}
