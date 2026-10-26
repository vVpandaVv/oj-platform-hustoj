param(
    [switch]$SkipConstituentChecks
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'php-source-markers.ps1')
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}
$phpArgs = @()
$extensionDir = Join-Path (Split-Path -Parent $php) 'ext'
if (Test-Path -LiteralPath $extensionDir) {
    $phpArgs += @('-d', "extension_dir=$extensionDir")
    foreach ($extension in @('intl', 'mbstring', 'zip')) {
        if (Test-Path -LiteralPath (Join-Path $extensionDir "php_$extension.dll")) {
            $phpArgs += @('-d', "extension=$extension")
        }
    }
}

function Invoke-Checked([string]$Command, [string[]]$Arguments) {
    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed ($LASTEXITCODE): $Command $($Arguments -join ' ')"
    }
}

function Assert-Markers([string]$Path, [string[]]$Markers, [string]$Label) {
    $source = Get-Content -LiteralPath (Join-Path $root $Path) -Raw -Encoding UTF8
    foreach ($marker in $Markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) {
            throw "G5-IMP-03-S04 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    foreach ($check in @(
        'check-report-export-enqueue-g5-imp-03-s01.ps1',
        'check-submission-bundle-enqueue-g5-imp-03-s02.ps1',
        'check-report-json-package-enqueue-g5-imp-03-s03.ps1'
    )) {
        Invoke-Checked 'powershell' @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot $check), '-SkipConstituentChecks'
        )
    }
}

$phpFiles = @(
    'src\Reporting\Export\Application\Export_Access_Guard.php'
    'src\Reporting\Export\Application\Export_Download.php'
    'src\Reporting\Export\Application\Read_Export_Artifact.php'
    'src\Reporting\Export\Application\Read_Export_Job.php'
    'src\Reporting\Export\Domain\Export_Work_Item.php'
    'src\Reporting\Export\Domain\Generated_Export_File.php'
    'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php'
    'src\Reporting\Export\Infrastructure\WordPress_Export_Work_Repository.php'
    'src\Reporting\Export\Port\Export_Work_Repository_Port_Interface.php'
    'src\Reporting\Export\Presentation\Export_Rest_Controller.php'
    'src\Reporting\ReportExport\Application\Report_Export_Failure.php'
    'src\Reporting\ReportExport\Application\Run_Next_Report_Export.php'
    'src\Reporting\ReportExport\Application\Run_Report_Export_Result.php'
    'src\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot.php'
    'src\Reporting\ReportExport\Domain\Report_Print_Html_Serializer.php'
    'src\Reporting\ReportExport\Infrastructure\Report_Export_Artifact_Writer.php'
    'src\Reporting\ReportExport\Infrastructure\Report_Export_Worker_Module.php'
    'src\Reporting\ReportExport\Port\Report_Export_Writer_Port_Interface.php'
    'src\Reporting\SubmissionBundle\Application\Run_Next_Submission_Bundle.php'
    'src\Reporting\SubmissionBundle\Application\Run_Submission_Bundle_Result.php'
    'src\Reporting\SubmissionBundle\Application\Submission_Bundle_Failure.php'
    'src\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot.php'
    'src\Reporting\SubmissionBundle\Infrastructure\Deterministic_Submission_Bundle_Writer.php'
    'src\Reporting\SubmissionBundle\Infrastructure\Submission_Bundle_Worker_Module.php'
    'src\Reporting\SubmissionBundle\Infrastructure\WordPress_Submission_Bundle_Attachment_Reader.php'
    'src\Reporting\SubmissionBundle\Port\Submission_Bundle_Attachment_Read_Port_Interface.php'
    'src\Reporting\SubmissionBundle\Port\Submission_Bundle_Writer_Port_Interface.php'
    'src\Storage\Infrastructure\WordPress_Export_Artifact_Private_File_Metadata.php'
    'src\Core\Composition_Root.php'
    'src\Core\Deactivation.php'
    'tests\Contract\report-export-job-download-g5-imp-03-s04.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php (@($phpArgs) + @('-l', (Join-Path $root $file)))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object {
    Get-Content -LiteralPath (Join-Path $root $_) -Raw -Encoding UTF8
}) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) {
        throw "G5-IMP-03-S04 contains a forbidden boundary marker: $forbidden"
    }
}

Assert-Markers 'src\Reporting\ReportExport\Infrastructure\Report_Export_Artifact_Writer.php' @(
    "'report_csv'"
    "'report_pdf'"
    "'report_json_package'"
    'checksums.sha256'
    'neutralized_cell_count'
    'metric_value'
) 'report bytes'
Assert-Markers 'src\Reporting\SubmissionBundle\Infrastructure\Deterministic_Submission_Bundle_Writer.php' @(
    'manifest.csv'
    'checksums.sha256'
    'archive_path'
    'ZIP_UNAVAILABLE'
) 'submission bundle bytes'
Assert-Markers 'src\Reporting\ReportExport\Application\Run_Next_Report_Export.php' @(
    'LEASE_SECONDS = 300'
    'RETENTION_SECONDS = 86400'
    'put_quarantined'
    'complete_ready'
    'SOURCE_ACCESS_DENIED'
    "'context'"
) 'report worker'
Assert-Markers 'src\Reporting\SubmissionBundle\Application\Run_Next_Submission_Bundle.php' @(
    'LEASE_SECONDS = 300'
    'RETENTION_SECONDS = 86400'
    'put_quarantined'
    'complete_ready'
    'SOURCE_STALE'
) 'bundle worker'
Assert-Markers 'src\Reporting\Export\Infrastructure\WordPress_Export_Work_Repository.php' @(
    "TABLE_JOBS = 'oj_ai_export_jobs'"
    "TABLE_PDF = 'oj_ai_pdf_artifacts'"
    'lease_token'
    'START TRANSACTION'
) 'literal work persistence'
Assert-Markers 'src\Storage\Infrastructure\WordPress_Export_Artifact_Private_File_Metadata.php' @(
    "TABLE = 'oj_ai_private_files'"
) 'literal private file metadata'
Assert-Markers 'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php' @(
    "'report_snapshot'"
    "'assignment'"
    "'context_reporter'"
    "'context_staff'"
    "'report_json_package'"
    "'submission_bundle'"
) 'Q23 Q24 query'
Assert-Markers 'src\Reporting\Export\Application\Export_Download.php' @(
    "'report_csv'"
    "'report_pdf'"
    "'report_json_package'"
    "'submission_bundle'"
    "'application/zip'"
) 'download profiles'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'new Report_Export_Worker_Module'
    'new Submission_Bundle_Worker_Module'
    'WordPress_Export_Work_Repository'
) 'composition'
Assert-Markers 'tests\Contract\report-export-job-download-g5-imp-03-s04.php' @(
    'C46 formula neutralization or CRLF drifted.'
    'C69 deterministic ZIP hash drifted.'
    'C50 deterministic bundle hash drifted.'
    'Post-render report relation drift did not fail.'
    'Q24 served an expired report artifact.'
    'G5-IMP-03-S04 passed:'
) 'vertical contract'
Assert-Markers 'docs\ai-assessment\g5-imp-03-s04-report-export-job-download.html' @(
    'G5-IMP-03-S04'
    'IMP-03 4/6'
    'Q23'
    'Q24'
    'check:report-export-job-download-g5'
    'Platform API change: 0'
    'G5-IMP-03-S05'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-03-s04-report-export-job-download.html'
    'G5-IMP-03-S04'
    'private ready CAS'
    'check:report-export-job-download-g5'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:report-export-job-download-g5'
    'check-report-export-job-download-g5-imp-03-s04.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-report-export-job-download-g5-imp-03-s04.ps1'
    'Report export job and download Q23/Q24 G5-IMP-03-S04 passed.'
) 'aggregate runner'

Invoke-Checked $php (@($phpArgs) + @((Join-Path $root 'tests\Contract\report-export-job-download-g5-imp-03-s04.php')))

Write-Output 'G5-IMP-03-S04 passed: actual report and submission bundle bytes, workers, private ready CAS, Q23/Q24 authorization, download integrity, expiry, and no-prefix boundaries are frozen.'
