param()

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

$runtimeFiles = @(
    (Join-Path $root 'src\Reporting\ResultPdf\Port\Own_Result_Pdf_Source_Query_Port_Interface.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Infrastructure\Q33_Own_Result_Pdf_Source_Query.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Application\Build_Result_Print_Projection.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Application\Result_Pdf_Failure.php')
)
$contract = Join-Path $root 'tests\Contract\result-print-projection-g4-imp-07-s01.php'

foreach ($file in @($runtimeFiles + $contract)) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath $_ -Raw }) -join "`n"
$forbiddenRuntimePrefix = '$wpdb' + '->prefix'
$legacyTablePrefix = 'wp_' + 'oj_ai_'
if ($runtimeSource.Contains($forbiddenRuntimePrefix) -or $runtimeSource.Contains($legacyTablePrefix)) {
    throw 'Forbidden add-on table prefix marker found in G4-IMP-07-S01 runtime.'
}
if ($runtimeSource.Contains('CodingPanda\OjPlatform\Kernel\')) {
    throw 'G4-IMP-07-S01 must not depend on OJ Platform internals.'
}
if ($runtimeSource -match '\b(?:INSERT|UPDATE|DELETE|REPLACE)\b') {
    throw 'G4-IMP-07-S01 must remain mutation-free.'
}

$adapter = Get-Content -LiteralPath $runtimeFiles[1] -Raw
if (-not $adapter.Contains('Read_Own_Submission_Result') -or $adapter.Contains('SELECT ')) {
    throw 'Result PDF source authorization must reuse Q33 without parallel SQL.'
}

& $php $contract
exit $LASTEXITCODE
