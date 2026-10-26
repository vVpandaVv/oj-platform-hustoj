$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

$files = @(
    'src\Submission\Assessment\Domain\Submission_Evaluation_Work_Item.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Work_Repository_Port_Interface.php'
    'src\Submission\Assessment\Application\Submission_Evaluation_Worker_Failure.php'
    'src\Submission\Assessment\Application\Claim_Next_Submission_Evaluation.php'
    'src\Submission\Assessment\Application\Start_Submission_Evaluation_Attempt.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository.php'
    'tests\Contract\submission-evaluation-queue-lease-g4-imp-02-s01.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $files | Where-Object { $_ -like 'src\*' }
$sourceText = ($runtimeFiles | ForEach-Object {
    Get-Content -LiteralPath (Join-Path $root $_) -Raw
}) -join "`n"

foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($sourceText.Contains($forbidden)) {
        throw "G4-IMP-02-S01 runtime contains forbidden boundary marker: $forbidden"
    }
}
foreach ($forbidden in @('Platform_Ai_Gateway_Client', '->executeTask(')) {
    if ($sourceText.Contains($forbidden)) {
        throw "G4-IMP-02-S01 queue/lease boundary must not execute the AI Gateway: $forbidden"
    }
}

$claim = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Application\Claim_Next_Submission_Evaluation.php') -Raw
foreach ($marker in @(
    'LEASE_SECONDS = 300'
    'claim_due('
    'new_lease_token()'
    "modify( '+' . `$seconds . ' seconds' )"
)) {
    if (-not $claim.Contains($marker)) {
        throw "G4-IMP-02-S01 claim marker is missing: $marker"
    }
}

$start = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Application\Start_Submission_Evaluation_Attempt.php') -Raw
foreach ($marker in @(
    'start_attempt('
    'Claim_Next_Submission_Evaluation::LEASE_SECONDS'
    '$this->ids->create()->value()'
)) {
    if (-not $start.Contains($marker)) {
        throw "G4-IMP-02-S01 attempt-start marker is missing: $marker"
    }
}

$repository = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository.php') -Raw
foreach ($marker in @(
    "private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations'"
    "private const TABLE_INPUTS      = 'oj_ai_submission_evaluation_inputs'"
    "private const TABLE_RUNS        = 'oj_ai_evaluation_runs'"
    "private const TABLE_ATTEMPTS    = 'oj_ai_evaluation_attempts'"
    'WHERE r.subject_type = %s AND r.operation_key = %s'
    "array( 'submission_evaluation', Submission_Score_Input_Builder::OPERATION, 'queued', 'running', 'queued', 'retry_wait'"
    'in_array( $previous_state, array( ''leased'', ''running'' )'
    'LIMIT 1 FOR UPDATE'
    'lock_version = lock_version + 1'
    'AND lease_token = %s'
    "array( 'gateway_failed', 'worker_lost'"
    "'state'                  => 'started'"
    'submission.evaluation.enqueue'
    'Addon_Capabilities::ASSESS_SUBMISSIONS'
    'Submission_Score_Input_Builder::MAXIMUM_INPUT_BYTES'
)) {
    if (-not $repository.Contains($marker)) {
        throw "G4-IMP-02-S01 repository marker is missing: $marker"
    }
}

$contract = Get-Content -LiteralPath (Join-Path $root 'tests\Contract\submission-evaluation-queue-lease-g4-imp-02-s01.php') -Raw
foreach ($marker in @(
    'A second worker must not acquire an unexpired lease.'
    'A worker must reclaim the run at the exact lease expiry.'
    'The old lease token must not start an attempt after reclaim.'
    'Attempt history must remain append-only.'
    'Invalid work item must roll the lease update back.'
)) {
    if (-not $contract.Contains($marker)) {
        throw "G4-IMP-02-S01 contract marker is missing: $marker"
    }
}

$schema = Get-Content -LiteralPath (Join-Path $root 'src\Database\Foundation\Schema_Catalog.php') -Raw
foreach ($marker in @(
    "'oj_ai_evaluation_runs'"
    "'oj_ai_evaluation_attempts'"
    "self::ix( 'ix_eval_run_queue', 'state', 'available_at_utc', 'id' )"
    "self::ix( 'ix_eval_run_lease', 'state', 'lease_expires_at_utc' )"
    "self::uq( 'uq_eval_attempt_no', 'evaluation_run_id', 'attempt_no' )"
)) {
    if (-not $schema.Contains($marker)) {
        throw "G4-IMP-02-S01 schema marker is missing: $marker"
    }
}

& $php (Join-Path $root 'tests\Contract\submission-evaluation-queue-lease-g4-imp-02-s01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-02-S01 submission evaluation queue and lease contract passed.'
