// Disposable localhost browser fixture using the actual shipped Lab and Ace assets.
const http=require('node:http'),fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'..');
const port=Number(process.env.OCL_EDITOR_PORT || 33429);
const origin='http://127.0.0.1:'+port;
const aceRoot=path.resolve(root,'../oj-integration-rebuild/oj-platform/resources/features/ace/vendor');
let notebook={formatVersion:2,revision:1,cells:[
  {id:'a',type:'code',code:'# 파이썬 실습\nname = "코딩판다"\nfor n in range(3):\n    print(name, n)',outputs:[{type:'stream',text:'코딩판다 0\n코딩판다 1\n코딩판다 2\n'}]},
  {id:'b',type:'text',code:'오늘의 기록\n반복문은 같은 작업을 여러 번 실행합니다.\n<tag>도 글자 그대로 보존합니다.',outputs:[]},
  {id:'c',type:'code',code:'print("다음 실습")',outputs:[]},
]};
// Normalized server responses only: archive validation is covered by the PHP suite.
const jsonl=[
  {name:'코딩판다',score:3,meta:{topic:'반복문',tags:['한글','Python']}},
  {name:'학생 둘',score:7,meta:{topic:'조건문',tags:['실습']}},
  {name:'셋째',score:11,meta:{topic:'함수',tags:[]}},
].map(row=>JSON.stringify(row)).join('\n')+'\n';
const csv='name,score\n코딩판다,3\n학생 둘,7\n셋째,11\n';
const datasetFiles=['train/data.jsonl','samples.csv','archive.zip','broken.jsonl','invalid-response.jsonl','report.h5'];
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,origin);
  function send(type,body,status=200){res.writeHead(status,{'Content-Type':type+'; charset=utf-8','Cache-Control':'no-store','Cross-Origin-Opener-Policy':'same-origin','Cross-Origin-Embedder-Policy':'require-corp','Cross-Origin-Resource-Policy':'same-origin'});res.end(body);}
  if(url.pathname==='/favicon.ico')return send('image/x-icon','',204);
  if(url.pathname==='/vendor/markdown-it.js')return send('application/javascript',fs.readFileSync(path.join(root,'resources/vendor/markdown-it/markdown-it.js')));
  if(url.pathname==='/api/contexts')return send('application/json',JSON.stringify([{type:'class',id:'local',label:'로컬 검증'}]));
  if(url.pathname==='/api/contexts/class/local/notebooks')return send('application/json',JSON.stringify({rows:[{userId:1,displayName:'검증 학생',cellCount:notebook.cells.length,updatedAt:null,hasError:false}],nextCursor:''}));
  if(url.pathname==='/api/contexts/class/local/notebooks/1')return send('application/json',JSON.stringify(notebook));
  if(url.pathname==='/api/datasets/recommended')return send('application/json',JSON.stringify([{id:1,source:'upload',label:'추천 CSV',fileName:'samples.csv'},{id:2,source:'upload',label:'추천 JSONL',fileName:'recommended.jsonl'}]));
  if(url.pathname==='/api/datasets/search')return send('application/json',JSON.stringify([{ref:'fixture/formats',title:'파일 형식 실습',subtitle:'CSV·JSONL·ZIP 로컬 검증'}]));
  if(url.pathname==='/api/datasets/files')return send('application/json',JSON.stringify(datasetFiles.map(name=>({name,size:'local fixture'}))));
  if(url.pathname==='/api/datasets/import'||/^\/api\/datasets\/recommended\/[12]\/import$/.test(url.pathname)){
    let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{
      let data;try{data=body?JSON.parse(body):{};}catch{return send('application/json',JSON.stringify({message:'잘못된 요청'}),400);}
      const file=url.pathname.includes('/recommended/1/')?'samples.csv':url.pathname.includes('/recommended/2/')?'recommended.jsonl':data.file;
      if(file==='broken.jsonl')return send('application/json',JSON.stringify({message:'JSONL 2행이 올바른 JSON 객체가 아닙니다.'}),415);
      if(file==='invalid-response.jsonl')return send('application/json',JSON.stringify({fileName:'bad.jsonl',format:'jsonl',content:null}));
      if(file==='samples.csv')return send('application/json',JSON.stringify({fileName:'samples.csv',format:'csv',content:csv}));
      if(['train/data.jsonl','recommended.jsonl','archive.zip'].includes(file))return send('application/json',JSON.stringify({fileName:file==='archive.zip'?'from_zip.jsonl':file.split('/').pop(),format:'jsonl',content:jsonl}));
      return send('application/json',JSON.stringify({message:'지원하지 않는 형식'}),415);
    });return;
  }
  if(url.pathname==='/api/notebook'){
    if(req.method==='GET')return send('application/json',JSON.stringify(notebook));
    let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{
      const data=JSON.parse(body);
      if(data.formatVersion!==2||data.revision!==notebook.revision)return send('application/json',JSON.stringify({message:'새로고침이 필요합니다.'}),409);
      notebook={...data,revision:data.revision+1,updatedAt:new Date().toISOString()};send('application/json',JSON.stringify(notebook));
    });return;
  }
  if(url.pathname.startsWith('/assets/')){
    const name=path.basename(url.pathname),file=path.join(root,'resources',name.endsWith('.css')?'css':'js',name);
    if(fs.existsSync(file))return send(name.endsWith('.css')?'text/css':'application/javascript',fs.readFileSync(file));
  }
  if(url.pathname.startsWith('/ace/')){
    const file=path.join(aceRoot,path.basename(url.pathname));
    if(fs.existsSync(file))return send('application/javascript',fs.readFileSync(file));
  }
  if(url.pathname==='/teacher')return send('text/html',`<!doctype html><html lang="ko"><meta charset="utf-8"><title>교사 노트북 — 로컬 검증</title><link rel="stylesheet" href="/assets/console.css"><link rel="stylesheet" href="/assets/markdown.css"><body><div class="ocl-console" data-ocl-dashboard data-config='{"restUrl":"/api","nonce":"local-only"}'><div data-field="messages"></div><select data-field="context" aria-label="학급"></select><div data-field="roster-panel" hidden><table><tbody data-field="roster-body"></tbody></table></div><div data-field="viewer-panel" hidden><h2 data-field="viewer-title"></h2><button data-action="close-viewer">닫기</button><div data-field="viewer-cells"></div></div></div><script src="/vendor/markdown-it.js"></script><script src="/assets/markdown.js"></script><script src="/assets/dashboard.js"></script></body></html>`);
  if(url.pathname!=='/')return send('text/plain','not found',404);
  const theme=url.searchParams.get('theme')==='chrome'?'chrome':'monokai';
  const real=url.searchParams.get('real')==='1';
  const config={restUrl:'/api',nonce:'local-only',editor:{available:true,theme,fontSize:18,baseUrl:origin+'/ace/',coreUrl:origin+'/ace/ace.js'}};
  if(real)config.workerUrl='/assets/pyodide-worker.js';
  if(url.searchParams.has('fallback'))config.editor.available=false;
  const buttons=[['run-all','전체 실행'],['add-cell','+ 코드 셀'],['add-text-cell','+ 텍스트 셀'],['stop','실행 중단'],['restart','런타임 재시작'],['download','내 노트북 내려받기'],['reload','최신 노트북 불러오기'],['toggle-datasets','데이터 불러오기'],['save','저장']];
  send('text/html',`<!doctype html><html lang="ko"><meta charset="utf-8"><title>코드 실습실 — 로컬 검증</title><link rel="stylesheet" href="/assets/lab.css"><link rel="stylesheet" href="/assets/markdown.css"><style>body{margin:0;background:#f6f8fb;font-family:system-ui,sans-serif}.ocl-lab{background:white}button,textarea{font-family:inherit}</style><body><div class="ocl-lab" data-ocl-lab data-config='${JSON.stringify(config)}'><div class="ocl-banner"><div><h1>코드 실습실</h1><p>Python 코드와 텍스트 기록을 함께 저장합니다.</p></div></div><div class="ocl-toolbar">${buttons.map(([action,label])=>`<button type="button" class="ocl-btn" data-action="${action}">${label}</button>`).join('')}<span data-field="status" role="status"></span></div><div data-field="datasets-panel" hidden><div data-field="recommended-list"></div><form data-field="search-form"><input data-field="search-input"></form><div data-field="search-results"></div><div data-field="files-section"><div data-field="files-title"></div><div data-field="files-list"></div></div></div><div data-field="cells"></div></div>${real ? '<script src="/assets/worker-runtime.js"></script>' : "<script>window.OclWorkerRuntime=function(){this.stop=function(){};this.run=function(id,code,output,begin){begin();output('로컬 UI 실행 확인\\\\n');return Promise.resolve({});};};</script>"}<script src="/vendor/markdown-it.js"></script><script src="/assets/markdown.js"></script><script src="/assets/text-cell.js"></script><script src="/assets/cell-editor.js"></script><script src="/assets/lab.js"></script></body></html>`);
});
server.listen(port,'127.0.0.1',()=>console.log('Editor browser fixture: '+origin+' (?real=1 uses the shipped Python worker)'));
