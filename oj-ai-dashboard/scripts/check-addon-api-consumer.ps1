param(
    [string] $PlatformSource = ''
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')

function Get-PhpExecutable {
    if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
        return $env:OJAI_PHP
    }

    $localPhp = 'D:\php\php.exe'
    if (Test-Path -LiteralPath $localPhp) {
        return $localPhp
    }

    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    return $null
}

$php = Get-PhpExecutable
if (-not $php) {
    Write-Host 'PHP executable not found. Set OJAI_PHP or install PHP.'
    exit 1
}

if ($PlatformSource) {
    $env:OJ_PLATFORM_SOURCE = $PlatformSource
}

& $php (Join-Path $root 'tests\Contract\addon-api-consumer.php')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

& $php (Join-Path $root 'tests\Contract\plugin-load-order.php')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

& $php (Join-Path $root 'tests\Contract\platform-console-menu-compatibility.php')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

& $php (Join-Path $root 'tests\Architecture\composition-root.php')
exit $LASTEXITCODE
