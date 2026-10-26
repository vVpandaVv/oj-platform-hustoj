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
            throw "G5-IMP-06-S05 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    foreach ($entry in @(
        @{ Script = 'check-fresh-install-g5-imp-06-s01.ps1'; Arguments = @('-SkipConstituentChecks') },
        @{ Script = 'check-sequential-upgrade-g5-imp-06-s02.ps1'; Arguments = @('-SkipConstituentChecks') },
        @{ Script = 'check-failure-restart-g5-imp-06-s03.ps1'; Arguments = @('-SkipConstituentChecks') },
        @{ Script = 'check-forward-repair-g5-imp-06-s04.ps1'; Arguments = @('-SkipConstituentChecks', '-SkipBrowser') },
        @{ Script = 'check-assessment-configuration-bootstrap-s01.ps1'; Arguments = @() }
    )) {
        $arguments = @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot $entry.Script)
        ) + @($entry.Arguments)
        Invoke-Checked 'powershell' $arguments
    }
}

Invoke-Checked $php @('-l', (Join-Path $root 'tests\Contract\schema-data-preservation-g5-imp-06-s05.php'))

foreach ($file in @(
    'src\Database\Foundation\Migration_Plan.php',
    'src\Database\Foundation\Schema_Installation_Runner.php',
    'src\Database\Foundation\Infrastructure\WordPress_Schema_Statement_Executor.php',
    'src\Operations\SchemaRepair\Application\Repair_Addon_Schema.php',
    'src\Operations\SchemaRepair\Infrastructure\WordPress_Schema_Repair_Receipt.php'
)) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'function down(')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-06-S05 preservation runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-06-S05 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'tests\Contract\schema-data-preservation-g5-imp-06-s05.php' @(
    'fresh install isolation'
    'Sequential upgrade changed protected rows, settings, files, or receipts.'
    'Partial upgrade changed protected data before C75.'
    'C75 replay changed rows, files, settings, options, or receipts.'
    'oj_ai_private_files'
    'oj_ai_audit_events'
    'oj_ai_outbox_events'
) 'integration contract'
Assert-Markers 'docs\ai-assessment\g5-imp-06-s05-data-preservation.html' @(
    'G5-IMP-06-S05'
    'IMP-06 5/5'
    'check:data-preservation-g5'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-06-s05-data-preservation.html'
    'G5-IMP-06-S05-G'
    'G5-IMP-07-S01'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g5-imp-06-s05-data-preservation.html'
    'check:data-preservation-g5'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R105'
    'G5-IMP-06-S05'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:data-preservation-g5'
    'check-data-preservation-g5-imp-06-s05.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-data-preservation-g5-imp-06-s05.ps1'
    'Schema data preservation G5-IMP-06-S05 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\schema-data-preservation-g5-imp-06-s05.php'))

Write-Output 'G5-IMP-06-S05 passed: install, upgrade, failure, C75 repair, replay, business rows, settings, private bytes, and prior receipts remain preservation-safe.'
