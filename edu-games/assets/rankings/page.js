(() => {
    'use strict';
    const h=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const number=v=>Number(v).toLocaleString('ko-KR',{maximumFractionDigits:1});
    const scopes={overall:'전체 순위',teams:'팀 평균 순위',team:'팀 내 순위'};
    class RankingPage {
        constructor(root){
            this.root=root;this.config=JSON.parse(root.dataset.config||'{}');this.app=root.querySelector('[data-er-app]');this.games=this.config.games||[];this.team=null;this.request=0;this.leaders=[];
            if(!this.config.isLoggedIn||!this.config.canView){this.app.innerHTML=`<div class="er-empty"><strong>${this.config.isLoggedIn?'순위를 볼 수 있는 권한이 없습니다.':'로그인 후 순위를 확인할 수 있습니다.'}</strong></div>`;return;}
            if(!this.games.length){this.app.innerHTML='<div class="er-empty"><strong>공개된 게임이 없습니다.</strong></div>';return;}
            const query=new URL(location.href).searchParams;
            this.game=this.games.find(g=>g.id===query.get('game'))||this.games.find(g=>g.id==='binary-conversion')||this.games[0];
            this.variant=this.game.variants.find(v=>v.id===query.get('records'))||this.game.variants[0];
            this.level=this.variant.levels.find(l=>l.id===query.get('level'))?.id||this.variant.levels[0]?.id||'';
            this.scope='overall';
            this.app.innerHTML=`<div class="er-layout"><aside class="er-sidebar"><div class="er-library-title"><strong>게임 목록</strong><span>${this.games.length}</span></div><label class="er-search">${this.icon('search')}<input type="search" data-er-search aria-label="게임 검색" placeholder="게임 검색"></label><nav class="er-games" aria-label="순위를 볼 게임" data-er-games></nav><details class="er-team"><summary>내 팀</summary><div data-er-team></div></details></aside>
                <div class="er-main"><label class="er-mobile-game">게임<select data-er-game-select aria-label="순위를 볼 게임">${this.games.map(g=>`<option value="${h(g.id)}">${h(g.title)}</option>`).join('')}</select></label>
                <header class="er-game-heading"><div class="er-game-mark" data-er-mark></div><div class="er-game-title"><p data-er-category></p><h2 data-er-title></h2></div><a class="er-play" data-er-play title="게임 열기" aria-label="게임 열기">게임 열기 ${this.icon('arrow-right')}</a><button type="button" class="er-icon-button" data-er-action="refresh" title="순위 새로고침" aria-label="순위 새로고침">${this.icon('refresh-cw')}</button></header>
                <div class="er-filters"><div class="er-scopes" role="tablist" aria-label="순위 범위">${Object.entries(scopes).map(([key,label])=>`<button type="button" role="tab" data-er-scope="${key}">${label}</button>`).join('')}</div><div class="er-record-filters"><label>기록<select data-er-variant aria-label="기록 종류"></select></label><label data-er-level-label>도전<select data-er-level aria-label="도전 선택"></select></label></div></div>
                <div class="er-metrics" aria-label="순위 요약"><div><span data-er-count-label>참여자</span><strong data-er-count>-</strong><small data-er-count-note>전체 참여자</small></div><div><span data-er-top-label>최고 점수</span><strong data-er-top>-</strong><small data-er-top-note>개인 최고 기록</small></div><div class="er-personal"><span data-er-mine-label>내 순위</span><strong data-er-mine>-</strong><small data-er-mine-note>기록 조회 중</small></div></div>
                <section class="er-highlights" data-er-highlights hidden aria-label="상위 기록"><div class="er-section-heading"><h3>${this.icon('trophy')}상위 기록</h3></div><div class="er-leaders" data-er-leaders></div></section>
                <section class="er-ranking"><div class="er-section-heading"><h3 data-er-table-title>전체 순위</h3><span data-er-status role="status" aria-live="polite"></span></div><div data-er-board></div></section>
                <section class="er-history" data-er-history hidden></section><button type="button" class="er-archive" data-er-action="archive" data-er-archive-button hidden>이전 시즌 내 기록 ${this.icon('arrow-right')}</button><div class="er-archive-results" data-er-archive-results></div></div></div>`;
            this.board=this.field('board');this.renderGames();this.renderFilters();this.renderTeam();
            root.addEventListener('click',e=>this.click(e));root.addEventListener('change',e=>this.change(e));root.addEventListener('submit',e=>this.join(e));
            this.field('search').addEventListener('input',()=>this.renderGames());
            root.addEventListener('keydown',e=>{const tab=e.target.closest('[data-er-scope]');if(!tab||!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;e.preventDefault();const tabs=[...root.querySelectorAll('[data-er-scope]:not(:disabled)')],i=tabs.indexOf(tab),next=e.key==='Home'?0:e.key==='End'?tabs.length-1:(i+(e.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;tabs[next].click();tabs[next].focus();});
            window.addEventListener('edu-games-record-deleted',()=>{this.leaders=[];});
            this.load();this.loadTeam();root.__RankingPage=this;
        }
        field(name){return this.root.querySelector(`[data-er-${name}]`);}
        icon(name){return `<svg aria-hidden="true"><use href="${h(this.config.icons)}#${name}"></use></svg>`;}
        async api(path,payload,signal){
            const response=await fetch(this.config.restUrl+path,{method:payload===undefined?'GET':'POST',credentials:'same-origin',signal,cache:'no-store',headers:{'Content-Type':'application/json','X-WP-Nonce':this.config.nonce},body:payload===undefined?undefined:JSON.stringify(payload)});
            const data=await response.json().catch(()=>null);if(!response.ok||!data)throw Error(data?.message||'순위를 불러오지 못했습니다.');return data;
        }
        renderGames(){
            const query=(this.field('search')?.value||'').trim().toLocaleLowerCase('ko-KR');let last='';
            const groups=new Map();this.games.forEach(g=>{if(!groups.has(g.category))groups.set(g.category,[]);groups.get(g.category).push(g);});
            this.field('games').innerHTML=[...groups.values()].flat().filter(g=>(g.title+' '+g.category).toLocaleLowerCase('ko-KR').includes(query)).map(g=>{
                const label=last===g.category?'':`<p class="er-category-label">${h(g.category)}</p>`;last=g.category;
                return `${label}<button type="button" data-er-game="${h(g.id)}" ${g.id===this.game.id?'aria-current="page"':''}><span class="er-game-icon" data-tone="${h(g.tone)}">${this.icon(g.icon)}</span><span>${h(g.title)}</span></button>`;
            }).join('')||'<p class="er-muted">검색한 게임이 없습니다.</p>';
        }
        renderFilters(){
            this.field('title').textContent=this.game.title;this.field('category').textContent=this.game.category;this.field('mark').innerHTML=this.icon(this.game.icon);this.field('mark').dataset.tone=this.game.tone;
            this.field('play').href=this.game.url;this.field('game-select').value=this.game.id;
            this.field('variant').innerHTML=this.game.variants.map(v=>`<option value="${h(v.id)}">${h(v.title)}</option>`).join('');this.field('variant').value=this.variant.id;
            this.field('level').innerHTML=this.variant.levels.map(l=>`<option value="${h(l.id)}">${h(l.title)}</option>`).join('');this.field('level').value=this.level;
            this.field('level-label').hidden=this.variant.levels.length<2;
            this.root.querySelectorAll('[data-er-scope]').forEach(b=>{const selected=b.dataset.erScope===this.scope;b.disabled=this.variant.id!=='arcade'&&b.dataset.erScope!=='overall';b.setAttribute('aria-selected',String(selected));b.tabIndex=selected?0:-1;b.title=b.disabled?'이 기록은 개인 순위로 집계합니다.':'';});
            this.field('archive-button').hidden=!this.variant.archive;
            this.field('history').hidden=true;this.field('archive-button').disabled=false;this.field('archive-button').setAttribute('aria-expanded','false');this.field('archive-button').dataset.loaded='';this.field('archive-results').replaceChildren();
            try{const url=new URL(location.href);url.searchParams.set('game',this.game.id);url.searchParams.set('records',this.variant.id);this.level?url.searchParams.set('level',this.level):url.searchParams.delete('level');history.replaceState(null,'',url);}catch{}
        }
        chooseGame(id){const game=this.games.find(g=>g.id===id);if(!game||game===this.game)return;this.game=game;this.variant=game.variants[0];this.level=this.variant.levels[0]?.id||'';this.scope='overall';this.renderGames();this.renderFilters();this.load();}
        change(e){if(e.target.matches('[data-er-game-select]'))this.chooseGame(e.target.value);
            if(e.target.matches('[data-er-variant]')){this.variant=this.game.variants.find(v=>v.id===e.target.value);this.level=this.variant.levels[0]?.id||'';this.scope='overall';this.renderFilters();this.load();}
            if(e.target.matches('[data-er-level]')){this.level=e.target.value;this.renderFilters();this.load();}}
        async click(e){
            const game=e.target.closest('[data-er-game]');if(game){this.chooseGame(game.dataset.erGame);return;}
            const scope=e.target.closest('[data-er-scope]');if(scope&&!scope.disabled){this.scope=scope.dataset.erScope;this.renderFilters();this.load();return;}
            const action=e.target.closest('[data-er-action]');if(!action||action.disabled)return;
            if(action.dataset.erAction==='refresh')return this.load();
            if(action.dataset.erAction==='archive')return this.archive(action);
            if(action.dataset.erAction==='leave-team'){
                if(!await this.confirm('팀에서 나갈까요?','내 소속만 해제하며 저장된 게임 기록은 유지합니다.'))return;
                action.disabled=true;try{const data=await this.api('/teams/leave',{});this.team=data.team;this.scope='overall';this.renderTeam();this.renderFilters();this.load();}catch(error){this.field('team-status').textContent=error.message;action.disabled=false;}
            }
        }
        async loadTeam(){try{const data=await this.api('/teams/mine');this.team=data.team;this.renderTeam();if(this.scope!=='overall')this.load();}catch{this.field('team-status').textContent='팀 정보를 불러오지 못했습니다.';}}
        renderTeam(){const t=this.team;this.field('team').innerHTML=t?`<strong>${h(t.name)}</strong><p>${number(t.memberCount||0)}명 · ${h(t.inviteCode||'')}</p><button type="button" class="er-text-button" data-er-action="leave-team">팀 나가기</button><p role="status" data-er-team-status></p>`:`<form data-er-join><label>초대 코드<input name="inviteCode" required maxlength="6" autocomplete="off" aria-label="팀 초대 코드"></label><button type="submit">팀 참여</button><p role="status" data-er-team-status></p></form>`;
            if(this.config.canManage)this.field('team').insertAdjacentHTML('beforeend',`<a class="er-manage-link" href="${h(this.config.manageUrl)}">팀 관리 ${this.icon('arrow-right')}</a>`);}
        async join(e){if(!e.target.matches('[data-er-join]'))return;e.preventDefault();const b=e.target.querySelector('button');b.disabled=true;try{const data=await this.api('/teams/join',{inviteCode:e.target.elements.inviteCode.value.trim()});this.team=data.team;this.renderTeam();this.load();}catch(error){this.field('team-status').textContent=error.message;b.disabled=false;}}
        async confirm(title,text){const d=document.createElement('dialog');d.className='edu-rank-dialog';d.innerHTML=`<h2>${h(title)}</h2><p>${h(text)}</p><form method="dialog"><button value="cancel" autofocus>취소</button><button value="confirm">팀 나가기</button></form>`;document.body.append(d);return new Promise(resolve=>{d.addEventListener('close',()=>{resolve(d.returnValue==='confirm');d.remove();},{once:true});d.showModal();});}
        async load(){
            const ticket=++this.request,key=`${this.game.id}:${this.variant.id}:${this.level}:${this.scope}:${this.scope==='team'?this.team?.id||0:0}`;
            this.abort?.abort();this.abort=new AbortController();this.field('status').textContent='기록 조회 중';
            this.root.querySelector('[data-er-action=refresh]').disabled=true;
            this.field('table-title').textContent=this.scope==='team'&&this.team?this.team.name+' · 팀 내 순위':scopes[this.scope];
            if(this.key!==key){this.key=key;this.leaders=[];this.paintMetrics(null);this.field('highlights').hidden=true;}
            const pager=window.EduGamesRankPager.request(this.board,key,()=>this.load());
            if(this.scope==='team'&&!this.team){const empty={leaderboard:[],pagination:{total:0,page:1,perPage:pager.perPage}};window.EduGamesRankPager.accept(this.board,key,empty,pager.ticket);this.paintMetrics(empty);this.board.innerHTML='<div class="er-empty"><strong>참여한 팀이 없습니다.</strong></div>';window.EduGamesRankPager.mount(this.board,key);this.field('status').textContent='';this.root.querySelector('[data-er-action=refresh]').disabled=false;return;}
            try{
                let data;
                if(this.variant.id==='arcade'){
                    const query=`page=${pager.page}&perPage=${pager.perPage}&overview=1`;
                    const path=this.scope==='overall'?`/leaderboards/game/${this.game.id}?${query}`:this.scope==='teams'?`/leaderboards/teams?game=${this.game.id}&${query}`:`/leaderboards/team/${this.team.id}?game=${this.game.id}&${query}`;
                    data=await this.api(path,undefined,this.abort.signal);
                }else data=await this.api(this.variant.api,{level:this.level,page:pager.page,perPage:pager.perPage,overview:true},this.abort.signal);
                if(ticket!==this.request||!window.EduGamesRankPager.accept(this.board,key,data,pager.ticket))return;
                const teams=this.scope==='teams',rows=teams?(data.standings||[]):(data.leaderboard||[]);
                if(pager.page===1||data.pagination?.page===1)this.leaders=rows.slice(0,3);
                this.paintMetrics(data);this.paintLeaders(teams);this.paintTable(rows,teams);window.EduGamesRankPager.mount(this.board,key,'[data-rank-row]',teams?'팀':'명');
                this.field('status').textContent=`전체 ${number(data.pagination?.total??rows.length)}${teams?'팀':'명'}`;
                this.paintHistory(data.history);
            }catch(error){if(ticket!==this.request||error.name==='AbortError')return;window.EduGamesRankPager.fail(this.board,pager.ticket);this.field('status').textContent=error.message;}
            finally{if(ticket===this.request)this.root.querySelector('[data-er-action=refresh]').disabled=false;}
        }
        paintMetrics(data){
            const teams=this.scope==='teams',overview=data?.overview,rows=teams?data?.standings:data?.leaderboard;
            const mine=overview?.personal||(rows||[]).find(r=>r.isMe||Number(r.userId)>0&&Number(r.userId)===Number(this.config.userId));
            const top=overview?.topScore??(this.leaders[0]?(teams?this.leaders[0].averageScore:this.leaders[0].score):null);
            this.field('count-label').textContent=teams?'참여 팀':'참여자';this.field('count').textContent=data?number(data.pagination?.total??rows?.length??0)+(teams?'팀':'명'):'-';
            this.field('count-note').textContent=teams?'전체 팀':this.scope==='team'?'팀 내 참여자':'전체 참여자';this.field('top-label').textContent=teams?'최고 평균':'최고 점수';this.field('top').textContent=top===null?'-':number(top);
            this.field('top-note').textContent=teams?'참여 팀원 최고점의 평균':this.variant.id==='play'?'오늘의 도전':this.variant.title;
            this.field('mine-label').textContent=teams?'내 팀':'내 순위';this.field('mine').textContent=teams?(this.team?.name||'미참여'):(mine?.rank?number(mine.rank)+'위':'-');
            this.field('mine-note').textContent=teams?(this.team?number(this.team.memberCount||0)+'명':'소속 팀 없음'):mine?`내 최고 ${number(mine.score)}점`:data?'아직 기록 없음':'기록 조회 중';
        }
        identity(row,teams=false){let name=teams?row.teamName:row.playerName||row.name||'도전자';const me=teams?Number(row.teamId)===Number(this.team?.id):Boolean(row.isMe||Number(row.userId)>0&&Number(row.userId)===Number(this.config.userId));if(me&&name==='나')name=this.config.userName||name;return {name,me};}
        avatar(name,tone){return `<span class="er-avatar" data-tone="${tone}" aria-hidden="true">${h(Array.from(name||'도전자').slice(0,1).join(''))}</span>`;}
        paintLeaders(teams){this.field('highlights').hidden=!this.leaders.length;this.field('leaders').innerHTML=this.leaders.map((r,i)=>{const {name,me}=this.identity(r,teams);return `<article class="er-leader" data-place="${Number(r.rank)||i+1}"><span class="er-place">${Number(r.rank)||i+1}</span>${this.avatar(name,['amber','blue','mint'][i])}<div><strong>${h(name)}${me?'<small class="er-me">나</small>':''}</strong><span>${number(teams?r.averageScore:r.score)}<small>점</small></span></div></article>`;}).join('');}
        paintTable(rows,teams){
            if(!rows.length){this.board.innerHTML='<div class="er-empty">'+this.icon('trophy')+'<strong>첫 번째 기록을 기다리고 있어요.</strong><a href="'+h(this.game.url)+'">게임 도전하기 '+this.icon('arrow-right')+'</a></div>';return;}
            const details=this.variant.id==='arcade'&&!teams,moderated=rows.some(r=>r.deleteUrl);
            this.board.innerHTML=`<table class="er-table ${details?'has-details':''}"><caption>${h(this.game.title)} · ${h(scopes[this.scope])}</caption><thead><tr><th scope="col">순위</th><th scope="col">${teams?'팀':'플레이어'}</th><th scope="col" class="er-numeric">${teams?'평균 점수':'최고 점수'}</th>${teams?'<th scope="col" class="er-detail">참여 인원</th>':details?'<th scope="col" class="er-detail">정확도</th><th scope="col" class="er-duration">플레이 시간</th>':''}${moderated?'<th scope="col" class="er-moderation-cell"><span class="er-sr">기록 관리</span></th>':''}</tr></thead><tbody>${rows.map(r=>{const {name,me}=this.identity(r,teams),rank=Number(r.rank)||0,score=teams?r.averageScore:r.score;return `<tr data-rank-row class="${me?'is-me':''}"><td><strong class="er-rank ${rank<=3?'er-rank-top':''}">${rank||'-'}</strong></td><td><div class="er-player">${this.avatar(name,me?'blue':rank%3===0?'coral':rank%2?'mint':'blue')}<span>${h(name)}${me?'<small class="er-me">나</small>':''}</span></div></td><td class="er-numeric"><b>${number(score||0)}</b><small>점</small></td>${teams?`<td class="er-detail">${number(r.memberCount||0)}명</td>`:details?`<td class="er-detail">${Number.isFinite(Number(r.accuracy))?number(r.accuracy)+'%':'-'}</td><td class="er-duration">${r.durationSeconds===undefined?'-':number(r.durationSeconds)+'초'}</td>`:''}${moderated?'<td class="er-moderation-cell"></td>':''}</tr>`;}).join('')}</tbody></table>`;
        }
        paintHistory(rows){const field=this.field('history');field.hidden=!rows?.length;if(!rows?.length)return;field.innerHTML=`<details><summary>내 평가 기록 · ${rows.length}회</summary><div class="er-history-list">${rows.map(r=>`<div><span>${h(r.started_at||'')}</span><span>${h({assessment:'평가',practice:'연습',guided:'안내 연습'}[r.mode]||r.mode)}</span><strong>${r.completed_at?'완료':'진행 중'}</strong></div>`).join('')}</div></details>`;}
        async archive(button){
            const field=this.field('archive-results'),key=this.key;
            if(button.dataset.loaded===key){field.hidden=!field.hidden;button.setAttribute('aria-expanded',String(!field.hidden));return;}
            button.disabled=true;try{const data=await this.api(this.variant.archive,{});if(key!==this.key)return;field.hidden=false;field.innerHTML=(data.records||[]).map(r=>`<div><span>${h(r.version)}</span><span>${h(r.level)}</span><strong>${number(r.score)}점</strong></div>`).join('')||'<p>이전 시즌 기록이 없습니다.</p>';button.dataset.loaded=key;button.setAttribute('aria-expanded','true');}catch(error){this.field('status').textContent=error.message;}finally{button.disabled=false;}
        }
    }
    const init=()=>document.querySelectorAll('[data-rankings-page]').forEach(root=>{if(!root.__RankingPage)new RankingPage(root);});
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
