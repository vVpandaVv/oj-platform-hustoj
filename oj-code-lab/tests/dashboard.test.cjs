const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const path=require('node:path');
class Element {
 constructor(){this.children=[];this.fields={};this.events={};this.hidden=false;this.value='';this.style={};this.attributes={};}
 set innerHTML(value){this.html=value;this.children=value.startsWith('<td>')?Array.from({length:5},()=>new Element()):[];}
 get innerHTML(){return this.html||'';}
 appendChild(item){this.children.push(item);}
 querySelector(key){return this.fields[key] ||= new Element();}
 getAttribute(){return JSON.stringify({restUrl:'/api',nonce:'test'});}
 setAttribute(key,value){this.attributes[key]=value;}
 addEventListener(type,fn){this.events[type]=fn;}
}
const flush=()=>new Promise(r=>setImmediate(r));
function app(){
 const root=new Element(),requests=[];
 const document={querySelector:()=>root,createElement:()=>new Element(),addEventListener:(_,fn)=>document.ready=fn};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../resources/js/dashboard.js'),'utf8'),{document,fetch:(url)=>new Promise((resolve,reject)=>requests.push({url,resolve,reject}))});
 document.ready();
 const field=name=>root.querySelector(`[data-field="${name}"]`);
 const choose=id=>{field('context').value=id;field('context').events.change();};
 const respond=(i,data)=>requests[i].resolve({ok:true,json:()=>Promise.resolve(data)});
 return {root,requests,field,choose,respond};
}
const row=(userId,displayName)=>({userId,displayName,cellCount:1,updatedAt:null,hasError:false});
test('old roster failure cannot replace newly selected class',async()=>{
 const a=app();a.respond(0,[]);await flush();a.choose('class:a');a.choose('class:b');
 a.respond(2,{rows:[row(2,'B')],nextCursor:''});await flush();a.requests[1].reject(new Error('old failure'));await flush();
 assert.equal(a.field('roster-body').children[0].children[0].textContent,'B');
});
test('deselected context ignores a pending roster response',async()=>{
 const a=app();a.respond(0,[]);await flush();a.choose('class:a');a.choose('');
 a.respond(1,{rows:[row(1,'A')],nextCursor:''});await flush();assert.equal(a.field('roster-panel').hidden,true);assert.equal(a.field('roster-body').children.length,0);
});
test('late learner response cannot appear under another learner title',async()=>{
 const a=app();a.respond(0,[]);await flush();a.choose('class:a');a.respond(1,{rows:[row(1,'A'),row(2,'B')],nextCursor:''});await flush();
 for(const item of a.field('roster-body').children)item.querySelector('[data-action="view"]').events.click();
 a.respond(3,{cells:[{code:'B code',outputs:[]}]});await flush();a.respond(2,{cells:[{code:'A code',outputs:[]}]});await flush();
 assert.equal(a.field('viewer-cells').children[0].children[0].textContent,'B code');assert.match(a.field('viewer-title').textContent,/B/);
});

test('mixed viewer displays literal text in order without executable outputs',async()=>{
 const a=app();a.respond(0,[]);await flush();a.choose('class:a');a.respond(1,{rows:[row(1,'A')],nextCursor:''});await flush();
 a.field('roster-body').children[0].querySelector('[data-action="view"]').events.click();
 const note="  한글 기록\n<script>alert('literal')</script> & **제목**";
 a.respond(2,{formatVersion:2,cells:[
  {type:'code',code:'print(1)',outputs:[{type:'stream',text:'1'}]},
  {type:'text',code:note,outputs:[{type:'error',text:'must not render'},{type:'image',image:'fake'}]},
  {code:'legacy code',outputs:[]}
 ]});await flush();
 const cells=a.field('viewer-cells').children;
 assert.equal(cells.length,3);assert.equal(cells[0].children[1].textContent,'1');
 assert.equal(cells[1].attributes['aria-label'],'텍스트 셀');assert.equal(cells[1].children.length,1);
 assert.equal(cells[1].children[0].className,'ocl-text-view');assert.equal(cells[1].children[0].textContent,note);
 assert.equal(cells[1].children[0].children.length,0);assert.equal(cells[1].children[0].style.whiteSpace,'pre-wrap');
 assert.equal(cells[2].attributes['aria-label'],'코드 셀');assert.equal(cells[2].children[0].textContent,'legacy code');
});
