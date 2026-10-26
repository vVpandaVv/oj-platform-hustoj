/* Real board renderer, JavaScript and PHP transitions; isolated HTTP/DB fixture. */
const fs=require('node:fs'), path=require('node:path'), http=require('node:http'), assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'../../_archived/readcontents-library/node_modules/playwright');
const root=path.resolve(__dirname,'..'), out=path.resolve(root,'../_notes/tmp/edu-games-v2-preview');
fs.mkdirSync(out,{recursive:true});
function php(input,raw=false){const r=spawnSync(process.env.PHP_BINARY||'D:/php/php.exe',['tests/browser-fixture.php'],{cwd:root,input:JSON.stringify({game:'warehouse',...input}),encoding:'utf8'});if(r.status!==0)throw Error(r.stderr||r.stdout);return raw?r.stdout:JSON.parse(r.stdout);}
const catalog=php({action:'catalog'}), sessions=new Map(), best={};let id=0,fail=false,last=null;
const server=http.createServer(async(req,res)=>{
    try{
        const url=new URL(req.url,'http://localhost');
        if(url.pathname.startsWith('/plugins/edu-games/')){
            const file=path.resolve(root,decodeURIComponent(url.pathname.slice(19)));
            if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.writeHead(404).end();return;}
            res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':file.endsWith('.svg')?'image/svg+xml':'application/octet-stream');res.end(fs.readFileSync(file));return;
        }
        if(req.method==='GET'){
            res.setHeader('Content-Type','text/html;charset=utf-8');res.end(`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>물류 기지 검증</title><style>body{margin:0;background:#fff}main{max-width:1120px;margin:auto}</style><link rel="stylesheet" href="/plugins/edu-games/assets/warehouse/style.css"><link rel="stylesheet" href="/plugins/edu-games/assets/learning/style.css"><link rel="stylesheet" href="/plugins/edu-games/assets/rankings/pager.css"><main>${php({action:'html'},true)}</main><script src="/plugins/edu-games/assets/rankings/pager.js"></script><script src="/plugins/edu-games/assets/warehouse/view.js"></script></html>`);return;
        }
        let body='';for await(const part of req)body+=part;const input=JSON.parse(body);const action=url.pathname.split('/').pop();res.setHeader('Content-Type','application/json');
        if(action==='summary'){
            let unlocked=true,stars=0;const levels={};
            Object.values(catalog.levels).forEach(l=>{const score=best[l.id]||0;const count=score>=975?3:score>=850?2:score>0?1:0;levels[l.id]={id:l.id,number:l.number,title:l.title,score,stars:count,unlocked};stars+=count;unlocked=unlocked&&score>0;});
            res.end(JSON.stringify({levels,stars,maxStars:36,level:input.level,leaderboard:[]}));return;
        }
        let record;
        if(action==='start'){
            record={...php({action,level:input.level}),token:`test-board-${++id}`,revision:0};sessions.set(record.token,record);
        }else{
            record=sessions.get(input.token);if(!record){res.writeHead(404).end(JSON.stringify({message:'검증용 만료'}));return;}
            if(action==='update'){
                if(fail){fail=false;res.writeHead(503).end(JSON.stringify({message:'검증용 저장 실패'}));return;}
                if(record.last!==input.requestId){Object.assign(record,php({action,state:record.state,move:input.move}),{revision:record.revision+1,last:input.requestId});}
            }
        }
        last=record;
        if(record.view.status==='won')best[record.view.level]=Math.max(best[record.view.level]||0,record.view.score);
        res.end(JSON.stringify({...record.view,token:record.token,revision:record.revision}));
    }catch(error){res.writeHead(500).end(JSON.stringify({message:error.message}));}
});
async function main(){
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
    const page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));let checks=0;
    const base=`http://127.0.0.1:${server.address().port}`;
    const ready=()=>page.waitForFunction(()=>document.querySelector('[data-warehouse]').getAttribute('aria-busy')!=='true');
    const act=async move=>{
        const before=last?.revision||0;
        if(move.type==='transfer')await page.locator(`[data-transfer="${move.from}"]`).selectOption(move.to);
        const response=page.waitForResponse(r=>r.url().endsWith('/warehouse/update')&&r.status()===200);
        await page.locator(`[data-move="${move.type}"]${move.from?`[data-from="${move.from}"]`:''}${move.type==='load'?`[data-to="${move.to}"]`:''}`).click();
        await response;await ready();assert.equal(last.revision,before+1);checks++;
    };
    try{
        await page.goto(base);await page.locator('[data-level="route-01"]').waitFor();assert(await page.locator('[data-level="route-02"]').isDisabled());checks++;
        await page.addScriptTag({url:base+'/plugins/edu-games/assets/warehouse/view.js'});
        for(const level of Object.keys(catalog.levels)){
            await page.locator(`[data-level="${level}"]`).click();
            if(level==='route-01'){
                assert.equal(id,0,'preview does not start a session');
                assert.equal(await page.locator('[data-warehouse]').evaluate(el=>el.__Warehouse.state),null);
                assert(await page.locator('[data-move="load"]').first().isEnabled(),'first cargo action available immediately');checks+=3;
                await page.screenshot({path:path.join(out,'warehouse-intuitive-preview.png'),fullPage:true});
            }else await page.locator('[data-warehouse] [data-command="start"]').click();
            await page.locator('[data-move="load"]').first().waitFor();await ready();
            for(const [index,move] of catalog.solutions[level].entries()){
                await act(move);
                if(level==='route-01'&&index===0){assert.equal(id,1,'first action starts exactly one session');assert.equal(last.view.moves,1,'first action is not discarded');checks+=2;}
                if(level==='route-02'&&index===3){
                    for(const width of [360,768,1280,1440]){
                        await page.setViewportSize({width,height:1000});
                        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`active board overflow ${width}`);checks++;
                        await page.screenshot({path:path.join(out,`warehouse-active-${width}.png`),fullPage:true});
                    }
                    await page.setViewportSize({width:1280,height:900});
                }
            }
            await page.locator('[data-result="won"]').waitFor();assert.equal(last.view.score,1000);checks++;
            assert((await page.locator('[data-role="collection"]').innerText()).includes(String(catalog.levels[level].number*3)));checks++;
        }
        for(const width of [360,768,1280,1440]){
            await page.setViewportSize({width,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`overflow ${width}`);checks++;
            await page.screenshot({path:path.join(out,`warehouse-${width}.png`),fullPage:true});
            assert(await page.locator('.ew-header img').evaluate(img=>img.complete&&img.naturalWidth>0),'crate asset');checks++;
        }
        assert((await page.locator('[data-role="personal"]').innerText()).includes('전체 구역 최단 기록 달성'));checks++;
        await page.setViewportSize({width:1280,height:900});await page.locator('[data-level="route-01"]').click();await page.locator('[data-warehouse] [data-command="start"]').click();await ready();
        fail=true;await page.locator('[data-move="load"][data-to="stack"]').click();await page.getByText('검증용 저장 실패',{exact:true}).waitFor();assert.equal(last.view.moves,0);checks++;
        await page.locator('[data-command="retry"]').click();await ready();assert.equal(last.view.moves,1);checks++;
        await page.reload();await page.locator('[data-warehouse] [data-command="resume"]').click();await ready();assert.equal(last.view.moves,1);checks++;
        await act({type:'ship',from:'stack'});assert.equal(last.view.mistakes,1);checks++;
        await act({type:'undo'});assert.equal(last.view.position.cursor,0);assert.equal(last.view.moves,3);checks++;
        await act({type:'load',to:'stack'});
        while(last.view.status==='playing')await act({type:'ship',from:'stack'});
        await page.locator('[data-result="lost"]').waitFor();assert.equal(last.view.score,0);checks++;
        await page.locator('[data-command="replay"]').click();await ready();assert.equal(last.view.moves,0);checks++;
        await page.locator('[data-move="load"][data-to="queue"]').focus();await page.keyboard.press('Enter');await ready();assert.equal(last.view.position.lanes.queue[0],'A');checks++;
        fail=true;await page.locator('[data-move="load"][data-to="stack"]').click();await page.getByText('검증용 저장 실패',{exact:true}).waitFor();
        sessions.delete(last.token);await page.locator('[data-warehouse] [data-command="resume"]').click();await page.getByText('검증용 만료',{exact:true}).waitFor();await ready();
        assert(await page.locator('[data-command="retry"]').isHidden());checks++;
        page.once('dialog',dialog=>dialog.accept());await page.locator('[data-warehouse] [data-command="start"]').click();await ready();assert.equal(last.view.moves,0);checks++;
        assert.deepEqual(errors,[]);console.log(`PASS: ${checks} warehouse browser checks, all 12 boards, 36 stars, retry/resume, loss, keyboard, four viewports.`);
    }catch(e){await page.screenshot({path:path.join(out,'warehouse-failure.png'),fullPage:true});throw e;}
    finally{await browser.close();server.close();}
}
main().catch(e=>{console.error(e);server.close();process.exitCode=1;});
