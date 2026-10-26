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
    (Join-Path $root 'src\Reporting\WorksheetPdf\Application\Worksheet_Pdf_Failure.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Application\Enqueue_Worksheet_Pdf_Command.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Application\Enqueue_Worksheet_Pdf_Result.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Application\Enqueue_Worksheet_Pdf.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Application\Run_Worksheet_Pdf_Result.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Application\Run_Next_Worksheet_Pdf.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Application\Read_Worksheet_Pdf_Job.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Application\Read_Worksheet_Pdf_Artifact.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Application\Worksheet_Pdf_Download.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Request_Snapshot.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Work_Item.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Render_Result.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Domain\Worksheet_Print_Html_Serializer.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Infrastructure\Dompdf_Html_To_Pdf_Renderer.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Infrastructure\WordPress_Worksheet_Pdf_Repository.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Infrastructure\WordPress_Worksheet_Pdf_Queue_Wakeup.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Infrastructure\Worksheet_Pdf_Worker_Module.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Presentation\Worksheet_Pdf_Rest_Controller.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Presentation\Worksheet_Pdf_Rest_Module.php')
    (Join-Path $root 'src\Reporting\Export\Application\Export_Failure.php')
    (Join-Path $root 'src\Reporting\Export\Application\Export_Download.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Job.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Artifact.php')
    (Join-Path $root 'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php')
    (Join-Path $root 'src\Reporting\Export\Presentation\Export_Rest_Controller.php')
    (Join-Path $root 'src\Storage\Infrastructure\WordPress_Generated_Private_File_Metadata.php')
)
$contract = Join-Path $root 'tests\Contract\worksheet-pdf-job-artifact-g2-imp-07-s02.php'
$phpArgs = @()
$extensionDir = Join-Path (Split-Path -Parent $php) 'ext'
if (Test-Path -LiteralPath $extensionDir) {
    $phpArgs += @('-d', "extension_dir=$extensionDir")
    foreach ($extension in @('intl', 'mbstring')) {
        if (Test-Path -LiteralPath (Join-Path $extensionDir "php_$extension.dll")) {
            $phpArgs += @('-d', "extension=$extension")
        }
    }
}

foreach ($file in @($runtimeFiles + $contract)) {
    & $php @phpArgs -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$forbiddenPrefix = '$wpdb' + '->prefix'
$legacyPrefix = 'wp_' + 'oj_ai_'
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath $file -Raw
    if ($source.Contains($forbiddenPrefix) -or $source.Contains($legacyPrefix)) {
        throw "Forbidden add-on table prefix marker found in $file"
    }
}

if (-not (Test-Path -LiteralPath (Join-Path $root 'composer.lock'))) {
    throw 'composer.lock is required for the PDF adapter.'
}
if (-not (Test-Path -LiteralPath (Join-Path $root 'resources\fonts\noto-sans-kr\NotoSansKR-Regular.ttf'))) {
    throw 'Packaged static Noto Sans KR font is missing.'
}
if (-not (Test-Path -LiteralPath (Join-Path $root 'resources\fonts\noto-sans-kr\OFL.txt'))) {
    throw 'Packaged Noto Sans KR license is missing.'
}

& $php @phpArgs $contract
exit $LASTEXITCODE
