import {cidr, ipString, contains, integer} from './model.js?ver=1.4.0-rc.4';
import {winAlias, winAdapter} from './windows-config.js?ver=1.4.0-rc.4';
import {winNeed, wildcard} from './windows-parser.js?ver=1.4.0-rc.4';
import {budget} from './text-pattern.js?ver=1.4.0-rc.4';
export function selectWindowsAdapters(n, a, required = false) {
    winNeed(!(a.interfacealias !== undefined && a.interfaceindex !== undefined), 'InterfaceAlias와 InterfaceIndex 중 하나만 지정하세요.');
    if (required) winNeed(a.interfacealias !== undefined || a.interfaceindex !== undefined, '대상 InterfaceAlias 또는 InterfaceIndex가 필요합니다.');
    if (a.interfaceindex !== undefined) { const i = integer(Number(a.interfaceindex), 1, n.interfaces.length); return [n.interfaces[i - 1]]; }
    const test = a.interfacealias !== undefined ? wildcard(a.interfacealias) : () => true, meter = budget();
    const selected = n.interfaces.filter(f => test(winAlias(n, f), meter)); winNeed(selected.length, 'Windows 인터페이스가 없습니다. Get-NetAdapter로 확인하세요.'); return selected;
}
const interfaceFields = (n, f) => ({InterfaceAlias: winAlias(n, f), InterfaceIndex: n.interfaces.findIndex(x => x.name === f.name) + 1});
export function windowsAddresses(n, selected = n.interfaces) { return selected.filter(f => f.address).map(f => ({...interfaceFields(n, f), IPAddress: cidr(f.address).address, PrefixLength: cidr(f.address).bits, AddressFamily: 'IPv4', PrefixOrigin: winAdapter(n, f).dhcp ? 'Dhcp' : 'Manual', AddressState: 'Preferred'})); }
export function windowsRoutes(e, n) { return e.routes(n).map(r => { const f = n.interfaces.find(f => f.name === r.dev); return {DestinationPrefix: r.dest, NextHop: r.via || '0.0.0.0', ...interfaceFields(n, f), RouteMetric: r.metric, InterfaceMetric: winAdapter(n, f).metric, Protocol: r.connected ? 'Local' : 'NetMgmt'}; }); }
export function windowsAdapters(e, n) { return n.interfaces.map(f => ({Name: winAlias(n, f), ifIndex: n.interfaces.findIndex(x => x.name === f.name) + 1, Status: !f.up ? 'Disabled' : e.portUp(n, f) ? 'Up' : 'Disconnected', MacAddress: f.mac.replace(/:/g, '-').toUpperCase()})); }
export function windowsIPConfiguration(e, n, all = false, detailed = false) { return n.interfaces.filter(f => all || e.portUp(n, f)).map(f => ({...interfaceFields(n, f), IPv4Address: windowsAddresses(n, [f]), IPv4DefaultGateway: n.routes.filter(r => r.dev === f.name && r.dest === '0.0.0.0/0').map(r => r.via), DNSServer: winAdapter(n, f).dns ? [winAdapter(n, f).dns] : [], ...(detailed ? {ComputerName: n.name, NetProfile: {NetworkCategory: winAdapter(n, f).category}, NetIPv4Interface: {Dhcp: winAdapter(n, f).dhcp ? 'Enabled' : 'Disabled', InterfaceMetric: winAdapter(n, f).metric}} : {})})); }
export function windowsConnections(e, n, listening = true) {
    const rows = [], rt = e.rt(n);
    if (listening && n.services.http) rows.push({LocalAddress: '0.0.0.0', LocalPort: n.services.http.port, RemoteAddress: '0.0.0.0', RemotePort: 0, State: 'Listen'});
    for (const j of e.jobs.values()) if (j.node === n.id && ['curl', 'tcp'].includes(j.type) && j.status === 'pending') { const r = e.route(n, j.dest), f = n.interfaces.find(f => f.name === r?.dev); rows.push({LocalAddress: f?.address ? cidr(f.address).address : '0.0.0.0', LocalPort: j.sourcePort, RemoteAddress: j.dest, RemotePort: j.port, State: j.tcpState === 'ESTABLISHED' ? 'Established' : 'SynSent'}); }
    for (const entry of rt.tcp.values()) if (entry.until > e.now && entry.state !== 'CLOSED') { const split = entry.remote.lastIndexOf(':'), address = n.interfaces.find(f => f.address)?.address; rows.push({LocalAddress: address ? cidr(address).address : '0.0.0.0', LocalPort: entry.localPort, RemoteAddress: entry.remote.slice(0, split), RemotePort: Number(entry.remote.slice(split + 1)), State: entry.state === 'ESTABLISHED' ? 'Established' : entry.state === 'CLOSE-WAIT' ? 'CloseWait' : 'SynReceived'}); }
    return rows;
}
export function windowsFirewallRows(n) { return n.windows.rules.map(r => ({Name: r.name, DisplayName: r.displayName, Enabled: r.enabled, Direction: r.direction, Action: r.action, Profile: [...r.profiles]})); }
export function windowsMask(f) { return f.address ? ipString(cidr(f.address).mask) : ''; }
export function windowsGateway(n, f) { return n.routes.find(r => r.dev === f.name && r.dest === '0.0.0.0/0')?.via || ''; }
export function windowsLocalSubnet(n, ip) { return n.interfaces.some(f => f.address && contains(f.address, ip)); }
