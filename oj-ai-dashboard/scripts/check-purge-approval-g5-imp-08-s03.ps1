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
            throw "G5-IMP-08-S03 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-purge-preview-g5-imp-08-s02.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\Purge\Application\Approve_Purge.php',
    'src\Operations\Purge\Application\Approve_Purge_Command.php',
    'src\Operations\Purge\Application\Approve_Purge_Result.php',
    'src\Operations\Purge\Application\Purge_Failure.php',
    'src\Operations\Purge\Application\Purge_Preview_Builder.php',
    'src\Operations\Purge\Application\Preview_Purge.php',
    'src\Operations\Purge\Infrastructure\WordPress_Purge_Approval_Repository.php',
    'src\Operations\Purge\Port\Purge_Approval_Repository_Port_Interface.php',
    'src\Operations\Purge\Presentation\Purge_Approval_Rest_Controller.php',
    'src\Operations\Purge\Presentation\Purge_Approval_Rest_Module.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\purge-approval-g5-imp-08-s03.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = @(
    'src\Operations\Purge\Application\Approve_Purge.php',
    'src\Operations\Purge\Application\Purge_Preview_Builder.php',
    'src\Operations\Purge\Infrastructure\WordPress_Purge_Approval_Repository.php',
    'src\Operations\Purge\Presentation\Purge_Approval_Rest_Controller.php'
)
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'DELETE FROM')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-08-S03 approval runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-08-S03 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\Purge\Application\Approve_Purge.php' @(
    'BACKUP_MAXIMUM_AGE_SECONDS = 86400'
    'APPROVAL_TTL_SECONDS = 1800'
    'TOKEN_SCOPE_MISMATCH'
    'LEGAL_HOLD_BLOCKED'
    'PREVIEW_STALE'
    'preview_json_hash'
) 'C72 revalidation'
Assert-Markers 'src\Operations\Purge\Application\Approve_Purge_Command.php' @(
    "CONFIRMATION_PHRASE = '"
    'backup_evidence_digest'
    'confirmation_phrase_hash'
    'operations.purge.approve'
) 'strict approval command'
Assert-Markers 'src\Operations\Purge\Infrastructure\WordPress_Purge_Approval_Repository.php' @(
    "'oj_ai_purge_jobs'"
    "'oj_ai_audit_events'"
    "'oj_ai_outbox_events'"
    'START TRANSACTION'
    'approval_token_hash'
    'IDEMPOTENCY_CONFLICT'
    'TOKEN_ALREADY_USED'
) 'atomic approval ledger'
Assert-Markers 'src\Operations\Purge\Presentation\Purge_Approval_Rest_Controller.php' @(
    '/purge-jobs/commands/approve'
    'Addon_Capabilities::MANAGE_MIGRATIONS'
    'Access_Policy_Request::CORE_DELETE_PLUGINS'
    "'business_mutation_count'"
    "'file_mutation_count'"
    "'platform_write_count'"
) 'C72 REST contract'
Assert-Markers 'tests\Contract\purge-approval-g5-imp-08-s03.php' @(
    'C72 delete_plugins AND gate drifted.'
    'C72 accepted a nonzero legal hold.'
    'C72 left a partial job after audit failure.'
    'zero deletion are frozen'
) 'contract fixture'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'Purge_Approval_Rest_Controller'
    'Purge_Approval_Rest_Module'
    'WordPress_Purge_Approval_Repository'
) 'production composition'
Assert-Markers 'docs\ai-assessment\g5-imp-08-s03-purge-approval.html' @(
    'G5-IMP-08-S03'
    'check:purge-approval-g5'
    'approved job'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-08-s03-purge-approval.html'
    'IMP-08 3/6'
    'G5-IMP-08-S04'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g5-imp-08-s03-purge-approval.html'
    'check:purge-approval-g5'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\a-ds-08-route-rest-command.html' @(
    'A-DS-08 Amendment 22'
    'g5-imp-08-s03-purge-approval.html'
) 'A-DS-08 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R122'
    'G5-IMP-08-S03'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:purge-approval-g5'
    'check-purge-approval-g5-imp-08-s03.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-purge-approval-g5-imp-08-s03.ps1'
    'Purge approval C72 G5-IMP-08-S03 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\purge-approval-g5-imp-08-s03.php'))

Write-Output 'G5-IMP-08-S03 passed: C72 revalidates token, site, key, scope, preview, 24-hour backup evidence, exact phrase, and legal-hold zero before atomically creating one approved purge job without deleting data.'
