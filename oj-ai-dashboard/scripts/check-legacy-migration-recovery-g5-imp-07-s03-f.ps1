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
            throw "G5-IMP-07-S03-F $Label marker is missing: $marker"
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
        (Join-Path $PSScriptRoot 'check-legacy-migration-owner-matrix-g5-imp-07-s03-d3-d8.ps1'),
        '-SkipConstituentChecks'
    )
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-runtime-g5-imp-07-s03-e.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Application\Execute_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Application\Process_Legacy_Migration_Batch.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Execution_Repository.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Repository.php',
    'tests\Contract\legacy-migration-execution-start-g5-imp-07-s03.php',
    'tests\Contract\legacy-migration-recovery-g5-imp-07-s03-f.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

foreach ($file in $phpFiles | Where-Object { $_ -like 'src\Operations\LegacyMigration\*' }) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'CREATE TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S03-F runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S03-F runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Application\Execute_Legacy_Migration.php' @(
    '$site_id = $this->sites->site_id();',
    "'site_id' => $site_id"
) 'execution service'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Execution_Repository.php' @(
    'WordPress_Schema_Lock',
    'oj_ai_dashboard_legacy_migration_',
    'WHERE state = %s AND public_id <> %s',
    'FOR UPDATE',
    'RUN_STATE_CONFLICT'
) 'site concurrency repository'
Assert-Markers 'tests\Contract\legacy-migration-execution-start-g5-imp-07-s03.php' @(
    'C62 allowed two different migration runs to execute on one site.',
    'C62 did not start the waiting run after the active run completed.',
    'C62 did not reject a busy site migration lock.'
) 'start concurrency contract'
Assert-Markers 'tests\Contract\legacy-migration-recovery-g5-imp-07-s03-f.php' @(
    'Worker source drift did not fail before the target transaction.',
    'Mid-batch SQL failure left a partial target or receipt.',
    'Retry from the durable checkpoint did not complete.',
    'Completed-run replay created duplicate targets or receipts.'
) 'recovery contract'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s03-d3-d-owner-migration.html' @(
    'id="s03-f-result"',
    'G5-IMP-07-S03-F',
    'check:legacy-migration-recovery-g5',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S03-F',
    'check:legacy-migration-recovery-g5',
    'G5-IMP-07-S03-G'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:legacy-migration-recovery-g5',
    'check-legacy-migration-recovery-g5-imp-07-s03-f.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-recovery-g5-imp-07-s03-f.ps1',
    'Legacy migration recovery G5-IMP-07-S03-F passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-recovery-g5-imp-07-s03-f.php'))

Write-Output 'G5-IMP-07-S03-F passed: source drift, mapping, site concurrency, stale worker, atomic SQL rollback, durable restart, zero duplicate target/item, and zero platform writes are frozen.'
