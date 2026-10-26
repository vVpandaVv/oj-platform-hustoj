const assert=require('node:assert/strict'),path=require('node:path');
module.exports=async({page,browser,url,out,ready,state,sessions})=>{
    let checks=0;
    const check=(ok,message)=>{assert(ok,message);checks++;};
    const go=async game=>{await page.goto(url+'/?game='+game);await ready(page);};
    const level=async id=>{await page.locator('.edu-game').evaluate(e=>e.__GameViewport.close());await page.locator('[data-role=settings]').evaluate(e=>e.open=true);await page.locator('[data-role=level]').selectOption(id);};
    const revision=async old=>{await page.waitForFunction(n=>(document.querySelector('[data-play]').__Play.state?.revision??-1)>n,old);await ready(page);};
    const act=async selector=>{const old=(await state(page))?.revision??-1;await page.locator(selector).click();try{await revision(old);}catch(error){console.error(selector,await page.evaluate(()=>({events:window.testEvents,state:document.querySelector('[data-play]').__Play.state})));throw error;}};
    const drag=async(from,to)=>{
        await from.scrollIntoViewIfNeeded();const a=await from.boundingBox(),b=await to.boundingBox();
        await page.mouse.move(a.x+a.width/2,a.y+a.height/2);await page.mouse.down();
        await page.mouse.move(b.x+b.width/2,b.y+20,{steps:8});await page.mouse.up();
    };
    await page.setViewportSize({width:1280,height:1200});
    await go('data-structure-lab');await act('[data-begin]');
    let s=await state(page),first=s.incoming[0];
    await drag(page.locator('[data-cargo=incoming]'),page.locator('[data-cargo-drop=stack]'));await revision(s.revision);
    s=await state(page);check(s.stack.length===1&&s.stack[0]===first,'drag inserts exactly once');
    await act('[data-do=push][data-lane=stack]');s=await state(page);
    check(await page.locator('[data-cargo=stack]').count()===1,'only the top crate is actionable');
    const top=s.stack.at(-1),wanted=s.orders[0];
    await drag(page.locator('[data-cargo=stack]'),page.locator('[data-cargo-drop=dispatch]'));await revision(s.revision);
    s=await state(page);check(!s.stack.includes(top),'drag removes the actual top');
    check(s.feedback.item===top&&s.feedback.to===(top===wanted?'배송 완료':'임시 보관'),'destination agrees with server rule');
    if(s.buffer.length){
        const item=s.buffer[0];await drag(page.locator('[data-cargo=buffer]').first(),page.locator('[data-cargo-drop=queue]'));await revision(s.revision);
        s=await state(page);check(s.queue.includes(item)&&!s.buffer.includes(item),'buffer crate can be reinserted');
    }
    await act('[data-do=reset]');s=await state(page);check(s.feedback.to==='입고대','reset replaces stale shipment feedback');
    const before=s.revision;await drag(page.locator('[data-cargo=incoming]'),page.locator('.eg-focus-toolbar'));
    check((await state(page)).revision===before&&await page.locator('.eo-drag-ghost').count()===0,'invalid drop is harmless');

    await go('missing-values');await level('example-median');
    const preview=await page.locator('[data-play]').evaluate(r=>r.__Play.preview),ids=preview.rows.map((r,i)=>r.group===preview.group?i:-1).filter(i=>i>=0);
    for(const id of ids)await page.locator('[data-do=select][data-id="'+id+'"]').click();
    await drag(page.locator('[data-do=method][data-value=median]'),page.locator('[data-method-drop="1"]'));
    check(await page.locator('[data-do=method][data-value=median]').getAttribute('aria-pressed')==='true','tool drops onto missing value');
    check(await page.locator('[data-method-drop="1"]').getAttribute('aria-pressed')==='true','drop selects corresponding estimate');
    check(await page.locator('.em-calculation .middle').count()>0,'median highlights middle observations');
    await act('[data-do=restore]');check((await page.locator('.em-target-label').innerText()).includes(ids.length+' / '+preview.rows.length),'review retains selected observation count');
    check(await page.locator('.em-comparison>summary').isVisible(),'comparison summary is visible');
    await page.locator('.em-comparison>summary').click();check(await page.locator('.em-comparison table').isVisible(),'optional comparison opens');
    await go('missing-values');await act('[data-begin]');s=await state(page);
    const domain=sessions.get(s.token).state,correct=domain.family;
    if(domain.kind==='cohort')for(let i=0;i<domain.rows.length;i++)if(domain.rows[i].group===domain.group)await page.locator('[data-do=select][data-id="'+i+'"]').click();
    await page.locator('[data-do=method][data-value="'+correct+'"]').click();await act('[data-do=restore]');
    s=await state(page);const clock=await page.locator('[data-clock]').innerText();
    await page.waitForTimeout(1200);check(s.timerPaused&&await page.locator('[data-clock]').innerText()===clock,'review clock stays paused');
    await act('[data-do=next]');check(!(await state(page)).timerPaused,'next resumes clock');

    await go('data-extraction');const count=await page.locator('.ex-outfeed .ex-belt .eh-object').count();
    await page.locator('[data-do=gate][data-gate=color][data-value=blue]').click();
    check(await page.locator('[data-do=gate][data-value=blue]').getAttribute('aria-pressed')==='true','swatch changes gate');
    check(count!==await page.locator('.ex-outfeed .ex-belt .eh-object').count(),'gate changes actual output');
    await page.locator('[data-field=shape]').uncheck();check(!(await page.locator('.ex-ticket').first().innerText()).includes('모양'),'projected labels omit unchecked field');

    await go('ai-classifier-lab');await page.locator('[data-do=boundary-view][data-value=incoming]').click();
    check(await page.locator('.eh-plot g').count()===8,'incoming view draws eight numbered objects');
    check((await page.locator('[data-play]').evaluate(r=>r.__Play.preview.incoming)).every(p=>p.label===undefined),'incoming ground truth remains private');
    const range=page.locator('[data-boundary="0"]'),box=await range.boundingBox();
    await page.mouse.move(box.x+box.width*.2,box.y+box.height/2);await page.mouse.down();
    for(const fraction of [.4,.6,.8])await page.mouse.move(box.x+box.width*fraction,box.y+box.height/2,{steps:3});
    await page.mouse.up();check(Number(await range.inputValue())>70,'native range survives a continuous drag');
    await page.locator('[data-boundary="0"]').fill('100');await page.locator('[data-boundary="1"]').fill('100');
    check(await page.locator('.cf-lane.lane-0 .cf-parcel').count()===8,'boundary redirects visible objects');
    await page.locator('[data-boundary="0"]').fill('0');await page.locator('[data-boundary="1"]').fill('0');
    check(await page.locator('.cf-lane.lane-1 .cf-parcel').count()===8,'opposite boundary reverses the routes');

    await go('ai-model-lab');await level('digits-017');await page.locator('.coach-candidates [data-digit="0"]').click();await act('[data-do=teach][data-label="1"]');
    check(await page.locator('.coach-memory [data-digit]').count()===1&&await page.locator('.coach-slot').count()===8,'memory displays occupied and available slots');
    await page.locator('[data-wrong-only]').check();s=await state(page);
    check(await page.locator('.coach-validation [data-validation]').count()===s.validation.filter(p=>p.prediction!==p.label).length,'error filter keeps only mistakes');
    check(await page.locator('.coach-chain [data-digit="0"]').count()===1,'prediction traces the actual nearest memory');

    await go('data-visualization');await level('practice-pie');
    await page.locator('[data-allocation-range="0"]').fill('4');
    check(await page.locator('[data-allocation="0"]').inputValue()==='4'&&await page.locator('[data-used]').innerText()==='4','range synchronizes counter and cargo');
    check(await page.locator('[data-allocation-chart]').evaluate(c=>Chart.getChart(c).data.datasets[0].data[0])===4,'range changes pie chart immediately');
    const pie=await page.locator('[data-play]').evaluate(r=>r.__Play.preview),total=pie.rows.reduce((n,r)=>n+r.value,0);
    for(let i=0;i<3;i++)await page.locator('[data-allocation-range="'+i+'"]').fill(String(Math.round(pie.supply*pie.rows[i].value/total)));
    await page.locator('[data-visual-chart]').scrollIntoViewIfNeeded();
    const slice=await page.locator('[data-visual-chart]').evaluate(c=>{const p=Chart.getChart(c).getDatasetMeta(0).data[1].getCenterPoint(),r=c.getBoundingClientRect();return{x:r.left+p.x,y:r.top+p.y};});
    await page.mouse.click(slice.x,slice.y);await page.waitForFunction(()=>document.activeElement?.matches('[data-allocation="1"]'));
    for(const width of [360,1280]){await page.setViewportSize({width,height:1050});await page.waitForTimeout(100);await page.screenshot({path:path.join(out,'allocation-resize-'+width+'.png'),fullPage:true});}
    await page.evaluate(()=>{window.testEvents=[];for(const name of ['pointerdown','pointerup','click'])document.addEventListener(name,e=>window.testEvents.push({type:name,tag:e.target.outerHTML.slice(0,250)}),true);});
    await act('[data-visual-action=dispatch]');check((await state(page)).feedback.correct,'slider allocation submits and is scored');
    await act('[data-visual-action=next]');check((await state(page)).phase==='choose','pie review advances');
    await go('data-visualization');
    await level('practice-line');await page.locator('[data-visual-chart]').press('ArrowRight');
    check(await page.locator('[data-visual-choice][aria-pressed=true]').count()===1,'graph supports keyboard selection');
    await level('practice-bar');await page.locator('[data-rescue-chart]').press('ArrowRight');
    check(await page.locator('[data-rescue-destination][aria-pressed=true]').count()===1,'bar graph supports keyboard selection');
    await act('[data-rescue=dispatch]');check(await page.locator('.dispatch-receipt').isVisible(),'receipt stays alongside the completed board');
    for(const game of ['missing-values','data-extraction','data-structure-lab','ai-classifier-lab','ai-model-lab','data-visualization']){
        await go(game);
        for(const width of [360,1280]){
            await page.setViewportSize({width,height:1000});
            check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),game+' fits '+width);
            await page.screenshot({path:path.join(out,'machine-'+game+'-'+width+'.png'),fullPage:true});
        }
    }
    const context=await browser.newContext({viewport:{width:390,height:1000},isMobile:true,hasTouch:true});
    try{
        const touch=await context.newPage();await touch.goto(url+'/?game=ai-model-lab');await ready(touch);
        const card=touch.locator('.coach-candidates [data-digit]').first();await card.tap();
        check(await card.getAttribute('aria-pressed')==='true','touch selects a sample');
        await card.scrollIntoViewIfNeeded();const a=await card.boundingBox(),tray=touch.locator('[data-tray]').first(),b=await tray.boundingBox(),label=await tray.getAttribute('data-tray'),cdp=await context.newCDPSession(touch);
        await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:a.x+20,y:a.y+20}]});
        await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:b.x+20,y:b.y+20}]});
        await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
        await touch.waitForFunction(name=>document.querySelector('[data-play]').__Play.state?.labels?.['0']===name,label);
        check((await state(touch)).labels['0']===label,'touch drag labels a sample once');
    }finally{await context.close();}
    console.log(checks+' remaining-game interface checks passed');
};
