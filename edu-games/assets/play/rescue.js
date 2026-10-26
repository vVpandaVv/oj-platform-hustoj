(() => {
    'use strict';
    const h = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const modes = {hour:'1시간씩',pair:'2시간씩 합계',total:'오전 전체 합계'};
    const regions = ['동쪽','중앙','서쪽'], colors = ['#26745d','#3577af','#b0672e'];
    function bars(s, mode) {
        const result = new Map();
        for (const row of s.rows) {
            const period = mode === 'total' ? '오전 전체' : mode === 'pair' ? (row.time < '11시' ? '09~10시' : '11~12시') : row.time;
            const id = row.region + '|' + period;
            if (!result.has(id)) result.set(id,{id,region:row.region,period,value:0,parts:[]});
            const bar = result.get(id); bar.value += row.value; bar.parts.push(row);
        }
        return [...result.values()];
    }
    function prepare(s,l) {
        if(s.scene!=='bar'){window.EduRescueScenes.prepare(s,l);return;}
        if (l.rescueTurn !== s.turn || l.rescuePhase !== s.phase) {
            l.rescueTurn=s.turn; l.rescuePhase=s.phase;
            l.rescueMode=s.phase==='review'?s.kind:(l.rescueMode||'hour');
            l.rescuePick=s.phase==='review'?s.feedback.selected.id:null;
        }
    }
    const formula = bar => bar.parts.map(p=>p.value).join(' + ') + (bar.parts.length>1?' = '+bar.value:'') + '명';
    function support(s,bar) {
        const total=s.rows.reduce((sum,r)=>sum+r.value,0),count=bar?.value||0;
        return `<div class="ev-supported"><strong>지원 ${count}명</strong><span>미지원 ${total-count}명 · 전체 ${total}명</span><meter min="0" max="${total}" value="${count}" aria-label="출동 지원 인원"></meter></div><div class="er-region-support">${regions.map((region,i)=>{
            const requests=s.rows.filter(r=>r.region===region).reduce((n,r)=>n+r.value,0),supported=bar?.region===region?count:0;
            return `<div><span>${region}</span><div class="er-support-track"><i style="width:${100*supported/requests}%;background:${colors[i]}"></i></div><b>${supported} / ${requests}명</b></div>`;
        }).join('')}</div>`;
    }
    function scene(s,bar) {
        const index=bar?regions.indexOf(bar.region):-1, target=index<0?150:50+index*100;
        return `<svg class="er-map" viewBox="0 0 300 176" role="img" aria-label="${bar?h(bar.region+' '+bar.period+(s.phase==='review'?' 도착':' 출동 예정')):'구조선 출동 대기'}">
            <rect x="0" y="0" width="300" height="82" fill="#eff3ee"/><rect x="0" y="82" width="300" height="94" fill="#e6f2f8"/>
            ${regions.map((region,i)=>`<g><rect x="${16+i*100}" y="43" width="68" height="43" rx="4" fill="${i===index?colors[i]:'#fff'}" stroke="${colors[i]}" stroke-width="2"/><text x="${50+i*100}" y="30" text-anchor="middle">${region}</text><text x="${50+i*100}" y="70" text-anchor="middle" fill="${i===index?'white':colors[i]}">${i===index?(s.phase==='review'?'도착':'예정'):'대기'}</text></g>`).join('')}
            <path d="M8 152 Q24 144 40 152 T72 152 T104 152 T136 152 T168 152 T200 152 T232 152 T264 152 T296 152" fill="none" stroke="#84b7ce" stroke-width="2"/>
            <g class="${s.phase==='review'?'er-boat-arrive':''}" style="--boat-x:${target}px" transform="translate(${target} 118)"><path d="M-25 0 H25 L15 18 H-15Z" fill="#265c65"/><rect x="-13" y="-17" width="26" height="17" rx="2" fill="#e3a52e"/><rect x="-8" y="-13" width="16" height="8" fill="#fff"/></g>
        </svg>`;
    }
    function result(s) {
        const f=s.feedback;
        if(!f)return '';
        return `<section class="er-feedback dispatch-receipt ${f.correct?'correct':'miss'}" role="status" data-rescue-review tabindex="-1"><h3>${f.correct?'출동 성공':'더 많은 요청이 있었습니다'}${s.unranked?'':` · ${f.delta>0?'+':''}${f.delta}점`}</h3>
            <div class="er-outcome"><div><span>내 출동</span><b>${h(f.selected.region)} · ${h(f.selected.period)}</b><strong>${f.selected.value}명</strong></div><div><span>이번 임무의 최선</span><b>${h(f.best.region)} · ${h(f.best.period)}</b><strong>${f.best.value}명</strong></div></div>
            <details><summary>시간 묶음별 비교</summary><p>${s.kind==='hour'?'한 시간의 요청은 그 시간의 막대 하나로 비교합니다.':s.kind==='pair'?'연속된 두 시간의 요청을 더해 비교합니다. 한 시간만 볼 때와 최선의 출동지가 같을 수도, 달라질 수도 있습니다.':'네 시간의 요청을 더해 비교합니다. 가장 높은 막대 하나가 있는 지역이 오전 합계도 항상 가장 큰 것은 아닙니다.'}</p>
            <div class="er-comparisons">${Object.entries(f.comparisons).map(([mode,bar])=>`<div class="${mode===s.kind?'current':''}"><span>${modes[mode]}</span><b>${h(bar.region)} · ${h(bar.period)}</b><span>${formula(bar)}</span></div>`).join('')}</div>
            </details><button type="button" data-rescue="next" class="eh-primary">다음 출동</button></section>`;
    }
    function live(s,l) {
        if(s.scene!=='bar')return window.EduRescueScenes.live(s,l);
        const mode=l.rescueMode, list=bars(s,mode), picked=list.find(b=>b.id===l.rescuePick), review=s.phase==='review';
        const fits=mode===s.kind;
        return `<div class="er-modes" role="group" aria-label="시간 묶음">${Object.entries(modes).map(([key,name])=>`<button type="button" data-rescue="mode" data-mode="${key}" aria-pressed="${mode===key}" ${review?'disabled':''}>${name}</button>`).join('')}</div>
            <div class="er-workspace dispatch-board"><div><div class="er-chart-title"><h4>지역별 구조 요청</h4><span>신규 요청 · 명</span></div><div class="er-chart"><canvas data-rescue-chart tabindex="0" aria-label="${h(modes[mode])} 지역별 구조 요청 막대그래프" aria-keyshortcuts="ArrowLeft ArrowRight Enter"></canvas></div>
            <div class="er-legend">${regions.map((name,i)=>`<span><i style="background:${colors[i]}"></i>${name}</span>`).join('')}</div>
            <div class="er-equation">${picked?`<b>${h(picked.region)} · ${h(picked.period)}</b><strong>${formula(picked)}</strong><span>${picked.parts.map(p=>p.time+' '+p.value+'명').join(' · ')}</span>`:'<b>선택한 요청</b><span>미선택</span>'}</div></div>
            <div class="er-dispatch"><h4>구조선 1척</h4>${scene(s,picked)}${support(s,picked)}<details class="dispatch-alternatives"><summary>출동지 목록</summary><div class="dispatch-choices">${list.map(b=>`<button type="button" data-rescue="pick" data-rescue-destination="${h(b.id)}" aria-pressed="${picked?.id===b.id}" ${review?'disabled':''}>${h(b.region)} · ${h(b.period)}</button>`).join('')}</div></details>
            <dl class="er-scope"><div><dt>임무 시간</dt><dd>${modes[s.kind]}</dd></div><div class="${fits?'':'mismatch'}"><dt>현재 그래프</dt><dd>${modes[mode]}</dd></div></dl>
            ${!fits?'<p class="er-warning" role="status">임무 시간과 그래프의 시간 범위가 다릅니다.</p>':''}
            ${!review?`<button type="button" data-rescue="dispatch" class="eh-primary" ${!picked||!fits?'disabled':''}>${picked?`${h(picked.region)} · ${h(picked.period)} 출동`:'출동 대기'}</button>`:''}</div></div>${result(s)}
            <details><summary>훈련 원본 기록 · 12개 관측</summary><div class="eh-table-scroll"><table><thead><tr><th>지역</th>${['09시','10시','11시','12시'].map(t=>`<th>${t}</th>`).join('')}</tr></thead><tbody>${regions.map(region=>`<tr><th>${region}</th>${s.rows.filter(r=>r.region===region).map(r=>`<td>${r.value}명</td>`).join('')}</tr>`).join('')}</tbody></table></div></details>`;
    }
    function render(s,l) {
        prepare(s,l);
        const done=s.status!=='playing';
        const hud=window.EduPlayViews.hud(s,false,true);
        if(done)return hud+`<div class="eh-result" data-result tabindex="-1"><h3>${s.unranked?'출동 연습':'출동 도전'} 완료</h3><strong>${s.unranked?s.completed+'회 성공':s.score+'점'}</strong><p>최고 연속 ${s.bestCombo}회 · 전체 임무 ${s.turn}회 · 정확도 ${s.turn?Math.round(100*s.completed/s.turn):0}%</p><dl class="er-scene-stats">${Object.entries(s.sceneStats).map(([scene,stats])=>`<div><dt>${{bar:'막대그래프',line:'꺾은선그래프',pie:'원그래프',histogram:'히스토그램',scatter:'산점도'}[scene]}</dt><dd>${stats.success} / ${stats.attempts}회 성공</dd></div>`).join('')}</dl>${s.unranked?'<p>연습 기록 · 순위 제외</p>':''}${window.EduPlayViews.resultActions(s)}</div>`;
        return hud+`<div class="er-mission" tabindex="-1"><div><span>출동 임무 ${s.turn+(s.phase==='review'?0:1)}</span><h3>${h(s.mission)}</h3><p>${h(s.objective)}</p></div><span class="er-capacity">${h(s.capacity)}${s.scene==='bar'?'<br>'+modes[s.kind]:''}</span></div>
            <p class="er-data-note">${h(s.contextNote)}</p>${!s.unranked&&s.phase==='choose'?`<div class="er-reward"><span>성공 +${{hour:100,total:120,pair:140,line:150,pie:200,histogram:150,scatter:140}[s.kind]}점</span><span>연속 보너스 +${Math.min(5,s.combo+1)*10}점</span><span>실수 −100점</span></div>`:''}<div data-rescue-live>${live(s,l)}</div><div class="eh-controls"><button type="button" data-rescue="finish">${s.unranked?'연습':'도전'} 마치기</button></div>`;
    }
    function mount(node,s,l,move) {
        if(s.scene!=='bar')return window.EduRescueScenes.mount(node,s,l,move);
        let chart=null;
        const surface=node.querySelector('[data-rescue-live]');
        function draw(focus) {
            chart?.destroy();surface.innerHTML=live(s,l);
            const list=bars(s,l.rescueMode), periods=[...new Set(list.map(b=>b.period))];
            const sets=l.rescueMode==='total'?[{label:'오전 전체',bars:list}]:regions.map(region=>({label:region,bars:list.filter(b=>b.region===region)}));
            chart=new Chart(surface.querySelector('[data-rescue-chart]'),{type:'bar',data:{labels:l.rescueMode==='total'?regions:periods,datasets:sets.map((set,j)=>({label:set.label,data:set.bars.map(b=>b.value),backgroundColor:set.bars.map(b=>colors[regions.indexOf(b.region)]),borderColor:set.bars.map(b=>b.id===l.rescuePick?'#cf9416':'transparent'),borderWidth:set.bars.map(b=>b.id===l.rescuePick?4:0),borderSkipped:false}))},options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false},tooltip:{callbacks:{title:items=>{const item=items[0],b=sets[item.datasetIndex].bars[item.dataIndex];return b.region+' · '+b.period;},label:item=>formula(sets[item.datasetIndex].bars[item.dataIndex])}}},scales:{y:{beginAtZero:true,title:{display:true,text:'신규 구조 요청 (명)'}}},onClick:(event,elements)=>{if(s.phase==='review'||!elements.length||node.closest('[aria-busy=true]'))return;const e=elements[0];l.rescuePick=sets[e.datasetIndex].bars[e.index].id;draw('destination');}}});
            if(focus==='destination')surface.querySelector('[data-rescue-chart]').focus({preventScroll:true});
            if(focus==='mode')surface.querySelector(`[data-mode="${l.rescueMode}"]`).focus({preventScroll:true});
        }
        node.onclick=e=>{
            const button=e.target.closest('[data-rescue]');if(!button||button.disabled||node.closest('[aria-busy=true]'))return;
            const type=button.dataset.rescue;
            if(type==='pick'){if(s.phase!=='review'){l.rescuePick=button.dataset.rescueDestination;draw('destination');}return;}
            if(type==='mode'){l.rescueMode=button.dataset.mode;l.rescuePick=null;draw('mode');return;}
            if(type==='dispatch'){if(l.rescuePick&&l.rescueMode===s.kind)move({type:'dispatch',view:l.rescueMode,bar:l.rescuePick});return;}
            move({type});
        };
        node.onkeydown=e=>{
            if(!e.target.matches('[data-rescue-chart]')||s.phase==='review'||node.closest('[aria-busy=true]'))return;
            if(['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key)){
                e.preventDefault();const list=bars(s,l.rescueMode),i=list.findIndex(b=>b.id===l.rescuePick),step=['ArrowLeft','ArrowUp'].includes(e.key)?-1:1;
                l.rescuePick=list[(i+step+list.length)%list.length].id;draw('destination');
            }else if(e.key==='Enter'&&l.rescuePick&&l.rescueMode===s.kind){e.preventDefault();move({type:'dispatch',view:l.rescueMode,bar:l.rescuePick});}
        };
        if(surface)draw();
        const focusKey=s.turn+':'+s.phase;
        if(surface&&s.turn>0&&l.rescueFocus!==focusKey){
            l.rescueFocus=focusKey;
            const target=node.querySelector(s.phase==='review'?'[data-rescue-review]':'.er-mission');
            target.focus({preventScroll:true});
        }
        return [{destroy(){chart?.destroy();node.onclick=null;node.onkeydown=null;}}];
    }
    if(typeof module!=='undefined')module.exports={bars};
    if(typeof window!=='undefined')window.EduRescue={render,mount};
})();
