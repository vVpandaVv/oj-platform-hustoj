(() => {
    if (window.EduGamesProgress) return;
    function connect(config, root, receive) {
        if (!config.currentUserId || !config.restUrl) return null;
        const node = document.createElement('p'); node.className = 'edu-progress-status'; node.setAttribute('role','status'); root.append(node);
        const jobs = [null]; let busy = false;
        const api = { synced: false, read: () => { jobs.push(null); pump(); }, save: payload => { jobs.push(payload); pump(); } };
        function status(message, retry = false) {
            node.textContent = message;
            if (retry) { const button=document.createElement('button'); button.type='button'; button.textContent='↻'; button.title='계정 기록 다시 동기화'; button.setAttribute('aria-label',button.title); button.addEventListener('click',()=>{if(!jobs.length)jobs.push(null);pump();}); node.append(' ',button); }
        }
        async function pump() {
            if (busy || !jobs.length) return;
            busy = true; const payload = jobs[0]; status(payload ? '계정 기록 저장 중' : '계정 기록 조회 중');
            try {
                const url = `${config.restUrl}/progress/${config.gameId}?accountUserId=${config.currentUserId}`;
                const response=await fetch(url,{method:payload?'POST':'GET',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':config.nonce},...(payload?{body:JSON.stringify(payload)}:{})});
                const data=await response.json();
                if(!response.ok) { const error=Error(data.message || '계정 기록을 동기화하지 못했습니다.'); error.status=response.status; throw error; }
                jobs.shift(); api.synced = true; receive(data); status('계정 기록 동기화됨');
            } catch (error) {
                if (error.status === 400) jobs.shift();
                status(error.message || '연결을 확인하고 계정 기록을 다시 동기화하세요.', true);
                busy = false; return;
            }
            busy = false; if (jobs.length) pump();
        }
        Promise.resolve().then(pump);
        window.addEventListener('edu-games-record-deleted', () => { if (root.isConnected) api.read(); });
        return api;
    }
    window.EduGamesProgress = { connect };
})();
