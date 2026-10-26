const assert=require('node:assert/strict'),path=require('node:path');
module.exports=async({page,browser,url,out,ready,state,setFault,sessions})=>{
    let checks=0;
    const go=async game=>{await page.goto(url+'/?game='+game);await ready(page);};
    const selectLevel=async level=>{await page.locator('.edu-game').evaluate(e=>e.__GameViewport.close());await page.locator('[data-role=settings]').evaluate(e=>e.open=true);await page.locator('[data-role=level]').selectOption(level);};
    const action=async selector=>{const revision=(await state(page))?.revision??-1;await page.evaluate(()=>{window.testEvents=[];if(!window.testEventHook){window.testEventHook=true;for(const type of ['pointerdown','pointerup','click'])document.addEventListener(type,e=>window.testEvents.push({type,tag:e.target.outerHTML.slice(0,250)}),true);}});await page.locator(selector).click();try{await page.waitForFunction(old=>(document.querySelector('[data-play]').__Play.state?.revision??-1)>old,revision);}catch(error){console.error(selector,await page.locator('[data-play]').evaluate(r=>({events:window.testEvents,state:r.__Play.state,message:r.querySelector('[data-role=message]').textContent})));throw error;}await ready(page);};
    const layout=async(game)=>{
        for(const width of [360,1280]){
            await page.setViewportSize({width,height:1050});await page.waitForTimeout(100);
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),game+' overflow '+width);
            const clipped=await page.locator('[data-play]').evaluate(r=>[...r.querySelectorAll('button')].filter(e=>e.offsetWidth&&(e.scrollWidth>e.clientWidth+3||e.scrollHeight>e.clientHeight+3)).map(e=>e.textContent));
            assert.deepEqual(clipped,[],game+' clipped controls '+width);
            if(game.startsWith('rescue-')||game.startsWith('scene-')){
                assert(await page.locator('[data-rescue-chart],[data-visual-chart]').evaluate(c=>new Set(c.getContext('2d').getImageData(0,0,c.width,c.height).data).size>8),'graph pixels render');checks++;
            }
            await page.screenshot({path:path.join(out,game+'-'+width+'.png'),fullPage:true});checks+=2;
        }
    };
    if(!process.env.PLAY_VISUAL_ONLY){
    await go('outlier-detection');
    assert.equal(await page.locator('[data-role=settings]').getAttribute('open'),null);checks++;
    assert(await page.locator('.eo-distribution').isVisible());checks++;
    await page.locator('[data-personal-best]').waitFor();
    await page.waitForFunction(()=>document.querySelector('[data-personal-best]').textContent!=='…');
    const original=await page.locator('.eh-equation').innerText();
    await page.locator('[data-do=select]').first().click();
    assert.notEqual(await page.locator('.eh-equation').innerText(),original);checks++;
    await action('[data-do=scan]');assert.equal((await state(page)).turn,1);checks++;
    await layout('outlier-detection');
    setFault('after');await page.locator('[data-do=scan]').click();await ready(page);await page.locator('[data-command=retry]').click();await ready(page);
    assert.equal((await state(page)).revision,2);checks++;
    await page.reload();await ready(page);await page.locator('[data-command=resume]').click();await ready(page);assert.equal((await state(page)).revision,2);checks++;
    await go('missing-values');
    await selectLevel('example-linear');
    assert(await page.locator('[data-do=restore]').isDisabled());checks++;
    await page.locator('[data-do=method][data-value=linear]').click();const linear=await page.locator('.em-targets').innerText();
    await page.locator('[data-do=method][data-value=mean]').click();assert.notEqual(await page.locator('.em-targets').innerText(),linear);checks++;
    await page.locator('[data-do=method][data-value=linear]').click();await action('[data-do=restore]');
    assert.equal((await state(page)).phase,'review');assert.equal((await state(page)).feedback.hits,3);checks+=2;
    await layout('missing-values');await action('[data-do=next]');assert.equal((await state(page)).phase,'solve');checks++;
    await go('missing-values');await selectLevel('example-median');
    const preview=await page.locator('[data-play]').evaluate(r=>r.__Play.preview);
    for(let i=0;i<preview.rows.length;i++)if(preview.rows[i].group===preview.group)await page.locator('[data-do=select][data-id="'+i+'"]').click();
    assert(await page.locator('[data-do=restore]').isDisabled());checks++;
    await page.locator('[data-do=method][data-value=mean]').click();const mean=await page.locator('.em-targets').innerText();
    await page.locator('[data-do=method][data-value=median]').click();assert.notEqual(await page.locator('.em-targets').innerText(),mean);checks++;
    await action('[data-do=restore]');assert.equal((await state(page)).feedback.hits,3);assert.equal((await state(page)).score,0);checks+=2;
    await layout('missing-median');
    for(const family of ['mean','mode','previous','next','seasonal','nearest','unknown']){
        await go('missing-values');await selectLevel('example-'+family);
        const preview=await page.locator('[data-play]').evaluate(r=>r.__Play.preview);
        if(preview.kind==='cohort')for(let i=0;i<preview.rows.length;i++)if(preview.rows[i].group===preview.group)await page.locator('[data-do=select][data-id="'+i+'"]').click();
        await page.locator('[data-do=method][data-value="'+family+'"]').click();
        await action('[data-do=restore]');assert.equal((await state(page)).feedback.hits,3,family+' UI');checks++;
        if(['mode','seasonal','nearest'].includes(family))await layout('missing-'+family);
    }
    await go('data-extraction');const and=await page.locator('.eh-equation').innerText();await page.locator('[data-do=join][data-value=or]').click();assert.notEqual(await page.locator('.eh-equation').innerText(),and);checks++;
    await page.locator('[data-field=shape]').uncheck();assert((await page.locator('.eh-equation').innerText()).includes('2열'));checks++;
    await action('[data-do=ship]');await layout('data-extraction');
    await go('data-structure-lab');await action('[data-do=push][data-lane=stack]');await action('[data-do=push][data-lane=stack]');
    // Keyboard play: after a server round trip the same control keeps focus
    // (the redraw used to drop it to <body> after every move).
    await page.focus('[data-do=push][data-lane=queue]');await page.keyboard.press('Enter');
    await page.waitForFunction(()=>document.activeElement?.matches('[data-do=push][data-lane=queue]'));checks++;
    let s=await state(page),top=s.stack[1];await action('[data-do=pop][data-lane=stack]');s=await state(page);assert(!s.stack.includes(top));checks++;
    await layout('data-structure-lab');
    await go('ai-classifier-lab');const boundary=await page.locator('[data-drag=boundary][data-end="0"]').boundingBox();
    const startY=await page.locator('[data-drag=boundary][data-end="0"]').getAttribute('cy');
    await page.mouse.move(boundary.x+boundary.width/2,boundary.y+boundary.height/2);await page.mouse.down();await page.mouse.move(boundary.x+boundary.width/2,boundary.y-55,{steps:5});await page.mouse.up();
    assert.notEqual(await page.locator('[data-drag=boundary][data-end="0"]').getAttribute('cy'),startY);checks++;
    await page.locator('[data-boundary="0"]').fill('64');await page.locator('[data-boundary="1"]').fill('26');await action('[data-do=classify]');assert.equal((await state(page)).feedback.hits.reduce((a,b)=>a+b),8);checks++;
    await layout('ai-classifier-lab');
    await go('ai-model-lab');await selectLevel('digits-017');await page.locator('[data-digit="0"]').first().click();await action('[data-do=teach][data-label="1"]');assert((await state(page)).validation.every(v=>v.prediction==='1'));checks++;
    await page.locator('.coach-validation [data-validation="0"]').click();assert((await page.locator('.eh-neighbor').innerText()).includes('붙인 이름 1'));checks++;
    await page.locator('[data-digit="1"]').first().dragTo(page.locator('[data-tray="7"]'));await page.waitForFunction(()=>document.querySelector('[data-play]').__Play.state?.labels?.['1']==='7');await ready(page);checks++;
    const pixels=await page.locator('[data-pixels]').first().evaluate(c=>new Set(c.getContext('2d').getImageData(0,0,8,8).data).size);assert(pixels>3);checks++;
    await layout('ai-model-lab');await action('[data-do=finish]');assert.equal((await state(page)).metrics.total,90);checks++;
    for(const combo of ['069','358','147','235','268','049','128','456','239','578','024']){
        await selectLevel('digits-'+combo);
        assert.deepEqual(await page.locator('[data-tray]').evaluateAll(es=>es.map(e=>e.dataset.tray)),combo.split(''));checks++;
        assert.equal(await page.locator('[data-do=teach]:disabled').count(),3);checks++;
        await page.locator('[data-digit="0"]').first().click();await action('[data-do=teach][data-label="'+combo[1]+'"]');
        assert((await state(page)).validation.every(v=>v.prediction===combo[1]));checks++;
        await page.locator('[data-do=photos-next]').click();assert(await page.locator('[data-digit="12"]').isVisible());checks++;
        await page.locator('.coach-validation [data-validation="0"]').click();assert((await page.locator('.eh-neighbor').innerText()).includes('붙인 이름 '+combo[1]));checks++;
        const ink=await page.locator('[data-pixels]').first().evaluate(c=>new Set(c.getContext('2d').getImageData(0,0,8,8).data).size);assert(ink>3);checks++;
        if(['069','358'].includes(combo))await layout('handwriting-'+combo);
        await action('[data-do=finish]');
        const result=await page.locator('[data-result]').innerText();for(const n of combo)assert(result.includes(n+': '));checks++;
        assert.equal((await state(page)).metrics.total,90);checks++;
    }
    }
    await page.setViewportSize({width:1280,height:1050});
    await go('data-visualization');
    await selectLevel('practice-bar');
    assert.equal(await page.locator('[data-do=receiver]').count(),0);checks++;
    assert(await page.locator('[data-rescue=dispatch]').isDisabled());checks++;
    // Click the tallest bar the chart actually draws (since 1.2.0 a bar can
    // group several hours, so bars no longer map 1:1 to raw rows).
    const point=await page.locator('[data-rescue-chart]').evaluate(c=>{
        const chart=Chart.getChart(c),r=c.getBoundingClientRect();let best=null;
        chart.data.datasets.forEach((set,d)=>set.data.forEach((v,i)=>{if(!best||v>best.v)best={v,d,i};}));
        const p=chart.getDatasetMeta(best.d).data[best.i];return{x:r.left+p.x,y:r.top+(p.y+p.base)/2,value:best.v};
    });
    await page.mouse.click(point.x,point.y);await page.waitForFunction(()=>!document.querySelector('.er-equation').innerText.includes('미선택'));
    {const eq=await page.locator('.er-equation').innerText();assert(eq.includes(point.value+'명'),eq);}checks++;
    await action('[data-rescue=dispatch]');assert.equal((await state(page)).phase,'review');assert.equal((await state(page)).score,0);checks+=2;
    assert.equal(browser.contexts()[0].pages().length,1);checks++;
    await layout('rescue-hour');await action('[data-rescue=next]');
    const scenes=require('../assets/play/rescue-scenes.js');
    for(const kind of ['line','pie','histogram','scatter']){
        await go('data-visualization');await selectLevel('practice-'+kind);
        const current=await page.locator('[data-play]').evaluate(r=>r.__Play.preview);assert.equal(current.scene,kind);checks++;
        assert(await page.locator('[data-visual-action=dispatch]').isDisabled());checks++;
        const type=await page.locator('[data-visual-chart]').evaluate(c=>Chart.getChart(c).config.type);
        assert.equal(type,kind==='histogram'?'bar':kind);checks++;
        if(kind==='pie'){
            const input=page.locator('[data-allocation="0"]');await input.fill('');await input.pressSequentially('12');assert.equal(await input.inputValue(),'12');assert.equal(await page.locator('[data-used]').innerText(),'12');checks+=2;
            const total=current.rows.reduce((a,r)=>a+r.value,0);
            for(let i=0;i<3;i++)await page.locator('[data-allocation="'+i+'"]').fill(String(Math.round(current.supply*current.rows[i].value/total)));
            assert.equal(await page.locator('[data-used]').innerText(),String(current.supply));checks++;
            const allocation=await page.locator('[data-allocation-chart]').evaluate(c=>Chart.getChart(c).data.datasets[0].data);
            assert.deepEqual(allocation,[...current.rows.map(r=>Math.round(current.supply*r.value/total)),0]);checks++;
            await page.locator('[data-allocate="1"][data-step="-1"]').click();assert(await page.locator('[data-visual-action=dispatch]').isDisabled());checks++;
            await page.locator('[data-allocate="1"][data-step="1"]').click();assert(!(await page.locator('[data-visual-action=dispatch]').isDisabled()));checks++;
            await page.locator('[data-visual-chart]').scrollIntoViewIfNeeded();
            const p=await page.locator('[data-visual-chart]').evaluate(c=>{const p=Chart.getChart(c).getDatasetMeta(0).data[1].getCenterPoint(),r=c.getBoundingClientRect();return{x:r.left+p.x,y:r.top+p.y};});
            await page.mouse.click(p.x,p.y);await page.waitForFunction(()=>document.activeElement?.matches('[data-allocation="1"]'));checks++;
        }else{
            const options=scenes.choices(current).filter(c=>c.eligible??true).sort((a,b)=>b.value-a.value),best=options[0];
            await page.locator('[data-visual-chart]').scrollIntoViewIfNeeded();
            const p=await page.locator('[data-visual-chart]').evaluate((c,{kind,best,current})=>{
                const chart=Chart.getChart(c),dataset=kind==='line'?['동쪽','중앙','서쪽'].indexOf(best.region):0,index=kind==='scatter'?current.rows.findIndex(r=>r.id===best.id):best.index;
                const el=chart.getDatasetMeta(dataset).data[index],r=c.getBoundingClientRect();return{x:r.left+el.x,y:r.top+(kind==='histogram'?(el.y+el.base)/2:el.y)};
            },{kind,best,current});
            if(kind==='histogram'){
                await page.locator('[data-window]').fill(String(best.index));
                if(best.index===0)await page.locator('[data-window]').evaluate(e=>e.dispatchEvent(new Event('input',{bubbles:true})));
                assert((await page.locator('.ev-supported').innerText()).includes('지원 가능 '+best.value+'명'));checks++;
                assert.equal(await page.locator('.ev-passengers .supported').count(),best.value);checks++;
            }else await page.mouse.click(p.x,p.y);
            await page.waitForFunction(id=>document.querySelector('[data-visual-choice][aria-pressed=true]')?.dataset.visualChoice===id,best.id);checks++;
        }
        await layout('scene-'+kind);await action('[data-visual-action=dispatch]');assert((await state(page)).feedback.correct);checks++;
        assert.equal((await state(page)).phase,'review');checks++;
        await layout('scene-'+kind+'-result');await action('[data-visual-action=next]');
    }
    await go('data-visualization');await selectLevel('practice-bar');
    const chooseBar=async id=>{await page.locator('.dispatch-alternatives').evaluate(e=>e.open=true);await page.locator('[data-rescue-destination="'+id+'"]').click();};
    let first=await page.locator('[data-rescue-destination]').first().getAttribute('data-rescue-destination');await chooseBar(first);await action('[data-rescue=dispatch]');await action('[data-rescue=next]');
    assert.equal((await state(page)).kind,'total');assert(await page.locator('[data-rescue=dispatch]').isDisabled());checks+=2;
    await page.locator('[data-rescue=mode][data-mode=total]').click();
    let options=require('../assets/play/rescue.js').bars(await state(page),'total');
    options.sort((a,b)=>b.value-a.value);await chooseBar(options[0].id);
    assert((await page.locator('.er-equation').innerText()).includes(' + '));checks++;
    await action('[data-rescue=dispatch]');assert((await state(page)).feedback.correct);checks++;
    await layout('rescue-total');await action('[data-rescue=next]');
    await page.locator('[data-rescue=mode][data-mode=pair]').click();
    options=require('../assets/play/rescue.js').bars(await state(page),'pair');
    options.sort((a,b)=>a.value-b.value);await chooseBar(options[0].id);
    await action('[data-rescue=dispatch]');assert.equal((await state(page)).combo,0);assert.equal((await state(page)).feedback.delta,0);checks+=2;
    await layout('rescue-pair');await action('[data-rescue=finish]');assert.equal((await state(page)).status,'won');checks++;
    await selectLevel('practice-bar');assert.equal(await page.locator('[data-clock]').count(),0);checks++;
    first=await page.locator('[data-rescue-destination]').first().getAttribute('data-rescue-destination');await chooseBar(first);await action('[data-rescue=dispatch]');assert.equal((await state(page)).score,0);checks++;
    await action('[data-rescue=finish]');assert.equal((await state(page)).status,'won');checks++;
    await go('data-visualization');await action('[data-rescue=begin]');
    const kinds=[];
    for(let i=0;i<7;i++){
        const current=await state(page);kinds.push(current.kind);let move;
        if(current.scene==='pie'){const total=current.rows.reduce((a,r)=>a+r.value,0);move={type:'dispatch',allocation:current.rows.map(r=>Math.round(current.supply*r.value/total))};}
        else if(current.scene==='bar'){const bars=require('../assets/play/rescue.js').bars(current,current.kind).sort((a,b)=>b.value-a.value);move={type:'dispatch',view:current.kind,bar:bars[0].id};}
        else {const choices=scenes.choices(current).filter(c=>c.eligible??true).sort((a,b)=>b.value-a.value);move={type:'dispatch',choice:choices[0].id};}
        await page.locator('[data-play]').evaluate((r,m)=>r.__Play.move(m),move);await ready(page);
        const reviewed=await state(page);assert(reviewed.feedback.correct&&reviewed.timerPaused);assert(reviewed.score>current.score);checks+=2;
        if(i===0){
            const time=await page.locator('[data-clock]').innerText();await page.waitForTimeout(1300);assert.equal(await page.locator('[data-clock]').innerText(),time);checks++;
            sessions.get(reviewed.token).state.deadline=Date.now()/1000-100;
            await page.reload();await ready(page);await page.locator('[data-command=resume]').click();await ready(page);
            assert.equal(await page.locator('[data-clock]').innerText(),time);assert.equal((await state(page)).status,'playing');checks+=2;
        }
        await action(current.scene==='bar'?'[data-rescue=next]':'[data-visual-action=next]');assert(!(await state(page)).timerPaused);checks++;
    }
    assert.deepEqual(kinds.sort(),['histogram','hour','line','pair','pie','scatter','total']);checks++;
    await action('[data-rescue=finish]');assert((await page.locator('[data-result]').innerText()).includes('정확도 100%'));checks++;
    // Real timeout handling in the preview: server deadline wins over the browser clock.
    await go('outlier-detection');await action('[data-do=scan]');s=await state(page);sessions.get(s.token).state.deadline=Date.now()/1000-1;
    const score=s.score;await action('[data-do=scan]');assert.equal((await state(page)).status,'won');assert.equal((await state(page)).score,score);checks+=2;
    await go('outlier-detection');await action('[data-do=scan]');s=await state(page);sessions.get(s.token).state.deadline=Date.now()/1000-1;
    await page.reload();await ready(page);await page.locator('[data-command=resume]').click();
    await page.waitForFunction(()=>document.querySelector('[data-play]').__Play.state?.status==='won');await ready(page);
    assert.equal((await state(page)).score,s.score);checks++;
    console.log(checks+' direct-manipulation browser checks passed; screenshots '+out);
};
