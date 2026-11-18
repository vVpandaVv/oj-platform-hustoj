(function(root,factory){
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;else root.EduQuickPlay=api;
})(typeof globalThis==='object'?globalThis:this,function(){
  'use strict';
  const DURATION=45000;
  const clone=v=>JSON.parse(JSON.stringify(v));
  function next(s){let x=s.rng||1;x^=x<<13;x^=x>>>17;x^=x<<5;s.rng=x>>>0;return s.rng/4294967296;}
  function shuffle(s,a){a=a.slice();for(let i=a.length-1;i>0;i--){const j=Math.floor(next(s)*(i+1));[a[i],a[j]]=[a[j],a[i]];}return a;}
  const fields=[
    ['도서관 온도','°C',24,8,65],['세면대 온수','°C',42,14,78],
    ['버스 대기','분',18,0,48],['사진 파일 크기','KB',120,20,380],
    ['줄넘기 연습','회',80,12,230],['하루 대출 도서','권',16,0,44]
  ];
  function lane(s,index,first=false){
    const n=first?index:Math.floor(next(s)*fields.length),[name,unit,base,low,high]=fields[n];
    const peers=shuffle(s,[-2,-1,1,2]).map(v=>base+v);
    const small=first?index===1:next(s)<.5;
    const unusual=small?low:high;
    const values=first?(index===0?[24,23,65,25,22]:[42,43,41,14,44]):shuffle(s,peers.concat(unusual));
    return{id:++s.nextId,name,unit,values,target:values.indexOf(unusual),peers:values.filter(v=>v!==unusual),wrong:[],readyAt:0,selected:-1};
  }
  function token(s,type){return{id:++s.nextId,type:type||['A','B','C'][Math.floor(next(s)*3)]};}
  function exposed(s,side){return side==='stack'?s.stack[s.stack.length-1]:s.queue[0];}
  function canTransfer(s,side){return Boolean(exposed(s,side))&&(side==='stack'?s.queue.length:s.stack.length)<4;}
  function transfer(s,side){const t=side==='stack'?s.stack.pop():s.queue.shift();if(side==='stack')s.queue.push(t);else s.stack.push(t);return t;}
  function order(s,first=false){
    if(first){s.order=['B','A','C'];s.orderAt=0;return;}
    const test={stack:clone(s.stack),queue:clone(s.queue)},wanted=[];
    // Generate each order from a legal sequence, including at least one immediate shipment.
    for(let i=0;i<3;i++){
      if(i>0)for(let j=0,n=Math.floor(next(s)*4);j<n;j++){
        const side=next(s)<.5?'stack':'queue';if(canTransfer(test,side))transfer(test,side);
      }
      let side=next(s)<.5?'stack':'queue';if(!exposed(test,side))side=side==='stack'?'queue':'stack';
      wanted.push(exposed(test,side).type);if(side==='stack')test.stack.pop();else test.queue.shift();
    }
    s.order=wanted;s.orderAt=0;
  }
  function event(s,kind,delta,reason,extra={}){s.event={id:++s.eventId,kind,delta,reason,...extra};}
  function start(s,now){if(s.phase==='ready'){s.phase='running';s.deadline=now+DURATION;}}
  function advance(s,now){
    if(s.phase==='running'&&now>=s.deadline){s.phase='done';return;}
    if(s.game==='outlier'&&s.phase!=='done')s.lanes.forEach((p,i)=>{if(p.readyAt&&now>=p.readyAt)s.lanes[i]=lane(s,i);});
  }
  function create(game,seed=31){
    if(!['outlier','cargo'].includes(game))throw Error('Unknown game');
    const s={game,seed,rng:seed>>>0||1,phase:'ready',deadline:0,duration:DURATION,good:0,bad:0,nextId:0,eventId:0,event:null};
    for(let i=0;i<8;i++)next(s);
    if(game==='outlier'){s.lanes=[lane(s,0,true),lane(s,1,true)];s.review=[];}
    else{s.stack=['A','B','C'].map(t=>token(s,t));s.queue=['B','C','A'].map(t=>token(s,t));s.delivered=0;s.ordersDone=0;order(s,true);}
    return s;
  }
  function remaining(s,now){return s.phase==='ready'?DURATION:s.phase==='done'?0:Math.max(0,s.deadline-now);}
  function tick(state,now){
    const needs=state.phase==='running'&&now>=state.deadline||state.game==='outlier'&&state.phase!=='done'&&state.lanes.some(p=>p.readyAt&&now>=p.readyAt);
    if(!needs)return state;const s=clone(state);advance(s,now);return s;
  }
  function step(state,a,now){
    let s=clone(state);advance(s,now);if(s.phase==='done')return s;
    if(s.game==='cargo'&&!['stack','queue'].includes(a.side))return s;
    if(s.game==='outlier'&&a.type==='pick'){
      const p=s.lanes[a.lane];
      if(!p||p.id!==a.id||p.readyAt||!Number.isInteger(a.index)||a.index<0||a.index>=p.values.length||p.wrong.includes(a.index))return s;
      start(s,now);const v=p.values[a.index],range=`${Math.min(...p.peers)}~${Math.max(...p.peers)}`;
      if(a.index===p.target){s.good++;p.selected=a.index;p.readyAt=now+280;s.review.unshift({name:p.name,value:v});s.review=s.review.slice(0,4);event(s,'good',10,`${v}: 다른 네 값 ${range}에서 멀리 떨어짐`,{lane:a.lane,index:a.index,value:v});}
      else{s.bad++;p.wrong.push(a.index);event(s,'bad',-5,`${v}: 다른 값들과 비슷한 크기`,{lane:a.lane,index:a.index,value:v});}
    }else if(s.game==='cargo'&&a.type==='ship'){
      const t=exposed(s,a.side);if(!t||t.id!==a.id)return s;start(s,now);
      const expected=s.order[s.orderAt];
      if(t.type!==expected){s.bad++;event(s,'bad',-5,`지금은 ${expected} 차례 · ${t.type}은 그대로`,{side:a.side,value:t.type});}
      else{
        if(a.side==='stack')s.stack.pop();else s.queue.shift();s.good++;s.delivered++;s.orderAt++;
        event(s,'good',10,`${t.type} 출고 완료`,{side:a.side,value:t.type});
        if(s.orderAt===3){s.ordersDone++;for(let i=0;i<3;i++){let side=next(s)<.5?'stack':'queue';if(s[side].length>=4)side=side==='stack'?'queue':'stack';s[side].push(token(s));}order(s);}
      }
    }else if(s.game==='cargo'&&a.type==='transfer'){
      const t=exposed(s,a.side);if(!t||t.id!==a.id||!canTransfer(s,a.side))return s;start(s,now);transfer(s,a.side);
      event(s,'move',0,`${t.type}: ${a.side==='stack'?'쌓인 상자 위 → 줄 맨 뒤':'줄 맨 앞 → 쌓인 상자 위'}`,{side:a.side,value:t.type});
    }
    return s;
  }
  function info(s){return{score:s.good*10-s.bad*5,good:s.good,bad:s.bad,formula:`${s.good} × 10 - ${s.bad} × 5 = ${s.good*10-s.bad*5}`};}
  return{create,step,tick,remaining,info,exposed,canTransfer,DURATION};
});
