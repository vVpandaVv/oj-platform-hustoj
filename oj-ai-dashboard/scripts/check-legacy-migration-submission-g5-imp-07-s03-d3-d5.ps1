[CmdletBinding()]
param(
    [switch]$SkipConstituentChecks
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

function Invoke-Checked([string]$Command, [string[]]$Arguments) {
    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed ($LASTEXITCODE): $Command $($Arguments -join ' ')"
    }
}

function Assert-Markers([string]$Path, [string[]]$Markers, [string]$Label) {
    $source = Get-Content -LiteralPath (Join-Path $root $Path) -Raw -Encoding UTF8
    foreach ($marker in $Markers) {
        if (-not $source.Contains($marker)) {
            throw "G5-IMP-07-S03-D3-D5 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    foreach ($gate in @(
        'check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1',
        'check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1',
        'check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1'
    )) {
        Invoke-Checked 'powershell' @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot $gate),
            '-SkipConstituentChecks'
        )
    }
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Submission_Response_Projector.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Submission_Writer.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Submission_Writer_Port_Interface.php',
    'tests\Contract\legacy-migration-submission-g5-imp-07-s03-d3-d5.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -notlike 'tests\*' }
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'START TRANSACTION', 'COMMIT', 'ROLLBACK', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'CREATE TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S03-D3-D5 runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S03-D3-D5 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Submission_Response_Projector.php' @(
    "'worksheet_student_answers'",
    "'zip_upload_submission'",
    'Submission_Response_Validator',
    'deferred_attachment',
    "'single_choice'",
    "'multiple_choice'",
    "'table'"
) 'response projector'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Submission_Writer.php' @(
    "'oj_ai_submissions'",
    "'oj_ai_submission_revisions'",
    "'oj_ai_submission_receipts'",
    "'submission_attachment_manifest'",
    "'Historical lock restored by legacy migration.'",
    "hash( 'sha256', (string) `$source_id )",
    'document_hash',
    'without owning a transaction'
) 'literal aggregate writer'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php' @(
    'Legacy_Migration_Submission_Writer_Port_Interface',
    "'worksheet_submissions' === `$item->source_type()",
    "'submission_revisions' === `$item->source_type()",
    "'submission_attachments' === `$item->source_type()",
    'submission_writer->write',
    'submission_writer->verify_revision',
    'submission_writer->verify_attachment'
) 'owner dispatch'
Assert-Markers 'src\Operations\LegacyMigration\Port\Legacy_Migration_Submission_Writer_Port_Interface.php' @(
    'caller-owned item transaction',
    'verify_revision',
    'verify_attachment'
) 'transaction ownership port'
Assert-Markers 'tests\Contract\legacy-migration-submission-g5-imp-07-s03-d3-d5.php' @(
    'Ten-kind attachment-free response did not pass complete validation.',
    'Locked control receipt violates the runtime teacher contract.',
    'D3-D5 fabricated a target attachment.',
    'Caller rollback left a submission root.',
    'D3-D5 runtime crossed a frozen boundary:'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s03-d3-d-owner-migration.html' @(
    'G5-IMP-07-S03-D3-D5',
    'id="d3-d5-result"',
    'check:legacy-migration-submission-g5',
    'locked control receipt',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S03-D3-D5',
    'SHA-256 metadata key',
    'G5-IMP-07-S03-D3-D6',
    'check:legacy-migration-submission-g5'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-26-R111-OBSERVED',
    'G5-IMP-07-S03-D3-D5',
    'check:legacy-migration-submission-g5',
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:legacy-migration-submission-g5',
    'check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1',
    'Legacy migration submission G5-IMP-07-S03-D3-D5 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-submission-g5-imp-07-s03-d3-d5.php'))

Write-Output 'G5-IMP-07-S03-D3-D5 passed: response projection, immutable revisions, official/control receipts, attachment deferral, relation verification, caller rollback, and zero platform writes are frozen.'
