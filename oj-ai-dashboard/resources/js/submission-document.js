(function (window, document) {
  'use strict';
  const idPattern = /^[0-9A-HJKMNP-TV-Z]{26}$/;
  const scopeQuery = context => new URLSearchParams({ context_type: context.type, context_id: context.id, context_revision: context.revision });
  const element = (tag, text = '') => { const node = document.createElement(tag); node.textContent = text; return node; };
  const icon = (root, name) => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    svg.setAttribute('class', 'oj-ai-icon'); svg.setAttribute('aria-hidden', 'true');
    use.setAttribute('href', `${root.dataset.iconSprite || ''}#${name}`); svg.append(use); return svg;
  };
  async function request(root, path, options = {}) {
    const response = await window.fetch(`${root.dataset.restRoot}${path}`, {
      credentials: 'same-origin', cache: 'no-store', ...options,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': root.dataset.restNonce || '', ...options.headers },
    });
    const body = await response.json();
    if (!response.ok) { const error = new Error(body.message || '요청을 처리하지 못했습니다.'); error.status = response.status; throw error; }
    return body.data;
  }
  function viewButton(root, revisionId, context, submissionId) {
    const button = element('button', '문제·답안 전체 보기');
    button.type = 'button'; button.className = 'oj-ai-document-button ojp-button'; button.prepend(icon(root, 'eye'));
    button.disabled = !idPattern.test(revisionId || '') || !context;
    button.addEventListener('click', async () => {
      const dialog = element('dialog'); dialog.className = 'oj-ai-document'; dialog.setAttribute('aria-label', '문제·답안 전체 보기');
      const header = element('header'); const title = element('h2', '문제·답안 전체 보기');
      const close = element('button'); close.type = 'button'; close.className = 'ojp-button ojp-button--plain';
      close.title = '닫기'; close.setAttribute('aria-label', '닫기'); close.append(icon(root, 'x'));
      const status = element('p', '문제와 공식 제출본을 불러오는 중입니다.'); status.setAttribute('role', 'status');
      header.append(title, close); dialog.append(header, status); root.append(dialog);
      const controller = new AbortController();
      close.addEventListener('click', () => dialog.close());
      dialog.addEventListener('close', () => { controller.abort(); dialog.remove(); if (button.isConnected) button.focus(); }, { once: true });
      dialog.showModal(); close.focus();
      try {
        const data = await request(root, `/submission-revisions/${encodeURIComponent(revisionId)}/view?${scopeQuery(context)}`, { signal: controller.signal });
        if (!dialog.open) return;
        const view = data?.submission_view;
        if (view?.revision_id !== revisionId || view?.submission_id !== submissionId || typeof view?.html !== 'string') throw new Error('제출본이 변경되었습니다. 새로고침 후 다시 확인해 주세요.');
        const frame = element('iframe'); frame.title = '전체 문제와 학생 공식 제출 내용'; frame.setAttribute('sandbox', ''); frame.referrerPolicy = 'no-referrer';
        frame.srcdoc = view.html; dialog.append(frame); status.remove();
      } catch (error) {
        if (dialog.open) status.textContent = error.message || '문제와 답안을 불러오지 못했습니다.';
      }
    });
    return button;
  }

  function bundle(root, container, assignment, context, submittedCount) {
    if (!container) return;
    container.replaceChildren();
    if (root.dataset.canExport !== '1' || !assignment || !context || !['open', 'closed'].includes(assignment.state)) return;
    const key = `oj-ai-submission-bundle:${root.dataset.actorId || ''}:${assignment.id}:${context.revision}`;
    let saved = null;
    try { saved = JSON.parse(window.sessionStorage.getItem(key) || 'null'); } catch (_error) { /* Storage is optional. */ }
    if (!saved || saved.assignmentId !== assignment.id || (saved.jobId && !idPattern.test(saved.jobId))) saved = null;
    let busy = false; let generation = 0;
    container.classList.add('oj-ai-document-export');
    const start = element('button'); start.type = 'button'; start.className = 'ojp-button';
    const label = element('span', 'PDF 일괄 다운로드'); start.append(icon(root, 'download'), label);
    const refresh = element('button'); refresh.type = 'button'; refresh.className = 'ojp-button'; refresh.title = '최신 제출본으로 PDF 다시 생성'; refresh.setAttribute('aria-label', refresh.title); refresh.append(icon(root, 'refresh-cw'));
    const status = element('span'); status.setAttribute('role', 'status');
    container.append(start, refresh, status);
    const current = () => root.isConnected && container.contains(start);
    const persist = () => { try { if (saved) window.sessionStorage.setItem(key, JSON.stringify(saved)); else window.sessionStorage.removeItem(key); } catch (_error) { /* Keep the in-memory job. */ } };
    const render = (message = '') => {
      if (!current()) return;
      start.disabled = busy || (!saved && submittedCount === 0);
      label.textContent = saved?.artifactId ? 'PDF ZIP 다운로드' : saved ? 'PDF 생성 상태 확인' : 'PDF 일괄 다운로드';
      refresh.hidden = !saved?.artifactId; refresh.disabled = busy;
      status.textContent = message || (saved?.artifactId ? 'PDF 준비됨' : submittedCount === 0 ? '공식 제출본 없음' : `공식 제출본 ${submittedCount}명`);
    };
    const download = async () => {
      const response = await window.fetch(`${root.dataset.restRoot}/artifacts/${encodeURIComponent(saved.artifactId)}/download`, {
        credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/zip', 'X-WP-Nonce': root.dataset.restNonce || '' },
      });
      if (!response.ok) { const error = new Error('ZIP을 내려받지 못했습니다. 상태를 다시 확인해 주세요.'); error.status = response.status; throw error; }
      if (!(response.headers.get('Content-Type') || '').toLowerCase().startsWith('application/zip')) throw new Error('다운로드 파일 형식을 확인하지 못했습니다.');
      const blob = await response.blob();
      if (!current()) return;
      const url = URL.createObjectURL(blob); const link = element('a'); link.href = url;
      link.download = `submissions-${assignment.id.toLowerCase()}.zip`; link.hidden = true;
      document.body.append(link); link.click(); link.remove(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
    const run = async (autoDownload = true) => {
      if (busy || !current()) return;
      busy = true; const runId = ++generation; render('PDF 준비 중');
      try {
        if (!saved) {
          const data = await request(root, `/assignments/${encodeURIComponent(assignment.id)}?${scopeQuery(context)}`);
          const fresh = data?.assignment;
          if (fresh?.id !== assignment.id || !/^[a-f0-9]{64}$/.test(fresh.content_hash || '') || !['open', 'closed'].includes(fresh.state)) throw new Error('배포 정보를 새로고침해 주세요.');
          const commandId = `bundle-${window.crypto.randomUUID()}`;
          saved = { assignmentId: assignment.id, command: { command_id: commandId,
            expected: { expected_assignment_id: assignment.id, expected_assignment_hash: fresh.content_hash, expected_schedule_version: fresh.schedule_version, expected_assignment_state: fresh.state },
            payload: { locale: 'ko-KR', profile: 'submission_bundle.v1', include_allowed_originals: false },
          } };
          persist();
        }
        if (!saved.jobId) {
          const data = await request(root, `/assignments/${encodeURIComponent(assignment.id)}/commands/export-submissions-zip?${scopeQuery(context)}`, {
            method: 'POST', headers: { 'Idempotency-Key': saved.command.command_id }, body: JSON.stringify(saved.command),
          });
          const job = data?.export_job;
          if (!idPattern.test(job?.id || '') || job.source_id !== assignment.id || job.artifact_kind !== 'submission_bundle') throw new Error('PDF 생성 요청을 확인하지 못했습니다.');
          saved = { assignmentId: assignment.id, jobId: job.id }; persist();
        }
        for (let attempt = 0; attempt < 120; attempt += 1) {
          if (!current() || generation !== runId) return;
          const data = await request(root, `/export-jobs/${encodeURIComponent(saved.jobId)}`);
          const job = data?.export_job;
          if (job?.job_id !== saved.jobId || job.source_id !== assignment.id || job.artifact_kind !== 'submission_bundle') throw new Error('PDF 생성 상태를 확인하지 못했습니다.');
          if (['failed', 'cancelled', 'expired'].includes(job.state)) { saved = null; persist(); throw new Error('PDF를 준비하지 못했습니다. 최신 제출 현황을 확인한 뒤 다시 생성해 주세요.'); }
          if (job.state === 'ready' && idPattern.test(job.artifact_id || '')) {
            saved.artifactId = job.artifact_id; persist();
            if (autoDownload) await download();
            busy = false; render(); return;
          }
          render('PDF 생성 중');
          await new Promise(resolve => window.setTimeout(resolve, 1500));
        }
        busy = false; render('서버에서 생성 중입니다. 잠시 후 상태를 확인해 주세요.');
      } catch (error) {
        if ([400, 403, 404, 409, 410, 422].includes(error.status)) { saved = null; persist(); }
        busy = false; render(error.message || 'PDF 생성 상태를 확인하지 못했습니다.');
      } finally { busy = false; if (current()) start.disabled = !saved && submittedCount === 0; }
    };
    start.addEventListener('click', () => run());
    refresh.addEventListener('click', () => { saved = null; persist(); run(); });
    render();
    if (saved?.jobId) run(false);
  }
  window.OJAISubmissionDocument = Object.freeze({ viewButton, bundle });
})(window, document);
