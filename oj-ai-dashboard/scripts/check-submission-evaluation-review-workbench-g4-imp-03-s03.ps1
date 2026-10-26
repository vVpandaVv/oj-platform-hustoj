param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'

$phpFiles = @(
    'src\Platform\Worksheet_Evaluation_Workbench_Page_Handler.php',
    'src\Platform\Addon_Manifest.php',
    'src\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector.php',
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Query_Rest_Controller.php',
    'tests\Contract\submission-evaluation-review-workbench-g4-imp-03-s03.php'
)
foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$nodeFiles = @(
    'resources\js\submission-evaluation-review-workbench.js',
    'tests\Contract\submission-evaluation-review-workbench-g4-imp-03-s03.mjs',
    'scripts\e2e-submission-evaluation-review-workbench.mjs'
)
foreach ($relative in $nodeFiles) {
    node --check (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\submission-evaluation-review-workbench-g4-imp-03-s03.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'tests\Contract\submission-evaluation-review-workbench-g4-imp-03-s03.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-submission-evaluation-review-workbench.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
