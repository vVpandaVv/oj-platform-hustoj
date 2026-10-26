param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'php-source-markers.ps1')
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

$runtimeFiles = @(
    (Join-Path $root 'src\Reporting\ResultPdf\Application\Enqueue_Result_Pdf_Command.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Application\Enqueue_Result_Pdf_Result.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Application\Enqueue_Own_Result_Pdf.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Application\Run_Result_Pdf_Result.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Application\Run_Next_Result_Pdf.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Application\Result_Pdf_Failure.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Domain\Result_Pdf_Request_Snapshot.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Domain\Result_Pdf_Work_Item.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Domain\Result_Print_Html_Serializer.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Port\Result_Pdf_Repository_Port_Interface.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Port\Result_Pdf_Work_Repository_Port_Interface.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Port\Result_Pdf_Queue_Wakeup_Port_Interface.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Infrastructure\WordPress_Result_Pdf_Repository.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Infrastructure\WordPress_Result_Pdf_Queue_Wakeup.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Infrastructure\Result_Pdf_Worker_Module.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Presentation\Result_Pdf_Rest_Controller.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Presentation\Result_Pdf_Rest_Module.php')
    (Join-Path $root 'src\Reporting\Export\Application\Export_Access_Guard.php')
    (Join-Path $root 'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php')
    (Join-Path $root 'src\Submission\Assessment\Infrastructure\Submission_Result_Object_Authorization.php')
    (Join-Path $root 'src\Core\Composition_Root.php')
    (Join-Path $root 'src\Core\Deactivation.php')
)
$contract = Join-Path $root 'tests\Contract\result-pdf-job-g4-imp-07-s02.php'
$storageContract = Join-Path $root 'tests\Contract\result-pdf-storage-g4-imp-07-s02.php'

foreach ($file in @($runtimeFiles + $contract + $storageContract)) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath $_ -Raw }) -join "`n"
$forbiddenRuntimePrefix = '$wpdb' + '->prefix'
$legacyTablePrefix = 'wp_' + 'oj_ai_'
if ($runtimeSource.Contains($forbiddenRuntimePrefix) -or $runtimeSource.Contains($legacyTablePrefix)) {
    throw 'Forbidden add-on table prefix marker found in G4-IMP-07-S02 runtime.'
}
if ($runtimeSource.Contains('CodingPanda\OjPlatform\Kernel\')) {
    throw 'G4-IMP-07-S02 must not depend on OJ Platform internals.'
}

$repository = Get-Content -LiteralPath (Join-Path $root 'src\Reporting\ResultPdf\Infrastructure\WordPress_Result_Pdf_Repository.php') -Raw
foreach ($table in @('oj_ai_export_jobs', 'oj_ai_pdf_artifacts', 'oj_ai_audit_events', 'oj_ai_outbox_events')) {
    if (-not (Test-OjaiPhpMarker -Source $repository -Marker $table -Php $php)) { throw "Missing literal result PDF persistence table: $table" }
}
foreach ($marker in @("'source_type' => 'submission_release'", "'artifact_kind' => 'result_pdf'", 'request_snapshot_hash')) {
    if (-not (Test-OjaiPhpMarker -Source $repository -Marker $marker -Php $php)) { throw "Missing result PDF repository contract marker: $marker" }
}

$worker = Get-Content -LiteralPath (Join-Path $root 'src\Reporting\ResultPdf\Application\Run_Next_Result_Pdf.php') -Raw
if (([regex]::Matches($worker, '\$this->authorized\(')).Count -lt 2 -or ([regex]::Matches($worker, '\$this->assert_same_snapshot\(')).Count -lt 2 -or -not (Test-OjaiPhpMarker -Source $worker -Marker 'LEASE_SECONDS = 300' -Php $php) -or -not (Test-OjaiPhpMarker -Source $worker -Marker 'RETENTION_SECONDS = 86400' -Php $php) -or -not (Test-OjaiPhpMarker -Source $worker -Marker 'put_quarantined' -Php $php)) {
    throw 'Result PDF worker must re-read the current release before and after render and persist privately.'
}

& $php $storageContract
exit $LASTEXITCODE
