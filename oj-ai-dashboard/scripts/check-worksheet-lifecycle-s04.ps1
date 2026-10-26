param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = if (Test-Path 'D:\php\php.exe') { 'D:\php\php.exe' } else { 'php' }
$files = @(
    'src\Worksheet\Authoring\Application\Worksheet_Lifecycle_Command.php',
    'src\Worksheet\Authoring\Application\Worksheet_Lifecycle_Result.php',
    'src\Worksheet\Authoring\Application\Change_Worksheet_Lifecycle.php',
    'src\Worksheet\Authoring\Application\Worksheet_Failure.php',
    'src\Worksheet\Authoring\Port\Worksheet_Lifecycle_Repository_Port_Interface.php',
    'src\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Lifecycle_Repository.php',
    'src\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\worksheet-lifecycle-s04.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\worksheet-lifecycle-s04.php')
exit $LASTEXITCODE
