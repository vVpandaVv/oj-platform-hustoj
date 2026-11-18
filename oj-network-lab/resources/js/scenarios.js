import {VERSION, newNode, publicKey, validateProject} from './model.js?ver=1.4.0-rc.4';
import {assignProfile, configSnapshot} from './device-config.js?ver=1.4.0-rc.4';
const node = (type, id, name, x, y, addresses) => { const n = newNode(type, id); n.name = name; n.x = x; n.y = y; addresses.forEach((a, i) => n.interfaces[i].address = a); return n; };
const cable = (id, a, pa, b, pb) => ({id: `l${id}`, a: {node: a.id, port: `eth${pa}`}, b: {node: b.id, port: `eth${pb}`}, up: true, delay: 5, loss: 0, rate: 100});
const route = (n, dest, via, dev) => n.routes.push({dest, via, dev, metric: 0});
export const LESSONS = [
    {id: 'ipv6', title: '12 · IPv6 구성과 장애 복구', goal: 'Linux 장비에 IPv6 주소와 기본 경로를 설정하고 NS/NA, 반환 경로, Hop Limit, MTU 장애를 확인합니다.', node: 'n1', command: 'ip -6 addr show'},
    {id: 'ospf', title: '13 · OSPF 인접 관계와 자동 우회', goal: 'R1·R2·R3의 IOS OSPF를 구성하고 cost, area, 포트 장애에 따른 LSDB·경로·통신 결과를 비교합니다.', node: 'n2', command: 'enable'},
    {id: 'bgp', title: '14 · BGP 피어와 경로 정책', goal: '세 AS의 eBGP 피어와 network를 구성하고 Remote AS, Weight, 광고 철회에 따른 경로와 왕복 통신을 확인합니다.', node: 'n2', command: 'enable'},
    {id: 'lan', title: '01 · 허브와 스위치', goal: 'PC-A에서 ping 192.168.10.20을 실행하고 ARP와 MAC 학습을 관찰하세요. 스위치를 허브로 바꿔 전파 범위를 비교하세요.', node: 'n1', command: 'ping -c 2 192.168.10.20'},
    {id: 'routing', title: '02 · 라우터와 응답 경로', goal: '다른 서브넷의 서버에 통신하세요. 서버의 기본 경로를 지우면 응답이 돌아오지 않습니다.', node: 'n1', command: 'ping 192.168.20.10'},
    {id: 'firewall', title: '03 · 방화벽과 NAT', goal: '웹 통신은 허용되고 ICMP는 차단됩니다. 방화벽 규칙과 NAT 전후 주소를 관찰하고 ICMP 허용 규칙을 추가하세요.', node: 'n1', command: 'curl http://203.0.113.10'},
    {id: 'vpn', title: '04 · 지점 간 VPN', goal: 'WAN 위의 가상 WireGuard 터널로 사설망끼리 연결합니다. wg show와 AllowedIPs·키·경로 설정을 바꿔 장애를 재현하세요.', node: 'n1', command: 'ping 192.168.20.10'},
    {id: 'dhcp', title: '05 · DHCP와 DNS', goal: 'PC-A에서 dhclient eth0으로 주소를 받은 뒤 dig web.lab과 curl http://web.lab을 실행하세요.', node: 'n1', command: 'dhclient eth0'},
    {id: 'broken', title: '06 · 장애 해결', goal: '서버의 기본 게이트웨이가 잘못되어 응답이 돌아오지 않습니다. 각 장비의 ip route와 패킷 관측으로 원인을 찾아 고치세요.', node: 'n1', command: 'ping 192.168.20.10'},
    {id: 'ios-switch', title: '07 · IOS 스위치 CLI', goal: 'SW-A에서 enable → configure terminal → interface Gi1/0/2 → shutdown을 실행하고 PC-A의 통신 실패를 확인하세요. no shutdown으로 복구하고 copy running-config startup-config로 저장하세요.', node: 'n2', command: 'enable'},
    {id: 'ios-router', title: '08 · IOS 라우터 CLI', goal: 'Router-A에서 enable, show ip route, show ip interface brief를 실행하세요. 정적 경로·ACL·NAT를 설정하고 포트 shutdown과 재부팅 전후를 비교하세요.', node: 'n3', command: 'enable'},
    {id: 'fortios', title: '09 · FortiOS 정책과 NAT', goal: 'FW-NAT에서 show firewall policy로 HTTP 허용 정책을 확인하세요. config firewall policy에서 PING 허용 정책을 추가하고 next/end로 적용한 뒤 PC-A의 통신을 확인하세요.', node: 'n3', command: 'show firewall policy'},
    {id: 'ipsec', title: '10 · FortiOS IPsec CLI', goal: 'FW-A에서 show vpn ipsec phase1-interface와 diagnose vpn tunnel list를 조회하세요. PC-A의 ping으로 교육용 IKEv2·ESP를 관찰하고 가상 PSK·proposal·선택자를 바꿔 실패 원인을 확인하세요.', node: 'n2', command: 'diagnose vpn tunnel list'},
    {id: 'windows', title: '11 · Windows CMD·PowerShell·방화벽', goal: 'WIN-PC에서 ipconfig /renew로 주소를 받고 powershell로 전환해 Test-NetConnection winweb.lab -Port 80을 실행하세요. WIN-Web에서 Public 인바운드 TCP 80 허용 규칙을 추가한 뒤 성공을 확인하고 Block 규칙의 우선순위도 비교하세요.', node: 'n1', command: 'ipconfig /renew'},
    {id: 'empty', title: '자유 구성', goal: '장비를 추가하고 포트를 연결한 뒤 주소와 경로를 설정하세요. help에서 지원 명령을 확인할 수 있습니다.', node: '', command: 'help'},
];
export function scenario(id) {
    if (id === 'ipv6') {
        const a = node('pc', 1, 'PC-A', 100, 200, []), r = node('router', 2, 'Router-A', 430, 200, []), b = node('server', 3, 'Server-B', 770, 200, []);
        return validateProject({schema: 2, engine: VERSION, title: LESSONS.find(l => l.id === id).title, seed: 42, nodes: [a, r, b], links: [cable(1, a, 0, r, 0), cable(2, r, 1, b, 0)]});
    }
    if (id === 'ospf' || id === 'bgp') {
        const a = node('pc', 1, 'PC-A', 100, 250, ['192.0.2.10/24']), r1 = node('router', 2, 'R1', 330, 250, ['192.0.2.1/24', '10.0.12.1/30', '10.0.13.1/30']);
        const r2 = node('router', 3, 'R2', 540, 65, ['10.0.12.2/30', '10.0.23.1/30']), r3 = node('router', 4, 'R3', 790, 250, ['198.51.100.1/24', '10.0.23.2/30', '10.0.13.2/30']), b = node('server', 5, 'Server-B', 1040, 250, ['198.51.100.10/24']);
        for (const r of [r1, r2, r3]) { assignProfile(r, 'ios'); r.startup = configSnapshot(r); }
        route(a, '0.0.0.0/0', '192.0.2.1', 'eth0'); route(b, '0.0.0.0/0', '198.51.100.1', 'eth0');
        return validateProject({schema: 2, engine: VERSION, title: LESSONS.find(l => l.id === id).title, seed: 42, nodes: [a, r1, r2, r3, b], links: [cable(1, a, 0, r1, 0), cable(2, r1, 1, r2, 0), cable(3, r2, 1, r3, 1), cable(4, r1, 2, r3, 2), cable(5, r3, 0, b, 0)]});
    }
    if (id === 'windows') {
        const p = scenario('dhcp'); p.title = LESSONS.find(l => l.id === id).title; p.nodes[0].name = 'WIN-PC'; assignProfile(p.nodes[0], 'windows');
        const web = node('server', 4, 'WIN-Web', 620, 340, ['192.168.10.20/24']); web.services.http = {port: 80, body: 'Windows 방화벽 실습 웹 서버입니다.'}; assignProfile(web, 'windows');
        p.nodes[2].services.dns.records['winweb.lab'] = '192.168.10.20'; p.nodes.push(web); p.links.push(cable(3, p.nodes[1], 2, web, 0)); return validateProject(p);
    }
    if (['ios-switch', 'ios-router', 'fortios', 'ipsec'].includes(id)) {
        const p = scenario(id === 'ios-switch' ? 'lan' : id === 'ios-router' ? 'routing' : id === 'fortios' ? 'firewall' : 'vpn');
        p.title = LESSONS.find(l => l.id === id).title;
        if (id === 'ios-switch') assignProfile(p.nodes[1], 'ios');
        if (id === 'ios-router') assignProfile(p.nodes[2], 'ios');
        if (id === 'fortios') { const n = assignProfile(p.nodes[2], 'fortios'); n.device.policies = [{id: 1, name: 'HTTP-NAT', srcintf: 'eth0', dstintf: 'eth1', srcaddr: ['all'], dstaddr: ['all'], service: ['HTTP'], action: 'accept', nat: true, status: true, logtraffic: true}]; n.startup = configSnapshot(n); }
        if (id === 'ipsec') for (const [index, name, remote, localNet, remoteNet] of [[1, 'to-b', '198.51.100.2', '192.168.10.0/24', '192.168.20.0/24'], [3, 'to-a', '198.51.100.1', '192.168.20.0/24', '192.168.10.0/24']]) {
            const n = p.nodes[index]; n.type = 'firewall'; n.interfaces = n.interfaces.filter(f => f.kind === 'ethernet'); n.routes = []; assignProfile(n, 'fortios'); n.name = index === 1 ? 'FW-A' : 'FW-B';
            n.interfaces.push({name, kind: 'ipsec', address: '', mac: `02:aa:00:00:00:0${index}`, up: true, vlan: 1, mode: 'access', allowedVlans: [1]});
            n.device.phase1 = [{name, interface: 'eth1', remote, psk: 'sim-ipsec-only', proposal: 'aes256-sha256', dhgrp: 14, ikeversion: 2, lifetime: 28800}];
            n.device.phase2 = [{name: 'private-lans', phase1: name, src: localNet, dst: remoteNet, proposal: 'aes256-sha256', pfs: true, lifetime: 3600}];
            n.routes = [{dest: remoteNet, via: '', dev: name, metric: 10, id: 1}];
            n.device.policies = [{id: 1, name: 'LAN-IPsec', srcintf: 'eth0', dstintf: name, srcaddr: ['all'], dstaddr: ['all'], service: ['ALL'], action: 'accept', nat: false, status: true, logtraffic: true}, {id: 2, name: 'IPsec-LAN', srcintf: name, dstintf: 'eth0', srcaddr: ['all'], dstaddr: ['all'], service: ['ALL'], action: 'accept', nat: false, status: true, logtraffic: true}];
            n.startup = configSnapshot(n);
        }
        return validateProject(p);
    }
    const project = {schema: 1, engine: VERSION, title: LESSONS.find(l => l.id === id)?.title || '네트워크', seed: 42, nodes: [], links: []};
    if (id === 'empty') return project;
    const a = node('pc', 1, 'PC-A', 100, 170, [id === 'dhcp' ? '' : '192.168.10.10/24']);
    const sw = node('switch', 2, 'SW-A', 340, 170, []);
    if (id === 'lan') {
        const b = node('pc', 3, 'PC-B', 590, 110, ['192.168.10.20/24']);
        const c = node('pc', 4, 'PC-C', 590, 290, ['192.168.10.30/24']);
        project.nodes = [a, sw, b, c]; project.links = [cable(1, a, 0, sw, 0), cable(2, sw, 1, b, 0), cable(3, sw, 2, c, 0)];
    } else if (id === 'dhcp') {
        const b = node('server', 3, 'DHCP-DNS-Web', 620, 170, ['192.168.10.1/24']);
        b.services.dhcp = {start: '192.168.10.100', end: '192.168.10.150', prefix: 24, gateway: '192.168.10.1', dns: '192.168.10.1'};
        b.services.dns = {records: {'web.lab': '192.168.10.1'}}; b.services.http = {port: 80, body: '네트워크 실습 웹 서버에 연결했습니다.'};
        project.nodes = [a, sw, b]; project.links = [cable(1, a, 0, sw, 0), cable(2, sw, 1, b, 0)];
    } else if (id === 'vpn') {
        const r1 = node('router', 2, 'VPN-A', 330, 170, ['192.168.10.1/24', '198.51.100.1/24']);
        const wan = node('switch', 3, '가상 WAN', 560, 170, []);
        const r2 = node('router', 4, 'VPN-B', 790, 170, ['192.168.20.1/24', '198.51.100.2/24']);
        const b = node('server', 5, 'Server-B', 1030, 170, ['192.168.20.10/24']);
        const wg = (n, address, key, peer, remote, allowed) => { n.interfaces.push({name: 'wg0', address, mac: `02:00:10:00:00:0${n.id.slice(1)}`, up: true, kind: 'wireguard', vlan: 1, mode: 'access', allowedVlans: [1], privateKey: key, listenPort: 51820, peers: [{publicKey: publicKey(peer), endpoint: `${remote}:51820`, allowedIPs: [allowed], keepalive: 0}]}); };
        wg(r1, '10.99.0.1/30', 'sim-a', 'sim-b', '198.51.100.2', '192.168.20.0/24');
        wg(r2, '10.99.0.2/30', 'sim-b', 'sim-a', '198.51.100.1', '192.168.10.0/24');
        route(a, '0.0.0.0/0', '192.168.10.1', 'eth0'); route(b, '0.0.0.0/0', '192.168.20.1', 'eth0');
        route(r1, '192.168.20.0/24', '', 'wg0'); route(r2, '192.168.10.0/24', '', 'wg0');
        project.nodes = [a, r1, wan, r2, b]; project.links = [cable(1, a, 0, r1, 0), cable(2, r1, 1, wan, 0), cable(3, wan, 1, r2, 1), cable(4, r2, 0, b, 0)];
    } else {
        const fw = id === 'firewall';
        const r = node(fw ? 'firewall' : 'router', 3, fw ? 'FW-NAT' : 'Router-A', 570, 170, ['192.168.10.1/24', fw ? '203.0.113.1/24' : '192.168.20.1/24']);
        const b = node('server', 4, 'Server-B', 820, 170, [fw ? '203.0.113.10/24' : '192.168.20.10/24']);
        b.services.http = {port: 80, body: 'HTTP 통신에 성공했습니다. 패킷과 방화벽 판정 과정을 확인하세요.'};
        route(a, '0.0.0.0/0', '192.168.10.1', 'eth0');
        if (!fw) route(b, '0.0.0.0/0', id === 'broken' ? '192.168.20.254' : '192.168.20.1', 'eth0');
        if (fw) {
            r.tables = [{family: 'inet', name: 'filter'}, {family: 'ip', name: 'nat'}];
            r.chains = [{family: 'inet', table: 'filter', name: 'forward', hook: 'forward', type: 'filter', priority: 0, policy: 'drop'}, {family: 'ip', table: 'nat', name: 'postrouting', hook: 'postrouting', type: 'nat', priority: 100, policy: 'accept'}];
            r.rules = [{handle: 1, family: 'inet', table: 'filter', chain: 'forward', match: {state: ['established', 'related']}, action: 'accept', to: ''}, {handle: 2, family: 'inet', table: 'filter', chain: 'forward', match: {src: '192.168.10.0/24', protocol: 'TCP', dport: 80}, action: 'accept', to: ''}, {handle: 3, family: 'ip', table: 'nat', chain: 'postrouting', match: {src: '192.168.10.0/24', oif: 'eth1'}, action: 'masquerade', to: ''}];
        }
        project.nodes = [a, sw, r, b]; project.links = [cable(1, a, 0, sw, 0), cable(2, sw, 1, r, 0), cable(3, r, 1, b, 0)];
    }
    return validateProject(project);
}
