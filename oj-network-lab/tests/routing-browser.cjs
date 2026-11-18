const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),out=path.resolve(root,'../_notes/tmp/oj-network-routing');
let html,browser;
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');res.end(`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/resources/css/network-lab.css"><style>body{margin:12px;font-family:Arial,sans-serif}</style>${html}<script src="/resources/js/app.js"></script></html>`);return;}
  if(url.pathname.startsWith('/api/')){res.setHeader('Content-Type','application/json');res.end('{"projects":[]}');return;}
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.writeHead(404);res.end();return;}
  res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':'text/css');res.end(fs.readFileSync(file));
});
(async()=>{
  const {ipv6Lab,ospfLab,bgpLab}=await import('./routing.mjs');
  await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin=`http://127.0.0.1:${server.address().port}`;
  html=execFileSync('D:/php/php.exe',[path.join(__dirname,'render.php')],{encoding:'utf8',env:{...process.env,OJNL_TEST_ORIGIN:origin}});
  browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
  const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));page.setDefaultTimeout(15000);
  await context.route('**/*',r=>r.request().url().startsWith(origin+'/')||r.request().url().startsWith('blob:')?r.continue():r.abort());
  async function cmd(id,line,expected){
    await page.selectOption('[data-terminal-node]',id);await page.click('[data-action="clear-terminal"]');
    await page.locator('[data-command]').fill(line);await page.locator('[data-command]').press('Enter');
    await page.waitForFunction(t=>document.querySelector('[data-terminal]').textContent.includes(t),line);
    if(expected)await page.waitForFunction(t=>document.querySelector('[data-terminal]').textContent.includes(t),expected);
    await page.waitForFunction(()=>document.querySelector('[data-clock]').textContent.includes('대기 0'));
  }
  async function batch(id,lines){
    await page.selectOption('[data-terminal-node]',id);await page.click('[data-action="clear-terminal"]');
    await page.locator('[data-command]').evaluate((el,value)=>{const d=new DataTransfer();d.setData('text/plain',value);el.dispatchEvent(new ClipboardEvent('paste',{clipboardData:d,bubbles:true,cancelable:true}));},lines.join('\n'));
    await page.waitForFunction(t=>document.querySelector('[data-terminal]').textContent.includes(t),lines.at(-1));
    await page.waitForFunction(()=>document.querySelector('[data-clock]').textContent.includes('대기 0'));
    assert(!(await page.locator('[data-terminal]').innerText()).includes('번째 줄에서 중지'));
  }
  async function open(id,project){
    await page.goto(origin+'/?exercise='+id);await page.locator('[data-command]:not(:disabled)').waitFor();
    await page.locator('[data-file]').setInputFiles({name:'configured.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(project))});
    const dialog=page.locator('[data-dialog][open]');if(await dialog.isVisible())await dialog.locator('button[value="ok"]').click();
    await page.locator('[data-command]:not(:disabled)').waitFor();await page.click('[data-action="play"]');
    await page.waitForFunction(()=>document.querySelector('[data-clock]').textContent.includes('대기 0'));
  }
  await open('l21',ipv6Lab().export());
  await cmd('n1','ping -6 2001:db8:20::10','1 transmitted, 1 received');
  await cmd('n2','ip link set dev eth1 mtu 1280');await cmd('n1','ping -6 -s 1400 2001:db8:20::10','MTU 1280');
  await cmd('n1','ping -6 -s 1200 2001:db8:20::10','1 transmitted, 1 received');
  await page.selectOption('[data-terminal-node]','n2');await page.locator('[data-ipv6] summary').click();
  assert.equal(await page.locator('[name="ipv6-0"]').inputValue(),'2001:db8:10::1/64');
  await page.locator('[name="mtu-1"]').fill('1500');await page.locator('[data-node-form] button[type="submit"]').click();
  await page.locator('[data-command]:not(:disabled)').waitFor();await cmd('n1','ping -6 -s 1400 2001:db8:20::10','1 transmitted, 1 received');
  fs.mkdirSync(out,{recursive:true});await page.screenshot({path:path.join(out,'ipv6-desktop.png'),fullPage:true});
  await cmd('n1','ip -6 addr del 2001:db8:10::10/64 dev eth0');
  await cmd('n1','ip -6 addr add 2001:db8:1234:5678:aaaa:bbbb:cccc:dddd/64 dev eth0');
  assert((await page.locator('[data-node="n1"] title').textContent()).includes('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd/64'));
  assert(await page.locator('[data-node="n1"] .nl-node-ip').evaluate(el=>el.getComputedTextLength()<=106));
  await open('l22',ospfLab().export());await cmd('n1','ping 198.51.100.10','1 transmitted, 1 received');
  await batch('n2',['enable','configure terminal','interface Gi0/1','shutdown','end']);await cmd('n2','show ip route','via 10.0.13.2');
  await cmd('n1','ping 198.51.100.10','1 transmitted, 1 received');
  await batch('n2',['configure terminal','interface Gi0/1','no shutdown','end']);await cmd('n2','show ip route','via 10.0.12.2');
  await page.screenshot({path:path.join(out,'ospf-desktop.png'),fullPage:true});
  assert(await page.locator('[data-map]').evaluate(svg=>[...svg.querySelectorAll('[data-node] rect')].every(rect=>{const r=rect.getBoundingClientRect(),b=svg.getBoundingClientRect();return r.left>=b.left&&r.right<=b.right&&r.top>=b.top&&r.bottom<=b.bottom;})));
  assert(await page.locator('[data-map]').evaluate(svg=>[...svg.querySelectorAll('.nl-link-label')].every(label=>[...svg.querySelectorAll('[data-node] rect')].every(rect=>{const a=label.getBoundingClientRect(),b=rect.getBoundingClientRect();return a.right<=b.left||a.left>=b.right||a.bottom<=b.top||a.top>=b.bottom;}))),'Port labels must not overlap devices');
  await open('l23',bgpLab().export());await batch('n2',['enable','configure terminal','router bgp 65001','neighbor 10.0.12.2 weight 200','end']);
  await cmd('n2','show ip route','via 10.0.12.2');await cmd('n1','ping 198.51.100.10','1 transmitted, 1 received');
  await batch('n4',['enable','configure terminal','router bgp 65003','no network 198.51.100.0 mask 255.255.255.0','end']);
  await cmd('n1','ping 198.51.100.10','1 transmitted, 0 received');
  await batch('n4',['configure terminal','router bgp 65003','network 198.51.100.0 mask 255.255.255.0','end']);
  await cmd('n1','ping 198.51.100.10','1 transmitted, 1 received');await cmd('n2','show ip bgp','65002 65003');
  await page.screenshot({path:path.join(out,'bgp-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(out,'bgp-mobile.png'),fullPage:true});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));assert.deepEqual(errors,[]);
  console.log('PASS actual Web Worker: IPv6 MTU GUI/CLI, OSPF failover, BGP policy/withdrawal/recovery, 390px layout.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();server.close();});
