(function () {
    'use strict';

    var STORAGE_KEY = 'teachingTools.operations.v1';
    var MODE_LABELS = {
        explain: '교사 설명',
        solo: '개별 활동',
        pair: '짝 활동',
        group: '모둠 활동',
        wrap: '정리',
    };

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-operations]');
        if (!root) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var boardEl = root.querySelector('[data-field="board"]');
        var saveStatusEl = root.querySelector('[data-field="save-status"]');
        var agendaFormEl = root.querySelector('[data-field="agenda-form"]');
        var agendaInputEl = root.querySelector('[data-field="agenda-input"]');
        var agendaListEl = root.querySelector('[data-field="agenda-list"]');
        var modeDisplayEl = root.querySelector('[data-field="mode-display"]');
        var modeOptionsEl = root.querySelector('[data-field="mode-options"]');
        var trafficEl = root.querySelector('[data-field="traffic"]');
        var clockEl = root.querySelector('[data-field="clock"]');
        var minutesEl = root.querySelector('[data-field="minutes"]');
        var noiseToggleLabelEl = root.querySelector('[data-field="noise-toggle-label"]');
        var noiseLevelEl = root.querySelector('[data-field="noise-level"]');
        var noiseMeterEl = root.querySelector('[data-field="noise-meter"]');
        var noiseThresholdEl = root.querySelector('[data-field="noise-threshold"]');
        var noiseThresholdOutputEl = root.querySelector('[data-field="noise-threshold-output"]');
        var noiseThresholdMarkerEl = root.querySelector('[data-field="noise-threshold-marker"]');
        var noiseStatusEl = root.querySelector('[data-field="noise-status"]');
        var templateNameEl = root.querySelector('[data-field="template-name"]');
        var templateSelectEl = root.querySelector('[data-field="template-select"]');

        var state = {
            agenda: [],
            mode: 'explain',
            light: 'green',
            minutes: 5,
            noiseThreshold: 55,
        };
        var remainingSeconds = 300;
        var timerRunning = false;
        var timerEndAt = 0;
        var timerHandle = null;
        var noiseStream = null;
        var noiseContext = null;
        var noiseAnalyser = null;
        var noiseFrame = null;
        var saveHandle = null;

        function request(path, method, body) {
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
                    return data;
                });
            });
        }

        function loadTemplateList() {
            request('/lesson-templates', 'GET').then(function (data) {
                templateSelectEl.innerHTML = '<option value="">저장된 수업안</option>';
                (data.items || []).forEach(function (item) {
                    var option = document.createElement('option');
                    option.value = String(item.id);
                    option.textContent = item.name;
                    templateSelectEl.appendChild(option);
                });
            }).catch(function (error) {
                saveStatusEl.textContent = error.message;
            });
        }

        function loadState() {
            try {
                var saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}');
                if (saved && typeof saved === 'object') {
                    state.agenda = Array.isArray(saved.agenda) ? saved.agenda.slice(0, 20).map(function (item) {
                        return { text: String(item.text || '').slice(0, 80), done: Boolean(item.done) };
                    }).filter(function (item) { return item.text; }) : [];
                    state.mode = MODE_LABELS[saved.mode] ? saved.mode : 'explain';
                    state.light = ['red', 'yellow', 'green'].indexOf(saved.light) >= 0 ? saved.light : 'green';
                    state.minutes = Math.max(1, Math.min(180, Number(saved.minutes || 5)));
                    state.noiseThreshold = Math.max(10, Math.min(90, Number(saved.noiseThreshold || 55)));
                }
            } catch (error) {
                // A blocked or malformed local store simply starts fresh.
            }
            remainingSeconds = state.minutes * 60;
        }

        function saveState() {
            if (saveHandle !== null) {
                window.clearTimeout(saveHandle);
            }
            saveStatusEl.textContent = '저장 중...';
            saveHandle = window.setTimeout(function () {
                saveHandle = null;
                try {
                    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
                    saveStatusEl.textContent = '이 브라우저에 저장됨';
                } catch (error) {
                    saveStatusEl.textContent = '브라우저 저장을 사용할 수 없습니다.';
                }
            }, 180);
        }

        function iconButton(label, iconName, action) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ojp-button ojp-button--plain htt-operations-list-button';
            button.setAttribute('aria-label', label);
            button.title = label;
            button.setAttribute('data-list-action', action);
            button.innerHTML = '<span aria-hidden="true">' + (iconName === 'up' ? '↑' : iconName === 'down' ? '↓' : '×') + '</span>';
            return button;
        }

        function renderAgenda() {
            agendaListEl.innerHTML = '';
            if (!state.agenda.length) {
                var empty = document.createElement('li');
                empty.className = 'ojp-empty-state htt-operations-agenda-empty';
                empty.textContent = '수업 활동을 추가하세요.';
                agendaListEl.appendChild(empty);
                return;
            }
            state.agenda.forEach(function (item, index) {
                var row = document.createElement('li');
                row.className = item.done ? 'is-done' : '';
                var check = document.createElement('input');
                check.type = 'checkbox';
                check.checked = item.done;
                check.setAttribute('aria-label', item.text + ' 완료');
                check.addEventListener('change', function () {
                    item.done = check.checked;
                    renderAgenda();
                    saveState();
                });
                var number = document.createElement('span');
                number.className = 'htt-operations-agenda-number';
                number.textContent = String(index + 1);
                var text = document.createElement('span');
                text.className = 'htt-operations-agenda-text';
                text.textContent = item.text;
                var actions = document.createElement('span');
                actions.className = 'htt-operations-agenda-actions';
                var up = iconButton('위로 이동', 'up', 'up');
                var down = iconButton('아래로 이동', 'down', 'down');
                var remove = iconButton('삭제', 'remove', 'remove');
                up.disabled = index === 0;
                down.disabled = index === state.agenda.length - 1;
                up.addEventListener('click', function () {
                    state.agenda.splice(index - 1, 0, state.agenda.splice(index, 1)[0]);
                    renderAgenda();
                    saveState();
                });
                down.addEventListener('click', function () {
                    state.agenda.splice(index + 1, 0, state.agenda.splice(index, 1)[0]);
                    renderAgenda();
                    saveState();
                });
                remove.addEventListener('click', function () {
                    state.agenda.splice(index, 1);
                    renderAgenda();
                    saveState();
                });
                actions.append(up, down, remove);
                row.append(check, number, text, actions);
                agendaListEl.appendChild(row);
            });
        }

        function renderMode() {
            modeDisplayEl.textContent = MODE_LABELS[state.mode];
            Array.prototype.forEach.call(modeOptionsEl.querySelectorAll('[data-mode]'), function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-mode') === state.mode);
            });
        }

        function renderLight() {
            root.setAttribute('data-active-light', state.light);
            Array.prototype.forEach.call(trafficEl.querySelectorAll('[data-light]'), function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-light') === state.light);
            });
        }

        function formatClock(seconds) {
            var value = Math.max(0, Math.round(seconds));
            return String(Math.floor(value / 60)).padStart(2, '0') + ':' + String(value % 60).padStart(2, '0');
        }

        function renderClock() {
            if (timerRunning) {
                remainingSeconds = Math.max(0, Math.ceil((timerEndAt - Date.now()) / 1000));
                if (remainingSeconds <= 0) {
                    timerRunning = false;
                    timerEndAt = 0;
                    window.clearInterval(timerHandle);
                    timerHandle = null;
                    clockEl.classList.add('is-ended');
                    if (window.HttTimerSound) {
                        window.HttTimerSound.play('bell');
                    }
                }
            }
            clockEl.textContent = formatClock(remainingSeconds);
        }

        function startTimer() {
            if (!timerRunning && remainingSeconds <= 0) {
                remainingSeconds = Math.max(1, Number(minutesEl.value || state.minutes)) * 60;
            }
            timerRunning = true;
            timerEndAt = Date.now() + remainingSeconds * 1000;
            clockEl.classList.remove('is-ended');
            if (timerHandle !== null) {
                window.clearInterval(timerHandle);
            }
            timerHandle = window.setInterval(renderClock, 250);
            renderClock();
        }

        function pauseTimer() {
            if (!timerRunning) {
                return;
            }
            remainingSeconds = Math.max(0, Math.ceil((timerEndAt - Date.now()) / 1000));
            timerRunning = false;
            timerEndAt = 0;
            if (timerHandle !== null) {
                window.clearInterval(timerHandle);
                timerHandle = null;
            }
            renderClock();
        }

        function resetTimer() {
            timerRunning = false;
            timerEndAt = 0;
            if (timerHandle !== null) {
                window.clearInterval(timerHandle);
                timerHandle = null;
            }
            state.minutes = Math.max(1, Math.min(180, Number(minutesEl.value || 5)));
            remainingSeconds = state.minutes * 60;
            clockEl.classList.remove('is-ended');
            renderClock();
            saveState();
        }

        function renderThreshold() {
            state.noiseThreshold = Math.max(10, Math.min(90, Number(noiseThresholdEl.value || 55)));
            noiseThresholdOutputEl.textContent = String(state.noiseThreshold);
            noiseThresholdMarkerEl.style.left = state.noiseThreshold + '%';
        }

        function stopNoise() {
            if (noiseFrame !== null) {
                window.cancelAnimationFrame(noiseFrame);
                noiseFrame = null;
            }
            if (noiseStream) {
                noiseStream.getTracks().forEach(function (track) { track.stop(); });
                noiseStream = null;
            }
            if (noiseContext) {
                noiseContext.close().catch(function () {});
                noiseContext = null;
            }
            noiseAnalyser = null;
            noiseLevelEl.style.width = '0%';
            noiseMeterEl.classList.remove('is-loud');
            noiseToggleLabelEl.textContent = '마이크 켜기';
            noiseStatusEl.textContent = '마이크를 켜면 현재 기기에서만 소음 크기를 계산합니다.';
        }

        function sampleNoise() {
            if (!noiseAnalyser) {
                return;
            }
            var data = new Uint8Array(noiseAnalyser.fftSize);
            noiseAnalyser.getByteTimeDomainData(data);
            var sum = 0;
            for (var i = 0; i < data.length; i += 1) {
                var sample = (data[i] - 128) / 128;
                sum += sample * sample;
            }
            var rms = Math.sqrt(sum / data.length);
            var level = Math.max(0, Math.min(100, Math.round(rms * 260)));
            noiseLevelEl.style.width = level + '%';
            var loud = level >= state.noiseThreshold;
            noiseMeterEl.classList.toggle('is-loud', loud);
            noiseStatusEl.textContent = loud ? '주의 기준을 넘었습니다.' : '현재 소음은 기준 안입니다.';
            noiseFrame = window.requestAnimationFrame(sampleNoise);
        }

        function startNoise() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                noiseStatusEl.textContent = '이 브라우저에서는 마이크 측정을 지원하지 않습니다.';
                return;
            }
            noiseStatusEl.textContent = '마이크 권한을 확인하고 있습니다.';
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                noiseStream = stream;
                var AudioContext = window.AudioContext || window.webkitAudioContext;
                noiseContext = new AudioContext();
                var source = noiseContext.createMediaStreamSource(stream);
                noiseAnalyser = noiseContext.createAnalyser();
                noiseAnalyser.fftSize = 1024;
                noiseAnalyser.smoothingTimeConstant = 0.72;
                source.connect(noiseAnalyser);
                noiseToggleLabelEl.textContent = '마이크 끄기';
                sampleNoise();
            }).catch(function () {
                stopNoise();
                noiseStatusEl.textContent = '마이크 권한이 필요합니다.';
            });
        }

        agendaFormEl.addEventListener('submit', function (event) {
            event.preventDefault();
            var text = (agendaInputEl.value || '').trim();
            if (!text || state.agenda.length >= 20) {
                return;
            }
            state.agenda.push({ text: text, done: false });
            agendaInputEl.value = '';
            renderAgenda();
            saveState();
        });

        modeOptionsEl.addEventListener('click', function (event) {
            var button = event.target.closest('[data-mode]');
            if (!button) {
                return;
            }
            state.mode = button.getAttribute('data-mode');
            renderMode();
            saveState();
        });

        trafficEl.addEventListener('click', function (event) {
            var button = event.target.closest('[data-light]');
            if (!button) {
                return;
            }
            state.light = button.getAttribute('data-light');
            renderLight();
            saveState();
        });

        minutesEl.addEventListener('change', resetTimer);
        root.querySelector('[data-action="timer-start"]').addEventListener('click', startTimer);
        root.querySelector('[data-action="timer-pause"]').addEventListener('click', pauseTimer);
        root.querySelector('[data-action="timer-reset"]').addEventListener('click', resetTimer);
        root.querySelector('[data-action="noise-toggle"]').addEventListener('click', function () {
            if (noiseStream) {
                stopNoise();
            } else {
                startNoise();
            }
        });
        noiseThresholdEl.addEventListener('input', function () {
            renderThreshold();
            saveState();
        });
        root.querySelector('[data-action="fullscreen"]').addEventListener('click', function () {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (boardEl.requestFullscreen) {
                boardEl.requestFullscreen();
            }
        });
        root.querySelector('[data-action="template-save"]').addEventListener('click', function () {
            var name = (templateNameEl.value || '').trim();
            if (!name || !state.agenda.length) {
                saveStatusEl.textContent = '수업안 이름과 활동을 입력하세요.';
                return;
            }
            request('/lesson-templates', 'POST', {
                name: name,
                steps: state.agenda.map(function (item) {
                    return { text: item.text, mode: state.mode, minutes: state.minutes };
                }),
            }).then(function (data) {
                saveStatusEl.textContent = '수업안을 저장했습니다.';
                loadTemplateList();
                templateSelectEl.value = String(data.id || '');
            }).catch(function (error) {
                saveStatusEl.textContent = error.message;
            });
        });
        root.querySelector('[data-action="template-load"]').addEventListener('click', function () {
            if (!templateSelectEl.value) {
                return;
            }
            request('/lesson-templates/' + templateSelectEl.value, 'GET').then(function (data) {
                var steps = Array.isArray(data.steps) ? data.steps : [];
                state.agenda = steps.map(function (step) { return { text: step.text, done: false }; });
                if (steps[0]) {
                    state.mode = MODE_LABELS[steps[0].mode] ? steps[0].mode : 'explain';
                    state.minutes = Math.max(1, Math.min(180, Number(steps[0].minutes || 5)));
                    minutesEl.value = String(state.minutes);
                    resetTimer();
                }
                templateNameEl.value = data.name || '';
                renderAgenda();
                renderMode();
                saveState();
            }).catch(function (error) {
                saveStatusEl.textContent = error.message;
            });
        });
        root.querySelector('[data-action="template-delete"]').addEventListener('click', function () {
            if (!templateSelectEl.value || !window.confirm('선택한 수업안을 삭제할까요?')) {
                return;
            }
            request('/lesson-templates/' + templateSelectEl.value, 'DELETE').then(function () {
                templateNameEl.value = '';
                loadTemplateList();
                saveStatusEl.textContent = '수업안을 삭제했습니다.';
            }).catch(function (error) {
                saveStatusEl.textContent = error.message;
            });
        });
        window.addEventListener('beforeunload', stopNoise);

        var usageButton = root.querySelector('[data-action="show-usage"]');
        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('수업 운영 보드 사용법', '<ul>'
                    + '<li>수업 순서를 추가하고 완료 여부와 순서를 바로 조정하세요.</li>'
                    + '<li>현재 활동 모드와 신호등은 프로젝터 화면에서 크게 보입니다.</li>'
                    + '<li>소음 신호는 켠 동안만 마이크를 사용하며 녹음하거나 서버로 보내지 않습니다.</li>'
                    + '<li>순서표와 설정은 현재 브라우저에 자동 저장됩니다.</li>'
                    + '</ul>');
            });
        }

        loadState();
        minutesEl.value = String(state.minutes);
        noiseThresholdEl.value = String(state.noiseThreshold);
        renderAgenda();
        renderMode();
        renderLight();
        renderThreshold();
        renderClock();
        loadTemplateList();
    });
})();
