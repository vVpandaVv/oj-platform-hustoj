(() => {
  'use strict';

  const root = document.getElementById('oj-ai-student-assignment-app');
  if (!root) return;

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
  const TIME_ZONE = resolveTimeZone(root.dataset.timezone);

  const SUBMISSION_STATUS_EVENT = 'oj-ai:student-submission-status';
  const apiBase = root.dataset.restRoot
    || `${String(window.wpApiSettings?.root || '/wp-json/').replace(/\/$/, '')}/oj-ai/v1`;
  let nonce = root.dataset.restNonce || window.wpApiSettings?.nonce || '';
  const mode = root.dataset.mode || 'list';
  const assignmentId = root.dataset.assignmentId || '';
  let assignments = [];
  let activeFilter = 'todo';
  const assetUrls = new Set();
  let documentGeneration = 0;

  // A host that caches this page's HTML can serve one visitor's frozen
  // nonce to everyone else. Fetch a live one and retry once before
  // giving up, instead of trusting only the value baked into the page.
  const refreshNonce = async () => {
    try {
      const response = await fetch(`${apiBase}/session-nonce`, {
        credentials: 'same-origin',
        // WordPress treats a request with NO nonce at all as anonymous
        // (logging the visitor out for that one request), which is worse
        // than the stale-nonce rejection this call exists to recover
        // from. Send the stale value anyway so the server sees an
        // invalid-nonce request from a still-logged-in visitor instead.
        headers: { Accept: 'application/json', 'X-WP-Nonce': nonce },
      });
      if (!response.ok) return false;
      const data = await response.json().catch(() => null);
      if (data && typeof data.nonce === 'string' && data.nonce) {
        nonce = data.nonce;
        return true;
      }
    } catch (_error) {
      // Fall through: the caller surfaces the original request's error.
    }
    return false;
  };

  // Do this once, eagerly, instead of waiting for the first protected
  // request to be rejected. A host that caches this page's HTML always
  // serves a frozen nonce on the very first load of a cached copy, so
  // waiting for that rejection would show a failed request on every
  // such visit; refreshing up front avoids it in the common case while
  // the reactive retry below still covers a nonce going stale mid-session.
  const readyNonce = refreshNonce();

  const fetchOnce = (path, options) => fetch(`${apiBase}${path}`, {
    credentials: 'same-origin',
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
      ...(options.headers || {}),
    },
  });

  const request = async (path, options = {}) => {
    await readyNonce;
    let response = await fetchOnce(path, options);
    if ((response.status === 401 || response.status === 403) && (await refreshNonce())) {
      response = await fetchOnce(path, options);
    }
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || '요청을 처리하지 못했습니다.');
    return body;
  };

  const create = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  };

  const toDate = (value) => value ? new Date(`${value.replace(' ', 'T')}Z`) : null;
  const formatDate = (value) => {
    const date = toDate(value);
    return date ? new Intl.DateTimeFormat('ko-KR', { month: 'long', day: 'numeric', weekday: 'short', hour: '2-digit', minute: '2-digit', timeZone: TIME_ZONE }).format(date) : '-';
  };
  const seoulDayKey = (date) => new Intl.DateTimeFormat('en-CA', { timeZone: TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
  const isToday = (value) => {
    const date = toDate(value);
    return Boolean(date) && seoulDayKey(date) === seoulDayKey(new Date());
  };
  const availabilityLabel = (value) => ({ upcoming: '예정', open: '작성 가능', late: '마감 지남', closed: '종료' }[value] || '');
  const saveLabel = (submission) => {
    if (!submission) return '시작 전';
    const state = String(submission.state || '');
    if (state === 'reopened') return '다시 작성';
    if (state === 'locked') return '잠김';
    if (state === 'auto_submitted') return '자동 제출';
    if (state === 'proxy_submitted') return '교사 제출';
    if (state === 'submitted') return '제출 완료';
    if (state === 'conflict') return '저장 확인 필요';
    if (submission.current_revision_id) return '저장됨';
    return '작성 중';
  };
  const assignmentUrl = (id) => `/oj-addons/oj-ai-dashboard/assignments/${encodeURIComponent(id)}`;
  const commandToken = () => {
    const value = window.crypto?.randomUUID?.() || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`;
    return `student-${value}`;
  };

  const finalSubmission = (item) => ['submitted', 'auto_submitted', 'proxy_submitted', 'locked']
    .includes(String(item.submission?.state || ''));

  const resultReleaseId = (item) => String(item.result?.release_id || '');

  const matchesFilter = (item) => {
    const complete = finalSubmission(item);
    if (activeFilter === 'todo') return !complete && item.availability !== 'closed';
    if (activeFilter === 'submitted') return complete && !resultReleaseId(item);
    return Boolean(resultReleaseId(item));
  };

  const filterCount = (filter) => {
    const previous = activeFilter;
    activeFilter = filter;
    const count = assignments.filter(matchesFilter).length;
    activeFilter = previous;
    return count;
  };

  const renderTabs = () => {
    const tabs = create('div', 'oj-ai-student-tabs');
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', '활동지 상태');
    [
      ['todo', '할 일'],
      ['submitted', '제출함'],
      ['results', '결과'],
    ].forEach(([key, label]) => {
      const button = create('button', 'oj-ai-student-tabs__button');
      const text = create('span', '', label);
      const count = create('span', 'oj-ai-student-tabs__count', String(filterCount(key)));
      count.setAttribute('aria-hidden', 'true');
      button.type = 'button';
      button.dataset.filter = key;
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-label', label);
      button.setAttribute('aria-selected', String(key === activeFilter));
      button.setAttribute('aria-controls', 'oj-ai-student-assignment-list');
      button.tabIndex = key === activeFilter ? 0 : -1;
      button.append(text, count);
      tabs.append(button);
    });
    return tabs;
  };

  const renderCard = (item) => {
    const card = create('article', 'oj-ai-student-card');
    const top = create('div', 'oj-ai-student-card__top');
    const statusLabel = activeFilter === 'results' && resultReleaseId(item)
      ? '결과 공개' : finalSubmission(item) ? saveLabel(item.submission) : availabilityLabel(item.availability);
    const status = create('span', 'oj-ai-student-status oj-ai-student-status--' + String(item.availability || ''), statusLabel);
    const context = create('span', 'oj-ai-student-card__context', item.context?.label || '수업');
    top.append(status, context);

    const title = create('h2', 'oj-ai-student-card__title');
    const link = create('a', '', item.title || '활동지');
    link.href = assignmentUrl(item.id);
    title.append(link);

    const details = create('dl', 'oj-ai-student-card__details');
    const detailRows = [
      ['마감', formatDate(item.schedule?.due_at_utc)],
      ['상태', saveLabel(item.submission)],
    ];
    if (item.context?.subject) detailRows.unshift(['교과', item.context.subject]);
    if (item.context?.teacher) detailRows.push(['교사', item.context.teacher]);
    detailRows.forEach(([term, value]) => {
      details.append(create('dt', '', term), create('dd', '', value));
    });

    const actions = Array.isArray(item.actions) ? item.actions : [];
    const releaseId = resultReleaseId(item);
    const actionLabel = activeFilter === 'results' && releaseId
      ? '결과 확인'
      : actions.includes('assignment.continue')
        ? '작성 계속'
        : actions.includes('assignment.start') ? '활동지 열기' : item.submission ? '제출 보기' : '활동지 열기';
    const action = create('a', 'oj-ai-student-primary', actionLabel);
    action.href = activeFilter === 'results' && releaseId
      ? '/oj-addons/oj-ai-dashboard/results/' + encodeURIComponent(releaseId)
      : assignmentUrl(item.id);
    card.append(top, title, details, action);
    return card;
  };

  const renderList = () => {
    const visible = assignments.filter(matchesFilter);
    const fragment = document.createDocumentFragment();
    fragment.append(renderTabs());
    const summary = create('p', 'oj-ai-student-summary', String(visible.length) + '개');
    summary.setAttribute('aria-live', 'polite');
    fragment.append(summary);
    const grid = create('div', 'oj-ai-student-grid');
    grid.id = 'oj-ai-student-assignment-list';
    if (visible.length) visible.forEach((item) => grid.append(renderCard(item)));
    else grid.append(create('p', 'oj-ai-student-empty', activeFilter === 'results' ? '공개된 결과가 없습니다.' : '표시할 활동지가 없습니다.'));
    fragment.append(grid);
    root.replaceChildren(fragment);
  };

  const responseItems = (assignment) => {
    const payload = assignment.response_schema?.payload || {};
    const items = Array.isArray(payload.items) ? payload.items : Array.isArray(payload.responses) ? payload.responses : [];
    return items.map((item, index) => ({
      response_id: String(item.response_id || item.id || ''),
      kind: String(item.kind || item.type || 'long_text'),
      label: String(item.label || item.title || `응답 ${index + 1}`),
      required: item.required === true,
      help_text: typeof item.help_text === 'string' ? item.help_text : '',
      constraints: item.constraints && typeof item.constraints === 'object' ? item.constraints : {},
    })).filter((item) => item.response_id);
  };

  const fieldId = (responseId) => `oj-ai-response-${responseId.replace(/[^A-Za-z0-9_-]/g, '-')}`;

  const appendChoiceControl = (container, item, multiple) => {
    const options = Array.isArray(item.constraints.options) ? item.constraints.options : [];
    options.forEach((option, optionIndex) => {
      const optionId = String(option.option_id || option.value || `option-${optionIndex + 1}`);
      const label = create('label', 'oj-ai-student-choice');
      const input = create('input', 'oj-ai-student-response-input');
      input.type = multiple ? 'checkbox' : 'radio';
      input.name = `response-${item.response_id}`;
      input.value = optionId;
      const text = create('span', '', String(option.label || option.value || optionId));
      label.append(input, text);
      container.append(label);
    });
  };

  const appendTableControl = (container, item) => {
    const rows = Array.isArray(item.constraints.rows) ? item.constraints.rows : [];
    const columns = Array.isArray(item.constraints.columns) ? item.constraints.columns : [];
    const scroll = create('div', 'oj-ai-student-response-table-wrap');
    const table = create('table', 'oj-ai-student-response-table');
    const head = create('thead');
    const headRow = create('tr');
    headRow.append(create('th', '', ''));
    columns.forEach((column) => headRow.append(create('th', '', String(column.label || column.column_id || '열'))));
    head.append(headRow);
    const body = create('tbody');
    rows.forEach((row) => {
      const rowId = String(row.row_id || 'row');
      const tr = create('tr');
      tr.append(create('th', '', String(row.label || rowId)));
      columns.forEach((column) => {
        const td = create('td');
        const input = create('input', 'oj-ai-student-response-input');
        const cellType = String(column.cell_type || 'short_text');
        input.type = cellType === 'checkbox' ? 'checkbox' : 'text';
        if (cellType === 'number') input.inputMode = 'decimal';
        input.dataset.rowId = rowId;
        input.dataset.columnId = String(column.column_id || 'column');
        input.setAttribute('aria-label', `${row.label || rowId} ${column.label || column.column_id || '열'}`);
        td.append(input);
        tr.append(td);
      });
      body.append(tr);
    });
    table.append(head, body);
    scroll.append(table);
    container.append(scroll);
  };

  const renderResponseControl = (item) => {
    const fieldset = create('fieldset', 'oj-ai-student-response-anchor');
    fieldset.dataset.responseId = item.response_id;
    const id = fieldId(item.response_id);
    fieldset.dataset.responseKind = item.kind;
    fieldset.tabIndex = -1;
    const legend = create('legend', 'oj-ai-student-response-label', item.label);
    legend.id = `${id}-label`;
    if (item.required) {
      const required = create('span', 'oj-ai-student-response-required', ' *');
      required.setAttribute('aria-label', '필수');
      legend.append(required);
    }
    fieldset.append(legend);
    if (item.kind === 'short_text' || item.kind === 'number') {
      const input = create('input', 'oj-ai-student-response-input');
      input.id = id;
      input.type = 'text';
      input.setAttribute('aria-labelledby', legend.id);
      if (item.kind === 'number') input.inputMode = 'decimal';
      fieldset.append(input);
    } else if (item.kind === 'long_text') {
      const textarea = create('textarea', 'oj-ai-student-response-input');
      textarea.id = id;
      textarea.rows = 5;
      textarea.setAttribute('aria-labelledby', legend.id);
      fieldset.append(textarea);
    } else if (item.kind === 'single_choice' || item.kind === 'multiple_choice') {
      const choices = create('div', 'oj-ai-student-response-choices');
      appendChoiceControl(choices, item, item.kind === 'multiple_choice');
      fieldset.append(choices);
    } else if (item.kind === 'checkbox') {
      const label = create('label', 'oj-ai-student-choice');
      const input = create('input', 'oj-ai-student-response-input');
      input.id = id;
      input.type = 'checkbox';
      label.append(input, create('span', '', item.label));
      fieldset.append(label);
    } else if (item.kind === 'table') {
      appendTableControl(fieldset, item);
    } else if (item.kind === 'image_upload' || item.kind === 'file_upload') {
      const input = create('input', 'oj-ai-student-response-input');
      input.id = id;
      input.type = 'file';
      input.setAttribute('aria-labelledby', legend.id);
      input.disabled = true;
      input.multiple = Number(item.constraints.max_files || 1) > 1;
      if (Array.isArray(item.constraints.mime_types)) input.accept = item.constraints.mime_types.join(',');
      fieldset.append(input);
    } else if (item.kind === 'drawing') {
      const canvas = create('canvas', 'oj-ai-student-response-drawing');
      canvas.width = 720;
      canvas.height = 320;
      canvas.setAttribute('role', 'img');
      canvas.setAttribute('aria-label', item.label);
      fieldset.append(canvas);
      const toolbar = create('div', 'oj-ai-student-drawing-toolbar');
      [['undo-drawing', '되돌리기'], ['clear-drawing', '그림 지우기'], ['save-drawing', '그림 저장']].forEach(([action, label]) => {
        const button = create('button', 'oj-ai-student-secondary', label);
        button.type = 'button'; button.dataset.action = action;
        toolbar.append(button);
      });
      const status = create('span', 'oj-ai-student-response-help');
      status.dataset.role = 'drawing-status'; status.setAttribute('role', 'status');
      fieldset.append(toolbar, status);
    }
    if (item.help_text) {
      const help = create('p', 'oj-ai-student-response-help', item.help_text);
      help.id = `${id}-help`;
      fieldset.setAttribute('aria-describedby', help.id);
      fieldset.append(help);
    }
    return fieldset;
  };

  const renderSubmissionStatus = (submission) => {
    root.querySelector('[data-role="submission-status"]')?.remove();
    if (!submission || typeof submission !== 'object') return;
    const state = String(submission.state || '');
    const notice = root.querySelector('.oj-ai-student-detail__notice');
    if (notice && !notice.classList.contains('oj-ai-student-detail__notice--closed')
      && !notice.classList.contains('oj-ai-student-detail__notice--upcoming')) {
      notice.textContent = saveLabel(submission);
    }
    if (finalSubmission({ submission })) root.querySelector('.oj-ai-student-primary--start')?.remove();
    const labels = {
      draft: '작성 중입니다',
      conflict: '저장 내용을 확인해 주세요',
      submitted: '제출이 완료되었습니다',
      auto_submitted: '자동 제출되었습니다',
      proxy_submitted: '교사가 제출했습니다',
      reopened: '다시 작성할 수 있습니다',
      locked: '제출이 잠겼습니다',
    };
    const section = create('section', `oj-ai-student-submission-status oj-ai-student-submission-status--${state || 'draft'}`);
    section.dataset.role = 'submission-status';
    section.setAttribute('aria-label', '제출 상태');
    section.append(create('h3', '', labels[state] || '제출 상태'));

    const control = submission.control_receipt && typeof submission.control_receipt === 'object'
      ? submission.control_receipt
      : null;
    if (control?.reason && ['reopened', 'locked'].includes(state)) {
      section.append(create('p', 'oj-ai-student-submission-status__reason', String(control.reason)));
    }

    const details = create('dl', 'oj-ai-student-submission-status__details');
    const rows = [];
    const attempt = Number(submission.official_attempt_no || 0);
    const maximum = Number(submission.resubmission?.max_attempts || 0);
    const remaining = Number(submission.resubmission?.remaining_attempts);
    if (submission.submitted_at_utc) rows.push(['마지막 제출', formatDate(submission.submitted_at_utc)]);
    const extraAttempt = maximum > 0 && (attempt > maximum || (state === 'reopened' && attempt >= maximum));
    if (attempt > 0 && maximum > 0) rows.push(['제출 횟수', extraAttempt ? `${attempt}회 (기본 ${maximum}회)` : `${attempt} / ${maximum}회`]);
    if (state === 'reopened' && extraAttempt && remaining > 0) rows.push(['교사 추가 허용', '1회']);
    if (Number.isFinite(remaining) && maximum > 0) rows.push(['남은 제출', `${Math.max(0, remaining)}회`]);
    if (state === 'reopened' && submission.resubmission?.reopen_until_utc) {
      rows.push(['다시 제출', formatDate(submission.resubmission.reopen_until_utc)]);
    }
    if (control?.received_at_utc && ['reopened', 'locked'].includes(state)) {
      rows.push(['처리 시각', formatDate(control.received_at_utc)]);
    }
    rows.forEach(([term, value]) => details.append(create('dt', '', term), create('dd', '', value)));
    if (rows.length) section.append(details);
    if (state === 'locked') section.append(create('p', 'oj-ai-student-submission-status__help', '교사에게 문의하세요.'));
    if ((submission.actions || []).includes('submission.export_pdf')) {
      const exportBar = create('div', 'oj-ai-student-export');
      exportBar.setAttribute('aria-label', '작성 결과 PDF');
      const exportButton = create('button', 'oj-ai-student-secondary', '작성 결과 PDF 만들기');
      exportButton.type = 'button';
      exportButton.dataset.action = 'export-submission-pdf';
      const exportStatus = create('span', 'oj-ai-student-export__status');
      exportStatus.dataset.role = 'submission-pdf-status';
      exportStatus.setAttribute('role', 'status');
      exportStatus.setAttribute('aria-live', 'polite');
      exportBar.append(exportButton, exportStatus);
      section.append(exportBar);
    }

    root.dataset.submission = JSON.stringify(submission);
    const anchor = root.querySelector('[data-role="submission-save"]')
      || root.querySelector('.oj-ai-student-detail__notice')
      || root.querySelector('.oj-ai-student-detail__header');
    anchor?.after(section);
  };

  const containsResponseNode = (node) => node && typeof node === 'object' && (
    node.type === 'oj_ai_response'
    || (Array.isArray(node.children) && node.children.some(containsResponseNode))
  );
  const isQuestionNode = (node) => node.type === 'callout' && node.tone === 'notice'
    && node.icon === 'idea' && containsResponseNode(node);

  const appendTextNodes = (parent, nodes, responses, rendered) => {
    (Array.isArray(nodes) ? nodes : []).forEach((node) => {
      if (!node || typeof node !== 'object') return;
      if (typeof node.text === 'string') {
        const span = document.createElement(node.code ? 'code' : 'span');
        span.textContent = node.text;
        if (node.bold) span.style.fontWeight = '700';
        if (node.italic) span.style.fontStyle = 'italic';
        if (node.underline || node.strikethrough) span.style.textDecoration = [node.underline ? 'underline' : '', node.strikethrough ? 'line-through' : ''].filter(Boolean).join(' ');
        parent.append(span);
        return;
      }
      const type = String(node.type || 'paragraph');
      if (type === 'oj_ai_response' || type === 'response_field') {
        const responseId = String(node.response_id || node.id || '');
        const item = responses.get(responseId) || {
          response_id: responseId || `unknown-${rendered.size + 1}`,
          kind: String(node.kind || 'long_text'),
          label: String(node.label || '응답'),
          required: node.required === true,
          help_text: '',
          constraints: node.constraints && typeof node.constraints === 'object' ? node.constraints : {},
        };
        rendered.add(item.response_id);
        parent.append(renderResponseControl(item));
        return;
      }
      if (type === 'a') {
        let link;
        try { const url = new URL(node.url); if (['https:', 'http:'].includes(url.protocol) && !url.username && !url.password) link = url.href; } catch (_) { /* Invalid source links remain plain text. */ }
        const anchor = create(link ? 'a' : 'span');
        if (link) { anchor.href = link; anchor.target = '_blank'; anchor.rel = 'noopener noreferrer'; }
        appendTextNodes(anchor, node.children, responses, rendered);
        parent.append(anchor); return;
      }
      if (type === 'equation') {
        const math = create(node.display ? 'div' : 'span', 'oj-ai-student-equation');
        if (window.katex?.render) window.katex.render(String(node.tex || ''), math, {displayMode: node.display === true, throwOnError: false, trust: false, strict: 'error', maxExpand: 1000});
        else math.textContent = String(node.tex || '');
        parent.append(math); return;
      }
      if (['img', 'audio', 'video', 'file', 'oj_ai_drawing'].includes(type)) {
        const figure = create('figure', 'oj-ai-student-source');
        const label = String(node.label || node.alt || '자료');
        const media = type === 'file' ? create('button', 'oj-ai-student-secondary', label) : create(type === 'oj_ai_drawing' ? 'img' : type);
        if (media instanceof HTMLImageElement) media.alt = label;
        if (media instanceof HTMLMediaElement) { media.controls = true; media.preload = 'metadata'; media.setAttribute('aria-label', label); }
        if (type === 'file') { media.type = 'button'; media.addEventListener('click', () => loadSourceAsset(media, node, true)); }
        else Promise.resolve().then(() => loadSourceAsset(media, node));
        figure.append(media);
        if (node.transcript) figure.append(create('p', 'oj-ai-student-source__transcript', String(node.transcript)));
        if (node.description) figure.append(create('figcaption', '', String(node.description)));
        appendTextNodes(figure, node.children, responses, rendered);
        parent.append(figure); return;
      }
      if (type === 'table') {
        const scroll = create('div', 'oj-ai-student-table-scroll');
        const table = create('table');
        const widths = node.column_ratios || node.column_widths;
        if (Array.isArray(widths) && widths.length) {
          const total = widths.reduce((sum, width) => sum + width, 0);
          table.style.width = Number.isInteger(node.width_percent) ? `${node.width_percent}%` : `${total}px`; table.style.maxWidth = '100%'; table.style.tableLayout = 'fixed'; table.style.overflowWrap = 'anywhere';
          const columns = create('colgroup');
          widths.forEach((width) => { const column = create('col'); column.style.width = `${width / total * 100}%`; columns.append(column); });
          table.append(columns);
        }
        appendTextNodes(table, node.children, responses, rendered);
        scroll.append(table); parent.append(scroll); return;
      }
      if (type === 'hr' || type === 'page_break') { parent.append(create('hr')); return; }
      const tag = ({ h1: 'h2', h2: 'h3', h3: 'h4', p: 'p', paragraph: 'p', blockquote: 'blockquote', ul: 'ul', ol: 'ol', li: 'li' })[type] || 'p';
      const isQuestion = isQuestionNode(node);
      const element = create(isQuestion ? 'section' : (({tr: 'tr', td: 'td', th: 'th', code_block: 'pre', callout: 'aside', column_group: 'div', column: 'div', caption: 'figcaption'})[type] || tag), isQuestion ? 'oj-ai-student-document__question' : `oj-ai-student-document__${type.replace(/[^a-z0-9_-]/gi, '')}`);
      appendTextNodes(element, node.children, responses, rendered);
      parent.append(element);
    });
  };

  const loadSourceAsset = async (element, node, download = false) => {
    const generation = documentGeneration;
    if (!/^[0-9A-HJKMNP-TV-Z]{26}$/.test(String(node.asset_id || ''))) return;
    if (download) element.disabled = true;
    try {
      await readyNonce;
      let response = await fetchOnce(`/me/assignments/${encodeURIComponent(assignmentId)}/assets/${encodeURIComponent(node.asset_id)}/content`, {cache: 'no-store'});
      if ((response.status === 401 || response.status === 403) && await refreshNonce()) response = await fetchOnce(`/me/assignments/${encodeURIComponent(assignmentId)}/assets/${encodeURIComponent(node.asset_id)}/content`, {cache: 'no-store'});
      if (!response.ok) throw new Error('자료를 불러오지 못했습니다.');
      let blob = await response.blob();
      if (generation !== documentGeneration || !root.contains(element)) return;
      const mime = String(response.headers?.get('X-OJ-AI-File-Type') || blob.type).split(';')[0];
      if (!download && !({img: ['image/png','image/jpeg','image/webp'], audio: ['audio/mpeg','audio/ogg','audio/wav','audio/x-wav'], video: ['video/mp4','video/webm'], oj_ai_drawing: ['image/png','image/jpeg','image/webp']})[node.type]?.includes(mime)) throw new Error('자료 형식을 확인할 수 없습니다.');
      if (!download) blob = blob.slice(0, blob.size, mime);
      const url = URL.createObjectURL(blob); assetUrls.add(url);
      if (download) {
        const link = create('a'); link.href = url; link.download = String(node.label || '자료').replace(/[\\/:*?"<>|]/g, '_');
        link.click(); URL.revokeObjectURL(url); assetUrls.delete(url);
      } else element.src = url;
    } catch (error) {
      if (generation === documentGeneration && root.contains(element)) {
        const message = element.parentElement.querySelector('[data-source-error]') || create('p', 'oj-ai-student-response-help');
        message.dataset.sourceError = 'true'; message.setAttribute('role', 'alert'); message.textContent = '자료를 불러오지 못했습니다. 새로고침해 주세요.';
        element.parentElement.append(message);
      }
    } finally { if (download) element.disabled = false; }
  };

  const responseAnswered = (fieldset) => fieldset.dataset.answered === 'true' || Array.from(fieldset.querySelectorAll('input, textarea, select')).some((field) => {
    if (field instanceof HTMLInputElement && ['checkbox', 'radio'].includes(field.type)) return field.checked;
    if (field instanceof HTMLInputElement && field.type === 'file') return Boolean(field.files?.length);
    return String(field.value || '').trim() !== '';
  });

  const updateQuestionProgress = (responseId = '') => {
    const fields = Array.from(root.querySelectorAll('.oj-ai-student-response-anchor'));
    const completed = fields.filter(responseAnswered).length;
    const status = root.querySelector('[data-role="question-progress"]');
    if (status) status.textContent = String(completed) + ' / ' + String(fields.length) + ' 완료';
    root.querySelectorAll('[data-action="jump-response"]').forEach((button) => {
      const field = fields.find((item) => item.dataset.responseId === button.dataset.responseId);
      button.dataset.complete = field && responseAnswered(field) ? 'true' : 'false';
      button.setAttribute('aria-current', responseId && button.dataset.responseId === responseId ? 'true' : 'false');
    });
  };

  const renderQuestionNavigation = (items) => {
    const nav = create('nav', 'oj-ai-student-question-nav');
    nav.setAttribute('aria-label', '문항 이동');
    const header = create('div', 'oj-ai-student-question-nav__header');
    header.append(create('h3', '', '문항'));
    const progress = create('span', '', '0 / ' + String(items.length) + ' 완료');
    progress.dataset.role = 'question-progress';
    progress.setAttribute('role', 'status');
    header.append(progress);
    const list = create('div', 'oj-ai-student-question-nav__list');
    items.forEach((item, index) => {
      const button = create('button', 'oj-ai-student-question-nav__button', String(index + 1));
      button.type = 'button';
      button.dataset.action = 'jump-response';
      button.dataset.responseId = item.response_id;
      button.setAttribute('aria-label', String(index + 1) + '번 ' + item.label);
      list.append(button);
    });
    nav.append(header, list);
    return nav;
  };

  const renderDetail = (assignment) => {
    documentGeneration += 1;
    assetUrls.forEach((url) => URL.revokeObjectURL(url)); assetUrls.clear();
    const fragment = document.createDocumentFragment();
    const back = create('a', 'oj-ai-student-back', '활동지 목록');
    back.href = '/oj-addons/oj-ai-dashboard/worksheets';
    fragment.append(back);

    const header = create('div', 'oj-ai-student-detail__header');
    const heading = create('h2', '', assignment.title || '활동지');
    const meta = create('p', '', (assignment.context?.label || '수업') + ' · ' + formatDate(assignment.schedule?.due_at_utc) + ' 마감');
    header.append(heading, meta);
    fragment.append(header);

    const notice = create('p', 'oj-ai-student-detail__notice oj-ai-student-detail__notice--' + String(assignment.availability || ''));
    notice.setAttribute('role', 'status');
    notice.textContent = assignment.availability === 'upcoming'
      ? formatDate(assignment.schedule?.opens_at_utc) + '부터 시작할 수 있습니다.'
      : assignment.availability === 'closed' ? '작성 시간이 종료되었습니다.' : saveLabel(assignment.submission);
    fragment.append(notice);
    if ((assignment.actions || []).includes('assignment.export_pdf')) {
      const exportBar = create('div', 'oj-ai-student-export');
      exportBar.setAttribute('aria-label', '활동지 PDF');
      const exportButton = create('button', 'oj-ai-student-secondary', 'PDF 만들기');
      exportButton.type = 'button';
      exportButton.dataset.action = 'export-pdf';
      const exportStatus = create('span', 'oj-ai-student-export__status');
      exportStatus.dataset.role = 'worksheet-pdf-status';
      exportStatus.setAttribute('role', 'status');
      exportStatus.setAttribute('aria-live', 'polite');
      exportBar.append(exportButton, exportStatus);
      fragment.append(exportBar);
    }

    if (assignment.student_document?.payload?.nodes) {
      const items = responseItems(assignment);
      const workspace = create('div', 'oj-ai-student-detail__workspace');
      workspace.append(renderQuestionNavigation(items));
      const documentView = create('article', 'oj-ai-student-document');
      const responses = new Map(items.map((item) => [item.response_id, item]));
      const rendered = new Set();
      appendTextNodes(documentView, assignment.student_document.payload.nodes, responses, rendered);
      items.filter((item) => !rendered.has(item.response_id)).forEach((item) => documentView.append(renderResponseControl(item)));
      workspace.append(documentView);
      fragment.append(workspace);
    }

    if ((assignment.actions || []).includes('assignment.start')) {
      const button = create('button', 'oj-ai-student-primary oj-ai-student-primary--start', '시작');
      button.type = 'button';
      button.dataset.action = 'start';
      fragment.append(button);
    } else if ((assignment.actions || []).includes('assignment.continue')) {
      const button = create('button', 'oj-ai-student-primary oj-ai-student-primary--start', '작성 계속');
      button.type = 'button';
      button.dataset.action = 'continue';
      fragment.append(button);
    }
    root.replaceChildren(fragment);
    root.dataset.assignment = JSON.stringify(assignment);
    updateQuestionProgress();
    root.dispatchEvent(new CustomEvent('oj-ai:student-assignment-detail', { detail: { assignment } }));
    window.setTimeout(() => updateQuestionProgress(), 0);
  };

  const loadList = async () => {
    try {
      const loaded = [];
      const cursors = new Set();
      let cursor = '';
      do {
        const body = await request('/me/assignments?limit=100' + (cursor ? '&cursor=' + encodeURIComponent(cursor) : ''));
        if (!Array.isArray(body.data?.items)) throw new Error('활동지 목록을 확인할 수 없습니다.');
        loaded.push(...body.data.items);
        cursor = body.meta?.next_cursor || '';
        if (cursor && cursors.has(cursor)) throw new Error('활동지 목록을 다시 불러와 주세요.');
        cursors.add(cursor);
      } while (cursor);
      assignments = loaded;
      const preferred = ['todo', 'submitted', 'results'].find((filter) => {
        activeFilter = filter;
        return assignments.some(matchesFilter);
      });
      activeFilter = preferred || 'todo';
      renderList();
    } catch (error) {
      root.replaceChildren(create('p', 'oj-ai-student-error', error.message));
    }
  };

  const loadDetail = async () => {
    try {
      const body = await request(`/me/assignments/${encodeURIComponent(assignmentId)}`);
      renderDetail(body.data?.assignment || {});
    } catch (error) {
      root.replaceChildren(create('p', 'oj-ai-student-error', error.message));
    }
  };

  const startSubmission = async (button) => {
    const assignment = JSON.parse(root.dataset.assignment || '{}');
    const token = commandToken();
    button.disabled = true;
    button.textContent = '시작 중...';
    try {
      await request(`/me/assignments/${encodeURIComponent(assignment.id)}/commands/start`, {
        method: 'POST',
        headers: { 'Idempotency-Key': token },
        body: JSON.stringify({
          command_id: token,
          expected: {
            expected_state: 'open',
            expected_lock_version: assignment.lock_version,
            expected_schedule_version: assignment.schedule_version,
            expected_schedule_hash: assignment.schedule_hash,
            expected_publication_hash: assignment.publication_hash,
          },
          payload: {},
        }),
      });
      await loadDetail();
    } catch (error) {
      button.disabled = false;
      button.textContent = '다시 시작';
      const message = create('p', 'oj-ai-student-error', error.message);
      message.setAttribute('role', 'alert');
      button.before(message);
    }
  };
  const waitForWorksheetPdf = async (jobId) => {
    for (let attempt = 0; attempt < 40; attempt += 1) {
      const body = await request(`/export-jobs/${encodeURIComponent(jobId)}`);
      const job = body.data?.export_job || {};
      if (job.state === 'ready' && job.artifact_id) return job;
      if (['failed', 'cancelled', 'expired'].includes(job.state)) throw new Error('PDF를 만들지 못했습니다.');
      await new Promise((resolve) => window.setTimeout(resolve, 500));
    }
    throw new Error('PDF 준비가 지연되고 있습니다.');
  };

  const exportWorksheetPdf = async (button) => {
    const assignment = JSON.parse(root.dataset.assignment || '{}');
    const status = button.closest('.oj-ai-student-export')?.querySelector('[data-role="worksheet-pdf-status"]');
    const token = commandToken();
    button.disabled = true;
    button.textContent = 'PDF 준비 중';
    if (status) status.textContent = '';
    try {
      const body = await request(`/me/assignments/${encodeURIComponent(assignment.id)}/commands/export-pdf`, {
        method: 'POST',
        headers: { 'Idempotency-Key': token },
        body: JSON.stringify({
          command_id: token,
          expected: {
            expected_assignment_lock_version: assignment.lock_version,
            expected_assignment_state: assignment.state,
            expected_context_revision: assignment.context?.revision,
            expected_publication_id: assignment.worksheet_publication_id,
            expected_publication_hash: assignment.publication_hash,
            expected_target_state: 'active',
          },
          payload: { profile: 'student_blank' },
        }),
      });
      const job = body.data?.export_job || {};
      if (!job.id) throw new Error('PDF 작업을 확인하지 못했습니다.');
      const ready = job.state === 'ready' && job.artifact_id ? job : await waitForWorksheetPdf(job.id);
      button.dataset.action = 'download-pdf';
      button.dataset.artifactId = ready.artifact_id;
      button.disabled = false;
      button.textContent = 'PDF 다운로드';
      if (status) status.textContent = '준비됨';
    } catch (error) {
      button.disabled = false;
      button.textContent = '다시 시도';
      if (status) status.textContent = error.message;
    }
  };

  const exportSubmissionPdf = async (button) => {
    const assignment = JSON.parse(root.dataset.assignment || '{}');
    const submission = JSON.parse(root.dataset.submission || '{}');
    const official = submission.official_revision || {};
    const status = button.closest('.oj-ai-student-export')?.querySelector('[data-role="submission-pdf-status"]');
    const token = commandToken();
    button.disabled = true;
    button.textContent = 'PDF 준비 중';
    if (status) status.textContent = '';
    try {
      const body = await request(`/me/submissions/${encodeURIComponent(submission.id)}/commands/export-pdf`, {
        method: 'POST',
        headers: { 'Idempotency-Key': token },
        body: JSON.stringify({
          command_id: token,
          expected: {
            expected_assignment_id: submission.assignment_id,
            expected_assignment_state: assignment.state,
            expected_attachment_set_hash: official.attachment_set_hash,
            expected_context_revision: assignment.context?.revision,
            expected_official_revision_hash: official.content_hash,
            expected_official_revision_id: official.id,
            expected_publication_hash: submission.source?.publication_hash,
            expected_publication_id: submission.source?.worksheet_publication_id,
            expected_submission_lock_version: submission.lock_version,
            expected_submission_state: submission.state,
            expected_target_state: 'active',
          },
          payload: { profile: 'teacher_submission' },
        }),
      });
      const job = body.data?.export_job || {};
      if (!job.id) throw new Error('PDF 작업을 확인하지 못했습니다.');
      const ready = job.state === 'ready' && job.artifact_id ? job : await waitForWorksheetPdf(job.id);
      button.dataset.action = 'download-submission-pdf';
      button.dataset.artifactId = ready.artifact_id;
      button.disabled = false;
      button.textContent = '작성 결과 PDF 다운로드';
      if (status) status.textContent = '준비됨';
    } catch (error) {
      button.disabled = false;
      button.textContent = '다시 시도';
      if (status) status.textContent = error.message;
    }
  };

  const downloadWorksheetPdf = async (button) => {
    const artifactId = button.dataset.artifactId || '';
    const status = button.closest('.oj-ai-student-export')?.querySelector('[data-role="worksheet-pdf-status"]');
    button.disabled = true;
    try {
      const response = await fetch(`${apiBase}/artifacts/${encodeURIComponent(artifactId)}/download`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf', 'X-WP-Nonce': nonce },
      });
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}));
        throw new Error(problem.message || 'PDF를 내려받지 못했습니다.');
      }
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `worksheet-${assignmentId}.pdf`;
      document.body.append(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      if (status) status.textContent = '다운로드됨';
    } catch (error) {
      if (status) status.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  };

  const downloadSubmissionPdf = async (button) => {
    const submission = JSON.parse(root.dataset.submission || '{}');
    const artifactId = button.dataset.artifactId || '';
    const status = button.closest('.oj-ai-student-export')?.querySelector('[data-role="submission-pdf-status"]');
    button.disabled = true;
    try {
      const response = await fetch(`${apiBase}/artifacts/${encodeURIComponent(artifactId)}/download`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf', 'X-WP-Nonce': nonce },
      });
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}));
        throw new Error(problem.message || 'PDF를 내려받지 못했습니다.');
      }
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `submission-${submission.id || 'result'}.pdf`;
      document.body.append(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      if (status) status.textContent = '다운로드됨';
    } catch (error) {
      if (status) status.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  };


  root.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('button') : null;
    if (!target) return;
    if (target.dataset.filter) {
      activeFilter = target.dataset.filter;
      renderList();
      root.querySelector(`[data-filter="${activeFilter}"]`)?.focus();
    } else if (target.dataset.action === 'jump-response') {
      const field = root.querySelector('[data-response-id="' + CSS.escape(target.dataset.responseId || '') + '"]');
      if (field) {
        updateQuestionProgress(target.dataset.responseId || '');
        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        field.focus();
      }
    } else if (target.dataset.action === 'start') {
      startSubmission(target);
    } else if (target.dataset.action === 'continue') {
      const responseField = root.querySelector('.oj-ai-student-response-anchor');
      if (responseField) {
        responseField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        responseField.focus();
      }
    } else if (target.dataset.action === 'export-pdf') {
      exportWorksheetPdf(target);
    } else if (target.dataset.action === 'download-pdf') {
      downloadWorksheetPdf(target);
    } else if (target.dataset.action === 'export-submission-pdf') {
      exportSubmissionPdf(target);
    } else if (target.dataset.action === 'download-submission-pdf') {
      downloadSubmissionPdf(target);
    }
  });

  root.addEventListener('input', () => updateQuestionProgress());
  root.addEventListener('change', () => updateQuestionProgress());

  root.addEventListener(SUBMISSION_STATUS_EVENT, (event) => {
    renderSubmissionStatus(event.detail?.submission);
  });

  root.addEventListener('keydown', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('[role="tab"][data-filter]') : null;
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const filters = ['todo', 'submitted', 'results'];
    const current = Math.max(0, filters.indexOf(tab.dataset.filter));
    let next = current;
    if (event.key === 'ArrowLeft') next = (current - 1 + filters.length) % filters.length;
    if (event.key === 'ArrowRight') next = (current + 1) % filters.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = filters.length - 1;
    event.preventDefault();
    activeFilter = filters[next];
    renderList();
    root.querySelector(`[data-filter="${activeFilter}"]`)?.focus();
  });

  if (mode === 'detail' && assignmentId) loadDetail();
  else loadList();
})();
