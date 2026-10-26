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
            throw "G5-IMP-06-S03 $Label marker is missing: $marker"
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
        (Join-Path $PSScriptRoot 'check-sequential-upgrade-g5-imp-06-s02.ps1'),
        '-SkipConstituentChecks'
    )
}

foreach ($file in @(
    'src\Database\Foundation\Schema_Migration_Checkpoint.php',
    'src\Database\Foundation\Schema_Installation_Runner.php',
    'src\Database\Foundation\Schema_Runtime_Readiness_Verifier.php',
    'src\Database\Foundation\Infrastructure\WordPress_Schema_Ledger.php',
    'tests\Contract\database-infrastructure.php',
    'tests\Contract\runtime-schema-readiness.php',
    'tests\Contract\failure-restart-g5-imp-06-s03.php'
)) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

foreach ($file in @(
    'src\Database\Foundation\Schema_Migration_Checkpoint.php',
    'src\Database\Foundation\Schema_Installation_Runner.php',
    'src\Database\Foundation\Schema_Runtime_Readiness_Verifier.php',
    'src\Database\Foundation\Infrastructure\WordPress_Schema_Ledger.php',
    'src\Database\Foundation\Infrastructure\WordPress_Schema_Statement_Executor.php'
)) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-06-S03 restart boundary contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'OJ\\Platform\\') {
        throw "G5-IMP-06-S03 restart boundary references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Database\Foundation\Schema_Migration_Checkpoint.php' @(
    'oj_ai.schema_migration_checkpoint'
    'schema_checkpoint_hash_mismatch'
    'schema_checkpoint_json_noncanonical'
    'schema_checkpoint_payload_invalid'
    'state_matches_payload'
) 'checkpoint integrity'
Assert-Markers 'src\Database\Foundation\Schema_Installation_Runner.php' @(
    'Schema_Migration_Checkpoint::issues'
    'schema_migration_ledger_invalid'
    'schema_existing_table_drift'
    'Migration_Ledger_State::REPAIR_REQUIRED'
    'Installation_State::MAINTENANCE_REQUIRED'
) 'restart runner'
Assert-Markers 'tests\Contract\failure-restart-g5-imp-06-s03.php' @(
    'CREATE, ALTER, postcondition, checkpoint write, ledger finish, and final state failures'
    'schema_checkpoint_json_noncanonical'
    'repair:restart-success-20260825'
    '51, count( array_unique( $restart_store->statements ) )'
    'fail_once_at_operation'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-06-s03-failure-restart.html' @(
    'G5-IMP-06-S03'
    'IMP-06 3/5'
    'schema_checkpoint_hash_mismatch'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-06-s03-failure-restart.html'
    'G5-IMP-06-S03-G'
    'G5-IMP-06-S04'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g5-imp-06-s03-failure-restart.html'
    'check:failure-restart-g5'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R103'
    'G5-IMP-06-S03'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:failure-restart-g5'
    'check-failure-restart-g5-imp-06-s03.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-failure-restart-g5-imp-06-s03.ps1'
    'Failure state and restart G5-IMP-06-S03 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\failure-restart-g5-imp-06-s03.php'))
Write-Output 'G5-IMP-06-S03 passed: checkpoint integrity fails before lock and DDL, normal activation stays blocked, and authorized repair resumes CREATE and ALTER paths without duplicate forward DDL.'
