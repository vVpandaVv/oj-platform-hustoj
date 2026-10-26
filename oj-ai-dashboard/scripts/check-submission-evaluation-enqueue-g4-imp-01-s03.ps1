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

$assessmentRoot = Join-Path $root 'src\Submission\Assessment'
$files = @(
    'src\Core\Composition_Root.php'
    'src\Submission\Student\Infrastructure\Submission_Object_Authorization.php'
    'src\Submission\Assessment\Application\Enqueue_Submission_Evaluation.php'
    'src\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Command.php'
    'src\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Result.php'
    'src\Submission\Assessment\Application\Submission_Evaluation_Failure.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Source.php'
    'src\Submission\Assessment\Domain\Submission_Score_Input.php'
    'src\Submission\Assessment\Domain\Submission_Score_Input_Builder.php'
    'src\Submission\Assessment\Infrastructure\Platform_Submission_Evaluation_Routing.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Queue_Wakeup.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Repository.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Source.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Queue_Wakeup_Port_Interface.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Repository_Port_Interface.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Routing_Port_Interface.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Source_Port_Interface.php'
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Rest_Module.php'
    'tests\Contract\submission-evaluation-enqueue-g4-imp-01-s03.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$sources = Get-ChildItem -LiteralPath $assessmentRoot -Recurse -File -Filter '*.php'
$sourceText = ($sources | ForEach-Object { Get-Content -LiteralPath $_.FullName -Raw }) -join "`n"
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($sourceText.Contains($forbidden)) {
        throw "G4-IMP-01-S03 runtime contains forbidden boundary marker: $forbidden"
    }
}

$repository = Get-Content -LiteralPath (Join-Path $assessmentRoot 'Infrastructure\WordPress_Submission_Evaluation_Repository.php') -Raw
foreach ($marker in @(
    "'oj_ai_submission_evaluations'"
    "'oj_ai_submission_evaluation_inputs'"
    "'oj_ai_evaluation_runs'"
    "'oj_ai_audit_events'"
    "'oj_ai_outbox_events'"
    "'START TRANSACTION'"
    "'COMMIT'"
    "'ROLLBACK'"
    'insert_evaluation('
    'insert_input('
    'insert_run('
    'insert_audit('
    'insert_outbox('
    "'submission_evaluation.enqueue_replayed'"
    '$receipt_only ? $record[''queued_at_utc''] : null'
)) {
    if (-not (Test-OjaiPhpMarker -Source $repository -Marker $marker -Php $php)) {
        throw "G4-IMP-01-S03 atomic repository marker is missing: $marker"
    }
}

$controller = Get-Content -LiteralPath (Join-Path $assessmentRoot 'Presentation\Submission_Evaluation_Rest_Controller.php') -Raw
foreach ($marker in @(
    "'/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/commands/evaluate'"
    "'submission.evaluation.enqueue'"
    'Addon_Capabilities::ASSESS_SUBMISSIONS'
    'Context_Scope::OPERATION_EVALUATE'
    'Object_Scope::TYPE_SUBMISSION'
    "'evaluate'"
    "'POST'"
    'Rest_Permission_Handoff'
)) {
    if (-not (Test-OjaiPhpMarker -Source $controller -Marker $marker -Php $php)) {
        throw "G4-IMP-01-S03 C29 REST marker is missing: $marker"
    }
}

$objectPolicy = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Student\Infrastructure\Submission_Object_Authorization.php') -Raw
foreach ($marker in @("'evaluate'", "'submitted','auto_submitted','proxy_submitted','locked'", "'context_staff'")) {
    if (-not (Test-OjaiPhpMarker -Source $objectPolicy -Marker $marker -Php $php)) {
        throw "G4-IMP-01-S03 submission object policy marker is missing: $marker"
    }
}

$routing = Get-Content -LiteralPath (Join-Path $assessmentRoot 'Infrastructure\Platform_Submission_Evaluation_Routing.php') -Raw
foreach ($marker in @('->canRoute(', '->snapshot(', 'Addon_Manifest::TASK_STUDENT_ASSESSMENT')) {
    if (-not (Test-OjaiPhpMarker -Source $routing -Marker $marker -Php $php)) {
        throw "G4-IMP-01-S03 public routing marker is missing: $marker"
    }
}
if ($routing.Contains('->execute(')) {
    throw 'G4-IMP-01-S03 enqueue must not execute the platform AI Gateway.'
}

& $php (Join-Path $root 'tests\Contract\submission-evaluation-enqueue-g4-imp-01-s03.php')
exit $LASTEXITCODE
