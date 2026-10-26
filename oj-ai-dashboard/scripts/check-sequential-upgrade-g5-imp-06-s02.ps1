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
            throw "G5-IMP-06-S02 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    foreach ($script in @(
        'check-database-foundation.ps1',
        'check-database-infrastructure.ps1',
        'check-activation-database.ps1',
        'check-runtime-schema-readiness.ps1'
    )) {
        Invoke-Checked 'powershell' @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot $script)
        )
    }
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-fresh-install-g5-imp-06-s01.ps1'),
        '-SkipConstituentChecks'
    )
}

foreach ($file in @(
    'src\Database\Foundation\Schema_Catalog.php',
    'src\Database\Foundation\Migration_Plan.php',
    'src\Database\Foundation\Schema_Installation_Runner.php',
    'src\Core\Activation_Workflow.php',
    'tests\Contract\sequential-upgrade-g5-imp-06-s02.php'
)) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

foreach ($file in @(
    'src\Database\Foundation\Schema_Catalog.php',
    'src\Database\Foundation\Migration_Plan.php',
    'src\Database\Foundation\Schema_Installation_Runner.php',
    'src\Database\Foundation\Infrastructure\WordPress_Schema_Inspector.php',
    'src\Database\Foundation\Infrastructure\WordPress_Schema_Ledger.php',
    'src\Database\Foundation\Infrastructure\WordPress_Schema_Statement_Executor.php'
)) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-06-S02 upgrade boundary contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'OJ\\Platform\\') {
        throw "G5-IMP-06-S02 upgrade boundary references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Database\Foundation\Schema_Catalog.php' @(
    "SCHEMA_VERSION = '1.0.4'"
    'TABLE_COUNT    = 48'
) 'target schema'
Assert-Markers 'src\Database\Foundation\Migration_Plan.php' @(
    "BASELINE_SCHEMA_VERSION = '1.0.0'"
    "PREVIOUS_SCHEMA_VERSION = '1.0.1'"
    '202608220001_export_job_context_identity'
    '202608230001_expand_submission_prompt_contract'
    '202608300001_add_evaluation_run_last_error_message'
) 'immutable migration plan'
Assert-Markers 'src\Database\Foundation\Schema_Installation_Runner.php' @(
    'is_supported_upgrade_state'
    'upgrade_ledger_issues'
    'upgrade_source_tables'
    'Schema_Installation_Result::UPGRADED'
    'Schema_Installation_Result::ALREADY_READY'
) 'sequential runner'
Assert-Markers 'tests\Contract\sequential-upgrade-g5-imp-06-s02.php' @(
    'upgrade:1.0.0-20260825'
    'upgrade:1.0.1-20260825'
    'upgrade:future-20260825'
    'upgrade:checksum-20260825'
    'upgrade:physical-drift-20260825'
    'g5_s02_assert_blocked_without_writes'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-06-s02-sequential-upgrade.html' @(
    'G5-IMP-06-S02'
    'IMP-06 2/5'
    'schema 1.0.2'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-06-s02-sequential-upgrade.html'
    'G5-IMP-06-S02-G'
    'G5-IMP-06-S03'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g5-imp-06-s02-sequential-upgrade.html'
    'schema 1.0.2'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R102'
    'G5-IMP-06-S02'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:sequential-upgrade-g5'
    'check-sequential-upgrade-g5-imp-06-s02.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-sequential-upgrade-g5-imp-06-s02.ps1'
    'Sequential upgrade G5-IMP-06-S02 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\sequential-upgrade-g5-imp-06-s02.php'))
Write-Output 'G5-IMP-06-S02 passed: exact 1.0.0 and 1.0.1 sources upgrade sequentially, target is a no-op, invalid sources fail before lock and DDL, and platform boundaries remain unchanged.'
