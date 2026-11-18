const fs = require('node:fs'), path = require('node:path'), net = require('node:net'), crypto = require('node:crypto');
const {spawn, execFile: execFileCallback} = require('node:child_process'), {promisify} = require('node:util');
const execFile = promisify(execFileCallback), assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const pluginRoot = path.resolve(__dirname, '..'), workspace = path.dirname(pluginRoot);
const cached = path.join(workspace, '_notes/tmp/oj-ml-1.0-integration');
const php = process.env.OJNL_REAL_PHP || path.join(cached, 'php83/php.exe');
const maria = process.env.OJNL_REAL_MARIA || path.join(cached, 'db/mariadb-11.4.10-winx64');
const wpZip = process.env.OJNL_REAL_WP_ZIP || path.join(cached, 'wordpress71.zip');
const platformZip = path.join(workspace, '_releases/oj-platform/2.5.9/oj-platform-2.5.9.zip');
const version = fs.readFileSync(path.join(pluginRoot, 'oj-network-lab.php'), 'utf8').match(/Version:\s*(\S+)/)[1];
const pluginZip = process.env.OJNL_REAL_PLUGIN_ZIP || path.join(workspace, `_releases/oj-network-lab/${version}/oj-network-lab-${version}.zip`);
const previousZip = path.join(workspace, '_releases/oj-network-lab/1.0.0-rc.2/oj-network-lab-1.0.0-rc.2.zip');
const evidence = path.join(workspace, '_notes/verification/oj-network-lab-windows-wordpress-2026-11-06', process.env.OJNL_REAL_MATRIX === 'wp66' ? 'wp66' : 'wp71');
const runRoot = path.join(workspace, '_notes/tmp', `network-lab-wp-${Date.now()}-${crypto.randomBytes(3).toString('hex')}`);
const site = path.join(runRoot, 'wordpress');
fs.mkdirSync(evidence, {recursive: true}); fs.mkdirSync(runRoot, {recursive: true});
const results = {passed: false, version, checks: [], failures: []};
const check = (condition, label) => { assert(condition, label); results.checks.push(label); console.log('OK ' + label); };
const password = crypto.randomBytes(24).toString('hex');
const processes = [], logs = [];
function start(binary, args, name, env = {}) {
    const log = fs.openSync(path.join(runRoot, name + '.log'), 'a'); logs.push(log);
    const child = spawn(binary, args, {cwd: binary === php ? path.dirname(php) : runRoot, windowsHide: true, stdio: ['ignore', log, log], env: {...process.env, ...env}}); processes.push(child); return child;
}
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function freePort() { return new Promise((resolve, reject) => { const s = net.createServer(); s.once('error', reject); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => resolve(p)); }); }); }
async function waitPort(port, child) {
    for (let i = 0; i < 150; i++) { if (child.exitCode !== null) throw new Error('Test service exited before startup.'); const ok = await new Promise(resolve => { const socket = net.connect({host: '127.0.0.1', port}); socket.on('connect', () => { socket.destroy(); resolve(true); }); socket.on('error', () => resolve(false)); }); if (ok) return; await pause(200); }
    throw new Error('Test service startup timed out.');
}
async function extract(zip, destination) {
    const python = process.env.PYTHON_BINARY || 'C:/Users/user/AppData/Local/Programs/Python/Python314/python.exe';
    await execFile(python, ['-c', 'import sys,zipfile,pathlib; p=pathlib.Path(sys.argv[2]).resolve(); z=zipfile.ZipFile(sys.argv[1]); assert all((p / i.filename).resolve().is_relative_to(p) for i in z.infolist()); z.extractall(p)', zip, destination], {windowsHide: true, maxBuffer: 1024 * 1024});
}
let ini, origin, browser;
async function driver(mode) {
    const {stdout, stderr} = await execFile(php, ['-c', ini, path.join(__dirname, 'wordpress-driver.php'), mode], {cwd: path.dirname(php), windowsHide: true, timeout: 120000, maxBuffer: 4 * 1024 * 1024, env: {...process.env, OJNL_WP_ROOT: site, OJNL_TEST_PASSWORD: password, OJNL_PLATFORM_ZIP: platformZip}});
    fs.writeFileSync(path.join(runRoot, `driver-${mode}.log`), stdout + stderr);
    const match = /OJNL_JSON:(.+)/.exec(stdout); if (!match) throw new Error('WordPress driver did not complete: ' + mode); return JSON.parse(match[1]);
}
async function login(page, username) {
    await page.goto(origin + '/wp-login.php');
    if (await page.locator('#user_login').count()) { await page.locator('#user_login').fill(username); await page.locator('#user_pass').fill(password); await page.locator('#wp-submit').click(); }
    else { await page.locator('input[name="user_login"]').fill(username); await page.locator('input[name="password"]').fill(password); await page.locator('form:has(input[name="user_login"]) button[type="submit"]').click(); }
    await page.waitForLoadState('domcontentloaded');
    await completeProfile(page, username);
}
async function completeProfile(page, username) {
    if (!await page.locator('input[name="mode"][value="profile-completion"]').count()) return;
    const form = page.locator('form:has(input[name="mode"][value="profile-completion"])');
    const email = form.locator('input[name="email"]'), displayName = form.locator('input[name="display_name"]');
    if (!await email.inputValue()) await email.fill(username + '@example.invalid');
    if (!await displayName.inputValue()) await displayName.fill(username);
    await form.locator('input[name="affiliation"]').fill('Isolated local verification');
    await form.locator('input[name="birth_date"]').fill('2000-01-01');
    results.profileFormFields = await form.evaluate(el => Object.fromEntries([...new FormData(el)].filter(([key]) => ['mode', 'default_language', 'editor_theme', 'default_editor', 'editor_font_size', 'profile_visibility', 'activity_visibility', 'inline_submission_panel'].includes(key))));
    await form.locator('button[type="submit"]').click(); await page.waitForLoadState('domcontentloaded');
    if (await page.locator('input[name="mode"][value="profile-completion"]').count()) { results.profileDiagnostic = await driver('debug-profile'); console.log('Profile diagnostic: ' + JSON.stringify(results.profileDiagnostic)); }
    check(await page.locator('input[name="mode"][value="profile-completion"]').count() === 0, 'real profile completion form saved for test account');
}
async function configOf(page) { return page.locator('[data-network-lab]').evaluate(el => JSON.parse(el.dataset.config)); }
async function rest(page, method, suffix, data) {
    return page.evaluate(async ({method, suffix, data}) => { const c = JSON.parse(document.querySelector('[data-network-lab]').dataset.config); const r = await fetch(c.restUrl + suffix, {method, credentials: 'same-origin', headers: {'X-WP-Nonce': c.nonce, ...(data ? {'Content-Type': 'application/json'} : {})}, ...(data ? {body: JSON.stringify(data)} : {})}); return {status: r.status, data: await r.json()}; }, {method, suffix, data});
}
(async () => {
    try {
        for (const file of [php, wpZip, platformZip, pluginZip, previousZip]) assert(fs.existsSync(file), 'Missing test input: ' + file);
        const dbPort = await freePort(), wpPort = await freePort(); origin = `http://127.0.0.1:${wpPort}`;
        fs.writeFileSync(path.join(runRoot, 'network-lab-test.json'), JSON.stringify({purpose: 'oj-network-lab-disposable-wordpress', root: site, url: origin}));
        ini = path.join(runRoot, 'php.ini');
        fs.writeFileSync(ini, `extension_dir="ext"\nextension=mysqli\nextension=pdo_mysql\nextension=mbstring\nextension=openssl\nextension=curl\nextension=zip\nextension=gd\nextension=intl\nextension=fileinfo\nerror_reporting=E_ALL\ndisplay_errors=Off\nlog_errors=On\nerror_log="${path.join(runRoot, 'php-errors.log').replace(/\\/g, '/')}"\nmemory_limit=512M\nmax_execution_time=120\nupload_max_filesize=32M\npost_max_size=32M\ndate.timezone=UTC\n`);
        const dataDir = path.join(runRoot, 'db');
        console.log('Preparing isolated MariaDB data directory.');
        await execFile(path.join(maria, 'bin/mariadb-install-db.exe'), [`--datadir=${dataDir}`, `--port=${dbPort}`, `--password=${password}`, '--silent'], {windowsHide: true, timeout: 120000, maxBuffer: 1024 * 1024});
        const database = start(path.join(maria, 'bin/mariadbd.exe'), ['--no-defaults', `--basedir=${maria}`, `--datadir=${dataDir}`, '--bind-address=127.0.0.1', `--port=${dbPort}`, '--skip-name-resolve', '--innodb-buffer-pool-size=64M', '--max-connections=30', '--console'], 'mariadb');
        await waitPort(dbPort, database);
        await execFile(php, ['-c', ini, '-r', "$p=new PDO('mysql:host=127.0.0.1;port=' . getenv('OJNL_DB_PORT'), 'root', getenv('OJNL_TEST_PASSWORD'));$p->exec('CREATE DATABASE network_lab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');echo 'database ready';"], {cwd: path.dirname(php), windowsHide: true, env: {...process.env, OJNL_DB_PORT: String(dbPort), OJNL_TEST_PASSWORD: password}});
        console.log('Extracting a clean WordPress core archive.'); await extract(wpZip, runRoot);
        const secretPhp = `<?php\ndefine('DB_NAME','network_lab_test');define('DB_USER','root');define('DB_PASSWORD','${password}');define('DB_HOST','127.0.0.1:${dbPort}');\n` + ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'].map(name => `define('${name}','${crypto.randomBytes(32).toString('hex')}');`).join('\n');
        fs.writeFileSync(path.join(runRoot, 'private.php'), secretPhp);
        fs.writeFileSync(path.join(site, 'wp-config.php'), `<?php\nrequire dirname(__DIR__).'/private.php';\ndefine('DB_CHARSET','utf8mb4');define('DB_COLLATE','');$table_prefix='wp_';\ndefine('WP_HOME','${origin}');define('WP_SITEURL','${origin}');define('WP_DEBUG',true);define('WP_DEBUG_DISPLAY',false);define('WP_DEBUG_LOG',true);define('DISABLE_WP_CRON',true);define('AUTOMATIC_UPDATER_DISABLED',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_ACCESSIBLE_HOSTS','127.0.0.1');define('FS_METHOD','direct');define('WP_ENVIRONMENT_TYPE','local');\nif(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require_once ABSPATH.'wp-settings.php';\n`);
        results.environment = await driver('install'); check(results.environment.installed, 'real WordPress core installed');
        check((await driver('install-platform')).platformActive, 'official OJ Platform ZIP installed and activated');
        fs.writeFileSync(path.join(runRoot, 'router.php'), `<?php\n$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$f=realpath(__DIR__.'/wordpress/'.ltrim(rawurldecode($p),'/'));if($f&&str_starts_with($f,__DIR__.DIRECTORY_SEPARATOR.'wordpress'.DIRECTORY_SEPARATOR)&&is_file($f))return false;require __DIR__.'/wordpress/index.php';\n`);
        const web = start(php, ['-c', ini, '-S', `127.0.0.1:${wpPort}`, '-t', site, path.join(runRoot, 'router.php')], 'wordpress'); await waitPort(wpPort, web);
        browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
        const admin = await browser.newPage({viewport: {width: 1510, height: 1100}});
        await login(admin, 'network-admin');
        await admin.goto(origin + '/wp-admin/admin.php?page=oj-platform-setup');
        await admin.locator('form:has(input[name="action"][value="oj_platform_setup"]) button[type="submit"]').click();
        await admin.waitForLoadState('domcontentloaded');
        await admin.screenshot({path: path.join(evidence, '00-platform-setup.png'), fullPage: true});
        check(new URL(admin.url()).pathname.startsWith('/oj-console') || new URL(admin.url()).pathname === '/user-settings', 'platform installation wizard completes through real admin form');
        await completeProfile(admin, 'network-admin');
        await admin.goto(origin + '/wp-admin/plugin-install.php?tab=upload');
        await admin.locator('input[name="pluginzip"]').setInputFiles(previousZip); await admin.locator('#install-plugin-submit').click();
        await admin.waitForLoadState('domcontentloaded');
        await admin.screenshot({path: path.join(evidence, '01-zip-install.png'), fullPage: true});
        const activateLink = admin.locator('a[href*="action=activate"][href*="oj-network-lab"]');
        await activateLink.first().click(); await admin.waitForLoadState('domcontentloaded');
        results.installation = await driver('report');
        fs.writeFileSync(path.join(evidence, 'installation.json'), JSON.stringify(results.installation, null, 2));
        check(results.installation.pluginActive && results.installation.addonStatus === 'accepted', 'ZIP activation and real platform catalog accept addon');
        check(results.installation.engine === 'InnoDB' && results.installation.schema === '1', 'real MariaDB table and schema marker created');
        check(results.installation.indexes.includes('owner_client') && results.installation.cleanupScheduled, 'unique owner index and cleanup schedule created');
        await admin.goto(origin + '/oj-console/addons/oj-network-lab'); await admin.locator('[data-command]:not(:disabled)').waitFor();
        await admin.screenshot({path: path.join(evidence, '02-platform-screen.png'), fullPage: true});
        await driver('users');
        const a = await browser.newPage({viewport: {width: 1440, height: 1100}}), b = await browser.newPage(), denied = await browser.newPage();
        await login(a, 'network-student-a'); await a.goto(origin + '/oj-addons/oj-network-lab/learn'); await a.locator('[data-command]:not(:disabled)').waitFor();
        async function cmd(line, expected) {
            await a.bringToFront(); const before = await a.locator('[data-terminal]').textContent(); await a.locator('[data-command]').fill(line); await a.locator('[data-command]').press('Enter');
            await a.waitForFunction(text => document.querySelector('[data-terminal]').textContent !== text, before);
            try { if (expected) await a.waitForFunction(text => document.querySelector('[data-terminal]').textContent.includes(text), expected); }
            catch (error) { results.simulationFailure = await a.evaluate(() => ({title: document.querySelector('[data-title]')?.value, selected: document.querySelector('[data-terminal-node]')?.value, terminal: document.querySelector('[data-terminal]')?.textContent, clock: document.querySelector('[data-clock]')?.textContent, status: document.querySelector('[data-status]')?.textContent, visible: document.visibilityState})); throw error; }
        }
        async function lesson(id) { await a.selectOption('[data-scenario]', id); await a.click('[data-action="lesson"]'); await a.locator('[data-command]:not(:disabled)').waitFor(); }
        await cmd('ping -c 2 192.168.10.20', '2 transmitted, 2 received'); check(true, 'real student page runs LAN simulation in Worker');
        await a.click('[data-action="save"]'); await a.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1'));
        const list = await rest(a, 'GET', 'projects'); check(list.status === 200 && list.data.projects.length === 1, 'cookie and real REST nonce save project to MariaDB');
        const id = Number(list.data.projects[0].id), row = await rest(a, 'GET', `projects/${id}`);
        check(row.status === 200 && row.data.project.nodes.length === 4, 'saved JSON project reads back from database');
        await a.reload(); await a.locator('[data-command]:not(:disabled)').waitFor(); check((await a.locator('[data-status]').textContent()).includes('복원'), 'browser draft restores after full WordPress reload');
        check(row.data.project.engine === '1.0.0-rc.2', 'legacy ZIP creates original rc.2 project before upgrade');
        await admin.goto(origin + '/wp-admin/plugin-install.php?tab=upload');
        await admin.locator('input[name="pluginzip"]').setInputFiles(pluginZip); await admin.locator('#install-plugin-submit').click();
        await admin.locator('a.update-from-upload-overwrite').click(); await admin.waitForLoadState('domcontentloaded');
        await admin.screenshot({path: path.join(evidence, '05-zip-upgrade.png'), fullPage: true});
        const upgraded = await driver('report');
        check(upgraded.version === version && upgraded.pluginActive && upgraded.projectCount >= 1, 'real WordPress ZIP replacement preserves activation and project data');
        await a.reload(); await a.locator('[data-command]:not(:disabled)').waitFor();
        check((await configOf(a)).version === version && (await a.locator('[data-status]').textContent()).includes('복원'), 'updated assets restore legacy browser draft');
        await a.click('[data-action="projects"]'); await a.locator(`[data-action="load-project"][data-id="${id}"]`).click();
        await a.locator('[data-command]:not(:disabled)').waitFor(); await cmd('ping 192.168.10.20', '1 transmitted, 1 received');
        await a.locator('[data-title]').fill('업데이트 후 보존된 프로젝트'); await a.locator('[data-title]').press('Tab');
        await a.click('[data-action="save"]'); await a.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 2'));
        const migrated = await rest(a, 'GET', `projects/${id}`);
        check(migrated.status === 200 && migrated.data.project.engine === version && migrated.data.revision === 2 && migrated.data.project.nodes.length === row.data.project.nodes.length, 'legacy server project opens and saves current format with same ID');
        check(migrated.data.project.schema === 2 && migrated.data.project.nodes.every(n => n.profile === 'linux'), 'schema 1 upgrade preserves legacy Linux profile');
        check(await a.locator('[data-command]').evaluate(el => { const output = document.querySelector('[data-terminal]'), screen = document.querySelector('[data-terminal-screen]'), form = el.closest('form'); return screen?.contains(el) && output.nextElementSibling === form && getComputedStyle(el).borderTopWidth === '0px' && Math.abs(form.getBoundingClientRect().top - output.getBoundingClientRect().bottom) < 2; }), 'installed ZIP shows inline prompt and borderless command after terminal output');
        await a.selectOption('[data-terminal-node]', 'n1'); await cmd('ip addr | grep -n inet', '2:    inet 192.168.10.10/24');
        await cmd('lab files | grep /state/interfaces.txt', '/state/interfaces.txt'); await cmd('lab stats | head -n 3', 'virtual_time_ms=');
        check((await rest(a, 'GET', `projects/${id}`)).data.revision === migrated.data.revision, 'Linux grep pipelines and common virtual tools run without changing saved revision');
        const changed = structuredClone(migrated.data.project); changed.title = 'stale request';
        check((await rest(a, 'PUT', `projects/${id}`, {revision: 0, project: changed})).status === 409, 'real database rejects stale revision');
        const noNonce = await a.evaluate(async () => { const c = JSON.parse(document.querySelector('[data-network-lab]').dataset.config); const r = await fetch(c.restUrl + 'projects', {credentials: 'same-origin'}); return r.status; }); check(noNonce === 401 || noNonce === 403, 'missing REST nonce rejected');
        await login(b, 'network-student-b'); await b.goto(origin + '/oj-addons/oj-network-lab/learn'); await b.locator('[data-command]:not(:disabled)').waitFor();
        check((await rest(b, 'GET', `projects/${id}`)).status === 404 && (await rest(b, 'DELETE', `projects/${id}`, {revision: 1})).status === 404, 'other account cannot read or delete student project');
        await login(denied, 'network-denied'); await denied.goto(origin + '/oj-addons/oj-network-lab/learn'); check(await denied.locator('[data-network-lab]').count() === 0, 'learner without declared capability cannot open lab');
        await lesson('routing'); await cmd('ping 192.168.20.10', '1 transmitted, 1 received');
        await a.selectOption('[data-terminal-node]', 'n4'); await cmd('ip route del default'); await a.selectOption('[data-terminal-node]', 'n1'); await a.click('[data-action="clear-terminal"]'); await cmd('ping 192.168.20.10', '100% packet loss'); check(true, 'routing success and missing return path differ on real page');
        await lesson('firewall'); await cmd('curl http://203.0.113.10', 'HTTP/1.1 200 OK'); await cmd('ping 203.0.113.10', '100% packet loss'); check(true, 'firewall and NAT model work within WordPress shell');
        await lesson('vpn'); await cmd('ping 192.168.20.10', '1 transmitted, 1 received'); await a.selectOption('[data-terminal-node]', 'n2'); await cmd('wg show', 'ms 전');
        await a.screenshot({path: path.join(evidence, '03-vpn.png'), fullPage: true}); check(true, 'VPN model and handshake inspection work on real student page');
        await lesson('dhcp'); await cmd('dhclient eth0', 'DHCPACK'); await cmd('dig web.lab', 'IN A 192.168.10.1'); await cmd('curl http://web.lab', 'HTTP/1.1 200 OK'); check(true, 'DHCP DNS and HTTP workflow passes in real WordPress');
        await a.click('[data-action="save"]'); await a.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1'));
        await lesson('ios-switch'); await cmd('enable'); await cmd('conf t'); await cmd('interface Gi1/0/2'); await cmd('shutdown'); await cmd('end');
        check(!await a.locator('[data-managed-form] input[name="up-1"]').isChecked(), 'real IOS console changes managed GUI state');
        await a.selectOption('[data-terminal-node]', 'n1'); await cmd('ping 192.168.10.20', '100% packet loss');
        await a.selectOption('[data-terminal-node]', 'n2'); await a.locator('[data-managed-form] input[name="up-1"]').check(); await a.locator('[data-managed-form] button[type="submit"]').click(); await a.locator('[data-command]:not(:disabled)').waitFor();
        await cmd('write memory'); await cmd('conf t'); await cmd('hostname TEMP-SW'); await cmd('end');
        await a.click('[data-action="save"]'); await a.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1'));
        const managedList = await rest(a, 'GET', 'projects'), managedItem = managedList.data.projects.find(p => p.title.includes('IOS 스위치')), managedRow = await rest(a, 'GET', `projects/${managedItem.id}`);
        check(managedRow.data.project.nodes.find(n => n.id === 'n2').name === 'TEMP-SW' && managedRow.data.project.nodes.find(n => n.id === 'n2').startup.name === 'SW-A', 'real MariaDB stores running and startup settings separately');
        await a.screenshot({path: path.join(evidence, '06-ios-cli.png'), fullPage: true});
        await a.reload(); await a.locator('[data-command]:not(:disabled)').waitFor(); await a.selectOption('[data-terminal-node]', 'n2'); await cmd('enable'); await cmd('reload', 'Save?'); await cmd('no', '[confirm]'); await cmd('', '시작 설정으로 재부팅');
        check(await a.locator('[data-prompt]').textContent() === 'SW-A>', 'stored IOS project restores startup configuration on confirmed reload');
        await cmd('enable'); await a.click('[data-action="clear-terminal"]'); await cmd('show running-config | include ^hostname', 'hostname SW-A');
        await cmd('lab cat /config/running.conf | grep -n hostname', '1:hostname SW-A'); await cmd('lab diff');
        await a.screenshot({path: path.join(evidence, '09-ios-tools.png'), fullPage: true}); check(true, 'IOS native output filter and common configuration analysis run from installed ZIP');
        await a.locator('[data-command]').focus(); await a.locator('.nl-terminal-panel').screenshot({path: path.join(evidence, '11-inline-terminal.png'), caret: 'initial'});
        await lesson('fortios');
        await cmd('show firewall policy | grep -f HTTP', 'set nat enable'); await cmd('lab cat /state/interfaces.txt | grep -n port1', 'port1');
        await a.screenshot({path: path.join(evidence, '10-fortios-tools.png'), fullPage: true}); check(true, 'FortiOS grep configuration context and common interface analysis work in real WordPress');
        for (const line of ['config firewall policy', 'edit 0', 'set srcintf port1', 'set dstintf port2', 'set srcaddr all', 'set dstaddr all', 'set service PING', 'set action accept', 'set nat enable', 'next', 'end']) await cmd(line);
        await a.selectOption('[data-terminal-node]', 'n1'); await cmd('ping 203.0.113.10', '1 transmitted, 1 received'); check(true, 'real FortiOS CLI policy and NAT alter packet verdict');
        await lesson('ipsec'); await a.selectOption('[data-terminal-node]', 'n1'); await cmd('ping 192.168.20.10', '1 transmitted, 1 received'); await a.selectOption('[data-terminal-node]', 'n2'); await cmd('diagnose vpn tunnel list', 'model_sa=up');
        await a.screenshot({path: path.join(evidence, '07-ipsec-cli.png'), fullPage: true}); check(true, 'real WordPress runs separate IKEv2 ESP model from device CLI');
        await a.click('[data-action="save"]'); await a.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1'));
        const {pathToFileURL} = require('node:url'), {scenario: testScenario} = await import(pathToFileURL(path.join(pluginRoot, 'resources/js/scenarios.js')).href), {assignProfile: testProfile} = await import(pathToFileURL(path.join(pluginRoot, 'resources/js/device-config.js')).href);
        const diagnostic = testScenario('vpn'); diagnostic.title = 'FortiOS 경로 진단';
        for (const i of [1, 3]) { const n = diagnostic.nodes[i]; n.interfaces = n.interfaces.filter(f => f.kind === 'ethernet'); n.routes = [{dest: i === 1 ? '192.168.20.0/24' : '192.168.10.0/24', via: i === 1 ? '198.51.100.2' : '198.51.100.1', dev: 'eth1', metric: 1}]; }
        diagnostic.nodes[1].type = 'firewall'; testProfile(diagnostic.nodes[1], 'fortios');
        await a.locator('[data-file]').setInputFiles({name: 'fortios-diagnostic.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(diagnostic))});
        await a.waitForFunction(() => document.querySelector('[data-title]').value === 'FortiOS 경로 진단'); await a.locator('[data-command]:not(:disabled)').waitFor();
        await a.selectOption('[data-terminal-node]', 'n2'); await cmd('execute traceroute 192.168.20.10', '1  198.51.100.2');
        check((await a.locator('[data-terminal]').textContent()).includes('2  192.168.20.10'), 'FortiOS traceroute observes related ICMP hop and destination on real page');
        await a.screenshot({path: path.join(evidence, '08-diagnostics.png'), fullPage: true});
        await a.click('[data-action="save"]'); await a.waitForFunction(() => document.querySelector('[data-remote]').textContent.includes('버전 1'));
        await lesson('windows'); check(await a.locator('[data-terminal-shell]').inputValue() === 'cmd', 'installed Windows lesson starts with CMD on the selected PC');
        await cmd('ipconfig /renew', 'DHCP lease acquired: 192.168.10.100/24'); await cmd('nslookup winweb.lab', 'Address: 192.168.10.20');
        check(await a.locator('[data-windows-form] input[name="address-0"]').inputValue() === '192.168.10.100/24', 'Windows CMD DHCP and DNS operate through real WordPress Worker');
        await a.locator('.nl-terminal-panel').screenshot({path: path.join(evidence, '12-windows-cmd.png'), caret: 'initial'});
        await a.selectOption('[data-terminal-shell]', 'powershell'); await a.waitForFunction(() => document.querySelector('[data-prompt]').textContent.startsWith('PS '));
        await cmd('New-NetIPAddress -InterfaceAlias Ethernet -IPAddress 192.168.10.110 -PrefixLength 24 -DefaultGateway 192.168.10.1');
        await cmd('Set-DnsClientServerAddress -InterfaceAlias Ethernet -ServerAddresses 192.168.10.1');
        check(!await a.locator('[data-windows-form] input[name="dhcp-0"]').isChecked() && await a.locator('[data-windows-form] input[name="address-0"]').inputValue() === '192.168.10.110/24', 'PowerShell static address disables DHCP and synchronizes Windows GUI');
        await a.click('[data-action="clear-terminal"]'); await cmd("Get-NetIPAddress | Where-Object { $_.AddressFamily -eq 'IPv4' } | Select-Object InterfaceAlias,IPAddress", '192.168.10.110');
        check(true, 'PowerShell object pipeline runs from installed ZIP');
        await a.click('[data-action="clear-terminal"]'); await cmd('Test-NetConnection winweb.lab -Port 80', 'TcpTestSucceeded : False'); check(true, 'default Windows inbound firewall blocks virtual TCP handshake');
        await a.selectOption('[data-terminal-node]', 'n4'); await a.selectOption('[data-terminal-shell]', 'powershell'); await a.waitForFunction(() => document.querySelector('[data-prompt]').textContent.startsWith('PS '));
        await cmd('New-NetFirewallRule -Name Web -DisplayName Web -Protocol TCP -LocalPort 80 -Profile Private -Action Allow', 'Profile     : {Private}');
        await a.selectOption('[data-windows-form] select[name="category-0"]', 'Private'); await a.locator('[data-windows-form] button[type="submit"]').click(); await a.locator('[data-command]:not(:disabled)').waitFor();
        check((await a.locator('[data-status]').textContent()).includes('Windows 설정을 적용했습니다.'), 'Windows GUI apply message uses host configuration semantics');
        await a.selectOption('[data-terminal-node]', 'n1'); await a.click('[data-action="clear-terminal"]'); await cmd('Test-NetConnection winweb.lab -Port 80', 'TcpTestSucceeded : True'); check(true, 'Windows firewall rule and GUI network category allow modeled TCP connection');
        await a.selectOption('[data-terminal-node]', 'n4'); await cmd('New-NetFirewallRule -Name Deny -DisplayName Deny -Protocol TCP -LocalPort 80 -Action Block');
        await a.selectOption('[data-terminal-node]', 'n1'); await a.click('[data-action="clear-terminal"]'); await cmd('Test-NetConnection winweb.lab -Port 80', 'TcpTestSucceeded : False');
        await a.selectOption('[data-terminal-node]', 'n4'); await cmd('Disable-NetFirewallRule -Name Deny'); await cmd('Get-NetFirewallRule -Name Web | Get-NetFirewallPortFilter', '{80}'); check(true, 'explicit Windows block precedence and rule disable work on real student page');
        await a.screenshot({path: path.join(evidence, '13-windows-firewall.png'), fullPage: true});
        await a.click('[data-action="save"]'); await a.locator('[data-action="save"]:not(:disabled)').waitFor(); await a.waitForFunction(() => document.querySelector('[data-remote]').textContent.startsWith('서버 저장됨'));
        const windowsList = await rest(a, 'GET', 'projects'), windowsItem = windowsList.data.projects.find(p => p.title.includes('Windows')), windowsSaved = await rest(a, 'GET', `projects/${windowsItem.id}`), windowsServer = windowsSaved.data.project.nodes.find(n => n.id === 'n4');
        check(windowsSaved.status === 200 && windowsServer.profile === 'windows' && windowsServer.windows.rules.some(r => r.name === 'Deny' && r.enabled === false) && windowsServer.windows.adapters[0].category === 'Private' && !windowsServer.startup, 'real MariaDB preserves Windows host and firewall settings');
        const invalidWindows = structuredClone(windowsSaved.data.project); invalidWindows.nodes.find(n => n.id === 'n4').windows.rules[0].localPort = ['900-80'];
        check((await rest(a, 'PUT', `projects/${windowsItem.id}`, {revision: windowsSaved.data.revision, project: invalidWindows})).status === 400 && (await rest(a, 'GET', `projects/${windowsItem.id}`)).data.revision === windowsSaved.data.revision, 'real REST rejects invalid Windows firewall range without changing revision');
        check((await rest(b, 'GET', `projects/${windowsItem.id}`)).status === 404, 'Windows project remains isolated from another account');
        await a.reload(); await a.locator('[data-command]:not(:disabled)').waitFor(); await a.selectOption('[data-terminal-node]', 'n1'); check(await a.locator('[data-terminal-shell]').inputValue() === 'cmd', 'Windows reload restores settings and starts a fresh CMD console');
        await cmd('powershell'); await cmd('Test-NetConnection winweb.lab -Port 80', 'TcpTestSucceeded : True');
        await a.setViewportSize({width: 390, height: 844}); await a.locator('.nl-terminal-panel').screenshot({path: path.join(evidence, '14-windows-mobile.png'), caret: 'initial'}); check(await a.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Windows shell and host settings fit real platform mobile shell');
        check((await driver('privacy')).exportCount === (await rest(a, 'GET', 'projects')).data.projects.length, 'real WordPress privacy exporter includes own saved projects');
        await a.setViewportSize({width: 390, height: 844}); await a.screenshot({path: path.join(evidence, '04-mobile.png'), fullPage: true}); check(await a.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'real platform mobile shell does not overflow');
        // Freeze browser writers before comparing lifecycle data; autosaves may add records.
        await browser.close(); browser = null;
        if (web.exitCode === null && web.signalCode === null) { const stopped = new Promise(resolve => web.once('exit', resolve)); web.kill(); await stopped; }
        const beforeDeactivate = await driver('report'); results.beforeDeactivation = beforeDeactivate;
        const deactivated = await driver('deactivate'); check(!deactivated.active && !deactivated.cleanupScheduled && deactivated.schema === '1', 'deactivation keeps data and clears scheduled cleanup');
        check((await driver('activate')).active, 'reactivation succeeds'); const after = await driver('report'); results.final = after;
        check(after.projectCount === beforeDeactivate.projectCount && after.projectDigest === beforeDeactivate.projectDigest, 'reactivation preserves project records');
        results.final = after; results.passed = true;
    } catch (error) {
        results.failures.push(error.message); console.error(error.stack);
        if (browser) { let index = 0; for (const context of browser.contexts()) for (const page of context.pages()) if (!page.isClosed()) { try { await page.screenshot({path: path.join(evidence, `failure-${index}.png`), fullPage: true}); fs.writeFileSync(path.join(evidence, `failure-page-${index}.txt`), await page.locator('body').innerText()); index++; } catch {} } }
        process.exitCode = 1;
    } finally {
        if (browser) await browser.close();
        for (const child of processes.reverse()) if (child.exitCode === null && child.signalCode === null) { child.kill(); await Promise.race([new Promise(resolve => child.once('exit', resolve)), pause(5000)]); }
        for (const log of logs) try { fs.closeSync(log); } catch {}
        for (const name of ['php-errors.log', 'mariadb.log', 'wordpress.log']) { const file = path.join(runRoot, name); fs.writeFileSync(path.join(evidence, name), fs.existsSync(file) ? fs.readFileSync(file) : ''); }
        const debug = path.join(site, 'wp-content/debug.log'); fs.writeFileSync(path.join(evidence, 'wordpress-debug.log'), fs.existsSync(debug) ? fs.readFileSync(debug) : '');
        fs.writeFileSync(path.join(evidence, 'result.json'), JSON.stringify(results, null, 2));
        console.log(`Real WordPress checks: ${results.checks.length}; passed=${results.passed}.`);
        if (!process.env.OJNL_KEEP_TEST_SITE) { const allowed = path.resolve(workspace, '_notes/tmp') + path.sep, resolved = path.resolve(runRoot); if (!resolved.startsWith(allowed) || !path.basename(resolved).startsWith('network-lab-wp-')) throw new Error('Unsafe test cleanup path.'); fs.rmSync(resolved, {recursive: true, force: true}); }
    }
})();
