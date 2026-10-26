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
            throw "G5-IMP-07-S05 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-run-query-g5-imp-07-s04.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Application\Legacy_Migration_Failure.php',
    'src\Operations\LegacyMigration\Application\Rollback_Legacy_Migration_Command.php',
    'src\Operations\LegacyMigration\Application\Rollback_Legacy_Migration_Result.php',
    'src\Operations\LegacyMigration\Application\Rollback_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Application\Read_Legacy_Migration_Run.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Rollback_Repository_Port_Interface.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Rollback_Repository.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Rollback_Rest_Controller.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Rollback_Rest_Module.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\legacy-migration-run-query-g5-imp-07-s04.php',
    'tests\Contract\legacy-migration-rollback-g5-imp-07-s05.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = @(
    'src\Operations\LegacyMigration\Application\Rollback_Legacy_Migration_Command.php',
    'src\Operations\LegacyMigration\Application\Rollback_Legacy_Migration_Result.php',
    'src\Operations\LegacyMigration\Application\Rollback_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Rollback_Repository_Port_Interface.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Rollback_Repository.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Rollback_Rest_Controller.php'
)
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S05 crossed a frozen boundary: $file -> $forbidden"
        }
    }
}

$repository = Get-Content -LiteralPath (Join-Path $root 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Rollback_Repository.php') -Raw -Encoding UTF8
foreach ($forbiddenDelete in @(
    'DELETE FROM oj_ai_import_runs',
    'DELETE FROM oj_ai_import_items',
    'DELETE FROM oj_ai_audit_events',
    'DELETE FROM oj_ai_outbox_events',
    'DELETE FROM oj_ai_private_files'
)) {
    if ($repository.Contains($forbiddenDelete)) {
        throw "G5-IMP-07-S05 deletes a preserved ledger or private tombstone: $forbiddenDelete"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Rollback_Repository.php' @(
    "private const TABLE_RUNS = 'oj_ai_import_runs';",
    "private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';",
    'execution_actor_user_id',
    'assert_external_references',
    'purge_private_files',
    "'purged'",
    'legal_hold_until_utc IS NULL',
    'delete_targets',
    "'DELETE FROM ' . `$table",
    "`$checkpoint['phase'] = 'compensated';",
    'EVENT_COMPENSATED'
) 'rollback repository'
Assert-Markers 'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Rollback_Rest_Controller.php' @(
    'A-DS08-C63',
    'ROUTE_ROLLBACK',
    "'methods'             => 'POST'",
    "ACTION_ROLLBACK = 'operations.legacy_migration.rollback'",
    'Addon_Capabilities::MANAGE_MIGRATIONS',
    'Legacy_Migration_Failure::TARGET_CHANGED',
    "'platform_write_count'"
) 'REST boundary'
Assert-Markers 'src\Core\Composition_Root.php' @(
    '$private_files = new Local_Private_File_Store();',
    'new WordPress_Legacy_Migration_Rollback_Repository( $canonical, $private_files )',
    'new Legacy_Migration_Rollback_Rest_Module'
) 'Composition Root'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s05-legacy-migration-rollback.html' @(
    'G5-IMP-07-S05',
    'A-DS08-C63',
    'check:legacy-migration-rollback-g5',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S05',
    'check:legacy-migration-rollback-g5',
    'IMP-07 5/6',
    'G5-IMP-07-S06'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:legacy-migration-rollback-g5',
    'check-legacy-migration-rollback-g5-imp-07-s05.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-rollback-g5-imp-07-s05.ps1',
    'Legacy migration C63 rollback G5-IMP-07-S05 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-run-query-g5-imp-07-s04.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-rollback-g5-imp-07-s05.php'))

Write-Output 'G5-IMP-07-S05 passed: strict C63 REST, execution-actor ownership, unchanged-target reverse deletion, private byte purge plus tombstone, durable replay, preserved migration ledgers, and zero platform writes are frozen.'
