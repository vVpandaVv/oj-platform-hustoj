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
        (Join-Path $PSScriptRoot 'check-report-snapshot-g5-imp-02-s01.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Reporting\Report\Application\Read_Report_Snapshot.php'
    'src\Reporting\Report\Application\Read_Report_Snapshot_Result.php'
    'src\Reporting\Report\Application\Report_Snapshot_Query_Failure.php'
    'src\Reporting\Report\Domain\Report_Snapshot_Scope.php'
    'src\Reporting\Report\Infrastructure\Report_Snapshot_Object_Authorization.php'
    'src\Reporting\Report\Infrastructure\WordPress_Report_Snapshot_Query.php'
    'src\Reporting\Report\Port\Report_Snapshot_Query_Port_Interface.php'
    'src\Reporting\Report\Presentation\Report_Snapshot_Query_Rest_Controller.php'
    'src\Reporting\Report\Presentation\Report_Snapshot_Query_Rest_Module.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\report-snapshot-query-g5-imp-02-s02.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php (@($phpArgs) + @('-l', (Join-Path $root $file)))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) {
        throw "G5-IMP-02-S02 contains a forbidden boundary marker: $forbidden"
    }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) {
            throw "G5-IMP-02-S02 $label marker is missing: $marker"
        }
    }
}

Assert-Markers 'src\Reporting\Report\Application\Read_Report_Snapshot.php' @(
    "ACTION_READ = 'report.snapshot.read'"
    'validate_filter('
    'validate_manifest('
    'validate_projection('
    'Object_Scope::TYPE_WORKSHEET'
    'SOURCE_ACCESS_DENIED'
    'SOURCE_ACCESS_UNAVAILABLE'
) 'profile and source authorization'
Assert-Markers 'src\Reporting\Report\Infrastructure\WordPress_Report_Snapshot_Query.php' @(
    "TABLE_SNAPSHOTS = 'oj_ai_report_snapshots'"
    'source_manifest_json'
    'projection_json'
    'Report_Snapshot_Scope'
) 'literal query adapter'
Assert-Markers 'src\Reporting\Report\Infrastructure\Report_Snapshot_Object_Authorization.php' @(
    'Object_Scope::TYPE_REPORT'
    "'view'"
    'context_id() !== $context->id()'
) 'snapshot object policy'
Assert-Markers 'src\Reporting\Report\Presentation\Report_Snapshot_Query_Rest_Controller.php' @(
    "ROUTE_SHOW = '/report-snapshots/(?P<snapshot_id>[0-9A-HJKMNP-TV-Z]{26})'"
    "'methods'             => 'GET'"
    'Addon_Capabilities::VIEW_REPORTS'
    'Context_Scope::OPERATION_REPORT'
    'Object_Scope::TYPE_REPORT'
    "'snapshot_immutable'"
) 'Q22 REST policy'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'new WordPress_Report_Snapshot_Query( $canonical )'
    'new Report_Snapshot_Object_Authorization( $report_snapshot_queries )'
    'new Report_Snapshot_Query_Rest_Controller('
    'new Report_Snapshot_Query_Rest_Module( $report_snapshot_query_rest )'
) 'composition root'
Assert-Markers 'docs\ai-assessment\g5-imp-02-s02-report-snapshot-query.html' @(
    'G5-IMP-02-S02'
    'GET /oj-ai/v1/report-snapshots/{snapshot_id}'
    '현재 Context'
    '활동지 source'
    'check:report-snapshot-query-g5'
    '플랫폼 API 변경 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-02-s02-report-snapshot-query.html'
    'g5-imp-02-s03-report-source-manifest.html'
    'g5-imp-02-s04-report-snapshot-integration-freeze.html'
    'G5-IMP-03-S01'
    'IMP-02 4/4'
) 'implementation roadmap'
Assert-Markers 'tests\Contract\report-snapshot-query-g5-imp-02-s02.php' @(
    'Q22 disclosed a snapshot after source access was revoked.'
    'Q22 returned a projection containing an unknown private field.'
    'Q22 report object revision must be the snapshot identity hash.'
) 'security regression fixture'

Invoke-Checked $php (@($phpArgs) + @((Join-Path $root 'tests\Contract\report-snapshot-query-g5-imp-02-s02.php')))

Write-Output 'G5-IMP-02-S02 passed: Q22 canonical profiles, immutable safe projection, current Context and every worksheet source authorization are frozen.'
