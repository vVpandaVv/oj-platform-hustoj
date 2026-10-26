(() => {
  'use strict';

  const root = document.getElementById('oj-ai-student-result-app');
  if (!root) return;

  const releaseId = root.dataset.releaseId || '';
  const apiBase = root.dataset.restRoot
    || `${String(window.wpApiSettings?.root || '/wp-json/').replace(/\/$/, '')}/oj-ai/v1`;
  let nonce = root.dataset.restNonce || window.wpApiSettings?.nonce || '';
  const requestedLocale = String(root.dataset.locale || document.documentElement.lang || navigator.language || 'ko-KR').replace(/_/g, '-');
  const locale = /^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/.test(requestedLocale) ? requestedLocale : 'ko-KR';
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
  const visibleStates = new Set(['released', 'corrected']);
  const exportStates = new Set(['queued', 'running', 'ready', 'failed', 'cancelled', 'expired']);
  const forbiddenKeys = new Set([
    'answer', 'answer_key', 'binding_key', 'confidence', 'criterion_id', 'model_key',
    'provider_key', 'reason', 'reason_code', 'reason_note', 'response_id', 'rubric',
    'safety_flags', 'student_note', 'teacher_memo',
  ]);
  let requestNumber = 0;
  let pdfRunNumber = 0;
  let activeResult = null;
  let activePdfJob = null;

  const create = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  };

  const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
  const hasExactKeys = (value, keys) => isObject(value)
    && JSON.stringify(Object.keys(value).sort()) === JSON.stringify([...keys].sort());
  const isText = (value) => typeof value === 'string' && value.trim() !== '';
  const isDecimal = (value) => typeof value === 'string' && /^(?:0|[1-9][0-9]{0,7})\.[0-9]{4}$/.test(value);

  const containsForbiddenKey = (value) => {
    if (!value || typeof value !== 'object') return false;
    return Object.entries(value).some(([key, item]) => forbiddenKeys.has(key.toLowerCase()) || containsForbiddenKey(item));
  };

  const validProjection = (projection, state, releasedAt) => {
    if (!hasExactKeys(projection, ['feedback', 'release', 'schema_id', 'schema_version', 'score', 'worksheet'])) return false;
    if (projection.schema_id !== 'oj_ai.student_release_projection' || projection.schema_version !== '1.0.0') return false;
    if (containsForbiddenKey(projection)) return false;

    const projectedRelease = projection.release;
    const worksheet = projection.worksheet;
    const score = projection.score;
    const feedback = projection.feedback;
    if (!hasExactKeys(projectedRelease, ['released_at_utc', 'state'])
      || projectedRelease.state !== state || projectedRelease.released_at_utc !== releasedAt) return false;
    if (!hasExactKeys(worksheet, ['title']) || !isText(worksheet.title)) return false;
    if (!hasExactKeys(score, ['criteria', 'total', 'visibility'])
      || !['total', 'total_and_criteria', 'hidden'].includes(score.visibility)
      || !Array.isArray(score.criteria)) return false;
    if (score.visibility === 'hidden' && (score.total !== null || score.criteria.length !== 0)) return false;
    if (score.visibility === 'total' && (!isDecimal(score.total) || score.criteria.length !== 0)) return false;
    if (score.visibility === 'total_and_criteria' && (!isDecimal(score.total) || score.criteria.length === 0)) return false;
    if (!score.criteria.every((item) => hasExactKeys(item, ['label', 'maximum_points', 'points'])
      && isText(item.label) && isDecimal(item.maximum_points) && isDecimal(item.points))) return false;

    if (!hasExactKeys(feedback, ['criteria', 'next_steps', 'overall_feedback', 'visibility'])
      || !['shown', 'hidden'].includes(feedback.visibility)
      || !Array.isArray(feedback.criteria) || !Array.isArray(feedback.next_steps)) return false;
    if (feedback.visibility === 'hidden'
      && (feedback.criteria.length !== 0 || feedback.next_steps.length !== 0 || feedback.overall_feedback !== null)) return false;
    if (feedback.visibility === 'shown') {
      if (!isText(feedback.overall_feedback) || feedback.criteria.length === 0) return false;
      if (!feedback.criteria.every((item) => hasExactKeys(item, ['label', 'next_step', 'strength'])
        && isText(item.label) && isText(item.next_step) && isText(item.strength))) return false;
      if (!feedback.next_steps.every(isText)) return false;
    }
    return !(score.visibility === 'hidden' && feedback.visibility === 'hidden');
  };

  const validResult = (result) => {
    if (!hasExactKeys(result, ['notice', 'projection', 'release'])) return false;
    const release = result.release;
    if (!hasExactKeys(release, ['hash', 'id', 'released_at_utc', 'state'])
      || !/^[0-9A-HJKMNP-TV-Z]{26}$/.test(release.id)
      || !/^[a-f0-9]{64}$/.test(release.hash)
      || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(release.released_at_utc)
      || !['released', 'corrected', 'stale', 'withdrawn'].includes(release.state)) return false;
    if (release.id !== releaseId) return false;

    if (visibleStates.has(release.state)) {
      return result.notice === null && validProjection(result.projection, release.state, release.released_at_utc);
    }

    const expectedNotice = release.state === 'stale' ? 'result_updating' : 'result_withdrawn';
    return result.projection === null
      && hasExactKeys(result.notice, ['code', 'message'])
      && result.notice.code === expectedNotice
      && isText(result.notice.message);
  };

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

  const fetchOnce = (path, options) => {
    const headers = {
      Accept: 'application/json',
      'X-WP-Nonce': nonce,
      ...(options.headers || {}),
    };
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';
    return fetch(`${apiBase}${path}`, {
      credentials: 'same-origin',
      ...options,
      headers,
    });
  };

  const jsonRequest = async (path, options = {}) => {
    await readyNonce;
    let response = await fetchOnce(path, options);
    if ((response.status === 401 || response.status === 403) && (await refreshNonce())) {
      response = await fetchOnce(path, options);
    }
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(isText(body?.message) ? body.message : '요청을 처리하지 못했습니다.');
      error.status = response.status;
      throw error;
    }
    return body;
  };

  const requestResult = () => jsonRequest(`/me/results/${encodeURIComponent(releaseId)}`);
  const commandToken = () => {
    const value = window.crypto?.randomUUID?.() || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`;
    return `result-pdf-${value}`;
  };
  const cancelPdf = () => {
    pdfRunNumber += 1;
    activePdfJob = null;
  };

  const points = (value) => String(value).replace(/\.0{4}$/, '').replace(/(\.\d*?[1-9])0+$/, '$1');
  const releasedAt = (value) => {
    const date = new Date(`${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(date);
  };
  const isPublicId = (value) => typeof value === 'string' && /^[0-9A-HJKMNP-TV-Z]{26}$/.test(value);
  const isUtc = (value) => typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value);
  const nullableUtc = (value) => value === null || isUtc(value);
  const nullableCode = (value) => value === null || (typeof value === 'string' && /^[a-z0-9_.-]{1,120}$/.test(value));

  const validEnqueueJob = (job, result) => hasExactKeys(job, ['artifact_kind', 'id', 'source_hash', 'source_id', 'source_type', 'state'])
    && isPublicId(job.id)
    && exportStates.has(job.state)
    && job.artifact_kind === 'result_pdf'
    && job.source_type === 'submission_release'
    && job.source_id === result.release.id
    && job.source_hash === result.release.hash;

  const validExportJob = (job, result) => {
    if (!hasExactKeys(job, [
      'artifact_id', 'artifact_kind', 'error_code', 'expires_at_utc', 'finished_at_utc', 'job_id',
      'profile_version', 'queued_at_utc', 'source_hash', 'source_id', 'source_type', 'started_at_utc', 'state',
    ])) return false;
    if (!isPublicId(job.job_id) || !exportStates.has(job.state)
      || job.artifact_kind !== 'result_pdf' || job.source_type !== 'submission_release'
      || job.source_id !== result.release.id || job.source_hash !== result.release.hash
      || job.profile_version !== 'result_print.v1' || !isUtc(job.queued_at_utc)
      || !nullableUtc(job.started_at_utc) || !nullableUtc(job.finished_at_utc) || !nullableCode(job.error_code)) return false;
    if (job.state === 'ready') return isPublicId(job.artifact_id) && isUtc(job.expires_at_utc);
    if (job.artifact_id !== null) return false;
    return job.expires_at_utc === null || (job.state === 'expired' && isUtc(job.expires_at_utc));
  };

  const pdfParts = () => ({
    button: root.querySelector('[data-role="result-pdf-button"]'),
    status: root.querySelector('[data-role="result-pdf-status"]'),
  });
  const setPdfUi = (action, label, statusText = '', disabled = false, artifactId = '') => {
    const parts = pdfParts();
    if (!(parts.button instanceof HTMLButtonElement) || !(parts.status instanceof HTMLElement)) return;
    parts.button.dataset.action = action;
    if (artifactId) parts.button.dataset.artifactId = artifactId;
    else delete parts.button.dataset.artifactId;
    parts.button.textContent = label;
    parts.button.disabled = disabled;
    parts.status.textContent = statusText;
  };
  const showPdfJob = (job, run) => {
    if (run !== pdfRunNumber) return;
    if (job.state === 'ready') {
      activePdfJob = { id: job.job_id, artifactId: job.artifact_id, sourceHash: job.source_hash };
      setPdfUi('download-result-pdf', 'PDF 다운로드', '준비됨', false, job.artifact_id);
    } else if (job.state === 'expired') {
      activePdfJob = null;
      setPdfUi('create-result-pdf', '다시 만들기', 'PDF가 만료되었습니다.');
    } else if (['failed', 'cancelled'].includes(job.state)) {
      activePdfJob = null;
      setPdfUi('create-result-pdf', '다시 만들기', 'PDF를 만들지 못했습니다.');
    }
  };
  const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));
  const waitForPdf = async (jobId, result, run) => {
    for (let attempt = 0; attempt < 40; attempt += 1) {
      const body = await jsonRequest(`/export-jobs/${encodeURIComponent(jobId)}`);
      if (run !== pdfRunNumber) return null;
      const job = body?.data?.export_job;
      if (!validExportJob(job, result) || job.job_id !== jobId) {
        const error = new Error('PDF 작업 응답이 올바르지 않습니다.');
        error.contract = true;
        throw error;
      }
      activePdfJob = { id: job.job_id, artifactId: job.artifact_id, sourceHash: job.source_hash };
      if (!['queued', 'running'].includes(job.state)) return job;
      await wait(500);
      if (run !== pdfRunNumber) return null;
    }
    const error = new Error('PDF 준비가 지연되고 있습니다.');
    error.delayed = true;
    throw error;
  };
  const refreshAfterPdfAccessChange = (run) => {
    if (run !== pdfRunNumber) return;
    loadResult();
  };
  const handlePdfError = (error, run, canResume = false) => {
    if (run !== pdfRunNumber) return;
    const status = Number(error?.status || 0);
    if ([401, 403, 404, 409].includes(status)) {
      refreshAfterPdfAccessChange(run);
    } else if (status === 410) {
      activePdfJob = null;
      setPdfUi('create-result-pdf', '다시 만들기', 'PDF가 만료되었습니다.');
    } else if (canResume && !error?.contract && activePdfJob?.id) {
      setPdfUi('resume-result-pdf', '다시 확인', error?.message || 'PDF 상태를 확인하지 못했습니다.');
    } else {
      activePdfJob = null;
      setPdfUi('create-result-pdf', '다시 시도', error?.message || 'PDF를 만들지 못했습니다.');
    }
  };

  const continuePdf = async (result, run, jobId) => {
    try {
      const job = await waitForPdf(jobId, result, run);
      if (job) showPdfJob(job, run);
    } catch (error) {
      handlePdfError(error, run, true);
    }
  };
  const createPdf = async () => {
    const result = activeResult;
    if (!result || !visibleStates.has(result.release.state)) return;
    const run = ++pdfRunNumber;
    activePdfJob = null;
    setPdfUi('create-result-pdf', 'PDF 준비 중', '만드는 중...', true);
    const token = commandToken();
    try {
      const body = await jsonRequest(`/me/results/${encodeURIComponent(result.release.id)}/commands/export-pdf`, {
        method: 'POST',
        headers: { 'Idempotency-Key': token },
        body: JSON.stringify({
          command_id: token,
          expected: {
            expected_release_hash: result.release.hash,
            expected_release_state: result.release.state,
          },
          payload: { locale, profile: 'student_result' },
        }),
      });
      if (run !== pdfRunNumber) return;
      const job = body?.data?.export_job;
      if (!validEnqueueJob(job, result)) {
        const error = new Error('PDF 작업을 확인하지 못했습니다.');
        error.contract = true;
        throw error;
      }
      activePdfJob = { id: job.id, artifactId: null, sourceHash: job.source_hash };
      await continuePdf(result, run, job.id);
    } catch (error) {
      handlePdfError(error, run, Boolean(activePdfJob?.id));
    }
  };
  const resumePdf = async () => {
    const result = activeResult;
    const job = activePdfJob;
    if (!result || !job?.id || job.sourceHash !== result.release.hash) return;
    const run = ++pdfRunNumber;
    setPdfUi('resume-result-pdf', 'PDF 준비 중', '확인 중...', true);
    await continuePdf(result, run, job.id);
  };
  const downloadPdf = async (button) => {
    const result = activeResult;
    const artifactId = button.dataset.artifactId || '';
    if (!result || !isPublicId(artifactId) || activePdfJob?.sourceHash !== result.release.hash) return;
    const run = ++pdfRunNumber;
    setPdfUi('download-result-pdf', '다운로드 중', '', true, artifactId);
    try {
      const response = await fetch(`${apiBase}/artifacts/${encodeURIComponent(artifactId)}/download`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf', 'X-WP-Nonce': nonce },
      });
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}));
        const error = new Error(isText(problem?.message) ? problem.message : 'PDF를 내려받지 못했습니다.');
        error.status = response.status;
        throw error;
      }
      const mime = String(response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
      const kind = String(response.headers.get('X-OJ-Artifact-Kind') || '');
      const sha256 = String(response.headers.get('X-OJ-Content-SHA256') || '').toLowerCase();
      const lengthValue = String(response.headers.get('Content-Length') || '');
      if (mime !== 'application/pdf' || kind !== 'result_pdf' || !/^[a-f0-9]{64}$/.test(sha256) || !/^[1-9][0-9]*$/.test(lengthValue)) {
        throw new Error('PDF 응답을 확인하지 못했습니다.');
      }
      const expectedBytes = Number(lengthValue);
      const blob = await response.blob();
      if (run !== pdfRunNumber) return;
      if (!Number.isSafeInteger(expectedBytes) || expectedBytes > 100 * 1024 * 1024 || blob.size !== expectedBytes
        || (blob.type && blob.type.toLowerCase() !== 'application/pdf')) throw new Error('PDF 파일을 확인하지 못했습니다.');
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `result-${result.release.id}.pdf`;
      document.body.append(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      setPdfUi('download-result-pdf', 'PDF 다운로드', '다운로드됨', false, artifactId);
    } catch (error) {
      const status = Number(error?.status || 0);
      if ([401, 403, 404, 409, 410].includes(status)) {
        handlePdfError(error, run, false);
      } else if (run === pdfRunNumber) {
        setPdfUi('download-result-pdf', '다시 다운로드', error?.message || 'PDF를 내려받지 못했습니다.', false, artifactId);
      }
    }
  };

  const retryButton = () => {
    const button = create('button', 'oj-ai-student-secondary', '다시 불러오기');
    button.type = 'button';
    button.dataset.action = 'reload-result';
    return button;
  };

  const renderLoading = () => {
    root.replaceChildren();
    root.dataset.state = 'loading';
    root.setAttribute('aria-busy', 'true');
    const status = create('p', 'oj-ai-student-page__status', '불러오는 중...');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    root.append(status);
  };

  const renderMessage = (state, title, detail = '', retry = false) => {
    root.replaceChildren();
    root.dataset.state = state;
    root.setAttribute('aria-busy', 'false');
    const panel = create('section', `oj-ai-result-message oj-ai-result-message--${state}`);
    panel.setAttribute('role', state === 'unavailable' ? 'alert' : 'status');
    panel.setAttribute('aria-live', 'polite');
    panel.append(create('h2', 'oj-ai-result-message__title', title));
    if (detail) panel.append(create('p', 'oj-ai-result-message__detail', detail));
    if (retry) panel.append(retryButton());
    root.append(panel);
  };

  const renderScore = (score) => {
    const section = create('section', 'oj-ai-result-section oj-ai-result-score-section');
    const title = create('h3', 'oj-ai-result-section__title', '점수');
    title.id = 'oj-ai-result-score-title';
    section.setAttribute('aria-labelledby', title.id);
    section.append(title);

    if (score.total !== null) {
      const total = create('p', 'oj-ai-result-total');
      total.append(create('span', 'oj-ai-result-total__label', '총점'));
      total.append(create('strong', 'oj-ai-result-total__value', points(score.total)));
      section.append(total);
    }
    if (score.criteria.length > 0) {
      const list = create('dl', 'oj-ai-result-score-list');
      score.criteria.forEach((criterion) => {
        const row = create('div', 'oj-ai-result-score-list__row');
        row.append(create('dt', '', criterion.label));
        row.append(create('dd', '', `${points(criterion.points)} / ${points(criterion.maximum_points)}`));
        list.append(row);
      });
      section.append(list);
    }
    return section;
  };

  const renderFeedback = (feedback) => {
    const section = create('section', 'oj-ai-result-section oj-ai-result-feedback');
    const title = create('h3', 'oj-ai-result-section__title', '피드백');
    title.id = 'oj-ai-result-feedback-title';
    section.setAttribute('aria-labelledby', title.id);
    section.append(title);

    const overall = create('div', 'oj-ai-result-feedback__overall');
    overall.append(create('h4', '', '종합 의견'));
    overall.append(create('p', '', feedback.overall_feedback));
    section.append(overall);

    const criteria = create('div', 'oj-ai-result-feedback-list');
    feedback.criteria.forEach((item) => {
      const article = create('article', 'oj-ai-result-feedback-item');
      article.append(create('h4', '', item.label));
      const strength = create('p');
      strength.append(create('strong', '', '잘한 점'));
      strength.append(document.createTextNode(` ${item.strength}`));
      const next = create('p');
      next.append(create('strong', '', '다음'));
      next.append(document.createTextNode(` ${item.next_step}`));
      article.append(strength, next);
      criteria.append(article);
    });
    section.append(criteria);

    if (feedback.next_steps.length > 0) {
      const nextSteps = create('div', 'oj-ai-result-next-steps');
      nextSteps.append(create('h4', '', '다음 학습'));
      const list = create('ul');
      feedback.next_steps.forEach((step) => list.append(create('li', '', step)));
      nextSteps.append(list);
      section.append(nextSteps);
    }
    return section;
  };

  const renderResult = (result) => {
    const { projection, release } = result;
    root.replaceChildren();
    root.dataset.state = release.state;
    root.setAttribute('aria-busy', 'false');

    const article = create('article', 'oj-ai-result');
    const header = create('header', 'oj-ai-result__header');
    const heading = create('h2', 'oj-ai-result__title', projection.worksheet.title);
    heading.tabIndex = -1;
    const meta = create('div', 'oj-ai-result__meta');
    const badge = create('span', `oj-ai-result-badge oj-ai-result-badge--${release.state}`, release.state === 'corrected' ? '수정된 결과' : '공개된 결과');
    const time = create('time', 'oj-ai-result__time', releasedAt(release.released_at_utc));
    time.dateTime = `${release.released_at_utc.replace(' ', 'T')}Z`;
    meta.append(badge, time);
    header.append(heading, meta);
    article.append(header);

    if (projection.score.visibility !== 'hidden') article.append(renderScore(projection.score));
    if (projection.feedback.visibility === 'shown') article.append(renderFeedback(projection.feedback));

    const actions = create('div', 'oj-ai-result-actions');
    const pdf = create('div', 'oj-ai-result-pdf');
    const pdfButton = create('button', 'oj-ai-student-primary oj-ai-result-pdf__button', 'PDF 만들기');
    pdfButton.type = 'button';
    pdfButton.dataset.action = 'create-result-pdf';
    pdfButton.dataset.role = 'result-pdf-button';
    pdfButton.setAttribute('aria-describedby', 'oj-ai-result-pdf-status');
    const pdfStatus = create('p', 'oj-ai-result-pdf__status');
    pdfStatus.id = 'oj-ai-result-pdf-status';
    pdfStatus.dataset.role = 'result-pdf-status';
    pdfStatus.setAttribute('role', 'status');
    pdfStatus.setAttribute('aria-live', 'polite');
    pdfStatus.setAttribute('aria-atomic', 'true');
    pdf.append(pdfButton, pdfStatus);
    actions.append(pdf, retryButton());
    article.append(actions);
    root.append(article);
    activeResult = result;
  };

  const loadResult = async () => {
    const currentRequest = ++requestNumber;
    cancelPdf();
    activeResult = null;
    renderLoading();
    try {
      const body = await requestResult();
      if (currentRequest !== requestNumber) return;
      const result = body?.data?.result;
      if (!validResult(result)) throw new Error('result_contract_invalid');
      if (result.release.state === 'stale') {
        renderMessage('updating', '결과를 업데이트하고 있어요.', '잠시 후 다시 확인해 주세요.', true);
      } else if (result.release.state === 'withdrawn') {
        renderMessage('withdrawn', '공개가 종료된 결과예요.');
      } else {
        renderResult(result);
      }
    } catch (error) {
      if (currentRequest !== requestNumber) return;
      const status = Number(error?.status || 0);
      if ([401, 403, 404, 409].includes(status)) {
        renderMessage('unavailable', '결과를 확인할 수 없습니다.');
      } else {
        renderMessage('error', '잠시 후 다시 확인해 주세요.', '', true);
      }
    }
  };

  root.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('button[data-action]') : null;
    if (!button) return;
    if (button.dataset.action === 'reload-result') loadResult();
    else if (button.dataset.action === 'create-result-pdf') createPdf();
    else if (button.dataset.action === 'resume-result-pdf') resumePdf();
    else if (button.dataset.action === 'download-result-pdf') downloadPdf(button);
  });

  loadResult();
})();
