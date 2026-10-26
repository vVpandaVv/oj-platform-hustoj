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
    (Join-Path $root 'src\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Application\Run_Next_Submission_Pdf.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Application\Run_Submission_Pdf_Result.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Application\Enqueue_Submission_Pdf.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Application\Enqueue_Submission_Pdf_Command.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Domain\Submission_Pdf_Work_Item.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Infrastructure\Dompdf_Html_To_Pdf_Renderer.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Application\Enqueue_Submission_Pdf_Result.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Application\Submission_Pdf_Failure.php')
    (Join-Path $root 'src\Reporting\Export\Application\Export_Access_Guard.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Job.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Artifact.php')
    (Join-Path $root 'src\Reporting\Export\Application\Export_Download.php')
    (Join-Path $root 'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Infrastructure\Submission_Pdf_Worker_Module.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Infrastructure\WordPress_Submission_Pdf_Queue_Wakeup.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Infrastructure\WordPress_Submission_Pdf_Repository.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Port\Submission_Pdf_Work_Repository_Port_Interface.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Port\Submission_Pdf_Queue_Wakeup_Port_Interface.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Port\Submission_Pdf_Repository_Port_Interface.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Presentation\Submission_Pdf_Rest_Controller.php')
    (Join-Path $root 'src\Reporting\SubmissionPdf\Presentation\Submission_Pdf_Rest_Module.php')
    (Join-Path $root 'src\Submission\Teacher\Port\Submission_Print_Source_Query_Port_Interface.php')
    (Join-Path $root 'src\Submission\Teacher\Infrastructure\WordPress_Teacher_Submission_Query.php')
    (Join-Path $root 'src\Worksheet\Authoring\Port\Worksheet_Publication_Print_Source_Query_Port_Interface.php')
    (Join-Path $root 'src\Core\Deactivation.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Build_Worksheet_Print_Projection.php')
    (Join-Path $root 'src\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Print_Source_Query.php')
    (Join-Path $root 'src\Core\Composition_Root.php')
)
$contracts = @(
    (Join-Path $root 'tests\Contract\worksheet-print-projection-g2-imp-07-s01.php')
    (Join-Path $root 'tests\Contract\teacher-submission-query-g3-imp-06-s02.php')
    (Join-Path $root 'tests\Contract\submission-pdf-worker-g3-imp-08-s01.php')
    (Join-Path $root 'tests\Contract\submission-pdf-enqueue-g3-imp-08-s01.php')
    (Join-Path $root 'tests\Contract\submission-pdf-query-download-g3-imp-08-s01.php')
)

foreach ($file in @($runtimeFiles + $contracts)) {
    & $php -l $file
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
    & $php $contract
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& (Join-Path $root 'scripts\check-submission-pdf-integration-s01-g.ps1')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G3-IMP-08 submission PDF aggregate passed through S01-G: frozen source, C49, worker, Context-aware Q23/Q24, real Korean PDF, responsive teacher UI, and private download.'
exit 0
