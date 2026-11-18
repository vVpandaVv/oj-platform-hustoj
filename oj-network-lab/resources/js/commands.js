import {clone, cidr, ipv4, contains, integer, token, publicKey, endpoint, validateProject} from './model.js?ver=1.4.0-rc.4';
import {command6} from './ipv6.js?ver=1.4.0-rc.4';

export const HELP = `브라우저 네트워크 실습 · Linux 문법의 지원 부분집합
ip addr [show] | ip addr add <주소/접두사> dev <포트>
ip addr flush dev <포트> | ip link [show]
ip link set [dev] <포트> up|down
ip link add <이름> type wireguard | ip link delete <이름>
ip route [show] | ip route add|replace <망/접두사|default> [via <IP>] [dev <포트>] [metric <정수>]
ip route del <망/접두사|default> | ip neigh [show|flush]
ip -6 addr show|add <IPv6/접두사> dev <포트>|flush dev <포트>
ip -6 route show|add|replace <망/접두사|default> [via <IPv6>] dev <포트>
ip -6 route del <망/접두사|default> | ip -6 neigh show|flush
ping -6 [-c 1~4] [-s 크기] <IPv6[%포트]> | traceroute -6 -I <IPv6>
ip link set dev <포트> mtu <1280~9000>
sysctl -w net.ipv6.conf.all.forwarding=0|1
sysctl [-w] net.ipv4.ip_forward[=0|1]
ping [-c 1~4] <IP|호스트> | traceroute -I <IP|호스트>
dig [@DNS주소] <이름> | resolvectl dns <포트> <DNS주소>
curl http://<IP|호스트>[:포트][/경로] | dhclient <포트> | ss [-tan]
bridge fdb [show] | bridge vlan show
bridge vlan add|del dev <포트> vid <VLAN> [pvid untagged]
nft list ruleset | nft flush ruleset
nft add table ip|inet <테이블>
nft add chain <family> <테이블> <체인> { type filter|nat hook <hook> priority <숫자>; policy accept|drop; }
nft add rule <family> <테이블> <체인> [ip saddr|daddr <CIDR>] [tcp|udp dport|sport <포트>]
  [iifname|oifname <인터페이스>] [ct state new,established,related] accept|drop|reject|masquerade|snat to <IP[:포트]>|dnat to <IP[:포트]>
nft delete rule <family> <테이블> <체인> handle <번호>
conntrack -L | tcpdump [-i <포트|any>] [tcp|udp|icmp|arp] [host <IP>] [port <번호>]
wg show | wg set <wg포트> private-key <가상키> [listen-port <포트>]
wg set <wg포트> peer <가상공개키> allowed-ips <CIDR,...> endpoint <IP:포트> [persistent-keepalive <초>]
wg set <wg포트> peer <가상공개키> remove | wg-quick up|down <wg포트>
sim key <가상개인키> | sim reset | sim time <밀리초> | sim capture stop
허브에는 관리 CLI가 없습니다. VPN 키·암호화는 교육용 모델입니다.
IPv6는 Linux Ethernet의 정적 주소·경로·NS/NA·ICMPv6·MTU 학습 모델입니다.
실제 셸, 임의 프로그램, SLAAC·DAD·DHCPv6·IPv6 방화벽·정밀 TCP 혼잡 제어·DHCP 갱신은 지원하지 않습니다.`;

function words(line) {
    const result = []; let current = '', quote = '', active = false;
    for (const char of line.trim()) {
        if (quote) { if (char === quote) quote = ''; else current += char; active = true; }
        else if (char === '"' || char === "'") { quote = char; active = true; }
        else if (/\s/.test(char) || '{};'.includes(char)) { if (active) { result.push(current); current = ''; active = false; } if ('{};'.includes(char)) result.push(char); }
        else { current += char; active = true; }
    }
    if (quote) throw new Error('따옴표를 닫아 주세요.'); if (active) result.push(current); return result;
}
const need = (condition, message = '지원 문법을 확인하세요. help로 명령어를 볼 수 있습니다.') => { if (!condition) throw new Error(message); };
const portOf = (n, name) => { const f = n.interfaces.find(f => f.name === name); need(f, '인터페이스가 없습니다.'); return f; };
function mutate(e, n, change, vpn = false) {
    const p = e.export(), copy = p.nodes.find(node => node.id === n.id); change(copy);
    const valid = validateProject(p); e.project = valid; e.revision++;
    if (vpn) e.rt(n).vpn.clear(); return '';
}
const valueAfter = (t, flag, fallback) => { const index = t.indexOf(flag); return index === -1 ? fallback : t[index + 1]; };
const network = value => value === 'default' ? '0.0.0.0/0' : (cidr(value), value);

export function command(e, n, line) {
    need(typeof line === 'string' && line.length <= 2048, '명령어는 2048자 이내입니다.');
    let t = words(line); if (t[0] === 'sudo') t.shift(); if (!t.length) return '';
    if (t[0] === 'help' || t[0] === '?') return HELP;
    if (n.type === 'hub') throw new Error('허브는 관리 CLI가 없습니다. 장비 설정에서 링크·포트를 바꾸세요.');
    const six = command6(e, n, t); if (six !== null) return six;
    if (t[0] === 'ip') {
        if (t[1] === 'addr' || t[1] === 'address') {
            if (!t[2] || t[2] === 'show') return n.interfaces.map(f => `${f.name}: ${f.up ? 'UP' : 'DOWN'} ${f.mac}\n    ${f.address ? `inet ${f.address}` : 'IPv4 주소 없음'}`).join('\n');
            if (t[2] === 'add') { need(t.length === 6 && t[4] === 'dev'); cidr(t[3]); return mutate(e, n, node => { const f = portOf(node, t[5]); need(!f.address, '이 프로필은 인터페이스당 IPv4 주소 하나를 지원합니다. 먼저 flush 하세요.'); f.address = t[3]; }); }
            if (t[2] === 'flush') { need(t.length === 5 && t[3] === 'dev'); return mutate(e, n, node => portOf(node, t[4]).address = ''); }
        }
        if (t[1] === 'link') {
            if (!t[2] || t[2] === 'show') return n.interfaces.map(f => `${f.name}: ${f.up ? 'UP' : 'DOWN'} ${f.kind} link/ether ${f.mac}`).join('\n');
            if (t[2] === 'set') { const args = t.slice(3).filter(x => x !== 'dev'); need(args.length === 2 && ['up', 'down'].includes(args[1])); return mutate(e, n, node => portOf(node, args[0]).up = args[1] === 'up', true); }
            if (t[2] === 'add') { need(t.length === 6 && t[4] === 'type' && t[5] === 'wireguard'); token(t[3]); return mutate(e, n, node => { need(!node.interfaces.some(f => f.name === t[3]), '인터페이스가 이미 있습니다.'); node.interfaces.push({name: t[3], kind: 'wireguard', address: '', mac: `02:ff:00:00:00:${node.interfaces.length.toString(16).padStart(2, '0')}`, up: false, vlan: 1, mode: 'access', allowedVlans: [1], privateKey: '', listenPort: 51820, peers: []}); }, true); }
            if (t[2] === 'delete' || t[2] === 'del') { need(t.length === 4 && portOf(n, t[3]).kind === 'wireguard', 'CLI에서는 WireGuard 포트만 삭제할 수 있습니다.'); return mutate(e, n, node => { node.interfaces = node.interfaces.filter(f => f.name !== t[3]); node.routes = node.routes.filter(r => r.dev !== t[3]); }, true); }
        }
        if (t[1] === 'route') {
            if (!t[2] || t[2] === 'show') return e.routes(n).map(r => `${r.dest === '0.0.0.0/0' ? 'default' : r.dest}${r.via ? ` via ${r.via}` : ''} dev ${r.dev}${r.connected ? ' proto kernel scope link' : ''}${r.metric ? ` metric ${r.metric}` : ''}`).join('\n') || '(경로 없음)';
            const dest = network(t[3]);
            if (t[2] === 'del' || t[2] === 'delete') { need(t.length === 4); return mutate(e, n, node => { need(node.routes.some(r => r.dest === dest), '정적 경로가 없습니다.'); node.routes = node.routes.filter(r => r.dest !== dest); }); }
            if (t[2] === 'add' || t[2] === 'replace') {
                const rest = t.slice(4); need(rest.length % 2 === 0 && rest.every((v, i) => i % 2 || ['via', 'dev', 'metric'].includes(v)));
                const via = valueAfter(t, 'via', ''); if (via) ipv4(via);
                const dev = valueAfter(t, 'dev', n.interfaces.find(f => f.address && via && contains(f.address, via))?.name); portOf(n, dev);
                const metric = integer(Number(valueAfter(t, 'metric', 0)), 0, 65535);
                return mutate(e, n, node => { const exists = node.routes.some(r => r.dest === dest && r.metric === metric); need(t[2] === 'replace' || !exists, '같은 경로가 있습니다. replace를 사용하세요.'); if (t[2] === 'replace') node.routes = node.routes.filter(r => r.dest !== dest || r.metric !== metric); node.routes.push({dest, via, dev, metric}); });
            }
        }
        if (t[1] === 'neigh') {
            if (t[2] === 'flush') { e.rt(n).arp.clear(); return '이 장비의 ARP 캐시를 비웠습니다.'; }
            need(!t[2] || t[2] === 'show'); return [...e.rt(n).arp.values()].filter(a => a.until > e.now).map(a => `${a.ip} dev ${a.port} lladdr ${a.mac} REACHABLE`).join('\n') || '(ARP 캐시 없음)';
        }
        throw new Error('지원하지 않는 ip 옵션입니다. help를 확인하세요.');
    }
    if (t[0] === 'sysctl') {
        t = t.filter(x => x !== '-w'); need(t.length === 2 && /^net\.ipv4\.ip_forward(=[01])?$/.test(t[1]));
        if (t[1].includes('=')) mutate(e, n, node => node.forwarding = t[1].endsWith('1'));
        return `net.ipv4.ip_forward = ${e.node(n.id).forwarding ? 1 : 0}`;
    }
    if (t[0] === 'ping') { let count = 1, target; for (let i = 1; i < t.length; i++) { if (t[i] === '-c') count = integer(Number(t[++i]), 1, 4); else { need(!t[i].startsWith('-') && target === undefined); target = t[i]; } } need(target); e.start(n, 'ping', target, {count}); return ''; }
    if (t[0] === 'traceroute') { need(t.length === 3 && t[1] === '-I', 'traceroute -I <주소> 형식을 지원합니다.'); e.start(n, 'trace', t[2]); return ''; }
    if (t[0] === 'curl') { need(t.length === 2); const m = /^http:\/\/([a-zA-Z0-9.-]+)(?::(\d+))?(\/[^\s]*)?$/.exec(t[1]); need(m, 'http://주소[:포트][/경로] 형식을 지원합니다. HTTPS/TLS는 미지원입니다.'); e.start(n, 'curl', m[1], {port: integer(Number(m[2] || 80), 1, 65535), path: m[3] || '/'}); return ''; }
    if (t[0] === 'dig') { const explicit = t[1]?.startsWith('@'); need(t.length === (explicit ? 3 : 2)); const name = t[explicit ? 2 : 1]; need(/^[a-z0-9][a-z0-9.-]{0,100}$/i.test(name)); e.startDNS(n, name, explicit ? t[1].slice(1) : n.resolver); return ''; }
    if (t[0] === 'resolvectl') { need(t.length === 4 && t[1] === 'dns'); portOf(n, t[2]); ipv4(t[3]); return mutate(e, n, node => node.resolver = t[3]); }
    if (t[0] === 'dhclient') { need(t.length === 2); e.startDHCP(n, t[1]); return ''; }
    if (t[0] === 'bridge') {
        need(n.type === 'switch', 'bridge 명령은 스위치 프로필에서 지원합니다.');
        if (t[1] === 'fdb') { need(!t[2] || t[2] === 'show'); return [...e.rt(n).fdb.values()].filter(f => f.until > e.now).map(f => `${f.mac} dev ${f.port} vlan ${f.vlan} dynamic`).join('\n') || '(MAC 학습 전)'; }
        if (t[1] === 'vlan') {
            if (t[2] === 'show') return n.interfaces.map(f => `${f.name} ${f.mode} pvid ${f.vlan} allowed ${f.allowedVlans.join(',')}`).join('\n');
            need(['add', 'del'].includes(t[2]) && t[3] === 'dev' && t[5] === 'vid');
            const vid = integer(Number(t[6]), 1, 4094); need(t.slice(7).every(s => ['pvid', 'untagged'].includes(s)));
            return mutate(e, n, node => { const f = portOf(node, t[4]); if (t[2] === 'del') f.allowedVlans = f.allowedVlans.filter(v => v !== vid); else if (t.includes('pvid') && t.includes('untagged')) { f.vlan = vid; f.mode = 'access'; f.allowedVlans = [vid]; } else { f.mode = 'trunk'; if (!f.allowedVlans.includes(vid)) f.allowedVlans.push(vid); } });
        }
    }
    if (t[0] === 'nft') {
        if (t.length === 2 && /\s/.test(t[1])) t = ['nft', ...words(t[1])];
        if (t.join(' ') === 'nft list ruleset') return n.tables.map(table => `table ${table.family} ${table.name} {\n${n.chains.filter(c => c.family === table.family && c.table === table.name).map(c => `  chain ${c.name} { type ${c.type} hook ${c.hook} priority ${c.priority}; policy ${c.policy};\n${n.rules.filter(r => r.family === c.family && r.table === c.table && r.chain === c.name).map(r => `    ${Object.entries(r.match).map(([k, v]) => `${k} ${Array.isArray(v) ? v.join(',') : v}`).join(' ')} ${r.action}${r.to ? ` to ${r.to}` : ''} # handle ${r.handle}`).join('\n')}\n  }`).join('\n')}\n}`).join('\n') || '(빈 ruleset)';
        if (t.join(' ') === 'nft flush ruleset') return mutate(e, n, node => { node.tables = []; node.chains = []; node.rules = []; });
        const [_, op, kind, family, table, name] = t;
        need(['ip', 'inet'].includes(family)); token(table);
        if (op === 'add' && kind === 'table') { need(t.length === 5); return mutate(e, n, node => { need(!node.tables.some(x => x.family === family && x.name === table), '테이블이 이미 있습니다.'); node.tables.push({family, name: table}); }); }
        if (op === 'add' && kind === 'chain') {
            const spec = t.slice(6).join(' '), m = /^\{ type (filter|nat) hook (input|output|forward|prerouting|postrouting) priority (-?\d+) ;(?: policy (accept|drop) ;)? \}$/.exec(spec);
            need(m, '체인에는 type, hook, 숫자 priority와 선택 policy를 순서대로 지정하세요.');
            return mutate(e, n, node => node.chains.push({family, table, name, type: m[1], hook: m[2], priority: Number(m[3]), policy: m[4] || 'accept'}));
        }
        if (op === 'delete' && kind === 'rule') { need(t.length === 8 && t[6] === 'handle'); const handle = integer(Number(t[7]), 1, 100000); return mutate(e, n, node => { const exists = r => r.family === family && r.table === table && r.chain === name && r.handle === handle; need(node.rules.some(exists), '해당 규칙이 없습니다.'); node.rules = node.rules.filter(r => !exists(r)); }); }
        if (op === 'add' && kind === 'rule') {
            const match = {}; let i = 6, action = '', to = '';
            while (i < t.length) {
                const word = t[i++];
                if (['accept', 'drop', 'reject', 'masquerade', 'snat', 'dnat'].includes(word)) { action = word; if (['snat', 'dnat'].includes(word)) { need(t[i++] === 'to'); to = t[i++]; } need(i === t.length); break; }
                if (word === 'ip') { const field = t[i++]; need(['saddr', 'daddr', 'protocol'].includes(field)); if (field === 'protocol') { need(['icmp', 'tcp', 'udp'].includes(t[i])); match.protocol = t[i++].toUpperCase(); } else { match[field === 'saddr' ? 'src' : 'dst'] = t[i].includes('/') ? t[i++] : `${t[i++]}/32`; } }
                else if (word === 'tcp' || word === 'udp') { match.protocol = word.toUpperCase(); const field = t[i++]; need(['dport', 'sport'].includes(field)); match[field] = Number(t[i++]); }
                else if (word === 'iifname' || word === 'oifname') match[word === 'iifname' ? 'iif' : 'oif'] = t[i++];
                else if (word === 'ct') { need(t[i++] === 'state'); match.state = t[i++].split(','); }
                else throw new Error(`지원하지 않는 nft 조건: ${word}`);
            }
            need(action); return mutate(e, n, node => node.rules.push({family, table, chain: name, handle: Math.max(0, ...node.rules.map(r => r.handle)) + 1, match, action, to}));
        }
        throw new Error('지원하지 않는 nft 문법입니다. help를 확인하세요.');
    }
    if (t[0] === 'conntrack') { need(t.length === 2 && t[1] === '-L'); return [...e.rt(n).conn.values()].filter(c => c.until > e.now).map(c => `${c.state} ${c.tuple}`).join('\n') || '(연결 추적 없음)'; }
    if (t[0] === 'ss') { need(t.length === 1 || t.length === 2 && t[1] === '-tan'); return [n.services.http ? `LISTEN *:${n.services.http.port}` : '', ...[...e.rt(n).tcp.values()].filter(s => s.until > e.now).map(s => `${s.state} *:${s.localPort} ${s.remote}`)].filter(Boolean).join('\n') || '(소켓 없음)'; }
    if (t[0] === 'wg') {
        if (t[1] === 'show' && t.length <= 3) return n.interfaces.filter(f => f.kind === 'wireguard' && (!t[2] || f.name === t[2])).map(f => `interface: ${f.name} (${f.up ? 'UP' : 'DOWN'})\n  가상 공개키: ${publicKey(f.privateKey)}\n  listen port: ${f.listenPort}\n${f.peers.map(p => { const v = e.vpnState(n, f, p); return `  peer: ${p.publicKey}\n    endpoint: ${v.endpoint || '(없음)'}\n    allowed ips: ${p.allowedIPs.join(', ')}\n    latest model handshake: ${v.handshake === null ? 'never' : `${e.now - v.handshake} ms 전`}`; }).join('\n')}`).join('\n') || '(WireGuard 인터페이스 없음)';
        need(t[1] === 'set' && portOf(n, t[2]).kind === 'wireguard');
        return mutate(e, n, node => {
            const f = portOf(node, t[2]); let i = 3, p = null;
            while (i < t.length) {
                const field = t[i++], value = t[i++]; need(value !== undefined);
                if (field === 'private-key') { need(!p); f.privateKey = value; }
                else if (field === 'listen-port') { need(!p); f.listenPort = Number(value); }
                else if (field === 'peer') { p = f.peers.find(p => p.publicKey === value); if (!p) { p = {publicKey: value, endpoint: '', allowedIPs: [], keepalive: 0}; f.peers.push(p); } if (t[i] === 'remove') { need(i + 1 === t.length); f.peers = f.peers.filter(peer => peer !== p); i++; } }
                else if (field === 'allowed-ips') { need(p); p.allowedIPs = value.split(','); }
                else if (field === 'endpoint') { need(p); endpoint(value); p.endpoint = value; }
                else if (field === 'persistent-keepalive') { need(p); need(Number(value) === 0, '이 버전은 keepalive 주기 실행을 지원하지 않습니다. 0만 설정할 수 있습니다.'); p.keepalive = 0; }
                else throw new Error(`지원하지 않는 wg 설정: ${field}`);
            }
        }, true);
    }
    if (t[0] === 'wg-quick') {
        need(t.length === 3 && ['up', 'down'].includes(t[1]) && portOf(n, t[2]).kind === 'wireguard');
        return mutate(e, n, node => { const f = portOf(node, t[2]); f.up = t[1] === 'up'; node.routes = node.routes.filter(r => r.dev !== f.name); if (f.up) { for (const p of f.peers) { if (p.endpoint) { const ep = endpoint(p.endpoint), r = e.route(n, ep.ip); need(r && r.dev !== f.name, '먼저 endpoint의 외부 경로를 설정하세요.'); if (p.allowedIPs.includes('0.0.0.0/0') && !node.routes.some(r => r.dest === `${ep.ip}/32`)) node.routes.push({...r, dest: `${ep.ip}/32`}); } for (const dest of p.allowedIPs) if (!node.routes.some(r => r.dest === dest && r.dev === f.name)) node.routes.push({dest, via: '', dev: f.name, metric: 0}); } } }, true);
    }
    if (t[0] === 'tcpdump') {
        const capture = {port: '', protocol: '', host: '', filterPort: 0};
        for (let i = 1; i < t.length; i++) { if (t[i] === '-i') { capture.port = t[++i]; if (capture.port === 'any') capture.port = ''; else portOf(n, capture.port); } else if (['tcp', 'udp', 'icmp', 'arp'].includes(t[i])) capture.protocol = t[i].toUpperCase(); else if (t[i] === 'host') { capture.host = t[++i]; ipv4(capture.host); } else if (t[i] === 'port') capture.filterPort = integer(Number(t[++i]), 1, 65535); else throw new Error('지원하지 않는 tcpdump 필터입니다.'); }
        e.rt(n).capture = capture; return '합성 패킷 관찰 시작 · sim capture stop 또는 Ctrl+C로 중지';
    }
    if (t[0] === 'sim') {
        if (t[1] === 'key' && t.length === 3) return `가상 공개키: ${publicKey(t[2])} (실서비스 사용 불가)`;
        if (t[1] === 'reset' && t.length === 2) { const revision = e.revision; e.load(e.project); e.revision = revision; return '현재 설정을 유지하고 실행 상태·가상 시간을 초기화했습니다.'; }
        if (t[1] === 'time' && t.length === 3) { e.schedule(integer(Number(t[2]), 1, 3600000), 'clock', {}); return '가상 시간 진행을 예약했습니다.'; }
        if (t[1] === 'capture' && t[2] === 'stop' && t.length === 3) { e.rt(n).capture = null; return '패킷 관찰 중지'; }
    }
    throw new Error('지원하지 않는 명령어·옵션입니다. help를 확인하세요.');
}
