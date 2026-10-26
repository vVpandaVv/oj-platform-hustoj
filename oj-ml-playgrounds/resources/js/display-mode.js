(function (global) {
	'use strict';
	var instances = new WeakMap();
	function button(attribute, text) {
		var node = document.createElement('button');
		node.type = 'button'; node.setAttribute(attribute, ''); node.textContent = text;
		return node;
	}
	function attach(root) {
		if (instances.has(root)) return instances.get(root);
		if (root.dataset.publicPcTerminal === '1') return null;
		var lab = root.dataset.ojMlPage === 'lab';
		var toolbar = document.createElement('div');
		toolbar.className = 'oj-ml-display-toolbar'; toolbar.setAttribute('data-oj-ml-display-toolbar', '');
		toolbar.setAttribute('role', 'group'); toolbar.setAttribute('aria-label', '화면 보기');
		var title = document.createElement('strong'); title.textContent = lab ? 'ML 실습' : 'ML 실습 관리';
		toolbar.appendChild(title);
		var wide = button('data-oj-ml-wide', '넓게 보기'); toolbar.appendChild(wide);
		var focus = button('data-oj-ml-focus', '집중 모드');
		var full = button('data-oj-ml-fullscreen', '전체화면');
		var guidance = button('data-oj-ml-guidance', '안내 접기');
		var submission = button('data-oj-ml-submission-toggle', '제출 확인');
		var exit = button('data-oj-ml-display-exit', '원래 화면');
		if (lab) { toolbar.appendChild(focus); toolbar.appendChild(full); toolbar.appendChild(guidance); toolbar.appendChild(submission); }
		toolbar.appendChild(exit);
		var notice = document.createElement('span'); notice.setAttribute('role', 'status'); notice.className = 'oj-ml-display-notice';
		toolbar.appendChild(notice); root.insertBefore(toolbar, root.firstChild);
		var ancestors = [], marked = [], isolated = [], oldOverflow = null, returnFocus = null, expanded = null;
		var pendingFull = 0, stopped = false, started = false, frameListeners = new WeakSet(), frameBindings = [];
		for (var parent = root.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
			if (parent.matches('.ojp-shell, .ojp-console-layout, .ojp-public-main, .ojp-console-main')) ancestors.push(parent);
		}
		root.dataset.displayMode = 'normal'; root.dataset.guidanceCollapsed = '0';
		var content = root.querySelector('[data-oj-ml-content]');
		var guideBox = null;
		if (lab && content) {
			guideBox = document.createElement('aside'); guideBox.className = 'oj-ml-lab-guidance';
			guideBox.setAttribute('aria-label', '과제 설명과 실습 안내');
			var panels = Array.from(content.children).filter(function (node) { return !node.matches('[data-oj-ml-workspace], [data-oj-ml-submit]'); });
			content.insertBefore(guideBox, content.firstChild);
			panels.forEach(function (panel) { guideBox.appendChild(panel); });
			var heading = guideBox.querySelector('h2'); if (heading) title.textContent = heading.textContent;
		}
		var displayTitle = title.textContent;
		function visible(node) {
			return node && node.isConnected && !node.closest('[inert]') && node.getClientRects().length
				&& global.getComputedStyle(node).visibility === 'visible';
		}
		function safeFocus(node) {
			if (visible(node) && !node.disabled) node.focus({ preventScroll: true });
		}
		function restoreIsolation() {
			isolated.forEach(function (entry) { entry[0].inert = entry[1]; }); isolated = [];
			if (oldOverflow !== null) { document.body.style.overflow = oldOverflow; oldOverflow = null; }
		}
		function isolate(node) {
			restoreIsolation(); oldOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden';
			for (var branch = node; branch && branch !== document.body; branch = branch.parentElement) {
				if (!branch.parentElement) break;
				Array.from(branch.parentElement.children).forEach(function (sibling) {
					if (sibling !== branch && !sibling.matches('script, style, link')) {
						isolated.push([sibling, sibling.inert]); sibling.inert = true;
					}
				});
			}
		}
		function setWide(enabled) {
			marked.forEach(function (node) { node.classList.remove('oj-ml-wide-host'); }); marked = [];
			if (enabled) ancestors.forEach(function (node) {
				if (!node.classList.contains('oj-ml-wide-host')) { node.classList.add('oj-ml-wide-host'); marked.push(node); }
			});
		}
		function unavailable() { return stopped || root.dataset.publicPcTerminal === '1' || root.dataset.publicPcLocked === '1' || root.dataset.publicPcCovered === '1'; }
		function update() {
			var mode = root.dataset.displayMode;
			wide.setAttribute('aria-pressed', String(mode === 'wide'));
			focus.setAttribute('aria-pressed', String(mode === 'focus'));
			full.setAttribute('aria-pressed', String(document.fullscreenElement === root));
			guidance.setAttribute('aria-expanded', String(root.dataset.guidanceCollapsed !== '1'));
			var guideLabel = root.dataset.guidanceCollapsed === '1' ? '과제·양식 안내' : '안내 접기';
			if (guidance.textContent !== guideLabel) guidance.textContent = guideLabel;
			var titleText = unavailable() ? '실습 보호 상태 확인 중' : displayTitle;
			if (title.textContent !== titleText) title.textContent = titleText;
			submission.setAttribute('aria-expanded', String(root.dataset.submissionOpen === '1'));
			exit.hidden = mode === 'normal';
			focus.disabled = full.disabled = guidance.disabled = submission.disabled = !started || unavailable();
			wide.disabled = unavailable();
		}
		function mode(next, initiator) {
			if (next !== 'normal' && unavailable()) return;
			if (next === 'focus' && !started) return;
			pendingFull++;
			if (document.fullscreenElement === root && next !== 'focus') document.exitFullscreen().catch(function () {});
			if (root.dataset.displayMode !== 'focus' && next === 'focus') returnFocus = initiator || focus;
			root.dataset.displayMode = next;
			setWide(next !== 'normal');
			if (next === 'focus') { isolate(root); }
			else { restoreIsolation(); safeFocus(returnFocus); returnFocus = null; }
			notice.textContent = next === 'focus' ? 'Esc로 원래 화면 · 제출은 작업 공간의 OJ에 제출하기' : '';
			update();
			if (next === 'focus') safeFocus(exit);
		}
		function closeReview(restore) {
			if (!expanded) return;
			var previous = expanded; expanded = null;
			delete previous.dataset.expanded; previous.removeAttribute('role'); previous.removeAttribute('aria-modal');
			delete root.dataset.reviewExpanded;
			var toggle = previous.querySelector('[data-oj-ml-review-expand]'); if (toggle) { toggle.textContent = '크게 채점하기'; toggle.setAttribute('aria-expanded', 'false'); }
			restoreIsolation(); if (restore) safeFocus(toggle);
		}
		function openReview(details) {
			if (expanded === details) { closeReview(true); return; }
			closeReview(false); expanded = details; details.open = true;
			details.dataset.expanded = '1'; root.dataset.reviewExpanded = '1';
			details.setAttribute('role', 'dialog'); details.setAttribute('aria-modal', 'true');
			var toggle = details.querySelector('[data-oj-ml-review-expand]');
			toggle.textContent = '채점 화면 닫기'; toggle.setAttribute('aria-expanded', 'true');
			isolate(details); safeFocus(toggle);
		}
		function reviews() {
			if (expanded && (!expanded.isConnected || !expanded.open)) closeReview(false);
			if (expanded) {
				var status = root.querySelector('[data-oj-ml-status]'), mirror = expanded.querySelector('[data-oj-ml-review-display-status]');
				if (status && mirror && mirror.textContent !== status.textContent) { mirror.textContent = status.textContent; mirror.dataset.kind = status.dataset.kind || 'info'; }
			}
			root.querySelectorAll('.oj-ml-result-workbench').forEach(function (details) {
				if (details.querySelector('[data-oj-ml-review-expand]')) return;
				var body = details.querySelector('.oj-ml-workbench-body'); if (!body) return;
				var tools = document.createElement('div'); tools.className = 'oj-ml-review-display-tools';
				var summary = details.querySelector('summary');
				details.setAttribute('aria-label', summary ? summary.textContent : '제출 내용 확인과 평가');
				var identity = document.createElement('strong'); identity.className = 'oj-ml-review-display-identity'; identity.textContent = details.getAttribute('aria-label'); tools.appendChild(identity);
				var toggle = button('data-oj-ml-review-expand', '크게 채점하기'); toggle.setAttribute('aria-expanded', 'false'); tools.appendChild(toggle);
				var splitLabel = document.createElement('label'); splitLabel.textContent = '내용 영역 너비 ';
				var split = document.createElement('input'); split.type = 'range'; split.min = '40'; split.max = '75'; split.value = '65'; split.step = '5';
				split.setAttribute('data-oj-ml-review-split', ''); splitLabel.appendChild(split); tools.appendChild(splitLabel);
				var reviewNotice = document.createElement('p'); reviewNotice.setAttribute('data-oj-ml-review-display-status', ''); reviewNotice.setAttribute('role', 'status'); tools.appendChild(reviewNotice);
				body.insertBefore(tools, body.firstChild);
				toggle.addEventListener('click', function () { openReview(details); });
				split.addEventListener('input', function () { details.style.setProperty('--oj-ml-preview-width', split.value + '%'); });
			});
		}
		function keydown(event) {
			if (event.key === 'Escape') {
				if (expanded) { event.preventDefault(); closeReview(true); }
				else if (root.dataset.displayMode === 'focus') { event.preventDefault(); mode('normal'); }
				return;
			}
			if (event.key !== 'Tab') return;
			var scope = expanded || (root.dataset.displayMode === 'focus' ? root : null); if (!scope) return;
			var nodes = Array.from(scope.querySelectorAll('button, a[href], input, select, textarea, summary, iframe, [tabindex="0"]')).filter(function (node) {
				return !node.disabled && visible(node);
			});
			var first = nodes[0], last = nodes[nodes.length - 1];
			if (event.shiftKey && document.activeElement === first) { event.preventDefault(); safeFocus(last); }
			else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); safeFocus(first); }
		}
		function frameKeys() {
			var frame = root.querySelector('.oj-ml-playground-frame'); if (!frame) return;
			function bind() {
				try {
					var doc = frame.contentDocument;
					if (doc && !frameListeners.has(doc)) {
						var escape = function (event) { if (event.key === 'Escape') keydown(event); };
						doc.addEventListener('keydown', escape); frameBindings.push([doc, 'keydown', escape]); frameListeners.add(doc);
					}
				} catch (ignored) {}
			}
			if (!frameListeners.has(frame)) { frame.addEventListener('load', bind); frameBindings.push([frame, 'load', bind]); frameListeners.add(frame); }
			bind();
		}
		function labStarted() {
			if (!lab || started || !root.querySelector('.oj-ml-playground-frame')) return;
			started = true; root.dataset.labStarted = '1';
			root.dataset.guidanceCollapsed = root.dataset.labGuidanceCollapsed === '0' ? '0' : '1';
			frameKeys(); update();
			if (root.dataset.labFocusOnStart === '1') mode('focus');
		}
		wide.addEventListener('click', function () { mode(root.dataset.displayMode === 'wide' ? 'normal' : 'wide', wide); });
		focus.addEventListener('click', function () { mode(root.dataset.displayMode === 'focus' ? 'normal' : 'focus', focus); });
		exit.addEventListener('click', function () { mode('normal'); });
		guidance.addEventListener('click', function () { root.dataset.guidanceCollapsed = root.dataset.guidanceCollapsed === '1' ? '0' : '1'; root.dataset.submissionOpen = '0'; update(); });
		submission.addEventListener('click', function () {
			root.dataset.submissionOpen = root.dataset.submissionOpen === '1' ? '0' : '1'; root.dataset.guidanceCollapsed = '1'; update();
			if (root.dataset.displayMode !== 'focus') { var panel = root.querySelector('[data-oj-ml-submit]'); if (panel) panel.scrollIntoView({ block: 'start' }); }
		});
		full.addEventListener('click', function () {
			if (unavailable()) return;
			if (document.fullscreenElement === root) { document.exitFullscreen().catch(function () {}); return; }
			mode('focus', full); var request = ++pendingFull;
			function failed() { if (!stopped && request === pendingFull) notice.textContent = '전체화면을 사용할 수 없어 집중 모드를 유지합니다. Esc로 돌아갈 수 있습니다.'; }
			try {
				if (!root.requestFullscreen) { failed(); return; }
				Promise.resolve(root.requestFullscreen()).then(function () {
					if ((stopped || request !== pendingFull) && document.fullscreenElement === root) document.exitFullscreen().catch(function () {});
				}, failed);
			} catch (ignored) { failed(); }
		});
		function fullscreenChanged() {
			// Leaving native fullscreen (including a file picker/Alt-Tab) retains focus layout and the same frame.
			if (root.dataset.displayMode === 'focus') notice.textContent = document.fullscreenElement === root
				? 'Esc로 전체화면 종료 · 원래 화면 버튼으로 집중 모드 종료' : 'Esc로 원래 화면 · 제출은 작업 공간의 OJ에 제출하기';
			update();
		}
		function stop() {
			if (stopped) return; stopped = true; pendingFull++;
			closeReview(false); mode('normal'); observer.disconnect(); detachObserver.disconnect();
			document.removeEventListener('keydown', keydown); document.removeEventListener('fullscreenchange', fullscreenChanged);
			global.removeEventListener('pagehide', stop);
			frameBindings.forEach(function (entry) { entry[0].removeEventListener(entry[1], entry[2]); }); frameBindings = [];
			toolbar.hidden = true;
		}
		var observer = new MutationObserver(function () {
			if (!root.isConnected || root.dataset.publicPcTerminal === '1') { stop(); return; }
			if (!lab) reviews();
			if (lab && !started) labStarted();
			update();
		});
		observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-public-pc-terminal', 'data-public-pc-locked', 'data-public-pc-covered', 'open'] });
		var detachObserver = new MutationObserver(function () { if (!root.isConnected) stop(); });
		detachObserver.observe(document.body, { childList: true, subtree: true });
		document.addEventListener('keydown', keydown); document.addEventListener('fullscreenchange', fullscreenChanged);
		global.addEventListener('pagehide', stop, { once: true });
		var api = { labStarted: labStarted, stop: stop }; instances.set(root, api);
		reviews(); labStarted(); update(); return api;
	}
	global.OJMLDisplay = { attach: attach, labStarted: function (root) { var controller = instances.get(root) || attach(root); if (controller) controller.labStarted(); } };
}(window));
