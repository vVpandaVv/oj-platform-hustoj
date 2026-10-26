(function () {
	'use strict';

	var PROTOCOL_VERSION = 2;
	var SESSION_CHECK_MS = 45000;
	var CONTEXT_VERIFY_TIMEOUT_MS = 15000;
	var PICKER_ARM_MS = 1500;
	var PICKER_MAX_MS = 120000;
	var PICKER_VERIFY_MAX_MS = 15000;
	var PICKER_FOCUS_GRACE_MS = 100;
	var controllers = new WeakMap();
	var monitors = new WeakMap();
	var readyByRoot = new WeakMap();
	var activePickerRoot = null;
	var attempts = Object.create(null);
	var attemptLookups = Object.create(null);
	var originalFetch = window.fetch.bind(window);
	var bfcachePageHidden = false;

	function emergencyLockPublicPcRoots() {
		document.querySelectorAll('.oj-ml-app[data-public-pc-mode="1"]').forEach(function (root) {
			root.dataset.publicPcLocked = '1';
			root.setAttribute('aria-busy', 'true');
			readyByRoot.set(root, false);
			delete root.dataset.restNonce;
			delete root.dataset.attemptId;
			delete root.dataset.assignmentId;
			var wipeButton = root.querySelector('[data-oj-ml-wipe-local]');
			if (wipeButton) wipeButton.disabled = true;
			root.querySelectorAll('[data-oj-ml-content]').forEach(function (content) {
				content.textContent = '';
			});
			root.querySelectorAll('.oj-ml-playground-frame').forEach(function (frame) {
				frame.hidden = true;
				try { frame.src = 'about:blank'; } catch (ignored) {}
				if (frame.parentNode) frame.parentNode.removeChild(frame);
			});
		});
	}

	window.addEventListener('pagehide', function (event) {
		bfcachePageHidden = event.persisted === true;
		emergencyLockPublicPcRoots();
	});
	window.addEventListener('pageshow', function (event) {
		if (event.persisted !== true && !bfcachePageHidden) return;
		window.location.reload();
	});


	function unwrap(payload) {
		return payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
	}

	function setStatus(root, text, kind) {
		if (root.dataset.publicPcTerminal === '1') return false;
		var status = root.querySelector('[data-oj-ml-status]');
		if (!status) return false;
		status.textContent = text;
		status.dataset.kind = kind || 'info';
		return true;
	}

	function apiFor(root, signal) {
		return {
			get: async function (path) {
				var abort = new window.AbortController();
				var timedOut = false;
				var forwardAbort = function () { abort.abort(); };
				var timer = window.setTimeout(function () {
					timedOut = true;
					abort.abort();
				}, CONTEXT_VERIFY_TIMEOUT_MS);
				if (signal) {
					if (signal.aborted) abort.abort();
					else signal.addEventListener('abort', forwardAbort, { once: true });
				}
				try {
					var base = String(root.dataset.restRoot || '').replace(/\/+$/, '');
					var response = await originalFetch(base + '/' + String(path || '').replace(/^\/+/, ''), {
						credentials: 'same-origin',
						signal: abort.signal,
						headers: {
							Accept: 'application/json',
							'X-WP-Nonce': root.dataset.restNonce || ''
						}
					});
					var payload = await response.json().catch(function () { return null; });
					if (!response.ok) {
						var responseError = new Error(payload && payload.message ? payload.message : '공용 기기 보호 정보를 불러오지 못했습니다.');
						responseError.status = response.status;
						throw responseError;
					}
					return unwrap(payload);
				} catch (error) {
					if (timedOut) throw new Error('공용 기기 로그인 확인 시간이 초과되었습니다. 네트워크를 확인한 뒤 페이지를 새로고침해 주세요.');
					throw error;
				} finally {
					window.clearTimeout(timer);
					if (signal) signal.removeEventListener('abort', forwardAbort);
				}
			}
		};
	}

	function attemptIdFrom(payload) {
		var data = unwrap(payload) || {};
		var assignment = data.assignment || data;
		var attempt = data.attempt || assignment.activeAttempt || null;
		return attempt && typeof attempt === 'object' ? String(attempt.id || attempt.publicId || '') : '';
	}

	function observeAttempt(response, assignmentId) {
		var lookup = response.clone().json().then(function (payload) {
			var id = attemptIdFrom(payload);
			if (id) attempts[assignmentId] = id;
			return id;
		}).catch(function () { return ''; });
		attemptLookups[assignmentId] = lookup;
	}

	window.fetch = async function (input, options) {
		var response = await originalFetch(input, options);
		try {
			var url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
			var match = /\/oj-ml\/v1\/assignments\/(\d+)(?:\/attempts)?\/?$/.exec(url.pathname);
			if (match && match[1]) observeAttempt(response, match[1]);
		} catch (ignored) {
			// The regular application owns unrelated fetch calls.
		}
		return response;
	};

	function rootForFrame(source) {
		var roots = document.querySelectorAll('.oj-ml-app[data-public-pc-mode="1"]');
		for (var index = 0; index < roots.length; index += 1) {
			var iframe = roots[index].querySelector('.oj-ml-playground-frame');
			if (iframe && iframe.contentWindow === source) return { root: roots[index], iframe: iframe };
		}
		return null;
	}

	async function knownAttempt(assignmentId) {
		if (attempts[assignmentId]) return attempts[assignmentId];
		if (attemptLookups[assignmentId]) return attemptLookups[assignmentId];
		return '';
	}

	async function assignmentPolicyFor(root, assignmentId) {
		try {
			// Read once for this frame's handshake, not on focus or periodic session checks.
			// A missing policy must never silently grant unrestricted classroom access.
			var detail = await apiFor(root).get('assignments/' + encodeURIComponent(assignmentId));
			var assignment = detail && (detail.assignment || detail);
			if (!assignment || String(assignment.id) !== assignmentId) return null;
			var policy = assignment.submissionPolicy;
			if (!policy || !Array.isArray(policy.allowedTaskTypes) || !Array.isArray(policy.allowedAlgorithms)) return null;
			return { version: 1, allowedTaskTypes: policy.allowedTaskTypes.slice(), allowedAlgorithms: policy.allowedAlgorithms.slice() };
		} catch (ignored) {
			return null;
		}
	}

	function waitForPublicPc(root) {
		if (!root || root.dataset.publicPcMode !== '1') return Promise.resolve(true);
		var pending = controllers.get(root);
		if (!pending) return Promise.resolve(false);
		return pending.then(function (controller) {
			return controller !== null
				&& readyByRoot.get(root) === true
				&& root.dataset.publicPcLocked !== '1'
				&& root.dataset.publicPcTerminal !== '1';
		}, function () { return false; });
	}

	Object.defineProperty(window, 'OjMlPublicPcReady', {
		configurable: false,
		enumerable: false,
		writable: false,
		value: waitForPublicPc
	});

	function guardInitialization(root) {
		var stale = false;
		var stopped = false;
		var abort = new window.AbortController();

		function lockRoot() {
			root.dataset.publicPcLocked = '1';
			root.setAttribute('aria-busy', 'true');
			readyByRoot.set(root, false);
			var wipeButton = root.querySelector('[data-oj-ml-wipe-local]');
			if (wipeButton) wipeButton.disabled = true;
		}

		function markStale() {
			if (stale) return;
			stale = true;
			lockRoot();
			abort.abort();
		}

		function cleanup() {
			if (stopped) return;
			stopped = true;
			window.removeEventListener('pagehide', onPageHide);
			document.removeEventListener('visibilitychange', onVisible);
		}

		function reloadWhenVisible() {
			if (!stale || document.visibilityState !== 'visible') return;
			cleanup();
			window.location.reload();
		}

		function failClosed() {
			markStale();
			reloadWhenVisible();
		}

		function onPageHide() {
			markStale();
		}


		function onVisible() {
			if (document.visibilityState !== 'visible') {
				markStale();
				return;
			}
			reloadWhenVisible();
		}

		lockRoot();
		window.addEventListener('pagehide', onPageHide);
		document.addEventListener('visibilitychange', onVisible);
		if (document.visibilityState !== 'visible') markStale();

		return Object.freeze({
			signal: abort.signal,
			isStale: function () { return stale; },
			cleanup: cleanup,
			failClosed: failClosed,
			reloadWhenVisible: reloadWhenVisible
		});
	}

	function monitorSession(root, controller) {
		var stopped = false;
		var suspended = false;
		var running = null;
		var timer = 0;
		var blurTimer = 0;
		var pickerTimer = 0;
		var pickerFinishTimer = 0;
		var picker = null;
		var frameBinding = null;
		var replayingInputs = new WeakSet();
		var blockedInputs = new WeakSet();
		var sessionMonitor = null;

		function stopLocked() {
			if (stopped) return;
			readyByRoot.set(root, false);
			cleanup();
		}

		function verify(background) {
			if (stopped || suspended) return Promise.resolve(false);
			if (picker) return Promise.resolve(null);
			if (running) return running;
			var current = controller.verify(background ? { background: true } : undefined).then(function (valid) {
				if (valid === true) readyByRoot.set(root, true);
				if (valid === false) stopLocked();
				return valid;
			}).catch(function () {
				stopLocked();
				return false;
			}).finally(function () {
				if (running === current) running = null;
			});
			running = current;
			return current;
		}

		function resume() {
			if (stopped) return;
			var wasSuspended = suspended;
			suspended = false;
			void verify(!wasSuspended);
		}

		function cancelBlurCheck() {
			if (!blurTimer) return;
			window.clearTimeout(blurTimer);
			blurTimer = 0;
		}

		function suspendForBackground() {
			if (stopped) return;
			suspended = true;
			running = null;
			readyByRoot.set(root, false);
			try {
				controller.suspend();
			} catch (ignored) {
				delete root.dataset.publicPcCovered;
				root.dataset.publicPcLocked = '1';
				root.setAttribute('aria-busy', 'true');
				var button = root.querySelector('[data-oj-ml-wipe-local]');
				if (button) button.disabled = true;
				var frame = root.querySelector('.oj-ml-playground-frame');
				if (frame) frame.hidden = true;
			}
		}

		function clearPickerTimers() {
			window.clearTimeout(pickerTimer);
			window.clearTimeout(pickerFinishTimer);
			pickerTimer = 0;
			pickerFinishTimer = 0;
		}

		function releasePicker() {
			clearPickerTimers();
			picker = null;
			if (activePickerRoot === root) activePickerRoot = null;
		}

		function blockPickerCompletion() {
			if (picker && picker.input) blockedInputs.add(picker.input);
			releasePicker();
		}

		function failPickerClosed() {
			blockPickerCompletion();
			suspendForBackground();
		}

		function isFileInput(target) {
			return target && target.nodeType === 1
				&& String(target.tagName || '').toUpperCase() === 'INPUT'
				&& String(target.type || '').toLowerCase() === 'file';
		}

		function stopPickerEvent(event, input, clearValue) {
			if (event && typeof event.preventDefault === 'function') event.preventDefault();
			if (event && typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
			if (event && typeof event.stopPropagation === 'function') event.stopPropagation();
			if (clearValue && input) {
				try { input.value = ''; } catch (ignored) {}
			}
		}

		function hasTransientActivation(view) {
			return !!(
				view && view.navigator && view.navigator.userActivation
				&& view.navigator.userActivation.isActive === true
			);
		}

		function replayPickerEvent(completed) {
			if (!completed.pendingType || !completed.input || completed.input.isConnected === false) return;
			var owner = completed.input.ownerDocument;
			var view = owner && owner.defaultView;
			if (!view || typeof view.Event !== 'function' || typeof completed.input.dispatchEvent !== 'function') return;
			replayingInputs.add(completed.input);
			try {
				completed.input.dispatchEvent(new view.Event(completed.pendingType, { bubbles: true }));
			} catch (ignored) {
				replayingInputs.delete(completed.input);
				try { completed.input.value = ''; } catch (ignoredValue) {}
			}
		}

		function schedulePickerVerification() {
			if (!picker || picker.state !== 'verifying' || picker.verificationStarted || stopped) return;
			window.clearTimeout(pickerFinishTimer);
			pickerFinishTimer = window.setTimeout(function () {
				pickerFinishTimer = 0;
				var currentPicker = picker;
				if (
					!currentPicker || currentPicker.state !== 'verifying'
					|| currentPicker.verificationStarted || stopped
				) return;
				if (document.visibilityState !== 'visible' || !document.hasFocus()) return;
				currentPicker.verificationStarted = true;
				if (typeof controller.verifyCovered !== 'function' || typeof controller.uncover !== 'function') {
					failPickerClosed();
					return;
				}
				var verification;
				try {
					verification = controller.verifyCovered();
				} catch (error) {
					failPickerClosed();
					return;
				}
				Promise.resolve(verification).then(function (valid) {
					if (picker !== currentPicker || stopped) return;
					var uncovered = false;
					if (valid === true) {
						try { uncovered = controller.uncover() === true; } catch (ignored) {}
					}
					if (valid !== true || !uncovered) {
						if (root.dataset.publicPcTerminal === '1') {
							blockPickerCompletion();
							stopLocked();
						} else failPickerClosed();
						return;
					}
					readyByRoot.set(root, true);
					releasePicker();
					replayPickerEvent(currentPicker);
				}, function () {
					if (picker !== currentPicker || stopped) return;
					blockPickerCompletion();
					if (root.dataset.publicPcTerminal === '1') stopLocked();
					else suspendForBackground();
				});
			}, PICKER_FOCUS_GRACE_MS);
		}

		function finishPicker(eventType) {
			if (!picker) return;
			if (eventType) picker.pendingType = eventType;
			if (picker.state !== 'verifying') {
				picker.state = 'verifying';
				picker.verificationStarted = false;
				window.clearTimeout(pickerTimer);
				pickerTimer = window.setTimeout(function () {
					if (picker && picker.state === 'verifying') failPickerClosed();
				}, PICKER_VERIFY_MAX_MS);
			}
			schedulePickerVerification();
		}

		function promotePicker() {
			if (!picker || picker.state !== 'armed') return;
			picker.state = 'open';
			window.clearTimeout(pickerTimer);
			pickerTimer = window.setTimeout(function () {
				if (picker && picker.state === 'open') failPickerClosed();
			}, PICKER_MAX_MS);
		}

		function beginPicker(input, view, expectedFrame) {
			if (
				stopped || suspended || picker || activePickerRoot !== null
				|| readyByRoot.get(root) !== true
				|| root.dataset.publicPcLocked === '1'
				|| root.dataset.publicPcTerminal === '1'
				|| !hasTransientActivation(view)
				|| typeof controller.cover !== 'function'
			) return false;
			if (expectedFrame) {
				if (
					expectedFrame.isConnected === false
					|| expectedFrame.contentWindow !== view
					|| expectedFrame.contentDocument !== input.ownerDocument
					|| !managedFrameOwnsFocus()
				) return false;
			} else {
				if (input.ownerDocument !== document) return false;
				if (typeof root.contains === 'function' && !root.contains(input)) return false;
				if (document.visibilityState !== 'visible' || !document.hasFocus()) return false;
			}
			if (controller.cover() !== true) return false;
			activePickerRoot = root;
			readyByRoot.set(root, false);
			running = null;
			picker = { input: input, state: 'armed', pendingType: '' };
			pickerTimer = window.setTimeout(function () {
				if (!picker || picker.state !== 'armed') return;
				promotePicker();
			}, PICKER_ARM_MS);
			return true;
		}

		function onPickerClick(event, view, expectedFrame) {
			var input = isFileInput(event && event.target) ? event.target : null;
			if (!input) return;
			try {
				if (beginPicker(input, view, expectedFrame)) {
					blockedInputs.delete(input);
					return;
				}
			} catch (ignored) { suspendForBackground(); }
			stopPickerEvent(event, input, true);
			setStatus(root, '보안 파일 선택을 시작하지 못했습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.', 'error');
		}

		function onPickerCompletion(event) {
			var input = isFileInput(event && event.target) ? event.target : null;
			if (!input) return;
			if (replayingInputs.has(input)) {
				replayingInputs.delete(input);
				return;
			}
			if (blockedInputs.has(input)) {
				blockedInputs.delete(input);
				stopPickerEvent(event, input, true);
				return;
			}
			if (!picker || picker.input !== input) {
				if (readyByRoot.get(root) !== true || root.dataset.publicPcLocked === '1') {
					stopPickerEvent(event, input, true);
				}
				return;
			}
			stopPickerEvent(event, input, false);
			finishPicker(event.type === 'cancel' ? 'cancel' : 'change');
		}

		function unbindFrame() {
			if (!frameBinding) return;
			frameBinding.document.removeEventListener('click', frameBinding.click, true);
			frameBinding.document.removeEventListener('change', frameBinding.complete, true);
			frameBinding.document.removeEventListener('cancel', frameBinding.complete, true);
			frameBinding.view.removeEventListener('focus', frameBinding.focus);
			frameBinding = null;
		}

		function bindFrame(frame) {
			unbindFrame();
			try {
				var frameDocument = frame && frame.contentDocument;
				var frameView = frame && frame.contentWindow;
				if (!frameDocument || !frameView || frame.isConnected === false) return false;
				var click = function (event) { onPickerClick(event, frameView, frame); };
				var complete = function (event) { onPickerCompletion(event); };
				var focus = function () { if (picker) finishPicker(); };
				frameDocument.addEventListener('click', click, true);
				frameDocument.addEventListener('change', complete, true);
				frameDocument.addEventListener('cancel', complete, true);
				frameView.addEventListener('focus', focus);
				frameBinding = { document: frameDocument, view: frameView, click: click, complete: complete, focus: focus };
				return true;
			} catch (error) {
				unbindFrame();
				return false;
			}
		}

		function managedFrameOwnsFocus() {
			try {
				return typeof controller.ownsFocusedFrame === 'function'
					&& controller.ownsFocusedFrame() === true;
			} catch (ignored) {
				return false;
			}
		}

		function onFocus() {
			cancelBlurCheck();
			if (picker) {
				finishPicker();
				return;
			}
			if (document.visibilityState === 'visible') resume();
		}
		function onBlur() {
			if (stopped) return;
			cancelBlurCheck();
			if (picker && (picker.state === 'armed' || picker.state === 'open')) {
				promotePicker();
				return;
			}
			if (picker && picker.state === 'verifying') {
				blurTimer = window.setTimeout(function () {
					blurTimer = 0;
					if (stopped || managedFrameOwnsFocus()) return;
					failPickerClosed();
				}, 0);
				return;
			}
			if (picker) {
				failPickerClosed();
				return;
			}
		}
		function onVisible() {
			cancelBlurCheck();
			if (document.visibilityState !== 'visible') {
				blockPickerCompletion();
				suspendForBackground();
				return;
			}
			if (picker) {
				finishPicker();
				return;
			}
			resume();
		}
		function onPageHide(event) {
			cancelBlurCheck();
			blockPickerCompletion();
			readyByRoot.set(root, false);
			suspended = true;
			running = null;
			controller.invalidate();
			window.clearInterval(timer);
			if (!event || event.persisted !== true) cleanup();
		}
		function cleanup() {
			stopped = true;
			window.clearInterval(timer);
			window.clearTimeout(blurTimer);
			blurTimer = 0;
			blockPickerCompletion();
			unbindFrame();
			window.removeEventListener('focus', onFocus);
			window.removeEventListener('blur', onBlur);
			window.removeEventListener('pagehide', onPageHide);
			document.removeEventListener('visibilitychange', onVisible);
			root.removeEventListener('click', onRootPickerClick, true);
			root.removeEventListener('change', onPickerCompletion, true);
			root.removeEventListener('cancel', onPickerCompletion, true);
			monitors.delete(root);
		}

		function onRootPickerClick(event) { onPickerClick(event, window, null); }

		window.addEventListener('focus', onFocus);
		window.addEventListener('blur', onBlur);
		document.addEventListener('visibilitychange', onVisible);
		window.addEventListener('pagehide', onPageHide);
		root.addEventListener('click', onRootPickerClick, true);
		root.addEventListener('change', onPickerCompletion, true);
		root.addEventListener('cancel', onPickerCompletion, true);
		timer = window.setInterval(function () { void verify(true); }, SESSION_CHECK_MS);
		sessionMonitor = Object.freeze({ bindFrame: bindFrame, stop: stopLocked });
		return sessionMonitor;
	}

	window.addEventListener('message', async function (event) {
		if (event.origin !== window.location.origin || !event.data || event.data.version !== PROTOCOL_VERSION) return;
		var found = rootForFrame(event.source);
		if (!found) return;
		var root = found.root;
		var controller = await (controllers.get(root) || Promise.resolve(null)).catch(function () { return null; });
		if (!controller) return;

		if (event.data.type === 'oj-ml:ready') {
			if (typeof event.data.frameNonce !== 'string' || !/^[a-f0-9]{32}$/.test(event.data.frameNonce)) return;
			controller.suspend();
			controller.setFrame(found.iframe, event.data.frameNonce);
			var assignmentId = String(root.dataset.assignmentId || '');
			var attemptId = await knownAttempt(assignmentId);
			var assignmentPolicy = await assignmentPolicyFor(root, assignmentId);
			if (await controller.verify() !== true) return;
			if (!controller.canConfigure(found.iframe, event.data.frameNonce)) return;
			var monitor = monitors.get(root);
			if (!monitor || !monitor.bindFrame(found.iframe)) {
				controller.invalidate('파일 선택 보호를 초기화하지 못해 실습 화면을 잠갔습니다. 페이지를 새로고침해 주세요.');
				if (monitor) monitor.stop();
				return;
			}
			var storage = controller.storage;
			if (!storage) return;
			root.dispatchEvent(new window.CustomEvent('oj-ml:trusted-frame', { detail: { frame: found.iframe, version: PROTOCOL_VERSION, frameNonce: event.data.frameNonce, assignmentId: assignmentId, storageSession: storage.session } }));
			found.iframe.contentWindow.postMessage({
				type: 'oj-ml:configure',
				version: PROTOCOL_VERSION,
				frameNonce: event.data.frameNonce,
				assignmentId: assignmentId,
				attemptId: attemptId || undefined,
				assignmentPolicy: assignmentPolicy,
				restRoot: root.dataset.restRoot || '',
				nonce: root.dataset.restNonce || '',
				privacyUrl: controller.privacyUrl,
				maxBytes: Number(root.dataset.maxUploadBytes || 104857600),
				storageScope: storage.scope,
				storageSession: storage.session,
				storageKey: storage.key,
				storageDatabase: storage.database,
				storageKeyVersion: storage.keyVersion,
				deviceMode: storage.mode
			}, window.location.origin);
			return;
		}

		if (controller.handleMessage(event)) {
			if (root.dataset.publicPcTerminal === '1') {
				var terminalMonitor = monitors.get(root);
				if (terminalMonitor) terminalMonitor.stop();
			}
			return;
		}
		if (typeof controller.matchesFrameMessage !== 'function' || !controller.matchesFrameMessage(event)) {
			controller.invalidate('실습 앱과의 보안 연결을 확인하지 못해 화면을 잠갔습니다. 페이지를 새로고침해 주세요.');
			var invalidMonitor = monitors.get(root);
			if (invalidMonitor) invalidMonitor.stop();
			return;
		}

		if (event.data.type !== 'oj-ml:submission-status') return;
		var trustedStatus = { state: event.data.state };
		if (typeof event.data.message === 'string') trustedStatus.message = event.data.message.slice(0, 128);
		if (event.data.receipt && typeof event.data.receipt === 'object') trustedStatus.receipt = event.data.receipt;
		if (event.data.attempt && typeof event.data.attempt === 'object') trustedStatus.attempt = event.data.attempt;
		if (event.data.state === 'submitting') {
			setStatus(root, 'MLPX 프로젝트를 서버에 제출하는 중입니다.');
		} else if (event.data.state === 'submitted') {
			setStatus(root, '제출이 서버에 안전하게 접수되었습니다. 결과 화면에서 처리 상태를 확인하세요.', 'success');
		} else if (event.data.state === 'error') {
			setStatus(root, '바로 제출에 실패했습니다. 다시 시도하거나 아래의 파일 제출 대체 수단을 이용해 주세요.', 'error');
		} else return;
		if (typeof window.CustomEvent === 'function' && typeof root.dispatchEvent === 'function') {
			root.dispatchEvent(new window.CustomEvent('oj-ml:trusted-submission-status', {
				detail: trustedStatus
			}));
		}
	});

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.oj-ml-app[data-public-pc-mode="1"]').forEach(function (root) {
			readyByRoot.set(root, false);
			root.addEventListener('click', function (event) {
				var start = event.target && event.target.closest ? event.target.closest('[data-oj-ml-start]') : null;
				if (!start || (readyByRoot.get(root) === true && root.dataset.publicPcLocked !== '1')) return;
				event.preventDefault();
				event.stopImmediatePropagation();
				setStatus(root, '현재 OJ 계정의 암호화 저장소를 확인할 때까지 잠시 기다려 주세요.', 'error');
			}, true);

			var initGuard = guardInitialization(root);
			var activeController = null;
			var promise = window.OjMlPublicPc.initialize(root, apiFor(root, initGuard.signal), setStatus).then(async function (controller) {
				activeController = controller;
				if (
					initGuard.isStale()
					|| document.visibilityState !== 'visible'
				) {
					controller.invalidate();
					initGuard.failClosed();
					return null;
				}
				var verified = await controller.verify();
				if (
					initGuard.isStale()
					|| document.visibilityState !== 'visible'
				) {
					controller.invalidate();
					initGuard.failClosed();
					return null;
				}
				if (verified !== true) {
					initGuard.cleanup();
					return null;
				}
				initGuard.cleanup();
				readyByRoot.set(root, true);
				var monitor = monitorSession(root, controller);
				monitors.set(root, monitor);
				return controller;
			}).catch(function (error) {
				if (initGuard.isStale()) {
					if (activeController !== null) activeController.invalidate();
					initGuard.failClosed();
					return null;
				}
				initGuard.cleanup();
				if (activeController !== null) return null;
				setStatus(root, error && error.message ? error.message : '공용 기기 보호를 초기화하지 못했습니다.', 'error');
				root.dataset.publicPcTerminal = '1';
				delete root.dataset.restNonce;
				delete root.dataset.attemptId;
				delete root.dataset.assignmentId;
				var content = root.querySelector('[data-oj-ml-content]');
				if (content) content.textContent = '';
				return null;
			});
			controllers.set(root, promise);
		});
	});
}());
