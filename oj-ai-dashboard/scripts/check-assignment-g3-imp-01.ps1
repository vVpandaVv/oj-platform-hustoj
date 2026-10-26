param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = if (Test-Path -LiteralPath 'D:\php\php.exe') { 'D:\php\php.exe' } else { (Get-Command php -ErrorAction Stop).Source }
$phpFiles = @(
    'src\Submission\Assignment\Application\Assignment_Failure.php',
    'src\Submission\Assignment\Application\Create_Assignment_Command.php',
    'src\Submission\Assignment\Application\Create_Assignment_Result.php',
    'src\Submission\Assignment\Application\Create_Assignment.php',
    'src\Submission\Assignment\Application\Read_Assignments.php',
    'src\Submission\Assignment\Domain\Assignment_Roster_Snapshot.php',
    'src\Submission\Assignment\Infrastructure\Platform_Assignment_Roster.php',
    'src\Submission\Assignment\Infrastructure\WordPress_Learner_Display_Snapshot.php',
    'src\Submission\Assignment\Infrastructure\WordPress_Assignment_Repository.php',
    'src\Submission\Assignment\Infrastructure\Assignment_Object_Authorization.php',
    'src\Submission\Assignment\Presentation\Assignment_Rest_Controller.php',
    'src\Submission\Assignment\Presentation\Assignment_Rest_Module.php',
    'src\Platform\Assignment_Workbench_Page_Handler.php',
    'src\Core\Composition_Root.php',
    'src\Platform\Addon_Manifest.php',
    'tests\Contract\assignment-create-g3-imp-01.php'
)

foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $phpFiles | Where-Object { -not $_.StartsWith('tests\') }
$forbiddenRuntimePrefix = '$wpdb' + '->prefix'
$legacyTablePrefix = 'wp_' + 'oj_ai_'
foreach ($relative in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $relative) -Raw
    if ($source.Contains($forbiddenRuntimePrefix) -or $source.Contains($legacyTablePrefix)) {
        throw "Forbidden add-on table prefix marker found in $relative"
    }
}

& $php (Join-Path $root 'tests\Contract\assignment-create-g3-imp-01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node --check (Join-Path $root 'resources\js\assignment-workbench.js')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node --check (Join-Path $root 'scripts\e2e-assignment-workbench.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node (Join-Path $root 'scripts\e2e-assignment-workbench.mjs')
exit $LASTEXITCODE
