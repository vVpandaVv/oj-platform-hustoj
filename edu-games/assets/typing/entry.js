(() => {
    'use strict';
    const h=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const icon=(name,url)=>`<svg class="ete-student-icon" aria-hidden="true" focusable="false"><use href="${h(url)}#${name}"></use></svg>`;
    const duration=n=>Number(n)%60===0?`${Number(n)/60}분`:`${Number(n)}초`;
    const number=(n,digits=1)=>Number(n||0).toLocaleString('ko-KR',{maximumFractionDigits:digits});
    const active=e=>(e.attempts||[]).find(a=>a.status==='running'&&a.token);
    const available=e=>!!active(e)||(e.status==='open'&&(e.attempts||[]).length<e.maxAttempts);
    const status=e=>e.participation?.status||(active(e)?'running':e.result?'finished':'absent');
    const states={absent:'미응시',running:'응시 중',finished:'완료'};
    const tone=s=>s==='running'?'warning':s==='finished'?'success':'neutral';
    const meta=e=>`${h(e.language==='python'?'Python':'C')} · ${duration(e.durationSeconds)} · 정확도 기준 ${h(e.minAccuracy)}% · 줄 끝 ${e.lineEnding==='space-enter'?'Space·Enter':'Enter'}`;
    const criterion=r=>r.verificationIssue?'전송 확인 필요':r.qualified?'기준 충족':r.validCharacters>0?'정확도 미달':'유효 코드 없음';
    const resultTone=r=>r.verificationIssue||!r.qualified?'warning':'success';
    function metrics(result,minAccuracy){
        const provisional=result?.status==='running',accuracy=Number(result?.inputAccuracy||0),threshold=Math.max(0,Math.min(100,Number(minAccuracy)));
        return `<div class="ete-student-metrics" data-typing-result-metrics${provisional?' data-provisional="true"':''}>
            <div><span>유효 CPM${provisional?' · 잠정':''}</span><strong data-student-cpm>${result?number(result.effectiveCpm):'-'}</strong><small>${result?'공백 제외 정타':'기록 대기'}</small></div>
            <div data-tone="${result&&!provisional&&accuracy<threshold?'warning':'neutral'}"><span>입력 정확도${provisional?' · 잠정':''}</span><strong data-student-accuracy>${result?number(accuracy,2)+'<small>%</small>':'-'}</strong><div class="ete-student-accuracy-track" aria-hidden="true"><span style="width:${Math.max(0,Math.min(100,accuracy))}%"></span><i style="left:${threshold}%"></i></div><small>기준 ${h(minAccuracy)}%</small></div>
        </div>`;
    }
    function history(e){
        const done=(e.attempts||[]).filter(a=>a.status==='finished');
        if(!done.length)return '';
        return `<details class="ete-student-results"><summary>회차별 기록 · ${done.length}회</summary><div class="ete-student-attempt-head" aria-hidden="true"><span>회차</span><span>유효 CPM</span><span>입력 정확도</span><span>판정</span></div>${done.map(a=>`<div class="ete-student-attempt" data-student-attempt="${Number(a.attemptNo)}"><span>${Number(a.attemptNo)}회${a.id===e.result?.id?'<small>대표</small>':''}</span><strong>${number(a.effectiveCpm)}</strong><span>${number(a.inputAccuracy,2)}%</span><small data-tone="${resultTone(a)}">${criterion(a)}</small></div>`).join('')}</details>`;
    }
    function card(e,options){
        const running=active(e),ready=available(e),attempts=e.attempts||[],s=status(e),result=e.result||running;
        const label=running?'이어서 응시':e.result?'다시 응시':'응시하기';
        const attrs=options.hub?`href="${h(link(options.gameUrl,e.id))}"`:running?`data-typing-resume="${h(running.token)}"`:`data-typing-evaluation="${Number(e.id)}"`;
        const tag=options.hub?'a':'button',action=ready?`<${tag} ${options.hub?'':'type="button"'} class="ete-student-action" ${attrs}>${label}${icon('arrow-right',options.iconsUrl)}</${tag}>`:'';
        const remaining=Math.max(0,Number(e.maxAttempts)-attempts.length);
        if(options.hub)return `<article class="ete-student-card ete-hub-card" data-typing-assignment-card="${Number(e.id)}" data-participation="${s}"><div class="ete-hub-info"><h3>${h(e.title)}</h3><div class="ete-hub-facts"><span class="ete-student-state" data-tone="${tone(s)}">${states[s]}</span><span>${h((e.contextLabels||[]).join(' · '))}</span><span>${meta(e)}</span></div></div>${result?`<div class="ete-hub-result"><span>유효 CPM <b>${number(result.effectiveCpm)}</b></span><span>입력 정확도 <b>${number(result.inputAccuracy,2)}%</b></span>${e.result?`<small class="ete-student-verdict" data-tone="${resultTone(e.result)}">${criterion(e.result)}</small>`:''}</div>`:''}${action||`<a class="ete-student-secondary" href="${h(link(options.gameUrl,e.id))}">결과 보기${icon('arrow-right',options.iconsUrl)}</a>`}</article>`;
        const recordLabel=e.result?`대표 ${Number(e.result.attemptNo)}회 · ${e.version==='typing-verified-v2'?'검정 v2':'검정 v3'} · 저장 완료`:running?`${Number(running.attemptNo)}회 응시 중 · 잠정 기록`:'아직 응시하지 않았습니다';
        return `<article class="ete-student-card" data-typing-assignment-card="${Number(e.id)}" data-participation="${s}">
            <div class="ete-student-card-top"><span class="ete-student-subject">${h((e.contextLabels||[]).join(' · '))}</span><span class="ete-student-state" data-tone="${tone(s)}">${states[s]}</span></div>
            <h3 tabindex="-1">${h(e.title)}</h3><p class="ete-student-meta">${meta(e)}</p>
            ${metrics(result,e.minAccuracy)}
            <div class="ete-student-record"><span>${recordLabel}</span>${e.result?`<span class="ete-student-verdict" data-tone="${resultTone(e.result)}">${criterion(e.result)}</span>`:''}</div>
            <div class="ete-student-card-foot"><span>${attempts.length} / ${Number(e.maxAttempts)}회 응시${running&&e.result?`<br>${Number(running.attemptNo)}회 재응시 중`:e.status==='closed'?'<br>응시 마감':ready?`<br>${remaining}회 남음`:'<br>응시 횟수 완료'}</span>${action}</div>
            ${history(e)}
        </article>`;
    }
    function link(base,id){const url=new URL(base,location.href);url.searchParams.set('evaluation',String(id));return url.href;}
    function render(evaluations,options={}){
        const filter=['all','absent','running','finished'].includes(options.filter)?options.filter:'all';
        const counts={all:evaluations.length,absent:0,running:0,finished:0};evaluations.forEach(e=>counts[status(e)]++);
        const priority=e=>active(e)?0:available(e)&&!e.result?1:e.result?2:3;
        const ordered=[...evaluations].sort((a,b)=>priority(a)-priority(b));
        const rows=ordered.filter(e=>filter==='all'||status(e)===filter),visible=options.hub?rows.slice(0,2):rows;
        const tabs=options.hub?'':`<div class="ete-student-statuses" role="group" aria-label="수행평가 응시 상태">${['all','absent','running','finished'].map(key=>`<button type="button" data-typing-filter="${key}" aria-pressed="${filter===key}"><span>${key==='all'?'전체':states[key]}</span><strong>${counts[key]}</strong></button>`).join('')}</div>`;
        return `<header class="ete-student-heading"><div>${icon('list',options.iconsUrl)}<h2 tabindex="-1">내 수행평가</h2><span class="ete-student-count">${evaluations.length}</span></div>${!options.hub?`<button type="button" class="ete-student-icon-button" data-typing-refresh title="평가 새로고침" aria-label="평가 새로고침">${icon('refresh-cw',options.iconsUrl)}</button>`:`<a class="ete-student-secondary" href="${h(options.gameUrl)}">내 평가 보기${icon('arrow-right',options.iconsUrl)}</a>`}</header>
            ${tabs}<div class="ete-student-grid">${visible.map(e=>card(e,options)).join('')}</div>${visible.length?'':`<div class="ete-student-empty">${evaluations.length?'해당 상태의 평가가 없습니다.':'배정된 수행평가가 없습니다.'}</div>`}`;
    }
    function selected(e,options={}){
        const result=options.result;
        return `<header class="ete-student-selected"><div class="ete-student-selected-icon">${icon('list',options.iconsUrl)}</div><div class="ete-student-selected-title"><span class="ete-student-state" data-tone="${options.finished?'success':'neutral'}">${options.finished?'응시 완료':'입력 대기'}</span><h2 tabindex="-1">${h(e.title)}</h2><p><span>${duration(e.durationSeconds)}</span><span>기준 ${h(e.minAccuracy)}%</span><span>줄 끝 ${e.lineEnding==='space-enter'?'Space·Enter':'Enter'}</span><span>${options.attemptNo||1}/${Number(e.maxAttempts)}회</span></p></div><button type="button" class="ete-student-secondary" data-typing-leave>${icon('arrow-left',options.iconsUrl)}평가 목록</button></header>
            ${options.finished&&result?`<section class="ete-student-finished" data-student-final-result tabindex="-1" aria-label="내 수행평가 결과"><div class="ete-student-receipt"><strong>서버 저장 완료 · ${Number(options.attemptNo)}회 결과</strong><span class="ete-student-verdict" data-tone="${resultTone(result)}">${criterion(result)}</span></div>${metrics(result,e.minAccuracy)}<div class="ete-student-result-facts"><span>유효 코드 <b>${number(result.validCharacters,0)}자</b></span><span>수정 전 포함 오타 <b>${number(result.mistakes,0)}회</b></span><span>${h(e.version==='typing-verified-v2'?'검정 v2':'검정 v3')}</span></div>${result.lateBatch?'<p class="ete-student-notice">마지막 서버 저장 지점까지 확정했습니다. 늦게 도착한 입력은 제외됩니다.</p>':''}<button type="button" class="ete-student-secondary" data-typing-review aria-expanded="false">${icon('list',options.iconsUrl)}<span>작성 코드 보기</span></button></section>`:''}`;
    }
    async function assigned(config){
        const url=new URL(config.restUrl,location.href),suffix='/typing/assigned';
        if(url.searchParams.has('rest_route'))url.searchParams.set('rest_route',url.searchParams.get('rest_route').replace(/\/$/,'')+suffix);else url.pathname=url.pathname.replace(/\/$/,'')+suffix;
        if(url.origin!==location.origin)throw Error('평가 주소를 확인하세요.');
        const response=await fetch(url.href,{credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':config.nonce||''}}),data=await response.json();
        if(!response.ok)throw Error(data.message||'평가를 불러오지 못했습니다.');return data.evaluations||[];
    }
    async function loadHub(root){
        try{const config=JSON.parse(root.dataset.config),rows=await assigned(config);root.hidden=!rows.length;root.innerHTML=rows.length?render(rows,{...config,hub:true}):'';}
        catch(error){root.innerHTML=`<p role="status">${h(error.message)}</p><button type="button" class="ete-student-secondary" data-entry-retry>다시 시도</button>`;root.querySelector('[data-entry-retry]').onclick=()=>loadHub(root);}
    }
    window.EduTypingEntry={render,selected,icon,available,active,link};
    const init=()=>document.querySelectorAll('[data-typing-entry-hub]').forEach(root=>{if(!root.dataset.entryReady){root.dataset.entryReady='true';loadHub(root);}});
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
