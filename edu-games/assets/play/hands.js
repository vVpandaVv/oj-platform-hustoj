(() => {
    'use strict';
    const h = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const mean = values => values.length ? values.reduce((a,b)=>a+b,0)/values.length : null;
    const fmt = n => n === null ? '없음' : Number(n.toFixed(1));
    const button = (text, action, extra = '', cls = '') => `<button type="button" data-do="${action}" class="${cls}" ${extra}>${text}</button>`;
    // Every board redraw replaces the markup, which used to drop keyboard
    // focus to <body> after each action. Remember the focused control by its
    // own data-* attributes and focus the same control in the new markup.
    const FOCUS_KEYS = ['focus', 'do', 'id', 'value', 'lane', 'gate', 'label', 'select', 'tray', 'field', 'rescue', 'mode', 'visualAction', 'allocate', 'step', 'boundary', 'source', 'wrongOnly', 'digit', 'validation'];
    const focusKey = (scope) => {
        const el = document.activeElement;
        if (!el || !scope || !scope.contains(el) || el === scope) return null;
        const parts = FOCUS_KEYS.filter(k => el.dataset && el.dataset[k] !== undefined)
            .map(k => '[data-' + k.replace(/[A-Z]/g, c => '-' + c.toLowerCase()) + '="' + CSS.escape(el.dataset[k]) + '"]');
        return parts.length ? el.tagName.toLowerCase() + parts.join('') : null;
    };
    const restoreFocus = (scope, key) => {
        if (!key || !scope) return;
        const el = scope.querySelector(key);
        if (el && !el.disabled) el.focus({ preventScroll: true });
    };
    const svg = (height, content) => `<svg class="eh-plot" viewBox="0 0 800 ${height}" role="group" aria-label="자료 조작판">${content}</svg>`;
    const line = (x1,y1,x2,y2,extra='class="eh-gridline"') => `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" ${extra}/>`;
    const label = (x,y,text,extra='') => `<text x="${x}" y="${y}" ${extra}>${h(text)}</text>`;
    const feedback = text => `<div class="eh-feedback" role="status">${text}</div>`;
    const names = {red:'빨강',blue:'파랑',triangle:'삼각형',circle:'원',id:'번호',color:'색',shape:'모양'};
    const selected = (l,id) => (l.ids || []).includes(id);
    function prepare(game,s,l) {
        if(l.turn !== s.turn) { l.turn=s.turn;l.ids=[];l.anchors=[];l.value=null;l.method='median'; }
        if(game==='missing-values')window.EduMissing.prepare(s,l);
        l.gates ||= s.gates || {color:'red',shape:'circle',join:'and',fields:['id','color','shape']};
        l.boundary ||= [...(s.line || [50,50])];
    }
    function outlier(s,l) {
        const values=s.values, lo=Math.min(...values)-7, hi=Math.max(...values)+7;
        const x=v=>100*(v-lo)/(hi-lo), original=mean(values), retained=mean(values.filter((v,i)=>!selected(l,i)));
        const placed=[];
        const points=values.map((v,i)=>{
            let row=0;while(placed.some(p=>p.row===row&&Math.abs(p.x-x(v))<20))row++;
            placed.push({row,x:x(v)});
            return button(h(v),'select',`data-id="${i}" data-outlier-token="${i}" aria-label="${v}${h(s.unit)} ${selected(l,i)?'관측판으로 되돌리기':'검토함으로 옮기기'}" aria-pressed="${selected(l,i)}" title="${selected(l,i)?'관측판으로':'검토함으로'}" style="left:${x(v)}%;top:calc(24px + ${row} * var(--eo-row-gap,48px))"`,'eo-point');
        }).join('');
        const height=`max(238px,calc(${Math.max(...placed.map(p=>p.row))} * var(--eo-row-gap,48px) + 114px))`;
        const f=s.feedback;
        const perfect=f&&f.selected.length===f.review.length&&f.selected.every(i=>f.review.includes(i));
        const verdict=f?`<div class="eo-verdict ${perfect?'success':'miss'}" role="status"><strong>${perfect?'정확한 분리':'다시 살펴볼 값'} ${f.delta===undefined?'':`<span>${f.delta>0?'+':''}${f.delta}점</span>`}</strong><div class="eo-verdict-values">${f.values.map((v,i)=>{
            const expected=f.review.includes(i),picked=f.selected.includes(i),wrong=expected!==picked;
            return `<span class="${wrong?'eo-wrong':''}" title="${expected?'검토 대상':'모여 있는 값'} · ${picked?'검토함으로 이동':'통과'}">${h(v)}<small>${wrong?(expected?'놓침':'과잉 선택'):(expected?'검토':'통과')}</small></span>`;
        }).join('')}</div></div>`:'<div class="eo-verdict eo-ready"><strong>검토 대상 분리</strong><span>원본 보존 · 검토함은 삭제함이 아닙니다</span></div>';
        return `<div class="eo-scanner">
            <div class="eo-stage-heading"><h4>${h(s.context)}</h4><span>관측 ${values.length}개 · ${h(s.unit)}</span></div>
            <div class="eo-distribution" data-outlier-zone="observed" aria-label="관측값 분포"><div class="eo-plot" style="height:${height}">
            <div class="eo-mean original" style="left:${x(original)}%" aria-hidden="true"></div>${retained===null?'':`<div class="eo-mean retained" style="left:${x(retained)}%" aria-hidden="true"></div>`}${points}
            <div class="eo-axis">${[0,1,2,3,4].map(i=>`<span style="left:${i*25}%">${fmt(lo+(hi-lo)*i/4)}</span>`).join('')}</div></div></div>
            <div class="eh-equation eo-means" aria-live="polite"><span class="original">원본 평균 <strong>${fmt(original)}</strong></span><span class="retained">검토 대상 제외 평균 <strong>${fmt(retained)}</strong></span><span class="eo-shift" title="화면에 표시된 평균값의 차이">표시값 차이 <strong>${retained===null?'없음':`${fmt(retained)>fmt(original)?'+':''}${fmt(fmt(retained)-fmt(original))}`}</strong></span></div>
            <div class="eh-review eo-review" data-outlier-zone="review"><div><h4>검토함 <strong>${l.ids.length}</strong></h4><span>통과 ${values.length-l.ids.length}개</span></div><div class="eo-tray">${l.ids.length?l.ids.map(i=>button(`${h(values[i])}<small>${h(s.unit)}</small><span aria-hidden="true">×</span>`,'select',`data-id="${i}" data-outlier-token="${i}" aria-label="${values[i]}${h(s.unit)} 관측판으로 되돌리기" title="관측판으로 되돌리기"`,'eo-review-token')).join(''):'<span class="eo-empty">검토 대상 없음</span>'}</div></div>
            <div class="eo-dispatch">${button(l.ids.length?`${l.ids.length}개 검토 · 나머지 통과`:'모두 통과','scan','','eh-primary')}<span>모음 ${s.turn+1}</span></div>${verdict}</div>`;
    }
    function missing(s,l) { return window.EduMissing.render(s,l); }
    function filtered(s,g) { return s.items.filter(i=>g.join==='and'?(i.color===g.color&&i.shape===g.shape):(i.color===g.color||i.shape===g.shape)); }
    const object = item => `<span class="eh-object" aria-label="${item.id} ${names[item.color]} ${names[item.shape]}"><i class="eh-symbol ${item.color} ${item.shape}"></i><span>${item.id}</span></span>`;
    function extraction(s,l) {
        const pass=filtered(s,l.gates),ids=pass.map(i=>i.id),fail=s.items.filter(i=>!ids.includes(i.id)),g=l.gates;
        const o=s.order,f=s.feedback;
        const gates=`<div class="ex-gates ${g.join}"><div class="ex-gate"><span>색 관문</span><div class="ex-swatches">${['red','blue'].map(c=>button(`<i class="eh-symbol circle ${c}"></i>`,'gate',`data-gate="color" data-value="${c}" aria-label="${names[c]} 관문" title="${names[c]}" aria-pressed="${g.color===c}"`)).join('')}</div></div><div class="ex-junction"><div class="ex-join" role="group" aria-label="조건 연결">${['and','or'].map(join=>button(`<b>${join==='and'?'∩':'∪'}</b><small>${join==='and'?'AND':'OR'}</small>`,'join',`data-value="${join}" title="${join==='and'?'두 관문 모두 통과':'어느 관문이든 통과'}" aria-pressed="${g.join===join}"`)).join('')}</div><span>${g.join==='and'?'둘 다':'하나 이상'}</span></div><div class="ex-gate"><span>모양 관문</span><div class="ex-swatches">${['circle','triangle'].map(shape=>button(`<i class="eh-symbol ${shape}"></i>`,'gate',`data-gate="shape" data-value="${shape}" aria-label="${names[shape]} 관문" title="${names[shape]}" aria-pressed="${g.shape===shape}"`)).join('')}</div></div></div>`;
        return `<div class="ex-order"><span>주문 ${s.turn+1}</span><strong>${names[o.color]} ${o.join==='and'?'이면서':'또는'} ${names[o.shape]}</strong><span>필수 라벨 ${o.fields.map(f=>names[f]).join(' · ')}</span></div>
            <div class="ex-factory"><section class="ex-infeed"><h4>입고 <b>${s.items.length}</b></h4><div class="ex-belt eh-objects">${s.items.map(object).join('')}</div></section>${gates}<section class="ex-outfeed"><h4>통과 <b>${pass.length}</b></h4><div class="ex-belt eh-objects">${pass.map(object).join('')||'<span class="ex-empty">통과 없음</span>'}</div><div class="ex-rejected"><span>대기 ${fail.length}</span><div class="eh-objects">${fail.map(object).join('')}</div></div></section></div>
            <section class="ex-labels"><div class="ex-label-head"><h4>출고 라벨</h4><div class="ex-fields">${['id','color','shape'].map(f=>`<label><input type="checkbox" data-field="${f}" ${g.fields.includes(f)?'checked':''}>${names[f]}</label>`).join('')}</div><div class="eh-equation"><strong>${pass.length}행 × ${g.fields.length}열</strong></div></div><div class="ex-tickets">${pass.map(item=>`<div class="ex-ticket" style="--fields:${Math.max(1,g.fields.length)}">${g.fields.map(f=>`<span><small>${names[f]}</small><b>${h(names[item[f]]||item[f])}</b></span>`).join('')||'<span>라벨 없음</span>'}</div>`).join('')}</div></section>
            ${f?`<div class="machine-receipt ${f.errors+f.columnErrors?'miss':'success'}" role="status"><strong>직전 출고 ${f.delta===undefined?'':(f.delta>0?'+':'')+f.delta+'점'}</strong><span>누락 ${f.wanted.filter(id=>!f.actual.includes(id)).length} · 잘못 출고 ${f.actual.filter(id=>!f.wanted.includes(id)).length} · 라벨 오류 ${f.columnErrors}</span><div class="ex-receipt-items">${(f.items||[]).map(item=>{const wanted=f.wanted.includes(item.id),sent=f.actual.includes(item.id);return `<span class="${wanted===sent?'':'wrong'}">${item.id}<small>${wanted===sent?(sent?'출고':'대기'):(sent?'오출고':'누락')}</small></span>`;}).join('')}</div></div>`:''}`;
    }
    function warehouse(s,l) {
        if(l.cargo?.source==='buffer'&&!s.buffer[l.cargo.id])l.cargo=null;
        const buffered=l.cargo?.source==='buffer',selectedBox=buffered?s.buffer[l.cargo.id]:s.incoming[0];
        const lane=(key,name)=>{
            const next=key==='stack'?s[key].length-1:0;
            const boxes=s[key].map((box,i)=>i===next?button(h(box),'pop',`data-lane="${key}" data-cargo="${key}" aria-label="${name} ${h(box)} 꺼내기" title="${name} 출구 · ${h(box)}" ${box!==s.orders[0]&&s.buffer.length>=2?'disabled':''}`,'eh-box wc-crate next'):`<span class="eh-box wc-crate">${h(box)}</span>`).join('');
            return `<section class="eh-lane wc-lane" data-cargo-drop="${key}"><div class="wc-lane-heading"><h4>${name}</h4><span>${key==='stack'?'LIFO':'FIFO'} · ${s[key].length}/5</span></div>${button(`${key==='stack'?'↓':'←'} ${h(selectedBox||'입고 없음')}`,'push',`data-lane="${key}" aria-label="${h(selectedBox||'물건')} ${name}에 넣기" title="${buffered?'임시 보관에서':'입고대에서'} ${name}으로" ${!selectedBox||s[key].length>=5?'disabled':''}`,'wc-entrance')}
                <div class="wc-exit-label">${key==='stack'?'↕ 입구·출구':'← 출구'}</div><div class="eh-${key} wc-track">${boxes||'<span class="wc-empty">비어 있음</span>'}</div><div class="wc-rule">${key==='stack'?'마지막에 넣은 물건부터':'먼저 넣은 물건부터'}</div></section>`;
        };
        const f=s.feedback;
        return `<div class="wc-dispatch" data-cargo-drop="dispatch"><span>지금 배송</span><strong>${h(s.orders[0])}</strong><div class="wc-order-list">${s.orders.slice(1).map(v=>`<span>${h(v)}</span>`).join('')}</div><small>조작 ${s.moves||0}회 · 배송 ${s.completed}개</small></div>
            <div class="wc-incoming"><h4>입고대</h4><div class="wc-incoming-belt">${s.incoming.map((v,i)=>i===0?button(h(v),'cargo',`data-source="incoming" data-cargo="incoming" aria-pressed="${!buffered}" title="${h(v)} 입고"`,'wc-crate'):`<span class="wc-crate waiting">${h(v)}</span>`).join('')||'<span>입고 완료</span>'}</div></div>
            <div class="eh-warehouse wc-warehouse">${lane('stack','스택')}${lane('queue','큐')}</div>
            <div class="wc-buffer"><div><h4>임시 보관</h4><small>재투입 −3점</small></div><div class="eh-buffer">${[0,1].map(i=>s.buffer[i]?button(h(s.buffer[i]),'cargo',`data-source="buffer" data-id="${i}" data-cargo="buffer" aria-pressed="${buffered&&l.cargo.id===i}" title="${h(s.buffer[i])} 재투입"`,'wc-crate'):'<span class="wc-empty-slot">빈칸</span>').join('')}</div></div>
            ${f?`<div class="machine-receipt ${f.to==='임시 보관'||f.delta<0?'miss':'success'}" role="status"><strong>${h(f.item)}</strong><span>${h(f.from)} → ${h(f.to)}</span><b>${f.delta>0?'+':''}${f.delta}점</b></div>`:''}<div class="wc-reset">${button('↻','reset','aria-label="남은 물건 입고대로 되돌리기, 최대 20점 차감" title="입고대로 되돌리기 · −20점"')}<span>재배치 −20점</span></div>`;
    }
    const predict = (p,b) => p.y >= b[0]+(b[1]-b[0])*p.x/100 ? 1:0;
    function classifier(s,l) {
        const x=v=>85+v*6.3,y=v=>320-v*2.7,b=l.boundary;
        const incoming=l.boundaryView==='incoming',points=incoming?s.incoming:s.samples;
        let plot=`<polygon points="85,50 715,50 715,${y(b[1])} 85,${y(b[0])}" fill="#edf0fd"/><polygon points="85,320 715,320 715,${y(b[1])} 85,${y(b[0])}" fill="#e4f2ea"/>`;
        for(let i=0;i<=100;i+=20){plot+=line(x(i),50,x(i),320)+line(85,y(i),715,y(i))+label(x(i),343,i,'text-anchor="middle"')+label(72,y(i)+5,i,'text-anchor="end"');}
        plot+=label(400,374,'폭 (cm)','text-anchor="middle"')+label(20,30,'무게 (g)');
        plot+=line(85,y(b[0]),715,y(b[1]),'stroke="#b07800" stroke-width="4"');
        points.forEach((p,i)=>{
            const guess=predict(p,b),correct=incoming||guess===p.label;
            plot+=`<g><title>${p.x}cm, ${p.y}g · ${incoming?'배송 '+(i+1):'실제 '+(p.label?'상자':'봉투')} / 현재 ${guess?'상자':'봉투'}</title><circle cx="${x(p.x)}" cy="${y(p.y)}" r="14" fill="${guess?'#6d69b9':'#147560'}" stroke="${correct?'white':'#e23152'}" stroke-width="${correct?2:4}"/>${label(x(p.x),y(p.y)+5,incoming?i+1:p.label?'상':'봉','text-anchor="middle" style="fill:white"')}</g>`;
        });
        b.forEach((v,i)=>{plot+=`<circle data-drag="boundary" data-end="${i}" cx="${i?715:85}" cy="${y(v)}" r="15" fill="#f5c044" stroke="#875a00" stroke-width="3"/>`;});
        const hits=[0,0];s.samples.forEach(p=>{if(predict(p,b)===p.label)hits[p.label]++;});
        const f=s.feedback;
        return `<div class="cf-heading"><h4>${h(s.shipment)}</h4><div class="cf-tabs" role="group" aria-label="분류판 자료">${button('예시 8개','boundary-view','data-value="samples" aria-pressed="'+!incoming+'"')}${button('이번 배송 8개','boundary-view','data-value="incoming" aria-pressed="'+incoming+'"')}</div></div>
            <div class="cf-workspace"><div>${svg(390,plot)}<div class="cf-boundary-controls">${[0,1].map(i=>`<label><span>${i?'오른쪽':'왼쪽'} 경계 <b>${b[i]}g</b></span><input type="range" min="0" max="100" data-boundary="${i}" value="${b[i]}" aria-label="${i?'오른쪽':'왼쪽'} 경계 무게"></label>`).join('')}</div></div>
            <div class="cf-routing">${[0,1].map(kind=>`<section class="cf-lane lane-${kind}"><h4>${kind?'상자':'봉투'} 배송길</h4><div class="cf-training"><span>예시 일치 ${hits[kind]} / 4</span><meter min="0" max="4" value="${hits[kind]}" aria-label="${kind?'상자':'봉투'} 예시 일치"></meter></div><div class="cf-parcels">${s.incoming.map((p,i)=>({p,i})).filter(({p})=>predict(p,b)===kind).map(({p,i})=>`<span class="cf-parcel"><b>${i+1}</b><small>${p.x}cm<br>${p.y}g</small></span>`).join('')||'<span class="cf-empty">배송 대기 없음</span>'}</div></section>`).join('')}<span class="cf-key">점 안 글자: 실제 포장 · 빨간 테두리: 오분류</span></div></div>
            ${f?`<div class="machine-receipt ${f.hits[0]+f.hits[1]===8?'success':'miss'}" role="status"><strong>직전 배송 ${f.hits[0]+f.hits[1]} / 8</strong><b>${f.delta===undefined?'':(f.delta>0?'+':'')+f.delta+'점'}</b><div class="cf-delivered">${f.items.map((p,i)=>`<span class="${p.label===p.prediction?'':'wrong'}"><b>${i+1}</b><small>${p.x}cm · ${p.y}g</small><span>${p.prediction?'상자':'봉투'} ${p.label===p.prediction?'✓':'→ '+(p.label?'상자':'봉투')}</span></span>`).join('')}</div></div>`:''}`;
    }
    const digit = (id,p,extra='',text='') => `<button type="button" class="eh-digit ${extra}" ${id.startsWith('c')?`data-digit="${id.slice(1)}" draggable="true"`:`data-validation="${id.slice(1)}"`} aria-label="${h(id.startsWith('c')?'학습 사진 '+(Number(id.slice(1))+1):'확인 사진 '+(Number(id.slice(1))+1))}"><canvas width="8" height="8" data-pixels="${p.pixels.join(',')}"></canvas><span>${text}</span></button>`;
    function handwriting(s,l) {
        const labels=s.labels||{},good=s.validation.filter(p=>p.prediction===p.label).length;
        const page=l.photosPage||0;
        const active=l.validation??s.validation.findIndex(p=>p.prediction!==p.label),vId=Math.max(0,active),p=s.validation[vId],nearest=p.neighbors.find(i=>labels[i]!==undefined);
        const recall=name=>{const rows=s.validation.filter(p=>p.label===name);return {hits:rows.filter(p=>p.prediction===name).length,total:rows.length};};
        let html=`<div class="coach-goal"><strong>손글씨 ${s.classes.join(' · ')} 인식</strong><span>기억 ${Object.keys(labels).length}/${s.memoryLimit}칸 · 미공개 검정 ${s.evaluationCount}장</span></div><div class="eh-coach coach-machine"><section><div class="coach-photo-head"><h4>학습 표본</h4><span>${page*12+1}~${Math.min(page*12+12,s.candidates.length)}/${s.candidates.length}</span>${button('←','photos-previous',`aria-label="이전 사진" title="이전 사진" ${page===0?'disabled':''}`)}${button('→','photos-next',`aria-label="다음 사진" title="다음 사진" ${page>=Math.ceil(s.candidates.length/12)-1?'disabled':''}`)}</div><div class="eh-digits coach-candidates">${s.candidates.slice(page*12,page*12+12).map((p,j)=>{const i=page*12+j;return digit('c'+i,p,'',`${i+1}${labels[i]!==undefined?' · '+labels[i]:''}`).replace('<button ',`<button aria-pressed="${l.card===i}" `);}).join('')}</div><div class="eh-trays coach-labels">`;
        for(const name of s.classes)html+=`<div class="eh-tray" data-tray="${name}">${button(name,'teach',`data-label="${name}" title="선택 표본에 ${name} 이름 붙이기" ${l.card===undefined?'disabled':''}`)}<small>기억 ${Object.values(labels).filter(v=>v===name).length}개</small></div>`;
        html+=`</div><div class="coach-memory-head"><h4>로봇의 기억</h4>${button('×','forget',`title="선택한 표본을 기억에서 빼기" aria-label="선택한 표본을 기억에서 빼기" ${l.card===undefined||labels[l.card]===undefined?'disabled':''}`)}</div><div class="coach-memory">${Array.from({length:s.memoryLimit},(_,i)=>{const entry=Object.entries(labels)[i];return entry?digit('c'+entry[0],s.candidates[entry[0]],nearest===Number(entry[0])?'coach-used':'','이름 '+entry[1]):'<span class="coach-slot">'+(i+1)+'</span>';}).join('')}</div></section>
            <section class="coach-inspector"><div class="coach-robot"><div class="coach-eyes" aria-hidden="true"><i></i><i></i></div><span>로봇의 답</span><strong class="${p.prediction!==null&&p.prediction!==p.label?'wrong':''}">${p.prediction??'?'}</strong><span>실제 이름 ${p.label}</span></div>
            <div class="eh-neighbor coach-chain">${digit('v'+vId,p,'','확인 표본')}<span aria-hidden="true">→</span>${nearest===undefined?'<span class="coach-no-memory">기억 없음</span>':digit('c'+nearest,s.candidates[nearest],'coach-used','붙인 이름 '+labels[nearest])}<span aria-hidden="true">→</span><strong>${p.prediction??'?'}</strong></div>
            <div class="coach-meter-list">${s.classes.map(name=>{const r=recall(name);return `<div><b>${name}</b><meter min="0" max="${r.total}" value="${r.hits}" aria-label="숫자 ${name} 확인 인식"></meter><span>${r.hits}/${r.total}</span></div>`;}).join('')}</div>
            <div class="coach-photo-head"><h4>확인 표본 ${good}/${s.validation.length}</h4><label><input type="checkbox" data-wrong-only ${l.wrongOnly?'checked':''}>오인식만</label></div><div class="eh-digits coach-validation">${s.validation.map((p,i)=>({p,i})).filter(({p})=>!l.wrongOnly||p.prediction!==p.label).map(({p,i})=>digit('v'+i,p,p.prediction!==null&&p.prediction!==p.label?'wrong':'',`${p.prediction??'?'} → ${p.label}`).replace('<button ',`<button aria-pressed="${vId===i}" `)).join('')||'<span class="coach-all-correct">모든 확인 표본 인식 성공</span>'}</div></section></div>`;
        return html;
    }
    const liveRender = {'outlier-detection':outlier,'missing-values':missing,'data-extraction':extraction,'data-structure-lab':warehouse,'ai-classifier-lab':classifier,'ai-model-lab':handwriting};
    function hud(s,coach=false,rescue=false) {
        const done=s.status!=='playing',unranked=!!s.unranked;
        return `<div class="eh-hud eh-scoreboard"><div class="eh-score-stat"><span>${unranked?'연습 성공':coach?'확인 인식':'점수'}</span><strong>${unranked?s.completed:coach?`${s.validation.filter(p=>p.prediction===p.label).length}/${s.validation.length}`:s.score}</strong></div>
            <div class="eh-time-stat"><span>${unranked?'시간제한 없음':s.timerPaused?'결과 확인 중':'남은 시간'}</span>${unranked?'<strong>연습</strong>':`<strong data-clock>${Math.ceil(s.remaining)}초</strong><progress data-countdown max="${s.duration||120}" value="${Math.max(0,s.remaining)}" aria-label="남은 시간"></progress>`}</div>
            <div><span>${coach?'학습 표본':'연속 성공'}</span><strong>${coach?`${Object.keys(s.labels).length}/${s.memoryLimit}`:s.combo||0}</strong></div>
            <div class="eh-best-stat"><span>${unranked?'순위 제외':'내 최고'}</span><strong data-personal-best>${unranked?'연습':'…'}</strong></div>
            ${!done?`<button type="button" data-${rescue?'rescue':'do'}="begin" data-begin class="eh-primary eh-start">${unranked?'연습 시작':'도전 시작'}</button>`:''}</div>`;
    }
    function resultActions(s) {
        return `<div class="eh-result-actions"><p data-record-difference ${s.unranked?'hidden':''}></p><button type="button" class="eh-primary" data-command="new">다시 도전</button><button type="button" data-command="show-records">내 기록과 순위</button></div>`;
    }
    function render(game,s,l) {
        if(game==='data-visualization')return window.EduRescue.render(s,l);
        prepare(game,s,l);
        const done=s.status!=='playing';
        const coach=game==='ai-model-lab'&&!done;
        if(done&&game==='missing-values'&&s.unranked)return `<div class="eh-result" data-result tabindex="-1"><h3>방법 연습 완료</h3><p>복원 성공 ${s.completed}회 · 순위 제외</p>${resultActions(s)}</div>`;
        const scoreboard=hud(s,coach);
        if(done)return scoreboard+`<div class="eh-result" data-result tabindex="-1"><h3>${s.unranked?'역할 연습':'도전'} 완료</h3><strong>${s.unranked?'출동 '+s.turn+'회':s.score+'점'}</strong>${game==='ai-model-lab'?`<p>처음 보는 손글씨 ${s.metrics.correct}/${s.metrics.total}개 인식</p><p>${s.classes.map((name,i)=>`${name}: ${s.metrics.recall[i]}%`).join(' · ')}</p><p>사용 기억 ${s.metrics.memory}/${s.memoryLimit}장 · 이름 수정 ${s.metrics.changes}회</p>`:s.unranked?'<p>순위 제외 · 그래프의 축과 묶음에 따라 전달할 수 있는 정보가 달라집니다.</p>':`<p>성공 ${s.completed}회</p>`}${resultActions(s)}</div>`;
        const submit={'missing-values':s.phase==='review'?['다음 데이터','next']:['세 빈칸 복원 확정','restore'],'data-extraction':['출고','ship'],'ai-classifier-lab':['새 물건 8개 배송','classify'],'ai-model-lab':['최종 검정','finish']}[game];
        const actions=`<div class="eh-controls eh-actionbar">${submit?button(submit[0],submit[1],'','eh-primary'):''}${game!=='ai-model-lab'?button(s.unranked?'연습 마치기':'도전 마치기','finish','','eh-finish'):''}</div>`;
        const credit=game==='ai-model-lab'?'<small class="eh-caption">손글씨: <a href="https://doi.org/10.24432/C50P49" target="_blank" rel="noopener">Alpaydin · Kaynak / UCI</a>, <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">CC BY 4.0</a> · 0~9 부분 집합, 화면 재표현</small>':'';
        return scoreboard+`<div class="eh-playfield" data-hands-live>${liveRender[game](s,l)}</div>`+actions+credit;
    }
    function paintDigits(node) {
        node.querySelectorAll('[data-pixels]').forEach(canvas=>{const ctx=canvas.getContext('2d');canvas.dataset.pixels.split(',').forEach((v,i)=>{const c=Math.round(Number(v)*255/16);ctx.fillStyle=`rgb(${c},${c},${c})`;ctx.fillRect(i%8,Math.floor(i/8),1,1);});});
    }
    function mount(game,node,s,l,move) {
        if(game==='data-visualization')return window.EduRescue.mount(node,s,l,move);
        let drag=null,suppressClickUntil=0;
        const clearDrag=()=>{
            node.querySelector('.eo-drag-ghost')?.remove();
            node.querySelectorAll('.eo-drop-active,.eo-dragging,.machine-drop-active').forEach(el=>el.classList.remove('eo-drop-active','eo-dragging','machine-drop-active'));
            drag=null;
        };
        const cleanup={destroy(){clearDrag();for(const event of ['click','keydown','input','pointerdown','pointermove','pointerup','pointercancel','lostpointercapture','dragstart','dragover','dragleave','drop'])node['on'+event]=null;}};
        const live=node.querySelector('[data-hands-live]');
        if(!live)return [cleanup];
        const draw=()=>{
            const key=focusKey(node);
            const means=game==='outlier-detection'?[...live.querySelectorAll('.eo-mean')].map(el=>({kind:el.classList.contains('retained')?'retained':'original',left:el.style.left})):[];
            const markup=liveRender[game](s,l);
            if(game==='ai-classifier-lab'&&live.querySelector('.cf-workspace')){
                // Keep native range inputs mounted throughout a held-pointer gesture.
                const fresh=document.createElement('div');fresh.innerHTML=markup;
                live.querySelector('.eh-plot').replaceWith(fresh.querySelector('.eh-plot'));
                live.querySelector('.cf-routing').replaceWith(fresh.querySelector('.cf-routing'));
                live.querySelectorAll('[data-do=boundary-view]').forEach(el=>el.setAttribute('aria-pressed',String(el.dataset.value===(l.boundaryView||'samples'))));
                live.querySelectorAll('[data-boundary]').forEach(el=>{el.value=l.boundary[Number(el.dataset.boundary)];el.closest('label').querySelector('b').textContent=el.value+'g';});
            }else live.innerHTML=markup;
            paintDigits(live);
            if(!window.matchMedia('(prefers-reduced-motion: reduce)').matches)means.forEach(previous=>{
                const el=live.querySelector('.eo-mean.'+previous.kind);
                if(el&&el.style.left!==previous.left)el.animate([{left:previous.left},{left:el.style.left}],{duration:180,easing:'ease-out'});
            });
            restoreFocus(node,key);
            node.querySelectorAll('[data-do=join]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.value===l.gates.join)));
            node.querySelectorAll('[data-boundary]').forEach(e=>e.value=l.boundary[Number(e.dataset.boundary)]);
            if(game==='missing-values'){
                node.querySelectorAll('[data-do=method]').forEach(b=>b.setAttribute('aria-pressed',String((s.phase==='review'?s.feedback.method:l.method)===b.dataset.value)));
                const submit=node.querySelector('[data-do=restore]');if(submit)submit.disabled=!window.EduMissing.ready(s,l);
            }
            const estimate=node.querySelector('[data-estimate]');if(estimate)estimate.textContent=l.value??'?';
            const range=node.querySelector('[data-value=estimate]');if(range&&l.value!==null)range.value=l.value;
        };
        const toggle=id=>{l.ids=selected(l,id)?l.ids.filter(v=>v!==id):[...l.ids,id];draw();};
        const anchor=id=>{
            l.anchors=l.anchors.includes(id)?l.anchors.filter(v=>v!==id):[...l.anchors.slice(-1),id];
            if(l.anchors.length===2){const[a,b]=l.anchors.map(i=>s.points[i]).sort((a,b)=>a.time-b.time),t=s.points[s.hole].time;
                if(t>a.time&&t<b.time)l.value=Math.round(a.value+(b.value-a.value)*(t-a.time)/(b.time-a.time));
            }draw();
        };
        const sendLabel=(id,label)=>move({type:'label',id,label});
        const putCargo=lane=>{
            const action=l.cargo?.source==='buffer'?{type:'return',lane,id:l.cargo.id}:{type:'push',lane};
            l.cargo=null;move(action);
        };
        const transferClick=payload=>{
            if(payload.type==='method'){l.method=payload.value;draw();}
            if(payload.type==='digit'){l.card=payload.id;draw();}
            if(payload.type==='cargo'){
                if(['stack','queue'].includes(payload.source))move({type:'pop',lane:payload.source});
                else{l.cargo=payload;draw();}
            }
        };
        const transferZone=(payload,x,y)=>{
            const el=document.elementFromPoint(x,y),selector=payload.type==='cargo'?'[data-cargo-drop]':payload.type==='method'?'[data-method-drop]':'[data-tray]';
            const zone=el?.closest(selector);if(!zone||!node.contains(zone))return null;
            if(payload.type==='cargo'){
                const destination=zone.dataset.cargoDrop,stored=['stack','queue'].includes(payload.source);
                if(stored?destination!=='dispatch':!['stack','queue'].includes(destination)||s[destination].length>=5)return null;
            }
            return zone;
        };
        node.onclick=e=>{
            if(node.closest('[aria-busy=true]')||(performance.now()<suppressClickUntil&&(e.target===node||e.target.closest('[data-outlier-token],[data-cargo],[data-do=method],[data-digit]'))))return;
            const selectedPoint=e.target.closest('[data-select]');if(selectedPoint){toggle(Number(selectedPoint.dataset.select));return;}
            const a=e.target.closest('[data-anchor]');if(a){anchor(Number(a.dataset.anchor));return;}
            const d=e.target.closest('[data-digit]');if(d){l.card=Number(d.dataset.digit);draw();return;}
            const v=e.target.closest('[data-validation]');if(v){l.validation=Number(v.dataset.validation);draw();return;}
            const b=e.target.closest('[data-do]');if(!b||b.disabled)return;
            const action=b.dataset.do;
            if(action==='select'){toggle(Number(b.dataset.id));return;}
            if(action==='missing-target'){l.target=Number(b.dataset.id);draw();return;}
            if(action==='photos-previous'||action==='photos-next'){l.photosPage=Math.max(0,Math.min(Math.ceil(s.candidates.length/12)-1,(l.photosPage||0)+(action==='photos-next'?1:-1)));draw();return;}
            if(action==='anchor'){anchor(Number(b.dataset.id));return;}
            if(action==='method'){l.method=b.dataset.value;draw();return;}
            if(action==='gate'){l.gates[b.dataset.gate]=b.dataset.value;draw();return;}
            if(action==='join'){l.gates.join=b.dataset.value;draw();return;}
            if(action==='boundary-view'){l.boundaryView=b.dataset.value;draw();return;}
            if(action==='cargo'){l.cargo={source:b.dataset.source,id:Number(b.dataset.id)};draw();return;}
            if(action==='teach'){if(l.card!==undefined)sendLabel(l.card,b.dataset.label);return;}
            if(action==='forget'){sendLabel(l.card,'remove');return;}
            if(action==='scan'){move({type:'scan',ids:l.ids});return;}
            if(action==='restore'){
                if(window.EduMissing.ready(s,l))move(window.EduMissing.payload(s,l));return;
            }
            if(action==='ship'){move({type:'ship',...l.gates});return;}
            if(action==='classify'){move({type:'classify',line:l.boundary});return;}
            if(action==='push'){putCargo(b.dataset.lane);return;}
            if(['pop','return'].includes(action)){move({type:action,lane:b.dataset.lane,...(action==='return'?{id:Number(b.dataset.id)}:{})});return;}
            move({type:action});
        };
        node.onkeydown=e=>{if(['Enter',' '].includes(e.key)&&e.target.matches('[data-select],[data-anchor],[data-do=missing-target]')){e.preventDefault();e.target.dispatchEvent(new MouseEvent('click',{bubbles:true}));}};
        node.oninput=e=>{
            if(node.closest('[aria-busy=true]'))return;const t=e.target;
            if(t.dataset.value==='estimate')l.value=Number(t.value);
            if(t.dataset.boundary!==undefined)l.boundary[Number(t.dataset.boundary)]=Number(t.value);
            if(t.dataset.gate)l.gates[t.dataset.gate]=t.value;
            if(t.dataset.field)l.gates.fields=t.checked?[...l.gates.fields,t.dataset.field]:l.gates.fields.filter(v=>v!==t.dataset.field);
            if(t.matches('[data-wrong-only]'))l.wrongOnly=t.checked;
            draw();
        };
        node.onpointerdown=e=>{
            const source=e.target.closest('[data-cargo],[data-do=method]')||(e.pointerType==='touch'?e.target.closest('[data-digit]'):null);
            if(source&&e.button===0&&!source.disabled&&!node.closest('[aria-busy=true]')){
                e.preventDefault();source.focus({preventScroll:true});
                const payload=source.dataset.cargo?{type:'cargo',source:source.dataset.cargo,id:Number(source.dataset.id)}:source.dataset.digit!==undefined?{type:'digit',id:Number(source.dataset.digit)}:{type:'method',value:source.dataset.value};
                drag={kind:'transfer',payload,text:source.innerText,x:e.clientX,y:e.clientY,moved:false};node.setPointerCapture(e.pointerId);return;
            }
            const token=e.target.closest('[data-outlier-token]');
            if(token&&e.button===0&&!node.closest('[aria-busy=true]')){
                e.preventDefault();token.focus({preventScroll:true});
                drag={kind:'outlier',id:Number(token.dataset.outlierToken),x:e.clientX,y:e.clientY,moved:false};
                node.setPointerCapture(e.pointerId);return;
            }
            const t=e.target.closest('[data-drag]');if(!t||e.button!==0||node.closest('[aria-busy=true]'))return;
            e.preventDefault();drag={kind:t.dataset.drag,end:Number(t.dataset.end),rect:t.closest('svg').getBoundingClientRect(),height:game==='missing-values'?310:390};node.setPointerCapture(e.pointerId);
        };
        node.onpointermove=e=>{
            if(!drag||!e.buttons)return;
            if(drag.kind==='transfer'){
                if(!drag.moved&&Math.hypot(e.clientX-drag.x,e.clientY-drag.y)<7)return;
                drag.moved=true;let ghost=node.querySelector('.eo-drag-ghost');
                if(!ghost){ghost=document.createElement('span');ghost.className='eo-drag-ghost';ghost.textContent=drag.text;ghost.setAttribute('aria-hidden','true');node.append(ghost);}
                ghost.style.left=e.clientX+'px';ghost.style.top=e.clientY+'px';
                const zone=transferZone(drag.payload,e.clientX,e.clientY);
                node.querySelectorAll('[data-cargo-drop],[data-method-drop],[data-tray]').forEach(el=>el.classList.toggle('machine-drop-active',el===zone));return;
            }
            if(drag.kind==='outlier'){
                if(!drag.moved&&Math.hypot(e.clientX-drag.x,e.clientY-drag.y)<7)return;
                drag.moved=true;
                let ghost=node.querySelector('.eo-drag-ghost');
                if(!ghost){ghost=document.createElement('span');ghost.className='eo-drag-ghost';ghost.textContent=s.values[drag.id]+s.unit;ghost.setAttribute('aria-hidden','true');node.append(ghost);node.querySelectorAll(`[data-outlier-token="${drag.id}"]`).forEach(el=>el.classList.add('eo-dragging'));}
                ghost.style.left=e.clientX+'px';ghost.style.top=e.clientY+'px';
                const zone=document.elementFromPoint(e.clientX,e.clientY)?.closest('[data-outlier-zone]');
                node.querySelectorAll('[data-outlier-zone]').forEach(el=>el.classList.toggle('eo-drop-active',el===zone));return;
            }
            const py=(e.clientY-drag.rect.top)/drag.rect.height*drag.height;
            if(drag.kind==='boundary')l.boundary[drag.end]=Math.round(Math.max(0,Math.min(100,(320-py)/2.7)));
            else l.value=Math.round(Math.max(s.min,Math.min(s.max,s.min+(265-py)/215*(s.max-s.min))));
            draw();
        };
        node.onpointerup=e=>{
            if(drag?.kind==='transfer'){
                const current=drag,zone=transferZone(current.payload,e.clientX,e.clientY);suppressClickUntil=performance.now()+200;clearDrag();
                if(!node.closest('[aria-busy=true]')){
                    if(!current.moved)transferClick(current.payload);
                    else if(zone){
                        const p=current.payload;
                        if(p.type==='method'){l.method=p.value;l.target=Number(zone.dataset.methodDrop);draw();}
                        if(p.type==='digit')sendLabel(p.id,zone.dataset.tray);
                        if(p.type==='cargo'){if(zone.dataset.cargoDrop==='dispatch')move({type:'pop',lane:p.source});else{l.cargo=p;putCargo(zone.dataset.cargoDrop);}}
                    }
                }
            }else if(drag?.kind==='outlier'){
                const current=drag,zone=document.elementFromPoint(e.clientX,e.clientY)?.closest('[data-outlier-zone]');
                suppressClickUntil=performance.now()+200;clearDrag();
                if(!node.closest('[aria-busy=true]')&&(!current.moved||(zone&&node.contains(zone)&&selected(l,current.id)!==(zone.dataset.outlierZone==='review'))))toggle(current.id);
            }else clearDrag();
            if(node.hasPointerCapture(e.pointerId))node.releasePointerCapture(e.pointerId);
        };
        node.onpointercancel=node.onlostpointercapture=clearDrag;
        node.ondragstart=e=>{const d=e.target.closest('[data-digit]');if(d)e.dataTransfer.setData('text/plain','digit:'+d.dataset.digit);};
        node.ondragover=e=>{const tray=e.target.closest('[data-tray]');if(tray){e.preventDefault();tray.classList.add('dragover');}};
        node.ondragleave=e=>e.target.closest('[data-tray]')?.classList.remove('dragover');
        node.ondrop=e=>{
            const tray=e.target.closest('[data-tray]');if(!tray)return;e.preventDefault();tray.classList.remove('dragover');
            const[type,value]=e.dataTransfer.getData('text/plain').split(':');
            if(type==='digit'&&tray.dataset.tray)sendLabel(Number(value),tray.dataset.tray);
        };
        draw();return [cleanup];
    }
    window.EduPlayViews={h,hud,resultActions,render,mount,focusKey,restoreFocus,form(){throw Error('직접 조작 화면에서 제출하세요.');},pick(){}};
})();
