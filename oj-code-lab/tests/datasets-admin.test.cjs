const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const path=require('node:path');

// Run the shipped curation UI against a minimal DOM and controlled HTTP responses.
class Element {
 constructor(tag='div'){this.tag=tag;this.children=[];this.fields={};this.events={};this.hidden=false;this.value='';this.disabled=false;this.files=[];this._html='';this._text='';}
 set innerHTML(value){this._html=value;this._text='';this.children=value.startsWith('<td>')?Array.from({length:4},()=>new Element('td')):[];}
 get innerHTML(){return this._html;}
 set textContent(value){this._text=value;this._html='';this.children=[];}
 get textContent(){return this._text;}
 appendChild(item){this.children.push(item);return item;}
 querySelector(key){return this.fields[key] ||= new Element();}
 getAttribute(){return JSON.stringify({restUrl:'/api',nonce:'test-only'});}
 addEventListener(type,fn){this.events[type]=fn;}
 fire(type,event={}){this.events[type]?.(event);}
 reset(){this.wasReset=true;}
}
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function app(){
 const root=new Element(),requests=[],prompts=[];
 const document={querySelector:()=>root,createElement:tag=>new Element(tag),addEventListener:(_,fn)=>document.ready=fn};
 class FormData {constructor(){this.parts=[];} append(name,value){this.parts.push([name,value]);}}
 const window={prompt:(message,initial)=>{prompts.push({message,initial});return initial;}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../resources/js/datasets-admin.js'),'utf8'),{
  document,window,FormData,fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))
 });
 document.ready();
 const field=name=>root.querySelector(`[data-field="${name}"]`);
 const respond=(index,data,ok=true)=>requests[index].resolve({ok,json:()=>Promise.resolve(data)});
 return {root,requests,prompts,field,respond};
}
async function searched(){
 const a=app();a.respond(0,[]);await flush();
 const form=a.field('search-form');form.querySelector('[data-field="search-input"]').value='records';
 form.fire('submit',{preventDefault(){}});a.respond(1,[{ref:'owner/slug',title:'연습 자료'}]);await flush();return a;
}

test('quick add explicitly fetches and pins the first supported file after unsupported entries',async()=>{
 const a=await searched();const row=a.field('search-results').children[0];
 assert.equal(row.children[1].textContent,'파일 선택');assert.equal(row.children[2].textContent,'첫 지원 파일 추가');
 row.children[2].fire('click');assert.equal(row.children[2].disabled,true);
 assert.equal(a.requests[2].url,'/api/datasets/files?owner=owner&slug=slug');
 a.respond(2,[{name:'README.md'},{name:'not-supported.json'},{name:'rows.NDJSON'},{name:'later.csv'}]);await flush();
 const body=JSON.parse(a.requests[3].options.body);
 assert.equal(body.fileName,'rows.NDJSON');assert.equal(body.source,'kaggle');assert.equal(body.label,'연습 자료');
 assert.equal(a.prompts.length,2);a.respond(3,{});await flush();a.respond(4,[]);await flush();
 assert.equal(row.children[2].disabled,false);
});

test('quick add reports no supported files without creating a recommendation',async()=>{
 const a=await searched();const quick=a.field('search-results').children[0].children[2];quick.fire('click');
 a.respond(2,[{name:'README.md'},{name:'image.png'}]);await flush();
 assert.equal(a.requests.length,3);assert.equal(a.prompts.length,0);assert.equal(quick.disabled,false);
 assert.match(a.field('messages').children[0].textContent,/CSV·JSONL·NDJSON·ZIP/);
});

test('teacher file selection handles JSONL NDJSON and ZIP and disables unsupported files',async()=>{
 const a=await searched();a.field('search-results').children[0].children[1].fire('click');
 a.respond(2,[{name:'README.md'},{name:'rows.JSONL'},{name:'rows.ndjson'},{name:'all.zip'}]);await flush();
 const rows=a.field('files-list').children;
 assert.match(a.field('files-title').textContent,/CSV · JSONL · NDJSON · ZIP/);
 assert.equal(rows[0].children[1].disabled,true);rows[0].children[1].fire('click');assert.equal(a.requests.length,3);
 for(const row of rows.slice(1)){assert.equal(row.children[1].disabled,false);}
 rows[3].children[1].fire('click');assert.equal(JSON.parse(a.requests[3].options.body).fileName,'all.zip');
 a.respond(3,{});await flush();a.respond(4,[]);await flush();
});

test('an API failure is rendered literally and re-enables the quick action',async()=>{
 const a=await searched();const quick=a.field('search-results').children[0].children[2];quick.fire('click');
 const error='<img src=x onerror=alert(1)> 파일 목록을 읽지 못했습니다.';
 a.respond(2,{message:error},false);await flush();const message=a.field('messages').children[0];
 assert.equal(message.textContent,error);assert.equal(message.innerHTML,'');assert.equal(quick.disabled,false);
 assert.equal(a.prompts.length,0);assert.equal(a.requests.length,3);
});

test('teacher upload sends CSV JSONL NDJSON and ZIP as the original multipart file',async()=>{
 for(const name of ['sample.csv','sample.jsonl','sample.ndjson','sample.zip']){
  const a=app();a.respond(0,[]);await flush();const form=a.field('upload-form');
  const file={name,bytes:'original file bytes'};form.querySelector('[data-field="upload-label"]').value='자료';
  form.querySelector('[data-field="upload-icon"]').value='';form.querySelector('[data-field="upload-file"]').files=[file];
  form.fire('submit',{preventDefault(){}});const request=a.requests[1];
  assert.equal(request.url,'/api/datasets/recommended/upload');assert.equal(request.options.method,'POST');
  assert.equal(request.options.headers['Content-Type'],undefined);
  assert.equal(request.options.body.parts.find(([key])=>key==='file')[1],file);
  a.respond(1,{});await flush();a.respond(2,[]);await flush();assert.equal(form.wasReset,true);
 }
});
