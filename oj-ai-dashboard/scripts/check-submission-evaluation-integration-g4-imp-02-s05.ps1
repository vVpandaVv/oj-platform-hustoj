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
    'src\Submission\Assessment\Application\Run_Next_Submission_Evaluation.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Control_Repository.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Query.php'
    'tests\Contract\submission-evaluation-worker-g4-imp-02-s02.php'
    'tests\Contract\submission-evaluation-integration-g4-imp-02-s05.php'
)
foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$contracts = @(
    'tests\Contract\submission-evaluation-queue-lease-g4-imp-02-s01.php'
    'tests\Contract\submission-evaluation-worker-g4-imp-02-s02.php'
    'tests\Contract\submission-external-ai-consent-g4-imp-02-r01.php'
    'tests\Contract\submission-evaluation-query-g4-imp-02-s03.php'
    'tests\Contract\submission-evaluation-control-g4-imp-02-s04.php'
    'tests\Contract\submission-evaluation-integration-g4-imp-02-s05.php'
)
foreach ($relative in $contracts) {
    & $php (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-02-S05 integration freeze passed: S01-S04 plus R01, abstain NULL, routing drift, stored corruption, and worker race.'
