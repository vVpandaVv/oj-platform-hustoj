const assert=require('node:assert/strict'),path=require('node:path');
module.exports=async({page,url,out,ready})=>{
    let checks=0;
    const check=(ok,label)=>{assert(ok,label);checks++;};
    const layouts={
        'binary-arcade':'.oj-binary-console:has(>.oj-binary-sidebar):has(.oj-binary-board)',
        'device-matching':'.oj-device-table:has(.oj-device-table-head):has([data-field=deck])',
        'keyword-rain':'.oj-rain-flight:has(.oj-rain-arena):has(.oj-rain-input-row)',
        'code-typing':'.oj-typing-workbench:has(.oj-typing-controls):has([data-field=input])',
        'image-data':'.oj-image-workbench:has(.oj-image-palette-strip):has(.oj-image-commitbar [data-action=check])',
        'outlier-detection':'.eo-workspace:has(>.eo-observatory):has(>.eo-control-dock [data-do=scan])',
        'missing-values':'.em-repair-board:has(>.em-observation-bank):has(>.em-repair-targets .em-toolbox):has(.em-confirm-dock)',
        'data-extraction':'.ex-factory:has(.ex-machine-console):has(.ex-outfeed .ex-labels):has(.ex-outfeed [data-do=ship])',
        'data-visualization':'.er-workspace:has(>.er-plot-pane canvas):has(>.er-dispatch [data-visual-action=dispatch],>.er-dispatch [data-rescue=dispatch])',
        'data-structure-lab':'.wh-floor:has(.wh-route):has(.wh-workspace .wh-dispatch)',
        'ai-classifier-lab':'.cf-workspace:has(>.cf-plot-pane):has(>.cf-routing [data-do=classify])',
        'ai-model-lab':'.coach-machine:has(>.coach-contact-sheet):has(>.coach-label-dock):has(>.coach-inspector [data-do=finish])'
    };
    for(const viewport of [{width:1280,height:800},{width:834,height:1112},{width:390,height:844}]){
        await page.setViewportSize(viewport);
        for(const [game,selector]of Object.entries(layouts)){
            const response=await page.request.get(url+'/?game='+game),html=await response.text();
            check(html.includes('eg-layout-v2')&&html.includes('data-game-workspace='),game+' workspace is rendered by PHP, not simulated by common CSS');
            await page.goto(url+'/?game='+game);await page.waitForSelector('.edu-game[data-shell-ready]');
            if(await page.locator('[data-play]').count())await ready(page);
            check(await page.locator(selector).count()===1,game+' dedicated board and control topology');
            check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),game+' fits width '+viewport.width);
            if(game==='code-typing'){
                const panel=page.locator('[data-typing-assignment-panel]');
                check(!(await panel.getAttribute('open'))&&await panel.evaluate(e=>!e.open),'classroom starts collapsed');
                check((await panel.boundingBox()).height<=54,'classroom summary is one compact row');
                check(await page.locator('[data-typing-assignments]').isHidden(),'large assessment dashboard is not shown on entry');
            }
            if(game==='device-matching')check(await page.locator('.oj-device-preview-card').count()===12,'ready table contains six pairs, not a text placeholder');
            await page.screenshot({path:path.join(out,'layout-v2-'+game+'-'+viewport.width+'.png'),fullPage:true});
        }
    }
    await page.setViewportSize({width:1280,height:800});
    await page.goto(url+'/?game=code-typing');
    await page.locator('[data-typing-assignment-panel]>summary').click();
    check(await page.locator('[data-typing-assignments]').isVisible(),'student opens class participation deliberately');
    await page.locator('[data-typing-assignment-panel]>summary').click();
    check(await page.locator('[data-typing-assignments]').isHidden(),'student returns to the code board without navigation');
    console.log(checks+' dedicated-layout and compact-classroom browser checks passed');
};
