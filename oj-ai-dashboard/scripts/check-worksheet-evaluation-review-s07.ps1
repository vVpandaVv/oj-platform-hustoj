param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'

& $php (Join-Path $root 'tests\Contract\worksheet-evaluation-review-s07.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
