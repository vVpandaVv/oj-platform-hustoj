(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-quickboard-host]');
        var render = window.HttWhiteboardRender;
        if (!root || !render) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var setupEl = root.querySelector('[data-field="setup"]');
        var sessionEl = root.querySelector('[data-field="session"]');
        var messageEl = root.querySelector('[data-field="message"]');
        var promptInput = root.querySelector('[data-field="prompt"]');
        var anonymousInput = root.querySelector('[data-field="anonymous"]');
        var joinCodeEl = root.querySelector('[data-field="join-code"]');
        var promptDisplayEl = root.querySelector('[data-field="prompt-display"]');
        var summaryEl = root.querySelector('[data-field="summary"]');
        var galleryEl = root.querySelector('[data-field="gallery"]');
        var spotlightEl = root.querySelector('[data-field="spotlight"]');
        var spotlightTitleEl = root.querySelector('[data-field="spotlight-title"]');
        var spotlightCanvas = root.querySelector('[data-field="spotlight-canvas"]');
        var spotlightCtx = spotlightCanvas.getContext('2d');
        var joinCode = '';
        var responses = [];
        var refreshHandle = null;
        var refreshInFlight = false;
        var realtime = null;
        var imageCache = {};

        function request(path, method, body) {
            try {
                if (window.HttAudience) { body = window.HttAudience.prepare(root, path, method, body); }
            } catch (error) { return Promise.reject(error); }
            return fetch(config.restUrl + path, {
                method: method,
                headers: Object.assign(
                    { 'X-WP-Nonce': config.nonce },
                    body ? { 'Content-Type': 'application/json' } : {}
                ),
                body: body ? JSON.stringify(body) : undefined,
                credentials: 'same-origin',
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error((data && data.message) || '요청을 처리하지 못했습니다.');
                    }
                    if (window.HttAudience) { window.HttAudience.completed(root, path, data); }
                    return data;
                });
            });
        }

        function showMessage(text, type) {
            messageEl.textContent = text || '';
            messageEl.className = 'htt-quickboard-message is-' + (type || 'info');
            messageEl.hidden = !text;
        }

        function openSpotlight(item) {
            spotlightTitleEl.textContent = item.name;
            spotlightEl.hidden = false;
            render.redrawElements(spotlightCtx, spotlightCanvas, item.elements || [], imageCache, function () {});
        }

        function clearResponses(id) {
            request('/sessions/' + joinCode + '/quickboard-responses/clear', 'POST', { id: id }).then(function () {
                if (realtime) {
                    realtime.send({ t: 'event', event: 'boards-cleared', data: { id: id } });
                }
                refreshResponses();
            }).catch(function (error) {
                showMessage(error.message, 'danger');
            });
        }

        function renderGallery() {
            galleryEl.innerHTML = '';
            var submitted = responses.filter(function (item) { return item.submittedAt > 0; }).length;
            summaryEl.textContent = responses.length
                ? submitted + '/' + responses.length + '명 제출'
                : '응답을 기다리고 있습니다.';

            if (!responses.length) {
                var empty = document.createElement('p');
                empty.className = 'ojp-empty-state';
                empty.textContent = '아직 참여한 학생이 없습니다.';
                galleryEl.appendChild(empty);
                return;
            }

            responses.forEach(function (item) {
                var card = document.createElement('article');
                card.className = 'htt-quickboard-card' + (item.submittedAt ? ' is-submitted' : ' is-waiting');
                var heading = document.createElement('div');
                heading.className = 'htt-quickboard-card-heading';
                var title = document.createElement('h3');
                title.textContent = item.name;
                var status = document.createElement('span');
                status.className = 'ojp-status-badge ' + (item.submittedAt ? 'ojp-status-badge--success' : 'ojp-status-badge--neutral');
                status.textContent = item.submittedAt ? '제출' : '대기';
                heading.append(title, status);

                var canvasButton = document.createElement('button');
                canvasButton.type = 'button';
                canvasButton.className = 'htt-quickboard-preview';
                canvasButton.disabled = !item.submittedAt;
                canvasButton.setAttribute('aria-label', item.name + ' 판서 크게 보기');
                var canvas = document.createElement('canvas');
                canvas.width = 500;
                canvas.height = 300;
                canvasButton.appendChild(canvas);
                var ctx = canvas.getContext('2d');
                render.redrawElements(ctx, canvas, item.elements || [], imageCache, function () {});
                canvasButton.addEventListener('click', function () { openSpotlight(item); });

                var actions = document.createElement('div');
                actions.className = 'htt-quickboard-card-actions';
                var spotlightButton = document.createElement('button');
                spotlightButton.type = 'button';
                spotlightButton.className = 'ojp-button ojp-button--tinted ojp-button--small';
                spotlightButton.textContent = '크게 보기';
                spotlightButton.disabled = !item.submittedAt;
                spotlightButton.addEventListener('click', function () { openSpotlight(item); });
                var clearButton = document.createElement('button');
                clearButton.type = 'button';
                clearButton.className = 'ojp-button ojp-button--plain ojp-button--small';
                clearButton.textContent = '지우기';
                clearButton.disabled = !item.submittedAt;
                clearButton.addEventListener('click', function () { clearResponses(item.id); });
                actions.append(spotlightButton, clearButton);
                card.append(heading, canvasButton, actions);
                galleryEl.appendChild(card);
            });
        }

        function refreshResponses() {
            if (!joinCode || refreshInFlight) {
                return;
            }
            refreshInFlight = true;
            request('/sessions/' + joinCode + '/quickboard-responses', 'GET').then(function (data) {
                refreshInFlight = false;
                responses = data.responses || [];
                renderGallery();
            }).catch(function (error) {
                refreshInFlight = false;
                showMessage(error.message, 'danger');
            });
        }

        function startRealtime() {
            if (!window.HttRealtime || !joinCode) {
                return;
            }
            realtime = window.HttRealtime.connect({
                ticket: function () {
                    return request('/sessions/' + joinCode + '/realtime-ticket', 'POST');
                },
                onMessage: function (message) {
                    if (message.t === 'participant-online' || message.t === 'participant-offline') {
                        refreshResponses();
                    } else if (message.t === 'participant-event' && message.event === 'board-updated') {
                        refreshResponses();
                    }
                },
            });
        }

        function stopSessionUi() {
            if (refreshHandle !== null) {
                window.clearInterval(refreshHandle);
                refreshHandle = null;
            }
            if (realtime) {
                realtime.stop();
                realtime = null;
            }
            joinCode = '';
            responses = [];
            galleryEl.innerHTML = '';
            sessionEl.hidden = true;
            setupEl.hidden = false;
        }

        root.querySelector('[data-action="start"]').addEventListener('click', function () {
            var prompt = (promptInput.value || '').trim();
            if (!prompt) {
                showMessage('학생에게 보여줄 질문 또는 안내를 입력하세요.', 'danger');
                return;
            }
            request('/sessions', 'POST', {
                toolType: 'quickboard',
                mode: 'live',
                state: { prompt: prompt, anonymous: Boolean(anonymousInput.checked) },
            }).then(function (data) {
                joinCode = data.joinCode;
                joinCodeEl.textContent = joinCode;
                promptDisplayEl.textContent = prompt;
                setupEl.hidden = true;
                sessionEl.hidden = false;
                showMessage('', 'info');
                renderGallery();
                refreshResponses();
                refreshHandle = window.setInterval(refreshResponses, 3000);
                startRealtime();
            }).catch(function (error) {
                showMessage(error.message, 'danger');
            });
        });

        root.querySelector('[data-action="copy-link"]').addEventListener('click', function () {
            var link = config.joinUrl + '?code=' + joinCode;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(link).then(function () {
                    showMessage('참여 링크를 복사했습니다.', 'success');
                }).catch(function () { window.prompt('참여 링크', link); });
            } else {
                window.prompt('참여 링크', link);
            }
        });

        root.querySelector('[data-action="clear-all"]').addEventListener('click', function () {
            if (window.confirm('모든 학생의 미니 판서를 지울까요?')) {
                clearResponses(0);
            }
        });

        root.querySelector('[data-action="end"]').addEventListener('click', function () {
            if (realtime) {
                realtime.send({ t: 'close' });
            }
            request('/sessions/' + joinCode + '/end', 'POST').catch(function () {}).then(function () {
                stopSessionUi();
                showMessage('미니 판서를 종료했습니다.', 'success');
            });
        });

        root.querySelector('[data-action="close-spotlight"]').addEventListener('click', function () {
            spotlightEl.hidden = true;
        });
        spotlightEl.addEventListener('click', function (event) {
            if (event.target === spotlightEl) {
                spotlightEl.hidden = true;
            }
        });

        var usageButton = root.querySelector('[data-action="show-usage"]');
        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('학생 미니 판서 사용법', '<ul>'
                    + '<li>질문을 입력하고 참여 코드나 링크를 학생에게 전달하세요.</li>'
                    + '<li>학생이 제출하거나 다시 제출하면 교사 갤러리가 즉시 갱신됩니다.</li>'
                    + '<li>썸네일을 누르면 한 학생의 판서를 크게 볼 수 있습니다.</li>'
                    + '<li>이름 숨김을 켜면 교사 화면에도 응답 번호만 표시됩니다.</li>'
                    + '</ul>');
            });
        }
    });
})();
