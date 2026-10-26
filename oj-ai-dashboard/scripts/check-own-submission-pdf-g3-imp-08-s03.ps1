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
$phpOptions = @()
if ((Test-Path -LiteralPath 'D:\php\ext\php_intl.dll') -and ((Split-Path -Parent $php) -eq 'D:\php')) {
    $phpOptions = @('-d', 'extension_dir=D:\php\ext', '-d', 'extension=intl')
}

$runtimeFiles = @(
    (Join-Path $root 'src\Reporting\SubmissionPdf\Application\Enqueue_Own_Submission_Pdf.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Application\Enqueue_Own_Submission_Pdf_Command.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Application\Run_Next_Submission_Pdf.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Infrastructure\WordPress_Submission_Pdf_Repository.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Port\Own_Submission_Pdf_Source_Query_Port_Interface.php')
    (Join-Path $root 'src\Reporting\Export\Application\Export_Access_Guard.php')
    (Join-Path $root 'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php')
    (Join-Path $root 'src\Reporting\Export\Presentation\Export_Rest_Controller.php')
    (Join-Path $root 'src\Submission\Student\Infrastructure\Submission_Object_Authorization.php')
    (Join-Path $root 'src\Submission\Student\Infrastructure\WordPress_Own_Submission_Pdf_Source_Query.php')
    (Join-Path $root 'src\Submission\Student\Infrastructure\WordPress_Student_Submission_Query.php')
    (Join-Path $root 'src\Submission\Student\Presentation\Student_Assignment_Rest_Controller.php')
    (Join-Path $root 'src\Core\Composition_Root.php')
)
$contracts = @(
    (Join-Path $root 'tests\Contract\own-submission-pdf-enqueue-worker-g3-imp-08-s03.php')
    (Join-Path $root 'tests\Contract\own-submission-pdf-query-download-g3-imp-08-s03.php')
)

foreach ($file in @($runtimeFiles + $contracts)) {
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

foreach ($contract in $contracts) {
    & $php @phpOptions $contract
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G3-IMP-08-S03 passed: strict C67, current official ownership, snapshot 1.1, double self authorization, private Q23/Q24, and no-prefix isolation.'
exit 0
