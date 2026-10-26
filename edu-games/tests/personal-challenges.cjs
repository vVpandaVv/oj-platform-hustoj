const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),stored=new Map();let writesFail=false,checks=0;
const storage={getItem:k=>stored.get(k)||null,setItem:(k,v)=>{if(writesFail)throw Error('storage unavailable');stored.set(k,v);}};
function load(folder){
    const source=fs.readFileSync(path.join(root,'blocks',folder,'view.js'),'utf8'),name=source.match(/class (\w+)/)[1];
    const context={document:{readyState:'loading',addEventListener(){}},window:{localStorage:storage},localStorage:storage,performance:{now:()=>1000}};
    vm.runInNewContext(source.replace('const init = () => {',`globalThis.Game = ${name}; const init = () => {`),context);
    return Object.create(context.Game.prototype);
}
function check(ok,label){assert(ok,label);checks++;}
const image=load('image-data');
image.rules={width:8,samples:[{id:'a',size:8},{id:'b',size:8},{id:'c',size:16}]};image.collection=new Set();image.collectionKey='image:user7';
image.state={mode:'restore',accuracy:100,sampleId:'a',selectedAnswerId:'b'};image.collectSolved();check(image.collection.size===0,'wrong identity not collected');
image.state.selectedAnswerId='a';image.state.accuracy=99.9;image.collectSolved();check(image.collection.size===0,'incomplete pixels not collected');
image.state.accuracy=100;image.collectSolved();image.collectSolved();check(image.collection.size===1,'no duplicates');
for(let i=0;i<30;i++)check(image.randomSampleId()==='b','unseen same size preferred');
image.state.mode='draw';image.state.sampleId='b';image.state.selectedAnswerId='b';image.collectSolved();check(!image.collection.has('b'),'drawing cannot unlock');
writesFail=true;image.state.mode='restore';image.collectSolved();check(image.collection.size===2,'blocked storage retains in-page collection');writesFail=false;

const typing=load('code-typing');typing.config={currentUserId:7};typing.state={language:'python',mode:'short'};typing.speedRecords={};typing.keywordStreak=0;typing.keywordStreakLanguage='';typing.challengeStorageKey='typing:user7';
let sample={id:'short-one',content:'A'.repeat(100)};typing.currentSample=()=>sample;typing.isAssessment=()=>typing.state.mode==='assessment';typing.isKeywordMode=()=>typing.state.mode==='keyword';typing.sessionTimeLimit=()=>120;
const metrics={accuracy:98,progress:100,typedLength:100,cpm:180};
typing.recordChallenge({...metrics,accuracy:96});check(!typing.speedRecords[typing.challengeKey()],'accuracy floor');
typing.recordChallenge({...metrics,progress:50});check(!typing.speedRecords[typing.challengeKey()],'unfinished practice not eligible');
typing.recordChallenge(metrics);check(typing.speedRecords[typing.challengeKey()]===180,'eligible speed');
typing.recordChallenge({...metrics,cpm:150});check(typing.speedRecords[typing.challengeKey()]===180,'best preserved');
sample={...sample,id:'short-two'};check(!typing.speedRecords[typing.challengeKey()],'different sample separate');
typing.state.mode='assessment';typing.recordChallenge({...metrics,progress:40});check(!typing.speedRecords[typing.challengeKey()],'early exam not eligible');
typing.recordChallenge(metrics);check(typing.speedRecords[typing.challengeKey()]===180,'finished exam eligible');
typing.state.language='c';check(!typing.speedRecords[typing.challengeKey()],'languages separate');
typing.state.mode='keyword';typing.recordChallenge({...metrics,accuracy:100});typing.recordChallenge({...metrics,accuracy:100});check(typing.keywordStreak===2,'keyword streak');
typing.recordChallenge({...metrics,accuracy:90});check(typing.keywordStreak===0&&typing.speedRecords[typing.challengeKey()]===2,'wrong breaks streak, best retained');
typing.state.language='python';typing.recordChallenge({...metrics,accuracy:100});check(typing.keywordStreak===1,'language resets current streak');
writesFail=true;typing.recordChallenge({...metrics,accuracy:100});check(typing.keywordStreak===2,'storage failure does not interrupt typing');writesFail=false;
const key7=typing.storageKey();typing.config.currentUserId=8;check(typing.storageKey()!==key7,'shared-PC user keys');
const field={textContent:''};typing.root={querySelector:()=>field};typing.state.mode='short';sample={id:'tiny',content:'AB'};
typing.speedRecords[typing.challengeKey()]=120;typing.renderChallenge();check(field.textContent.includes('측정 상한'),'no impossible target above measurement ceiling');
typing.speedRecords[typing.challengeKey()]=110;typing.renderChallenge();check(field.textContent.includes('다음 목표 120 CPM'),'target capped to sample length');
const binary=load('binary-arcade');binary.config={currentUserId:7};const b7=binary.storageKey();binary.config.currentUserId=8;check(binary.storageKey()!==b7,'binary user keys');
for(const game of ['binary-arcade','character-code','keyword-rain','device-matching','code-typing']){
    const html=spawnSync(process.env.PHP_BINARY||'D:/php/php.exe',['tests/browser-fixture.php'],{cwd:root,input:JSON.stringify({game,action:'html'}),encoding:'utf8'});
    check(html.status===0&&html.stdout.includes('data-field="challenge-goal"'),`${game} goal renderer`);
}
console.log(`PASS: ${checks} collection, speed/accuracy, streak, device-scoped record and goal renderer checks.`);
