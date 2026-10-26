import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const rootDirectory = new URL('../', import.meta.url);
// Optional stdin contains unchanged release sources for a before/after regression run.
// The default always exercises both real, current product files without patching them.
const baselineInput = process.argv.includes('--source-json-stdin') ? readFileSync(0, 'utf8') : '';
const baseline = baselineInput
	? JSON.parse(baselineInput.charCodeAt(0) === 0xfeff ? baselineInput.slice(1) : baselineInput) : null;
const controllerSource = baseline?.controllerSource
	?? await readFile(new URL('resources/js/public-pc.js', rootDirectory), 'utf8');
const bootstrapSource = baseline?.bootstrapSource
	?? await readFile(new URL('resources/js/public-pc-bootstrap.js', rootDirectory), 'utf8');

const frameNonce = 'a'.repeat(32);
const origin = 'https://school.example';
const scope = 'A'.repeat(24);
const currentPreference = `ml-playgrounds:theme:oj:${scope}`;
const otherPreference = `ml-playgrounds:theme:oj:${'Z'.repeat(24)}`;

function eventTarget() {
	const listeners = new Map();
	return {
		addEventListener(type, listener) {
			if (!listeners.has(type)) listeners.set(type, new Set());
			listeners.get(type).add(listener);
		},
		removeEventListener(type, listener) { listeners.get(type)?.delete(listener); },
		dispatch(type, event = {}) {
			// Browser dispatch calls all listeners synchronously in registration order;
			// an async listener's continuation must NOT run before later listeners.
			return [...(listeners.get(type) || [])].map((listener) => listener(event));
		},
		dispatchEvent(event) { this.dispatch(event.type, event); return true; },
		listenerCount(type) { return listeners.get(type)?.size ?? 0; },
	};
}

function element(tagName, ownerDocument) {
	const attributes = new Map();
	return {
		...eventTarget(),
		nodeType: 1,
		tagName: tagName.toUpperCase(),
		ownerDocument,
		dataset: {},
		children: [],
		isConnected: true,
		textContent: '',
		setAttribute(name, value) { attributes.set(name, String(value)); },
		removeAttribute(name) { attributes.delete(name); },
		getAttribute(name) { return attributes.get(name) ?? null; },
		appendChild(child) { child.parentNode = this; this.children.push(child); return child; },
		removeChild(child) {
			this.children.splice(this.children.indexOf(child), 1);
			child.parentNode = null;
			child.isConnected = false;
			return child;
		},
		focus() { ownerDocument.activeElement = this; },
		showModal() { this.open = true; },
		close() { this.open = false; this.dispatch('close', { type: 'close' }); },
	};
}

async function flush() {
	await new Promise((resolve) => setImmediate(resolve));
}

async function fixture({ assignment = { id: 17, submissionPolicy: { allowedTaskTypes: ['classification'], allowedAlgorithms: ['knn'], teacherPrivateField: 'must-not-cross-bridge' } }, policyFailure = false } = {}) {
	const posts = [];
	const statuses = [];
	const sequence = [];
	const timers = [];
	const preferences = new Map([[currentPreference, 'dark'], [otherPreference, 'light'], ['unrelated', 'keep']]);
	let reloads = 0;
	let frameRemovals = 0;
	let fetches = 0;
	let policyFetches = 0;
	const document = {
		...eventTarget(),
		visibilityState: 'visible',
		hasFocus: () => true,
		documentElement: { style: {} },
		createElement(tagName) { return element(tagName, this); },
	};
	const root = element('section', document);
	root.dataset = {
		publicPcMode: '1', privacyUrl: `${origin}/privacy`,
		restRoot: `${origin}/wp-json/oj-ml/v1`, restNonce: 'fixture-nonce',
		assignmentId: '17', attemptId: 'fixture-attempt', ojMlPage: 'lab',
	};
	const button = element('button', document);
	const panel = element('aside', document);
	const content = element('div', document);
	content.textContent = 'synthetic private content';
	const status = element('p', document);
	let statusKind;
	Object.defineProperty(status.dataset, 'kind', {
		get: () => statusKind,
		set(value) { statusKind = value; statuses.push({ text: status.textContent, kind: value }); },
	});
	const frameDocument = { ...eventTarget() };
	const frameWindow = {
		...eventTarget(),
		postMessage(data, targetOrigin) {
			assert.equal(targetOrigin, origin);
			posts.push(data);
			sequence.push(data.type);
		},
	};
	frameDocument.defaultView = frameWindow;
	const frame = element('iframe', document);
	Object.assign(frame, { contentWindow: frameWindow, contentDocument: frameDocument, hidden: false });
	root.appendChild(frame);
	const removeChild = root.removeChild.bind(root);
	root.removeChild = (child) => {
		if (child === frame) frameRemovals += 1;
		return removeChild(child);
	};
	const parts = new Map([
		['[data-oj-ml-wipe-local]', button], ['[data-oj-ml-shared-device]', panel],
		['[data-oj-ml-content]', content], ['[data-oj-ml-status]', status],
	]);
	root.querySelector = (selector) => selector === '.oj-ml-playground-frame'
		? (frame.isConnected ? frame : null) : (parts.get(selector) ?? null);
	root.querySelectorAll = (selector) => {
		const found = root.querySelector(selector);
		return found ? [found] : [];
	};
	root.contains = (node) => node.ownerDocument === document;
	document.querySelectorAll = (selector) => selector === '.oj-ml-app[data-public-pc-mode="1"]' ? [root] : [];
	document.activeElement = frame;
	function newTimer(callback, delay) {
		const timer = { callback, delay, cleared: false };
		timers.push(timer);
		return timer;
	}
	const window = {
		...eventTarget(),
		location: { href: `${origin}/oj-addons/ml/lab/17`, origin, reload() { reloads += 1; } },
		AbortController,
		CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
		navigator: { userActivation: { isActive: true } },
		setTimeout: newTimer,
		clearTimeout(timer) { if (timer) timer.cleared = true; },
		setInterval: newTimer,
		clearInterval(timer) { if (timer) timer.cleared = true; },
		// Used only by unchanged historical sources in --source-json-stdin mode.
		confirm() { sequence.push('native-confirm'); return true; },
		localStorage: {
			get length() { return preferences.size; },
			key: (index) => [...preferences.keys()][index] ?? null,
			removeItem: (key) => preferences.delete(key),
		},
		indexedDB: { deleteDatabase() { throw new Error('The managed-frame path must not directly delete a database.'); } },
		async fetch(url, options) {
			if (url === `${origin}/wp-json/oj-ml/v1/assignments/17`) {
				policyFetches += 1;
				assert.equal(options.credentials, 'same-origin');
				assert.equal(options.headers['X-WP-Nonce'], 'fixture-nonce');
				if (policyFailure) throw new Error('Synthetic policy load failure');
				return { ok: true, status: 200, json: async () => ({ assignment }) };
			}
			assert.equal(url, `${origin}/wp-json/oj-ml/v1/session/storage-context`);
			fetches += 1;
			sequence.push('verify-context');
			return {
				ok: true, status: 200,
				json: async () => ({ storage: {
					mode: 'shared', keyVersion: 1, scope, session: 'B'.repeat(24), key: 'C'.repeat(43),
					database: `ml-playgrounds-oj-v1-${scope}`,
				} }),
			};
		},
	};
	document.defaultView = window;
	const context = vm.createContext({ window, document, URL, AbortController, console, WeakSet, Promise });
	vm.runInContext(controllerSource, context, { filename: 'public-pc.js' });
	vm.runInContext(bootstrapSource, context, { filename: 'public-pc-bootstrap.js' });
	assert.equal(window.listenerCount('message'), 1, 'Bootstrap installs its dispatcher before controller initialization.');
	document.dispatch('DOMContentLoaded');
	await flush();
	assert.equal(await window.OjMlPublicPcReady(root), true, 'Both actual sources must initialize successfully.');
	assert.equal(fetches, 2);
	function message(type, overrides = {}, envelope = {}) {
		return window.dispatch('message', {
			origin, source: frameWindow, data: { type, version: 2, frameNonce, ...overrides }, ...envelope,
		});
	}
	message('oj-ml:ready');
	await flush();
	assert.equal(posts.filter((post) => post.type === 'oj-ml:configure').length, 1);
	if (!baseline) {
		assert.equal(policyFetches, 1, 'Assignment restrictions are fetched once during the frame handshake.');
		const expected = !policyFailure && assignment && String(assignment.id) === '17'
			&& Array.isArray(assignment.submissionPolicy?.allowedTaskTypes) && Array.isArray(assignment.submissionPolicy?.allowedAlgorithms)
			? { version: 1, allowedTaskTypes: assignment.submissionPolicy.allowedTaskTypes, allowedAlgorithms: assignment.submissionPolicy.allowedAlgorithms } : null;
		assert.deepEqual(JSON.parse(JSON.stringify(posts.find(post => post.type === 'oj-ml:configure').assignmentPolicy)), expected,
			'Only the correct assignment allowlists cross the bridge; failed/missing policy stays null, never unrestricted.');
	}
	assert.equal(frame.hidden, false);
	assert.equal(button.disabled, false);
	const beforeClickFetches = fetches;
	const [clicking] = button.dispatch('click', { type: 'click', target: button });
	const dialog = root.children.find((child) => child.tagName === 'DIALOG');
	if (dialog) {
		assert.equal(dialog.open, true);
		assert.equal(posts.filter((post) => post.type === 'oj-ml:storage-wipe-request').length, 0);
		const actions = dialog.children.find((child) => child.className === 'oj-ml-actions');
		const accept = actions.children.find((child) => child.className === 'oj-ml-wipe-dialog__confirm');
		assert.ok(accept, 'Use the real dialog confirmation button, not an internal controller method.');
		accept.dispatch('click', { type: 'click', target: accept });
	} else assert.ok(baseline, 'Current product code must require the visible DOM confirmation.');
	await flush();
	assert.equal(fetches, beforeClickFetches + 1, 'A fresh context check must precede the wipe request.');
	if (!baseline) assert.equal(policyFetches, 1, 'Local-data cleanup must not reload the assignment or workspace.');
	assert.equal(posts.filter((post) => post.type === 'oj-ml:storage-wipe-request').length, 1);
	assert.equal(posts.at(-1).frameNonce, frameNonce);
	assert.equal(statuses.some((entry) => entry.kind === 'success'), false, 'No success before a valid child ACK.');
	return {
		window, document, root, frame, status, statuses, button, panel, preferences, sequence,
		clicking, message, timers,
		get frameRemovals() { return frameRemovals; },
		get reloads() { return reloads; },
	};
}

async function assertSuccess(f, label) {
	f.message('oj-ml:storage-wiped');
	await flush();
	await f.clicking;
	assert.equal(f.status.dataset.kind, 'success', `${label}: one valid ACK must remain success after every registered listener settles.`);
	assert.match(f.status.textContent, /현재 계정의 로컬 실습 데이터를 삭제했습니다/);
	assert.equal(f.statuses.filter((entry) => entry.kind === 'success').length, 1);
	assert.equal(f.statuses.filter((entry) => entry.kind === 'error').length, 0);
	assert.equal(f.root.dataset.publicPcTerminal, '1');
	assert.equal(f.frameRemovals, 1);
	assert.equal(f.button.textContent, '로컬 데이터 삭제 완료');
	assert.equal(f.button.disabled, true);
	assert.equal(f.panel.dataset.busy, undefined);
	assert.equal(f.preferences.has(currentPreference), false);
	assert.equal(f.preferences.get(otherPreference), 'light');
	assert.equal(f.preferences.get('unrelated'), 'keep');
	assert.equal(f.reloads, 0);
	const terminalStatus = f.status.textContent;
	const terminalWrites = f.statuses.length;
	f.message('oj-ml:storage-wiped');
	f.message('oj-ml:storage-error', { code: 'OJ_STORAGE_DELETE_BLOCKED' });
	f.window.dispatch('focus');
	await flush();
	assert.equal(f.status.textContent, terminalStatus, 'Late duplicate ACK/error/focus cannot overwrite terminal success.');
	assert.equal(f.statuses.length, terminalWrites);
	assert.equal(f.frameRemovals, 1);
}

async function assertRejected(f, expected) {
	await flush();
	await f.clicking;
	assert.equal(f.root.dataset.publicPcTerminal, '1');
	assert.equal(f.status.dataset.kind, 'error');
	assert.match(f.status.textContent, expected);
	assert.equal(f.statuses.filter((entry) => entry.kind === 'error').length, 1);
	assert.equal(f.statuses.some((entry) => entry.kind === 'success'), false);
	assert.equal(f.preferences.get(currentPreference), 'dark');
	assert.equal(f.preferences.get(otherPreference), 'light');
	assert.equal(f.frameRemovals, 1);
	const firstError = f.status.textContent;
	f.message('oj-ml:storage-wiped');
	await flush();
	assert.equal(f.status.textContent, firstError, 'A late successful ACK must not replace the first terminal failure.');
	assert.equal(f.reloads, 0);
}

await assertSuccess(await fixture(), 'combined bootstrap/controller dispatch');

if (!baseline) {
	for (const options of [
		{ policyFailure: true },
		{ assignment: null },
		{ assignment: { id: 18, submissionPolicy: { allowedTaskTypes: ['classification'], allowedAlgorithms: ['knn'] } } },
		{ assignment: { id: 17, submissionPolicy: {} } },
		{ assignment: { id: 17, submissionPolicy: { allowedTaskTypes: ['classification'], allowedAlgorithms: [] } } },
	]) await assertSuccess(await fixture(options), 'policy configuration preserves explicit cleanup');
}

const rejected = await fixture();
rejected.message('oj-ml:storage-error', { code: 'OJ_STORAGE_DELETE_BLOCKED' });
await assertRejected(rejected, /다른 ML 실습 탭이 저장소를 사용 중/);

const wrongNonce = await fixture();
wrongNonce.message('oj-ml:storage-wiped', { frameNonce: 'b'.repeat(32) });
await assertRejected(wrongNonce, /실습 앱과의 보안 연결을 확인하지 못해/);

const stale = await fixture();
const beforeStale = stale.statuses.length;
stale.message('oj-ml:storage-wiped', {}, { source: {} });
stale.message('oj-ml:storage-wiped', {}, { origin: 'https://untrusted.example' });
stale.message('oj-ml:storage-wiped', { version: 1 });
await flush();
assert.equal(stale.statuses.length, beforeStale, 'Stale source, wrong origin and wrong protocol cannot settle the pending wipe.');
assert.equal(stale.root.dataset.publicPcTerminal, undefined);
assert.equal(stale.frame.isConnected, true);
assert.equal(stale.preferences.get(currentPreference), 'dark');
await assertSuccess(stale, 'valid ACK after rejected untrusted envelopes');

const revoked = await fixture();
revoked.message('oj-ml:storage-revoked');
await assertRejected(revoked, /실습 저장소 연결이 종료되었습니다/);

console.log('OJ ML combined public-PC dispatch regression passed (cleanup dispatch and assignment-policy handshake).');
