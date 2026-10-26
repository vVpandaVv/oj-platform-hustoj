$ErrorActionPreference = 'Stop'
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
    'src\Reporting\Report\Domain\Report_Filter.php'
    'src\Reporting\Report\Domain\Report_Denominator_Contract.php'
    'src\Reporting\Report\Application\Read_Worksheet_Reports.php'
    'src\Reporting\Report\Application\Read_Worksheet_Report_Detail.php'
    'src\Reporting\Report\Port\Assignment_Progress_Source_Query_Port_Interface.php'
    'src\Reporting\Report\Port\Assignment_Report_Detail_Source_Query_Port_Interface.php'
    'src\Reporting\Report\Presentation\Report_Filter_Query.php'
    'src\Reporting\Report\Presentation\Worksheet_Report_Rest_Controller.php'
    'src\Reporting\Report\Presentation\Worksheet_Report_Detail_Rest_Controller.php'
    'src\Submission\Assignment\Infrastructure\WordPress_Assignment_Progress_Report_Source_Query.php'
    'src\Submission\Assignment\Infrastructure\WordPress_Assignment_Report_Detail_Source_Query.php'
    'tests\Contract\worksheet-report-filter-denominator-g5-imp-01-s03.php'
)
foreach ($file in $phpFiles) {
    & $php @phpArgs -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) { throw "G5-IMP-01-S03 contains a forbidden boundary marker: $forbidden" }
}

foreach ($path in @(
    'src\Submission\Assignment\Infrastructure\WordPress_Assignment_Progress_Report_Source_Query.php'
    'src\Submission\Assignment\Infrastructure\WordPress_Assignment_Report_Detail_Source_Query.php'
)) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($forbidden in @('oj_ai_worksheets', 'oj_ai_worksheet_publications', 'oj_ai_submission_evaluation_results', 'oj_ai_submission_evaluation_reviews', 'oj_ai_submission_release_revisions')) {
        if ($source.Contains($forbidden)) { throw "G5-IMP-01-S03 Assignment source crossed its owner boundary: $forbidden" }
    }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not $source.Contains($marker)) { throw "G5-IMP-01-S03 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Reporting\Report\Domain\Report_Filter.php' @(
    "SCHEMA_ID = 'oj_ai.report_filter'"
    "VERSION   = '1.0.0'"
    "BOUNDARY  = '[from,to)'"
    "array( 'scheduled', 'open', 'closed' )"
    "array( 'not_started', 'in_progress', 'official' )"
) 'filter contract'
Assert-Markers 'src\Reporting\Report\Domain\Report_Denominator_Contract.php' @(
    "SCHEMA_ID = 'oj_ai.report_denominator_contract'"
    "'denominator_key'"
    "'included_reason_codes'"
    "'excluded_reason_codes'"
) 'denominator contract'
foreach ($path in @(
    'src\Submission\Assignment\Infrastructure\WordPress_Assignment_Progress_Report_Source_Query.php'
    'src\Submission\Assignment\Infrastructure\WordPress_Assignment_Report_Detail_Source_Query.php'
)) {
    Assert-Markers $path @(
        'COALESCE('
        'created_at_utc) >= %s'
        'created_at_utc) < %s'
        'submission_stage_predicate'
        'filter_payload'
    ) 'owner query'
}
foreach ($path in @(
    'src\Reporting\Report\Application\Read_Worksheet_Reports.php'
    'src\Reporting\Report\Application\Read_Worksheet_Report_Detail.php'
)) {
    Assert-Markers $path @(
        'Report_Denominator_Contract::payload()'
        '$filter->payload()'
        '$filter->available()'
    ) 'application response'
}
foreach ($path in @(
    'src\Reporting\Report\Presentation\Worksheet_Report_Rest_Controller.php'
    'src\Reporting\Report\Presentation\Worksheet_Report_Detail_Rest_Controller.php'
)) {
    Assert-Markers $path @(
        'Report_Filter_Query::KEYS'
        'Report_Filter_Query::from_params( $params )'
    ) 'REST filter'
}
Assert-Markers 'docs\ai-assessment\g5-imp-01-s03-report-filter-denominator.html' @(
    'G5-IMP-01-S03'
    'oj_ai.report_filter@1.0.0'
    'oj_ai.report_denominator_contract@1.0.0'
    '[from,to)'
    'not_started'
    'check:worksheet-report-filter-g5'
    '플랫폼 API 변경 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-01-s03-report-filter-denominator.html'
    'G5-IMP-01-S04'
) 'implementation roadmap'

foreach ($test in @(
    'tests\Contract\worksheet-report-list-g5-imp-01-s01.php'
    'tests\Contract\worksheet-report-detail-g5-imp-01-s02.php'
    'tests\Contract\worksheet-report-filter-denominator-g5-imp-01-s03.php'
)) {
    & $php @phpArgs (Join-Path $root $test)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G5-IMP-01-S03 passed: canonical period/state filters, matching denominators, exclusion reasons, and filter-bound cursors are frozen.'
