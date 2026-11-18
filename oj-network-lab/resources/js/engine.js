import {LIMITS, VERSION, clone, validateProject, ipv4, cidr, contains, ipString, endpoint, publicKey} from './model.js?ver=1.4.0-rc.4';
import {deviceCommand, prompt, session, complete, interrupt} from './cli.js?ver=1.4.0-rc.4';
import {deviceFilter, deviceNAT, iosACL} from './device-traffic.js?ver=1.4.0-rc.4';
import {ipsecTransmit, ipsecReceive, retryIPsec, ipsecStatus} from './ipsec.js?ver=1.4.0-rc.4';
import {configSnapshot} from './device-config.js?ver=1.4.0-rc.4';
import {winAdapter, windowsResolver} from './windows-config.js?ver=1.4.0-rc.4';
import {windowsJobHeader, finishWindowsJob} from './windows-traffic.js?ver=1.4.0-rc.4';
import {send6, receive6, retryND} from './ipv6.js?ver=1.4.0-rc.4';
import {refreshRouting, convergeRouting} from './routing.js?ver=1.4.0-rc.4';

class EventQueue {
    constructor() { this.items = []; }
    get length() { return this.items.length; }
    push(e) { let i = this.items.length; this.items.push(e); while (i > 0) { const p = (i - 1) >> 1; if (this.less(this.items[p], e)) break; this.items[i] = this.items[p]; i = p; } this.items[i] = e; }
    less(a, b) { return a.at < b.at || (a.at === b.at && a.order < b.order); }
    pop() { const first = this.items[0], last = this.items.pop(); if (this.items.length) { let i = 0; while (i * 2 + 1 < this.items.length) { let c = i * 2 + 1; if (c + 1 < this.items.length && this.less(this.items[c + 1], this.items[c])) c++; if (this.less(last, this.items[c])) break; this.items[i] = this.items[c]; i = c; } this.items[i] = last; } return first; }
}
const tuple = p => `${p.protocol}/${p.src}/${p.sport || 0}/${p.dst}/${p.dport || 0}`;
const reverse = p => tuple({...p, src: p.dst, dst: p.src, sport: p.dport, dport: p.sport});
const address = f => f?.address ? cidr(f.address).address : '';
const BROADCAST = 'ff:ff:ff:ff:ff:ff';

export class Engine {
    constructor(project) { this.load(project); }
    load(project) {
        this.project = validateProject(project); this.now = 0; this.sequence = 0; this.processed = 0; this.seed = this.project.seed; this.queue = new EventQueue(); this.trace = []; this.messages = []; this.jobs = new Map(); this.pausedReason = ''; this.portSequence = 40000; this.lastLink = ''; this.revision = 0;
        this.consoles = new Map();
        this.runtime = new Map(); for (const n of this.project.nodes) this.resetDevice(n.id);
        this.busy = new Map();
        this.routingStamp = ''; this.routingEpoch = 0; refreshRouting(this);
    }
    node(id) { const n = this.project.nodes.find(n => n.id === id); if (!n) throw new Error('장비를 선택하세요.'); return n; }
    rt(node) { return this.runtime.get(typeof node === 'string' ? node : node.id); }
    resetDevice(id) { this.runtime.set(id, {arp: new Map(), nd: new Map(), ndWaiting: new Map(), dynamic: [], ospf: {neighbors: [], database: []}, bgp: {neighbors: [], paths: []}, fdb: new Map(), waiting: new Map(), conn: new Map(), nat: [], tcp: new Map(), dns: new Map(), leases: new Map(), vpn: new Map(), ipsec: new Map(), hits: new Map(), capture: null}); this.routingStamp = ''; this.consoles.delete(id); for (const j of this.jobs.values()) if (j.node === id && j.status === 'pending') j.status = 'cancelled'; }
    configurationChanged(before, after) {
        const rt = this.rt(after); if (!rt) { this.resetDevice(after.id); return; }
        if (JSON.stringify(before.interfaces) !== JSON.stringify(after.interfaces) || JSON.stringify(before.routes6) !== JSON.stringify(after.routes6)) { rt.nd.clear(); rt.ndWaiting.clear(); }
        if (JSON.stringify(before.interfaces) !== JSON.stringify(after.interfaces) || JSON.stringify(before.routes) !== JSON.stringify(after.routes)) { rt.arp.clear(); rt.fdb.clear(); rt.waiting.clear(); rt.conn.clear(); rt.nat = []; rt.vpn.clear(); rt.ipsec.clear(); }
        if (JSON.stringify(before.device) !== JSON.stringify(after.device) || JSON.stringify(before.rules) !== JSON.stringify(after.rules)) { rt.conn.clear(); rt.nat = []; rt.hits.clear(); }
        if (JSON.stringify(before.windows) !== JSON.stringify(after.windows)) { rt.conn.clear(); rt.hits.clear(); }
        if (after.profile === 'windows' && before.resolver !== after.resolver) rt.dns.clear();
        if (after.profile === 'windows') {
            if (JSON.stringify(before.windows?.adapters.map(a => [a.name, a.dns, a.dnsAutomatic])) !== JSON.stringify(after.windows.adapters.map(a => [a.name, a.dns, a.dnsAutomatic]))) rt.dns.clear();
            for (const j of this.jobs.values()) if (j.node === after.id && j.type === 'dhcp' && j.status === 'pending') { const f = after.interfaces.find(f => f.name === j.port); if (!f?.up || !winAdapter(after, f)?.dhcp) j.status = 'cancelled'; }
        }
        if (JSON.stringify(before.device?.phase1) !== JSON.stringify(after.device?.phase1) || JSON.stringify(before.device?.phase2) !== JSON.stringify(after.device?.phase2)) rt.ipsec.clear();
    }
    portUp(n, f) {
        if (!f?.up) return false;
        if (f.kind === 'subinterface') return f.encapsulated && this.portUp(n, n.interfaces.find(p => p.name === f.parent));
        if (f.kind === 'vlan') return n.device?.vlans.some(v => v.id === f.vlanId) && n.interfaces.some(p => p.kind === 'ethernet' && p.switchport !== false && (p.mode === 'access' ? p.vlan === f.vlanId : p.trunkAll || p.allowedVlans.includes(f.vlanId)) && this.portUp(n, p));
        if (f.kind !== 'ethernet') return true;
        const link = this.project.links.find(l => l.up && ((l.a.node === n.id && l.a.port === f.name) || (l.b.node === n.id && l.b.port === f.name)));
        if (!link) return false; const target = link.a.node === n.id ? link.b : link.a; return this.node(target.node).interfaces.find(p => p.name === target.port)?.up === true;
    }
    ipsecStatus(n) { return ipsecStatus(this, n); }
    complete(id, line) { return complete(this, this.node(id), line); }
    consolePrompt(id) { return prompt(this, this.node(id)); }
    interrupt(id) { this.say(id, interrupt(this, this.node(id))); for (const j of this.jobs.values()) if (j.node === id && j.status === 'pending') j.status = 'cancelled'; }
    configure(project) {
        const next = validateProject(project), old = new Map(this.project.nodes.map(n => [n.id, n]));
        if (this.project.nodes.some(n => !next.nodes.some(x => x.id === n.id))) this.cancel();
        this.project = next;
        for (const n of next.nodes) { if (n.profile === 'fortios' && n.device.saveMode === 'automatic') n.startup = configSnapshot(n); if (!old.has(n.id) || old.get(n.id).profile !== n.profile) this.resetDevice(n.id); else this.configurationChanged(old.get(n.id), n); }
        for (const id of this.runtime.keys()) if (!next.nodes.some(n => n.id === id)) { this.runtime.delete(id); this.consoles.delete(id); }
        this.revision++;
        refreshRouting(this);
    }
    rand() { this.seed = (Math.imul(this.seed, 1664525) + 1013904223) >>> 0; return this.seed / 4294967296; }
    log(node, action, p = {}, detail = '', port = '', link = '') {
        const item = {id: ++this.sequence, at: this.now, node: typeof node === 'string' ? node : node.id, port, action, packet: p.id || '', protocol: p.protocol || p.type || '', src: p.src || p.senderIP || '', dst: p.dst || p.targetIP || '', sport: p.sport || 0, dport: p.dport || 0, flags: p.flags || '', detail, link};
        if (p.vpn) item.detail = `${detail} [VPN 외부 UDP · ${p.vpn.type}]`;
        if (p.ipsec) item.detail = `${detail} [IPsec 외부 ${p.protocol} · ${p.ipsec.kind}]`;
        this.trace.push(item); if (this.trace.length > LIMITS.trace) this.trace.shift();
        const capture = this.rt(item.node)?.capture;
        if (capture && (!capture.port || capture.port === port) && (!capture.protocol || capture.protocol === item.protocol) && (!capture.host || capture.host === item.src || capture.host === item.dst) && (!capture.filterPort || capture.filterPort === item.sport || capture.filterPort === item.dport)) this.say(item.node, `${(item.at / 1000).toFixed(3)} ${port} ${item.protocol} ${item.src}:${item.sport} > ${item.dst}:${item.dport} ${item.flags} ${item.detail}`);
    }
    say(node, text, clear = false) { this.messages.push({node: typeof node === 'string' ? node : node.id, text, ...(clear ? {clear: true} : {})}); if (this.messages.length > 500) this.messages.shift(); }
    drain() { const messages = this.messages; this.messages = []; return messages; }
    schedule(delay, type, data) {
        if (this.queue.length >= LIMITS.queue) { this.pausedReason = '대기 사건 한도에 도달했습니다. 루프·트래픽을 확인하고 실행을 초기화하세요.'; return; }
        this.queue.push({at: this.now + Math.max(0, Math.ceil(delay)), order: ++this.sequence, type, data});
    }
    step(count = 1) {
        for (let i = 0; i < count && this.queue.length && !this.pausedReason; i++) {
            if (++this.processed > LIMITS.events) { this.pausedReason = '실행 사건 한도에 도달했습니다. L2 루프 여부를 확인하세요.'; break; }
            const e = this.queue.pop(); this.now = e.at;
            if (e.type === 'frame') this.receiveFrame(e.data);
            else if (e.type === 'windowsLocal') this.local(this.node(e.data.node), {name: 'Loopback'}, e.data.packet);
            else if (e.type === 'arpRetry') this.arpRetry(e.data);
            else if (e.type === 'ndRetry') retryND(this, e.data);
            else if (e.type === 'routingConverge') convergeRouting(this, e.data);
            else if (e.type === 'probe') this.probe(e.data);
            else if (e.type === 'jobTimeout') this.timeout(e.data);
            else if (e.type === 'tcpRetry') this.retryTCP(e.data);
            else if (e.type === 'vpnRetry') this.retryVPN(e.data);
            else if (e.type === 'ipsecRetry') retryIPsec(this, e.data);
        }
        return this.queue.length > 0 && !this.pausedReason;
    }
    run() { while (this.step(1000)) { /* bounded by LIMITS.events */ } return this; }
    cancel() { this.queue = new EventQueue(); this.routingStamp = ''; this.pausedReason = ''; this.processed = 0; for (const j of this.jobs.values()) if (j.status === 'pending') { j.status = 'cancelled'; this.say(j.node, '^C'); } for (const rt of this.runtime.values()) { rt.waiting.clear(); rt.ndWaiting.clear(); rt.capture = null; rt.vpn.clear(); rt.ipsec.clear(); } }
    execute(id, line) {
        const n = this.node(id), s = session(this, n), previous = s.status || 0; s.status = 0;
        try { if (typeof line !== 'string' || line.length > 2048 || /[\r\n\x00]/.test(line)) throw new Error('명령은 한 줄 2048자 이내입니다.'); if (line.trim()) { s.history.push(line); s.history = s.history.slice(-100); } const output = deviceCommand(this, n, line, previous); if (output) this.say(id, output); return output; }
        catch (error) { s.status = 2; throw error; }
        finally { refreshRouting(this); }
    }
    export() { return clone(this.project); }
    state() {
        return {project: this.export(), now: this.now, queued: this.queue.length, processed: this.processed, pausedReason: this.pausedReason, lastLink: this.lastLink, trace: this.trace.slice(-150), runtime: this.project.nodes.map(n => {
            const r = this.rt(n); return {id: n.id, nd: [...r.nd.values()].filter(a => a.until > this.now), dynamic: r.dynamic, ospf: r.ospf, bgp: r.bgp, cli: {prompt: prompt(this, n), mode: session(this, n).mode, shell: session(this, n).shell || '', pending: !!session(this, n).pending}, arp: [...r.arp.values()].filter(a => a.until > this.now), fdb: [...r.fdb.values()].filter(a => a.until > this.now), conn: [...r.conn.values()].filter(c => c.until > this.now).slice(-40), nat: r.nat.filter(c => c.until > this.now), vpn: [...r.vpn.values()].map(v => ({interface: v.interface, publicKey: v.publicKey, handshake: v.handshake, endpoint: v.endpoint})), ipsec: n.profile === 'fortios' ? this.ipsecStatus(n) : [], tcp: [...r.tcp.values()].slice(-20)};
        })};
    }
    routes(n, dynamic = true) {
        const available = n.interfaces.filter(f => f.up && (n.profile === 'linux' || this.portUp(n, f)) && !(n.profile === 'ios' && n.type === 'switch' && f.kind === 'ethernet' && f.switchport !== false));
        const routes = available.filter(f => f.address).map(f => ({dest: `${ipString(cidr(f.address).network)}/${cidr(f.address).bits}`, via: '', dev: f.name, metric: 0, connected: true}));
        return [...routes, ...n.routes.map(r => r.dev ? r : {...r, dev: available.find(f => f.address && r.via && contains(f.address, r.via))?.name || ''}), ...(dynamic ? this.rt(n).dynamic : [])].filter(r => available.some(f => f.name === r.dev));
    }
    route(n, dst) { const metric = r => r.metric + (n.profile === 'windows' ? winAdapter(n, n.interfaces.find(f => f.name === r.dev)).metric : 0); const distance = r => r.connected ? 0 : r.distance ?? 1; return this.routes(n).filter(r => contains(r.dest, dst)).sort((a, b) => cidr(b.dest).bits - cidr(a.dest).bits || distance(a) - distance(b) || metric(a) - metric(b))[0]; }
    packet(protocol, src, dst, extras = {}) { return {id: `p${++this.sequence}`, protocol, src, dst, ttl: 64, sport: 0, dport: 0, size: 84, ...extras}; }
    sendFrame(n, f, frame) {
        if (f.kind === 'subinterface') { if (!f.up || !f.encapsulated) return; return this.sendFrame(n, n.interfaces.find(p => p.name === f.parent), {...frame, tag: f.vlanId}); }
        if (f.kind === 'vlan') {
            if (!this.portUp(n, f)) return;
            const learned = this.rt(n).fdb.get(`${f.vlanId}/${frame.dstMAC}`);
            for (const out of n.interfaces.filter(p => p.kind === 'ethernet' && p.switchport !== false && (p.mode === 'access' ? p.vlan === f.vlanId : p.trunkAll || p.allowedVlans.includes(f.vlanId)))) if (!learned || learned.until <= this.now || learned.port === out.name) this.sendFrame(n, out, {...frame, tag: out.mode === 'trunk' && out.vlan !== f.vlanId ? f.vlanId : null});
            return;
        }
        const link = this.project.links.find(l => (l.a.node === n.id && l.a.port === f.name) || (l.b.node === n.id && l.b.port === f.name));
        const p = frame.packet || frame;
        if (!f.up || !link?.up) { this.log(n, 'drop', p, '포트 또는 링크가 내려가 있습니다.', f.name); return; }
        if (this.rand() * 100 < link.loss) { this.log(n, 'drop', p, '링크 손실 모델', f.name, link.id); return; }
        const target = link.a.node === n.id ? link.b : link.a;
        const key = `${link.id}/${n.id}`, duration = Math.max(1, Math.ceil((p.size || 84) * 8 / (link.rate * 1000))), free = Math.max(this.now, this.busy.get(key) || 0);
        if (free - this.now > duration * 32) { this.log(n, 'drop', p, '링크 송신 큐 초과', f.name, link.id); return; }
        this.busy.set(key, free + duration); this.lastLink = link.id;
        this.log(n, 'tx', p, frame.type === 'ARP' ? frame.op : `Ethernet ${frame.srcMAC} → ${frame.dstMAC}${frame.tag ? ` VLAN ${frame.tag}` : ''}`, f.name, link.id);
        this.schedule(free - this.now + duration + link.delay, 'frame', {target, frame: clone(frame), hops: (frame.hops || 0) + 1});
    }
    receiveFrame({target, frame, hops}) {
        const n = this.node(target.node), f = n.interfaces.find(f => f.name === target.port);
        if (!f?.up) return;
        if (hops > 128) { this.pausedReason = 'L2 프레임이 128회 이상 전달되었습니다. 루프를 해소한 뒤 실행을 초기화하세요.'; return; }
        frame.hops = hops; const p = frame.packet || frame;
        this.log(n, 'rx', p, frame.type === 'ARP' ? `ARP ${frame.op}` : '프레임 수신', f.name);
        if (n.type === 'hub') { for (const out of n.interfaces) if (out.name !== f.name) this.sendFrame(n, out, frame); return; }
        if (n.type === 'switch' && !(n.profile === 'ios' && f.switchport === false)) {
            const vlan = frame.tag ?? f.vlan;
            if ((f.mode === 'access' && frame.tag !== null && frame.tag !== undefined) || (f.mode === 'trunk' && !f.trunkAll && !f.allowedVlans.includes(vlan)) || (n.profile === 'ios' && !n.device.vlans.some(v => v.id === vlan))) { this.log(n, 'drop', p, '수신 VLAN 불일치·미생성 VLAN', f.name); return; }
            this.rt(n).fdb.set(`${vlan}/${frame.srcMAC}`, {mac: frame.srcMAC, vlan, port: f.name, until: this.now + 300000});
            const svi = n.interfaces.find(v => v.kind === 'vlan' && v.vlanId === vlan && this.portUp(n, v));
            if (svi && (frame.dstMAC === svi.mac || (frame.type === 'ARP' && frame.targetIP === address(svi)))) { this.receiveEthernet(n, svi, frame); if (frame.dstMAC !== BROADCAST) return; }
            const learned = this.rt(n).fdb.get(`${vlan}/${frame.dstMAC}`);
            const port = learned?.until > this.now ? learned.port : null;
            for (const out of n.interfaces) {
                if (out.kind !== 'ethernet' || out.switchport === false || out.name === f.name || (port && out.name !== port) || (out.mode === 'access' ? out.vlan !== vlan : !out.trunkAll && !out.allowedVlans.includes(vlan))) continue;
                this.sendFrame(n, out, {...frame, tag: out.mode === 'trunk' && (n.profile === 'linux' || out.vlan !== vlan) ? vlan : null});
            }
            return;
        }
        const sub = n.interfaces.find(v => v.kind === 'subinterface' && v.encapsulated && v.parent === f.name && v.vlanId === frame.tag && v.up);
        if (sub) { this.receiveEthernet(n, sub, frame); return; }
        if (frame.tag != null && frame.tag !== f.vlan) { this.log(n, 'drop', p, '인터페이스 VLAN 불일치', f.name); return; }
        this.receiveEthernet(n, f, frame);
    }
    receiveEthernet(n, f, frame) {
        if (frame.type === 'IPv6') { if (frame.dstMAC === f.mac || frame.dstMAC.startsWith('33:33:')) receive6(this, n, f, frame); return; }
        if (frame.dstMAC !== f.mac && frame.dstMAC !== BROADCAST) return;
        if (frame.type === 'ARP') {
            if (frame.senderIP !== '0.0.0.0') this.learnARP(n, f, frame.senderIP, frame.srcMAC);
            if (frame.op === 'request' && address(f) === frame.targetIP) this.sendFrame(n, f, {type: 'ARP', protocol: 'ARP', op: 'reply', srcMAC: f.mac, dstMAC: frame.srcMAC, senderIP: address(f), targetIP: frame.senderIP, tag: null});
        } else this.receiveIP(n, f, frame.packet);
    }
    learnARP(n, f, ip, mac) {
        const key = `${f.name}/${ip}`; this.rt(n).arp.set(key, {port: f.name, ip, mac, until: this.now + 30000});
        const pending = this.rt(n).waiting.get(key);
        if (pending) { this.rt(n).waiting.delete(key); for (const p of pending.packets) this.sendFrame(n, f, {type: 'IPv4', srcMAC: f.mac, dstMAC: mac, tag: null, packet: p}); }
    }
    arpRetry({node, port, ip, attempt}) {
        const n = this.node(node), f = n.interfaces.find(f => f.name === port), key = `${port}/${ip}`;
        const waiting = this.rt(n).waiting.get(key); if (!waiting) return;
        if (attempt >= 3 || !f?.up) { this.rt(n).waiting.delete(key); for (const p of waiting.packets) this.log(n, 'drop', p, `ARP 응답 없음: ${ip}`, port); return; }
        this.sendFrame(n, f, {type: 'ARP', protocol: 'ARP', op: 'request', srcMAC: f.mac, dstMAC: BROADCAST, senderIP: address(f) || '0.0.0.0', targetIP: ip, tag: null});
        this.schedule(800, 'arpRetry', {node, port, ip, attempt: attempt + 1});
    }
    transmit(n, p, generated = false, ingress = '') {
        if (p.family === 6) return send6(this, n, p, generated);
        if (n.profile === 'windows' && generated && (p.dst === '127.0.0.1' || n.interfaces.some(f => f.up && f.address && cidr(f.address).address === p.dst))) { const local = {...clone(p), src: p.src || p.dst}; this.log(n, 'loopback', local, 'Windows 호스트 내부 IPv4 전달'); this.schedule(0, 'windowsLocal', {node: n.id, packet: local}); return; }
        const r = this.route(n, p.dst), f = n.interfaces.find(f => f.name === r?.dev);
        if (!r || !f || (!f.address && f.kind !== 'ipsec')) { this.log(n, 'drop', p, '목적지 경로 또는 출발 인터페이스 주소가 없습니다.'); return; }
        p = clone(p); if (!p.src || p.src === '0.0.0.0') p.src = address(f) || (f.kind === 'ipsec' ? address(n.interfaces.find(i => i.name === n.device.phase1.find(v => v.name === f.name)?.interface)) : '');
        if (!p.src) { this.log(n, 'drop', p, '출발 IPv4 주소가 없습니다.', f.name); return; }
        if (!this.filter(n, generated ? 'output' : 'forward', p, ingress, f.name)) return;
        p = this.nat(n, 'postrouting', p, ingress, f.name); if (!p) return;
        if (f.kind === 'ipsec') { if (p.ipsec) { this.log(n, 'drop', p, 'IPsec endpoint 경로가 터널로 되돌아갑니다.'); return; } ipsecTransmit(this, n, f, p); return; }
        if (f.kind === 'wireguard') { if (p.vpn) { this.log(n, 'drop', p, 'VPN endpoint 경로가 터널로 되돌아갑니다.'); return; } this.tunnel(n, f, p); return; }
        const nextHop = r.via || p.dst;
        if (r.via && !contains(f.address, nextHop)) { this.log(n, 'drop', p, '게이트웨이가 직접 연결 서브넷에 없습니다.', f.name); return; }
        const key = `${f.name}/${nextHop}`, cached = this.rt(n).arp.get(key);
        if (cached?.until > this.now) this.sendFrame(n, f, {type: 'IPv4', srcMAC: f.mac, dstMAC: cached.mac, tag: null, packet: p});
        else {
            let pending = this.rt(n).waiting.get(key);
            if (!pending) { pending = {packets: []}; this.rt(n).waiting.set(key, pending); this.schedule(0, 'arpRetry', {node: n.id, port: f.name, ip: nextHop, attempt: 0}); }
            if (pending.packets.length < 32) pending.packets.push(p); else this.log(n, 'drop', p, 'ARP 대기 큐 초과', f.name);
        }
    }
    receiveIP(n, f, packet) {
        if (!iosACL(this, n, 'in', f.name, packet)) return;
        let p = this.nat(n, 'prerouting', clone(packet), f.name, '');
        if (p.dhcp && p.protocol === 'UDP') { if (this.filter(n, 'input', p, f.name, '')) this.dhcpReceive(n, f, p); return; }
        if (n.interfaces.some(i => i.up && address(i) === p.dst)) {
            if (!this.filter(n, 'input', p, f.name, '')) return;
            this.local(n, f, p); return;
        }
        if (!n.forwarding) { this.log(n, 'drop', p, 'IP forwarding이 꺼져 있습니다.', f.name); return; }
        if (p.ttl <= 1) { this.log(n, 'drop', p, 'TTL 만료', f.name); this.icmpError(n, p, 'time-exceeded'); return; }
        p.ttl--; this.log(n, 'route', p, '라우터 전달', f.name); this.transmit(n, p, false, f.name);
    }
    matches(rule, p, ingress, egress, state) {
        return Object.entries(rule.match).every(([key, value]) => {
            if (key === 'src' || key === 'dst') return contains(value, p[key]);
            if (key === 'iif') return value === ingress;
            if (key === 'oif') return value === egress;
            if (key === 'state') return value.includes(state);
            return p[key] === value;
        });
    }
    connectionState(n, p) {
        const rt = this.rt(n), key = tuple(p), back = rt.conn.get(reverse(p));
        if (p.icmp === 'error' && p.quoted && (rt.conn.get(tuple(p.quoted))?.until > this.now || rt.conn.get(reverse(p.quoted))?.until > this.now)) return 'related';
        const own = rt.conn.get(key);
        const established = back?.until > this.now || (own?.until > this.now && own.state === 'established');
        const state = established ? 'established' : 'new';
        rt.conn.set(key, {...own, tuple: key, state, until: this.now + 60000});
        if (back) back.state = 'established';
        if (rt.conn.size > 256) rt.conn.delete(rt.conn.keys().next().value);
        return state;
    }
    filter(n, hook, p, ingress, egress) {
        const state = this.connectionState(n, p);
        if (!deviceFilter(this, n, hook, p, ingress, egress)) return false;
        for (const c of n.chains.filter(c => c.type === 'filter' && c.hook === hook).sort((a, b) => a.priority - b.priority)) {
            const rule = n.rules.find(r => r.family === c.family && r.table === c.table && r.chain === c.name && this.matches(r, p, ingress, egress, state));
            const verdict = rule?.action || c.policy;
            this.log(n, verdict === 'accept' ? 'allow' : 'drop', p, `${hook} ${c.table}/${c.name} ${rule ? `handle ${rule.handle}` : 'policy'}: ${verdict} · ${state}`, ingress || egress);
            if (verdict !== 'accept') { if (verdict === 'reject' && p.icmp !== 'error') this.icmpError(n, p, 'admin-prohibited'); return false; }
        }
        return true;
    }
    nat(n, hook, packet, ingress, egress) {
        const rt = this.rt(n); rt.nat = rt.nat.filter(b => b.until > this.now);
        let p = clone(packet);
        if (hook === 'prerouting') {
            const binding = rt.nat.find(b => reverse(b.after) === tuple(p));
            if (binding) { p = {...p, src: binding.before.dst, dst: binding.before.src, sport: binding.before.dport, dport: binding.before.sport}; binding.until = this.now + 60000; this.log(n, 'nat', p, '기존 NAT binding의 응답 변환', ingress); return p; }
        }
        const existing = rt.nat.find(b => b.hook === hook && tuple(b.before) === tuple(p));
        if (existing) return {...p, src: existing.after.src, dst: existing.after.dst, sport: existing.after.sport, dport: existing.after.dport};
        const vendorRule = deviceNAT(this, n, hook, p, ingress, egress);
        const candidates = n.chains.filter(c => c.type === 'nat' && c.hook === hook).sort((a, b) => a.priority - b.priority).map(c => n.rules.find(r => r.family === c.family && r.table === c.table && r.chain === c.name && this.matches(r, p, ingress, egress, 'new')));
        for (const rule of [vendorRule, ...candidates]) {
            if (!rule) continue;
            if (!['masquerade', 'snat', 'dnat'].includes(rule.action)) continue;
            const before = clone(p);
            if (rule.action === 'masquerade' || rule.action === 'snat') {
                p.src = rule.action === 'masquerade' ? address(n.interfaces.find(f => f.name === egress)) : rule.to.split(':')[0];
                if (!p.src) { this.log(n, 'drop', before, 'SNAT 출발 주소가 없습니다. IPsec 경로의 NAT 설정을 확인하세요.', egress); return null; }
                p.sport = rule.to.includes(':') ? Number(rule.to.split(':')[1]) : ++this.portSequence;
                if (p.protocol === 'ICMP') p.dport = p.sport;
            } else { const [ip, port] = rule.to.split(':'); p.dst = ip; if (port) p.dport = Number(port); }
            rt.nat.push({hook, before, after: clone(p), until: this.now + 60000}); if (rt.nat.length > 128) rt.nat.shift();
            this.log(n, 'nat', p, `handle ${rule.handle}: ${before.src}:${before.sport} → ${p.src}:${p.sport}, ${before.dst}:${before.dport} → ${p.dst}:${p.dport}`, egress || ingress);
            break;
        }
        return p;
    }
    icmpError(n, p, reason) {
        if (p.protocol === 'ICMP' && p.icmp === 'error') return;
        this.transmit(n, this.packet('ICMP', '', p.src, {icmp: 'error', reason, quoted: {src: p.src, dst: p.dst, protocol: p.protocol, sport: p.sport, dport: p.dport, job: p.job, probe: p.probe}}), true);
    }
    local(n, f, p) {
        this.log(n, 'local', p, '목적지 프로토콜 처리', f.name);
        if (p.ipsec) { ipsecReceive(this, n, f, p); return; }
        if (p.protocol === 'ICMP' || p.protocol === 'ICMPv6') {
            if (p.icmp === 'echo') this.transmit(n, this.packet(p.protocol, p.dst, p.src, {icmp: 'reply', sport: p.dport, dport: p.sport, job: p.job, probe: p.probe, ...(p.family === 6 ? {family: 6, size: p.size} : {})}), true);
            else {
                const j = this.jobs.get(p.job || p.quoted?.job); if (!j || j.status !== 'pending') return;
                const probe = p.probe ?? p.quoted?.probe;
                if (p.icmp === 'reply') { if (!j.results.some(r => r.probe === probe)) j.results.push({probe, ip: p.src, ok: true, time: this.now - (j.sent[probe] || j.start), ...(j.options?.windows ? {ttl: p.ttl} : {})}); }
                else { j.errors.push(`${p.src}: ${p.reason}`); if (j.type === 'trace' && !j.results.some(r => r.probe === probe)) j.results.push({probe, ip: p.src, ok: false, time: this.now - (j.sent[probe] || j.start)}); }
                if (j.type === 'ping' && j.results.length === j.count) this.finish(j);
            }
        } else if (p.protocol === 'UDP') {
            if (p.vpn) this.vpnReceive(n, p);
            else if (p.dns?.kind === 'query' && p.dport === 53 && n.services.dns) {
                const name = p.dns.name.toLowerCase(), value = Object.hasOwn(n.services.dns.records, name) ? n.services.dns.records[name] : '';
                this.transmit(n, this.packet('UDP', p.dst, p.src, {sport: 53, dport: p.sport, job: p.job, dns: {kind: 'answer', name: p.dns.name, value}}), true);
            } else if (p.dns?.kind === 'answer') {
                const j = this.jobs.get(p.job); if (!j || j.status !== 'pending' || j.node !== n.id || j.port !== p.dport || j.dest !== p.src) return;
                j.answered = true; j.value = p.dns.value; if (j.value) this.rt(n).dns.set(p.dns.name, {value: j.value, until: this.now + 60000}); this.finish(j);
            } else this.icmpError(n, p, 'port-unreachable');
        } else if (p.protocol === 'TCP') this.tcpReceive(n, p);
    }
    job(node, type, dest, extra = {}) {
        if ([...this.jobs.values()].filter(j => j.status === 'pending').length >= 20) throw new Error('진행 중인 통신은 최대 20개입니다.');
        const j = {id: `j${++this.sequence}`, node: node.id, type, dest, start: this.now, status: 'pending', results: [], errors: [], sent: {}, ...extra};
        this.jobs.set(j.id, j); if (this.jobs.size > 100) { const old = [...this.jobs.values()].find(j => j.status !== 'pending'); if (old) this.jobs.delete(old.id); }
        return j;
    }
    start(n, type, dest, options = {}) {
        if (n.profile === 'windows' && String(dest).toLowerCase() === 'localhost') dest = '127.0.0.1';
        try { ipv4(dest); } catch {
            if (!/^[a-z0-9][a-z0-9.-]{0,100}$/i.test(dest)) throw new Error('주소 또는 호스트 이름을 확인하세요.');
            const cached = this.rt(n).dns.get(dest.toLowerCase());
            if (cached?.until > this.now) return this.start(n, type, cached.value, options);
            return this.startDNS(n, dest, n.profile === 'windows' ? windowsResolver(n, f => this.portUp(n, f)) : n.resolver, {type, options});
        }
        if (type === 'curl' || type === 'tcp') return this.startTCP(n, dest, {...options, connectOnly: type === 'tcp'});
        const count = type === 'trace' ? options.hops || 8 : options.count || 1;
        const j = this.job(n, type, dest, {count, options});
        for (let i = 1; i <= count; i++) this.schedule((i - 1) * 100, 'probe', {job: j.id, probe: i});
        this.schedule(4000 + count * 100, 'jobTimeout', j.id);
        const header = windowsJobHeader(type, dest, options); if (header === null) this.say(n, type === 'trace' ? `traceroute -I to ${dest}, 최대 8홉 (ICMP 모델)` : `PING ${dest} (가상 시간)`); else if (header) this.say(n, header); return j;
    }
    probe({job, probe}) {
        const j = this.jobs.get(job); if (j?.status !== 'pending') return;
        if (j.type === 'trace' && j.results.some(r => r.ok)) return;
        j.sent[probe] = this.now;
        if (j.options?.ipv6) { this.transmit(this.node(j.node), this.packet('ICMPv6', '', j.dest, {family: 6, icmp: 'echo', size: 48 + j.options.size, dev: j.options.dev, ttl: j.type === 'trace' ? probe : 64, job, probe}), true); return; }
        this.transmit(this.node(j.node), this.packet('ICMP', '', j.dest, {icmp: 'echo', sport: Number(j.id.slice(1)), dport: Number(j.id.slice(1)), ttl: j.type === 'trace' ? probe : 64, job, probe}), true);
    }
    startDNS(n, name, server, after = null) {
        if (!server) throw new Error('DNS 서버가 없습니다. resolvectl dns eth0 <IPv4>로 설정하세요.'); ipv4(server);
        const j = this.job(n, 'dns', server, {name: name.toLowerCase(), port: ++this.portSequence, after});
        this.transmit(n, this.packet('UDP', '', server, {sport: j.port, dport: 53, job: j.id, dns: {kind: 'query', name: j.name}}), true);
        this.schedule(4000, 'jobTimeout', j.id); return j;
    }
    startTCP(n, dst, {port = 80, path = '/', connectOnly = false, ...options} = {}) {
        const j = this.job(n, connectOnly ? 'tcp' : 'curl', dst, {port, sourcePort: ++this.portSequence, path, options, retries: 0, tcpState: 'SYN-SENT'});
        j.lastPacket = this.packet('TCP', '', dst, {sport: j.sourcePort, dport: port, flags: 'S', seq: 100, ack: 0, job: j.id});
        this.transmit(n, j.lastPacket, true); this.schedule(1000, 'tcpRetry', j.id); this.schedule(9000, 'jobTimeout', j.id);
        return j;
    }
    retryTCP(id) { const j = this.jobs.get(id); if (!j || j.status !== 'pending') return; if (++j.retries <= 3) { this.log(j.node, 'retry', j.lastPacket, `TCP 재전송 ${j.retries}`); this.transmit(this.node(j.node), j.lastPacket, true); this.schedule(1000 * 2 ** j.retries, 'tcpRetry', id); } }
    tcpReceive(n, p) {
        const j = this.jobs.get(p.job), key = `${p.src}:${p.sport}/${p.dst}:${p.dport}`;
        if (j?.node === n.id && j.status === 'pending' && p.dport === j.sourcePort && p.src === j.dest) {
            if (p.flags.includes('R')) { j.errors.push('Connection refused (TCP RST)'); this.finish(j); return; }
            if (p.flags === 'SA') {
                j.tcpState = 'ESTABLISHED';
                this.transmit(n, this.packet('TCP', p.dst, p.src, {sport: p.dport, dport: p.sport, flags: 'A', seq: 101, ack: 501, job: j.id}), true);
                if (j.type === 'tcp') { j.connected = true; this.transmit(n, this.packet('TCP', p.dst, p.src, {sport: p.dport, dport: p.sport, flags: 'FA', seq: 101, ack: 501, job: j.id}), true); this.finish(j); return; }
                j.lastPacket = this.packet('TCP', p.dst, p.src, {sport: p.dport, dport: p.sport, flags: 'PA', seq: 101, ack: 501, job: j.id, request: j.path, size: 160});
                this.transmit(n, j.lastPacket, true);
            } else if (p.response !== undefined) {
                j.response = p.response; j.tcpState = 'FIN-WAIT-1';
                this.transmit(n, this.packet('TCP', p.dst, p.src, {sport: p.dport, dport: p.sport, flags: 'FA', seq: 102, ack: p.seq + p.response.length, job: j.id}), true); this.finish(j);
            }
            return;
        }
        const rt = this.rt(n);
        if (p.flags === 'S') {
            if (!n.services.http || n.services.http.port !== p.dport) { this.transmit(n, this.packet('TCP', p.dst, p.src, {sport: p.dport, dport: p.sport, flags: 'RA', ack: p.seq + 1, job: p.job}), true); return; }
            rt.tcp.set(key, {remote: `${p.src}:${p.sport}`, localPort: p.dport, state: 'SYN-RECEIVED', until: this.now + 60000});
            if (rt.tcp.size > 40) rt.tcp.delete(rt.tcp.keys().next().value);
            this.transmit(n, this.packet('TCP', p.dst, p.src, {sport: p.dport, dport: p.sport, flags: 'SA', seq: 500, ack: p.seq + 1, job: p.job}), true);
        } else if (rt.tcp.has(key)) {
            const socket = rt.tcp.get(key); if (socket.until < this.now) { rt.tcp.delete(key); return; }
            if (p.flags.includes('A')) socket.state = 'ESTABLISHED';
            if (p.request !== undefined) this.transmit(n, this.packet('TCP', p.dst, p.src, {sport: p.dport, dport: p.sport, flags: 'PA', seq: 501, ack: p.seq + p.request.length, job: p.job, response: n.services.http?.body || '', size: 200 + (n.services.http?.body.length || 0)}), true);
            if (p.flags.includes('F')) { socket.state = 'CLOSE-WAIT'; this.transmit(n, this.packet('TCP', p.dst, p.src, {sport: p.dport, dport: p.sport, flags: 'FA', seq: 502, ack: p.seq + 1, job: p.job}), true); socket.state = 'CLOSED'; }
        }
    }
    broadcast(n, f, dhcp, job) { const packet = this.packet('UDP', address(f) || '0.0.0.0', '255.255.255.255', {sport: dhcp.kind === 'discover' || dhcp.kind === 'request' ? 68 : 67, dport: dhcp.kind === 'discover' || dhcp.kind === 'request' ? 67 : 68, dhcp, job}); if (n.profile === 'windows' && !this.filter(n, 'output', packet, '', f.name)) return; this.sendFrame(n, f, {type: 'IPv4', srcMAC: f.mac, dstMAC: BROADCAST, tag: null, packet}); }
    startDHCP(n, port) { const f = n.interfaces.find(f => f.name === port && f.kind === 'ethernet'); if (!f?.up) throw new Error('켜진 Ethernet 인터페이스가 필요합니다.'); const j = this.job(n, 'dhcp', '', {port}); this.broadcast(n, f, {kind: 'discover', mac: f.mac}, j.id); this.schedule(4000, 'jobTimeout', j.id); return j; }
    dhcpReceive(n, f, p) {
        const d = p.dhcp, service = n.services.dhcp, rt = this.rt(n);
        if (service && (d.kind === 'discover' || d.kind === 'request')) {
            const leases = rt.leases; for (const [mac, lease] of leases) if (lease.until < this.now) leases.delete(mac);
            let chosen = leases.get(d.mac)?.ip;
            if (!chosen) { for (let ip = ipv4(service.start); ip <= ipv4(service.end); ip++) { const candidate = ipString(ip); if (!n.interfaces.some(f => address(f) === candidate) && ![...leases.values()].some(l => l.ip === candidate)) { chosen = candidate; break; } } }
            if (!chosen || (d.kind === 'request' && (d.server !== address(f) || d.address !== chosen))) return;
            leases.set(d.mac, {ip: chosen, until: this.now + 3600000});
            this.broadcast(n, f, {kind: d.kind === 'discover' ? 'offer' : 'ack', mac: d.mac, address: chosen, server: address(f), prefix: service.prefix, gateway: service.gateway, dns: service.dns}, p.job);
        } else if (d.mac === f.mac) {
            const j = this.jobs.get(p.job); if (j?.status !== 'pending' || j.node !== n.id || j.port !== f.name) return;
            if (d.kind === 'offer') { j.server = d.server; this.broadcast(n, f, {...d, kind: 'request'}, p.job); }
            if (d.kind === 'ack' && d.server === j.server) { f.address = `${d.address}/${d.prefix}`; n.resolver = d.dns; if (n.profile === 'windows') { const a = winAdapter(n, f); a.leaseDns = d.dns; if (a.dnsAutomatic) a.dns = d.dns; n.resolver = windowsResolver(n); } n.routes = n.routes.filter(r => r.dest !== '0.0.0.0/0' || (n.profile === 'windows' && r.dev !== f.name)); n.routes.push({dest: '0.0.0.0/0', via: d.gateway, dev: f.name, metric: 0}); this.revision++; j.value = f.address; this.finish(j); }
        }
    }
    vpnState(n, f, peer) {
        const key = `${f.name}/${peer.publicKey}`; let v = this.rt(n).vpn.get(key);
        if (!v) { v = {interface: f.name, publicKey: peer.publicKey, handshake: null, endpoint: peer.endpoint, pending: [], attempting: false}; this.rt(n).vpn.set(key, v); } return v;
    }
    tunnel(n, f, p) {
        if (!f.privateKey) { this.log(n, 'drop', p, 'VPN 가상 개인키가 없습니다.', f.name); return; }
        const peer = f.peers.filter(peer => peer.allowedIPs.some(net => contains(net, p.dst))).sort((a, b) => Math.max(...b.allowedIPs.filter(net => contains(net, p.dst)).map(net => cidr(net).bits)) - Math.max(...a.allowedIPs.filter(net => contains(net, p.dst)).map(net => cidr(net).bits)))[0];
        if (!peer) { this.log(n, 'drop', p, '목적지에 해당하는 AllowedIPs 피어가 없습니다.', f.name); return; }
        const v = this.vpnState(n, f, peer);
        if (v.handshake !== null && this.now - v.handshake < 120000) { this.outer(n, f, peer, 'data', p); return; }
        if (v.pending.length < 32) v.pending.push(p);
        if (!v.attempting) { v.attempting = true; this.outer(n, f, peer, 'init'); this.schedule(1000, 'vpnRetry', {node: n.id, port: f.name, peer: peer.publicKey, attempt: 1}); }
    }
    outer(n, f, peer, type, inner = null, override = '') {
        const v = this.vpnState(n, f, peer), remote = override || v.endpoint || peer.endpoint;
        if (!remote) { this.log(n, 'drop', inner || {}, 'VPN endpoint가 없습니다.', f.name); return; }
        const e = endpoint(remote);
        const outer = this.packet('UDP', '', e.ip, {sport: f.listenPort, dport: e.port, vpn: {type, sender: publicKey(f.privateKey), target: peer.publicKey, inner: inner ? clone(inner) : null}, size: (inner?.size || 80) + 80});
        this.log(n, 'vpn', outer, `${f.name}: ${type}`, f.name); this.transmit(n, outer, true);
    }
    retryVPN({node, port, peer, attempt}) {
        const n = this.node(node), f = n.interfaces.find(f => f.name === port), p = f?.peers.find(p => p.publicKey === peer); if (!p) return;
        const v = this.vpnState(n, f, p); if (v.handshake !== null && this.now - v.handshake < 120000) return;
        if (attempt >= 3) { v.pending = []; v.attempting = false; this.log(n, 'drop', {}, 'VPN 핸드셰이크 응답 없음: 키·endpoint·외부 경로·UDP 방화벽을 확인하세요.', port); return; }
        this.outer(n, f, p, 'init'); this.schedule(1000, 'vpnRetry', {node, port, peer, attempt: attempt + 1});
    }
    vpnReceive(n, p) {
        const f = n.interfaces.find(f => f.kind === 'wireguard' && f.up && f.privateKey && f.listenPort === p.dport && publicKey(f.privateKey) === p.vpn.target);
        const peer = f?.peers.find(peer => peer.publicKey === p.vpn.sender);
        if (!peer) { this.log(n, 'drop', p, 'VPN 키 또는 수신 포트 불일치'); return; }
        const v = this.vpnState(n, f, peer); v.endpoint = `${p.src}:${p.sport}`;
        if (p.vpn.type === 'init' || p.vpn.type === 'reply') {
            v.handshake = this.now; v.attempting = false; this.log(n, 'vpn', p, `${f.name}: 가상 핸드셰이크 성립`, f.name);
            if (p.vpn.type === 'init') this.outer(n, f, peer, 'reply', null, v.endpoint);
            const pending = v.pending; v.pending = []; for (const inner of pending) this.outer(n, f, peer, 'data', inner);
        } else if (p.vpn.type === 'data') {
            const inner = p.vpn.inner;
            if (v.handshake === null || this.now - v.handshake >= 120000 || !peer.allowedIPs.some(net => contains(net, inner.src))) { this.log(n, 'drop', p, 'VPN 내부 출발 주소의 AllowedIPs 또는 핸드셰이크 불일치', f.name); return; }
            this.log(n, 'decap', inner, '터널 종단 내부 패킷 (교육용 모델)', f.name); this.receiveIP(n, f, inner);
        }
    }
    timeout(id) { const j = this.jobs.get(id); if (j?.status === 'pending') this.finish(j); }
    finish(j) {
        if (j.status !== 'pending') return; j.status = 'done'; const n = this.node(j.node);
        if (finishWindowsJob(this, n, j)) return;
        if (j.type === 'ping') { const replies = j.results.map(r => `${r.ip}: icmp_seq=${r.probe} time=${r.time} ms (가상)`); this.say(n, [...replies, ...j.errors, `${j.count} transmitted, ${j.results.length} received, ${Math.round((1 - j.results.length / j.count) * 100)}% packet loss`].join('\n')); }
        else if (j.type === 'trace') { const end = j.results.find(r => r.ok)?.probe || j.count; this.say(n, Array.from({length: end}, (_, i) => { const r = j.results.find(r => r.probe === i + 1); return `${i + 1}  ${r ? `${r.ip}  ${r.time} ms` : '* * *'}`; }).join('\n')); }
        else if (j.type === 'curl') this.say(n, j.response !== undefined ? `HTTP/1.1 200 OK\n\n${j.response}` : j.errors.join('\n') || 'curl: 연결 시간 초과. 경로·ARP·방화벽·서비스를 확인하세요.');
        else if (j.type === 'dhcp') this.say(n, j.value ? `DHCPACK: ${j.value} · 임대 3600초 (설정 저장, 갱신 타이머는 미지원)` : 'DHCP 응답 시간 초과. 같은 L2 영역의 DHCP 서버를 확인하세요.');
        else if (j.type === 'dns') {
            if (!j.after) this.say(n, j.value ? `${j.name}. 60 IN A ${j.value}` : 'DNS 응답 없음 또는 NXDOMAIN');
            else if (j.value) this.start(n, j.after.type, j.value, j.after.options);
            else this.say(n, `이름을 해석하지 못했습니다: ${j.name}`);
        }
    }
}
