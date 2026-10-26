(() => {
  'use strict';
  if (window.OJAIWorkflow) return;
  const id = value => /^[0-9A-HJKMNP-TV-Z]{26}$/.test(String(value || '')) ? value : '';
  const token = value => typeof value === 'string' && value.length <= 191 && /^[A-Za-z0-9][A-Za-z0-9_.:@/-]*$/.test(value) ? value : '';
  const params = new URLSearchParams(location.search);
  const nav = document.querySelector('[data-oj-ai-workflow]');
  const projectWorkspace = document.querySelector('#oj-ai-projects');
  const originProject = id(params.get('from_project'));
  const originWorksheet = id(params.get('from_worksheet'));
  const stages = ['criteria','author','review','assignment','assessment','report','release'];
  const preferredContextIndex = (items, singleOnly = true) => {
    const groupIndexes = items.map((item,index) => item.type === 'group' ? index : -1).filter(index => index >= 0);
    if (groupIndexes.length) return !singleOnly || groupIndexes.length === 1 ? groupIndexes[0] : -1;
    return items.length && (!singleOnly || items.length === 1) ? 0 : -1;
  };
  const contextPickers = new WeakMap();
  const contextPicker = (select, items, selectedIndex = -1) => {
    if (!select) return null;
    const existing = contextPickers.get(select);
    if (existing) { existing.update(items, selectedIndex); return existing; }
    const label = select.closest('label');
    const wrapper = document.createElement('div'); wrapper.className = 'oj-ai-context-picker';
    const typeLabel = document.createElement('label');
    const caption = document.createElement('span'); caption.textContent = '대상 구분';
    const type = document.createElement('select'); type.dataset.contextType = '';
    for (const [value, text] of [['group','그룹(반)'], ['class','특정 수업']]) {
      const option = document.createElement('option'); option.value = value; option.textContent = text; type.append(option);
    }
    typeLabel.append(caption,type); label.before(wrapper); wrapper.append(typeLabel,label);
    let contexts = items;
    const render = (kind, index = -1) => {
      type.value = kind;
      const name = kind === 'group' ? '그룹(반)' : '수업';
      const span = label.querySelector('span'); if (span) span.textContent = name;
      const empty = document.createElement('option'); empty.value = '';
      empty.textContent = contexts.some(item => item.type === kind) ? `${name} 선택` : `선택할 ${name} 없음`;
      select.replaceChildren(empty);
      contexts.forEach((item,i) => {
        if (item.type !== kind) return;
        const option = document.createElement('option'); option.value = String(i); option.textContent = item.label; select.append(option);
      });
      select.value = index >= 0 && contexts[index]?.type === kind ? String(index) : '';
      type.dataset.previousValue = kind;
    };
    const setSelected = index => render(contexts[index]?.type || type.value || 'group', index);
    type.addEventListener('change', () => {
      const nextType = type.value;
      const previousValue = select.value;
      select.dataset.navigationPreviousValue = previousValue;
      select.value = '';
      let accepted = false;
      const reached = () => { accepted = true; };
      select.addEventListener('change', reached);
      select.dispatchEvent(new Event('change', { bubbles:true }));
      select.removeEventListener('change', reached);
      if (!accepted) { type.value = type.dataset.previousValue; select.value = previousValue; return; }
      render(nextType);
    });
    const picker = { setSelected, update(nextItems,index) { contexts = nextItems; setSelected(index); } };
    contextPickers.set(select,picker);
    render(contexts[selectedIndex]?.type || (contexts.some(item => item.type === 'group') || !contexts.length ? 'group' : 'class'),selectedIndex);
    return picker;
  };
  const originStage = stages.includes(params.get('from_stage')) ? params.get('from_stage') : 'criteria';
  const selected = {
    projectId:id(params.get('project_id')),projectTitle:'',
    worksheetId: id(nav?.dataset.workflowWorksheet) || id(params.get('worksheet_id')),
    assignmentId: id(params.get('assignment_id')),
    publicationId: id(params.get('publication_id')),
    publicationHash: /^[a-f0-9]{64}$/.test(params.get('publication_hash') || '') ? params.get('publication_hash') : '',
    context: ['class', 'group'].includes(params.get('context_type')) && token(params.get('context_id'))
      ? {type:params.get('context_type'), id:token(params.get('context_id')), revision:token(params.get('context_revision'))} : null,
    title: '', assignmentTitle: '',
  };
  const scope = () => {
    const result = new URLSearchParams();
    if(selected.projectId)result.set('project_id',selected.projectId);
    if (selected.worksheetId) result.set('worksheet_id', selected.worksheetId);
    if (selected.assignmentId) result.set('assignment_id', selected.assignmentId);
    if (selected.publicationId && selected.publicationHash) { result.set('publication_id',selected.publicationId); result.set('publication_hash',selected.publicationHash); }
    if (selected.context) for (const key of ['type','id','revision']) if (selected.context[key]) result.set('context_'+key,selected.context[key]);
    return result;
  };
  const overviewUrl = (route, preserveContext = true) => {
    const entry = document.querySelector('[data-workspace-link]');
    const base = entry?.dataset.baseHref || entry?.href || document.querySelector('[data-workspace-base]')?.dataset.workspaceBase || '/oj-console/addons/oj-ai-dashboard/projects';
    const target = new URL(route, new URL(base, location.href));
    if (preserveContext && selected.context) for (const key of ['type','id','revision']) if(selected.context[key])target.searchParams.set('context_'+key, selected.context[key]);
    const project = selected.projectId || originProject;
    if (project) {
      target.searchParams.set('from_project',project);
      const worksheet = selected.projectId ? selected.worksheetId : originWorksheet;
      if(worksheet)target.searchParams.set('from_worksheet',worksheet);
      target.searchParams.set('from_stage',selected.projectId ? (nav?.dataset.currentStep || 'criteria') : originStage);
    }
    return target.href;
  };
  const canNavigate = stage => {
    if(stage === 'summary' && projectWorkspace)return projectWorkspace.dataset.canSummary === '1';
    const step = nav?.querySelector(`[data-workflow-step="${stage}"]`);
    if(step?.getAttribute('aria-disabled') === 'true')return false;
    if(stage === 'summary' && document.querySelector('[data-workspace-navigation]'))return Boolean(document.querySelector('[data-workspace-link="reports"]'));
    const marker=document.querySelector('[data-workspace-reports]');
    if(stage === 'summary' && marker)return marker.dataset.workspaceReports === '1';
    return true;
  };
  const renderOverview = () => {
    document.querySelectorAll('[data-workspace-link]').forEach(link=>{
      if(!link.dataset.baseHref)link.dataset.baseHref=link.href;
      link.href=overviewUrl(link.dataset.workspaceLink,false);
    });
    document.querySelectorAll('[data-workflow-overview]').forEach(link=>{
      link.hidden=Boolean(projectWorkspace) || (!selected.worksheetId && !selected.projectId);
      link.href=overviewUrl(link.dataset.workflowOverview);
    });
    const back=document.querySelector('[data-workspace-return]');
    const projects=document.querySelector('[data-workspace-link="projects"]');
    const projectsBase=projects?.dataset.baseHref || (back?.dataset.workspaceBase ? new URL('projects', new URL(back.dataset.workspaceBase,location.href)).href : '');
    if(back && projectsBase && originProject && !selected.projectId){
      const target=new URL('projects', new URL(projectsBase,location.href));
      target.searchParams.set('project_id',originProject);target.searchParams.set('stage',originStage);
      if(originWorksheet)target.searchParams.set('worksheet_id',originWorksheet);
      back.href=target.href;back.textContent='작업하던 평가로 돌아가기';back.hidden=false;
    }
  };
  const url = (stage, extra = {}) => {
    const base = nav?.dataset.workflowBase || document.querySelector('[data-workflow-base]')?.dataset.workflowBase || '/oj-console/addons/oj-ai-dashboard/';
    const query = scope();
    query.set('stage',stage);
    const route = selected.projectId && stage!=='summary' ? 'projects' : ['criteria','author','review'].includes(stage) ? 'worksheets/'+encodeURIComponent(selected.worksheetId)
      : stage === 'assignment' ? 'assignments' : stage === 'summary' ? 'reports' : 'assessments';
    if (route === 'assessments') query.set('type','submission');
    for (const [key,value] of Object.entries(extra)) if (value) query.set(key,String(value));
    return new URL(route+'?'+query, new URL(base,location.href)).href;
  };
  const render = () => {
    renderOverview();
    if (selected.projectId && nav && !projectWorkspace) {
      const stage=params.get('stage') || (document.querySelector('#oj-ai-worksheet-authoring-app') ? 'author' : nav.dataset.currentStep);
      if (['criteria','author','review','assignment','assessment','report','release'].includes(stage)) { location.replace(url(stage)); return; }
    }
    if (nav) {
      nav.hidden = !selected.worksheetId && !projectWorkspace;
      nav.dataset.workflowWorksheet = selected.worksheetId;
      nav.querySelectorAll('a[data-workflow-step]').forEach(a=>{a.href=url(a.dataset.workflowStep);});
    }
    if (projectWorkspace) return;
    const header = document.querySelector('[data-workflow-context]');
    if (header) {
      header.hidden = !selected.worksheetId;
      header.querySelector('[data-workflow-title]').textContent=selected.title || selected.assignmentTitle || '활동지 작업';
      header.querySelector('[data-workflow-scope]').textContent=[selected.context?.label, selected.title ? selected.assignmentTitle : ''].filter(Boolean).join(' · ');
      const back=header.querySelector('[data-workflow-return]');
      if(back&&selected.projectId){back.textContent=selected.projectTitle||'이 평가로 돌아가기';back.href=url('criteria');}
    }
    const editorBack = document.querySelector('[data-workflow-editor-return]');
    if (editorBack) {
      if (!editorBack.dataset.defaultHref) editorBack.dataset.defaultHref = editorBack.href;
      const projectUrl = new URL(url('criteria'));
      projectUrl.searchParams.set('stage', 'author');
      editorBack.href = selected.projectId ? projectUrl.href : editorBack.dataset.defaultHref;
      editorBack.setAttribute('aria-label', selected.projectId ? '이 평가의 활동지 목록으로' : '활동지 목록으로');
    }
  };
  const setSelection = value => {
    const projectChanged='projectId' in value && selected.projectId && id(value.projectId) && id(value.projectId)!==selected.projectId;
    const worksheetChanged='worksheetId' in value && id(value.worksheetId)!==selected.worksheetId;
    const contextChanged='context' in value && ['type','id','revision'].some(key=>(value.context?.[key]||'')!==(selected.context?.[key]||''));
    const assignmentChanged='assignmentId' in value && id(value.assignmentId)!==selected.assignmentId;
    if(projectChanged){selected.worksheetId='';selected.projectTitle='';selected.title='';selected.context=null;}
    if(projectChanged || worksheetChanged || contextChanged){selected.assignmentId='';selected.assignmentTitle='';selected.publicationId='';selected.publicationHash='';}
    if(worksheetChanged)selected.title='';
    if(assignmentChanged){selected.assignmentTitle='';selected.publicationId='';selected.publicationHash='';}
    if('publicationId' in value && id(value.publicationId)!==selected.publicationId)selected.publicationHash='';
    if('projectId' in value)selected.projectId=id(value.projectId);
    if('projectTitle' in value)selected.projectTitle=String(value.projectTitle||'');
    if ('worksheetId' in value) selected.worksheetId=id(value.worksheetId);
    if ('assignmentId' in value) selected.assignmentId=id(value.assignmentId);
    if ('assignmentId' in value && !value.assignmentId) { selected.publicationId='';selected.publicationHash=''; }
    if ('publicationId' in value) selected.publicationId=id(value.publicationId);
    if ('publicationHash' in value) selected.publicationHash=/^[a-f0-9]{64}$/.test(value.publicationHash || '') ? value.publicationHash : '';
    if ('title' in value) selected.title=String(value.title || '');
    if ('assignmentTitle' in value) selected.assignmentTitle=String(value.assignmentTitle || '');
    if ('context' in value) selected.context=value.context && ['class','group'].includes(value.context.type) && token(value.context.id)
      ? {type:value.context.type,id:value.context.id,revision:token(value.context.revision),label:String(value.context.label || '')} : null;
    const current=new URL(location.href);
    for(const key of ['project_id','worksheet_id','assignment_id','publication_id','publication_hash','context_type','context_id','context_revision']) current.searchParams.delete(key);
    scope().forEach((v,k)=>current.searchParams.set(k,v));
    window.history.replaceState(window.history.state,'',current.href);
    render();
  };
  const setStage = stage => {
    if (!stages.includes(stage) || !nav || !nav.querySelector(`[data-workflow-step="${stage}"]`) || !canNavigate(stage)) return;
    if (projectWorkspace && stage !== projectWorkspace.dataset.projectStage) {
      if(document.dispatchEvent(new CustomEvent('ojai:before-navigation',{cancelable:true,detail:{href:url(stage)}})))location.assign(url(stage));
      return;
    }
    nav.dataset.currentStep=stage;
    nav.querySelectorAll('[data-workflow-step]').forEach(e=>{if(e.dataset.workflowStep===stage)e.setAttribute('aria-current','step');else e.removeAttribute('aria-current');});
    nav.querySelector('[data-workflow-select]').value=stage;
    const current=new URL(location.href);current.searchParams.set('stage',stage);window.history.replaceState(window.history.state,'',current.href);
  };
  const allPages = async (request, path) => {
    const [route,search='']=path.split('?');
    const query=new URLSearchParams(search); const seen=new Set();const items=[];
    do {
      const page=await request(route+'?'+query.toString());
      if(!Array.isArray(page?.data?.items))throw Error('목록을 확인할 수 없습니다.');
      items.push(...page.data.items);
      const cursor=page.meta?.next_cursor || '';
      if(!cursor)return items;
      if(seen.has(cursor))throw Error('목록을 다시 불러와 주세요.');
      seen.add(cursor);query.set('cursor',cursor);
    } while(true);
  };
  window.OJAIWorkflow={read:()=>({...selected,context:selected.context?{...selected.context}:null}),url,overviewUrl,canNavigate,setSelection,setStage,allPages,contextPicker,preferredContextIndex};
  if(selected.projectId && nav && !projectWorkspace) {
    const stage=params.get('stage') || (document.querySelector('#oj-ai-worksheet-authoring-app') ? 'author' : nav.dataset.currentStep) || 'author';
    if(['criteria','author','review','assignment','assessment','report','release'].includes(stage)) { location.replace(url(stage)); return; }
  }
  document.addEventListener('click',event=>{
    const link=event.target.closest?.('a');
    if(event.defaultPrevented || !link?.href || link.target === '_blank' || link.hasAttribute('download') || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)return;
    if(new URL(link.href,location.href).origin===location.origin && !document.dispatchEvent(new CustomEvent('ojai:before-navigation',{cancelable:true,detail:{href:link.href}})))event.preventDefault();
  });
  nav?.querySelector('[data-workflow-select]')?.addEventListener('change',event=>{
    const control=nav.querySelector(`[data-workflow-step="${event.target.value}"]`);
    control?.click();
    event.target.value=nav.dataset.currentStep;
  });
  render();
})();
