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
            throw "G5-IMP-07-S02 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-preview-g5-imp-07-s01.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Command.php',
    'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Result.php',
    'src\Operations\LegacyMigration\Application\Legacy_Migration_Failure.php',
    'src\Operations\LegacyMigration\Application\Preview_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php',
    'src\Operations\LegacyMigration\Infrastructure\Signed_Legacy_Preview_Token.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Approval_Repository.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Approval_Repository_Port_Interface.php',
    'src\Operations\LegacyMigration\Port\Legacy_Preview_Token_Verifier_Port_Interface.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Approval_Rest_Controller.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Approval_Rest_Module.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\legacy-migration-approval-g5-imp-07-s02.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = @(
    'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration.php',
    'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Command.php',
    'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Approval_Repository.php',
    'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Approval_Rest_Controller.php'
)
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'CREATE TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S02 approval runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S02 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Domain\Legacy_Migration_Plan.php' @(
    'oj_ai.legacy_migration_plan@1.0.0'
    'mapping_decisions'
    'mapping_requirements'
    'plan_hash'
) 'shared deterministic plan'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\Signed_Legacy_Preview_Token.php' @(
    'Legacy_Preview_Token_Verifier_Port_Interface'
    'TOKEN_EXPIRED'
    '$now >= $expires'
) 'token verification'
Assert-Markers 'src\Operations\LegacyMigration\Application\Approve_Legacy_Migration.php' @(
    'TOKEN_SCOPE_MISMATCH'
    'SOURCE_STALE'
    'oj_ai.legacy_migration_preview@1.0.0'
    'Legacy_Migration_Plan'
) 'C70 application'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Approval_Repository.php' @(
    "'oj_ai_import_runs'"
    "'oj_ai_audit_events'"
    "'oj_ai_outbox_events'"
    'START TRANSACTION'
    'ROLLBACK'
    'legacy_migration.approved'
) 'atomic literal-table repository'
Assert-Markers 'src\Operations\LegacyMigration\Presentation\Legacy_Migration_Approval_Rest_Controller.php' @(
    '/migration-runs/commands/approve'
    'Addon_Capabilities::MANAGE_MIGRATIONS'
    '$result->replayed() ? 200 : 201'
    "'platform_write_count'"
) 'C70 REST contract'
Assert-Markers 'tests\Contract\legacy-migration-approval-g5-imp-07-s02.php' @(
    'C70 left a partial run after audit failure.'
    'C70 accepted a token at its expiry boundary.'
    'Same C70 request did not replay the same run ID.'
    'Concurrent C70 loser did not recover the winner run.'
    'C70 replay REST response must be 200.'
) 'contract fixture'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'Legacy_Migration_Approval_Rest_Controller'
    'Legacy_Migration_Approval_Rest_Module'
) 'production composition'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s02-legacy-migration-approval.html' @(
    'G5-IMP-07-S02'
    'check:legacy-migration-approval-g5'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-07-s02-legacy-migration-approval.html'
    'G5-IMP-07-S02-G'
    'G5-IMP-07-S03'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g5-imp-07-s02-legacy-migration-approval.html'
    'check:legacy-migration-approval-g5'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R107'
    'G5-IMP-07-S02'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:legacy-migration-approval-g5'
    'check-legacy-migration-approval-g5-imp-07-s02.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-approval-g5-imp-07-s02.ps1'
    'Legacy migration approval C70 G5-IMP-07-S02 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-approval-g5-imp-07-s02.php'))

Write-Output 'G5-IMP-07-S02 passed: C70 revalidates the signed preview and exact source, atomically creates one approved run with audit/outbox, replays durably, and writes no platform or business data.'
