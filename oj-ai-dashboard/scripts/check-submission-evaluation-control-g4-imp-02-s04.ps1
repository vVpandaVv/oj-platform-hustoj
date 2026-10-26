$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'php-source-markers.ps1')

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

$files = @(
    'src\Core\Composition_Root.php'
    'src\Submission\Assessment\Application\Cancel_Submission_Evaluation.php'
    'src\Submission\Assessment\Application\Cancel_Submission_Evaluation_Command.php'
    'src\Submission\Assessment\Application\Retry_Submission_Evaluation.php'
    'src\Submission\Assessment\Application\Retry_Submission_Evaluation_Command.php'
    'src\Submission\Assessment\Application\Submission_Evaluation_Control_Result.php'
    'src\Submission\Assessment\Application\Submission_Evaluation_Failure.php'
    'src\Submission\Assessment\Infrastructure\Submission_Evaluation_Object_Authorization.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Control_Repository.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Control_Repository_Port_Interface.php'
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Control_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Control_Rest_Module.php'
    'tests\Contract\submission-evaluation-control-g4-imp-02-s04.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $files | Where-Object { $_ -like 'src\*' }
$runtimeText = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\', 'OJ\Platform\Internal')) {
    if ($runtimeText.Contains($forbidden)) {
        throw "G4-IMP-02-S04 runtime contains forbidden boundary marker: $forbidden"
    }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) {
            throw "G4-IMP-02-S04 $label marker is missing: $marker"
        }
    }
}

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Control_Repository.php' @(
    "private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations'"
    "private const TABLE_INPUTS      = 'oj_ai_submission_evaluation_inputs'"
    "private const TABLE_RESULTS     = 'oj_ai_submission_evaluation_results'"
    "private const TABLE_RUNS        = 'oj_ai_evaluation_runs'"
    "private const TABLE_ATTEMPTS    = 'oj_ai_evaluation_attempts'"
    "private const TABLE_AUDIT       = 'oj_ai_audit_events'"
    "private const TABLE_OUTBOX      = 'oj_ai_outbox_events'"
    "'submission_evaluation.retry_queued'"
    "'submission_evaluation.cancelled'"
    "'START TRANSACTION'"
    "'COMMIT'"
    "'ROLLBACK'"
    "' FOR UPDATE'"
    "array( 'review_required', 'reviewed' )"
    "array( 'queued', 'retry_wait', 'leased', 'running' )"
    'lease_token = NULL'
    'lock_version = lock_version + 1'
    'result_public_id IS NULL'
    'expected_latest_result_id'
    'expected_result_count'
    'assert_retry_postcondition('
    'assert_cancel_postcondition('
    'cancel_started_attempt('
) 'atomic retry/cancel repository'

$repository = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Control_Repository.php') -Raw
if ([regex]::Matches($repository, 'START TRANSACTION').Count -lt 2) {
    throw 'G4-IMP-02-S04 C31 and C32 must each start one transaction.'
}
if ($repository.Contains("'UPDATE ' . self::TABLE_RESULTS")) {
    throw 'G4-IMP-02-S04 C31/C32 may not update prior result rows.'
}

Assert-Markers 'src\Submission\Assessment\Presentation\Submission_Evaluation_Control_Rest_Controller.php' @(
    "ROUTE_RETRY = '/submission-evaluations/"
    "ROUTE_CANCEL = '/submission-evaluations/"
    "'methods'             => 'POST'"
    'Rest_Permission_Handoff'
    'Context_Scope::OPERATION_EVALUATE'
    'Addon_Capabilities::ASSESS_SUBMISSIONS'
    'Object_Scope::TYPE_EVALUATION'
    "array( 'command_id', 'expected', 'payload' )"
    "array( 'reason' )"
    'expected_latest_result_id'
    '? 202 : 200'
    'STATE_CONFLICT'
) 'C31/C32 REST controller'

$controller = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Presentation\Submission_Evaluation_Control_Rest_Controller.php') -Raw
if ([regex]::Matches($controller, "'methods'\s*=>\s*'POST'").Count -ne 2) {
    throw 'G4-IMP-02-S04 controller must register exactly two POST operations.'
}

Assert-Markers 'src\Submission\Assessment\Application\Retry_Submission_Evaluation.php' @(
    'repository->find_command_receipt('
    'routing->plan()'
    'latest_result_id'
    'expected_result_count'
    'run_no'
    'queue->wake()'
) 'C31 application'

Assert-Markers 'src\Submission\Assessment\Application\Cancel_Submission_Evaluation.php' @(
    'repository->find_command_receipt('
    "array( 'queued', 'retry_wait', 'leased', 'running' )"
    'expected_result_count'
    'repository->cancel('
) 'C32 application'

Assert-Markers 'src\Submission\Assessment\Infrastructure\Submission_Evaluation_Object_Authorization.php' @(
    "array( 'view', 'retry', 'cancel', 'review', 'release', 'withdraw_release', 'withdraw_approval', 'report' )"
    'Object_Scope::TYPE_EVALUATION'
) 'Evaluation object authorization'

$worker = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository.php') -Raw
$lockPosition = $worker.IndexOf('if ( ! $this->lock_matches( $item, ''running'' )')
$resultPosition = if ($lockPosition -ge 0) { $worker.IndexOf('self::TABLE_RESULTS,', $lockPosition) } else { -1 }
if ($lockPosition -lt 0 -or $resultPosition -lt 0 -or $lockPosition -ge $resultPosition) {
    throw 'G4-IMP-02-S04 late worker output must pass state, lock, and lease CAS before result insertion.'
}

Assert-Markers 'src\Core\Composition_Root.php' @(
    'new WordPress_Submission_Evaluation_Control_Repository('
    'new Retry_Submission_Evaluation('
    'new Cancel_Submission_Evaluation('
    'new Submission_Evaluation_Control_Rest_Controller('
    'new Submission_Evaluation_Control_Rest_Module('
    '$submission_evaluation_queue'
) 'Composition Root'

& $php (Join-Path $root 'tests\Contract\submission-evaluation-control-g4-imp-02-s04.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-02-S04 C31 retry, C32 cooperative cancellation, result preservation, CAS, REST authorization, and durable receipt contract passed.'
