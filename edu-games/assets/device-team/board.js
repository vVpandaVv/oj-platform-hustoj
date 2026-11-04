(() => {
    'use strict';
    const h=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    class DeviceTeamBoard {
        constructor(game){
            this.game=game;this.nodes=new Map();this.selected='';
            game.root.addEventListener('click',e=>{const card=e.target.closest('[data-dt-card]');if(card&&!card.disabled){this.selected=card.dataset.dtCard;if(card.dataset.state==='down')this.flip(card.dataset.dtCard);else this.inspect();}if(e.target.closest('[data-dt-release]'))game.command({type:'release-cards',stage:game.room.board.stage});});
            game.root.addEventListener('focusin',e=>{const card=e.target.closest('[data-dt-card]');if(card){this.selected=card.dataset.dtCard;this.inspect();}});
            game.root.addEventListener('pointerover',e=>{const card=e.target.closest('[data-dt-card]');if(card&&card.dataset.state!=='down'){this.selected=card.dataset.dtCard;this.inspect();}});
            this.observer=new ResizeObserver(()=>this.layout());this.observer.observe(game.root);
        }
        template(){return '<div class="dt-workbench"><div class="dt-deck oj-device-deck" data-bt-board role="group" aria-label="팀 공유 카드판"></div><aside class="dt-inspector" data-dt-inspector aria-live="polite"></aside></div><div class="dt-actions"><span class="bt-board-status" data-dt-event data-bt-board-status role="status"></span><button type="button" data-dt-release>선택 놓기</button><a href="'+h(this.game.config.creditsUrl)+'" target="_blank" rel="noopener">사진 출처</a></div>';}
        flip(id){const b=this.game.room?.board;if(b)this.game.command({type:'flip',card:id,stage:b.stage});}
        sync(){
            const g=this.game,r=g.room,b=r.board,deck=g.app.querySelector('[data-bt-board]');if(!b)return;
            const key=`${r.code}:${r.round}:${r.watchTeam}:${b.stage}`;
            if(this.key!==key){this.key=key;this.nodes.clear();deck.replaceChildren();this.selected='';}
            const mine=b.turns[String(r.me)],blocked=r.status!=='playing'||r.serverNow<r.startedAt||b.clearAt||!r.myTeam||r.watchTeam!==r.myTeam||mine?.count===2;
            b.cards.forEach((card,index)=>{
                let node=this.nodes.get(card.id);
                if(!node){node=document.createElement('button');node.type='button';node.className='dt-card oj-device-card';node.dataset.dtCard=card.id;node.innerHTML='<span class="oj-device-card-inner dt-card-inner"><span class="oj-device-card-face oj-device-card-back dt-back"><span class="oj-device-card-mark">?</span><span>DEVICE</span></span><span class="oj-device-card-face oj-device-card-front dt-card-content"></span></span><span class="dt-card-owner"></span>';deck.append(node);this.nodes.set(card.id,node);}
                const face=card.face,changed=node.classList.contains('is-face-up')!==Boolean(face);node.dataset.state=card.state;node.disabled=Boolean(card.state==='matched'||(card.state==='down'&&blocked));
                // Native 2D faces avoid WebKit's 3D backface compositing issues.
                node.classList.toggle('is-mine',card.owner===r.me);node.classList.toggle('is-matched',card.state==='matched');
                if(changed)node.classList.add('has-turned');
                node.classList.toggle('is-face-up',Boolean(face));
                node.querySelector('.dt-back').setAttribute('aria-hidden',String(Boolean(face)));node.querySelector('.dt-card-content').setAttribute('aria-hidden',String(!face));
                const content=face?face.kind==='photo'?`<span class="oj-device-photo-wrap"><img src="${h(face.image)}" alt="${h(face.alt)}" draggable="false"></span><span class="oj-device-chip">${h(face.category)}</span>`:`<span class="oj-device-text-card"><strong>${h(face.name)}</strong><span class="dt-card-description">${h(face.description)}</span></span>`:'';
                if(node.querySelector('.dt-card-content').innerHTML!==content)node.querySelector('.dt-card-content').innerHTML=content;
                const owner=r.players.find(p=>p.id===card.owner);node.querySelector('.dt-card-owner').textContent=face?(owner?.name||''):'';
                node.setAttribute('aria-label',card.state==='matched'?`${index+1}번 찾은 카드`:face?`${index+1}번 ${face.kind==='photo'?face.alt:face.name} · ${owner?.name||''}`:`${index+1}번 카드 열기`);
                node.tabIndex=card.state==='matched'?-1:0;node.setAttribute('aria-hidden',String(card.state==='matched'));
            });
            g.app.querySelector('[data-dt-release]').disabled=!mine||mine.count!==1||blocked;
            g.app.querySelector('[data-bt-board-title]').textContent=`${b.team}팀 · 장치 카드 매칭`;
            g.app.querySelector('[data-bt-pressure]').textContent=`${b.stage}단계 · ${b.pairs}쌍 · ${b.size}명`;
            g.app.querySelector('[data-dt-progress]').textContent=`${b.stageCorrect} / ${b.pairs}`;
            this.inspect();this.layout();this.paintStatus();
        }
        paintStatus(){
            const g=this.game,r=g.room,b=r?.board,field=g.app.querySelector('[data-dt-event]');if(!field||!b)return;
            const event=b.event,player=r.players.find(p=>p.id===event?.player);
            const text=r.status==='paused'?'경기가 일시 정지되었습니다.':b.clearAt?`${b.stage}판 완료 · 다음 판 준비 중`:event?event.type==='match'?`${player?.name||''} · ${event.name} +${event.points}점`:`${player?.name||''} · 다른 짝 · -20점`:'';
            field.textContent=g.notice?.text||text;field.classList.toggle('bt-error',Boolean(g.notice?.error&&g.notice?.text));
        }
        inspect(){
            const g=this.game,b=g.room?.board,field=g.app.querySelector('[data-dt-inspector]');if(!field||!b)return;
            const card=b.cards.find(c=>c.id===this.selected&&c.face)||b.cards.find(c=>c.owner===g.room.me&&c.face)||b.cards.find(c=>c.face),f=card?.face;
            const html=f?`<div class="dt-inspector-picture"><img src="${h(f.image)}" alt="${h(f.alt)}"></div><div><span class="bt-muted">${h(f.category)}</span>${f.name?`<h4>${h(f.name)}</h4><p>${h(f.description)}</p>`:'<h4>장치 사진</h4>'}<a href="${h(f.sourceUrl)}" target="_blank" rel="noopener" class="bt-link">${h(f.author)} · ${h(f.license)}</a></div>`:'<div class="dt-waiting"><strong>사진 ↔ 이름·설명</strong><span>열린 카드가 없습니다</span></div>';
            if(field.innerHTML!==html)field.innerHTML=html;
        }
        layout(){
            const deck=this.game.app.querySelector('.dt-deck'),b=this.game.room?.board;if(!deck||!b||!deck.clientWidth)return;
            const count=b.cards.length,mobile=window.matchMedia('(max-width:700px)').matches,style=getComputedStyle(deck),gap=parseFloat(style.columnGap)||0;
            const width=deck.clientWidth-parseFloat(style.paddingLeft)-parseFloat(style.paddingRight);
            const view=parseFloat(getComputedStyle(this.game.root).getPropertyValue('--eg-view-height'))||650;
            const area=this.game.root.closest('.eg-focus-area');let space=view-(parseFloat(style.getPropertyValue('--dt-budget'))||330);
            if(this.area!==area){if(this.area)this.observer.unobserve(this.area);this.area=area;if(area)this.observer.observe(area);}
            if(area){
                const footer=[this.game.app.querySelector('.dt-inspector'),this.game.app.querySelector('.dt-actions')].reduce((height,node)=>{const css=getComputedStyle(node);return height+node.getBoundingClientRect().height+parseFloat(css.marginTop)+parseFloat(css.marginBottom);},0);
                space=area.getBoundingClientRect().bottom-parseFloat(getComputedStyle(area).paddingBottom)-deck.getBoundingClientRect().top-footer-2;
            }
            const pixels=Math.max(0,Math.floor(space))+'px';if(deck.style.getPropertyValue('--dt-space')!==pixels)deck.style.setProperty('--dt-space',pixels);
            const available=space-parseFloat(style.paddingTop)-parseFloat(style.paddingBottom),minimum=mobile?40:36,maximum=mobile?128:150;
            const options=Array.from({length:13},(_,i)=>i+4).filter(n=>count%n===0&&count/n<=8&&(width-(n-1)*gap)/n>=36);
            const score=n=>{const rows=count/n,height=Math.max(minimum,Math.min(maximum,(available-(rows-1)*gap)/rows));return Math.abs(Math.log(((width-(n-1)*gap)/n)/height/(mobile?1.1:1.4)));};
            options.sort((a,c)=>score(a)-score(c));const columns=options[0]||8,rows=count/columns;
            if(deck.style.getPropertyValue('--dt-columns')!==String(columns))deck.style.setProperty('--dt-columns',String(columns));
            if(deck.style.getPropertyValue('--dt-rows')!==String(rows))deck.style.setProperty('--dt-rows',String(rows));
            const height=(deck.clientHeight-parseFloat(style.paddingTop)-parseFloat(style.paddingBottom)-(rows-1)*gap)/rows;
            this.game.root.dataset.dtDense=height<80?'true':'false';
            this.game.root.dataset.dtReadable=height>=124&&(width-(columns-1)*gap)/columns>=128?'true':'false';
        }
    }
    window.EduDeviceTeamBoard=DeviceTeamBoard;
})();
