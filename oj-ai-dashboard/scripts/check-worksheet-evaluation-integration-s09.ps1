param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'

$phpFiles = @(
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Control_Repository.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Query.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Review_Repository.php',
    'tests\Contract\worksheet-evaluation-integration-s09.php'
)
foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$contracts = @(
    'tests\Contract\worksheet-evaluation-enqueue-s03.php',
    'tests\Contract\worksheet-evaluation-worker-s04.php',
    'tests\Contract\worksheet-evaluation-query-s05.php',
    'tests\Contract\worksheet-evaluation-control-s06.php',
    'tests\Contract\worksheet-evaluation-review-s07.php',
    'tests\Contract\worksheet-evaluation-workbench-s08.php',
    'tests\Contract\worksheet-evaluation-integration-s09.php'
)
foreach ($relative in $contracts) {
    & $php (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

node (Join-Path $root 'tests\Contract\worksheet-evaluation-workbench-s08.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-worksheet-evaluation-workbench.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
