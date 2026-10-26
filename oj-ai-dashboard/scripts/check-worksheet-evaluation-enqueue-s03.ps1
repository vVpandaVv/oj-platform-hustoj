$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'
$evaluationRoot = Join-Path $root 'src\Worksheet\Evaluation'

$files = @(
    'src\Rest\Rest_Problem.php',
    'src\Core\Composition_Root.php',
    'src\Worksheet\Evaluation\Application\Enqueue_Worksheet_Evaluation_Command.php',
    'src\Worksheet\Evaluation\Application\Enqueue_Worksheet_Evaluation_Result.php',
    'src\Worksheet\Evaluation\Application\Enqueue_Worksheet_Evaluation.php',
    'src\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure.php',
    'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Source.php',
    'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Routing_Snapshot.php',
    'src\Worksheet\Evaluation\Infrastructure\Platform_Worksheet_Evaluation_Routing.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Configuration.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Queue_Wakeup.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Repository.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Source.php',
    'src\Worksheet\Evaluation\Port\Worksheet_Evaluation_Configuration_Port_Interface.php',
    'src\Worksheet\Evaluation\Port\Worksheet_Evaluation_Queue_Wakeup_Port_Interface.php',
    'src\Worksheet\Evaluation\Port\Worksheet_Evaluation_Repository_Port_Interface.php',
    'src\Worksheet\Evaluation\Port\Worksheet_Evaluation_Routing_Port_Interface.php',
    'src\Worksheet\Evaluation\Port\Worksheet_Evaluation_Source_Port_Interface.php',
    'src\Worksheet\Evaluation\Presentation\Worksheet_Evaluation_Rest_Controller.php',
    'src\Worksheet\Evaluation\Presentation\Worksheet_Evaluation_Rest_Module.php',
    'tests\Contract\worksheet-evaluation-enqueue-s03.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$sources = Get-ChildItem -LiteralPath $evaluationRoot -Recurse -File -Filter '*.php'
$sourceText = ($sources | ForEach-Object { Get-Content -LiteralPath $_.FullName -Raw }) -join "`n"
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_')) {
    if ($sourceText.Contains($forbidden)) {
        throw "S03 evaluation runtime contains forbidden table prefix marker: $forbidden"
    }
}

$repository = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Infrastructure\WordPress_Worksheet_Evaluation_Repository.php') -Raw
foreach ($marker in @(
    "'oj_ai_worksheet_evaluations'",
    "'oj_ai_worksheet_evaluation_inputs'",
    "'oj_ai_evaluation_runs'",
    "'oj_ai_audit_events'",
    "'oj_ai_outbox_events'",
    "'START TRANSACTION'",
    "'COMMIT'",
    "'ROLLBACK'",
    'insert_evaluation(',
    'insert_input(',
    'insert_run(',
    'insert_audit(',
    'insert_outbox(',
    "'worksheet_evaluation.enqueue_replayed'",
    '$receipt_only ? $record[''queued_at_utc''] : null'
)) {
    if (-not $repository.Contains($marker)) {
        throw "S03 atomic repository marker is missing: $marker"
    }
}

$controller = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Presentation\Worksheet_Evaluation_Rest_Controller.php') -Raw
foreach ($marker in @(
    "'/worksheet-revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/commands/evaluate'",
    "'worksheet.evaluation.enqueue'",
    'Addon_Capabilities::ASSESS_WORKSHEETS',
    'Object_Scope::TYPE_WORKSHEET_REVISION',
    "'edit'",
    "'POST'",
    'Rest_Permission_Handoff'
)) {
    if (-not $controller.Contains($marker)) {
        throw "S03 C10 REST marker is missing: $marker"
    }
}

$routing = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Infrastructure\Platform_Worksheet_Evaluation_Routing.php') -Raw
foreach ($marker in @('->canRoute(', '->snapshot(', 'Addon_Manifest::TASK_WORKSHEET_QUALITY')) {
    if (-not $routing.Contains($marker)) {
        throw "S03 public routing marker is missing: $marker"
    }
}
if ($routing.Contains('->execute(')) {
    throw 'S03 enqueue must not execute the platform AI Gateway.'
}

& $php (Join-Path $root 'tests\Contract\worksheet-evaluation-enqueue-s03.php')
exit $LASTEXITCODE
