import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const rootDirectory = new URL('../', import.meta.url);
const controllerSource = await readFile(new URL('resources/js/public-pc.js', rootDirectory), 'utf8');
const bootstrapSource = await readFile(new URL('resources/js/public-pc-bootstrap.js', rootDirectory), 'utf8');
const addonSource = await readFile(new URL('resources/js/addon.js', rootDirectory), 'utf8');

function storageContext(overrides = {}) {
	const scope = overrides.scope || 'A'.repeat(24);
	return {
		mode: 'shared',
		keyVersion: 1,
		scope,
		session: overrides.session || 'B'.repeat(24),
		key: overrides.key || 'C'.repeat(43),
		database: overrides.database || `ml-playgrounds-oj-v1-${scope}`,
	};
}

function fakeRoot() {
	const attributes = new Map();
	const content = { textContent: 'previous student secret' };
	const button = {
		disabled: false,
		addEventListener() {},
	};
	const panel = { dataset: {} };
	const status = { textContent: '', dataset: {} };
	const rootEvents = eventTarget();
	return {
		root: {
			...rootEvents,
			dataset: {
				publicPcMode: '1',
				privacyUrl: 'https://school.example/privacy',
				restRoot: 'https://school.example/wp-json/oj-ml/v1',
				restNonce: 'nonce',
				attemptId: 'attempt-1',
				assignmentId: '17',
			},
			setAttribute(name, value) { attributes.set(name, String(value)); },
			removeAttribute(name) { attributes.delete(name); },
			getAttribute(name) { return attributes.get(name); },
			querySelector(selector) {
				if (selector === '[data-oj-ml-content]') return content;
				if (selector === '[data-oj-ml-wipe-local]') return button;
				if (selector === '[data-oj-ml-shared-device]') return panel;
				if (selector === '[data-oj-ml-status]') return status;
				return null;
			},
			querySelectorAll() { return []; },
		},
		attributes,
		button,
		content,
		status,
	};
}

function controllerEnvironment() {
	const document = {
		visibilityState: 'visible',
		hasFocus: () => true,
	};
	const window = {
		location: {
			href: 'https://school.example/oj-addons/ml/lab',
			origin: 'https://school.example',
		},
		navigator: {},
		localStorage: {
			length: 0,
			key: () => null,
			removeItem() {},
		},
		AbortController,
		addEventListener() {},
		setTimeout,
		clearTimeout,
		confirm: () => false,
	};
	const context = vm.createContext({ window, document, URL, AbortController, console });
	vm.runInContext(controllerSource, context, { filename: 'public-pc.js' });
	return { window, document };
}

async function assertControllerFocusOwnership() {
	const { window, document } = controllerEnvironment();
	const fixture = fakeRoot();
	const controller = await window.OjMlPublicPc.initialize(
		fixture.root,
		{ get: async () => ({ storage: storageContext() }) },
		() => {},
	);
	const frame = { hidden: false, isConnected: true, contentWindow: {} };
	controller.setFrame(frame, 'a'.repeat(32));

	document.activeElement = frame;
	document.hasFocus = () => true;
	assert.equal(controller.ownsFocusedFrame(), true, 'The registered iframe may retain the public-PC session focus.');

	document.hasFocus = () => false;
	assert.equal(controller.ownsFocusedFrame(), false, 'An iframe activeElement must not bypass a real top-level focus loss.');

	document.hasFocus = () => true;
	document.activeElement = {};
	assert.equal(controller.ownsFocusedFrame(), false, 'Unmanaged focused elements must not bypass suspension.');

	document.activeElement = frame;
	document.visibilityState = 'hidden';
	assert.equal(controller.ownsFocusedFrame(), false, 'A hidden document must never claim iframe focus ownership.');
}

async function assertControllerPickerCover() {
	const { window, document } = controllerEnvironment();
	const fixture = fakeRoot();
	let requests = 0;
	const controller = await window.OjMlPublicPc.initialize(
		fixture.root,
		{
			get: async () => {
				requests += 1;
				return { storage: storageContext() };
			},
		},
		() => {},
	);
	const frame = { hidden: false, isConnected: true, contentWindow: {} };
	controller.setFrame(frame, 'a'.repeat(32));
	assert.equal(await controller.verify(), true);
	assert.equal(frame.inert, false);
	const verifiedRequests = requests;

	assert.equal(controller.cover(), true, 'A foreground picker must enter privacy coverage.');
	assert.equal(fixture.root.dataset.publicPcCovered, '1');
	assert.equal(fixture.attributes.get('aria-busy'), 'true');
	assert.equal(fixture.button.disabled, true);
	assert.equal(frame.hidden, false, 'Privacy coverage must keep the iframe connected and rendered.');
	assert.equal(frame.inert, true, 'Privacy coverage must remove iframe keyboard and accessibility interaction.');
	assert.equal(await controller.verify(), null, 'Periodic verification must not replace picker verification.');
	assert.equal(requests, verifiedRequests);

	assert.equal(await controller.verifyCovered(), true, 'Picker return must revalidate the same storage context.');
	assert.equal(fixture.root.dataset.publicPcCovered, '1', 'The curtain must remain until the monitor completes.');
	assert.equal(frame.hidden, false);
	assert.equal(controller.uncover(), true);
	assert.equal('publicPcCovered' in fixture.root.dataset, false);
	assert.equal(fixture.attributes.has('aria-busy'), false);
	assert.equal(fixture.button.disabled, false);
	assert.equal(frame.inert, false);

	assert.equal(controller.cover(), true);
	document.hasFocus = () => false;
	controller.suspend();
	assert.equal('publicPcCovered' in fixture.root.dataset, false, 'A real suspension must clear the curtain state.');
	assert.equal(frame.hidden, true);
	assert.equal(frame.inert, true);
}

async function assertControllerBackgroundContinuity() {
	const { window, document } = controllerEnvironment();
	const fixture = fakeRoot();
	const pending = deferred();
	let requests = 0;
	const controller = await window.OjMlPublicPc.initialize(
		fixture.root,
		{
			get: async () => {
				requests += 1;
				if (requests <= 2) return { storage: storageContext() };
				return pending.promise;
			},
		},
		() => {},
	);
	const frame = {
		hidden: false,
		src: 'https://school.example/oj-addons/ml/playground/',
		isConnected: true,
		contentWindow: {},
	};
	frame.parentNode = {
		removeChild(child) {
			child.isConnected = false;
			child.parentNode = null;
		},
	};
	controller.setFrame(frame, 'a'.repeat(32));
	assert.equal(await controller.verify(), true);
	document.hasFocus = () => false;

	const checking = controller.verify({ background: true });
	await Promise.resolve();
	assert.equal(frame.hidden, false, 'A delayed background check must leave the iframe visible.');
	assert.equal(frame.inert, false, 'A delayed background check must leave the iframe interactive.');
	assert.equal(fixture.root.dataset.publicPcLocked, undefined);
	assert.equal(fixture.button.disabled, false);

	pending.resolve({ storage: storageContext() });
	assert.equal(await checking, true);
	assert.equal(frame.hidden, false);
	assert.equal(frame.inert, false);

	let reloads = 0;
	window.location.reload = () => { reloads += 1; };
	assert.equal(controller.handleMessage({
		origin: window.location.origin,
		source: frame.contentWindow,
		data: { type: 'oj-ml:storage-revoked', version: 2, frameNonce: 'a'.repeat(32) },
	}), true);
	assert.equal(reloads, 0, 'A revoked child bridge must not enter an automatic reload loop.');
	assert.equal(fixture.root.dataset.publicPcTerminal, '1');
	assert.equal(frame.parentNode, null);
}

async function assertControllerResumeSupersedesStaleVerification() {
	const { window } = controllerEnvironment();
	const fixture = fakeRoot();
	const staleResponse = deferred();
	let requests = 0;
	const controller = await window.OjMlPublicPc.initialize(
		fixture.root,
		{
			get: async () => {
				requests += 1;
				if (requests <= 2 || requests === 4) return { storage: storageContext() };
				return staleResponse.promise;
			},
		},
		() => {},
	);
	const frame = {
		hidden: false,
		src: 'https://school.example/oj-addons/ml/playground/',
		isConnected: true,
		contentWindow: {},
	};
	controller.setFrame(frame, 'b'.repeat(32));
	assert.equal(await controller.verify(), true);

	const stale = controller.verify();
	await Promise.resolve();
	assert.equal(requests, 3);
	controller.suspend();
	const resumed = controller.verify();
	await Promise.resolve();
	assert.equal(requests, 4, 'Resume must start a fresh strict check instead of reusing a suspended promise.');
	assert.equal(await resumed, true);
	assert.equal(frame.hidden, false, 'A successful resume check must restore the workspace.');
	assert.equal(frame.inert, false);
	assert.equal(fixture.root.dataset.publicPcLocked, undefined);

	staleResponse.resolve({ storage: storageContext() });
	assert.equal(await stale, null, 'The superseded pre-suspension check must not mutate the resumed screen.');
}

async function assertControllerFailure(outcome) {
	const { window } = controllerEnvironment();
	const fixture = fakeRoot();
	const statuses = [];
	let request = 0;
	const initial = storageContext();
	const api = {
		async get() {
			request += 1;
			if (request === 1) return { storage: initial };
			if (outcome === 'mismatch') {
				return { storage: storageContext({ session: 'D'.repeat(24) }) };
			}
			throw new Error('session endpoint unavailable');
		},
	};
	const controller = await window.OjMlPublicPc.initialize(
		fixture.root,
		api,
		(_root, text, kind) => statuses.push({ text, kind: kind || 'info' }),
	);
	statuses.length = 0;

	const frame = {
		hidden: false,
		src: 'https://school.example/oj-addons/ml/playground/',
		isConnected: true,
		contentWindow: {},
	};
	frame.parentNode = {
		removeChild(child) {
			child.isConnected = false;
			child.parentNode = null;
		},
	};
	controller.setFrame(frame, 'a'.repeat(32));

	if (outcome === 'mismatch') {
		assert.equal(await controller.verify(), false, 'A changed login context must fail verification.');
	} else {
		await assert.rejects(controller.verify(), /session endpoint unavailable/);
	}

	assert.equal(statuses.length, 1, `${outcome} must publish one terminal status.`);
	assert.equal(statuses[0].kind, 'error', `${outcome} must publish an error status.`);
	assert.ok(statuses[0].text.length > 0, `${outcome} must explain the terminal lock.`);
	assert.equal(fixture.root.dataset.publicPcLocked, '1');
	assert.equal(fixture.attributes.has('aria-busy'), false, 'A terminal result must not remain busy.');
	assert.equal(fixture.button.disabled, true);
	assert.equal(fixture.content.textContent, '');
	assert.equal('restNonce' in fixture.root.dataset, false);
	assert.equal('attemptId' in fixture.root.dataset, false);
	assert.equal('assignmentId' in fixture.root.dataset, false);
	assert.equal(frame.hidden, true);
	assert.equal(frame.src, 'about:blank');
	assert.equal(frame.parentNode, null);
	assert.equal(controller.storage, null);
	assert.equal(controller.canConfigure(frame, 'a'.repeat(32)), false);
	assert.equal(fixture.root.dataset.publicPcTerminal, '1');

	const firstTerminalStatus = { ...statuses[0] };
	controller.invalidate('A later callback must not replace this message.', 'success');
	assert.deepEqual(statuses, [firstTerminalStatus], 'Repeated invalidation must not replace the first terminal status.');
	controller.suspend();
	assert.equal(fixture.attributes.has('aria-busy'), false, 'A late suspend must not restore busy after terminal completion.');
	assert.equal(fixture.button.disabled, true);
	assert.equal(fixture.content.textContent, '');
}

async function assertControllerWipeClick(outcome, withFrame = true) {
	const blockedOutcome = outcome === 'blocked' || outcome === 'blocked-error';
	const { window, document } = controllerEnvironment();
	Object.assign(window, eventTarget());
	Object.assign(document, eventTarget());
	const fixture = fakeRoot();
	const dialogs = [];
	fixture.root.children = [];
	fixture.root.appendChild = (child) => { fixture.root.children.push(child); child.parentNode = fixture.root; };
	fixture.root.removeChild = (child) => {
		fixture.root.children = fixture.root.children.filter((value) => value !== child);
		child.parentNode = null;
	};
	fixture.button.focus = () => { document.activeElement = fixture.button; };
	document.createElement = (tag) => {
		const attributes = new Map();
		const element = {
			...eventTarget(), tagName: tag, children: [], parentNode: null,
			setAttribute: (name, value) => attributes.set(name, String(value)),
			getAttribute: (name) => attributes.get(name),
			appendChild(child) { this.children.push(child); child.parentNode = this; },
			focus() { document.activeElement = this; },
		};
		if (tag === 'dialog') {
			dialogs.push(element);
			element.open = false;
			element.showModal = () => {
				if (outcome === 'dialog-error') throw new Error('showModal failed');
				element.open = true;
			};
			element.close = () => { element.open = false; element.dispatch('close'); };
			if (outcome === 'dialog-unsupported') delete element.showModal;
		}
		return element;
	};
	fixture.root.dataset.ojMlPage = 'lab';
	const panel = fixture.root.querySelector('[data-oj-ml-shared-device]');
	const pendingVerification = deferred();
	const sequence = [];
	const statuses = [];
	const posts = [];
	const deletedDatabases = [];
	const deleteEvents = new Map();
	const timers = [];
	let leaseHeld = false;
	let reloads = 0;
	const initial = storageContext();
	const currentPreference = `ml-playgrounds:theme:oj:${initial.scope}`;
	const otherPreference = `ml-playgrounds:theme:oj:${'Z'.repeat(24)}`;
	const preferences = new Map([[currentPreference, 'dark'], [otherPreference, 'light'], ['unrelated', 'keep']]);
	let clickHandler;
	let requests = 0;
	fixture.button.addEventListener = (type, listener) => {
		assert.equal(type, 'click');
		clickHandler = listener;
	};
	window.confirm = () => { throw new Error('Native confirmation must not be used.'); };
	window.localStorage = {
		get length() { return preferences.size; },
		key: (index) => [...preferences.keys()][index] ?? null,
		removeItem: (key) => preferences.delete(key),
	};
	window.setTimeout = (callback, delay) => {
		const timer = { callback, delay, cleared: false };
		timers.push(timer);
		return timer;
	};
	window.clearTimeout = (timer) => { timer.cleared = true; };
	window.location.reload = () => { reloads += 1; };
	window.BroadcastChannel = class {
		constructor(name) { assert.equal(name, `ml-playgrounds:oj-storage-control-v1:${initial.scope}`); }
		postMessage(message) {
			assert.equal(message.type, 'oj-ml:storage-revoke');
			assert.equal(message.scope, initial.scope);
			sequence.push('revoke');
			if (outcome === 'revoke-error') throw new Error('PRIVATE_INTERNAL_REVOKE_FAILURE');
		}
		close() {}
	};
	window.navigator.locks = {
		async request(name, options, callback) {
			assert.equal(name, `ml-playgrounds:oj-storage-wipe-v1:${initial.scope}`);
			assert.equal(options.mode, 'exclusive');
			sequence.push('exclusive-lock');
			return Promise.resolve().then(() => {
				if (options.signal.aborted) throw Object.assign(new Error('aborted'), { name: 'AbortError' });
				leaseHeld = true;
				return Promise.resolve(callback()).finally(() => { leaseHeld = false; });
			});
		},
	};
	window.indexedDB = {
		deleteDatabase(name) {
			deletedDatabases.push(name);
			sequence.push('delete-database');
			return { addEventListener(type, listener) {
				deleteEvents.set(type, listener);
				if (type === (blockedOutcome ? 'blocked' : 'success')) queueMicrotask(listener);
			} };
		},
	};
	const controller = await window.OjMlPublicPc.initialize(fixture.root, {
		async get(path) {
			assert.equal(path, 'session/storage-context');
			requests += 1;
			if (requests <= 2) return { storage: initial };
			sequence.push('verify');
			return pendingVerification.promise;
		},
	}, (_root, text, kind) => statuses.push({ text, kind: kind || 'info' }));
	const frame = {
		hidden: false,
		isConnected: true,
		src: 'https://school.example/oj-addons/ml/playground/',
		contentWindow: { postMessage(message, origin) {
			assert.equal(origin, window.location.origin);
			posts.push(message);
			sequence.push('wipe-request');
		} },
	};
	frame.parentNode = { removeChild(child) { child.isConnected = false; child.parentNode = null; } };
	if (withFrame) controller.setFrame(frame, 'a'.repeat(32));
	assert.equal(await controller.verify(), true);
	assert.equal(fixture.button.disabled, false);
	assert.equal(typeof clickHandler, 'function', 'The actual wipe button click handler must be registered.');
	const clicking = clickHandler();
	assert.equal(dialogs.length, 1);
	const dialog = dialogs[0];
	assert.deepEqual(deletedDatabases, []);
	assert.deepEqual(posts, []);
	assert.equal(requests, 2, 'Opening the dialog must not verify or delete before consent.');
	assert.equal(panel.dataset.busy, undefined, 'The confirmation must remain outside the busy panel.');
	assert.equal(window.listenerCount('message'), 0, 'Bootstrap must be the only window message dispatcher.');
	if (outcome === 'dialog-error' || outcome === 'dialog-unsupported') {
		await clicking;
		assert.deepEqual(sequence, []);
		assert.equal(requests, 2);
		assert.equal(fixture.button.disabled, false);
		assert.equal(fixture.root.children.length, 0);
		assert.match(statuses.at(-1).text, /삭제 확인창을 열지 못해 삭제를 시작하지 않았습니다/);
		assert.equal(document.listenerCount('visibilitychange'), 0);
		assert.equal(window.listenerCount('pagehide'), 0);
		return;
	}
	assert.equal(dialog.parentNode, fixture.root);
	assert.equal(dialog.open, true);
	const [title, description, actions] = dialog.children;
	const [cancelButton, acceptButton] = actions.children;
	assert.equal(dialog.getAttribute('aria-labelledby'), title.id);
	assert.equal(dialog.getAttribute('aria-describedby'), description.id);
	assert.match(description.textContent, /현재 로그인한 OJ 계정/);
	assert.match(description.textContent, /이미 OJ 서버에 제출한 파일/);
	assert.match(description.textContent, /되돌릴 수 없습니다/);
	assert.equal(cancelButton.type, 'button');
	assert.equal(acceptButton.type, 'button');
	assert.equal(document.activeElement, cancelButton, 'Initial focus must be on Cancel.');
	for (const [shiftKey, target] of [[false, acceptButton], [false, cancelButton], [true, acceptButton], [true, cancelButton]]) {
		let prevented = false;
		dialog.dispatch('keydown', { key: 'Tab', shiftKey, preventDefault() { prevented = true; } });
		assert.equal(prevented, true);
		assert.equal(document.activeElement, target, 'Tab and Shift+Tab must wrap within the two confirmation controls.');
	}
	assert.equal(fixture.button.disabled, true);
	await clickHandler();
	assert.equal(dialogs.length, 1, 'Repeated clicks must not create another confirmation.');
	const ordinaryCancel = ['cancel', 'escape', 'close'].includes(outcome);
	const safetyCancel = ['confirm-hidden', 'confirm-pagehide', 'confirm-suspend', 'confirm-invalidated'].includes(outcome);
	if (outcome === 'cancel') cancelButton.dispatch('click', { preventDefault() {} });
	else if (outcome === 'escape') dialog.dispatch('cancel', { preventDefault() {} });
	else if (outcome === 'close') dialog.close();
	else if (outcome === 'confirm-hidden') {
		document.visibilityState = 'hidden'; document.dispatch('visibilitychange');
	} else if (outcome === 'confirm-pagehide') window.dispatch('pagehide');
	else if (outcome === 'confirm-suspend') controller.suspend();
	else if (outcome === 'confirm-invalidated') controller.invalidate('Account changed');
	else { sequence.push('confirm'); acceptButton.dispatch('click'); }
	assert.equal(dialog.open, false);
	assert.equal(fixture.root.children.length, 0);
	assert.equal(document.listenerCount('visibilitychange'), 0);
	assert.equal(window.listenerCount('pagehide'), 0);
	acceptButton.dispatch('click');
	assert.equal(dialog.listenerCount('cancel'), 0);
	assert.equal(dialog.listenerCount('close'), 0);
	assert.equal(dialog.listenerCount('keydown'), 0);
	assert.equal(acceptButton.listenerCount('click'), 0);
	assert.equal(cancelButton.listenerCount('click'), 0);

	if (ordinaryCancel || safetyCancel) {
		await clicking;
		assert.deepEqual(sequence, [], 'Cancel must not revalidate, revoke, or delete anything.');
		assert.equal(requests, 2);
		assert.equal(panel.dataset.busy, undefined);
		if (ordinaryCancel) {
			assert.equal(fixture.button.disabled, false);
			assert.equal(fixture.root.dataset.publicPcLocked, undefined);
			assert.deepEqual(statuses, []);
			assert.equal(document.activeElement, fixture.button);
		}
		if (outcome === 'confirm-invalidated' || outcome === 'confirm-suspend' || outcome === 'confirm-hidden') {
			assert.equal(fixture.button.disabled, true, 'Cancellation must not undo a security lock.');
		}
		assert.equal(preferences.size, 3);
		assert.equal(timers.length, 0);
		if (outcome !== 'confirm-invalidated') assert.equal(frame.isConnected, true);
		return;
	}
	await flushPromises();

	assert.deepEqual(sequence, ['confirm', 'verify'], 'Confirmation must start fresh verification before any wipe.');
	assert.equal(fixture.button.disabled, true);
	assert.equal(panel.dataset.busy, '1');
	assert.equal(fixture.root.dataset.publicPcLocked, '1');
	if (outcome === 'error') pendingVerification.reject(new Error('session endpoint unavailable'));
	else {
		if (outcome === 'hidden') {
			document.visibilityState = 'hidden';
			controller.suspend();
		}
		pendingVerification.resolve({ storage: outcome === 'mismatch'
			? storageContext({ scope: 'D'.repeat(24) }) : initial });
	}
	await flushPromises();

	if (outcome === 'success' && withFrame) {
		assert.equal(posts.length, 1);
		assert.equal(posts[0].type, 'oj-ml:storage-wipe-request');
		assert.equal(posts[0].version, 2);
		assert.equal(posts[0].frameNonce, 'a'.repeat(32));
		assert.equal(statuses.some((status) => status.kind === 'success'), false, 'Success must wait for the child acknowledgement.');
		controller.handleMessage({
			origin: window.location.origin,
			source: frame.contentWindow,
			data: { type: 'oj-ml:storage-wiped', version: 2, frameNonce: 'a'.repeat(32) },
		});
	}
	if (blockedOutcome) {
		assert.equal(leaseHeld, true, 'A blocked DB request must keep its exclusive lease.');
		assert.equal(fixture.root.dataset.publicPcTerminal, undefined, 'Blocked is not a completed deletion.');
		for (const timer of timers.filter((entry) => entry.delay === 10000 && !entry.cleared)) timer.callback();
	}
	await clicking;
	assert.equal(fixture.root.dataset.publicPcTerminal, '1');
	assert.equal(fixture.attributes.has('aria-busy'), false, 'Completed or uncertain deletion must announce a non-busy terminal result.');
	assert.equal(fixture.button.disabled, true);
	assert.equal(panel.dataset.busy, undefined);
	assert.equal(controller.storage, null);
	assert.equal(preferences.get(otherPreference), 'light');
	assert.equal(preferences.get('unrelated'), 'keep');
	assert.equal(timers.some((timer) => timer.delay === 1200), false, `${outcome} must not schedule an automatic reload.`);
	assert.equal(timers.filter((timer) => !timer.cleared).length, 0, `${outcome} must leave no active navigation or wipe timer.`);
	assert.equal(reloads, 0, `${outcome} must keep the terminal result visible.`);

	if (outcome === 'success') {
		assert.equal(fixture.button.textContent, '로컬 데이터 삭제 완료');
		assert.equal(statuses.filter((status) => status.kind === 'success').length, 1);
		assert.match(statuses.at(-1).text, /현재 계정의 로컬 실습 데이터를 삭제했습니다/);
		assert.match(statuses.at(-1).text, /새로고침하면 새 실습을 시작/);
		assert.equal(preferences.has(currentPreference), false);
		assert.deepEqual(sequence, withFrame
			? ['confirm', 'verify', 'wipe-request']
			: ['confirm', 'verify', 'exclusive-lock', 'revoke', 'delete-database']);
		assert.deepEqual(deletedDatabases, withFrame ? [] : [initial.database]);
		if (withFrame) assert.equal(frame.isConnected, false);
	} else if (blockedOutcome) {
		assert.deepEqual(sequence, ['confirm', 'verify', 'exclusive-lock', 'revoke', 'delete-database']);
		assert.deepEqual(deletedDatabases, [initial.database]);
		assert.equal(preferences.has(currentPreference), true);
		assert.match(statuses.at(-1).text, /다른 ML 실습 탭이 저장소를 사용 중/);
		assert.doesNotMatch(statuses.at(-1).text, /OJ_STORAGE/);
		assert.equal(leaseHeld, true, 'UI timeout must not release the pending IndexedDB lease.');
		const terminalStatuses = [...statuses];
		deleteEvents.get(outcome === 'blocked-error' ? 'error' : 'success')();
		await flushPromises();
		assert.equal(leaseHeld, false, 'Only a terminal database event may release the lease.');
		assert.deepEqual(statuses, terminalStatuses, 'A late success must not overwrite the uncertain terminal UI.');
	} else if (outcome === 'revoke-error') {
		assert.deepEqual(sequence, ['confirm', 'verify', 'exclusive-lock', 'revoke']);
		assert.deepEqual(deletedDatabases, []);
		assert.equal(preferences.has(currentPreference), true);
		assert.equal(statuses.some((status) => status.kind === 'success'), false);
		assert.doesNotMatch(statuses.at(-1).text, /PRIVATE_INTERNAL/);
	} else {
		assert.deepEqual(sequence, ['confirm', 'verify'], 'A changed or unavailable context must never revoke or wipe.');
		assert.deepEqual(posts, []);
		assert.deepEqual(deletedDatabases, []);
		assert.equal(preferences.has(currentPreference), true);
		assert.equal(statuses.filter((status) => status.kind === 'error').length, 1);
		assert.equal(statuses.some((status) => status.kind === 'success'), false);
		assert.notEqual(fixture.button.textContent, '로컬 데이터 삭제 완료');
		if (outcome === 'hidden') {
			assert.match(statuses.at(-1).text, /삭제를 시작하지 않았습니다/);
			assert.match(statuses.at(-1).text, /페이지를 활성화한 뒤 새로고침/);
		}
	}
}

function eventTarget() {
	const listeners = new Map();
	return {
		CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
		dispatchEvent(event) { this.dispatch(event.type, event); return true; },
		addEventListener(type, listener) {
			if (!listeners.has(type)) listeners.set(type, new Set());
			listeners.get(type).add(listener);
		},
		removeEventListener(type, listener) {
			if (listeners.has(type)) listeners.get(type).delete(listener);
		},
		dispatch(type, event = {}) {
			for (const listener of [...(listeners.get(type) || [])]) listener(event);
		},
		listenerCount(type) { return (listeners.get(type) || new Set()).size; },
	};
}

async function flushPromises() {
	await Promise.resolve();
	await Promise.resolve();
	await new Promise((resolve) => setImmediate(resolve));
}

async function assertMonitorFailure(outcome) {
	const windowEvents = eventTarget();
	const documentEvents = eventTarget();
	const fixture = fakeRoot();
	const statusWrites = [];
	const intervals = [];
	const clearedIntervals = [];
	const timeouts = [];
	let reloads = 0;
	let verifyCalls = 0;
	let invalidations = 0;
	let statusSetter = null;
	let terminal = false;
	let statusText = '';
	Object.defineProperty(fixture.status, 'textContent', {
		configurable: true,
		get: () => statusText,
		set(value) {
			statusText = String(value);
			statusWrites.push(statusText);
		},
	});

	const controller = {
		verify() {
			verifyCalls += 1;
			if (verifyCalls === 1) {
				delete fixture.root.dataset.publicPcLocked;
				fixture.root.removeAttribute('aria-busy');
				fixture.button.disabled = false;
				return Promise.resolve(true);
			}
			this.invalidate(outcome === 'mismatch'
				? '로그인 보호 정보가 변경되어 실습 화면을 잠갔습니다.'
				: '로그인 상태를 확인할 수 없어 실습 화면을 잠갔습니다.');
			return outcome === 'mismatch'
				? Promise.resolve(false)
				: Promise.reject(new Error('session endpoint unavailable'));
		},
		invalidate(message) {
			invalidations += 1;
			if (!terminal && message && statusSetter) statusSetter(fixture.root, message, 'error');
			terminal = true;
			fixture.root.dataset.publicPcLocked = '1';
			fixture.root.dataset.publicPcTerminal = '1';
			fixture.root.setAttribute('aria-busy', 'true');
			fixture.button.disabled = true;
		},
		suspend() {},
		setFrame() {},
		canConfigure: () => false,
		handleMessage: () => false,
		get storage() { return terminal ? null : storageContext(); },
		privacyUrl: 'https://school.example/privacy',
	};

	fixture.root.querySelectorAll = () => [];
	const document = {
		...documentEvents,
		visibilityState: 'visible',
		hasFocus: () => true,
		documentElement: { style: {} },
		querySelectorAll(selector) {
			return selector === '.oj-ml-app[data-public-pc-mode="1"]' ? [fixture.root] : [];
		},
	};
	const location = {
		href: 'https://school.example/oj-addons/ml/lab',
		origin: 'https://school.example',
		reload() { reloads += 1; },
	};
	const window = {
		...windowEvents,
		location,
		AbortController,
		fetch: async () => { throw new Error('Unexpected fetch'); },
		setInterval(callback, delay) {
			const timer = { callback, delay };
			intervals.push(timer);
			return timer;
		},
		clearInterval(timer) { clearedIntervals.push(timer); },
		setTimeout(callback, delay) {
			const timer = { callback, delay };
			timeouts.push(timer);
			return timer;
		},
		clearTimeout() {},
		OjMlPublicPc: {
			initialize(_root, _api, setStatus) {
				statusSetter = setStatus;
				return Promise.resolve(controller);
			},
		},
	};
	const context = vm.createContext({ window, document, URL, AbortController, console });
	vm.runInContext(bootstrapSource, context, { filename: 'public-pc-bootstrap.js' });
	assert.equal(typeof window.OjMlPublicPcReady, 'function');
	assert.equal(await window.OjMlPublicPcReady(fixture.root), false, 'A root without a controller must not be ready.');
	document.dispatch('DOMContentLoaded');
	await flushPromises();

	assert.equal(await window.OjMlPublicPcReady(fixture.root), true, 'A verified and unlocked root must become ready.');
	assert.equal(intervals.length, 1, 'A ready public-PC root must start one session monitor.');
	statusWrites.length = 0;
	intervals[0].callback();
	await flushPromises();

	assert.equal(invalidations, 1, `${outcome} must not be invalidated again by the monitor.`);
	assert.equal(statusWrites.length, 1, `${outcome} must not duplicate the controller-owned status.`);
	assert.equal(fixture.status.dataset.kind, 'error');
	assert.equal(timeouts.length, 0, `${outcome} must not schedule a reload.`);
	assert.equal(reloads, 0, `${outcome} must remain visibly locked without reloading.`);
	assert.ok(clearedIntervals.includes(intervals[0]), `${outcome} must stop periodic verification.`);
	assert.equal(window.listenerCount('focus'), 0, `${outcome} must remove the focus monitor.`);
	assert.equal(window.listenerCount('blur'), 0, `${outcome} must remove the blur monitor.`);
	assert.equal(document.listenerCount('visibilitychange'), 0, `${outcome} must remove the visibility monitor.`);
	assert.equal(await window.OjMlPublicPcReady(fixture.root), false, `${outcome} terminal roots must stop being ready.`);
	assert.equal(fixture.root.dataset.publicPcLocked, '1');
	assert.equal(fixture.root.dataset.publicPcTerminal, '1');
	assert.equal(fixture.button.disabled, true);

	let prevented = false;
	let propagationStopped = false;
	fixture.root.dispatch('click', {
		target: { closest: () => ({}) },
		preventDefault() { prevented = true; },
		stopImmediatePropagation() { propagationStopped = true; },
	});
	assert.equal(prevented, true);
	assert.equal(propagationStopped, true);
	assert.equal(statusWrites.length, 1, `${outcome} locked click must not overwrite terminal status.`);
	assert.equal(fixture.button.disabled, true, `${outcome} locked click must not re-enable controls.`);
}

async function assertMonitorBlurRouting() {
	const windowEvents = eventTarget();
	const documentEvents = eventTarget();
	const fixture = fakeRoot();
	const intervals = [];
	const timeouts = [];
	let focusState = true;
	let suspendCalls = 0;
	let verifyCalls = 0;
	const verifyModes = [];
	let document;
	const frame = {
		hidden: false,
		isConnected: true,
		contentWindow: {},
	};

	const controller = {
		verify(options) {
			verifyModes.push(options || null);
			verifyCalls += 1;
			delete fixture.root.dataset.publicPcLocked;
			fixture.root.removeAttribute('aria-busy');
			fixture.button.disabled = false;
			frame.hidden = false;
			return Promise.resolve(true);
		},
		ownsFocusedFrame() {
			return document.visibilityState === 'visible'
				&& document.hasFocus()
				&& document.activeElement === frame;
		},
		suspend() {
			suspendCalls += 1;
			fixture.root.dataset.publicPcLocked = '1';
			fixture.root.setAttribute('aria-busy', 'true');
			fixture.button.disabled = true;
			frame.hidden = true;
		},
		invalidate() {},
		setFrame() {},
		canConfigure: () => false,
		handleMessage: () => false,
		get storage() { return storageContext(); },
		privacyUrl: 'https://school.example/privacy',
	};

	fixture.root.querySelectorAll = () => [];
	document = {
		...documentEvents,
		visibilityState: 'visible',
		activeElement: frame,
		hasFocus: () => focusState,
		documentElement: { style: {} },
		querySelectorAll(selector) {
			return selector === '.oj-ml-app[data-public-pc-mode="1"]' ? [fixture.root] : [];
		},
	};
	const location = {
		href: 'https://school.example/oj-addons/ml/lab',
		origin: 'https://school.example',
		reload() {},
	};
	const window = {
		...windowEvents,
		location,
		AbortController,
		fetch: async () => { throw new Error('Unexpected fetch'); },
		setInterval(callback, delay) {
			const timer = { callback, delay };
			intervals.push(timer);
			return timer;
		},
		clearInterval() {},
		setTimeout(callback, delay) {
			const timer = { callback, delay, cleared: false };
			timeouts.push(timer);
			return timer;
		},
		clearTimeout(timer) {
			if (timer) timer.cleared = true;
		},
		OjMlPublicPc: {
			initialize() { return Promise.resolve(controller); },
		},
	};
	function runTimeouts() {
		const pending = timeouts.splice(0);
		for (const timer of pending) if (!timer.cleared) timer.callback();
	}

	const context = vm.createContext({ window, document, URL, AbortController, console });
	vm.runInContext(bootstrapSource, context, { filename: 'public-pc-bootstrap.js' });
	document.dispatch('DOMContentLoaded');
	await flushPromises();

	assert.equal(intervals.length, 1, 'The focus test must start one session monitor.');
	assert.equal(verifyCalls, 1, 'Initialization must verify once before monitoring focus.');
	assert.equal(await window.OjMlPublicPcReady(fixture.root), true);
	intervals[0].callback();
	await flushPromises();
	assert.equal(verifyModes.at(-1)?.background, true, 'The 45-second check must be silent.');
	assert.equal(frame.hidden, false, 'A successful periodic check must keep the project visible.');
	assert.equal(fixture.root.dataset.publicPcLocked, undefined);
	assert.equal(await window.OjMlPublicPcReady(fixture.root), true);


	focusState = false;
	window.dispatch('blur');
	focusState = true;
	window.dispatch('focus');
	await flushPromises();
	runTimeouts();
	assert.equal(suspendCalls, 0, 'Returning before the deferred blur check must cancel the stale lock.');
	assert.equal(frame.hidden, false);

	window.dispatch('blur');
	assert.equal(suspendCalls, 0, 'Blur classification must wait for iframe focus to settle.');
	runTimeouts();
	assert.equal(suspendCalls, 0, 'Focusing the managed iframe must not suspend or hide it.');
	assert.equal(frame.hidden, false);
	assert.equal(await window.OjMlPublicPcReady(fixture.root), true);

	document.visibilityState = 'hidden';
	document.dispatch('visibilitychange');
	assert.equal(suspendCalls, 1, 'A hidden document must suspend immediately.');
	assert.equal(frame.hidden, true);
	assert.equal(await window.OjMlPublicPcReady(fixture.root), false);

	document.visibilityState = 'visible';
	focusState = true;
	document.dispatch('visibilitychange');
	await flushPromises();
	assert.equal(frame.hidden, false, 'Successful foreground revalidation must restore the frame.');
	assert.equal(await window.OjMlPublicPcReady(fixture.root), true);

	focusState = false;
	document.activeElement = frame;
	window.dispatch('blur');
	runTimeouts();
	assert.equal(suspendCalls, 1, 'A visible page must not hide the project merely because window focus moved.');
	assert.equal(frame.hidden, false);
	assert.equal(await window.OjMlPublicPcReady(fixture.root), true);

	focusState = true;
	window.dispatch('focus');
	await flushPromises();
	assert.equal(frame.hidden, false);
	assert.equal(verifyModes.at(-1)?.background, true, 'Focus return without suspension must stay silent.');
	delete controller.ownsFocusedFrame;
	window.dispatch('blur');
	runTimeouts();
	assert.equal(suspendCalls, 1, 'Ordinary blur no longer depends on iframe focus ownership.');
	assert.equal(frame.hidden, false);

	window.dispatch('focus');
	await flushPromises();
	assert.equal(frame.hidden, false);
	controller.ownsFocusedFrame = () => {
		throw new Error('stale controller method failure');
	};
	window.dispatch('blur');
	runTimeouts();
	assert.equal(suspendCalls, 1, 'A focus-helper failure outside a picker must not blank the workspace.');
	assert.equal(frame.hidden, false);
	assert.equal(await window.OjMlPublicPcReady(fixture.root), true);
}

async function assertNativePickerLifecycle() {
	const windowEvents = eventTarget();
	const documentEvents = eventTarget();
	const frameWindowEvents = eventTarget();
	const frameDocumentEvents = eventTarget();
	const fixture = fakeRoot();
	const intervals = [];
	const timeouts = [];
	const framePosts = [];
	let focusState = true;
	let suspendCalls = 0;
	let verifyCalls = 0;
	let coverCalls = 0;
	let verifyCoveredCalls = 0;
	let uncoverCalls = 0;
	let throwCover = false;
	let hangVerifyCovered = false;
	let covered = false;
	let document;
	let window;

	class FakeEvent {
		constructor(type, options = {}) {
			this.type = type;
			this.bubbles = options.bubbles === true;
		}
	}

	const frameView = {
		...frameWindowEvents,
		navigator: { userActivation: { isActive: true } },
		Event: FakeEvent,
		postMessage(message) { framePosts.push(message); },
	};
	const frameDocument = {
		...frameDocumentEvents,
		defaultView: frameView,
	};
	const frame = {
		hidden: false,
		isConnected: true,
		contentWindow: frameView,
		contentDocument: frameDocument,
	};

	const controller = {
		verify() {
			verifyCalls += 1;
			delete fixture.root.dataset.publicPcLocked;
			fixture.root.removeAttribute('aria-busy');
			fixture.button.disabled = false;
			frame.hidden = false;
			return Promise.resolve(true);
		},
		verifyCovered() {
			verifyCoveredCalls += 1;
			if (hangVerifyCovered) return new Promise(() => {});
			return Promise.resolve(focusState && document.visibilityState === 'visible');
		},
		ownsFocusedFrame() {
			return document.visibilityState === 'visible'
				&& document.hasFocus()
				&& document.activeElement === frame;
		},
		cover() {
			if (!focusState || covered || fixture.root.dataset.publicPcLocked === '1') return false;
			coverCalls += 1;
			covered = true;
			fixture.root.dataset.publicPcCovered = '1';
			fixture.root.setAttribute('aria-busy', 'true');
			fixture.button.disabled = true;
			frame.hidden = false;
			if (throwCover) throw new Error('Partial cover failure');
			return true;
		},
		uncover() {
			if (!covered || !focusState || document.visibilityState !== 'visible') return false;
			uncoverCalls += 1;
			covered = false;
			delete fixture.root.dataset.publicPcCovered;
			fixture.root.removeAttribute('aria-busy');
			fixture.button.disabled = false;
			frame.hidden = false;
			return true;
		},
		suspend() {
			suspendCalls += 1;
			covered = false;
			delete fixture.root.dataset.publicPcCovered;
			fixture.root.dataset.publicPcLocked = '1';
			fixture.root.setAttribute('aria-busy', 'true');
			fixture.button.disabled = true;
			frame.hidden = true;
		},
		invalidate() {
			this.suspend();
			fixture.root.dataset.publicPcTerminal = '1';
		},
		setFrame() {},
		canConfigure: () => true,
		handleMessage: () => false,
		get storage() { return storageContext(); },
		privacyUrl: 'https://school.example/privacy',
	};

	const originalQuerySelector = fixture.root.querySelector.bind(fixture.root);
	fixture.root.querySelector = (selector) =>
		selector === '.oj-ml-playground-frame' ? frame : originalQuerySelector(selector);
	fixture.root.querySelectorAll = () => [];
	fixture.root.contains = (node) => node?.ownerDocument === document;
	document = {
		...documentEvents,
		visibilityState: 'visible',
		activeElement: frame,
		hasFocus: () => focusState,
		documentElement: { style: {} },
		querySelectorAll(selector) {
			return selector === '.oj-ml-app[data-public-pc-mode="1"]' ? [fixture.root] : [];
		},
	};
	const location = {
		href: 'https://school.example/oj-addons/ml/lab',
		origin: 'https://school.example',
		reload() {},
	};
	window = {
		...windowEvents,
		location,
		navigator: { userActivation: { isActive: true } },
		Event: FakeEvent,
		AbortController,
		fetch: async () => { throw new Error('Unexpected fetch'); },
		setInterval(callback, delay) {
			const timer = { callback, delay };
			intervals.push(timer);
			return timer;
		},
		clearInterval() {},
		setTimeout(callback, delay) {
			const timer = { callback, delay, cleared: false, fired: false };
			timeouts.push(timer);
			return timer;
		},
		clearTimeout(timer) {
			if (timer) timer.cleared = true;
		},
		OjMlPublicPc: {
			initialize() { return Promise.resolve(controller); },
		},
	};
	document.defaultView = window;

	function domEvent(target, type) {
		return {
			target,
			type,
			prevented: false,
			immediateStopped: false,
			preventDefault() { this.prevented = true; },
			stopImmediatePropagation() { this.immediateStopped = true; },
			stopPropagation() {},
		};
	}

	function fileInput(ownerDocument) {
		return {
			nodeType: 1,
			tagName: 'INPUT',
			type: 'file',
			ownerDocument,
			isConnected: true,
			value: 'C:\\fakepath\\dataset.csv',
			replayed: [],
			dispatchEvent(event) {
				this.replayed.push(event.type);
				const replay = domEvent(this, event.type);
				if (ownerDocument === frameDocument) frameDocument.dispatch(event.type, replay);
				else fixture.root.dispatch(event.type, replay);
				return true;
			},
		};
	}

	function runTimers(maxDelay) {
		let next;
		do {
			next = timeouts.find((timer) => !timer.cleared && !timer.fired && timer.delay <= maxDelay);
			if (next) {
				next.fired = true;
				next.callback();
			}
		} while (next);
	}

	const context = vm.createContext({ window, document, URL, AbortController, console, WeakSet, Promise });
	vm.runInContext(bootstrapSource, context, { filename: 'public-pc-bootstrap.js' });
	document.dispatch('DOMContentLoaded');
	await flushPromises();
	window.dispatch('message', {
		origin: location.origin,
		source: frameView,
		data: { type: 'oj-ml:ready', version: 2, frameNonce: 'a'.repeat(32) },
	});
	await flushPromises();
	assert.equal(framePosts.some((message) => message.type === 'oj-ml:configure'), true);
	assert.equal(frameDocument.listenerCount('click'), 1, 'The managed iframe document must capture file inputs.');
	assert.equal(fixture.root.listenerCount('change') > 0, true, 'The parent submission input must be captured.');
	suspendCalls = 0;
	const baselineVerifyCalls = verifyCalls;

	const childInput = fileInput(frameDocument);
	const childClick = domEvent(childInput, 'click');
	frameDocument.dispatch('click', childClick);
	assert.equal(childClick.prevented, false);
	assert.equal(coverCalls, 1);
	assert.equal(fixture.root.dataset.publicPcCovered, '1');
	assert.equal(frame.hidden, false, 'Opening the native picker must not hide the iframe.');
	assert.equal(await window.OjMlPublicPcReady(fixture.root), false);

	focusState = false;
	window.dispatch('blur');
	assert.equal(suspendCalls, 0, 'The armed picker blur must be consumed behind the privacy curtain.');
	intervals[0].callback();
	await flushPromises();
	assert.equal(verifyCalls, baselineVerifyCalls, 'Periodic verification must pause while the native picker is open.');
	assert.equal(frame.hidden, false);

	const verificationBeforeEarlyCompletion = verifyCoveredCalls;
	const childChange = domEvent(childInput, 'change');
	frameDocument.dispatch('change', childChange);
	assert.equal(childChange.immediateStopped, true, 'The original change must wait for session verification.');
	assert.deepEqual(childInput.replayed, []);
	runTimers(100);
	await flushPromises();
	assert.equal(verifyCoveredCalls, verificationBeforeEarlyCompletion, 'A change arriving before top-level focus must wait behind the curtain.');
	assert.equal(frame.hidden, false);
	focusState = true;
	window.dispatch('focus');
	runTimers(100);
	await flushPromises();
	assert.equal(verifyCoveredCalls, 1);
	assert.equal(uncoverCalls, 1);
	assert.deepEqual(childInput.replayed, ['change'], 'A verified selection must replay change exactly once.');
	assert.equal(await window.OjMlPublicPcReady(fixture.root), true);

	const verifyingInput = fileInput(frameDocument);
	frameDocument.dispatch('click', domEvent(verifyingInput, 'click'));
	const verifyingChange = domEvent(verifyingInput, 'change');
	frameDocument.dispatch('change', verifyingChange);
	const suspendsBeforeManagedBlur = suspendCalls;
	window.dispatch('blur');
	runTimers(0);
	assert.equal(suspendCalls, suspendsBeforeManagedBlur, 'Moving focus into the managed iframe during verification must not lock the project.');
	runTimers(100);
	await flushPromises();
	assert.deepEqual(verifyingInput.replayed, ['change']);

	const parentInput = fileInput(document);
	const parentClick = domEvent(parentInput, 'click');
	fixture.root.dispatch('click', parentClick);
	assert.equal(parentClick.prevented, false);
	const uncoversBeforeArmDeadline = uncoverCalls;
	runTimers(1500);
	assert.equal(fixture.root.dataset.publicPcCovered, '1', 'The arm deadline must promote the picker without exposing the project.');
	assert.equal(uncoverCalls, uncoversBeforeArmDeadline);
	assert.equal(await window.OjMlPublicPcReady(fixture.root), false);
	focusState = false;
	window.dispatch('blur');
	focusState = true;
	window.dispatch('focus');
	const parentCancel = domEvent(parentInput, 'cancel');
	fixture.root.dispatch('cancel', parentCancel);
	runTimers(100);
	await flushPromises();
	assert.deepEqual(parentInput.replayed, ['cancel'], 'Cancel must also resume only after verification.');
	assert.equal(await window.OjMlPublicPcReady(fixture.root), true);

	const replayCountBeforeRetry = childInput.replayed.length;
	childInput.value = 'C:\\fakepath\\retry.csv';
	frameDocument.dispatch('click', domEvent(childInput, 'click'));
	const suspendsBeforeHidden = suspendCalls;
	focusState = false;
	window.dispatch('blur');
	document.visibilityState = 'hidden';
	document.dispatch('visibilitychange');
	assert.equal(suspendCalls, suspendsBeforeHidden + 1, 'A hidden page must suspend even while a picker is open.');
	assert.equal(frame.hidden, true);

	document.visibilityState = 'visible';
	focusState = true;
	document.dispatch('visibilitychange');
	await flushPromises();
	assert.equal(await window.OjMlPublicPcReady(fixture.root), true);
	frameDocument.dispatch('click', domEvent(childInput, 'click'));
	frameDocument.dispatch('change', domEvent(childInput, 'change'));
	runTimers(100);
	await flushPromises();
	assert.equal(childInput.replayed.length, replayCountBeforeRetry + 1, 'A successful retry on the same input must clear its stale blocked marker.');

	const lateInput = fileInput(frameDocument);
	frameDocument.dispatch('click', domEvent(lateInput, 'click'));
	focusState = false;
	window.dispatch('blur');
	document.visibilityState = 'hidden';
	document.dispatch('visibilitychange');
	const blockedChange = domEvent(lateInput, 'change');
	frameDocument.dispatch('change', blockedChange);
	assert.equal(blockedChange.immediateStopped, true);
	assert.equal(lateInput.value, '', 'A selection completed after fail-closed suspension must be discarded.');
	document.visibilityState = 'visible';
	focusState = true;
	document.dispatch('visibilitychange');
	await flushPromises();

	parentInput.value = 'C:\\fakepath\\timeout.mlpx';
	fixture.root.dispatch('click', domEvent(parentInput, 'click'));
	const suspendsBeforePickerTimeout = suspendCalls;
	focusState = false;
	window.dispatch('blur');
	runTimers(120000);
	assert.equal(suspendCalls, suspendsBeforePickerTimeout + 1, 'A picker that never closes must fail closed at the bounded timeout.');
	assert.equal(frame.hidden, true);

	focusState = true;
	window.dispatch('focus');
	await flushPromises();
	const hangingInput = fileInput(document);
	hangVerifyCovered = true;
	fixture.root.dispatch('click', domEvent(hangingInput, 'click'));
	fixture.root.dispatch('change', domEvent(hangingInput, 'change'));
	runTimers(100);
	await flushPromises();
	const suspendsBeforeVerificationDeadline = suspendCalls;
	runTimers(15000);
	assert.equal(suspendCalls, suspendsBeforeVerificationDeadline + 1, 'A stalled storage-context verification must fail closed at its own deadline.');
	assert.equal(frame.hidden, true);
	hangVerifyCovered = false;
	window.dispatch('focus');
	await flushPromises();

	throwCover = true;
	const coverFailureClick = domEvent(parentInput, 'click');
	const suspendsBeforeCoverFailure = suspendCalls;
	fixture.root.dispatch('click', coverFailureClick);
	assert.equal(coverFailureClick.prevented, true);
	assert.equal(suspendCalls, suspendsBeforeCoverFailure + 1, 'A partial cover exception must fail closed.');
	assert.equal(frame.hidden, true);
	assert.equal(await window.OjMlPublicPcReady(fixture.root), false);
	throwCover = false;
	window.dispatch('focus');
	await flushPromises();

	window.navigator.userActivation.isActive = false;
	const untrustedClick = domEvent(parentInput, 'click');
	const coversBeforeUntrustedClick = coverCalls;
	fixture.root.dispatch('click', untrustedClick);
	assert.equal(untrustedClick.prevented, true, 'A file chooser without transient user activation must be blocked.');
	assert.equal(coverCalls, coversBeforeUntrustedClick);
}

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise((resolvePromise, rejectPromise) => {
		resolve = resolvePromise;
		reject = rejectPromise;
	});
	return { promise, resolve, reject };
}

function assertAddonUploadReadinessContract() {
	const start = addonSource.indexOf("uploadForm.addEventListener('submit'");
	const end = addonSource.indexOf('\n\t\tif (blockedReason)', start);
	const handler = start >= 0 && end > start ? addonSource.slice(start, end) : '';
	const readiness = handler.indexOf('await window.OjMlPublicPcReady(root)');
	const fileRead = handler.indexOf('input.files');
	assert.equal(readiness >= 0 && fileRead > readiness, true, 'Manual upload must re-check public-PC readiness before reading the chosen file.');
	assert.equal(handler.includes("root.dataset.publicPcCovered === '1'"), true);
	assert.equal(handler.includes("root.dataset.publicPcLocked === '1'"), true);
}

async function assertAddonReadinessRace() {
	const documentEvents = eventTarget();
	const ready = deferred();
	const response = deferred();
	const sequence = [];
	let fetches = 0;
	const status = {
		textContent: '공용 기기 보호를 확인하는 중입니다.',
		dataset: { kind: 'info' },
	};
	const content = {
		textContent: '보호된 기존 내용',
		innerHTML: '<p>보호된 기존 내용</p>',
	};
	const root = {
		dataset: {
			publicPcMode: '1',
			ojMlPage: 'lab',
			assignmentId: '17',
			restRoot: 'https://school.example/wp-json/oj-ml/v1',
			restNonce: 'nonce',
		},
		querySelector(selector) {
			if (selector === '[data-oj-ml-status]') return status;
			if (selector === '[data-oj-ml-content]') return content;
			return null;
		},
	};
	const document = {
		...documentEvents,
		querySelectorAll(selector) {
			return selector === '.oj-ml-app[data-oj-ml-page]' ? [root] : [];
		},
		createElement() {
			throw new Error('Terminal addon flow must not create DOM nodes.');
		},
	};
	const window = {
		location: {
			href: 'https://school.example/oj-addons/ml/lab/17',
			origin: 'https://school.example',
		},
		crypto: { randomUUID: () => 'idempotency-key' },
		addEventListener() {},
		OjMlPublicPcReady() {
			sequence.push('ready');
			return ready.promise;
		},
		fetch() {
			sequence.push('fetch');
			fetches += 1;
			return response.promise;
		},
	};
	const context = vm.createContext({
		window,
		document,
		URL,
		FormData,
		console,
	});
	vm.runInContext(addonSource, context, { filename: 'addon.js' });
	document.dispatch('DOMContentLoaded');
	await flushPromises();

	assert.deepEqual(sequence, ['ready'], 'The addon must consult readiness before starting REST work.');
	assert.equal(fetches, 0, 'The addon must not fetch while public-PC verification is pending.');
	assert.equal(content.innerHTML, '<p>보호된 기존 내용</p>');

	ready.resolve(true);
	await flushPromises();
	assert.deepEqual(sequence, ['ready', 'fetch'], 'REST work may start only after readiness resolves true.');
	assert.equal(fetches, 1);

	root.dataset.publicPcTerminal = '1';
	status.textContent = '로그인 보호 정보가 변경되어 실습 화면을 잠갔습니다.';
	status.dataset.kind = 'error';
	const terminalStatus = status.textContent;
	const terminalContent = content.innerHTML;
	response.resolve({
		ok: true,
		status: 200,
		json: async () => ({ assignment: { title: 'Must not render' } }),
	});
	await flushPromises();

	assert.equal(status.textContent, terminalStatus, 'An in-flight addon response must not overwrite terminal status.');
	assert.equal(status.dataset.kind, 'error');
	assert.equal(content.innerHTML, terminalContent, 'An in-flight addon response must not overwrite terminal DOM.');
}

assertAddonUploadReadinessContract();
await assertAddonReadinessRace();

await assertControllerFocusOwnership();
await assertControllerPickerCover();
await assertControllerBackgroundContinuity();
await assertControllerResumeSupersedesStaleVerification();
await assertControllerFailure('mismatch');
await assertControllerFailure('error');
await assertControllerWipeClick('cancel');
await assertControllerWipeClick('escape');
await assertControllerWipeClick('close');
await assertControllerWipeClick('dialog-error');
await assertControllerWipeClick('dialog-unsupported');
await assertControllerWipeClick('confirm-hidden');
await assertControllerWipeClick('confirm-pagehide');
await assertControllerWipeClick('confirm-suspend');
await assertControllerWipeClick('confirm-invalidated');
await assertControllerWipeClick('success');
await assertControllerWipeClick('success', false);
await assertControllerWipeClick('blocked', false);
await assertControllerWipeClick('blocked-error', false);
await assertControllerWipeClick('revoke-error', false);
await assertControllerWipeClick('hidden');
await assertControllerWipeClick('mismatch');
await assertControllerWipeClick('error');
await assertMonitorFailure('mismatch');
await assertMonitorFailure('error');
await assertMonitorBlurRouting();
await assertNativePickerLifecycle();

console.log('OJ ML public-PC status regression passed.');
