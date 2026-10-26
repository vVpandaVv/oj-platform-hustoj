const fs=require('fs'), path=require('path'), assert=require('node:assert/strict');
const {chromium}=require('playwright');
process.chdir(path.resolve(__dirname, '../../../..'));
const {execFileSync}=require('node:child_process');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 const page=await browser.newPage();
 const css=fs.readFileSync('oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css','utf8');
 const js=fs.readFileSync('oj-integration-rebuild/oj-platform/resources/ui/js/oj-ui.js','utf8');
 for(const [file,id,commandId] of [['teaching-tools/src/Addon/Settings_Page_Handler.php','htt-server-install','htt-install-command'],['oj-code-arena/src/Addon/Settings_Page_Handler.php','oca-server-install','oca-install-command']]){
  const php=fs.readFileSync(file,'utf8');
  let dialog=php.match(/<dialog[\s\S]*?<\/dialog>/)[0];
  dialog=dialog.replace(/<\?php if \(null !== \$install_command\) : \?>/g,'').replace(/<\?php else : \?>[\s\S]*?<\?php endif; \?>/g,'').replace(/<\?php echo esc_textarea\([^]*?\); \?>/g,"sudo bash '/server/install.sh' 'secret'").replace(/<\?php[\s\S]*?\?>/g,'');
  await page.setContent(`<html lang="ko"><style>${css}</style><body class="ojp-shell"><button type="button" style="display:none" hidden data-ojp-server-open="${id}">서버 설치·복구</button>${dialog}</body></html>`);
  await page.addScriptTag({content:js});
  await page.getByText('서버 설치·복구',{exact:true}).click();
  assert(await page.locator('#'+id).evaluate(x=>x.open));
  const value=await page.locator('#'+commandId).inputValue();assert(value.includes('sudo bash'));
  // Test rejected/unavailable Clipboard API fallback inside the modal focus trap.
  await page.evaluate(()=>{window.__copied='';document.execCommand=(c)=>{window.__copied=document.activeElement.value;return c==='copy';};});
  await page.locator(`[data-ojp-copy-target="${commandId}"]`).click();
  assert.equal(await page.evaluate(()=>window.__copied),value);
  assert.equal(await page.locator(`[data-ojp-copy-target="${commandId}"]`).textContent(),'복사됨');
  await page.keyboard.press('Escape');assert.equal(await page.locator('#'+id).evaluate(x=>x.open),false);
  assert.equal(await page.evaluate(()=>document.activeElement.getAttribute('data-ojp-server-open')),id);
  await page.setViewportSize({width:390,height:844});await page.getByText('서버 설치·복구',{exact:true}).click();
  const box=await page.locator('#'+id).boundingBox();assert(box.x>=0&&box.x+box.width<=391,'Mobile modal overflow');
  await page.locator('[data-ojp-server-close]').click();assert.equal(await page.locator('#'+id).evaluate(x=>x.open),false);
  console.log(id+': open, copy fallback, Escape, focus return, mobile width and close passed');
 }
 const raw=execFileSync(process.env.PHP_BINARY||'php',['-r',"require 'oj-integration-rebuild/oj-platform/tests/Unit/server-preparation.php'; echo $html;"],{encoding:'utf8'});
 const common=raw.slice(raw.indexOf('<div'));
 await page.setContent(`<style>${css}</style>${common}`);await page.addScriptTag({content:js});
 assert.equal(await page.locator('section.ojp-section').count(),5);
 assert(await page.locator('#server-command-ml').isVisible());
 assert.equal(await page.locator('#server-command-ai').isVisible(),false);
 await page.evaluate(()=>{window.__copied='';document.execCommand=()=>{window.__copied=document.activeElement.value;return true;};});
 await page.locator('[data-ojp-copy-target="server-command-ml"]').click();
 assert((await page.evaluate(()=>window.__copied)).includes("oj-ml-playgrounds/resources/server/install.sh"));
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth),false);
 console.log('Common preparation: task selection, collapsed sections, mobile layout and command copy passed');
 for (const [owner,file,className] of [
  ['oj-ai-dashboard','oj-ai-dashboard/src/Platform/External_Ai_Consent_Settings_Page_Handler.php','OJAI\\Dashboard\\Platform\\External_Ai_Consent_Settings_Page_Handler'],
  ['oj-ml-playgrounds','oj-ml-playgrounds/src/Platform/Addon_Page_Handler.php','OJML\\Playgrounds\\Platform\\Addon_Page_Handler'],
  ['readcontents-library','readcontents-library/src/ReadContents/Library/Addon/ConsoleEditorPage.php','ReadContents\\Library\\Addon\\ConsoleEditorPage']
 ]) {
  const code="require 'oj-integration-rebuild/oj-platform/tests/Unit/server-preparation.php'; require '"+file+"'; $method = new ReflectionMethod('"+className+"', 'server_install_markup'); echo $method->invoke(null);";
  const rendered=execFileSync(process.env.PHP_BINARY||'php',['-r',code],{encoding:'utf8'});
  const panel=rendered.slice(rendered.indexOf('<section'));
  assert(!panel.includes('plugins.php?page=oj-server-install'));
  await page.setContent(`<style>${css}</style>${panel}`);await page.addScriptTag({content:js});
  await page.locator('[data-ojp-server-open]').click();
  const modal=page.locator('dialog');assert(await modal.evaluate(x=>x.open));
  const command=await modal.locator('textarea').first().inputValue();
  assert(command.includes(owner+'/resources/server/install.sh'));
  await page.evaluate(()=>{window.__copied='';document.execCommand=()=>{window.__copied=document.activeElement.value;return true;};});
  await modal.locator('[data-ojp-copy-target]').first().click();assert.equal(await page.evaluate(()=>window.__copied),command);
  const bounds=await modal.boundingBox(); assert(bounds.x>=0&&bounds.x+bounds.width<=391);
  await page.keyboard.press('Escape');assert.equal(await modal.evaluate(x=>x.open),false);
  console.log(owner+': settings modal, own script, command copy and mobile width passed');
 }
 for (const [file,rootAttribute,buttonAttribute,resultAttribute] of [
  ['teaching-tools/resources/js/realtime-settings.js','data-htt-realtime-settings','data-htt-realtime-status','data-htt-realtime-result'],
  ['oj-code-arena/resources/js/settings.js','data-oca-settings-page','data-oca-status','data-oca-status-result']
 ]) {
  for(const enabled of [true,false]) {
   await page.setContent(`<div ${rootAttribute} data-config='{"checkOnOpen":${enabled},"restUrl":"/test","nonce":"test"}'><button ${buttonAttribute}>확인</button><div ${resultAttribute}></div></div>`);
   await page.evaluate(()=>{window.__healthCalls=0;window.fetch=async()=>{window.__healthCalls++;return {ok:false,json:async()=>({message:'연결 확인 실패'})};};});
   await page.addScriptTag({content:fs.readFileSync(file,'utf8')});
   if(enabled) { await page.waitForFunction(()=>window.__healthCalls===1); await page.waitForFunction(()=>document.body.textContent.includes('서버 설치·복구')); }
   assert.equal(await page.evaluate(()=>window.__healthCalls),enabled?1:0);
  }
  console.log(file+': enabled mode checked once; disabled mode silent; failure points to installer');
 }
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
