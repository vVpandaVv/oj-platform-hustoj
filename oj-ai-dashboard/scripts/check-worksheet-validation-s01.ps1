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

$phpFiles = @(
    (Join-Path $root 'src\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Command.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Result.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Validate_Worksheet_Revision.php')
    (Join-Path $root 'src\Worksheet\Authoring\Port\Worksheet_Asset_Readiness_Port_Interface.php')
    (Join-Path $root 'src\Storage\Infrastructure\Worksheet_Asset_Readiness_Checker.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Document_Profile.php')
    (Join-Path $root 'src\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller.php')
    (Join-Path $root 'src\Core\Composition_Root.php')
    (Join-Path $root 'tests\Contract\worksheet-validation-s01.php')
)

foreach ($file in $phpFiles) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-validation-s01.php')
exit $LASTEXITCODE
