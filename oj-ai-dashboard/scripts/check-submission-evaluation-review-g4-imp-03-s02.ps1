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
    'src\Submission\Assessment\Application\Submission_Evaluation_Failure.php'
    'src\Submission\Assessment\Application\Review_Submission_Evaluation_Command.php'
    'src\Submission\Assessment\Application\Submission_Evaluation_Review_Result.php'
    'src\Submission\Assessment\Application\Review_Submission_Evaluation.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Review_Repository_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Review_Repository.php'
    'src\Submission\Assessment\Infrastructure\Submission_Evaluation_Object_Authorization.php'
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Review_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Review_Rest_Module.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\submission-evaluation-review-g4-imp-03-s01.php'
    'tests\Contract\submission-evaluation-review-storage-g4-imp-03-s02.php'
    'tests\Contract\submission-evaluation-review-rest-g4-imp-03-s02.php'
)
foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$contracts = @(
    'tests\Contract\submission-evaluation-review-g4-imp-03-s01.php'
    'tests\Contract\submission-evaluation-review-storage-g4-imp-03-s02.php'
    'tests\Contract\submission-evaluation-review-rest-g4-imp-03-s02.php'
)
foreach ($relative in $contracts) {
    & $php (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-03-S02 review activation passed: S01 score and safety regression, official pointer CAS, immutable stale release, rollback atomicity, strict C34 REST, Context/object authorization, and durable replay.'
