(() => {
  'use strict';

  const ASSIGNMENT_STATES = ['scheduled', 'open', 'closed'];
  const SUBMISSION_STATES = ['not_started', 'in_progress', 'official'];
  const VIEWS = ['worksheets', 'assignments', 'students', 'student'];
  const LENSES = ['worksheet', 'group', 'student'];
  const EXPORT_FORMATS = Object.freeze({
    pdf: { route: 'export-pdf', profile: 'report_print.v1', extension: 'pdf', mimeType: 'application/pdf', label: 'PDF' },
    csv: { route: 'export-csv', profile: 'report_csv.v1', extension: 'csv', mimeType: 'text/csv', label: 'CSV' },
    zip: { route: 'export-json-package', profile: 'report_json_package.v1', extension: 'zip', mimeType: 'application/zip', label: 'ZIP' },
  });
  const PAGE_LIMIT = 25;
  const MAX_PAGE_COUNT = 4;
  const HISTORY_KEY = 'ojAiReportDashboardV1';
  const workflowEntry = window.OJAIWorkflow?.read() || {};

  const canonicalStates = (values, allowed) => {
    const selected = new Set(Array.isArray(values) ? values : []);
    const result = allowed.filter((value) => selected.has(value));
    return result.length ? result : [...allowed];
  };

  const validDate = (value) => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return false;
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));
    return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
  };

  const addInclusiveDay = (value) => {
    if (!validDate(value)) throw new Error('날짜를 확인해 주세요.');
    const [year, month, day] = value.split('-').map(Number);
    return new Date(Date.UTC(year, month - 1, day + 1)).toISOString().slice(0, 10);
  };

  const normalizedFilters = (value = {}) => {
    const from = validDate(value.periodFrom) ? value.periodFrom : '';
    const through = validDate(value.periodThrough) ? value.periodThrough : '';
    return {
      periodFrom: from && through ? from : '',
      periodThrough: from && through ? through : '',
      assignmentStates: canonicalStates(value.assignmentStates, ASSIGNMENT_STATES),
      submissionStates: canonicalStates(value.submissionStates, SUBMISSION_STATES),
    };
  };

  const buildReportQuery = (context, filters = {}, cursor = '', limit = PAGE_LIMIT) => {
    if (!context || !context.type || !context.id || !context.revision) throw new Error('대상을 선택해 주세요.');
    const rawFrom = typeof filters.periodFrom === 'string' ? filters.periodFrom : '';
    const rawThrough = typeof filters.periodThrough === 'string' ? filters.periodThrough : '';
    if ((rawFrom || rawThrough) && (!rawFrom || !rawThrough)) {
      throw new Error('기간의 시작과 마지막 날짜를 모두 선택해 주세요.');
    }
    if (rawFrom && (!validDate(rawFrom) || !validDate(rawThrough) || rawFrom > rawThrough)) {
      throw new Error('기간을 확인해 주세요.');
    }
    const applied = normalizedFilters(filters);
    const params = new URLSearchParams({
      context_type: context.type,
      context_id: context.id,
      context_revision: context.revision,
    });
    if (applied.periodFrom) {
      params.set('period_from', applied.periodFrom);
      params.set('period_to', addInclusiveDay(applied.periodThrough));
    }
    if (applied.assignmentStates.length !== ASSIGNMENT_STATES.length) {
      params.set('assignment_states', applied.assignmentStates.join(','));
    }
    if (applied.submissionStates.length !== SUBMISSION_STATES.length) {
      params.set('submission_states', applied.submissionStates.join(','));
    }
    if (cursor) params.set('cursor', cursor);
    params.set('limit', String(limit));
    return params.toString();
  };

  const formatRatio = (metric) => {
    const numerator = Number(metric?.numerator);
    const denominator = Number(metric?.denominator);
    if (!Number.isInteger(numerator) || !Number.isInteger(denominator)) return '-';
    const percentage = typeof metric.value === 'string' ? ` (${metric.value}%)` : '';
    return `${numerator} / ${denominator}${percentage}`;
  };

  const formatAverage = (metric) => {
    const count = Number(metric?.n);
    if (metric?.suppressed === true) return `표시 안 함 (${Number.isInteger(count) ? count : 0}명)`;
    if (typeof metric?.value !== 'string') return '자료 없음';
    return `${metric.value}% (${Number.isInteger(count) ? count : 0}명)`;
  };

  // Unlike formatAverage(), this never reports a small-group suppression: it
  // averages one already-identified student's own scores across worksheets,
  // not scores across several students, so there is no group to protect.
  const formatIndividualAverage = (metric) => {
    const count = Number(metric?.n);
    if (typeof metric?.value !== 'string') return '자료 없음';
    return `${metric.value}% (${Number.isInteger(count) ? count : 0}건)`;
  };

  // Given the assignments-list page fetched for a worksheet (its items and
  // whether another page follows), decide whether that worksheet has
  // exactly one distribution -- if so, the "배포 선택" list screen is a
  // trivial single-choice step and can be skipped straight to that one
  // distribution's student list.
  const soleAssignmentFrom = (items, hasMore) => {
    if (!Array.isArray(items) || items.length !== 1 || hasMore) return null;
    const item = items[0];
    const id = typeof item?.id === 'string' ? item.id : '';
    if (!id) return null;
    return { id, title: typeof item.title === 'string' && item.title ? item.title : '배포' };
  };

  // Breadcrumb step counts for the worksheet/group lens drill-down (0=활동지,
  // 1=배포 목록, 2=학생별, 3=학생). Each step is normally one real
  // history.pushState -- except when a worksheet's sole distribution was
  // resolved and the "배포 목록" screen was skipped, collapsing the 0->2 hop
  // into a single pushState. `skipped` accounts for that so breadcrumb/back
  // navigation (history.go(-steps)) always lands on a real, visited state.
  const worksheetTrailSteps = (depth, skipped) => {
    const normalizedDepth = Math.max(0, Math.min(3, Number(depth) || 0));
    const hop = skipped && normalizedDepth >= 2 ? 1 : 0;
    const worksheetOmitted = skipped && normalizedDepth >= 2;
    return {
      root: Math.max(0, normalizedDepth - hop),
      worksheet: normalizedDepth >= 1 && !worksheetOmitted ? Math.max(0, normalizedDepth - 1) : null,
      assignment: normalizedDepth >= 2 ? Math.max(0, normalizedDepth - 2) : null,
      student: normalizedDepth >= 3 ? 0 : null,
    };
  };

  const adapter = Object.freeze({
    addInclusiveDay,
    buildReportQuery,
    formatAverage,
    formatIndividualAverage,
    formatRatio,
    normalizedFilters,
    soleAssignmentFrom,
    worksheetTrailSteps,
  });
  if (typeof window !== 'undefined') window.OJAIReportDashboard = adapter;

  const root = typeof document !== 'undefined' ? document.getElementById('oj-ai-report-app') : null;
  if (!root) return;

  const restRoot = String(root.dataset.restRoot || '/wp-json/oj-ai/v1').replace(/\/$/, '');
  const nonce = String(root.dataset.restNonce || '');
  const locale = String(root.dataset.locale || 'ko-KR').replace('_', '-');
  const resolveTimeZone = (raw) => {
    const candidate = raw || 'Asia/Seoul';
    try {
      new Intl.DateTimeFormat('en-US', { timeZone: candidate });
      return candidate;
    } catch (_error) {
      const match = candidate.match(/^([+-])(\d{2}):(\d{2})$/);
      if (match && match[3] === '00') {
        const etc = `Etc/GMT${match[1] === '-' ? '+' : '-'}${Number(match[2])}`;
        try {
          new Intl.DateTimeFormat('en-US', { timeZone: etc });
          return etc;
        } catch (_fallbackError) { /* fall through to default */ }
      }
      return 'Asia/Seoul';
    }
  };
  const timeZone = resolveTimeZone(root.dataset.timezone);
  const find = (selector) => root.querySelector(selector);
  const all = (selector) => Array.from(root.querySelectorAll(selector));
  const create = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '') node.textContent = String(text);
    return node;
  };

  class ReportRequestError extends Error {
    constructor(status, code, message) {
      super(message);
      this.name = 'ReportRequestError';
      this.status = status;
      this.code = code;
    }
  }

  const defaultState = () => ({
    marker: HISTORY_KEY,
    view: 'worksheets',
    lens: 'worksheet',
    depth: 0,
    context: null,
    filters: normalizedFilters(),
    worksheet: null,
    assignment: null,
    assignmentSkipped: false,
    studentId: null,
    pageCursors: [''],
    scrollY: 0,
  });

  const safeText = (value, maximum = 240) => typeof value === 'string' ? value.slice(0, maximum) : '';
  const safeReference = (value, fields) => {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
    return fields.reduce((result, field) => {
      result[field] = safeText(value[field]);
      return result;
    }, {});
  };

  const normalizeHistoryState = (value) => {
    if (!value || value.marker !== HISTORY_KEY) return defaultState();
    const next = defaultState();
    next.view = VIEWS.includes(value.view) ? value.view : 'worksheets';
    next.lens = LENSES.includes(value.lens) ? value.lens : 'worksheet';
    next.depth = Math.max(0, Math.min(3, Number(value.depth) || 0));
    next.context = safeReference(value.context, ['type', 'id', 'revision']);
    if (next.context && (!next.context.type || !next.context.id || !next.context.revision)) next.context = null;
    next.filters = normalizedFilters(value.filters);
    next.worksheet = safeReference(value.worksheet, ['id', 'title', 'publicationId', 'publicationHash']);
    next.assignment = safeReference(value.assignment, ['id', 'title']);
    next.assignmentSkipped = Boolean(value.assignmentSkipped);
    next.studentId = safeText(value.studentId, 80) || null;
    next.pageCursors = Array.isArray(value.pageCursors)
      ? value.pageCursors.filter((item) => typeof item === 'string' && item.length <= 191).slice(0, MAX_PAGE_COUNT)
      : [''];
    if (!next.pageCursors.length || next.pageCursors[0] !== '') next.pageCursors = [''];
    next.scrollY = Math.max(0, Number(value.scrollY) || 0);
    if (!next.context) return defaultState();
    if (next.lens === 'student') {
      // Student lens has its own, shallower shape: 'worksheets' is the
      // student roster (depth 0), 'student' is one student's cross-worksheet
      // detail (depth 1) -- it never uses worksheet/assignment drill-down.
      next.worksheet = null;
      next.assignment = null;
      next.assignmentSkipped = false;
      if (next.view !== 'worksheets' && next.view !== 'student') {
        next.view = 'worksheets'; next.depth = 0;
      }
      if (next.view === 'student' && !next.studentId) {
        next.view = 'worksheets'; next.depth = 0;
      }
      next.depth = next.view === 'student' ? 1 : 0;
      if (next.view === 'worksheets') next.studentId = null;
      return next;
    }
    if (next.view !== 'worksheets' && (!next.worksheet?.id || !next.worksheet?.publicationId || !next.worksheet?.publicationHash)) {
      next.view = 'worksheets'; next.depth = 0; next.worksheet = null; next.assignmentSkipped = false;
    }
    if (['students', 'student'].includes(next.view) && !next.assignment?.id) {
      next.view = 'assignments'; next.depth = 1; next.assignment = null; next.studentId = null; next.assignmentSkipped = false;
    }
    if (next.view === 'student' && !next.studentId) {
      next.view = 'students'; next.depth = 2;
    }
    // The skipped-assignments-screen marker is only meaningful for the
    // students/student views it was coined for; sanitize it elsewhere so a
    // corrupted or hand-edited history entry can't mis-render the trail.
    if ('students' !== next.view && 'student' !== next.view) next.assignmentSkipped = false;
    return next;
  };

  let contexts = [];
  let reportContextPicker = null;
  let state = normalizeHistoryState(window.history.state);
  let activeItems = [];
  let activeReport = null;
  let snapshotSourceReport = null;
  let exportBusy = false;
  let nextCursor = '';
  let requestSequence = 0;

  const snapshot = () => ({
    marker: HISTORY_KEY,
    view: state.view,
    lens: state.lens,
    depth: state.depth,
    context: state.context ? { ...state.context } : null,
    filters: {
      periodFrom: state.filters.periodFrom,
      periodThrough: state.filters.periodThrough,
      assignmentStates: [...state.filters.assignmentStates],
      submissionStates: [...state.filters.submissionStates],
    },
    worksheet: state.worksheet ? { ...state.worksheet } : null,
    assignment: state.assignment ? { ...state.assignment } : null,
    assignmentSkipped: state.assignmentSkipped === true,
    studentId: state.studentId,
    pageCursors: [...state.pageCursors],
    scrollY: state.scrollY,
  });

  const replaceHistory = () => window.history.replaceState(snapshot(), '', window.location.href);
  const commitScroll = () => {
    state.scrollY = Math.max(0, window.scrollY || 0);
    replaceHistory();
  };
  const pushState = (next) => {
    commitScroll();
    state = normalizeHistoryState(next);
    window.history.pushState(snapshot(), '', window.location.href);
    syncControls();
    loadView(false);
  };

  const request = async (path, options = {}) => {
    const method = options.method || 'GET';
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (nonce) headers['X-WP-Nonce'] = nonce;
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;
    const response = await window.fetch(restRoot + path, {
      method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers,
      ...(options.body !== undefined ? { body: JSON.stringify(options.body) } : {}),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const diagnosticCode = String(body.code || body.data?.code || 'report_request_failed');
      const correlation = String(body.data?.correlation_id || body.meta?.correlation_id || '');
      console.warn('OJ AI report request failed ' + JSON.stringify({
        status: response.status,
        code: /^[a-z][a-z0-9_]{0,99}$/.test(diagnosticCode) ? diagnosticCode : 'report_request_failed',
        correlation: /^[A-Za-z0-9_.:-]{1,100}$/.test(correlation) ? correlation : '',
      }));
      throw new ReportRequestError(
        response.status,
        safeText(body.code || body.data?.code || 'report_request_failed', 100),
        safeText(body.message || '요청을 처리하지 못했습니다.', 300)
      );
    }
    if (!body || typeof body.data !== 'object' || body.data === null) {
      throw new ReportRequestError(503, 'report_response_invalid', '지금은 보고서를 불러올 수 없습니다.');
    }
    return body;
  };

  const setNotice = (message, tone = 'neutral', alert = false) => {
    const node = find('[data-role="notice"]');
    if (!node) return;
    node.textContent = message || '';
    node.dataset.tone = tone;
    node.setAttribute('role', alert ? 'alert' : 'status');
    node.hidden = !message;
  };

  const setBusy = (busy) => {
    const content = find('[data-role="content"]');
    if (content) content.setAttribute('aria-busy', busy ? 'true' : 'false');
    root.dataset.loading = busy ? 'true' : 'false';
  };

  const clearRendered = () => {
    activeItems = [];
    activeReport = null;
    snapshotSourceReport = null;
    nextCursor = '';
    find('[data-role="list"]')?.replaceChildren();
    find('[data-role="metrics"]')?.replaceChildren();
    find('[data-role="breadcrumbs"]')?.replaceChildren();
    const content = find('[data-role="content"]');
    if (content) content.hidden = true;
    const baseline = find('[data-role="baseline"]');
    if (baseline) { baseline.hidden = true; baseline.textContent = ''; }
    const more = find('[data-role="more"]');
    if (more) more.hidden = true;
    const exportButton = find('[data-action="export-report"]');
    if (exportButton) exportButton.disabled = true;
  };

  const disableFilters = (disabled) => {
    all('[data-role="filters"] input, [data-role="filters"] select, [data-role="filters"] button').forEach((control) => {
      control.disabled = disabled;
    });
  };

  const loseAccess = (message, clearContext) => {
    ++requestSequence;
    clearRendered();
    setBusy(false);
    root.dataset.access = 'denied';
    const previousContext = clearContext ? null : state.context;
    state = { ...defaultState(), context: previousContext ? { ...previousContext } : null, filters: state.filters };
    replaceHistory();
    if (clearContext) {
      contexts = [];
      const select = find('[data-field="context"]');
      if (select) {
        select.replaceChildren();
        const option = create('option', '', '대상 선택');
        option.value = '';
        select.append(option);
      }
      disableFilters(true);
    }
    setNotice(message, 'error', true);
  };

  const handleError = (error) => {
    const status = Number(error?.status || 0);
    if (status === 401 || status === 403) {
      loseAccess('이 보고서를 볼 수 없습니다.', true);
      return;
    }
    if (status === 404) {
      loseAccess('보고서를 찾을 수 없습니다.', false);
      return;
    }
    clearRendered();
    setBusy(false);
    if (status === 409) {
      state = { ...state, view: 'worksheets', depth: 0, worksheet: null, assignment: null, assignmentSkipped: false, studentId: null, pageCursors: [''], scrollY: 0 };
      replaceHistory();
      setNotice('내용이 바뀌었습니다. 다시 조회해 주세요.', 'warning', true);
      return;
    }
    if (status === 422) {
      setNotice('기간과 상태를 확인해 주세요.', 'warning', true);
      return;
    }
    setNotice('지금은 보고서를 불러올 수 없습니다.', 'error', true);
  };

  const contextForState = () => contexts.find((item) => (
    item.type === state.context?.type && item.id === state.context?.id
  )) || null;

  const reportPath = (cursor = '') => {
    const query = new URLSearchParams(buildReportQuery(state.context, state.filters, cursor, PAGE_LIMIT));
    if (state.lens === 'student') {
      if (state.view === 'worksheets') return `/reports/students?${query.toString()}`;
      return `/reports/students/${encodeURIComponent(state.studentId)}?${query.toString()}`;
    }
    if (state.view === 'worksheets') return `/reports?${query.toString()}`;
    query.set('publication_id', state.worksheet.publicationId);
    query.set('publication_hash', state.worksheet.publicationHash);
    if (state.view === 'students' || state.view === 'student') query.set('assignment_id', state.assignment.id);
    return `/reports/worksheets/${encodeURIComponent(state.worksheet.id)}?${query.toString()}`;
  };

  const pageItems = (report) => {
    if (state.lens === 'student') return Array.isArray(report.items) ? report.items : [];
    if (state.view === 'worksheets') return Array.isArray(report.items) ? report.items : [];
    if (state.view === 'assignments') return Array.isArray(report.detail?.assignments) ? report.detail.assignments : [];
    return Array.isArray(report.detail?.students) ? report.detail.students : [];
  };

  const fetchPage = async (cursor) => {
    const body = await request(reportPath(cursor));
    const report = body.data;
    if (state.lens === 'student') {
      if (state.view === 'student' && report.level !== 'student_detail') {
        throw new ReportRequestError(409, 'report_level_changed', '내용이 바뀌었습니다.');
      }
    } else {
      const expectedLevel = state.view === 'assignments' ? 'assignments' : 'students';
      if (state.view !== 'worksheets' && report.level !== expectedLevel) {
        throw new ReportRequestError(409, 'report_level_changed', '내용이 바뀌었습니다.');
      }
    }
    return {
      report,
      items: pageItems(report),
      nextCursor: typeof body.meta?.next_cursor === 'string' ? body.meta.next_cursor : '',
    };
  };

  const loadView = async (restoreScroll) => {
    if (!state.context) {
      clearRendered();
      setNotice(contexts.length ? '대상을 선택하세요.' : '볼 수 있는 대상이 없습니다.');
      return;
    }
    const sequence = ++requestSequence;
    clearRendered();
    setBusy(true);
    setNotice('보고서를 불러오는 중입니다.');
    try {
      if (workflowEntry.worksheetId && state.view === 'worksheets') {
        state.lens = 'worksheet';
        let match = null;
        let pageCursor = '';
        const seen = new Set();
        do {
          const page = await fetchPage(pageCursor);
          if (sequence !== requestSequence) return;
          match = page.items.find(item => item.worksheet?.id === workflowEntry.worksheetId);
          pageCursor = page.nextCursor;
          if (seen.has(pageCursor) && pageCursor) throw new Error('Repeated report cursor');
          seen.add(pageCursor);
        } while (!match && pageCursor);
        if (!match) {
          setBusy(false); setNotice('이 대상과 조회 조건에 해당하는 활동지 보고서가 없습니다.', 'warning', true); return;
        }
        const worksheet = { id: match.worksheet.id, title: match.worksheet.title, publicationId: match.publication.id, publicationHash: match.publication.hash };
        let assignment = null;
        if (workflowEntry.assignmentId) {
          if (workflowEntry.publicationId && workflowEntry.publicationHash) { worksheet.publicationId=workflowEntry.publicationId; worksheet.publicationHash=workflowEntry.publicationHash; }
          const query = new URLSearchParams(buildReportQuery(state.context,state.filters));
          query.set('publication_id',worksheet.publicationId);query.set('publication_hash',worksheet.publicationHash);
          let item=null; const cursors=new Set();
          do {
            const page=await request('/reports/worksheets/'+encodeURIComponent(worksheet.id)+'?'+query);
            if (sequence !== requestSequence) return;
            item=page.data?.detail?.assignments?.find(row=>row.id===workflowEntry.assignmentId);
            const cursor=page.meta?.next_cursor || '';
            if (item || !cursor) break;
            if(cursors.has(cursor))throw Error('Repeated assignment cursor');
            cursors.add(cursor);query.set('cursor',cursor);
          } while(true);
          if (!item) { setBusy(false); setNotice('선택한 배포를 확인할 수 없습니다.', 'warning', true); return; }
          assignment = {id:item.id,title:item.title};
        }
        state = {...state,worksheet,assignment,view:assignment?'students':'assignments',depth:assignment?2:1,pageCursors:[''],studentId:null};
        replaceHistory(); syncControls();
      }
      let items = [];
      let report = null;
      let firstReport = null;
      let cursor = '';
      for (const pageCursor of state.pageCursors) {
        const page = await fetchPage(pageCursor);
        if (sequence !== requestSequence) return;
        items = items.concat(page.items);
        report = page.report;
        if (!firstReport) firstReport = page.report;
        cursor = page.nextCursor;
      }
      if (state.lens === 'group' && state.view === 'worksheets') {
        const seen = new Set(state.pageCursors);
        while (cursor) {
          if (seen.has(cursor)) throw new Error('Repeated report cursor');
          seen.add(cursor);
          const page = await fetchPage(cursor);
          if (sequence !== requestSequence) return;
          if (page.report.filter_hash !== firstReport.filter_hash || page.report.metric_contract !== firstReport.metric_contract) throw new Error('보고서 조회 조건이 변경되었습니다. 다시 조회해 주세요.');
          items.push(...page.items);
          report = page.report;
          cursor = page.nextCursor;
        }
      }
      activeItems = state.lens === 'group' ? items : items.slice(0, PAGE_LIMIT * MAX_PAGE_COUNT);
      activeReport = report;
      snapshotSourceReport = firstReport;
      nextCursor = activeItems.length < PAGE_LIMIT * MAX_PAGE_COUNT ? cursor : '';
      render();
      window.OJAIWorkflow?.setSelection({ worksheetId: state.worksheet?.id || workflowEntry.worksheetId || '', title: state.worksheet?.title || '', assignmentId: state.assignment?.id || '', publicationId: state.worksheet?.publicationId, publicationHash: state.worksheet?.publicationHash, assignmentTitle: state.assignment?.title || '', context: contextForState() });
      setBusy(false);
      setNotice(activeItems.length ? '' : '표시할 결과가 없습니다.');
      root.dataset.access = 'allowed';
      if (restoreScroll) window.requestAnimationFrame(() => window.scrollTo(0, state.scrollY));
    } catch (error) {
      if (sequence === requestSequence) handleError(error);
    }
  };

  const loadMore = async (button) => {
    if (!nextCursor || state.pageCursors.length >= MAX_PAGE_COUNT) return;
    button.disabled = true;
    const cursor = nextCursor;
    try {
      const page = await fetchPage(cursor);
      activeItems = activeItems.concat(page.items).slice(0, PAGE_LIMIT * MAX_PAGE_COUNT);
      activeReport = page.report;
      nextCursor = activeItems.length < PAGE_LIMIT * MAX_PAGE_COUNT ? page.nextCursor : '';
      state.pageCursors.push(cursor);
      state.scrollY = window.scrollY || 0;
      replaceHistory();
      render();
      window.requestAnimationFrame(() => window.scrollTo(0, state.scrollY));
    } catch (error) {
      handleError(error);
    } finally {
      button.disabled = false;
    }
  };

  const utcDate = (value) => {
    if (!value) return '-';
    const source = String(value).replace(' ', 'T');
    const date = new Date(source.endsWith('Z') ? source : `${source}Z`);
    if (Number.isNaN(date.getTime())) return '-';
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(date);
  };

  const assignmentState = (value) => ({ scheduled: '예약', open: '진행', closed: '종료', withdrawn: '철회' }[value] || '확인 필요');
  const studentStatus = (value) => ({
    not_submitted: '미제출', review_required: '검토 필요', reviewed: '검토 완료',
    released: '결과 공개', withdrawn: '공개 회수', release_stale: '업데이트 중',
  }[value] || '확인 필요');
  const releaseState = (value) => ({ released: '공개', corrected: '정정 공개', withdrawn: '공개 회수', stale: '업데이트 중' }[value] || '공개 전');

  const appendCell = (row, label, value, className = '') => {
    const cell = create('td', className, value);
    cell.dataset.label = label;
    row.append(cell);
    return cell;
  };

  const table = (headers) => {
    const wrap = create('div', 'oj-ai-report__table-wrap');
    const value = create('table', 'oj-ai-report__table');
    const head = create('thead');
    const row = create('tr');
    headers.forEach((header) => {
      const cell = create('th', '', header);
      cell.scope = 'col';
      row.append(cell);
    });
    head.append(row);
    const body = create('tbody');
    value.append(head, body);
    wrap.append(value);
    return { wrap, body };
  };

  const linkButton = (label, action, data = {}) => {
    const button = create('button', 'oj-ai-report__table-link', label);
    button.type = 'button';
    button.dataset.action = action;
    Object.entries(data).forEach(([key, value]) => { button.dataset[key] = String(value); });
    return button;
  };

  const metricPairs = (progress) => [
    ['대상', `${Number(progress?.target_count || 0)}명`],
    ['제출', formatRatio(progress?.submission_rate)],
    ['평가', formatRatio(progress?.assessment_rate)],
    ['공개', formatRatio(progress?.release_rate)],
    ['평균', formatAverage(progress?.average_score)],
    ['검토 필요', `${Number(progress?.review_required_count || 0)}건`],
  ];

  const renderMetrics = (progress) => {
    const target = find('[data-role="metrics"]');
    target.replaceChildren();
    if (!progress || state.view === 'worksheets' || state.view === 'student') {
      target.hidden = true;
      return;
    }
    metricPairs(progress).forEach(([label, value]) => {
      const item = create('div');
      item.append(create('dt', '', label), create('dd', '', value));
      target.append(item);
    });
    target.hidden = false;
  };

  const renderWorksheets = () => {
    const result = table(['활동지', '대상', '제출', '평가', '평균', '검토']);
    activeItems.forEach((item) => {
      const row = create('tr');
      const titleCell = appendCell(row, '활동지', '', 'oj-ai-report__title-cell');
      titleCell.append(linkButton(item.worksheet?.title || '활동지', 'open-worksheet', { worksheetId: item.worksheet?.id || '' }));
      if (item.worksheet?.subject_label) titleCell.append(create('span', 'oj-ai-report__subline', item.worksheet.subject_label));
      appendCell(row, '대상', `${Number(item.progress?.target_count || 0)}명`);
      appendCell(row, '제출', formatRatio(item.progress?.submission_rate));
      appendCell(row, '평가', formatRatio(item.progress?.assessment_rate));
      appendCell(row, '평균', formatAverage(item.progress?.average_score));
      appendCell(row, '검토', `${Number(item.progress?.review_required_count || 0)}건`);
      result.body.append(row);
    });
    return result.wrap;
  };

  const sumMetric = (name) => {
    const numerator = activeItems.reduce((sum, item) => sum + Number(item.progress?.[name]?.numerator || 0), 0);
    const denominator = activeItems.reduce((sum, item) => sum + Number(item.progress?.[name]?.denominator || 0), 0);
    return {
      numerator,
      denominator,
      value: denominator > 0 ? ((numerator / denominator) * 100).toFixed(2) : '0.00',
    };
  };

  const renderGroups = () => {
    const context = contextForState();
    const result = table(['배포 대상', '활동지', '대상 합계', '제출', '평가', '검토']);
    const caption = create('caption', '', `조회 시각: ${utcDate(snapshotSourceReport?.baseline_at_utc)} ~ ${utcDate(activeReport?.baseline_at_utc)}`);
    result.body.parentElement.prepend(caption);
    const row = create('tr');
    appendCell(row, '배포 대상', context?.label || '선택한 대상', 'oj-ai-report__title-cell');
    appendCell(row, '활동지', String(activeItems.length) + '개');
    appendCell(row, '대상 합계', String(activeItems.reduce((sum, item) => sum + Number(item.progress?.target_count || 0), 0)) + '명');
    appendCell(row, '제출', formatRatio(sumMetric('submission_rate')));
    appendCell(row, '평가', formatRatio(sumMetric('assessment_rate')));
    appendCell(row, '검토', String(activeItems.reduce((sum, item) => sum + Number(item.progress?.review_required_count || 0), 0)) + '건');
    result.body.append(row);
    return result.wrap;
  };

  const renderAssignments = () => {
    const result = table(['배포', '상태', '대상', '제출', '평가', '평균', '마감']);
    activeItems.forEach((item) => {
      const row = create('tr');
      const titleCell = appendCell(row, '배포', '', 'oj-ai-report__title-cell');
      titleCell.append(linkButton(item.title || '배포', 'open-assignment', { assignmentId: item.id || '' }));
      appendCell(row, '상태', assignmentState(item.state));
      appendCell(row, '대상', `${Number(item.progress?.target_count || 0)}명`);
      appendCell(row, '제출', formatRatio(item.progress?.submission_rate));
      appendCell(row, '평가', formatRatio(item.progress?.assessment_rate));
      appendCell(row, '평균', formatAverage(item.progress?.average_score));
      appendCell(row, '마감', utcDate(item.schedule?.due_at_utc));
      result.body.append(row);
    });
    return result.wrap;
  };

  const studentScore = (item) => typeof item.official_assessment?.score?.value === 'string'
    ? `${item.official_assessment.score.value}%` : '-';

  const renderStudentRoster = () => {
    const result = table(['학생', '대상', '제출', '평가', '평균', '검토']);
    activeItems.forEach((item) => {
      const row = create('tr');
      const nameCell = appendCell(row, '학생', '', 'oj-ai-report__title-cell');
      nameCell.append(linkButton(item.target?.display_name || '학생', 'open-student', { studentId: item.target?.id || '' }));
      appendCell(row, '대상', `${Number(item.summary?.target_count || 0)}개`);
      appendCell(row, '제출', formatRatio(item.summary?.submission_rate));
      appendCell(row, '평가', formatRatio(item.summary?.assessment_rate));
      appendCell(row, '평균', formatIndividualAverage(item.summary?.average_score));
      appendCell(row, '검토', `${Number(item.summary?.review_required_count || 0)}건`);
      result.body.append(row);
    });
    return result.wrap;
  };

  const renderStudentCrossWorksheets = () => {
    const result = table(['활동지', '배포', '상태', '제출', '점수', '결과']);
    activeItems.forEach((item) => {
      const row = create('tr');
      const titleCell = appendCell(row, '활동지', '', 'oj-ai-report__title-cell');
      titleCell.append(create('span', '', item.worksheet?.title || '활동지'));
      if (item.worksheet?.subject_label) titleCell.append(create('span', 'oj-ai-report__subline', item.worksheet.subject_label));
      appendCell(row, '배포', item.assignment?.title || '배포');
      appendCell(row, '상태', studentStatus(item.status));
      appendCell(row, '제출', utcDate(item.submission?.submitted_at_utc));
      appendCell(row, '점수', studentScore(item));
      appendCell(row, '결과', releaseState(item.official_assessment?.release?.state));
      result.body.append(row);
    });
    return result.wrap;
  };

  const renderStudents = () => {
    const result = table(['학생', '상태', '제출', '점수', '결과']);
    activeItems.forEach((item) => {
      const row = create('tr');
      const nameCell = appendCell(row, '학생', '', 'oj-ai-report__title-cell');
      nameCell.append(linkButton(item.target?.display_name || '학생', 'open-student', { studentId: item.target?.id || '' }));
      appendCell(row, '상태', studentStatus(item.status));
      appendCell(row, '제출', utcDate(item.submission?.submitted_at_utc));
      appendCell(row, '점수', studentScore(item));
      appendCell(row, '결과', releaseState(item.official_assessment?.release?.state));
      result.body.append(row);
    });
    return result.wrap;
  };

  const detailPair = (list, label, value) => {
    const item = create('div');
    item.append(create('dt', '', label), create('dd', '', value));
    list.append(item);
  };

  const renderStudent = () => {
    const item = activeItems.find((candidate) => candidate.target?.id === state.studentId);
    if (!item) return create('p', 'oj-ai-report__empty', '학생 결과를 찾을 수 없습니다.');
    const wrapper = create('section', 'oj-ai-report__student-detail');
    const details = create('dl', 'oj-ai-report__student-facts');
    detailPair(details, '상태', studentStatus(item.status));
    detailPair(details, '제출', utcDate(item.submission?.submitted_at_utc));
    detailPair(details, '점수', studentScore(item));
    detailPair(details, '검토', utcDate(item.official_assessment?.review?.reviewed_at_utc));
    detailPair(details, '결과', releaseState(item.official_assessment?.release?.state));
    wrapper.append(details);
    const criteria = Array.isArray(item.official_assessment?.criteria) ? item.official_assessment.criteria : [];
    if (criteria.length) {
      wrapper.append(create('h3', '', '평가 기준'));
      const criterionList = create('dl', 'oj-ai-report__criteria');
      criteria.forEach((criterion, index) => detailPair(
        criterionList,
        `기준 ${index + 1}`,
        typeof criterion.value === 'string' ? `${criterion.value}%` : '-'
      ));
      wrapper.append(criterionList);
    }
    return wrapper;
  };

  const crumb = (label, steps) => {
    const item = create('li');
    if (steps > 0) {
      const button = create('button', '', label);
      button.type = 'button';
      button.dataset.action = 'history-back';
      button.dataset.steps = String(steps);
      item.append(button);
    } else {
      item.textContent = label;
      item.setAttribute('aria-current', 'page');
    }
    return item;
  };

  const renderTrail = (studentName) => {
    const target = find('[data-role="breadcrumbs"]');
    target.replaceChildren();
    if (workflowEntry.worksheetId) {
      const overview = create('li');
      const link = create('a', '', '대상 전체 집계');
      link.href = window.OJAIWorkflow.overviewUrl('reports');
      overview.append(link);
      target.append(overview);
      const level = (label, view) => {
        const item = crumb(label, state.view === view ? 0 : 1);
        const button = item.querySelector('button');
        if (button) {
          button.dataset.action = 'report-level';
          button.dataset.view = view;
        }
        return item;
      };
      target.append(level(state.worksheet?.title || '활동지', 'assignments'));
      if (state.depth >= 2) target.append(level(state.assignment?.title || '배포', 'students'));
      if (state.depth >= 3) target.append(crumb(studentName || '학생', 0));
    } else if (state.lens === 'student') {
      target.append(crumb('학생별', state.depth));
      if (state.depth >= 1) target.append(crumb(studentName || '학생', 0));
    } else {
      const steps = worksheetTrailSteps(state.depth, state.assignmentSkipped);
      target.append(crumb('활동지', steps.root));
      if (null !== steps.worksheet) target.append(crumb(state.worksheet?.title || '활동지', steps.worksheet));
      if (null !== steps.assignment) target.append(crumb(state.assignment?.title || '배포', steps.assignment));
      if (null !== steps.student) target.append(crumb(studentName || '학생', steps.student));
    }
    const back = find('[data-action="back"]');
    back.hidden = state.depth === 0;
  };

  // A workflow deep link has no parent report entries in browser history.
  const openWorkflowLevel = (view) => {
    if (!['assignments', 'students'].includes(view)) return;
    pushState({
      ...state, view, depth: view === 'assignments' ? 1 : 2,
      assignment: view === 'assignments' ? null : state.assignment,
      assignmentSkipped: false, studentId: null, pageCursors: [''], scrollY: 0,
    });
  };

  const syncLenses = () => {
    all('[data-lens]').forEach((button) => {
      const selected = button.dataset.lens === state.lens;
      button.setAttribute('aria-selected', selected ? 'true' : 'false');
      button.tabIndex = selected ? 0 : -1;
    });
  };

  const refreshExportControls = () => {
    const button = find('[data-action="export-report"]');
    const status = find('[data-role="export-status"]');
    if (!button || !status) return;
    const ready = root.dataset.canExport === '1'
      && state.view === 'worksheets'
      && state.lens === 'worksheet'
      && Boolean(snapshotSourceReport);
    button.disabled = exportBusy || !ready;
    if (!exportBusy) {
      status.textContent = ready ? '활동지별 보고서' : '활동지별에서 만들 수 있습니다.';
      status.dataset.tone = ready ? 'neutral' : 'muted';
    }
  };

  const render = () => {
    if (!activeReport) return;
    const context = contextForState();
    const detail = activeReport.detail || {};
    const isStudentLens = state.lens === 'student';
    const selectedStudentName = state.view !== 'student'
      ? null
      : isStudentLens
        ? (activeReport.student?.display_name || null)
        : (activeItems.find((item) => item.target?.id === state.studentId)?.target?.display_name || null);
    renderTrail(selectedStudentName);
    const lensTitles = {
      worksheet: ['활동지별', context?.label || '선택한 대상'],
      group: ['배포 대상별', context?.label || '선택한 대상'],
      student: ['학생별', context?.label || '선택한 대상'],
    };
    const titles = {
      assignments: [state.worksheet?.title || '활동지', state.lens === 'student' ? '배포 선택' : '배포'],
      students: [state.assignment?.title || '배포', '학생별'],
      student: isStudentLens
        ? [selectedStudentName || '학생', context?.label || '선택한 대상']
        : [selectedStudentName || '학생', state.assignment?.title || '배포'],
    };
    const currentTitle = state.view === 'worksheets' ? lensTitles[state.lens] : titles[state.view];
    find('[data-role="view-title"]').textContent = currentTitle[0];
    find('[data-role="view-eyebrow"]').textContent = currentTitle[1];
    find('[data-role="view-count"]').textContent = state.view === 'student'
      ? (isStudentLens ? String(activeItems.length) + '건' : '')
      : state.view === 'worksheets' && state.lens === 'group' ? '1개 대상' : String(activeItems.length) + '건';
    find('[data-role="summary"]').textContent = context ? context.label : '대상을 선택하세요.';
    const baseline = find('[data-role="baseline"]');
    baseline.textContent = '기준 ' + utcDate(activeReport.baseline_at_utc);
    baseline.hidden = false;
    renderMetrics(detail.summary);
    const list = find('[data-role="list"]');
    list.replaceChildren(
      state.view === 'worksheets'
        ? (state.lens === 'group' ? renderGroups() : isStudentLens ? renderStudentRoster() : renderWorksheets())
        : state.view === 'assignments' ? renderAssignments()
          : state.view === 'students' ? renderStudents()
            : isStudentLens ? renderStudentCrossWorksheets() : renderStudent()
    );
    const more = find('[data-role="more"]');
    more.hidden = !nextCursor || state.lens === 'group' || (state.view === 'student' && !isStudentLens);
    find('[data-role="content"]').hidden = false;
    syncLenses();
    refreshExportControls();
  };

  const worksheetById = (id) => activeItems.find((item) => item.worksheet?.id === id) || null;
  const assignmentById = (id) => activeItems.find((item) => item.id === id) || null;
  const studentById = (id) => activeItems.find((item) => item.target?.id === id) || null;

  // The assignments-list request for one worksheet: the same request that
  // backs the "배포 목록" screen, used here to check ahead of rendering it.
  const assignmentsListPath = (worksheet, cursor = '') => {
    const query = new URLSearchParams(buildReportQuery(state.context, state.filters, cursor, PAGE_LIMIT));
    query.set('publication_id', worksheet.publicationId);
    query.set('publication_hash', worksheet.publicationHash);
    return `/reports/worksheets/${encodeURIComponent(worksheet.id)}?${query.toString()}`;
  };

  // Best-effort resolution of a worksheet's sole distribution: fetches the
  // same assignments list the "배포 목록" screen would show and, only when it
  // resolves to exactly one item with no further pages, returns it so the
  // caller can jump straight to its student list. Any failure or mismatch
  // (stale count, more than one distribution by the time we check) returns
  // null so the caller falls back to the ordinary list screen unchanged.
  const resolveSoleAssignment = async (worksheet) => {
    try {
      const body = await request(assignmentsListPath(worksheet));
      const report = body.data;
      if (report.level !== 'assignments') return null;
      const items = Array.isArray(report.detail?.assignments) ? report.detail.assignments : [];
      const hasMore = typeof body.meta?.next_cursor === 'string' && body.meta.next_cursor !== '';
      return soleAssignmentFrom(items, hasMore);
    } catch (_error) {
      return null;
    }
  };

  const openWorksheet = async (id) => {
    const item = worksheetById(id);
    if (!item) return;
    const worksheet = {
      id: item.worksheet.id, title: item.worksheet.title,
      publicationId: item.publication?.id || '', publicationHash: item.publication?.hash || '',
    };
    if (state.lens !== 'student' && Number(item.progress?.assignment_count) === 1) {
      const sequence = ++requestSequence;
      setBusy(true);
      setNotice('보고서를 불러오는 중입니다.');
      const sole = await resolveSoleAssignment(worksheet);
      if (sequence !== requestSequence) return;
      setBusy(false);
      if (sole) {
        pushState({
          ...state, view: 'students', depth: 2, assignmentSkipped: true,
          worksheet, assignment: sole, studentId: null, pageCursors: [''], scrollY: 0,
        });
        return;
      }
    }
    pushState({
      ...state, view: 'assignments', depth: 1, assignmentSkipped: false,
      worksheet, assignment: null, studentId: null, pageCursors: [''], scrollY: 0,
    });
  };

  const openAssignment = (id) => {
    const item = assignmentById(id);
    if (!item) return;
    pushState({
      ...state, view: 'students', depth: 2, assignmentSkipped: false,
      assignment: { id: item.id, title: item.title }, studentId: null, pageCursors: [''], scrollY: 0,
    });
  };

  const openStudent = (id) => {
    if (!studentById(id)) return;
    if (state.lens === 'student') {
      // Student lens: jump straight from the roster to this one student's
      // cross-worksheet detail (a fresh, independently paginated resource),
      // skipping the worksheet -> distribution drill-down entirely.
      pushState({ ...state, view: 'student', depth: 1, studentId: id, pageCursors: [''], scrollY: 0 });
      return;
    }
    pushState({ ...state, view: 'student', depth: 3, studentId: id, scrollY: 0 });
  };

  const selectLens = (lens) => {
    if (workflowEntry.worksheetId) return;
    if (!LENSES.includes(lens) || lens === state.lens) return;
    pushState(baseState(state.context, state.filters, lens));
  };

  const selectedValues = (kind) => all(`[data-filter="${kind}"]:checked`).map((field) => field.value);
  const readFilters = () => {
    const from = find('[data-field="period-from"]').value;
    const through = find('[data-field="period-through"]').value;
    if ((from || through) && (!from || !through)) throw new Error('기간의 시작과 마지막 날짜를 모두 선택해 주세요.');
    if (from && (!validDate(from) || !validDate(through) || from > through)) throw new Error('기간을 확인해 주세요.');
    const assignmentStates = selectedValues('assignment');
    const submissionStates = selectedValues('submission');
    if (!assignmentStates.length || !submissionStates.length) throw new Error('상태를 하나 이상 선택해 주세요.');
    return normalizedFilters({ periodFrom: from, periodThrough: through, assignmentStates, submissionStates });
  };

  const syncControls = () => {
    find('[data-field="period-from"]').value = state.filters.periodFrom;
    find('[data-field="period-through"]').value = state.filters.periodThrough;
    all('[data-filter="assignment"]').forEach((field) => { field.checked = state.filters.assignmentStates.includes(field.value); });
    all('[data-filter="submission"]').forEach((field) => { field.checked = state.filters.submissionStates.includes(field.value); });
    const context = find('[data-field="context"]');
    const index = contexts.findIndex((item) => item.type === state.context?.type && item.id === state.context?.id);
    if (reportContextPicker) reportContextPicker.setSelected(index);
    else context.value = index >= 0 ? String(index) : '';
    syncLenses();
  };

  const baseState = (context, filters = state.filters, lens = state.lens) => ({
    ...defaultState(), lens, context: context ? { type: context.type, id: context.id, revision: context.revision } : null,
    filters,
  });

  const applyFilters = () => {
    try {
      const filters = readFilters();
      if (!state.context) throw new Error('대상을 선택해 주세요.');
      pushState(baseState(state.context, filters));
    } catch (error) {
      setNotice(error.message || '필터를 확인해 주세요.', 'warning', true);
    }
  };

  const resetFilters = () => {
    const filters = normalizedFilters();
    state.filters = filters;
    syncControls();
    if (state.context) pushState(baseState(state.context, filters));
    else replaceHistory();
  };

  const selectContext = (value) => {
    clearRendered();
    const index = value === '' ? -1 : Number(value);
    const context = index >= 0 ? contexts[index] : null;
    window.OJAIWorkflow.setSelection({context,assignmentId:'',assignmentTitle:'',publicationId:'',publicationHash:''});
    pushState(baseState(context));
  };

  const canonicalValue = (value) => {
    if (Array.isArray(value)) return value.map(canonicalValue);
    if (value && typeof value === 'object') {
      return Object.keys(value).sort().reduce((result, key) => {
        result[String(key).normalize('NFC')] = canonicalValue(value[key]);
        return result;
      }, {});
    }
    return typeof value === 'string' ? value.normalize('NFC') : value;
  };

  const canonicalJson = (value) => JSON.stringify(canonicalValue(value));

  const sha256 = async (value) => {
    if (!window.crypto?.subtle) throw new Error('이 브라우저에서는 파일을 만들 수 없습니다.');
    const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
  };

  const publicId = () => {
    const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    let time = Date.now();
    let head = '';
    for (let index = 0; index < 10; index += 1) {
      head = alphabet[time % 32] + head;
      time = Math.floor(time / 32);
    }
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    let tail = '';
    for (let index = 0; index < 16; index += 1) tail += alphabet[bytes[index] % 32];
    return head + tail;
  };

  const setExportStatus = (message, tone = 'neutral') => {
    const status = find('[data-role="export-status"]');
    if (!status) return;
    status.textContent = message;
    status.dataset.tone = tone;
  };

  const snapshotPayload = () => {
    const report = snapshotSourceReport;
    const filter = report?.filters?.applied;
    if (
      state.view !== 'worksheets'
      || state.lens !== 'worksheet'
      || !report
      || !filter
      || typeof report.filter_hash !== 'string'
      || typeof report.source_manifest_hash !== 'string'
    ) {
      throw new Error('활동지별 보고서를 조회한 뒤 다시 시도해 주세요.');
    }
    return {
      lens: 'worksheet',
      context: { type: state.context.type, id: state.context.id, revision: state.context.revision },
      filter: canonicalValue(filter),
      source_page_size: PAGE_LIMIT,
    };
  };

  const createReportSnapshot = async () => {
    const payload = snapshotPayload();
    const requestHash = await sha256(canonicalJson({
      schema_id: 'oj_ai.report_snapshot_request',
      schema_version: '1.0.0',
      payload,
    }));
    const commandId = publicId();
    const source = snapshotSourceReport;
    const body = await request('/reports/commands/create-snapshot', {
      method: 'POST',
      idempotencyKey: 'report-snapshot-' + commandId,
      body: {
        command_id: commandId,
        expected: {
          expected_source_type: 'context',
          expected_source_id: state.context.id,
          expected_source_revision: state.context.revision,
          expected_live_source_manifest_hash: source.source_manifest_hash,
          expected_filter_hash: source.filter_hash,
          expected_request_schema: 'oj_ai.report_snapshot_request',
          expected_request_version: '1.0.0',
          expected_request_hash: requestHash,
        },
        payload,
      },
    });
    const snapshot = body.data?.report_snapshot;
    if (!snapshot?.id || snapshot.state !== 'ready' || !/^[a-f0-9]{64}$/.test(String(snapshot.snapshot_hash || ''))) {
      throw new Error('보고서 파일 원본을 확인하지 못했습니다.');
    }
    return snapshot;
  };

  const waitForExport = async (jobId) => {
    for (let attempt = 0; attempt < 40; attempt += 1) {
      const body = await request('/export-jobs/' + encodeURIComponent(jobId));
      const job = body.data?.export_job || {};
      if (job.state === 'ready' && job.artifact_id) return job;
      if (['failed', 'cancelled', 'expired'].includes(job.state)) throw new Error('파일을 만들지 못했습니다.');
      await new Promise((resolve) => window.setTimeout(resolve, 500));
    }
    throw new Error('파일 준비가 지연되고 있습니다.');
  };

  const downloadReport = async (artifactId, format) => {
    const headers = { Accept: format.mimeType };
    if (nonce) headers['X-WP-Nonce'] = nonce;
    const response = await window.fetch(restRoot + '/artifacts/' + encodeURIComponent(artifactId) + '/download', {
      method: 'GET',
      credentials: 'same-origin',
      headers,
    });
    if (!response.ok) throw new Error('파일을 내려받지 못했습니다.');
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'worksheet-report.' + format.extension;
    document.body.append(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  };

  const exportReport = async (button) => {
    if (button.disabled || exportBusy) return;
    const selected = find('input[name="oj-ai-report-format"]:checked');
    const formatKey = String(selected?.value || 'pdf');
    const format = EXPORT_FORMATS[formatKey];
    if (!format) return;
    exportBusy = true;
    refreshExportControls();
    setExportStatus(format.label + ' 만드는 중', 'working');
    try {
      const snapshot = await createReportSnapshot();
      const commandId = publicId();
      const body = await request('/report-snapshots/' + encodeURIComponent(snapshot.id) + '/commands/' + format.route, {
        method: 'POST',
        idempotencyKey: 'report-export-' + commandId,
        body: {
          command_id: commandId,
          expected: {
            expected_snapshot_hash: snapshot.snapshot_hash,
            expected_snapshot_id: snapshot.id,
            expected_snapshot_state: 'ready',
          },
          payload: { locale: locale, profile: format.profile },
        },
      });
      const queued = body.data?.export_job || {};
      const jobId = String(queued.id || queued.job_id || '');
      if (!jobId) throw new Error('파일 작업을 확인하지 못했습니다.');
      const ready = queued.state === 'ready' && queued.artifact_id ? queued : await waitForExport(jobId);
      await downloadReport(ready.artifact_id, format);
      setExportStatus(format.label + ' 다운로드됨', 'ready');
    } catch (error) {
      setExportStatus(error instanceof Error ? error.message : '파일을 만들지 못했습니다.', 'error');
    } finally {
      exportBusy = false;
      const current = find('[data-action="export-report"]');
      if (current) current.disabled = state.view !== 'worksheets' || state.lens !== 'worksheet' || !snapshotSourceReport;
    }
  };

  const loadContexts = async () => {
    setBusy(true);
    setNotice('배포 대상을 불러오는 중입니다.');
    try {
      const items = await window.OJAIWorkflow.allPages(request, '/contexts?limit=100');
      contexts = items.filter((item) => item?.selectable === true);
      const select = find('[data-field="context"]');
      const requested = workflowEntry.context;
      let selected = requested ? contexts.find(item => item.type === requested.type && item.id === requested.id && (!requested.revision || item.revision === requested.revision)) : contextForState();
      if (!selected && !requested && !state.context) selected = contexts[window.OJAIWorkflow.preferredContextIndex(contexts)];
      reportContextPicker = window.OJAIWorkflow.contextPicker(select, contexts, contexts.indexOf(selected));
      if (selected) {
        const revisionChanged = state.context && state.context.revision !== selected.revision;
        state.context = { type: selected.type, id: selected.id, revision: selected.revision };
        if (revisionChanged) state = baseState(state.context, state.filters);
      } else {
        state = baseState(null, state.filters);
      }
      syncControls();
      if (workflowEntry.worksheetId) all('[data-lens]').forEach(button => { button.hidden = true; });
      replaceHistory();
      setBusy(false);
      if (state.context) loadView(true);
      else setNotice(contexts.length ? '대상을 선택하세요.' : '볼 수 있는 대상이 없습니다.');
    } catch (error) {
      handleError(error);
    }
  };

  find('[data-role="filters"]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    applyFilters();
  });
  find('[data-field="context"]')?.addEventListener('change', (event) => selectContext(event.target.value));
  root.addEventListener('click', (event) => {
    const lens = event.target instanceof Element ? event.target.closest('[data-lens]') : null;
    if (lens && root.contains(lens)) {
      selectLens(lens.dataset.lens || '');
      return;
    }
    const target = event.target instanceof Element ? event.target.closest('[data-action]') : null;
    if (!target || !root.contains(target)) return;
    const action = target.dataset.action;
    if (action === 'reset-filters') resetFilters();
    else if (action === 'report-level') openWorkflowLevel(target.dataset.view);
    else if (action === 'back' && workflowEntry.worksheetId) {
      if (state.view === 'assignments') window.location.assign(window.OJAIWorkflow.overviewUrl('reports'));
      else openWorkflowLevel(state.view === 'student' ? 'students' : 'assignments');
    }
    else if (action === 'back') window.history.back();
    else if (action === 'history-back') window.history.go(-Math.max(1, Number(target.dataset.steps) || 1));
    else if (action === 'open-worksheet') openWorksheet(target.dataset.worksheetId || '');
    else if (action === 'open-assignment') openAssignment(target.dataset.assignmentId || '');
    else if (action === 'open-student') openStudent(target.dataset.studentId || '');
    else if (action === 'load-more') loadMore(target);
    else if (action === 'export-report') exportReport(target);
  });
  root.addEventListener('keydown', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('[role="tab"][data-lens]') : null;
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const current = Math.max(0, LENSES.indexOf(tab.dataset.lens));
    let next = current;
    if (event.key === 'ArrowLeft') next = (current - 1 + LENSES.length) % LENSES.length;
    if (event.key === 'ArrowRight') next = (current + 1) % LENSES.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = LENSES.length - 1;
    event.preventDefault();
    selectLens(LENSES[next]);
    window.requestAnimationFrame(() => find('[data-lens="' + LENSES[next] + '"]')?.focus());
  });
  window.addEventListener('popstate', (event) => {
    if (!event.state || event.state.marker !== HISTORY_KEY) return;
    state = normalizeHistoryState(event.state);
    syncControls();
    loadView(true);
  });
  window.addEventListener('pagehide', commitScroll);

  syncControls();
  loadContexts();
})();
