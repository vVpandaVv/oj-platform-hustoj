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

$files = @(
    (Join-Path $root 'src\Worksheet\Authoring\Application\Publish_Worksheet_Revision_Command.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Publish_Worksheet_Revision_Result.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Publish_Worksheet_Revision.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Publication_Projection.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Publication_Projector.php')
    (Join-Path $root 'src\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Publication_Configuration.php')
    (Join-Path $root 'src\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Publication_Repository.php')
    (Join-Path $root 'src\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller.php')
    (Join-Path $root 'src\Core\Composition_Root.php')
    (Join-Path $root 'tests\Contract\worksheet-publication-s03.php')
)

foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$forbiddenRuntimePrefix = '$wpdb' + '->prefix'
$legacyTablePrefix = 'wp_' + 'oj_ai_'
$runtimeRoot = (Join-Path $root 'src') + [IO.Path]::DirectorySeparatorChar
foreach ($file in @($files | Where-Object { $_.StartsWith($runtimeRoot, [StringComparison]::OrdinalIgnoreCase) })) {
    $source = Get-Content -LiteralPath $file -Raw
    if ($source.Contains($forbiddenRuntimePrefix) -or $source.Contains($legacyTablePrefix)) {
        throw "Forbidden add-on table prefix marker found in $file"
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-publication-s03.php')
exit $LASTEXITCODE
