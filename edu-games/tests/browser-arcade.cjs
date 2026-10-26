const assert=require('node:assert/strict'),path=require('node:path');
module.exports=async({page,browser,url,out,ready,state,sessions})=>{
    let checks=0;
    const selected=()=>page.locator('.eo-point[aria-pressed=true]').count();
    const action=async selector=>{
        const before=(await state(page))?.revision??-1;
        await page.locator(selector).click();
        await page.waitForFunction(old=>(document.querySelector('[data-play]').__Play.state?.revision??-1)>old,before);await ready(page);
    };
    const drag=async(from,to)=>{
        const a=await from.boundingBox(),b=await to.boundingBox();
        await page.mouse.move(a.x+a.width/2,a.y+a.height/2);await page.mouse.down();
        await page.mouse.move(b.x+b.width/2,b.y+b.height/2,{steps:8});await page.mouse.up();
    };
    await page.setViewportSize({width:1280,height:1100});
    await page.goto(url+'/?game=outlier-detection');await ready(page);
    await page.waitForFunction(()=>document.querySelector('[data-personal-best]').textContent==='0');checks++;
    assert.equal(await page.locator('[data-role=settings]').getAttribute('open'),null);checks++;
    const before=await page.locator('.eo-means').innerText();
    await page.locator('.eo-point').first().focus();await page.keyboard.press('Space');
    assert.equal(await selected(),1);assert.notEqual(await page.locator('.eo-means').innerText(),before);checks+=2;
    await page.locator('.eo-review-token').press('Enter');assert.equal(await selected(),0);checks++;
    await drag(page.locator('.eo-point').first(),page.locator('.eo-review'));
    assert.equal(await selected(),1);assert.equal(await page.locator('.eo-review-token').count(),1);checks+=2;
    await drag(page.locator('.eo-review-token'),page.locator('.eo-distribution'));
    assert.equal(await selected(),0);checks++;
    await drag(page.locator('.eo-point').first(),page.locator('.ep-heading'));
    assert.equal(await selected(),0);assert.equal(await page.locator('.eo-drag-ghost').count(),0);checks+=2;
    // Cancelled pointer sequences must not classify a value.
    const cancelled=await page.locator('.eo-point').first().boundingBox();
    await page.mouse.move(cancelled.x+20,cancelled.y+20);await page.mouse.down();
    await page.locator('[data-role=board]').dispatchEvent('pointercancel',{pointerId:1,pointerType:'mouse'});await page.mouse.up();
    assert.equal(await selected(),0);checks++;
    await action('[data-begin]');
    const current=await state(page),truth=sessions.get(current.token).state.truth;
    for(const id of truth)await page.locator('.eo-point[data-id="'+id+'"]').click();
    assert.equal(await selected(),truth.length);checks++;
    await action('[data-do=scan]');
    assert((await state(page)).score>0);assert(await page.locator('.eo-verdict.success').isVisible());
    assert.equal(await selected(),0);checks+=3;
    for(const width of [360,1280]){
        await page.setViewportSize({width,height:1000});
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
        const clear=await page.locator('.eo-plot').evaluate(el=>{
            const axis=el.querySelector('.eo-axis').getBoundingClientRect();
            return [...el.querySelectorAll('.eo-point')].every(p=>p.getBoundingClientRect().bottom<axis.top);
        });assert(clear,'tokens clear numeric axis');checks+=2;
        await page.screenshot({path:path.join(out,'arcade-outlier-'+width+'.png'),fullPage:true});
    }
    await action('[data-do=finish]');
    assert((await page.locator('[data-record-difference]').innerText()).includes('최고 기록 경신'));checks++;
    const score=(await state(page)).score;
    await page.locator('.eh-result-actions [data-command=new]').click();
    await page.waitForFunction(value=>document.querySelector('[data-personal-best]').textContent===String(value),score);
    assert.equal(await state(page),null);checks++;
    // Real touch input follows the same click/drag lifecycle, without hover painting.
    const context=await browser.newContext({viewport:{width:390,height:844},hasTouch:true,isMobile:true});
    try{
        const touch=await context.newPage();await touch.goto(url+'/?game=outlier-detection');await ready(touch);
        const token=touch.locator('.eo-point').first();await token.tap();
        assert.equal(await touch.locator('.eo-review-token').count(),1);checks++;
        await touch.locator('.eo-review-token').tap();assert.equal(await touch.locator('.eo-review-token').count(),0);checks++;
        const top=await token.boundingBox();await touch.evaluate(y=>window.scrollBy(0,y-80),top.y);
        const a=await token.boundingBox(),b=await touch.locator('.eo-review').boundingBox(),cdp=await context.newCDPSession(touch);
        await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:a.x+a.width/2,y:a.y+20}]});
        await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:b.x+b.width/2,y:b.y+20}]});
        await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
        assert.equal(await touch.locator('.eo-review-token').count(),1);checks++;
        await touch.screenshot({path:path.join(out,'arcade-outlier-touch.png'),fullPage:true});
    }finally{await context.close();}
    console.log(checks+' arcade interface checks passed');
};
