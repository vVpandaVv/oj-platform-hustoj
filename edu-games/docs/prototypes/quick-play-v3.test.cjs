const assert=require('node:assert/strict'),E=require('./quick-play-v3.js');
let checks=0;
function check(v,label){assert(v,label);checks++;}
function solveOrder(s){
 const seen=new Set(),queue=[{s,path:[]}],goal=s.ordersDone;
 for(let n=0;n<queue.length;n++){
  const {s:p,path}=queue[n];if(p.ordersDone>goal)return path;
  const key=JSON.stringify([p.stack.map(t=>t.type),p.queue.map(t=>t.type),p.orderAt]);if(seen.has(key))continue;seen.add(key);
  for(const side of ['stack','queue']){
   const t=E.exposed(p,side);if(!t)continue;
   if(t.type===p.order[p.orderAt]){const a={type:'ship',side,id:t.id};queue.push({s:E.step(p,a,100),path:path.concat(a)});}
   if(E.canTransfer(p,side)){const a={type:'transfer',side,id:t.id};queue.push({s:E.step(p,a,100),path:path.concat(a)});}
  }
 }
 return null;
}
let waves=0,low=0,high=0,orders=0;
for(let seed=1;seed<=30;seed++){
 let s=E.create('outlier',seed),now=0;
 check(s.phase==='ready'&&E.remaining(s,99999)===45000,'clock waits for the first action');
 for(let n=0;n<12;n++){
  for(let lane=0;lane<2;lane++){
   const p=s.lanes[lane],value=p.values[p.target];
   check(p.peers.length===4&&p.values.length===5,'four comparison values and one target');
   check(value<Math.min(...p.peers)||value>Math.max(...p.peers),'outlier is outside comparison group');
   if(value<Math.min(...p.peers))low++;else high++;waves++;
   const before=E.info(s).score,a={type:'pick',lane,id:p.id,index:p.target};
   s=E.step(s,a,now);check(E.info(s).score===before+10,'correct pick adds exactly ten');
   s=E.step(s,a,now);check(E.info(s).score===before+10,'same target cannot award twice');
  }
  now+=300;s=E.tick(s,now);
 }
 check(s.review.length===4,'review tray bounded');
 s=E.tick(s,45000);check(s.phase==='done','session ends at deadline');
 const frozen=E.info(s).score;s=E.step(s,{type:'pick',lane:0,id:s.lanes[0].id,index:s.lanes[0].target},45000);
 check(E.info(s).score===frozen,'no score after deadline');
 let c=E.create('cargo',seed);
 for(let n=0;n<12;n++){
  const path=solveOrder(c);check(path&&path.length>0,'generated order is solvable from live storage');
  for(const a of path){const before=E.info(c).score;c=E.step(c,a,100);check(E.info(c).score===before+(a.type==='ship'?10:0),'shipping ten, transfer zero');check(c.stack.length<=4&&c.queue.length<=4,'storage capacity');check(c.stack.length+c.queue.length+c.good%3===6,'cargo conserved apart from automatic refill');check(E.info(c).score===c.good*10-c.bad*5,'transparent score equation');}
  orders++;
 }
}
let s=E.create('outlier',9),p=s.lanes[1],max=p.values.indexOf(Math.max(...p.values));s=E.step(s,{type:'pick',lane:1,id:p.id,index:max},0);
check(E.info(s).score===-5,'always choosing the highest value is wrong for low outliers');
s=E.step(s,{type:'pick',lane:1,id:p.id,index:max},0);check(E.info(s).score===-5,'one mistaken mark cannot be penalized twice');
let c=E.create('cargo',9),t=E.exposed(c,'stack');c=E.step(c,{type:'ship',side:'stack',id:t.id},0);check(c.bad===1&&E.exposed(c,'stack').id===t.id,'wrong shipment costs five and keeps the box');
c=E.step(c,{type:'transfer',side:'stack',id:t.id},1);check(E.info(c).score===-5,'moving does not hide any score bonus');
const score=E.info(c).score;c=E.tick(c,45000);c=E.step(c,{type:'ship',side:'queue',id:E.exposed(c,'queue').id},45001);check(c.phase==='done'&&E.info(c).score===score,'expired cargo input cannot score');
check(low>0&&high>0,'both low and high outliers present');
check(JSON.stringify({outlier:E.create('outlier'),cargo:E.create('cargo')}).length<16000,'host state remains small');
console.log(JSON.stringify({checks,waves,low,high,orders,scoreRule:'good * 10 - bad * 5'}));
