param()

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

$files = @(
    Get-ChildItem -LiteralPath (Join-Path $root 'src\Worksheet\Authoring') -Filter '*.php' -Recurse
)
$files += Get-Item -LiteralPath @(
    (Join-Path $root 'src\Security\Policy\Object_Scope.php')
    (Join-Path $root 'src\Security\Policy\Infrastructure\Object_Authorization_Router.php')
    (Join-Path $root 'src\Core\Composition_Root.php')
    (Join-Path $root 'tests\Contract\worksheet-authoring-g2-imp-01.php')
)

foreach ($file in $files) {
    & $php -l $file.FullName
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-authoring-g2-imp-01.php')
exit $LASTEXITCODE
