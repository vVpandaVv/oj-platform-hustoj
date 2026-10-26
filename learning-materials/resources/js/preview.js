(function () {
    'use strict';

    var initialized = new WeakSet();

    async function openPreview(root) {
        var button = root.querySelector('.lmt-preview-open');
        if (button.disabled) return;
        var status = root.querySelector('.lmt-preview-status');
        var container = root.querySelector('.lmt-preview-frame');
        var newWindow = root.querySelector('.lmt-preview-new-window');
        var failureMessage = root.dataset.failureMessage || '미리보기를 불러오지 못했습니다. 원본을 다운로드해 주세요.';
        var timeoutMessage = root.dataset.timeoutMessage || '자료가 보이지 않으면 원본을 다운로드해 주세요.';
        var controller = new AbortController();
        var requestTimer = setTimeout(function () { controller.abort(); }, 15000);
        clearTimeout(root.previewTimer);
        clearTimeout(root.linkExpiryTimer);
        if (newWindow) newWindow.hidden = true;
        container.replaceChildren();
        button.disabled = true;
        status.textContent = '불러오는 중…';

        function hasRemoteDocument(frame) {
            try {
                var href = frame.contentWindow && frame.contentWindow.location.href;
                return Boolean(href && href !== 'about:blank');
            } catch (error) {
                // A rendered cross-origin document cannot be inspected.
                return error.name === 'SecurityError';
            }
        }

        function mountViewer(url, attempt) {
            if (!root.isConnected) return;
            clearTimeout(root.previewTimer);
            container.replaceChildren();
            var frame = document.createElement('iframe');
            frame.className = 'lmt-viewer';
            frame.title = 'Google 학습자료 미리보기';
            frame.referrerPolicy = 'no-referrer';
            // Google can return 204 application/binary. Never allow an automatic download.
            frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox');

            function retryOrFinish() {
                if (!root.isConnected || container.children[0] !== frame) return;
                clearTimeout(root.previewTimer);
                if (attempt < 3) {
                    status.textContent = '다시 불러오는 중…';
                    mountViewer(url, attempt + 1);
                } else {
                    status.textContent = failureMessage;
                }
            }

            frame.addEventListener('load', function () {
                if (container.children[0] !== frame || !hasRemoteDocument(frame)) return;
                clearTimeout(root.previewTimer);
                status.textContent = '';
            });
            frame.addEventListener('error', retryOrFinish);
            root.previewTimer = setTimeout(function () {
                if (!root.isConnected || container.children[0] !== frame) return;
                if (!hasRemoteDocument(frame)) {
                    retryOrFinish();
                } else {
                    status.textContent = timeoutMessage;
                }
            }, 12000);
            frame.src = url.href;
            container.appendChild(frame);
        }

        try {
            var body = new URLSearchParams({
                action: 'learning_materials_preview_link',
                material_id: root.dataset.materialId,
                nonce: root.dataset.nonce
            });
            var response = await fetch(root.dataset.endpoint, {
                method: 'POST', credentials: 'same-origin', body: body,
                cache: 'no-store', signal: controller.signal
            });
            var result = await response.json();
            if (!response.ok || !result.success) throw new Error('preview unavailable');
            var url = new URL(result.data.viewer_url);
            var allowed = (url.origin === 'https://docs.google.com' && (url.pathname === '/gview'
                || /^\/(document|spreadsheets|presentation)\/d\/(e\/)?[A-Za-z0-9_-]+\/(preview|embed|pub|pubhtml)$/.test(url.pathname)))
                || (url.origin === 'https://drive.google.com' && /^\/file\/d\/[A-Za-z0-9_-]+\/preview$/.test(url.pathname));
            if (!allowed || url.username || url.password) {
                throw new Error('unexpected viewer');
            }
            if (!root.isConnected) return;
            root.lastExpiresAt = Number(result.data.expires_at) || 0;
            if (newWindow) {
                newWindow.href = url.href;
                newWindow.hidden = false;
                if (root.lastExpiresAt) root.linkExpiryTimer = setTimeout(function () {
                    newWindow.hidden = true;
                }, Math.max(0, root.lastExpiresAt * 1000 - Date.now()));
            }
            mountViewer(url, 1);
            status.textContent = '불러오는 중…';
        } catch (error) {
            status.textContent = failureMessage;
        } finally {
            clearTimeout(requestTimer);
            button.disabled = false;
        }
    }

    async function diagnose(button) {
        if (button.disabled) return;
        var root = button.closest('.lmt-diagnostics');
        var output = root.querySelector('.lmt-diagnostic-results');
        var preview = root.closest('.lmt-public').querySelector('.lmt-google-preview');
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 25000);
        button.disabled = true;
        output.textContent = '진단 중…';
        try {
            var body = new URLSearchParams({ action: 'learning_materials_diagnose', material_id: root.dataset.materialId,
                nonce: root.dataset.nonce, expires_at: preview ? String(preview.lastExpiresAt || 0) : '0' });
            var response = await fetch(root.dataset.endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body, signal: controller.signal });
            var result = await response.json();
            if (!response.ok || !result.success || !Array.isArray(result.data.checks)) throw new Error('diagnostics failed');
            if (!root.isConnected) return;
            var list = document.createElement('ul');
            result.data.checks.forEach(function (check) {
                var item = document.createElement('li');
                var state = { ok: '정상', warning: '확인 필요', error: '오류' }[check.status] || '확인 필요';
                item.textContent = '[' + state + '] ' + String(check.label) + ': ' + String(check.message);
                list.appendChild(item);
            });
            output.replaceChildren(list);
        } catch (error) {
            output.textContent = '진단하지 못했습니다. 로그인과 네트워크 연결을 확인해 주세요.';
        } finally {
            clearTimeout(timer);
            button.disabled = false;
        }
    }

    function initialize() {
        document.querySelectorAll('.lmt-public .lmt-google-preview').forEach(function (root) {
            if (initialized.has(root)) return;
            initialized.add(root);
            openPreview(root);
        });
    }

    document.addEventListener('click', function (event) {
        var diagnosticButton = event.target.closest('.lmt-public .lmt-diagnostic-run');
        if (diagnosticButton) { diagnose(diagnosticButton); return; }
        var button = event.target.closest('.lmt-public .lmt-preview-open');
        if (button) openPreview(button.closest('.lmt-google-preview'));
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
    // Also initialize material pages inserted by platform navigation.
    new MutationObserver(function (changes) {
        if (changes.some(function (change) { return change.addedNodes.length > 0; })) initialize();
    }).observe(document.documentElement, { childList: true, subtree: true });
}());
