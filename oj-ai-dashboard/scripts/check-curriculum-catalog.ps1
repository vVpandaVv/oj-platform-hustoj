param()

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction SilentlyContinue).Source
}
if (-not $php) {
    Write-Host 'PHP executable not found. Set OJAI_PHP or install PHP.'
    exit 1
}
$test = Join-Path $root 'tests\Contract\curriculum-catalog.php'
& $php -l $test
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php $test
exit $LASTEXITCODE