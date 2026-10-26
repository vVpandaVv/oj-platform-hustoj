$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

$phpFiles = @(
    'src\Core\Composition_Root.php'
    'src\Core\Deactivation.php'
    'src\Submission\Assessment\Application\Submission_Evaluation_Failure.php'
    'src\Submission\Assessment\Application\Generate_Submission_Feedback_Command.php'
    'src\Submission\Assessment\Application\Generate_Submission_Feedback_Result.php'
    'src\Submission\Assessment\Application\Generate_Submission_Feedback.php'
    'src\Submission\Assessment\Application\Run_Submission_Feedback_Result.php'
    'src\Submission\Assessment\Application\Run_Next_Submission_Feedback.php'
    'src\Submission\Assessment\Domain\Submission_Feedback_Input.php'
    'src\Submission\Assessment\Domain\Submission_Feedback_Input_Builder.php'
    'src\Submission\Assessment\Domain\Submission_Feedback_Normalized_Result.php'
    'src\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer.php'
    'src\Submission\Assessment\Domain\Submission_Feedback_Work_Item.php'
    'src\Submission\Assessment\Port\Submission_Feedback_Repository_Port_Interface.php'
    'src\Submission\Assessment\Port\Submission_Feedback_Work_Repository_Port_Interface.php'
    'src\Submission\Assessment\Port\Submission_Feedback_Execution_Port_Interface.php'
    'src\Submission\Assessment\Port\Submission_Feedback_Execution_Consent_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\Platform_Submission_Feedback_Execution.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Feedback_Execution_Consent.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Feedback_Repository.php'
    'src\Submission\Assessment\Infrastructure\Submission_Feedback_Worker_Module.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository.php'
    'src\Submission\Assessment\Presentation\Submission_Feedback_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Submission_Feedback_Rest_Module.php'
    'tests\Contract\submission-feedback-draft-g4-imp-04-s01.php'
    'tests\Contract\submission-feedback-worker-g4-imp-04-s01.php'
)

foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$contracts = @(
    'tests\Contract\submission-feedback-draft-g4-imp-04-s01.php'
    'tests\Contract\submission-feedback-worker-g4-imp-04-s01.php'
)

foreach ($relative in $contracts) {
    & $php (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-04-S01 passed: official-review feedback input, closed output, idempotent enqueue, worker rechecks, immutable AI draft append, and score-run isolation are frozen.'
