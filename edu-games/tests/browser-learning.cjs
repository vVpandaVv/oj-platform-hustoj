/* Real renderers, browser assets and PHP Run; WP transport/database are isolated doubles. */
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { spawnSync } = require('node:child_process');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || '../../_archived/readcontents-library/node_modules/playwright');
const root = path.resolve(__dirname,'..');
const out = path.resolve(root,'../_notes/tmp/edu-games-v2-preview'); fs.mkdirSync(out,{recursive:true});
const php = process.env.PHP_BINARY || 'D:/php/php.exe';
const runPHP = (args, input) => { const r=spawnSync(php,args,{cwd:root,input:input?JSON.stringify(input):undefined,encoding:'utf8',maxBuffer:20e6}); if(r.status!==0)throw Error(r.stderr||r.stdout);return r.stdout; };
const fixture = (data) => JSON.parse(runPHP(['tests/browser-fixture.php'],data));
const bank = JSON.parse(runPHP(['tests/learning-content.php','json']));
const sessions = new Map(); let nextId=0; let fixtureId=null; let failNext=false;
const server = http.createServer(async(req,res)=>{
    try{
        const url=new URL(req.url,'http://localhost');
        if(url.pathname.startsWith('/plugins/edu-games/')){
            const file=path.resolve(root,decodeURIComponent(url.pathname.slice('/plugins/edu-games/'.length)));
            if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.writeHead(404).end();return;}
            res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':file.endsWith('.png')?'image/png':'image/jpeg');res.end(fs.readFileSync(file));return;
        }
        if(req.method==='GET'){
            const game=url.searchParams.get('game')||'data-extraction';
            const html=runPHP(['tests/browser-fixture.php'],{game,action:'html',learning:url.searchParams.get('legacy')!=='1'&&Object.hasOwn(bank,game)});
            const legacy=['image-data','binary-arcade','character-code','device-matching','code-typing','keyword-rain'].includes(game);
            res.setHeader('Content-Type','text/html; charset=utf-8');res.end(`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>학습 브라우저 검증</title><style>body{margin:0;padding:16px;font-family:Arial,sans-serif}main{max-width:1120px;margin:auto}button,input,select{font:inherit}</style><link rel="stylesheet" href="/plugins/edu-games/assets/learning/style.css">${legacy?`<link rel="stylesheet" href="/plugins/edu-games/blocks/${game}/style.css">`:''}<link rel="stylesheet" href="/plugins/edu-games/assets/rankings/pager.css"><main>${html}</main><script src="/plugins/edu-games/assets/rankings/pager.js"></script><script src="/plugins/edu-games/assets/vendor/chartjs/chart.umd.min.js"></script><script src="/plugins/edu-games/assets/learning/view.js"></script>${legacy?`<script src="/plugins/edu-games/blocks/${game}/view.js"></script>`:''}</html>`);return;
        }
        let body='';for await(const chunk of req)body+=chunk;const input=JSON.parse(body||'{}');
        const match=url.pathname.match(/\/learning\/([a-z-]+)\/(start|update|resume|summary)$/);
        res.setHeader('Content-Type','application/json');
        if(!match){res.end(JSON.stringify({leaderboard:[],entries:[],sessionToken:'fixture-token'}));return;}
        const [,game,action]=match;
        if(action==='summary'){res.end(JSON.stringify({history:[],leaderboard:[],level:input.level}));return;}
        if(failNext&&action==='update'){failNext=false;res.writeHead(503).end(JSON.stringify({message:'검증용 일시 오류'}));return;}
        let record;
        if(action==='start'){
            const data=fixture({game,action,mode:input.mode,level:input.level,fixtureId});
            record={...data,mode:input.mode,level:input.level,token:`fixture-${++nextId}`,revision:0};sessions.set(record.token,record);
        }else{
            record=sessions.get(input.token);if(!record)throw Error('fixture missing session');
            if(action==='update'){
                if(record.last!==input.requestId){const data=fixture({game,action,mode:record.mode,state:record.state,payload:input});Object.assign(record,data,{revision:record.revision+1,last:input.requestId});}
            }
        }
        res.end(JSON.stringify({...record.view,mode:record.mode,level:record.level,token:record.token,revision:record.revision}));
    }catch(e){res.writeHead(500).end(JSON.stringify({message:e.message}));}
});
async function fill(page, entry){
    for(const field of entry.question.fields){
        const locator=page.locator(`[data-answer="${field.key}"]`);const answer=entry.answer[field.key];
        if(field.type==='multi'){for(const input of await locator.all()){await input.setChecked(answer.includes(await input.getAttribute('value')));}}
        else if(field.type==='choice')await locator.selectOption(answer);
        else await locator.fill(Array.isArray(answer)?answer.join(', '):String(answer));
    }
}
async function main(){
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const base=`http://127.0.0.1:${server.address().port}`;
    const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
    const context=await browser.newContext({viewport:{width:1280,height:900}});const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
    let checks=0,submissions=0;
    try{
        for(const [game,entries] of Object.entries(bank)){
            if(process.env.GAME && process.env.GAME!==game)continue;
            console.log(`Checking ${game}`);
            for(const entry of (process.env.QUICK ? entries.slice(0,1) : entries).filter(e=>!process.env.CASE||new RegExp(process.env.CASE).test(e.question.id))){
                fixtureId=entry.question.id;await page.goto(`${base}/?game=${game}`);
                await page.locator('[data-command="answer"]').waitFor();await fill(page,entry);
                await page.locator('[data-command="answer"]').click();await page.getByRole('heading',{name:'정답입니다',exact:true}).waitFor();checks++;submissions++;
                if(entry===entries[0]){
                    for(const width of [360,768,1280,1440]){
                        await page.setViewportSize({width,height:900});
                        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
                        await page.screenshot({path:path.join(out,'latest-layout.png'),fullPage:true});
                        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${game} overflow ${width}`);
                        if(width===360||width===1280)await page.screenshot({path:path.join(out,`${game}-${width}.png`),fullPage:true});checks++;
                    }
                    const canvas=page.locator('[data-learning] canvas');
                    if(await canvas.count())assert(await canvas.evaluate(c=>{const p=c.getContext('2d').getImageData(0,0,c.width,c.height).data;return p.some((v,i)=>i%4===3&&v>0);}),`${game} blank canvas`);
                }
                await page.locator('[data-command="next"]').click();await page.getByRole('heading',{name:'세트 결과',exact:true}).waitFor();
                await page.evaluate(()=>localStorage.clear());
            }
        }
        fixtureId=bank['data-extraction'][0].question.id;
        await page.goto(`${base}/?game=data-extraction`);await page.locator('[data-command="answer"]').waitFor();
        await page.locator('.edu-learn-query summary').click();
        await page.locator('[data-query-column="0"]').selectOption('재고');await page.locator('[data-query-op="0"]').selectOption('lt');await page.locator('[data-query-value="0"]').fill('3');
        await page.locator('[data-query-apply]').click();assert.equal(await page.locator('[data-answer="rowIds"]').inputValue(),'B, D');checks++;
        await page.locator('[data-command="answer"]').click();await page.getByRole('heading',{name:'다시 확인할 부분이 있습니다'}).waitFor();
        await fill(page,bank['data-extraction'][0]);failNext=true;
        await page.locator('[data-command="answer"]').click();await page.getByText('검증용 일시 오류').waitFor();
        assert.equal(await page.locator('[data-answer="rowIds"]').inputValue(),bank['data-extraction'][0].answer.rowIds.join(', '));
        await page.locator('[data-command="answer"]').click();await page.getByRole('heading',{name:'정답입니다',exact:true}).waitFor();
        assert((await page.locator('.edu-learn-result').innerText()).includes('첫 답: 오답'));
        await page.reload();await page.getByRole('heading',{name:'정답입니다',exact:true}).waitFor();checks+=3;
        for(const game of ['image-data','binary-arcade','character-code']){
            await page.goto(`${base}/?game=${game}`);
            await page.locator(`[data-${game}]`).waitFor();
            for(const width of [360,768,1280,1440]){await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${game} overflow ${width}`);checks++;}
            await page.screenshot({path:path.join(out,`${game}-1280.png`),fullPage:true});
        }
        await page.goto(`${base}/?game=image-data`);await page.locator('[data-pixel="0"]').waitFor();
        await page.locator('[data-field="grid-size"]').selectOption('8');assert.equal(await page.locator('[data-pixel]').count(),64);checks++;
        await page.locator('[data-action="check"]').click();assert.equal(await page.locator('[data-field="accuracy"]').innerText(),'0.0%');checks++;
        await page.locator('[data-mode="draw"]').click();await page.locator('[data-color="2"]').click();
        const pixel=(i)=>page.locator(`[data-pixel="${i}"]`);await pixel(0).click();
        const b=await pixel(1).boundingBox();await page.mouse.move(b.x+b.width/2,b.y+b.height/2);
        assert((await pixel(0).getAttribute('aria-label')).endsWith(' 2'));assert((await pixel(1).getAttribute('aria-label')).endsWith(' 0'));checks++;
        const first=await pixel(2).boundingBox(),last=await pixel(4).boundingBox(),after=await pixel(5).boundingBox();
        await page.mouse.move(first.x+first.width/2,first.y+first.height/2);await page.mouse.down();await page.mouse.move(last.x+last.width/2,last.y+last.height/2,{steps:5});await page.mouse.up();await page.mouse.move(after.x+after.width/2,after.y+after.height/2);
        assert((await pixel(4).getAttribute('aria-label')).endsWith(' 2'));assert((await pixel(5).getAttribute('aria-label')).endsWith(' 0'));checks++;
        await page.locator('[data-action="undo"]').click();assert((await pixel(2).getAttribute('aria-label')).endsWith(' 0'));assert((await pixel(0).getAttribute('aria-label')).endsWith(' 2'));checks++;
        await pixel(0).focus();await page.keyboard.press('ArrowRight');await page.keyboard.press('Space');assert((await pixel(1).getAttribute('aria-label')).endsWith(' 2'));checks++;
        const pending=page.waitForEvent('download');await page.locator('[data-action="export"]').click();const download=await pending;const exported=JSON.parse(fs.readFileSync(await download.path(),'utf8'));assert.equal(exported.size,8);assert.equal(exported.rows[0].slice(0,2),'22');checks++;
        const importFile=page.locator('[data-field="import"]');await importFile.setInputFiles({name:'invalid.json',mimeType:'application/json',buffer:Buffer.from('{"size":9}')});await page.getByText('크기·색상 코드·팔레트를 확인하세요.',{exact:true}).waitFor();assert((await pixel(0).getAttribute('aria-label')).endsWith(' 2'));checks++;
        await page.locator('[data-action="clear"]').click();await importFile.setInputFiles({name:'valid.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(exported))});await page.getByText('이미지 배열을 불러왔습니다.',{exact:true}).waitFor();assert((await pixel(1).getAttribute('aria-label')).endsWith(' 2'));checks++;
        await page.screenshot({path:path.join(out,'image-8x8.png'),fullPage:true});
        await page.locator('[data-mode="restore"]').click();
        const restoreId=await page.locator('[data-field="sample"]').inputValue();
        const imageConfig=JSON.parse(await page.locator('[data-image-data]').getAttribute('data-config'));
        const restoreSample=imageConfig.rules.samples.find(sample=>sample.id===restoreId);
        const restorePixels=restoreSample.data.join('').split('');
        for(const color of new Set(restorePixels.filter(c=>c!=='0'))){
            await page.locator(`[data-color="${color}"]`).click();
            for(let i=0;i<restorePixels.length;i++)if(restorePixels[i]===color)await pixel(i).click();
        }
        await page.locator('[data-action="check"]').click();await page.locator(`[data-answer="${restoreId}"]`).click();
        assert((await page.locator('[data-field="collection-progress"]').innerText()).includes('1 / 45'));checks++;
        await page.locator('[data-action="check"]').click();assert((await page.locator('[data-field="collection-progress"]').innerText()).includes('1 / 45'));checks++;
        await page.locator('.oj-image-collection summary').click();await page.screenshot({path:path.join(out,'image-collection.png'),fullPage:true});
        await page.reload();assert((await page.locator('[data-field="collection-progress"]').innerText()).includes('1 / 45'));checks++;
        await page.goto(`${base}/?game=device-matching&legacy=1`);await page.locator('[data-action="start"]').click();
        for(let stage=0;stage<4;stage++){
            // Card ids are opaque now: read the pairs from the game state.
            const pairs=await page.evaluate(stage=>{const cards=document.querySelector('[data-device-matching]').__DeviceMatching.state.cards;return cards.filter(c=>c.type==='image').map(c=>[c.id,cards.find(t=>t.type==='text'&&t.pairId===c.pairId).id]);},stage);
            assert.equal(pairs.length,6+2*stage);
            for(const [imageId,textId] of pairs){
                const imageCard=page.locator(`[data-card-id="${imageId}"]`);
                assert.equal(await imageCard.getAttribute('aria-label'),'뒤집힌 카드');
                await imageCard.click();await page.locator(`[data-card-id="${textId}"]`).click();
                await page.waitForFunction(id=>document.querySelector(`[data-card-id="${id}"]`).classList.contains('is-matched'),imageId);checks++;
            }
            if(stage<3)await page.locator('[data-action="next-stage"]').click();
        }
        await page.waitForFunction(()=>document.querySelector('[data-field="challenge-goal"]').textContent.includes('12 / 12'));
        assert((await page.locator('[data-field="challenge-goal"]').innerText()).includes('무오답'));checks++;
        await page.reload();assert((await page.locator('[data-field="challenge-goal"]').innerText()).includes('12 / 12'));checks++;
        await page.locator('[data-action="start"]').click();
        const replay=await page.evaluate(()=>{const cards=document.querySelector('[data-device-matching]').__DeviceMatching.state.cards;return cards.filter(c=>c.type==='image').map(c=>[c.id,cards.find(t=>t.type==='text'&&t.pairId===c.pairId).id]);});
        for(let miss=0;miss<8;miss++){
            await page.locator(`[data-card-id="${replay[0][0]}"]`).click();await page.locator(`[data-card-id="${replay[1][1]}"]`).click();
            await page.waitForFunction(id=>document.querySelector(`[data-card-id="${id}"]`).getAttribute('aria-label')==='뒤집힌 카드',replay[0][0]);
        }
        await page.locator(`[data-card-id="${replay[0][0]}"]`).click();await page.locator(`[data-card-id="${replay[0][1]}"]`).click();
        await page.waitForFunction(id=>document.querySelector(`[data-card-id="${id}"]`).classList.contains('is-matched'),replay[0][0]);checks++;
        // Face-down cards keep no answer in the markup.
        assert.equal(await page.locator('.oj-device-card:not(.is-face-up) img').count(),0);checks++;
        await page.goto(`${base}/?game=code-typing&legacy=1`);await page.locator('[data-mode="short"]').click();
        const typing=page.locator('[data-code-typing]');const config=JSON.parse(await typing.getAttribute('data-config'));
        const sampleId=await typing.locator('[data-field="sample"]').inputValue();const sample=config.rules.samples.find(s=>s.id===sampleId);
        const input=typing.locator('[data-field="input"]');
        await input.fill(sample.content);assert(await typing.locator('.is-running').count());
        await input.press('Enter');await typing.locator('.is-finished').waitFor();
        const score=await typing.locator('[data-field="score"]').innerText();await input.press('a');assert.equal(await input.inputValue(),sample.content);assert.equal(await typing.locator('[data-field="score"]').innerText(),score);checks++;
        if(typeof sample.expectedOutput==='string'){await typing.locator('[data-field="expected-output"]').waitFor();assert.equal(await typing.locator('[data-field="expected-output"] pre').textContent(),sample.expectedOutput);checks++;}
        await input.press('Space');assert.equal(await input.inputValue(),'');await input.fill('x');assert(await typing.locator('.is-running').count());checks++;
        await page.goto(`${base}/?game=learning-report`);await page.getByRole('heading',{name:'내 학습 기록',exact:true}).waitFor();
        await page.locator('[data-role="report-game"]').selectOption('missing-values');await page.getByRole('heading',{name:'내 학습 기록',exact:true}).waitFor();checks++;
        assert.deepEqual(errors,[]);console.log(`PASS: ${checks} browser checks; ${submissions} canonical submissions, retry/resume, desktop/mobile. Artifacts: ${out}`);
    }catch(error){await page.screenshot({path:path.join(out,'failure.png'),fullPage:true});console.error(await page.locator('body').innerText(),errors);throw error;}
    finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
}
main().catch(e=>{console.error(e);server.close();process.exitCode=1;});
