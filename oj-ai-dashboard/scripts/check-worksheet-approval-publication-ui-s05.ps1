param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = if (Test-Path 'D:\php\php.exe') { 'D:\php\php.exe' } else { 'php' }
$phpFiles = @(
    'src\Worksheet\Authoring\Application\Approve_Worksheet_Revision.php',
    'src\Worksheet\Authoring\Application\Publish_Worksheet_Revision.php',
    'src\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller.php',
    'src\Platform\Worksheet_Workbench_Page_Handler.php',
    'tests\Contract\worksheet-validation-s01.php',
    'tests\Contract\worksheet-approval-command-s02.php',
    'tests\Contract\worksheet-publication-s03.php',
    'tests\Contract\worksheet-lifecycle-s04.php'
)

foreach ($file in $phpFiles) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

foreach ($test in @(
    'tests\Contract\worksheet-validation-s01.php',
    'tests\Contract\worksheet-approval-command-s02.php',
    'tests\Contract\worksheet-publication-s03.php',
    'tests\Contract\worksheet-lifecycle-s04.php'
)) {
    & $php (Join-Path $root $test)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

foreach ($file in @(
    'resources\js\worksheet-authoring-workbench.js',
    'scripts\e2e-worksheet-workbench.mjs',
    'tests\Contract\worksheet-approval-publication-ui-s05.mjs'
)) {
    & node --check (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& node (Join-Path $root 'tests\Contract\worksheet-approval-publication-ui-s05.mjs')
exit $LASTEXITCODE
