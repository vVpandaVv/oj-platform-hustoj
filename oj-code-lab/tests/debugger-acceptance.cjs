// Local-only acceptance of the step debugger with the shipped Ace UI and actual Pyodide Worker.
// Uses real mouse/keyboard input through Playwright; REST persistence is the in-memory fixture.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const assert=require('node:assert/strict'),{spawn}=require('node:child_process');
const playwright=require(process.env.OCL_PLAYWRIGHT || 'playwright');
const engine=process.env.OCL_BROWSER_ENGINE || 'chromium';
if(!['chromium','firefox','webkit'].includes(engine))throw new Error('Unsupported browser engine');
const root=path.resolve(__dirname,'..');
const port=Number(process.env.OCL_EDITOR_PORT || ({chromium:33434,firefox:33435,webkit:33436})[engine]);
const origin='http://127.0.0.1:'+port,url=origin+'/?real=1';
const report={status:'running',engine,hostPlatform:process.platform,fixture:url,scope:'step debugger only; no WordPress, database or Apple Safari acceptance',checks:[],errors:[],warnings:[],sourceSha256:{}};
for(const file of ['resources/js/cell-editor.js','resources/js/lab.js','resources/js/worker-runtime.js','resources/js/pyodide-worker.js','resources/css/lab.css'])
 report.sourceSha256[file]=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex');
const server=spawn(process.execPath,[path.join(__dirname,'editor-browser-server.cjs')],{cwd:root,env:{...process.env,OCL_EDITOR_PORT:String(port)},windowsHide:true,stdio:['ignore','pipe','pipe']});
let browser;
function pass(label){report.checks.push(label);console.log('PASS ['+engine+'] '+label);}
const CODE=['def square(n):','    r = n * n','    return r','','name = input("이름: ")','total = 0','for i in range(3):','    total += square(i)','print(name, total)','x = 1 / (total - 5)'].join('\n');
(async()=>{
 try{
  await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(new Error('Fixture startup timed out')),15000);server.stdout.once('data',()=>{clearTimeout(timer);resolve();});server.once('error',reject);server.once('exit',code=>{if(code)reject(new Error('Fixture exited '+code));});server.stderr.on('data',data=>process.stderr.write(data));});
  const options={headless:true};if(process.env.OCL_BROWSER_EXECUTABLE)options.executablePath=process.env.OCL_BROWSER_EXECUTABLE;
  browser=await playwright[engine].launch(options);report.browser=browser.version();
  const page=await (await browser.newContext({viewport:{width:1280,height:900}})).newPage();
  page.on('pageerror',error=>report.errors.push(error.message));
  page.on('console',message=>{if(['warning','error'].includes(message.type()))report.warnings.push(message.type()+': '+message.text()+' ('+message.location().url+')');});
  await page.goto(url);
  const cell=page.locator('.ocl-cell:has(.ocl-ace-host)').first(),host=cell.locator('.ocl-ace-host');
  await cell.scrollIntoViewIfNeeded();await host.locator('.ace_content').waitFor();
  await host.locator('.ace_text-input').focus();await page.keyboard.press('Control+A');await page.keyboard.insertText(CODE);
  const where=()=>cell.locator('.ocl-debug-where').innerText();
  const paused=async pattern=>{
   try{await page.waitForFunction(({pattern})=>{const w=document.querySelector('.ocl-debug-where');const b=document.querySelector('.ocl-debug-over');return w&&new RegExp(pattern).test(w.textContent)&&b&&!b.disabled;},{pattern},{timeout:30000});}
   catch(error){throw new Error('Expected pause /'+pattern+'/; panel shows: '+await page.evaluate(()=>{const w=document.querySelector('.ocl-debug-where');return w?w.textContent+' | active='+(document.activeElement&&document.activeElement.className):'(no panel)';}));}
  };
  const marked=()=>page.evaluate(()=>{const e=document.querySelector('.ocl-ace-host').env.editor;const m=Object.values(e.session.getMarkers(false)).find(x=>x.clazz==='ocl-debug-line');return m?m.range.start.row+1:null;});

  await cell.locator('.ocl-btn-debug').click();await paused('1번째 줄');
  assert.equal(await marked(),1);pass('debug pauses before line 1 and Ace marks it');
  await host.locator('.ace_text-input').focus();await page.keyboard.insertText('XX');
  assert.equal(await cell.locator('.ocl-code').inputValue(),CODE);pass('cell text is read-only while debugging');

  await page.keyboard.press('F10');await paused('5번째 줄');await page.keyboard.press('F10');
  const stdin=cell.locator('.ocl-input-request input');await stdin.waitFor({timeout:20000});await stdin.fill('판다');await stdin.press('Enter');
  await paused('6번째 줄');assert.match(await cell.locator('.ocl-debug-vars').innerText(),/name\s+str \(len=2\)\s+'판다'/);
  assert.equal(await cell.locator('.ocl-debug-changed .ocl-debug-name').innerText(),'name');pass('F10 steps over, input() works while debugging and the new variable is marked');

  await page.keyboard.press('F10');await paused('7번째 줄');await page.keyboard.press('F10');await paused('8번째 줄');
  await page.keyboard.press('F11');await paused('2번째 줄.*square');
  assert.match(await cell.locator('.ocl-debug-stack').innerText(),/셀 8줄 → square\(\) 2줄/);
  assert.match(await cell.locator('.ocl-debug-vars').first().innerText(),/지역 변수 · square\(\)[\s\S]*n\s+int\s+0/);pass('F11 steps into the cell function with locals and call stack');
  await page.keyboard.press('Shift+F11');await paused('(7|8)번째 줄');assert.doesNotMatch(await where(),/square/);pass('Shift+F11 returns to the caller');

  await cell.locator('.ocl-debug-prev').click();assert.match(await where(),/지난 단계/);
  const counter=await cell.locator('.ocl-debug-counter').innerText();await cell.locator('.ocl-debug-latest').click();
  assert.notEqual(await cell.locator('.ocl-debug-counter').innerText(),counter);pass('step history moves back and returns to the latest step');

  await cell.locator('.ocl-debug-stop').click();await page.waitForFunction(()=>!document.querySelector('.ocl-debug-panel'));
  assert.equal(await marked(),null);pass('stop removes the panel and the line mark');

  // Breakpoints: real gutter click on line 2 and F9 on line 9.
  await host.locator('.ace_gutter-cell',{hasText:/^2$/}).click();
  await host.locator('.ace_text-input').focus();await page.evaluate(()=>document.querySelector('.ocl-ace-host').env.editor.gotoLine(9));await page.keyboard.press('F9');
  const breakpoints=await page.evaluate(()=>Object.keys(document.querySelector('.ocl-ace-host').env.editor.session.getBreakpoints()).map(n=>+n+1));
  assert.deepEqual(breakpoints,[2,9]);await page.waitForFunction(()=>document.querySelectorAll('.ocl-ace-host .ace_gutter-cell.ace_breakpoint').length===2,null,{timeout:5000});pass('gutter click and F9 set breakpoints');
  await cell.locator('.ocl-btn-debug').click();
  const stdin2=cell.locator('.ocl-input-request input');await stdin2.waitFor({timeout:20000});await stdin2.fill('곰');await stdin2.press('Enter');
  await paused('2번째 줄.*square');pass('debug with breakpoints runs to the first breakpoint');
  for(const expected of ['2번째 줄','2번째 줄','9번째 줄']){await cell.locator('.ocl-debug-continue').click();await paused(expected);}
  pass('continue stops at each breakpoint in order');
  await cell.locator('.ocl-debug-continue').click();
  await page.waitForFunction(()=>{const b=document.querySelector('.ocl-debug-over');return b&&b.hidden;},null,{timeout:30000});
  assert.match(await where(),/10번째 줄에서 오류/);assert.equal(await marked(),10);
  const error=await cell.locator('.ocl-output-error').innerText();
  assert.match(error,/ZeroDivisionError/);assert.doesNotMatch(error,/__ocl_debug_exec/);pass('run ends in a review of the failing line with a normal traceback');
  assert.equal(await page.evaluate(()=>document.querySelector('.ocl-ace-host').env.editor.getReadOnly()),false);

  // Dismissing the completed review must not reset the shared Python session
  // or remove input() in a later cell.
  const laterCell=page.locator('.ocl-cell:has(.ocl-ace-host)').nth(1);
  await laterCell.scrollIntoViewIfNeeded();await laterCell.locator('.ace_content').waitFor();
  const laterInput=laterCell.locator('.ace_text-input');
  await laterInput.focus();await page.keyboard.press('Control+A');
  await page.keyboard.insertText('later = input("확인: ")\nprint(name, total, later)');
  await laterCell.locator('.ocl-btn-run').click();
  const waiting=laterCell.locator('.ocl-input-request input');await waiting.waitFor({timeout:20000});await waiting.fill('유지');
  await cell.locator('.ocl-debug-prev').click();assert.notEqual(await marked(),null);
  assert.equal(await cell.locator('.ocl-debug-stop').isVisible(),true);
  await cell.locator('.ocl-debug-stop').click();await page.waitForFunction(()=>!document.querySelector('.ocl-debug-panel'));
  assert.equal(await marked(),null);assert.equal(await cell.locator('.ocl-output-error').innerText(),error);
  assert.equal(await waiting.inputValue(),'유지');await waiting.press('Enter');
  await laterCell.locator('.ocl-output').filter({hasText:'곰 5 유지'}).waitFor();
  pass('completed error review stops directly without clearing its output, variables or the next cell input');

  await laterCell.locator('.ocl-btn-debug').click();await paused('1번째 줄');
  await laterCell.locator('.ocl-debug-continue').click();
  const debugInput=laterCell.locator('.ocl-input-request input');await debugInput.waitFor();await debugInput.fill('완료');await debugInput.press('Enter');
  await laterCell.locator('.ocl-debug-where').filter({hasText:'실행이 끝났습니다'}).waitFor();
  await laterCell.locator('.ocl-debug-stop').waitFor({state:'visible'});
  await laterCell.locator('.ocl-debug-stop').click();await laterCell.locator('.ocl-debug-panel').waitFor({state:'detached'});
  await laterCell.locator('.ocl-output').filter({hasText:'곰 5 완료'}).waitFor();
  await laterInput.focus();await page.keyboard.press('Control+A');await page.keyboard.insertText('print("AFTER_DEBUG_CLOSE", name, total, later)');
  await laterCell.locator('.ocl-btn-run').click();await laterCell.locator('.ocl-output').filter({hasText:'AFTER_DEBUG_CLOSE 곰 5 완료'}).waitFor();
  pass('completed successful debug stops directly and its variables remain usable by the next run');

  await host.locator('.ace_text-input').focus();await page.keyboard.press('Control+Home');await page.keyboard.insertText('# 수정\n');
  await page.waitForFunction(()=>!document.querySelector('.ocl-debug-panel'));
  assert.deepEqual(await page.evaluate(()=>Object.keys(document.querySelector('.ocl-ace-host').env.editor.session.getBreakpoints()).map(n=>+n+1)),[3,10]);
  pass('breakpoints still follow edited lines after closing the review');

  await cell.locator('.ocl-btn-run').click();await page.waitForFunction(()=>/곰|ZeroDivisionError|EOFError|이름/.test(document.querySelector('.ocl-output').textContent),null,{timeout:30000});
  const stdin3=cell.locator('.ocl-input-request input');await stdin3.waitFor({timeout:20000});await stdin3.fill('끝');await stdin3.press('Enter');
  await page.waitForFunction(()=>document.querySelector('.ocl-output').textContent.includes('끝 5'),null,{timeout:30000});
  assert.equal(await cell.locator('.ocl-debug-panel').count(),0);pass('normal run is unaffected by breakpoints');
  assert.deepEqual(report.errors,[]);assert.deepEqual(report.warnings,[]);pass('no page errors and no console warnings');
  report.status='passed';
 }catch(error){report.status='failed';report.failure=String(error&&error.stack||error);process.exitCode=1;}
 finally{
  if(browser)await browser.close();server.kill();
  report.checkedAt=new Date().toISOString();console.log(JSON.stringify(report));
 }
})();
