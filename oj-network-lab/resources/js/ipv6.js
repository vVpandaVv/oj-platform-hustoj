import {ipv6, cidr6, contains6, linkLocal, addresses6, isLinkLocal, multicastMAC, solicitedNode} from './ipv6-address.js?ver=1.4.0-rc.4';

const need = (ok, message = 'IPv6 지원 문법을 확인하세요.') => { if (!ok) throw new Error(message); };
const port = (n, name) => { const f = n.interfaces.find(f => f.name === name && f.kind === 'ethernet'); need(f, 'Ethernet 포트가 필요합니다.'); return f; };
const mutate = (e, n, fn) => { const p = e.export(); fn(p.nodes.find(x => x.id === n.id)); e.configure(p); return ''; };
export function routes6(e, n) {
    const available = n.interfaces.filter(f => f.up && f.ipv6?.length && e.portUp(n, f));
    const connected = available.flatMap(f => [...new Set([...f.ipv6.map(a => cidr6(a).dest), 'fe80::/64'])].map(dest => ({dest, via: '', dev: f.name, metric: 0, connected: true})));
    return [...connected, ...(n.routes6 || [])].filter(r => available.some(f => f.name === r.dev));
}
function route6(e, n, dst, dev = '') { return routes6(e, n).filter(r => (!dev || r.dev === dev) && contains6(r.dest, dst)).sort((a, b) => cidr6(b.dest).bits - cidr6(a.dest).bits || a.metric - b.metric)[0]; }
export function command6(e, n, t) {
    if (t[0] === 'sysctl' && t.some(s => s.startsWith('net.ipv6.'))) {
        const a = t.filter(s => s !== '-w'); need(a.length === 2 && /^net\.ipv6\.conf\.all\.forwarding(?:=[01])?$/.test(a[1]));
        if (a[1].includes('=')) mutate(e, n, x => x.forwarding6 = a[1].endsWith('1'));
        return `net.ipv6.conf.all.forwarding = ${e.node(n.id).forwarding6 ? 1 : 0}`;
    }
    if (t[0] === 'ip' && t[1] === '-6') {
        if (['addr', 'address'].includes(t[2])) {
            if (t.length === 3 || t.length === 4 && t[3] === 'show') return n.interfaces.map(f => `${f.name}: ${f.up ? 'UP' : 'DOWN'}\n${(f.ipv6 || []).map(a => `    inet6 ${a}`).join('\n')}${f.ipv6?.length ? `\n    inet6 ${linkLocal(f)}/64 scope link` : '    IPv6 주소 없음'}`).join('\n');
            if (t[3] === 'flush') { need(t.length === 6 && t[4] === 'dev'); return mutate(e, n, x => port(x, t[5]).ipv6 = []); }
            if (t[3] === 'add' || t[3] === 'del') {
                need(t.length === 7 && t[5] === 'dev'); const a = cidr6(t[4]).value;
                return mutate(e, n, x => { const f = port(x, t[6]); if (t[3] === 'add') { need(!f.ipv6.includes(a), 'IPv6 주소가 이미 있습니다.'); f.ipv6.push(a); } else { need(f.ipv6.includes(a), '지울 IPv6 주소가 없습니다.'); f.ipv6 = f.ipv6.filter(v => v !== a); } });
            }
        }
        if (t[2] === 'route') {
            if (t.length === 3 || t.length === 4 && t[3] === 'show') return routes6(e, n).map(r => `${r.dest === '::/0' ? 'default' : r.dest}${r.via ? ` via ${r.via}` : ''} dev ${r.dev} metric ${r.metric}${r.connected ? ' proto kernel' : ''}`).join('\n') || '(IPv6 경로 없음)';
            const dest = t[4] === 'default' ? '::/0' : cidr6(t[4]).dest;
            if (['del', 'delete'].includes(t[3])) { need(t.length === 5); return mutate(e, n, x => { need(x.routes6.some(r => r.dest === dest), 'IPv6 정적 경로가 없습니다.'); x.routes6 = x.routes6.filter(r => r.dest !== dest); }); }
            need(['add', 'replace'].includes(t[3]) && (t.length - 5) % 2 === 0);
            const opts = {}; for (let i = 5; i < t.length; i += 2) { need(['via', 'dev', 'metric'].includes(t[i]) && opts[t[i]] === undefined); opts[t[i]] = t[i + 1]; }
            port(n, opts.dev); const via = opts.via ? ipv6(opts.via) : '', metric = opts.metric === undefined ? 1024 : Number(opts.metric);
            return mutate(e, n, x => { const exists = x.routes6.some(r => r.dest === dest && r.metric === metric); need(t[3] === 'replace' || !exists, '같은 경로가 있습니다. replace를 사용하세요.'); if (t[3] === 'replace') x.routes6 = x.routes6.filter(r => r.dest !== dest || r.metric !== metric); x.routes6.push({dest, via, dev: opts.dev, metric}); });
        }
        if (t[2] === 'neigh' && (t.length === 3 || t.length === 4 && ['show', 'flush'].includes(t[3]))) {
            if (t[3] === 'flush') { e.rt(n).nd.clear(); return 'IPv6 이웃 캐시를 비웠습니다.'; }
            return [...e.rt(n).nd.values()].filter(v => v.until > e.now).map(v => `${v.ip} dev ${v.port} lladdr ${v.mac} REACHABLE`).join('\n') || '(IPv6 이웃 캐시 없음)';
        }
        need(false);
    }
    if (t[0] === 'ip' && t[1] === 'link' && t[2] === 'set' && t.includes('mtu')) {
        const a = t.slice(3).filter(s => s !== 'dev'); need(a.length === 3 && a[1] === 'mtu');
        return mutate(e, n, x => port(x, a[0]).mtu = Number(a[2]));
    }
    if (['ping', 'ping6', 'traceroute', 'traceroute6'].includes(t[0]) && (t[0].endsWith('6') || t.includes('-6'))) {
        need(['pc', 'router', 'server'].includes(n.type), 'IPv6 실습은 Linux PC·서버·라우터를 선택하세요.');
        let count = 1, size = 56, dev = '', dest = '';
        const trace = t[0].startsWith('traceroute');
        for (let i = 1; i < t.length; i++) {
            if (t[i] === '-6' || trace && t[i] === '-I') continue;
            if (t[i] === '-c' && !trace) count = Number(t[++i]);
            else if (t[i] === '-s' && !trace) size = Number(t[++i]);
            else if (t[i] === '-I' && !trace) dev = t[++i];
            else { need(!dest && !t[i].startsWith('-')); dest = t[i]; }
        }
        need(Number.isInteger(count) && count >= 1 && count <= 4 && Number.isInteger(size) && size >= 0 && size <= 8952);
        const split = dest.split('%'); need(split.length <= 2); if (split[1]) { need(!dev || dev === split[1]); dev = split[1]; }
        dest = ipv6(split[0]); if (dev) port(n, dev);
        need(!isLinkLocal(dest) || dev, '링크 로컬 목적지는 %eth0 또는 -I eth0으로 범위를 지정하세요.');
        need(dest !== '::' && !dest.startsWith('ff'), '유니캐스트 목적지를 지정하세요.');
        const job = e.job(n, trace ? 'trace' : 'ping', dest, {count: trace ? 8 : count, options: {ipv6: true, size, dev}});
        for (let i = 1; i <= job.count; i++) e.schedule((i - 1) * 100, 'probe', {job: job.id, probe: i});
        e.schedule(4000 + job.count * 100, 'jobTimeout', job.id); e.say(n, `${trace ? 'traceroute -6' : 'PING IPv6'} ${dest} (가상 시간)`); return '';
    }
    return null;
}
export function send6(e, n, p, generated = false) {
    p = {...p, family: 6};
    if (generated && (p.dst === '::1' || n.interfaces.some(f => f.up && addresses6(f).includes(p.dst)))) { e.local(n, {name: 'lo'}, {...p, src: p.src || p.dst}); return; }
    const r = route6(e, n, p.dst, generated ? p.dev || '' : ''), f = n.interfaces.find(f => f.name === r?.dev);
    if (!r || !f) { e.log(n, 'drop', p, 'IPv6 목적지 경로가 없습니다.'); return; }
    p.src ||= isLinkLocal(p.dst) ? linkLocal(f) : f.ipv6.map(a => cidr6(a).address).find(a => !isLinkLocal(a));
    if (!p.src) { e.log(n, 'drop', p, '출발 IPv6 주소가 없습니다.', f.name); return; }
    delete p.dev;
    if (p.size > f.mtu) {
        e.log(n, 'drop', p, `IPv6 Packet Too Big: MTU ${f.mtu}`, f.name);
        if (generated) { const j = e.jobs.get(p.job); if (j) j.errors.push(`local: Message too long, MTU ${f.mtu}`); }
        else error6(e, n, p, `Packet Too Big (MTU ${f.mtu})`);
        return;
    }
    const next = r.via || p.dst;
    if (r.via && !isLinkLocal(next) && !f.ipv6.some(a => contains6(a, next))) { e.log(n, 'drop', p, 'IPv6 게이트웨이가 직접 연결 접두사에 없습니다.', f.name); return; }
    const rt = e.rt(n), key = `${f.name}/${next}`, cached = rt.nd.get(key);
    if (cached?.until > e.now) e.sendFrame(n, f, {type: 'IPv6', srcMAC: f.mac, dstMAC: cached.mac, tag: null, packet: p});
    else {
        let pending = rt.ndWaiting.get(key);
        if (!pending) { rt.ndWaiting.set(key, pending = {packets: []}); e.schedule(0, 'ndRetry', {node: n.id, port: f.name, ip: next, attempt: 0}); }
        if (pending.packets.length < 32) pending.packets.push(p); else e.log(n, 'drop', p, 'IPv6 이웃 대기 큐 초과', f.name);
    }
}
export function retryND(e, {node, port: name, ip, attempt}) {
    const n = e.project.nodes.find(n => n.id === node), f = n?.interfaces.find(f => f.name === name), rt = n && e.rt(n), key = `${name}/${ip}`, pending = rt?.ndWaiting.get(key);
    if (!pending) return;
    if (attempt >= 3 || !f?.up || !f.ipv6?.length) { rt.ndWaiting.delete(key); for (const p of pending.packets) e.log(n, 'drop', p, `IPv6 NS 응답 없음: ${ip}`, name); return; }
    const dst = solicitedNode(ip), p = e.packet('ICMPv6', linkLocal(f), dst, {nd: 'NS', target: ip, ttl: 255, family: 6, size: 72});
    e.sendFrame(n, f, {type: 'IPv6', srcMAC: f.mac, dstMAC: multicastMAC(dst), tag: null, packet: p});
    e.schedule(800, 'ndRetry', {node, port: name, ip, attempt: attempt + 1});
}
function learn(e, n, f, ip, mac) {
    const rt = e.rt(n), key = `${f.name}/${ip}`; rt.nd.set(key, {ip, mac, port: f.name, until: e.now + 30000});
    const pending = rt.ndWaiting.get(key); if (!pending) return;
    rt.ndWaiting.delete(key); for (const p of pending.packets) e.sendFrame(n, f, {type: 'IPv6', srcMAC: f.mac, dstMAC: mac, tag: null, packet: p});
}
function error6(e, n, p, reason) {
    if (p.icmp === 'error') return;
    send6(e, n, e.packet('ICMPv6', '', p.src, {family: 6, icmp: 'error', reason, size: 96, quoted: {job: p.job, probe: p.probe}}), true);
}
export function receive6(e, n, f, frame) {
    const p = frame.packet; if (!p || !f.ipv6?.length) return;
    if (p.nd) {
        if (p.ttl !== 255) return;
        if (p.nd === 'NS' && addresses6(f).includes(p.target)) {
            learn(e, n, f, p.src, frame.srcMAC);
            e.log(n, 'neighbor', p, `NS 수신: ${p.target}, NA 응답`, f.name);
            e.sendFrame(n, f, {type: 'IPv6', srcMAC: f.mac, dstMAC: frame.srcMAC, tag: null, packet: e.packet('ICMPv6', p.target, p.src, {family: 6, nd: 'NA', target: p.target, ttl: 255, size: 72})});
        } else if (p.nd === 'NA' && addresses6(f).includes(p.dst)) { learn(e, n, f, p.target, frame.srcMAC); e.log(n, 'neighbor', p, `NA 수신: ${p.target}`, f.name); }
        return;
    }
    if (n.interfaces.some(i => i.up && addresses6(i).includes(p.dst))) { e.local(n, f, p); return; }
    if (!n.forwarding6) { e.log(n, 'drop', p, 'IPv6 forwarding이 꺼져 있습니다.', f.name); return; }
    if (isLinkLocal(p.src) || isLinkLocal(p.dst)) { e.log(n, 'drop', p, '링크 로컬 주소는 라우팅하지 않습니다.', f.name); return; }
    if (p.ttl <= 1) { e.log(n, 'drop', p, 'IPv6 Hop Limit 만료', f.name); error6(e, n, p, 'time-exceeded'); return; }
    e.log(n, 'route', p, 'IPv6 라우터 전달', f.name); send6(e, n, {...p, ttl: p.ttl - 1});
}
