[CmdletBinding()]
param(
    [switch]$SkipConstituentChecks
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'php-source-markers.ps1')
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
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) {
            throw "G5-IMP-07-S03-D3-D4 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1'),
        '-SkipConstituentChecks'
    )
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php',
    'src\Operations\LegacyMigration\Application\Execute_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Assignment_Writer.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Assignment_Writer_Port_Interface.php',
    'tests\Contract\legacy-migration-assignment-g5-imp-07-s03-d3-d4.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = @(
    'src\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php',
    'src\Operations\LegacyMigration\Application\Execute_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Assignment_Writer.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Assignment_Writer_Port_Interface.php'
)
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'CREATE TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S03-D3-D4 runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S03-D3-D4 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest.php' @(
    'assignment_source_ref',
    'assignment_ref',
    'context_ref_for_assignment'
) 'assignment-bound mapping manifest'
Assert-Markers 'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php' @(
    'oj_ai.legacy_migration_plan@1.2.0',
    'oj_ai.legacy_migration_plan@1.1.0',
    'requires reapproval',
    'assignment_source_ref'
) 'plan compatibility contract'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Assignment_Writer.php' @(
    "'oj_ai_import_runs'",
    "'oj_ai_assignments'",
    "'oj_ai_assignment_targets'",
    'Restores closed historical assignments without opening its own transaction.',
    'ensure_for_assignment',
    'assignment_source_ref',
    'private const MAXIMUM_TARGETS = 5000'
) 'literal aggregate writer'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php' @(
    'Legacy_Migration_Assignment_Writer_Port_Interface',
    "'worksheet_assignments' === `$item->source_type()",
    'assignment_writer->write'
) 'owner dispatch'
Assert-Markers 'src\Operations\LegacyMigration\Port\Legacy_Migration_Assignment_Writer_Port_Interface.php' @(
    'Joins the caller transaction',
    'never commits an import item receipt itself'
) 'transaction ownership port'
Assert-Markers 'tests\Contract\legacy-migration-assignment-g5-imp-07-s03-d3-d4.php' @(
    'A learner from another assignment in the same Context leaked into assignment 41.',
    'Context-only @1.1.0 mapping plan remained executable.',
    'Target failure left an assignment root after rollback.',
    'D3-D4 runtime crossed a frozen boundary:'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s03-d3-d-owner-migration.html' @(
    'G5-IMP-07-S03-D3-D4',
    'D3-D4-G',
    'oj_ai.legacy_migration_plan@1.2.0',
    'check:legacy-migration-assignment-g5',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S03-D3-D4-G',
    'caller-owned transaction',
    'G5-IMP-07-S03-D3-D5',
    'G5-IMP-07-S03-D3-D6',
    'check:legacy-migration-assignment-g5'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-25-R110-OBSERVED',
    'G5-IMP-07-S03-D3-D4',
    'check:legacy-migration-assignment-g5',
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:legacy-migration-assignment-g5',
    'check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1',
    'Legacy migration assignment G5-IMP-07-S03-D3-D4 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-assignment-g5-imp-07-s03-d3-d4.php'))

Write-Output 'G5-IMP-07-S03-D3-D4 passed: assignment-bound plan 1.2, closed historical roots, exact learner targets, caller-owned rollback, and zero platform writes are frozen.'
