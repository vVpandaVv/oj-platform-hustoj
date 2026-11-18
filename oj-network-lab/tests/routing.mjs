import assert from 'node:assert/strict';
import {Engine} from '../resources/js/engine.js';
import {scenario} from '../resources/js/scenarios.js';
import {validateProject} from '../resources/js/model.js';
import {ipv6, cidr6, contains6} from '../resources/js/ipv6-address.js';

let checks = 0;
function test(name, fn) { fn(); checks++; console.log('OK', name); }
export function commands(e, id, lines) { for (const line of lines.split('\n').filter(Boolean)) { try { e.execute(id, line); e.run(); } catch (err) { throw new Error(`${id}: ${line}: ${err.message}`); } } return e; }
const ping = (e, target = '198.51.100.10', id = 'n1') => { e.execute(id, `ping -c 1 ${target}`); e.run(); return [...e.jobs.values()].at(-1); };
export function ipv6Lab() {
    const e = new Engine(scenario('ipv6'));
    commands(e, 'n1', 'ip -6 addr add 2001:db8:10::10/64 dev eth0\nip -6 route add default via 2001:db8:10::1 dev eth0');
    commands(e, 'n2', 'ip -6 addr add 2001:db8:10::1/64 dev eth0\nip -6 addr add 2001:db8:20::1/64 dev eth1\nsysctl -w net.ipv6.conf.all.forwarding=1');
    commands(e, 'n3', 'ip -6 addr add 2001:db8:20::10/64 dev eth0\nip -6 route add default via 2001:db8:20::1 dev eth0'); return e;
}
export function ospfLab() {
    const e = new Engine(scenario('ospf'));
    for (const [id, rid, networks] of [['n2', '1.1.1.1', ['192.0.2.0 0.0.0.255', '10.0.12.0 0.0.0.3', '10.0.13.0 0.0.0.3']], ['n3', '2.2.2.2', ['10.0.12.0 0.0.0.3', '10.0.23.0 0.0.0.3']], ['n4', '3.3.3.3', ['198.51.100.0 0.0.0.255', '10.0.23.0 0.0.0.3', '10.0.13.0 0.0.0.3']]]) {
        commands(e, id, ['enable', 'configure terminal', 'router ospf 1', `router-id ${rid}`, ...networks.map(net => `network ${net} area 0`), 'end'].join('\n'));
    }
    for (const id of ['n2', 'n4']) commands(e, id, 'configure terminal\ninterface Gi0/2\nip ospf cost 100\nend'); return e;
}
export function bgpLab() {
    const e = new Engine(scenario('bgp'));
    for (const [id, asn, peers, net] of [['n2',65001,[['10.0.12.2',65002],['10.0.13.2',65003]],'192.0.2.0'],['n3',65002,[['10.0.12.1',65001],['10.0.23.2',65003]],''],['n4',65003,[['10.0.13.1',65001],['10.0.23.1',65002]],'198.51.100.0']]) {
        commands(e, id, ['enable','configure terminal',`router bgp ${asn}`,...peers.map(([ip, a]) => `neighbor ${ip} remote-as ${a}`),...(net ? [`network ${net} mask 255.255.255.0`] : []),'end'].join('\n'));
    } return e;
}
test('IPv6 platform parsing, canonicalization, /0 /64 /127 /128 and rejection', () => {
    assert.equal(ipv6('2001:0DB8:0000:0000:0000:0000:0000:0001'), '2001:db8::1');
    assert.equal(cidr6('2001:db8:1::5/64').dest, '2001:db8:1::/64');
    assert(contains6('::/0', 'ffff::1')); assert(contains6('2001:db8::/127', '2001:db8::1')); assert(!contains6('2001:db8::/127', '2001:db8::2')); assert(!contains6('::1/128', '::2'));
    for (const bad of ['1::2::3', '12345::', '1:2:3:4:5:6:7:8:9', 'fe80::1%eth0', 'https://::1']) assert.throws(() => ipv6(bad));
});
test('IPv6 NS/NA, routed echo, no ARP and Hop Limit', () => {
    const e = ipv6Lab(), j = ping(e, '-6 2001:db8:20::10'); assert.equal(j.results.length,1);
    assert(e.trace.some(t => t.action === 'neighbor' && t.detail.startsWith('NA'))); assert(!e.trace.some(t => t.protocol === 'ARP'));
    commands(e, 'n1', 'traceroute -6 -I 2001:db8:20::10'); const trace = [...e.jobs.values()].at(-1); assert(trace.results.some(r => !r.ok)); assert(trace.results.some(r => r.ok));
});
test('IPv6 return route and forwarding faults restore, cached neighbors expire', () => {
    const e = ipv6Lab(); commands(e, 'n3','ip -6 route del default'); assert.equal(ping(e,'-6 2001:db8:20::10').results.length,0);
    commands(e, 'n3','ip -6 route add default via 2001:db8:20::1 dev eth0'); assert.equal(ping(e,'-6 2001:db8:20::10').results.length,1);
    commands(e,'n2','sysctl -w net.ipv6.conf.all.forwarding=0'); assert.equal(ping(e,'-6 2001:db8:20::10').results.length,0);
    commands(e,'n2','sysctl -w net.ipv6.conf.all.forwarding=1'); e.now += 31000; assert.equal(ping(e,'-6 2001:db8:20::10').results.length,1);
});
test('IPv6 Packet Too Big and safe payload, link-local scope, atomic validation', () => {
    const e = ipv6Lab(); commands(e,'n2','ip link set dev eth1 mtu 1280'); const j = ping(e,'-6 -s 1400 2001:db8:20::10'); assert.equal(j.results.length,0); assert(j.errors.some(x=>x.includes('MTU 1280')));
    assert.equal(ping(e,'-6 -s 1200 2001:db8:20::10').results.length,1);
    assert.throws(()=>e.execute('n1','ping -6 fe80::2')); const before=e.export(); assert.throws(()=>e.execute('n1','ip link set dev eth0 mtu 1200')); assert.deepEqual(e.export(),before);
    assert.throws(()=>e.execute('n1','ip -6 addr add 2001:0db8:10:0::10/64 dev eth0')); assert.deepEqual(e.export(),before);
    assert.deepEqual(new Engine(e.export()).export(),e.export());
});
test('OSPF configured adjacency, LSDB, cost path and true forwarded traffic', () => {
    const e=ospfLab(); assert.equal(e.rt('n2').ospf.neighbors.filter(n=>n.state==='Full').length,2); assert(e.rt('n2').ospf.database.length>3);
    const r=e.route(e.node('n2'),'198.51.100.10'); assert.equal(r.source,'O'); assert.equal(r.via,'10.0.12.2'); assert.equal(r.metric,30);
    assert.equal(ping(e).results.length,1); assert(e.trace.some(t=>t.node==='n3' && t.action==='route'));
});
test('OSPF failover, complete withdrawal, recovery and area mismatch', () => {
    const e=ospfLab(); commands(e,'n2','configure terminal\ninterface Gi0/1\nshutdown\nend'); assert.equal(e.route(e.node('n2'),'198.51.100.10').via,'10.0.13.2'); assert.equal(ping(e).results.length,1);
    commands(e,'n2','configure terminal\ninterface Gi0/2\nshutdown\nend'); assert.equal(e.route(e.node('n2'),'198.51.100.10'),undefined); assert.equal(ping(e).results.length,0);
    commands(e,'n2','configure terminal\ninterface Gi0/1\nno shutdown\nexit\ninterface Gi0/2\nno shutdown\nend'); assert.equal(ping(e).results.length,1);
    commands(e,'n3','configure terminal\nrouter ospf 1\nnetwork 10.0.23.0 0.0.0.3 area 1\nend'); assert(e.rt('n3').ospf.neighbors.some(n=>n.reason==='Area mismatch')); assert.equal(e.route(e.node('n2'),'198.51.100.10').via,'10.0.13.2');
    commands(e,'n3','configure terminal\nrouter ospf 1\nnetwork 10.0.23.0 0.0.0.3 area 0\nend'); assert.equal(e.route(e.node('n2'),'198.51.100.10').via,'10.0.12.2');
});
test('OSPF process removal, duplicate ID, persistence and startup replay', () => {
    const e=ospfLab(); commands(e,'n3','configure terminal\nrouter ospf 1\nrouter-id 1.1.1.1\nend'); assert(e.rt('n2').ospf.neighbors.some(n=>n.reason==='중복 Router ID'));
    commands(e,'n3','configure terminal\nrouter ospf 1\nrouter-id 2.2.2.2\nend');
    for(const id of ['n2','n3','n4']) commands(e,id,'copy running-config startup-config'); const p=e.export(),copy=new Engine(p).run(); assert.equal(ping(copy).results.length,1); assert.deepEqual(copy.export(),p);
    commands(e,'n2','configure terminal\nno router ospf 1\nend'); assert.equal(e.rt('n2').dynamic.length,0);
});
test('BGP bilateral peers, AS path, all valid candidates and actual traffic', () => {
    const e=bgpLab(); assert(e.rt('n2').bgp.neighbors.every(n=>n.state==='Established')); const r=e.route(e.node('n2'),'198.51.100.10'); assert.equal(r.source,'B'); assert.deepEqual(r.path,[65003]); assert.equal(r.via,'10.0.13.2');
    assert.equal(ping(e).results.length,1); assert(e.rt('n2').bgp.paths.some(p=>p.dest==='198.51.100.0/24' && p.path.join(' ')==='65002 65003'));
});
test('BGP Weight policy, peer shutdown, incorrect AS diagnosis and recovery', () => {
    const e=bgpLab(); commands(e,'n2','configure terminal\nrouter bgp 65001\nneighbor 10.0.12.2 weight 200\nend'); assert.equal(e.route(e.node('n2'),'198.51.100.10').via,'10.0.12.2'); assert.equal(ping(e).results.length,1);
    commands(e,'n2','configure terminal\nrouter bgp 65001\nneighbor 10.0.12.2 shutdown\nend'); assert.equal(e.route(e.node('n2'),'198.51.100.10').via,'10.0.13.2');
    commands(e,'n2','configure terminal\nrouter bgp 65001\nno neighbor 10.0.12.2 shutdown\nneighbor 10.0.12.2 remote-as 65099\nend'); assert.equal(e.rt('n2').bgp.neighbors.find(n=>n.ip==='10.0.12.2').reason,'Remote AS mismatch');
    commands(e,'n2','configure terminal\nrouter bgp 65001\nneighbor 10.0.12.2 remote-as 65002\nend'); assert.equal(e.route(e.node('n2'),'198.51.100.10').via,'10.0.12.2'); assert.equal(ping(e).results.length,1);
});
test('BGP exact origination and transit withdrawal reject phantom networks and own-AS loops', () => {
    const e=bgpLab(); commands(e,'n4','configure terminal\nrouter bgp 65003\nnetwork 203.0.113.0 mask 255.255.255.0\nno network 198.51.100.0 mask 255.255.255.0\nend');
    assert(!e.rt('n2').bgp.paths.some(p=>p.dest==='203.0.113.0/24')); assert(!e.route(e.node('n2'),'198.51.100.10')); assert.equal(ping(e).results.length,0);
    commands(e,'n4','configure terminal\nrouter bgp 65003\nnetwork 198.51.100.0 mask 255.255.255.0\nend'); assert.equal(ping(e).results.length,1);
    for(const n of e.project.nodes.filter(n=>n.bgp)) for(const p of e.rt(n).bgp.paths) assert(!p.path.includes(n.bgp.asn));
    const copy=new Engine(e.export()).run(); assert.equal(ping(copy).results.length,1);
});
test('dynamic config bounds reject malformed imports without mutation', () => {
    const e=bgpLab(), before=e.export(); commands(e,'n2','configure terminal\nrouter bgp 65001'); assert.throws(()=>e.execute('n2','neighbor 10.0.12.2 weight 65536')); assert.deepEqual(e.export(),before);
    const p=e.export(); p.nodes[1].bgp.neighbors.push({...p.nodes[1].bgp.neighbors[0]}); assert.throws(()=>validateProject(p));
    const previous=e.export();previous.engine='1.4.0-rc.1';assert.deepEqual(new Engine(previous).export(),e.export());
});
test('IPv6 read-only pipelines, cancellation and reload preserve configured behavior', () => {
    const e=ipv6Lab(); commands(e,'n1','ip -6 addr show | grep 2001:db8');
    const saved=e.export(); assert.throws(()=>e.execute('n1','ip -6 addr flush dev eth0 | cat')); assert.deepEqual(e.export(),saved);
    e.execute('n1','ping -6 2001:db8:20::10'); e.cancel();
    assert.equal(ping(e,'-6 2001:db8:20::10').results.length,1);
    const o=ospfLab(); commands(o,'n2','copy running-config startup-config\nconfigure terminal\nno router ospf 1\nend\nreload');
    commands(o,'n2','no\nyes'); assert.equal(ping(o).results.length,1); assert.equal(o.route(o.node('n2'),'198.51.100.10').source,'O');
});
test('OSPF and BGP ACL faults, total link loss and recovery change control state', () => {
    for(const make of [ospfLab,bgpLab]){
        const e=make(),proto=make===ospfLab?'ospf':'tcp';
        commands(e,'n2',`configure terminal\nip access-list extended CONTROL\ndeny ${proto} any any${proto==='tcp'?' eq 179':''}\npermit ip any any\nexit\ninterface Gi0/1\nip access-group CONTROL in\nend`);
        if(proto==='ospf')assert(e.rt('n2').ospf.neighbors.some(n=>n.reason==='OSPF ACL 차단'));
        else assert(e.rt('n2').bgp.neighbors.some(n=>n.reason==='TCP 179 ACL 차단'));
        assert.equal(ping(e).results.length,1);
        commands(e,'n2','configure terminal\ninterface Gi0/1\nno ip access-group CONTROL in\nend');
        assert.equal(e.rt('n2')[proto==='ospf'?'ospf':'bgp'].neighbors.filter(n=>n.state===(proto==='ospf'?'Full':'Established')).length,2);
        const project=e.export(); for(const link of project.links)if((link.a.node==='n2'||link.b.node==='n2')&&link.a.node!=='n1'&&link.b.node!=='n1')link.loss=100;
        e.configure(project);e.run();assert.equal(ping(e).results.length,0);
    }
});
console.log(`${checks} IPv6/dynamic-routing behavior checks passed.`);
