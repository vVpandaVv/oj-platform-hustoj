(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.OJAILearnerLabels = api;
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';
  const modes = ['management_name', 'login', 'display_name'];
  const normalize = mode => modes.includes(mode) ? mode : 'display_name';
  const text = value => typeof value === 'string' ? value.trim() : '';
  function label(identity, mode) {
    return text(identity?.labels?.[normalize(mode)]) || text(identity?.labels?.display_name)
      || text(identity?.display_name) || text(identity?.labels?.login) || '학생';
  }
  function searchText(identity) {
    return [...modes.map(mode => text(identity?.labels?.[mode])), text(identity?.display_name)].join(' ').toLocaleLowerCase();
  }
  function create(root) {
    const select = root.querySelector('[data-learner-label-mode]');
    const key = `ojai.learner-label.${root.dataset.actorId || 'teacher'}`;
    const bindings = new WeakMap();
    let mode = 'display_name';
    try { mode = normalize(window.localStorage.getItem(key)); } catch (_) { /* Storage can be disabled. */ }
    if (select) select.value = mode;
    const update = element => {
      const value = bindings.get(element);
      if (!value) return;
      element.textContent = label(value.identity, mode) + value.suffix;
    };
    select?.addEventListener('change', () => {
      mode = normalize(select.value);
      select.value = mode;
      try { window.localStorage.setItem(key, mode); } catch (_) { /* Keep the in-page selection. */ }
      // Only identity text changes; grading inputs, selected rows and scroll stay intact.
      root.querySelectorAll('[data-learner-label]').forEach(update);
    });
    return {
      label: identity => label(identity, mode),
      searchText,
      bind(element, identity, suffix = '') {
        if (!element) return element;
        bindings.set(element, { identity, suffix });
        element.dataset.learnerLabel = '';
        update(element);
        return element;
      },
    };
  }
  return { create, label, searchText, normalize };
});
