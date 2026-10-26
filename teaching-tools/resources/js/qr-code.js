(function () {
    'use strict';

    function render(codeEl) {
        var code = String(codeEl.textContent || '').trim().toUpperCase();
        var root = codeEl.closest('[data-config]');
        if (!root || !window.qrcode || !/^[A-Z0-9]{6}$/.test(code)) {
            return;
        }
        var config;
        try {
            config = JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (error) {
            return;
        }
        if (!config.joinUrl) {
            return;
        }

        var container = codeEl.parentElement.querySelector('[data-htt-join-qr]');
        if (!container) {
            container = document.createElement('div');
            container.className = 'htt-join-qr';
            container.setAttribute('data-htt-join-qr', '');
            codeEl.parentElement.appendChild(container);
        }
        if (container.getAttribute('data-code') === code) {
            return;
        }

        window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];
        var value = config.joinUrl + (config.joinUrl.indexOf('?') === -1 ? '?' : '&') + 'code=' + encodeURIComponent(code);
        var qr = window.qrcode(0, 'M');
        qr.addData(value, 'Byte');
        qr.make();
        var modules = qr.getModuleCount();
        var cellSize = Math.max(3, Math.floor(176 / modules));
        var margin = 4;
        var size = (modules + margin * 2) * cellSize;
        var canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', '수업 참여 QR 코드');
        var context = canvas.getContext('2d');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, size, size);
        context.fillStyle = '#111';
        for (var row = 0; row < modules; row += 1) {
            for (var col = 0; col < modules; col += 1) {
                if (qr.isDark(row, col)) {
                    context.fillRect((col + margin) * cellSize, (row + margin) * cellSize, cellSize, cellSize);
                }
            }
        }
        container.replaceChildren(canvas);
        container.setAttribute('data-code', code);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-field="join-code"]').forEach(function (codeEl) {
            render(codeEl);
            new MutationObserver(function () { render(codeEl); }).observe(codeEl, {
                childList: true,
                characterData: true,
                subtree: true,
            });
        });
    });
})();
