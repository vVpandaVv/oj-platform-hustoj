(() => {
  'use strict';
  const root = document.querySelector('#oj-ai-projects');
  if (!root) return;
  const content = root.querySelector('[data-project-content]');
  const notice = root.querySelector('[data-project-notice]');
  const dialog = root.querySelector('[data-project-dialog]');
  const steps = [['criteria','평가 기준'],['author','활동지 작성'],['review','활동지 검토'],['assignment','배포'],['assessment','AI 평가'],['report','보고서 작성'],['release','학생 공개']];
  const query = new URLSearchParams(location.search);
  const validId = value => /^[0-9A-HJKMNP-TV-Z]{26}$/.test(String(value || ''));
  const state = { project:null, draft:null, items:[], dirty:false, busy:false, editVersion:0, stage:steps.some(([key])=>key===query.get('stage')) ? query.get('stage') : 'criteria', worksheetId:root.dataset.projectWorksheet || query.get('worksheet_id') || '', search:'', listView:query.get('list')==='archived'?'archived':'active' };
  let listRequest = 0;
  const pendingCommands = new Map();
  const copy = value => JSON.parse(JSON.stringify(value));
  const escape = value => String(value ?? '').replace(/[&<>"']/g, x=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[x]));
  const icon = name => `<svg aria-hidden="true"><use href="${escape(root.dataset.iconUrl)}#${name}"></use></svg>`;
  const button = (action,label,options='') => action==='template'&&root.dataset.canTemplate==='0' ? '' : `<button type="button" data-action="${action}" ${options}>${label}</button>`;
  const remove = (action,label,attrs='') => button(action,icon('trash'),`class="icon" title="${label}" aria-label="${label}" ${attrs}`);
  const id = () => [...crypto.getRandomValues(new Uint8Array(26))].map(n=>'0123456789ABCDEFGHJKMNPQRSTVWXYZ'[n%32]).join('');
  const message = (text='',error=false) => { notice.textContent=text; notice.classList.toggle('error',error); };
  const path = (route,params={}) => { const url=new URL(route,new URL(root.dataset.consoleBase,location.href)); for(const [k,v] of Object.entries(params))if(v)url.searchParams.set(k,String(v));return url.href; };
  const projectUrl = (projectId,stage='criteria',worksheetId='') => {
    const params={project_id:projectId,stage,worksheet_id:worksheetId};
    const selection=window.OJAIWorkflow?.read();
    if(selection?.projectId===projectId){
      if(selection.context)for(const key of ['type','id','revision'])params['context_'+key]=selection.context[key];
      if(worksheetId && worksheetId===selection.worksheetId){
        params.assignment_id=selection.assignmentId;
        params.publication_id=selection.publicationId;
        params.publication_hash=selection.publicationHash;
      }
    }
    return path('projects',params);
  };
  const navigate = href => { if (document.dispatchEvent(new CustomEvent('ojai:before-navigation',{cancelable:true,detail:{href}}))) location.assign(href); };
  async function api(route,body) {
    const intent=body?JSON.stringify([route,body.expected,body.payload]):null;
    if(intent){if(!pendingCommands.has(intent))pendingCommands.set(intent,copy(body));body=pendingCommands.get(intent);}
    const controller = new AbortController(); const timeout=setTimeout(()=>controller.abort(),30000);
    try {
      const response=await fetch(root.dataset.apiBase.replace(/\/$/,'')+route,{method:body?'POST':'GET',credentials:'same-origin',signal:controller.signal,headers:{'X-WP-Nonce':root.dataset.nonce,...(body?{'Content-Type':'application/json','Idempotency-Key':body.command_id}:{})},body:body?JSON.stringify(body):undefined});
      let value;try{value=await response.json();}catch{throw Error('서버 응답을 읽지 못했습니다. 시스템 상태를 확인해 주세요.');}
      if(!response.ok||value.ok===false)throw Error(value.message||value.error?.message||'평가를 처리하지 못했습니다.');
      if(intent)pendingCommands.delete(intent);
      return value;
    } catch(error){if(error.name==='AbortError')throw Error('응답 시간이 초과되었습니다. 입력 내용은 유지됩니다. 같은 작업을 다시 시도해 주세요.');throw error;}
    finally{clearTimeout(timeout);}
  }
  const command = (expected,payload) => ({command_id:crypto.randomUUID(),expected,payload});
  const badge = (status,number) => `<span class="badge ${status==='confirmed'?'confirmed':''}">${status==='confirmed'?'기준 확정':'작성 중'}${number?' · '+escape(number)+'차':''}</span>`;
  const ask = (options,fallback) => window.OJP && typeof window.OJP.confirm === 'function' ? window.OJP.confirm(options) : Promise.resolve(window.confirm(fallback));
  const canLeave = () => !state.dirty || confirm('저장하지 않은 평가 기준이 있습니다. 변경 내용을 버리고 이동할까요?');
  function changed(){state.dirty=true;state.editVersion++;const label=root.querySelector('[data-save-state]');if(label)label.textContent='저장하지 않은 변경';}
  async function loadList() {
    const request=++listRequest;const view=state.listView;
    const items=[];const seen=new Set();let cursor='';
    do{const response=await api('/assessment-projects?archived='+(view==='archived'?'1':'0')+(cursor?'&cursor='+encodeURIComponent(cursor):''));if(request!==listRequest)return null;if(!Array.isArray(response.data?.projects))throw Error('평가 목록 형식을 확인할 수 없습니다.');items.push(...response.data.projects);cursor=response.meta?.next_cursor;if(cursor&&seen.has(String(cursor)))throw Error('평가 목록을 다시 불러와 주세요.');seen.add(String(cursor));}while(cursor);
    state.items=items;return items;
  }
  async function load(projectId) {
    message('평가를 불러오는 중입니다.');
    if(projectId){const response=await api('/assessment-projects/'+projectId);state.project=response.data.project;state.draft=copy(state.project.configuration);state.titleDraft=state.project.title;state.dirty=false;}
    else{state.project=null;await loadList();}
    render();message();
  }
  function renderList() {
    const items=state.items.filter(item=>item.title.toLocaleLowerCase().includes(state.search.toLocaleLowerCase()));
    content.innerHTML=`<header class="ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>평가 프로젝트</h1><p class="meta">${state.listView==='archived'?'보관된 평가':'활성 평가'} ${state.items.length}개</p></div><div class="actions ojp-heading-actions">${button('new',icon('plus')+' 새 평가','class="primary ojp-button ojp-button--primary"')}${button('reload',icon('refresh-cw'),'class="icon" title="새로고침" aria-label="새로고침"')}</div></header><div class="list-toolbar"><div class="list-switch" role="group" aria-label="평가 목록 구분">${[['active','활성 평가'],['archived','보관함']].map(([value,label])=>`<label><input type="radio" name="project-list-view" value="${value}" ${state.listView===value?'checked':''}><span>${label}</span></label>`).join('')}</div><label class="search">평가 찾기<input type="search" data-field="search" value="${escape(state.search)}" placeholder="평가 이름"></label></div><div data-list-table>${listTable(items)}</div>`;
  }
  const archiveButton = item => button(item.archived?'restore':'archive',icon(item.archived?'archive-restore':'archive')+(item.archived?' 복원':' 보관'),`data-id="${escape(item.project_id)}" title="${item.archived?'활성 목록으로 복원':'보관함으로 이동'}"`);
  function listTable(items) {
    return items.length?`<table class="table"><thead><tr><th>평가</th><th>기준</th><th>활동지</th><th>관리</th></tr></thead><tbody>${items.map(item=>`<tr><td><a href="${projectUrl(item.project_id)}">${escape(item.title)}</a></td><td>${badge(item.state,item.revision_no)}</td><td>${item.worksheet_count}개</td><td><div class="actions">${button('copy','기준 복사',`data-id="${escape(item.project_id)}"`)}${archiveButton(item)}</div></td></tr>`).join('')}</tbody></table>`:`<div class="empty">${state.search?'검색 결과가 없습니다.':state.listView==='archived'?'보관된 평가가 없습니다.':'활성 평가가 없습니다.'}</div>`;
  }
  function render() {
    if(!state.project){renderList();return;}
    const p=state.project;
    root.querySelector('[data-project-header]').innerHTML=`<a data-return href="${path('projects',p.archived?{list:'archived'}:{})}">${p.archived?'보관함':'평가 목록'}</a><header><div><h1>${escape(p.title)}</h1><p>${p.archived?'<span class="badge archived">보관됨</span> ':''}${badge(p.state,p.revision_no)} <span class="meta">활동지 ${p.worksheets.length}개</span></p></div><div class="actions">${button('copy','이 평가 기준 복사',`data-id="${escape(p.project_id)}"`)}${archiveButton(p)}${button('reload',icon('refresh-cw'),'class="icon" title="새로고침" aria-label="새로고침"')}</div></header>`;
    if (!state.worksheetId && p.worksheets.length) state.worksheetId=p.worksheets[0].worksheet_id;
    const worksheetActions=state.stage==='author'?`<div class="actions">${button('copy-worksheet','기존 활동지 복사',p.state!=='confirmed'?'disabled':'')}${button('new-worksheet',icon('plus')+' 활동지 추가',`class="primary" ${p.state!=='confirmed'?'disabled':''}`)}</div>`:'';
    root.querySelector('[data-project-selection]').innerHTML=state.stage==='criteria'?'':`<div class="oj-ai-project-workspace__selection"><label>활동지<select aria-label="현재 평가의 활동지" data-project-worksheet-select ${p.worksheets.length?'':'disabled'}>${p.worksheets.length?p.worksheets.map(w=>`<option value="${escape(w.worksheet_id)}" ${w.worksheet_id===state.worksheetId?'selected':''}>${escape(w.title)}</option>`).join(''):'<option value="">등록된 활동지 없음</option>'}</select></label>${worksheetActions}</div>`;
    content.innerHTML=state.stage==='criteria'?`<div data-stage-content>${criteriaForm()}</div>`:(!p.worksheets.length?'<p class="empty">이 평가에 등록된 활동지가 없습니다.</p>':'');
    window.OJAIWorkflow?.setSelection({projectId:p.project_id,projectTitle:p.title,worksheetId:state.worksheetId});
    window.OJAIWorkflow?.setStage(state.stage);
  }
  function criteriaForm() {
    const d=state.draft;
    return `<form data-criteria-form><section class="band"><label>평가 이름<input data-field="title" value="${escape(state.project.title)}" maxlength="255" required></label></section><div class="paired band"><section><div class="section-heading"><h2>학습 목표</h2>${button('add-goal',icon('plus'),'class="icon" title="학습 목표 추가" aria-label="학습 목표 추가"')}</div>${d.learning_goals.map((g,i)=>`<div class="entry"><label>목표 ${i+1}<textarea data-kind="goal" data-index="${i}" maxlength="1000">${escape(g.text)}</textarea></label>${remove('remove-goal','학습 목표 삭제',`data-index="${i}"`)}</div>`).join('')||'<p class="meta">등록된 학습 목표가 없습니다.</p>'}</section><section><div class="section-heading"><h2>성취기준</h2>${button('add-standard',icon('plus'),'class="icon" title="성취기준 추가" aria-label="성취기준 추가"')}</div>${d.achievement_standards.map((s,i)=>`<div class="standard"><label>코드 (선택)<input data-kind="standard" data-prop="code" data-index="${i}" maxlength="100" value="${escape(s.code)}"></label><label>성취기준 ${i+1}<textarea data-kind="standard" data-prop="text" data-index="${i}" maxlength="2000">${escape(s.text)}</textarea></label>${remove('remove-standard','성취기준 삭제',`data-index="${i}"`)}</div>`).join('')||'<p class="meta">등록된 성취기준이 없습니다.</p>'}</section></div><section class="band"><div class="section-heading"><h2>평가 기준</h2><div class="actions">${button('template','공통 기준 가져오기')}${button('add-criterion',icon('plus')+' 평가 항목 추가')}</div></div>${d.criteria.map(criterionForm).join('')||'<p class="meta">등록된 평가 항목이 없습니다.</p>'}<p class="meta">총점 <strong data-total>${escape(d.total_points)}</strong>점</p></section><footer class="footer"><span class="meta" data-save-state>${state.dirty?'저장하지 않은 변경':'저장됨'}</span><div class="actions">${button('save',icon('save')+' 초안 저장')}${button('confirm','기준 확정 · 활동지 작성','class="primary"')}</div></footer></form><details class="band"><summary>기준 변경 이력</summary>${(state.project.history||[]).map(r=>`<p>${badge(r.state,r.revision_no)} <span class="meta">${escape(r.created_at_utc)} UTC</span></p>`).join('')}</details>`;
  }
  function criterionForm(c,i) {
    return `<div class="criterion"><div class="criterion-fields"><label>평가 항목 ${i+1}<input data-kind="criterion" data-prop="label" data-index="${i}" maxlength="120" value="${escape(c.label)}"></label>${remove('remove-criterion','평가 항목 삭제',`data-index="${i}"`)}</div><label>평가 방법<textarea data-kind="criterion" data-prop="description" data-index="${i}" maxlength="1000">${escape(c.description)}</textarea></label><div class="levels">${c.levels.map((l,j)=>`<div class="level"><label>등급별 수행 기준 ${j+1}<textarea data-kind="level" data-prop="label" data-index="${i}" data-level="${j}" maxlength="500">${escape(l.label)}</textarea></label><label>점수<input type="number" min="0" max="100000" step="0.01" data-kind="level" data-prop="points" data-index="${i}" data-level="${j}" value="${escape(l.points)}"></label>${remove('remove-level','등급 삭제',`data-index="${i}" data-level="${j}"`)}</div>`).join('')}${button('add-level','등급 추가',`data-index="${i}"`)}</div></div>`;
  }
  function totals(){let sum=0;for(const [i,c] of state.draft.criteria.entries()){c.position=i;for(const [j,l] of c.levels.entries())l.position=j;const max=Math.max(0,...c.levels.map(l=>Math.round(Number(l.points)*100)));c.maximum_points=Number.isFinite(max)?String(max/100):'';sum+=max;}state.draft.total_points=Number.isFinite(sum)?String(sum/100):'';const node=root.querySelector('[data-total]');if(node)node.textContent=state.draft.total_points;}
  function repaintCriteria(){totals();root.querySelector('[data-stage-content]').innerHTML=criteriaForm();}
  async function save(confirmed) {
    if(!root.querySelector('[data-criteria-form]').reportValidity())return;
    totals(); const title=root.querySelector('[data-field=title]').value.trim();
    const editVersion=state.editVersion;
    const body=command({revision_id:state.project.current_revision_id},{title,configuration:copy(state.draft),state:confirmed?'confirmed':'draft'});
    message();root.setAttribute('aria-busy','true');
    root.querySelector('[data-save-state]').textContent='저장 중';
    try {
      const result=await api('/assessment-projects/'+state.project.project_id+'/revisions',body);
      const laterTitle=state.project.title;
      state.project=result.data.project;state.dirty=state.editVersion!==editVersion;
      if(state.dirty){
        state.project.title=laterTitle;
        render();message('저장했습니다. 이후 추가한 변경 내용은 아직 저장되지 않았습니다.');return;
      }
      state.draft=copy(state.project.configuration);
      if(confirmed)setStage('author');else render();message(confirmed?'평가 기준을 확정했습니다.':'평가 기준 초안을 저장했습니다.');
    } finally {
      root.removeAttribute('aria-busy');
      const label=root.querySelector('[data-save-state]');if(label)label.textContent=state.dirty?'저장하지 않은 변경':'저장됨';
    }
  }
  function setStage(stage){navigate(projectUrl(state.project.project_id,stage,state.worksheetId));}
  function openDialog(title,body,submit,callback) {
    dialog.innerHTML=`<form method="dialog"><header><h2 id="project-dialog-title">${title}</h2>${button('close-dialog',icon('x'),'class="icon" title="닫기" aria-label="닫기"')}</header>${body}<p class="dialog-error" role="alert"></p><footer class="actions">${button('close-dialog','취소')}<button type="submit" class="primary">${submit}</button></footer></form>`;
    dialog.querySelector('form').addEventListener('submit',async event=>{
      event.preventDefault();if(state.busy)return;
      const data=new FormData(event.target);
      const controls=[...dialog.querySelectorAll('input,select,textarea,button')].map(control=>[control,control.disabled]);
      const submitButton=dialog.querySelector('[type=submit]');
      const label=submitButton.textContent;
      controls.forEach(([control])=>{control.disabled=true;});
      submitButton.textContent='처리 중';dialog.setAttribute('aria-busy','true');
      dialog.querySelector('.dialog-error').textContent='';state.busy=true;
      try{await callback(data);dialog.close();}
      catch(error){dialog.querySelector('.dialog-error').textContent=error.message;}
      finally{
        state.busy=false;dialog.removeAttribute('aria-busy');
        controls.forEach(([control,disabled])=>{control.disabled=disabled;});
        submitButton.textContent=label;
        if(dialog.open)submitButton.focus();
      }
    });
    dialog.showModal();dialog.querySelector('input,select,textarea')?.focus();
  }
  async function newProject(sourceId=null) {
    let source=null;if(sourceId){const r=await api('/assessment-projects/'+sourceId);source=r.data.project;}
    openDialog(source?'평가 기준 복사':'새 평가',`<label>평가 이름<input name="title" required maxlength="255" value="${escape(source?source.title+' 복사':'')}"></label>`,source?'복사':'만들기',async form=>{
      const result=await api('/assessment-projects',command({expected_state:'absent'},{title:form.get('title'),copy_from:source?.project_id||null,source_revision_id:source?.revision_id||null}));
      location.assign(projectUrl(result.data.project.project_id));
    });
  }
  function archiveProject(projectId,archived) {
    const item=state.project||state.items.find(p=>p.project_id===projectId);
    if(!item||item.project_id!==projectId||!Number.isSafeInteger(item.lock_version))throw Error('평가 목록을 새로고침한 뒤 다시 시도해 주세요.');
    openDialog(archived?'평가 보관':'평가 복원',`<p><strong>${escape(item.title)}</strong></p><p>${archived?'활성 목록에서 제외하고 보관함으로 옮깁니다.':'활성 목록으로 되돌립니다.'}</p><p>활동지·제출·평가 기록은 유지됩니다. 진행 중인 배포와 학생의 접근은 변경되지 않습니다.</p>`,archived?'보관':'복원',async()=>{
      const result=await api('/assessment-projects/'+projectId+'/archive',command({lock_version:item.lock_version},{archived}));
      if(state.project){state.project=result.data.project;state.draft=copy(state.project.configuration);state.dirty=false;render();}
      else{state.items=state.items.filter(p=>p.project_id!==projectId);renderList();}
      message(archived?'평가를 보관했습니다.':'평가를 활성 목록으로 복원했습니다.');
      dialog.addEventListener('close',()=>root.querySelector(state.project?`[data-action="${archived?'restore':'archive'}"]`:'[name="project-list-view"]:checked')?.focus(),{once:true});
    });
  }
  async function importTemplate() {
    const r=await api('/settings/rubrics');const subject=r.data?.rubrics?.subjects?.find(s=>s.subject_type==='student_submission');const templates=subject?.revisions?.filter(r=>Array.isArray(r.value?.criteria))||[];
    if(!templates.length)throw Error('가져올 공통 기준이 없습니다.');
    openDialog('공통 기준 가져오기',`<label>기준 버전<select name="revision">${templates.map((r,i)=>`<option value="${i}">${r.revision_no}차 · ${escape(r.state==='active'?'사용 중':r.state==='retired'?'이전 버전':'초안')}</option>`).join('')}</select></label>`,'가져오기',async form=>{
      const chosen=templates[Number(form.get('revision'))];if(!chosen)throw Error('기준을 선택해 주세요.');
      if(state.draft.criteria.length&&!(await ask({title:'평가 항목을 교체할까요?',message:'현재 평가 항목을 선택한 공통 기준의 복사본으로 교체합니다.',confirmLabel:'교체'},'현재 평가 항목을 선택한 공통 기준의 복사본으로 교체할까요?')))return;
      state.draft.criteria=copy(chosen.value.criteria).map(c=>({...c,criterion_id:id(),description:c.description||'',levels:(c.levels||[]).map(l=>({...l,level_key:id()}))}));changed();repaintCriteria();
    });
  }
  async function copyWorksheet() {
    const items=[];let cursor='';const seen=new Set();
    do { const r=await api('/worksheets?limit=100'+(cursor?'&cursor='+encodeURIComponent(cursor):''));
      if(!Array.isArray(r.data?.items))throw Error('활동지 목록을 읽지 못했습니다.');
      items.push(...r.data.items);cursor=r.meta?.next_cursor;
      if(cursor&&seen.has(cursor))throw Error('활동지 목록을 다시 불러와 주세요.');seen.add(cursor);
    } while(cursor);
    if(!items.length)throw Error('복사할 활동지가 없습니다.');
    openDialog('기존 활동지 복사',`<label>활동지<select name="source">${items.map((w,i)=>`<option value="${i}">${escape(w.title)}</option>`).join('')}</select></label>`,'이 평가에 복사',async form=>{
      const source=items[Number(form.get('source'))];
      const response=await api('/worksheets/'+source.id);const w=response.data.worksheet;
      const r=await api('/assessment-projects/'+state.project.project_id+'/worksheets/copy',command({revision_id:state.project.revision_id},{worksheet_id:w.id,revision_id:w.current_revision_id,state:w.state}));
      navigate(projectUrl(state.project.project_id,'author',r.data.worksheet_id));
    });
  }
  root.addEventListener('input',event=>{
    if(event.target.closest('[data-project-stage-body]'))return;
    const e=event.target; if(e.dataset.field==='search'){state.search=e.value;root.querySelector('[data-list-table]').innerHTML=listTable(state.items.filter(p=>p.title.toLocaleLowerCase().includes(e.value.toLocaleLowerCase())));return;}
    if(e.dataset.field==='title'){state.project.title=e.value;changed();return;}
    const i=Number(e.dataset.index),j=Number(e.dataset.level),kind=e.dataset.kind;
    if(kind==='goal')state.draft.learning_goals[i].text=e.value;
    else if(kind==='standard')state.draft.achievement_standards[i][e.dataset.prop]=e.value;
    else if(kind==='criterion')state.draft.criteria[i][e.dataset.prop]=e.value;
    else if(kind==='level')state.draft.criteria[i].levels[j][e.dataset.prop]=e.value;
    else return;changed();totals();
  });
  root.addEventListener('click',async event=>{
    if(event.target.closest('[data-project-stage-body]'))return;
    const b=event.target.closest('[data-action]');if(!b)return;const action=b.dataset.action;if(state.busy)return;if(action==='close-dialog'){dialog.close();return;}
    try{
      const i=Number(b.dataset.index),j=Number(b.dataset.level);
      if(action==='stage'){if(state.dirty){message('기준 변경 내용을 저장한 뒤 이동해 주세요.',true);return;}setStage(b.dataset.stage);return;}
      if(action==='reload'){if(canLeave())navigate(location.href);return;}
      if(action==='new'||action==='copy'){if(canLeave())await newProject(action==='copy'?b.dataset.id:null);return;}
      if(action==='archive'||action==='restore'){if(document.dispatchEvent(new CustomEvent('ojai:before-navigation',{cancelable:true,detail:{href:location.href}})))archiveProject(b.dataset.id,action==='archive');return;}
      if(action==='template'){await importTemplate();return;}
      if(action==='copy-worksheet'){if(document.dispatchEvent(new CustomEvent('ojai:before-navigation',{cancelable:true,detail:{href:location.href}})))await copyWorksheet();return;}
      if(action==='new-worksheet'){
        if(!document.dispatchEvent(new CustomEvent('ojai:before-navigation',{cancelable:true,detail:{href:location.href}})))return;
        openDialog('활동지 추가',`<label>활동지 이름<input name="title" maxlength="255" required></label>`,'만들기',async form=>{const r=await api('/assessment-projects/'+state.project.project_id+'/worksheets',command({revision_id:state.project.revision_id},{title:form.get('title')}));navigate(projectUrl(state.project.project_id,'author',r.data.worksheet_id));});return;
      }
      if(action==='save'||action==='confirm'){state.busy=true;b.disabled=true;await save(action==='confirm');return;}
      const d=state.draft;
      if(action==='add-goal')d.learning_goals.push({goal_id:id(),text:''});
      else if(action==='remove-goal')d.learning_goals.splice(i,1);
      else if(action==='add-standard')d.achievement_standards.push({standard_id:id(),code:'',text:''});
      else if(action==='remove-standard')d.achievement_standards.splice(i,1);
      else if(action==='add-criterion')d.criteria.push({criterion_id:id(),label:'',description:'',scope:'submission',maximum_points:'1',position:d.criteria.length,levels:[{level_key:id(),label:'',points:'1',position:0},{level_key:id(),label:'',points:'0',position:1}]});
      else if(action==='remove-criterion')d.criteria.splice(i,1);
      else if(action==='add-level'){if(d.criteria[i].levels.length>=10)throw Error('등급은 최대 10개까지 추가할 수 있습니다.');d.criteria[i].levels.push({level_key:id(),label:'',points:'0',position:d.criteria[i].levels.length});}
      else if(action==='remove-level'){if(d.criteria[i].levels.length<=2)throw Error('등급은 최소 2개가 필요합니다.');d.criteria[i].levels.splice(j,1);}
      else return;
      changed();repaintCriteria();
    }catch(error){message(error.message,true);}finally{state.busy=false;b.disabled=false;}
  });
  root.addEventListener('change', async event => {
    if(event.target.matches('[name="project-list-view"]')) {
      state.listView=event.target.value==='archived'?'archived':'active';
      const url=new URL(location.href);if(state.listView==='archived')url.searchParams.set('list','archived');else url.searchParams.delete('list');history.replaceState(null,'',url.href);
      state.items=[];renderList();message('평가를 불러오는 중입니다.');
      try{if(await loadList()){renderList();message();root.querySelector(`[name="project-list-view"][value="${state.listView}"]`)?.focus();}}
      catch(error){message(error.message,true);}return;
    }
    if(event.target.matches('[data-project-worksheet-select]')) {
      const chosen=event.target.value;
      if(!state.project.worksheets.some(w=>w.worksheet_id===chosen))return;
      if(canLeave())navigate(projectUrl(state.project.project_id,state.stage,chosen));
      event.target.value=state.worksheetId;return;
    }
    if (!event.target.matches('[data-project-stage-select]')) return;
    if (state.dirty) { message('기준 변경 내용을 저장한 뒤 이동해 주세요.', true); event.target.value = state.stage; return; }
    setStage(event.target.value);
  });
  window.addEventListener('beforeunload',event=>{if(state.dirty){event.preventDefault();event.returnValue='';}});
  dialog.addEventListener('cancel',event=>{if(state.busy)event.preventDefault();});
  document.addEventListener('ojai:before-navigation',event=>{if(!canLeave())event.preventDefault();});
  content.addEventListener('submit',event=>event.preventDefault());
  const projectId=query.get('project_id');
  if(projectId&&!validId(projectId)){message('평가 주소가 올바르지 않습니다.',true);return;}
  load(projectId).catch(error=>{
    content.innerHTML=button('reload',icon('refresh-cw')+' 다시 불러오기');
    message(error.message,true);
  });
})();
