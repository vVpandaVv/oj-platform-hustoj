(() => {
  'use strict';

  const root = document.getElementById('oj-ai-assignment-app');
  if (!root) return;

  const learnerLabels = window.OJAILearnerLabels.create(root);

  const restRoot = root.dataset.restRoot || '/wp-json/oj-ai/v1';
  const nonce = root.dataset.restNonce || '';
  const canManageSubmissions = root.dataset.canManageSubmissions === 'true';
  const canAssessSubmissions = root.dataset.canAssessSubmissions === 'true';
  const assessmentsUrl = root.dataset.assessmentsUrl || '/oj-console/addons/oj-ai-dashboard/assessments?type=submission';
  const requestedWorksheetId = (() => {
    const value = new URLSearchParams(window.location?.search || '').get('worksheet_id') || '';
    return /^[0-9A-HJKMNP-TV-Z]{26}$/.test(value) ? value : '';
  })();
  const contexts = [];
  let filterPicker = null;
  let targetPicker = null;
  const workflowEntry = window.OJAIWorkflow?.read() || {};
  const publications = [];
  let assignments = [];
  let assignmentListRequest = 0;
  let assignmentDetailRequest = 0;
  let step = 1;
  let wizardTrigger = null;
  let activeAssignment = null;
  let lifecycleAction = '';
  let lifecycleTrigger = null;
  let detailTrigger = null;
  let overviewScroll = 0;
  let lifecycleBusy = false;
  let submissionControlBusy = false;
  let submissionItems = [];
  let submissionAssignment = null;
  let activeSubmission = null;
  let activeSubmissionName = '';
  let submissionPanelTrigger = null;
  let submissionListRequest = 0;
  let submissionControlAction = '';
  let submissionControlTrigger = null;
  const submissionPdfJobs = new Map();
  const attachmentUrls = new Set();
  let lifecycleConfirmation = null;
  let targetAddition = null;

  const find = (selector) => root.querySelector(selector);
  const all = (selector) => Array.from(root.querySelectorAll(selector));
  const create = (tag, className = '', value = '') => { const node = document.createElement(tag); if (className) node.className = className; if (value) node.textContent = value; return node; };
  const text = (selector, value) => { const node = find(selector); if (node) node.textContent = value; };
  const icon = (name) => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    svg.setAttribute('class', 'oj-ai-icon'); svg.setAttribute('aria-hidden', 'true');
    use.setAttribute('href', `${root.dataset.iconSprite || ''}#${name}`); svg.append(use); return svg;
  };
  const showAssignmentView = (view) => {
    const overview = find('[data-role="assignment-overview"]');
    const detail = find('[data-role="assignment-detail"]');
    const submissions = find('[data-role="submission-panel"]');
    if (overview) overview.hidden = view !== 'overview';
    if (detail) detail.hidden = view !== 'detail';
    if (submissions) submissions.hidden = view !== 'submissions';
    root.dataset.view = view;
  };
  const dialogBusy = (selector, busy) => {
    const dialog = find(selector);
    dialog?.setAttribute('aria-busy', String(busy));
    dialog?.querySelectorAll('button, input, select, textarea').forEach(control => { control.disabled = busy; });
  };
  const selected = (field) => {
    const select = find(`[data-field="${field}"]`);
    return select && select.value !== '' ? Number(select.value) : -1;
  };
  const request = async (path, options = {}) => {
    const response = await fetch(`${restRoot}${path}`, {
      credentials: 'same-origin',
      ...options,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': nonce, ...(options.headers || {}) },
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) { const error = new Error(body.message || '요청을 처리하지 못했습니다.'); error.status = response.status; throw error; }
    return body;
  };
  const option = (label, index) => {
    const node = document.createElement('option'); node.value = String(index); node.textContent = label; return node;
  };
  const contextQuery = (context) => new URLSearchParams({ context_type: context.type, context_id: context.id, context_revision: context.revision, limit: '100' }).toString();
  const contextScopeQuery = (context) => new URLSearchParams({ context_type: context.type, context_id: context.id, context_revision: context.revision }).toString();
  const localToUtc = (value) => value ? new Date(value).toISOString().replace(/\.\d{3}Z$/, 'Z') : null;
  const utcToLocal = (value) => {
    if (!value) return '';
    const date = new Date(value.replace(' ', 'T') + (value.includes('Z') ? '' : 'Z'));
    return new Date(date.getTime() - (date.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
  };
  const formatDate = (value) => value ? new Date(value.replace(' ', 'T') + (value.includes('Z') ? '' : 'Z')).toLocaleString() : '수동 공개';
  const stateLabel = (value) => ({ draft: '준비', scheduled: '예약', open: '진행', closed: '종료', withdrawn: '철회' }[value] || value);
  const submissionStateLabel = (value) => ({ not_started: '시작 전', draft: '작성 중', conflict: '저장 확인', submitted: '제출', auto_submitted: '자동 제출', proxy_submitted: '교사 제출', reopened: '다시 작성', locked: '잠김' }[value] || value);
  const evaluationLabel = (value) => ({ not_evaluated: '평가 전', requested: '요청됨', queued: '대기', running: '진행 중', review_required: '검토 대기', reviewed: '검토 완료', failed: '평가 실패', cancelled: '취소' }[value] || value || '평가 전');
  const releaseLabel = (value) => ({ not_released: '공개 전', released: '공개됨' }[value] || value || '공개 전');
  const receiptActionLabel = (value) => ({ submit: '제출', auto_submit: '자동 제출', proxy_submit: '교사 제출', reopen: '다시 열기', lock: '잠금' }[value] || value);
  const actionLabel = (value) => ({ update_schedule: '일정', open: '열기', close: '종료', reopen: '재개', withdraw: '철회' }[value] || value);
  const actionPath = (value) => value === 'update_schedule' ? 'update-schedule' : value;
  const commandToken = () => {
    const random = window.crypto && typeof window.crypto.randomUUID === 'function'
      ? window.crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`;
    return `assign-${random}`;
  };

  const assessmentTarget = (evaluationId = '') => {
    const target = new URL(window.OJAIWorkflow?.read().projectId ? window.OJAIWorkflow.url('assessment') : assessmentsUrl, window.location.href);
    const context = contexts[selected('context-filter')];
    target.searchParams.set('type', 'submission');
    target.searchParams.set('stage', 'assessment');
    const assignment = submissionAssignment || activeAssignment;
    if (assignment?.id) target.searchParams.set('assignment_id', assignment.id);
    if (assignment?.worksheet_id || requestedWorksheetId) target.searchParams.set('worksheet_id', assignment?.worksheet_id || requestedWorksheetId);
    if (context) {
      target.searchParams.set('context_type', context.type);
      target.searchParams.set('context_id', context.id);
      target.searchParams.set('context_revision', context.revision);
    }
    if (evaluationId) target.searchParams.set('evaluation_id', evaluationId);
    return target.toString();
  };
  const syncAssessmentLinks = () => {
    all('a[data-action="open-assessments"]').forEach(link => { link.href = assessmentTarget(link.dataset.evaluationId || ''); });
  };

  const loadBase = async () => {
    try {
      const [contextItems, worksheetItems] = await Promise.all([
        window.OJAIWorkflow.allPages(request, '/contexts?limit=100'), window.OJAIWorkflow.allPages(request, '/worksheets?state=active&limit=100'),
      ]);
      contexts.push(...contextItems.filter((item) => item.selectable));
      publications.push(...worksheetItems.filter((item) => (!requestedWorksheetId || item.id === requestedWorksheetId) && item.latest_publication_id && item.latest_publication_hash));
      const filter = find('[data-field="context-filter"]');
      const target = find('[data-field="context"]');
      const entryContext = workflowEntry.context;
      const contextIndex = entryContext ? contexts.findIndex(item => item.id === entryContext.id && item.type === entryContext.type && (!entryContext.revision || item.revision === entryContext.revision)) : window.OJAIWorkflow.preferredContextIndex(contexts);
      filterPicker = window.OJAIWorkflow.contextPicker(filter, contexts, contextIndex);
      targetPicker = window.OJAIWorkflow.contextPicker(target, contexts, contextIndex);
      const publication = find('[data-field="publication"]');
      publications.forEach((item, index) => publication?.append(option(item.title, index)));
      const requestedPublication = requestedWorksheetId
        ? publications.findIndex((item) => item.id === requestedWorksheetId)
        : -1;
      if (requestedPublication >= 0 && publication) {
        publication.value = String(requestedPublication);
        text('[data-role="notice"]', contexts.length
          ? '활동지를 선택했습니다. 배포 대상과 일정을 정해 주세요.'
          : '배포할 대상이 없습니다.');
        window.OJAIWorkflow.setSelection({ worksheetId: requestedWorksheetId, title: publications[requestedPublication].title });
      } else if (requestedWorksheetId) {
        text('[data-role="publication-notice"]', '새 배포에는 문서 확인·교사 검토 승인·발행이 필요합니다. AI 점검은 선택 사항입니다. 기존 배포는 대상을 선택하여 확인할 수 있습니다.');
      } else if (publications.length === 0) {
        text('[data-role="notice"]', '배포할 발행본이 없습니다. 활동지를 점검, 승인, 발행한 뒤 다시 시도해 주세요.');
      } else {
        text('[data-role="notice"]', contexts.length ? '배포 대상을 선택해 주세요.' : '배포할 대상이 없습니다.');
      }
      text('[data-role="assignment-scope"]', requestedWorksheetId ? '조회 범위: 현재 선택한 활동지의 배포' : '조회 범위: 선택한 대상의 전체 활동지 배포');
      if (contextIndex >= 0) {
        if (filter) filter.value = String(contextIndex);
        if (target) target.value = String(contextIndex);
        await loadAssignments();
        const selectedAssignment = assignments.find(item => item.id === workflowEntry.assignmentId);
        if (selectedAssignment) await loadDetail(selectedAssignment);
      } else if (entryContext) text('[data-role="notice"]', '이전에 선택한 대상을 확인할 수 없습니다. 대상을 다시 선택해 주세요.');
    } catch (error) { text('[data-role="notice"]', error.message); }
  };

  const loadAssignments = async () => {
    const requestId = ++assignmentListRequest;
    const index = selected('context-filter');
    const content = find('[data-role="assignment-content"]');
    const create = find('[data-action="new"]');
    window.OJAIWorkflow.setSelection({context:contexts[index] || null, assignmentId:'', assignmentTitle:'', publicationId:'', publicationHash:''});
    if (index < 0 || !contexts[index]) {
      assignments = []; renderAssignments([]);
      text('[data-role="summary"]','배포 대상을 선택하세요.');
      text('[data-role="notice"]','배포 대상을 선택해 주세요.');
      if (content) content.hidden = true; if (create) create.disabled = true; return;
    }
    const context = contexts[index];
    if (create) create.disabled = publications.length === 0;
    text('[data-role="notice"]', '배포 현황을 불러오는 중입니다.');
    try {
      const items = await window.OJAIWorkflow.allPages(request, `/assignments?${contextQuery(context)}`);
      if (requestId !== assignmentListRequest || contexts[selected('context-filter')] !== context) return;
      assignments = items.filter(item => !requestedWorksheetId || item.worksheet_id === requestedWorksheetId);
      window.OJAIWorkflow.setSelection({ context, assignmentId: '', assignmentTitle: '' });
      renderAssignments(assignments);
      text('[data-role="summary"]', `${context.label} · ${requestedWorksheetId ? '현재 활동지 ' : '전체 '}배포 ${assignments.length}개`);
      const otherCount = items.length - assignments.length;
      text('[data-role="notice"]', assignments.length ? '' : requestedWorksheetId
        ? `이 ${context.type === 'group' ? '그룹(반)' : '수업'}에는 현재 활동지의 배포가 없습니다.${otherCount ? ` 다른 활동지의 배포 ${otherCount}개가 있습니다.` : ''}`
        : '선택한 대상에 배포된 활동지가 없습니다.');
      if (content) content.hidden = false;
    } catch (error) {
      if (requestId !== assignmentListRequest || contexts[selected('context-filter')] !== context) return;
      text('[data-role="notice"]', error.message); if (content) content.hidden = true;
    }
  };

  const renderAssignments = (items) => {
    const body = find('[data-role="assignment-list"]'); if (!body) return; body.replaceChildren();
    items.forEach((item) => {
      const row = document.createElement('tr');
      const titleCell = document.createElement('td');
      const titleButton = document.createElement('button'); titleButton.type = 'button'; titleButton.className = 'oj-ai-assignment__table-link';
      titleButton.dataset.action = 'view-assignment'; titleButton.dataset.assignmentId = item.id; titleButton.textContent = item.title; titleCell.append(titleButton); row.append(titleCell);
      [stateLabel(item.state), `${item.target_count}명`, `${item.progress?.submitted || 0} / ${item.target_count}`, formatDate(item.schedule?.due_at_utc)].forEach((value) => {
        const cell = document.createElement('td'); cell.textContent = String(value); row.append(cell);
      });
      const actions = document.createElement('td'); actions.className = 'oj-ai-assignment__row-actions';
      const detailButton = create('button', 'oj-ai-assignment__icon'); detailButton.type = 'button';
      detailButton.dataset.action = 'view-assignment'; detailButton.dataset.assignmentId = item.id;
      detailButton.setAttribute('aria-label', `${item.title} 상세 보기`); detailButton.title = '상세 보기'; detailButton.append(icon('eye'));
      actions.append(detailButton);
      row.append(actions);
      body.append(row);
    });
  };
  const commandButton = (action, assignmentId) => {
    const button = document.createElement('button'); button.type = 'button'; button.dataset.action = 'assignment-command';
    button.dataset.command = action; button.dataset.assignmentId = assignmentId;
    button.textContent = action === 'update_schedule' ? '일정 변경' : `배포 ${actionLabel(action)}`;
    if (action === 'update_schedule') button.prepend(icon('edit'));
    if (['open', 'reopen'].includes(action)) button.className = 'oj-ai-assignment__primary';
    if (action === 'withdraw') button.className = 'oj-ai-assignment__danger';
    return button;
  };
  const assignmentById = (id) => {
    return assignments.find((item) => item.id === id)
      || (activeAssignment?.id === id ? activeAssignment : null);
  };

  const showStep = (nextStep) => {
    step = Math.min(4, Math.max(1, nextStep));
    all('[data-step]').forEach((node) => { node.hidden = Number(node.dataset.step) !== step; });
    all('[data-step-indicator]').forEach((node) => { if (Number(node.dataset.stepIndicator) === step) node.setAttribute('aria-current', 'step'); else node.removeAttribute('aria-current'); });
    const labels = ['발행본', '대상', '일정', '확인'];
    text('[data-role="step-label"]', `${step} / 4`); text('[data-role="step-title"]', labels[step - 1]);
    const previous = find('[data-action="previous"]'); const next = find('[data-action="next"]'); const create = find('[data-action="create"]');
    if (previous) previous.hidden = step === 1; if (next) next.hidden = step === 4; if (create) create.hidden = step !== 4;
    if (step === 4) fillReview();
  };
  const wizardNotice = (message = '') => { const node = find('[data-role="wizard-notice"]'); if (!node) return; node.textContent = message; node.hidden = message === ''; };
  const validateStep = () => {
    if (step === 1 && selected('publication') < 0) return '활동지를 선택해 주세요.';
    if (step === 2 && selected('context') < 0) return '배포 대상을 선택해 주세요.';
    if (step === 3) {
      const due = find('[data-field="due-at"]')?.value || ''; const closes = find('[data-field="closes-at"]')?.value || ''; const opens = find('[data-field="opens-at"]')?.value || '';
      if (!due || !closes) return '제출 마감과 종료 시각을 입력해 주세요.';
      if ((opens && opens > due) || due > closes) return '공개, 마감, 종료 순서를 확인해 주세요.';
    }
    return '';
  };
  const fillReview = () => {
    const publication = publications[selected('publication')]; const context = contexts[selected('context')];
    text('[data-review="publication"]', publication?.title || '-'); text('[data-review="context"]', context ? `${context.label} (${context.type === 'group' ? '그룹(반)' : '수업'})` : '-');
    text('[data-review="opens"]', find('[data-field="opens-at"]')?.value ? new Date(find('[data-field="opens-at"]').value).toLocaleString() : '수동 공개');
    text('[data-review="due"]', find('[data-field="due-at"]')?.value ? new Date(find('[data-field="due-at"]').value).toLocaleString() : '-');
  };
  const openWizard = (trigger) => {
    wizardTrigger = trigger || null;
    const filterIndex = selected('context-filter'); if (filterIndex >= 0) targetPicker?.setSelected(filterIndex);
    const wizard = find('[data-role="wizard"]'); if (wizard) wizard.hidden = false; wizardNotice(); showStep(1);
    find('[data-field="publication"]')?.focus();
  };
  const closeWizard = () => {
    const wizard = find('[data-role="wizard"]'); if (wizard) wizard.hidden = true;
    wizardTrigger?.focus(); wizardTrigger = null;
  };
  const createAssignment = async () => {
    const publication = publications[selected('publication')]; const context = contexts[selected('context')];
    if (!publication || !context) return;
    const create = find('[data-action="create"]'); if (create) create.disabled = true; wizardNotice('배포를 만들고 있습니다.');
    const token = commandToken();
    const payload = {
      command_id: token,
      expected: { expected_context_revision: context.revision, expected_publication_hash: publication.latest_publication_hash },
      payload: {
        worksheet_publication_id: publication.latest_publication_id,
        context: { type: context.type, id: context.id },
        schedule: {
          opens_at_utc: localToUtc(find('[data-field="opens-at"]')?.value || ''),
          due_at_utc: localToUtc(find('[data-field="due-at"]')?.value || ''),
          closes_at_utc: localToUtc(find('[data-field="closes-at"]')?.value || ''),
          auto_submit_enabled: Boolean(find('[data-field="auto-submit"]')?.checked),
        },
        resubmission_policy: {
          max_attempts: Number(find('[data-field="max-attempts"]')?.value || 2),
          reopen_until_utc: null,
          copy_previous_answers: Boolean(find('[data-field="copy-answers"]')?.checked),
        },
      },
    };
    try {
      await request('/assignments', { method: 'POST', headers: { 'Idempotency-Key': token }, body: JSON.stringify(payload) });
      closeWizard(); filterPicker?.setSelected(contexts.indexOf(context)); await loadAssignments();
    } catch (error) { wizardNotice(error.message); } finally { if (create) create.disabled = false; }
  };
  const lifecycleNotice = (message = '') => { const node = find('[data-role="lifecycle-notice"]'); if (!node) return; node.textContent = message; node.hidden = message === ''; };
  const renderDetail = (assignment, targets) => {
    activeAssignment = assignment;
    submissionAssignment = null;
    window.OJAIWorkflow.setSelection({ worksheetId: assignment.worksheet_id || requestedWorksheetId, assignmentId: assignment.id, publicationId: assignment.worksheet_publication_id, publicationHash: assignment.publication_hash, assignmentTitle: assignment.title, context: contexts[selected('context-filter')] });
    text('[data-detail="state"]', stateLabel(assignment.state)); text('[data-detail="title"]', assignment.title);
    const badge = find('[data-detail="state"]'); if (badge) badge.dataset.state = assignment.state;
    text('[data-detail="context"]', contexts[selected('context-filter')]?.label || '');
    text('[data-detail="target-count"]', `${assignment.target_count}명`);
    text('[data-detail="progress"]', `${assignment.progress?.submitted || 0} / ${assignment.target_count}`);
    text('[data-detail="missing"]', `${Math.max(0, Number(assignment.target_count || 0) - Number(assignment.progress?.submitted || 0))}명`);
    text('[data-detail="roster-count"]', targets.length === Number(assignment.target_count) ? `${targets.length}명` : `${targets.length} / ${assignment.target_count}명`);
    text('[data-detail="opens"]', formatDate(assignment.schedule?.opens_at_utc));
    text('[data-detail="due"]', formatDate(assignment.schedule?.due_at_utc));
    text('[data-detail="closes"]', formatDate(assignment.schedule?.closes_at_utc));
    const addTargets = find('[data-action="add-targets"]');
    const closed = !['draft', 'scheduled', 'open'].includes(assignment.state);
    const expired = [assignment.schedule?.due_at_utc, assignment.schedule?.closes_at_utc].some(value => value && new Date(value.replace(' ', 'T') + (value.includes('Z') ? '' : 'Z')).getTime() <= Date.now());
    const unavailable = closed ? `배포 ${stateLabel(assignment.state)} 상태입니다.` : expired ? '학생 추가 기한이 지났습니다.' : '';
    if (addTargets) { addTargets.disabled = closed || expired; addTargets.title = unavailable || '학생 추가'; }
    const availability = find('[data-role="target-availability"]'); if (availability) { availability.textContent = unavailable; availability.hidden = !unavailable; }
    const mainActions = find('[data-role="submission-entry"]') || find('[data-role="detail-actions"]');
    if (mainActions) {
      mainActions.replaceChildren();
      if (canManageSubmissions && assignment.state !== 'withdrawn') {
        const submissions = create('button', 'oj-ai-assignment__primary', '제출 현황');
        submissions.prepend(icon('list'));
        submissions.type = 'button'; submissions.dataset.action = 'view-submissions'; submissions.dataset.assignmentId = assignment.id;
        mainActions.append(submissions);
      }
    }
    const scheduleActions = find('[data-role="schedule-actions"]'); const stateActions = find('[data-role="state-actions"]');
    scheduleActions?.replaceChildren(); stateActions?.replaceChildren();
    (assignment.actions || []).forEach(key => {
      const action = key.replace('assignment.', '');
      const parent = action === 'update_schedule' ? scheduleActions : stateActions;
      (parent || mainActions)?.append(commandButton(action, assignment.id));
    });
    const stateSection = find('[data-role="state-section"]'); if (stateSection) stateSection.hidden = !(assignment.actions || []).some(key => key !== 'assignment.update_schedule');
    all('a[data-action="open-assessments"]').forEach(link => { link.hidden = assignment.state === 'withdrawn'; });
    syncAssessmentLinks();
    const list = find('[data-role="target-list"]'); if (list) {
      list.replaceChildren(); targets.forEach((target) => { const item = document.createElement('li'); item.textContent = target.display_name; list.append(item); });
    }
    showAssignmentView('detail');
  };
  const loadDetail = async (assignment, trigger = null) => {
    const context = contexts[selected('context-filter')]; if (!assignment || !context) return;
    const requestId = ++assignmentDetailRequest;
    if (trigger) { detailTrigger = trigger; overviewScroll = window.scrollY; }
    text('[data-role="notice"]', '배포 상세를 불러오는 중입니다.');
    try {
      const body = await request(`/assignments/${encodeURIComponent(assignment.id)}?${contextQuery(context)}`);
      if (requestId !== assignmentDetailRequest || contexts[selected('context-filter')] !== context) return;
      renderDetail(body.data?.assignment || assignment, body.data?.targets || []); text('[data-role="notice"]', '');
      if (trigger) { find('[data-role="assignment-detail"]')?.scrollIntoView({ block: 'start' }); find('[data-detail="title"]')?.focus({ preventScroll: true }); }
    } catch (error) { if (requestId === assignmentDetailRequest && contexts[selected('context-filter')] === context) text('[data-role="notice"]', error.message); }
  };

  const targetSelectionChanged = () => {
    if (!targetAddition) return;
    const boxes = all('[data-field="target-learner"]');
    const checked = boxes.filter(box => box.checked);
    text('[data-role="target-selected"]', `${checked.length}명 선택`);
    const selectAll = find('[data-field="target-all"]');
    if (selectAll) {
      selectAll.disabled = targetAddition.busy || boxes.length === 0;
      selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
      selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }
    boxes.forEach(box => { box.disabled = targetAddition.busy; });
    const confirm = find('[data-action="confirm-targets"]');
    if (confirm) confirm.disabled = targetAddition.busy || !targetAddition.expected || checked.length === 0;
    for (const action of ['refresh-targets', 'cancel-targets']) {
      const button = find(`[data-action="${action}"]`); if (button) button.disabled = action === 'cancel-targets' ? Boolean(targetAddition.writing) : targetAddition.busy;
    }
  };
  const closeTargetAddition = () => {
    if (targetAddition?.writing) return;
    const trigger = targetAddition?.trigger;
    targetAddition = null;
    find('[data-role="target-dialog"]')?.close();
    trigger?.focus();
  };
  const refreshTargetCandidates = async () => {
    const operation = targetAddition;
    if (!operation || operation.busy) return;
    operation.busy = true; operation.expected = null; operation.pending = null;
    find('[data-role="target-candidates"]')?.replaceChildren();
    targetSelectionChanged();
    text('[data-role="target-notice"]', '추가할 학생을 확인하는 중입니다.');
    try {
      const body = await request(`/assignments/${encodeURIComponent(operation.assignment.id)}/target-candidates?${contextScopeQuery(operation.context)}`);
      if (targetAddition !== operation || contexts[selected('context-filter')] !== operation.context) return;
      operation.expected = body.data?.expected || null;
      const list = find('[data-role="target-candidates"]');
      const items = body.data?.items || [];
      items.forEach(item => {
        const label = create('label', 'oj-ai-assignment__check');
        const checkbox = create('input'); checkbox.type = 'checkbox'; checkbox.dataset.field = 'target-learner'; checkbox.value = String(item.learner_user_id);
        label.append(checkbox, create('span', '', item.display_name)); list?.append(label);
      });
      text('[data-role="target-notice"]', items.length ? `추가 가능 ${items.length}명` : '추가할 학생이 없습니다.');
    } catch (error) {
      if (targetAddition === operation) text('[data-role="target-notice"]', error.message);
    } finally {
      operation.busy = false;
      if (targetAddition === operation) targetSelectionChanged();
    }
  };
  const openTargetAddition = (trigger) => {
    const context = contexts[selected('context-filter')];
    const dialog = find('[data-role="target-dialog"]');
    if (!context || !activeAssignment || !dialog || targetAddition) return;
    targetAddition = { context, assignment: activeAssignment, trigger, busy: false, expected: null, pending: null };
    text('[data-role="target-assignment"]', activeAssignment.title);
    dialog.showModal();
    refreshTargetCandidates();
  };
  const confirmTargetAddition = async () => {
    const operation = targetAddition;
    if (!operation || operation.busy || !operation.expected || contexts[selected('context-filter')] !== operation.context) return;
    const ids = all('[data-field="target-learner"]').filter(box => box.checked).map(box => Number(box.value)).sort((a, b) => a - b);
    if (ids.length === 0) return;
    const body = JSON.stringify({ expected: operation.expected, learner_user_ids: ids });
    if (!operation.pending || operation.pending.body !== body) operation.pending = { body, token: commandToken() };
    operation.busy = true; operation.writing = true; targetSelectionChanged();
    text('[data-role="target-notice"]', '학생을 추가하는 중입니다.');
    try {
      const result = await request(`/assignments/${encodeURIComponent(operation.assignment.id)}/commands/add-targets?${contextScopeQuery(operation.context)}`, {
        method: 'POST', headers: { 'Idempotency-Key': operation.pending.token }, body: operation.pending.body,
      });
      if (targetAddition !== operation) return;
      operation.busy = false; operation.writing = false; closeTargetAddition();
      if (contexts[selected('context-filter')] !== operation.context) return;
      await loadAssignments();
      await loadDetail(assignmentById(operation.assignment.id) || operation.assignment);
      text('[data-role="notice"]', `${result.data?.added_count || ids.length}명을 추가했습니다.`);
    } catch (error) {
      if (targetAddition === operation) {
        if (error.status === 409 || error.status === 403 || error.status === 404) operation.expected = null;
        text('[data-role="target-notice"]', error.message);
      }
    } finally {
      operation.busy = false; operation.writing = false;
      if (targetAddition === operation) targetSelectionChanged();
    }
  };

  const submissionContextQuery = (context, cursor = '') => {
    const params = new URLSearchParams({ context_type: context.type, context_id: context.id, context_revision: context.revision, limit: '100' });
    if (cursor) params.set('cursor', cursor);
    return params.toString();
  };
  const submissionNotice = (message = '') => text('[data-role="submission-notice"]', message);
  const renderSubmissionRows = (items) => {
    const rows = find('[data-role="submission-rows"]'); if (!rows) return;
    rows.replaceChildren();
    items.forEach((item) => {
      const row = document.createElement('tr');
      row.append(learnerLabels.bind(create('td'), item.target));
      row.append(create('td', '', submissionStateLabel(item.status)));
      row.append(create('td', '', item.submission ? `${Number(item.submission.official_attempt_no || 0)}회` : '-'));
      row.append(create('td', '', item.submission?.submitted_at_utc ? formatDate(item.submission.submitted_at_utc) : '-'));
      const action = create('td', 'oj-ai-assignment__row-actions');
      if (item.submission?.id) {
        const button = create('button', 'oj-ai-assignment__table-link', '보기');
        button.type = 'button'; button.dataset.action = 'view-submission'; button.dataset.submissionId = item.submission.id;
        action.append(button);
      } else action.append(create('span', '', '-'));
      row.append(action); rows.append(row);
    });
  };
  const loadSubmissions = async (trigger = null) => {
    const context = contexts[selected('context-filter')];
    const assignment = activeAssignment || assignmentById(trigger?.dataset.assignmentId || '');
    if (!canManageSubmissions || !context || !assignment) return;
    const requestId = ++submissionListRequest;
    const isCurrent = () => root.isConnected && requestId === submissionListRequest && contexts[selected('context-filter')] === context && activeAssignment?.id === assignment.id;
    submissionAssignment = assignment;
    find('[data-role="submission-bundle"]')?.replaceChildren();
    clearAttachmentPreviews();
    renderSubmissionRows([]);
    syncAssessmentLinks();
    if (trigger) submissionPanelTrigger = trigger;
    const panel = find('[data-role="submission-panel"]'); const detail = find('[data-role="assignment-detail"]');
    if (panel) panel.hidden = false; if (detail) detail.hidden = true;
    showAssignmentView('submissions');
    text('[data-role="submission-title"]', assignment.title || '제출 현황');
    text('[data-role="submission-context"]', `${context.label} · 제출 현황`);
    const list = find('[data-role="submission-list"]'); const submissionDetail = find('[data-role="submission-detail"]');
    if (list) list.hidden = false; if (submissionDetail) submissionDetail.hidden = true;
    submissionNotice('제출 현황을 불러오는 중입니다.');
    try {
      const items = []; let cursor = ''; let pages = 0; let firstAssignment = null;
      do {
        const body = await request(`/assignments/${encodeURIComponent(assignment.id)}/submissions?${submissionContextQuery(context, cursor)}`);
        if (!isCurrent()) return;
        firstAssignment = firstAssignment || body.data?.assignment || null;
        items.push(...(body.data?.items || []));
        cursor = String(body.meta?.next_cursor || ''); pages += 1;
      } while (cursor && pages < 100);
      if (cursor) throw new Error('제출 목록을 모두 불러오지 못했습니다.');
      submissionItems = items; submissionAssignment = firstAssignment || assignment;
      window.OJAIWorkflow.setSelection({ worksheetId: assignment.worksheet_id || requestedWorksheetId, assignmentId: assignment.id, publicationId: assignment.worksheet_publication_id, publicationHash: assignment.publication_hash, assignmentTitle: assignment.title, context });
      syncAssessmentLinks();
      text('[data-role="submission-title"]', submissionAssignment.title || assignment.title);
      const submitted = items.filter(item => item.submission?.official_revision_id).length;
      window.OJAISubmissionDocument?.bundle(root, find('[data-role="submission-bundle"]'), assignment, context, submitted);
      renderSubmissionRows(items); submissionNotice(`전체 ${items.length}명 · 제출 ${submitted}명 · 미제출 ${items.length - submitted}명`);
      if (trigger) { panel?.scrollIntoView({ block: 'start' }); find('[data-role="submission-title"]')?.focus({ preventScroll: true }); }
    } catch (error) { if (isCurrent()) submissionNotice(error.message); }
  };

  const assignmentScheduleVersion = (assignment) => Number(assignment?.schedule_version || assignment?.schedule?.version || 0);
  const evaluateAssignmentSubmissions = async (trigger) => {
    const context = contexts[selected('context-filter')];
    const assignment = submissionAssignment || activeAssignment;
    const scheduleVersion = assignmentScheduleVersion(assignment);
    if (!canAssessSubmissions || !context || !assignment || !Number.isInteger(scheduleVersion) || scheduleVersion < 1) {
      submissionNotice('배포 정보를 다시 불러와 주세요.');
      return;
    }
    const sourceCutoffUtc = new Date().toISOString().slice(0, 19).replace('T', ' ');
    let cursor = '';
    let page = 0;
    const counts = { created: 0, existing: 0, skipped: 0, failed: 0 };
    trigger.disabled = true;
    submissionNotice('제출 평가 작업을 만드는 중입니다.');
    try {
      do {
        const commandId = commandToken();
        const envelope = await request(
          '/assignments/' + encodeURIComponent(assignment.id) + '/commands/evaluate-submissions?' + contextScopeQuery(context),
          {
            method: 'POST',
            headers: { 'Idempotency-Key': commandId },
            body: JSON.stringify({
              command_id: commandId,
              expected: {
                expected_state: assignment.state,
                expected_schedule_version: scheduleVersion,
              },
              payload: {
                source_cutoff_utc: sourceCutoffUtc,
                item_cursor: cursor,
                limit: 100,
              },
            }),
          }
        );
        Object.keys(counts).forEach(key => { counts[key] += Number(envelope.meta?.counts?.[key] || 0); });
        cursor = String(envelope.meta?.next_item_cursor || '');
        page += 1;
      } while (cursor && page < 100);
      if (cursor) throw new Error('제출 평가 작업을 모두 만들지 못했습니다.');
      await loadSubmissions();
      submissionNotice(`채점 대기 등록 ${counts.created}명 · 기존 작업 ${counts.existing}명 · 제외 ${counts.skipped}명 · 등록 실패 ${counts.failed}명`);
    } catch (error) {
      submissionNotice(error.message || '제출 평가를 시작하지 못했습니다.');
    } finally {
      if (trigger.isConnected) trigger.disabled = false;
    }
  };

  const evaluateSubmission = async (trigger) => {
    const context = contexts[selected('context-filter')];
    const submission = activeSubmission;
    const revision = submission?.official_revision;
    if (!canAssessSubmissions || !context || !submission?.id || !revision?.id || !revision?.content_hash) {
      submissionNotice('공식 제출본을 다시 불러와 주세요.');
      return;
    }
    trigger.disabled = true;
    submissionNotice('AI 평가 작업을 만드는 중입니다.');
    try {
      const commandId = commandToken();
      const envelope = await request(
        '/submissions/' + encodeURIComponent(submission.id) + '/commands/evaluate?' + contextScopeQuery(context),
        {
          method: 'POST',
          headers: { 'Idempotency-Key': commandId },
          body: JSON.stringify({
            command_id: commandId,
            expected: {
              expected_official_revision_id: revision.id,
              expected_official_revision_hash: revision.content_hash,
            },
            payload: {},
          }),
        }
      );
      const evaluation = envelope.data?.evaluation || {};
      activeSubmission.evaluation = { ...(activeSubmission.evaluation || {}), id: evaluation.id || '', status: evaluation.state || 'requested' };
      text('[data-submission="evaluation"]', evaluationLabel(activeSubmission.evaluation.status));
      renderSubmissionActions(activeSubmission);
      submissionNotice('AI 평가를 시작했습니다. 평가에서 진행 상태를 확인할 수 있습니다.');
    } catch (error) {
      submissionNotice(error.message || 'AI 평가를 시작하지 못했습니다.');
    } finally {
      if (trigger.isConnected) trigger.disabled = false;
    }
  };

  const historyPages = async (submissionId, context) => {
    const revisions = []; let cursor = ''; let pages = 0;
    do {
      const body = await request(`/submissions/${encodeURIComponent(submissionId)}/revisions?${submissionContextQuery(context, cursor)}`);
      revisions.push(...(body.data?.revisions || []));
      cursor = String(body.meta?.next_cursor || ''); pages += 1;
    } while (cursor && pages < 100);
    if (cursor) throw new Error('변경 기록을 모두 불러오지 못했습니다.');
    return revisions;
  };
  const answerText = (value, attachments) => {
    if (value === null || value === undefined || value === '') return '미작성';
    if (typeof value === 'boolean') return value ? '선택함' : '선택하지 않음';
    if (typeof value === 'string') return attachments.get(value) ? `첨부: ${attachments.get(value)}` : value;
    if (Array.isArray(value)) return value.length ? value.map((item) => answerText(item, attachments)).join(', ') : '미작성';
    if (typeof value === 'object') {
      const values = Object.values(value).map((item) => answerText(item, attachments)).filter(Boolean);
      return values.length ? values.join(' · ') : '미작성';
    }
    return String(value);
  };
  const clearAttachmentPreviews = () => {
    attachmentUrls.forEach(url => URL.revokeObjectURL(url));
    attachmentUrls.clear();
  };
  const attachmentReferences = (value, result = new Set()) => {
    if (typeof value === 'string') result.add(value);
    else if (value && typeof value === 'object') Object.values(value).forEach(item => attachmentReferences(item, result));
    return result;
  };
  const renderAnswerAttachments = (container, value, submission) => {
    const ids = attachmentReferences(value);
    const context = contexts[selected('context-filter')];
    if (!context || !submission.official_revision) return;
    (submission.attachments || []).filter(item => ids.has(item.id) && item.state === 'bound' && item.revision_scope?.includes('official')).forEach(item => {
      const imageType = ['image/png', 'image/jpeg', 'image/webp'].includes(item.mime_type);
      const line = create('div', 'oj-ai-assignment__attachment');
      const button = create('button', '', imageType ? '그림 보기' : '파일 내려받기');
      button.type = 'button';
      button.setAttribute('aria-label', `${item.name} ${button.textContent}`);
      const status = create('span', 'oj-ai-assignment__action-status');
      status.setAttribute('role', 'status');
      line.append(button, status); container.append(line);
      button.addEventListener('click', async () => {
        if (button.disabled) return;
        button.disabled = true; status.textContent = '불러오는 중';
        try {
          const response = await fetch(`${restRoot}/submissions/${encodeURIComponent(submission.id)}/attachments/${encodeURIComponent(item.id)}/content?${contextScopeQuery(context)}`, {
            credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': nonce, Accept: 'application/octet-stream' },
          });
          if (!response.ok) throw new Error('첨부파일을 열 수 없습니다. 제출 내용을 새로 불러와 주세요.');
          const blob = await response.blob();
          if (!container.isConnected || !root.isConnected || activeSubmission !== submission || contexts[selected('context-filter')] !== context) return;
          if (blob.size !== Number(item.byte_size) || response.headers.get('X-OJ-AI-File-Type') !== item.mime_type) throw new Error('첨부파일을 확인하지 못했습니다.');
          const url = URL.createObjectURL(new Blob([blob], { type: imageType ? item.mime_type : 'application/octet-stream' }));
          attachmentUrls.add(url);
          if (imageType) {
            const preview = create('img', 'oj-ai-assignment__attachment-preview');
            preview.alt = String(item.name || '제출 그림'); preview.src = url;
            try { await preview.decode(); }
            catch (error) { URL.revokeObjectURL(url); attachmentUrls.delete(url); throw new Error('그림을 표시하지 못했습니다.'); }
            if (!container.isConnected || !root.isConnected || activeSubmission !== submission || contexts[selected('context-filter')] !== context) {
              URL.revokeObjectURL(url); attachmentUrls.delete(url); return;
            }
            container.append(preview); button.hidden = true;
          } else {
            const link = create('a'); link.href = url; link.download = String(item.name || 'attachment'); link.hidden = true;
            document.body.append(link); link.click(); link.remove();
            window.setTimeout(() => { URL.revokeObjectURL(url); attachmentUrls.delete(url); }, 1000);
          }
          status.textContent = imageType ? '' : '내려받았습니다.';
        } catch (error) { if (container.isConnected) status.textContent = error.message || '첨부파일을 열지 못했습니다.'; }
        finally { button.disabled = false; }
      });
    });
  };
  const submissionPdfKey = (submission) => String(submission?.official_revision?.id || '');
  const submissionPdfBody = (submission, context, commandId) => {
    const revision = submission?.official_revision;
    const source = submission?.source;
    const frozenContext = submission?.context;
    if (!revision || !source || !frozenContext || !submission.id
      || revision.id !== submission.official_revision_id
      || frozenContext.type !== context?.type || frozenContext.id !== context?.id || frozenContext.revision !== context?.revision) {
      throw new Error('제출 내용을 다시 불러와 주세요.');
    }
    return {
      command_id: commandId,
      expected: {
        expected_revision_id: revision.id,
        expected_revision_hash: revision.content_hash,
        expected_publication_hash: source.publication_hash,
        expected_attachment_set_hash: revision.attachment_set_hash,
        expected_submission_id: submission.id,
        expected_submission_state: submission.state,
        expected_submission_lock_version: submission.lock_version,
        expected_context_revision: frozenContext.revision,
      },
      payload: { profile: 'teacher_submission' },
    };
  };
  const submissionReopenBlockReason = (submission, now = Date.now()) => {
    if (submission.assignment_state === 'closed') return '배포가 종료되어 수정할 수 없습니다. 배포 재개가 필요합니다.';
    if (submission.assignment_state !== 'open') return '진행 중인 배포에서만 제출을 다시 열 수 있습니다.';
    const timestamp = (value) => value ? Date.parse(value.replace(' ', 'T') + (value.includes('Z') ? '' : 'Z')) : null;
    const closes = timestamp(submission.schedule?.closes_at_utc);
    if (closes !== null && now >= closes) return '종료 시각이 지나 수정할 수 없습니다. 배포 일정 변경이 필요합니다.';
    const opens = timestamp(submission.schedule?.opens_at_utc);
    if (opens !== null && now < opens) return '아직 배포 시작 시각이 되지 않았습니다.';
    if (submission.state === 'reopened') return '이미 추가 제출이 허용되어 학생이 답변을 수정할 수 있습니다.';
    if (!['submitted', 'auto_submitted', 'proxy_submitted', 'locked'].includes(submission.state)) return '제출이 완료된 학생만 다시 열 수 있습니다.';
    return '';
  };
  const renderSubmissionActions = (submission) => {
    const actions = find('[data-role="submission-actions"]');
    if (!actions) return;
    actions.replaceChildren();
    const reopenReason = canManageSubmissions ? submissionReopenBlockReason(submission) : '';
    const reopenStatus = find('[data-role="submission-reopen-status"]');
    if (reopenStatus) { reopenStatus.textContent = reopenReason; reopenStatus.hidden = !reopenReason; }
    if (canManageSubmissions) {
      const button = create('button', 'oj-ai-assignment__primary', '추가 제출 1회 허용');
      button.type = 'button'; button.dataset.action = 'submission-control'; button.dataset.command = 'reopen';
      button.disabled = Boolean(reopenReason);
      button.title = reopenReason || '이 학생의 답변 수정 및 추가 제출 1회 허용';
      if (reopenReason) button.setAttribute('aria-describedby', 'oj-ai-submission-reopen-status');
      actions.append(button);
    }
    const revisionId = submissionPdfKey(submission);
    const evaluationStatus = String(submission.evaluation?.status || 'not_evaluated');
    const evaluableState = ['submitted', 'auto_submitted', 'proxy_submitted', 'locked'].includes(submission.state);
    if (canAssessSubmissions && revisionId && evaluableState && ['not_evaluated', 'failed', 'cancelled'].includes(evaluationStatus)) {
      const evaluate = create('button', 'oj-ai-assignment__primary', evaluationStatus === 'not_evaluated' ? 'AI 평가 시작' : 'AI 평가 다시 시작');
      evaluate.type = 'button';
      evaluate.dataset.action = 'evaluate-submission';
      actions.append(evaluate);
    }
    if (revisionId) {
      const viewer = window.OJAISubmissionDocument?.viewButton(root, revisionId, contexts[selected('context-filter')], submission.id);
      if (viewer) actions.append(viewer);
      const pdf = submissionPdfJobs.get(revisionId) || null;
      const button = create('button', '', 'PDF');
      button.type = 'button';
      button.dataset.action = pdf?.artifactId ? 'download-submission-pdf' : 'export-submission-pdf';
      button.disabled = Boolean(pdf?.pending || pdf?.downloading);
      button.title = pdf?.artifactId ? 'PDF 내려받기' : 'PDF 만들기';
      actions.append(button);
      const message = pdf?.message || '';
      if (message) {
        const status = create('span', 'oj-ai-assignment__action-status', message);
        status.setAttribute('role', 'status');
        actions.append(status);
      }
    }
    const canLock = canManageSubmissions && ['open', 'closed'].includes(submission.assignment_state) && ['submitted', 'auto_submitted', 'proxy_submitted', 'reopened'].includes(submission.state);
    if (canLock) {
      const button = create('button', '', '잠금'); button.type = 'button'; button.dataset.action = 'submission-control'; button.dataset.command = 'lock'; actions.append(button);
    }
  };
  const updateSubmissionPdf = (revisionId, value) => {
    submissionPdfJobs.set(revisionId, value);
    if (submissionPdfKey(activeSubmission) === revisionId) renderSubmissionActions(activeSubmission);
  };
  const pause = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));
  const pollSubmissionPdf = async (revisionId, jobId) => {
    for (let attempt = 0; attempt < 200; attempt += 1) {
      if (attempt > 0) await pause(1500);
      const body = await request(`/export-jobs/${encodeURIComponent(jobId)}`);
      const job = body.data?.export_job;
      if (!job || job.job_id !== jobId || job.artifact_kind !== 'submission_pdf' || job.source_id !== revisionId) {
        throw new Error('PDF 상태를 확인하지 못했습니다.');
      }
      if (job.state === 'ready' && job.artifact_id) {
        updateSubmissionPdf(revisionId, { jobId, artifactId: job.artifact_id, pending: false, downloading: false, message: 'PDF 준비됨' });
        return job.artifact_id;
      }
      if (['failed', 'cancelled', 'expired'].includes(job.state)) throw new Error('PDF 생성 실패');
      const current = submissionPdfJobs.get(revisionId);
      if (!current || current.message !== 'PDF 준비 중') {
        updateSubmissionPdf(revisionId, { jobId, artifactId: '', pending: true, downloading: false, message: 'PDF 준비 중' });
      }
    }
    throw new Error('PDF 생성 실패');
  };
  const downloadName = (response, submission) => {
    const disposition = response.headers.get('Content-Disposition') || '';
    const matched = disposition.match(/filename\*=UTF-8''([^;]+)/i);
    if (matched) {
      try { return decodeURIComponent(matched[1]); } catch (error) { /* Use the safe local name. */ }
    }
    return `submission-${String(submission?.id || 'result').toLowerCase()}.pdf`;
  };
  const downloadSubmissionPdf = async (artifactId, submission = activeSubmission) => {
    const revisionId = submissionPdfKey(submission);
    if (!revisionId || !artifactId) return;
    const current = submissionPdfJobs.get(revisionId) || {};
    updateSubmissionPdf(revisionId, { ...current, artifactId, pending: false, downloading: true, message: 'PDF 내려받는 중' });
    try {
      const response = await fetch(`${restRoot}/artifacts/${encodeURIComponent(artifactId)}/download`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf', 'X-WP-Nonce': nonce },
      });
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}));
        throw new Error(problem.message || 'PDF 내려받기 실패');
      }
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url; link.download = downloadName(response, submission); link.hidden = true;
      document.body.append(link); link.click(); link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      updateSubmissionPdf(revisionId, { ...current, artifactId, pending: false, downloading: false, message: 'PDF 준비됨' });
    } catch (error) {
      updateSubmissionPdf(revisionId, { ...current, artifactId, pending: false, downloading: false, message: 'PDF 내려받기 실패' });
    }
  };
  const exportSubmissionPdf = async (trigger) => {
    const submission = activeSubmission;
    const context = contexts[selected('context-filter')];
    const revisionId = submissionPdfKey(submission);
    if (!submission || !context || !revisionId) return;
    const existing = submissionPdfJobs.get(revisionId);
    if (existing?.artifactId) { await downloadSubmissionPdf(existing.artifactId, submission); return; }
    if (existing?.pending) return;
    const commandId = commandToken();
    let body;
    try { body = submissionPdfBody(submission, context, commandId); }
    catch (error) { updateSubmissionPdf(revisionId, { pending: false, downloading: false, artifactId: '', message: 'PDF 생성 실패' }); return; }
    updateSubmissionPdf(revisionId, { pending: true, downloading: false, artifactId: '', message: 'PDF 준비 중' });
    if (trigger) trigger.disabled = true;
    try {
      const response = await request(`/submission-revisions/${encodeURIComponent(revisionId)}/commands/export-pdf?${contextScopeQuery(context)}`, {
        method: 'POST', headers: { 'Idempotency-Key': commandId }, body: JSON.stringify(body),
      });
      const job = response.data?.export_job;
      if (!job || !job.id || job.source_id !== revisionId || job.artifact_kind !== 'submission_pdf') throw new Error('PDF 생성 실패');
      updateSubmissionPdf(revisionId, { jobId: job.id, artifactId: '', pending: true, downloading: false, message: 'PDF 준비 중' });
      const artifactId = await pollSubmissionPdf(revisionId, job.id);
      if (submissionPdfKey(activeSubmission) === revisionId) await downloadSubmissionPdf(artifactId, submission);
    } catch (error) {
      updateSubmissionPdf(revisionId, { pending: false, downloading: false, artifactId: '', message: 'PDF 생성 실패' });
    } finally {
      if (trigger && trigger.isConnected) trigger.disabled = false;
    }
  };
  const renderSubmissionDetail = (submission, studentName, revisions) => {
    clearAttachmentPreviews();
    activeSubmission = submission; activeSubmissionName = studentName;
    text('[data-submission="state"]', submissionStateLabel(submission.state));
    text('[data-submission="student"]', studentName);
    learnerLabels.bind(find('[data-submission="student"]'), submission.target || { display_name: studentName });
    text('[data-submission="attempt"]', `${Number(submission.official_attempt_no || 0)}회`);
    text('[data-submission="submitted"]', submission.submitted_at_utc ? formatDate(submission.submitted_at_utc) : '-');
    text('[data-submission="evaluation"]', evaluationLabel(submission.evaluation?.status));
    text('[data-submission="release"]', releaseLabel(submission.release?.status));

    const answers = find('[data-role="submission-answers"]');
    if (answers) {
      answers.replaceChildren();
      const attachments = new Map((submission.attachments || []).map((item) => [String(item.id || ''), String(item.name || '첨부 파일')]));
      const values = submission.official_revision?.response?.answers || submission.current_revision?.response?.answers || {};
      const entries = Object.values(values);
      if (!entries.length) answers.append(create('dt', '', '-'), create('dd', '', '작성 내용이 없습니다.'));
      entries.forEach((value, index) => {
        const answer = create('dd', '', answerText(value, attachments));
        renderAnswerAttachments(answer, value, submission);
        answers.append(create('dt', '', `문항 ${index + 1}`), answer);
      });
    }

    renderSubmissionActions(submission);

    const history = find('[data-role="submission-history"]');
    if (history) {
      history.replaceChildren();
      revisions.forEach((revision) => {
        const item = create('li', 'oj-ai-assignment__history-item');
        const heading = create('div', 'oj-ai-assignment__history-heading');
        heading.append(create('strong', '', `버전 ${revision.revision_no}`), create('time', '', formatDate(revision.created_at_utc)));
        const flags = [];
        if (revision.is_official) flags.push('제출본'); if (revision.is_current) flags.push('현재');
        if (flags.length) heading.append(create('span', '', flags.join(' · ')));
        item.append(heading);
        (revision.receipts || []).forEach((receipt) => {
          const evidence = create('div', 'oj-ai-assignment__history-receipt');
          evidence.append(create('strong', '', receiptActionLabel(receipt.action)), create('span', '', formatDate(receipt.received_at_utc)));
          if (receipt.reason) evidence.append(create('p', '', String(receipt.reason)));
          item.append(evidence);
        });
        history.append(item);
      });
      if (!revisions.length) history.append(create('li', 'oj-ai-assignment__history-item', '변경 기록이 없습니다.'));
    }
    const list = find('[data-role="submission-list"]'); const detail = find('[data-role="submission-detail"]');
    if (list) list.hidden = true; if (detail) detail.hidden = false;
    detail?.scrollIntoView({ block: 'nearest' });
  };
  const loadSubmissionDetail = async (item, trigger = null) => {
    const context = contexts[selected('context-filter')]; const submissionId = item?.submission?.id;
    if (!context || !submissionId) return;
    submissionNotice('제출 내용을 불러오는 중입니다.');
    try {
      const [detailBody, revisions] = await Promise.all([
        request(`/submissions/${encodeURIComponent(submissionId)}?${contextScopeQuery(context)}`),
        historyPages(submissionId, context),
      ]);
      renderSubmissionDetail(detailBody.data?.submission || {}, learnerLabels.label(item.target), revisions);
      submissionNotice('');
    } catch (error) { submissionNotice(error.message); trigger?.focus(); }
  };
  const closeSubmissions = (restoreFocus = true) => {
    submissionListRequest += 1;
    clearAttachmentPreviews();
    const panel = find('[data-role="submission-panel"]'); const detail = find('[data-role="assignment-detail"]');
    if (panel) panel.hidden = true; if (detail && activeAssignment) detail.hidden = false;
    showAssignmentView(activeAssignment ? 'detail' : 'overview');
    activeSubmission = null; activeSubmissionName = ''; submissionItems = []; submissionAssignment = null;
    if (restoreFocus) submissionPanelTrigger?.focus(); submissionPanelTrigger = null;
  };
  const backToSubmissions = () => {
    clearAttachmentPreviews();
    const list = find('[data-role="submission-list"]'); const detail = find('[data-role="submission-detail"]');
    if (list) list.hidden = false; if (detail) detail.hidden = true; activeSubmission = null; activeSubmissionName = '';
  };
  const submissionControlNotice = (message = '') => { const node = find('[data-role="submission-control-notice"]'); if (!node) return; node.textContent = message; node.hidden = message === ''; };
  const openSubmissionControl = (action, trigger) => {
    if (!canManageSubmissions || !activeSubmission || !['reopen', 'lock'].includes(action)) return;
    if (action === 'reopen' && submissionReopenBlockReason(activeSubmission)) { renderSubmissionActions(activeSubmission); return; }
    submissionControlAction = action; submissionControlTrigger = trigger;
    learnerLabels.bind(find('[data-role="submission-control-label"]'), activeSubmission.target || { display_name: activeSubmissionName });
    text('[data-role="submission-control-title"]', action === 'reopen' ? '추가 제출 1회 허용' : '제출 잠금');
    const summary = find('[data-role="submission-control-summary"]');
    if (summary) {
      summary.hidden = action !== 'reopen';
      summary.textContent = action === 'reopen' ? `현재 제출 ${Number(activeSubmission.official_attempt_no || 0)}회 · 추가 제출 1회 허용` : '';
    }
    const reason = find('[data-field="submission-control-reason"]'); if (reason) reason.value = '';
    submissionControlNotice(); const dialog = find('[data-role="submission-control-dialog"]');
    if (dialog) { dialog.hidden = false; if (!dialog.open) dialog.showModal?.(); } reason?.focus();
  };
  const closeSubmissionControl = (force = false) => {
    if (submissionControlBusy && !force) return;
    const dialog = find('[data-role="submission-control-dialog"]'); if (dialog) { if (dialog.open) dialog.close(); dialog.hidden = true; }
    submissionControlNotice(); submissionControlAction = ''; submissionControlTrigger?.focus(); submissionControlTrigger = null;
  };
  const executeSubmissionControl = async () => {
    if (submissionControlBusy) return;
    const context = contexts[selected('context-filter')]; const submission = activeSubmission;
    const reason = String(find('[data-field="submission-control-reason"]')?.value || '').trim();
    if (!context || !submission || !submissionControlAction) return;
    if (!reason) { submissionControlNotice('사유를 입력해 주세요.'); return; }
    const token = commandToken(); const action = submissionControlAction; const submissionId = submission.id;
    const body = {
      command_id: token,
      expected: {
        expected_state: submission.state,
        expected_lock_version: submission.lock_version,
        expected_official_revision_id: submission.official_revision_id,
        expected_official_attempt_no: submission.official_attempt_no,
        expected_schedule_version: submission.schedule?.version,
        expected_schedule_hash: submission.schedule?.hash,
        expected_publication_hash: submission.source?.publication_hash,
      },
      payload: { reason },
    };
    const confirm = find('[data-action="confirm-submission-control"]'); if (confirm) confirm.disabled = true;
    submissionControlBusy = true; dialogBusy('[data-role="submission-control-dialog"]', true);
    submissionControlNotice('처리 중입니다.');
    try {
      await request(`/submissions/${encodeURIComponent(submissionId)}/commands/${action}?${contextScopeQuery(context)}`, {
        method: 'POST', headers: { 'Idempotency-Key': token }, body: JSON.stringify(body),
      });
      closeSubmissionControl(true); await loadSubmissions();
      const refreshed = submissionItems.find((item) => item.submission?.id === submissionId);
      if (refreshed) await loadSubmissionDetail(refreshed);
    } catch (error) { submissionControlNotice(error.message); }
    finally { submissionControlBusy = false; dialogBusy('[data-role="submission-control-dialog"]', false); if (confirm) confirm.disabled = false; }
  };
  const closeDetail = (restoreFocus = true) => {
    ++assignmentDetailRequest; ++submissionListRequest;
    const id = activeAssignment?.id;
    activeAssignment = null; submissionAssignment = null;
    showAssignmentView('overview');
    if (restoreFocus) {
      const trigger = detailTrigger?.isConnected ? detailTrigger : all('[data-action="view-assignment"]').find(button => button.dataset.assignmentId === id);
      trigger?.focus({ preventScroll: true }); window.scrollTo({ top: overviewScroll });
    }
    detailTrigger = null;
  };
  const openLifecycle = (action, assignment, trigger) => {
    lifecycleAction = action; activeAssignment = assignment; lifecycleTrigger = trigger;
    text('[data-role="lifecycle-title"]', action === 'reopen' ? '배포 재개' : '일정 변경'); lifecycleNotice();
    text('[data-role="lifecycle-assignment"]', `${contexts[selected('context-filter')]?.label || ''} · ${assignment.title}`);
    text('[data-action="save-lifecycle"]', action === 'reopen' ? '배포 재개' : '변경 저장');
    const schedule = assignment.schedule || {};
    const opens = find('[data-field="lifecycle-opens"]'); const due = find('[data-field="lifecycle-due"]'); const closes = find('[data-field="lifecycle-closes"]'); const auto = find('[data-field="lifecycle-auto-submit"]');
    if (opens) opens.value = action === 'reopen' ? '' : utcToLocal(schedule.opens_at_utc);
    if (due) due.value = utcToLocal(schedule.due_at_utc); if (closes) closes.value = utcToLocal(schedule.closes_at_utc); if (auto) auto.checked = Boolean(schedule.auto_submit_enabled);
    const dialog = find('[data-role="lifecycle-dialog"]'); if (dialog) { dialog.hidden = false; if (!dialog.open) dialog.showModal?.(); } due?.focus();
  };
  const closeLifecycle = (force = false) => {
    if (lifecycleBusy && !force) return;
    const dialog = find('[data-role="lifecycle-dialog"]'); if (dialog) { if (dialog.open) dialog.close(); dialog.hidden = true; }
    lifecycleNotice(); lifecycleAction = ''; lifecycleTrigger?.focus(); lifecycleTrigger = null;
  };
  const lifecycleBody = (assignment, action, schedule = null) => ({
    command_id: commandToken(),
    expected: {
      expected_state: assignment.state,
      expected_lock_version: assignment.lock_version,
      expected_schedule_version: assignment.schedule_version,
      expected_schedule_hash: assignment.schedule_hash,
    },
    payload: schedule ? { schedule } : {},
  });
  const executeLifecycle = async (action, assignment, trigger, schedule = null) => {
    if (lifecycleBusy) return;
    const context = contexts[selected('context-filter')]; if (!context || !assignment) return;
    const body = lifecycleBody(assignment, action, schedule); const token = body.command_id;
    if (trigger) trigger.disabled = true;
    lifecycleBusy = true; dialogBusy('[data-role="lifecycle-dialog"]', true);
    const commandButtons = all('[data-action="assignment-command"]'); commandButtons.forEach(button => { button.disabled = true; });
    try {
      await request(`/assignments/${encodeURIComponent(assignment.id)}/commands/${actionPath(action)}?${contextScopeQuery(context)}`, {
        method: 'POST', headers: { 'Idempotency-Key': token }, body: JSON.stringify(body),
      });
      closeLifecycle(true); await loadAssignments();
      const refreshed = assignmentById(assignment.id); if (refreshed && find('[data-role="assignment-detail"]') && !find('[data-role="assignment-detail"]').hidden) await loadDetail(refreshed);
      text('[data-role="notice"]', action === 'update_schedule' ? '배포 일정을 변경했습니다.' : `배포 ${actionLabel(action)} 처리가 완료되었습니다.`);
      find('[data-detail="title"]')?.focus({ preventScroll: true });
    } catch (error) {
      if (find('[data-role="lifecycle-dialog"]') && !find('[data-role="lifecycle-dialog"]').hidden) lifecycleNotice(error.message);
      else text('[data-role="notice"]', error.message);
    } finally { lifecycleBusy = false; dialogBusy('[data-role="lifecycle-dialog"]', false); commandButtons.forEach(button => { button.disabled = false; }); if (trigger) trigger.disabled = false; }
  };
  const requestLifecycle = async (action, assignment, trigger) => {
    if (lifecycleBusy || !assignment || !(assignment.actions || []).includes(`assignment.${action}`)) return;
    if (action === 'update_schedule' || action === 'reopen') { openLifecycle(action, assignment, trigger); return; }
    const prompts = { open: '배포를 여시겠습니까?', close: '배포를 종료하시겠습니까?', withdraw: '배포를 철회하시겠습니까?' };
    if (!prompts[action] || lifecycleConfirmation) return;
    const context = contexts[selected('context-filter')];
    const dialog = create('dialog', 'oj-ai-assignment__confirmation');
    const heading = create('h2', '', prompts[action]); heading.id = 'oj-ai-assignment-confirm-title';
    dialog.setAttribute('aria-labelledby', heading.id);
    const cancel = create('button', '', '취소'); cancel.type = 'button'; cancel.autofocus = true;
    const accept = create('button', action === 'withdraw' ? 'oj-ai-assignment__danger' : 'oj-ai-assignment__primary', `배포 ${actionLabel(action)}`); accept.type = 'button';
    const identity = create('p', 'oj-ai-assignment__dialog-context', `${context?.label || ''} · ${assignment.title}`);
    const effect = create('p', '', { open: '학생이 이 활동지에 참여할 수 있게 됩니다.', close: '배포 상태가 종료로 변경됩니다.', withdraw: '학생의 이 배포 접근이 중단됩니다. 기존 제출 기록은 삭제되지 않습니다.' }[action]);
    const footer = create('footer'); footer.append(cancel, accept); dialog.append(heading, identity, effect, footer);
    lifecycleConfirmation = dialog; root.append(dialog);
    const accepted = await new Promise(resolve => {
      let settled = false;
      const finish = value => {
        if (settled) return; settled = true;
        if (dialog.open) dialog.close(); dialog.remove(); lifecycleConfirmation = null;
        if (trigger?.isConnected) trigger.focus(); resolve(value);
      };
      cancel.addEventListener('click', () => finish(false)); accept.addEventListener('click', () => finish(true));
      dialog.addEventListener('cancel', event => { event.preventDefault(); finish(false); });
      dialog.addEventListener('close', () => finish(false));
      try { dialog.showModal(); cancel.focus(); }
      catch (error) { text('[data-role="notice"]', '확인창을 열지 못했습니다. 새로고침 후 다시 시도해 주세요.'); finish(false); }
    });
    if (accepted && root.isConnected && contexts[selected('context-filter')] === context) await executeLifecycle(action, assignment, trigger);
  };
  const saveLifecycle = () => {
    const due = find('[data-field="lifecycle-due"]')?.value || ''; const closes = find('[data-field="lifecycle-closes"]')?.value || ''; const opens = find('[data-field="lifecycle-opens"]')?.value || '';
    if (!due || !closes) { lifecycleNotice('제출 마감과 종료 시각을 입력해 주세요.'); return; }
    if ((opens && opens > due) || due > closes) { lifecycleNotice('공개, 마감, 종료 순서를 확인해 주세요.'); return; }
    const schedule = { opens_at_utc: localToUtc(opens), due_at_utc: localToUtc(due), closes_at_utc: localToUtc(closes), auto_submit_enabled: Boolean(find('[data-field="lifecycle-auto-submit"]')?.checked) };
    executeLifecycle(lifecycleAction, activeAssignment, find('[data-action="save-lifecycle"]'), schedule);
  };

  find('[data-role="target-dialog"]')?.addEventListener('cancel', event => { event.preventDefault(); closeTargetAddition(); });
  find('[data-role="lifecycle-dialog"]')?.addEventListener('cancel', event => { event.preventDefault(); closeLifecycle(); });
  find('[data-role="submission-control-dialog"]')?.addEventListener('cancel', event => { event.preventDefault(); closeSubmissionControl(); });
  root.addEventListener('change', (event) => {
    if (event.target?.matches('[data-field="target-all"]') && targetAddition && !targetAddition.busy) all('[data-field="target-learner"]').forEach(box => { box.checked = event.target.checked; });
    if (event.target?.matches('[data-field="target-all"], [data-field="target-learner"]')) targetSelectionChanged();
    if (event.target?.matches('[data-field="context-filter"]')) { closeTargetAddition(); lifecycleConfirmation?.close(); clearAttachmentPreviews(); closeSubmissions(false); closeDetail(false); loadAssignments(); }
  });
  window.addEventListener('pagehide', clearAttachmentPreviews);
  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-action]'); if (!button) return;
    if (lifecycleBusy) { event.preventDefault(); return; }
    const action = button.dataset.action;
    if (action === 'open-assessments') {
      button.href = assessmentTarget(button.dataset.evaluationId || '');
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || button.target === '_blank') return;
      event.preventDefault();
      const href=assessmentTarget(button.dataset.evaluationId || '');
      if(document.dispatchEvent(new CustomEvent('ojai:before-navigation',{cancelable:true,detail:{href}})))window.location.assign(href);
      return;
    }
    if (action === 'new') openWizard(button);
    if (action === 'add-targets') openTargetAddition(button);
    if (action === 'refresh-targets') refreshTargetCandidates();
    if (action === 'cancel-targets') closeTargetAddition();
    if (action === 'confirm-targets') confirmTargetAddition();
    if (action === 'view-assignment') loadDetail(assignmentById(button.dataset.assignmentId), button);
    if (action === 'close-detail') closeDetail();
    if (action === 'refresh-detail') loadDetail(activeAssignment);
    if (action === 'view-submissions') loadSubmissions(button);
    if (action === 'close-submissions') closeSubmissions();
    if (action === 'refresh-submissions') loadSubmissions();
    if (action === 'back-submissions') backToSubmissions();
    if (action === 'view-submission') loadSubmissionDetail(submissionItems.find((item) => item.submission?.id === button.dataset.submissionId), button);
    if (action === 'export-submission-pdf') exportSubmissionPdf(button);
    if (action === 'download-submission-pdf') downloadSubmissionPdf(submissionPdfJobs.get(submissionPdfKey(activeSubmission))?.artifactId || '', activeSubmission);
    if (action === 'evaluate-assignment-submissions') evaluateAssignmentSubmissions(button);
    if (action === 'evaluate-submission') evaluateSubmission(button);
    if (action === 'submission-control') openSubmissionControl(button.dataset.command, button);
    if (action === 'cancel-submission-control') closeSubmissionControl();
    if (action === 'confirm-submission-control') executeSubmissionControl();
    if (action === 'assignment-command') requestLifecycle(button.dataset.command, assignmentById(button.dataset.assignmentId), button);
    if (action === 'cancel-lifecycle') closeLifecycle();
    if (action === 'save-lifecycle') saveLifecycle();
    if (action === 'cancel') closeWizard();
    if (action === 'previous') { wizardNotice(); showStep(step - 1); }
    if (action === 'next') { const message = validateStep(); wizardNotice(message); if (!message) showStep(step + 1); }
    if (action === 'create') createAssignment();
  });
  root.addEventListener('keydown', (event) => {
    if (lifecycleBusy || submissionControlBusy) { if (event.key === 'Escape') event.preventDefault(); return; }
    if (event.key === 'Escape' && targetAddition) { event.preventDefault(); closeTargetAddition(); return; }
    if (event.key === 'Escape' && lifecycleConfirmation) { event.preventDefault(); lifecycleConfirmation.close(); return; }
    const wizard = find('[data-role="wizard"]');
    const lifecycle = find('[data-role="lifecycle-dialog"]');
    const submissionControl = find('[data-role="submission-control-dialog"]');
    if (event.key === 'Escape' && submissionControl && !submissionControl.hidden) { event.preventDefault(); closeSubmissionControl(); return; }
    if (event.key === 'Escape' && lifecycle && !lifecycle.hidden) { event.preventDefault(); closeLifecycle(); return; }
    if (event.key === 'Escape' && wizard && !wizard.hidden) { event.preventDefault(); closeWizard(); return; }
    if (event.key === 'Escape' && find('[data-role="submission-panel"]') && !find('[data-role="submission-panel"]').hidden) { event.preventDefault(); closeSubmissions(); return; }
    if (event.key === 'Escape' && activeAssignment) { event.preventDefault(); closeDetail(); }
  });

  loadBase();
})();
