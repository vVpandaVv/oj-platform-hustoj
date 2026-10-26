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
    (Join-Path $root 'src\Worksheet\Authoring\Port\Worksheet_Print_Source_Query_Port_Interface.php')
    (Join-Path $root 'src\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Print_Source_Query.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Build_Worksheet_Print_Projection.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Worksheet_Failure.php')
)
$files = @(
    $runtimeFiles
    (Join-Path $root 'tests\Contract\worksheet-print-projection-g2-imp-07-s01.php')
)

foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$forbiddenRuntimePrefix = '$wpdb' + '->prefix'
$legacyTablePrefix = 'wp_' + 'oj_ai_'
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath $file -Raw
    if ($source.Contains($forbiddenRuntimePrefix) -or $source.Contains($legacyTablePrefix)) {
        throw "Forbidden add-on table prefix marker found in $file"
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-print-projection-g2-imp-07-s01.php')
exit $LASTEXITCODE
