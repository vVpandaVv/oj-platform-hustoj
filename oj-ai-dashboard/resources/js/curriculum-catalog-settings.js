(function curriculumCatalogSettings(window, document) {
  'use strict';

  const root = document.getElementById('oj-ai-assessment-settings');
  const panel = root?.querySelector('[data-settings-panel="curriculum"]');
  if (!root || !panel) return;

  const restRoot = String(root.dataset.restRoot || '').replace(/\/$/, '');
  const restNonce = String(root.dataset.restNonce || '');
  const locale = String(root.dataset.locale || '');
  const pageLimit = Number.parseInt(root.dataset.catalogPageLimit || '', 10);
  const manualSourceKind = String(root.dataset.catalogManualSourceKind || '');
  if (!Number.isInteger(pageLimit) || pageLimit < 1 || !manualSourceKind) {
    panel.textContent = '성취기준 설정을 불러오지 못했습니다.';
    return;
  }
  const state = {
    curriculums: [],
    standards: [],
    selectedCurriculumId: '',
    busy: false,
    searchTimer: 0,
  };

  const field = (name) => panel.querySelector('[data-catalog-field="' + name + '"]');
  const role = (name) => panel.querySelector('[data-catalog-role="' + name + '"]');
  const action = (name) => panel.querySelector('[data-catalog-action="' + name + '"]');

  function randomToken(prefix) {
    const value = window.crypto?.randomUUID
      ? window.crypto.randomUUID()
      : String(Date.now()) + '-' + Math.random().toString(16).slice(2);
    return prefix + '-' + value;
  }

  function problemMessage(payload, status) {
    if (typeof payload?.message === 'string' && payload.message.trim()) return payload.message;
    const messages = {
      403: '이 작업을 할 수 없습니다.',
      404: '교육과정 또는 성취기준을 찾을 수 없습니다.',
      409: '내용이 바뀌었습니다. 다시 불러와 주세요.',
      422: '입력한 내용을 확인해 주세요.',
      503: '잠시 후 다시 시도해 주세요.',
    };
    return messages[status] || '요청을 완료하지 못했습니다.';
  }

  async function api(path, options = {}) {
    const headers = { Accept: 'application/json' };
    if (restNonce) headers['X-WP-Nonce'] = restNonce;
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;
    const response = await window.fetch(restRoot + path, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers,
      ...(options.body === undefined ? {} : { body: JSON.stringify(options.body) }),
    });
    let payload = {};
    try {
      payload = await response.json();
    } catch (_error) {
      payload = {};
    }
    if (!response.ok || !payload?.data) {
      const error = new Error(problemMessage(payload, response.status));
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  function setNotice(message, tone = 'neutral') {
    const notice = role('notice');
    if (!notice) return;
    notice.textContent = message;
    notice.dataset.tone = tone;
  }

  function selectedCurriculum() {
    return state.curriculums.find(
      (item) => item.curriculum_id === state.selectedCurriculumId
    ) || null;
  }

  function setBusy(busy) {
    state.busy = busy;
    panel.querySelectorAll('button, input, select, textarea').forEach((control) => {
      if (control.closest('dialog[open]')) return;
      control.disabled = busy;
    });
    refreshActions();
  }

  function refreshActions() {
    const selected = selectedCurriculum();
    const curriculumEdit = action('curriculum-edit');
    const standardNew = action('standard-new');
    const curriculumSelect = field('curriculum');
    if (curriculumSelect) curriculumSelect.disabled = state.busy || !state.curriculums.length;
    if (curriculumEdit) {
      curriculumEdit.disabled = state.busy || !selected || selected.source_kind !== manualSourceKind;
    }
    if (standardNew) standardNew.disabled = state.busy || !selected || selected.source_kind !== manualSourceKind;
    const curriculumNew = action('curriculum-new');
    if (curriculumNew) curriculumNew.disabled = state.busy;
  }

  function renderCurriculums(preferredId = '') {
    const select = field('curriculum');
    if (!select) return;
    const previous = preferredId || state.selectedCurriculumId;
    select.replaceChildren();
    if (!state.curriculums.length) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = '등록된 교육과정 없음';
      select.append(option);
      state.selectedCurriculumId = '';
    } else {
      state.curriculums.forEach((curriculum) => {
        const option = document.createElement('option');
        option.value = curriculum.curriculum_id;
        option.textContent = [curriculum.title, curriculum.source_version].filter(Boolean).join(' · ');
        select.append(option);
      });
      state.selectedCurriculumId = state.curriculums.some(
        (item) => item.curriculum_id === previous
      ) ? previous : state.curriculums[0].curriculum_id;
      select.value = state.selectedCurriculumId;
    }
    refreshActions();
  }

  function renderStandards() {
    const list = role('standards');
    if (!list) return;
    list.replaceChildren();
    if (!state.selectedCurriculumId) {
      const empty = document.createElement('p');
      empty.className = 'oj-ai-catalog__empty';
      empty.textContent = '교육과정을 먼저 추가하세요.';
      list.append(empty);
      return;
    }
    if (!state.standards.length) {
      const empty = document.createElement('p');
      empty.className = 'oj-ai-catalog__empty';
      empty.textContent = field('search')?.value.trim()
        ? '검색 결과가 없습니다.'
        : '등록된 성취기준이 없습니다.';
      list.append(empty);
      return;
    }

    state.standards.forEach((standard) => {
      const row = document.createElement('div');
      row.className = 'oj-ai-catalog__row';
      const main = document.createElement('div');
      main.className = 'oj-ai-catalog__row-main';
      const title = document.createElement('strong');
      const meta = document.createElement('small');
      title.textContent = [standard.code, standard.title].filter(Boolean).join(' · ');
      meta.textContent = standard.grade_label || '범위 미지정';
      main.append(title, meta);
      if (standard.description) {
        const description = document.createElement('p');
        description.textContent = standard.description;
        main.append(description);
      }
      const controls = document.createElement('div');
      controls.className = 'oj-ai-catalog__row-actions';
      const edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'oj-ai-catalog__icon-button';
      edit.dataset.catalogAction = 'standard-edit';
      edit.dataset.standardId = standard.standard_public_id;
      edit.setAttribute('aria-label', '성취기준 수정');
      edit.title = '성취기준 수정';
      edit.disabled = state.busy || selectedCurriculum()?.source_kind !== manualSourceKind;
      const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
      use.setAttribute('href', String(root.dataset.iconSprite || '') + '#edit');
      icon.setAttribute('aria-hidden', 'true');
      icon.append(use);
      edit.append(icon);
      controls.append(edit);
      row.append(main, controls);
      list.append(row);
    });
  }

  async function loadStandards() {
    if (!state.selectedCurriculumId) {
      state.standards = [];
      renderStandards();
      return;
    }
    const search = String(field('search')?.value || '').trim();
    try {
      const path = '/curriculums/' + encodeURIComponent(state.selectedCurriculumId)
        + '/standards?limit=' + encodeURIComponent(String(pageLimit))
        + '&search=' + encodeURIComponent(search);
      const payload = await api(path);
      state.standards = Array.isArray(payload.data.items) ? payload.data.items : [];
      renderStandards();
      setNotice(String(state.standards.length) + '개 성취기준', 'neutral');
    } catch (error) {
      state.standards = [];
      renderStandards();
      setNotice(error.message, 'danger');
    }
  }

  async function loadCurriculums(preferredId = '') {
    setBusy(true);
    setNotice('불러오는 중', 'neutral');
    try {
      const payload = await api('/curriculums?limit=' + encodeURIComponent(String(pageLimit)));
      state.curriculums = Array.isArray(payload.data.items) ? payload.data.items : [];
      renderCurriculums(preferredId);
      await loadStandards();
      if (!state.curriculums.length) setNotice('교육과정을 추가하세요.', 'neutral');
    } catch (error) {
      state.curriculums = [];
      state.standards = [];
      renderCurriculums();
      renderStandards();
      setNotice(error.message, 'danger');
    } finally {
      setBusy(false);
    }
  }

  function openDialog(name) {
    const dialog = panel.querySelector('[data-catalog-dialog="' + name + '"]');
    if (!dialog) return;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }

  function closeDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  function setDialogError(name, message) {
    const target = role(name + '-error');
    if (target) target.textContent = message || '';
  }

  function openCurriculum(record = null) {
    const form = panel.querySelector('[data-catalog-form="curriculum"]');
    form?.reset();
    field('curriculum-id').value = record?.curriculum_id || '';
    field('curriculum-lock').value = record ? String(record.lock_version) : '';
    field('curriculum-title').value = record?.title || '';
    field('curriculum-source-key').value = record?.source_key || '';
    field('curriculum-source-version').value = record?.source_version || '';
    field('curriculum-locale').value = record?.locale || locale;
    role('curriculum-dialog-title').textContent = record ? '교육과정 수정' : '교육과정 추가';
    setDialogError('curriculum', '');
    openDialog('curriculum');
    field('curriculum-title').focus();
  }

  function openStandard(record = null) {
    const form = panel.querySelector('[data-catalog-form="standard"]');
    form?.reset();
    field('standard-id').value = record?.standard_public_id || '';
    field('standard-lock').value = record ? String(record.lock_version) : '';
    field('standard-code').value = record?.code || '';
    field('standard-title').value = record?.title || '';
    field('standard-grade').value = record?.grade_label || '';
    field('standard-description').value = record?.description || '';
    role('standard-dialog-title').textContent = record ? '성취기준 수정' : '성취기준 추가';
    setDialogError('standard', '');
    openDialog('standard');
    field('standard-code').focus();
  }

  async function saveCurriculum(form) {
    if (!form.reportValidity()) return;
    const id = String(field('curriculum-id').value || '');
    const lock = String(field('curriculum-lock').value || '');
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    setDialogError('curriculum', '');
    try {
      const payload = await api('/curriculums/commands/save', {
        method: 'POST',
        idempotencyKey: randomToken('curriculum-key'),
        body: {
          command_id: randomToken('curriculum-save'),
          expected: { expected_lock_version: id ? Number(lock) : null },
          payload: {
            curriculum_id: id || null,
            title: field('curriculum-title').value.trim(),
            source_key: field('curriculum-source-key').value.trim(),
            source_version: field('curriculum-source-version').value.trim(),
            locale: field('curriculum-locale').value.trim(),
          },
        },
      });
      closeDialog(form.closest('dialog'));
      await loadCurriculums(payload.data.curriculum.curriculum_id);
      setNotice('교육과정을 저장했습니다.', 'success');
    } catch (error) {
      setDialogError('curriculum', error.message);
    } finally {
      submit.disabled = false;
    }
  }

  async function saveStandard(form) {
    if (!form.reportValidity() || !state.selectedCurriculumId) return;
    const id = String(field('standard-id').value || '');
    const lock = String(field('standard-lock').value || '');
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    setDialogError('standard', '');
    try {
      const path = '/curriculums/' + encodeURIComponent(state.selectedCurriculumId)
        + '/standards/commands/save';
      await api(path, {
        method: 'POST',
        idempotencyKey: randomToken('standard-key'),
        body: {
          command_id: randomToken('standard-save'),
          expected: { expected_lock_version: id ? Number(lock) : null },
          payload: {
            standard_id: id || null,
            code: field('standard-code').value.trim(),
            title: field('standard-title').value.trim(),
            description: field('standard-description').value.trim(),
            grade_label: field('standard-grade').value.trim() || null,
          },
        },
      });
      closeDialog(form.closest('dialog'));
      await loadStandards();
      setNotice('성취기준을 저장했습니다.', 'success');
    } catch (error) {
      setDialogError('standard', error.message);
    } finally {
      submit.disabled = false;
    }
  }

  root.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-settings-tab="curriculum"]');
    if (tab) {
      void loadCurriculums(state.selectedCurriculumId);
      return;
    }
    const control = event.target.closest('[data-catalog-action]');
    if (!control || !panel.contains(control)) return;
    const name = control.dataset.catalogAction;
    if (name === 'curriculum-new') openCurriculum();
    if (name === 'curriculum-edit') openCurriculum(selectedCurriculum());
    if (name === 'standard-new') openStandard();
    if (name === 'standard-edit') {
      openStandard(state.standards.find(
        (item) => item.standard_public_id === control.dataset.standardId
      ) || null);
    }
    if (name === 'dialog-close') closeDialog(control.closest('dialog'));
  });

  field('curriculum')?.addEventListener('change', (event) => {
    state.selectedCurriculumId = String(event.currentTarget.value || '');
    void loadStandards();
    refreshActions();
  });

  field('search')?.addEventListener('input', () => {
    window.clearTimeout(state.searchTimer);
    state.searchTimer = window.setTimeout(loadStandards, 250);
  });

  panel.querySelector('[data-catalog-form="curriculum"]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    void saveCurriculum(event.currentTarget);
  });
  panel.querySelector('[data-catalog-form="standard"]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    void saveStandard(event.currentTarget);
  });

  panel.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) closeDialog(dialog);
    });
  });

  renderCurriculums();
  renderStandards();
})(window, document);