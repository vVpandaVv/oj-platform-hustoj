import {cidr, ipv4, integer, contains, validateProject} from './model.js?ver=1.4.0-rc.4';
import {update} from './cli-common.js?ver=1.4.0-rc.4';
import {winAlias, winAdapter, WIN_PROFILES} from './windows-config.js?ver=1.4.0-rc.4';
import {selectWindowsAdapters, windowsAddresses, windowsRoutes, windowsAdapters, windowsIPConfiguration, windowsConnections, windowsFirewallRows} from './windows-query.js?ver=1.4.0-rc.4';
import {psArguments, winNeed, winEnum, winBool, winList, wildcard} from './windows-parser.js?ver=1.4.0-rc.4';
import {budget} from './text-pattern.js?ver=1.4.0-rc.4';
const selectors = ['interfacealias', 'interfaceindex'];
const family = a => { if (a.addressfamily) winNeed(a.addressfamily.toLowerCase() === 'ipv4', '현재 Windows 프로필은 IPv4만 지원합니다.'); };
const dryRun = (a, command) => a.whatif ? {text: `What if: ${command} (가상 설정을 변경하지 않았습니다.)`} : null;
function change(e, n, a, command, fn) { const project = e.export(); fn(project.nodes.find(x => x.id === n.id)); const next = validateProject(project), dry = dryRun(a, command); if (dry) return dry; e.project = next; e.revision++; e.configurationChanged(n, e.node(n.id)); return {rows: []}; }
export function renewWindows(e, n, selected, presentation = 'cmd-dhcp') {
    winNeed(selected.length && selected.every(f => winAdapter(n, f).dhcp && f.up), 'DHCP가 켜진 Ethernet 인터페이스가 필요합니다.');
    winNeed([...e.jobs.values()].filter(j => j.status === 'pending').length + selected.length <= 20, '진행 중 통신 한도를 초과합니다.');
    for (const f of selected) { for (const old of e.jobs.values()) if (old.node === n.id && old.type === 'dhcp' && old.port === f.name && old.status === 'pending') old.status = 'cancelled'; const job = e.startDHCP(n, f.name); job.windows = presentation; }
}
export function releaseWindows(e, n, selected) {
    winNeed(selected.length && selected.every(f => winAdapter(n, f).dhcp), 'DHCP 인터페이스만 release할 수 있습니다.');
    const names = selected.map(f => f.name);
    update(e, n, x => { for (const f of x.interfaces.filter(f => names.includes(f.name))) { f.address = ''; const a = winAdapter(x, f); a.leaseDns = ''; if (a.dnsAutomatic) a.dns = ''; } x.routes = x.routes.filter(r => !names.includes(r.dev)); });
    e.rt(n).dns.clear(); for (const j of e.jobs.values()) if (j.node === n.id && j.type === 'dhcp' && names.includes(j.port) && j.status === 'pending') j.status = 'cancelled';
}
export function powershellNetwork(e, n, tokens) {
    const command = tokens[0].toLowerCase(); let a;
    if (command === 'get-netadapter') { a = psArguments(tokens, ['name']); const test = wildcard(a.name || '*'), meter = budget(); return {rows: windowsAdapters(e, n).filter(r => test(r.Name, meter))}; }
    if (['get-netipconfiguration', 'get-netipaddress', 'get-netipinterface', 'get-netconnectionprofile', 'get-dnsclientserveraddress', 'get-netneighbor', 'get-netroute'].includes(command)) {
        a = psArguments(tokens, [...selectors, ...(!['get-netipconfiguration', 'get-netconnectionprofile'].includes(command) ? ['addressfamily'] : []), ...(command === 'get-netipaddress' ? ['ipaddress'] : []), ...(command === 'get-netroute' ? ['destinationprefix'] : [])], command === 'get-netipconfiguration' ? ['all', 'detailed'] : []); family(a);
        const selected = selectWindowsAdapters(n, a), names = new Set(selected.map(f => f.name)), indexes = new Set(selected.map(f => n.interfaces.indexOf(f) + 1));
        if (command === 'get-netipconfiguration') return {rows: windowsIPConfiguration(e, n, a.all, a.detailed).filter(r => indexes.has(r.InterfaceIndex)), list: true};
        if (command === 'get-netipaddress') { if (a.ipaddress) ipv4(a.ipaddress); return {rows: windowsAddresses(n, selected).filter(r => !a.ipaddress || r.IPAddress === a.ipaddress)}; }
        if (command === 'get-netroute') { if (a.destinationprefix) cidr(a.destinationprefix); return {rows: windowsRoutes(e, n).filter(r => indexes.has(r.InterfaceIndex) && (!a.destinationprefix || r.DestinationPrefix === a.destinationprefix))}; }
        if (command === 'get-netneighbor') return {rows: [...e.rt(n).arp.values()].filter(r => r.until > e.now && names.has(r.port)).map(r => ({InterfaceAlias: winAlias(n, n.interfaces.find(f => f.name === r.port)), IPAddress: r.ip, LinkLayerAddress: r.mac.replace(/:/g, '-').toUpperCase(), State: 'Reachable'}))};
        return {rows: selected.map(f => { const base = {InterfaceAlias: winAlias(n, f), InterfaceIndex: n.interfaces.indexOf(f) + 1}, meta = winAdapter(n, f); return command === 'get-netconnectionprofile' ? {...base, Name: `Network-${n.interfaces.indexOf(f) + 1}`, NetworkCategory: meta.category} : command === 'get-dnsclientserveraddress' ? {...base, AddressFamily: 'IPv4', ServerAddresses: meta.dns ? [meta.dns] : []} : {...base, AddressFamily: 'IPv4', Dhcp: meta.dhcp ? 'Enabled' : 'Disabled', InterfaceMetric: meta.metric, ConnectionState: e.portUp(n, f) ? 'Connected' : 'Disconnected'}; })};
    }
    if (command === 'get-nettcpconnection') {
        a = psArguments(tokens, ['state', 'localport', 'remoteport']);
        if (a.localport) integer(Number(a.localport), 1, 65535); if (a.remoteport) integer(Number(a.remoteport), 1, 65535);
        return {rows: windowsConnections(e, n).filter(r => (!a.state || r.State.toLowerCase() === a.state.toLowerCase()) && (!a.localport || r.LocalPort === Number(a.localport)) && (!a.remoteport || r.RemotePort === Number(a.remoteport)))};
    }
    if (['new-netipaddress', 'set-netipaddress', 'remove-netipaddress'].includes(command)) {
        a = psArguments(tokens, [...selectors, 'ipaddress', 'addressfamily', ...(command === 'remove-netipaddress' ? [] : ['prefixlength']), ...(command === 'new-netipaddress' ? ['defaultgateway'] : [])], ['whatif']); family(a);
        const selected = selectWindowsAdapters(n, a, true); winNeed(selected.length === 1, '주소 설정은 인터페이스 하나를 지정하세요.'); const f = selected[0], current = f.address && cidr(f.address);
        if (a.ipaddress) ipv4(a.ipaddress);
        const prefix = command !== 'remove-netipaddress' ? integer(Number(a.prefixlength), 1, 32) : 0;
        if (command === 'new-netipaddress') {
            winNeed(a.ipaddress && (!current || winAdapter(n, f).dhcp), '인터페이스당 IPv4 주소 하나를 지원합니다. 기존 정적 주소는 먼저 Remove-NetIPAddress로 지우세요.');
            if (a.defaultgateway) { ipv4(a.defaultgateway); winNeed(contains(`${a.ipaddress}/${prefix}`, a.defaultgateway), '기본 게이트웨이는 직접 연결 서브넷에 있어야 합니다.'); }
        } else winNeed(current && (!a.ipaddress || current.address === a.ipaddress), '지정한 기존 IP 주소가 없습니다. Set-NetIPAddress는 기존 주소의 접두사를 바꿉니다.');
        const result = change(e, n, a, tokens[0], x => { const port = x.interfaces.find(p => p.name === f.name), meta = winAdapter(x, port); port.address = command === 'remove-netipaddress' ? '' : `${command === 'new-netipaddress' ? a.ipaddress : current.address}/${prefix}`; if (command === 'new-netipaddress') { meta.dhcp = false; if (a.defaultgateway) { x.routes = x.routes.filter(r => r.dest !== '0.0.0.0/0' || r.dev !== f.name); x.routes.push({dest: '0.0.0.0/0', via: a.defaultgateway, dev: f.name, metric: 0}); } } });
        return a.whatif || command !== 'new-netipaddress' ? result : {rows: windowsAddresses(e.node(n.id), [e.node(n.id).interfaces.find(p => p.name === f.name)])};
    }
    if (['set-netipinterface', 'set-netconnectionprofile', 'set-dnsclientserveraddress'].includes(command)) {
        const extra = command === 'set-netipinterface' ? ['dhcp', 'interfacemetric'] : command === 'set-netconnectionprofile' ? ['networkcategory'] : ['serveraddresses'];
        a = psArguments(tokens, [...selectors, ...(command === 'set-netipinterface' ? ['addressfamily'] : []), ...extra], ['whatif', ...(command === 'set-dnsclientserveraddress' ? ['resetserveraddresses'] : [])]); family(a); const selected = selectWindowsAdapters(n, a, true), names = selected.map(f => f.name);
        let dhcp, metric, category, dns;
        if (command === 'set-netipinterface') { winNeed(a.dhcp !== undefined || a.interfacemetric !== undefined, 'Dhcp 또는 InterfaceMetric을 지정하세요.'); if (a.dhcp) dhcp = winEnum(a.dhcp, ['Enabled', 'Disabled']) === 'Enabled'; if (a.interfacemetric !== undefined) metric = integer(Number(a.interfacemetric), 0, 65535); }
        if (command === 'set-netconnectionprofile') category = winEnum(a.networkcategory, ['Public', 'Private']);
        if (command === 'set-dnsclientserveraddress') { winNeed(!!a.serveraddresses !== !!a.resetserveraddresses, 'ServerAddresses 또는 ResetServerAddresses 중 하나를 지정하세요.'); if (a.serveraddresses) { winNeed(winList(a.serveraddresses).length === 1, '인터페이스당 DNS 서버 하나를 지원합니다.'); ipv4(a.serveraddresses); dns = a.serveraddresses; } }
        const renewing = dhcp === true ? selected.filter(f => f.up) : [];
        if (renewing.length && !a.whatif) winNeed([...e.jobs.values()].filter(j => j.status === 'pending').length + renewing.length <= 20, '진행 중 통신 한도를 초과합니다.');
        const result = change(e, n, a, tokens[0], x => { for (const f of x.interfaces.filter(p => names.includes(p.name))) { const meta = winAdapter(x, f); if (dhcp !== undefined) { if (meta.dhcp !== dhcp || dhcp) { f.address = ''; x.routes = x.routes.filter(r => r.dev !== f.name || r.dest !== '0.0.0.0/0'); } meta.dhcp = dhcp; } if (metric !== undefined) meta.metric = metric; if (category) meta.category = category; if (dns !== undefined) { meta.dns = dns; meta.dnsAutomatic = false; } if (a.resetserveraddresses) { meta.dnsAutomatic = true; meta.dns = meta.dhcp ? meta.leaseDns : ''; } } });
        if (!a.whatif && renewing.length) renewWindows(e, e.node(n.id), renewing.map(f => e.node(n.id).interfaces.find(p => p.name === f.name))); return result;
    }
    if (['enable-netadapter', 'disable-netadapter'].includes(command)) {
        a = psArguments(tokens, ['name'], ['whatif']); winNeed(a.name, 'Name으로 인터페이스를 지정하세요.'); const selected = selectWindowsAdapters(n, {interfacealias: a.name}); return change(e, n, a, tokens[0], x => { for (const f of x.interfaces) if (selected.some(p => p.name === f.name)) f.up = command === 'enable-netadapter'; });
    }
    if (['new-netroute', 'set-netroute', 'remove-netroute'].includes(command)) {
        a = psArguments(tokens, [...selectors, 'destinationprefix', 'nexthop', 'addressfamily', ...(command === 'remove-netroute' ? [] : ['routemetric'])], ['whatif']); family(a); const selected = selectWindowsAdapters(n, a, command === 'new-netroute'), names = selected.map(f => f.name); winNeed(a.destinationprefix, 'DestinationPrefix가 필요합니다.'); const net = cidr(a.destinationprefix); winNeed(net.ip === net.network, 'DestinationPrefix는 네트워크 주소여야 합니다.'); if (a.nexthop) ipv4(a.nexthop);
        const matches = r => names.includes(r.dev) && r.dest === a.destinationprefix && (!a.nexthop || (r.via || '0.0.0.0') === a.nexthop);
        const metric = command !== 'remove-netroute' ? integer(Number(a.routemetric ?? 0), 0, 65535) : 0;
        if (command === 'new-netroute') winNeed(selected.length === 1 && a.nexthop && !n.routes.some(matches), '새 경로에는 인터페이스 하나·NextHop이 필요하며 기존 경로와 중복할 수 없습니다.'); else winNeed(n.routes.some(matches), '변경할 정적 경로가 없습니다.');
        const result = change(e, n, a, tokens[0], x => { if (command === 'new-netroute') x.routes.push({dest: a.destinationprefix, via: a.nexthop === '0.0.0.0' ? '' : a.nexthop, dev: names[0], metric}); else if (command === 'remove-netroute') x.routes = x.routes.filter(r => !matches(r)); else { winNeed(a.routemetric !== undefined, 'RouteMetric을 지정하세요.'); x.routes.filter(matches).forEach(r => r.metric = metric); } });
        return a.whatif || command !== 'new-netroute' ? result : {rows: windowsRoutes(e, e.node(n.id)).filter(r => r.DestinationPrefix === a.destinationprefix)};
    }
    return null;
}
export function selectWindowsRules(n, a, required = false) {
    if (required) winNeed(a.name !== undefined || a.displayname !== undefined, 'Name 또는 DisplayName으로 대상 규칙을 지정하세요.');
    const meter = budget(), name = a.name !== undefined ? wildcard(a.name) : () => true, display = a.displayname !== undefined ? wildcard(a.displayname) : () => true;
    return n.windows.rules.filter(r => name(r.name, meter) && display(r.displayName, meter));
}
export function firewallFilterRows(n, rules, address = false) { return rules.map(r => address ? {InstanceID: r.name, LocalAddress: [...r.localAddress], RemoteAddress: [...r.remoteAddress]} : {InstanceID: r.name, Protocol: r.protocol, LocalPort: [...r.localPort], RemotePort: [...r.remotePort], IcmpType: [...r.icmpType]}); }
export function powershellFirewall(e, n, tokens) {
    const command = tokens[0].toLowerCase();
    if (['get-netfirewallprofile', 'set-netfirewallprofile'].includes(command)) {
        const a = psArguments(tokens, ['name', 'profile', ...(command === 'set-netfirewallprofile' ? ['enabled', 'defaultinboundaction', 'defaultoutboundaction'] : [])], command === 'set-netfirewallprofile' ? ['whatif'] : []);
        winNeed(!(a.name && a.profile), 'Name과 Profile 중 하나를 지정하세요.'); const names = a.name || a.profile ? winList(a.name || a.profile).map(s => winEnum(s, WIN_PROFILES)) : WIN_PROFILES;
        if (command === 'get-netfirewallprofile') return {rows: n.windows.profiles.filter(p => names.includes(p.name)).map(p => ({Name: p.name, Enabled: p.enabled, DefaultInboundAction: p.inbound, DefaultOutboundAction: p.outbound}))};
        winNeed(a.enabled !== undefined || a.defaultinboundaction !== undefined || a.defaultoutboundaction !== undefined, '변경할 프로필 설정이 필요합니다.'); const enabled = a.enabled !== undefined ? winBool(a.enabled) : undefined, inbound = a.defaultinboundaction ? winEnum(a.defaultinboundaction, ['Allow', 'Block']) : undefined, outbound = a.defaultoutboundaction ? winEnum(a.defaultoutboundaction, ['Allow', 'Block']) : undefined;
        return change(e, n, a, tokens[0], x => x.windows.profiles.filter(p => names.includes(p.name)).forEach(p => { if (enabled !== undefined) p.enabled = enabled; if (inbound) p.inbound = inbound; if (outbound) p.outbound = outbound; }));
    }
    if (['get-netfirewallportfilter', 'get-netfirewalladdressfilter'].includes(command)) { psArguments(tokens); return {rows: firewallFilterRows(n, n.windows.rules, command === 'get-netfirewalladdressfilter')}; }
    if (command === 'get-netfirewallrule') {
        const a = psArguments(tokens, ['name', 'displayname', 'enabled', 'direction', 'action', 'profile']); const selected = selectWindowsRules(n, a); const enabled = a.enabled === undefined ? undefined : winBool(a.enabled), direction = a.direction && winEnum(a.direction, ['Inbound', 'Outbound']), action = a.action && winEnum(a.action, ['Allow', 'Block']), profile = a.profile && winEnum(a.profile, ['Any', ...WIN_PROFILES]);
        const names = new Set(selected.filter(r => (enabled === undefined || r.enabled === enabled) && (!direction || r.direction === direction) && (!action || r.action === action) && (!profile || r.profiles.includes(profile) || r.profiles.includes('Any'))).map(r => r.name)); return {rows: windowsFirewallRows(n).filter(r => names.has(r.Name))};
    }
    const commands = ['new-netfirewallrule', 'set-netfirewallrule', 'remove-netfirewallrule', 'enable-netfirewallrule', 'disable-netfirewallrule']; if (!commands.includes(command)) return null;
    const edit = ['new-netfirewallrule', 'set-netfirewallrule'].includes(command), fields = ['enabled', 'direction', 'action', 'profile', 'protocol', 'localaddress', 'remoteaddress', 'localport', 'remoteport', 'icmptype', 'interfacealias'];
    const a = psArguments(tokens, ['name', 'displayname', ...(edit ? fields : []), ...(command === 'set-netfirewallrule' ? ['newdisplayname'] : [])], ['whatif']);
    const creating = command === 'new-netfirewallrule', selected = creating ? [] : selectWindowsRules(n, a, true); winNeed(creating || selected.length, '대상 방화벽 규칙이 없습니다.'); if (creating) winNeed(a.displayname, 'DisplayName이 필요합니다.');
    const patch = {};
    if (a.enabled !== undefined) patch.enabled = winBool(a.enabled);
    for (const [key, choices] of [['direction', ['Inbound', 'Outbound']], ['action', ['Allow', 'Block']], ['protocol', ['Any', 'TCP', 'UDP', 'ICMPv4']]]) if (a[key] !== undefined) patch[key] = winEnum(a[key], choices);
    if (a.profile !== undefined) patch.profiles = winList(a.profile).map(s => winEnum(s, ['Any', ...WIN_PROFILES]));
    for (const [param, prop] of [['localaddress', 'localAddress'], ['remoteaddress', 'remoteAddress'], ['localport', 'localPort'], ['remoteport', 'remotePort'], ['icmptype', 'icmpType']]) if (a[param] !== undefined) patch[prop] = winList(a[param]).map(s => /^any$/i.test(s) ? 'Any' : /^localsubnet$/i.test(s) && param.endsWith('address') ? 'LocalSubnet' : s);
    if (a.interfacealias !== undefined) patch.interfaces = /^any$/i.test(a.interfacealias) ? ['Any'] : [...new Set(winList(a.interfacealias).flatMap(alias => selectWindowsAdapters(n, {interfacealias: alias}).map(f => f.name)))];
    if (a.newdisplayname !== undefined) patch.displayName = a.newdisplayname;
    let createdName;
    const result = change(e, n, a, tokens[0], x => {
        if (creating) { let i = 1; while (x.windows.rules.some(r => r.name.toLowerCase() === `simrule-${i}`)) i++; createdName = a.name || `SimRule-${i}`; x.windows.rules.push({name: createdName, displayName: a.displayname, enabled: true, direction: 'Inbound', action: 'Allow', profiles: ['Any'], protocol: 'Any', localAddress: ['Any'], remoteAddress: ['Any'], localPort: ['Any'], remotePort: ['Any'], icmpType: ['Any'], interfaces: ['Any'], ...patch}); }
        else { const names = new Set(selected.map(r => r.name)); if (command === 'remove-netfirewallrule') x.windows.rules = x.windows.rules.filter(r => !names.has(r.name)); else for (const rule of x.windows.rules.filter(r => names.has(r.name))) Object.assign(rule, patch, command === 'enable-netfirewallrule' ? {enabled: true} : command === 'disable-netfirewallrule' ? {enabled: false} : {}); }
    });
    return !creating || a.whatif ? result : {rows: windowsFirewallRows(e.node(n.id)).filter(r => r.Name === createdName), list: true};
}
