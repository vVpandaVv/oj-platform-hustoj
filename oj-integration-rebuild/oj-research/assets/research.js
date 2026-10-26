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

  function initialize() { document.querySelectorAll('[data-oj-research-design]').forEach(initializeResearchDesign); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initialize); } else { initialize(); }
})();
