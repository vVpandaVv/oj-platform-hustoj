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
            throw "G5-IMP-07-S03-D3-D3 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-mapping-g5-imp-07-s03-d3-d2.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Publication.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Publication_Projector.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Publication_Writer.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Publication_Writer_Port_Interface.php',
    'tests\Contract\legacy-migration-publication-g5-imp-07-s03-d3-d3.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = @(
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Publication.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Publication_Projector.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Publication_Writer.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Publication_Writer_Port_Interface.php'
)
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'CREATE TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S03-D3-D3 runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S03-D3-D3 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Domain\Legacy_Migration_Publication_Projector.php' @(
    'legacy-migration@1.0.0',
    'oj_ai.legacy_migration_configuration@1.0.0',
    'Worksheet_Preview_Projector',
    'Worksheet_Projection_Safety_Inspector',
    'source_revision_hash'
) 'canonical projector'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Publication_Writer.php' @(
    "'oj_ai_import_items'",
    "'oj_ai_worksheets'",
    "'oj_ai_worksheet_revisions'",
    "'oj_ai_worksheet_publications'",
    "private const PUBLICATION_STATE = 'retired'",
    'latest_publication_public_id',
    'ensure_for_assignment'
) 'literal storage writer'
Assert-Markers 'src\Operations\LegacyMigration\Port\Legacy_Migration_Publication_Writer_Port_Interface.php' @(
    'caller-owned assignment transaction',
    'does not create an import item receipt',
    'ensure_for_assignment'
) 'D3-D4 hand-off port'
Assert-Markers 'src\Submission\Assignment\Infrastructure\WordPress_Assignment_Repository.php' @(
    "'active' !== ( `$row['state']",
    'latest_publication_public_id'
) 'normal assignment reuse block'
Assert-Markers 'tests\Contract\legacy-migration-publication-g5-imp-07-s03-d3-d3.php' @(
    'Historical-only publication was exposed as active.',
    'D3-D3 consumed an assignment or extra import item receipt.',
    'Active publication was accepted as a migration-only replay.',
    'Migration publication SQL crossed a frozen boundary:'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s03-d3-d-owner-migration.html' @(
    'G5-IMP-07-S03-D3-D3',
    'D3-D3-F',
    'check:legacy-migration-publication-g5',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S03-D3-D3-F',
    'immutable publication',
    'G5-IMP-07-S03-D3-D4',
    'G5-IMP-07-S03-D3-D5',
    'G5-IMP-07-S03-D3-D6',
    'check:legacy-migration-publication-g5'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-25-R109-OBSERVED',
    'G5-IMP-07-S03-D3-D3',
    'check:legacy-migration-publication-g5',
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:legacy-migration-publication-g5',
    'check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-publication-g5-imp-07-s03-d3-d3.ps1',
    'Legacy migration publication G5-IMP-07-S03-D3-D3 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-worksheet-target-writer-g5-imp-07-s03.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-publication-g5-imp-07-s03-d3-d3.php'))

Write-Output 'G5-IMP-07-S03-D3-D3 passed: exact worksheet receipts, canonical historical projections, retired immutable storage, replay closure, and zero platform writes are frozen.'
