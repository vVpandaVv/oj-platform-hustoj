(() => {
    const escape = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const names = {stack:'스택',queue:'큐',priority:'우선순위 큐'};
    const rules = {stack:'나중에 입고한 화물부터',queue:'먼저 입고한 화물부터',priority:'큰 우선순위부터 · 동률은 입고순'};
    const stars = count => '★'.repeat(count) + '☆'.repeat(3-count);
    class Warehouse {
        constructor(root) {
            this.root=root;
            this.config=JSON.parse(root.dataset.config);
            this.key=`edu-warehouse:${this.config.userId}:${this.config.version}`;
            this.level=root.querySelector('[data-role=level]');
            this.state=null; this.progress=null; this.pending=null; this.busy=false;
            try { this.token=localStorage.getItem(this.key); } catch { this.token=null; }
            root.addEventListener('click',event=>this.click(event));
            this.level.addEventListener('change',()=>{ if(this.state?.status!=='playing'){this.state=null;this.render();} this.summary(); });
            this.summary();
            this.resumeButton();
            this.render();
        }
        field(name) { return this.root.querySelector(`[data-role="${name}"]`); }
        message(text) { this.field('message').textContent=text; }
        resumeButton() {
            const button=this.root.querySelector('[data-command=resume]');
            if(button) button.hidden=!this.token;
        }
        async api(action,data={}) {
            const response=await fetch(`${this.config.restUrl}/${action}`,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':this.config.nonce},credentials:'same-origin',body:JSON.stringify(data)});
            let body;
            try { body=await response.json(); } catch { throw new Error('서버 응답을 확인하지 못했습니다. 같은 요청을 다시 보내거나 이어 하기를 누르세요.'); }
            if(!response.ok) { const e=new Error(body.message||'기록을 불러오지 못했습니다.'); e.status=response.status; throw e; }
            return body;
        }
        async summary() {
            const level=this.level.value;
            const container=this.field('leaders'), pager=window.EduGamesRankPager.request(container,level,()=>this.summary());
            try {
                const summary=await this.api('summary',{level,page:pager.page,perPage:pager.perPage});
                if(!window.EduGamesRankPager.accept(container,level,summary,pager.ticket)||this.level.value!==level) return;
                this.progress=summary;
                this.field('collection').textContent=`수집한 별 ${summary.stars} / ${summary.maxStars}`;
                this.field('map').innerHTML=Object.values(summary.levels).map(l=>`<button type="button" data-level="${escape(l.id)}" class="${l.id===level?'is-selected':''}" aria-label="${l.number}구역 ${escape(l.title)}, 별 ${l.stars}개${l.unlocked?'':' 잠김'}" ${!l.unlocked?'disabled':''}>${l.number}<span>${l.unlocked?stars(l.stars):'잠김'}</span></button>`).join('');
                [...this.level.options].forEach(option=>{option.disabled=!summary.levels[option.value]?.unlocked;});
                const record=summary.levels[level];
                const perfect=Object.values(summary.levels).every(item=>item.score===1000);
                this.field('personal').textContent=perfect?'전체 구역 최단 기록 달성 · 캠페인 완주':record.score?`내 최고 ${record.score.toLocaleString()}점 · ${stars(record.stars)} · 다음 목표 ${record.score===1000?(summary.stars===summary.maxStars?'다른 구역의 최단 기록':'다른 구역의 별 수집'):record.stars<3?'별 3개':'최단 이동 1,000점'}`:'첫 출고를 완료하면 다음 구역이 열립니다.';
                this.field('leaders').innerHTML=summary.leaderboard.length?`<table><thead><tr><th>순위</th><th>도전자</th><th>최고 점수</th></tr></thead><tbody>${summary.leaderboard.map(row=>`<tr><td>${row.rank}</td><td>${escape(row.name)}</td><td>${row.score.toLocaleString()}</td></tr>`).join('')}</tbody></table>`:'아직 완료 기록이 없습니다.';
                window.EduGamesRankPager?.mount(this.field('leaders'), level, 'tbody tr');
            } catch(error) { window.EduGamesRankPager.fail(container,pager.ticket); }
        }
        async click(event) {
            const button=event.target.closest('button');
            if(!button||!this.root.contains(button)||button.disabled) return;
            if(button.dataset.level) {
                if(this.busy||this.pending) return;
                if(this.state?.status==='playing') return;
                this.level.value=button.dataset.level; this.state=null;this.render();await this.summary(); return;
            }
            const command=button.dataset.command;
            if(command==='refresh') { await this.summary(); return; }
            if(this.busy) return;
            if(command==='resume') { await this.resume(); return; }
            if(command==='retry') { await this.send(); return; }
            if(this.pending) { this.message('저장 결과를 확인해야 합니다. 같은 요청을 다시 보내거나 이어 하기를 누르세요.'); return; }
            if(command==='start'||command==='replay'||command==='next') {
                if(this.state?.status==='playing'&&!window.confirm('현재 배치를 남기고 새 도전을 시작할까요?')) return;
                if(command==='replay') this.level.value=this.state.level;
                if(command==='next') this.level.value=button.dataset.next;
                await this.start(); return;
            }
            if(!button.dataset.move) return;
            const move={type:button.dataset.move};
            if(button.dataset.from) move.from=button.dataset.from;
            if(button.dataset.to) move.to=button.dataset.to;
            if(move.type==='transfer') move.to=this.root.querySelector(`[data-transfer="${move.from}"]`).value;
            if(!this.state?.token) {
                this.setBusy(true);
                try { this.remember(await this.api('start',{level:this.level.value})); }
                catch(error) { this.message(error.message); }
                finally { this.setBusy(false);this.render(); }
                if(!this.state?.token) return;
            }
            this.pending={token:this.state.token,revision:this.state.revision,requestId:crypto.randomUUID(),move};
            this.focusMove={type:move.type,from:move.from,to:button.dataset.to};
            await this.send();
        }
        setBusy(value) {
            this.busy=value; this.root.setAttribute('aria-busy',String(value));
            this.root.querySelectorAll('[data-move],[data-command=start],[data-command=replay],[data-command=next]').forEach(button=>{
                if(value) { button.dataset.wasDisabled=button.disabled?'1':'0'; button.disabled=true; }
                else { button.disabled=button.dataset.wasDisabled==='1'; }
            });
        }
        remember(state) {
            this.state=state; this.token=state.token;
            try { localStorage.setItem(this.key,state.token); } catch { /* Server state remains authoritative. */ }
            this.level.value=state.level; this.resumeButton(); this.render(); this.message(state.message);
        }
        async start() {
            this.setBusy(true);
            try { this.remember(await this.api('start',{level:this.level.value})); await this.summary(); }
            catch(error) { this.message(error.message); }
            finally { this.setBusy(false); this.render(); }
        }
        async resume() {
            if(!this.token) return;
            this.setBusy(true);
            try {
                this.remember(await this.api('resume',{token:this.token}));
                this.pending=null; this.root.querySelector('[data-command=retry]').hidden=true;
                await this.summary();
            } catch(error) {
                this.message(error.message);
                if(error.status===404) {
                    this.token=null; this.pending=null;
                    try { localStorage.removeItem(this.key); } catch { /* The stale token is ignored in this tab. */ }
                    this.root.querySelector('[data-command=retry]').hidden=true;
                    this.resumeButton();
                }
            }
            finally { this.setBusy(false); this.render(); }
        }
        async send() {
            if(!this.pending||this.busy) return;
            this.setBusy(true);
            try {
                const result=await this.api('update',this.pending);
                this.pending=null; this.root.querySelector('[data-command=retry]').hidden=true;
                this.remember(result);
                if(result.status!=='playing') await this.summary();
            } catch(error) {
                this.message(error.message);
                if(error.status>=400&&error.status<500&&error.status!==409) this.pending=null;
                this.root.querySelector('[data-command=retry]').hidden=!this.pending;
            } finally {
                this.setBusy(false); this.render();
                if(this.focusMove) {
                    const m=this.focusMove;
                    const selector=`[data-move="${m.type}"]${m.from?`[data-from="${m.from}"]`:''}${m.to?`[data-to="${m.to}"]`:''}`;
                    const target=this.root.querySelector(selector); if(target&&!target.disabled) target.focus({preventScroll:true});
                }
            }
        }
        cargo(item,extra='') {
            const s=this.state||this.config.previews?.[this.level.value];
            return `<span class="ew-cargo ${extra}"><b>${escape(item)}</b><small>우선 ${s.definition.priorities[item]}</small></span>`;
        }
        render() {
            if(this.config.reportOnly) return;
            const s=this.state||this.config.previews?.[this.level.value];if(!s)return;
            const d=s.definition, p=s.position;
            this.level.disabled=this.busy||!!this.pending||!!this.state&&s.status==='playing';
            const locked=this.busy||!!this.pending||s.status!=='playing';
            const disabled=value=>value?'disabled':'';
            let result='';
            if(s.status!=='playing') {
                const next=Object.values(this.progress?.levels||{}).find(level=>level.number===d.number+1);
                const allPerfect=this.progress&&Object.values(this.progress.levels).every(level=>level.score===1000);
                result=`<section class="ew-result" data-result="${s.status}"><h3>${s.status==='won'?`${stars(s.stars)} 출고 완료`:'에너지 소진'}</h3><p>${s.status==='won'?`${s.score.toLocaleString()}점 · ${s.moves}수 / 최단 ${s.par}수 · 오출고 ${s.mistakes}회 · 되돌리기 ${s.undos}회`:'주문 순서와 각 보관함의 출구를 다시 살펴보세요.'}</p><p>${s.status==='won'?(allPerfect?'12개 구역을 모두 최단 경로로 완주했습니다.':s.score===1000?'최단 경로 달성! 다른 구역의 기록에 도전하세요.':'다음 목표: 되돌리기와 오출고 없이 최단 경로 달성'):'다음 도전은 동일한 배치로 시작합니다.'}</p><button type="button" data-command="replay">같은 구역 재도전</button>${s.status==='won'&&next?.unlocked?`<button type="button" data-command="next" data-next="${next.id}">다음 구역</button>`:''}</section>`;
            }
            this.field('board').innerHTML=`<h3>${d.number}구역 · ${escape(d.title)}</h3><div class="ew-dashboard"><div><span>남은 에너지</span><strong>${Math.max(0,s.budget-s.moves)}</strong></div><div><span>이동 / 최단</span><strong>${s.moves} / ${s.par}</strong></div><div><span>출고</span><strong>${p.delivered} / ${d.target.length}</strong></div><div><span>되돌리기 잔여</span><strong>${3-s.undos}</strong></div></div>
                <div class="ew-order"><h3>출고 주문</h3>${d.target.map((item,i)=>this.cargo(item,i<p.delivered?'is-done':i===p.delivered?'is-next':'')).join('')}</div>
                <div class="ew-floor"><div class="ew-incoming"><h3>입고 대기</h3>${d.input.slice(p.cursor).map((item,i)=>this.cargo(item,i===0?'is-next':'')).join('')||'<span>입고 완료</span>'}</div>
                <div class="ew-lanes" data-count="${d.lanes.length}">${d.lanes.map(lane=>{
                    const items=lane==='stack'?[...p.lanes[lane]].reverse():p.lanes[lane];
                    const slots=[...items,...Array(d.capacity-items.length).fill(null)];
                    return `<section class="ew-lane"><h3>${names[lane]}</h3><p>${rules[lane]}</p><div class="ew-slots">${slots.map(item=>`<div class="ew-slot">${item?this.cargo(item,s.heads[lane]===item?'is-next':''):''}</div>`).join('')}</div><div class="ew-lane-actions"><button type="button" data-move="load" data-to="${lane}" aria-label="${names[lane]} 입고" ${disabled(locked||p.cursor>=d.input.length||items.length>=d.capacity)}>입고</button><button type="button" data-move="ship" data-from="${lane}" aria-label="${names[lane]} 출고" ${disabled(locked||!items.length)}>출고</button></div><div class="ew-transfer"><select data-transfer="${lane}" aria-label="${names[lane]}에서 이동할 보관함" ${disabled(locked)}>${d.lanes.filter(to=>to!==lane).map(to=>`<option value="${to}">${names[to]}로</option>`).join('')}</select><button type="button" data-move="transfer" data-from="${lane}" aria-label="${names[lane]} 화물 옮기기" ${disabled(locked||!items.length)}>옮기기</button></div></section>`;
                }).join('')}</div></div><div class="ew-bottom"><button type="button" data-move="undo" title="되돌리기" aria-label="되돌리기" ${disabled(locked||!s.canUndo)}>↶</button><span>이동 1회 = 에너지 1 · 최단 출고 1,000점</span></div>${result}`;
        }
    }
    document.querySelectorAll('[data-warehouse]').forEach(root=>{
        if(root.dataset.warehouseInitialized) return;
        root.dataset.warehouseInitialized='true';
        root.__Warehouse=new Warehouse(root);
    });
})();
