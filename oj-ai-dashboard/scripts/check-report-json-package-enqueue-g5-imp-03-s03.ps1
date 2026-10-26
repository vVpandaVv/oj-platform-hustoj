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
    foreach ($extension in @('intl', 'mbstring')) {
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
            throw "G5-IMP-03-S03 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-report-export-enqueue-g5-imp-03-s01.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Reporting\ReportExport\Application\Enqueue_Report_Export.php'
    'src\Reporting\ReportExport\Application\Enqueue_Report_Export_Command.php'
    'src\Reporting\ReportExport\Application\Enqueue_Report_Export_Result.php'
    'src\Reporting\ReportExport\Application\Report_Export_Failure.php'
    'src\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot.php'
    'src\Reporting\ReportExport\Port\Report_Export_Queue_Wakeup_Port_Interface.php'
    'src\Reporting\ReportExport\Port\Report_Export_Repository_Port_Interface.php'
    'src\Reporting\ReportExport\Infrastructure\WordPress_Report_Export_Queue_Wakeup.php'
    'src\Reporting\ReportExport\Infrastructure\WordPress_Report_Export_Repository.php'
    'src\Reporting\ReportExport\Presentation\Report_Export_Rest_Controller.php'
    'src\Reporting\ReportExport\Presentation\Report_Export_Rest_Module.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\report-export-enqueue-g5-imp-03-s01.php'
    'tests\Contract\report-json-package-enqueue-g5-imp-03-s03.php'
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
        throw "G5-IMP-03-S03 contains a forbidden boundary marker: $forbidden"
    }
}

Assert-Markers 'src\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot.php' @(
    "SCHEMA = 'oj_ai.report_export_request'"
    "VERSION = '1.0.0'"
    "JSON_PACKAGE_PROFILE = 'report_json_package.v1'"
    "'report_json_package' => self::JSON_PACKAGE_PROFILE"
    'MAXIMUM_ROWS = 50_000'
    'MAXIMUM_BYTES = 52_428_800'
    'source_manifest_hash()'
    'Report_Snapshot_Profile::IDENTITY_SCHEMA'
) 'immutable JSON package request'
Assert-Markers 'src\Reporting\ReportExport\Presentation\Report_Export_Rest_Controller.php' @(
    'ROUTE_JSON_PACKAGE'
    '/commands/export-json-package'
    'ACTION_JSON_PACKAGE'
    'report.export.json_package.enqueue'
    'enqueue_json_package'
    "'report_json_package'"
    'Addon_Capabilities::EXPORT_REPORTS'
    'Context_Scope::OPERATION_REPORT'
    'Object_Scope::TYPE_REPORT'
    'expected_snapshot_hash'
) 'C69 REST policy'
Assert-Markers 'src\Reporting\ReportExport\Infrastructure\WordPress_Report_Export_Repository.php' @(
    "TABLE_JOBS = 'oj_ai_export_jobs'"
    "TABLE_REPORTS = 'oj_ai_report_snapshots'"
    "TABLE_AUDIT = 'oj_ai_audit_events'"
    "TABLE_OUTBOX = 'oj_ai_outbox_events'"
    "'report_snapshot_id' => `$report_snapshot_pk"
    "'source_type' => 'report_snapshot'"
    'START TRANSACTION'
    'FOR UPDATE'
    'active_identity_key'
) 'literal atomic persistence'
Assert-Markers 'tests\Contract\report-json-package-enqueue-g5-imp-03-s03.php' @(
    'C69 canonical manifest bytes drifted before enqueue.'
    'C69 exported after one source worksheet permission was revoked.'
    'C69 accepted a reused Idempotency-Key with a different command digest.'
    'C69 rollback left a partial export job.'
    'G5-IMP-03-S03 passed:'
) 'vertical contract'
Assert-Markers 'docs\ai-assessment\g5-imp-03-s03-report-json-package-enqueue.html' @(
    'G5-IMP-03-S03'
    'IMP-03 3/6'
    'report_json_package.v1'
    'manifest.json'
    'report.json'
    'checksums.sha256'
    'check:report-json-package-enqueue-g5'
    'Platform API change: 0'
    'G5-IMP-03-S04'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-03-s03-report-json-package-enqueue.html'
    'G5-IMP-03-S03'
    'g5-imp-03-s04-report-export-job-download.html'
    'G5-IMP-03-S04'
    'Q23'
    'Q24'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:report-json-package-enqueue-g5'
    'check-report-json-package-enqueue-g5-imp-03-s03.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-report-json-package-enqueue-g5-imp-03-s03.ps1'
    'Report JSON package enqueue C69 G5-IMP-03-S03 passed.'
) 'aggregate runner'

Invoke-Checked $php (@($phpArgs) + @((Join-Path $root 'tests\Contract\report-json-package-enqueue-g5-imp-03-s03.php')))

Write-Output 'G5-IMP-03-S03 passed: C69 exact snapshot JSON package profile, canonical manifest linkage, current authorization, durable idempotency, report FK, and atomic enqueue are frozen.'
