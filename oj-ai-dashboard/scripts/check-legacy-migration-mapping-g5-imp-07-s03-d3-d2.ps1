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
            throw "G5-IMP-07-S03-D3-D2 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-approval-g5-imp-07-s02.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Command.php',
    'src\Operations\LegacyMigration\Application\Execute_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Application\Process_Legacy_Migration_Batch.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot.php',
    'src\Operations\LegacyMigration\Domain\Running_Legacy_Migration_Run.php',
    'src\Operations\LegacyMigration\Infrastructure\Platform_Legacy_Mapping_Decision_Validator.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Repository.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Prefixed_Legacy_Source_Inspector.php',
    'src\Operations\LegacyMigration\Port\Legacy_Mapping_Decision_Validator_Port_Interface.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Approval_Rest_Controller.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\legacy-migration-mapping-g5-imp-07-s03-d3-d2.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = @(
    'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Command.php',
    'src\Operations\LegacyMigration\Application\Execute_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Application\Process_Legacy_Migration_Batch.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot.php',
    'src\Operations\LegacyMigration\Infrastructure\Platform_Legacy_Mapping_Decision_Validator.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Approval_Rest_Controller.php'
)
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'CREATE TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S03-D3-D2 runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S03-D3-D2 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php' @(
    'oj_ai.legacy_migration_plan@1.2.0',
    'oj_ai.legacy_migration_plan@1.1.0',
    'oj_ai.legacy_migration_plan@1.0.0',
    'assignment_source_ref',
    'mapping_manifest',
    'assert_executable',
    'assert_mapping_contract'
) 'versioned plan'
Assert-Markers 'src\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest.php' @(
    'worksheet_assignments',
    'worksheet_submissions',
    'context_source_ref',
    'MAXIMUM_REQUIREMENTS = 50000'
) 'PII-free dependency manifest'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\Platform_Legacy_Mapping_Decision_Validator.php' @(
    'Assignment_Roster_Port_Interface',
    'roster_revision',
    'membership_revision',
    'MAPPING_REQUIRED'
) 'platform roster validation'
Assert-Markers 'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration.php' @(
    'mapping_decisions()',
    'mapping_validator->validate',
    'MAPPING_REQUIRED'
) 'C70 mapping approval'
Assert-Markers 'src\Operations\LegacyMigration\Application\Execute_Legacy_Migration.php' @(
    '$run->plan()',
    'mapping_validator->validate',
    'assert_executable'
) 'C62 mapping revalidation'
Assert-Markers 'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Approval_Rest_Controller.php' @(
    "array( 'source', 'preview_token' )",
    "array( 'source', 'preview_token', 'mapping_decisions' )",
    'Legacy_Migration_Failure::MAPPING_REQUIRED'
) 'conditional C70 body'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'Platform_Legacy_Mapping_Decision_Validator',
    '$legacy_mapping_validator',
    'new Platform_Assignment_Roster( $runtime )'
) 'production composition'
Assert-Markers 'tests\Contract\legacy-migration-mapping-g5-imp-07-s03-d3-d2.php' @(
    'PII-free dependency manifests',
    'C70 accepted an incomplete mapping set.',
    'C62 accepted a changed Context revision.',
    'C62 accepted a changed roster revision.',
    'C62 accepted a changed learner membership revision.'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s03-d3-d-owner-migration.html' @(
    'G5-IMP-07-S03-D3-D2',
    'check:legacy-migration-mapping-g5',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S03-D3-D2',
    'G5-IMP-07-S03-D3-D3',
    'G5-IMP-07-S03-D3-D3-A',
    'check:legacy-migration-mapping-g5'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-25-R108-OBSERVED',
    'G5-IMP-07-S03-D3-D2',
    'check:legacy-migration-mapping-g5',
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:legacy-migration-mapping-g5',
    'check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1',
    'Legacy migration mapping G5-IMP-07-S03-D3-D2 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-preview-g5-imp-07-s01.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-approval-g5-imp-07-s02.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-execution-start-g5-imp-07-s03.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-batch-g5-imp-07-s03.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-mapping-g5-imp-07-s03-d3-d2.php'))

Write-Output 'G5-IMP-07-S03-D3-D2 passed: dependency mapping manifests, canonical approvals, platform roster checks, C62 revision revalidation, and zero platform writes are frozen.'
