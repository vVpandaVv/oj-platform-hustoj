(function () {
    'use strict';

    var MAX_OPTIONS = 6;
    var TYPE_HELP = {
        choice: '보기 중 하나를 고릅니다. 정답을 지정하면 공개할 때 함께 표시됩니다.',
        scale: '1부터 정한 최댓값까지 느낌이나 이해도를 표시합니다.',
        text: '100자 이내의 짧은 답을 한 번 받습니다.',
        wordcloud: '30자 이내의 핵심어를 모아 빈도에 따라 크게 표시합니다.',
        qna: '학생 이름 없이 질문을 받고, 승인한 질문만 학생 화면에 보냅니다.',
    };

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-quiz-host]');
        if (!root) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var setup = root.querySelector('[data-field="setup"]');
        var messagesEl = root.querySelector('[data-field="messages"]');
        var resultEl = root.querySelector('[data-field="result"]');
        var joinInfo = root.querySelector('[data-field="join-info"]');
        var joinCodeEl = root.querySelector('[data-field="join-code"]');
        var questionDisplayEl = root.querySelector('[data-field="question-display"]');
        var responseCountEl = root.querySelector('[data-field="response-count"]');
        var barsEl = root.querySelector('[data-field="bars"]');
        var textWallEl = root.querySelector('[data-field="text-wall"]');
        var wordcloudEl = root.querySelector('[data-field="wordcloud"]');
        var qnaEl = root.querySelector('[data-field="qna"]');
        var optionsListEl = root.querySelector('[data-field="options-list"]');
        var choiceFieldsEl = root.querySelector('[data-field="choice-fields"]');
        var scaleFieldsEl = root.querySelector('[data-field="scale-fields"]');
        var typeHelpEl = root.querySelector('[data-field="type-help"]');
        var questionInput = root.querySelector('[data-field="question"]');
        var gradedInput = root.querySelector('[data-field="graded"]');
        var scaleLowInput = root.querySelector('[data-field="scale-low"]');
        var scaleHighInput = root.querySelector('[data-field="scale-high"]');
        var scaleMaxInput = root.querySelector('[data-field="scale-max"]');
        var textControlsEl = root.querySelector('[data-field="text-controls"]');
        var blockedWordsInput = root.querySelector('[data-field="blocked-words"]');
        var moderationRequiredInput = root.querySelector('[data-field="moderation-required"]');
        var startButton = root.querySelector('[data-action="start"]');
        var addOptionButton = root.querySelector('[data-action="add-option"]');
        var revealButton = root.querySelector('[data-action="reveal"]');
        var toggleAcceptingButton = root.querySelector('[data-action="toggle-accepting"]');
        var restartButton = root.querySelector('[data-action="restart"]');
        var copyLinkButton = root.querySelector('[data-action="copy-link"]');
        var usageButton = root.querySelector('[data-action="show-usage"]');

        var optionValues = ['', ''];
        var correctIndex = null;
        var joinCode = '';
        var pollHandle = null;
        var pollInFlight = false;
        var responseType = 'choice';
        var quizState = {};
        var latestResponses = [];

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

        function showMessages(messages) {
            messagesEl.innerHTML = '';
            messages.forEach(function (message) {
                var item = document.createElement('div');
                item.className = 'message ' + (message.type || 'info');
                item.textContent = message.text;
                messagesEl.appendChild(item);
            });
        }

        function selectedResponseType() {
            var selected = root.querySelector('input[name="htt-quiz-type"]:checked');
            return selected ? selected.value : 'choice';
        }

        function updateTypeFields() {
            var type = selectedResponseType();
            choiceFieldsEl.hidden = type !== 'choice';
            scaleFieldsEl.hidden = type !== 'scale';
            textControlsEl.hidden = ['text', 'wordcloud', 'qna'].indexOf(type) === -1;
            moderationRequiredInput.disabled = type === 'qna';
            if (type === 'qna') {
                moderationRequiredInput.checked = true;
            }
            typeHelpEl.textContent = TYPE_HELP[type] || '';
            questionInput.placeholder = type === 'qna'
                ? '예: 오늘 수업에서 궁금한 점을 자유롭게 적어주세요.'
                : '예: 오늘 배운 내용 중 가장 중요한 것은?';
        }

        function readOptionsFromInputs() {
            optionValues = Array.prototype.map.call(
                optionsListEl.querySelectorAll('.htt-quiz-option-input'),
                function (input) { return input.value; }
            );
            var checkedRadio = optionsListEl.querySelector('.htt-quiz-correct-radio:checked');
            correctIndex = checkedRadio ? parseInt(checkedRadio.value, 10) : null;
        }

        function renderOptionRows() {
            optionsListEl.innerHTML = '';
            optionValues.forEach(function (value, index) {
                var row = document.createElement('div');
                row.className = 'htt-quiz-option-row';

                var radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'htt-quiz-correct';
                radio.value = String(index);
                radio.className = 'htt-quiz-correct-radio';
                radio.checked = correctIndex === index;
                radio.hidden = !gradedInput.checked;
                radio.title = '정답으로 지정';
                radio.setAttribute('aria-label', (index + 1) + '번을 정답으로 지정');
                radio.addEventListener('change', function () { correctIndex = index; });

                var input = document.createElement('input');
                input.type = 'text';
                input.maxLength = 100;
                input.className = 'htt-quiz-option-input';
                input.placeholder = '선택지 ' + (index + 1);
                input.value = value;
                input.addEventListener('input', function () { optionValues[index] = input.value; });

                row.append(radio, input);
                if (optionValues.length > 2) {
                    var removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'ojp-button ojp-button--plain ojp-button--small';
                    removeButton.textContent = '삭제';
                    removeButton.addEventListener('click', function () {
                        readOptionsFromInputs();
                        optionValues.splice(index, 1);
                        if (correctIndex === index) {
                            correctIndex = null;
                        } else if (correctIndex !== null && correctIndex > index) {
                            correctIndex -= 1;
                        }
                        renderOptionRows();
                    });
                    row.appendChild(removeButton);
                }
                optionsListEl.appendChild(row);
            });
        }

        function countResponses() {
            return latestResponses.length;
        }

        function responseCounts(values, key) {
            var counts = values.map(function () { return 0; });
            latestResponses.forEach(function (response) {
                var index = key === 'value'
                    ? values.indexOf(Number(response.value))
                    : Number(response.optionIndex);
                if (index >= 0 && counts[index] !== undefined) {
                    counts[index] += 1;
                }
            });
            return counts;
        }

        function renderBars(labels, counts, revealFlag, correctIdx) {
            var total = counts.reduce(function (sum, count) { return sum + count; }, 0);
            barsEl.innerHTML = '';
            labels.forEach(function (label, index) {
                var count = counts[index] || 0;
                var pct = total ? Math.round((count / total) * 100) : 0;
                var row = document.createElement('div');
                row.className = 'htt-quiz-bar-row' + (revealFlag && correctIdx === index ? ' is-correct' : '');
                var labelEl = document.createElement('span');
                labelEl.className = 'htt-quiz-bar-label';
                labelEl.textContent = label + (revealFlag && correctIdx === index ? ' ✓' : '');
                var track = document.createElement('div');
                track.className = 'htt-quiz-bar-track';
                var fill = document.createElement('div');
                fill.className = 'htt-quiz-bar-fill';
                fill.style.width = pct + '%';
                track.appendChild(fill);
                var countEl = document.createElement('span');
                countEl.className = 'htt-quiz-bar-count';
                countEl.textContent = count + '명 (' + pct + '%)';
                row.append(labelEl, track, countEl);
                barsEl.appendChild(row);
            });
        }

        function renderTextWall() {
            textWallEl.innerHTML = '';
            visibleTextResponses().forEach(function (response) {
                var item = document.createElement('p');
                item.textContent = response.text || '';
                textWallEl.appendChild(item);
            });
            if (!visibleTextResponses().length) {
                textWallEl.textContent = '아직 응답이 없습니다.';
            }
        }

        function visibleTextResponses() {
            if (!quizState.moderationRequired && responseType !== 'qna') {
                return latestResponses;
            }
            return latestResponses.filter(function (response) {
                return response.status === 'approved';
            });
        }

        function groupedWords() {
            var grouped = {};
            visibleTextResponses().forEach(function (response) {
                var text = String(response.text || '').trim();
                var key = text.toLocaleLowerCase('ko');
                if (!key) {
                    return;
                }
                if (!grouped[key]) {
                    grouped[key] = { text: text, count: 0 };
                }
                grouped[key].count += 1;
            });
            return Object.keys(grouped).map(function (key) { return grouped[key]; }).sort(function (a, b) {
                return b.count - a.count || a.text.localeCompare(b.text, 'ko');
            });
        }

        function renderWordcloud() {
            wordcloudEl.innerHTML = '';
            var words = groupedWords();
            var max = words.reduce(function (value, word) { return Math.max(value, word.count); }, 1);
            words.forEach(function (word) {
                var item = document.createElement('span');
                item.textContent = word.text;
                item.title = word.count + '명';
                item.style.setProperty('--htt-word-weight', String(word.count / max));
                wordcloudEl.appendChild(item);
            });
            if (!words.length) {
                wordcloudEl.textContent = '아직 응답이 없습니다.';
            }
        }

        function moderate(responseId, status) {
            request('/sessions/' + joinCode + '/quiz-moderate', 'POST', {
                responseId: responseId,
                status: status,
            }).then(function () {
                poll();
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        }

        function qnaSection(title, status, responses) {
            var section = document.createElement('section');
            var heading = document.createElement('h3');
            heading.textContent = title + ' ' + responses.length;
            section.appendChild(heading);
            if (!responses.length) {
                var empty = document.createElement('p');
                empty.className = 'ojp-empty-state';
                empty.textContent = '해당 질문이 없습니다.';
                section.appendChild(empty);
                return section;
            }
            responses.forEach(function (response) {
                var item = document.createElement('div');
                item.className = 'htt-quiz-qna-item is-' + status;
                var text = document.createElement('p');
                text.textContent = response.text || '';
                var actions = document.createElement('div');
                if (status !== 'approved') {
                    var approve = document.createElement('button');
                    approve.type = 'button';
                    approve.className = 'ojp-button ojp-button--tinted ojp-button--small';
                    approve.textContent = '공개';
                    approve.addEventListener('click', function () { moderate(response.id, 'approved'); });
                    actions.appendChild(approve);
                }
                if (status !== 'dismissed') {
                    var dismiss = document.createElement('button');
                    dismiss.type = 'button';
                    dismiss.className = 'ojp-button ojp-button--plain ojp-button--small';
                    dismiss.textContent = '숨김';
                    dismiss.addEventListener('click', function () { moderate(response.id, 'dismissed'); });
                    actions.appendChild(dismiss);
                }
                if (status !== 'pending') {
                    var pending = document.createElement('button');
                    pending.type = 'button';
                    pending.className = 'ojp-button ojp-button--plain ojp-button--small';
                    pending.textContent = '대기';
                    pending.addEventListener('click', function () { moderate(response.id, 'pending'); });
                    actions.appendChild(pending);
                }
                item.append(text, actions);
                section.appendChild(item);
            });
            return section;
        }

        function renderQna() {
            qnaEl.innerHTML = '';
            ['pending', 'approved', 'dismissed'].forEach(function (status) {
                var labels = { pending: '대기', approved: '학생에게 공개', dismissed: '숨김' };
                qnaEl.appendChild(qnaSection(labels[status], status, latestResponses.filter(function (response) {
                    return (response.status || 'pending') === status;
                })));
            });
        }

        function renderResults() {
            responseCountEl.textContent = String(countResponses());
            barsEl.hidden = true;
            textWallEl.hidden = true;
            wordcloudEl.hidden = true;
            qnaEl.hidden = true;
            revealButton.hidden = responseType === 'qna';
            revealButton.textContent = quizState.revealed ? '공개 중' : '학생 화면에 결과 공개';
            revealButton.disabled = Boolean(quizState.revealed);
            toggleAcceptingButton.textContent = quizState.acceptingResponses === false ? '응답 다시 받기' : '응답 마감';

            if (responseType === 'choice') {
                barsEl.hidden = false;
                var options = quizState.options || [];
                renderBars(options, responseCounts(options, 'optionIndex'), Boolean(quizState.revealed), quizState.correctIndex);
            } else if (responseType === 'scale') {
                barsEl.hidden = false;
                var values = [];
                for (var value = Number(quizState.scaleMin || 1); value <= Number(quizState.scaleMax || 5); value += 1) {
                    values.push(value);
                }
                renderBars(values.map(String), responseCounts(values, 'value'), false, null);
            } else if (responseType === 'text') {
                textWallEl.hidden = false;
                renderTextWall();
                if (quizState.moderationRequired) {
                    qnaEl.hidden = false;
                    renderQna();
                }
            } else if (responseType === 'wordcloud') {
                wordcloudEl.hidden = false;
                renderWordcloud();
                if (quizState.moderationRequired) {
                    qnaEl.hidden = false;
                    renderQna();
                }
            } else if (responseType === 'qna') {
                qnaEl.hidden = false;
                renderQna();
            }
        }

        function poll() {
            if (!joinCode || pollInFlight) {
                return;
            }
            pollInFlight = true;
            request('/sessions/' + joinCode, 'GET').then(function (data) {
                pollInFlight = false;
                quizState = data.state || quizState;
                latestResponses = Array.isArray(quizState.responses)
                    ? quizState.responses
                    : (quizState.votes || []);
                renderResults();
            }).catch(function () {
                pollInFlight = false;
                if (pollHandle) {
                    window.clearInterval(pollHandle);
                    pollHandle = null;
                }
            });
        }

        function buildState() {
            responseType = selectedResponseType();
            var question = (questionInput.value || '').trim();
            if (!question) {
                throw new Error('질문 또는 안내를 입력하세요.');
            }
            var state = {
                responseType: responseType,
                question: question,
                revealed: false,
                acceptingResponses: true,
                blockedWords: (blockedWordsInput.value || '').split(',').map(function (word) {
                    return word.trim();
                }).filter(Boolean).slice(0, 50),
                moderationRequired: responseType === 'qna' || moderationRequiredInput.checked,
                responses: [],
            };
            if (responseType === 'choice') {
                readOptionsFromInputs();
                var options = optionValues.map(function (value) { return value.trim(); }).filter(Boolean);
                if (options.length < 2) {
                    throw new Error('선택지를 2개 이상 입력하세요.');
                }
                if (gradedInput.checked && (correctIndex === null || correctIndex >= options.length)) {
                    throw new Error('정답 선택지를 지정하세요.');
                }
                state.options = options;
                state.correctIndex = gradedInput.checked ? correctIndex : null;
                state.votes = [];
            } else if (responseType === 'scale') {
                state.scaleMin = 1;
                state.scaleMax = parseInt(scaleMaxInput.value, 10) || 5;
                state.scaleLowLabel = (scaleLowInput.value || '').trim();
                state.scaleHighLabel = (scaleHighInput.value || '').trim();
            }
            return state;
        }

        gradedInput.addEventListener('change', function () {
            readOptionsFromInputs();
            renderOptionRows();
        });

        addOptionButton.addEventListener('click', function () {
            readOptionsFromInputs();
            if (optionValues.length >= MAX_OPTIONS) {
                showMessages([{ type: 'warning', text: '선택지는 최대 ' + MAX_OPTIONS + '개까지 만들 수 있습니다.' }]);
                return;
            }
            optionValues.push('');
            renderOptionRows();
        });

        root.querySelector('[data-field="response-types"]').addEventListener('change', updateTypeFields);

        startButton.addEventListener('click', function () {
            var state;
            try {
                state = buildState();
            } catch (error) {
                showMessages([{ type: 'warning', text: error.message }]);
                return;
            }
            var mode = root.querySelector('input[name="htt-quiz-mode"]:checked').value;
            quizState = state;
            latestResponses = [];

            request('/sessions', 'POST', { toolType: 'quiz', mode: mode, state: state }).then(function (data) {
                joinCode = data.joinCode;
                setup.hidden = true;
                resultEl.hidden = false;
                joinInfo.hidden = mode !== 'live';
                joinCodeEl.textContent = joinCode;
                questionDisplayEl.textContent = state.question;
                showMessages([]);
                renderResults();
                if (mode === 'live') {
                    pollHandle = window.setInterval(poll, 1000);
                }
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        });

        copyLinkButton.addEventListener('click', function () {
            var link = config.joinUrl + '?code=' + joinCode;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(link).then(function () {
                    showMessages([{ type: 'info', text: '참여 링크를 복사했습니다.' }]);
                }).catch(function () { window.prompt('참여 링크', link); });
            } else {
                window.prompt('참여 링크', link);
            }
        });

        revealButton.addEventListener('click', function () {
            if (!joinCode || quizState.revealed) {
                return;
            }
            request('/sessions/' + joinCode + '/quiz-reveal', 'POST', { revealed: true }).then(function () {
                quizState.revealed = true;
                renderResults();
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        });

        toggleAcceptingButton.addEventListener('click', function () {
            if (!joinCode) {
                return;
            }
            var accepting = quizState.acceptingResponses === false;
            request('/sessions/' + joinCode + '/quiz-accepting', 'POST', { accepting: accepting }).then(function () {
                quizState.acceptingResponses = accepting;
                renderResults();
                showMessages([{ type: 'info', text: accepting ? '응답 접수를 다시 시작했습니다.' : '응답 접수를 마감했습니다.' }]);
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        });

        restartButton.addEventListener('click', function () {
            if (pollHandle) {
                window.clearInterval(pollHandle);
                pollHandle = null;
            }
            var endRequest = joinCode
                ? request('/sessions/' + joinCode + '/end', 'POST').catch(function () {})
                : Promise.resolve();
            endRequest.then(function () {
                joinCode = '';
                resultEl.hidden = true;
                setup.hidden = false;
                showMessages([]);
            });
        });

        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('빠른 응답 사용법', '<ul>'
                    + '<li>객관식, 척도, 한줄 응답, 워드클라우드 또는 익명 질문을 고르세요.</li>'
                    + '<li>학생 기기로 응답을 받으려면 참여 코드를 공유하세요.</li>'
                    + '<li>익명 질문은 교사에게도 이름이 표시되지 않으며, 공개한 질문만 학생 화면에 나타납니다.</li>'
                    + '<li>응답은 이 수업 세션에만 저장되고 세션이 정리되면 함께 삭제됩니다.</li>'
                    + '</ul>');
            });
        }

        renderOptionRows();
        updateTypeFields();
    });
})();
