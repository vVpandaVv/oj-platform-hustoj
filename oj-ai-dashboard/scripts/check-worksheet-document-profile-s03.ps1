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
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Canonical_Json.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Public_Id.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Public_Id_Generator.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Document_Profile.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Canonical_Worksheet_Document.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer.php')
    (Join-Path $root 'tests\Contract\worksheet-document-profile-s03-contract.php')
)

foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-document-profile-s03-contract.php')
exit $LASTEXITCODE
