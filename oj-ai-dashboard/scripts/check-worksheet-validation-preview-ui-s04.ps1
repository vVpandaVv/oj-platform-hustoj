$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'
$phpFiles = @(
    'src\Platform\Worksheet_Workbench_Page_Handler.php',
    'tests\Contract\worksheet-workbench-s08.php'
)

foreach ($file in $phpFiles) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\worksheet-workbench-s08.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$nodeFiles = @(
    'resources\js\worksheet-authoring-workbench.js',
    'scripts\e2e-worksheet-workbench.mjs',
    'tests\Contract\worksheet-validation-preview-ui-s04.mjs'
)
foreach ($file in $nodeFiles) {
    & node --check (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& node (Join-Path $root 'tests\Contract\worksheet-validation-preview-ui-s04.mjs')
exit $LASTEXITCODE
