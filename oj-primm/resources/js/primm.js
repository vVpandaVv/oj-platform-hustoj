(function () {
  'use strict';
  var stageLabels = { predict: '예측', run: '실행 비교', investigate: '탐구', modify: '수정', make: '제작' };
  var stageKeys = Object.keys(stageLabels);

  function element(tag, className, text) {
    var item = document.createElement(tag);
    if (className) { item.className = className; }
    if (text !== undefined) { item.textContent = String(text); }
    return item;
  }
  function button(label, fn, primary) {
    var item = element('button', 'ojp-button' + (primary ? ' ojp-button--primary' : ''), label);
    item.type = 'button';
    item.addEventListener('click', fn);
    return item;
  }
  function field(label, control) {
    var wrap = element('label', 'ojp-field');
    wrap.appendChild(element('span', 'ojp-label', label)); wrap.appendChild(control);
    return wrap;
  }
  function textInput(type, name, value) {
    var input = element('input', 'ojp-input'); input.type = type; input.name = name;
    if (value !== undefined) { input.value = value; }
    return input;
  }
  function select(name, options) {
    var input = element('select', 'ojp-select'); input.name = name;
    options.forEach(function (option) { var item = element('option', '', option[1]); item.value = option[0]; input.appendChild(item); });
    return input;
  }
  function textarea(name, rows) {
    var input = element('textarea', 'ojp-textarea'); input.name = name; input.rows = rows || 5; input.maxLength = 20000;
    return input;
  }
  function list(value) { return Array.isArray(value) ? value : (value && Array.isArray(value.items) ? value.items : []); }
  function plain(value) {
    var input = element('textarea'); input.innerHTML = String(value || '').replace(/<[^>]*>/g, ' ');
    return input.value.replace(/\s+/g, ' ').trim();
  }
  function link(label, value) {
    try {
      var url = new URL(value || '', window.location.href);
      if (url.origin !== window.location.origin) { return element('span', 'ojp-muted', label); }
      var item = element('a', 'ojp-button', label); item.href = url.href; item.target = '_blank'; item.rel = 'noopener';
      return item;
    } catch (error) { return element('span', 'ojp-muted', label); }
  }
  function empty(parent, title, description) {
    var box = element('div', 'ojp-empty-state'); box.appendChild(element('h2', '', title));
    box.appendChild(element('p', '', description)); parent.appendChild(box);
  }
  function mount(root) {
    if (root.ojPrimm) { return root.ojPrimm; }
    var config;
    try { config = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (error) { return; }
    var content = root.querySelector('[data-primm-content]');
    var status = root.querySelector('[data-primm-status]');
    var state = { view: null, draft: null, dirty: 0, saved: 0, saving: null, timer: null, disposed: 0, practiceSource: null, makeSubmission: null };
    function message(text, error) {
      if (status.textContent !== text) { status.textContent = text; } status.classList.toggle('is-error', Boolean(error));
    }
    async function api(path, input, signal) {
      var response = await window.fetch(config.restUrl.replace(/\/$/, '') + path, {
        method: input === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: input === undefined ? undefined : JSON.stringify(input), signal: signal
      });
      var payload;
      try { payload = await response.json(); } catch (error) { throw new Error('서버 응답을 확인하지 못했습니다. 다시 시도해 주세요.'); }
      if (!response.ok) {
        var failure = new Error(payload.message || '요청을 완료하지 못했습니다.'); failure.status = response.status; throw failure;
      }
      return payload;
    }
    function localKey() { return 'oj_primm_' + config.userId + '_' + state.view.activity.id; }
    function persistLocal() {
      if (!state.view || !state.draft) { return; }
      try { window.sessionStorage.setItem(localKey(), JSON.stringify({ version: state.view.attempt.version,
        stage: state.view.attempt.current_stage, draft: state.draft })); } catch (error) { /* Server save remains available. */ }
    }
    function protectDraft(record) {
      if (!state.view || !record || !record.draft) { return; }
      try {
        var key = localKey() + '_conflicts', records = JSON.parse(window.sessionStorage.getItem(key) || '[]');
        if (!Array.isArray(records)) { records = []; }
        var signature = JSON.stringify([record.version,record.stage,record.draft]);
        if (!records.some(function(item){return item.signature===signature;})) {
          records.push({signature:signature,version:record.version,stage:record.stage,draft:record.draft,savedAt:new Date().toISOString()});
          window.sessionStorage.setItem(key,JSON.stringify(records));
        }
      } catch (error) { /* The current editor still contains the unsaved work. */ }
    }
    function recoveryPanel(view) {
      if (view.read_only || view.review_only) { return; }
      var records=[];
      try { records=JSON.parse(window.sessionStorage.getItem(localKey()+'_conflicts')||'[]'); } catch(error) { return; }
      if(!Array.isArray(records)||!records.length){return;}
      var box=element('section','ojprimm-card ojprimm-recovery');box.setAttribute('data-primm-conflict','');box.setAttribute('aria-label','저장 충돌 초안 복구');
      box.appendChild(element('h3','','저장하지 못한 내 초안이 있습니다'));
      box.appendChild(element('p','','서버 기록과 다른 버전의 초안을 보관했습니다. 비교한 뒤 직접 선택하세요. 선택하기 전에는 보관한 초안을 지우거나 덮어쓰지 않습니다.'));
      var server=textarea('server_response',4);server.readOnly=true;server.value=(view.attempt.responses||{})[view.attempt.current_stage]||'';box.appendChild(field('현재 서버 응답',server));
      records.forEach(function(record,index){
        var details=element('details','ojprimm-conflict-copy');details.open=index===records.length-1;
        details.appendChild(element('summary','',(stageLabels[record.stage]||record.stage)+' 단계 · 내 초안 '+(index+1)+' · 서버 버전 '+record.version));
        var response=textarea('local_response_'+index,4);response.readOnly=true;response.value=record.draft.response||'';details.appendChild(field('내가 작성한 응답 · 선택해서 복사할 수 있습니다',response));
        var code=textarea('local_code_'+index,6);code.readOnly=true;code.value=record.draft.code||'';code.classList.add('ojprimm-code-input');details.appendChild(field('내 코드 초안',code));
        if(record.stage===view.attempt.current_stage){details.appendChild(button('이 초안으로 이어쓰기',function(){
          state.draft={response:record.draft.response||'',code:record.draft.code||'',supportState:record.draft.supportState||''};
          var input=content.querySelector('[name=stage_response]'),draft=content.querySelector('[name=draft_code]');if(input){input.value=state.draft.response;}if(draft){draft.value=state.draft.code;}
          changed();message('선택한 초안을 편집기에 복구했습니다. 보관본은 직접 정리할 때까지 유지합니다.');if(input){input.focus();}
        },true));}else{details.appendChild(element('p','ojp-field-help','서버의 현재 단계가 달라 자동 대입하지 않습니다. 필요한 내용을 복사해 보관하세요. 최초 예측은 바뀌지 않습니다.'));}
        details.appendChild(button('이 보관본 정리',function(){
          var remaining=records.filter(function(item){return item.signature!==record.signature;});records=remaining;window.sessionStorage.setItem(localKey()+'_conflicts',JSON.stringify(remaining));details.remove();message('직접 선택한 보관본을 정리했습니다.');
        }));box.appendChild(details);
      });content.appendChild(box);return box;
    }
    function changed() {
      state.dirty += 1; persistLocal(); message('작성 내용을 저장할 준비가 되었습니다.');
      var advance=content.querySelector('[data-primm-advance]');if(advance&&state.view&&(state.view.practice_required||state.view.activity.practice_required)&&state.view.attempt.current_stage==='modify'){advance.disabled=state.practiceSource!==state.draft.code;}
      window.clearTimeout(state.timer); state.timer = window.setTimeout(function () { save(false).catch(showError); }, 800);
      if (state.makeSubmission) { state.makeSubmission.paint(); }
    }
    function showError(error) {
      if(error.status===409&&state.view&&state.draft){protectDraft({version:state.view.attempt.version,stage:state.view.attempt.current_stage,draft:state.draft});}
      message(error.status === 409 ? error.message + ' 내 초안을 별도로 보관했습니다. 새로고침하면 서버 기록과 비교하고 복구할 수 있습니다.' : error.message, true);
    }
    async function save(advance) {
      window.clearTimeout(state.timer);
      if (state.saving) { await state.saving; return save(advance); }
      if (!state.view || state.view.read_only || state.view.review_only || (!advance && state.dirty === state.saved)) { return state.view; }
      var revision = state.dirty;
      var renderGeneration = state.disposed;
      var stage = state.view.attempt.current_stage;
      var responses = {}; responses[stage] = state.draft.response;
      var input = { expected_version: state.view.attempt.version, stage: stage, responses: responses,
        draft_code: state.draft.code, advance: Boolean(advance) };
      if (stage === 'modify') { input.support_state = state.draft.supportState; }
      message('작성 내용을 저장하고 있습니다.');
      var action = api('/activities/' + state.view.activity.id + '/progress', input);
      state.saving = action;
      try {
        var view = await action;
        if (renderGeneration !== state.disposed) { return view; }
        state.view = view; state.saved = revision;
        if (revision === state.dirty) {
          try { window.sessionStorage.removeItem(localKey()); } catch (error) { /* Optional local recovery. */ }
        } else { persistLocal(); }
        message('저장되었습니다. 다음에 접속해도 이어서 진행할 수 있습니다.');
        if (advance) { renderActivity(view); }
        else if (state.makeSubmission) { state.makeSubmission.receive(view, input.draft_code); }
        return view;
      } catch (error) { persistLocal(); throw error; }
      finally { if (state.saving === action) { state.saving = null; } }
    }

    function stopSubmission() {
      if (state.makeSubmission) { state.makeSubmission.dispose(); state.makeSubmission = null; }
    }
    function judgeAssessment() {
      var assessment = content.querySelector('[data-primm-judge-status]');
      if (!assessment || !state.view) { return; }
      var attempt = state.view.attempt;
      assessment.textContent = attempt.is_accepted ? '마지막 확인 당시 본인의 정답 제출을 확인했습니다.'
        : '정답 제출 미확인 · 과정 진행과 공식 채점 결과는 별개입니다.';
      if (attempt.verified_at) { assessment.textContent += ' 마지막 본인 제출 확인: ' + attempt.verified_at + ' UTC. 확인 당시의 기록이며 현재 결과는 제출 화면에서 다시 확인하세요.'; }
    }
    function renderMakeSubmission(panel, view, generation) {
      var projection = view.make_submission || {available:false,status:'unavailable',watch:{active:false},submission:null,recent:null};
      var area = element('section', 'ojprimm-card'); area.setAttribute('data-primm-make-submission', '');
      area.appendChild(element('h4', '', '이 문제의 최근 내 제출'));
      area.appendChild(element('p', 'ojp-field-help', '채점 화면에 저장한 초안과 수업 언어를 자동으로 채웁니다. 코드를 확인한 뒤 직접 제출하세요. PRIMM으로 돌아오면 새 본인 제출의 채점 결과를 확인합니다.'));
      var resultBox = element('div', 'ojprimm-make-result'); resultBox.setAttribute('role', 'status'); resultBox.setAttribute('aria-live', 'polite');
      var fallback = element('div', 'ojprimm-make-fallback'); fallback.hidden = true;
      var busy = false, disposed = false, suspended = false, timer = null, abort = null, comparisonDraft = state.draft.code, lastPaint = '', stopped = false, needsReload = false;
      var prepared = null, fallbackTimer = null;
      var pollLimit = 12 * 60 * 1000, pollInterval = 4000, unchangedPolls = 0, lastPollSignature = '';
      function alive() { return !disposed && generation === state.disposed && state.view && state.view.activity.id === view.activity.id; }
      function allowed() {
        return alive() && projection.available === true && !state.view.read_only && !state.view.review_only && !state.view.stale
          && ['review','withdrawn','draft'].indexOf(state.view.activity.status || state.view.activity.state) === -1;
      }
      function nativeUrl() {
        try {
          var url = new URL(state.view.target_url || '', window.location.href);
          if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)
            || url.pathname !== '/problems/' + view.activity.target_problem_id) { return null; }
          url.pathname = '/submit/' + view.activity.target_problem_id; return url.href;
        } catch (error) { return null; }
      }
      function launchUrl(value) {
        try {
          var expected = new URL(nativeUrl()), url = new URL(value.url || '', window.location.href);
          if (url.origin !== expected.origin || url.protocol !== expected.protocol || url.pathname !== expected.pathname
            || url.hash || url.username || url.password || url.href.length > 8192
            || !Number.isInteger(value.expires_at) || value.expires_at * 1000 <= Date.now()
            || ['python','c','cpp','java'].indexOf(value.language) === -1) { return null; }
          var keys = Array.from(url.searchParams.keys());
          if (keys.some(function (key) { return ['draft','class_id','group_id'].indexOf(key) === -1 || url.searchParams.getAll(key).length !== 1; })
            || !url.searchParams.get('draft') || url.searchParams.get('draft').length > 4096
            || ['class_id','group_id'].some(function (key) { return url.searchParams.get(key) !== expected.searchParams.get(key); })) { return null; }
          return url.href;
        } catch (error) { return null; }
      }
      function discardFallback() {
        prepared = null; window.clearTimeout(fallbackTimer); fallbackTimer = null;
        if (!fallback.hidden) { fallback.replaceChildren(element('p', 'ojprimm-notice', '초안이 바뀌었거나 전달 시간이 지났습니다. 채점 화면에서 제출하기를 눌러 최신 초안을 다시 준비하세요.')); }
      }
      function checkFallback() {
        if (prepared && (prepared.revision !== state.dirty || prepared.code !== state.draft.code || prepared.expiresAt <= Date.now())) { discardFallback(); }
      }
      function lockPreparation() {
        var focused = document.activeElement;
        var controls = Array.from(content.querySelectorAll('button,input,textarea,select')).map(function (control) { return {control:control,disabled:control.disabled}; });
        controls.forEach(function (item) { item.control.disabled = true; });
        return function () { controls.forEach(function (item) { item.control.disabled = item.disabled; }); if (alive() && focused && content.contains(focused) && !focused.disabled) { focused.focus(); } };
      }
      fallback.addEventListener('click', function (event) { checkFallback(); if (!prepared) { event.preventDefault(); } });
      function pending() { return Boolean((projection.watch || {}).active || (projection.submission || {}).processing); }
      function startedAt() {
        var watch = projection.watch || {}, submission = projection.submission || {};
        if (watch.started_at) {
          var date = String(watch.started_at), parsed = Date.parse(/(?:Z|[+-]\d\d:\d\d)$/.test(date) ? date : date.replace(' ', 'T') + 'Z');
          if (Number.isFinite(parsed)) { return parsed; }
        }
        var key = localKey() + '_submission_poll_' + (submission.id || 'watch'), value;
        try { value = Number(window.sessionStorage.getItem(key)); } catch (error) { /* Local deadline below. */ }
        if (!value) { value = Date.now(); try { window.sessionStorage.setItem(key, String(value)); } catch (error) { /* Server watch still survives refresh. */ } }
        return value;
      }
      function expired() { return pending() && Date.now() - startedAt() >= pollLimit; }
      function blockingWatch() { return Boolean((projection.submission || {}).processing || ((projection.watch || {}).active && !expired())); }
      function clearPoll() { window.clearTimeout(timer); timer = null; }
      function schedule() {
        clearPoll();
        if (!allowed() || suspended || busy || stopped || projection.status === 'unavailable' || document.visibilityState === 'hidden' || !pending() || expired()) { return; }
        var ceiling = projection.status === 'processing' ? 12000 : 30000;
        var delay = Math.min(ceiling, pollInterval * Math.pow(2, Math.min(unchangedPolls, 3)));
        timer = window.setTimeout(function () { check((projection.submission || {}).id ? 'linked' : 'watch', false); }, delay);
      }
      function paint() {
        if (!alive()) { return; }
        checkFallback();
        launch.disabled = !allowed() || !nativeUrl() || busy || Boolean((projection.submission || {}).processing);
        previous.disabled = !allowed() || busy || blockingWatch();
        refresh.disabled = !allowed() || busy;
        refresh.textContent = needsReload ? '서버 기록 다시 불러오기' : '채점 결과 다시 확인';
        var signature = JSON.stringify([projection, busy, stopped, needsReload, state.draft.code === comparisonDraft, expired()]);
        if (signature === lastPaint) { return; } lastPaint = signature; resultBox.replaceChildren();
        var submission = projection.submission;
        if (!allowed()) { resultBox.appendChild(element('p', 'ojp-muted', '현재 화면에서는 제출 결과를 연결할 수 없습니다. 학생의 현재 제작 단계에서 확인하세요.')); return; }
        if (projection.status === 'unavailable') { resultBox.appendChild(element('p', 'ojprimm-notice', '최근 제출을 연결하지 못했습니다. 채점 화면에서 결과를 확인하세요. 저장된 결과가 있다면 마지막 확인 기록입니다.')); }
        if (submission) {
          var labels = {4:'맞았습니다',5:'출력 형식을 확인해 주세요',6:'오답입니다',7:'시간 제한을 초과했습니다',8:'메모리 제한을 초과했습니다',9:'출력 제한을 초과했습니다',10:'실행 오류가 발생했습니다',11:'컴파일 오류가 있습니다'};
          var title = submission.processing ? '채점 중입니다. 결과가 나오면 확인합니다.' : (labels[submission.result_code] || submission.label || '채점 결과를 확인했습니다.');
          resultBox.appendChild(element('p', 'ojprimm-result', (projection.status === 'unavailable' ? '마지막 확인 기록 · ' : '') + title));
          resultBox.appendChild(element('p', 'ojp-field-help', '제출 #' + submission.id + (submission.submitted_at ? ' · ' + submission.submitted_at + ' UTC' : '') + ' · 이 문제에 대한 본인 제출 기록입니다.'));
          if (state.draft.code !== comparisonDraft) { resultBox.appendChild(element('p', 'ojprimm-notice', '현재 초안이 바뀌었습니다. 제출 코드와 비교하려면 채점 결과를 다시 확인하세요.')); }
          else if (submission.draft_matches === false) { resultBox.appendChild(element('p', 'ojprimm-notice', '현재 초안과 다른 코드의 제출 결과입니다.')); }
          else if (submission.draft_matches === null || submission.draft_matches === undefined) { resultBox.appendChild(element('p', 'ojp-field-help', '제출 원문을 확인할 수 없어 현재 초안과 비교할 수 없습니다.')); }
          else { resultBox.appendChild(element('p', 'ojp-field-help', '현재 저장한 초안과 제출 코드가 일치합니다.')); }
          if (submission.verified_at) { resultBox.appendChild(element('p', 'ojp-field-help', '결과 확인 시각: ' + submission.verified_at + ' UTC · 재채점으로 달라질 수 있습니다.')); }
          resultBox.appendChild(link('OJ에서 제출 결과 보기', submission.detail_url));
        } else if ((projection.watch || {}).active) {
          resultBox.appendChild(element('p', '', '새 본인 제출을 기다리고 있습니다. 채점 화면에서 직접 제출한 뒤 이 탭으로 돌아오세요.'));
        } else {
          resultBox.appendChild(element('p', 'ojp-muted', '최근 제출을 연결하지 못했습니다. 채점 화면에서 결과를 확인하세요. 이전 기록을 사용하려면 아래 버튼을 직접 선택하세요.'));
        }
        if (expired() || stopped) { resultBox.appendChild(element('p', 'ojprimm-notice', needsReload ? '기록 버전이 바뀌어 자동 확인을 멈췄습니다. 내 초안은 보관했습니다.' : '자동 확인을 멈췄습니다. 채점 화면의 결과를 확인하거나 다시 확인 버튼을 눌러 주세요.')); }
      }
      function receive(result, draftCode) {
        if (!alive()) { return; }
        projection = result.make_submission || projection;
        var signature = JSON.stringify([projection.status, projection.submission, projection.recent, projection.watch]);
        unchangedPolls = signature === lastPollSignature ? unchangedPolls + 1 : 0;
        lastPollSignature = signature; comparisonDraft = draftCode; paint(); schedule();
      }
      async function request(suffix, input) {
        window.clearTimeout(state.timer);
        while (state.saving) { await state.saving; if (!alive()) { return null; } }
        await save(false);
        while (state.saving) { await state.saving; if (!alive()) { return null; } }
        if (!alive() || !allowed()) { return null; }
        var checkedDraft = state.view.attempt.draft_code || '';
        abort = new AbortController();
        var action = api('/activities/' + view.activity.id + suffix, Object.assign({expected_version:state.view.attempt.version}, input || {}), abort.signal);
        state.saving = action;
        try {
          var result = await action;
          if (!alive()) { return null; }
          // Polls update only server bookkeeping. Never replace the editor or the learner's unsaved draft.
          state.view = Object.assign({}, state.view, {attempt:result.attempt,make_submission:result.make_submission});
          if (state.dirty !== state.saved) { persistLocal(); }
          receive(result, checkedDraft); judgeAssessment(); return result;
        } finally { if (state.saving === action) { state.saving = null; } abort = null; }
      }
      function failure(error) {
        if (!alive() || error.name === 'AbortError') { return; }
        stopped = true; clearPoll();
        if (error.status === 409) { needsReload = true; }
        if (error.status === 401 || error.status === 403) { projection = Object.assign({}, projection, {available:false}); }
        showError(error); paint();
      }
      async function check(mode, explicit) {
        if (!allowed() || suspended || busy || document.visibilityState === 'hidden' || (!explicit && (stopped || projection.status === 'unavailable' || !pending() || expired()))) { return; }
        clearPoll(); busy = true; paint();
        if (explicit) { stopped = false; unchangedPolls = 0; }
        try { await request('/submission-check', {mode:mode}); }
        catch (error) { failure(error); }
        finally { busy = false; if (alive()) { paint(); schedule(); } }
      }
      var launch = button('채점 화면에서 제출하기', async function () {
        if (!allowed() || busy || (projection.submission || {}).processing) { return; }
        var popup = null;
        try { popup = window.open('about:blank', '_blank'); if (popup) { popup.opener = null; } } catch (error) { if (popup) { popup.close(); } popup = null; }
        busy = true; stopped = false; fallback.hidden = true; discardFallback(); clearPoll(); paint();
        var unlock = lockPreparation();
        try {
          var result = await request('/submission-launch');
          if (!result || !alive()) { if (popup && !popup.closed) { popup.close(); } return; }
          var delivery = result.submission_launch || {}, url = launchUrl(delivery);
          if (!url || result.make_submission.available !== true) { throw new Error('채점 화면을 준비하지 못했습니다. 현재 수업과 문제 상태를 확인해 주세요.'); }
          if (popup && !popup.closed) { popup.location.replace(url); }
          else {
            prepared = {url:url,revision:state.dirty,code:state.view.attempt.draft_code || '',expiresAt:delivery.expires_at * 1000};
            fallback.replaceChildren(element('p', 'ojprimm-notice', '새 탭이 차단되었습니다. 아래 링크로 초안과 언어가 채워진 채점 화면을 열고, 확인한 뒤 직접 제출하세요.'));
            fallback.appendChild(link('채점 화면 새 탭으로 열기', url));
            var sameTab = link('이 탭에서 채점 화면 열기', url); sameTab.removeAttribute('target'); fallback.appendChild(sameTab); fallback.hidden = false;
            fallbackTimer = window.setTimeout(checkFallback, Math.max(0, prepared.expiresAt - Date.now()));
          }
          message(result.make_submission.status === 'unavailable'
            ? '초안과 언어가 채워진 채점 화면에서 확인한 뒤 직접 제출하세요. 현재 연결 설정에서는 최근 제출을 자동 확인하지 못했습니다.'
            : '초안과 언어를 채울 채점 화면을 준비했습니다. 화면에서 확인한 뒤 직접 제출하세요. 이 탭으로 돌아오면 새 본인 제출 결과를 확인합니다.');
        } catch (error) { if (popup && !popup.closed) { popup.close(); } failure(error); }
        finally { unlock(); busy = false; if (alive()) { paint(); schedule(); } }
      }, true);
      var previous = button('이전 내 제출 결과 사용', function () { check('latest', true); });
      var refresh = button('채점 결과 다시 확인', function () {
        if (needsReload) { protectDraft({version:state.view.attempt.version,stage:state.view.attempt.current_stage,draft:state.draft}); openActivity(view.activity.id).catch(showError); return; }
        check((projection.submission || {}).id ? 'linked' : 'watch', true);
      });
      var controls = element('div', 'ojprimm-toolbar'); controls.appendChild(launch); controls.appendChild(previous); controls.appendChild(refresh);
      area.appendChild(resultBox); area.appendChild(controls); area.appendChild(fallback); panel.appendChild(area);
      function resume() { clearPoll(); if (!suspended && document.visibilityState !== 'hidden') { check((projection.submission || {}).id ? 'linked' : 'watch', false); } }
      function pageHide(event) { if (event.persisted) { suspended = true; clearPoll(); } else { dispose(); } }
      function pageShow(event) { if (event.persisted && alive()) { suspended = false; paint(); resume(); } }
      function dispose() { disposed = true; clearPoll(); window.clearTimeout(fallbackTimer); if (abort) { abort.abort(); } window.removeEventListener('focus', resume); document.removeEventListener('visibilitychange', resume); window.removeEventListener('pagehide', pageHide); window.removeEventListener('pageshow', pageShow); }
      var controller = {dispose:dispose,receive:receive,paint:paint}; state.makeSubmission = controller;
      window.addEventListener('focus', resume); document.addEventListener('visibilitychange', resume); window.addEventListener('pagehide', pageHide); window.addEventListener('pageshow', pageShow);
      paint(); schedule(); return controller;
    }

    async function teacher() {
      content.replaceChildren();
      var contexts = list(await api('/teacher/contexts'));
      if (!contexts.length) { empty(content, '담당 수업이 없습니다.', 'OJ Platform에서 담당 수업·그룹과 PRIMM 수업 관리 권한을 확인해 주세요.'); message('수업을 먼저 연결해 주세요.'); return; }
      var availability;
      try { availability = await api('/teacher/generation-status'); }
      catch (error) { availability = {available:false,message:error.message}; }
      var create = element('section', 'ojp-section'); create.appendChild(element('h2', '', '문제 하나로 PRIMM 수업 준비'));
      create.appendChild(element('p', 'ojp-muted', 'AI가 도입 코드, 예측·탐구 질문, 수정 과제와 제작 안내를 만듭니다. 기존 OJ로 예제와 예상 출력을 검증하고 오류를 자동 수정한 뒤 수업에 배포합니다.'));
      var form = element('form', 'ojp-form-stack ojprimm-generation-form'); form.setAttribute('data-primm-create', '');
      var context = select('context', contexts.map(function (item, index) { return [String(index), item.label || item.title || item.id]; }));
      var title = textInput('text', 'title', ''); title.maxLength = 160; title.placeholder = '비워 두면 AI가 만든 수업 제목을 사용합니다';
      var target = textInput('number', 'target_problem_id', ''); target.min = '1'; target.required = true;
      var language = select('language', [['python','Python'],['c','C'],['cpp','C++'],['java','Java']]);
      var executionLanguages=Array.isArray(availability.execution_languages)?availability.execution_languages:(Array.isArray(availability.languages)?availability.languages:[]);
      var executionAvailable=availability.execution_available===true;
      Array.from(language.options).forEach(function(option){option.disabled=!executionAvailable||executionLanguages.indexOf(option.value)===-1;if(option.disabled){option.textContent+=' · 실행 검사 필요';}});
      var languageNote=element('p','ojp-field-help');languageNote.setAttribute('data-primm-language-availability','');languageNote.setAttribute('role','status');
      var kind = select('support_kind', [['none', '없음 · 직접 수정'], ['code_ordering', 'Parsons · 코드 순서 배열'], ['code_completion', '코드 빈칸 완성']]);
      var find = element('div', 'ojprimm-search'); var search = textInput('search', 'search', ''); search.placeholder = '문제 제목 또는 번호 검색';
      var results = element('div', 'ojprimm-search-results');
      find.appendChild(field('문제 찾기', search));
      var searchButton = button('검색', async function () {
        searchButton.disabled = true;
        try {
          var problems = list(await api('/problems?search=' + encodeURIComponent(search.value))); results.replaceChildren();
          if (!problems.length) { results.appendChild(element('p', 'ojp-muted', '검색 결과가 없습니다. 접근 가능한 문제 번호를 직접 입력할 수 있습니다.')); }
          problems.forEach(function (problem) {
            var row = element('div', 'ojprimm-search-result'); var id = problem.problem_id || problem.id || problem.problemId;
            row.appendChild(element('span', '', id + ' · ' + problem.title));
            row.appendChild(button('제작 문제 선택', function () { target.value = id; })); results.appendChild(row);
          });
        } catch (error) { showError(error); } finally { searchButton.disabled = false; }
      });
      find.appendChild(searchButton); find.appendChild(results);
      [field('배포할 수업·그룹', context), field('활동 이름', title), find,
        field('최종 제작 문제 번호', target), field('수업 언어', language), field('수정 단계 도움 방식', kind)].forEach(function (item) { form.appendChild(item); });
      form.appendChild(languageNote);
      form.appendChild(element('p', 'ojp-field-help', '문제 본문·입출력 설명·공개 예제를 외부 AI로 보냅니다. 오류 수정 때는 AI가 만든 수업 자료와 해당 예제의 실행 진단도 보냅니다. 학생 코드와 단계별 응답은 보내지 않습니다.'));
      var useTemplate=textInput('checkbox','use_template'),templateField=field('선택: AI 대신 기본 예제로 준비 · OJ 검증 후 교사 검토',useTemplate);templateField.classList.add('ojprimm-wide');form.appendChild(templateField);
      var availableNote = element('p', availability.available ? 'ojp-field-help' : 'ojprimm-notice', availability.message || (availability.available ? 'AI 생성 준비가 되었습니다.' : '플랫폼 AI 작업 설정을 확인해 주세요.'));
      availableNote.setAttribute('data-primm-generation-availability',''); form.appendChild(availableNote);
      var submit = element('button', 'ojp-button ojp-button--primary', 'AI로 PRIMM 자동 생성'); submit.type = 'submit'; submit.disabled = !availability.available; form.appendChild(submit);
      var jobPanel = element('section','ojprimm-card ojprimm-generation'); jobPanel.setAttribute('data-primm-generation',''); jobPanel.hidden = true;
      var activeJob = null, generationTimer = null, generationBusy = false, jobSerial = 0, generationRenderKey = '';
      var processed = Object.create(null);
      var generationStorageKey = 'oj_primm_generation_' + config.userId;
      function rememberJob(job) {
        try { window.sessionStorage.setItem(generationStorageKey, String(job.id)); } catch (error) { /* URL also restores the job. */ }
        var url = new URL(window.location.href); url.searchParams.set('generation', job.id); window.history.replaceState(null,'',url.href);
      }
      function pendingJob(job) { return job && ['queued','running','retry_wait'].indexOf(job.status)!==-1; }
      function syncSubmit() {
        var supported=executionAvailable&&executionLanguages.indexOf(language.value)!==-1;
        submit.disabled = !supported || (!availability.available&&!useTemplate.checked) || generationBusy || (pendingJob(activeJob)&&activeJob.can_process!==false);submit.textContent=useTemplate.checked?'기본 예제로 PRIMM 준비':'AI로 PRIMM 자동 생성';
        var note=supported?'선택한 언어는 OJ 실행 검사를 통과했습니다.':'현재 선택한 언어는 OJ 실행 검사에 통과하지 않았습니다. 플랫폼 제출 설정에서 검사하거나 실행 가능한 다른 언어를 선택하세요. 학급 기본 설정은 그대로 보존합니다.';
        if(languageNote.textContent!==note){languageNote.textContent=note;}languageNote.className=supported?'ojp-field-help':'ojprimm-notice';
      }
      useTemplate.addEventListener('change',syncSubmit);
      language.addEventListener('change',syncSubmit);syncSubmit();
      function schedulePoll(id, serial) {
        window.clearTimeout(generationTimer);
        generationTimer = window.setTimeout(async function () {
          if (serial !== jobSerial) { return; }
          try { await receiveJob(await api('/teacher/generations/' + Number(id)), serial); }
          catch (error) { showError(error); if (error.status !== 401 && error.status !== 403) { schedulePoll(id, serial); } }
        }, 1800);
      }
      async function triggerProcess(job, serial) {
        if (processed[job.id] || serial !== jobSerial || job.can_process===false) { return; }
        processed[job.id] = true;
        try { await receiveJob(await api('/teacher/generations/' + Number(job.id) + '/process', {}), serial, true); }
        catch (error) { if (serial === jobSerial) { showError(error); schedulePoll(job.id, serial); } }
        finally { delete processed[job.id]; }
      }
      async function receiveJob(job, serial, fromProcess) {
        if (serial !== jobSerial || !job || !Number(job.id)) { return; }
        if (activeJob && activeJob.id === job.id && !pendingJob(activeJob) && pendingJob(job)) { return; }
        activeJob = job;if(job.can_process!==false){rememberJob(job);}syncSubmit();
        var renderKey=JSON.stringify([job.id,job.status,job.phase,job.repair_attempt,job.message,job.error,job.activity_id,job.activity_state,job.is_stalled,job.usage,job.next_attempt_at,job.can_cancel,job.can_resume,job.can_retry,job.is_expired||job.expired]);
        if(renderKey===generationRenderKey){if(pendingJob(job)&&job.can_process!==false){schedulePoll(job.id,serial);if(job.status==='queued'&&!fromProcess){triggerProcess(job,serial);}}return;}
        generationRenderKey=renderKey;
        jobPanel.hidden = false; jobPanel.replaceChildren(); jobPanel.dataset.generationStatus = job.status;
        jobPanel.dataset.generationPhase = job.phase || (job.status === 'queued' ? 'generating' : job.status);
        jobPanel.setAttribute('aria-live', 'polite');
        var jobVerified = job.verification && job.verification.status === 'verified' && job.verification.engine === 'oj';
        var labels = {queued:'생성 대기 중',running:'AI가 수업 자료를 만들고 있습니다',retry_wait:'연결 복구 후 다시 시도합니다',canceled:'생성을 취소했습니다',stopped:'관리자가 작업을 중단했습니다',ready:jobVerified ? 'OJ 검증 완료 · PRIMM 활동 준비 완료' : 'PRIMM 활동 준비 완료 · 실행 검증 전',failed:'수업 자료를 준비하지 못했습니다'};
        var phases = {generating:'AI가 수업 자료를 만들고 있습니다',verifying:'기존 OJ로 코드와 예상 출력을 검증하고 있습니다',repairing:'검증 오류를 AI가 수정하고 있습니다',quality:'수정 과제의 학습 의미와 난이도를 확인하고 있습니다',publishing:'OJ 검증을 통과한 수업 자료를 저장하고 있습니다'};
        jobPanel.appendChild(element('h3','',pendingJob(job) && (job.status === 'running' || ['verifying','repairing','quality','publishing'].indexOf(job.phase)!==-1) ? (phases[job.phase] || labels.running) : (labels[job.status] || '생성 상태 확인')));
        jobPanel.appendChild(element('p','ojp-muted','작업 #' + job.id + ' · 목표 문제 ' + (job.target_problem_id || target.value) + ' · ' + (job.language || language.value)));
        if (job.message) { jobPanel.appendChild(element('p','ojp-field-help',job.message)); }
        if(job.is_stalled){jobPanel.appendChild(element('p','ojprimm-notice','작업이 오래 대기하고 있습니다. 채점기·AI 연결을 확인하거나 작업을 취소한 뒤 재개하세요.'));}
        if(job.next_attempt_at){jobPanel.appendChild(element('p','ojp-field-help','다음 재시도: '+job.next_attempt_at+' UTC'));}
        if(job.usage){jobPanel.appendChild(element('p','ojp-field-help','이 작업의 AI 호출 '+(Number(job.usage.ai_calls)||0)+'회 · 코드 실행 '+(Number(job.usage.execution_count)||0)+'회'));}
        ['cancel','resume','stop'].forEach(function(action){
          var allowed=action==='cancel'?(job.can_cancel===true):(action==='resume'?job.can_resume===true:(config.isAdmin&&pendingJob(job)));
          if(!allowed){return;}jobPanel.appendChild(button({cancel:'생성 취소',resume:'작업 재개',stop:'관리자 중단'}[action],async function(){
            try{jobSerial+=1;activeJob=null;generationRenderKey='';await receiveJob(await api('/teacher/generations/'+Number(job.id)+'/'+action,{}),jobSerial);}catch(error){showError(error);}
          }));
        });
        if (Number(job.repair_attempt) > 0) {
          jobPanel.appendChild(element('p','ojp-field-help','자동 수정 ' + Number(job.repair_attempt) + ' / ' + (Number(job.max_repairs) || 2) + '회 · 수정 뒤 전체 예제를 다시 검증합니다.'));
        }
        if (pendingJob(job)) {
          jobPanel.appendChild(element('p','','검증을 통과한 자료만 수업에 배포합니다. 페이지를 새로고침해도 같은 작업을 이어서 확인합니다.'));
          if(job.can_process===false){jobPanel.appendChild(button('관리자 현황 새로고침',async function(){try{var overview=await api('/teacher/generations-summary'),latest=list(overview.jobs).find(function(item){return Number(item.id)===Number(job.id);});if(latest){generationRenderKey='';await receiveJob(latest,serial);}}catch(error){showError(error);}}));}
          else{schedulePoll(job.id, serial);if(job.status==='queued'&&!fromProcess){triggerProcess(job,serial);}}
        } else {
          window.clearTimeout(generationTimer);
          if (job.status === 'failed') {
            jobPanel.appendChild(element('p','ojprimm-notice',job.error || '잠시 후 다시 시도해 주세요.'));
            jobPanel.appendChild(element('p','ojp-field-help','이 작업의 자료는 학생에게 배포되지 않았습니다. 원인을 확인한 뒤 다시 시도하세요.'));
            if(job.can_retry!==false&&!job.is_expired&&!job.expired){
            var retry = button('생성 다시 시도', async function () {
              retry.disabled = true; generationBusy = true; syncSubmit();
              try { var retried=await api('/teacher/generations/' + Number(job.id) + '/retry', {});delete processed[job.id];jobSerial+=1;activeJob=null;await receiveJob(retried,jobSerial); }
              catch (error) { showError(error); retry.disabled = false; }
              finally { generationBusy = false; syncSubmit(); }
            }); jobPanel.appendChild(retry);
            }
            if((job.is_expired||job.expired||job.can_retry===false)&&job.new_request&&job.can_process!==false){
              var replacementKey=requestKey(),freshStart=button('새 생성 시작',async function(){freshStart.disabled=true;generationBusy=true;syncSubmit();try{
                var source=job.new_request,endpoint='/teacher/generations',input={request_key:replacementKey,force:true};
                if(source.kind==='validate'||source.kind==='regenerate'){endpoint='/teacher/activities/'+Number(source.activity_id)+'/'+source.kind;if(source.kind==='regenerate'){input.scope=source.scope||'all';}}
                else{['context_type','context_id','target_problem_id','language','support_kind','title','use_template'].forEach(function(key){if(source[key]!==undefined){input[key]=source[key];}});}
                var replacement=await api(endpoint,input);jobSerial+=1;activeJob=null;generationRenderKey='';await receiveJob(replacement,jobSerial);message(source.kind==='validate'?'만료된 검증을 새 요청으로 시작했습니다. AI 생성은 호출하지 않습니다.':'이전 요청을 재전송하지 않고 새 생성 요청을 시작했습니다.');
              }catch(error){showError(error);freshStart.disabled=false;}finally{generationBusy=false;syncSubmit();}},true);jobPanel.appendChild(freshStart);
            }
          } else if (job.status === 'ready' && Number(job.activity_id)) {
            var waitingReview=job.activity_state&&job.activity_state!=='active';
            jobPanel.appendChild(element('p','',jobVerified ? (waitingReview?'OJ 자동 검증을 통과한 자료를 보관했습니다. 교사 검토·배포 관리에서 공개 여부를 확인하세요.':'OJ 자동 검증을 통과한 자료가 수업에 배포되었습니다. 생성된 자료를 학생 화면에서 확인하세요.') : '이전 버전에서 생성한 활동입니다. 코드와 예상 출력은 실제 실행으로 검증되지 않았습니다.'));
            list((job.quality_summary||{}).reasons).forEach(function(reason){jobPanel.appendChild(element('p','ojprimm-notice',reason));});
            jobPanel.appendChild(element('p','ojp-field-help',jobVerified ? '예제 검증은 제공된 시험 사례의 코드·출력 일치를 확인합니다. 수업 전에 질문과 수정 과제의 적합성도 확인하세요.' : 'OJ 검증을 적용하려면 새 활동을 생성하세요. 수업 전에 예제와 수정 과제를 확인하세요.'));
            jobPanel.appendChild(link('생성된 활동 확인', '/oj-addons/oj-primm/learn?activity=' + Number(job.activity_id)));
            jobPanel.appendChild(button('활동 검토·배포 관리',function(){manageActivity(Number(job.activity_id)).catch(showError);}));
            await refresh();
          }
        }
      }
      var overview = element('section', 'ojp-section'); overview.appendChild(element('h2', '', '배포한 활동과 학생 진행'));
      var rows = element('div', 'ojprimm-activity-list'); overview.appendChild(rows);
      var activityCursor='',activityNext=button('활동 더 보기',function(){refresh(true).catch(showError);});activityNext.hidden=true;overview.appendChild(activityNext);
      async function refresh(append) {
        var chosen = contexts[Number(context.value)];
        var data = await api('/teacher/activities?context_type=' + encodeURIComponent(chosen.type || chosen.context_type)
          + '&context_id=' + encodeURIComponent(chosen.id || chosen.context_id)+'&limit=25'+(append&&activityCursor?'&cursor='+encodeURIComponent(activityCursor):''));
        if(!append){rows.replaceChildren();}activityCursor=data.next_cursor||'';activityNext.hidden=!activityCursor;
        var limitations = [];
        if (list(data.activities).length >= 100) { limitations.push('활동 최근 ' + list(data.activities).length + '개 표시'); }
        if (list(data.attempts).length >= (Number(data.report_limit) || 200)) { limitations.push('학생 진행 최근 ' + list(data.attempts).length + '개 표시'); }
        if (limitations.length) {
          var limitNote = element('p', 'ojp-field-help', limitations.join(' · ') + ' · 전체 기록이 아닙니다.');
          limitNote.setAttribute('data-primm-report-limit', ''); rows.appendChild(limitNote);
        }
        if (!list(data.activities).length) { empty(rows, '아직 배포한 활동이 없습니다.', '위에서 문제를 골라 첫 활동을 만드세요.'); return; }
        list(data.activities).forEach(function (activity) {
          var card = element('article', 'ojprimm-card'); card.appendChild(element('h3', '', activity.title));
          card.appendChild(element('p', 'ojp-muted', '제작 문제 ' + activity.target_problem_id + ' · ' + ({none:'도움 없음',code_ordering:'코드 순서 배열',code_completion:'코드 빈칸 완성'}[activity.support_kind] || '')));
          card.appendChild(link('학생 활동 열기', '/oj-addons/oj-primm/learn?activity=' + activity.id));
          card.appendChild(button('검토·수정·배포 관리',function(){manageActivity(activity.id).catch(showError);}));
          var attempts = list(data.attempts).filter(function (attempt) { return Number(attempt.activity_id) === Number(activity.id); });
          if (!attempts.length) { card.appendChild(element('p', 'ojp-muted', '아직 저장된 학생 응답이 없습니다.')); }
          attempts.forEach(function (attempt) {
            var details = element('details', 'ojprimm-attempt'); details.appendChild(element('summary', '',
              (attempt.display_name || attempt.user_name || '학생 #' + (attempt.user_id || attempt.learner_id)) + ' · '
              + (stageLabels[attempt.current_stage] || '예측') + ' · ' + (attempt.is_accepted ? '마지막 확인 당시 정답' : '정답 제출 미확인')));
            if (attempt.verified_at) { details.appendChild(element('p','ojp-field-help','마지막 본인 제출 확인: ' + attempt.verified_at + ' UTC · 확인 당시의 결과이며 재채점 후 달라질 수 있습니다.')); }
            Object.keys(attempt.responses || {}).forEach(function (key) { details.appendChild(element('h4', '', stageLabels[key] || key)); details.appendChild(element('pre', 'ojprimm-answer', attempt.responses[key])); });
            if (attempt.draft_code) { details.appendChild(element('pre', 'ojprimm-code', attempt.draft_code)); }
            card.appendChild(details);
          }); rows.appendChild(card);
        });
      }
      var managementPanel=element('section','ojp-section ojprimm-management');managementPanel.hidden=true;managementPanel.setAttribute('data-primm-management','');
      function requestKey(){return window.crypto&&window.crypto.randomUUID?window.crypto.randomUUID():'primm-'+Date.now()+'-'+Math.random().toString(16).slice(2);}
      function contextQuery(){var chosen=contexts[Number(context.value)];return 'context_type='+encodeURIComponent(chosen.type||chosen.context_type)+'&context_id='+encodeURIComponent(chosen.id||chosen.context_id);}
      function sourceSummary(lesson){
        if(!lesson){return '';}var demo=lesson.demo||{},parts=[lesson.title,'학습 목표\n'+(lesson.objectives||[]).join('\n'),'도입 코드\n'+(demo.code||''),'도입 입력\n'+(demo.input||''),'도입 예상 출력\n'+(demo.expected_output||''),'도입 설명\n'+(demo.explanation||'')];
        (lesson.stages||[]).forEach(function(stage){parts.push([stage.label,stage.prompt,(stage.questions||[]).join('\n'),stage.task||'',stage.starter_code||''].join('\n'));list(stage.tests).forEach(function(test,index){parts.push('수정 사례 '+(index+1)+' 입력\n'+test.input+'\n예상 출력\n'+test.expected_output);});});
        var support=lesson.support||{};list(support.blocks).forEach(function(block,index){parts.push('도움 조각 '+(index+1)+'\n'+block.code);});if(support.template){parts.push('빈칸 코드\n'+support.template);}list(support.blanks).forEach(function(blank){parts.push('빈칸 '+blank.label+'\n'+blank.answer);});return parts.join('\n\n');
      }
      var operationBusy=false;
      async function startOperation(id,operation,input){
        if(operationBusy||(pendingJob(activeJob)&&activeJob.can_process!==false)){message('진행 중인 작업을 먼저 확인하거나 취소해 주세요.');return;}operationBusy=true;
        var payload=Object.assign({},input||{});delete payload.request_key;var signature=JSON.stringify([id,operation,payload]),stored=null,storageKey=generationStorageKey+'_operation';
        try{stored=JSON.parse(window.sessionStorage.getItem(storageKey)||'null');}catch(error){/* A fresh request still has a stable key. */}
        if(!stored||stored.signature!==signature){stored={signature:signature,key:requestKey()};}payload.request_key=stored.key;
        try{window.sessionStorage.setItem(storageKey,JSON.stringify(stored));}catch(error){/* The open form retains the key. */}
        try{var job=await api('/teacher/activities/'+id+'/'+operation,payload);window.sessionStorage.removeItem(storageKey);jobSerial+=1;activeJob=null;generationRenderKey='';await receiveJob(job,jobSerial);jobPanel.scrollIntoView({block:'nearest'});}finally{operationBusy=false;}
      }
      async function manageActivity(id,previousLesson){
        var data=await api('/teacher/activities/'+Number(id)+'/manage');managementPanel.hidden=false;managementPanel.replaceChildren();
        var activity=data.activity||{},lesson=data.lesson||null,revision=data.revision,stateLabel={active:'공개 중',review:'검토 필요',draft:'초안',withdrawn:'배포 회수'},quality=activity.quality||{};
        var heading=element('h2','','수업 자료 관리 · '+(activity.title||id));heading.tabIndex=-1;managementPanel.appendChild(heading);heading.focus();
        managementPanel.appendChild(element('p','ojp-field-help','버전 '+(activity.content_version||activity.version||revision||1)+' · '+(stateLabel[data.state]||data.state)+' · 수정하면 새 초안 버전을 만듭니다. 기존 학생의 학습 기록과 수업 버전은 유지됩니다.'));
        var actions=element('div','ojprimm-toolbar');actions.appendChild(link('다섯 단계 미리 보기','/oj-addons/oj-primm/learn?activity='+id));
        actions.appendChild(button('검증 후 배포 준비',function(){startOperation(id,'validate',{expected_revision:revision,request_key:requestKey()}).catch(showError);}));
        var reviewAck=textInput('checkbox','acknowledge_review'),requiresReview=['review_required','needs_review'].indexOf(quality.status)!==-1;
        var publish=button('수업에 공개',async function(){try{await api('/teacher/activities/'+id+'/publish',{expected_revision:revision,acknowledge_review:reviewAck.checked});await manageActivity(id);await refresh();message('수업 자료를 공개했습니다.');}catch(error){showError(error);}},true);
        publish.disabled=Boolean(lesson&&lesson.verification&&lesson.verification.status!=='verified')||requiresReview;actions.appendChild(publish);
        actions.appendChild(button('배포 회수',async function(){try{await api('/teacher/activities/'+id+'/withdraw',{expected_revision:revision});await manageActivity(id);await refresh();message('새 학습을 막고 기존 기록을 보존했습니다.');}catch(error){showError(error);}}));managementPanel.appendChild(actions);
        list(quality.reasons||quality.notes).forEach(function(note){managementPanel.appendChild(element('p','ojprimm-notice',note));});
        if(requiresReview){managementPanel.appendChild(field('학습 목표·수정 요구·시험 사례를 확인했습니다',reviewAck));reviewAck.addEventListener('change',function(){publish.disabled=!reviewAck.checked||Boolean(lesson&&lesson.verification&&lesson.verification.status!=='verified');});}
        if(previousLesson&&lesson){var comparison=element('details','ojprimm-comparison');comparison.open=true;comparison.appendChild(element('summary','','새 버전 변경 내용 확인'));var columns=element('div','ojprimm-compare-grid');[['변경 전',previousLesson],['저장된 새 버전',lesson]].forEach(function(item){var value=textarea('version_comparison',10);value.readOnly=true;value.value=sourceSummary(item[1]);columns.appendChild(field(item[0],value));});comparison.appendChild(columns);managementPanel.appendChild(comparison);}
        var edit=element('form','ojp-form-stack');edit.setAttribute('data-primm-editor','');var name=textInput('text','edit_title',activity.title||'');name.maxLength=160;edit.appendChild(field('활동 이름',name));
        var draft=lesson?JSON.parse(JSON.stringify(lesson)):null,editors=[];
        function editText(parent,label,name,value,rows,onSave){var input=textarea(name,rows||3);input.maxLength=65536;input.value=value||'';parent.appendChild(field(label,input));editors.push(function(){onSave(input.value);});return input;}
        if(draft){
          editText(edit,'학습 목표 · 한 줄에 하나','edit_objectives',(draft.objectives||[]).join('\n'),3,function(value){draft.objectives=value.split('\n').filter(function(item){return item.trim();});});
          var demo=element('details','ojprimm-edit-section');demo.open=true;demo.appendChild(element('summary','','도입 예제와 출력'));
          editText(demo,'도입 예제 코드','edit_demo_code',draft.demo.code,7,function(value){draft.demo.code=value;});
          editText(demo,'도입 예제 입력','edit_demo_input',draft.demo.input,2,function(value){draft.demo.input=value;});
          editText(demo,'도입 예제 예상 출력','edit_demo_output',draft.demo.expected_output,2,function(value){draft.demo.expected_output=value;});
          editText(demo,'예제 설명 · 예측 단계 뒤에 표시','edit_demo_explanation',draft.demo.explanation,3,function(value){draft.demo.explanation=value;});edit.appendChild(demo);
          draft.stages.forEach(function(stage,index){var box=element('details','ojprimm-edit-section');box.appendChild(element('summary','',(index+1)+' · '+stageLabels[stage.key]));
            editText(box,'단계 안내','edit_prompt_'+stage.key,stage.prompt,3,function(value){stage.prompt=value;});
            editText(box,'질문 · 한 줄에 하나','edit_questions_'+stage.key,(stage.questions||[]).join('\n'),3,function(value){stage.questions=value.split('\n').filter(function(item){return item.trim();});});
            if(stage.task!==undefined){editText(box,'과제','edit_task_'+stage.key,stage.task,3,function(value){stage.task=value;});}
            if(stage.key==='modify'){
              editText(box,'수정 활동 기준 코드 · 학생의 초기 답안과 별개','edit_modify_code',stage.starter_code,8,function(value){stage.starter_code=value;});
              var tests=element('div','ojprimm-edit-tests');stage.tests.forEach(function(test,testIndex){editText(tests,'사례 '+(testIndex+1)+' 입력','edit_test_input_'+testIndex,test.input,2,function(value){test.input=value;});editText(tests,'사례 '+(testIndex+1)+' 예상 출력','edit_test_output_'+testIndex,test.expected_output,2,function(value){test.expected_output=value;});});box.appendChild(tests);
              box.appendChild(button('시험 사례 추가',function(){if(stage.tests.length>=8){message('시험 사례는 최대 8개입니다.',true);return;}var test={input:'',expected_output:''};stage.tests.push(test);var testIndex=stage.tests.length-1;editText(tests,'사례 '+(testIndex+1)+' 입력','edit_test_input_'+testIndex,'',2,function(value){test.input=value;});editText(tests,'사례 '+(testIndex+1)+' 예상 출력','edit_test_output_'+testIndex,'',2,function(value){test.expected_output=value;});}));
            }edit.appendChild(box);
          });
          var helper=element('details','ojprimm-edit-section');helper.appendChild(element('summary','','선택 도움 자료 편집'));var supportKind=select('edit_support_kind',[['none','없음'],['code_ordering','Parsons 코드 순서 배열'],['code_completion','코드 빈칸 완성']]);supportKind.value=draft.support.kind;supportKind.disabled=true;helper.appendChild(field('현재 도움 방식 · 자료를 바꾸려면 새 생성에서 선택하세요',supportKind));
          var helpSource=element('div','ojprimm-help-editor');helper.appendChild(helpSource);
          function renderHelp(){helpSource.replaceChildren();helpSource.appendChild(element('p','ojp-field-help','수정 기준 코드를 바꾸면 조각·빈칸 자료도 함께 바꿔야 합니다. 구성을 새로 만들려면 아래의 수정 단계 AI 재생성을 사용하세요.'));if(supportKind.value==='code_ordering'){var blocks=JSON.parse(JSON.stringify(draft.support.blocks||[]));blocks.forEach(function(block,index){editText(helpSource,'코드 조각 '+(index+1),'edit_ordering_block_'+index,block.code,3,function(value){block.code=value.endsWith('\n')?value:value+'\n';});});helpSource.appendChild(element('p','ojp-field-help','모든 조각을 순서대로 연결한 내용이 수정 기준 코드와 같아야 합니다. 조각 식별자와 들여쓰기는 보존합니다.'));editors.push(function(){if(supportKind.value==='code_ordering'){draft.support={kind:'code_ordering',blocks:blocks};}});}
            else if(supportKind.value==='code_completion'){var existing=draft.support.kind==='code_completion'?draft.support:{kind:'code_completion',template:'',blanks:[]};editText(helpSource,'빈칸이 표시된 코드','edit_completion_template',existing.template,7,function(value){if(supportKind.value==='code_completion'){existing.template=value;draft.support=existing;}});existing.blanks.forEach(function(blank,index){editText(helpSource,'빈칸 '+(index+1)+' 안내 이름','edit_blank_label_'+index,blank.label,1,function(value){blank.label=value;});editText(helpSource,'빈칸 '+(index+1)+' 기준 답안','edit_blank_'+index,blank.answer,2,function(value){blank.answer=value;});});}
          }supportKind.addEventListener('change',renderHelp);renderHelp();editors.push(function(){if(supportKind.value==='none'){draft.support={kind:'none'};}});edit.appendChild(helper);
        }
        var saveEdit=element('button','ojp-button ojp-button--primary','새 버전 초안으로 저장');saveEdit.type='submit';edit.appendChild(saveEdit);
        var editRequestKey=requestKey(),editSignature='';edit.addEventListener('submit',async function(event){event.preventDefault();saveEdit.disabled=true;try{editors.forEach(function(saveValue){saveValue();});if(draft){draft.title=name.value||draft.title;}var patch={title:name.value,lesson:draft},nextSignature=JSON.stringify(patch);if(editSignature&&editSignature!==nextSignature){editRequestKey=requestKey();}editSignature=nextSignature;var result=await api('/teacher/activities/'+id+'/edit',{expected_revision:revision,request_key:editRequestKey,patch:patch});await manageActivity((result.activity||{}).id||id,lesson);await refresh();message('새 버전 초안을 저장했습니다. 코드·입출력 변경 내용은 전체 재검증을 거쳐 공개할 수 있습니다.');}catch(error){showError(error);}finally{saveEdit.disabled=false;}});managementPanel.appendChild(edit);
        var reuse=element('details','ojprimm-edit-section');reuse.appendChild(element('summary','','다른 수업에 재사용 · 부분 재생성'));var destination=select('clone_context',contexts.map(function(item,index){return[String(index),item.label||item.id];}));reuse.appendChild(field('복제할 수업·그룹',destination));var cloneKeys=Object.create(null),clone=button('이 자료를 선택한 수업에 복제',async function(){clone.disabled=true;try{var chosen=contexts[Number(destination.value)],binding=String(chosen.type||chosen.context_type)+':'+String(chosen.id||chosen.context_id);cloneKeys[binding]=cloneKeys[binding]||requestKey();var result=await api('/teacher/activities/'+id+'/clone',{context_type:chosen.type||chosen.context_type,context_id:chosen.id||chosen.context_id,request_key:cloneKeys[binding]});await manageActivity((result.activity||{}).id);await refresh();message('다른 수업에 재사용할 새 활동을 준비했습니다.');}catch(error){showError(error);}finally{clone.disabled=false;}});reuse.appendChild(clone);
        var scope=select('regenerate_scope',[['all','전체 자료'],['predict','예측 질문'],['run','실행 비교 질문'],['investigate','탐구 질문'],['modify','수정 과제·코드·도움 자료'],['make','제작 안내']]);reuse.appendChild(field('AI로 다시 만들 부분',scope));reuse.appendChild(button('선택한 부분 재생성',function(){startOperation(id,'regenerate',{scope:scope.value,request_key:requestKey()}).catch(showError);}));managementPanel.appendChild(reuse);
        if(list(data.versions).length){var versions=element('details','ojprimm-edit-section');versions.appendChild(element('summary','','버전 이력'));list(data.versions).forEach(function(version){versions.appendChild(button('버전 '+(version.content_version||version.version||version.revision||version.id)+' · '+(version.state||version.status||''),function(){manageActivity(version.id).catch(showError);}));});managementPanel.appendChild(versions);}
        managementPanel.appendChild(button('관리 화면 닫기',function(){managementPanel.hidden=true;}));managementPanel.scrollIntoView({block:'start'});
      }
      var settingsPanel=element('details','ojprimm-class-settings');settingsPanel.appendChild(element('summary','','선택: 학급 기본 설정'));var settingsBody=element('div','ojp-form-stack');settingsPanel.appendChild(settingsBody);
      async function loadSettings(){settingsBody.replaceChildren();try{var value=await api('/teacher/settings?'+contextQuery()),settings=value.settings||value;
        var controls={language:select('setting_language',[['python','Python'],['c','C'],['cpp','C++'],['java','Java']]),level:select('setting_level',[['beginner','기초'],['intermediate','중급'],['advanced','심화']]),minutes:textInput('number','setting_minutes',settings.minutes||45),prerequisites:textarea('setting_prerequisites',3),support_kind:select('setting_support',[['none','없음'],['code_ordering','Parsons 코드 순서 배열'],['code_completion','코드 빈칸 완성']]),auto_prepare:textInput('checkbox','setting_auto_prepare'),auto_publish:textInput('checkbox','setting_auto_publish')};
        controls.language.value=settings.default_language||settings.language||'python';controls.level.value=settings.level||'beginner';controls.prerequisites.value=(settings.prerequisites||[]).join('\n');controls.support_kind.value=settings.support_kind||'none';controls.minutes.min=15;controls.minutes.max=180;controls.auto_prepare.checked=settings.auto_prepare!==false;controls.auto_publish.checked=settings.auto_publish!==false;
        [['기본 언어','language'],['학습 수준','level'],['선행 개념 · 한 줄에 하나','prerequisites'],['수업 시간 · 분','minutes'],['기본 수정 도움','support_kind'],['학급 기본값으로 자료 자동 준비','auto_prepare'],['검증 후 자동 공개 · 품질 검토가 필요한 자료는 보류','auto_publish']].forEach(function(item){settingsBody.appendChild(field(item[0],controls[item[1]]));});
        language.value=controls.language.value;kind.value=controls.support_kind.value;syncSubmit();
        settingsBody.appendChild(button('학급 기본 설정 저장',async function(){try{await api('/teacher/settings?'+contextQuery(),{language:controls.language.value,level:controls.level.value,prerequisites:controls.prerequisites.value.split('\n').filter(function(item){return item.trim();}),minutes:Number(controls.minutes.value),support_kind:controls.support_kind.value,auto_prepare:controls.auto_prepare.checked,auto_publish:controls.auto_publish.checked});language.value=controls.language.value;kind.value=controls.support_kind.value;syncSubmit();message('학급 기본 설정을 저장했습니다. 다음 생성부터 적용합니다.');}catch(error){showError(error);}},true));
      }catch(error){settingsBody.appendChild(element('p','ojprimm-notice',error.message));}}
      var reportPanel=element('section','ojp-section ojprimm-report');reportPanel.setAttribute('data-primm-report','');reportPanel.appendChild(element('h2','','전체 학급 진행 확인'));var reportSearch=textInput('search','report_search','');var reportActivity=textInput('number','report_activity','');reportActivity.min=1;var reportRows=element('div','ojprimm-report-rows'),reportSummary=element('p','ojp-field-help'),reportNext=null,reportCursor='';
      reportPanel.appendChild(field('학생 이름 검색',reportSearch));reportPanel.appendChild(field('활동 번호 · 비우면 최근 활동',reportActivity));var reportTools=element('div','ojprimm-toolbar');reportPanel.appendChild(reportTools);reportPanel.appendChild(reportSummary);reportPanel.appendChild(reportRows);
      function reportQuery(cursor){return contextQuery()+'&limit=50&search='+encodeURIComponent(reportSearch.value)+(reportActivity.value?'&activity_id='+Number(reportActivity.value):'')+(cursor?'&cursor='+encodeURIComponent(cursor):'');}
      async function loadReport(append){var result=await api('/teacher/report?'+reportQuery(append?reportCursor:''));if(!append){reportRows.replaceChildren();}var counts=result.counts||{};reportSummary.textContent=(result.activity_id?'활동 #'+result.activity_id+' · ':'')+'검색 결과 '+(Number(result.total)||0)+'명 · 전체 학급 미시작 '+(Number(counts.not_started)||0)+' · 진행 '+(Number(counts.in_progress)||0)+' · 과정 완료 '+(Number(counts.process_complete)||0)+' · 정답 확인 '+(Number(counts.accepted)||0);
        list(result.items).forEach(function(item){var row=element('article','ojprimm-card');row.appendChild(element('h3','',item.display_name||'학생 #'+item.user_id));row.appendChild(element('p','',item.attempt?(stageLabels[item.current_stage||item.attempt.current_stage]||'예측')+' · '+(item.process_complete?'과정 완료':'진행 중'):'미시작'));row.appendChild(element('p','ojp-field-help',item.is_accepted?'마지막 확인 당시 정답 · '+(item.verified_at||'')+' UTC':'정답 제출 미확인'));if(item.attempt){var responses=element('details','ojprimm-attempt');responses.appendChild(element('summary','','응답과 코드 확인'));Object.keys(item.attempt.responses||{}).forEach(function(stage){responses.appendChild(element('h4','',stageLabels[stage]||stage));responses.appendChild(element('pre','ojprimm-answer',item.attempt.responses[stage]));});if(item.attempt.draft_code){responses.appendChild(element('pre','ojprimm-code',item.attempt.draft_code));}row.appendChild(responses);}reportRows.appendChild(row);});reportCursor=result.next_cursor||'';reportNext.hidden=!reportCursor;}
      reportTools.appendChild(button('진행 조회',function(){loadReport(false).catch(showError);},true));reportNext=button('다음 학생 더 보기',function(){loadReport(true).catch(showError);});reportNext.hidden=true;reportTools.appendChild(reportNext);
      reportTools.appendChild(button('전체 보고서 CSV 내려받기',async function(){try{var response=await window.fetch(config.restUrl.replace(/\/$/,'')+'/teacher/report.csv?'+reportQuery(''),{credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':config.nonce}});if(!response.ok){throw new Error('보고서를 내려받지 못했습니다. 수업 권한과 연결을 확인하세요.');}var objectUrl=URL.createObjectURL(await response.blob()),anchor=element('a');anchor.href=objectUrl;anchor.download='primm-progress.csv';anchor.click();window.setTimeout(function(){URL.revokeObjectURL(objectUrl);},1000);message('현재 검색 범위의 전체 보고서를 내려받았습니다.');}catch(error){showError(error);}}));
      var generationHistory=element('details','ojprimm-edit-section');generationHistory.appendChild(element('summary','','생성 작업과 사용량'));var historyRows=element('div','ojprimm-activity-list');generationHistory.appendChild(historyRows);
      generationHistory.addEventListener('toggle',async function(){if(!generationHistory.open){return;}try{
        var summary=await api('/teacher/generations-summary');historyRows.replaceChildren();
        if(summary.daily_usage){historyRows.appendChild(element('p','ojp-field-help',(summary.daily_usage.date||'오늘')+' UTC 일일 AI 호출 '+(Number(summary.daily_usage.ai_calls)||0)+'회'));}
        if(summary.usage){historyRows.appendChild(element('p','ojp-field-help','최근 최대 '+(Number(summary.sample_limit)||100)+'건 합계 · AI 호출 '+(Number(summary.usage.ai_calls)||0)+'회 · 코드 실행 '+(Number(summary.usage.execution_count)||0)+'회'));}
        if(summary.statuses&&Object.keys(summary.statuses).length){historyRows.appendChild(element('p','ojp-field-help','표시된 작업 상태 · '+Object.keys(summary.statuses).map(function(key){return key+' '+(Number(summary.statuses[key])||0)+'건';}).join(' · ')));}
        if(config.isAdmin){
          var automation=summary.automatic_preparation,background=summary.background_error;
          if(automation&&automation.last_code){historyRows.appendChild(element('p','ojp-field-help','자동 준비 최근 진단 · '+automation.last_code+' · '+(automation.last_at||'')+' UTC'));}
          if(automation&&automation.counts){historyRows.appendChild(element('p','ojp-field-help','자동 준비 누적 건수 · '+Object.keys(automation.counts).map(function(code){return code+' '+(Number(automation.counts[code])||0)+'건';}).join(' · ')));}
          if(background&&background.code){historyRows.appendChild(element('p','ojprimm-notice','백그라운드 최근 진단 · '+background.code+' · '+(background.at||'')+' UTC'));}
        }
        list(summary.jobs||summary.items).forEach(function(job){var row=element('div','ojprimm-card');row.appendChild(element('p','','작업 #'+job.id+' · '+job.status+(job.is_stalled?' · 장기 대기':'')));row.appendChild(button('작업 확인·재개',function(){jobSerial+=1;activeJob=null;generationRenderKey='';receiveJob(job,jobSerial).catch(showError);jobPanel.scrollIntoView({block:'nearest'});}));historyRows.appendChild(row);});
      }catch(error){historyRows.replaceChildren(element('p','ojprimm-notice',error.message));}});
      context.addEventListener('change', function () {refresh().catch(showError);loadSettings();loadReport(false).catch(showError);});
      form.addEventListener('submit', async function (event) {
        event.preventDefault(); if (!form.reportValidity()) { return; }
        if (!executionAvailable||executionLanguages.indexOf(language.value)===-1||(!availability.available&&!useTemplate.checked)||generationBusy || (pendingJob(activeJob)&&activeJob.can_process!==false)) { syncSubmit(); return; }
        generationBusy = true; syncSubmit();
        try {
          var chosen = contexts[Number(context.value)];
          var input = {title:title.value,context_type:chosen.type || chosen.context_type,context_id:chosen.id || chosen.context_id,
            target_problem_id:Number(target.value),language:language.value,support_kind:kind.value};
          if(useTemplate.checked){input.use_template=true;}
          var signature = JSON.stringify(input), pending = null;
          try { pending = JSON.parse(window.sessionStorage.getItem(generationStorageKey + '_request') || 'null'); } catch (error) { /* New request below. */ }
          if (!pending || pending.signature !== signature) {
            var requestKey = window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,function(character){var value=Math.floor(Math.random()*16);return(character==='x'?value:(value&3|8)).toString(16);});
            pending = {signature:signature,key:requestKey};
            try { window.sessionStorage.setItem(generationStorageKey + '_request', JSON.stringify(pending)); } catch (error) { /* Server request still has an idempotency key. */ }
          }
          input.request_key = pending.key;
          var job = await api('/teacher/generations', input);
          try { window.sessionStorage.removeItem(generationStorageKey + '_request'); } catch (error) { /* Optional request recovery. */ }
          jobSerial += 1; activeJob = null; await receiveJob(job, jobSerial); message('AI 수업 자료 생성을 시작했습니다.');
        } catch (error) { showError(error); } finally { generationBusy = false; syncSubmit(); }
      });
      var manual = element('details','ojprimm-manual'); manual.appendChild(element('summary','','선택: 기존 기초 예제로 활동 만들기'));
      var manualForm = element('form','ojp-form-stack'); manualForm.setAttribute('data-primm-manual-create','');
      var manualDemo = select('demo_code',[['sum','변수와 덧셈 · 검증된 기초 예제'],['condition','조건문 · 검증된 기초 예제']]);
      var manualKind = select('manual_support_kind',[['none','도움 없이 코드 수정 기록'],['code_ordering','Parsons · 코드 순서 배열'],['code_completion','코드 빈칸 완성']]);
      var manualSupport = textInput('number','support_problem_id',''); manualSupport.min='1';manualSupport.disabled=true;
      manualKind.addEventListener('change',function(){manualSupport.disabled=manualKind.value==='none';manualSupport.required=manualKind.value!=='none';});
      manualForm.appendChild(element('p','ojp-field-help','위에서 고른 수업·목표 문제와 활동 이름을 사용합니다. AI 생성 없이 검증된 기초 예제를 연결합니다. 도움 문제는 해당 작성 방식으로 준비된 별도 문제여야 합니다.'));
      [field('기초 도입 예제',manualDemo),field('기존 도움 문제 방식',manualKind),field('별도의 도움 문제 번호',manualSupport)].forEach(function(item){manualForm.appendChild(item);});
      var manualSubmit = element('button','ojp-button','기초 예제로 활동 만들기');manualSubmit.type='submit';manualForm.appendChild(manualSubmit);
      manualForm.addEventListener('submit',async function(event){
        event.preventDefault();if(!target.reportValidity()||!manualForm.reportValidity()){return;}manualSubmit.disabled=true;
        try {var chosen=contexts[Number(context.value)];await api('/teacher/activities',{title:title.value,context_type:chosen.type||chosen.context_type,context_id:chosen.id||chosen.context_id,target_problem_id:Number(target.value),support_kind:manualKind.value,support_problem_id:manualKind.value==='none'?null:Number(manualSupport.value),demo_code:manualDemo.value});message('PRIMM 활동을 만들었습니다. 학생 화면에서 확인할 수 있습니다.');await refresh();}
        catch(error){showError(error);}finally{manualSubmit.disabled=false;}
      });manual.appendChild(manualForm);
      create.appendChild(form);create.appendChild(settingsPanel);create.appendChild(jobPanel);create.appendChild(generationHistory);create.appendChild(manual);content.appendChild(create);content.appendChild(managementPanel);content.appendChild(overview);content.appendChild(reportPanel);
      await loadSettings();loadReport(false).catch(showError);
      await refresh();message('목표 문제와 수업 언어를 고르면 AI가 단계별 자료를 만듭니다.');
      var restoreId = new URL(window.location.href).searchParams.get('generation');
      if (!restoreId) { try { restoreId = window.sessionStorage.getItem(generationStorageKey); } catch (error) { /* URL remains available. */ } }
      if (restoreId && /^\d+$/.test(restoreId)) {
        try { jobSerial += 1; await receiveJob(await api('/teacher/generations/' + Number(restoreId)), jobSerial); }
        catch(error){showError(error);}
      }
    }

    async function studentList() {
      stopSubmission();
      state.disposed += 1; content.replaceChildren(); state.view = null; state.draft = null;
      var activities = list(await api('/activities'));
      if (!activities.length) { empty(content, '참여할 PRIMM 활동이 없습니다.', '수업·그룹에 가입되어 있는지 확인하고 교사가 활동을 배포할 때까지 기다려 주세요.'); message('아직 배포된 활동이 없습니다.'); return; }
      var cards = element('div', 'ojprimm-activity-list');
      activities.forEach(function (item) {
        var activity = item.activity || item; var attempt = item.attempt || {};
        var card = element('article', 'ojprimm-card'); card.appendChild(element('h2', '', activity.title));
        card.appendChild(element('p', 'ojp-muted', '문제 ' + activity.target_problem_id + ' · ' + (stageLabels[attempt.current_stage] || '예측') + ' 단계'));
        card.appendChild(button('이어서 진행', function () { openActivity(activity.id).catch(showError); }, true)); cards.appendChild(card);
      }); content.appendChild(cards); message('활동을 골라 시작하세요.');
    }
    async function openActivity(id) {
      var view = await api('/activities/' + Number(id)); renderActivity(view);
      var url = new URL(window.location.href); url.searchParams.set('activity', id); window.history.replaceState(null, '', url.href);
    }
    function renderActivity(view) {
      stopSubmission();
      state.disposed += 1; var generation = state.disposed;
      state.view = view;
      var stage = view.display_stage || view.attempt.current_stage;
      state.draft = { response: (view.attempt.responses || {})[stage] || '', code: view.attempt.draft_code || '', supportState: view.attempt.support_state || '' };
      state.practiceSource=view.attempt.practice_passed===true?view.attempt.draft_code:null;
      state.dirty = 0; state.saved = 0;
      try {
        var recovered = JSON.parse(window.sessionStorage.getItem(localKey()) || 'null');
        if (!view.read_only&&!view.review_only&&recovered && recovered.version === view.attempt.version && recovered.stage === stage && recovered.draft) {
          state.draft = recovered.draft; state.dirty = 1;
        }else if(!view.read_only&&!view.review_only&&recovered&&recovered.draft){protectDraft(recovered);}
      } catch (error) { /* Keep the saved server revision. */ }
      content.replaceChildren();
      var toolbar = element('div', 'ojprimm-toolbar'); toolbar.appendChild(button('활동 목록', async function () {
        try { await save(false); await studentList(); } catch (error) { showError(error); }
      })); toolbar.appendChild(element('span', 'ojp-muted', view.read_only ? '교사 확인 화면 · 읽기 전용' : '단계별 응답 자동 저장')); content.appendChild(toolbar);
      if(view.review_only){toolbar.appendChild(button('현재 학습 단계로 돌아가기',function(){openActivity(view.activity.id).catch(showError);},true));toolbar.appendChild(element('span','ojp-field-help','완료한 단계를 읽고 있습니다. 최초 예측과 저장한 응답은 바뀌지 않습니다.'));}
      content.appendChild(element('h2', '', view.activity.title));
      var recovery=recoveryPanel(view);
      var lessonSummary = view.lesson_summary || {};
      var generated = view.activity.generation_kind === 'ai';
      var preparedLesson = generated || view.activity.generation_kind === 'template';
      var verification = lessonSummary.verification || (view.demo || {}).verification || {};
      var ojVerified = verification.status === 'verified' && verification.engine === 'oj';
      if (list(lessonSummary.objectives).length) {
        var objectives = element('ul','ojprimm-objectives');
        list(lessonSummary.objectives).forEach(function(objective){objectives.appendChild(element('li','',objective));});content.appendChild(objectives);
      }
      if (lessonSummary.language) { content.appendChild(element('p','ojp-field-help','수업 언어: ' + lessonSummary.language)); }
      if (ojVerified) {
        var verificationSummary = 'OJ 자동 검증 완료 · 예제와 수정 기준 코드의 예상 출력이 일치했습니다.';
        if (Number(verification.case_count) > 0) { verificationSummary += ' 확인한 시험 사례: ' + Number(verification.case_count) + '개.'; }
        if (verification.checked_at) { verificationSummary += ' 확인 시각: ' + verification.checked_at + ' UTC.'; }
        var verificationNotice = element('p','ojprimm-notice',verificationSummary); verificationNotice.setAttribute('data-primm-example-verification','verified'); content.appendChild(verificationNotice);
      } else if (lessonSummary.verification_note || verification.note) {
        var unverifiedNotice = element('p','ojprimm-notice',preparedLesson && !generated ? 'OJ 자동 실행 검증 전인 기본 예제 기반 수업 자료입니다.' : (lessonSummary.verification_note || verification.note)); unverifiedNotice.setAttribute('data-primm-example-verification','unverified'); content.appendChild(unverifiedNotice);
      }
      var steps = element('ol', 'ojprimm-steps');
      stageKeys.forEach(function (key, index) {
        var label = (index + 1) + ' · ' + stageLabels[key];
        var completed=(view.attempt.completed_stages||[]).indexOf(key)!==-1;
        var item = element('li', key === stage ? 'is-current' : '', (view.read_only||completed) ? undefined : label);
        if(view.read_only){
          var preview=button(label,function(){
            var previewView=Object.assign({},view,{attempt:Object.assign({},view.attempt,{current_stage:key})});
            renderActivity(previewView);
          });preview.classList.add('ojprimm-step-preview');preview.setAttribute('aria-pressed',String(key===stage));item.appendChild(preview);
        }else if(completed){
          var revisit=button(label,function(){
            save(false).then(function(){return api('/activities/'+view.activity.id+'?stage='+encodeURIComponent(key));}).then(renderActivity).catch(showError);
          });revisit.classList.add('ojprimm-step-preview');revisit.setAttribute('aria-pressed',String(key===stage));item.appendChild(revisit);
        }
        if ((view.attempt.completed_stages || []).indexOf(key) !== -1) { item.classList.add('is-complete'); }
        if (key === stage) { item.setAttribute('aria-current', 'step'); } steps.appendChild(item);
      }); content.appendChild(steps);
      var target = element('section', 'ojprimm-target'); target.appendChild(element('strong', '', '최종 제작 · ' + view.activity.target_problem_id + ' ' + (view.activity.target_title || (view.target || {}).title || '')));
      if (view.target) { target.appendChild(element('p', '', plain(view.target.description).slice(0, 400))); }
      target.appendChild(link('문제 설명 열기', view.target_url)); content.appendChild(target);
      if (view.stale) { var stale = element('p', 'ojprimm-notice', '활동 또는 문제가 변경되었습니다. 작성한 내용을 보관하고 페이지를 새로고침해 주세요.'); stale.setAttribute('role', 'alert'); content.appendChild(stale); }
      var panel = element('fieldset', 'ojp-section ojprimm-stage'); panel.setAttribute('data-primm-stage', stage); panel.setAttribute('aria-label',stageLabels[stage]+' 단계');
      var descriptor = list(view.stages).find(function (item) { return item.key === stage; }) || {};
      var stageHeading=element('h3', '', stageLabels[stage]);stageHeading.tabIndex=-1;panel.appendChild(stageHeading);panel.appendChild(element('p', '', descriptor.prompt || '코드를 살펴보고 생각을 기록하세요.'));
      if (list(descriptor.questions).length) {
        var questions=element('ol','ojprimm-questions');
        list(descriptor.questions).forEach(function(question){questions.appendChild(element('li','',question));});panel.appendChild(questions);
      }
      if (descriptor.task) { panel.appendChild(element('p','ojprimm-answer',descriptor.task)); }
      var demo = view.demo || {}; var prepared = typeof demo.code === 'string' && demo.code.trim() !== '';
      if (['predict', 'run', 'investigate'].indexOf(stage) !== -1) {
        if (!prepared) { var notice = element('p', 'ojprimm-notice', '코드를 읽을 도입 자료가 아직 준비되지 않았습니다. 교사에게 기초 도입 예제 설정을 요청해 주세요. 예상과 생각은 임시 저장할 수 있습니다.'); notice.setAttribute('role','alert'); panel.appendChild(notice); }
        else {
          panel.appendChild(element('p', 'ojp-field-help', generated ? '목표 문제의 개념을 연습하는 AI 생성 도입 예제입니다.' : (preparedLesson ? '기본 예제를 바탕으로 준비한 도입 자료입니다.' : '제작 문제의 정답과 별개인 기초 도입 예제입니다.')));
          if (demo.language) { panel.appendChild(element('p','ojp-field-help','예제 언어: ' + demo.language)); }
          panel.appendChild(element('pre', 'ojprimm-code', demo.code));
          if (demo.input) { panel.appendChild(element('h4','','예제 입력')); panel.appendChild(element('pre','ojprimm-code',demo.input)); }
          if (demo.explanation && stage !== 'predict') { panel.appendChild(element('p','',demo.explanation)); }
          if (stage === 'run') {
            panel.appendChild(element('p', 'ojp-field-help', ojVerified ? '기존 OJ가 예제 코드를 실행하여 예상 출력과 일치하는지 확인했습니다. 저장한 예측과 비교하세요.' : (generated ? 'AI가 제시한 예상 출력입니다. 실제 실행으로 검증되지 않았습니다. 실행 환경에서 직접 확인하고 예측과 비교하세요.' : (preparedLesson ? '기본 예제의 예상 출력입니다. 실제 실행으로 검증되지 않았습니다. 실행 환경에서 직접 확인하고 예측과 비교하세요.' : '예측을 저장한 뒤 확인하는, 미리 검증된 기초 예제의 결과입니다. 이 화면은 임의의 Python 코드를 실행하지 않습니다.'))));
            var expected = element('pre', 'ojprimm-code'); expected.hidden = !(view.read_only||view.review_only);
            if(view.read_only||view.review_only){expected.textContent=demo.expected_output===undefined?'예상 출력이 없습니다.':demo.expected_output;}
            var reveal = button(ojVerified ? 'OJ 검증 출력 확인' : (generated ? 'AI 예상 출력 확인' : (preparedLesson ? '예상 출력 확인' : '검증된 예제 결과 확인')), function () {
              expected.textContent = demo.expected_output === undefined ? '결과를 확인할 수 없습니다. 새로고침해 주세요.' : demo.expected_output;
              expected.hidden = false; reveal.disabled = true;
            }); if(!view.read_only){panel.appendChild(reveal);} panel.appendChild(expected);
          } else if (stage === 'investigate' && demo.expected_output !== undefined) {
            panel.appendChild(element('h4','',ojVerified ? 'OJ로 검증한 예제 출력' : (generated ? 'AI 예상 출력 · 실제 실행 검증 전' : (preparedLesson ? '예상 출력 · 실제 실행 검증 전' : '예제 출력'))));panel.appendChild(element('pre','ojprimm-code',demo.expected_output));
          }
        }
      }
      if (stage === 'modify') {
        panel.appendChild(element('p', 'ojp-muted', preparedLesson ? '준비된 도움 자료로 코드를 수정해 보세요. 코드 복원 성공과 채점 정답은 구분됩니다.' : '수정 도움 활동은 별도의 도입 문제를 사용합니다. 코드 복원 성공과 채점 정답은 구분됩니다.'));
        if (view.activity.support_is_target) { panel.appendChild(element('p', 'ojprimm-notice', '목표 문제를 지원 활동으로 사용하므로 제작 문제의 풀이 도움을 제공합니다. 독립적인 제작 활동이 필요하면 교사에게 별도의 도움 문제를 요청하세요.')); }
        if(view.read_only&&descriptor.starter_code){
          panel.appendChild(element('h4','','교사용 수정 활동 전체 코드'));
          panel.appendChild(element('pre','ojprimm-code',descriptor.starter_code));
        }
        if (!view.read_only && !view.review_only && view.activity.support_kind !== 'none') {
          var help = element('div', 'ojprimm-support'); help.appendChild(element('p', '', '도움 활동을 불러오고 있습니다.')); panel.appendChild(help);
          renderSupport(help, view, generation).catch(function (error) { help.replaceChildren(element('p', 'ojprimm-notice', error.message)); });
        }
      }
      if (stage === 'modify' || stage === 'make') {
        if (!state.draft.code && descriptor.starter_code && (stage !== 'modify' || view.activity.support_kind === 'none')) { state.draft.code = descriptor.starter_code; }
        if (stage === 'modify' && view.activity.support_kind === 'none' && !state.draft.code && demo.code) { state.draft.code = demo.code; }
        var code = textarea('draft_code', 10); code.classList.add('ojprimm-code-input'); code.maxLength = 65536; code.spellcheck = false; code.value = state.draft.code;
        code.addEventListener('input', function () { state.draft.code = code.value; changed(); });
        panel.appendChild(field(stage === 'make' ? '내 프로그램 초안' : '수정한 코드 초안', code));
        if(stage==='modify'&&!view.read_only&&!view.review_only&&(ojVerified||view.practice_required||view.activity.practice_required)){renderPractice(panel,view,generation);}
        if (stage === 'make') {
          renderMakeSubmission(panel, view, generation);
        }
      }
      if (stage !== 'predict' && list(descriptor.tests).length) {
        var tests=element('details','ojprimm-tests');tests.appendChild(element('summary','',ojVerified ? '확인할 예제 · OJ 검증 완료' : (generated ? '확인할 예제 · AI 예상 출력' : (preparedLesson ? '확인할 예제 · 예상 출력' : '확인할 예제'))));
        if(preparedLesson){tests.appendChild(element('p','ojp-field-help',ojVerified ? '수정 활동의 기준 코드를 기존 OJ에서 실행하여 아래 예상 출력과 일치하는지 확인했습니다. 내 수정 코드의 정답 판정은 별도로 확인해야 합니다.' : (generated ? '아래 예상 출력은 AI가 생성했으며 실제 실행으로 검증되지 않았습니다.' : '아래 예상 출력은 기본 예제의 예상 결과이며 실제 실행으로 검증되지 않았습니다.')));}
        list(descriptor.tests).forEach(function(test,index){tests.appendChild(element('h4','','예제 '+(index+1)+' 입력'));tests.appendChild(element('pre','ojprimm-code',test.input || ''));tests.appendChild(element('p','ojp-field-help','예상 출력'));tests.appendChild(element('pre','ojprimm-code',test.expected_output || ''));});panel.appendChild(tests);
      }
      if (list(descriptor.hints).length) {
        var hints=element('details','ojprimm-hints');hints.appendChild(element('summary','','생각을 돕는 힌트'));
        list(descriptor.hints).forEach(function(hint){hints.appendChild(element('p','',hint));});panel.appendChild(hints);
      }
      var response = textarea('stage_response', 6); response.value = state.draft.response;
      response.maxLength = 4000;
      var labels = {predict:'예상 출력과 그렇게 생각한 이유',run:'실행 결과와 예측이 같거나 달랐던 점',investigate:'변수·조건·실행 순서를 설명해 보세요',modify:'무엇을 바꾸었고 결과가 어떻게 달라졌나요?',make:'해결 방법과 확인한 사례를 설명해 보세요'};
      response.addEventListener('input', function () { state.draft.response = response.value; changed(); }); panel.appendChild(field(labels[stage], response));
      var controls = element('div', 'ojprimm-toolbar');
      var saveButton = button('지금 저장', function () { save(false).catch(showError); }); controls.appendChild(saveButton);
      var advance = button(stage === 'make' ? '학습 과정 마치기' : '저장하고 다음 단계', async function () {
        panel.disabled = true;
        try { await save(true); } catch (error) { showError(error); panel.disabled = false; }
      }, true);advance.setAttribute('data-primm-advance','');controls.appendChild(advance);panel.appendChild(controls);
      if(stage==='modify'&&(view.practice_required||view.activity.practice_required)){advance.disabled=state.practiceSource!==state.draft.code;panel.appendChild(element('p','ojp-field-help','수정 코드를 실행해 모든 사례를 통과한 뒤 다음 단계로 이동합니다. 코드를 바꾸면 다시 확인하세요.'));}
      if (view.read_only || view.review_only || view.stale) { panel.querySelectorAll('input,textarea,select,button').forEach(function (control) { if(control.tagName==='TEXTAREA'||control.tagName==='INPUT'){control.readOnly=true;}else{control.disabled = true;} }); }
      else if (!prepared && ['predict','run','investigate'].indexOf(stage) !== -1) { advance.disabled = true; }
      content.appendChild(panel);
      var focusTarget=recovery?recovery.querySelector('h3'):stageHeading;focusTarget.tabIndex=-1;focusTarget.focus();
      var assessment = element('p', 'ojprimm-result'); assessment.setAttribute('data-primm-judge-status', '');
      assessment.textContent = view.attempt.is_accepted ? '마지막 확인 당시 본인의 정답 제출을 확인했습니다.'
        : '정답 제출 미확인 · 과정 진행과 공식 채점 결과는 별개입니다.';
      if (view.attempt.verified_at) { assessment.textContent += ' 마지막 본인 제출 확인: ' + view.attempt.verified_at + ' UTC. 확인 당시의 기록이며 현재 결과는 제출 화면에서 다시 확인하세요.'; }
      content.appendChild(assessment);
      judgeAssessment();
      if (view.attempt.status === 'process_complete') { content.appendChild(element('p', 'ojprimm-notice', '학습 과정을 마쳤습니다. 채점 결과는 위의 정답 제출 확인 상태를 따릅니다.')); }
      var previous = element('details', 'ojprimm-previous'); previous.appendChild(element('summary', '', '앞 단계에 저장한 응답'));
      Object.keys(view.attempt.responses || {}).forEach(function (key) { if (key !== stage) { previous.appendChild(element('h4','',stageLabels[key])); previous.appendChild(element('pre','ojprimm-answer',view.attempt.responses[key])); } }); content.appendChild(previous);
      if (state.dirty) { message('이 창에 남아 있던 작성 내용을 복구했습니다. 지금 저장을 눌러 서버에 보관하세요.'); }
      else { message(view.read_only ? '교사 확인용 읽기 전용 화면입니다.' : '현재 단계의 응답을 작성하면 자동으로 저장됩니다.'); }
    }

    function renderPractice(panel,view,generation){
      var area=element('section','ojprimm-practice');area.setAttribute('data-primm-practice','');area.appendChild(element('h4','','내 수정 코드 실행·확인'));
      area.appendChild(element('p','ojp-field-help','현재 수정 코드만 기존 OJ의 수업용 실행으로 확인합니다. 기준 코드나 빈칸 정답을 공개하지 않으며 제작 문제의 공식 정답 판정과 별개입니다.'));
      var resultBox=element('div','ojprimm-practice-results');resultBox.setAttribute('role','status');var pending=null;
      var storageKey=localKey()+'_practice';try{pending=JSON.parse(window.sessionStorage.getItem(storageKey)||'null');}catch(error){/* Start a new practice request. */}
      var run=button('수정 코드 실행·확인',function(){
        pending={source:state.draft.code,request_key:window.crypto&&window.crypto.randomUUID?window.crypto.randomUUID():'practice-'+Date.now()+'-'+Math.random().toString(16).slice(2),startedAt:Date.now()};execute();
      },true);area.appendChild(run);area.appendChild(resultBox);panel.appendChild(area);
      async function execute(){
        if(generation!==state.disposed||!pending){return;}if(!pending.source.trim()){message('수정할 코드를 먼저 작성하거나 배열·빈칸 코드를 복원해 주세요.',true);return;}
        if(pending.startedAt&&Date.now()-pending.startedAt>=86400000){resultBox.textContent='이전 연습 요청의 24시간 유효 기간이 지났습니다. 수정 코드는 보존했습니다. 실행 버튼으로 새 연습을 시작하세요.';window.sessionStorage.removeItem(storageKey);pending=null;run.disabled=false;return;}
        run.disabled=true;resultBox.textContent='OJ에서 내 코드를 확인하고 있습니다.';
        try{
          if(!pending.ticket){await save(false);}
          var payload={source:pending.source,request_key:pending.request_key};if(pending.ticket){payload.ticket=pending.ticket;}
          var result=await api('/activities/'+view.activity.id+'/practice',payload);if(generation!==state.disposed){return;}
          if(result.expired===true&&result.status!=='completed'){resultBox.textContent='이전 연습 요청이 만료되었습니다. 실행 버튼으로 새 연습을 시작하세요.';window.sessionStorage.removeItem(storageKey);pending=null;run.disabled=false;return;}
          pending.ticket=result.ticket;window.sessionStorage.setItem(storageKey,JSON.stringify(pending));
          if(result.status==='pending'){window.setTimeout(execute,1500);return;}
          var changedCode=pending.source!==state.draft.code;resultBox.replaceChildren();
          state.practiceSource=result.all_passed?pending.source:null;var advance=panel.querySelector('[data-primm-advance]');if(advance&&(view.practice_required||view.activity.practice_required)){advance.disabled=state.practiceSource!==state.draft.code;}
          resultBox.appendChild(element('p',changedCode?'ojprimm-notice':'ojp-field-help',changedCode?'확인하는 동안 코드가 바뀌었습니다. 아래는 이전 초안의 결과입니다. 현재 코드를 다시 확인하세요.':(result.all_passed?'모든 수정 사례를 통과했습니다. 제작 문제의 공식 정답 판정과 별개입니다.':'수정 사례와 다른 결과가 있습니다. 오류와 출력 결과를 살펴보세요.')));
          list(result.results).forEach(function(item,index){var record=element('details','ojprimm-tests');record.appendChild(element('summary','','사례 '+(Number.isInteger(item.index)&&item.index>0?item.index:index+1)+' · '+({accepted:'출력 일치',wrong_answer:'출력 불일치',compile_error:'컴파일 오류',runtime_error:'실행 오류',time_limit:'시간 초과',memory_limit:'메모리 초과',output_limit:'출력 초과',output_incomplete:'완료 확인 불가'}[item.verdict]||item.verdict)));if(item.actual_output!==undefined){record.appendChild(element('pre','ojprimm-code',item.actual_output));}if(item.diagnostics){record.appendChild(element('pre','ojprimm-answer',item.diagnostics));}resultBox.appendChild(record);});
          window.sessionStorage.removeItem(storageKey);pending=null;run.disabled=false;
        }catch(error){if(generation!==state.disposed){return;}resultBox.textContent=error.message;run.disabled=false;}
      }
      if(pending&&pending.source===state.draft.code){execute();}
    }

    async function renderSupport(container, view, generation) {
      var payload = await api('/activities/' + view.activity.id + '/support');
      if (generation !== state.disposed) { return; }
      container.replaceChildren(); container.appendChild(element('h4','',view.activity.support_title || '수정 도움 문제'));
      var saved = null;
      try { saved = JSON.parse(state.draft.supportState || 'null'); } catch (error) { /* Start with current public version. */ }
      if (saved && saved.template_hash !== payload.template_hash) { saved = null; container.appendChild(element('p','ojprimm-notice','도움 문제가 변경되어 조각·빈칸을 새 버전으로 불러왔습니다.')); }
      var answer = { template_hash:payload.template_hash };
      if (view.activity.support_kind === 'code_ordering') {
        var blocks = list(payload.blocks); var byId = Object.create(null);
        blocks.forEach(function (block) { byId[block.id] = block; });
        var ids = saved && Array.isArray(saved.block_ids) ? saved.block_ids : blocks.map(function (block) { return block.id; });
        if (ids.length !== blocks.length || new Set(ids).size !== blocks.length || ids.some(function (id) { return !byId[id]; })) { ids = blocks.map(function(block){return block.id;}); }
        var ordered = element('ol','ojprimm-pieces'); ordered.setAttribute('data-primm-ordering','');
        var dragged = null, pointerDrag = null, dropTarget = null, dropAfter = false;
        function record() { answer.block_ids = ids.slice(); state.draft.supportState = JSON.stringify(answer); changed(); }
        function clearDrop() { ordered.querySelectorAll('.is-drop-before,.is-drop-after').forEach(function(piece){piece.classList.remove('is-drop-before','is-drop-after');});dropTarget=null; }
        function indicateDrop(piece, clientY) {
          clearDrop();if(!piece||piece.dataset.blockId===dragged){return;}
          dropAfter=clientY>piece.getBoundingClientRect().top+piece.getBoundingClientRect().height/2;
          dropTarget=piece.dataset.blockId;piece.classList.add(dropAfter?'is-drop-after':'is-drop-before');
        }
        function placeDragged() {
          if(!dragged||!dropTarget||dragged===dropTarget){return;}
          var moved=dragged,from=ids.indexOf(moved);ids.splice(from,1);
          var to=ids.indexOf(dropTarget)+(dropAfter?1:0);ids.splice(to,0,moved);record();redraw(moved);
        }
        function redraw(focus) {
          ordered.replaceChildren();
          ids.forEach(function(id,index) {
            var piece = element('li','ojprimm-piece'); piece.dataset.blockId=id; piece.tabIndex=0;
            piece.setAttribute('aria-label','코드 조각 '+(index+1)+' / '+ids.length);
            var number=element('span','ojprimm-piece-number',String(index+1));number.setAttribute('aria-hidden','true');piece.appendChild(number);
            var handle=element('button','ojprimm-piece-handle','⠿');handle.type='button';handle.draggable=true;handle.setAttribute('aria-label','코드 조각 끌어서 이동');piece.appendChild(handle);
            piece.appendChild(element('pre','ojprimm-code',String(byId[id].code||'').replace(/\r?\n$/,'')));
            var tools = element('div','ojprimm-piece-tools');
            function move(offset) { if(view.read_only){return;}var next=index+offset;if(next<0||next>=ids.length){return;} ids.splice(index,1);ids.splice(next,0,id);record();redraw(id); }
            var up=button('↑',function(){move(-1);});up.setAttribute('aria-label','위로');up.disabled=index===0;
            var down=button('↓',function(){move(1);});down.setAttribute('aria-label','아래로');down.disabled=index===ids.length-1;tools.appendChild(up);tools.appendChild(down);
            piece.appendChild(tools);
            piece.addEventListener('keydown',function(event){if(event.altKey&&(event.key==='ArrowUp'||event.key==='ArrowDown')){event.preventDefault();move(event.key==='ArrowUp'?-1:1);}});
            piece.addEventListener('dragstart',function(event){if(view.read_only){event.preventDefault();return;}dragged=id;piece.classList.add('is-dragging');if(event.dataTransfer){event.dataTransfer.setData('text/plain',id);event.dataTransfer.effectAllowed='move';}});
            piece.addEventListener('dragover',function(event){if(dragged){event.preventDefault();indicateDrop(piece,event.clientY);}});
            piece.addEventListener('drop',function(event){event.preventDefault();indicateDrop(piece,event.clientY);placeDragged();dragged=null;clearDrop();});
            piece.addEventListener('dragend',function(){dragged=null;piece.classList.remove('is-dragging');clearDrop();});
            handle.addEventListener('pointerdown',function(event){
              if(view.read_only||event.pointerType==='mouse'){return;}event.preventDefault();pointerDrag={pointerId:event.pointerId,id:id,x:event.clientX,y:event.clientY,moved:false};handle.setPointerCapture(event.pointerId);
            });
            handle.addEventListener('pointermove',function(event){
              if(!pointerDrag||pointerDrag.pointerId!==event.pointerId){return;}
              if(!pointerDrag.moved&&Math.abs(event.clientX-pointerDrag.x)+Math.abs(event.clientY-pointerDrag.y)<6){return;}
              event.preventDefault();pointerDrag.moved=true;dragged=id;piece.classList.add('is-dragging');
              var hit=document.elementFromPoint(event.clientX,event.clientY);var targetPiece=hit&&hit.closest('[data-block-id]');indicateDrop(targetPiece&&ordered.contains(targetPiece)?targetPiece:null,event.clientY);
            });
            handle.addEventListener('pointerup',function(event){if(!pointerDrag||pointerDrag.pointerId!==event.pointerId){return;}event.preventDefault();if(pointerDrag.moved){placeDragged();}pointerDrag=null;dragged=null;piece.classList.remove('is-dragging');clearDrop();});
            handle.addEventListener('pointercancel',function(){pointerDrag=null;dragged=null;piece.classList.remove('is-dragging');clearDrop();});
            ordered.appendChild(piece);if(focus===id){piece.focus();message('코드 조각을 '+(index+1)+' / '+ids.length+' 위치로 이동했습니다.');}
          });
        }
        answer.block_ids=ids.slice();redraw();container.appendChild(element('p','ojp-field-help','위로·아래로 버튼, 끌어 놓기, Alt + 위·아래 방향키로 조각을 이동합니다. 내용과 들여쓰기는 고정됩니다.'));container.appendChild(ordered);
      } else {
        answer.answers=Object.create(null);var blanks=Object.create(null);list(payload.blanks).forEach(function(blank){blanks[blank.id]=blank;});
        var body=element('div','ojprimm-completion');body.setAttribute('data-primm-completion','');
        var template=String(payload.starter_code||'');var token=/\{\{OJ_BLANK:([a-zA-Z0-9_-]{1,64})\}\}/g;var match;var cursor=0;
        while((match=token.exec(template))!==null){
          body.appendChild(element('span','',template.slice(cursor,match.index)));var blank=blanks[match[1]];
          if(!blank){throw new Error('빈칸 구성 정보가 일치하지 않습니다. 교사에게 확인해 주세요.');}
          var input=blank.kind==='block'?textarea('blank_'+blank.id,3):textInput('text','blank_'+blank.id,'');
          input.maxLength=blank.max_bytes;input.value=saved&&saved.answers&&typeof saved.answers[blank.id]==='string'?saved.answers[blank.id]:'';
          input.setAttribute('aria-label',blank.label);input.placeholder=blank.label;input.dataset.blankId=blank.id;answer.answers[blank.id]=input.value;
          input.addEventListener('input',function(event){answer.answers[event.target.dataset.blankId]=event.target.value;state.draft.supportState=JSON.stringify(answer);changed();});body.appendChild(input);cursor=token.lastIndex;
        }
        body.appendChild(element('span','',template.slice(cursor)));container.appendChild(body);
      }
      // Persist the first public block order too; re-entry restores that attempt rather than reshuffling it.
      if (!view.read_only) { state.draft.supportState=JSON.stringify(answer); changed(); }
      var feedback=element('p','ojp-field-help');feedback.setAttribute('role','status');
      var assemble=button('배열·빈칸 코드 복원 확인',async function(){
        assemble.disabled=true;
        try {
          var result=await api('/activities/'+view.activity.id+'/support/assemble',answer);
          feedback.textContent='코드를 복원했습니다. 채점 정답 여부는 아직 확인하지 않았습니다.';
          var restored=element('pre','ojprimm-code',result.source);var old=container.querySelector('[data-primm-restored-code]');if(old){old.remove();}
          restored.setAttribute('data-primm-restored-code','');container.appendChild(restored);
          if(!view.read_only){state.draft.code=result.source;var draft=content.querySelector('[name=draft_code]');if(draft){draft.value=result.source;}changed();}
        }catch(error){feedback.textContent=error.message;}finally{assemble.disabled=false;}
      });assemble.disabled=Boolean(view.read_only);container.appendChild(assemble);container.appendChild(feedback);
      if(view.read_only){container.querySelectorAll('button,input,textarea,select').forEach(function(control){control.disabled=true;});}
    }

    root.ojPrimm={api:api,save:save};
    window.addEventListener('beforeunload',function(event){if(state.dirty!==state.saved){event.preventDefault();event.returnValue='';}});
    var start=config.mode==='teacher'?teacher():Promise.resolve().then(function(){var id=new URL(window.location.href).searchParams.get('activity');return id&&/^\d+$/.test(id)?openActivity(Number(id)):studentList();});
    start.catch(function(error){showError(error);content.replaceChildren();content.appendChild(button('다시 불러오기',function(){window.location.reload();}));});
    return root.ojPrimm;
  }
  window.OJPrimm=Object.freeze({mount:mount});
  function initialize(){document.querySelectorAll('[data-oj-primm]').forEach(mount);}
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',initialize);}else{initialize();}
})();
