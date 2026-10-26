$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'
$files = @(
    'src\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector.php',
    'src\Worksheet\Authoring\Application\Validate_Worksheet_Revision.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\worksheet-projection-safety-s03.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\worksheet-projection-safety-s03.php')
exit $LASTEXITCODE
