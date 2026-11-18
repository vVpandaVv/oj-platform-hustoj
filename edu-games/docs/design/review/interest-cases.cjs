const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const prototype = process.argv[2] || path.resolve(__dirname,'../prototypes/outlier-context-playtest.html');
const fragment = fs.readFileSync(prototype, 'utf8');
assert(!fragment.startsWith('\uFEFF'));
assert(!/\p{Script=Han}/u.test(fragment));
const source = fragment.match(/<script>([\s\S]*?)<\/script>/)[1];
const sandbox = {exports:{}};
new Function('module',source)(sandbox);
const core = sandbox.exports;
const results = [];
function check(game, test) { results.push({game, ...test()}); }

check('character-code',()=>{
 const table=new Map([['M',42],['A',17],['P',63],[' ',90],['2',28]]);
 const encoded=[...'MAP 2'].map(c=>table.get(c));
 assert.deepEqual(encoded,[42,17,63,90,28]);
 const inverse=new Map([...table].map(([a,b])=>[b,a]));
 assert.equal(encoded.map(v=>inverse.get(v)).join(''),'MAP 2');
 const jobs=[{id:'short',work:2,due:3,size:3},{id:'encode',work:5,due:9,size:8},{id:'table',work:4,due:12,size:4}];
 function run(order){let time=0,sealed=0,missed=0;for(const i of order){time+=jobs[i].work;if(time<=jobs[i].due)sealed+=jobs[i].size;else missed++;}return{sealed,missed};}
 const urgent=run([0,1,2]),longFirst=run([1,0,2]);
 assert.deepEqual(urgent,{sealed:15,missed:0});assert.equal(longFirst.missed,1);
 return{actualConversion:true,abstractWorkUnits:true,urgent,longFirst};
});

check('image-data',()=>{
 const small=['00000000','02200220','22222222','22222222','02222220','00222200','00022000','00000000'];
 const rows=small.flatMap(row=>{const doubled=[...row].map(c=>c+c).join('');return[doubled,doubled];});
 assert.equal(rows.length,16);assert(rows.every(row=>row.length===16));
 const cells=rows.reduce((a,row)=>a+row.length,0);
 const runs=rows.reduce((a,row)=>a+1+[...row].slice(1).filter((v,i)=>v!==row[i]).length,0);
 assert(runs<cells);
 const palette=['white','black','red','green'];
 for(const row of rows){const decoded=[...row].map(v=>palette[Number(v)]);assert.equal(decoded.map(v=>palette.indexOf(v)).join(''),row);}
 return{cells,manualRowRuns:runs,firstSmallPatchCells:16,roundTrip:true,observation:'Interaction counts, not student timings'};
});

check('outlier-detection',()=>{
 let generatedPairs=0,ordinaryEndpoints=0;
 for(let seed=1;seed<=1000;seed++){const s=core.fresh(seed);assert(core.valid(s));for(const target of new Set(s.cards.map(c=>c.target))){const pair=s.cards.filter(c=>c.target===target);assert.equal(pair.filter(core.isOutlier).length,1);const ordinary=pair.find(c=>!core.isOutlier(c));if(target===Math.min(...ordinary.values)||target===Math.max(...ordinary.values))ordinaryEndpoints++;generatedPairs++;}}
 assert(ordinaryEndpoints>1000);
 let informed=0,firstVisible=0,edgeOnly=0;
 for(let seed=1;seed<=200;seed++)for(const strategy of ['informed','first-visible','edge-only']){
  const s=core.fresh(seed);let now=1;
  for(let round=0;round<6;round++){
   const first=strategy==='informed'?s.cards.find(core.isOutlier):strategy==='edge-only'?s.cards.find(c=>c.target===Math.min(...c.values)||c.target===Math.max(...c.values)):s.cards[0],mate=s.cards.find(c=>c.target===first.target&&c.id!==first.id);
   core.pick(s,first.id,now);core.pick(s,mate.id,now+10);assert(s.pending);core.tick(s,now+500);assert(core.valid(s));
   const previous=s.score;core.pick(s,first.id,now+600);assert.equal(s.score,previous,'Retired answer cannot score');now+=1000;
  }
  if(strategy==='informed')informed+=s.score;else if(strategy==='edge-only')edgeOnly+=s.score;else firstVisible+=s.score;
 }
 const timed=core.fresh();core.pick(timed,timed.cards[0].id,10);core.tick(timed,60010);assert(timed.ended);const final=timed.score;core.pick(timed,timed.cards[1].id,60011);assert.equal(timed.score,final);
 assert(informed>firstVisible);assert(informed>edgeOnly);
 return{generatedPairs,ordinaryEndpoints,computerDecisionsPerPolicy:1200,informedScore:informed,firstVisibleScore:firstVisible,edgeOnlyScore:edgeOnly,retiredAnswersCannotScore:true,deadlineEnds:true};
});

check('missing-values',()=>{
 const cases=[{actual:24,mean:82/3,chosen:22+(2-1)/(10-1)*(40-22)},{actual:11.5,mean:(10+11+12+80)/4,chosen:(11+12)/2},{actual:1,mean:2/3,chosen:1},{actual:50,mean:(50+10+50+10)/4,chosen:50}];
 const meanError=cases.reduce((n,c)=>n+Math.abs(c.actual-c.mean),0),chosenError=cases.reduce((n,c)=>n+Math.abs(c.actual-c.chosen),0);
 assert.equal(chosenError,0);assert(meanError>40);
 const hypotheses=[[10,10,10,10,10],[10,15,20,15,10],[10,10,30,30,30]];
 assert.equal(new Set(hypotheses.map(h=>h[0])).size,1);assert.equal(new Set(hypotheses.map(h=>h[2])).size,3);
 const target=3,allowedDonors=[1,2,4];assert(!allowedDonors.includes(target));
 return{sameUnits:true,alwaysMeanError:meanError,appropriateMethodsError:chosenError,repeatingObservationDistinctOutcomes:1,informativeObservationDistinctOutcomes:3,targetNotDirectlyRevealed:true};
});

check('data-visualization',()=>{
 const values=[10,12,20,21];const changes=values.slice(1).map((v,i)=>v-values[i]);const largest=changes.indexOf(Math.max(...changes));
 assert.equal(largest,1);assert.equal(changes[largest],8);
 const pie=[50,30,20];assert.equal(pie.reduce((a,b)=>a+b),100);assert.equal(pie[0]/100,.5);
 const sample=[1,2,8,10,11,12,17,19,22,28],bins=[0,10,20].map(a=>sample.filter(v=>v>=a&&v<a+10).length);
 assert.deepEqual(bins,[3,5,2]);
 return{largestChange:{from:1,to:2,amount:8},pieHalf:.5,histogram:bins,designActionModel:{threeRepeatedSetups:15,oneSetupAndThreeFindings:7},observation:'Setup action counts are a rule-model example, not measured UI timings'};
});

check('data-extraction',()=>{
 const rows=[['1','red','circle'],['2','red','square'],['3','blue','circle'],['4','blue','square'],['5','green','circle'],['6','red','circle']];
 const red=rows.filter(r=>r[1]==='red'),blueOrGreen=rows.filter(r=>r[1]==='blue'||r[1]==='green'),redAndCircle=rows.filter(r=>r[1]==='red'&&r[2]==='circle');
 assert.equal(red.length,3);assert.equal(blueOrGreen.length,3);assert.equal(redAndCircle.length,2);
 const projected=red.map(r=>[r[0],r[2]]);assert.equal(projected.length,3);assert(projected.every(r=>r.length===2));
 const next=[['7','blue','square'],['8','blue','circle'],['9','blue','square']];assert.equal(next.filter(r=>r[1]==='red').length,0);assert.equal(next.filter(r=>r[1]==='blue').length,3);
 return{targetRows:3,distinctValidSelections:[red.map(r=>r[0]),blueOrGreen.map(r=>r[0])],andCount:2,projectedRows:projected.length,blindRepeatRedNextRows:0};
});

check('data-structure',()=>{
 const stack=['A','B'],queue=['A','B'];assert.equal(stack.at(-1),'B');assert.equal(queue[0],'A');assert.equal(stack.length,2);assert.equal(queue.length,2);
 assert.equal(stack.pop(),'B');assert.equal(queue.shift(),'A');
 const priority=[{kind:'B',p:2,seq:0},{kind:'A',p:9,seq:1},{kind:'C',p:9,seq:2}].sort((a,b)=>b.p-a.p||a.seq-b.seq);assert.deepEqual(priority.map(v=>v.kind),['A','C','B']);
 const rack=['A','B','A','B','C','C'],newRack=[...rack,'A'];let oldRack=[...newRack];
 for(let i=0;i<=oldRack.length-3;i++)if(oldRack[i]===oldRack[i+1]&&oldRack[i]===oldRack[i+2])oldRack.splice(i,3);
 const count=new Map();for(const v of newRack)count.set(v,(count.get(v)||0)+1);const clear=[...count].find(([,n])=>n>=3)[0];let n=3;const recovered=newRack.filter(v=>v===clear&&n>0?(n--,false):true);
 assert.equal(oldRack.length,7);assert.equal(recovered.length,4);
 return{peekDoesNotRemove:true,stackFirst:'B',queueFirst:'A',priorityAndTie:['A','C','B'],oldConsecutiveRackLength:oldRack.length,revisedMatchingRackLength:recovered.length};
});

check('ai-classifier',()=>{
 const label=x=>x>=6?'B':'A',train=[2,9],evalX=Array.from({length:10},(_,i)=>i+1),predict=(cut,x)=>x>=cut?'B':'A';
 const trainAccuracy=cut=>train.filter(x=>predict(cut,x)===label(x)).length,evalAccuracy=cut=>evalX.filter(x=>predict(cut,x)===label(x)).length;
 assert.equal(trainAccuracy(3),2);assert.equal(trainAccuracy(6),2);assert.equal(evalAccuracy(3),7);assert.equal(evalAccuracy(6),10);
 let candidates=[3,4,5,6,7,8,9];const redundant=candidates.filter(c=>predict(c,2)===label(2));assert.equal(redundant.length,7);
 for(const x of [6,4,5])candidates=candidates.filter(c=>predict(c,x)===label(x));assert.deepEqual(candidates,[6]);
 return{trainCorrectBoth:2,newCorrectAtCut3:7,newCorrectAtCut6:10,candidatesAfterRedundantSample:7,candidatesAfterInformativeSamples:candidates,observation:'One-dimensional counterexample; not full two-dimensional gameplay validation'};
});

const report={kind:'designer-rule-model-tests',studentParticipants:0,studentEnjoymentCertified:false,sourceOfStudentPreference:'User-reported classroom observation, not these automated tests',results};
fs.writeFileSync(path.join(__dirname,'interest-results.json'),JSON.stringify(report,null,2)+'\n','utf8');
console.log(JSON.stringify(report,null,2));
