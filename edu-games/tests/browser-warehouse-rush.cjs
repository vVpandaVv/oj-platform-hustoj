const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs');
module.exports=async({page,browser,url,out,ready,state,setFault,sessions})=>{
    let checks=0;
    const check=(ok,text)=>{assert(ok,text);checks++;};
    const revision=async old=>{await page.waitForFunction(n=>(document.querySelector('[data-play]').__Play.state?.revision??-1)>n,old);await ready(page);};
    const act=async selector=>{const old=(await state(page))?.revision??-1;await page.locator(selector).click();await revision(old);};
    const drag=async(from,to)=>{const a=await from.boundingBox(),b=await to.boundingBox();await page.mouse.move(a.x+a.width/2,a.y+a.height/2);await page.mouse.down();await page.mouse.move(b.x+b.width/2,b.y+b.height/2,{steps:8});await page.mouse.up();};
    await page.setViewportSize({width:1280,height:800});
    await page.goto(url+'/?game=data-structure-lab');await ready(page);
    check(await page.locator('[data-drop-dock]').count()===3,'three concurrent visible destinations');
    check(await page.locator('.wh-receiving img').evaluate(img=>img.complete&&img.naturalWidth>0),'forklift asset loaded');
    await act('[data-begin]');
    check(await page.locator('[data-warehouse]').evaluate(el=>el.contains(document.activeElement)),'start enters the playable controls without an extra click');
    let s=await state(page),first=s.incoming[0];
    await drag(page.locator('[data-drag-source=incoming]'),page.locator('[data-drop-lane=stack]'));await revision(s.revision);
    s=await state(page);check(s.stack[0]===first&&s.stack.length===1,'drag inserts exactly one parcel');
    await act('[data-wh=put-stack]');s=await state(page);
    check(await page.locator('[data-drag-source=stack]').count()===1,'only stack top is interactive');
    const top=s.stack.at(-1),slot=s.orders.findIndex(o=>o.items[0]===top);
    const target=slot<0?0:slot;
    await drag(page.locator('[data-drag-source=stack]'),page.locator(`[data-drop-dock="${target}"]`));await revision(s.revision);
    s=await state(page);check(s.feedback.item===top&&!s.stack.includes(top),'LIFO visible result agrees with domain');
    if(s.buffer.length){
        const item=s.buffer[0];await page.waitForTimeout(370);
        await page.locator('[data-wh=hold]').first().click();await act('[data-wh=put-queue]');
        s=await state(page);check(s.queue.includes(item)&&!s.buffer.includes(item),'touch-style selection and reinsertion');
    }
    await page.waitForTimeout(370);
    await page.locator('[data-wh=put-queue]').focus();let before=(await state(page)).revision;
    await page.keyboard.press('a');await revision(before);s=await state(page);
    check(s.feedback.to==='stack','A shortcut inserts on the chosen stack');
    check(await page.locator('[data-wh=put-queue]').evaluate(el=>document.activeElement===el),'focus survives server repaint');
    await page.keyboard.press('2');check(await page.locator('[data-slot="1"]').getAttribute('aria-pressed')==='true','order choice is a local action');
    before=s.revision;await page.keyboard.press('j');await revision(before);
    check((await state(page)).moves===s.moves+1,'one keypress has one effect');

    s=await state(page);before=s.revision;setFault('after');
    await page.locator('[data-wh=put-queue]').click();await ready(page);
    check(await page.locator('[data-command=retry]').isVisible(),'lost response exposes retry');
    check(await page.locator('.eg-focus-area').evaluate(el=>el.scrollHeight<=el.clientHeight+2),'save error and retry do not resize the game');
    await page.locator('[data-command=retry]').click();await ready(page);
    check((await state(page)).revision===before+1,'retry does not move another parcel');
    await page.reload();await ready(page);await page.locator('[data-command=resume]').click();await ready(page);
    check((await state(page)).revision===before+1,'warehouse resumes with same inventory');

    for(const size of [{width:1280,height:800},{width:1024,height:768},{width:390,height:844},{width:360,height:740}]){
        await page.setViewportSize(size);await page.waitForTimeout(180);
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no horizontal page overflow '+size.width);
        const bounds=await page.locator('.eg-focus-area').evaluate(el=>({height:el.clientHeight,scroll:el.scrollHeight}));
        await page.screenshot({path:path.join(out,'warehouse-rush-'+size.width+'.png')});
        check(bounds.scroll<=bounds.height+2,'focused game fits vertically '+JSON.stringify(size)+' '+JSON.stringify(bounds));
        const clipped=await page.locator('[data-warehouse] button').evaluateAll(es=>es.filter(e=>e.offsetWidth&&(e.scrollWidth>e.clientWidth+3||e.scrollHeight>e.clientHeight+3)).map(e=>e.textContent));
        assert.deepEqual(clipped,[],'controls fit '+size.width);checks++;
        await page.screenshot({path:path.join(out,'warehouse-rush-'+size.width+'.png')});
    }
    const platform=process.env.OJ_PLATFORM_ROOT||path.resolve(__dirname,'../../oj-integration-rebuild/oj-platform');
    await page.addStyleTag({content:fs.readFileSync(path.join(platform,'resources/ui/css/oj-ui-system.css'),'utf8')});
    await page.evaluate(()=>document.documentElement.setAttribute('data-ojp-scheme','light'));
    const light=await page.locator('[data-warehouse]').evaluate(el=>getComputedStyle(el).color);
    await page.evaluate(()=>document.documentElement.setAttribute('data-ojp-scheme','dark'));
    await page.waitForTimeout(100);
    check(await page.locator('[data-warehouse]').evaluate(el=>getComputedStyle(el).color)!==light,'dark mode follows shared theme');
    await page.screenshot({path:path.join(out,'warehouse-rush-dark.png')});
    await page.evaluate(()=>document.documentElement.setAttribute('data-ojp-scheme','light'));
    await act('[data-wh=finish]');
    check((await state(page)).status==='won'&&await page.locator('[data-result]').isVisible(),'finish keeps score and operational results');
    await page.locator('[data-command=show-records]').click();
    check(await page.locator('[data-role=records]').getAttribute('open')!==null,'existing ranking view accessible');
    const touch=await browser.newPage({viewport:{width:834,height:1194},hasTouch:true,isMobile:true});
    try{
        await touch.goto(url+'/?game=data-structure-lab');await ready(touch);
        await touch.locator('[data-begin]').tap();await ready(touch);
        await touch.locator('[data-wh=put-stack]').tap();await touch.waitForFunction(()=>document.querySelector('[data-play]').__Play.state.stack.length===1);await ready(touch);
        await touch.locator('[data-wh=put-stack]').tap();await touch.waitForFunction(()=>document.querySelector('[data-play]').__Play.state.stack.length===2);await ready(touch);
        const prior=await state(touch);await touch.locator('[data-drag-source=stack]').tap();await revisionOnTouch();
        check((await state(touch)).stack.length===1,'touch on the top parcel removes only the last insertion');
        check((await state(touch)).revision===prior.revision+1,'touch tap has no duplicate click');
        await touch.screenshot({path:path.join(out,'warehouse-rush-touch.png')});
        async function revisionOnTouch(){await touch.waitForFunction(n=>document.querySelector('[data-play]').__Play.state.revision>n,prior.revision);await ready(touch);}
    }finally{await touch.close();}
    console.log(checks+' warehouse rush browser checks passed; screenshots '+out);
};
