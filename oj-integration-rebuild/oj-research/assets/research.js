(function () {
  'use strict';
  function initializeResearchDesign(section) {
    var form = section.closest('form');
    var select = section.querySelector('[name="design"]');
    var status = section.querySelector('[data-oj-research-design-status]');
    if (!form || !select) return;
    select.addEventListener('change', function () {
      if (select.value === 'custom') {
        status.textContent = '현재 조건을 유지합니다. 모든 연구 조건을 직접 조정할 수 있습니다.';
        return;
      }
      var values = {
        arm_A_ai: 'level', arm_B_ai: select.value === 'adaptation' ? 'common' : 'off',
        arm_A_recommendation: 'common', arm_B_recommendation: 'common',
        arm_B_minutes: form.elements.arm_A_minutes.value,
        arm_B_conditions: form.elements.arm_A_conditions.value,
        support_pretest_A: 'off', support_pretest_B: 'off',
        support_posttest_A: 'off', support_posttest_B: 'off'
      };
      Object.keys(values).forEach(function (name) { form.elements[name].value = values[name]; });
      status.textContent = '아래 조건을 맞췄습니다: A 수준별 AI, B ' +
        (select.value === 'adaptation' ? '공통 AI' : 'AI 없음') +
        ' · 공통 문제 순서 · 같은 풀이 제한과 추가 조건 · 사전·사후 AI 없음. 확인 후 저장하세요.';
    });
  }

  function bindDialog(dialog) {
    var opener = null;
    dialog.addEventListener('close', function () {
      if (opener && opener.isConnected) opener.focus();
    });
    dialog.addEventListener('click', function (event) {
      if (event.target.closest('[data-oj-research-close]')) dialog.close();
      if (event.target === dialog) {
        var box = dialog.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
      }
    });
    return function (button) { opener = button; if (!dialog.open) dialog.showModal(); };
  }

  function initializeClassroomDesign(section) {
    var form = section.closest('form');
    var select = section.querySelector('[name="design"]');
    var status = section.querySelector('[data-oj-classroom-comparison]');
    if (!form || !select || !status) return;
    var questions = {
      adaptation: '공통 AI 피드백과 비교하여 수준별 AI 피드백을 제공할 때 알고리즘 성취가 얼마나 달라지는가?',
      feedback: 'AI 없는 수업과 비교하여 수준별 AI 피드백을 제공할 때 알고리즘 성취가 얼마나 달라지는가?',
      custom: '저장된 두 집단의 수업 조건에 따라 알고리즘 성취가 얼마나 달라지는가?'
    };
    function updateRatio() {
      var summary = form.querySelector('[data-oj-research-ratio]');
      var a = Number(form.elements.arm_A_weight.value), b = Number(form.elements.arm_B_weight.value);
      if (summary && a > 0 && b > 0) summary.textContent = '목표 인원 비율 A:B = ' + a + ':' + b +
        ' · A 약 ' + Math.round(100 * a / (a + b)) + '% / B 약 ' + Math.round(100 * b / (a + b)) + '%';
    }
    form.elements.arm_A_weight.addEventListener('change', updateRatio);
    form.elements.arm_B_weight.addEventListener('change', updateRatio); updateRatio();
    function update(changed) {
      status.textContent = select.value === 'adaptation'
        ? 'B는 공통 AI 피드백입니다. 수준에 맞춘 설명의 추가 효과를 살펴봅니다.'
        : select.value === 'feedback'
          ? 'B는 AI 없는 기존 수업입니다. 수준별 AI를 포함한 수업의 차이를 살펴봅니다.'
          : 'A와 B의 AI·문제 제공 조건을 세부 조건에서 직접 선택합니다.';
      ['A','B'].forEach(function (arm) {
        ['ai','recommendation','minutes'].forEach(function (key) {
          var field = form.elements['arm_' + arm + '_' + key];
          if (field) field.disabled = select.value !== 'custom';
        });
        ['pretest','posttest'].forEach(function (phase) {
          var field = form.elements['support_' + phase + '_' + arm];
          if (field) field.disabled = select.value !== 'custom';
        });
      });
      var question = form.elements.note_question;
      if (changed && question && Object.values(questions).includes(question.value)) question.value = questions[select.value];
      if (changed && select.value !== 'custom') {
        form.elements.arm_A_ai.value = 'level'; form.elements.arm_B_ai.value = select.value === 'adaptation' ? 'common' : 'off';
        form.elements.arm_A_recommendation.value = 'common'; form.elements.arm_B_recommendation.value = 'common';
        form.elements.arm_B_conditions.value = form.elements.arm_A_conditions.value;
      }
    }
    select.addEventListener('change', function () { update(true); }); update(false);
  }

  function initializeModals() {
    if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return;
    document.querySelectorAll('details[data-oj-research-modal]').forEach(function (details, index) {
      var summary = details.querySelector('summary');
      if (!summary) return;
      var wrapper = document.createElement('div');
      wrapper.className = 'ojp-research-modal-action';
      var button = document.createElement('button');
      button.type = 'button'; button.className = 'ojp-button'; button.textContent = summary.textContent;
      button.setAttribute('aria-haspopup', 'dialog');
      var dialog = document.createElement('dialog');
      dialog.className = 'ojp-dialog ojp-research-modal'; dialog.id = 'oj-research-detail-' + index;
      button.setAttribute('aria-controls', dialog.id);
      var heading = document.createElement('h2'); heading.id = dialog.id + '-title'; heading.textContent = summary.textContent;
      dialog.setAttribute('aria-labelledby', heading.id);
      var header = document.createElement('header'); header.className = 'ojp-dialog__header'; header.appendChild(heading);
      var close = document.createElement('button'); close.type = 'button'; close.className = 'ojp-button'; close.textContent = '닫기'; close.setAttribute('data-oj-research-close', ''); header.appendChild(close);
      var body = document.createElement('div'); body.className = 'ojp-dialog__body';
      Array.from(details.childNodes).forEach(function (node) { if (node !== summary) body.appendChild(node); });
      dialog.append(header, body); wrapper.append(button, dialog); details.replaceWith(wrapper);
      var form = dialog.closest('form');
      if (form) {
        var note = document.createElement('p'); note.className = 'ojp-field-help';
        note.textContent = '수정한 내용은 이 창을 닫은 뒤 수업 설정을 저장해야 반영됩니다.'; body.prepend(note);
      }
      var open = bindDialog(dialog); button.addEventListener('click', function () { open(button); });
    });
    document.addEventListener('invalid', function (event) {
      var dialog = event.target.closest('dialog.ojp-research-modal');
      if (dialog && !dialog.open) dialog.showModal();
    }, true);

    var preview = document.getElementById('oj-research-document-preview');
    if (!preview || !window.fetch || !window.AbortController) return;
    var openPreview = bindDialog(preview);
    var title = preview.querySelector('#oj-research-preview-title');
    var body = preview.querySelector('[data-oj-research-preview-body]');
    var controller = null;
    preview.addEventListener('close', function () { if (controller) controller.abort(); });
    document.querySelectorAll('[data-oj-research-document]').forEach(function (link) {
      link.setAttribute('aria-haspopup', 'dialog');
      link.setAttribute('aria-controls', preview.id);
      link.addEventListener('click', async function (event) {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        var url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !/^\/oj-console\/research\/\d+\/report$/.test(url.pathname)) return;
        event.preventDefault(); if (controller) controller.abort(); controller = new AbortController();
        var current = controller;
        title.textContent = link.getAttribute('data-oj-research-document');
        body.textContent = '자료를 불러오는 중입니다…'; body.setAttribute('aria-busy', 'true'); openPreview(link);
        url.searchParams.set('fragment', '1');
        try {
          var response = await fetch(url.href, {credentials:'same-origin', cache:'no-store', signal:current.signal});
          if (!response.ok || response.redirected || !(response.headers.get('content-type') || '').includes('text/html')) throw new Error('load');
          var html = await response.text();
          if (controller === current && preview.open) body.innerHTML = html;
        } catch (error) {
          if (error.name !== 'AbortError' && controller === current) {
            body.textContent = '자료를 불러오지 못했습니다. 로그인 상태를 확인하거나 별도 화면에서 열어 주세요. ';
            var fallback = document.createElement('a'); fallback.href = link.href; fallback.textContent = '별도 화면 열기'; body.appendChild(fallback);
          }
        } finally { if (controller === current) body.removeAttribute('aria-busy'); }
      });
    });
  }

  function initialize() {
    document.querySelectorAll('[data-oj-research-design]').forEach(initializeResearchDesign);
    document.querySelectorAll('[data-oj-classroom-design]').forEach(initializeClassroomDesign);
    initializeModals();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initialize); } else { initialize(); }
})();
