const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
  const root = path.resolve(__dirname, '../..');
  const html = execFileSync('D:/php/php.exe', ['-r', 'require "src/Kernel/Autoloader.php"; CodingPanda\\OjPlatform\\Kernel\\Autoloader::register(getcwd()); require "tests/Support/wordpress-stubs.php"; $e=new CodingPanda\\OjPlatform\\Ui\\Editor\\RichTextEditor(); echo "<form id=agreement>".$e->render("content","동의 문서","<h2>개인정보 처리방침</h2><p>기존 본문</p>",16,true)."<button type=submit>저장</button></form><form id=problem>".$e->render("description","문제 설명","Hello",12,true,true,new CodingPanda\\OjPlatform\\Ui\\Editor\\RichTextImageUpload("/upload","<input name=action value=image>")).$e->render("input","입력","",8).$e->render("output","출력","",8).$e->render("hint","힌트","",8)."</form><form id=board>".$e->render("body","게시판 본문","첫 줄\n둘째 줄",12,true)."</form>";'], {cwd:root,encoding:'utf8'});
  const browser = await chromium.launch({headless:true,channel:'chrome'});
  try {
    const page = await browser.newPage({viewport:{width:1280,height:900}});
    page.setDefaultTimeout(5000);
    const failures=[];
    page.on('pageerror', e=>failures.push(e.message));
    await page.route('**/*', async route => {
      const url=new URL(route.request().url());
      if(url.hostname!=='editor.test') throw new Error('Unexpected external request: '+url);
      if(url.pathname==='/upload') return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,url:'http://editor.test/image.png'})});
      if(url.pathname.startsWith('/resources/')) {
        const file=path.resolve(root,'.'+url.pathname);
        assert(file.startsWith(root+path.sep));
        return route.fulfill({contentType:file.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(file)});
      }
      return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang=ko><meta charset=UTF-8><meta name=viewport content="width=device-width,initial-scale=1"><link rel=stylesheet href=/resources/ui/css/oj-ui-system.css><body class=ojp-app><main class=ojp-shell>'+html+'</main><script src=/resources/ui/js/oj-ui.js defer></script></body></html>'});
    });
    await page.goto('http://editor.test/');
    await page.waitForFunction(()=>document.querySelectorAll('.jodit-container').length===6);
    assert.equal(await page.locator('[data-ojp-rich-text-editor]').count(),6);
    const spacing=await page.evaluate(()=>{
      const editor=document.querySelector('#agreement [data-ojp-rich-text-editor]').ojpEditor;
      const html='<h2>처리방침</h2>\n<p>첫 문단<br>둘째 줄</p>\n<p>다음 문단</p>\n<ul><li>항목</li></ul>';
      editor.value=html;
      const publicBody=document.createElement('div'); publicBody.className='ojp-policy-content ojp-prose'; publicBody.innerHTML=html; document.body.appendChild(publicBody);
      const properties=['fontSize','lineHeight','marginTop','marginBottom','whiteSpace'];
      const result=['h2','p','ul','li'].map(tag=>{
        const a=getComputedStyle(editor.editor.querySelector(tag)); const b=getComputedStyle(publicBody.querySelector(tag));
        return properties.map(key=>[tag,key,a[key],b[key]]);
      }).flat();
      publicBody.remove(); return result;
    });
    spacing.forEach(([tag,key,a,b])=>assert.equal(a,b,tag+' '+key+' must match the public document'));
    const getValue=()=>page.evaluate(()=>document.querySelector('#agreement [data-ojp-rich-text-editor]').ojpEditor.value);
    await page.evaluate(()=>{
      const e=document.querySelector('#agreement [data-ojp-rich-text-editor]').ojpEditor;
      e.value='<h2>Privacy</h2><table><tbody><tr><th colspan="2">Data</th></tr><tr><td rowspan="2">Required</td><td>Name</td></tr><tr><td>Email</td></tr></tbody></table>';
    });
    assert((await getValue()).includes('rowspan="2"'));
    await page.locator('#agreement .jodit-toolbar-button_source button').first().click();
    const source=page.locator('#agreement .jodit-source__mirror');
    await source.waitFor({state:'visible'});
    await source.fill('<h2>Updated</h2><p style="font-size:20px">Text</p><table><tr><td colspan="2">Cells</td></tr></table><script>window.injected=true</script><img src="x" onerror="window.injected=true"><a href="javascript:alert(1)">Unsafe</a>');
    await source.dispatchEvent('input');
    await page.locator('#agreement .jodit-toolbar-button_source button').first().click();
    await page.waitForTimeout(400);
    assert.equal(await page.evaluate(()=>Boolean(window.injected)),false);
    const value=await getValue();
    assert(!/<script|onerror=|javascript:/i.test(value));
    assert(value.includes('colspan="2"'));
    assert(value.includes('font-size'));
    await page.evaluate(()=>{
      const e=document.querySelector('#agreement [data-ojp-rich-text-editor]').ojpEditor;
      e.value='<p>First</p>'; e.history.snapshot.make();
      e.s.focus(); e.s.insertHTML('<p>Second</p>'); e.history.snapshot.make();
      e.history.undo();
    });
    assert(!(await getValue()).includes('Second'));
    await page.evaluate(()=>document.querySelector('#agreement [data-ojp-rich-text-editor]').ojpEditor.history.redo());
    assert((await getValue()).includes('Second'));
    await page.locator('#agreement .jodit-toolbar-button_table button').first().click();
    assert(await page.locator('.jodit-popup:visible').count()>0);
    await page.locator('.jodit-popup:visible [role=gridcell]').nth(11).click();
    assert.equal(await page.locator('#agreement .jodit-wysiwyg table tr').count(),2);
    assert.equal(await page.locator('#agreement .jodit-wysiwyg table td').count(),4);
    await page.evaluate(()=>{
      const e=document.querySelector('#agreement [data-ojp-rich-text-editor]').ojpEditor;
      const t=e.editor.querySelector('table'); const api=e.getInstance('Table',e.o);
      api.addSelection(t.rows[0].cells[0]); api.addSelection(t.rows[0].cells[1]); api.mergeSelected(t);
    });
    assert.equal(await page.locator('#agreement .jodit-wysiwyg td[colspan="2"]').count(),1);
    const tableEdits=await page.evaluate(()=>{
      const e=document.querySelector('#agreement [data-ojp-rich-text-editor]').ojpEditor;
      const t=e.editor.querySelector('table'); const api=e.getInstance('Table',e.o);
      api.getAllSelectedCells().forEach(c=>api.removeSelection(c)); api.addSelection(t.rows[0].cells[0]); api.splitHorizontal(t);
      return t.querySelectorAll('td').length;
    });
    assert(tableEdits>3);
    const rowEdits=await page.evaluate(()=>{
      const e=document.querySelector('#agreement [data-ojp-rich-text-editor]').ojpEditor;
      e.value='<table><tbody><tr><td>A</td><td>B</td></tr><tr><td>C</td><td>D</td></tr></tbody></table>';
      const t=e.editor.querySelector('table'); const api=e.getInstance('Table',e.o);
      api.appendRow(t,t.rows[0],true); const added=t.rows.length; api.removeRow(t,1);
      return {added,removed:t.rows.length};
    });
    assert.equal(rowEdits.added,3); assert.equal(rowEdits.removed,2);
    await page.keyboard.press('Escape');
    await page.screenshot({path:path.resolve(root,'../../_notes/tmp/common-editor-desktop.png')});
    await page.setViewportSize({width:390,height:844});
    await page.waitForTimeout(300);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    await page.screenshot({path:path.resolve(root,'../../_notes/tmp/common-editor-mobile.png')});
    await page.locator('#problem .jodit-toolbar-button_dots button').first().click();
    await page.screenshot({path:path.resolve(root,'../../_notes/tmp/common-editor-more.png')});
    assert.equal(await page.locator('.jodit-toolbar-button_ojpLatex button:visible').count(),1);
    await page.locator('#agreement .jodit-toolbar-button_source button').first().click();
    await source.fill('<p>Saved in HTML mode</p>'); await source.dispatchEvent('input');
    const submitted=await page.evaluate(()=>{
      const f=document.querySelector('#agreement');
      const event=new Event('submit',{cancelable:true}); f.dispatchEvent(event);
      return {prevented:event.defaultPrevented,body:new FormData(f).get('content')};
    });
    assert(!submitted.prevented); assert(submitted.body.includes('Saved in HTML mode'));
    await page.setViewportSize({width:1280,height:900});
    await page.locator('#problem .jodit-toolbar-button_ojpLatex button').first().click();
    const dialog=page.locator('[role=dialog]');
    await dialog.locator('input').fill('x^2');
    await dialog.locator('button').filter({hasText:/OK|확인/}).click();
    assert((await page.evaluate(()=>document.querySelector('#problem [data-ojp-rich-text-editor]').ojpEditor.value)).includes('x^2'));
    const chooserPromise=page.waitForEvent('filechooser');
    await page.locator('#problem .jodit-toolbar-button_ojpImage button').first().click();
    const chooser=await chooserPromise;
    await chooser.setFiles({name:'pixel.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jp1sAAAAASUVORK5CYII=','base64')});
    await page.waitForFunction(()=>document.querySelector('#problem .jodit-wysiwyg img[src="http://editor.test/image.png"]'));
    const rejected=await page.evaluate(()=>{
      const r=document.querySelector('#board [data-ojp-rich-text-editor]'); r.ojpEditor.value='<p><br></p>';
      const event=new Event('submit',{cancelable:true}); document.querySelector('#board').dispatchEvent(event);
      return event.defaultPrevented;
    });
    assert.equal(rejected,true);
    assert.equal(failures.length,0,failures.join('\n'));
    console.log('Common editor: six fields, source roundtrip, tables/spans, XSS filtering, undo/redo, responsive toolbar and HTML-mode submit passed.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
