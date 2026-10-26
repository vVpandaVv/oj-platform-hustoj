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
    'src\Rest\Rest_Permission_Handoff.php'
    'src\Rest\Rest_Request_Authorizer.php'
    'src\Submission\Assessment\Application\Enqueue_Submission_Evaluation.php'
    'src\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Command.php'
    'src\Submission\Assessment\Application\Enqueue_Assignment_Evaluations.php'
    'src\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Command.php'
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Assignment_Evaluation_Rest_Controller.php'
    'tests\Contract\submission-evaluation-request-freeze-g4-imp-01-s05.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$sourceText = ($files | Where-Object { $_ -like 'src\*' } | ForEach-Object {
    Get-Content -LiteralPath (Join-Path $root $_) -Raw
}) -join "`n"
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($sourceText.Contains($forbidden)) {
        throw "G4-IMP-01-S05 runtime contains forbidden boundary marker: $forbidden"
    }
}
if ($sourceText.Contains('Platform_Ai_Gateway_Client') -or $sourceText.Contains('->executeTask(')) {
    throw 'G4-IMP-01-S05 enqueue freeze must not execute the platform AI Gateway synchronously.'
}

$handoff = Get-Content -LiteralPath (Join-Path $root 'src\Rest\Rest_Permission_Handoff.php') -Raw
foreach ($marker in @(
    'WeakMap'
    '$this->grants[ $request ]'
    'unset( $this->grants[ $request ] )'
    'Rest_Permission_Grant'
)) {
    if (-not $handoff.Contains($marker)) {
        throw "G4-IMP-01-S05 request-bound permission marker is missing: $marker"
    }
}

$authorizer = Get-Content -LiteralPath (Join-Path $root 'src\Rest\Rest_Request_Authorizer.php') -Raw
foreach ($marker in @(
    '$this->nonces->verify('
    'new Idempotency_Key('
    "'idempotency_key_required'"
    "'idempotency_key_invalid'"
    'Rest_Authorization_Result::validation_failed('
)) {
    if (-not $authorizer.Contains($marker)) {
        throw "G4-IMP-01-S05 REST authorization marker is missing: $marker"
    }
}

$submissionCommand = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Command.php') -Raw
$assignmentCommand = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Command.php') -Raw
foreach ($marker in @(
    '$this->actor_user_id'
    "'|submission.evaluation.enqueue|'"
    '$this->submission_id'
    '$this->idempotency_fingerprint'
)) {
    if (-not $submissionCommand.Contains($marker)) {
        throw "G4-IMP-01-S05 C29 actor-scoped idempotency marker is missing: $marker"
    }
}
foreach ($marker in @(
    '$this->actor_user_id'
    "'|assignment.evaluations.enqueue|'"
    '$this->assignment_id'
    '$this->idempotency_fingerprint'
)) {
    if (-not $assignmentCommand.Contains($marker)) {
        throw "G4-IMP-01-S05 C30 actor-scoped idempotency marker is missing: $marker"
    }
}

$submissionApplication = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Application\Enqueue_Submission_Evaluation.php') -Raw
foreach ($marker in @(
    'find_command_receipt('
    'find_official('
    '$source->lock_version()'
    '$source->context_revision()'
    'Submission_Evaluation_Failure::SOURCE_STALE'
)) {
    if (-not $submissionApplication.Contains($marker)) {
        throw "G4-IMP-01-S05 C29 CAS/replay marker is missing: $marker"
    }
}

$assignmentApplication = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Application\Enqueue_Assignment_Evaluations.php') -Raw
foreach ($marker in @(
    'find_receipt('
    '$command->authorized_context_revision()'
    '$command->authorized_revision()'
    '$command->authorized_state()'
    'Assignment_Evaluation_Failure::SOURCE_STALE'
)) {
    if (-not $assignmentApplication.Contains($marker)) {
        throw "G4-IMP-01-S05 C30 CAS/replay marker is missing: $marker"
    }
}

foreach ($controllerPath in @(
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Assignment_Evaluation_Rest_Controller.php'
)) {
    $controller = Get-Content -LiteralPath (Join-Path $root $controllerPath) -Raw
    foreach ($marker in @(
        'Context_Scope::OPERATION_EVALUATE'
        'Addon_Capabilities::ASSESS_SUBMISSIONS'
        '$this->permissions->take( $request )'
        "'context_revision'"
    )) {
        if (-not $controller.Contains($marker)) {
            throw "G4-IMP-01-S05 REST Context/grant marker is missing in ${controllerPath}: $marker"
        }
    }
}

$contract = Get-Content -LiteralPath (Join-Path $root 'tests\Contract\submission-evaluation-request-freeze-g4-imp-01-s05.php') -Raw
foreach ($marker in @(
    'invalid nonce must fail closed'
    'missing Idempotency-Key must fail closed'
    'cross-user request must be denied'
    'Context revision drift must be denied'
    'direct URL object substitution must be denied'
    'grant must be one-use'
    'post-permission source race'
    'post-permission state race'
    'storage key must isolate actors'
    'semantic replay'
)) {
    if (-not $contract.Contains($marker)) {
        throw "G4-IMP-01-S05 integration contract marker is missing: $marker"
    }
}

& $php (Join-Path $root 'tests\Contract\submission-evaluation-request-freeze-g4-imp-01-s05.php')
exit $LASTEXITCODE
