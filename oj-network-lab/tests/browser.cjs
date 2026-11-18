const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const http = require('node:http'), fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict'), {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..'), output = process.env.OJNL_TEST_OUTPUT || path.resolve(root, '../_notes/verification/oj-network-lab-windows-2026-11-06');
fs.mkdirSync(output, {recursive: true});
fs.writeFileSync(path.join(output, 'browser-result.json'), JSON.stringify({passed: false, status: 'running'}));
let html = '', nextId = 1, rows = new Map(), nonceChecks = 0, conflictOnce = false, apiRequests = 0;
function phpJson(value) { if (Array.isArray(value)) return value.map(phpJson); if (value && typeof value === 'object') return Object.keys(value).length ? Object.fromEntries(Object.entries(value).map(([k, v]) => [k, phpJson(v)])) : []; return value; }
const server = http.createServer((req, res) => {
    if (req.url === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>네트워크 실습 테스트</title><link rel="stylesheet" href="/resources/css/network-lab.css"><style>body{margin:24px;background:#f7f9fc;font-family:Arial,sans-serif}main{max-width:1550px;margin:auto}</style><main>${html}</main><script src="/resources/js/app.js"></script></html>`); return; }
    if (req.url.startsWith('/api/')) {
        apiRequests++; if (req.headers['x-wp-nonce'] !== 'test-nonce') { res.writeHead(403); res.end('{}'); return; } nonceChecks++;
        let body = ''; req.on('data', c => body += c); req.on('end', () => {
            res.setHeader('Content-Type', 'application/json'); const id = Number(req.url.split('/')[3]), data = body ? JSON.parse(body) : {};
            if (req.method === 'GET' && !id) res.end(JSON.stringify({projects: [...rows.values()].map(r => ({id: r.id, client_id: r.clientId, title: r.project.title, revision: r.revision, updated_at: '2026-11-05 00:00:00'}))}));
            else if (req.method === 'GET') setTimeout(() => res.end(JSON.stringify(rows.get(id))), 250);
            else if (req.method === 'DELETE') { assert.equal(data.revision, rows.get(id).revision); rows.delete(id); res.end('{"deleted":true}'); }
            else { if (conflictOnce) { conflictOnce = false; res.writeHead(409); res.end('{"message":"저장 충돌 테스트"}'); return; } const row = {id: id || nextId++, clientId: data.clientId || rows.get(id).clientId, revision: id ? rows.get(id).revision + 1 : 1, project: phpJson(data.project)}; rows.set(row.id, row); res.end(JSON.stringify(row)); }
        }); return;
    }
    const file = path.resolve(root, '.' + decodeURIComponent(req.url.split('?')[0]));
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) { res.writeHead(404); res.end('missing'); return; }
    res.setHeader('Content-Type', file.endsWith('.js') ? 'application/javascript; charset=utf-8' : file.endsWith('.css') ? 'text/css; charset=utf-8' : 'application/json'); res.end(fs.readFileSync(file));
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); const origin = `http://127.0.0.1:${server.address().port}`;
    html = execFileSync(process.env.PHP_BINARY || 'D:/php/php.exe', [path.join(__dirname, 'render.php')], {encoding: 'utf8', env: {...process.env, OJNL_TEST_ORIGIN: origin}});
    const browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe'}); const page = await browser.newPage({viewport: {width: 1510, height: 1060}, deviceScaleFactor: 1});
    await page.addInitScript(() => {
        const descriptor = Object.getOwnPropertyDescriptor(IDBTransaction.prototype, 'oncomplete');
        Object.defineProperty(IDBTransaction.prototype, 'oncomplete', {...descriptor, set(callback) { descriptor.set.call(this, callback ? function (event) { setTimeout(() => callback.call(this, event), 250); } : callback); }});
    });
    const errors = [], external = []; page.on('pageerror', e => errors.push(e.message)); page.on('request', req => { if (!req.url().startsWith(origin) && !req.url().startsWith('blob:')) external.push(req.url()); });
    try {
        await page.goto(origin); await page.locator('[data-command]:not(:disabled)').waitFor();
        async function cmd(text, expected) { const before = await page.locator('[data-terminal]').textContent(); await page.locator('[data-command]').fill(text); await page.locator('[data-command]').press('Enter'); await page.waitForFunction(value => document.querySelector('[data-terminal]').textContent !== value, before); if (expected) await page.waitForFunction(value => document.querySelector('[data-terminal]').textContent.includes(value), expected); }
        async function lesson(id) { await page.selectOption('[data-scenario]', id); await page.click('[data-action="lesson"]'); await page.locator('[data-action="lesson"]:not(:disabled)').waitFor(); await page.waitForFunction(value => document.querySelector('[data-title]').value.includes(value), id === 'windows' ? 'Windows' : id === 'vpn' ? 'VPN' : id === 'firewall' ? '방화벽' : id === 'dhcp' ? 'DHCP' : id === 'empty' ? '자유' : id === 'ios-switch' ? 'IOS 스위치' : id === 'ios-router' ? 'IOS 라우터' : id === 'fortios' ? 'FortiOS 정책' : id === 'ipsec' ? 'IPsec' : '라우터'); }
        await cmd('ping -c 2 192.168.10.20', '2 transmitted, 2 received'); assert.equal(apiRequests, 0, 'packet processing makes no REST calls');
        const inlineLayout = await page.evaluate(() => { const screen = document.querySelector('[data-terminal-screen]'), output = document.querySelector('[data-terminal]'), input = document.querySelector('[data-command]'), form = input.closest('form'), style = getComputedStyle(input); return {inside: screen.contains(input), adjacent: output.nextElementSibling === form, gap: form.getBoundingClientRect().top - output.getBoundingClientRect().bottom, border: style.borderTopWidth, background: style.backgroundColor}; });
        assert(inlineLayout.inside && inlineLayout.adjacent && Math.abs(inlineLayout.gap) < 2 && inlineLayout.border === '0px' && inlineLayout.background === 'rgba(0, 0, 0, 0)', 'prompt and borderless input follow output in the same terminal screen');
        await page.locator('[data-command]').blur(); const screenSize = await page.locator('[data-terminal-screen]').boundingBox(); await page.locator('[data-terminal-screen]').click({position: {x: screenSize.width - 30, y: screenSize.height - 20}});
        assert(await page.locator('[data-command]').evaluate(el => el === document.activeElement), 'clicking terminal whitespace focuses command entry');
        await page.screenshot({path: path.join(output, 'desktop.png'), fullPage: true});
        await page.click('[data-action="save"]'); await page.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1')); assert.equal(rows.size, 1);
        await page.reload(); await page.locator('[data-command]:not(:disabled)').waitFor(); assert((await page.locator('[data-status]').innerText()).includes('복원'));
        await lesson('firewall'); await cmd('curl http://203.0.113.10', 'HTTP/1.1 200 OK'); await cmd('ping 203.0.113.10', '100% packet loss');
        await page.selectOption('[data-terminal-node]', 'n3'); await cmd('nft add rule inet filter forward ip protocol icmp accept'); await page.selectOption('[data-terminal-node]', 'n1'); await page.click('[data-action="clear-terminal"]'); await cmd('ping 203.0.113.10', '1 transmitted, 1 received');
        await page.selectOption('[data-terminal-node]', 'n3'); await cmd('nft add rule inet filter forward accept'); await page.click('[data-action="save"]'); await page.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1'));
        await lesson('vpn'); await cmd('ping 192.168.20.10', '1 transmitted, 1 received'); await page.selectOption('[data-terminal-node]', 'n2'); await cmd('wg show', 'ms 전');
        await page.screenshot({path: path.join(output, 'vpn.png'), fullPage: true});
        await page.click('[data-action="save"]'); await page.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1'));
        await page.locator('[data-title]').fill('VPN 변경'); await page.locator('[data-title]').press('Tab'); conflictOnce = true; await page.click('[data-action="save"]'); await page.locator('[data-conflict]:not([hidden])').waitFor();
        await page.click('[data-action="copy"]'); await page.waitForFunction(() => document.querySelector('[data-conflict]').hidden && document.querySelector('[data-remote]').textContent.includes('버전 1'));
        assert.equal(rows.size, 4, 'conflict copy preserves existing project');
        await page.locator('[data-title]').fill('전환 직전 변경'); await page.locator('[data-title]').press('Tab');
        await page.selectOption('[data-scenario]', 'dhcp'); await page.click('[data-action="lesson"]'); assert(await page.locator('[data-command]').isDisabled(), 'scenario switch locks commands before delayed local backup'); await cmd('dhclient eth0', 'DHCPACK'); await cmd('dig web.lab', 'IN A 192.168.10.1'); await cmd('curl http://web.lab', 'HTTP/1.1 200 OK');
        const download = page.waitForEvent('download'); await page.click('[data-action="export"]'); const artifact = await download; const exportPath = path.join(output, 'export.json'); await artifact.saveAs(exportPath); const exported = JSON.parse(fs.readFileSync(exportPath, 'utf8')); assert.equal(exported.nodes[0].interfaces[0].address, '192.168.10.100/24');
        const traceDownload = page.waitForEvent('download'); await page.click('[data-action="trace-export"]'); await (await traceDownload).saveAs(path.join(output, 'synthetic-trace.json'));
        await lesson('empty');
        for (const ip of ['10.0.0.1/24', '10.0.0.2/24']) { await page.click('[data-action="add-node"]'); await page.locator('[data-node-form] input[name="address-0"]').fill(ip); await page.locator('[data-node-form] button[type="submit"]').click(); await page.locator('[data-command]:not(:disabled)').waitFor(); }
        await page.click('[data-action="links"]'); await page.selectOption('[data-link-form] select[name="a"]', 'n1/eth0'); await page.selectOption('[data-link-form] select[name="b"]', 'n2/eth0'); await page.locator('[data-link-form] button[type="submit"]').click(); await page.locator('[data-command]:not(:disabled)').waitFor(); await page.selectOption('[data-terminal-node]', 'n1'); await cmd('ping 10.0.0.2', '1 transmitted, 1 received');
        await page.click('[data-tab="device"]'); await page.locator('[data-node-form] input[name="name"]').fill('<script>alert(1)</script>'); await page.locator('[data-node-form] button[type="submit"]').click(); await page.waitForFunction(() => document.querySelector('[data-terminal-node]').textContent.includes('<script>')); assert.equal(await page.locator('script').count(), 1, 'name is escaped');
        await page.locator('[data-file]').setInputFiles(exportPath); await page.waitForFunction(() => document.querySelector('[data-title]').value.includes('DHCP')); await page.locator('[data-command]:not(:disabled)').waitFor();
        await page.locator('[data-autoplay]').uncheck(); await cmd('ping 192.168.10.1'); await page.waitForFunction(() => document.querySelector('[data-clock]').textContent.includes('대기 2')); await page.click('[data-action="step"]'); await page.waitForFunction(() => document.querySelector('[data-clock]').textContent.includes('처리 1')); assert((await page.locator('[data-clock]').innerText()).includes('일시정지')); await page.click('[data-action="play"]'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('1 transmitted, 1 received'));
        await page.setViewportSize({width: 390, height: 844}); await page.screenshot({path: path.join(output, 'mobile.png'), fullPage: true});
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'page does not overflow on mobile');
        await page.setViewportSize({width: 1510, height: 1060}); await page.locator('[data-autoplay]').check();
        await lesson('ios-switch'); assert.equal(await page.locator('[data-terminal-node]').inputValue(), 'n2');
        await cmd('enable'); await page.locator('[data-command]').fill('conf'); await page.locator('[data-command]').press('Tab'); await page.waitForFunction(() => document.querySelector('[data-command]').value === 'configure ');
        await page.locator('[data-command]').fill(''); await page.locator('[data-command]').press('?'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('configure'));
        await cmd('conf t'); await cmd('interface Gi1/0/2'); await cmd('shutdown'); await cmd('end'); assert.equal(await page.locator('[data-managed-form] input[name="up-1"]').isChecked(), false, 'CLI updates inspector');
        await page.selectOption('[data-terminal-node]', 'n1'); await cmd('ping 192.168.10.20', '100% packet loss');
        await page.selectOption('[data-terminal-node]', 'n2'); await page.locator('[data-command]').press('ArrowUp'); assert.equal(await page.locator('[data-command]').inputValue(), 'end', 'command history is device scoped');
        await page.locator('[data-managed-form] input[name="up-1"]').check(); await page.locator('[data-managed-form] button[type="submit"]').click(); await page.locator('[data-command]:not(:disabled)').waitFor(); assert.equal(await page.locator('[data-prompt]').textContent(), 'SW-A#', 'GUI preserves console mode');
        await cmd('show running-config', 'no shutdown'); await cmd('conf t'); await cmd('hostname TEMP-SW'); await cmd('end');
        await page.screenshot({path: path.join(output, 'ios-switch.png'), fullPage: true});
        await page.click('[data-action="save"]'); await page.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1'));
        assert([...rows.values()].some(r => r.project.schema === 2 && r.project.nodes.some(n => n.profile === 'ios' && n.name === 'TEMP-SW' && n.startup.name === 'SW-A')), 'server stores running and startup separately');
        await cmd('reload', 'Save?'); await cmd('no', '[confirm]'); await cmd('', '시작 설정으로 재부팅'); await page.waitForFunction(() => document.querySelector('[data-prompt]').textContent === 'SW-A>');
        await lesson('fortios');
        const policy = ['config firewall policy', 'edit 0', 'set srcintf port1', 'set dstintf port2', 'set srcaddr all', 'set dstaddr all', 'set service PING', 'set action accept', 'set nat enable', 'next', 'end'].join('\n');
        await page.locator('[data-command]').evaluate((el, value) => { const clipboardData = new DataTransfer(); clipboardData.setData('text/plain', value); el.dispatchEvent(new ClipboardEvent('paste', {clipboardData, bubbles: true, cancelable: true})); }, policy);
        await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('FW-NAT (2) # set nat enable'));
        await cmd('show firewall policy', '"PING"'); await page.selectOption('[data-terminal-node]', 'n1'); await cmd('ping 203.0.113.10', '1 transmitted, 1 received');
        await page.screenshot({path: path.join(output, 'fortios.png'), fullPage: true});
        await lesson('ipsec'); await page.selectOption('[data-terminal-node]', 'n1'); await cmd('ping 192.168.20.10', '1 transmitted, 1 received'); await page.selectOption('[data-terminal-node]', 'n2'); await cmd('diagnose vpn tunnel list', 'model_sa=up');
        await page.screenshot({path: path.join(output, 'ipsec.png'), fullPage: true});
        await page.click('[data-action="save"]'); await page.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1'));
        await page.reload(); await page.locator('[data-command]:not(:disabled)').waitFor(); await page.selectOption('[data-terminal-node]', 'n2'); await cmd('show vpn ipsec phase1-interface', 'sim-ipsec-only');
        await page.setViewportSize({width: 390, height: 844}); await cmd('config vpn ipsec phase1-interface'); await cmd('edit to-b'); await page.screenshot({path: path.join(output, 'mobile-cli.png'), fullPage: true});
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'managed CLI does not overflow on mobile'); assert.deepEqual(errors, []); assert.deepEqual(external, []); assert(nonceChecks > 0);
        await page.setViewportSize({width: 1510, height: 1060}); await cmd('abort'); await page.click('[data-action="projects"]'); await page.locator('[data-action="load-project"]').first().click(); assert(await page.locator('[data-command]').isDisabled(), 'server fetch locks editing before response'); await page.locator('[data-command]:not(:disabled)').waitFor();
        await lesson('empty'); await page.selectOption('[data-add-type]', 'router'); await page.click('[data-action="add-node"]'); await page.locator('[data-command]:not(:disabled)').waitFor();
        assert.equal(await page.locator('[data-managed-form] input[name="up-0"]').isChecked(), false, 'new IOS router starts shutdown');
        await page.locator('[data-command]').evaluate(el => { const clipboardData = new DataTransfer(); clipboardData.setData('text/plain', 'enable\nconfigure terminal\nhostname BATCH-OK\nrouter unsupported 1\nhostname SHOULD-NOT-APPLY'); el.dispatchEvent(new ClipboardEvent('paste', {clipboardData, bubbles: true, cancelable: true})); });
        await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('4번째 줄에서 중지'));
        assert((await page.locator('[data-prompt]').textContent()).startsWith('BATCH-OK')); assert(!(await page.locator('[data-terminal]').textContent()).includes('SHOULD-NOT-APPLY'));
        await cmd('end'); await page.locator('[data-command]').fill('reload'); await page.locator('[data-command]').press('Control+c'); assert.equal(await page.locator('[data-command]').inputValue(), ''); await cmd('show running-config', 'hostname BATCH-OK');
        await cmd('show running-config | include ^hostname', 'hostname BATCH-OK');
        await cmd('lab cat /config/running.conf | grep -n hostname', '1:hostname BATCH-OK');
        await cmd('lab diff', '+hostname BATCH-OK');
        await page.locator('[data-command]').fill('show running-config | '); await page.locator('[data-command]').press('?'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('출력 분석 도움말'));
        await page.locator('[data-command]').fill("lab echo '"); await page.locator('[data-command]').press('?'); assert.equal(await page.locator('[data-command]').inputValue(), "lab echo '?");
        await page.locator('[data-command]').fill(''); await page.click('[data-action="tools-help"]'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('lab files'));
        await page.screenshot({path: path.join(output, 'ios-tools.png'), fullPage: true});
        await lesson('fortios'); await cmd('show firewall policy | grep -f HTTP', 'set nat enable');
        await cmd('lab cat /state/interfaces.txt | grep -n port1', 'port1');
        await page.click('[data-action="save"]'); await page.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1'));
        await page.selectOption('[data-terminal-node]', 'n1'); const readRequests = apiRequests;
        await cmd('ip addr | grep -n inet', '2:    inet 192.168.10.10/24');
        await cmd('lab echo "<img src=x onerror=alert(1)>" | grep -F img', '<img src=x onerror=alert(1)>'); assert.equal(await page.locator('.nl-terminal img').count(), 0, 'tool text is escaped');
        await page.locator('[data-command]').fill("echo 'a"); await page.locator('[data-command]').press('?'); assert.equal(await page.locator('[data-command]').inputValue(), "echo 'a?");
        await page.locator('[data-command]').fill(''); await cmd('lab stats | head -n 3', 'virtual_time_ms='); assert.equal(apiRequests, readRequests, 'analysis tools make no REST requests');
        await page.locator('[data-command]').fill('echo TYPED-DRAFT'); await page.locator('[data-command]').press('ArrowUp'); assert.equal(await page.locator('[data-command]').inputValue(), 'lab stats | head -n 3'); await page.locator('[data-command]').press('ArrowDown'); assert.equal(await page.locator('[data-command]').inputValue(), 'echo TYPED-DRAFT', 'history navigation restores current edit');
        await page.locator('[data-command]').press('Control+l'); assert.equal(await page.locator('[data-terminal]').textContent(), ''); assert.equal(await page.locator('[data-command]').inputValue(), 'echo TYPED-DRAFT'); await page.locator('[data-command]').press('Enter'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('\nTYPED-DRAFT'));
        await page.locator('[data-command]').fill('echo IME-DRAFT'); const imeOutput = await page.locator('[data-terminal]').textContent(); await page.locator('[data-command]').evaluate(el => el.dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', isComposing: true, bubbles: true, cancelable: true}))); assert.equal(await page.locator('[data-terminal]').textContent(), imeOutput); assert.equal(await page.locator('[data-command]').inputValue(), 'echo IME-DRAFT', 'IME confirmation does not submit');
        await page.locator('[data-command]').press('Enter'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('\nIME-DRAFT'));
        await page.locator('[data-command]').fill('echo COPY-DRAFT'); await page.locator('[data-command]').evaluate(el => el.setSelectionRange(5, 9)); await page.locator('[data-command]').press('Control+c'); assert.equal(await page.locator('[data-command]').inputValue(), 'echo COPY-DRAFT', 'Ctrl+C preserves selected input for copying');
        await page.locator('[data-command]').fill('ping -c 4 203.0.113.10'); await page.locator('[data-command]').press('Enter'); await page.locator('[data-command]').fill('echo PRESERVED-WHILE-RUNNING'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('4 transmitted')); assert.equal(await page.locator('[data-command]').inputValue(), 'echo PRESERVED-WHILE-RUNNING', 'asynchronous output preserves active edit');
        await page.locator('[data-command]').press('Control+l'); await page.locator('[data-command]').press('Enter'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('\nPRESERVED-WHILE-RUNNING')); await cmd('ip addr | grep -n inet', '2:    inet 192.168.10.10/24');
        await page.locator('.nl-terminal-panel').screenshot({path: path.join(output, 'terminal-inline-desktop.png'), caret: 'initial'});
        await cmd('lab cat /config/running.conf', '"interfaces"'); await page.locator('[data-terminal-screen]').evaluate(el => el.scrollTop = 0); await page.locator('[data-action="pause"]').evaluate(el => el.click()); await page.waitForTimeout(120); assert(await page.locator('[data-terminal-screen]').evaluate(el => el.scrollTop < 2), 'runtime refresh preserves scrollback position');
        await page.locator('[data-command]').fill('echo TOOLBAR-RUN'); await page.click('[data-action="terminal-submit"]'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('\nTOOLBAR-RUN')); assert(await page.locator('[data-command]').evaluate(el => el === document.activeElement), 'toolbar execution returns focus to inline command');
        await page.locator('[data-command]').press('Control+l'); await cmd('ip addr | grep -n inet', '2:    inet 192.168.10.10/24');
        await page.locator('[data-command]').fill('echo ' + 'long-command-'.repeat(25)); assert(await page.locator('[data-command]').evaluate(el => el.clientHeight > parseFloat(getComputedStyle(el).lineHeight) * 2), 'long commands wrap and grow');
        await page.setViewportSize({width: 390, height: 844}); await page.locator('[data-command]').fill('echo ' + 'wrapped-command-'.repeat(10)); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'inline command has no horizontal mobile overflow');
        await page.locator('.nl-terminal-panel').screenshot({path: path.join(output, 'terminal-inline-mobile.png'), caret: 'initial'}); await page.locator('[data-command]').fill('echo SOFT-ENTER'); await page.locator('[data-command]').evaluate(el => el.dispatchEvent(new InputEvent('beforeinput', {inputType: 'insertLineBreak', bubbles: true, cancelable: true}))); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('\nSOFT-ENTER')); assert.equal(await page.locator('[data-command]').inputValue(), '', 'soft keyboard line-break input executes once');
        await page.locator('[data-command]').press('Control+l'); await page.locator('[data-command]').press('Shift+Tab'); assert(!(await page.locator('[data-command]').evaluate(el => el === document.activeElement)), 'Shift+Tab leaves command entry');
        await page.setViewportSize({width: 1510, height: 1060});
        await page.screenshot({path: path.join(output, 'linux-tools.png'), fullPage: true});
        await lesson('windows'); assert.equal(await page.locator('[data-terminal-shell]').inputValue(), 'cmd'); assert.equal(await page.locator('[data-prompt]').textContent(), 'C:\\Lab>');
        await cmd('ipconfig /renew', 'DHCP lease acquired: 192.168.10.100/24'); await page.waitForFunction(() => document.querySelector('[data-windows-form] input[name="address-0"]').value === '192.168.10.100/24');
        await cmd('ipconfig /all | findstr /I /C:"IPv4 Address"', '\n   IPv4 Address'); await page.locator('.nl-terminal-panel').screenshot({path: path.join(output, 'windows-cmd.png'), caret: 'initial'});
        await page.selectOption('[data-terminal-shell]', 'powershell'); await page.waitForFunction(() => document.querySelector('[data-prompt]').textContent.startsWith('PS ')); await page.click('[data-action="clear-terminal"]');
        await cmd("Get-NetIPAddress | Where-Object { $_.AddressFamily -eq 'IPv4' } | Select-Object InterfaceAlias,IPAddress", '192.168.10.100');
        await cmd('Test-NetConnection winweb.lab -Port 80', 'TcpTestSucceeded : False');
        await page.selectOption('[data-terminal-node]', 'n4'); await page.selectOption('[data-terminal-shell]', 'powershell'); await page.waitForFunction(() => document.querySelector('[data-prompt]').textContent.startsWith('PS '));
        await cmd('New-NetFirewallRule -Name Web -DisplayName "Web Access" -Protocol TCP -LocalPort 80 -Profile Private -Action Allow', 'Profile     : {Private}');
        await page.selectOption('[data-windows-form] select[name="category-0"]', 'Private'); await page.locator('[data-windows-form] button[type="submit"]').click(); await page.locator('[data-command]:not(:disabled)').waitFor(); assert.equal(await page.locator('[data-terminal-shell]').inputValue(), 'powershell');
        assert((await page.locator('[data-status]').textContent()).includes('Windows 설정을 적용했습니다.'), 'Windows settings do not prompt for device startup configuration');
        await page.selectOption('[data-terminal-node]', 'n1'); await page.click('[data-action="clear-terminal"]'); await cmd('Test-NetConnection winweb.lab -Port 80', 'TcpTestSucceeded : True');
        await page.selectOption('[data-terminal-node]', 'n4'); await cmd('New-NetFirewallRule -Name BlockWeb -DisplayName BlockWeb -Protocol TCP -LocalPort 80 -Action Block', 'Action      : Block');
        await page.selectOption('[data-terminal-node]', 'n1'); await page.click('[data-action="clear-terminal"]'); await cmd('Test-NetConnection winweb.lab -Port 80', 'TcpTestSucceeded : False');
        await page.selectOption('[data-terminal-node]', 'n4'); await cmd('Set-NetFirewallRule -Name BlockWeb -Enabled False'); await cmd('Get-NetFirewallRule -Name Web | Get-NetFirewallPortFilter', '{80}');
        await page.screenshot({path: path.join(output, 'windows-firewall.png'), fullPage: true});
        await page.click('[data-action="save"]'); await page.locator('[data-action="save"]:not(:disabled)').waitFor(); await page.waitForFunction(() => document.querySelector('[data-remote]').textContent.startsWith('서버 저장됨'));
        const windowsRow = [...rows.values()].find(r => r.project.title.includes('Windows')); assert(windowsRow.project.nodes.find(n => n.id === 'n4').windows.rules.some(r => r.name === 'Web' && r.profiles[0] === 'Private')); assert.equal(windowsRow.project.nodes.find(n => n.id === 'n4').windows.rules.find(r => r.name === 'BlockWeb').enabled, false);
        await page.reload(); await page.locator('[data-command]:not(:disabled)').waitFor(); await page.selectOption('[data-terminal-node]', 'n1'); assert.equal(await page.locator('[data-terminal-shell]').inputValue(), 'cmd');
        await cmd('powershell'); await page.waitForFunction(() => document.querySelector('[data-terminal-shell]').value === 'powershell'); const windowsReads = apiRequests;
        await cmd('Get-NetIPConfiguration', '192.168.10.100'); await cmd('Test-NetConnection winweb.lab -Port 80', 'TcpTestSucceeded : True'); assert.equal(apiRequests, windowsReads, 'Windows queries and TCP packets make no API requests');
        await cmd('Get-Content C:\\Lab\\etc\\hostname', '\nWIN-PC'); await cmd('Clear-Host'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent === '');
        await page.locator('[data-command]').fill('Get-NetIPAdd'); await page.locator('[data-command]').press('Tab'); await page.waitForFunction(() => document.querySelector('[data-command]').value === 'Get-NetIPAddress '); await page.locator('[data-command]').press('Enter'); await page.waitForFunction(() => document.querySelector('[data-terminal]').textContent.includes('192.168.10.100'));
        await page.setViewportSize({width: 390, height: 844}); await cmd('Get-NetFirewallProfile | Format-List'); await page.locator('.nl-terminal-panel').screenshot({path: path.join(output, 'windows-mobile.png'), caret: 'initial'}); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Windows shell and settings fit mobile width');
        await page.setViewportSize({width: 1510, height: 1060}); await page.selectOption('[data-terminal-node]', 'n3'); assert(await page.locator('[data-shell-picker]').isHidden(), 'Linux device has no Windows shell selector');
        await lesson('empty'); await page.selectOption('[data-add-type]', 'pc'); await page.selectOption('[data-add-profile]', 'windows'); await page.click('[data-action="add-node"]'); await page.locator('[data-command]:not(:disabled)').waitFor(); assert.equal(await page.locator('[data-windows-form]').count(), 1); await cmd('ping -n 1 localhost', 'Received = 1');
        assert.deepEqual(errors, []); assert.deepEqual(external, []);
        console.log('Browser checks passed: inline terminal layout/click/edit/wrap/IME/history/copy/scrollback/mobile, legacy regression, IOS/FortiOS tools and configuration, IPsec/save/reload, no external traffic.');
        fs.writeFileSync(path.join(output, 'browser-result.json'), JSON.stringify({passed: true, errors, external, nonceChecks, apiRequests}, null, 2));
    } catch (error) {
        await page.screenshot({path: path.join(output, 'failure.png'), fullPage: true});
        fs.writeFileSync(path.join(output, 'browser-result.json'), JSON.stringify({passed: false, error: error.message, errors, status: await page.locator('[data-status]').textContent().catch(() => ''), terminal: await page.locator('[data-terminal]').textContent().catch(() => '')}, null, 2)); throw error;
    } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
