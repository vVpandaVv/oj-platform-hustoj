(() => {
    const UI = window.EduStrategyUI;
    if (!UI) return;
    const { esc, table, stars, button } = UI;
    class Strategy {
        constructor(root) {
            this.root = root; this.config = JSON.parse(root.dataset.config); this.state = null; this.pending = null; this.busy = false; this.selected = null; this.panel = 0; this.recordIndex = 0;
            this.level = this.field('level'); this.key = `edu-strategy:${this.config.userId}:${this.config.game}:${this.config.version}`;
            try { this.token = localStorage.getItem(this.key); } catch { this.token = null; }
            root.addEventListener('click', e => this.click(e)); root.addEventListener('submit', e => this.submit(e));
            root.addEventListener('input', e => { if (e.target.type === 'range') e.target.closest('label').querySelector('output').textContent = e.target.value; this.previewBoundary(e.target.closest('form')); });
            root.addEventListener('change', e => { if (e.target.name === 'op') this.filterFields(e.target.closest('form')); this.previewBoundary(e.target.closest('form')); });
            root.addEventListener('keydown', e => this.coordinateKey(e));
            this.level.addEventListener('change', () => { if (!this.state || this.state.status !== 'playing') { this.state = null; this.selected = null; this.panel = 0; this.render(); } this.summary(); });
            this.render(); this.resumeButton();
            const details = this.config.reportOnly ? root.closest('details') : null;
            if (details && !details.open) details.addEventListener('toggle', () => { if (details.open) this.summary(); }); else this.summary();
        }
        field(name) { return this.root.querySelector(`[data-role="${name}"]`); }
        message(text) { this.field('message').textContent = text; }
        resumeButton() { const b = this.root.querySelector('[data-command=resume]'); if (b) b.hidden = !this.token; }
        async api(action, payload) {
            const response = await fetch(`${this.config.restUrl}/${action}`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.config.nonce }, body: JSON.stringify(payload) });
            let body; try { body = await response.json(); } catch { throw Error('응답을 확인하지 못했습니다. 저장 재시도 또는 이어 하기로 확인하세요.'); }
            if (!response.ok) { const e = Error(body.message || '요청에 실패했습니다.'); e.status = response.status; throw e; } return body;
        }
        async summary() {
            const level = this.level.value;
            const key = `${this.config.game}:${level}`, container = this.field('leaders');
            const pager = window.EduGamesRankPager.request(container, key, () => this.summary());
            try {
                const data = await this.api('summary', { level, page: pager.page, perPage: pager.perPage });
                if (!window.EduGamesRankPager.accept(container, key, data, pager.ticket) || level !== this.level.value) return; this.progress = data;
                this.field('collection').textContent = `수집한 별 ${data.stars} / ${data.maxStars}`;
                this.field('map').innerHTML = Object.values(data.levels).map(l => `<button type="button" data-level="${esc(l.id)}" ${!l.unlocked || this.busy || this.pending || this.state?.status === 'playing' ? 'disabled' : ''}>${l.number}. ${esc(l.title)}<small>${l.unlocked ? stars(l.stars) : '잠김'}</small></button>`).join('');
                [...this.level.options].forEach(o => o.disabled = !data.levels[o.value]?.unlocked);
                const record = data.levels[level];
                this.field('personal').textContent = record.score ? `내 최고 ${record.score}점 · ${stars(record.stars)} · ${Object.values(data.levels).every(l => l.score === 1000) ? '시즌 전체 목표 달성' : record.score === 1000 ? '다음 목표: 다른 보드의 별 수집' : '다음 목표: 같은 보드에서 처리 비용 줄이기'}` : '아직 완주 기록이 없습니다.';
                this.field('leaders').innerHTML = data.leaderboard.length ? table(data.leaderboard.map(r => ({ '순위': r.rank, '도전자': r.name, '점수': r.score }))) : '<p class="es-muted">완주 기록 없음</p>';
                window.EduGamesRankPager?.mount(this.field('leaders'), `${this.config.game}:${level}`, 'tbody tr');
            } catch (e) { window.EduGamesRankPager.fail(container, pager.ticket); }
        }
        async click(event) {
            const b = event.target.closest('button'); if (!b || !this.root.contains(b) || b.matches(':disabled') || this.busy) return;
            const command = b.dataset.command;
            if (command === 'refresh') { await this.summary(); return; }
            if (command === 'resume') { await this.resume(); return; }
            if (command === 'retry') { await this.send(); return; }
            if (command === 'archive') {
                try { const data = await this.api('archive', {}); this.field('archive').innerHTML = data.records.length ? table(data.records.map(r => ({ '시즌': r.version, '보드': r.level, '최고점': r.score, '완료 횟수': r.attempts }))) : '<p>이전 시즌 기록 없음</p>'; } catch (e) { this.message(e.message); } return;
            }
            if (this.pending) { this.message('저장 결과를 먼저 확인하세요. 저장 재시도 또는 이어 하기를 사용할 수 있습니다.'); return; }
            if (b.dataset.record !== undefined) {
                this.recordIndex = Number(b.dataset.record); this.focusTarget = null; this.render();
                if (b.hasAttribute('data-case-next') || window.matchMedia('(max-width:760px)').matches) this.root.querySelector('[data-case-heading]')?.focus();
                return;
            }
            if (command === 'readings') { this.root.querySelector(`[data-record="${this.recordIndex}"]`)?.focus(); return; }
            if (b.dataset.panel !== undefined) { this.panel = Number(b.dataset.panel); this.selected = null; this.focusTarget = null; this.render(); return; }
            if (b.dataset.level) { this.level.value = b.dataset.level; this.state = null; this.selected = null; this.panel = 0; this.render(); await this.summary(); return; }
            if (['start', 'replay', 'next'].includes(command)) {
                if (this.state?.status === 'playing' && !window.confirm('현재 진행을 남기고 새 도전을 시작할까요?')) return;
                if (command === 'next') this.level.value = b.dataset.next; await this.start(); return;
            }
            if (b.dataset.pick !== undefined) { this.selected = Number(b.dataset.pick); this.render(); return; }
            if (b.dataset.place !== undefined || b.dataset.pointX !== undefined) {
                if (this.selected === null) { this.message('배치할 자료 타일을 선택하세요.'); return; }
                const move = { type: 'place', row: this.selected, ...(this.panel ? { panel: this.panel } : {}) };
                if (b.dataset.pointX !== undefined) { move.x = Number(b.dataset.pointX); move.y = Number(b.dataset.pointY); } else move.slot = Number(b.dataset.place);
                await this.move(move); return;
            }
            if (b.dataset.move) await this.move(JSON.parse(b.dataset.move));
        }
        filterFields(f) {
            if (!f || f.dataset.type !== 'filter') return;
            const op = f.querySelector('[name=op]')?.value;
            for (const [selector, hidden] of [['[data-filter-value]', ['missing', 'present'].includes(op)], ['[data-filter-upper]', op !== 'range']]) {
                const wrapper = f.querySelector(selector); if (wrapper) { wrapper.hidden = hidden; wrapper.querySelectorAll('input').forEach(i => i.disabled = hidden); }
            }
        }
        previewBoundary(form) {
            if (this.config.game !== 'ai-classifier-lab' || form?.dataset.type !== 'boundary' || this.busy || this.pending) return;
            const s = this.state || this.config.previews[this.level.value]; if (s.status !== 'playing') return;
            const boundary = { axis: form.elements.axis.value, op: form.elements.op.value, cut: Number(form.elements.cut.value) };
            this.root.querySelector('.es-plane').innerHTML = UI.previewBoundary(s, JSON.parse(form.dataset.params).slot, boundary);
            this.root.querySelector('[data-boundary-preview]').textContent = `미배치 경계: ${boundary.axis.toUpperCase()} ${boundary.op === 'gte' ? '≥' : '<'} ${boundary.cut}`;
        }
        coordinateKey(e) {
            const b = e.target.closest('[data-point-x]'); if (!b) return;
            let x = Number(b.dataset.pointX), y = Number(b.dataset.pointY);
            if (e.key === 'ArrowLeft') x--; else if (e.key === 'ArrowRight') x++; else if (e.key === 'ArrowUp') y++; else if (e.key === 'ArrowDown') y--; else if (e.key === 'Home') x = 0; else if (e.key === 'End') x = 10; else return;
            e.preventDefault(); x = Math.max(0, Math.min(10, x)); y = Math.max(0, Math.min(10, y));
            this.root.querySelectorAll('[data-point-x]').forEach(n => n.tabIndex = -1);
            const target = this.root.querySelector(`[data-point-x="${x}"][data-point-y="${y}"]`); target.tabIndex = 0; target.focus();
        }
        async submit(event) {
            const f = event.target.closest('form[data-type]'); if (!f) return; event.preventDefault(); if (this.busy || this.pending) return;
            const move = { type: f.dataset.type, ...JSON.parse(f.dataset.params) };
            f.querySelectorAll('[name]:not(:disabled)').forEach(input => { move[input.name] = input.hasAttribute('data-number') ? Number(input.value) : input.value; });
            await this.move(move);
        }
        async move(move) {
            if (this.pending || this.busy || this.state && this.state.status !== 'playing') return;
            if (this.config.game === 'outlier-detection' && move.type === 'investigate') this.recordIndex = this.state?.marked[0] ?? 0;
            if (!this.state?.token) {
                this.busy = true; this.render();
                try { this.remember(await this.api('start', { level: this.level.value })); }
                catch (error) { this.message(error.message); }
                finally { this.busy = false; }
                if (!this.state?.token) { this.render(); return; }
            }
            this.pending = { token: this.state.token, revision: this.state.revision, requestId: crypto.randomUUID(), move }; await this.send();
            if (this.config.game === 'outlier-detection' && move.type === 'investigate' && this.state?.phase === 'investigate' && !this.pending) this.root.querySelector('[data-case-heading]')?.focus();
        }
        remember(state) {
            if (this.state?.level !== state.level) this.panel = 0;
            this.state = state; this.token = state.token; this.level.value = state.level;
            try { localStorage.setItem(this.key, this.token); } catch { /* Server records remain authoritative. */ }
            const plot = state.plots?.[this.panel];
            if (plot && (plot.slots.flat().includes(this.selected) || Object.hasOwn(plot.positions, this.selected))) this.selected = null;
            this.resumeButton(); this.message(state.message);
        }
        async start() {
            this.busy = true; this.render();
            try { this.remember(await this.api('start', { level: this.level.value })); this.selected = null; this.panel = 0; this.recordIndex = 0; } catch (e) { this.message(e.message); }
            finally { this.busy = false; this.render(); await this.summary(); }
        }
        async resume() {
            if (!this.token) return; this.busy = true; this.render();
            try { this.remember(await this.api('resume', { token: this.token })); this.pending = null; }
            catch (e) { this.message(e.message); if (e.status === 404) { this.token = null; this.pending = null; this.state = null; this.panel = 0; this.selected = null; try { localStorage.removeItem(this.key); } catch {} } }
            finally { this.busy = false; this.resumeButton(); this.render(); await this.summary(); }
        }
        async send() {
            if (!this.pending || this.busy) return; this.busy = true; this.render();
            try { const state = await this.api('update', this.pending); this.pending = null; this.remember(state); }
            catch (e) { this.message(e.message); if (e.status === 400) this.pending = null; }
            finally { this.busy = false; this.render(); if (this.state?.status !== 'playing') await this.summary(); }
        }
        render() {
            const openDetails = [...this.root.querySelectorAll('[data-role=board] details[data-detail][open]')].map(d => d.dataset.detail);
            const focused = document.activeElement;
            if (this.field('board')?.contains(focused)) {
                const f = focused.closest('form');
                this.focusTarget = f ? { form: f.dataset.type, params: f.dataset.params, name: focused.name || '' } : { ...focused.dataset };
                this.focusSlot = [...this.root.querySelectorAll('.es-slot,.es-node,.es-boundary')].indexOf(focused.closest('.es-slot,.es-node,.es-boundary'));
            }
            this.root.setAttribute('aria-busy', String(this.busy)); this.level.disabled = this.busy || !!this.pending || this.state?.status === 'playing';
            this.root.querySelector('[data-command=retry]').hidden = !this.pending;
            this.root.querySelectorAll('[data-command]').forEach(b => b.disabled = this.busy);
            this.root.querySelectorAll('[data-level]').forEach(b => b.disabled = this.busy || !!this.pending || this.state?.status === 'playing' || !this.progress?.levels[b.dataset.level]?.unlocked);
            if (this.config.reportOnly) return;
            this.chart?.destroy(); this.chart = null;
            const s = this.state || this.config.previews[this.level.value], playing = s.status === 'playing', active = (playing || this.config.game === 'outlier-detection') && !this.busy && !this.pending;
            if (s.plots && !s.plots[this.panel]) this.panel = 0;
            const ids = Object.keys(this.config.previews), next = ids[ids.indexOf(s.level) + 1];
            const tabs = s.plots?.length > 1 ? `<nav class="es-plot-tabs" aria-label="작업판">${s.plots.map((p, i) => `<button type="button" data-panel="${i}" aria-pressed="${this.panel === i}" ${this.busy || this.pending ? 'disabled' : ''}>${i + 1}. ${esc(p.title)}</button>`).join('')}</nav>` : '';
            const finish = { 'data-extraction': '출고 중단', 'missing-values': '운영 중단', 'outlier-detection': '조사 종료 · 1', 'data-visualization': '제작 완료 · 1', 'ai-classifier-lab': '최종 평가 · 1', 'ai-model-lab': '배포 · 1' }[this.config.game];
            const resource = { 'outlier-detection': '조사권', 'missing-values': '복구 전력', 'ai-model-lab': '연구 예산', 'ai-classifier-lab': '실험 예산' }[this.config.game] || '작업권';
            const finishDisabled = this.config.game === 'ai-model-lab' ? !s.validated : this.config.game === 'data-visualization' ? s.plots.some(p => (p.kind === 'scatter' ? Object.keys(p.positions).length : p.slots.flat().length) !== p.rows.length) : this.config.game === 'ai-classifier-lab' ? !s.validation : false;
            const finishControl = ['data-extraction', 'missing-values'].includes(this.config.game) ? `<details class="es-technical"><summary>진행 중단</summary>${button(finish, { type: 'finish' })}</details>` : this.config.game === 'outlier-detection' ? '' : `<div class="es-section">${button(finish, { type: 'finish' }, finishDisabled)}</div>`;
            this.field('board').innerHTML = `<section class="es-goal"><h3>${esc(s.title)}</h3><p>${esc(s.goal)}</p><div class="es-hud"><strong>${resource} ${s.budget - s.spent} / ${s.budget}</strong><progress class="es-energy" max="${s.budget}" value="${s.budget - s.spent}" aria-label="남은 ${resource}"></progress><span>행동 ${s.moves}회</span></div></section>${tabs}<fieldset class="es-game-area" ${active ? '' : 'disabled'}>${UI.render(this.config.game, s, this)}${playing ? finishControl : ''}</fieldset>${!playing ? `<section class="es-result ${s.status === 'lost' ? 'is-lost' : ''}" tabindex="-1"><h3>${s.status === 'won' ? '도전 성공' : '도전 종료'} · ${s.score}점 · ${stars(s.stars)}</h3><p>${esc(s.message)}</p>${Object.keys(s.evaluation).length ? table(Object.entries(s.evaluation).map(([key, value]) => ({ '항목': key, '결과': value }))) : ''}<div class="es-controls"><button type="button" data-command="replay">같은 보드 재도전</button>${s.status === 'won' && next ? `<button type="button" data-command="next" data-next="${next}">다음 보드</button>` : ''}</div></section>` : ''}`;
            this.root.querySelectorAll('form[data-type=filter]').forEach(f => this.filterFields(f));
            this.root.querySelectorAll('[data-role=board] details[data-detail]').forEach(d => d.open = openDetails.includes(d.dataset.detail));
            if (s.plots) this.chart = UI.chart(this.root.querySelector('[data-chart]'), s.plots[this.panel]);
            if (!this.busy && !this.pending) this.restoreFocus(playing);
        }
        restoreFocus(playing) {
            let target;
            if (!playing && this.focusTarget?.panel !== undefined) target = this.root.querySelector(`[data-panel="${this.focusTarget.panel}"]`);
            else if (!playing) target = this.root.querySelector('.es-result');
            else if (this.focusTarget) {
                const k = this.focusTarget;
                if (k.form) { const f = [...this.root.querySelectorAll('form')].find(f => f.dataset.type === k.form && f.dataset.params === k.params); target = f && [...f.elements].find(e => (e.name || '') === k.name); }
                else if (k.pointX !== undefined) {
                    target = this.root.querySelector(`[data-point-x="${k.pointX}"][data-point-y="${k.pointY}"]`);
                    if (target) { this.root.querySelectorAll('[data-point-x]').forEach(n => n.tabIndex = -1); target.tabIndex = 0; }
                }
                else target = [...this.root.querySelectorAll('button')].find(b => ['move', 'pick', 'place', 'panel', 'record', 'command'].some(key => k[key] !== undefined && b.dataset[key] === k[key]));
                if (this.config.game === 'outlier-detection' && (!target || target.matches(':disabled'))) target = this.root.querySelector('[data-case-heading]');
                if (!target || target.matches(':disabled')) target = this.root.querySelectorAll('.es-slot,.es-node,.es-boundary')[this.focusSlot]?.querySelector('button:not(:disabled),select:not(:disabled)');
            }
            if (target && !target.matches(':disabled')) target.focus({ preventScroll: true });
        }
    }
    const init = () => document.querySelectorAll('[data-strategy]').forEach(root => { if (!root.dataset.strategyInitialized) { root.dataset.strategyInitialized = '1'; root.__Strategy = new Strategy(root); } });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
