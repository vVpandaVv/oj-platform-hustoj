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
            throw "G5-IMP-07-S03-D3-D8 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-private-files-g5-imp-07-s03-d3-d7.ps1')
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Owner_Matrix.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Factory.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php',
    'tests\Contract\legacy-migration-owner-matrix-g5-imp-07-s03-d3-d8.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -notlike 'tests\*' }
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'CREATE TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S03-D3-D8 runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S03-D3-D8 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Owner_Matrix.php' @(
    'array_keys( $tables ) !== array_keys( self::ENTRIES )',
    '$disposition !== $entry[''disposition'']',
    "'assignment'",
    "'submission'",
    "'zip'",
    "'evaluation'",
    'migration_source_types'
) '35-table owner matrix'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Factory.php' @(
    'WordPress_Legacy_Migration_Assignment_Writer',
    'WordPress_Legacy_Migration_Submission_Writer',
    'WordPress_Legacy_Migration_Evaluation_Writer',
    'WordPress_Legacy_Migration_Zip_Writer',
    'WordPress_Legacy_Migration_Batch_Repository',
    'Process_Legacy_Migration_Batch',
    'without registering a REST route or worker hook'
) 'production batch graph'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php' @(
    'Legacy_Migration_Owner_Matrix',
    'HANDLER_DIRECT',
    'HANDLER_ASSIGNMENT',
    'HANDLER_SUBMISSION',
    'HANDLER_ZIP',
    'HANDLER_EVALUATION'
) 'matrix-bound target dispatch'
Assert-Markers 'tests\Contract\legacy-migration-owner-matrix-g5-imp-07-s03-d3-d8.php' @(
    'Owner matrix did not freeze exactly 35 source tables.',
    'The bounded 250-item owner dispatch lost an item.',
    'Mapping-only source reached a target owner.',
    'Production graph lost the atomic batch repository.',
    'Private owners did not share one scanner policy.'
) 'integration contract'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s03-d3-d-owner-migration.html' @(
    'G5-IMP-07-S03-D3-D8',
    'id="d3-d8-result"',
    'D3-D 8/8',
    'check:legacy-migration-owner-matrix-g5',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S03-D3-D8',
    'D3-D 8/8',
    'G5-IMP-07-S03-D4-B',
    'check:legacy-migration-owner-matrix-g5'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-26-R114-OBSERVED',
    'G5-IMP-07-S03-D3-D8',
    'check:legacy-migration-owner-matrix-g5',
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:legacy-migration-owner-matrix-g5',
    'check-legacy-migration-owner-matrix-g5-imp-07-s03-d3-d8.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-owner-matrix-g5-imp-07-s03-d3-d8.ps1',
    'Legacy migration owner matrix G5-IMP-07-S03-D3-D8 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-owner-matrix-g5-imp-07-s03-d3-d8.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-source-item-reader-g5-imp-07-s03.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-target-writer-g5-imp-07-s03.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-worksheet-target-writer-g5-imp-07-s03.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-batch-g5-imp-07-s03.php'))

Write-Output 'G5-IMP-07-S03-D3-D8 passed: exact 35-table disposition and owner coverage, complete production graph, 250-item batch, rollback, retry, stale and duplicate exclusion, and zero platform writes are frozen.'
