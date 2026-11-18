(() => {
    'use strict';
    const assets = new URL('.', document.currentScript.src);
    const icons = new URL('../shell/icons.svg', assets).href;
    const h = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const icon = name => `<svg aria-hidden="true"><use href="${icons}#${name}"></use></svg>`;
    const control = (action, text, attrs = '') => `<button type="button" data-wh="${action}" data-focus="wh-${action}" ${attrs}>${text}</button>`;
    const crate = (item, attrs = '', button = false) => {
        const tag = button ? 'button' : 'span';
        return `<${tag} ${button ? 'type="button"' : ''} class="wh-crate wh-${h(item[0]?.toLowerCase())}" data-item="${h(item)}" ${attrs}><strong>${h(item.split('-')[0])}</strong><small>${h(item.split('-')[1])}</small></${tag}>`;
    };
    const sourceItem = (s, l) => l.hold !== null && l.hold !== undefined ? s.buffer[l.hold] : s.incoming[0];
    const dockName = slot => ['동쪽', '중앙', '서쪽'][slot];
    function render(s, l) {
        l.dock ??= 0;
        if (l.hold !== null && !s.buffer[l.hold]) l.hold = null;
        const done = s.status !== 'playing', item = sourceItem(s,l), order = s.orders[l.dock];
        const stat = (label, value, attr='') => `<div><span>${label}</span><strong ${attr}>${value}</strong></div>`;
        const incoming = s.incoming.slice(0, 7).map((id,i) => crate(id, i === 0 ? `data-wh="incoming" data-focus="wh-incoming" data-drag-source="incoming" aria-label="입고 첫 물건 ${h(id)} 선택" aria-pressed="${l.hold == null}"` : 'aria-hidden="true"', i===0)).join('');
        const lanes = ['stack','queue'].map(lane => {
            const list = s[lane], exit = lane === 'stack' ? list.length-1 : 0;
            const cells = Array.from({length:s.capacity},(_,i) => list[i] ? crate(list[i], i===exit ? `data-wh="pop-${lane}" data-focus="wh-pop-${lane}" data-drag-source="${lane}" aria-label="${lane==='stack'?'스택 맨 위':'큐 맨 앞'} ${h(list[i])} 출고"` : 'aria-hidden="true"', i===exit) : '<span class="wh-empty-slot" aria-hidden="true"></span>').join('');
            const title = lane === 'stack' ? '스택' : '큐', code = lane === 'stack' ? 'LIFO' : 'FIFO';
            return `<section class="wh-lane wh-lane-${lane}" data-destination="${lane}" aria-label="${title}">
                <header><h3>${title} <small>${code}</small></h3><span>${list.length}/${s.capacity}</span></header>
                <p class="wh-law">${lane==='stack'?'나중에 넣은 물건부터':'먼저 넣은 물건부터'}</p>
                ${control('put-'+lane, icon('arrow-right')+`<span>${item ? h(item)+' 적재' : '입고 대기'}</span>`, `data-drop-lane="${lane}" title="${lane==='stack'?'맨 위에 쌓기 (A)':'맨 뒤에 줄 세우기 (S)'}" ${!item||list.length>=s.capacity||done?'disabled':''}`)}
                <div class="wh-storage"><span class="wh-exit-label">${lane==='stack'?'입구 · 출구':'출구 ←'}</span><div class="wh-slots">${cells}</div>${lane==='queue'?'<span class="wh-entrance-label">← 입구</span>':''}</div>
                ${control('pop-'+lane, icon('arrow-right')+'<span>출고</span>', `title="${title} 출구 → ${dockName(l.dock)} 출고장 (${lane==='stack'?'J':'K'})" ${!list.length||done?'disabled':''}`)}
            </section>`;
        }).join('');
        const docks = s.orders.map((o,i) => {
            const sent = o.manifest.length-o.items.length;
            return `<button type="button" class="wh-dock ${o.express?'wh-express':''}" data-wh="dock" data-slot="${i}" data-focus="wh-dock-${i}" data-drop-dock="${i}" data-destination="dock-${i}" aria-pressed="${l.dock===i}" aria-label="${dockName(i)} 출고장 선택. 다음 물건 ${h(o.items[0])}" ${done?'disabled':''}>
                <span class="wh-dock-heading"><b>${dockName(i)} 출고장</b><small>${o.express?'급행':'일반'} #${o.id}</small></span>
                <span class="wh-manifest">${o.manifest.map((id,j)=>`<span class="${j<sent?'wh-delivered':j===sent?'wh-next':''}">${j<sent?'<b aria-label="배송 완료">✓</b>':crate(id)}</span>`).join('')}</span>
                <span class="wh-order-status">${o.express?`<span data-order-bonus="${i}">보너스 +${Math.floor(50*Math.min(1,o.remaining/o.window))}</span><span data-order-clock="${i}">${Math.ceil(o.remaining)}초</span>`:`<span>완수 +50</span><span>${sent}/${o.manifest.length}</span>`}</span>
            </button>`;
        }).join('');
        const buffer = Array.from({length:s.bufferCapacity},(_,i) => s.buffer[i] ? crate(s.buffer[i], `data-wh="hold" data-id="${i}" data-focus="wh-buffer-${i}" data-drag-source="buffer" aria-label="임시 보관 ${h(s.buffer[i])} 선택" aria-pressed="${l.hold===i}"`, true) : '<span class="wh-empty-slot" aria-hidden="true"></span>').join('');
        const f=s.feedback, delta=f?.delta || 0;
        const place=id=>({incoming:'입고',stack:'스택',queue:'큐',buffer:'임시 보관',storage:'보관대'}[id]||(id.startsWith('dock-')?dockName(Number(id.slice(5)))+' 출고장':id));
        const receipt=f?`${f.item} · ${place(f.from)} → ${place(f.to)}`:`${dockName(l.dock)} 출고장 · 다음 ${order.items[0]}`;
        const e=s.earnings;
        const outcome = done ? `<div class="wh-finish" data-result tabindex="-1"><span>오늘의 배송 종료</span><h3>${s.score.toLocaleString()}점</h3><p>배송 ${s.completed} · 주문 ${s.contracts} · 바로 배송 ${s.efficient}</p><p>재배치 ${s.relocations} · 최대 연속 ${s.bestCombo}</p><p class="wh-ledger">배송 ${e.delivery} + 효율 ${e.efficiency} + 연속 ${e.combo}<br>주문 ${e.contracts} + 급행 ${e.express} − 비용 ${e.cost}</p><p data-record-difference></p><div><button type="button" data-command="new">${icon('refresh-cw')}다시 도전</button><button type="button" data-command="show-records">${icon('trophy')}내 기록과 순위</button></div></div>` : '';
        return `<div class="wh-game ${done?'wh-ended':''}" data-warehouse data-board-layout="warehouse" tabindex="0" aria-label="스택·큐 물류 레이스 게임판">
            <header class="wh-hud">${stat('점수',s.score.toLocaleString())}${stat('남은 시간',Math.ceil(s.remaining)+'초','data-clock')}<div class="wh-run-stats">${stat('배송 / 주문',s.completed+' / '+s.contracts)}${stat('연속 배송',s.combo)}${stat('내 최고','…','data-personal-best')}</div><div class="wh-hud-actions">${control('begin',icon('arrow-right')+'<span>시작</span>','data-begin title="120초 도전 시작"')}${control('finish',icon('trophy'),'title="현재 기록으로 마치기" aria-label="도전 마치기"')}</div></header>
            <div class="wh-floor">
                <section class="wh-receiving" data-destination="incoming"><div class="wh-receiving-label"><img src="${new URL('warehouse-forklift.svg',assets).href}" alt="" width="56" height="44"><h3>입고</h3><span>${s.incoming.length}개</span></div><div class="wh-belt">${incoming||'<span class="wh-waiting">다음 주문 대기</span>'}</div>${s.incoming.length>7?`<span class="wh-more">+${s.incoming.length-7}</span>`:''}</section>
                <div class="wh-route"><div><span>선택 물건</span>${item?crate(item):'<b>입고 대기</b>'}</div><span aria-hidden="true">→</span><div><span>${dockName(l.dock)} 다음 주문</span>${order.items[0]?crate(order.items[0]):'<b>주문 완료</b>'}</div></div>
                <div class="wh-workspace">${lanes}<section class="wh-dispatch" aria-label="배송 주문"><h3>출고장 <small>다음 물건부터 배송</small></h3>${docks}</section></div>
                <section class="wh-buffer" data-drop-buffer data-destination="buffer"><div><h3>임시 보관 <small>${s.buffer.length}/${s.bufferCapacity}</small></h3><span>재배치 3점 비용</span></div><div class="wh-buffer-slots">${buffer}</div>${control('ship-held',icon('arrow-right')+'<span>보관 물건 출고</span>',`title="선택한 보관 물건을 ${dockName(l.dock)} 출고장으로 배송" ${l.hold==null||done?'disabled':''}`)}${control('reset',icon('refresh-cw')+'<small>−25</small>','title="보관 물건 전부 재입고 · 25점 비용" aria-label="긴급 재입고, 25점 비용" '+(!s.stack.length&&!s.queue.length&&!s.buffer.length||done?'disabled':''))}</section>
                <footer class="wh-feedback" role="status" title="${h(s.message)}"><span class="wh-feedback-kind">${f?.kind==='delivery'?'배송 완료':f?.kind==='park'?'임시 이동':f?.kind==='rescue'?'재입고':'물류 관제'}</span><span>${h(receipt)}</span><strong class="${delta<0?'wh-cost':''}">${delta?`${delta>0?'+':''}${delta}`:''}</strong></footer>
                ${outcome}
            </div>
        </div>`;
    }

    function mount(node,s,l,move) {
        let drag=null, suppress=0;
        const root=node.closest('[data-play]'), mounted=performance.now();
        const live=()=>s.status==='playing'&&root?.getAttribute('aria-busy')!=='true';
        const focusKey=()=>document.activeElement?.dataset.focus;
        const draw=()=>{
            const focus=focusKey();l.bestText=node.querySelector('[data-personal-best]')?.textContent||l.bestText;node.innerHTML=render(s,l);
            root.querySelectorAll('[data-begin]').forEach(el=>el.hidden=root.dataset.phase!=='ready');
            if(focus)node.querySelector(`[data-focus="${CSS.escape(focus)}"]`)?.focus({preventScroll:true});
            const best=l.bestText;if(best)node.querySelector('[data-personal-best]').textContent=best;
            tick();
        };
        const selectDock=slot=>{l.bestText=node.querySelector('[data-personal-best]')?.textContent;l.dock=slot;draw();};
        const send=action=>{if(!live())return;l.hold=null;return move(action);};
        const pop=(lane,slot=l.dock)=>send({type:'pop',lane,order:s.orders[slot].id});
        const put=lane=>send(l.hold==null?{type:'push',lane}:{type:'return',lane,id:l.hold});
        const tick=()=>{
            const active=root?.dataset.phase==='playing', elapsed=active?(performance.now()-mounted)/1000:0;
            s.orders.forEach((o,i)=>{
                const remaining=Math.max(0,o.remaining-elapsed), clock=node.querySelector(`[data-order-clock="${i}"]`), bonus=node.querySelector(`[data-order-bonus="${i}"]`);
                if(clock)clock.textContent=remaining?Math.ceil(remaining)+'초':'정시 보너스 종료';
                if(bonus)bonus.textContent='보너스 +'+Math.floor(50*Math.min(1,remaining/o.window));
            });
        };
        const timer=setInterval(tick,250);
        node.onclick=e=>{
            const target=e.target.closest('[data-wh]');if(!target||!live()||performance.now()<suppress)return;
            const action=target.dataset.wh;
            if(action==='dock'){selectDock(Number(target.dataset.slot));return;}
            if(action==='hold'){l.bestText=node.querySelector('[data-personal-best]')?.textContent;l.hold=Number(target.dataset.id);draw();return;}
            if(action==='incoming'){l.hold=null;draw();return;}
            if(action.startsWith('put-'))put(action.slice(4));
            if(action.startsWith('pop-'))pop(action.slice(4));
            if(action==='ship-held'&&l.hold!=null)send({type:'ship-buffer',id:l.hold,order:s.orders[l.dock].id});
            if(['begin','finish','reset'].includes(action))send({type:action});
        };
        node.onkeydown=e=>{
            if(!live()||e.ctrlKey||e.metaKey||e.altKey||e.isComposing||e.target.matches('input,select,textarea'))return;
            const key=e.key.toLowerCase();
            if(['1','2','3','a','s','j','k'].includes(key)){
                e.preventDefault();if(e.repeat)return;
                if(['1','2','3'].includes(key))selectDock(Number(key)-1);
                else if(key==='a'||key==='s'){if(sourceItem(s,l)&&s[key==='a'?'stack':'queue'].length<s.capacity)put(key==='a'?'stack':'queue');}
                else if(s[key==='j'?'stack':'queue'].length)pop(key==='j'?'stack':'queue');
            }
            if(key==='escape'&&l.hold!=null){e.preventDefault();e.stopPropagation();l.hold=null;draw();}
        };
        const clear=()=>{drag?.ghost?.remove();node.querySelectorAll('.wh-drag-over').forEach(el=>el.classList.remove('wh-drag-over'));drag=null;};
        node.onpointerdown=e=>{
            const token=e.target.closest('[data-drag-source]');if(!token||!live()||e.button!==0)return;
            drag={id:e.pointerId,x:e.clientX,y:e.clientY,source:token.dataset.dragSource,index:Number(token.dataset.id),item:token.dataset.item};
        };
        node.onpointermove=e=>{
            if(!drag||drag.id!==e.pointerId)return;
            if(!drag.ghost&&Math.hypot(e.clientX-drag.x,e.clientY-drag.y)<7)return;
            if(!drag.ghost){node.setPointerCapture(e.pointerId);drag.ghost=document.createElement('div');drag.ghost.className='wh-drag-ghost';drag.ghost.innerHTML=crate(drag.item);node.append(drag.ghost);}
            drag.ghost.style.left=e.clientX+'px';drag.ghost.style.top=e.clientY+'px';
            node.querySelectorAll('.wh-drag-over').forEach(el=>el.classList.remove('wh-drag-over'));
            document.elementFromPoint(e.clientX,e.clientY)?.closest('[data-drop-lane],[data-drop-dock],[data-drop-buffer]')?.classList.add('wh-drag-over');
        };
        node.onpointerup=e=>{
            if(!drag||drag.id!==e.pointerId)return;
            const d=drag, zone=d.ghost?document.elementFromPoint(e.clientX,e.clientY)?.closest('[data-drop-lane],[data-drop-dock],[data-drop-buffer]'):null;
            if(d.ghost)suppress=performance.now()+350;
            clear();if(node.hasPointerCapture(e.pointerId))node.releasePointerCapture(e.pointerId);
            if(!zone||!node.contains(zone)||!live())return;
            if(zone.dataset.dropLane&&['incoming','buffer'].includes(d.source))send(d.source==='buffer'?{type:'return',lane:zone.dataset.dropLane,id:d.index}:{type:'push',lane:zone.dataset.dropLane});
            if(zone.dataset.dropDock!==undefined&&d.source!=='incoming'){
                l.dock=Number(zone.dataset.dropDock);
                if(d.source==='buffer')send({type:'ship-buffer',id:d.index,order:s.orders[l.dock].id});else pop(d.source);
            }
            if(zone.hasAttribute('data-drop-buffer')&&['stack','queue'].includes(d.source))send({type:'park',lane:d.source});
        };
        node.onpointercancel=clear;node.onlostpointercapture=clear;
        const f=s.feedback;
        if(f&&l.lastEffect!==f.move){
            l.lastEffect=f.move;
            const target=node.querySelector(`[data-destination="${CSS.escape(f.to)}"]`);
            if(target&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches)target.animate([{filter:'brightness(1.2)',transform:'translateY(-3px)'},{filter:'brightness(1)',transform:'translateY(0)'}],{duration:240});
        }
        tick();
        queueMicrotask(()=>{
            if(node.isConnected&&root?.dataset.phase==='playing'&&!node.contains(document.activeElement))node.querySelector('[data-wh="put-stack"]:not(:disabled),[data-warehouse]')?.focus({preventScroll:true});
        });
        return [{destroy(){clearInterval(timer);clear();for(const event of ['click','keydown','pointerdown','pointermove','pointerup','pointercancel','lostpointercapture'])node['on'+event]=null;}}];
    }
    window.EduWarehouse={render,mount};
})();
