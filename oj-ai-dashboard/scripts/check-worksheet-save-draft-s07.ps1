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
    (Join-Path $root 'src\Core\Composition_Root.php')
    (Join-Path $root 'src\Rest\Rest_Envelope_Factory.php')
    (Join-Path $root 'src\Worksheet\Authoring\Infrastructure\Worksheet_Object_Authorization.php')
    (Join-Path $root 'src\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller.php')
    (Join-Path $root 'tests\Contract\worksheet-authoring-g2-imp-01.php')
    (Join-Path $root 'tests\Contract\worksheet-save-draft-s07-rest.php')
)

foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-authoring-g2-imp-01.php')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

& $php (Join-Path $root 'tests\Contract\worksheet-save-draft-s07-rest.php')
exit $LASTEXITCODE
