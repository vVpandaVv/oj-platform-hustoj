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
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Approval_Policy.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Approval_Decision.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Approval_Policy_Gate.php')
    (Join-Path $root 'tests\Contract\worksheet-approval-policy-s01.php')
)

foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$forbiddenRuntimePrefix = '$wpdb' + '->prefix'
$legacyTablePrefix = 'wp_' + 'oj_ai_'
foreach ($file in $files) {
    $source = Get-Content -LiteralPath $file -Raw
    if ($source.Contains($forbiddenRuntimePrefix) -or $source.Contains($legacyTablePrefix)) {
        throw "Forbidden add-on table prefix marker found in $file"
    }
}

$gateSource = Get-Content -LiteralPath $files[2] -Raw
foreach ($marker in @(
    'worksheet_evaluation_safety_review_required',
    'worksheet_evaluation_reassessment_required',
    'worksheet_evaluation_dismissal_not_allowed',
    'worksheet_validation_evidence_inconsistent'
)) {
    if (-not $gateSource.Contains($marker)) {
        throw "Approval gate marker missing: $marker"
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-approval-policy-s01.php')
exit $LASTEXITCODE
