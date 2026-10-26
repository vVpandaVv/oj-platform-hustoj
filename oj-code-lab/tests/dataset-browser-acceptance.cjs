// Actual Chromium, Ace, pandas and Pyodide; REST responses are local memory fixtures.
// ZIP extraction is covered independently by tests/dataset-archive.php.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),net=require('node:net');
const assert=require('node:assert/strict'),{spawn}=require('node:child_process');
const playwright=require(process.env.OCL_PLAYWRIGHT || 'playwright');
const root=path.resolve(__dirname,'..'),port=Number(process.env.OCL_EDITOR_PORT || 33434),origin='http://127.0.0.1:'+port;
const sourceFiles=['resources/js/cell-editor.js','resources/js/lab.js','resources/js/worker-runtime.js','resources/js/pyodide-worker.js','resources/css/lab.css'];
const hash=file=>crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex');
const report={status:'running',engine:'chromium',hostPlatform:process.platform,fixture:origin+'/?real=1',scope:'Actual Ace/Pyodide/pandas in a headless browser with loopback memory REST. No WordPress, database, Kaggle network request, or production access. ZIP response is already normalized; real decompression and hostile ZIP boundaries are covered by the separate PHP suite.',checks:[],errors:[],requestedRuntimeAssets:[],imports:[],sourceSha256:Object.fromEntries(sourceFiles.map(file=>[file,hash(file)])),fixtureSha256:{'editor-browser-server.cjs':hash('tests/editor-browser-server.cjs'),'dataset-browser-acceptance.cjs':hash('tests/dataset-browser-acceptance.cjs')}};
let browser,server;
function pass(name){report.checks.push(name);console.log('PASS '+name);}
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function portClosed(){return new Promise(resolve=>{const socket=net.createConnection({host:'127.0.0.1',port});socket.once('connect',()=>{socket.destroy();resolve(false);});socket.once('error',()=>resolve(true));});}
async function edit(page,index,code){
 // Lazy Ace hosts are hidden until their visible cell enters the viewport.
 await page.locator('.ocl-cell:has(.ocl-code)').nth(index).scrollIntoViewIfNeeded();
 const host=page.locator('.ocl-ace-host').nth(index);const input=host.locator('.ace_text-input');await input.waitFor({state:'attached'});await input.focus();await page.keyboard.press('Control+A');await page.keyboard.insertText(code);
 assert.equal(await page.locator('.ocl-code').nth(index).inputValue(),code);
}
async function probe(page,code,expected){
 await page.locator('[data-action="add-cell"]').click();const index=await page.locator('.ocl-code').count()-1;await edit(page,index,code);await page.keyboard.press('Control+Enter');
 await page.waitForFunction(({index,expected})=>document.querySelectorAll('.ocl-output')[index]?.textContent.includes(expected),{index,expected},{timeout:120000});
 await page.waitForFunction(()=>document.querySelectorAll('.ocl-output-running').length===0);
 assert.equal(await page.locator('.ocl-output-error').count(),0);return index;
}
async function files(page){
 const panel=page.locator('[data-field="datasets-panel"]');if(!await panel.isVisible())await page.locator('[data-action="toggle-datasets"]').click();
 await page.locator('[data-field="search-input"]').fill('형식 실습');await page.locator('[data-field="search-input"]').press('Enter');
 await page.locator('[data-field="search-results"]').getByRole('button',{name:'파일 보기',exact:true}).click();await page.locator('[data-field="files-list"] strong').last().waitFor();
}
function row(page,file){return page.locator('[data-field="files-list"] .ocl-dataset-row').filter({has:page.getByText(file,{exact:true})});}
async function importFile(page,file){
 await files(page);const before=await page.locator('.ocl-code').count();const response=page.waitForResponse(item=>item.url()===origin+'/api/datasets/import'&&item.request().method()==='POST');
 await row(page,file).getByRole('button').click();const reply=await response;assert.equal(reply.status(),200);report.imports.push({requested:reply.request().postDataJSON(),normalized:await reply.json()});
 await page.waitForFunction(count=>document.querySelectorAll('.ocl-code').length===count+1,before);
 const code=await page.locator('.ocl-code').last().inputValue();assert(code.endsWith('print(df.head())'),'Import should display a preview immediately');
 await page.waitForFunction(index=>document.querySelectorAll('.ocl-output')[index]?.textContent.includes('코딩판다'),before,{timeout:120000});
 await page.waitForFunction(()=>document.querySelectorAll('.ocl-output-running').length===0);assert.equal(await page.locator('.ocl-output-error').count(),0);return code;
}
async function contents(page){return page.locator('.ocl-cell').evaluateAll(nodes=>nodes.map(node=>({id:node.dataset.cellId,body:node.querySelector('.ocl-cell-input').value,output:node.querySelector('.ocl-output')?.textContent||''})));}
(async()=>{
 try{
  assert(await portClosed(),'Dedicated loopback port is already in use; existing process untouched');
  server=spawn(process.execPath,[path.join(__dirname,'editor-browser-server.cjs')],{cwd:root,env:{...process.env,OCL_EDITOR_PORT:String(port)},windowsHide:true,stdio:['ignore','pipe','pipe']});
  await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(new Error('Fixture startup timed out')),15000);server.stdout.once('data',()=>{clearTimeout(timer);resolve();});server.once('error',reject);server.once('exit',code=>{if(code)reject(new Error('Fixture exited '+code));});server.stderr.on('data',data=>process.stderr.write(data));});
  browser=await playwright.chromium.launch({headless:true,...(process.env.OCL_BROWSER_EXECUTABLE?{executablePath:process.env.OCL_BROWSER_EXECUTABLE}:{})});report.browser=browser.version();
  const context=await browser.newContext({viewport:{width:1280,height:900}}),page=await context.newPage();
  page.on('pageerror',error=>report.errors.push(error.message));page.on('dialog',dialog=>dialog.accept());page.on('request',request=>{if(/pyodide|worker-runtime/.test(request.url()))report.requestedRuntimeAssets.push(request.url());});
  await page.goto(origin+'/?real=1');await page.locator('.ocl-code').first().waitFor({state:'attached'});await page.waitForFunction(()=>window.ace&&document.querySelector('.ace_text-input'));
  report.capabilities=await page.evaluate(()=>({crossOriginIsolated,sharedArrayBuffer:typeof SharedArrayBuffer==='function',aceVersion:window.ace.version,userAgent:navigator.userAgent}));assert(report.capabilities.crossOriginIsolated&&report.capabilities.sharedArrayBuffer);pass('actual Ace and isolated SharedArrayBuffer runtime initialize');
  const original=await contents(page),note=await page.locator('.ocl-text').inputValue();
  await files(page);assert.equal(await row(page,'report.h5').getByRole('button').isDisabled(),true);assert.equal(await row(page,'archive.zip').getByRole('button').textContent(),'ZIP 가져오기');pass('search and file listing expose JSONL/CSV/ZIP and disable unsupported H5');
  const jsonlCode=await importFile(page,'train/data.jsonl');assert(jsonlCode.includes('pd.read_json("/home/pyodide/ocl-datasets/data.jsonl", lines=True)'));pass('nested JSONL file import creates read_json(lines=True) and automatically prints head');
  await probe(page,'assert len(df) == 3\nassert df.iloc[0]["name"] == "코딩판다"\nassert df.iloc[0]["meta"]["tags"] == ["한글", "Python"]\nassert df.iloc[2]["score"] == 11\njsonl_df = df.copy(deep=True)\nprint("JSONL_ROWS", len(df))\nprint(df.head().to_string(index=False))','JSONL_ROWS 3');pass('real pandas retains three JSONL rows, Korean strings, nested dictionaries and lists');
  const csvCode=await importFile(page,'samples.csv');assert(csvCode.includes('pd.read_csv("/home/pyodide/ocl-datasets/samples.csv")'));assert(!csvCode.includes('read_json'));pass('CSV import still generates read_csv and automatically prints head');
  await probe(page,'assert len(df) == 3\nassert list(df.columns) == ["name", "score"]\nassert df.iloc[1]["name"] == "학생 둘"\nassert int(df["score"].sum()) == 21\nassert jsonl_df.iloc[0]["meta"]["topic"] == "반복문"\nprint("CSV_ROWS", len(df))\nprint(df.head().to_string(index=False))','CSV_ROWS 3');pass('actual CSV parsing retains Korean values and shared Python state');
  const zipCode=await importFile(page,'archive.zip');assert(zipCode.includes('from_zip.jsonl'));assert(!zipCode.includes('archive.zip'));assert(zipCode.includes('lines=True'));pass('normalized ZIP response uses extracted JSONL filename and matching pandas reader');
  await probe(page,'assert len(df) == 3\nassert df.iloc[0]["meta"]["topic"] == "반복문"\nprint("ZIP_NORMALIZED_ROWS", len(df))','ZIP_NORMALIZED_ROWS 3');pass('actual pandas executes already-normalized ZIP JSONL response');
  for(const [file,message] of [['broken.jsonl','JSONL 2행'],['invalid-response.jsonl','데이터 응답 형식']]){
   await files(page);const before=await contents(page);const response=page.waitForResponse(item=>item.url()===origin+'/api/datasets/import'&&item.request().method()==='POST');await row(page,file).getByRole('button').click();await response;
   await page.waitForFunction(message=>document.querySelector('[data-field="status"]').textContent.includes(message),message);assert.deepEqual(await contents(page),before);pass(file==='broken.jsonl'?'server validation error leaves all code, notes and outputs unchanged':'malformed normalized response adds no cell and preserves the notebook');
  }
  assert.deepEqual((await contents(page)).slice(0,original.length),original);assert.equal(await page.locator('.ocl-text').inputValue(),note);pass('all imports preserve existing code cells and literal text note');
  await probe(page,'import pandas, sys\nprint("VERSIONS", pandas.__version__, sys.version.split()[0])','VERSIONS');report.pythonVersions=await page.locator('.ocl-output').last().innerText();
  const save=page.waitForResponse(item=>item.url()===origin+'/api/notebook'&&item.request().method()==='PUT');await page.locator('[data-action="save"]').click();assert.equal((await save).status(),200);const saved=await contents(page);await page.reload();await page.locator('.ocl-code').first().waitFor({state:'attached'});assert.deepEqual(await contents(page),saved);pass('memory REST save and reload preserve generated readers, notes and previews');
  assert.equal(report.errors.length,0);assert(report.requestedRuntimeAssets.some(url=>url.includes('pyodide.asm.wasm')));assert(report.requestedRuntimeAssets.some(url=>url.includes('pandas-')));pass('actual WASM and pandas assets load with no uncaught page errors');
  report.status='passed';
 }catch(error){report.status='failed';report.failure=String(error.stack||error);console.error(report.failure);process.exitCode=1;}
 finally{
  if(browser)await browser.close();if(server&&!server.killed)server.kill();if(server)await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));
  await delay(50);report.cleanup={browserClosed:true,ownedFixtureStopped:true,portClosed:await portClosed()};report.checkedAt=new Date().toISOString();report.requestedRuntimeAssets=[...new Set(report.requestedRuntimeAssets)];
  report.sourceUnchanged=Object.fromEntries(sourceFiles.map(file=>[file,hash(file)===report.sourceSha256[file]]));if(!Object.values(report.sourceUnchanged).every(Boolean)){report.status='failed';report.failure='Runtime source changed during execution';process.exitCode=1;}
  assert(report.cleanup.portClosed);if(process.env.OCL_BROWSER_REPORT)fs.writeFileSync(process.env.OCL_BROWSER_REPORT,JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report));
 }
})().catch(error=>{console.error(error);process.exitCode=1;});
