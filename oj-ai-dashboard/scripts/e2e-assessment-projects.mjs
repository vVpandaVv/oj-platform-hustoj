import assert from 'node:assert/strict';
import {readFile,mkdir} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {createServer} from 'node:http';
import {setTimeout as delay} from 'node:timers/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {loadPlaywright,browserLaunchOptions} from './lib/playwright-runtime.mjs';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const serveOnly=process.argv.includes('--serve');
const html=(query,project)=>{
  const markup=execFileSync(process.env.OJAI_PHP||'php',['tests/fixtures/render-workflow-page.php','projects',JSON.stringify(query),JSON.stringify(project||{})],{cwd:root,encoding:'utf8'});
  return `<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Assessment projects</title><link rel="stylesheet" href="/resources/css/admin-shell.css"><link rel="stylesheet" href="/resources/css/assessment-projects.css"><link rel="stylesheet" href="/resources/css/worksheet-workflow.css"><body>${markup}<script src="/resources/js/worksheet-workflow.js"></script><script src="/resources/js/assessment-projects.js"></script></body></html>`;
};
const projects=new Map(),worksheets=new Map(),requests=[];let sequence=100;
const id=()=> '01K'+String(sequence++).padStart(23,'0');
const empty=()=>({learning_goals:[],achievement_standards:[],criteria:[],total_points:'0'});
const legacy={id:id(),current_revision_id:id(),title:'Existing worksheet',state:'draft'};worksheets.set(legacy.id,legacy);
let failSave=false, holdSave=false, releaseSave=null, failCommittedSave=false;
let failLoad=true, holdCreate=true, releaseCreate=null;
if(serveOnly){failLoad=false;holdCreate=false;}
const held=async getRelease=>{
  const deadline=Date.now()+10000;
  while(!getRelease()&&Date.now()<deadline)await delay(10);
  assert.equal(typeof getRelease(),'function','Expected the server to hold a command');
};
const saveReceipts=new Map();
const server=createServer(async(req,res)=>{
  try{
    const url=new URL(req.url,'http://localhost');
    if(url.pathname.startsWith('/wp-json/oj-ai/v1/')){
      const route=url.pathname.replace('/wp-json/oj-ai/v1','');let input=null;
      if(req.method==='POST'){let text='';for await(const chunk of req)text+=chunk;input=JSON.parse(text);assert.equal(req.headers['x-wp-nonce'],'workflow-test-nonce');assert.equal(req.headers['idempotency-key'],input.command_id);}
      requests.push({route,method:req.method,input});
      if(failLoad&&route==='/assessment-projects'&&req.method==='GET'){
        failLoad=false;res.writeHead(503,{'Content-Type':'application/json'}).end(JSON.stringify({message:'평가 목록을 불러오지 못했습니다.'}));return;
      }
      if(route.endsWith('/revisions')&&saveReceipts.has(input?.command_id)){
        res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({ok:true,data:saveReceipts.get(input.command_id)}));return;
      }
      let data={},status=200;
      if(route==='/assessment-projects'&&req.method==='GET')data={projects:[...projects.values()].filter(p=>p.archived===(url.searchParams.get('archived')==='1')).map(p=>({...p,worksheet_count:p.worksheets.length}))};
      else if(route==='/assessment-projects'){
        if(holdCreate){holdCreate=false;await new Promise(resolve=>{releaseCreate=resolve;});releaseCreate=null;}
        const source=projects.get(input.payload.copy_from);const configuration=source?structuredClone(source.configuration):empty();
        if(source){for(const g of configuration.learning_goals)g.goal_id=id();for(const s of configuration.achievement_standards)s.standard_id=id();for(const c of configuration.criteria){c.criterion_id=id();for(const l of c.levels)l.level_key=id();}}
        const p={project_id:id(),title:input.payload.title,revision_id:id(),revision_no:1,state:'draft',archived:false,lock_version:0,content_hash:'a'.repeat(64),rubric_content_hash:'b'.repeat(64),configuration,worksheets:[],history:[]};p.current_revision_id=p.revision_id;projects.set(p.project_id,p);data={project:p};status=201;
      }else if(route==='/worksheets')data={items:[...worksheets.values()]};
      else if(/^\/worksheets\/[^/]+$/.test(route))data={worksheet:worksheets.get(route.split('/')[2])};
      else {
        const p=projects.get(route.split('/')[2]);if(!p){res.writeHead(404,{'Content-Type':'application/json'}).end(JSON.stringify({message:'Not found'}));return;}
        if(route.endsWith('/archive')){
          assert.equal(input.expected.lock_version,p.lock_version);assert.equal(typeof input.payload.archived,'boolean');
          p.archived=input.payload.archived;p.lock_version++;
        }else if(route.endsWith('/revisions')){
          if(failSave){res.writeHead(409,{'Content-Type':'application/json'}).end(JSON.stringify({message:'다른 창에서 평가 기준이 변경되었습니다.'}));return;}
          assert.equal(input.expected.revision_id,p.current_revision_id);Object.assign(p,input.payload,{revision_id:id(),revision_no:p.revision_no+1,lock_version:p.lock_version+1});p.current_revision_id=p.revision_id;p.history.unshift({revision_id:p.revision_id,revision_no:p.revision_no,state:p.state,created_at_utc:'2026-09-07 12:00:00'});
        }else if(route.includes('/worksheets')){
          assert.equal(p.state,'confirmed');assert.equal(input.expected.revision_id,p.revision_id);
          const source=worksheets.get(input.payload.worksheet_id);const w={id:id(),current_revision_id:id(),title:source?source.title+' copy':input.payload.title,state:'draft'};
          worksheets.set(w.id,w);p.worksheets.push({worksheet_id:w.id,title:w.title,state:w.state,revision_id:w.current_revision_id,criteria_revision_no:p.revision_no,project_revision_id:p.revision_id});data={worksheet_id:w.id,project_id:p.project_id};
        }
        if(!data.worksheet_id)data={project:p};
      }
      if(route.endsWith('/revisions')){
        data=structuredClone(data);saveReceipts.set(input.command_id,data);
        if(holdSave){holdSave=false;await new Promise(resolve=>{releaseSave=resolve;});releaseSave=null;}
        if(failCommittedSave){failCommittedSave=false;res.writeHead(503,{'Content-Type':'application/json'}).end(JSON.stringify({message:'저장 응답을 확인하지 못했습니다. 다시 시도해 주세요.'}));return;}
      }
      res.writeHead(status,{'Content-Type':'application/json'}).end(JSON.stringify({ok:true,data,meta:{next_cursor:null}}));return;
    }
    if(url.pathname.includes('/oj-console/')){res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html(Object.fromEntries(url.searchParams),projects.get(url.searchParams.get('project_id'))));return;}
    const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));if(!file.startsWith(root+path.sep)){res.writeHead(403).end();return;}
    res.setHeader('Content-Type',({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'}[path.extname(file)]||'application/octet-stream'));res.end(await readFile(file));
  }catch(error){res.writeHead(500).end(String(error.stack));}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
if(serveOnly){
  console.log(`Local in-memory project fixture: http://127.0.0.1:${server.address().port}/oj-console/addons/oj-ai-dashboard/projects`);
  await new Promise(()=>{});
}
const {chromium}=await loadPlaywright();const browser=await chromium.launch({...browserLaunchOptions(),headless:true});const page=await browser.newPage({viewport:{width:1440,height:1000}});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
const base=`http://127.0.0.1:${server.address().port}/oj-console/addons/oj-ai-dashboard/projects`;
const artifacts=path.join(root,'tests/artifacts/assessment-projects');await mkdir(artifacts,{recursive:true});
const action=name=>page.locator(`[data-action="${name}"]`);
const fit=async()=>{assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Page overflow');assert.equal(await page.evaluate(()=>[...document.querySelectorAll('input,textarea,select,button')].filter(e=>e.getClientRects().length).some(e=>e.getBoundingClientRect().right>innerWidth+1)),false,'Control overflow');};
try{
  await page.goto(base);
  await page.getByText('평가 목록을 불러오지 못했습니다.',{exact:true}).waitFor();
  await action('reload').click();await action('new').click();
  await page.locator('dialog input').fill('Evidence assessment');await page.locator('dialog [type=submit]').click();
  await held(()=>releaseCreate);
  assert.equal(await page.locator('dialog').getAttribute('aria-busy'),'true');
  assert.equal(await page.locator('dialog button:enabled,dialog input:enabled').count(),0,'Pending commands disable dialog controls');
  await page.keyboard.press('Escape');assert.equal(await page.locator('dialog').evaluate(node=>node.open),true,'Escape must not abandon a pending command');
  releaseCreate();
  await page.waitForURL(/project_id=/);const projectId=new URL(page.url()).searchParams.get('project_id');
  await action('add-goal').waitFor();
  assert.equal(await page.locator('[data-workspace-navigation]').count(),0,'No duplicate global track inside an evaluation');
  assert.equal(await page.locator('[data-project-worksheet-select], [data-action="new-worksheet"], [data-action="copy-worksheet"]').count(),0,'Criteria must not expose worksheet creation or selection');
  await action('add-goal').click();await page.locator('[data-kind=goal]').fill('Explain a claim using evidence.');
  await action('add-standard').click();await page.locator('[data-kind=standard][data-prop=text]').fill('Compare sources and support a reasoned conclusion.');
  await action('add-criterion').click();await page.locator('[data-kind=criterion][data-prop=label]').fill('Evidence use');await page.locator('[data-kind=criterion][data-prop=description]').fill('Evaluate the connection between observations and claims.');
  await page.locator('[data-kind=level][data-prop=label]').nth(0).fill('Supports the claim with relevant evidence.');await page.locator('[data-kind=level][data-prop=label]').nth(1).fill('No supporting evidence.');
  const longDescription='가'.repeat(249)+'\n'+'나'.repeat(250);
  assert.equal(await page.locator('[data-kind=level][data-prop=label]').first().getAttribute('maxlength'),'500');
  for(let i=0;i<5;i++)await action('add-level').click();
  for(let i=0;i<7;i++){
    await page.locator('[data-kind=level][data-prop=label]').nth(i).fill(i===0?longDescription:`Observable performance ${i+1}`);
    await page.locator('[data-kind=level][data-prop=points]').nth(i).fill(String(20-i*2));
  }
  await page.screenshot({path:path.join(artifacts,'criteria-desktop.png'),fullPage:true});await fit();
  await page.setViewportSize({width:390,height:844});await fit();await page.screenshot({path:path.join(artifacts,'criteria-mobile.png'),fullPage:true});
  await action('confirm').click();await page.waitForURL(u=>u.searchParams.get('stage')==='author');
  const savedLevels=projects.get(projectId).configuration.criteria[0].levels;
  assert.equal(savedLevels.length,7);assert.equal(savedLevels[0].label,longDescription);
  assert.deepEqual(savedLevels.map(l=>Number(l.points)),[20,18,16,14,12,10,8]);
  for(const title of ['Source analysis','Written conclusion']){
    const previous=new URL(page.url()).searchParams.get('worksheet_id');
    await action('new-worksheet').click();await page.locator('[data-project-dialog] input').fill(title);await page.locator('[data-project-dialog] [type=submit]').click();await page.waitForURL(u=>u.pathname.endsWith('/projects')&&u.searchParams.get('worksheet_id')!==previous);
    await page.getByRole('combobox',{name:'현재 평가의 활동지'}).waitFor();
  }
  const beforeCopy=new URL(page.url()).searchParams.get('worksheet_id');
  await action('copy-worksheet').click();await page.locator('[data-project-dialog] select').selectOption('0');await page.locator('[data-project-dialog] [type=submit]').click();await page.waitForURL(u=>u.pathname.endsWith('/projects')&&u.searchParams.get('worksheet_id')!==beforeCopy);
  assert.equal(projects.get(projectId).worksheets.length,3);assert.equal(worksheets.get(legacy.id).title,'Existing worksheet');
  await page.getByRole('combobox',{name:'현재 평가의 활동지'}).waitFor();
  assert.equal(await page.locator('[data-project-content] .table').count(),0,'No second worksheet-selection track');
  const selectedId=new URL(page.url()).searchParams.get('worksheet_id');
  const scopeContext={type:'class',id:'class:30',revision:'test-context-revision'};
  await page.evaluate(({context,assignmentId,publicationId})=>window.OJAIWorkflow.setSelection({context,assignmentId,publicationId,publicationHash:'a'.repeat(64)}),{context:scopeContext,assignmentId:id(),publicationId:id()});
  const nextWorksheet=projects.get(projectId).worksheets.find(w=>w.worksheet_id!==selectedId).worksheet_id;
  await page.getByRole('combobox',{name:'현재 평가의 활동지'}).selectOption(nextWorksheet);
  await page.waitForURL(u=>u.searchParams.get('worksheet_id')===nextWorksheet);
  let changedUrl=new URL(page.url());
  assert.equal(changedUrl.searchParams.get('context_id'),scopeContext.id,'Switching worksheets retains the selected class');
  assert.equal(changedUrl.searchParams.get('context_revision'),scopeContext.revision);
  for(const key of ['assignment_id','publication_id','publication_hash'])assert.equal(changedUrl.searchParams.has(key),false,'Changing worksheets clears stale deployment identifiers');
  await page.getByRole('combobox',{name:'현재 평가의 활동지'}).selectOption(selectedId);
  await page.waitForURL(u=>u.searchParams.get('worksheet_id')===selectedId);
  for(const stage of ['review','assignment','assessment','report','release']){
    await page.locator('[data-workflow-select]').selectOption(stage);await page.waitForURL(u=>u.searchParams.get('stage')===stage);
    await page.getByRole('combobox',{name:'현재 평가의 활동지'}).waitFor();
    assert.equal(new URL(page.url()).pathname,new URL(base).pathname);
    assert.equal(new URL(page.url()).searchParams.get('worksheet_id'),selectedId);
    assert.equal(new URL(page.url()).searchParams.get('context_id'),scopeContext.id,'Seven workflow stages retain the selected class');
    assert.equal(await page.locator('[data-oj-ai-workflow]').count(),1);
    assert.equal(await page.locator('[data-workspace-navigation], [data-action="new-worksheet"], [data-action="copy-worksheet"]').count(),0,'Later stages must contain neither duplicate navigation nor authoring commands');
    assert.equal(await page.locator('[data-project-stage-body] > *').count(),1);
    assert.equal(await page.locator(stage==='review'?'#oj-ai-worksheet-authoring-app':stage==='assignment'?'#oj-ai-assignment-app':'#oj-ai-submission-review-app').count(),1);
  }
  await page.setViewportSize({width:1440,height:1000});await page.locator('[data-workflow-step=criteria]').click();await action('copy').click();await page.locator('[data-project-dialog] input').fill('Independent assessment');await page.locator('[data-project-dialog] [type=submit]').click();await page.waitForURL(u=>u.searchParams.get('project_id')!==projectId);
  const copiedId=new URL(page.url()).searchParams.get('project_id');await page.locator('[data-kind=goal]').fill('Different goal.');await action('save').click();await page.getByText('평가 기준 초안을 저장했습니다.',{exact:true}).waitFor();
  assert.equal(new URL(page.url()).searchParams.has('context_id'),false,'A copied independent evaluation does not inherit a class selection');
  assert.notEqual(projects.get(copiedId).configuration.learning_goals[0].goal_id,projects.get(projectId).configuration.learning_goals[0].goal_id);assert.equal(projects.get(projectId).configuration.learning_goals[0].text,'Explain a claim using evidence.');assert.equal(projects.get(copiedId).worksheets.length,0);
  const copiedUrl=page.url(), criteriaBeforeArchive=projects.get(copiedId).revision_no;
  await action('archive').click();await page.getByRole('button',{name:'취소',exact:true}).click();
  assert.equal(projects.get(copiedId).archived,false,'Cancelling archival changed the project');
  await action('archive').click();await page.locator('[data-project-dialog] [type=submit]').click();
  await page.getByText('평가를 보관했습니다.',{exact:true}).waitFor();
  assert.equal(projects.get(copiedId).revision_no,criteriaBeforeArchive,'Archive must not create a criteria revision');
  await page.getByRole('link',{name:'보관함',exact:true}).click();
  await page.locator('[data-action=restore]').waitFor();
  assert.equal(await page.getByRole('link',{name:'Independent assessment',exact:true}).count(),1);
  await page.getByRole('radio',{name:'활성 평가',exact:true}).check();
  await page.getByText('활성 평가 1개',{exact:true}).waitFor();
  assert.equal(await page.getByRole('link',{name:'Independent assessment',exact:true}).count(),0);
  await page.getByRole('radio',{name:'보관함',exact:true}).check();
  await page.getByText('보관된 평가 1개',{exact:true}).waitFor();
  await action('restore').click();await page.locator('[data-project-dialog] [type=submit]').click();
  await page.getByText('평가를 활성 목록으로 복원했습니다.',{exact:true}).waitFor();
  assert.equal(projects.get(copiedId).archived,false);
  assert.equal(projects.get(copiedId).revision_no,criteriaBeforeArchive);
  await page.goto(copiedUrl);await action('save').waitFor();
  holdSave=true;
  await page.locator('[data-kind=goal]').fill('Goal at save time.');await action('save').click();
  await page.waitForFunction(()=>document.querySelector('[data-action=save]').disabled);
  await page.locator('[data-kind=goal]').fill('Goal typed during save.');
  await page.locator('[data-field=title]').fill('Title typed during save.');
  await held(()=>releaseSave);releaseSave();
  await page.getByText('저장했습니다. 이후 추가한 변경 내용은 아직 저장되지 않았습니다.',{exact:true}).waitFor();
  assert.equal(await page.locator('[data-kind=goal]').inputValue(),'Goal typed during save.');
  assert.equal(await page.locator('[data-field=title]').inputValue(),'Title typed during save.');
  assert.equal(await page.locator('[data-save-state]').textContent(),'저장하지 않은 변경');
  assert.equal(projects.get(copiedId).configuration.learning_goals[0].text,'Goal at save time.');
  await action('save').click();await page.getByText('평가 기준 초안을 저장했습니다.',{exact:true}).waitFor();
  assert.equal(projects.get(copiedId).configuration.learning_goals[0].text,'Goal typed during save.');
  assert.equal(projects.get(copiedId).title,'Title typed during save.');
  holdSave=true;await action('confirm').click();
  await page.waitForFunction(()=>document.querySelector('[data-action=confirm]').disabled);
  await page.locator('[data-kind=goal]').fill('Another change during confirmation.');
  await held(()=>releaseSave);releaseSave();
  await page.getByText('저장했습니다. 이후 추가한 변경 내용은 아직 저장되지 않았습니다.',{exact:true}).waitFor();
  assert.equal(new URL(page.url()).searchParams.get('stage'),'criteria','A late confirmation must not navigate away from new input');
  assert.equal(await page.locator('[data-kind=goal]').inputValue(),'Another change during confirmation.');
  const beforeRetry=projects.get(copiedId).revision_no;
  failCommittedSave=true;await page.locator('[data-kind=goal]').fill('Recover an uncertain save.');await action('save').click();
  await page.getByText('저장 응답을 확인하지 못했습니다. 다시 시도해 주세요.',{exact:true}).waitFor();
  const uncertain=requests.filter(r=>r.route.endsWith('/revisions')).at(-1);
  await action('save').click();await page.getByText('평가 기준 초안을 저장했습니다.',{exact:true}).waitFor();
  const replay=requests.filter(r=>r.route.endsWith('/revisions')).at(-1);
  assert.deepEqual(replay.input,uncertain.input,'Retry must replay the same command and payload');
  assert.equal(projects.get(copiedId).revision_no,beforeRetry+1,'Uncertain retry must not add another revision');
  failSave=true;await page.locator('[data-kind=goal]').fill('Unsaved change.');await action('save').click();await page.getByText('다른 창에서 평가 기준이 변경되었습니다.',{exact:true}).waitFor();assert.equal(await page.locator('[data-kind=goal]').inputValue(),'Unsaved change.');
  assert.equal(requests.some(r=>r.route.includes('/settings/')&&r.method==='POST'),false,'Project criteria must never write global settings');assert.deepEqual(errors,[]);
  console.log('Assessment project browser flow passed: real PHP page, load recovery, busy dialog, late input retention, confirmation guard, idempotent retry, criteria confirmation, multiple worksheets, copy isolation, seven scoped steps, error retention, desktop/mobile fit.');
}finally{releaseSave?.();releaseCreate?.();await browser.close();await new Promise(resolve=>server.close(resolve));}
