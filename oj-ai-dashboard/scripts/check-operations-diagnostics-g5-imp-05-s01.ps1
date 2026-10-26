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
            throw "G5-IMP-05-S01 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    foreach ($gate in @(
        'check-runtime-schema-readiness.ps1',
        'check-access-policy.ps1',
        'check-rest-foundation.ps1'
    )) {
        Invoke-Checked 'powershell' @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot $gate)
        )
    }
}

$phpFiles = @(
    'src\Operations\Diagnostics\Application\Read_System_Diagnostics.php'
    'src\Operations\Diagnostics\Domain\Bridge_Diagnostics_Result.php'
    'src\Operations\Diagnostics\Domain\Queue_Diagnostics_Result.php'
    'src\Operations\Diagnostics\Port\Bridge_Diagnostics_Port_Interface.php'
    'src\Operations\Diagnostics\Port\Queue_Diagnostics_Port_Interface.php'
    'src\Operations\Diagnostics\Infrastructure\Addon_Runtime_Bridge_Diagnostics.php'
    'src\Operations\Diagnostics\Infrastructure\WordPress_Evaluation_Queue_Diagnostics.php'
    'src\Operations\Diagnostics\Presentation\Operations_Diagnostics_Rest_Controller.php'
    'src\Operations\Diagnostics\Presentation\Operations_Diagnostics_Rest_Module.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\operations-diagnostics-g5-imp-05-s01.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\Operations\Diagnostics\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object {
    Get-Content -LiteralPath (Join-Path $root $_) -Raw -Encoding UTF8
}) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\', 'AddonAiTaskGatewayV1', 'ai_tasks()->execute')) {
    if ($runtimeSource.Contains($forbidden)) {
        throw "G5-IMP-05-S01 contains a forbidden boundary marker: $forbidden"
    }
}

Assert-Markers 'src\Operations\Diagnostics\Application\Read_System_Diagnostics.php' @(
    "PROFILE = 'oj_ai.diagnostics@1.0.0'"
    'Queue_Diagnostics_Result::READY'
    'Queue_Diagnostics_Result::ATTENTION'
    'Queue_Diagnostics_Result::UNAVAILABLE'
    "'open_platform_operations'"
    "'check_data_structure'"
    "'check_jobs'"
    "'resume_jobs'"
) 'safe projection'
Assert-Markers 'src\Operations\Diagnostics\Infrastructure\WordPress_Evaluation_Queue_Diagnostics.php' @(
    "TABLE_RUNS = 'oj_ai_evaluation_runs'"
    'FAILURE_THRESHOLD = 3'
    'WINDOW_MINUTES = 15'
    'Worksheet_Evaluation_Worker_Module::SWEEP_HOOK'
    'Submission_Evaluation_Worker_Module::SWEEP_HOOK'
    'Submission_Feedback_Worker_Module::SWEEP_HOOK'
    'Student_Note_Worker_Module::SWEEP_HOOK'
    "'queue_schedule_inactive'"
    "'queue_stale_lease_detected'"
    "'queue_failure_threshold_reached'"
    "'queue_backlog_delayed'"
) 'queue diagnostics'
Assert-Markers 'src\Operations\Diagnostics\Presentation\Operations_Diagnostics_Rest_Controller.php' @(
    "REST_NAMESPACE = 'oj-ai/v1'"
    "ROUTE = '/diagnostics'"
    "ACTION_READ = 'operations.diagnostics.read'"
    'Access_Policy_Request::SHELL_CONSOLE'
    "'addon_status_unavailable'"
    'Rest_Problem::operation('
) 'Q28 REST'
Assert-Markers 'src\Core\Composition_Root.php' @(
    '$schema_readiness = new WordPress_Schema_Runtime_Readiness()'
    'new Addon_Runtime_Bridge_Diagnostics( $runtime )'
    'new WordPress_Evaluation_Queue_Diagnostics()'
    'new Operations_Diagnostics_Rest_Module( $operations_diagnostics_rest )'
) 'Composition Root'
Assert-Markers 'tests\Contract\operations-diagnostics-g5-imp-05-s01.php' @(
    'Q28 did not cover all four AI evaluation workers.'
    'Stale lease did not receive first diagnostic priority.'
    'Q28 leaked a forbidden diagnostic field:'
    'Q28 added an unauthorized add-on capability beyond Console common.'
    'Q28 inspected the add-on bridge after common policy denial.'
    'G5-IMP-05-S01 passed:'
) 'contract test'
Assert-Markers 'docs\ai-assessment\g5-imp-05-s01-bridge-schema-queue-diagnostics.html' @(
    'IMP-05 1/4'
    'Q28'
    'literal <code>oj_ai_*</code>'
    'oj_ai.diagnostics@1.0.0'
    'check:operations-diagnostics-g5'
    'Platform API change: 0'
    'G5-IMP-05-S02'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-05-s01-bridge-schema-queue-diagnostics.html'
    'g5-imp-05-s02-ai-routing-diagnostics.html'
    'g5-imp-05-s03-storage-secret-diagnostics.html'
    'g5-imp-05-s04-operations-ui-regression.html'
    'Q28 5-check'
    'IMP-05 4/4'
    'G5 6/8'
    'G5-IMP-06-S01'
) 'additive implementation roadmap'
Assert-Markers 'package.json' @(
    'check:operations-diagnostics-g5'
    'check-operations-diagnostics-g5-imp-05-s01.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-operations-diagnostics-g5-imp-05-s01.ps1'
    'Operations diagnostics Q28 Bridge, schema, and queue G5-IMP-05-S01 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\operations-diagnostics-g5-imp-05-s01.php'))

Write-Output 'G5-IMP-05-S01 passed: Q28 Bridge, schema, four AI workers, backlog, stale lease, failure threshold, Console common policy, and secret-free projection are frozen.'
