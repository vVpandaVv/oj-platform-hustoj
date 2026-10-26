const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

// Execute the complete shipped lab.js; emulate only the DOM and transport.
class Element {
  constructor(tag='div') { this.tag=tag; this.children=[]; this.events={}; this.fields={}; this.style={}; this.dataset={}; this.value=''; this.disabled=false; this.hidden=false; this._html=''; this._text=''; }
  set innerHTML(value) { this._html=value; this.children=[]; }
  get innerHTML() { return this._html; }
  set textContent(value) { this._text=value; this._html=''; this.children=[]; }
  get textContent() { return this._text; }
  appendChild(child) { return this.insertBefore(child,null); }
  insertBefore(child,reference) {
    if(reference!==null && !this.children.includes(reference)) throw new Error('Reference is not a child');
    if(child.parent) child.remove();
    const index=reference===null?this.children.length:this.children.indexOf(reference);
    child.parent=this; this.children.splice(index,0,child); return child;
  }
  remove() { if(this.parent) this.parent.children=this.parent.children.filter(x=>x!==this); this.parent=null; }
  get nextElementSibling() { return this.parent?.children[this.parent.children.indexOf(this)+1] || null; }
  get tagName() { return this.tag.toUpperCase(); }
  get lastElementChild() { return this.children.at(-1); }
  get classList() { return {contains:name=>(this.className||'').split(' ').includes(name)}; }
  setAttribute(name,value) { (this.attributes ||= {})[name]=String(value); }
  focus(options) { this.focusOptions=options; this.ownerDocument.activeElement=this; }
  scrollIntoView(options) { this.scrollOptions=options; }
  addEventListener(type,fn) { this.events[type]=fn; }
  fire(type,event={}) { this.events[type]?.(event); }
  getAttribute(name) { return name==='data-config'?JSON.stringify({restUrl:'/api',nonce:'test-only'}):(this.attributes?.[name] ?? null); }
  querySelector(selector) {
    if(selector.startsWith('.')) {
      return this.descendants().find(x=>(x.className||'').split(' ').includes(selector.slice(1))) || null;
    }
    return this.fields[selector] ||= Object.assign(new Element(),{ownerDocument:this.ownerDocument});
  }
  querySelectorAll(selector) {
    if(selector==='button, input') return Object.values(this.fields);
    if(selector==='[data-cell-id]') return this.descendants().filter(x=>x.dataset.cellId);
    if(selector.startsWith('.')) return this.descendants().filter(x=>(x.className||'').split(' ').includes(selector.slice(1)));
    return [];
  }
  descendants() { return this.children.flatMap(x=>[x,...x.descendants()]); }
}
const flush = () => new Promise(resolve=>setImmediate(resolve));
function app() {
  const root=new Element(); const requests=[]; const timers=[]; const events={}; const workers=[];
  class Worker { constructor(){this.sent=[];workers.push(this);} postMessage(data){this.sent.push(data);if(data.type==='init')queueMicrotask(()=>this.emit({type:'ready'}));} terminate(){this.terminated=true;} emit(data){this.onmessage({data});} }
  const document={querySelector:()=>root,createElement:tag=>Object.assign(new Element(tag),{ownerDocument:document}),createTextNode:text=>Object.assign(new Element('text'),{textContent:text,ownerDocument:document}),addEventListener:(_,cb)=>{document.ready=cb;}};
  root.ownerDocument=document;
  const window={crossOriginIsolated:true,addEventListener:(type,fn)=>events[type]=fn,setInterval:fn=>timers.push(fn),setTimeout:()=>0};
  const context={document,window,Worker,SharedArrayBuffer,Atomics,TextEncoder,TextDecoder,setTimeout,clearTimeout,console,fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../resources/js/worker-runtime.js'),'utf8'),context);
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../resources/js/lab.js'),'utf8'),context);
  document.ready();
  const field=name=>root.querySelector(`[data-field="${name}"]`);
  const action=name=>root.querySelector(`[data-action="${name}"]`).fire('click');
  // Successful notebook fixtures use the current transport envelope by default.
  const respond=(i,data,ok=true,status=200)=>requests[i].resolve({ok,status,json:()=>Promise.resolve(i===0 && data && Array.isArray(data.cells) ? {formatVersion:2,...data} : data)});
  return {root,document,requests,timers,events,workers,field,action,respond};
}
async function loaded() {
  const a=app(); a.respond(0,{revision:1,cells:[{id:'c1',code:'print(1)',outputs:[]}]}); await flush(); return a;
}
function edit(a,code) { const textarea=a.field('cells').querySelector('.ocl-code'); textarea.value=code; textarea.fire('input'); }
function isDirty(a) { let prevented=false; a.events.beforeunload({preventDefault:()=>prevented=true}); return prevented; }
test('mixed cells retain literal notes and order with formatVersion 2',async()=>{
  const a=await loaded();
  a.field('cells').querySelectorAll('.ocl-btn-insert-text')[0].fire('click');
  const note=cellRows(a)[0].querySelector('.ocl-text');
  note.value='풀이 기록\n  <script>print("메모")</script>\n끝'; note.fire('input');
  a.action('add-text-cell');
  const last=cellRows(a).at(-1).querySelector('.ocl-text'); last.value='다음에 이어서'; last.fire('input');
  a.action('save'); const body=JSON.parse(a.requests[1].options.body);
  assert.equal(body.formatVersion,2);
  assert.deepEqual(body.cells.map(c=>c.type),['text','code','text']);
  assert.equal(body.cells[0].code,note.value); assert.deepEqual(body.cells[0].outputs,[]);
  assert.equal(a.document.activeElement,last);
  assert.equal(cellRows(a)[0].querySelector('.ocl-btn-run'),null);
});
test('run all executes only code cells in mixed notebook order',async()=>{
  const a=app(); a.respond(0,{formatVersion:2,revision:3,cells:[
    {id:'note',type:'text',code:'this is not Python',outputs:[{type:'error',text:'discard'}]},
    {id:'one',type:'code',code:'print(1)',outputs:[]},
    {id:'two',type:'text',code:'설명',outputs:[]},
    {id:'three',type:'code',code:'print(3)',outputs:[]},
  ]}); await flush();
  assert.equal(cellRows(a)[0].querySelector('.ocl-output'),null);
  a.action('run-all'); await flush(); const worker=a.workers[0];
  assert.equal(worker.sent[1].code,'print(1)'); worker.emit({type:'result',id:worker.sent[1].id}); await flush();
  assert.equal(worker.sent[2].code,'print(3)'); worker.emit({type:'result',id:worker.sent[2].id}); await flush();
  assert.equal(worker.sent.length,3); a.action('save');
  assert.deepEqual(JSON.parse(a.requests[1].options.body).cells[0].outputs,[]);
  a.action('stop');
});
test('unsupported cell types block load and writes instead of converting content',async()=>{
  const a=app();a.respond(0,{revision:1,cells:[{id:'future',type:'unknown',code:'keep',outputs:[]}]});await flush();
  a.action('save');assert.equal(a.requests.length,1);assert.match(a.field('status').textContent,/저장/);
});
test('old or unknown server formats never enable edits or writes',async()=>{
  for(const formatVersion of [undefined,1,3]) {
    const a=app();
    a.requests[0].resolve({ok:true,status:200,json:()=>Promise.resolve({formatVersion,revision:1,cells:[{id:'old',code:'keep',outputs:[]}]})});
    await flush();a.action('save');assert.equal(a.requests.length,1);assert.equal(a.field('cells').children.length,0);
  }
});
test('stopping execution preserves the existing editors and note focus',async()=>{
  const a=await loaded();a.action('add-text-cell');const note=cellRows(a)[1].querySelector('.ocl-text');
  note.value='중단 후에도 기록';note.fire('input');const code=cellRows(a)[0].querySelector('.ocl-code');
  a.action('stop');assert.equal(cellRows(a)[1].querySelector('.ocl-text'),note);
  assert.equal(cellRows(a)[0].querySelector('.ocl-code'),code);assert.equal(a.document.activeElement,note);
});
test('in-flight save preserves subsequent edits for autosave',async()=>{
  const a=await loaded(); edit(a,'print(2)'); a.action('save'); edit(a,'print(3)');
  a.respond(1,{updatedAt:'now',revision:2}); await flush();
  assert.equal(isDirty(a),true); assert.match(a.field('status').textContent,/추가 변경/);
  a.timers[0](); assert.equal(a.requests.length,3);
  assert.equal(JSON.parse(a.requests[2].options.body).cells[0].code,'print(3)');
  a.respond(2,{updatedAt:'later',revision:3}); await flush(); assert.equal(isDirty(a),false);
});
test('failed save keeps content and retries on next timer',async()=>{
  const a=await loaded(); edit(a,'unsaved'); a.action('save'); a.respond(1,{message:'DB unavailable'},false,500); await flush();
  assert.equal(isDirty(a),true); assert.equal(a.field('status').textContent,'DB unavailable');
  a.timers[0](); assert.equal(JSON.parse(a.requests[2].options.body).cells[0].code,'unsaved');
});
test('failed initial HTTP read never enables destructive save',async()=>{
  const a=app(); a.respond(0,{message:'Forbidden'},false,403); await flush(); a.action('save'); a.timers[0]();
  assert.equal(a.requests.length,1); assert.equal(a.field('cells').children.length,0);
  assert.equal(a.root.querySelector('[data-action="save"]').disabled,true);
});
test('malformed initial response never becomes a new notebook',async()=>{
  const a=app(); a.respond(0,{}); await flush(); a.action('save'); assert.equal(a.requests.length,1);
});
test('saving is blocked while initial load is pending',async()=>{
  const a=app(); a.action('save'); assert.equal(a.requests.length,1);
});
test('initial network failure protects existing notebook',async()=>{
  const a=app(); a.requests[0].reject(new Error('offline')); await flush(); a.action('save');
  assert.equal(a.requests.length,1); assert.equal(a.field('cells').children.length,0);
});
test('first notebook supports normal edit and save',async()=>{
  const a=app(); a.respond(0,{cells:[],updatedAt:null,revision:0}); await flush(); edit(a,'print("안녕")'); a.action('save');
  assert.equal(JSON.parse(a.requests[1].options.body).cells[0].code,'print("안녕")');
  a.respond(1,{updatedAt:'now',revision:1}); await flush(); assert.equal(isDirty(a),false);
});
test('untrusted API error is rendered as text',async()=>{
  const a=await loaded(); const form=a.field('search-form'); form.querySelector('[data-field="search-input"]').value='data';
  form.fire('submit',{preventDefault(){}}); const message='<img src=x onerror=alert(1)>';
  a.respond(1,{message},false,502); await flush();
  assert.equal(a.field('search-results').textContent,message); assert.equal(a.field('search-results').innerHTML,'');
});

test('malformed successful save response never clears unsaved changes',async()=>{
  const a=await loaded(); edit(a,'not confirmed'); a.action('save'); a.respond(1,null); await flush();
  assert.equal(isDirty(a),true); assert.match(a.field('status').textContent,/저장 결과/);
});

test('conflict keeps local edits and pauses automatic retries',async()=>{
  const a=await loaded(); edit(a,'local copy'); a.action('save');
  assert.equal(JSON.parse(a.requests[1].options.body).revision,1);
  a.respond(1,{message:'conflict'},false,409); await flush(); a.timers[0](); a.action('save');
  assert.equal(a.requests.length,2); assert.equal(isDirty(a),true);
  assert.equal(a.field('cells').querySelector('.ocl-code').value,'local copy');
});

async function waitingForInput() {
  const a=await loaded(); edit(a,'input()'); a.field('cells').querySelector('.ocl-btn-run').fire('click'); await flush();
  const worker=a.workers[0], id=worker.sent[1].id;
  worker.emit({type:'input-request',id});
  return {a,worker,id};
}
test('adding and deleting other cells preserves pending stdin control',async()=>{
  const {a,worker,id}=await waitingForInput();
  const form=a.field('cells').querySelector('.ocl-input-request'); assert.ok(form);
  a.action('add-cell');
  assert.equal(a.field('cells').querySelector('.ocl-input-request'),form);
  a.field('cells').querySelectorAll('[data-cell-id]')[1].querySelector('.ocl-btn-danger').fire('click');
  assert.equal(a.field('cells').querySelector('.ocl-input-request'),form);
  form.querySelector('input').value='still available'; form.fire('submit',{preventDefault(){}});
  assert.equal(Atomics.load(new Int32Array(worker.sent[0].syncBuffer),0),1);
  worker.emit({type:'result',id}); await flush(); a.action('stop');
});
test('deleting a cell awaiting stdin sends EOF so queued work can proceed',async()=>{
  const {a,worker,id}=await waitingForInput();
  a.field('cells').querySelector('.ocl-btn-danger').fire('click');
  assert.equal(Atomics.load(new Int32Array(worker.sent[0].syncBuffer),0),2);
  assert.equal(a.field('cells').querySelector('.ocl-input-request'),null);
  worker.emit({type:'result',id,error:'EOFError'}); await flush(); a.action('stop');
});

test('dataset import generates read_csv using the acknowledged isolated file path',async()=>{
 const a=await loaded();a.field('datasets-panel').hidden=true;a.action('toggle-datasets');
 a.respond(1,[{id:7,label:'sample',source:'upload'}]);await flush();
 a.field('recommended-list').children[0].children[1].fire('click');
 a.respond(2,{fileName:'pandas.py',content:'column\n1\n'});await flush();
 const worker=a.workers[0]; assert.equal(worker.sent[1].type,'writeFile');
 worker.emit({type:'ack',id:worker.sent[1].id,filePath:'/home/pyodide/ocl-datasets/pandas.py'});await flush();
 assert.equal(worker.sent[2].type,'run');
 assert.ok(worker.sent[2].code.includes('pd.read_csv("/home/pyodide/ocl-datasets/pandas.py")'));
 worker.emit({type:'result',id:worker.sent[2].id});await flush();a.action('stop');
});

test('a silent completed run clears only its own running indicator',async()=>{
 const a=await loaded();edit(a,'sequence = [1]');
 a.field('cells').querySelector('.ocl-btn-run').fire('click');
 a.field('cells').querySelector('.ocl-btn-run').fire('click');await flush();
 const worker=a.workers[0];worker.emit({type:'result',id:worker.sent[1].id});await flush();
 assert.ok(a.field('cells').querySelector('.ocl-output-running'),'the next run keeps its indicator');
 worker.emit({type:'result',id:worker.sent[2].id});await flush();
 assert.equal(a.field('cells').querySelector('.ocl-output-running'),null);a.action('stop');
});


function cellRows(a) { return a.field('cells').querySelectorAll('[data-cell-id]'); }
function insertControls(a) { return a.field('cells').querySelectorAll('.ocl-btn-insert'); }
function setCode(row,code) { const textarea=row.querySelector('.ocl-code'); textarea.value=code; textarea.fire('input'); }
function assertInsertionLayout(a) {
  const rows=cellRows(a), children=a.field('cells').children;
  assert.equal(children.length,rows.length*2+1);
  children.forEach((child,index)=>assert.ok(child.classList.contains(index%2?'ocl-cell':'ocl-cell-insert')));
  assert.equal(insertControls(a).length,rows.length+1);
}

test('inline insertion saves first, middle and last cells in the displayed order',async()=>{
  const a=app();
  a.respond(0,{revision:7,cells:[
    {id:'first',code:'original first',outputs:[{type:'stream',text:'saved output'}]},
    {id:'last',code:'original last',outputs:[]},
  ]}); await flush();
  const originals=cellRows(a), savedOutput=originals[0].querySelector('.ocl-output-text');
  insertControls(a)[0].fire('click'); setCode(cellRows(a)[0],'inserted first');
  insertControls(a)[2].fire('click'); setCode(cellRows(a)[2],'inserted middle');
  insertControls(a).at(-1).fire('click'); setCode(cellRows(a).at(-1),'inserted last');
  assertInsertionLayout(a);
  assert.equal(cellRows(a)[1],originals[0]); assert.equal(cellRows(a)[3],originals[1]);
  assert.equal(originals[0].querySelector('.ocl-output-text'),savedOutput);
  a.action('save');
  const payload=JSON.parse(a.requests[1].options.body);
  assert.equal(payload.revision,7);
  assert.deepEqual(payload.cells.map(cell=>cell.code),['inserted first','original first','inserted middle','original last','inserted last']);
  assert.equal(payload.cells[1].id,'first'); assert.equal(payload.cells[3].id,'last');
  assert.deepEqual(payload.cells[1].outputs,[{type:'stream',text:'saved output'}]);
  assert.equal(new Set(payload.cells.map(cell=>cell.id)).size,5);
});

test('a cell inserted during an in-flight save remains dirty and reaches autosave',async()=>{
  const a=await loaded(); a.action('save');
  insertControls(a)[0].fire('click'); setCode(cellRows(a)[0],'inserted while saving');
  assert.equal(JSON.parse(a.requests[1].options.body).cells.length,1);
  a.respond(1,{updatedAt:'first response',revision:2}); await flush();
  assert.equal(isDirty(a),true); assert.match(a.field('status').textContent,/추가 변경/);
  a.timers[0]();
  const payload=JSON.parse(a.requests[2].options.body);
  assert.equal(payload.revision,2);
  assert.deepEqual(payload.cells.map(cell=>cell.code),['inserted while saving','print(1)']);
  a.respond(2,{updatedAt:'second response',revision:3}); await flush();
  assert.equal(isDirty(a),false);
});

test('inserting around active stdin preserves its form, typed input and later output',async()=>{
  const {a,worker,id}=await waitingForInput();
  const runningCell=cellRows(a)[0], form=runningCell.querySelector('.ocl-input-request');
  const input=form.querySelector('input'); input.value='작성 중인 입력';
  insertControls(a)[0].fire('click'); insertControls(a).at(-1).fire('click');
  assert.equal(cellRows(a)[1],runningCell);
  assert.equal(runningCell.querySelector('.ocl-input-request'),form);
  assert.equal(form.querySelector('input'),input); assert.equal(input.value,'작성 중인 입력');
  cellRows(a)[0].querySelector('.ocl-btn-danger').fire('click');
  assert.equal(runningCell.querySelector('.ocl-input-request'),form);
  form.fire('submit',{preventDefault(){}});
  assert.equal(Atomics.load(new Int32Array(worker.sent[0].syncBuffer),0),1);
  worker.emit({type:'stream',id,text:'input received'});
  worker.emit({type:'result',id}); await flush();
  assert.ok(runningCell.querySelectorAll('.ocl-output-text').some(node=>node.textContent==='input received'));
  assert.equal(runningCell.querySelector('.ocl-input-request'),null);
  a.action('stop');
});

test('deleting a middle cell keeps one working insertion control per remaining gap',async()=>{
  const a=app();
  a.respond(0,{revision:1,cells:['first','middle','last'].map(id=>({id,code:id,outputs:[]}))}); await flush();
  const first=cellRows(a)[0], last=cellRows(a)[2];
  cellRows(a)[1].querySelector('.ocl-btn-danger').fire('click');
  assertInsertionLayout(a);
  insertControls(a)[1].fire('click'); setCode(cellRows(a)[1],'replacement');
  assertInsertionLayout(a);
  assert.equal(cellRows(a)[0],first); assert.equal(cellRows(a)[2],last);
  a.action('save');
  assert.deepEqual(JSON.parse(a.requests[1].options.body).cells.map(cell=>cell.code),['first','replacement','last']);
});

test('the remaining insertion control restores an empty notebook and focuses the new editor',async()=>{
  const a=await loaded(); cellRows(a)[0].querySelector('.ocl-btn-danger').fire('click');
  assert.equal(cellRows(a).length,0); assertInsertionLayout(a);
  const button=insertControls(a)[0];
  assert.equal(button.type,'button'); assert.equal(button.getAttribute('aria-label'),'여기에 코드 셀 추가');
  button.fire('click');
  assertInsertionLayout(a);
  const row=cellRows(a)[0], textarea=row.querySelector('.ocl-code');
  assert.equal(a.document.activeElement,textarea);
  assert.equal(textarea.focusOptions.preventScroll,true);
  assert.equal(row.scrollOptions.block,'nearest');
  assert.equal(textarea.getAttribute('aria-label'),'Python 코드');
  setCode(row,'print("다시 시작")'); a.action('save');
  assert.deepEqual(JSON.parse(a.requests[1].options.body).cells.map(cell=>cell.code),['print("다시 시작")']);
});


async function importRecommended(a, result, ok=true, status=200) {
 a.field('datasets-panel').hidden=true;a.action('toggle-datasets');
 a.respond(1,[{id:7,label:'자료',source:'upload'}]);await flush();
 a.field('recommended-list').children[0].children[1].fire('click');
 a.respond(2,result,ok,status);await flush();
}

test('JSONL and NDJSON imports use the acknowledged path with lines=True and run the preview',async()=>{
 for(const result of [
  {fileName:'records.csv',format:'jsonl'},
  {fileName:'nested/records.jsonl'},
  {fileName:'records.NDJSON'},
  {fileName:'records.JSONL',format:'jsonl'},
  {fileName:'safe-"quoted"-records.jsonl',format:'jsonl'},
 ]) {
  const a=await loaded();const content='{"이름":"한글", "value":1}\n{"이름":"둘", "value":2}\n';
  await importRecommended(a,{...result,content});const worker=a.workers[0];
  assert.equal(worker.sent[1].type,'writeFile');
  assert.equal(new TextDecoder().decode(worker.sent[1].bytes),content);
  const actualPath='/home/pyodide/ocl-datasets/'+worker.sent[1].fileName;
  worker.emit({type:'ack',id:worker.sent[1].id,filePath:actualPath});await flush();
  assert.equal(worker.sent[2].type,'run');
  assert.equal(worker.sent[2].code,'import pandas as pd\n\ndf = pd.read_json('+JSON.stringify(actualPath)+', lines=True)\nprint(df.head())');
  worker.emit({type:'result',id:worker.sent[2].id});await flush();a.action('stop');
 }
});

test('the server format takes precedence over a filename extension',async()=>{
 const a=await loaded();await importRecommended(a,{fileName:'records.jsonl',content:'x\n1\n',format:'csv'});
 const worker=a.workers[0];const filePath='/home/pyodide/ocl-datasets/records.jsonl';
 worker.emit({type:'ack',id:worker.sent[1].id,filePath});await flush();
 assert.ok(worker.sent[2].code.includes('pd.read_csv('+JSON.stringify(filePath)+')'));
 worker.emit({type:'result',id:worker.sent[2].id});await flush();a.action('stop');
});

test('importing a JSONL preview preserves existing code, notes, and literal output DOM',async()=>{
 const a=app();a.respond(0,{revision:1,cells:[
  {id:'c1',type:'code',code:'print("keep")',outputs:[{type:'stream',text:'<script>literal output</script>'}]},
  {id:'n1',type:'text',code:'내 기록',outputs:[]}
 ]});await flush();
 const originalCode=cellRows(a)[0].querySelector('.ocl-code');
 const originalOutput=cellRows(a)[0].querySelector('.ocl-output');
 const originalNote=cellRows(a)[1].querySelector('.ocl-text');
 await importRecommended(a,{fileName:'records.jsonl',format:'jsonl',content:'{"n":1}\n'});
 const worker=a.workers[0];worker.emit({type:'ack',id:worker.sent[1].id,filePath:'/home/pyodide/ocl-datasets/records.jsonl'});await flush();
 assert.equal(cellRows(a)[0].querySelector('.ocl-code'),originalCode);
 assert.equal(originalCode.value,'print("keep")');assert.equal(cellRows(a)[0].querySelector('.ocl-output'),originalOutput);
 assert.equal(cellRows(a)[1].querySelector('.ocl-text'),originalNote);assert.equal(originalNote.value,'내 기록');
 assert.equal(cellRows(a).length,3);worker.emit({type:'result',id:worker.sent[2].id});await flush();a.action('stop');
});

test('unsupported and malformed import responses never create a file or code cell',async()=>{
 for(const result of [null,{fileName:'data.csv',content:null},{fileName:'data.zip',content:'binary',format:'zip'}]) {
  const a=await loaded();await importRecommended(a,result);
  assert.equal(a.workers.length,0);assert.equal(cellRows(a).length,1);
  assert.equal(cellRows(a)[0].querySelector('.ocl-code').value,'print(1)');
  assert.match(a.field('status').textContent,/형식/);
 }
});

test('an ambiguous ZIP error remains literal and leaves the notebook unchanged',async()=>{
 const a=await loaded();const error='ZIP에 파일이 여러 개입니다. <img src=x onerror=alert(1)> 원하는 파일을 따로 업로드해 주세요.';
 await importRecommended(a,{message:error},false,422);
 assert.equal(a.field('status').textContent,error);assert.equal(a.field('status').innerHTML,'');
 assert.equal(a.workers.length,0);assert.equal(cellRows(a).length,1);
});

test('Kaggle file selection enables JSONL NDJSON and ZIP while disabling unsupported entries',async()=>{
 const a=await loaded();const form=a.field('search-form');form.querySelector('[data-field="search-input"]').value='records';
 form.fire('submit',{preventDefault(){}});a.respond(1,[{ref:'owner/slug',title:'Records'}]);await flush();
 a.field('search-results').children[0].children[1].fire('click');
 a.respond(2,[{name:'readme.md'},{name:'records.JSONL'},{name:'records.ndjson'},{name:'records.zip'}]);await flush();
 const rows=a.field('files-list').children;assert.equal(rows[0].children[1].disabled,true);
 rows[0].children[1].fire('click');assert.equal(a.requests.length,3);
 assert.equal(rows[1].children[1].disabled,false);assert.equal(rows[2].children[1].disabled,false);
 assert.equal(rows[3].children[1].textContent,'ZIP 가져오기');rows[3].children[1].fire('click');
 assert.equal(JSON.parse(a.requests[3].options.body).file,'records.zip');
 a.respond(3,{message:'여러 파일은 하나를 골라 주세요.'},false,422);await flush();
 assert.equal(a.field('status').textContent,'여러 파일은 하나를 골라 주세요.');
});

test('debugging shows the paused line and variables, marks changes and locks the cell',async()=>{
 const a=await loaded();edit(a,'x = 1\nx = 2\nprint(x)');
 const row=cellRows(a)[0];row.querySelector('.ocl-btn-debug').fire('click');await flush();
 const worker=a.workers[0],id=worker.sent[1].id;assert.equal(worker.sent[1].type,'debug');
 assert.ok(row.querySelector('.ocl-debug-panel'));assert.equal(row.querySelector('.ocl-code').readOnly,true);
 worker.emit({type:'debug-pause',id,snapshot:{line:2,function:'<module>',stack:[{function:'<module>',line:2}],locals:null,globals:[{name:'x',type:'int',value:'1'}]}});
 assert.match(row.querySelector('.ocl-debug-where').textContent,/2번째 줄/);
 assert.equal(row.querySelector('.ocl-debug-code').textContent,'2 │ x = 2');
 assert.equal(row.querySelector('.ocl-debug-value').textContent,'1');
 assert.equal(row.querySelector('.ocl-debug-changed'),null,'first pause has nothing to compare');
 row.querySelector('.ocl-debug-over').fire('click');
 const sync=new Int32Array(worker.sent[0].syncBuffer);assert.equal(Atomics.load(sync,0),1);
 assert.equal(JSON.parse(new TextDecoder().decode(new Uint8Array(sync.buffer,8,Atomics.load(sync,1)).slice())).action,'over');
 assert.equal(row.querySelector('.ocl-debug-over').disabled,true);
 worker.emit({type:'debug-pause',id,snapshot:{line:3,function:'<module>',stack:[],locals:null,globals:[{name:'x',type:'int',value:'<b>2</b>'}]}});
 assert.equal(row.querySelector('.ocl-debug-value').textContent,'<b>2</b>');
 assert.ok(row.querySelector('.ocl-debug-changed'));
 worker.emit({type:'stream',id,text:'2\n'});worker.emit({type:'result',id});await flush();
 assert.ok(row.querySelector('.ocl-debug-panel'),'the step history stays open for review');
 assert.equal(row.querySelector('.ocl-code').readOnly,false);assert.equal(row.querySelector('.ocl-debug-over').hidden,true);
 assert.equal(row.querySelector('.ocl-output-text').textContent,'2\n');
 row.querySelector('.ocl-debug-stop').fire('click');assert.equal(row.querySelector('.ocl-debug-panel'),null);a.action('stop');
});
test('step history moves back and forth, keeps the end snapshot and closes on edit',async()=>{
 const a=await loaded();edit(a,'a = 1\nb = a / 0');
 const row=cellRows(a)[0];row.querySelector('.ocl-btn-debug').fire('click');await flush();
 const worker=a.workers[0],id=worker.sent[1].id;assert.deepEqual([...worker.sent[1].breakpoints],[]);
 worker.emit({type:'debug-pause',id,snapshot:{line:1,function:'<module>',stack:[],locals:null,globals:[]}});
 assert.equal(row.querySelector('.ocl-debug-nav').hidden,true,'one step needs no navigation');
 row.querySelector('.ocl-debug-over').fire('click');
 const sync=new Int32Array(worker.sent[0].syncBuffer);
 assert.deepEqual(JSON.parse(new TextDecoder().decode(new Uint8Array(sync.buffer,8,Atomics.load(sync,1)).slice())),{action:'over',breakpoints:[]});
 worker.emit({type:'debug-pause',id,snapshot:{line:2,function:'<module>',stack:[],locals:null,globals:[{name:'a',type:'int',value:'1'}]}});
 assert.equal(row.querySelector('.ocl-debug-counter').textContent,'단계 2 / 2');
 row.querySelector('.ocl-debug-prev').fire('click');
 assert.equal(row.querySelector('.ocl-debug-counter').textContent,'단계 1 / 2');assert.match(row.querySelector('.ocl-debug-where').textContent,/지난 단계/);
 assert.equal(row.querySelector('.ocl-debug-empty').textContent,'아직 변수가 없습니다.');
 assert.equal(row.querySelector('.ocl-debug-over').disabled,false,'execution can resume while viewing history');
 row.querySelector('.ocl-debug-latest').fire('click');assert.equal(row.querySelector('.ocl-debug-counter').textContent,'단계 2 / 2');
 Atomics.store(sync,0,0);row.querySelector('.ocl-debug-continue').fire('click');assert.equal(Atomics.load(sync,0),1);
 worker.emit({type:'result',id,error:'ZeroDivisionError',debugEnd:{event:'exception',line:2,function:'<module>',stack:[{function:'<module>',line:2}],locals:null,globals:[{name:'a',type:'int',value:'1'}]}});await flush();
 assert.equal(row.querySelector('.ocl-debug-counter').textContent,'단계 3 / 3');
 assert.match(row.querySelector('.ocl-debug-where').textContent,/2번째 줄에서 오류/);
 assert.equal(row.querySelector('.ocl-debug-stop').hidden,false);assert.equal(row.querySelector('.ocl-debug-close'),null);
 row.querySelector('.ocl-debug-prev').fire('click');assert.equal(row.querySelector('.ocl-debug-counter').textContent,'단계 2 / 3');
 edit(a,'a = 1\nb = a / 1');assert.equal(row.querySelector('.ocl-debug-panel'),null,'editing closes the outdated history');
 a.action('stop');
});
test('a debug run that never pauses leaves no panel behind',async()=>{
 const a=await loaded();const row=cellRows(a)[0];row.querySelector('.ocl-btn-debug').fire('click');await flush();
 const worker=a.workers[0];worker.emit({type:'result',id:worker.sent[1].id,error:'SyntaxError'});await flush();
 assert.equal(row.querySelector('.ocl-debug-panel'),null);assert.equal(row.querySelector('.ocl-code').readOnly,false);a.action('stop');
});
test('stopping a paused debug session removes the panel and unlocks the cell',async()=>{
 const a=await loaded();const row=cellRows(a)[0];row.querySelector('.ocl-btn-debug').fire('click');await flush();
 const worker=a.workers[0];worker.emit({type:'debug-pause',id:worker.sent[1].id,snapshot:{line:1,globals:[]}});
 row.querySelector('.ocl-debug-stop').fire('click');await flush();
 assert.equal(worker.terminated,true);assert.equal(row.querySelector('.ocl-debug-panel'),null);
 assert.equal(row.querySelector('.ocl-code').readOnly,false);
 row.querySelector('.ocl-btn-debug').fire('click');await flush();assert.equal(a.workers.length,2,'a new session can start');a.action('stop');
});

async function finishedDebugReview(a,error) {
 const row=cellRows(a)[0];row.querySelector('.ocl-btn-debug').fire('click');await flush();
 const worker=a.workers[0],id=worker.sent[1].id;
 worker.emit({type:'debug-pause',id,snapshot:{line:1,globals:[]}});
 row.querySelector('.ocl-debug-continue').fire('click');
 worker.emit({type:'stream',id,text:'retained output\n'});
 worker.emit({type:'result',id,error,debugEnd:{
  event:error?'exception':'finished',line:error?2:null,function:'<module>',stack:[],locals:null,
  globals:[{name:'value',type:'int',value:'7'}]
 }});await flush();
 return {row,worker,stopButton:row.querySelector('.ocl-debug-stop')};
}

test('stop closes a finished or failed debug review without rerunning, clearing outputs or terminating the Worker',async()=>{
 for(const error of [undefined,'ZeroDivisionError: division by zero']){
  const a=await loaded();edit(a,error?'value = 7\n1 / 0':'value = 7\nprint(value)');
  const {row,worker,stopButton}=await finishedDebugReview(a,error);
  const textarea=row.querySelector('.ocl-code'),output=row.querySelector('.ocl-output-text');
  const errorOutput=row.querySelector('.ocl-output-error'),sentCount=worker.sent.length;
  assert.equal(stopButton.hidden,false);assert.equal(stopButton.disabled,false);
  assert.equal(stopButton.textContent,'디버깅 중단');
  assert.equal(row.querySelector('.ocl-debug-close'),null);
  stopButton.fire('click');await flush();
  assert.equal(row.querySelector('.ocl-debug-panel'),null);
  assert.equal(row.querySelector('.ocl-code'),textarea);assert.equal(textarea.readOnly,false);
  assert.equal(row.querySelector('.ocl-output-text'),output);assert.equal(output.textContent,'retained output\n');
  assert.equal(row.querySelector('.ocl-output-error'),errorOutput);
  if(error){assert.equal(errorOutput.textContent,error);}
  assert.notEqual(worker.terminated,true);assert.equal(a.workers.length,1);
  assert.equal(worker.sent.length,sentCount,'closing the review submits no new execution');
  a.action('save');
  const saved=JSON.parse(a.requests[1].options.body).cells[0];
  assert.equal(saved.code,textarea.value);
  assert.deepEqual(saved.outputs,[{type:'stream',text:'retained output\n'},...(error?[{type:'error',text:error}]:[])]);
  a.respond(1,{updatedAt:'saved',revision:2});await flush();a.action('stop');
 }
});

test('closing a finished review preserves another cell input and its queued successor',async()=>{
 const a=app();a.respond(0,{revision:1,cells:[
  {id:'a',code:'value = 7',outputs:[]},
  {id:'b',code:'answer = input()',outputs:[]},
  {id:'c',code:'print(answer)',outputs:[]}
 ]});await flush();
 const {row,worker,stopButton}=await finishedDebugReview(a);
 const [,inputRow,queuedRow]=cellRows(a);
 inputRow.querySelector('.ocl-btn-run').fire('click');await flush();
 const inputJob=worker.sent[2];worker.emit({type:'input-request',id:inputJob.id});
 const form=inputRow.querySelector('.ocl-input-request'),input=form.querySelector('input');
 input.value='작성 중인 입력';
 queuedRow.querySelector('.ocl-btn-run').fire('click');await flush();
 assert.equal(worker.sent.length,3,'the successor waits for the active input cell');
 stopButton.fire('click');await flush();
 assert.equal(row.querySelector('.ocl-debug-panel'),null);assert.notEqual(worker.terminated,true);
 assert.equal(inputRow.querySelector('.ocl-input-request'),form);
 assert.equal(form.querySelector('input'),input);assert.equal(input.value,'작성 중인 입력');
 assert.equal(a.document.activeElement,input);
 form.fire('submit',{preventDefault(){}});
 const sync=new Int32Array(worker.sent[0].syncBuffer);
 assert.equal(Atomics.load(sync,0),1);
 assert.equal(new TextDecoder().decode(new Uint8Array(sync.buffer,8,Atomics.load(sync,1))),'작성 중인 입력');
 worker.emit({type:'stream',id:inputJob.id,text:'input accepted\n'});
 worker.emit({type:'result',id:inputJob.id});await flush();
 assert.equal(worker.sent[3].code,'print(answer)');assert.equal(a.workers.length,1);
 worker.emit({type:'stream',id:worker.sent[3].id,text:'queued output\n'});
 worker.emit({type:'result',id:worker.sent[3].id});await flush();
 assert.equal(queuedRow.querySelector('.ocl-output-text').textContent,'queued output\n');
 assert.ok(inputRow.querySelectorAll('.ocl-output-text').some(node=>node.textContent==='input accepted\n'));
 a.action('stop');
});

test('a closed review stop callback cannot stop or unlock a new debug session',async()=>{
 const a=await loaded();const {row,worker,stopButton}=await finishedDebugReview(a);
 stopButton.fire('click');
 row.querySelector('.ocl-btn-debug').fire('click');await flush();
 const nextJob=worker.sent[2];assert.equal(nextJob.type,'debug');
 worker.emit({type:'debug-pause',id:nextJob.id,snapshot:{line:1,globals:[]}});
 const panel=row.querySelector('.ocl-debug-panel');
 stopButton.fire('click');await flush();
 assert.equal(row.querySelector('.ocl-debug-panel'),panel);
 assert.equal(row.querySelector('.ocl-code').readOnly,true);
 assert.notEqual(worker.terminated,true);assert.equal(a.workers.length,1);
 const sync=new Int32Array(worker.sent[0].syncBuffer);Atomics.store(sync,0,0);
 row.querySelector('.ocl-debug-over').fire('click');
 assert.equal(Atomics.load(sync,0),1,'the new pause still accepts its own resume command');
 row.querySelector('.ocl-debug-stop').fire('click');await flush();
 assert.equal(worker.terminated,true,'a resumed, still-live debug run retains force-stop behavior');
 assert.equal(row.querySelector('.ocl-debug-panel'),null);
 assert.equal(row.querySelector('.ocl-code').readOnly,false);
});
