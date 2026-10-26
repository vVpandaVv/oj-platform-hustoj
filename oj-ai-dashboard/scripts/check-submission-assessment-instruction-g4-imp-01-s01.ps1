$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$platform = $env:OJ_PLATFORM_SOURCE
if (-not $platform) {
    $platform = Join-Path (Split-Path -Parent $root) 'oj-integration-rebuild\oj-platform'
}
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

$files = @(
    (Join-Path $root 'src\Platform\Addon_Manifest.php')
    (Join-Path $root 'tests\Contract\addon-api-consumer.php')
    (Join-Path $root 'tests\Contract\submission-assessment-instruction-g4-imp-01-s01.php')
)
foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$forbiddenPrefix = '$wpdb' + '->prefix'
$legacyPrefix = 'wp_' + 'oj_ai_'
$boundaryFiles = @(
    (Join-Path $root 'src\Platform\Addon_Manifest.php')
    (Join-Path $root 'resources\contracts\ai\student-submission-assessment-prompt-v2.txt')
)
foreach ($file in $boundaryFiles) {
    $source = Get-Content -LiteralPath $file -Raw
    if ($source.Contains($forbiddenPrefix) -or $source.Contains($legacyPrefix)) {
        throw "Forbidden add-on table prefix marker found in $file"
    }
    if ($source.Contains('CodingPanda\OjPlatform\Kernel\')) {
        throw "Platform internal namespace found in $file"
    }
}

& $php (Join-Path $root 'tests\Contract\submission-assessment-instruction-g4-imp-01-s01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$providerContract = Join-Path $platform 'tests\Contract\addon-i06-ai-instruction.php'
if (-not (Test-Path -LiteralPath $providerContract)) {
    throw "OJ Platform AJ08 provider contract is unavailable: $providerContract"
}
& $php $providerContract
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-01-S01 passed: submission task instruction v2, immutable v1 history, operation isolation, score/abstain safety, and AJ08 provider order.'
exit 0
