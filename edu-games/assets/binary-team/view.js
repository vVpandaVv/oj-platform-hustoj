(() => {
    'use strict';
    const h = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const states = { lobby:'대기실', playing:'경기 중', paused:'일시 정지', finished:'경기 종료' };
    const reason = { overflow:'8줄 포화', time:'시간 종료', host:'방장 종료', 'pause-timeout':'장시간 정지' };
    const seconds = value => Math.max(0, Math.ceil(value / 1000));
    const clock = value => `${Math.floor(seconds(value)/60)}:${String(seconds(value)%60).padStart(2,'0')}`;
    const button = (label, action, extra = '', primary = false) => `<button type="button" data-bt="${action}" ${extra} class="oj-binary-button ${primary?'oj-binary-button-primary bt-primary':''}">${label}</button>`;

    class BinaryTeam {
        constructor(root) {
            this.root=root;this.config=JSON.parse(root.dataset.config||'{}');this.app=root.querySelector('[data-bt-app]');this.message=root.querySelector('[data-bt-message]');
            this.room=null;this.code='';this.watch=0;this.screen='rooms';this.rows=new Map();this.drafts=new Map();this.bitDrafts=new Map();this.answering=new Set();this.pending=0;this.queue=Promise.resolve();this.polling=false;this.offset=0;this.failures=0;
            this.key=this.config.gameId==='device-matching'?`edu-games-device-team:${this.config.currentUserId}`:`edu-games-binary-team:${this.config.currentUserId}`;
            this.operations=this.config.screen&&this.config.screen!=='student'?new window.EduTeamOperations(this):null;
            if(this.operations)this.key+=`:${this.config.screen}`;
            this.cardBoard=this.config.gameId==='device-matching'?new window.EduDeviceTeamBoard(this):null;
            this.socket=this.config.websocketUrl&&window.EduBinarySocket?new window.EduBinarySocket({url:this.config.websocketUrl,channel:this.config.channel||'binary-team',ticket:()=>this.getTicket(),
                state:room=>{if(room.code===this.code){this.receive(room);this.failures=0;this.root.classList.remove('bt-disconnected');}},status:()=>this.connectionLabel()}):null;
            this.root.addEventListener('click',e=>this.click(e));this.root.addEventListener('submit',e=>this.submit(e));
            this.root.addEventListener('change',e=>{
                if(e.target.matches('[data-bt-assign]'))this.command({type:'team',user:Number(e.target.dataset.btAssign),team:Number(e.target.value)});
                if(e.target.matches('[data-bt-watch]')){this.watch=Number(e.target.value);this.poll();}
                if(e.target.matches('[data-bt-bot-level]'))this.command({type:'bot-level',pace:e.target.value});
            });
            this.root.addEventListener('input',e=>this.editAnswer(e));
            this.root.addEventListener('compositionend',e=>this.editAnswer(e));
            this.root.addEventListener('focusin',e=>{
                if(!e.target.matches('[data-bt-answer]'))return;
                const row=this.room?.board?.rows.find(r=>r.id===Number(e.target.dataset.btAnswer));
                if(row&&row.owner!==this.room.me)this.command({type:'claim',row:row.id});
            });
            this.root.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.isComposing&&e.target.matches('[data-bt-answer]')){e.preventDefault();this.answer(Number(e.target.dataset.btAnswer));}});
            this.root.addEventListener('focusout',e=>{if(e.target.matches('[data-bt-assign]')&&this.operations)setTimeout(()=>{if(this.room?.overview)this.operations.render();},0);});
            this.timer=setInterval(()=>{if(!root.isConnected){clearInterval(this.timer);clearInterval(this.clockTimer);this.socket?.close();return;}if(this.code&&document.visibilityState==='visible')this.poll();},700);
            this.clockTimer=setInterval(()=>this.tick(),100);
            window.addEventListener('online',()=>this.poll());document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')this.poll();});
            if(!this.config.isLoggedIn){this.app.innerHTML='<div class="bt-empty">로그인 후 팀전에 참가할 수 있습니다.</div>';return;}
            try{this.code=this.config.initialRoom||localStorage.getItem(this.key)||'';}catch{this.code=this.config.initialRoom||'';}
            if(this.code)this.poll();else this.list();
        }
        op(){return window.crypto?.randomUUID?.()||`${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}`;}
        async request(path='',body,op) {
            const payload=body===undefined?undefined:{...body,op:op||this.op()};
            if(payload&&this.socket?.ready&&path.startsWith(`/rooms/${this.socket.code}`)){
                try{return await this.socket.request(payload);}catch(error){if(!error.fallback)throw error;}
            }
            if(this.config.relayUrl&&!this.relayDisabled&&/^\/rooms\/[A-Z2-9]{6}(?:\?|$)/.test(path)){
                try{return await this.fastRequest(path,payload);}catch(error){if(!error.fallback)throw error;this.relayDisabled=true;}
            }
            return this.restRequest(path,payload);
        }
        async restRequest(path='',body) {
            if(this.operations){const role=this.config.screen==='board'?'board':'control';path+=`${path.includes('?')?'&':'?'}role=${role}`;if(path.startsWith('/ticket'))path+=`&room=${encodeURIComponent(this.code)}`;}
            const headers={'X-WP-Nonce':this.config.nonce};if(body!==undefined)headers['Content-Type']='application/json';
            return this.fetchJson(this.config.restUrl+path,{method:body===undefined?'GET':'POST',headers,body:body===undefined?undefined:JSON.stringify(body)});
        }
        async fetchJson(url,options){
            const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
            try{
                const response=await fetch(url,{...options,credentials:'same-origin',signal:controller.signal,cache:'no-store'});
                const data=await response.json().catch(()=>null);if(!response.ok||!data){const error=new Error(data?.message||'요청을 처리하지 못했습니다.');error.status=response.status;error.code=data?.code||'relay-unavailable';throw error;}return data;
            }finally{clearTimeout(timer);}
        }
        async getTicket(){
            if(this.ticketCode!==this.code){this.ticket=null;this.ticketCode=this.code;}
            if(!this.ticket||Date.now()>=this.ticketUntil){const data=await this.restRequest('/ticket');this.ticket=data.ticket;this.ticketUntil=Date.now()+60000;}
            return this.ticket;
        }
        connectionLabel(){const label=this.app.querySelector('.bt-connection');if(label)label.textContent=this.socket?.ready?'WebSocket':'REST';}
        async fastRequest(path,command){
            const url=new URL(path,location.origin),code=url.pathname.split('/').at(-1);
            for(let attempt=0;attempt<2;attempt++){
                if(!this.ticket||this.ticketCode!==code||Date.now()>=this.ticketUntil){
                    try{await this.getTicket();}
                    catch(error){error.fallback=true;throw error;}
                }
                try{return await this.fetchJson(this.config.relayUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({game:this.config.gameId||'binary-conversion',ticket:this.ticket,code,watch:Number(url.searchParams.get('watchTeam')||0),command:command??null})});}
                catch(error){if(error.status===401&&attempt===0){this.ticket=null;continue;}if(error.code==='relay-unavailable'||!error.status||error.status===401)error.fallback=true;throw error;}
            }
        }
        say(text,error=false){
            this.notice={text,error};
            if(this.app.querySelector('[data-bt-board-status]')){this.message.textContent='';this.paintStatus();}
            else{this.message.textContent=text;this.message.classList.toggle('bt-error',error);}
        }
        paintStatus(){
            if(this.cardBoard)return this.cardBoard.paintStatus();
            const field=this.app.querySelector('[data-bt-board-status]'),r=this.room;if(!field||!r)return;
            const base=r.status==='paused'?'경기가 일시 정지되었습니다.':r.board?.endedAt!==null&&r.board?'우리 팀 종료 · 다른 팀 경기 진행 중':r.board?.rows?.length===8?'위험 · 8줄 포화':'';
            field.textContent=this.notice?.text||base;field.classList.toggle('bt-error',Boolean(this.notice?.error&&this.notice?.text));
        }
        remember(){try{if(this.code)localStorage.setItem(this.key,this.code);else localStorage.removeItem(this.key);}catch{}}
        async poll(){
            if(!this.code||this.polling||this.pending)return;
            if(this.socket?.ready&&this.socket.code===this.code){this.socket.connect(this.code,this.watch);return;}
            this.polling=true;const code=this.code;
            try{
                const data=await this.request(`/rooms/${code}?watchTeam=${this.watch}`);
                if(this.code===code){this.receive(data);if(this.failures)this.say('연결이 복구되었습니다.');this.failures=0;this.root.classList.remove('bt-disconnected');}
            }catch(error){
                if(this.code!==code)return;
                if([403,404,410].includes(error.status)){const closed=error.status===404&&this.room;this.code='';this.room=null;this.remember();this.say(closed?'방이 닫혔습니다.':error.message,!closed);this.list();}
                else{this.failures++;this.say('재연결 중 · 서버의 경기 시간은 계속 흐릅니다.',true);this.root.classList.add('bt-disconnected');}
            }finally{this.polling=false;}
        }
        command(body){
            const code=this.code,round=this.room?.round,op=this.op();this.pending++;
            const draftKey=body.type==='bit'?`${round}:${body.row}:${body.bit}`:null;
            if(draftKey){this.bitDrafts.set(draftKey,{op,value:body.value});this.syncRows(this.room.board.rows);}
            this.queue=this.queue.then(async()=>{
                if(code!==this.code||round!==this.room?.round)return;
                if(['bit','answer'].includes(body.type)&&!this.room.board?.rows.some(r=>r.id===body.row))return;
                const wrongBefore=this.room.players.find(p=>p.id===this.room.me)?.wrong||0;
                let data;
                for(let attempt=0;attempt<2;attempt++){
                    try{data=await this.request(`/rooms/${code}?watchTeam=${this.watch}`,{...body,round},op);break;}
                    catch(error){if(attempt===0&&(!error.status||error.status===503||error.status===429)){await new Promise(r=>setTimeout(r,error.status===429?650:300));continue;}throw error;}
                }
                if(draftKey&&this.bitDrafts.get(draftKey)?.op===op)this.bitDrafts.delete(draftKey);
                if(code===this.code){
                    if(data.left){this.code='';this.room=null;this.remember();await this.list();}else this.receive(data);
                    if(body.type==='answer'&&(data.players?.find(p=>p.id===data.me)?.wrong||0)>wrongBefore){this.say('10진수 답이 다릅니다.',true);this.app.querySelector(`[data-bt-answer="${body.row}"]`)?.setAttribute('aria-invalid','true');}
                    else this.say('');
                }
            }).catch(error=>{
                if(draftKey&&this.bitDrafts.get(draftKey)?.op===op)this.bitDrafts.delete(draftKey);
                if(this.room?.board&&this.app.querySelector('[data-bt-board]')){if(this.cardBoard)this.cardBoard.sync();else this.syncRows(this.room.board.rows);}
                this.say(error.message,true);
            }).finally(()=>{this.pending--;if(!this.pending)this.poll();});
            return this.queue;
        }
        receive(room){
            if(room.code!==this.code)return;
            if(!this.room)this.say('');
            if(this.room?.code===room.code&&this.room.revision>room.revision)return;
            this.offset=room.serverNow-Date.now();
            const crossingStart=this.room?.serverNow<room.startedAt&&room.serverNow>=room.startedAt;
            const same=this.room?.revision===room.revision&&this.room?.watchTeam===room.watchTeam&&this.room?.overview===room.overview&&!crossingStart;
            this.room=room;this.remember();this.root.dataset.phase=room.status;
            if(this.operations){
                this.root.dataset.phase=this.config.screen==='teacher'?'manage':room.status;
                if(!room.overview){this.poll();return;}
                this.socket?.connect(room.code,this.watch);if(!same)this.operations.render();this.tick();return;
            }
            this.socket?.connect(room.code,this.watch);
            if(same){this.tick();return;}
            if(room.status==='lobby')this.lobby();
            else if(room.status==='finished'){this.result(room.summary,true);}
            else this.play();
            this.tick();
        }
        async list(){
            this.socket?.close();
            this.room=null;
            this.screen='rooms';this.root.dataset.phase='idle';this.root.__GameViewport?.close();this.rows.clear();
            this.app.innerHTML=`<nav class="bt-tabs">${button('방 목록','list','','true')}${button('참여 기록','records')}</nav>
                <div class="bt-entry"><form data-bt-form="join"><h3>방 참가</h3><div class="bt-inline"><input name="code" aria-label="방 코드" placeholder="6자리 방 코드" minlength="6" maxlength="6" required autocomplete="off" autocapitalize="characters"> <button class="bt-primary" type="submit">참가</button></div></form>
                <form data-bt-form="create"><h3>연습방 만들기</h3><label>방 이름<input name="title" value="${h(this.config.title||'이진수 팀전')}" maxlength="60" required></label><div class="bt-options">
                <label>팀 수<select name="teams">${[2,3,4,5,6].map(n=>`<option>${n}</option>`).join('')}</select></label>
                <label>팀 정원<select name="capacity">${[2,3,4,5,6].map(n=>`<option ${n===5?'selected':''}>${n}</option>`).join('')}</select></label>
                <label>경기 시간<select name="duration"><option value="120">2분</option><option value="180" selected>3분</option><option value="300">5분</option></select></label>
                <label>팀 난도<select name="difficulty"><option value="standard">팀 표준</option><option value="challenge">팀 도전</option></select></label></div>
                <label>봇 난이도<select name="botPace"><option value="slow" selected>입문</option><option value="normal">보통</option><option value="fast">도전</option></select></label><label class="bt-check"><input type="checkbox" name="participate" checked>나도 선수로 참가</label><button class="oj-binary-button oj-binary-button-primary bt-primary" type="submit">연습방 만들기</button></form></div>
                <section><div class="bt-section-title"><h3>내 방</h3>${button('새로 고침','list')}</div><div data-bt-rooms>불러오는 중</div></section>`;
            if(this.operations)return this.operations.list();
            try{const data=await this.request('/rooms');if(this.screen!=='rooms')return;this.app.querySelector('[data-bt-rooms]').innerHTML=data.items.map(r=>`<div class="bt-room-list"><strong>${h(r.title)}</strong><span>${h(r.code)} · ${states[r.status]} · ${r.players}명</span><div class="bt-inline">${button('입장','open',`data-code="${h(r.code)}"`)}${r.host&&r.mode==='practice'?button('방 닫기','delete-room',`data-code="${h(r.code)}" data-round="${r.round}"`):''}</div></div>`).join('')||'<p class="bt-muted">참가한 방이 없습니다.</p>';}catch(error){this.say(error.message,true);}
        }
        async submit(event){
            const form=event.target.closest('[data-bt-form]');if(!form)return;event.preventDefault();const values=Object.fromEntries(new FormData(form));
            const submit=form.querySelector('[type=submit]');submit.disabled=true;
            try{
                if(form.dataset.btForm==='create'){
                    this.createOp||=this.op();
                    const data=await this.request('/rooms',{title:values.title,teams:Number(values.teams),capacity:Number(values.capacity),duration:Number(values.duration),difficulty:values.difficulty,participate:values.participate==='on',mode:this.operations?values.mode:'practice',assignment:values.assignment||'auto',botPace:values.botPace||'slow'},this.createOp);
                    this.createOp=null;
                    this.code=data.code;this.room=null;this.receive(data);
                }else if(this.operations){this.code=String(values.code).trim().toUpperCase();this.room=null;await this.poll();}
                else await this.join(String(values.code).trim().toUpperCase());
                    this.say('');
            }catch(error){this.say(error.message,true);}finally{submit.disabled=false;}
        }
        async join(code){
            const old=this.code;this.code=code;this.room=null;
            try{const data=await this.request(`/rooms/${code}`,{type:'join'});this.receive(data);}catch(error){this.code=old;throw error;}
        }
        async click(event){
            const b=event.target.closest('[data-bt]');if(!b||b.disabled)return;const action=b.dataset.bt;
            if(action==='list'){this.code='';this.room=null;this.remember();return this.list();}
            if(action==='records'){this.code='';this.remember();return this.records(Number(b.dataset.page||1));}
            if(action==='open'){this.code=b.dataset.code;this.room=null;return this.poll();}
            if(action==='join'){try{await this.join(this.code);}catch(error){this.say(error.message,true);}return;}
            if(action==='record'){try{const r=await this.request(`/records/${b.dataset.id}`);this.result(r,false);}catch(error){this.say(error.message,true);}return;}
            if(action==='ready')return this.command({type:'ready',ready:!this.room.players.find(p=>p.id===this.room.me)?.ready});
            if(action==='bit')return this.command({type:'bit',row:Number(b.dataset.row),bit:Number(b.dataset.bit),value:b.textContent==='1'?0:1});
            if(action==='claim'){const row=Number(b.dataset.row);await this.command({type:action,row});if(this.room?.board?.rows.find(r=>r.id===row)?.owner===this.room.me)this.app.querySelector(`[data-bt-answer="${row}"]`)?.focus({preventScroll:true});return;}
            if(action==='release')return this.command({type:action,row:Number(b.dataset.row)});
            if(action==='answer')return this.answer(Number(b.dataset.row));
            if(action==='delete-room'){
                if(!await this.confirm('방과 대기 중인 참가자가 정리됩니다. 진행 중인 경기는 현재 결과를 저장하며, 기존 기록은 삭제하지 않습니다.','방 닫기','방 닫기'))return;
                b.disabled=true;
                try{await this.restRequest(`/rooms/${b.dataset.code}`,{type:'close',round:Number(b.dataset.round),op:this.op()});await this.list();}
                catch(error){this.say(error.message,true);b.disabled=false;}return;
            }
            if(action==='close'||action==='leave'&&this.room?.host){
                if(!await this.confirm('모든 참가자가 방에서 나갑니다. 진행 중인 경기는 결과를 저장하고 방을 닫습니다.','방 닫고 나가기','방 닫기'))return;
                return this.command({type:'close'});
            }
            if(action==='stop'&&!await this.confirm('종료하면 모든 팀의 현재 기록이 확정됩니다.'))return;
            if(['balance','start','practice-start','pause','resume','stop','rematch','leave'].includes(action))return this.command({type:action});
            if(action==='kick')return this.command({type:'kick',user:Number(b.dataset.user)});
            if(['bot-add','bot-fill','bot-clear'].includes(action))return this.command({type:action,...(b.dataset.team?{team:Number(b.dataset.team)}:{})});
        }
        confirm(text,title='경기 종료',accept='경기 종료'){return new Promise(resolve=>{
            const dialog=document.createElement('dialog');dialog.className='bt-confirm';dialog.innerHTML=`<h3>${h(title)}</h3><p>${h(text)}</p><form method="dialog"><button value="cancel">취소</button><button value="yes">${h(accept)}</button></form>`;
            this.root.append(dialog);dialog.addEventListener('close',()=>{resolve(dialog.returnValue==='yes');dialog.remove();},{once:true});dialog.showModal();
        });}
        lobby(){
            const lobbyKey=JSON.stringify([this.room.code,this.room.round,this.room.host,this.room.config,this.room.players]);
            if(this.app.querySelector('.bt-lobby-teams')&&this.lobbyKey===lobbyKey)return;
            this.lobbyKey=lobbyKey;
            this.screen='room';this.rows.clear();this.bitDrafts.clear();const r=this.room,me=r.players.find(p=>p.id===r.me);
            const counts=r.teams.map(t=>r.players.filter(p=>p.team===t.team).length);
            const startable=counts.every(n=>n>=2&&n===counts[0])&&r.players.every(p=>p.team>0&&p.ready&&p.online);
            const old=document.activeElement,focusUser=old?.dataset.btAssign;
            this.app.innerHTML=`<div class="bt-room-heading"><div><h3>${h(r.title)}</h3><strong class="bt-code">${h(r.code)}</strong><span>${r.mode==='official'?'정식 경기':'연습'} · 대기실 · ${r.config.duration/60}분 · ${r.config.difficulty==='challenge'?'팀 도전':'팀 표준'}</span></div><div class="bt-inline">${r.host?button('방 닫고 나가기','close'):me?button('방 나가기','leave'):''}${button('방 목록','list')}</div></div>
                <div class="bt-lobby-teams">${r.teams.map(t=>`<section class="bt-team"><h4>${t.team}팀 <span>${counts[t.team-1]}/${r.config.capacity}</span></h4>${r.players.filter(p=>p.team===t.team).map(p=>`<div class="bt-player"><span class="bt-online ${p.online?'is-online':''}" title="${p.online?'접속 중':'연결 끊김'}"></span><strong>${h(p.name)}${p.id===r.me?' (나)':''}</strong><span class="bt-ready ${p.ready?'is-ready':''}">${p.ready?'준비':'대기'}</span>
                ${r.host||(r.mode!=='official'&&p.id===r.me)?`<select data-bt-assign="${p.id}" aria-label="${h(p.name)} 팀">${r.teams.map(x=>`<option value="${x.team}" ${x.team===p.team?'selected':''}>${x.team}팀</option>`).join('')}</select>`:''}${r.host&&p.id!==r.me?button('×','kick',`data-user="${p.id}" title="${h(p.name)} 참가 해제" aria-label="${h(p.name)} 참가 해제"`):''}</div>`).join('')||'<p class="bt-muted">참가 대기</p>'}${r.host&&r.mode==='practice'?button('봇 추가','bot-add',`data-team="${t.team}"`):''}</section>`).join('')}</div>
                ${r.players.some(p=>p.team===0)?`<section class="bt-waiting"><h4>팀 배정 대기</h4>${r.players.filter(p=>p.team===0).map(p=>`<span>${h(p.name)}</span>`).join('')}</section>`:''}
                <div class="bt-lobby-actions">${r.host&&r.mode==='practice'&&!r.players.some(p=>!p.bot&&p.id!==r.me)?button('봇과 바로 시작','practice-start','',true):''}${me?button(me.ready?'준비 취소':'준비하기','ready',`aria-pressed="${me.ready}" ${me.team?'':'disabled'}`):button('선수로 참가','join')}${r.host?button('무작위 팀 배정','balance')+(r.mode==='practice'?button('빈자리 봇 채우기','bot-fill')+button('봇 모두 빼기','bot-clear'):'')+button('경기 시작','start',startable?'':'disabled',true):''}</div>
                ${r.host&&r.mode==='practice'?`<label class="bt-inline bt-bot-level">봇 난이도 <select data-bt-bot-level aria-label="봇 난이도">${[['slow','입문'],['normal','보통'],['fast','도전']].map(([value,label])=>`<option value="${value}" ${value===(r.config.botPace||'slow')?'selected':''}>${label}</option>`).join('')}</select></label>`:''}<p class="bt-muted">각 팀 2명 이상 · 같은 인원 · 전원 준비</p>`;
            if(focusUser)this.app.querySelector(`[data-bt-assign="${focusUser}"]`)?.focus({preventScroll:true});
        }
        play(){
            this.screen='room';const r=this.room;
            this.root.style.setProperty('--bt-teams',String(r.teams.length));
            if(!this.app.querySelector('[data-bt-board]')){
                const weights='<div class="bt-weights oj-binary-grid-bits">'+[128,64,32,16,8,4,2,1].map(n=>`<span class="oj-binary-grid-cell">${n}</span>`).join('')+'</div>';
                const boardHTML=this.cardBoard?this.cardBoard.template():`<section class="bt-binary-board oj-binary-board" aria-label="이진수 팀 게임판"><div class="bt-board-head oj-binary-board-head"><span class="oj-binary-grid-mode">형식</span><span class="oj-binary-grid-prompt">문제</span>${weights}<span class="oj-binary-grid-answer">현재값</span><span class="bt-grid-owner">담당</span></div><div class="bt-danger-line">위험선</div><div class="bt-board oj-binary-lanes" data-bt-board></div><div class="bt-board-foot oj-binary-board-foot"><span class="oj-binary-grid-mode">자리값</span><span class="oj-binary-grid-prompt">10진수</span>${weights}<span class="oj-binary-grid-answer">합계</span><span class="bt-grid-owner"></span></div></section><p class="bt-board-status" data-bt-board-status role="status"></p>`;
                const boardHeading=this.cardBoard?'<div class="oj-device-stagebar"><div><span data-bt-pressure></span><h3 data-bt-board-title></h3></div><div class="oj-device-progress" data-dt-progress></div></div>':'<div class="bt-board-heading"><strong data-bt-board-title></strong><span data-bt-pressure></span></div>';
                const stat=this.cardBoard?'oj-device-stat':'oj-binary-sidecard',stats=this.cardBoard?'oj-device-stats':'oj-binary-sidebar';
                const scoreStat=`<div class="${stat}"><span>점수</span><strong data-bt-score>0</strong></div>`,levelStat=`<div class="${stat}"><span>${this.cardBoard?'단계':'레벨'}</span><strong data-bt-level>1</strong></div>`;
                this.rows.clear();this.app.innerHTML=`<div class="bt-sessionbar"><span data-bt-room-label></span><small class="bt-connection">실시간</small><div class="bt-play-actions" data-bt-host></div></div><div class="bt-metrics ${stats}" aria-label="게임 상태">${this.cardBoard?levelStat+scoreStat:scoreStat+levelStat}<div class="${stat}"><span>연속 정답</span><strong data-bt-combo>0</strong></div><div class="${stat}"><span>정답·오답</span><strong data-bt-correct>0 / 0</strong></div><div class="${stat}"><span>남은 시간</span><strong data-bt-time></strong></div></div>
                    <details class="bt-team-summary"><summary>팀 현황 <span data-bt-team-rank></span></summary>${this.cardBoard?'':boardHeading}<div class="bt-team-scores" data-bt-scores></div><div class="bt-playing-members" data-bt-members></div></details>${this.cardBoard?boardHeading:''}
                    ${boardHTML}`;
            }
            const host=this.app.querySelector('[data-bt-host]');
            const hostHtml=`${r.host?button(r.status==='paused'?'계속':'일시 정지',r.status==='paused'?'resume':'pause')+button('경기 종료','stop')+button('방 닫기','close'):''}${button('목록 보기','list')}${r.canWatch?`<select data-bt-watch aria-label="관전 팀">${r.teams.map(t=>`<option value="${t.team}" ${t.team===r.watchTeam?'selected':''}>${t.team}팀</option>`).join('')}</select>`:''}`;
            const hostKey=`${r.host}:${r.status}:${r.canWatch}:${r.watchTeam}:${r.teams.length}`;
            if(host.dataset.key!==hostKey){host.innerHTML=hostHtml;host.dataset.key=hostKey;}
            this.app.querySelector('[data-bt-room-label]').textContent=`${r.watchTeam}팀 · ${r.code}${r.mode==='practice'?' · 연습':''}`;
            const mine=r.teams.find(t=>t.team===r.watchTeam);this.app.querySelector('[data-bt-team-rank]').textContent=mine?`${1+r.teams.filter(t=>t.score>mine.score).length}위 / ${r.teams.length}팀`:'';
            this.app.querySelector('[data-bt-scores]').innerHTML=r.teams.map(t=>`<div class="${t.team===r.myTeam?'is-mine':''}"><strong>${t.team}팀</strong><b>${t.score.toLocaleString()}점</b><span>${this.cardBoard?`${t.stage}판 · ${t.correct}쌍`:t.ended?'종료':`${t.occupied}/8줄`}</span></div>`).join('');
            this.app.querySelector('[data-bt-members]').innerHTML=r.players.filter(p=>p.team===r.watchTeam).map(p=>`<span class="${p.online?'':'is-offline'}">${h(p.name)} <b>${p.correct}</b></span>`).join('');
            const b=r.board;this.app.querySelector('[data-bt-board-title]').textContent=b?`${b.team}팀 공유 보드${b.endedAt!==null?' · 종료':''}`:'관전 대기';
            this.app.querySelector('[data-bt-pressure]').innerHTML=b&&!this.cardBoard?`${b.size}명 · ${b.difficulty.spawnMs/1000}초 간격 · 역변환 ${Math.round(b.difficulty.inverseChance*100)}% · 다음 <b data-bt-next></b>초`:'';
            this.root.classList.toggle('bt-danger',Boolean(b?.rows?.length===8&&b.endedAt===null));
            this.app.querySelector('[data-bt-score]').textContent=(b?.score||0).toLocaleString();this.app.querySelector('[data-bt-correct]').textContent=`${b?.correct||0} / ${b?.wrong||0}`;
            this.app.querySelector('[data-bt-level]').textContent=b?.stage||b?.level||1;this.app.querySelector('[data-bt-combo]').textContent=b?.combo||0;
            const boardKey=`${r.code}:${r.round}:${r.watchTeam}`;
            if(this.boardKey!==boardKey){this.boardKey=boardKey;this.rows.clear();this.drafts.clear();this.bitDrafts.clear();this.app.querySelector('[data-bt-board]').replaceChildren();}
            if(this.cardBoard)this.cardBoard.sync();else this.syncRows(b?.rows||[]);
            this.paintStatus();
            this.connectionLabel();
        }
        syncRows(rows){
            const board=this.app.querySelector('[data-bt-board]'),r=this.room,ids=new Set(rows.map(x=>x.id));
            for(const[id,node]of this.rows)if(!ids.has(id)){node.remove();this.rows.delete(id);this.drafts.delete(id);}
            for(const row of rows){
                let node=this.rows.get(row.id);
                if(!node){node=document.createElement('article');node.className='bt-line oj-binary-row';node.dataset.btRow=row.id;
                    node.innerHTML=`<div class="bt-mode oj-binary-row-mode">${row.mode==='dec-to-bin'?'10→2':'2→10'}</div><div class="bt-prompt oj-binary-row-prompt ${row.mode==='bin-to-dec'?'is-binary':''}"><strong>${h(row.prompt)}</strong></div><div class="bt-bits oj-binary-row-bits"></div><div class="bt-answer oj-binary-row-answer"></div><div class="bt-owner"></div>`;
                    const bits=node.querySelector('.bt-bits');
                    for(let bit=0;bit<8;bit++){const cell=document.createElement(row.mode==='dec-to-bin'?'button':'span');cell.className='oj-binary-row-bit';if(row.mode==='dec-to-bin'){cell.type='button';cell.dataset.bt='bit';cell.dataset.row=row.id;cell.dataset.bit=bit;cell.setAttribute('aria-label',`${128>>bit} 자리 비트`);}else cell.classList.add('is-readonly');bits.append(cell);}
                    node.querySelector('.bt-answer').innerHTML=row.mode==='bin-to-dec'?`<input class="oj-binary-answer-input" inputmode="numeric" pattern="[0-9]*" maxlength="3" placeholder="입력" data-bt-answer="${row.id}" aria-label="${h(row.prompt)}의 10진수 답"><button type="button" data-bt="answer" data-row="${row.id}" title="답 제출" aria-label="답 제출">↵</button>`:'<output class="oj-binary-answer-output" title="선택한 비트의 합"></output>';
                    this.rows.set(row.id,node);board.append(node);
                }
                node.style.gridRow=String(8-rows.indexOf(row));
                const locked=Boolean(row.owner&&row.owner!==r.me),disabled=r.status!=='playing'||r.board.endedAt!==null||r.serverNow<r.startedAt||locked||(r.canWatch&&r.watchTeam!==r.myTeam)||!r.myTeam;
                node.classList.toggle('is-locked',locked);node.classList.toggle('is-owned',row.owner===r.me);
                const bits=row.bits.map((v,i)=>this.bitDrafts.get(`${r.round}:${row.id}:${i}`)?.value??v);
                [...node.querySelector('.bt-bits').children].forEach((cell,i)=>{const value=row.mode==='dec-to-bin'?bits[i]:Number(row.prompt[i]);cell.textContent=value;cell.classList.toggle('is-on',value===1);if(cell.tagName==='BUTTON'){cell.disabled=disabled;cell.setAttribute('aria-pressed',String(value===1));}});
                const output=node.querySelector('output');if(output)output.textContent=parseInt(bits.join(''),2);
                const input=node.querySelector('input');if(input){input.disabled=disabled;if(document.activeElement!==input)input.value=this.drafts.get(row.id)||'';node.querySelector('[data-bt=answer]').disabled=disabled;}
                const owner=r.players.find(p=>p.id===row.owner),ownerHtml=owner?`<span title="${h(owner.name)}">${h(owner.name)}</span>${row.owner===r.me?button('↗','release',`data-row="${row.id}" title="팀원에게 넘기기" aria-label="팀원에게 넘기기"`):''}`:button('풀기','claim',`data-row="${row.id}" ${disabled?'disabled':''}`);
                if(node.querySelector('.bt-owner').innerHTML!==ownerHtml)node.querySelector('.bt-owner').innerHTML=ownerHtml;
            }
            board.querySelectorAll('.bt-empty-row').forEach(e=>e.remove());
            for(let i=0;i<8-rows.length;i++){const empty=document.createElement('div');empty.className='bt-empty-row';empty.style.gridRow=String(i+1);empty.setAttribute('aria-hidden','true');board.append(empty);}
        }
        editAnswer(event){
            const input=event.target;if(!input.matches('[data-bt-answer]'))return;
            if(!event.isComposing)input.value=input.value.replace(/[^0-9]/g,'').slice(0,3);
            const id=Number(input.dataset.btAnswer);this.drafts.set(id,input.value);input.removeAttribute('aria-invalid');
            if(!event.isComposing)this.answer(id,true);
        }
        answer(id,automatic=false){
            const input=this.app.querySelector(`[data-bt-answer="${id}"]`),r=this.room,row=r?.board?.rows?.find(row=>row.id===id);
            if(!input||input.disabled||!input.value||!row||row.mode!=='bin-to-dec'||r.status!=='playing')return;
            const value=input.value;
            if(automatic&&(!/^[0-9]+$/.test(value)||Number(value)!==parseInt(row.prompt,2)))return;
            const key=`${r.code}:${r.round}:${r.watchTeam}:${id}`;if(this.answering.has(key))return;
            this.answering.add(key);
            return this.command({type:'answer',row:id,value}).finally(()=>this.answering.delete(key));
        }
        tick(){
            const r=this.room;if(!r||!['playing','paused'].includes(r.status))return;
            const now=r.status==='paused'?r.pausedAt:Date.now()+this.offset,field=this.app.querySelector('[data-bt-time]');if(!field)return;
            field.textContent=now<r.startedAt?`시작 ${seconds(r.startedAt-now)}`:clock(r.endsAt-now);
            const next=this.app.querySelector('[data-bt-next]');if(next&&r.board)next.textContent=(Math.max(0,r.board.nextSpawnAt-now)/1000).toFixed(1);
            if(r.status==='playing'&&r.serverNow<r.startedAt&&now>=r.startedAt)this.poll();
        }
        async records(page=1){
            this.socket?.close();
            this.screen='records';this.root.dataset.phase='idle';this.root.__GameViewport?.close();
            this.room=null;this.recordMode||='all';
            this.app.innerHTML=`<nav class="bt-tabs">${button('방 목록','list')}${button('참여 기록','records','','true')}<select data-bt-record-mode aria-label="경기 기록 종류"><option value="all">전체 경기</option><option value="official">정식 경기</option><option value="practice">연습 경기</option></select></nav><div data-bt-records>불러오는 중</div>`;
            const filter=this.app.querySelector('[data-bt-record-mode]');filter.value=this.recordMode;filter.onchange=()=>{this.recordMode=filter.value;this.records();};
            try{const data=await this.request(`/records?page=${page}&mode=${this.recordMode}`);if(this.screen!=='records')return;
                this.app.querySelector('[data-bt-records]').innerHTML=data.items.map(x=>`<div class="bt-room-list"><strong>${h(x.title)}</strong><span>${x.mode==='official'?'정식':'연습'}${x.hasBots?' · 봇 포함':''} · ${h(x.code)} · ${new Date(x.finishedAt).toLocaleString('ko-KR')}</span>${button('결과 보기','record',`data-id="${h(x.id)}"`)}</div>`).join('')||'<p class="bt-muted">참여 기록이 없습니다.</p>';
                const p=data.pagination;this.app.insertAdjacentHTML('beforeend',`<div class="bt-pagination">${button('이전','records',`data-page="${p.page-1}" ${p.page<=1?'disabled':''}`)}<span>${p.page}/${p.pages} · 총 ${p.total}경기</span>${button('다음','records',`data-page="${p.page+1}" ${p.page>=p.pages?'disabled':''}`)}</div>`);
            }catch(error){this.say(error.message,true);}
        }
        result(s,live){
            this.screen='result';this.rows.clear();const me=this.room?.me||this.config.currentUserId;
            this.app.innerHTML=`<div class="bt-result-head"><h3>${h(s.title)} · 결과</h3><span>${s.mode==='official'?'정식 경기':'연습'}${s.hasBots?' · 봇 포함':''} · ${reason[s.reason]||'경기 종료'} · ${seconds(s.durationMs)}초</span></div>
                <div class="bt-results"><div class="bt-result-row bt-muted"><span>순위</span><span>팀</span><span>점수</span><span>해결</span><span>오답</span><span>${this.cardBoard?'진행':'생존'}</span></div>${s.teams.map(t=>`<div class="bt-result-row ${s.players.some(p=>p.id===me&&p.team===t.team)?'is-mine':''}"><strong>${t.rank}</strong><span>${t.team}팀 · ${t.size}명${this.cardBoard?' · '+t.stage+'판':''}</span><b>${t.score.toLocaleString()}</b><span>${t.correct}</span><span>${t.wrong}</span><span>${clock(t.survivedMs)}</span></div>`).join('')}</div>
                <h4>팀원 기여</h4><div class="bt-contributions">${s.players.map(p=>`<div><strong>${h(p.name)}</strong><span>${p.team}팀</span><span>해결 ${p.correct} · 오답 ${p.wrong} · ${this.cardBoard?'뒤집기':'비트'} ${p.edits}</span></div>`).join('')}</div>
                <div class="bt-lobby-actions">${live&&this.room.host?button('다시 경기','rematch','',true)+button('방 닫고 나가기','close'):''}${button('참여 기록','records')}${button('방 목록','list')}</div>`;
        }
    }
    const init=()=>document.querySelectorAll('[data-binary-team]').forEach(root=>{if(!root.__BinaryTeam)root.__BinaryTeam=new BinaryTeam(root);});
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
