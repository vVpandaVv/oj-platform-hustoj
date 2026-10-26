param(
    [switch]$SkipConstituentChecks,
    [switch]$SkipVisual
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}
$phpOptions = @()
if ((Test-Path -LiteralPath 'D:\php\ext\php_intl.dll') -and ((Split-Path -Parent $php) -eq 'D:\php')) {
    $phpOptions = @('-d', 'extension_dir=D:\php\ext', '-d', 'extension=intl')
}

$runtimeFiles = @(
    (Join-Path $root 'src\Reporting\Export\Application\Export_Access_Guard.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Job.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Artifact.php')
    (Join-Path $root 'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Request_Snapshot.php')
)
$contract = Join-Path $root 'tests\Contract\pdf-profile-integration-freeze-g3-imp-08-s04.php'
foreach ($file in @($runtimeFiles + $contract)) {
    & $php @phpOptions -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$forbiddenPrefix = '$wpdb' + '->prefix'
$legacyPrefix = 'wp_' + 'oj_ai_'
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath $file -Raw
    if ($source.Contains($forbiddenPrefix) -or $source.Contains($legacyPrefix)) {
        throw "Forbidden add-on table prefix marker found in $file"
    }
    if ($source.Contains('CodingPanda\OjPlatform\Kernel\')) {
        throw "Platform internal namespace found in $file"
    }
}

& $php @phpOptions $contract
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

if (-not $SkipConstituentChecks) {
    foreach ($checker in @(
        'check-submission-pdf-g3-imp-08.ps1'
        'check-own-worksheet-pdf-g3-imp-08-s02.ps1'
        'check-own-submission-pdf-g3-imp-08-s03.ps1'
    )) {
        & (Join-Path $PSScriptRoot $checker)
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
}

if (-not $SkipVisual) {
    & (Join-Path $PSScriptRoot 'check-worksheet-pdf-package-integration-s04.ps1')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & node (Join-Path $root 'scripts\e2e-student-assignments.mjs')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

if ($SkipConstituentChecks -or $SkipVisual) {
    Write-Output 'G3-IMP-08-S04 focused contract passed; aggregate evidence is supplied by the separately registered constituent and visual checks.'
} else {
    Write-Output 'G3-IMP-08-S04 passed: C49/C51/C67 profile isolation, canonical render parity, expiry/revocation/CAS evidence, real Korean PDFs, private artifacts, and teacher/student responsive UI.'
}
exit 0
