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
            throw "G5-IMP-07-S01 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-data-preservation-g5-imp-06-s05.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Application\Legacy_Migration_Failure.php',
    'src\Operations\LegacyMigration\Application\Preview_Legacy_Migration_Command.php',
    'src\Operations\LegacyMigration\Application\Preview_Legacy_Migration_Result.php',
    'src\Operations\LegacyMigration\Application\Preview_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot.php',
    'src\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2.php',
    'src\Operations\LegacyMigration\Infrastructure\Signed_Legacy_Preview_Token.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Site.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Prefixed_Legacy_Source_Inspector.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface.php',
    'src\Operations\LegacyMigration\Port\Legacy_Preview_Token_Port_Interface.php',
    'src\Operations\LegacyMigration\Port\Legacy_Preview_Token_Verifier_Port_Interface.php',
    'src\Operations\LegacyMigration\Port\Legacy_Source_Inspector_Port_Interface.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Preview_Rest_Controller.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Preview_Rest_Module.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\legacy-migration-preview-g5-imp-07-s01.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = @(
    'src\Operations\LegacyMigration\Application\Preview_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2.php',
    'src\Operations\LegacyMigration\Infrastructure\Signed_Legacy_Preview_Token.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Prefixed_Legacy_Source_Inspector.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Preview_Rest_Controller.php'
)
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'INSERT INTO', 'UPDATE oj_ai_', 'DELETE FROM')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S01 preview runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S01 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2.php' @(
    'oj_ai_dashboard_wpdb_prefixed@0.1.2'
    'oj_ai_academic_years'
    'oj_ai_import_items'
    'local_academic_master_not_imported'
    'legacy_import_ledger_not_replayed'
) 'exact source profile'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Prefixed_Legacy_Source_Inspector.php' @(
    '$database->prefix'
    'information_schema.TABLES'
    'SHOW COLUMNS FROM'
    'SELECT * FROM'
    'hash_file'
    'SOURCE_COLLISION'
) 'read-only source inspector'
Assert-Markers 'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php' @(
    'oj_ai.legacy_migration_plan@1.0.0'
    'mapping_decisions'
    'mapping_requirements'
    'plan_hash'
) 'deterministic preview plan'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\Signed_Legacy_Preview_Token.php' @(
    'MAXIMUM_TOKEN_BYTES = 4096'
    'TTL_SECONDS = 1800'
    'source_fingerprint'
    'idempotency_key_hash'
    'random_bytes( 16 )'
) 'signed 30-minute token'
Assert-Markers 'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Preview_Rest_Controller.php' @(
    '/migration-runs/commands/preview'
    'Addon_Capabilities::MANAGE_MIGRATIONS'
    "'mutation_count'"
    "'ledger_mutation_count'"
    "'platform_write_count'"
) 'C61 REST contract'
Assert-Markers 'tests\Contract\legacy-migration-preview-g5-imp-07-s01.php' @(
    'exact legacy inventory'
    'One source row change did not change the source fingerprint.'
    'C61 preview token exceeded 4 KiB.'
    'C61 accepted an invalid standard nonce.'
) 'contract fixture'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'Legacy_Migration_Preview_Rest_Controller'
    'Legacy_Migration_Preview_Rest_Module'
) 'production composition'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s01-legacy-migration-preview.html' @(
    'G5-IMP-07-S01'
    'check:legacy-migration-preview-g5'
    'mutation 0'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-07-s01-legacy-migration-preview.html'
    'G5-IMP-07-S01-G'
    'G5-IMP-07-S02'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g5-imp-07-s01-legacy-migration-preview.html'
    'check:legacy-migration-preview-g5'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R106'
    'G5-IMP-07-S01'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:legacy-migration-preview-g5'
    'check-legacy-migration-preview-g5-imp-07-s01.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-preview-g5-imp-07-s01.ps1'
    'Legacy migration preview C61 G5-IMP-07-S01 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-preview-g5-imp-07-s01.php'))

Write-Output 'G5-IMP-07-S01 passed: C61 reads one exact legacy profile, fingerprints rows and blobs, returns a 30-minute idempotency-bound token, and mutates no add-on ledger or platform data.'
