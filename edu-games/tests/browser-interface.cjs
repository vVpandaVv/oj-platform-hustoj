const assert=require('node:assert/strict'),path=require('node:path');
module.exports=async({page,url,out,ready})=>{
    let checks=0;
    const check=(value,label)=>{assert(value,label);checks++;};
    const games=['outlier-detection','missing-values','data-visualization','data-extraction','data-structure-lab','ai-classifier-lab','ai-model-lab','binary-arcade','character-code','code-typing','device-matching','image-data','keyword-rain'];
    const go=async game=>{
        await page.goto(url+'/?game='+game);
        await page.waitForSelector('.edu-game[data-shell-ready=true]');
        if(games.indexOf(game)<7)await ready(page);
    };
    for(const game of games){
        for(const width of [1280,390]){
            await page.setViewportSize({width,height:1000});await go(game);
            if(game==='device-matching'){await page.locator('[data-action=start]').click();await page.waitForSelector('.eg-focus-dialog');await page.locator('.edu-game').evaluate(e=>e.__GameViewport.close());}
            if(game==='binary-arcade'){
                await page.locator('[data-action=practice]').first().click();
                await page.waitForSelector('.eg-focus-dialog');await page.locator('.edu-game').evaluate(e=>e.__GameViewport.close());
                await page.locator('.oj-binary-overlay').evaluate(e=>Promise.all(e.getAnimations().map(a=>a.finished)));
                check((await page.locator('.oj-binary-sidebar').boundingBox()).height<300,'compact binary HUD');
                check((await page.locator('.oj-binary-board').boundingBox()).height<950,'eight-row binary board fits compact layout');
                check(await page.locator('.oj-binary-answer-output').evaluateAll(es=>es.every(e=>getComputedStyle(e).color==='rgb(29, 29, 31)')),'binary sum readable');
            }
            check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),game+' page overflow '+width);
            const size=await page.locator('.edu-game').boundingBox();
            check(Math.abs(size.width-Math.min(1120,width-32))<2,game+' shared width '+width);
            check(await page.locator('.eg-game-header h2').evaluate(e=>getComputedStyle(e).fontSize==='24px'),game+' consistent title');
            check(await page.locator('.edu-game').evaluate(e=>getComputedStyle(e).isolation==='isolate'),game+' isolated layers');
            check(await page.locator('.eg-game-back').count()===1,game+' hub navigation');
            if(game==='device-matching'){
                const columns=await page.locator('.oj-device-deck').evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length);
                check(columns===(width===1280?4:2),'card deck full rows');
            }
            if(game==='data-visualization'){
                check(await page.locator('canvas').first().evaluate(c=>c.width>0&&[...c.getContext('2d').getImageData(0,0,c.width,c.height).data].some((n,i)=>i%4===3&&n>0)),'visible graph canvas');
            }
            if(game==='character-code')check((await page.locator('.oj-code-submit-row>button').boundingBox()).width>=80,'submit label fits');
            if(game==='code-typing')check(await page.locator('.oj-typing-keyboard').evaluate(e=>e.scrollWidth<=e.clientWidth+1),'all keyboard keys fit');
            await page.screenshot({path:path.join(out,`interface-${game}-${width}.png`),fullPage:true});
        }
    }
    await go('code-typing');
    const target=await page.evaluate(()=>document.querySelector('[data-code-typing]').__CodeTyping.currentSample().content);
    await page.locator('[data-field=input]').fill(target);await page.locator('[data-field=input]').press('Enter');
    await page.waitForFunction(()=>document.querySelector('[data-code-typing]').__CodeTyping.state.status==='finished');
    check(await page.locator('[data-field=input]').inputValue()===target,'typing result retained');
    check(await page.locator('.oj-typing-char.is-correct').first().evaluate(e=>getComputedStyle(e).color==='rgb(22, 125, 72)'),'correct code is readable on light background');

    await page.setViewportSize({width:1280,height:1000});
    let deletes=0,failed=true,removed=false,admin=true;
    const deleteUrl='/edu-games/v1/leaderboards/game/character-code/records/score/91';
    await page.route('**/leaderboards/game/character-code?*',route=>route.fulfill({json:{leaderboard:[{rank:1,playerName:'검증 학생',score:removed?800:1200,accuracy:100,correctCount:8,durationSeconds:20,...(admin?{deleteUrl}: {})}],pagination:{page:1,perPage:20,total:1}}}));
    await page.route('**/records/score/91',route=>{
        check(route.request().method()==='DELETE','deletion uses DELETE');
        check(route.request().headers()['x-wp-nonce']==='fixture-nonce','deletion sends nonce');
        deletes++;
        if(failed){failed=false;return route.fulfill({status:503,json:{message:'테스트 저장 오류'}});}
        removed=true;return route.fulfill({json:{deleted:true}});
    });
    await go('character-code');
    await page.locator('[data-shell-section=records]>summary').click();
    await page.setViewportSize({width:390,height:1000});
    await page.waitForSelector('.edu-rank-delete');
    check(await page.locator('.edu-rank-delete').evaluate(e=>{const r=e.getBoundingClientRect(),p=e.closest('[data-rank-row]').getBoundingClientRect();return r.left>=p.left&&r.right<=p.right;}),'mobile deletion button stays inside row');
    await page.locator('.edu-rank-delete').click();
    check(await page.locator('.edu-rank-dialog [data-record-label]').textContent()==='검증 학생 · 1200점','confirmation names target');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile confirmation fits');
    await page.locator('.edu-rank-dialog [data-cancel]').click();check(deletes===0,'cancel makes no request');
    await page.locator('.edu-rank-delete').click();await page.locator('.edu-rank-dialog [data-confirm]').click();
    await page.waitForSelector('.edu-rank-dialog [data-delete-status]:text-is("테스트 저장 오류")');
    check(await page.locator('.edu-rank-delete').count()===1,'failure preserves visible ranking');
    await page.locator('.edu-rank-dialog [data-confirm]').click();
    await page.waitForSelector('.edu-rank-delete[title*="800점"]');
    check(await page.locator('.edu-rank-dialog').count()===0&&deletes===2,'successful deletion reloads next best');
    check(await page.locator('[data-rank-size]').evaluate(e=>document.activeElement===e),'focus restored after ranking refresh');
    admin=false;await go('character-code');await page.locator('[data-shell-section=records]>summary').click();
    await page.waitForSelector('[data-rank-row]');check(await page.locator('.edu-rank-delete').count()===0,'learner has no deletion button');

    let runDeleted=false;
    await page.route('**/play/outlier-detection/summary',async route=>{
        const response=await route.fetch(),data=await response.json();
        data.levels['board-01'].score=runDeleted?500:900;
        data.leaderboard=[{rank:1,name:'관리자 검증',score:runDeleted?500:900,deleteUrl:'/edu-games/v1/leaderboards/game/outlier-detection/records/run/17'}];
        data.pagination={page:1,perPage:20,total:1};await route.fulfill({json:data});
    });
    await page.route('**/records/run/17',route=>{runDeleted=true;return route.fulfill({json:{deleted:true}});});
    await go('outlier-detection');await page.locator('[data-role=records]>summary').click();
    await page.locator('.edu-rank-delete').click();await page.locator('.edu-rank-dialog [data-confirm]').click();
    await page.waitForSelector('.edu-rank-delete[title*="500점"]');
    await page.waitForFunction(()=>document.querySelector('[data-personal-best]').textContent==='500');
    check(runDeleted,'run record deletion updates HUD and rankings');
    console.log(checks+' shared-interface and ranking moderation browser checks passed');
};
