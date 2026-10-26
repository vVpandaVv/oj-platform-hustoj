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
    (Join-Path $root 'src\Worksheet\Authoring\Application\Save_Worksheet_Draft_Command.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Worksheet_Failure.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Canonical_Json.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Public_Id.php')
    (Join-Path $root 'tests\Contract\worksheet-save-draft-c02-contract.php')
)

foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-save-draft-c02-contract.php')
exit $LASTEXITCODE
