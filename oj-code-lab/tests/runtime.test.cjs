const {test}=require('node:test');
const assert=require('node:assert/strict');
const Runtime=require('../resources/js/worker-runtime.js');
const flush=()=>new Promise(r=>setImmediate(r));
function setup(autoReady=true,extra={}) {
 const workers=[];
 const runtime=new Runtime({url:'worker.js',indexUrl:'https://example.invalid/',initTimeout:100,...extra,workerFactory:()=>{
  const w={sent:[],terminated:false,postMessage(data){this.sent.push(data);if(data.type==='init'&&autoReady){queueMicrotask(()=>this.onmessage({data:{type:'ready'}}));}},terminate(){this.terminated=true;},emit(data){this.onmessage({data});}};
  workers.push(w);return w;
 }});
 return {runtime,workers};
}
test('runs execute sequentially and stale result cannot resolve next job',async()=>{
 const {runtime:r,workers:w}=setup();const a=r.run('a','A');const b=r.run('b','B');await flush();
 assert.deepEqual(w[0].sent.map(x=>x.type),['init','run']);const id=w[0].sent[1].id;
 w[0].emit({type:'result',id});await a;await flush();assert.equal(w[0].sent[2].code,'B');
 let done=false;b.then(()=>done=true);w[0].emit({type:'result',id});await flush();assert.equal(done,false);
 w[0].emit({type:'result',id:w[0].sent[2].id});await b;r.stop();
});
test('stop terminates running Worker and cancels queued jobs',async()=>{
 const {runtime:r,workers:w}=setup();const results=Promise.allSettled([r.run('a','while True: pass'),r.run('b','B')]);await flush();r.stop();
 assert.equal(w[0].terminated,true);assert.ok((await results).every(x=>x.status==='rejected'));assert.equal(w[0].sent.filter(x=>x.type==='run').length,1);
 const next=r.run('c','C');await flush();assert.equal(w.length,2);w[1].emit({type:'result',id:w[1].sent[1].id});await next;r.stop();
});
test('stop during initialization rejects active and queued requests',async()=>{
 const {runtime:r,workers:w}=setup(false);const results=Promise.allSettled([r.run('a','A'),r.run('b','B')]);r.stop();
 assert.ok((await results).every(x=>x.status==='rejected'));assert.equal(w[0].terminated,true);
});
test('initialization timeout terminates the stuck Worker',async()=>{
 const {runtime:r,workers:w}=setup(false,{initTimeout:10});await assert.rejects(r.run('a','A'),/초과/);assert.equal(w[0].terminated,true);
});
test('post-initialization Worker crash rejects pending operations',async()=>{
 const {runtime:r,workers:w}=setup();const result=assert.rejects(r.run('a','A'));await flush();w[0].onerror({});await result;assert.equal(w[0].terminated,true);
});
test('writeFile waits for acknowledgement before run',async()=>{
 const {runtime:r,workers:w}=setup();const file=r.writeFile('../data.csv',new Uint8Array([1,2]));const run=r.run('a','read');await flush();
 assert.equal(w[0].sent[1].type,'writeFile');assert.equal(w[0].sent[1].fileName,'data.csv');
 w[0].emit({type:'ack',id:w[0].sent[1].id,filePath:'/home/pyodide/ocl-datasets/data.csv'});assert.equal(await file,'/home/pyodide/ocl-datasets/data.csv');await flush();assert.equal(w[0].sent[2].type,'run');w[0].emit({type:'result',id:w[0].sent[2].id});await run;r.stop();
});
test('stdin response belongs only to its current request',async()=>{
 let firstInput;const {runtime:r,workers:w}=setup(true,{sharedInput:true,onInput:(cell,submit)=>firstInput=submit});
 const run=r.run('a','input()');await flush();const id=w[0].sent[1].id;w[0].emit({type:'input-request',id});firstInput('한글');assert.equal(Atomics.load(r.sync,0),1);
 w[0].emit({type:'result',id});await run;Atomics.store(r.sync,0,0);firstInput('stale');assert.equal(Atomics.load(r.sync,0),0);r.stop();
});

// Execute the shipped Worker script with only the Pyodide API stubbed. Check
// every real FS destination rather than mirroring a path helper in the test.
function workerScript() {
 const vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
 const messages=[],files=new Map(),directories=[];
 const self={postMessage:data=>messages.push(data)};
 const pyodide={setStdin(){},runPythonAsync:()=>Promise.resolve(),FS:{
  mkdirTree:directory=>directories.push(directory),
  writeFile:(name,bytes)=>files.set(name.startsWith('/')?name:'/home/pyodide/'+name,Array.from(bytes))
 }};
 self.loadPyodide=()=>Promise.resolve(pyodide);
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../resources/js/pyodide-worker.js'),'utf8'),{
  self,importScripts(){},loadPyodide:()=>Promise.resolve(pyodide),TextEncoder,TextDecoder,Atomics,console
 });
 return {self,messages,files,directories};
}
test('data file named pandas.py is written outside Python default module lookup paths',async()=>{
 const w=workerScript();w.self.onmessage({data:{type:'init',indexUrl:'https://example.invalid/'}});await flush();
 assert.ok(w.messages.some(x=>x.type==='ready'));
 const bytes=new TextEncoder().encode('raise RuntimeError("DATA_FILE_EXECUTED")');
 w.self.onmessage({data:{type:'writeFile',id:2,fileName:'../../pandas.py',bytes:bytes.buffer}});await flush();
 assert.equal(w.files.has('/home/pyodide/pandas.py'),false);
 assert.deepEqual(w.files.get('/home/pyodide/ocl-datasets/pandas.py'),Array.from(bytes));
 assert.equal(w.messages.find(x=>x.id===2).filePath,'/home/pyodide/ocl-datasets/pandas.py');
});
test('worker rejects empty, traversal-only and NUL data filenames without writes',async()=>{
 const w=workerScript();w.self.onmessage({data:{type:'init',indexUrl:'https://example.invalid/'}});await flush();
 for(const [i,name] of ['', '..', 'folder/.', 'bad\0.csv'].entries()) {
  w.self.onmessage({data:{type:'writeFile',id:10+i,fileName:name,bytes:new ArrayBuffer(0)}});await flush();
  assert.equal(w.messages.find(x=>x.id===10+i).failed,true);
 }
 assert.equal(w.files.size,0);
});
test('writeFile rejects an acknowledgement that points outside the data directory',async()=>{
 const {runtime:r,workers:w}=setup();const failure=assert.rejects(r.writeFile('pandas.py',new Uint8Array([1])),/저장 경로/);await flush();
 w[0].emit({type:'ack',id:w[0].sent[1].id,filePath:'/home/pyodide/pandas.py'});await failure;r.stop();
});
test('debug pauses hand a snapshot to the UI and resume writes only one command',async()=>{
 let paused=null;const {runtime:r,workers:w}=setup(true,{sharedInput:true});
 const run=r.debug('a','x = 1',()=>{},()=>{},(snapshot,resume)=>paused={snapshot,resume});await flush();
 assert.equal(w[0].sent[1].type,'debug');const id=w[0].sent[1].id;
 w[0].emit({type:'debug-pause',id,snapshot:{line:1,globals:[]}});assert.equal(paused.snapshot.line,1);
 assert.equal(paused.resume({action:'over',breakpoints:[]}),true);assert.equal(Atomics.load(r.sync,0),1);
 const length=Atomics.load(r.sync,1);
 assert.deepEqual(JSON.parse(new TextDecoder().decode(new Uint8Array(r.sync.buffer,8,length).slice())),{action:'over',breakpoints:[]});
 Atomics.store(r.sync,0,0);assert.equal(paused.resume({action:'step'}),false,'a pause is answered once');assert.equal(Atomics.load(r.sync,0),0);
 w[0].emit({type:'result',id});await run;r.stop();
});
test('debug is refused up front without cross-origin isolation',async()=>{
 const {runtime:r,workers:w}=setup(true,{sharedInput:false});
 await assert.rejects(r.debug('a','x = 1',()=>{},()=>{},()=>{}),/cross-origin isolation/);assert.equal(w.length,0);
});
test('a debug pause from a plain run is ignored',async()=>{
 let paused=false;const {runtime:r,workers:w}=setup(true,{sharedInput:true});
 const run=r.run('a','x = 1');await flush();const id=w[0].sent[1].id;
 w[0].emit({type:'debug-pause',id,snapshot:{line:1}});assert.equal(r.waitingDebug,null);
 w[0].emit({type:'result',id});await run;r.stop();
});
test('debug sends initial breakpoints and resolves with the end snapshot',async()=>{
 const {runtime:r,workers:w}=setup(true,{sharedInput:true});
 const run=r.debug('a','x = 1',()=>{},()=>{},()=>{},[2,5]);await flush();
 assert.deepEqual(w[0].sent[1].breakpoints,[2,5]);
 w[0].emit({type:'result',id:w[0].sent[1].id,debugEnd:{event:'finished',globals:[]}});
 assert.equal((await run).debugEnd.event,'finished');r.stop();
});
test('package loading is quiet but keeps Pyodide integrity checks',async()=>{
 const vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');const calls=[];
 const pyodide={setStdin(){},runPythonAsync:()=>Promise.resolve('{"error":null,"images":[]}'),globals:{set(){}},
  loadPackagesFromImports:(code,options)=>{calls.push(options);return Promise.resolve();},loadPackage:(names,options)=>{calls.push(options);return Promise.resolve();}};
 const messages=[],self={postMessage:m=>messages.push(m),loadPyodide:()=>Promise.resolve(pyodide)};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../resources/js/pyodide-worker.js'),'utf8'),{self,importScripts(){},TextEncoder,TextDecoder,Atomics,console});
 self.onmessage({data:{type:'init',indexUrl:'x/'}});await flush();
 self.onmessage({data:{type:'run',id:3,code:'import seaborn'}});await flush();await flush();
 assert.equal(calls.length,2);
 for(const options of calls){assert.equal(options.checkIntegrity,true);assert.equal(typeof options.messageCallback,'function');assert.equal(options.errorCallback,undefined,'errors still reach the console');}
 assert.ok(messages.some(m=>m.type==='result'&&m.id===3));
});
