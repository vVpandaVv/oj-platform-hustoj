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
            throw "G5-IMP-03-S06 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    foreach ($gate in @(
        'check-report-export-enqueue-g5-imp-03-s01.ps1',
        'check-submission-bundle-enqueue-g5-imp-03-s02.ps1',
        'check-report-json-package-enqueue-g5-imp-03-s03.ps1',
        'check-report-export-job-download-g5-imp-03-s04.ps1',
        'check-report-export-control-g5-imp-03-s05.ps1'
    )) {
        Invoke-Checked 'powershell' @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot $gate), '-SkipConstituentChecks'
        )
    }
}

$phpFiles = @(
    'src\Reporting\Export\Application\Export_Access_Guard.php'
    'src\Reporting\Export\Application\Export_Download.php'
    'src\Reporting\Export\Application\Read_Export_Artifact.php'
    'src\Reporting\Export\Application\Read_Export_Job.php'
    'src\Reporting\Export\Domain\Generated_Export_File.php'
    'src\Reporting\Export\Infrastructure\WordPress_Export_Control_Repository.php'
    'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php'
    'src\Reporting\Export\Presentation\Export_Rest_Controller.php'
    'src\Reporting\ReportExport\Application\Run_Next_Report_Export.php'
    'src\Reporting\SubmissionBundle\Application\Run_Next_Submission_Bundle.php'
    'src\Storage\Infrastructure\WordPress_Export_Artifact_Private_File_Metadata.php'
    'tests\Contract\report-export-integration-freeze-g5-imp-03-s06.php'
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
        throw "G5-IMP-03-S06 contains a forbidden boundary marker: $forbidden"
    }
}

Assert-Markers 'src\Reporting\Export\Presentation\Export_Rest_Controller.php' @(
    'serve_stream'
    'same_download'
    'verified_handle'
    'hash_init'
    'flock'
    'X-OJ-Content-SHA256'
) 'pre-stream defense'
Assert-Markers 'src\Reporting\Export\Application\Read_Export_Artifact.php' @(
    'readable_path'
    'filesize'
    'STORED_DATA_INVALID'
) 'Q24 integrity'
Assert-Markers 'src\Reporting\Export\Domain\Generated_Export_File.php' @(
    "hash_file( 'sha256'"
    'hash_equals'
) 'generated checksum'
Assert-Markers 'src\Reporting\ReportExport\Application\Run_Next_Report_Export.php' @(
    'put_quarantined'
    'complete_ready'
    '$context = $this->authorize'
) 'report worker authorization'
Assert-Markers 'src\Reporting\SubmissionBundle\Application\Run_Next_Submission_Bundle.php' @(
    'put_quarantined'
    'complete_ready'
    '$this->authorize'
) 'bundle worker authorization'
Assert-Markers 'src\Reporting\Export\Infrastructure\WordPress_Export_Control_Repository.php' @(
    "TABLE_JOBS   = 'oj_ai_export_jobs'"
    'active_identity_key'
    'export_job.cancelled'
    'export_job.retry_queued'
) 'control lineage'
Assert-Markers 'src\Storage\Infrastructure\WordPress_Export_Artifact_Private_File_Metadata.php' @(
    "TABLE = 'oj_ai_private_files'"
    "'sha256'"
    "'byte_size'"
) 'private metadata'

Assert-Markers 'tests\Contract\report-export-job-download-g5-imp-03-s04.php' @(
    'C69 deterministic ZIP hash drifted.'
    'C50 deterministic bundle hash drifted.'
    'Q24 accepted a report object relation that no longer matched Context.'
    'Q24 served an expired report artifact.'
) 'S04 vertical regression'
Assert-Markers 'tests\Contract\report-export-control-g5-imp-03-s05.php' @(
    'C53 did not cancel a queued export.'
    'C54 did not create a queued retry.'
    'C54 did not reuse the existing semantic job.'
) 'S05 vertical regression'
Assert-Markers 'tests\Contract\report-export-integration-freeze-g5-imp-03-s06.php' @(
    'C54 changed frozen retry evidence:'
    'Q23 disclosed a cancelled job after current authorization was revoked.'
    'Pre-stream authorization revocation emitted bytes.'
    'Changed artifact metadata emitted bytes.'
    'Same-size post-validation file tampering emitted bytes.'
    'G5-IMP-03-S06 passed:'
) 'integration contract'
Assert-Markers 'docs\ai-assessment\g5-imp-03-s06-report-export-integration-freeze.html' @(
    'G5-IMP-03-S06'
    'IMP-03 6/6'
    'check:report-export-integration-g5'
    'Platform API change: 0'
    'G5-IMP-05-S01'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-03-s06-report-export-integration-freeze.html'
    'g5-imp-03-s06-report-export-integration-freeze.html'
    'IMP-03 6/6'
    'G5 6/8'
    'G5-IMP-06-S01'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:report-export-integration-g5'
    'check-report-export-integration-g5-imp-03-s06.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-report-export-integration-g5-imp-03-s06.ps1'
    'Report export checksum and authorization integration G5-IMP-03-S06 passed.'
) 'aggregate runner'

Invoke-Checked $php (@($phpArgs) + @((Join-Path $root 'tests\Contract\report-export-integration-freeze-g5-imp-03-s06.php')))

Write-Output 'G5-IMP-03-S06 passed: S01-S05 export lineage, all four streams, current authorization, metadata identity, checksum, byte size, expiry, and tamper blocking are frozen.'
