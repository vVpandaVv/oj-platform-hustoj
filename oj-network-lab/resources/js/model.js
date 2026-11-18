import {normalizeDevice, configSnapshot} from './device-config.js?ver=1.4.0-rc.4';
import {normalizeWindows, windowsResolver} from './windows-config.js?ver=1.4.0-rc.4';
import {cidr6, ipv6} from './ipv6-address.js?ver=1.4.0-rc.4';
import {normalizeRouting} from './routing-config.js?ver=1.4.0-rc.4';
export const VERSION = '1.4.0-rc.4';
export const LIMITS = Object.freeze({nodes: 20, links: 40, interfaces: 10, rules: 64, events: 100000, queue: 20000, trace: 1000});
export const clone = value => JSON.parse(JSON.stringify(value));
export function ipv4(value) {
    if (typeof value !== 'string' || !/^(0|[1-9]\d{0,2})(\.(0|[1-9]\d{0,2})){3}$/.test(value)) throw new Error('올바른 IPv4 주소가 필요합니다.');
    const parts = value.split('.').map(Number);
    if (parts.some(n => n > 255)) throw new Error('IPv4 주소는 0~255 범위입니다.');
    return parts.reduce((n, part) => (n * 256 + part) >>> 0, 0);
}
export function cidr(value) {
    const [address, prefix, extra] = String(value).split('/');
    if (extra !== undefined || !/^([0-9]|[12][0-9]|3[0-2])$/.test(prefix ?? '')) throw new Error('주소/접두사 형식이 필요합니다. 예: 192.168.1.10/24');
    const ip = ipv4(address), bits = Number(prefix), mask = bits === 0 ? 0 : (0xffffffff << (32 - bits)) >>> 0;
    return {address, bits, ip, network: (ip & mask) >>> 0, mask};
}
export function contains(network, address) { const c = cidr(network); return ((ipv4(address) & c.mask) >>> 0) === c.network; }
export function ipString(number) { return [24, 16, 8, 0].map(shift => (number >>> shift) & 255).join('.'); }
export function text(value, max = 80) { if (typeof value !== 'string' || value.length > max || /[\x00-\x08\x0b\x0c\x0e-\x1f]/.test(value)) throw new Error('문자열 형식 또는 길이를 확인하세요.'); return value; }
export function token(value) { if (typeof value !== 'string' || !/^[a-zA-Z][a-zA-Z0-9_-]{0,23}$/.test(value)) throw new Error('이름은 영문자로 시작하는 영문·숫자·밑줄·하이픈 24자 이내입니다.'); return value; }
export function integer(value, min, max) { if (!Number.isInteger(value) || value < min || value > max) throw new Error(`정수 ${min}~${max} 범위가 필요합니다.`); return value; }
const oneOf = (v, values) => { if (!values.includes(v)) throw new Error('지원하지 않는 설정입니다.'); return v; };
const array = (v, max) => { if (!Array.isArray(v) || v.length > max) throw new Error('목록 형식 또는 최대 개수를 확인하세요.'); return v; };
export function endpoint(value) { const m = /^(\d+\.\d+\.\d+\.\d+):(\d+)$/.exec(value); if (!m) throw new Error('endpoint는 IPv4:포트 형식입니다.'); ipv4(m[1]); integer(Number(m[2]), 1, 65535); return {ip: m[1], port: Number(m[2])}; }
export const publicKey = privateKey => `sim-public:${privateKey}`;
export function newNode(type, index = 1) {
    oneOf(type, ['pc', 'server', 'hub', 'switch', 'router', 'firewall']);
    const count = ['hub', 'switch'].includes(type) ? 6 : ['router', 'firewall'].includes(type) ? 3 : 1;
    return {id: `n${index}`, name: `${type}-${index}`, type, x: 100 + ((index - 1) % 5) * 165, y: 90 + Math.floor((index - 1) / 5) * 150,
        forwarding: ['router', 'firewall'].includes(type), resolver: '', routes: [], tables: [], chains: [], rules: [],
        interfaces: Array.from({length: count}, (_, i) => ({name: `eth${i}`, address: '', mac: `02:00:00:${(index & 255).toString(16).padStart(2, '0')}:00:${i.toString(16).padStart(2, '0')}`, up: true, kind: 'ethernet', vlan: 1, mode: 'access', allowedVlans: [1]})),
        services: {http: null, dns: null, dhcp: null}};
}
export function validateProject(input) {
    if (!input || typeof input !== 'object' || ![1, 2].includes(input.schema)) throw new Error('지원하는 프로젝트 형식은 schema 1·2입니다.');
    if (![VERSION, '1.0.0-rc.1', '1.0.0-rc.2', '1.0.0-rc.3', '1.1.0-rc.1', '1.1.0-rc.2', '1.1.0-rc.3', '1.2.0-rc.1', '1.2.0-rc.2', '1.2.0-rc.3', '1.3.0-rc.1', '1.3.0-rc.2', '1.3.0-rc.3', '1.4.0-rc.1', '1.4.0-rc.2', '1.4.0-rc.3'].includes(input.engine)) throw new Error('이 파일의 엔진 버전이 다릅니다. 지원하는 버전으로 내보낸 설정을 사용하세요.');
    const nodes = array(input.nodes, LIMITS.nodes).map(raw => {
        const node = newNode(raw.type, 1);
        node.id = token(raw.id); node.name = text(raw.name, 40); node.x = integer(raw.x, 0, 1400); node.y = integer(raw.y, 0, 900);
        node.profile = oneOf(raw.profile || 'linux', ['linux', 'windows', 'ios', 'fortios']);
        if (node.profile !== 'linux' && !/^[A-Za-z][A-Za-z0-9_-]{0,31}$/.test(node.name)) throw new Error('장비 호스트 이름은 영문으로 시작하는 영문·숫자·하이픈·밑줄 32자 이내입니다.');
        if ((node.profile === 'windows' && !['pc', 'server'].includes(node.type)) || (node.profile === 'ios' && !['router', 'switch'].includes(node.type)) || (node.profile === 'fortios' && node.type !== 'firewall')) throw new Error('장비 종류와 CLI 프로필이 맞지 않습니다.');
        node.forwarding = raw.forwarding === true; node.resolver = raw.resolver ? (ipv4(raw.resolver), raw.resolver) : '';
        node.forwarding6 = raw.forwarding6 === true;
        if (node.forwarding6 && node.profile !== 'linux') throw new Error('IPv6는 Linux 학습 프로필에서 지원합니다.');
        node.interfaces = array(raw.interfaces, LIMITS.interfaces).map((f, i) => {
            token(f.name); if (f.address) cidr(f.address);
            if (!/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i.test(f.mac) || (parseInt(f.mac.slice(0, 2), 16) & 1)) throw new Error('유니캐스트 MAC 주소가 필요합니다.');
            const item = {name: f.name, address: f.address || '', mac: f.mac.toLowerCase(), up: f.up === true, kind: oneOf(f.kind, ['ethernet', 'wireguard', 'vlan', 'subinterface', 'ipsec']), vlan: integer(f.vlan, 1, 4094), mode: oneOf(f.mode, ['access', 'trunk']), allowedVlans: array(f.allowedVlans, 64).map(n => integer(n, 1, 4094))};
            item.ipv6 = array(f.ipv6 || [], 8).map(a => cidr6(a).value);
            item.mtu = integer(f.mtu ?? 1500, 1280, 9000);
            if (item.ipv6.length && (node.profile !== 'linux' || !['pc', 'server', 'router'].includes(node.type) || f.kind !== 'ethernet')) throw new Error('IPv6는 Linux PC·서버·라우터의 Ethernet 포트에서 지원합니다.');
            if (new Set(item.ipv6.map(a => cidr6(a).address)).size !== item.ipv6.length || item.ipv6.some(a => ['::', '::1'].includes(cidr6(a).address) || cidr6(a).address.startsWith('ff') || cidr6(a).address.startsWith('::ffff:'))) throw new Error('중복·미지정·멀티캐스트·매핑 주소는 포트에 지정할 수 없습니다.');
            if (f.description) item.description = text(f.description, 80);
            if (f.switchport !== undefined) item.switchport = f.switchport === true;
            if (f.trunkAll !== undefined) item.trunkAll = f.trunkAll === true;
            if (f.allowPing !== undefined) item.allowPing = f.allowPing === true;
            if (['vlan', 'subinterface'].includes(f.kind)) item.vlanId = integer(f.vlanId, 1, 4094);
            if (f.kind === 'subinterface') { item.parent = token(f.parent); item.unit = integer(f.unit, 1, 4094); item.encapsulated = f.encapsulated === true; }
            if (item.kind === 'wireguard') {
                item.privateKey = text(f.privateKey || '', 64); item.listenPort = integer(f.listenPort || 51820, 1, 65535);
                item.peers = array(f.peers || [], 8).map(p => ({publicKey: text(p.publicKey, 100), endpoint: p.endpoint ? (endpoint(p.endpoint), p.endpoint) : '', allowedIPs: array(p.allowedIPs, 16).map(s => (cidr(s), s)), keepalive: integer(p.keepalive || 0, 0, 0)}));
                if (new Set(item.peers.map(p => p.publicKey)).size !== item.peers.length) throw new Error('중복 VPN 피어입니다.');
            }
            return item;
        });
        if (new Set(node.interfaces.map(f => f.name)).size !== node.interfaces.length) throw new Error('중복 인터페이스 이름입니다.');
        const supportedKinds = node.profile === 'linux' ? ['ethernet', 'wireguard'] : node.profile === 'windows' ? ['ethernet'] : node.profile === 'fortios' ? ['ethernet', 'ipsec'] : node.type === 'switch' ? ['ethernet', 'vlan'] : ['ethernet', 'subinterface'];
        if (node.interfaces.some(f => !supportedKinds.includes(f.kind))) throw new Error('장비 CLI 프로필에서 지원하지 않는 논리 인터페이스입니다.');
        if (node.interfaces.some(f => f.kind === 'subinterface' && !node.interfaces.some(p => p.name === f.parent && p.kind === 'ethernet'))) throw new Error('서브인터페이스의 물리 포트가 없습니다.');
        const vlanPorts = node.interfaces.filter(f => f.kind === 'vlan' || (f.kind === 'subinterface' && f.encapsulated)).map(f => `${f.parent || ''}/${f.vlanId}`);
        if (new Set(vlanPorts).size !== vlanPorts.length) throw new Error('같은 포트·VLAN에 논리 인터페이스를 중복 생성할 수 없습니다.');
        node.routes = array(raw.routes || [], 64).map((r, i) => { cidr(r.dest); if (r.via) ipv4(r.via); if (!node.interfaces.some(f => f.name === r.dev) && !(node.profile === 'ios' && !r.dev && r.via)) throw new Error('경로의 인터페이스가 없습니다.'); return {dest: r.dest, via: r.via || '', dev: r.dev || '', metric: integer(r.metric || 0, 0, 65535), ...(node.profile === 'fortios' ? {id: integer(r.id || i + 1, 1, 2147483647)} : {})}; });
        node.routes6 = array(raw.routes6 || [], 64).map(r => {
            if (node.profile !== 'linux' || !node.interfaces.some(f => f.name === r.dev && f.kind === 'ethernet')) throw new Error('IPv6 경로의 Linux Ethernet 포트가 필요합니다.');
            return {dest: cidr6(r.dest).dest, via: r.via ? ipv6(r.via) : '', dev: r.dev, metric: integer(r.metric ?? 1024, 0, 65535)};
        });
        normalizeRouting(raw, node, {array, integer, ipv4, cidr, ipString});
        node.tables = array(raw.tables || [], 12).map(t => ({family: oneOf(t.family, ['ip', 'inet']), name: token(t.name)}));
        node.chains = array(raw.chains || [], 16).map(c => ({family: oneOf(c.family, ['ip', 'inet']), table: token(c.table), name: token(c.name), hook: oneOf(c.hook, ['input', 'output', 'forward', 'prerouting', 'postrouting']), type: oneOf(c.type, ['filter', 'nat']), priority: integer(c.priority, -199, 300), policy: oneOf(c.policy, ['accept', 'drop'])}));
        if (node.chains.some(c => c.type === 'filter' ? !['input', 'output', 'forward'].includes(c.hook) : !['prerouting', 'postrouting'].includes(c.hook) || c.policy !== 'accept')) throw new Error('filter는 input/output/forward, nat는 prerouting/postrouting과 accept 정책만 지원합니다.');
        if (new Set(node.tables.map(t => `${t.family}/${t.name}`)).size !== node.tables.length) throw new Error('중복 테이블입니다.');
        if (node.chains.some(c => !node.tables.some(t => t.name === c.table && t.family === c.family))) throw new Error('체인에 해당하는 테이블이 없습니다.');
        if (new Set(node.chains.map(c => `${c.family}/${c.table}/${c.name}`)).size !== node.chains.length) throw new Error('중복 체인입니다.');
        node.rules = array(raw.rules || [], LIMITS.rules).map(r => {
            const rule = {handle: integer(r.handle, 1, 100000), family: oneOf(r.family, ['ip', 'inet']), table: token(r.table), chain: token(r.chain), match: {}, action: oneOf(r.action, ['accept', 'drop', 'reject', 'masquerade', 'snat', 'dnat']), to: ''};
            if (!node.chains.some(c => c.name === rule.chain && c.table === rule.table && c.family === rule.family)) throw new Error('규칙의 체인이 없습니다.');
            for (const [key, value] of Object.entries(r.match || {})) {
                if (key === 'src' || key === 'dst') { cidr(value); rule.match[key] = value; }
                else if (key === 'sport' || key === 'dport') rule.match[key] = integer(value, 1, 65535);
                else if (key === 'protocol') rule.match[key] = oneOf(value, ['ICMP', 'TCP', 'UDP']);
                else if (key === 'iif' || key === 'oif') rule.match[key] = token(value);
                else if (key === 'state') rule.match[key] = array(value, 3).map(s => oneOf(s, ['new', 'established', 'related']));
                else throw new Error('미지원 방화벽 조건입니다.');
            }
            if (['snat', 'dnat'].includes(rule.action)) { const [ip, port] = String(r.to).split(':'); ipv4(ip); if (port) integer(Number(port), 1, 65535); rule.to = r.to; }
            const chain = node.chains.find(c => c.family === rule.family && c.table === rule.table && c.name === rule.chain);
            if (chain.type === 'nat' && !['masquerade', 'snat', 'dnat'].includes(rule.action)) throw new Error('nat 체인에서는 주소 변환 규칙만 지원합니다.');
            if (['masquerade', 'snat', 'dnat'].includes(rule.action) && (chain.type !== 'nat' || (rule.action === 'dnat' ? chain.hook !== 'prerouting' : chain.hook !== 'postrouting'))) throw new Error('NAT 규칙의 체인·hook을 확인하세요.');
            return rule;
        });
        if (new Set(node.rules.map(r => r.handle)).size !== node.rules.length) throw new Error('중복 방화벽 handle입니다.');
        const services = raw.services || {};
        node.services = {http: null, dns: null, dhcp: null};
        if (services.http) node.services.http = {port: integer(services.http.port, 1, 65535), body: text(services.http.body, 2000)};
        if (services.dns) {
            node.services.dns = {records: {}};
            const entries = Object.entries(services.dns.records || {}); if (entries.length > 40) throw new Error('DNS 레코드는 최대 40개입니다.');
            for (const [name, ip] of entries) { if (!/^[a-z0-9][a-z0-9.-]{0,100}$/i.test(name) || name === '__proto__') throw new Error('DNS 이름을 확인하세요.'); ipv4(ip); node.services.dns.records[name.toLowerCase()] = ip; }
        }
        if (services.dhcp) {
            const d = services.dhcp; ipv4(d.start); ipv4(d.end); ipv4(d.gateway); if (d.dns) ipv4(d.dns);
            integer(d.prefix, 1, 30); if (ipv4(d.end) < ipv4(d.start) || ipv4(d.end) - ipv4(d.start) > 200 || !contains(`${d.start}/${d.prefix}`, d.end) || !contains(`${d.start}/${d.prefix}`, d.gateway)) throw new Error('DHCP 풀과 게이트웨이를 확인하세요.');
            node.services.dhcp = {start: d.start, end: d.end, prefix: d.prefix, gateway: d.gateway, dns: d.dns || ''};
        }
        if (node.profile === 'windows') {
            if (node.forwarding || node.tables.length || node.chains.length || node.rules.length || raw.startup || raw.device) throw new Error('Windows 호스트는 전용 방화벽을 사용하며 IP forwarding·시작 설정을 지원하지 않습니다.');
            node.windows = normalizeWindows(raw.windows, node, {array, integer, text, oneOf, cidr, ipv4});
            node.resolver = windowsResolver(node);
        } else if (raw.windows) throw new Error('Windows 설정은 Windows 프로필에서만 사용할 수 있습니다.');
        if (['ios', 'fortios'].includes(node.profile)) node.device = normalizeDevice(raw.device, node, {array, integer, text, oneOf, cidr, ipv4, token});
        if (raw.startup) {
            if (raw.startup.startup || raw.startup.profile || raw.startup.id) throw new Error('시작 설정 중첩을 허용하지 않습니다.');
            const saved = validateProject({schema: 2, engine: VERSION, seed: 1, title: '', nodes: [{...node, ...raw.startup, startup: null}], links: []}).nodes[0];
            node.startup = configSnapshot(saved);
        } else if (['ios', 'fortios'].includes(node.profile)) node.startup = configSnapshot(node);
        return node;
    });
    if (new Set(nodes.map(n => n.id)).size !== nodes.length) throw new Error('중복 장비 ID입니다.');
    const occupied = new Set();
    const links = array(input.links, LIMITS.links).map(l => {
        const ends = [l.a, l.b].map(e => { const n = nodes.find(n => n.id === e?.node), f = n?.interfaces.find(f => f.name === e.port); if (!f || f.kind !== 'ethernet') throw new Error('링크 끝 포트가 올바르지 않습니다.'); const k = `${e.node}/${e.port}`; if (occupied.has(k)) throw new Error('한 포트에는 케이블 하나만 연결할 수 있습니다.'); occupied.add(k); return {node: e.node, port: e.port}; });
        if (ends[0].node === ends[1].node) throw new Error('같은 장비의 포트를 직접 연결할 수 없습니다.');
        return {id: token(l.id), a: ends[0], b: ends[1], up: l.up === true, delay: integer(l.delay, 0, 2000), loss: integer(l.loss, 0, 100), rate: integer(l.rate || 100, 1, 10000)};
    });
    if (new Set(links.map(l => l.id)).size !== links.length) throw new Error('중복 링크 ID입니다.');
    return {schema: 2, engine: VERSION, title: text(input.title || '새 네트워크', 80), seed: integer(input.seed ?? 42, 1, 2147483647), nodes, links};
}
