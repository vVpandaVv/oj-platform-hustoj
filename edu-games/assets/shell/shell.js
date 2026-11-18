(() => {
    'use strict';
    const icons = new URL('icons.svg', document.currentScript.src).href;
    function themeCharts(root) {
        const canvases = root.querySelectorAll('[data-rescue-chart],[data-visual-chart],[data-allocation-chart]');
        if (!canvases.length) return;
        const style = getComputedStyle(root), ink = style.getPropertyValue('--eg-ink').trim(), line = style.getPropertyValue('--eg-line').trim();
        canvases.forEach(canvas => {
            const chart = window.Chart?.getChart(canvas), key = ink + line;
            if (!chart || chart.__egTheme === key) return;
            chart.__egTheme = key;
            chart.options.color = ink;
            Object.values(chart.options.scales || {}).forEach(scale => {
                scale.ticks.color = ink;
                scale.title.color = ink;
                scale.grid.color = line;
                scale.border.color = line;
            });
            chart.update('none');
        });
    }
    function mount(root) {
        if (root.dataset.shellReady) return;
        root.dataset.shellReady = 'true';
        const config = JSON.parse(root.dataset.config || '{}');
        if (config.reportOnly) return;
        const shell = root.querySelector('[class$="-shell"]') || root;
        const header = shell.querySelector('[class$="-topbar"],.ep-heading');
        if (!header) return;
        header.classList.add('eg-game-header');
        if (config.hubUrl) {
            const back = document.createElement('a');
            back.className = 'eg-game-back'; back.href = config.hubUrl;
            back.innerHTML = `<svg aria-hidden="true"><use href="${icons}#arrow-left"></use></svg><span>게임 허브</span>`;
            header.before(back);
        }
        const labels = {'device-matching':'장치 카드 매칭','image-data':'이미지 데이터','keyword-rain':'키워드 레인'};
        if (labels[config.gameId]&&!config.channel) header.querySelector('h2').textContent = labels[config.gameId];
        // Move existing nodes, never clone: the game retains its fields and event handlers.
        function fold(node, title, kind) {
            if (!node) return;
            const details = document.createElement('details'); details.className = 'eg-game-disclosure';
            details.dataset.shellSection = kind;
            const summary = document.createElement('summary'); summary.textContent = title;
            details.append(summary, node); shell.append(details);
        }
        const ranks = shell.querySelector('.oj-binary-rank-panel,.oj-code-rank-panel,.oj-typing-rank-panel,.oj-device-side [data-field=leaderboard],.oj-rain-side [data-field=leaderboard]');
        fold(ranks?.matches('[data-field=leaderboard]') ? ranks.closest('section') : ranks, '순위와 기록', 'records');
        fold(shell.querySelector('.oj-device-rule-card') || shell.querySelector('.oj-rain-rules')?.closest('section'), '게임 도움말', 'help');
        const sidebar = shell.querySelector('.oj-binary-sidebar');
        if (sidebar&&!sidebar.closest('[data-game-workspace]')) shell.querySelector('.oj-binary-layout').before(sidebar);
        shell.querySelectorAll('.oj-device-side,.oj-rain-side').forEach(side => {
            if (!side.children.length) side.remove();
        });
        const records = shell.querySelector('[data-role=records]');
        if (records) records.classList.add('eg-game-disclosure');
        arcade(root, shell, header, config, fold);
        viewport(root, header);
    }
    function arcade(root, shell, header, config, fold) {
        const games = {
            'binary-conversion': ['이진수 ↔ 십진수, 자리값으로 빠르게 변환', '정답 + 자리수·레벨·연속 보너스'],
            'device-matching': ['사진과 설명, 같은 장치의 짝 찾기', '짝 맞추기 + 연속 보너스 · 실수 감점'],
            'keyword-rain': ['바닥에 닿기 전에 단어 입력', '정확한 입력 + 길이·단계·콤보 보너스'],
            'code-typing': ['제시 코드를 정확하고 빠르게 입력', '검정: 유효 코드 CPM · 수정 전 오타도 정확도에 반영'],
            'image-data': ['픽셀과 색상 코드로 그림 완성', '복원 성공 → 내 도감에 수집 · 직접 만들기는 자유 작업'],
            'outlier-detection': ['모여 있는 값에서 멀리 떨어진 값만 검토함으로', '정확한 분리로 득점 · 놓침·과잉 선택 감점'],
            'missing-values': ['관측 근거에 맞는 도구로 빈칸 복원', '복원 오차와 근거 선택에 따라 판정'],
            'data-visualization': ['그래프를 읽고 임무에 맞게 출동·배정', '임무 성공 + 연속 보너스 · 실수 감점'],
            'data-extraction': ['주문에 맞게 관문과 출고 라벨 설정', '조건·라벨이 모두 맞는 출고로 득점'],
            'data-structure-lab': ['스택·큐의 출구를 이용해 주문 순서대로 배송', '배송·주문·급행 보너스 · 재배치 비용'],
            'ai-classifier-lab': ['경계선을 움직여 봉투와 상자 배송길 나누기', '새 물건의 분류 결과로 득점 · 오분류 감점'],
            'ai-model-lab': ['손글씨에 이름을 붙여 로봇의 기억 만들기', '확인 인식은 즉시 변화 · 최종 검정은 처음 보는 손글씨']
        };
        const game = config.game || config.gameId, info = games[game];
        if (!info || config.channel) return;
        root.classList.add('eg-arcade');
        root.dataset.arcadeGame = game;
        const objective = document.createElement('div');
        objective.className = 'eg-objective';
        const goal = document.createElement('strong'); goal.textContent = info[0];
        const help = document.createElement('details'); help.className = 'eg-score-help';
        const summary = document.createElement('summary'); summary.textContent = '득점 기준';
        const reward = document.createElement('p'); reward.textContent = info[1];
        help.append(summary, reward); objective.append(goal, help);
        header.after(objective);
        const receipt = document.createElement('output');
        receipt.className = 'eg-action-receipt'; receipt.dataset.empty = 'true';
        receipt.setAttribute('aria-live', 'polite'); receipt.setAttribute('aria-atomic', 'true');
        objective.insertBefore(receipt, help);
        if (game === 'image-data' && root.classList.contains('eg-layout-v2')) {
            shell.querySelector('.oj-image-stats')?.append(objective);
        }
        root.addEventListener('edu-games:action', event => {
            const { delta, text, tone = 'success' } = event.detail || {};
            receipt.replaceChildren();
            if (Number.isFinite(delta) && delta !== 0) {
                const points = document.createElement('strong');
                points.textContent = (delta > 0 ? '+' : '−') + Math.abs(delta).toLocaleString() + '점';
                receipt.append(points);
            }
            const label = document.createElement('span'); label.textContent = text || '반영 완료';
            receipt.append(label); delete receipt.dataset.empty; receipt.dataset.tone = tone;
            if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
                receipt.getAnimations().forEach(animation => animation.cancel());
                receipt.animate([{transform:'translateY(5px)',opacity:.3},{transform:'translateY(0)',opacity:1}],{duration:180});
            }
        });
        root.addEventListener('click', event => {
            if (event.target.closest('[data-command=new],[data-action=start],[data-action=restart],[data-action=practice],[data-action=start-ranked],[data-mode],[data-begin]')) {
                receipt.replaceChildren(); receipt.dataset.empty = 'true';
            }
        }, true);
        root.addEventListener('change', event => {
            if (event.target.matches('[data-role=level],[data-field=language],[data-field=difficulty],[data-field=grid-size],[data-field=sample]')) {
                receipt.replaceChildren(); receipt.dataset.empty = 'true';
            }
        });
        // Preserve live field references and listeners while removing duplicate pre-game prose.
        const challenge = shell.querySelector('[data-field=challenge-goal]');
        if (challenge && game !== 'code-typing') fold(challenge, '내 도전 목표', 'goals');
        if (game === 'code-typing') {
            fold(shell.querySelector('.oj-typing-keyboard'), '키보드', 'keyboard');
        }
        const secondary = {
            'device-matching': ['streak','rank'], 'keyword-rain': ['correct','level','rank'],
            'code-typing': ['mistakes','samples','score','rank']
        }[game];
        if (secondary) {
            const extra = document.createElement('div'); extra.className = 'eg-extra-stats';
            secondary.forEach(name => {
                const stat = shell.querySelector(`[data-field="${name}"]`)?.closest('[class$="-stat"]');
                if (stat) extra.append(stat);
            });
            if (extra.children.length) fold(extra, '세부 기록', 'stats');
        }
        const iconActions = { reset:'refresh-cw', clear:'trash', undo:'arrow-left', redo:'arrow-right', export:'external-link' };
        header.querySelectorAll('button[data-action]').forEach(button => {
            const name = iconActions[button.dataset.action];
            if (!name || button.querySelector('svg')) return;
            const label = button.textContent.trim();
            button.title = label; button.setAttribute('aria-label', label);
            button.classList.add('eg-icon-command');
            button.innerHTML = `<svg aria-hidden="true"><use href="${icons}#${name}"></use></svg>`;
        });
    }
    function viewport(root, header) {
        const button = document.createElement('button');
        button.type='button';button.className='eg-focus-button';button.title='집중 화면';button.setAttribute('aria-label','집중 화면');
        button.innerHTML=`<svg aria-hidden="true"><use href="${icons}#monitor"></use></svg>`;
        header.append(button);
        let dialog=null, marker=null, originalOverflow='', previous=false, frame=0, lastFocus=null;
        let binaryStyle=null;
        const running = () => root.dataset.phase==='playing'||root.__BinaryArcade?.state.status==='practice'||[
            root.__BinaryArcade,root.__CharacterCode,root.__CodeTyping,root.__DeviceMatching,root.__KeywordRain
        ].some(game=>game&&['playing','running','stage-clear'].includes(game.state.status));
        function fit() {
            if(!dialog)return;
            const area=dialog.querySelector('.eg-focus-area');
            root.style.setProperty('--eg-view-height',(area.clientHeight-16)+'px');
            const binary=root.querySelector('.oj-binary-shell');
            if(binary){
                if(!binaryStyle)binaryStyle=['--binary-row-height','--binary-grid-height'].map(key=>[key,binary.style.getPropertyValue(key)]);
                // Arcade physics consumes numeric custom properties, not CSS expressions.
                const row=Math.max(38,Math.min(60,Math.floor((area.clientHeight-(root.classList.contains('eg-arcade')?320:280))/8)));
                binary.style.setProperty('--binary-row-height',row+'px');
                binary.style.setProperty('--binary-grid-height',(row*8)+'px');
            }
            const cards=root.querySelectorAll('[data-card-id]'),deck=root.querySelector('.oj-device-deck');
            if(deck&&cards.length){
                const columns=getComputedStyle(deck).gridTemplateColumns.split(' ').length;
                const rows=Math.ceil(cards.length/columns);
                root.style.setProperty('--eg-card-rows',String(rows));
                root.dataset.compactCards=root.clientWidth<760||rows>=4?'true':'false';
                let inspector=root.querySelector('.eg-card-inspector');
                if(!inspector){inspector=document.createElement('p');inspector.className='eg-card-inspector';inspector.setAttribute('aria-live','polite');deck.after(inspector);}
                const selected=root.__DeviceMatching?.state.openedIds.map(id=>root.__DeviceMatching.state.cards.find(c=>c.id===id)).filter(c=>c?.type==='text').at(-1);
                const text=selected?deck.querySelector(`[data-card-id="${CSS.escape(selected.id)}"] .oj-device-text-card`)?.textContent.trim().replace(/\s+/g,' ')||'':'';
                if(inspector.textContent!==text)inspector.textContent=text;
            }
            root.dataset.focusDensity=area.clientHeight<650?'tight':'normal';
        }
        function close() {
            if(!dialog)return;
            const focused=document.activeElement;
            const old=dialog;dialog=null;
            marker.replaceWith(root);marker=null;root.classList.remove('eg-focused');
            delete root.dataset.focusDensity;delete root.dataset.compactCards;root.style.removeProperty('--eg-view-height');
            if(binaryStyle){const shell=root.querySelector('.oj-binary-shell');binaryStyle.forEach(([key,value])=>value?shell.style.setProperty(key,value):shell.style.removeProperty(key));binaryStyle=null;}
            document.documentElement.style.overflow=originalOverflow;
            old.close();old.remove();
            (root.contains(focused)?focused:button).focus({preventScroll:true});
            window.dispatchEvent(new Event('resize'));
        }
        function open() {
            if(dialog||document.querySelector('.eg-focus-dialog[open]'))return;
            lastFocus=document.activeElement;
            marker=document.createComment('game-focus-origin');root.before(marker);
            dialog=document.createElement('dialog');dialog.className='eg-focus-dialog';dialog.setAttribute('aria-label',header.querySelector('h2').textContent+' 집중 화면');
            const bar=document.createElement('header');bar.className='eg-focus-toolbar';
            const title=document.createElement('strong');title.textContent=header.querySelector('h2').textContent;
            const exit=document.createElement('button');exit.type='button';exit.className='eg-focus-button';exit.title='집중 화면 나가기';exit.setAttribute('aria-label',exit.title);exit.innerHTML=`<svg aria-hidden="true"><use href="${icons}#x"></use></svg>`;
            exit.onclick=close;bar.append(title,exit);
            const area=document.createElement('div');area.className='eg-focus-area';
            dialog.append(bar,area);document.body.append(dialog);area.append(root);root.classList.add('eg-focused');
            originalOverflow=document.documentElement.style.overflow;document.documentElement.style.overflow='hidden';
            dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
            dialog.showModal();fit();
            if(root.contains(lastFocus))lastFocus.focus({preventScroll:true});else exit.focus({preventScroll:true});
            window.dispatchEvent(new Event('resize'));
        }
        button.onclick=open;
        const update=()=>{
            frame=0;const active=running();
            if(active&&!previous&&!dialog)open();
            previous=active;fit();themeCharts(root);
        };
        const schedule=()=>{if(!frame)frame=requestAnimationFrame(update);};
        new MutationObserver(schedule).observe(root,{childList:true,subtree:true,attributes:true,attributeFilter:['data-phase','class','hidden']});
        window.addEventListener('resize',schedule);
        window.visualViewport?.addEventListener('resize',schedule);
        root.addEventListener('click',event=>{if(event.target.closest('[data-command=show-records]'))close();},true);
        root.__GameViewport={open,close,fit};
    }
    const init = () => document.querySelectorAll('.edu-game').forEach(mount);
    const syncThemes = () => document.querySelectorAll('.edu-game').forEach(themeCharts);
    new MutationObserver(syncThemes).observe(document.documentElement, { attributes:true, attributeFilter:['data-ojp-scheme'] });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', syncThemes);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    window.EduGamesShell = { mount };
})();
