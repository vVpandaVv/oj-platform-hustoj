import assert from 'node:assert/strict';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadPlaywright, browserLaunchOptions } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.OJAI_PHP || 'php';
const markup = execFileSync(php, ['tests/fixtures/render-criteria-first-workflow.php'], { cwd: root, encoding: 'utf8' });
const fixture = await readFile(path.join(root, 'tests/fixtures/worksheet-workbench-visual.html'), 'utf8');
const injection = `<script>
document.getElementById('oj-ai-worksheet-authoring-app').outerHTML = ${JSON.stringify(markup)};
const originalFetch = window.fetch;
window.__changedRubric = false;
const projectMode=new URLSearchParams(location.search).has('project_test')||location.pathname.endsWith('/projects');
const projectId='01K00000000000000000090001',projectRevision='01K00000000000000000090002',projectCriterion='01K00000000000000000090003';
const projectFixture={project_id:projectId,title:'평가별 기준 연결 확인',revision_id:projectRevision,revision_no:2,state:'confirmed',content_hash:'a'.repeat(64),rubric_content_hash:'b'.repeat(64),configuration:{learning_goals:[{goal_id:'01K00000000000000000090004',text:'평가별 학습 목표'}],achievement_standards:[{standard_id:'01K00000000000000000090005',code:'EVIDENCE',text:'평가별 성취기준 원문'}],criteria:[{criterion_id:projectCriterion,label:'이 평가만의 채점 기준',description:'평가에 연결한 근거로 판단한다.',scope:'submission',maximum_points:'10',position:0,levels:[{level_key:'01K00000000000000000090006',label:'충족',points:'10',position:0},{level_key:'01K00000000000000000090007',label:'미충족',points:'0',position:1}]}],total_points:'10'}};
window.fetch = async (url, options = {}) => {
  const latestProject=JSON.parse(localStorage.getItem('criteria-latest-project')||'null')||projectFixture;
  if(projectMode&&String(url).endsWith('/assessment-projects/'+projectId))return {ok:true,status:200,json:async()=>({data:{project:{...latestProject,current_revision_id:latestProject.revision_id,worksheets:[{worksheet_id:document.querySelector('[data-oj-ai-workflow]').dataset.workflowWorksheet,title:'평가 연결 활동지',publication_id:window.__publishedWorksheet?'01K00000000000000000091999':null}]}}})};
  if(projectMode&&String(url).endsWith('/assessment-project')){
    const pin=JSON.parse(localStorage.getItem('criteria-fixture')||'null')?.document.settings.assessment_project;
    const selected=pin?.revision_id===latestProject.revision_id?latestProject:projectFixture;
    return {ok:true,status:200,json:async()=>({data:{project:{...selected,current_revision_id:latestProject.revision_id}}})};
  }
  if (String(url).includes('/worksheet-evaluations?')) return {ok:true,status:200,json:async()=>({data:{items:[]},meta:{next_cursor:''}})};
  const response = await originalFetch(url, options);
  if (String(url).includes('/assets/')) return response;
  const payload = await response.json();
  const method = options.method || 'GET';
  if(projectMode && payload.data?.preview) {
    const stored=JSON.parse(localStorage.getItem('criteria-fixture')||'null');
    if(stored) {
      const canonical=await window.canonicalizePreparation(stored.document);
      payload.data.preview.projection=String(url).includes('projection=student')?canonical.student_preview:canonical.print_preview;
    }
  }
  if (String(url).endsWith('/settings/rubrics')) {
    payload.data.rubrics.subjects[0].revisions[0].content_hash = (window.__changedRubric ? 'c' : 'b').repeat(64);
  }
  if (String(url).includes('/commands/save-draft') && response.ok) {
    const body = JSON.parse(options.body).payload;
    if (window.__failSave) return {ok:false,status:503,json:async()=>({message:'저장 시험 오류'})};
    const canonical = await window.canonicalizePreparation(body.teacher_document);
    payload.data.temporary_id_map = canonical.temporary_id_map;
    payload.data.revision.document_hash = canonical.document_hash;
    const mappedLinks = body.achievement_links.map(link => ({...link, response_id: canonical.temporary_id_map[link.response_id] || link.response_id}));
    localStorage.setItem('criteria-fixture', JSON.stringify({document:canonical.document,links:mappedLinks,hash:canonical.document_hash}));
    window.__savedPreparation = canonical.document.settings.preparation;
  }
  if (method === 'GET' && payload.data?.teacher_document) {
    const stored = JSON.parse(localStorage.getItem('criteria-fixture') || 'null');
    if (stored) {
      payload.data.teacher_document = stored.document;
      payload.data.achievement_links = stored.links;
      payload.data.revision.document_hash = stored.hash;
    } else {
      payload.data.teacher_document.settings.preparation = {state:'draft',rubric_revision_id:null,rubric_content_hash:null};
      payload.data.teacher_document.settings.learning_goals = [];
      payload.data.teacher_document.settings.assessment_bindings = [];
      payload.data.achievement_links = [];
      if(projectMode){const s=payload.data.teacher_document.settings;s.assessment_project={project_id:projectId,revision_id:projectRevision,content_hash:projectFixture.content_hash};s.preparation={state:'confirmed',rubric_revision_id:projectRevision,rubric_content_hash:projectFixture.rubric_content_hash};s.learning_goals=projectFixture.configuration.learning_goals.map(g=>({...g,scope:'worksheet',response_id:null}));s.assessment_bindings=[{criterion_id:projectCriterion,scope:'worksheet',response_id:null}];}
      if(projectMode)payload.data.teacher_document.nodes.splice(2,0,{
        type:'ol',node_id:'01K00000000000000000091001',start:5,children:[
          {type:'li',node_id:'01K00000000000000000091002',children:[{text:'Compare '},{text:'the evidence',bold:true},{text:' carefully.'}]},
          {type:'li',node_id:'01K00000000000000000091003',children:[
            {type:'p',node_id:'01K00000000000000000091004',children:[{text:'Explain your reasons.'}]},
            {type:'ul',node_id:'01K00000000000000000091005',children:[
              {type:'li',node_id:'01K00000000000000000091006',children:[{text:'Keep nested evidence.'}]}
            ]}
          ]}
        ]
      });
    }
  }
  return {ok:response.ok,status:response.status,json:async()=>payload};
};
</script>`;
const html = fixture.replace('  <script src="../../resources/assets/builder/plate-template-builder.js">', `${injection}\n<link rel="stylesheet" href="../../resources/css/worksheet-evaluation-workbench.css"><link rel="stylesheet" href="../../resources/css/worksheet-workflow.css"><script src="../../resources/js/worksheet-workflow.js"></script>\n  <script src="../../resources/assets/builder/plate-template-builder.js">`).replace('</body>', '<script src="../../resources/js/worksheet-evaluation-workbench.js"></script></body>');
const server = createServer(async (request, response) => {
  try {
    const url = new URL(request.url, 'http://localhost');
    const pathname = decodeURIComponent(url.pathname);
    if(pathname.endsWith('/projects')) {
      const project={project_id:'01K00000000000000000090001',worksheets:[{worksheet_id:'01HZY3M4N5P6Q7R8S9T0V1W2X3'}]};
      const projectMarkup=execFileSync(php,['tests/fixtures/render-workflow-page.php','projects',JSON.stringify(Object.fromEntries(url.searchParams)),JSON.stringify(project)],{cwd:root,encoding:'utf8'});
      const document=html.replace(JSON.stringify(markup),JSON.stringify(projectMarkup)).replaceAll('../../resources/','/resources/').replace('<script src="/resources/js/worksheet-workflow.js"></script>','<link rel="stylesheet" href="/resources/css/assessment-projects.css"><script src="/resources/js/worksheet-workflow.js"></script><script src="/resources/js/assessment-projects.js"></script>');
      response.setHeader('Content-Type','text/html; charset=utf-8');response.end(document);return;
    }
    const filename = path.resolve(root, `.${pathname}`);
    if (!filename.startsWith(root + path.sep)) { response.writeHead(403).end(); return; }
    const type = {'.js':'text/javascript','.css':'text/css','.html':'text/html','.svg':'image/svg+xml','.woff2':'font/woff2'}[path.extname(filename)] || 'application/octet-stream';
    response.setHeader('Content-Type', `${type}; charset=utf-8`);
    response.end(pathname.endsWith('worksheet-workbench-visual.html') ? html : await readFile(filename));
  } catch { response.writeHead(404).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const { chromium } = await loadPlaywright();
const browser = await chromium.launch({ ...browserLaunchOptions(), headless: true });
const artifacts = path.join(root, 'tests/artifacts/criteria-first-workflow');
await mkdir(artifacts, {recursive:true});
const errors = [];
const context = await browser.newContext({viewport:{width:1440,height:1000}});
const page = await context.newPage();
page.on('pageerror', error => errors.push(error.message));
await page.exposeFunction('canonicalizePreparation', document => JSON.parse(execFileSync(php, ['tests/fixtures/canonicalize-preparation-document.php'], {cwd:root,input:JSON.stringify(document),encoding:'utf8'})));
const app = page.locator('#oj-ai-worksheet-authoring-app');
const screenshot = async name => page.screenshot({path:path.join(artifacts, `${name}.png`),fullPage:true});
const canNavigate = () => page.evaluate(() => document.dispatchEvent(new CustomEvent('ojai:before-navigation', {
  cancelable:true,detail:{href:location.href}
})));
const openInspector = async () => {
  if (!await page.locator('.oj-ai-workbench__inspector').isVisible()) await page.locator('[data-action="toggle-inspector"]').click();
};
const assertListContent = async () => {
  const list = await page.evaluate(() => JSON.parse(document.getElementById('ws-builder-schema').value).plate_value.find(node => node.type === 'ol'));
  assert.equal(list.start,5,'Numbered list start must survive load and save');
  assert.equal(list.children[0].children[0].type,'lic','Saved comparison must use the normalized list content');
  assert.deepEqual(list.children[0].children[0].children,[{text:'Compare '},{text:'the evidence',bold:true},{text:' carefully.'}]);
  assert.equal(list.children[1].children[0].children[0].text,'Explain your reasons.');
  assert.equal(list.children[1].children[1].type,'ul','Nested list must survive load and save');
  assert.equal(list.children[1].children[1].children[0].children[0].children[0].text,'Keep nested evidence.');
  const renderedStart=await page.locator('[data-slate-editor] ol').first().getAttribute('start');
  assert.equal(renderedStart,'5','Editor and student preview must show the same numbered-list start');
};
const assertFit = async () => {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
  if (overflow) {
    await screenshot('overflow-failure');
    console.log(await page.evaluate(() => [...document.querySelectorAll('main,section,nav,div,input,select,button')].filter(e=>e.getClientRects().length && e.getBoundingClientRect().right>innerWidth+1).slice(0,15).map(e=>({tag:e.tagName,class:e.className,role:e.getAttribute('data-role'),right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width}))));
  }
  assert.equal(overflow, false, 'Viewport must not overflow');
};
const assertOutlineResize = async () => {
  const outline = page.locator('#oj-ai-question-outline');
  const handle = page.getByRole('separator', {name:'문항 목록 너비 조절',exact:true});
  const width = async () => Math.round((await outline.boundingBox()).width);
  const dragBy = async (distance, cancel = false) => {
    await handle.scrollIntoViewIfNeeded();
    const box = await handle.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + 100);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 + distance, box.y + 100, {steps:8});
    if (cancel) await page.keyboard.press('Escape');
    await page.mouse.up();
  };
  await page.locator('[data-action="toggle-outline"]').click();
  assert.equal(await width(),260);
  const before = await page.locator('#ws-builder-schema').inputValue();
  await dragBy(120);
  assert.equal(await width(),380,'Dragging the boundary must resize the outline');
  assert.equal(await handle.getAttribute('aria-valuenow'),'380');
  await dragBy(-80,true);
  assert.equal(await width(),380,'Escape during a drag restores the previous width');
  assert.equal(await app.getAttribute('data-compact-panel'),'outline','Cancel resize must not close the panel');
  await handle.focus(); await page.keyboard.press('Home');
  assert.equal(await width(),210);
  await page.keyboard.press('ArrowRight');
  assert.equal(await width(),226);
  await page.keyboard.press('End');
  assert.equal(await width(),480);
  assert.ok((await page.locator('.oj-ai-workbench__canvas').boundingBox()).width>=420,'Resizing must reserve editor space');
  await dragBy(800); assert.equal(await width(),480,'Drag respects the maximum');
  await dragBy(-800); assert.equal(await width(),210,'Drag respects the minimum');
  await handle.dblclick(); assert.equal(await width(),260,'Double-click resets the preferred width');
  await dragBy(120);
  await outline.locator('strong').first().evaluate(element => {
    element.textContent = '디지털 사회의 변화와 개인정보 보호를 위한 구체적인 실천 방안 및 탐구 과정 정리';
  });
  const title = await outline.locator('strong').first().evaluate(element => ({
    height: element.getBoundingClientRect().height, lineHeight: parseFloat(getComputedStyle(element).lineHeight),
    fits: element.scrollWidth<=element.clientWidth+1, whiteSpace:getComputedStyle(element).whiteSpace,
  }));
  assert.ok(title.height>title.lineHeight && title.fits && title.whiteSpace==='normal','Long titles must wrap instead of clipping');
  await screenshot('outline-resize-desktop');
  assert.equal(await page.locator('#ws-builder-schema').inputValue(),before,'Panel sizing must not edit the worksheet');
  assert.equal(await canNavigate(),true);
  const workspace = page.locator('.oj-ai-workbench__workspace');
  // Simulate a narrower console without changing the browser viewport.
  await app.evaluate(element => {element.style.width='870px';});
  await page.waitForFunction(() => Number(document.querySelector('[role="separator"]').getAttribute('aria-valuemax'))<480);
  assert.ok((await page.locator('.oj-ai-workbench__canvas').boundingBox()).width>=419);
  await app.evaluate(element => {element.style.removeProperty('width');});
  await page.waitForFunction(() => document.querySelector('#oj-ai-question-outline').getBoundingClientRect().width===380);
  await page.setViewportSize({width:390,height:844});
  await assertFit();
  assert.equal(await handle.isVisible(),false,'Stacked mobile panels must not offer horizontal resizing');
  assert.ok(Math.abs((await outline.boundingBox()).width-(await workspace.boundingBox()).width)<3);
  await screenshot('outline-resize-mobile');
  await page.setViewportSize({width:1440,height:1000});
  await page.waitForFunction(() => document.querySelector('#oj-ai-question-outline').getBoundingClientRect().width===380);
  await page.locator('[data-action="toggle-inspector"]').click();
  assert.equal(await handle.isVisible(),false,'Inspector mode must not keep a spare splitter column');
  await page.locator('[data-action="toggle-outline"]').click();
  assert.equal(await width(),380);
  await page.reload();
  await page.waitForFunction(()=>document.querySelector('[data-role="save-status"]').textContent==='저장됨');
  await page.locator('[data-action="toggle-outline"]').click();
  assert.equal(await width(),380,'Width preference must survive reload');
  await handle.focus(); await page.keyboard.press('Escape');
  assert.equal(await handle.isVisible(),false);
  assert.equal(await page.locator('[data-action="toggle-outline"]').evaluate(element => element===document.activeElement),true);
};
try {
  // Standalone criteria are retired; exercise the actual project authoring surface.

  await page.goto(`http://127.0.0.1:${server.address().port}/oj-console/addons/oj-ai-dashboard/projects?project_id=01K00000000000000000090001&worksheet_id=01HZY3M4N5P6Q7R8S9T0V1W2X3&stage=author`);
  await page.waitForFunction(()=>document.querySelector('[data-role="save-status"]').textContent==='저장됨');
  assert.equal(await app.getAttribute('data-workflow-stage'),'author');
  await page.getByRole('heading',{name:'평가별 기준 연결 확인',exact:true}).waitFor();
  await assertOutlineResize();
  assert.equal(await canNavigate(),true,'Loaded worksheet marked saved must allow workflow navigation');
  await page.waitForTimeout(700);
  assert.equal(await canNavigate(),true,'Plate normalization must not create unsaved changes after load');
  await assertListContent();
  await page.locator('[data-slate-editor]').getByText('Explain your reasons.',{exact:true}).click();
  await page.keyboard.press('End');
  await page.keyboard.insertText(' Edited.');
  assert.equal(await canNavigate(),false,'Real list text edits must block navigation');
  await page.keyboard.press('Control+z');
  await page.waitForTimeout(700);
  assert.equal(await canNavigate(),true,'Undoing a list edit must restore the saved baseline');
  await assertListContent();
  assert.equal(await page.getByRole('navigation',{name:'평가 진행 단계',exact:true}).count(),1);
  assert.equal(await page.getByRole('combobox',{name:'현재 평가의 활동지',exact:true}).isVisible(),true);
  assert.match(await page.locator('[data-workflow-step="criteria"]').getAttribute('href'),/projects\?/);
  assert.match(await page.locator('[data-role="basis-summary"]').textContent(),/평가별 학습 목표/);
  assert.match(await page.locator('[data-role="basis-summary"]').textContent(),/평가별 성취기준 원문/);
  assert.equal(await page.locator('[data-role="confirmed-basis"]').isVisible(),true,'Recorded criteria summary must remain accessible in the current editor');
  assert.match(await page.locator('[data-project-header]').textContent(),/평가별 기준 연결 확인/);
  assert.equal(await page.evaluate(()=>window.ojAiDashboardStudentRubricCriteria.length),1);
  assert.equal(await page.evaluate(()=>window.ojAiDashboardStudentRubricCriteria[0].label),'이 평가만의 채점 기준');
  assert.equal(await page.getByRole('link',{name:'활동지 목록',exact:true}).count(),0);
  assert.equal(await page.locator('[data-project-content] .table').count(),0);
  assert.equal(await page.locator('[data-project-stage-body] #oj-ai-worksheet-authoring-app').count(),1);
  await openInspector();
  await page.locator('[data-inspector-tab="assessment"]').click();
  const target = page.locator('[data-field="assessment-target"]').first();
  const responseTarget = await target.locator('option').nth(1).getAttribute('value');
  await target.selectOption(responseTarget);
  assert.equal(await canNavigate(),false,'Actual response mapping edits must block navigation');
  await page.locator('[data-action="save"]').click();await page.waitForFunction(()=>window.__savedPreparation?.state==='confirmed');
  await page.waitForFunction(()=>!document.querySelector('[data-action="save"]').disabled);
  await page.waitForTimeout(700);
  assert.equal(await canNavigate(),true,'Saved worksheet must allow workflow navigation after Plate settles');
  await assertListContent();
  assert.equal(await page.locator('#ws-template-title').isEnabled(),true,'Project response mapping must not lock authoring after save');
  assert.equal(await page.locator('[data-slate-editor]').getAttribute('contenteditable'),'true');
  assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('criteria-fixture')).document.settings.assessment_project.project_id),'01K00000000000000000090001');
  await page.reload();await page.waitForFunction(()=>document.querySelector('[data-role="save-status"]').textContent==='저장됨');
  assert.equal(await app.getAttribute('data-workflow-stage'),'author','Saved project mapping must not reopen criteria approval');
  await page.waitForTimeout(700);
  assert.equal(await canNavigate(),true,'Reloaded saved list must allow navigation');
  await assertListContent();
  const titleBeforeEdit = await page.locator('#ws-template-title').inputValue();
  await page.locator('#ws-template-title').fill(titleBeforeEdit+' - edited');
  assert.equal(await canNavigate(),false,'Unsaved title must block navigation');
  await page.evaluate(() => { window.__failSave = true; });
  await page.locator('[data-action="save"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-role="notice"]').textContent.includes('저장 시험 오류'));
  assert.equal(await canNavigate(),false,'Failed save must not clear unsaved changes');
  assert.equal(await page.locator('#ws-template-title').inputValue(),titleBeforeEdit+' - edited','Failed save must preserve the input');
  await page.evaluate(() => { window.__failSave = false; });
  await page.locator('[data-action="save"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-role="save-status"]').textContent==='저장됨');
  assert.equal(await canNavigate(),true,'Successful retry must clear unsaved changes');
  await page.locator('[data-action="save"]').click();
  await page.waitForFunction(()=>!document.querySelector('[data-action="save"]').disabled);
  await page.waitForTimeout(700);
  assert.equal(await canNavigate(),true,'Repeated no-change save must allow navigation');
  await assertListContent();
  await openInspector();
  await page.locator('[data-inspector-tab="goals"]').click();
  await page.locator('.hws-response-node__preview').first().click();
  assert.equal(await page.locator('[data-inspector-tab="question"]').getAttribute('aria-selected'),'true','Response selection must open question settings');
  assert.equal(await page.getByRole('combobox',{name:'응답 형식',exact:true}).isVisible(),true);
  assert.equal(await page.locator('.hws-response-inspector:not([hidden])').evaluate(e=>getComputedStyle(e).display),'grid','Inspector stylesheet must not be nested inside the paper selector');
  for (const width of [1024,760,390]) {
    await page.setViewportSize({width,height:900});
    await assertFit();
    const toolbarFit=await page.locator('.hws-worksheet-toolbar').evaluate(e=>{
      const bounds=e.getBoundingClientRect();
      return [...e.querySelectorAll('button')].filter(b=>b.getBoundingClientRect().width>0).every(b=>{
        const box=b.getBoundingClientRect();return box.left>=bounds.left-1&&box.right<=bounds.right+1;
      });
    });
    assert.equal(toolbarFit,true,`All toolbar commands must fit at ${width}px`);
    await screenshot(`project-author-${width}`);
  }
  await page.setViewportSize({width:1440,height:1000});
  await assertFit();await screenshot('05-project-editor');
  assert.equal(await page.locator('[data-workflow-select]').isVisible(),false,'Desktop must not show a second step selector');
  assert.equal(await page.locator('.oj-ai-project-workspace').count(),1,'Repeated asset initialization must not duplicate the project flow');
  assert.equal(await page.locator('.hws-response-inspector__criteria').first().isVisible(),false,'Project mapping must have one configuration surface');
  await page.evaluate(() => {
    const latest=structuredClone(projectFixture);
    latest.revision_id='01K00000000000000000092001';latest.revision_no=3;
    latest.content_hash='c'.repeat(64);latest.rubric_content_hash='d'.repeat(64);
    latest.configuration.criteria[0].label='수정된 평가 요소';
    latest.configuration.criteria[0].description='수정된 평가 방법';
    latest.configuration.learning_goals[0].text='수정된 학습 목표';
    latest.configuration.achievement_standards[0].text='수정된 성취기준';
    localStorage.setItem('criteria-latest-project',JSON.stringify(latest));
  });
  await page.reload();
  await page.waitForFunction(()=>document.querySelector('[data-role="save-status"]').textContent==='저장됨');
  await openInspector();
  await page.locator('[data-inspector-tab="assessment"]').click();
  assert.match(await page.locator('[data-role="project-criteria-version"]').innerText(),/적용 기준 2차.*최신 기준 3차/);
  assert.match(await page.locator('[data-role="assessment-map"]').innerText(),/이 평가만의 채점 기준/,'Old pinned criterion must remain until explicit application');
  await page.locator('#ws-template-title').fill('Unsaved title');
  await page.locator('[data-action="refresh-project-criteria"]').click();
  assert.match(await app.locator('.oj-ai-workbench__notice[data-role="notice"]').innerText(),/작성 중인 내용을 저장/);
  await page.locator('[data-action="save"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-role="save-status"]').textContent==='저장됨');
  await page.evaluate(()=>{window.__failSave=true;});
  await page.locator('[data-action="refresh-project-criteria"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-role="notice"]').textContent.includes('저장 시험 오류'));
  assert.match(await page.locator('[data-role="assessment-map"]').innerText(),/이 평가만의 채점 기준/,'Failed refresh must retain old applied criterion');
  await page.evaluate(()=>{window.__failSave=false;});
  await page.locator('[data-action="refresh-project-criteria"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-role="notice"]').textContent.includes('최신 확정 기준 3차를 적용'));
  assert.match(await page.locator('[data-role="assessment-map"]').innerText(),/수정된 평가 요소/);
  assert.match(await page.locator('[data-role="assessment-map"]').innerText(),/수정된 평가 방법/);
  assert.equal(await page.locator('[data-field="assessment-target"]').first().inputValue(),responseTarget,'Existing response mapping must survive criteria refresh');
  assert.match(await page.locator('[data-role="basis-summary"]').textContent(),/수정된 학습 목표/);
  assert.match(await page.locator('[data-role="basis-summary"]').textContent(),/수정된 성취기준/);
  assert.equal(await canNavigate(),true,'Criteria refresh must save a clean editor state');
  await assertListContent();
  await page.reload();
  await page.waitForFunction(()=>document.querySelector('[data-role="save-status"]').textContent==='저장됨');
  assert.match(await page.locator('[data-role="project-criteria-version"]').innerText(),/적용 기준 3차/);
  assert.match(await page.locator('[data-role="basis-summary"]').textContent(),/수정된 평가 요소/);
  assert.equal(await page.locator('[data-action="refresh-project-criteria"]').isVisible(),false,'Applied latest revision must not offer another refresh');
  await screenshot('06-refreshed-project-criteria');
  for (const width of [1440, 1232, 1024, 760, 390]) {
    await page.setViewportSize({width,height:1000});
    assert.equal(await page.locator('.oj-ai-workbench__outline').isVisible(),false);
    assert.equal(await page.locator('.oj-ai-workbench__inspector').isVisible(),false);
    const canvas=await page.locator('.oj-ai-workbench__canvas').boundingBox();
    const workspace=await page.locator('.oj-ai-workbench__workspace').boundingBox();
    assert.ok(canvas.width>=workspace.width-3,'Closed panels must leave the full workspace width to the document');
    await page.locator('[data-action="toggle-outline"]').click();
    assert.equal(await page.locator('.oj-ai-workbench__outline').isVisible(),true);
    await page.locator('[data-action="toggle-inspector"]').click();
    assert.equal(await page.locator('.oj-ai-workbench__outline').isVisible(),false,'Only one panel may occupy the authoring surface');
    assert.equal(await page.locator('.oj-ai-workbench__inspector').isVisible(),true);
    await assertFit(); await screenshot(`07-panel-${width}`);
    await page.getByRole('button',{name:'설정 닫기',exact:true}).click();
    assert.equal(await page.locator('[data-action="toggle-inspector"]').getAttribute('aria-expanded'),'false');
    assert.equal(await page.locator('[data-action="toggle-inspector"]').evaluate(e=>e===document.activeElement),true,'Closing a panel must return keyboard focus');
    assert.equal(await canNavigate(),true,'Panel controls must not dirty the worksheet');
    await assertFit(); await screenshot(`08-full-canvas-${width}`);
  }
  await page.setViewportSize({width:1232,height:900});
  await page.locator('[data-workflow-step="review"]').click();
  await page.locator('[data-role="worksheet-review-stage"] [data-action="preview-open"]').click();
  for (const kind of ['student','print']) {
    await page.locator(`[data-action="preview-${kind}"]`).click();
    await page.locator('.oj-ai-workbench__preview-document ol').waitFor();
    const lists=await page.locator('.oj-ai-workbench__preview-document').evaluate(e=>({
      start:e.querySelector('ol').start,
      ordered:getComputedStyle(e.querySelector('ol')).listStyleType,
      item:getComputedStyle(e.querySelector('ol > li')).listStyleType,
      nested:getComputedStyle(e.querySelector('ul')).listStyleType,
      padding:parseFloat(getComputedStyle(e.querySelector('ol')).paddingInlineStart),
      bold:e.querySelector('ol strong')?.textContent,
      text:e.querySelector('ol').textContent
    }));
    assert.equal(lists.start,5);
    assert.equal(lists.ordered,'decimal'); assert.equal(lists.item,'decimal');
    assert.equal(lists.nested,'disc'); assert.ok(lists.padding>=24);
    assert.equal(lists.bold,'the evidence'); assert.match(lists.text,/Keep nested evidence/);
    const selectedTab=page.locator(`[data-action="preview-${kind}"]`);
    assert.equal(await selectedTab.evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(48, 54, 64)','Selected preview tab must retain contrasting text and background');
    await screenshot(`09-${kind}-numbered-preview`);
    await page.setViewportSize({width:390,height:844});
    await assertFit(); await screenshot(`09-${kind}-numbered-preview-mobile`);
    await page.setViewportSize({width:1232,height:900});
  }
  await page.locator('.oj-ai-workbench__preview-close').click();
  await page.locator('[data-workflow-step="author"]').click();
  await page.waitForFunction(()=>document.querySelector('#oj-ai-worksheet-authoring-app').dataset.workflowStage==='author' && document.querySelector('[data-role="save-status"]').textContent==='저장됨');
  await openInspector();
  await page.getByRole('button',{name:'설정 닫기',exact:true}).focus();
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('.oj-ai-workbench__inspector').isVisible(),false,'Escape must close the focused settings panel');
  const questionCount=await page.locator('.hws-question-block').count();
  await page.locator('[data-action="add-question"]').click();
  await page.waitForFunction(count=>document.querySelectorAll('.hws-question-block').length===count+1,questionCount);
  assert.equal(await canNavigate(),false,'The always-visible add-question control must create a real edit');

  await page.evaluate(() => {
    const stored=JSON.parse(localStorage.getItem('criteria-fixture'));
    delete stored.document.settings.assessment_project;
    stored.document.settings.preparation={state:'draft',rubric_revision_id:null,rubric_content_hash:null};
    localStorage.setItem('criteria-fixture',JSON.stringify(stored));
  });
  const migrated = await page.context().newPage();
  migrated.on('pageerror', error => errors.push(error.message));
  await migrated.exposeFunction('canonicalizePreparation', document => JSON.parse(execFileSync(php, ['tests/fixtures/canonicalize-preparation-document.php'], {cwd:root,input:JSON.stringify(document),encoding:'utf8'})));
  await migrated.goto(page.url());
  await migrated.waitForFunction(()=>document.querySelector('[data-role="save-status"]').textContent==='저장됨');
  assert.equal(await migrated.locator('[data-role="preparation-required"]').isVisible(),true,'Unconfirmed migrated drafts must not silently adopt current criteria');
  assert.equal(await migrated.locator('[data-slate-editor]').getAttribute('contenteditable'),'false');
  assert.match(await migrated.locator('[data-role="project-criteria-version"]').textContent(),/프로젝트 기준 적용 전/);
  assert.equal(await migrated.locator('[data-action="confirm-criteria"]').count(),0,'Migration must not render retired confirmation UI');
  await migrated.locator('[data-action="refresh-project-criteria"]').click();
  await migrated.waitForFunction(()=>document.querySelector('[data-slate-editor]').getAttribute('contenteditable')==='true');
  assert.equal(await migrated.evaluate(()=>JSON.parse(localStorage.getItem('criteria-fixture')).document.settings.assessment_project.revision_id),'01K00000000000000000092001');
  await migrated.reload();
  await migrated.waitForFunction(()=>document.querySelector('[data-role="save-status"]').textContent==='저장됨');
  assert.equal(await migrated.locator('[data-slate-editor]').getAttribute('contenteditable'),'true','Explicitly applied migration criteria must survive reload');
  await migrated.screenshot({path:path.join(artifacts,'10-migrated-project-author.png'),fullPage:true});
  await migrated.close();
  assert.deepEqual(errors, []);
  const result = {passed:true,checks:['single project workflow navigation','PHP canonical save','save failure gate','reload','embedded review without automatic modal','review preview','mobile panel disclosure','desktop and mobile overflow','assessment-owned criteria and achievement standards','project pin canonical save and reload','saved list navigation after load/save/reload','ordered and nested list structure and bold text preserved','real list edit and undo navigation protection','title edit and response mapping navigation protection','failed save preserves edits and blocks navigation','successful retry and repeated save allow navigation','migrated draft read-only until explicit confirmed criteria application','migrated criteria save and reload without retired confirmation UI'],runtimeErrors:errors};
  result.checks.push('applied and latest criteria revision visibility','explicit criteria refresh and reload','refreshed label/method/goals/standards','response mapping survives criteria refresh','unsaved input blocks criteria refresh','failed refresh retains prior criteria');
  result.checks.push('full-width authoring at five viewport widths','exclusive accessible side panels and focus return','panel controls preserve saved state','PHP student and print projections retain ordered and nested list markers/start/bold');
  result.checks.push('mobile preview and selected tab contrast','panel Escape and always-visible question insertion');
  result.checks.push('outline drag resizing and bounds','keyboard resize and Escape cancellation','outline width persistence and responsive clamping','wrapped long outline titles','outline resizing does not dirty worksheet');
  await writeFile(path.join(artifacts,'result.json'),JSON.stringify(result,null,2));
  console.log(JSON.stringify(result));
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
}
