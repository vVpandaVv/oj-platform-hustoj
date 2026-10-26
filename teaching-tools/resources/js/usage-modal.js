/**
 * Shared "사용법" (usage guide) sheet for every host tool page. Keeps
 * how-to-use text out of the always-visible setup screen (which is often
 * projected) — it only appears when the teacher explicitly asks for it.
 * It reuses the platform sheet look (ojp-sheet) and adds a scrolling body.
 */
window.HttUsageModal = (function () {
    'use strict';

    var overlay = null;
    var titleEl = null;
    var bodyEl = null;
    var closeButton = null;
    var lastFocus = null;

    function ensureBuilt() {
        if (overlay) {
            return;
        }

        overlay = document.createElement('div');
        overlay.className = 'ojp-sheet-scrim htt-usage-scrim';
        overlay.hidden = true;

        var sheet = document.createElement('div');
        sheet.className = 'ojp-sheet htt-usage-sheet';
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');
        sheet.setAttribute('aria-labelledby', 'htt-usage-title');

        titleEl = document.createElement('h2');
        titleEl.id = 'htt-usage-title';

        bodyEl = document.createElement('div');
        bodyEl.className = 'htt-usage-body';

        var actions = document.createElement('div');
        actions.className = 'ojp-sheet__actions';

        closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'ojp-button ojp-button--primary';
        closeButton.textContent = '닫기';
        closeButton.addEventListener('click', hide);
        actions.appendChild(closeButton);

        sheet.append(titleEl, bodyEl, actions);
        overlay.appendChild(sheet);

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                hide();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !overlay.hidden) {
                hide();
            }
        });

        document.body.appendChild(overlay);
    }

    function show(title, html) {
        ensureBuilt();
        lastFocus = document.activeElement;
        titleEl.textContent = title;
        bodyEl.innerHTML = html;
        bodyEl.scrollTop = 0;
        overlay.hidden = false;
        closeButton.focus();
    }

    function hide() {
        if (!overlay || overlay.hidden) {
            return;
        }
        overlay.hidden = true;
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
        lastFocus = null;
    }

    return { show: show, hide: hide };
})();
