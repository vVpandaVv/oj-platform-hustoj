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
    'src\Core\Activation.php',
    'src\Core\Installation_Manager.php',
    'src\Core\Activation_Preflight.php',
    'src\Core\Activation_Workflow.php',
    'src\Database\Foundation\Port\Schema_Installer_Port_Interface.php',
    'src\Database\Foundation\Schema_Installation_Runner.php',
    'src\Database\Foundation\Infrastructure\WordPress_Schema_Installation_Runner_Factory.php',
    'tests\Contract\activation-database-integration.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\activation-database-integration.php')
exit $LASTEXITCODE
