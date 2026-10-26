/* Real renderers and progress validator with isolated HTTP/roster storage. */
const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const {spawnSync}=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'../../_archived/readcontents-library/node_modules/playwright');
const root=path.resolve(__dirname,'..'),out=path.resolve(root,'../_notes/tmp/edu-games-v1');
fs.mkdirSync(out,{recursive:true});
function php(input){const p=spawnSync(process.env.PHP_BINARY||'D:/php/php.exe',['tests/browser-fixture.php'],{cwd:root,input:JSON.stringify(input),encoding:'utf8',maxBuffer:20e6});if(p.status!==0)throw Error(p.stderr);return p.stdout;}
const progress={},posts=[],errors=[];let checks=0,failNext=false,characterBestScore=4321;
const server=http.createServer(async(req,res)=>{
    try{
        const url=new URL(req.url,'http://localhost');
        const json=data=>{res.setHeader('Content-Type','application/json');res.end(JSON.stringify(data));};
        if(url.pathname.includes('/progress/')){
            assert.equal(url.searchParams.get('accountUserId'),'7');
            const game=url.pathname.split('/').at(-1);progress[game]??={};
            if(req.method==='POST'){
                const chunks=[];for await(const chunk of req)chunks.push(chunk);const payload=JSON.parse(Buffer.concat(chunks));
                if(failNext){failNext=false;res.statusCode=503;return json({message:'일시적인 연결 오류'});}
                const entries=JSON.parse(php({game,action:'progress',payload}));
                for(const [key,value]of Object.entries(entries))progress[game][key]=Math.max(progress[game][key]||0,value);
                posts.push({game,payload});
            }
            return json({records:progress[game],bestScore:game==='character-code'?characterBestScore:0});
        }
        if(url.pathname.includes('/leaderboards/'))return json({leaderboard:[],pagination:{page:1,perPage:20,total:0,totalPages:1}});
        if(url.pathname.endsWith('/reports/contexts'))return json({items:[{type:'class',id:'1',label:'1학년 1반',revision:'r1'},{type:'class',id:'2',label:'1학년 2반',revision:'r2'}],nextCursor:''});
        if(url.pathname.endsWith('/reports/students')){
            const size=Number(url.searchParams.get('perPage')),number=Number(url.searchParams.get('page')),total=30;
            const students=Array.from({length:total},(_,i)=>({name:`학생 ${i+1}`,attempts:i%3?2:0,completed:i%3?1:0,cleared:i%3?1:0,stars:i%3?3:0,best:i%3?950:null,first:i%3?2:null,final:i%3?3:null,total:i%3?3:null,last:i%3?'2026-09-12 01:20:00':null})).slice((number-1)*size,number*size);
            return json({students,participants:20,version:'fixture-version',pagination:{page:number,perPage:size,total,totalPages:Math.ceil(total/size)}});
        }
        if(['/assets/','/resources/','/blocks/'].some(p=>url.pathname.startsWith(p))){const file=path.resolve(root,'.'+url.pathname);if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.svg')?'image/svg+xml':'text/css');return res.end(fs.readFileSync(file));}
        const game=url.searchParams.get('game')||'console-report',report=game==='console-report';
        const css=report?'/resources/css/console-game-report.css':`/blocks/${game}/style.css`,js=report?'/resources/js/console-game-report.js':`/blocks/${game}/view.js`;
        const siteMenu=game==='binary-arcade'?'<nav data-site-menu style="position:fixed;inset:0 0 auto;height:56px;z-index:10;background:#fff;color:#111;border-bottom:1px solid #bbb;display:flex;align-items:center;padding:0 24px">사이트 메뉴</nav><style>main{padding-top:80px;padding-bottom:900px}</style>':'';
        res.setHeader('Content-Type','text/html;charset=utf-8');
        res.end(`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{margin:16px;font-family:Arial,sans-serif}main{max-width:1120px;margin:auto}</style><link rel="stylesheet" href="${css}"><link rel="stylesheet" href="/assets/rankings/pager.css"><link rel="stylesheet" href="/assets/shell/shell.css">${siteMenu}<main>${php({game,action:'html'})}</main><script src="/assets/rankings/pager.js"></script><script src="/assets/learning/progress.js"></script><script type="module" src="${js}"></script><script src="/assets/shell/shell.js"></script></html>`);
    }catch(e){errors.push(e.message);res.statusCode=400;res.end(JSON.stringify({message:e.message}));}
});
async function run(){
    await new Promise(r=>server.listen(0,'127.0.0.1',r));
    const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
    const base=`http://127.0.0.1:${server.address().port}`;
    const context=await browser.newContext(),page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
    try{
        await page.goto(base);await page.waitForFunction(()=>document.querySelectorAll('[data-rank-row]').length===20);
        assert.match(await page.locator('[data-field=status]').textContent(),/참여 20 \/ 30명/);checks++;
        await page.locator('[data-rank-page=next]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-rank-row]').length===10);
        assert.match(await page.locator('[data-rank-row]').first().textContent(),/학생 21/);checks++;
        await page.locator('[data-field=context]').selectOption('1');await page.waitForFunction(()=>document.querySelector('.edu-rank-pagination output')?.textContent==='1 / 2');
        assert.match(await page.locator('[data-field=status]').textContent(),/1학년 2반/);checks++;
        await page.locator('[data-field=game]').selectOption('outlier-detection');await page.locator('[data-field=mode]').selectOption('strategy');await page.waitForSelector('th:text-is("완주 보드")');checks++;
        for(const width of[360,1280]){await page.setViewportSize({width,height:900});assert(await page.locator('[data-game-report]').evaluate(r=>r.scrollWidth<=r.clientWidth+1));checks++;await page.screenshot({path:path.join(out,`report-${width}.png`),fullPage:true});}
        await page.goto(`${base}/?game=image-data`);await page.waitForFunction(()=>document.querySelector('[data-image-data]')?.__ImageData?.account?.synced);
        failNext=true;
        const sampleId=await page.evaluate(()=>{const game=document.querySelector('[data-image-data]').__ImageData,sample=game.rules.samples[0];Object.assign(game.state,{mode:'restore',sampleId:sample.id,pixels:sample.data.join('').split(''),accuracy:100,selectedAnswerId:sample.id});game.collectSolved();return sample.id;});
        await page.getByRole('button',{name:'계정 기록 다시 동기화'}).click();
        await page.waitForFunction(()=>document.querySelector('.edu-progress-status').textContent==='계정 기록 동기화됨');
        assert.equal(progress['image-data'][sampleId],1);checks++;
        const second=await browser.newContext(),fresh=await second.newPage();
        await fresh.goto(`${base}/?game=image-data`);await fresh.waitForFunction(()=>document.querySelector('[data-image-data]')?.__ImageData?.account?.synced);
        assert(await fresh.evaluate(id=>document.querySelector('[data-image-data]').__ImageData.collection.has(id),sampleId));checks++;
        assert.match(await fresh.locator('[data-field=collection-progress]').textContent(),/계정/);checks++;
        await second.close();
        await page.goto(`${base}/?game=code-typing`);await page.waitForFunction(()=>document.querySelector('[data-code-typing]')?.__CodeTyping?.account?.synced);
        const input=page.locator('[data-field=input]');
        const target=await page.evaluate(()=>document.querySelector('[data-code-typing]').__CodeTyping.currentSample().content);
        await input.fill(target);await input.press('Enter');
        await page.waitForFunction(()=>document.querySelector('[data-code-typing]').__CodeTyping.state.status==='finished');
        await page.waitForFunction(()=>document.querySelector('.edu-progress-status').textContent==='계정 기록 동기화됨');
        assert(posts.some(p=>p.game==='code-typing'&&p.payload.key.endsWith(':keyword:streak')));checks++;
        const before=await input.inputValue();assert.equal(before,target);checks++;
        await page.evaluate(()=>{const g=document.querySelector('[data-code-typing]').__CodeTyping,s=g.rules.samples.find(s=>s.mode==='short');Object.assign(g.state,{language:s.language,mode:s.mode,sampleId:s.id});g.activeSample=s;g.resetSession(false);});
        const short=await page.evaluate(()=>document.querySelector('[data-code-typing]').__CodeTyping.currentSample().content);
        await input.fill(short);await input.press('Enter');
        await page.waitForFunction(()=>document.querySelector('.edu-progress-status').textContent==='계정 기록 동기화됨'&&document.querySelector('[data-code-typing]').__CodeTyping.state.status==='finished');
        assert(posts.some(p=>p.game==='code-typing'&&p.payload.key.includes(':short:')));checks++;
        assert.equal(await input.inputValue(),short);checks++;
        await page.goto(`${base}/?game=character-code`);
        await page.waitForFunction(()=>document.querySelector('.edu-progress-status')?.textContent==='계정 기록 동기화됨');
        assert.equal(await page.locator('[data-field=best]').textContent(),'4321');checks++;
        const answer=page.locator('[data-answer-input]');await answer.fill('A');
        const prompt=await page.locator('[data-field=prompt]').textContent();
        characterBestScore=5000;
        await page.evaluate(()=>document.querySelector('[data-character-code]').__CharacterCode.account.read());
        await page.waitForFunction(()=>document.querySelector('[data-field=best]').textContent==='5000'&&document.querySelector('.edu-progress-status').textContent==='계정 기록 동기화됨');
        assert.equal(await answer.inputValue(),'A');checks++;
        assert(await answer.evaluate(input=>document.activeElement===input));checks++;
        assert.equal(await page.locator('[data-field=prompt]').textContent(),prompt);checks++;
        await page.screenshot({path:path.join(out,'character-account-sync.png'),fullPage:true});
        for(const width of[360,1280]){
            await page.setViewportSize({width,height:900});
            await page.goto(`${base}/?game=binary-arcade`);
            await page.waitForFunction(()=>document.querySelector('[data-binary-arcade]')?.__BinaryArcade);
            for(const selector of['.oj-binary-overlay','.oj-binary-row']){
                if(selector==='.oj-binary-row'){
                    await page.evaluate(()=>scrollTo(0,0));
                    await page.locator('[data-action=practice]').first().click();
                    await page.waitForSelector('.eg-focus-dialog');await page.locator('.edu-game').evaluate(e=>e.__GameViewport.close());
                }
                const x=await page.locator(selector).first().evaluate(element=>{
                    const rect=element.getBoundingClientRect();
                    scrollTo(0,scrollY+rect.top+Math.min(40,rect.height/2)-28);
                    return Math.min(innerWidth-10,Math.max(10,rect.left+rect.width/2));
                });
                await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
                assert(await page.evaluate(x=>!!document.elementFromPoint(x,28)?.closest('[data-site-menu]'),x),`${selector} must stay below site menu at ${width}`);checks++;
                const bounds=await page.locator(selector).first().boundingBox();
                assert(bounds.y<28&&bounds.y+bounds.height>28,'test scroll must overlap the menu');checks++;
                await page.screenshot({path:path.join(out,`binary-menu-${selector.includes('overlay')?'overlay':'row'}-${width}.png`)});
            }
        }
        assert.deepEqual(errors,[]);checks++;
        console.log(`browser-v1: ${checks} passed (isolated account/roster fixtures)`);
    }finally{await browser.close();await new Promise(r=>server.close(r));}
}
run().catch(e=>{console.error(e);process.exitCode=1;});
