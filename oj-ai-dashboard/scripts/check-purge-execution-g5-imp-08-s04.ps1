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
            throw "G5-IMP-08-S04 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-purge-approval-g5-imp-08-s03.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\Purge\Application\Execute_Purge.php',
    'src\Operations\Purge\Application\Execute_Purge_Command.php',
    'src\Operations\Purge\Application\Execute_Purge_Result.php',
    'src\Operations\Purge\Application\Cancel_Purge.php',
    'src\Operations\Purge\Application\Cancel_Purge_Command.php',
    'src\Operations\Purge\Application\Cancel_Purge_Result.php',
    'src\Operations\Purge\Application\Process_Purge_Batch.php',
    'src\Operations\Purge\Application\Purge_Failure.php',
    'src\Operations\Purge\Domain\Purge_Target_Catalog.php',
    'src\Operations\Purge\Domain\Purge_Job_Snapshot.php',
    'src\Operations\Purge\Domain\Purge_Batch_Result.php',
    'src\Operations\Purge\Port\Purge_Operation_Repository_Port_Interface.php',
    'src\Operations\Purge\Port\Purge_Queue_Wakeup_Port_Interface.php',
    'src\Operations\Purge\Infrastructure\WordPress_Purge_Operation_Repository.php',
    'src\Operations\Purge\Infrastructure\WordPress_Purge_Queue_Wakeup.php',
    'src\Operations\Purge\Infrastructure\Purge_Worker_Module.php',
    'src\Operations\Purge\Presentation\Purge_Control_Rest_Controller.php',
    'src\Operations\Purge\Presentation\Purge_Control_Rest_Module.php',
    'src\Security\Policy\Purge_Recovery_Access_Policy.php',
    'src\Core\Composition_Root.php',
    'src\Core\Deactivation.php',
    'tests\Contract\purge-execution-cancel-g5-imp-08-s04.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -notlike 'tests\*' }
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-08-S04 runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    foreach ($forbidden in @('update_option(', 'delete_option(', 'add_role(', 'remove_role(')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-08-S04 runtime mutates an excluded WordPress option or role: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-08-S04 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\Purge\Application\Execute_Purge.php' @(
    'APPROVAL_TTL_SECONDS = 1800'
    'BACKUP_MAXIMUM_AGE_SECONDS = 86400'
    'BATCH_SIZE = 250'
    'TIME_LIMIT_SECONDS = 20'
    'Purge_Failure::BACKUP_INVALID'
    '$operations - 3'
    '$purge_jobs - 1'
    '$incomplete_total - 1'
    "'stage' => 'private_files'"
) 'C73 exact approval delta'
Assert-Markers 'src\Operations\Purge\Domain\Purge_Target_Catalog.php' @(
    'TARGET_TABLE_COUNT = 43'
    "'oj_ai_installations'"
    "'oj_ai_schema_migrations'"
    "'oj_ai_audit_events'"
    "'oj_ai_outbox_events'"
    "'oj_ai_purge_jobs'"
    'array_reverse( $this->catalog->creation_order() )'
) 'reverse target catalog'
Assert-Markers 'src\Operations\Purge\Infrastructure\WordPress_Purge_Operation_Repository.php' @(
    'START TRANSACTION'
    'Installation_State::MAINTENANCE_REQUIRED'
    '$this->assert_maintenance_state();'
    '$this->files->delete( $storage_key );'
    'DELETE FROM'
    "array( 'approved', 'running' )"
    "checkpoint['phase'] = 'cancelled';"
    'Purge_Failure::LEGAL_HOLD_BLOCKED'
) 'bounded purge and cancellation'
Assert-Markers 'src\Operations\Purge\Application\Process_Purge_Batch.php' @(
    'Purge_Failure::DELETE_FAILED'
    'Purge_Failure::MAINTENANCE_UNAVAILABLE'
    'Purge_Failure::STORAGE_UNAVAILABLE'
    '$this->queue->wake( $result->job_id() );'
) 'durable worker retry'
Assert-Markers 'src\Operations\Purge\Presentation\Purge_Control_Rest_Controller.php' @(
    '/purge-jobs/(?P<job_id>[0-9A-HJKMNP-TV-Z]{26})/commands/execute'
    '/purge-jobs/(?P<job_id>[0-9A-HJKMNP-TV-Z]{26})/commands/cancel'
    'Addon_Capabilities::MANAGE_MIGRATIONS'
    'Access_Policy_Request::CORE_DELETE_PLUGINS'
    '$status = 202;'
    '$status = 200;'
    "array( 'command_id', 'expected', 'payload' )"
    "array( 'preview_hash', 'state' )"
) 'C73 and C74 REST contract'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'Purge_Control_Rest_Controller'
    'Purge_Control_Rest_Module'
    'WordPress_Purge_Operation_Repository'
    'Purge_Worker_Module'
    'Purge_Recovery_Access_Policy'
) 'production composition'
Assert-Markers 'src\Core\Deactivation.php' @(
    'Purge_Worker_Module::clear()'
) 'worker deactivation'
Assert-Markers 'tests\Contract\purge-execution-cancel-g5-imp-08-s04.php' @(
    'C73 target row count included protected ledgers.'
    'C73 did not delete private bytes before metadata purge.'
    'Retryable C73 failure did not wake recovery.'
    'C73 delete_plugins AND gate drifted.'
    'C74 did not cancel at the batch boundary.'
    'C73 exact approval delta'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-08-s04-purge-execution-cancel.html' @(
    'G5-IMP-08-S04'
    'check:purge-execution-g5'
    '40-table'
    '250'
    '20'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-08-s04-purge-execution-cancel.html'
    'IMP-08 4/6'
    'G5-IMP-08-S05'
    'Q36'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g5-imp-08-s04-purge-execution-cancel.html'
    'check:purge-execution-g5'
    'maintenance_required'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\a-ds-08-route-rest-command.html' @(
    'A-DS-08 Amendment 23'
    'g5-imp-08-s04-purge-execution-cancel.html'
    'Page 9'
    'GET 38'
    'POST 79'
) 'A-DS-08 synchronization'
Assert-Markers 'docs\ai-assessment\a-ds-05-addon-database.html' @(
    'A-DS-05 Amendment 11'
    'completed|failed|cancelled'
    'A-DS05-AM11-03'
) 'A-DS-05 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R123'
    'G5-IMP-08-S04'
    'check:purge-execution-g5'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:purge-execution-g5'
    'check-purge-execution-g5-imp-08-s04.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-purge-execution-g5-imp-08-s04.ps1'
    'Purge execution C73 and cancellation C74 G5-IMP-08-S04 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\purge-execution-cancel-g5-imp-08-s04.php'))

Write-Output 'G5-IMP-08-S04 passed: C73 preserves five control ledgers, deletes private bytes before 40 reverse-order data tables in 250-row or 20-second batches, and C74 cancels only before execution or at a serialized batch boundary.'
