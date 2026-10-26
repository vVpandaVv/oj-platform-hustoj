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
    (Join-Path $root 'src\Platform\Addon_Manifest.php')
    (Join-Path $root 'src\Platform\Worksheet_Workbench_Page_Handler.php')
    (Join-Path $root 'tests\Contract\addon-api-consumer.php')
    (Join-Path $root 'tests\Contract\worksheet-workbench-s08.php')
    (Join-Path $root 'tests\fixtures\worksheet-workbench-document-verifier.php')
)

foreach ($file in $phpFiles) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\addon-api-consumer.php')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

& $php (Join-Path $root 'tests\Contract\worksheet-workbench-s08.php')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

node --check (Join-Path $root 'resources\js\worksheet-authoring-workbench.js')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

node --check (Join-Path $root 'tests\Contract\worksheet-workbench-s08.mjs')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

node --check (Join-Path $root 'scripts\e2e-worksheet-workbench.mjs')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

$env:OJAI_PHP = $php
node (Join-Path $root 'tests\Contract\worksheet-workbench-s08.mjs')
exit $LASTEXITCODE
