const assert=require('node:assert/strict'),path=require('node:path');
module.exports=async({base,out,user,state,done,advance,wsMode,getDb,setup})=>{
 let checks=0;const host=await user(100),players=[];
 await host.locator('[name=participate]').uncheck();
 await host.locator('[data-bt-form=create] [type=submit]').click();await host.waitForSelector('.bt-code');const code=(await state(host)).code;
 for(const id of [1,2,3,4]){const p=await user(id);players.push(p);await p.locator('[name=code]').fill(code);await p.locator('[data-bt-form=join] [type=submit]').click();await p.waitForSelector('[data-bt=ready]');}
 await host.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.room.players.length===4);
 for(const p of players){await p.locator('[data-bt=ready]').click();await done(p);}
 await host.waitForFunction(()=>!document.querySelector('[data-bt=start]').disabled);await host.locator('[data-bt=start]').click();await done(host);
 const start=(await state(host)).startedAt;await advance(start+10);
 const [a,b,c]=players;for(const p of players)await p.waitForSelector('.dt-card');
 if(wsMode)for(const p of players)await p.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.socket?.ready);
 assert.equal(await a.locator('.dt-card').count(),24);assert.equal(await a.locator('.dt-card img').count(),0);checks+=2;
 assert(await a.locator('.dt-deck').evaluate(deck=>{
  const probe=document.createElement('section');probe.className='edu-game eg-focused';probe.style.cssText='position:fixed;left:-10000px;width:200px';
  probe.innerHTML='<div class="oj-device-shell"><button class="oj-device-card"><span class="oj-device-card-inner"><span class="oj-device-card-face oj-device-card-back"><span class="oj-device-card-mark">?</span><span>DEVICE</span></span></span></button></div>';
  document.body.append(probe);
  const back=getComputedStyle(deck.querySelector('.oj-device-card-back')),reference=getComputedStyle(probe.querySelector('.oj-device-card-back'));
  const match=['backgroundColor','borderColor','borderRadius','padding','fontSize'].every(key=>back[key]===reference[key]);probe.remove();return deck.classList.contains('oj-device-deck')&&match;
 }),'team backs use the actual personal card styling');checks++;
 const raw=()=>getDb().rooms[code].boards[1],first=raw().cards[0],other=raw().cards.find(x=>x.device!==first.device);
 const flip=async(p,id)=>{await p.locator(`[data-dt-card="${id}"]`).click();await done(p);};
 await flip(a,first.id);await c.waitForFunction(id=>document.querySelector(`[data-dt-card="${id}"]`).dataset.state==='open',first.id);
 await a.waitForTimeout(300);await a.screenshot({path:path.join(out,'device-team-start-1280.png')});
 const initialFrame=await a.locator('.eg-focus-area').evaluate(e=>({x:e.scrollWidth-e.clientWidth,y:e.scrollHeight-e.clientHeight}));assert(initialFrame.x<=1&&initialFrame.y<=1,'24-card board and controls fit '+JSON.stringify(initialFrame));checks++;
 assert(await a.locator(`[data-dt-card="${first.id}"] .oj-device-card-inner`).evaluate(e=>getComputedStyle(e).animationName==='oj-device-open'),'personal 2D flip animation is reused');checks++;
 await flip(c,first.id);assert.equal(Object.keys(raw().turns).length,1,'opening a teammate face only inspects, never steals');checks++;
 await flip(c,other.id);assert.equal(Object.keys(raw().turns).length,2);checks++;
 const mate=raw().cards.find(x=>x.device===other.device&&x.id!==other.id);await flip(c,mate.id);
 await a.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.room.board.correct===1);
 assert.equal((await state(b)).board.correct,0);checks++;
 const wrong=raw().cards.find(x=>x.state==='down'&&x.device!==first.device);await flip(a,wrong.id);
 assert.equal(raw().wrong,1);checks++;
 await advance(start+1300);await a.locator('[data-binary-team]').evaluate(r=>r.__BinaryTeam.poll());
 await a.waitForFunction(id=>document.querySelector(`[data-dt-card="${id}"]`).dataset.state==='down',first.id);
 assert.equal(await a.locator(`[data-dt-card="${first.id}"] img`).count(),0);checks++;
 await a.waitForTimeout(300);
 assert(await a.locator(`[data-dt-card="${first.id}"]`).evaluate(card=>getComputedStyle(card.querySelector('.oj-device-card-back')).visibility==='visible'&&getComputedStyle(card.querySelector('.oj-device-card-front')).visibility==='hidden'),'mismatch returns to the same visible back');checks++;
 console.log('Device board: parallel selections, matching and mismatch recovery passed.');
 const beforeDevices=new Set(raw().cards.map(x=>x.device));
 const pairs=new Map();for(const card of raw().cards)if(card.state!=='matched'){if(!pairs.has(card.device))pairs.set(card.device,[]);pairs.get(card.device).push(card.id);}
 let at=start+1400;
 for(const pair of pairs.values()){for(const id of pair)await flip(a,id);at+=100;}
 assert(raw().clearAt>0);checks++;
 await advance(raw().clearAt+1);await a.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.room.board.stage===2);
 assert(raw().cards.every(x=>!beforeDevices.has(x.device)),'next stage does not repeat devices');checks++;
 assert.equal(await a.locator('.dt-card').count(),32);checks++;
 await a.reload();await a.waitForSelector('.dt-card');assert.equal((await state(a)).board.stage,2);checks++;
 await host.locator('[data-bt=stop]').click();await host.locator('.bt-confirm [value=yes]').click();await done(host);await a.waitForSelector('.bt-result-row');
 assert.equal(Object.keys(getDb().results).length,1);checks++;
 await a.locator('[data-bt=records]').click();await a.waitForSelector('[data-bt=record]');checks++;
 const large=setup({op:'large-device-board',title:'36명 장치 팀전',teams:6,capacity:6,duration:180,difficulty:'challenge'});
 await advance(large.startedAt+10);await a.locator('[data-bt=list]').click();await a.locator('[name=code]').fill(large.code);await a.locator('[data-bt-form=join] [type=submit]').click();await a.waitForSelector('.dt-card');
 assert.equal(await a.locator('.dt-card').count(),64);checks++;
 const board=getDb().rooms[large.code].boards[1],photo=board.cards.find(x=>x.kind==='photo');await flip(a,photo.id);
 await a.waitForFunction(()=>[...document.querySelectorAll('.dt-card img')].every(i=>i.complete&&i.naturalWidth>0));checks++;
 for(const viewport of [{width:1280,height:720},{width:390,height:844},{width:834,height:1194},{width:1194,height:834}]){
  await a.setViewportSize(viewport);await a.waitForTimeout(200);await a.screenshot({path:path.join(out,'device-team-'+viewport.width+'.png')});
  const m=await a.locator('.eg-focus-area').evaluate(e=>({x:e.scrollWidth-e.clientWidth,y:e.scrollHeight-e.clientHeight}));assert(m.x<=1&&m.y<=1,'64 cards fit '+JSON.stringify({viewport,m}));checks++;
  assert(await a.locator('.dt-deck').evaluate(e=>e.children.length%getComputedStyle(e).gridTemplateColumns.split(' ').length===0),'no incomplete last row');checks++;
  assert(await a.locator('.dt-card img').evaluateAll(images=>images.every(i=>{const r=i.getBoundingClientRect(),p=i.closest('button').getBoundingClientRect();return r.width>0&&r.height>0&&r.left>=p.left&&r.right<=p.right+1&&r.top>=p.top&&r.bottom<=p.bottom+1&&getComputedStyle(i).objectFit==='contain';})),'photo is contained in card');checks++;
 }
 await a.setViewportSize({width:1194,height:760});await a.waitForTimeout(200);
 const resized=await a.locator('.eg-focus-area').evaluate(e=>({x:e.scrollWidth-e.clientWidth,y:e.scrollHeight-e.clientHeight}));assert(resized.x<=1&&resized.y<=1,'height-only resize keeps controls visible '+JSON.stringify(resized));checks++;
 await a.locator('.dt-card[data-state=open]').first().click();assert(await a.locator('.dt-inspector img').count());checks++;
 console.log(`PASS: ${checks} device team ${wsMode?'WebSocket':'REST'} multi-client, 64-card viewport, automatic next-board, image and record checks.`);
};
