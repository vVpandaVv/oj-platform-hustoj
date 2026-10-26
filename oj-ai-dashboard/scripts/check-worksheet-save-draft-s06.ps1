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
    (Join-Path $root 'src\Storage\Port\Worksheet_Asset_Binder_Port_Interface.php')
    (Join-Path $root 'src\Storage\Infrastructure\WordPress_Worksheet_Asset_Binder.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Save_Worksheet_Draft.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Save_Worksheet_Draft_Command.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Worksheet_Failure.php')
    (Join-Path $root 'src\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Repository.php')
    (Join-Path $root 'src\Worksheet\Authoring\Infrastructure\Worksheet_Draft_Save_Record_Validator.php')
    (Join-Path $root 'tests\Contract\worksheet-save-draft-s05-transaction.php')
    (Join-Path $root 'tests\Contract\worksheet-save-draft-s06-operations.php')
)

foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-save-draft-s06-operations.php')
exit $LASTEXITCODE
