import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { access, readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { validateUiPrototype } from './validate-ui-prototype.mjs';
import { missingPhpFragments } from './php-source-fragments.mjs';

function checkPhpFragments(label, source, fragments) {
  for (const fragment of missingPhpFragments(source, fragments)) {
    fail(`${label}: missing PHP fragment ${fragment}`);
  }
}

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const docsRoot = path.join(root, 'docs', 'ai-assessment');
const designFiles = [
  'index.html',
  'design-procedure.html',
  'implementation-roadmap.html',
  'redesign.html',
  'code-consistency.html',
  'a-d-001-boundary-dependencies.html',
  'a-d-002-screen-entrypoints.html',
  'a-d-003-access-settings.html',
  'a-d-004-data-storage.html',
  'a-d-005-worksheet-plate.html',
  'a-d-006-ai-evaluation-flow.html',
  'a-d-007-user-workflow-risks.html',
  'a-d-008-module-data-ownership.html',
  'a-d-009-baseline-gap-freeze.html',
  'a-ds-00-scope-baseline.html',
  'a-ds-01-ui-system.html',
  'a-ds-02-addon-lifecycle.html',
  'a-ds-02-addon-api-profile.html',
  'a-ds-02-am-01-onready-adoption.html',
  'a-ds-03-access-policy.html',
  'a-ds-04-settings-ai-connection.html',
  'a-ds-05-addon-database.html',
  'a-ds-06-platform-ledger-connector.html',
  'a-ds-07-ai-job-execution.html',
  'a-ds-08-route-rest-command.html',
  'a-ds-09-worksheet-authoring-version.html',
  'a-ds-10-worksheet-self-evaluation.html',
  'a-ds-11-assignment-student-submission.html',
  'a-ds-12-submission-ai-assessment.html',
  'a-ds-13-teacher-review-feedback-release.html',
  'a-ds-14-dashboard-report-export.html',
  'a-ds-15-install-upgrade-migration-operations.html',
  'a-ds-16-cross-validation-freeze.html',
  'platform-api-implementation-audit.html',
  'g0-c28-i06-release-gate.html',
  'g1-addon-foundation-implementation.html',
  'g1-composition-root-implementation.html',
  'g1-database-foundation-implementation.html',
  'g1-database-infrastructure-implementation.html',
  'g1-activation-database-integration.html',
  'g1-runtime-schema-readiness.html',
  'g1-common-access-policy.html',
  'g1-common-rest-envelope.html',
  'g1-context-list-q01.html',
  'g2-worksheet-authoring-vertical-slice.html',
  'g2-imp-01-corrective-revalidation.html',
  'g2-imp-02-s01-plate-runtime-entrypoint.html',
  'g2-imp-02-s02-c02-contract.html',
  'g2-imp-02-s03-document-profile.html',
  'g2-imp-02-s04-revision-projection.html',
  'g2-imp-02-s05-revision-transaction.html',
  'g2-imp-02-s06-operation-transaction.html',
  'g2-imp-02-s07-rest-boundary.html',
  'g2-imp-02-s08-workbench-plate.html',
  'g2-imp-02-s09-save-conflict-recovery.html',
  'g2-imp-02-s10-regression.html',
  'g2-imp-02-s11-visual-verification.html',
  'g2-imp-02-s12-freeze.html',
  'g2-imp-03-s01-private-asset-staging.html',
  'g2-imp-03-s02-private-asset-access.html',
  'g2-imp-03-s03-template-import-contract.html',
  'g2-imp-03-s04-worksheet-duplicate.html',
  'g2-imp-03-s05-workbench-integration.html',
  'g2-imp-04-s01-validation.html',
  'g2-imp-04-s02-preview.html',
  'g2-imp-04-s03-projection-safety.html',
  'g2-imp-04-s04-ui-e2e-freeze.html',
  'g2-imp-05-s01-task-instruction.html',
  'g2-imp-05-s02-p04-input.html',
  'g2-imp-05-s03-c10-enqueue.html',
  'g2-imp-05-s04-worker-normalizer.html',
  'g2-imp-05-s05-query-projections.html',
  'g2-imp-05-s06-retry-cancel.html',
  'g2-imp-05-s07-review.html',
  'g5-imp-04-assessment-configuration.html',
  'g3-imp-01-assignment-create.html',
  'g3-imp-02-assignment-lifecycle.html',
  'g3-imp-03-student-assignment.html',
  'g3-imp-04-s01-response-renderer.html',
  'g3-imp-04-s02-own-submission-query.html',
  'g3-imp-04-s03-autosave-revision.html',
  'g3-imp-04-s04-offline-conflict-recovery.html',
  'g3-imp-04-s05-responsive-accessibility-freeze.html',
  'g3-imp-05-s01-private-attachment-staging.html',
  'g3-imp-05-s02-staged-attachment-removal.html',
  'g3-imp-05-s03-attachment-scan-boundary.html',
  'g3-imp-05-s04-expiry-cleanup-recovery.html',
  'g3-imp-06-s01-official-submit-receipt.html',
  'g3-imp-06-s02-teacher-submission-projections.html',
  'g3-imp-06-s03-teacher-reopen-lock.html',
  'g3-imp-06-s04-submission-screen-audit.html',
  'g3-imp-06-s05-concurrency-freeze.html',
  'g3-imp-07-s01-private-zip-staging.html',
  'g3-imp-07-s02-async-scan-preview.html',
  'g3-imp-07-s03-explicit-mapping.html',
  'g3-imp-07-s04-confirm-cancel.html',
  'g3-imp-07-s05-integration-freeze.html',
  'g3-imp-08-s01-teacher-submission-pdf.html',
  'g3-imp-08-s02-learner-worksheet-pdf.html',
  'g3-imp-08-s03-learner-submission-pdf.html',
  'g3-imp-08-s04-pdf-integration-freeze.html',
  'g4-imp-01-s01-score-task-instruction.html',
  'g4-imp-01-s02-official-input-projection.html',
  'g4-imp-01-s03-single-evaluation-enqueue.html',
  'g4-imp-01-s04-assignment-evaluation-enqueue.html',
  'g4-imp-01-s05-idempotency-authorization-freeze.html',
  'g4-imp-02-s01-evaluation-queue-lease.html',
  'g4-imp-02-s02-gateway-output-result.html',
  'g4-imp-02-r01-submission-external-ai-consent.html',
  'g4-imp-02-s03-submission-evaluation-query.html',
  'g4-imp-02-s04-submission-evaluation-control.html',
  'g4-imp-02-s05-submission-evaluation-integration-freeze.html',
  'g4-imp-03-s01-submission-evaluation-review-append.html',
  'g4-imp-03-s02-official-pointer-cas.html',
  'g4-imp-03-s03-score-evidence-review-ui.html',
  'g4-imp-03-s04-stale-source-integration-freeze.html',
  'g4-imp-04-s01-feedback-draft.html',
  'g4-imp-04-s02-feedback-review.html',
  'g4-imp-04-s03-feedback-approval.html',
  'g4-imp-04-s04-feedback-workbench-integration.html',
  'g4-imp-05-s01-student-note-draft.html',
  'g4-imp-05-s02-student-note-query.html',
  'g4-imp-05-s03-student-note-review-approval.html',
  'g4-imp-05-s04-student-note-report-inclusion.html',
  'g4-imp-05-s05-student-note-integration-freeze.html',
  'g4-imp-06-s01-student-result-release.html',
  'g4-imp-06-s02-student-result-query.html',
  'g2-imp-05-s08-evaluation-workbench.html',
  'g2-imp-05-s09-integration-freeze.html',
  'g2-imp-05-r01-external-ai-consent.html',
  'g2-imp-06-s01-approval-policy.html',
  'g2-imp-06-s02-approval-command.html',
  'g2-imp-06-s03-publication.html',
  'g2-imp-06-s04-lifecycle.html',
  'g2-imp-06-s05-ui-freeze.html',
  'g2-imp-07-s01-print-projection.html',
  'g4-imp-06-s03-student-result-withdrawal.html',
  'g4-imp-06-s04-student-result-correction.html',
  'g4-imp-06-s05-student-result-ui-integration.html',
  'g4-imp-07-s01-result-print-projection.html',
  'g4-imp-07-s02-result-pdf-job.html',
  'g4-imp-07-s03-result-pdf-access-freeze.html',
  'g4-imp-07-s04-result-pdf-ui-integration.html',
  'g5-imp-01-s01-worksheet-report-list.html',
  'g5-imp-01-s02-worksheet-report-detail.html',
  'g5-imp-01-s03-report-filter-denominator.html',
  'g5-imp-01-s04-report-dashboard.html',
  'g5-imp-02-s01-report-snapshot-create.html',
  'g5-imp-02-s02-report-snapshot-query.html',
  'g5-imp-02-s03-report-source-manifest.html',
  'g5-imp-02-s04-report-snapshot-integration-freeze.html',
  'g5-imp-03-s01-report-export-enqueue.html',
  'g5-imp-03-s02-submission-bundle-enqueue.html',
  'g5-imp-03-s03-report-json-package-enqueue.html',
  'g5-imp-03-s04-report-export-job-download.html',
  'g5-imp-03-s05-report-export-control.html',
  'g5-imp-03-s06-report-export-integration-freeze.html',
  'g5-imp-05-s01-bridge-schema-queue-diagnostics.html',
  'g5-imp-05-s02-ai-routing-diagnostics.html',
  'g5-imp-05-s03-storage-secret-diagnostics.html',
  'g5-imp-05-s04-operations-ui-regression.html',
  'g5-imp-06-s01-fresh-install.html',
  'g5-imp-06-s02-sequential-upgrade.html',
  'g5-imp-06-s03-failure-restart.html',
  'g5-imp-06-s04-forward-repair.html',
  'g5-imp-06-s05-data-preservation.html',
  'g5-imp-07-s01-legacy-migration-preview.html',
  'g5-imp-07-s02-legacy-migration-approval.html',
  'g5-imp-07-s03-d3-d-owner-migration.html',
  'g2-imp-07-s02-pdf-job-artifact.html',
  'g2-imp-07-s03-worksheet-package.html',
  'g2-imp-07-s04-integration-freeze.html',
];
const findingCounts = new Map([
  ['a-d-001-boundary-dependencies.html', 8],
  ['a-d-002-screen-entrypoints.html', 14],
  ['a-d-003-access-settings.html', 14],
  ['a-d-004-data-storage.html', 16],
  ['a-d-005-worksheet-plate.html', 20],
  ['a-d-006-ai-evaluation-flow.html', 20],
  ['a-d-007-user-workflow-risks.html', 24],
  ['a-d-008-module-data-ownership.html', 15],
]);
const expectedSeverity = { Critical: 36, High: 79, Medium: 16 };
const voidElements = new Set([
  'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta',
  'param', 'source', 'track', 'wbr',
]);
const errors = [];
const platformDriftWarnings = [];
const allowPlatformDrift = process.argv.includes('--allow-platform-drift');
const documents = new Map();
const findingIds = new Set();
const severityTotals = { Critical: 0, High: 0, Medium: 0 };

function fail(message) {
  errors.push(message);
}

function reportPlatformDrift(message) {
  if (allowPlatformDrift) {
    platformDriftWarnings.push(message);
    return;
  }
  fail(message);
}

function relativeLabel(absolutePath) {
  return path.relative(root, absolutePath).replaceAll(path.sep, '/');
}

function collectIds(content, file) {
  const ids = new Set();
  for (const match of content.matchAll(/\sid=(['"])(.*?)\1/gi)) {
    const id = match[2];
    if (ids.has(id)) {
      fail(`${file}: duplicate id "${id}"`);
    }
    ids.add(id);
  }
  return ids;
}

async function collectPhpFiles(directory) {
  const files = [];
  const entries = await readdir(directory, { withFileTypes: true });
  for (const entry of entries) {
    const absolutePath = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...await collectPhpFiles(absolutePath));
    } else if (entry.isFile() && entry.name.endsWith('.php')) {
      files.push(absolutePath);
    }
  }
  return files;
}

function checkBalancedTags(content, file) {
  const stack = [];
  const source = content.replace(/<!--[\s\S]*?-->/g, '');
  const tagPattern = /<\/?([a-z][a-z0-9-]*)\b[^>]*>/gi;

  for (const match of source.matchAll(tagPattern)) {
    const token = match[0];
    const name = match[1].toLowerCase();
    if (voidElements.has(name) || token.endsWith('/>')) {
      continue;
    }
    if (!token.startsWith('</')) {
      stack.push(name);
      continue;
    }
    const opened = stack.pop();
    if (opened !== name) {
      fail(`${file}: closing </${name}> does not match <${opened ?? 'none'}>`);
      return;
    }
  }

  if (stack.length > 0) {
    fail(`${file}: unclosed tags: ${stack.join(', ')}`);
  }
}

async function readUtf8(relativePath) {
  const absolutePath = path.join(docsRoot, relativePath);
  const bytes = await readFile(absolutePath);
  if (bytes.length >= 3 && bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf) {
    fail(`${relativePath}: UTF-8 BOM is not allowed`);
  }

  let content;
  try {
    content = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch (error) {
    fail(`${relativePath}: invalid UTF-8 (${error.message})`);
    content = bytes.toString('utf8');
  }
  if (content.includes('\ufffd')) {
    fail(`${relativePath}: replacement character U+FFFD found`);
  }
  return { absolutePath, content };
}

async function checkLink(sourceFile, href) {
  if (!href || /^(?:[a-z]+:|\/\/)/i.test(href)) {
    return;
  }

  const [rawPath, rawFragment = ''] = href.split('#', 2);
  const pathPart = rawPath.split('?', 1)[0];
  let targetPath = sourceFile;
  if (pathPart) {
    try {
      targetPath = path.resolve(path.dirname(sourceFile), decodeURI(pathPart));
    } catch {
      fail(`${relativeLabel(sourceFile)}: invalid href encoding "${href}"`);
      return;
    }
  }

  try {
    await access(targetPath);
  } catch {
    fail(`${relativeLabel(sourceFile)}: missing local link target "${href}"`);
    return;
  }

  if (!rawFragment || path.extname(targetPath).toLowerCase() !== '.html') {
    return;
  }

  let target = documents.get(targetPath);
  if (!target) {
    const content = await readFile(targetPath, 'utf8');
    target = { ids: collectIds(content, relativeLabel(targetPath)) };
    documents.set(targetPath, target);
  }
  let fragment;
  try {
    fragment = decodeURIComponent(rawFragment);
  } catch {
    fail(`${relativeLabel(sourceFile)}: invalid fragment encoding "${href}"`);
    return;
  }
  if (!target.ids.has(fragment)) {
    fail(`${relativeLabel(sourceFile)}: missing fragment "${href}"`);
  }
}

for (const file of designFiles) {
  const { absolutePath, content } = await readUtf8(file);
  const ids = collectIds(content, file);
  documents.set(absolutePath, { content, ids });

  if (!/^<!doctype html>/i.test(content)) fail(`${file}: missing HTML doctype`);
  if (!/<html\b[^>]*\blang=['"]ko['"]/i.test(content)) fail(`${file}: missing lang="ko"`);
  if (!/<meta\b[^>]*\bcharset=['"]?utf-8['"]?/i.test(content)) fail(`${file}: missing UTF-8 charset`);
  if (!/<link\b[^>]*\brel=['"]stylesheet['"][^>]*\bhref=['"]styles\.css['"]/i.test(content)) {
    fail(`${file}: missing shared styles.css link`);
  }

  const currentCount = (content.match(/\baria-current=['"]page['"]/gi) ?? []).length;
  if (currentCount !== 1) fail(`${file}: expected one aria-current page link, found ${currentCount}`);

  const documentNavigation = content.match(/<div class=['"]nav-group['"]>[\s\S]*?<\/div>/i)?.[0] ?? '';
  const scopeLinkCount = (documentNavigation.match(/href=['"]a-ds-00-scope-baseline\.html['"]/gi) ?? []).length;
  if (scopeLinkCount !== 1) fail(`${file}: expected one A-DS-00 document navigation link, found ${scopeLinkCount}`);

  const uiLinkCount = (documentNavigation.match(/href=['"]a-ds-01-ui-system\.html['"]/gi) ?? []).length;
  if (uiLinkCount !== 1) fail(`${file}: expected one A-DS-01 document navigation link, found ${uiLinkCount}`);

  const lifecycleLinkCount = (documentNavigation.match(/href=['"]a-ds-02-addon-lifecycle\.html['"]/gi) ?? []).length;
  if (lifecycleLinkCount !== 1) fail(`${file}: expected one A-DS-02 document navigation link, found ${lifecycleLinkCount}`);

  const apiProfileLinkCount = (documentNavigation.match(/href=['"]a-ds-02-addon-api-profile\.html['"]/gi) ?? []).length;
  if (apiProfileLinkCount !== 1) fail(`${file}: expected one A-DS-02 API profile navigation link, found ${apiProfileLinkCount}`);

  const amendmentLinkCount = (documentNavigation.match(/href=['"]a-ds-02-am-01-onready-adoption\.html['"]/gi) ?? []).length;
  if (amendmentLinkCount !== 1) fail(`${file}: expected one A-DS-02 AM-01 navigation link, found ${amendmentLinkCount}`);

  const accessPolicyLinkCount = (documentNavigation.match(/href=['"]a-ds-03-access-policy\.html['"]/gi) ?? []).length;
  if (accessPolicyLinkCount !== 1) fail(`${file}: expected one A-DS-03 access policy navigation link, found ${accessPolicyLinkCount}`);

  const settingsLinkCount = (documentNavigation.match(/href=['"]a-ds-04-settings-ai-connection\.html['"]/gi) ?? []).length;
  if (settingsLinkCount !== 1) fail(`${file}: expected one A-DS-04 settings navigation link, found ${settingsLinkCount}`);

  const databaseLinkCount = (documentNavigation.match(/href=['"]a-ds-05-addon-database\.html['"]/gi) ?? []).length;
  if (databaseLinkCount !== 1) fail(`${file}: expected one A-DS-05 database navigation link, found ${databaseLinkCount}`);

  const platformLedgerLinkCount = (documentNavigation.match(/href=['"]a-ds-06-platform-ledger-connector\.html['"]/gi) ?? []).length;
  if (platformLedgerLinkCount !== 1) fail(`${file}: expected one A-DS-06 platform ledger navigation link, found ${platformLedgerLinkCount}`);

  const aiJobLinkCount = (documentNavigation.match(/href=['"]a-ds-07-ai-job-execution\.html['"]/gi) ?? []).length;
  if (aiJobLinkCount !== 1) fail(`${file}: expected one A-DS-07 AI job navigation link, found ${aiJobLinkCount}`);

  const routeRestLinkCount = (documentNavigation.match(/href=['"]a-ds-08-route-rest-command\.html['"]/gi) ?? []).length;
  if (routeRestLinkCount !== 1) fail(file + ': expected one A-DS-08 route/REST navigation link, found ' + routeRestLinkCount);

  const worksheetAuthoringLinkCount = (documentNavigation.match(/href=['"]a-ds-09-worksheet-authoring-version\.html['"]/gi) ?? []).length;
  if (worksheetAuthoringLinkCount !== 1) fail(file + ': expected one A-DS-09 worksheet authoring navigation link, found ' + worksheetAuthoringLinkCount);

  const worksheetEvaluationLinkCount = (documentNavigation.match(/href=['"]a-ds-10-worksheet-self-evaluation\.html['"]/gi) ?? []).length;
  if (worksheetEvaluationLinkCount !== 1) fail(file + ': expected one A-DS-10 worksheet evaluation navigation link, found ' + worksheetEvaluationLinkCount);

  const studentSubmissionLinkCount = (documentNavigation.match(/href=['"]a-ds-11-assignment-student-submission\.html['"]/gi) ?? []).length;
  if (studentSubmissionLinkCount !== 1) fail(file + ': expected one A-DS-11 student submission navigation link, found ' + studentSubmissionLinkCount);

  const submissionEvaluationLinkCount = (documentNavigation.match(/href=['"]a-ds-12-submission-ai-assessment\.html['"]/gi) ?? []).length;
  if (submissionEvaluationLinkCount !== 1) fail(file + ': expected one A-DS-12 submission assessment navigation link, found ' + submissionEvaluationLinkCount);

  const reviewFeedbackReleaseLinkCount = (documentNavigation.match(/href=['"]a-ds-13-teacher-review-feedback-release\.html['"]/gi) ?? []).length;
  if (reviewFeedbackReleaseLinkCount !== 1) fail(file + ': expected one A-DS-13 review/feedback/release navigation link, found ' + reviewFeedbackReleaseLinkCount);

  const dashboardReportExportLinkCount = (documentNavigation.match(/href=['"]a-ds-14-dashboard-report-export\.html['"]/gi) ?? []).length;
  if (dashboardReportExportLinkCount !== 1) fail(file + ': expected one A-DS-14 dashboard/report/export navigation link, found ' + dashboardReportExportLinkCount);

  const installOperationsLinkCount = (documentNavigation.match(/href=['"]a-ds-15-install-upgrade-migration-operations\.html['"]/gi) ?? []).length;
  if (installOperationsLinkCount !== 1) fail(file + ': expected one A-DS-15 install/operations navigation link, found ' + installOperationsLinkCount);

  const crossValidationLinkCount = (documentNavigation.match(/href=['"]a-ds-16-cross-validation-freeze\.html['"]/gi) ?? []).length;
  if (crossValidationLinkCount !== 1) fail(file + ': expected one A-DS-16 cross-validation navigation link, found ' + crossValidationLinkCount);

  const apiAuditLinkCount = (documentNavigation.match(/href=['"]platform-api-implementation-audit\.html['"]/gi) ?? []).length;
  if (apiAuditLinkCount !== 1) fail(`${file}: expected one platform API audit navigation link, found ${apiAuditLinkCount}`);

  const g0ReleaseLinkCount = (documentNavigation.match(/href=['"]g0-c28-i06-release-gate\.html['"]/gi) ?? []).length;
  if (g0ReleaseLinkCount !== 1) fail(`${file}: expected one G0 release gate navigation link, found ${g0ReleaseLinkCount}`);

  checkBalancedTags(content, file);
}

await access(path.join(docsRoot, 'styles.css')).catch(() => fail('docs/ai-assessment/styles.css: missing'));

for (const error of await validateUiPrototype(docsRoot)) fail(error);

for (const file of designFiles) {
  const absolutePath = path.join(docsRoot, file);
  const { content } = documents.get(absolutePath);
  const hrefs = [...content.matchAll(/\bhref=(['"])(.*?)\1/gi)].map((match) => match[2]);
  for (const href of hrefs) {
    await checkLink(absolutePath, href);
  }
}

for (const [file, expectedCount] of findingCounts) {
  const { content } = documents.get(path.join(docsRoot, file));
  let count = 0;
  for (const rowMatch of content.matchAll(/<tr\b[^>]*>[\s\S]*?<\/tr>/gi)) {
    const row = rowMatch[0];
    const idMatch = row.match(/<td\b[^>]*>\s*<code>\s*(A-?D\d{3}-[A-Z]\d{2})\s*<\/code>\s*<\/td>/i);
    if (!idMatch) continue;

    count += 1;
    const id = idMatch[1];
    if (findingIds.has(id)) fail(`${file}: duplicate finding id ${id}`);
    findingIds.add(id);

    const severities = [...row.matchAll(/>\s*(Critical|High|Medium)\s*</g)].map((match) => match[1]);
    if (severities.length !== 1) {
      fail(`${id}: expected one severity, found ${severities.length}`);
    } else {
      severityTotals[severities[0]] += 1;
    }

    const owners = [...row.matchAll(/A-DS-(\d{2})/g)].map((match) => Number(match[1]));
    if (owners.length === 0) fail(`${id}: no A-DS owner`);
    for (const owner of owners) {
      if (owner < 0 || owner > 16) fail(`${id}: invalid A-DS owner ${owner}`);
    }
  }

  if (count !== expectedCount) fail(`${file}: expected ${expectedCount} findings, found ${count}`);
}

if (findingIds.size !== 131) fail(`expected 131 unique findings, found ${findingIds.size}`);
for (const [severity, expected] of Object.entries(expectedSeverity)) {
  if (severityTotals[severity] !== expected) {
    fail(`${severity}: expected ${expected}, found ${severityTotals[severity]}`);
  }
}

const procedure = documents.get(path.join(docsRoot, 'design-procedure.html')).content;
for (let stage = 0; stage <= 16; stage += 1) {
  const id = `A-DS-${String(stage).padStart(2, '0')}`;
  if (!procedure.includes(`<code>${id}</code>`)) fail(`design-procedure.html: missing stage ${id}`);
}
for (const marker of ['A-DS-15</code> 설치·업그레이드·기존 데이터 이전·운영', '사용자 확인·동결', 'A-DS-16</code> 전체 연결·교차 검증·동결', 'G0 C28-I06 코드 관문 통과', 'Page 9·Menu 6·Asset 5·GET 40·POST 79', 'A-DS15-CA01', 'A-DS15-DBA01', 'A-DS15-RA01', 'API-AUD-2026-08-14-R6', 'G0-REL-C28-I06', 'G1-IMP-01', 'G1 1차 기반 계약 통과', 'G1-IMP-05', 'G1 Activation·DB 통합 계약 통과', 'G1-IMP-06', 'G1 Runtime 스키마 검산 계약 통과']) {
  if (!procedure.includes(marker)) fail(`design-procedure.html: missing current status marker ${marker}`);
}
for (const marker of ['G1-IMP-07', 'G1-IMP-08', '공통 REST envelope', 'G1-REST-GAP-01', 'Context 목록 adapter·Q01']) {
  if (!procedure.includes(marker)) {
    fail(`design-procedure.html: missing G1 current status marker ${marker}`);
  }
}
for (const marker of ['G1-IMP-09', 'G1-REST-GAP-01 해결', '활성 REST 경로 1개', '활동지 수직 흐름', 'G4-IMP-02-S01', 'G4-IMP-02-S02 Worker 활성', 'G4-IMP-02-R01 완료', 'G4-IMP-02-S03 완료', 'G4-IMP-02-S04 완료', 'G4-IMP-02-S05 완료', 'G2 7/7', 'G3는 8/8', 'C67 본인 current official', 'IMP-01 5/5 완료', 'IMP-02 5/5 완료', 'G4-IMP-03-S01 완료', 'G4-IMP-03-S02 완료', 'G4-IMP-03-S03 완료', 'G4-IMP-03-S04 완료', 'IMP-03 4/4 완료', 'G4-IMP-04-S01 완료', 'G4-IMP-04-S02 완료', 'G4-IMP-04-S03 완료', 'G4-IMP-04-S04 완료', 'IMP-04 4/4 완료', 'G4-IMP-05-S01 완료', 'G4-IMP-05-S02 완료', 'G4-IMP-05-S03 완료', 'G4-IMP-05-S04 완료', 'G4-IMP-05-S05 완료', 'IMP-05 5/5 완료', 'G4-IMP-06-S01 완료', 'G4-IMP-06-S02 완료', 'G4-IMP-06-S03 완료', 'IMP-06 3/5 진행', '다음 G4-IMP-06-S04 C43 정정·stale 처리']) {
  if (marker === 'G2 7/7' && procedure.includes('G6-IMP-01')) continue;
  if (marker === 'G3는 8/8' && procedure.includes('G3-IMP-08-S04')) continue;
  if (['G4-IMP-06-S03 완료', 'IMP-06 3/5 진행', '다음 G4-IMP-06-S04 C43 정정·stale 처리'].includes(marker)) continue;
  if (!procedure.includes(marker)) {
    fail(`design-procedure.html: missing Q01 current status marker ${marker}`);
  }
}
for (const marker of ['g4-imp-07-s01-result-print-projection.html', 'g4-imp-07-s02-result-pdf-job.html', 'g4-imp-07-s03-result-pdf-access-freeze.html', 'g4-imp-07-s04-result-pdf-ui-integration.html', 'G4-IMP-07-S01 완료', 'G4-IMP-07-S02 완료', 'G4-IMP-07-S03 완료', 'G4-IMP-07-S04 완료', 'IMP-07 4/4 완료', 'g5-imp-01-s01-worksheet-report-list.html', 'G5-IMP-01-S01 완료', 'g5-imp-01-s02-worksheet-report-detail.html', 'G5-IMP-01-S02 완료', 'g5-imp-01-s03-report-filter-denominator.html', 'G5-IMP-01-S03 완료', 'g5-imp-01-s04-report-dashboard.html', 'G5-IMP-01-S04 완료', 'IMP-01 4/4', 'g5-imp-02-s01-report-snapshot-create.html', 'g5-imp-02-s02-report-snapshot-query.html', 'g5-imp-02-s03-report-source-manifest.html', 'g5-imp-02-s04-report-snapshot-integration-freeze.html', 'G5-IMP-02-S04 완료', 'IMP-02 4/4', 'g5-imp-03-s01-report-export-enqueue.html', 'G5-IMP-03-S01 완료', 'g5-imp-03-s02-submission-bundle-enqueue.html', 'G5-IMP-03-S02 완료', 'g5-imp-03-s03-report-json-package-enqueue.html', 'G5-IMP-03-S03 완료', 'IMP-03 3/6', '다음 G5-IMP-03-S04 Q23·Q24 job·download']) {
  if (['G5-IMP-03-S03 완료', 'IMP-03 3/6', '다음 G5-IMP-03-S04 Q23·Q24 job·download'].includes(marker)) continue;
  if (!procedure.includes(marker)) fail(`design-procedure.html: missing G5 IMP-03-S04 current status marker ${marker}`);
}
for (const marker of ['g5-imp-03-s04-report-export-job-download.html', 'g5-imp-03-s05-report-export-control.html', 'g5-imp-03-s06-report-export-integration-freeze.html', 'G5-IMP-03-S06 완료', 'IMP-03 6/6', 'G5 6/8', 'g5-imp-05-s01-bridge-schema-queue-diagnostics.html', 'g5-imp-05-s02-ai-routing-diagnostics.html', 'g5-imp-05-s03-storage-secret-diagnostics.html', 'g5-imp-05-s04-operations-ui-regression.html', 'G5-IMP-05-S04 완료', 'IMP-05 4/4', 'g5-imp-06-s01-fresh-install.html', 'g5-imp-06-s02-sequential-upgrade.html', 'g5-imp-06-s03-failure-restart.html', 'g5-imp-06-s04-forward-repair.html', 'G5-IMP-06-S04</code></a></td><td>완료', 'g5-imp-06-s05-data-preservation.html', 'G5-IMP-06-S05</code></a></td><td>완료', 'IMP-06 5/5', 'g5-imp-07-s01-legacy-migration-preview.html', 'G5-IMP-07-S01</code></a></td><td>완료', 'g5-imp-07-s02-legacy-migration-approval.html', 'G5-IMP-07-S02</code></a></td><td>완료', 'G5-IMP-07-S03 완료·IMP-07 3/6', 'G5-IMP-07-S03-D3-D2', 'check:legacy-migration-mapping-g5', 'G5-IMP-07-S03-D3-D3', 'check:legacy-migration-publication-g5', 'G5-IMP-07-S03-D3-D4', 'check:legacy-migration-assignment-g5', 'G5-IMP-07-S03-D3-D5', 'check:legacy-migration-submission-g5', 'G5-IMP-07-S03-D3-D6', 'check:legacy-migration-evaluation-g5', 'G5-IMP-07-S03-D3-D7', 'D3-D 6/8']) {
  if (marker === 'D3-D 6/8' && procedure.includes('D3-D 8/8')) continue;
  if (!procedure.includes(marker)) fail(`design-procedure.html: missing G5 IMP-07-S02 current status marker ${marker}`);
}
for (const marker of ['g5-imp-07-s04-migration-run-query.html', 'g5-imp-07-s05-legacy-migration-rollback.html', 'G5-IMP-07-S05 완료·IMP-07 5/6', 'check:legacy-migration-run-query-g5', 'check:legacy-migration-rollback-g5', 'G5-IMP-07-S06']) {
  if (!procedure.includes(marker)) fail(`design-procedure.html: missing G5 IMP-07-S05 current status marker ${marker}`);
}
for (const marker of ['g5-imp-07-s06-legacy-migration-integration-freeze.html', 'G5-IMP-07-S06 완료·IMP-07 6/6', 'check:legacy-migration-integration-g5', 'G5 7/8', 'G5-IMP-08-S01']) {
  if (!procedure.includes(marker)) fail(`design-procedure.html: missing G5 IMP-07-S06 current status marker ${marker}`);
}
for (const staleMarker of ['다음 G5-IMP-03-S01 C46·C47 CSV·report PDF', '다음 G5-IMP-03-S02 C50 ZIP', '다음 G5-IMP-03-S03 C69 JSON package', 'G5-IMP-03-S02 완료·IMP-03 2/6']) {
for (const marker of ['g5-imp-08-s01-uninstall-preservation.html', 'g5-imp-08-s02-purge-preview.html', 'G5-IMP-08-S02 완료·IMP-08 2/6', 'check:purge-preview-g5', 'G5-IMP-08-S03', 'C72 approve']) {
  if (!procedure.includes(marker)) fail(`design-procedure.html: missing G5 IMP-08-S02 current status marker ${marker}`);
}
  if (procedure.includes(staleMarker)) fail(`design-procedure.html: stale G5 current marker remains ${staleMarker}`);
}
for (const staleMarker of ['IMP-03 3/6', '다음 G5-IMP-03-S04 Q23·Q24 job·download', '다음 G5-IMP-03-S05 C53·C54 cancel·retry']) {
  if (procedure.includes(staleMarker)) fail(`design-procedure.html: stale G5 IMP-03-S03 marker remains ${staleMarker}`);
}

const implementationRoadmap = documents.get(path.join(docsRoot, 'implementation-roadmap.html')).content;
for (const marker of [
  'G0~G6 큰 관문 아래에서 실제로 수행하는 작업',
  '<code>G1-IMP-09-S04</code>',
  '<code>G2-IMP-01-S10</code>',
  '<code>G2-IMP-02-S01</code>',
  '<code>G2-IMP-02-S02</code>',
  '<code>G2-IMP-02-S12</code>',
  'G2-IMP-02-S03 완료',
  'G2-IMP-02-S04 완료',
  'G2-IMP-02-S06 완료',
  'G2-IMP-02-S07 완료',
  'G2-IMP-02-S08 완료',
  'G2-IMP-02-S09 완료',
  'G2-IMP-02-S10 완료',
  'G2-IMP-02-S11 완료',
  'G2-IMP-02-S12 완료',
  'G2-IMP-04-S01 완료',
  'G2-IMP-04-S02 완료',
  'G2-IMP-04-S03 완료',
  'G2-IMP-04-S04 완료',
  'G2-IMP-05-S01 완료',
  'G2-IMP-05-S02 완료',
  'G2-IMP-05-S03 완료',
  'G2-IMP-05-S04 완료',
  'G2-IMP-05-S05 완료',
  'G2-IMP-05-S06 완료',
  'G2-IMP-05-S07 완료',
  'G2-IMP-05-S08 완료',
  'G2-IMP-05-S09 완료',
  'G2-IMP-05-R01 완료',
  'G2-IMP-06-S01 완료',
  'G2-IMP-06-S02 완료',
  'G2-IMP-06-S03 완료',
  'G2-IMP-06-S04 완료',
  'G2-IMP-06-S05 완료',
  'G5-IMP-04-S01 완료',
  'G5-IMP-04-S02 완료',
  'G5-IMP-04-S03 완료',
  'G5-IMP-04-S04 완료',
  'G5-IMP-04-S05 완료',
  'G3-IMP-01-S01 완료',
  'G3-IMP-01-S02 완료',
  'G3-IMP-01-S03 완료',
  'G3-IMP-01-S04 완료',
  'G3-IMP-01-S05 완료',
  'G3-IMP-02-S01 완료',
  'G3-IMP-02-S02 완료',
  'G3-IMP-02-S03 완료',
  'G3-IMP-02-S04 완료',
  'G3-IMP-03-S01 완료',
  'G3-IMP-03-S02 완료',
  'G3-IMP-03-S03 완료',
  'G3-IMP-03-S04 완료',
  'G3-IMP-04-S01 완료',
  'G3-IMP-04-S02 완료',
  'G3-IMP-04-S03 완료',
  'G3-IMP-04-S04 완료',
  'G3-IMP-04-S05 완료',
  'G3-IMP-05-S01 완료',
  'G3-IMP-05-S02 완료',
  'G3-IMP-05-S03 완료',
  'G3-IMP-05-S04 완료',
  'G3-IMP-06-S01 완료',
  'G3-IMP-06-S02 완료',
  'G3-IMP-06-S03 완료',
  'G3-IMP-06-S04 완료',
  'G3-IMP-06-S05 완료',
  'G3-IMP-07-S01 완료',
  'IMP-06 5/5',
  'G3-IMP-07-S02 완료',
  'G3-IMP-07-S03 완료',
  'G3-IMP-07-S04 완료',
  'G3-IMP-07-S05 완료',
  'G2-IMP-07-S01 완료',
  'G2-IMP-07-S02 완료',
  'G2-IMP-07-S04 완료',
  'G2-IMP-07-S03 완료',
  'IMP-07 5/5 완료',
  'G3-IMP-08-S02 완료',
  '<code>G3-IMP-08-S02-A</code>',
  '<code>G3-IMP-08-S02-F</code>',
  'G3-IMP-08-S03 완료',
  '<code>G3-IMP-08-S03-A</code>',
  '<code>G3-IMP-08-S03-F</code>',
  'G3-IMP-08-S04 완료',
  '<code>G3-IMP-08-S04-A</code>',
  '<code>G3-IMP-08-S04-F</code>',
  'G3 8/8 완료',
  'G4-IMP-01-S01 완료',
  'G4-IMP-01-S02 완료',
  'G4-IMP-01-S03 완료',
  'G4-IMP-01-S04 완료',
  'G4-IMP-01-S05 완료',
  'G4 IMP-01 5/5 완료',
  'G4-IMP-02-S01 완료',
  'G4-IMP-02-S02 Worker 활성',
  'G4-IMP-02-R01 완료',
  'G4-IMP-02-S03 완료',
  'G4-IMP-02-S04 완료',
  'G4-IMP-02-S05 완료',
  'IMP-02 5/5 완료',
  'IMP-01 통합 검증',
  'S01~S04 계약 편입',
  'OJ Platform dev.74 관찰',
  '<code>2.0.0-dev.74</code> 관찰',
  '<code>G4-IMP-02-S01</code>',
  '<code>G4-IMP-02-S02</code>',
  '<code>G4-IMP-02-R01</code>',
  '<code>G4-IMP-02-S03</code>',
  '<code>G4-IMP-02-S04</code>',
  '<code>G4-IMP-02-S05</code>',
  '<code>G4-IMP-01-S01</code>',
  '<code>G4-IMP-01-S02</code>',
  '<code>G4-IMP-01-S03</code>',
  '<code>G4-IMP-01-S04</code>',
  '<code>G4-IMP-01-S05</code>',
  '피드백 작업대 통합 동결 완료',
  'IMP-03 4/4 완료',
  'G4-IMP-04-S01 완료',
  'G4-IMP-04-S02 완료',
  'G4-IMP-04-S03 완료',
  'G4-IMP-04-S04',
  'IMP-04 4/4 완료',
  'G4-IMP-05-S01 완료',
  'G4-IMP-05-S02 완료',
  'G4-IMP-05-S03 완료',
  'G4-IMP-05-S04 완료',
  'G4-IMP-05-S05 완료',
  'IMP-05 5/5 완료',
  'G4-IMP-06-S01 완료',
  'G4-IMP-06-S02 완료',
  'g4-imp-06-s03-student-result-withdrawal.html',
  'G4-IMP-06-S04 완료',
  'G4-IMP-06-S05 완료',
  'IMP-06 5/5 완료',
  'g4-imp-07-s01-result-print-projection.html',
  'g4-imp-07-s02-result-pdf-job.html',
  'g4-imp-07-s03-result-pdf-access-freeze.html',
  'g4-imp-07-s04-result-pdf-ui-integration.html',
  'G4-IMP-07-S02 완료',
  'G4-IMP-07-S03 완료',
  'G4-IMP-07-S04 완료',
  'IMP-07 4/4 완료',
  'g5-imp-01-s01-worksheet-report-list.html',
  'G5-IMP-01-S01 완료',
  'g5-imp-01-s02-worksheet-report-detail.html',
  'G5-IMP-01-S02 완료',
  'g5-imp-01-s03-report-filter-denominator.html',
  'G5-IMP-01-S03 완료',
  'g5-imp-01-s04-report-dashboard.html',
  'G5-IMP-01-S04 완료',
  'IMP-01 4/4',
  'g5-imp-02-s01-report-snapshot-create.html',
  'C45 보고서 스냅샷 생성 완료',
  'check:report-snapshot-g5',
  'g5-imp-02-s02-report-snapshot-query.html',
  'Q22 보고서 스냅샷 조회 완료',
  'check:report-snapshot-query-g5',
  'g5-imp-02-s03-report-source-manifest.html',
  'G5-IMP-02-S03 완료',
  '보고서 원본 계보 내부 전달 완료',
  'check:report-source-manifest-g5',
  'g5-imp-02-s04-report-snapshot-integration-freeze.html',
  'G5-IMP-02-S04 완료',
  'IMP-02 4/4',
  '보고서 스냅샷 통합 동결 완료',
  'check:report-snapshot-integration-g5',
  'g5-imp-03-s01-report-export-enqueue.html',
  'G5-IMP-03-S01 완료',
  'C46·C47 보고서 내보내기 작업 완료',
  'check:report-export-enqueue-g5',
  'g5-imp-03-s02-submission-bundle-enqueue.html',
  'G5-IMP-03-S02 완료',
  'C50 학생 결과물 ZIP 작업 완료',
  'check:submission-bundle-enqueue-g5',
  'submission_bundle.v1',
  'g5-imp-03-s03-report-json-package-enqueue.html',
  'G5-IMP-03-S03 완료',
  'IMP-03 3/6',
  'C69 보고서 JSON package 작업 완료',
  'check:report-json-package-enqueue-g5',
  'report_json_package.v1',
  '다음 G5-IMP-03-S04 Q23·Q24 job·download',
  '7/7 · IMP-01 5/5 · IMP-02 5/5·R01 · IMP-03 4/4 · IMP-04 4/4 · IMP-05 5/5 · IMP-06 5/5 · IMP-07 4/4 완료',
  '학생 특기사항 통합 동결 완료',
  'C44 학생 결과 공개 회수 완료',
  '불변 withdrawn revision',
  'exact-copy projection',
  'C43 학생 결과 정정 공개 완료',
  'corrected revision',
  '공개 projection 완료',
  '<code>G4-IMP-05-S01</code>',
  '<code>G4-IMP-05-S02</code>',
  '<code>G4-IMP-05-S03</code>',
  '<code>G4-IMP-05-S04</code>',
  '<code>G4-IMP-05-S05</code>',
  '<code>G4-IMP-06-S01</code>',
  '<code>G4-IMP-06-S02</code>',
  'Q33 학생 본인 결과 조회 완료',
  '본인 current release',
  '<code>G4-IMP-06-S05</code>',
  'g4-imp-06-s05-student-result-ui-integration.html',
  '권한 회수 즉시 본문 제거',
  '<code>G4-IMP-07-S01</code>',
  'result_print.v1',
  'check:worksheet-report-list-g5',
  'check:worksheet-report-detail-g5',
  'source·filter·projection hash',
  'oj_ai.reporting_metrics@1.0.0',
  '<code>G4-IMP-07-S02</code>',
  '<code>G4-IMP-07-S03</code>',
  '<code>G4-IMP-07-S04</code>',
  'oj_ai.result_pdf_request@1.0.0',
  '300초 lease',
  'stale·withdrawn',
  '<code>G4-IMP-06-S03</code>',
  '<code>G4-IMP-06-S04</code>',
  '두 번 전체 source 수집',
  '2/8·IMP-01 4/4·IMP-02 4/4·IMP-03 3/6·IMP-04 5/5',
  '9/9+R01·완료',
  '7/7·IMP-07 4/4 완료',
  '<code>G2-IMP-07-S01-A</code>',
  '<code>G2-IMP-07-S01-E</code>',
  '<code>G2-IMP-07-S02-A</code>',
  '<code>G2-IMP-07-S03-A</code>',
  '<code>G2-IMP-07-S04-C</code>',
  '<code>G2-IMP-05-S09-A</code>',
  '<code>G2-IMP-05-S09-E</code>',
  '<code>G2-IMP-05-R01-A</code>',
  '<code>G2-IMP-05-R01-E</code>',
  '<code>G2-IMP-06-S01</code>',
  '<code>G2-IMP-06-S02</code>',
  '<code>G2-IMP-06-S03</code>',
  '<code>G2-IMP-06-S04</code>',
  '<code>G2-IMP-06-S05</code>',
  '<code>G2-IMP-05-S01-A</code>',
  '<code>G2-IMP-05-S02-E</code>',
  '<code>G2-IMP-05-S03-E</code>',
  '<code>G2-IMP-05-S04-E</code>',
  '<code>G2-IMP-05-S05-E</code>',
  '<code>G2-IMP-05-S06-E</code>',
  '<code>G2-IMP-04-S01-A</code>',
  '<code>G2-IMP-04-S02-D</code>',
  '<code>G2-IMP-04-S04-B</code>',
  '<code>G3-IMP-08</code>',
  '<code>G4-IMP-07</code>',
  '<code>G5-IMP-08</code>',
  '<code>G6-IMP-05</code>',
  'CFG-BOOTSTRAP-02',
  '공개 API 변경 없음',
]) {
  if (['G5-IMP-03-S03 완료', 'IMP-03 3/6', '다음 G5-IMP-03-S04 Q23·Q24 job·download', '2/8·IMP-01 4/4·IMP-02 4/4·IMP-03 3/6·IMP-04 5/5'].includes(marker)) continue;
  if (!implementationRoadmap.includes(marker)) fail(`implementation-roadmap.html: missing progress marker ${marker}`);
}
for (const marker of ['g5-imp-05-s04-operations-ui-regression.html', 'G5-IMP-05-S04 완료·IMP-05 4/4', 'Q28 5-check·C75 활성', 'IMP-05 4/4', 'G5 6/8', 'g5-imp-06-s01-fresh-install.html', 'g5-imp-06-s02-sequential-upgrade.html', 'g5-imp-06-s03-failure-restart.html', 'g5-imp-06-s04-forward-repair.html', 'G5-IMP-06-S04-A', 'G5-IMP-06-S04-H', 'check:forward-repair-g5', 'g5-imp-06-s05-data-preservation.html', 'G5-IMP-06-S05 완료·IMP-06 5/5', 'G5-IMP-06-S05-A', 'G5-IMP-06-S05-G', 'check:data-preservation-g5', 'g5-imp-07-s01-legacy-migration-preview.html', 'G5-IMP-07-S01-A', 'G5-IMP-07-S01-G', 'g5-imp-07-s02-legacy-migration-approval.html', 'g5-imp-07-s04-migration-run-query.html', 'G5-IMP-07-S04 완료·IMP-07 4/6', 'S04 완료·4/6', 'check:legacy-migration-run-query-g5', 'G5-IMP-07-S05', 'G5-IMP-07-S02-A', 'G5-IMP-07-S02-G', 'G5-IMP-07-S03-D3-C', 'G5-IMP-07-S03-D3-D', '완료·4/4', '6-item batch rollback·retry']) {
  if (!implementationRoadmap.includes(marker)) fail(`implementation-roadmap.html: missing G5 IMP-07-S04 current marker ${marker}`);
}
for (const marker of ['g5-imp-07-s05-legacy-migration-rollback.html', 'G5-IMP-07-S05 완료·IMP-07 5/6', 'S05 완료·5/6', 'check:legacy-migration-rollback-g5', 'G5-IMP-07-S06', 'source 변화·token 만료']) {
  if (!implementationRoadmap.includes(marker)) fail(`implementation-roadmap.html: missing G5 IMP-07-S05 current marker ${marker}`);
}
for (const marker of ['g5-imp-07-s06-legacy-migration-integration-freeze.html', 'G5-IMP-07-S06 완료·IMP-07 6/6', '완료·IMP-07 6/6', 'check:legacy-migration-integration-g5', 'G5 7/8', 'G5-IMP-08-S01', 'uninstall 보존']) {
  if (!implementationRoadmap.includes(marker)) fail(`implementation-roadmap.html: missing G5 IMP-07-S06 current marker ${marker}`);
}
for (const marker of ['g5-imp-03-s04-report-export-job-download.html', 'g5-imp-03-s05-report-export-control.html', 'g5-imp-03-s06-report-export-integration-freeze.html', 'G5-IMP-03-S06 완료', 'IMP-03 6/6', 'Q23·Q24 내보내기 작업·다운로드 완료', 'C53·C54 내보내기 제어 완료', '내보내기 통합 동결 완료', 'check:report-export-job-download-g5', 'check:report-export-control-g5', 'G5-IMP-05-S01', '6/8·IMP-01 4/4·IMP-02 4/4·IMP-03 6/6·IMP-04 5/5·IMP-05 4/4·IMP-06 5/5']) {
for (const marker of ['g5-imp-08-s01-uninstall-preservation.html', 'g5-imp-08-s02-purge-preview.html', 'G5-IMP-08-S02 완료·IMP-08 2/6', 'S02 완료·2/6', 'check:purge-preview-g5', 'IMP-08 2/6', 'G5-IMP-08-S03', 'C72']) {
  if (!implementationRoadmap.includes(marker)) fail(`implementation-roadmap.html: missing G5 IMP-08-S02 current marker ${marker}`);
}
  if (marker === '6/8·IMP-01 4/4·IMP-02 4/4·IMP-03 6/6·IMP-04 5/5·IMP-05 4/4·IMP-06 5/5') {
    if (!implementationRoadmap.includes('8/8·IMP-01 4/4·IMP-02 4/4·IMP-03 6/6·IMP-04 5/5·IMP-05 4/4·IMP-06 5/5·IMP-07 6/6·IMP-08 6/6')) fail('implementation-roadmap.html: missing completed G5 8/8 aggregate');
    continue;
  }
  if (!implementationRoadmap.includes(marker)) fail(`implementation-roadmap.html: missing G5 IMP-03-S06 progress marker ${marker}`);
}
for (const staleMarker of ['IMP-03 3/6', '다음 G5-IMP-03-S04 Q23·Q24 job·download', '다음 G5-IMP-03-S05 C53·C54 cancel·retry']) {
  if (implementationRoadmap.includes(staleMarker)) fail(`implementation-roadmap.html: stale G5 IMP-03-S03 marker remains ${staleMarker}`);
}
for (const marker of ['g5-imp-07-s03-d3-d-owner-migration.html', 'G5-IMP-07-S03-D3-D2', 'G5-IMP-07-S03-D3-D3', 'G5-IMP-07-S03-D3-D3-F', 'G5-IMP-07-S03-D3-D4-G', '완료·8/8', 'D3-D 6/8', '완료·7/7', 'check:legacy-migration-mapping-g5', 'check:legacy-migration-publication-g5', 'check:legacy-migration-assignment-g5', 'G5-IMP-07-S03-D3-D5', 'check:legacy-migration-submission-g5', 'G5-IMP-07-S03-D3-D6', 'D3-D6 구현 결과', 'check:legacy-migration-evaluation-g5', 'G5-IMP-07-S03-D3-D7']) {
  if (!implementationRoadmap.includes(marker)) fail(`implementation-roadmap.html: missing D3-D5 completion marker ${marker}`);
}
for (const marker of [
  'D3-D5 구현 결과',
  '10종 response',
  'SHA-256 metadata key',
  'attachment manifest',
  'relation source drift',
  'caller rollback',
]) {
  if (!implementationRoadmap.includes(marker)) fail(`implementation-roadmap.html: missing D3-D5 result marker ${marker}`);
for (const marker of [
  'D3-D6 구현 결과',
  'current-contract',
  '10개 source dispatch',
  '원본 provider 응답 비저장',
  'caller rollback',
]) {
  if (!implementationRoadmap.includes(marker)) fail(`implementation-roadmap.html: missing D3-D6 result marker ${marker}`);
}
}
for (const staleMarker of ['D3-D2 진행', '진행·1/8', '진행·2/8', 'D3-D3 진행', '진행·3/8', 'D3-D 3/8', 'D3-D4 진행', '진행·0/7']) {
  if (implementationRoadmap.includes(staleMarker)) fail(`implementation-roadmap.html: stale D3-D4 marker remains ${staleMarker}`);
}
const resultPdfDetailedRow = implementationRoadmap.indexOf('<code>G4-IMP-07-S03</code></a></td><td>Q23·Q24 결과 PDF 접근 동결');
const resultPdfDetailedBodyOpen = implementationRoadmap.lastIndexOf('<tbody>', resultPdfDetailedRow);
const resultPdfDetailedBodyClose = implementationRoadmap.indexOf('</tbody>', resultPdfDetailedRow);
const resultPdfDetailedTableClose = implementationRoadmap.indexOf('</table>', resultPdfDetailedRow);
if (resultPdfDetailedRow < 0 || resultPdfDetailedBodyOpen < 0 || resultPdfDetailedBodyClose < resultPdfDetailedRow || resultPdfDetailedTableClose < resultPdfDetailedBodyClose) {
  fail('implementation-roadmap.html: G4-IMP-07 S01-S03 detail rows must remain inside one tbody');
}
for (const staleMarker of [
  'IMP-04 3/4 진행',
  'S03 완료·IMP-04 3/4',
  '다음 G4-IMP-04-S04 revision UI·통합 회귀',
  '3/7 · IMP-01 5/5 · IMP-02 5/5·R01 · IMP-03 4/4 · IMP-04 3/4 진행',
  '다음 G4-IMP-05-S01 C38 학생 특기사항 초안',
  'S04 완료·IMP-04 4/4',
  'S01 완료·IMP-05 1/5',
  'IMP-05 1/5 진행',
  '다음 G4-IMP-05-S02 Q18~Q19 특기사항 조회',
  'S02 완료·IMP-05 2/5',
  'IMP-05 2/5 진행',
  '다음 G4-IMP-05-S03 C39~C40 특기사항 검토·승인',
  'S03 완료·IMP-05 3/5',
  'IMP-05 3/5 진행',
  '다음 G4-IMP-05-S04 C41~C42 보고서 포함·제외',
  'S04 완료·IMP-05 4/5',
  'IMP-05 4/5 진행',
  '다음 G4-IMP-05-S05 provenance·권한 통합 동결',
  '4/7 · IMP-01 5/5 · IMP-02 5/5·R01 · IMP-03 4/4 · IMP-04 4/4 · IMP-05 4/5 진행',
  '139개 설계 HTML + UI 시안 1개',
  'S03 검증',
  'S04 검증',
  '141개 설계 HTML + UI 시안 1개',
  'IMP-06 4/5 진행',
  '다음 G4-IMP-06-S05 학생 결과 화면·통합 동결',
  '5/7 · IMP-01 5/5 · IMP-02 5/5·R01 · IMP-03 4/4 · IMP-04 4/4 · IMP-05 5/5 완료 · IMP-06 4/5 진행',
  'C43 정정 완료',
  '140개 설계 HTML + UI 시안 1개',
  'IMP-06 3/5 진행',
  '다음 G4-IMP-06-S04 C43 정정·stale 처리',
  '5/7 · IMP-01 5/5 · IMP-02 5/5·R01 · IMP-03 4/4 · IMP-04 4/4 · IMP-05 5/5 완료 · IMP-06 3/5 진행',
  'IMP-06 2/5 진행',
  '다음 G4-IMP-06-S03 C44 공개 회수',
  '5/7 · IMP-01 5/5 · IMP-02 5/5·R01 · IMP-03 4/4 · IMP-04 4/4 · IMP-05 5/5 완료 · IMP-06 2/5 진행',
  '4/7 · IMP-01 5/5 · IMP-02 5/5·R01 · IMP-03 4/4 · IMP-04 4/4 · IMP-05 3/5 진행',
  'S05 검증',
  '142개 설계 HTML + UI 시안 1개',
  '다음 G4-IMP-07-S01 학생 결과 PDF 공개 projection',
  '학생 결과 UI 완료',
  'IMP-06 1/5 진행',
  '다음 G4-IMP-06-S02 Q33 학생 본인 결과 조회',
  '5/7 · IMP-01 5/5 · IMP-02 5/5·R01 · IMP-03 4/4 · IMP-04 4/4 · IMP-05 5/5 완료 · IMP-06 1/5 진행',
  '다음 G5-IMP-01-S04 대시보드 UI·권한',
  'IMP-01 3/4',
  'G5 1/8 진행',
  '다음 G5-IMP-02-S01 C45 보고서 snapshot',
  '2/8·IMP-01 4/4·IMP-04 5/5',
  'G5-IMP-02-S01 완료·IMP-02 1/4',
  '다음 G5-IMP-02-S02 Q22 snapshot 조회',
  '2/8·IMP-01 4/4·IMP-02 1/4·IMP-04 5/5',
  'G5-IMP-02-S02 완료·IMP-02 2/4',
  '다음 G5-IMP-02-S03 source manifest·hash',
  '2/8·IMP-01 4/4·IMP-02 2/4·IMP-04 5/5',
  'IMP-02 2/4 진행',
  '다음 G5-IMP-03-S01 C46·C47 CSV·report PDF',
  'G5-IMP-03-S01 완료·IMP-03 1/6',
  '다음 G5-IMP-03-S02 C50 ZIP',
  'IMP-03 1/6 진행',
  'G5-IMP-03-S02 완료·IMP-03 2/6',
  'IMP-03 2/6 진행',
  '다음 G5-IMP-03-S03 C69 JSON package',
]) {
  if (implementationRoadmap.includes(staleMarker)) fail(`implementation-roadmap.html: stale current-state marker ${staleMarker}`);
}

const indexDocument = documents.get(path.join(docsRoot, 'index.html')).content;
for (const marker of ['A-DS-14 사용자 확인·동결', 'A-DS-15 사용자 확인·동결', 'A-DS-16 사용자 확인·동결', 'G0 C28-I06 코드 관문 통과', 'A-DS-16 전체 연결·교차 검증·동결', '공개 Addon API <code>1.1.0</code>', '공개 symbol 37개', 'G0 C28-I06 릴리스 관문', 'G1 애드온 기반 구현 기록', 'G1 1차 기반 계약 통과', 'G1 활성화·데이터베이스 통합 기록', 'G1 Activation·DB 통합 계약 통과', 'G1 Runtime 스키마 검산 기록', 'G1 Runtime 스키마 검산 계약 통과']) {
  if (marker === '공개 Addon API <code>1.1.0</code>' && indexDocument.includes('플랫폼 Addon API <code>1.1.0</code>')) continue;
  if (!indexDocument.includes(marker)) fail(`index.html: missing current status marker ${marker}`);
}
for (const marker of ['g5-imp-01-s01-worksheet-report-list.html', 'g5-imp-01-s02-worksheet-report-detail.html', 'g5-imp-01-s03-report-filter-denominator.html', 'g5-imp-01-s04-report-dashboard.html', 'G5-IMP-01-S04 완료', 'g5-imp-02-s01-report-snapshot-create.html', 'g5-imp-02-s02-report-snapshot-query.html', 'g5-imp-02-s03-report-source-manifest.html', 'g5-imp-02-s04-report-snapshot-integration-freeze.html', 'G5-IMP-02-S04 완료', 'g5-imp-03-s01-report-export-enqueue.html', 'G5-IMP-03-S01 완료', 'g5-imp-03-s02-submission-bundle-enqueue.html', 'G5-IMP-03-S02 완료', 'g5-imp-03-s03-report-json-package-enqueue.html', 'schema 1.0.2', 'IMP-01 4/4', 'IMP-02 4/4', 'G5 6/8']) {
  if (['G5-IMP-03-S03 완료', 'IMP-03 3/6', '다음 G5-IMP-03-S04 Q23·Q24 job·download'].includes(marker)) continue;
  if (!indexDocument.includes(marker)) {
    fail(`index.html: missing G5 IMP-03-S04 current status marker ${marker}`);
  }
}
for (const marker of ['g5-imp-03-s04-report-export-job-download.html', 'g5-imp-03-s05-report-export-control.html', 'g5-imp-03-s06-report-export-integration-freeze.html', 'G5-IMP-03-S06 완료', 'IMP-03 6/6', 'G5 6/8', 'g5-imp-05-s01-bridge-schema-queue-diagnostics.html', 'g5-imp-05-s02-ai-routing-diagnostics.html', 'g5-imp-05-s03-storage-secret-diagnostics.html', 'g5-imp-05-s04-operations-ui-regression.html', 'G5-IMP-05-S04 완료', 'IMP-05 4/4', 'g5-imp-06-s01-fresh-install.html', 'g5-imp-06-s02-sequential-upgrade.html', 'g5-imp-06-s03-failure-restart.html', 'g5-imp-06-s04-forward-repair.html', 'g5-imp-06-s05-data-preservation.html', 'G5-IMP-06-S05 완료·IMP-06 5/5', 'IMP-06 5/5', 'g5-imp-07-s01-legacy-migration-preview.html', 'g5-imp-07-s02-legacy-migration-approval.html', 'G5-IMP-07-S03 완료·IMP-07 3/6', 'g5-imp-07-s03-d3-d-owner-migration.html', 'G5-IMP-07-S03-D3-D6 완료·D3-D 6/8', '현재 G5-IMP-07-S03-D3-D7 private file safety']) {
  if (!indexDocument.includes(marker)) fail(`index.html: missing G5 IMP-07-S03-D3-D5 current status marker ${marker}`);
}
for (const marker of ['g5-imp-07-s05-legacy-migration-rollback.html', 'G5-IMP-07-S05 완료·IMP-07 5/6', 'G5-IMP-07-S06', 'source 변화·token 만료']) {
  if (!indexDocument.includes(marker)) fail(`index.html: missing G5 IMP-07-S05 current status marker ${marker}`);
}
for (const marker of ['g5-imp-07-s06-legacy-migration-integration-freeze.html', 'G5-IMP-07-S06 완료·IMP-07 6/6', 'G5 7/8', 'G5-IMP-08-S01', 'uninstall 보존']) {
  if (!indexDocument.includes(marker)) fail(`index.html: missing G5 IMP-07-S06 current status marker ${marker}`);
}
for (const staleMarker of ['다음 G5-IMP-07-S03 C62 이전 실행']) if (indexDocument.includes(staleMarker)) fail(`index.html: stale G5 IMP-07-S03 marker remains ${staleMarker}`);
for (const marker of ['g5-imp-08-s01-uninstall-preservation.html', 'g5-imp-08-s02-purge-preview.html', 'G5-IMP-08-S02 완료·IMP-08 2/6', 'G5-IMP-08-S03', 'C72 approve']) {
  if (!indexDocument.includes(marker)) fail(`index.html: missing G5 IMP-08-S02 current status marker ${marker}`);
}
for (const staleMarker of ['다음 G5-IMP-03-S01 C46·C47 CSV·report PDF', '다음 G5-IMP-03-S02 C50 ZIP', '다음 G5-IMP-03-S03 C69 JSON package', 'G5-IMP-03-S01 완료·IMP-03 1/6', 'G5-IMP-03-S02 완료·IMP-03 2/6']) if (indexDocument.includes(staleMarker)) fail(`index.html: stale G5 current marker remains ${staleMarker}`);
for (const staleMarker of ['IMP-03 3/6', '다음 G5-IMP-03-S04 Q23·Q24 job·download', '다음 G5-IMP-03-S05 C53·C54 cancel·retry']) if (indexDocument.includes(staleMarker)) fail(`index.html: stale G5 IMP-03 marker remains ${staleMarker}`);
for (const marker of ['G1 공통 접근 정책 기록', 'G1 공통 REST envelope 기록', 'G1 교사 Context 목록·Q01 기록', 'G1 Context 목록·Q01</a> 계약까지 통과', 'G1-REST-GAP-01', 'dev.64']) {
  if (!indexDocument.includes(marker)) {
    fail(`index.html: missing G1 REST status marker ${marker}`);
  }
}
for (const marker of ['G2-IMP-02-S03 활동지 문서 검증', 'temporary_id_map', 'G2-IMP-02-S04 리비전 Projection', 'G2-IMP-02-S05 불변 Revision Transaction', 'G2-IMP-02-S06 Operation Transaction', 'G2-IMP-02-S07 C02 REST', 'G2-IMP-02-S08 Plate 교사 작업대', 'G2-IMP-02-S09 저장·충돌·복구', 'G2-IMP-02-S10 전체 회귀', 'G2-IMP-02-S11 화면 검증', 'G2-IMP-02-S12 구현 동결', 'G2-IMP-04-S01 활동지 검증', 'G2-IMP-04-S02 학생·인쇄 미리보기', 'G2-IMP-04-S03 Projection 안전 검사', 'G2-IMP-04-S04 UI·E2E 동결', 'G2-IMP-05-S01 활동지 자체 평가 지시문', 'G2-IMP-05-S02 P04 활동지 평가 입력', 'G2-IMP-05-S03 C10 활동지 평가 요청', 'G2-IMP-05-S04 AI 평가 워커·정규화', 'G2-IMP-05-S05 활동지 평가 조회', 'G2-IMP-05-S06 평가 재시도·취소', 'G2-IMP-05-S07 교사 검토', 'G2-IMP-05-S08 평가 작업대', 'G2-IMP-05-S09 통합 동결', 'G2-IMP-05-R01 외부 AI 동의', 'G2-IMP-06-S01 승인 정책 관문', 'G2-IMP-06-S02 C06 명시 승인', 'G2-IMP-06-S03 C07 불변 발행', 'G2-IMP-06-S04 C08·C09 보관·복원', 'G2-IMP-06-S05 승인·발행 UI 동결', 'G5-IMP-04 평가 설정 구현', 'G3-IMP-01 배포 생성', 'G3-IMP-02 배포 일정·상태', '다음 실행은 <code>G3-IMP-03</code>', 'dev.64']) {
  if (!indexDocument.includes(marker)) {
    fail(`index.html: missing G2 current status marker ${marker}`);
  }
}

for (const marker of ['G3-IMP-04-S02 Q32 본인 제출 조회', 'G3-IMP-04-S03 C23 불변 자동 저장', 'G3-IMP-04-S04 오프라인·충돌 복구', 'G3-IMP-04-S05 반응형·접근성 동결', 'G3-IMP-05-S01 C24 private 첨부', 'G3-IMP-05-S02 C25 첨부 제거', 'G3-IMP-05-S03 첨부 검사 경계', 'G3-IMP-05-S04 첨부 만료·복구', 'G3-IMP-06-S01 C26 공식 제출', 'G3-IMP-06-S02 Q13~Q15 교사용 제출 조회', 'G3-IMP-06-S03 C27~C28 교사 재개방·잠금', 'G3-IMP-06-S04 제출 화면·감사', 'G3-IMP-06-S05 제출 동시성 동결', 'G3-IMP-07-S01 C19 비공개 ZIP', 'G3-IMP-07-S02 Q35 비동기 검사', 'G3-IMP-07-S03 C68 명시 매핑', 'G3-IMP-07-S04 C20 확인·C21 취소', 'G3-IMP-07-S05 ZIP 통합 동결', 'G2-IMP-07-S01 활동지 인쇄 Projection 완료', 'G2-IMP-07-S02 활동지 PDF 완료', 'G2-IMP-07-S03 활동지 package 완료', 'G2-IMP-07-S04 통합 동결 완료', 'G3-IMP-08-S01 교사용 제출 PDF', 'G3-IMP-08-S02 학생용 활동지 PDF', 'G3-IMP-08-S03 학생 작성 결과 PDF', 'G3-IMP-08-S04 PDF 통합 동결', 'G3 8/8 완료', 'G4-IMP-01-S01 학생 제출 평가 지시문', 'G4-IMP-01-S02 공식 제출 평가 입력', 'G4-IMP-01-S03 단건 제출 평가 요청', 'G4-IMP-01-S04 배포 제출 일괄 평가', 'G4-IMP-01-S05 멱등·권한 종합 동결', 'G4-IMP-02-S01 제출 평가 큐·임대', 'G4-IMP-02-S02 Gateway·결과 정규화', 'G4-IMP-02-R01 학생 제출 외부 AI 동의', 'G4-IMP-02-S03 학생 제출 평가 조회', 'G4-IMP-02-S04 학생 제출 평가 재시도·취소', 'G4-IMP-02-S05 학생 제출 평가 통합 동결', 'request-bound grant', 'schema 1.0.2', 'IMP-02 5/5 완료', 'G4-IMP-03-S01 학생 제출 평가 검토 Append', 'G4-IMP-03-S02 공식 검토 포인터 CAS', 'IMP-03 2/4 진행', 'dev.71', '다음 실행은 <code>G4-IMP-03-S03</code>']) {
  if (!indexDocument.includes(marker)) {
    fail(`index.html: missing G3 Q32 current status marker ${marker}`);
  }
}
for (const marker of ['G4-IMP-03-S03 점수·근거 검토 UI', 'IMP-03 3/4 진행', 'dev.73', '다음 실행은 <code>G4-IMP-03-S04</code>', 'review_precondition']) {
  if (!indexDocument.includes(marker)) fail(`index.html: missing G4 S03 current status marker ${marker}`);
}
for (const marker of ['G4-IMP-05-S01 학생 특기사항 AI 초안', 'G4-IMP-05-S02 학생 특기사항 조회', 'G4-IMP-05-S03 학생 특기사항 검토·승인', 'G4-IMP-05-S04 학생 특기사항 보고서 포함·제외', 'G4-IMP-05-S05 학생 특기사항 통합 동결', 'G4-IMP-06-S01 C43 학생 결과 최초 공개', 'G4-IMP-06-S02 Q33 학생 본인 결과 조회', 'G4-IMP-06-S03 C44 학생 결과 공개 회수', 'G4-IMP-06-S01 완료', 'G4-IMP-06-S02 완료', 'G4-IMP-06-S03 완료', 'IMP-05 5/5 완료', 'IMP-06 3/5 진행', 'G4 6/7 진행', '다음 실행은 <code>G4-IMP-06-S04</code> C43 정정·stale 처리', 'current release exact ID·hash', '학생 결과 PDF에도 특기사항을 노출하지 않는다']) {
  if (['G4-IMP-06-S03 완료', 'IMP-06 3/5 진행', 'G4 6/7 진행', '다음 실행은 <code>G4-IMP-06-S04</code> C43 정정·stale 처리', 'current release exact ID·hash', '학생 결과 PDF에도 특기사항을 노출하지 않는다'].includes(marker)) continue;
  if (!indexDocument.includes(marker)) fail(`index.html: missing G4 IMP-05-S03 current status marker ${marker}`);
}
for (const marker of ['g4-imp-07-s01-result-print-projection.html', 'G4-IMP-07-S01 학생 결과 PDF 공개 Projection', 'G4-IMP-07-S01 완료', 'g4-imp-07-s02-result-pdf-job.html', 'G4-IMP-07-S02 학생 결과 PDF Job', 'G4-IMP-07-S02 완료', 'g4-imp-07-s03-result-pdf-access-freeze.html', 'G4-IMP-07-S03 학생 결과 PDF 접근 동결', 'G4-IMP-07-S03 완료', 'g4-imp-07-s04-result-pdf-ui-integration.html', 'G4-IMP-07-S04 학생 결과 PDF 화면·시각 통합', 'G4-IMP-07-S04 완료', 'IMP-07 4/4 완료', '다음 G5-IMP-01-S01 Q20 활동지별 보고서 목록', 'Q33→C52→Q23→Q24', '한글 A4 5 page', '학생 결과 PDF에도 특기사항을 노출하지 않는다']) {
  if ('학생 결과 PDF에도 특기사항을 노출하지 않는다' === marker) continue;
  if (!indexDocument.includes(marker)) fail(`index.html: missing G4 IMP-07-S04 current status marker ${marker}`);
}
if (!indexDocument.includes('특기사항을 노출하지 않는다')) fail('index.html: missing student-note non-disclosure invariant');

const g0Release = documents.get(path.join(docsRoot, 'g0-c28-i06-release-gate.html')).content;
for (const marker of [
  'G0 · C28-I06 · Release Gate',
  '추가 API 변경 없음',
  '1.0.0 → 1.1.0',
  '공개 symbol 37',
  '47개 script',
  '61a7252239d11d94329458bbefd77bb625962b6b645e782df476baf0fc4ed751',
  '9a5388c371368f9db03ff1ff73652abb259890a76dbf9becc4573b71797e89fd',
  'source_dirty=true',
  'source_dirty=false',
  '업그레이드 조건',
  '롤백 조건',
  'clean production build 대기',
  'G1 1차 기반 계약 통과',
]) {
  if (!g0Release.includes(marker)) fail(`g0-c28-i06-release-gate.html: missing release marker ${marker}`);
}

const g1Foundation = documents.get(path.join(docsRoot, 'g1-addon-foundation-implementation.html')).content;
for (const marker of [
  'G1 · Add-on Foundation · Implementation 01',
  'G1 1차 기반 계약 통과',
  'Database Foundation 계약 통과',
  'G1 전체 진행 중',
  '플랫폼 파일 변경 0건',
  'API 변경 없음',
  'oj_platform_register_addons',
  'addon_runtime_unavailable',
  'worksheet_quality.v1',
  '45개 무접두어 table',
  'Activation·Database 통합',
  'G1 구현 기록 01~08',
  'REST envelope</a></td><td><span class="badge good">계약 통과·경로 0개',
  'G1-REST-GAP-01',
  '95e6c99',
  '93개 source',
  'd83f8c12a30fe9d241523e3b147263d845078a8cf86179b97ea652088b1c2e22',
  '기준선 수용 보류',
]) {
  if (!g1Foundation.includes(marker)) fail(`g1-addon-foundation-implementation.html: missing implementation marker ${marker}`);
}

const g1Composition = documents.get(path.join(docsRoot, 'g1-composition-root-implementation.html')).content;
for (const marker of [
  'G1 · Add-on Foundation · Implementation 02',
  'Composition Root 계약 통과',
  'Platform_Runtime_Readiness_Port_Interface',
  '중복 부팅 방지',
  'legacy Runtime 미부팅',
  'Addon API <code>1.1.0</code>·37 symbol 유지',
  '45개 literal <code>oj_ai_*</code> table',
  '실제 asset 통합 인수만 보류',
]) {
  if (!g1Composition.includes(marker)) {
    fail(`g1-composition-root-implementation.html: missing implementation marker ${marker}`);
  }
}
const g1Database = documents.get(path.join(docsRoot, 'g1-database-foundation-implementation.html')).content;
for (const marker of [
  'G1 · Database Foundation · Implementation 03',
  '45개 무접두어 스키마',
  '45 table',
  '10 owner',
  'prefix 결합 0건',
  '202608140001_bootstrap_schema_ledger',
  '202608140002_create_frozen_schema',
  '202608220001_export_job_context_identity',
  '202608230001_expand_submission_prompt_contract',
  'A-DS05-IC01',
  'c55b775563273920fef127142e99e64321a07699e6a3f081579714dfb4a526c1',
  '31cee35952177177a1b36bd483f43ea1d6c1880c7d2a1e1ad891f896a41e32b1',
  'e49cb1fbcd726f4aae44fe4cb0ad952eabda49971420a7a2a398eee037fd6ee6',
  'Activation 후속 연결 완료',
  '운영 DB 실행 0건',
  '플랫폼 API 변경 없음',
  'DB infrastructure adapter',
]) {
  if (!g1Database.includes(marker)) {
    fail(`g1-database-foundation-implementation.html: missing implementation marker ${marker}`);
  }
}
const g1DatabaseInfrastructure = documents.get(path.join(docsRoot, 'g1-database-infrastructure-implementation.html')).content;
for (const marker of [
  'G1 · Database Infrastructure · Implementation 04',
  '설치와 명시 복구를 실행 가능한 DB 인프라로 만들었다',
  'oj_ai_dashboard_schema',
  'information_schema',
  'registration_pending',
  'maintenance_required',
  'schema_repair_authorization_invalid',
  '24시간 이내 backup evidence hash',
  '45 CREATE+2 ALTER',
  '기존 1.0.0',
  'upgraded',
  'Activation 연결 완료',
  'Activation 연결은 구현 기록 05에서 완료',
  '공개 API 변경 없음',
  '플랫폼 파일 변경 0건',
  'npm.cmd run check:database-infrastructure',
]) {
  if (!g1DatabaseInfrastructure.includes(marker)) {
    fail(`g1-database-infrastructure-implementation.html: missing implementation marker ${marker}`);
  }
}

const g1Activation = documents.get(path.join(docsRoot, 'g1-activation-database-integration.html')).content;
for (const marker of [
  'G1 · Activation and Database Integration · Implementation 05',
  '활성화가 검증된 스키마 설치를 거쳐 완료되도록 연결했다',
  'Schema_Installer_Port_Interface',
  'WordPress_Schema_Installation_Runner_Factory',
  'registration_pending',
  'addon_version_option_write_failed',
  '1.0.0·1.0.1 → 1.0.2 upgraded 결과',
  'raw SQL·예외 노출 0건',
  '$wpdb-&gt;prefix',
  '공개 Addon API <code>1.1.0</code>',
  'dev.35 병행 작업',
  'npm.cmd run check:activation-database',
]) {
  if (!g1Activation.includes(marker)) {
    fail(`g1-activation-database-integration.html: missing implementation marker ${marker}`);
  }
}

const g1RuntimeReadiness = documents.get(path.join(docsRoot, 'g1-runtime-schema-readiness.html')).content;
for (const marker of [
  'G1 · Runtime Schema Readiness · Implementation 06',
  'accepted onReady에서 스키마를 읽기 전용으로 검산한다',
  'Schema_Ledger_Reader_Port_Interface',
  'WordPress_Schema_Ledger_Reader',
  'registration_pending|runtime_ready',
  'schema_catalog_drift',
  'schema 1.0.2와 네 migration',
  'SELECT-only',
  '같은 boot 재시도 0건',
  '공개 API 변경 없음',
  '플랫폼 파일 변경 0건',
  '2.0.0-dev.36',
  'Addon API</td><td><code>1.1.0</code>',
  'npm.cmd run check:runtime-schema-readiness',
]) {
  if (!g1RuntimeReadiness.includes(marker)) {
    fail(`g1-runtime-schema-readiness.html: missing implementation marker ${marker}`);
  }
}

const g1CommonPolicy = documents.get(path.join(docsRoot, 'g1-common-access-policy.html')).content;
for (const marker of [
  'G1 · Common Access Policy · Implementation 07',
  'G1-IMP-07 완료',
  'capability + platform context + add-on object + state',
  'Capability 정확히 15개',
  'Access_Policy_Port_Interface',
  'WordPress_Actor_Authorization',
  'Platform_Context_Authorization',
  'Unavailable_Object_Authorization',
  'AddonCapabilityKeyV1',
  'AddonContextAccessRequestV1',
  'Page·REST·Job·Artifact 4개 surface parity',
  'oj_ai_authentication_required',
  'oj_ai_access_denied',
  'oj_ai_resource_not_found',
  'oj_ai_state_conflict',
  'oj_ai_runtime_unavailable',
  'npm.cmd run check:access-policy',
  '2.0.0-dev.38',
  'Addon API</td><td><code>1.1.0</code>',
  '공개 API 변경 없음',
  '플랫폼 파일 변경 0건',
  '완료 · REST envelope',
]) {
  if (!g1CommonPolicy.includes(marker)) {
    fail(`g1-common-access-policy.html: missing implementation marker ${marker}`);
  }
}

const g1CommonRest = documents.get(path.join(docsRoot, 'g1-common-rest-envelope.html')).content;
for (const marker of [
  'G1 · Common REST Envelope · Implementation 08',
  'G1-IMP-08 계약 통과',
  '현재 활성 REST 경로는 0개',
  'WordPress_Rest_Request_Adapter',
  'WordPress_Rest_Nonce_Verifier',
  'Correlation_Id_Factory',
  'Rest_Request_Authorizer',
  'Idempotency_Key',
  'Rest_Problem',
  'Rest_Envelope_Factory',
  'wp_rest',
  'oj_ai_validation_failed',
  'G1-REST-GAP-01',
  'QueryInputV1.cursor',
  '미완성 업무 경로를 fail closed',
  'npm.cmd run check:rest-foundation',
  '2.0.0-dev.38',
  'Addon API</td><td><code>1.1.0</code>',
  '플랫폼 source 수정</td><td>0건',
]) {
  if (!g1CommonRest.includes(marker)) {
    fail(`g1-common-rest-envelope.html: missing implementation marker ${marker}`);
  }
}

const g1ContextQ01 = documents.get(path.join(docsRoot, 'g1-context-list-q01.html')).content;
for (const marker of [
  'G1 · Teaching Context List · Implementation 09',
  'G1-IMP-09 계약 통과',
  '활성 REST 경로 1개',
  'G1-REST-GAP-01 해결',
  'QueryInputV1.Q01',
  'Q01 cursor 512자',
  'GET /contexts',
  "types=['class','group']",
  'Rest_Permission_Handoff',
  'Platform_Teaching_Context_Query',
  'Teaching_Context_Query_Port_Interface',
  '중복 stable ID·반복 cursor',
  'message·details',
  'npm run check:context-q01',
  '2.0.0-dev.38',
  'Addon API</td><td><code>1.1.0</code>',
  '플랫폼 source 수정</td><td>0건',
]) {
  if (!g1ContextQ01.includes(marker)) {
    fail(`g1-context-list-q01.html: missing implementation marker ${marker}`);
  }
}

const frozenBaseline = documents.get(path.join(docsRoot, 'a-d-009-baseline-gap-freeze.html')).content;
for (const marker of ['사용자 확정 2026-08-10', 'A-D-001~009</code> 전체 기준선을 동결']) {
  if (!frozenBaseline.includes(marker)) fail(`a-d-009-baseline-gap-freeze.html: missing freeze marker ${marker}`);
}

const scopeBaseline = documents.get(path.join(docsRoot, 'a-ds-00-scope-baseline.html')).content;
for (let decision = 1; decision <= 16; decision += 1) {
  const id = `A-DS00-D${String(decision).padStart(2, '0')}`;
  if (!scopeBaseline.includes(`<code>${id}</code>`)) fail(`a-ds-00-scope-baseline.html: missing decision ${id}`);
}
for (let success = 1; success <= 9; success += 1) {
  const id = `A-DS00-S${String(success).padStart(2, '0')}`;
  if (!scopeBaseline.includes(`<code>${id}</code>`)) fail(`a-ds-00-scope-baseline.html: missing success criterion ${id}`);
}
for (const term of [
  'WorksheetTemplate',
  'WorksheetDraftRevision',
  'PublishedWorksheetRevision',
  'WorksheetEvaluation',
  'AssignmentTargetSnapshot',
  'OfficialSubmissionRevision',
  'SubmissionEvaluation',
  'EvaluationPolicy',
  'RubricRevision',
  'ReviewDecision',
  'ReleaseRevision',
  'PdfArtifact',
]) {
  if (!scopeBaseline.includes(`<code>${term}</code>`)) fail(`a-ds-00-scope-baseline.html: missing canonical term ${term}`);
}
for (const marker of [
  'OJ Platform 필수 AI 평가 애드온',
  '학습 공간 &gt; AI 활동지',
  '학습 &gt; 활동지',
  'WordPress prefix를 붙이지 않은 고정 <code>oj_ai_*</code>',
  'A-DS-16 사용자 승인 전',
  '사용자 확정 2026-08-10',
]) {
  if (!scopeBaseline.includes(marker)) fail(`a-ds-00-scope-baseline.html: missing fixed scope marker ${marker}`);
}

const uiSystem = documents.get(path.join(docsRoot, 'a-ds-01-ui-system.html')).content;
for (let decision = 1; decision <= 19; decision += 1) {
  const id = `A-DS01-D${String(decision).padStart(2, '0')}`;
  if (!uiSystem.includes(`<code>${id}</code>`)) fail(`a-ds-01-ui-system.html: missing decision ${id}`);
}
for (let acceptance = 1; acceptance <= 12; acceptance += 1) {
  const id = `A-DS01-A${String(acceptance).padStart(2, '0')}`;
  if (!uiSystem.includes(`<code>${id}</code>`)) fail(`a-ds-01-ui-system.html: missing acceptance criterion ${id}`);
}
for (let screen = 1; screen <= 6; screen += 1) {
  const id = `A-DS01-C${String(screen).padStart(2, '0')}`;
  if (!uiSystem.includes(`<code>${id}</code>`)) fail(`a-ds-01-ui-system.html: missing Console screen ${id}`);
}
for (let screen = 1; screen <= 3; screen += 1) {
  const id = `A-DS01-P${String(screen).padStart(2, '0')}`;
  if (!uiSystem.includes(`<code>${id}</code>`)) fail(`a-ds-01-ui-system.html: missing PublicUI screen ${id}`);
}
for (let principle = 1; principle <= 8; principle += 1) {
  const id = `A-DS01-U${String(principle).padStart(2, '0')}`;
  if (!uiSystem.includes(`<code>${id}</code>`)) fail(`a-ds-01-ui-system.html: missing UX principle ${id}`);
}
for (const marker of [
  '교사 화면군 6',
  '학생 화면군 3',
  '<code>AI 활동지</code>, <code>활동지 배포</code>, <code>AI 평가</code>, <code>활동지 보고서</code>, <code>AI 평가 설정</code> 다섯 업무',
  '활동지별(기본)·수업·그룹별·학생별',
  '<code>활동지 → 배포 건 → 학생 제출</code>',
  '할 일·제출함·결과',
  'Console 직접 메뉴 5개',
  '학습 &gt; 활동지',
  'WCAG 2.2 AA',
  '응답칸 10종',
  'WorksheetEditorProfile',
  '1440×900, 1024×768, 768×1024, 390×844',
  '업무 runtime 변경 0건',
  '상태 사용자 확인·동결',
  '상세 설계 2/17 동결',
  'G4-IMP-06-S05 학생 결과 UI 동결 2026-08-24',
  '권한 회수 즉시 본문 제거',
  '1280×900 desktop과 390×844 mobile',
  'g4-imp-06-s05-student-result-ui-integration.html',
]) {
  if (!uiSystem.includes(marker)) fail(`a-ds-01-ui-system.html: missing fixed UI marker ${marker}`);
}

const addonLifecycle = documents.get(path.join(docsRoot, 'a-ds-02-addon-lifecycle.html')).content;
for (let decision = 1; decision <= 19; decision += 1) {
  const id = `A-DS02-D${String(decision).padStart(2, '0')}`;
  if (!addonLifecycle.includes(`<code>${id}</code>`)) fail(`a-ds-02-addon-lifecycle.html: missing decision ${id}`);
}
for (let acceptance = 1; acceptance <= 12; acceptance += 1) {
  const id = `A-DS02-A${String(acceptance).padStart(2, '0')}`;
  if (!addonLifecycle.includes(`<code>${id}</code>`)) fail(`a-ds-02-addon-lifecycle.html: missing acceptance criterion ${id}`);
}
for (const marker of [
  'oj_platform_register_addons',
  'AddonManifestBuilderV1::onReady()',
  'C28-API-V1-LOCK-01',
  'AddonRuntimeContextV1',
  'contexts()',
  'sourceKind=addon_payload',
  'addon.oj_ai_dashboard.console.worksheets',
  'addon.oj_ai_dashboard.public.worksheets',
  'addon.oj_ai_dashboard.worksheet_quality_assessment',
  'addon.oj_ai_dashboard.student_submission_assessment',
  'registration_pending',
  'runtime_unavailable',
  '고정 <code>oj_ai_*</code>',
  'C28-I01~I05',
  'C28-I06',
  'AM-01',
  '업무 Runtime 변경 0건',
  '사용자 확정 2026-08-10',
  '상세 설계 3/17 동결',
  'AM-01 사용자 확인·동결',
  'AM-01로 대체 확정 2026-08-12',
  'G5-IMP-05-S02 구현 인수',
  'AddonAiTaskGatewayV1',
  'G5-IMP-05-S03 구현 인수',
  '저장소 진단',
  'G5-IMP-05-S04 구현 인수',
  'G5-IMP-05-S04 구현 인수·자산 정정',
  'addon.oj_ai_dashboard.asset.settings',
  '자산 5개',
  '각 CSS·JS 목록 8개 이하',
  '401·403이면 기존 행을 지우고',
  'Platform API change: 0',
]) {
  if (!addonLifecycle.includes(marker)) fail(`a-ds-02-addon-lifecycle.html: missing lifecycle marker ${marker}`);
}

const apiProfile = documents.get(path.join(docsRoot, 'a-ds-02-addon-api-profile.html')).content;
for (let acceptance = 1; acceptance <= 10; acceptance += 1) {
  const id = `A-DS02-API-A${String(acceptance).padStart(2, '0')}`;
  if (!apiProfile.includes(`<code>${id}</code>`)) fail(`a-ds-02-addon-api-profile.html: missing acceptance criterion ${id}`);
}
for (const marker of [
  'oj_platform_register_addons',
  'AddonRegistrationGatewayV1',
  'AddonManifestBuilderV1::onReady()',
  'AddonRuntimeContextV1',
  'AddonContextGatewayV1',
  'AddonLearningContextGatewayV1',
  'AddonAssignmentRosterGatewayV1',
  'AddonAiTaskGatewayV1',
  'AddonAiTaskInstructionV1',
  'onReady(AddonRuntimeContextV1)',
  'sourceKind=addon_payload',
  'C28-API-V1-LOCK-01',
  'C28-I01~I06',
  'AM-01',
  'Addon API 1.1.0',
  '&gt;=2.0.0-dev.33 &lt;3.0.0',
  '&gt;=1.1.0 &lt;2.0.0',
  '@A_DS03_CAPABILITY_KEY@',
  'A-DS-02 사용자 확인·동결',
  'AM-01 사용자 확인·동결',
  'Asset Bundle</td><td>5',
  '22개 고정 기여',
  'CSS·JS 각 최대 8개 제한',
]) {
  if (!apiProfile.includes(marker)) fail(`a-ds-02-addon-api-profile.html: missing API marker ${marker}`);
}
for (const obsoletePattern of [
  "add_action('oj_platform_loaded'",
  'AddonRuntimeGatewayV1',
  'forAddon(',
]) {
  if (apiProfile.includes(obsoletePattern)) {
    fail(`a-ds-02-addon-api-profile.html: obsolete active API pattern found ${obsoletePattern}`);
  }
}
if (addonLifecycle.includes("add_action('oj_platform_loaded'")) {
  fail('a-ds-02-addon-lifecycle.html: obsolete Runtime action code sample found');
}

const amendment = documents.get(path.join(docsRoot, 'a-ds-02-am-01-onready-adoption.html')).content;
for (let decision = 1; decision <= 10; decision += 1) {
  const id = `A-DS02-AM01-D${String(decision).padStart(2, '0')}`;
  if (!amendment.includes(`<code>${id}</code>`)) fail(`a-ds-02-am-01-onready-adoption.html: missing decision ${id}`);
}
for (let acceptance = 1; acceptance <= 12; acceptance += 1) {
  const id = `A-DS02-AM01-A${String(acceptance).padStart(2, '0')}`;
  if (!amendment.includes(`<code>${id}</code>`)) fail(`a-ds-02-am-01-onready-adoption.html: missing acceptance criterion ${id}`);
}
for (const marker of [
  'AddonManifestBuilderV1::onReady(callable)',
  'AddonRuntimeContextV1',
  'addon_namespace_reserved',
  'AiTaskRoutingService',
  'StoredAiTaskRouter',
  'C28-I01~I05',
  'C28-I06',
  'OJ_PLATFORM_ADDON_API_VERSION=1.1.0',
  '&gt;=1.1.0 &lt;2.0.0',
  '2.0.0-dev.33',
  '370파일',
  'Runtime 변경 0건',
  '상태 사용자 확인·동결',
  '사용자 확정 2026-08-12',
]) {
  if (!amendment.includes(marker)) fail(`a-ds-02-am-01-onready-adoption.html: missing amendment marker ${marker}`);
}
const frozenAmendmentDecisionCount = (amendment.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenAmendmentDecisionCount !== 10) {
  fail(`a-ds-02-am-01-onready-adoption.html: expected 10 frozen decisions, found ${frozenAmendmentDecisionCount}`);
}
if (amendment.includes('확인 대기')) {
  fail('a-ds-02-am-01-onready-adoption.html: pending approval marker remains after freeze');
}
for (const obsoletePattern of [
  "add_action('oj_platform_loaded'",
  'use CodingPanda\\OjPlatform\\AddonApi\\V1\\AddonRuntimeGatewayV1;',
  "$gateway-&gt;forAddon('oj-ai-dashboard')",
]) {
  if (amendment.includes(obsoletePattern)) {
    fail(`a-ds-02-am-01-onready-adoption.html: obsolete executable pattern found ${obsoletePattern}`);
  }
}

const accessPolicy = documents.get(path.join(docsRoot, 'a-ds-03-access-policy.html')).content;
for (let capability = 1; capability <= 15; capability += 1) {
  const id = 'A-DS03-CAP' + String(capability).padStart(2, '0');
  if (!accessPolicy.includes('<code>' + id + '</code>')) fail('a-ds-03-access-policy.html: missing capability ' + id);
}
for (let decision = 1; decision <= 16; decision += 1) {
  const id = 'A-DS03-D' + String(decision).padStart(2, '0');
  if (!accessPolicy.includes('<code>' + id + '</code>')) fail('a-ds-03-access-policy.html: missing decision ' + id);
}
for (let acceptance = 1; acceptance <= 14; acceptance += 1) {
  const id = 'A-DS03-A' + String(acceptance).padStart(2, '0');
  if (!accessPolicy.includes('<code>' + id + '</code>')) fail('a-ds-03-access-policy.html: missing acceptance criterion ' + id);
}
for (const marker of [
  '정확히 15개',
  'oj_ai_dashboard_view_workspace',
  'oj_ai_dashboard_manage_migrations',
  'oj_ai_dashboard_participate',
  'oj_access_console',
  'listTeachingContexts',
  'class|group',
  'view|assign|evaluate|report|manage|participate',
  'authorization_envelope',
  'Job은 권한 주체가 아니다',
  'capability + platform context + add-on object + state',
  '상태 사용자 확인·동결',
  '사용자 확정 2026-08-12',
  'A-DS-02·AM-01 사용자 확인·동결',
  'AM-01 동결 입력 2026-08-12',
  '상세 설계 4/17 동결',
  'Runtime 변경 0건',
  'AM-01과 플랫폼 공백 보상 정책',
  'AiTaskRoutingService',
  'StoredAiTaskRouter',
]) {
  if (!accessPolicy.includes(marker)) fail('a-ds-03-access-policy.html: missing access marker ' + marker);
}
const frozenAccessDecisionCount = (accessPolicy.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenAccessDecisionCount !== 16) {
  fail(`a-ds-03-access-policy.html: expected 16 frozen decisions, found ${frozenAccessDecisionCount}`);
}
if (accessPolicy.includes('확인 대기')) {
  fail('a-ds-03-access-policy.html: pending approval marker remains after freeze');
}

const settingsPolicy = documents.get(path.join(docsRoot, 'a-ds-04-settings-ai-connection.html')).content;
for (let decision = 1; decision <= 18; decision += 1) {
  const id = 'A-DS04-D' + String(decision).padStart(2, '0');
  if (!settingsPolicy.includes('<code>' + id + '</code>')) fail('a-ds-04-settings-ai-connection.html: missing decision ' + id);
}
for (let acceptance = 1; acceptance <= 14; acceptance += 1) {
  const id = 'A-DS04-A' + String(acceptance).padStart(2, '0');
  if (!settingsPolicy.includes('<code>' + id + '</code>')) fail('a-ds-04-settings-ai-connection.html: missing acceptance criterion ' + id);
}
for (const marker of [
  '플랫폼 소유 provider profile·비밀값·model routing과 애드온 소유 평가 정책·루브릭·검토 기준',
  '평가 정책',
  '기본 루브릭',
  '검토·승인 기준',
  '연결 및 진단',
  'addon.oj_ai_dashboard.worksheet_quality_assessment',
  'addon.oj_ai_dashboard.student_submission_assessment',
  'canRoute()',
  'snapshot()',
  'execute()',
  'provider secret·endpoint·raw client',
  'review_required=true',
  'explicit_release_required=true',
  'draft → active → retired',
  'oj_ai_dashboard_manage_policies',
  'ai_provider',
  'ai_model',
  '고정 <code>oj_ai_*</code>',
  'A-DS-03 사용자 확인·동결',
  '상태 사용자 확인·동결',
  '사용자 확정 2026-08-12',
  '상세 설계 5/17 동결',
  'Runtime 변경 0건',
  'G4-IMP-02-R01 연결 및 진단 구현',
  '활동지 평가',
  '학생 제출 평가',
  'Q38/C78/C79',
  'G5-IMP-05-S02 AI routing 인수',
  '다시 확인 필요',
  'G5-IMP-05-S03 비밀 보호 인수',
  'Q28 전체 projection',
  'G5-IMP-05-S04 운영 화면 인수',
  'code·details·issues·path·metrics와 task 내부의 다른 값',
  '0.1.15 구현 확인',
]) {
  if (!settingsPolicy.includes(marker)) fail('a-ds-04-settings-ai-connection.html: missing settings marker ' + marker);
}
const frozenSettingsDecisionCount = (settingsPolicy.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenSettingsDecisionCount !== 18) {
  fail(`a-ds-04-settings-ai-connection.html: expected 18 frozen decisions, found ${frozenSettingsDecisionCount}`);
}
if (settingsPolicy.includes('확인 대기')) {
  fail('a-ds-04-settings-ai-connection.html: pending approval marker remains after freeze');
}

const databaseDesign = documents.get(path.join(docsRoot, 'a-ds-05-addon-database.html')).content;
for (let decision = 1; decision <= 24; decision += 1) {
  const id = 'A-DS05-D' + String(decision).padStart(2, '0');
  if (!databaseDesign.includes('<code>' + id + '</code>')) fail('a-ds-05-addon-database.html: missing decision ' + id);
}
for (let acceptance = 1; acceptance <= 18; acceptance += 1) {
  const id = 'A-DS05-A' + String(acceptance).padStart(2, '0');
  if (!databaseDesign.includes('<code>' + id + '</code>')) fail('a-ds-05-addon-database.html: missing acceptance criterion ' + id);
}
const targetTables = [...databaseDesign.matchAll(/<tr><td><code>(oj_ai_[a-z0-9_]+)<\/code><\/td><td><code>PK/g)].map((match) => match[1]);
if (targetTables.length !== 45 || new Set(targetTables).size !== 45) {
  fail(`a-ds-05-addon-database.html: expected 45 unique catalog tables, found ${targetTables.length}/${new Set(targetTables).size}`);
}
for (const marker of [
  '목표 table 45개',
  'owner 10개',
  '고정 <code>oj_ai_*</code>',
  '{당시 WordPress prefix}oj_ai_*',
  'A-DS07-DB01~DB03',
  'oj_ai_worksheet_evaluations',
  'oj_ai_submission_evaluations',
  'oj_ai_evaluation_runs',
  'oj_ai_private_files',
  'oj_ai_schema_migrations',
  'ON DELETE RESTRICT ON UPDATE RESTRICT',
  '활동지 자체 평가',
  '학생 제출 평가',
  'raw provider response는 저장하지 않음',
  '학년·학급·그룹·학생·담당 master table',
  'A-DS-04 사용자 확인·동결',
  '상태 사용자 확정·동결',
  '확정일 2026-08-13',
  '상세 설계 6/17',
  'Runtime 변경 0건',
  'A-DS-05 Amendment 09 · 구현 확정',
  'A-DS05-AM09-01',
  'A-DS05-AM09-04',
  'A-DS-05 Amendment 10 · 구현 확정',
  'A-DS05-AM10-01',
  'A-DS05-AM10-05',
  '202608220001_export_job_context_identity',
  '202608230001_expand_submission_prompt_contract',
  '31cee35952177177a1b36bd483f43ea1d6c1880c7d2a1e1ad891f896a41e32b1',
  'e49cb1fbcd726f4aae44fe4cb0ad952eabda49971420a7a2a398eee037fd6ee6',
  'G5-IMP-02-S01 snapshot 저장 구현 증거 2026-08-25',
  'G5-IMP-02-S02 snapshot 조회 구현 증거 2026-08-25',
  'G5-IMP-02-S03 source manifest 구현 증거 2026-08-25',
  'G5-IMP-02-S04 snapshot 통합 동결 증거 2026-08-25',
  'G5-IMP-03-S01 export job 구현 증거 2026-08-25',
  'G5-IMP-03-S02 C50 저장 구현 증거 2026-08-25',
  'G5-IMP-03-S03 C69 저장 구현 증거 2026-08-25',
  'oj_ai_report_snapshots',
  'oj_ai_export_jobs',
  'report_snapshot_id=NULL',
  'source_type=assignment',
  'snapshot UPDATE·신규 table·DDL·migration·WordPress prefix 사용은 0건',
  'literal <code>oj_ai_report_snapshots</code>를 SELECT만',
  '신규 table·DDL·migration·UPDATE·WordPress prefix 사용은 0건',
  'snapshot UPDATE·DELETE·신규 table·column·DDL·migration은 0건',
  'schema 1.0.2 append-only 전진',
  'IMP-02 4/4 완료',
  'C46·C47·C50·C69 기존 export job 등록 완료',
  'artifact_kind=report_json_package',
  'IMP-03 3/6 진행',
  '다음 G5-IMP-03-S04 Q23·Q24 job·download',
]) {
  if (['C46·C47·C50·C69 기존 export job 등록 완료', 'IMP-03 3/6 진행', '다음 G5-IMP-03-S04 Q23·Q24 job·download'].includes(marker)) continue;
  if (!databaseDesign.includes(marker)) fail('a-ds-05-addon-database.html: missing database marker ' + marker);
}
for (const marker of ['G5-IMP-03-S04 artifact 저장 구현 증거 2026-08-25', 'G5-IMP-03-S05 작업 제어 저장 증거 2026-08-25', 'G5-IMP-03-S06 저장 통합 동결 증거 2026-08-25', 'G5-IMP-05-S03 저장소 진단 증거 2026-08-25', 'literal <code>oj_ai_private_files</code>', '신규 table·column·DDL·migration·write는 0건']) {
  if (!databaseDesign.includes(marker)) fail('a-ds-05-addon-database.html: missing G5 IMP-03-S06 database marker ' + marker);
}
const frozenDatabaseDecisionCount = (databaseDesign.match(/<td>사용자 확정<\/td><\/tr>/g) ?? []).length;
if (frozenDatabaseDecisionCount !== 24) {
  fail('a-ds-05-addon-database.html: expected 24 frozen decisions, found ' + frozenDatabaseDecisionCount);
}
if (databaseDesign.includes('확인 대기')) {
  fail('a-ds-05-addon-database.html: pending approval marker remains after freeze');
}

const platformLedgerDesign = documents.get(path.join(docsRoot, 'a-ds-06-platform-ledger-connector.html')).content;
for (let decision = 1; decision <= 24; decision += 1) {
  const id = 'A-DS06-D' + String(decision).padStart(2, '0');
  if (!platformLedgerDesign.includes('<code>' + id + '</code>')) {
    fail('a-ds-06-platform-ledger-connector.html: missing decision ' + id);
  }
}
for (let acceptance = 1; acceptance <= 18; acceptance += 1) {
  const id = 'A-DS06-A' + String(acceptance).padStart(2, '0');
  if (!platformLedgerDesign.includes('<code>' + id + '</code>')) {
    fail('a-ds-06-platform-ledger-connector.html: missing acceptance criterion ' + id);
  }
}
for (const marker of [
  'AddonRuntimeContextV1::contexts()',
  'AddonContextGatewayV1',
  'listTeachingContexts()',
  'authorize()',
  'snapshot()',
  'class|group',
  'AddonLearningContextGatewayV1',
  'listLearningContexts',
  'learner_context_api_required',
  'C28-I06-CG01~CG11',
  'C28-I06-CG10',
  'C28-I06-CG11',
  'AddonAssignmentRosterGatewayV1',
  'listAssignmentLearners',
  'rosterRevision(): ?string;',
  'CG11-PAGE-INV-01',
  'Addendum 01 · A-DS-11 정합화',
  'expected revision 불일치·provider 예외 공통',
  'Oj_Table_Resolver',
  'OJ problem·Judge submission',
  'A-DS-05 사용자 확정·동결',
  '상태 사용자 확정·동결',
  '상세 설계 7/17 동결',
  'Runtime 변경 0건',
  'A-DS-16 사용자 승인 전',
]) {
  if (!platformLedgerDesign.includes(marker)) {
    fail('a-ds-06-platform-ledger-connector.html: missing platform ledger marker ' + marker);
  }
}
const frozenPlatformLedgerDecisionCount = (platformLedgerDesign.match(/<td>사용자 확정<\/td><\/tr>/g) ?? []).length;
if (frozenPlatformLedgerDecisionCount !== 24) {
  fail('a-ds-06-platform-ledger-connector.html: expected 24 frozen decisions, found ' + frozenPlatformLedgerDecisionCount);
}
if (platformLedgerDesign.includes('확인 대기')) {
  fail('a-ds-06-platform-ledger-connector.html: pending approval marker remains after freeze');
}

const aiJobDesign = documents.get(path.join(docsRoot, 'a-ds-07-ai-job-execution.html')).content;
for (let decision = 1; decision <= 24; decision += 1) {
  const id = 'A-DS07-D' + String(decision).padStart(2, '0');
  if (!aiJobDesign.includes('<code>' + id + '</code>')) {
    fail('a-ds-07-ai-job-execution.html: missing decision ' + id);
  }
}
for (let acceptance = 1; acceptance <= 18; acceptance += 1) {
  const id = 'A-DS07-A' + String(acceptance).padStart(2, '0');
  if (!aiJobDesign.includes('<code>' + id + '</code>')) {
    fail('a-ds-07-ai-job-execution.html: missing acceptance criterion ' + id);
  }
}
for (const marker of [
  'AddonRuntimeContextV1::aiTasks()',
  'AddonAiTaskGatewayV1',
  'addon.oj_ai_dashboard.worksheet_quality_assessment',
  'addon.oj_ai_dashboard.student_submission_assessment',
  'sourceKind',
  'platform_submission',
  '별도 profile 승인 조건이 아니다',
  'oj_ai_evaluation_runs',
  'oj_ai_evaluation_attempts',
  'WP-Cron',
  'WP-CLI',
  '배포 환경에서 검증한 1~4 범위 override',
  '화면·DB option으로 노출하지 않음',
  'at-least-once',
  'exactly-once',
  '300초',
  '최대 3 attempts',
  '24시간',
  'routing_changed_during_execute',
  'measurement=unavailable',
  'raw provider response는 저장하지 않는다',
  'C28-I06-AJ01~AJ08',
  'C28-I06-AJ07',
  'C28-I06-AJ08',
  'fresh <code>snapshot(taskKey)</code>',
  'A-DS07-DB01~DB03',
  'A-DS-06 사용자 확정·동결',
  '상태 사용자 확인·동결',
  '확정일 2026-08-13',
  '상세 설계 8/17',
  'Runtime 변경 0건',
  'G4-IMP-02-S01 구현 인수',
  'g4-imp-02-s01-evaluation-queue-lease.html',
  'G4-IMP-02-S02 실행 코어 인수',
  'g4-imp-02-s02-gateway-output-result.html',
  'G4-IMP-02-R01 활성화 인수',
  'G4-IMP-02-S05 통합 인수',
  'G5-IMP-05-S01 큐 진단 인수',
  'G5-IMP-05-S02 routing 진단 인수',
  '<code>execute()</code> 호출은 0회',
  '활동지 평가·제출 평가·피드백·특기사항 네 1분 sweep',
  'worker는 Composition Root에 등록됐다',
  'A-DS-16 사용자 승인 전',
]) {
  if (!aiJobDesign.includes(marker)) {
    fail('a-ds-07-ai-job-execution.html: missing AI job marker ' + marker);
  }
}
const frozenAiJobDecisionCount = (aiJobDesign.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenAiJobDecisionCount !== 24) {
  fail('a-ds-07-ai-job-execution.html: expected 24 frozen decisions, found ' + frozenAiJobDecisionCount);
}
if (aiJobDesign.includes('<td>확인 대기</td></tr>')) {
  fail('a-ds-07-ai-job-execution.html: pending decision remains after freeze');
}

const routeRestDesign = documents.get(path.join(docsRoot, 'a-ds-08-route-rest-command.html')).content;
for (let page = 1; page <= 9; page += 1) {
  const id = 'A-DS08-PG' + String(page).padStart(2, '0');
  if (!routeRestDesign.includes('<code>' + id + '</code>')) {
    fail('a-ds-08-route-rest-command.html: missing Page Route ' + id);
  }
}
for (let query = 1; query <= 40; query += 1) {
  const id = 'A-DS08-Q' + String(query).padStart(2, '0');
  const count = (routeRestDesign.match(new RegExp('<code>' + id + '<\\/code>', 'g')) ?? []).length;
  if (count !== 1) fail('a-ds-08-route-rest-command.html: expected one query ' + id + ', found ' + count);
}
for (let command = 1; command <= 79; command += 1) {
  const id = 'A-DS08-C' + String(command).padStart(2, '0');
  const count = (routeRestDesign.match(new RegExp('<code>' + id + '<\\/code>', 'g')) ?? []).length;
  if (count !== 1) fail('a-ds-08-route-rest-command.html: expected one command ' + id + ', found ' + count);
}
for (let acceptance = 1; acceptance <= 20; acceptance += 1) {
  const id = 'A-DS08-A' + String(acceptance).padStart(2, '0');
  if (!routeRestDesign.includes('<code>' + id + '</code>')) {
    fail('a-ds-08-route-rest-command.html: missing acceptance criterion ' + id);
  }
}
for (let decision = 1; decision <= 24; decision += 1) {
  const id = 'A-DS08-D' + String(decision).padStart(2, '0');
  if (!routeRestDesign.includes('<code>' + id + '</code>')) {
    fail('a-ds-08-route-rest-command.html: missing decision ' + id);
  }
}
for (let amendment = 1; amendment <= 4; amendment += 1) {
  const id = 'A-DS08-DBA' + String(amendment).padStart(2, '0');
  if (!routeRestDesign.includes('<code>' + id + '</code>')) {
    fail('a-ds-08-route-rest-command.html: missing database amendment ' + id);
  }
}
for (let gap = 1; gap <= 5; gap += 1) {
  const id = 'C28-I06-RC' + String(gap).padStart(2, '0');
  if (!routeRestDesign.includes('<code>' + id + '</code>')) {
    fail('a-ds-08-route-rest-command.html: missing platform gap ' + id);
  }
}
for (const marker of [
  '상태 구현 동결',
  '확정일 2026-08-13',
  'Detailed Design 9 / 17',
  'Page Route 9',
  'REST 조회 40',
  'REST 명령 79',
  'oj-ai/v1',
  "rest_url(&#39;oj-ai/v1&#39;)",
  'X-WP-Nonce',
  'wp_rest',
  'Idempotency-Key',
  'AuthorizationEnvelopeV1',
  'ExpectedStateV1.C02',
  'expected_revision_id=PublicIdV1',
  'CommandInputV1.C02',
  'G2-IMP-02-S06 C02 명확화',
  'oj_access_console',
  'oj_ai_dashboard_participate',
  'AddonCapabilityKeyV1',
  'public static function wordpress(string $declaredKey): string',
  'oj_ai_student_note_revisions',
  '44개 → 45개',
  'A-DS09-RA01~RA04',
  'A-DS11-RA01~RA03',
  'A-DS08-Q39',
  'A-DS08-Q40',
  '/submission-evaluation-candidates',
  'amendment-26',
  'A-DS08-Q35',
  'A-DS08-C67',
  'C67 구현 동결',
  'g3-imp-08-s03-learner-submission-pdf.html',
  'expected_attachment_set_hash',
  'expected_official_revision_hash',
  'teacher_submission',
  'self_submission',
  'A-DS08-C68',
  'A-DS08-C69',
  'A-DS08-Q36',
  'A-DS08-C70',
  'A-DS08-C71',
  'A-DS08-C72',
  'A-DS08-C73',
  'A-DS08-C74',
  'A-DS08-C75',
  'A-DS-08 Amendment 02 · 사용자 확인·동결',
  'A-DS08-Q37',
  'A-DS08-C76',
  'A-DS08-C77',
  'A-DS-08 Amendment 06 · G2 R01 구현 동결',
  'A-DS08-Q38',
  'A-DS08-C78',
  'A-DS08-C79',
  'A-DS-08 Amendment 07 · G4 R01 구현 동결',
  'A-DS-08 Amendment 04 · 사용자 확인·동결',
  'A-DS-08 Amendment 05 · 사용자 확인·동결',
  'delete_plugins',
  'preview_token',
  '최대 4 KiB',
  '활동지 → 배포 → 제출',
  'EnqueueWorksheetEvaluation',
  'EnqueueSubmissionEvaluation',
  'EnqueueStudentNoteDraft',
  'EnqueueOwnWorksheetPdf',
  'EnqueueOwnResultPdf',
  'G4 R01 Runtime 구현 완료',
  'G4-IMP-01-S03 C29 구현 증거 2026-08-23',
  'G4-IMP-01-S04 C30 구현 증거 2026-08-23',
  'G4-IMP-01-S05 종합 동결 증거 2026-08-23',
  'G4-IMP-02-S03 Q16·Q17 구현 증거 2026-08-23',
  'G4-IMP-02-S04 C31·C32 구현 증거 2026-08-23',
  'G4-IMP-02-S05 통합 동결 증거 2026-08-23',
  'G4-IMP-03-S01·S02 C34 구현 증거 2026-08-23',
  'G4-IMP-03-S03 검토 UI 증거 2026-08-23',
  'G4-IMP-04-S01 C35 구현 증거 2026-08-24',
  'G4-IMP-04-S02 C36 구현 증거 2026-08-24',
  'G4-IMP-04-S03 C37 구현 증거 2026-08-24',
  'G4-IMP-04-S04 UI·통합 동결 증거 2026-08-24',
  'G4-IMP-05-S01 C38 구현 증거 2026-08-24',
  'G4-IMP-05-S02 Q18·Q19 구현 증거 2026-08-24',
  'G4-IMP-05-S03 C39·C40 구현 증거 2026-08-24',
  'G4-IMP-05-S04 C41·C42 구현 증거 2026-08-24',
  'g4-imp-05-s04-student-note-report-inclusion.html',
  'G4-IMP-05-S05 provenance·권한 통합 동결 증거 2026-08-24',
  'g4-imp-05-s05-student-note-integration-freeze.html',
  'student note object Policy',
  'G4-IMP-06-S01 C43 구현 증거 2026-08-24',
  'G4-IMP-06-S02 Q33 구현 증거 2026-08-24',
  'release <code>self_view</code>',
  'manage_student_notes</code>+report 권한',
  'G4-IMP-06-S03 C44 구현 증거 2026-08-24',
  'G4-IMP-06-S03 완료',
  'G4-IMP-06-S04 C43 구현 증거 2026-08-24',
  'submission_result.corrected',
  'G4-IMP-06-S05 PublicUI 구현 증거 2026-08-24',
  'Student_Result_Page_Handler',
  'G4-IMP-07-S01 result_print 구현 증거 2026-08-24',
  'G4-IMP-07-S02 C52 활성 증거 2026-08-24',
  'G4-IMP-07-S03 Q23·Q24 동결 증거 2026-08-24',
  'G4-IMP-07-S04 화면 연결 증거 2026-08-24',
  'G5-IMP-01-S01 Q20 활성 증거 2026-08-24',
  'G5-IMP-01-S02 Q21 활성 증거 2026-08-24',
  'G5-IMP-01-S03 filter·분모 활성 증거 2026-08-24',
  'G5-IMP-01-S04 보고서 화면 연결 증거 2026-08-24',
  'G5-IMP-02-S01 C45 활성 증거 2026-08-25',
  'G5-IMP-02-S02 Q22 활성 증거 2026-08-25',
  'G5-IMP-02-S03 내부 계보 증거 2026-08-25',
  'G5-IMP-02-S04 통합 동결 증거 2026-08-25',
  'G5-IMP-03-S01 C46·C47 활성 증거 2026-08-25',
  'G5-IMP-03-S02 C50 활성 증거 2026-08-25',
  'G5-IMP-03-S03 C69 활성 증거 2026-08-25',
  'POST /oj-ai/v1/reports/commands/create-snapshot',
  'GET /oj-ai/v1/report-snapshots/{snapshot_id}',
  'POST /oj-ai/v1/assignments/{assignment_id}/commands/export-submissions-zip',
  'POST /oj-ai/v1/report-snapshots/{snapshot_id}/commands/export-json-package',
  '두 번 수집해 manifest·items hash',
  '모든 활동지 source 권한을 다시 확인',
  'report_source_stale',
  'C46·C47·C50·C69 strict enqueue 활성',
  'IMP-03 3/6 진행',
  '다음 G5-IMP-03-S04 Q23·Q24 job·download',
  'strict <code>command_id/expected/payload</code>',
  'C52는 이미 정본 명령에 포함',
  '실제 stream 직전에 같은 actor·artifact를 다시 인가',
  'Page 9·GET 38·POST 79와 공개 Addon API는 유지',
  '새 route 없이 Page 9·GET 38·POST 79를 유지',
  'body-free outbox',
  'C29~C44·C52와 Q33',
  'C37 영역',
  '승인 도중 피드백 변경 금지',
  'expected_feedback_revision_hash',
  'source_mode',
  'review_precondition',
  'g4-imp-02-s04-submission-evaluation-control.html',
  'g4-imp-02-s05-submission-evaluation-integration-freeze.html',
  'g4-imp-01-s03-single-evaluation-enqueue.html',
  'g4-imp-01-s04-assignment-evaluation-enqueue.html',
  'g4-imp-01-s05-idempotency-authorization-freeze.html',
  'A-DS-16 승인 전',
]) {
  if (['C46·C47·C50·C69 strict enqueue 활성', 'IMP-03 3/6 진행', '다음 G5-IMP-03-S04 Q23·Q24 job·download'].includes(marker)) continue;
  if (!routeRestDesign.includes(marker)) {
    fail('a-ds-08-route-rest-command.html: missing route/REST marker ' + marker);
  }
}
for (const marker of ['G5-IMP-03-S04 Q23·Q24 활성 증거 2026-08-25', 'G5-IMP-03-S05 C53·C54 활성 증거 2026-08-25', 'G5-IMP-03-S06 Q23·Q24 stream 동결 증거 2026-08-25', 'G5-IMP-05-S01 Q28 구현 인수', 'G5-IMP-05-S02 Q28 구현 인수', 'G5-IMP-05-S03 Q28 구현 인수', 'G5-IMP-05-S04 운영 화면 인수', 'oj_ai.diagnostics@1.0.0', 'G5-IMP-07-S01 C61 구현 인수', 'POST /oj-ai/v1/migration-runs/commands/preview', 'mutation 0', 'G5-IMP-07-S02 C70 구현 인수', 'g5-imp-07-s02-legacy-migration-approval.html', 'POST /oj-ai/v1/migration-runs/commands/approve', '최초 201', 'replay', 'G5-IMP-07-S03-D3-D2 구현 완료', 'check:legacy-migration-mapping-g5']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing G5 IMP-07-S03-D3-D2 route/REST marker ' + marker);
}
for (const marker of ['Amendment 09', 'G5-IMP-07-S03-D3-D3 구현 완료', 'D3-D4 assignment item transaction', 'Page 9·조회 38·명령 79']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing D3-D3 no-REST-change marker ' + marker);
}
for (const marker of ['Amendment 10', 'G5-IMP-07-S03-D3-D4 구현 완료', 'oj_ai.legacy_migration_plan@1.2.0', 'assignment_source_ref', 'check:legacy-migration-assignment-g5', 'G5-IMP-07-S03-D3-D5', 'Platform API change: 0']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing D3-D4 compatible API extension marker ' + marker);
}
for (const marker of ['Amendment 11', 'G5-IMP-07-S03-D3-D5 구현 완료', 'check:legacy-migration-submission-g5', 'Page 9·조회 38·명령 79', 'D3-D6 evaluation lineage', 'Platform API change: 0']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing D3-D5 no-REST-change marker ' + marker);
}
for (const marker of ['Amendment 12', 'G5-IMP-07-S03-D3-D6 구현 완료', 'check:legacy-migration-evaluation-g5', 'Page 9·조회 38·명령 79', 'D3-D7 private file safety', 'Platform API change: 0']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing D3-D6 no-REST-change marker ' + marker);
}
const frozenRouteRestDecisionCount = (routeRestDesign.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
const implementedRouteRestDecisionCount = (routeRestDesign.match(/<td>G4 R01 구현 동결<\/td><\/tr>/g) ?? []).length;
if (frozenRouteRestDecisionCount !== 23 || implementedRouteRestDecisionCount !== 1) {
  fail('a-ds-08-route-rest-command.html: expected 23 user-frozen and one G4 R01 implementation-frozen decision');
}
if (routeRestDesign.includes('<td>확인 대기</td></tr>')) {
  fail('a-ds-08-route-rest-command.html: pending decision remains after freeze');
}
const querySection = routeRestDesign.slice(routeRestDesign.indexOf('id="queries"'), routeRestDesign.indexOf('id="commands"'));
const commandSection = routeRestDesign.slice(routeRestDesign.indexOf('id="commands"'), routeRestDesign.indexOf('id="dto"'));
const getPaths = [...querySection.matchAll(/<td><code>(\/[^<]+)<\/code><\/td>/g)].map((match) => match[1]);
const postPaths = [...commandSection.matchAll(/<td><code>(\/[^<]+)<\/code><\/td>/g)].map((match) => match[1]);
if (getPaths.length !== 40 || new Set(getPaths).size !== 40) {
  fail('a-ds-08-route-rest-command.html: GET paths must be 40 unique entries');
}
if (postPaths.length !== 79 || new Set(postPaths).size !== 79) {
  fail('a-ds-08-route-rest-command.html: POST paths must be 79 unique entries');
}


const worksheetAuthoring = documents.get(path.join(docsRoot, 'a-ds-09-worksheet-authoring-version.html')).content;
for (let baseline = 1; baseline <= 6; baseline += 1) {
  const id = 'A-DS09-B' + String(baseline).padStart(2, '0');
  if (!worksheetAuthoring.includes('<code>' + id + '</code>')) {
    fail('a-ds-09-worksheet-authoring-version.html: missing implementation baseline ' + id);
  }
}
for (const [prefix, expected] of [['N', 16], ['R', 10], ['L', 12], ['P', 5], ['RA', 4], ['DBA', 3], ['A', 24], ['D', 24]]) {
  for (let number = 1; number <= expected; number += 1) {
    const id = 'A-DS09-' + prefix + String(number).padStart(2, '0');
    const count = (worksheetAuthoring.match(new RegExp('<code>' + id + '<\/code>', 'g')) ?? []).length;
    if (count !== 1) {
      fail('a-ds-09-worksheet-authoring-version.html: expected one ' + id + ', found ' + count);
    }
  }
}
for (const marker of [
  'Detailed Design 10 / 17',
  '상태 사용자 확인·동결',
  '확정일 2026-08-13',
  'Plate 53.1.2 기준선',
  'worksheet_plate@1',
  'oj_ai.worksheet_document@1.0.0',
  '콘텐츠 node 16종',
  '응답칸 10종',
  'Projection 5종',
  'oj_ai_response',
  'hws_response',
  'PublicIdV1',
  'id="c02-s02-contract"',
  'expected.expected_revision_id',
  'G2-IMP-02-S02 구현 증거 2026-08-20',
  'G2-IMP-02-S03 구현 증거 2026-08-20',
  'G2-IMP-02-S04 구현 증거 2026-08-20',
  'G2-IMP-02-S05 구현 증거 2026-08-20',
  'Canonical document',
  'UTF-8 JSON 2 MiB',
  '5,000개',
  'oj_ai.student_document@1.0.0',
  'oj_ai.student_response_schema@1.0.0',
  'oj_ai.grading_schema@1.0.0',
  'oj_ai.grading_schema@1.1.0',
  'A-DS-09 Projection Amendment 01 · 사용자 확인·동결',
  'A-DS12-PA01',
  'oj_ai.worksheet_evaluation_input@1.0.0',
  'oj_ai.worksheet_print@1.0.0',
  'application/vnd.oj.ai-worksheet+zip',
  'worksheet.ojws',
  'GET /worksheet-assets/{asset_id}/content',
  'source_type=revision|publication|staged',
  'A-DS-05 Database Amendment 02 · 사용자 확인·동결',
  'document_hash CHAR(64)',
  'publication_hash CHAR(64)',
  'JSON(request_snapshot)',
  'table 수는 45개, owner는 10개',
  '조회는 34개, 명령은 66개',
  'A-DS-08 Amendment 01',
  'OJ Platform API 추가 요구가 아니다',
  'Runtime 변경 0건',
  'G2-IMP-07-S04 구현 증거 2026-08-22',
  'A-DS-16 승인 전',
]) {
  if (!worksheetAuthoring.includes(marker)) {
    fail('a-ds-09-worksheet-authoring-version.html: missing worksheet marker ' + marker);
  }
}
const worksheetDecisionStart = worksheetAuthoring.indexOf('id="decisions"');
const worksheetDecisionEnd = worksheetAuthoring.indexOf('id="completion"', worksheetDecisionStart);
const worksheetDecisionSection = worksheetAuthoring.slice(worksheetDecisionStart, worksheetDecisionEnd);
const frozenWorksheetDecisionCount = (worksheetDecisionSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenWorksheetDecisionCount !== 24) {
  fail('a-ds-09-worksheet-authoring-version.html: expected 24 frozen decisions, found ' + frozenWorksheetDecisionCount);
}
if (worksheetDecisionSection.includes('<td>확인 대기</td></tr>')) {
  fail('a-ds-09-worksheet-authoring-version.html: pending decision remains after freeze');
}
const routeAmendmentStart = worksheetAuthoring.indexOf('id="route-amendments"');
const routeAmendmentEnd = worksheetAuthoring.indexOf('id="database-amendments"', routeAmendmentStart);
const routeAmendmentSection = worksheetAuthoring.slice(routeAmendmentStart, routeAmendmentEnd);
const frozenRouteAmendmentCount = (routeAmendmentSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenRouteAmendmentCount !== 4) {
  fail('a-ds-09-worksheet-authoring-version.html: expected 4 frozen REST amendments, found ' + frozenRouteAmendmentCount);
}
const databaseAmendmentStart = worksheetAuthoring.indexOf('id="database-amendments"');
const databaseAmendmentEnd = worksheetAuthoring.indexOf('id="services"', databaseAmendmentStart);
const databaseAmendmentSection = worksheetAuthoring.slice(databaseAmendmentStart, databaseAmendmentEnd);
const frozenDatabaseAmendmentCount = (databaseAmendmentSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenDatabaseAmendmentCount !== 3) {
  fail('a-ds-09-worksheet-authoring-version.html: expected 3 frozen database amendments, found ' + frozenDatabaseAmendmentCount);
}

for (const marker of ['A-DS08-DBA01~DBA03', 'A-DS08-DBA04', 'oj_ai_student_note_revisions', 'A-DS-05 Amendment 01']) {
  if (!databaseDesign.includes(marker)) fail('a-ds-05-addon-database.html: missing A-DS-08 amendment marker ' + marker);
}
for (const marker of [
  'A-DS-05 Amendment 02 · 사용자 확인·동결',
  'A-DS09-DBA01~DBA03',
  'document_schema_version',
  'document_hash',
  'projection_manifest_version',
  'publication_hash',
  'request_contract_version',
  'JSON(request_snapshot)',
  'request_hash',
  '기존 <code>validation_hash</code>는 validator 결과 hash로 유지',
]) {
  if (!databaseDesign.includes(marker)) fail('a-ds-05-addon-database.html: missing A-DS-09 amendment marker ' + marker);
}

const worksheetEvaluation = documents.get(path.join(docsRoot, 'a-ds-10-worksheet-self-evaluation.html')).content;
for (const [prefix, expected] of [['B', 8], ['DIM', 6], ['L', 10], ['A', 24], ['D', 24]]) {
  for (let number = 1; number <= expected; number += 1) {
    const id = 'A-DS10-' + prefix + String(number).padStart(2, '0');
    const count = (worksheetEvaluation.match(new RegExp('<code>' + id + '<\/code>', 'g')) ?? []).length;
    if (count !== 1) {
      fail('a-ds-10-worksheet-self-evaluation.html: expected one ' + id + ', found ' + count);
    }
  }
}
for (const marker of [
  'Detailed Design 11 / 17',
  '상태 사용자 확인·동결',
  'A-DS-05 Amendment 03 반영',
  '핵심 차원 6종',
  'REST 추가 0건',
  'DB 보강 1건 확정',
  'addon.oj_ai_dashboard.worksheet_quality_assessment',
  '<code>sourceKind</code></td><td><code>none</code>',
  'worksheet_quality.v1',
  'worksheet_quality_prompt.v2',
  'oj_ai.worksheet_quality_request@1.0.0',
  'oj_ai.worksheet_quality_output@1.0.0',
  'oj_ai.worksheet_quality_result@1.0.0',
  '921,600 bytes',
  '524,288 bytes',
  'learning_goal_alignment',
  'instruction_clarity',
  'answerability',
  'difficulty_fit',
  'rubric_quality',
  '<code>safety</code>',
  '차원 rating은 <code>meets</code>',
  '<code>minor_issue</code>',
  '<code>major_issue</code>',
  '<code>not_assessable</code>',
  'ready|needs_attention|action_required|safety_review|not_assessable',
  '<code>resolved</code>',
  '<code>deferred</code>',
  '<code>dismissed</code>',
  'A-DS10-DBA01',
  'Table 45개·owner 10개·무접두어',
  'C28-I06-AJ08',
  'AiTaskPromptFactory',
  'id="aj08-candidate-contract"',
  'AddonAiTaskInstructionV1',
  'instruction(): ?AddonAiTaskInstructionV1',
  '16,384 bytes',
  'platform_system',
  'addon_task_instruction',
  'untrusted_user_data',
  'system → system → user',
  'requiresAddonApi &gt;= 1.1.0',
  'interface 6개·DTO 24개·권한 utility 1개·공개 symbol 31개',
  'Q09~Q10',
  'C10~C12',
  'C33',
  '업무 Runtime 변경 0건',
  'A-DS-16 승인 전',
]) {
  if (!worksheetEvaluation.includes(marker)) {
    fail('a-ds-10-worksheet-self-evaluation.html: missing worksheet evaluation marker ' + marker);
  }
}
const worksheetEvaluationDecisionStart = worksheetEvaluation.indexOf('id="decisions"');
const worksheetEvaluationDecisionEnd = worksheetEvaluation.indexOf('id="completion"', worksheetEvaluationDecisionStart);
const worksheetEvaluationDecisionSection = worksheetEvaluation.slice(worksheetEvaluationDecisionStart, worksheetEvaluationDecisionEnd);
const frozenWorksheetEvaluationDecisionCount = (worksheetEvaluationDecisionSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenWorksheetEvaluationDecisionCount !== 24) {
  fail('a-ds-10-worksheet-self-evaluation.html: expected 24 frozen decisions, found ' + frozenWorksheetEvaluationDecisionCount);
}
if (worksheetEvaluationDecisionSection.includes('<td>확인 대기</td></tr>')) {
  fail('a-ds-10-worksheet-self-evaluation.html: frozen decisions must not remain pending');
}
const worksheetEvaluationStorageStart = worksheetEvaluation.indexOf('id="storage"');
const worksheetEvaluationStorageEnd = worksheetEvaluation.indexOf('id="platform-gap"', worksheetEvaluationStorageStart);
const worksheetEvaluationStorageSection = worksheetEvaluation.slice(worksheetEvaluationStorageStart, worksheetEvaluationStorageEnd);
const frozenWorksheetEvaluationDatabaseCount = (worksheetEvaluationStorageSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenWorksheetEvaluationDatabaseCount !== 1) {
  fail('a-ds-10-worksheet-self-evaluation.html: expected one frozen database amendment, found ' + frozenWorksheetEvaluationDatabaseCount);
}
for (const marker of [
  'A-DS-05 Amendment 03 · 사용자 확인·동결',
  'A-DS10-DBA01',
  'JSON(item_decisions)',
  'item_decisions_schema VARCHAR(100)',
  'item_decisions_version SMALLINT UNSIGNED',
  'item_decisions_json LONGTEXT',
  'item_decisions_hash CHAR(64)',
  'Amendment 01~10 반영',
]) {
  if (!databaseDesign.includes(marker)) fail('a-ds-05-addon-database.html: missing A-DS-10 amendment marker ' + marker);
}

const studentSubmission = documents.get(path.join(docsRoot, 'a-ds-11-assignment-student-submission.html')).content;
for (const [prefix, expected] of [['B', 10], ['A', 24], ['D', 24]]) {
  for (let number = 1; number <= expected; number += 1) {
    const id = 'A-DS11-' + prefix + String(number).padStart(2, '0');
    const count = (studentSubmission.match(new RegExp('<code>' + id + '<\/code>', 'g')) ?? []).length;
    if (count !== 1) fail('a-ds-11-assignment-student-submission.html: expected one ' + id + ', found ' + count);
  }
}
for (const marker of [
  'Detailed Design 12 / 17',
  '상태 사용자 확인·동결',
  '확정일 2026-08-13',
  'A-DS-05 Amendment 04 반영',
  'A-DS-08 Amendment 02 반영',
  '응답칸 10종',
  'REST 보강 3건 확정',
  'DB 보강 2건 확정',
  'A-DS11-RA01',
  'A-DS08-C67',
  '/me/submissions/{submission_id}/commands/export-pdf',
  'A-DS11-DBA01',
  'A-DS11-DBA02',
  'A-DS11-RA02',
  'A-DS11-RA03',
  'A-DS08-Q35',
  'A-DS08-C68',
  'oj_ai_assignments',
  'oj_ai_assignment_targets',
  'oj_ai_submission_receipts',
  'target_roster_revision VARCHAR(191)',
  'membership_revision VARCHAR(191)',
  'schedule_version INT UNSIGNED',
  'assignment_schedule_hash CHAR(64)',
  'actor_type VARCHAR(16)',
  'actor_user_id BIGINT UNSIGNED nullable',
  'profile <code>PK, PID, IMM</code> → <code>PK, PID, SYS + content_hash</code>',
  'opens_at_utc',
  'due_at_utc',
  'closes_at_utc',
  'draft|conflict|submitted|auto_submitted|proxy_submitted|reopened|locked',
  'target에서 <code>not_started</code>로 계산',
  'oj_ai_outbox_events',
  'assignment.auto_submit_due',
  'student_response_schema',
  'short_text',
  'long_text',
  'image_upload',
  'file_upload',
  'drawing',
  'ready가 아닌 첨부',
  '60초 주기',
  'auto_submit',
  'proxy_submit',
  'id="zip-import"',
  '압축비 20:1 이하',
  'official_revision_public_id',
  'C28-I06-CG01',
  'C28-I06-CG02',
  'C28-I06-CG11',
  'C28-I06-RC02',
  'C28-I06-RC03',
  'Table 45개·owner 10개·고정 무접두어',
  '업무 Runtime 변경 0건',
  'A-DS-16 승인 전',
]) {
  if (!studentSubmission.includes(marker)) fail('a-ds-11-assignment-student-submission.html: missing student submission marker ' + marker);
}
for (const id of ['A-DS11-RA01', 'A-DS11-RA02', 'A-DS11-RA03', 'A-DS11-DBA01', 'A-DS11-DBA02']) {
  const count = (studentSubmission.match(new RegExp('<code>' + id + '<\/code>', 'g')) ?? []).length;
  if (count < 2) fail('a-ds-11-assignment-student-submission.html: insufficient trace for ' + id);
}
const studentSubmissionDecisionStart = studentSubmission.indexOf('id="decisions"');
const studentSubmissionDecisionEnd = studentSubmission.indexOf('id="completion"', studentSubmissionDecisionStart);
const studentSubmissionDecisionSection = studentSubmission.slice(studentSubmissionDecisionStart, studentSubmissionDecisionEnd);
const frozenStudentSubmissionDecisionCount = (studentSubmissionDecisionSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenStudentSubmissionDecisionCount !== 24) fail('a-ds-11-assignment-student-submission.html: expected 24 frozen decisions, found ' + frozenStudentSubmissionDecisionCount);
if (studentSubmissionDecisionSection.includes('<td>확인 대기</td></tr>')) fail('a-ds-11-assignment-student-submission.html: frozen decisions must not remain pending');
const studentSubmissionRestStart = studentSubmission.indexOf('id="rest"');
const studentSubmissionRestEnd = studentSubmission.indexOf('id="storage"', studentSubmissionRestStart);
const frozenStudentSubmissionRestCount = (studentSubmission.slice(studentSubmissionRestStart, studentSubmissionRestEnd).match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenStudentSubmissionRestCount !== 3) fail('a-ds-11-assignment-student-submission.html: expected three frozen REST amendments, found ' + frozenStudentSubmissionRestCount);
const studentSubmissionStorageStart = studentSubmission.indexOf('id="storage"');
const studentSubmissionStorageEnd = studentSubmission.indexOf('id="platform-gap"', studentSubmissionStorageStart);
const frozenStudentSubmissionDatabaseCount = (studentSubmission.slice(studentSubmissionStorageStart, studentSubmissionStorageEnd).match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenStudentSubmissionDatabaseCount !== 2) fail('a-ds-11-assignment-student-submission.html: expected two frozen database amendments, found ' + frozenStudentSubmissionDatabaseCount);
for (const marker of [
  'A-DS-05 Amendment 04 · 사용자 확인·동결',
  'A-DS11-DBA01~DBA02',
  'A-DS05-AM04-01',
  'A-DS05-AM04-03',
  'target_roster_revision',
  'membership_revision',
  'assignment_schedule_hash',
  'Amendment 01~10 반영',
]) {
  if (!databaseDesign.includes(marker)) fail('a-ds-05-addon-database.html: missing A-DS-11 amendment marker ' + marker);
}
for (const marker of [
  'A-DS-05 Amendment 08 · 사용자 확인·동결',
  'A-DS05-AM08-01',
  'A-DS05-AM08-02',
  'A-DS05-AM08-03',
  'A-DS05-AM08-04',
  'idempotency_key VARCHAR(191)',
  'uq_purge_idempotency(idempotency_key)',
  'uq_purge_approval_token(approval_token_hash)',
]) {
  if (!databaseDesign.includes(marker)) fail('a-ds-05-addon-database.html: missing A-DS-15 amendment marker ' + marker);
}

const submissionAssessment = documents.get(path.join(docsRoot, 'a-ds-12-submission-ai-assessment.html')).content;
for (const [prefix, expected] of [['B', 10], ['L', 12], ['A', 24], ['D', 24]]) {
  for (let number = 1; number <= expected; number += 1) {
    const id = 'A-DS12-' + prefix + String(number).padStart(2, '0');
    const count = (submissionAssessment.match(new RegExp('<code>' + id + '<\/code>', 'g')) ?? []).length;
    if (count !== 1) fail('a-ds-12-submission-ai-assessment.html: expected one ' + id + ', found ' + count);
  }
}
for (const marker of [
  'Detailed Design 13 / 17',
  '상태 사용자 확인·동결',
  '확정일 2026-08-13',
  'A-DS-09 Projection Amendment 01 반영',
  'A-DS-05 Amendment 05 반영',
  'addon.oj_ai_dashboard.student_submission_assessment',
  'sourceKind</code></td><td><code>addon_payload',
  'submission_score.v1',
  'submission_score_prompt.v1',
  'student_submission_assessment_prompt.v2',
  'v1 이력 보존',
  'oj_ai.submission_score_request@1.0.0',
  'oj_ai.submission_score_output@1.0.0',
  'oj_ai.submission_score_result@1.0.0',
  'oj_ai.grading_schema@1.0.0',
  'oj_ai.grading_schema@1.1.0',
  'A-DS12-PA01',
  'A-DS12-DBA01',
  'JSON(ai_representation)',
  'ai_text_representation',
  'Amendment 01 반영',
  'Amendment 05 반영',
  '응답칸 10종',
  'short_text', 'long_text', 'single_choice', 'multiple_choice', 'checkbox',
  'number', 'table', 'image_upload', 'file_upload', 'drawing',
  '921,600 bytes',
  '524,288 bytes',
  'artifact_unavailable',
  'candidate total을 null',
  'review_required=true',
  'explicit_release_required=true',
  'A-DS08-Q16', 'A-DS08-Q17', 'A-DS08-C29', 'A-DS08-C30', 'A-DS08-C31', 'A-DS08-C32',
  'REST 보강 후보 0건',
  'oj_ai_submission_evaluations',
  'oj_ai_submission_evaluation_results',
  'C28-I06-AJ08',
  'C29·C30 구현 완료',
  'G4 IMP-01 5/5 완료',
  'G4-IMP-01-S05 구현 증거 2026-08-23',
  'g4-imp-01-s05-idempotency-authorization-freeze.html',
  'G4-IMP-02-S01 구현 증거 2026-08-23',
  'g4-imp-02-s01-evaluation-queue-lease.html',
  'G4-IMP-02-S02 실행 코어 증거 2026-08-23',
  'g4-imp-02-s02-gateway-output-result.html',
  '허용 플래그 5개',
  'G4-IMP-02-R01 학생 제출 동의 증거 2026-08-23',
  'g4-imp-02-r01-submission-external-ai-consent.html',
  'g4-imp-02-s03-submission-evaluation-query.html',
  'Q38·C78·C79',
  'G4-IMP-02-S03 평가 조회 증거 2026-08-23',
  'G4-IMP-02-S04 실행 제어 증거 2026-08-23',
  'G4-IMP-02-S05 통합 동결 증거 2026-08-23',
  'g4-imp-02-s04-submission-evaluation-control.html',
  'g4-imp-02-s05-submission-evaluation-integration-freeze.html',
  'IMP-02 5/5 완료',
  'A-DS-16 승인 전',
]) {
  if (!submissionAssessment.includes(marker)) fail('a-ds-12-submission-ai-assessment.html: missing submission assessment marker ' + marker);
}
const submissionAssessmentDecisionStart = submissionAssessment.indexOf('id="decisions"');
const submissionAssessmentDecisionEnd = submissionAssessment.indexOf('id="completion"', submissionAssessmentDecisionStart);
const submissionAssessmentDecisionSection = submissionAssessment.slice(submissionAssessmentDecisionStart, submissionAssessmentDecisionEnd);
const frozenSubmissionAssessmentDecisionCount = (submissionAssessmentDecisionSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenSubmissionAssessmentDecisionCount !== 24) fail('a-ds-12-submission-ai-assessment.html: expected 24 frozen decisions, found ' + frozenSubmissionAssessmentDecisionCount);
if (submissionAssessmentDecisionSection.includes('<td>확인 대기</td></tr>')) fail('a-ds-12-submission-ai-assessment.html: frozen decisions must not remain pending');
if (submissionAssessment.includes('<code>PA01</code>') || submissionAssessment.includes('<code>DBA01</code>')) fail('a-ds-12-submission-ai-assessment.html: abbreviated amendment IDs are forbidden');
if (submissionAssessment.includes('<code>wp_oj_ai_')) fail('a-ds-12-submission-ai-assessment.html: prefixed target table name found');
if (submissionAssessment.includes('DB 보강 후보 0건')) fail('a-ds-12-submission-ai-assessment.html: stale zero database candidate marker found');
if (submissionAssessment.includes('run-scoped 무작위 evaluation ref')) fail('a-ds-12-submission-ai-assessment.html: run-scoped evaluation reference conflicts with retry identity');

const teacherReview = documents.get(path.join(docsRoot, 'a-ds-13-teacher-review-feedback-release.html')).content;
for (const [prefix, expected] of [['B', 12], ['L', 10], ['A', 24], ['D', 24]]) {
  for (let number = 1; number <= expected; number += 1) {
    const id = 'A-DS13-' + prefix + String(number).padStart(2, '0');
    const count = (teacherReview.match(new RegExp('<code>' + id + '<\/code>', 'g')) ?? []).length;
    if (count !== 1) fail('a-ds-13-teacher-review-feedback-release.html: expected one ' + id + ', found ' + count);
  }
}
for (const marker of [
  'Detailed Design 14 / 17',
  '상태 사용자 확인·동결',
  '확정일 2026-08-13',
  'A-DS-12 사용자 확인·동결',
  'A-DS13-CA01', 'A-DS13-DBA01', 'A-DS13-RA01',
  'student_submission_assessment.v1', 'submission_score.v1', 'submission_feedback.v1',
  'student_submission_assessment_prompt.v2', 'student_submission_assessment_prompt.v1', 'submission_score_prompt.v1', 'submission_feedback_prompt.v1', 'v1 이력이 보존',
  'oj_ai.submission_feedback_request|output|result@1.0.0',
  'accepted', 'adjusted', 'rejected', 'ai_accepted', 'teacher_assigned',
  'official_review_public_id', 'official_review_hash', 'current_release_public_id', 'current_release_hash',
  'previous_revision_id', 'source_evaluation_run_public_id', 'release_reason_code', 'release_reason_note',
  'released', 'corrected', 'withdrawn', 'stale',
  'feedback_source_stale', 'release_source_stale',
  'AddonCapabilityKeyV1::wordpress($declaredKey)',
  'Page Route 9개·조회 35개·명령 68개',
  'table 수 45개·owner 10개',
  '새 플랫폼 API 0건', 'C28-I06-AJ08',
  '업무 Runtime 변경 0건', 'A-DS-16 승인 전',
  'G4-IMP-03-S02 구현 반영', 'C34 REST를 활성화',
  'G4-IMP-03-S03 구현 반영', '점수·근거 검토 UI', 'review_precondition',
  'G4-IMP-03-S04 구현 반영', 'review_source_stale', '409 뒤 최신 상태', '403 권한 회수',
  'official_review_changed',
  'G4-IMP-04-S01 구현 반영', 'C35 피드백 초안',
  'G4-IMP-04-S02 구현 반영', 'C36 교사 피드백 검토', 'exact replay',
  'G4-IMP-04-S03 구현 반영', 'C37 피드백 승인', '요청 <code>payload</code>는 빈 객체', '승인만으로 학생 공개본은 생성하지 않는다',
  'G4-IMP-04-S04 구현 반영', '피드백 작업대·통합 동결', 'approved', '학생 공개 전',
  'G4-IMP-05-S05 구현 증거',
  'G4-IMP-06-S01 C43 구현 증거',
  'G4-IMP-06-S02 Q33 구현 증거',
  'G4-IMP-06-S03 C44 구현 증거',
  'G4-IMP-06-S04 C43 구현 증거',
  'G4-IMP-06-S05 PublicUI 구현 증거',
  'G4-IMP-07-S01 학생 결과 PDF 공개 projection 증거',
  'G4-IMP-07-S02 학생 결과 PDF job 증거',
  'G4-IMP-07-S03 학생 결과 PDF 접근 동결 증거',
  'G4-IMP-07-S04 학생 결과 PDF 화면·시각 증거',
  'IMP-06 5/5·IMP-07 4/4 완료',
  '다음 G5-IMP-01-S01',
  '특기사항 학생 비노출 유지',
]) {
  if (!teacherReview.includes(marker)) fail('a-ds-13-teacher-review-feedback-release.html: missing teacher review marker ' + marker);
}
const teacherReviewDecisionStart = teacherReview.indexOf('id="decisions"');
const teacherReviewDecisionEnd = teacherReview.indexOf('id="completion"', teacherReviewDecisionStart);
const teacherReviewDecisionSection = teacherReview.slice(teacherReviewDecisionStart, teacherReviewDecisionEnd);
const frozenTeacherReviewDecisionCount = (teacherReviewDecisionSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenTeacherReviewDecisionCount !== 24) fail('a-ds-13-teacher-review-feedback-release.html: expected 24 frozen decisions, found ' + frozenTeacherReviewDecisionCount);
if (teacherReviewDecisionSection.includes('<td>확인 대기</td></tr>')) fail('a-ds-13-teacher-review-feedback-release.html: frozen decisions must not remain pending');
if (teacherReview.includes('<code>wp_oj_ai_')) fail('a-ds-13-teacher-review-feedback-release.html: prefixed target table name found');
if (teacherReview.includes('task마다 input contract 여러 개')) fail('a-ds-13-teacher-review-feedback-release.html: platform task contract multiplicity is misstated');

const dashboardReportExport = documents.get(path.join(docsRoot, 'a-ds-14-dashboard-report-export.html')).content;
for (const [prefix, expected] of [['B', 14], ['L', 10], ['A', 24], ['D', 24]]) {
  for (let number = 1; number <= expected; number += 1) {
    const id = 'A-DS14-' + prefix + String(number).padStart(2, '0');
    const count = (dashboardReportExport.match(new RegExp('<code>' + id + '<\/code>', 'g')) ?? []).length;
    if (count !== 1) fail('a-ds-14-dashboard-report-export.html: expected one ' + id + ', found ' + count);
  }
}
for (const marker of [
  'Detailed Design 15 / 17',
  '상태 사용자 확인·동결',
  '확정일 2026-08-14',
  'A-DS-13 사용자 확인·동결',
  'A-DS14-CA01', 'A-DS14-DBA01', 'A-DS14-RA01', 'A-DS14-PA01',
  'student_submission_assessment.v1', 'special_note_draft', 'student_special_note.v1',
  'oj_ai.student_special_note_request|output|result@1.0.0',
  'student_submission_assessment_prompt.v2', 'submission_special_note_prompt.v1', 'task-level v1 이력 보존', 'C28-I06-AJ08',
  'include_in_reports=1', 'manage_student_notes', 'view_reports', 'export_reports',
  'WorksheetReportSourceV1', 'AssignmentProgressSourceV1', 'OfficialAssessmentSourceV1', 'ApprovedStudentNoteSourceV1',
  'oj_ai.reporting_metrics@1.0.0', 'oj_ai.report_source_manifest@1.0.0',
  'worksheet_print.v1', 'submission_print.v1', 'result_print.v1', 'report_print.v1',
  'dompdf/dompdf ^3.1', 'remote disabled', 'Noto Sans KR',
  'export-json-package', 'Page Route 9개·조회 35개', '명령을 69개',
  'source_manifest_schema VARCHAR(100)', 'source_manifest_version SMALLINT UNSIGNED',
  'source_manifest_json LONGTEXT', 'source_manifest_hash CHAR(64)',
  'table 45개·owner 10개', '무접두어 <code>oj_ai_*',
  'C28-I03-CG11', 'RC01', 'RC02', 'RC03',
  'G2-IMP-07-S03 구현 증거',
  'G2-IMP-07-S04 구현 증거',
  'artifact 기본 수명은 ready 후 24시간', 'zip_unavailable',
  'G2-IMP-07-S02 구현 증거',
  'G4-IMP-05-S01 C38 구현 반영',
  '<code>ai_draft</code>, <code>include_in_reports=0</code>',
  'C38~C42와 Q18~Q19 교사용 흐름이 활성화',
  'C41의 명시적 선택만 포함 revision을 추가',
  'C42는 다시 제외 revision을 추가',
  '과거 보고서 snapshot은 바뀌지 않고 새 snapshot만',
  'G4-IMP-05-S04 구현 증거',
  'G4-IMP-05-S05 구현 증거',
  'G4-IMP-06-S01 C43 구현 증거',
  'G4-IMP-06-S02 Q33 구현 증거',
  'G4-IMP-06-S03 C44 구현 증거',
  'G4-IMP-06-S04 C43 구현 증거',
  'G4-IMP-06-S05 결과 화면 구현 증거',
  'G4-IMP-07-S01 result_print 구현 증거',
  'G4-IMP-07-S02 C52 구현 증거',
  'G4-IMP-07-S03 Q23·Q24 구현 증거',
  'oj_ai.result_pdf_request@1.0.0',
  '300초 lease',
  'result_pdf',
  'G4-IMP-07-S04 화면·시각 구현 증거',
  'G5-IMP-01-S01 Q20 구현 증거',
  'G5-IMP-01-S02 Q21 구현 증거',
  'G5-IMP-01-S03 filter·분모 구현 증거',
  'G5-IMP-01-S04 대시보드 구현 증거',
  'IMP-01 4/4 완료',
  'G5-IMP-02-S01 C45 구현 증거',
  'G5-IMP-02-S02 Q22 구현 증거',
  'G5-IMP-02-S03 원본 계보 구현 증거',
  'G5-IMP-02-S04 통합 동결 증거',
  'G5-IMP-03-S01 C46·C47 구현 증거',
  'G5-IMP-03-S02 C50 구현 증거',
  'G5-IMP-03-S03 C69 구현 증거',
  'G5-IMP-03-S04 Q23·Q24 구현 증거',
  'oj_ai.report_source_manifest@1.0.0',
  'oj_ai.report_projection@1.0.0',
  'source_manifest_hash',
  'submission_bundle.v1',
  'report_json_package.v1',
  '500 files·500 MiB',
  '모든 활동지 source 접근권한을 재검사',
  'IMP-02 4/4 완료',
  'C46·C47·C50·C69 enqueue 완료',
  'IMP-03 3/6 진행',
  '다음 G5-IMP-03-S04 Q23·Q24 job·download',
  '원 승인자·승인 시각',
  '어떤 초안·승인본도 자동 포함하지 않는다',
  '새 플랫폼 API 0건', '업무 Runtime 변경 0건', 'A-DS-16 승인 전',
]) {
  if (['C46·C47·C50·C69 enqueue 완료', 'IMP-03 3/6 진행', '다음 G5-IMP-03-S04 Q23·Q24 job·download'].includes(marker)) continue;
  if (!dashboardReportExport.includes(marker)) fail('a-ds-14-dashboard-report-export.html: missing dashboard/report/export marker ' + marker);
}
for (const marker of ['G5-IMP-03-S05 C53·C54 구현 증거', 'G5-IMP-03-S06 checksum·권한 통합 동결 증거', 'C46·C47·C50·C69 실제 artifact·Q23·Q24·C53·C54와 checksum·권한 통합 동결', 'IMP-03 6/6 완료', '다음 G5-IMP-05-S01 Q28 Bridge·schema·queue']) {
  if (!dashboardReportExport.includes(marker)) fail('a-ds-14-dashboard-report-export.html: missing G5 IMP-03-S06 dashboard/report/export marker ' + marker);
}
const dashboardDecisionStart = dashboardReportExport.indexOf('id="decisions"');
const dashboardDecisionEnd = dashboardReportExport.indexOf('id="completion"', dashboardDecisionStart);
const dashboardDecisionSection = dashboardReportExport.slice(dashboardDecisionStart, dashboardDecisionEnd);
const frozenDashboardDecisionCount = (dashboardDecisionSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenDashboardDecisionCount !== 24) fail('a-ds-14-dashboard-report-export.html: expected 24 frozen decisions, found ' + frozenDashboardDecisionCount);
if (dashboardDecisionSection.includes('<td>확인 대기</td></tr>')) fail('a-ds-14-dashboard-report-export.html: frozen decisions must not remain pending');
if (dashboardReportExport.includes('<code>wp_oj_ai_')) fail('a-ds-14-dashboard-report-export.html: prefixed target table name found');
if (dashboardReportExport.includes('included|excluded')) fail('a-ds-14-dashboard-report-export.html: report inclusion must use include_in_reports, not pseudo states');

const installOperations = documents.get(path.join(docsRoot, 'a-ds-15-install-upgrade-migration-operations.html')).content;
for (const [prefix, expected] of [['B', 16], ['L', 12], ['A', 24], ['D', 24]]) {
  for (let number = 1; number <= expected; number += 1) {
    const id = 'A-DS15-' + prefix + String(number).padStart(2, '0');
    const count = (installOperations.match(new RegExp('<code>' + id + '<\/code>', 'g')) ?? []).length;
    if (count !== 1) fail('a-ds-15-install-upgrade-migration-operations.html: expected one ' + id + ', found ' + count);
  }
}
for (const marker of [
  'Detailed Design 16 / 17', '상태 사용자 확인·동결', '확정일 2026-08-14',
  'A-DS-14 사용자 확인·동결', '목표 table 45개 유지',
  'A-DS15-CA01', 'A-DS15-DBA01', 'A-DS15-RA01',
  'oj_ai.legacy_migration_plan@1.0.0', 'oj_ai.legacy_migration_package@1.0.0',
  'preview_token', '최대 4 KiB', 'idempotency_key_hash', 'uq_import_idempotency', 'idempotency_key VARCHAR(191)', 'uq_purge_idempotency', 'uq_purge_approval_token', '30분',
  'POST /migration-runs/commands/approve', 'POST /purge-jobs/commands/approve',
  'Q36', 'C70~C75', 'Page 9·조회 36·명령 75',
  'AddonRuntimeReadiness', 'dev.33 G0 구현 인수', '새 플랫폼 API 후보가 없다', 'pending|ready|failed|not_required',
  'C28-I06-LC01', 'C28-I06-RC01', 'C28-I06-RC02', 'C28-I06-CG11', 'C28-I06-AJ08',
  'table 45개·owner 10개', '고정 무접두어 <code>oj_ai_*',
  '애드온 업무 Runtime 변경 0건', '&gt;=1.1.0 &lt;2.0.0',
  '202608220001_export_job_context_identity',
  '31cee35952177177a1b36bd483f43ea1d6c1880c7d2a1e1ad891f896a41e32b1',
  '45 CREATE + 1 ALTER',
  'schema 1.0.2 전진 마이그레이션',
  '202608230001_expand_submission_prompt_contract',
  '45 CREATE + 2 ALTER',
  '1.0.1은 ALTER 1건',
  'upgraded',
  '후속 Amendment 07',
  'Page 9·조회 38·명령 79',
  '학생 제출 평가 worker hook',
  '새 table·column·migration은 0건',
  'G5-IMP-05-S02 구현 인수',
  'task별 설정 필요·확인 불가·다시 확인 필요',
  'G5-IMP-05-S03 구현 인수',
  '물리 파일 전체 순회·repair·삭제는 수행하지 않는다',
  'G5-IMP-05-S04 구현 인수',
  'dom|fileinfo|intl|mbstring|mysqli|openssl|xmlwriter|zip',
  'G5-IMP-06-S02 순차 upgrade 인수',
  'check:sequential-upgrade-g5',
  'exact 1.0.0·1.0.1 source의 최소 delta',
  'G5-IMP-06-S03 실패 상태·재시작 인수',
  'check:failure-restart-g5',
  'checkpoint 봉투',
  'G5-IMP-06-S04 C75 정방향 복구 인수',
  'check:forward-repair-g5',
  'option claim → <code>oj_ai_audit_events</code>·<code>oj_ai_outbox_events</code>',
  'G5-IMP-06-S05 데이터 보존 회귀 인수',
  'g5-imp-06-s05-data-preservation.html',
  'check:data-preservation-g5',
  'G5-IMP-07-S01 C61 이전 preview 인수',
  'g5-imp-07-s01-legacy-migration-preview.html',
  'check:legacy-migration-preview-g5',
  'oj_ai_dashboard_wpdb_prefixed@0.1.2',
  '업무·import·audit·outbox·option·플랫폼 mutation 0',
  'G5-IMP-07-S02 C70 이전 승인 인수',
  'g5-imp-07-s02-legacy-migration-approval.html',
  'check:legacy-migration-approval-g5',
  'import item·업무·플랫폼 write는 0건',
  'G5-IMP-07-S03-D3-D2 mapping 계약 인수',
  'check:legacy-migration-mapping-g5',
  'G5-IMP-07-S03-D3-D3 migration publication 인수',
  'check:legacy-migration-publication-g5',
  'G5-IMP-07-S03-D3-D4 assignment·target 인수',
  'check:legacy-migration-assignment-g5',
  'G5-IMP-07-S03-D3-D5 submission 인수',
  'check:legacy-migration-submission-g5',
  'G5-IMP-07-S03-D3-D6 evaluation lineage 인수',
  'check:legacy-migration-evaluation-g5',
]) {
  if (!installOperations.includes(marker)) fail('a-ds-15-install-upgrade-migration-operations.html: missing D3-D5 install/operations marker ' + marker);
}
const installDecisionStart = installOperations.indexOf('id="decisions"');
const installDecisionEnd = installOperations.indexOf('id="completion"', installDecisionStart);
const installDecisionSection = installOperations.slice(installDecisionStart, installDecisionEnd);
const frozenInstallDecisionCount = (installDecisionSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
if (frozenInstallDecisionCount !== 24) fail('a-ds-15-install-upgrade-migration-operations.html: expected 24 frozen decisions, found ' + frozenInstallDecisionCount);
if (installDecisionSection.includes('<td>확인 대기</td></tr>')) fail('a-ds-15-install-upgrade-migration-operations.html: frozen decisions must not remain pending');
if (installOperations.includes('A-DS15-PCA01') || installOperations.includes('C28-I06-LC02')) fail('a-ds-15-install-upgrade-migration-operations.html: obsolete platform status candidate remains');
if (installOperations.includes('<code>wp_oj_ai_')) fail('a-ds-15-install-upgrade-migration-operations.html: prefixed target table name found');
if (installOperations.includes('/migration-runs/{run_id}/commands/approve') || installOperations.includes('/purge-jobs/{job_id}/commands/approve')) fail('a-ds-15-install-upgrade-migration-operations.html: approval path requires an identifier before the record exists');

const crossValidation = documents.get(path.join(docsRoot, 'a-ds-16-cross-validation-freeze.html')).content;
for (const [prefix, expected] of [['B', 17], ['S', 12], ['A', 24], ['D', 24]]) {
  for (let number = 1; number <= expected; number += 1) {
    const id = 'A-DS16-' + prefix + String(number).padStart(2, '0');
    const count = (crossValidation.match(new RegExp('<code>' + id + '</code>', 'g')) ?? []).length;
    if (count !== 1) fail('a-ds-16-cross-validation-freeze.html: expected one ' + id + ', found ' + count);
  }
}
for (const marker of [
  'Detailed Design 17 / 17',
  '상태 Amendment 20 반영',
  'A-DS-00~16 사용자 확인·동결',
  'Page 9',
  'REST GET 40 · POST 79',
  'Capability 15',
  'Table 45 · Owner 10',
  'AI task 2',
  '응답칸 10종',
  'A-DS-08 표제',
  '61a7252239d11d94329458bbefd77bb625962b6b645e782df476baf0fc4ed751',
  'interface 8개·DTO 28개·권한 utility 1개·공개 symbol 37개',
  'C28-I06-LC01',
  'C28-I06-CG01',
  'C28-I06-CG02',
  'C28-I06-CG11',
  'C28-I06-AJ08',
  'C28-I06-RC01',
  'C28-I06-RC02',
  'CG03·RC04·RC05',
  'G2 R01 Amendment 06',
  '활동지 Q37·C76·C77',
  'G4 R01 Amendment 07',
  '학생 제출 Q38·C78·C79',
  '&gt;=2.0.0-dev.33 &lt;3.0.0',
  'Addon API <code>1.1.0</code>',
  'G0 · 플랫폼 C28-I06',
  'G6 · 최종 인수',
  'G6-IMP-05 S01~S05 통과',
  'A-DS16-B17',
  'asset bundle 5',
  'Amendment 08 구현 동결',
  'CSS·JS가 최대 8개',
]) {
  if (!crossValidation.includes(marker)) fail('a-ds-16-cross-validation-freeze.html: missing cross-validation marker ' + marker);
}
const crossValidationDecisionStart = crossValidation.indexOf('id="decisions"');
const crossValidationDecisionEnd = crossValidation.indexOf('id="completion"', crossValidationDecisionStart);
const crossValidationDecisionSection = crossValidation.slice(crossValidationDecisionStart, crossValidationDecisionEnd);
const frozenCrossValidationDecisionCount = (crossValidationDecisionSection.match(/<td>사용자 확인·동결<\/td><\/tr>/g) ?? []).length;
const amendment26CrossValidationDecisionCount = (crossValidationDecisionSection.match(/<td>Amendment 26 구현 동결<\/td><\/tr>/g) ?? []).length;
const amendment08CrossValidationDecisionCount = (crossValidationDecisionSection.match(/<td>Amendment 08 구현 동결<\/td><\/tr>/g) ?? []).length;
if (frozenCrossValidationDecisionCount !== 22 || amendment26CrossValidationDecisionCount !== 1 || amendment08CrossValidationDecisionCount !== 1) fail('a-ds-16-cross-validation-freeze.html: expected 22 user-frozen, one Amendment 26, and one Amendment 08 implementation-frozen decision');
if (crossValidationDecisionSection.includes('<td>확인 대기</td></tr>')) fail('a-ds-16-cross-validation-freeze.html: frozen decisions must not remain pending');
if (crossValidation.includes('<code>wp_oj_ai_')) fail('a-ds-16-cross-validation-freeze.html: prefixed target table name found');

const apiAudit = documents.get(path.join(docsRoot, 'platform-api-implementation-audit.html')).content;
for (let verified = 1; verified <= 12; verified += 1) {
  const id = 'API-AUD-V' + String(verified).padStart(2, '0');
  if (!apiAudit.includes('<code>' + id + '</code>')) {
    fail('platform-api-implementation-audit.html: missing verification ' + id);
  }
}
for (let finding = 1; finding <= 15; finding += 1) {
  const id = 'API-AUD-F' + String(finding).padStart(2, '0');
  const count = (apiAudit.match(new RegExp('<code>' + id + '<\\/code>', 'g')) ?? []).length;
  if (count !== 1) {
    fail('platform-api-implementation-audit.html: expected one finding ' + id + ', found ' + count);
  }
}
for (const marker of [
  'API-AUD-2026-08-14-R6',
  '공개 symbol 37개 일치',
  'API 1.1.0',
  'interface 8개 + DTO 28개 + 권한 utility 1개 = 37개',
  '계약 fixture 6개 통과',
  '감사 발견 15건',
  '플랫폼 release blocker 0건',
  '소비자 보강 5건',
  '91개 파일',
  '61a7252239d11d94329458bbefd77bb625962b6b645e782df476baf0fc4ed751',
  'dev.33 R6 구현 검증',
  'C28-I06-LC01',
  'C28-I06-CG06',
  'C28-I06-CG10',
  'C28-I06-CG11',
  'AddonAssignmentRosterGatewayV1::listAssignmentLearners()',
  'A-DS-02~15의 플랫폼 의존 주장',
  'C28-I06-AJ06',
  'C28-I06-AJ07',
  'C28-I06-AJ08',
  'AddonAiTaskInstructionV1(contractVersion, content)',
  'platform_system → addon_task_instruction → untrusted_user_data',
  '16,384 bytes',
  'C28-I06-RC01',
  'C28-I06-RC05',
  'AddonCapabilityKeyV1::wordpress()',
  'fresh <code>snapshot()</code>',
  'student_submission_assessment.v1',
  'R6 재확인',
  'A-DS-14 API 판정:',
  'A-DS-15 API 판정:',
  'AddonSystemStatusProvider',
  'A-DS-16 G0 검증:',
  'a-ds-16-cross-validation-freeze.html',
  'A-DS09-RA01~RA04',
  '새 플랫폼 API·table을 요구하지 않는다',
  'API-AUD-2026-08-14-R7-PENDING',
  '95e6c99',
  'd83f8c12a30fe9d241523e3b147263d845078a8cf86179b97ea652088b1c2e22',
  'WordPressAddonAssetRenderer',
  'R-02',
  '기준선 수용 보류',
  'API-AUD-2026-08-20-R10-OBSERVED',
  '2.0.0-dev.58',
  'dd89731a1ae5da3d15203f43ce5607c251dd9152357321d67ab13bd2d790c1c7',
  'S05 revision transaction',
  'R10 dev.58 dirty 관찰값 미승인',
  '공개 API 1.1.0·37 symbol 유지',
  'API-AUD-2026-08-21-R11-OBSERVED',
  'G2-IMP-02-S08~S12',
  '새 API 불필요',
  'R11 dev.58 dirty 관찰값 미승인',
  'API-AUD-2026-08-21-R12-OBSERVED',
  'G2-IMP-03-S01~S05',
  'C02·C03·C04·C64·C65·Q34',
  'R12 dev.58 dirty 관찰값 미승인',
  'API-AUD-2026-08-21-R13-OBSERVED',
  'G2-IMP-04-S01',
  '제공자 조립 상태',
  '새 API 불필요',
  'write 0건',
  'R13 dev.58 dirty 관찰값 미승인',
  'API-AUD-2026-08-21-R14-OBSERVED',
  'G2-IMP-04-S02~S03',
  '제공자 계약',
  'f784994f35ab03b3b46e0fed8e77aa141ed7f4af00b5ad0f7455663c3e0f44ff',
  'R14 dev.58 dirty 관찰값 미승인',
  'API-AUD-2026-08-21-R15-OBSERVED',
  'G2-IMP-04-S04',
  '74599c9cf26e6207c6a83f66b8095df6a6547bf0b04900c41806496320dd0735',
  'R15 dev.58 dirty 관찰값 미승인',
  'IMP-04 4/4 동결 시점',
  'API-AUD-2026-08-21-R16-OBSERVED',
  'G2-IMP-05-S01',
  'b8cde15c850b5e55725ecd35f58ddf0d3bac1d6a0ce5d6752796fc20283b0a7f',
  '20198a7d53f5503cf8b672919b96bf4771c247d7b5287ab166417d2fb8c31887',
  'R16 dev.58 dirty 관찰값 미승인',
  'API-AUD-2026-08-21-R17-OBSERVED',
  'G2-IMP-05-S02',
  '921,600',
  'description=null',
  'R17 dev.58 dirty 관찰값 미승인',
  'P04 순수 투영',
  '기존 AJ08 공개 계약을 정확히 소비',
  '플랫폼 API 변경이 아니다',
  'API-AUD-2026-08-21-R18-OBSERVED',
  'G2-IMP-05-S04',
  'check:worksheet-evaluation-worker-s04',
  'R18 dev.58 dirty 관찰값',
  'API-AUD-2026-08-21-R19-OBSERVED',
  'Q09·Q10 조회 구현 후 읽기 전용 재검증',
  'snapshot(string): AddonAiTaskRoutingSnapshotV1',
  'execute(AddonAiTaskRequestV1): AddonAiTaskResultV1',
  'check:worksheet-evaluation-query-s05',
  'G2-IMP-05-S05 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R19 dev.58 dirty 관찰값',
  'API-AUD-2026-08-21-R20-OBSERVED',
  'C11·C12 제어 구현 후 읽기 전용 재검증',
  'check:worksheet-evaluation-control-s06',
  'G2-IMP-05-S06 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R20 dev.58 dirty 관찰값',
  'API-AUD-2026-08-21-R21-OBSERVED',
  'C33 교사 검토 구현 후 읽기 전용 재검증',
  'check:worksheet-evaluation-review-s07',
  'G2-IMP-05-S07 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R21 dev.58 dirty 관찰값',
  'API-AUD-2026-08-21-R22-OBSERVED',
  '평가 작업대 구현 후 읽기 전용 재검증',
  '2.0.0-dev.59',
  'AddonPageHandlerV1',
  'check:worksheet-evaluation-workbench-s08',
  'G2-IMP-05-S08 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R22 dev.59 dirty 관찰값',
  'API-AUD-2026-08-21-R23-OBSERVED',
  '통합·실패 동결 후 읽기 전용 재검증',
  'check:worksheet-evaluation-integration-s09',
  'G2-IMP-05-S09 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  '애드온 내부 예외 분류 변경',
  'R23 dev.59 dirty 관찰값',
  'API-AUD-2026-08-21-R24-OBSERVED',
  'R01 외부 AI 동의 구현 후 읽기 전용 재검증',
  '2.0.0-dev.61',
  'Page 9·GET 37·POST 77',
  'check:external-ai-consent-r01',
  'G2-IMP-05-R01 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  '애드온 소비자 REST는 GET 36→37, POST 75→77로 변경',
  'R24 dev.61 dirty 관찰값',
  'API-AUD-2026-08-21-R25-OBSERVED',
  'S01 승인 정책 관문 후 읽기 전용 재검증',
  'G2-IMP-06-S01 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'Page 9·GET 37·POST 77, table 45·owner 10 유지',
  'check:worksheet-approval-policy-s01',
  '새 플랫폼 API 후보는 없으며 애드온 REST 개수도 바뀌지 않는다',
  'R25 dev.61 dirty 관찰값',
  'API-AUD-2026-08-21-R26-OBSERVED',
  'C06 명시 승인 후 읽기 전용 재검증',
  'check:worksheet-approval-command-s02',
  'G2-IMP-06-S02 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R26 dev.61 dirty 관찰값',
  'API-AUD-2026-08-21-R27-OBSERVED',
  'C07 불변 발행 후 읽기 전용 재검증',
  '2.0.0-dev.62',
  'check:worksheet-publication-s03',
  'G2-IMP-06-S03 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R27 dev.62 dirty 관찰값',
  'API-AUD-2026-08-21-R28-OBSERVED',
  'C08·C09 수명주기 구현 후 읽기 전용 재검증',
  'check:worksheet-lifecycle-s04',
  'G2-IMP-06-S04 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R28 dev.62 dirty 관찰값',
  '플랫폼 적용 변경 0건',
  'API-AUD-2026-08-21-R29-OBSERVED',
  '승인·발행 작업대 UI 동결 후 읽기 전용 재검증',
  'check:worksheet-approval-publication-ui-s05',
  'G2-IMP-06-S05 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R29 dev.62 dirty 관찰값',
  'S05 적용을 위한 플랫폼 수정은 없다',
  'API-AUD-2026-08-21-R30-OBSERVED',
  '평가 설정 seed·명령·UI 구현 후 읽기 전용 재검증',
  'check:assessment-configuration-bootstrap-s01',
  'CFG-BOOTSTRAP-02 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R30 dev.62 dirty 관찰값',
  '평가 설정 적용을 위한 플랫폼 수정은 없다',
  'API-AUD-2026-08-21-R31-OBSERVED',
  'G3 배포 생성 후 읽기 전용 재검증',
  '2.0.0-dev.63',
  'check:assignment-g3',
  'AddonAssignmentRosterGatewayV1',
  'latest_publication_hash',
  'G3-IMP-01 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R31 dev.63 dirty 관찰값',
  '애드온 코드의 플랫폼 호환 수정은 없다',
  'API-AUD-2026-08-21-R32-OBSERVED',
  'G3 배포 상태 구현 후 읽기 전용 재검증',
  '2.0.0-dev.64',
  'check:assignment-g3-lifecycle',
  'lock_version',
  'schedule_hash',
  'G3-IMP-02 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R32 dev.64 dirty 관찰값',
  '제품 버전 관찰값은 dev.63→dev.64',
  'API-AUD-2026-08-21-R33-OBSERVED',
  'G3 학생 활동지 구현 후 읽기 전용 재검증',
  '2.0.0-dev.66',
  'check:assignment-g3-student',
  'AddonLearningContextGatewayV1',
  'G3-IMP-03 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R33 dev.66 dirty 관찰값',
  '제품 버전 관찰값은 dev.64→dev.66',
  '애드온 코드의 플랫폼 호환 수정은 없다',
  'API-AUD-2026-08-22-R34-OBSERVED',
  'G3 Q35 비동기 ZIP 검사 후 읽기 전용 재검증',
  '2.0.0-dev.67',
  'check:submission-zip-g3',
  'scripts/check-addon-api-consumer.ps1',
  'G3-IMP-07-S02 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R34 dev.67 dirty 관찰값',
  '제품 버전 관찰값은 dev.66→dev.67',
  '93개 파일',
  'b55f3e803a1deb9ec934e9039462938af855102ddc99918d0d14b6ee593aaf60',
  '별도 플랫폼 문서 정합화와 clean-release 재승인',
  'G3-IMP-07-S03',
  '현재 확인된 새 플랫폼 API 후보는 없다',
  '애드온 코드의 플랫폼 호환 수정은 없다',
  'API-AUD-2026-08-22-R35-OBSERVED',
  'G3 C68 명시적 ZIP 매핑 후 읽기 전용 재검증',
  'POST /oj-ai/v1/zip-uploads/{upload_id}/commands/map',
  'oj_ai_assignment_targets',
  'G3-IMP-07-S03 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R34 이후 플랫폼 제품 버전',
  'R35 dev.67 dirty 관찰값',
  'G3-IMP-07-S04',
  '현재 확인된 새 플랫폼 API 후보는 없다',
  'API-AUD-2026-08-22-R36-OBSERVED',
  'G3 C20·C21 ZIP 확정·취소 후 읽기 전용 재검증',
  'commands/confirm',
  'commands/cancel',
  'oj_ai_submission_receipts',
  'oj_ai_submission_attachments',
  '공급자 계약 2종과 소비자·Composition Root 계약 재실행 통과',
  'G3-IMP-07-S04 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R35 이후 플랫폼 제품 버전',
  'R36 dev.67 dirty 관찰값',
  'G3-IMP-07-S05',
  '현재 확인된 새 플랫폼 API 후보는 없다',
  'API-AUD-2026-08-22-R37-OBSERVED',
  'G3 ZIP 통합 동결 후 읽기 전용 재검증',
  'WordPress Cron·애드온 Composition Root',
  'legal hold는 잠금 아래 재검사',
  'G3-IMP-07-S05 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R36 이후 플랫폼 제품 버전',
  'R37 dev.67 dirty 관찰값',
  'G3-IMP-08-S01',
  '현재 플랫폼 API를 다시 읽고 변경 시 즉시 알린다',
  'API-AUD-2026-08-22-R38-OBSERVED',
  'G2 활동지 인쇄 Projection 후 읽기 전용 재검증',
  'Worksheet_Print_Source_Query_Port_Interface',
  'oj_ai_worksheet_publications',
  '신규 route·table·DDL·mutation 0건',
  'G2-IMP-07-S01 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R37 이후 플랫폼 제품 버전',
  'R38 dev.67 dirty 관찰값',
  'G2-IMP-07-S02',
  '공개 API가 달라지면 구현을 멈추고 새 감사 ID와 변경 계약을 기록한다',
  'API-AUD-2026-08-22-R39-OBSERVED',
  'G2 활동지 PDF job·artifact 후 읽기 전용 재검증',
  'src/AddonApi/V1',
  'A-DS05 Amendment 09',
  '플랫폼 table·migration·option·DDL 변경 0건',
  'G2-IMP-07-S02 구현·검증·문서화 중 이 작업의 <code>oj-platform</code> write 0건',
  'R39 확인 시점의 선택 status',
  'G2-IMP-07-S03',
  'API-AUD-2026-08-22-R40-OBSERVED',
  'G2 활동지 package 후 읽기 전용 재검증',
  'C66·Q23·Q24',
  '추가 API 0건',
  'S03 구현·문서·검증 과정에서 <code>oj-platform</code> 파일 write 0건',
  'R40 확인 시점에도 선택 status',
  'API-AUD-2026-08-22-R41-OBSERVED',
  'G2 PDF·package 통합 동결 후 읽기 전용 재검증',
  'interface 8개·final DTO 29개',
  'S04 구현·검증·문서화 과정에서 <code>oj-platform</code> 파일 write 0건',
  'R41 확인 시점의 선택 status',
  'API-AUD-2026-08-23-R42-OBSERVED',
  'G3 교사용 제출 PDF 통합 동결 후 읽기 전용 재검증',
  'Addon API <code>1.1.0</code>, 공개 symbol 37개',
  'S01-A~G 구현·검증·문서화 과정에서 <code>oj-platform</code> 파일 write 0건',
  'R42 확인 시점의 선택 status',
  'G3-IMP-08-S02',
  'API-AUD-2026-08-23-R43-OBSERVED',
  'G3 학생용 활동지 PDF 동결 후 읽기 전용 재검증',
  'S02-A~F 구현·검증·문서화 과정에서 <code>oj-platform</code> 파일 write 0건',
  'G3-IMP-08-S02',
  'G3-IMP-08-S03',
  'check:own-worksheet-pdf-g3',
  'R43 확인 결과 제품 버전',
  'API-AUD-2026-08-23-R44-OBSERVED',
  'G3 학생 작성 결과 PDF 동결 후 읽기 전용 재검증',
  'S03-A~F 구현·검증·문서화 과정에서 <code>oj-platform</code> 파일 write 0건',
  'submission snapshot 1.1',
  'check:own-submission-pdf-g3',
  'G3-IMP-08-S04',
  'R44는 dirty <code>dev.67</code>',
  'API-AUD-2026-08-23-R45-OBSERVED',
  'G3 PDF 통합 동결 후 읽기 전용 재검증',
  'S04-A~F 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'Export_Access_Guard',
  'check:pdf-integration-g3',
  'G4-IMP-01',
  'G3-IMP-08-S01',
  'API-AUD-2026-08-23-R46-OBSERVED',
  'G4 제출 평가 지시문 후 읽기 전용 재검증',
  'student_submission_assessment_prompt.v2',
  '5,131-byte hash',
  'task당 instruction 1개',
  'check:submission-assessment-instruction-g4',
  'G4-IMP-01-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'G4-IMP-01-S02',
  'API-AUD-2026-08-23-R47-OBSERVED',
  'G4 제출 평가 공식 입력 후 읽기 전용 재검증',
  'Submission_Score_Input_Builder',
  'grading projector <code>1.1.0</code>',
  'check:submission-assessment-input-g4',
  'addon-contribution-composition.php',
  'G4-IMP-01-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  '64ce74e32f6b7418acc0a115a3b422154bb0368317072b43fd1774b9d10b55fa',
  'G4-IMP-01-S03',
  'API-AUD-2026-08-23-R48-OBSERVED',
  'G4 C29 단건 제출 평가 요청 후 읽기 전용 재검증',
  'messaging.inbox',
  'canRoute',
  'check:submission-evaluation-enqueue-g4',
  'G4-IMP-01-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'G4-IMP-01-S04',
  'API-AUD-2026-08-23-R49-OBSERVED',
  'G4 C30 배포 제출 일괄 평가 요청 후 읽기 전용 재검증',
  'assignment_evaluations.enqueue_receipt',
  'check:assignment-evaluation-enqueue-g4',
  'G4-IMP-01-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R49는 dirty <code>dev.67</code> 관찰값',
  'G4-IMP-01-S05',
  'API-AUD-2026-08-23-R50-OBSERVED',
  'G4 C29·C30 멱등·권한 종합 동결 후 읽기 전용 재검증',
  '2.0.0-dev.68',
  'check:submission-evaluation-freeze-g4',
  'G4-IMP-01-S05 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R50은 dirty <code>dev.68</code> 관찰값',
  'API-AUD-2026-08-23-R51-OBSERVED',
  'G4 S05 설계 관문 중 dev.69 drift 재검증',
  '2.0.0-dev.69',
  'd4e7af3890775522ed4a3514a721c1d48d7286b77fef7cfe12f5c845881996a6',
  'R51은 dirty <code>dev.69</code> 관찰값',
  'API-AUD-2026-08-23-R52-OBSERVED',
  'G4 S05 전체 회귀 종료 후 dev.70 drift 재검증',
  '2.0.0-dev.70',
  'aceb97e4093cc1acfbf1dcac3e7bcd77d63064c974fce31f6adee524893c8a07',
  'R52는 dirty <code>dev.70</code> 관찰값',
  'API-AUD-2026-08-23-R53-OBSERVED',
  'G4 제출 평가 queue·lease 구현 후 읽기 전용 재검증',
  '외부 공개 symbol 37개 유지',
  'V1/Internal/ValueValidator',
  'check:submission-evaluation-queue-lease-g4',
  '전체 <code>npm test</code> 종료 코드 0',
  '119개 설계 HTML + UI 시안 1개',
  'G4-IMP-02-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R53은 dirty <code>dev.70</code> 관찰값',
  'API-AUD-2026-08-23-R54-OBSERVED',
  'G4 제출 평가 Gateway·결과 코어 구현 후 읽기 전용 재검증',
  'snapshot(string): AddonAiTaskRoutingSnapshotV1',
  'execute(AddonAiTaskRequestV1): AddonAiTaskResultV1',
  '120개 설계 HTML + UI 시안 1개 통과',
  'G4-IMP-02-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R54는 dirty <code>dev.70</code> 관찰값',
  'API-AUD-2026-08-23-R55-OBSERVED',
  'G4 학생 제출 외부 AI 동의·worker 활성화 후 읽기 전용 재검증',
  'Page 9·GET 38·POST 79',
  'check:submission-external-ai-consent-g4',
  '플랫폼 제공자 계약 5개 통과',
  'G4-IMP-02-R01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R55는 dirty <code>dev.70</code> 관찰값',
  'API-AUD-2026-08-23-R56-OBSERVED',
  'R01 전체 회귀·플랫폼 dev.71 drift 후 읽기 전용 재검증',
  '2.0.0-dev.71',
  'd2e31c8f30988cd3603dccd08b3406e46d418331c1c5995bd3fdc26b8f4c6bfe',
  '전체 <code>npm test</code> 종료 코드 0',
  '공용 설정 fixture',
  'R56은 dirty <code>dev.71</code> 관찰값',
  'API-AUD-2026-08-23-R57-OBSERVED',
  'G4-IMP-02-S03 Q16·Q17 조회와 schema 1.0.2 후 읽기 전용 재검증',
  '202608230001_expand_submission_prompt_contract',
  '제공자·소비자 계약',
  'G4-IMP-02-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R57은 dirty <code>dev.71</code> 관찰값',
  'API-AUD-2026-08-23-R58-OBSERVED',
  'G4-IMP-02-S04 C31·C32 제어 후 읽기 전용 재검증',
  'C31·C32 구현',
  'G4-IMP-02-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R58은 dirty <code>dev.71</code> 관찰값',
  'API-AUD-2026-08-23-R59-OBSERVED',
  'G4-IMP-02-S05 실행·정규화 통합 동결 후 읽기 전용 재검증',
  '<code>oj-platform.php</code>·<code>tests/Contract/addon-contribution-composition.php</code>',
  '공개 source 경계 clean',
  'check:submission-evaluation-integration-g4',
  'G4-IMP-02-S05 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R59는 dirty <code>dev.71</code> 관찰값',
  'API-AUD-2026-08-23-R60-OBSERVED',
  'G4-IMP-03-S01 교사 검토 Append 후 읽기 전용 재검증',
  'C34 REST 비활성',
  'G4-IMP-03-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R60은 dirty <code>dev.71</code> 관찰값',
  'API-AUD-2026-08-23-R61-OBSERVED',
  'G4-IMP-03-S02 공식 포인터 CAS·C34 활성 후 읽기 전용 재검증',
  'G4-IMP-03-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R61은 dirty <code>dev.71</code> 관찰값',
  'API-AUD-2026-08-23-R62-OBSERVED',
  'G4-IMP-03-S03 점수·근거 검토 UI 후 읽기 전용 재검증',
  'G4-IMP-03-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R62는 dirty <code>dev.72</code> 관찰값',
  '127개 설계 HTML·UI 시안 1개 재실행',
  '애드온 계약 알림',
  'API-AUD-2026-08-23-R63-OBSERVED',
  '플랫폼 dev.73 병행 drift 후 읽기 전용 재검증',
  'R63 재확인 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  '1f2a722e285626eef39c94885b88c7ed2512b7025cf90c48e5219e432b82fb65',
  'R63 dev.73 dirty 관찰',
  'C34 활성',
  '추가 공개 API 0건',
  'API-AUD-2026-08-24-R64-OBSERVED',
  'G4-IMP-03-S04 stale source 통합 동결 후 읽기 전용 재검증',
  'G4-IMP-03-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R64도 dirty <code>dev.73</code>',
  '128개 설계 HTML와 UI 시안 1개',
  'API-AUD-2026-08-24-R65-OBSERVED',
  'G4-IMP-04-S01 C35 피드백 초안 후 읽기 전용 재검증',
  'G4-IMP-04-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R65도 dirty <code>dev.73</code>',
  '129개 설계 HTML·UI 시안 1개',
  'check:submission-feedback-g4',
  'API-AUD-2026-08-24-R66-OBSERVED',
  'G4-IMP-04-S02 C36 교사 수정·기각 후 읽기 전용 재검증',
  'G4-IMP-04-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R66은 dirty <code>dev.73</code>',
  '131개 설계 HTML·UI prototype 1개',
  'check:submission-feedback-review-g4',
  'API-AUD-2026-08-24-R67-OBSERVED',
  'G4-IMP-04-S03 C37 피드백 승인 후 읽기 전용 재검증',
  'G4-IMP-04-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R67은 dirty <code>dev.73</code>',
  'check:submission-feedback-approval-g4',
  'schema 표지만 output 형태로 전환해 재정규화',
  'API-AUD-2026-08-24-R68-OBSERVED',
  'G4-IMP-04-S04 피드백 작업대·통합 동결 후 읽기 전용 재검증',
  'G4-IMP-04-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R68은 dirty <code>dev.73</code>',
  'check:submission-feedback-integration-g4',
  '132개 설계 HTML·UI prototype 1개',
  '병행 fixture 관찰',
  'core <code>messaging.inbox</code> route stub 추가',
  '선택적 history node를 직접 append하던 애드온 UI 오류',
  'API-AUD-2026-08-24-R69-OBSERVED',
  'G4-IMP-05-S01 C38 학생 특기사항 초안 후 읽기 전용 재검증',
  'G4-IMP-05-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R69는 dirty <code>dev.73</code>',
  'check:student-note-draft-g4',
  'S04 권한 회귀 계약',
  'view·retry·cancel·review·report',
  '133개 설계 HTML·UI prototype 1개',
  'literal <code>oj_ai_student_note_revisions</code>',
  '0937613894ce76103dc29893a8191f49f7db6d2dc3d20130e97d6b8a61726d1e',
  '23b2634dde8936101834ea3b0c0a00ef00b443a5976c4db49c60646e0b6911ab',
  'C38 구현을 위한 플랫폼 수정',
  'API-AUD-2026-08-24-R70-OBSERVED',
  'G4-IMP-05-S02 Q18·Q19 학생 특기사항 조회 후 읽기 전용 재검증',
  'G4-IMP-05-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R70은 dirty <code>dev.73</code>',
  'check:student-note-query-g4',
  '134개 설계 HTML·UI prototype 1개',
  'RFC 4648 Base32',
  'student_visible=false',
  'report_auto_inclusion=false',
  'b6b52dfb1c5170da1ad6a343bae401c675264d07bda553d447b2a59f2ef41a73',
  'Q18·Q19 구현을 위한 플랫폼 수정',
  '다음 변화 후보:</strong> <code>G4-IMP-05-S03</code>',
  'API-AUD-2026-08-24-R71-OBSERVED',
  'G4-IMP-05-S03 C39·C40 학생 특기사항 검토·승인 후 읽기 전용 재검증',
  'G4-IMP-05-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R71은 dirty <code>dev.73</code>',
  'check:student-note-review-approval-g4',
  '135개 설계 HTML·UI prototype 1개',
  '최초 직접 작성은 평가 PublicId를 note root로 재사용',
  'C39·C40 구현을 위한 플랫폼 수정',
  '다음 변화 후보:</strong> <code>G4-IMP-05-S04</code>',
  'API-AUD-2026-08-24-R72-OBSERVED',
  'G4-IMP-05-S04 C41·C42 학생 특기사항 보고서 포함·제외 후 읽기 전용 재검증',
  'G4-IMP-05-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  '제공자 검사 전 113건, 검사 후 116건, 문서 동기화·최종 확인 시점 122건',
  '2.0.0-dev.74',
  'product version이 <code>dev.73</code>에서 <code>dev.74</code>로 바뀌었고',
  'src/Kernel/AddonApi',
  'check:student-note-report-inclusion-g4',
  '136개 설계 HTML·UI prototype 1개',
  'student_note.include_in_reports',
  'C41·C42 구현을 위한 플랫폼 수정',
  '과거 보고서 snapshot은 수정하지 않는다',
  '다음 변화 후보:</strong> <code>G4-IMP-05-S05</code>',
  'API-AUD-2026-08-24-R73-OBSERVED',
  'G4-IMP-05-S05 provenance·권한 통합 동결 후 읽기 전용 재검증',
  'G4-IMP-05-S05 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'check:student-note-integration-g4',
  '137개 설계 HTML·UI prototype 1개',
  'C39~C42 단일 승자',
  'student_note_source_stale',
  'student_visible=false',
  '초기 S05 테스트 glob',
  '로드맵 상단 IMP-05 상태가 <code>3/5 진행</code>',
  'C38~C42 통합 동결을 위한 플랫폼 수정',
  '다음 변화 후보:</strong> <code>G4-IMP-06-S01</code>',
  'student-note-integration-g4-imp-05-s05.php',
  'student-note-integration-storage-g4-imp-05-s05.php',
  'API-AUD-2026-08-24-R74-OBSERVED',
  'G4-IMP-06-S01 C43 학생 결과 최초 공개 후 읽기 전용 재검증',
  'G4-IMP-06-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'check:submission-result-release-g4',
  '138개 설계 HTML·UI prototype 1개',
  'oj_ai_submission_release_revisions',
  'C43 최초 공개를 위한 플랫폼 수정',
  '다음 변화 후보:</strong> <code>G4-IMP-06-S02</code>',
  'API-AUD-2026-08-24-R75-OBSERVED',
  'G4-IMP-06-S02 Q33 학생 본인 결과 조회 후 읽기 전용 재검증',
  'G4-IMP-06-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'check:submission-result-query-g4',
  '139개 설계 HTML·UI prototype 1개',
  'Object_Scope::TYPE_RELEASE',
  'Q33 학생 본인 조회를 위한 플랫폼 수정',
  '다음 변화 후보:</strong> <code>G4-IMP-06-S03</code>',
  '전체 <code>npm test</code>',
  '6/6 통과',
  '124개 설계 HTML·UI 시안 1개 재실행',
  'API-AUD-2026-08-24-R76-OBSERVED',
  'G4-IMP-06-S03 C44 학생 결과 공개 회수 후 읽기 전용 재검증',
  'G4-IMP-06-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'check:submission-result-withdrawal-g4',
  '140개 설계 HTML·UI prototype 1개',
  'submission_result.withdrawn',
  'C44 공개 회수를 위한 플랫폼 수정',
  'private <code>reason_note</code>',
  '다음 변화 후보:</strong> <code>G4-IMP-06-S04</code>',
  'API-AUD-2026-08-24-R77-OBSERVED',
  'G4-IMP-06-S04 C43 학생 결과 정정 공개 후 읽기 전용 재검증',
  'G4-IMP-06-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'check:submission-result-correction-g4',
  '141개 설계 HTML·UI prototype 1개',
  'submission_result.corrected',
  'C43 정정 공개를 위한 플랫폼 수정',
  'reason note와 결과 본문은 REST response·audit metadata·outbox에 넣지 않는다',
  '다음 변화 후보:</strong> <code>G4-IMP-06-S05</code>',
  'src/AddonApi/V1',
  '공개 Addon API 변화는 없다',
  'G4-IMP-02-S01',
  'API-AUD-2026-08-24-R78-OBSERVED',
  'G4-IMP-06-S05 학생 결과 화면·통합 동결 후 읽기 전용 재검증',
  'G4-IMP-06-S05 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> file write 0건',
  'check:submission-result-ui-g4',
  '142개 설계 HTML·UI prototype 1개',
  'Student_Result_Page_Handler',
  '권한 회수 즉시 본문 제거',
  '학생 결과 화면을 위한 플랫폼 수정',
  '다음 변화 후보:</strong> <code>G4-IMP-07-S01</code>',
  'G4-IMP-02-S02',
  'G4-IMP-02-R01',
  'API-AUD-2026-08-24-R79-OBSERVED',
  'G4-IMP-07-S01 학생 결과 PDF 공개 projection 후 읽기 전용 재검증',
  'G4-IMP-07-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> file write 0건',
  'check:result-print-projection-g4',
  '143개 설계 HTML·UI prototype 1개',
  'Q33_Own_Result_Pdf_Source_Query',
  'Build_Result_Print_Projection',
  'result_print.v1',
  'API-AUD-2026-08-24-R80-OBSERVED',
  'G4-IMP-07-S02 학생 결과 PDF job 후 읽기 전용 재검증',
  'G4-IMP-07-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> file write 0건',
  'check:result-pdf-job-g4',
  'repository transaction·rollback',
  '144개 설계 HTML·UI prototype 1개',
  'oj_ai.result_pdf_request@1.0.0',
  '300초 lease',
  'submission_release/result_pdf',
  'C52 결과 PDF job을 위한 플랫폼 수정',
  '다음 변화 후보:</strong> <code>G4-IMP-07-S03</code>',
  'R80은 dirty <code>dev.74</code>',
  '플랫폼 private class·table·migration을 참조하지 않는다',
  'API-AUD-2026-08-24-R81-OBSERVED',
  'G4-IMP-07-S03 학생 결과 PDF 접근 동결 후 읽기 전용 재검증',
  'G4-IMP-07-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> file write 0건',
  'check:result-pdf-access-g4',
  '145개 설계 HTML·UI prototype 1개',
  'result_pdf',
  'self_release',
  'LOCK_SH',
  '같은 크기 변조도 0 byte로 종료',
  '다음 변화 후보:</strong> <code>G4-IMP-07-S04</code>',
  'R81은 dirty <code>dev.74</code>',
  'API-AUD-2026-08-24-R82-OBSERVED',
  'G4-IMP-07-S04 학생 결과 PDF 화면·시각 통합 후 읽기 전용 재검증',
  'G4-IMP-07-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'check:result-pdf-ui-g4',
  '146개 설계 HTML·UI prototype 1개',
  'AddonPageRequestV1::locale()',
  'desktop 1280×900·mobile 390×844',
  'A4 5 page',
  '다음 변화 후보:</strong> <code>G5-IMP-01-S01</code>',
  'R82는 dirty <code>dev.74</code>',
  'API-AUD-2026-08-24-R83-OBSERVED',
  'G5-IMP-01-S01 Q20 활동지별 보고서 구현 후 읽기 전용 재검증',
  'G5-IMP-01-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'check:worksheet-report-list-g5',
  'Page 9·GET 38·POST 79 유지',
  'private cross-query 0',
  '다음 변화 후보:</strong> <code>G5-IMP-01-S02</code>',
  'R83은 <code>dev.74</code> 읽기 전용 관찰값',
  'API-AUD-2026-08-24-R84-OBSERVED',
  'G5-IMP-01-S02 Q21 배포·학생 보고서 구현 후 읽기 전용 재검증',
  'GET /oj-ai/v1/reports/worksheets/{worksheet_id}',
  'oj_ai.reporting_metrics@1.0.0',
  'G5-IMP-01-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'check:worksheet-report-detail-g5',
  '연락처·login·AI 후보값을 제외',
  '다음 변화 후보:</strong> <code>G5-IMP-01-S03</code>',
  'R84는 <code>dev.74</code> 읽기 전용 관찰값',
  'API-AUD-2026-08-24-R85-OBSERVED',
  'G5-IMP-01-S03 Q20·Q21 filter·분모 통합 후 읽기 전용 재검증',
  'oj_ai.report_filter@1.0.0',
  'oj_ai.report_denominator_contract@1.0.0',
  'not_started|in_progress|official',
  'filter-bound·private cross-query 0',
  'check:worksheet-report-filter-g5',
  'G5-IMP-01-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  '다음 변화 후보:</strong> <code>G5-IMP-01-S04</code>',
  'R85는 병행 수정 중인 dirty <code>dev.74</code>',
  'API-AUD-2026-08-24-R86-OBSERVED',
  'G5-IMP-01-S04 보고서 대시보드 구현 후 읽기 전용 재검증',
  'src/AddonApi/V1',
  '공개 symbol 37개 유지',
  '기존 Menu 기여 1개',
  'Q20→Q21→학생',
  'check:report-dashboard-g5',
  'G5-IMP-01-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R86은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-02-S01</code>',
  'API-AUD-2026-08-25-R87-OBSERVED',
  'G5-IMP-02-S01 C45 보고서 snapshot 생성 후 읽기 전용 재검증',
  'dirty 122건',
  'src/AddonApi/V1</code> 작업트리 변경 0건',
  'Page 9·GET 38·POST 79를 유지',
  'oj_ai_report_snapshots',
  '현재 Console menu assertion 47개',
  'check:report-snapshot-g5',
  'G5-IMP-02-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R87은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-02-S02</code>',
  'API-AUD-2026-08-25-R88-OBSERVED',
  'G5-IMP-02-S02 Q22 보고서 snapshot 조회 후 읽기 전용 재검증',
  'GET /oj-ai/v1/report-snapshots/{snapshot_id}',
  'manifest의 모든 worksheet object authorization',
  '저장 profile·canonical hash·snapshot identity',
  'DDL·migration·mutation 0',
  'source_manifest_hash',
  'check:report-snapshot-query-g5',
  'G5-IMP-02-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R88은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-02-S03</code>',
  'API-AUD-2026-08-25-R89-OBSERVED',
  'G5-IMP-02-S03 source manifest·hash 내부 전달 후 읽기 전용 재검증',
  '074c3411efc354c4b103358e7c90e0f77afc71895335bb7fc6e050d67d66ceba',
  'read_source_manifest()',
  'REST controller·route·Page·Console·PublicUI 등록은 0건',
  'source_manifest_json',
  'check:report-source-manifest-g5',
  'G5-IMP-02-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R89는 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-02-S04</code>',
  'API-AUD-2026-08-25-R90-OBSERVED',
  'G5-IMP-02-S04 snapshot 통합 동결 후 읽기 전용 재검증',
  '실제 C45→Q22·내부 manifest',
  'report_source_stale',
  '현재 Context와 모든 worksheet object authorization',
  '저장 canonical bytes·hash·source link',
  '동일 key·digest 동시 요청의 단일 승자',
  'check:report-snapshot-integration-g5',
  'G5-IMP-02-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R90은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-03-S01</code>',
  'API-AUD-2026-08-25-R91-OBSERVED',
  'G5-IMP-03-S01 C46·C47 보고서 내보내기 작업 등록 후 읽기 전용 재검증',
  'cd681ad8226cba9c6996bb0a1247a7a942f722df820d50327b1726df981f4ab2',
  'report_csv.v1',
  'report_print.v1',
  'oj_ai_export_jobs',
  'durable receipt replay',
  'check:report-export-enqueue-g5',
  'G5-IMP-03-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R91은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-03-S02</code>',
  'API-AUD-2026-08-25-R92-OBSERVED',
  'G5-IMP-03-S02 C50 학생 결과물 ZIP 작업 등록 후 읽기 전용 재검증',
  '4cf4bfe9472e8d355df47429a4de6dcd0115d11d9dc3dddaa8f4a46b80e1f83e',
  '074c3411efc354c4b103358e7c90e0f77afc71895335bb7fc6e050d67d66ceba',
  '33f873544bf1dc6aa6594208fb79fcf35093bb7ce5ed66e28532c6960659c64e',
  'submission_bundle.v1',
  '500 files·500 MiB',
  'report_snapshot_id=NULL',
  'source_type=assignment',
  'check:submission-bundle-enqueue-g5',
  'G5-IMP-03-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R92는 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-03-S03</code>',
  'C69 exact report snapshot JSON package',
  'API-AUD-2026-08-25-R93-OBSERVED',
  'G5-IMP-03-S03 C69 보고서 JSON package 작업 등록 후 읽기 전용 재검증',
  'f0c589ce0979599511599ed4a350611cd5e252407374678ebc5251e713d1b124',
  'report_json_package.v1',
  'source_type=report_snapshot',
  'artifact_kind=report_json_package',
  'canonical manifest bytes',
  'check:report-json-package-enqueue-g5',
  'G5-IMP-03-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R93은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-03-S04</code>',
  'Q23 export job 상태',
  '결과 PDF 공개 projection을 위한 플랫폼 수정',
  '다음 변화 후보:</strong> <code>G4-IMP-07-S02</code>',
  'G2-IMP-07-S04',
]) {
  if (!apiAudit.includes(marker)) {
    fail('platform-api-implementation-audit.html: missing audit marker ' + marker);
  }
}
for (const marker of [
  'API-AUD-2026-08-25-R94-OBSERVED',
  'G5-IMP-03-S04 Q23·Q24 작업·다운로드 후 읽기 전용 재검증',
  'Platform API change: 0',
  'context_reporter',
  'context_staff',
  '300초 lease',
  'check:report-export-job-download-g5',
  'G5-IMP-03-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R94는 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-03-S05</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R94 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R95-OBSERVED',
  'G5-IMP-03-S05 C53·C54 작업 제어 후 읽기 전용 재검증',
  'Platform API change: 0',
  'check:report-export-control-g5',
  'G5-IMP-03-S05 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R95는 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-03-S06</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R95 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R96-OBSERVED',
  'G5-IMP-03-S06 checksum·권한 통합 동결 후 읽기 전용 재검증',
  'Platform API change: 0',
  'check:report-export-integration-g5',
  'G5-IMP-03-S06 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R96은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-05-S01</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R96 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R97-OBSERVED',
  'G5-IMP-05-S01 Q28 Bridge·schema·queue 구현 후 읽기 전용 재검증',
  'Platform API change: 0',
  'AddonRuntimeContextV1::snapshot()',
  'oj_ai_evaluation_runs',
  'check:operations-diagnostics-g5',
  'G5-IMP-05-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R97은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-05-S02</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R97 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R98-OBSERVED',
  'G5-IMP-05-S02 AI routing 안전 진단 후 읽기 전용 재검증',
  'AddonAiTaskGatewayV1::canRoute',
  'AddonAiTaskGatewayV1::snapshot',
  'AddonApiProblemV1::retryable()',
  'API change: 0',
  'check:operations-diagnostics-g5',
  'G5-IMP-05-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R98은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-05-S03</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R98 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R99-OBSERVED',
  'G5-IMP-05-S03 저장소·비밀 보호 진단 후 읽기 전용 재검증',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'Platform API change: 0',
  'literal <code>oj_ai_private_files</code>',
  'check:operations-diagnostics-g5',
  'G5-IMP-05-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R99는 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-05-S04</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R99 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R100-OBSERVED',
  'G5-IMP-05-S04 운영 화면·전체 회귀 후 읽기 전용 재검증',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'Platform API change: 0',
  'Page 9·GET 38·POST 79 유지',
  'AddonAssetBundleDefinitionV1',
  '각각 최대 8개',
  '2 menu·5 asset·2 AI task',
  'check:addon-api',
  'code·details·issues·path·metrics·tasks',
  'G5-IMP-05-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R100은 병행 수정 중인 dirty <code>dev.74</code>',
  '33f873544bf1dc6aa6594208fb79fcf35093bb7ce5ed66e28532c6960659c64e',
  '다음 변화 후보:</strong> <code>G5-IMP-06-S01</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R100 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R101-OBSERVED',
  'G5-IMP-06-S01 신규 설치 후 읽기 전용 재검증',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'Platform API change: 0',
  'git status --short -- src/AddonApi/V1',
  'docs/development-c-28-addon-extension-api.html',
  '2.0.0-dev.33',
  '플랫폼 후속 정리',
  '165개 설계 HTML + UI prototype 1개·131개 finding 통과',
  'network activation 전달',
  '45개 literal <code>oj_ai_*</code> table·10 owner',
  'registration_pending',
  'SELECT-only',
  'check:fresh-install-g5',
  'G5-IMP-06-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R101은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-06-S02</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R101 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R102-OBSERVED',
  'G5-IMP-06-S02 순차 upgrade 후 읽기 전용 재검증',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'git status --short -- src/AddonApi/V1',
  '2.0.0-dev.33',
  '공개 API 의존 추가 0',
  'schema <code>1.0.0</code>은 ALTER 2건',
  'unknown·future version, owner·fingerprint·ledger·checksum·physical metadata 불일치',
  '45개 literal <code>oj_ai_*</code> table',
  '<code>wp_oj_ai_*</code>·down migration은 없다',
  '일부 G5 HTML에 <code>1.0.3</code>이 적힌 문제',
  'check:sequential-upgrade-g5',
  'G5-IMP-06-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R102는 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-06-S03</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R102 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R103-OBSERVED',
  'G5-IMP-06-S03 실패 상태·재시작 후 읽기 전용 재검증',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'git diff -- src/AddonApi/V1',
  'Addon API <code>1.1.0</code>',
  'Schema_Migration_Checkpoint',
  'schema·version·canonical JSON·SHA-256·payload·원장 상태 결속',
  'CREATE·ALTER·postcondition·checkpoint write·migration finish·최종 installation update 실패',
  '45개 CREATE 후 ALTER 직전 중단',
  '새 table·column·migration·down SQL',
  'check:failure-restart-g5',
  'G5-IMP-06-S03 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R103은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-06-S04</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R103 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R104-OBSERVED',
  'G5-IMP-06-S04 C75 정방향 복구 후 읽기 전용 재검증',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'git status --short -- src/AddonApi/V1 src/Kernel/Addon',
  'Addon API <code>1.1.0</code>',
  'commitAndFreeze → composer-&gt;compose → publishRuntimeReady → onReady',
  'AddonCapabilityKeyV1::wordpress',
  '15 capability·9 Page·2 menu·5 asset·2 AI task',
  'Schema_Recovery_Access_Policy',
  'POST /operations/schema/commands/repair',
  '24시간 backup digest',
  '새 table·column·migration·down SQL',
  'check:forward-repair-g5',
  'G5-IMP-06-S04 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R104는 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-06-S05</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R104 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R105-OBSERVED',
  'G5-IMP-06-S05 데이터 보존 회귀 후 읽기 전용 재검증',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'git status --short -- src/AddonApi/V1 src/Kernel/Addon',
  'Addon API <code>1.1.0</code>',
  'production PHP·JS·CSS',
  'Manifest 변경 0',
  '실제 schema runner와 C75 service',
  'private bytes·기존 audit/outbox',
  'schema <code>1.0.2</code>·45개 literal <code>oj_ai_*</code> table·migration 4개',
  'check:data-preservation-g5',
  'G5-IMP-06-S05 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R105는 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-07-S01</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R105 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R106-OBSERVED',
  'G5-IMP-07-S01 C61 기존 데이터 이전 preview 후 읽기 전용 재검증',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'git status --short -- src/AddonApi/V1 src/Kernel/Addon',
  'Addon API <code>1.1.0</code>',
  '15 capability·9 Page·2 menu·5 asset·2 AI task',
  '기존 <code>manage_migrations</code>',
  'POST /oj-ai/v1/migration-runs/commands/preview',
  '플랫폼 Context 저장소와 구현 namespace는 호출하지 않는다',
  'schema <code>1.0.2</code>·45개 literal <code>oj_ai_*</code> table·migration 4개',
  'check:legacy-migration-preview-g5',
  'G5-IMP-07-S01 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R106은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-07-S02</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R106 audit marker ' + marker);
}
for (const marker of [
  'API-AUD-2026-08-25-R107-OBSERVED',
  'G5-IMP-07-S02 C70 기존 데이터 이전 계획 승인 후 읽기 전용 재검증',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'git status --short -- src/AddonApi/V1 src/Kernel/Addon',
  'Addon API <code>1.1.0</code>',
  'oj_platform_register_addons → onReady',
  '2d83a2add77e6e7dc017c92b5b5a791743962fcc53a7ef60534e1a8f7bf7e8d9',
  'strict <code>npm run check:design</code>',
  '--allow-platform-drift',
  '15 capability·9 Page·2 menu·5 asset·2 AI task',
  '기존 Console Policy와 <code>manage_migrations</code>',
  'POST /oj-ai/v1/migration-runs/commands/approve',
  'oj_ai_import_runs</code>·<code>oj_ai_audit_events</code>·<code>oj_ai_outbox_events',
  'mapping_decisions',
  'check:legacy-migration-approval-g5',
  'G5-IMP-07-S02 구현·검증·문서화 과정에서 이 작업의 <code>oj-platform</code> 파일 write 0건',
  'R107은 병행 수정 중인 dirty <code>dev.74</code>',
  '다음 변화 후보:</strong> <code>G5-IMP-07-S03</code>',
]) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R107 audit marker ' + marker);
}
const r105SectionEnd = apiAudit.indexOf('</section>', apiAudit.indexOf('id="r105-observation"'));
const r106SectionStart = apiAudit.indexOf('id="r106-observation"');
const r106SectionEnd = apiAudit.indexOf('</section>', r106SectionStart);
const r107SectionStart = apiAudit.indexOf('id="r107-observation"');
const r107SectionEnd = apiAudit.indexOf('</section>', r107SectionStart);
const finalEvidenceTableStart = apiAudit.indexOf('<table>', r107SectionEnd);
if (
  r105SectionEnd < 0 ||
  r106SectionStart < r105SectionEnd ||
  r106SectionEnd < r106SectionStart ||
  r107SectionStart < r106SectionEnd ||
  r107SectionEnd < r107SectionStart ||
  finalEvidenceTableStart < r107SectionEnd
) {
  fail('platform-api-implementation-audit.html: R105, R106 and R107 must be ordered sibling sections before the final evidence table');
}

const reportExportEvidenceRow = apiAudit.indexOf('<code>npm run check:report-export-enqueue-g5</code></td><td>C46·C47 strict command');
const reportExportEvidenceBodyClose = apiAudit.indexOf('</tbody>', reportExportEvidenceRow);
const apiAuditFooter = apiAudit.indexOf('<p class="footer">', reportExportEvidenceRow);
const freshInstallEvidenceRow = apiAudit.indexOf('<code>npm run check:fresh-install-g5</code></td><td>환경·platform/API·storage preflight');
const freshInstallEvidenceBodyClose = apiAudit.indexOf('</tbody>', freshInstallEvidenceRow);
if (freshInstallEvidenceRow < 0 || freshInstallEvidenceBodyClose < freshInstallEvidenceRow || apiAuditFooter < freshInstallEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-06-S01 evidence row must remain inside the final evidence table before the footer');
}
const sequentialUpgradeEvidenceRow = apiAudit.indexOf('<code>npm run check:sequential-upgrade-g5</code></td><td>exact 1.0.0·1.0.1 순차 delta');
const sequentialUpgradeEvidenceBodyClose = apiAudit.indexOf('</tbody>', sequentialUpgradeEvidenceRow);
if (sequentialUpgradeEvidenceRow < 0 || sequentialUpgradeEvidenceBodyClose < sequentialUpgradeEvidenceRow || apiAuditFooter < sequentialUpgradeEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-06-S02 evidence row must remain inside the final evidence table before the footer');
}
const failureRestartEvidenceRow = apiAudit.indexOf('<code>npm run check:failure-restart-g5</code></td><td>checkpoint 봉투·상태 결속');
const failureRestartEvidenceBodyClose = apiAudit.indexOf('</tbody>', failureRestartEvidenceRow);
if (failureRestartEvidenceRow < 0 || failureRestartEvidenceBodyClose < failureRestartEvidenceRow || apiAuditFooter < failureRestartEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-06-S03 evidence row must remain inside the final evidence table before the footer');
}
const forwardRepairEvidenceRow = apiAudit.indexOf('<code>npm run check:forward-repair-g5</code></td><td>C75 strict body');
const forwardRepairEvidenceBodyClose = apiAudit.indexOf('</tbody>', forwardRepairEvidenceRow);
if (forwardRepairEvidenceRow < 0 || forwardRepairEvidenceBodyClose < forwardRepairEvidenceRow || apiAuditFooter < forwardRepairEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-06-S04 evidence row must remain inside the final evidence table before the footer');
}
const dataPreservationEvidenceRow = apiAudit.indexOf('<code>npm run check:data-preservation-g5</code></td><td>S01~S04·설정 seed');
const dataPreservationEvidenceBodyClose = apiAudit.indexOf('</tbody>', dataPreservationEvidenceRow);
if (dataPreservationEvidenceRow < 0 || dataPreservationEvidenceBodyClose < dataPreservationEvidenceRow || apiAuditFooter < dataPreservationEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-06-S05 evidence row must remain inside the final evidence table before the footer');
}
const legacyMigrationPreviewEvidenceRow = apiAudit.indexOf('<code>npm run check:legacy-migration-preview-g5</code></td><td>C61 exact 0.1.2 source profile');
const legacyMigrationPreviewEvidenceBodyClose = apiAudit.indexOf('</tbody>', legacyMigrationPreviewEvidenceRow);
if (legacyMigrationPreviewEvidenceRow < 0 || legacyMigrationPreviewEvidenceBodyClose < legacyMigrationPreviewEvidenceRow || apiAuditFooter < legacyMigrationPreviewEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-07-S01 evidence row must remain inside the final evidence table before the footer');
}
const legacyMigrationApprovalEvidenceRow = apiAudit.indexOf('<code>npm run check:legacy-migration-approval-g5</code></td><td>C70 token·site·key·source·plan 재검산');
const legacyMigrationApprovalEvidenceBodyClose = apiAudit.indexOf('</tbody>', legacyMigrationApprovalEvidenceRow);
if (legacyMigrationApprovalEvidenceRow < 0 || legacyMigrationApprovalEvidenceBodyClose < legacyMigrationApprovalEvidenceRow || apiAuditFooter < legacyMigrationApprovalEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-07-S02 evidence row must remain inside the final evidence table before the footer');
}
const fullTestEvidenceRow = apiAudit.indexOf('<code>npm test</code></td><td>설계 172개 HTML');
const fullTestEvidenceBodyClose = apiAudit.indexOf('</tbody>', fullTestEvidenceRow);
if (fullTestEvidenceRow < 0 || fullTestEvidenceBodyClose < fullTestEvidenceRow || apiAuditFooter < fullTestEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-07-S03-D3-D2 full-test evidence row must remain inside the final evidence table before the footer');
}
if (reportExportEvidenceRow < 0 || reportExportEvidenceBodyClose < reportExportEvidenceRow || apiAuditFooter < reportExportEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-03-S01 evidence row must remain inside the final evidence table before the footer');
}
const submissionBundleEvidenceRow = apiAudit.indexOf('<code>npm run check:submission-bundle-enqueue-g5</code></td><td>C50 strict command');
const submissionBundleEvidenceBodyClose = apiAudit.indexOf('</tbody>', submissionBundleEvidenceRow);
if (submissionBundleEvidenceRow < 0 || submissionBundleEvidenceBodyClose < submissionBundleEvidenceRow || apiAuditFooter < submissionBundleEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-03-S02 evidence row must remain inside the final evidence table before the footer');
}
const reportJsonPackageEvidenceRow = apiAudit.indexOf('<code>npm run check:report-json-package-enqueue-g5</code></td><td>C69 strict command');
const reportJsonPackageEvidenceBodyClose = apiAudit.indexOf('</tbody>', reportJsonPackageEvidenceRow);
if (reportJsonPackageEvidenceRow < 0 || reportJsonPackageEvidenceBodyClose < reportJsonPackageEvidenceRow || apiAuditFooter < reportJsonPackageEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-03-S03 evidence row must remain inside the final evidence table before the footer');
}
const reportExportJobDownloadEvidenceRow = apiAudit.indexOf('<code>npm run check:report-export-job-download-g5</code></td><td>실제 CSV');
const reportExportJobDownloadEvidenceBodyClose = apiAudit.indexOf('</tbody>', reportExportJobDownloadEvidenceRow);
if (reportExportJobDownloadEvidenceRow < 0 || reportExportJobDownloadEvidenceBodyClose < reportExportJobDownloadEvidenceRow || apiAuditFooter < reportExportJobDownloadEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-03-S04 evidence row must remain inside the final evidence table before the footer');
}
const auditCriticalCount = (apiAudit.match(/<td>Critical<\/td>/g) ?? []).length;
const auditHighCount = (apiAudit.match(/<td>High<\/td>/g) ?? []).length;
const reportExportControlEvidenceRow = apiAudit.indexOf('<code>npm run check:report-export-control-g5</code></td><td>C53 queued-only');
const reportExportControlEvidenceBodyClose = apiAudit.indexOf('</tbody>', reportExportControlEvidenceRow);
if (reportExportControlEvidenceRow < 0 || reportExportControlEvidenceBodyClose < reportExportControlEvidenceRow || apiAuditFooter < reportExportControlEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-03-S05 evidence row must remain inside the final evidence table before the footer');
}
const auditMediumCount = (apiAudit.match(/<td>Medium<\/td>/g) ?? []).length;
const reportExportIntegrationEvidenceRow = apiAudit.indexOf('<code>npm run check:report-export-integration-g5</code></td><td>S01~S05');
const reportExportIntegrationEvidenceBodyClose = apiAudit.indexOf('</tbody>', reportExportIntegrationEvidenceRow);
if (reportExportIntegrationEvidenceRow < 0 || reportExportIntegrationEvidenceBodyClose < reportExportIntegrationEvidenceRow || apiAuditFooter < reportExportIntegrationEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-03-S06 evidence row must remain inside the final evidence table before the footer');
}
const operationsAiRoutingEvidenceRow = apiAudit.indexOf('<code>npm run check:operations-diagnostics-g5-s02</code></td><td>S01과 두 AI task');
const operationsAiRoutingEvidenceBodyClose = apiAudit.indexOf('</tbody>', operationsAiRoutingEvidenceRow);
if (operationsAiRoutingEvidenceRow < 0 || operationsAiRoutingEvidenceBodyClose < operationsAiRoutingEvidenceRow || apiAuditFooter < operationsAiRoutingEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-05-S02 evidence row must remain inside the final evidence table before the footer');
}
const operationsStorageEvidenceRow = apiAudit.indexOf('<code>npm run check:operations-diagnostics-g5-s03</code></td><td>S01·S02와 private path');
const operationsStorageEvidenceBodyClose = apiAudit.indexOf('</tbody>', operationsStorageEvidenceRow);
if (operationsStorageEvidenceRow < 0 || operationsStorageEvidenceBodyClose < operationsStorageEvidenceRow || apiAuditFooter < operationsStorageEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-05-S03 evidence row must remain inside the final evidence table before the footer');
}
const operationsDiagnosticsUiEvidenceRow = apiAudit.indexOf('<code>npm run check:operations-diagnostics-g5</code></td><td>S01~S03, 기존 설정 Page');
const operationsDiagnosticsUiEvidenceBodyClose = apiAudit.indexOf('</tbody>', operationsDiagnosticsUiEvidenceRow);
if (operationsDiagnosticsUiEvidenceRow < 0 || operationsDiagnosticsUiEvidenceBodyClose < operationsDiagnosticsUiEvidenceRow || apiAuditFooter < operationsDiagnosticsUiEvidenceBodyClose) {
  fail('platform-api-implementation-audit.html: G5 IMP-05-S04 evidence row must remain inside the final evidence table before the footer');
}
if (auditCriticalCount !== 2 || auditHighCount !== 10 || auditMediumCount !== 3) {
  fail('platform-api-implementation-audit.html: expected Critical 2, High 10 and Medium 3 findings');
}

const plateTypesPath = path.join(root, 'resources', 'plate', 'whole-editor', 'src', 'types.ts');
const plateSchemaPath = path.join(root, 'resources', 'plate', 'whole-editor', 'src', 'utils', 'schema.ts');
const platePackageLockPath = path.join(root, 'resources', 'plate', 'whole-editor', 'package-lock.json');
const plateDocumentServicePath = path.join(root, 'src', 'Worksheet', 'Plate_Document_Service.php');
const plateTypes = await readFile(plateTypesPath, 'utf8').catch(() => fail('Plate types source: missing'));
const plateSchema = await readFile(plateSchemaPath, 'utf8').catch(() => fail('Plate schema source: missing'));
const platePackageLock = await readFile(platePackageLockPath, 'utf8').catch(() => fail('Plate package lock: missing'));
const plateDocumentService = await readFile(plateDocumentServicePath, 'utf8').catch(() => fail('Plate document service: missing'));

const platformRoot = process.env.OJ_AI_PLATFORM_ROOT
  ? path.resolve(process.env.OJ_AI_PLATFORM_ROOT)
  : path.resolve(root, "..", "oj-integration-rebuild", "oj-platform");
const platformContractPath = path.join(platformRoot, 'docs', 'development-c-28-addon-api-v1-contract.html');
const platformDesignPath = path.join(platformRoot, 'docs', 'development-c-28-addon-extension-api.html');
const platformMigrationPath = path.join(platformRoot, 'docs', 'development-c-28-addon-onready-migration.html');
const platformReleaseEvidencePath = path.join(platformRoot, 'docs', 'development-c-28-addon-api-v1-release-evidence.html');
const platformDocsIndexPath = path.join(platformRoot, 'docs', 'index.html');
const platformSequencePath = path.join(platformRoot, 'docs', 'development-sequence.html');
const platformBaselinePath = path.join(platformRoot, 'docs', 'd-006-current-state-baseline.html');
const platformCommonPath = path.join(platformRoot, 'docs', 'development-common-foundation.html');
const platformDs16Path = path.join(platformRoot, 'docs', 'development-ds-16-integration-freeze.html');
const platformMenuMapPath = path.join(platformRoot, 'docs', 'user-menu-page-access-data-map.html');
const platformBuilderPath = path.join(platformRoot, 'src', 'AddonApi', 'V1', 'AddonManifestBuilderV1.php');
const platformLifecyclePath = path.join(platformRoot, 'src', 'Kernel', 'Addon', 'AddonLifecycleManager.php');
const platformContextImplementationPath = path.join(platformRoot, 'src', 'Kernel', 'Addon', 'RealAddonContextGateway.php');
const platformContextFixturePath = path.join(platformRoot, 'tests', 'Contract', 'addon-i05-runtime.php');
const platformLearningClassPath = path.join(platformRoot, 'modules', 'Classes', 'Domain', 'LearningClass.php');
const platformAiRequestPath = path.join(platformRoot, 'src', 'AddonApi', 'V1', 'AddonAiTaskRequestV1.php');
const platformAiResultPath = path.join(platformRoot, 'src', 'AddonApi', 'V1', 'AddonAiTaskResultV1.php');
const platformAiRuntimePath = path.join(platformRoot, 'src', 'Kernel', 'Addon', 'AddonAiTaskRuntimeGateway.php');
const platformUnavailableAiRuntimePath = path.join(platformRoot, 'src', 'Kernel', 'Addon', 'UnavailableAddonAiTaskGateway.php');
const platformAiRouterPath = path.join(platformRoot, 'modules', 'Settings', 'Application', 'StoredAiTaskRouter.php');
const platformAiPromptFactoryPath = path.join(platformRoot, 'modules', 'Settings', 'Application', 'AiTaskPromptFactory.php');
const platformAiDefinitionPath = path.join(platformRoot, 'src', 'AddonApi', 'V1', 'AddonAiTaskDefinitionV1.php');
const platformAiProfilePath = path.join(platformRoot, 'modules', 'Settings', 'Domain', 'AiProviderProfile.php');
const platformAiHttpResponsePath = path.join(platformRoot, 'modules', 'Settings', 'Contract', 'AiHttpResponse.php');
const platformEntryPath = path.join(platformRoot, 'oj-platform.php');
const platformRouteContributorPath = path.join(platformRoot, 'src', 'Kernel', 'Addon', 'AddonRouteContributor.php');
const platformConsoleAccessPath = path.join(platformRoot, 'src', 'Kernel', 'Addon', 'AddonConsoleRouteAccessRule.php');
const platformRouteHandlerPath = path.join(platformRoot, 'src', 'Kernel', 'Addon', 'AddonRouteHandler.php');
const platformCapabilityKeysPath = path.join(platformRoot, 'src', 'Kernel', 'Addon', 'AddonCapabilityKeys.php');
const platformPublicMenuPath = path.join(platformRoot, 'modules', 'PublicUi', 'Application', 'PublicMenuSettingsService.php');
const platformCapabilityAssignmentPath = path.join(platformRoot, 'modules', 'Permission', 'Infrastructure', 'WordPress', 'WordPressAddonCapabilityAssignmentGateway.php');
const platformCapabilityKeyPath = path.join(platformRoot, 'src', 'AddonApi', 'V1', 'AddonCapabilityKeyV1.php');
const platformAddonStatusPath = path.join(platformRoot, 'src', 'Kernel', 'Integration', 'Operations', 'AddonSystemStatusProvider.php');
const platformCompositionRootPath = path.join(platformRoot, 'src', 'Kernel', 'Composition', 'CompositionRoot.php');
const platformLearningGatewayPath = path.join(platformRoot, 'src', 'AddonApi', 'V1', 'AddonLearningContextGatewayV1.php');
const platformAssignmentRosterGatewayPath = path.join(platformRoot, 'src', 'AddonApi', 'V1', 'AddonAssignmentRosterGatewayV1.php');
const platformAiInstructionPath = path.join(platformRoot, 'src', 'AddonApi', 'V1', 'AddonAiTaskInstructionV1.php');
const platformRuntimeReadinessPath = path.join(platformRoot, 'src', 'Kernel', 'Addon', 'AddonRuntimeReadiness.php');
const platformContextI06FixturePath = path.join(platformRoot, 'tests', 'Contract', 'addon-i06-context.php');
const platformAiInstructionFixturePath = path.join(platformRoot, 'tests', 'Contract', 'addon-i06-ai-instruction.php');
const removedRuntimeGatewayPath = path.join(platformRoot, 'src', 'AddonApi', 'V1', 'AddonRuntimeGatewayV1.php');
const platformContract = await readFile(platformContractPath, 'utf8').catch(() => fail('platform C-28 API v1 contract: missing'));
const platformDesign = await readFile(platformDesignPath, 'utf8').catch(() => fail('platform C-28 extension design: missing'));
const platformMigration = await readFile(platformMigrationPath, 'utf8').catch(() => fail('platform C-28 onReady migration guide: missing'));
const platformReleaseEvidence = await readFile(platformReleaseEvidencePath, 'utf8').catch(() => fail('platform C-28 API 1.1 release evidence: missing'));
const platformDocsIndex = await readFile(platformDocsIndexPath, 'utf8').catch(() => fail('platform docs index: missing'));
const platformSequence = await readFile(platformSequencePath, 'utf8').catch(() => fail('platform development sequence: missing'));
const platformBaseline = await readFile(platformBaselinePath, 'utf8').catch(() => fail('platform D-006 baseline: missing'));
const platformCommon = await readFile(platformCommonPath, 'utf8').catch(() => fail('platform common foundation: missing'));
const platformDs16 = await readFile(platformDs16Path, 'utf8').catch(() => fail('platform DS-16: missing'));
const platformMenuMap = await readFile(platformMenuMapPath, 'utf8').catch(() => fail('platform user menu map: missing'));
const platformBuilder = await readFile(platformBuilderPath, 'utf8').catch(() => fail('platform AddonManifestBuilderV1 source: missing'));
const platformLifecycle = await readFile(platformLifecyclePath, 'utf8')
  .catch(() => fail('platform AddonLifecycleManager source: missing'));
const platformContextImplementation = await readFile(platformContextImplementationPath, 'utf8')
  .catch(() => fail('platform RealAddonContextGateway source: missing'));
const platformContextFixture = await readFile(platformContextFixturePath, 'utf8')
  .catch(() => fail('platform Context I05 fixture: missing'));
const platformLearningClass = await readFile(platformLearningClassPath, 'utf8')
  .catch(() => fail('platform LearningClass source: missing'));
const platformAiRequest = await readFile(platformAiRequestPath, 'utf8')
  .catch(() => fail('platform AddonAiTaskRequestV1 source: missing'));
const platformAiResult = await readFile(platformAiResultPath, 'utf8')
  .catch(() => fail('platform AddonAiTaskResultV1 source: missing'));
const platformAiRuntime = await readFile(platformAiRuntimePath, 'utf8')
  .catch(() => fail('platform AddonAiTaskRuntimeGateway source: missing'));
const platformUnavailableAiRuntime = await readFile(platformUnavailableAiRuntimePath, 'utf8')
  .catch(() => fail('platform UnavailableAddonAiTaskGateway source: missing'));
const platformAiRouter = await readFile(platformAiRouterPath, 'utf8')
  .catch(() => fail('platform StoredAiTaskRouter source: missing'));
const platformAiPromptFactory = await readFile(platformAiPromptFactoryPath, 'utf8')
  .catch(() => fail('platform AiTaskPromptFactory source: missing'));
const platformAiDefinition = await readFile(platformAiDefinitionPath, 'utf8')
  .catch(() => fail('platform AddonAiTaskDefinitionV1 source: missing'));
const platformAiProfile = await readFile(platformAiProfilePath, 'utf8')
  .catch(() => fail('platform AiProviderProfile source: missing'));
const platformAiHttpResponse = await readFile(platformAiHttpResponsePath, 'utf8')
  .catch(() => fail('platform AiHttpResponse source: missing'));
const platformEntry = await readFile(platformEntryPath, 'utf8')
  .catch(() => fail('platform plugin entry source: missing'));
const platformRouteContributor = await readFile(platformRouteContributorPath, 'utf8')
  .catch(() => fail('platform AddonRouteContributor source: missing'));
const platformConsoleAccess = await readFile(platformConsoleAccessPath, 'utf8')
  .catch(() => fail('platform AddonConsoleRouteAccessRule source: missing'));
const platformRouteHandler = await readFile(platformRouteHandlerPath, 'utf8')
  .catch(() => fail('platform AddonRouteHandler source: missing'));
const platformCapabilityKeys = await readFile(platformCapabilityKeysPath, 'utf8')
  .catch(() => fail('platform AddonCapabilityKeys source: missing'));
const platformPublicMenu = await readFile(platformPublicMenuPath, 'utf8')
  .catch(() => fail('platform PublicMenuSettingsService source: missing'));
const platformCapabilityAssignment = await readFile(platformCapabilityAssignmentPath, 'utf8')
  .catch(() => fail('platform addon capability assignment source: missing'));
const platformAddonStatus = await readFile(platformAddonStatusPath, 'utf8')
  .catch(() => fail('platform AddonSystemStatusProvider source: missing'));
const platformCompositionRoot = await readFile(platformCompositionRootPath, 'utf8')
  .catch(() => fail('platform CompositionRoot source: missing'));
const platformLearningGateway = await readFile(platformLearningGatewayPath, 'utf8')
  .catch(() => fail('platform AddonLearningContextGatewayV1 source: missing'));
const platformAssignmentRosterGateway = await readFile(platformAssignmentRosterGatewayPath, 'utf8')
  .catch(() => fail('platform AddonAssignmentRosterGatewayV1 source: missing'));
const platformAiInstruction = await readFile(platformAiInstructionPath, 'utf8')
  .catch(() => fail('platform AddonAiTaskInstructionV1 source: missing'));
const platformRuntimeReadiness = await readFile(platformRuntimeReadinessPath, 'utf8')
  .catch(() => fail('platform AddonRuntimeReadiness source: missing'));
const platformContextI06Fixture = await readFile(platformContextI06FixturePath, 'utf8')
  .catch(() => fail('platform C28-I06 Context fixture: missing'));
const platformAiInstructionFixture = await readFile(platformAiInstructionFixturePath, 'utf8')
  .catch(() => fail('platform C28-I06 AI instruction fixture: missing'));

const addonApiV1Entries = await readdir(path.join(platformRoot, 'src', 'AddonApi', 'V1'), { withFileTypes: true });
const approvedPlatformCompatibility = JSON.parse(await readFile(path.join(root, 'resources/contracts/platform-compatibility-baseline-v1.json'), 'utf8'));
if (approvedPlatformCompatibility.contract !== 'oj_ai.platform_compatibility_baseline@1') {
  fail('platform compatibility baseline: invalid contract');
}
const addonApiV1Files = addonApiV1Entries
  .filter((entry) => entry.isFile() && entry.name.endsWith('.php'))
  .map((entry) => path.join(platformRoot, 'src', 'AddonApi', 'V1', entry.name));
let publicInterfaceCount = 0;
let publicFinalClassCount = 0;
for (const publicFile of addonApiV1Files) {
  const source = await readFile(publicFile, 'utf8');
  if (/\binterface\s+[A-Za-z_][A-Za-z0-9_]*/.test(source)) {
    publicInterfaceCount += 1;
  } else if (/\bfinal class\s+[A-Za-z_][A-Za-z0-9_]*/.test(source)) {
    publicFinalClassCount += 1;
  } else {
    fail('platform Addon API public file has no interface/final class declaration: ' + path.basename(publicFile));
  }
}
// R163: optional editor interfaces extend, rather than replace, the v1 runtime.
if (publicInterfaceCount !== approvedPlatformCompatibility.public_interfaces || publicFinalClassCount !== approvedPlatformCompatibility.public_final_classes || addonApiV1Files.length !== approvedPlatformCompatibility.public_files) {
  fail(
    'platform Addon API public surface changed: interface '
      + publicInterfaceCount + ', final classes ' + publicFinalClassCount + ', files ' + addonApiV1Files.length
  );
}

for (const [file, markers] of Object.entries({
  'AddonEditorGatewayV1.php': ['interface AddonEditorGatewayV1', 'public function current(): AddonEditorSnapshotV1;'],
  'AddonEditorRuntimeContextV1.php': ['interface AddonEditorRuntimeContextV1 extends AddonRuntimeContextV1', 'public function editor(): AddonEditorGatewayV1;'],
  'AddonEditorSnapshotV1.php': ['final class AddonEditorSnapshotV1', 'public function isAvailable(): bool', 'public function coreUrl(): string', 'public function baseUrl(): string'],
})) {
  const source = await readFile(path.join(platformRoot, 'src', 'AddonApi', 'V1', file), 'utf8');
  for (const marker of markers) {
    if (!source.includes(marker)) fail('platform optional editor API changed: ' + file + ' -> ' + marker);
  }
}

const auditedPlatformFiles = [
  ...await collectPhpFiles(path.join(platformRoot, 'src', 'AddonApi', 'V1')),
  ...await collectPhpFiles(path.join(platformRoot, 'src', 'Kernel', 'Addon')),
  platformAiRouterPath,
  platformAiPromptFactoryPath,
  path.join(platformRoot, 'modules', 'AdminConsole', 'Navigation', 'ConsoleMenuResolver.php'),
  path.join(platformRoot, 'modules', 'AdminConsole', 'Contract', 'MenuDefinition.php'),
  platformPublicMenuPath,
  ...[
    'addon-api-v1.php',
    'addon-runtime-dispatch.php',
    'addon-i05-runtime.php',
    'addon-contribution-composition.php',
    'addon-i06-context.php',
    'addon-i06-ai-instruction.php',
  ].map((name) => path.join(platformRoot, 'tests', 'Contract', name)),
  path.join(platformRoot, 'tests', 'Unit', 'console-menus.php'),
  platformEntryPath,
  platformAddonStatusPath,
  platformCompositionRootPath,
].sort((left, right) => {
  const leftRelative = path.relative(platformRoot, left).replaceAll('\\', '/');
  const rightRelative = path.relative(platformRoot, right).replaceAll('\\', '/');
  return leftRelative.localeCompare(rightRelative, 'en');
});
const platformAuditHash = createHash('sha256');
for (const auditedFile of auditedPlatformFiles) {
  const relativePath = path.relative(platformRoot, auditedFile).replaceAll('\\', '/');
  platformAuditHash.update(relativePath);
  platformAuditHash.update(Buffer.from([0]));
  platformAuditHash.update(await readFile(auditedFile));
  platformAuditHash.update(Buffer.from([0]));
}
const actualPlatformAuditFingerprint = platformAuditHash.digest('hex');
const expectedPlatformAuditFingerprint = approvedPlatformCompatibility.implementation_audit.sha256;
if (auditedPlatformFiles.length !== approvedPlatformCompatibility.implementation_audit.file_count || actualPlatformAuditFingerprint !== expectedPlatformAuditFingerprint) {
  reportPlatformDrift(
    'platform implementation audit baseline changed: files '
      + auditedPlatformFiles.length + ', SHA-256 ' + actualPlatformAuditFingerprint
      + '; review against ' + approvedPlatformCompatibility.review_id + ' before release approval'
  );
}
const platformCapabilityKey = await readFile(platformCapabilityKeyPath, 'utf8')
  .catch(() => fail('platform AddonCapabilityKeyV1 source: missing'));
for (const marker of [
  'final class AddonCapabilityKeyV1',
  "private const WORDPRESS_PREFIX = 'oj_addon_';",
  'public static function wordpress(string $declaredKey): string',
  "ValueValidator::capabilityKey($declaredKey, 'declaredKey');",
]) {
  if (!platformCapabilityKey.includes(marker)) fail('platform AddonCapabilityKeyV1 baseline changed: ' + marker);
}
if (!platformCapabilityKeys.includes('return AddonCapabilityKeyV1::wordpress($addonCapabilityKey);')) {
  fail('platform internal addon capability mapper no longer delegates to AddonCapabilityKeyV1');
}
for (const [source, marker, label] of [
  [platformLearningGateway, 'interface AddonLearningContextGatewayV1 extends AddonContextGatewayV1', 'learning context gateway'],
  [platformAssignmentRosterGateway, 'interface AddonAssignmentRosterGatewayV1 extends AddonContextGatewayV1', 'assignment roster gateway'],
  [platformAiInstruction, 'final class AddonAiTaskInstructionV1', 'AI task instruction'],
  [platformAiInstruction, "ValueValidator::text($normalized, 'content', 16384, false, true);", 'AI instruction limit'],
  [platformAiInstruction, "$this->contentHash = hash('sha256', $normalized);", 'AI instruction hash'],
]) {
  if (!source.includes(marker)) fail('platform API 1.1 source changed: ' + label + ' marker ' + marker);
}
await access(removedRuntimeGatewayPath)
  .then(() => fail('platform source: removed AddonRuntimeGatewayV1.php still exists'))
  .catch(() => {});
checkBalancedTags(platformContract, 'development-c-28-addon-api-v1-contract.html');
checkBalancedTags(platformDesign, 'development-c-28-addon-extension-api.html');
checkBalancedTags(platformMigration, 'development-c-28-addon-onready-migration.html');
checkBalancedTags(platformReleaseEvidence, 'development-c-28-addon-api-v1-release-evidence.html');
checkBalancedTags(platformDocsIndex, 'platform docs/index.html');
checkBalancedTags(platformSequence, 'platform development-sequence.html');
checkBalancedTags(platformBaseline, 'platform d-006-current-state-baseline.html');
checkBalancedTags(platformCommon, 'platform development-common-foundation.html');
checkBalancedTags(platformDs16, 'platform development-ds-16-integration-freeze.html');
checkBalancedTags(platformMenuMap, 'platform user-menu-page-access-data-map.html');
for (const marker of [
  'C28-I06 · Addon API 1.1 · Release Evidence',
  'API 1.1.0 · 37 symbol',
  '47개 script',
  '61a7252239d11d94329458bbefd77bb625962b6b645e782df476baf0fc4ed751',
  '9a5388c371368f9db03ff1ff73652abb259890a76dbf9becc4573b71797e89fd',
  'source_dirty=true',
  'source_dirty=false',
  '업그레이드 절차',
  '롤백 절차',
  'clean production build 대기',
]) {
  if (!platformReleaseEvidence.includes(marker)) fail(`platform release evidence: missing marker ${marker}`);
}
for (const [label, source, markers] of [
  ['docs index', platformDocsIndex, ['C28-I01~I06', 'Addon API 1.1', 'development-c-28-addon-api-v1-release-evidence.html']],
  ['development sequence', platformSequence, ['C28-I01~I06 코드 관문 통과', '검증 ZIP 통과', 'clean production package']],
  ['D-006 baseline', platformBaseline, ['onReady(callable)', 'Addon API 1.1·C28-I06 코드 관문 통과']],
  ['common foundation', platformCommon, ['dev.33·API 1.1 구현', 'API 1.1 릴리스 증거']],
  ['DS-16', platformDs16, ['Addon API 1.1 코드·검증 ZIP 통과', '공개 37개', 'clean production package']],
  ['user menu map', platformMenuMap, ['Addon API 1.1·RC02 구현', 'scope·feature·handler', 'API 1.1 증거서']],
]) {
  for (const marker of markers) {
    if (!source.includes(marker)) fail(`platform ${label}: missing current C-28 marker ${marker}`);
  }
}
if (platformBaseline.includes('<code>oj_platform_loaded</code> Runtime Gateway')) {
  fail('platform D-006 baseline: obsolete oj_platform_loaded Runtime Gateway remains current');
}
for (const obsoletePhrase of ['부팅 순서와 두 WordPress action', 'root가 두 action callback을 선언한다']) {
  if (platformContract.includes(obsoletePhrase)) fail(`platform C-28 API contract: obsolete AM-01 phrase found ${obsoletePhrase}`);
}
for (const marker of [
  'C28-API-V1-LOCK-01',
  'development-c-28-addon-api-v1-contract.html',
  'Addon API 1.1.0',
  '공개 symbol 37개',
  'oj_platform_register_addons',
  'onReady(callable',
  'AddonRuntimeContextV1',
  'C28-I01~I06 구현 완료',
  'AddonLearningContextGatewayV1',
  'AddonAssignmentRosterGatewayV1',
  'AddonAiTaskInstructionV1',
  'instruction(): ?AddonAiTaskInstructionV1',
  '16,384 bytes',
  'system → system → user',
  'requiresAddonApi &gt;= 1.1.0',
  'interface 8개·DTO 28개·권한 utility 1개',
  'C28-I06-LC01',
  'C28-I06-CG01',
  'C28-I06-CG11',
  'C28-I06-AJ08',
  'C28-I06-RC01',
  'C28-I06-RC05',
  'AddonCapabilityKeyV1::wordpress(string $declaredKey): string',
  'A-DS-15 G0 구현 결과',
  'A-DS-16 G0 구현 결과',
  'a-ds-16-cross-validation-freeze.html',
  'AddonRuntimeReadiness',
]) {
  if (!platformDesign.includes(marker)) fail(`platform C-28 design: missing marker ${marker}`);
}
for (const marker of [
  'Amendment AM-01',
  'AddonManifestBuilderV1::onReady(callable $callback)',
  'AddonRuntimeContextV1',
  'callback이 <strong>전혀 호출되지 않는다</strong>',
  'C28-I06-LC01',
  '같은 부팅에서 재시도하지 않는다',
  '현재 Addon API 1.1.0',
  'pending|ready|failed|not_required',
]) {
  if (!platformMigration.includes(marker)) fail(`platform C-28 onReady migration: missing marker ${marker}`);
}
for (const marker of [
  'interface AddonManifestBuilderV1',
  'public function onReady(callable $callback): void;',
  'Called at most once per registration.',
]) {
  if (!platformBuilder.includes(marker)) fail(`platform AddonManifestBuilderV1 source: missing marker ${marker}`);
}
for (const marker of [
  'public function publishRuntimeReady(): void',
  '$this->runtimePublished = true;',
  '$this->readiness->register($entry->addonId(), $entry->onReadyCallback() !== null);',
  '$onReady($context);',
  '$this->readiness->markReady($entry->addonId());',
  '$this->readiness->markFailed($entry->addonId());',
  '$this->errors->report($error);',
]) {
  if (!platformLifecycle.includes(marker)) {
    fail('platform lifecycle baseline changed; reconcile C28-I06-LC01 marker ' + marker);
  }
}
for (const marker of ['pending', 'ready', 'failed', 'not_required', 'addon_runtime_callback_failed']) {
  if (!platformRuntimeReadiness.includes(marker)) fail('platform Runtime readiness changed: ' + marker);
}
const runtimeCallbackInvocationCount = (platformLifecycle.match(/\$onReady\(\$context\);/g) ?? []).length;
if (runtimeCallbackInvocationCount !== 1) {
  fail('platform lifecycle callback dispatch changed; reconcile C28-I06-LC01');
}
for (const marker of [
  'final class RealAddonContextGateway implements',
  'AddonLearningContextGatewayV1,',
  'AddonAssignmentRosterGatewayV1',
  'public function listLearningContexts(AddonLearningContextQueryV1 $query): AddonContextPageV1',
  'public function listAssignmentLearners(',
  "$request->operation() === 'participate'",
  '! $class->isEnabled()',
  '! $group->isEnabled()',
  'private const MAX_ASSIGNMENT_LEARNERS = 5000;',
]) {
  if (!platformContextImplementation.includes(marker)) {
    fail('platform Context implementation baseline changed; reconcile A-DS-06 marker ' + marker);
  }
}
for (const marker of [
  'C28-I06 Context 계약 검사 통과',
  'CG01 self participate',
  'CG02 active learning list',
  'CG11 authorized roster·names·race·5000 cap',
]) {
  if (!platformContextI06Fixture.includes(marker)) fail('platform C28-I06 Context fixture changed: ' + marker);
}
for (const marker of [
  '$start = 0;',
  'continue; // one malformed entry must not discard the rest of the page',
  "return new AddonContextAccessDecisionV1(false, 'addon_context_unavailable', null, $correlationId);",
  "'addon_context_unavailable',",
]) {
  if (!platformContextImplementation.includes(marker)) {
    fail('platform Context implementation baseline changed; reconcile C28-I06-CG08~CG10 marker ' + marker);
  }
}
for (const marker of [
  '// 3. Student (no staff role) -> deny',
  '// 9. learnerUserId is an active participant of this class -> allow',
  '// 10. contextType = site/user -> always deny',
  "// 14. snapshot() mirrors authorize()'s staff gate and mandatory revision check",
]) {
  if (!platformContextFixture.includes(marker)) {
    fail('platform Context fixture baseline changed; reconcile A-DS-06 marker ' + marker);
  }
}
for (const field of ['academic_year', 'grade_label']) {
  if (platformLearningClass.includes(field)) {
    fail('platform LearningClass now contains ' + field + '; reconcile C28-I06-CG03 and A-DS-06');
  }
}
for (const marker of [
  'final class AddonAiTaskRequestV1',
  'private readonly int $actorUserId',
  'private readonly string $idempotencyKey',
  "ValueValidator::safeToken($this->idempotencyKey, 'idempotencyKey', 128);",
  "ValueValidator::jsonMap($this->input, 'input', 1048576);",
]) {
  if (!platformAiRequest.includes(marker)) {
    fail('platform AI request baseline changed; reconcile A-DS-07 marker ' + marker);
  }
}
if (platformAiRequest.includes('expectedRoutingRevision')) {
  fail('platform AI request now has expectedRoutingRevision; reconcile C28-I06-AJ02 and A-DS-07');
}
for (const marker of [
  'final class AddonAiTaskRuntimeGateway implements AddonAiTaskGatewayV1',
  "$request->contractVersion() !== $definition->inputContractVersion()",
  "$definition->sourceKind() === 'platform_submission'",
  'new AiTaskRequest($taskKey, $request->contractVersion(), $request->input());',
  'addon_ai_task_unroutable',
  'addon_ai_task_failed',
]) {
  if (!platformAiRuntime.includes(marker)) {
    fail('platform AI Runtime baseline changed; reconcile A-DS-07 marker ' + marker);
  }
}
const aiExecuteStart = platformAiRuntime.indexOf('public function execute(AddonAiTaskRequestV1 $request)');
const aiExecuteEnd = platformAiRuntime.indexOf('private static function isWellFormedTaskKey', aiExecuteStart);
const aiExecuteSource = platformAiRuntime.slice(aiExecuteStart, aiExecuteEnd);
const executeSnapshotCount = (aiExecuteSource.match(/\$this->aiTaskRouter->snapshot\(/g) ?? []).length;
if (
  executeSnapshotCount !== 1
  || !aiExecuteSource.includes('(string) $assignment->routingRevision()')
  || aiExecuteSource.indexOf('$this->aiTaskRouter->snapshot(') > aiExecuteSource.indexOf('$this->aiTaskRouter->execute(')
) {
  fail('platform AI result routing snapshot timing changed; reconcile C28-I06-AJ07 and A-DS-07');
}

for (const marker of [
  "if (str_starts_with($taskType, 'addon.'))",
  '$instruction = $this->addonTasks->instruction($taskType);',
  "['role' => 'system', 'content' => $instruction->content()]",
  "['role' => 'user', 'content' => $input]",
]) {
  if (!platformAiPromptFactory.includes(marker)) {
    fail('platform AI prompt baseline changed; reconcile C28-I06-AJ08 and A-DS-10 marker ' + marker);
  }
}
for (const marker of [
  'private readonly ?AddonAiTaskInstructionV1 $instruction = null',
  'public function instruction(): ?AddonAiTaskInstructionV1',
]) {
  if (!platformAiDefinition.includes(marker)) fail('platform AI task definition changed: ' + marker);
}
for (const marker of [
  'C28-I06 AJ08 계약 검사 통과',
  'task instruction 격리·prompt order·catalog/routing fingerprint·probe·provider adapter·raw 비노출',
  'OpenAI', 'Ollama', 'Gemini',
]) {
  if (!platformAiInstructionFixture.includes(marker)) fail('platform C28-I06 AI instruction fixture changed: ' + marker);
}

for (const marker of [
  "new AddonApiProblemV1('addon_ai_task_unroutable', $message, $correlationId, false)",
]) {
  if (!platformAiRuntime.includes(marker)) {
    fail('platform real AI unroutable semantics changed; reconcile C28-I06-AJ06 marker ' + marker);
  }
}
for (const marker of [
  "'addon_ai_task_unroutable',",
  'true',
]) {
  if (!platformUnavailableAiRuntime.includes(marker)) {
    fail('platform unavailable AI semantics changed; reconcile C28-I06-AJ06 marker ' + marker);
  }
}
// R171: actor checks remain; the platform ledger also binds addon-owned request keys.
const actorAccessGuard = '$this->actorAiAccess?->assertAiAccess($request->actorUserId());';
const actorGuardFirst = aiExecuteSource.indexOf(actorAccessGuard);
const actorGuardLast = aiExecuteSource.lastIndexOf(actorAccessGuard);
if (
  actorGuardFirst < 0
  || actorGuardFirst >= aiExecuteSource.indexOf('$this->aiTaskRouter->snapshot(')
  || actorGuardLast <= aiExecuteSource.indexOf('$this->aiTaskRouter->execute(')
  || actorGuardLast >= aiExecuteSource.indexOf('return new AddonAiTaskResultV1(true')
  || (aiExecuteSource.match(/\$request->actorUserId\(\)/g) ?? []).length !== 3
  || aiExecuteSource.split(actorAccessGuard).length !== 3
) {
  fail('platform AI actor preflight/postflight changed; reconcile R171 and A-DS-07');
}
for (const marker of [
  'new AiTaskRequest($taskKey, $request->contractVersion(), $request->input())',
  '$this->requestLedger->execute($this->addonId,$request->actorUserId(),$request->idempotencyKey(),',
  "hash('sha256',json_encode([$taskKey,$request->contractVersion(),$request->input(),$assignment->profileFingerprint(),$assignment->routingRevision()],JSON_THROW_ON_ERROR))",
  "true,'addon_ai_request_pending'",
  "false,'addon_ai_outcome_uncertain'",
]) {
  if (!aiExecuteSource.includes(marker)) {
    fail('platform AI request isolation or durable replay contract changed; reconcile R171: ' + marker);
  }
}
for (const marker of [
  '$this->routing->findAtRevision(',
  'hash_equals($assignment->profileFingerprint(), $fingerprint)',
  "$current->fallbackPolicy() !== 'transient_only'",
  '$this->assignmentFingerprint($current, $primary, $fallback)',
]) {
  if (!platformAiRouter.includes(marker)) {
    fail('platform StoredAiTaskRouter baseline changed; reconcile A-DS-07 marker ' + marker);
  }
}
for (const marker of [
  'final class AddonAiTaskResultV1',
  "ValueValidator::jsonMap($this->output, 'output');",
  'public function output(): array',
  'public function routingSnapshot(): AddonAiTaskRoutingSnapshotV1',
  'public function correlationId(): string',
]) {
  if (!platformAiResult.includes(marker)) {
    fail('platform AI result baseline changed; reconcile A-DS-07 marker ' + marker);
  }
}
for (const usageField of ['tokenUsage', 'actualCost', 'public function usage(']) {
  if (platformAiResult.includes(usageField)) {
    fail('platform AI result now exposes usage telemetry; reconcile C28-I06-AJ03 and A-DS-07');
  }
}
for (const marker of [
  "// canRoute: addon's own declared task -> routable",
  "'submission_feedback'",
  "'addon.otherns.review'",
  "retryable() === true",
  'SECRET-INTERNAL-DETAIL',
]) {
  if (!platformContextFixture.includes(marker)) {
    fail('platform AI fixture baseline changed; reconcile A-DS-07 marker ' + marker);
  }
}
for (const marker of [
  'OJ_PLATFORM_VERSION',
  'OJ_PLATFORM_ADDON_API_VERSION',
]) {
  if (!platformEntry.includes(marker)) {
    fail('platform version baseline changed; rerun API-AUD-2026-08-14-R6 marker ' + marker);
  }
}
// Additive Addon API minors stay compatible with the declared >=1.1.0 <2.0.0 range.
function supportedAddonApiVersion(version) {
  const match = /^1\.(\d+)\.\d+$/.exec(String(version ?? ''));
  return match !== null && Number(match[1]) >= 1;
}
const platformAddonApiVersion = platformEntry.match(/OJ_PLATFORM_ADDON_API_VERSION',\s*'([^']+)'/)?.[1];
if (!supportedAddonApiVersion(platformAddonApiVersion)) {
  fail('platform version baseline changed; rerun API-AUD-2026-08-14-R6 Addon API ' + platformAddonApiVersion);
}
const expectedPlatformProductVersion = "'" + approvedPlatformCompatibility.platform_version + "'";
if (!platformEntry.includes(expectedPlatformProductVersion)) {
  reportPlatformDrift('platform version baseline changed; review ' + approvedPlatformCompatibility.review_id + ' marker ' + expectedPlatformProductVersion);
}
for (const marker of [
  '$this->timeoutSeconds < 10',
  '$this->timeoutSeconds > 120',
]) {
  if (!platformAiProfile.includes(marker)) {
    fail('platform AI timeout baseline changed; reconcile API-AUD-V05 marker ' + marker);
  }
}
if (!platformAiHttpResponse.includes('strlen($this->body) > 2097152')) {
  fail('platform AI response size baseline changed; reconcile API-AUD-V05');
}
for (const marker of [
  'final class AddonSystemStatusProvider implements SystemStatusProvider',
  "['accepted' => 0, 'incompatible' => 0, 'rejected' => 0]",
  'AddonRuntimeReadiness::READY',
  'AddonRuntimeReadiness::NOT_REQUIRED',
  'AddonRuntimeReadiness::PENDING',
  'AddonRuntimeReadiness::FAILED',
  "$details['애드온 ' . $addonId] = $this->entrySummary($entry, $runtimeState);",
]) {
  if (!platformAddonStatus.includes(marker)) fail('platform addon status provider baseline changed: ' + marker);
}
for (const marker of [
  'new AddonSystemStatusProvider($addonCatalog, $addonRuntimeReadiness)',
  '$operations->service(SystemStatusRegistry::class)->register(',
]) {
  if (!platformCompositionRoot.includes(marker)) fail('platform addon status provider wiring changed: ' + marker);
}

for (const marker of [
  "['GET']",
  'new AddonRouteHandler(',
]) {
  if (!platformRouteContributor.includes(marker)) {
    fail('platform route contributor baseline changed; reconcile C28-I06-RC01/RC05 marker ' + marker);
  }
}
if (platformRouteContributor.includes("['GET', 'HEAD']")) {
  fail('platform route contributor now registers HEAD; reconcile C28-I06-RC05 and A-DS-08');
}
for (const marker of [
  "$this->permissions->can($userId, 'oj_access_console')",
  '$this->permissions->can($userId, $capability)',
]) {
  if (!platformConsoleAccess.includes(marker)) fail('platform Console access changed: ' + marker);
}
for (const marker of [
  "$this->permissions->can($identity->userId(), 'oj_access_console')",
  '$this->permissions->can($identity->userId(), $this->capabilityKey)',
]) {
  if (!platformRouteHandler.includes(marker)) fail('platform Console route handler changed: ' + marker);
}
for (const marker of [
  "private const CAPABILITY_PREFIX = 'oj_addon_';",
  'public static function capabilityKey(string $addonCapabilityKey): string',
]) {
  if (!platformCapabilityKeys.includes(marker)) {
    fail('platform addon capability mapping baseline changed; reconcile C28-I06-RC03 marker ' + marker);
  }
}
const publicResolveStart = platformPublicMenu.indexOf('private function resolveEntry(');
const publicResolveEnd = platformPublicMenu.indexOf('private function route(', publicResolveStart);
const publicResolveSource = platformPublicMenu.slice(publicResolveStart, publicResolveEnd);
if (!publicResolveSource.includes('$this->features->isEnabled(') || !publicResolveSource.includes('$this->permissions->can(') || !publicResolveSource.includes('$this->addonMenus->capability(')) {
  fail('platform Public addon menu authorization changed; reconcile C28-I06-RC02 and A-DS-08');
}
for (const marker of ['rolesGrantedTo(', 'grantToRole(', 'revokeFromRole(']) {
  if (!platformCapabilityAssignment.includes(marker)) {
    fail('platform addon capability assignment baseline changed; reconcile C28-I06-RC04 marker ' + marker);
  }
}
for (const marker of ['grantToUser(', 'revokeFromUser(', 'usersGrantedTo(']) {
  if (platformCapabilityAssignment.includes(marker)) {
    fail('platform addon user capability assignment now exists; reconcile C28-I06-RC04 and A-DS-08 marker ' + marker);
  }
}


const expectedResponseKinds = [
  'short_text',
  'long_text',
  'single_choice',
  'multiple_choice',
  'checkbox',
  'number',
  'table',
  'image_upload',
  'file_upload',
  'drawing',
];
const currentResponseKinds = [...plateTypes.matchAll(/\|\s*'([^']+)'/g)].map((match) => match[1]);
if (JSON.stringify(currentResponseKinds) !== JSON.stringify(expectedResponseKinds)) {
  fail('Plate response kind baseline changed; reconcile A-DS09-B04 and the 10 response contracts');
}
if (!/"node_modules\/platejs": \{\s*"version": "53\.1\.2"/.test(platePackageLock)) {
  fail('Plate package baseline changed from platejs@53.1.2; reconcile A-DS09-B01');
}
for (const marker of [
  "node.type === 'hws_response'",
  'version: 1',
  'const nodeId = temporaryNodeId();',
  'const responseId = temporaryNodeId();',
  'globalThis.crypto.randomUUID()',
  "document_html: ''",
  'blocks: []',
]) {
  if (!plateSchema.includes(marker)) {
    fail('Plate browser schema baseline changed; reconcile A-DS09-B02/B03 marker ' + marker);
  }
}
for (const alias of ["'ojai_response'", "'worksheet_response'", "'response_field'", "'hws_response'"]) {
  if (!plateDocumentService.includes(alias)) {
    fail('Plate PHP legacy alias baseline changed; reconcile A-DS09-B03 marker ' + alias);
  }
}

const plateRuntimeS01 = documents.get(path.join(docsRoot, 'g2-imp-02-s01-plate-runtime-entrypoint.html')).content;
for (const marker of [
  'G2 · Plate Runtime · Implementation 02 · Step 01',
  'G2-IMP-02-S01 완료',
  'platejs@53.1.2',
  'worksheet_plate@1',
  'hws_response',
  'oj_ai_response',
  'PublicIdV1',
  'Addon_Page_Handler',
  'ASSET_WORKSHEET_EDITOR',
  'C02',
  'playwright',
  '플랫폼 파일 변경 0건',
  '공개 API 변경 없음',
]) {
  if (!plateRuntimeS01.includes(marker)) {
    fail('g2-imp-02-s01-plate-runtime-entrypoint.html: missing S01 marker ' + marker);
  }
}

const saveDraftC02 = documents.get(path.join(docsRoot, 'g2-imp-02-s02-c02-contract.html')).content;
for (const marker of [
  'G2 · Worksheet Save Draft · Implementation 02 · Step 02',
  'G2-IMP-02-S02 완료',
  '/worksheets/{worksheet_id}/commands/save-draft',
  'expected_revision_id',
  'teacher_document',
  'achievement_links',
  'staged_asset_receipt_ids',
  'worksheet.save-draft',
  'idempotent_replay',
  'unchanged=true',
  'temporary_id_map',
  'worksheet_revision_conflict',
  'oj_ai_state_conflict',
  'G2-IMP-02-S06',
  'table·DDL·Repository write 변경 0건',
  '플랫폼 소스 수정 없음',
  '공개 API 변경 없음',
]) {
  if (!saveDraftC02.includes(marker)) {
    fail('g2-imp-02-s02-c02-contract.html: missing S02 marker ' + marker);
  }
}

const documentProfileS03 = documents.get(path.join(docsRoot, 'g2-imp-02-s03-document-profile.html')).content;
for (const marker of [
  'G2 · Worksheet Document · Implementation 02 · Step 03',
  'G2-IMP-02-S03 완료',
  'worksheet_plate@1',
  '콘텐츠 16 family',
  '구조 6종',
  '응답 10종',
  'Mark 4종',
  'temporary_id_map',
  'worksheet_schema_unsupported',
  'worksheet_node_not_allowed',
  'worksheet_property_not_allowed',
  'worksheet_id_invalid',
  'worksheet_limit_exceeded',
  'npm run check:worksheet-s03',
  'G2-IMP-02-S04',
  'table·DDL·Repository write 변경 0건',
  '플랫폼 소스 수정 없음',
  '공개 API 변경 없음',
]) {
  if (!documentProfileS03.includes(marker)) {
    fail('g2-imp-02-s03-document-profile.html: missing S03 marker ' + marker);
  }
}

const revisionProjectionS04 = documents.get(path.join(docsRoot, 'g2-imp-02-s04-revision-projection.html')).content;
for (const marker of [
  'G2 · Worksheet Revision · Implementation 02 · Step 04',
  'G2-IMP-02-S04 완료',
  'Worksheet_Revision_Projector',
  '응답칸 10종',
  'oj_ai.worksheet_question',
  'oj_ai.worksheet_question_private_metadata',
  'primary | supporting',
  'standard_public_id',
  'asset binding',
  'oj_ai.worksheet_revision_projection@1.0.0',
  'npm run check:worksheet-s04',
  'G2-IMP-02-S05',
  'table·DDL·Repository write 변경 0건',
  'wp_oj_ai_*',
  '플랫폼 소스 수정 없음',
  '공개 API 변경 없음',
]) {
  if (!revisionProjectionS04.includes(marker)) {
    fail('g2-imp-02-s04-revision-projection.html: missing S04 marker ' + marker);
  }
}

const revisionTransactionS05 = documents.get(path.join(docsRoot, 'g2-imp-02-s05-revision-transaction.html')).content;
for (const marker of [
  'G2 · Worksheet Revision · Implementation 02 · Step 05',
  'G2-IMP-02-S05 완료',
  'Save_Worksheet_Draft',
  'Worksheet_Draft_Save_Record_Validator',
  'append_draft_revision',
  'START TRANSACTION',
  'content_hash = document_hash',
  'worksheet_revision_conflict',
  '동일 내용 200·도메인 0-write',
  'npm run check:worksheet-s05',
  'G2-IMP-02-S06',
  'wp_oj_ai_*',
  '플랫폼 소스 수정 없음',
  '공개 API 변경 없음',
]) {
  if (!revisionTransactionS05.includes(marker)) {
    fail('g2-imp-02-s05-revision-transaction.html: missing S05 marker ' + marker);
  }
}
const operationTransactionS06 = documents.get(path.join(docsRoot, 'g2-imp-02-s06-operation-transaction.html')).content;
for (const marker of [
  'G2 · Worksheet Save Operation · Implementation 02 · Step 06',
  'G2-IMP-02-S06 완료',
  'WordPress_Worksheet_Asset_Binder',
  'append_draft_revision',
  'oj_ai_private_files',
  'quarantined → ready',
  'worksheet_idempotency_conflict',
  'idempotent_replay=true',
  'operation receipt 1건',
  'npm run check:worksheet-s06',
  'G2-IMP-02-S07',
  'wp_oj_ai_*',
  '플랫폼 소스 수정 없음',
  '공개 API 변경 없음',
]) {
  if (!operationTransactionS06.includes(marker)) {
    fail('g2-imp-02-s06-operation-transaction.html: missing S06 marker ' + marker);
  }
}
if (operationTransactionS06.includes('$wpdb->prefix')) {
  fail('g2-imp-02-s06-operation-transaction.html: unescaped WordPress prefix expression found');
}

const restBoundaryS07 = documents.get(path.join(docsRoot, 'g2-imp-02-s07-rest-boundary.html')).content;
for (const marker of [
  'G2 · Worksheet Save Draft · Implementation 02 · Step 07',
  'G2-IMP-02-S07 완료',
  'POST /oj-ai/v1/worksheets/{worksheet_id}/commands/save-draft',
  'owner·capability·nonce·Idempotency-Key',
  '멱등 영수증',
  'npm run check:worksheet-s07',
  'G2-IMP-02-S08',
  '플랫폼 소스 수정 없음',
  '공개 API 변경 없음',
]) {
  if (!restBoundaryS07.includes(marker)) {
    fail('g2-imp-02-s07-rest-boundary.html: missing S07 marker ' + marker);
  }
}
if (restBoundaryS07.includes('$wpdb->prefix')) {
  fail('g2-imp-02-s07-rest-boundary.html: unescaped WordPress prefix expression found');
}

const workbenchS08 = documents.get(path.join(docsRoot, 'g2-imp-02-s08-workbench-plate.html')).content;
for (const marker of [
  'G2 · Worksheet Workbench · Implementation 02 · Step 08',
  'G2-IMP-02-S08 완료',
  'Q04',
  'Q05',
  'Q07',
  'C01',
  'C02',
  'Worksheet_Workbench_Page_Handler',
  'oj_ai.worksheet_document',
  'worksheet_plate@1',
  '응답칸 10종',
  'temporary_id_map',
  'npm run check:worksheet-s08',
  '플랫폼 소스 수정 없음',
  '공개 API 변경 없음',
]) {
  if (!workbenchS08.includes(marker)) fail('g2-imp-02-s08-workbench-plate.html: missing S08 marker ' + marker);
}

const saveRecoveryS09 = documents.get(path.join(docsRoot, 'g2-imp-02-s09-save-conflict-recovery.html')).content;
for (const marker of [
  'G2 · Worksheet Save UX · Implementation 02 · Step 09',
  'G2-IMP-02-S09 완료',
  '저장 중',
  '저장됨',
  'oj_ai_state_conflict',
  'local recovery',
  'Idempotency-Key',
  'false dirty',
  '700ms',
  '플랫폼 소스 수정 없음',
]) {
  if (!saveRecoveryS09.includes(marker)) fail('g2-imp-02-s09-save-conflict-recovery.html: missing S09 marker ' + marker);
}

const regressionS10 = documents.get(path.join(docsRoot, 'g2-imp-02-s10-regression.html')).content;
for (const marker of [
  'G2 · Regression Gate · Implementation 02 · Step 10',
  'G2-IMP-02-S10 완료',
  'npm test',
  'npm run check:worksheet-s08',
  'architecture 통과',
  'src/Platform/Worksheet_Workbench_Page_Handler.php',
  'oj_ai.worksheet_document',
  'G1·G2 전체 회귀 통과',
  'WordPress prefix 결합 없음',
  '승인 기준선으로 사용하지 않는다',
]) {
  if (!regressionS10.includes(marker)) fail('g2-imp-02-s10-regression.html: missing S10 marker ' + marker);
}

const visualS11 = documents.get(path.join(docsRoot, 'g2-imp-02-s11-visual-verification.html')).content;
for (const marker of [
  'G2 · Visual Acceptance · Implementation 02 · Step 11',
  'G2-IMP-02-S11 완료',
  'Desktop 1440×1000',
  'Mobile 390×844',
  'worksheet-workbench-desktop.png',
  'worksheet-workbench-mobile.png',
  'npm run e2e:worksheet',
  '700ms',
  'viewport horizontal overflow',
  'page·console error',
]) {
  if (!visualS11.includes(marker)) fail('g2-imp-02-s11-visual-verification.html: missing S11 marker ' + marker);
}

const freezeS12 = documents.get(path.join(docsRoot, 'g2-imp-02-s12-freeze.html')).content;
for (const marker of [
  'G2 · Worksheet Workbench · Implementation 02 · Step 12',
  'G2-IMP-02-S12 완료',
  'G2-IMP-02 12/12',
  '다음 G2-IMP-03-S01',
  'C64 private asset staging',
  '2.0.0-dev.58',
  'Addon API',
  '공개 symbol 37개',
  '새 API 불필요',
  'oj-platform',
  'write 0건',
  '공개 플랫폼 API 변경 없음',
]) {
  if (!freezeS12.includes(marker)) fail('g2-imp-02-s12-freeze.html: missing S12 marker ' + marker);
}

for (const content of [workbenchS08, saveRecoveryS09, regressionS10, visualS11, freezeS12]) {
  if (content.includes('$wpdb->prefix')) fail('G2-IMP-02 S08~S12 docs: unescaped WordPress prefix expression found');
}

const validationS01 = documents.get(path.join(docsRoot, 'g2-imp-04-s01-validation.html')).content;
for (const marker of [
  'G2 · Worksheet Validation · Implementation 04 · Step 01',
  'G2-IMP-04-S01 완료',
  'oj_ai.worksheet_validation_projection@1.0.0',
  '/worksheet-revisions/{revision_id}/commands/validate',
  'expected_state',
  'expected_revision_id',
  'payload',
  'mutation_count=0',
  'document_profile',
  'canonical_hash',
  'validation_receipt',
  'asset_readiness',
  'student_projection',
  'print_projection',
  'renderer_parity',
  'private_field_scan',
  'release_ready=false',
  '20 MiB',
  '200 MiB',
  'npm run check:worksheet-validation-s01',
  '다음 S02 Q08',
  '플랫폼 API 변경 없음',
]) {
  if (!validationS01.includes(marker)) fail('g2-imp-04-s01-validation.html: missing S01 marker ' + marker);
}
if (validationS01.includes('$wpdb->prefix')) fail('G2-IMP-04-S01 doc: unescaped WordPress prefix expression found');

const previewS02 = documents.get(path.join(docsRoot, 'g2-imp-04-s02-preview.html')).content;
for (const marker of [
  'G2 · Worksheet Validation · Implementation 04 · Step 02',
  'S02 완료',
  '/worksheet-revisions/{revision_id}/preview?projection=student',
  'projection=print',
  'student_blank|teacher_blank',
  'oj_ai.student_document@1.0.0',
  'oj_ai.student_response_schema@1.0.0',
  'oj_ai.worksheet_print@1.0.0',
  'grading',
  'private <code>asset_id</code>',
  'mutation 0',
  'npm run check:worksheet-preview-s02',
  '다음 S03 parity·scan',
  '플랫폼 API 변경 없음',
]) {
  if (!previewS02.includes(marker)) fail('g2-imp-04-s02-preview.html: missing S02 marker ' + marker);
}

const safetyS03 = documents.get(path.join(docsRoot, 'g2-imp-04-s03-projection-safety.html')).content;
for (const marker of [
  'G2 · Worksheet Validation · Implementation 04 · Step 03',
  'S03 완료',
  'Renderer parity',
  'semantic manifest',
  'forbidden_field_count = 0',
  'private_asset_url_count = 0',
  'invalid_asset_reference_count = 0',
  'student_blank',
  'teacher_blank',
  'release_ready=true',
  'release_ready=false',
  'npm run check:worksheet-projection-safety-s03',
  '다음 S04 UI·E2E',
  '플랫폼 API 변경 없음',
]) {
  if (!safetyS03.includes(marker)) fail('g2-imp-04-s03-projection-safety.html: missing S03 marker ' + marker);
}

const uiS04 = documents.get(path.join(docsRoot, 'g2-imp-04-s04-ui-e2e-freeze.html')).content;
for (const marker of [
  'G2 · Worksheet Validation · Implementation 04 · Step 04',
  'S04 완료',
  'IMP-04 4/4 완료',
  'C05 exact JSON',
  'projection=student',
  'projection=print&amp;profile=student_blank',
  'replaceChildren',
  '응답 10종',
  '최상위 layer',
  'worksheet-preview-student-desktop.png',
  'worksheet-preview-print-mobile.png',
  'npm run check:worksheet-validation-preview-ui-s04',
  'npm run e2e:worksheet',
  'npm test',
  '다음 G2-IMP-05-S01',
  '플랫폼 API 변경 없음',
]) {
  if (!uiS04.includes(marker)) fail('g2-imp-04-s04-ui-e2e-freeze.html: missing S04 marker ' + marker);
}

const qualityInstructionS01 = documents.get(path.join(docsRoot, 'g2-imp-05-s01-task-instruction.html')).content;
for (const marker of [
  'G2 · Worksheet Self-Evaluation · Implementation 05 · Step 01',
  'G2-IMP-05-S01 완료',
  'addon.oj_ai_dashboard.worksheet_quality_assessment',
  '활동지 자체 평가',
  'worksheet_quality.v1',
  'sourceKind=none',
  'worksheet_quality_prompt.v2',
  '2,607 bytes',
  '985 bytes',
  '20198a7d53f5503cf8b672919b96bf4771c247d7b5287ab166417d2fb8c31887',
  'learning_goal_alignment',
  '5499f44428a9e504e045fb6035c6d650bef8601a8421567095063a231e5db2cd',
  'instruction_clarity',
  'answerability',
  'difficulty_fit',
  'rubric_quality',
  '<code>safety</code>',
  'platform system',
  'untrusted user data',
  'npm run check:worksheet-quality-instruction-s01',
  'AJ08 제공자 계약 통과',
  'Task 2개 유지',
  '다음 G2-IMP-05-S02',
  '플랫폼 API 변경 없음',
]) {
  if (!qualityInstructionS01.includes(marker)) fail('g2-imp-05-s01-task-instruction.html: missing S01 marker ' + marker);
}

const evaluationInputS02 = documents.get(path.join(docsRoot, 'g2-imp-05-s02-p04-input.html')).content;
for (const marker of [
  'G2 · Worksheet Self-Evaluation · Implementation 05 · Step 02',
  'G2-IMP-05-S02 완료',
  'worksheet_quality.v1',
  'oj_ai.worksheet_quality_request@1.0.0',
  'quality_assessment',
  'oj_ai.worksheet_evaluation_input@1.0.0',
  'worksheet_quality_prompt.v2',
  '5499f44428a9e504e045fb6035c6d650bef8601a8421567095063a231e5db2cd',
  'oj_ai.worksheet_quality_output@1.0.0',
  'learning_goal_alignment',
  'instruction_clarity',
  'answerability',
  'difficulty_fit',
  'rubric_quality',
  '<code>safety</code>',
  '<code>link_required=true</code>',
  '<code>description=null</code>',
  '<code>921,600 bytes</code>',
  'worksheet_evaluation_input_invalid',
  'worksheet_evaluation_input_too_large',
  'npm run check:worksheet-evaluation-input-s02',
  'Gateway 0-call',
  '다음 G2-IMP-05-S03',
  '플랫폼 API 변경 없음',
]) {
  if (!evaluationInputS02.includes(marker)) fail('g2-imp-05-s02-p04-input.html: missing S02 marker ' + marker);
}

const evaluationEnqueueS03 = documents.get(path.join(docsRoot, 'g2-imp-05-s03-c10-enqueue.html')).content;
for (const marker of [
  'G2 · Worksheet Self-Evaluation · Implementation 05 · Step 03',
  'G2-IMP-05-S03 완료',
  'POST /oj-ai/v1/worksheet-revisions/{revision_id}/commands/evaluate',
  'assess_worksheets',
  'Rest_Permission_Handoff',
  'oj_ai_worksheet_evaluations',
  'oj_ai_worksheet_evaluation_inputs',
  'oj_ai_evaluation_runs',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'worksheet_evaluation.enqueue_replayed',
  'worksheet_evaluation_idempotency_conflict',
  'AddonAiTaskGatewayV1::canRoute()',
  'AddonAiTaskGatewayV1::snapshot()',
  'AddonAiTaskGatewayV1::execute()',
  'Gateway execute 0-call',
  'API-COMPAT-01',
  'CFG-BOOTSTRAP-01',
  'npm run check:worksheet-evaluation-enqueue-s03',
  '다음 G2-IMP-05-S04',
  '플랫폼 API 변경 없음',
]) {
  if (!evaluationEnqueueS03.includes(marker)) fail('g2-imp-05-s03-c10-enqueue.html: missing S03 marker ' + marker);
}

const evaluationWorkerS04 = documents.get(path.join(docsRoot, 'g2-imp-05-s04-worker-normalizer.html')).content;
for (const marker of [
  'G2 · Worksheet Self-Evaluation · Implementation 05 · Step 04',
  'G2-IMP-05-S04 완료',
  'lease 300초',
  '최대 3회',
  'oj_ai.external_ai_processing_consent@1.0.0',
  'external_processing_not_approved',
  'AddonAiTaskRequestV1',
  'AddonAiTaskGatewayV1::snapshot()',
  'AddonAiTaskGatewayV1::execute()',
  '524,288 bytes',
  'oj_ai_worksheet_evaluation_results',
  'oj_ai_worksheet_evaluation_items',
  'oj_ai_evaluation_attempts',
  'published_at_utc=NULL',
  'worksheet_quality_prompt.v2',
  '5499f44428a9e504e045fb6035c6d650bef8601a8421567095063a231e5db2cd',
  'CONSENT-BOOTSTRAP-01',
  'API-COMPAT-01',
  'npm run check:worksheet-evaluation-worker-s04',
  '다음 G2-IMP-05-S05',
  '플랫폼 API 변경 없음',
]) {
  if (!evaluationWorkerS04.includes(marker)) fail('g2-imp-05-s04-worker-normalizer.html: missing S04 marker ' + marker);
}

const evaluationQueryS05 = documents.get(path.join(docsRoot, 'g2-imp-05-s05-query-projections.html')).content;
for (const marker of [
  'G2 · Worksheet Self-Evaluation · Implementation 05 · Step 05',
  'G2-IMP-05-S05 완료',
  'GET /oj-ai/v1/worksheet-evaluations',
  'GET /oj-ai/v1/worksheet-evaluations/{evaluation_id}',
  'owner SQL filter',
  'Rest_Permission_Handoff',
  'Object_Scope::TYPE_EVALUATION',
  'signed cursor',
  'mutation 0',
  'raw AI output 0',
  'worksheet_evaluation_stored_data_invalid',
  'oj_ai_worksheet_evaluations',
  'oj_ai_worksheet_evaluation_results',
  'oj_ai_worksheet_evaluation_items',
  'oj_ai_worksheet_evaluation_reviews',
  'oj_ai_evaluation_runs',
  'Canonical JSON',
  'canonical byte',
  'npm run check:worksheet-evaluation-query-s05',
  '다음 G2-IMP-05-S06',
  '플랫폼 API 변경 없음',
]) {
  if (!evaluationQueryS05.includes(marker)) fail('g2-imp-05-s05-query-projections.html: missing S05 marker ' + marker);
}

const evaluationControlS06 = documents.get(path.join(docsRoot, 'g2-imp-05-s06-retry-cancel.html')).content;
for (const marker of [
  'G2 · Worksheet Self-Evaluation · Implementation 05 · Step 06',
  'G2-IMP-05-S06 완료',
  'POST /oj-ai/v1/worksheet-evaluations/{evaluation_id}/commands/retry',
  'POST /oj-ai/v1/worksheet-evaluations/{evaluation_id}/commands/cancel',
  'assess_worksheets',
  'expected_run_id',
  'failed|cancelled',
  'queued|retry_wait|leased|running',
  'frozen input',
  'lease_token',
  'late worker discard',
  'worksheet_evaluation.retry_queued',
  'worksheet_evaluation.cancelled',
  'oj_ai_worksheet_evaluations',
  'oj_ai_worksheet_evaluation_inputs',
  'oj_ai_evaluation_runs',
  'oj_ai_evaluation_attempts',
  'oj_ai_worksheet_evaluation_results',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'npm run check:worksheet-evaluation-control-s06',
  '다음 G2-IMP-05-S07',
  '플랫폼 API 변경 없음',
]) {
  if (!evaluationControlS06.includes(marker)) fail('g2-imp-05-s06-retry-cancel.html: missing S06 marker ' + marker);
}

const evaluationReviewS07 = documents.get(path.join(docsRoot, 'g2-imp-05-s07-review.html')).content;
for (const marker of [
  'G2 · Worksheet Self-Evaluation · Implementation 05 · Step 07',
  'G2-IMP-05-S07 완료',
  'POST /oj-ai/v1/worksheet-evaluations/{evaluation_id}/commands/review',
  'review_assessments',
  'expected_result_id',
  'expected_review_no',
  'resolved',
  'deferred',
  'dismissed',
  'append-only',
  'worksheet_evaluation_review_conflict',
  'worksheet_evaluation_review_incomplete',
  'oj_ai_worksheet_evaluation_reviews',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'npm run check:worksheet-evaluation-review-s07',
  '다음 S08 작업대 UI',
  '플랫폼 API 변경 없음',
]) {
  if (!evaluationReviewS07.includes(marker)) fail('g2-imp-05-s07-review.html: missing S07 marker ' + marker);
}

if (evaluationReviewS07.includes('$wpdb->prefix') || evaluationReviewS07.includes('wp_oj_ai_')) {
  fail('g2-imp-05-s07-review.html: forbidden table prefix found');
}

const evaluationWorkbenchS08 = documents.get(path.join(docsRoot, 'g2-imp-05-s08-evaluation-workbench.html')).content;
for (const marker of [
  'G2 · Worksheet Self-Evaluation · Implementation 05 · Step 08',
  'G2-IMP-05-S08 UI 완료',
  'Q09',
  'Q10',
  'C10~C12·C33',
  'Worksheet_Evaluation_Workbench_Page_Handler',
  'data-consent-recorded',
  'CONSENT-BOOTSTRAP-01',
  'tests/artifacts/worksheet-evaluation-workbench-desktop.png',
  'tests/artifacts/worksheet-evaluation-workbench-mobile.png',
  'npm run check:worksheet-evaluation-workbench-s08',
  'npm run e2e:evaluation',
  '다음 단계',
  'G2-IMP-05-S09',
  '플랫폼 API 변경 없음',
]) {
  if (!evaluationWorkbenchS08.includes(marker)) fail('g2-imp-05-s08-evaluation-workbench.html: missing S08 marker ' + marker);
}

if (evaluationWorkbenchS08.includes('$wpdb->prefix') || evaluationWorkbenchS08.includes('wp_oj_ai_')) {
  fail('g2-imp-05-s08-evaluation-workbench.html: forbidden table prefix found');
}

const evaluationFreezeS09 = documents.get(path.join(docsRoot, 'g2-imp-05-s09-integration-freeze.html')).content;
for (const marker of [
  'G2 · Worksheet Self-Evaluation · Implementation 05 · Step 09',
  'G2-IMP-05-S09 완료',
  'IMP-05 9/9',
  'STORED_DATA_INVALID',
  'worksheet_evaluation_stored_data_invalid',
  'check:worksheet-evaluation-integration-s09',
  'external_processing_not_approved',
  'routing_changed_during_execute',
  'tests/artifacts/worksheet-evaluation-workbench-desktop.png',
  'CONSENT-BOOTSTRAP-01',
  'G2-IMP-05-R01',
  '애드온 REST 카탈로그',
  '플랫폼 API 변경 없음',
]) {
  if (!evaluationFreezeS09.includes(marker)) fail('g2-imp-05-s09-integration-freeze.html: missing S09 marker ' + marker);
}

if (evaluationFreezeS09.includes('$wpdb->prefix') || evaluationFreezeS09.includes('wp_oj_ai_')) {
  fail('g2-imp-05-s09-integration-freeze.html: forbidden table prefix found');
}

const externalAiConsentR01 = documents.get(path.join(docsRoot, 'g2-imp-05-r01-external-ai-consent.html')).content;
for (const marker of [
  'G2 · Worksheet Self-Evaluation · Release Correction 01',
  'G2-IMP-05-R01 완료',
  'CONSENT-BOOTSTRAP-01 해소',
  'A-DS08-Q37',
  'A-DS08-C76',
  'A-DS08-C77',
  'oj_ai_outbox_events',
  'oj_ai_audit_events',
  'Q37은 안전 조회',
  'C76·C77만 <code>manage_policies</code>',
  'check:external-ai-consent-r01',
  '1280 desktop·390 mobile',
  '2.0.0-dev.61',
  '다음 G2-IMP-06-S01',
  '플랫폼 API 변경 없음',
]) {
  if (!externalAiConsentR01.includes(marker)) fail('g2-imp-05-r01-external-ai-consent.html: missing R01 marker ' + marker);
}

if (externalAiConsentR01.includes('$wpdb->prefix') || externalAiConsentR01.includes('wp_oj_ai_')) {
  fail('g2-imp-05-r01-external-ai-consent.html: forbidden table prefix found');
}

const approvalPolicyS01 = documents.get(path.join(docsRoot, 'g2-imp-06-s01-approval-policy.html')).content;
for (const marker of [
  'G2 · Worksheet Approval and Publication · Implementation 06 · Step 01',
  'G2-IMP-06-S01 완료',
  'Worksheet_Approval_Policy',
  'Worksheet_Approval_Policy_Gate',
  'Worksheet_Approval_Decision',
  'oj_ai.worksheet_approval_policy@1.0.0',
  'oj_ai.worksheet_approval_decision@1.0.0',
  'worksheet_evaluation_safety_review_required',
  'worksheet_evaluation_reassessment_required',
  'worksheet_evaluation_dismissal_not_allowed',
  'check:worksheet-approval-policy-s01',
  'REST·DB 변경 0건',
  'Page 9·GET 37·POST 77 유지',
  'API-AUD-2026-08-21-R25-OBSERVED',
  '다음 G2-IMP-06-S02',
  '플랫폼 API 변경 없음',
]) {
  if (!approvalPolicyS01.includes(marker)) fail('g2-imp-06-s01-approval-policy.html: missing S01 marker ' + marker);
}

if (approvalPolicyS01.includes('$wpdb->prefix') || approvalPolicyS01.includes('wp_oj_ai_')) {
  fail('g2-imp-06-s01-approval-policy.html: forbidden table prefix found');
}

const approvalCommandS02 = documents.get(path.join(docsRoot, 'g2-imp-06-s02-approval-command.html')).content;
for (const marker of [
  'G2 · Worksheet Approval and Publication · Implementation 06 · Step 02',
  'G2-IMP-06-S02 완료',
  'C06 활성',
  'expected_validation_projection_hash',
  'expected_approval_decision_hash',
  'Idempotency-Key',
  'worksheet_approval_conflict',
  'check:worksheet-approval-command-s02',
  '새 table·migration 0건',
  'Page 9·GET 37·POST 77 유지',
  'API-AUD-2026-08-21-R26-OBSERVED',
  '다음 G2-IMP-06-S03',
  '플랫폼 API 변경 없음',
]) {
  if (!approvalCommandS02.includes(marker)) fail('g2-imp-06-s02-approval-command.html: missing S02 marker ' + marker);
}

if (approvalCommandS02.includes('$wpdb->prefix') || approvalCommandS02.includes('wp_oj_ai_')) {
  fail('g2-imp-06-s02-approval-command.html: forbidden table prefix found');
}

const publicationS03 = documents.get(path.join(docsRoot, 'g2-imp-06-s03-publication.html')).content;
for (const marker of [
  'G2 · Worksheet Approval and Publication · Implementation 06 · Step 03',
  'G2-IMP-06-S03 완료',
  'C07 활성',
  'expected_approval_decision_hash',
  'oj_ai.student_document@1.0.0',
  'oj_ai.student_response_schema@1.0.0',
  'oj_ai.grading_schema@1.1.0',
  'oj_ai.worksheet_print@1.0.0',
  'worksheet_publication_conflict',
  'check:worksheet-publication-s03',
  '새 table·migration 0건',
  'Page 9·GET 37·POST 77',
  'API-AUD-2026-08-21-R27-OBSERVED',
  '2.0.0-dev.62',
  'CFG-BOOTSTRAP-02',
  '다음 G2-IMP-06-S04',
  '플랫폼 API 변경 없음',
]) {
  if (!publicationS03.includes(marker)) fail('g2-imp-06-s03-publication.html: missing S03 marker ' + marker);
}

if (publicationS03.includes('$wpdb->prefix') || publicationS03.includes('wp_oj_ai_')) {
  fail('g2-imp-06-s03-publication.html: forbidden table prefix found');
}

const lifecycleS04 = documents.get(path.join(docsRoot, 'g2-imp-06-s04-lifecycle.html')).content;
for (const marker of [
  'G2 · Worksheet Approval and Publication · Implementation 06 · Step 04',
  'G2-IMP-06-S04 완료',
  'C08·C09 활성',
  'expected_lock_version',
  'worksheet_lifecycle_conflict',
  'worksheet.archived',
  'worksheet.restored',
  'check:worksheet-lifecycle-s04',
  'API-AUD-2026-08-21-R28-OBSERVED',
  '2.0.0-dev.62',
  '다음 G2-IMP-06-S05',
  '플랫폼 API 변경 없음',
]) {
  if (!lifecycleS04.includes(marker)) fail('g2-imp-06-s04-lifecycle.html: missing S04 marker ' + marker);
}

if (lifecycleS04.includes('$wpdb->prefix') || lifecycleS04.includes('wp_oj_ai_')) {
  fail('g2-imp-06-s04-lifecycle.html: forbidden table prefix found');
}

const approvalPublicationUiS05 = documents.get(path.join(docsRoot, 'g2-imp-06-s05-ui-freeze.html')).content;
for (const marker of [
  'G2 · Worksheet Approval and Publication · Implementation 06 · Step 05',
  'G2-IMP-06-S05 완료',
  'IMP-06 5/5 완료',
  'C05 승인 준비 정보',
  'approval_ready=false',
  'C06·C07 exact evidence',
  '검증 → 승인 → 발행 → 보관 → 복원',
  'check:worksheet-approval-publication-ui-s05',
  'npm run e2e:worksheet',
  'API-AUD-2026-08-21-R29-OBSERVED',
  'CFG-BOOTSTRAP-02',
  '플랫폼 API 변경 없음',
]) {
  if (!approvalPublicationUiS05.includes(marker)) fail('g2-imp-06-s05-ui-freeze.html: missing S05 marker ' + marker);
}

if (approvalPublicationUiS05.includes('$wpdb->prefix') || approvalPublicationUiS05.includes('wp_oj_ai_')) {
  fail('g2-imp-06-s05-ui-freeze.html: forbidden table prefix found');
}

const worksheetPrintProjectionS01 = documents.get(path.join(docsRoot, 'g2-imp-07-s01-print-projection.html')).content;
for (const marker of [
  'G2 · Worksheet · Implementation 07 · Step 01',
  'G2-IMP-07-S01 완료',
  'worksheet_print.v1',
  'oj_ai.worksheet_print@1.0.0',
  'worksheet_print_source_conflict',
  'canonical 규칙 밖의 숫자·값',
  'Worksheet_Print_Source_Query_Port_Interface',
  'oj_ai_worksheet_publications',
  'check:worksheet-pdf-package-g2',
  'API-AUD-2026-08-22-R38-OBSERVED',
  '다음 G2-IMP-07-S02',
  '플랫폼 API 변경 없음',
]) {
  if (!worksheetPrintProjectionS01.includes(marker)) {
    fail('g2-imp-07-s01-print-projection.html: missing S01 marker ' + marker);
  }
}

if (worksheetPrintProjectionS01.includes('$wpdb->prefix') || worksheetPrintProjectionS01.includes('wp_oj_ai_')) {
  fail('g2-imp-07-s01-print-projection.html: forbidden table prefix found');
}

const worksheetPdfJobArtifactS02 = documents.get(path.join(docsRoot, 'g2-imp-07-s02-pdf-job-artifact.html')).content;
for (const marker of [
  'G2 · Worksheet · Implementation 07 · Step 02',
  'G2-IMP-07-S02 완료',
  'POST /oj-ai/v1/worksheet-revisions/{revision_id}/commands/export-pdf',
  'GET /oj-ai/v1/export-jobs/{job_id}',
  'GET /oj-ai/v1/artifacts/{artifact_id}/download',
  'oj_ai.worksheet_pdf_request@1.0.0',
  '4 MiB',
  'Dompdf_Html_To_Pdf_Renderer',
  'dompdf/dompdf v3.1.6',
  '194018e6…61e252',
  '500 page',
  '100 MiB',
  'active_identity_key VARCHAR(191) NULL',
  'A-DS05-AM09-04',
  '202608220001_export_job_context_identity',
  'check:database-infrastructure',
  'API-AUD-2026-08-22-R39-OBSERVED',
  '후속 G2-IMP-07-S03 완료',
  '플랫폼 API 변경 없음',
]) {
  if (!worksheetPdfJobArtifactS02.includes(marker)) {
    fail('g2-imp-07-s02-pdf-job-artifact.html: missing S02 marker ' + marker);
  }
}

if (worksheetPdfJobArtifactS02.includes('$wpdb->prefix') || worksheetPdfJobArtifactS02.includes('wp_oj_ai_')) {
  fail('g2-imp-07-s02-pdf-job-artifact.html: forbidden table prefix found');
}

const worksheetPackageS03 = documents.get(path.join(docsRoot, 'g2-imp-07-s03-worksheet-package.html')).content;
for (const marker of [
  'G2 · Worksheet · Implementation 07 · Step 03',
  'G2-IMP-07-S03 완료',
  'C66',
  'POST /oj-ai/v1/worksheet-revisions/{revision_id}/commands/export-package',
  'oj_ai.worksheet_package_request@1',
  'application/vnd.oj.ai-worksheet+zip',
  'manifest.json',
  'worksheet.json',
  'assets/{sha256}.{extension}',
  '결정적 생성',
  'Zip_Worksheet_Template_Package_Reader',
  '교사용 비공개 패키지',
  'oj_ai_export_jobs',
  'oj_ai_private_files',
  'Export_Rest_Controller',
  'npm run check:worksheet-package-s03',
  'npm run check:worksheet-pdf-s02',
  'npm run check:worksheet-pdf-package-g2',
  'API-AUD-2026-08-22-R40-OBSERVED',
  '후속 G2-IMP-07-S04 완료',
  '플랫폼 API 변경 없음',
]) {
  if (!worksheetPackageS03.includes(marker)) {
    fail('g2-imp-07-s03-worksheet-package.html: missing S03 marker ' + marker);
  }
}

if (worksheetPackageS03.includes('$wpdb->prefix') || worksheetPackageS03.includes('wp_oj_ai_')) {
  fail('g2-imp-07-s03-worksheet-package.html: forbidden table prefix found');
}

const worksheetPdfPackageFreezeS04 = documents.get(path.join(docsRoot, 'g2-imp-07-s04-integration-freeze.html')).content;
for (const marker of [
  'G2 · Worksheet · Implementation 07 · Step 04',
  'G2-IMP-07-S04 완료',
  'G2 7/7 완료',
  'PDF',
  '활동지 파일',
  'Q23·Q24',
  'application/pdf',
  'Noto Sans KR Regular',
  'NotoSansKR',
  '/ToUnicode',
  '내용 해시 기반 cache staging',
  'A4 3페이지',
  'oj_ai_export_jobs',
  'oj_ai_pdf_artifacts',
  'oj_ai_private_files',
  'npm run check:worksheet-pdf-package-s04',
  'npm run e2e:worksheet',
  'npm run check:worksheet-pdf-package-g2',
  'API-AUD-2026-08-22-R41-OBSERVED',
  '다음 G3-IMP-08-S01',
  '플랫폼 API 변경 없음',
]) {
  if (!worksheetPdfPackageFreezeS04.includes(marker)) {
    fail('g2-imp-07-s04-integration-freeze.html: missing S04 marker ' + marker);
  }
}

if (worksheetPdfPackageFreezeS04.includes('$wpdb->prefix') || worksheetPdfPackageFreezeS04.includes('wp_oj_ai_')) {
  fail('g2-imp-07-s04-integration-freeze.html: forbidden table prefix found');
}

const assessmentConfiguration = documents.get(path.join(docsRoot, 'g5-imp-04-assessment-configuration.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 04 · CFG-BOOTSTRAP-02',
  'G5-IMP-04-S01~S05 완료',
  'CFG-BOOTSTRAP-02 해소',
  'oj_ai_evaluation_policy_revisions',
  'oj_ai_rubric_revisions',
  'oj_ai_review_rule_revisions',
  'Q25~Q27과 C55~C60',
  'Page 9·GET 37·POST 77',
  '사람 검토·명시 공개',
  'active|retired',
  'check:assessment-configuration-bootstrap-s01',
  'API-AUD-2026-08-21-R30-OBSERVED',
  '다음 G3-IMP-01',
  '플랫폼 API 변경 없음',
]) {
  if (!assessmentConfiguration.includes(marker)) {
    fail('g5-imp-04-assessment-configuration.html: missing implementation marker ' + marker);
  }
}

const assignmentCreate = documents.get(path.join(docsRoot, 'g3-imp-01-assignment-create.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 01',
  'G3-IMP-01-S01~S05 완료',
  'Q11·Q12·C13',
  'AddonAssignmentRosterGatewayV1',
  '50 page·5,000명',
  'oj_ai_assignments',
  'oj_ai_assignment_targets',
  'latest_publication_hash',
  'check:assignment-g3',
  'API-AUD-2026-08-21-R31-OBSERVED',
  '다음 G3-IMP-02',
  '플랫폼 API 변경 없음',
]) {
  if (!assignmentCreate.includes(marker)) {
    fail('g3-imp-01-assignment-create.html: missing implementation marker ' + marker);
  }
}

const assignmentLifecycle = documents.get(path.join(docsRoot, 'g3-imp-02-assignment-lifecycle.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 02',
  'G3-IMP-02-S01~S04 완료',
  'C14~C18',
  'expected_lock_version',
  'expected_schedule_version',
  'expected_schedule_hash',
  'assignment.auto_submit_due',
  'oj_ai_assignments',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'check:assignment-g3-lifecycle',
  'API-AUD-2026-08-21-R32-OBSERVED',
  '2.0.0-dev.64',
  '다음 G3-IMP-03',
  '플랫폼 API 변경 없음',
]) {
  if (!assignmentLifecycle.includes(marker)) {
    fail('g3-imp-02-assignment-lifecycle.html: missing implementation marker ' + marker);
  }
}
const studentAssignment = documents.get(path.join(docsRoot, 'g3-imp-03-student-assignment.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 03',
  'G3-IMP-03-S01~S04 완료',
  'Q30·Q31 활성',
  'C22 활성',
  'AddonLearningContextGatewayV1',
  'expected_lock_version',
  'expected_schedule_version',
  'expected_schedule_hash',
  'expected_publication_hash',
  'oj_ai_assignments',
  'oj_ai_assignment_targets',
  'oj_ai_submissions',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'check:assignment-g3-student',
  'API-AUD-2026-08-21-R33-OBSERVED',
  '2.0.0-dev.66',
  '다음 G3-IMP-04',
  '플랫폼 API 변경 없음',
]) {
  if (!studentAssignment.includes(marker)) {
    fail('g3-imp-03-student-assignment.html: missing implementation marker ' + marker);
  }
}

const responseRenderer = documents.get(path.join(docsRoot, 'g3-imp-04-s01-response-renderer.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 04 · Step 01',
  'G3-IMP-04-S01 완료',
  'P02.payload.items',
  'short_text',
  'long_text',
  'single_choice',
  'multiple_choice',
  'checkbox',
  'number',
  'table',
  'image_upload',
  'file_upload',
  'drawing',
  'resources/js/student-assignments.js',
  'scripts/e2e-student-assignments.mjs',
  'G3-IMP-04-S02',
  'Q32',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!responseRenderer.includes(marker)) {
    fail('g3-imp-04-s01-response-renderer.html: missing implementation marker ' + marker);
  }
}

const ownSubmissionQuery = documents.get(path.join(docsRoot, 'g3-imp-04-s02-own-submission-query.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 04 · Step 02',
  'G3-IMP-04-S02 완료',
  'GET /oj-ai/v1/me/submissions/{submission_id}',
  'student_submission.view',
  'oj_ai_participate',
  'submission:{submission_id}:self_view',
  'CG02',
  'current_revision',
  'official_revision',
  'save_token',
  'file_public_id',
  'idempotency key',
  'Submission_Revision_Integrity',
  'oj_ai.submission_response',
  'tests/Contract/student-submission-q32-g3-imp-04-s02.php',
  'npm run check:assignment-g3-student',
  'scripts/check-addon-api-consumer.ps1',
  '$wpdb-&gt;prefix',
  'G3-IMP-04-S03',
  'C23',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!ownSubmissionQuery.includes(marker)) {
    fail('g3-imp-04-s02-own-submission-query.html: missing implementation marker ' + marker);
  }
}

const autosaveRevision = documents.get(path.join(docsRoot, 'g3-imp-04-s03-autosave-revision.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 04 · Step 03',
  'G3-IMP-04-S03 완료',
  'POST /oj-ai/v1/me/submissions/{submission_id}/commands/autosave',
  'expected_save_token',
  'Submission_Save_Token',
  'submission_revision_conflict',
  'submission_validation_failed',
  'attachment_not_ready',
  'submission_already_official',
  'oj_ai_submission_revisions',
  'oj_ai_submission_attachments',
  'tests/Contract/student-submission-autosave-g3-imp-04-s03.php',
  'scripts/check-student-assignment-g3-imp-03.ps1',
  '$wpdb-&gt;prefix',
  'G3-IMP-04-S04',
  'G3-IMP-04-S05',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!autosaveRevision.includes(marker)) {
    fail('g3-imp-04-s03-autosave-revision.html: missing implementation marker ' + marker);
  }
}

const offlineConflictRecovery = documents.get(path.join(docsRoot, 'g3-imp-04-s04-offline-conflict-recovery.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 04 · Step 04',
  'G3-IMP-04-S04 완료',
  'GET /oj-ai/v1/me/submissions/{submission_id}',
  'POST /oj-ai/v1/me/submissions/{submission_id}/commands/autosave',
  'oj_ai_submission_recovery_v1:',
  '12시간',
  'pending_operation',
  'expected_publication_hash',
  'Idempotency-Key',
  '다른 저장 내용이 있습니다',
  '내 내용 모두',
  '저장된 내용 모두',
  '선택한 내용 저장',
  'scripts/e2e-student-assignments.mjs',
  'G3-IMP-04 4/5',
  '다음 G3-IMP-04-S05',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!offlineConflictRecovery.includes(marker)) {
    fail('g3-imp-04-s04-offline-conflict-recovery.html: missing implementation marker ' + marker);
  }
}

const responsiveAccessibilityFreeze = documents.get(path.join(docsRoot, 'g3-imp-04-s05-responsive-accessibility-freeze.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 04 · Step 05',
  'G3-IMP-04-S05 완료',
  'IMP-04 5/5 완료',
  '활동지 상태',
  'ArrowLeft',
  'ArrowRight',
  'Home',
  'End',
  'aria-live="polite"',
  'aria-atomic="true"',
  '40px',
  '1280×900',
  '390×844',
  'scripts/e2e-student-assignments.mjs',
  '다음 G3-IMP-05-S01',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!responsiveAccessibilityFreeze.includes(marker)) {
    fail('g3-imp-04-s05-responsive-accessibility-freeze.html: missing implementation marker ' + marker);
  }
}

const privateAttachmentStaging = documents.get(path.join(docsRoot, 'g3-imp-05-s01-private-attachment-staging.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 05 · Step 01',
  'G3-IMP-05-S01 완료',
  'IMP-05 1/4',
  'POST /oj-ai/v1/me/submissions/{submission_id}/attachments',
  'multipart/form-data',
  'Idempotency-Key',
  '202',
  'oj_ai_private_files',
  'oj_ai_submission_attachments',
  'scan_state=pending',
  'state=scanning',
  '25 MiB',
  '100 MiB',
  'tests/Contract/student-submission-attachment-stage-g3-imp-05-s01.php',
  'scripts/check-student-assignment-g3-imp-03.ps1',
  '$wpdb-&gt;prefix',
  '다음 G3-IMP-05-S02',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!privateAttachmentStaging.includes(marker)) {
    fail('g3-imp-05-s01-private-attachment-staging.html: missing implementation marker ' + marker);
  }
}

const stagedAttachmentRemoval = documents.get(path.join(docsRoot, 'g3-imp-05-s02-staged-attachment-removal.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 05 · Step 02',
  'G3-IMP-05-S02 완료',
  'IMP-05 2/4',
  'POST /oj-ai/v1/me/submissions/{submission_id}/attachments/{attachment_id}/commands/remove',
  'Idempotency-Key',
  'oj_ai_submission_attachments',
  'oj_ai_private_files',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'submission_revision_id',
  'bound_at_utc',
  'legal_hold',
  'START TRANSACTION',
  'tests/Contract/student-submission-attachment-remove-g3-imp-05-s02.php',
  'scripts/check-student-assignment-g3-imp-03.ps1',
  '$wpdb-&gt;prefix',
  '다음 G3-IMP-05-S03',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!stagedAttachmentRemoval.includes(marker)) {
    fail('g3-imp-05-s02-staged-attachment-removal.html: missing implementation marker ' + marker);
  }
}

const attachmentScanBoundary = documents.get(path.join(docsRoot, 'g3-imp-05-s03-attachment-scan-boundary.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 05 · Step 03',
  'G3-IMP-05-S03 완료',
  'IMP-05 3/4',
  'scanning + quarantined + pending',
  'lock_version',
  '300-second reclaim lease',
  'ClamAV',
  'tcp://127.0.0.1:3310',
  'OJ_AI_CLAMD_SOCKET',
  'oj_ai_submission_attachments',
  'oj_ai_private_files',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'START TRANSACTION',
  'tests/Contract/student-submission-attachment-scan-g3-imp-05-s03.php',
  'scripts/check-student-assignment-g3-imp-03.ps1',
  '$wpdb-&gt;prefix',
  '다음 G3-IMP-05-S04',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!attachmentScanBoundary.includes(marker)) {
    fail('g3-imp-05-s03-attachment-scan-boundary.html: missing implementation marker ' + marker);
  }
}

const attachmentCleanupRecovery = documents.get(path.join(docsRoot, 'g3-imp-05-s04-expiry-cleanup-recovery.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 05 · Step 04',
  'G3-IMP-05-S04 완료',
  'IMP-05 4/4',
  '24시간',
  'expires_at_utc',
  '300초',
  'legal_hold_until_utc IS NULL',
  'oj_ai_submission_attachments',
  'oj_ai_private_files',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'submission.attachment.expired',
  'submission.attachment.purged',
  'expired',
  'purged',
  'orphan',
  'tests/Contract/student-submission-attachment-cleanup-g3-imp-05-s04.php',
  'scripts/check-student-assignment-g3-imp-03.ps1',
  '$wpdb-&gt;prefix',
  '다음 G3-IMP-06-S01',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!attachmentCleanupRecovery.includes(marker)) {
    fail('g3-imp-05-s04-expiry-cleanup-recovery.html: missing implementation marker ' + marker);
  }
}

const officialSubmitReceipt = documents.get(path.join(docsRoot, 'g3-imp-06-s01-official-submit-receipt.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 06 · Step 01',
  'G3-IMP-06-S01 완료',
  'IMP-06 1/5',
  'G3 5/8 진행',
  'POST /oj-ai/v1/me/submissions/{submission_id}/commands/submit',
  'student_submission.submit',
  'expected_current_revision_id',
  'expected_save_token',
  'oj_ai_submission_receipts',
  'oj_ai_submissions',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'submission.submitted',
  'submission_revision_hash',
  'attachment_set_hash',
  'resubmission_policy_hash',
  'durable replay',
  'tests/Contract/student-submission-submit-g3-imp-06-s01.php',
  'scripts/check-student-assignment-g3-imp-03.ps1',
  '$wpdb-&gt;prefix',
  '다음 G3-IMP-06-S02',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!officialSubmitReceipt.includes(marker)) {
    fail('g3-imp-06-s01-official-submit-receipt.html: missing implementation marker ' + marker);
  }
}

const teacherSubmissionProjections = documents.get(path.join(docsRoot, 'g3-imp-06-s02-teacher-submission-projections.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 06 · Step 02',
  'G3-IMP-06-S02 완료',
  'IMP-06 2/5',
  'G3 5/8 진행',
  'GET /oj-ai/v1/assignments/{assignment_id}/submissions',
  'GET /oj-ai/v1/submissions/{submission_id}',
  'GET /oj-ai/v1/submissions/{submission_id}/revisions',
  'not_started',
  'not_evaluated|reviewed',
  'not_released|released',
  'view_submissions',
  'teacher_view',
  'teacher_history',
  'manage_submissions',
  'oj_ai_assignment_targets',
  'oj_ai_submission_revisions',
  'oj_ai_submission_receipts',
  'file_public_id',
  'idempotency_key',
  'tests/Contract/teacher-submission-query-g3-imp-06-s02.php',
  'scripts/check-student-assignment-g3-imp-03.ps1',
  'WordPress_Teacher_Submission_Query',
  'Teacher_Submission_Rest_Controller',
  '$wpdb-&gt;prefix',
  '다음 G3-IMP-06-S03',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!teacherSubmissionProjections.includes(marker)) {
    fail('g3-imp-06-s02-teacher-submission-projections.html: missing implementation marker ' + marker);
  }
}

const teacherSubmissionControl = documents.get(path.join(docsRoot, 'g3-imp-06-s03-teacher-reopen-lock.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 06 · Step 03',
  'G3-IMP-06-S03 완료',
  'IMP-06 3/5',
  'G3 5/8 진행',
  'POST /oj-ai/v1/submissions/{submission_id}/commands/reopen',
  'POST /oj-ai/v1/submissions/{submission_id}/commands/lock',
  'expected_official_revision_id',
  'expected_official_attempt_no',
  'reopened_fork',
  'copy_previous_answers',
  'max_attempts',
  'reopen_until_utc',
  'submission.reopened',
  'submission.locked',
  'oj_ai_submission_revisions',
  'oj_ai_submission_receipts',
  'tests/Contract/teacher-submission-control-g3-imp-06-s03.php',
  'scripts/check-student-assignment-g3-imp-03.ps1',
  'WordPress_Teacher_Submission_Control_Repository',
  'Teacher_Submission_Rest_Controller',
  '$wpdb-&gt;prefix',
  '다음 G3-IMP-06-S04',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!teacherSubmissionControl.includes(marker)) {
    fail('g3-imp-06-s03-teacher-reopen-lock.html: missing implementation marker ' + marker);
  }
}

const submissionScreenAudit = documents.get(path.join(docsRoot, 'g3-imp-06-s04-submission-screen-audit.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 06 · Step 04',
  'G3-IMP-06-S04 완료',
  'IMP-06 4/5',
  'G3 5/8 진행',
  'control_receipt',
  'remaining_attempts',
  'Q13',
  'Q14',
  'Q15',
  'POST /oj-ai/v1/submissions/{submission_id}/commands/reopen',
  'POST /oj-ai/v1/submissions/{submission_id}/commands/lock',
  'data-can-manage-submissions',
  'tests/Contract/submission-screen-audit-g3-imp-06-s04.php',
  'scripts/e2e-student-assignments.mjs',
  'scripts/e2e-assignment-lifecycle.mjs',
  '$wpdb-&gt;prefix',
  '다음 G3-IMP-06-S05',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionScreenAudit.includes(marker)) {
    fail('g3-imp-06-s04-submission-screen-audit.html: missing implementation marker ' + marker);
  }
}

const submissionConcurrencyFreeze = documents.get(path.join(docsRoot, 'g3-imp-06-s05-concurrency-freeze.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 06 · Step 05',
  'G3-IMP-06-S05 완료',
  'IMP-06 5/5 완료',
  'G3 6/8 진행',
  'C23·C26·C27·C28 동결',
  'submission_revision_conflict',
  'expected_schedule_version',
  'expected_publication_hash',
  'Autosave_Own_Submission',
  'find_autosave_receipt',
  'tests/Contract/submission-concurrency-regression-g3-imp-06-s05.php',
  'scripts/check-student-assignment-g3-imp-03.ps1',
  '현재 OJ Platform dev.67·Addon API 1.1.0',
  '$wpdb-&gt;prefix',
  '다음 G3-IMP-07-S01',
  'oj-platform</code> write는 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionConcurrencyFreeze.includes(marker)) {
    fail('g3-imp-06-s05-concurrency-freeze.html: missing implementation marker ' + marker);
  }
}

const submissionZipStage = documents.get(path.join(docsRoot, 'g3-imp-07-s01-private-zip-staging.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 07 · Step 01',
  'G3-IMP-07-S01 완료',
  'IMP-07 1/5',
  'C19 활성',
  'private-only',
  '100 MiB',
  '500 MiB',
  '20:1',
  'submission_zip_source',
  'oj_ai_zip_uploads',
  'tests/Contract/submission-zip-stage-g3-imp-07-s01.php',
  'scripts/check-submission-zip-g3-imp-07.ps1',
  '$wpdb-&gt;prefix',
  'oj-platform</code> write는 0건',
  '다음 G3-IMP-07-S02',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionZipStage.includes(marker)) {
    fail('g3-imp-07-s01-private-zip-staging.html: missing implementation marker ' + marker);
  }
}

const submissionZipScan = documents.get(path.join(docsRoot, 'g3-imp-07-s02-async-scan-preview.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 07 · Step 02',
  'G3-IMP-07-S02 완료',
  'IMP-07 2/5',
  'Q35 활성',
  '제안만 생성',
  '5분 임대',
  'GET /oj-ai/v1/zip-uploads/{upload_id}',
  'manage_submissions',
  'view_submissions',
  'submission_zip_item',
  'oj_ai_zip_upload_items',
  'expected_mapping_version',
  'tests/Contract/submission-zip-scan-preview-g3-imp-07-s02.php',
  'scripts/check-submission-zip-g3-imp-07.ps1',
  'API-AUD-2026-08-22-R34-OBSERVED',
  '2.0.0-dev.67',
  '$wpdb-&gt;prefix',
  'oj-platform</code> write는 0건',
  '다음 G3-IMP-07-S03',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionZipScan.includes(marker)) {
    fail('g3-imp-07-s02-async-scan-preview.html: missing implementation marker ' + marker);
  }
}

const submissionZipMapping = documents.get(path.join(docsRoot, 'g3-imp-07-s03-explicit-mapping.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 07 · Step 03',
  'G3-IMP-07-S03 완료',
  'IMP-07 3/5',
  'C68 활성',
  'POST /oj-ai/v1/zip-uploads/{upload_id}/commands/map',
  'command_id',
  'expected_mapping_version',
  'target_id',
  'learner_user_id',
  'response_id',
  'zip_mapping_invalid',
  'oj_ai_zip_uploads',
  'oj_ai_zip_upload_items',
  'oj_ai_assignment_targets',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'tests/Contract/submission-zip-mapping-g3-imp-07-s03.php',
  'scripts/check-submission-zip-g3-imp-07.ps1',
  'API-AUD-2026-08-22-R35-OBSERVED',
  '$wpdb-&gt;prefix',
  'oj-platform</code> write는 0건',
  '다음 G3-IMP-07-S04',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionZipMapping.includes(marker)) {
    fail('g3-imp-07-s03-explicit-mapping.html: missing implementation marker ' + marker);
  }
}

const submissionZipFinalization = documents.get(path.join(docsRoot, 'g3-imp-07-s04-confirm-cancel.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 07 · Step 04',
  'G3-IMP-07-S04 완료',
  'IMP-07 4/5',
  'C20·C21 활성',
  'POST /oj-ai/v1/zip-uploads/{upload_id}/commands/confirm',
  'POST /oj-ai/v1/zip-uploads/{upload_id}/commands/cancel',
  'command_id',
  'expected_mapping_version',
  'reason',
  'zip_preview_not_ready',
  'zip_mapping_invalid',
  'submission_official_exists',
  'proxy_submit',
  'proxy_submitted',
  'oj_ai_zip_uploads',
  'oj_ai_zip_upload_items',
  'oj_ai_submissions',
  'oj_ai_submission_revisions',
  'oj_ai_submission_receipts',
  'oj_ai_submission_attachments',
  'oj_ai_private_files',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'tests/Contract/submission-zip-confirm-cancel-g3-imp-07-s04.php',
  'scripts/check-submission-zip-g3-imp-07.ps1',
  'API-AUD-2026-08-22-R36-OBSERVED',
  '$wpdb-&gt;prefix',
  'oj-platform</code> write는 0건',
  '다음 G3-IMP-07-S05',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionZipFinalization.includes(marker)) {
    fail('g3-imp-07-s04-confirm-cancel.html: missing implementation marker ' + marker);
  }
}
const submissionZipFreeze = documents.get(path.join(docsRoot, 'g3-imp-07-s05-integration-freeze.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 07 · Step 05',
  'G3-IMP-07-S05 완료',
  'IMP-07 5/5 완료',
  'G3 7/8 진행',
  'C19·Q35·C68·C20·C21 동결',
  '100 MiB',
  '25 MiB',
  '500 MiB',
  '20:1',
  '300초 lease',
  'legal_hold_until_utc',
  'submission_zip.file.purged',
  'oj_ai_assignments',
  'oj_ai_zip_uploads',
  'oj_ai_zip_upload_items',
  'oj_ai_private_files',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'tests/Contract/submission-zip-integration-freeze-g3-imp-07-s05.php',
  'scripts/check-submission-zip-g3-imp-07.ps1',
  'API-AUD-2026-08-22-R37-OBSERVED',
  '$wpdb-&gt;prefix',
  'oj-platform</code> write는 0건',
  '다음 G3-IMP-08-S01',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionZipFreeze.includes(marker)) {
    fail('g3-imp-07-s05-integration-freeze.html: missing implementation marker ' + marker);
  }
}

const submissionPdfS01 = documents.get(path.join(docsRoot, 'g3-imp-08-s01-teacher-submission-pdf.html')).content;
for (const marker of [
  'G3-IMP-08-S01',
  '세부 7/7 완료',
  'S01-A~G 완료',
  'S01-G 완료',
  'generate-submission-pdf-visual.php',
  'check-submission-pdf-integration-s01-g.ps1',
  'teacher-submission-ko.pdf',
  '3페이지 A4',
  'desktop·mobile',
  'C49',
  'submission_print.v1',
  'API-AUD-2026-08-23-R42-OBSERVED',
  'submission_pdf',
  'submission_revision',
  'oj_ai_export_jobs',
  'oj_ai_pdf_artifacts',
  'submission-pdf-enqueue-g3-imp-08-s01.php',
  'submission-pdf-worker-g3-imp-08-s01.php',
  'submission-pdf-query-download-g3-imp-08-s01.php',
  'lease CAS',
  'Q23·Q24',
  '신규 table 없음',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionPdfS01.includes(marker)) {
    fail('g3-imp-08-s01-teacher-submission-pdf.html: missing implementation marker ' + marker);
  }
}

if (submissionPdfS01.includes('$wpdb->prefix') || submissionPdfS01.includes('wp_oj_ai_')) {
  fail('g3-imp-08-s01-teacher-submission-pdf.html: forbidden table prefix found');
}


const learnerWorksheetPdfS02 = documents.get(path.join(docsRoot, 'g3-imp-08-s02-learner-worksheet-pdf.html')).content;
for (const marker of [
  'G3-IMP-08-S02 완료',
  '세부 6/6 완료',
  'S02-A',
  'S02-F',
  'C51',
  'student_blank',
  'self_target',
  'worksheet_publication',
  'worksheet_pdf_request 1.1.0',
  'oj_ai_assignments',
  'oj_ai_assignment_targets',
  'oj_ai_export_jobs',
  'oj_ai_pdf_artifacts',
  'oj_ai_private_files',
  'check:own-worksheet-pdf-g3',
  'own-worksheet-pdf-enqueue-worker-g3-imp-08-s02.php',
  'own-worksheet-pdf-query-download-g3-imp-08-s02.php',
  'descriptor',
  'desktop·mobile',
  'API-AUD-2026-08-23-R43-OBSERVED',
  '플랫폼 API 변경 없음',
  '다음 G3-IMP-08-S03',
]) {
  if (!learnerWorksheetPdfS02.includes(marker)) {
    fail('g3-imp-08-s02-learner-worksheet-pdf.html: missing implementation marker ' + marker);
  }
}
if (learnerWorksheetPdfS02.includes('$wpdb->prefix') || learnerWorksheetPdfS02.includes('wp_oj_ai_')) {
  fail('g3-imp-08-s02-learner-worksheet-pdf.html: forbidden table prefix found');
}

const learnerSubmissionPdfS03 = documents.get(path.join(docsRoot, 'g3-imp-08-s03-learner-submission-pdf.html')).content;
for (const marker of [
  'G3 · Distribution · Implementation 08 · Step 03',
  'G3-IMP-08-S03 완료',
  '세부 6/6 완료',
  'S03-A',
  'S03-F',
  'POST /oj-ai/v1/me/submissions/{submission_id}/commands/export-pdf',
  'expected_assignment_id',
  'expected_attachment_set_hash',
  'expected_official_revision_hash',
  'expected_official_revision_id',
  'expected_submission_lock_version',
  'teacher_submission',
  'self_submission',
  'submission_pdf_request 1.1.0',
  'oj_ai_assignments',
  'oj_ai_assignment_targets',
  'oj_ai_submissions',
  'oj_ai_export_jobs',
  'oj_ai_pdf_artifacts',
  'oj_ai_private_files',
  'check:own-submission-pdf-g3',
  'own-submission-pdf-enqueue-worker-g3-imp-08-s03.php',
  'own-submission-pdf-query-download-g3-imp-08-s03.php',
  'e2e:student',
  'reopened·locked',
  'desktop·mobile',
  'API-AUD-2026-08-23-R44-OBSERVED',
  '플랫폼 API 변경 없음',
  '다음 G3-IMP-08-S04',
]) {
  if (!learnerSubmissionPdfS03.includes(marker)) {
    fail('g3-imp-08-s03-learner-submission-pdf.html: missing implementation marker ' + marker);
  }
}
if (learnerSubmissionPdfS03.includes('$wpdb->prefix') || learnerSubmissionPdfS03.includes('wp_oj_ai_')) {
  fail('g3-imp-08-s03-learner-submission-pdf.html: forbidden table prefix found');
}

const pdfIntegrationFreezeS04 = documents.get(path.join(docsRoot, 'g3-imp-08-s04-pdf-integration-freeze.html')).content;
for (const marker of [
  'G3 · IMP-08 · S04',
  'S04 A~F 6/6 완료',
  'G3 8/8 완료',
  'C49',
  'C51',
  'C67',
  'context_staff + teacher_view + submission',
  'self_target + export_pdf + assignment',
  'self_submission + export_pdf + submission',
  'relation과 action·object type·Context 유무의 exact 조합',
  'canonical 출력으로 렌더링',
  'snapshot 강등',
  '3페이지 A4 portrait',
  'check:pdf-integration-g3',
  'pdf-profile-integration-freeze-g3-imp-08-s04.php',
  'check:submission-pdf-g3',
  'check:own-worksheet-pdf-g3',
  'check:own-submission-pdf-g3',
  'e2e:student',
  'API-AUD-2026-08-23-R45-OBSERVED',
  '플랫폼 API 변경 없음',
  '다음 구현 관문은 <code>G4-IMP-01</code>',
]) {
  if (!pdfIntegrationFreezeS04.includes(marker)) {
    fail('g3-imp-08-s04-pdf-integration-freeze.html: missing implementation marker ' + marker);
  }
}
if (pdfIntegrationFreezeS04.includes('$wpdb->prefix') || pdfIntegrationFreezeS04.includes('wp_oj_ai_')) {
  fail('g3-imp-08-s04-pdf-integration-freeze.html: forbidden table prefix found');
}

const submissionAssessmentInstructionS01 = documents.get(path.join(docsRoot, 'g4-imp-01-s01-score-task-instruction.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 01 · Step 01',
  'G4-IMP-01-S01 완료',
  '현재 IMP-01 2/5',
  'addon.oj_ai_dashboard.student_submission_assessment',
  'student_submission_assessment.v1',
  'sourceKind=addon_payload',
  'student_submission_assessment_prompt.v2',
  '5,131 bytes',
  'e4eb274fe8156e2b1e6d9413ae95f0bb020301df6eb84a076f70a5647b7016d3',
  'student_submission_assessment_prompt.v1',
  '1,242 bytes',
  '62f147f6638ad72bb05e703df69c920ecc568e35cd090e83f9f2955a13527a66',
  '[submission_score_prompt.v1]',
  '[submission_feedback_prompt.v1]',
  '[submission_special_note_prompt.v1]',
  'submission_score.v1',
  'submission_feedback.v1',
  'student_special_note.v1',
  '점수 범위를 임의로 clamp',
  '보류를 0점으로 바꾸지 않는다',
  '교사가 별도 review 단계',
  'check:submission-assessment-instruction-g4',
  'submission-assessment-instruction-g4-imp-01-s01.php',
  'addon-i06-ai-instruction.php',
  'API-AUD-2026-08-23-R46-OBSERVED',
  '신규 route·table·migration·플랫폼 source 변경은 모두 0건',
  'OJ Platform 파일을 수정하지 않았다',
  'G4-IMP-01-S02 official input projection 완료',
  '다음 G4-IMP-01-S03',
]) {
  if (!submissionAssessmentInstructionS01.includes(marker)) {
    fail('g4-imp-01-s01-score-task-instruction.html: missing implementation marker ' + marker);
  }
}
if (submissionAssessmentInstructionS01.includes('$wpdb->prefix') || submissionAssessmentInstructionS01.includes('wp_oj_ai_')) {
  fail('g4-imp-01-s01-score-task-instruction.html: forbidden table prefix found');
}
const submissionAssessmentInputS02 = documents.get(path.join(docsRoot, 'g4-imp-01-s02-official-input-projection.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 01 · Step 02',
  'G4-IMP-01-S02 완료',
  'IMP-01 2/5',
  '다음 G4-IMP-01-S03',
  'Submission_Score_Input_Builder',
  'exact revision identity',
  'oj_ai.grading_schema@1.1.0',
  'student_submission_assessment.v1',
  'submission_score.v1',
  'oj_ai.submission_score_request@1.0.0',
  'oj_ai.submission_score_output',
  'student_submission_assessment_prompt.v2',
  'submission_score_prompt.v1',
  'short_text',
  'long_text',
  'single_choice',
  'multiple_choice',
  'checkbox',
  'number',
  'table',
  'image_upload',
  'drawing',
  'file_upload',
  'submission_pii_redaction.v1',
  'artifact_references',
  'source_revision_hash',
  'rubric_hash',
  'P03 grading projector만',
  '921,600 bytes',
  'artifact_not_ready',
  'official_submission_conflict',
  'assessment_configuration_conflict',
  'check:submission-assessment-input-g4',
  'submission-assessment-input-g4-imp-01-s02.php',
  'worksheet-publication-s03.php',
  'API-AUD-2026-08-23-R47-OBSERVED',
  '신규 route·table·column·migration과 OJ Platform source 변경은 0건',
]) {
  if (!submissionAssessmentInputS02.includes(marker)) {
    fail('g4-imp-01-s02-official-input-projection.html: missing implementation marker ' + marker);
  }
}
if (submissionAssessmentInputS02.includes('$wpdb->prefix') || submissionAssessmentInputS02.includes('wp_oj_ai_')) {
  fail('g4-imp-01-s02-official-input-projection.html: forbidden table prefix found');
}
const submissionEvaluationEnqueueS03 = documents.get(path.join(docsRoot, 'g4-imp-01-s03-single-evaluation-enqueue.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 01 · Step 03',
  'G4-IMP-01-S03 완료',
  '현재 IMP-01 5/5 완료',
  '다음 G4-IMP-02-S01',
  'POST /oj-ai/v1/submissions/{submission_id}/commands/evaluate',
  'submission.evaluation.enqueue',
  'assess_submissions',
  'context_type',
  'context_id',
  'context_revision',
  'expected_official_revision_id',
  'expected_official_revision_hash',
  'Submission_Evaluation_Rest_Controller',
  'Enqueue_Submission_Evaluation',
  'oj_ai_submission_evaluations',
  'oj_ai_submission_evaluation_inputs',
  'oj_ai_evaluation_runs',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'student_submission_assessment.v1',
  'submission_score.v1',
  'submission_evaluation.queued',
  'submission_evaluation.enqueue_replayed',
  'exact receipt replay',
  'semantic replay',
  'canRoute',
  'snapshot',
  'Gateway execute 0-call',
  'npm run check:submission-evaluation-enqueue-g4',
  'submission-evaluation-enqueue-g4-imp-01-s03.php',
  'API-AUD-2026-08-23-R48-OBSERVED',
  '신규 table·column·migration과 OJ Platform source 변경은 0건',
  'G4-IMP-01-S04 완료',
  'G4-IMP-01-S05 완료',
]) {
  if (!submissionEvaluationEnqueueS03.includes(marker)) {
    fail('g4-imp-01-s03-single-evaluation-enqueue.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationEnqueueS03.includes('$wpdb->prefix') || submissionEvaluationEnqueueS03.includes('wp_oj_ai_')) {
  fail('g4-imp-01-s03-single-evaluation-enqueue.html: forbidden table prefix found');
}

const assignmentEvaluationEnqueueS04 = documents.get(path.join(docsRoot, 'g4-imp-01-s04-assignment-evaluation-enqueue.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 01 · Step 04',
  'G4-IMP-01-S04 완료',
  'IMP-01 5/5 완료',
  'C30 활성화',
  'G4-IMP-01-S05 완료',
  '다음 G4-IMP-02-S01',
  'POST /oj-ai/v1/assignments/{assignment_id}/commands/evaluate-submissions',
  'assignment.evaluations.enqueue',
  'assess_submissions',
  'evaluate_submissions',
  'command_id',
  'expected_schedule_version',
  'item_cursor',
  'source_cutoff_utc',
  'created',
  'existing',
  'skipped',
  'failed',
  'oj_ai_outbox_events',
  'oj_ai.assignment_evaluation_batch_receipt',
  '191자를 지킨다',
  'npm run check:assignment-evaluation-enqueue-g4',
  'assignment-evaluation-enqueue-g4-imp-01-s04.php',
  'API-AUD-2026-08-23-R49-OBSERVED',
  '신규 table·column·migration 0건',
  '공개 API 변경 없음',
]) {
  if (!assignmentEvaluationEnqueueS04.includes(marker)) {
    fail('g4-imp-01-s04-assignment-evaluation-enqueue.html: missing implementation marker ' + marker);
  }
}
if (assignmentEvaluationEnqueueS04.includes('$wpdb->prefix') || assignmentEvaluationEnqueueS04.includes('wp_oj_ai_')) {
  fail('g4-imp-01-s04-assignment-evaluation-enqueue.html: forbidden table prefix found');
}

const submissionEvaluationFreezeS05 = documents.get(path.join(docsRoot, 'g4-imp-01-s05-idempotency-authorization-freeze.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 01 · Step 05',
  'G4-IMP-01-S05 완료',
  'IMP-01 5/5 완료',
  'G4 1/7 진행',
  '다음 G4-IMP-02-S01',
  'POST /oj-ai/v1/submissions/{submission_id}/commands/evaluate',
  'POST /oj-ai/v1/assignments/{assignment_id}/commands/evaluate-submissions',
  'WeakMap',
  'request-bound',
  'actor_user_id + action + object + fingerprint',
  'SOURCE_STALE',
  'semantic replay',
  'npm run check:submission-evaluation-freeze-g4',
  'submission-evaluation-request-freeze-g4-imp-01-s05.php',
  'API-AUD-2026-08-23-R50-OBSERVED',
  'API-AUD-2026-08-23-R51-OBSERVED',
  'API-AUD-2026-08-23-R52-OBSERVED',
  '신규 route·table·column·migration 0건',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionEvaluationFreezeS05.includes(marker)) {
    fail('g4-imp-01-s05-idempotency-authorization-freeze.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationFreezeS05.includes('$wpdb->prefix') || submissionEvaluationFreezeS05.includes('wp_oj_ai_')) {
  fail('g4-imp-01-s05-idempotency-authorization-freeze.html: forbidden table prefix found');
}

const submissionEvaluationQueueLeaseS01 = documents.get(path.join(docsRoot, 'g4-imp-02-s01-evaluation-queue-lease.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 02 · Step 01',
  'G4-IMP-02-S01 완료',
  'IMP-02 1/5',
  '다음 G4-IMP-02-S02',
  'Claim_Next_Submission_Evaluation',
  'Start_Submission_Evaluation_Attempt',
  'Submission_Evaluation_Work_Item',
  'WordPress_Submission_Evaluation_Work_Repository',
  'queued|retry_wait',
  'leased|running',
  '300초',
  'worker_lost',
  'append-only',
  'npm run check:submission-evaluation-queue-lease-g4',
  'submission-evaluation-queue-lease-g4-imp-02-s01.php',
  '전체 애드온 회귀 종료 코드 0',
  '119개 설계 HTML + UI 시안 1개 통과',
  'API-AUD-2026-08-23-R53-OBSERVED',
  '신규 table·column·migration·route 0건',
  '플랫폼 API 변경 없음',
  '아직 AI를 호출하지 않음',
]) {
  if (!submissionEvaluationQueueLeaseS01.includes(marker)) {
    fail('g4-imp-02-s01-evaluation-queue-lease.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationQueueLeaseS01.includes('$wpdb->prefix') || submissionEvaluationQueueLeaseS01.includes('wp_oj_ai_')) {
  fail('g4-imp-02-s01-evaluation-queue-lease.html: forbidden table prefix found');
}

const submissionEvaluationWorkerS02 = documents.get(path.join(docsRoot, 'g4-imp-02-s02-gateway-output-result.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 02 · Step 02',
  'S02 실행 코어 완료',
  'IMP-02 2/5',
  'R01 후 Worker 활성',
  'Platform_Submission_Evaluation_Execution',
  'Run_Next_Submission_Evaluation',
  'Submission_Evaluation_Output_Normalizer',
  'WordPress_Submission_Evaluation_Work_Repository',
  '524,288 bytes',
  '최대 JSON 깊이는 12',
  'artifact_quality_issue',
  'evidence_limited',
  'response_ambiguous',
  'safety_review_required',
  'teacher_review_required',
  'candidate total은 null',
  'oj_ai.submission_external_ai_processing_consent@1.0.0',
  '숨은 option fallback 없이',
  'Composition_Root</code>에 등록했고',
  'npm run check:submission-evaluation-worker-g4',
  'submission-evaluation-worker-g4-imp-02-s02.php',
  '121개 설계 HTML + UI 시안 1개 통과',
  'API-AUD-2026-08-23-R54-OBSERVED',
  '다음 G4-IMP-02-S03',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionEvaluationWorkerS02.includes(marker)) {
    fail('g4-imp-02-s02-gateway-output-result.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationWorkerS02.includes('$wpdb->prefix') || submissionEvaluationWorkerS02.includes('wp_oj_ai_')) {
  fail('g4-imp-02-s02-gateway-output-result.html: forbidden table prefix found');
}

const submissionExternalAiConsentR01 = documents.get(path.join(docsRoot, 'g4-imp-02-r01-submission-external-ai-consent.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 02 · Required Gate 01',
  'R01 완료',
  'Worker 활성',
  'Page 9 · GET 38 · POST 79',
  'Q38',
  'C78',
  'C79',
  'oj_ai.submission_external_ai_processing_consent@1.0.0',
  'submission_external_ai_consent',
  'student_artifact_text',
  'student_response_content',
  'submission_assessment_configuration',
  'worksheet_grading_content',
  'oj_ai_outbox_events',
  'oj_ai_audit_events',
  'WordPress option',
  'npm run check:submission-external-ai-consent-g4',
  'API-AUD-2026-08-23-R56-OBSERVED',
  '전체 애드온 회귀 종료 코드 0',
  '다음 G4-IMP-02-S03',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionExternalAiConsentR01.includes(marker)) {
    fail('g4-imp-02-r01-submission-external-ai-consent.html: missing implementation marker ' + marker);
  }
}
if (submissionExternalAiConsentR01.includes('$wpdb->prefix') || submissionExternalAiConsentR01.includes('wp_oj_ai_')) {
  fail('g4-imp-02-r01-submission-external-ai-consent.html: forbidden table prefix found');
}

const submissionEvaluationQueryS03 = documents.get(path.join(docsRoot, 'g4-imp-02-s03-submission-evaluation-query.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 02 · Step 03',
  'S03 완료',
  'IMP-02 3/5',
  'Q16·Q17 활성',
  'GET /oj-ai/v1/submission-evaluations',
  'assess_submissions',
  'review_assessments',
  'Rest_Permission_Handoff::check_any()',
  'Evaluation_Object_Authorization_Router',
  '<code>q16</code>',
  '128-bit scope hash',
  '191자 한도',
  'raw provider 출력',
  'schema <code>1.0.2</code>',
  '202608230001_expand_submission_prompt_contract',
  'e49cb1fbcd726f4aae44fe4cb0ad952eabda49971420a7a2a398eee037fd6ee6',
  'npm run check:submission-evaluation-query-g4',
  'submission-evaluation-query-g4-imp-02-s03.php',
  'API-AUD-2026-08-23-R57-OBSERVED',
  '다음 G4-IMP-02-S04',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionEvaluationQueryS03.includes(marker)) {
    fail('g4-imp-02-s03-submission-evaluation-query.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationQueryS03.includes('$wpdb->prefix') || submissionEvaluationQueryS03.includes('wp_oj_ai_')) {
  fail('g4-imp-02-s03-submission-evaluation-query.html: forbidden table prefix found');
}
const submissionEvaluationControlS04 = documents.get(path.join(docsRoot, 'g4-imp-02-s04-submission-evaluation-control.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 02 · Step 04',
  'S04 완료',
  'IMP-02 4/5',
  'C31·C32 활성',
  'POST /oj-ai/v1/submission-evaluations/{evaluation_id}/commands/retry',
  'POST /oj-ai/v1/submission-evaluations/{evaluation_id}/commands/cancel',
  'expected_latest_result_id',
  'Addon_Capabilities::ASSESS_SUBMISSIONS',
  'Context_Scope::OPERATION_EVALUATE',
  'Submission_Evaluation_Object_Authorization',
  'same evaluation identity',
  '새 routing snapshot',
  'append-only',
  "lock_matches($item, 'running')",
  'oj_ai_evaluation_runs',
  'oj_ai_submission_evaluation_results',
  'npm run check:submission-evaluation-control-g4',
  'submission-evaluation-control-g4-imp-02-s04.php',
  'API-AUD-2026-08-23-R58-OBSERVED',
  '다음 G4-IMP-02-S05',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionEvaluationControlS04.includes(marker)) {
    fail('g4-imp-02-s04-submission-evaluation-control.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationControlS04.includes('$wpdb->prefix') || submissionEvaluationControlS04.includes('wp_oj_ai_')) {
  fail('g4-imp-02-s04-submission-evaluation-control.html: forbidden table prefix found');
}
const submissionEvaluationIntegrationS05 = documents.get(path.join(docsRoot, 'g4-imp-02-s05-submission-evaluation-integration-freeze.html')).content;
for (const marker of [
  'G4 · Submission Assessment · Implementation 02 · Step 05',
  'S05 완료',
  'IMP-02 5/5 완료',
  'G4-IMP-02-S05-A',
  'G4-IMP-02-S05-E',
  'candidate_score = null',
  'routing_changed_during_execute',
  'STORED_DATA_INVALID',
  '비만료 고정 시계',
  '<code>npm test</code> 종료 코드 0·124개 HTML·UI 시안 1개 통과',
  "lock_matches($item, 'running')",
  'check:submission-evaluation-integration-g4',
  'submission-evaluation-integration-g4-imp-02-s05.php',
  'API-AUD-2026-08-23-R59-OBSERVED',
  '다음 G4-IMP-03-S01',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionEvaluationIntegrationS05.includes(marker)) {
    fail('g4-imp-02-s05-submission-evaluation-integration-freeze.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationIntegrationS05.includes('$wpdb->prefix') || submissionEvaluationIntegrationS05.includes('wp_oj_ai_')) {
  fail('g4-imp-02-s05-submission-evaluation-integration-freeze.html: forbidden table prefix found');
}

const submissionEvaluationReviewS01 = documents.get(path.join(docsRoot, 'g4-imp-03-s01-submission-evaluation-review-append.html')).content;
for (const marker of [
  'G4 · Teacher Review · Implementation 03 · Step 01',
  'S01 완료',
  '후속 S02 완료·IMP-03 2/4',
  'S02에서 C34 활성',
  'G4-IMP-03-S02',
  'oj_ai.submission_score_decisions@1.0.0',
  'binding_key',
  'official_points',
  'teacher_assigned',
  'review_source_stale',
  'review_items_incomplete',
  'review_score_invalid',
  'safety_review_required',
  'check:submission-evaluation-review-g4',
  'submission-evaluation-review-storage-g4-imp-03-s02.php',
  'submission-evaluation-review-rest-g4-imp-03-s02.php',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionEvaluationReviewS01.includes(marker)) {
    fail('g4-imp-03-s01-submission-evaluation-review-append.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationReviewS01.includes('$wpdb->prefix') || submissionEvaluationReviewS01.includes('wp_oj_ai_')) {
  fail('g4-imp-03-s01-submission-evaluation-review-append.html: forbidden table prefix found');
}

const submissionEvaluationReviewS02 = documents.get(path.join(docsRoot, 'g4-imp-03-s02-official-pointer-cas.html')).content;
for (const marker of [
  'G4 · Teacher Review · Implementation 03 · Step 02',
  'S02 완료',
  'IMP-03 2/4',
  'C34 활성',
  'START TRANSACTION',
  'accepted|adjusted',
  'rejected',
  'review_source_stale',
  'official_review_changed',
  'POST /oj-ai/v1/submission-evaluations/{evaluation_id}/commands/review',
  'review_assessments',
  'Context operation <code>evaluate</code>',
  'evaluation object action <code>review</code>',
  '새 저장 201, exact replay 200',
  'submission-evaluation-review-storage-g4-imp-03-s02.php',
  'submission-evaluation-review-rest-g4-imp-03-s02.php',
  'API-AUD-2026-08-23-R61-OBSERVED',
  '다음 G4-IMP-03-S03 점수·근거 검토 UI',
  '<code>npm test</code> 종료 코드 0',
  '회귀 보완',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionEvaluationReviewS02.includes(marker)) {
    fail('g4-imp-03-s02-official-pointer-cas.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationReviewS02.includes('$wpdb->prefix') || submissionEvaluationReviewS02.includes('wp_oj_ai_')) {
  fail('g4-imp-03-s02-official-pointer-cas.html: forbidden table prefix found');
}

const submissionEvaluationReviewWorkbenchS03 = documents.get(path.join(docsRoot, 'g4-imp-03-s03-score-evidence-review-ui.html')).content;
for (const marker of [
  'G4 · Teacher Review · Implementation 03 · Step 03',
  'S03 완료',
  'IMP-03 3/4',
  'Q16·Q17·C34 연결',
  '학생 제출',
  '활동지 자체 점검',
  'assess_submissions',
  'review_assessments',
  'review_precondition',
  'expected_result_id',
  'expected_official_review_id',
  'expected_review_no',
  'accepted|adjusted|rejected',
  'teacher_assigned',
  'textContent',
  'replaceChildren',
  'desktop 1440×1000',
  'mobile 390×844',
  'check:submission-evaluation-review-workbench-g4',
  'API-AUD-2026-08-23-R63-OBSERVED',
  '다음 G4-IMP-03-S04 stale source 회귀',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionEvaluationReviewWorkbenchS03.includes(marker)) {
    fail('g4-imp-03-s03-score-evidence-review-ui.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationReviewWorkbenchS03.includes('$wpdb->prefix') || submissionEvaluationReviewWorkbenchS03.includes('wp_oj_ai_')) {
  fail('g4-imp-03-s03-score-evidence-review-ui.html: forbidden table prefix found');
}

const submissionEvaluationReviewIntegrationS04 = documents.get(path.join(docsRoot, 'g4-imp-03-s04-stale-source-integration-freeze.html')).content;
for (const marker of [
  'G4 · Teacher Review · Implementation 03 · Step 04',
  'G4-IMP-03-S04 완료',
  'IMP-03 4/4 완료',
  '경합 5종 통과',
  'review_source_stale',
  '최신 평가 result',
  '공식 review pointer',
  '현재 학생 release',
  'evaluation root CAS',
  'submission root CAS',
  'durable replay',
  '409·403 화면 복구',
  'state.canReview',
  'oj_ai_submission_evaluation_reviews',
  'oj_ai_submission_evaluation_review_items',
  'oj_ai_submission_feedback_releases',
  'check:submission-evaluation-review-integration-g4',
  'submission-evaluation-review-integration-g4-imp-03-s04.php',
  'API-AUD-2026-08-24-R64-OBSERVED',
  '다음 G4-IMP-04-S01',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionEvaluationReviewIntegrationS04.includes(marker)) {
    fail('g4-imp-03-s04-stale-source-integration-freeze.html: missing implementation marker ' + marker);
  }
}
if (submissionEvaluationReviewIntegrationS04.includes('$wpdb->prefix') || submissionEvaluationReviewIntegrationS04.includes('wp_oj_ai_')) {
  fail('g4-imp-03-s04-stale-source-integration-freeze.html: forbidden table prefix found');
}

const submissionFeedbackDraftS01 = documents.get(path.join(docsRoot, 'g4-imp-04-s01-feedback-draft.html')).content;
for (const marker of [
  'G4 · Feedback · Implementation 04 · Step 01',
  'G4-IMP-04-S01 완료',
  'IMP-04 1/4',
  'C35 활성',
  'POST /submission-evaluations/{evaluation_id}/commands/generate-feedback',
  'student_submission_assessment.v1',
  'feedback_draft',
  'oj_ai.submission_feedback_request@1.0.0',
  'review_assessments',
  '학생 비공개',
  'oj_ai_submission_feedback_revisions',
  'ai_draft',
  'feedback_source_stale',
  'feedback_schema_invalid',
  'check:submission-feedback-g4',
  'submission-feedback-draft-g4-imp-04-s01.php',
  'submission-feedback-worker-g4-imp-04-s01.php',
  'API-AUD-2026-08-24-R65-OBSERVED',
  '다음 G4-IMP-04-S02',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionFeedbackDraftS01.includes(marker)) {
    fail('g4-imp-04-s01-feedback-draft.html: missing implementation marker ' + marker);
  }
}
if (submissionFeedbackDraftS01.includes('$wpdb->prefix') || submissionFeedbackDraftS01.includes('wp_oj_ai_')) {
  fail('g4-imp-04-s01-feedback-draft.html: forbidden table prefix found');
}

const submissionFeedbackReviewS02 = documents.get(path.join(docsRoot, 'g4-imp-04-s02-feedback-review.html')).content;
for (const marker of [
  'G4 · Feedback · Implementation 04 · Step 02',
  'G4-IMP-04-S02 완료',
  'IMP-04 2/4',
  'C36 활성',
  'POST /submission-evaluations/{evaluation_id}/commands/review-feedback',
  'expected_official_review_hash',
  'expected_feedback_revision_hash',
  'source_mode',
  'direct + teacher_draft',
  'edit + teacher_draft',
  'edit + rejected',
  'teacher_draft|rejected',
  'oj_ai_submission_feedback_revisions',
  'START TRANSACTION',
  'feedback_source_stale',
  'durable replay',
  'check:submission-feedback-review-g4',
  'submission-feedback-review-g4-imp-04-s02.php',
  'submission-feedback-review-storage-g4-imp-04-s02.php',
  'submission-feedback-review-rest-g4-imp-04-s02.php',
  'API-AUD-2026-08-24-R66-OBSERVED',
  '다음 G4-IMP-04-S03',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionFeedbackReviewS02.includes(marker)) {
    fail('g4-imp-04-s02-feedback-review.html: missing implementation marker ' + marker);
  }
}
if (submissionFeedbackReviewS02.includes('$wpdb->prefix') || submissionFeedbackReviewS02.includes('wp_oj_ai_')) {
  fail('g4-imp-04-s02-feedback-review.html: forbidden table prefix found');
}

const submissionFeedbackApprovalS03 = documents.get(path.join(docsRoot, 'g4-imp-04-s03-feedback-approval.html')).content;
for (const marker of [
  'G4 · Feedback · Implementation 04 · Step 03',
  'G4-IMP-04-S03 완료',
  'IMP-04 3/4',
  'C37 활성',
  'POST /submission-evaluations/{evaluation_id}/commands/approve-feedback',
  'expected_official_review_hash',
  'expected_feedback_revision_hash',
  '"payload": {}',
  'teacher_draft → approved',
  'submission_feedback.approved',
  'oj_ai.submission_feedback_approval_audit',
  'oj_ai.submission_feedback_approval_event',
  'oj_ai_submission_feedback_revisions',
  '저장 result profile을 gateway output 형태로 재정규화',
  '승인은 학생 공개가 아니다',
  'check:submission-feedback-approval-g4',
  'submission-feedback-approval-g4-imp-04-s03.php',
  'submission-feedback-approval-storage-g4-imp-04-s03.php',
  'submission-feedback-approval-rest-g4-imp-04-s03.php',
  'API-AUD-2026-08-24-R67-OBSERVED',
  '다음 G4-IMP-04-S04',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionFeedbackApprovalS03.includes(marker)) {
    fail('g4-imp-04-s03-feedback-approval.html: missing implementation marker ' + marker);
  }
}
if (submissionFeedbackApprovalS03.includes('$wpdb->prefix') || submissionFeedbackApprovalS03.includes('wp_oj_ai_')) {
  fail('g4-imp-04-s03-feedback-approval.html: forbidden table prefix found');
}

const submissionFeedbackWorkbenchS04 = documents.get(path.join(docsRoot, 'g4-imp-04-s04-feedback-workbench-integration.html')).content;
for (const marker of [
  'G4 · Feedback · Implementation 04 · Step 04',
  'G4-IMP-04-S04 완료',
  'IMP-04 4/4 완료',
  'C35~C37 UI 연결',
  '공식 review가 이 evaluation에 속할 때만 작성 영역을 연다',
  'AI 초안·초안 저장',
  '수정 저장·기각·승인',
  '학생 공개 전',
  '피드백이 변경되었습니다. 다시 확인해 주세요.',
  'data-can-review=0',
  'oj_ai_submission_feedback_revisions',
  'submission-feedback-workbench-desktop.png',
  'submission-feedback-workbench-mobile.png',
  'check:submission-feedback-integration-g4',
  'submission-feedback-workbench-g4-imp-04-s04.mjs',
  'submission-feedback-workbench-g4-imp-04-s04.php',
  'e2e-submission-feedback-workbench.mjs',
  'API-AUD-2026-08-24-R68-OBSERVED',
  '다음 G4-IMP-05-S01',
  '플랫폼 API 변경 없음',
]) {
  if (!submissionFeedbackWorkbenchS04.includes(marker)) {
    fail('g4-imp-04-s04-feedback-workbench-integration.html: missing implementation marker ' + marker);
  }
}
if (submissionFeedbackWorkbenchS04.includes('$wpdb->prefix') || submissionFeedbackWorkbenchS04.includes('wp_oj_ai_')) {
  fail('g4-imp-04-s04-feedback-workbench-integration.html: forbidden table prefix found');
}

const studentNoteDraftS01 = documents.get(path.join(docsRoot, 'g4-imp-05-s01-student-note-draft.html')).content;
for (const marker of [
  'G4 · Student Notes · Implementation 05 · Step 01',
  'G4-IMP-05-S01 완료',
  'IMP-05 1/5',
  'C38 활성',
  'POST /oj-ai/v1/submission-evaluations/{evaluation_id}/commands/generate-student-note',
  'expected_official_review_hash',
  '"payload": {}',
  'student_submission_assessment.v1',
  'special_note_draft',
  'student_special_note.v1',
  'oj_ai.student_special_note_request|output|result@1.0.0',
  'manage_student_notes',
  'oj_ai_student_note_revisions',
  'include_in_reports=0',
  'student_note_source_stale',
  'student_note_schema_invalid',
  'check:student-note-draft-g4',
  'student-note-draft-g4-imp-05-s01.php',
  'student-note-worker-g4-imp-05-s01.php',
  'API-AUD-2026-08-24-R69-OBSERVED',
  '다음 G4-IMP-05-S02',
  '플랫폼 API 변경 없음',
]) {
  if (!studentNoteDraftS01.includes(marker)) {
    fail('g4-imp-05-s01-student-note-draft.html: missing implementation marker ' + marker);
  }
}
if (studentNoteDraftS01.includes('$wpdb->prefix') || studentNoteDraftS01.includes('wp_oj_ai_')) {
  fail('g4-imp-05-s01-student-note-draft.html: forbidden table prefix found');
}

const studentNoteQueryS02 = documents.get(path.join(docsRoot, 'g4-imp-05-s02-student-note-query.html')).content;
for (const marker of [
  'G4 · Student Notes · Implementation 05 · Step 02',
  'G4-IMP-05-S02 완료',
  'IMP-05 2/5',
  'Q18·Q19 활성',
  'GET /oj-ai/v1/student-notes',
  'GET /oj-ai/v1/student-notes/{note_id}',
  'report_status',
  'manage_student_notes',
  'student_note',
  'student_visible=false',
  'report_auto_inclusion=false',
  'oj_ai_student_note_revisions',
  'check:student-note-query-g4',
  'student-note-query-g4-imp-05-s02.php',
  'API-AUD-2026-08-24-R70-OBSERVED',
  '다음 G4-IMP-05-S03',
  '플랫폼 API 변경 없음',
]) {
  if (!studentNoteQueryS02.includes(marker)) {
    fail('g4-imp-05-s02-student-note-query.html: missing implementation marker ' + marker);
  }
}
if (studentNoteQueryS02.includes('$wpdb->prefix') || studentNoteQueryS02.includes('wp_oj_ai_')) {
  fail('g4-imp-05-s02-student-note-query.html: forbidden table prefix found');
}

const studentNoteReviewApprovalS03 = documents.get(path.join(docsRoot, 'g4-imp-05-s03-student-note-review-approval.html')).content;
for (const marker of [
  'G4 · Student Notes · Implementation 05 · Step 03',
  'G4-IMP-05-S03 완료',
  'IMP-05 3/5',
  'C39·C40 활성',
  'POST /oj-ai/v1/student-notes/{note_id}/commands/review',
  'POST /oj-ai/v1/student-notes/{note_id}/commands/approve',
  'expected_include_in_reports',
  'payload가 비어 있지 않으면 422',
  '평가의 PublicId를 사용',
  'include_in_reports=false',
  'student_note.reviewed',
  'student_note.approved',
  'check:student-note-review-approval-g4',
  'student-note-review-approval-g4-imp-05-s03.php',
  'student-note-review-approval-storage-g4-imp-05-s03.php',
  'student-note-review-approval-rest-g4-imp-05-s03.php',
  'API-AUD-2026-08-24-R71-OBSERVED',
  '다음 G4-IMP-05-S04',
  '플랫폼 API 변경 없음',
]) {
  if (!studentNoteReviewApprovalS03.includes(marker)) {
    fail('g4-imp-05-s03-student-note-review-approval.html: missing implementation marker ' + marker);
  }
}
if (studentNoteReviewApprovalS03.includes('$wpdb->prefix') || studentNoteReviewApprovalS03.includes('wp_oj_ai_')) {
  fail('g4-imp-05-s03-student-note-review-approval.html: forbidden table prefix found');
}

const studentNoteReportInclusionS04 = documents.get(path.join(docsRoot, 'g4-imp-05-s04-student-note-report-inclusion.html')).content;
for (const marker of [
  'G4 · Student Notes · Implementation 05 · Step 04',
  'G4-IMP-05-S04 완료',
  'IMP-05 4/5',
  'C41·C42 활성',
  'POST /oj-ai/v1/student-notes/{note_id}/commands/include-in-reports',
  'POST /oj-ai/v1/student-notes/{note_id}/commands/exclude-from-reports',
  'expected_include_in_reports',
  '"payload": {}',
  '<code>approved + false</code>',
  '<code>approved + true</code>',
  'student_note.included_in_reports',
  'student_note.excluded_from_reports',
  '원래 승인자와 승인 시각은 보존',
  '과거 snapshot은 그대로 보존',
  'manage_student_notes',
  'oj_ai_student_note_revisions',
  'check:student-note-report-inclusion-g4',
  'student-note-report-inclusion-g4-imp-05-s04.php',
  'student-note-report-inclusion-storage-g4-imp-05-s04.php',
  'student-note-report-inclusion-rest-g4-imp-05-s04.php',
  'API-AUD-2026-08-24-R72-OBSERVED',
  '다음 G4-IMP-05-S05',
  '2.0.0-dev.74',
  '플랫폼 API 변경 없음',
]) {
  if (!studentNoteReportInclusionS04.includes(marker)) {
    fail('g4-imp-05-s04-student-note-report-inclusion.html: missing implementation marker ' + marker);
  }
}
if (studentNoteReportInclusionS04.includes('$wpdb->prefix') || studentNoteReportInclusionS04.includes('wp_oj_ai_')) {
  fail('g4-imp-05-s04-student-note-report-inclusion.html: forbidden table prefix found');
}


const studentNoteIntegrationS05 = documents.get(path.join(docsRoot, 'g4-imp-05-s05-student-note-integration-freeze.html')).content;
for (const marker of [
  'G4 · Student Notes · Implementation 05 · Step 05',
  'G4-IMP-05-S05 완료',
  'IMP-05 5/5 완료',
  'C38~C42·Q18~Q19 동결',
  'student_visible=false',
  'student_note_source_stale',
  '학생 특기사항 REST controller 5개 모두 OJ Console',
  '원 승인자·승인 시각',
  'check:student-note-integration-g4',
  'student-note-integration-g4-imp-05-s05.php',
  'student-note-integration-storage-g4-imp-05-s05.php',
  'API-AUD-2026-08-24-R73-OBSERVED',
  '다음 G4-IMP-06-S01',
  '플랫폼 API 변경 없음',
]) {
  if (!studentNoteIntegrationS05.includes(marker)) {
    fail('g4-imp-05-s05-student-note-integration-freeze.html: missing implementation marker ' + marker);
  }
}
if (studentNoteIntegrationS05.includes('$wpdb->prefix') || studentNoteIntegrationS05.includes('wp_oj_ai_')) {
  fail('g4-imp-05-s05-student-note-integration-freeze.html: forbidden table prefix found');
}
if (studentNoteIntegrationS05.includes('href="/me/') || studentNoteIntegrationS05.includes('SHELL_PUBLIC</code> 등록')) {
  fail('g4-imp-05-s05-student-note-integration-freeze.html: student/public route exposure found');
}

const studentResultReleaseS01 = documents.get(path.join(docsRoot, 'g4-imp-06-s01-student-result-release.html')).content;
for (const marker of [
  'G4 · Student Results · Implementation 06 · Step 01',
  'G4-IMP-06-S01 완료',
  'C43 최초 공개 활성',
  'IMP-06 1/5',
  'POST /oj-ai/v1/submission-evaluations/{evaluation_id}/commands/release',
  'oj_ai.student_release_projection',
  'oj_ai_submission_release_revisions',
  'publish_feedback',
  'feedback_not_approved',
  'release_source_stale',
  'release_projection_invalid',
  'release_already_current',
  'check:submission-result-release-g4',
  'API-AUD-2026-08-24-R74-OBSERVED',
  'G4-IMP-06-S02 Q33 학생 본인 결과 조회',
  '플랫폼 API 변경 없음',
]) {
  if (!studentResultReleaseS01.includes(marker)) {
    fail('g4-imp-06-s01-student-result-release.html: missing implementation marker ' + marker);
  }
}
if (studentResultReleaseS01.includes('$wpdb->prefix') || studentResultReleaseS01.includes('wp_oj_ai_')) {
  fail('g4-imp-06-s01-student-result-release.html: forbidden table prefix found');
}
const studentResultQueryS02 = documents.get(path.join(docsRoot, 'g4-imp-06-s02-student-result-query.html')).content;
for (const marker of [
  'G4 · Student Results · Implementation 06 · Step 02',
  'G4-IMP-06-S02 Q33 완료',
  'IMP-06 2/5',
  'GET /oj-ai/v1/me/results/{release_id}',
  'Object_Scope::TYPE_RELEASE',
  'result_updating',
  'result_withdrawn',
  'oj_ai_submission_release_revisions',
  'check:submission-result-query-g4',
  'API-AUD-2026-08-24-R75-OBSERVED',
  'G4-IMP-06-S03 C44 공개 회수',
  '플랫폼 API 변경 없음',
]) {
  if (!studentResultQueryS02.includes(marker)) {
    fail('g4-imp-06-s02-student-result-query.html: missing implementation marker ' + marker);
  }
}
if (studentResultQueryS02.includes('$wpdb->prefix') || studentResultQueryS02.includes('wp_oj_ai_')) {
  fail('g4-imp-06-s02-student-result-query.html: forbidden table prefix found');
}
if (!studentResultQueryS02.includes('다른 학생 결과, 과거 공개본, 미공개 결과')) {
  fail('g4-imp-06-s02-student-result-query.html: missing owner-only not-found contract');
}
if (!studentResultQueryS02.includes('C52 PDF action은 아직 없음')) {
  fail('g4-imp-06-s02-student-result-query.html: missing deferred result PDF boundary');
}

const studentResultWithdrawalS03 = documents.get(path.join(docsRoot, 'g4-imp-06-s03-student-result-withdrawal.html')).content;
for (const marker of [
  'G4 · Student Results · Implementation 06 · Step 03',
  'G4-IMP-06-S03 C44 완료',
  'IMP-06 3/5',
  'POST /oj-ai/v1/submission-evaluations/{evaluation_id}/commands/withdraw-release',
  'withdraw_release',
  'data_correction',
  'publication_error',
  'privacy_request',
  'oj_ai_submission_release_revisions',
  'check:submission-result-withdrawal-g4',
  'API-AUD-2026-08-24-R76-OBSERVED',
  'G4-IMP-06-S04 C43 정정·stale 처리',
  '플랫폼 API 변경 없음',
]) {
  if (!studentResultWithdrawalS03.includes(marker)) {
    fail('g4-imp-06-s03-student-result-withdrawal.html: missing implementation marker ' + marker);
  }
}
if (studentResultWithdrawalS03.includes('$wpdb->prefix') || studentResultWithdrawalS03.includes('wp_oj_ai_')) {
  fail('g4-imp-06-s03-student-result-withdrawal.html: forbidden table prefix found');
}
if (!studentResultWithdrawalS03.includes('사유 note는 교사용 이력에만 남고 학생에게는 공통 회수 안내')) {
  fail('g4-imp-06-s03-student-result-withdrawal.html: missing private withdrawal-note boundary');

}

const studentResultCorrectionS04 = documents.get(path.join(docsRoot, 'g4-imp-06-s04-student-result-correction.html')).content;
for (const marker of [
  'G4 · Student Results · Implementation 06 · Step 04',
  'G4-IMP-06-S04 C43 완료',
  'IMP-06 4/5',
  'POST /oj-ai/v1/submission-evaluations/{evaluation_id}/commands/release',
  'mode=correction',
  'previous_release_id',
  'data_correction|publication_error|privacy_request|other',
  'oj_ai_submission_release_revisions',
  'submission_result.corrected',
  'check:submission-result-correction-g4',
  'API-AUD-2026-08-24-R77-OBSERVED',
  'G4-IMP-06-S05 학생 결과 화면·통합 동결',
  '플랫폼 API 변경 없음',
]) {
  if (!studentResultCorrectionS04.includes(marker)) {
    fail('g4-imp-06-s04-student-result-correction.html: missing implementation marker ' + marker);
  }
}
if (studentResultCorrectionS04.includes('$wpdb->prefix') || studentResultCorrectionS04.includes('wp_oj_ai_')) {
  fail('g4-imp-06-s04-student-result-correction.html: forbidden table prefix found');
}
if (!studentResultCorrectionS04.includes('이전 approved feedback은 재사용하지 않는다')) {
  fail('g4-imp-06-s04-student-result-correction.html: missing official-review feedback isolation boundary');
}

const studentResultUiS05 = documents.get(path.join(docsRoot, 'g4-imp-06-s05-student-result-ui-integration.html')).content;
for (const marker of [
  'G4 · Student Results · Implementation 06 · Step 05',
  'G4-IMP-06-S05 완료',
  'IMP-06 5/5 완료',
  'Student_Result_Page_Handler',
  'GET /oj-ai/v1/me/results/{release_id}',
  'oj_ai.student_release_projection',
  'result_updating',
  'result_withdrawn',
  '권한 회수 즉시 본문 제거',
  'student-result-released-desktop.png',
  'student-result-released-mobile.png',
  'check:submission-result-ui-g4',
  'API-AUD-2026-08-24-R78-OBSERVED',
  'G4-IMP-07-S01 학생 결과 PDF 공개 projection',
  '플랫폼 API 변경 없음',
]) {
  if (!studentResultUiS05.includes(marker)) {
    fail('g4-imp-06-s05-student-result-ui-integration.html: missing implementation marker ' + marker);
  }
}
if (studentResultUiS05.includes('$wpdb->prefix') || studentResultUiS05.includes('wp_oj_ai_')) {
  fail('g4-imp-06-s05-student-result-ui-integration.html: forbidden table prefix found');
}
if (!studentResultUiS05.includes('Page handler는 결과 본문을 읽지 않는다')) {
  fail('g4-imp-06-s05-student-result-ui-integration.html: missing shell-only Page boundary');
}

const resultPrintProjectionS01 = documents.get(path.join(docsRoot, 'g4-imp-07-s01-result-print-projection.html')).content;
for (const marker of [
  'G4 · Student Results · Implementation 07 · Step 01',
  'G4-IMP-07-S01 완료',
  'IMP-07 1/4 진행',
  'Read_Own_Submission_Result',
  'Q33_Own_Result_Pdf_Source_Query',
  'Build_Result_Print_Projection',
  'result_print.v1',
  'oj_ai.result_print@1.0.0',
  'submission_release',
  '<code>released|corrected</code>',
  'result_pdf_source_stale',
  'check:result-print-projection-g4',
  'API-AUD-2026-08-24-R79-OBSERVED',
  '143개 설계 HTML + UI prototype 1개',
  'G4-IMP-07-S02',
  '플랫폼 API 변경 없음',
]) {
  if (!resultPrintProjectionS01.includes(marker)) {
    fail('g4-imp-07-s01-result-print-projection.html: missing implementation marker ' + marker);
  }
}
if (resultPrintProjectionS01.includes('$wpdb->prefix') || resultPrintProjectionS01.includes('wp_oj_ai_')) {
  fail('g4-imp-07-s01-result-print-projection.html: forbidden table prefix found');
}
if (!resultPrintProjectionS01.includes('C52 route, queue, artifact, PDF bytes를 아직 추가하지 않는다') && !resultPrintProjectionS01.includes('C52 route·queue·artifact를 아직 등록하지 않아')) {
  fail('g4-imp-07-s01-result-print-projection.html: missing S01/S02 responsibility boundary');
}
const resultPdfJobS02 = documents.get(path.join(docsRoot, 'g4-imp-07-s02-result-pdf-job.html')).content;
for (const marker of [
  'G4 · Student Results · Implementation 07 · Step 02',
  'G4-IMP-07-S02 완료',
  '후속 G4-IMP-07-S03 완료',
  'IMP-07 3/4 진행',
  'POST /oj-ai/v1/me/results/{release_id}/commands/export-pdf',
  'oj_ai.result_pdf_request@1.0.0',
  'result_print.v1',
  'oj_ai.student_release_projection@1.0.0',
  '300초 lease',
  '24시간 보존',
  'oj_ai_export_jobs',
  'oj_ai_pdf_artifacts',
  'oj_ai_private_files',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'Q23·Q24',
  'self_release',
  'check:result-pdf-job-g4',
  'result-pdf-storage-g4-imp-07-s02.php',
  '24개 runtime·contract',
  'check:result-pdf-access-g4',
  'API-AUD-2026-08-24-R80-OBSERVED',
  '144개 설계 HTML + UI prototype 1개',
  'G4-IMP-07-S03',
  'G4-IMP-07-S04',
  '플랫폼 API 변경 없음',
]) {
  if (!resultPdfJobS02.includes(marker)) {
    fail('g4-imp-07-s02-result-pdf-job.html: missing implementation marker ' + marker);
  }
}
if (resultPdfJobS02.includes('$wpdb->prefix') || resultPdfJobS02.includes('wp_oj_ai_')) {
  fail('g4-imp-07-s02-result-pdf-job.html: forbidden table prefix found');
}
if (!resultPdfJobS02.includes('렌더링 도중 결과가 회수·정정되면 두 번째 재검사에서 실패한다')) {
  fail('g4-imp-07-s02-result-pdf-job.html: missing post-render revocation boundary');
}
const resultPdfAccessS03 = documents.get(path.join(docsRoot, 'g4-imp-07-s03-result-pdf-access-freeze.html')).content;
for (const marker of [
  'G4 · Student Results · Implementation 07 · Step 03',
  'G4-IMP-07-S03 완료',
  'IMP-07 3/4 진행',
  'result_pdf',
  'self_release',
  'Q23·Q24',
  '전송 직전 재인가',
  'LOCK_SH',
  'SHA-256',
  'tests/Contract/result-pdf-access-g4-imp-07-s03.php',
  'check:result-pdf-access-g4',
  'API-AUD-2026-08-24-R81-OBSERVED',
  '145개 설계 HTML + UI prototype 1개',
  'G4-IMP-07-S04',
  '플랫폼 API 변경 없음',
]) {
  if (!resultPdfAccessS03.includes(marker)) {
    fail('g4-imp-07-s03-result-pdf-access-freeze.html: missing implementation marker ' + marker);
  }
}
if (resultPdfAccessS03.includes('$wpdb->prefix') || resultPdfAccessS03.includes('wp_oj_ai_')) {
  fail('g4-imp-07-s03-result-pdf-access-freeze.html: forbidden table prefix found');
}
if (!resultPdfAccessS03.includes('실제 body 전송 직전에 같은 actor·artifact ID로 Q24를 다시 실행한다')) {
  fail('g4-imp-07-s03-result-pdf-access-freeze.html: missing pre-stream reauthorization boundary');
}
const resultPdfUiS04 = documents.get(path.join(docsRoot, 'g4-imp-07-s04-result-pdf-ui-integration.html')).content;
for (const marker of [
  'G4 · Student Results · Implementation 07 · Step 04',
  'G4-IMP-07-S04 완료',
  'IMP-07 4/4 완료',
  'G4 7/7 완료',
  'PDF 만들기',
  'PDF 준비 중',
  'PDF 다운로드',
  'PDF가 만료되었습니다.',
  'POST /oj-ai/v1/me/results/{release_id}/commands/export-pdf',
  'GET /oj-ai/v1/export-jobs/{job_id}',
  'GET /oj-ai/v1/artifacts/{artifact_id}/download',
  'Q33→C52→Q23→Q24',
  'desktop',
  'mobile',
  '5 page',
  'Noto Sans KR',
  'tests/Contract/result-pdf-ui-g4-imp-07-s04.php',
  'check:result-pdf-ui-g4',
  'API-AUD-2026-08-24-R82-OBSERVED',
  'G5-IMP-01-S01',
  '플랫폼 API 변경 없음',
]) {
  if (!resultPdfUiS04.includes(marker)) {
    fail('g4-imp-07-s04-result-pdf-ui-integration.html: missing implementation marker ' + marker);
  }
}
if (resultPdfUiS04.includes('$wpdb->prefix') || resultPdfUiS04.includes('wp_oj_ai_')) {
  fail('g4-imp-07-s04-result-pdf-ui-integration.html: forbidden table prefix found');
}
if (!resultPdfUiS04.includes('job ID와 artifact ID는 현재 화면 메모리에만 두며')) {
  fail('g4-imp-07-s04-result-pdf-ui-integration.html: missing browser capability storage boundary');
}
const worksheetReportListS01 = documents.get(path.join(docsRoot, 'g5-imp-01-s01-worksheet-report-list.html')).content;
for (const marker of [
  'G5-IMP-01-S01',
  'GET /oj-ai/v1/reports',
  'oj_ai.reporting_metrics@1.0.0',
  'Worksheet_Report_Source_Query_Port_Interface',
  'Assignment_Progress_Source_Query_Port_Interface',
  'Official_Assessment_Source_Query_Port_Interface',
  'exact Context',
  'signed cursor',
  '5명 미만',
  'source_manifest_hash',
  'projection_hash',
  'check:worksheet-report-list-g5',
  'G5-IMP-01-S02',
  '플랫폼 API 변경 0',
]) {
  if (!worksheetReportListS01.includes(marker)) {
    fail('g5-imp-01-s01-worksheet-report-list.html: missing implementation marker ' + marker);
  }
}
if (worksheetReportListS01.includes('$wpdb->prefix') || worksheetReportListS01.includes('wp_oj_ai_')) {
  fail('g5-imp-01-s01-worksheet-report-list.html: forbidden table prefix found');
}

const worksheetReportDetailS02 = documents.get(path.join(docsRoot, 'g5-imp-01-s02-worksheet-report-detail.html')).content;
for (const marker of [
  'G5-IMP-01-S02',
  'GET /oj-ai/v1/reports/worksheets/{worksheet_id}',
  'assignment_id',
  'assignments',
  'students',
  'oj_ai.reporting_metrics@1.0.0',
  'VIEW_REPORTS',
  'display_name',
  'student_visible',
  'source_manifest_hash',
  'signed cursor',
  '5명 미만',
  'check:worksheet-report-detail-g5',
  'G5-IMP-01-S03',
  '플랫폼 API 변경 0',
]) {
  if (!worksheetReportDetailS02.includes(marker)) {
    fail('g5-imp-01-s02-worksheet-report-detail.html: missing implementation marker ' + marker);
  }
}
if (worksheetReportDetailS02.includes('$wpdb->prefix') || worksheetReportDetailS02.includes('wp_oj_ai_')) {
  fail('g5-imp-01-s02-worksheet-report-detail.html: forbidden table prefix found');
}

const worksheetReportFilterS03 = documents.get(path.join(docsRoot, 'g5-imp-01-s03-report-filter-denominator.html')).content;
for (const marker of [
  'G5-IMP-01-S03',
  'oj_ai.report_filter@1.0.0',
  'oj_ai.report_denominator_contract@1.0.0',
  'period_from',
  'period_to',
  '[from,to)',
  'assignment_states',
  'submission_states',
  'not_started',
  'in_progress',
  'official',
  'filter-bound',
  '분모·포함·제외 사유',
  'check:worksheet-report-filter-g5',
  'G5-IMP-01-S04',
  '플랫폼 API 변경 0',
]) {
  if (!worksheetReportFilterS03.includes(marker)) {
    fail('g5-imp-01-s03-report-filter-denominator.html: missing implementation marker ' + marker);
  }
}
if (worksheetReportFilterS03.includes('$wpdb->prefix') || worksheetReportFilterS03.includes('wp_oj_ai_')) {
  fail('g5-imp-01-s03-report-filter-denominator.html: forbidden table prefix found');
}

const reportDashboardS04 = documents.get(path.join(docsRoot, 'g5-imp-01-s04-report-dashboard.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 01 · Step 04',
  'G5-IMP-01-S04 완료',
  'IMP-01 4/4 완료',
  'G5 2/8 완료',
  'Console Menu 5개',
  'GET /oj-ai/v1/reports',
  'VIEW_REPORTS',
  'period_to',
  '[from,to)',
  '401·403',
  'textContent',
  'report-dashboard-desktop.png',
  'check:report-dashboard-g5',
  'G5-IMP-02-S01',
  '플랫폼 API 변경 0',
]) {
  if (!reportDashboardS04.includes(marker)) {
    fail('g5-imp-01-s04-report-dashboard.html: missing implementation marker ' + marker);
  }
}
if (reportDashboardS04.includes('$wpdb->prefix') || reportDashboardS04.includes('wp_oj_ai_')) {
  fail('g5-imp-01-s04-report-dashboard.html: forbidden table prefix found');
}
if (!reportDashboardS04.includes('학생 표시 이름과 보고서 응답 본문은 저장하지 않는다')) {
  fail('g5-imp-01-s04-report-dashboard.html: missing history privacy boundary');
}

const reportSnapshotS01 = documents.get(path.join(docsRoot, 'g5-imp-02-s01-report-snapshot-create.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 02 · Step 01',
  'G5-IMP-02-S01 완료',
  'IMP-02 1/4 진행',
  'POST /oj-ai/v1/reports/commands/create-snapshot',
  'oj_ai.report_snapshot_request@1.0.0',
  'oj_ai.report_filter@1.0.0',
  'oj_ai.report_source_manifest@1.0.0',
  'oj_ai.report_projection@1.0.0',
  'oj_ai.report_snapshot_identity@1.0.0',
  'oj_ai_report_snapshots',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  '10,000',
  'report_source_stale',
  'durable replay',
  'check:report-snapshot-g5',
  'Page 9·GET 38·POST 79',
  'G5-IMP-02-S02',
  '플랫폼 API 변경 0',
]) {
  if (!reportSnapshotS01.includes(marker)) {
    fail('g5-imp-02-s01-report-snapshot-create.html: missing implementation marker ' + marker);
  }
}
if (reportSnapshotS01.includes('$wpdb->prefix') || reportSnapshotS01.includes('wp_oj_ai_')) {
  fail('g5-imp-02-s01-report-snapshot-create.html: forbidden table prefix found');
}

const reportSnapshotS02 = documents.get(path.join(docsRoot, 'g5-imp-02-s02-report-snapshot-query.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 02 · Step 02',
  'G5-IMP-02-S02 완료',
  'IMP-02 2/4 진행',
  'GET /oj-ai/v1/report-snapshots/{snapshot_id}',
  'oj_ai.report_filter@1.0.0',
  'oj_ai.report_source_manifest@1.0.0',
  'oj_ai.report_projection@1.0.0',
  'oj_ai.report_snapshot_identity@1.0.0',
  'source_manifest_hash',
  '현재 Context',
  '모든 활동지 source',
  'literal <code>oj_ai_report_snapshots</code> SELECT 전용 접근',
  'report_snapshot_not_found',
  'check:report-snapshot-query-g5',
  'Page 9·GET 38·POST 79',
  'G5-IMP-02-S03',
  '플랫폼 API 변경 0',
]) {
  if (!reportSnapshotS02.includes(marker)) {
    fail('g5-imp-02-s02-report-snapshot-query.html: missing implementation marker ' + marker);
  }
}
if (reportSnapshotS02.includes('$wpdb->prefix') || reportSnapshotS02.includes('wp_oj_ai_')) {
  fail('g5-imp-02-s02-report-snapshot-query.html: forbidden table prefix found');
}

const reportSourceManifestS03 = documents.get(path.join(docsRoot, 'g5-imp-02-s03-report-source-manifest.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 02 · Step 03',
  'G5-IMP-02-S03 완료',
  'IMP-02 3/4 진행',
  'read_source_manifest()',
  'oj_ai.report_source_manifest@1.0.0',
  'source_manifest_json',
  'source_manifest_hash',
  'oj_ai.report_snapshot_identity@1.0.0',
  'authorize_and_validate()',
  '모든 고유 활동지의 view 권한',
  'Q22의 공개 allowlist',
  'check:report-source-manifest-g5',
  'REST 변화 0',
  'Page 9·GET 38·POST 79',
  'G5-IMP-02-S04',
  'Platform API change: 0',
]) {
  if (!reportSourceManifestS03.includes(marker)) {
    fail('g5-imp-02-s03-report-source-manifest.html: missing implementation marker ' + marker);
  }
}
if (reportSourceManifestS03.includes('$wpdb->prefix') || reportSourceManifestS03.includes('wp_oj_ai_')) {
  fail('g5-imp-02-s03-report-source-manifest.html: forbidden table prefix found');
}

const reportSnapshotIntegrationS04 = documents.get(path.join(docsRoot, 'g5-imp-02-s04-report-snapshot-integration-freeze.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 02 · Step 04',
  'G5-IMP-02-S04 완료',
  'IMP-02 4/4 완료',
  'report_source_stale',
  '현재 권한 철회',
  '저장 무결성',
  'durable receipt',
  'check:report-snapshot-integration-g5',
  'REST·DB 변화 0',
  'Page 9·GET 38·POST 79 유지',
  'G5-IMP-03-S01',
  'Platform API change: 0',
]) {
  if (!reportSnapshotIntegrationS04.includes(marker)) {
    fail('g5-imp-02-s04-report-snapshot-integration-freeze.html: missing implementation marker ' + marker);
  }
}
if (reportSnapshotIntegrationS04.includes('$wpdb->prefix') || reportSnapshotIntegrationS04.includes('wp_oj_ai_')) {
  fail('g5-imp-02-s04-report-snapshot-integration-freeze.html: forbidden table prefix found');
}

const reportExportEnqueueS01 = documents.get(path.join(docsRoot, 'g5-imp-03-s01-report-export-enqueue.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 03 · Step 01',
  'G5-IMP-03-S01 완료',
  'IMP-03 1/6 진행',
  'POST /oj-ai/v1/report-snapshots/{snapshot_id}/commands/export-csv',
  'POST /oj-ai/v1/report-snapshots/{snapshot_id}/commands/export-pdf',
  'report_csv.v1',
  'report_print.v1',
  'oj_ai_export_jobs',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  '보고서 생성 당시 Context revision',
  '현재 Context revision',
  'check:report-export-enqueue-g5',
  'Page 9·GET 38·POST 79',
  'G5-IMP-03-S02',
  'Platform API change: 0',
]) {
  if (!reportExportEnqueueS01.includes(marker)) {
    fail('g5-imp-03-s01-report-export-enqueue.html: missing implementation marker ' + marker);
  }
}
if (reportExportEnqueueS01.includes('$wpdb->prefix') || reportExportEnqueueS01.includes('wp_oj_ai_')) {
  fail('g5-imp-03-s01-report-export-enqueue.html: forbidden table prefix found');
}

const submissionBundleEnqueueS02 = documents.get(path.join(docsRoot, 'g5-imp-03-s02-submission-bundle-enqueue.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 03 · Step 02',
  'G5-IMP-03-S02 완료',
  'IMP-03 2/6 진행',
  'POST /oj-ai/v1/assignments/{assignment_id}/commands/export-submissions-zip',
  'submission_bundle.v1',
  'submissions/0001-{target_id}.pdf',
  'attachments/0001-{target_id}/{attachment_id}.pdf',
  '500 files',
  '500 MiB',
  'oj_ai_export_jobs',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'report_snapshot_id=NULL',
  'source <code>assignment</code>',
  'check:submission-bundle-enqueue-g5',
  'Page 9·GET 38·POST 79',
  'G5-IMP-03-S03',
  'Platform API change: 0',
]) {
  if (!submissionBundleEnqueueS02.includes(marker)) {
    fail('g5-imp-03-s02-submission-bundle-enqueue.html: missing implementation marker ' + marker);
  }
}
if (submissionBundleEnqueueS02.includes('$wpdb->prefix') || submissionBundleEnqueueS02.includes('wp_oj_ai_')) {
  fail('g5-imp-03-s02-submission-bundle-enqueue.html: forbidden table prefix found');
}

const reportJsonPackageEnqueueS03 = documents.get(path.join(docsRoot, 'g5-imp-03-s03-report-json-package-enqueue.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 03 · Step 03',
  'G5-IMP-03-S03 완료',
  'IMP-03 3/6 진행',
  'POST /oj-ai/v1/report-snapshots/{snapshot_id}/commands/export-json-package',
  'report_json_package.v1',
  'manifest.json',
  'report.json',
  'checksums.sha256',
  'source manifest hash',
  '50,000 rows',
  '50 MiB',
  'oj_ai_export_jobs',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'report snapshot FK',
  'artifact kind <code>report_json_package</code>',
  'check:report-json-package-enqueue-g5',
  'Page 9·GET 38·POST 79',
  'G5-IMP-03-S04',
  'Platform API change: 0',
]) {
  if (!reportJsonPackageEnqueueS03.includes(marker)) {
    fail('g5-imp-03-s03-report-json-package-enqueue.html: missing implementation marker ' + marker);
  }
}
if (reportJsonPackageEnqueueS03.includes('$wpdb->prefix') || reportJsonPackageEnqueueS03.includes('wp_oj_ai_')) {
  fail('g5-imp-03-s03-report-json-package-enqueue.html: forbidden table prefix found');
}

const reportExportJobDownloadS04 = documents.get(path.join(docsRoot, 'g5-imp-03-s04-report-export-job-download.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 03 · Step 04',
  'G5-IMP-03-S04 완료',
  'IMP-03 4/6 진행',
  'Q23·Q24 활성',
  'report_csv',
  'report_pdf',
  'report_json_package',
  'submission_bundle',
  '300초 lease',
  '생성 전후 두 번 확인',
  'oj_ai_export_jobs',
  'oj_ai_pdf_artifacts',
  'oj_ai_private_files',
  'context_reporter',
  'context_staff',
  'check:report-export-job-download-g5',
  'Platform API change: 0',
  'G5-IMP-03-S05',
]) {
  if (!reportExportJobDownloadS04.includes(marker)) {
    fail('g5-imp-03-s04-report-export-job-download.html: missing implementation marker ' + marker);
  }
}
if (reportExportJobDownloadS04.includes('$wpdb->prefix') || reportExportJobDownloadS04.includes('wp_oj_ai_')) {
  fail('g5-imp-03-s04-report-export-job-download.html: forbidden table prefix found');
}
const reportExportControlS05 = documents.get(path.join(docsRoot, 'g5-imp-03-s05-report-export-control.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 03 · Step 05',
  'G5-IMP-03-S05 완료',
  'IMP-03 5/6 진행',
  'C53·C54 활성',
  'POST /export-jobs/{job_id}/commands/cancel',
  'POST /export-jobs/{job_id}/commands/retry',
  'expected_state=queued',
  'expected_state=failed',
  'queued|running',
  'oj_ai_export_jobs',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'export_job.cancelled',
  'export_job.retry_queued',
  'export_job.retry_reused',
  'check:report-export-control-g5',
  'schema 1.0.2',
  'Platform API change: 0',
  'G5-IMP-03-S06',
]) {
  if (!reportExportControlS05.includes(marker)) {
    fail('g5-imp-03-s05-report-export-control.html: missing implementation marker ' + marker);
  }
}
if (reportExportControlS05.includes('$wpdb->prefix') || reportExportControlS05.includes('wp_oj_ai_')) {
  fail('g5-imp-03-s05-report-export-control.html: forbidden table prefix found');
}
const reportExportIntegrationS06 = documents.get(path.join(docsRoot, 'g5-imp-03-s06-report-export-integration-freeze.html')).content;
for (const marker of [
  'G5 · Reports · Implementation 03 · Step 06',
  'G5-IMP-03-S06 완료',
  'IMP-03 6/6 완료',
  'G5 4/8 완료',
  'CSV·PDF·JSON ZIP·학생 결과물 ZIP',
  'Q24 preflight',
  'stream 재인가',
  '0 byte 규칙',
  'cancelled',
  'failed',
  'queued',
  'check:report-export-integration-g5',
  'report-export-integration-freeze-g5-imp-03-s06.php',
  'schema 1.0.2',
  'literal <code>oj_ai_*</code>',
  'Platform API change: 0',
  'G5-IMP-05-S01',
]) {
  if (!reportExportIntegrationS06.includes(marker)) {
    fail('g5-imp-03-s06-report-export-integration-freeze.html: missing implementation marker ' + marker);
  }
}
if (reportExportIntegrationS06.includes('$wpdb->prefix') || reportExportIntegrationS06.includes('wp_oj_ai_')) {
  fail('g5-imp-03-s06-report-export-integration-freeze.html: forbidden table prefix found');
}
const operationsDiagnosticsS01 = documents.get(path.join(docsRoot, 'g5-imp-05-s01-bridge-schema-queue-diagnostics.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 05 · Step 01',
  'G5-IMP-05-S01 완료',
  'IMP-05 1/4',
  'G5 4/8 완료',
  'oj_ai.diagnostics@1.0.0',
  'Bridge ready 이후',
  'queue_stale_lease_detected',
  'queue_failure_threshold_reached',
  'queue_backlog_delayed',
  '활동지 평가·학생 제출 평가·피드백·특기사항',
  '사용 준비됨',
  '확인 필요',
  '사용할 수 없음',
  'check:operations-diagnostics-g5',
  'operations-diagnostics-g5-imp-05-s01.php',
  'schema 1.0.2',
  'literal <code>oj_ai_*</code>',
  'Platform API change: 0',
  'G5-IMP-05-S02',
]) {
  if (!operationsDiagnosticsS01.includes(marker)) {
    fail('g5-imp-05-s01-bridge-schema-queue-diagnostics.html: missing implementation marker ' + marker);
  }
}
if (operationsDiagnosticsS01.includes('$wpdb->prefix') || operationsDiagnosticsS01.includes('wp_oj_ai_')) {
  fail('g5-imp-05-s01-bridge-schema-queue-diagnostics.html: forbidden table prefix found');
}
const operationsAiRoutingS02 = documents.get(path.join(docsRoot, 'g5-imp-05-s02-ai-routing-diagnostics.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 05 · Step 02',
  'G5-IMP-05-S02 완료',
  'IMP-05 2/4',
  'G5 4/8 완료',
  'Q28 4-check',
  'canRoute()',
  'snapshot()',
  'execute() 0',
  '연결됨',
  '설정 필요',
  '확인 불가',
  '다시 확인 필요',
  'routing revision',
  '실행 연결 확인 필요',
  'open_platform_operations',
  '화면 소비는 <code>status</code>만',
  'operations-ai-routing-g5-imp-05-s02.php',
  'check-operations-ai-routing-g5-imp-05-s02.ps1',
  'literal <code>oj_ai_*</code>',
  'Platform API change: 0',
  'G5-IMP-05-S03',
]) {
  if (!operationsAiRoutingS02.includes(marker)) {
    fail('g5-imp-05-s02-ai-routing-diagnostics.html: missing implementation marker ' + marker);
  }
}
if (operationsAiRoutingS02.includes('$wpdb->prefix') || operationsAiRoutingS02.includes('wp_oj_ai_')) {
  fail('g5-imp-05-s02-ai-routing-diagnostics.html: forbidden table prefix found');
}
const operationsStorageS03 = documents.get(path.join(docsRoot, 'g5-imp-05-s03-storage-secret-diagnostics.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 05 · Step 03',
  'G5-IMP-05-S03 완료',
  'IMP-05 3/4',
  'G5 4/8 완료',
  'Q28 5-check',
  'oj_ai_private_files',
  'public web root',
  'scan·cleanup',
  'bounded 메타데이터 집계',
  'quarantined → ready|rejected → expired|purged',
  'resume_storage_jobs',
  'check_private_storage',
  'operations-storage-g5-imp-05-s03.php',
  'check-operations-storage-g5-imp-05-s03.ps1',
  'Platform API change: 0',
  'G5-IMP-05-S04',
]) {
  if (!operationsStorageS03.includes(marker)) {
    fail('g5-imp-05-s03-storage-secret-diagnostics.html: missing implementation marker ' + marker);
  }
}
if (operationsStorageS03.includes('$wpdb->prefix') || operationsStorageS03.includes('wp_oj_ai_')) {
  fail('g5-imp-05-s03-storage-secret-diagnostics.html: forbidden table prefix found');
}
const operationsDiagnosticsUiS04 = documents.get(path.join(docsRoot, 'g5-imp-05-s04-operations-ui-regression.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 05 · Step 04',
  'G5-IMP-05-S04 완료',
  'IMP-05 4/4',
  'G5 5/8 완료',
  'Q28 5-check',
  '애드온 실행',
  '데이터 구조',
  '작업 처리',
  '파일 보관',
  'AI 작업 배정',
  'code',
  'details',
  'issues',
  'path',
  'metrics',
  'tasks',
  'operations-diagnostics-ui-g5-imp-05-s04.php',
  'operations-diagnostics-ui-g5-imp-05-s04.mjs',
  'e2e-operations-diagnostics.mjs',
  '15 capability·9 Page·2 menu·5 asset·2 AI task',
  'AddonAssetBundleDefinitionV1',
  'CSS·JS 각 최대 8개',
  'asset bundle 5개',
  'Platform API change: 0',
  'G5-IMP-06-S01',
]) {
  if (!operationsDiagnosticsUiS04.includes(marker)) {
    fail('g5-imp-05-s04-operations-ui-regression.html: missing implementation marker ' + marker);
  }
}
const freshInstallS01 = documents.get(path.join(docsRoot, 'g5-imp-06-s01-fresh-install.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 06 · Step 01',
  'G5-IMP-06-S01 완료',
  'IMP-06 1/5',
  '45 table',
  'registration_pending',
  'dom|fileinfo|intl|mbstring|mysqli|openssl|xmlwriter|zip',
  'platform_missing',
  'addon_api_missing',
  'multisite_unsupported',
  'SELECT-only',
  'check:fresh-install-g5',
  'OJ_AI_PRIVATE_STORAGE_DIR',
  'HOME',
  'USERPROFILE',
  'Platform API change: 0',
  'G5-IMP-06-S02',
]) {
  if (!freshInstallS01.includes(marker)) {
    fail('g5-imp-06-s01-fresh-install.html: missing implementation marker ' + marker);
  }
}
if (freshInstallS01.includes('$wpdb->prefix') || freshInstallS01.includes('wp_oj_ai_')) {
  fail('g5-imp-06-s01-fresh-install.html: forbidden table prefix found');
}
const sequentialUpgradeS02 = documents.get(path.join(docsRoot, 'g5-imp-06-s02-sequential-upgrade.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 06 · Step 02',
  'G5-IMP-06-S02 완료',
  'IMP-06 2/5',
  'schema 1.0.2',
  '지원 source 1.0.0|1.0.1',
  '1.0.0 → 1.0.1 → 1.0.2',
  '1.0.1 → 1.0.2',
  'already_ready',
  'schema_preflight_blocked',
  'lock·DDL·원장 write 0건',
  'down migration 0',
  'check:sequential-upgrade-g5',
  'Platform API change: 0',
  'G5-IMP-06-S03',
]) {
  if (!sequentialUpgradeS02.includes(marker)) {
    fail('g5-imp-06-s02-sequential-upgrade.html: missing implementation marker ' + marker);
  }
}
const failureRestartS03 = documents.get(path.join(docsRoot, 'g5-imp-06-s03-failure-restart.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 06 · Step 03',
  'G5-IMP-06-S03 완료',
  'IMP-06 3/5',
  'maintenance_required',
  'repair_required',
  'schema_checkpoint_hash_mismatch',
  'schema_checkpoint_json_noncanonical',
  'schema_migration_ledger_invalid',
  'CREATE·ALTER 재개',
  'check:failure-restart-g5',
  'Platform API change: 0',
  'G5-IMP-06-S04',
]) {
  if (!failureRestartS03.includes(marker)) {
    fail('g5-imp-06-s03-failure-restart.html: missing implementation marker ' + marker);
  }
}
if (failureRestartS03.includes('$wpdb->prefix')) {
  fail('g5-imp-06-s03-failure-restart.html: unescaped WordPress prefix expression found');
}

const forwardRepairS04 = documents.get(path.join(docsRoot, 'g5-imp-06-s04-forward-repair.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 06 · Step 04',
  'G5-IMP-06-S04 완료',
  'IMP-06 4/5',
  'POST /operations/schema/commands/repair',
  'operations.schema.repair.view',
  'oj_access_console',
  'manage_migrations',
  '24시간',
  'schema_repair_converged',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'check:forward-repair-g5',
  'desktop 1280×900',
  'mobile 390×844',
  'Platform API change: 0',
  'G5-IMP-06-S05',
]) {
  if (!forwardRepairS04.includes(marker)) {
    fail('g5-imp-06-s04-forward-repair.html: missing implementation marker ' + marker);
  }
}
if (forwardRepairS04.includes('$wpdb->prefix') || forwardRepairS04.includes('wp_oj_ai_')) {
  fail('g5-imp-06-s04-forward-repair.html: forbidden table prefix found');
}

const dataPreservationS05 = documents.get(path.join(docsRoot, 'g5-imp-06-s05-data-preservation.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 06 · Step 05',
  'G5-IMP-06-S05 완료',
  'IMP-06 5/5',
  '업무 row',
  '평가 설정',
  'private file',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'check:data-preservation-g5',
  'CREATE TABLE',
  'ALTER TABLE',
  'Runtime 변경 0건',
  'Platform API change: 0',
  'G5-IMP-07-S01',
]) {
  if (!dataPreservationS05.includes(marker)) {
    fail('g5-imp-06-s05-data-preservation.html: missing implementation marker ' + marker);
  }
}
if (dataPreservationS05.includes('$wpdb->prefix') || dataPreservationS05.includes('wp_oj_ai_')) {
  fail('g5-imp-06-s05-data-preservation.html: forbidden table prefix found');
}

const legacyMigrationPreviewS01 = documents.get(path.join(docsRoot, 'g5-imp-07-s01-legacy-migration-preview.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 07 · Step 01',
  'G5-IMP-07-S01 완료',
  'IMP-07 1/6',
  'oj_ai_dashboard_wpdb_prefixed@0.1.2',
  '정확한 35개 table·column 순서',
  'source_fingerprint',
  'oj_ai.legacy_migration_plan@1.0.0',
  'idempotency_key_hash',
  '1,800초',
  'POST /oj-ai/v1/migration-runs/commands/preview',
  'manage_migrations',
  'mutation_count=0',
  'ledger_mutation_count=0',
  'platform_write_count=0',
  'check:legacy-migration-preview-g5',
  'Platform API change: 0',
  'G5-IMP-07-S02',
]) {
  if (!legacyMigrationPreviewS01.includes(marker)) {
    fail('g5-imp-07-s01-legacy-migration-preview.html: missing implementation marker ' + marker);
  }
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (legacyMigrationPreviewS01.includes(forbidden)) {
    fail('g5-imp-07-s01-legacy-migration-preview.html: forbidden target prefix marker found ' + forbidden);
  }
}

const legacyMigrationApprovalS02 = documents.get(path.join(docsRoot, 'g5-imp-07-s02-legacy-migration-approval.html')).content;
for (const marker of [
  'G5 · Operations · Implementation 07 · Step 02',
  'G5-IMP-07-S02 완료',
  'IMP-07 2/6',
  'POST /oj-ai/v1/migration-runs/commands/approve',
  'preview_token',
  'manage_migrations',
  'oj_ai.legacy_migration_plan@1.0.0',
  'oj_ai_import_runs',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'oj_ai_import_items',
  '최초 201',
  'replay 200',
  'mapping_decisions',
  'mapping_requirements',
  'check:legacy-migration-approval-g5',
  'Platform API change: 0',
  'G5-IMP-07-S03',
]) {
  if (!legacyMigrationApprovalS02.includes(marker)) {
    fail('g5-imp-07-s02-legacy-migration-approval.html: missing implementation marker ' + marker);
  }
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (legacyMigrationApprovalS02.includes(forbidden)) {
    fail('g5-imp-07-s02-legacy-migration-approval.html: forbidden target prefix marker found ' + forbidden);
  }
}

const legacyOwnerMappingD3D2 = documents.get(path.join(docsRoot, 'g5-imp-07-s03-d3-d-owner-migration.html')).content;
for (const marker of [
  'G5-IMP-07-S03-D3-D2',
  'D3-D2 완료',
  'G5-IMP-07-S03-D3-D3',
  'D3-D3 완료',
  'G5-IMP-07-S03-D3-D4',
  'D3-D4 완료',
  'G5-IMP-07-S03-D3-D5',
  'D3-D5 완료',
  'G5-IMP-07-S03-D3-D6',
  'D3-D6 완료',
  'D3-D7 다음',
  'check:legacy-migration-publication-g5',
  'check:legacy-migration-assignment-g5',
  'check:legacy-migration-submission-g5',
  'check:legacy-migration-evaluation-g5',
  'oj_ai.legacy_migration_plan@1.2.0',
  'assignment_source_ref',
  'mapping_manifest',
  'mapping_decisions',
  'Context revision',
  'roster revision',
  'membership revision',
  'check:legacy-migration-mapping-g5',
  'Platform API change: 0',
]) {
  if (!legacyOwnerMappingD3D2.includes(marker)) {
    fail('g5-imp-07-s03-d3-d-owner-migration.html: missing D3-D5 marker ' + marker);
  }
}
for (const marker of [
  'id="d3-d5-contract"',
  'submission aggregate 구현 계약',
  'source·dependency 잠금',
  'revision source snapshot',
  'legacy response 변환',
  'attachment fail-closed 경계',
  'submission_attachment_manifest',
  'official_revision_public_id = null',
  'oj_ai_submissions',
  'oj_ai_submission_revisions',
  'Submission_Response_Validator',
  'D3-D7',
  'D3-D5 구현 결과',
  'locked control receipt',
  'check:legacy-migration-submission-g5',
  'document_hash',
  'Platform API change: 0',
]) {
  if (!legacyOwnerMappingD3D2.includes(marker)) fail('g5-imp-07-s03-d3-d-owner-migration.html: missing D3-D5 contract marker ' + marker);
}
for (const marker of [
  'id="d3-d6-result"',
  'G5-IMP-07-S03-D3-D6',
  'response:{public_id}',
  'raw provider response',
  'WordPress_Legacy_Migration_Evaluation_Writer',
  'check:legacy-migration-evaluation-g5',
  'D3-D 6/8',
  'Platform API change: 0',
]) {
  if (!legacyOwnerMappingD3D2.includes(marker)) fail('g5-imp-07-s03-d3-d-owner-migration.html: missing D3-D6 result marker ' + marker);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (legacyOwnerMappingD3D2.includes(forbidden)) {
    fail('g5-imp-07-s03-d3-d-owner-migration.html: forbidden target prefix marker found ' + forbidden);
  }
}

const legacyMigrationRunQueryS04 = await readFile(path.join(docsRoot, 'g5-imp-07-s04-migration-run-query.html'), 'utf8').catch(() => {
  fail('g5-imp-07-s04-migration-run-query.html: missing');
  return '';
});
for (const marker of [
  'G5-IMP-07-S04 완료',
  'A-DS08-Q29',
  'check:legacy-migration-run-query-g5',
  'IMP-07 4/6',
  'Platform API change: 0',
]) {
  if (!legacyMigrationRunQueryS04.includes(marker)) fail('g5-imp-07-s04-migration-run-query.html: missing implementation marker ' + marker);
}

const legacyMigrationRollbackS05 = await readFile(path.join(docsRoot, 'g5-imp-07-s05-legacy-migration-rollback.html'), 'utf8').catch(() => {
  fail('g5-imp-07-s05-legacy-migration-rollback.html: missing');
  return '';
});
for (const marker of [
  'G5-IMP-07-S05 완료',
  'A-DS08-C63',
  'check:legacy-migration-rollback-g5',
  'IMP-07 5/6',
  'g5-imp-07-s06-legacy-migration-integration-freeze.html',
  'Platform API change: 0',
]) {
  if (!legacyMigrationRollbackS05.includes(marker)) fail('g5-imp-07-s05-legacy-migration-rollback.html: missing implementation marker ' + marker);
}

const legacyMigrationIntegrationS06 = await readFile(path.join(docsRoot, 'g5-imp-07-s06-legacy-migration-integration-freeze.html'), 'utf8').catch(() => {
  fail('g5-imp-07-s06-legacy-migration-integration-freeze.html: missing');
  return '';
});
for (const marker of [
  'G5 · Operations · Implementation 07 · Step 06',
  'G5-IMP-07-S06 완료',
  'IMP-07 6/6 완료',
  'G5 7/8 완료',
  '1,800초',
  'SOURCE_STALE',
  'C62 실행·C63 보상 경쟁',
  'check:legacy-migration-integration-g5',
  '운영 DB 실행 0',
  'G5-IMP-08-S01',
  'Platform API change: 0',
]) {
  if (!legacyMigrationIntegrationS06.includes(marker)) fail('g5-imp-07-s06-legacy-migration-integration-freeze.html: missing implementation marker ' + marker);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (legacyMigrationIntegrationS06.includes(forbidden)) fail('g5-imp-07-s06-legacy-migration-integration-freeze.html: forbidden target prefix marker found ' + forbidden);
}
for (const marker of ['amendment-19', 'Amendment 19', 'G5-IMP-07-S06 완료', 'Page 9·GET 38·POST 79']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing S06 amendment marker ' + marker);
}
for (const marker of ['G5-IMP-07-S06 통합 인수', 'check:legacy-migration-integration-g5', 'IMP-07을 6/6']) {
  if (!installOperations.includes(marker)) fail('a-ds-15-install-upgrade-migration-operations.html: missing S06 acceptance marker ' + marker);
}
for (const marker of ['API-AUD-2026-08-25-R108-OBSERVED', 'G5-IMP-07-S03-D3-D2', 'check:legacy-migration-mapping-g5', 'Platform API change: 0']) {
const uninstallPreservationS01 = await readFile(path.join(docsRoot, 'g5-imp-08-s01-uninstall-preservation.html'), 'utf8').catch(() => {
  fail('g5-imp-08-s01-uninstall-preservation.html: missing');
  return '';
});
for (const marker of [
  'G5 · Operations · Implementation 08 · Step 01',
  'G5-IMP-08-S01 완료',
  'IMP-08 1/6 완료',
  'A-DS15-A20',
  'A-DS15-D22',
  '45개 literal',
  'option',
  'Private 파일',
  '플랫폼 배정',
  'check:uninstall-preservation-g5',
  '운영 DB·파일 실행',
  'G5-IMP-08-S02',
  'Platform API change: 0',
]) {
  if (!uninstallPreservationS01.includes(marker)) fail('g5-imp-08-s01-uninstall-preservation.html: missing implementation marker ' + marker);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (uninstallPreservationS01.includes(forbidden)) fail('g5-imp-08-s01-uninstall-preservation.html: forbidden target prefix marker found ' + forbidden);
}
for (const marker of ['amendment-20', 'Amendment 20', 'G5-IMP-08-S01 완료', 'Page 9·GET 38·POST 79']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing S01 no-route-change marker ' + marker);
}
for (const marker of ['G5-IMP-08-S01 uninstall 보존 인수', 'check:uninstall-preservation-g5', 'A-DS15-A20', 'A-DS15-D22']) {
  if (!installOperations.includes(marker)) fail('a-ds-15-install-upgrade-migration-operations.html: missing S01 acceptance marker ' + marker);
}
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R108 D3-D2 marker ' + marker);
}
const purgePreviewS02 = await readFile(path.join(docsRoot, 'g5-imp-08-s02-purge-preview.html'), 'utf8').catch(() => {
  fail('g5-imp-08-s02-purge-preview.html: missing');
  return '';
});
for (const marker of [
  'G5 · Operations · Implementation 08 · Step 02',
  'G5-IMP-08-S02 완료',
  'IMP-08 2/6',
  '45개 literal',
  '10개 owner',
  '단일 SELECT',
  'oj_ai.purge_preview@1.0.0',
  'Idempotency-Key',
  'manage_migrations',
  'delete_plugins',
  '1,800초',
  'mutation 0',
  'check:purge-preview-g5',
  'G5-IMP-08-S03',
  'Platform API change: 0',
]) {
  if (!purgePreviewS02.includes(marker)) fail('g5-imp-08-s02-purge-preview.html: missing implementation marker ' + marker);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (purgePreviewS02.includes(forbidden)) fail('g5-imp-08-s02-purge-preview.html: forbidden target prefix marker found ' + forbidden);
}
for (const marker of ['amendment-21', 'Amendment 21', 'G5-IMP-08-S02 완료', 'Page 9·GET 38·POST 79']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing S02 amendment marker ' + marker);
}
for (const marker of ['G5-IMP-08-S02 C71 preview 인수', 'g5-imp-08-s02-purge-preview.html', 'check:purge-preview-g5', 'mutation 0']) {
  if (!installOperations.includes(marker)) fail('a-ds-15-install-upgrade-migration-operations.html: missing S02 acceptance marker ' + marker);
}
for (const marker of ['API-AUD-2026-08-25-R109-OBSERVED', 'G5-IMP-07-S03-D3-D3', 'check:legacy-migration-publication-g5', 'G5-IMP-07-S03-D3-D4', 'Platform API change: 0']) {
  if (!apiAudit.includes(marker)) fail('platform-api-implementation-audit.html: missing R109 D3-D3 marker ' + marker);
}
const r110Start = apiAudit.indexOf('id="api-audit-r110"');
const r110End = apiAudit.indexOf('</section>', r110Start);
const r110Section = r110Start >= 0 && r110End > r110Start ? apiAudit.slice(r110Start, r110End) : '';
for (const marker of ['API-AUD-2026-08-25-R110-OBSERVED', 'G5-IMP-07-S03-D3-D4', 'oj_ai.legacy_migration_plan@1.2.0', 'assignment_source_ref', 'check:legacy-migration-assignment-g5', '전체 애드온 회귀', '172개 설계 HTML', '종료 코드 0', '플랫폼 계약 회귀', '7종 통과', '14c3b2fea37a12fae90d6a338edd7df21a8d71841b4cf8f12cd08a8cd8933eae', 'G5-IMP-07-S03-D3-D5', 'Platform API change: 0']) {
  if (!r110Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R110 D3-D4 marker ' + marker);
}
const r111Start = apiAudit.indexOf('id="api-audit-r111"');
const r111End = apiAudit.indexOf('</section>', r111Start);
const r111Section = r111Start >= 0 && r111End > r111Start ? apiAudit.slice(r111Start, r111End) : '';
for (const marker of ['API-AUD-2026-08-26-R111-OBSERVED', 'G5-IMP-07-S03-D3-D5', 'document_hash', 'teacher control receipt', 'check:legacy-migration-submission-g5', '172개 설계 HTML', '종료 코드 0', '7종 통과', '14c3b2fea37a12fae90d6a338edd7df21a8d71841b4cf8f12cd08a8cd8933eae', 'G5-IMP-07-S03-D3-D6', 'Platform API change: 0']) {
  if (!r111Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R111 D3-D5 marker ' + marker);
}
const r112Start = apiAudit.indexOf('id="api-audit-r112"');
const r112End = apiAudit.indexOf('</section>', r112Start);
const r112Section = r112Start >= 0 && r112End > r112Start ? apiAudit.slice(r112Start, r112End) : '';
for (const marker of ['API-AUD-2026-08-26-R112-OBSERVED', 'G5-IMP-07-S03-D3-D6', 'check:legacy-migration-evaluation-g5', 'raw provider response', 'G5-IMP-07-S03-D3-D7', 'Platform API change: 0']) {
  if (!r112Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R112 D3-D6 marker ' + marker);
}
const r119Start = apiAudit.indexOf('id="api-audit-r119"');
const r119End = apiAudit.indexOf('</section>', r119Start);
const r119Section = r119Start >= 0 && r119End > r119Start ? apiAudit.slice(r119Start, r119End) : '';
for (const marker of ['API-AUD-2026-08-26-R119-OBSERVED', 'G5-IMP-07-S06', 'check:legacy-migration-integration-g5', '172개 HTML', '131개 finding', '7/7 통과', 'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb', '운영 DB 실행 0건', 'G5-IMP-08-S01', 'Platform API change: 0']) {
  if (!r119Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R119 S06 marker ' + marker);
}
for (const marker of ['npm test', '종료 코드 0']) {
  if (!r119Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R119 full regression marker ' + marker);
}
const r120Start = apiAudit.indexOf('id="api-audit-r120"');
const r120End = apiAudit.indexOf('</section>', r120Start);
const r120Section = r120Start >= 0 && r120End > r120Start ? apiAudit.slice(r120Start, r120End) : '';
for (const marker of ['API-AUD-2026-08-26-R120-OBSERVED', 'G5-IMP-08-S01', 'check:uninstall-preservation-g5', '45개', '7/7 통과', 'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb', '운영 DB·파일 삭제 0건', 'G5-IMP-08-S02', 'Platform API change: 0']) {
  if (!r120Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R120 S01 marker ' + marker);
}
for (const marker of ['npm test', '종료 코드 0']) {
  if (!r120Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R120 full regression marker ' + marker);
}
const r121Start = apiAudit.indexOf('id="api-audit-r121"');
const r121End = apiAudit.indexOf('</section>', r121Start);
const r121Section = r121Start >= 0 && r121End > r121Start ? apiAudit.slice(r121Start, r121End) : '';
for (const marker of ['API-AUD-2026-08-26-R121-OBSERVED', 'G5-IMP-08-S02', 'check:purge-preview-g5', '45개', '10개 owner', '172개 HTML', '131개 finding', '7/7 통과', 'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb', '운영 DB·파일 삭제 0건', 'G5-IMP-08-S03', 'Platform API change: 0']) {
  if (!r121Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R121 S02 marker ' + marker);
}
for (const marker of ['npm test', '종료 코드 0']) {
  if (!r121Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R121 full regression marker ' + marker);
}
const uninstallPreservationEvidenceRow = apiAudit.indexOf('<tr><td><code>npm run check:uninstall-preservation-g5</code>');
const uninstallPreservationFullRegressionRow = apiAudit.indexOf('<tr><td><code>npm test</code> S01 최종 회귀');
const uninstallPreservationAuditFooter = apiAudit.indexOf('<p class="footer">', uninstallPreservationEvidenceRow);
if (uninstallPreservationEvidenceRow < 0 || uninstallPreservationFullRegressionRow < uninstallPreservationEvidenceRow || uninstallPreservationAuditFooter < uninstallPreservationFullRegressionRow) {
  fail('platform-api-implementation-audit.html: G5-IMP-08-S01 evidence rows must remain inside the final evidence table before the footer');
}
const purgePreviewEvidenceRow = apiAudit.indexOf('<tr><td><code>npm run check:purge-preview-g5</code>');
const purgePreviewFullRegressionRow = apiAudit.indexOf('<tr><td><code>npm test</code> S02 최종 회귀');
const purgePreviewAuditFooter = apiAudit.indexOf('<p class="footer">', purgePreviewEvidenceRow);
if (purgePreviewEvidenceRow < 0 || purgePreviewFullRegressionRow < purgePreviewEvidenceRow || purgePreviewAuditFooter < purgePreviewFullRegressionRow) {
  fail('platform-api-implementation-audit.html: G5-IMP-08-S02 evidence rows must remain inside the final evidence table before the footer');
}

if (operationsDiagnosticsUiS04.includes('$wpdb->prefix') || operationsDiagnosticsUiS04.includes('wp_oj_ai_')) {
  fail('g5-imp-05-s04-operations-ui-regression.html: forbidden table prefix found');
}



const packageManifestSource = await readFile(path.join(root, 'package.json'), 'utf8').catch(() => {
  fail('package.json: missing');
  return '{}';
});
let packageManifest = {};
try {
  packageManifest = JSON.parse(packageManifestSource);
} catch {
  fail('package.json: invalid JSON');
}
const addonVersion = typeof packageManifest.version === 'string' ? packageManifest.version : '';
if (!/^\d+\.\d+\.\d+$/.test(addonVersion)) fail('package.json: invalid add-on version');
const runTestsSource = await readFile(path.join(root, 'scripts', 'run-tests.mjs'), 'utf8').catch(() => {
  fail('scripts/run-tests.mjs: missing');
  return '';
});
const reportDashboardCheckSource = await readFile(path.join(root, 'scripts', 'check-report-dashboard-g5-imp-01-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-report-dashboard-g5-imp-01-s04.ps1: missing');
  return '';
});
const reportSnapshotCheckSource = await readFile(path.join(root, 'scripts', 'check-report-snapshot-g5-imp-02-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-report-snapshot-g5-imp-02-s01.ps1: missing');
  return '';
});
const reportSnapshotQueryCheckSource = await readFile(path.join(root, 'scripts', 'check-report-snapshot-query-g5-imp-02-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-report-snapshot-query-g5-imp-02-s02.ps1: missing');
  return '';
});
const reportSourceManifestCheckSource = await readFile(path.join(root, 'scripts', 'check-report-source-manifest-g5-imp-02-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-report-source-manifest-g5-imp-02-s03.ps1: missing');
  return '';
});
const reportSnapshotIntegrationCheckSource = await readFile(path.join(root, 'scripts', 'check-report-snapshot-integration-g5-imp-02-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-report-snapshot-integration-g5-imp-02-s04.ps1: missing');
  return '';
});
const reportExportEnqueueCheckSource = await readFile(path.join(root, 'scripts', 'check-report-export-enqueue-g5-imp-03-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-report-export-enqueue-g5-imp-03-s01.ps1: missing');
  return '';
});
const submissionBundleEnqueueCheckSource = await readFile(path.join(root, 'scripts', 'check-submission-bundle-enqueue-g5-imp-03-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-bundle-enqueue-g5-imp-03-s02.ps1: missing');
  return '';
});
const reportJsonPackageEnqueueCheckSource = await readFile(path.join(root, 'scripts', 'check-report-json-package-enqueue-g5-imp-03-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-report-json-package-enqueue-g5-imp-03-s03.ps1: missing');
  return '';
});
const reportExportJobDownloadCheckSource = await readFile(path.join(root, 'scripts', 'check-report-export-job-download-g5-imp-03-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-report-export-job-download-g5-imp-03-s04.ps1: missing');
  return '';
});
const reportExportControlCheckSource = await readFile(path.join(root, 'scripts', 'check-report-export-control-g5-imp-03-s05.ps1'), 'utf8').catch(() => {
  fail('scripts/check-report-export-control-g5-imp-03-s05.ps1: missing');
  return '';
});
const worksheetPdfPackageSource = await readFile(path.join(root, 'scripts', 'check-worksheet-pdf-package-g2-imp-07.ps1'), 'utf8').catch(() => {
  fail('scripts/check-worksheet-pdf-package-g2-imp-07.ps1: missing');
  return '';
});
const reportExportIntegrationCheckSource = await readFile(path.join(root, 'scripts', 'check-report-export-integration-g5-imp-03-s06.ps1'), 'utf8').catch(() => {
  fail('scripts/check-report-export-integration-g5-imp-03-s06.ps1: missing');
  return '';
});
const operationsDiagnosticsCheckSource = await readFile(path.join(root, 'scripts', 'check-operations-diagnostics-g5-imp-05-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-operations-diagnostics-g5-imp-05-s01.ps1: missing');
  return '';
});
const operationsAiRoutingCheckSource = await readFile(path.join(root, 'scripts', 'check-operations-ai-routing-g5-imp-05-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-operations-ai-routing-g5-imp-05-s02.ps1: missing');
  return '';
});
const operationsStorageCheckSource = await readFile(path.join(root, 'scripts', 'check-operations-storage-g5-imp-05-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-operations-storage-g5-imp-05-s03.ps1: missing');
  return '';
});
const operationsDiagnosticsUiCheckSource = await readFile(path.join(root, 'scripts', 'check-operations-diagnostics-ui-g5-imp-05-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-operations-diagnostics-ui-g5-imp-05-s04.ps1: missing');
  return '';
});
const freshInstallCheckSource = await readFile(path.join(root, 'scripts', 'check-fresh-install-g5-imp-06-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-fresh-install-g5-imp-06-s01.ps1: missing');
  return '';
});
const sequentialUpgradeCheckSource = await readFile(path.join(root, 'scripts', 'check-sequential-upgrade-g5-imp-06-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-sequential-upgrade-g5-imp-06-s02.ps1: missing');
  return '';
});
const failureRestartCheckSource = await readFile(path.join(root, 'scripts', 'check-failure-restart-g5-imp-06-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-failure-restart-g5-imp-06-s03.ps1: missing');
  return '';
});
const forwardRepairCheckSource = await readFile(path.join(root, 'scripts', 'check-forward-repair-g5-imp-06-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-forward-repair-g5-imp-06-s04.ps1: missing');
  return '';
});
const dataPreservationCheckSource = await readFile(path.join(root, 'scripts', 'check-data-preservation-g5-imp-06-s05.ps1'), 'utf8').catch(() => {
  fail('scripts/check-data-preservation-g5-imp-06-s05.ps1: missing');
  return '';
});
const legacyMigrationIntegrationCheckSource = await readFile(path.join(root, 'scripts', 'check-legacy-migration-integration-g5-imp-07-s06.ps1'), 'utf8').catch(() => {
  fail('scripts/check-legacy-migration-integration-g5-imp-07-s06.ps1: missing');
  return '';
});
const uninstallPreservationCheckSource = await readFile(path.join(root, 'scripts', 'check-uninstall-data-preservation-g5-imp-08-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-uninstall-data-preservation-g5-imp-08-s01.ps1: missing');
  return '';
});
const purgePreviewCheckSource = await readFile(path.join(root, 'scripts', 'check-purge-preview-g5-imp-08-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-purge-preview-g5-imp-08-s02.ps1: missing');
  return '';
});
for (const marker of ['check:legacy-migration-integration-g5', 'scripts/check-legacy-migration-integration-g5-imp-07-s06.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-07-S06 integration marker ' + marker);
}
for (const marker of ['scripts/check-legacy-migration-integration-g5-imp-07-s06.ps1', '-SkipConstituentChecks', 'Legacy migration integration G5-IMP-07-S06 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-07-S06 aggregate marker ' + marker);
}
for (const marker of [
  'check-legacy-migration-preview-g5-imp-07-s01.ps1',
  'check-legacy-migration-rollback-g5-imp-07-s05.ps1',
  'legacy-migration-integration-freeze-g5-imp-07-s06.php',
  'G5-IMP-07-S06 passed:',
]) {
  if (!legacyMigrationIntegrationCheckSource.includes(marker)) fail('S06 integration gate: missing constituent marker ' + marker);
}
for (const marker of ['check:purge-preview-g5', 'scripts/check-purge-preview-g5-imp-08-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-08-S02 purge preview marker ' + marker);
}
for (const marker of ['scripts/check-purge-preview-g5-imp-08-s02.ps1', '-SkipConstituentChecks', 'Purge preview C71 G5-IMP-08-S02 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-08-S02 aggregate marker ' + marker);
}
for (const marker of ['purge-preview-g5-imp-08-s02.php', 'g5-imp-08-s02-purge-preview.html', 'check-uninstall-data-preservation-g5-imp-08-s01.ps1', 'G5-IMP-08-S02 passed:']) {
  if (!purgePreviewCheckSource.includes(marker)) fail('S02 purge preview gate: missing contract marker ' + marker);
}
const purgeApprovalS03 = await readFile(path.join(docsRoot, 'g5-imp-08-s03-purge-approval.html'), 'utf8').catch(() => {
  fail('g5-imp-08-s03-purge-approval.html: missing');
  return '';
});
const purgeApprovalCheckSource = await readFile(path.join(root, 'scripts', 'check-purge-approval-g5-imp-08-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-purge-approval-g5-imp-08-s03.ps1: missing');
  return '';
});
for (const marker of [
  'G5 · Operations · Implementation 08 · Step 03',
  'G5-IMP-08-S03 완료',
  'IMP-08 3/6 완료',
  '영구 삭제',
  '86,400초',
  'approved',
  'check:purge-approval-g5',
  '6/7·외부 정합화 필요',
  'Platform API change: 0',
  'G5-IMP-08-S04',
]) {
  if (!purgeApprovalS03.includes(marker)) fail('g5-imp-08-s03-purge-approval.html: missing implementation marker ' + marker);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (purgeApprovalS03.includes(forbidden)) fail('g5-imp-08-s03-purge-approval.html: forbidden target prefix marker found ' + forbidden);
}
for (const marker of ['g5-imp-08-s03-purge-approval.html', 'G5-IMP-08-S03 완료·IMP-08 3/6', 'check:purge-approval-g5', 'G5-IMP-08-S04', 'C73 execute·C74 cancel']) {
  if (!procedure.includes(marker)) fail('design-procedure.html: missing G5 IMP-08-S03 current marker ' + marker);
  if (!implementationRoadmap.includes(marker)) fail('implementation-roadmap.html: missing G5 IMP-08-S03 current marker ' + marker);
  if (!indexDocument.includes(marker)) fail('index.html: missing G5 IMP-08-S03 current marker ' + marker);
}
for (const marker of ['amendment-22', 'A-DS-08 Amendment 22', 'g5-imp-08-s03-purge-approval.html', 'G5-IMP-08-S03 완료', 'Page 9·GET 38·POST 79']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing S03 amendment marker ' + marker);
}
for (const marker of ['G5-IMP-08-S03 C72 승인 인수', 'g5-imp-08-s03-purge-approval.html', 'check:purge-approval-g5', 'manage_migrations + delete_plugins']) {
  if (!installOperations.includes(marker)) fail('a-ds-15-install-upgrade-migration-operations.html: missing S03 acceptance marker ' + marker);
}
const r122Start = apiAudit.indexOf('id="api-audit-r122"');
const r122End = apiAudit.indexOf('</section>', r122Start);
const r122Section = r122Start >= 0 && r122End > r122Start ? apiAudit.slice(r122Start, r122End) : '';
for (const marker of ['API-AUD-2026-08-26-R122-OBSERVED', 'G5-IMP-08-S03', 'check:purge-approval-g5', '2.0.0-dev.74', '1.1.0', '37개', '62·17·11', '6/7 통과', 'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb', '14c3b2fea37a12fae90d6a338edd7df21a8d71841b4cf8f12cd08a8cd8933eae', '운영 DB·파일 삭제 0건', 'G5-IMP-08-S04', 'Platform API change: 0']) {
  if (!r122Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R122 S03 marker ' + marker);
}
for (const marker of ['npm test', '종료 코드 0']) {
  if (!r122Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R122 full regression marker ' + marker);
}
const purgeApprovalEvidenceRow = apiAudit.indexOf('<tr><td><code>npm run check:purge-approval-g5</code>');
const purgeApprovalFullRegressionRow = apiAudit.indexOf('<tr><td><code>npm test</code> S03 최종 회귀');
const purgeApprovalAuditFooter = apiAudit.indexOf('<p class="footer">', purgeApprovalEvidenceRow);
if (purgeApprovalEvidenceRow < 0 || purgeApprovalFullRegressionRow < purgeApprovalEvidenceRow || purgeApprovalAuditFooter < purgeApprovalFullRegressionRow) {
  fail('platform-api-implementation-audit.html: G5-IMP-08-S03 evidence rows must remain inside the final evidence table before the footer');
}
for (const marker of ['check:purge-approval-g5', 'scripts/check-purge-approval-g5-imp-08-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-08-S03 purge approval marker ' + marker);
}
for (const marker of ['scripts/check-purge-approval-g5-imp-08-s03.ps1', '-SkipConstituentChecks', 'Purge approval C72 G5-IMP-08-S03 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-08-S03 aggregate marker ' + marker);
}
for (const marker of ['purge-approval-g5-imp-08-s03.php', 'g5-imp-08-s03-purge-approval.html', 'check-purge-preview-g5-imp-08-s02.ps1', 'CORE_DELETE_PLUGINS', 'G5-IMP-08-S03 passed:']) {
  if (!purgeApprovalCheckSource.includes(marker)) fail('S03 purge approval gate: missing contract marker ' + marker);
}
const purgeExecutionS04 = await readFile(path.join(docsRoot, 'g5-imp-08-s04-purge-execution-cancel.html'), 'utf8').catch(() => {
  fail('g5-imp-08-s04-purge-execution-cancel.html: missing');
  return '';
});
const purgeExecutionCheckSource = await readFile(path.join(root, 'scripts', 'check-purge-execution-g5-imp-08-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-purge-execution-g5-imp-08-s04.ps1: missing');
  return '';
});
for (const marker of [
  'G5 · Operations · Implementation 08 · Step 04',
  'G5-IMP-08-S04 완료',
  'IMP-08 4/6 완료',
  'C73',
  'C74',
  '40개',
  '250행',
  '20초',
  'check:purge-execution-g5',
  '6/7',
  'Platform API change: 0',
  'G5-IMP-08-S05',
  'Q36',
]) {
  if (!purgeExecutionS04.includes(marker)) fail('g5-imp-08-s04-purge-execution-cancel.html: missing implementation marker ' + marker);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (purgeExecutionS04.includes(forbidden)) fail('g5-imp-08-s04-purge-execution-cancel.html: forbidden target prefix marker found ' + forbidden);
}
for (const marker of ['g5-imp-08-s04-purge-execution-cancel.html', 'G5-IMP-08-S04', 'IMP-08 4/6', 'check:purge-execution-g5', 'G5-IMP-08-S05', 'Q36']) {
  if (!procedure.includes(marker)) fail('design-procedure.html: missing G5 IMP-08-S04 current marker ' + marker);
  if (!implementationRoadmap.includes(marker)) fail('implementation-roadmap.html: missing G5 IMP-08-S04 current marker ' + marker);
  if (!indexDocument.includes(marker)) fail('index.html: missing G5 IMP-08-S04 current marker ' + marker);
}
for (const marker of ['amendment-23', 'A-DS-08 Amendment 23', 'g5-imp-08-s04-purge-execution-cancel.html', 'G5-IMP-08-S04 완료', 'Page 9·GET 38·POST 79']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing S04 amendment marker ' + marker);
}
for (const marker of ['G5-IMP-08-S04 C73·C74 인수', 'g5-imp-08-s04-purge-execution-cancel.html', 'check:purge-execution-g5', 'maintenance_required', '250행·20초']) {
  if (!installOperations.includes(marker)) fail('a-ds-15-install-upgrade-migration-operations.html: missing S04 acceptance marker ' + marker);
}
for (const marker of ['A-DS-05 Amendment 11', 'completed|failed|cancelled', '40개 data table', 'schema 변경 0']) {
  if (!databaseDesign.includes(marker)) fail('a-ds-05-addon-database.html: missing S04 purge state marker ' + marker);
}
const r123Start = apiAudit.indexOf('id="api-audit-r123"');
const r123End = apiAudit.indexOf('</section>', r123Start);
const r123Section = r123Start >= 0 && r123End > r123Start ? apiAudit.slice(r123Start, r123End) : '';
for (const marker of [
  'API-AUD-2026-08-26-R123-OBSERVED',
  'G5-IMP-08-S04',
  'check:purge-execution-g5',
  '2.0.0-dev.74',
  '1.1.0',
  '37개',
  'route 160',
  'action 131',
  'capability 63',
  'role 18',
  'preset 11',
  'menu 59',
  '6/7 통과',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  '68131918db0fd91307a4341dfff684ccb549e53e5e635270a731531e1fd5b92b',
  '운영 DB·파일 삭제 0건',
  'G5-IMP-08-S05',
  'Platform API change: 0',
]) {
  if (!r123Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R123 S04 marker ' + marker);
}
for (const marker of ['npm test', '종료 코드 0']) {
  if (!r123Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R123 full regression marker ' + marker);
}
const purgeExecutionEvidenceRow = apiAudit.indexOf('<tr><td><code>npm run check:purge-execution-g5</code>');
const purgeExecutionFullRegressionRow = apiAudit.indexOf('<tr><td><code>npm test</code> S04 최종 회귀');
const purgeExecutionAuditFooter = apiAudit.indexOf('<p class="footer">', purgeExecutionEvidenceRow);
if (purgeExecutionEvidenceRow < 0 || purgeExecutionFullRegressionRow < purgeExecutionEvidenceRow || purgeExecutionAuditFooter < purgeExecutionFullRegressionRow) {
  fail('platform-api-implementation-audit.html: G5-IMP-08-S04 evidence rows must remain inside the final evidence table before the footer');
}
for (const marker of ['check:purge-execution-g5', 'scripts/check-purge-execution-g5-imp-08-s04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-08-S04 purge execution marker ' + marker);
}
for (const marker of ['scripts/check-purge-execution-g5-imp-08-s04.ps1', '-SkipConstituentChecks', 'Purge execution C73 and cancellation C74 G5-IMP-08-S04 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-08-S04 aggregate marker ' + marker);
}
for (const marker of [
  'purge-execution-cancel-g5-imp-08-s04.php',
  'g5-imp-08-s04-purge-execution-cancel.html',
  'check-purge-approval-g5-imp-08-s03.ps1',
  'CORE_DELETE_PLUGINS',
  'TARGET_TABLE_COUNT = 43',
  'G5-IMP-08-S04 passed:',
]) {
  if (!purgeExecutionCheckSource.includes(marker)) fail('S04 purge execution gate: missing contract marker ' + marker);
}
const purgeJobQueryS05 = await readFile(path.join(docsRoot, 'g5-imp-08-s05-purge-job-query.html'), 'utf8').catch(() => {
  fail('g5-imp-08-s05-purge-job-query.html: missing');
  return '';
});
const purgeJobQueryCheckSource = await readFile(path.join(root, 'scripts', 'check-purge-job-query-g5-imp-08-s05.ps1'), 'utf8').catch(() => {
  fail('scripts/check-purge-job-query-g5-imp-08-s05.ps1: missing');
  return '';
});
for (const marker of [
  'G5 · Operations · Implementation 08 · Step 05',
  'G5-IMP-08-S05 완료',
  'IMP-08 5/6 완료',
  'GET /oj-ai/v1/purge-jobs/{job_id}',
  '1,800초',
  '현재 legal hold',
  'check:purge-job-query-g5',
  '6/7',
  'Platform API change: 0',
  'G5-IMP-08-S06',
]) {
  if (!purgeJobQueryS05.includes(marker)) fail('g5-imp-08-s05-purge-job-query.html: missing implementation marker ' + marker);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (purgeJobQueryS05.includes(forbidden)) fail('g5-imp-08-s05-purge-job-query.html: forbidden target prefix marker found ' + forbidden);
}
for (const marker of ['g5-imp-08-s05-purge-job-query.html', 'G5-IMP-08-S05', 'IMP-08 5/6', 'check:purge-job-query-g5', 'G5-IMP-08-S06']) {
  if (!procedure.includes(marker)) fail('design-procedure.html: missing G5 IMP-08-S05 current marker ' + marker);
  if (!implementationRoadmap.includes(marker)) fail('implementation-roadmap.html: missing G5 IMP-08-S05 current marker ' + marker);
  if (!indexDocument.includes(marker)) fail('index.html: missing G5 IMP-08-S05 current marker ' + marker);
}
for (const marker of ['amendment-24', 'A-DS-08 Amendment 24', 'g5-imp-08-s05-purge-job-query.html', 'G5-IMP-08-S05 완료', 'Page 9·GET 38·POST 79']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing S05 amendment marker ' + marker);
}
for (const marker of ['G5-IMP-08-S05 Q36 인수', 'g5-imp-08-s05-purge-job-query.html', 'check:purge-job-query-g5', '1,800초', 'manage_migrations']) {
  if (!installOperations.includes(marker)) fail('a-ds-15-install-upgrade-migration-operations.html: missing S05 acceptance marker ' + marker);
}
for (const marker of ['A-DS-05 Amendment 12', 'A-DS05-AM12-03', 'Q36', 'schema 변경 0']) {
  if (!databaseDesign.includes(marker)) fail('a-ds-05-addon-database.html: missing S05 purge query marker ' + marker);
}
const r124Start = apiAudit.indexOf('id="api-audit-r124"');
const r124End = apiAudit.indexOf('</section>', r124Start);
const r124Section = r124Start >= 0 && r124End > r124Start ? apiAudit.slice(r124Start, r124End) : '';
for (const marker of [
  'API-AUD-2026-08-26-R124-OBSERVED',
  'G5-IMP-08-S05',
  'check:purge-job-query-g5',
  '2.0.0-dev.74',
  '1.1.0',
  '37개',
  'route 160',
  'action 131',
  'capability 63',
  'role 18',
  'preset 11',
  'menu 59',
  '6/7 통과',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'Page 9·GET 38·POST 79',
  '운영 DB·파일 삭제 0건',
  'G5-IMP-08-S06',
  'Platform API change: 0',
]) {
  if (!r124Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R124 S05 marker ' + marker);
}
for (const marker of ['npm test', '종료 코드 0']) {
  if (!r124Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R124 full regression marker ' + marker);
}
const purgeJobQueryEvidenceRow = apiAudit.indexOf('<tr><td><code>npm run check:purge-job-query-g5</code>');
const purgeJobQueryFullRegressionRow = apiAudit.indexOf('<tr><td><code>npm test</code> S05 최종 회귀');
const purgeJobQueryAuditFooter = apiAudit.indexOf('<p class="footer">', purgeJobQueryEvidenceRow);
if (purgeJobQueryEvidenceRow < 0 || purgeJobQueryFullRegressionRow < purgeJobQueryEvidenceRow || purgeJobQueryAuditFooter < purgeJobQueryFullRegressionRow) {
  fail('platform-api-implementation-audit.html: G5-IMP-08-S05 evidence rows must remain inside the final evidence table before the footer');
}
for (const marker of ['check:purge-job-query-g5', 'scripts/check-purge-job-query-g5-imp-08-s05.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-08-S05 purge query marker ' + marker);
}
for (const marker of ['scripts/check-purge-job-query-g5-imp-08-s05.ps1', '-SkipConstituentChecks', 'Purge job Q36 status G5-IMP-08-S05 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-08-S05 aggregate marker ' + marker);
}
for (const marker of [
  'purge-job-query-g5-imp-08-s05.php',
  'g5-imp-08-s05-purge-job-query.html',
  'check-purge-execution-g5-imp-08-s04.ps1',
  'Addon_Capabilities::MANAGE_MIGRATIONS',
  'expected_row_count( $preview )',
  'G5-IMP-08-S05 passed:',
]) {
  if (!purgeJobQueryCheckSource.includes(marker)) fail('S05 purge job query gate: missing contract marker ' + marker);
}
const purgeIntegrationS06 = await readFile(path.join(docsRoot, 'g5-imp-08-s06-purge-integration-freeze.html'), 'utf8').catch(() => {
  fail('g5-imp-08-s06-purge-integration-freeze.html: missing');
  return '';
});
const purgeIntegrationCheckSource = await readFile(path.join(root, 'scripts', 'check-purge-integration-g5-imp-08-s06.ps1'), 'utf8').catch(() => {
  fail('scripts/check-purge-integration-g5-imp-08-s06.ps1: missing');
  return '';
});
const executePurgeS06Source = await readFile(path.join(root, 'src', 'Operations', 'Purge', 'Application', 'Execute_Purge.php'), 'utf8');
const readPurgeJobS06Source = await readFile(path.join(root, 'src', 'Operations', 'Purge', 'Application', 'Read_Purge_Job.php'), 'utf8');
for (const marker of [
  'G5 · Operations · Implementation 08 · Step 06',
  'G5-IMP-08-S06 완료',
  'IMP-08 6/6 완료',
  'G5 8/8 완료',
  'now &gt;= expires',
  '정확히 24시간',
  '남은 행·파일·바이트',
  'check:purge-integration-g5',
  '6/7',
  'Platform API change: 0',
  'G6-IMP-01',
]) {
  if (!purgeIntegrationS06.includes(marker)) fail('g5-imp-08-s06-purge-integration-freeze.html: missing implementation marker ' + marker);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (purgeIntegrationS06.includes(forbidden)) fail('g5-imp-08-s06-purge-integration-freeze.html: forbidden target prefix marker found ' + forbidden);
}
for (const marker of ['g5-imp-08-s06-purge-integration-freeze.html', 'G5-IMP-08-S06', 'IMP-08 6/6', 'G5 8/8', 'check:purge-integration-g5', 'G6-IMP-01']) {
  if (!procedure.includes(marker)) fail('design-procedure.html: missing G5 IMP-08-S06 completion marker ' + marker);
  if (!implementationRoadmap.includes(marker)) fail('implementation-roadmap.html: missing G5 IMP-08-S06 completion marker ' + marker);
  if (!indexDocument.includes(marker)) fail('index.html: missing G5 IMP-08-S06 completion marker ' + marker);
}
for (const marker of ['amendment-25', 'A-DS-08 Amendment 25', 'g5-imp-08-s06-purge-integration-freeze.html', 'Page 9·GET 38·POST 79', 'G6-IMP-01']) {
  if (!routeRestDesign.includes(marker)) fail('a-ds-08-route-rest-command.html: missing S06 amendment marker ' + marker);
}
for (const marker of ['G5-IMP-08-S06 purge 통합 동결 인수', 'g5-imp-08-s06-purge-integration-freeze.html', 'check:purge-integration-g5', '정확한 token·승인 만료', 'G5 8/8']) {
  if (!installOperations.includes(marker)) fail('a-ds-15-install-upgrade-migration-operations.html: missing S06 acceptance marker ' + marker);
}
for (const marker of ['A-DS-05 Amendment 13', 'A-DS05-AM13-03', '남은 행·파일·바이트 0', 'schema 변경 0']) {
  if (!databaseDesign.includes(marker)) fail('a-ds-05-addon-database.html: missing S06 purge integration marker ' + marker);
}
const r125Start = apiAudit.indexOf('id="api-audit-r125"');
const r125End = apiAudit.indexOf('</section>', r125Start);
const r125Section = r125Start >= 0 && r125End > r125Start ? apiAudit.slice(r125Start, r125End) : '';
for (const marker of [
  'API-AUD-2026-08-26-R125-OBSERVED',
  'G5-IMP-08-S06',
  'check:purge-integration-g5',
  '2.0.0-dev.74',
  '1.1.0',
  '37개',
  'route 160',
  'action 131',
  'capability 63',
  'role 18',
  'preset 11',
  'menu 59',
  '6/7 통과',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'Page 9·GET 38·POST 79',
  '운영 DB·파일 삭제 0건',
  'G6-IMP-01',
  'Platform API change: 0',
]) {
  if (!r125Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R125 S06 marker ' + marker);
}
for (const marker of ['npm test', '종료 코드 0']) {
  if (!r125Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R125 full regression marker ' + marker);
}
const purgeIntegrationEvidenceRow = apiAudit.indexOf('<tr><td><code>npm run check:purge-integration-g5</code>');
const purgeIntegrationFullRegressionRow = apiAudit.indexOf('<tr><td><code>npm test</code> S06 최종 회귀', purgeIntegrationEvidenceRow);
const purgeIntegrationAuditFooter = apiAudit.indexOf('<p class="footer">', purgeIntegrationEvidenceRow);
if (purgeIntegrationEvidenceRow < 0 || purgeIntegrationFullRegressionRow < purgeIntegrationEvidenceRow || purgeIntegrationAuditFooter < purgeIntegrationFullRegressionRow) {
  fail('platform-api-implementation-audit.html: G5-IMP-08-S06 evidence rows must remain inside the final evidence table before the footer');
}
for (const marker of ['check:purge-integration-g5', 'scripts/check-purge-integration-g5-imp-08-s06.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-08-S06 purge integration marker ' + marker);
}
for (const marker of ['scripts/check-purge-integration-g5-imp-08-s06.ps1', '-SkipConstituentChecks', 'Purge integration G5-IMP-08-S06 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-08-S06 aggregate marker ' + marker);
}
for (const marker of [
  'purge-integration-freeze-g5-imp-08-s06.php',
  'g5-imp-08-s06-purge-integration-freeze.html',
  'check-uninstall-data-preservation-g5-imp-08-s01.ps1',
  'check-purge-preview-g5-imp-08-s02.ps1',
  'check-purge-approval-g5-imp-08-s03.ps1',
  'check-purge-execution-g5-imp-08-s04.ps1',
  'check-purge-job-query-g5-imp-08-s05.ps1',
  '$now_time >= $expires_time',
  '$this->now() >= $expires',
  'G5-IMP-08-S06 passed:',
]) {
  if (!purgeIntegrationCheckSource.includes(marker)) fail('S06 purge integration gate: missing contract marker ' + marker);
}
if (!executePurgeS06Source.includes('$now_time >= $expires_time')) fail('Execute_Purge.php: exact approval expiry must be fail-closed');
if (!readPurgeJobS06Source.includes('$this->now() >= $expires')) fail('Read_Purge_Job.php: Q36 expiry must match C73 exact boundary');
const businessScenarioAcceptanceG6Imp01 = await readFile(path.join(docsRoot, 'g6-imp-01-business-scenario-acceptance.html'), 'utf8').catch(() => {
  fail('g6-imp-01-business-scenario-acceptance.html: missing');
  return '';
});
const businessScenarioAcceptanceCheckG6Imp01 = await readFile(path.join(root, 'scripts', 'check-business-scenarios-g6-imp-01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-business-scenarios-g6-imp-01.ps1: missing');
  return '';
});
const businessScenarioAcceptanceContractG6Imp01 = await readFile(path.join(root, 'tests', 'Contract', 'business-scenario-acceptance-g6-imp-01.php'), 'utf8').catch(() => {
  fail('tests/Contract/business-scenario-acceptance-g6-imp-01.php: missing');
  return '';
});
checkBalancedTags(businessScenarioAcceptanceG6Imp01, 'g6-imp-01-business-scenario-acceptance.html');
for (const marker of [
  'G6 · Final Acceptance · Implementation 01',
  'G6-IMP-01 완료',
  'A-DS16-S01',
  'A-DS16-S12',
  '기존 증거 22개',
  'check:business-scenarios-g6',
  'G6 1/5',
  'Platform API change: 0',
  'G6-IMP-02',
]) {
  if (!businessScenarioAcceptanceG6Imp01.includes(marker)) fail('g6-imp-01-business-scenario-acceptance.html: missing acceptance marker ' + marker);
}
for (let number = 1; number <= 12; number += 1) {
  const scenarioId = 'A-DS16-S' + String(number).padStart(2, '0');
  if (!businessScenarioAcceptanceG6Imp01.includes(scenarioId)) fail('g6-imp-01-business-scenario-acceptance.html: missing scenario ' + scenarioId);
  if (!businessScenarioAcceptanceCheckG6Imp01.includes("'" + scenarioId + "'")) fail('G6 IMP-01 gate: missing scenario ' + scenarioId);
  if (!businessScenarioAcceptanceContractG6Imp01.includes("'" + scenarioId + "'")) fail('G6 IMP-01 contract: missing scenario ' + scenarioId);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (businessScenarioAcceptanceG6Imp01.includes(forbidden)) fail('g6-imp-01-business-scenario-acceptance.html: forbidden target prefix marker found ' + forbidden);
}
for (const marker of ['g6-imp-01-business-scenario-acceptance.html', 'G6-IMP-01', 'G6 1/5', 'check:business-scenarios-g6', 'G6-IMP-02']) {
  if (!procedure.includes(marker)) fail('design-procedure.html: missing G6 IMP-01 completion marker ' + marker);
  if (!implementationRoadmap.includes(marker)) fail('implementation-roadmap.html: missing G6 IMP-01 completion marker ' + marker);
  if (!indexDocument.includes(marker)) fail('index.html: missing G6 IMP-01 completion marker ' + marker);
}
for (const marker of ['id="amendment-09"', 'Amendment 09', 'G6-IMP-01', 'A-DS16-S01', 'A-DS16-S12', '기존 증거 22개', 'check:business-scenarios-g6', 'G6 1/5', 'G6-IMP-02']) {
  if (!crossValidation.includes(marker)) fail('a-ds-16-cross-validation-freeze.html: missing G6 IMP-01 amendment marker ' + marker);
}
const r126Start = apiAudit.indexOf('id="api-audit-r126"');
const r126End = apiAudit.indexOf('</section>', r126Start);
const r126Section = r126Start >= 0 && r126End > r126Start ? apiAudit.slice(r126Start, r126End) : '';
for (const marker of [
  'API-AUD-2026-08-26-R126-OBSERVED',
  'G6-IMP-01',
  '2.0.0-dev.74',
  '1.1.0',
  '공개 symbol 37개',
  '실제 route 160',
  'action 131',
  'capability 63',
  'role 18',
  'preset 11',
  'menu 59',
  'fixture 152·129·61·16·10·58',
  '6/7 통과',
  'A-DS16-S01~S12',
  '기존 증거 22개',
  'Page 9·GET 38·POST 79',
  'npm test',
  '종료 코드 0',
  'G6-IMP-02',
  'Platform API change: 0',
]) {
  if (!r126Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R126 G6 marker ' + marker);
}
const businessScenarioEvidenceRow = apiAudit.indexOf('<tr><td><code>npm run check:business-scenarios-g6</code>');
const businessScenarioFullRegressionRow = apiAudit.indexOf('<tr><td><code>npm test</code> G6-IMP-01 최종 회귀', businessScenarioEvidenceRow);
const businessScenarioAuditFooter = apiAudit.indexOf('<p class="footer">', businessScenarioEvidenceRow);
if (businessScenarioEvidenceRow < 0 || businessScenarioFullRegressionRow < businessScenarioEvidenceRow || businessScenarioAuditFooter < businessScenarioFullRegressionRow) {
  fail('platform-api-implementation-audit.html: G6-IMP-01 evidence rows must remain inside the final evidence table before the footer');
}
for (const marker of ['check:business-scenarios-g6', 'scripts/check-business-scenarios-g6-imp-01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G6 IMP-01 business scenario marker ' + marker);
}
for (const marker of ['scripts/check-business-scenarios-g6-imp-01.ps1', '-SkipConstituentChecks', 'G6-IMP-01 business scenarios passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G6 IMP-01 aggregate marker ' + marker);
}
for (const marker of [
  'SkipConstituentChecks',
  '$scenarioEvidence = [ordered]@{',
  "Invoke-Checked $node @('scripts\\run-tests.mjs')",
  'business-scenario-acceptance-g6-imp-01.php',
  'CodingPanda\\OjPlatform\\Kernel\\',
  'G6-IMP-01 passed:',
]) {
  if (!businessScenarioAcceptanceCheckG6Imp01.includes(marker)) fail('G6 IMP-01 gate: missing contract marker ' + marker);
}
for (const marker of [
  'g6_imp01_same( 22, count( $unique_evidence )',
  'g6_imp01_same( 48, count( $catalog->table_names() )',
  'g6_imp01_same( 10, count( $catalog->owner_counts() )',
  "str_starts_with( $table_name, 'oj_ai_' )",
  'CodingPanda\\\\OjPlatform\\\\Kernel\\\\',
  'G6-IMP-01 passed:',
]) {
  if (!businessScenarioAcceptanceContractG6Imp01.includes(marker)) fail('G6 IMP-01 PHP contract: missing marker ' + marker);
}
const securityPrivacyAccessibilityG6Imp02 = await readFile(path.join(docsRoot, 'g6-imp-02-security-privacy-accessibility.html'), 'utf8').catch(() => {
  fail('g6-imp-02-security-privacy-accessibility.html: missing');
  return '';
});
const securityPrivacyAccessibilityCheckG6Imp02 = await readFile(path.join(root, 'scripts', 'check-security-privacy-accessibility-g6-imp-02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-security-privacy-accessibility-g6-imp-02.ps1: missing');
  return '';
});
const securityPrivacyAccessibilityContractG6Imp02 = await readFile(path.join(root, 'tests', 'Contract', 'security-privacy-accessibility-g6-imp-02.php'), 'utf8').catch(() => {
  fail('tests/Contract/security-privacy-accessibility-g6-imp-02.php: missing');
  return '';
});
checkBalancedTags(securityPrivacyAccessibilityG6Imp02, 'g6-imp-02-security-privacy-accessibility.html');
for (const marker of [
  'G6 · Final Acceptance · Implementation 02',
  'G6-IMP-02 완료',
  'G6-IMP-02-S01',
  'G6-IMP-02-S05',
  '기존 관문 33개',
  '브라우저 12종',
  '중대 미해결 결함 0건',
  'check:security-privacy-accessibility-g6',
  'G6 2/5',
  'Platform API change: 0',
  'G6-IMP-03',
]) {
  if (!securityPrivacyAccessibilityG6Imp02.includes(marker)) fail('g6-imp-02-security-privacy-accessibility.html: missing acceptance marker ' + marker);
}
for (let number = 1; number <= 5; number += 1) {
  const dimensionId = 'G6-IMP-02-S' + String(number).padStart(2, '0');
  if (!securityPrivacyAccessibilityG6Imp02.includes(dimensionId)) fail('G6 IMP-02 HTML: missing dimension ' + dimensionId);
  if (!securityPrivacyAccessibilityCheckG6Imp02.includes("'" + dimensionId + "'")) fail('G6 IMP-02 gate: missing dimension ' + dimensionId);
  if (!securityPrivacyAccessibilityContractG6Imp02.includes("'" + dimensionId + "'")) fail('G6 IMP-02 contract: missing dimension ' + dimensionId);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (securityPrivacyAccessibilityG6Imp02.includes(forbidden)) fail('G6 IMP-02 HTML: forbidden target prefix marker found ' + forbidden);
}
for (const source of [procedure, implementationRoadmap, indexDocument]) {
  for (const marker of ['g6-imp-02-security-privacy-accessibility.html', 'G6-IMP-02', 'check:security-privacy-accessibility-g6', 'G6 2/5', 'G6-IMP-03']) {
    if (!source.includes(marker)) fail('canonical progress document: missing G6 IMP-02 marker ' + marker);
  }
}
for (const marker of ['id="amendment-10"', 'Amendment 10', 'G6-IMP-02-S01', 'G6-IMP-02-S05', '기존 관문 33개', '브라우저 12종', 'check:security-privacy-accessibility-g6', 'G6 2/5', 'G6-IMP-03']) {
  if (!crossValidation.includes(marker)) fail('a-ds-16-cross-validation-freeze.html: missing G6 IMP-02 amendment marker ' + marker);
}
const r127Start = apiAudit.indexOf('id="api-audit-r127"');
const r127End = apiAudit.indexOf('</section>', r127Start);
const r127Section = r127Start >= 0 && r127End > r127Start ? apiAudit.slice(r127Start, r127End) : '';
for (const marker of [
  'API-AUD-2026-08-26-R127-OBSERVED',
  'G6-IMP-02',
  '2.0.0-dev.74',
  '1.1.0',
  '공개 symbol 37개',
  '실제 route 160',
  'action 131',
  'capability 63',
  'role 18',
  'preset 11',
  'menu 59',
  'fixture 152·129·61·16·10·58',
  '6/7 통과',
  '기존 관문 33개',
  '브라우저 12종',
  'Page 9·GET 38·POST 79',
  'npm test',
  '종료 코드 0',
  'G6-IMP-03',
  'Platform API change: 0',
]) {
  if (!r127Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R127 G6 marker ' + marker);
}
const securityAcceptanceEvidenceRow = apiAudit.indexOf('<tr><td><code>npm run check:security-privacy-accessibility-g6</code>');
const securityAcceptanceFullRegressionRow = apiAudit.indexOf('<tr><td><code>npm test</code> G6-IMP-02 최종 회귀', securityAcceptanceEvidenceRow);
const securityAcceptanceAuditFooter = apiAudit.indexOf('<p class="footer">', securityAcceptanceEvidenceRow);
if (securityAcceptanceEvidenceRow < 0 || securityAcceptanceFullRegressionRow < securityAcceptanceEvidenceRow || securityAcceptanceAuditFooter < securityAcceptanceFullRegressionRow) {
  fail('platform-api-implementation-audit.html: G6-IMP-02 evidence rows must remain inside the final evidence table before the footer');
}
for (const marker of ['check:security-privacy-accessibility-g6', 'scripts/check-security-privacy-accessibility-g6-imp-02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G6 IMP-02 marker ' + marker);
}
for (const marker of ['scripts/check-security-privacy-accessibility-g6-imp-02.ps1', '-SkipConstituentChecks', 'G6-IMP-02 security, privacy, and accessibility passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G6 IMP-02 aggregate marker ' + marker);
}
for (const marker of [
  'SkipConstituentChecks',
  '$dimensionEvidence = [ordered]@{',
  "Invoke-Checked $node @('scripts\\run-tests.mjs')",
  'security-privacy-accessibility-g6-imp-02.php',
  'settings semantics',
  'settings keyboard',
  'consent keyboard',
  'G6-IMP-02 passed:',
]) {
  if (!securityPrivacyAccessibilityCheckG6Imp02.includes(marker)) fail('G6 IMP-02 gate: missing contract marker ' + marker);
}
for (const marker of [
  'g6_imp02_same( 33, count( $unique_evidence )',
  'g6_imp02_same( 12, count( $browser_evidence )',
  "'$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_'",
  'CodingPanda\\\\OjPlatform\\\\Kernel\\\\',
  'ArrowLeft',
  ':focus-visible',
  'G6-IMP-02 passed:',
]) {
  if (!securityPrivacyAccessibilityContractG6Imp02.includes(marker)) fail('G6 IMP-02 PHP contract: missing marker ' + marker);
}
const performanceResilienceG6Imp03 = await readFile(path.join(docsRoot, 'g6-imp-03-performance-resilience.html'), 'utf8').catch(() => {
  fail('g6-imp-03-performance-resilience.html: missing');
  return '';
});
const performanceResilienceCheckG6Imp03 = await readFile(path.join(root, 'scripts', 'check-performance-resilience-g6-imp-03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-performance-resilience-g6-imp-03.ps1: missing');
  return '';
});
const performanceResilienceContractG6Imp03 = await readFile(path.join(root, 'tests', 'Contract', 'performance-resilience-g6-imp-03.php'), 'utf8').catch(() => {
  fail('tests/Contract/performance-resilience-g6-imp-03.php: missing');
  return '';
});
checkBalancedTags(performanceResilienceG6Imp03, 'g6-imp-03-performance-resilience.html');
for (const marker of [
  'G6 · Final Acceptance · Implementation 03',
  'G6-IMP-03 완료',
  'G6-IMP-03-S01',
  'G6-IMP-03-S05',
  '28 existing gates',
  'check:performance-resilience-g6',
  'G6 3/5',
  'Platform API change: 0',
  'G6-IMP-04',
]) {
  if (!performanceResilienceG6Imp03.includes(marker)) fail('g6-imp-03-performance-resilience.html: missing acceptance marker ' + marker);
}
for (let number = 1; number <= 5; number += 1) {
  const dimensionId = 'G6-IMP-03-S' + String(number).padStart(2, '0');
  if (!performanceResilienceG6Imp03.includes(dimensionId)) fail('G6 IMP-03 HTML: missing dimension ' + dimensionId);
  if (!performanceResilienceCheckG6Imp03.includes("'" + dimensionId + "'")) fail('G6 IMP-03 gate: missing dimension ' + dimensionId);
  if (!performanceResilienceContractG6Imp03.includes("'" + dimensionId + "'")) fail('G6 IMP-03 contract: missing dimension ' + dimensionId);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (performanceResilienceG6Imp03.includes(forbidden)) fail('G6 IMP-03 HTML: forbidden target prefix marker found ' + forbidden);
}
for (const source of [procedure, implementationRoadmap, indexDocument]) {
  for (const marker of ['g6-imp-03-performance-resilience.html', 'G6-IMP-03', 'check:performance-resilience-g6', 'G6 3/5', 'G6-IMP-04']) {
    if (!source.includes(marker)) fail('canonical progress document: missing G6 IMP-03 marker ' + marker);
  }
}
for (const marker of ['id="amendment-11"', 'Amendment 11', 'G6-IMP-03-S01', 'G6-IMP-03-S05', '28 existing gates', 'check:performance-resilience-g6', 'G6 3/5', 'G6-IMP-04']) {
  if (!crossValidation.includes(marker)) fail('a-ds-16-cross-validation-freeze.html: missing G6 IMP-03 amendment marker ' + marker);
}
const r128Start = apiAudit.indexOf('id="api-audit-r128"');
const r128End = apiAudit.indexOf('</section>', r128Start);
const r128Section = r128Start >= 0 && r128End > r128Start ? apiAudit.slice(r128Start, r128End) : '';
for (const marker of [
  'API-AUD-2026-08-26-R128-OBSERVED',
  'G6-IMP-03',
  '2.0.0-dev.74',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  '68131918db0fd91307a4341dfff684ccb549e53e5e635270a731531e1fd5b92b',
  '1.1.0',
  '공개 symbol 37개',
  '실제 route 160',
  'action 131',
  'capability 63',
  'role 18',
  'preset 11',
  'menu 59',
  'fixture 152·129·61·16·10·58',
  '6/7 통과',
  '28 existing gates',
  'G6 3/5 완료',
  'Page 9·GET 38·POST 79',
  'npm test',
  '종료 코드 0',
  'G6-IMP-04',
  'Platform API change: 0',
]) {
  if (!r128Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R128 G6 marker ' + marker);
}
const performanceAcceptanceEvidenceRow = apiAudit.indexOf('<tr><td><code>npm run check:performance-resilience-g6</code>');
const performanceAcceptanceFullRegressionRow = apiAudit.indexOf('<tr><td><code>npm test</code> G6-IMP-03 최종 회귀', performanceAcceptanceEvidenceRow);
const performanceAcceptanceAuditFooter = apiAudit.indexOf('<p class="footer">', performanceAcceptanceEvidenceRow);
if (performanceAcceptanceEvidenceRow < 0 || performanceAcceptanceFullRegressionRow < performanceAcceptanceEvidenceRow || performanceAcceptanceAuditFooter < performanceAcceptanceFullRegressionRow) {
  fail('platform-api-implementation-audit.html: G6-IMP-03 evidence rows must remain inside the final evidence table before the footer');
}
for (const marker of ['check:performance-resilience-g6', 'scripts/check-performance-resilience-g6-imp-03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G6 IMP-03 marker ' + marker);
}
for (const marker of ['scripts/check-performance-resilience-g6-imp-03.ps1', '-SkipConstituentChecks', 'G6-IMP-03 performance and resilience passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G6 IMP-03 aggregate marker ' + marker);
}
for (const marker of [
  'SkipConstituentChecks',
  '$dimensionEvidence = [ordered]@{',
  "Invoke-Checked $node @('scripts\\run-tests.mjs')",
  'performance-resilience-g6-imp-03.php',
  'roster bounds',
  'queue recovery',
  'document bounds',
  'archive bounds',
  'report export bounds',
  'migration recovery',
  'purge recovery',
  'G6-IMP-03 passed:',
]) {
  if (!performanceResilienceCheckG6Imp03.includes(marker)) fail('G6 IMP-03 gate: missing contract marker ' + marker);
}
for (const marker of [
  'g6_imp03_same( 28, count( $unique_evidence )',
  "'PAGE_LIMIT', 100",
  "'MAXIMUM_LEARNERS', 5000",
  "'LEASE_SECONDS', 300",
  "'MAXIMUM_ATTEMPTS', 3",
  "'MAXIMUM_ROWS', 50000",
  "'BATCH_SIZE', 250",
  "'source_fingerprint', 'plan_hash', 'checkpoint', 'commit_batch'",
  "'$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_'",
  'CodingPanda\\\\OjPlatform\\\\Kernel\\\\',
  'G6-IMP-03 passed:',
]) {
  if (!performanceResilienceContractG6Imp03.includes(marker)) fail('G6 IMP-03 PHP contract: missing marker ' + marker);
}
checkPhpFragments('G6 IMP-03 PHP contract', performanceResilienceContractG6Imp03, [
  "'MAX_DOCUMENT_BYTES' => 2097152",
  "'MAX_ARCHIVE_BYTES' => 104857600",
]);

const upgradeRollbackG6Imp04 = await readFile(path.join(docsRoot, 'g6-imp-04-upgrade-rollback.html'), 'utf8').catch(() => {
  fail('g6-imp-04-upgrade-rollback.html: missing');
  return '';
});
const upgradeRollbackCheckG6Imp04 = await readFile(path.join(root, 'scripts', 'check-upgrade-rollback-g6-imp-04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-upgrade-rollback-g6-imp-04.ps1: missing');
  return '';
});
const upgradeRollbackContractG6Imp04 = await readFile(path.join(root, 'tests', 'Contract', 'upgrade-rollback-g6-imp-04.php'), 'utf8').catch(() => {
  fail('tests/Contract/upgrade-rollback-g6-imp-04.php: missing');
  return '';
});
checkBalancedTags(upgradeRollbackG6Imp04, 'g6-imp-04-upgrade-rollback.html');
for (const marker of [
  'G6 · Final Acceptance · Implementation 04',
  'G6-IMP-04 완료',
  'G6-IMP-04-S01',
  'G6-IMP-04-S05',
  '13 existing gates',
  'check:upgrade-rollback-g6',
  'G6 4/5',
  'Platform API change: 0',
  'G6-IMP-05',
]) {
  if (!upgradeRollbackG6Imp04.includes(marker)) fail('g6-imp-04-upgrade-rollback.html: missing acceptance marker ' + marker);
}
for (let number = 1; number <= 5; number += 1) {
  const dimensionId = 'G6-IMP-04-S' + String(number).padStart(2, '0');
  if (!upgradeRollbackG6Imp04.includes(dimensionId)) fail('G6 IMP-04 HTML: missing dimension ' + dimensionId);
  if (!upgradeRollbackCheckG6Imp04.includes("'" + dimensionId + "'")) fail('G6 IMP-04 gate: missing dimension ' + dimensionId);
  if (!upgradeRollbackContractG6Imp04.includes("'" + dimensionId + "'")) fail('G6 IMP-04 contract: missing dimension ' + dimensionId);
}
for (const forbidden of ['$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_']) {
  if (upgradeRollbackG6Imp04.includes(forbidden)) fail('G6 IMP-04 HTML: forbidden target prefix marker found ' + forbidden);
}
for (const source of [procedure, implementationRoadmap, indexDocument]) {
  for (const marker of ['g6-imp-04-upgrade-rollback.html', 'G6-IMP-04', 'check:upgrade-rollback-g6', 'G6 4/5', 'G6-IMP-05']) {
    if (!source.includes(marker)) fail('canonical progress document: missing G6 IMP-04 marker ' + marker);
  }
}
for (const marker of ['id="amendment-12"', 'Amendment 12', 'G6-IMP-04-S01', 'G6-IMP-04-S05', '13 existing gates', 'check:upgrade-rollback-g6', 'G6 4/5', 'G6-IMP-05']) {
  if (!crossValidation.includes(marker)) fail('a-ds-16-cross-validation-freeze.html: missing G6 IMP-04 amendment marker ' + marker);
}
for (const marker of ['id="g6-upgrade-rollback"', 'g6-imp-04-upgrade-rollback.html', '13 existing gates', 'check:upgrade-rollback-g6', 'G6 4/5', 'G6-IMP-05']) {
  if (!installOperations.includes(marker)) fail('a-ds-15-install-upgrade-migration-operations.html: missing G6 IMP-04 marker ' + marker);
}
const r129Start = apiAudit.indexOf('id="api-audit-r129"');
const r129End = apiAudit.indexOf('</section>', r129Start);
const r129Section = r129Start >= 0 && r129End > r129Start ? apiAudit.slice(r129Start, r129End) : '';
for (const marker of [
  'API-AUD-2026-08-26-R129-OBSERVED',
  'G6-IMP-04',
  '2.0.0-dev.74',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  '68131918db0fd91307a4341dfff684ccb549e53e5e635270a731531e1fd5b92b',
  '1.1.0',
  '공개 symbol 37개',
  '실제 route 160',
  'action 131',
  'capability 63',
  'role 18',
  'preset 11',
  'menu 59',
  'fixture 152·129·61·16·10·58',
  '6/7 통과',
  '13 existing gates',
  'active worker 16개',
  'recurring sweep 14개',
  'G6 4/5 완료',
  'Page 9·GET 38·POST 79',
  'npm test',
  '종료 코드 0',
  'G6-IMP-05',
  'Platform API change: 0',
]) {
  if (!r129Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R129 G6 marker ' + marker);
}
const upgradeRollbackEvidenceRow = apiAudit.indexOf('<tr><td><code>npm run check:upgrade-rollback-g6</code>');
const upgradeRollbackFullRegressionRow = apiAudit.indexOf('<tr><td><code>npm test</code> G6-IMP-04 최종 회귀', upgradeRollbackEvidenceRow);
const upgradeRollbackEvidenceBodyClose = apiAudit.indexOf('</tbody>', upgradeRollbackFullRegressionRow);
const upgradeRollbackAuditFooter = apiAudit.indexOf('<p class="footer">', upgradeRollbackFullRegressionRow);
if (
  upgradeRollbackEvidenceRow < 0
  || upgradeRollbackFullRegressionRow < upgradeRollbackEvidenceRow
  || upgradeRollbackEvidenceBodyClose < upgradeRollbackFullRegressionRow
  || upgradeRollbackAuditFooter < upgradeRollbackEvidenceBodyClose
) {
  fail('platform-api-implementation-audit.html: G6-IMP-04 evidence rows must remain inside the final evidence table before the footer');
}
for (const marker of ['check:upgrade-rollback-g6', 'scripts/check-upgrade-rollback-g6-imp-04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G6 IMP-04 marker ' + marker);
}
for (const marker of ['scripts/check-upgrade-rollback-g6-imp-04.ps1', '-SkipConstituentChecks', 'G6-IMP-04 upgrade and rollback passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G6 IMP-04 aggregate marker ' + marker);
}
for (const marker of [
  'SkipConstituentChecks',
  '$dimensionEvidence = [ordered]@{',
  "Invoke-Checked $node @('scripts\\run-tests.mjs')",
  'upgrade-rollback-g6-imp-04.php',
  'target schema',
  'supported upgrade',
  'rollback fail-closed',
  'reactivation',
  'worker cleanup',
  'evidence package boundary',
  '13 existing gates',
  'G6-IMP-04 passed:',
]) {
  if (!upgradeRollbackCheckG6Imp04.includes(marker)) fail('G6 IMP-04 gate: missing contract marker ' + marker);
}
for (const marker of [
  'g6_imp04_same( 13, count( $unique_evidence )',
  'Package add-on version is invalid.',
  "\"'1.0.4'\"",
  'g5_s02_assert_blocked_without_writes',
  'g6_imp04_same( 16, count( $workers )',
  'g6_imp04_same( 14, $recurring_workers',
  'oj_ai.g6_upgrade_rollback_evidence@1',
  'manifest.json',
  'checksums.sha256',
  "'$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_'",
  'CodingPanda\\\\OjPlatform\\\\Kernel\\\\',
  'G6-IMP-04 passed:',
]) {
  if (!upgradeRollbackContractG6Imp04.includes(marker)) fail('G6 IMP-04 PHP contract: missing marker ' + marker);
}
checkPhpFragments('G6 IMP-04 PHP contract', upgradeRollbackContractG6Imp04, [
  "'ADDON_API_RANGE' => '>=1.1.0 <2.0.0'",
  "'BASELINE_SCHEMA_VERSION' => '1.0.0'",
  "'PREVIOUS_SCHEMA_VERSION' => '1.0.1'",
  "g6_imp04_same( '1.0.4', \\OJAI\\Dashboard\\Tests\\Support\\Source_Contract_Tokens::string_constant( $catalog_source, 'SCHEMA_VERSION' )",
]);

for (const marker of ['id="amendment-14"', 'Amendment 14', 'Q39·Q40', 'Page 9·GET 40·POST 79', 'Platform API change']) {
  if (!crossValidation.includes(marker)) fail('A-DS-16 Amendment 14: missing marker ' + marker);
}
for (const marker of ['id="amendment-15"', 'Amendment 15', '0.1.53', '구형 학생 루브릭', 'Platform API change']) {
  if (!crossValidation.includes(marker)) fail('A-DS-16 Amendment 15: missing marker ' + marker);
}
for (const marker of ['id="amendment-16"', 'Amendment 16', '0.1.54', 'addon_ai_task_failed', 'Platform API change']) {
  if (!crossValidation.includes(marker)) fail('A-DS-16 Amendment 16: missing marker ' + marker);
}
for (const marker of ['id="amendment-17"', 'Amendment 17', '0.1.55', 'Worksheet_Quality_Prompt_Contract', 'OJAI_PLAYWRIGHT_MODULE', 'Platform API change']) {
  if (!crossValidation.includes(marker)) fail('A-DS-16 Amendment 17: missing marker ' + marker);
}
for (const marker of ['id="amendment-18"', 'Amendment 18', '0.1.56', 'C23', 'C26', 'Platform API change']) {
  if (!crossValidation.includes(marker)) fail('A-DS-16 Amendment 18: missing marker ' + marker);
}
for (const marker of ['id="amendment-19"', 'Amendment 19', '0.1.57', 'assignment.auto_submit_due', 'auto_submitted', 'Platform API change']) {
  if (!crossValidation.includes(marker)) fail('A-DS-16 Amendment 19: missing marker ' + marker);
}
for (const marker of ['id="amendment-20"', 'Amendment 20', '0.1.58', 'assessment_bindings', 'achievement_links', 'Platform API change']) {
  if (!crossValidation.includes(marker)) fail('A-DS-16 Amendment 20: missing marker ' + marker);
}
const boundaryFinalPackageG6Imp05 = await readFile(path.join(docsRoot, 'g6-imp-05-boundary-final-package.html'), 'utf8').catch(() => {
  fail('g6-imp-05-boundary-final-package.html: missing');
  return '';
});
const releaseChangelog = await readFile(path.join(docsRoot, 'release-changelog.html'), 'utf8').catch(() => {
  fail('release-changelog.html: missing');
  return '';
});
const worksheetPromptRecoveryReport = await readFile(path.join(docsRoot, 'worksheet-evaluation-prompt-recovery-2026-09-02.html'), 'utf8').catch(() => {
  fail('worksheet-evaluation-prompt-recovery-2026-09-02.html: missing');
  return '';
});
const submissionInboxRecoveryReport = await readFile(path.join(docsRoot, 'submission-evaluation-inbox-recovery-2026-09-02.html'), 'utf8').catch(() => {
  fail('submission-evaluation-inbox-recovery-2026-09-02.html: missing');
  return '';
});
const worksheetRubricRecoveryReport = await readFile(path.join(docsRoot, 'worksheet-evaluation-rubric-recovery-2026-09-02.html'), 'utf8').catch(() => {
  fail('worksheet-evaluation-rubric-recovery-2026-09-02.html: missing');
  return '';
});
const worksheetRetryRecoveryReport = await readFile(path.join(docsRoot, 'worksheet-evaluation-retry-token-recovery-2026-09-02.html'), 'utf8').catch(() => {
  fail('worksheet-evaluation-retry-token-recovery-2026-09-02.html: missing');
  return '';
});
const studentSubmitRecoveryReport = await readFile(path.join(docsRoot, 'student-submission-save-submit-recovery-2026-09-02.html'), 'utf8').catch(() => {
  fail('student-submission-save-submit-recovery-2026-09-02.html: missing');
  return '';
});
const automaticSubmissionRecoveryReport = await readFile(path.join(docsRoot, 'automatic-submission-worker-recovery-2026-09-02.html'), 'utf8').catch(() => {
  fail('automatic-submission-worker-recovery-2026-09-02.html: missing');
  return '';
});
const worksheetScopedAssessmentReport = await readFile(path.join(docsRoot, 'worksheet-scoped-assessment-learning-goals-2026-09-02.html'), 'utf8').catch(() => {
  fail('worksheet-scoped-assessment-learning-goals-2026-09-02.html: missing');
  return '';
});
const boundaryFinalPackageCheckG6Imp05 = await readFile(path.join(root, 'scripts', 'check-boundary-final-package-g6-imp-05.ps1'), 'utf8').catch(() => {
  fail('scripts/check-boundary-final-package-g6-imp-05.ps1: missing');
  return '';
});
const boundaryFinalPackageContractG6Imp05 = await readFile(path.join(root, 'tests', 'Contract', 'boundary-final-package-g6-imp-05.php'), 'utf8').catch(() => {
  fail('tests/Contract/boundary-final-package-g6-imp-05.php: missing');
  return '';
});
const boundaryFingerprintSource = await readFile(path.join(root, 'resources', 'contracts', 'release-boundary-fingerprint-v1.json'), 'utf8').catch(() => {
  fail('resources/contracts/release-boundary-fingerprint-v1.json: missing');
  return '{}';
});
const releaseBuilderSource = await readFile(path.join(root, 'scripts', 'build-release.ps1'), 'utf8');
const boundaryFingerprint = JSON.parse(boundaryFingerprintSource);
checkBalancedTags(boundaryFinalPackageG6Imp05, 'g6-imp-05-boundary-final-package.html');
checkBalancedTags(releaseChangelog, 'release-changelog.html');
checkBalancedTags(worksheetPromptRecoveryReport, 'worksheet-evaluation-prompt-recovery-2026-09-02.html');
checkBalancedTags(submissionInboxRecoveryReport, 'submission-evaluation-inbox-recovery-2026-09-02.html');
checkBalancedTags(worksheetRubricRecoveryReport, 'worksheet-evaluation-rubric-recovery-2026-09-02.html');
checkBalancedTags(worksheetRetryRecoveryReport, 'worksheet-evaluation-retry-token-recovery-2026-09-02.html');
checkBalancedTags(studentSubmitRecoveryReport, 'student-submission-save-submit-recovery-2026-09-02.html');
checkBalancedTags(automaticSubmissionRecoveryReport, 'automatic-submission-worker-recovery-2026-09-02.html');
checkBalancedTags(worksheetScopedAssessmentReport, 'worksheet-scoped-assessment-learning-goals-2026-09-02.html');
for (const marker of [
  'G6 · Final Acceptance · Implementation 05',
  'G6-IMP-05-S01',
  'G6-IMP-05-S05',
  'S01~S05 통과',
  'G6 5/5 완료',
  'source_dirty=false',
  '65c6827313d6812f326cf5153b09776fbe2dbf1ade13e6eb4bb7ccd288fbac7d',
  'c89f0221367a983650841832907b0e1e9c6b2d668c84f4377ebf356e6cf07263',
  '564911712420c44ff1707c87263c7f21573db4aebffe3ba3948065aca3870ae6',
  'fc0a96323f3c5596418559482e0a538844af2a90bf038db4d1aa801fb4c975a9',
  '4ada3f5c31ed8abf2360649f173b7dd72daef2d4f23c28b497fb140f40189b11',
  'b05943ad7c7fb7114ab4e5bc8c3b5092a5ce9947090947c65ce0ea0b8df4b04f',
  'e8818031c7eef5d3cf36ebdb84d66407f44b37c2fa413c1da43b68723b5382e6',
  '9ae0aa386d29ef2292a31d5bfddfd2fdd08ca17ea09652dfc56448405bb12de0',
  '0adae3bad5da667eb5faf286cc9c0db36bb3f93fb77d6f44c6cd5ac54d00b1fa',
  'f8a2dadc02fa514cc3d855b42cb47b271a989d1cef600e3f939a1606be20b072',
  '2a8d558abf3b2646652e0bc8c5cb94e24b417c435b4589ae9857eeac909b12be',
  '590c3cc48fb750bf0fa971ad00194c0fbf8cbbb69e17b08c1f4f1e084fba651f',
  'Platform API change: 0',
]) {
  if (!boundaryFinalPackageG6Imp05.includes(marker)) fail('G6 IMP-05 HTML: missing marker ' + marker);
}
for (let number = 1; number <= 5; number += 1) {
  const dimensionId = 'G6-IMP-05-S' + String(number).padStart(2, '0');
  if (!boundaryFinalPackageG6Imp05.includes(dimensionId)) fail('G6 IMP-05 HTML: missing dimension ' + dimensionId);
  if (!boundaryFinalPackageCheckG6Imp05.includes("'" + dimensionId + "'")) fail('G6 IMP-05 gate: missing dimension ' + dimensionId);
  if (!boundaryFinalPackageContractG6Imp05.includes("'" + dimensionId + "'")) fail('G6 IMP-05 contract: missing dimension ' + dimensionId);
}
for (const marker of [
  '0.1.58 · 활동지별 평가 요소·학습목표 연결',
  'worksheet-scoped-assessment-learning-goals-2026-09-02.html',
  '0.1.57 · 활동지 마감 자동 제출 복구',
  'automatic-submission-worker-recovery-2026-09-02.html',
  '0.1.56 · 학생 제출 저장·제출 순서 복구',
  'student-submission-save-submit-recovery-2026-09-02.html',
  '0.1.55 · 실행환경·계약 하드코딩 제거',
  '0.1.54 · 활동지 재점검·출력 한도 복구',
  'worksheet-evaluation-retry-token-recovery-2026-09-02.html',
  '0.1.53 · 활동지 자체 점검 루브릭 복구',
  'worksheet-evaluation-rubric-recovery-2026-09-02.html',
  '0.1.52 · 학생 제출 평가 작업함 복구',
  'submission-evaluation-inbox-recovery-2026-09-02.html',
  '0.1.51 · 활동지 자체 점검 프롬프트 자동 복구',
  'worksheet-evaluation-prompt-recovery-2026-09-02.html',
  '0.1.10 · 활동지 다중 문항 작성 UX 보완',
  '0.1.11 · 문항·답안 구조 및 메뉴 보완',
  '0.1.12 · 편집 화면과 응답 설정 동기화 보완',
  '0.1.16 · Linux 설치 ZIP 경로 보완',
  '0.1.15 · AI 작업 배정 상태 안내 보완',
  '0.1.9 · 활동지 작성 진입 안내 보완',
  'AI 활동지 작업 공간 보기',
  '활동지 만들기·편집',
  '0.1.7 · 플러그인 로드 순서 호환 릴리스',
  '0.1.5 · 설치 중단 복구 릴리스',
  'G6-IMP-05 S01~S05 통과',
  'G6-IMP-05-S05',
  'source_dirty=false',
  'Platform API change: 0',
]) {
  if (!releaseChangelog.includes(marker)) fail('release-changelog.html: missing marker ' + marker);
}
for (const marker of [
  'EVAL-PROMPT-REM-2026-09-02',
  'worksheet_quality_prompt.v3',
  'worksheet_evaluation_prompt_unavailable',
  'append-only policy upgrade',
  '수동 SQL 불필요',
  'Platform API change: 0',
]) {
  if (!worksheetPromptRecoveryReport.includes(marker)) fail('worksheet prompt recovery report: missing marker ' + marker);
}
for (const marker of [
  'SUBMISSION-INBOX-REM-2026-09-02',
  'submission-evaluation-candidates',
  '애드온 REST Q39 추가',
  '애드온 REST Q40 추가',
  'Add-on REST Q39·Q40',
  'Platform API change: 0',
]) {
  if (!submissionInboxRecoveryReport.includes(marker)) fail('submission inbox recovery report: missing marker ' + marker);
}
for (const marker of [
  'EVAL-RUBRIC-REM-2026-09-02',
  'grading_rubric.criteria[].levels',
  'assessment_configuration.student_rubric_contract_upgraded',
  '수동 SQL 불필요',
  'Platform API change: 0',
]) {
  if (!worksheetRubricRecoveryReport.includes(marker)) fail('worksheet rubric recovery report: missing marker ' + marker);
}
for (const marker of [
  'EVAL-RETRY-REM-2026-09-02',
  'worksheet_quality_prompt.v4',
  'addon_ai_task_failed',
  '고정 토큰 수치',
  '최신 run',
  '새 evaluation root',
  'Platform API change: 0',
]) {
  if (!worksheetRetryRecoveryReport.includes(marker)) fail('worksheet retry recovery report: missing marker ' + marker);
}
for (const marker of [
  'SUBMIT-ORDER-REM-2026-09-02',
  'saveNow()',
  'C23→C26',
  'oj-ai-dashboard-0.1.56.zip',
  'Platform API change: 0',
]) {
  if (!studentSubmitRecoveryReport.includes(marker)) fail('student submit recovery report: missing marker ' + marker);
}
for (const marker of [
  'AUTO-SUBMIT-REM-2026-09-02',
  'assignment.auto_submit_due',
  'auto_submitted',
  'oj-ai-dashboard-0.1.57.zip',
  '60초',
  'Platform API change: 0',
]) {
  if (!automaticSubmissionRecoveryReport.includes(marker)) fail('automatic submission recovery report: missing marker ' + marker);
}
for (const marker of [
  'WS-SCOPED-ASSESSMENT-2026-09-02',
  '0.1.58',
  'assessment_bindings',
  'achievement_links',
  '활동지 자체 평가 P04',
  '발행 P03',
  '학생 제출 평가',
  'Platform API change: 0',
]) {
  if (!worksheetScopedAssessmentReport.includes(marker)) fail('worksheet scoped assessment report: missing marker ' + marker);
}
if (boundaryFingerprint.contract !== 'oj_ai.release_boundary_fingerprint@1') fail('release boundary fingerprint: contract drifted');
if (boundaryFingerprint.algorithm !== 'sha256-path-nul-bytes-nul-v1') fail('release boundary fingerprint: algorithm drifted');
if (boundaryFingerprint.addon_version !== addonVersion) fail('release boundary fingerprint: add-on version drifted');
if (!supportedAddonApiVersion(boundaryFingerprint.platform_addon_api_version) || boundaryFingerprint.platform_addon_api_version !== platformAddonApiVersion) fail('release boundary fingerprint: platform API version drifted');
for (const marker of ['api-audit-r159', '0.1.55 실행환경·계약 하드코딩 제거', '95f00be2080cc22ccc4c14fd6036a0dbd06a565edf8d765a7592480919cbcbbe', 'Platform API change: 0']) {
  if (!apiAudit.includes(marker)) fail('platform API audit R159: missing marker ' + marker);
}
for (const marker of ['api-audit-r160', '0.1.56 학생 제출 저장·제출 순서 복구', 'c09bcd41909ea55438034e35558d2e81c93995bf0c32fa26efc585afdc37282e', 'Platform API change: 0']) {
  if (!apiAudit.includes(marker)) fail('platform API audit R160: missing marker ' + marker);
}
for (const marker of ['api-audit-r161', '0.1.57 활동지 마감 자동 제출 복구', 'd3c480d8dd581aeb339389908afc3533d864a40e024e0fbd51e7a46e592f335c', 'Platform API change: 0']) {
  if (!apiAudit.includes(marker)) fail('platform API audit R161: missing marker ' + marker);
}
for (const marker of ['api-audit-r162', '0.1.58 활동지별 평가 요소·학습목표 연결', 'settings.assessment_bindings', 'achievement_links', '11d972b4bf1b3b85cf6970677f3d4a864dce0c290293b33164855d3f01ae80da', 'Platform API change: 0']) {
  if (!apiAudit.includes(marker)) fail('platform API audit R162: missing marker ' + marker);
}

// The reviewed manifest is the single baseline; verify it against source bytes.
const boundaryVerification = spawnSync(process.execPath, [
  path.join(root, 'scripts/fingerprint-release-boundaries.mjs'),
  '--verify', path.join(root, 'resources/contracts/release-boundary-fingerprint-v1.json'),
  '--platform-root', platformRoot,
], { encoding: 'utf8', timeout: 30000, windowsHide: true });
if (boundaryVerification.error || boundaryVerification.status !== 0) {
  fail('release boundary fingerprint: source verification failed ' + (boundaryVerification.error?.message || boundaryVerification.stderr));
}
for (const source of [procedure, implementationRoadmap, indexDocument, crossValidation, installOperations]) {
  for (const marker of ['g6-imp-05-boundary-final-package.html', 'G6-IMP-05', 'S05', 'source_dirty=false']) {
    if (!source.includes(marker)) fail('canonical progress document: missing G6 IMP-05 marker ' + marker);
  }
}
for (const marker of ['id="amendment-13"', 'Amendment 13', 'G6-IMP-05-S01', 'G6-IMP-05-S05', 'check:boundary-final-package-g6', 'source_dirty=false']) {
  if (!crossValidation.includes(marker)) fail('a-ds-16-cross-validation-freeze.html: missing G6 IMP-05 amendment marker ' + marker);
}
const r130Start = apiAudit.indexOf('id="api-audit-r130"');
const r130End = apiAudit.indexOf('</section>', r130Start);
const r130Section = r130Start >= 0 && r130End > r130Start ? apiAudit.slice(r130Start, r130End) : '';
for (const marker of [
  'API-AUD-2026-08-26-R130-OBSERVED',
  'G6-IMP-05-S01',
  'G6-IMP-05-S03',
  'G6-IMP-05-S04',
  'G6-IMP-05-S05',
  '2.0.0-dev.74',
  'b5edecbfb54f0b6ecac1bcb75db9f7e0435216eb',
  'source_dirty=false',
  '14c3b2fea37a12fae90d6a338edd7df21a8d71841b4cf8f12cd08a8cd8933eae',
  'storage_path_not_writable',
  'Platform API change: 0',
]) {
  if (!r130Section.includes(marker)) fail('platform-api-implementation-audit.html: missing R130 marker ' + marker);
}
for (const marker of ['check:boundary-final-package-g6', 'scripts/check-boundary-final-package-g6-imp-05.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G6 IMP-05 marker ' + marker);
}
for (const marker of [
  'scripts/check-boundary-final-package-g6-imp-05.ps1',
  '-SkipConstituentChecks',
  '-SkipProductionPackage',
  'G6-IMP-05 S01-S04 prepackage checks passed',
]) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G6 IMP-05 aggregate marker ' + marker);
}
for (const marker of ['SkipProductionPackage', 'src/AddonApi/V1', 'fingerprint-release-boundaries.mjs', 'source_dirty=true']) {
  if (!boundaryFinalPackageCheckG6Imp05.includes(marker)) fail('G6 IMP-05 gate: missing marker ' + marker);
}
for (const marker of ['oj_ai.release_boundary_fingerprint@1', 'Runtime depends on an OJ Platform internal namespace', 'release-manifest.json', 'source_dirty=false']) {
  if (!boundaryFinalPackageContractG6Imp05.includes(marker)) fail('G6 IMP-05 PHP contract: missing marker ' + marker);
}
for (const marker of ['Release packaging requires source_dirty=false.', 'release-manifest.json', 'source_commit', 'source_dirty', 'System.Security.Cryptography.SHA256', '.zip.sha256', 'resources\\icons', 'lucide.svg', 'System.IO.Compression.ZipArchiveMode', 'CreateEntry', "entryName.Contains('\\')", 'oj-ai-dashboard/oj-ai-dashboard.php']) {
  if (!releaseBuilderSource.includes(marker)) fail('build-release.ps1: missing final package marker ' + marker);
}
if (releaseBuilderSource.includes('AllowDirty')) fail('build-release.ps1: dirty bypass must not exist');
if (releaseBuilderSource.includes('Get-FileHash')) fail('build-release.ps1: Windows PowerShell incompatible Get-FileHash must not be used');
if (releaseBuilderSource.includes('Compress-Archive')) fail('build-release.ps1: ZIP entries must use portable forward-slash paths');

const e2eScriptEntries = await readdir(path.join(root, 'scripts'), { withFileTypes: true });
const e2eBrowserScripts = e2eScriptEntries
  .filter((entry) => entry.isFile() && /^e2e-.*\.mjs$/.test(entry.name))
  .map((entry) => entry.name)
  .sort();
for (const filename of e2eBrowserScripts) {
  const source = await readFile(path.join(root, 'scripts', filename), 'utf8');
  if (!source.includes('playwright.chromium.launch')) continue;
  if (!source.includes("from './lib/playwright-runtime.mjs'")) {
    fail(filename + ': browser E2E must use the shared Playwright runtime loader');
  }
  if (source.includes('async function loadPlaywright')) {
    fail(filename + ': local Playwright loader must not be duplicated');
  }
  if (/[A-Za-z]:\\(?:Users|Program Files)|\/home\/[^/]+/.test(source)) {
    fail(filename + ': user-specific absolute execution path is forbidden');
  }
}
const worksheetWorkbenchContractSource = await readFile(path.join(root, 'tests', 'Contract', 'worksheet-workbench-s08.mjs'), 'utf8');
if (!worksheetWorkbenchContractSource.includes("process.env.OJAI_PHP || 'php'")) {
  fail('worksheet-workbench-s08.mjs: PHP runtime must use OJAI_PHP or PATH');
}
if (/[A-Za-z]:\\/.test(worksheetWorkbenchContractSource)) {
  fail('worksheet-workbench-s08.mjs: hard-coded Windows PHP path is forbidden');
}
const worksheetEvaluationUiSource = await readFile(path.join(root, 'resources', 'js', 'worksheet-evaluation-workbench.js'), 'utf8');
if (/4096 이상|MAX_TOKENS|출력 토큰 한도/.test(worksheetEvaluationUiSource)) {
  fail('worksheet-evaluation-workbench.js: provider text and fixed token guidance are forbidden');
}
if (!worksheetEvaluationUiSource.includes("run.last_error_code === 'addon_ai_task_failed'")) {
  fail('worksheet-evaluation-workbench.js: stable platform error-code handling is missing');
}
const worksheetPromptContractSource = await readFile(path.join(root, 'src', 'Worksheet', 'Evaluation', 'Domain', 'Worksheet_Quality_Prompt_Contract.php'), 'utf8');
for (const marker of ['worksheet_quality_prompt.v6', 'CONTENT_HASH', 'worksheet-quality-prompt-v6.txt']) {
  if (!worksheetPromptContractSource.includes(marker)) fail('worksheet prompt contract: missing marker ' + marker);
}

const legacyMigrationPreviewCheckSource = await readFile(path.join(root, 'scripts', 'check-legacy-migration-preview-g5-imp-07-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-legacy-migration-preview-g5-imp-07-s01.ps1: missing');
  return '';
});
for (const marker of ['check:uninstall-preservation-g5', 'scripts/check-uninstall-data-preservation-g5-imp-08-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-08-S01 uninstall marker ' + marker);
}
for (const marker of ['scripts/check-uninstall-data-preservation-g5-imp-08-s01.ps1', 'Uninstall preservation G5-IMP-08-S01 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-08-S01 aggregate marker ' + marker);
}
for (const marker of ['uninstall.php', 'uninstall-data-preservation-g5-imp-08-s01.php', 'release-package-boundary.php', 'G5-IMP-08-S01 passed:']) {
  if (!uninstallPreservationCheckSource.includes(marker)) fail('S01 uninstall gate: missing contract marker ' + marker);
}
const legacyMigrationApprovalCheckSource = await readFile(path.join(root, 'scripts', 'check-legacy-migration-approval-g5-imp-07-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-legacy-migration-approval-g5-imp-07-s02.ps1: missing');
  return '';
});
const legacyMigrationMappingCheckSource = await readFile(path.join(root, 'scripts', 'check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1'), 'utf8').catch(() => {
  fail('scripts/check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1: missing');
  return '';
});
const legacyMigrationPublicationCheckSource = await readFile(path.join(root, 'scripts', 'check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1'), 'utf8').catch(() => {
  fail('scripts/check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1: missing');
  return '';
});
const legacyMigrationAssignmentCheckSource = await readFile(path.join(root, 'scripts', 'check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1'), 'utf8').catch(() => {
  fail('scripts/check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1: missing');
  return '';
});
const legacyMigrationSubmissionCheckSource = await readFile(path.join(root, 'scripts', 'check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1'), 'utf8').catch(() => {
  fail('scripts/check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1: missing');
  return '';
});
const legacyMigrationEvaluationCheckSource = await readFile(path.join(root, 'scripts', 'check-legacy-migration-evaluation-g5-imp-07-s03-d3-d6.ps1'), 'utf8').catch(() => {
  fail('scripts/check-legacy-migration-evaluation-g5-imp-07-s03-d3-d6.ps1: missing');
  return '';
});
const submissionPdfSource = await readFile(path.join(root, 'scripts', 'check-submission-pdf-g3-imp-08.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-pdf-g3-imp-08.ps1: missing');
  return '';
});
const ownWorksheetPdfSource = await readFile(path.join(root, 'scripts', 'check-own-worksheet-pdf-g3-imp-08-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-own-worksheet-pdf-g3-imp-08-s02.ps1: missing');
  return '';
});
const ownSubmissionPdfSource = await readFile(path.join(root, 'scripts', 'check-own-submission-pdf-g3-imp-08-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-own-submission-pdf-g3-imp-08-s03.ps1: missing');
  return '';
});
const pdfIntegrationSource = await readFile(path.join(root, 'scripts', 'check-pdf-integration-g3-imp-08-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-pdf-integration-g3-imp-08-s04.ps1: missing');
  return '';
});
const submissionAssessmentInstructionSource = await readFile(path.join(root, 'scripts', 'check-submission-assessment-instruction-g4-imp-01-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-assessment-instruction-g4-imp-01-s01.ps1: missing');
  return '';
});
const submissionAssessmentInputSource = await readFile(path.join(root, 'scripts', 'check-submission-assessment-input-g4-imp-01-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-assessment-input-g4-imp-01-s02.ps1: missing');
  return '';
});
const submissionEvaluationEnqueueSource = await readFile(path.join(root, 'scripts', 'check-submission-evaluation-enqueue-g4-imp-01-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-evaluation-enqueue-g4-imp-01-s03.ps1: missing');
  return '';
});
const assignmentEvaluationEnqueueSource = await readFile(path.join(root, 'scripts', 'check-assignment-evaluation-enqueue-g4-imp-01-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-assignment-evaluation-enqueue-g4-imp-01-s04.ps1: missing');
  return '';
});
const submissionEvaluationFreezeSource = await readFile(path.join(root, 'scripts', 'check-submission-evaluation-freeze-g4-imp-01-s05.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-evaluation-freeze-g4-imp-01-s05.ps1: missing');
  return '';
});
const submissionEvaluationQueueLeaseSource = await readFile(path.join(root, 'scripts', 'check-submission-evaluation-queue-lease-g4-imp-02-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-evaluation-queue-lease-g4-imp-02-s01.ps1: missing');
  return '';
});
const submissionEvaluationWorkerSource = await readFile(path.join(root, 'scripts', 'check-submission-evaluation-worker-g4-imp-02-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-evaluation-worker-g4-imp-02-s02.ps1: missing');
  return '';
});
const submissionExternalAiConsentSource = await readFile(path.join(root, 'scripts', 'check-submission-external-ai-consent-g4-imp-02-r01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-external-ai-consent-g4-imp-02-r01.ps1: missing');
  return '';
});
const submissionEvaluationQuerySource = await readFile(path.join(root, 'scripts', 'check-submission-evaluation-query-g4-imp-02-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-evaluation-query-g4-imp-02-s03.ps1: missing');
  return '';
});
const submissionEvaluationControlSource = await readFile(path.join(root, 'scripts', 'check-submission-evaluation-control-g4-imp-02-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-evaluation-control-g4-imp-02-s04.ps1: missing');
  return '';
});
const submissionEvaluationIntegrationSource = await readFile(path.join(root, 'scripts', 'check-submission-evaluation-integration-g4-imp-02-s05.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-evaluation-integration-g4-imp-02-s05.ps1: missing');
  return '';
});
const submissionEvaluationReviewSource = await readFile(path.join(root, 'scripts', 'check-submission-evaluation-review-g4-imp-03-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-evaluation-review-g4-imp-03-s02.ps1: missing');
  return '';
});
const submissionEvaluationReviewIntegrationSource = await readFile(path.join(root, 'scripts', 'check-submission-evaluation-review-integration-g4-imp-03-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-evaluation-review-integration-g4-imp-03-s04.ps1: missing');
  return '';
});
const submissionFeedbackSource = await readFile(path.join(root, 'scripts', 'check-submission-feedback-g4-imp-04-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-feedback-g4-imp-04-s01.ps1: missing');
  return '';
});
const submissionFeedbackReviewSource = await readFile(path.join(root, 'scripts', 'check-submission-feedback-review-g4-imp-04-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-feedback-review-g4-imp-04-s02.ps1: missing');
  return '';
});
const submissionFeedbackReviewRepositorySource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Infrastructure', 'WordPress_Submission_Feedback_Review_Repository.php'), 'utf8').catch(() => {
  fail('WordPress_Submission_Feedback_Review_Repository.php: missing');
  return '';
});
const submissionFeedbackReviewRestSource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Presentation', 'Submission_Feedback_Review_Rest_Controller.php'), 'utf8').catch(() => {
  fail('Submission_Feedback_Review_Rest_Controller.php: missing');
  return '';
});
const submissionFeedbackReviewRestModuleSource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Presentation', 'Submission_Feedback_Review_Rest_Module.php'), 'utf8').catch(() => {
  fail('Submission_Feedback_Review_Rest_Module.php: missing');
  return '';
});
const submissionFeedbackApprovalSource = await readFile(path.join(root, 'scripts', 'check-submission-feedback-approval-g4-imp-04-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-feedback-approval-g4-imp-04-s03.ps1: missing');
  return '';
});
const submissionFeedbackIntegrationSource = await readFile(path.join(root, 'scripts', 'check-submission-feedback-integration-g4-imp-04-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-feedback-integration-g4-imp-04-s04.ps1: missing');
  return '';
});
const studentNoteDraftCheckSource = await readFile(path.join(root, 'scripts', 'check-student-note-draft-g4-imp-05-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-student-note-draft-g4-imp-05-s01.ps1: missing');
  return '';
});
const studentNoteQueryCheckSource = await readFile(path.join(root, 'scripts', 'check-student-note-query-g4-imp-05-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-student-note-query-g4-imp-05-s02.ps1: missing');
  return '';
});
const studentNoteReviewApprovalCheckSource = await readFile(path.join(root, 'scripts', 'check-student-note-review-approval-g4-imp-05-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-student-note-review-approval-g4-imp-05-s03.ps1: missing');
  return '';
});
const studentNoteReportInclusionCheckSource = await readFile(path.join(root, 'scripts', 'check-student-note-report-inclusion-g4-imp-05-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-student-note-report-inclusion-g4-imp-05-s04.ps1: missing');
  return '';
});

const studentNoteIntegrationCheckSource = await readFile(path.join(root, 'scripts', 'check-student-note-integration-g4-imp-05-s05.ps1'), 'utf8').catch(() => {
  fail('scripts/check-student-note-integration-g4-imp-05-s05.ps1: missing');
  return '';
});
const studentResultReleaseCheckSource = await readFile(path.join(root, 'scripts', 'check-submission-result-release-g4-imp-06-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-result-release-g4-imp-06-s01.ps1: missing');
  return '';
});
const studentResultQueryCheckSource = await readFile(path.join(root, 'scripts', 'check-submission-result-query-g4-imp-06-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-result-query-g4-imp-06-s02.ps1: missing');
  return '';
});
const approveSubmissionFeedbackSource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Application', 'Approve_Submission_Feedback.php'), 'utf8').catch(() => {
  fail('Approve_Submission_Feedback.php: missing');
  return '';
});
const studentResultWithdrawalCheckSource = await readFile(path.join(root, 'scripts', 'check-submission-result-withdrawal-g4-imp-06-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-result-withdrawal-g4-imp-06-s03.ps1: missing');
  return '';
});
const studentResultCorrectionCheckSource = await readFile(path.join(root, 'scripts', 'check-submission-result-correction-g4-imp-06-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-result-correction-g4-imp-06-s04.ps1: missing');
  return '';
});
const approveSubmissionFeedbackCommandSource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Application', 'Approve_Submission_Feedback_Command.php'), 'utf8').catch(() => {
  fail('Approve_Submission_Feedback_Command.php: missing');
  return '';
});
const studentResultUiCheckSource = await readFile(path.join(root, 'scripts', 'check-submission-result-ui-integration-g4-imp-06-s05.ps1'), 'utf8').catch(() => {
  fail('scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1: missing');
  return '';
});
const submissionFeedbackApprovalPortSource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Port', 'Submission_Feedback_Approval_Repository_Port_Interface.php'), 'utf8').catch(() => {
  fail('Submission_Feedback_Approval_Repository_Port_Interface.php: missing');
  return '';
});
const resultPrintProjectionCheckSource = await readFile(path.join(root, 'scripts', 'check-result-print-projection-g4-imp-07-s01.ps1'), 'utf8').catch(() => {
  fail('scripts/check-result-print-projection-g4-imp-07-s01.ps1: missing');
  return '';
});
const resultPrintBuilderSource = await readFile(path.join(root, 'src', 'Reporting', 'ResultPdf', 'Application', 'Build_Result_Print_Projection.php'), 'utf8').catch(() => {
  fail('Build_Result_Print_Projection.php: missing');
  return '';
});
const resultPrintAdapterSource = await readFile(path.join(root, 'src', 'Reporting', 'ResultPdf', 'Infrastructure', 'Q33_Own_Result_Pdf_Source_Query.php'), 'utf8').catch(() => {
  fail('Q33_Own_Result_Pdf_Source_Query.php: missing');
  return '';
});
const resultPrintContractSource = await readFile(path.join(root, 'tests', 'Contract', 'result-print-projection-g4-imp-07-s01.php'), 'utf8').catch(() => {
  fail('tests/Contract/result-print-projection-g4-imp-07-s01.php: missing');
  return '';
});
const resultPdfJobCheckSource = await readFile(path.join(root, 'scripts', 'check-result-pdf-job-g4-imp-07-s02.ps1'), 'utf8').catch(() => {
  fail('scripts/check-result-pdf-job-g4-imp-07-s02.ps1: missing');
  return '';
});
const resultPdfJobContractSource = await readFile(path.join(root, 'tests', 'Contract', 'result-pdf-job-g4-imp-07-s02.php'), 'utf8').catch(() => {
  fail('tests/Contract/result-pdf-job-g4-imp-07-s02.php: missing');
  return '';
});
const resultPdfStorageContractSource = await readFile(path.join(root, 'tests', 'Contract', 'result-pdf-storage-g4-imp-07-s02.php'), 'utf8').catch(() => {
  fail('tests/Contract/result-pdf-storage-g4-imp-07-s02.php: missing');
  return '';
});
const resultPdfRestSource = await readFile(path.join(root, 'src', 'Reporting', 'ResultPdf', 'Presentation', 'Result_Pdf_Rest_Controller.php'), 'utf8').catch(() => {
  fail('Result_Pdf_Rest_Controller.php: missing');
  return '';
});
const resultPdfSnapshotSource = await readFile(path.join(root, 'src', 'Reporting', 'ResultPdf', 'Domain', 'Result_Pdf_Request_Snapshot.php'), 'utf8').catch(() => {
  fail('Result_Pdf_Request_Snapshot.php: missing');
  return '';
});
const resultPdfWorkerSource = await readFile(path.join(root, 'src', 'Reporting', 'ResultPdf', 'Application', 'Run_Next_Result_Pdf.php'), 'utf8').catch(() => {
  fail('Run_Next_Result_Pdf.php: missing');
  return '';
});
const resultPdfRepositorySource = await readFile(path.join(root, 'src', 'Reporting', 'ResultPdf', 'Infrastructure', 'WordPress_Result_Pdf_Repository.php'), 'utf8').catch(() => {
  fail('WordPress_Result_Pdf_Repository.php: missing');
  return '';
});
const resultPdfSerializerSource = await readFile(path.join(root, 'src', 'Reporting', 'ResultPdf', 'Domain', 'Result_Print_Html_Serializer.php'), 'utf8').catch(() => {
  fail('Result_Print_Html_Serializer.php: missing');
  return '';
});
const resultPdfAccessCheckSource = await readFile(path.join(root, 'scripts', 'check-result-pdf-access-g4-imp-07-s03.ps1'), 'utf8').catch(() => {
  fail('scripts/check-result-pdf-access-g4-imp-07-s03.ps1: missing');
  return '';
});
const resultPdfAccessContractSource = await readFile(path.join(root, 'tests', 'Contract', 'result-pdf-access-g4-imp-07-s03.php'), 'utf8').catch(() => {
  fail('tests/Contract/result-pdf-access-g4-imp-07-s03.php: missing');
  return '';
});
const resultPdfUiCheckSource = await readFile(path.join(root, 'scripts', 'check-result-pdf-ui-integration-g4-imp-07-s04.ps1'), 'utf8').catch(() => {
  fail('scripts/check-result-pdf-ui-integration-g4-imp-07-s04.ps1: missing');
  return '';
});
const resultPdfUiContractSource = await readFile(path.join(root, 'tests', 'Contract', 'result-pdf-ui-g4-imp-07-s04.php'), 'utf8').catch(() => {
  fail('tests/Contract/result-pdf-ui-g4-imp-07-s04.php: missing');
  return '';
});
const resultPdfVisualFixtureSource = await readFile(path.join(root, 'tests', 'fixtures', 'generate-result-pdf-visual.php'), 'utf8').catch(() => {
  fail('tests/fixtures/generate-result-pdf-visual.php: missing');
  return '';
});
const exportDownloadSource = await readFile(path.join(root, 'src', 'Reporting', 'Export', 'Application', 'Export_Download.php'), 'utf8').catch(() => {
  fail('Export_Download.php: missing');
  return '';
});
const exportRestControllerSource = await readFile(path.join(root, 'src', 'Reporting', 'Export', 'Presentation', 'Export_Rest_Controller.php'), 'utf8').catch(() => {
  fail('Export_Rest_Controller.php: missing');
  return '';
});
const submissionFeedbackApprovalRestSource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Presentation', 'Submission_Feedback_Approval_Rest_Controller.php'), 'utf8').catch(() => {
  fail('Submission_Feedback_Approval_Rest_Controller.php: missing');
  return '';
});
const submissionFeedbackApprovalRestModuleSource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Presentation', 'Submission_Feedback_Approval_Rest_Module.php'), 'utf8').catch(() => {
  fail('Submission_Feedback_Approval_Rest_Module.php: missing');
  return '';
});
const submissionEvaluationReviewRepositorySource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Infrastructure', 'WordPress_Submission_Evaluation_Review_Repository.php'), 'utf8').catch(() => {
  fail('WordPress_Submission_Evaluation_Review_Repository.php: missing');
  return '';
});
const submissionEvaluationReviewRestSource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Presentation', 'Submission_Evaluation_Review_Rest_Controller.php'), 'utf8').catch(() => {
  fail('Submission_Evaluation_Review_Rest_Controller.php: missing');
  return '';
});
const submissionEvaluationReviewRestModuleSource = await readFile(path.join(root, 'src', 'Submission', 'Assessment', 'Presentation', 'Submission_Evaluation_Review_Rest_Module.php'), 'utf8').catch(() => {
  fail('Submission_Evaluation_Review_Rest_Module.php: missing');
  return '';
});
const compositionRootSource = await readFile(path.join(root, 'src', 'Core', 'Composition_Root.php'), 'utf8').catch(() => {
  fail('src/Core/Composition_Root.php: missing');
  return '';
});
for (const marker of ['check:submission-evaluation-review-g4', 'scripts/check-submission-evaluation-review-g4-imp-03-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-03-S02 aggregate marker ' + marker);
}
for (const marker of ['check:submission-evaluation-review-workbench-g4', 'scripts/check-submission-evaluation-review-workbench-g4-imp-03-s03.ps1', 'e2e:submission-review']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-03-S03 workbench marker ' + marker);
}
for (const marker of ['check:submission-evaluation-review-integration-g4', 'scripts/check-submission-evaluation-review-integration-g4-imp-03-s04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-03-S04 integration marker ' + marker);
}
for (const marker of ['check:submission-feedback-g4', 'scripts/check-submission-feedback-g4-imp-04-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-04-S01 feedback marker ' + marker);
}
for (const marker of ['check:submission-feedback-review-g4', 'scripts/check-submission-feedback-review-g4-imp-04-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-04-S02 feedback review marker ' + marker);
}
for (const marker of ['check:submission-feedback-approval-g4', 'scripts/check-submission-feedback-approval-g4-imp-04-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-04-S03 feedback approval marker ' + marker);
}
for (const marker of ['check:submission-feedback-integration-g4', 'scripts/check-submission-feedback-integration-g4-imp-04-s04.ps1', 'e2e:submission-feedback', 'scripts/e2e-submission-feedback-workbench.mjs']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-04-S04 feedback integration marker ' + marker);
}
for (const marker of ['check:student-note-draft-g4', 'scripts/check-student-note-draft-g4-imp-05-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-05-S01 student note marker ' + marker);
}
for (const marker of ['check:student-note-query-g4', 'scripts/check-student-note-query-g4-imp-05-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-05-S02 student note query marker ' + marker);
}
for (const marker of ['check:student-note-review-approval-g4', 'scripts/check-student-note-review-approval-g4-imp-05-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-05-S03 student note review/approval marker ' + marker);
}
for (const marker of ['check:student-note-report-inclusion-g4', 'scripts/check-student-note-report-inclusion-g4-imp-05-s04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-05-S04 student note report inclusion marker ' + marker);
}
for (const marker of ['scripts/check-submission-evaluation-review-g4-imp-03-s02.ps1', 'submission evaluation official review activation G4-IMP-03-S02 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-03-S02 aggregate marker ' + marker);
}
for (const marker of ['check:student-note-integration-g4', 'scripts/check-student-note-integration-g4-imp-05-s05.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-05-S05 student note integration marker ' + marker);
}
for (const marker of ['check:submission-result-release-g4', 'scripts/check-submission-result-release-g4-imp-06-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-06-S01 result release marker ' + marker);
}
for (const marker of ['check:submission-result-query-g4', 'scripts/check-submission-result-query-g4-imp-06-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-06-S02 result query marker ' + marker);
}
for (const marker of ['scripts/check-submission-evaluation-review-workbench-g4-imp-03-s03.ps1', 'submission evaluation score and evidence review workbench G4-IMP-03-S03 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-03-S03 workbench marker ' + marker);
}
for (const marker of ['scripts/check-submission-evaluation-review-integration-g4-imp-03-s04.ps1', '-SkipConstituentChecks', 'submission evaluation stale-source integration freeze G4-IMP-03-S04 passed']) {
for (const marker of ['check:submission-result-withdrawal-g4', 'scripts/check-submission-result-withdrawal-g4-imp-06-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-06-S03 result withdrawal marker ' + marker);
}
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-03-S04 integration marker ' + marker);
}
for (const marker of ['scripts/check-submission-feedback-g4-imp-04-s01.ps1', 'official-review feedback draft G4-IMP-04-S01 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-04-S01 feedback marker ' + marker);
}
for (const marker of ['scripts/check-submission-feedback-review-g4-imp-04-s02.ps1', 'teacher feedback review G4-IMP-04-S02 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-04-S02 feedback review marker ' + marker);
}
for (const marker of ['scripts/check-submission-feedback-approval-g4-imp-04-s03.ps1', 'exact teacher feedback approval G4-IMP-04-S03 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-04-S03 feedback approval marker ' + marker);
}
for (const marker of ['scripts/check-submission-feedback-integration-g4-imp-04-s04.ps1', '-SkipConstituentChecks', 'submission feedback workbench integration G4-IMP-04-S04 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-04-S04 feedback integration marker ' + marker);
}
for (const marker of ['scripts/check-student-note-draft-g4-imp-05-s01.ps1', 'student special note draft G4-IMP-05-S01 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-05-S01 student note marker ' + marker);
}
for (const marker of ['scripts/check-student-note-query-g4-imp-05-s02.ps1', 'teacher student-note query G4-IMP-05-S02 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-05-S02 student note query marker ' + marker);
}
for (const marker of ['scripts/check-student-note-review-approval-g4-imp-05-s03.ps1', 'Student-note teacher review and exact approval G4-IMP-05-S03 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-05-S03 student note review/approval marker ' + marker);
}
for (const marker of ['scripts/check-student-note-report-inclusion-g4-imp-05-s04.ps1', '-SkipConstituentChecks', 'Student-note report inclusion and exclusion G4-IMP-05-S04 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-05-S04 student note report inclusion marker ' + marker);
}
for (const marker of ['student-note-draft-g4-imp-05-s01.php', 'student-note-worker-g4-imp-05-s01.php', 'G4-IMP-05-S01 passed:', 'immutable excluded AI draft append']) {
  if (!studentNoteDraftCheckSource.includes(marker)) fail('scripts/check-student-note-draft-g4-imp-05-s01.ps1: missing contract marker ' + marker);
}
for (const marker of ['student-note-draft-g4-imp-05-s01.php', 'student-note-query-g4-imp-05-s02.php', 'G4-IMP-05-S02 passed:', 'zero student routes are frozen']) {
  if (!studentNoteQueryCheckSource.includes(marker)) fail('scripts/check-student-note-query-g4-imp-05-s02.ps1: missing contract marker ' + marker);
}
for (const marker of ['scripts/check-student-note-integration-g4-imp-05-s05.ps1', '-SkipConstituentChecks', 'Student-note provenance and authorization integration freeze G4-IMP-05-S05 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-05-S05 student note integration marker ' + marker);
}
for (const marker of ['scripts/check-submission-result-release-g4-imp-06-s01.ps1', 'Student result first release C43 G4-IMP-06-S01 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-06-S01 result release marker ' + marker);
}
for (const marker of ['scripts/check-submission-result-query-g4-imp-06-s02.ps1', 'Student own current result query Q33 G4-IMP-06-S02 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-06-S02 result query marker ' + marker);
}
for (const marker of ['submission-result-release-g4-imp-06-s01.php', 'submission-result-release-storage-g4-imp-06-s01.php', 'submission-result-release-rest-g4-imp-06-s01.php', 'G4-IMP-06-S01 passed:', 'body-free outbox', 'append-only history']) {
  if (!studentResultReleaseCheckSource.includes(marker)) fail('scripts/check-submission-result-release-g4-imp-06-s01.ps1: missing contract marker ' + marker);
}
for (const marker of ['submission-result-query-g4-imp-06-s02.php', 'G4-IMP-06-S02 passed:', 'owner-only current release', 'stale/withdrawn body suppression', 'PublicUI REST', 'literal tables are frozen']) {
for (const marker of ['scripts/check-submission-result-withdrawal-g4-imp-06-s03.ps1', '-SkipConstituentChecks', 'Student result release withdrawal C44 G4-IMP-06-S03 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-06-S03 result withdrawal marker ' + marker);
}
  if (!studentResultQueryCheckSource.includes(marker)) fail('scripts/check-submission-result-query-g4-imp-06-s02.ps1: missing contract marker ' + marker);
}
for (const marker of ['student-note-review-approval-g4-imp-05-s03.php', 'student-note-review-approval-storage-g4-imp-05-s03.php', 'student-note-review-approval-rest-g4-imp-05-s03.php', 'G4-IMP-05-S03 passed:', 'immutable revision append']) {
  if (!studentNoteReviewApprovalCheckSource.includes(marker)) fail('scripts/check-student-note-review-approval-g4-imp-05-s03.ps1: missing contract marker ' + marker);
}
for (const marker of ['param([switch]$SkipConstituentChecks)', 'student-note-report-inclusion-g4-imp-05-s04.php', 'student-note-report-inclusion-storage-g4-imp-05-s04.php', 'student-note-report-inclusion-rest-g4-imp-05-s04.php', 'G4-IMP-05-S04 passed:', 'immutable report inclusion toggle', 'approval metadata preservation']) {
  if (!studentNoteReportInclusionCheckSource.includes(marker)) fail('scripts/check-student-note-report-inclusion-g4-imp-05-s04.ps1: missing contract marker ' + marker);
for (const marker of ['submission-result-withdrawal-g4-imp-06-s03.php', 'submission-result-withdrawal-storage-g4-imp-06-s03.php', 'submission-result-withdrawal-rest-g4-imp-06-s03.php', 'G4-IMP-06-S03 passed:', 'immutable withdrawn revision', 'Q33 immediate suppression']) {
  if (!studentResultWithdrawalCheckSource.includes(marker)) fail('scripts/check-submission-result-withdrawal-g4-imp-06-s03.ps1: missing contract marker ' + marker);
}
}
for (const marker of ['check:worksheet-approval-publication-ui-s05', 'scripts/check-worksheet-approval-publication-ui-s05.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing S05 aggregate marker ' + marker);
}
for (const marker of ['scripts/check-worksheet-approval-publication-ui-s05.ps1', 'worksheet approval/publication lifecycle IMP-06-S01-S05']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing S05 aggregate marker ' + marker);
}
for (const marker of ['param([switch]$SkipConstituentChecks)', 'student-note-integration-g4-imp-05-s05.php', 'student-note-integration-storage-g4-imp-05-s05.php', 'G4-IMP-05-S05 passed:', 'Console-only authorization', 'single-winner concurrency', 'durable replay']) {
  if (!studentNoteIntegrationCheckSource.includes(marker)) fail('scripts/check-student-note-integration-g4-imp-05-s05.ps1: missing contract marker ' + marker);
}
for (const marker of ['check:submission-result-correction-g4', 'scripts/check-submission-result-correction-g4-imp-06-s04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-06-S04 result correction marker ' + marker);
}
for (const marker of ['scripts/check-submission-result-correction-g4-imp-06-s04.ps1', '-SkipConstituentChecks', 'Student result correction C43 G4-IMP-06-S04 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-06-S04 result correction marker ' + marker);
}
for (const marker of ['[switch]$SkipConstituentChecks', 'submission-result-correction-g4-imp-06-s04.php', 'submission-result-correction-storage-g4-imp-06-s04.php', 'submission-result-correction-rest-g4-imp-06-s04.php', 'G4-IMP-06-S04 passed:', 'immutable corrected revision', 'exact current and official source CAS', 'body-free outbox', 'submission_result.corrected']) {
  if (!studentResultCorrectionCheckSource.includes(marker)) fail('scripts/check-submission-result-correction-g4-imp-06-s04.ps1: missing contract marker ' + marker);
}
for (const marker of ['check:submission-result-ui-g4', 'scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-06-S05 result UI marker ' + marker);
}
for (const marker of ['scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1', '-SkipConstituentChecks', 'Student result PublicUI integration freeze G4-IMP-06-S05 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-06-S05 result UI marker ' + marker);
}
for (const marker of ['[switch]$SkipConstituentChecks', 'submission-result-ui-g4-imp-06-s05.php', 'G4-IMP-06-S05 passed:', 'Student_Result_Page_Handler', 'Q33 allowlist renderer', 'permission-loss body clearing', 'e2e-student-results.mjs']) {
  if (!studentResultUiCheckSource.includes(marker)) fail('scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1: missing contract marker ' + marker);
}
for (const marker of ['student-results.css', 'student-results.js', 'PUBLIC_RESULT']) {
  if (!studentResultUiCheckSource.includes(marker)) fail('scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1: missing asset integration marker ' + marker);
}
for (const marker of ['check:worksheet-print-s01', 'check:worksheet-pdf-s02', 'check:worksheet-package-s03', 'check:worksheet-pdf-package-s04', 'check:worksheet-pdf-package-g2', 'scripts/check-worksheet-print-projection-s01.ps1', 'scripts/check-worksheet-pdf-job-artifact-s02.ps1', 'scripts/check-worksheet-package-s03.ps1', 'scripts/check-worksheet-pdf-package-integration-s04.ps1', 'scripts/check-worksheet-pdf-package-g2-imp-07.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G2 worksheet PDF aggregate marker ' + marker);
}
for (const marker of ['scripts/check-worksheet-pdf-package-g2-imp-07.ps1', 'worksheet PDF/package IMP-07-S01-S04']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G2 worksheet PDF aggregate marker ' + marker);
}
for (const marker of ['check-worksheet-print-projection-s01.ps1', 'check-worksheet-pdf-job-artifact-s02.ps1', 'check-worksheet-package-s03.ps1', 'check-worksheet-pdf-package-integration-s04.ps1', 'aggregate passed through S04']) {
for (const marker of ['check:result-print-projection-g4', 'scripts/check-result-print-projection-g4-imp-07-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-07-S01 result print marker ' + marker);
}
for (const marker of ['scripts/check-result-print-projection-g4-imp-07-s01.ps1', 'Student result public PDF projection G4-IMP-07-S01 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-07-S01 result print marker ' + marker);
}
for (const marker of ['result-print-projection-g4-imp-07-s01.php', 'Read_Own_Submission_Result', 'Result PDF source authorization must reuse Q33 without parallel SQL.', 'G4-IMP-07-S01 must remain mutation-free.']) {
  if (!resultPrintProjectionCheckSource.includes(marker)) fail('scripts/check-result-print-projection-g4-imp-07-s01.ps1: missing contract marker ' + marker);
}
checkPhpFragments('Build_Result_Print_Projection.php', resultPrintBuilderSource, [
  "public const ARTIFACT_PROFILE_VERSION = 'result_print.v1'",
  "public const SCHEMA = 'oj_ai.result_print'",
  "public const PROFILE = 'student_result'",
  "public const PAGE_PROFILE = 'worksheet_a4@1'",
  "'type' => 'submission_release'",
  'Submission_Result_Projection::MAXIMUM_BYTES',
  'assert_public_projection',
]);
for (const marker of ['Read_Own_Submission_Result', 'find_current_for_actor', 'Submission_Evaluation_Failure::NOT_FOUND', 'Result_Pdf_Failure::SOURCE_INVALID']) {
  if (!resultPrintAdapterSource.includes(marker)) fail('Q33_Own_Result_Pdf_Source_Query.php: missing Q33 adapter marker ' + marker);
}
if (resultPrintAdapterSource.includes('SELECT ')) fail('Q33_Own_Result_Pdf_Source_Query.php: parallel SQL path found');
for (const marker of ['G4-IMP-07-S01 passed:', 'exact Q33 public projection', 'A stale current release', 'A withdrawn current release', 'A private field', 'student_note', 'mutation-free']) {
  if (!resultPrintContractSource.includes(marker)) fail('result-print-projection-g4-imp-07-s01.php: missing contract marker ' + marker);
}
  if (!worksheetPdfPackageSource.includes(marker)) fail('scripts/check-worksheet-pdf-package-g2-imp-07.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['check:result-pdf-job-g4', 'scripts/check-result-pdf-job-g4-imp-07-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-07-S02 result PDF job marker ' + marker);
}
for (const marker of ['scripts/check-result-pdf-job-g4-imp-07-s02.ps1', 'Student result private PDF C52 job G4-IMP-07-S02 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-07-S02 result PDF job marker ' + marker);
}
for (const marker of ['result-pdf-job-g4-imp-07-s02.php', 'result-pdf-storage-g4-imp-07-s02.php', 'oj_ai_export_jobs', 'LEASE_SECONDS = 300', 'RETENTION_SECONDS = 86400', 'put_quarantined', 'Forbidden add-on table prefix']) {
  if (!resultPdfJobCheckSource.includes(marker)) fail('scripts/check-result-pdf-job-g4-imp-07-s02.ps1: missing contract marker ' + marker);
}
for (const marker of ["REST_NAMESPACE = 'oj-ai/v1'", '/me/results/(?P<release_id>', "ACTION_ENQUEUE = 'result.pdf.enqueue'", "array( 'command_id', 'expected', 'payload' )", "array( 'locale', 'profile' )", "'artifact_kind' => 'result_pdf'"]) {
  if (!resultPdfRestSource.includes(marker)) fail('Result_Pdf_Rest_Controller.php: missing C52 marker ' + marker);
}
checkPhpFragments('Result_Pdf_Request_Snapshot.php', resultPdfSnapshotSource, ["SCHEMA = 'oj_ai.result_pdf_request'", "VERSION = '1.0.0'", 'MAX_BYTES = 1_048_576', 'Submission_Result_Projection::SCHEMA', 'Submission_Result_Projection_Builder::SCORE_VISIBILITIES', 'Submission_Result_Projection_Builder::FEEDBACK_VISIBILITIES']);
checkPhpFragments('Run_Next_Result_Pdf.php', resultPdfWorkerSource, ['LEASE_SECONDS = 300', 'RETENTION_SECONDS = 86400', 'MAX_PDF_BYTES = 104_857_600', 'MAX_PDF_PAGES = 500', '$this->authorized(', '$this->assert_same_snapshot(', 'put_quarantined', "'self_release' === $object_evidence->actor_relation()"]);
if ((resultPdfWorkerSource.match(/\$this->authorized\(/g) ?? []).length < 2 || (resultPdfWorkerSource.match(/\$this->assert_same_snapshot\(/g) ?? []).length < 2) {
  fail('Run_Next_Result_Pdf.php: generation authorization/current-release recheck must occur twice');
}
checkPhpFragments('WordPress_Result_Pdf_Repository.php', resultPdfRepositorySource, ["TABLE_JOBS = 'oj_ai_export_jobs'", "TABLE_ARTIFACTS = 'oj_ai_pdf_artifacts'", "TABLE_AUDIT = 'oj_ai_audit_events'", "TABLE_OUTBOX = 'oj_ai_outbox_events'"]);
for (const marker of ['START TRANSACTION', 'FOR UPDATE', 'active_identity_key', 'request_snapshot_hash']) {
  if (!resultPdfRepositorySource.includes(marker)) fail('WordPress_Result_Pdf_Repository.php: missing persistence marker ' + marker);
}
for (const marker of ['serialize( array $projection', "array( 'hidden', 'total', 'total_and_criteria' )", "array( 'hidden', 'shown' )", 'Hidden result score leaked values.', 'Hidden result feedback leaked values.', 'htmlspecialchars']) {
  if (!resultPdfSerializerSource.includes(marker)) fail('Result_Print_Html_Serializer.php: missing allowlist marker ' + marker);
}
for (const marker of ['G4-IMP-07-S02 passed:', 'strict C52', 'immutable result snapshot', '300-second lease', 'private PDF', 'double current-release authorization', 'Q23/Q24 self-release basis', 'oj_ai_export_jobs']) {
  if (!resultPdfJobContractSource.includes(marker)) fail('result-pdf-job-g4-imp-07-s02.php: missing contract marker ' + marker);
}
for (const marker of ['G4-IMP-07-S02 storage passed:', 'atomic enqueue', 'durable replay', 'exact 300-second lease expiry', 'stale-token CAS', 'failed completion', 'rollback', 'WordPress_Result_Pdf_Repository']) {
  if (!resultPdfStorageContractSource.includes(marker)) fail('result-pdf-storage-g4-imp-07-s02.php: missing contract marker ' + marker);
}
for (const marker of ['WordPress_Result_Pdf_Repository', 'Result_Pdf_Rest_Controller', 'Result_Pdf_Worker_Module', 'Enqueue_Own_Result_Pdf', 'Run_Next_Result_Pdf']) {
  if (!compositionRootSource.includes(marker)) fail('src/Core/Composition_Root.php: missing result PDF activation marker ' + marker);
}
for (const marker of ['check:result-pdf-access-g4', 'scripts/check-result-pdf-access-g4-imp-07-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-07-S03 result PDF access marker ' + marker);
}
for (const marker of ['scripts/check-result-pdf-access-g4-imp-07-s03.ps1', 'Student result Q23/Q24 revocation and pre-stream integrity G4-IMP-07-S03 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-07-S03 result PDF access marker ' + marker);
}
for (const marker of ['result-pdf-access-g4-imp-07-s03.php', "'result_pdf'", "'self_release'", 'same_download', 'verified_handle', 'LOCK_SH', "hash_init( 'sha256' )", 'read_artifact->execute']) {
  if (!resultPdfAccessCheckSource.includes(marker)) fail('scripts/check-result-pdf-access-g4-imp-07-s03.ps1: missing contract marker ' + marker);
}
for (const marker of ['G4-IMP-07-S03 passed:', 'current release Q23/Q24', 'correction and withdrawal revocation', 'exact expiry', 'other actor isolation', 'MIME/hash/bytes integrity', 'self_release PublicUI', 'pre-stream reauthorization']) {
  if (!resultPdfAccessContractSource.includes(marker)) fail('result-pdf-access-g4-imp-07-s03.php: missing contract marker ' + marker);
}
for (const marker of ['check:result-pdf-ui-g4', 'scripts/check-result-pdf-ui-integration-g4-imp-07-s04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 IMP-07-S04 result PDF UI marker ' + marker);
}
for (const marker of ['scripts/check-result-pdf-ui-integration-g4-imp-07-s04.ps1', 'Student result PDF UI and Korean A4 visual integration G4-IMP-07-S04 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 IMP-07-S04 result PDF UI marker ' + marker);
}
for (const marker of ['check:worksheet-report-list-g5', 'scripts/check-worksheet-report-list-g5-imp-01-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-01-S01 report list marker ' + marker);
}
for (const marker of ['scripts/check-worksheet-report-list-g5-imp-01-s01.ps1', 'Worksheet report list Q20 G5-IMP-01-S01 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-01-S01 report list marker ' + marker);
}
for (const marker of ['check:worksheet-report-detail-g5', 'scripts/check-worksheet-report-detail-g5-imp-01-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-01-S02 report detail marker ' + marker);
}
for (const marker of ['scripts/check-worksheet-report-detail-g5-imp-01-s02.ps1', 'Worksheet report detail Q21 G5-IMP-01-S02 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-01-S02 report detail marker ' + marker);
}
for (const marker of ['check:worksheet-report-filter-g5', 'scripts/check-worksheet-report-filter-denominator-g5-imp-01-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-01-S03 report filter marker ' + marker);
}
for (const marker of ['scripts/check-worksheet-report-filter-denominator-g5-imp-01-s03.ps1', 'Worksheet report canonical filters and denominators G5-IMP-01-S03 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-01-S03 report filter marker ' + marker);
}
for (const marker of ['check:report-dashboard-g5', 'e2e:reports', 'scripts/check-report-dashboard-g5-imp-01-s04.ps1', 'scripts/e2e-report-dashboard.mjs']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-01-S04 report dashboard marker ' + marker);
}
for (const marker of ['scripts/check-report-dashboard-g5-imp-01-s04.ps1', 'Worksheet report Q20 to Q21 Console dashboard G5-IMP-01-S04 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-01-S04 report dashboard marker ' + marker);
}
for (const marker of ['report-dashboard-g5-imp-01-s04.php', 'report-dashboard-g5-imp-01-s04.mjs', 'e2e-report-dashboard.mjs', 'SkipConstituentChecks', 'wp_oj_ai_', 'Addon_Capabilities::VIEW_REPORTS']) {
  if (!reportDashboardCheckSource.includes(marker)) fail('scripts/check-report-dashboard-g5-imp-01-s04.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:report-snapshot-g5', 'scripts/check-report-snapshot-g5-imp-02-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-02-S01 report snapshot marker ' + marker);
}
if (!runTestsSource.includes('scripts/check-report-snapshot-g5-imp-02-s01.ps1')) {
  fail('scripts/run-tests.mjs: missing G5 IMP-02-S01 report snapshot gate');
}
for (const marker of ['report-snapshot-create-g5-imp-02-s01.php', 'SkipConstituentChecks', 'MAXIMUM_ROWS = 10000', 'oj_ai_report_snapshots', 'wp_oj_ai_', 'Addon_Capabilities::VIEW_REPORTS', 'race_outbox_insert', 'G5-IMP-02-S01 passed:']) {
  if (!reportSnapshotCheckSource.includes(marker)) fail('scripts/check-report-snapshot-g5-imp-02-s01.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:report-snapshot-query-g5', 'scripts/check-report-snapshot-query-g5-imp-02-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-02-S02 report snapshot query marker ' + marker);
}
if (!runTestsSource.includes('scripts/check-report-snapshot-query-g5-imp-02-s02.ps1')) {
  fail('scripts/run-tests.mjs: missing G5 IMP-02-S02 report snapshot query gate');
}
for (const marker of ['report-snapshot-query-g5-imp-02-s02.php', 'SkipConstituentChecks', 'oj_ai_report_snapshots', 'wp_oj_ai_', 'Addon_Capabilities::VIEW_REPORTS', 'GET /oj-ai/v1/report-snapshots/{snapshot_id}', 'G5-IMP-02-S02 passed:']) {
  if (!reportSnapshotQueryCheckSource.includes(marker)) fail('scripts/check-report-snapshot-query-g5-imp-02-s02.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:report-source-manifest-g5', 'scripts/check-report-source-manifest-g5-imp-02-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-02-S03 source manifest marker ' + marker);
}
if (!runTestsSource.includes('scripts/check-report-source-manifest-g5-imp-02-s03.ps1')) {
  fail('scripts/run-tests.mjs: missing G5 IMP-02-S03 source manifest gate');
}
for (const marker of ['report-source-manifest-g5-imp-02-s03.php', 'SkipConstituentChecks', 'read_source_manifest', 'source_manifest_json', 'wp_oj_ai_', 'G5-IMP-02-S03 passed:']) {
  if (!reportSourceManifestCheckSource.includes(marker)) fail('scripts/check-report-source-manifest-g5-imp-02-s03.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:report-snapshot-integration-g5', 'scripts/check-report-snapshot-integration-g5-imp-02-s04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-02-S04 integration marker ' + marker);
}
if (!runTestsSource.includes('scripts/check-report-snapshot-integration-g5-imp-02-s04.ps1')) {
  fail('scripts/run-tests.mjs: missing G5 IMP-02-S04 integration gate');
}
for (const marker of ['report-snapshot-integration-g5-imp-02-s04.php', 'SkipConstituentChecks', 'report_source_stale', 'read_source_manifest', 'wp_oj_ai_', 'G5-IMP-02-S04 passed:']) {
  if (!reportSnapshotIntegrationCheckSource.includes(marker)) fail('scripts/check-report-snapshot-integration-g5-imp-02-s04.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:report-export-enqueue-g5', 'scripts/check-report-export-enqueue-g5-imp-03-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-03-S01 report export enqueue marker ' + marker);
}
for (const marker of ['scripts/check-report-export-enqueue-g5-imp-03-s01.ps1', 'Report CSV and PDF enqueue C46/C47 G5-IMP-03-S01 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-03-S01 report export enqueue aggregate marker ' + marker);
}
for (const marker of ['report-export-enqueue-g5-imp-03-s01.php', 'SkipConstituentChecks', 'report_csv.v1', 'report_print.v1', 'oj_ai_export_jobs', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'wp_oj_ai_', 'Addon_Capabilities::EXPORT_REPORTS', 'G5-IMP-03-S01 passed:']) {
  if (!reportExportEnqueueCheckSource.includes(marker)) fail('scripts/check-report-export-enqueue-g5-imp-03-s01.ps1: missing gate marker ' + marker);
}
for (const marker of ['result-pdf-ui-g4-imp-07-s04.php', 'generate-result-pdf-visual.php', 'e2e-student-results.mjs', '[switch]$SkipVisual', 'Poppler', 'System.Drawing', 'API-AUD-2026-08-24-R82-OBSERVED']) {
  if (!resultPdfUiCheckSource.includes(marker)) fail('scripts/check-result-pdf-ui-integration-g4-imp-07-s04.ps1: missing integration marker ' + marker);
}
for (const marker of ['check:submission-bundle-enqueue-g5', 'scripts/check-submission-bundle-enqueue-g5-imp-03-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-03-S02 submission bundle enqueue marker ' + marker);
}
for (const marker of ['scripts/check-submission-bundle-enqueue-g5-imp-03-s02.ps1', 'Submission bundle C50 G5-IMP-03-S02 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-03-S02 submission bundle aggregate marker ' + marker);
}
for (const marker of ['submission-bundle-enqueue-g5-imp-03-s02.php', 'SkipConstituentChecks', 'submission_bundle.v1', 'MAXIMUM_ARCHIVE_FILES = 500', 'oj_ai_export_jobs', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'wp_oj_ai_', 'Addon_Capabilities::EXPORT_REPORTS', 'G5-IMP-03-S02 passed:']) {
  if (!submissionBundleEnqueueCheckSource.includes(marker)) fail('scripts/check-submission-bundle-enqueue-g5-imp-03-s02.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:report-json-package-enqueue-g5', 'scripts/check-report-json-package-enqueue-g5-imp-03-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-03-S03 report JSON package marker ' + marker);
}
for (const marker of ['scripts/check-report-json-package-enqueue-g5-imp-03-s03.ps1', 'Report JSON package enqueue C69 G5-IMP-03-S03 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-03-S03 report JSON package aggregate marker ' + marker);
}
for (const marker of ['report-json-package-enqueue-g5-imp-03-s03.php', 'SkipConstituentChecks', 'report_json_package.v1', 'source_manifest_hash()', 'oj_ai_export_jobs', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'wp_oj_ai_', 'Addon_Capabilities::EXPORT_REPORTS', 'G5-IMP-03-S03 passed:']) {
  if (!reportJsonPackageEnqueueCheckSource.includes(marker)) fail('scripts/check-report-json-package-enqueue-g5-imp-03-s03.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:report-export-job-download-g5', 'scripts/check-report-export-job-download-g5-imp-03-s04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-03-S04 report export job/download marker ' + marker);
}
for (const marker of ['scripts/check-report-export-job-download-g5-imp-03-s04.ps1', 'Report export job and download Q23/Q24 G5-IMP-03-S04 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-03-S04 report export job/download aggregate marker ' + marker);
}
for (const marker of ['report-export-job-download-g5-imp-03-s04.php', 'SkipConstituentChecks', 'report_csv', 'report_pdf', 'report_json_package', 'submission_bundle', 'oj_ai_export_jobs', 'oj_ai_private_files', 'wp_oj_ai_', 'G5-IMP-03-S04 passed:']) {
  if (!reportExportJobDownloadCheckSource.includes(marker)) fail('scripts/check-report-export-job-download-g5-imp-03-s04.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:report-export-control-g5', 'scripts/check-report-export-control-g5-imp-03-s05.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-03-S05 report export control marker ' + marker);
}
for (const marker of ['scripts/check-report-export-control-g5-imp-03-s05.ps1', 'Export cancel C53 and retry C54 G5-IMP-03-S05 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-03-S05 report export control aggregate marker ' + marker);
}
for (const marker of ['report-export-control-g5-imp-03-s05.php', 'SkipConstituentChecks', 'oj_ai_export_jobs', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'export_job.retry_queued', 'export_job.retry_reused', '/commands/cancel', '/commands/retry', 'wp_oj_ai_', 'G5-IMP-03-S05 passed:']) {
  if (!reportExportControlCheckSource.includes(marker)) fail('scripts/check-report-export-control-g5-imp-03-s05.ps1: missing gate marker ' + marker);
}
for (const marker of ['G4-IMP-07-S04 UI contract passed:', 'normalized locale', 'exact C52', 'allowlisted Q23', 'private Q24', 'revocation clearing', 'expiry remake', 'responsive state controls']) {
  if (!resultPdfUiContractSource.includes(marker)) fail('result-pdf-ui-g4-imp-07-s04.php: missing contract marker ' + marker);
}
for (const marker of ['check:report-export-integration-g5', 'scripts/check-report-export-integration-g5-imp-03-s06.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-03-S06 report export integration marker ' + marker);
}
for (const marker of ['scripts/check-report-export-integration-g5-imp-03-s06.ps1', 'Report export checksum and authorization integration G5-IMP-03-S06 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-03-S06 report export integration aggregate marker ' + marker);
}
for (const marker of ['report-export-integration-freeze-g5-imp-03-s06.php', 'SkipConstituentChecks', 'same_download', 'verified_handle', 'oj_ai_export_jobs', 'oj_ai_private_files', 'wp_oj_ai_', 'G5-IMP-03-S06 passed:']) {
  if (!reportExportIntegrationCheckSource.includes(marker)) fail('scripts/check-report-export-integration-g5-imp-03-s06.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:operations-diagnostics-g5', 'scripts/check-operations-diagnostics-g5-imp-05-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-05-S01 operations diagnostics marker ' + marker);
}
for (const marker of ['scripts/check-operations-diagnostics-g5-imp-05-s01.ps1', 'Operations diagnostics Q28 Bridge, schema, and queue G5-IMP-05-S01 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-05-S01 operations diagnostics aggregate marker ' + marker);
}
for (const marker of ['operations-diagnostics-g5-imp-05-s01.php', 'SkipConstituentChecks', 'oj_ai_evaluation_runs', 'queue_stale_lease_detected', 'queue_failure_threshold_reached', 'SHELL_CONSOLE', 'wp_oj_ai_', 'G5-IMP-05-S01 passed:']) {
  if (!operationsDiagnosticsCheckSource.includes(marker)) fail('scripts/check-operations-diagnostics-g5-imp-05-s01.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:operations-diagnostics-g5-s02', 'scripts/check-operations-ai-routing-g5-imp-05-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-05-S02 AI routing diagnostics marker ' + marker);
}
for (const marker of ['scripts/check-operations-ai-routing-g5-imp-05-s02.ps1', 'Operations diagnostics Q28 AI routing G5-IMP-05-S02 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-05-S02 AI routing diagnostics aggregate marker ' + marker);
}
for (const marker of ['operations-ai-routing-g5-imp-05-s02.php', 'SkipConstituentChecks', 'AddonAiTaskGatewayV1', '->canRoute(', '->snapshot(', '->execute(', 'wp_oj_ai_', 'G5-IMP-05-S02 passed:']) {
  if (!operationsAiRoutingCheckSource.includes(marker)) fail('scripts/check-operations-ai-routing-g5-imp-05-s02.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:operations-diagnostics-g5-s03', 'scripts/check-operations-storage-g5-imp-05-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-05-S03 storage diagnostics marker ' + marker);
}
for (const marker of ['scripts/check-operations-storage-g5-imp-05-s03.ps1', 'Operations diagnostics Q28 storage and secret protection G5-IMP-05-S03 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-05-S03 storage diagnostics aggregate marker ' + marker);
}
for (const marker of ['operations-storage-g5-imp-05-s03.php', 'SkipConstituentChecks', 'oj_ai_private_files', 'Local_Private_Storage_Root', "'expired'", 'storage_key', 'wp_oj_ai_', 'G5-IMP-05-S03 passed:']) {
  if (!operationsStorageCheckSource.includes(marker)) fail('scripts/check-operations-storage-g5-imp-05-s03.ps1: missing gate marker ' + marker);
}
for (const marker of ['e2e:operations', 'check:operations-diagnostics-g5-s04', 'check:operations-diagnostics-g5', 'scripts/check-operations-diagnostics-ui-g5-imp-05-s04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-05-S04 operations UI marker ' + marker);
}
for (const marker of ['scripts/check-operations-diagnostics-ui-g5-imp-05-s04.ps1', 'Operations diagnostics UI and five-check regression G5-IMP-05-S04 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-05-S04 operations UI aggregate marker ' + marker);
}
for (const marker of [
  'operations-diagnostics-ui-g5-imp-05-s04.php',
  'operations-diagnostics-ui-g5-imp-05-s04.mjs',
  'e2e-operations-diagnostics.mjs',
  'SkipConstituentChecks',
  'wp_oj_ai_',
  'G5-IMP-05-S04 passed:',
]) {
  if (!operationsDiagnosticsUiCheckSource.includes(marker)) fail('scripts/check-operations-diagnostics-ui-g5-imp-05-s04.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:fresh-install-g5', 'scripts/check-fresh-install-g5-imp-06-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-06-S01 fresh installation marker ' + marker);
}
for (const marker of ['scripts/check-fresh-install-g5-imp-06-s01.ps1', 'Fresh installation G5-IMP-06-S01 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-06-S01 fresh installation aggregate marker ' + marker);
}
for (const marker of [
  'fresh-install-g5-imp-06-s01.php',
  'SkipConstituentChecks',
  'Activation_Preflight',
  'Local_Private_Storage_Root.php',
  'registration_pending',
  'wp_oj_ai_',
  'G5-IMP-06-S01 passed:',
]) {
  if (!freshInstallCheckSource.includes(marker)) fail('scripts/check-fresh-install-g5-imp-06-s01.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:sequential-upgrade-g5', 'scripts/check-sequential-upgrade-g5-imp-06-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-06-S02 sequential upgrade marker ' + marker);
}
for (const marker of ['scripts/check-sequential-upgrade-g5-imp-06-s02.ps1', '-SkipConstituentChecks', 'Sequential upgrade G5-IMP-06-S02 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-06-S02 sequential upgrade aggregate marker ' + marker);
}
for (const marker of [
  'sequential-upgrade-g5-imp-06-s02.php',
  'SkipConstituentChecks',
  'schema 1.0.2',
  'wp_oj_ai_',
  'R102',
  'g5-imp-06-s02-sequential-upgrade.html',
  'G5-IMP-06-S02 passed:',
]) {
  if (!sequentialUpgradeCheckSource.includes(marker)) fail('scripts/check-sequential-upgrade-g5-imp-06-s02.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:failure-restart-g5', 'scripts/check-failure-restart-g5-imp-06-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-06-S03 failure/restart marker ' + marker);
}
for (const marker of ['scripts/check-failure-restart-g5-imp-06-s03.ps1', '-SkipConstituentChecks', 'Failure state and restart G5-IMP-06-S03 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-06-S03 failure/restart aggregate marker ' + marker);
}
for (const marker of [
  'failure-restart-g5-imp-06-s03.php',
  'SkipConstituentChecks',
  'schema_checkpoint_hash_mismatch',
  'wp_oj_ai_',
  'R103',
  'g5-imp-06-s03-failure-restart.html',
  'G5-IMP-06-S03 passed:',
]) {
  if (!failureRestartCheckSource.includes(marker)) fail('scripts/check-failure-restart-g5-imp-06-s03.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:forward-repair-g5', 'scripts/check-forward-repair-g5-imp-06-s04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-06-S04 forward repair marker ' + marker);
}
for (const marker of ['scripts/check-forward-repair-g5-imp-06-s04.ps1', '-SkipConstituentChecks', '-SkipBrowser', 'Forward schema repair C75 G5-IMP-06-S04 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-06-S04 forward repair aggregate marker ' + marker);
}
for (const marker of [
  'schema-forward-repair-g5-imp-06-s04.php',
  'SkipConstituentChecks',
  'schema_repair_converged',
  'wp_oj_ai_',
  'R104',
  'g5-imp-06-s04-forward-repair.html',
  'G5-IMP-06-S04 passed:',
]) {
  if (!forwardRepairCheckSource.includes(marker)) fail('scripts/check-forward-repair-g5-imp-06-s04.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:data-preservation-g5', 'scripts/check-data-preservation-g5-imp-06-s05.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-06-S05 data preservation marker ' + marker);
}
for (const marker of ['scripts/check-data-preservation-g5-imp-06-s05.ps1', '-SkipConstituentChecks', 'Schema data preservation G5-IMP-06-S05 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-06-S05 data preservation aggregate marker ' + marker);
}
for (const marker of [
  'schema-data-preservation-g5-imp-06-s05.php',
  'SkipConstituentChecks',
  'check-forward-repair-g5-imp-06-s04.ps1',
  'check-assessment-configuration-bootstrap-s01.ps1',
  'oj_ai_private_files',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'R105',
  'g5-imp-06-s05-data-preservation.html',
  'G5-IMP-06-S05 passed:',
]) {
  if (!dataPreservationCheckSource.includes(marker)) fail('scripts/check-data-preservation-g5-imp-06-s05.ps1: missing gate marker ' + marker);
}
for (const marker of ['Result_Print_Html_Serializer', 'Dompdf_Html_To_Pdf_Renderer', 'S04_RESULT_PRIVATE_TEACHER_MEMO', 'page_count() < 2']) {
for (const marker of ['check:legacy-migration-preview-g5', 'scripts/check-legacy-migration-preview-g5-imp-07-s01.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-07-S01 legacy migration preview marker ' + marker);
}
for (const marker of ['scripts/check-legacy-migration-preview-g5-imp-07-s01.ps1', '-SkipConstituentChecks', 'Legacy migration preview C61 G5-IMP-07-S01 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-07-S01 aggregate marker ' + marker);
}
for (const marker of [
  'legacy-migration-preview-g5-imp-07-s01.php',
  'SkipConstituentChecks',
  'check-data-preservation-g5-imp-06-s05.ps1',
  'oj_ai_dashboard_wpdb_prefixed@0.1.2',
  '$database->prefix',
  'information_schema.TABLES',
  'TTL_SECONDS = 1800',
  'MAXIMUM_TOKEN_BYTES = 4096',
  'mutation_count',
  'ledger_mutation_count',
  'platform_write_count',
  'R106',
  'g5-imp-07-s01-legacy-migration-preview.html',
  'G5-IMP-07-S01 passed:',
]) {
  if (!legacyMigrationPreviewCheckSource.includes(marker)) fail('scripts/check-legacy-migration-preview-g5-imp-07-s01.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:legacy-migration-approval-g5', 'scripts/check-legacy-migration-approval-g5-imp-07-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-07-S02 legacy migration approval marker ' + marker);
}
for (const marker of ['scripts/check-legacy-migration-approval-g5-imp-07-s02.ps1', '-SkipConstituentChecks', 'Legacy migration approval C70 G5-IMP-07-S02 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-07-S02 aggregate marker ' + marker);
}
for (const marker of [
  'legacy-migration-approval-g5-imp-07-s02.php',
  'SkipConstituentChecks',
  'check-legacy-migration-preview-g5-imp-07-s01.ps1',
  'oj_ai.legacy_migration_plan@1.0.0',
  'oj_ai_import_runs',
  'oj_ai_audit_events',
  'oj_ai_outbox_events',
  'TOKEN_EXPIRED',
  'SOURCE_STALE',
  'START TRANSACTION',
  'ROLLBACK',
  'R107',
  'g5-imp-07-s02-legacy-migration-approval.html',
  'G5-IMP-07-S02 passed:',
]) {
  if (!legacyMigrationApprovalCheckSource.includes(marker)) fail('scripts/check-legacy-migration-approval-g5-imp-07-s02.ps1: missing gate marker ' + marker);
}
  if (!resultPdfVisualFixtureSource.includes(marker)) fail('generate-result-pdf-visual.php: missing visual marker ' + marker);
}
for (const marker of ['check:legacy-migration-mapping-g5', 'scripts/check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-07-S03-D3-D2 mapping marker ' + marker);
}
for (const marker of ['scripts/check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1', '-SkipConstituentChecks', 'Legacy migration mapping G5-IMP-07-S03-D3-D2 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-07-S03-D3-D2 aggregate marker ' + marker);
}
for (const marker of [
  'legacy-migration-mapping-g5-imp-07-s03-d3-d2.php',
  'SkipConstituentChecks',
  'check-legacy-migration-approval-g5-imp-07-s02.ps1',
  'oj_ai.legacy_migration_plan@1.2.0',
  'oj_ai.legacy_migration_plan@1.1.0',
  'assignment_source_ref',
  'Legacy_Mapping_Manifest.php',
  'Platform_Legacy_Mapping_Decision_Validator.php',
  'mapping_validator->validate',
  'membership_revision',
  'G5-IMP-07-S03-D3-D2',
  'g5-imp-07-s03-d3-d-owner-migration.html',
  'check:legacy-migration-mapping-g5',
  'Platform API change: 0',
]) {
  if (!legacyMigrationMappingCheckSource.includes(marker)) fail('scripts/check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:legacy-migration-publication-g5', 'scripts/check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-07-S03-D3-D3 publication marker ' + marker);
}
for (const marker of ['scripts/check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1', '-SkipConstituentChecks', 'Legacy migration publication G5-IMP-07-S03-D3-D3 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-07-S03-D3-D3 aggregate marker ' + marker);
}
for (const marker of [
  'legacy-migration-publication-g5-imp-07-s03-d3-d3.php',
  'SkipConstituentChecks',
  'check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1',
  'Legacy_Migration_Publication_Projector.php',
  'WordPress_Legacy_Migration_Publication_Writer.php',
  'Legacy_Migration_Publication_Writer_Port_Interface.php',
  'G5-IMP-07-S03-D3-D3',
  'g5-imp-07-s03-d3-d-owner-migration.html',
  'check:legacy-migration-publication-g5',
  'API-AUD-2026-08-25-R109-OBSERVED',
  'Platform API change: 0',
]) {
  if (!legacyMigrationPublicationCheckSource.includes(marker)) fail('scripts/check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:legacy-migration-assignment-g5', 'scripts/check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-07-S03-D3-D4 assignment marker ' + marker);
}
for (const marker of ['scripts/check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1', '-SkipConstituentChecks', 'Legacy migration assignment G5-IMP-07-S03-D3-D4 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-07-S03-D3-D4 aggregate marker ' + marker);
}
for (const marker of ['check:legacy-migration-evaluation-g5', 'scripts/check-legacy-migration-evaluation-g5-imp-07-s03-d3-d6.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-07-S03-D3-D6 evaluation marker ' + marker);
}
for (const marker of ['scripts/check-legacy-migration-evaluation-g5-imp-07-s03-d3-d6.ps1', '-SkipConstituentChecks', 'Legacy migration evaluation G5-IMP-07-S03-D3-D6 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-07-S03-D3-D6 aggregate marker ' + marker);
}
for (const marker of [
  'legacy-migration-evaluation-g5-imp-07-s03-d3-d6.php',
  'SkipConstituentChecks',
  'check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1',
  'Legacy_Migration_Evaluation_Projector.php',
  'WordPress_Legacy_Migration_Evaluation_Writer.php',
  'Legacy_Migration_Evaluation_Writer_Port_Interface.php',
  'G5-IMP-07-S03-D3-D6',
  'g5-imp-07-s03-d3-d-owner-migration.html',
  'check:legacy-migration-evaluation-g5',
  'API-AUD-2026-08-26-R112-OBSERVED',
  'Platform API change: 0',
]) {
  if (!legacyMigrationEvaluationCheckSource.includes(marker)) fail('scripts/check-legacy-migration-evaluation-g5-imp-07-s03-d3-d6.ps1: missing gate marker ' + marker);
}
for (const marker of [
  'legacy-migration-assignment-g5-imp-07-s03-d3-d4.php',
  'SkipConstituentChecks',
  'check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1',
  'check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1',
  'WordPress_Legacy_Migration_Assignment_Writer.php',
  'Legacy_Migration_Assignment_Writer_Port_Interface.php',
  'oj_ai.legacy_migration_plan@1.2.0',
  'assignment_source_ref',
  'G5-IMP-07-S03-D3-D4',
  'g5-imp-07-s03-d3-d-owner-migration.html',
  'check:legacy-migration-assignment-g5',
  'API-AUD-2026-08-25-R110-OBSERVED',
  'Platform API change: 0',
]) {
  if (!legacyMigrationAssignmentCheckSource.includes(marker)) fail('scripts/check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1: missing gate marker ' + marker);
}
for (const marker of ['check:legacy-migration-submission-g5', 'scripts/check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G5 IMP-07-S03-D3-D5 submission marker ' + marker);
}
for (const marker of ['scripts/check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1', '-SkipConstituentChecks', 'Legacy migration submission G5-IMP-07-S03-D3-D5 passed.']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G5 IMP-07-S03-D3-D5 aggregate marker ' + marker);
}
for (const marker of [
  'legacy-migration-submission-g5-imp-07-s03-d3-d5.php',
  'SkipConstituentChecks',
  'check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1',
  'check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1',
  'check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1',
  'Legacy_Migration_Submission_Response_Projector.php',
  'WordPress_Legacy_Migration_Submission_Writer.php',
  'Legacy_Migration_Submission_Writer_Port_Interface.php',
  'G5-IMP-07-S03-D3-D5',
  'g5-imp-07-s03-d3-d-owner-migration.html',
  'check:legacy-migration-submission-g5',
  'API-AUD-2026-08-26-R111-OBSERVED',
  'Platform API change: 0',
]) {
  if (!legacyMigrationSubmissionCheckSource.includes(marker)) fail('scripts/check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1: missing gate marker ' + marker);
}
for (const marker of ["'result_pdf'", "'application/pdf'", "'.pdf'", '104_857_600']) {
  if (!exportDownloadSource.includes(marker)) fail('Export_Download.php: missing result PDF profile marker ' + marker);
}
for (const marker of ["'self_release'", 'read_artifact->execute', 'same_download', 'verified_handle', 'LOCK_SH', "hash_init( 'sha256' )", '64 * 1024']) {
  if (!exportRestControllerSource.includes(marker)) fail('Export_Rest_Controller.php: missing pre-stream authorization/integrity marker ' + marker);
}
const exportReadExecutions = exportRestControllerSource.match(/read_artifact->execute/g) || [];
if (exportReadExecutions.length < 2) {
  fail('Export_Rest_Controller.php: download must reauthorize immediately before streaming');
}
for (const marker of ['check:assignment-g3', 'scripts/check-assignment-g3-imp-01.ps1', 'check:assignment-g3-lifecycle', 'scripts/check-assignment-lifecycle-g3-imp-02.ps1', 'check:assignment-g3-student', 'scripts/check-student-assignment-g3-imp-03.ps1', 'e2e:assignment', 'e2e:student']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G3 aggregate marker ' + marker);
}
for (const marker of ['scripts/check-assignment-g3-imp-01.ps1', 'scripts/check-assignment-lifecycle-g3-imp-02.ps1', 'scripts/check-student-assignment-g3-imp-03.ps1', 'assignment delivery G3-IMP-01 through G3-IMP-03']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G3 aggregate marker ' + marker);
}

for (const content of [previewS02, safetyS03, uiS04, qualityInstructionS01, evaluationInputS02, evaluationEnqueueS03, evaluationWorkerS04, evaluationQueryS05, evaluationControlS06, evaluationReviewS07, evaluationWorkbenchS08, evaluationFreezeS09, externalAiConsentR01, approvalPolicyS01, approvalCommandS02, publicationS03, lifecycleS04, approvalPublicationUiS05, worksheetPrintProjectionS01, worksheetPdfJobArtifactS02, worksheetPackageS03, worksheetPdfPackageFreezeS04, submissionZipStage, submissionZipScan, submissionZipMapping, submissionZipFinalization, submissionZipFreeze, submissionPdfS01, learnerWorksheetPdfS02, learnerSubmissionPdfS03, pdfIntegrationFreezeS04, submissionAssessmentInstructionS01, submissionAssessmentInputS02, submissionEvaluationEnqueueS03, assignmentEvaluationEnqueueS04, submissionEvaluationFreezeS05, submissionEvaluationQueueLeaseS01, submissionEvaluationWorkerS02, submissionEvaluationQueryS03, submissionEvaluationControlS04, submissionEvaluationIntegrationS05, submissionEvaluationReviewIntegrationS04, submissionFeedbackDraftS01, studentNoteDraftS01, studentNoteQueryS02, studentNoteReviewApprovalS03, studentNoteReportInclusionS04, studentResultReleaseS01, studentResultCorrectionS04, studentResultUiS05, resultPrintProjectionS01, resultPdfJobS02, resultPdfAccessS03, resultPdfUiS04, worksheetReportListS01, reportSnapshotS01, reportSnapshotS02, reportSourceManifestS03, reportSnapshotIntegrationS04, reportExportEnqueueS01, reportExportControlS05]) {
  if (content.includes('$wpdb->prefix')) {
    fail('implementation docs: unescaped WordPress prefix expression found');
  }
}
for (const marker of ['check:submission-zip-g3', 'scripts/check-submission-zip-g3-imp-07.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing submission ZIP aggregate marker ' + marker);
}
for (const marker of ['scripts/check-submission-zip-g3-imp-07.ps1', 'submission ZIP G3-IMP-07-S01-S05']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing submission ZIP aggregate marker ' + marker);
}
for (const marker of ['check:submission-pdf-g3', 'scripts/check-submission-pdf-g3-imp-08.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing submission PDF aggregate marker ' + marker);
}
for (const marker of ['scripts/check-submission-pdf-g3-imp-08.ps1', 'teacher submission PDF G3-IMP-08-S01-G passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing submission PDF aggregate marker ' + marker);
}
for (const marker of ['submission-pdf-worker-g3-imp-08-s01.php', 'submission-pdf-query-download-g3-imp-08-s01.php', 'Run_Next_Submission_Pdf.php', 'Submission_Pdf_Worker_Module.php', 'check-submission-pdf-integration-s01-g.ps1', 'aggregate passed through S01-G']) {
  if (!submissionPdfSource.includes(marker)) fail('scripts/check-submission-pdf-g3-imp-08.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['check:own-worksheet-pdf-g3', 'scripts/check-own-worksheet-pdf-g3-imp-08-s02.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing own worksheet PDF aggregate marker ' + marker);
}
if (!runTestsSource.includes('scripts/check-own-worksheet-pdf-g3-imp-08-s02.ps1')) {
  fail('scripts/run-tests.mjs: missing own worksheet PDF aggregate marker');
}
for (const marker of ['own-worksheet-pdf-enqueue-worker-g3-imp-08-s02.php', 'own-worksheet-pdf-query-download-g3-imp-08-s02.php', 'G3-IMP-08-S02 passed']) {
  if (!ownWorksheetPdfSource.includes(marker)) fail('scripts/check-own-worksheet-pdf-g3-imp-08-s02.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['check:own-submission-pdf-g3', 'scripts/check-own-submission-pdf-g3-imp-08-s03.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing own submission PDF aggregate marker ' + marker);
}
if (!runTestsSource.includes('scripts/check-own-submission-pdf-g3-imp-08-s03.ps1')) {
  fail('scripts/run-tests.mjs: missing own submission PDF aggregate marker');
}
for (const marker of ['own-submission-pdf-enqueue-worker-g3-imp-08-s03.php', 'own-submission-pdf-query-download-g3-imp-08-s03.php', 'G3-IMP-08-S03 passed']) {
  if (!ownSubmissionPdfSource.includes(marker)) fail('scripts/check-own-submission-pdf-g3-imp-08-s03.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['check:pdf-integration-g3', 'scripts/check-pdf-integration-g3-imp-08-s04.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing PDF integration aggregate marker ' + marker);
}
for (const marker of ['scripts/check-pdf-integration-g3-imp-08-s04.ps1', '-SkipConstituentChecks', '-SkipVisual', 'PDF integration G3-IMP-08-S04 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing PDF integration aggregate marker ' + marker);
}
for (const marker of ['pdf-profile-integration-freeze-g3-imp-08-s04.php', 'check-submission-pdf-g3-imp-08.ps1', 'check-own-worksheet-pdf-g3-imp-08-s02.ps1', 'check-own-submission-pdf-g3-imp-08-s03.ps1', 'check-worksheet-pdf-package-integration-s04.ps1', 'e2e-student-assignments.mjs', 'G3-IMP-08-S04 passed']) {
  if (!pdfIntegrationSource.includes(marker)) fail('scripts/check-pdf-integration-g3-imp-08-s04.ps1: missing aggregate marker ' + marker);
}



for (const marker of ['check:submission-assessment-instruction-g4', 'scripts/check-submission-assessment-instruction-g4-imp-01-s01.ps1', 'check:submission-assessment-input-g4', 'scripts/check-submission-assessment-input-g4-imp-01-s02.ps1', 'check:submission-evaluation-enqueue-g4', 'scripts/check-submission-evaluation-enqueue-g4-imp-01-s03.ps1', 'check:assignment-evaluation-enqueue-g4', 'scripts/check-assignment-evaluation-enqueue-g4-imp-01-s04.ps1', 'check:submission-evaluation-freeze-g4', 'scripts/check-submission-evaluation-freeze-g4-imp-01-s05.ps1', 'check:submission-evaluation-queue-lease-g4', 'scripts/check-submission-evaluation-queue-lease-g4-imp-02-s01.ps1', 'check:submission-evaluation-worker-g4', 'scripts/check-submission-evaluation-worker-g4-imp-02-s02.ps1', 'check:submission-external-ai-consent-g4', 'scripts/check-submission-external-ai-consent-g4-imp-02-r01.ps1', 'check:submission-evaluation-query-g4', 'scripts/check-submission-evaluation-query-g4-imp-02-s03.ps1', 'check:submission-evaluation-control-g4', 'scripts/check-submission-evaluation-control-g4-imp-02-s04.ps1', 'check:submission-evaluation-integration-g4', 'scripts/check-submission-evaluation-integration-g4-imp-02-s05.ps1']) {
  if (!packageManifestSource.includes(marker)) fail('package.json: missing G4 S01-S05 aggregate marker ' + marker);
}
for (const marker of ['scripts/check-submission-assessment-instruction-g4-imp-01-s01.ps1', 'scripts/check-submission-assessment-input-g4-imp-01-s02.ps1', 'scripts/check-submission-evaluation-enqueue-g4-imp-01-s03.ps1', 'scripts/check-assignment-evaluation-enqueue-g4-imp-01-s04.ps1', 'scripts/check-submission-evaluation-freeze-g4-imp-01-s05.ps1', 'scripts/check-submission-evaluation-queue-lease-g4-imp-02-s01.ps1', 'scripts/check-submission-evaluation-worker-g4-imp-02-s02.ps1', 'scripts/check-submission-external-ai-consent-g4-imp-02-r01.ps1', 'scripts/check-submission-evaluation-query-g4-imp-02-s03.ps1', 'scripts/check-submission-evaluation-control-g4-imp-02-s04.ps1', 'scripts/check-submission-evaluation-integration-g4-imp-02-s05.ps1', 'submission evaluation queue/lease G4-IMP-02-S01 passed', 'submission evaluation Gateway/output/candidate transaction G4-IMP-02-S02 passed', 'student submission external AI consent G4-IMP-02-R01 passed', 'submission evaluation query G4-IMP-02-S03 passed', 'submission evaluation retry/cancel G4-IMP-02-S04 passed', 'submission evaluation integration freeze G4-IMP-02-S05 passed']) {
  if (!runTestsSource.includes(marker)) fail('scripts/run-tests.mjs: missing G4 S01-S05 aggregate marker ' + marker);
}
for (const marker of ['submission-assessment-instruction-g4-imp-01-s01.php', 'student-submission-assessment-prompt-v2.txt', 'addon-i06-ai-instruction.php', 'G4-IMP-01-S01 passed']) {
  if (!submissionAssessmentInstructionSource.includes(marker)) fail('scripts/check-submission-assessment-instruction-g4-imp-01-s01.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Submission_Score_Input_Builder.php', 'submission-assessment-input-g4-imp-01-s02.php', 'worksheet-publication-s03.php', 'G4-IMP-01-S02 passed']) {
  if (!submissionAssessmentInputSource.includes(marker)) fail('scripts/check-submission-assessment-input-g4-imp-01-s02.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Submission_Evaluation_Rest_Controller.php', 'WordPress_Submission_Evaluation_Repository.php', 'Platform_Submission_Evaluation_Routing.php', 'submission-evaluation-enqueue-g4-imp-01-s03.php']) {
  if (!submissionEvaluationEnqueueSource.includes(marker)) fail('scripts/check-submission-evaluation-enqueue-g4-imp-01-s03.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Assignment_Evaluation_Rest_Controller.php', 'WordPress_Assignment_Evaluation_Batch_Source.php', 'WordPress_Assignment_Evaluation_Batch_Receipt.php', 'assignment-evaluation-enqueue-g4-imp-01-s04.php']) {
  if (!assignmentEvaluationEnqueueSource.includes(marker)) fail('scripts/check-assignment-evaluation-enqueue-g4-imp-01-s04.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Rest_Permission_Handoff.php', 'Submission_Evaluation_Rest_Controller.php', 'Assignment_Evaluation_Rest_Controller.php', 'submission-evaluation-request-freeze-g4-imp-01-s05.php', 'WeakMap', 'SOURCE_STALE']) {
  if (!submissionEvaluationFreezeSource.includes(marker)) fail('scripts/check-submission-evaluation-freeze-g4-imp-01-s05.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Submission_Evaluation_Work_Item.php', 'Claim_Next_Submission_Evaluation.php', 'Start_Submission_Evaluation_Attempt.php', 'WordPress_Submission_Evaluation_Work_Repository.php', 'submission-evaluation-queue-lease-g4-imp-02-s01.php', 'LEASE_SECONDS = 300', 'worker_lost']) {
  if (!submissionEvaluationQueueLeaseSource.includes(marker)) fail('scripts/check-submission-evaluation-queue-lease-g4-imp-02-s01.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Run_Next_Submission_Evaluation.php', 'Submission_Evaluation_Output_Normalizer.php', 'Platform_Submission_Evaluation_Execution.php', 'WordPress_Submission_Evaluation_Execution_Consent.php', 'WordPress_Submission_Evaluation_Work_Repository.php', 'submission-evaluation-worker-g4-imp-02-s02.php', 'MAXIMUM_OUTPUT_BYTES = 524288', 'callable $loader', 'hidden consent fallback is forbidden']) {
  if (!submissionEvaluationWorkerSource.includes(marker)) fail('scripts/check-submission-evaluation-worker-g4-imp-02-s02.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Submission_External_Ai_Consent_Record.php', 'WordPress_Submission_External_Ai_Consent_Repository.php', 'Submission_External_Ai_Consent_Rest_Controller.php', 'Submission_External_Ai_Consent_Rest_Module.php', 'Submission_Evaluation_Worker_Module.php', 'Deactivation.php', 'submission-external-ai-consent-g4-imp-02-r01.php', 'submission-external-ai-consent-g4-imp-02-r01.mjs', 'e2e-external-ai-consent-settings.mjs', 'Q38/C78/C79']) {
  if (!submissionExternalAiConsentSource.includes(marker)) fail('scripts/check-submission-external-ai-consent-g4-imp-02-r01.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Submission_Evaluation_Query_Projector.php', 'WordPress_Submission_Evaluation_Query.php', 'Submission_Evaluation_Query_Rest_Controller.php', 'submission-evaluation-query-g4-imp-02-s03.php', '202608230001_expand_submission_prompt_contract', 'G4-IMP-02-S03 Q16/Q17 query']) {
  if (!submissionEvaluationQuerySource.includes(marker)) fail('scripts/check-submission-evaluation-query-g4-imp-02-s03.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Retry_Submission_Evaluation.php', 'Cancel_Submission_Evaluation.php', 'WordPress_Submission_Evaluation_Control_Repository.php', 'Submission_Evaluation_Control_Rest_Controller.php', 'submission-evaluation-control-g4-imp-02-s04.php', 'expected_latest_result_id', 'late worker output', 'G4-IMP-02-S04 C31 retry, C32 cooperative cancellation']) {
  if (!submissionEvaluationControlSource.includes(marker)) fail('scripts/check-submission-evaluation-control-g4-imp-02-s04.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Run_Next_Submission_Evaluation.php', 'Submission_Evaluation_Output_Normalizer.php', 'WordPress_Submission_Evaluation_Work_Repository.php', 'WordPress_Submission_Evaluation_Control_Repository.php', 'submission-evaluation-worker-g4-imp-02-s02.php', 'submission-evaluation-control-g4-imp-02-s04.php', 'submission-evaluation-integration-g4-imp-02-s05.php', 'S01-S04 plus R01', 'abstain NULL', 'routing drift', 'stored corruption', 'worker race']) {
  if (!submissionEvaluationIntegrationSource.includes(marker)) fail('scripts/check-submission-evaluation-integration-g4-imp-02-s05.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Review_Submission_Evaluation.php', 'WordPress_Submission_Evaluation_Review_Repository.php', 'Submission_Evaluation_Review_Rest_Controller.php', 'Submission_Evaluation_Review_Rest_Module.php', 'Composition_Root.php', 'submission-evaluation-review-g4-imp-03-s01.php', 'submission-evaluation-review-storage-g4-imp-03-s02.php', 'submission-evaluation-review-rest-g4-imp-03-s02.php', 'official pointer CAS', 'immutable stale release', 'rollback atomicity', 'strict C34 REST', 'durable replay']) {
  if (!submissionEvaluationReviewSource.includes(marker)) fail('scripts/check-submission-evaluation-review-g4-imp-03-s02.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['SkipConstituentChecks', 'check-submission-evaluation-review-g4-imp-03-s02.ps1', 'check-submission-evaluation-review-workbench-g4-imp-03-s03.ps1', 'submission-evaluation-review-integration-g4-imp-03-s04.php', 'exact result', 'official pointer', 'release', 'both root CAS paths', '409 reload', 'permission revocation', 'immutable history']) {
  if (!submissionEvaluationReviewIntegrationSource.includes(marker)) fail('scripts/check-submission-evaluation-review-integration-g4-imp-03-s04.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Submission_Feedback_Input_Builder.php', 'Submission_Feedback_Output_Normalizer.php', 'Generate_Submission_Feedback.php', 'Run_Next_Submission_Feedback.php', 'WordPress_Submission_Feedback_Repository.php', 'Platform_Submission_Feedback_Execution.php', 'Submission_Feedback_Rest_Controller.php', 'submission-feedback-draft-g4-imp-04-s01.php', 'submission-feedback-worker-g4-imp-04-s01.php', 'official-review feedback input', 'immutable AI draft append', 'score-run isolation']) {
  if (!submissionFeedbackSource.includes(marker)) fail('scripts/check-submission-feedback-g4-imp-04-s01.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Review_Submission_Feedback_Command.php', 'Submission_Feedback_Review_Result.php', 'Review_Submission_Feedback.php', 'WordPress_Submission_Feedback_Review_Repository.php', 'Submission_Feedback_Review_Rest_Controller.php', 'Submission_Feedback_Review_Rest_Module.php', 'submission-feedback-review-g4-imp-04-s02.php', 'submission-feedback-review-storage-g4-imp-04-s02.php', 'submission-feedback-review-rest-g4-imp-04-s02.php', 'direct/edit/reject feedback review', 'source hash', 'durable replay', 'rollback']) {
  if (!submissionFeedbackReviewSource.includes(marker)) fail('scripts/check-submission-feedback-review-g4-imp-04-s02.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['Approve_Submission_Feedback_Command.php', 'Approve_Submission_Feedback.php', 'Submission_Feedback_Approval_Repository_Port_Interface.php', 'WordPress_Submission_Feedback_Review_Repository.php', 'Submission_Feedback_Approval_Rest_Controller.php', 'Submission_Feedback_Approval_Rest_Module.php', 'submission-feedback-approval-g4-imp-04-s03.php', 'submission-feedback-approval-storage-g4-imp-04-s03.php', 'submission-feedback-approval-rest-g4-imp-04-s03.php', 'exact teacher-draft approval', 'CAS', 'provenance', 'durable replay', 'privacy', 'rollback']) {
  if (!submissionFeedbackApprovalSource.includes(marker)) fail('scripts/check-submission-feedback-approval-g4-imp-04-s03.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['SkipConstituentChecks', 'SkipVisual', 'check-submission-feedback-g4-imp-04-s01.ps1', 'check-submission-feedback-review-g4-imp-04-s02.ps1', 'check-submission-feedback-approval-g4-imp-04-s03.ps1', 'submission-feedback-workbench-g4-imp-04-s04.php', 'submission-feedback-workbench-g4-imp-04-s04.mjs', 'e2e-submission-feedback-workbench.mjs', 'exact C35-C37 feedback UI', '409 reload', '403 permission revocation', 'immutable revision history']) {
  if (!submissionFeedbackIntegrationSource.includes(marker)) fail('scripts/check-submission-feedback-integration-g4-imp-04-s04.ps1: missing aggregate marker ' + marker);
}
for (const marker of ['START TRANSACTION', 'oj_ai_submission_feedback_revisions', 'latest_previous_feedback_hash', 'source_feedback_hash', 'expected_feedback_revision_hash', 'COMMIT', 'ROLLBACK']) {
  if (!submissionFeedbackReviewRepositorySource.includes(marker)) fail('WordPress_Submission_Feedback_Review_Repository.php: missing C36 transaction marker ' + marker);
}
for (const marker of ['ROUTE_REVIEW', 'Addon_Capabilities::REVIEW_ASSESSMENTS', 'Context_Scope::OPERATION_EVALUATE', 'Object_Scope::TYPE_EVALUATION', 'expected_feedback_revision_hash', 'source_mode', '201', '409']) {
  if (!submissionFeedbackReviewRestSource.includes(marker)) fail('Submission_Feedback_Review_Rest_Controller.php: missing C36 marker ' + marker);
}
if (!submissionFeedbackReviewRestModuleSource.includes("add_action( 'rest_api_init'")) {
  fail('Submission_Feedback_Review_Rest_Module.php: C36 route registration missing');
}
for (const content of [submissionFeedbackReviewRepositorySource, submissionFeedbackReviewRestSource, submissionFeedbackReviewRestModuleSource]) {
  if (content.includes('$wpdb->prefix') || content.includes('wp_oj_ai_')) {
    fail('C36 feedback review source: forbidden table prefix found');
  }
}
for (const marker of ['Submission_Feedback_Approval_Repository_Port_Interface', 'APPROVAL_EVENT', 'submission_feedback.approved', 'oj_ai.submission_feedback_approval_audit', 'oj_ai.submission_feedback_approval_event', 'latest_feedback_profile_hash', 'append_approved_revision', 'START TRANSACTION', 'FOR UPDATE', 'COMMIT', 'ROLLBACK']) {
  if (!submissionFeedbackReviewRepositorySource.includes(marker)) fail('WordPress_Submission_Feedback_Review_Repository.php: missing C37 approval marker ' + marker);
}
for (const marker of ['find_approval_command_receipt', 'find_owned_approval_snapshot', 'append_approved_revision']) {
  if (!submissionFeedbackApprovalPortSource.includes(marker)) fail('Submission_Feedback_Approval_Repository_Port_Interface.php: missing C37 port marker ' + marker);
}
checkPhpFragments('Approve_Submission_Feedback.php', approveSubmissionFeedbackSource, ["'state' => 'approved'", "'source_mode' => 'approve'"]);
for (const marker of ['teacher_draft', 'Submission_Feedback_Input_Builder::OUTPUT_SCHEMA', 'Submission_Feedback_Input_Builder::OUTPUT_VERSION', 'profile_json()', 'profile_hash()', 'latest_feedback_source_run_id']) {
  if (!approveSubmissionFeedbackSource.includes(marker)) fail('Approve_Submission_Feedback.php: missing C37 application marker ' + marker);
}
for (const marker of ['submission.feedback.approve', "'payload'       => array()", 'expected_feedback_revision_no', 'context_staff']) {
  if (!approveSubmissionFeedbackCommandSource.includes(marker)) fail('Approve_Submission_Feedback_Command.php: missing C37 command marker ' + marker);
}
for (const marker of ['ROUTE_APPROVE', '/commands/approve-feedback', 'ACTION_APPROVE', 'Addon_Capabilities::REVIEW_ASSESSMENTS', 'Context_Scope::OPERATION_EVALUATE', 'Object_Scope::TYPE_EVALUATION', "array() !== $payload", 'expected_feedback_revision_hash', '201', '409']) {
  if (!submissionFeedbackApprovalRestSource.includes(marker)) fail('Submission_Feedback_Approval_Rest_Controller.php: missing C37 REST marker ' + marker);
}
if (!submissionFeedbackApprovalRestModuleSource.includes("add_action( 'rest_api_init'")) {
  fail('Submission_Feedback_Approval_Rest_Module.php: C37 route registration missing');
}
for (const content of [approveSubmissionFeedbackSource, approveSubmissionFeedbackCommandSource, submissionFeedbackApprovalPortSource, submissionFeedbackApprovalRestSource, submissionFeedbackApprovalRestModuleSource]) {
  if (content.includes('$wpdb->prefix') || content.includes('wp_oj_ai_')) {
    fail('C37 feedback approval source: forbidden table prefix found');
  }
}
checkPhpFragments('WordPress_Submission_Evaluation_Review_Repository.php', submissionEvaluationReviewRepositorySource, ["'state' => 'stale'"]);
for (const marker of ['START TRANSACTION', 'expected_submission_lock_version', 'current_release_public_id', 'official_review_changed', 'advance_evaluation', 'advance_submission']) {
  if (!submissionEvaluationReviewRepositorySource.includes(marker)) fail('WordPress_Submission_Evaluation_Review_Repository.php: missing S02 transaction marker ' + marker);
}
for (const marker of ['ROUTE_REVIEW', 'Addon_Capabilities::REVIEW_ASSESSMENTS', 'Context_Scope::OPERATION_EVALUATE', 'Object_Scope::TYPE_EVALUATION', "array( 'command_id', 'expected', 'payload' )", 'result->replayed() ? 200 : 201']) {
  if (!submissionEvaluationReviewRestSource.includes(marker)) fail('Submission_Evaluation_Review_Rest_Controller.php: missing C34 marker ' + marker);
}
if (!submissionEvaluationReviewRestModuleSource.includes("add_action( 'rest_api_init'")) {
  fail('Submission_Evaluation_Review_Rest_Module.php: C34 route registration missing');
}
for (const marker of ['new WordPress_Submission_Evaluation_Review_Repository', 'new Submission_Evaluation_Review_Rest_Controller', 'new Submission_Evaluation_Review_Rest_Module']) {
  if (!compositionRootSource.includes(marker)) fail('src/Core/Composition_Root.php: missing S02 activation marker ' + marker);
}
for (const marker of ['new Review_Submission_Feedback', 'new WordPress_Submission_Feedback_Review_Repository', 'new Submission_Feedback_Review_Rest_Controller', 'new Submission_Feedback_Review_Rest_Module']) {
  if (!compositionRootSource.includes(marker)) fail('src/Core/Composition_Root.php: missing C36 activation marker ' + marker);
}
for (const marker of ['new Approve_Submission_Feedback', 'new Submission_Feedback_Approval_Rest_Controller', 'new Submission_Feedback_Approval_Rest_Module', '$submission_feedback_revision_repository']) {
  if (!compositionRootSource.includes(marker)) fail('src/Core/Composition_Root.php: missing C37 activation marker ' + marker);
}
for (const marker of ['new Generate_Student_Note_Draft', 'new Student_Note_Rest_Controller', 'new Student_Note_Worker_Module', 'new Student_Note_Input_Builder', 'new Student_Note_Output_Normalizer']) {
  if (!compositionRootSource.includes(marker)) fail('src/Core/Composition_Root.php: missing C38 activation marker ' + marker);
}
if (platformContract.includes('oj_platform_addons_ready') || platformDesign.includes('oj_platform_addons_ready')) {
  fail('platform C-28 documents: obsolete oj_platform_addons_ready hook found');
}

const allContent = designFiles
  .map((file) => documents.get(path.join(docsRoot, file)).content)
  .join('\n');
for (const forbidden of [
  '2.0.0-dev.18',
  '2.0.0-dev.31',
  'A-DS-00~A-DS-15',
  'oj_platform_addons_ready',
  '<code>worksheet_quality_assessment.v1</code>, 교사용 exact revision',
  'Critical 22\u00b7High 68\u00b7Medium 5\u00b7\ubbf8\ubd84\ub958 36',
]) {
  if (allContent.includes(forbidden)) fail(`obsolete design baseline found: ${forbidden}`);
}

const requirements = [
  ['platform dev.33', '2.0.0-dev.33'],
  ['fixed add-on tables', 'oj_ai_*'],
  ['OJ Console integration', 'OJ Console'],
  ['PublicUI integration', 'PublicUI'],
  ['Plate editor', 'Plate'],
  ['worksheet self-evaluation', '\ud65c\ub3d9\uc9c0 \uc790\uccb4 AI \ud3c9\uac00'],
  ['student submission evaluation', '\ud559\uc0dd \uc81c\ucd9c\ubb3c AI \ud3c9\uac00'],
  ['PDF export', 'PDF'],
  ['worksheet-first reports', '\ud65c\ub3d9\uc9c0\ubcc4'],
  ['student special note contract', 'student_special_note.v1'],
  ['private export', 'private storage'],
  ['JSON package', 'JSON package'],
  ['no platform patch', '\ud50c\ub7ab\ud3fc \ud30c\uc77c \ub610\ub294 migration\uc744 \ud328\uce58\ud558\uc9c0'],
];
for (const [label, needle] of requirements) {
  if (!allContent.includes(needle)) fail(`required decision missing: ${label}`);
}

if (platformDriftWarnings.length > 0) {
  console.warn(`Platform release-baseline drift warnings (${platformDriftWarnings.length}):`);
  for (const warning of platformDriftWarnings) {
    console.warn(`- ${warning}`);
  }
}

if (errors.length > 0) {
  console.error(`AI assessment design validation failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`AI assessment design validation passed: ${designFiles.length} design HTML files + 1 UI prototype, ${findingIds.size} findings.`);
console.log(`Severity totals: Critical ${severityTotals.Critical}, High ${severityTotals.High}, Medium ${severityTotals.Medium}.`);
