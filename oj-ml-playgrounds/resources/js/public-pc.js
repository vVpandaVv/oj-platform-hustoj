(function () {
	'use strict';

	var PROTOCOL_VERSION = 2;
	var WIPE_TIMEOUT_MS = 10000;
	var wipeDialogSequence = 0;

	function wipeFailureMessage(error) {
		var code = error && typeof error.message === 'string' ? error.message : '';
		var name = error && typeof error.name === 'string' ? error.name : '';
		if (code === 'OJ_STORAGE_DELETE_BLOCKED') {
			return '다른 ML 실습 탭이 저장소를 사용 중이어서 삭제 완료를 확인하지 못했습니다. 모든 ML 실습 탭을 닫은 뒤 이 페이지를 새로고침하고 다시 시도해 주세요.';
		}
		if (code === 'OJ_STORAGE_WIPE_TIMEOUT') {
			return '실습 앱의 삭제 완료 응답을 기다리는 동안 시간이 초과되었습니다. 삭제 결과를 확인하지 못했습니다. 모든 ML 실습 탭을 닫은 뒤 이 페이지를 새로고침하고 다시 시도해 주세요.';
		}
		if (name === 'AbortError') {
			return '다른 ML 실습 탭의 저장소 잠금 해제를 기다리는 동안 시간이 초과되었습니다. 삭제 결과를 확인하지 못했습니다. 모든 ML 실습 탭을 닫은 뒤 이 페이지를 새로고침하고 다시 시도해 주세요.';
		}
		if (code === 'OJ_STORAGE_LOCK_UNAVAILABLE') {
			return '이 브라우저에서 안전한 로컬 데이터 삭제 잠금을 사용할 수 없어 삭제를 시작하지 않았습니다. 브라우저를 업데이트한 뒤 이 페이지를 새로고침하고 다시 시도해 주세요.';
		}
		if (code === 'OJ_STORAGE_CONTEXT_CHANGED') {
			return '삭제 중 실습 저장소 연결이 변경되어 삭제 완료를 확인하지 못했습니다. 이 페이지를 새로고침하고 다시 시도해 주세요.';
		}
		if (code === 'OJ_STORAGE_NOT_READY') {
			return '실습 저장소의 삭제 준비 상태를 확인하지 못해 삭제를 시작하지 않았습니다. 모든 ML 실습 탭을 닫은 뒤 이 페이지를 새로고침하고 다시 시도해 주세요.';
		}
		return '로컬 데이터 삭제 완료를 확인하지 못했습니다. 다른 ML 실습 탭을 모두 닫은 뒤 이 페이지를 새로고침하고 다시 시도해 주세요.';
	}

	function storageContext(payload) {
		var storage = payload && payload.storage ? payload.storage : null;
		if (!storage || storage.mode !== 'shared' || storage.keyVersion !== 1) return null;
		if (typeof storage.scope !== 'string' || !/^[A-Za-z0-9_-]{20,64}$/.test(storage.scope)) return null;
		if (typeof storage.session !== 'string' || !/^[A-Za-z0-9_-]{20,64}$/.test(storage.session)) return null;
		if (typeof storage.key !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(storage.key)) return null;
		if (storage.database !== 'ml-playgrounds-oj-v1-' + storage.scope) return null;
		return Object.freeze({
			scope: storage.scope,
			session: storage.session,
			key: storage.key,
			database: storage.database,
			keyVersion: 1,
			mode: 'shared'
		});
	}

	function httpUrl(value) {
		try {
			var url = new URL(String(value || ''), window.location.href);
			if (url.protocol === 'http:' || url.protocol === 'https:') return url.href;
			return '';
		} catch (ignored) {
			return '';
		}
	}

	function deleteDatabase(name, onBlocked) {
		return new Promise(function (resolve, reject) {
			var request;
			try {
				request = window.indexedDB.deleteDatabase(name);
			} catch (error) {
				reject(error);
				return;
			}
			request.addEventListener('success', function () { resolve(); });
			request.addEventListener('error', function () { reject(request.error || new Error('OJ_STORAGE_WIPE_FAILED')); });
			// blocked is not a cancellation: keep the exclusive lease until the request settles.
			request.addEventListener('blocked', function () { if (onBlocked) onBlocked(); });
		});
	}

	function deleteScopedPreferences(scope) {
		var suffix = ':oj:' + scope;
		try {
			for (var index = window.localStorage.length - 1; index >= 0; index -= 1) {
				var key = window.localStorage.key(index);
				if (key && key.indexOf('ml-playgrounds:') === 0 && key.slice(-suffix.length) === suffix) {
					window.localStorage.removeItem(key);
				}
			}
		} catch (ignored) {}
	}

	function revokeStorage(scope) {
		if (typeof window.BroadcastChannel !== 'function') throw new Error('OJ_STORAGE_LOCK_UNAVAILABLE');
		var channel = new window.BroadcastChannel('ml-playgrounds:oj-storage-control-v1:' + scope);
		try {
			channel.postMessage({
				type: 'oj-ml:storage-revoke',
				version: 1,
				scope: scope
			});
		} finally {
			channel.close();
		}
	}

	async function deleteWithExclusiveLock(storage) {
		var locks = window.navigator && window.navigator.locks;
		if (!locks || typeof locks.request !== 'function') throw new Error('OJ_STORAGE_LOCK_UNAVAILABLE');
		var abort = new window.AbortController();
		var revoked = false;
		var lease = null;
		var blocked = false;
		var timer;
		var deadline = new Promise(function (resolve, reject) {
			timer = window.setTimeout(function () {
				abort.abort();
				reject(new Error(blocked ? 'OJ_STORAGE_DELETE_BLOCKED' : 'OJ_STORAGE_WIPE_TIMEOUT'));
			}, WIPE_TIMEOUT_MS);
		});
		deadline.catch(function () {});
		try {
			// Queue the exclusive lease before revocation lets existing readers release it.
			lease = Promise.resolve(locks.request(
				'ml-playgrounds:oj-storage-wipe-v1:' + storage.scope,
				{ mode: 'exclusive', signal: abort.signal },
				async function () {
					if (!revoked) throw new Error('OJ_STORAGE_NOT_READY');
					await deleteDatabase(storage.database, function () { blocked = true; });
				}
			));
			// Observe rejection immediately even when revocation itself throws.
			lease.catch(function () {});
			revokeStorage(storage.scope);
			revoked = true;
			// UI waiting is bounded; an already-running IndexedDB request must keep its lease.
			await Promise.race([lease, deadline]);
		} catch (error) {
			abort.abort();
			throw error;
		} finally {
			window.clearTimeout(timer);
		}
	}

	async function initialize(root, api, setStatus) {
		if (root.dataset.publicPcMode !== '1') return null;
		var privacyUrl = httpUrl(root.dataset.privacyUrl);
		if (!privacyUrl) throw new Error('개인정보 및 저장 안내 주소가 올바르지 않습니다. 관리자에게 문의해 주세요.');
		root.dataset.publicPcLocked = '1';
		root.setAttribute('aria-busy', 'true');
		var response = await api.get('session/storage-context');
		var storage = storageContext(response);
		response = null;
		if (!storage) throw new Error('공용 기기용 사용자 저장소를 확인하지 못했습니다. 다시 로그인해 주세요.');

		var frame = null;
		var frameNonce = '';
		var pendingWipe = null;
		var panel = root.querySelector('[data-oj-ml-shared-device]');
		var button = root.querySelector('[data-oj-ml-wipe-local]');
		if (button) button.disabled = true;
		var verification = null;
		var verificationMode = '';
		var verificationGeneration = 0;
		var suspended = false;
		var covered = false;
		var wiping = false;
		var terminal = false;
		var confirming = false;
		var cancelConfirmation = null;

		function canConfirmWipe() {
			return !terminal && !suspended && !covered && !wiping && storage !== null
				&& root.isConnected !== false && root.dataset.publicPcLocked !== '1' && isForeground();
		}

		function requestWipeConfirmation() {
			if (confirming || !canConfirmWipe()) return Promise.resolve(false);
			confirming = true;
			if (button) button.disabled = true;
			return new Promise(function (resolve) {
				var dialog = null, cancelButton = null, acceptButton = null, settled = false;
				var doc = root.ownerDocument || document;
				function finish(accepted, restoreFocus) {
					if (settled) return;
					accepted = accepted === true && canConfirmWipe();
					settled = true;
					cancelConfirmation = null;
					confirming = false;
					doc.removeEventListener('visibilitychange', onVisibility);
					window.removeEventListener('pagehide', onPageHide);
					if (cancelButton) cancelButton.removeEventListener('click', onCancel);
					if (acceptButton) acceptButton.removeEventListener('click', onAccept);
					if (dialog) {
						dialog.removeEventListener('cancel', onCancel);
						dialog.removeEventListener('close', onClose);
						dialog.removeEventListener('keydown', onKeyDown);
						try { if (dialog.open) dialog.close(); } catch (ignored) {}
						if (dialog.parentNode) dialog.parentNode.removeChild(dialog);
					}
					if (button) {
						button.disabled = !canConfirmWipe();
						if (!accepted && restoreFocus && !button.disabled && button.isConnected !== false
							&& typeof button.focus === 'function') button.focus();
					}
					resolve(accepted);
				}
				function onCancel(event) { if (event) event.preventDefault(); finish(false, true); }
				function onAccept() { finish(true, false); }
				function onClose() { finish(false, true); }
				function onKeyDown(event) {
					if (event.key !== 'Tab') return;
					event.preventDefault();
					var next = event.shiftKey
						? (doc.activeElement === acceptButton ? cancelButton : acceptButton)
						: (doc.activeElement === cancelButton ? acceptButton : cancelButton);
					next.focus();
				}
				function onVisibility() { if (doc.visibilityState !== 'visible') finish(false, false); }
				function onPageHide() { finish(false, false); }
				cancelConfirmation = function () { finish(false, false); };
				try {
					dialog = doc.createElement('dialog');
					if (typeof dialog.showModal !== 'function') throw new Error('DIALOG_UNAVAILABLE');
					var id = 'oj-ml-wipe-confirm-' + (++wipeDialogSequence);
					dialog.className = 'oj-ml-wipe-dialog';
					dialog.setAttribute('aria-labelledby', id + '-title');
					dialog.setAttribute('aria-describedby', id + '-description');
					var title = doc.createElement('h2');
					title.id = id + '-title';
					title.textContent = '이 기기의 내 실습 데이터를 삭제할까요?';
					var description = doc.createElement('p');
					description.id = id + '-description';
					description.textContent = '현재 로그인한 OJ 계정의 로컬 프로젝트, 데이터셋, 학습 모델, 임베딩과 첨부파일을 이 브라우저에서 삭제합니다. 이 작업은 되돌릴 수 없습니다. 이미 OJ 서버에 제출한 파일과 다운로드 폴더의 .mlpx·.csv·양식 .md 파일은 삭제되지 않습니다.';
					var actions = doc.createElement('div');
					actions.className = 'oj-ml-actions';
					cancelButton = doc.createElement('button');
					cancelButton.type = 'button';
					cancelButton.textContent = '취소';
					cancelButton.autofocus = true;
					acceptButton = doc.createElement('button');
					acceptButton.type = 'button';
					acceptButton.className = 'oj-ml-wipe-dialog__confirm';
					acceptButton.textContent = '로컬 데이터 삭제';
					actions.appendChild(cancelButton);
					actions.appendChild(acceptButton);
					dialog.appendChild(title);
					dialog.appendChild(description);
					dialog.appendChild(actions);
					cancelButton.addEventListener('click', onCancel);
					acceptButton.addEventListener('click', onAccept);
					dialog.addEventListener('cancel', onCancel);
					dialog.addEventListener('close', onClose);
					dialog.addEventListener('keydown', onKeyDown);
					doc.addEventListener('visibilitychange', onVisibility);
					window.addEventListener('pagehide', onPageHide);
					// Outside both the busy notice and the private content hidden by a lock.
					root.appendChild(dialog);
					dialog.showModal();
					cancelButton.focus();
				} catch (error) {
					finish(false, true);
					if (canConfirmWipe()) setStatus(root, '삭제 확인창을 열지 못해 삭제를 시작하지 않았습니다. 이 페이지를 새로고침하거나 최신 Chrome/Edge에서 다시 시도해 주세요.', 'error');
				}
			});
		}

		function clearCover() {
			covered = false;
			delete root.dataset.publicPcCovered;
		}

		function setFrameBlocked(blocked) {
			if (!frame) return;
			frame.inert = blocked === true;
			if (typeof frame.setAttribute !== 'function' || typeof frame.removeAttribute !== 'function') return;
			if (blocked) {
				frame.setAttribute('inert', '');
				frame.setAttribute('aria-hidden', 'true');
			} else {
				frame.removeAttribute('inert');
				frame.removeAttribute('aria-hidden');
			}
		}

		function setFrame(nextFrame, nextNonce) {
			frame = nextFrame || null;
			frameNonce = typeof nextNonce === 'string' && /^[a-f0-9]{32}$/.test(nextNonce) ? nextNonce : '';
			if (frame) {
				var blocked = terminal || suspended || covered || wiping || root.dataset.publicPcLocked === '1';
				setFrameBlocked(blocked);
				frame.hidden = blocked;
			}
		}

		function lock(message, kind) {
			clearCover();
			root.dataset.publicPcLocked = '1';
			if (terminal) root.removeAttribute('aria-busy');
			else root.setAttribute('aria-busy', 'true');
			if (button) button.disabled = true;
			if (frame) {
				setFrameBlocked(true);
				frame.hidden = true;
			}
			if (message) setStatus(root, message, kind);
		}

		function isForeground() {
			return document.visibilityState === 'visible';
		}

		function ownsFocusedFrame() {
			return frame !== null
				&& frame.isConnected !== false
				&& document.visibilityState === 'visible'
				&& typeof document.hasFocus === 'function' && document.hasFocus()
				&& document.activeElement === frame;
		}

		function unlock() {
			if (terminal || suspended || covered || wiping || !isForeground()) return false;
			delete root.dataset.publicPcLocked;
			root.removeAttribute('aria-busy');
			if (button) button.disabled = confirming;
			if (frame) {
				setFrameBlocked(false);
				frame.hidden = false;
			}
			return true;
		}

		function canConfigure(nextFrame, nextNonce) {
			return !terminal
				&& !suspended
				&& !covered
				&& !wiping
				&& storage !== null
				&& root.dataset.publicPcLocked !== '1'
				&& isForeground()
				&& frame === nextFrame
				&& frameNonce === nextNonce
				&& nextFrame && nextFrame.isConnected !== false;
		}

		function destroyFrame() {
			if (pendingWipe) {
				pendingWipe.reject(new Error('OJ_STORAGE_CONTEXT_CHANGED'));
				pendingWipe = null;
			}
			if (frame) {
				try { frame.src = 'about:blank'; } catch (ignored) {}
				if (frame.parentNode) frame.parentNode.removeChild(frame);
			}
			setFrame(null, '');
		}

		function purgePrivateState() {
			storage = null;
			delete root.dataset.restNonce;
			delete root.dataset.attemptId;
			delete root.dataset.assignmentId;
			var content = root.querySelector('[data-oj-ml-content]');
			if (content) content.textContent = '';
		}

		function suspend(message, kind) {
			suspended = true;
			if (cancelConfirmation) cancelConfirmation();
			verificationGeneration += 1;
			lock(message || '', kind);
		}

		function invalidate(message, kind) {
			var firstTerminalStatus = !terminal;
			terminal = true;
			suspend(firstTerminalStatus ? (message || '페이지 보안을 위해 이전 실습 화면을 닫았습니다.') : '', kind || 'error');
			root.dataset.publicPcTerminal = '1';
			purgePrivateState();
			destroyFrame();
		}

		function sameContext(next) {
			return storage !== null
				&& next.scope === storage.scope
				&& next.session === storage.session
				&& next.key === storage.key
				&& next.database === storage.database
				&& next.keyVersion === storage.keyVersion;
		}

		function cover() {
			if (
				terminal || suspended || covered || wiping
				|| root.dataset.publicPcLocked === '1' || !isForeground()
			) return false;
			covered = true;
			verificationGeneration += 1;
			verification = null;
			verificationMode = '';
			root.dataset.publicPcCovered = '1';
			root.setAttribute('aria-busy', 'true');
			if (button) button.disabled = true;
			if (frame) {
				setFrameBlocked(true);
				frame.hidden = false;
			}
			return true;
		}

		function uncover() {
			if (
				terminal || suspended || !covered || wiping
				|| root.dataset.publicPcLocked === '1' || !isForeground()
			) return false;
			clearCover();
			root.removeAttribute('aria-busy');
			if (button) button.disabled = confirming;
			if (frame) {
				setFrameBlocked(false);
				frame.hidden = false;
			}
			return true;
		}

		function verify(options) {
			options = options && typeof options === 'object' ? options : {};
			var background = options.background === true;
			var keepLocked = options.keepLocked === true;
			if (terminal) return Promise.resolve(false);
			if (wiping || covered) return Promise.resolve(null);
			if (!isForeground()) {
				if (!background) suspend();
				return Promise.resolve(null);
			}
			if (verification) {
				if (!suspended && (background || verificationMode === 'strict')) return verification;
				verificationGeneration += 1;
				verification = null;
				verificationMode = '';
			}
			if (background) {
				if (suspended || root.dataset.publicPcLocked === '1') return Promise.resolve(null);
			} else {
				suspended = false;
			}
			var generation = ++verificationGeneration;
			if (!background) lock();
			var current = (async function () {
				try {
					var latest = storageContext(await api.get('session/storage-context'));
					if (terminal) return false;
					if (wiping || suspended || covered || generation !== verificationGeneration) return null;
					if (!latest || !sameContext(latest)) {
						invalidate('로그인 보호 정보가 변경되어 실습 화면을 잠갔습니다. 다른 계정으로 전환했다면 페이지를 새로고침하세요. 전환하지 않았다면 관리자에게 REST 캐시와 서버 SALT 설정 확인을 요청하세요.');
						return false;
					}
					if (background) {
						if (
							root.dataset.publicPcLocked === '1'
							|| !isForeground()
						) return null;
					} else if (!keepLocked && !unlock()) return null;
					return true;
				} catch (error) {
					if (
						!terminal
						&& (wiping || suspended || covered || generation !== verificationGeneration)
					) return null;
					invalidate('로그인 상태를 확인할 수 없어 실습 화면을 잠갔습니다. 로그인과 네트워크 상태를 확인한 뒤 페이지를 새로고침해 주세요.');
					throw error;
				} finally {
					if (verification === current) {
						verification = null;
						verificationMode = '';
					}
				}
			}());
			verification = current;
			verificationMode = background ? 'background' : 'strict';
			return current;
		}

		function verifyCovered() {
			if (terminal) return Promise.resolve(false);
			if (wiping || suspended || !covered || !isForeground()) return Promise.resolve(null);
			var generation = ++verificationGeneration;
			var current = (async function () {
				try {
					var latest = storageContext(await api.get('session/storage-context'));
					if (terminal) return false;
					if (wiping || suspended || !covered || generation !== verificationGeneration) return null;
					if (!latest || !sameContext(latest)) {
						invalidate('로그인 보호 정보가 변경되어 실습 화면을 잠갔습니다. 다른 계정으로 전환했다면 페이지를 새로고침하세요. 전환하지 않았다면 관리자에게 REST 캐시와 서버 SALT 설정 확인을 요청하세요.');
						return false;
					}
					if (!isForeground()) return null;
					return true;
				} catch (error) {
					if (
						!terminal
						&& (wiping || suspended || !covered || generation !== verificationGeneration)
					) return null;
					invalidate('로그인 상태를 확인할 수 없어 실습 화면을 잠갔습니다. 로그인과 네트워크 상태를 확인한 뒤 페이지를 새로고침해 주세요.');
					throw error;
				} finally {
					if (verification === current) {
						verification = null;
						verificationMode = '';
					}
				}
			}());
			verificationMode = 'covered';
			verification = current;
			return current;
		}

		function matchesFrameMessage(event) {
			if (!frame || event.source !== frame.contentWindow || event.origin !== window.location.origin) return false;
			if (!event.data || event.data.version !== PROTOCOL_VERSION || event.data.frameNonce !== frameNonce) return false;
			return true;
		}

		function handleMessage(event) {
			if (!matchesFrameMessage(event)) return false;
			if (event.data.type === 'oj-ml:storage-revoked') {
				invalidate('실습 저장소 연결이 종료되었습니다. 페이지를 새로고침하면 현재 OJ 계정의 저장소에 다시 연결됩니다.');
				return true;
			}
			if (!pendingWipe) return false;
			if (event.data.type === 'oj-ml:storage-wiped') {
				pendingWipe.resolve();
				pendingWipe = null;
				destroyFrame();
				return true;
			}
			if (event.data.type === 'oj-ml:storage-error') {
				var error = new Error(event.data.code || 'OJ_STORAGE_WIPE_FAILED');
				pendingWipe.reject(error);
				pendingWipe = null;
				invalidate(wipeFailureMessage(error));
				return true;
			}
			return false;
		}

		async function wipe() {
			if (storage === null) throw new Error('OJ_STORAGE_CONTEXT_CHANGED');
			var scope = storage.scope;
			var database = storage.database;
			if (!frame || !frame.contentWindow || !frameNonce) {
				await deleteWithExclusiveLock({ scope: scope, database: database });
			} else await new Promise(function (resolve, reject) {
				var timer = window.setTimeout(function () {
					if (!pendingWipe) return;
					pendingWipe = null;
					reject(new Error('OJ_STORAGE_WIPE_TIMEOUT'));
				}, WIPE_TIMEOUT_MS);
				pendingWipe = {
					resolve: function () { window.clearTimeout(timer); resolve(); },
					reject: function (error) { window.clearTimeout(timer); reject(error); }
				};
				frame.contentWindow.postMessage({
					type: 'oj-ml:storage-wipe-request',
					version: PROTOCOL_VERSION,
					frameNonce: frameNonce
				}, window.location.origin);
			});
			deleteScopedPreferences(scope);
		}

		if (button) {
			button.addEventListener('click', async function () {
				if (confirming || !canConfirmWipe()) return;
				var confirmed = await requestWipeConfirmation();
				if (!confirmed || !canConfirmWipe()) return;
				button.disabled = true;
				if (panel) panel.dataset.busy = '1';
				suspend('현재 OJ 로그인 상태를 다시 확인하는 중입니다.');
				try {
					var verified = await verify({ keepLocked: true });
					if (verified !== true) {
						if (verified === null) {
							invalidate('페이지가 활성 상태인지 확인하지 못해 로컬 데이터 삭제를 시작하지 않았습니다. 이 페이지를 활성화한 뒤 새로고침하고 다시 시도해 주세요.');
						}
						return;
					}
					wiping = true;
					suspend();
					setStatus(root, '현재 계정의 로컬 실습 데이터를 삭제하는 중입니다.');
					await wipe();
					invalidate('이 기기에서 현재 계정의 로컬 실습 데이터를 삭제했습니다. 다운로드 폴더의 .mlpx·.csv·양식 .md 파일은 직접 삭제해야 합니다. 이 페이지를 새로고침하면 새 실습을 시작할 수 있습니다.', 'success');
					button.textContent = '로컬 데이터 삭제 완료';
				} catch (error) {
					invalidate(wipeFailureMessage(error));
				} finally {
					if (panel) delete panel.dataset.busy;
				}
			});
		}

		return Object.freeze({
			get storage() { return terminal ? null : storage; },
			privacyUrl: privacyUrl,
			setFrame: setFrame,
			handleMessage: handleMessage,
			matchesFrameMessage: matchesFrameMessage,
			verify: verify,
			verifyCovered: verifyCovered,
			ownsFocusedFrame: ownsFocusedFrame,
			cover: cover,
			uncover: uncover,
			canConfigure: canConfigure,
			suspend: suspend,
			invalidate: invalidate
		});
	}

	Object.defineProperty(window, 'OjMlPublicPc', {
		configurable: false,
		enumerable: false,
		writable: false,
		value: Object.freeze({ initialize: initialize })
	});
}());
