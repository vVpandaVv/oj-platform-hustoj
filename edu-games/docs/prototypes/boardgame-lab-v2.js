(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.EduBoardLab = api;
})(typeof globalThis === 'object' ? globalThis : this, function () {
  'use strict';
  const copy = v => JSON.parse(JSON.stringify(v));
  const sum = a => a.reduce((n, v) => n + v, 0);
  function random(seed) {
    let x = (seed >>> 0) || 1;
    const next = () => { x ^= x << 13; x ^= x >>> 17; x ^= x << 5; return (x >>> 0) / 4294967296; };
    for (let i=0; i<8; i++) next();
    return next;
  }
  function shuffle(a, r) {
    a = a.slice();
    for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(r() * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; }
    return a;
  }
  const patterns = [
    { name: '로켓', rows: ['00011000','00122100','01233210','01233210','01222210','12222221','00144100','00044000'], needs: [5,2,1] },
    { name: '물고기', rows: ['00011100','00133310','10131431','11333331','10133310','00133210','00122100','00011000'], needs: [2,5,1] },
    { name: '꽃', rows: ['00444400','04444440','04422440','00444400','00033000','00333000','03333000','00033000'], needs: [1,2,5] }
  ];
  function createMarket(seed) {
    const r = random(seed);
    const paintings = patterns.map(p => {
      const target = p.rows.join('').split('').map(Number), mask = [];
      p.needs.forEach((n, k) => mask.push(...shuffle(target.map((c,i) => c === k + 2 ? i : -1).filter(i => i >= 0), r).slice(0,n)));
      const board = target.slice(); mask.forEach(i => { board[i] = null; });
      return { name: p.name, target, mask, board };
    });
    const deck = shuffle(Array.from({length:45},(_,i) => ({color:2+i%3,count:2+Math.floor(r()*3)})),r);
    return { seed, round:0, maxRounds:10, paintings, market:deck.slice(0,3), deck, deckAt:3, stock:[0,0,0,0,0], selected:0, color:2, waste:0, undo:null, done:false, last:'그림 두 장 완성 · 남은 물감은 여섯 방울까지' };
  }
  function marketInfo(s) {
    const completed = s.paintings.filter(p => p.mask.every(i => p.board[i] === p.target[i])).length;
    const correct = sum(s.paintings.map(p => p.mask.filter(i => p.board[i] === p.target[i]).length));
    return { completed, correct, score: completed * 10 + correct - s.waste, win:completed >= 2 };
  }
  function marketMove(s, a) {
    if (a.type === 'select' && s.paintings[a.index]) s.selected = a.index;
    else if (a.type === 'color' && a.color >= 2 && a.color <= 4) s.color = a.color;
    else if (a.type === 'take' && s.round < s.maxRounds && s.market[a.index]) {
      const p = s.market[a.index], n = Math.min(p.count, 6-sum(s.stock));
      s.stock[p.color] += n; s.waste += p.count - n; s.round++; s.undo = null;
      s.market[a.index] = s.deck[s.deckAt++]; s.color = p.color;
      s.last = `물감 ${n}방울 확보${n < p.count ? ` · 넘친 ${p.count-n}방울은 버림` : ''}`;
    } else if (a.type === 'refresh' && s.round < s.maxRounds) {
      s.market = s.deck.slice(s.deckAt, s.deckAt+3); s.deckAt += 3; s.round++; s.undo = null;
      s.last = '한 차례를 써서 물감을 모두 새로 받았습니다';
    } else if (a.type === 'discard' && s.stock[s.color]>0) {
      const n=s.stock[s.color];s.stock[s.color]=0;s.waste+=n;s.undo=null;
      s.last=`${s.color}번 물감 ${n}방울 비움 · 보관 공간 확보`;
    } else if (a.type === 'paint') {
      const p = s.paintings[s.selected], i = a.index;
      if (!p.mask.includes(i) || !s.stock[s.color] || p.board[i] === s.color) return s;
      s.undo = { painting:s.selected, index:i, before:p.board[i], color:s.color };
      p.board[i] = s.color; s.stock[s.color]--;
      const complete = p.mask.every(j => p.board[j] === p.target[j]);
      s.last = complete ? `${p.name} 완성 · +10점` : p.target[i] === s.color ? '행의 숫자와 색이 맞았습니다' : '이 칸의 색이 행 코드와 다릅니다';
    } else if (a.type === 'undo' && s.undo) {
      const u = s.undo; s.paintings[u.painting].board[u.index] = u.before; s.stock[u.color]++; s.undo = null;
      s.last = '마지막 붓질 취소 · 물감 회수';
    } else if (a.type === 'finish' && (s.round >= s.maxRounds || marketInfo(s).completed >= 2)) {
      s.done = true; s.last = marketInfo(s).win ? '그림 전시 성공' : '이번 전시는 미완성 · 같은 물감으로 다시 도전';
    }
    return s;
  }

  const ops = ['pushS','pushQ','popS','popQ','return'];
  const opNames = {pushS:'위로 넣기',pushQ:'뒤로 넣기',popS:'위에서 꺼내기',popQ:'앞에서 꺼내기',return:'출고 취소'};
  const pairKeys = ['AA','AB','AC','BA','BB','BC','CA','CB','CC'];
  function freshOrder(s) {
    for (let tries = 0; tries < 60; tries++) {
      const c = s.orderDeck[s.deckAt++ % s.orderDeck.length];
      if (!s.orders.some(p => p && p.key === c.key)) return copy(c);
    }
    const key = pairKeys.find(k => !s.orders.some(p => p && p.key === k));
    return {key,points:3};
  }
  function legal(p, op) {
    return op === 'pushS' ? p.feed.length > 0 && p.stack.length < 4
      : op === 'pushQ' ? p.feed.length > 0 && p.queue.length < 4
      : op === 'popS' ? p.stack.length > 0 && p.out.length < 2
      : op === 'popQ' ? p.queue.length > 0 && p.out.length < 2
      : op === 'return' && p.out.length > 0;
  }
  function applyOrder(p, op, orders, refill) {
    if (!legal(p, op)) return {ok:false,claimed:null};
    if (op === 'pushS') p.stack.push(p.feed.shift());
    if (op === 'pushQ') p.queue.push(p.feed.shift());
    if (op === 'popS') p.out.push(p.stack.pop());
    if (op === 'popQ') p.out.push(p.queue.shift());
    if (op === 'return') p.feed.push(...p.out.splice(0));
    const i = orders.findIndex(c => c && c.key === p.out.join(''));
    if (i < 0) return {ok:true,claimed:null};
    const c = orders[i]; p.score += c.points; p.claimed.push(c); p.out = [];
    orders[i] = null; if (refill) orders[i] = refill();
    return {ok:true,claimed:c};
  }
  function preview(s, plan = s.plan, actor = 'you') {
    const p = copy(s[actor]), orders = copy(s.orders), valid = [];
    plan.forEach(op => valid.push(applyOrder(p,op,orders,null).ok));
    return {player:p,orders,valid};
  }
  function potential(p, orders) {
    let best = 0;
    for (const c of orders.filter(Boolean)) {
      let n = c.key.startsWith(p.out.join('')) ? p.out.length * 4 : -8;
      const top = p.stack[p.stack.length-1], front = p.queue[0], want = c.key[p.out.length];
      if (want && (top === want || front === want)) n += 4;
      best = Math.max(best,n);
    }
    return best - (p.out.length === 2 ? 12 : 0);
  }
  function botPlan(s) {
    let best = -Infinity, plan = [];
    function visit(p, orders, path, useful) {
      if (path.length === 3) {
        const value = (p.score-s.bot.score)*100 + potential(p,orders) + useful*.05;
        if (value > best) { best = value; plan = path; }
        return;
      }
      for (const op of ops) {
        if (!legal(p,op)) continue;
        const q = copy(p), next = copy(orders); applyOrder(q,op,next,null);
        visit(q,next,path.concat(op),useful+1);
      }
    }
    visit(copy(s.bot),copy(s.orders),[],0);
    return plan;
  }
  function createRace(seed) {
    const r = random(seed), pawn = () => ({stack:['A','B'],queue:['C'],feed:['A','C','B'],out:[],score:0,claimed:[]});
    const orderDeck = shuffle(Array.from({length:54},(_,i)=>({key:pairKeys[i%9],points:3+(i%3)})),r);
    const s = {seed,round:0,maxRounds:8,you:pawn(),bot:pawn(),orders:[{key:'BA',points:3},{key:'AC',points:4},{key:'CB',points:5}],orderDeck,deckAt:0,plan:[],botPlan:[],trace:[],supply:Array.from({length:8},()=>['A','B','C'][Math.floor(r()*3)]),done:false,last:'주문은 공용 · 먼저 꺼낸 쪽이 가져갑니다'};
    s.botPlan = botPlan(s); return s;
  }
  function raceInfo(s) {
    return {score:s.you.score,other:s.bot.score,result:s.you.score > s.bot.score ? 'win' : s.you.score === s.bot.score ? 'draw' : 'lose',win:s.you.score>s.bot.score};
  }
  function raceMove(s,a,onStep) {
    if (a.type === 'plan' && ops.includes(a.op) && s.plan.length < 3) {
      const p = preview(s).player;
      if (legal(p,a.op)) { s.plan.push(a.op); s.last = '예약만으로는 물건이 움직이지 않습니다'; }
    } else if (a.type === 'undo') { s.plan.pop(); s.last = '마지막 예약 취소'; }
    else if (a.type === 'resolve' && s.plan.length) {
      s.trace = [];
      const first = s.round % 2 ? 'bot' : 'you', actors = first === 'you' ? ['you','bot'] : ['bot','you'];
      for (let step = 0; step < 3; step++) for (const actor of actors) {
        const op = (actor === 'you' ? s.plan : s.botPlan)[step];
        if (!op) continue;
        const result = applyOrder(s[actor],op,s.orders,()=>freshOrder(s));
        s.trace.push({actor,op,ok:result.ok,claimed:result.claimed});
        if (typeof onStep==='function') onStep(copy({you:s.you,bot:s.bot,orders:s.orders,trace:s.trace,step,actor}));
      }
      const c = s.supply[s.round]; for (const who of ['you','bot']) if (s[who].feed.length < 7) s[who].feed.push(c);
      s.round++; s.plan = []; s.done = s.round >= s.maxRounds;
      if (!s.done) s.botPlan = botPlan(s);
      const claims = s.trace.filter(t=>t.claimed).map(t=>`${t.actor==='you'?'나':'로봇'} ${t.claimed.key} +${t.claimed.points}`);
      s.last = claims.length ? claims.join(' · ') : '이번에는 주문 선점 없음 · 남은 물건으로 다음 계획';
    }
    return s;
  }

  function createNight(seed) {
    const r = random(seed), kind = ((seed % 6)+6)%6, culprit = Math.floor(r()*3);
    const names = ['도서관','체육관','운동장'];
    if (kind === 3) names[culprit] = '급식 조리실';
    const world = Array.from({length:4},(_,n)=>Array.from({length:3},(_,i)=>{
      let truth = 22+Math.floor(r()*5), fault = '', event = false;
      if (kind === 3 && i === culprit) truth = 42+Math.floor(r()*4);
      if (n === 2 && i === (culprit+1)%3) {truth = 38;event = true;}
      if ((kind === 0 || kind === 5) && i === culprit) fault = 'unit';
      if (kind === 1 && i === culprit) fault = 'stuck';
      if (kind === 2 && i === culprit && n === 1) fault = 'spike';
      if (kind === 4 && i === culprit && (n === 0 || n === 3)) fault = 'spike';
      if (kind === 5 && i === (culprit+2)%3 && n === 1) fault = 'spike';
      const raw = fault === 'unit' ? Math.round((truth*1.8+32)*10)/10 : fault === 'stuck' ? 42 : fault === 'spike' ? truth+40 : truth;
      return {truth,raw,fault,event};
    }));
    return {seed,kind,culprit,names,world,night:0,maxNights:4,budget:6,selected:0,fixes:[null,null,null],checked:[null,null,null],inspected:[false,false,false],held:[false,false,false],accepted:0,bad:0,eventKept:0,eventMissed:0,history:[],done:false,last:'높은 값도 진짜일 수 있습니다 · 확인 도구는 여섯 개'};
  }
  function reading(s,i) {
    if (s.checked[i] !== null) return s.checked[i];
    const d = s.world[s.night][i];
    if (s.fixes[i] === 'unit') return Math.round((d.raw-32)/1.8*10)/10;
    if (s.fixes[i] === 'restart' && d.fault === 'stuck') return d.truth;
    return d.raw;
  }
  function sourceNote(s,i) {
    const d = s.world[s.night][i];
    const context = s.kind === 3 && i === s.culprit ? '조리실 평소 40~47°C' : d.event ? '옆 장비도 38°C · 실제 고온' : '같은 장소의 평소 값 22~26°C';
    const metadata = !s.inspected[i] ? '원본 설정 미확인' : d.fault === 'unit' ? '단위: °F · 설정이 계속 유지됨' : d.fault === 'stuck' ? '센서: 같은 42를 반복 전송 중' : d.fault === 'spike' ? '기록: 이번 전송에만 잡음' : '단위: °C · 장비 정상';
    return {context,metadata};
  }
  function nightInfo(s) {
    return {score:s.accepted*3-s.bad*4+s.eventKept*2+s.budget,win:s.accepted>=11&&s.eventMissed===0};
  }
  function nightMove(s,a) {
    const i = s.selected;
    if (a.type === 'select' && a.index>=0 && a.index<3) s.selected = a.index;
    else if (a.type === 'hold') {s.held[i] = !s.held[i];s.last=s.held[i]?'이번 값만 보류 · 다음 밤 센서는 그대로':'측정값 사용으로 복귀';}
    else if (a.type === 'inspect' && s.budget>=1 && !s.inspected[i]) {s.budget--;s.inspected[i]=true;s.last=sourceNote(s,i).metadata;}
    else if (a.type === 'measure' && s.budget>=1 && s.checked[i]===null) {s.budget--;s.checked[i]=s.world[s.night][i].truth;s.last='독립 장비로 다시 측정 · 이번 밤만 대체';}
    else if ((a.type === 'unit' || a.type === 'restart') && s.budget>=2 && s.fixes[i]!==a.type) {s.budget-=2;s.fixes[i]=a.type;s.last=a.type==='unit'?'화씨→섭씨 변환 적용 · 다음 밤에도 유지':'센서 재시작 · 반복 전송 오류를 복구';}
    else if (a.type === 'close') {
      const results = s.world[s.night].map((d,j)=>{
        const used = !s.held[j], correct = Math.abs(reading(s,j)-d.truth)<.2;
        if (used && correct) s.accepted++;
        if (used && !correct) s.bad++;
        if (d.event) {if(used&&correct)s.eventKept++;else s.eventMissed++;}
        return {name:s.names[j],value:reading(s,j),truth:d.truth,used,correct,event:d.event};
      });
      s.history.push(results);s.night++;
      s.last = `${results.filter(d=>d.used&&d.correct).length}/3개 정확 · ${results.filter(d=>d.used&&!d.correct).length}개 잘못 사용`;
      s.checked=[null,null,null];s.held=[false,false,false];s.done=s.night>=s.maxNights;
    }
    return s;
  }
  function create(game,seed=17) {
    return game==='market'?createMarket(seed):game==='race'?createRace(seed):createNight(seed);
  }
  function move(game,state,action,onStep) {
    if (state.done && !['select','color'].includes(action.type)) return state;
    const s = copy(state);
    return game==='market'?marketMove(s,action):game==='race'?raceMove(s,action,onStep):nightMove(s,action);
  }
  function info(game,s) {return game==='market'?marketInfo(s):game==='race'?raceInfo(s):nightInfo(s);}
  return {create,move,info,preview,legal,ops,opNames,reading,sourceNote,botPlan,copy};
});
