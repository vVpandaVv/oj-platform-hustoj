const assert = require('node:assert/strict');
const E = require('./boardgame-lab-v2.js');
let checks = 0;
const check = (v,label) => { assert(v,label);checks++; };
const step = (g,s,a) => E.move(g,s,a);
function marketCanWin(s) {
  return [[0,1],[0,2],[1,2]].some(pair=>{
    const need=[2,3,4].map(c=>pair.reduce((n,i)=>n+s.paintings[i].mask.filter(j=>s.paintings[i].target[j]===c).length,0)),seen=new Set();
    function search(turn,at,market,need) {
      if(need.every(v=>v<=0))return true;
      if(turn===10||need.reduce((a,b)=>a+b,0)>(10-turn)*4)return false;
      const key=[turn,at,...market,...need].join(',');if(seen.has(key))return false;seen.add(key);
      const choices=[0,1,2].sort((a,b)=>Math.min(need[s.deck[market[b]].color-2],s.deck[market[b]].count)-Math.min(need[s.deck[market[a]].color-2],s.deck[market[a]].count));
      for(const i of choices){const p=s.deck[market[i]],next=need.slice(),m=market.slice();next[p.color-2]=Math.max(0,next[p.color-2]-p.count);m[i]=at;if(search(turn+1,at+1,m,next))return true;}
      return search(turn+1,at+3,[at,at+1,at+2],need);
    }
    return search(0,3,[0,1,2],need);
  });
}
function fill(s) {
  for (;;) {
    const options = [];
    s.paintings.forEach((p,i)=>{
      const missing=p.mask.filter(j=>p.board[j]!==p.target[j]);
      const can=missing.filter(j=>s.stock[p.target[j]]>0);
      if(can.length)options.push({i,j:can[0],missing:missing.length});
    });
    if(!options.length)return s;
    options.sort((a,b)=>a.missing-b.missing);
    const {i,j}=options[0];s=step('market',s,{type:'select',index:i});s=step('market',s,{type:'color',color:s.paintings[i].target[j]});s=step('market',s,{type:'paint',index:j});
  }
}
function playMarket(seed,smart) {
  let s=E.create('market',seed);
  for(let n=0;n<10;n++) {
    s=fill(s);
    let selected=0;
    if(smart){
      let best=-Infinity;
      for(let i=0;i<3;i++) {
        const next=fill(step('market',s,{type:'take',index:i}));
        const value=E.info('market',next).score;
        if(value>best){best=value;selected=i;}
      }
    }
    s=step('market',s,{type:'take',index:selected});
    check(s.stock.reduce((a,b)=>a+b,0)<=6,'paint storage capacity');
  }
  s=fill(s);s=step('market',s,{type:'finish'});check(s.done,'market ends');return E.info('market',s);
}
function playRace(seed,smart) {
  let s=E.create('race',seed);
  for(let n=0;n<8;n++) {
    const original=JSON.stringify(s);
    const plan=smart?E.botPlan({...E.copy(s),bot:E.copy(s.you)}):null;
    check(JSON.stringify(s)===original,'planning cannot mutate game');
    for(let j=0;j<3;j++) {
      const p=E.preview(s).player;
      const op=smart?plan[j]:['popS','popQ','pushS','return','pushQ'].find(op=>E.legal(p,op));
      if(op)s=step('race',s,{type:'plan',op});
    }
    const previous=E.copy(s);s=step('race',s,{type:'resolve'});
    for(const actor of ['you','bot']) {
      const count=p=>p.stack.length+p.queue.length+p.feed.length+p.out.length+p.claimed.length*2;
      check(count(s[actor])-count(previous[actor])>=0&&count(s[actor])-count(previous[actor])<=1,'crates conserved apart from one arrival');
      check(s[actor].out.length<=2&&s[actor].stack.length<=4&&s[actor].queue.length<=4,'storage boundaries');
    }
    check(new Set(s.orders.map(o=>o.key)).size===3,'public orders are distinct');
  }
  check(s.done&&s.round===8,'race ends after eight rounds');
  const unchanged=step('race',s,{type:'resolve'});check(unchanged===s,'finished result cannot score again');return E.info('race',s);
}
function playNight(seed,policy) {
  let s=E.create('night',seed);
  for(let night=0;night<4;night++) {
    for(let i=0;i<3;i++) {
      s=step('night',s,{type:'select',index:i});
      const value=E.reading(s,i),context=E.sourceNote(s,i).context;
      if(policy==='all') {s=step('night',s,{type:'measure'});continue;}
      if(policy==='discard'&&(value<22||value>26)){s=step('night',s,{type:'hold'});continue;}
      if(policy!=='evidence'||context.includes('실제 고온')||context.includes('조리실')||(value>=22&&value<=26))continue;
      s=step('night',s,{type:'inspect'});
      const note=E.sourceNote(s,i).metadata;
      s=step('night',s,{type:note.includes('°F')?'unit':note.includes('반복 전송')?'restart':'measure'});
    }
    s=step('night',s,{type:'close'});
    check(s.budget>=0,'night budget never negative');
  }
  check(s.done&&s.history.length===4,'night session ends');
  check(s.accepted+s.bad<=12,'no duplicate data scoring');
  check(step('night',s,{type:'close'})===s,'no repeated final submission');
  return {...E.info('night',s),accepted:s.accepted,bad:s.bad,eventMissed:s.eventMissed,budget:s.budget};
}
const summary={market:{first:[],adaptive:[]},race:{fixed:[],planned:[]},night:{all:[],evidence:[],discard:[]}};
for(let seed=24;seed<54;seed++) {
  for(const g of ['market','race','night'])assert.deepEqual(E.create(g,seed),E.create(g,seed));
  check(marketCanWin(E.create('market',seed)),'tested market board has a winning resource path');
  summary.market.first.push(playMarket(seed,false));summary.market.adaptive.push(playMarket(seed,true));
  summary.race.fixed.push(playRace(seed,false));summary.race.planned.push(playRace(seed,true));
  for(const policy of ['all','evidence','discard'])summary.night[policy].push(playNight(seed,policy));
}
function aggregate(a){return{runs:a.length,averageScore:+(a.reduce((n,p)=>n+p.score,0)/a.length).toFixed(2),wins:a.filter(p=>p.win).length,min:Math.min(...a.map(p=>p.score)),max:Math.max(...a.map(p=>p.score))};}
const report=Object.fromEntries(Object.entries(summary).map(([g,policies])=>[g,Object.fromEntries(Object.entries(policies).map(([k,a])=>[k,aggregate(a)]))]));
check(report.market.adaptive.averageScore>report.market.first.averageScore,'market choices must matter');
check(report.race.planned.averageScore>report.race.fixed.averageScore,'order planning must matter');
check(report.night.evidence.averageScore>report.night.all.averageScore,'measure everything cannot be the best policy');
check(report.night.all.wins<report.night.evidence.wins,'checking arbitrary sources must not always pass');
check(report.night.discard.wins===0,'discarding all extremes loses real heat alerts');
const restored=JSON.stringify({revision:2,game:'market',states:Object.fromEntries(['market','race','night'].map(g=>[g,E.create(g,24)])),best:{}});
check(Buffer.byteLength(restored)<16384,'snapshot fits host state');
let stuck=E.create('market',24);stuck.stock=[0,0,6,0,0];stuck.color=2;stuck=E.move('market',stuck,{type:'discard'});check(stuck.stock[2]===0&&stuck.waste===6,'unneeded paint cannot deadlock storage');
let n=E.create('night',24);n=E.move('night',n,{type:'select',index:n.culprit});n=E.move('night',n,{type:'measure'});check(E.reading(n,n.culprit)===n.world[0][n.culprit].truth,'measurement replaces current value');n=E.move('night',n,{type:'close'});check(E.reading(n,n.culprit)!==n.world[1][n.culprit].truth,'measurement cannot repair the next night');
console.log(JSON.stringify({checks,stateBytes:Buffer.byteLength(restored),report},null,2));
