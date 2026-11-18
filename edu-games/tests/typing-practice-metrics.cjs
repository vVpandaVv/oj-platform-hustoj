const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),context={window:{},document:{readyState:'loading',addEventListener(){}},performance:{now:()=>10000}};
vm.runInNewContext(fs.readFileSync(path.join(root,'assets/typing/verified.js'),'utf8'),context);
vm.runInNewContext(fs.readFileSync(path.join(root,'blocks/code-typing/view.js'),'utf8').replace('const init = () => {','globalThis.TestTyping = CodeTypingGame; const init = () => {'),context);
const engine=context.window.EduTypingVerified;let checks=0;const check=(v,label)=>{assert(v,label);checks++;};
function game(mode='short',content='ab = 1\nc=2'){
 const g=Object.create(context.TestTyping.prototype);g.state={mode,language:'python',status:'running',input:''};g.rules={modes:{assessment:{records:true}}};g.lineEnding='enter';g.currentSample=()=>({id:'test',content});g.scoreFor=()=>0;g.session={};g.fields={input:{value:'',selectionStart:0,selectionEnd:0}};
 g.handleInput=function(kind){this.trackPracticeInput(this.fields.input.value,kind);this.state.input=this.fields.input.value;};return g;
}
for(const mode of ['keyword','short','long']){
 const g=game(mode);g.trackPracticeInput(' '.repeat(10));g.state.input=' '.repeat(10);check(g.practiceMetrics(5).cpm===0,mode+' whitespace has no speed');
 const value='xb = 1    ';g.trackPracticeInput(value);g.state.input=value;
 const measured=g.practiceMetrics(5);check(measured.validCharacters===3&&measured.cpm===36,mode+' later positional matches count despite first typo');
 const before=measured.accuracy;g.trackPracticeInput('ab = 1    ');g.state.input='ab = 1    ';check(g.practiceMetrics(5).mistakes>0&&g.practiceMetrics(5).accuracy<100,mode+' corrected errors remain');
 const corrected=g.practiceMetrics(5);g.trackPracticeInput('xb = 1    ');g.trackPracticeInput('ab = 1    ');g.state.input='ab = 1    ';check(g.practiceMetrics(5).validCharacters===4&&g.practiceMetrics(5).accuracy<corrected.accuracy,mode+' retyping does not inflate credit');
 check(before<100,mode+' prior spaces remain in attempt history');
}
const g=game('short','ab\ncd');g.lineEnding='space-enter';g.fields.input={value:'ab',selectionStart:2,selectionEnd:2};g.trackPracticeInput('ab');g.state.input='ab';let prevented=false;
g.handleEditorKeydown({key:' ',preventDefault(){prevented=true;}});check(prevented&&g.state.input==='ab\n','Space converts only expected line end');check(g.practiceMetrics(5).accuracy===100&&g.practiceMetrics(5).validCharacters===2,'accepted Space is correct but earns no code speed');
g.fields.input.value='ab\nc';g.fields.input.selectionStart=g.fields.input.selectionEnd=4;g.state.input='ab\nc';prevented=false;g.handleEditorKeydown({key:' ',preventDefault(){prevented=true;}});check(!prevented,'Space in middle of line remains literal');
const strict=game('short','ab\ncd');strict.fields.input={value:'ab',selectionStart:2,selectionEnd:2};prevented=false;strict.handleEditorKeydown({key:' ',preventDefault(){prevented=true;}});check(!prevented,'Enter-only mode never normalizes Space');
const complete=game('short','a b');complete.trackPracticeInput('a b');complete.state.input='a b';check(complete.practiceMetrics(5).validCharacters===2,'practice completion does not auto-reset before result');complete.trackPracticeInput('axb');complete.state.input='axb';check(complete.practiceMetrics(5).validCharacters===2&&complete.practiceMetrics(5).accuracy<100,'editing a completed practice does not create phantom completed code');
const fixed=game('assessment');fixed.lineEnding='space-enter';fixed.verified={evaluation:{}};check(fixed.lineEndingMode()==='enter','existing evaluations keep Enter-only');fixed.verified.evaluation.lineEnding='space-enter';fixed.lineEnding='enter';check(fixed.lineEndingMode()==='space-enter','teacher policy overrides student preference');fixed.verified.s={lineEnding:'enter'};check(fixed.lineEndingMode()==='enter','active server run controls policy');
const old=game();old.lineEnding='enter';const key=old.challengeKey();old.lineEnding='space-enter';check(key!==old.challengeKey()&&key.endsWith('typing-v3:enter'),'practice benchmarks separate new rules and line-ending policy');
const replay=engine.fresh([{content:'a\nb'}],60,95,engine.VERSION,'space-enter');engine.apply(replay,{start:0,delete:0,text:'a',kind:'type',at:0});engine.apply(replay,{start:1,delete:0,text:'\n',kind:'linebreak',at:1});check(replay.mistakes===0,'shared engine accepts configured linebreak');
console.log('PASS: '+checks+' practice CPM, historical accuracy and line-ending control checks.');
