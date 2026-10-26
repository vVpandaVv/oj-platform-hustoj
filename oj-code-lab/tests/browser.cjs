const playwright = require(process.env.OCL_PLAYWRIGHT || 'playwright');
const engine = process.env.OCL_BROWSER_ENGINE || 'chromium';
if (!['chromium','firefox','webkit'].includes(engine)) throw new Error('Unsupported browser engine');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const url = 'http://127.0.0.1:33369/oj-addons/oj-code-lab/lab';
(async () => {
  const browser = await playwright[engine].launch({headless:true});
  const context = await browser.newContext({viewport:{width:1280,height:900}});
  const page = await context.newPage();
  const errors = [], completedChecks=[]; let checks = 0, failure=null, capabilities=null;
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if(m.type()==='error') console.log('CONSOLE '+m.text()); });
  page.on('dialog', d => d.accept());
  async function pass(label) { checks++; completedChecks.push(label); console.log('PASS '+label); }
  const status = p => p.locator('[data-field="status"]');
  async function run(code, expected) {
    await page.locator('.ocl-code').first().fill(code);
    await page.locator('.ocl-btn-run').first().click();
    await page.waitForFunction(s => document.querySelector('.ocl-output').textContent.includes(s), expected, {timeout:90000});
  }
  async function save(p) {
    const expected=await p.locator('.ocl-code').evaluateAll(nodes=>nodes.map(node=>node.value));
    for(let attempt=0;attempt<3;attempt++) {
      const pending=p.waitForResponse(r=>r.url().endsWith('/notebook') && r.request().method()==='PUT');
      await p.locator('[data-action="save"]').click();
      const response=await pending; await response.finished();
      const sent=response.request().postDataJSON().cells.map(cell=>cell.code);
      if(JSON.stringify(sent)===JSON.stringify(expected)) return response;
      // A prior autosave can already be in flight when the button is clicked.
      // Let its completion reach the UI, then request the current edit snapshot.
      await p.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    }
    throw new Error('The current notebook snapshot was never sent for saving');
  }
  try {
    await page.goto(url);
    await page.locator('.ocl-code').first().waitFor();
    while(await page.locator('.ocl-code').count()>1) await page.locator('.ocl-cell-toolbar .ocl-btn-danger').last().click();
    capabilities=await page.evaluate(() => ({crossOriginIsolated,sharedArrayBuffer:typeof SharedArrayBuffer !== 'undefined',webAssembly:typeof WebAssembly !== 'undefined'}));
    assert(capabilities.crossOriginIsolated); await pass('real page and worker isolation headers');
    await run('print("실제 Python 실행 성공")', '실제 Python 실행 성공'); await pass('Pyodide 0.26.4 executes Korean output');
    await run('name = input("이름: ")\nprint("안녕", name)', '이름:');
    await page.locator('.ocl-input-request input').fill('학생'); await page.locator('.ocl-input-request input').press('Enter');
    await page.waitForFunction(() => document.querySelector('.ocl-output').textContent.includes('안녕 학생')); await pass('SharedArrayBuffer input returns Korean text');
    await run('value = input("보존: ")\nprint("입력 보존", value)', '보존:');
    await page.locator('[data-action="add-cell"]').click();
    await page.locator('.ocl-cell-toolbar .ocl-btn-danger').last().click();
    await page.locator('.ocl-input-request input').fill('확인'); await page.locator('.ocl-input-request input').press('Enter');
    await page.waitForFunction(() => document.querySelector('.ocl-output').textContent.includes('입력 보존 확인')); await pass('cell add and other-cell deletion preserve active stdin');
    await page.locator('[data-action="add-cell"]').click();
    await page.locator('.ocl-code').last().fill('input("삭제할 입력: ")'); await page.locator('.ocl-btn-run').last().click();
    await page.locator('.ocl-input-request').waitFor(); await page.locator('.ocl-cell-toolbar .ocl-btn-danger').last().click();
    await run('print("입력 삭제 후 정상")','입력 삭제 후 정상'); await pass('deleting the input cell releases Worker stdin');
    // Only the remote dataset response is a fixture: the Worker, package import,
    // Python module lookup and read_csv below execute in the actual WASM runtime.
    await page.route('**/datasets/recommended',route=>route.fulfill({json:[{id:987,label:'module-name regression',source:'upload'}]}));
    await page.route('**/datasets/recommended/987/import',route=>route.fulfill({json:{fileName:'pandas.py',content:'__import__("builtins").print("DATA_FILE_EXECUTED")\n'}}));
    await page.locator('[data-action="toggle-datasets"]').click();
    await page.locator('[data-field="recommended-list"] button').first().click();
    await page.waitForFunction(() => [...document.querySelectorAll('.ocl-code')].some(e=>e.value.includes('/home/pyodide/ocl-datasets/pandas.py')),null,{timeout:90000});
    const importedCode=await page.locator('.ocl-code').last().inputValue();
    await page.locator('.ocl-code').last().fill(importedCode+'\nprint("SAFE_DATA_IMPORT", pd.__file__, df.shape)');
    await page.locator('.ocl-btn-run').last().click();
    await page.waitForFunction(() => [...document.querySelectorAll('.ocl-output')].some(e=>e.textContent.includes('SAFE_DATA_IMPORT')),null,{timeout:120000});
    assert(!((await page.locator('[data-field="cells"]').textContent()).includes('DATA_FILE_EXECUTED')));
    assert.equal(await page.locator('.ocl-output-error').count(),0); await pass('pandas.py dataset cannot replace pandas during automatic import');
    await page.locator('.ocl-cell-toolbar .ocl-btn-danger').last().click();
    await page.unroute('**/datasets/recommended');await page.unroute('**/datasets/recommended/987/import');
    await page.locator('.ocl-code').first().fill('while True: pass'); await page.locator('.ocl-btn-run').first().click();
    await page.locator('.ocl-output-running').waitFor();
    await page.locator('[data-action="stop"]').click({timeout:3000});
    await run('print("중단 후 복구")', '중단 후 복구'); await pass('infinite Python loop stops and fresh worker runs');
    await run('import matplotlib.pyplot as plt\nplt.plot([1,2,3])\nplt.show()', '');
    await page.waitForFunction(() => document.querySelector('.ocl-output img') || document.querySelector('.ocl-output-error'), null, {timeout:120000});
    assert.equal(await page.locator('.ocl-output-error').count(),0,await page.locator('.ocl-output').first().textContent());
    await page.locator('.ocl-output img').waitFor({timeout:5000}); await pass('matplotlib plot renders through actual WASM');
    await page.locator('[data-action="restart"]').click();
    await run('print("재시작 성공")', '재시작 성공'); await pass('runtime restart creates a working interpreter');
    await page.locator('.ocl-code').first().fill('sequence = [1]');
    await page.locator('[data-action="add-cell"]').click(); await page.locator('.ocl-code').nth(1).fill('sequence.append(2)\nprint(sequence)');
    await page.locator('[data-action="run-all"]').click();
    await page.waitForFunction(() => [...document.querySelectorAll('.ocl-output')].some(e=>e.textContent.includes('[1, 2]'))); await pass('run-all observes ordered shared state');
    await page.waitForFunction(() => document.querySelectorAll('.ocl-output-running').length===0);
    assert.equal(await page.locator('.ocl-output-running').count(),0); await pass('silent completed cells clear their running indicators');
    assert.equal((await save(page)).status(),200);
    const second = await context.newPage(); second.on('dialog', d=>d.accept()); await second.goto(url); await second.locator('.ocl-code').first().waitFor();
    assert.equal(await second.locator('.ocl-code').first().inputValue(),'sequence = [1]'); await pass('saved notebook reloads in another tab');
    await page.locator('.ocl-code').first().fill('print("current tab")'); assert.equal((await save(page)).status(),200);
    await second.locator('.ocl-code').first().fill('print("stale tab")'); assert.equal((await save(second)).status(),409); await pass('two real tabs reject stale save with HTTP 409');
    const downloadPromise = second.waitForEvent('download'); await second.locator('[data-action="download"]').click();
    const download = await downloadPromise; assert.equal(download.suggestedFilename(),'oj-code-lab-notebook.json'); await pass('conflicted notebook can be downloaded');
    await Promise.all([second.waitForEvent('domcontentloaded'),second.locator('[data-action="reload"]').click()]);
    await second.locator('.ocl-code').first().waitFor();
    assert.equal(await second.locator('.ocl-code').first().inputValue(),'print("current tab")'); await pass('conflict recovery reloads latest server contents');
    assert.deepEqual(errors,[]); await pass('no uncaught page errors');
    if(process.env.OCL_BROWSER_SCREENSHOT) await page.screenshot({path:process.env.OCL_BROWSER_SCREENSHOT,fullPage:true});
  } catch(e) {
    failure=String(e.message||e); console.error('STATUS: '+await status(page).textContent().catch(()=>''));
    console.error(await page.locator('.ocl-output').first().textContent().catch(()=>'')); console.error(e); process.exitCode=1;
  } finally {
    const report={status:failure?'failed':'passed',engine,checks,completedChecks,browser:browser.version(),hostPlatform:process.platform,capabilities,pyodide:'0.26.4',authentication:'local fixture; real WordPress REST/capabilities/nonce',datasetImport:'fixture CSV response; real Worker, pandas and Python module lookup',errors,failure};
    if(process.env.OCL_BROWSER_REPORT) fs.writeFileSync(process.env.OCL_BROWSER_REPORT,JSON.stringify(report,null,2)+'\n');
    console.log(JSON.stringify(report)); await browser.close();
  }
})();
