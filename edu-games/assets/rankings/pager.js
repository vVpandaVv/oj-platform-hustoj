(() => {
    'use strict';
    if (window.EduGamesRankPager) return;
    const states = new WeakMap();
    const sizes = [20, 50, 100];
    const icons = document.currentScript?.src
        ? new URL('../shell/icons.svg', document.currentScript.src).href
        : 'assets/shell/icons.svg';
    async function removeRecord(container, state, record, button) {
        const root = container.closest('[data-config]');
        let config;
        try { config = JSON.parse(root?.dataset.config || '{}'); } catch { return; }
        const url = new URL(record.deleteUrl, location.href);
        if (url.origin !== location.origin || !config.nonce) return;
        const dialog = document.createElement('dialog');
        dialog.className = 'edu-rank-dialog';
        dialog.setAttribute('aria-label', '순위 기록 삭제');
        dialog.innerHTML = '<h2>이 기록을 삭제할까요?</h2><p data-record-label></p><p>해당 도전의 기록 한 건을 삭제하며 복구할 수 없습니다. 다른 도전 기록이 있으면 다음 최고 기록으로 순위가 다시 계산됩니다.</p><p data-delete-status role="status"></p><div><button type="button" data-cancel autofocus>취소</button><button type="button" data-confirm>기록 삭제</button></div>';
        dialog.querySelector('[data-record-label]').textContent = `${record.playerName || record.name || '참여자'} · ${record.score}점`;
        document.body.append(dialog);
        let saving = false;
        dialog.querySelector('[data-cancel]').onclick = () => dialog.close();
        dialog.addEventListener('cancel', e => { if (saving) e.preventDefault(); });
        dialog.addEventListener('close', () => { dialog.remove(); if (button.isConnected) button.focus({preventScroll:true}); }, {once:true});
        dialog.querySelector('[data-confirm]').onclick = async () => {
            if (saving) return;
            saving = true;
            dialog.querySelectorAll('button').forEach(b => { b.disabled = true; });
            const status = dialog.querySelector('[data-delete-status]'); status.textContent = '삭제 중';
            try {
                const response = await fetch(url, { method:'DELETE', credentials:'same-origin', headers:{'X-WP-Nonce':config.nonce} });
                const data = await response.json();
                if (!response.ok) throw Error(data.message || '기록을 삭제하지 못했습니다.');
                dialog.close();
                state.focus = 'size';
                window.dispatchEvent(new CustomEvent('edu-games-record-deleted'));
            } catch (error) {
                status.textContent = error.message || '연결을 확인한 뒤 다시 시도하세요.';
                dialog.querySelectorAll('button').forEach(b => { b.disabled = false; });
            } finally { saving = false; }
        };
        dialog.showModal();
    }
    function moderation(container, state) {
        state.rows.forEach((row, i) => {
            row.querySelector('.edu-rank-delete')?.remove();
            const record = state.records?.[i];
            if (!record?.deleteUrl) return;
            row.classList.add('edu-rank-moderated');
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'edu-rank-delete';
            button.title = `${record.playerName || record.name || '참여자'}의 ${record.score}점 기록 삭제`;
            button.setAttribute('aria-label', button.title);
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('aria-hidden', 'true');
            const use = document.createElementNS(svg.namespaceURI, 'use'); use.setAttribute('href', icons+'#trash'); svg.append(use); button.append(svg);
            button.onclick = e => { e.stopPropagation(); removeRecord(container,state,record,button); };
            (row.tagName === 'TR' ? row.lastElementChild : row).append(button);
        });
    }
    function paint(container, state, focus) {
        const total = state.remote ? state.total : state.rows.length;
        const pages = Math.max(1, Math.ceil(total / state.size));
        state.page = Math.max(1, Math.min(pages, state.page));
        const start = (state.page - 1) * state.size;
        state.rows.forEach((row, i) => { row.hidden = !state.remote && (i < start || i >= start + state.size); });
        container.querySelector(':scope > .edu-rank-pagination')?.remove();
        container.setAttribute('aria-busy', String(!!state.busy));
        if (!total && !state.error && !state.busy) return;
        const nav = document.createElement('nav');
        nav.className = 'edu-rank-pagination'; nav.setAttribute('aria-label', '순위 페이지 이동');
        const button = (action, label, symbol, disabled) => `<button type="button" data-rank-page="${action}" title="${label}" aria-label="${label}" ${disabled || state.busy ? 'disabled' : ''}>${symbol}</button>`;
        nav.innerHTML = `<span class="edu-rank-range">${start + 1}~${Math.min(total, start + state.size)} / ${total}${state.unit}</span><label>표시 <select data-rank-size aria-label="페이지당 항목 수">${sizes.map(size => `<option value="${size}" ${size === state.size ? 'selected' : ''}>${size}${state.unit}</option>`).join('')}</select></label><div class="edu-rank-page-controls">${button('first', '처음 페이지', '&laquo;', state.page === 1)}${button('previous', '이전 페이지', '&lsaquo;', state.page === 1)}<output aria-live="polite" aria-atomic="true">${state.page} / ${pages}</output>${button('next', '다음 페이지', '&rsaquo;', state.page === pages)}${button('last', '마지막 페이지', '&raquo;', state.page === pages)}</div>`;
        container.append(nav);
        nav.querySelector('select').disabled = !!state.busy;
        if (!total) nav.querySelector('.edu-rank-range').textContent = '기록 없음';
        if (state.busy) nav.querySelector('output').textContent = '조회 중';
        if (state.error) nav.insertAdjacentHTML('beforeend', `<span role="status">순위를 불러오지 못했습니다.</span>${button('retry', '순위 다시 조회', '다시 조회', false)}`);
        if (focus) {
            const target = focus === 'size' ? nav.querySelector('select') : nav.querySelector(`[data-rank-page="${focus}"]:not(:disabled)`);
            (target || nav.querySelector('select')).focus({ preventScroll: true });
        }
    }
    function mount(container, key, selector = '[data-rank-row]', unit = '명') {
        if (!container) return;
        let state = states.get(container);
        if (!state) {
            state = { key, page: 1, size: 20, rows: [] }; states.set(container, state);
            window.addEventListener('edu-games-record-deleted', () => { if (container.isConnected) state.reload?.(); });
            container.addEventListener('click', event => {
                const button = event.target.closest('[data-rank-page]');
                if (!button || button.disabled || button.closest('.edu-rank-pagination')?.parentElement !== container) return;
                event.stopPropagation();
                const action = button.dataset.rankPage;
                const pages = Math.max(1, Math.ceil((state.remote ? state.total : state.rows.length) / state.size));
                const page = { first: 1, previous: state.page - 1, next: state.page + 1, last: pages }[action] ?? state.page;
                if (state.reload) { state.requested = { page, perPage: state.size }; state.focus = action; state.reload(); return; }
                state.page = page;
                paint(container, state, action);
            });
            container.addEventListener('change', event => {
                if (!event.target.matches('[data-rank-size]') || event.target.closest('.edu-rank-pagination')?.parentElement !== container) return;
                event.stopPropagation();
                const size = Number(event.target.value); if (!sizes.includes(size)) return;
                if (state.reload) { state.requested = { page: 1, perPage: size }; state.focus = 'size'; state.reload(); return; }
                state.size = size; state.page = 1; paint(container, state, 'size');
            });
        }
        if (state.key !== key) { state.key = key; state.page = 1; state.remote = false; state.requested = null; state.reload = null; state.error = false; }
        state.unit = unit === '팀' ? '팀' : '명';
        state.rows = [...container.querySelectorAll(selector)];
        moderation(container, state);
        container.classList.add('edu-rank-paged');
        paint(container, state, state.focus); state.focus = null;
    }
    function request(container, key, reload) {
        if (!states.has(container) || states.get(container).key !== key) { container.innerHTML = ''; mount(container, key); }
        const state = states.get(container);
        state.reload = reload; state.busy = true; state.error = false;
        const query = state.requested || { page: state.page, perPage: state.size };
        paint(container, state);
        state.ticket = (state.ticket || 0) + 1;
        return { ...query, ticket: state.ticket };
    }
    function accept(container, key, data, ticket) {
        const state = states.get(container);
        if (!state || state.key !== key || state.ticket !== ticket) return false;
        state.busy = false; state.error = false; state.requested = null; state.remote = !!data.pagination;
        state.records = data.leaderboard || [];
        if (state.remote) { state.total = Number(data.pagination.total); state.page = Number(data.pagination.page); state.size = Number(data.pagination.perPage); }
        else state.reload = null;
        return true;
    }
    function fail(container, ticket) {
        const state = states.get(container); if (!state || state.ticket !== ticket) return;
        state.busy = false; state.error = true; state.requested = null; paint(container, state);
    }
    window.EduGamesRankPager = { mount, request, accept, fail };
})();
