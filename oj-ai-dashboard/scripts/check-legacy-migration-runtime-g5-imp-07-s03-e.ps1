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
            throw "G5-IMP-07-S03-E $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-owner-matrix-g5-imp-07-s03-d3-d8.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Execution_Rest_Controller.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Execution_Rest_Module.php',
    'src\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Worker_Module.php',
    'src\Core\Composition_Root.php',
    'src\Core\Deactivation.php',
    'tests\Contract\legacy-migration-runtime-g5-imp-07-s03-e.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

foreach ($file in $phpFiles | Where-Object { $_ -like 'src\Operations\LegacyMigration\*' }) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'CREATE TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S03-E runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S03-E runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Execution_Rest_Controller.php' @(
    "'/migration-runs/(?P<run_id>[0-9A-HJKMNP-TV-Z]{26})/commands/execute'",
    "'operations.legacy_migration.execute'",
    'Addon_Capabilities::MANAGE_MIGRATIONS',
    "array( 'command_id', 'expected', 'payload' )",
    "array( 'plan_hash', 'source_fingerprint', 'state' )",
    "'replayed'",
    '202'
) 'C62 REST'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Worker_Module.php' @(
    'WordPress_Legacy_Migration_Queue_Wakeup::HOOK',
    '$this->worker->execute( $run_id );',
    'Public_Id::is_valid',
    'wp_clear_scheduled_hook'
) 'worker'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'Legacy_Migration_Execution_Rest_Controller',
    'Legacy_Migration_Execution_Rest_Module',
    'WordPress_Legacy_Migration_Execution_Repository',
    'WordPress_Legacy_Migration_Batch_Factory::create',
    'Legacy_Migration_Worker_Module'
) 'composition'
Assert-Markers 'src\Core\Deactivation.php' @(
    'Legacy_Migration_Worker_Module::clear();'
) 'deactivation'
Assert-Markers 'tests\Contract\legacy-migration-runtime-g5-imp-07-s03-e.php' @(
    'First C62 REST response must be 202.',
    'C62 replay REST response must remain 202.',
    'C62 replay created duplicate target, item, or ledger rows.',
    'C62 worker did not pass the exact scheduled run ID.'
) 'runtime contract'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s03-d3-d-owner-migration.html' @(
    'id="s03-e-result"',
    'G5-IMP-07-S03-E',
    'check:legacy-migration-runtime-g5',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S03-E',
    'legacy-migration-runtime-g5',
    'check:legacy-migration-runtime-g5',
    'G5-IMP-07-S03-F'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-26-R115-OBSERVED',
    'G5-IMP-07-S03-E',
    'check:legacy-migration-runtime-g5',
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:legacy-migration-runtime-g5',
    'check-legacy-migration-runtime-g5-imp-07-s03-e.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-runtime-g5-imp-07-s03-e.ps1',
    'Legacy migration C62 runtime G5-IMP-07-S03-E passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-runtime-g5-imp-07-s03-e.php'))

Write-Output 'G5-IMP-07-S03-E passed: C62 REST, Composition Root, exact async run handoff, durable replay, cleanup, zero duplicate target/item, and zero platform writes are frozen.'
