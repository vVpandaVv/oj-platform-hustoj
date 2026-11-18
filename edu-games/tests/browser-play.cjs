/* New game loops using real PHP domains/renderers and isolated HTTP/storage doubles. */
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const { spawnSync } = require('node:child_process');
const assert = require('node:assert/strict');
const { chromium, webkit } = require(process.env.PLAYWRIGHT_PATH || '../../_archived/readcontents-library/node_modules/playwright');
const hands = process.env.PLAY_LEGACY !== '1';
const root = path.resolve(__dirname, '..'), out = path.resolve(root, '../_notes/tmp/edu-games-'+(hands?'hands':'play')+(process.env.PLAY_BROWSER==='webkit'?'-webkit':''));
fs.mkdirSync(out, { recursive: true });
const run = (file, input, extraEnv = {}, options = []) => {
    const p = spawnSync(process.env.PHP_BINARY || 'D:/php/php.exe', [...options,file], { cwd: root, env: {...process.env,...extraEnv}, input: JSON.stringify(input), encoding: 'utf8', maxBuffer: 20e6 });
    if (p.status !== 0) throw Error(p.stderr || p.stdout);
    return p.stdout;
};
const fixture = input => JSON.parse(run(hands?'tests/hands-fixture.php':'tests/play-fixture.php', input));
const games = ['outlier-detection','missing-values','data-visualization','data-extraction','data-structure-lab','ai-classifier-lab','ai-model-lab'];
const classic = ['binary-arcade','character-code','code-typing','device-matching','image-data','keyword-rain'];
const catalogs = Object.fromEntries(games.map(game => [game, fixture({ game, action: 'catalog' })]));
const typingDb = path.join(out,'all-games-typing-'+process.pid+'.sqlite');
const typingFixture = input => JSON.parse(run('tests/typing-fixture.php',{db:typingDb,...input},{},['-d','extension=pdo_sqlite']));
const teacherPath = '/oj-console/addons/edu-games/typing-evaluations';
const hubPath = '/oj-addons/edu-games/games';
function seedTyping() {
    const catalog = typingFixture({actor:1,path:'/typing/catalog'}).data;
    const created = typingFixture({actor:1,method:'POST',path:'/typing/evaluations',body:{
        title:'미리보기 · 1·2반 코드 검정',language:'python',durationSeconds:60,minAccuracy:95,maxAttempts:3,
        sampleIds:catalog.samples.filter(s=>s.language==='python').slice(0,2).map(s=>s.id),
        contexts:[{type:'group',id:'g1',revision:'r1',label:'1반'},{type:'group',id:'g2',revision:'r1',label:'2반'}]
    }});
    if(created.error)throw Error(created.error);
    const opened=typingFixture({actor:1,method:'POST',path:'/typing/evaluations/'+created.data.evaluation.id+'/status',body:{status:'open'}});
    if(opened.error)throw Error(opened.error);
}
const sessions = new Map(), best = {};
let id = 0, fault = '', checks = 0;
const server = http.createServer(async (req, res) => {
    const reply = (data, status = 200) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(data)); };
    try {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname.startsWith('/plugins/edu-games/')) {
            const file = path.resolve(root, decodeURIComponent(url.pathname.slice('/plugins/edu-games/'.length)));
            if (!file.startsWith(root + path.sep) || !['.js','.css','.svg','.jpg','.png'].includes(path.extname(file)) || !fs.existsSync(file)) return reply({},404);
            res.writeHead(200, { 'Content-Type': {'.js':'application/javascript','.css':'text/css','.svg':'image/svg+xml','.jpg':'image/jpeg','.png':'image/png'}[path.extname(file)],'Cache-Control':'no-store' }); res.end(fs.readFileSync(file)); return;
        }
        if(url.pathname.includes('/progress/'))return reply({records:{},bestScore:0});
        if(url.pathname.includes('/leaderboards/'))return reply({leaderboard:[],pagination:{page:1,perPage:20,total:0}});
        if(url.pathname.endsWith('/session'))return reply({sessionToken:'preview-token'});
        if(url.pathname.endsWith('/scores'))return reply({score:0,rank:null,leaderboard:[]});
        const typingApi = /^\/(?:api\/(1|7)\/)?edu-games\/v1(\/(?:typing\/.*|reports\/contexts))$/.exec(url.pathname);
        if(typingApi){
            let raw='';for await(const chunk of req)raw+=chunk;
            const data=typingFixture({actor:Number(typingApi[1]||7),method:req.method,path:typingApi[2],query:Object.fromEntries(url.searchParams),body:JSON.parse(raw||'{}')});
            return data.error?reply({message:data.error},data.status):reply(data.data);
        }
        if (req.method === 'GET') {
            const teacher=url.pathname===teacherPath||url.pathname==='/teacher';
            const hub=url.pathname===hubPath||url.pathname==='/hub';
            const routed=/^\/oj-addons\/edu-games\/games\/([a-z-]+)\/?$/.exec(url.pathname)?.[1];
            const game = teacher?'typing-evaluations':hub?'public-game-hub':url.searchParams.get('game')||(routed==='binary-conversion'?'binary-arcade':routed)||games[0];
            if(teacher||hub){
                const actor=teacher?1:7;
                const html=run('tests/browser-fixture.php',{game,action:'html',restPrefix:'/api/'+actor,userId:actor});
                const css=teacher?['resources/css/typing-evaluations.css']:['resources/css/public-hub.css','assets/typing/entry.css'];
                const js=teacher?['resources/js/typing-evaluations.js']:['assets/typing/entry.js'];
                res.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'});
                return res.end('<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>격리된 수행평가 미리보기</title><style>body{margin:0;padding:24px 16px;font-family:system-ui}main,nav{max-width:1120px;margin:auto}nav{margin-bottom:20px;display:flex;gap:20px}</style>'+css.map(f=>'<link rel="stylesheet" href="/plugins/edu-games/'+f+'">').join('')+'<nav aria-label="미리보기 역할"><a href="'+hubPath+'">학생 게임 허브</a><a href="/?game=code-typing">학생 코드 타이핑</a><a href="'+teacherPath+'">교사 평가 운영</a></nav><main>'+html+'</main>'+js.map(f=>'<script src="/plugins/edu-games/'+f+'"></script>').join('')+'</html>');
            }
            if (![...games,...classic].includes(game)) return reply({},404);
            const isClassic=classic.includes(game);
            const html = run('tests/browser-fixture.php', { game, action: 'html', play: !isClassic });
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8','Cache-Control':'no-store' });
            const registered=process.env.PLAY_PLATFORM_ASSETS==='1'?JSON.parse(run('tests/addon-assets.php',{}, {EDU_GAMES_ASSET_PAGE:'addon.edu_games.games.'+(game==='binary-arcade'?'binary-conversion':game)})):null;
            const typingStyles=game==='code-typing'?['assets/typing/entry.css','assets/typing/verified.css']:[];
            const typingScripts=game==='code-typing'?['assets/typing/entry.js','assets/typing/verified.js']:[];
            const css=registered?registered.styles:isClassic?[`blocks/${game}/style.css`,...typingStyles]:['assets/play/style.css','assets/play/hands.css',...(game==='data-structure-lab'?['assets/play/warehouse.css']:[])];
            const js=registered?registered.scripts:isClassic?['assets/learning/progress.js',...typingScripts,`blocks/${game}/view.js`]:['assets/vendor/chartjs/chart.umd.min.js','assets/play/missing.js','assets/play/rescue-scenes.js','assets/play/rescue.js',...(game==='data-structure-lab'?['assets/play/warehouse.js']:[]),'assets/play/'+(hands?'hands':'views')+'.js','assets/play/core.js'];
            const names=['데이터 스캐너','데이터 잇기','그래프 출동','조건 관문','스택·큐','분류 경계','손글씨 로봇','이진수','문자코드','코드 타이핑','장치 카드','이미지 데이터','키워드 레인'];
            const styles=registered?css:[...css,'assets/rankings/pager.css','assets/shell/shell.css','assets/shell/layouts.css'],scripts=registered?js:['assets/rankings/pager.js',...js,'assets/shell/shell.js'];
            res.end('<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>교육 게임 로컬 미리보기</title><style>body{margin:0;padding:24px 16px;font-family:system-ui,sans-serif;background:#fff}main{max-width:1120px;margin:auto}nav.preview-nav{max-width:1120px;margin:0 auto 28px;display:flex;flex-wrap:wrap;gap:10px 18px;font-size:13px}nav.preview-nav a{color:#626269;text-decoration:none}</style>'+styles.map(file=>'<link rel="stylesheet" href="/plugins/edu-games/'+file+'">').join('')+'<nav class="preview-nav" aria-label="미리보기 게임 선택">'+[...games,...classic].map((g,i)=>'<a href="/?game='+g+'">'+names[i]+'</a>').join('')+'<a href="'+hubPath+'">학생 게임 허브</a><a href="'+teacherPath+'">교사 평가 운영</a></nav><main>'+html+'</main>'+scripts.map(file=>'<script src="/plugins/edu-games/'+file+'"></script>').join('')+'</html>');
            return;
        }
        const match = url.pathname.match(/\/play\/([a-z-]+)\/(start|update|resume|summary|archive)$/);
        if (!match) return reply({},404);
        const [,game,action] = match;
        let raw=''; for await (const chunk of req) raw+=chunk;
        const input = JSON.parse(raw || '{}');
        if(action==='archive')return reply({records:[]});
        if(action==='summary') {
            const levels = Object.fromEntries(Object.entries(catalogs[game].levels).map(([key,d])=>[key,{...d,id:key,score:best[game+':'+key]||0,unlocked:true}]));
            return reply({levels,leaderboard:[],level:input.level,pagination:{page:1,perPage:20,total:0}});
        }
        let record;
        if(action==='start'){
            record={...fixture({game,action,level:input.level}),game,token:String(++id).padStart(48,'0'),revision:0};sessions.set(record.token,record);
        }else{
            record=sessions.get(input.token);
            if(!record||record.game!==game)return reply({message:'도전 만료'},404);
            if(action==='update'){
                if(fault==='before'){fault='';return reply({message:'저장 연결 오류'},503);}
                if(record.last!==input.requestId){
                    if(record.revision!==input.revision)return reply({message:'다른 창에서 진행되었습니다.'},409);
                    const data=fixture({game,action,state:record.state,move:input.move});
                    if(data.error)return reply({message:data.error},400);
                    Object.assign(record,data,{revision:record.revision+1,last:input.requestId});
                    if(record.state.status!=='playing'){const key=game+':'+record.state.level;best[key]=Math.max(best[key]||0,record.state.score);}
                }
                if(fault==='after'){fault='';return reply({message:'응답 연결 오류'},503);}
            }
        }
        if(hands)record.view=fixture({game,action:'view',state:record.state}).view;
        reply({...record.view,token:record.token,revision:record.revision});
    }catch(e){reply({message:e.message},500);}
});
const ready = page => page.waitForFunction(()=>document.querySelector('[data-play]')?.getAttribute('aria-busy')==='false');
const state = page => page.locator('[data-play]').evaluate(r=>r.__Play.state);
async function perform(page,m) {
    const before=await state(page), old=before?.revision??-1;
    if(m.type==='node')await page.locator('[data-pick=node][data-id="'+m.node+'"]').click();
    if(m.type==='deck'){
        const selected=await page.locator('[data-pick=deck][aria-pressed=true]').evaluateAll(es=>es.map(e=>Number(e.dataset.id)));
        for(const id of selected.filter(i=>!m.cards.includes(i)))await page.locator('[data-pick=deck][data-id="'+id+'"]').click();
        for(const id of m.cards.filter(i=>!selected.includes(i)))await page.locator('[data-pick=deck][data-id="'+id+'"]').click();
    }
    const buttons=page.locator('[data-move]');let found=false;
    for(let i=0;i<await buttons.count();i++){
        if(require('node:util').isDeepStrictEqual(JSON.parse(await buttons.nth(i).getAttribute('data-move')),m)){
            await buttons.nth(i).evaluate(e=>{for(let n=e.parentElement;n;n=n.parentElement)if(n.tagName==='DETAILS')n.open=true;});
            await buttons.nth(i).click();found=true;break;
        }
    }
    if(!found){
        const forms=page.locator('[data-play-form="'+m.type+'"]');
        for(let i=0;i<await forms.count();i++){
            const f=forms.nth(i),extra=JSON.parse(await f.getAttribute('data-extra'));
            if(!Object.entries(extra).every(([k,v])=>m[k]===v))continue;
            let fields={...m}; delete fields.type; for(const k of Object.keys(extra))delete fields[k];
            if(m.type==='gates'){
                fields={count:m.gates.length};m.gates.forEach((g,j)=>Object.assign(fields,{['x0-'+j]:g[0],['y0-'+j]:g[1],['x1-'+j]:g[2],['y1-'+j]:g[3]}));
            }
            if(m.type==='charts'){fields={};for(let j=0;j<2;j++)Object.assign(fields,{['group'+j]:m.charts[j]?.group||'off',['kind'+j]:m.charts[j]?.kind||'bar'});}
            for(const [key,value]of Object.entries(fields)){
                if(m.type==='node'&&((key==='fields'&&m.kind==='filter')||(key==='rule'&&m.kind==='project')))continue;
                if(key==='fields'){for(const box of await f.locator('input[name=fields]').all())await box.setChecked(value.includes(await box.getAttribute('value')));continue;}
                const field=f.locator('[name="'+key+'"]');
                if(await field.evaluate(e=>e.tagName)==='SELECT')await field.selectOption(String(value));else await field.fill(String(value));
            }
            await f.locator('[type=submit]').click();found=true;break;
        }
    }
    assert(found,'control exists '+JSON.stringify(m));
    await page.waitForFunction(old=>(document.querySelector('[data-play]').__Play.state?.revision??-1)>old,old);
    await ready(page);checks++;
}
async function layout(page,game,phase){
    for(const width of [360,1280]){
        await page.setViewportSize({width,height:950});await page.waitForTimeout(220);
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),game+' overflow '+width);
        assert.deepEqual(await page.locator('[data-play]').evaluate(r=>[...r.querySelectorAll('button')].filter(e=>e.offsetWidth&&(e.scrollWidth>e.clientWidth+3||e.scrollHeight>e.clientHeight+3)).map(e=>e.textContent)),[],game+' clipped buttons');
        await page.screenshot({path:path.join(out,game+'-'+phase+'-'+width+'.png'),fullPage:true});checks+=2;
    }
}
async function main(){
    if(process.env.PLAY_TYPING_DEMO==='1')seedTyping();
    await new Promise(resolve=>server.listen(Number(process.env.PLAY_PREVIEW_PORT||0),'127.0.0.1',resolve));
    const url='http://127.0.0.1:'+server.address().port;console.log('Preview '+url);
    if(process.env.PLAY_PREVIEW)return;
    const browser=await (process.env.PLAY_BROWSER==='webkit'?webkit.launch({headless:true}):chromium.launch({executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true}));
    try{
        const page=await browser.newPage();const errors=[];page.on('pageerror',e=>{errors.push(e.message);console.error('Browser error:',e.message);});
        if(hands&&process.env.PLAY_LAYOUT_V2_ONLY){await require('./browser-layout-v2.cjs')({page,browser,url,out,ready,state,sessions});assert.deepEqual(errors,[]);return;}
        if(hands&&process.env.PLAY_PREVIEW_INTEGRITY_ONLY){await require('./browser-preview-integrity.cjs')({page,browser,url,out,typingFixture});assert.deepEqual(errors,[]);return;}
        if(hands&&process.env.PLAY_BOARD_UI_ONLY){await require('./browser-board-ui.cjs')({page,browser,url,out,ready,state,sessions});assert.deepEqual(errors,[]);return;}
        if(hands){await require(process.env.PLAY_WAREHOUSE_ONLY?'./browser-warehouse-rush.cjs':process.env.PLAY_THEME_ONLY?'./browser-theme.cjs':process.env.PLAY_FIT_ONLY?'./browser-fit-behavior.cjs':process.env.PLAY_VIEWPORT_ONLY?'./browser-viewport.cjs':process.env.PLAY_INTERFACE_ONLY?'./browser-interface.cjs':process.env.PLAY_ARCADE_ONLY?'./browser-arcade.cjs':process.env.PLAY_MACHINES_ONLY?'./browser-machines.cjs':'./browser-hands.cjs')({page,browser,url,out,ready,state,setFault:value=>{fault=value;},sessions});assert.deepEqual(errors,[]);return;}
        for(const game of games){
            for(const level of ['board-01','board-02']){
                await page.goto(url+'/?game='+game);await ready(page);await page.locator('[data-role=level]').selectOption(level);
                if(level==='board-01')await layout(page,game,'start');
                const moves=catalogs[game].solutions[level];
                for(const m of moves){
                    if(game==='outlier-detection'&&m.station!==undefined)await page.locator('[data-pick=station][data-id="'+m.station+'"]').click();
                    if(game==='missing-values')await page.locator('[data-pick=cell][data-id="'+m.cell+'"]').click();
                    await perform(page,m);
                }
                assert.equal((await state(page)).status,'won',game+' completes');checks++;
                if(level==='board-01')await layout(page,game,'finish');
            }
            console.log(game+' completed both situations');
        }
        // A lost response can be retried without applying the move twice, then resumed.
        await page.goto(url+'/?game=outlier-detection');await ready(page);
        fault='after';
        await page.locator('[data-pick=station][data-id="1"]').click();
        await page.getByRole('button',{name:'센서 교체 · 자원 3'}).click();await ready(page);
        await page.locator('[data-command=retry]').click();await ready(page);
        assert.equal((await state(page)).energy,5);assert.equal((await state(page)).revision,1);checks+=2;
        await page.reload();await ready(page);await page.locator('[data-command=resume]').click();await ready(page);
        assert.equal((await state(page)).energy,5);checks++;
        assert.deepEqual(errors,[]);checks++;
        console.log(checks+' browser checks passed; screenshots '+out);
    }finally{await browser.close();server.close();for(const suffix of ['','-wal','-shm'])try{fs.unlinkSync(typingDb+suffix);}catch{}}
}
main().catch(e=>{console.error(e);server.close();process.exitCode=1;});
