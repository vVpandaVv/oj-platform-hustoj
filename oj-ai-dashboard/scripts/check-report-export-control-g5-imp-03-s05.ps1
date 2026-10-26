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
            throw "G5-IMP-03-S05 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-report-export-job-download-g5-imp-03-s04.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Reporting\Export\Application\Cancel_Export_Job.php'
    'src\Reporting\Export\Application\Cancel_Export_Job_Command.php'
    'src\Reporting\Export\Application\Export_Control_Result.php'
    'src\Reporting\Export\Application\Export_Failure.php'
    'src\Reporting\Export\Application\Retry_Export_Job.php'
    'src\Reporting\Export\Application\Retry_Export_Job_Command.php'
    'src\Reporting\Export\Port\Export_Control_Queue_Wakeup_Port_Interface.php'
    'src\Reporting\Export\Port\Export_Control_Repository_Port_Interface.php'
    'src\Reporting\Export\Infrastructure\WordPress_Export_Control_Queue_Wakeup.php'
    'src\Reporting\Export\Infrastructure\WordPress_Export_Control_Repository.php'
    'src\Reporting\Export\Presentation\Export_Control_Rest_Controller.php'
    'src\Reporting\Export\Presentation\Export_Control_Rest_Module.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\report-export-control-g5-imp-03-s05.php'
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
        throw "G5-IMP-03-S05 contains a forbidden boundary marker: $forbidden"
    }
}

Assert-Markers 'src\Reporting\Export\Infrastructure\WordPress_Export_Control_Repository.php' @(
    "TABLE_JOBS   = 'oj_ai_export_jobs'"
    "TABLE_AUDIT  = 'oj_ai_audit_events'"
    "TABLE_OUTBOX = 'oj_ai_outbox_events'"
    'START TRANSACTION'
    'FOR UPDATE'
    'export_job.cancelled'
    'export_job.retry_queued'
    'export_job.retry_reused'
    'RETRYABLE_ERRORS'
    'active_identity_key'
) 'atomic control repository'
Assert-Markers 'src\Reporting\Export\Presentation\Export_Control_Rest_Controller.php' @(
    '/commands/cancel'
    '/commands/retry'
    "ACTION_CANCEL = 'export.job.cancel'"
    "ACTION_RETRY = 'export.job.retry'"
    'expected_error_code'
    'expected_source_hash'
) 'strict C53 C54 REST'
Assert-Markers 'src\Reporting\Export\Infrastructure\WordPress_Export_Control_Queue_Wakeup.php' @(
    'oj_ai_dashboard_worksheet_pdf_worker'
    'oj_ai_dashboard_worksheet_package_worker'
    'oj_ai_dashboard_submission_pdf_worker'
    'oj_ai_dashboard_result_pdf_worker'
    'oj_ai_dashboard_report_export_worker'
    'oj_ai_dashboard_submission_bundle_worker'
) 'worker routing'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'WordPress_Export_Control_Repository'
    'new Export_Control_Rest_Controller'
    'new Export_Control_Rest_Module'
) 'composition'
Assert-Markers 'tests\Contract\report-export-control-g5-imp-03-s05.php' @(
    'C53 did not cancel a queued export.'
    'C54 did not create a queued retry.'
    'C54 did not reuse the existing semantic job.'
    'C54 rollback left a partial retry job.'
    'G5-IMP-03-S05 passed:'
) 'vertical contract'
Assert-Markers 'docs\ai-assessment\g5-imp-03-s05-report-export-control.html' @(
    'G5-IMP-03-S05'
    'IMP-03 5/6'
    'C53'
    'C54'
    'check:report-export-control-g5'
    'Platform API change: 0'
    'G5-IMP-03-S06'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-03-s05-report-export-control.html'
    'G5-IMP-03-S05'
    'queued|running'
    'check:report-export-control-g5'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:report-export-control-g5'
    'check-report-export-control-g5-imp-03-s05.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-report-export-control-g5-imp-03-s05.ps1'
    'Export cancel C53 and retry C54 G5-IMP-03-S05 passed.'
) 'aggregate runner'

Invoke-Checked $php (@($phpArgs) + @((Join-Path $root 'tests\Contract\report-export-control-g5-imp-03-s05.php')))

Write-Output 'G5-IMP-03-S05 passed: queued-only cancel, retryable failed-job append, semantic reuse, durable idempotency, atomic audit/outbox, worker routing, and no-prefix boundaries are frozen.'
