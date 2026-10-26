import assert from 'node:assert/strict';
import {readFile, writeFile, mkdir} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {createServer} from 'node:http';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {loadPlaywright,browserLaunchOptions} from './lib/playwright-runtime.mjs';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.OJAI_PHP || 'php';
const publicId=n=>'01K6B'+String(n).padStart(21,'0');
const worksheet=publicId(90), assignment=publicId(2), publication=publicId(91);
const project = publicId(200);
const projectData={project_id:project,title:'생태 관찰 평가',revision_no:3,state:'confirmed',configuration:{learning_goals:[],achievement_standards:[],criteria:[],total_points:'0'},worksheets:[{worksheet_id:worksheet,title:'생태 관찰'},{worksheet_id:publicId(201),title:'다른 활동지'}]};
const context={type:'class',id:'class-5-2',revision:'membership-17',label:'5학년 2반',selectable:true};
const groups=[{type:'group',id:'group-1',revision:'group-r1',label:'1학년 1반',selectable:true},{type:'group',id:'group-2',revision:'group-r2',label:'1학년 2반',selectable:true}];
let groupMode=false;
const descriptors={
  worksheets:['worksheet-workbench-visual','oj-ai-worksheet-authoring-app','worksheet-authoring-workbench'],
  assignment:['assignment-workbench-visual','oj-ai-assignment-app','assignment-workbench'],
  assessment:['submission-feedback-workbench-visual','oj-ai-submission-review-app','submission-evaluation-review-workbench'],
  report:['report-dashboard-visual','oj-ai-report-app','report-dashboard'],
  criteria:['assessment-configuration-settings-visual','oj-ai-assessment-settings','assessment-configuration-settings'],
  settings:['assessment-configuration-settings-visual','oj-ai-assessment-settings','assessment-configuration-settings'],
};
async function documentFor(kind,query,projectMode=false) {
  const [fixture,id,script]=descriptors[kind];
  const markup=execFileSync(php,['tests/fixtures/render-workflow-page.php',projectMode?'projects':kind,JSON.stringify(Object.fromEntries(query)),JSON.stringify(projectData)],{cwd:root,encoding:'utf8'});
  let html=await readFile(path.join(root,'tests/fixtures',fixture+'.html'),'utf8');
  html=html.replaceAll('../../resources/','/resources/');
  html=html.replace('<script src="/resources/js/worksheet-workflow.js"></script>','');
  if(kind==='report') html=html.replaceAll('worksheet-report-10',worksheet).replaceAll('publication-report-10',publication).replaceAll('assignment-report-1',assignment);
  const inject=`<script>
  document.getElementById(${JSON.stringify(id)}).outerHTML=${JSON.stringify(markup)};
  (()=>{
    const original=window.fetch;
    const wid=${JSON.stringify(worksheet)}, aid=${JSON.stringify(assignment)}, pid=${JSON.stringify(publication)}, context=${JSON.stringify(context)};
    const base={id:aid,worksheet_id:wid,worksheet_publication_id:pid,publication_hash:'a'.repeat(64),title:'생태 관찰 배포',state:'closed',context,target_count:1,progress:{submitted:1},schedule:{due_at_utc:'2026-09-01 09:00:00'},actions:[]};
    const reply=(data,meta={})=>({ok:true,status:200,json:async()=>({data,meta})});
    window.__flowRequests=[];window.__groupCreateRequests=[];
    window.fetch=async(url,options={})=>{
      const value=String(url), parsed=new URL(value,location.origin);
      window.__flowRequests.push({url:value,method:options.method||'GET'});
      if(parsed.pathname.endsWith('/assessment-projects'))return reply({projects:[{...${JSON.stringify(projectData)},worksheet_count:2}]});
      if(parsed.pathname.endsWith('/assessment-projects/${project}'))return reply({project:${JSON.stringify(projectData)}});
      if(value.includes('/contexts?'))return reply({items:${JSON.stringify(groupMode ? [context,...groups] : [context])}});
      if(value.includes('/worksheets?'))return reply({items:[{id:wid,title:'생태 관찰',state:'active',latest_publication_id:pid,latest_publication_hash:'a'.repeat(64)}]});
      if(${JSON.stringify(groupMode)}&&parsed.pathname.endsWith('/assignments')&&options.method==='POST') {
        window.__groupCreateRequests.push(JSON.parse(options.body));
        if(window.__rejectGroupDuplicate)return {ok:false,status:409,json:async()=>({message:'같은 발행본을 이미 배포받은 학생이 있습니다. 기존 배포나 대상 그룹을 확인해 주세요.'})};
        return reply({assignment:base});
      }
      if(value.includes('/assignments?')) {
        if(${JSON.stringify(groupMode)}) {
          const contexts=${JSON.stringify([context,...groups])};
          const current=contexts.find(item=>item.type===parsed.searchParams.get('context_type')&&item.id===parsed.searchParams.get('context_id'));
          if(!current)return {ok:false,status:403,json:async()=>({message:'Not allowed'})};
          return reply({items:[{...base,context:current,title:current.label+' 배포'}]});
        }
        if(parsed.searchParams.get('failure'))return {ok:false,status:503,json:async()=>({message:'시험 오류'})};
        return parsed.searchParams.get('cursor') ? reply({items:[base,{...base,id:${JSON.stringify(publicId(92))},title:'이전 발행본 배포',worksheet_publication_id:${JSON.stringify(publicId(93))}}]})
          : reply({items:[{...base,id:${JSON.stringify(publicId(94))},worksheet_id:${JSON.stringify(publicId(95))},title:'다른 활동지 배포'}]},{next_cursor:'second-page'});
      }
      if(value.includes('/assignments/'+aid+'?')){
        if(window.__delayAssignmentDetail)await new Promise(resolve=>{window.__releaseAssignmentDetail=resolve;});
        return reply({assignment:base,targets:[]});
      }
      if(value.includes('/submission-evaluation-candidates?')||value.includes('/student-notes?'))return reply({items:[]});
      if(value.includes('/submission-evaluation-roster?')) {
        const evaluations=await (await original(parsed.href.replace('/submission-evaluation-roster?','/submission-evaluations?'),options)).json();
        return reply({items:evaluations.data.items.map((item,index)=>({id:'target-'+index,learner:item.learner,assigned:true,current_member:true,status:'submitted',submission:{id:item.submission.id,official_revision_id:item.submission.source_revision_id,submitted_at_utc:item.submission.submitted_at_utc}}))});
      }
      const response=await original(parsed.href,options); const payload=await response.json();
      if(payload.data?.evaluation && payload.data?.selected_result){
        payload.data.feedback=[{id:${JSON.stringify(publicId(96))},state:'approved',revision_no:1,profile:{value:{overall_feedback:'근거를 들어 생태계의 관계를 설명했습니다.',criterion_feedback:[],next_steps:['관찰 결과 비교하기']}}}];
        payload.data.evaluation.release={state:'released',current_id:${JSON.stringify(publicId(97))},current_hash:'d'.repeat(64)};
      }
      if(${JSON.stringify(kind)}==='report' && parsed.pathname.endsWith('/reports')) {
        const items=payload.data.items;
        payload.data.items=parsed.searchParams.get('cursor') ? items.filter(i=>i.worksheet.id===wid) : items.filter(i=>i.worksheet.id!==wid);
        payload.meta.next_cursor=parsed.searchParams.get('cursor') ? '' : 'second-page';
      }
      return {ok:response.ok,status:response.status,json:async()=>payload};
    };
  })();
  </script><link rel="stylesheet" href="/resources/css/worksheet-workflow.css">${projectMode?'<link rel="stylesheet" href="/resources/css/assessment-projects.css">':''}<script src="/resources/js/worksheet-workflow.js"></script>${projectMode?'<script src="/resources/js/assessment-projects.js"></script>':''}`;
  return html.replace('<script src="/resources/js/'+script+'.js">',inject+'<script src="/resources/js/'+script+'.js">');
}
const cache=new Map();
const server=createServer(async(req,res)=>{
  try {
    const url=new URL(req.url,'http://localhost');
    const route=url.pathname.split('/').pop();
    const kind=route==='projects'?(!url.searchParams.get('project_id')||url.searchParams.get('stage')==='criteria'?'criteria':url.searchParams.get('stage')==='assignment'?'assignment':'assessment'):route==='assignments'?'assignment':route==='assessments'?'assessment':route==='reports'?'report':route;
    if(descriptors[kind]) {
      if(!cache.has(req.url))cache.set(req.url,await documentFor(kind,url.searchParams,route==='projects'));
      res.setHeader('Content-Type','text/html; charset=utf-8'); res.end(cache.get(req.url)); return;
    }
    const filename=path.resolve(root,'.'+decodeURIComponent(url.pathname));
    if(!filename.startsWith(root+path.sep)){res.writeHead(403).end();return;}
    res.setHeader('Content-Type',({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'}[path.extname(filename)]||'application/octet-stream')+'; charset=utf-8');
    res.end(await readFile(filename));
  }catch(error){res.writeHead(500).end(String(error));}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const {chromium}=await loadPlaywright();
const browser=await chromium.launch({...browserLaunchOptions(),headless:true});
const page=await browser.newPage({viewport:{width:1440,height:1000}});
const artifacts=path.join(root,'tests/artifacts/workflow-navigation'); await mkdir(artifacts,{recursive:true});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
const base='http://127.0.0.1:'+server.address().port+'/oj-console/addons/oj-ai-dashboard/';
const scope=new URLSearchParams({project_id:project,worksheet_id:worksheet,assignment_id:assignment,context_type:context.type,context_id:context.id,context_revision:context.revision});
const projectShell = async () => {
  await page.getByRole('heading',{name:'생태 관찰 평가',exact:true}).waitFor();
  assert.equal(await page.getByRole('navigation',{name:'평가 진행 단계',exact:true}).count(),1);
  assert.equal(await page.locator('[data-workspace-navigation]').count(),0);
  assert.equal(await page.getByRole('combobox',{name:'현재 평가의 활동지',exact:true}).inputValue(),worksheet);
  assert.equal(await page.locator('.oj-ai-project-workspace').count(),1,'A single project workspace must survive repeated context updates');
  assert.equal(new URL(page.url()).pathname,new URL(base+'projects').pathname);
};
const fit=async()=>assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Horizontal overflow');
const shot=async name=>page.screenshot({path:path.join(artifacts,name+'.png'),fullPage:true});
try{
  const unrelatedScope=new URLSearchParams(scope);unrelatedScope.set('worksheet_id',publicId(201));unrelatedScope.set('stage','assignment');unrelatedScope.delete('assignment_id');
  await page.goto(base+'projects?'+unrelatedScope);
  await page.getByText('이 수업에는 현재 활동지의 배포가 없습니다. 다른 활동지의 배포 3개가 있습니다.',{exact:true}).waitFor();
  assert.match(await page.locator('[data-role="publication-notice"]').innerText(),/새 배포.*기존 배포/);
  const workspace=()=>page.getByRole('navigation',{name:'활동지 평가 메뉴',exact:true});
  assert.equal(await workspace().count(),0,'The current evaluation has one seven-step track only');
  assert.equal(await page.getByRole('link',{name:'수업 전체 배포 보기',exact:true}).isVisible(),false,'Scoped work must not promote a competing global track');
  for(const width of [1280,768,390]){await page.setViewportSize({width,height:900});await fit();await shot('navigation-scope-'+width);}
  await page.getByRole('link',{name:'평가 목록',exact:true}).click();
  await workspace().waitFor();
  assert.deepEqual(await workspace().getByRole('link').allTextContents(),['평가 프로젝트','활동지 보관함','설정']);
  await page.getByRole('navigation',{name:'전체 업무',exact:true}).getByRole('link',{name:'배포·제출',exact:true}).click();
  await page.waitForFunction(()=>document.querySelectorAll('[data-role="assignment-list"] tr').length===3);
  assert.equal(new URL(page.url()).searchParams.has('project_id'),false);
  assert.equal(new URL(page.url()).searchParams.has('worksheet_id'),false);
  assert.equal(new URL(page.url()).searchParams.get('context_id'),context.id);
  for(const [label,route] of [['평가 프로젝트','projects'],['활동지 보관함','worksheets'],['평가 기준','criteria'],['설정','settings'],['평가 프로젝트','projects'],['평가','assessments'],['보고서','reports']]){
    await page.locator('nav').getByRole('link',{name:label,exact:true}).click();
    await workspace().waitFor();
    assert.equal(new URL(page.url()).pathname,new URL(base+route).pathname);
    assert.equal(await workspace().locator('[aria-current="page"]').count(),1);
    assert.equal(await workspace().count(),1);
    if(route==='worksheets'){
      await page.waitForSelector('[data-worksheet-row]');
      for(const width of [1280,768,390]){
        await page.setViewportSize({width,height:900});
        const layout=await page.evaluate(()=>{
          const app=document.querySelector('#oj-ai-worksheet-authoring-app');
          const create=app.querySelector('a.oj-ai-workbench__primary').getBoundingClientRect();
          const links=[...app.querySelectorAll('[data-worksheet-row] td:nth-child(3) a')];
          return {createHeight:create.height,hasLinks:links.length>0,linksFit:links.every(link=>{const r=link.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&link.scrollWidth<=link.clientWidth+1;}),overflow:document.documentElement.scrollWidth>innerWidth+1};
        });
        assert(layout.createHeight>=40 && layout.hasLinks && layout.linksFit && !layout.overflow,JSON.stringify(layout));
        await shot('worksheet-list-'+width);
      }
    }
    await fit();
  }
  await page.setViewportSize({width:1440,height:1000});
  await page.goto(base+'assignments?'+scope);
  await page.waitForFunction(()=>document.querySelectorAll('[data-role="assignment-list"] tr').length===2);
  assert.equal(await page.locator('[data-role="wizard"]').isVisible(),false);
  assert.match(await page.locator('[data-role="assignment-list"]').textContent(),/이전 발행본/);
  assert.doesNotMatch(await page.locator('[data-role="assignment-list"]').textContent(),/다른 활동지/);
  await page.waitForFunction(()=>document.querySelector('[data-detail="title"]').textContent==='생태 관찰 배포');
  await projectShell();
  await fit();await shot('04-assignment');
  await page.locator('[data-workflow-step="assessment"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-review-role="detail"]')?.textContent.includes('점수와 근거'));
  assert.equal(new URL(page.url()).searchParams.get('assignment_id'),assignment);
  assert.equal(new URL(page.url()).searchParams.get('project_id'),project);
  await projectShell();
  assert.equal(await page.locator('[data-review-role="detail"] [data-feedback-action]').count(),0);
  assert.equal(await page.locator('[data-release-action="open"]').count(),0);
  const queries=await page.evaluate(()=>window.__flowRequests.filter(r=>r.url.includes('/submission-evaluations?')));
  assert(queries.length>0 && queries.every(r=>new URL(r.url,locationBase()).searchParams.get('assignment_id')===assignment));
  await fit(); await shot('05-assessment');
  const points=page.locator('[data-review-field="official-points"]').first();
  if(await points.count()){
    await points.fill('3');
    page.once('dialog',dialog=>dialog.dismiss());
    await page.getByRole('link',{name:'평가 목록',exact:true}).click();
    assert.equal(new URL(page.url()).searchParams.get('stage'),'assessment');
    assert.equal(await points.inputValue(),'3');
    page.once('dialog',dialog=>dialog.accept());
  }
  await page.locator('nav [data-workflow-step="report"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-review-role="detail"]')?.textContent.includes('학생 피드백'));
  await projectShell();
  assert.equal(await page.locator('[data-review-field="official-points"]').count(),0);
  assert.equal(await page.locator('[data-release-action="open"]').count(),0);
  assert.match(await page.locator('.oj-ai-submission-review__metrics').textContent(),/학생 공개공개/);
  await fit();await shot('06-report-writing');
  const summaryUrl=await page.getByRole('link',{name:'보고서',exact:true}).getAttribute('href');
  await page.locator('nav [data-workflow-step="release"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-review-role="detail"]')?.textContent.includes('검토·승인한 내용'));
  await projectShell();
  assert.equal(await page.locator('[data-review-role="detail"] [data-feedback-action]').count(),0);
  assert.equal(await page.locator('[data-review-field="official-points"]').count(),0);
  assert.equal(await page.locator('[data-release-action="open"]').count(),1);
  await fit();await shot('07-release');
  await page.setViewportSize({width:390,height:844});await fit();await shot('07-release-mobile');
  await page.setViewportSize({width:1440,height:1000});
  await page.goto(summaryUrl);
  await page.waitForFunction(()=>document.querySelector('[data-role="content"]')?.getAttribute('aria-busy')==='false' && document.querySelectorAll('[data-action="open-student"]').length>0);
  assert.equal(await page.locator('[data-action="open-worksheet"]').count(),0);
  const reportQueries=await page.evaluate(()=>window.__flowRequests.filter(r=>r.url.includes('/reports/worksheets/')));
  assert(reportQueries.length>0 && reportQueries.every(r=>r.url.includes(worksheet)) && reportQueries.some(r=>r.url.includes('assignment_id='+assignment)));
  assert.equal(await page.evaluate(()=>window.__flowRequests.some(r=>r.url.includes('/assignments?'))),false,'Report readers must not require assignment-management permission');
  await fit();await shot('06-report-detail');
  for(const kind of ['criteria','settings']){
    const libraryScope=new URLSearchParams(scope);libraryScope.delete('project_id');
    await page.goto(base+kind+'?'+libraryScope);
    const tabs=await page.locator('[data-settings-tab]').allTextContents();
    assert.deepEqual(tabs,kind==='criteria'?['평가 기준','성취기준']:['평가 정책','검토·승인','AI 연결','시스템 상태']);
    await fit();await shot(kind);
  }
  const invalidContext=new URLSearchParams(scope);invalidContext.set('context_id','missing-class');
  await page.goto(base+'assessments?'+invalidContext);
  await page.getByText('선택한 대상의 배포를 확인할 수 없습니다. 배포 대상을 다시 선택해 주세요.',{exact:true}).waitFor();
  assert.equal(await page.locator('[data-review-field="context"]').inputValue(),'');
  assert.equal(await page.evaluate(()=>window.__flowRequests.some(r=>r.url.includes('/submission-evaluations?'))),false,'Unknown context must not fall back to another class');
  const cycle=await page.evaluate(async()=>{
    let calls=0;
    try {await window.OJAIWorkflow.allPages(async()=>{calls++;return {data:{items:[]},meta:{next_cursor:'same-cursor'}};},'/cycle');return {calls,rejected:false};}
    catch {return {calls,rejected:true};}
  });
  assert.deepEqual(cycle,{calls:2,rejected:true});
  await page.goto(base+'assessments?context_type=class&context_id=class-5-2&context_revision=membership-17');
  await page.waitForFunction(()=>[...document.querySelectorAll('[data-review-field="assignment"] option')].some(option=>option.textContent==='생태 관찰 배포'));
  await page.locator('[data-review-field="assignment"]').selectOption({label:'생태 관찰 배포'});
  await page.waitForFunction(()=>document.querySelector('[data-review-role="detail"]')?.textContent.includes('점수와 근거'));
  const selectedLink=new URL(await page.locator('nav [data-workflow-step="report"]').getAttribute('href'));
  assert.equal(selectedLink.searchParams.get('worksheet_id'),worksheet);
  assert.equal(selectedLink.searchParams.get('assignment_id'),assignment);
  assert.equal(selectedLink.searchParams.get('publication_hash'),'a'.repeat(64));
  groupMode=true;cache.clear();
  for(const width of [1440,390]){
    await page.setViewportSize({width,height:900});
    for(const [route,selector,panel] of [
      ['assignments','[data-field="context-filter"]','[data-role="assignment-content"]'],
      ['assessments','[data-review-field="context"]','[data-review-role="content"]'],
      ['reports','[data-field="context"]','[data-role="content"]'],
    ]){
      await page.goto(base+route);
      const select=page.locator(selector);
      const picker=select.locator('xpath=../..');
      const type=picker.locator('[data-context-type]');
      await type.waitFor();
      assert.equal(await type.inputValue(),'group','Groups are the default in mixed installations');
      assert.deepEqual(await select.locator('option').allTextContents(),['그룹(반) 선택',...groups.map(g=>g.label)]);
      await select.selectOption('1');
      await page.waitForFunction(()=>window.__flowRequests.some(r=>r.url.includes('context_id=group-1')));
      await select.selectOption('2');
      await page.waitForFunction(()=>window.__flowRequests.some(r=>r.url.includes('context_id=group-2')));
      assert.equal(await select.inputValue(),'2');
      await fit();await shot('group-first-'+route+'-'+width);
      if(route==='assignments'){
        await page.locator('[data-action="new"]').click();
        const wizard=page.locator('[data-role="wizard"]');
        await wizard.locator('[data-field="publication"]').selectOption('0');
        await wizard.locator('[data-action="next"]').click();
        assert.equal(await wizard.locator('[data-context-type]').inputValue(),'group');
        await wizard.locator('[data-field="context"]').selectOption('1');
        await wizard.locator('[data-action="next"]').click();
        const future=offset=>new Date(Date.now()+offset*3600000).toISOString().slice(0,16);
        await wizard.locator('[data-field="due-at"]').fill(future(48));
        await wizard.locator('[data-field="closes-at"]').fill(future(72));
        await wizard.locator('[data-action="next"]').click();
        assert.match(await wizard.locator('[data-review="context"]').innerText(),/1학년 1반.*그룹/);
        await page.evaluate(()=>{window.__rejectGroupDuplicate=true;});
        await wizard.locator('[data-action="create"]').click();
        await wizard.getByText('같은 발행본을 이미 배포받은 학생이 있습니다. 기존 배포나 대상 그룹을 확인해 주세요.',{exact:true}).waitFor();
        assert.equal(await wizard.locator('[data-field="context"]').inputValue(),'1','A duplicate warning keeps the group and schedule for correction');
        await page.evaluate(()=>{window.__rejectGroupDuplicate=false;});
        await wizard.locator('[data-action="create"]').click();
        await wizard.waitFor({state:'hidden'});
        assert.equal(await select.inputValue(),'1','After creation the new group becomes the displayed scope');
        const bodies=await page.evaluate(()=>window.__groupCreateRequests);
        assert.equal(bodies.length,2);
        for(const body of bodies){assert.deepEqual(body.payload.context,{type:'group',id:groups[0].id});assert.equal(body.expected.expected_context_revision,groups[0].revision);assert.equal(body.payload.worksheet_publication_id,publication);assert.equal('learner_user_ids' in body.payload,false);}
        await page.evaluate(()=>{window.__delayAssignmentDetail=true;});
        await page.locator('[data-action="view-assignment"]').first().click();
        await page.waitForFunction(()=>typeof window.__releaseAssignmentDetail==='function');
      }
      if(route==='assessments'){
        const points=page.locator('[data-review-field="official-points"]').first();
        await points.waitFor();
        const edited=await points.inputValue()==='3'?'2':'3';
        await points.fill(edited);
        page.once('dialog',dialog=>dialog.dismiss());
        await type.selectOption('class');
        assert.equal(await type.inputValue(),'group','Cancelling a target-type change retains the original group');
        assert.equal(await select.inputValue(),'2');
        assert.equal(await points.inputValue(),edited,'Cancelled type changes preserve score drafts');
        page.once('dialog',dialog=>dialog.accept());
      }
      await type.selectOption('class');
      if(route==='assignments'){
        await page.evaluate(async()=>{window.__releaseAssignmentDetail();await new Promise(resolve=>setTimeout(resolve,0));});
        assert.equal(await page.locator('[data-role="assignment-detail"]').isVisible(),false,'A delayed detail from the previous group cannot reopen after switching');
      }
      assert.equal(await select.inputValue(),'','Changing target type clears the selected cohort');
      assert.equal(await page.evaluate(()=>window.OJAIWorkflow.read().context),null);
      if(await page.locator(panel).count())assert.equal(await page.locator(panel).isVisible(),false,'Do not keep results from the previous group visible');
      assert.deepEqual(await select.locator('option').allTextContents(),['수업 선택',context.label]);
      await select.selectOption('0');
      await page.waitForFunction(()=>window.__flowRequests.some(r=>r.url.includes('context_id=class-5-2')));
      await page.goto(base+route+'?'+new URLSearchParams({context_type:'class',context_id:context.id,context_revision:context.revision}));
      await page.locator('[data-context-type]').first().waitFor({state:'attached'});
      assert.equal(await page.locator('[data-context-type]').first().inputValue(),'class','Existing class links stay reachable');
      assert.equal(await page.locator(selector).inputValue(),'0');
    }
    for(const stage of ['assignment','assessment','report','release']){
      const groupScope=new URLSearchParams(scope);groupScope.set('context_type','group');groupScope.set('context_id',groups[0].id);groupScope.set('context_revision',groups[0].revision);groupScope.set('stage',stage);
      await page.goto(base+'projects?'+groupScope);
      await page.locator('[data-context-type]').first().waitFor({state:'attached'});
      assert.equal(await page.locator('[data-context-type]').first().inputValue(),'group');
      await fit();await shot('group-workflow-'+stage+'-'+width);
    }
  }
  assert.deepEqual(errors,[]);
  const result={passed:true,checks:['actual PHP markup','three global entries outside evaluations','one track inside evaluations','legacy overviews reachable from evaluation list','scope-aware empty state and other assignment count','overview clears project and worksheet filters','unpublished notice survives class selection','unsaved score navigation cancellation and confirmation','assignment pagination and old publications','no automatic wizard','same worksheet and assignment across stages','score/report/release control separation','scoped report across pages','criteria vs operations tabs','unknown context does not broaden query','repeated cursor rejected','bulk inbox selection becomes workflow context','group-first assignment/review/report selectors','separate class selection and old links','cohort switching clears stale results','group scope through scoring/report/release','desktop and mobile overflow'],runtimeErrors:errors};
  await writeFile(path.join(artifacts,'result.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result));
}catch(error){
  await shot('failure');
  console.error(JSON.stringify({url:page.url(),errors,text:await page.locator('body').innerText(),requests:await page.evaluate(()=>window.__flowRequests)},null,2));
  throw error;
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
function locationBase(){return base;}
