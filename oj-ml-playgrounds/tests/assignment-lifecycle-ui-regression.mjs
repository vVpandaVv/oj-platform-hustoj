import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../resources/js/addon.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../resources/css/addon.css', import.meta.url), 'utf8');

function extract(name) {
	const pattern = new RegExp(`\\tfunction ${name}\\([^\\n]*\\) \\{[\\s\\S]*?\\n\\t\\}`);
	const match = source.match(pattern);
	assert.ok(match, `${name} helper must exist.`);
	return match[0].trim();
}

function extractRange(startMarker, endMarker) {
	const start = source.indexOf(startMarker);
	const end = source.indexOf(endMarker, start);
	assert.ok(start >= 0 && end > start, `${startMarker} source range must exist.`);
	return source.slice(start, end).trim();
}

const helpers = [
	'escapeHtml',
	'statusTone',
	'formatDate',
	'studentAssignmentUrl',
	'studentResultUrl',
	'assignmentStatusLabel',
	'learnerAssignmentState',
	'hasSubmittedAttempt',
	'learnerAssignmentCardHtml',
	'consoleAssignmentActionsHtml',
	'assignmentPaginationHtml',
].map(extract).join('\n');

const ui = vm.runInNewContext(
	`(() => { ${helpers}; return { learnerAssignmentState, hasSubmittedAttempt, learnerAssignmentCardHtml, consoleAssignmentActionsHtml, assignmentPaginationHtml }; })()`,
);

const createRefreshGuard = vm.runInNewContext(
	`(() => { ${extract('createRefreshGuard')}; return createRefreshGuard; })()`,
);
function deferred() {
	let resolve;
	let reject;
	const promise = new Promise((resolvePromise, rejectPromise) => {
		resolve = resolvePromise;
		reject = rejectPromise;
	});
	return { promise, resolve, reject };
}
const busyStates = [];
const refreshGuard = createRefreshGuard((busy) => busyStates.push(busy));
let committedValue = null;
async function commitWhenCurrent(promise) {
	const ticket = refreshGuard.begin();
	try {
		const value = await promise;
		if (!refreshGuard.isCurrent(ticket)) return false;
		committedValue = value;
		return true;
	} finally {
		refreshGuard.finish();
	}
}
const slow = deferred();
const fast = deferred();
const slowCommit = commitWhenCurrent(slow.promise);
const fastCommit = commitWhenCurrent(fast.promise);
fast.resolve('new response');
assert.equal(await fastCommit, true);
assert.equal(committedValue, 'new response');
assert.equal(refreshGuard.isBusy(), true);
slow.resolve('stale response');
assert.equal(await slowCommit, false);
assert.equal(committedValue, 'new response');
assert.equal(refreshGuard.isBusy(), false);
const rejected = deferred();
const rejectedCommit = commitWhenCurrent(rejected.promise);
rejected.reject(new Error('network rejected'));
await assert.rejects(rejectedCommit, /network rejected/);
assert.equal(refreshGuard.isBusy(), false);
assert.deepEqual(busyStates, [true, true, true, false, true, false]);

let deleteRequest = null;
const createClientSource = extractRange('\tfunction createClient(root) {', '\n\tfunction publicPcTerminated');
const apiSandbox = {
	FormData: class FormDataStub {},
	window: {
		fetch: async (url, options) => {
			deleteRequest = { url, options };
			return {
				ok: true,
				json: async () => ({ data: { deleted: true } }),
			};
		},
	},
};
const createClient = vm.runInNewContext(
	`(() => { ${extract('joinUrl')}; ${extract('unwrap')}; ${createClientSource}; return createClient; })()`,
	apiSandbox,
);
const deleteResult = await createClient({
	dataset: { restRoot: '/wp-json/oj-ml/v1', restNonce: 'test-nonce' },
}).remove('console/assignments/9', { expectedRevision: 7 });
assert.equal(deleteResult.deleted, true);
assert.equal(deleteRequest.url, '/wp-json/oj-ml/v1/console/assignments/9');
assert.equal(deleteRequest.options.method, 'DELETE');
assert.equal(deleteRequest.options.headers['Content-Type'], 'application/json');
assert.equal(deleteRequest.options.headers['X-WP-Nonce'], 'test-nonce');
assert.deepEqual(JSON.parse(deleteRequest.options.body), { expectedRevision: 7 });

const assignmentPager = ui.assignmentPaginationHtml(250, 100, 2, 100);
assert.match(assignmentPager, /data-assignment-page="previous"/);
assert.match(assignmentPager, /data-assignment-page="next"/);
assert.match(assignmentPager, /2\/3 페이지/);
assert.equal(ui.assignmentPaginationHtml(100, 100, 1, 100), '');

const reportLoaderSource = extractRange(
	'\tasync function loadAllReportAssignments(api) {',
	'\n\tasync function loadAllTeachingContexts',
);
const loadAllReportAssignments = vm.runInNewContext(
	`(() => { ${extract('assignmentItems')}; ${reportLoaderSource}; return loadAllReportAssignments; })()`,
);
const reportCalls = [];
const loadedReports = await loadAllReportAssignments({
	get: async (path) => {
		reportCalls.push(path);
		return {
			total: 250,
			items: Array.from({ length: 250 }, (_, index) => ({ id: index + 1, title: `과제 ${index + 1}` })),
		};
	},
});
assert.equal(loadedReports.items.length, 250);
assert.equal(loadedReports.total, 250);
assert.equal(loadedReports.limited, false);
assert.deepEqual(reportCalls, ['console/report-assignment-options']);
const studentRoot = {
	dataset: {
		labUrl: '/lab/{assignment_id}',
		resultUrl: '/result/{attempt_id}',
	},
};

for (const status of ['scheduled', 'open', 'late_open', 'expired', 'closed', 'archived']) {
	assert.equal(ui.learnerAssignmentState({ studentStatus: status }), status);
}
assert.equal(
	ui.learnerAssignmentState({ status: 'archived', studentStatus: 'open' }),
	'archived',
);
assert.equal(ui.learnerAssignmentState({
	status: 'published',
	opensAt: '2999-01-01T00:00:00.000Z',
}), 'scheduled');
assert.equal(ui.learnerAssignmentState({
	status: 'published',
	dueAt: '2000-01-01T00:00:00.000Z',
	submissionPolicy: { allowLateSubmission: false },
}), 'expired');
assert.equal(ui.learnerAssignmentState({
	status: 'published',
	dueAt: '2000-01-01T00:00:00.000Z',
	submissionPolicy: { allowLateSubmission: true },
}), 'late_open');

assert.equal(ui.hasSubmittedAttempt({ latestAttempt: { id: 'attempt-1', receivedAt: '2026-09-01T00:00:00Z' } }), true);
assert.equal(ui.hasSubmittedAttempt({ latestAttempt: { id: 'attempt-1', receivedAt: null } }), false);
assert.equal(ui.hasSubmittedAttempt({ latestAttempt: null }), false);

const currentCard = ui.learnerAssignmentCardHtml(studentRoot, {
	id: 'assignment-1',
	title: '<script>alert(1)</script>',
	description: '설명',
	studentStatus: 'open',
}, false);
assert.match(currentCard, /실습 열기/);
assert.doesNotMatch(currentCard, /<script>/);
assert.match(currentCard, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);

const historyCard = ui.learnerAssignmentCardHtml(studentRoot, {
	id: 'assignment-1',
	title: '지난 실습',
	studentStatus: 'expired',
	latestAttempt: { id: 'attempt-1', receivedAt: '2026-09-01T00:00:00Z' },
}, true);
assert.match(historyCard, /제출 결과 보기/);
assert.doesNotMatch(historyCard, /실습 열기/);

const consoleRoot = { dataset: { consoleResultsUrl: '/console/{assignment_id}/results' } };
const draftActions = ui.consoleAssignmentActionsHtml(consoleRoot, { id: 'a1', revision: 2, status: 'draft' });
assert.match(draftActions, /data-edit-id="a1"/);
assert.match(draftActions, /data-publish-id="a1"/);
assert.match(draftActions, /data-delete-id="a1"/);
assert.match(draftActions, /data-delete-id="a1" data-revision="2"/);
assert.doesNotMatch(draftActions, /data-archive-id|>결과</);

const publishedActions = ui.consoleAssignmentActionsHtml(consoleRoot, { id: 'a2', revision: 3, status: 'published' });
assert.match(publishedActions, /data-edit-id="a2"/);
assert.match(publishedActions, /data-archive-id="a2"/);
assert.match(publishedActions, /보관\(학생 숨김\)/);
assert.match(publishedActions, />결과</);
assert.doesNotMatch(publishedActions, /data-publish-id|data-delete-id/);

const closedActions = ui.consoleAssignmentActionsHtml(consoleRoot, { id: 'a3', revision: 4, status: 'closed' });
assert.match(closedActions, /data-archive-id="a3"/);
assert.match(closedActions, /보관\(학생 숨김\)/);
assert.match(closedActions, />결과</);
assert.doesNotMatch(closedActions, /data-edit-id|data-publish-id|data-delete-id/);

const archivedActions = ui.consoleAssignmentActionsHtml(consoleRoot, { id: 'a4', revision: 5, status: 'archived' });
assert.match(archivedActions, />결과</);
assert.doesNotMatch(archivedActions, /data-edit-id|data-publish-id|data-delete-id|data-archive-id/);

assert.match(source, /var currentStates = \['scheduled', 'open', 'late_open'\]/);
assert.match(source, /var historyStates = \['expired', 'closed'\]/);
assert.doesNotMatch(source, /var historyStates = \[[^\]]*archived/);
assert.match(source, /historyStates\.indexOf\(learnerAssignmentState\(item\)\) !== -1 && hasSubmittedAttempt\(item\)/);
assert.match(source, /<details class="oj-ml-assignment-history"><summary>지난 실습·제출 결과/);
assert.match(source, /\['context', 'taskType', 'opensAt', 'maxRows', 'maxFeatures', 'allowLateSubmission', 'maxArtifactMiB'\]/);
assert.match(source, /setLockedFields\(published\)/);
assert.match(source, /field\('maxAttempts'\)\.min = published \? String\(Math\.max\(1, Number\(item\.maxAttempts\) \|\| 1\)\) : '1'/);
assert.match(source, /field\('maxAttempts'\)\.min = '1'/);
assert.match(source, /escapeHtml\(assignmentStatusLabel\(item\)\)/);
assert.match(source, /api\.remove\('console\/assignments\/'/);
assert.match(source, /await api\.remove\('console\/assignments\/' \+ encodeURIComponent\(button\.dataset\.deleteId\), \{\s*expectedRevision: Number\(button\.dataset\.revision\)\s*\}\);/);
assert.match(source, /confirmAction\(\{ title: '이 초안 과제를 삭제할까요\?'[^}]*destructive: true \}\)/);
assert.match(source, /confirmAction\(\{ title: '이 과제를 보관할까요\?'/);
assert.doesNotMatch(source, /window\.confirm\('/, 'Confirmations go through the platform sheet with a native fallback only.');
assert.match(source, /status: 'archived'/);

const managementAssignments = extractRange(
	'\tasync function renderConsoleAssignments(root, api) {',
	'\n\tfunction submissionFactRow',
);
const reportAssignments = extractRange(
	'\tasync function renderConsoleResults(root, api) {',
	'\n\tfunction runtimeCodeLabel',
);
assert.match(managementAssignments, /var requestedPage = assignmentPage/);
assert.match(managementAssignments, /api\.get\('console\/assignments\?per_page=' \+ assignmentPerPage \+ '&page=' \+ requestedPage \+ '&status=' \+ requestedView\)/);
assert.doesNotMatch(managementAssignments, /console\/report-assignments/);
assert.match(managementAssignments, /data-assignment-page/);
assert.match(managementAssignments, /if \(requestedPage > maxPage\) \{\s*assignmentPage = maxPage;\s*return await refresh\(\);/);
assert.match(managementAssignments, /!assignmentRefreshGuard\.isCurrent\(refreshToken\) \|\| assignmentPage !== requestedPage/);
assert.match(managementAssignments, /if \(assignmentRefreshGuard\.isBusy\(\)\) return/);
assert.match(managementAssignments, /finally \{\s*assignmentRefreshGuard\.finish\(\);\s*\}/);
assert.match(managementAssignments, /else \{\s*await api\.post\('console\/assignments', payload\);\s*assignmentPage = 1;\s*selectAssignmentView\('unarchived'\); clearAssignmentList\(\);\s*\}/);
assert.match(reportAssignments, /loadAllReportAssignments\(api\)/);
assert.doesNotMatch(reportAssignments, /api\.get\('console\/report-assignments/);
assert.doesNotMatch(reportAssignments, /api\.get\('console\/assignments\?per_page=100'\)/);
assert.match(reportAssignments, /var requestedAssignment = String\(select\.value \|\| ''\)/);
assert.match(reportAssignments, /var requestedPage = resultPage/);
assert.match(reportAssignments, /encodeURIComponent\(requestedAssignment\)/);
assert.match(reportAssignments, /String\(select\.value \|\| ''\) !== requestedAssignment \|\| resultPage !== requestedPage/);
assert.match(reportAssignments, /select\.disabled = busy/);
assert.match(reportAssignments, /if \(resultRefreshGuard\.isBusy\(\)\) return/);
assert.match(reportAssignments, /finally \{\s*resultRefreshGuard\.finish\(\);\s*\}/);
assert.match(reportLoaderSource, /api\.get\('console\/report-assignment-options'\)/);
assert.doesNotMatch(reportLoaderSource, /for \(var page|console\/report-assignments/);

const publishedStart = source.indexOf("if (editing && editing.status === 'published') {");
const publishedEnd = source.indexOf('\n\t\t\t\tvar selectedContext', publishedStart);
assert.ok(publishedStart >= 0 && publishedEnd > publishedStart, 'Published safe-edit branch must exist.');
const publishedPatch = source.slice(publishedStart, publishedEnd);
for (const field of ['expectedRevision', 'title', 'description', 'dueAt', 'maxAttempts']) {
	assert.match(publishedPatch, new RegExp(`\\b${field}\\b`));
}
assert.doesNotMatch(publishedPatch, /\b(?:contextType|contextId|taskType|opensAt|maxRows|maxFeatures|policy)\b/);

assert.match(css, /\.oj-ml-app button:disabled\s*\{[^}]*cursor:\s*not-allowed;/s);
assert.match(css, /\.oj-ml-app (?:input|textarea|select):disabled[\s\S]*cursor:\s*not-allowed;/);

console.log('OJ ML assignment lifecycle UI regression passed.');
