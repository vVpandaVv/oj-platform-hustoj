(() => {
    'use strict';
    const VERSION='typing-verified-v3',LEGACY_VERSION='typing-verified-v2';
    const h=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const chars=t=>Array.from(String(t).replace(/\r\n?/g,'\n'));
    const code=t=>chars(t).filter(c=>!(/\s/u).test(c)).length;
    const fresh=(samples,duration,minAccuracy,version=VERSION,lineEnding='enter')=>({version,lineEnding,samples:samples.map(s=>({...s,content:chars(s.content).join('')})),durationSeconds:duration,minAccuracy,sampleIndex:0,input:'',credited:{},attempted:0,correctAttempts:0,mistakes:0,completed:0,completedCode:0,completedCharacters:0,lastAt:-1,status:'running'});
    function edit(s,e){
        const target=chars(s.samples[s.sampleIndex%s.samples.length].content),input=chars(s.input),text=chars(e.text);
        if(e.kind==='linebreak'&&((s.lineEnding||'enter')!=='space-enter'||e.text!=='\n'||e.delete!==0||target[e.start]!=='\n'))throw Error('줄 끝 입력 설정을 확인하세요.');
        text.forEach((c,i)=>{const pos=e.start+i;if(target[pos]===c){if(!s.credited[pos]){s.credited[pos]=true;s.attempted++;s.correctAttempts++;}}else{s.attempted++;s.mistakes++;}});
        input.splice(e.start,e.delete,...text);s.input=input.join('');s.lastAt=e.at;
        return s;
    }
    function apply(s,e){
        edit(s,e);
        if(s.input===s.samples[s.sampleIndex%s.samples.length].content){s.completed++;s.completedCode+=code(s.input);s.completedCharacters+=chars(s.input).length;s.sampleIndex++;s.input='';s.credited={};}
        return s;
    }
    function metrics(s,elapsed){
        const target=chars(s.samples[s.sampleIndex%s.samples.length].content),input=chars(s.input);let prefix='',same=0,matchedCode=0,continuous=true;
        input.forEach((c,i)=>{const ok=c===target[i];if(ok){same++;matchedCode+=code(c);}if(!ok)continuous=false;if(continuous)prefix+=c;});
        const valid=s.completedCode+(s.version===LEGACY_VERSION?code(prefix):matchedCode),accuracy=s.attempted?100*s.correctAttempts/s.attempted:0;
        const cpm=valid*60/Math.max(1,s.status==='finished'?s.durationSeconds:elapsed),qualified=valid>0&&accuracy>=s.minAccuracy&&!s.verificationIssue;
        return {effectiveCpm:Math.round(cpm*10)/10,inputAccuracy:accuracy,finalAccuracy:s.completedCharacters+input.length?100*(s.completedCharacters+same)/(s.completedCharacters+input.length):0,
            validCharacters:valid,mistakes:s.mistakes,attempted:s.attempted,correctAttempts:s.correctAttempts,samplesCompleted:s.completed,qualified,score:qualified?Math.round(cpm*10):0};
    }
    class Verified {
        constructor(game){
            this.g=game;this.config=game.config;this.events=[];this.remote=null;this.pending=null;this.evaluation=null;this.inflight=null;this.s=null;this.composing=false;this.frozen=false;this.epoch=0;
            this.key='edu-typing-v2:'+this.config.currentUserId;
            this.portal=game.root.querySelector('[data-typing-assignments]');
            this.panel=game.root.querySelector('[data-typing-assignment-panel]');
            this.requestedEvaluation=new URL(location.href).searchParams.get('evaluation');
            const input=game.fields.input;
            input.addEventListener('beforeinput',e=>{if(game.isAssessment()&&['insertFromPaste','insertFromDrop'].includes(e.inputType)){e.preventDefault();game.setFeedback('검정에는 붙여넣기와 끌어 넣기를 사용할 수 없습니다.');}});
            for(const type of ['paste','drop'])input.addEventListener(type,e=>{if(game.isAssessment())e.preventDefault();});
            input.addEventListener('compositionstart',()=>{this.composing=true;});
            input.addEventListener('compositionend',()=>{this.composing=false;if(game.isAssessment())this.input(input.value);});
            input.addEventListener('keyup',e=>{if(game.isAssessment()&&this.s&&['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'].includes(e.key))this.renderStream();});
            game.root.addEventListener('click',e=>{
                if(e.target.closest('[data-typing-refresh]')){this.refreshAssignments();return;}
                const filter=e.target.closest('[data-typing-filter]');if(filter){this.assignmentFilter=filter.dataset.typingFilter;this.renderAssignments();this.portal.querySelector(`[data-typing-filter="${this.assignmentFilter}"]`)?.focus();return;}
                const review=e.target.closest('[data-typing-review]');if(review){const open=game.root.toggleAttribute('data-typing-review-open');review.setAttribute('aria-expanded',String(open));review.lastElementChild.textContent=open?'작성 코드 접기':'작성 코드 보기';return;}
                if(e.target.closest('[data-typing-error-location]')){if(this.firstError!=null){input.focus();input.selectionStart=input.selectionEnd=this.firstError;this.renderStream();}return;}
                const b=e.target.closest('[data-typing-evaluation],[data-typing-resume],[data-typing-retry],[data-typing-leave]');if(!b)return;
                if(b.hasAttribute('data-typing-retry')){if(!this.remote)this.begin(game.state.input);else if(this.remaining()<=0)this.finish();else this.flush().catch(error=>game.setFeedback(error.message));return;}
                if(b.hasAttribute('data-typing-leave')){if(game.state.status==='running')return;this.evaluation=null;game.state.mode='keyword';game.renderControls();game.resetSession(false);const url=new URL(location.href);url.searchParams.delete('evaluation');history.replaceState(null,'',url);game.root.__GameViewport?.close();this.renderAssignments();this.portal.querySelector('h2')?.focus();return;}
                if(b.dataset.typingEvaluation)this.selectEvaluation(Number(b.dataset.typingEvaluation)).catch(error=>game.setFeedback(error.message));
                if(b.dataset.typingResume)this.resume(b.dataset.typingResume).catch(error=>game.setFeedback(error.message));
            });
            this.refreshAssignments();this.controls();
        }
        async api(path,body){
            const url=new URL(this.config.restUrl,location.href),suffix='/typing/'+path;
            if(url.searchParams.has('rest_route'))url.searchParams.set('rest_route',url.searchParams.get('rest_route').replace(/\/$/,'')+suffix);else url.pathname=url.pathname.replace(/\/$/,'')+suffix;
            if(url.origin!==location.origin)throw Error('검정 서버 주소를 확인하세요.');
            const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),30000);
            try{const response=await fetch(url.href,{method:body===undefined?'GET':'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'Content-Type':'application/json','X-WP-Nonce':this.config.nonce||''},...(body!==undefined?{body:JSON.stringify(body)}:{})});const data=await response.json();if(!response.ok)throw Object.assign(Error(data.message||'검정 요청에 실패했습니다.'),{status:response.status});return data;}
            catch(error){if(error.name==='AbortError')throw Error('서버 연결 시간이 초과되었습니다.');throw error;}
            finally{clearTimeout(timeout);}
        }
        remember(){try{if(this.remote)localStorage.setItem(this.key,JSON.stringify({token:this.remote.token,pending:this.pending,events:this.events,evaluation:this.evaluation}));}catch{}}
        clear(){this.epoch++;this.s=null;this.remote=null;this.events=[];this.pending=null;this.inflight=null;this.frozen=false;this.finishing=false;}
        label(){return (this.s?.version||this.evaluation?.version||VERSION)===LEGACY_VERSION?'검정 v2 · 이전 연속 일치':'검정 v3 · 정타 CPM';}
        prepare(){if(this.g.state.status==='running')return;this.clear();this.g.prepareNextRound();this.g.fields.input.focus();}
        async selectEvaluation(id){
            if(this.g.state.status==='running')return;
            const g=this.g;g.fields.input.disabled=true;if(this.panel)this.panel.open=true;
            this.portal?.setAttribute('aria-busy','true');
            try{const preview=await this.api('evaluations/'+id+'/preview');this.evaluation={...preview.evaluation,samples:preview.samples,nextAttempt:(this.assigned?.find(e=>e.id===id)?.attempts.length||0)+1};g.state.language=this.evaluation.language;g.state.mode='assessment';g.state.sampleId=this.evaluation.sampleIds[0];g.renderControls();g.resetSession(false);g.activeSample={...preview.samples[0],mode:'assessment'};g.renderAll();this.renderAssignments();g.setFeedback('입력 대기');g.root.__GameViewport?.open();}
            finally{this.portal?.setAttribute('aria-busy','false');g.fields.input.disabled=false;g.fields.input.focus();}
        }
        seed(){
            const g=this.g,all=g.rules.samples.filter(s=>s.mode==='assessment'&&s.language===g.state.language),first=all.findIndex(s=>s.id===g.state.sampleId);
            const samples=this.evaluation?(this.evaluation.samples||this.evaluation.sampleIds.map(id=>all.find(s=>s.id===id)).filter(Boolean)):[...all.slice(Math.max(0,first)),...all.slice(0,Math.max(0,first))];
            return fresh(samples,this.evaluation?.durationSeconds||300,this.evaluation?.minAccuracy||95,this.evaluation?.version||VERSION,this.evaluation?.lineEnding||g.lineEnding);
        }
        async begin(value){
            const g=this.g;if(!this.config.isLoggedIn){g.fields.input.value='';g.setFeedback('검정 기록은 로그인 후 시작할 수 있습니다.');return;}
            this.clear();const epoch=this.epoch;this.s=this.seed();this.started=performance.now();this.deadline=this.started+this.s.durationSeconds*1000;
            g.resetAssessmentSession();g.state.status='running';g.state.startedAt=this.started;g.state.timeLimitSeconds=this.s.durationSeconds;
            g.clearTimer();g.timer=setInterval(()=>this.tick(),250);g.updateShellState();this.input(value);this.controls();g.fields.input.disabled=true;
            const retry=g.root.querySelector('[data-verified-retry]');if(retry)retry.hidden=true;
            g.setFeedback('검정 시작 · 서버 연결 중');
            try{
                const view=await this.api('runs/start',this.evaluation?{evaluationId:this.evaluation.id}:{language:g.state.language,sampleId:g.state.sampleId,lineEnding:g.lineEnding});if(epoch!==this.epoch)return;
                this.remote=view;
                if(view.metrics.attempted||view.sampleIndex){this.events=[];this.pending=null;g.lineLog=[];g.loggedLineCount=0;}
                this.accept(view);this.remember();this.render();g.fields.input.disabled=false;g.fields.input.focus();await this.flush();
                g.setFeedback(view.status==='finished'?'저장된 검정 결과입니다.':this.label()+' · 수정 전 오타 포함');
            }catch(error){this.frozen=true;g.fields.input.disabled=true;g.setFeedback(error.message+' · 연결·저장 재시도로 이어 하세요.');if(retry)retry.hidden=false;}
        }
        input(value,kind='type'){
            const g=this.g;if(this.composing)return;
            if(g.state.status==='idle'){this.begin(value);return;}
            if(!this.s||this.frozen||this.finishing||g.state.status!=='running'){g.fields.input.value=g.state.input;return;}
            const before=chars(this.s.input),after=chars(value);let start=0,end=0;
            while(start<before.length&&start<after.length&&before[start]===after[start])start++;
            while(end<before.length-start&&end<after.length-start&&before[before.length-1-end]===after[after.length-1-end])end++;
            const text=after.slice(start,after.length-end).join(''),del=before.length-start-end;
            if(!text&&!del)return;
            if(after.length>chars(this.s.samples[this.s.sampleIndex%this.s.samples.length].content).length+4096){g.fields.input.value=this.s.input;g.setFeedback('입력 길이를 초과했습니다. 오타를 수정하세요.');return;}
            if(chars(text).length>32){g.fields.input.value=this.s.input;g.setFeedback('한 번에 입력된 내용이 너무 많습니다. 직접 입력하세요.');return;}
            const at=Math.min(this.s.durationSeconds*1000,Math.max(this.s.lastAt||0,0,Math.floor(performance.now()-this.started)));
            if(this.remaining()<=0){g.fields.input.value=this.s.input;this.finish();return;}
            const event={start,delete:del,text,kind:text?(['tab','linebreak'].includes(kind)?kind:'type'):'delete',at};
            g.state.input=after.join('');const index=this.s.sampleIndex;apply(this.s,event);this.events.push(event);
            if(this.s.sampleIndex!==index)g.loggedLineCount=0;
            this.remember();this.render();
            if(this.events.length>=100)this.flush().catch(error=>g.setFeedback(error.message));
        }
        keydown(e){
            if(this.g.state.status==='finished')return false;
            if(e.key==='Tab'){
                e.preventDefault();const el=this.g.fields.input,a=el.selectionStart,b=el.selectionEnd,value=el.value.slice(0,a)+'    '+el.value.slice(b);
                this.input(value,'tab');el.selectionStart=el.selectionEnd=a+4;
            }
            return true;
        }
        accept(view){
            this.remote=view;this.deadline=performance.now()+view.metrics.remaining*1000;this.started=this.deadline-view.metrics.durationSeconds*1000;
            this.s={...view,...view.metrics,completed:view.metrics.samplesCompleted,status:view.status,attempted:view.metrics.attempted,correctAttempts:view.metrics.correctAttempts,mistakes:view.metrics.mistakes,credited:{...view.credited},durationSeconds:view.metrics.durationSeconds};
            if(view.status==='running')for(const event of this.events)apply(this.s,event);
        }
        remaining(){return this.s?Math.max(0,Math.ceil((this.deadline-performance.now())/1000)):this.evaluation?.durationSeconds||300;}
        metric(){const g=this.g,s=this.s||this.seed(),elapsed=s.durationSeconds-this.remaining(),m=metrics(s,elapsed);return {...m,progress:g.state.status==='finished'?100:Math.max(0,elapsed/s.durationSeconds*100),accuracy:m.inputAccuracy,cpm:m.effectiveCpm,wpm:m.effectiveCpm/5,correct:m.validCharacters,typedLength:m.attempted,elapsedSeconds:g.state.status==='finished'?s.durationSeconds:elapsed,completed:g.state.status==='finished'};}
        render(){
            const g=this.g;if(this.s){g.activeSample={...this.s.samples[this.s.sampleIndex%this.s.samples.length],mode:'assessment'};g.state.sampleId=g.activeSample.id;g.state.input=this.s.input;g.fields.input.value=this.s.input;g.state.timeLimitSeconds=this.s.durationSeconds;}
            g.renderAll();this.controls();
        }
        renderStream(){
            const g=this.g,lines=g.currentSampleLines(),value=g.state.input,target=g.currentSample().content;
            let prefix=0;while(prefix<value.length&&value[prefix]===target[prefix])prefix++;
            this.firstError=prefix<value.length?prefix:null;
            const errorButton=g.root.querySelector('[data-typing-error-location]');if(errorButton)errorButton.hidden=this.firstError===null||g.state.status==='finished';
            const caret=Math.min(g.fields.input.selectionStart??value.length,value.length);
            let current=lines.findIndex((line,i)=>caret<line.end||i===lines.length-1);current=Math.max(0,current);
            const start=Math.max(0,current-5),stop=Math.min(lines.length,current+5);
            g.fields.stream.innerHTML=lines.slice(start,stop).map((line,j)=>{
                const index=start+j;let typed=value.slice(line.start,Math.min(value.length,line.end));
                if(typed.endsWith('\n')&&line.end<=value.length)typed=typed.slice(0,-1);
                const state=index===current?'is-current':value.length>line.start?'is-done':'is-upcoming';
                return `<div data-verified-line="${index}">${g.renderLineSlot(line.text,typed,state)}</div>`;
            }).join('');
            g.fields.stream.querySelectorAll('.oj-typing-char.is-current').forEach(el=>el.classList.remove('is-current'));
            const row=g.fields.stream.querySelector(`[data-verified-line="${current}"]`);
            row?.querySelectorAll('.oj-typing-line-target .oj-typing-char')[caret-lines[current].start]?.classList.add('is-current');
            const currentEl=row?.firstElementChild;if(currentEl)g.fields.stream.scrollTop=Math.max(0,currentEl.offsetTop-g.fields.stream.offsetTop-g.fields.stream.clientHeight/2+currentEl.clientHeight/2);
            g.highlightNextKey();
        }
        controls(){
            const g=this.g,active=g.isAssessment()&&g.state.status==='running',exhausted=g.isAssessment()&&g.state.status==='finished'&&this.evaluation&&this.remote?.attemptNo>=this.evaluation.maxAttempts;
            g.root.toggleAttribute('data-typing-verified-running',active);
            g.root.querySelectorAll('[data-mode],[data-field=language],[data-action=start],[data-action=reset]').forEach(el=>el.disabled=!!(active||exhausted));
            if(this.evaluation&&!active){g.fields.language.disabled=true;g.root.querySelectorAll('[data-mode]').forEach(el=>el.disabled=true);}
            const sample=g.fields.sample;if(g.isAssessment())sample.disabled=active||!!this.evaluation;
            const speed=g.fields.speed?.previousElementSibling,accuracy=g.fields.accuracy?.previousElementSibling;
            if(speed)speed.textContent=active?'유효 CPM · 잠정':'유효 CPM';if(accuracy)accuracy.textContent='입력 정확도';
            if(g.fields.score?.previousElementSibling)g.fields.score.previousElementSibling.textContent=g.isAssessment()?'유효 글자':'점수';
            if(g.fields.progress?.previousElementSibling)g.fields.progress.previousElementSibling.textContent=g.isAssessment()?'검정 경과':'진행률';
            g.renderLineEndingControl();
            this.portal?.toggleAttribute('hidden',active);
            this.panel?.toggleAttribute('hidden',active);
            g.root.toggleAttribute('data-typing-evaluation-selected',!!this.evaluation);
            g.root.toggleAttribute('data-typing-evaluation-ready',!!this.evaluation&&g.state.status==='idle');
            const confirmed=!!this.evaluation&&g.state.status==='finished'&&this.remote?.status==='finished';
            g.root.toggleAttribute('data-typing-result-confirmed',confirmed);
            if(!confirmed)g.root.removeAttribute('data-typing-review-open');
            const leave=this.portal?.querySelector('[data-typing-leave]');if(leave)leave.hidden=!this.evaluation;
        }
        tick(){
            if(this.g.state.status!=='running')return;
            if(this.remaining()<=0){this.finish();return;}
            if(this.remote&&(!this.lastFlush||performance.now()-this.lastFlush>1500)){this.lastFlush=performance.now();this.flush().catch(error=>this.g.setFeedback(error.message+' · 저장 재시도 대기'));}
            this.g.renderStats();
        }
        async flush(finish=false){
            if(!this.remote)return;
            if(this.remote.status==='finished'&&!this.pending){this.events=[];return;}
            if(this.inflight){await this.inflight;return this.flush(finish);}
            if(!this.pending&&!this.events.length&&!finish)return;
            if(!this.pending){this.pending={token:this.remote.token,revision:this.remote.revision,requestId:globalThis.crypto?.randomUUID?.()||('typing-'+Date.now()+'-'+Math.random().toString(16).slice(2)),events:this.events.splice(0),...(finish?{finish:true}:{})};this.remember();}
            const epoch=this.epoch;
            this.inflight=this.api('runs/update',this.pending).catch(async error=>{
                if(error.status!==409)throw error;
                const view=await this.api('runs/resume',{token:this.remote.token});
                if(view.status!=='finished')throw error;
                view.lateBatch=true;this.events=[];return view;
            }).then(view=>{if(epoch!==this.epoch)return;this.pending=null;this.accept(view);this.remember();if(this.frozen&&!this.finishing){this.frozen=false;this.g.fields.input.disabled=false;this.g.fields.input.focus();const retry=this.g.root.querySelector('[data-verified-retry]');if(retry)retry.hidden=true;}this.render();}).finally(()=>{if(epoch===this.epoch)this.inflight=null;});
            await this.inflight;
            if(finish&&this.remote.status!=='finished')return this.flush(true);
        }
        async finish(){
            if(this.finishing||!this.s)return;this.finishing=true;this.g.clearTimer();this.g.fields.input.disabled=true;
            try{
                if(!this.remote)throw Error('서버 연결이 완료되지 않았습니다.');
                await this.flush(true);this.s.status='finished';const g=this.g;g.state.status='finished';g.state.durationSeconds=this.s.durationSeconds;g.state.score=this.remote.metrics.score;g.state.finishReason='expired';
                g.fields.input.disabled=false;g.updateShellState();this.render();this.renderAssignments();
                g.root.dispatchEvent(new CustomEvent('edu-games:action',{detail:{text:this.remote.metrics.effectiveCpm+' 유효 CPM · 입력 정확도 '+this.remote.metrics.inputAccuracy+'%',tone:this.remote.metrics.qualified?'success':'miss'}}));
                g.setFeedback(this.evaluation?'결과 저장 완료.':`서버 재계산 완료 · ${this.remote.metrics.effectiveCpm} 유효 CPM · 입력 정확도 ${this.remote.metrics.inputAccuracy}% · 작성 부분 일치율 ${this.remote.metrics.finalAccuracy}%${this.remote.metrics.verificationIssue?' · 입력 전송 이력 확인 필요, 결과는 보존됩니다.':this.remote.metrics.qualified?'':' · 정확도 기준 미달, 기록은 보존됩니다.'}${this.remote.lateBatch?' · 늦게 도착한 입력은 제외하고 마지막 저장 지점까지 확정했습니다.':''}`);
                const retry=g.root.querySelector('[data-verified-retry]');if(retry)retry.hidden=true;
                try{localStorage.removeItem(this.key);}catch{}this.loadLeaderboard();await this.refreshAssignments();
                if(this.evaluation)this.portal?.querySelector('[data-student-final-result]')?.focus();
            }catch(error){if(error.message.includes('제한시간이 끝나면'))setTimeout(()=>this.finish(),150);else{this.g.setFeedback(error.message+' · 저장 재시도로 기록을 확정하세요.');const b=this.g.root.querySelector('[data-verified-retry]');if(b)b.hidden=false;}}
            finally{this.finishing=false;}
        }
        async resume(token){
            if(this.g.state.status==='running')return;this.clear();const view=await this.api('runs/resume',{token});
            let cached=null;try{cached=JSON.parse(localStorage.getItem(this.key)||'null');}catch{}
            this.evaluation=this.assigned?.find(e=>e.id===view.evaluationId)||cached?.evaluation||null;
            if(this.evaluation)this.evaluation.samples=view.samples;
            this.g.state.language=view.samples[0].language;this.g.state.mode='assessment';this.g.renderControls();this.g.resetAssessmentSession();this.g.state.status=view.status==='finished'?'finished':'running';
            if(cached?.token===token&&(!cached.pending||cached.pending.revision>=view.revision||cached.pending.requestId===view.lastRequest)){this.pending=cached.pending||null;this.events=cached.events||[];}
            this.accept(view);this.g.updateShellState();this.render();this.g.fields.input.disabled=false;this.g.fields.input.focus();
            this.renderAssignments();
            if(view.status==='running'){this.g.clearTimer();this.g.timer=setInterval(()=>this.tick(),250);await this.flush();}
            this.g.setFeedback(view.status==='finished'?'서버에 저장된 검정 결과입니다.':'진행 중인 검정을 이어갑니다.');
        }
        renderAssignments(){
            if(!this.portal||!window.EduTypingEntry)return;
            const assigned=this.assigned||[],count=this.g.root.querySelector('[data-typing-assignment-count]'),status=this.g.root.querySelector('[data-typing-entry-status]');
            if(count)count.textContent=String(assigned.length);
            if(status){const running=assigned.filter(e=>window.EduTypingEntry.active(e)).length,absent=assigned.filter(e=>!e.result&&!window.EduTypingEntry.active(e)).length;status.textContent=this.evaluation?this.evaluation.title:running?'응시 중 '+running:absent?'미응시 '+absent:assigned.length?'내 결과 보기':'배정 없음';}
            if(this.evaluation&&this.panel)this.panel.open=true;
            const title=this.g.root.querySelector('.oj-typing-topbar h2');if(title)title.textContent=this.evaluation?.title||'코드 타이핑';
            const entry=window.EduTypingEntry,options={iconsUrl:this.config.iconsUrl||'',filter:this.assignmentFilter,finished:this.g.state.status==='finished',attemptNo:this.remote?.attemptNo||this.evaluation?.nextAttempt||1,
                result:this.remote?.status==='finished'?{...this.remote.metrics,status:'finished',lateBatch:this.remote.lateBatch}:null};
            this.portal.innerHTML=this.evaluation?entry.selected(this.evaluation,options):entry.render(this.assigned||[],options);
            let cached=null;try{cached=JSON.parse(localStorage.getItem(this.key)||'null');}catch{}
            const shown=(this.assigned||[]).some(e=>(e.attempts||[]).some(a=>a.token===cached?.token));
            if(cached&&!shown&&!this.evaluation)this.portal.insertAdjacentHTML('beforeend',`<button type="button" class="ete-student-secondary" data-typing-resume="${h(cached.token)}">${entry.icon('refresh-cw',options.iconsUrl)}개인 검정 이어 하기</button>`);
            this.controls();
        }
        async refreshAssignments(){
            if(!this.portal)return;
            if(!this.config.isLoggedIn){this.portal.hidden=true;if(this.panel)this.panel.hidden=true;return;}
            this.portal.setAttribute('aria-busy','true');
            try{
                const data=await this.api('assigned');this.assigned=data.evaluations||[];
                this.renderAssignments();
                if(this.requestedEvaluation&&/^\d+$/.test(this.requestedEvaluation)){
                    const id=Number(this.requestedEvaluation);this.requestedEvaluation=null;
                    const running=window.EduTypingEntry.active(this.assigned.find(e=>e.id===id)||{});
                    const evaluation=this.assigned.find(e=>e.id===id);
                    if(running)await this.resume(running.token);else if(evaluation&&!window.EduTypingEntry.available(evaluation)){if(this.panel)this.panel.open=true;this.portal.querySelector(`[data-typing-assignment-card="${id}"] h3`)?.focus();}else await this.selectEvaluation(id);
                }
            }catch(error){if(this.evaluation&&this.remote?.status==='finished'){this.renderAssignments();this.g.setFeedback(error.message);}else this.portal.innerHTML=`<p role="status">${h(error.message)}</p><button type="button" class="ete-student-secondary" data-typing-refresh>다시 시도</button>`;}
            finally{this.portal.setAttribute('aria-busy','false');}
        }
        async loadLeaderboard(){
            if(!this.g.fields.leaderboard)return;
            if(this.evaluation){this.g.fields.leaderboard.innerHTML='<p>수행평가 결과는 본인과 담당 교사만 조회합니다.</p>';return;}
            const version=this.remote?.version||VERSION,lineEnding=this.g.lineEndingMode(),sampleId=this.remote?.samples?.[0]?.id||this.g.state.sampleId,field=this.g.fields.leaderboard,key=version+':'+sampleId+':'+lineEnding,pager=window.EduGamesRankPager.request(field,key,()=>this.loadLeaderboard());
            try{
                const data=await this.api('leaderboard',{version,level:sampleId,lineEnding,page:pager.page,perPage:pager.perPage});if(!window.EduGamesRankPager.accept(field,key,data,pager.ticket))return;
                this.g.state.myRank=data.myRank;field.innerHTML='<div class="oj-typing-rank-row"><span>순위</span><span>참여자 · 입력 정확도</span><span>유효 CPM</span></div>'+(data.leaderboard||[]).map(r=>`<div data-rank-row class="oj-typing-rank-row"><strong>${r.rank}</strong><span>${h(r.name)} · ${r.accuracy}%</span><b>${r.effectiveCpm}</b></div>`).join('');window.EduGamesRankPager.mount(field,key);this.g.renderStats();
            }catch(error){window.EduGamesRankPager.fail(field,pager.ticket);this.g.setFeedback(error.message);}
        }
    }
    window.EduTypingVerified={VERSION,LEGACY_VERSION,mount:game=>new Verified(game),edit,apply,metrics,fresh,code};
})();
