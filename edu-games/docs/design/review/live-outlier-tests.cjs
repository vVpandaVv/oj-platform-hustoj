const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
function load(name){const fragment=fs.readFileSync(path.resolve(__dirname,'../prototypes',name),'utf8');assert(!fragment.startsWith('\uFEFF'));assert(!/\p{Script=Han}/u.test(fragment));const mod={exports:{}};new Function('module',fragment.match(/<script>([\s\S]*?)<\/script>/)[1])(mod);return mod.exports;}
const C=load('outlier-live-board.html'),old=load('outlier-context-playtest.html');
const checks=[];
function test(name,fn){checks.push({name,...fn()});}

test('old-two-slots-remain-a-pair',()=>{
 const s=old.fresh(47),a=s.cards.find(old.isOutlier),b=s.cards.find(c=>c.target===a.target&&c.id!==a.id),ia=s.cards.indexOf(a),ib=s.cards.indexOf(b);let samePair=0;
 for(let i=0;i<30;i++){const now=1+i*1000;assert.equal(s.cards[ia].target,s.cards[ib].target);samePair++;old.pick(s,s.cards[ia].id,now);old.pick(s,s.cards[ib].id,now+10);old.tick(s,now+500);}
 assert.equal(samePair,30);return{repeatedSlots:[ia,ib],samePairRounds:samePair,score:s.score,meaning:'Pair continuity, not a claim that every orientation was correct'};
});

test('same-observation-changes-decision',()=>{
 const s=C.fresh(71),ordinary=s.records.find(r=>r.value===45),unusual=s.records.find(r=>r.value>=72);const ordinaryId=ordinary.id,unusualId=unusual.id;
 assert(!C.isCandidate(s,ordinary));assert(C.isCandidate(s,unusual));C.start(s,0);C.tick(s,3200);assert(!C.isCandidate(s,s.records.find(r=>r.id===unusualId)));C.tick(s,6400);assert(C.isCandidate(s,s.records.find(r=>r.id===ordinaryId)));
 return{same45:'ordinary → candidate',sameHighValue:'candidate → ordinary',referenceActuallyChanged:true};
});

test('one-observation-one-score-and-no-fast-click-farming',()=>{
 const s=C.fresh(71),candidate=s.records.find(r=>C.isCandidate(s,r));C.pick(s,candidate.id,0);assert.equal(s.score,1);for(let n=0;n<100;n++)C.pick(s,candidate.id,n);assert.equal(s.score,1);C.tick(s,1600);if(s.records.some(r=>r.id===candidate.id)){C.pick(s,candidate.id,1600);assert.equal(s.score,1);}
 const ordinary=s.records.find(r=>!C.isCandidate(s,r)&&!r.claimed),lives=s.lives;C.pick(s,ordinary.id,1601);assert.equal(s.lives,lives-1);for(let n=0;n<50;n++)C.pick(s,ordinary.id,1601+n);assert.equal(s.lives,lives-1);
 return{repeatedCorrectClicks:100,score:1,repeatedWrongClicks:50,oneLossPerReferenceEpoch:true};
});

test('a-former-wrong-choice-can-become-correct-only-after-data-change',()=>{
 const s=C.fresh(71),r=s.records.find(v=>v.value===45);C.pick(s,r.id,0);assert.equal(s.score,0);assert.equal(s.lives,2);C.tick(s,6400);C.pick(s,r.id,6400);assert.equal(s.score,1);assert.equal(s.lives,2);return{reEvaluationUsesNewReference:true};
});

test('source-values-are-immutable-and-catches-preserve-context',()=>{
 const s=C.fresh(71),values=new Map(s.records.map(r=>[r.id,r.value])),r=s.records.find(v=>C.isCandidate(s,v)),ref=[...s.reference];C.pick(s,r.id,0);assert.deepEqual(s.reviews[0].reference,ref);C.tick(s,6400);for(const row of s.recordHistory)if(values.has(row.id))assert.equal(row.value,values.get(row.id));assert.deepEqual(s.reviews[0].reference,ref);assert(s.referenceHistory.length>8);return{originalValuesUnchanged:true,reviewSnapshotUnchanged:true};
});

test('pause-resume-and-real-end-conditions',()=>{
 const s=C.fresh(71);C.start(s,10);C.tick(s,1010);C.pause(s,1010);const t=s.elapsed;C.tick(s,50000);assert.equal(s.elapsed,t);C.start(s,50000);C.tick(s,50500);assert.equal(s.elapsed,t+500);
 const wrong=C.fresh(71),ordinary=wrong.records.filter(r=>!C.isCandidate(wrong,r));for(const r of ordinary.slice(0,3))C.pick(wrong,r.id,0);assert.equal(wrong.mode,'ended');assert.equal(wrong.lives,0);assert.equal(wrong.finishReason,'lives');
 const time=C.fresh(71);C.start(time,0);for(let at=0;at<=C.DURATION;at+=100){C.tick(time,at);if(time.mode==='ended')break;for(const r of time.records)if(!r.claimed&&C.isCandidate(time,r))C.pick(time,r.id,at);}assert.equal(time.mode,'ended');assert.equal(time.finishReason,'time');const score=time.score;C.pick(time,time.records[0].id,100000);assert.equal(time.score,score);assert.equal(time.best,score);
 return{pausedTimeExcluded:true,threeMistakesEnd:true,timeLimitEnd:true,resultStable:true,completedScore:score};
});

test('no-impossible-expiry-and-state-size-limit',()=>{
 for(let seed=1;seed<=30;seed++){const s=C.fresh(seed);C.start(s,0);for(let t=0;t<=C.DURATION;t+=200){C.tick(s,t);if(s.mode==='ended')break;for(const r of s.records)if(!r.claimed&&C.isCandidate(s,r))C.pick(s,r.id,t);assert(C.valid(s));assert(Buffer.byteLength(JSON.stringify({schema:1,state:s,serial:1}),'utf8')<16384);}assert.equal(s.missed,0);for(const e of s.reviews)if(e.kind==='miss')throw new Error('Immediate model should not miss');}
 const s=C.fresh(71);C.start(s,0);C.tick(s,1600);assert.equal(s.missed,0,'Initial oldest point had less than minimum response time');
 return{seeds:30,immediateReaderMisses:0,minimumOpportunityMs:C.EXPOSURE,privateStateUnder16KiB:true};
});

test('fixed-slot-and-maximum-value-policies',()=>{
 function run(seed,policy,slot=0){const s=C.fresh(seed);C.start(s,0);for(let t=0;t<=C.DURATION;t+=200){C.tick(s,t);if(s.mode==='ended')break;if(policy==='reader'){for(const r of [...s.records])if(!r.claimed&&C.isCandidate(s,r))C.pick(s,r.id,t);}else if(policy==='maximum'){const r=[...s.records].filter(r=>!r.claimed).sort((a,b)=>b.value-a.value)[0];if(r)C.pick(s,r.id,t);}else if(policy==='slot'){C.pick(s,[...s.records].reverse()[slot].id,t);}}return{score:s.score,lives:s.lives,mode:s.mode};}
 let reader=0,bestFixed=0,maximum=0,slotsEndedEarly=0;
 for(let seed=1;seed<=60;seed++){const aware=run(seed,'reader'),fixed=Array.from({length:6},(_,slot)=>run(seed,'slot',slot)),max=run(seed,'maximum');reader+=aware.score;bestFixed+=Math.max(...fixed.map(v=>v.score));maximum+=max.score;slotsEndedEarly+=fixed.filter(v=>v.lives===0).length;assert(aware.score>Math.max(...fixed.map(v=>v.score)));}
 assert(reader>maximum);return{computerSeeds:60,readerScore:reader,retrospectiveBestFixedSlotScore:bestFixed,maximumOnlyScore:maximum,fixedSlotLifeLossSessions:slotsEndedEarly,notAStudentEnjoymentMeasure:true};
});
const report={kind:'live-game-rule-regressions',studentPlaytests:0,checks};fs.writeFileSync(path.resolve(__dirname,'live-outlier-results.json'),JSON.stringify(report,null,2)+'\n','utf8');console.log(JSON.stringify(report,null,2));
