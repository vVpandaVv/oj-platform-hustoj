param(
    [switch]$SkipConstituentChecks
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
    & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'scripts\check-submission-evaluation-review-g4-imp-03-s02.ps1')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'scripts\check-submission-evaluation-review-workbench-g4-imp-03-s03.ps1')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$phpFiles = @(
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Review_Repository.php',
    'tests\Contract\submission-evaluation-review-storage-g4-imp-03-s02.php',
    'tests\Contract\submission-evaluation-review-rest-g4-imp-03-s02.php',
    'tests\Contract\submission-evaluation-review-integration-g4-imp-03-s04.php'
)
foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$nodeFiles = @(
    'resources\js\submission-evaluation-review-workbench.js',
    'scripts\e2e-submission-evaluation-review-workbench.mjs'
)
foreach ($relative in $nodeFiles) {
    node --check (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\submission-evaluation-review-integration-g4-imp-03-s04.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-03-S04 integration freeze passed: exact result, official pointer, release, both root CAS paths, 409 reload, permission revocation, replay, and immutable history.'
exit 0
