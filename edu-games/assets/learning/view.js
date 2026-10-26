(() => {
    'use strict';
    const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const modes = { guided: '안내 연습', practice: '독립 연습', assessment: '평가' };
    const levels = { all: '혼합', easy: '기초', normal: '보통', hard: '심화' };
    const colors = ['#287c69', '#bd7040', '#426ab5', '#9e4974', '#818328', '#4b929e'];
    const tokens = (value) => value.trim() ? value.split(',').map((part) => part.trim()).filter(Boolean) : [];

    class LearningGame {
        constructor(root) {
            this.root = root;
            this.config = JSON.parse(root.dataset.config);
            this.work = root.querySelector('[data-role="work"]');
            this.message = root.querySelector('[data-role="message"]');
            this.mode = root.querySelector('[data-role="mode"]');
            this.level = root.querySelector('[data-role="level"]');
            this.key = `edu-learning:${this.config.userId}:${this.config.gameId}:${this.config.version}`;
            this.state = null; this.busy = false; this.chart = null; this.lastRequest = null;
            root.addEventListener('click', (event) => {
                const command = event.target.closest('[data-command]');
                if (command) this.command(command.dataset.command);
                if (event.target.closest('[data-query-apply]')) this.applyQuery();
            });
            root.addEventListener('input', () => this.preview());
            root.addEventListener('change', (event) => {
                if (event.target.matches('[data-role="report-game"]')) { this.config.gameId = event.target.value; this.command('history'); return; }
                if (event.target.matches('[data-simulation-model]')) { this.renderSimulation(Number(event.target.value)); return; }
                if (event.target.matches('[data-row]')) {
                    const field = this.work.querySelector('[data-answer="rowIds"]');
                    if (field) {
                        const selected = tokens(field.value).filter((id) => id !== event.target.dataset.row);
                        if (event.target.checked) selected.push(event.target.dataset.row);
                        field.value = selected.join(', ');
                    }
                }
                this.preview();
            });
            if (this.config.reportOnly) { this.command('history'); return; }
            let saved; try { saved = localStorage.getItem(this.key); } catch (_) { /* Storage can be disabled. */ }
            this.saved = saved;
            root.querySelector('[data-command="resume"]').hidden = !saved;
            if (saved) this.command('resume'); else this.command('start');
        }

        async api(path, data) {
            const response = await fetch(`${this.config.restUrl}/learning/${this.config.gameId}/${path}`, {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.config.nonce }, body: JSON.stringify(data),
            });
            const result = await response.json();
            if (!response.ok) { const error = new Error(result.message || '요청을 처리하지 못했습니다.'); error.status = response.status; throw error; }
            return result;
        }

        setBusy(value) {
            this.busy = value;
            this.root.querySelectorAll('button').forEach((button) => { button.disabled = value; });
            this.root.querySelectorAll('[data-role="mode"], [data-role="level"], [data-role="report-game"]').forEach((input) => { input.disabled = value; });
            this.root.setAttribute('aria-busy', value ? 'true' : 'false');
        }

        async command(action) {
            if (this.busy) return;
            this.setBusy(true); this.message.textContent = '';
            try {
                if (action === 'history') { await this.history(); return; }
                let result;
                if (action === 'start') result = await this.api('start', { mode: this.mode.value, level: this.level.value });
                else if (action === 'resume') result = await this.api('resume', { token: this.state?.token || this.saved });
                else {
                    if (!this.state?.question) return;
                    const payload = { token: this.state.token, revision: this.state.revision, questionId: this.state.question.id, action };
                    if (action === 'answer') payload.answer = this.answer();
                    const signature = JSON.stringify(payload);
                    if (this.lastRequest?.signature === signature) payload.requestId = this.lastRequest.id;
                    else { payload.requestId = crypto.randomUUID(); this.lastRequest = { signature, id: payload.requestId }; }
                    result = await this.api('update', payload);
                }
                this.state = result; this.saved = result.token; this.lastRequest = null;
                try { localStorage.setItem(this.key, result.token); } catch (_) { /* Session still works without local storage. */ }
                this.mode.value = result.mode; this.level.value = result.level;
                this.root.querySelector('[data-command="resume"]').hidden = false;
                this.render();
            } catch (error) {
                this.message.textContent = error.message;
                if (error.status === 409 && this.state?.token) {
                    try { this.state = await this.api('resume', { token: this.state.token }); this.render(); } catch (_) { /* Keep the entered answer on connection failure. */ }
                }
            } finally { this.setBusy(false); this.lockFields(); }
        }

        answer() {
            const answer = {};
            for (const field of this.state.question.fields) {
                const inputs = [...this.work.querySelectorAll('[data-answer]')].filter((input) => input.dataset.answer === field.key);
                if (field.type === 'multi') answer[field.key] = [...new Set(inputs.filter((input) => input.checked).map((input) => input.value))];
                else if (field.type === 'number') answer[field.key] = inputs[0]?.value.trim() ? Number(inputs[0].value) : null;
                else if (field.type === 'tokens') answer[field.key] = tokens(inputs[0]?.value || '');
                else answer[field.key] = inputs[0]?.value || '';
            }
            return answer;
        }

        table(rows, selectable = false) {
            if (!rows.length) return '<p>결과 행 없음</p>';
            const columns = Object.keys(rows[0]);
            return `<div class="edu-learn-table-wrap"><table><thead><tr>${selectable ? '<th scope="col">선택</th>' : ''}${columns.map((c) => `<th scope="col">${escape(c)}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${selectable ? `<td><input type="checkbox" data-row="${escape(row[columns[0]])}" aria-label="${escape(row[columns[0]])} 행 선택"></td>` : ''}${columns.map((c) => `<td class="${row[c] === null ? 'is-null' : ''}">${row[c] === null ? '미기록' : escape(row[c])}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
        }

        field(field, value) {
            const key = escape(field.key);
            const check = this.state.record?.result.checks.find((item) => item.key === field.key);
            const cls = check ? (check.correct ? ' is-right' : ' is-wrong') : '';
            let control;
            if (field.type === 'multi') {
                control = `<fieldset><legend>${escape(field.label)}</legend><div class="edu-learn-options">${Object.entries(field.options).map(([id, label]) => `<label><input type="checkbox" data-answer="${key}" value="${escape(id)}" ${(value || []).includes(id) ? 'checked' : ''}>${escape(label)}</label>`).join('')}</div></fieldset>`;
            } else if (field.type === 'choice') {
                control = `<label>${escape(field.label)}<select data-answer="${key}"><option value="">선택</option>${Object.entries(field.options).map(([id, label]) => `<option value="${escape(id)}" ${value === id ? 'selected' : ''}>${escape(label)}</option>`).join('')}</select></label>`;
            } else {
                control = `<label>${escape(field.label)}<input type="${field.type === 'number' ? 'number' : 'text'}" ${field.type === 'number' ? 'step="any"' : ''} data-answer="${key}" value="${escape(Array.isArray(value) ? value.join(', ') : value ?? '')}" ${field.type === 'tokens' ? 'placeholder="A, B, C" autocomplete="off" spellcheck="false"' : ''}></label>${field.type === 'tokens' ? `<div class="edu-learn-token-preview" data-tokens="${key}"></div>` : ''}`;
            }
            const pixels = field.pixels ? `<div class="edu-learn-digit" role="img" aria-label="${escape(field.label)} 숫자 이미지">${field.pixels.map((v) => `<span style="background:rgb(${255 - Math.round(v / 16 * 255)},${255 - Math.round(v / 16 * 255)},${255 - Math.round(v / 16 * 255)})"></span>`).join('')}</div>` : '';
            return `<div class="edu-learn-field${cls}${pixels ? ' edu-learn-digit-item' : ''}">${pixels}${control}</div>`;
        }

        render() {
            if (this.chart) { this.chart.destroy(); this.chart = null; }
            const state = this.state;
            this.root.querySelector('[data-role="status"]').textContent = `${modes[state.mode]} · ${levels[state.level]} · ${Math.min(state.position, state.summary.total)}/${state.summary.total} · 첫 정답 ${state.summary.firstCorrect} · 수정 후 정답 ${state.summary.finalCorrect}`;
            if (state.done) {
                this.work.innerHTML = this.summaryMarkup(state.summary);
                this.work.querySelector('h3').focus();
                return;
            }
            const q = state.question;
            const values = state.record?.finalAnswer || {};
            const chart = ['chart', 'classifier'].includes(q.visual.type);
            this.work.innerHTML = `<div class="edu-learn-prompt"><h3 tabindex="-1">${escape(q.skill)}</h3><p>${escape(q.prompt)}</p></div>
                ${state.hint ? `<div class="edu-learn-hint">${escape(state.hint)}</div>` : ''}
                <div class="edu-learn-columns"><section aria-label="문제 자료">${this.table(q.data.rows || [], q.visual.type === 'extract')}${chart ? '<div class="edu-learn-chart"><canvas role="img" aria-label="데이터 그래프"></canvas></div>' : ''}<div class="edu-learn-preview" data-role="preview"></div></section>
                <section class="edu-learn-fields ${q.visual.type === 'digits' ? 'edu-learn-digit-fields' : ''}" aria-label="답안">${q.fields.map((field) => this.field(field, values[field.key])).join('')}</section></div>
                <div class="edu-learn-actions"><button type="button" data-command="answer">${state.record ? '수정 답안 제출' : '답안 제출'}</button>${state.mode !== 'assessment' && !state.record ? '<button type="button" data-command="hint">판단 힌트</button>' : ''}${state.record ? '<button type="button" data-command="next">다음 문항</button>' : ''}</div>
                ${state.record ? this.resultMarkup(q, state.record) : ''}`;
            this.work.querySelectorAll('[data-row]').forEach((input) => { input.checked = (values.rowIds || []).includes(input.dataset.row); });
            this.preview(); this.renderSimulation(0); this.lockFields();
            const source = this.work.querySelector('[aria-label="문제 자료"]');
            if (q.visual.type === 'extract') source.insertAdjacentHTML('afterbegin', this.queryBuilder(q));
            if (q.visual.type === 'code') source.insertAdjacentHTML('beforeend', `<pre class="edu-learn-code"><code>${escape(q.visual.code)}</code></pre>`);
            if (q.visual.type === 'device') source.insertAdjacentHTML('beforeend', `<img class="edu-learn-device" src="${escape(q.visual.image)}" alt="${escape(q.visual.alt)}">`);
            this.work.querySelector(state.record ? '.edu-learn-result h4' : 'h3')?.focus();
        }

        queryBuilder(q) {
            const columns = Object.keys(q.data.rows[0] || {});
            const options = '<option value="">없음</option>' + columns.map((c) => `<option value="${escape(c)}">${escape(c)}</option>`).join('');
            return `<details class="edu-learn-query"><summary>조건·정렬 구성</summary>${[0, 1].map((i) => `<div class="edu-learn-query-row"><label>조건 ${i + 1} 열<select data-query-column="${i}">${options}</select></label><label>관계<select data-query-op="${i}"><option value="eq">같음</option><option value="lt">미만</option><option value="lte">이하</option><option value="gt">초과</option><option value="gte">이상</option><option value="notNull">기록 있음</option></select></label><label>비교값<input data-query-value="${i}" type="text"></label></div>`).join('')}<p>두 조건을 모두 만족하는 행</p><div class="edu-learn-query-row"><label>정렬 열<select data-query-sort>${options}</select></label><label>순서<select data-query-order><option value="asc">오름차순</option><option value="desc">내림차순</option></select></label><label>동률 기준<select data-query-tie>${options}</select></label></div><button type="button" data-query-apply>결과 행 만들기</button></details>`;
        }

        applyQuery() {
            if (this.busy || !this.state?.question || (this.state.record && (this.state.mode === 'assessment' || this.state.record.result.correct))) return;
            const q = this.state.question;
            try {
                const conditions = [0, 1].map((i) => {
                    const column = this.work.querySelector(`[data-query-column="${i}"]`).value;
                    const op = this.work.querySelector(`[data-query-op="${i}"]`).value;
                    let value = this.work.querySelector(`[data-query-value="${i}"]`).value;
                    if (column && op !== 'notNull' && typeof q.data.rows.find((r) => r[column] !== null)?.[column] === 'number') {
                        if (!value.trim() || !Number.isFinite(Number(value))) throw Error('숫자 열의 비교값을 입력하세요.');
                        value = Number(value);
                    }
                    return { column, op, value };
                });
                let rows = q.data.rows.filter((row) => conditions.every(({ column, op, value }) => {
                    if (!column) return true;
                    if (op === 'notNull') return row[column] !== null;
                    if (row[column] === null) return false;
                    return { eq: row[column] === value, lt: row[column] < value, lte: row[column] <= value, gt: row[column] > value, gte: row[column] >= value }[op];
                }));
                const sort = this.work.querySelector('[data-query-sort]').value;
                const tie = this.work.querySelector('[data-query-tie]').value;
                const direction = this.work.querySelector('[data-query-order]').value === 'desc' ? -1 : 1;
                const compare = (a, b, key) => a[key] === b[key] ? 0 : a[key] === null ? 1 : b[key] === null ? -1 : a[key] < b[key] ? -1 : 1;
                if (sort) rows = rows.slice().sort((a, b) => direction * compare(a, b, sort) || (tie ? compare(a, b, tie) : 0));
                this.work.querySelector('[data-answer="rowIds"]').value = rows.map((row) => Object.values(row)[0]).join(', ');
                this.message.textContent = ''; this.preview();
            } catch (error) { this.message.textContent = error.message; }
        }

        lockFields() {
            const locked = Boolean(this.state?.record && (this.state.mode === 'assessment' || this.state.record.result.correct || this.state.record.attempts >= 10));
            this.work.querySelectorAll('input, select:not([data-simulation-model])').forEach((input) => { input.disabled = locked || this.busy; });
            const submit = this.work.querySelector('[data-command="answer"]'); if (submit) submit.hidden = locked;
        }

        resultMarkup(question, record) {
            const value = (check) => {
                const field = question.fields.find((item) => item.key === check.key);
                const format = (v) => field.options?.[v] ?? v;
                if (Array.isArray(check.expected)) return check.expected.length ? check.expected.map(format).join(field.type === 'choice' ? ' 또는 ' : ', ') : '없음';
                return String(check.expected);
            };
            const labels = record.result.labeling;
            const labeling = labels ? `<p>관찰한 라벨 중 정확도 ${labels.accuracyOfDecided}% · 판단 완료 ${labels.coverage}% · 보류 ${labels.deferred}개 · 전체 ${labels.total}개 중 정답 ${labels.correct}개</p>` : '';
            const simulation = record.result.simulation;
            const comparison = simulation ? `<h4>정답 개수별 사전 실험 비교</h4><p>현재 답안 자체로 재훈련한 결과가 아닙니다. 훈련 ${simulation.trainingCount}개, 비교용 검증 ${simulation.validationCount}개, 별도 평가 ${simulation.reservedTestCount}개 보존. scikit-learn ${escape(simulation.engineVersion)}</p><label>비교 모델<select data-simulation-model>${simulation.models.map((m, i) => `<option value="${i}">${escape(m.title)}</option>`).join('')}</select></label><div data-role="simulation"></div>` : (labels ? '<p>보류한 라벨을 확정하면 사전 실험과 비교할 수 있습니다.</p>' : '');
            return `<section class="edu-learn-result"><h4 tabindex="-1">${record.result.correct ? '정답입니다' : '다시 확인할 부분이 있습니다'}</h4><p>첫 답: ${record.firstCorrect ? '정답' : '오답'} · 제출 ${record.attempts}회${record.hintUsed ? ' · 도움 사용' : ''}</p>${labeling}<p>${escape(record.result.explanation)}</p><ul>${record.result.checks.filter((check) => !check.correct).map((check) => `<li>${escape(check.label)}: ${escape(value(check))}</li>`).join('')}</ul>${comparison}</section>`;
        }

        renderSimulation(index) {
            const simulation = this.state?.record?.result.simulation;
            const node = this.work.querySelector('[data-role="simulation"]');
            if (!simulation || !node) return;
            const model = simulation.models[index]; if (!model) return;
            const metricRows = [['accuracy', '정확도'], ['precision', '정밀도(매크로)'], ['recall', '재현율(매크로)'], ['f1', 'F1(매크로)']].map(([key, label]) => ({ '지표': label, '대표 실험': `${(model[key] * 100).toFixed(2)}%`, '반복 실험 평균': `${(model.mean[key] * 100).toFixed(2)}%` }));
            node.innerHTML = `${this.table(metricRows)}<p>반복 ${model.trials}회 · 정확도 표준편차 ${(model.std * 100).toFixed(2)}%p</p><h4>대표 실험 혼동행렬 · 행=실제, 열=예측</h4>${this.table(model.matrix.map((row, i) => Object.fromEntries([['실제', simulation.labels[i]], ...row.map((value, j) => [`예측 ${simulation.labels[j]}`, value])])))}<p>위 행렬과 대표 실험 지표는 같은 실험입니다. 반복 평균은 별도 열로 표시합니다.</p>`;
        }

        summaryMarkup(summary) {
            return `<section class="edu-learn-summary"><h3 tabindex="-1">세트 결과</h3><dl><div><dt>첫 정답</dt><dd>${summary.firstCorrect}/${summary.total}</dd></div><div><dt>수정 후 정답</dt><dd>${summary.finalCorrect}/${summary.total}</dd></div><div><dt>첫 답 점수</dt><dd>${summary.score}</dd></div><div><dt>도움 사용</dt><dd>${summary.hintCount}</dd></div></dl>${this.table(summary.skills.map((skill) => ({ '학습 요소': skill.skill, '첫 답': skill.firstCorrect ? '정답' : '오답', '최종 답': skill.finalCorrect ? '정답' : '오답', '제출 횟수': skill.attempts })))}</section>`;
        }

        preview() {
            if (!this.state?.question) return;
            const q = this.state.question;
            const answer = this.answer();
            this.work.querySelectorAll('[data-tokens]').forEach((node) => { node.innerHTML = (answer[node.dataset.tokens] || []).map((token) => `<span>${escape(token)}</span>`).join(''); });
            const area = this.work.querySelector('[data-role="preview"]');
            if (q.visual.type === 'extract') {
                const rows = (answer.rowIds || []).map((id) => {
                    const original = q.data.rows.find((row) => String(Object.values(row)[0]) === id);
                    return Object.fromEntries((answer.columns || []).map((column) => [column, column === '1인당' ? answer[`value-${id}`] : original?.[column] ?? null]));
                });
                area.innerHTML = `<h4>추출 결과</h4>${answer.columns?.length ? this.table(rows) : ''}`;
                this.work.querySelectorAll('[data-row]').forEach((input) => { input.checked = answer.rowIds.includes(input.dataset.row); });
            } else if (q.visual.type === 'aggregate') area.innerHTML = `<h4>집계 결과</h4>${this.table(q.visual.groups.map((id) => ({ '구역': id, '합계(L)': answer[id] })))}`;
            if (['chart', 'classifier'].includes(q.visual.type)) this.drawChart(q, answer);
        }

        drawChart(q, answer) {
            const canvas = this.work.querySelector('canvas'); if (!canvas || typeof window.Chart !== 'function') return;
            if (this.chart) this.chart.destroy();
            let type = answer.chart || 'bar'; let data; let options = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: false } } };
            if (q.visual.type === 'classifier') {
                type = 'scatter';
                data = { datasets: ['A', 'B'].map((label, i) => ({ label, data: q.data.rows.filter((row) => row['라벨'] === label).map((row) => ({ x: row['폭(mm)'], y: row['높이(mm)'], id: row['번호'] })), backgroundColor: colors[i], pointStyle: i ? 'triangle' : 'circle', pointRadius: 7 })) };
                data.datasets.push({ label: '새 점', data: [q.visual.query], pointStyle: 'rectRot', backgroundColor: '#30343b', pointRadius: 9 });
                if (q.visual.axis) {
                    const t = q.visual.threshold;
                    data.datasets.push({ label: `경계 ${q.visual.axis}=${t}`, data: q.visual.axis === 'x' ? [{ x: t, y: 0 }, { x: t, y: 12 }] : [{ x: 0, y: t }, { x: 12, y: t }], showLine: true, borderColor: '#747c78', borderDash: [4, 4], pointRadius: 0 });
                }
                options.plugins.legend.display = true;
                options.plugins.tooltip = { callbacks: { label: (context) => `${context.dataset.label} ${context.raw.id || ''} (${context.raw.x}, ${context.raw.y})` } };
                options.scales = { x: { min: 0, max: 12, ticks: { stepSize: 1 }, title: { display: true, text: '폭(mm)' } }, y: { min: 0, max: 12, ticks: { stepSize: 1 }, title: { display: true, text: '높이(mm)' } } };
            } else if (q.visual.histogram) {
                type = 'bar'; data = { labels: q.visual.labels, datasets: [{ label: '도수', data: q.visual.labels.map((_, index) => answer[`bin-${index}`] ?? 0), backgroundColor: colors[0], categoryPercentage: 1, barPercentage: 1 }] };
                options.scales = { y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: '도수' } }, x: { title: { display: true, text: '측정 구간' } } };
            } else {
                const x = answer.x, y = answer.y;
                const labels = q.data.rows.map((row) => row[x] ?? '');
                const values = q.data.rows.map((row) => typeof row[y] === 'number' ? row[y] : null);
                data = { labels, datasets: [{ label: y || '값', data: type === 'scatter' ? q.data.rows.map((row) => ({ x: typeof row[x] === 'number' ? row[x] : null, y: typeof row[y] === 'number' ? row[y] : null })) : values, backgroundColor: type === 'pie' ? colors : colors[0], borderColor: colors[0], borderWidth: 2 }] };
                if (type === 'stacked100') {
                    const total = values.reduce((a, b) => a + (b || 0), 0);
                    type = 'bar'; data = { labels: ['전체'], datasets: labels.map((label, i) => ({ label, data: [total ? values[i] / total * 100 : 0], backgroundColor: colors[i] })) };
                    options.indexAxis = 'y'; options.scales = { x: { stacked: true, max: 100, title: { display: true, text: '%' } }, y: { stacked: true } }; options.plugins.legend.display = true;
                } else if (type !== 'pie') {
                    if (type === 'histogram') type = 'bar';
                    options.scales = { x: { title: { display: true, text: x || '' } }, y: { min: typeof answer.baseline === 'number' ? answer.baseline : 0, title: { display: true, text: y || '' } } };
                } else options.plugins.legend.display = true;
            }
            this.chart = new window.Chart(canvas, { type, data, options });
        }

        async history() {
            const node = this.root.querySelector('[data-role="history"]'); node.hidden = false;
            const key = `${this.config.gameId}:${this.level.value}`;
            const pager = window.EduGamesRankPager.request(node, key, () => this.history());
            try {
            const result = await this.api('summary', { level: this.level.value, page: pager.page, perPage: pager.perPage });
            if (!window.EduGamesRankPager.accept(node, key, result, pager.ticket)) return;
            node.innerHTML = `<section class="edu-learn-summary"><h3>내 학습 기록</h3>${this.table(result.history.map((row) => ({ '시작(UTC)': row.started_at, '모드': modes[row.mode], '난이도': levels[row.level], '상태': row.completed_at ? '완료' : '진행 중', '첫 정답': `${row.summary.firstCorrect}/${row.summary.total}`, '수정 후': `${row.summary.finalCorrect}/${row.summary.total}` })))}<h3>평가 순위 · ${escape(levels[result.level])}</h3><p>개념 실습 2026.09 · 첫 답 점수 · 동점은 공동 순위</p><div data-assessment-ranking>${this.table(result.leaderboard.map((row, index, rows) => ({ '순위': row.rank ?? rows.findIndex((other) => Number(other.score) === Number(row.score)) + 1, '학습자': row.name, '점수': row.score })))}</div></section>`;
            window.EduGamesRankPager.mount(node, key, '[data-assessment-ranking] tbody tr');
            } catch (error) { window.EduGamesRankPager.fail(node, pager.ticket); }
        }
    }
    document.querySelectorAll('[data-learning]').forEach((root) => {
        const initialize = () => { if (!root.dataset.initialized) { root.dataset.initialized = 'true'; new LearningGame(root); } };
        const details = root.closest('details');
        if (details && !details.open) details.addEventListener('toggle', () => { if (details.open) initialize(); });
        else initialize();
    });
})();
