(function (window, document) {
  'use strict';

  const settings = document.querySelector('#oj-ai-assessment-settings');
  const root = document.querySelector('#oj-ai-settings-panel-data');
  if (!settings || !root) return;

  const SOURCE_PROFILE = 'oj_ai_dashboard_wpdb_prefixed@0.1.2';
  const PUBLIC_ID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  const elements = {
    migrationDialog: root.querySelector('[data-data-dialog="migration"]'),
    migrationNotice: root.querySelector('[data-data-role="migration-notice"]'),
    migrationSummary: root.querySelector('[data-data-role="migration-summary"]'),
    migrationDetail: root.querySelector('[data-data-role="migration-detail"]'),
    migrationCandidates: root.querySelector('[data-data-value="migration-candidates"]'),
    migrationMapping: root.querySelector('[data-data-value="migration-mapping"]'),
    migrationExcluded: root.querySelector('[data-data-value="migration-excluded"]'),
    migrationAck: root.querySelector('[data-data-field="migration-ack"]'),
    migrationStart: root.querySelector('[data-data-action="migration-start"]'),
    purgeDialog: root.querySelector('[data-data-dialog="purge"]'),
    purgeNotice: root.querySelector('[data-data-role="purge-notice"]'),
    purgeSummary: root.querySelector('[data-data-role="purge-summary"]'),
    purgeRows: root.querySelector('[data-data-value="purge-rows"]'),
    purgeFiles: root.querySelector('[data-data-value="purge-files"]'),
    purgeJobs: root.querySelector('[data-data-value="purge-jobs"]'),
    purgeBackupAck: root.querySelector('[data-data-field="purge-backup-ack"]'),
    purgeBackupReference: root.querySelector('[data-data-field="purge-backup-reference"]'),
    purgeBackupTime: root.querySelector('[data-data-field="purge-backup-time"]'),
    purgePhrase: root.querySelector('[data-data-field="purge-phrase"]'),
    purgeStart: root.querySelector('[data-data-action="purge-start"]'),
  };
  const state = {
    migration: { busy: false, preview: null, idempotencyKey: '', started: false },
    purge: { busy: false, preview: null, idempotencyKey: '', started: false },
  };

  function operationKey(prefix) {
    const random = window.crypto?.randomUUID
      ? window.crypto.randomUUID().replaceAll('-', '')
      : Math.random().toString(36).slice(2);
    return `${prefix}:${Date.now().toString(36)}:${random}`;
  }

  function publicId() {
    const bytes = new Uint8Array(26);
    if (window.crypto?.getRandomValues) {
      window.crypto.getRandomValues(bytes);
    } else {
      bytes.forEach((_value, index) => { bytes[index] = Math.floor(Math.random() * 256); });
    }
    return [...bytes].map((value) => PUBLIC_ID_ALPHABET[value % PUBLIC_ID_ALPHABET.length]).join('');
  }

  function formatNumber(value) {
    const number = Number.isFinite(Number(value)) ? Number(value) : 0;
    const locale = String(settings.dataset.locale || 'ko-KR').replace(/_/g, '-');
    try {
      return new Intl.NumberFormat(locale).format(number);
    } catch (_error) {
      return new Intl.NumberFormat('ko-KR').format(number);
    }
  }

  function setNotice(node, message, tone = 'neutral') {
    if (!node) return;
    node.textContent = message;
    node.dataset.tone = tone;
  }

  async function request(path, body, idempotencyKey) {
    const response = await window.fetch(`${settings.dataset.restRoot}${path}`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-WP-Nonce': settings.dataset.restNonce || '',
        'Idempotency-Key': idempotencyKey,
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    });
    let payload = null;
    try {
      payload = await response.json();
    } catch (_error) {
      payload = null;
    }
    if (!response.ok) {
      const error = new Error(payload?.message || '요청을 처리하지 못했습니다.');
      error.status = Number(payload?.data?.status || response.status || 500);
      throw error;
    }
    if (!payload || typeof payload !== 'object' || !payload.data) {
      throw new Error('응답을 확인할 수 없습니다.');
    }
    return payload.data;
  }

  function openDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.showModal === 'function') {
      if (!dialog.open) dialog.showModal();
    } else {
      dialog.setAttribute('open', '');
    }
  }

  function closeDialog(dialog, busy) {
    if (!dialog || busy) return;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  function setDialogBusy(dialog, busy) {
    if (!dialog) return;
    dialog.setAttribute('aria-busy', busy ? 'true' : 'false');
    dialog.querySelectorAll('button, input').forEach((control) => {
      control.disabled = busy;
    });
  }

  function migrationCounts() {
    const counts = state.migration.preview?.counts || {};
    return {
      candidates: Number(counts.migration_candidates || 0),
      mapping: Number(counts.mapping_needed || 0),
      excluded: Number(counts.excluded || 0),
      conflicts: Number(counts.conflicts || 0),
    };
  }

  function renderMigration() {
    const preview = state.migration.preview;
    const counts = migrationCounts();
    const eligible = Boolean(preview)
      && counts.candidates > 0
      && counts.mapping === 0
      && counts.conflicts === 0
      && !state.migration.started;
    elements.migrationSummary.hidden = !preview;
    elements.migrationCandidates.textContent = formatNumber(counts.candidates);
    elements.migrationMapping.textContent = formatNumber(counts.mapping);
    elements.migrationExcluded.textContent = formatNumber(counts.excluded);
    elements.migrationAck.disabled = state.migration.busy || !eligible;
    elements.migrationStart.disabled = state.migration.busy || !eligible || !elements.migrationAck.checked;

    if (!preview || state.migration.started) return;
    if (counts.conflicts > 0) {
      elements.migrationDetail.textContent = '원본 데이터의 충돌을 해결한 뒤 다시 확인하세요.';
    } else if (counts.mapping > 0) {
      elements.migrationDetail.textContent = '수업 또는 학생 연결이 필요한 항목이 있습니다. OJ Platform의 수업과 사용자 정보를 확인한 뒤 다시 시도하세요.';
    } else if (counts.candidates === 0) {
      elements.migrationDetail.textContent = '가져올 이전 데이터가 없습니다.';
    } else {
      elements.migrationDetail.textContent = '가져올 자료를 확인했습니다. 원본은 그대로 보존됩니다.';
    }
  }

  async function previewMigration() {
    state.migration = { busy: true, preview: null, idempotencyKey: operationKey('legacy-preview'), started: false };
    elements.migrationAck.checked = false;
    setDialogBusy(elements.migrationDialog, true);
    setNotice(elements.migrationNotice, '가져올 데이터를 확인하는 중입니다.');
    try {
      const data = await request(
        '/migration-runs/commands/preview',
        { source: { profile: SOURCE_PROFILE } },
        state.migration.idempotencyKey
      );
      if (!data.migration_preview) throw new Error('가져올 데이터를 확인할 수 없습니다.');
      state.migration.preview = data.migration_preview;
      setNotice(elements.migrationNotice, '가져올 범위를 확인했습니다.', 'success');
    } catch (error) {
      setNotice(elements.migrationNotice, error?.message || '가져올 데이터를 확인하지 못했습니다.', 'danger');
    } finally {
      state.migration.busy = false;
      setDialogBusy(elements.migrationDialog, false);
      renderMigration();
    }
  }

  async function startMigration() {
    const preview = state.migration.preview;
    const counts = migrationCounts();
    if (state.migration.busy || !preview || !elements.migrationAck.checked
      || counts.candidates < 1 || counts.mapping > 0 || counts.conflicts > 0) return;

    state.migration.busy = true;
    setDialogBusy(elements.migrationDialog, true);
    setNotice(elements.migrationNotice, '가져오기를 준비하는 중입니다.');
    try {
      const approved = await request(
        '/migration-runs/commands/approve',
        { source: { profile: SOURCE_PROFILE }, preview_token: preview.preview_token },
        state.migration.idempotencyKey
      );
      const run = approved.migration_run;
      if (!run?.id || !run?.plan_hash || !run?.source?.fingerprint) {
        throw new Error('가져오기 승인을 확인할 수 없습니다.');
      }
      await request(
        `/migration-runs/${encodeURIComponent(run.id)}/commands/execute`,
        {
          command_id: publicId(),
          expected: { plan_hash: run.plan_hash, source_fingerprint: run.source.fingerprint, state: 'approved' },
          payload: {},
        },
        operationKey('legacy-execute')
      );
      state.migration.started = true;
      setNotice(elements.migrationNotice, '가져오기를 시작했습니다. 완료될 때까지 원본 데이터는 그대로 유지됩니다.', 'success');
      elements.migrationDetail.textContent = '작업은 백그라운드에서 계속됩니다.';
    } catch (error) {
      setNotice(elements.migrationNotice, error?.message || '가져오기를 시작하지 못했습니다.', 'danger');
    } finally {
      state.migration.busy = false;
      setDialogBusy(elements.migrationDialog, false);
      renderMigration();
    }
  }

  function purgeInventory() {
    const preview = state.purge.preview || {};
    return {
      rows: Number(preview.total_row_count || 0),
      files: Number(preview.private_files?.file_count || 0),
      holds: Number(preview.private_files?.legal_hold_count || 0),
      jobs: Number(preview.incomplete_jobs?.total || 0),
    };
  }

  function purgeFormReady() {
    const inventory = purgeInventory();
    return Boolean(state.purge.preview)
      && inventory.rows + inventory.files > 0
      && inventory.holds === 0
      && inventory.jobs === 0
      && elements.purgeBackupAck?.checked
      && (elements.purgeBackupReference?.value.trim().length || 0) >= 2
      && Boolean(elements.purgeBackupTime?.value)
      && elements.purgePhrase?.value.trim() === '영구 삭제'
      && !state.purge.started;
  }

  function renderPurge() {
    if (!elements.purgeDialog) return;
    const preview = state.purge.preview;
    const inventory = purgeInventory();
    elements.purgeSummary.hidden = !preview;
    elements.purgeRows.textContent = formatNumber(inventory.rows);
    elements.purgeFiles.textContent = formatNumber(inventory.files);
    elements.purgeJobs.textContent = formatNumber(inventory.jobs);
    const locked = state.purge.busy || !preview || state.purge.started;
    [
      elements.purgeBackupAck,
      elements.purgeBackupReference,
      elements.purgeBackupTime,
      elements.purgePhrase,
    ].filter(Boolean).forEach((control) => {
      control.disabled = locked;
    });
    elements.purgeStart.disabled = state.purge.busy || !purgeFormReady();

    if (!preview || state.purge.started) return;
    if (inventory.holds > 0) {
      setNotice(elements.purgeNotice, '보존 중인 자료가 있어 영구 삭제할 수 없습니다.', 'danger');
    } else if (inventory.jobs > 0) {
      setNotice(elements.purgeNotice, '진행 중인 작업을 마친 뒤 다시 확인하세요.', 'danger');
    } else if (inventory.rows + inventory.files === 0) {
      setNotice(elements.purgeNotice, '삭제할 데이터가 없습니다.');
    } else {
      setNotice(elements.purgeNotice, '삭제 범위를 확인했습니다. 백업과 확인 문구를 입력하세요.', 'warning');
    }
  }

  function localNowValue() {
    const now = new Date();
    const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 16);
  }

  async function previewPurge() {
    state.purge = { busy: true, preview: null, idempotencyKey: operationKey('purge-preview'), started: false };
    elements.purgeBackupAck.checked = false;
    elements.purgeBackupReference.value = '';
    elements.purgeBackupTime.value = localNowValue();
    elements.purgePhrase.value = '';
    setDialogBusy(elements.purgeDialog, true);
    setNotice(elements.purgeNotice, '삭제할 데이터를 확인하는 중입니다.');
    try {
      const data = await request(
        '/purge-jobs/commands/preview',
        { scope: { type: 'addon' } },
        state.purge.idempotencyKey
      );
      if (!data.purge_preview) throw new Error('삭제할 데이터를 확인할 수 없습니다.');
      state.purge.preview = data.purge_preview;
    } catch (error) {
      setNotice(elements.purgeNotice, error?.message || '삭제할 데이터를 확인하지 못했습니다.', 'danger');
    } finally {
      state.purge.busy = false;
      setDialogBusy(elements.purgeDialog, false);
      renderPurge();
    }
  }

  async function sha256(value) {
    if (!window.crypto?.subtle || typeof window.TextEncoder !== 'function') {
      throw new Error('이 브라우저에서는 백업 확인을 처리할 수 없습니다.');
    }
    const bytes = new window.TextEncoder().encode(value);
    const digest = await window.crypto.subtle.digest('SHA-256', bytes);
    return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  }

  function utcDatabaseTime(localValue) {
    const value = new Date(localValue);
    if (Number.isNaN(value.getTime())) throw new Error('백업 확인 시각을 확인해 주세요.');
    return value.toISOString().slice(0, 19).replace('T', ' ');
  }

  async function startPurge() {
    if (state.purge.busy || !purgeFormReady()) return;
    const preview = state.purge.preview;
    state.purge.busy = true;
    setDialogBusy(elements.purgeDialog, true);
    setNotice(elements.purgeNotice, '영구 삭제를 준비하는 중입니다.', 'warning');
    try {
      const evidence = await sha256(elements.purgeBackupReference.value.trim());
      const approved = await request(
        '/purge-jobs/commands/approve',
        {
          scope: { type: 'addon' },
          preview_token: preview.preview_token,
          backup: {
            evidence_digest: evidence,
            verified_at_utc: utcDatabaseTime(elements.purgeBackupTime.value),
          },
          confirmation: { phrase: '영구 삭제' },
        },
        state.purge.idempotencyKey
      );
      const job = approved.purge_job;
      if (!job?.id || !job?.preview_hash) throw new Error('영구 삭제 승인을 확인할 수 없습니다.');
      await request(
        `/purge-jobs/${encodeURIComponent(job.id)}/commands/execute`,
        {
          command_id: publicId(),
          expected: { preview_hash: job.preview_hash, state: 'approved' },
          payload: {},
        },
        operationKey('purge-execute')
      );
      state.purge.started = true;
      setNotice(elements.purgeNotice, '영구 삭제를 시작했습니다. 완료될 때까지 플러그인을 제거하지 마세요.', 'success');
    } catch (error) {
      setNotice(elements.purgeNotice, error?.message || '영구 삭제를 시작하지 못했습니다.', 'danger');
    } finally {
      state.purge.busy = false;
      setDialogBusy(elements.purgeDialog, false);
      renderPurge();
    }
  }

  root.addEventListener('submit', (event) => event.preventDefault());
  root.addEventListener('input', (event) => {
    if (event.target.matches('[data-data-field^="migration-"]')) renderMigration();
    if (event.target.matches('[data-data-field^="purge-"]')) renderPurge();
  });
  root.addEventListener('change', (event) => {
    if (event.target.matches('[data-data-field^="migration-"]')) renderMigration();
    if (event.target.matches('[data-data-field^="purge-"]')) renderPurge();
  });
  root.addEventListener('click', (event) => {
    const control = event.target.closest('[data-data-action]');
    if (!control) return;
    const action = control.dataset.dataAction;
    if (action === 'migration-open') {
      openDialog(elements.migrationDialog);
      previewMigration();
    }
    if (action === 'purge-open') {
      openDialog(elements.purgeDialog);
      previewPurge();
    }
    if (action === 'dialog-close') {
      closeDialog(control.closest('dialog'), state.migration.busy || state.purge.busy);
    }
    if (action === 'migration-start') startMigration();
    if (action === 'purge-start') startPurge();
  });
  [elements.migrationDialog, elements.purgeDialog].filter(Boolean).forEach((dialog) => {
    dialog.addEventListener('cancel', (event) => {
      if (state.migration.busy || state.purge.busy) event.preventDefault();
    });
  });
})(window, document);
