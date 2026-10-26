(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-timer-host]');
        if (!root) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var setup = root.querySelector('[data-field="setup"]');
        var display = root.querySelector('[data-field="display"]');
        var joinInfo = root.querySelector('[data-field="join-info"]');
        var joinCodeEl = root.querySelector('[data-field="join-code"]');
        var participantCountEl = root.querySelector('[data-field="participant-count"]');
        var clockEl = root.querySelector('[data-field="clock"]');
        var endedMessageEl = root.querySelector('[data-field="ended-message"]');
        var soundSelect = root.querySelector('[data-field="sound"]');
        var startButton = root.querySelector('[data-action="start"]');
        var toggleButton = root.querySelector('[data-action="toggle"]');
        var resetButton = root.querySelector('[data-action="reset"]');
        var copyLinkButton = root.querySelector('[data-action="copy-link"]');
        var usageButton = root.querySelector('[data-action="show-usage"]');
        var previewSoundButton = root.querySelector('[data-action="preview-sound"]');

        if (previewSoundButton && window.HttTimerSound) {
            previewSoundButton.addEventListener('click', function () {
                window.HttTimerSound.play(soundSelect.value);
            });
        }

        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('타이머 사용법', '<ul>'
                    + '<li>시간을 설정하고 "시작"을 누르면 카운트다운이 시작됩니다.</li>'
                    + '<li>"학생 기기에도 표시"를 선택하면 참여 코드가 생성되고, 학생은 참여 페이지에서 같은 코드로 입장해 같은 타이머를 봅니다.</li>'
                    + '<li>시간이 다 되면 "시간이 종료되었습니다" 표시가 깜빡이며 나타나고, 선택한 알림음이 울립니다(교사·학생 화면 모두). "미리듣기"로 알림음을 미리 확인할 수 있습니다.</li>'
                    + '<li>"새 타이머"를 누르면 진행 중인 세션이 종료되고 처음 설정으로 돌아갑니다.</li>'
                    + '</ul>');
            });
        }

        var state = {
            joinCode: null,
            mode: 'solo',
            remainingSeconds: 0,
            running: false,
            sound: 'chime',
            tickHandle: null,
            syncHandle: null,
        };

        function formatClock(totalSeconds) {
            var seconds = Math.max(0, Math.round(totalSeconds));
            var minutes = Math.floor(seconds / 60);
            var rest = seconds % 60;
            return String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
        }

        function renderClock() {
            clockEl.textContent = formatClock(state.remainingSeconds);
        }

        function setEnded(ended) {
            clockEl.classList.toggle('is-ended', ended);
            endedMessageEl.hidden = !ended;
        }

        function request(path, method, body) {
            try {
                if (window.HttAudience) { body = window.HttAudience.prepare(root, path, method, body); }
            } catch (error) { return Promise.reject(error); }
            return fetch(config.restUrl + path, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
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

        function pushState() {
            if (!state.joinCode) {
                return;
            }
            request('/sessions/' + state.joinCode + '/state', 'POST', {
                state: {
                    remainingSeconds: state.remainingSeconds,
                    running: state.running,
                    sound: state.sound,
                },
            }).catch(function () {
                // Best-effort sync — the host's own clock keeps running locally either way.
            });
        }

        function stopTimers() {
            if (state.tickHandle) {
                clearInterval(state.tickHandle);
                state.tickHandle = null;
            }
            if (state.syncHandle) {
                clearInterval(state.syncHandle);
                state.syncHandle = null;
            }
        }

        function tick() {
            if (!state.running) {
                return;
            }
            state.remainingSeconds = Math.max(0, state.remainingSeconds - 1);
            renderClock();
            if (state.remainingSeconds <= 0) {
                state.running = false;
                setEnded(true);
                if (window.HttTimerSound) {
                    window.HttTimerSound.play(state.sound);
                }
                pushState();
            }
        }

        function startLocalClock() {
            stopTimers();
            state.tickHandle = setInterval(tick, 1000);
            if (state.mode === 'live') {
                state.syncHandle = setInterval(pushState, 2000);
            }
        }

        startButton.addEventListener('click', function () {
            var minutes = parseInt(root.querySelector('[data-field="minutes"]').value, 10) || 0;
            var seconds = parseInt(root.querySelector('[data-field="seconds"]').value, 10) || 0;
            var mode = root.querySelector('input[name="htt-timer-mode"]:checked').value;
            var totalSeconds = Math.max(1, minutes * 60 + seconds);
            var sound = soundSelect ? soundSelect.value : 'none';

            request('/sessions', 'POST', {
                toolType: 'timer',
                mode: mode,
                state: { remainingSeconds: totalSeconds, running: true, sound: sound },
            }).then(function (data) {
                state.joinCode = data.joinCode;
                state.mode = data.mode;
                state.remainingSeconds = totalSeconds;
                state.running = true;
                state.sound = sound;
                setEnded(false);

                setup.hidden = true;
                display.hidden = false;

                if (state.mode === 'live') {
                    joinInfo.hidden = false;
                    joinCodeEl.textContent = state.joinCode;
                    participantCountEl.textContent = '';
                }

                renderClock();
                startLocalClock();
            }).catch(function (error) {
                if (window.OJP && typeof window.OJP.toast === 'function') {
                    window.OJP.toast(error.message, 'danger');
                } else {
                    window.alert(error.message);
                }
            });
        });

        if (copyLinkButton) {
            copyLinkButton.addEventListener('click', function () {
                if (!state.joinCode) {
                    return;
                }
                var link = config.joinUrl + '?code=' + state.joinCode;
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(link).then(function () {
                        if (window.OJP && typeof window.OJP.toast === 'function') {
                            window.OJP.toast('참여 링크를 복사했습니다');
                        }
                    }).catch(function () { window.prompt('참여 링크', link); });
                } else {
                    window.prompt('참여 링크', link);
                }
            });
        }

        toggleButton.addEventListener('click', function () {
            state.running = !state.running;
            toggleButton.textContent = state.running ? '일시정지' : '계속';
            pushState();
        });

        resetButton.addEventListener('click', function () {
            stopTimers();
            if (state.joinCode) {
                request('/sessions/' + state.joinCode + '/end', 'POST').catch(function () {});
            }
            state.joinCode = null;
            setEnded(false);
            display.hidden = true;
            joinInfo.hidden = true;
            setup.hidden = false;
        });
    });
})();
