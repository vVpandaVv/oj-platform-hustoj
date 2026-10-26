(() => {
    const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const empty=title=>`<div class="ojp-empty-state"><div><strong>${esc(title)}</strong></div></div>`;
    // "last" arrives as UTC "Y-m-d H:i:s"; show it in the site time zone (IANA name or fixed offset).
    const siteTime=(value,zone)=>{
        if(!value)return '-';
        const date=new Date(String(value).replace(' ','T')+'Z');if(Number.isNaN(date.getTime()))return String(value);
        const pad=n=>String(n).padStart(2,'0'),offset=/^([+-])(\d{2}):(\d{2})$/.exec(zone||'');
        if(!zone||zone==='UTC'||offset){
            const shifted=new Date(date.getTime()+(offset?(offset[1]==='-'?-1:1)*(Number(offset[2])*60+Number(offset[3]))*60000:0));
            return `${shifted.getUTCFullYear()}-${pad(shifted.getUTCMonth()+1)}-${pad(shifted.getUTCDate())} ${pad(shifted.getUTCHours())}:${pad(shifted.getUTCMinutes())}`;
        }
        try{
            const parts=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(date).map(p=>[p.type,p.value]));
            return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`;
        }catch(error){return `${String(value).slice(0,16)} UTC`;}
    };
    class Report {
        constructor(root) {
            this.root=root;this.config=JSON.parse(root.dataset.config);this.contexts=[];this.cursor='';this.contextRequest=0;this.recordRequest=0;
            this.field('game').innerHTML=this.config.games.map(game=>`<option value="${esc(game.id)}">${esc(game.title)}</option>`).join('');
            this.modes();
            root.addEventListener('change',event=>{if(event.target===this.field('game'))this.modes();this.load();});
            root.addEventListener('click',event=>{const action=event.target.closest('[data-action]')?.dataset.action;if(action==='refresh')this.load();if(action==='contexts')this.loadContexts();if(action==='more')this.loadContexts(true);});
            this.loadContexts();
        }
        field(key){return this.root.querySelector(`[data-field="${key}"]`);}
        modes(){const game=this.config.games.find(game=>game.id===this.field('game').value);this.field('mode').innerHTML=Object.entries(game?.modes||{}).map(([id,mode])=>`<option value="${id}">${esc(mode.label)}</option>`).join('');}
        async api(path,params){const response=await fetch(`${this.config.restUrl}${path}?${new URLSearchParams(params)}`,{credentials:'same-origin',headers:{'X-WP-Nonce':this.config.nonce}});const data=await response.json();if(!response.ok)throw Error(data.message||'기록을 조회하지 못했습니다.');return data;}
        async loadContexts(more=false){
            const request=++this.contextRequest;this.recordRequest++;
            this.field('status').textContent='학급 목록을 불러오는 중입니다.';
            try {
                const data=await this.api('/reports/contexts',{cursor:more?this.cursor:''});
                if(request!==this.contextRequest)return;
                const selected=more?this.field('context').value:'0';this.contexts=more?[...this.contexts,...data.items]:data.items;this.cursor=data.nextCursor||'';
                this.field('context').innerHTML=this.contexts.map((item,i)=>`<option value="${i}">${esc(item.label)}</option>`).join('');this.field('context').value=selected;
                this.root.querySelector('[data-action=more]').hidden=!this.cursor;
                if(this.contexts.length)await this.load();else{this.field('status').textContent='';this.field('records').innerHTML=empty('담당 학급·그룹이 없습니다.');}
            }catch(error){if(request===this.contextRequest)this.field('status').textContent=error.message;}
        }
        async load(){
            const context=this.contexts[Number(this.field('context').value)];if(!context)return;
            const request=++this.recordRequest;
            const params={type:context.type,id:context.id,revision:context.revision,...Object.fromEntries(['game','mode','from','to'].map(key=>[key,this.field(key).value]))};
            const key=JSON.stringify(params),container=this.field('records');
            const pager=window.EduGamesRankPager.request(container,key,()=>this.load());
            try {
                const data=await this.api('/reports/students',{...params,page:pager.page,perPage:pager.perPage});
                if(request!==this.recordRequest)return;
                if(!window.EduGamesRankPager.accept(container,key,data,pager.ticket))return;
                this.field('status').textContent=`${context.label} · 참여 ${data.participants} / ${data.pagination.total}명 · 콘텐츠 버전 ${data.version}`;
                const concept=['assessment','practice','guided'].includes(params.mode),board=['strategy','board'].includes(params.mode),collection=params.mode==='collection';
                const value=n=>n===null||n===undefined?'-':esc(n);
                const zone=this.config.timezone||'UTC';
                container.innerHTML=data.students.length?`<div class="ojp-table-wrap eg-report-scroll"><table class="ojp-table eg-report-table"><thead><tr><th scope="col">학생</th><th scope="col">참여</th><th scope="col">${collection?'복원한 그림':'시도'}</th><th scope="col">완료 횟수</th>${board?'<th scope="col">완주 보드</th><th scope="col">별</th>':''}<th scope="col">${collection?'도감 수':'최고점'}</th>${concept?'<th scope="col">최근 완료 첫 답</th><th scope="col">최근 완료 수정 후</th>':''}<th scope="col">최근 활동</th></tr></thead><tbody>${data.students.map(row=>`<tr data-rank-row><th scope="row">${esc(row.name)}</th><td>${row.attempts?'<span class="ojp-status-badge ojp-status-badge--success">참여</span>':'<span class="ojp-status-badge ojp-status-badge--neutral">미참여</span>'}</td><td>${row.attempts}</td><td>${row.completed}</td>${board?`<td>${row.cleared}</td><td>${row.stars}</td>`:''}<td>${value(row.best)}</td>${concept?`<td>${row.total===null?'-':`${row.first}/${row.total}`}</td><td>${row.total===null?'-':`${row.final}/${row.total}`}</td>`:''}<td>${row.last?`<time datetime="${esc(String(row.last).replace(' ','T')+'Z')}">${esc(siteTime(row.last,zone))}</time>`:'-'}</td></tr>`).join('')}</tbody></table></div>`:empty('표시할 학생이 없습니다.');
                window.EduGamesRankPager.mount(container,key);
            }catch(error){if(request!==this.recordRequest)return;window.EduGamesRankPager.fail(container,pager.ticket);this.field('status').textContent=error.message;}
        }
    }
    const init=()=>document.querySelectorAll('[data-game-report]').forEach(root=>{if(!root.__EduReport)root.__EduReport=new Report(root);});
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
