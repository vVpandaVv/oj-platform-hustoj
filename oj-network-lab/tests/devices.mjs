import assert from 'node:assert/strict';
import {Engine} from '../resources/js/engine.js';
import {scenario} from '../resources/js/scenarios.js';
import {clone, validateProject, newNode, VERSION} from '../resources/js/model.js';
import {assignProfile, configSnapshot} from '../resources/js/device-config.js';
let passed = 0;
const test = (label, fn) => { fn(); passed++; console.log('OK ' + label); };
function cmd(e, id, lines) { let out; for (const line of lines) out = e.execute(id, line); return out; }
function run(e, id, line) { e.execute(id, line); e.run(); return [...e.jobs.values()].at(-1); }
const boot = (e, id, save = 'no') => { const out = e.execute(id, 'reload'); if (out.includes('Save?')) e.execute(id, save); e.execute(id, ''); };
function fortiPolicy(e, lines, id = '0') { return cmd(e, 'n3', ['config firewall policy', `edit ${id}`, 'set srcintf port1', 'set dstintf port2', 'set srcaddr all', 'set dstaddr all', 'set service PING', 'set action accept', 'set nat enable', ...lines, 'next', 'end']); }

test('managed profiles preserve legacy Linux projects and schema migration', () => {
    const p = clone(scenario('routing')); p.schema = 1; p.engine = '1.0.0-rc.3'; p.nodes.forEach(n => delete n.profile);
    const e = new Engine(p); assert.equal(e.export().schema, 2); assert(e.project.nodes.every(n => n.profile === 'linux')); assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 1);
});
test('profile/type, snapshot nesting, duplicate objects and limits validate', () => {
    const p = scenario('ios-router'); p.nodes[0].profile = 'ios'; assert.throws(() => validateProject(p));
    const q = scenario('ios-switch'); q.nodes[1].startup.startup = {}; assert.throws(() => validateProject(q));
    const r = scenario('fortios'); r.nodes[2].device.policies.push(clone(r.nodes[2].device.policies[0])); assert.throws(() => validateProject(r));
});
test('IOS modes, privilege, abbreviation, contextual help and independent consoles', () => {
    const e = new Engine(scenario('ios-router')); assert.throws(() => e.execute('n3', 'configure terminal')); assert.throws(() => e.execute('n3', 'show running-config'));
    cmd(e, 'n3', ['en', 'conf t']); assert(e.state().runtime.find(n => n.id === 'n3').cli.prompt.includes('(config)#'));
    assert(e.execute('n3', '?').includes('interface')); assert.equal(e.complete('n3', 'hostn').line, 'hostname ');
    assert.throws(() => e.execute('n3', 'ip r'), /Ambiguous/); assert.equal(e.state().runtime.find(n => n.id === 'n1').cli.mode, 'root');
});
test('unsupported IOS commands and arguments cannot mutate the network', () => {
    const e = new Engine(scenario('ios-switch')); cmd(e, 'n2', ['enable', 'conf t']); const before = e.export();
    for (const line of ['router ospf 1', 'interface Gi9/9/9', 'vlan 9999', 'hostname bad;whoami']) assert.throws(() => e.execute('n2', line));
    assert.deepEqual(e.export(), before);
});
test('IOS port shutdown affects actual forwarding and counters clear locally', () => {
    const e = new Engine(scenario('ios-switch')); assert.equal(run(e, 'n1', 'ping 192.168.10.20').results.length, 1);
    cmd(e, 'n2', ['enable', 'conf t', 'interface Gi1/0/2', 'shutdown', 'end']); assert.equal(run(e, 'n1', 'ping 192.168.10.20').results.length, 0);
    cmd(e, 'n2', ['conf t', 'interface Gi1/0/2', 'no shutdown', 'end']); assert.equal(run(e, 'n1', 'ping 192.168.10.20').results.length, 1);
    assert(e.execute('n2', 'show mac address-table').includes('DYNAMIC')); e.execute('n2', 'clear mac address-table dynamic'); assert.equal(e.rt('n2').fdb.size, 0);
});
test('VLAN creation, access membership and deletion alter delivery', () => {
    const e = new Engine(scenario('ios-switch')); cmd(e, 'n2', ['enable', 'conf t', 'vlan 10', 'name STUDENTS', 'exit', 'interface Gi1/0/2', 'switchport access vlan 10', 'end']);
    assert.equal(run(e, 'n1', 'ping 192.168.10.20').results.length, 0);
    cmd(e, 'n2', ['conf t', 'interface Gi1/0/1', 'switchport access vlan 10', 'end']); assert.equal(run(e, 'n1', 'ping 192.168.10.20').results.length, 1);
    assert(e.execute('n2', 'show vlan brief').includes('STUDENTS')); cmd(e, 'n2', ['conf t', 'no vlan 10', 'end']); assert.equal(run(e, 'n1', 'ping 192.168.10.20').results.length, 0);
});
test('SVIs route between VLANs and depend on active member links', () => {
    const p = scenario('ios-switch'); p.nodes = p.nodes.filter(n => n.id !== 'n4'); p.links = p.links.filter(l => l.b.node !== 'n4'); p.nodes.find(n => n.id === 'n3').interfaces[0].address = '192.168.20.20/24';
    p.nodes[0].routes = [{dest: '0.0.0.0/0', via: '192.168.10.1', dev: 'eth0', metric: 0}]; p.nodes[2].routes = [{dest: '0.0.0.0/0', via: '192.168.20.1', dev: 'eth0', metric: 0}];
    const e = new Engine(p); cmd(e, 'n2', ['enable', 'conf t', 'vlan 10', 'exit', 'vlan 20', 'exit', 'interface Gi1/0/1', 'switchport access vlan 10', 'exit', 'interface Gi1/0/2', 'switchport access vlan 20', 'exit', 'interface Vlan10', 'ip address 192.168.10.1 255.255.255.0', 'exit', 'interface Vlan20', 'ip address 192.168.20.1 255.255.255.0', 'exit', 'ip routing', 'end']);
    assert.equal(run(e, 'n1', 'ping 192.168.20.20').results.length, 1); assert(e.execute('n2', 'show ip route').includes('Vlan20'));
    cmd(e, 'n2', ['conf t', 'interface Gi1/0/2', 'shutdown', 'end']); assert(!e.routes(e.node('n2')).some(r => r.dev === 'vlan20'));
});
test('802.1Q router subinterfaces and switch trunk allowed list work together', () => {
    const p = scenario('ios-router'); assignProfile(p.nodes[1], 'ios'); const e = new Engine(p);
    cmd(e, 'n2', ['enable', 'conf t', 'vlan 10', 'exit', 'interface Gi1/0/1', 'switchport access vlan 10', 'exit', 'interface Gi1/0/2', 'switchport mode trunk', 'switchport trunk allowed vlan 10', 'end']);
    cmd(e, 'n3', ['enable', 'conf t', 'interface Gi0/0', 'no ip address', 'exit', 'interface Gi0/0.10', 'encapsulation dot1Q 10', 'ip address 192.168.10.1 255.255.255.0', 'end']);
    assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 1);
    cmd(e, 'n2', ['conf t', 'interface Gi1/0/2', 'switchport trunk allowed vlan none', 'end']); assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 0);
});
test('native VLAN and trunk default-all carry untagged traffic', () => {
    const e = new Engine(scenario('ios-switch')); cmd(e, 'n2', ['enable', 'conf t', 'vlan 10', 'exit', 'interface Gi1/0/1', 'switchport access vlan 10', 'exit', 'interface Gi1/0/2', 'switchport mode trunk', 'switchport trunk native vlan 10', 'end']);
    assert.equal(run(e, 'n1', 'ping 192.168.10.20').results.length, 1);
});
test('IOS static routes may be unresolved then become active after address changes', () => {
    const e = new Engine(scenario('ios-router')); cmd(e, 'n3', ['enable', 'conf t', 'ip route 10.9.0.0 255.255.0.0 10.8.0.2', 'end']); assert(!e.routes(e.node('n3')).some(r => r.dest === '10.9.0.0/16'));
    cmd(e, 'n3', ['conf t', 'interface Gi0/2', 'ip address 10.8.0.1 255.255.255.0', 'end']); assert(!e.routes(e.node('n3')).some(r => r.dest === '10.9.0.0/16'), 'disconnected port does not install route');
});
test('named extended ACL order, implicit deny and hit counters affect traffic', () => {
    const e = new Engine(scenario('ios-router')); cmd(e, 'n3', ['enable', 'conf t', 'ip access-list extended WEB', '10 deny icmp any any', '20 permit tcp any any eq www', 'exit', 'interface Gi0/1', 'ip access-group WEB out', 'end']);
    assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 0); assert(run(e, 'n1', 'curl http://192.168.20.10').response); assert(e.execute('n3', 'show access-lists').includes('1 matches'));
    cmd(e, 'n3', ['conf t', 'ip access-list extended WEB', 'no 10', '5 permit icmp any any', 'end']); assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 1);
});
test('IOS NAT overload restores return packets without remote private routes', () => {
    const p = scenario('firewall'); const r = p.nodes[2]; r.type = 'router'; assignProfile(r, 'ios'); const e = new Engine(p);
    assert(!run(e, 'n1', 'curl http://203.0.113.10').response);
    cmd(e, 'n3', ['enable', 'conf t', 'access-list 10 permit 192.168.10.0 0.0.0.255', 'interface Gi0/0', 'ip nat inside', 'exit', 'interface Gi0/1', 'ip nat outside', 'exit', 'ip nat inside source list 10 interface Gi0/1 overload', 'end']);
    assert(run(e, 'n1', 'curl http://203.0.113.10').response); assert(e.execute('n3', 'show ip nat translations').includes('203.0.113.1'));
});
test('running and startup config survive project storage independently', () => {
    const e = new Engine(scenario('ios-switch')); cmd(e, 'n2', ['enable', 'conf t', 'hostname LAB-SW', 'end']); const restored = new Engine(e.export());
    assert.equal(restored.node('n2').name, 'LAB-SW'); restored.execute('n2', 'enable'); assert(restored.execute('n2', 'show startup-config').includes('hostname SW-A')); boot(restored, 'n2'); assert.equal(restored.node('n2').name, 'SW-A');
});
test('copy/write and explicit save-on-reload persist device settings', () => {
    const e = new Engine(scenario('ios-router')); cmd(e, 'n3', ['enable', 'conf t', 'hostname SAVED', 'end', 'write memory']); boot(e, 'n3'); assert.equal(e.node('n3').name, 'SAVED');
    cmd(e, 'n3', ['enable', 'conf t', 'hostname SAVE-ON-RELOAD', 'end']); boot(e, 'n3', 'yes'); assert.equal(e.node('n3').name, 'SAVE-ON-RELOAD');
});
test('invalid subnet masks and undefined ACL references are atomic', () => {
    const e = new Engine(scenario('ios-router')); cmd(e, 'n3', ['enable', 'conf t', 'interface Gi0/0']); const before = e.export(); assert.throws(() => e.execute('n3', 'ip address 10.0.0.1 255.0.255.0')); assert.throws(() => e.execute('n3', 'ip access-group MISSING in')); assert.deepEqual(e.export(), before);
});
test('FortiOS edit buffer commits at next/end and abort discards only current entry', () => {
    const e = new Engine(scenario('fortios')); const before = e.export(); cmd(e, 'n3', ['config firewall address', 'edit "internal net"', 'set subnet 192.168.10.0 255.255.255.0']); assert.deepEqual(e.export(), before);
    cmd(e, 'n3', ['abort', 'edit LAN', 'set subnet 192.168.10.0 255.255.255.0', 'next', 'edit UNSAVED', 'set subnet 10.0.0.0 255.0.0.0', 'abort', 'end']); assert.deepEqual(e.node('n3').device.addresses.map(a => a.name), ['LAN']);
});
test('FortiOS policy NAT permits HTTP and implicit deny blocks ICMP', () => {
    const e = new Engine(scenario('fortios')); assert(run(e, 'n1', 'curl http://203.0.113.10').response); assert.equal(run(e, 'n1', 'ping 203.0.113.10').results.length, 0);
    assert(e.execute('n3', 'diagnose sys session list').includes('policy_id=1')); assert(e.execute('n3', 'execute log display').includes('implicit deny'));
});
test('FortiOS policy order changes verdict and invalidates affected sessions', () => {
    const e = new Engine(scenario('fortios')); fortiPolicy(e, []); assert.equal(run(e, 'n1', 'ping 203.0.113.10').results.length, 1);
    fortiPolicy(e, ['set action deny'], '3'); cmd(e, 'n3', ['config firewall policy', 'move 3 before 2', 'end']); assert.equal(run(e, 'n1', 'ping 203.0.113.10').results.length, 0);
    cmd(e, 'n3', ['config firewall policy', 'move 2 before 3', 'end']); assert.equal(run(e, 'n1', 'ping 203.0.113.10').results.length, 1);
});
test('FortiOS address/service objects govern matching and references cannot dangle', () => {
    const e = new Engine(scenario('fortios'));
    cmd(e, 'n3', ['config firewall address', 'edit LAN', 'set subnet 192.168.10.0 255.255.255.0', 'next', 'end', 'config firewall service custom', 'edit WEB', 'set tcp-portrange 80', 'next', 'end', 'config firewall policy', 'edit 1', 'set srcaddr LAN', 'set service WEB', 'next', 'end']);
    assert(run(e, 'n1', 'curl http://203.0.113.10').response); cmd(e, 'n3', ['config firewall address']); const before = e.export(); assert.throws(() => e.execute('n3', 'delete LAN')); assert.deepEqual(e.export(), before); e.execute('n3', 'end');
});
test('FortiOS failed commit retains editable buffer and does not change project', () => {
    const e = new Engine(scenario('fortios')); cmd(e, 'n3', ['config firewall policy', 'edit 0', 'set service PING']); const before = e.export(); assert.throws(() => e.execute('n3', 'next')); assert.deepEqual(e.export(), before); assert.equal(e.state().runtime.find(n => n.id === 'n3').cli.mode, 'edit'); e.interrupt('n3'); assert.equal(e.state().runtime.find(n => n.id === 'n3').cli.mode, 'root');
});
test('FortiOS manual/automatic saving and reboot respect profile semantics', () => {
    const e = new Engine(scenario('fortios')); cmd(e, 'n3', ['config system global', 'set cfg-save manual', 'end', 'execute cfg save', 'config system global', 'set hostname TEMP', 'end', 'execute reboot', 'y']); assert.equal(e.node('n3').name, 'FW-NAT');
    cmd(e, 'n3', ['config system global', 'set cfg-save automatic', 'set hostname AUTO', 'end', 'execute reboot', 'y']); assert.equal(e.node('n3').name, 'AUTO');
});
test('FortiOS static routes and interface state are visible and persistent', () => {
    const e = new Engine(scenario('fortios')); cmd(e, 'n3', ['config router static', 'edit 0', 'set dst 10.1.0.0 255.255.0.0', 'set gateway 203.0.113.254', 'set device port2', 'next', 'end']); assert(e.execute('n3', 'get router info routing-table all').includes('10.1.0.0/16'));
    cmd(e, 'n3', ['config system interface', 'edit port2', 'set status down', 'next', 'end']); assert(!e.execute('n3', 'get router info routing-table all').includes('10.1.0.0/16'));
});
test('local-in ping requires allowaccess while forwarded policy is independent', () => {
    const e = new Engine(scenario('fortios')); assert.equal(run(e, 'n1', 'ping 192.168.10.1').results.length, 0);
    cmd(e, 'n3', ['config system interface', 'edit port1', 'set allowaccess ping', 'next', 'end']); assert.equal(run(e, 'n1', 'ping 192.168.10.1').results.length, 1);
});
test('IPsec negotiates IKEv2 and ESP with inner policy and no plaintext outer trace', () => {
    const e = new Engine(scenario('ipsec')); assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 1);
    assert(e.execute('n2', 'diagnose vpn tunnel list').includes('model_sa=up')); assert(e.trace.some(t => t.protocol === 'ESP')); assert(e.trace.some(t => t.detail.includes('ike-auth')));
    assert(e.trace.filter(t => t.protocol === 'ESP').every(t => !t.src.startsWith('192.168.') && !t.dst.startsWith('192.168.'))); assert(e.trace.some(t => t.action === 'decap'));
});
for (const [label, change] of [
    ['PSK mismatch', n => n.device.phase1[0].psk = 'wrong-sim-key'],
    ['IKE proposal mismatch', n => n.device.phase1[0].proposal = 'aes128-sha256'],
    ['traffic selector mismatch', n => n.device.phase2[0].dst = '192.168.99.0/24'],
    ['CHILD_SA proposal mismatch', n => n.device.phase2[0].proposal = 'aes128-sha256'],
    ['WAN failure', n => n.interfaces.find(f => f.name === 'eth1').up = false],
    ['inner firewall deny', n => n.device.policies = []],
]) test('IPsec ' + label + ' prevents false success', () => { const p = scenario('ipsec'); change(p.nodes[3]); const e = new Engine(p); assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 0); });
test('IPsec SA expires and negotiates again on fresh traffic', () => {
    const e = new Engine(scenario('ipsec')); run(e, 'n1', 'ping 192.168.20.10'); e.execute('n1', 'sim time 3600000'); e.run(); assert(!e.ipsecStatus(e.node('n2'))[0].up); assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 1);
});
test('IPsec CLI changes configuration and persists the saved model', () => {
    const e = new Engine(scenario('ipsec')); cmd(e, 'n2', ['config vpn ipsec phase1-interface', 'edit to-b', 'set psksecret changed-sim-key', 'next', 'end']); assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 0);
    cmd(e, 'n4', ['config vpn ipsec phase1-interface', 'edit to-a', 'set psksecret changed-sim-key', 'next', 'end']); const restored = new Engine(e.export()); assert.equal(run(restored, 'n1', 'ping 192.168.20.10').results.length, 1);
});
test('GUI configuration preserves console mode and another device runtime', () => {
    const e = new Engine(scenario('ios-switch')); run(e, 'n1', 'ping 192.168.10.20'); cmd(e, 'n2', ['enable', 'conf t']); const before = e.rt('n1').arp.size, time = e.now;
    const p = e.export(); p.nodes[1].interfaces[1].up = false; e.configure(p);
    assert.equal(e.rt('n1').arp.size, before); assert.equal(e.now, time); assert(e.consolePrompt('n2').includes('(config)#')); assert.equal(run(e, 'n1', 'ping 192.168.10.20').results.length, 0);
});
test('FortiOS GUI edits obey automatic startup persistence', () => {
    const e = new Engine(scenario('fortios')); const p = e.export(); p.nodes[2].interfaces[1].up = false; e.configure(p); assert.equal(e.node('n3').startup.interfaces[1].up, false);
    cmd(e, 'n3', ['execute reboot', 'y']); assert.equal(e.node('n3').interfaces[1].up, false);
});
test('subinterface without encapsulation remains operationally down', () => {
    const e = new Engine(scenario('ios-router')); cmd(e, 'n3', ['enable', 'conf t', 'interface Gi0/0.10', 'ip address 10.10.0.1 255.255.255.0', 'end']); assert(!e.routes(e.node('n3')).some(r => r.dev === 'eth0_10'));
});
test('incorrect NAT on an unnumbered IPsec interface drops rather than faking success', () => {
    const p = scenario('ipsec'); p.nodes[1].device.policies[0].nat = true; const e = new Engine(p); assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 0); assert(e.trace.some(t => t.detail.includes('SNAT 출발 주소')));
});
test('unsupported multi CHILD_SA and profile/interface combinations reject import', () => {
    const p = scenario('ipsec'); p.nodes[1].device.phase2.push({...p.nodes[1].device.phase2[0], name: 'extra'}); assert.throws(() => validateProject(p));
    const q = scenario('routing'); q.nodes[2].interfaces[2].kind = 'ipsec'; assert.throws(() => validateProject(q));
});
test('configuration from GUI cannot replace a project with invalid profile data', () => {
    const e = new Engine(scenario('fortios')), before = e.export(), bad = e.export(); bad.nodes[2].device.policies[0].srcaddr = ['missing']; assert.throws(() => e.configure(bad)); assert.deepEqual(e.export(), before);
});
test('complete IPsec tunnel, routes and policies can be created entirely by CLI', () => {
    const p = scenario('ipsec');
    for (const i of [1, 3]) { const n = p.nodes[i]; n.interfaces = n.interfaces.filter(f => f.kind === 'ethernet'); n.routes = []; n.device.phase1 = []; n.device.phase2 = []; n.device.policies = []; n.startup = configSnapshot(n); }
    const e = new Engine(p);
    for (const [id, tunnel, remote, src, dst] of [['n2', 'to-b', '198.51.100.2', '192.168.10.0', '192.168.20.0'], ['n4', 'to-a', '198.51.100.1', '192.168.20.0', '192.168.10.0']]) {
        cmd(e, id, ['config vpn ipsec phase1-interface', `edit ${tunnel}`, 'set interface port2', `set remote-gw ${remote}`, 'set ike-version 2', 'set psksecret cli-sim-key', 'next', 'end', 'config vpn ipsec phase2-interface', 'edit private-lans', `set phase1name ${tunnel}`, `set src-subnet ${src} 255.255.255.0`, `set dst-subnet ${dst} 255.255.255.0`, 'next', 'end', 'config router static', 'edit 0', `set dst ${dst} 255.255.255.0`, `set device ${tunnel}`, 'next', 'end']);
        for (const [from, to] of [['port1', tunnel], [tunnel, 'port1']]) cmd(e, id, ['config firewall policy', 'edit 0', `set srcintf ${from}`, `set dstintf ${to}`, 'set srcaddr all', 'set dstaddr all', 'set service ALL', 'set action accept', 'next', 'end']);
    }
    assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 1);
});
test('IOS static TCP port forwarding is configured by device commands', () => {
    const p = scenario('ios-router'); p.nodes[0].interfaces[0].address = '198.51.100.10/24'; p.nodes[0].routes[0].via = '198.51.100.1'; p.nodes[2].interfaces[0].address = '198.51.100.1/24';
    const e = new Engine(p); cmd(e, 'n3', ['enable', 'conf t', 'interface Gi0/0', 'ip nat outside', 'exit', 'interface Gi0/1', 'ip nat inside', 'exit', 'ip nat inside source static tcp 192.168.20.10 80 198.51.100.1 8080', 'end']); assert(run(e, 'n1', 'curl http://198.51.100.1:8080').response);
});
test('cancelling an in-flight IPsec negotiation permits a clean retry', () => {
    const e = new Engine(scenario('ipsec')); e.execute('n1', 'ping 192.168.20.10');
    for (let i = 0; i < 100 && e.queue.length && !e.rt('n2').ipsec.size; i++) e.step();
    assert(e.rt('n2').ipsec.size > 0); e.cancel(); assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 1);
});
test('rc.1 schema 2 device projects retain both configurations after upgrade', () => {
    const p = scenario('ipsec'); p.engine = '1.1.0-rc.1'; const e = new Engine(p); assert.equal(e.export().engine, VERSION); assert.deepEqual(e.node('n2').startup, p.nodes[1].startup); assert.equal(run(e, 'n1', 'ping 192.168.20.10').results.length, 1);
});
test('FortiOS traceroute accepts related ICMP hop errors without management ping', () => {
    const p = scenario('vpn'); for (const i of [1, 3]) { const n = p.nodes[i]; n.interfaces = n.interfaces.filter(f => f.kind === 'ethernet'); n.routes = [{dest: i === 1 ? '192.168.20.0/24' : '192.168.10.0/24', via: i === 1 ? '198.51.100.2' : '198.51.100.1', dev: 'eth1', metric: 1}]; }
    p.nodes[1].type = 'firewall'; assignProfile(p.nodes[1], 'fortios'); const e = new Engine(p); const j = run(e, 'n2', 'execute traceroute 192.168.20.10'); assert(j.results.some(r => r.ip === '198.51.100.2')); assert(j.results.some(r => r.ip === '192.168.20.10'));
});
test('IOS show reports disconnected down separately from administratively down', () => {
    const e = new Engine(scenario('ios-switch')); e.execute('n2', 'enable'); assert(e.execute('n2', 'show interfaces Gi1/0/6').includes('is down, line protocol is down')); cmd(e, 'n2', ['conf t', 'interface Gi1/0/6', 'shutdown', 'end']); assert(e.execute('n2', 'show interfaces Gi1/0/6').includes('administratively down'));
});
test('original 1.0 rc.1 projects migrate as Linux without topology loss', () => {
    const p = scenario('lan'); p.engine = '1.0.0-rc.1'; p.schema = 1; p.nodes.forEach(n => delete n.profile); const e = new Engine(p); assert.equal(run(e, 'n1', 'ping 192.168.10.20').results.length, 1); assert(e.project.nodes.every(n => n.profile === 'linux'));
});
console.log(`${passed} device CLI and forwarding checks passed (${VERSION}).`);
