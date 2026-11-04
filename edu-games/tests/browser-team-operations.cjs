const assert=require('node:assert/strict'),path=require('node:path');
module.exports=async({browser,base,out,state,done,advance,wsMode,game,getDb,setup})=>{
 let checks=0;const contexts=[],errors=[];
 const open=async(id,screen='student',code='')=>{
  const context=await browser.newContext({viewport:{width:1440,height:900},extraHTTPHeaders:{'X-Test-User':String(id)}});contexts.push(context);const p=await context.newPage();p.setDefaultTimeout(20000);p.on('pageerror',e=>errors.push(e.message));
  await p.addInitScript(()=>{const interval=window.setInterval.bind(window);window.setInterval=(fn,ms,...args)=>interval(fn,ms===700?1800:ms,...args);});
  await p.goto(`${base}/?user=${id}&screen=${screen}&room=${code}`);return p;
 };
 const poll=p=>p.locator('[data-binary-team]').evaluate(r=>r.__BinaryTeam.poll());
 const waitPlayers=(p,n)=>p.waitForFunction(n=>document.querySelector('[data-binary-team]').__BinaryTeam.room?.players.length===n,n);
 const classroomCheck=async()=>{
  const classroom=setup({title:'30명 학급 경기',teams:6,capacity:5,duration:180,op:'operations-classroom-30'});await advance(classroom.startedAt+1000);const projector=await open(100,'board',classroom.code);await projector.waitForSelector('.bt-activity');await projector.setViewportSize({width:1280,height:720});await projector.waitForTimeout(200);
  assert.equal(await projector.locator('.bt-ops-team').count(),6);assert.equal((await state(projector)).players.length,30);checks+=2;
  await projector.screenshot({path:path.join(out,`operations-classroom-${game}.png`)});
  const fit=await projector.locator('.eg-focus-area').evaluate(e=>({x:e.scrollWidth-e.clientWidth,y:e.scrollHeight-e.clientHeight,sections:[...e.querySelectorAll('.bt-ops-heading,.bt-ops-teams,.bt-feed,.bt-ops-team')].map(n=>[n.className,Math.round(n.getBoundingClientRect().height)])}));assert(fit.x<=1&&fit.y<=1,'30-person projector fits '+JSON.stringify(fit));checks++;
 };
 try{
  if(process.env.TEAM_CLASSROOM_ONLY){await classroomCheck();assert.deepEqual(errors,[]);console.log('PASS: 30-person projector '+game);return;}
  const host=await open(100,'teacher');await host.waitForSelector('[data-bt-form=create]');
  assert.equal(await host.locator('[name=mode]').inputValue(),'official');checks++;
  await host.locator('[data-bt-form=create] [type=submit]').click();await host.waitForSelector('.bt-project-code');const code=(await state(host)).code;
  const players=[];for(const id of [1,2,3,4]){const p=await open(id);players.push(p);await p.locator('[data-bt-form=join] [name=code]').fill(code);await p.locator('[data-bt-form=join] [type=submit]').click();await p.waitForSelector('.bt-waiting');assert.equal(await p.locator('[data-bt-assign]').count(),0);assert(await p.locator('[data-bt=ready]').isDisabled());checks+=2;}
  await waitPlayers(host,4);assert((await state(host)).players.every(p=>p.team===0));checks++;
  await host.locator('[data-bt=balance]').click();await done(host);assert((await state(host)).players.every(p=>p.team>0));checks++;
  const first=(await state(host)).players[0];await host.locator(`[data-bt-assign="${first.id}"]`).selectOption('0');await done(host);await host.locator(`[data-bt-assign="${first.id}"]`).selectOption(String(first.team));await done(host);await host.locator('.bt-ops-heading').click();
  assert.equal((await state(host)).players.find(p=>p.id===first.id).team,first.team);checks++;
  const board=await open(100,'board',code);await board.waitForSelector('.bt-project-code');assert.equal(await board.locator('[data-bt]').count(),0);checks++;
  assert((await state(board)).readOnly&&!(await state(board)).host);checks++;
  const forbidden=await board.evaluate(async()=>{const t=document.querySelector('[data-binary-team]').__BinaryTeam;const ticket=await t.getTicket();const response=await fetch(t.config.relayUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({game:t.config.gameId,ticket,code:t.code,command:{type:'start',op:'projector-mutate-test',round:t.room.round}})});return response.status;});assert.equal(forbidden,403);checks++;
  for(const p of players){await poll(p);await p.locator('[data-bt=ready]').click();await done(p);}
  await host.waitForFunction(()=>document.querySelector('[data-bt=start]')?.disabled===false);await host.locator('[data-bt=start]').click();await done(host);const start=(await state(host)).startedAt;await advance(start+1000);await poll(board);await board.waitForSelector('.bt-activity');
  assert.equal((await state(board)).teams.length,2);assert.equal((await state(board)).board,null);checks+=2;
  const raw=JSON.stringify(await state(board));for(const key of ['decimal','prompt','bits','device','face','seed','botState']){assert(!raw.includes(`"${key}":`));checks++;}
  if(wsMode){await host.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.socket?.ready);await board.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.socket?.ready);checks++;}
  for(const viewport of [{width:1440,height:900},{width:834,height:1194},{width:390,height:844}]){await board.setViewportSize(viewport);await board.waitForTimeout(150);const size=await board.locator('[data-binary-team]').evaluate(r=>({client:r.clientWidth,scroll:r.scrollWidth}));assert(size.scroll<=size.client+1,JSON.stringify({viewport,size}));checks++;await board.screenshot({path:path.join(out,`operations-${game}-${viewport.width}.png`),fullPage:true});}
  await host.locator('[data-bt=pause]').click();await done(host);await poll(board);await board.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.room.status==='paused');checks++;
  await host.locator('[data-bt=stop]').click();await host.locator('.bt-confirm [value=yes]').click();await host.waitForSelector('.bt-result-row');await done(host);await poll(board);await board.waitForSelector('.bt-result-row');assert.equal(await board.locator('[data-bt]').count(),0);assert.equal(getDb().results[code+'-1'].mode,'official');checks+=2;
  const student=players[0];await student.locator('[data-bt=list]').click();await student.waitForSelector('[data-bt-form=create]');assert.equal(await student.locator('[name=mode]').count(),0);checks++;
  await student.locator('[data-bt-form=create] [type=submit]').click();await student.waitForSelector('.bt-code');
  assert.equal(await student.locator('[data-bt-bot-level]').inputValue(),'slow');await student.locator('[data-bt-bot-level]').selectOption('normal');await done(student);assert.equal((await state(student)).config.botPace,'normal');await student.locator('[data-bt-bot-level]').selectOption('slow');await done(student);checks+=2;
  await student.locator('[data-bt=bot-fill]').click();await done(student);assert.equal((await state(student)).players.filter(p=>p.bot).length,3);checks++;
  await student.locator('[data-bt=ready]').click();await done(student);await student.locator('[data-bt=start]').click();await done(student);const practice=await state(student);for(let at=practice.startedAt;at<=practice.startedAt+20000;at+=1000){await advance(at);await poll(student);}
  await student.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.room.players.some(p=>p.bot&&p.edits>0));
  assert((await state(student)).players.filter(p=>p.bot).some(p=>p.edits>0));checks++;
  await student.locator('[data-bt=stop]').click();await student.locator('.bt-confirm [value=yes]').click();await student.waitForSelector('.bt-result-row');await done(student);const result=getDb().results[practice.code+'-1'];assert(result.mode==='practice'&&result.hasBots);checks++;
  await student.locator('[data-bt=records]').click();await student.waitForSelector('[data-bt-record-mode]');await student.locator('[data-bt-record-mode]').selectOption('official');await student.waitForTimeout(700);assert.equal(await student.locator('[data-bt=record]').count(),1);checks++;
  const publicTeacher=await open(100);await publicTeacher.waitForSelector('[data-bt-form=create]');assert.equal(await publicTeacher.getByText('교사 운영실',{exact:true}).count(),0);assert(await publicTeacher.locator('[name=participate]').isChecked());checks+=2;
  await student.locator('[data-bt=list]').click();await student.locator('[data-bt-form=create] [type=submit]').click();await student.waitForSelector('.bt-code');
  const soloCode=(await state(student)).code;await student.locator('[data-bt=practice-start]').click();await student.waitForFunction(()=>document.querySelector('[data-binary-team]').__BinaryTeam.room.status==='playing');await done(student);
  const quick=await state(student);assert.equal(quick.players.filter(p=>p.bot).length,3);assert(quick.myTeam>0&&quick.players.every(p=>p.ready));checks+=2;
  await advance(quick.startedAt+4000);await poll(student);await student.waitForSelector('[data-bt-board]');
  for(const viewport of [{width:1280,height:720},{width:390,height:844}]){await student.setViewportSize(viewport);await student.waitForTimeout(200);await student.screenshot({path:path.join(out,`team-solo-${game}-${viewport.width}.png`)});const fit=await student.locator('.eg-focus-area').evaluate(e=>({x:e.scrollWidth-e.clientWidth,y:e.scrollHeight-e.clientHeight}));assert(fit.x<=1&&fit.y<=1,'solo board fits '+JSON.stringify({viewport,fit}));checks++;}
  await student.locator('[data-bt=close]').click();await student.locator('.bt-confirm [value=cancel]').click();assert(getDb().rooms[soloCode]);checks++;
  await student.locator('[data-bt=close]').click();await student.locator('.bt-confirm [value=yes]').click();await student.waitForSelector('[data-bt-form=create]');await done(student);
  assert(!getDb().rooms[soloCode]&&getDb().results[soloCode+'-1']);assert.equal(await student.locator(`[data-bt=open][data-code="${soloCode}"]`).count(),0);checks+=2;
  await student.locator(`[data-bt=delete-room][data-code="${practice.code}"]`).click();await student.locator('.bt-confirm [value=yes]').click();await student.waitForFunction(code=>!document.querySelector(`[data-bt=delete-room][data-code="${code}"]`),practice.code);assert(!getDb().rooms[practice.code]&&getDb().results[practice.code+'-1']);checks++;
  await student.locator('[data-bt-form=create] [type=submit]').click();await student.waitForSelector('.bt-code');const emptyCode=(await state(student)).code;
  const guest=players[1];await guest.locator('[data-bt=list]').click();await guest.locator('[data-bt-form=join] [name=code]').fill(emptyCode);await guest.locator('[data-bt-form=join] [type=submit]').click();await guest.waitForSelector('.bt-code');
  await student.locator('[data-bt=close]').click();await student.locator('.bt-confirm [value=yes]').click();await student.waitForSelector('[data-bt-form=create]');await poll(guest);await guest.waitForSelector('[data-bt-form=create]');assert(!getDb().rooms[emptyCode]);checks++;
  await host.locator('[data-bt=list]').click();await host.locator(`[data-bt=delete-room][data-code="${code}"]`).click();await host.locator('.bt-confirm [value=yes]').click();await host.waitForFunction(code=>!document.querySelector(`[data-bt=delete-room][data-code="${code}"]`),code);assert(!getDb().rooms[code]&&getDb().results[code+'-1']);checks++;
  await classroomCheck();
  assert.deepEqual(errors,[]);checks++;console.log(`PASS: ${checks} ${game} teacher/student/projector, manual/random assignment, readonly relay, bot practice and records browser checks (${wsMode?'WebSocket':'HTTP'}).`);
 }finally{for(const c of contexts)await c.close();}
};
