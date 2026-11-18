import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const require=createRequire(path.join(root,'_notes/readcontents-server-import-2026-09-12/editor-source/package.json'));
const {chromium,expect}=require('@playwright/test');
const assets=path.join(root,'readcontents-library/assets/editor');
const out=path.join(root,'readcontents-content/network-curriculum/review-toc/editor-screenshots');
fs.mkdirSync(out,{recursive:true});
const node=(id,type,title,parentId=null)=>({id,slug:id,type,title,parentId,order:0,status:'published',contentType:'default',schemaVersion:'1.0.0',metadata:{}});
const topic=node('topic','topic','네트워크 교재'),chapter=node('chapter','chapter','OSI의 일곱 질문','topic'),section=node('section','section','일곱 관점에서 읽기','chapter'),lesson=node('lesson','lesson','G01 OSI 7계층','section');
const tree={topic,chapters:[{chapter,sections:[{section,lessons:[lesson]}]}]};
const counters={topics:0,chapters:0,sections:0,lessons:0};
const preview={topic,created:counters,reused:{...counters,topics:1,chapters:1,sections:1},conflicts:[],containerTitles:[
  {sourceId:'chapter',type:'chapter',existingId:'chapter',existingTitle:chapter.title,incomingTitle:'OSI 7계층과 TCP/IP 계층 구조'},
  {sourceId:'section',type:'section',existingId:'section',existingTitle:section.title,incomingTitle:'OSI 7계층별 기능과 데이터 전달'},
]};
const result={topic,idMap:{topic:'topic',chapter:'chapter',section:'section',lesson:'lesson'},created:counters,reused:preview.reused,replaced:{...counters,chapters:1,sections:1},skipped:{...counters,lessons:1}};
const doc={lessonId:'lesson',schemaVersion:'1.0.0',revision:1,plateValue:[{type:'p',children:[{text:'보존할 본문'}]}],assets:[],visuals:[]};
const pkg={kind:'contents-editor-package',packageVersion:'1.0.0',exportedAt:'2026-11-12',scope:'topic',tree,lessonDocuments:{lesson:doc}};
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname==='/'){
    res.setHeader('Content-Type','text/html; charset=utf-8');
    res.end(`<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/admin.css"><link rel="stylesheet" href="/workspace-layout.css"></head><body style="margin:0"><div id="contents-editor-admin-root" class="rcis-editor-root" data-rest-url="/rest/" data-rest-namespace="readcontents-library/v1" data-rest-nonce="test" data-can-publish="1" data-can-import-server="1"></div><div id="contents-editor-overlay-root"></div><script type="module" src="/admin.js"></script></body></html>`);return;
  }
  const file=path.resolve(assets,url.pathname.slice(1));
  if(!file.startsWith(assets+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.writeHead(404).end();return;}
  res.setHeader('Content-Type',{'.js':'text/javascript','.css':'text/css','.woff2':'font/woff2'}[path.extname(file)]??'application/octet-stream');
  fs.createReadStream(file).pipe(res);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
try{
  for(const width of [1200,390])for(const mode of ['json','zip','server']){
    const page=await browser.newPage({viewport:{width,height:900}}),requests=[],errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    await page.route('**/rest/**',async route=>{
      const req=route.request(),url=new URL(req.url());
      const send=data=>route.fulfill({contentType:'application/json',body:JSON.stringify(data)});
      if(url.pathname.endsWith('/topics'))return send([tree]);
      if(url.pathname.endsWith('/nodes'))return send([]);
      if(url.pathname.endsWith('/document'))return send(doc);
      if(url.pathname.endsWith('/packages/server'))return send({directory:'/private/readcontents-imports',files:[{name:'course.zip',bytes:123,modified:1}],error:null,job:null});
      if(url.pathname.endsWith('/packages/server/preview'))return send({fileName:'course.zip',fingerprint:'fixture',preview});
      if(url.pathname.endsWith('/preview')||url.pathname.endsWith('/preview-zip'))return send(preview);
      if(url.pathname.endsWith('/import')||url.pathname.endsWith('/import-zip')){requests.push(req.postData());return send(result);}
      if(url.pathname.endsWith('/packages/server/start')){
        requests.push(req.postData());return send({id:'job',fileName:'course.zip',status:'completed',cursor:1,canControl:true,current:'',error:null,assets:{done:0,total:0},content:{done:1,total:1},result});
      }
      throw Error('Unexpected route: '+url.pathname);
    });
    await page.goto(`http://127.0.0.1:${server.address().port}`);
    await page.getByRole('article',{name:topic.title,exact:true}).waitFor();
    await page.getByRole('button',{name:'보조 기능 열기',exact:true}).click();
    await page.getByRole('menuitem',{name:'패키지 가져오기/내보내기',exact:true}).click();
    if(mode==='server')await page.getByRole('button',{name:'미리보기',exact:true}).click();
    else await page.locator('input[type="file"][accept*=".json"]').setInputFiles({name:'course.'+mode,mimeType:mode==='json'?'application/json':'application/zip',buffer:mode==='json'?Buffer.from(JSON.stringify(pkg)):Buffer.from('fixture-zip')});
    const checkbox=page.getByRole('checkbox',{name:'기존 주제·챕터·장 제목 갱신 (2개)',exact:true});
    await expect(checkbox).not.toBeChecked();
    await page.getByText('목차 제목 변경 내역',{exact:true}).click();
    await expect(page.getByText('현재: OSI의 일곱 질문',{exact:true})).toBeVisible();
    await expect(page.getByText('변경: OSI 7계층과 TCP/IP 계층 구조',{exact:true})).toBeVisible();
    await checkbox.check();
    await checkbox.scrollIntoViewIfNeeded();
    await page.screenshot({path:path.join(out,`${mode}-${width}.png`)});
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth<=1));
    if(mode==='server')await page.getByRole('button',{name:'서버 ZIP 가져오기 시작',exact:true}).click();
    else await page.getByRole('button',{name:'선택한 방식으로 가져오기',exact:true}).click();
    await expect.poll(()=>requests.length).toBe(1);
    if(mode==='zip')assert.match(requests[0],/name="updateContainerTitles"\r\n\r\ntrue/);
    else assert.equal(JSON.parse(requests[0]).options.updateContainerTitles,true);
    assert.deepEqual(errors,[]);
    console.log('PASS',mode,width,'title comparison, unchecked default, explicit request option, layout');
    await page.close();
  }
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
