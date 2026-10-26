(() => {
    'use strict';
    const h=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const regions=['동쪽','중앙','서쪽'], colors=['#26745d','#3577af','#b0672e'];
    const names={line:'꺾은선그래프 · 시간에 따른 변화',pie:'원그래프 · 전체에서 차지하는 비율',histogram:'히스토그램 · 연속 구간별 분포',scatter:'산점도 · 두 값의 관계'};
    function bins(s){
        return Array.from({length:s.binCount},(_,i)=>{const low=s.binStart+i*s.binWidth,high=low+s.binWidth;return{low,high,count:s.rows.filter(r=>r.value>=low&&r.value<high).length};});
    }
    function choices(s){
        if(s.scene==='line')return regions.flatMap(region=>{
            const rows=s.rows.filter(r=>r.region===region);
            return rows.slice(1).map((b,j)=>{const a=rows[j];return{id:region+'|'+b.time,name:region+' '+a.time+'~'+b.time,value:b.value-a.value,unit:'cm 상승',before:a.value,after:b.value,region,index:j+1};});
        });
        if(s.scene==='histogram'){const set=bins(s);return set.slice(0,set.length-s.window+1).map((b,i)=>{const parts=set.slice(i,i+s.window).map(b=>b.count);return{id:'size-'+i,name:b.low+'cm 이상 '+set[i+s.window-1].high+'cm 미만',value:parts.reduce((a,b)=>a+b,0),unit:'명 포함',index:i,parts};});}
        if(s.scene==='scatter')return s.rows.map(r=>({id:r.id,name:r.name,value:s.scatterGoal==='distance'?r.x:-r.y,unit:s.scatterGoal==='distance'?'m':'W',distance:r.x,power:r.y,eligible:s.scatterGoal==='distance'?r.y<=s.budget:r.x>=s.minDistance}));
        return [];
    }
    function prepare(s,l){
        if(l.visualTurn!==s.turn||l.visualPhase!==s.phase||l.visualScene!==s.scene){
            l.visualTurn=s.turn;l.visualPhase=s.phase;l.visualScene=s.scene;
            l.visualPick=s.phase==='review'?s.feedback.selected?.id:null;
            l.allocation=s.phase==='review'&&s.scene==='pie'?[...s.feedback.allocation]:[0,0,0];l.activeRegion=null;
        }
    }
    function detail(s,c){
        if(!c)return '미선택';
        if(s.scene==='line')return `${c.after} − ${c.before} = ${c.value}cm`;
        if(s.scene==='histogram')return `${c.parts.join(' + ')} = ${c.value}명`;
        return `거리 ${c.distance}m · 소비 전력 ${c.power}W`;
    }
    function result(s){
        const f=s.feedback;if(!f)return '';
        const outcome=s.scene==='pie'?`<div class="dispatch-ratios">${f.details.map((r,i)=>`<div><strong>${r.name} · 요청 ${r.ratio}%</strong><span>내 배정 ${r.actual}개 / 비례 배정 ${r.target}개</span><div class="dispatch-ratio-track"><i style="width:${Math.min(100,100*r.actual/s.supply)}%;background:${colors[i]}"></i><b style="left:${Math.min(100,100*r.target/s.supply)}%" title="비례 배정 ${r.target}개"></b></div></div>`).join('')}</div>`:
            `<div class="er-outcome"><div><span>내 선택</span><b>${h(f.selected.name)}</b><strong>${detail(s,f.selected)}</strong>${s.scene==='scatter'&&!f.selected.eligible?'<span class="er-warning">'+(s.scatterGoal==='distance'?'전력 한도 초과':'목적지 도달 불가')+'</span>':''}</div><div><span>임무에 맞는 선택</span><b>${h(f.best.name)}</b><strong>${detail(s,f.best)}</strong></div></div>`;
        return `<section class="er-feedback ev-result dispatch-receipt ${f.correct?'correct':'miss'}" data-rescue-review tabindex="-1" role="status"><h3>${f.correct?'임무 성공':'배치를 다시 비교해 보세요'}${s.unranked?'':` · ${f.delta>0?'+':''}${f.delta}점`}</h3>${outcome}<details><summary>판정 근거</summary><p>${h(f.explanation)}</p></details><button type="button" data-visual-action="next" class="eh-primary">다음 출동</button></section>`;
    }
    function cargo(s,l){
        const units=[];l.allocation.forEach((n,i)=>{for(let j=0;j<n;j++)units.push(i);});
        return Array.from({length:Math.max(s.supply,units.length)},(_,i)=>`<span style="--cargo:${colors[units[i]]||'#e4e9e6'}" title="${units[i]===undefined?'미배정':regions[units[i]]}"></span>`).join('');
    }
    function piePanel(s,l,review){
        const total=s.rows.reduce((a,r)=>a+r.value,0),used=l.allocation.reduce((a,n)=>a+n,0);
        return `<h4>보급품 <span data-used>${used}</span> / ${s.supply}개</h4><div class="ev-cargo" aria-label="배정한 보급품 ${used}개">${cargo(s,l)}</div>
            <div class="ev-allocations">${s.rows.map((r,i)=>`<div class="ev-allocation ${l.activeRegion===i?'active':''}">
                <span><i style="background:${colors[i]}"></i>${r.region}<small>요청 ${r.value}건 · ${(100*r.value/total).toFixed(1)}%</small></span>
                <div class="ev-stepper"><button type="button" data-allocate="${i}" data-step="-1" aria-label="${r.region} 보급품 1개 줄이기" title="${r.region} 1개 줄이기" ${review?'disabled':''}>−</button><input type="number" min="0" max="${s.supply}" step="1" value="${l.allocation[i]}" data-allocation="${i}" aria-label="${r.region} 보급품 수" ${review?'disabled':''}><button type="button" data-allocate="${i}" data-step="1" aria-label="${r.region} 보급품 1개 늘리기" title="${r.region} 1개 늘리기" ${review?'disabled':''}>+</button></div>
                <div class="dispatch-allocation-rail"><input type="range" data-allocation-range="${i}" min="0" max="${s.supply}" step="1" value="${l.allocation[i]}" aria-label="${r.region} 보급품 배정" ${review?'disabled':''}><output data-allocation-output="${i}">${l.allocation[i]}개</output></div>
                <span class="ev-coverage" data-coverage="${i}">요청 대비 지원 ${(100*l.allocation[i]/r.value).toFixed(1)}%</span><meter data-coverage-meter="${i}" min="0" max="100" value="${100*l.allocation[i]/r.value}" aria-label="${r.region} 요청 대비 지원율"></meter>
            </div>`).join('')}</div><p data-balance class="${used>s.supply?'er-warning':'ev-balance'}">${used>s.supply?`${used-s.supply}개 초과`:`남은 보급품 ${s.supply-used}개`}</p>`;
    }
    function instrument(s,c){
        if(s.scene==='line'){
            return `<div class="ev-gauges">${['이전 수위','나중 수위'].map((label,i)=>{const v=c?(i?c.after:c.before):0;return `<div><span>${label}</span><div class="ev-water"><i style="height:${Math.max(0,Math.min(100,v))}%"></i></div><b>${c?v+'cm':'?'}</b></div>`;}).join('')}</div>`;
        }
        if(s.scene==='scatter')return `<div class="ev-power"><b>${h(s.capacity)}</b><label>통신 거리 ${c?c.distance:0}m<meter min="0" max="120" value="${c?.distance||0}" aria-label="통신 거리"></meter></label><label>소비 전력 ${c?c.power:0}W<meter min="0" max="100" value="${c?.power||0}" aria-label="소비 전력"></meter></label><strong class="${c&&!c.eligible?'er-warning':''}">${c?(c.eligible?'연결 가능':s.scatterGoal==='distance'?'전력 부족 '+(c.power-s.budget)+'W':'도달 거리 부족 '+(s.minDistance-c.distance)+'m'):'장치 미선택'}</strong></div>`;
        const set=bins(s),count=c?.value||0;
        return `<div class="ev-supported"><strong>지원 가능 ${count}명</strong><span>미지원 ${s.rows.length-count}명 · 전체 ${s.rows.length}명</span><meter min="0" max="${s.rows.length}" value="${count}" aria-label="조끼 지원 인원"></meter></div><div class="ev-passengers" aria-label="선택 범위에 포함된 사람">${set.map((b,i)=>Array.from({length:b.count},()=>`<i class="${c&&i>=c.index&&i<c.index+s.window?'supported':''}" title="${b.low}~${b.high}cm"></i>`).join('')).join('')}</div>`;
    }
    function live(s,l){
        const review=s.phase==='review',list=choices(s),picked=list.find(c=>c.id===l.visualPick),pie=s.scene==='pie';
        const titles={line:'시간별 수위 관측',pie:'지역별 보급 요청',histogram:'탑승자 가슴둘레 분포',scatter:'중계기 관측 성능'};
        const labels={line:'경보 구간',histogram:'적재할 연속 '+s.window+'개 구간',scatter:'중계기 선택'};
        return `<div class="ev-scene-label">${names[s.scene]}</div><div class="er-workspace ev-workspace dispatch-board" data-visual-scene="${s.scene}"><div><h4>${titles[s.scene]}</h4><div class="${pie?'ev-pie-pair':''}"><div><div class="er-chart ev-chart"><canvas data-visual-chart tabindex="0" aria-label="${names[s.scene]}" aria-keyshortcuts="ArrowLeft ArrowRight"></canvas></div>${pie?'<b>지역별 요청 비율</b>':''}</div>${pie?'<div><div class="er-chart ev-chart"><canvas data-allocation-chart aria-label="내 보급품 배분 비율"></canvas></div><b>내 배분 비율</b></div>':''}</div>
            ${s.scene==='line'||pie?`<div class="er-legend">${regions.map((r,i)=>`<span><i style="background:${colors[i]}"></i>${r}</span>`).join('')}</div>`:''}
            <div class="er-equation">${pie?`<b>요청 총 ${s.rows.reduce((a,r)=>a+r.value,0)}건 = 원 전체 100%</b><span>보급품 ${s.supply}개 = 배분 전체 100%</span>`:`<b>${picked?h(picked.name):labels[s.scene]+' 미선택'}</b><strong>${detail(s,picked)}</strong>${s.scene==='histogram'?'<span>하한 이상, 상한 미만 · 연속된 '+s.window+'개 구간의 사람 수</span>':''}`}</div>${s.scene==='histogram'?`<label class="ev-range">적재 범위 시작<input data-window type="range" min="0" max="${s.binCount-s.window}" step="1" value="${picked?.index??0}" aria-label="적재 범위 시작" ${review?'disabled':''}></label>`:''}</div>
            <div class="er-dispatch">${pie?piePanel(s,l,review):`<h4>${h(s.capacity)}</h4><div data-instrument>${instrument(s,picked)}</div><details class="dispatch-alternatives"><summary>${labels[s.scene]} 목록</summary><div class="dispatch-choices">${list.map(c=>`<button type="button" data-visual-choice="${h(c.id)}" aria-pressed="${picked?.id===c.id}" ${review?'disabled':''}>${h(c.name)}</button>`).join('')}</div></details>`}
            ${review?'':`<button type="button" data-visual-action="dispatch" class="eh-primary" ${pie?(l.allocation.reduce((a,b)=>a+b,0)!==s.supply?'disabled':''):(!picked?'disabled':'')}>${{line:'경보 배치',pie:'보급품 배분 확정',histogram:'선택 범위 적재',scatter:'중계기 배치'}[s.scene]}</button>`}</div></div>${result(s)}`;
    }
    function chartConfig(s,l,pick){
        const picked=choices(s).find(c=>c.id===l.visualPick);
        const options={responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false}},scales:{x:{title:{display:true}},y:{beginAtZero:true,title:{display:true}}}};
        if(s.scene==='line'){
            const times=[...new Set(s.rows.map(r=>r.time))];
            options.scales.x.title.text='관측 시각';options.scales.y.title.text='수위 (cm)';
            options.onClick=(event,els,chart)=>{
                if(els.length){const e=els[0];pick(regions[e.datasetIndex]+'|'+times[Math.max(1,e.index)]);return;}
                let nearest=null,distance=18;
                regions.forEach((region,d)=>{const points=chart.getDatasetMeta(d).data;for(let i=1;i<points.length;i++){
                    const a=points[i-1],b=points[i],dx=b.x-a.x,dy=b.y-a.y,t=Math.max(0,Math.min(1,((event.x-a.x)*dx+(event.y-a.y)*dy)/(dx*dx+dy*dy))),gap=Math.hypot(event.x-a.x-t*dx,event.y-a.y-t*dy);
                    if(gap<distance){distance=gap;nearest=region+'|'+times[i];}
                }});if(nearest)pick(nearest);
            };
            return{type:'line',data:{labels:times,datasets:regions.map((region,i)=>({label:region,data:s.rows.filter(r=>r.region===region).map(r=>r.value),borderColor:colors[i],backgroundColor:colors[i],pointRadius:6,pointHitRadius:16,tension:0,segment:{borderColor:ctx=>picked?.region===region&&ctx.p1DataIndex===picked.index?'#cf9416':colors[i],borderWidth:ctx=>picked?.region===region&&ctx.p1DataIndex===picked.index?6:2}}))},options};
        }
        if(s.scene==='pie'){
            delete options.scales;const total=s.rows.reduce((a,r)=>a+r.value,0);
            options.plugins.tooltip={callbacks:{label:c=>`${c.label}: ${c.raw}건 · ${(100*c.raw/total).toFixed(1)}%`}};
            options.onClick=(event,els)=>{if(els.length)pick(els[0].index);};
            return{type:'pie',data:{labels:regions,datasets:[{data:s.rows.map(r=>r.value),backgroundColor:colors,borderColor:s.rows.map((r,i)=>l.activeRegion===i?'#d49a24':'white'),borderWidth:s.rows.map((r,i)=>l.activeRegion===i?5:2)}]},options};
        }
        if(s.scene==='histogram'){
            const set=bins(s);options.scales.x.title.text='가슴둘레 (cm) · 하한 이상, 상한 미만';options.scales.y.title.text='사람 수 (명)';options.scales.y.ticks={stepSize:1};
            options.plugins.tooltip={callbacks:{title:items=>{const b=set[items[0].dataIndex];return `${b.low}cm 이상 ${b.high}cm 미만`;},label:c=>`${c.raw}명`}};
            options.onClick=(event,els)=>{if(els.length)pick('size-'+Math.max(0,Math.min(Math.round(els[0].index-(s.window-1)/2),set.length-s.window)));};
            return{type:'bar',data:{labels:set.map(b=>[String(b.low),String(b.high)+' 미만']),datasets:[{data:set.map(b=>b.count),barPercentage:1,categoryPercentage:1,backgroundColor:set.map((b,i)=>picked&&i>=picked.index&&i<picked.index+s.window?'#c78b22':'#3577af'),borderColor:'white',borderWidth:1}]},options};
        }
        options.scales.x={type:'linear',min:0,max:120,title:{display:true,text:'통신 거리 (m)'}};options.scales.y={min:0,max:100,title:{display:true,text:'소비 전력 (W)'}};
        options.plugins.tooltip={callbacks:{label:c=>c.datasetIndex===1?s.capacity:`${s.rows[c.dataIndex].name}: ${c.raw.x}m, ${c.raw.y}W`}};
        options.onClick=(event,els)=>{const e=els.find(e=>e.datasetIndex===0);if(e)pick(s.rows[e.index].id);};
        const condition=choices(s),limit=s.scatterGoal==='distance'?[{x:0,y:s.budget},{x:120,y:s.budget}]:[{x:s.minDistance,y:0},{x:s.minDistance,y:100}];
        return{type:'scatter',data:{datasets:[{data:s.rows.map(r=>({x:r.x,y:r.y})),pointRadius:8,pointHitRadius:14,backgroundColor:s.rows.map((r,i)=>r.id===l.visualPick?'#d79a26':condition[i].eligible?'#3577af':'#adb8c1'),borderColor:s.rows.map(r=>r.id===l.visualPick?'#735300':'#fff'),borderWidth:2},{type:'line',data:limit,borderColor:'#b3435d',borderDash:[6,4],borderWidth:2,pointRadius:0}]},options};
    }
    function mount(node,s,l,move){
        let chart=null,allocationChart=null;const surface=node.querySelector('[data-rescue-live]'),review=s.phase==='review';
        const canEdit=()=>!review&&!node.closest('[aria-busy=true]');
        function draw(focus){
            chart?.destroy();allocationChart?.destroy();surface.innerHTML=live(s,l);
            chart=new Chart(surface.querySelector('[data-visual-chart]'),chartConfig(s,l,id=>{if(!canEdit())return;if(s.scene==='pie')l.activeRegion=id;else l.visualPick=id;draw(s.scene==='pie'?'[data-allocation="'+id+'"]':'[data-visual-chart]');}));
            if(s.scene==='pie')allocationChart=new Chart(surface.querySelector('[data-allocation-chart]'),{type:'pie',data:{labels:[...regions,'미배정'],datasets:[{data:[...l.allocation,Math.max(0,s.supply-l.allocation.reduce((a,b)=>a+b,0))],backgroundColor:[...colors,'#e4e9e6'],borderColor:'white',borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>`${c.label}: ${c.raw}개`}}}}});
            if(focus)surface.querySelector(focus)?.focus({preventScroll:true});
        }
        node.onpointerdown=e=>{
            const button=e.target.closest('[data-visual-action],[data-rescue]');
            if(e.button!==0||!button||button.disabled||node.closest('[aria-busy=true]'))return;
            // A resized, focused number input can restore an old viewport on blur.
            const x=window.scrollX,y=window.scrollY;
            e.preventDefault();button.focus({preventScroll:true});
            if(window.scrollX!==x||window.scrollY!==y)window.scrollTo({left:x,top:y,behavior:'instant'});
        };
        node.onclick=e=>{
            const choice=e.target.closest('[data-visual-choice]');
            if(choice&&canEdit()){l.visualPick=choice.dataset.visualChoice;draw('[data-visual-chart]');return;}
            const step=e.target.closest('[data-allocate]');
            if(step&&canEdit()&&!step.disabled){const input=surface.querySelector('[data-allocation="'+step.dataset.allocate+'"]');input.value=String(Math.max(0,Math.min(s.supply,Number(input.value)+Number(step.dataset.step))));input.dispatchEvent(new Event('input',{bubbles:true}));return;}
            const b=e.target.closest('[data-visual-action],[data-rescue]');if(!b||b.disabled||node.closest('[aria-busy=true]'))return;
            const type=b.dataset.visualAction||b.dataset.rescue;
            if(type==='dispatch'){if(canEdit())move(s.scene==='pie'?{type,allocation:[...l.allocation]}:{type,choice:l.visualPick});return;}
            move({type});
        };
        node.onkeydown=e=>{
            if(!canEdit()||!e.target.matches('[data-visual-chart]')||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;
            e.preventDefault();const step=['ArrowLeft','ArrowUp'].includes(e.key)?-1:1;
            if(s.scene==='pie')l.activeRegion=((l.activeRegion??-1)+step+3)%3;
            else{const list=choices(s),i=list.findIndex(c=>c.id===l.visualPick);l.visualPick=list[(i+step+list.length)%list.length].id;}
            draw('[data-visual-chart]');
        };
        node.oninput=e=>{
            const input=e.target;if(!canEdit())return;
            if(input.matches('[data-allocation-range]')){
                const number=surface.querySelector('[data-allocation="'+input.dataset.allocationRange+'"]');number.value=input.value;number.dispatchEvent(new Event('input',{bubbles:true}));return;
            }
            if(input.matches('[data-window]')){
                l.visualPick='size-'+input.value;
                const next=chartConfig(s,l,()=>{});chart.data.datasets[0].backgroundColor=next.data.datasets[0].backgroundColor;chart.update('none');
                const picked=choices(s).find(c=>c.id===l.visualPick);
                surface.querySelector('.er-equation').innerHTML=`<b>${h(picked.name)}</b><strong>${detail(s,picked)}</strong><span>하한 이상, 상한 미만 · 연속된 ${s.window}개 구간의 사람 수</span>`;
                surface.querySelectorAll('[data-visual-choice]').forEach(el=>el.setAttribute('aria-pressed',String(el.dataset.visualChoice===l.visualPick)));
                surface.querySelector('[data-instrument]').innerHTML=instrument(s,picked);
                surface.querySelector('[data-visual-action=dispatch]').disabled=false;return;
            }
            if(!input.matches('[data-allocation]'))return;
            const i=Number(input.dataset.allocation),value=Number(input.value);
            l.allocation[i]=Number.isFinite(value)?Math.max(0,Math.min(s.supply,Math.trunc(value))):0;l.activeRegion=i;
            if(input.value!==''&&value!==l.allocation[i])input.value=l.allocation[i];
            surface.querySelectorAll('[data-allocation-range]').forEach((el,j)=>el.value=l.allocation[j]);
            surface.querySelectorAll('[data-allocation-output]').forEach((el,j)=>el.textContent=l.allocation[j]+'개');
            const used=l.allocation.reduce((a,n)=>a+n,0),balance=surface.querySelector('[data-balance]'),crates=surface.querySelector('.ev-cargo');
            surface.querySelector('[data-used]').textContent=used;crates.innerHTML=cargo(s,l);crates.setAttribute('aria-label','배정한 보급품 '+used+'개');
            balance.textContent=used>s.supply?`${used-s.supply}개 초과`:`남은 보급품 ${s.supply-used}개`;balance.className=used>s.supply?'er-warning':'ev-balance';
            surface.querySelectorAll('[data-coverage]').forEach((e,j)=>e.textContent=`요청 대비 지원 ${(100*l.allocation[j]/s.rows[j].value).toFixed(1)}%`);
            surface.querySelectorAll('[data-coverage-meter]').forEach((e,j)=>e.value=100*l.allocation[j]/s.rows[j].value);
            surface.querySelectorAll('.ev-allocation').forEach((e,j)=>e.classList.toggle('active',i===j));
            surface.querySelector('[data-visual-action=dispatch]').disabled=used!==s.supply;
            chart.data.datasets[0].borderColor=s.rows.map((r,j)=>j===i?'#d49a24':'white');chart.data.datasets[0].borderWidth=s.rows.map((r,j)=>j===i?5:2);chart.update('none');
            allocationChart.data.datasets[0].data=[...l.allocation,Math.max(0,s.supply-used)];allocationChart.update('none');
        };
        if(surface)draw();
        const key=s.turn+':'+s.phase;
        if(surface&&s.turn>0&&l.rescueFocus!==key){l.rescueFocus=key;const target=node.querySelector(review?'[data-rescue-review]':'.er-mission');target.focus({preventScroll:true});}
        return[{destroy(){chart?.destroy();allocationChart?.destroy();node.onpointerdown=null;node.onclick=null;node.onkeydown=null;node.oninput=null;}}];
    }
    if(typeof module!=='undefined')module.exports={bins,choices};
    if(typeof window!=='undefined')window.EduRescueScenes={prepare,live,mount};
})();
