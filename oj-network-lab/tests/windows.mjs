import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {existsSync} from 'node:fs';
import {Engine} from '../resources/js/engine.js';
import {scenario} from '../resources/js/scenarios.js';
import {assignProfile} from '../resources/js/device-config.js';
import {validateProject, clone, newNode, VERSION} from '../resources/js/model.js';
import {powershellFilter, findstrFilter} from '../resources/js/windows-pipeline.js';
import {windowsWords} from '../resources/js/windows-parser.js';
import {budget} from '../resources/js/text-pattern.js';
let passed = 0, references = 0;
function test(name, fn) { fn(); passed++; console.log('OK ' + name); }
function command(e, id, line) { const output = e.execute(id, line); e.run(); return [output, ...e.drain().filter(m => m.node === id).map(m => m.text)].join('\n'); }
function host(scene = 'lan', id = 'n1') { const p = scenario(scene); assignProfile(p.nodes.find(n => n.id === id), 'windows'); return new Engine(p); }
function ps(e, id = 'n1') { e.execute(id, 'powershell'); e.drain(); }
function ready() { const e = new Engine(scenario('windows')); command(e, 'n1', 'ipconfig /renew'); ps(e); return e; }
function rejectsAtomic(e, id, line) { const before = e.export(), revision = e.revision, queued = e.queue.length; assert.throws(() => e.execute(id, line), undefined, line); assert.deepEqual(e.export(), before, line); assert.equal(e.revision, revision, line); assert.equal(e.queue.length, queued, line); }

test('Windows is a host profile with independent bounded configuration', () => {
    const e = host(); assert.equal(e.node('n1').windows.profiles.length, 3); assert.equal(e.node('n1').windows.rules.length, 1); assert(!e.node('n1').device && !e.node('n1').startup);
    for (const type of ['hub', 'switch', 'router', 'firewall']) assert.throws(() => assignProfile(newNode(type), 'windows'));
    const named = scenario('lan'); named.nodes[0].name = '학생 PC'; assignProfile(named.nodes[0], 'windows'); assert.doesNotThrow(() => validateProject(named));
    const p = e.export(); p.nodes[0].forwarding = true; assert.throws(() => new Engine(p));
});
test('CMD and PowerShell switch without altering device or another console', () => {
    const e = ready(), before = e.export(), revision = e.revision; assert.equal(e.consolePrompt('n1'), 'PS C:\\Lab>'); e.execute('n1', 'cmd'); assert.equal(e.consolePrompt('n1'), 'C:\\Lab>'); assert.equal(e.consolePrompt('n4'), 'C:\\Lab>'); e.execute('n1', 'powershell.exe'); assert.equal(e.consolePrompt('n1'), 'PS C:\\Lab>'); assert.deepEqual(e.export(), before); assert.equal(e.revision, revision); e.execute('n1', 'exit'); assert.equal(e.consolePrompt('n1'), 'C:\\Lab>');
});
test('case-insensitive CMD syntax and Windows ping options use real packets', () => {
    const e = host(); assert(e.execute('n1', 'IPCONFIG /ALL').includes('192.168.10.10')); const text = command(e, 'n1', 'PiNg -n 2 192.168.10.20'); assert(text.includes('Sent = 2, Received = 2')); assert(e.trace.some(t => t.protocol === 'ARP')); rejectsAtomic(e, 'n1', 'ping -c 2 192.168.10.20'); rejectsAtomic(e, 'n1', 'ping -n 5 192.168.10.20');
});
test('Windows DHCP DORA, DNS and release update the selected host', () => {
    const e = new Engine(scenario('windows')); assert(command(e, 'n1', 'ipconfig /renew').includes('192.168.10.100/24')); assert(e.node('n1').windows.adapters[0].dhcp); assert.equal(e.node('n1').windows.adapters[0].dns, '192.168.10.1'); assert(command(e, 'n1', 'nslookup winweb.lab').includes('192.168.10.20')); e.execute('n1', 'ipconfig /release'); assert.equal(e.node('n1').interfaces[0].address, ''); assert.equal(e.node('n1').resolver, ''); assert(command(e, 'n1', 'ipconfig /renew Ethernet').includes('DHCP lease acquired'));
});
test('static addressing disables DHCP and rejects stale DHCP replies', () => {
    const e = new Engine(scenario('windows')); e.execute('n1', 'ipconfig /renew'); ps(e); e.execute('n1', 'New-NetIPAddress -InterfaceAlias Ethernet -IPAddress 192.168.10.77 -PrefixLength 24 -DefaultGateway 192.168.10.1'); e.run(); assert.equal(e.node('n1').interfaces[0].address, '192.168.10.77/24'); assert.equal(e.node('n1').windows.adapters[0].dhcp, false); assert([...e.jobs.values()].some(j => j.type === 'dhcp' && j.status === 'cancelled'));
    rejectsAtomic(e, 'n1', 'New-NetIPAddress -InterfaceAlias Ethernet -IPAddress 192.168.10.78 -PrefixLength 24'); rejectsAtomic(e, 'n1', 'Set-NetIPAddress -InterfaceAlias Ethernet -IPAddress 192.168.10.78 -PrefixLength 25'); e.execute('n1', 'Set-NetIPAddress -InterfaceAlias Ethernet -IPAddress 192.168.10.77 -PrefixLength 25'); assert.equal(e.node('n1').interfaces[0].address, '192.168.10.77/25');
});
test('PowerShell can acquire DHCP and configure per-adapter DNS independently', () => {
    const e = host('dhcp'); ps(e); e.execute('n1', 'Set-DnsClientServerAddress -InterfaceAlias Ethernet -ServerAddresses 192.168.10.9'); command(e, 'n1', 'Set-NetIPInterface -InterfaceIndex 1 -Dhcp Enabled'); assert.equal(e.node('n1').interfaces[0].address, '192.168.10.100/24'); assert.equal(e.node('n1').windows.adapters[0].dns, '192.168.10.9'); e.execute('n1', 'Set-DnsClientServerAddress -InterfaceAlias Ethernet -ResetServerAddresses'); assert.equal(e.node('n1').windows.adapters[0].dns, '192.168.10.1'); rejectsAtomic(e, 'n1', 'Set-DnsClientServerAddress -InterfaceAlias Ethernet -ServerAddresses 192.168.10.1,192.168.10.2');
});
test('CMD DNS cache inspection and clearing do not change project revision', () => {
    const e = ready(); e.execute('n1', 'cmd'); command(e, 'n1', 'nslookup winweb.lab'); const revision = e.revision; assert(e.execute('n1', 'ipconfig /displaydns').includes('winweb.lab')); e.execute('n1', 'ipconfig /flushdns'); assert.equal(e.rt('n1').dns.size, 0); assert.equal(e.revision, revision);
});
test('Windows address, route, adapter and DNS queries expose typed properties', () => {
    const e = ready(); for (const line of ['Get-NetAdapter', 'Get-NetIPConfiguration', 'Get-NetIPAddress', 'Get-NetIPInterface', 'Get-NetRoute', 'Get-NetNeighbor', 'Get-NetTCPConnection', 'Get-DnsClientServerAddress', 'Get-NetConnectionProfile']) { const revision = e.revision; assert.doesNotThrow(() => e.execute('n1', line)); assert.equal(e.revision, revision); }
    rejectsAtomic(e, 'n1', 'Get-NetIPAddress -AddressFamily IPv6');
});
test('CMD static routes are shared with PowerShell and affect forwarding', () => {
    const e = host('routing'); e.execute('n1', 'route delete 0.0.0.0'); assert(command(e, 'n1', 'ping -n 1 192.168.20.10').includes('Received = 0')); e.execute('n1', 'route add 192.168.20.0 mask 255.255.255.0 192.168.10.1 metric 5 if 1'); assert(command(e, 'n1', 'ping -n 1 192.168.20.10').includes('Received = 1')); ps(e); assert(e.execute('n1', 'Get-NetRoute -DestinationPrefix 192.168.20.0/24').includes('192.168.10.1')); rejectsAtomic(e, 'n1', 'New-NetRoute -DestinationPrefix 192.168.20.1/24 -InterfaceIndex 1 -NextHop 192.168.10.1'); e.execute('n1', 'Set-NetRoute -DestinationPrefix 192.168.20.0/24 -RouteMetric 11'); assert.equal(e.node('n1').routes[0].metric, 11); e.execute('n1', 'Remove-NetRoute -DestinationPrefix 192.168.20.0/24 -Confirm:$false'); assert.equal(e.node('n1').routes.length, 0);
});
test('adapter disable and enable alter operational routing', () => {
    const e = host(); ps(e); e.execute('n1', 'Disable-NetAdapter -Name Ethernet -Confirm:$false'); assert.equal(e.node('n1').interfaces[0].up, false); assert(command(e, 'n1', 'Test-NetConnection 192.168.10.20 -InformationLevel Quiet').includes('False')); e.execute('n1', 'Enable-NetAdapter -Name Ethernet -Confirm:$false'); assert(command(e, 'n1', 'Test-NetConnection 192.168.10.20 -InformationLevel Quiet').includes('True'));
});
test('TCP tests perform SYN ACK FIN without creating an HTTP request', () => {
    const e = ready(); const open = command(e, 'n1', 'Test-NetConnection web.lab -Port 80'); assert(/TcpTestSucceeded\s+: True/.test(open)); assert(e.trace.some(t => t.protocol === 'TCP' && t.flags === 'SA')); assert(!e.trace.some(t => t.flags === 'PA')); assert(/TcpTestSucceeded\s+: False/.test(command(e, 'n1', 'Test-NetConnection web.lab -Port 81'))); assert(e.trace.some(t => t.flags.includes('R')));
});
test('default Windows inbound blocking and allow rule affect actual TCP tests', () => {
    const e = ready(); assert(/TcpTestSucceeded\s+: False/.test(command(e, 'n1', 'Test-NetConnection winweb.lab -Port 80'))); ps(e, 'n4'); e.execute('n4', 'New-NetFirewallRule -Name Web -DisplayName "Allow Web" -Protocol TCP -LocalPort 80 -Direction Inbound -Action Allow'); assert(/TcpTestSucceeded\s+: True/.test(command(e, 'n1', 'Test-NetConnection winweb.lab -Port 80'))); assert(e.trace.some(t => t.node === 'n4' && t.detail.includes('Allow Web')));
});
test('explicit block wins over allow regardless of rule insertion order', () => {
    const e = ready(); ps(e, 'n4'); e.execute('n4', 'New-NetFirewallRule -Name Deny -DisplayName Deny -Protocol TCP -LocalPort 80 -Action Block'); e.execute('n4', 'New-NetFirewallRule -Name Allow -DisplayName Allow -Protocol TCP -LocalPort 80 -Action Allow'); assert(/TcpTestSucceeded\s+: False/.test(command(e, 'n1', 'Test-NetConnection winweb.lab -Port 80'))); e.execute('n4', 'Disable-NetFirewallRule -Name Deny'); assert(/TcpTestSucceeded\s+: True/.test(command(e, 'n1', 'Test-NetConnection winweb.lab -Port 80'))); e.execute('n4', 'Enable-NetFirewallRule -Name Deny'); assert(/TcpTestSucceeded\s+: False/.test(command(e, 'n1', 'Test-NetConnection winweb.lab -Port 80')));
});
test('active network category controls profile-scoped rules', () => {
    const e = ready(); ps(e, 'n4'); e.execute('n4', 'New-NetFirewallRule -Name PrivateWeb -DisplayName Web -Protocol TCP -LocalPort 80 -Profile Private -Action Allow'); assert(/TcpTestSucceeded\s+: False/.test(command(e, 'n1', 'Test-NetConnection winweb.lab -Port 80'))); e.execute('n4', 'Set-NetConnectionProfile -InterfaceAlias Ethernet -NetworkCategory Private'); assert(/TcpTestSucceeded\s+: True/.test(command(e, 'n1', 'Test-NetConnection winweb.lab -Port 80'))); rejectsAtomic(e, 'n4', 'Set-NetConnectionProfile -InterfaceAlias Ethernet -NetworkCategory DomainAuthenticated');
});
test('firewall address, interface, ICMP type and port-range conditions are enforced', () => {
    const e = host(); ps(e); e.execute('n1', 'New-NetFirewallRule -Name Ping -DisplayName Ping -Protocol ICMPv4 -IcmpType 8 -RemoteAddress 192.168.10.20/32 -InterfaceAlias Ethernet -Action Allow'); assert(command(e, 'n3', 'ping 192.168.10.10').includes('1 transmitted, 1 received')); assert(command(e, 'n4', 'ping 192.168.10.10').includes('100% packet loss')); e.execute('n1', 'Set-NetFirewallRule -Name Ping -RemoteAddress LocalSubnet'); assert(command(e, 'n4', 'ping 192.168.10.10').includes('1 transmitted, 1 received'));
    rejectsAtomic(e, 'n1', 'New-NetFirewallRule -DisplayName Bad -Protocol ICMPv4 -LocalPort 80'); rejectsAtomic(e, 'n1', 'New-NetFirewallRule -DisplayName Bad -Protocol TCP -LocalPort 100-80');
});
test('blocked traffic cannot prime stateful authorization for reverse packets', () => {
    const e = host(); ps(e); e.execute('n1', 'Set-NetFirewallProfile -DefaultOutboundAction Block'); const packet = e.packet('TCP', '192.168.10.10', '192.168.10.20', {sport: 40001, dport: 80, flags: 'S'}); assert.equal(e.filter(e.node('n1'), 'output', packet, '', 'eth0'), false); const reply = {...packet, src: packet.dst, dst: packet.src, sport: packet.dport, dport: packet.sport, flags: 'SA'}; assert.equal(e.filter(e.node('n1'), 'input', reply, 'eth0', ''), false);
});
test('stateful replies are allowed while unrelated echo is blocked', () => {
    const e = host(); assert(command(e, 'n1', 'ping -n 1 192.168.10.20').includes('Received = 1')); assert(command(e, 'n3', 'ping 192.168.10.10').includes('100% packet loss'));
});
test('Windows traceroute accepts related ICMP errors without opening echo inbound', () => {
    const e = host('routing'); const text = command(e, 'n1', 'tracert -d -h 4 192.168.20.10'); assert(text.includes('192.168.10.1') && text.includes('192.168.20.10')); assert(e.trace.some(t => t.node === 'n1' && t.detail.includes('stateful')));
});
test('firewall profiles and rules can be set, disabled and removed independently', () => {
    const e = host(); ps(e); e.execute('n1', 'Set-NetFirewallProfile -Profile Public -Enabled False'); assert(command(e, 'n3', 'ping 192.168.10.10').includes('1 transmitted, 1 received')); e.execute('n1', 'Set-NetFirewallProfile -Profile Public -Enabled True'); assert(command(e, 'n3', 'ping 192.168.10.10').includes('100% packet loss')); e.execute('n1', 'New-NetFirewallRule -Name Ping -DisplayName Ping -Protocol ICMPv4'); e.execute('n1', 'Set-NetFirewallRule -Name Ping -NewDisplayName "Echo Allowed" -Enabled False'); assert(e.execute('n1', 'Get-NetFirewallRule -DisplayName "Echo*"').includes('False')); e.execute('n1', 'Remove-NetFirewallRule -Name Ping -Confirm:$false'); assert(!e.node('n1').windows.rules.some(r => r.name === 'Ping'));
});
test('WhatIf validates changes and leaves configuration, queues and revisions intact', () => {
    const e = ready(), before = e.export(), rev = e.revision;
    for (const line of ['New-NetFirewallRule -DisplayName Test -Protocol TCP -LocalPort 80 -WhatIf', 'Set-NetFirewallProfile -Enabled False -WhatIf', 'Set-NetIPInterface -InterfaceAlias Ethernet -Dhcp Enabled -WhatIf']) assert(e.execute('n1', line).startsWith('What if:'));
    assert.deepEqual(e.export(), before); assert.equal(e.revision, rev); assert.equal(e.queue.length, 0); rejectsAtomic(e, 'n1', 'New-NetFirewallRule -DisplayName Bad -Protocol TCP -LocalPort 0 -WhatIf');
});
test('PowerShell pipelines preserve numeric and Boolean properties', () => {
    const e = ready(); assert(e.execute('n1', 'Get-NetIPAddress | Where-Object { $_.PrefixLength -eq 24 } | Select-Object InterfaceAlias,IPAddress').includes('192.168.10.100')); assert(e.execute('n1', 'Get-NetFirewallProfile | Where-Object Enabled -eq $true | Measure-Object').includes('Count : 3')); assert(e.execute('n1', 'Get-NetFirewallProfile | Sort-Object -Property Name -Descending | Select-Object -First 1 -ExpandProperty Name').includes('Public')); assert(e.execute('n1', 'Get-NetIPInterface | Measure-Object -Property InterfaceMetric -Sum').includes('25'));
});
test('firewall filter objects remain separate from firewall rule objects', () => {
    const e = ready(); e.execute('n1', 'New-NetFirewallRule -Name Range -DisplayName Range -Protocol TCP -LocalPort 80-90 -RemoteAddress 192.168.10.0/24'); assert(!e.execute('n1', 'Get-NetFirewallRule -Name Range').includes('80-90')); assert(e.execute('n1', 'Get-NetFirewallRule -Name Range | Get-NetFirewallPortFilter | Select-Object Protocol,LocalPort').includes('80-90')); assert(e.execute('n1', 'Get-NetFirewallRule -Name Range | Get-NetFirewallAddressFilter').includes('192.168.10.0/24'));
});
test('Windows findstr and Select-String filter text with distinct exit status', () => {
    const e = ready(); e.execute('n1', 'cmd'); assert(e.execute('n1', 'ipconfig /all | findstr /I /C:"IPv4 Address"').includes('192.168.10.100')); assert.equal(e.execute('n1', 'ipconfig | findstr notfound'), ''); assert.equal(e.execute('n1', 'echo %errorlevel%'), '1'); ps(e); assert(e.execute('n1', 'ipconfig /all | Select-String -Pattern "IPv4"').includes('192.168.10.100')); assert.equal(e.execute('n1', 'ipconfig | Select-String -Pattern missing -Quiet'), 'False'); assert.equal(e.execute('n1', 'Write-Output $?'), 'True'); assert.equal(e.execute('n1', "Write-Output '$?'"), '$?');
});
test('virtual Windows files, directories and history never reach the host filesystem', () => {
    const e = ready(), before = e.export(); assert(e.execute('n1', 'Get-Content C:\\Lab\\etc\\hostname').includes('WIN-PC')); e.execute('n1', 'Set-Location C:\\Lab\\state'); assert.equal(e.consolePrompt('n1'), 'PS C:\\Lab\\state>'); assert(e.execute('n1', 'Get-Content interfaces.txt | Select-String Ethernet').includes('Ethernet')); assert(e.execute('n1', 'Get-ChildItem').includes('interfaces.txt')); assert(e.execute('n1', 'Get-History -Count 2').includes('Get-History'));
    for (const line of ['Get-Content C:\\Windows\\win.ini', 'Get-Content \\\\server\\share', 'Set-Location ..\\..', 'Set-Content C:\\Lab\\etc\\hostname BAD']) rejectsAtomic(e, 'n1', line); assert.deepEqual(e.export(), before);
});
test('invalid pipelines and script syntax reject before source actions', () => {
    const e = ready();
    for (const line of ['New-NetFirewallRule -DisplayName Bad | Select-Object Name', 'Test-NetConnection web.lab -Port 80 | Select-Object TcpTestSucceeded', 'cmd | findstr x', 'Get-NetIPAddress | Select-String -Pattern "["', 'Get-NetIPAddress | Where-Object { $_.IPAddress -eq $(New-NetIPAddress) }', 'Get-NetIPAddress; Set-NetFirewallProfile -Enabled False', 'powershell -Command "Get-NetIPAddress"', 'Write-Output $(hostname)', 'Get-NetIPAddress > C:\\Lab\\out.txt', 'Get-NetIPAddress | Invoke-Expression']) rejectsAtomic(e, 'n1', line);
});
test('Windows pattern, object and configuration bounds reject excessive inputs', () => {
    const e = ready(); rejectsAtomic(e, 'n1', 'Get-NetIPAddress' + ' | Select-Object IPAddress'.repeat(8)); rejectsAtomic(e, 'n1', `ipconfig | Select-String '${'a'.repeat(257)}'`); const p = e.export(); p.nodes[0].windows.rules = Array.from({length: 65}, (_, i) => ({...p.nodes[0].windows.rules[0], name: 'R' + i})); assert.throws(() => validateProject(p)); const bad = e.export(); bad.nodes[0].windows.rules[0].interfaces = ['eth9']; assert.throws(() => validateProject(bad));
});
test('PowerShell predicates preserve literal dollar names and reject variable evaluation', () => {
    const e = ready(); e.execute('n1', "New-NetFirewallRule -Name '$true' -DisplayName Literal"); assert(e.execute('n1', "Get-NetFirewallRule | Where-Object { $_.Name -eq '$true' }").includes('Literal')); rejectsAtomic(e, 'n1', 'Get-NetFirewallRule | Where-Object { $_.Name -eq $env:USER }');
});
test('Windows help completion clear messages and runtime shell state survive normal use', () => {
    const e = ready(), before = e.export(); assert(e.execute('n1', 'Get-Help').includes('Test-NetConnection')); assert.equal(e.complete('n1', 'Get-NetIPAdd').line, 'Get-NetIPAddress '); assert.equal(e.complete('n1', 'Get-NetIPAddress | Select-Obj').line, 'Get-NetIPAddress | Select-Object '); e.execute('n1', 'Clear-Host'); assert(e.drain().some(m => m.clear)); assert.deepEqual(e.export(), before); assert.equal(new Engine(e.export()).consolePrompt('n1'), 'C:\\Lab>');
});
test('DNS missing names and TCP test failures never report success', () => {
    const e = ready(); assert(command(e, 'n1', 'Resolve-DnsName missing.lab').includes('DNS name does not exist')); assert(command(e, 'n1', 'Test-NetConnection missing.lab -Port 80 -InformationLevel Quiet').includes('False')); assert(!e.trace.some(t => t.protocol === 'TCP' && t.dst === 'missing.lab'));
});
test('previous Linux and device projects retain their CLI profiles', () => {
    for (const scene of ['lan', 'ios-switch', 'fortios', 'ipsec']) { const p = scenario(scene), before = new Engine(p).export(); p.engine = '1.2.0-rc.3'; assert.deepEqual(new Engine(p).export(), before); }
    const windows = scenario('windows'), before = new Engine(windows).export(); windows.engine = '1.3.0-rc.1'; assert.deepEqual(new Engine(windows).export(), before);
});
test('Windows localhost and own-address traffic stay on the host', () => {
    const e = host(); e.execute('n1', 'ping -n 1 localhost'); e.run(); assert(e.drain().some(m => m.text.includes('Received = 1'))); assert(!e.trace.some(t => t.link)); ps(e); e.execute('n1', 'Disable-NetAdapter -Name Ethernet -Confirm:$false'); assert(command(e, 'n1', 'Test-NetConnection localhost -InformationLevel Quiet').includes('True'));
});
test('Windows clients interoperate with existing WireGuard and IPsec gateways', () => {
    for (const scene of ['vpn', 'ipsec']) { const e = host(scene); assert(command(e, 'n1', 'ping -n 1 192.168.20.10').includes('Received = 1')); assert(e.trace.some(t => scene === 'vpn' ? t.action === 'vpn' : t.protocol === 'ESP')); }
});

const findstrExe = process.env.SystemRoot ? process.env.SystemRoot + '/System32/findstr.exe' : 'C:/Windows/System32/findstr.exe';
if (existsSync(findstrExe)) test('supported FINDSTR cases match the native Windows executable', () => {
    const fixtures = [['findstr /I up', 'UP\ndown\nsetup\n'], ['findstr /N /V down', 'up\ndown\n'], ['findstr /L "a.b"', 'a.b\naxb\n'], ['findstr /R "^up$"', 'up\nsetup\n'], ['findstr /C:"hello world"', 'hello world\nhello\nworld\n'], ['findstr /C:"a.b"', 'a.b\naxb\n'], ['findstr /R /C:"a.b"', 'a.b\naxb\n'], ['findstr /C:"a.b" /R', 'a.b\naxb\n'], ['findstr /X up', 'up\nsetup\n'], ['findstr missing', 'up\ndown\n']];
    for (const [line, input] of fixtures) { const t = windowsWords(line, 'cmd'), result = spawnSync(findstrExe, t.slice(1), {input: input.replace(/\n/g, '\r\n'), encoding: 'utf8', windowsHide: true, timeout: 5000}); assert(!result.error, result.error?.message); const actual = findstrFilter(t, budget(), () => { throw new Error('Unexpected file'); })({text: input}); assert.equal(actual.text, result.stdout.replace(/\r\n/g, '\n').replace(/\n$/, ''), line); assert.equal(actual.status, result.status, line); references++; }
});
const powershellExe = process.env.SystemRoot ? process.env.SystemRoot + '/System32/WindowsPowerShell/v1.0/powershell.exe' : 'C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe';
if (existsSync(powershellExe)) test('bounded object filters match native PowerShell on static fixture objects', () => {
    const rows = [{Name: 'beta', Count: 2, Enabled: true}, {Name: 'alpha', Count: 10, Enabled: false}, {Name: 'gamma', Count: 5, Enabled: true}];
    const fixtures = [['Where-Object { $_.Enabled -eq $true }', 'Select-Object Name,Count'], ['Where-Object Count -gt 2', 'Sort-Object -Property Count -Descending'], ['Sort-Object -Property Name', 'Select-Object -First 1 -ExpandProperty Name'], ['Measure-Object -Property Count -Sum -Minimum -Maximum -Average']];
    for (const stages of fixtures) { let value = {rows}; for (const stage of stages) value = powershellFilter(stage, budget())(value); const fixture = "@([pscustomobject]@{Name='beta';Count=2;Enabled=$true},[pscustomobject]@{Name='alpha';Count=10;Enabled=$false},[pscustomobject]@{Name='gamma';Count=5;Enabled=$true})"; const script = '[Console]::OutputEncoding=[Text.UTF8Encoding]::new($false); ConvertTo-Json -Compress -InputObject @(' + fixture + ' | ' + stages.join(' | ') + ')'; const result = spawnSync(powershellExe, ['-NoProfile', '-NonInteractive', '-Command', script], {encoding: 'utf8', windowsHide: true, timeout: 10000}); assert(!result.error && result.status === 0, result.stderr || result.error?.message); const expected = JSON.parse(result.stdout.trim()); if (stages[0].startsWith('Measure')) for (const key of Object.keys(value.rows[0])) assert.equal(value.rows[0][key], expected[0][key]); else assert.deepEqual(value.rows, expected); references++; }
});
console.log(`${passed} Windows checks passed; ${references} native reference comparisons (${VERSION}).`);
