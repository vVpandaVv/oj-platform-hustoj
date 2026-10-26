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
        if (labels[config.gameId]) header.querySelector('h2').textContent = labels[config.gameId];
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
        if (sidebar) shell.querySelector('.oj-binary-layout').before(sidebar);
        shell.querySelectorAll('.oj-device-side,.oj-rain-side').forEach(side => {
            if (!side.children.length) side.remove();
        });
        const records = shell.querySelector('[data-role=records]');
        if (records) records.classList.add('eg-game-disclosure');
        viewport(root, header);
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
                const row=Math.max(38,Math.min(60,Math.floor((area.clientHeight-280)/8)));
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
