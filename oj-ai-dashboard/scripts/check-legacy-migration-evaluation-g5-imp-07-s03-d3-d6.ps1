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
            throw "G5-IMP-07-S03-D3-D6 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Evaluation_Projector.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Evaluation_Writer.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Evaluation_Writer_Port_Interface.php',
    'tests\Contract\legacy-migration-evaluation-g5-imp-07-s03-d3-d6.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -notlike 'tests\*' }
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'START TRANSACTION', 'COMMIT', 'ROLLBACK', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'CREATE TABLE', 'oj_ai_audit_events', 'oj_ai_outbox_events')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S03-D3-D6 runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S03-D3-D6 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Domain\Legacy_Migration_Evaluation_Projector.php' @(
    'Submission_Score_Input_Builder',
    'Submission_Evaluation_Output_Normalizer',
    "'anchor' => 'response:' . `$response_id",
    "'evidence_limited', 'teacher_review_required'"
) 'current-contract projector'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Evaluation_Writer.php' @(
    "'oj_ai_submission_evaluations'",
    "'oj_ai_submission_evaluation_inputs'",
    "'oj_ai_evaluation_runs'",
    "'oj_ai_submission_evaluation_results'",
    "'oj_ai_submission_evaluation_reviews'",
    "'oj_ai_submission_feedback_revisions'",
    "'oj_ai_submission_release_revisions'",
    "'oj_ai_student_note_revisions'",
    "'legacy_inflight_cancelled'",
    "'legacy_result_missing'",
    'provider_digest',
    'without replaying AI or owning a transaction'
) 'literal evaluation writer'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php' @(
    'Legacy_Migration_Evaluation_Writer_Port_Interface',
    'EVALUATION_SOURCE_TYPES',
    'evaluation_writer->write',
    'Legacy_Target_Transformation_Failure::UNSUPPORTED'
) 'owner dispatch'
Assert-Markers 'src\Operations\LegacyMigration\Port\Legacy_Migration_Evaluation_Writer_Port_Interface.php' @(
    'caller-owned import transaction',
    'Legacy_Target_Write_Result'
) 'transaction ownership port'
Assert-Markers 'tests\Contract\legacy-migration-evaluation-g5-imp-07-s03-d3-d6.php' @(
    'Evaluation migration relation limit changed.',
    'Historical score input leaked the legacy provider field.',
    'Historical evidence limitation did not require teacher review.',
    'Student release leaked provider-only evidence.',
    'D3-D6 persisted the raw provider response.',
    'D3-D6 did not make incomplete legacy runs terminal.'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s03-d3-d-owner-migration.html' @(
    'G5-IMP-07-S03-D3-D6',
    'id="d3-d6-result"',
    'check:legacy-migration-evaluation-g5',
    'raw provider response',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S03-D3-D6',
    'D3-D 6/8',
    'G5-IMP-07-S03-D3-D7',
    'check:legacy-migration-evaluation-g5'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-26-R112-OBSERVED',
    'G5-IMP-07-S03-D3-D6',
    'check:legacy-migration-evaluation-g5',
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:legacy-migration-evaluation-g5',
    'check-legacy-migration-evaluation-g5-imp-07-s03-d3-d6.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-evaluation-g5-imp-07-s03-d3-d6.ps1',
    'Legacy migration evaluation G5-IMP-07-S03-D3-D6 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-evaluation-g5-imp-07-s03-d3-d6.php'))

Write-Output 'G5-IMP-07-S03-D3-D6 passed: current-contract score history, reviews, feedback, releases, notes, terminal runs, caller rollback, and zero platform writes are frozen.'
