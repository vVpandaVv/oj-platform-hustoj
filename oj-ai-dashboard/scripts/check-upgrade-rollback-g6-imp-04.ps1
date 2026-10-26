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
$node = (Get-Command node -ErrorAction Stop).Source

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
            throw "G6-IMP-04 $Label marker is missing: $marker"
        }
    }
}

$dimensionEvidence = [ordered]@{
    'G6-IMP-04-S01' = @(
        'scripts\check-addon-api-consumer.ps1',
        'scripts\check-database-foundation.ps1',
        'scripts\check-activation-database.ps1',
        'scripts\check-runtime-schema-readiness.ps1',
        'scripts\check-assessment-configuration-bootstrap-s01.ps1',
        'scripts\check-fresh-install-g5-imp-06-s01.ps1'
    )
    'G6-IMP-04-S02' = @(
        'scripts\check-sequential-upgrade-g5-imp-06-s02.ps1',
        'scripts\check-failure-restart-g5-imp-06-s03.ps1',
        'scripts\check-forward-repair-g5-imp-06-s04.ps1',
        'scripts\check-data-preservation-g5-imp-06-s05.ps1'
    )
    'G6-IMP-04-S03' = @(
        'scripts\check-sequential-upgrade-g5-imp-06-s02.ps1',
        'scripts\check-data-preservation-g5-imp-06-s05.ps1',
        'scripts\check-release-package-boundary.ps1'
    )
    'G6-IMP-04-S04' = @(
        'scripts\check-activation-database.ps1',
        'scripts\check-assessment-configuration-bootstrap-s01.ps1',
        'scripts\check-legacy-migration-runtime-g5-imp-07-s03-e.ps1',
        'scripts\check-purge-execution-g5-imp-08-s04.ps1'
    )
    'G6-IMP-04-S05' = @(
        'scripts\check-addon-api-consumer.ps1',
        'scripts\check-runtime-schema-readiness.ps1',
        'scripts\check-release-package-boundary.ps1'
    )
}

$runnerSource = Get-Content -LiteralPath (Join-Path $root 'scripts\run-tests.mjs') -Raw -Encoding UTF8
foreach ($dimension in $dimensionEvidence.Keys) {
    foreach ($relativePath in $dimensionEvidence[$dimension]) {
        if (-not (Test-Path -LiteralPath (Join-Path $root $relativePath))) {
            throw "G6-IMP-04 evidence file is missing: $relativePath"
        }
        if (-not (Test-OjaiPhpMarker -Source $runnerSource -Marker $relativePath.Replace('\', '/') -Php $php)) {
            throw "G6-IMP-04 aggregate runner evidence is missing: $relativePath"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Push-Location $root
    try {
        Invoke-Checked $node @('scripts\run-tests.mjs')
    } finally {
        Pop-Location
    }
}

$contract = Join-Path $root 'tests\Contract\upgrade-rollback-g6-imp-04.php'
Invoke-Checked $php @('-l', $contract)
Invoke-Checked $php @($contract)

Assert-Markers 'src\Database\Foundation\Schema_Catalog.php' @(
    "SCHEMA_VERSION = '1.0.4'", 'TABLE_COUNT    = 48'
) 'target schema'
Assert-Markers 'src\Database\Foundation\Migration_Plan.php' @(
    "BASELINE_SCHEMA_VERSION = '1.0.0'", "PREVIOUS_SCHEMA_VERSION = '1.0.1'"
) 'supported upgrade'
Assert-Markers 'tests\Contract\sequential-upgrade-g5-imp-06-s02.php' @(
    'upgrade:future-20260825', 'g5_s02_assert_blocked_without_writes', 'schema_preflight_blocked'
) 'rollback fail-closed'
Assert-Markers 'tests\Contract\activation-database-integration.php' @(
    'A verified reactivation must be idempotent.', 'Reactivation must refresh the code version after verification.'
) 'reactivation'
Assert-Markers 'src\Core\Deactivation.php' @(
    'Legacy_Migration_Worker_Module::clear();', 'Purge_Worker_Module::clear();',
    'Worksheet_Evaluation_Worker_Module::clear();', 'Submission_Evaluation_Worker_Module::clear();',
    'Report_Export_Worker_Module::clear();', 'Worksheet_Package_Worker_Module::clear();'
) 'worker cleanup'
Assert-Markers 'scripts\build-release.ps1' @(
    'Release packaging requires source_dirty=false.', '$requiredEntries = @(', '$forbiddenDirectories'
) 'evidence package boundary'
Assert-Markers 'docs\ai-assessment\g6-imp-04-upgrade-rollback.html' @(
    '<title>G6-IMP-04', 'G6-IMP-04-S01', 'G6-IMP-04-S05', '13 existing gates',
    'check:upgrade-rollback-g6', 'G6 4/5', 'Platform API change: 0', 'G6-IMP-05'
) 'acceptance HTML'
Assert-Markers 'docs\ai-assessment\a-ds-16-cross-validation-freeze.html' @(
    'id="amendment-12"', 'G6-IMP-04', '13 existing gates',
    'check:upgrade-rollback-g6', 'G6 4/5', 'G6-IMP-05'
) 'A-DS-16 synchronization'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g6-imp-04-upgrade-rollback.html', 'check:upgrade-rollback-g6', 'G6 4/5'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g6-imp-04-upgrade-rollback.html', 'G6-IMP-04', 'G6 4/5', 'G6-IMP-05'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\design-procedure.html' @(
    'g6-imp-04-upgrade-rollback.html', 'G6-IMP-04', 'G6 4/5', 'G6-IMP-05'
) 'design procedure'
Assert-Markers 'docs\ai-assessment\index.html' @(
    'g6-imp-04-upgrade-rollback.html', 'check:upgrade-rollback-g6', 'G6 4/5', 'G6-IMP-05'
) 'document index'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-26-R129-OBSERVED', 'G6-IMP-04', 'Platform API change: 0', 'G6-IMP-05'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:upgrade-rollback-g6', 'check-upgrade-rollback-g6-imp-04.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-upgrade-rollback-g6-imp-04.ps1', '-SkipConstituentChecks',
    'G6-IMP-04 upgrade and rollback passed.'
) 'aggregate runner'

Write-Output 'G6-IMP-04 passed: 13 existing gates enforce clean install, supported upgrade, rollback fail-closed, reactivation, worker restoration, and reproducible evidence.'
