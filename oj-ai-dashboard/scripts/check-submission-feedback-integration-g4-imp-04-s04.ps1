param(
    [switch]$SkipConstituentChecks,
    [switch]$SkipVisual
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

if (-not $SkipConstituentChecks) {
    $constituentChecks = @(
        'check-submission-feedback-g4-imp-04-s01.ps1'
        'check-submission-feedback-review-g4-imp-04-s02.ps1'
        'check-submission-feedback-approval-g4-imp-04-s03.ps1'
    )
    foreach ($relative in $constituentChecks) {
        & (Join-Path $PSScriptRoot $relative)
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
}

$phpFiles = @(
    'src\Platform\Worksheet_Evaluation_Workbench_Page_Handler.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Feedback_Repository.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Feedback_Review_Repository.php'
    'tests\Contract\submission-feedback-workbench-g4-imp-04-s04.php'
)
foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$nodeFiles = @(
    'resources\js\submission-evaluation-review-workbench.js'
    'tests\Contract\submission-feedback-workbench-g4-imp-04-s04.mjs'
    'scripts\e2e-submission-feedback-workbench.mjs'
)
foreach ($relative in $nodeFiles) {
    node --check (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\submission-feedback-workbench-g4-imp-04-s04.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'tests\Contract\submission-feedback-workbench-g4-imp-04-s04.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

if (-not $SkipVisual) {
    node (Join-Path $root 'scripts\e2e-submission-feedback-workbench.mjs')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-04-S04 passed: S01-S03 integration, exact C35-C37 feedback UI, 409 reload, 403 permission revocation, desktop/mobile layout, student-release separation, and immutable revision history are frozen.'
