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
            throw "G5-IMP-07-S06 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    foreach ($gate in @(
        'check-legacy-migration-preview-g5-imp-07-s01.ps1',
        'check-legacy-migration-approval-g5-imp-07-s02.ps1',
        'check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1',
        'check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1',
        'check-legacy-migration-assignment-g5-imp-07-s03-d3-d4.ps1',
        'check-legacy-migration-submission-g5-imp-07-s03-d3-d5.ps1',
        'check-legacy-migration-evaluation-g5-imp-07-s03-d3-d6.ps1',
        'check-legacy-migration-private-files-g5-imp-07-s03-d3-d7.ps1',
        'check-legacy-migration-owner-matrix-g5-imp-07-s03-d3-d8.ps1',
        'check-legacy-migration-runtime-g5-imp-07-s03-e.ps1',
        'check-legacy-migration-recovery-g5-imp-07-s03-f.ps1',
        'check-legacy-migration-run-query-g5-imp-07-s04.ps1',
        'check-legacy-migration-rollback-g5-imp-07-s05.ps1'
    )) {
        Invoke-Checked 'powershell' @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot $gate), '-SkipConstituentChecks'
        )
    }
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Infrastructure\Signed_Legacy_Preview_Token.php',
    'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Application\Execute_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Application\Process_Legacy_Migration_Batch.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Execution_Repository.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Rollback_Repository.php',
    'src\Storage\Infrastructure\Local_Private_Storage_Root.php',
    'src\Storage\Infrastructure\Local_Private_File_Store.php',
    'tests\Contract\legacy-migration-integration-freeze-g5-imp-07-s06.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'CodingPanda\OjPlatform\Kernel\')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S06 crossed a frozen target or platform boundary: $file -> $forbidden"
        }
    }
}

Assert-Markers 'tests\Contract\legacy-migration-integration-freeze-g5-imp-07-s06.php' @(
    'Expired preview token was accepted at the exact boundary.',
    'Fresh preview token could not enter reapproval.',
    'C62 site lock prefix is missing.',
    'C63 site lock prefix no longer matches C62.',
    'Private purge retry was not idempotent after byte deletion.',
    'G5-IMP-07-S06 passed:'
) 'integration contract'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s06-legacy-migration-integration-freeze.html' @(
    '<title>G5-IMP-07-S06',
    'id="result"',
    'IMP-07 6/6',
    'check:legacy-migration-integration-g5',
    'Platform API change: 0',
    'G5-IMP-08-S01'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-07-s06-legacy-migration-integration-freeze.html',
    'id="now"',
    'IMP-07 6/6',
    'check:legacy-migration-integration-g5',
    'G5-IMP-08-S01'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:legacy-migration-integration-g5',
    'check-legacy-migration-integration-g5-imp-07-s06.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-integration-g5-imp-07-s06.ps1',
    'Legacy migration integration G5-IMP-07-S06 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-integration-freeze-g5-imp-07-s06.php'))

Write-Output 'G5-IMP-07-S06 passed: S01-S05 source drift, exact token expiry and re-preview, C62/C63 serialization, retry-safe purge, durable replay, and preservation boundaries are frozen.'
