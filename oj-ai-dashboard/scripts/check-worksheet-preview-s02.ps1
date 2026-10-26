$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'
$files = @(
    'src\Worksheet\Authoring\Domain\Worksheet_Preview_Projection.php',
    'src\Worksheet\Authoring\Domain\Worksheet_Preview_Projector.php',
    'src\Worksheet\Authoring\Application\Read_Worksheet_Preview.php',
    'src\Worksheet\Authoring\Application\Validate_Worksheet_Revision.php',
    'src\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\worksheet-preview-s02.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\worksheet-preview-s02.php')
exit $LASTEXITCODE
