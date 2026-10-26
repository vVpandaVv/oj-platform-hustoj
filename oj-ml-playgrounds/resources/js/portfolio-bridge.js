(function (global) {
	'use strict';
	function bind(panel, root, assignment, questions) {
		if (!root) return;
		var identity = null, project = null, pending = null, timer = null;
		var button = panel.querySelector('[data-student-template-apply]'), note = panel.querySelector('[data-template-bridge-status]');
		var seen = Object.create(null), taken = Object.create(null);
		var sections = questions.map(function (q, index) {
			var base = q.id || q.title.trim().toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '') || 'section-' + (index + 1);
			seen[base] = (seen[base] || 0) + 1;
			var candidate = seen[base] === 1 ? base : base + '-' + seen[base], id = candidate, n = 2;
			while (taken[id]) { id = candidate + '-' + n++; } taken[id] = true;
			return { id: id, title: q.title.trim(), description: q.prompt.trim() };
		});
		function sameFrame() {
			return identity && root.isConnected && panel.isConnected && root.dataset.publicPcTerminal !== '1'
				&& identity.frame === root.querySelector('.oj-ml-playground-frame') && identity.frame.isConnected;
		}
		function allowed() { return sameFrame() && root.dataset.publicPcLocked !== '1' && root.dataset.publicPcCovered !== '1'; }
		function update() { button.disabled = !allowed() || !project || !project.projectId || !project.projectToken || !!pending; }
		function clearPending() { global.clearTimeout(timer); timer = null; pending = null; }
		function configure(event) {
			clearPending(); project = null; identity = event.detail;
			if (!identity || identity.assignmentId !== String(assignment.id)) identity = null;
			note.textContent = '프로젝트 연결을 확인하는 중입니다.'; update();
		}
		function message(event) {
			if (!panel.isConnected) { cleanup(); return; }
			var data = event.data;
			if (!sameFrame() || !data || event.origin !== global.location.origin || event.source !== identity.frame.contentWindow
				|| data.version !== 2 || data.frameNonce !== identity.frameNonce || data.assignmentId !== identity.assignmentId || data.storageSession !== identity.storageSession) return;
			if (data.type === 'oj-ml:portfolio-project') {
				if (!project || project.projectId !== data.projectId || project.projectToken !== data.projectToken) clearPending();
				project = data;
				note.textContent = data.projectId && data.projectToken ? '적용 대상: ' + String(data.projectName || '현재 프로젝트').slice(0, 200) : '실습에서 프로젝트를 열어 주세요.';
				update(); return;
			}
			if (data.type !== 'oj-ml:portfolio-template-result' || !pending || data.requestId !== pending.requestId
				|| data.projectId !== pending.projectId || data.projectToken !== pending.projectToken) return;
			clearPending();
			if (data.state === 'applied' || data.state === 'unchanged') note.textContent = '양식을 저장했습니다. 추가 ' + Number(data.added || 0) + '문항 · 기존 답변은 유지됩니다. 포트폴리오에서 확인하세요.';
			else {
				var messages = { OJ_PORTFOLIO_TEMPLATE_CONFLICT: '같은 문항 ID의 제목·안내가 달라 자동 변경하지 않았습니다. 기존 답변을 보존하고 교사에게 양식을 확인하세요.', OJ_PORTFOLIO_PROJECT_CHANGED: '적용 중 프로젝트가 바뀌었습니다. 현재 프로젝트를 확인하고 다시 누르세요.', OJ_PORTFOLIO_SAVE_FAILED: '양식 저장에 실패했습니다. 저장 공간을 확인하고 다시 시도하세요.', OJ_PORTFOLIO_BUSY: '다른 양식 적용을 처리 중입니다. 잠시 후 다시 시도하세요.' };
				note.textContent = messages[data.code] || '양식을 적용하지 못했습니다. 프로젝트를 확인하세요. 양식 다운로드도 사용할 수 있습니다.';
			}
			update();
		}
		button.addEventListener('click', async function () {
			if (global.OjMlPublicPcReady && await global.OjMlPublicPcReady(root) !== true) { update(); return; }
			if (!allowed() || !project || !project.projectId || !project.projectToken || pending) return;
			pending = { type: 'oj-ml:portfolio-template-apply', version: 2, frameNonce: identity.frameNonce, assignmentId: identity.assignmentId, storageSession: identity.storageSession,
				requestId: global.crypto.randomUUID(), projectId: project.projectId, projectToken: project.projectToken, template: { sections: sections, locale: 'ko' } };
			identity.frame.contentWindow.postMessage(pending, global.location.origin);
			note.textContent = '양식을 적용하고 저장하는 중입니다…'; update();
			timer = global.setTimeout(function () { clearPending(); note.textContent = '저장 응답을 확인하지 못했습니다. 포트폴리오를 먼저 확인하세요. 다시 적용해도 기존 답변은 덮어쓰지 않습니다.'; update(); }, 15000);
		});
		// Keep correlated state received during a temporary privacy check, but enable no
		// actions until verification unlocks. Terminal revocation always discards it.
		var observer = new global.MutationObserver(function () {
			if (root.dataset.publicPcTerminal === '1') { clearPending(); project = null; identity = null; }
			update();
		});
		observer.observe(root, { attributes: true, attributeFilter: ['data-public-pc-locked', 'data-public-pc-terminal', 'data-public-pc-covered'] });
		// public-pc-bootstrap always reloads a persisted pageshow before private work resumes.
		function cleanup() { clearPending(); identity = null; project = null; observer.disconnect(); update(); root.removeEventListener('oj-ml:trusted-frame', configure); global.removeEventListener('message', message); global.removeEventListener('pagehide', cleanup); }
		root.addEventListener('oj-ml:trusted-frame', configure); global.addEventListener('message', message); global.addEventListener('pagehide', cleanup);
	}
	global.OJMLPortfolioBridge = { bind: bind };
})(window);
