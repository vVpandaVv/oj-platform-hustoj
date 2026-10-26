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
    'src\Submission\Assignment\Infrastructure\Assignment_Object_Authorization.php'
    'src\Submission\Assessment\Application\Assignment_Evaluation_Failure.php'
    'src\Submission\Assessment\Application\Enqueue_Assignment_Evaluations.php'
    'src\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Command.php'
    'src\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Result.php'
    'src\Submission\Assessment\Application\Enqueue_Submission_Evaluation.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Assignment_Evaluation_Batch_Receipt.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Assignment_Evaluation_Batch_Source.php'
    'src\Submission\Assessment\Port\Assignment_Evaluation_Batch_Receipt_Port_Interface.php'
    'src\Submission\Assessment\Port\Assignment_Evaluation_Batch_Source_Port_Interface.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Enqueuer_Port_Interface.php'
    'src\Submission\Assessment\Presentation\Assignment_Evaluation_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Assignment_Evaluation_Rest_Module.php'
    'tests\Contract\assignment-evaluation-enqueue-g4-imp-01-s04.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$sources = Get-ChildItem -LiteralPath $assessmentRoot -Recurse -File -Filter '*.php'
$sourceText = ($sources | ForEach-Object { Get-Content -LiteralPath $_.FullName -Raw }) -join "`n"
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($sourceText.Contains($forbidden)) {
        throw "G4-IMP-01-S04 runtime contains forbidden boundary marker: $forbidden"
    }
}
if ($sourceText.Contains('Platform_Ai_Gateway_Client')) {
    throw 'G4-IMP-01-S04 enqueue must not execute the platform AI Gateway synchronously.'
}

$controller = Get-Content -LiteralPath (Join-Path $assessmentRoot 'Presentation\Assignment_Evaluation_Rest_Controller.php') -Raw
foreach ($marker in @(
    "'/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})/commands/evaluate-submissions'"
    "'assignment.evaluations.enqueue'"
    'Addon_Capabilities::ASSESS_SUBMISSIONS'
    'Context_Scope::OPERATION_EVALUATE'
    'Object_Scope::TYPE_ASSIGNMENT'
    "'evaluate_submissions'"
    "'POST'"
    "array( 'command_id', 'expected', 'payload' )"
    "array( 'expected_schedule_version', 'expected_state' )"
    "array( 'item_cursor', 'limit', 'source_cutoff_utc' )"
    'Rest_Permission_Handoff'
)) {
    if (-not (Test-OjaiPhpMarker -Source $controller -Marker $marker -Php $php)) {
        throw "G4-IMP-01-S04 C30 REST marker is missing: $marker"
    }
}

$application = Get-Content -LiteralPath (Join-Path $assessmentRoot 'Application\Enqueue_Assignment_Evaluations.php') -Raw
foreach ($marker in @(
    'Submission_Evaluation_Enqueuer_Port_Interface'
    'new Enqueue_Submission_Evaluation_Command('
    "array( 'created' => 0, 'existing' => 0, 'failed' => 0, 'skipped' => 0"
    "'outside_source_cutoff'"
    "'submission_not_official'"
    "'official_revision_missing'"
    "'submission_source_invalid'"
    'catch ( Submission_Evaluation_Failure $failure )'
)) {
    if (-not (Test-OjaiPhpMarker -Source $application -Marker $marker -Php $php)) {
        throw "G4-IMP-01-S04 orchestration marker is missing: $marker"
    }
}

$source = Get-Content -LiteralPath (Join-Path $assessmentRoot 'Infrastructure\WordPress_Assignment_Evaluation_Batch_Source.php') -Raw
foreach ($marker in @(
    "'oj_ai_assignments'"
    "'oj_ai_assignment_targets'"
    "'oj_ai_submissions'"
    "'oj_ai_submission_revisions'"
    "'ae_batch'"
    "array( 'i', 's', 'v' )"
    "hash( 'sha256', `$assignment_id . '|' . `$source_cutoff_utc )"
)) {
    if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) {
        throw "G4-IMP-01-S04 source/cursor marker is missing: $marker"
    }
}

$receipt = Get-Content -LiteralPath (Join-Path $assessmentRoot 'Infrastructure\WordPress_Assignment_Evaluation_Batch_Receipt.php') -Raw
foreach ($marker in @(
    "'oj_ai_outbox_events'"
    "'assignment_evaluations.enqueue_receipt'"
    "'published_at_utc'"
    "'START TRANSACTION'"
    "'COMMIT'"
    "'ROLLBACK'"
    'IDEMPOTENCY_CONFLICT'
    'STORED_DATA_INVALID'
)) {
    if (-not (Test-OjaiPhpMarker -Source $receipt -Marker $marker -Php $php)) {
        throw "G4-IMP-01-S04 durable receipt marker is missing: $marker"
    }
}

$objectPolicy = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assignment\Infrastructure\Assignment_Object_Authorization.php') -Raw
foreach ($marker in @(
    "'open' => array( 'view','view_submissions','import_zip','evaluate_submissions'"
    "'closed' => array( 'view','view_submissions','import_zip','evaluate_submissions'"
    "'context_staff'"
)) {
    if (-not (Test-OjaiPhpMarker -Source $objectPolicy -Marker $marker -Php $php)) {
        throw "G4-IMP-01-S04 assignment object policy marker is missing: $marker"
    }
}

$composition = Get-Content -LiteralPath (Join-Path $root 'src\Core\Composition_Root.php') -Raw
foreach ($marker in @(
    'new Enqueue_Assignment_Evaluations('
    'new WordPress_Assignment_Evaluation_Batch_Source('
    'new WordPress_Assignment_Evaluation_Batch_Receipt('
    'new Assignment_Evaluation_Rest_Module('
)) {
    if (-not (Test-OjaiPhpMarker -Source $composition -Marker $marker -Php $php)) {
        throw "G4-IMP-01-S04 composition marker is missing: $marker"
    }
}

& $php (Join-Path $root 'tests\Contract\assignment-evaluation-enqueue-g4-imp-01-s04.php')
exit $LASTEXITCODE
