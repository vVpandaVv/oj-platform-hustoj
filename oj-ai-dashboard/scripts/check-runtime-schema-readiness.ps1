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
    'src\Core\Composition_Root.php',
    'src\Database\Foundation\Port\Schema_Ledger_Reader_Port_Interface.php',
    'src\Database\Foundation\Port\Schema_Ledger_Port_Interface.php',
    'src\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface.php',
    'src\Database\Foundation\Schema_Runtime_Readiness_Result.php',
    'src\Database\Foundation\Schema_Runtime_Readiness_Verifier.php',
    'src\Database\Foundation\Infrastructure\WordPress_Schema_Ledger_Reader.php',
    'src\Database\Foundation\Infrastructure\WordPress_Schema_Runtime_Readiness.php',
    'src\Platform\Addon_Integration.php',
    'src\Platform\Addon_Manifest.php',
    'src\Platform\Addon_Runtime_Readiness_Gate.php',
    'tests\Contract\runtime-schema-readiness.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\runtime-schema-readiness.php')
exit $LASTEXITCODE
