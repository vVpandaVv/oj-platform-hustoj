(function () {
  'use strict';

  function byId(id) {
    return id ? document.getElementById(id) : null;
  }

  function initializeMessageNotifications() {
    var link = document.querySelector('[data-ojp-message-notification]');
    if (!link || !window.fetch) { return; }
    var endpoint = new URL(link.href, window.location.href);
    endpoint.searchParams.set('notification', '1');
    var busy = false;
    var timer;
    async function refresh() {
      if (busy || document.hidden) { return; }
      busy = true;
      var controller = new AbortController();
      var timeout = window.setTimeout(function () { controller.abort(); }, 10000);
      try {
        var response = await window.fetch(endpoint.toString(), {
          credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
          headers: { Accept: 'application/json' }
        });
        if (!response.ok || response.redirected) { return; }
        var data = await response.json();
        if (!Number.isSafeInteger(data.unread) || data.unread < 0) { return; }
        document.querySelectorAll('[data-ojp-message-count]').forEach(function (badge) {
          badge.textContent = data.unread > 99 ? '99+' : String(data.unread);
          badge.hidden = data.unread === 0;
        });
        document.querySelectorAll('[data-ojp-message-dot]').forEach(function (dot) { dot.hidden = data.unread === 0; });
        link.setAttribute('aria-label', '쪽지함, 읽지 않은 쪽지 ' + data.unread + '개');
      } catch (error) {
        // Keep the last known count when the connection is unavailable.
      } finally {
        window.clearTimeout(timeout);
        busy = false;
      }
    }
    function resume() {
      window.clearInterval(timer);
      if (!document.hidden) { refresh(); timer = window.setInterval(refresh, 30000); }
    }
    document.addEventListener('visibilitychange', resume);
    window.addEventListener('pageshow', resume);
    window.addEventListener('pagehide', function () { window.clearInterval(timer); });
    resume();
  }

  function initializeTabs(root) {
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));

    function activate(selected) {
      tabs.forEach(function (tab) {
        var active = tab === selected;
        var panel = byId(tab.getAttribute('aria-controls'));
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.setAttribute('tabindex', active ? '0' : '-1');
        if (panel) {
          panel.hidden = !active;
        }
      });
    }

    tabs.forEach(function (tab, index) {
      tab.addEventListener('click', function () {
        activate(tab);
      });
      tab.addEventListener('keydown', function (event) {
        var nextIndex = index;
        if (event.key === 'ArrowRight') {
          nextIndex = (index + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft') {
          nextIndex = (index - 1 + tabs.length) % tabs.length;
        } else {
          return;
        }

        event.preventDefault();
        tabs[nextIndex].focus();
        activate(tabs[nextIndex]);
      });
    });
  }

  function initializeCopyButtons() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest('[data-ojp-copy-target]');
      if (!button) {
        return;
      }

      var source = byId(button.getAttribute('data-ojp-copy-target'));
      if (!source) {
        return;
      }

      var value = typeof source.value === 'string' ? source.value : source.textContent || '';
      var copy = function () {
        var input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        (source.closest('dialog') || document.body).appendChild(input);
        input.select();
        var copied = document.execCommand('copy');
        input.remove();
        if (!copied && typeof source.select === 'function') {
          source.focus();
          source.select();
        }
        return copied;
      };

      var originalLabel = button.innerHTML;
      var copiedNotice = function (copied) {
        if (!button.closest('.ojp-server-install') && !source.id.startsWith('server-command-')) {
          return;
        }
        button.textContent = copied ? '복사됨' : '명령을 선택해 직접 복사하세요';
        window.setTimeout(function () { button.innerHTML = originalLabel; }, 2500);
      };

      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(value).then(function () { copiedNotice(true); }).catch(function () { copiedNotice(copy()); });
      } else {
        copiedNotice(copy());
      }
    });
  }

  document.addEventListener('click', function (event) {
    var open = event.target.closest('[data-ojp-server-open]');
    if (open) {
      var dialog = byId(open.getAttribute('data-ojp-server-open'));
      if (dialog && typeof dialog.showModal === 'function') {
        dialog.showModal();
      }
    }
    var close = event.target.closest('[data-ojp-server-close]');
    if (close) {
      var parent = close.closest('dialog');
      if (parent) { parent.close(); }
    }
  });

  // D-010 공용 부품: 시트(확인), 토스트, 찾기(Ctrl/⌘ K), 화면 모드.
  // window.OJP로도 내보내 애드온이 같은 부품을 쓴다.
  var lastSheetFocus = null;

  function closeSheet(scrim) {
    if (!scrim || !scrim.parentNode) {
      return;
    }
    scrim.parentNode.removeChild(scrim);
    if (lastSheetFocus && typeof lastSheetFocus.focus === 'function') {
      lastSheetFocus.focus();
    }
    lastSheetFocus = null;
  }

  function trapFocus(container, event) {
    if (event.key !== 'Tab') {
      return;
    }
    var focusable = Array.prototype.slice.call(container.querySelectorAll(
      'button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'
    ));
    if (!focusable.length) {
      return;
    }
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  // options: { title, message, confirmLabel, cancelLabel, destructive }
  function confirmSheet(options) {
    options = options || {};
    return new Promise(function (resolve) {
      lastSheetFocus = document.activeElement;
      var scrim = document.createElement('div');
      scrim.className = 'ojp-sheet-scrim';
      var sheet = document.createElement('div');
      sheet.className = 'ojp-sheet';
      sheet.setAttribute('role', 'alertdialog');
      sheet.setAttribute('aria-modal', 'true');
      var titleId = 'ojp-sheet-title-' + Date.now();
      sheet.setAttribute('aria-labelledby', titleId);

      var heading = document.createElement('h2');
      heading.id = titleId;
      heading.textContent = options.title || '계속할까요?';
      sheet.appendChild(heading);
      if (options.message) {
        var body = document.createElement('p');
        body.textContent = options.message;
        sheet.appendChild(body);
      }

      var actions = document.createElement('div');
      actions.className = 'ojp-sheet__actions';
      var cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'ojp-button';
      cancel.textContent = options.cancelLabel || '취소';
      var ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'ojp-button ' + (options.destructive ? 'ojp-button--danger' : 'ojp-button--primary');
      ok.textContent = options.confirmLabel || '확인';
      actions.appendChild(cancel);
      actions.appendChild(ok);
      sheet.appendChild(actions);
      scrim.appendChild(sheet);

      function finish(result) {
        closeSheet(scrim);
        resolve(result);
      }
      cancel.addEventListener('click', function () { finish(false); });
      ok.addEventListener('click', function () { finish(true); });
      scrim.addEventListener('click', function (event) {
        if (event.target === scrim) { finish(false); }
      });
      scrim.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          finish(false);
          return;
        }
        trapFocus(sheet, event);
      });

      document.body.appendChild(scrim);
      cancel.focus();
    });
  }

  function toast(message, tone) {
    if (!message) {
      return;
    }
    var region = document.querySelector('.ojp-toast-region');
    if (!region) {
      region = document.createElement('div');
      region.className = 'ojp-toast-region';
      region.setAttribute('role', 'status');
      region.setAttribute('aria-live', 'polite');
      document.body.appendChild(region);
    }
    var item = document.createElement('div');
    item.className = 'ojp-toast' + (tone && tone !== 'success' ? ' ojp-toast--' + tone : '');
    item.textContent = message;
    region.appendChild(item);
    window.setTimeout(function () {
      item.classList.add('is-leaving');
      window.setTimeout(function () {
        if (item.parentNode) { item.parentNode.removeChild(item); }
      }, 220);
    }, 2600);
  }

  function initializeConfirmSubmit() {
    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-ojp-confirm]');
      if (!trigger) {
        return;
      }
      if (trigger.getAttribute('data-ojp-confirmed') === 'true') {
        trigger.removeAttribute('data-ojp-confirmed');
        return;
      }

      var message = trigger.getAttribute('data-ojp-confirm') || '';
      if (message === '') {
        return;
      }
      event.preventDefault();
      event.stopImmediatePropagation();
      var label = (trigger.getAttribute('data-ojp-confirm-label') || trigger.textContent || '').trim();
      confirmSheet({
        title: message,
        confirmLabel: label !== '' && label.length <= 20 ? label : '확인',
        destructive: trigger.classList.contains('ojp-button--danger')
          || trigger.getAttribute('data-ojp-confirm-tone') === 'danger'
      }).then(function (accepted) {
        if (!accepted) {
          return;
        }
        trigger.setAttribute('data-ojp-confirmed', 'true');
        trigger.click();
      });
    }, true);
  }

  function initializeFlashToasts() {
    document.querySelectorAll('.ojp-notice--success[data-ojp-flash]').forEach(function (notice) {
      var title = notice.querySelector('strong');
      var body = notice.querySelector('p');
      var text = (title ? title.textContent : '').trim();
      var detail = (body ? body.textContent : '').trim();
      toast(detail !== '' && detail !== text ? text + ' · ' + detail : text, 'success');
      notice.parentNode.removeChild(notice);
    });
  }

  function initializeColorScheme() {
    var root = document.documentElement;
    var KEY = 'ojp-color-scheme';
    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-ojp-scheme-choice]'));
    if (!buttons.length) {
      return;
    }
    function sync() {
      var current = root.getAttribute('data-ojp-scheme') || 'auto';
      buttons.forEach(function (button) {
        button.setAttribute('aria-pressed', button.getAttribute('data-ojp-scheme-choice') === current ? 'true' : 'false');
      });
    }
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        var value = button.getAttribute('data-ojp-scheme-choice');
        root.setAttribute('data-ojp-scheme', value);
        try {
          if (value === 'auto') {
            window.localStorage.removeItem(KEY);
          } else {
            window.localStorage.setItem(KEY, value);
          }
        } catch (error) {
          // Without storage the choice lasts for this page only.
        }
        sync();
      });
    });
    sync();
  }

  function commandItems() {
    var items = [];
    var seen = Object.create(null);
    function add(label, group, href) {
      label = (label || '').trim();
      var id = (href || '') + '#' + label;
      if (!label || !href || seen[id]) {
        return;
      }
      seen[id] = true;
      items.push({ label: label, group: (group || '').trim(), href: href });
    }
    var nav = document.querySelector('.ojp-console-sidebar .ojp-console-nav')
      || document.querySelector('#ojp-console-drawer .ojp-console-nav');
    if (nav) {
      nav.querySelectorAll('.ojp-console-nav__group').forEach(function (group) {
        var root = group.querySelector('.ojp-console-nav__root');
        var rootLabel = root ? root.textContent : '';
        if (root) { add(rootLabel, '', root.getAttribute('href')); }
        group.querySelectorAll('.ojp-console-nav__children a').forEach(function (link) {
          add(link.textContent, rootLabel, link.getAttribute('href'));
        });
      });
    }
    document.querySelectorAll('.ojp-local-nav a, [data-ojp-command-item]').forEach(function (link) {
      add(
        link.getAttribute('data-ojp-command-label') || link.textContent,
        link.getAttribute('data-ojp-command-group') || '이 화면',
        link.getAttribute('href')
      );
    });
    return items;
  }

  function openCommandPalette() {
    if (document.querySelector('.ojp-palette')) {
      return;
    }
    var items = commandItems();
    lastSheetFocus = document.activeElement;
    var scrim = document.createElement('div');
    scrim.className = 'ojp-sheet-scrim ojp-sheet-scrim--top';
    var palette = document.createElement('div');
    palette.className = 'ojp-palette';
    palette.setAttribute('role', 'dialog');
    palette.setAttribute('aria-modal', 'true');
    palette.setAttribute('aria-label', '메뉴와 화면 찾기');
    var input = document.createElement('input');
    input.className = 'ojp-palette__input';
    input.type = 'search';
    input.placeholder = '메뉴 이름으로 찾기';
    input.setAttribute('aria-label', '찾을 메뉴 이름');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'true');
    input.setAttribute('aria-controls', 'ojp-palette-list');
    var list = document.createElement('ul');
    list.className = 'ojp-palette__list';
    list.id = 'ojp-palette-list';
    list.setAttribute('role', 'listbox');
    palette.appendChild(input);
    palette.appendChild(list);
    scrim.appendChild(palette);

    var visible = [];
    var active = 0;
    function go(item) {
      closeSheet(scrim);
      window.location.href = item.href;
    }
    function render() {
      var query = input.value.trim().toLowerCase().replace(/\s+/g, '');
      visible = items.filter(function (item) {
        if (query === '') { return true; }
        return (item.group + item.label).toLowerCase().replace(/\s+/g, '').indexOf(query) !== -1;
      }).slice(0, 40);
      active = Math.min(active, Math.max(visible.length - 1, 0));
      list.textContent = '';
      if (!visible.length) {
        var empty = document.createElement('li');
        empty.className = 'ojp-palette__empty';
        empty.textContent = '맞는 메뉴가 없습니다.';
        list.appendChild(empty);
        input.removeAttribute('aria-activedescendant');
        return;
      }
      visible.forEach(function (item, index) {
        var row = document.createElement('li');
        row.className = 'ojp-palette__item';
        row.id = 'ojp-palette-item-' + index;
        row.setAttribute('role', 'option');
        row.setAttribute('aria-selected', index === active ? 'true' : 'false');
        var label = document.createElement('span');
        label.textContent = item.label;
        row.appendChild(label);
        if (item.group) {
          var group = document.createElement('small');
          group.textContent = item.group;
          row.appendChild(group);
        }
        row.addEventListener('mousemove', function () {
          if (active !== index) { active = index; render(); }
        });
        row.addEventListener('click', function () { go(item); });
        list.appendChild(row);
      });
      input.setAttribute('aria-activedescendant', 'ojp-palette-item-' + active);
      var current = list.children[active];
      if (current && typeof current.scrollIntoView === 'function') {
        current.scrollIntoView({ block: 'nearest' });
      }
    }
    input.addEventListener('input', function () { active = 0; render(); });
    scrim.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeSheet(scrim);
      } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (visible.length) {
          active = (active + (event.key === 'ArrowDown' ? 1 : -1) + visible.length) % visible.length;
          render();
        }
      } else if (event.key === 'Enter' && visible[active]) {
        event.preventDefault();
        go(visible[active]);
      }
    });
    scrim.addEventListener('click', function (event) {
      if (event.target === scrim) { closeSheet(scrim); }
    });
    document.body.appendChild(scrim);
    render();
    input.focus();
  }

  function initializeCommandPalette() {
    var openers = document.querySelectorAll('[data-ojp-command-open]');
    if (!openers.length) {
      return;
    }
    var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
    openers.forEach(function (opener) {
      var hint = opener.querySelector('kbd');
      if (hint && mac) { hint.textContent = '⌘K'; }
      opener.addEventListener('click', openCommandPalette);
    });
    document.addEventListener('keydown', function (event) {
      if ((event.ctrlKey || event.metaKey) && !event.altKey && (event.key === 'k' || event.key === 'K')) {
        event.preventDefault();
        openCommandPalette();
      }
    });
  }

  window.OJP = window.OJP || {};
  window.OJP.confirm = confirmSheet;
  window.OJP.toast = toast;
  window.OJP.openCommandPalette = openCommandPalette;

  function generateStrongPassword() {
    var groups = [
      'ABCDEFGHJKLMNPQRSTUVWXYZ',
      'abcdefghijkmnopqrstuvwxyz',
      '23456789',
      '!@#$%*+-_'
    ];
    var pool = groups.join('');
    var characters = groups.map(function (group) {
      return group.charAt(Math.floor(Math.random() * group.length));
    });

    while (characters.length < 16) {
      characters.push(pool.charAt(Math.floor(Math.random() * pool.length)));
    }

    for (var index = characters.length - 1; index > 0; index -= 1) {
      var swap = Math.floor(Math.random() * (index + 1));
      var temp = characters[index];
      characters[index] = characters[swap];
      characters[swap] = temp;
    }

    return characters.join('');
  }

  function passwordStrength(value) {
    var categories = 0;
    if (/[A-Z]/.test(value)) {
      categories += 1;
    }
    if (/[a-z]/.test(value)) {
      categories += 1;
    }
    if (/[0-9]/.test(value)) {
      categories += 1;
    }
    if (/[^A-Za-z0-9]/.test(value)) {
      categories += 1;
    }

    if (value.length >= 12 && categories >= 3) {
      return 'strong';
    }
    if (value.length >= 8 && categories >= 2) {
      return 'medium';
    }

    return 'weak';
  }

  function initializePasswordSuggest(input) {
    var strengthLabel = { weak: '약함', medium: '보통', strong: '강함' };
    var group = input.closest('[data-ojp-password-group]');
    var strengthOutput = group ? group.querySelector('[data-ojp-password-strength]') : null;
    var weakConfirm = group ? group.querySelector('[data-ojp-weak-password-confirm]') : null;
    var weakCheckbox = weakConfirm ? weakConfirm.querySelector('input[type="checkbox"]') : null;
    var regenerateButton = group ? group.querySelector('[data-ojp-password-regenerate]') : null;
    var form = input.form;

    function needsWeakConfirm() {
      return input.value !== '' && passwordStrength(input.value) === 'weak';
    }

    function refreshStrength() {
      var level = passwordStrength(input.value);
      if (strengthOutput) {
        strengthOutput.textContent = input.value === '' ? '' : strengthLabel[level];
        strengthOutput.className = 'ojp-password-strength ojp-password-strength--' + level;
      }
      if (weakConfirm) {
        var requiresConfirm = needsWeakConfirm();
        weakConfirm.hidden = !requiresConfirm;
        if (!requiresConfirm && weakCheckbox) {
          weakCheckbox.checked = false;
        }
      }
    }

    input.value = generateStrongPassword();
    refreshStrength();
    input.addEventListener('input', refreshStrength);

    if (regenerateButton) {
      regenerateButton.addEventListener('click', function () {
        input.value = generateStrongPassword();
        input.dispatchEvent(new Event('ojp-password-regenerated'));
        input.focus();
        refreshStrength();
      });
    }

    if (form) {
      form.addEventListener('submit', function (event) {
        if (needsWeakConfirm() && (!weakCheckbox || !weakCheckbox.checked)) {
          event.preventDefault();
          if (weakConfirm) {
            weakConfirm.hidden = false;
          }
          window.alert(
            '약한 비밀번호입니다. "약한 비밀번호를 사용합니다"를 체크해야 이 비밀번호로 초기화할 수 있습니다.'
          );
          input.focus();
        }
      });
    }
  }

  var passwordFieldSequence = 0;
  function initializePasswordVisibility(input) {
    if (input.hasAttribute('data-ojp-password-visibility')) { return; }
    input.setAttribute('data-ojp-password-visibility', '');
    var group = input.closest('[data-ojp-secret-field], [data-ojp-password-group]');
    var toggle = group ? group.querySelector('[data-ojp-secret-toggle], [data-ojp-password-toggle]') : null;
    if (!toggle) {
      group = document.createElement('div');
      group.className = 'ojp-password-field';
      input.parentNode.insertBefore(group, input);
      group.appendChild(input);
      toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'ojp-button ojp-button--small';
      toggle.setAttribute('data-ojp-password-toggle', '');
      group.appendChild(toggle);
    }
    if (!input.id) {
      do { passwordFieldSequence += 1; input.id = 'ojp-password-visibility-' + passwordFieldSequence; }
      while (document.getElementById(input.id) !== input);
    }
    toggle.setAttribute('aria-controls', input.id);

    function setVisible(visible) {
      input.type = visible ? 'text' : 'password';
      toggle.textContent = visible ? '숨김' : '보임';
      toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
      toggle.setAttribute('aria-label', visible ? '비밀번호 숨김' : '비밀번호 보임');
      toggle.title = visible ? '비밀번호 숨김' : '비밀번호 보임';
    }
    function hide() { setVisible(false); }
    hide();
    toggle.addEventListener('click', function () { setVisible(input.type === 'password'); });
    group.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { hide(); toggle.focus(); }
    });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { hide(); }
    });
    window.addEventListener('pagehide', hide);
    window.addEventListener('pageshow', hide);
    input.addEventListener('ojp-password-regenerated', hide);
    if (input.form) { input.form.addEventListener('submit', hide); }
  }

  function initializeDialogs() {
    document.addEventListener('click', function (event) {
      var opener = event.target.closest('[data-ojp-dialog-open]');
      var closer = event.target.closest('[data-ojp-dialog-close]');

      if (opener) {
        var dialog = byId(opener.getAttribute('data-ojp-dialog-open'));
        if (dialog && typeof dialog.showModal === 'function') {
          dialog.showModal();
        }
      }

      if (closer) {
        var parentDialog = closer.closest('dialog');
        if (parentDialog) {
          parentDialog.close();
        }
      }
    });
  }

  function initializeDrawers() {
    document.addEventListener('click', function (event) {
      var toggle = event.target.closest('[data-ojp-drawer-toggle]');
      if (!toggle) {
        return;
      }

      var drawer = byId(toggle.getAttribute('data-ojp-drawer-toggle'));
      if (!drawer) {
        return;
      }

      var open = drawer.getAttribute('data-open') !== 'true';
      drawer.setAttribute('data-open', open ? 'true' : 'false');
      drawer.inert = !open;
      document.querySelectorAll('[data-ojp-drawer-toggle]').forEach(function (button) {
        if (button.getAttribute('data-ojp-drawer-toggle') === drawer.id) {
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
      });
      if (!open) {
        var opener = document.querySelector('[data-ojp-drawer-toggle="' + drawer.id + '"]');
        if (opener) { opener.focus(); }
      }
    });
  }

  function initializeConsoleSubmenus() {
    var storageKey = 'ojp-console-submenus';
    var toggles = Array.prototype.slice.call(document.querySelectorAll('[data-ojp-console-submenu]'));
    if (!toggles.length) { return; }
    var saved = Object.create(null);
    try {
      var stored = JSON.parse(window.sessionStorage.getItem(storageKey) || '{}');
      if (stored && typeof stored === 'object' && !Array.isArray(stored)) {
        Object.keys(stored).forEach(function (key) { saved[key] = stored[key] === true; });
      }
    } catch (error) { /* Menu navigation also works when browser storage is unavailable. */ }

    function setOpen(key, open) {
      toggles.forEach(function (toggle) {
        if (toggle.dataset.menuKey !== key) { return; }
        var panel = byId(toggle.getAttribute('aria-controls'));
        if (!panel) { return; }
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        var label = toggle.dataset.menuLabel + (open ? ' 하위 메뉴 접기' : ' 하위 메뉴 펼치기');
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
      });
    }

    toggles.forEach(function (toggle) {
      setOpen(toggle.dataset.menuKey, toggle.dataset.current === 'true' || saved[toggle.dataset.menuKey] === true);
      toggle.addEventListener('click', function () {
        var open = toggle.getAttribute('aria-expanded') !== 'true';
        saved[toggle.dataset.menuKey] = open;
        setOpen(toggle.dataset.menuKey, open);
        try { window.sessionStorage.setItem(storageKey, JSON.stringify(saved)); } catch (error) { /* Optional persistence. */ }
      });
    });
  }

  function initializeSidebarCollapse() {
    var STORAGE_KEY = 'ojp-console-sidebar';
    var root = document.documentElement;
    var toggles = Array.prototype.slice.call(
      document.querySelectorAll('[data-ojp-sidebar-toggle]')
    );
    if (toggles.length === 0) {
      return;
    }

    function syncToggles(collapsed) {
      toggles.forEach(function (toggle) {
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', collapsed ? '메뉴 펼치기' : '메뉴 접기');
      });
    }

    syncToggles(root.getAttribute('data-ojp-sidebar') === 'collapsed');

    toggles.forEach(function (toggle) {
      toggle.addEventListener('click', function () {
        var collapsed = root.getAttribute('data-ojp-sidebar') === 'collapsed';
        var next = !collapsed;
        if (next) {
          root.setAttribute('data-ojp-sidebar', 'collapsed');
        } else {
          root.removeAttribute('data-ojp-sidebar');
        }
        syncToggles(next);
        try {
          window.localStorage.setItem(STORAGE_KEY, next ? 'collapsed' : 'expanded');
        } catch (error) {
          // localStorage may be unavailable (private browsing); the choice
          // just won't persist across page loads in that case.
        }
      });
    });
  }

  function initializeMenus() {
    var toggles = Array.prototype.slice.call(
      document.querySelectorAll('[data-ojp-menu-toggle]')
    );

    function closeAll(exceptId) {
      toggles.forEach(function (toggle) {
        var id = toggle.getAttribute('data-ojp-menu-toggle');
        if (id === exceptId) {
          return;
        }
        var menu = byId(id);
        toggle.setAttribute('aria-expanded', 'false');
        if (menu) {
          menu.hidden = true;
        }
      });
    }

    toggles.forEach(function (toggle) {
      toggle.addEventListener('click', function (event) {
        var id = toggle.getAttribute('data-ojp-menu-toggle');
        var menu = byId(id);
        if (!menu) {
          return;
        }
        event.preventDefault();
        var open = menu.hidden;
        closeAll(open ? id : '');
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });

    document.addEventListener('click', function (event) {
      if (event.target.closest('[data-ojp-menu-toggle], [data-ojp-menu]')) {
        return;
      }
      closeAll('');
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') {
        return;
      }
      var active = toggles.find(function (toggle) {
        return toggle.getAttribute('aria-expanded') === 'true';
      });
      closeAll('');
      if (active) {
        active.focus();
      }
    });

    document.querySelectorAll('[data-ojp-menu] a').forEach(function (link) {
      link.addEventListener('click', function () {
        closeAll('');
      });
    });
  }

  function tableCellText(cell) {
    var values = Array.prototype.map.call(cell.querySelectorAll('input:not([type="checkbox"]):not([type="hidden"]), select, textarea'), function (field) {
      return field.value;
    });
    return (values.length ? values.join(' ') : cell.textContent || '').trim();
  }

  function initializeSortableTable(table) {
    var headers = Array.prototype.slice.call(
      table.querySelectorAll('thead [data-ojp-sort-key]')
    );
    if (!headers.length) {
      return;
    }

    var body = table.querySelector('tbody');

    function rowValue(row, columnIndex) {
      var cell = row.children[columnIndex];
      return cell ? (cell.getAttribute('data-sort-value') || tableCellText(cell)).trim() : '';
    }

    function sortBy(header, columnIndex) {
      if (!body) {
        return;
      }
      var rows = Array.prototype.slice.call(body.rows);
      var direction = header.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
      var factor = direction === 'ascending' ? 1 : -1;

      rows.sort(function (a, b) {
        var valueA = rowValue(a, columnIndex);
        var valueB = rowValue(b, columnIndex);
        var numberA = Number(valueA);
        var numberB = Number(valueB);
        if (valueA !== '' && valueB !== '' && !Number.isNaN(numberA) && !Number.isNaN(numberB)) {
          return (numberA - numberB) * factor;
        }
        return valueA.localeCompare(valueB, 'ko') * factor;
      });

      headers.forEach(function (other) {
        other.removeAttribute('aria-sort');
      });
      header.setAttribute('aria-sort', direction);
      rows.forEach(function (row) {
        body.appendChild(row);
      });
    }

    headers.forEach(function (header) {
      var columnIndex = Array.prototype.indexOf.call(
        header.parentElement.children,
        header
      );
      header.setAttribute('role', 'button');
      header.setAttribute('tabindex', '0');
      header.classList.add('ojp-sortable-column');
      header.addEventListener('click', function () {
        sortBy(header, columnIndex);
      });
      header.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }
        event.preventDefault();
        sortBy(header, columnIndex);
      });
    });
  }

  function initializeTableFilter(input) {
    var table = byId(input.getAttribute('data-ojp-table-filter'));
    var body = table ? table.querySelector('tbody') : null;
    if (!body) {
      return;
    }

    function isPlaceholderRow(row) {
      return row.cells.length === 1 && row.cells[0].hasAttribute('colspan');
    }

    var noResults = document.createElement('tr');
    var noResultsCell = document.createElement('td');
    noResultsCell.colSpan = table.querySelectorAll('thead th').length || 1;
    noResultsCell.textContent = '검색 결과가 없습니다.';
    noResults.hidden = true;
    noResults.appendChild(noResultsCell);

    function apply() {
      var query = input.value.trim().toLowerCase();
      var rows = Array.prototype.slice.call(body.rows).filter(function (row) {
        return row !== noResults && !isPlaceholderRow(row);
      });
      var visible = 0;
      rows.forEach(function (row) {
        var text = Array.prototype.map.call(row.cells, tableCellText).join(' ').toLowerCase();
        var matches = query === '' || text.indexOf(query) !== -1;
        row.hidden = !matches;
        if (matches) {
          visible += 1;
        }
      });
      if (rows.length > 0) {
        if (!noResults.parentElement) {
          body.appendChild(noResults);
        }
        noResults.hidden = visible !== 0;
      }
    }

    input.addEventListener('input', apply);
    table.addEventListener('input', apply);
    apply();
  }

  function initializeMemberNames(root) {
    var form = root.querySelector('[data-ojp-name-form]');
    var fields = Array.prototype.slice.call(root.querySelectorAll('[data-ojp-managed-name]'));
    var save = form.querySelector('[type="submit"]');
    var reset = root.querySelector('[data-ojp-name-reset]');
    var count = root.querySelector('[data-ojp-name-count]');
    var status = root.querySelector('[data-ojp-name-status]');
    var busy = false;

    function changed() {
      return fields.filter(function (field) { return field.value.trim() !== field.dataset.originalName; });
    }
    function update() {
      var size = changed().length;
      count.textContent = '변경 ' + size + '명';
      save.disabled = busy || size === 0;
      reset.disabled = busy || size === 0;
    }
    function fieldError(field, message) {
      var error = byId(field.getAttribute('aria-describedby'));
      error.textContent = message;
      error.hidden = !message;
      field.setAttribute('aria-invalid', message ? 'true' : 'false');
    }
    fields.forEach(function (field) {
      field.addEventListener('input', function () { fieldError(field, ''); update(); });
    });
    reset.addEventListener('click', function () {
      fields.forEach(function (field) {
        field.value = field.dataset.originalName;
        fieldError(field, '');
        field.dispatchEvent(new Event('input', { bubbles: true }));
      });
      status.textContent = '';
      update();
    });
    window.addEventListener('beforeunload', function (event) {
      if (busy || changed().length) { event.preventDefault(); event.returnValue = ''; }
    });
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (busy) { return; }
      var pending = changed();
      if (!pending.length) { return; }
      if (pending.length > 100) { status.textContent = '한 번에 100명까지 저장할 수 있습니다.'; return; }
      var invalid = pending.find(function (field) { return !field.value.trim(); });
      if (invalid) { fieldError(invalid, '관리용 이름을 입력해 주세요.'); invalid.focus(); return; }
      var body = new FormData(form);
      body.set('name_changes', JSON.stringify(pending.map(function (field) {
        return { user_id: Number(field.dataset.userId), name: field.value, expected_name: field.dataset.originalName };
      })));
      busy = true;
      fields.forEach(function (field) { field.disabled = true; });
      update();
      status.textContent = '저장 중...';
      try {
        // WordPress's hidden input named "action" shadows the form.action property.
        var response = await fetch(form.getAttribute('action'), { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        var result = await response.json();
        if (!response.ok || !Array.isArray(result.results)) {
          throw new Error(result.error || '저장하지 못했습니다. 로그인 상태를 확인하고 다시 시도해 주세요.');
        }
        var saved = 0;
        pending.forEach(function (field) {
          var row = result.results.find(function (item) { return item.user_id === Number(field.dataset.userId); });
          if (row && row.saved && typeof row.name === 'string') {
            field.value = row.name;
            field.dataset.originalName = row.name;
            fieldError(field, '');
            saved += 1;
          } else {
            fieldError(field, row && row.error ? row.error : '저장 결과를 확인하지 못했습니다. 다시 시도해 주세요.');
          }
        });
        status.textContent = saved + '명 저장 완료' + (saved < pending.length ? ', ' + (pending.length - saved) + '명 저장 실패' : '');
      } catch (error) {
        status.textContent = error instanceof SyntaxError || error instanceof TypeError
          ? '서버 응답을 확인하지 못했습니다. 입력 내용은 유지됩니다. 로그인과 연결 상태를 확인해 주세요.' : error.message;
      } finally {
        busy = false;
        fields.forEach(function (field) { field.disabled = false; });
        update();
      }
    });
    update();
  }

  function initializeBulkSelection(root) {
    var master = root.querySelector('[data-ojp-select-all]');
    var items = Array.prototype.slice.call(root.querySelectorAll('[data-ojp-select-item]'));
    var bar = root.querySelector('[data-ojp-bulk-bar]');
    var count = root.querySelector('[data-ojp-selected-count]');

    if (!master || items.length === 0) {
      return;
    }

    function update() {
      var selected = items.filter(function (item) {
        return item.checked;
      }).length;

      master.checked = selected === items.length;
      master.indeterminate = selected > 0 && selected < items.length;
      if (bar) {
        bar.hidden = selected === 0;
      }
      if (count) {
        count.textContent = String(selected);
      }
    }

    master.addEventListener('change', function () {
      items.forEach(function (item) {
        item.checked = master.checked;
      });
      update();
    });
    items.forEach(function (item) {
      item.addEventListener('change', update);
    });
    root.querySelectorAll('form[data-ojp-bulk-copy]').forEach(function (form) {
      form.addEventListener('submit', function () {
        form.querySelectorAll('[data-ojp-bulk-copy-value]').forEach(function (input) {
          input.remove();
        });
        items.forEach(function (item) {
          if (!item.checked || !item.name) {
            return;
          }
          var copy = document.createElement('input');
          copy.type = 'hidden';
          copy.name = item.name;
          copy.value = item.value;
          copy.setAttribute('data-ojp-bulk-copy-value', '');
          form.appendChild(copy);
        });
      });
    });
    update();
  }

  function createField(labelText, control) {
    var field = document.createElement('label');
    var label = document.createElement('span');
    field.className = 'ojp-field';
    label.className = 'ojp-label';
    label.textContent = labelText;
    field.appendChild(label);
    field.appendChild(control);
    return field;
  }

  function initializeCodeCompletion(root) {
    var form = root.closest('form');
    var schemaInput = root.querySelector('[data-ojp-completion-schema]');
    var template = root.querySelector('textarea[name="starter_code"]');
    var language = root.querySelector('[name="completion_language_key"]');
    var addButton = root.querySelector('[data-ojp-add-blank]');
    var list = root.querySelector('[data-ojp-blank-list]');
    var toolbar = root.querySelector('.ojp-code-completion__toolbar');
    var modes = form
      ? Array.prototype.slice.call(form.querySelectorAll('input[name="code_entry_mode"]'))
      : [];

    if (!schemaInput || !template || !language || !addButton || !list) {
      return;
    }

    var schema = {
      language_key: '',
      blanks: []
    };
    var message = document.createElement('p');
    message.className = 'ojp-form-note';
    message.setAttribute('role', 'status');
    message.setAttribute('aria-live', 'polite');
    message.hidden = true;
    if (toolbar) {
      toolbar.insertAdjacentElement('afterend', message);
    } else {
      root.insertBefore(message, list);
    }

    try {
      var parsed = JSON.parse(schemaInput.value || '{}');
      if (parsed && typeof parsed === 'object') {
        schema.language_key = typeof parsed.language_key === 'string'
          ? parsed.language_key
          : '';
        schema.blanks = Array.isArray(parsed.blanks)
          ? parsed.blanks.filter(function (blank) {
            return blank && typeof blank.id === 'string';
          }).map(function (blank) {
            return {
              id: blank.id,
              label: typeof blank.label === 'string' ? blank.label : blank.id,
              kind: blank.kind === 'block' ? 'block' : 'inline',
              max_bytes: Math.min(65536, Math.max(1, Number(blank.max_bytes) || 256))
            };
          })
          : [];
      }
    } catch (error) {
      schema = {
        language_key: '',
        blanks: []
      };
    }

    if (language.tagName !== 'SELECT' || Array.prototype.some.call(language.options, function (option) {
      return option.value === schema.language_key;
    })) {
      language.value = language.value || schema.language_key;
    }

    function showMessage(text, isError) {
      message.textContent = text;
      message.classList.toggle('is-error', Boolean(isError));
      message.hidden = text === '';
    }

    function writeSchema() {
      schema.language_key = language.value.trim();
      schemaInput.value = JSON.stringify({
        language_key: schema.language_key,
        blanks: schema.blanks.map(function (blank) {
          return {
            id: blank.id,
            label: blank.label.trim(),
            kind: blank.kind,
            max_bytes: Number(blank.max_bytes)
          };
        })
      });
    }

    function nextBlankId() {
      var sequence = 1;
      var used = schema.blanks.reduce(function (ids, blank) {
        ids[blank.id] = true;
        return ids;
      }, {});
      while (used['blank_' + sequence]) {
        sequence += 1;
      }
      return 'blank_' + sequence;
    }

    function renderBlank(blank, index) {
      var row = document.createElement('div');
      var idField = document.createElement('div');
      var idLabel = document.createElement('span');
      var idValue = document.createElement('span');
      var labelInput = document.createElement('input');
      var kindSelect = document.createElement('select');
      var inlineOption = document.createElement('option');
      var blockOption = document.createElement('option');
      var maxInput = document.createElement('input');
      var remove = document.createElement('button');

      row.className = 'ojp-blank-row';
      row.setAttribute('data-ojp-blank-id', blank.id);

      idField.className = 'ojp-field';
      idLabel.className = 'ojp-label';
      idLabel.textContent = '빈칸 ID';
      idValue.className = 'ojp-blank-row__id';
      idValue.textContent = blank.id;
      idValue.title = blank.id;
      idField.appendChild(idLabel);
      idField.appendChild(idValue);

      labelInput.className = 'ojp-input';
      labelInput.type = 'text';
      labelInput.value = blank.label;
      labelInput.required = true;
      labelInput.maxLength = 100;
      labelInput.addEventListener('input', function () {
        blank.label = labelInput.value;
        writeSchema();
      });

      kindSelect.className = 'ojp-select';
      inlineOption.value = 'inline';
      inlineOption.textContent = '한 줄';
      blockOption.value = 'block';
      blockOption.textContent = '여러 줄';
      kindSelect.appendChild(inlineOption);
      kindSelect.appendChild(blockOption);
      kindSelect.value = blank.kind;
      kindSelect.addEventListener('change', function () {
        blank.kind = kindSelect.value === 'block' ? 'block' : 'inline';
        writeSchema();
      });

      maxInput.className = 'ojp-input';
      maxInput.type = 'number';
      maxInput.min = '1';
      maxInput.max = '65536';
      maxInput.required = true;
      maxInput.value = String(blank.max_bytes);
      maxInput.addEventListener('input', function () {
        blank.max_bytes = Math.min(65536, Math.max(1, Number(maxInput.value) || 1));
        writeSchema();
      });

      remove.className = 'ojp-button ojp-button--danger ojp-button--small';
      remove.type = 'button';
      remove.textContent = '삭제';
      remove.setAttribute('aria-label', blank.label + ' 빈칸 삭제');
      remove.addEventListener('click', function () {
        var token = '{{OJ_BLANK:' + blank.id + '}}';
        schema.blanks.splice(index, 1);
        template.value = template.value.split(token).join('');
        render();
        template.focus();
        showMessage('빈칸을 삭제했습니다.', false);
      });

      row.appendChild(idField);
      row.appendChild(createField('표시 이름', labelInput));
      row.appendChild(createField('입력 형식', kindSelect));
      row.appendChild(createField('최대 바이트', maxInput));
      row.appendChild(remove);
      return row;
    }

    function render() {
      list.replaceChildren();
      schema.blanks.forEach(function (blank, index) {
        list.appendChild(renderBlank(blank, index));
      });
      if (schema.blanks.length === 0) {
        var empty = document.createElement('p');
        empty.className = 'ojp-form-note';
        empty.textContent = '아직 지정한 코드 빈칸이 없습니다.';
        list.appendChild(empty);
      }
      writeSchema();
    }

    function updateMode() {
      var selected = modes.find(function (mode) {
        return mode.checked;
      });
      root.hidden = Boolean(selected) && selected.value !== 'code_completion';
      language.required = !root.hidden;
      root.querySelectorAll('input, textarea, select, button').forEach(function (control) {
        // Full-code mode still owns its existing starter text; ordering never posts it.
        control.disabled = root.hidden && !(selected && selected.value === 'full_code' && control.name === 'starter_code');
      });
      if (!root.hidden) {
        writeSchema();
      }
    }

    addButton.addEventListener('click', function () {
      var start = template.selectionStart;
      var end = template.selectionEnd;
      if (schema.blanks.length >= 30) {
        showMessage('코드 빈칸은 최대 30개까지 만들 수 있습니다.', true);
        return;
      }
      if (typeof start !== 'number' || typeof end !== 'number' || start === end) {
        showMessage('시작 코드에서 빈칸으로 바꿀 영역을 먼저 선택해 주세요.', true);
        template.focus();
        return;
      }

      var selectedCode = template.value.slice(start, end);
      var id = nextBlankId();
      var kind = selectedCode.indexOf('\n') >= 0 ? 'block' : 'inline';
      var blank = {
        id: id,
        label: '빈칸 ' + (schema.blanks.length + 1),
        kind: kind,
        max_bytes: kind === 'block' ? 4096 : 256
      };
      var token = '{{OJ_BLANK:' + id + '}}';
      template.setRangeText(token, start, end, 'end');
      schema.blanks.push(blank);
      render();
      showMessage('선택한 영역을 ' + blank.label + '으로 바꿨습니다.', false);
      template.focus();
    });

    language.addEventListener('input', writeSchema);
    language.addEventListener('change', writeSchema);
    modes.forEach(function (mode) {
      mode.addEventListener('change', updateMode);
    });
    if (form) {
      form.addEventListener('submit', writeSchema);
    }

    render();
    updateMode();
  }

  function initializeAgreementDocumentForm(form) {
    var type = form.querySelector('[name="type_slug"]');
    var required = form.querySelector('[data-ojp-agreement-required]');
    var label = form.querySelector('[data-ojp-agreement-required-label]');
    if (!type || !required || !label) return;
    var userRequired = required.checked;
    function update() {
      var option = type.options[type.selectedIndex];
      var guardianOnly = option && option.getAttribute('data-guardian-only') === 'true';
      if (!required.disabled) userRequired = required.checked;
      required.disabled = Boolean(guardianOnly);
      required.checked = guardianOnly ? true : userRequired;
      label.textContent = guardianOnly ? '해당 가입자의 법정대리인 필수 동의' : '필수 동의 문서';
    }
    type.addEventListener('change', update);
    update();
  }

  var richTextLoader = null;
  var uiScript = document.currentScript;
  function initializeRichTextEditor(root) {
    if (!richTextLoader) {
      richTextLoader = new Promise(function (resolve, reject) {
        var script = document.createElement('script');
        var base = uiScript || document.querySelector('script[src*="/ui/js/oj-ui.js"]');
        if (!base || !base.src) { reject(new Error('편집기 자산 주소를 확인하지 못했습니다.')); return; }
        var url = new URL('../../features/richtext/js/editor.js', base.src);
        url.search = new URL(base.src).search;
        script.src = url.href;
        script.onload = function () { resolve(window.OJPRichText); };
        script.onerror = function () { reject(new Error('편집기를 불러오지 못했습니다.')); };
        document.head.appendChild(script);
      });
    }
    richTextLoader.then(function (editor) { return editor.initialize(root); }).catch(function (error) {
      var status = root.querySelector('[data-ojp-rich-text-status]');
      if (status) { status.hidden = false; status.textContent = error.message + ' HTML 입력란을 사용할 수 있습니다.'; }
      reportInitError('rich-text-editor', error);
    });
  }

  function initializeAiJobForm(form) {
    var status = form.querySelector('[data-ojp-ai-job-status]');
    var submit = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (submit) {
        submit.disabled = true;
      }
      if (status) {
        status.textContent = '작업을 등록하는 중입니다.';
      }
      window.fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) {
        return response.json().then(function (body) {
          return { response: response, body: body };
        });
      }).then(function (result) {
        if (!result.response.ok || !result.body.ok) {
          throw new Error(result.body.message || 'AI 작업을 등록하지 못했습니다.');
        }
        if (status) {
          status.textContent = result.body.message;
        }
        window.location.assign(result.body.status_url);
      }).catch(function (error) {
        if (status) {
          status.textContent = error.message;
        }
        if (submit) {
          submit.disabled = false;
        }
      });
    });
  }

  function initializeDraftReview(review) {
    var applyButton = review.querySelector('[data-ojp-apply-draft]');
    var status = review.querySelector('[data-ojp-draft-status]');
    var editor = document.querySelector('[data-ojp-problem-editor]');
    var reference = review.getAttribute('data-draft-reference') || '';

    if (!applyButton || !editor || !reference) {
      return;
    }

    function field(name) {
      if (name === 'topic_keys') {
        return editor.elements.namedItem('topic_keys[]');
      }
      return editor.elements.namedItem(name);
    }

    function draftValue(name) {
      var values = Array.prototype.slice.call(
        review.querySelectorAll('[data-ojp-draft-value]')
      );
      var value = values.find(function (candidate) {
        return candidate.getAttribute('data-ojp-draft-value') === name;
      });
      return value ? value.value : null;
    }

    function rememberReference() {
      var existing = Array.prototype.slice.call(
        editor.querySelectorAll('input[name="applied_draft_references[]"]')
      ).some(function (input) {
        return input.value === reference;
      });
      if (existing) {
        return;
      }
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'applied_draft_references[]';
      input.value = reference;
      editor.appendChild(input);
    }

    applyButton.addEventListener('click', function () {
      var selected = Array.prototype.slice.call(
        review.querySelectorAll('[data-ojp-draft-select]:checked')
      );
      var applied = 0;

      selected.forEach(function (choice) {
        var targetName = choice.getAttribute('data-ojp-draft-target') || '';
        var control = field(targetName);
        var value = draftValue(targetName);
        if (!control || value === null) {
          return;
        }
        if (targetName === 'topic_keys') {
          var topicKeys;
          try {
            topicKeys = JSON.parse(value);
          } catch (error) {
            topicKeys = [];
          }
          Array.prototype.slice.call(control.options).forEach(function (option) {
            option.selected = topicKeys.indexOf(option.value) >= 0;
          });
        } else {
          control.value = value;
        }
        control.dispatchEvent(new Event('input', { bubbles: true }));
        control.dispatchEvent(new Event('change', { bubbles: true }));
        applied += 1;
      });

      if (applied === 0) {
        if (status) {
          status.textContent = '적용할 항목을 하나 이상 선택해 주세요.';
          status.classList.add('is-error');
        }
        return;
      }
      rememberReference();
      if (status) {
        status.textContent = applied
          + '개 항목을 편집 폼에 옮겼습니다. 내용을 확인한 뒤 저장하세요.';
        status.classList.remove('is-error');
      }
    });
  }

  function initializeJobPoll(form) {
    var initialStatus = form.getAttribute('data-job-status') || '';
    var timer = window.setInterval(function () {
      window.fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) {
        return response.json();
      }).then(function (body) {
        if (!body.ok) {
          return;
        }
        if (body.terminal || body.status !== initialStatus) {
          window.clearInterval(timer);
          window.location.reload();
        }
      }).catch(function () {
        window.clearInterval(timer);
      });
    }, 3000);
  }

  function initializeLimitedMultiSelect(select) {
    var maximum = Number(select.getAttribute('data-ojp-max-selections'));
    var help = select.parentElement
      ? select.parentElement.querySelector('[data-ojp-selection-help]')
      : null;

    if (!Number.isInteger(maximum) || maximum < 1) {
      return;
    }

    select.addEventListener('change', function () {
      var selected = Array.prototype.slice.call(select.options).filter(function (option) {
        return option.selected;
      });
      if (selected.length <= maximum) {
        if (help) {
          help.classList.remove('ojp-field-error');
          help.textContent = '최대 ' + maximum + '개까지 선택할 수 있습니다.';
        }
        return;
      }

      selected.slice(maximum).forEach(function (option) {
        option.selected = false;
      });
      if (help) {
        help.classList.add('ojp-field-error');
        help.textContent = '최대 ' + maximum + '개만 선택할 수 있습니다.';
      }
    });
  }

  function initializeQuizEditor(root) {
    var questionsContainer = root.querySelector('[data-oj-quiz-questions]');
    var addQuestion = root.querySelector('[data-oj-quiz-add-question]');
    var resultPolicy = root.querySelector('[data-oj-quiz-result-policy]');
    var resultsAt = root.querySelector('[data-oj-quiz-results-at]');
    var protection = root.querySelector('[data-oj-quiz-protection]');
    var reapplyOptions = root.querySelector('[data-oj-quiz-reapply-options]');

    if (!questionsContainer || !addQuestion) {
      return;
    }

    function uuid() {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
      }
      var bytes = new Uint8Array(16);
      if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        window.crypto.getRandomValues(bytes);
      } else {
        for (var index = 0; index < bytes.length; index += 1) {
          bytes[index] = Math.floor(Math.random() * 256);
        }
      }
      bytes[6] = (bytes[6] & 15) | 64;
      bytes[8] = (bytes[8] & 63) | 128;
      var hex = Array.prototype.map.call(bytes, function (value) {
        return value.toString(16).padStart(2, '0');
      }).join('');
      return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-'
        + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-'
        + hex.slice(20);
    }

    function resetChoice(choice, index) {
      var fields = choice.querySelectorAll('[data-oj-quiz-choice-field]');
      fields.forEach(function (field) {
        var key = field.getAttribute('data-oj-quiz-choice-field');
        if (key === 'choice_key') {
          field.value = uuid();
        } else if (key === 'enabled') {
          field.value = '1';
        } else if (key === 'label') {
          field.value = String.fromCharCode(65 + index);
        } else {
          field.value = '';
        }
      });
      var correct = choice.querySelector('[data-oj-quiz-correct]');
      if (correct) {
        correct.checked = index === 0;
      }
    }

    function resetQuestion(question) {
      var exportButton = question.querySelector('[data-oj-quiz-export-button]');
      if (exportButton) {
        exportButton.remove();
      }
      question.querySelectorAll('[data-oj-quiz-question-field]').forEach(function (field) {
        var key = field.getAttribute('data-oj-quiz-question-field');
        if (key === 'question_key') {
          field.value = uuid();
        } else if (key === 'enabled') {
          field.value = '1';
        } else if (key === 'points') {
          field.value = '1';
        } else if (key === 'type') {
          field.value = 'single_choice';
        } else {
          field.value = '';
        }
      });
      var choices = Array.prototype.slice.call(
        question.querySelectorAll('[data-oj-quiz-choice]')
      );
      choices.slice(2).forEach(function (choice) {
        choice.remove();
      });
      Array.prototype.slice.call(
        question.querySelectorAll('[data-oj-quiz-choice]')
      ).forEach(resetChoice);
      updateQuestionType(question);
    }

    function questionType(question) {
      var typeField = question.querySelector('[data-oj-quiz-question-field="type"]');
      return typeField ? typeField.value : 'single_choice';
    }

    function updateQuestionType(question) {
      var type = questionType(question);
      var typeLabel = question.querySelector('[data-oj-quiz-type-label]');
      if (typeLabel) {
        typeLabel.textContent = { single_choice: '단일 선택', multi_choice: '다중 답', short_answer: '단답형' }[type] || '단일 선택';
      }
      var choicesSection = question.querySelector('[data-oj-quiz-choices-section]');
      var choicesList = question.querySelector('[data-oj-quiz-choices]');
      var answersSection = question.querySelector('[data-oj-quiz-accepted-answers-section]');
      if (choicesSection) {
        choicesSection.hidden = type === 'short_answer';
      }
      if (choicesList) {
        choicesList.hidden = type === 'short_answer';
        choicesList.querySelectorAll('input, textarea, select').forEach(function (field) {
          field.disabled = type === 'short_answer';
        });
      }
      if (answersSection) {
        answersSection.hidden = type !== 'short_answer';
        answersSection.querySelectorAll('input, textarea, select').forEach(function (field) {
          field.disabled = type !== 'short_answer';
          if (field.matches('[data-oj-quiz-question-field="accepted_answers_text"]')) {
            field.required = type === 'short_answer';
          }
        });
      }
      var correctInputs = Array.prototype.slice.call(
        question.querySelectorAll('[data-oj-quiz-correct]')
      );
      var isMulti = type === 'multi_choice';
      correctInputs.forEach(function (input) {
        input.type = isMulti ? 'checkbox' : 'radio';
      });
      if (!isMulti) {
        var checked = correctInputs.filter(function (input) {
          return input.checked;
        });
        checked.slice(1).forEach(function (input) {
          input.checked = false;
        });
        if (checked.length === 0 && correctInputs.length > 0) {
          correctInputs[0].checked = true;
        }
      }
    }

    function reindex() {
      var questions = Array.prototype.slice.call(
        questionsContainer.querySelectorAll('[data-oj-quiz-question]')
      );
      questions.forEach(function (question, questionIndex) {
        updateQuestionType(question);
        var number = question.querySelector('[data-oj-quiz-question-number]');
        if (number) {
          number.textContent = String(questionIndex + 1);
        }
        question.querySelectorAll('[data-oj-quiz-question-field]').forEach(function (field) {
          var key = field.getAttribute('data-oj-quiz-question-field');
          field.name = 'questions[' + questionIndex + '][' + key + ']';
        });
        var type = questionType(question);
        var isMulti = type === 'multi_choice';
        var choices = Array.prototype.slice.call(
          question.querySelectorAll('[data-oj-quiz-choice]')
        );
        choices.forEach(function (choice, choiceIndex) {
          var keyField = choice.querySelector(
            '[data-oj-quiz-choice-field="choice_key"]'
          );
          if (keyField && !keyField.value) {
            keyField.value = uuid();
          }
          choice.querySelectorAll('[data-oj-quiz-choice-field]').forEach(function (field) {
            var key = field.getAttribute('data-oj-quiz-choice-field');
            field.name = 'questions[' + questionIndex + '][choices]['
              + choiceIndex + '][' + key + ']';
          });
          var correct = choice.querySelector('[data-oj-quiz-correct]');
          if (correct) {
            correct.name = isMulti
              ? 'questions[' + questionIndex + '][correct_choices][]'
              : 'questions[' + questionIndex + '][correct_choice]';
            correct.value = keyField ? keyField.value : '';
          }
          var removeChoice = choice.querySelector('[data-oj-quiz-remove-choice]');
          if (removeChoice) {
            removeChoice.disabled = choices.length <= 2;
          }
        });
        var removeQuestion = question.querySelector('[data-oj-quiz-remove-question]');
        if (removeQuestion) {
          removeQuestion.disabled = questions.length <= 1;
        }
        var addChoice = question.querySelector('[data-oj-quiz-add-choice]');
        if (addChoice) {
          addChoice.disabled = choices.length >= 8;
        }
      });
      addQuestion.disabled = questions.length >= 50;
    }

    function updateResultPolicy() {
      if (!resultPolicy || !resultsAt) {
        return;
      }
      var scheduled = resultPolicy.value === 'after_end';
      resultsAt.disabled = !scheduled;
      resultsAt.required = scheduled;
    }

    function updateReapplyOptions() {
      if (!protection || !reapplyOptions) {
        return;
      }
      var protectedRevision = protection.checked;
      reapplyOptions.hidden = protectedRevision;
      reapplyOptions.querySelectorAll('[data-oj-quiz-reapply-scope]').forEach(
        function (scope) {
          var permissionDisabled = scope.hasAttribute(
            'data-oj-quiz-permission-disabled'
          );
          scope.disabled = protectedRevision || permissionDisabled;
        }
      );
    }

    root.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      var addChoice = target.closest('[data-oj-quiz-add-choice]');
      if (addChoice) {
        var question = addChoice.closest('[data-oj-quiz-question]');
        var choiceContainer = question
          ? question.querySelector('[data-oj-quiz-choices]')
          : null;
        var choices = choiceContainer
          ? Array.prototype.slice.call(
              choiceContainer.querySelectorAll('[data-oj-quiz-choice]')
            )
          : [];
        if (!choiceContainer || choices.length < 1 || choices.length >= 8) {
          return;
        }
        var clone = choices[choices.length - 1].cloneNode(true);
        resetChoice(clone, choices.length);
        choiceContainer.appendChild(clone);
        reindex();
        return;
      }

      var removeChoice = target.closest('[data-oj-quiz-remove-choice]');
      if (removeChoice) {
        var selectedChoice = removeChoice.closest('[data-oj-quiz-choice]');
        var selectedContainer = selectedChoice ? selectedChoice.parentElement : null;
        var siblingChoices = selectedContainer
          ? selectedContainer.querySelectorAll('[data-oj-quiz-choice]')
          : [];
        if (selectedChoice && siblingChoices.length > 2) {
          var wasCorrect = Boolean(
            selectedChoice.querySelector('[data-oj-quiz-correct]:checked')
          );
          selectedChoice.remove();
          if (wasCorrect && selectedContainer) {
            var first = selectedContainer.querySelector('[data-oj-quiz-correct]');
            if (first) {
              first.checked = true;
            }
          }
          reindex();
        }
        return;
      }

      var removeQuestion = target.closest('[data-oj-quiz-remove-question]');
      if (removeQuestion) {
        var selectedQuestion = removeQuestion.closest('[data-oj-quiz-question]');
        if (
          selectedQuestion
          && questionsContainer.querySelectorAll('[data-oj-quiz-question]').length > 1
        ) {
          selectedQuestion.remove();
          reindex();
        }
      }
    });

    addQuestion.addEventListener('click', function () {
      var questions = questionsContainer.querySelectorAll('[data-oj-quiz-question]');
      if (questions.length < 1 || questions.length >= 50) {
        return;
      }
      var clone = questions[questions.length - 1].cloneNode(true);
      resetQuestion(clone);
      questionsContainer.appendChild(clone);
      reindex();
    });

    questionsContainer.addEventListener('change', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      if (target.matches('[data-oj-quiz-question-type]')) {
        var question = target.closest('[data-oj-quiz-question]');
        if (question) {
          updateQuestionType(question);
          reindex();
        }
      }
    });

    if (resultPolicy) {
      resultPolicy.addEventListener('change', updateResultPolicy);
    }
    if (protection) {
      protection.addEventListener('change', updateReapplyOptions);
    }
    root.addEventListener('submit', reindex);
    reindex();
    updateResultPolicy();
    updateReapplyOptions();
  }

  function initializeProblemBookFilter(input) {
    var form = input.closest('form');
    var picker = form ? form.querySelector('[data-oj-problem-book-picker]') : null;
    if (!picker) {
      return;
    }

    input.addEventListener('input', function () {
      var keyword = input.value.trim().toLocaleLowerCase();
      picker.querySelectorAll('[data-oj-problem-book-problem]').forEach(function (item) {
        var searchable = (
          item.getAttribute('data-oj-problem-book-problem') || ''
        ).toLocaleLowerCase();
        item.hidden = keyword !== '' && searchable.indexOf(keyword) === -1;
      });
    });
  }

  var featureScriptPromises = {};

  function loadFeatureScript(url) {
    if (featureScriptPromises[url]) {
      return featureScriptPromises[url];
    }

    featureScriptPromises[url] = new Promise(function (resolve, reject) {
      var existing = Array.prototype.find.call(
        document.querySelectorAll('script[data-oj-feature-src]'),
        function (script) {
          return script.getAttribute('data-oj-feature-src') === url;
        }
      );
      if (existing) {
        if (existing.getAttribute('data-loaded') === 'true') {
          resolve();
        } else {
          existing.addEventListener('load', resolve, { once: true });
          existing.addEventListener('error', reject, { once: true });
        }
        return;
      }

      var script = document.createElement('script');
      script.src = url;
      script.defer = true;
      script.setAttribute('data-oj-feature-src', url);
      script.addEventListener('load', function () {
        script.setAttribute('data-loaded', 'true');
        resolve();
      }, { once: true });
      script.addEventListener('error', reject, { once: true });
      document.head.appendChild(script);
    });

    return featureScriptPromises[url];
  }

  function loadFeatureScripts(urls) {
    return urls.reduce(function (promise, url) {
      return promise.then(function () {
        return loadFeatureScript(url);
      });
    }, Promise.resolve());
  }

  function showSubmissionClipboardNotice(form, paste) {
    var notice = form.querySelector('[data-oj-clipboard-notice]');
    if (!notice) {
      notice = document.createElement('p');
      notice.className = 'ojp-field-help';
      notice.setAttribute('role', 'status');
      notice.setAttribute('data-oj-clipboard-notice', '');
      form.appendChild(notice);
    }
    notice.textContent = paste ? '이 제출 화면에서는 코드 붙여넣기가 제한되어 있습니다.' : '이 제출 화면에서는 코드 복사·잘라내기가 제한되어 있습니다.';
  }

  function initializeSubmissionClipboard(form) {
    if (form.hasAttribute('data-oj-clipboard-ready')) { return; }
    form.setAttribute('data-oj-clipboard-ready', '');
    var allowCopy = form.getAttribute('data-oj-code-copy') !== '0';
    var allowPaste = form.getAttribute('data-oj-code-paste') !== '0';
    function isCodeTarget(target) {
      return target && target.closest && target.closest('[data-oj-source-editor], [data-oj-ace-editor], [data-oj-completion-editor], [data-oj-blockly-code]');
    }
    function stop(event) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
    ['copy', 'cut', 'paste', 'drop', 'dragstart', 'beforeinput', 'keydown'].forEach(function (type) {
      form.addEventListener(type, function (event) {
        if (!isCodeTarget(event.target)) { return; }
        var key = String(event.key || '').toLowerCase();
        var copying = type === 'copy' || type === 'cut' || type === 'dragstart';
        var pasting = type === 'paste' || type === 'drop'
          || (type === 'beforeinput' && /^insertFrom(Paste|Drop)/.test(event.inputType || ''));
        if (type === 'keydown') {
          copying = ((event.ctrlKey || event.metaKey) && (key === 'c' || key === 'x'))
            || (event.ctrlKey && key === 'insert') || (event.shiftKey && key === 'delete');
          pasting = ((event.ctrlKey || event.metaKey) && key === 'v') || (event.shiftKey && key === 'insert');
        }
        if ((!allowCopy && copying) || (!allowPaste && pasting)) {
          stop(event);
          showSubmissionClipboardNotice(form, pasting);
        }
      }, true);
    });
  }

  function guardSubmissionClipboardCommands(form, editor) {
    editor.commands.on('exec', function (event) {
      var name = event.command && event.command.name;
      if ((form.getAttribute('data-oj-code-copy') === '0' && (name === 'copy' || name === 'cut'))
          || (form.getAttribute('data-oj-code-paste') === '0' && name === 'paste')) {
        event.preventDefault();
        event.stopPropagation();
        showSubmissionClipboardNotice(form, name === 'paste');
      }
    });
  }

  function initializeSubmissionMode(form) {
    var modes = Array.prototype.slice.call(
      form.querySelectorAll('input[name="submission_mode"]')
    );
    var panels = Array.prototype.slice.call(
      form.querySelectorAll('[data-oj-mode-panel]')
    );
    var language = form.querySelector('[data-oj-language-select]');
    var source = form.querySelector('[data-oj-source-editor]');
    var aceRoot = form.querySelector('[data-oj-ace-editor]');
    var problemInput = form.querySelector('input[name="problem_id"]');
    var previousLanguage = language ? language.value : '';
    var blocklyLoading = null;
    var aceLoading = null;
    var aceEditor = null;
    var syncingSource = false;
    var problemId = problemInput ? problemInput.value : 'unknown';
    var handoffKey = form.getAttribute('data-oj-handoff-storage');
    if (!/^oj_submission_handoff_[a-f0-9]{64}$/.test(handoffKey || '')) { handoffKey = null; }
    var sourceKey = handoffKey || 'oj_submission_source_' + problemId;

    function scheduleDraftSave() {
      if (!source) {
        return;
      }
      if (handoffKey) {
        try {
          window.sessionStorage.setItem(sourceKey, JSON.stringify({
            source: source.value,
            language_id: language ? language.value : ''
          }));
        } catch (error) { /* Optional tab-local recovery. */ }
        return;
      }
      window.clearTimeout(scheduleDraftSave.timer);
      scheduleDraftSave.timer = window.setTimeout(function () {
        try {
          window.sessionStorage.setItem(sourceKey, source.value);
        } catch (error) {
        }
      }, 1000);
    }

    if (source) {
      try {
        var draft = window.sessionStorage.getItem(sourceKey);
        if (draft !== null && handoffKey && language) {
          var restored = JSON.parse(draft);
          var allowedLanguage = restored && typeof restored.language_id === 'string'
            && Array.prototype.some.call(language.options, function (option) {
              return option.value === restored.language_id && !option.disabled;
            });
          if (allowedLanguage && typeof restored.source === 'string') {
            source.value = restored.source;
            language.value = restored.language_id;
            previousLanguage = language.value;
          }
        } else if (draft !== null && !handoffKey && source.value.trim() === '') {
          source.value = draft;
        }
      } catch (error) {
      }
      source.addEventListener('input', function () {
        if (
          aceEditor
          && !syncingSource
          && aceEditor.getValue() !== source.value
        ) {
          aceEditor.setValue(source.value, -1);
        }
        scheduleDraftSave();
      });
      source.addEventListener('oj-source-focus', function () {
        loadAce().then(function () {
          if (aceEditor) {
            aceEditor.focus();
          } else {
            source.focus();
          }
        });
      });
    }

    function selectedAceMode() {
      if (!language || language.selectedIndex < 0) {
        return 'text';
      }
      var option = language.options[language.selectedIndex];

      return option.getAttribute('data-oj-ace-mode') || 'text';
    }

    function updateAceMode() {
      if (!aceEditor) {
        return;
      }
      aceEditor.session.setMode('ace/mode/' + selectedAceMode());
    }

    function syncAceFromSource() {
      if (
        aceEditor
        && source
        && aceEditor.getValue() !== source.value
      ) {
        aceEditor.setValue(source.value, -1);
        aceEditor.clearSelection();
      }
    }

    function loadAce() {
      if (!aceRoot || !source) {
        return Promise.resolve();
      }
      if (aceEditor) {
        syncAceFromSource();
        updateAceMode();
        aceEditor.resize();
        return Promise.resolve();
      }
      if (aceLoading) {
        return aceLoading;
      }

      var assets;
      try {
        assets = JSON.parse(aceRoot.getAttribute('data-assets') || '{}');
      } catch (error) {
        assets = {};
      }
      var surface = aceRoot.querySelector('[data-oj-ace-surface]');
      var status = aceRoot.querySelector('[data-oj-ace-status]');
      if (!assets.core || !assets.base || !surface) {
        if (status) {
          status.hidden = false;
          status.textContent = 'Ace 편집기를 불러오지 못해 기본 입력창으로 전환했습니다.';
        }
        return Promise.resolve();
      }

      aceRoot.setAttribute('aria-busy', 'true');
      aceLoading = loadFeatureScript(assets.core).then(function () {
        if (!window.ace || typeof window.ace.edit !== 'function') {
          throw new Error('Ace 편집기 모듈을 찾을 수 없습니다.');
        }
        window.ace.config.set('basePath', assets.base);
        window.ace.config.set('modePath', assets.base);
        window.ace.config.set('themePath', assets.base);

        aceEditor = window.ace.edit(surface);
        guardSubmissionClipboardCommands(form, aceEditor);
        aceEditor.$blockScrolling = Infinity;
        aceEditor.setTheme(
          'ace/theme/' + (aceRoot.getAttribute('data-oj-ace-theme') || 'chrome')
        );
        aceEditor.setOptions({
          fontSize: (aceRoot.getAttribute('data-oj-ace-font-size') || '16') + 'px',
          showPrintMargin: false,
          displayIndentGuides: true,
          highlightActiveLine: true,
          wrap: false,
          useSoftTabs: true,
          tabSize: 4
        });
        aceEditor.session.setUseWorker(false);
        aceEditor.session.setMode('ace/mode/' + selectedAceMode());
        aceEditor.setValue(source.value, -1);
        aceEditor.clearSelection();
        aceEditor.session.on('change', function () {
          syncingSource = true;
          source.value = aceEditor.getValue();
          source.dispatchEvent(new Event('input', { bubbles: true }));
          syncingSource = false;
        });
        var textInput = surface.querySelector('.ace_text-input');
        if (textInput) {
          textInput.setAttribute('aria-label', '소스 코드');
        }
        aceRoot.classList.add('is-mounted');
        aceRoot.setAttribute('data-mounted', 'true');
        aceRoot.removeAttribute('aria-busy');
        if (status) {
          status.hidden = true;
        }
        aceEditor.resize();
      }).catch(function () {
        aceRoot.removeAttribute('aria-busy');
        if (status) {
          status.hidden = false;
          status.textContent = 'Ace 편집기를 불러오지 못해 기본 입력창으로 전환했습니다.';
          status.classList.add('is-error');
        }
      });

      return aceLoading;
    }

    function loadBlockly(panel) {
      var root = panel.querySelector('[data-oj-blockly-editor]');
      if (!root || root.getAttribute('data-mounted') === 'true') {
        return Promise.resolve();
      }
      if (blocklyLoading) {
        return blocklyLoading;
      }
      var assets;
      try {
        assets = JSON.parse(root.getAttribute('data-assets') || '{}');
      } catch (error) {
        assets = {};
      }
      var urls = [
        assets.core,
        assets.blocks,
        assets.messages,
        assets.python,
        assets.editor
      ].filter(Boolean);
      root.setAttribute('aria-busy', 'true');
      blocklyLoading = loadFeatureScripts(urls).then(function () {
        if (
          !window.OJPlatformBlockly
          || typeof window.OJPlatformBlockly.mount !== 'function'
        ) {
          throw new Error('Blockly 편집기 모듈을 찾을 수 없습니다.');
        }
        window.OJPlatformBlockly.mount(root);
        root.setAttribute('data-mounted', 'true');
        root.removeAttribute('aria-busy');
      }).catch(function () {
        root.removeAttribute('aria-busy');
        var status = root.querySelector('[data-oj-blockly-status]');
        if (status) {
          status.textContent = 'Blockly 편집기를 불러오지 못했습니다.';
          status.classList.add('is-error');
        }
      });

      return blocklyLoading;
    }

    function activate(mode) {
      panels.forEach(function (panel) {
        var active = panel.getAttribute('data-oj-mode-panel') === mode;
        panel.hidden = !active;
        panel.querySelectorAll('input, select, textarea, button').forEach(function (control) {
          if (control.hasAttribute('data-oj-keep-enabled')) {
            return;
          }
          control.disabled = !active;
        });
        if (active && mode === 'blockly') {
          loadBlockly(panel);
        }
      });
      if (language) {
        if (mode === 'blockly') {
          previousLanguage = language.value;
          var pythonOption = language.querySelector('option[value="6"]');
          if (!pythonOption) {
            pythonOption = document.createElement('option');
            pythonOption.value = '6';
            pythonOption.textContent = 'Python 3';
            pythonOption.setAttribute('data-oj-blockly-language', '');
            pythonOption.setAttribute('data-oj-ace-mode', 'python');
            language.appendChild(pythonOption);
          }
          language.value = '6';
          language.required = false;
        } else {
          var temporary = language.querySelector('[data-oj-blockly-language]');
          if (temporary) {
            temporary.remove();
          }
          if (previousLanguage !== '') {
            language.value = previousLanguage;
          }
          language.required = true;
        }
      }
      if (mode === 'code') {
        loadAce().then(updateAceMode);
      }
    }

    if (language) {
      language.addEventListener('change', updateAceMode);
      if (handoffKey) {
        language.addEventListener('change', scheduleDraftSave);
      }
    }
    form.addEventListener('submit', function () {
      if (aceEditor && source) {
        source.value = aceEditor.getValue();
      }
    });
    window.addEventListener('resize', function () {
      if (aceEditor) {
        aceEditor.resize();
      }
    });
    modes.forEach(function (mode) {
      mode.addEventListener('change', function () {
        if (mode.checked) {
          activate(mode.value);
        }
      });
    });
    var selected = modes.find(function (mode) {
      return mode.checked;
    });
    if (selected) {
      activate(selected.value);
    }
  }

  function initializeThemePreview(root) {
    var themeSelect = root.querySelector('[data-ojp-theme-select]');
    var aceRoot = root.querySelector('[data-oj-ace-editor]');
    if (!themeSelect || !aceRoot) {
      return;
    }

    var surface = aceRoot.querySelector('[data-oj-ace-surface]');
    var source = aceRoot.querySelector('[data-oj-source-editor]');
    var status = aceRoot.querySelector('[data-oj-ace-status]');
    var aceEditor = null;
    var assets;
    try {
      assets = JSON.parse(aceRoot.getAttribute('data-assets') || '{}');
    } catch (error) {
      assets = {};
    }

    if (!assets.core || !assets.base || !surface || !source) {
      return;
    }

    aceRoot.setAttribute('aria-busy', 'true');
    loadFeatureScript(assets.core).then(function () {
      if (!window.ace || typeof window.ace.edit !== 'function') {
        throw new Error('Ace 편집기 모듈을 찾을 수 없습니다.');
      }
      window.ace.config.set('basePath', assets.base);
      window.ace.config.set('modePath', assets.base);
      window.ace.config.set('themePath', assets.base);

      aceEditor = window.ace.edit(surface);
      aceEditor.$blockScrolling = Infinity;
      aceEditor.setTheme(
        'ace/theme/' + (aceRoot.getAttribute('data-oj-ace-theme') || 'chrome')
      );
      aceEditor.setOptions({
        fontSize: (aceRoot.getAttribute('data-oj-ace-font-size') || '16') + 'px',
        showPrintMargin: false,
        displayIndentGuides: true,
        highlightActiveLine: true,
        wrap: false,
        useSoftTabs: true,
        tabSize: 4
      });
      aceEditor.session.setUseWorker(false);
      aceEditor.setValue(source.value, -1);
      aceEditor.clearSelection();
      aceEditor.session.on('change', function () {
        source.value = aceEditor.getValue();
        source.dispatchEvent(new Event('input', { bubbles: true }));
      });
      aceRoot.classList.add('is-mounted');
      aceRoot.setAttribute('data-mounted', 'true');
      aceRoot.removeAttribute('aria-busy');
      if (status) {
        status.hidden = true;
      }
      aceEditor.resize();
    }).catch(function () {
      aceRoot.removeAttribute('aria-busy');
      if (status) {
        status.hidden = false;
        status.textContent = 'Ace 편집기를 불러오지 못해 기본 입력창으로 전환했습니다.';
      }
    });

    themeSelect.addEventListener('change', function () {
      aceRoot.setAttribute('data-oj-ace-theme', themeSelect.value);
      if (aceEditor) {
        aceEditor.setTheme('ace/theme/' + themeSelect.value);
      }
    });
  }

  function initializeSubmissionBulk(root) {
    var checkboxes = Array.prototype.slice.call(
      document.querySelectorAll(
        '.ojp-table-check input[name="submission_ids[]"]'
      )
    );
    root.querySelectorAll('[data-oj-bulk-operation]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        form.querySelectorAll('[data-oj-bulk-value]').forEach(function (item) {
          item.remove();
        });
        var selected = checkboxes.filter(function (item) {
          return item.checked;
        });
        if (selected.length === 0) {
          event.preventDefault();
          var reason = form.querySelector('input[name="reason"]');
          if (reason) {
            reason.setCustomValidity('처리할 제출을 먼저 선택해 주세요.');
            reason.reportValidity();
            window.setTimeout(function () {
              reason.setCustomValidity('');
            }, 1000);
          }
          return;
        }
        selected.forEach(function (item) {
          var copy = document.createElement('input');
          copy.type = 'hidden';
          copy.name = 'submission_ids[]';
          copy.value = item.value;
          copy.setAttribute('data-oj-bulk-value', '');
          form.appendChild(copy);
        });
      });
    });
  }

  function initializeSubmissionPoll(form) {
    var endpoint = form.getAttribute('data-endpoint');
    var maximumSeconds = Number(form.getAttribute('data-max-seconds')) || 180;
    var copy = form.querySelector('[data-oj-poll-copy]');
    var badge = document.querySelector('[data-oj-result-badge] .ojp-badge');
    var intervals = [2000, 3000, 5000, 8000, 10000];
    var started = Date.now();
    var attempts = 0;
    var stopped = false;
    var timer = null;

    function stop(message) {
      stopped = true;
      window.clearTimeout(timer);
      if (copy && message) {
        copy.textContent = message;
      }
    }

    function schedule() {
      if (stopped) {
        return;
      }
      if ((Date.now() - started) / 1000 >= maximumSeconds) {
        stop('자동 갱신을 마쳤습니다. 필요하면 페이지를 새로 고쳐 주세요.');
        return;
      }
      var delay = intervals[Math.min(attempts, intervals.length - 1)];
      if (copy) {
        copy.textContent = Math.round(delay / 1000)
          + '초 뒤 결과를 다시 확인합니다.';
      }
      timer = window.setTimeout(poll, delay);
    }

    function poll() {
      timer = null;
      if (document.hidden) {
        schedule();
        return;
      }
      attempts += 1;
      window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      }).then(function (response) {
        return response.json().then(function (payload) {
          return { response: response, payload: payload };
        });
      }).then(function (result) {
        var payload = result.payload;
        if (!result.response.ok || !payload.ok) {
          stop(payload.message || '자동 갱신을 중단했습니다.');
          return;
        }
        if (badge) {
          badge.textContent = payload.result_label;
          badge.className = payload.result_class
            ? String(payload.result_class)
            : 'ojp-badge ojp-badge--' + String(payload.result_tone || 'neutral');
        }
        var time = document.querySelector('[data-oj-result-time]');
        var memory = document.querySelector('[data-oj-result-memory]');
        if (time) {
          time.textContent = Number(payload.time_ms).toLocaleString() + 'ms';
        }
        if (memory) {
          memory.textContent = Number(payload.memory_kb).toLocaleString() + 'KB';
        }
        if (!payload.is_processing) {
          // Compile messages, judge reports and AI feedback are rendered by the page itself, so
          // reload once the result is final instead of leaving the details out until a manual refresh.
          stop('채점이 완료되었습니다. 결과 상세를 불러옵니다.');
          window.setTimeout(function () {
            window.location.reload();
          }, 600);
          return;
        }
        schedule();
      }).catch(function () {
        schedule();
      });
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && !stopped && !timer) {
        schedule();
      }
    });
    schedule();
  }

  var codeOrderingLoader = null;
  function initializeCodeOrderingAuthoring(root) {
    var form = root.closest('form');
    if (!form) { return; }
    var modes = Array.prototype.slice.call(form.querySelectorAll('input[name="code_entry_mode"]'));
    var orderingLanguage = root.querySelector('[name="ordering_language_key"]');
    var allowedLanguages = Array.prototype.slice.call(form.querySelectorAll('input[name="enabled_language_ids[]"]'));
    var languageIds = { python: '6', c: '0', cpp: '1', java: '3' };
    var previousLanguages = [];
    var wasActive = false;
    function update() {
      var selected = modes.find(function (mode) { return mode.checked; });
      var active = Boolean(selected) && selected.value === 'code_ordering';
      if (active && !wasActive) {
        previousLanguages = allowedLanguages.map(function (control) {
          return { control: control, checked: control.checked, disabled: control.disabled };
        });
      }
      if (active) {
        var languageId = orderingLanguage ? languageIds[orderingLanguage.value] : null;
        allowedLanguages.forEach(function (control) {
          control.checked = control.value === languageId;
          control.disabled = true;
        });
      } else if (wasActive) {
        previousLanguages.forEach(function (saved) {
          saved.control.checked = saved.checked;
          saved.control.disabled = saved.disabled;
        });
      }
      root.hidden = !active;
      root.querySelectorAll('input, textarea, select, button').forEach(function (control) {
        control.disabled = !active;
        if (control.name === 'ordering_source') { control.required = active; }
      });
      wasActive = active;
    }
    modes.forEach(function (mode) { mode.addEventListener('change', update); });
    if (orderingLanguage) { orderingLanguage.addEventListener('change', update); }
    update();
  }

  function initializeCodeOrdering(root) {
    if (!codeOrderingLoader) {
      var base = uiScript || document.querySelector('script[src*="/ui/js/oj-ui.js"]');
      if (!base || !base.src) { return; }
      var url = new URL('../../features/code-ordering/js/editor.js', base.src);
      url.search = new URL(base.src).search;
      codeOrderingLoader = loadFeatureScript(url.href).then(function () {
        if (!window.OJPlatformCodeOrdering) { throw new Error('코드 배열 도구를 찾을 수 없습니다.'); }
        return window.OJPlatformCodeOrdering;
      });
    }
    codeOrderingLoader.then(function (editor) { editor.mount(root); }).catch(function (error) {
      var status = root.querySelector('[data-oj-ordering-status]');
      if (status) { status.textContent = '코드 배열 도구를 불러오지 못했습니다. 페이지를 새로고침해 주세요.'; }
      reportInitError('code-ordering', error);
    });
  }

  function initializeSubmissionCompletion(root) {
    var form = root.closest('form');
    var problemInput = form ? form.querySelector('input[name="problem_id"]') : null;
    var key = 'oj_submission_completion_'
      + (problemInput ? problemInput.value : 'unknown');
    var fields = Array.prototype.slice.call(root.querySelectorAll('input, textarea'));
    var timer = null;
    try {
      var saved = JSON.parse(window.sessionStorage.getItem(key) || '{}');
      fields.forEach(function (field) {
        if (Object.prototype.hasOwnProperty.call(saved, field.name)) {
          field.value = saved[field.name];
          field.closest('.ojp-completion-blank').classList.toggle(
            'is-complete',
            field.value.trim() !== ''
          );
        }
      });
    } catch (error) {
    }
    fields.forEach(function (field) {
      field.addEventListener('input', function () {
        var blank = field.closest('.ojp-completion-blank');
        if (blank) {
          blank.classList.toggle('is-complete', field.value.trim() !== '');
        }
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
          var values = {};
          fields.forEach(function (item) {
            values[item.name] = item.value;
          });
          try {
            window.sessionStorage.setItem(key, JSON.stringify(values));
          } catch (error) {
          }
        }, 1000);
      });
    });
  }

  function initializeAiFeedback(form) {
    var endpoint = form.getAttribute('action');
    var operation = form.querySelector('input[name="operation"]');
    var jobId = form.querySelector('input[name="job_id"]');
    var button = form.querySelector('[data-oj-ai-feedback-submit]');
    var cancelButton = form.querySelector('[data-oj-ai-feedback-cancel]');
    var status = form.querySelector('[data-oj-ai-feedback-status]');
    var panel = form.closest('[data-oj-ai-feedback-panel]');
    var badge = panel
      ? panel.querySelector('[data-oj-ai-feedback-badge]')
      : null;
    var intervals = [2000, 3000, 5000, 8000];
    var attempts = 0;
    var started = 0;
    var timer = null;
    var initialLabel = button ? button.textContent : '피드백 생성';

    if (!endpoint || !operation || !jobId || !button) {
      return;
    }

    function setStatus(message, tone) {
      if (status) {
        status.textContent = message;
      }
      if (badge) {
        badge.textContent = tone === 'success'
          ? '생성 완료'
          : (tone === 'warning'
            ? '확인 필요'
            : (tone === 'neutral' ? '취소됨' : '생성 중'));
        badge.className = tone === 'neutral'
          ? 'ojp-badge'
          : 'ojp-badge ojp-badge--' + tone;
      }
    }

    function finish(message, tone, canRetry) {
      window.clearTimeout(timer);
      operation.value = canRetry ? 'retry' : 'generate';
      button.disabled = false;
      button.textContent = canRetry
        ? '다시 시도'
        : (tone === 'success' ? '피드백 다시 생성' : initialLabel);
      if (cancelButton) {
        cancelButton.hidden = true;
        cancelButton.disabled = false;
      }
      setStatus(message, tone);
    }

    function appendFeedbackBlock(root, title, body) {
      var block = document.createElement('div');
      var heading = document.createElement('h3');
      var paragraph = document.createElement('p');
      block.className = 'ojp-feedback-block';
      heading.textContent = title;
      paragraph.textContent = body;
      block.appendChild(heading);
      block.appendChild(paragraph);
      root.appendChild(block);
    }

    function renderFeedback(feedback) {
      var content = panel
        ? panel.querySelector('[data-oj-ai-feedback-content]')
        : null;
      if (!content || !feedback) {
        return;
      }
      content.replaceChildren();
      content.className = 'ojp-feedback-content';
      content.setAttribute('data-oj-ai-feedback-content', '');
      appendFeedbackBlock(
        content,
        '결과 이해',
        String(feedback.result_understanding || '')
      );
      var hints = document.createElement('div');
      var heading = document.createElement('h3');
      var list = document.createElement('ol');
      hints.className = 'ojp-feedback-block';
      heading.textContent = '단계별 힌트';
      hints.appendChild(heading);
      (Array.isArray(feedback.step_hints) ? feedback.step_hints : [])
        .forEach(function (hint) {
          var item = document.createElement('li');
          item.textContent = String(hint);
          list.appendChild(item);
        });
      hints.appendChild(list);
      content.appendChild(hints);
      appendFeedbackBlock(
        content,
        '다시 확인할 점',
        String(feedback.review_question || '')
      );
    }

    function request() {
      if (Date.now() - started >= 300000) {
        finish(
          '자동 확인을 마쳤습니다. 잠시 뒤 상태를 다시 확인해 주세요.',
          'warning',
          false
        );
        return;
      }
      window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      }).then(function (response) {
        return response.json().then(function (payload) {
          return { response: response, payload: payload };
        });
      }).then(function (result) {
        var payload = result.payload;
        if (!result.response.ok || !payload.ok) {
          finish(
            payload.message || 'AI 피드백을 만들지 못했습니다.',
            'warning',
            false
          );
          return;
        }
        if (payload.job_id) {
          jobId.value = String(payload.job_id);
          operation.value = 'status';
        }
        if (payload.terminal) {
          if (payload.feedback) {
            renderFeedback(payload.feedback);
            finish(
              payload.message || 'AI 피드백을 만들었습니다.',
              'success',
              false
            );
            return;
          }
          finish(
            payload.message || 'AI 피드백을 만들지 못했습니다.',
            payload.status === 'cancelled' ? 'neutral' : 'warning',
            Boolean(payload.can_retry)
          );
          return;
        }
        button.disabled = true;
        if (cancelButton) {
          cancelButton.hidden = !payload.can_cancel;
          cancelButton.disabled = false;
        }
        setStatus(payload.message || 'AI 피드백을 생성하고 있습니다.', 'info');
        var delay = intervals[Math.min(attempts, intervals.length - 1)];
        attempts += 1;
        timer = window.setTimeout(request, delay);
      }).catch(function () {
        window.clearTimeout(timer);
        operation.value = jobId.value ? 'status' : 'generate';
        button.disabled = false;
        button.textContent = jobId.value ? '상태 다시 확인' : '다시 요청';
        if (cancelButton) {
          cancelButton.hidden = true;
        }
        setStatus('네트워크 연결을 확인한 뒤 상태를 다시 확인해 주세요.', 'warning');
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      attempts = 0;
      started = Date.now();
      button.disabled = true;
      if (cancelButton) {
        cancelButton.hidden = true;
      }
      setStatus(
        operation.value === 'retry'
          ? 'AI 피드백 작업을 다시 요청하고 있습니다.'
          : 'AI 피드백 작업을 요청하고 있습니다.',
        'info'
      );
      request();
    });

    if (cancelButton) {
      cancelButton.addEventListener('click', function () {
        if (!jobId.value) {
          return;
        }
        window.clearTimeout(timer);
        operation.value = 'cancel';
        button.disabled = true;
        cancelButton.disabled = true;
        setStatus('AI 피드백 작업 취소를 요청하고 있습니다.', 'info');
        request();
      });
    }
  }
  function clearCompletedSubmissionDrafts(root) {
    var problemId = root.getAttribute('data-problem-id');
    if (!problemId) {
      return;
    }
    try {
      window.sessionStorage.removeItem('oj_submission_source_' + problemId);
      window.sessionStorage.removeItem('oj_submission_completion_' + problemId);
      window.sessionStorage.removeItem('oj_blockly_draft_' + problemId);
    } catch (error) {
    }
  }

  function initializeAiProviderForm(select) {
    var form = select.closest('[data-ojp-provider-form]');
    var layout = form ? form.closest('.ojp-two-column') : null;
    var baseUrl = form
      ? form.querySelector('[data-ojp-provider-base-url]')
      : null;
    var model = form
      ? form.querySelector('[data-ojp-provider-model]')
      : null;
    var authentication = form
      ? form.querySelector('[data-ojp-provider-authentication]')
      : null;
    var examples = layout
      ? Array.prototype.slice.call(
        layout.querySelectorAll('[data-ojp-provider-example]')
      )
      : [];

    if (!form) {
      return;
    }

    function authenticationLabel(method) {
      return method === 'x_api_key' ? 'X-API-Key' : 'Bearer';
    }

    function activate(resetValues) {
      var option = select.options[select.selectedIndex];
      if (!option) {
        return;
      }
      var providerKey = option.value;
      var methods = (option.getAttribute('data-authentication-methods') || '')
        .split(',')
        .filter(Boolean);
      var currentAuthentication = authentication
        ? authentication.value
        : '';

      examples.forEach(function (example) {
        example.hidden = example.getAttribute('data-ojp-provider-example')
          !== providerKey;
      });
      if (baseUrl && (resetValues || baseUrl.value.trim() === '')) {
        baseUrl.value = option.getAttribute('data-default-base-url') || '';
      }
      if (model && (resetValues || model.value.trim() === '')) {
        model.value = option.getAttribute('data-default-model') || '';
      }
      if (authentication && methods.length > 0) {
        authentication.replaceChildren();
        methods.forEach(function (method) {
          var methodOption = document.createElement('option');
          methodOption.value = method;
          methodOption.textContent = authenticationLabel(method);
          authentication.appendChild(methodOption);
        });
        authentication.value = methods.indexOf(currentAuthentication) >= 0
          ? currentAuthentication
          : methods[0];
      }
    }

    select.addEventListener('change', function () {
      activate(true);
      form.querySelector('[name="secret"]').value = '';
    });
    form.addEventListener('input', function (event) {
      if (['profile_key', 'base_url', 'model', 'secret'].indexOf(event.target.name) >= 0) {
        form.querySelector('[name="allow_submission_source"]').checked = false;
      }
    });
    form.addEventListener('change', function (event) {
      if (['provider_key', 'authentication_method'].indexOf(event.target.name) >= 0) {
        form.querySelector('[name="allow_submission_source"]').checked = false;
      }
    });
    activate(false);
  }

  function initializeServerClock(root) {
    var display = root.querySelector('[data-ojp-server-clock-value]');
    var serverTime = Date.parse(root.getAttribute('data-server-time') || '');
    var timezone = root.getAttribute('data-server-timezone') || '';
    var offsetSeconds = Number(root.getAttribute('data-server-offset') || '0');
    var transitionTime = Date.parse(
      root.getAttribute('data-contest-transition') || ''
    );
    var usesPerformance = window.performance
      && typeof window.performance.now === 'function';
    var startedAt = usesPerformance ? window.performance.now() : Date.now();
    var formatter = null;
    var timer = null;

    if (!display || !Number.isFinite(serverTime)) {
      return;
    }
    if (!Number.isFinite(offsetSeconds)) {
      offsetSeconds = 0;
    }

    if (
      timezone !== ''
      && window.Intl
      && typeof window.Intl.DateTimeFormat === 'function'
    ) {
      try {
        formatter = new window.Intl.DateTimeFormat(
          'en-CA-u-ca-gregory-nu-latn',
          {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hourCycle: 'h23'
          }
        );
      } catch (timezoneError) {
        formatter = null;
      }
    }

    function pad(value) {
      return String(value).padStart(2, '0');
    }

    function formatTime(date) {
      var values = {};
      if (formatter && typeof formatter.formatToParts === 'function') {
        formatter.formatToParts(date).forEach(function (part) {
          if (part.type !== 'literal') {
            values[part.type] = part.value;
          }
        });
        if (
          values.year
          && values.month
          && values.day
          && values.hour
          && values.minute
          && values.second
        ) {
          return values.year + '-' + values.month + '-' + values.day + ' '
            + values.hour + ':' + values.minute + ':' + values.second;
        }
      }
      var shifted = new Date(date.getTime() + (offsetSeconds * 1000));
      return shifted.getUTCFullYear() + '-'
        + pad(shifted.getUTCMonth() + 1) + '-'
        + pad(shifted.getUTCDate()) + ' '
        + pad(shifted.getUTCHours()) + ':'
        + pad(shifted.getUTCMinutes()) + ':'
        + pad(shifted.getUTCSeconds());
    }

    function currentTime() {
      var elapsed = (usesPerformance ? window.performance.now() : Date.now())
        - startedAt;
      return serverTime + Math.max(0, elapsed);
    }

    function tick() {
      var current = currentTime();
      var date = new Date(current);
      display.textContent = formatTime(date);
      display.setAttribute('datetime', date.toISOString());

      if (Number.isFinite(transitionTime) && current >= transitionTime) {
        if (timer !== null) {
          window.clearTimeout(timer);
        }
        window.location.reload();
        return;
      }
      timer = window.setTimeout(tick, Math.max(100, 1010 - (current % 1000)));
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        if (timer !== null) {
          window.clearTimeout(timer);
        }
        tick();
      }
    });
    tick();
  }

  function reportInitError(context, error) {
    if (window.console && typeof window.console.error === 'function') {
      window.console.error('oj-ui: ' + context + ' failed to initialize', error);
    }
  }

  function safeEach(selector, fn) {
    document.querySelectorAll(selector).forEach(function (element) {
      try {
        fn(element);
      } catch (error) {
        reportInitError(selector, error);
      }
    });
  }

  function safeCall(name, fn) {
    try {
      fn();
    } catch (error) {
      reportInitError(name, error);
    }
  }

  function initialize() {
    // Each widget is initialized independently (safeEach/safeCall catch and
    // log per element) so one broken widget elsewhere on the page — e.g. a
    // problem statement's rich-text render, or an unrelated dashboard card —
    // can never prevent critical forms (like code submission) from wiring up.
    safeEach('[data-ojp-tabs]', initializeTabs);
    safeEach('[data-ojp-bulk-select]', initializeBulkSelection);
    safeEach('[data-ojp-server-clock]', initializeServerClock);
    safeEach('[data-ojp-rich-text-editor]', initializeRichTextEditor);
    safeEach('[data-ojp-agreement-document-form]', initializeAgreementDocumentForm);
    safeEach('[data-ojp-code-completion]', initializeCodeCompletion);
    safeEach('[data-ojp-code-ordering]', initializeCodeOrderingAuthoring);
    safeEach('[data-ojp-max-selections]', initializeLimitedMultiSelect);
    safeEach('[data-ojp-ai-job-form]', initializeAiJobForm);
    safeEach('[data-ojp-draft-review]', initializeDraftReview);
    safeEach('[data-ojp-job-poll]', initializeJobPoll);
    safeEach('[data-oj-quiz-editor]', initializeQuizEditor);
    safeEach('[data-oj-problem-book-filter]', initializeProblemBookFilter);
    safeEach('[data-oj-submission-form]', initializeSubmissionClipboard);
    safeEach('[data-oj-submission-form]', initializeSubmissionMode);
    safeEach('[data-oj-submission-bulk]', initializeSubmissionBulk);
    safeEach('[data-oj-submission-poll]', initializeSubmissionPoll);
    safeEach('[data-oj-completion-editor]', initializeSubmissionCompletion);
    safeEach('[data-oj-code-ordering]', initializeCodeOrdering);
    safeEach('[data-oj-submission-complete]', clearCompletedSubmissionDrafts);
    safeEach('[data-oj-ai-feedback]', initializeAiFeedback);
    safeEach('[data-ojp-provider-select]', initializeAiProviderForm);
    safeEach('[data-ojp-sortable-table]', initializeSortableTable);
    safeEach('[data-ojp-table-filter]', initializeTableFilter);
    safeEach('[data-ojp-member-names]', initializeMemberNames);
    safeEach('[data-ojp-password-suggest]', initializePasswordSuggest);
    safeEach('input[type="password"]', initializePasswordVisibility);
    safeEach('[data-ojp-theme-preview]', initializeThemePreview);
    safeCall('initializeCopyButtons', initializeCopyButtons);
    document.querySelectorAll('[data-ojp-server-open]').forEach(function (button) {
      var dialog = byId(button.getAttribute('data-ojp-server-open'));
      if (dialog && typeof dialog.showModal === 'function') {
        button.hidden = false;
        button.style.removeProperty('display');
        var fallback = document.querySelector('[data-ojp-server-fallback="' + dialog.id + '"]');
        if (fallback) { fallback.hidden = true; }
      }
    });
    safeCall('initializeConfirmSubmit', initializeConfirmSubmit);
    safeCall('initializeFlashToasts', initializeFlashToasts);
    safeCall('initializeColorScheme', initializeColorScheme);
    safeCall('initializeCommandPalette', initializeCommandPalette);
    safeCall('initializeDialogs', initializeDialogs);
    safeCall('initializeDrawers', initializeDrawers);
    safeCall('initializeSidebarCollapse', initializeSidebarCollapse);
    safeCall('initializeConsoleSubmenus', initializeConsoleSubmenus);
    safeCall('initializeMenus', initializeMenus);
    safeCall('initializeMessageNotifications', initializeMessageNotifications);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
}());
