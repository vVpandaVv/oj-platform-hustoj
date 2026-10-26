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
    'src\Submission\Assessment\Application\Review_Submission_Feedback_Command.php'
    'src\Submission\Assessment\Application\Submission_Feedback_Review_Result.php'
    'src\Submission\Assessment\Application\Review_Submission_Feedback.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector.php'
    'src\Submission\Assessment\Port\Submission_Feedback_Review_Repository_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Feedback_Review_Repository.php'
    'src\Submission\Assessment\Presentation\Submission_Feedback_Review_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Submission_Feedback_Review_Rest_Module.php'
    'tests\Contract\submission-feedback-review-g4-imp-04-s02.php'
    'tests\Contract\submission-feedback-review-storage-g4-imp-04-s02.php'
    'tests\Contract\submission-feedback-review-rest-g4-imp-04-s02.php'
)

foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$contracts = @(
    'tests\Contract\submission-feedback-review-g4-imp-04-s02.php'
    'tests\Contract\submission-feedback-review-storage-g4-imp-04-s02.php'
    'tests\Contract\submission-feedback-review-rest-g4-imp-04-s02.php'
)

foreach ($relative in $contracts) {
    & $php (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-04-S02 passed: C36 direct/edit/reject feedback review, strict REST authorization, immutable append, CAS, source hash, durable replay, privacy, and rollback are frozen.'
