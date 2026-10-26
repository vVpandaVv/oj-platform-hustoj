// Local-only acceptance of the shipped Ace UI and actual Pyodide Worker.
// REST persistence is intentionally in memory; this is not WordPress/DB evidence.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const assert=require('node:assert/strict'),{spawn}=require('node:child_process');
const playwright=require(process.env.OCL_PLAYWRIGHT || 'playwright');
const engine=process.env.OCL_BROWSER_ENGINE || 'firefox';
if(!['chromium','firefox','webkit'].includes(engine))throw new Error('Unsupported browser engine');
const root=path.resolve(__dirname,'..');
const port=Number(process.env.OCL_EDITOR_PORT || ({chromium:33431,firefox:33432,webkit:33433})[engine]);
const origin='http://127.0.0.1:'+port,url=origin+'/?real=1';
const report={status:'running',engine,hostPlatform:process.platform,fixture:url,authentication:'none; loopback-only memory REST fixture',storage:'in-memory revision compare-and-swap; no WordPress or database acceptance',runtime:'shipped worker-runtime.js and pyodide-worker.js; Pyodide 0.26.4 from pinned CDN',appleSafariTested:false,codeEntry:engine==='firefox'?'Playwright keyboard.type with keydown/keypress/input/keyup':'Playwright keyboard.insertText after Ace select-all',checks:[],errors:[],requestedRuntimeAssets:[],sourceSha256:{}};
const sourceFiles=['resources/js/cell-editor.js','resources/js/lab.js','resources/js/worker-runtime.js','resources/js/pyodide-worker.js','resources/css/lab.css'];
for(const file of sourceFiles)report.sourceSha256[file]=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex');
const server=spawn(process.execPath,[path.join(__dirname,'editor-browser-server.cjs')],{cwd:root,env:{...process.env,OCL_EDITOR_PORT:String(port)},windowsHide:true,stdio:['ignore','pipe','pipe']});
let browser;
function pass(label){report.checks.push(label);console.log('PASS ['+engine+'] '+label);}
const flush=p=>p.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
async function configurePage(page){page.on('pageerror',error=>report.errors.push(error.message));page.on('dialog',dialog=>dialog.accept());page.on('request',request=>{if(/pyodide|worker-runtime/.test(request.url()))report.requestedRuntimeAssets.push(request.url());});}
async function ready(page){await page.locator('.ocl-code').first().waitFor({state:'attached'});await page.locator('.ocl-cell:has(.ocl-code)').last().scrollIntoViewIfNeeded();await page.waitForFunction(()=>Array.from(document.querySelectorAll('.ocl-code')).every(node=>node.hidden));}
async function edit(page,index,code){const host=page.locator('.ocl-ace-host').nth(index);await host.scrollIntoViewIfNeeded();const input=host.locator('.ace_text-input');await input.focus();const modifier=await page.evaluate(()=>window.ace.require('ace/lib/useragent').isMac?'Meta':'Control');await page.keyboard.press(modifier+'+A');if(engine==='firefox')await page.keyboard.type(code);else await page.keyboard.insertText(code);assert.equal(await page.locator('.ocl-code').nth(index).inputValue(),code);}
async function output(page,index,text){await page.waitForFunction(({index,text})=>document.querySelectorAll('.ocl-output')[index]?.textContent.includes(text),{index,text},{timeout:100000});}
async function run(page,index,code,text){await edit(page,index,code);await page.keyboard.press('Control+Enter');await output(page,index,text);await page.waitForFunction(()=>document.querySelectorAll('.ocl-output-running').length===0);}
async function saved(page){const expected=await page.locator('.ocl-cell-input').evaluateAll(nodes=>nodes.map(node=>node.value));for(let retry=0;retry<3;retry++){const pending=page.waitForResponse(response=>response.url()===origin+'/api/notebook'&&response.request().method()==='PUT');await page.locator('[data-action="save"]').click();const response=await pending;await response.finished();if(JSON.stringify(response.request().postDataJSON().cells.map(cell=>cell.code))===JSON.stringify(expected)){await flush(page);return response;}await flush(page);}throw new Error('Current contents were not included in a save request');}
(async()=>{
 try{
  await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(new Error('Fixture startup timed out')),15000);server.stdout.once('data',()=>{clearTimeout(timer);resolve();});server.once('error',reject);server.once('exit',code=>{if(code)reject(new Error('Fixture exited '+code));});server.stderr.on('data',data=>process.stderr.write(data));});
  const options={headless:true};if(process.env.OCL_BROWSER_EXECUTABLE)options.executablePath=process.env.OCL_BROWSER_EXECUTABLE;
  browser=await playwright[engine].launch(options);report.browser=browser.version();
  const context=await browser.newContext({viewport:{width:1280,height:900}});
  const page=await context.newPage();await configurePage(page);await page.goto(url);await ready(page);
  report.capabilities=await page.evaluate(()=>({crossOriginIsolated,sharedArrayBuffer:typeof SharedArrayBuffer==='function',webAssembly:typeof WebAssembly==='object',aceVersion:window.ace.version,userAgent:navigator.userAgent}));
  assert(report.capabilities.crossOriginIsolated&&report.capabilities.sharedArrayBuffer);pass('same-origin isolation, SharedArrayBuffer and real Ace load');
  assert.equal((await page.request.get(origin+'/assets/pyodide-worker.js')).headers()['cross-origin-resource-policy'],'same-origin');pass('actual Worker response carries CORP');
  await run(page,0,'print("실제 Ace 파이썬 실행")','실제 Ace 파이썬 실행');pass('Ctrl+Enter from Ace executes actual Python and Korean output');
  await edit(page,0,'name = input("이름: ")\nprint("안녕", name)');await page.keyboard.press('Control+Enter');
  const stdin=page.locator('.ocl-input-request input');await stdin.waitFor({timeout:20000});await stdin.fill('입력 보존');
  await page.locator('.ocl-btn-insert-text').nth(1).click();await page.locator('.ocl-text').first().fill('한글 메모 <tag>\nraise Exception("TEXT_MUST_NOT_RUN")');
  assert.equal(await stdin.inputValue(),'입력 보존');pass('inserting and editing a text cell preserves active stdin');
  await stdin.fill('한글 학생');await stdin.press('Enter');await output(page,0,'안녕 한글 학생');assert((await page.locator('.ocl-output').first().innerText()).includes('이름: 한글 학생'));pass('shared-memory Korean input reaches Python and retains echo');
  await edit(page,0,'while True: pass');await page.keyboard.press('Control+Enter');await page.locator('.ocl-output-running').first().waitFor({timeout:10000});
  await page.locator('[data-action="stop"]').click({timeout:3000});await run(page,0,'print("중단 후 복구")','중단 후 복구');pass('infinite Python loop can be stopped and a fresh worker executes');
  await edit(page,0,'order = ["first"]\nprint("CODE_FIRST")');await edit(page,1,'order.append("last")\nprint("CODE_LAST", ",".join(order))');
  await page.locator('[data-action="run-all"]').click();await output(page,1,'CODE_LAST first,last');await page.waitForFunction(()=>document.querySelectorAll('.ocl-output-running').length===0);
  assert.equal(await page.locator('.ocl-output-error').count(),0);assert.equal(await page.locator('.ocl-text-cell .ocl-btn-run').count(),0);assert((await page.locator('.ocl-output').first().innerText()).includes('CODE_FIRST'));pass('Run All skips text cells and preserves shared Python execution order');
  const note='한글 메모 <tag>\nraise Exception("TEXT_MUST_NOT_RUN")';assert.equal(await page.locator('.ocl-text').first().inputValue(),note);pass('text notes remain literal after execution');
  assert.equal((await saved(page)).status(),200);
  const expected=await page.locator('.ocl-cell-input').evaluateAll(nodes=>nodes.map(node=>node.value));const expectedOutputs=await page.locator('.ocl-output').allTextContents();
  const second=await context.newPage();await configurePage(second);await second.goto(url);await ready(second);
  assert.deepEqual(await second.locator('.ocl-cell-input').evaluateAll(nodes=>nodes.map(node=>node.value)),expected);assert.deepEqual(await second.locator('.ocl-output').allTextContents(),expectedOutputs);pass('saving and opening a new tab restores mixed contents, order and outputs');
  await edit(page,0,'print("latest current tab")');assert.equal((await saved(page)).status(),200);
  await edit(second,0,'print("stale local tab")');assert.equal((await saved(second)).status(),409);pass('two tabs reject stale save with HTTP 409');
  const pendingDownload=second.waitForEvent('download');await second.locator('[data-action="download"]').click();const download=await pendingDownload;assert.equal(download.suggestedFilename(),'oj-code-lab-notebook.json');const downloaded=JSON.parse(fs.readFileSync(await download.path(),'utf8'));assert.equal(downloaded.cells[0].code,'print("stale local tab")');assert(downloaded.cells.some(cell=>cell.type==='text'&&cell.code===note));pass('conflicted mixed notebook downloads with unsaved code and notes intact');
  await Promise.all([second.waitForEvent('domcontentloaded'),second.locator('[data-action="reload"]').click()]);await ready(second);assert.equal(await second.locator('.ocl-code').first().inputValue(),'print("latest current tab")');assert.equal(await second.locator('.ocl-text').first().inputValue(),note);pass('conflict recovery reloads latest saved mixed notebook');
  assert.equal(report.errors.length,0);assert(report.requestedRuntimeAssets.some(url=>url.includes('pyodide.asm.wasm')));pass('real WASM requested and no uncaught page errors');
  report.status='passed';
 }catch(error){report.status='failed';report.failure=String(error.stack||error);console.error(report.failure);process.exitCode=1;}
 finally{
  if(browser)await browser.close();if(!server.killed)server.kill();await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));
  report.cleanup={browserClosed:true,ownedFixtureStopped:true};report.checkedAt=new Date().toISOString();report.requestedRuntimeAssets=[...new Set(report.requestedRuntimeAssets)];
  for(const file of sourceFiles)assert.equal(crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex'),report.sourceSha256[file],'Runtime changed while checking '+file);
  if(process.env.OCL_BROWSER_REPORT)fs.writeFileSync(process.env.OCL_BROWSER_REPORT,JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report));
 }
})().catch(error=>{console.error(error);process.exitCode=1;});
