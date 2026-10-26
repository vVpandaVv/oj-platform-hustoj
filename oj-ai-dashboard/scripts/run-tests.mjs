import { spawnSync } from 'node:child_process';

const checks = [
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=intl', 'tests/Contract/worksheet-table-widths.php'] },
  { command: process.execPath, args: ['scripts/e2e-worksheet-table-widths.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=intl', 'tests/Contract/submission-screen-view.php', '--fixture'] },
  { command: process.execPath, args: ['scripts/e2e-submission-document.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=intl', 'tests/Contract/worksheet-teacher-approval.php'] },
  { command: process.execPath, args: ['scripts/e2e-worksheet-teacher-approval.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/worksheet-project-migration.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/page-color-scheme.php'] },
  { command: process.execPath, args: ['scripts/e2e-color-scheme.mjs'] },
  { command: process.execPath, args: ['scripts/e2e-worksheet-library-entry.mjs'] },
  { command: process.execPath, args: ['scripts/e2e-criteria-first-workflow.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/retired-runtime.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/server-setup-recovery.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/server-config-helper.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/external-ml-assessment.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/external-common-assessment.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/external-ml-assessment-storage.php'] },
  { command: process.execPath, args: ['tests/Contract/student-note-failure-ui.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/student-note-academic-language.php'] },
  { command: process.execPath, args: ['tests/Contract/report-writing-guidance.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/student-note-process-evidence.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/assignment-late-joiners.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/assignment-late-joiners-rest.php'] },
  { command: process.execPath, args: ['tests/Contract/assignment-late-joiners.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/submission-extra-attempt.php'] },
  { command: process.execPath, args: ['tests/Contract/submission-reopen-discovery.mjs'] },
  { command: process.execPath, args: ['tests/Contract/submission-source-hash-echo.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/submission-source-hash-echo.php'] },
  { command: process.execPath, args: ['tests/Contract/submission-bulk-grading.mjs'] },
  { command: process.execPath, args: ['tests/Contract/submission-bulk-review-report.mjs'] },
  { command: process.execPath, args: ['tests/Contract/submission-assigned-contexts.mjs'] },
  { command: process.execPath, args: ['tests/Contract/submission-score-download.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/submission-score-download.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/submission-bulk-review-report.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/submission-bulk-grading.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/submission-bulk-retry.php'] },
  { command: process.execPath, args: ['tests/Contract/runtime-boundary-scan-scope.mjs'] },
  { command: process.execPath, args: ['tests/Contract/submission-score-progress.mjs'] },
  { command: process.execPath, args: ['tests/Contract/submission-score-rejection.mjs'] },
  { command: process.execPath, args: ['tests/Contract/submission-independent-scroll.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/submission-evidence-references.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/submission-structured-input-readback.php'] },
  ...['default', 'disabled', 'alternate', 'cron'].map(mode => ({
    command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/evaluation-queue-dispatch.php', mode],
  })),
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/evaluation-queue-sql.php'] },
  { command: 'powershell', args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'tests/Contract/powershell-php-markers.ps1'] },
  { command: process.execPath, args: ['tests/Contract/design-php-fragments.mjs'] },
  { command: process.execPath, args: ['tests/Contract/release-build-preflight.mjs'] },
  { command: process.execPath, args: ['tests/Contract/phpcs-rule-boundaries.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/attachment-content-boundaries.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/report-source-validation.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/raw-field-key-validation.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/phpstan-runtime-boundaries.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/activation-message-escaping.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/source-contract-token-boundaries.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/report-legacy-invalid-shapes.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/rest-envelope-invalid-shapes.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/worksheet-save-invalid-shapes.php'] },
  { command: process.execPath, args: ['scripts/e2e-question-sections.mjs'] },
  { command: process.execPath, args: ['scripts/e2e-answer-surfaces.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/session-nonce-boundary.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/assessment-project-error-privacy.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/server-storage-recovery-audit.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/export-zip-entry-integrity.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/export-csv-cell-safety.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/report-context-publication-access.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/phpdoc-normalization.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/php-source-contract.php'] },
  { command: process.execPath, args: ['tests/Contract/release-quality-summary.mjs'] },
  { command: process.execPath, args: ['tests/Contract/release-fingerprint-observation.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', '-d', 'extension=gd', 'tests/Contract/teacher-attachment-content.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', '-d', 'extension=gd', 'tests/Contract/bound-attachment-retention.php'] },
  { command: process.execPath, args: ['scripts/e2e-teacher-attachments.mjs'] },
  { command: process.execPath, args: ['scripts/check-clamav-probe.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/operations-storage-resume.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/operations-storage-backlog.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/export-retention-cleanup.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/release-production-vendor.php'] },
  { command: process.execPath, args: ['tests/Contract/defect-resolution-ui.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/assessment-projects.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/workspace-navigation.php'] },
  { command: process.execPath, args: ['scripts/e2e-assessment-projects.mjs'] },
  { command: process.execPath, args: ['scripts/e2e-project-spacing.mjs'] },
  { command: process.execPath, args: ['scripts/e2e-workflow-selection.mjs'] },
  { command: process.env.OJAI_PHP || 'php', args: ['tests/Contract/private-storage-web-boundary.php'] },
  { command: process.env.OJAI_PHP || 'php', args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/auto-submit-candidate-pagination.php'] },
  { command: process.execPath, args: ['scripts/e2e-storage-defect-resolution.mjs'] },
  { command: process.execPath, args: ['scripts/e2e-student-attachment-defects.mjs'] },
  { command: process.execPath, args: ['scripts/e2e-drawing-upload-recovery.mjs'] },
  { command: process.execPath, args: ['scripts/e2e-report-pagination-defects.mjs'] },
  {
    command: process.execPath,
    args: ['scripts/check-ai-assessment-design.mjs', '--allow-platform-drift'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-addon-api-consumer.ps1'],
  },
  {
    command: process.execPath,
    args: ['tests/Contract/data-management-settings-ui.mjs'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-database-foundation.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-database-infrastructure.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-activation-database.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-runtime-schema-readiness.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-fresh-install-g5-imp-06-s01.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-sequential-upgrade-g5-imp-06-s02.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-failure-restart-g5-imp-06-s03.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-forward-repair-g5-imp-06-s04.ps1', '-SkipConstituentChecks', '-SkipBrowser'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-data-preservation-g5-imp-06-s05.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-preview-g5-imp-07-s01.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-approval-g5-imp-07-s02.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-evaluation-g5-imp-07-s03-d3-d6.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-private-files-g5-imp-07-s03-d3-d7.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-owner-matrix-g5-imp-07-s03-d3-d8.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-runtime-g5-imp-07-s03-e.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-recovery-g5-imp-07-s03-f.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-run-query-g5-imp-07-s04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-rollback-g5-imp-07-s05.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-legacy-migration-integration-g5-imp-07-s06.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-uninstall-data-preservation-g5-imp-08-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-purge-preview-g5-imp-08-s02.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-purge-approval-g5-imp-08-s03.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-purge-execution-g5-imp-08-s04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-purge-job-query-g5-imp-08-s05.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-purge-integration-g5-imp-08-s06.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-access-policy.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-rest-foundation.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-context-rest-q01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-curriculum-catalog.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-release-package-boundary.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-authoring-g2-imp-01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-save-draft-c02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-document-profile-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-revision-projection-s04.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-save-draft-s05.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-save-draft-s06.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-save-draft-s07.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-workbench-s08.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-asset-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-asset-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-template-import-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-duplicate-s04.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-assets-ui-s05.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-validation-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-preview-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-projection-safety-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-validation-preview-ui-s04.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-quality-instruction-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-evaluation-input-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-evaluation-enqueue-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-evaluation-worker-s04.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-evaluation-query-s05.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-evaluation-control-s06.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-evaluation-review-s07.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-evaluation-workbench-s08.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-evaluation-integration-s09.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-external-ai-consent-r01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-assessment-configuration-bootstrap-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-approval-policy-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-approval-command-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-publication-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-lifecycle-s04.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-approval-publication-ui-s05.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-pdf-package-g2-imp-07.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-assignment-g3-imp-01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-assignment-lifecycle-g3-imp-02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-student-assignment-g3-imp-03.ps1'],
  },
  {
    command: process.env.OJAI_PHP || 'php',
    args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/student-result-navigation.php'],
  },
  {
    command: process.env.OJAI_PHP || 'php',
    args: ['-d', 'extension=pdo_sqlite', 'tests/Contract/student-file-exposure.php'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-zip-g3-imp-07.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-pdf-g3-imp-08.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-own-worksheet-pdf-g3-imp-08-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-own-submission-pdf-g3-imp-08-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-pdf-integration-g3-imp-08-s04.ps1', '-SkipConstituentChecks', '-SkipVisual'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-assessment-instruction-g4-imp-01-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-assessment-input-g4-imp-01-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-evaluation-enqueue-g4-imp-01-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-assignment-evaluation-enqueue-g4-imp-01-s04.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-evaluation-freeze-g4-imp-01-s05.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-evaluation-queue-lease-g4-imp-02-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-evaluation-worker-g4-imp-02-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-evaluation-query-g4-imp-02-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-evaluation-control-g4-imp-02-s04.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-external-ai-consent-g4-imp-02-r01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-evaluation-integration-g4-imp-02-s05.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-evaluation-review-g4-imp-03-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-evaluation-review-workbench-g4-imp-03-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-evaluation-review-integration-g4-imp-03-s04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-feedback-g4-imp-04-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-feedback-review-g4-imp-04-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-feedback-approval-g4-imp-04-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-feedback-integration-g4-imp-04-s04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-feedback-approval-withdrawal-g4-imp-04-s05.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-student-note-draft-g4-imp-05-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-student-note-query-g4-imp-05-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-student-note-review-approval-g4-imp-05-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-student-note-report-inclusion-g4-imp-05-s04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-student-note-integration-g4-imp-05-s05.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-student-note-approval-withdrawal-g4-imp-05-s06.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-result-release-g4-imp-06-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-result-query-g4-imp-06-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-result-withdrawal-g4-imp-06-s03.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-result-correction-g4-imp-06-s04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-result-print-projection-g4-imp-07-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-result-pdf-job-g4-imp-07-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-result-pdf-access-g4-imp-07-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-result-pdf-ui-integration-g4-imp-07-s04.ps1', '-SkipConstituentChecks', '-SkipBrowser', '-SkipVisual'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-report-list-g5-imp-01-s01.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-report-detail-g5-imp-01-s02.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-worksheet-report-filter-denominator-g5-imp-01-s03.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-report-dashboard-g5-imp-01-s04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-student-report-g5-imp-04.ps1'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-report-snapshot-g5-imp-02-s01.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-report-snapshot-query-g5-imp-02-s02.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-report-source-manifest-g5-imp-02-s03.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-report-snapshot-integration-g5-imp-02-s04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-report-export-enqueue-g5-imp-03-s01.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-submission-bundle-enqueue-g5-imp-03-s02.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-report-json-package-enqueue-g5-imp-03-s03.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-report-export-job-download-g5-imp-03-s04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-report-export-control-g5-imp-03-s05.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-report-export-integration-g5-imp-03-s06.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-operations-diagnostics-g5-imp-05-s01.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-operations-ai-routing-g5-imp-05-s02.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-operations-storage-g5-imp-05-s03.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-operations-diagnostics-ui-g5-imp-05-s04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-business-scenarios-g6-imp-01.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-security-privacy-accessibility-g6-imp-02.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-performance-resilience-g6-imp-03.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-upgrade-rollback-g6-imp-04.ps1', '-SkipConstituentChecks'],
  },
  {
    command: 'powershell',
    args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/check-boundary-final-package-g6-imp-05.ps1', '-SkipConstituentChecks', '-SkipProductionPackage'],
  },
];

const failures = [];
for (const check of checks) {
  const result = spawnSync(check.command, check.args, { stdio: 'inherit' });
  if (result.status !== 0) {
    const fileIndex = check.args.indexOf('-File');
    failures.push(fileIndex >= 0 ? check.args[fileIndex + 1] : check.args[0] ?? check.command);
  }
}

if (failures.length > 0) {
  console.error(`Regression aggregate failed (${failures.length}):`);
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log('Canonical design, all G1 foundation contracts, Q01, G2 Worksheet authoring through IMP-04, worksheet self-evaluation IMP-05 S01-S09 plus R01, worksheet approval/publication lifecycle IMP-06-S01-S05, worksheet PDF/package IMP-07-S01-S04, assessment configuration G5-IMP-04-S01-S05, assignment delivery G3-IMP-01 through G3-IMP-03, submission ZIP G3-IMP-07-S01-S05, teacher submission PDF G3-IMP-08-S01-G passed, learner worksheet PDF G3-IMP-08-S02 passed, learner submission PDF G3-IMP-08-S03 passed, PDF integration G3-IMP-08-S04 passed, submission assessment G4-IMP-01-S01-S05 passed, submission evaluation queue/lease G4-IMP-02-S01 passed, submission evaluation Gateway/output/candidate transaction G4-IMP-02-S02 passed, submission evaluation query G4-IMP-02-S03 passed, submission evaluation retry/cancel G4-IMP-02-S04 passed, student submission external AI consent G4-IMP-02-R01 passed, submission evaluation integration freeze G4-IMP-02-S05 passed, submission evaluation official review activation G4-IMP-03-S02 passed, submission evaluation score and evidence review workbench G4-IMP-03-S03 passed, submission evaluation stale-source integration freeze G4-IMP-03-S04 passed, official-review feedback draft G4-IMP-04-S01 passed, teacher feedback review G4-IMP-04-S02 passed, exact teacher feedback approval G4-IMP-04-S03 passed, submission feedback workbench integration G4-IMP-04-S04 passed, student special note draft G4-IMP-05-S01 passed, and teacher student-note query G4-IMP-05-S02 passed.');
console.log('Student-note teacher review and exact approval G4-IMP-05-S03 passed.');
console.log('Student-note report inclusion and exclusion G4-IMP-05-S04 passed.');
console.log('Student-note provenance and authorization integration freeze G4-IMP-05-S05 passed.');
console.log('Student result first release C43 G4-IMP-06-S01 passed.');
console.log('Student own current result query Q33 G4-IMP-06-S02 passed.');
console.log('Student result release withdrawal C44 G4-IMP-06-S03 passed.');
console.log('Student result correction C43 G4-IMP-06-S04 passed.');
console.log('Student result PublicUI integration freeze G4-IMP-06-S05 passed.');
console.log('Student result public PDF projection G4-IMP-07-S01 passed.');
console.log('Student result private PDF C52 job G4-IMP-07-S02 passed.');
console.log('Student result Q23/Q24 revocation and pre-stream integrity G4-IMP-07-S03 passed.');
console.log('Student result PDF UI and Korean A4 visual integration G4-IMP-07-S04 passed.');
console.log('Worksheet report list Q20 G5-IMP-01-S01 passed.');
console.log('Worksheet report detail Q21 G5-IMP-01-S02 passed.');
console.log('Worksheet report canonical filters and denominators G5-IMP-01-S03 passed.');
console.log('Worksheet report Q20 to Q21 Console dashboard G5-IMP-01-S04 passed.');
console.log('Report CSV and PDF enqueue C46/C47 G5-IMP-03-S01 passed.');
console.log('Submission bundle C50 G5-IMP-03-S02 passed.');
console.log('Report JSON package enqueue C69 G5-IMP-03-S03 passed.');
console.log('Report export job and download Q23/Q24 G5-IMP-03-S04 passed.');
console.log('Export cancel C53 and retry C54 G5-IMP-03-S05 passed.');
console.log('Report export checksum and authorization integration G5-IMP-03-S06 passed.');
console.log('Operations diagnostics Q28 Bridge, schema, and queue G5-IMP-05-S01 passed.');
console.log('Operations diagnostics Q28 AI routing G5-IMP-05-S02 passed.');
console.log('Operations diagnostics Q28 storage and secret protection G5-IMP-05-S03 passed.');
console.log('Operations diagnostics UI and five-check regression G5-IMP-05-S04 passed.');
console.log('Fresh installation G5-IMP-06-S01 passed.');
console.log('Sequential upgrade G5-IMP-06-S02 passed.');
console.log('Failure state and restart G5-IMP-06-S03 passed.');
console.log('Forward schema repair C75 G5-IMP-06-S04 passed.');
console.log('Schema data preservation G5-IMP-06-S05 passed.');
console.log('Legacy migration preview C61 G5-IMP-07-S01 passed.');
console.log('Legacy migration approval C70 G5-IMP-07-S02 passed.');
console.log('Legacy migration mapping G5-IMP-07-S03-D3-D2 passed.');
console.log('Legacy migration publication G5-IMP-07-S03-D3-D3 passed.');
console.log('Legacy migration assignment G5-IMP-07-S03-D3-D4 passed.');
console.log('Legacy migration submission G5-IMP-07-S03-D3-D5 passed.');
console.log('Legacy migration evaluation G5-IMP-07-S03-D3-D6 passed.');
console.log('Legacy migration private files G5-IMP-07-S03-D3-D7 passed.');
console.log('Legacy migration owner matrix G5-IMP-07-S03-D3-D8 passed.');
console.log('Legacy migration C62 runtime G5-IMP-07-S03-E passed.');
console.log('Legacy migration recovery G5-IMP-07-S03-F passed.');
console.log('Legacy migration Q29 query G5-IMP-07-S04 passed.');
console.log('Legacy migration C63 rollback G5-IMP-07-S05 passed.');
console.log('Legacy migration integration G5-IMP-07-S06 passed.');
console.log('Uninstall preservation G5-IMP-08-S01 passed.');
console.log('Purge preview C71 G5-IMP-08-S02 passed.');
console.log('Purge approval C72 G5-IMP-08-S03 passed.');
console.log('Purge execution C73 and cancellation C74 G5-IMP-08-S04 passed.');
console.log('Purge job Q36 status G5-IMP-08-S05 passed.');
console.log('Purge integration G5-IMP-08-S06 passed.');
console.log('G6-IMP-01 business scenarios passed.');
console.log('G6-IMP-02 security, privacy, and accessibility passed.');
console.log('G6-IMP-03 performance and resilience passed.');
console.log('G6-IMP-04 upgrade and rollback passed.');
console.log('G6-IMP-05 S01-S04 prepackage checks passed; S05 requires clean source.');
