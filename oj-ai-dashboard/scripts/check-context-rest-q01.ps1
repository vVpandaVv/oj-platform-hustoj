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
    'src\Context\Context_Value.php',
    'src\Context\Teaching_Context_Page.php',
    'src\Context\Teaching_Context_Query.php',
    'src\Context\Teaching_Context_Query_Result.php',
    'src\Context\Teaching_Context_Summary.php',
    'src\Context\Infrastructure\Platform_Teaching_Context_Query.php',
    'src\Context\Port\Teaching_Context_Query_Port_Interface.php',
    'src\Context\Presentation\Context_Rest_Controller.php',
    'src\Context\Presentation\Context_Rest_Module.php',
    'src\Rest\Rest_Permission_Grant.php',
    'src\Rest\Rest_Permission_Handoff.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\context-rest-q01.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\context-rest-q01.php')
exit $LASTEXITCODE
