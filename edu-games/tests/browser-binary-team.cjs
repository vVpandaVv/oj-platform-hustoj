const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'../../_archived/readcontents-library/node_modules/playwright');
const root=path.resolve(__dirname,'..'),out=path.resolve(root,'../_notes/tmp/edu-games-binary-team');fs.mkdirSync(out,{recursive:true});
let db={rooms:{},results:{}},manual=process.env.BINARY_TEAM_PREVIEW?null:Date.now(),socketUrl='',hub=null,dropNextCommand=false,droppedCommands=0,httpCommands=0;
const wsMode=Boolean(process.env.BINARY_TEAM_WEBSOCKET);
const deviceMode=Boolean(process.env.DEVICE_TEAM),game=deviceMode?'device-matching':'binary-conversion';
const now=()=>manual??Date.now();
function php(input){const p=spawnSync(process.env.PHP_BINARY||'D:/php/php.exe',[path.join(__dirname,'binary-team-fixture.php')],{input:JSON.stringify(input),encoding:'utf8',maxBuffer:16e6});if(p.status!==0)throw Error(p.stderr||p.stdout);return p.stdout;}
const server=http.createServer(async(req,res)=>{
 const url=new URL(req.url,'http://localhost'),uid=Number(req.headers['x-test-user']||url.searchParams.get('user')||7);
 const json=(x,status=200)=>{res.writeHead(status,{'content-type':'application/json','cache-control':'no-store'});res.end(JSON.stringify(x));};
 try{
  if(url.pathname==='/_advance'){manual=Number(url.searchParams.get('at'));return json({now:now()});}
  if(url.pathname==='/_state')return json(db);
  const relay=url.pathname==='/plugins/edu-games/binary-relay.php';
  if(url.pathname.startsWith('/plugins/edu-games/')&&!relay){
   const file=path.resolve(root,decodeURIComponent(url.pathname.slice('/plugins/edu-games/'.length)));
   if(!file.startsWith(root+path.sep)||!['.css','.js','.svg','.jpg','.png','.webp','.md'].includes(path.extname(file))||!fs.existsSync(file))return json({},404);
   res.writeHead(200,{'content-type':{'.css':'text/css','.js':'application/javascript','.svg':'image/svg+xml','.jpg':'image/jpeg','.png':'image/png','.webp':'image/webp','.md':'text/plain'}[path.extname(file)]});return res.end(fs.readFileSync(file));
  }
  if(url.pathname==='/'){
   const html=php({action:'html',uid,socketUrl,game,screen:url.searchParams.get('screen')||'student',room:url.searchParams.get('room')||''});res.writeHead(200,{'content-type':'text/html; charset=utf-8'});
   const identity=`<script>const nativeFetch=window.fetch.bind(window);window.fetch=(url,options={})=>nativeFetch(url,{...options,headers:{...options.headers,'X-Test-User':${Number.isSafeInteger(uid)?uid:7}}});</script>`;
   return res.end('<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>실시간 팀전 미리보기</title><style>body{margin:0;padding:24px 16px;font-family:system-ui}main{max-width:1120px;margin:auto}</style>'+identity+['assets/shell/shell.css','blocks/binary-arcade/style.css','blocks/device-matching/style.css','assets/binary-team/style.css','assets/device-team/style.css','assets/binary-team/operations.css'].map(f=>`<link rel="stylesheet" href="/plugins/edu-games/${f}">`).join('')+'<main>'+html+'</main>'+['assets/shell/shell.js','assets/binary-team/socket.js','assets/device-team/board.js','assets/binary-team/operations.js','assets/binary-team/view.js'].map(f=>`<script src="/plugins/edu-games/${f}"></script>`).join('')+'</html>');
  }
  let raw='';for await(const chunk of req)raw+=chunk;const body=raw?JSON.parse(raw):undefined;
  const prefix='/edu-games/v1/'+(deviceMode?'device-team':'binary-team');let action,code,id;
  if(relay)action=body?.sync?'socket':'relay';
  else if(url.pathname===prefix+'/ticket')action='ticket';
  else if(url.pathname===prefix+'/rooms')action=req.method==='POST'?'create':'rooms';
  else if(url.pathname===prefix+'/records')action='records';
  else if(url.pathname.startsWith(prefix+'/records/')){action='result';id=url.pathname.split('/').at(-1);}
  else if(url.pathname.startsWith(prefix+'/rooms/')){action=req.method==='POST'?'command':'poll';code=url.pathname.split('/').at(-1);}
  else return json({},404);
  if(action==='command'||action==='relay'&&body?.command)httpCommands++;
  const reply=JSON.parse(php({action,code,id,uid,game,body,raw,time:req.headers['x-oj-realtime-time'],signature:req.headers['x-oj-realtime-signature'],db,now:now(),watch:url.searchParams.get('watchTeam'),page:url.searchParams.get('page')||1,role:url.searchParams.get('role')||'play',room:url.searchParams.get('room'),mode:url.searchParams.get('mode')||'all'}));db=reply.db;json(reply.data,reply.status);
 }catch(e){console.error(e);json({message:e.message},500);}
});
async function main(){
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const base='http://127.0.0.1:'+server.address().port;console.log('Binary team preview '+base);
 if(wsMode){
  const {createHost}=require('../../_shared/oj-realtime-hub/src/host'),{createAdapter}=require('../realtime-server/src/adapter');
  const app={id:'edu-games',version:require('../realtime-addon.json').version,adapter:'realtime-server/src/adapter.js',path:'/edu-games-live',secret:'binary-team-test-secret-0123456789',origins:[base],env:{WP_API:base+'/plugins/edu-games/binary-relay.php'}};
  hub=createHost({apps:[app]},{adapters:{'edu-games':context=>createAdapter({...context,clock:now,fetch:async(...args)=>{
   const response=await fetch(...args);if(dropNextCommand&&JSON.parse(args[1].body).action){await response.json();dropNextCommand=false;droppedCommands++;throw Error('test: lost committed WebSocket response');}return response;
  }})}});
  await new Promise(resolve=>hub.server.listen(0,'127.0.0.1',resolve));socketUrl=`ws://127.0.0.1:${hub.server.address().port}/edu-games-live`;console.log('WebSocket '+socketUrl);
 }
 if(process.env.BINARY_TEAM_PREVIEW)return;
 const browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});const contexts=[],errors=[];let checks=0;
 const user=async id=>{const c=await browser.newContext({viewport:{width:1280,height:720},extraHTTPHeaders:{'X-Test-User':String(id)}});contexts.push(c);const p=await c.newPage();p.setDefaultTimeout(20000);p.on('pageerror',e=>errors.push(e.message));
  // Each isolated PHP request starts a CLI process; this is not a load-test server.
  await p.addInitScript(()=>{const interval=window.setInterval.bind(window);window.setInterval=(fn,ms,...args)=>interval(fn,ms===700?2500:ms,...args);});
  await p.goto(base+'/?user='+id);await p.waitForSelector('[data-bt-form=create]');return p;};
 const state=p=>p.locator('[data-binary-team]').evaluate(r=>r.__BinaryTeam.room);
 const done=async p=>{await p.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.pending===0);};
 const advance=async at=>{await fetch(base+'/_advance?at='+at);if(wsMode)await new Promise(resolve=>setTimeout(resolve,1300));};
 try{
  if(process.env.TEAM_OPERATIONS){await require('./browser-team-operations.cjs')({browser,base,out,state,done,advance,wsMode,game,getDb:()=>db,setup:body=>{const reply=JSON.parse(php({action:'setup',uid:100,game,body,db,now:now()}));db=reply.db;return reply.data;}});assert.deepEqual(errors,[]);return;}
  if(deviceMode){await require('./browser-device-team.cjs')({browser,base,root,out,user,state,done,advance,wsMode,getDb:()=>db,setup:body=>{const reply=JSON.parse(php({action:'setup',uid:100,game,body,db,now:now()}));db=reply.db;return reply.data;}});assert.deepEqual(errors,[]);return;}
  const host=await user(100),players=[];
  await host.locator('[name=participate]').uncheck();
  await host.locator('[data-bt-form=create] [type=submit]').click();await host.waitForSelector('.bt-code');const code=(await state(host)).code;
  for(const id of [1,2,3,4]){const p=await user(id);players.push(p);await p.locator('[name=code]').fill(code);await p.locator('[data-bt-form=join] [type=submit]').click();await p.waitForSelector('[data-bt=ready]');}
  await host.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.room.players.length===4);
  console.log('Lobby: four independent players joined.');
  for(const p of players){await p.locator('[data-bt=ready]').click();await done(p);}
  await host.waitForFunction(()=>!document.querySelector('[data-bt=start]').disabled);await host.locator('[data-bt=start]').click();await done(host);
  const start=(await state(host)).startedAt;await advance(start+100);for(const p of players){await p.locator('[data-binary-team]').evaluate(r=>r.__BinaryTeam.poll());await p.waitForSelector('[data-bt-board]');}
  const [a,b,c,d]=players;
  const initial=await state(a);assert.equal(initial.myTeam,1);assert.equal((await state(c)).myTeam,1);assert.equal((await state(b)).myTeam,2);checks+=3;
  const row=initial.board.rows[0];
  await a.screenshot({path:path.join(out,'board-start-1280.png')});
  let lost=false;
  if(wsMode){for(const p of players)await p.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.socket?.ready);dropNextCommand=true;}
  else await a.route('**/plugins/edu-games/binary-relay.php',async route=>{
   if(!lost&&route.request().postDataJSON()?.command?.type==='claim'){lost=true;await route.fetch();await route.abort('failed');}
   else await route.continue();
  });
  await a.locator(`[data-bt=claim][data-row="${row.id}"]`).click();await done(a);
  assert(wsMode?droppedCommands===1&&httpCommands>0:lost&&await a.locator('[data-binary-team]').evaluate(r=>r.__BinaryTeam.relayDisabled),'lost committed response safely retries through HTTP');checks++;
  await c.waitForFunction(id=>document.querySelector(`[data-bt-row="${id}"]`).classList.contains('is-locked'),row.id);
  assert(await c.locator(`[data-bt-row="${row.id}"] .bt-owner`).innerText().then(t=>t.includes('학생 1')));checks++;
  if(wsMode)for(const p of [a,c])await p.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.socket?.ready);
  const httpBeforeSolve=httpCommands;
  const authoritative=db.rooms[code].boards[1].rows.find(r=>r.id===row.id);
  const before=initial.board.score;
  if(row.mode==='bin-to-dec'){await a.locator(`[data-bt-answer="${row.id}"]`).fill(String(authoritative.decimal));}
  else for(const[bit,value]of [...authoritative.decimal.toString(2).padStart(8,'0')].entries())if(value==='1')await a.locator(`[data-bt=bit][data-row="${row.id}"][data-bit="${bit}"]`).click();
  await done(a);await c.waitForFunction(score=>document.querySelector('[data-binary-team]').__BinaryTeam.room.board.score>score,before);
  if(wsMode){assert.equal(httpCommands,httpBeforeSolve,'healthy WebSocket carries commands without browser HTTP commands');checks++;}
  console.log('Shared board: lease and solve synchronized.');
  assert.equal((await state(a)).board.correct,1);assert.equal((await state(c)).board.correct,1);assert.equal((await state(b)).board.correct,0);checks+=3;
  await a.reload();await a.waitForSelector('[data-bt-board]');assert.equal((await state(a)).board.correct,1);checks++;
  await host.locator('[data-bt=pause]').click();await done(host);await advance(start+15000);
  await a.locator('[data-binary-team]').evaluate(r=>r.__BinaryTeam.poll());assert.equal((await state(a)).status,'paused');checks++;
  await host.locator('[data-bt=resume]').click();await done(host);const resume=await state(host);assert(resume.endsAt>initial.endsAt);checks++;
  // Populate all eight rows, then check laptop, phone, and iPad framing.
  let rawState=db.rooms[code],board=rawState.boards[1];
  for(let n=0;n<6&&board.rows.length<8;n++){await advance(board.nextSpawnAt);await a.locator('[data-binary-team]').evaluate(r=>r.__BinaryTeam.poll());rawState=db.rooms[code];board=rawState.boards[1];}
  for(const viewport of [{width:1280,height:720},{width:390,height:844},{width:834,height:1194},{width:1194,height:834}]){
   await a.setViewportSize(viewport);await a.waitForTimeout(100);
   await a.screenshot({path:path.join(out,'board-'+viewport.width+'.png')});
   assert.equal(await a.locator('.bt-line,.bt-empty-row').count(),8);checks++;
   const measure=await a.locator('.eg-focus-area').evaluate(e=>({x:e.scrollWidth-e.clientWidth,y:e.scrollHeight-e.clientHeight}));assert(measure.x<=1&&measure.y<=1,JSON.stringify({viewport,measure}));checks++;
   assert(await a.locator('.bt-line').evaluateAll(rows=>rows.every(r=>{const b=r.getBoundingClientRect();return [...r.querySelectorAll('.bt-bits>*,input')].every(e=>{const x=e.getBoundingClientRect();return x.top>=b.top-1&&x.bottom<=b.bottom+1;});})));checks++;
   assert(await a.locator('[data-binary-team]').evaluate(root=>{const cells=[...root.querySelector('.bt-bits').children].map(e=>e.getBoundingClientRect());return [...root.querySelectorAll('.bt-weights')].every(w=>[...w.children].every((e,i)=>{const a=e.getBoundingClientRect(),b=cells[i];return Math.abs((a.left+a.width/2)-(b.left+b.width/2))<2;}));}),'place weights align with all bit columns');checks++;
   assert(await a.locator('.bt-board').evaluate(board=>{const css=getComputedStyle(board);return css.gap==='0px'&&css.padding==='0px'&&getComputedStyle(board,'::before').backgroundImage.includes('linear-gradient');}),'continuous personal-arcade grid without row boxes');checks++;
   assert(await a.locator('.bt-line').evaluateAll(rows=>rows.every(row=>{const css=getComputedStyle(row),bits=row.querySelector('.bt-bits');return row.classList.contains('oj-binary-row')&&css.borderRadius==='0px'&&getComputedStyle(bits).gap==='0px'&&[...bits.children].every(bit=>{const s=getComputedStyle(bit);return bit.classList.contains('oj-binary-row-bit')&&s.borderRadius==='0px'&&Math.abs(bit.getBoundingClientRect().height-bits.getBoundingClientRect().height)<1;});})),'full-height shared square bits, not inset buttons');checks++;
   await a.screenshot({path:path.join(out,'board-'+viewport.width+'.png')});
  }
  await host.locator('[data-bt=stop]').click();await host.locator('.bt-confirm [value=yes]').click();await done(host);
  for(const p of players)await p.waitForSelector('.bt-result-row');
  assert.equal(Object.keys(db.results).length,1);checks++;
  await a.locator('[data-bt=records]').click();await a.waitForSelector('[data-bt=record]');await a.locator('[data-bt=record]').click();await a.waitForSelector('.bt-result-row');checks++;
  const outsider=await user(20);await outsider.locator('[data-bt=records]').click();await outsider.waitForSelector('[data-bt-records]');assert.equal(await outsider.locator('[data-bt=record]').count(),0);checks++;
  await host.locator('[data-bt=rematch]').click();await done(host);assert.equal((await state(host)).round,2);assert((await state(host)).players.every(p=>!p.ready));checks+=2;
  const setup=JSON.parse(php({action:'setup',uid:100,body:{op:'setup-classroom-30',title:'30명 팀전',teams:6,capacity:5,duration:180},db,now:now()}));assert.equal(setup.status,200);db=setup.db;
  await advance(setup.data.startedAt+6000);
  await a.locator('[data-bt=list]').click();await a.locator('[name=code]').fill(setup.data.code);await a.locator('[data-bt-form=join] [type=submit]').click();await a.waitForSelector('[data-bt-board]');
  assert.equal((await state(a)).players.length,30);assert.equal((await state(a)).board.difficulty.spawnMs,1250);checks+=2;
  const wrongRow=(await state(a)).board.rows.find(r=>r.mode==='bin-to-dec');
  if(wrongRow){
   const input=a.locator(`[data-bt-answer="${wrongRow.id}"]`),correct=(await state(a)).board.correct,wrong=(await state(a)).board.wrong,decimal=parseInt(wrongRow.prompt,2),text=String(decimal);
   await input.fill(decimal===0?'1':'0');await done(a);assert.equal((await state(a)).board.wrong,wrong,'unfinished or wrong typing is not submitted automatically');checks++;
   await input.press('Enter');await done(a);assert((await a.locator('[data-bt-board-status]').innerText()).includes('다릅니다'));checks++;
   await advance(setup.data.startedAt+6650);
   if(text.length>1){await input.fill(text.slice(0,-1));await done(a);assert.equal((await state(a)).board.correct,correct,'a correct answer prefix is not accepted');checks++;}
   await input.evaluate((e,text)=>{e.value=text;e.dispatchEvent(new InputEvent('input',{bubbles:true,isComposing:true}));},text);await done(a);assert.equal((await state(a)).board.correct,correct,'composition waits for the committed value');checks++;
   await input.evaluate(e=>{e.dispatchEvent(new CompositionEvent('compositionend',{bubbles:true}));e.dispatchEvent(new InputEvent('input',{bubbles:true}));e.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true}));});await done(a);
   assert.equal((await state(a)).board.correct,correct+1,'correct input auto-submits once even with repeated input/Enter');assert.equal(await input.count(),0);checks+=2;
   const next=(await state(a)).board.rows.find(r=>r.mode==='bin-to-dec');
   if(next){await advance(setup.data.startedAt+7300);const n=(await state(a)).board.correct;await a.locator(`[data-bt-answer="${next.id}"]`).fill(String(parseInt(next.prompt,2)));await done(a);assert.equal((await state(a)).board.correct,n+1,'normal typing solves without Enter');checks++;}
  }
  for(const viewport of [{width:1280,height:720},{width:390,height:844}]){
   await a.setViewportSize(viewport);await a.waitForTimeout(100);await a.screenshot({path:path.join(out,'classroom-'+viewport.width+'.png')});
   const size=await a.locator('.eg-focus-area').evaluate(e=>({x:e.scrollWidth-e.clientWidth,y:e.scrollHeight-e.clientHeight}));assert(size.x<=1&&size.y<=1,'30-player board fits '+JSON.stringify({viewport,size}));checks++;
  }
  await a.addStyleTag({path:path.resolve(root,'../oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css')});
  const currentScore=(await state(a)).board.score;
  await a.evaluate(()=>document.documentElement.dataset.ojpScheme='dark');
  assert(await a.locator('.bt-line').first().evaluate(e=>getComputedStyle(e).backgroundColor===getComputedStyle(e.closest('.bt-binary-board')).backgroundColor),'shared board follows the personal arcade dark fill');checks++;
  await a.screenshot({path:path.join(out,'classroom-dark.png')});
  await a.evaluate(()=>document.documentElement.dataset.ojpScheme='light');
  assert.equal((await state(a)).board.score,currentScore,'theme changes preserve the running game');checks++;
  assert.deepEqual(errors,[]);checks++;
  console.log(`PASS: ${checks} multi-client shared-board, collision, reconnect, pause, viewport, result and privacy checks.`);
 }finally{await browser.close();if(hub)await hub.close();await new Promise(resolve=>server.close(resolve));}
}
main().catch(e=>{console.error(e);server.close();process.exitCode=1;});
