(async function () {
    'use strict';
    const root = document.querySelector('[data-network-lab]'); if (!root) return;
    const config = JSON.parse(root.dataset.config || '{}');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    try {
        const asset = name => { const url = new URL(name, config.assetUrl); url.searchParams.set('ver', config.version); return url.href; };
        const [{scenario, LESSONS}, {validateProject, newNode, clone, VERSION, LIMITS}, {HELP}, {PROFILES, assignProfile, portLabel, configSnapshot}, {TOOLS_HELP}, {WINDOWS_HELP}, {resolveLaunch}] = await Promise.all(['scenarios.js', 'model.js', 'commands.js', 'device-config.js', 'text-help.js', 'windows-help.js', 'curriculum.js'].map(name => import(asset(name))));
        if (VERSION !== config.version) throw new Error('실습 자산 버전이 다릅니다. 캐시를 비우고 새로고침하세요.');
        const {exercise: linkedExercise, lesson: linkedLesson, invalid: invalidLink} = resolveLaunch(location.search);
        const localContext = linkedExercise?.id || '', backupPrefix = localContext ? `exercise:${localContext}:backup:` : 'backup:';
        const inLocalContext = key => String(key).startsWith(backupPrefix) || !localContext && key === 'current';
        let worker, snapshot, selected = '', tab = 'device', ready = false, dirty = false, epoch = 0, serial = 0, localTimer, serverBusy = false, conflict = false, pendingCreate = null, currentLesson = 'lan';
        let meta = freshMeta(), projectRows = [], historyIndex = 0, commandHistory = [], historyDraft = '', drag = null, localFailed = false, inspectorDirty = false, inspectorBaseline = '';
        const terminals = new Map();
        const histories = new Map();
        let commandLayout = '';
        let configurationRequest = 0, awaitingConfiguration = 0, loadingServer = false;
        const types = {pc: 'PC', server: '서버', hub: '허브', switch: '스위치', router: '라우터', firewall: '방화벽'};
        function freshMeta() { return {id: 0, revision: 0, clientId: crypto.randomUUID()}; }
        const $ = selector => root.querySelector(selector);
        root.innerHTML = `<header class="nl-header"><div><p class="nl-eyebrow">OJ NETWORK LAB <span class="nl-pill">브라우저 실행</span></p><h1>직접 연결하고, 통신을 확인하세요.</h1><p>허브에서 가상 VPN까지 · 명령어와 패킷으로 배우는 네트워크</p></div><button type="button" data-action="help">명령어 도움말</button></header>
        <div class="nl-status" role="status" aria-live="polite" data-status>실습을 준비하고 있습니다.</div>
        <div class="nl-conflict" data-conflict hidden>서버 저장 버전이 바뀌었습니다. 로컬 초안은 유지합니다. <button type="button" data-action="server-reload">서버본 불러오기</button> <button type="button" data-action="copy">새 사본으로 저장</button></div>
        <div class="nl-toolbar"><label class="nl-title-label">프로젝트 <input data-title maxlength="80" aria-label="프로젝트 제목"></label><label>다음에 열 예제 <select data-scenario>${LESSONS.map(l => `<option value="${l.id}">${esc(l.title)}</option>`).join('')}</select></label><button type="button" data-action="lesson">예제 열기</button><button type="button" data-action="save" class="nl-primary">서버 저장</button><button type="button" data-action="projects">내 프로젝트</button><button type="button" data-action="export">파일 내보내기</button><button type="button" data-action="import">파일 가져오기</button><input type="file" data-file accept="application/json,.json" hidden></div>
        <div class="nl-goal" data-goal></div><div class="nl-storage"><span data-local>로컬 초안 준비 중</span><span data-remote>서버에 저장하지 않은 프로젝트</span><button type="button" data-action="local-clear">로컬 초안 삭제</button></div>
        <section class="nl-projects" data-projects hidden><div class="nl-section-title"><h2>내 프로젝트</h2><button type="button" data-action="refresh-projects">목록 새로고침</button><button type="button" data-action="close-projects">닫기</button></div><div data-project-list></div><details><summary>이 브라우저의 복구 사본</summary><div data-backups></div></details></section>
        <div class="nl-workspace"><section class="nl-map-panel" aria-label="네트워크 구성도"><div class="nl-section-title"><h2>네트워크 구성도</h2><div><label class="nl-sr-only" for="nl-device-type">추가할 장비</label><select id="nl-device-type" data-add-type>${Object.entries(types).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select><select data-add-profile aria-label="새 장비 CLI 프로필"><option value="auto">권장 CLI</option><option value="linux">Linux</option><option value="ios">IOS XE</option><option value="fortios">FortiOS</option></select><button type="button" data-action="add-node">장비 추가</button><button type="button" data-action="links">케이블 연결</button></div></div><p class="nl-map-hint">장비를 선택해 설정·명령 실행 · 드래그 또는 선택 후 방향키로 이동</p><div class="nl-map-scroll"><svg data-map viewBox="0 0 1200 460" role="group" aria-label="장비와 케이블"></svg></div><div class="nl-playback"><button type="button" data-action="play">▶ 실행</button><button type="button" data-action="pause">Ⅱ 일시정지</button><button type="button" data-action="step">한 단계</button><button type="button" data-action="cancel">통신 중지</button><button type="button" data-action="reset">실행 초기화</button><label><input type="checkbox" data-autoplay checked>명령 실행 후 자동 진행</label><span data-clock></span></div></section>
        <aside class="nl-inspector"><nav class="nl-tabs" aria-label="장비 패널"><button type="button" data-tab="device">장비 설정</button><button type="button" data-tab="links">링크</button><button type="button" data-tab="state">상태</button></nav><div data-inspector></div></aside></div>
        <div class="nl-bottom"><section class="nl-terminal-panel"><div class="nl-section-title"><h2>장비 터미널</h2><select data-terminal-node aria-label="명령을 실행할 장비"></select><label data-shell-picker hidden><span class="nl-sr-only">Windows 셸</span><select data-terminal-shell aria-label="Windows 셸"><option value="cmd">CMD</option><option value="powershell">PowerShell</option></select></label><button type="button" data-action="terminal-submit" title="현재 명령 실행 (Enter)">실행 ↵</button><button type="button" data-action="tools-help">분석 도구</button><button type="button" data-action="clear-terminal">화면 지우기</button></div><div class="nl-terminal" data-terminal-screen role="group" aria-label="장비 터미널"><pre class="nl-terminal-output" data-terminal role="log" aria-label="터미널 출력" aria-live="polite" aria-relevant="additions text"></pre><form data-command-form class="nl-command"><label data-prompt for="nl-command">장비 $</label><textarea id="nl-command" data-command rows="1" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="2048" enterkeyhint="send" aria-label="장비 명령" aria-describedby="nl-terminal-hint"></textarea></form></div><p id="nl-terminal-hint" class="nl-sr-only">출력 다음 프롬프트에서 입력합니다. Enter 실행, 위·아래 화살표 이력, Tab 완성, Shift+Tab 이전 도구, Ctrl+C 중단, Ctrl+L 화면 지우기. 여러 줄 붙여넣기는 명령을 순서대로 실행합니다.</p></section>
        <section class="nl-trace-panel"><div class="nl-section-title"><h2>패킷 타임라인</h2><label><input type="checkbox" data-trace-selected>선택 장비만</label><button type="button" data-action="trace-export">관측 내보내기</button></div><p class="nl-muted">합성 관측 자료 · VPN 내부 패킷은 터널 종단에서 확인합니다. 최근 150건 표시</p><div class="nl-trace-scroll"><table><thead><tr><th>가상 ms</th><th>장비·포트</th><th>처리</th><th>통신</th><th>판정 근거</th></tr></thead><tbody data-trace></tbody></table></div></section></div>
        <details class="nl-help" data-help><summary>지원 명령어와 재현 범위</summary><pre data-help-text></pre><p>IPv6 정적 주소·경로·NS/NA·ICMPv6·MTU, IOS OSPFv2 단일 영역과 직접 연결 eBGP IPv4를 지원합니다. OSPF/BGP는 설정 변화 후 가상 500ms에 경로를 계산하는 교육용 제어 모델이며 실제 Hello·Dead·TCP·Hold 타이머와 같은 수렴 시간은 아닙니다. SLAAC·DAD·DHCPv6·IPv6 방화벽·OSPFv3·iBGP·MP-BGP·STP·실제 암호화·PCAP은 미지원입니다. 실행 상태는 초기화할 수 있으며 저장·내보내기는 네트워크 설정을 보관합니다. 브라우저 자기 점검은 공식 채점 결과가 아닙니다.</p></details>
        <dialog class="nl-dialog" data-dialog><form method="dialog"><p data-dialog-message></p><div><button value="cancel">취소</button><button value="ok" class="nl-primary">진행</button></div></form></dialog>`;
        controls(false);
        if (linkedExercise) $('[data-backups]').previousElementSibling.textContent = `${linkedExercise.id.toUpperCase()} 실습의 복구 사본`;
        function showGoal(detail, preset = false) {
            const goal = $('[data-goal]'), exercise = preset && currentLesson === linkedExercise?.scenario ? linkedExercise : null;
            goal.textContent = exercise ? `${exercise.id.toUpperCase()} · ${exercise.title}\n${exercise.goal}` : detail;
            if (!exercise) return;
            const reference = document.createElement('details'), summary = document.createElement('summary'), body = document.createElement('div');
            summary.textContent = '기본 예제 참고'; body.textContent = detail;
            reference.append(summary, body); goal.append(reference);
        }
        $('[data-add-profile]').insertAdjacentHTML('beforeend', '<option value="windows">Windows</option>');
        $('[data-help-text]').textContent = `장비 CLI 안내\nIOS: enable → configure terminal → interface Gi... → shutdown / no shutdown → end\nshow running-config / show startup-config / copy running-config startup-config / reload\n스위치: vlan 10 / switchport mode access|trunk / switchport access vlan 10 / interface Vlan10\n라우터: ip address IP MASK / ip route NET MASK GATEWAY / ip access-list standard|extended NAME\nFortiOS: config system interface|router static|firewall address|firewall service custom|firewall policy\nedit 이름·번호 → set 필드 값 → next 또는 end. abort는 현재 미확정 편집을 취소합니다.\n정책: set srcintf port1 / set dstintf port2 / set srcaddr all / set dstaddr all / set service PING / set action accept\nVPN: config vpn ipsec phase1-interface|phase2-interface / diagnose vpn tunnel list\n현재 모드에서 ?와 Tab을 사용하세요. 여러 줄 붙여넣기는 100줄까지 순서대로 실행하고 오류 줄에서 멈춥니다.\n프로젝트 저장은 실행 설정과 시작 설정을 함께 보관합니다. 장비 저장 명령과는 별개입니다.\nIOS reload, FortiOS execute reboot는 장비의 저장 설정을 복원합니다. 상단 실행 초기화는 현재 설정을 유지합니다.\n미지원: OSPF/BGP·STP·실제 암호화·실제 장비 SSH 접속·임의 셸. 가상 키만 사용하세요.\n\n${HELP}\n\n${TOOLS_HELP}`;
        $('[data-help-text]').textContent += `\n\n${WINDOWS_HELP}`;
        $('[data-help-text]').textContent = $('[data-help-text]').textContent.replace('미지원: OSPF/BGP·STP', '미지원: OSPFv3·iBGP·MP-BGP·STP');
        $('[data-help-text]').textContent += '\n\nIOS OSPF: router ospf 1 / router-id 1.1.1.1 / network NET WILDCARD area 0\ninterface Gi0/1 → ip ospf cost 10\nshow ip ospf neighbor / show ip ospf database / show ip ospf interface\nIOS eBGP: router bgp 65001 / neighbor IP remote-as AS / network NET mask MASK\nneighbor IP weight 200 / neighbor IP shutdown / no neighbor IP shutdown\nshow ip bgp summary / show ip bgp / show ip route';
        const db = new Promise((resolve, reject) => {
            const request = indexedDB.open(`oj-network-lab-${config.siteKey}-${config.userId}`, 1);
            request.onupgradeneeded = () => request.result.createObjectStore('drafts');
            request.onsuccess = () => resolve(request.result); request.onerror = () => reject(new Error('브라우저 저장소를 열지 못했습니다. 파일로 내보내세요.'));
        });
        db.catch(() => {});
        async function localOp(mode, operation) { const connection = await db; return new Promise((resolve, reject) => { const tx = connection.transaction('drafts', mode), store = tx.objectStore('drafts'); const request = operation(store); tx.oncomplete = () => resolve(request?.result); tx.onerror = () => reject(new Error('브라우저 초안을 저장하지 못했습니다. 파일로 내보내세요.')); tx.onabort = tx.onerror; }); }
        function status(message, error = false) { const el = $('[data-status]'); el.textContent = message; el.classList.toggle('is-error', error); }
        async function saveLocal(backup = false) {
            // A new reading tab must never replace the main workspace's current draft.
            backup = backup || !!linkedLesson;
            if (!snapshot) return; const generation = epoch, record = {project: clone(snapshot.project), meta: clone(meta), dirty, at: Date.now(), context: localContext};
            try {
                await db; if (generation !== epoch) return;
                await localOp('readwrite', store => store.put(record, backup ? `${backupPrefix}${record.meta.clientId}` : 'current'));
                if (backup) {
                    const connection = await db;
                    await new Promise((resolve, reject) => {
                        const tx = connection.transaction('drafts', 'readwrite'), store = tx.objectStore('drafts'), request = store.openCursor(), items = [];
                        request.onsuccess = () => { const cursor = request.result; if (cursor) { if (String(cursor.key).startsWith(backupPrefix)) items.push({key: cursor.key, at: cursor.value.at}); cursor.continue(); } else for (const item of items.sort((a, b) => b.at - a.at).slice(20)) store.delete(item.key); };
                        tx.oncomplete = resolve; tx.onerror = reject;
                    });
                }
                if (generation === epoch) { localFailed = false; $('[data-local]').textContent = `로컬 초안 저장됨 · ${new Date(record.at).toLocaleTimeString()}`; }
            }
            catch (error) { localFailed = true; $('[data-local]').textContent = '로컬 저장 실패 · 파일로 내보내세요'; status(error.message, true); }
        }
        function markDirty() { dirty = true; serial++; $('[data-remote]').textContent = '서버에 반영하지 않은 변경 있음'; clearTimeout(localTimer); localTimer = setTimeout(() => saveLocal(), 600); }
        async function api(path, method = 'GET', body) { const response = await fetch(new URL(path, config.restUrl), {method, credentials: 'same-origin', cache: 'no-store', headers: {'X-WP-Nonce': config.nonce, ...(body ? {'Content-Type': 'application/json'} : {})}, ...(body ? {body: JSON.stringify(body)} : {})}); const data = await response.json(); if (!response.ok) { const error = new Error(data.message || '서버 요청을 처리하지 못했습니다.'); error.status = response.status; throw error; } return data; }
        async function saveServer(quiet = false) {
            if (!snapshot || serverBusy) return;
            if (conflict) { if (!quiet) status('충돌을 먼저 해결하거나 새 사본으로 저장하세요.', true); return; }
            serverBusy = true; $('[data-action="save"]').disabled = true;
            const generation = epoch, savedSerial = serial, project = clone(snapshot.project), savedId = meta.id;
            const body = savedId ? {revision: meta.revision, project} : (pendingCreate ||= {clientId: meta.clientId, project});
            try {
                const result = await api(savedId ? `projects/${savedId}` : 'projects', savedId ? 'PUT' : 'POST', body);
                if (generation !== epoch) return;
                meta.id = result.id; meta.revision = result.revision; meta.clientId = result.clientId; pendingCreate = null;
                dirty = serial !== savedSerial || JSON.stringify(snapshot.project) !== JSON.stringify(validateProject(result.project));
                $('[data-remote]').textContent = dirty ? '이전 변경 저장됨 · 추가 변경은 저장 대기' : `서버 저장됨 · 버전 ${meta.revision}`;
                if (!quiet) status(dirty ? '서버에 저장했습니다. 추가 변경은 다음 저장에 반영됩니다.' : '서버에 저장했습니다.'); await saveLocal();
            } catch (error) {
                if (generation !== epoch) return;
                if (error.status === 409) { conflict = true; $('[data-conflict]').hidden = false; }
                $('[data-remote]').textContent = `서버 저장 실패: ${error.message}`; status(error.message + ' 로컬 초안 또는 파일 내보내기를 이용하세요.', true);
            } finally { serverBusy = false; $('[data-action="save"]').disabled = !ready; }
        }
        function terminal(text, id = selected) { const lines = terminals.get(id) || []; lines.push(...String(text).split('\n')); terminals.set(id, lines.slice(-400)); if (id === selected) renderTerminal(); }
        function resizeCommand(follow = true) { const input = $('[data-command]'), screen = $('[data-terminal-screen]'), scrollTop = screen.scrollTop, layout = `${input.clientWidth}:${input.value}`; if (layout !== commandLayout) { commandLayout = layout; input.style.height = '0px'; input.style.height = `${Math.max(parseFloat(getComputedStyle(input).lineHeight) || 20, input.scrollHeight)}px`; } screen.scrollTop = follow ? screen.scrollHeight : scrollTop; }
        function focusCommand() { const input = $('[data-command]'); if (!input.disabled) { input.focus({preventScroll: true}); resizeCommand(); } }
        function renderTerminal() {
            const node = snapshot?.project.nodes.find(n => n.id === selected), cli = snapshot?.runtime?.find(r => r.id === selected)?.cli, screen = $('[data-terminal-screen]'), follow = screen.scrollHeight - screen.scrollTop - screen.clientHeight < 40;
            $('[data-prompt]').textContent = cli?.prompt || `${node?.name || '장비 선택'} $`; $('[data-command]').placeholder = cli?.pending ? '확인 응답 또는 Enter' : '';
            $('[data-shell-picker]').hidden = node?.profile !== 'windows'; $('[data-terminal-shell]').value = cli?.shell || 'cmd'; $('[data-terminal-shell]').disabled = !ready || node?.profile !== 'windows';
            const pre = $('[data-terminal]'), text = (terminals.get(selected) || [node?.type === 'hub' ? '허브는 관리 명령을 지원하지 않습니다.' : `${PROFILES[node?.profile] || '장비'} · help 또는 ?로 명령어를 확인하세요.`]).join('\n');
            if (pre.textContent !== text) pre.textContent = text;
            $('[data-terminal-node]').value = selected; $('[data-command]').disabled = !node || !ready || node.type === 'hub'; $('[data-action="terminal-submit"]').disabled = $('[data-command]').disabled;
            $('[data-command-form]').hidden = !node || node.type === 'hub'; resizeCommand(follow);
        }
        function send(type, extra = {}) { worker?.postMessage({type, ...extra}); }
        function controls(enabled) { root.querySelectorAll('button, input, select, textarea').forEach(el => el.disabled = !enabled); if (enabled) { $('[data-action="save"]').disabled = serverBusy; $('[data-command]').disabled = !selected || snapshot?.project.nodes.find(n => n.id === selected)?.type === 'hub'; $('[data-action="terminal-submit"]').disabled = $('[data-command]').disabled; } }
        function mountWorker(project) {
            if (worker) worker.terminate(); awaitingConfiguration = 0; ready = false; controls(false); worker = new Worker(asset('worker.js'), {type: 'module'});
            const thisWorker = worker;
            worker.onmessage = event => {
                if (worker !== thisWorker) return;
                const m = event.data;
                if (m.type === 'terminal') { if (m.clear) { terminals.set(m.node, []); if (m.node === selected) renderTerminal(); } else terminal(m.text, m.node); }
                else if (m.type === 'changed') markDirty();
                else if (m.type === 'state') {
                    if (loadingServer) return;
                    if (awaitingConfiguration && m.configurationRequest !== awaitingConfiguration) return;
                    const configured = !!awaitingConfiguration, choicesChanged = JSON.stringify(snapshot?.project.nodes.map(n => [n.id, n.name])) !== JSON.stringify(m.project.nodes.map(n => [n.id, n.name]));
                    awaitingConfiguration = 0;
                    const wasReady = ready;
                    if (drag) { const old = snapshot.project.nodes.find(n => n.id === drag.id), next = m.project.nodes.find(n => n.id === drag.id); if (old && next) { next.x = old.x; next.y = old.y; } }
                    snapshot = m; ready = true;
                    if (!snapshot.project.nodes.some(n => n.id === selected)) selected = snapshot.project.nodes[0]?.id || '';
                    if (dirty && !wasReady) saveLocal();
                    renderCanvas(); renderTrace(); renderRuntime(); renderTerminal();
                    if (choicesChanged) renderChoices();
                    if (!wasReady) { renderChoices(); renderInspector(); controls(true); }
                    else if (!inspectorDirty && !$('[data-inspector]').contains(document.activeElement) && tab === 'device' && snapshot.project.nodes.find(n => n.id === selected)?.profile !== 'linux') renderInspector();
                    $('[data-clock]').textContent = `${m.now} ms · 대기 ${m.queued} · 처리 ${m.processed} · ${m.playing ? '실행 중' : '일시정지'}`;
                    if (m.pausedReason) status(m.pausedReason, true);
                    else if (configured) status(snapshot.project.nodes.find(n => n.id === selected)?.profile === 'windows' ? 'Windows 설정을 적용했습니다. 서버 저장으로 프로젝트를 보관하세요.' : '실행 설정을 적용했습니다. 장비의 시작 설정 저장 여부를 확인하세요.');
                } else if (m.type === 'trace') download({format: 'synthetic-network-trace', engine: VERSION, trace: m.trace}, 'network-trace.json');
                else if (m.type === 'complete') { if (m.node === selected && $('[data-command]').value === m.original) { $('[data-command]').value = m.line; resizeCommand(); } if (m.choices.length !== 1) terminal(m.choices.join('  ') || '(완성할 명령 없음)', m.node); }
                else if (m.type === 'error') { if (m.configure && m.requestId === awaitingConfiguration) awaitingConfiguration = 0; if (!m.printed) terminal(`오류: ${m.message}`, m.node || selected); status(m.message, true); }
            };
            worker.onerror = event => { ready = false; controls(true); $('[data-command]').disabled = true; status(`실습 엔진을 불러오지 못했습니다. JavaScript 파일·브라우저 정책을 확인하세요. ${event.message || ''}`, true); };
            send('load', {project});
        }
        async function begin(project, metadata = freshMeta(), changed = false, preferredNode = '') {
            const valid = validateProject(project);
            ready = false; controls(false);
            if (worker) { worker.terminate(); worker = null; }
            if (snapshot && dirty) await saveLocal(true);
            clearTimeout(localTimer); epoch++; serial = 0; meta = metadata; conflict = false; pendingCreate = null; dirty = changed; terminals.clear(); histories.clear(); selected = valid.nodes.find(n => n.id === preferredNode)?.id || valid.nodes[0]?.id || ''; snapshot = {project: valid, trace: [], runtime: [], now: 0, queued: 0};
            $('[data-conflict]').hidden = true; $('[data-title]').value = valid.title; $('[data-remote]').textContent = meta.id ? `서버 프로젝트 ${meta.id} · 버전 ${meta.revision}` : '서버에 저장하지 않은 프로젝트';
            mountWorker(valid); renderChoices(); renderCanvas(); renderInspector(); controls(false); if (changed) markDirty();
        }
        function apply(project) { const valid = validateProject(project); ready = false; controls(false); awaitingConfiguration = ++configurationRequest; send('configure', {project: valid, requestId: awaitingConfiguration}); markDirty(); status('설정을 적용하고 있습니다. 변경된 장비의 관련 캐시·세션을 갱신합니다.'); }
        async function openLesson(lesson, changed) {
            await begin(scenario(lesson.id), freshMeta(), changed, lesson.node);
            currentLesson = lesson.id;
            $('[data-scenario]').value = lesson.id;
            showGoal(lesson.goal, true);
        }
        function renderChoices() { const select = $('[data-terminal-node]'); select.innerHTML = snapshot.project.nodes.map(n => `<option value="${esc(n.id)}">${esc(n.name)}</option>`).join(''); select.value = selected; }
        function pick(id) { selected = id; commandHistory = histories.get(id) || []; historyIndex = commandHistory.length; historyDraft = ''; $('[data-command]').value = ''; renderChoices(); renderCanvas(); renderInspector(); renderTerminal(); $('[data-terminal-screen]').scrollTop = $('[data-terminal-screen]').scrollHeight; }
        function svgEl(tag, attrs, content) { const el = document.createElementNS('http://www.w3.org/2000/svg', tag); for (const [key, value] of Object.entries(attrs)) el.setAttribute(key, value); if (content !== undefined) el.textContent = content; return el; }
        function renderCanvas() {
            if (!snapshot) return; const map = $('[data-map]'), p = snapshot.project;
            const width = Math.max(1000, ...p.nodes.map(n => n.x + 100)), height = Math.max(400, ...p.nodes.map(n => n.y + 90)); map.setAttribute('viewBox', `0 0 ${width} ${height}`); map.replaceChildren();
            const defs = svgEl('defs', {}), pattern = svgEl('pattern', {id: 'nl-dots', width: 24, height: 24, patternUnits: 'userSpaceOnUse'}); pattern.append(svgEl('circle', {cx: 2, cy: 2, r: 1, class: 'nl-dot'})); defs.append(pattern); map.append(defs, svgEl('rect', {width: '100%', height: '100%', fill: 'url(#nl-dots)'}));
            for (const link of p.links) {
                const a = p.nodes.find(n => n.id === link.a.node), b = p.nodes.find(n => n.id === link.b.node); if (!a || !b) continue;
                const path = svgEl('path', {d: `M ${a.x} ${a.y} L ${b.x} ${b.y}`, class: `nl-wire${link.up ? '' : ' is-down'}${snapshot.lastLink === link.id ? ' is-active' : ''}`, 'data-link': link.id, tabindex: 0, role: 'button', 'aria-label': `케이블 ${a.name} ${link.a.port} - ${b.name} ${link.b.port}`}); map.append(path);
                map.append(svgEl('text', {x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 - 12, class: 'nl-link-label'}, `${link.a.port} ↔ ${link.b.port}${link.up ? '' : ' · 끊김'}`));
            }
            if (!p.nodes.length) map.append(svgEl('text', {x: 24, y: 48, class: 'nl-empty-label'}, '장비 없음'));
            for (const n of p.nodes) {
                const g = svgEl('g', {transform: `translate(${n.x - 65} ${n.y - 37})`, 'data-node': n.id, class: `nl-node${n.id === selected ? ' is-selected' : ''}`, role: 'button', tabindex: 0, 'aria-label': `${n.name} ${types[n.type]}. Enter로 선택, 방향키로 이동`});
                g.append(svgEl('rect', {width: 130, height: 74, rx: 12, class: 'nl-node-box'}));
                g.append(svgEl('text', {x: 12, y: 24, class: 'nl-node-type'}, types[n.type]));
                g.append(svgEl('text', {x: 12, y: 46, class: 'nl-node-name'}, n.name.length > 15 ? `${n.name.slice(0, 14)}…` : n.name));
                const address = n.interfaces.find(f => f.address)?.address || n.interfaces.find(f => f.ipv6?.length)?.ipv6[0] || `${n.interfaces.length} ports`;
                g.append(svgEl('text', {x: 12, y: 62, class: 'nl-node-ip'}, address), svgEl('title', {}, `${n.name}\n${address}`)); map.append(g);
                for (const label of g.querySelectorAll('.nl-node-name, .nl-node-ip')) {
                    const full = label.textContent; let length = full.length;
                    while (label.getComputedTextLength() > 106 && length > 1) label.textContent = full.slice(0, --length) + '…';
                }
            }
        }
        function renderTrace() {
            if (!snapshot) return; const filter = $('[data-trace-selected]').checked;
            const events = snapshot.trace.filter(t => !filter || t.node === selected);
            $('[data-trace]').innerHTML = events.length ? events.map(t => `<tr class="${t.action === 'drop' ? 'nl-drop' : ''}"><td>${t.at}</td><td>${esc(snapshot.project.nodes.find(n => n.id === t.node)?.name || t.node)}<br>${esc(t.port)}</td><td>${esc(t.action)}</td><td>${esc(t.protocol)} ${esc(t.flags)}<br>${esc(t.src)}${t.sport ? `:${t.sport}` : ''} → ${esc(t.dst)}${t.dport ? `:${t.dport}` : ''}</td><td>${esc(t.detail)}</td></tr>`).join('') : '<tr><td colspan="5">통신을 실행하면 패킷 처리 과정이 여기에 나타납니다.</td></tr>';
            const scroll = $('.nl-trace-scroll'); scroll.scrollTop = scroll.scrollHeight;
        }
        function renderRuntime() { const pre = $('[data-runtime]'); if (pre) pre.textContent = JSON.stringify(snapshot.runtime?.find(r => r.id === selected) || {}, null, 2); }
        const field = (label, name, value = '', type = 'text', attrs = '') => `<label>${label}<input name="${esc(name)}" type="${type}" value="${esc(value)}" ${attrs}></label>`;
        function renderInspector() {
            inspectorDirty = false;
            if (!snapshot) return; const panel = $('[data-inspector]'), n = snapshot.project.nodes.find(n => n.id === selected);
            root.querySelectorAll('[data-tab]').forEach(b => { b.classList.toggle('is-current', b.dataset.tab === tab); b.setAttribute('aria-pressed', String(b.dataset.tab === tab)); });
            if (tab === 'links') {
                const p = snapshot.project, occupied = new Set(p.links.flatMap(l => [`${l.a.node}/${l.a.port}`, `${l.b.node}/${l.b.port}`]));
                const ports = p.nodes.flatMap(n => n.interfaces.filter(f => f.kind === 'ethernet' && !occupied.has(`${n.id}/${f.name}`)).map(f => `<option value="${n.id}/${f.name}">${esc(n.name)} · ${f.name}</option>`)).join('');
                panel.innerHTML = `<h3>케이블 연결</h3><form data-link-form><label>출발 포트<select name="a">${ports}</select></label><label>도착 포트<select name="b">${ports}</select></label>${field('지연 (ms)', 'delay', 5, 'number', 'min="0" max="2000"')}${field('손실 (%)', 'loss', 0, 'number', 'min="0" max="100"')}${field('속도 (Mbps)', 'rate', 100, 'number', 'min="1" max="10000"')}<button type="submit">케이블 추가</button></form><h3>연결된 케이블</h3>${p.links.map(l => `<div class="nl-link-item"><strong>${esc(p.nodes.find(n => n.id === l.a.node)?.name)} ${l.a.port} ↔ ${esc(p.nodes.find(n => n.id === l.b.node)?.name)} ${l.b.port}</strong><p>${l.delay}ms · 손실 ${l.loss}% · ${l.rate}Mbps</p><button type="button" data-action="link-toggle" data-id="${l.id}">${l.up ? '연결 끊기' : '연결 복구'}</button> <button type="button" data-action="link-remove" data-id="${l.id}">삭제</button></div>`).join('') || '<p>아직 연결한 케이블이 없습니다.</p>'}`;
                return;
            }
            if (!n) { panel.innerHTML = '<p>장비를 추가하거나 구성도에서 선택하세요.</p>'; return; }
            if (tab === 'state') { panel.innerHTML = `<h3>${esc(n.name)} 내부 상태</h3><pre class="nl-runtime" data-runtime></pre>`; renderRuntime(); return; }
            if (n.profile === 'windows') { renderWindows(n, panel); return; }
            if (n.profile !== 'linux') { renderManaged(n, panel); return; }
            panel.innerHTML = `<h3>${esc(n.name)} 설정</h3><form data-node-form>${field('이름', 'name', n.name, 'text', 'maxlength="40" required')}<label>장비 종류<select name="type">${Object.entries(types).map(([k, v]) => `<option value="${k}" ${n.type === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label><label class="nl-check"><input type="checkbox" name="forwarding" ${n.forwarding ? 'checked' : ''}>IP forwarding</label>${field('DNS 서버', 'resolver', n.resolver)}
            ${n.interfaces.map((f, i) => `<fieldset><legend>${f.name} · ${f.kind}</legend><label class="nl-check"><input type="checkbox" name="up-${i}" ${f.up ? 'checked' : ''}>포트 켜짐</label>${field('IPv4/접두사', `address-${i}`, f.address)}${f.kind === 'ethernet' ? `${field('Access / Native VLAN', `vlan-${i}`, f.vlan, 'number', 'min="1" max="4094"')}<label>포트 모드<select name="mode-${i}"><option value="access" ${f.mode === 'access' ? 'selected' : ''}>access</option><option value="trunk" ${f.mode === 'trunk' ? 'selected' : ''}>trunk</option></select></label>${field('허용 VLAN (쉼표 구분)', `allowed-${i}`, f.allowedVlans.join(','))}` : `${field('가상 개인키', `key-${i}`, f.privateKey)}${field('수신 UDP 포트', `listen-${i}`, f.listenPort, 'number')}<label>피어 JSON<textarea name="peers-${i}" rows="6" spellcheck="false">${esc(JSON.stringify(f.peers, null, 2))}</textarea></label>`}</fieldset>`).join('')}
            <details><summary>정적 경로와 서비스 설정</summary><label>정적 경로 JSON<textarea name="routes" rows="5" spellcheck="false">${esc(JSON.stringify(n.routes, null, 2))}</textarea></label><label class="nl-check"><input type="checkbox" name="http-on" ${n.services.http ? 'checked' : ''}>HTTP 서버</label>${field('HTTP 포트', 'http-port', n.services.http?.port || 80, 'number')}<label>HTTP 응답<textarea name="http-body">${esc(n.services.http?.body || '가상 웹 서버입니다.')}</textarea></label><label class="nl-check"><input type="checkbox" name="dns-on" ${n.services.dns ? 'checked' : ''}>DNS 서버 (A 레코드)</label><label>이름=주소 (한 줄에 하나)<textarea name="dns-records" rows="4">${esc(Object.entries(n.services.dns?.records || {}).map(([name, ip]) => `${name}=${ip}`).join('\n'))}</textarea></label><label class="nl-check"><input type="checkbox" name="dhcp-on" ${n.services.dhcp ? 'checked' : ''}>DHCP 서버</label>${field('풀 시작 주소', 'dhcp-start', n.services.dhcp?.start || '192.168.10.100')}${field('풀 끝 주소', 'dhcp-end', n.services.dhcp?.end || '192.168.10.150')}${field('접두사 길이', 'dhcp-prefix', n.services.dhcp?.prefix || 24, 'number')}${field('게이트웨이', 'dhcp-gateway', n.services.dhcp?.gateway || '192.168.10.1')}${field('배포할 DNS 주소', 'dhcp-dns', n.services.dhcp?.dns || '')}</details><button type="submit" class="nl-primary">장비 설정 적용</button></form><p class="nl-muted">설정 적용 시 진행 중 통신을 초기화합니다. 방화벽·VPN 명령어는 help에서 확인하세요.</p>${profilePicker(n)}<button type="button" data-action="add-wg">WireGuard 포트 추가</button> <button type="button" data-action="remove-node">장비 삭제</button>`;
            panel.querySelector('[data-node-form] button[type="submit"]').insertAdjacentHTML('beforebegin', ipv6Fields(n));
        }
        function ipv6Fields(n) {
            if (!['pc', 'server', 'router'].includes(n.type)) return '';
            return `<details data-ipv6><summary>IPv6 주소와 경로</summary><label class="nl-check"><input type="checkbox" name="forwarding6" ${n.forwarding6 ? 'checked' : ''}>IPv6 forwarding</label>${n.interfaces.map((f, i) => f.kind === 'ethernet' ? `<fieldset><legend>${esc(f.name)}</legend>${field('IPv6/접두사 (쉼표 구분)', `ipv6-${i}`, (f.ipv6 || []).join(', '))}${field('MTU', `mtu-${i}`, f.mtu || 1500, 'number', 'min="1280" max="9000"')}</fieldset>` : '').join('')}<label>IPv6 정적 경로 JSON<textarea name="routes6" rows="4" spellcheck="false">${esc(JSON.stringify(n.routes6 || [], null, 2))}</textarea></label></details>`;
        }
        function profilePicker(n) { return `<label>CLI 프로필<select data-profile>${['linux', ...(['pc', 'server'].includes(n.type) ? ['windows'] : n.type === 'firewall' ? ['fortios'] : ['router', 'switch'].includes(n.type) ? ['ios'] : [])].map(p => `<option value="${p}" ${p === n.profile ? 'selected' : ''}>${PROFILES[p]}</option>`).join('')}</select></label><button type="button" data-action="profile-change">프로필 교체</button><p class="nl-muted">교체 시 장비별 정책·저장 설정을 새 프로필로 초기화합니다.</p>`; }
        function renderWindows(n, panel) {
            inspectorBaseline = JSON.stringify(configSnapshot(n));
            panel.innerHTML = `<h3>${esc(n.name)} · Windows</h3><p class="nl-muted">CMD·PowerShell이 주소·경로·DNS·방화벽을 공유합니다. 셸은 터미널 상단에서 전환합니다.</p><form data-windows-form>${field('호스트 이름', 'name', n.name, 'text', 'maxlength="32" pattern="[A-Za-z][A-Za-z0-9_-]*" required')}${n.interfaces.map((f, i) => { const a = n.windows.adapters.find(a => a.name === f.name); return `<fieldset><legend>${esc(portLabel(n, f))} · ifIndex ${i + 1}</legend><label class="nl-check"><input type="checkbox" name="up-${i}" ${f.up ? 'checked' : ''}>어댑터 켜짐</label><label class="nl-check"><input type="checkbox" name="dhcp-${i}" ${a.dhcp ? 'checked' : ''}>DHCP</label>${field('현재 IPv4/접두사', `address-${i}`, f.address)}<label>네트워크 프로필<select name="category-${i}"><option value="Public" ${a.category === 'Public' ? 'selected' : ''}>Public</option><option value="Private" ${a.category === 'Private' ? 'selected' : ''}>Private</option></select></label>${field('인터페이스 메트릭', `metric-${i}`, a.metric, 'number', 'min="0" max="65535"')}<label class="nl-check"><input type="checkbox" name="auto-dns-${i}" ${a.dnsAutomatic ? 'checked' : ''}>DHCP DNS 사용</label>${field('DNS 서버 (IPv4 하나)', `dns-${i}`, a.dns)}</fieldset>`; }).join('')}<details><summary>정적 경로와 HTTP 서비스</summary><label>정적 경로 JSON<textarea name="routes" rows="4" spellcheck="false">${esc(JSON.stringify(n.routes, null, 2))}</textarea></label><label class="nl-check"><input type="checkbox" name="http-on" ${n.services.http ? 'checked' : ''}>HTTP 서버</label>${field('HTTP 포트', 'http-port', n.services.http?.port || 80, 'number')}<label>HTTP 응답<textarea name="http-body">${esc(n.services.http?.body || 'Windows 가상 웹 서버입니다.')}</textarea></label></details><button type="submit" class="nl-primary">Windows 설정 적용</button></form><p class="nl-muted">DHCP 주소 취득은 ipconfig /renew를 실행하세요. 수동 주소는 DHCP를 해제한 뒤 설정합니다. 호스트 방화벽은 아래 PowerShell 명령으로 관리합니다.</p><h3>Windows 방화벽</h3><p>${n.windows.profiles.map(p => `${p.name}: ${p.enabled ? 'On' : 'Off'} · In ${p.inbound} / Out ${p.outbound}`).join('<br>')}</p><p>규칙 ${n.windows.rules.length}개 · Get-NetFirewallRule / New-NetFirewallRule / Set-NetFirewallRule / Remove-NetFirewallRule</p>${profilePicker(n)}<button type="button" data-action="remove-node">장비 삭제</button>`;
        }
        function renderManaged(n, panel) {
            inspectorBaseline = JSON.stringify(configSnapshot(n));
            panel.innerHTML = `<h3>${esc(n.name)} · ${PROFILES[n.profile]}</h3><p>교육용 명령 부분집합 · 현재 모드의 ?로 문법 확인</p><form data-managed-form>${field('장비 이름', 'name', n.name, 'text', 'maxlength="32" pattern="[A-Za-z][A-Za-z0-9_-]*" required')}<label class="nl-check"><input type="checkbox" name="forwarding" ${n.forwarding ? 'checked' : ''}>IP 라우팅</label>${n.interfaces.map((f, i) => `<fieldset><legend>${esc(portLabel(n, f))} · ${f.kind}</legend><label class="nl-check"><input type="checkbox" name="up-${i}" ${f.up ? 'checked' : ''}>관리 상태 UP</label>${f.kind !== 'ipsec' && !(n.type === 'switch' && f.kind === 'ethernet' && f.switchport !== false) ? field('IPv4/접두사', `address-${i}`, f.address) : ''}${field('설명', `description-${i}`, f.description || '', 'text', 'maxlength="80"')}${n.type === 'switch' && f.kind === 'ethernet' && f.switchport !== false ? `<label>포트 모드<select name="mode-${i}"><option value="access" ${f.mode === 'access' ? 'selected' : ''}>access</option><option value="trunk" ${f.mode === 'trunk' ? 'selected' : ''}>trunk</option></select></label>${field('Access / Native VLAN', `vlan-${i}`, f.vlan, 'number', 'min="1" max="4094"')}<label class="nl-check"><input type="checkbox" name="all-${i}" ${f.trunkAll ? 'checked' : ''}>trunk 모든 VLAN 허용</label>${field('허용 VLAN (쉼표 구분)', `allowed-${i}`, f.allowedVlans.join(','))}` : ''}${n.profile === 'fortios' && f.kind === 'ethernet' ? `<label class="nl-check"><input type="checkbox" name="ping-${i}" ${f.allowPing ? 'checked' : ''}>장비 관리 ping 허용</label>` : ''}</fieldset>`).join('')}<button type="submit" class="nl-primary">실행 설정 적용</button></form><p class="nl-muted">IOS의 시작 설정은 copy running-config startup-config로 저장합니다. FortiOS는 설정된 automatic/manual 방식을 따릅니다. CLI와 GUI가 같은 설정을 사용합니다.</p>${profilePicker(n)}<button type="button" data-action="remove-node">장비 삭제</button>`;
        }
        async function confirmAction(message) { const dialog = $('[data-dialog]'); $('[data-dialog-message]').textContent = message; dialog.showModal(); return new Promise(resolve => dialog.addEventListener('close', () => resolve(dialog.returnValue === 'ok'), {once: true})); }
        function download(data, name) { const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json;charset=utf-8'}), url = URL.createObjectURL(blob), a = document.createElement('a'); a.href = url; a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(url), 2000); }
        async function listProjects() {
            $('[data-projects]').hidden = false; $('[data-project-list]').textContent = '서버 목록을 불러오고 있습니다.';
            try { const data = await api('projects'); projectRows = data.projects; $('[data-project-list]').innerHTML = projectRows.length ? projectRows.map(p => `<div class="nl-project-row"><span><strong>${esc(p.title)}</strong> · 버전 ${Number(p.revision)} · ${esc(p.updated_at)} UTC</span><button type="button" data-action="load-project" data-id="${Number(p.id)}">열기</button><button type="button" data-action="delete-project" data-id="${Number(p.id)}">서버에서 삭제</button></div>`).join('') : '<p>서버에 저장한 프로젝트가 없습니다.</p>'; } catch (error) { $('[data-project-list]').textContent = error.message; }
            try { const records = (await localOp('readonly', store => store.getAll())).filter(r => (r.context || '') === localContext).sort((a, b) => b.at - a.at); $('[data-backups]').innerHTML = records.map((r, i) => `<div class="nl-project-row"><span>${esc(r.project.title)} · ${new Date(r.at).toLocaleString()}</span><button type="button" data-local-index="${i}">복구</button></div>`).join('') || '<p>로컬 복구 사본이 없습니다.</p>'; $('[data-backups]')._records = records; } catch (error) { $('[data-backups]').textContent = error.message; }
        }
        async function loadServer(id) {
            if (loadingServer) return;
            loadingServer = true; ready = false; controls(false); send('pause'); status('서버 프로젝트를 불러오고 있습니다.');
            try { const result = await api(`projects/${id}`); loadingServer = false; await begin(result.project, {id: result.id, revision: result.revision, clientId: result.clientId}); currentLesson = ''; showGoal('저장한 네트워크 설정을 불러왔습니다. 실행 상태는 새로 시작합니다.'); status('서버 프로젝트를 불러왔습니다.'); }
            catch (error) { loadingServer = false; ready = !!snapshot; controls(ready); renderTerminal(); throw error; }
        }
        async function action(name, button) {
            if (name === 'help') { $('[data-help]').open = true; $('[data-help]').scrollIntoView({behavior: 'smooth'}); }
            else if (name === 'terminal-submit') { $('[data-command-form]').requestSubmit(); focusCommand(); }
            else if (name === 'tools-help') { terminal((snapshot?.project.nodes.find(n => n.id === selected)?.profile === 'windows' ? WINDOWS_HELP + '\n\n' : '') + TOOLS_HELP); focusCommand(); }
            else if (['play', 'pause', 'step', 'cancel'].includes(name)) send(name);
            else if (name === 'reset') { send('cancel'); mountWorker(snapshot.project); status('설정을 유지하고 패킷·시간·캐시를 초기화했습니다.'); }
            else if (name === 'clear-terminal') { terminals.set(selected, []); renderTerminal(); focusCommand(); }
            else if (name === 'links') { tab = 'links'; renderInspector(); }
            else if (name === 'lesson') { await openLesson(LESSONS.find(l => l.id === $('[data-scenario]').value), true); status('예제를 열었습니다.'); }
            else if (name === 'save') await saveServer();
            else if (name === 'copy') { await saveLocal(true); meta = freshMeta(); conflict = false; pendingCreate = null; $('[data-conflict]').hidden = true; markDirty(); await saveServer(); }
            else if (name === 'server-reload') { if (meta.id) await loadServer(meta.id); else await listProjects(); }
            else if (name === 'projects' || name === 'refresh-projects') await listProjects();
            else if (name === 'close-projects') $('[data-projects]').hidden = true;
            else if (name === 'load-project') await loadServer(Number(button.dataset.id));
            else if (name === 'delete-project') { const id = Number(button.dataset.id), row = projectRows.find(p => Number(p.id) === id); if (row && await confirmAction(`서버의 “${row.title}” 프로젝트를 삭제할까요? 브라우저 복구 사본은 유지합니다.`)) { await api(`projects/${id}`, 'DELETE', {revision: Number(row.revision)}); if (meta.id === id) { meta = freshMeta(); markDirty(); } await listProjects(); status('서버 프로젝트를 삭제했습니다.'); } }
            else if (name === 'export') download(snapshot.project, 'oj-network-lab.json');
            else if (name === 'trace-export') send('trace');
            else if (name === 'import') $('[data-file]').click();
            else if (name === 'add-node') { const p = clone(snapshot.project); if (p.nodes.length >= LIMITS.nodes) throw new Error('장비는 최대 20개입니다.'); let index = 1; while (p.nodes.some(n => n.id === `n${index}`)) index++; const n = newNode($('[data-add-type]').value, index), chosen = $('[data-add-profile]').value; assignProfile(n, chosen === 'auto' ? n.type === 'firewall' ? 'fortios' : ['router', 'switch'].includes(n.type) ? 'ios' : 'linux' : chosen); if (n.profile === 'ios' && n.type === 'router') { n.interfaces.forEach(f => f.up = false); n.startup = configSnapshot(n); } p.nodes.push(n); terminals.delete(n.id); histories.delete(n.id); selected = n.id; apply(p); }
            else if (name === 'profile-change') { const profile = $('[data-profile]').value, node = snapshot.project.nodes.find(n => n.id === selected); if (profile !== node.profile && await confirmAction('CLI 프로필을 교체할까요? 물리 포트와 유효한 경로를 유지하고 정책·논리 포트·장비 시작 설정을 초기화합니다.')) { const p = clone(snapshot.project); assignProfile(p.nodes.find(n => n.id === selected), profile); apply(p); } }
            else if (name === 'remove-node') { if (selected && await confirmAction('선택 장비와 연결된 케이블을 삭제할까요?')) { const p = clone(snapshot.project); p.nodes = p.nodes.filter(n => n.id !== selected); p.links = p.links.filter(l => l.a.node !== selected && l.b.node !== selected); apply(p); } }
            else if (name === 'add-wg') { const n = snapshot.project.nodes.find(n => n.id === selected); let index = 0; while (n.interfaces.some(f => f.name === `wg${index}`)) index++; send('command', {node: selected, line: `ip link add wg${index} type wireguard`, autoplay: false}); setTimeout(renderInspector, 100); }
            else if (name === 'link-toggle' || name === 'link-remove') { const p = clone(snapshot.project), id = button.dataset.id; if (name === 'link-remove') p.links = p.links.filter(l => l.id !== id); else { const l = p.links.find(l => l.id === id); l.up = !l.up; } apply(p); }
            else if (name === 'local-clear' && await confirmAction(`${linkedExercise ? linkedExercise.id.toUpperCase() + ' 실습의' : '일반 실습의'} 로컬 초안과 복구 사본을 삭제할까요? 다른 실습과 서버 프로젝트는 유지합니다.`)) {
                clearTimeout(localTimer);
                await localOp('readwrite', store => { const request = store.openCursor(); request.onsuccess = () => { const cursor = request.result; if (cursor) { if (inLocalContext(cursor.key)) cursor.delete(); cursor.continue(); } }; return request; });
                $('[data-backups]')._records = []; $('[data-backups]').textContent = '로컬 복구 사본이 없습니다.';
                $('[data-local]').textContent = '현재 실습의 로컬 초안을 삭제했습니다.'; status('현재 실습의 로컬 초안과 복구 사본을 삭제했습니다.');
            }
        }
        root.addEventListener('click', event => {
            const tabButton = event.target.closest('[data-tab]'); if (tabButton) { tab = tabButton.dataset.tab; renderInspector(); return; }
            const restore = event.target.closest('[data-local-index]'); if (restore) { const r = $('[data-backups]')._records?.[Number(restore.dataset.localIndex)]; if (r) begin(r.project, r.meta, true).then(() => { currentLesson = ''; showGoal('선택한 로컬 복구 사본을 열었습니다. 실행 상태는 새로 시작합니다.'); status('로컬 복구 사본을 열었습니다.'); }).catch(error => status(error.message, true)); return; }
            const button = event.target.closest('[data-action]'); if (button) action(button.dataset.action, button).catch(error => status(error.message, true));
        });
        root.addEventListener('submit', event => {
            if (event.target.closest('[data-dialog]')) return;
            event.preventDefault();
            try {
                if (event.target.matches('[data-command-form]')) { const input = $('[data-command]'), line = input.value.trim(), pending = snapshot.runtime?.find(r => r.id === selected)?.cli.pending; if (!ready || input.disabled) return; input.value = ''; resizeCommand(); if (!line && !pending) { terminal(`${$('[data-prompt]').textContent} `); return; } commandHistory = [...(histories.get(selected) || []), line].slice(-100); histories.set(selected, commandHistory); historyIndex = commandHistory.length; historyDraft = ''; send('command', {node: selected, line, autoplay: $('[data-autoplay]').checked}); }
                else if (event.target.matches('[data-windows-form]')) {
                    const data = new FormData(event.target), p = clone(snapshot.project), n = p.nodes.find(n => n.id === selected);
                    if (inspectorBaseline !== JSON.stringify(configSnapshot(n))) throw new Error('CLI에서 설정이 바뀌었습니다. 장비를 다시 선택해 최신 설정을 확인하세요.');
                    n.name = data.get('name'); n.routes = JSON.parse(data.get('routes'));
                    n.interfaces.forEach((f, i) => { const a = n.windows.adapters.find(a => a.name === f.name), address = data.get(`address-${i}`).trim(), dhcp = data.has(`dhcp-${i}`); if (dhcp && address !== f.address) throw new Error('수동 주소를 바꾸려면 DHCP를 먼저 해제하세요.'); if (dhcp && !a.dhcp) { f.address = ''; n.routes = n.routes.filter(r => r.dev !== f.name || r.dest !== '0.0.0.0/0'); } else f.address = address; f.up = data.has(`up-${i}`); a.dhcp = dhcp; a.category = data.get(`category-${i}`); a.metric = Number(data.get(`metric-${i}`)); a.dnsAutomatic = data.has(`auto-dns-${i}`); a.dns = a.dnsAutomatic ? a.leaseDns : data.get(`dns-${i}`).trim(); });
                    n.services.http = data.has('http-on') ? {port: Number(data.get('http-port')), body: data.get('http-body')} : null;
                    inspectorDirty = false; apply(p);
                }
                else if (event.target.matches('[data-managed-form]')) {
                    const data = new FormData(event.target), p = clone(snapshot.project), n = p.nodes.find(n => n.id === selected);
                    if (inspectorBaseline !== JSON.stringify(configSnapshot(n))) throw new Error('CLI에서 설정이 바뀌었습니다. 장비를 다시 선택해 최신 설정을 확인하세요.');
                    n.name = data.get('name'); n.forwarding = data.has('forwarding');
                    n.interfaces.forEach((f, i) => { f.up = data.has(`up-${i}`); f.description = data.get(`description-${i}`) || ''; if (data.has(`address-${i}`)) f.address = data.get(`address-${i}`).trim(); if (data.has(`mode-${i}`)) { f.mode = data.get(`mode-${i}`); f.vlan = Number(data.get(`vlan-${i}`)); f.trunkAll = data.has(`all-${i}`); f.allowedVlans = data.get(`allowed-${i}`).split(',').filter(Boolean).map(Number); if (!n.device.vlans.some(v => v.id === f.vlan)) n.device.vlans.push({id: f.vlan, name: `VLAN${f.vlan}`}); } if (n.profile === 'fortios' && f.kind === 'ethernet') f.allowPing = data.has(`ping-${i}`); });
                    inspectorDirty = false; apply(p);
                }
                else if (event.target.matches('[data-node-form]')) {
                    const data = new FormData(event.target), p = clone(snapshot.project), n = p.nodes.find(n => n.id === selected);
                    n.name = data.get('name'); n.type = data.get('type'); n.forwarding = data.has('forwarding'); n.resolver = data.get('resolver').trim(); n.routes = JSON.parse(data.get('routes'));
                    if (data.has('routes6')) { n.forwarding6 = data.has('forwarding6'); n.routes6 = JSON.parse(data.get('routes6')); n.interfaces.forEach((f, i) => { if (data.has(`ipv6-${i}`)) { f.ipv6 = data.get(`ipv6-${i}`).split(',').map(s => s.trim()).filter(Boolean); f.mtu = Number(data.get(`mtu-${i}`)); } }); }
                    n.interfaces.forEach((f, i) => { f.address = data.get(`address-${i}`).trim(); f.up = data.has(`up-${i}`); if (f.kind === 'ethernet') { f.vlan = Number(data.get(`vlan-${i}`)); f.mode = data.get(`mode-${i}`); f.allowedVlans = data.get(`allowed-${i}`).split(',').filter(Boolean).map(Number); } else { f.privateKey = data.get(`key-${i}`); f.listenPort = Number(data.get(`listen-${i}`)); f.peers = JSON.parse(data.get(`peers-${i}`)); } });
                    n.services.http = data.has('http-on') ? {port: Number(data.get('http-port')), body: data.get('http-body')} : null;
                    n.services.dns = null; if (data.has('dns-on')) { const records = Object.create(null); for (const line of data.get('dns-records').split('\n').filter(s => s.trim())) { const pair = line.split('='); if (pair.length !== 2) throw new Error('DNS는 이름=주소 형식입니다.'); records[pair[0].trim()] = pair[1].trim(); } n.services.dns = {records}; }
                    n.services.dhcp = data.has('dhcp-on') ? {start: data.get('dhcp-start'), end: data.get('dhcp-end'), prefix: Number(data.get('dhcp-prefix')), gateway: data.get('dhcp-gateway'), dns: data.get('dhcp-dns')} : null; apply(p);
                } else if (event.target.matches('[data-link-form]')) { const data = new FormData(event.target), p = clone(snapshot.project); const parse = value => { if (!value) throw new Error('빈 Ethernet 포트가 필요합니다.'); const [node, port] = value.split('/'); return {node, port}; }; let id = 1; while (p.links.some(l => l.id === `l${id}`)) id++; p.links.push({id: `l${id}`, a: parse(data.get('a')), b: parse(data.get('b')), up: true, delay: Number(data.get('delay')), loss: Number(data.get('loss')), rate: Number(data.get('rate'))}); apply(p); }
            } catch (error) { status(error.message, true); }
        });
        $('[data-title]').addEventListener('change', event => { const p = clone(snapshot.project); p.title = event.target.value; try { apply(p); } catch (error) { status(error.message, true); } });
        $('[data-terminal-node]').addEventListener('change', event => pick(event.target.value));
        $('[data-terminal-shell]').addEventListener('change', event => { if (!ready || snapshot?.project.nodes.find(n => n.id === selected)?.profile !== 'windows') return; $('[data-command]').value = ''; historyDraft = ''; resizeCommand(); send('command', {node: selected, line: event.target.value === 'powershell' ? 'powershell' : 'cmd', autoplay: false}); });
        $('[data-trace-selected]').addEventListener('change', renderTrace);
        $('[data-file]').addEventListener('change', async event => { try { const file = event.target.files[0]; if (!file) return; if (file.size > 262144) throw new Error('가져올 파일은 256 KiB 이내입니다.'); await begin(JSON.parse(await file.text()), freshMeta(), true); currentLesson = ''; showGoal('파일에서 가져온 네트워크입니다. 서버 저장을 누르면 내 프로젝트로 보관합니다.'); status('설정 파일을 불러왔습니다.'); } catch (error) { status(error.message, true); } finally { event.target.value = ''; } });
        root.addEventListener('input', event => { if (event.target.closest('[data-managed-form], [data-windows-form]')) inspectorDirty = true; });
        function contextHelpAllowed(line) {
            const profile = snapshot.project.nodes.find(n => n.id === selected)?.profile;
            if (['linux', 'windows'].includes(profile)) return !line.trim();
            let quote = '', escaped = false;
            for (const char of line) { if (escaped) { escaped = false; continue; } if (char === '\\' && quote !== "'") { escaped = true; continue; } if (quote) { if (char === quote) quote = ''; } else if (char === '"' || char === "'") quote = char; }
            return !quote && !escaped;
        }
        $('[data-command]').addEventListener('keydown', event => {
            if (event.isComposing || event.keyCode === 229) return;
            if (event.key === 'Enter') { event.preventDefault(); $('[data-command-form]').requestSubmit(); }
            else if (event.ctrlKey && event.key.toLowerCase() === 'c' && event.target.selectionStart === event.target.selectionEnd) { event.preventDefault(); event.target.value = ''; resizeCommand(); send('interrupt', {node: selected}); }
            else if (event.ctrlKey && event.key.toLowerCase() === 'l') { event.preventDefault(); terminals.set(selected, []); renderTerminal(); }
            else if (event.key === 'Tab' && !event.shiftKey) { event.preventDefault(); send('complete', {node: selected, line: event.target.value}); }
            else if (event.key === '?' && !event.ctrlKey && contextHelpAllowed(event.target.value)) { event.preventDefault(); const linux = snapshot.project.nodes.find(n => n.id === selected)?.profile === 'linux'; send('command', {node: selected, line: linux ? 'help' : event.target.value + '?', autoplay: false}); }
            else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') { event.preventDefault(); commandHistory = histories.get(selected) || []; if (historyIndex === commandHistory.length) historyDraft = event.target.value; historyIndex = Math.max(0, Math.min(commandHistory.length, historyIndex + (event.key === 'ArrowUp' ? -1 : 1))); event.target.value = commandHistory[historyIndex] ?? historyDraft; event.target.setSelectionRange(event.target.value.length, event.target.value.length); resizeCommand(); }
        });
        $('[data-command]').addEventListener('beforeinput', event => { if (!event.isComposing && ['insertLineBreak', 'insertParagraph'].includes(event.inputType)) { event.preventDefault(); $('[data-command-form]').requestSubmit(); } });
        $('[data-command]').addEventListener('input', () => resizeCommand());
        $('[data-command]').addEventListener('paste', event => { const value = event.clipboardData?.getData('text') || ''; if (!/[\r\n]/.test(value)) return; event.preventDefault(); if (!ready || event.target.disabled) return; const lines = value.split(/\r?\n/).map(line => line.trim()).filter(Boolean); if (lines.length > 100 || lines.some(line => line.length > 2048)) { status('붙여넣기는 명령 100줄, 한 줄 2048자 이내입니다.', true); return; } $('[data-command]').value = ''; resizeCommand(); send('batch', {node: selected, lines, autoplay: $('[data-autoplay]').checked}); });
        $('[data-terminal-screen]').addEventListener('click', event => { if (event.target === $('[data-command]') || !window.getSelection()?.isCollapsed) return; focusCommand(); });
        let terminalWidth = 0;
        new ResizeObserver(entries => { const width = entries[0].contentRect.width; if (width !== terminalWidth) { terminalWidth = width; resizeCommand(false); } }).observe($('[data-terminal-screen]'));
        const map = $('[data-map]');
        function point(event) { const p = new DOMPoint(event.clientX, event.clientY); return p.matrixTransform(map.getScreenCTM().inverse()); }
        map.addEventListener('pointerdown', event => { if (!ready) return; const nodeEl = event.target.closest('[data-node]'); if (nodeEl) { const id = nodeEl.dataset.node; pick(id); const p = point(event), n = snapshot.project.nodes.find(n => n.id === id); drag = {id, x: n.x, y: n.y, startX: p.x, startY: p.y}; map.setPointerCapture(event.pointerId); } else if (event.target.matches('[data-link]')) { tab = 'links'; renderInspector(); } });
        map.addEventListener('pointermove', event => { if (!drag) return; const p = point(event), n = snapshot.project.nodes.find(n => n.id === drag.id); n.x = Math.round(Math.max(70, Math.min(1120, drag.x + p.x - drag.startX))); n.y = Math.round(Math.max(50, Math.min(800, drag.y + p.y - drag.startY))); renderCanvas(); });
        function endDrag() { if (!drag) return; const n = snapshot.project.nodes.find(n => n.id === drag.id), changed = n && (n.x !== drag.x || n.y !== drag.y); drag = null; if (changed) apply(snapshot.project); }
        map.addEventListener('pointerup', endDrag); map.addEventListener('pointercancel', endDrag);
        map.addEventListener('keydown', event => { const el = event.target.closest('[data-node]'); if (!ready || !el) return; if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); pick(el.dataset.node); } else if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) { event.preventDefault(); const p = clone(snapshot.project), n = p.nodes.find(n => n.id === el.dataset.node); n.x = Math.max(70, Math.min(1120, n.x + (event.key === 'ArrowLeft' ? -10 : event.key === 'ArrowRight' ? 10 : 0))); n.y = Math.max(50, Math.min(800, n.y + (event.key === 'ArrowUp' ? -10 : event.key === 'ArrowDown' ? 10 : 0))); apply(p); } });
        document.addEventListener('visibilitychange', () => { if (document.hidden) send('pause'); });
        window.addEventListener('beforeunload', event => { if (dirty && localFailed) { event.preventDefault(); event.returnValue = ''; } });
        setInterval(() => { if (!document.hidden && dirty && !conflict) saveServer(true); }, 30000 + Math.floor(Math.random() * 3000));
        if (linkedLesson) {
            await openLesson(linkedLesson, false);
            status(invalidLink ? '알 수 없는 실습 번호 대신 링크의 기존 예제를 열었습니다.' : '읽기자료의 실습 예제를 열었습니다.', invalidLink);
            return;
        }
        let restored = null; try { restored = await localOp('readonly', store => store.get('current')); } catch (error) { status(error.message, true); }
        if (restored) { try { await begin(restored.project, restored.meta, restored.dirty); currentLesson = ''; $('[data-goal]').textContent = '이 브라우저의 마지막 초안을 복원했습니다. 서버와 다르면 충돌 안내 후 사본을 선택할 수 있습니다.'; status('로컬 초안을 복원했습니다.'); } catch (error) { await begin(scenario('lan'), freshMeta(), true); status(`이전 초안을 열지 못했습니다: ${error.message}`, true); } }
        else { await begin(scenario('lan'), freshMeta(), true); $('[data-goal]').textContent = LESSONS[0].goal; status('준비되었습니다. PC-A에서 ping 192.168.10.20을 실행해 보세요.'); }
        if (invalidLink) status('링크에 지정된 실습을 찾지 못해 일반 실습 화면을 열었습니다.', true);
    } catch (error) { root.replaceChildren(); const p = document.createElement('p'); p.setAttribute('role', 'alert'); p.textContent = `네트워크 실습을 준비하지 못했습니다: ${error.message}`; root.append(p); }
})();
