(()=>{'use strict';
const h=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const icons=new URL('../shell/icons.svg',document.currentScript.src).href;
const items={shuffle:['순서 교란',3],erase:['숫자 지우개',4],key:['만능열쇠',5],shield:['방어막',2],wrench:['복구 렌치',2]};
window.EduVaultTeamBoard=class {
 constructor(team){this.t=team;this.target='';this.shop='shuffle';this.host=null;this.board=null;
  team.root.addEventListener('click',e=>{const b=e.target.closest('[data-vt-item],[data-vt-buy]');if(!b||b.disabled)return;if(b.hasAttribute('data-vt-buy'))team.command({type:'buy',item:this.shop});else{const [targetTeam,targetVault]=this.target.split(':').map(Number);team.command({type:'item',item:b.dataset.vtItem,targetTeam,targetVault});}});
  team.root.addEventListener('change',e=>{if(e.target.matches('[data-vt-target]'))this.target=e.target.value;if(e.target.matches('[data-vt-shop]')){this.shop=e.target.value;this.syncItems();}});
  this.timer=setInterval(()=>{if(!team.root.isConnected){clearInterval(this.timer);return;}const r=team.room;if(r?.status==='playing'&&r.board?.active!==null&&r.board?.active!==undefined&&!team.pending)team.command({type:'touch',vault:r.board.active});},8000);
 }
 template(){return '<section class="vt-workspace"><div data-bt-board></div><details class="vt-inventory" open><summary>동전·아이템 <span data-vt-coins></span></summary><div class="vt-items" data-vt-items></div><div class="vt-targets"><label>공격 대상<select data-vt-target></select></label><details class="vt-shop"><summary>아이템 상점</summary><div><select data-vt-shop aria-label="구매 아이템"></select><button type="button" data-vt-buy>구매</button></div></details></div></details></section><p class="bt-board-status" data-bt-board-status role="status" aria-live="polite"></p>';}
 sync(){const t=this.t,r=t.room,b=r?.board,host=t.app.querySelector('[data-bt-board]');if(!host||!b)return;
  this.inventory=t.app.querySelector('.vt-inventory')||this.inventory;
  if(host!==this.host){this.host=host;this.board=new window.EduVaultBoard(host,{team:true,me:r.me,icons,codebook:r.codebook,command:move=>t.command(move),offset:()=>t.offset,readOnly:()=>!t.room?.myTeam||t.room?.watchTeam!==t.room?.myTeam,afterRender:node=>{if(this.inventory)node.querySelector('.ea-codebook').append(this.inventory);this.syncItems();}});}
  this.inventory?.remove();this.board.update({...b,status:r.status});if(this.inventory)host.querySelector('.ea-codebook').append(this.inventory);this.syncItems();const level=t.app.querySelector('[data-bt-level]');if(level){level.textContent=`${b.stageCorrect}/${b.pairs}`;level.previousElementSibling.textContent='금고';}const combo=t.app.querySelector('[data-bt-combo]');if(combo){combo.textContent=b.coins;combo.previousElementSibling.textContent='동전';}this.paintStatus();
 }
 syncItems(){const t=this.t,r=t.room,b=r?.board,area=t.app.querySelector('[data-vt-items]');if(!area||!b)return;const readonly=r.status!=='playing'||r.myTeam!==r.watchTeam||r.serverNow<r.startedAt;
  t.app.querySelector('[data-vt-coins]').textContent=`동전 ${b.coins}${b.shield?' · 방어막 ON':''}`;
  const target=t.app.querySelector('[data-vt-target]'),targets=r.targets||[];if(!targets.some(x=>`${x.team}:${x.vault}`===this.target))this.target=targets[0]?`${targets[0].team}:${targets[0].vault}`:'';
  if(document.activeElement!==target){target.innerHTML=targets.map(x=>`<option value="${x.team}:${x.vault}">${x.team}팀 · ${x.vault+1}번 금고</option>`).join('');target.value=this.target;}target.disabled=readonly||!targets.length;
  area.innerHTML=Object.entries(items).map(([id,[label]])=>`<button type="button" data-vt-item="${id}" ${readonly||!b.items[id]||(['shuffle','erase'].includes(id)&&!this.target)?'disabled':''} title="${id==='key'?'기본 금고 즉시 열기 · 50점':id==='wrench'?'우리 금고의 공격만 해제':id==='shield'?'다음 공격 1회 차단':id==='shuffle'?'상대 미작업 금고의 패킷 순서 교란':'상대 미작업 금고의 숫자 가리기'}"><span>${h(label)}</span><b>${b.items[id]}</b></button>`).join('');
  const shop=t.app.querySelector('[data-vt-shop]');if(document.activeElement!==shop){shop.innerHTML=Object.entries(items).map(([id,[label,cost]])=>`<option value="${id}">${label} · ${cost}동전</option>`).join('');shop.value=this.shop;}shop.disabled=readonly;t.app.querySelector('[data-vt-buy]').disabled=readonly||b.coins<items[this.shop][1];
 }
 paintStatus(){const t=this.t,field=t.app.querySelector('[data-bt-board-status]');if(!field)return;field.textContent=t.notice?.text||t.room?.board?.event?.text||'';field.classList.toggle('bt-error',!!t.notice?.error);}
};
})();
