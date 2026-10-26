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
            throw "G5-IMP-08-S02 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-uninstall-data-preservation-g5-imp-08-s01.ps1')
    )
}

$phpFiles = @(
    'src\Operations\Purge\Application\Purge_Failure.php',
    'src\Operations\Purge\Application\Preview_Purge_Command.php',
    'src\Operations\Purge\Application\Preview_Purge_Result.php',
    'src\Operations\Purge\Application\Purge_Preview_Builder.php',
    'src\Operations\Purge\Application\Preview_Purge.php',
    'src\Operations\Purge\Domain\Purge_Inventory_Snapshot.php',
    'src\Operations\Purge\Infrastructure\Signed_Purge_Preview_Token.php',
    'src\Operations\Purge\Infrastructure\WordPress_Purge_Inventory.php',
    'src\Operations\Purge\Infrastructure\WordPress_Purge_Site.php',
    'src\Operations\Purge\Port\Purge_Inventory_Port_Interface.php',
    'src\Operations\Purge\Port\Purge_Preview_Token_Port_Interface.php',
    'src\Operations\Purge\Port\Purge_Preview_Token_Verifier_Port_Interface.php',
    'src\Operations\Purge\Port\Purge_Site_Port_Interface.php',
    'src\Operations\Purge\Presentation\Purge_Preview_Rest_Controller.php',
    'src\Operations\Purge\Presentation\Purge_Preview_Rest_Module.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\purge-preview-g5-imp-08-s02.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = @(
    'src\Operations\Purge\Application\Purge_Preview_Builder.php',
    'src\Operations\Purge\Application\Preview_Purge.php',
    'src\Operations\Purge\Infrastructure\Signed_Purge_Preview_Token.php',
    'src\Operations\Purge\Infrastructure\WordPress_Purge_Inventory.php',
    'src\Operations\Purge\Presentation\Purge_Preview_Rest_Controller.php'
)
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'INSERT INTO', 'DELETE FROM')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-08-S02 preview runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-08-S02 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\Purge\Infrastructure\WordPress_Purge_Inventory.php' @(
    "SELECT 'owner_rows'"
    'oj_ai_private_files'
    'private_blob_bytes'
    'legal_hold_count'
    'incomplete_jobs'
    'UNION ALL'
) 'single read snapshot'
Assert-Markers 'src\Operations\Purge\Application\Purge_Preview_Builder.php' @(
    'oj_ai.purge_preview@1.0.0'
    'scope_hash'
    'preview_hash'
    'owner_rows'
    'private_files'
    'incomplete_jobs'
) 'canonical preview profile'
Assert-Markers 'src\Operations\Purge\Application\Preview_Purge.php' @(
    'PREVIEW_PROFILE = Purge_Preview_Builder::PROFILE'
    '$this->builder->build'
) 'shared preview builder'
Assert-Markers 'src\Operations\Purge\Infrastructure\Signed_Purge_Preview_Token.php' @(
    'MAXIMUM_TOKEN_BYTES = 4096'
    'TTL_SECONDS = 1800'
    'scope_fingerprint'
    'preview_hash'
    'idempotency_key_hash'
    'random_bytes( 16 )'
) 'signed 30-minute token'
Assert-Markers 'src\Operations\Purge\Presentation\Purge_Preview_Rest_Controller.php' @(
    '/purge-jobs/commands/preview'
    'Addon_Capabilities::MANAGE_MIGRATIONS'
    "'mutation_count'"
    "'role_mutation_count'"
    "'file_mutation_count'"
    "'option_mutation_count'"
) 'C71 REST contract'
Assert-Markers 'tests\Contract\purge-preview-g5-imp-08-s02.php' @(
    'exact 48-table owner inventory'
    'One target row change did not invalidate the C71 preview hash.'
    'C71 token did not expire at exactly 30 minutes.'
    'C71 preview incorrectly requires delete_plugins.'
) 'contract fixture'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'Purge_Preview_Rest_Controller'
    'Purge_Preview_Rest_Module'
) 'production composition'
Assert-Markers 'docs\ai-assessment\g5-imp-08-s02-purge-preview.html' @(
    'G5-IMP-08-S02'
    'check:purge-preview-g5'
    'mutation 0'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-08-s02-purge-preview.html'
    'IMP-08 2/6'
    'G5-IMP-08-S03'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g5-imp-08-s02-purge-preview.html'
    'check:purge-preview-g5'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\a-ds-08-route-rest-command.html' @(
    'A-DS-08 Amendment 21'
    'g5-imp-08-s02-purge-preview.html'
) 'A-DS-08 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R121'
    'G5-IMP-08-S02'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:purge-preview-g5'
    'check-purge-preview-g5-imp-08-s02.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-purge-preview-g5-imp-08-s02.ps1'
    'Purge preview C71 G5-IMP-08-S02 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\purge-preview-g5-imp-08-s02.php'))

Write-Output 'G5-IMP-08-S02 passed: C71 reads the exact literal add-on inventory, returns an idempotency-bound 30-minute token, and mutates no ledger, role, file, option, or platform data.'
