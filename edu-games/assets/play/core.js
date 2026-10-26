(() => {
    'use strict';
    if (window.EduPlayMounted) return;
    window.EduPlayMounted = true;
    const V = window.EduPlayViews;
    document.querySelectorAll('[data-play]').forEach(root => {
        const config = JSON.parse(root.dataset.config);
        if (V.receiver?.(root, config)) return;
        const $ = s => root.querySelector(s), level = $('[data-role=level]');
        const storageKey = 'edu-play:' + config.version + ':' + config.userId + ':' + config.game;
        const read = () => { try { return JSON.parse(localStorage.getItem(storageKey) || 'null'); } catch { return null; } };
        const save = data => { try { data ? localStorage.setItem(storageKey, JSON.stringify(data)) : localStorage.removeItem(storageKey); } catch {} };
        let state = null, busy = false, pending = null, charts = [], local = {}, sequence = 0, clockEnd = 0, finishSent = false, clockState = null;
        const bestScores = new Map();
        let bestRequest = 0;
        root.dataset.game = config.game;
        const message = (text, error = false) => { const target=$('[data-role=message]'); target.textContent = text || ''; target.className = error ? 'ep-message ep-error' : 'ep-message'; };
        async function api(action, body) {
            const response = await fetch(config.restUrl + '/' + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce }, body: JSON.stringify(body) });
            let data;
            try { data = await response.json(); } catch { throw Error('서버 응답을 읽지 못했습니다.'); }
            if (!response.ok) throw Object.assign(Error(data.message || '요청하지 못했습니다.'), { status: response.status });
            return data;
        }
        function paintBest() {
            const s=state||config.previews[level.value], best=bestScores.get(level.value);
            if(s.unranked)return;
            root.querySelectorAll('[data-personal-best]').forEach(el=>{el.textContent=best===undefined?'미확인':best.toLocaleString();});
            const result=$('[data-record-difference]');
            if(result)result.textContent=best===undefined?'기록 저장 완료':s.score>best?`최고 기록 경신 · +${s.score-best}점`:s.score===best?`최고 기록과 동점 · ${best}점`:`최고 기록 ${best}점까지 ${best-s.score}점`;
        }
        async function loadBest() {
            const id=level.value, ticket=++bestRequest;
            if(config.reportOnly||config.previews[id]?.unranked)return;
            try {
                const data=await api('summary',{level:id,page:1,perPage:1});
                if(ticket!==bestRequest)return;
                bestScores.set(id,Number(data.levels?.[id]?.score??0));paintBest();
            } catch { /* A missing ranking response must not prevent play. */ }
        }
        function setBusy(value) {
            busy = value; root.setAttribute('aria-busy', String(value));
            root.querySelectorAll('button,input,select').forEach(e => {
                if (value) { e.dataset.wasDisabled = e.disabled ? '1' : '0'; e.disabled = true; }
                else if (e.dataset.wasDisabled !== undefined) { e.disabled = e.dataset.wasDisabled === '1'; delete e.dataset.wasDisabled; }
            });
        }
        function paint() {
            charts.forEach(c => c.destroy()); charts = [];
            if (!config.reportOnly) {
                const s = state || config.previews[level.value];
                root.dataset.phase = state ? state.status === 'playing' ? 'playing' : 'finished' : 'ready';
                $('[data-role=board]').innerHTML = V.render(config.game, s, local);
                charts = V.mount(config.game, $('[data-role=board]'), s, local, move);
                if (state && state.remaining !== null && state !== clockState) { clockEnd = performance.now() + state.remaining * 1000; clockState = state; }
                if($('[data-role=challenge]'))$('[data-role=challenge]').textContent=s.title;
                paintBest();
            }
            const resume = $('[data-command=resume]'), retry = $('[data-command=retry]');
            if (resume) resume.hidden = !!state || !read();
            if (retry) retry.hidden = !pending;
            if (state) message(state.message);
            root.querySelectorAll('[data-begin]').forEach(button => { button.hidden = !!state; });
            root.toggleAttribute('data-time-low',!!state&&!state.timerPaused&&state.status==='playing'&&state.remaining!==null&&state.remaining<=15);
        }
        async function records() {
            const container = $('[data-role=leaders]'), key = config.game + ':' + level.value;
            if (config.previews[level.value]?.unranked) { container.textContent = '연습은 경쟁 순위에 포함하지 않습니다.'; return; }
            const pager = window.EduGamesRankPager;
            const query = pager ? pager.request(container, key, records) : { page: 1, perPage: 20 };
            try {
                const data = await api('summary', { level: level.value, page: query.page, perPage: query.perPage });
                if (pager && !pager.accept(container, key, data, query.ticket)) return;
                const personal = data.levels?.[level.value]?.score ?? 0;
                container.innerHTML = '<p>내 최고 기록: ' + personal + '점</p><table><thead><tr><th>순위</th><th>참여자</th><th>점수</th></tr></thead><tbody>' + (data.leaderboard || []).map(r => '<tr data-rank-row><td>' + V.h(r.rank) + '</td><td>' + V.h(r.name) + '</td><td>' + V.h(r.score) + '</td></tr>').join('') + '</tbody></table>';
                if (pager) pager.mount(container, key);
            } catch (e) { if (pager) pager.fail(container, query.ticket); message(e.message,true); }
        }
        async function move(action, retry = false) {
            if (busy || (pending && !retry)) return;
            const focusKey = V.focusKey ? V.focusKey(root) : (document.activeElement?.dataset?.focus ? '[data-focus="' + CSS.escape(document.activeElement.dataset.focus) + '"]' : null);
            setBusy(true);
            try {
                if (!state) { state = await api('start', { level: level.value, version: config.version }); save({ token: state.token, level: state.level }); clockEnd = performance.now() + (state.remaining || 0) * 1000; if($('[data-role=settings]'))$('[data-role=settings]').open=false; }
                if (action?.type === 'begin') { paint(); return; }
                if (!pending) pending = { token: state.token, revision: state.revision, requestId: (globalThis.crypto?.randomUUID?.() || ('move-' + Date.now() + '-' + (++sequence))), move: action };
                state = await api('update', pending); pending = null;
                if (state.status !== 'playing') save(null);
                paint();
                if (state.status !== 'playing') {
                    if ($('[data-role=records]').open) await records();
                    $('[data-result]')?.focus();
                } else if (focusKey && (!document.activeElement || document.activeElement === document.body || !root.contains(document.activeElement))) {
                    // Keep the student on the control they used (paint() rebuilt it).
                    const again = root.querySelector(focusKey);
                    if (again && !again.disabled) again.focus({ preventScroll: true });
                }
            } catch (e) {
                if (e.status && e.status < 500) {
                    pending = null;
                    if (e.status === 409) {
                        try { state = await api('resume', { token: state.token }); } catch {}
                    }
                    if (e.status === 404) { save(null); state = null; }
                }
                paint(); message(e.message + (pending ? ' 같은 행동을 저장 재시도로 다시 보낼 수 있습니다.' : ''),true);
            } finally { setBusy(false); }
        }
        root.addEventListener('submit', e => {
            const form = e.target.closest('form[data-play-form]');
            if (!form) return;
            e.preventDefault();
            try { move(V.form(form, state || config.previews[level.value], local)); } catch (error) { message(error.message,true); }
        });
        root.addEventListener('click', async e => {
            const button = e.target.closest('button');
            if (!button || busy) return;
            if (button.dataset.pick !== undefined) { V.pick(button, local); paint(); return; }
            if (button.dataset.move) { move(JSON.parse(button.dataset.move)); return; }
            const cmd = button.dataset.command;
            if (!cmd) return;
            if (cmd === 'show-records') { $('[data-role=records]').open=true; $('[data-role=records]').scrollIntoView({block:'start'}); return; }
            if (cmd === 'records') { records(); return; }
            if (cmd === 'retry') { move(null, true); return; }
            if (cmd === 'new') {
                if ((state?.status === 'playing' || pending) && !window.confirm('현재 도전을 남겨 두고 새 도전을 시작할까요?')) return;
                state = null; pending = null; local = {}; clockEnd = 0; finishSent = false; save(null); paint(); message(''); loadBest(); $('[data-begin]')?.focus({preventScroll:true}); return;
            }
            if (cmd === 'resume') {
                const saved = read(); if (!saved) return;
                setBusy(true);
                try { state = await api('resume', { token: saved.token }); level.value = state.level; selected=state.level; pending = null; local = {}; finishSent=false; paint(); loadBest(); }
                catch (error) { if (error.status === 404) save(null); paint(); message(error.message,true); }
                finally { setBusy(false); }
            }
            if (cmd === 'archive') {
                try { const data = await api('archive', {}); $('[data-role=archive]').innerHTML = (data.records || []).map(r => '<p>' + V.h(r.version) + ' · ' + V.h(r.level) + ' · ' + V.h(r.score) + '점</p>').join('') || '이전 기록이 없습니다.'; }
                catch (error) { message(error.message,true); }
            }
        });
        let selected = level.value;
        level.addEventListener('change', () => {
            if ((state?.status === 'playing' || pending) && !window.confirm('현재 도전 대신 다른 상황을 열까요?')) { level.value = selected; return; }
            selected = level.value; state = null; pending = null; local = {}; clockEnd = 0; finishSent = false; save(null); paint(); message('');
            loadBest();
            if ($('[data-role=records]').open) records();
        });
        $('[data-role=records]').addEventListener('toggle', e => { if (e.target.open) records(); });
        window.addEventListener('edu-games-record-deleted', () => { if (root.isConnected) loadBest(); });
        root.__Play = { get state() { return state; }, get preview() { return config.previews[level.value]; }, move };
        setInterval(() => {
            if (!state || state.remaining === null || state.timerPaused || state.status !== 'playing' || !clockEnd) return;
            const remaining = Math.max(0, Math.ceil((clockEnd - performance.now()) / 1000));
            const timer = root.querySelector('[data-clock]'); if (timer) timer.textContent = remaining + '초';
            const progress = root.querySelector('[data-countdown]'); if(progress)progress.value=remaining;
            root.toggleAttribute('data-time-low',remaining<=15);
            if (!remaining && !busy && !pending && !finishSent) { finishSent = true; move({type:'finish'}).finally(() => { if(state?.status === 'playing') finishSent = false; }); }
        }, 200);
        paint(); setBusy(false);
        if (config.reportOnly) records(); else loadBest();
    });
})();
