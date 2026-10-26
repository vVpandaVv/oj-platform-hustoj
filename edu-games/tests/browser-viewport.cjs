const assert=require('node:assert/strict'),path=require('node:path');
module.exports=async({page,url,out,ready,state})=>{
    const games=['outlier-detection','missing-values','data-visualization','data-extraction','data-structure-lab','ai-classifier-lab','ai-model-lab','binary-arcade','character-code','code-typing','device-matching','image-data','keyword-rain'];
    const measures=[];
    for(const viewport of process.env.VIEWPORT_EXTRA?[]:[{width:1280,height:720},{width:390,height:844},{width:834,height:1194},{width:1194,height:834}])for(const game of games.filter(g=>!process.env.VIEWPORT_GAME||g===process.env.VIEWPORT_GAME)){
        const {width}=viewport;
        await page.setViewportSize(viewport);
        await page.goto(url+'/?game='+game);await page.waitForSelector('.edu-game[data-shell-ready=true]');
        if(games.indexOf(game)<7){await ready(page);await page.locator('[data-begin]').click();await ready(page);}
        if(game==='device-matching')await page.locator('[data-action=start]').click();
        if(game==='binary-arcade')await page.locator('[data-action=practice]').first().click();
        if(['character-code','keyword-rain'].includes(game))await page.locator('[data-action=start-ranked]').click();
        await page.locator('.edu-game').evaluate(e=>e.__GameViewport.open());
        await page.waitForTimeout(150);
        const measure=await page.locator('.eg-focus-area').evaluate(e=>{
            const r=e.getBoundingClientRect(),root=e.querySelector('.edu-game');
            const visible=b=>b.checkVisibility()&&!b.closest('details:not([open])');
            return {area:e.clientHeight,height:root.getBoundingClientRect().height,overflowY:e.scrollHeight-e.clientHeight,overflowX:e.scrollWidth-e.clientWidth,hiddenControls:[...root.querySelectorAll('button')].filter(b=>{const x=b.getBoundingClientRect();return visible(b)&&x.width&&x.height&&(x.bottom>r.bottom+1||x.right>r.right+1);}).map(b=>b.textContent.trim()).slice(0,6),parts:[...root.querySelectorAll('.eh-playfield>*,[data-hands-live]>*,[data-rescue-live]>*,.er-dispatch>*,.oj-image-shell>*,.oj-image-layout>*,.oj-image-canvas-panel>*,.oj-rain-shell>*,.oj-rain-panel>*')].filter(visible).map(b=>[b.className,Math.round(b.getBoundingClientRect().height)])};
        });
        measures.push({game,width,...measure});
        await page.screenshot({path:path.join(out,`viewport-${game}-${width}.png`),fullPage:true});
        const retained=await page.locator('.edu-game').evaluate(e=>{e.__viewportMarker={};return e.__viewportMarker!==undefined;});
        assert(retained);
        await page.getByRole('button',{name:'집중 화면 나가기',exact:true}).click();
        assert.equal(await page.locator('.eg-focus-dialog').count(),0);
        assert.equal(await page.locator('main .edu-game').count(),1);
        assert.equal(await page.evaluate(()=>document.documentElement.style.overflow),'');
        assert(await page.locator('.edu-game').evaluate(e=>e.__viewportMarker!==undefined),'original game node retained');
        await page.waitForTimeout(50);assert.equal(await page.locator('.eg-focus-dialog').count(),0,'exit does not reopen an active game');
    }
    const clipped=measures.filter(m=>m.overflowY>1||m.overflowX>1||m.hiddenControls.length);
    assert.deepEqual(clipped,[],'all starting boards and controls fit');
    console.log(measures.length+' starting viewport layouts passed');
    if(process.env.VIEWPORT_GAME)return;
    const extra=[];
    const measure=async label=>{
        await page.waitForTimeout(120);
        const size=await page.locator('.eg-focus-area').evaluate(e=>({y:e.scrollHeight-e.clientHeight,x:e.scrollWidth-e.clientWidth}));
        extra.push({label,...size});console.log(label,size);await page.screenshot({path:path.join(out,'focus-'+label+'.png'),fullPage:true});
    };
    const act=async selector=>{const old=(await state(page))?.revision??-1;await page.locator(selector).first().click();await page.waitForFunction(n=>(document.querySelector('[data-play]').__Play.state?.revision??-1)>n,old);await ready(page);};
    for(const width of [1280,390]){
        await page.setViewportSize({width,height:width===1280?720:844});
        for(const scene of ['bar','line','pie','histogram','scatter']){
            await page.goto(url+'/?game=data-visualization');await ready(page);
            await page.locator('[data-role=settings]').evaluate(e=>e.open=true);
            await page.locator('[data-role=level]').selectOption('practice-'+scene);
            await act('[data-begin]');
            await page.locator('.edu-game').evaluate(e=>e.__GameViewport.open());
            await measure(scene+'-'+width);
            if(scene==='pie')await page.locator('[data-allocation="0"]').fill(String(await page.locator('[data-play]').evaluate(r=>r.__Play.preview.supply)));
            else await page.locator(scene==='bar'?'[data-rescue-chart]':'[data-visual-chart]').press('ArrowRight');
            await act(scene==='bar'?'[data-rescue=dispatch]':'[data-visual-action=dispatch]');
            await measure(scene+'-review-'+width);
        }
        for(const family of ['median','nearest','unknown']){
            await page.goto(url+'/?game=missing-values');await ready(page);
            await page.locator('[data-role=settings]').evaluate(e=>e.open=true);
            await page.locator('[data-role=level]').selectOption('example-'+family);
            await act('[data-begin]');await measure('missing-'+family+'-'+width);
            if(family==='median'){
                await page.locator('[data-do=method][data-value=median]').click();
                for(const choice of await page.locator('[data-do=select]').all())await choice.click();
                await measure('missing-calculation-'+width);
            }
        }
        await page.goto(url+'/?game=missing-values');await ready(page);await act('[data-begin]');
        const missing=await state(page);
        if(missing.kind==='cohort')for(let i=0;i<missing.rows.length;i++)await page.locator('[data-do=select][data-id="'+i+'"]').click();
        await page.locator('[data-do=method]').first().click();await act('[data-do=restore]');await measure('missing-review-'+width);
        await page.goto(url+'/?game=data-structure-lab');await ready(page);await act('[data-begin]');
        await act('[data-do=push][data-lane=stack]');await act('[data-do=pop][data-lane=stack]');await measure('warehouse-feedback-'+width);
        await page.goto(url+'/?game=ai-model-lab');await ready(page);await act('[data-begin]');
        for(let i=0;i<9;i++){await page.locator('[data-digit="'+i+'"]').first().click();await act('[data-do=teach]');}
        await measure('full-memory-'+width);
        await page.goto(url+'/?game=device-matching');await page.locator('[data-action=start]').click();
        await page.locator('.edu-game').evaluate(r=>{const g=r.__DeviceMatching;g.state.stageIndex=g.rules.stages.length-1;g.prepareStage();});
        await measure('cards-24-'+width);
        const textId=await page.locator('.edu-game').evaluate(r=>r.__DeviceMatching.state.cards.find(c=>c.type==='text').id);
        await page.locator('[data-card-id="'+textId+'"]').click();
        if(width===390){await page.waitForTimeout(60);assert((await page.locator('.eg-card-inspector').innerText()).length>10,'compact card keeps its full description');}
        await measure('card-description-'+width);
        await page.goto(url+'/?game=binary-arcade');await page.locator('[data-action=practice]').first().click();
        for(let i=1;i<8;i++)await page.locator('[data-action=practice]').first().click();
        await measure('binary-eight-'+width);
        assert(await page.locator('.edu-game').evaluate(r=>{const g=r.__BinaryArcade,s=r.querySelector('.oj-binary-shell'),h=parseFloat(getComputedStyle(s).getPropertyValue('--binary-row-height'));return g.state.problems.length===8&&g.getRowMetrics().rowHeight===h;}),'eight-row physics matches CSS height');
        await page.keyboard.press('Escape');assert.equal(await page.locator('.eg-focus-dialog').count(),0);
        assert.equal(await page.locator('.edu-game').evaluate(r=>r.__BinaryArcade.state.problems.length),8,'exit retains all eight rows');
        await page.goto(url+'/?game=image-data');await page.locator('[data-mode=draw]').click();
        await page.locator('.edu-game').evaluate(e=>e.__GameViewport.open());await measure('image-create-'+width);
        await page.locator('[data-color="2"]').click();await page.locator('[data-pixel="0"]').click();
        await page.locator('[data-pixel="1"]').hover();assert(await page.locator('.edu-game').evaluate(r=>r.__ImageData.state.pixels[0]==='2'&&r.__ImageData.state.pixels[1]==='0'),'painting stops on pointer release');
        for(const mode of ['decode','encode','rebuild']){
            await page.goto(url+'/?game=character-code');
            await page.locator('[data-field=difficulty]').selectOption('hard');
            await page.locator(`[data-mode="${mode}"]`).click();
            await page.locator('.edu-game').evaluate(root=>{
                const game=root.__CharacterCode;
                const phrases=game.getDifficulty().phrases;
                const longest=[...phrases].sort((a,b)=>b.length-a.length)[0];
                const original=game.pickPhrase;
                game.pickPhrase=()=>longest;game.newPracticeRound();game.pickPhrase=original;
                root.__GameViewport.open();
            });
            await measure('character-'+mode+'-'+width);
        }
    }
    for(const m of extra)assert(m.y<=1&&m.x<=1,m.label+' fits: '+JSON.stringify(m));
    console.log(extra.length+' populated and feedback viewport layouts passed');
};
