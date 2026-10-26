(() => {
    'use strict';
    const h=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const show=v=>v===null||v===undefined?'미상':typeof v==='number'?String(Math.round(v*100)/100):h(v);
    const descriptions={linear:'시간 간격대로 연결',mean:'합 ÷ 개수',median:'정렬 후 가운데',mode:'가장 자주 나온 종류',previous:'앞의 관측값 →',next:'← 뒤의 관측값',seasonal:'같은 시간대끼리',nearest:'특성이 가장 가까운 자료',unknown:'값을 만들지 않기'};
    const marks={linear:'●─●',mean:'Σ ÷ n',median:'▁ ▂ ▃ ▆ █',mode:'A A B A',previous:'● → ?',next:'? ← ●',seasonal:'↻',nearest:'≈',unknown:'?'};
    function sourceIds(s,l) { return s.rows.map((r,i)=>i).filter(i=>s.rows[i].value!==null&&(s.kind!=='cohort'||l.ids.includes(i))); }
    function predictions(s,method,ids) {
        const rows=ids.map(i=>s.rows[i]).filter(r=>r.value!==null);
        return s.targets.map(target=>{
            let use=[...rows],value=null,formula='근거 부족';
            if(!method)return {value:null,sources:[],formula:'복원 도구 미선택'};
            if(method==='unknown')return {value:null,sources:[],formula:'미상 보류'};
            if(method==='seasonal')use=use.filter(r=>r.period===target.period);
            if(['mean','median','mode','seasonal'].includes(method)&&use.length){
                const values=use.map(r=>r.value),numeric=values.every(v=>typeof v==='number');
                if(method==='mode'){
                    const counts=new Map();values.forEach(v=>counts.set(v,(counts.get(v)||0)+1));
                    const max=Math.max(...counts.values()),modes=[...counts].filter(([,n])=>n===max);
                    if(modes.length===1){value=modes[0][0];formula='최다 '+max+'회';}else formula='최빈값 동률 · 한 값으로 정할 수 없음';
                }else if(numeric){
                    values.sort((a,b)=>a-b);const n=values.length,sum=values.reduce((a,b)=>a+b,0);
                    value=method==='median'?(values[Math.floor((n-1)/2)]+values[Math.floor(n/2)])/2:sum/n;
                    formula=method==='median'?'정렬 후 가운데 값':sum+' ÷ '+n;
                }
            }else if(['previous','next','linear'].includes(method)){
                const a=rows.filter(r=>r.x<target.x).sort((a,b)=>b.x-a.x)[0],b=rows.filter(r=>r.x>target.x).sort((a,b)=>a.x-b.x)[0];
                use=method==='previous'&&a?[a]:method==='next'&&b?[b]:[];
                if(use.length){value=use[0].value;formula='시점 '+use[0].x+'의 값';}
                if(method==='linear'&&a&&b&&typeof a.value==='number'&&typeof b.value==='number'){use=[a,b];value=a.value+(b.value-a.value)*(target.x-a.x)/(b.x-a.x);formula=`${a.value} + (${b.value} − ${a.value}) × (${target.x} − ${a.x}) ÷ (${b.x} − ${a.x})`;}
            }else if(method==='nearest'&&rows.length){
                const distance=r=>r.features.reduce((sum,v,i)=>sum+(v-target.features[i])**2,0);
                use.sort((a,b)=>distance(a)-distance(b));use=[use[0]];value=use[0].value;formula='거리 제곱 '+distance(use[0]);
            }
            return {value:typeof value==='number'?Math.round(value*100)/100:value,sources:use.map(r=>r.label),formula};
        });
    }
    function prepare(s,l) {
        const key=s.level+':'+s.turn+':'+s.phase;
        if(l.missingKey!==key){l.missingKey=key;l.ids=[];l.method=null;l.target=0;}
    }
    function controls(s,l) {
        return `<div class="em-repair-heading"><h4>${h(s.context)}</h4><span>복원 ${s.turn+(s.phase==='review'?0:1)}</span></div><p class="em-evidence">${h(s.evidence)}</p>`+
            (s.group?`<div class="em-target-label">복원 대상 <strong>${h(s.group)}</strong><span>선택 관측 ${(s.phase==='review'?s.feedback.ids:l.ids).length} / ${s.rows.length}</span></div>`:'')+
            `<div class="em-methods em-toolbox" role="group" aria-label="복원 도구">${s.methods.map(m=>`<button type="button" data-do="method" data-value="${m}" aria-pressed="${s.phase==='review'?s.feedback.method===m:l.method===m}" ${s.phase==='review'?'disabled':''} title="${h(descriptions[m])}"><span class="em-method-mark" aria-hidden="true">${marks[m]}</span><b>${h(s.methodNames[m])}</b></button>`).join('')}</div>`;
    }
    function plot(s,p,active) {
        const all=s.rows.map(r=>r.value).concat(p.map(r=>r.value)).filter(v=>typeof v==='number'),min=Math.min(...all)-6,max=Math.max(...all)+6;
        const left=s.rows[0].x,span=s.rows[s.rows.length-1].x-left,x=v=>55+(v-left)/span*690,y=v=>190-(v-min)/(max-min)*150;
        const values=s.rows.map(r=>r.value===null?p[s.targets.findIndex(t=>t.x===r.x)].value:r.value);
        let out='';for(let i=0;i<4;i++){const v=min+(max-min)*i/3;out+=`<line x1="55" y1="${y(v)}" x2="745" y2="${y(v)}" class="eh-gridline"/><text x="45" y="${y(v)+5}" text-anchor="end">${show(v)}</text>`;}
        for(let i=1;i<s.rows.length;i++){
            const a=s.rows[i-1],b=s.rows[i],va=values[i-1],vb=values[i];if(va===null||vb===null)continue;
            out+=`<path d="M ${x(a.x)},${y(va)} L ${x(b.x)},${y(vb)}" fill="none" stroke="${a.value===null||b.value===null?'#b26504':'#11735f'}" stroke-width="3" ${a.value===null||b.value===null?'stroke-dasharray="6 5"':''}/>`;
        }
        s.rows.forEach((r,i)=>{
            const hole=r.value===null,v=values[i],cy=v===null?115:y(v),target=hole?s.targets.findIndex(t=>t.x===r.x):null;
            out+=`<circle cx="${x(r.x)}" cy="${cy}" r="${hole?12:8}" class="${hole?'eh-estimate':'eh-observed'}" ${hole?`data-do="missing-target" data-id="${target}" role="button" tabindex="0" aria-label="빈칸 ${target+1}"`:''} ${!hole&&p[active]?.sources.includes(r.label)?'style="stroke:#dd9a15;stroke-width:4"':''}/>`;
            out+=`<text x="${x(r.x)}" y="${cy-18}" text-anchor="middle">${v===null?'?':show(v)}</text><text x="${x(r.x)}" y="216" text-anchor="middle">${r.x}</text>`;
            if(r.period)out+=`<text x="${x(r.x)}" y="237" text-anchor="middle" class="eh-tiny">${h(r.label)}</text>`;
        });
        return `<svg class="eh-plot em-plot" viewBox="0 0 800 ${s.rows[0].period?255:235}" role="group" aria-label="관측값과 세 빈칸의 복원 그래프">${out}</svg><p class="eh-caption">가로: 시점 · 세로: ${h(s.unit)} · 채운 점: 관측 / 빈 점: 추정</p>`;
    }
    function records(s,l,p) {
        const reviewing=s.phase==='review',ids=reviewing?s.feedback.ids:l.ids,used=p[l.target||0]?.sources||[];
        const max=Math.max(...s.rows.map(r=>typeof r.value==='number'?r.value:0),1);
        return `<div class="em-observations">${s.rows.map((r,i)=>{
            const tag=s.kind==='cohort'?'button':'div',chosen=ids.includes(i),attrs=tag==='button'?`type="button" data-do="select" data-id="${i}" aria-pressed="${chosen}" ${reviewing?'disabled':''}`:'';
            return `<${tag} class="em-record ${used.includes(r.label)?'em-used':''}" ${attrs}><small>${h(r.label)}</small>${r.group?`<span>${h(r.group)}</span>`:''}${r.features?`<span>${r.features[0]} × ${r.features[1]} cm</span>`:''}<b>${show(r.value)} ${h(s.unit)}</b>${typeof r.value==='number'?`<i class="em-measure" style="--measure:${Math.max(0,r.value/max)*100}%"></i>`:''}</${tag}>`;
        }).join('')}</div>`;
    }
    function result(s) {
        if(s.phase!=='review')return '';
        const f=s.feedback;
        return `<div class="machine-receipt em-feedback ${f.scopeOk&&f.hits===3?'success':'miss'}" tabindex="-1" data-missing-result role="status"><strong>${f.scopeOk&&f.hits===3?'복원 성공':'복원 미통과'} · ${f.hits}/3</strong><span>${h(s.methodNames[f.method])}</span><b>${s.unranked?'연습':`${f.delta>0?'+':''}${f.delta}점`}</b>${!f.scopeOk?'<span>비교 대상이 섞였거나 필요한 관측이 빠졌습니다.</span>':''}</div><details class="em-comparison"><summary>방법별 복원 비교</summary><p>${h(f.reason)}</p><div class="eh-table-scroll"><table><thead><tr><th>방법</th><th>세 빈칸의 복원값</th><th>차이</th><th>일치</th></tr></thead><tbody>${f.comparisons.map(c=>`<tr ${c.method===f.method?'class="em-chosen"':''}><th>${h(s.methodNames[c.method])}${c.method===f.method?' (선택)':''}</th><td>${c.predictions.map(p=>show(p.value)).join(' / ')}</td><td>${c.errors.map(e=>e===null?'비교 불가':show(e)).join(' / ')}</td><td>${c.hits}/3</td></tr>`).join('')}</tbody></table></div></details>`;
    }
    function calculation(s,l,p) {
        const method=s.phase==='review'?s.feedback.method:l.method,values=p.sources.map(name=>s.rows.find(r=>r.label===name)?.value).filter(v=>v!==undefined);
        if(!values.length)return method?`<span>${method==='unknown'?'확인할 근거 없음 · 미상 유지':'근거 관측 미선택'}</span>`:'';
        const ordered=method==='median'?[...values].sort((a,b)=>a-b):values;
        return `<div class="em-calculation" aria-label="복원 계산 근거">${ordered.map((v,i)=>`<span class="${method==='median'&&(i===Math.floor((ordered.length-1)/2)||i===Math.floor(ordered.length/2))?'middle':''}">${show(v)}</span>${method==='mean'&&i<ordered.length-1?'<i>+</i>':''}`).join('')}<b>${method==='mean'?'÷ '+values.length:method==='median'?'가운데':method==='mode'?'최다':'→'}</b><strong>${show(p.value)}</strong></div>`;
    }
    function render(s,l) {
        const p=s.phase==='review'?s.feedback.predictions:predictions(s,l.method,sourceIds(s,l));
        return controls(s,l)+`<div class="em-repair-board"><section class="em-observation-bank"><h4>${s.kind==='cohort'?'비교 관측':'남아 있는 관측'}</h4>${s.kind==='series'?plot(s,p,l.target||0):records(s,l,p)}</section><section class="em-repair-targets"><div class="em-targets">${s.targets.map((t,i)=>{
            const review=s.phase==='review',ok=review&&s.feedback.scopeOk&&s.feedback.errors[i]!==null&&s.feedback.errors[i]<=s.tolerance;
            return `<button type="button" data-do="missing-target" data-id="${i}" data-method-drop="${i}" aria-pressed="${(l.target||0)===i}" class="em-target ${review?(ok?'restored':'broken'):''}"><span>${s.kind==='series'&&!t.period?'시점 '+t.x:h(t.label)}</span>${t.features?`<small>${t.features[0]} × ${t.features[1]} cm</small>`:''}<strong>${p[i].value===null&&!review&&l.method!=='unknown'?'?':show(p[i].value)} <small>${h(s.unit)}</small></strong><small>${review?`관측 ${s.feedback.observed[i]===null?'미상':show(s.feedback.observed[i])} · ${ok?'✓ 일치':'차이 '+(s.feedback.errors[i]===null?'확인 불가':show(s.feedback.errors[i]))}`:'추정값'}</small></button>`;
        }).join('')}</div><div class="em-formula" aria-live="polite">${calculation(s,l,p[l.target||0])}<b>${h(p[l.target||0].formula)}</b><small>근거: ${p[l.target||0].sources.map(h).join(', ')||'없음'}</small></div></section></div>`+result(s);
    }
    function ready(s,l) {return s.phase==='solve'&&s.methods.includes(l.method)&&(s.kind!=='cohort'||l.ids.length>=2);}
    const api={prepare,controls,render,predictions,sourceIds,ready,payload(s,l){return {type:'restore',method:l.method,ids:s.kind==='cohort'?l.ids:[]};}};
    if(typeof module==='object'&&module.exports)module.exports=api;else window.EduMissing=api;
})();
