/* Dedicated ranking page, real PHP renderer and browser runtime; example data only. */
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'../../_archived/readcontents-library/node_modules/playwright');
const root=path.resolve(__dirname,'..'),out=path.join(root,'docs/previews/rankings');fs.mkdirSync(out,{recursive:true});
const names=['김하늘','이도윤','박지우','최서연','정시우','한유진','윤다은','강지호','신예린','임서준','조수아','송민재'];
const stores=new Map();let myTeam={id:2,name:'연결고리',memberCount:5,inviteCode:'EDU321'},reads=0;
function rowsFor(key){if(!stores.has(key))stores.set(key,Array.from({length:180},(_,i)=>({id:i+1,userId:i+1,playerName:i<names.length?names[i]:`도전자 ${i+1}`,score:i===179?0:198450-i*925,accuracy:Math.round((99.8-i*.12)*10)/10,durationSeconds:45+i,rank:i+1})));return stores.get(key);}
const server=http.createServer(async(req,res)=>{
 const url=new URL(req.url,'http://127.0.0.1'),admin=req.headers['x-preview-admin']==='1';
 const json=(data,status=200)=>{res.writeHead(status,{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store'});res.end(JSON.stringify(data));};
 try{
  const asset=url.pathname.replace(/^\/plugins\/edu-games/,'');
  if(asset.startsWith('/assets/')){const file=path.resolve(root,'.'+asset);if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.writeHead(404);return res.end();}res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.svg')?'image/svg+xml':'text/css');return res.end(fs.readFileSync(file));}
  if(url.pathname==='/'){
   const isAdmin=url.searchParams.get('admin')==='1';
   const php=spawnSync(process.env.PHP_BINARY||'D:/php/php.exe',['tests/browser-fixture.php'],{cwd:root,input:JSON.stringify({action:'html',game:'ranking-page',admin:isAdmin}),encoding:'utf8',maxBuffer:8e6});if(php.status!==0)throw Error(php.stderr);
   const identity=`<script>const originalFetch=fetch.bind(window);window.fetch=(url,options={})=>originalFetch(url,{...options,headers:{...options.headers,'X-Preview-Admin':'${isAdmin?'1':'0'}'}});</script>`;
   res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(`<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>게임 순위 디자인 미리보기</title><style>body{margin:0;background:var(--ojp-color-surface,#fff);color:var(--ojp-color-ink,#1d1d1f);font-family:system-ui}main{max-width:1160px;margin:0 auto;padding:0 20px 40px}.preview-note{display:flex;align-items:center;justify-content:space-between;max-width:1120px;margin:0 auto 20px;padding:12px 0;border-bottom:1px solid var(--ojp-color-line,#e4e4e8);font-size:11px;color:var(--ojp-color-muted,#6e6e73)}.preview-note a{color:inherit;text-decoration:none}html[data-ojp-scheme=dark]{--ojp-color-surface:#1c1c1e;--ojp-color-ink:#f5f5f7;--ojp-color-muted:#a1a1a6;--ojp-color-line:#39393e;--ojp-color-subtle:#252528;--ojp-color-primary:#64aaff;--ojp-color-accent-bg:#172e47;--ojp-color-success:#75c99b;--ojp-color-success-bg:#1c3429;--ojp-color-warning:#e7bd75;--ojp-color-warning-bg:#3b3020;--ojp-color-danger:#f2948a;--ojp-color-danger-bg:#3e2424}</style><link rel="stylesheet" href="/assets/rankings/pager.css"><link rel="stylesheet" href="/assets/rankings/page.css">${identity}</head><body><main><div class="preview-note"><span>디자인 미리보기 · 예시 데이터${isAdmin?' · 관리자':''}</span><a href="?admin=${isAdmin?'0':'1'}">${isAdmin?'학생 화면':'관리자 화면'}</a></div>${php.stdout}</main><script src="/assets/rankings/pager.js"></script><script src="/assets/rankings/page.js"></script></body></html>`);
  }
  if(!url.pathname.startsWith('/edu-games/v1/')){res.writeHead(404);return res.end('Preview only');}
  let raw='';for await(const c of req)raw+=c;const body=raw?JSON.parse(raw):{};
  if(url.pathname.endsWith('/teams/mine'))return json({team:myTeam});
  if(url.pathname.endsWith('/teams/join')){myTeam={id:2,name:'연결고리',memberCount:5,inviteCode:body.inviteCode};return json({team:myTeam});}
  if(url.pathname.endsWith('/teams/leave')){myTeam=null;return json({team:null});}
  reads++;
  const game=url.pathname.match(/leaderboards\/game\/([^/]+)/)?.[1]||url.pathname.match(/\/(?:play|strategy|learning)\/([^/]+)/)?.[1]||url.searchParams.get('game')||'data-structure-lab';
  const modern=!url.pathname.includes('/leaderboards/'),variant=modern?url.pathname.split('/')[3]:'arcade',key=game+':'+variant;
  if(req.method==='DELETE'){if(!admin)return json({message:'권한 없음'},403);const id=Number(url.pathname.split('/').at(-1));stores.set(game+':arcade',rowsFor(game+':arcade').filter(r=>r.id!==id));return json({deleted:true});}
  if(url.pathname.endsWith('/archive'))return json({records:[{version:'2026-09',level:'board-01',score:950,attempts:3}]});
  const teams=url.pathname.endsWith('/leaderboards/teams');let rows;
  if(teams)rows=Array.from({length:60},(_,i)=>({rank:i+1,teamId:i+1,teamName:['알고리즘 탐험대','연결고리','데이터 나침반'][i]||`정보 ${i+1}팀`,memberCount:5,averageScore:98320-i*670}));
  else{rows=rowsFor(key).map((r,i)=>({...r,rank:i+1,score:modern?(i===179?0:5740-i*25):r.score}));if(url.pathname.includes('/leaderboards/team/'))rows=rows.slice(0,60);
   if(modern)rows=rows.map(r=>({...r,name:admin?r.playerName:r.userId===7?'나':'도전자',isMe:r.userId===7,playerName:undefined}));
   if(admin)rows=rows.map(r=>({...r,deleteUrl:`/edu-games/v1/leaderboards/game/${game}/records/${modern?'run':'score'}/${r.id}`}));
  }
  const perPage=Number(body.perPage||url.searchParams.get('perPage')||20),totalPages=Math.max(1,Math.ceil(rows.length/perPage)),page=Math.min(totalPages,Math.max(1,Number(body.page||url.searchParams.get('page')||1)));
  const mine=rows.find(r=>r.userId===7),data={pagination:{page,perPage,total:rows.length,totalPages},overview:{topScore:rows[0]?.score??null,personal:mine?{rank:mine.rank,score:mine.score}:null},myRank:mine?.rank??null};
  data[teams?'standings':'leaderboard']=rows.slice((page-1)*perPage,page*perPage);
  if(modern){data.level=body.level;data.levels={[body.level]:{score:mine?.score||0}};if(variant==='learning')data.history=[{started_at:'2026-11-03 03:00:00',completed_at:'2026-11-03 03:02:00',mode:'assessment'}];}
  return json(data);
 }catch(e){console.error(e);json({message:e.message},500);}
});
async function run(){
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const base=`http://127.0.0.1:${server.address().port}`;console.log('Ranking preview '+base);
 if(process.env.RANKING_PREVIEW)return;
 const browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true}),page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];let checks=0;
 page.on('pageerror',e=>errors.push(e.message));page.setDefaultTimeout(20000);
 const wait=async text=>page.waitForFunction(value=>document.querySelector('[data-er-board] .edu-rank-pagination output')?.textContent===value,text);
 try{
  await page.goto(base);await wait('1 / 9');assert.equal(await page.locator('[data-rank-row]').count(),20);assert.equal(await page.locator('[data-er-mine]').textContent(),'7위');checks+=2;
  assert.equal(reads,1,'only selected ranking fetched on initial page');checks++;
  await page.screenshot({path:path.join(out,'desktop-view.png')});
  await page.screenshot({path:path.join(out,'desktop.png'),fullPage:true});
  await page.locator('[data-rank-page=last]').click();await wait('9 / 9');assert.equal(await page.locator('[data-rank-row]').last().locator('b').textContent(),'0');assert.equal(await page.locator('[data-er-mine]').textContent(),'7위');checks+=2;
  await page.locator('[data-rank-size]').selectOption('50');await wait('1 / 4');assert.equal(await page.locator('[data-rank-row]').count(),50);checks++;
  await page.locator('[data-er-scope=teams]').click();await wait('1 / 2');assert.match(await page.locator('[data-er-status]').textContent(),/60팀/);checks++;
  await page.locator('[data-er-scope=team]').click();await wait('1 / 2');assert.match(await page.locator('[data-er-table-title]').textContent(),/연결고리/);checks++;
  await page.locator('[data-er-game=missing-values]').click();await wait('1 / 4');assert(await page.locator('[data-er-scope=teams]').isDisabled());assert.equal(await page.locator('[data-er-board] .is-me').count(),1);checks+=2;
  await page.locator('[data-er-variant]').selectOption('strategy');await wait('1 / 4');assert(await page.locator('[data-er-level]').isVisible());checks++;
  await page.locator('[data-er-level]').selectOption({index:1});await wait('1 / 4');checks++;
  await page.locator('[data-er-variant]').selectOption('assessment');await wait('1 / 4');assert(await page.locator('[data-er-history]').isVisible());checks++;
  await page.locator('[data-er-game=binary-conversion]').click();await wait('1 / 4');await page.locator('[data-rank-size]').selectOption('20');await wait('1 / 9');
  await page.locator('[data-er-search]').fill('장치');assert.equal(await page.locator('[data-er-game]').count(),1);checks++;await page.locator('[data-er-search]').fill('');
  for(const width of [1280,834,390,320]){await page.setViewportSize({width,height:width<700?844:1000});const geometry=await page.locator('[data-rankings-page]').evaluate(r=>({x:r.scrollWidth-r.clientWidth}));assert(geometry.x<=1,'fits '+width+JSON.stringify(geometry));checks++;if(width===390)await page.screenshot({path:path.join(out,'mobile.png'),fullPage:true});}
  await page.setViewportSize({width:1440,height:1000});await page.evaluate(()=>document.documentElement.dataset.ojpScheme='dark');await page.screenshot({path:path.join(out,'dark.png'),fullPage:true});checks++;
  await page.goto(base+'/?admin=1');await wait('1 / 9');assert.equal(await page.locator('.edu-rank-delete').count(),20);checks++;
  await page.setViewportSize({width:390,height:844});assert(await page.locator('.edu-rank-delete').first().isVisible());assert(await page.locator('[data-rank-row]').first().evaluate(r=>r.scrollWidth<=r.clientWidth+1));checks+=2;
  await page.locator('.edu-rank-delete').first().click();await page.locator('.edu-rank-dialog [data-confirm]').click();await page.waitForFunction(()=>document.querySelector('[data-er-status]')?.textContent==='전체 179명');assert.equal(await page.locator('[data-er-top]').textContent(),'197,525');checks++;
  assert.deepEqual(errors,[]);checks++;console.log(`PASS: ${checks} ranking page, engines, pagination, privacy, moderation, responsive and dark-mode browser checks.`);
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
}
run().catch(e=>{console.error(e);server.close();process.exitCode=1;});
