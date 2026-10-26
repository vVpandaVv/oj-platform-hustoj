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
    'src\Submission\Assessment\Application\Submission_Evaluation_Worker_Failure.php'
    'src\Submission\Assessment\Application\Run_Submission_Evaluation_Result.php'
    'src\Submission\Assessment\Application\Run_Next_Submission_Evaluation.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Normalized_Result.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer.php'
    'src\Submission\Assessment\Domain\Submission_External_Ai_Consent_Record.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Execution_Port_Interface.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Execution_Consent_Port_Interface.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Work_Repository_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\Platform_Submission_Evaluation_Execution.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Execution_Consent.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository.php'
    'src\Submission\Assessment\Infrastructure\Submission_Evaluation_Worker_Module.php'
    'tests\Contract\submission-evaluation-worker-g4-imp-02-s02.php'
    'tests\Contract\submission-evidence-validation.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $files | Where-Object { $_ -like 'src\*' }
$sourceText = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($sourceText.Contains($forbidden)) { throw "G4-IMP-02-S02 runtime contains forbidden boundary marker: $forbidden" }
}

$consentSource = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Execution_Consent.php') -Raw
foreach ($required in @('callable $loader', 'Closure::fromCallable( $loader )')) {
    if (-not (Test-OjaiPhpMarker -Source $consentSource -Marker $required -Php $php)) { throw "G4-IMP-02-S02 explicit consent loader marker is missing: $required" }
}
foreach ($forbidden in @('get_option', 'public const OPTION', '?callable $loader')) {
    if ($consentSource.Contains($forbidden)) { throw "G4-IMP-02-S02 hidden consent fallback is forbidden: $forbidden" }
}

$normalizer = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer.php') -Raw
foreach ($marker in @(
    'MAXIMUM_OUTPUT_BYTES = 524288'
    'MAXIMUM_JSON_DEPTH = 12'
    'MAXIMUM_EVIDENCE_TOTAL = 1200'
    'teacher_review_required'
    'safety_review_required'
    'OUTPUT_PRIVACY_INVALID'
    '$candidate_score = 0 === $abstained_count'
)) {
    if (-not (Test-OjaiPhpMarker -Source $normalizer -Marker $marker -Php $php)) { throw "G4-IMP-02-S02 normalizer marker is missing: $marker" }
}

$worker = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Application\Run_Next_Submission_Evaluation.php') -Raw
foreach ($marker in @(
    'external_processing_not_approved'
    'routing_changed_during_execute'
    'MAXIMUM_ATTEMPTS = 3'
    '? 60 : 300'
    '$this->authorized( $item )'
    '$this->source_matches( $item )'
    '$this->consent->allows( $item )'
    '$this->repository->complete_success('
)) {
    if (-not (Test-OjaiPhpMarker -Source $worker -Marker $marker -Php $php)) { throw "G4-IMP-02-S02 worker marker is missing: $marker" }
}

$repository = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository.php') -Raw
foreach ($marker in @(
    "private const TABLE_RESULTS     = 'oj_ai_submission_evaluation_results'"
    "private const TABLE_ITEMS       = 'oj_ai_submission_evaluation_items'"
    "private const TABLE_AUDIT       = 'oj_ai_audit_events'"
    "private const TABLE_OUTBOX      = 'oj_ai_outbox_events'"
    'result_type = %s, result_public_id = %s'
    "'review_required'"
    'WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s'
    'assert_success_postcondition'
)) {
    if (-not (Test-OjaiPhpMarker -Source $repository -Marker $marker -Php $php)) { throw "G4-IMP-02-S02 repository marker is missing: $marker" }
}

$platform = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Infrastructure\Platform_Submission_Evaluation_Execution.php') -Raw
foreach ($marker in @('AddonAiTaskRequestV1', '->snapshot( $task_key )', '->execute(', 'addon_ai_task_failed', 'addon_ai_task_unroutable')) {
    if (-not (Test-OjaiPhpMarker -Source $platform -Marker $marker -Php $php)) { throw "G4-IMP-02-S02 public Gateway marker is missing: $marker" }
}

& $php (Join-Path $root 'tests\Contract\submission-evidence-validation.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-02-S02 submission evaluation Gateway, output, and candidate result contract passed.'
