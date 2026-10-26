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
            throw "G5-IMP-07-S04 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-recovery-g5-imp-07-s03-f.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Run_Query_Port_Interface.php',
    'src\Operations\LegacyMigration\Application\Read_Legacy_Migration_Run_Result.php',
    'src\Operations\LegacyMigration\Application\Read_Legacy_Migration_Run.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Run_Query.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Run_Query_Rest_Controller.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Run_Query_Rest_Module.php',
    'src\Operations\LegacyMigration\Application\Legacy_Migration_Failure.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\legacy-migration-run-query-g5-imp-07-s04.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = @(
    'src\Operations\LegacyMigration\Application\Read_Legacy_Migration_Run.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Run_Query.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Run_Query_Rest_Controller.php'
)
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S04 crossed a frozen boundary: $file -> $forbidden"
        }
    }
}

$querySource = Get-Content -LiteralPath (Join-Path $root 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Run_Query.php') -Raw -Encoding UTF8
foreach ($write in @('INSERT ', 'UPDATE ', 'DELETE ', 'DROP ', 'ALTER ', 'CREATE ', 'TRUNCATE ', 'RENAME ')) {
    if ($querySource.ToUpperInvariant().Contains($write)) {
        throw "G5-IMP-07-S04 query adapter contains a write marker: $write"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Run_Query.php' @(
    "private const TABLE_RUNS = 'oj_ai_import_runs';",
    "private const TABLE_ITEMS = 'oj_ai_import_items';",
    'single-statement snapshot adapter',
    'observed_item_total'
) 'query adapter'
Assert-Markers 'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Run_Query_Rest_Controller.php' @(
    'A-DS08-Q29',
    'ROUTE_SHOW',
    "'methods' => 'GET'",
    'Addon_Capabilities::MANAGE_MIGRATIONS',
    "'platform_write_count' => 0"
) 'REST boundary'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'new WordPress_Legacy_Migration_Run_Query()',
    'new Legacy_Migration_Run_Query_Rest_Module'
) 'Composition Root'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s04-migration-run-query.html' @(
    'G5-IMP-07-S04',
    'A-DS08-Q29',
    'check:legacy-migration-run-query-g5',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S04',
    'check:legacy-migration-run-query-g5',
    'IMP-07 4/6',
    'G5-IMP-07-S05'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:legacy-migration-run-query-g5',
    'check-legacy-migration-run-query-g5-imp-07-s04.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-run-query-g5-imp-07-s04.ps1',
    'Legacy migration Q29 query G5-IMP-07-S04 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-run-query-g5-imp-07-s04.php'))

Write-Output 'G5-IMP-07-S04 passed: Q29 safe checkpoint and item summary query, canonical integrity, literal tables, zero mutation, and zero platform writes are frozen.'
