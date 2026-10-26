const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
(async()=>{
  const root=path.resolve(__dirname,'../..');
  const output=execFileSync('D:/php/php.exe',['tests/Unit/open-source.php','--render'],{cwd:root,encoding:'utf8'});
  const html=output.slice(output.indexOf('<article>'));
  const browser=await chromium.launch({headless:true,channel:'chrome'});
  try {
    const page=await browser.newPage({viewport:{width:1280,height:900}});
    await page.route('**/*',route=>route.abort());
    await page.setContent('<!doctype html><html lang=ko><meta charset=UTF-8><meta name=viewport content="width=device-width,initial-scale=1"><style>'+fs.readFileSync(path.join(root,'resources/ui/css/oj-ui-system.css'),'utf8')+'</style><body class=ojp-app><main class=ojp-shell>'+html+'</main></body></html>');
    assert.equal(await page.locator('tbody tr').count(),10);
    await page.locator('details summary').first().click();
    assert.equal(await page.locator('details').first().getAttribute('open'),'');
    await page.screenshot({path:path.resolve(root,'../../_notes/tmp/open-source-desktop.png')});
    await page.setViewportSize({width:390,height:844});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    await page.screenshot({path:path.resolve(root,'../../_notes/tmp/open-source-mobile.png')});
    console.log('Public license page: inventory, full notice expansion and mobile containment passed.');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
