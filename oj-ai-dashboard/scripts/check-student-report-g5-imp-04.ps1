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
    'src\Reporting\Report\Application\Read_Student_Roster_Reports.php'
    'src\Reporting\Report\Application\Read_Student_Report_Detail.php'
    'src\Reporting\Report\Domain\Student_Roster_Report_Projector.php'
    'src\Reporting\Report\Domain\Student_Report_Detail_Projector.php'
    'src\Reporting\Report\Domain\Report_Metrics.php'
    'src\Reporting\Report\Port\Student_Roster_Report_Source_Query_Port_Interface.php'
    'src\Reporting\Report\Port\Student_Report_Detail_Source_Query_Port_Interface.php'
    'src\Reporting\Report\Port\Official_Assessment_Score_Lookup_Port_Interface.php'
    'src\Reporting\Report\Presentation\Student_Roster_Rest_Controller.php'
    'src\Reporting\Report\Presentation\Student_Roster_Rest_Module.php'
    'src\Reporting\Report\Presentation\Student_Report_Detail_Rest_Controller.php'
    'src\Reporting\Report\Presentation\Student_Report_Detail_Rest_Module.php'
    'src\Submission\Assignment\Infrastructure\WordPress_Student_Roster_Report_Source_Query.php'
    'src\Submission\Assignment\Infrastructure\WordPress_Student_Report_Detail_Source_Query.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Official_Assessment_Score_Lookup_Query.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\student-report-list-g5-imp-04-s01.php'
    'tests\Contract\student-report-detail-g5-imp-04-s02.php'
)
foreach ($file in $phpFiles) {
    & $php @phpArgs -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not $source.Contains($marker)) { throw "G5-IMP-04 $label marker is missing: $marker" }
    }
}
Assert-Markers 'src\Core\Composition_Root.php' @(
    'new Student_Roster_Rest_Controller('
    'new Student_Roster_Rest_Module( $student_roster_rest )'
    'new Student_Report_Detail_Rest_Controller('
    'new Student_Report_Detail_Rest_Module( $student_report_detail_rest )'
) 'composition root'

& $php @phpArgs (Join-Path $root 'tests\Contract\student-report-list-g5-imp-04-s01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php @phpArgs (Join-Path $root 'tests\Contract\student-report-detail-g5-imp-04-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G5-IMP-04 passed: student-lens roster and cross-worksheet detail sources, signed cursors, and REST wiring are frozen.'
