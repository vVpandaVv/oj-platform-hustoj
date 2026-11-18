(() => {
    'use strict';

    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[char]));
    const languageLabel = language => ({
        ko: '한국어', en: '영어', korean: '한국어', english: '영어',
        python: 'Python', javascript: 'JavaScript', java: 'Java', cpp: 'C++', c: 'C'
    }[language] || language);
    const statusLabel = status => ({
        draft: '초안', open: '응시 가능', closed: '응시 마감',
        absent: '미응시', running: '응시 중', finished: '완료'
    }[status] || status || '-');
    const badge = status => {
        const tone = ({ open: 'success', finished: 'success', running: 'warning' })[status] || 'neutral';
        return `<span class="ojp-status-badge ojp-status-badge--${tone}">${esc(statusLabel(status))}</span>`;
    };
    const metric = value => value === null || value === undefined || value === '' || !Number.isFinite(Number(value))
        ? '-' : Number(value).toLocaleString('ko-KR', { maximumFractionDigits: 1 });
    const accuracy = value => metric(value) === '-' ? '-' : `${Number(value).toLocaleString('ko-KR',{maximumFractionDigits:2})}%`;
    const qualified = row => row.verificationIssue ? '<span class="ojp-status-badge ojp-status-badge--warning">전송 확인</span>' : (row.resultStatus||row.status) !== 'finished' ? '-' : row.qualified === true
        ? '<span class="ojp-status-badge ojp-status-badge--success">충족</span>'
        : row.qualified === false ? '<span class="ojp-status-badge ojp-status-badge--warning">기준 미달</span>' : '-';
    const contextKey = item => JSON.stringify([item.type, String(item.id)]);
    const requireArray = value => {
        if (!Array.isArray(value)) throw new Error('응답 형식이 올바르지 않습니다. 다시 불러와 주세요.');
        return value;
    };
    // Quote every cell; protect formulas even when preceded by whitespace.
    const csvCell = value => {
        let text = String(value ?? '');
        if (/^\s*[=+@-]|^[\t\r\n]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    };

    class TypingEvaluations {
        constructor(root) {
            this.root = root;
            this.config = JSON.parse(root.dataset.config);
            this.view = 'list';
            this.evaluations = [];
            this.contexts = [];
            this.selectedContexts = new Map();
            this.cursor = '';
            this.contextsLoaded = false;
            this.catalogLoaded = false;
            this.contextsLoading = false;
            this.catalogLoading = false;
            this.samples = [];
            this.sampleSelections = new Map();
            this.listRequest = 0;
            this.resultsRequest = 0;
            this.attemptsRequest = 0;
            this.exportRequest = 0;
            this.page = 1;
            this.perPage = 20;
            this.resultFilters = {status:'all',quality:'all',group:'',search:'',sort:'name',direction:'asc'};
            this.saving = false;
            this.statusSaving = false;
            root.querySelectorAll('[data-icon]').forEach(button => {
                button.innerHTML = this.icon(button.dataset.icon);
                button.classList.add('ete-icon-button');
            });
            root.addEventListener('click', event => {
                const button = event.target.closest('button[data-action]');
                if (button && root.contains(button) && !button.disabled) this.action(button);
            });
            root.addEventListener('change', event => this.change(event.target));
            root.addEventListener('input',event=>{if(event.target.matches('[data-result-search]')){window.clearTimeout(this.searchTimer);this.searchTimer=window.setTimeout(()=>{this.resultFilters.search=event.target.value.trim();this.loadResults(1);},250);}});
            this.field('attempts-section').addEventListener('close',()=>{this.attemptsRequest++;});
            this.field('create-form').addEventListener('submit', event => {
                event.preventDefault();
                this.createEvaluation();
            });
            this.loadEvaluations();
        }

        field(name) { return this.root.querySelector(`[data-field="${name}"]`); }
        control(name) { return this.field('create-form').elements.namedItem(name); }
        icon(name) {
            if (!this.config.iconsUrl) return `<span aria-hidden="true">${({ 'arrow-left': '&#8592;', 'arrow-right': '&#8594;', x: '&#215;', 'refresh-cw': '&#8635;' })[name] || ''}</span>`;
            return `<svg class="ete-icon" aria-hidden="true" focusable="false"><use href="${esc(this.config.iconsUrl)}#${esc(name)}"></use></svg>`;
        }
        iconButton(action, icon, label, extra = '', disabled = false) {
            return `<button type="button" class="ojp-button ojp-button--plain ete-icon-button" data-action="${action}" title="${esc(label)}" aria-label="${esc(label)}" ${extra} ${disabled ? 'disabled' : ''}>${this.icon(icon)}</button>`;
        }
        state(text, retry = '') {
            return `<div class="ete-state"><p role="status">${esc(text)}</p>${retry ? `<button type="button" class="ojp-button ojp-button--tinted ojp-button--small" data-action="${retry}">다시 시도</button>` : ''}</div>`;
        }
        message(name, text = '', error = false) {
            const element = this.field(name);
            element.textContent = text;
            element.classList.toggle('is-error', error);
        }
        setView(view) {
            window.clearTimeout(this.searchTimer);
            this.view = view;
            this.root.dataset.currentView=view;
            this.root.querySelectorAll('[data-view]').forEach(section => { section.hidden = section.dataset.view !== view; });
            this.message('message');
            this.resultsRequest++;
            this.exportRequest++;
            this.closeAttempts();
            const heading = this.root.querySelector(`[data-view="${view}"] h2`);
            heading.focus({ preventScroll: true });
        }

        async api(path, params = {}, body) {
            const url = new URL(this.config.restUrl, window.location.href);
            // WordPress can supply either pretty REST URLs or ?rest_route= URLs.
            if (url.searchParams.has('rest_route')) {
                url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + path);
            } else {
                url.pathname = url.pathname.replace(/\/$/, '') + path;
            }
            Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
            if (url.origin !== window.location.origin) throw new Error('API 주소가 현재 사이트와 일치하지 않습니다.');
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 30000);
            try {
                const response = await fetch(url.href, {
                    method: body === undefined ? 'GET' : 'POST',
                    credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                    headers: { 'X-WP-Nonce': this.config.nonce, Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) },
                    ...(body === undefined ? {} : { body: JSON.stringify(body) })
                });
                let data;
                try { data = await response.json(); } catch (error) { throw new Error('서버 응답을 읽지 못했습니다. 다시 시도해 주세요.'); }
                if (!response.ok) throw new Error(typeof data?.message === 'string' ? data.message : '요청을 처리하지 못했습니다. 다시 시도해 주세요.');
                if (!data || typeof data !== 'object') throw new Error('응답 형식이 올바르지 않습니다.');
                return data;
            } catch (error) {
                if (error.name === 'AbortError') throw new Error('응답 시간이 초과되었습니다. 다시 불러와 주세요.');
                if (error instanceof TypeError) throw new Error('서버에 연결하지 못했습니다. 연결 상태를 확인해 주세요.');
                throw error;
            } finally { window.clearTimeout(timeout); }
        }

        action(button) {
            const action = button.dataset.action;
            if (action === 'new') {
                this.setView('create');
                if (!this.contextsLoaded) this.loadContexts();
                if (!this.catalogLoaded) this.loadCatalog();
                this.updateCreateButton();
            }
            if (action === 'cancel-create' || action === 'back') { this.setView('list'); this.loadEvaluations(); }
            if (action === 'refresh-list') this.loadEvaluations();
            if (action === 'retry-contexts' || action === 'more-contexts') this.loadContexts();
            if (action === 'retry-catalog') this.loadCatalog();
            if (action === 'results') this.openResults(button.dataset.id);
            if (action === 'refresh-results' || action === 'retry-results') this.loadResults(this.page);
            if (action === 'page') this.loadResults(Number(button.dataset.page));
            if (action === 'change-status') this.changeStatus();
            if (action === 'export') this.exportCsv();
            if (action === 'copy-student-link') this.copyStudentLink();
            if (action === 'result-status') {this.resultFilters.status=button.dataset.status;this.loadResults(1);}
            if (action === 'result-sort') {const key=button.dataset.sort;this.resultFilters.direction=this.resultFilters.sort===key?(this.resultFilters.direction==='asc'?'desc':'asc'):['cpm','accuracy'].includes(key)?'desc':'asc';this.resultFilters.sort=key;this.loadResults(1);}
            if (action === 'reset-filters') {window.clearTimeout(this.searchTimer);this.resultFilters={status:'all',quality:'all',group:'',search:'',sort:'name',direction:'asc'};this.root.querySelector('[data-result-search]').value='';this.loadResults(1);}
            if (action === 'sample-up' || action === 'sample-down' || action === 'remove-sample') this.moveSample(button.dataset.id, action);
            if (action === 'attempts') this.loadAttempts(button.dataset.userId, button.dataset.name);
            if (action === 'retry-attempts' && this.student) this.loadAttempts(this.student.id, this.student.name);
            if (action === 'close-attempts') this.closeAttempts();
        }

        change(input) {
            if(input.matches('[data-result-group]')){this.resultFilters.group=input.value;this.loadResults(1);}
            if(input.matches('[data-result-quality]')){this.resultFilters.quality=input.value;this.loadResults(1);}
            if(input.matches('[data-result-order]')){[this.resultFilters.sort,this.resultFilters.direction]=input.value.split(':');this.loadResults(1);}
            if (input.matches('[data-per-page]')) { this.perPage = Number(input.value); this.loadResults(1); }
            if (input.name === 'language') { this.renderSamples(); this.updateCreateButton(); }
            if (input.matches('[data-context-index]')) {
                const item = this.contexts[Number(input.dataset.contextIndex)];
                if (item) {
                    if (input.checked) this.selectedContexts.set(contextKey(item), item);
                    else this.selectedContexts.delete(contextKey(item));
                    this.field('context-count').textContent = `${this.selectedContexts.size}개 선택`;
                    this.updateCreateButton();
                }
            }
            if (input.matches('[data-sample-id]')) {
                const ids = this.selectedSamples();
                const id = input.dataset.sampleId;
                this.sampleSelections.set(this.control('language').value, input.checked ? [...ids.filter(value => value !== id), id] : ids.filter(value => value !== id));
                this.renderSamples(id);
                this.updateCreateButton();
            }
            if (input.matches('[data-page-input]')) {
                const pages = Number(this.resultsData?.pagination.totalPages) || 1;
                if (input.checkValidity() && Number(input.value) >= 1 && Number(input.value) <= pages) this.loadResults(Number(input.value));
                else { input.reportValidity(); input.value = String(this.page); }
            }
        }

        async loadEvaluations() {
            const request = ++this.listRequest;
            const container = this.field('evaluations');
            const button = this.root.querySelector('[data-action="refresh-list"]');
            container.setAttribute('aria-busy', 'true');
            container.innerHTML = this.state('평가 목록을 불러오는 중입니다.');
            button.disabled = true;
            try {
                const data = await this.api('/typing/evaluations');
                if (request !== this.listRequest) return;
                this.evaluations = requireArray(data.evaluations);
                this.renderEvaluations();
            } catch (error) {
                if (request === this.listRequest) container.innerHTML = this.state(error.message, 'refresh-list');
            } finally {
                if (request === this.listRequest) { container.setAttribute('aria-busy', 'false'); button.disabled = false; }
            }
        }
        renderEvaluations() {
            this.field('evaluations').innerHTML = this.evaluations.length ? `<div class="ojp-table-wrap ete-table-scroll" tabindex="0" role="region" aria-label="평가 목록"><table class="ojp-table ete-table"><thead><tr><th scope="col">평가명</th><th scope="col">상태</th><th scope="col">대상 학급·그룹</th><th scope="col">언어</th><th scope="col">시간</th><th scope="col">최소 정확도</th><th scope="col">최대 응시</th><th scope="col">대상자</th></tr></thead><tbody>${this.evaluations.map(item => `<tr><th scope="row" class="ete-name"><button type="button" class="ete-text-button" data-action="results" data-id="${esc(item.id)}">${esc(item.title)}</button></th><td>${badge(item.status)}</td><td class="ete-groups">${esc((item.contextLabels || []).join(', ')) || '-'}</td><td>${esc(languageLabel(item.language))}</td><td>${esc(metric(Number(item.durationSeconds) / 60))}분</td><td>${esc(accuracy(item.minAccuracy))}</td><td>${esc(metric(item.maxAttempts))}회</td><td>${esc(metric(item.targetCount))}</td></tr>`).join('')}</tbody></table></div>` : this.state('등록된 평가가 없습니다.');
        }

        async loadContexts() {
            if (this.contextsLoading || (this.contextsLoaded && !this.cursor)) return;
            this.contextsLoading = true;
            const container = this.field('contexts');
            const more = this.root.querySelector('[data-action="more-contexts"]');
            container.setAttribute('aria-busy', 'true');
            more.disabled = true;
            if (!this.contexts.length) container.innerHTML = this.state('학급·그룹을 불러오는 중입니다.');
            this.message('contexts-message');
            try {
                const data = await this.api('/reports/contexts', { cursor: this.cursor });
                const items = requireArray(data.items);
                const seen = new Set(this.contexts.map(contextKey));
                items.forEach(item => { if (!seen.has(contextKey(item))) { this.contexts.push(item); seen.add(contextKey(item)); } });
                const next = String(data.nextCursor ?? '');
                if (next !== '' && String(next) === String(this.cursor)) throw new Error('다음 학급·그룹을 불러오지 못했습니다. 다시 시도해 주세요.');
                this.cursor = next;
                this.contextsLoaded = true;
                this.renderContexts();
                more.hidden = this.cursor === '';
            } catch (error) {
                if (!this.contexts.length) container.innerHTML = this.state(error.message, 'retry-contexts');
                else this.message('contexts-message', error.message, true);
                more.hidden = !this.contexts.length;
            } finally {
                this.contextsLoading = false;
                container.setAttribute('aria-busy', 'false');
                more.disabled = false;
                this.updateCreateButton();
            }
        }
        renderContexts() {
            this.field('contexts').innerHTML = this.contexts.length ? this.contexts.map((item, index) => `<label class="ete-check-row"><input type="checkbox" data-context-index="${index}" ${this.selectedContexts.has(contextKey(item)) ? 'checked' : ''}><span>${esc(item.label)}</span></label>`).join('') : this.state('담당 학급·그룹이 없습니다.');
            this.field('context-count').textContent = `${this.selectedContexts.size}개 선택`;
        }

        async loadCatalog() {
            if (this.catalogLoading) return;
            this.catalogLoading = true;
            const container = this.field('samples');
            container.setAttribute('aria-busy', 'true');
            container.innerHTML = this.state('평가 지문을 불러오는 중입니다.');
            this.message('samples-message');
            try {
                const data = await this.api('/typing/catalog');
                this.samples = requireArray(data.samples);
                const languages = [...new Set(this.samples.map(sample => sample.language))];
                this.control('language').innerHTML = languages.length ? languages.map(language => `<option value="${esc(language)}">${esc(languageLabel(language))}</option>`).join('') : '<option value="">사용 가능한 언어 없음</option>';
                this.control('language').disabled = !languages.length;
                if (languages.includes('ko')) this.control('language').value = 'ko';
                this.catalogLoaded = true;
                this.renderSamples();
            } catch (error) { container.innerHTML = this.state(error.message, 'retry-catalog'); }
            finally { this.catalogLoading = false; container.setAttribute('aria-busy', 'false'); this.updateCreateButton(); }
        }
        selectedSamples() { return this.sampleSelections.get(this.control('language').value) || []; }
        renderSamples(focusId) {
            const language = this.control('language').value;
            const samples = this.samples.filter(sample => sample.language === language);
            const ids = this.selectedSamples();
            this.field('samples').innerHTML = samples.length ? samples.map(sample => `<label class="ete-check-row"><input type="checkbox" data-sample-id="${esc(sample.id)}" ${ids.includes(String(sample.id)) ? 'checked' : ''}><span>${esc(sample.title)}</span></label>`).join('') : this.state('이 언어의 평가 지문이 없습니다.');
            this.field('sample-count').textContent = `${ids.length}개 선택`;
            this.field('sample-order').innerHTML = ids.length ? `<h3>출제 순서</h3><ol>${ids.map((id, index) => {
                const sample = samples.find(item => String(item.id) === id);
                const title = sample?.title || id;
                return `<li><span class="ete-order-title">${esc(title)}</span><span class="ete-actions">${this.iconButton('sample-up', 'arrow-left', `${title} 위로`, `data-id="${esc(id)}"`, index === 0)}${this.iconButton('sample-down', 'arrow-right', `${title} 아래로`, `data-id="${esc(id)}"`, index === ids.length - 1)}${this.iconButton('remove-sample', 'x', `${title} 선택 해제`, `data-id="${esc(id)}"`)}</span></li>`;
            }).join('')}</ol>` : '';
            if (focusId !== undefined) [...this.field('samples').querySelectorAll('input')].find(input => input.dataset.sampleId === focusId)?.focus({ preventScroll: true });
        }
        moveSample(id, action) {
            const ids = [...this.selectedSamples()];
            const index = ids.indexOf(id);
            if (index < 0) return;
            if (action === 'remove-sample') ids.splice(index, 1);
            else {
                const next = index + (action === 'sample-up' ? -1 : 1);
                if (next < 0 || next >= ids.length) return;
                [ids[index], ids[next]] = [ids[next], ids[index]];
            }
            this.sampleSelections.set(this.control('language').value, ids);
            this.renderSamples();
            this.updateCreateButton();
            const button = [...this.field('sample-order').querySelectorAll('button')].find(item => item.dataset.id === id && item.dataset.action === action && !item.disabled);
            (button || this.field('samples').querySelector('input'))?.focus({ preventScroll: true });
        }
        updateCreateButton() {
            this.field('create-submit').disabled = this.saving || !this.catalogLoaded || !this.selectedContexts.size || !this.selectedSamples().length;
        }
        async createEvaluation() {
            if (this.saving || !this.field('create-form').reportValidity()) return;
            const language = this.control('language').value;
            const ids = this.selectedSamples();
            const body = {
                title: this.control('title').value.trim(), language,
                durationSeconds: Number(this.control('durationSeconds').value),
                minAccuracy: Number(this.control('minAccuracy').value),
                maxAttempts: Number(this.control('maxAttempts').value),
                lineEnding: this.control('lineEnding').value,
                contexts: [...this.selectedContexts.values()].map(({ type, id, label, revision }) => ({ type, id, label, revision })),
                sampleIds: ids.map(id => this.samples.find(sample => String(sample.id) === id && sample.language === language)?.id)
            };
            if (!body.title || !body.contexts.length || !ids.length || body.sampleIds.some(id => id === undefined)
                || ![60, 180, 300, 600].includes(body.durationSeconds) || ![1, 2, 3].includes(body.maxAttempts)
                || !Number.isInteger(body.minAccuracy) || body.minAccuracy < 70 || body.minAccuracy > 100) {
                this.message('create-message', '평가명, 대상 학급·그룹, 지문 및 평가 조건을 확인해 주세요.', true);
                return;
            }
            this.saving = true;
            this.field('create-controls').disabled = true;
            this.message('create-message', '초안을 저장하는 중입니다.');
            try {
                const data = await this.api('/typing/evaluations', {}, body);
                if (data.evaluation?.id === undefined) throw new Error('저장 결과를 확인하지 못했습니다. 평가 목록을 확인해 주세요.');
                this.upsertEvaluation(data.evaluation);
                this.field('create-form').reset();
                this.selectedContexts.clear();
                this.sampleSelections.clear();
                this.renderContexts();
                this.renderSamples();
                this.message('create-message');
                this.openResults(data.evaluation.id);
                this.message('message', '평가 초안을 저장했습니다.');
            } catch (error) { this.message('create-message', error.message, true); }
            finally { this.saving = false; this.field('create-controls').disabled = false; this.updateCreateButton(); }
        }
        upsertEvaluation(evaluation) {
            const index = this.evaluations.findIndex(item => String(item.id) === String(evaluation.id));
            if (index < 0) this.evaluations.unshift(evaluation);
            else this.evaluations[index] = { ...this.evaluations[index], ...evaluation };
        }

        openResults(id) {
            this.evaluation = this.evaluations.find(item => String(item.id) === String(id));
            if (!this.evaluation) return;
            this.setView('results');
            this.page = 1;
            this.resultsData = null;
            this.resultFilters={status:'all',quality:'all',group:'',search:'',sort:'name',direction:'asc'};
            this.root.querySelector('[data-result-search]').value='';
            this.renderEvaluation();
            this.renderSummary();
            this.loadResults(1);
        }
        renderEvaluation() {
            const item = this.evaluation;
            this.field('results-title').textContent = item.title || '평가 결과';
            this.field('evaluation-status').innerHTML = badge(item.status);
            this.field('evaluation-meta').textContent = [
                (item.contextLabels || []).join(', '), languageLabel(item.language),
                item.version === 'typing-verified-v2' ? '검정 v2 · 이전 연속 일치' : '검정 v3 · 정타 CPM',
                `${metric(Number(item.durationSeconds) / 60)}분`, `최소 정확도 ${accuracy(item.minAccuracy)}`, `최대 ${metric(item.maxAttempts)}회`, `줄 끝 ${item.lineEnding==='space-enter'?'Space·Enter':'Enter만'}`
            ].filter(Boolean).join(' · ');
            const button = this.field('status-button');
            button.textContent = this.statusSaving ? '변경 중' : item.status === 'open' ? '응시 마감' : item.status === 'closed' ? '다시 열기' : '평가 시작';
            button.disabled = this.statusSaving || !['draft', 'open', 'closed'].includes(item.status);
            const url=new URL(this.config.studentUrl||'/oj-addons/edu-games/games/code-typing',location.href);url.searchParams.set('evaluation',String(item.id));
            const open=item.status==='open'&&url.origin===location.origin;
            this.field('share-button').disabled=!open;
            this.field('student-link').hidden=!open;this.field('student-link').href=url.href;
            this.field('share-url').value=url.href;this.field('share-url').hidden=true;
            this.field('share-status').textContent=item.status==='draft'?'공개 전':item.status==='closed'?'응시 마감':'';
        }
        async copyStudentLink() {
            if(!this.evaluation||this.evaluation.status!=='open')return;
            const url=this.field('share-url').value;
            try{await navigator.clipboard.writeText(url);this.field('share-status').textContent='참여 링크 복사됨';}
            catch(error){const input=this.field('share-url');input.hidden=false;input.focus();input.select();this.field('share-status').textContent='참여 주소';}
        }
        renderSummary(summary = {},comparison={}) {
            this.field('summary').innerHTML = [['assigned','전체 대상','all'],['completed','완료','finished'],['running','응시 중','running'],['absent','미응시','absent']].map(([key,label,status])=>`<button type="button" class="ete-summary-filter" data-action="result-status" data-status="${status}" aria-pressed="${this.resultFilters.status===status}" title="${label} 학생 보기"><span>${label}</span><strong>${esc(metric(summary[key]))}</strong></button>`).join('')
                +`<div class="ete-summary-stat"><span>평균 유효 CPM</span><strong>${esc(metric(comparison.averageCpm))}</strong><small>전체 확정 ${comparison.finalCount||0}명</small></div><div class="ete-summary-stat"><span>평균 입력 정확도</span><strong>${comparison.averageInputAccuracy==null?'-':esc(accuracy(comparison.averageInputAccuracy))}</strong><small>전체 확정 ${comparison.finalCount||0}명</small></div>`;
        }
        async changeStatus() {
            if (!this.evaluation || this.statusSaving) return;
            const id = this.evaluation.id;
            const status = this.evaluation.status === 'open' ? 'closed' : 'open';
            this.statusSaving = true;
            this.renderEvaluation();
            this.message('message', '평가 상태를 변경하는 중입니다.');
            try {
                const data = await this.api(`/typing/evaluations/${encodeURIComponent(id)}/status`, {}, { status });
                if (data.evaluation?.id === undefined) throw new Error('평가 상태를 확인하지 못했습니다. 다시 불러와 주세요.');
                this.upsertEvaluation(data.evaluation);
                if (String(this.evaluation.id) === String(id)) {
                    this.evaluation = { ...this.evaluation, ...data.evaluation };
                    this.renderEvaluation();
                    if (this.view === 'results') {
                        this.message('message', status === 'open' ? '평가를 열었습니다.' : '새 응시를 마감했습니다.');
                        this.loadResults(this.page);
                    }
                }
            } catch (error) { if (String(this.evaluation.id) === String(id) && this.view === 'results') this.message('message', error.message, true); }
            finally { this.statusSaving = false; this.renderEvaluation(); }
        }

        async loadResults(page) {
            if (this.view !== 'results' || !this.evaluation) return;
            const focused=document.activeElement,sortFocus=focused?.matches('[data-action=result-sort]')?focused.dataset.sort:null,statusFocus=focused?.matches('[data-action=result-status]')?focused.dataset.status:null;
            const id = this.evaluation.id;
            const request = ++this.resultsRequest;
            this.page = Math.max(1, Math.floor(page) || 1);
            this.resultsData = null;
            this.closeAttempts();
            this.field('results').setAttribute('aria-busy', 'true');
            this.field('results').innerHTML = this.state('참여자 결과를 불러오는 중입니다.');
            this.field('pagination').hidden = true;
            this.field('export-button').disabled = true;
            try {
                const data = await this.api(`/typing/evaluations/${encodeURIComponent(id)}/results`, { page: this.page, perPage: this.perPage,...this.resultFilters });
                if (request !== this.resultsRequest) return;
                requireArray(data.rows);
                if (!data.pagination || !Number.isInteger(data.pagination.page) || !Number.isInteger(data.pagination.totalPages)
                    || data.pagination.page < 1 || data.pagination.totalPages < 0 || !Number.isInteger(data.pagination.total)
                    || data.pagination.total < 0 || !Number.isInteger(data.pagination.perPage) || data.pagination.perPage < 1) throw new Error('페이지 정보를 읽지 못했습니다. 다시 불러와 주세요.');
                this.resultsData = data;
                this.page = data.pagination.page;
                this.evaluation = { ...this.evaluation, ...data.evaluation };
                this.upsertEvaluation(this.evaluation);
                this.renderEvaluation();
                this.renderSummary(data.summary,data.comparison);
                const group=this.root.querySelector('[data-result-group]');const options='<option value="">전체 그룹</option>'+(data.groups||[]).map(label=>`<option value="${esc(label)}">${esc(label)}</option>`).join('');if(group.innerHTML!==options)group.innerHTML=options;group.value=this.resultFilters.group;
                this.root.querySelector('[data-result-quality]').value=this.resultFilters.quality;
                this.root.querySelector('[data-result-order]').value=this.resultFilters.sort+':'+this.resultFilters.direction;
                this.renderResults(data.rows);
                this.renderPagination(data.pagination);
                const restoreFocus=document.activeElement===focused||document.activeElement===document.body;
                if(sortFocus&&restoreFocus)this.root.querySelector(`[data-action=result-sort][data-sort="${CSS.escape(sortFocus)}"]`)?.focus({preventScroll:true});
                if(statusFocus&&restoreFocus)this.root.querySelector(`[data-action=result-status][data-status="${CSS.escape(statusFocus)}"]`)?.focus({preventScroll:true});
                this.field('result-stamp').textContent=new Intl.DateTimeFormat('ko-KR',{hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date())+' 갱신';
                this.field('export-button').disabled = !!this.exporting;
            } catch (error) {
                if (request === this.resultsRequest) { this.renderSummary(); this.field('results').innerHTML = this.state(error.message, 'retry-results'); }
            } finally { if (request === this.resultsRequest) this.field('results').setAttribute('aria-busy', 'false'); }
        }
        renderResults(rows) {
            const th=(key,label)=>`<th scope="col" aria-sort="${this.resultFilters.sort===key?(this.resultFilters.direction==='asc'?'ascending':'descending'):'none'}"><button type="button" class="ete-sort-button" data-action="result-sort" data-sort="${key}" title="${label} 정렬">${label}<span data-direction="${this.resultFilters.sort===key?this.resultFilters.direction:'none'}">${this.icon('arrow-right')}</span></button>${key==='accuracy'?`<small class="ete-threshold">기준 ${Number(this.evaluation.minAccuracy)}%</small>`:''}</th>`;
            this.field('results').innerHTML=rows.length?`<div class="ete-comparison-wrap"><table class="ete-comparison-table"><caption class="ete-sr-only">학생별 유효 CPM, 입력 정확도와 응시 상태</caption><colgroup><col class="ete-col-person"><col class="ete-col-state"><col class="ete-col-metric"><col class="ete-col-metric"><col class="ete-col-detail"></colgroup><thead><tr>${th('name','학생')}${th('status','응시 상태')}${th('cpm','유효 CPM')}${th('accuracy','입력 정확도')}<th scope="col"><span class="ete-sr-only">기록 보기</span></th></tr></thead><tbody>${rows.map(row=>{
                const absent=row.status==='absent',note=row.quality==='review'?'전송 확인':row.quality==='below'?(row.validCharacters?'정확도 미달':'유효 입력 없음'):'';
                return `<tr data-participant-row data-user-id="${Number(row.userId)}" data-result-state="${row.status}"><th scope="row" class="ete-person"><strong>${absent?esc(row.name):`<button type="button" class="ete-person-button" data-action="attempts" data-user-id="${Number(row.userId)}" data-name="${esc(row.name)}">${esc(row.name)}</button>`}</strong><small title="${esc((row.groups||[]).join(' · '))}">${esc((row.groups||[]).join(' · '))} · ID ${Number(row.userId)}</small></th><td class="ete-state-cell">${badge(row.status)}<small>${row.activeAttemptNo&&row.resultStatus==='finished'?`${row.activeAttemptNo}회 재응시 중`:`${Number(row.attempts)}/${Number(this.evaluation.maxAttempts)}회`}</small>${note?`<span class="ete-review-note">${note}</span>`:''}</td><td class="ete-result-metric"><span class="ete-mobile-label" aria-hidden="true">유효 CPM</span>${this.metricBar(row,'cpm')}</td><td class="ete-result-metric"><span class="ete-mobile-label" aria-hidden="true">입력 정확도</span>${this.metricBar(row,'accuracy')}</td><td class="ete-detail-cell">${this.iconButton('attempts','arrow-right',row.name+' 응시 기록',`data-user-id="${Number(row.userId)}" data-name="${esc(row.name)}"`,absent)}</td></tr>`;
            }).join('')}</tbody></table></div>`:this.state('조회 조건에 맞는 학생이 없습니다.','reset-filters');
        }
        metricBar(row,kind){
            if(row.status==='absent')return '<span class="ete-unmeasured">-</span>';
            const value=Number(kind==='cpm'?row.effectiveCpm:row.inputAccuracy),max=kind==='cpm'?Number(this.resultsData.comparison?.cpmMax||1):100,percent=Math.max(0,Math.min(100,100*value/max));
            const provisional=row.resultStatus==='running',tone=kind==='cpm'?'primary':row.inputAccuracy>=this.evaluation.minAccuracy?'success':'warning';
            return `<div class="ete-metric-value" data-tone="${tone}" ${provisional?'data-provisional':''}><strong>${esc(kind==='cpm'?metric(value):accuracy(value))}</strong>${provisional?'<small>잠정</small>':row.attempts>1?`<small>대표 ${Number(row.attemptNo)}회</small>`:''}</div><div class="ete-metric-track" data-tone="${tone}" aria-hidden="true"><i style="width:${percent.toFixed(2)}%"></i>${kind==='accuracy'?`<b style="left:${Number(this.evaluation.minAccuracy)}%"></b>`:''}</div>`;
        }
        renderPagination(pagination) {
            const { page, perPage, total, totalPages } = pagination;
            const first = total ? (page - 1) * perPage + 1 : 0;
            const last = Math.min(page * perPage, total);
            this.field('pagination').hidden = false;
            this.field('pagination').innerHTML = `<span class="ete-page-count">조회 ${esc(metric(total))}명 / 전체 ${Number(this.resultsData.summary.assigned)}명 · ${first}–${last}</span><div class="ete-actions"><label class="ete-page-label">표시 <select class="ojp-select" data-per-page>${[20,50,100].map(size=>`<option value="${size}" ${size===perPage?'selected':''}>${size}명</option>`).join('')}</select></label>${this.iconButton('page', 'arrow-left', '이전 페이지', `data-page="${page - 1}"`, page <= 1)}<label class="ete-page-label">페이지 <input class="ojp-input ete-page-input" data-page-input type="number" min="1" max="${Math.max(1, totalPages)}" step="1" value="${page}" required></label><span>/ ${Math.max(1, totalPages)}</span>${this.iconButton('page', 'arrow-right', '다음 페이지', `data-page="${page + 1}"`, page >= totalPages)}</div>`;
        }
        siteTime(value) {
            if (!value) return '-';
            let text = String(value).trim().replace(' ', 'T');
            if (!/(?:Z|[+-]\d{2}:?\d{2})$/i.test(text)) text += 'Z';
            let date = new Date(text);
            if (Number.isNaN(date.getTime())) return String(value);
            let zone = this.config.timezone || 'UTC';
            const offset = /^([+-])(\d{2}):(\d{2})$/.exec(zone);
            if (offset) {
                date = new Date(date.getTime() + (offset[1] === '-' ? -1 : 1) * (Number(offset[2]) * 60 + Number(offset[3])) * 60000);
                zone = 'UTC';
            }
            try { return new Intl.DateTimeFormat('ko-KR', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(date); }
            catch (error) { return date.toISOString().slice(0, 16).replace('T', ' ') + ' UTC'; }
        }

        closeAttempts() {
            this.attemptsRequest++;
            if(this.field('attempts-section').open)this.field('attempts-section').close();
            this.field('attempts').innerHTML = '';
            this.field('attempts').setAttribute('aria-busy', 'false');
        }
        async loadAttempts(userId, name) {
            const request = ++this.attemptsRequest;
            this.student = { id: userId, name };
            if(!this.field('attempts-section').open)this.field('attempts-section').showModal();
            this.field('attempts-title').textContent = `${name} · 응시 기록`;
            this.field('attempts-title').focus({ preventScroll: true });
            this.field('attempts').setAttribute('aria-busy', 'true');
            this.field('attempts').innerHTML = this.state('응시 기록을 불러오는 중입니다.');
            try {
                const data = await this.api(`/typing/evaluations/${encodeURIComponent(this.evaluation.id)}/attempts`, { userId });
                if (request !== this.attemptsRequest) return;
                const attempts = requireArray(data.attempts);
                const selected=this.resultsData?.rows.find(row=>String(row.userId)===String(userId));
                const facts=row=>`<dl class="ete-attempt-facts">${[['유효 글자',metric(row.validCharacters)],['누적 오타',metric(row.mistakes)],['작성 일치율',accuracy(row.finalAccuracy)],['완성 코드',metric(row.samplesCompleted)],['검정 시간',metric(row.durationSeconds)+'초'],['완료 시각',this.siteTime(row.completedAt)]].map(([label,value])=>`<div><dt>${label}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl>`;
                const overview=selected?`<p class="ete-meta">${esc((selected.groups||[]).join(' · '))} · ID ${Number(userId)}</p><dl class="ete-student-overview"><div><dt>유효 CPM</dt><dd>${esc(metric(selected.effectiveCpm))}</dd></div><div><dt>입력 정확도</dt><dd>${esc(accuracy(selected.inputAccuracy))}</dd></div><div><dt>${selected.resultStatus==='running'?'잠정 기록':'대표 기록'}</dt><dd>${Number(selected.attemptNo)}회</dd></div></dl>`:'';
                this.field('attempts').innerHTML=attempts.length?overview+`<table class="ete-attempt-table"><thead><tr><th scope="col">회차</th><th scope="col">응시 상태</th><th scope="col">유효 CPM</th><th scope="col">입력 정확도</th></tr></thead><tbody>${attempts.map(row=>`<tr><th scope="row">${Number(row.attemptNo)}회${row.id===selected?.id?'<small>대표</small>':''}</th><td>${badge(row.status)}${qualified(row)}</td><td><strong>${esc(metric(row.effectiveCpm))}</strong>${row.status==='running'?'<small>잠정</small>':''}</td><td><strong>${esc(accuracy(row.inputAccuracy))}</strong></td></tr><tr><td colspan="4"><details class="ete-attempt-details" ${attempts.length===1?'open':''}><summary>${Number(row.attemptNo)}회 입력 내역</summary>${facts(row)}</details></td></tr>`).join('')}</tbody></table>`:this.state('응시 기록이 없습니다.');
            } catch (error) { if (request === this.attemptsRequest) this.field('attempts').innerHTML = this.state(error.message, 'retry-attempts'); }
            finally { if (request === this.attemptsRequest) this.field('attempts').setAttribute('aria-busy', 'false'); }
        }

        async exportCsv() {
            if (!this.resultsData || this.exporting) return;
            this.exporting = true;
            const request = ++this.exportRequest;
            const evaluation = { ...this.evaluation };
            const expected = this.resultsData.pagination.total;
            this.field('export-button').disabled = true;
            this.message('message', '전체 결과를 내보내는 중입니다.');
            try {
                const data = await this.api(`/typing/evaluations/${encodeURIComponent(evaluation.id)}/results`, { export: 1 });
                if (request !== this.exportRequest) return;
                const rows = requireArray(data.rows);
                const total = data.pagination?.total ?? data.summary?.assigned ?? expected;
                if (rows.length > 2000 || total > 2000) throw new Error('CSV 내보내기는 대상자 2,000명까지 가능합니다.');
                if (rows.length !== Number(total)) throw new Error('전체 대상자 결과를 받지 못해 내보내기를 중단했습니다. 결과를 새로고침해 주세요.');
                const header = ['학생 ID', '학생', '학급·그룹', '참여 상태', '응시 횟수', '표시 기록', '대표 회차', '유효 CPM', '입력 정확도 (%)', '작성 일치율 (%)', '유효 글자', '오타', '정확도 기준', '완료 시각'];
                const records = rows.map(row => {
                    const absent = row.status === 'absent';
                    return [row.userId, row.name, (row.groups || []).join(', '), statusLabel(row.status), row.attempts,absent?'':row.resultStatus==='running'?'잠정':'확정',absent?'':row.attemptNo,
                        ...['effectiveCpm', 'inputAccuracy', 'finalAccuracy', 'validCharacters', 'mistakes'].map(key => absent ? '' : row[key]),
                        row.verificationIssue ? '입력 이력 확인 필요' : (row.resultStatus||row.status) === 'finished' && typeof row.qualified === 'boolean' ? (row.qualified ? '충족' : '기준 미달') : '',
                        row.completedAt ? this.siteTime(row.completedAt) : ''];
                });
                const csv = [header, ...records].map(row => row.map(csvCell).join(',')).join('\r\n');
                const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
                const link = document.createElement('a');
                link.href = url;
                link.download = `typing-evaluation-${String(evaluation.id).replace(/[^a-zA-Z0-9_-]/g, '_')}.csv`;
                this.root.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                this.message('message', `전체 ${rows.length}명의 CSV를 내보냈습니다.`);
            } catch (error) { if (request === this.exportRequest) this.message('message', error.message, true); }
            finally {
                this.exporting = false;
                this.field('export-button').disabled = !this.resultsData;
            }
        }
    }

    const init = () => document.querySelectorAll('[data-typing-evaluations]').forEach(root => {
        if (root.__eduTypingEvaluations) return;
        try { root.__eduTypingEvaluations = new TypingEvaluations(root); }
        catch (error) { root.querySelector('[data-field="message"]').textContent = '평가 화면을 준비하지 못했습니다. 페이지를 새로고침해 주세요.'; }
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
