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
    (Join-Path $root 'src\Submission\Assessment\Domain\Submission_Score_Input.php')
    (Join-Path $root 'src\Submission\Assessment\Domain\Submission_Score_Input_Builder.php')
    (Join-Path $root 'src\Submission\Assessment\Domain\Submission_Score_Input_Failure.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Publication_Projector.php')
    (Join-Path $root 'tests\Contract\submission-assessment-input-g4-imp-01-s02.php')
    (Join-Path $root 'tests\Contract\worksheet-publication-s03.php')
)
foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$forbiddenPrefix = '$wpdb' + '->prefix'
$legacyPrefix = 'wp_' + 'oj_ai_'
$platformInternal = 'CodingPanda\OjPlatform\Kernel\'
$runtimeRoot = (Join-Path $root 'src') + [IO.Path]::DirectorySeparatorChar
foreach ($file in @($files | Where-Object { $_.StartsWith($runtimeRoot, [StringComparison]::OrdinalIgnoreCase) })) {
    $source = Get-Content -LiteralPath $file -Raw
    if ($source.Contains($forbiddenPrefix) -or $source.Contains($legacyPrefix)) {
        throw "Forbidden add-on table prefix marker found in $file"
    }
    if ($source.Contains($platformInternal)) {
        throw "Platform internal namespace found in $file"
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-publication-s03.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& $php (Join-Path $root 'tests\Contract\submission-assessment-input-g4-imp-01-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-01-S02 passed: official source, P03 binding identity, ten response kinds, private artifact separation, redaction and fail-closed limits.'
exit 0
