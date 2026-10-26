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
    (Join-Path $root 'src\Platform\Worksheet_Workbench_Page_Handler.php')
    (Join-Path $root 'tests\Contract\worksheet-workbench-s08.php')
    (Join-Path $root 'tests\Contract\worksheet-asset-s01.php')
    (Join-Path $root 'tests\Contract\worksheet-asset-s02.php')
    (Join-Path $root 'tests\Contract\worksheet-template-import-s03.php')
    (Join-Path $root 'tests\Contract\worksheet-duplicate-s04.php')
)

foreach ($file in $phpFiles) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

$phpTests = @(
    (Join-Path $root 'tests\Contract\worksheet-workbench-s08.php')
    (Join-Path $root 'tests\Contract\worksheet-asset-s01.php')
    (Join-Path $root 'tests\Contract\worksheet-asset-s02.php')
    (Join-Path $root 'tests\Contract\worksheet-template-import-s03.php')
    (Join-Path $root 'tests\Contract\worksheet-duplicate-s04.php')
)

foreach ($test in $phpTests) {
    & $php $test
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
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

$bundle = Join-Path $root 'resources\assets\builder\plate-template-builder.js'
if (-not (Select-String -LiteralPath $bundle -SimpleMatch 'ojai-plate-asset-insert' -Quiet)) {
    Write-Host 'Plate asset bridge is missing from the built browser bundle.'
    exit 1
}

$env:OJAI_PHP = $php
node (Join-Path $root 'tests\Contract\worksheet-workbench-s08.mjs')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

node (Join-Path $root 'scripts\e2e-worksheet-workbench.mjs')
exit $LASTEXITCODE
