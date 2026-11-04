(() => {
    'use strict';
    const h=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const button=(label,action,extra='',primary=false)=>`<button type="button" data-bt="${action}" ${extra} class="${primary?'bt-primary':''}">${label}</button>`;
    const states={lobby:'대기실',playing:'경기 중',paused:'일시 정지',finished:'경기 종료'};
    window.EduTeamOperations=class {
        constructor(team){this.t=team;this.board=team.config.screen==='board';}
        async list(){
            const t=this.t,c=t.config;
            if(this.board){t.app.innerHTML='<form data-bt-form="observe" class="bt-board-entry"><h3>현황판 열기</h3><div class="bt-inline"><input name="code" aria-label="방 코드" placeholder="6자리 방 코드" minlength="6" maxlength="6" required><button type="submit" class="bt-primary">현황판 열기</button></div></form>';return;}
            const gameUrl=game=>{const url=new URL(c.teacherUrl,location.href);url.searchParams.set('game',game);return h(url.href);};
            t.app.innerHTML=`<nav class="bt-tabs bt-game-tabs"><a href="${gameUrl('binary-conversion')}" ${c.gameId==='binary-conversion'?'aria-current="page"':''}>이진수 아케이드</a><a href="${gameUrl('device-matching')}" ${c.gameId==='device-matching'?'aria-current="page"':''}>장치 카드 매칭</a>${button('참여 기록','records')}</nav>
                <div class="bt-entry"><form data-bt-form="create"><h3>경기 만들기</h3><label>경기 이름<input name="title" value="${h(c.title)}" maxlength="60" required></label>
                <div class="bt-options"><label>경기 구분<select name="mode"><option value="official">정식 경기</option><option value="practice">연습 경기 · 봇 사용 가능</option></select></label><label>참가 시 팀 배정<select name="assignment"><option value="manual">교사 직접 배정</option><option value="auto">빈 팀부터 자동 배정</option></select></label>
                <label>팀 수<select name="teams">${[2,3,4,5,6].map(n=>`<option>${n}</option>`).join('')}</select></label><label>팀 정원<select name="capacity">${[2,3,4,5,6].map(n=>`<option ${n===5?'selected':''}>${n}</option>`).join('')}</select></label>
                <label>경기 시간<select name="duration"><option value="120">2분</option><option value="180" selected>3분</option><option value="300">5분</option></select></label><label>팀 난도<select name="difficulty"><option value="standard">팀 표준</option><option value="challenge">팀 도전</option></select></label><label>연습 봇 난이도<select name="botPace"><option value="slow" selected>입문</option><option value="normal">보통</option><option value="fast">도전</option></select></label></div><button type="submit" class="bt-primary">경기 만들기</button></form>
                <section><div class="bt-section-title"><h3>내가 운영하는 경기</h3>${button('새로 고침','list')}</div><div data-bt-rooms>불러오는 중</div><form data-bt-form="observe" class="bt-inline"><input name="code" aria-label="운영할 방 코드" placeholder="6자리 방 코드" minlength="6" maxlength="6" required><button type="submit">운영실 열기</button></form></section></div>`;
            try{const data=await t.request('/rooms');if(t.screen!=='rooms')return;const items=data.items.filter(r=>r.host||c.admin);t.app.querySelector('[data-bt-rooms]').innerHTML=items.map(r=>`<div class="bt-room-list"><strong>${h(r.title)}</strong><span>${r.mode==='official'?'정식':'연습'} · ${h(r.code)} · ${states[r.status]} · ${r.players}명</span><div class="bt-inline">${button('운영실','open',`data-code="${h(r.code)}"`)}${button('방 닫기','delete-room',`data-code="${h(r.code)}" data-round="${r.round}"`)}</div></div>`).join('')||'<p class="bt-muted">운영 중인 경기가 없습니다.</p>';}catch(e){t.say(e.message,true);}
        }
        link(){const t=this.t;return `<a class="bt-link" href="${h(t.config.boardUrl)}&amp;room=${h(t.room.code)}" target="_blank" rel="noopener">현황판 열기 ↗</a>`;}
        player(p,r){
            const control=!this.board&&r.host&&r.status==='lobby';
            return `<div class="bt-player"><span class="bt-online ${p.online?'is-online':''}" title="${p.online?'접속 중':'연결 끊김'}"></span><strong>${h(p.name)}${p.bot?' · 봇':''}</strong><span class="bt-ready ${p.ready?'is-ready':''}">${r.status==='lobby'?(p.ready?'준비':'대기'):`해결 ${p.correct}`}</span>${control?`<select data-bt-assign="${p.id}" aria-label="${h(p.name)} 팀"><option value="0" ${p.team===0?'selected':''}>미배정</option>${r.teams.map(x=>`<option value="${x.team}" ${p.team===x.team?'selected':''}>${x.team}팀</option>`).join('')}</select>${button('×','kick',`data-user="${p.id}" title="${h(p.name)} 참가 해제" aria-label="${h(p.name)} 참가 해제"`)}`:''}</div>`;
        }
        render(){
            const t=this.t,r=t.room;t.screen='room';
            t.root.dataset.teamCount=String(r.teams.length);
            // Do not replace a teacher's team selector while its menu is open.
            if(!this.board&&t.app.contains(document.activeElement)&&document.activeElement.matches('[data-bt-assign]'))return;
            if(r.status==='finished'){
                t.result(r.summary,true);
                if(this.board)t.app.querySelector('.bt-lobby-actions')?.remove();
                else t.app.insertAdjacentHTML('afterbegin',this.link());
                return;
            }
            const lobby=r.status==='lobby',waiting=r.players.filter(p=>p.team===0),counts=r.teams.map(x=>r.players.filter(p=>p.team===x.team).length);
            const startable=waiting.length===0&&counts.every(n=>n>=2&&n===counts[0])&&r.players.every(p=>p.ready&&p.online);
            const controls=this.board?'':`${this.link()}${lobby?button('무작위 팀 배정','balance')+(r.mode==='practice'?button('빈자리 봇 채우기','bot-fill')+button('봇 모두 빼기','bot-clear'):'')+button('경기 시작','start',startable?'':'disabled',true):button(r.status==='paused'?'경기 재개':'일시 정지',r.status==='paused'?'resume':'pause')+button('경기 종료','stop')}${button('방 닫기','close')}${button('방 목록','list')}`;
            t.app.innerHTML=`<header class="bt-ops-heading"><div><span class="bt-mode">${r.mode==='official'?'정식 경기':'연습 경기'}</span><h3>${h(r.title)}</h3><span>${states[r.status]} · ${r.config.duration/60}분 · ${r.players.length}명</span></div><div class="bt-project-code"><span>참가 코드</span><strong>${h(r.code)}</strong></div><div class="bt-project-time"><span>남은 시간</span><strong data-bt-time>${lobby?'대기 중':''}</strong><small class="bt-connection">REST</small></div></header>
                ${controls?`<nav class="bt-lobby-actions bt-ops-actions">${controls}${lobby&&r.mode==='practice'?`<label class="bt-inline">봇 난이도 <select data-bt-bot-level aria-label="봇 난이도">${[['slow','입문'],['normal','보통'],['fast','도전']].map(([value,label])=>`<option value="${value}" ${value===(r.config.botPace||'slow')?'selected':''}>${label}</option>`).join('')}</select></label>`:''}</nav>`:''}
                ${waiting.length?`<section class="bt-waiting"><h4>팀 배정 대기 · ${waiting.length}명</h4><div class="bt-waiting-roster">${waiting.map(p=>this.player(p,r)).join('')}</div></section>`:''}
                <div class="bt-ops-teams">${r.teams.map(team=>`<section class="bt-ops-team"><header><h4>${team.team}팀 <span>${counts[team.team-1]}/${r.config.capacity}명</span></h4><b>${lobby?'':`${team.rank}위`}</b></header><div class="bt-team-total"><strong>${team.score.toLocaleString()}<small>점</small></strong><span>${t.cardBoard?`${team.stage}판 · ${team.correct}쌍`:`해결 ${team.correct} · ${team.occupied}/8줄`}${team.ended?' · 종료':''}</span></div>
                    ${!lobby?`<div class="bt-activity ${t.cardBoard?'bt-activity-cards':'bt-activity-bits'}" aria-label="${team.team}팀 진행 상태">${(t.cardBoard?team.preview:Array.from({length:8},(_,i)=>team.preview[i]||{state:'empty'})).map(p=>`<i class="is-${h(p.state)} ${p.owner?'has-owner':''}" title="${p.state==='matched'?'해결':p.owner?'조작 중':p.state==='empty'?'빈 줄':'대기'}"></i>`).join('')}</div>${t.cardBoard?`<progress max="${team.pairs||1}" value="${team.stageCorrect||0}" aria-label="${team.team}팀 현재 판 완성도"></progress>`:''}`:''}
                    <div class="bt-ops-roster">${r.players.filter(p=>p.team===team.team).map(p=>this.player(p,r)).join('')||'<p class="bt-muted">참가 대기</p>'}</div>${!this.board&&lobby&&r.mode==='practice'?button('봇 추가','bot-add',`data-team="${team.team}" ${counts[team.team-1]>=r.config.capacity?'disabled':''}`):''}</section>`).join('')}</div>
                <section class="bt-feed"><h4>경기 소식</h4><ol>${r.feed.slice(this.board?-2:-6).reverse().map(e=>`<li><time>${new Date(e.at).toLocaleTimeString('ko-KR',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}</time> ${h(e.text)}</li>`).join('')||'<li>참가자를 기다리고 있습니다.</li>'}</ol></section>`;
            t.connectionLabel();
        }
    };
})();
