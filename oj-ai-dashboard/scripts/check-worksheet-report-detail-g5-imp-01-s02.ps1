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

$phpFiles = @(
    'src\Reporting\Report\Application\Report_Query_Failure.php'
    'src\Reporting\Report\Application\Read_Worksheet_Report_Detail.php'
    'src\Reporting\Report\Domain\Report_Metrics.php'
    'src\Reporting\Report\Domain\Worksheet_Report_Detail_Projector.php'
    'src\Reporting\Report\Domain\Worksheet_Report_Projector.php'
    'src\Reporting\Report\Port\Assignment_Report_Detail_Source_Query_Port_Interface.php'
    'src\Reporting\Report\Port\Official_Assessment_Detail_Source_Query_Port_Interface.php'
    'src\Reporting\Report\Presentation\Worksheet_Report_Detail_Rest_Controller.php'
    'src\Reporting\Report\Presentation\Worksheet_Report_Detail_Rest_Module.php'
    'src\Submission\Assignment\Infrastructure\WordPress_Assignment_Report_Detail_Source_Query.php'
    'src\Submission\Assessment\Infrastructure\Official_Assessment_Report_Detail_Source_Query.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\worksheet-report-detail-g5-imp-01-s02.php'
)
foreach ($file in $phpFiles) {
    & $php @phpArgs -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) { throw "G5-IMP-01-S02 contains a forbidden boundary marker: $forbidden" }
}

$assignmentSource = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assignment\Infrastructure\WordPress_Assignment_Report_Detail_Source_Query.php') -Raw
foreach ($forbidden in @('oj_ai_worksheets', 'oj_ai_worksheet_publications', 'oj_ai_submission_evaluation_results', 'oj_ai_submission_evaluation_reviews', 'oj_ai_submission_release_revisions')) {
    if ($assignmentSource.Contains($forbidden)) { throw "Q21 Assignment detail source crossed its owner boundary: $forbidden" }
}
$assessmentSource = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Infrastructure\Official_Assessment_Report_Detail_Source_Query.php') -Raw
foreach ($forbidden in @('oj_ai_assignments', 'oj_ai_assignment_targets', 'oj_ai_submissions', 'oj_ai_worksheets', 'oj_ai_worksheet_publications')) {
    if ($assessmentSource.Contains($forbidden)) { throw "Q21 Assessment bridge crossed its owner boundary: $forbidden" }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G5-IMP-01-S02 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Reporting\Report\Presentation\Worksheet_Report_Detail_Rest_Controller.php' @(
    "ROUTE_DETAIL = '/reports/worksheets/"
    'Addon_Capabilities::VIEW_REPORTS'
    'Access_Policy_Request::SHELL_CONSOLE'
    'Context_Scope::OPERATION_REPORT'
    '$this->permissions->take( $request )'
    "'publication_id'"
    "'publication_hash'"
) 'Q21 authorization'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'new Worksheet_Report_Detail_Rest_Controller('
    'new Worksheet_Report_Detail_Rest_Module( $report_detail_rest )'
) 'composition root'
Assert-Markers 'docs\ai-assessment\g5-imp-01-s02-worksheet-report-detail.html' @(
    'G5-IMP-01-S02'
    'GET /oj-ai/v1/reports/worksheets/{worksheet_id}'
    'oj_ai.reporting_metrics@1.0.0'
    'check:worksheet-report-detail-g5'
    '플랫폼 API 변경 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-01-s02-worksheet-report-detail.html'
    'G5-IMP-01-S03'
) 'implementation roadmap'

& $php @phpArgs (Join-Path $root 'tests\Contract\worksheet-report-list-g5-imp-01-s01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php @phpArgs (Join-Path $root 'tests\Contract\worksheet-report-detail-g5-imp-01-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G5-IMP-01-S02 passed: Q21 assignment/student drill-down, shared metrics, exact sources, signed cursors, and teacher-only identity rows are frozen.'
