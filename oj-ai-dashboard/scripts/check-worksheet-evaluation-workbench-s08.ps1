param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'

$phpFiles = @(
    'src\Platform\Worksheet_Evaluation_Workbench_Page_Handler.php',
    'src\Platform\Addon_Manifest.php',
    'tests\Contract\worksheet-evaluation-workbench-s08.php'
)
foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

node --check (Join-Path $root 'resources\js\worksheet-evaluation-workbench.js')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node --check (Join-Path $root 'resources\js\worksheet-workflow.js')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node --check (Join-Path $root 'tests\Contract\worksheet-evaluation-workbench-s08.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node --check (Join-Path $root 'scripts\e2e-worksheet-evaluation-workbench.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& $php (Join-Path $root 'tests\Contract\worksheet-evaluation-workbench-s08.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'tests\Contract\worksheet-evaluation-workbench-s08.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-worksheet-evaluation-workbench.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-worksheet-zero-findings.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-worksheet-review-ledger.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-worksheet-review-navigation.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-review-container-layout.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-review-readiness-sync.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
