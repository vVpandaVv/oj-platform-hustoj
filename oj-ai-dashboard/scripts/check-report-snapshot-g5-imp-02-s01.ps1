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

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-worksheet-report-filter-denominator-g5-imp-01-s03.ps1')
    )
}

$phpFiles = @(
    'src\Reporting\Report\Application\Create_Report_Snapshot.php'
    'src\Reporting\Report\Application\Create_Report_Snapshot_Command.php'
    'src\Reporting\Report\Application\Create_Report_Snapshot_Result.php'
    'src\Reporting\Report\Application\Report_Snapshot_Failure.php'
    'src\Reporting\Report\Domain\Report_Snapshot_Profile.php'
    'src\Reporting\Report\Infrastructure\WordPress_Report_Snapshot_Repository.php'
    'src\Reporting\Report\Port\Report_Snapshot_Repository_Port_Interface.php'
    'src\Reporting\Report\Presentation\Report_Snapshot_Rest_Controller.php'
    'src\Reporting\Report\Presentation\Report_Snapshot_Rest_Module.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\report-snapshot-create-g5-imp-02-s01.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php (@($phpArgs) + @('-l', (Join-Path $root $file)))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) {
        throw "G5-IMP-02-S01 contains a forbidden boundary marker: $forbidden"
    }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) {
            throw "G5-IMP-02-S01 $label marker is missing: $marker"
        }
    }
}

Assert-Markers 'src\Reporting\Report\Application\Create_Report_Snapshot.php' @(
    'MAXIMUM_ROWS = 10000'
    '$first = $this->collect( $command )'
    '$second = $this->collect( $command )'
    'expected_live_source_manifest_hash()'
    'SOURCE_STALE'
) 'two-pass source freeze'
Assert-Markers 'src\Reporting\Report\Domain\Report_Snapshot_Profile.php' @(
    "'oj_ai.report_source_manifest'"
    "'oj_ai.report_projection'"
    "'oj_ai.report_snapshot_identity'"
    'Worksheet_Report_Projector::METRIC_CONTRACT'
) 'canonical profiles'
Assert-Markers 'src\Reporting\Report\Infrastructure\WordPress_Report_Snapshot_Repository.php' @(
    "'oj_ai_report_snapshots'"
    "'oj_ai_audit_events'"
    "'oj_ai_outbox_events'"
    "'report.snapshot.created'"
    'START TRANSACTION'
    'ROLLBACK'
    'Report_Snapshot_Failure::STORAGE_UNAVAILABLE'
    'recover_receipt'
) 'literal persistence'
$repository = Get-Content -LiteralPath (Join-Path $root 'src\Reporting\Report\Infrastructure\WordPress_Report_Snapshot_Repository.php') -Raw
if ($repository.Contains('UPDATE ' + 'oj_ai_report_snapshots')) {
    throw 'C45 repository must not update immutable report snapshot rows.'
}
Assert-Markers 'src\Reporting\Report\Presentation\Report_Snapshot_Rest_Controller.php' @(
    "ROUTE_CREATE = '/reports/commands/create-snapshot'"
    "ACTION_CREATE = 'report.snapshot.create'"
    'Addon_Capabilities::VIEW_REPORTS'
    'Access_Policy_Request::SHELL_CONSOLE'
    'Context_Scope::OPERATION_REPORT'
    'idempotency_key()'
    'expected_request_hash'
) 'C45 REST policy'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'new Report_Snapshot_Rest_Controller('
    'new WordPress_Report_Snapshot_Repository( $canonical )'
    'new Report_Snapshot_Rest_Module( $report_snapshot_rest )'
) 'composition root'
Assert-Markers 'src\Database\Foundation\Schema_Catalog.php' @(
    "'oj_ai_report_snapshots'"
    "'source_manifest_schema'"
    "'source_manifest_hash'"
) 'frozen schema'
Assert-Markers 'docs\ai-assessment\g5-imp-02-s01-report-snapshot-create.html' @(
    'G5-IMP-02-S01'
    'POST /oj-ai/v1/reports/commands/create-snapshot'
    'oj_ai.report_snapshot_request@1.0.0'
    'oj_ai_report_snapshots'
    'check:report-snapshot-g5'
    '플랫폼 API 변경 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-02-s01-report-snapshot-create.html'
    'G5-IMP-02-S02'
) 'implementation roadmap'
Assert-Markers 'tests\Contract\report-snapshot-create-g5-imp-02-s01.php' @(
    'race_outbox_insert'
    'A concurrent exact C45 winner must be returned as a durable replay.'
    'Concurrent changed C45 winner must return idempotency conflict.'
) 'concurrent idempotency recovery'

Invoke-Checked $php (@($phpArgs) + @((Join-Path $root 'tests\Contract\report-snapshot-create-g5-imp-02-s01.php')))

Write-Output 'G5-IMP-02-S01 passed: C45 canonical request, two-pass source freeze, immutable literal-table transaction, durable replay and rollback are frozen.'
