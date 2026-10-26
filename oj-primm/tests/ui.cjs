const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
  const root = path.resolve(__dirname, '..');
  const platform = path.resolve(root, '../oj-integration-rebuild/oj-platform');
  const output = path.resolve(root, '../_notes/verification/2026-10-25/oj-primm-1.0');
  fs.mkdirSync(output, {recursive:true});
  const html = {};
  for (const mode of ['student','teacher']) html[mode] = execFileSync(process.env.PHP_BINARY || 'D:/php/php.exe', ['tests/render-ui.php',mode],{cwd:root,encoding:'utf8'});
  const keys = ['predict','run','investigate','modify','make'];
  const activities = [
    {id:1,title:'조건을 읽고 합계 만들기',target_problem_id:100,target_title:'합계',context_type:'class',context_id:'class:7',support_kind:'code_ordering',support_problem_id:101,support_title:'도입 코드 배열',demo_code:'sum'},
    {id:2,title:'빈칸으로 조건문 연습',target_problem_id:100,target_title:'합계',context_type:'class',context_id:'class:7',support_kind:'code_completion',support_problem_id:102,support_title:'도입 빈칸 완성',demo_code:'condition'},
    {id:3,title:'도입 자료 준비 확인',target_problem_id:100,target_title:'합계',context_type:'class',context_id:'class:7',support_kind:'none',support_problem_id:null,demo_code:'none'},
    {id:4,title:'AI가 만든 합계 수업',target_problem_id:100,target_title:'합계',context_type:'class',context_id:'class:7',support_kind:'none',support_problem_id:null,demo_code:'generated',generation_kind:'ai'}
  ];
  const attempts = {};
  const emptyAttempt = () => ({version:0,current_stage:'predict',responses:{},draft_code:'',support_state:'',completed_stages:[],submission_id:null,result_code:null,is_accepted:false,status:'in_progress',verified_at:null});
  const copy = value => JSON.parse(JSON.stringify(value));
  const attemptFor = id => attempts[id] || emptyAttempt();
  const oldSubmission={id:501,result_code:4,processing:false,label:'맞았습니다',submitted_at:'2026-10-24 12:00:00',verified_at:'2026-10-24 12:00:00',language:'python',draft_matches:false,detail_url:'/submissions/501?class_id=7'};
  const submissionRecords={},makeStates={};
  let submissionDelay=0,submissionFailure=0,watchFailure=0,disabledMake=false,progressDelay=0,launchTtl=300,unsafeLaunch=false;
  const handoffs={};let nextHandoff=1;
  function makeProjection(id,attempt){
    const available=!readOnlyPreview&&!disabledMake&&attempt.current_stage==='make';
    const saved=makeStates[id]||{status:'idle',watch:{active:false,started_at:null},submission:null,recent:null};
    return {available,status:available?saved.status:'unavailable',watch:copy(saved.watch),submission:saved.submission?copy(saved.submission):null,recent:saved.recent?copy(saved.recent):null};
  }
  function view(id,reviewStage) {
    const activity=activities.find(a=>a.id===id), originalAttempt=readOnlyPreview?emptyAttempt():attemptFor(id),attempt=reviewStage?{...originalAttempt,current_stage:reviewStage}:originalAttempt;
    const demo={key:activity.demo_code,code:activity.demo_code==='none'?'':'numbers = [1, 2, 3]\ntotal = 0\nfor number in numbers:\n    total += number\nprint(total)',input:''};
    if(attempt.current_stage!=='predict')demo.expected_output='6\n';
    const projected={activity,attempt:{...attempt,practice_passed:practicePassSource[id]!==undefined&&practicePassSource[id]===attempt.draft_code},practice_required:id===4&&practiceRequired,stages:keys.map(key=>({key,label:key,prompt:'단계별 생각을 기록하세요.'})),target:{id:100,title:'합계',description:'<p>입력한 수의 합을 출력하세요.</p>'},support:null,target_url:'/problems/100?class_id=7',support_url:'/problems/'+activity.support_problem_id+'?class_id=7',read_only:false,stale:false,demo};
    if(id===4){
      const verification_note=verifiedLesson?'OJ에서 코드와 예상 출력의 일치를 확인했습니다.':'AI 예상 출력은 실제 실행으로 검증되지 않았습니다.';
      const verification=verifiedLesson?{status:'verified',engine:'oj',case_count:2,checked_at:'2026-10-24 15:00:00',repair_attempts:1,note:verification_note,actual_output:'DO_NOT_RENDER_PRIVATE_STDOUT'}:{status:'not_executed',note:verification_note};
      projected.lesson_summary={title:activity.title,language:'python',objectives:['변수와 출력의 관계를 설명한다.'],verification_note,verification};
      projected.demo={code:'value = 2\nprint(value)',input:'',language:'python',verification_note,verification};
      if(attempt.current_stage!=='predict'||readOnlyPreview){projected.demo.expected_output='2\n';projected.demo.explanation='변수의 값을 출력합니다.';}
      projected.stages=(readOnlyPreview?keys:[attempt.current_stage]).map(key=>({key,label:key,prompt:key==='predict'?'<img src=x onerror="window.primmInjected=1"> 설명을 읽어 보세요.':'변수의 값을 바꾸고 출력이 어떻게 달라지는지 확인하세요.',questions:[key==='make'?'제작한 프로그램을 어떤 입력으로 확인하나요?':'값은 어디서 바뀌나요?'],task:key==='modify'?'값을 3으로 바꾸세요.':'',starter_code:key==='modify'?'value = 2\nprint(value)':'',tests:key==='modify'?[{input:'',expected_output:'3\n'}]:[],hints:key==='modify'?['변수 대입문을 살펴보세요.']:[]}));
      if(readOnlyPreview){projected.read_only=true;projected.activity={...activity,support_kind:'code_completion'};}
    }
    projected.make_submission=makeProjection(id,attempt);
    if(reviewStage){projected.attempt=originalAttempt;projected.display_stage=reviewStage;projected.review_only=true;projected.make_submission.available=false;projected.make_submission.status='unavailable';}
    return copy(projected);
  }
  const settings={language:'python',default_language:'python',level:'beginner',prerequisites:[],minutes:45,support_kind:'none',auto_prepare:true,auto_publish:true};
  const managed={};
  function manager(id){if(!managed[id]){const activity=activities.find(item=>item.id===id);managed[id]={activity,revision:'r1',state:'active',versions:[{id,version:1,state:'active'}],lesson:{title:activity.title,language:'python',objectives:['변수 값을 설명한다.'],demo:{code:'value = 2\nprint(value)\n',input:'',expected_output:'2\n',explanation:'변수를 출력합니다.'},stages:keys.map(key=>({key,label:key,prompt:key+' 안내',questions:[key+' 질문'],...(key==='modify'?{task:'3으로 수정하세요.',starter_code:'value = 3\nprint(value)\n',tests:[{input:'',expected_output:'3\n'}]}:{})})),support:{kind:'none'},verification:{status:'verified',engine:'oj'}}};}return managed[id];}
  const ids=['a'.repeat(32),'b'.repeat(32),'c'.repeat(32),'d'.repeat(32),'e'.repeat(32)];
  const ordering={problem_id:101,language:'python',template_hash:'1'.repeat(64),blocks:[{id:ids[1],code:'b = 2\n'},{id:ids[2],code:'total = a + b\n'},{id:ids[0],code:'a = 1\n'},{id:ids[4],code:'print(total)\n'},{id:ids[3],code:'total += 1\n'}]};
  const completion={problem_id:102,language:'python',template_hash:'2'.repeat(64),starter_code:'score = {{OJ_BLANK:value}}\nlabel = {{OJ_BLANK:__proto__}}\nif score >= 60:\n    print("통과")\n',blanks:[{id:'value',label:'점수',kind:'inline',max_bytes:10},{id:'__proto__',label:'안내 문자열',kind:'inline',max_bytes:100}]};
  const calls=[];
  let emptyList=false, denyTeacher=false, reportAtLimit=false, aiAvailable=true, generationShouldFail=false, recoverLease=false, readOnlyPreview=false,verifiedLesson=false,validatePipeline=false,practiceRequired=false,executionAvailable=true,executionLanguages=['python','c','cpp','java'];
  const practicePassSource={};
  const jobs={},recoverAfterRunning={};let nextJob=1;
  const browser=await chromium.launch({headless:true,channel:'chrome',ignoreDefaultArgs:['--disable-back-forward-cache']});
  try {
    const context=await browser.newContext({viewport:{width:1280,height:920}});
    await context.addInitScript(()=>{window.primmBfcacheRestores=0;window.addEventListener('pageshow',event=>{if(event.persisted)window.primmBfcacheRestores++;});});
    const page=await context.newPage();
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await context.route('**/*',async route=>{
      const req=route.request(),url=new URL(req.url());assert.equal(url.hostname,'primm.test');
      const json=(data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
      if(url.pathname.startsWith('/api/')) {
        assert.equal(req.headers()['x-wp-nonce'],'ui-nonce');
        const endpoint=url.pathname.slice(4);let body=req.method()==='POST'?JSON.parse(req.postData()):null;
        calls.push({endpoint,body,query:Object.fromEntries(url.searchParams)});
        if(endpoint==='/teacher/contexts')return denyTeacher?json({message:'담당 수업 권한이 없습니다.'},403):json([{type:'class',id:'class:7',label:'내 담당 수업'}]);
        if(endpoint==='/teacher/settings'){if(body){Object.assign(settings,body);settings.default_language=body.language;}return json(copy(settings));}
        if(endpoint==='/teacher/report.csv')return route.fulfill({status:200,contentType:'text/csv',headers:{'Content-Disposition':'attachment; filename=primm-progress.csv'},body:'student,status\n학생1,not_started\n학생2,in_progress\n'});
        if(endpoint==='/teacher/report')return json({total:2,counts:{not_started:1,in_progress:1,process_complete:0,accepted:0},items:url.searchParams.get('cursor')?[{user_id:21,display_name:'학생 둘',attempt:{...emptyAttempt(),responses:{predict:'두 번째 학생의 생각'}},current_stage:'predict'}]:[{user_id:20,display_name:'학생 하나',attempt:null}],next_cursor:url.searchParams.get('cursor')?'':'cursor-page-2'});
        if(endpoint==='/teacher/generations-summary')return json({jobs:Object.values(jobs),usage:{ai_calls:3,execution_count:6},sample_limit:100,statuses:{queued:2,failed:1},daily_usage:{date:'2026-10-25',ai_calls:9},automatic_preparation:{last_code:'auto_prepare_context_unavailable',last_at:'2026-10-25 10:00:00',counts:{auto_prepare_enqueued:3,auto_prepare_context_unavailable:2}},background_error:{code:'background_storage_or_runtime',type:'PRIVATE_CLASS_NAME',at:'2026-10-25 10:01:00'}});
        const managementMatch=endpoint.match(/^\/teacher\/activities\/(\d+)\/(manage|edit|publish|withdraw|clone|validate|regenerate)$/);
        if(managementMatch){const id=Number(managementMatch[1]),operation=managementMatch[2],current=manager(id);if(operation==='manage')return json(copy(current));
          if(operation==='edit'||operation==='clone'){const newId=activities.length+1,activity={...current.activity,id:newId,title:body.patch?body.patch.title:current.activity.title,version:2};activities.push(activity);managed[newId]={activity,revision:'r2',state:'draft',versions:[...current.versions,{id:newId,version:2,state:'draft'}],lesson:body.patch?copy(body.patch.lesson):copy(current.lesson)};if(operation==='edit'){managed[newId].lesson.verification={status:'not_executed'};}return json(copy(managed[newId]));}
          if(operation==='publish'||operation==='withdraw'){current.state=operation==='publish'?'active':'withdrawn';return json(copy(current));}
          const jobId=nextJob++;jobs[jobId]={id:jobId,status:'queued',phase:'verifying',target_problem_id:100,language:'python',activity_id:null,target_activity_id:id,can_cancel:true};return json(copy(jobs[jobId]));
        }
        if(endpoint==='/teacher/generation-status')return json({available:aiAvailable&&executionAvailable,languages:executionLanguages,execution_available:executionAvailable,execution_languages:executionLanguages,message:aiAvailable?'AI 생성 준비 완료':'플랫폼 AI 작업 설정이 필요합니다.'});
        if(endpoint==='/teacher/generations'&&body){const id=nextJob++;jobs[id]={id,status:'queued',phase:'generating',repair_attempt:0,max_repairs:2,step:0,activity_id:null,error:'',templateOnly:body.use_template===true,updated_at:'2026-10-24 13:00:00',language:body.language,support_kind:body.support_kind,target_problem_id:body.target_problem_id};return json(copy(jobs[id]));}
        const generationMatch=endpoint.match(/^\/teacher\/generations\/(\d+)(.*)$/);
        if(generationMatch){
          const job=jobs[Number(generationMatch[1])],suffix=generationMatch[2];if(!job)return json({message:'본인의 생성 작업이 아닙니다.'},403);
          if(suffix==='/cancel'){job.status='canceled';job.can_cancel=false;job.can_resume=true;return json(copy(job));}
          if(suffix==='/resume'){job.status='queued';job.can_cancel=true;job.can_resume=false;return json(copy(job));}
          if(suffix==='/stop'){job.status='stopped';return json(copy(job));}
          if(suffix==='/retry'){job.status='queued';job.phase='generating';job.step=0;job.repair_attempt=0;job.error='';return json(copy(job));}
          if(suffix==='/process'){
            job.status='running';
            if(recoverLease&&!recoverAfterRunning[job.id]){recoverAfterRunning[job.id]='running';return json(copy(job));}
            await new Promise(resolve=>setTimeout(resolve,700));
            if(generationShouldFail){job.status='failed';job.phase='verifying';job.error='자동 수정 한도 안에 코드와 예상 출력의 일치를 확인하지 못했습니다.';return json(copy(job));}
            if(validatePipeline&&job.step<3){job.status='queued';job.phase=job.step===1?'repairing':'verifying';job.repair_attempt=job.step===0?0:1;job.message=job.phase==='repairing'?'출력 불일치를 수정하고 있습니다.':'생성한 도입 예제와 수정 기준 코드를 확인합니다.';job.step++;return json(copy(job));}
            job.status='ready';job.phase='ready';job.activity_id=job.target_activity_id||4;job.error='';job.message='';job.verification={status:'verified',engine:'oj',case_count:2,checked_at:'2026-10-24 15:00:00',repair_attempts:job.repair_attempt};if(job.templateOnly){job.activity_state='review';job.quality_summary={status:'needs_review',reasons:['기본 예제와 목표 문제의 연관성을 확인하세요.']};}if(job.target_activity_id){managed[job.target_activity_id].lesson.verification=copy(job.verification);}return json(copy(job));
          }
          if(recoverAfterRunning[job.id]==='running'){job.status='queued';recoverAfterRunning[job.id]='recovered';}
          return json(copy(job));
        }
        if(endpoint==='/teacher/activities')return body?json({...activities[0],id:8}):json(reportAtLimit
          ? {activities:Array.from({length:100},(_,index)=>({...activities[0],id:1000+index})),attempts:Array.from({length:200},(_,index)=>({...emptyAttempt(),activity_id:1000,user_id:2000+index})),report_limit:200}
          : {activities:url.searchParams.get('cursor')?[{...activities[0],id:90,title:'이전 학기 활동'}]:activities,attempts:Object.entries(attempts).map(([id,a])=>({...a,activity_id:Number(id),user_id:19})),report_limit:200,next_cursor:url.searchParams.get('cursor')?'':'activity-page-2'});
        if(endpoint==='/problems')return json([{id:100,title:'합계'},{id:101,title:'도입 코드 배열'}]);
        if(endpoint==='/activities')return json(emptyList?[]:activities.map(a=>({...a,attempt:attemptFor(a.id)})));
        const match=endpoint.match(/^\/activities\/(\d+)(.*)$/);if(!match)return json({message:'없음'},404);
        const id=Number(match[1]),suffix=match[2];
        if(!suffix){const reviewStage=url.searchParams.get('stage');if(reviewStage&&!attemptFor(id).completed_stages.includes(reviewStage))return json({message:'완료한 단계만 다시 읽을 수 있습니다.'},403);return json(view(id,reviewStage));}
        if(suffix==='/practice'){if(!body.ticket)return json({ticket:'1',status:'pending',results:[],all_passed:false});if(body.source.includes('3')){practicePassSource[id]=body.source;}return json({ticket:body.ticket,status:'completed',results:[{index:1,verdict:body.source.includes('3')?'accepted':'wrong_answer',actual_output:body.source.includes('3')?'3\n':'2\n'}],all_passed:body.source.includes('3'),checked_at:'2026-10-25 10:00:00'});}
        if(suffix==='/submission-watch'||suffix==='/submission-launch'||suffix==='/submission-check'){
          const current=attemptFor(id);
          if(disabledMake||readOnlyPreview)return json({message:'현재 제작 활동에서 제출을 확인할 권한이 없습니다.'},403);
          if(body.expected_version!==current.version)return json({message:'기록 버전이 바뀌었습니다.'},409);
          if(suffix!=='/submission-check'&&watchFailure)return json({message:'제출 확인을 준비하지 못했습니다.'},watchFailure);
          if(suffix==='/submission-check'&&submissionFailure)return json({message:'제출 결과 조회 권한이 없습니다.'},submissionFailure);
          if(submissionDelay)await new Promise(resolve=>setTimeout(resolve,submissionDelay));
          const next=copy(current),records=submissionRecords[id]||[oldSubmission],saved=makeStates[id]||{status:'idle',watch:{active:false,started_at:null},submission:null,recent:null};
          next.version++;
          if(suffix!=='/submission-check'){
            if(suffix==='/submission-launch'&&!String(current.draft_code||'').trim())return json({message:'채점 화면에 채울 프로그램 초안을 먼저 작성해 주세요.'},400);
            if(!saved.watch.active){makeStates[id]={status:'watching',watch:{active:true,started_at:new Date().toISOString()},submission:null,recent:null,baseline:Math.max(0,...records.map(item=>item.id))};next.submission_id=null;next.is_accepted=false;next.result_code=null;}
          }else{
            let selected=null;
            if(body.mode==='latest')selected=records.at(-1)||null;
            else if(next.submission_id)selected=records.find(item=>item.id===next.submission_id)||null;
            else if(saved.watch.active)selected=records.find(item=>item.id>(saved.baseline||0))||null;
            if(selected){saved.submission=copy(selected);saved.recent=copy(selected);saved.status=selected.processing?'processing':selected.result_code===4?'accepted':'judged';saved.watch.active=selected.processing;next.submission_id=selected.id;next.result_code=selected.result_code;next.is_accepted=!selected.processing&&selected.result_code===4;next.verified_at=selected.verified_at;}
            makeStates[id]=saved;
          }
          attempts[id]=next;const launched=view(id);
          if(suffix==='/submission-launch'){
            const token='opaque-ticket-'+nextHandoff++,expires_at=Math.floor(Date.now()/1000)+launchTtl;
            handoffs[token]={source:current.draft_code,language:'python',expires_at};
            launched.submission_launch={url:unsafeLaunch?'https://unsafe.test/submit/100?draft='+token:'/submit/100?class_id=7&draft='+token,language:'python',expires_at};
          }
          return json(launched);
        }
        if(suffix==='/progress') {
          if(progressDelay)await new Promise(resolve=>setTimeout(resolve,progressDelay));
          if(body.advance)await new Promise(resolve=>setTimeout(resolve,250));
          const current=attemptFor(id);
          if(body.expected_version!==current.version||body.stage!==current.current_stage)return json({message:'기록 버전이 바뀌었습니다.'},409);
          if(body.advance&&!String(body.responses[body.stage]||'').trim())return json({message:'응답을 기록해 주세요.'},400);
          if(body.submission_id===999)return json({message:'목표 문제에 대한 본인의 실제 제출 번호를 입력해 주세요.'},403);
          const next=copy(current);next.version++;next.responses={...next.responses,...body.responses};
          if(body.draft_code!==undefined)next.draft_code=body.draft_code;
          if(body.support_state!==undefined)next.support_state=body.support_state;
          if(body.advance){next.completed_stages=[...new Set([...next.completed_stages,body.stage])];if(body.stage==='make')next.status='process_complete';else next.current_stage=keys[keys.indexOf(body.stage)+1];}
          if(body.submission_id===501){next.submission_id=501;next.result_code=4;next.is_accepted=true;next.verified_at='2026-10-24 12:00:00';}
          attempts[id]=next;return json(view(id));
        }
        if(suffix==='/support')return json(id===1?ordering:completion);
        if(suffix==='/support/assemble'){
          if(body.template_hash!==(id===1?ordering:completion).template_hash)return json({message:'도움 문제 버전이 변경되었습니다.'},409);
          return json({problem_id:id===1?101:102,template_hash:body.template_hash,language:'python',source:id===1?body.block_ids.map(blockId=>ordering.blocks.find(b=>b.id===blockId).code).join(''):'score = '+body.answers.value+'\nlabel = '+body.answers.__proto__+'\nif score >= 60:\n    print("통과")\n'});
        }
        return json({message:'없음'},404);
      }
      if(url.pathname==='/primm.js')return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(root,'resources/js/primm.js'))});
      if(url.pathname==='/primm.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'resources/css/primm.css'))});
      if(url.pathname==='/platform.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(platform,'resources/ui/css/oj-ui-system.css'))});
      if(url.pathname==='/submit/100'){
        const delivery=handoffs[url.searchParams.get('draft')],valid=delivery&&delivery.expires_at*1000>Date.now()&&url.searchParams.get('class_id')==='7';
        if(!valid)return route.fulfill({status:410,contentType:'text/html',body:'<!doctype html><meta charset=UTF-8><h1>초안 전달 시간이 지났습니다. PRIMM에서 다시 준비하세요.</h1>'});
        const payload=JSON.stringify(delivery).replace(/</g,'\\u003c');
        return route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset=UTF-8><title>기존 OJ 제출 화면</title><h1>초안과 언어를 확인한 뒤 직접 제출하세요</h1><form><textarea name=source></textarea><select name=language><option value=c>C</option><option value=python>Python</option></select><button type=submit>직접 제출</button></form><script>const delivery='+payload+';document.querySelector("[name=source]").value=delivery.source;document.querySelector("[name=language]").value=delivery.language;window.nativeSubmitCount=0;document.querySelector("form").addEventListener("submit",event=>{event.preventDefault();window.nativeSubmitCount++;});</script>'});
      }
      const mode=url.pathname==='/teacher'?'teacher':'student';
      return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="ko"><meta charset=UTF-8><meta name=viewport content="width=device-width,initial-scale=1"><link rel=stylesheet href=/platform.css><link rel=stylesheet href=/primm.css><body class=ojp-app><main class=ojp-shell>'+html[mode]+'</main><script defer src=/primm.js></script></body></html>'});
    });
    async function response(text){await page.locator('[name=stage_response]').fill(text);}
    async function next(stage){await page.getByRole('button',{name:'저장하고 다음 단계',exact:true}).click();assert(await page.locator('[name=stage_response]').isDisabled(),'pending stage advance locks editing');await page.waitForSelector('[data-primm-stage='+stage+']');}
    async function bfcacheReturn(id){
      await page.locator('[name=draft_code]').fill('print("BFCACHE_DRAFT")');await response('뒤로 돌아와도 보존할 제작 응답');await page.getByRole('button',{name:'지금 저장',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('저장되었습니다'));
      await page.evaluate(()=>{window.open=()=>null;});await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).click();await page.getByRole('link',{name:'이 탭에서 채점 화면 열기',exact:true}).waitFor();
      await page.evaluate(()=>{window.primmBfcacheEditor=document.querySelector('[name=draft_code]');});
      const watches=calls.filter(call=>call.endpoint==='/activities/'+id+'/submission-launch').length;
      await page.getByRole('link',{name:'이 탭에서 채점 화면 열기',exact:true}).click();await page.waitForURL(/\/submit\/100\?class_id=7&draft=/);
      assert.equal(await page.locator('[name=source]').inputValue(),'print("BFCACHE_DRAFT")');assert.equal(await page.evaluate(()=>window.nativeSubmitCount),0,'same-tab fallback only fills the native editor; it never submits');
      submissionRecords[id]=[copy(oldSubmission),{...copy(oldSubmission),id:504,result_code:2,processing:true,label:'컴파일 중',detail_url:'/submissions/504?class_id=7'}];
      await page.goBack({waitUntil:'commit'});await page.waitForSelector('[data-primm-make-submission]');
      assert.equal(await page.evaluate(()=>window.primmBfcacheRestores),1,'the native same-tab fallback actually restores Chrome BFCache (pageshow.persisted), not a reload');
      assert(await page.evaluate(()=>window.primmBfcacheEditor===document.querySelector('[name=draft_code]')),'BFCache keeps the exact editor DOM instance');
      await page.getByText('채점 중입니다. 결과가 나오면 확인합니다.',{exact:true}).waitFor();
      assert.equal(attempts[id].submission_id,504,'pageshow resumes the existing watch and links a new submission');
      assert.equal(calls.filter(call=>call.endpoint==='/activities/'+id+'/submission-launch').length,watches,'BFCache return does not prepare another draft or reset the watch');
      assert.equal(await page.locator('[name=draft_code]').inputValue(),'print("BFCACHE_DRAFT")');assert.equal(await page.locator('[name=stage_response]').inputValue(),'뒤로 돌아와도 보존할 제작 응답');
      const before=calls.filter(call=>call.endpoint==='/activities/'+id+'/submission-check').length;
      await page.getByRole('button',{name:'채점 결과 다시 확인',exact:true}).click();await page.waitForTimeout(100);
      assert.equal(calls.filter(call=>call.endpoint==='/activities/'+id+'/submission-check').length,before+1,'manual result controls remain alive after BFCache restoration');
      await page.screenshot({path:path.join(output,'make-submission-bfcache.png'),fullPage:true});
    }
    if(process.argv.includes('--bfcache-only')){
      attempts[2]={...emptyAttempt(),current_stage:'make',completed_stages:['predict','run','investigate','modify']};
      await page.goto('http://primm.test/student?activity=2');await page.waitForSelector('[data-primm-make-submission]');await page.evaluate(()=>{window.open=()=>null;});
      await bfcacheReturn(2);
      assert.equal(errors.length,0,errors.join('\n'));console.log('PRIMM actual Chrome BFCache: same-tab native fallback, persisted DOM/response/draft, pageshow watch resume and live manual controls passed.');return;
    }
    await page.goto('http://primm.test/student?activity=1');await page.waitForSelector('[data-primm-stage=predict]');
    assert(!await page.getByText('6',{exact:true}).count(),'predict must not reveal expected output');
    await response('6이 출력될 것 같습니다. 반복하면서 더합니다.');
    await page.getByRole('button',{name:'지금 저장',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('저장되었습니다'));
    await page.reload();await page.waitForSelector('[data-primm-stage=predict]');assert.equal(await page.locator('[name=stage_response]').inputValue(),'6이 출력될 것 같습니다. 반복하면서 더합니다.');
    await next('run');assert.equal(await page.getByRole('button',{name:'검증된 예제 결과 확인'}).count(),1);
    assert.equal(await page.getByRole('link',{name:'코드 실습실 열기'}).count(),0,'optional Code Lab must not create a broken dependency link');
    await page.getByRole('button',{name:'검증된 예제 결과 확인'}).click();assert(await page.locator('.ojprimm-stage pre').last().textContent().then(t=>t.includes('6')));
    await response('실행 기록은 6이고 예측과 같습니다.');await next('investigate');await response('total은 반복할 때마다 증가합니다.');await next('modify');
    await page.waitForSelector('[data-primm-ordering]');assert.equal(await page.locator('[data-primm-ordering] li').count(),5);
    assert((await page.locator('[data-primm-ordering]').boundingBox()).height<=217,'five single-line pieces remain compact on desktop');
    const compactCode=await page.locator('[data-primm-ordering] pre').first().evaluate(item=>({margin:getComputedStyle(item).margin,padding:getComputedStyle(item).padding,text:item.textContent}));
    assert.equal(compactCode.margin,'0px');assert.equal(compactCode.padding,'0px');assert(!compactCode.text.endsWith('\n'),'display removes only terminal code newline');
    const original=await page.locator('[data-primm-ordering] li').evaluateAll(items=>items.map(i=>i.dataset.blockId));
    await page.locator('[data-primm-ordering] li').first().getByRole('button',{name:'아래로'}).click();
    assert.notDeepEqual(await page.locator('[data-primm-ordering] li').evaluateAll(items=>items.map(i=>i.dataset.blockId)),original);
    await page.getByRole('button',{name:'배열·빈칸 코드 복원 확인'}).click();await page.waitForSelector('[data-primm-restored-code]');
    assert((await page.locator('[data-primm-judge-status]').textContent()).includes('정답 제출 미확인'));
    await response('변수 대입 순서를 바꾸어 확인했습니다.');await page.getByRole('button',{name:'지금 저장',exact:true}).click();await page.waitForTimeout(200);
    const restored=await page.locator('[data-primm-ordering] li').evaluateAll(items=>items.map(i=>i.dataset.blockId));
    await page.reload();await page.waitForSelector('[data-primm-ordering]');assert.deepEqual(await page.locator('[data-primm-ordering] li').evaluateAll(items=>items.map(i=>i.dataset.blockId)),restored);
    await page.screenshot({path:path.join(output,'ordering-desktop.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    assert((await page.locator('[data-primm-ordering]').boundingBox()).height<=237,'five single-line pieces remain compact on mobile');
    await page.locator('[data-primm-ordering]').scrollIntoViewIfNeeded();
    const touchBefore=await page.locator('[data-primm-ordering] li').evaluateAll(items=>items.map(item=>item.dataset.blockId));
    const fromHandle=await page.locator('.ojprimm-piece-handle').first().boundingBox(),toPiece=await page.locator('[data-primm-ordering] li').last().boundingBox();
    const touchSession=await context.newCDPSession(page),touchX=fromHandle.x+fromHandle.width/2,touchY=fromHandle.y+fromHandle.height/2;
    await touchSession.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:touchX,y:touchY}]});
    for(let step=1;step<=6;step++)await touchSession.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:touchX,y:touchY+(toPiece.y+toPiece.height-6-touchY)*step/6}]});
    await touchSession.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
    assert.notDeepEqual(await page.locator('[data-primm-ordering] li').evaluateAll(items=>items.map(item=>item.dataset.blockId)),touchBefore,'touch handle drag reorders code pieces');
    await touchSession.detach();
    await page.screenshot({path:path.join(output,'ordering-mobile.png'),fullPage:true});
    await next('make');assert.equal(await page.locator('[name=submission_id]').count(),0,'Make no longer asks learners to discover a submission number');
    assert.equal(calls.filter(call=>call.endpoint==='/activities/1/submission-check').length,0,'entering Make never auto-links an old AC');
    const finalDraft='if True:\n    print("한글 😀 </script><img src=x onerror=window.nativeInjected=1>")\n';
    progressDelay=600;await response('여러 입력을 시험해서 해결했습니다.');
    const savingBeforeLaunch=page.waitForRequest(request=>request.url().endsWith('/activities/1/progress'));
    await page.locator('[name=draft_code]').fill('print("earlier autosave")');await savingBeforeLaunch;
    await page.locator('[name=draft_code]').fill(finalDraft);
    const launchCallsBefore=calls.filter(call=>call.endpoint==='/activities/1/submission-launch').length;
    const popupPromise=page.waitForEvent('popup');await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).click();const nativeTab=await popupPromise;
    assert(await page.locator('[name=draft_code]').isDisabled(),'preparing a handoff freezes the exact current code while prior autosave finishes');
    assert(await page.getByRole('button',{name:'학습 과정 마치기',exact:true}).isDisabled());assert(await page.getByRole('button',{name:'활동 목록',exact:true}).isDisabled());
    await nativeTab.waitForURL(/\/submit\/100\?class_id=7&draft=/);assert.equal(await nativeTab.evaluate(()=>window.opener),null,'the pre-opened native tab has no opener');
    progressDelay=0;assert(await page.locator('[name=draft_code]').isEnabled(),'preparation restores editing controls');
    const watchCall=calls.findLast(call=>call.endpoint==='/activities/1/submission-launch');assert.deepEqual(Object.keys(watchCall.body),['expected_version']);
    assert.equal(calls.filter(call=>call.endpoint==='/activities/1/submission-launch').length,launchCallsBefore+1,'one click prepares exactly one native handoff');
    assert.equal(attempts[1].draft_code,finalDraft,'the current draft is saved before establishing the watch baseline');
    assert.equal(calls.findLast(call=>call.endpoint==='/activities/1/progress').body.draft_code,finalDraft);
    assert.equal(await nativeTab.locator('[name=source]').inputValue(),finalDraft,'Unicode, HTML-like text, indentation and final newline reach the native editor exactly');
    assert.equal(await nativeTab.locator('[name=language]').inputValue(),'python');assert.equal(await nativeTab.evaluate(()=>window.nativeInjected),undefined);
    assert(!nativeTab.url().includes('print')&&!nativeTab.url().includes(encodeURIComponent('한글')),'code never appears in the native URL');
    assert.equal(await nativeTab.evaluate(()=>window.nativeSubmitCount),0,'opening a prepared native editor never submits code');
    await nativeTab.getByRole('button',{name:'직접 제출',exact:true}).click();assert.equal(await nativeTab.evaluate(()=>window.nativeSubmitCount),1,'the final native submit remains the learner’s explicit action');
    assert(await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).isEnabled(),'an unlinked watch permits explicit preparation again without resetting its baseline');
    assert.equal(await page.getByText('맞았습니다',{exact:true}).count(),0,'an existing AC before the watch baseline is not linked');
    submissionRecords[1]=[copy(oldSubmission),{...copy(oldSubmission),id:502,result_code:2,processing:true,label:'컴파일 중',draft_matches:true,detail_url:'/submissions/502?class_id=7'}];
    await nativeTab.close();await page.bringToFront();await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.getByText('채점 중입니다. 결과가 나오면 확인합니다.',{exact:true}).waitFor();
    assert.equal(attempts[1].submission_id,502);assert.equal(calls.findLast(call=>call.endpoint==='/activities/1/submission-check').body.mode,'watch');
    const periodicChecks=calls.filter(call=>call.endpoint==='/activities/1/submission-check').length;await page.waitForTimeout(4200);assert.equal(calls.filter(call=>call.endpoint==='/activities/1/submission-check').length,periodicChecks+1,'visible pending results poll once per interval without overlap');
    submissionDelay=600;submissionRecords[1][1]={...submissionRecords[1][1],result_code:4,processing:false,label:'맞았습니다',draft_matches:false};
    const concurrentChecks=calls.filter(call=>call.endpoint==='/activities/1/submission-check').length;
    await page.evaluate(()=>{window.primmMakeCode=document.querySelector('[name=draft_code]');for(let index=0;index<4;index++)window.dispatchEvent(new Event('focus'));});await page.waitForTimeout(80);
    await page.locator('[name=draft_code]').fill('print("typing while polling")');await response('결과를 기다리는 동안 작성한 성찰');
    await page.locator('[name=draft_code]').evaluate(input=>{input.focus();input.setSelectionRange(4,4);});
    await page.getByText('맞았습니다',{exact:true}).waitFor();
    assert.equal(calls.findLast(call=>call.endpoint==='/activities/1/submission-check').body.mode,'linked','once linked, polling remains fixed to that ID');
    assert.equal(calls.filter(call=>call.endpoint==='/activities/1/submission-check').length,concurrentChecks+1,'repeated return/focus events share one in-flight request');
    assert(await page.evaluate(()=>window.primmMakeCode===document.querySelector('[name=draft_code]')),'polling keeps the editor DOM');
    assert.equal(await page.locator('[name=draft_code]').inputValue(),'print("typing while polling")');assert.equal(await page.locator('[name=stage_response]').inputValue(),'결과를 기다리는 동안 작성한 성찰');
    assert.equal(await page.locator('[name=draft_code]').evaluate(input=>input.selectionStart),4,'polling preserves the editing cursor');
    assert((await page.locator('[data-primm-make-submission]').textContent()).includes('현재 초안'),'polling reports a changed or differing current draft without replacing it');
    submissionDelay=0;await page.getByRole('button',{name:'지금 저장',exact:true}).click();await page.waitForTimeout(100);
    submissionRecords[1][1].draft_matches=false;await page.getByRole('button',{name:'채점 결과 다시 확인',exact:true}).click();await page.getByText('현재 초안과 다른 코드의 제출 결과입니다.',{exact:true}).waitFor();
    submissionRecords[1][1].draft_matches=null;await page.getByRole('button',{name:'채점 결과 다시 확인',exact:true}).click();await page.getByText('제출 원문을 확인할 수 없어 현재 초안과 비교할 수 없습니다.',{exact:true}).waitFor();
    assert.equal(await page.getByRole('link',{name:'OJ에서 제출 결과 보기'}).getAttribute('href'),'http://primm.test/submissions/502?class_id=7');
    const completedChecks=calls.filter(call=>call.endpoint==='/activities/1/submission-check').length;await page.waitForTimeout(4200);assert.equal(calls.filter(call=>call.endpoint==='/activities/1/submission-check').length,completedChecks,'final judgements stop the timer');
    submissionRecords[1][1].result_code=6;await page.getByRole('button',{name:'채점 결과 다시 확인',exact:true}).click();await page.getByText('오답입니다',{exact:true}).waitFor();assert((await page.locator('[data-primm-judge-status]').textContent()).includes('정답 제출 미확인'));
    submissionRecords[1][1].result_code=11;await page.getByRole('button',{name:'채점 결과 다시 확인',exact:true}).click();await page.getByText('컴파일 오류가 있습니다',{exact:true}).waitFor();
    submissionRecords[1][1].result_code=4;await page.getByRole('button',{name:'채점 결과 다시 확인',exact:true}).click();await page.getByText('맞았습니다',{exact:true}).waitFor();
    await page.screenshot({path:path.join(output,'make-submission-mobile.png'),fullPage:true});
    await page.getByRole('button',{name:'학습 과정 마치기'}).click();
    await page.waitForFunction(()=>document.body.textContent.includes('학습 과정을 마쳤습니다.'));
    assert((await page.locator('[data-primm-judge-status]').textContent()).includes('정답 제출을 확인'),'actual own AC survives separate process completion');
    await page.getByRole('button',{name:'5 · 제작',exact:true}).click();await page.waitForFunction(()=>document.body.textContent.includes('완료한 단계를 읽고 있습니다'));
    assert(await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).isDisabled(),'completed-stage review cannot launch submission watch');
    attempts[2]={...emptyAttempt(),current_stage:'make',completed_stages:['predict','run','investigate','modify']};
    await page.goto('http://primm.test/student?activity=2');await page.waitForSelector('[data-primm-make-submission]');
    assert.equal(await page.getByText('맞았습니다',{exact:true}).count(),0);assert.equal(calls.filter(call=>call.endpoint==='/activities/2/submission-check').length,0);
    await page.getByRole('button',{name:'이전 내 제출 결과 사용',exact:true}).click();await page.getByText('맞았습니다',{exact:true}).waitFor();assert.equal(calls.findLast(call=>call.endpoint==='/activities/2/submission-check').body.mode,'latest');
    makeStates[2].status='unavailable';await page.reload();await page.getByText('저장된 결과가 있다면 마지막 확인 기록입니다.',{exact:false}).waitFor();assert(await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).isEnabled(),'lookup policy failures preserve a safe native submission path');
    await page.locator('[name=draft_code]').fill('print("fallback")');await page.evaluate(()=>{window.open=()=>null;});await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).click();await page.getByRole('link',{name:'채점 화면 새 탭으로 열기',exact:true}).waitFor();
    assert.match(await page.getByRole('link',{name:'채점 화면 새 탭으로 열기',exact:true}).getAttribute('href'),/^http:\/\/primm\.test\/submit\/100\?class_id=7&draft=opaque-ticket-/);
    assert.equal(await page.getByRole('link',{name:'이 탭에서 채점 화면 열기',exact:true}).getAttribute('target'),null,'blocked popups have a deliberate same-tab fallback');
    const fallbackPopup=page.waitForEvent('popup');await page.getByRole('link',{name:'채점 화면 새 탭으로 열기',exact:true}).click();const fallbackTab=await fallbackPopup;await fallbackTab.waitForURL(/\/submit\/100\?class_id=7&draft=/);
    assert.equal(await fallbackTab.locator('[name=source]').inputValue(),'print("fallback")');assert.equal(await fallbackTab.evaluate(()=>window.nativeSubmitCount),0);
    await fallbackTab.reload();assert.equal(await fallbackTab.locator('[name=source]').inputValue(),'print("fallback")','owner-bound short-lived handoffs support native refresh');await fallbackTab.close();
    await response('대체 링크 준비 뒤 응답 변경');assert.equal(await page.getByRole('link',{name:'채점 화면 새 탭으로 열기',exact:true}).count(),0,'dirty changes discard the old prepared fallback URL');
    const unchangedBaseline=makeStates[2].baseline;launchTtl=3;await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).click();await page.getByRole('link',{name:'채점 화면 새 탭으로 열기',exact:true}).waitFor();await page.waitForTimeout(3200);
    assert.equal(await page.getByRole('link',{name:'채점 화면 새 탭으로 열기',exact:true}).count(),0,'expired fallback URLs are removed without a server request');
    assert.equal(makeStates[2].baseline,unchangedBaseline,'explicit draft re-preparation keeps the active watch baseline');launchTtl=300;
    await bfcacheReturn(2);
    const crlfDraft='if True:\r\n    print("CRLF 한글 </script>")\r\n';
    makeStates[2]={status:'idle',watch:{active:false,started_at:null},submission:null,recent:null};attempts[2]={...attempts[2],draft_code:crlfDraft,submission_id:null};
    await page.reload();await page.waitForSelector('[data-primm-make-submission]');const crlfPopup=page.waitForEvent('popup');await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).click();const crlfTab=await crlfPopup;await crlfTab.waitForURL(/\/submit\/100\?class_id=7&draft=/);
    assert.equal(handoffs[new URL(crlfTab.url()).searchParams.get('draft')].source,crlfDraft,'server handoff keeps CRLF bytes before browser textarea newline normalization');
    assert.equal(await crlfTab.locator('[name=source]').inputValue(),crlfDraft.replace(/\r\n/g,'\n'));await crlfTab.close();
    makeStates[2]={status:'idle',watch:{active:false,started_at:null},submission:null,recent:null};attempts[2]={...attempts[2],draft_code:'',submission_id:null};
    await page.reload();await page.waitForSelector('[data-primm-make-submission]');const emptyPopup=page.waitForEvent('popup');await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).click();const emptyTab=await emptyPopup;
    await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('초안을 먼저'));await page.waitForTimeout(100);assert(emptyTab.isClosed(),'empty code preparation closes the blank tab');assert.equal(makeStates[2].watch.active,false);
    await page.locator('[name=draft_code]').fill('print("safe code")');unsafeLaunch=true;const unsafePopup=page.waitForEvent('popup');await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).click();const unsafeTab=await unsafePopup;
    await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('현재 수업과 문제 상태'));await page.waitForTimeout(100);assert(unsafeTab.isClosed(),'cross-origin returned handoff URLs are rejected');unsafeLaunch=false;
    makeStates[2]={status:'idle',watch:{active:false,started_at:null},submission:null,recent:null};attempts[2].submission_id=null;attempts[2].is_accepted=false;watchFailure=403;
    await page.reload();await page.waitForSelector('[data-primm-make-submission]');const failedPopupPromise=page.waitForEvent('popup');await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).click();const failedPopup=await failedPopupPromise;
    await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('준비하지 못했습니다'));await page.waitForTimeout(100);assert(failedPopup.isClosed(),'failed preparation closes the temporary blank tab');watchFailure=0;
    makeStates[2]={status:'watching',watch:{active:true,started_at:new Date(Date.now()-13*60*1000).toISOString()},submission:null,recent:null,baseline:501};
    await page.reload();await page.waitForSelector('[data-primm-make-submission]');await page.getByText('자동 확인을 멈췄습니다.',{exact:false}).waitFor();const expiredChecks=calls.filter(call=>call.endpoint==='/activities/2/submission-check').length;await page.waitForTimeout(4200);assert.equal(calls.filter(call=>call.endpoint==='/activities/2/submission-check').length,expiredChecks,'an expired watch does not poll indefinitely');
    assert(await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).isEnabled(),'an expired unlinked watch permits a deliberate new submission attempt');
    makeStates[2]={status:'watching',watch:{active:true,started_at:new Date().toISOString()},submission:null,recent:null,baseline:501};submissionRecords[2]=[copy(oldSubmission),{...copy(oldSubmission),id:503,result_code:2,processing:true,detail_url:'/submissions/503?class_id=7'}];
    await page.reload();await page.waitForSelector('[data-primm-make-submission]');await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.getByText('채점 중입니다. 결과가 나오면 확인합니다.',{exact:true}).waitFor();assert.equal(attempts[2].submission_id,503,'refresh restores a server-owned active watch');
    submissionFailure=403;await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('조회 권한이 없습니다'));const forbiddenChecks=calls.filter(call=>call.endpoint==='/activities/2/submission-check').length;await page.waitForTimeout(4200);assert.equal(calls.filter(call=>call.endpoint==='/activities/2/submission-check').length,forbiddenChecks,'permission failures stop polling');submissionFailure=0;
    disabledMake=true;await page.reload();await page.waitForSelector('[data-primm-make-submission]');assert(await page.getByRole('button',{name:'채점 화면에서 제출하기',exact:true}).isDisabled());disabledMake=false;
    await page.reload();await page.waitForSelector('[data-primm-make-submission]');attempts[2].version++;await response('POLL_CONFLICT_DRAFT');await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('내 초안을 별도로 보관'));
    assert.equal(await page.locator('[name=stage_response]').inputValue(),'POLL_CONFLICT_DRAFT','a stale poll/save version preserves the current response');await page.getByRole('button',{name:'서버 기록 다시 불러오기',exact:true}).click();await page.waitForSelector('[data-primm-conflict]');assert((await page.locator('[name=local_response_0]').inputValue()).includes('POLL_CONFLICT_DRAFT'));
    delete makeStates[2];delete submissionRecords[2];
    if(process.argv.includes('--make-only')){assert.equal(errors.length,0,errors.join('\n'));console.log('PRIMM Make Chrome fixture passed: latest saved Unicode/CRLF draft and language prefill, manual-only native submit, safe popup/blocked fallback, stale/expired re-preparation, BFCache return, baseline/fixed-ID polling, draft/cursor preservation and forbidden/conflict recovery.');return;}
    attempts[2]={...emptyAttempt(),current_stage:'modify',completed_stages:['predict','run','investigate']};
    await page.goto('http://primm.test/student?activity=2');await page.waitForSelector('[data-primm-completion]');
    await page.locator('[data-blank-id=value]').fill('70');await page.locator('[data-blank-id=__proto__]').fill('"안내"');await response('기준보다 큰 점수로 바꾸었습니다.');await page.getByRole('button',{name:'배열·빈칸 코드 복원 확인'}).click();await page.waitForSelector('[data-primm-restored-code]');
    assert((await page.locator('[data-primm-restored-code]').textContent()).includes('score = 70'));
    const specialAnswer=calls.findLast(call=>call.endpoint==='/activities/2/support/assemble').body.answers;
    assert(Object.prototype.hasOwnProperty.call(specialAnswer,'__proto__'),'valid prototype-named blank IDs must survive JSON submission');
    assert.equal(specialAnswer.__proto__,'"안내"');
    await page.getByRole('button',{name:'지금 저장',exact:true}).click();await page.waitForTimeout(200);await page.reload();await page.waitForSelector('[data-blank-id=value]');assert.equal(await page.locator('[data-blank-id=value]').inputValue(),'70');
    assert.equal(await page.locator('[data-blank-id=__proto__]').inputValue(),'"안내"','prototype-named blank draft must restore after reconnect');
    await page.screenshot({path:path.join(output,'completion-mobile.png'),fullPage:true});
    await page.goto('http://primm.test/student?activity=3');await page.waitForSelector('[data-primm-stage=predict]');assert(await page.getByRole('button',{name:'저장하고 다음 단계',exact:true}).isDisabled());assert(await page.getByText('코드를 읽을 도입 자료가 아직 준비되지 않았습니다.',{exact:false}).count());
    emptyList=true;await page.goto('http://primm.test/student');await page.getByRole('heading',{name:'참여할 PRIMM 활동이 없습니다.'}).waitFor();emptyList=false;
    await page.goto('http://primm.test/student?activity=4');await page.waitForSelector('[data-primm-stage=predict]');
    assert.equal(await page.locator('.ojprimm-stage img').count(),0,'generated prompts must be rendered as text');assert.equal(await page.evaluate(()=>window.primmInjected),undefined);
    assert.equal(await page.getByRole('button',{name:'AI 예상 출력 확인'}).count(),0,'AI output stays hidden during Predict');
    await response('변수에 저장한 2가 출력됩니다.');await next('run');
    await page.getByRole('button',{name:'AI 예상 출력 확인'}).click();assert(await page.getByText('실제 실행으로 검증되지 않았습니다.',{exact:false}).count());
    await response('직접 실행 결과와 비교했습니다.');await next('investigate');await response('대입한 값을 출력합니다.');await next('modify');
    assert.equal(await page.locator('[name=draft_code]').inputValue(),'value = 2\nprint(value)');
    await page.getByText('확인할 예제 · AI 예상 출력',{exact:true}).click();assert(await page.getByText('값을 3으로 바꾸세요.',{exact:true}).count());
    assert(await page.getByText('아래 예상 출력은 AI가 생성했으며 실제 실행으로 검증되지 않았습니다.',{exact:true}).count());
    await page.screenshot({path:path.join(output,'generated-student-mobile.png'),fullPage:true});
    verifiedLesson=true;practiceRequired=true;attempts[4]={...emptyAttempt()};
    await page.goto('http://primm.test/student?activity=4');await page.waitForSelector('[data-primm-stage=predict]');
    const exampleStatus=await page.locator('[data-primm-example-verification=verified]').textContent();
    assert(exampleStatus.includes('OJ 자동 검증 완료')&&exampleStatus.includes('2개')&&exampleStatus.includes('2026-10-24 15:00:00 UTC'));
    assert.equal(await page.getByText('DO_NOT_RENDER_PRIVATE_STDOUT',{exact:false}).count(),0,'verification metadata must not render execution details');
    assert.equal(await page.getByRole('button',{name:'OJ 검증 출력 확인'}).count(),0,'verified output also stays hidden during Predict');
    assert.equal(await page.getByText('2',{exact:true}).count(),0);
    await response('2가 출력될 것으로 예상합니다.');await next('run');
    assert.equal(await page.getByRole('button',{name:'AI 예상 출력 확인'}).count(),0);
    await page.getByRole('button',{name:'OJ 검증 출력 확인'}).click();assert(await page.getByText('2',{exact:true}).isVisible());
    await response('OJ에서 확인한 출력과 예측이 같습니다.');await next('investigate');await response('저장한 값이 출력됩니다.');await next('modify');
    assert.equal(await page.evaluate(()=>document.activeElement?.tagName),'H3','stage advance focuses its heading');
    await page.getByText('확인할 예제 · OJ 검증 완료',{exact:true}).click();
    assert(await page.getByText('수정 활동의 기준 코드를 기존 OJ에서 실행하여 아래 예상 출력과 일치하는지 확인했습니다. 내 수정 코드의 정답 판정은 별도로 확인해야 합니다.',{exact:true}).count());
    assert((await page.locator('[data-primm-judge-status]').textContent()).includes('정답 제출 미확인'),'validated reference code never implies learner AC');
    assert(await page.getByRole('button',{name:'저장하고 다음 단계',exact:true}).isDisabled(),'new lesson Modify waits for a passing execution of the current code');
    await page.getByRole('button',{name:'수정 코드 실행·확인',exact:true}).click();await page.getByText('수정 사례와 다른 결과가 있습니다. 오류와 출력 결과를 살펴보세요.',{exact:true}).waitFor();
    await page.locator('[name=draft_code]').fill('value = 3\nprint(value)');await page.getByRole('button',{name:'수정 코드 실행·확인',exact:true}).click();await page.getByText('모든 수정 사례를 통과했습니다. 제작 문제의 공식 정답 판정과 별개입니다.',{exact:true}).waitFor();
    assert(await page.getByRole('button',{name:'저장하고 다음 단계',exact:true}).isEnabled());await page.locator('[name=draft_code]').fill('value = 4\nprint(value)');assert(await page.getByRole('button',{name:'저장하고 다음 단계',exact:true}).isDisabled(),'editing invalidates the current-code gate');await page.locator('[name=draft_code]').fill('value = 3\nprint(value)');
    assert((await page.locator('[data-primm-judge-status]').textContent()).includes('정답 제출 미확인'),'practice pass never claims Make AC');
    await page.getByRole('button',{name:'지금 저장',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('저장되었습니다'));
    const reviewSaves=calls.filter(call=>call.endpoint==='/activities/4/progress').length;
    await page.getByRole('button',{name:'1 · 예측',exact:true}).click();await page.waitForSelector('[data-primm-stage=predict]');
    assert(await page.locator('[name=stage_response]').evaluate(input=>input.readOnly));assert.equal(await page.getByRole('button',{name:'OJ 검증 출력 확인'}).count(),0,'read-only Predict preserves hidden expected output');
    await page.getByRole('button',{name:'현재 학습 단계로 돌아가기',exact:true}).click();await page.waitForSelector('[data-primm-stage=modify]');assert.equal(calls.filter(call=>call.endpoint==='/activities/4/progress').length,reviewSaves,'completed-stage review never writes progress');
    await page.screenshot({path:path.join(output,'verified-student-mobile.png'),fullPage:true});
    await page.goto('http://primm.test/student?activity=3');await page.waitForSelector('[data-primm-stage=predict]');
    await response('LOCAL_CONFLICT_RESPONSE');attempts[3]={...emptyAttempt(),version:1,responses:{predict:'SERVER_NEW_RESPONSE'}};
    await page.getByRole('button',{name:'지금 저장',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('내 초안을 별도로 보관'));
    await page.reload();await page.waitForSelector('[data-primm-conflict]');assert.equal(await page.locator('[name=server_response]').inputValue(),'SERVER_NEW_RESPONSE');assert.equal(await page.locator('[name=local_response_0]').inputValue(),'LOCAL_CONFLICT_RESPONSE');
    await page.getByRole('button',{name:'이 초안으로 이어쓰기',exact:true}).click();assert.equal(await page.locator('[name=stage_response]').inputValue(),'LOCAL_CONFLICT_RESPONSE');
    assert.equal(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('oj_primm_19_3_conflicts')).length),1,'explicit restore keeps protected copy until explicit cleanup');
    await page.screenshot({path:path.join(output,'conflict-recovery-mobile.png'),fullPage:true});
    const previewProgressBefore=calls.filter(call=>call.endpoint==='/activities/4/progress').length;
    const previewSupportBefore=calls.filter(call=>call.endpoint==='/activities/4/support').length;
    readOnlyPreview=true;await page.goto('http://primm.test/student?activity=4');await page.waitForSelector('[data-primm-stage=predict]');
    assert.equal(await page.locator('.ojprimm-step-preview').count(),5,'teacher preview offers all five stages');
    await page.getByRole('button',{name:'4 · 수정',exact:true}).click();await page.waitForSelector('[data-primm-stage=modify]');
    assert(await page.getByText('값을 3으로 바꾸세요.',{exact:true}).count());assert(await page.getByText('교사용 수정 활동 전체 코드',{exact:true}).count());
    assert((await page.locator('.ojprimm-stage pre').first().textContent()).includes('value = 2'));
    await page.getByRole('button',{name:'5 · 제작',exact:true}).click();await page.waitForSelector('[data-primm-stage=make]');
    assert(await page.getByText('제작한 프로그램을 어떤 입력으로 확인하나요?',{exact:true}).count());
    await page.getByRole('button',{name:'2 · 실행 비교',exact:true}).click();await page.waitForSelector('[data-primm-stage=run]');
    assert(await page.locator('.ojprimm-stage pre').last().isVisible(),'teacher can inspect expected output without a disabled reveal button');
    assert.equal(calls.filter(call=>call.endpoint==='/activities/4/progress').length,previewProgressBefore,'teacher stage preview never saves learner progress');
    assert.equal(calls.filter(call=>call.endpoint==='/activities/4/support').length,previewSupportBefore,'teacher preview shows server-projected source without student support API calls');
    activities[3].generation_kind='template';
    await page.reload();await page.waitForSelector('[data-primm-stage=predict]');
    assert.equal(await page.getByText('기본 예제를 바탕으로 준비한 도입 자료입니다.',{exact:true}).count(),1,'OJ-verified templates retain their basic-example origin label');
    assert.equal(await page.getByText('목표 문제의 개념을 연습하는 AI 생성 도입 예제입니다.',{exact:true}).count(),0,'OJ verification never turns a template into an AI-generated example');
    assert.equal(await page.locator('[data-primm-example-verification=verified]').count(),1,'template origin does not hide independent OJ execution proof');
    await page.getByRole('button',{name:'2 · 실행 비교',exact:true}).click();await page.waitForSelector('[data-primm-stage=run]');
    assert.equal(await page.getByText('기존 OJ가 예제 코드를 실행하여 예상 출력과 일치하는지 확인했습니다. 저장한 예측과 비교하세요.',{exact:true}).count(),1);
    verifiedLesson=false;await page.reload();await page.waitForSelector('[data-primm-stage=predict]');
    assert.equal(await page.getByText('OJ 자동 실행 검증 전인 기본 예제 기반 수업 자료입니다.',{exact:true}).count(),1,'legacy AI-worded verification notes must not relabel an unverified template');
    assert.equal(await page.getByText('AI 예상 출력은 실제 실행으로 검증되지 않았습니다.',{exact:true}).count(),0);
    await page.getByRole('button',{name:'2 · 실행 비교',exact:true}).click();await page.waitForSelector('[data-primm-stage=run]');
    assert.equal(await page.getByText('기본 예제의 예상 출력입니다. 실제 실행으로 검증되지 않았습니다. 실행 환경에서 직접 확인하고 예측과 비교하세요.',{exact:true}).count(),1);
    await page.getByRole('button',{name:'4 · 수정',exact:true}).click();await page.waitForSelector('[data-primm-stage=modify]');
    await page.getByText('확인할 예제 · 예상 출력',{exact:true}).click();
    assert.equal(await page.getByText('아래 예상 출력은 기본 예제의 예상 결과이며 실제 실행으로 검증되지 않았습니다.',{exact:true}).count(),1);
    assert.equal(await page.getByText('아래 예상 출력은 AI가 생성했으며 실제 실행으로 검증되지 않았습니다.',{exact:true}).count(),0);
    activities[3].generation_kind='ai';verifiedLesson=true;await page.reload();await page.waitForSelector('[data-primm-stage=predict]');
    assert.equal(await page.getByText('목표 문제의 개념을 연습하는 AI 생성 도입 예제입니다.',{exact:true}).count(),1,'an explicit complete-AI boundary retains the AI origin label');
    readOnlyPreview=false;
    await page.setViewportSize({width:1280,height:920});await page.goto('http://primm.test/teacher');await page.waitForSelector('[data-primm-create]');
    assert.equal(await page.locator('[name=context] option').count(),1,'teacher sees only authorized contexts');
    assert.deepEqual(await page.locator('[name=language] option').evaluateAll(options=>options.map(option=>option.value)),['python','c','cpp','java']);
    assert.equal(await page.locator('[name=language]').inputValue(),'python');
    assert.equal(await page.locator('.ojprimm-manual').getAttribute('open'),null,'manual example selection is optional and collapsed');
    await page.getByRole('button',{name:'활동 더 보기',exact:true}).click();await page.getByRole('heading',{name:'이전 학기 활동',exact:true}).waitFor();assert.equal(calls.findLast(call=>call.endpoint==='/teacher/activities').query.cursor,'activity-page-2');
    await page.locator('.ojprimm-class-settings summary').click();assert(await page.locator('[name=setting_auto_publish]').isChecked(),'default keeps problem-only automatic publication');
    await page.locator('[name=setting_level]').selectOption('intermediate');await page.locator('[name=setting_minutes]').fill('60');await page.locator('[name=setting_prerequisites]').fill('변수\n조건문');await page.getByRole('button',{name:'학급 기본 설정 저장',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('학급 기본 설정을 저장'));assert.deepEqual(settings.prerequisites,['변수','조건문']);assert.equal(settings.minutes,60);await page.locator('.ojprimm-class-settings summary').click();
    await page.getByText('학생 하나',{exact:true}).waitFor();assert(await page.getByText('미시작',{exact:true}).count());await page.getByRole('button',{name:'다음 학생 더 보기',exact:true}).click();await page.getByText('학생 둘',{exact:true}).waitFor();
    await page.locator('[name=report_search]').fill('학생');await page.getByRole('button',{name:'진행 조회',exact:true}).click();await page.waitForTimeout(100);assert.equal(calls.findLast(call=>call.endpoint==='/teacher/report').query.search,'학생');
    const downloadPromise=page.waitForEvent('download');await page.getByRole('button',{name:'전체 보고서 CSV 내려받기',exact:true}).click();assert.equal((await downloadPromise).suggestedFilename(),'primm-progress.csv');
    await page.getByRole('article').filter({has:page.getByRole('heading',{name:'AI가 만든 합계 수업',exact:true})}).getByRole('button',{name:'검토·수정·배포 관리',exact:true}).click();await page.waitForSelector('[data-primm-editor]');
    await page.locator('[name=edit_title]').fill('교사가 검토한 새 버전');await page.locator('[name=edit_demo_explanation]').fill('새 버전에서 다듬은 설명입니다.');await page.getByRole('button',{name:'새 버전 초안으로 저장',exact:true}).click();await page.getByRole('heading',{name:'수업 자료 관리 · 교사가 검토한 새 버전',exact:true}).waitFor();
    assert.equal(managed[5].state,'draft');assert.equal(managed[4].lesson.demo.explanation,'변수를 출력합니다.','existing lesson remains immutable');assert(await page.getByText('새 버전 변경 내용 확인',{exact:true}).count());
    assert(await page.getByRole('button',{name:'수업에 공개',exact:true}).isDisabled(),'edited code remains unpublished before fresh execution');await page.getByRole('button',{name:'검증 후 배포 준비',exact:true}).click();await page.waitForSelector('[data-generation-status=ready]');await page.getByRole('button',{name:'활동 검토·배포 관리',exact:true}).click();await page.waitForSelector('[data-primm-editor]');
    await page.getByRole('button',{name:'수업에 공개',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-primm-management]').textContent.includes('공개 중'));assert.equal(managed[5].state,'active');
    await page.getByRole('button',{name:'배포 회수',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-primm-management]').textContent.includes('배포 회수'));assert.equal(managed[5].state,'withdrawn');
    await page.locator('[data-primm-management] > details').filter({has:page.getByText('다른 수업에 재사용 · 부분 재생성',{exact:true})}).locator('summary').click();await page.getByRole('button',{name:'이 자료를 선택한 수업에 복제',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-primm-management]').textContent.includes('버전 2'));assert(activities.length>=6);await page.locator('[data-primm-management]').screenshot({path:path.join(output,'management-desktop.png')});
    await page.getByRole('button',{name:'관리 화면 닫기',exact:true}).click();
    await page.locator('[name=target_problem_id]').fill('100');await page.locator('[name=support_kind]').selectOption('code_completion');
    const initialGeneratedJob=nextJob;generationShouldFail=true;await page.getByRole('button',{name:'AI로 PRIMM 자동 생성',exact:true}).click();
    await page.waitForSelector('[data-generation-status=queued]');assert(await page.getByRole('button',{name:'AI로 PRIMM 자동 생성',exact:true}).isDisabled(),'pending generation prevents duplicate starts');
    await page.waitForTimeout(100);await page.reload();await page.waitForSelector('[data-generation-status=failed]');
    assert.equal(calls.filter(call=>call.endpoint==='/teacher/generations'&&call.body).length,1,'reload resumes the existing job without creating a new one');
    const generationCall=calls.find(call=>call.endpoint==='/teacher/generations'&&call.body);
    assert.equal(generationCall.body.context_id,'class:7');assert.equal(generationCall.body.support_kind,'code_completion');assert.equal(generationCall.body.language,'python');
    assert.match(generationCall.body.request_key,/^[a-f0-9-]{36}$/);assert(!('support_problem_id'in generationCall.body));assert(!('actor_user_id'in generationCall.body));
    assert.equal(await page.getByRole('link',{name:'생성된 활동 확인'}).count(),0,'failed lesson must not expose an activity link');
    assert(await page.getByText('이 작업의 자료는 학생에게 배포되지 않았습니다. 원인을 확인한 뒤 다시 시도하세요.',{exact:true}).count());
    generationShouldFail=false;validatePipeline=true;await page.getByRole('button',{name:'생성 다시 시도',exact:true}).click();
    await page.waitForSelector('[data-generation-phase=verifying][data-generation-status=queued]');assert(await page.getByText('기존 OJ로 코드와 예상 출력을 검증하고 있습니다',{exact:true}).count());
    assert.equal(await page.getByRole('link',{name:'생성된 활동 확인'}).count(),0,'unverified checkpoint must not expose an activity link');
    await page.screenshot({path:path.join(output,'generation-verifying-desktop.png'),fullPage:true});
    await page.waitForSelector('[data-generation-phase=repairing]');assert(await page.getByText('검증 오류를 AI가 수정하고 있습니다',{exact:true}).count());
    assert(await page.getByText('자동 수정 1 / 2회 · 수정 뒤 전체 예제를 다시 검증합니다.',{exact:true}).count());
    await page.screenshot({path:path.join(output,'generation-repairing-desktop.png'),fullPage:true});
    await page.waitForSelector('[data-generation-status=ready]');validatePipeline=false;
    assert.equal(calls.filter(call=>call.endpoint==='/teacher/generations/'+initialGeneratedJob+'/process').length,5,'checkpoint processing continues through generation, validation, repair and revalidation');
    assert(await page.getByText('OJ 자동 검증을 통과한 자료가 수업에 배포되었습니다. 생성된 자료를 학생 화면에서 확인하세요.',{exact:true}).count());
    assert.equal(await page.getByRole('link',{name:'생성된 활동 확인'}).getAttribute('href'),'http://primm.test/oj-addons/oj-primm/learn?activity=4');
    const receipt=jobs[initialGeneratedJob].verification;delete jobs[initialGeneratedJob].verification;await page.reload();await page.waitForSelector('[data-generation-status=ready]');
    assert(await page.getByText('이전 버전에서 생성한 활동입니다. 코드와 예상 출력은 실제 실행으로 검증되지 않았습니다.',{exact:true}).count());
    assert.equal(await page.getByText('OJ 자동 검증을 통과한 자료가 수업에 배포되었습니다.',{exact:false}).count(),0,'legacy ready job must not claim OJ validation');jobs[initialGeneratedJob].verification=receipt;
    const recoveredJobId=nextJob;recoverLease=true;await page.locator('[name=target_problem_id]').fill('100');await page.getByRole('button',{name:'AI로 PRIMM 자동 생성',exact:true}).click();
    await page.waitForSelector('[data-generation-status=running]');await page.waitForSelector('[data-generation-status=ready]');recoverLease=false;
    assert.equal(calls.filter(call=>call.endpoint==='/teacher/generations/'+recoveredJobId+'/process').length,2,'a running lease returning to queued triggers one recovery process');
    await page.locator('[name=target_problem_id]').fill('100');await page.locator('.ojprimm-manual summary').click();
    assert.deepEqual(await page.locator('[name=demo_code] option').evaluateAll(options=>options.map(option=>option.value)),['sum','condition']);
    await page.locator('[name=manual_support_kind]').selectOption('code_completion');assert(await page.locator('[name=support_problem_id]').isEnabled());await page.locator('[name=support_problem_id]').fill('102');
    await page.getByRole('button',{name:'기초 예제로 활동 만들기',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('PRIMM 활동을 만들었습니다'));
    const createCall=calls.findLast(c=>c.endpoint==='/teacher/activities'&&c.body);assert.equal(createCall.body.context_id,'class:7');assert.equal(createCall.body.support_kind,'code_completion');assert.equal(createCall.body.support_problem_id,102);assert(!('actor_user_id'in createCall.body));
    await page.screenshot({path:path.join(output,'teacher-desktop.png'),fullPage:true});
    await page.getByRole('article').filter({has:page.getByRole('heading',{name:'AI가 만든 합계 수업',exact:true})}).getByRole('button',{name:'검토·수정·배포 관리',exact:true}).click();await page.waitForSelector('[data-primm-editor]');
    await page.locator('[data-primm-management] > details').filter({has:page.getByText('다른 수업에 재사용 · 부분 재생성',{exact:true})}).locator('summary').click();await page.locator('[name=regenerate_scope]').selectOption('modify');await page.getByRole('button',{name:'선택한 부분 재생성',exact:true}).click();await page.waitForSelector('[data-generation-status=ready]');assert.equal(calls.findLast(call=>call.endpoint==='/teacher/activities/4/regenerate').body.scope,'modify');
    const stalledId=nextJob++;jobs[stalledId]={id:stalledId,status:'running',phase:'verifying',can_cancel:true,can_resume:false,is_stalled:true,target_problem_id:100,language:'python',usage:{ai_calls:1,execution_count:2}};
    const history=page.locator('details').filter({has:page.locator('summary').getByText('생성 작업과 사용량',{exact:true})}).first();await history.locator('summary').first().click();await history.getByText('작업 #'+stalledId+' · running · 장기 대기',{exact:true}).waitFor();await history.locator('.ojprimm-card').filter({hasText:'작업 #'+stalledId+' · running'}).getByRole('button',{name:'작업 확인·재개',exact:true}).click();await page.waitForSelector('[data-generation-status=running]');
    assert(await history.getByText('2026-10-25 UTC 일일 AI 호출 9회',{exact:true}).count());assert(await history.getByText('최근 최대 100건 합계 · AI 호출 3회 · 코드 실행 6회',{exact:true}).count());assert(await history.getByText('자동 준비 최근 진단 · auto_prepare_context_unavailable · 2026-10-25 10:00:00 UTC',{exact:true}).count());assert(await history.getByText('자동 준비 누적 건수 · auto_prepare_enqueued 3건 · auto_prepare_context_unavailable 2건',{exact:true}).count());assert(await history.getByText('백그라운드 최근 진단 · background_storage_or_runtime · 2026-10-25 10:01:00 UTC',{exact:true}).count());assert.equal(await page.getByText('PRIVATE_CLASS_NAME',{exact:false}).count(),0,'administrator diagnostics render only safe code, date and counts');
    await page.evaluate(()=>{window.primmStableGenerationHeading=document.querySelector('[data-primm-generation] h3');});await page.waitForTimeout(2000);assert(await page.evaluate(()=>window.primmStableGenerationHeading===document.querySelector('[data-primm-generation] h3')),'unchanged status polling preserves DOM and avoids repeated live announcements');
    await page.getByRole('button',{name:'생성 취소',exact:true}).click();await page.waitForSelector('[data-generation-status=canceled]');await page.getByRole('button',{name:'작업 재개',exact:true}).click();await page.waitForSelector('[data-generation-status=ready]');
    const stopId=nextJob++;jobs[stopId]={id:stopId,status:'running',phase:'verifying',can_cancel:true,target_problem_id:100,language:'python'};await history.locator('summary').first().click();await history.locator('summary').first().click();await history.getByText('작업 #'+stopId+' · running',{exact:true}).waitFor();await history.locator('.ojprimm-card').filter({hasText:'작업 #'+stopId+' · running'}).getByRole('button',{name:'작업 확인·재개',exact:true}).click();await page.waitForSelector('[data-generation-status=running]');await page.getByRole('button',{name:'관리자 중단',exact:true}).click();await page.waitForSelector('[data-generation-status=stopped]');
    const foreignId=nextJob++;jobs[foreignId]={id:foreignId,status:'queued',phase:'verifying',can_process:false,can_cancel:false,can_resume:false,target_problem_id:100,language:'python'};await history.locator('summary').first().click();await history.locator('summary').first().click();await history.getByText('작업 #'+foreignId+' · queued',{exact:true}).waitFor();await history.locator('.ojprimm-card').filter({hasText:'작업 #'+foreignId+' · queued'}).getByRole('button',{name:'작업 확인·재개',exact:true}).click();await page.waitForSelector('[data-generation-status=queued]');await page.waitForTimeout(1900);assert.equal(calls.filter(call=>call.endpoint==='/teacher/generations/'+foreignId+'/process').length,0,'administrator overview never processes another creator request');assert.equal(await page.getByRole('button',{name:'생성 취소',exact:true}).count(),0);assert(await page.getByRole('button',{name:'관리자 현황 새로고침',exact:true}).count());
    jobs[foreignId]={...jobs[foreignId],status:'ready',activity_id:4,activity_state:'review',verification:{status:'verified',engine:'oj'},quality_summary:{status:'needs_review',reasons:['수정 과제 의미를 확인해 주세요.']}};managed[4].state='review';managed[4].activity.quality={status:'needs_review',reasons:['수정 과제 의미를 확인해 주세요.']};await page.getByRole('button',{name:'관리자 현황 새로고침',exact:true}).click();await page.waitForSelector('[data-generation-status=ready]');assert(await page.getByText('OJ 자동 검증을 통과한 자료를 보관했습니다. 교사 검토·배포 관리에서 공개 여부를 확인하세요.',{exact:true}).count());
    await page.getByRole('button',{name:'활동 검토·배포 관리',exact:true}).click();await page.waitForSelector('[name=acknowledge_review]');assert(await page.getByRole('button',{name:'수업에 공개',exact:true}).isDisabled());await page.locator('[name=acknowledge_review]').check();await page.getByRole('button',{name:'수업에 공개',exact:true}).click();await page.waitForTimeout(100);assert.equal(calls.findLast(call=>call.endpoint==='/teacher/activities/4/publish').body.acknowledge_review,true);await page.locator('[data-primm-management]').screenshot({path:path.join(output,'quality-review-desktop.png')});
    for(const kind of ['create','validate','regenerate']){
      const expiredId=nextJob++;jobs[expiredId]={id:expiredId,status:'failed',phase:'verifying',can_process:true,can_retry:false,is_expired:true,can_resume:false,target_problem_id:100,language:'python',error:'24시간 작업 유효 기간이 지났습니다.',new_request:{kind,context_type:'class',context_id:'class:7',target_problem_id:100,language:'python',support_kind:'none',title:'만료 후 새 요청',activity_id:5,scope:'modify'}};
      await history.locator('summary').first().click();await history.locator('summary').first().click();await history.getByText('작업 #'+expiredId+' · failed',{exact:true}).waitFor();await history.locator('.ojprimm-card').filter({hasText:'작업 #'+expiredId+' · failed'}).getByRole('button',{name:'작업 확인·재개',exact:true}).click();await page.waitForSelector('[data-generation-status=failed]');assert.equal(await page.getByRole('button',{name:'생성 다시 시도',exact:true}).count(),0);assert.equal(calls.filter(call=>call.endpoint==='/teacher/generations/'+expiredId+'/process').length,0,'an expired job never automatically redispatches');
      const requestsBefore=calls.filter(call=>call.endpoint==='/teacher/generations'&&call.body).length;await page.getByRole('button',{name:'새 생성 시작',exact:true}).click();await page.waitForSelector('[data-generation-status=ready]');const expected=kind==='create'?'/teacher/generations':'/teacher/activities/5/'+kind,replacement=calls.findLast(call=>call.endpoint===expected&&call.body);assert.equal(replacement.body.force,true);assert(replacement.body.request_key);assert.equal(calls.filter(call=>call.endpoint==='/teacher/generations/'+expiredId+'/retry').length,0);if(kind!=='create'){assert.equal(calls.filter(call=>call.endpoint==='/teacher/generations'&&call.body).length,requestsBefore,'expired validation or regeneration preserves its operation');}if(kind==='regenerate'){assert.equal(replacement.body.scope,'modify');}
    }
    reportAtLimit=true;await page.reload();await page.waitForSelector('[data-primm-report-limit]');
    const limitText=await page.locator('[data-primm-report-limit]').textContent();
    assert(limitText.includes('활동 최근 100개 표시')&&limitText.includes('학생 진행 최근 200개 표시')&&limitText.includes('전체 기록이 아닙니다.'));
    reportAtLimit=false;
    executionLanguages=['python'];settings.language='java';settings.default_language='java';await page.reload();await page.waitForSelector('[data-primm-create]');await page.waitForFunction(()=>document.querySelector('[name=language]').value==='java');assert(await page.locator('[name=language] option[value=java]').evaluate(option=>option.disabled));assert(await page.getByRole('button',{name:'AI로 PRIMM 자동 생성',exact:true}).isDisabled());assert((await page.locator('[data-primm-language-availability]').textContent()).includes('학급 기본 설정은 그대로 보존'));
    await page.locator('.ojprimm-class-settings summary').click();assert.equal(await page.locator('[name=setting_language]').inputValue(),'java');assert(await page.locator('[name=setting_language] option[value=java]').evaluate(option=>!option.disabled),'an unsupported class default remains editable and preserved');await page.getByRole('button',{name:'학급 기본 설정 저장',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('학급 기본 설정을 저장했습니다'));assert.equal(calls.findLast(call=>call.endpoint==='/teacher/settings'&&call.body).body.language,'java');await page.locator('.ojprimm-class-settings summary').click();await page.locator('[name=language]').selectOption('python');assert(await page.getByRole('button',{name:'AI로 PRIMM 자동 생성',exact:true}).isEnabled());await page.screenshot({path:path.join(output,'execution-language-availability.png'),fullPage:true});settings.language='python';settings.default_language='python';
    aiAvailable=false;await page.reload();await page.waitForSelector('[data-primm-create]');assert(await page.getByRole('button',{name:'AI로 PRIMM 자동 생성',exact:true}).isDisabled());assert(await page.getByText('플랫폼 AI 작업 설정이 필요합니다.',{exact:true}).count());
    await page.locator('[name=use_template]').check();assert(await page.getByRole('button',{name:'기본 예제로 PRIMM 준비',exact:true}).isEnabled());await page.locator('[name=target_problem_id]').fill('100');const templateJobId=nextJob;await page.getByRole('button',{name:'기본 예제로 PRIMM 준비',exact:true}).click();await page.waitForFunction(id=>document.querySelector('[data-primm-generation]').textContent.includes('작업 #'+id+' ·'),templateJobId);await page.waitForSelector('[data-generation-status=ready]');assert.equal(calls.findLast(call=>call.endpoint==='/teacher/generations'&&call.body).body.use_template,true);assert(await page.getByText('기본 예제와 목표 문제의 연관성을 확인하세요.',{exact:true}).count());
    executionAvailable=false;executionLanguages=[];await page.reload();await page.waitForSelector('[data-primm-create]');await page.locator('[name=use_template]').check();assert(await page.getByRole('button',{name:'기본 예제로 PRIMM 준비',exact:true}).isDisabled(),'template fallback also requires actual OJ execution support');assert(await page.locator('[name=language] option[value=python]').evaluate(option=>option.disabled));
    denyTeacher=true;await page.reload();await page.waitForFunction(()=>document.querySelector('[data-primm-status]').textContent.includes('권한이 없습니다'));assert.equal(await page.locator('[data-primm-create]').count(),0);
    assert.equal(errors.length,0,errors.join('\n'));
    console.log('PRIMM Chrome UI: saved Make draft/language handoff, manual-only native submit, safe popup/fallback/expiry/BFCache return/watch/fixed-ID polling and old-result consent; conflict recovery, immutable versions/edit/validate/publish/withdraw/reuse, paginated roster/CSV/settings, practice gating, completed-stage review/focus, job controls/polling/quality/template fallback and compact touch ordering passed.');
    console.log('Screenshots: '+output);
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
