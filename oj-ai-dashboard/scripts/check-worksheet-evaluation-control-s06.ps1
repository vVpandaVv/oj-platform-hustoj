$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'php-source-markers.ps1')

$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'
$evaluationRoot = Join-Path $root 'src\Worksheet\Evaluation'

$files = @(
    'src\Core\Composition_Root.php',
    'src\Worksheet\Evaluation\Application\Cancel_Worksheet_Evaluation.php',
    'src\Worksheet\Evaluation\Application\Cancel_Worksheet_Evaluation_Command.php',
    'src\Worksheet\Evaluation\Application\Retry_Worksheet_Evaluation.php',
    'src\Worksheet\Evaluation\Application\Retry_Worksheet_Evaluation_Command.php',
    'src\Worksheet\Evaluation\Application\Worksheet_Evaluation_Control_Result.php',
    'src\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Control_Repository.php',
    'src\Worksheet\Evaluation\Port\Worksheet_Evaluation_Control_Repository_Port_Interface.php',
    'src\Worksheet\Evaluation\Presentation\Worksheet_Evaluation_Control_Rest_Controller.php',
    'src\Worksheet\Evaluation\Presentation\Worksheet_Evaluation_Control_Rest_Module.php',
    'tests\Contract\worksheet-evaluation-control-s06.php'
)
foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$sources = Get-ChildItem -LiteralPath $evaluationRoot -Recurse -File -Filter '*.php'
$sourceText = [string]::Join([Environment]::NewLine, ($sources | ForEach-Object { Get-Content -LiteralPath $_.FullName -Raw }))
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'OJ\Platform\Internal', 'OJ_Platform_Internal')) {
    if ($sourceText.Contains($forbidden)) {
        throw "S06 evaluation control runtime contains forbidden dependency marker: $forbidden"
    }
}

$repository = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Infrastructure\WordPress_Worksheet_Evaluation_Control_Repository.php') -Raw
foreach ($marker in @(
    "'oj_ai_worksheet_evaluations'",
    "'oj_ai_worksheet_evaluation_inputs'",
    "'oj_ai_worksheet_evaluation_results'",
    "'oj_ai_evaluation_runs'",
    "'oj_ai_evaluation_attempts'",
    "'oj_ai_worksheet_revisions'",
    "'oj_ai_worksheets'",
    "'oj_ai_audit_events'",
    "'oj_ai_outbox_events'",
    "'START TRANSACTION'",
    "'COMMIT'",
    "'ROLLBACK'",
    "' FOR UPDATE'",
    "array( 'failed', 'cancelled' )",
    "array( 'queued', 'retry_wait', 'leased', 'running' )",
    'lease_token = NULL',
    'lock_version = lock_version + 1',
    'result_count',
    "'worksheet_evaluation.retry_queued'",
    "'worksheet_evaluation.cancelled'",
    'assert_retry_postcondition(',
    'assert_cancel_postcondition('
)) {
    if (-not (Test-OjaiPhpMarker -Source $repository -Marker $marker -Php $php)) {
        throw "S06 atomic retry/cancel repository marker is missing: $marker"
    }
}

$controller = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Presentation\Worksheet_Evaluation_Control_Rest_Controller.php') -Raw
foreach ($marker in @(
    "ROUTE_RETRY = '/worksheet-evaluations/",
    "ROUTE_CANCEL = '/worksheet-evaluations/",
    "'methods'             => 'POST'",
    'Rest_Permission_Handoff',
    'Addon_Capabilities::ASSESS_WORKSHEETS',
    'Object_Scope::TYPE_EVALUATION',
    "array( 'command_id', 'expected', 'payload' )",
    "array( 'expected_state', 'expected_run_id', 'expected_run_no' )",
    '? 202 : 200',
    'CONFIGURATION_CHANGED'
)) {
    if (-not (Test-OjaiPhpMarker -Source $controller -Marker $marker -Php $php)) {
        throw "S06 C11/C12 REST marker is missing: $marker"
    }
}
if ([regex]::Matches($controller, "'methods'\s*=>\s*'POST'").Count -ne 2) {
    throw 'S06 C11/C12 controller must register exactly two POST operations.'
}

$retry = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Application\Retry_Worksheet_Evaluation.php') -Raw
foreach ($marker in @('configuration->active()', 'routing->plan()', 'source_hash', 'configuration_hash', 'queue->wake()')) {
    if (-not (Test-OjaiPhpMarker -Source $retry -Marker $marker -Php $php)) {
        throw "S06 retry application marker is missing: $marker"
    }
}

$cancel = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Application\Cancel_Worksheet_Evaluation.php') -Raw
foreach ($marker in @("'queued', 'retry_wait', 'leased', 'running'", 'repository->cancel(')) {
    if (-not (Test-OjaiPhpMarker -Source $cancel -Marker $marker -Php $php)) {
        throw "S06 cancellation application marker is missing: $marker"
    }
}

$worker = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Infrastructure\WordPress_Worksheet_Evaluation_Work_Repository.php') -Raw
$lockPosition = $worker.IndexOf('lock_matches( $item, ''running'' )')
$resultPosition = $worker.IndexOf('self::TABLE_RESULTS,')
if ($lockPosition -lt 0 -or $resultPosition -lt 0 -or $lockPosition -ge $resultPosition) {
    throw 'S06 late worker output must pass state, lock version, and lease checks before result insertion.'
}

$composition = Get-Content -LiteralPath (Join-Path $root 'src\Core\Composition_Root.php') -Raw
foreach ($marker in @(
    'new WordPress_Worksheet_Evaluation_Control_Repository(',
    'new Retry_Worksheet_Evaluation(',
    'new Cancel_Worksheet_Evaluation(',
    'new Worksheet_Evaluation_Control_Rest_Controller(',
    'new Worksheet_Evaluation_Control_Rest_Module('
)) {
    if (-not (Test-OjaiPhpMarker -Source $composition -Marker $marker -Php $php)) {
        throw "S06 composition marker is missing: $marker"
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-evaluation-control-s06.php')
exit $LASTEXITCODE
