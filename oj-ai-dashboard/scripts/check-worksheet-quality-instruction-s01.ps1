$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$platform = $env:OJ_PLATFORM_SOURCE
if (-not $platform) {
    $platform = Join-Path (Split-Path -Parent $root) 'oj-integration-rebuild\oj-platform'
}
$php = 'D:\php\php.exe'

$files = @(
    'src\Platform\Addon_Manifest.php',
    'tests\Contract\worksheet-quality-instruction-s01.php'
)
foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\worksheet-quality-instruction-s01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& $php (Join-Path $platform 'tests\Contract\addon-i06-ai-instruction.php')
exit $LASTEXITCODE
