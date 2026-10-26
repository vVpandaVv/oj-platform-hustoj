import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const scriptPath = path.join(root, 'resources', 'js', 'report-dashboard.js');
const stylePath = path.join(root, 'resources', 'css', 'report-dashboard.css');
const shellStylePath = path.join(root, 'resources', 'css', 'admin-shell.css');
const handlerPath = path.join(root, 'src', 'Platform', 'Report_Dashboard_Page_Handler.php');
const manifestPath = path.join(root, 'src', 'Platform', 'Addon_Manifest.php');
const [source, style, shellStyle, handler, manifest] = await Promise.all([
  readFile(scriptPath, 'utf8'), readFile(stylePath, 'utf8'), readFile(shellStylePath, 'utf8'),
  readFile(handlerPath, 'utf8'), readFile(manifestPath, 'utf8'),
]);

const window = {};
const document = { getElementById: () => null };
vm.runInNewContext(source, { window, document, URLSearchParams, Date, Error, Set, Intl, console }, { filename: scriptPath });
const adapter = window.OJAIReportDashboard;
assert.ok(adapter, 'The pure report dashboard adapter must be exported without a DOM root.');

assert.equal(adapter.addInclusiveDay('2026-08-31'), '2026-09-01');
assert.equal(adapter.addInclusiveDay('2028-02-29'), '2028-03-01');
assert.throws(() => adapter.addInclusiveDay('2026-02-30'), /날짜/);

const context = { type: 'class', id: 'class-a', revision: 'context-r7' };
const filteredQuery = adapter.buildReportQuery(context, {
  periodFrom: '2026-08-01', periodThrough: '2026-08-31',
  assignmentStates: ['open', 'closed'], submissionStates: ['official'],
}, 'cursor-a', 25);
assert.equal(
  filteredQuery,
  'context_type=class&context_id=class-a&context_revision=context-r7&period_from=2026-08-01&period_to=2026-09-01&assignment_states=open%2Cclosed&submission_states=official&cursor=cursor-a&limit=25'
);
assert.equal(
  adapter.buildReportQuery(context),
  'context_type=class&context_id=class-a&context_revision=context-r7&limit=25'
);
assert.throws(() => adapter.buildReportQuery(context, { periodFrom: '2026-08-01' }), /모두/);
assert.throws(() => adapter.buildReportQuery(context, { periodFrom: '2026-09-01', periodThrough: '2026-08-01' }), /기간/);
assert.equal(adapter.formatRatio({ numerator: 18, denominator: 24, value: '75.0' }), '18 / 24 (75.0%)');
assert.equal(adapter.formatAverage({ value: null, n: 4, suppressed: true }), '표시 안 함 (4명)');
assert.equal(adapter.formatAverage({ value: '82.5', n: 9, suppressed: false }), '82.5% (9명)');

// A worksheet with exactly one distribution should skip the "배포 목록" list
// screen; soleAssignmentFrom() is the pure decision behind that skip.
// (Compared via JSON, not assert.deepEqual: the adapter's object was built
// inside a separate vm realm, so its Object.prototype identity differs from
// this file's -- deepEqual/deepStrictEqual would fail on that alone even
// when every own property matches.)
assert.equal(
  JSON.stringify(adapter.soleAssignmentFrom([{ id: 'assignment-a', title: '1차 배포' }], false)),
  JSON.stringify({ id: 'assignment-a', title: '1차 배포' }),
  'Exactly one distribution with no further pages must resolve to that sole distribution.'
);
assert.equal(
  adapter.soleAssignmentFrom([{ id: 'assignment-a', title: '' }], false).title,
  '배포',
  'A blank distribution title must fall back to a safe default.'
);
assert.equal(
  adapter.soleAssignmentFrom([{ id: 'assignment-a', title: 'X' }], true), null,
  'A sole item on the current page with more pages behind it must not be treated as the only distribution.'
);
assert.equal(
  adapter.soleAssignmentFrom([{ id: 'a', title: 'A' }, { id: 'b', title: 'B' }], false), null,
  'Two or more distributions must keep the ordinary list screen (no skip).'
);
assert.equal(adapter.soleAssignmentFrom([], false), null, 'Zero distributions must not resolve to a sole distribution.');
assert.equal(
  adapter.soleAssignmentFrom([{ title: 'no id' }], false), null,
  'A distribution missing its id must not be treated as resolvable.'
);

// worksheetTrailSteps() must keep today's non-skip breadcrumb math exactly
// as before, and only special-case the skip a worksheet with one
// distribution takes (depth jumps 0 -> 2 in a single real history entry).
const trailSteps = (depth, skipped) => JSON.stringify(adapter.worksheetTrailSteps(depth, skipped));
assert.equal(trailSteps(0, false), JSON.stringify({ root: 0, worksheet: null, assignment: null, student: null }));
assert.equal(trailSteps(1, false), JSON.stringify({ root: 1, worksheet: 0, assignment: null, student: null }));
assert.equal(trailSteps(2, false), JSON.stringify({ root: 2, worksheet: 1, assignment: 0, student: null }));
assert.equal(trailSteps(3, false), JSON.stringify({ root: 3, worksheet: 2, assignment: 1, student: 0 }));
assert.equal(
  trailSteps(2, true), JSON.stringify({ root: 1, worksheet: null, assignment: 0, student: null }),
  'Skipping the assignments screen must collapse the root crumb to a single real step back and drop the now-nonexistent 배포 목록 crumb.'
);
assert.equal(
  trailSteps(3, true), JSON.stringify({ root: 2, worksheet: null, assignment: 1, student: 0 }),
  'Drilling into a student after a skipped assignments screen must still land the root crumb on a real history entry.'
);

for (const marker of [
  "allPages(request, '/contexts?limit=100')", "return `/reports?${query.toString()}`", '/reports/worksheets/',
  "query.set('assignment_id'", "cache: 'no-store'", "headers['X-WP-Nonce']", 'ReportRequestError',
  "status === 401 || status === 403", "loseAccess('이 보고서를 볼 수 없습니다.'", 'clearRendered()',
  'window.history.pushState', "window.addEventListener('popstate'", 'state.pageCursors.push(cursor)',
  'window.scrollTo(0, state.scrollY)', 'replaceChildren', 'textContent',
  // Worksheet-lens single-distribution skip (G5-IMP-01-S05): the "배포 목록"
  // screen is skipped only when exactly one distribution resolves, and the
  // ordinary 2+ distribution list screen (renderAssignments) is untouched.
  'Number(item.progress?.assignment_count) === 1', 'resolveSoleAssignment', 'soleAssignmentFrom(items, hasMore)',
  'assignmentSkipped: true', 'assignmentSkipped: false',
  'next.assignmentSkipped = Boolean(value.assignmentSkipped)',
  "state.view === 'assignments' ? renderAssignments()",
]) {
  assert.ok(source.includes(marker), `Report dashboard script marker is missing: ${marker}`);
}
for (const forbidden of ['.innerHTML', 'insertAdjacentHTML', 'localStorage', 'sessionStorage', 'eval(']) {
  assert.ok(!source.includes(forbidden), `Report dashboard must not use unsafe or stale storage API: ${forbidden}`);
}

for (const marker of [
  '#oj-ai-report-app', '.oj-ai-report__filters', '.oj-ai-report__metrics', '.oj-ai-report__table-link',
  '.oj-ai-report__student-facts', '@media (max-width: 920px)', '@media (max-width: 680px)',
  'content: attr(data-label)', 'overflow-wrap: anywhere',
]) {
  assert.ok(style.includes(marker), `Report responsive style marker is missing: ${marker}`);
}
for (const marker of ['.oj-ai-dashboard-admin', '.oj-ai-icon']) {
  assert.ok(shellStyle.includes(marker), `Shared Console shell style marker is missing: ${marker}`);
}
assert.ok(!/linear-gradient|radial-gradient/i.test(style), 'The report dashboard must not use decorative gradients.');
assert.ok(!/font-size:\s*(?:clamp|min|max)\(/i.test(style), 'Report font size must not scale with viewport width.');

for (const marker of ['id="oj-ai-report-app"', 'data-role="breadcrumbs"', 'data-role="metrics"', 'Addon_Capabilities::VIEW_REPORTS']) {
  assert.ok(handler.includes(marker), `Report Page handler marker is missing: ${marker}`);
}
assert.ok(!handler.includes('Console_Local_Navigation::markup'), 'Report Page must not duplicate the OJ Console menu inside page content.');
for (const marker of ['활동지 평가', '평가 프로젝트', '평가 기준', 'self::MENU_WORKSPACE', 'self::CONSOLE_PROJECTS', 'self::CONSOLE_CRITERIA', 'self::CONSOLE_SETTINGS', "array( 'settings', '설정', self::CONSOLE_SETTINGS, 70 )"]) {
  assert.ok(manifest.includes(marker), `Direct OJ Console menu marker is missing: ${marker}`);
}
for (const asset of ['resources/css/report-dashboard.css', 'resources/js/report-dashboard.js', 'Report_Dashboard_Page_Handler']) {
  assert.ok(manifest.includes(asset), `Report Console registration is missing: ${asset}`);
}

console.log('G5-IMP-01-S04 UI contract passed: inclusive dates, shared Q20/Q21 filters, safe DOM/history, direct Console menus, responsive report styling, and the worksheet-lens single-distribution skip.');
