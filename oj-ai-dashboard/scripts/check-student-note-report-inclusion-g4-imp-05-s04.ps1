param([switch]$SkipConstituentChecks)

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

$files = @(
    'src\Core\Composition_Root.php'
    'src\Submission\Assessment\Application\Change_Student_Note_Report_Inclusion.php'
    'src\Submission\Assessment\Application\Change_Student_Note_Report_Inclusion_Command.php'
    'src\Submission\Assessment\Application\Student_Note_Revision_Result.php'
    'src\Submission\Assessment\Port\Student_Note_Report_Inclusion_Repository_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\Student_Note_Object_Authorization.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Review_Repository.php'
    'src\Submission\Assessment\Presentation\Student_Note_Report_Inclusion_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Report_Inclusion_Rest_Module.php'
    'tests\Contract\student-note-report-inclusion-g4-imp-05-s04.php'
    'tests\Contract\student-note-report-inclusion-storage-g4-imp-05-s04.php'
    'tests\Contract\student-note-report-inclusion-rest-g4-imp-05-s04.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $files | Where-Object { $_ -like 'src\*' }
$sourceText = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($sourceText.Contains($forbidden)) { throw "G4-IMP-05-S04 runtime contains forbidden boundary marker: $forbidden" }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G4-IMP-05-S04 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Submission\Assessment\Application\Change_Student_Note_Report_Inclusion_Command.php' @(
    "'approved' !== `$expected_state"
    '$expected_include_in_reports === $target_include_in_reports'
    "'student_note.include_in_reports'"
    "'student_note.exclude_from_reports'"
    "'payload' => array()"
) 'exact command'

Assert-Markers 'src\Submission\Assessment\Application\Change_Student_Note_Report_Inclusion.php' @(
    'find_report_inclusion_receipt('
    'find_owned_report_inclusion_snapshot('
    "'include_in_reports' => `$target"
    "'source_mode' => `$target ? 'include_in_reports' : 'exclude_from_reports'"
    'validate_stored_profile('
) 'application service'

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Review_Repository.php' @(
    "private const TABLE_NOTES       = 'oj_ai_student_note_revisions'"
    "private const INCLUDE_EVENT  = 'student_note.included_in_reports'"
    "private const EXCLUDE_EVENT  = 'student_note.excluded_from_reports'"
    "'include_in_reports' => `$record['include_in_reports'] ? 1 : 0"
    "`$approved_by = `$snapshot['latest_approved_by']"
    "`$approved_at = `$snapshot['latest_approved_at']"
    'START TRANSACTION'
    'FOR UPDATE'
) 'literal immutable repository'

Assert-Markers 'src\Submission\Assessment\Presentation\Student_Note_Report_Inclusion_Rest_Controller.php' @(
    "ROUTE_INCLUDE = '/student-notes/(?P<note_id>"
    "ROUTE_EXCLUDE = '/student-notes/(?P<note_id>"
    "ACTION_INCLUDE = 'student_note.include_in_reports'"
    "ACTION_EXCLUDE = 'student_note.exclude_from_reports'"
    'Addon_Capabilities::MANAGE_STUDENT_NOTES'
    'Context_Scope::OPERATION_REPORT'
    "`$target ? 'include_in_reports' : 'exclude_from_reports'"
    'array() !== $payload'
) 'C41/C42 REST controller'

Assert-Markers 'src\Submission\Assessment\Infrastructure\Student_Note_Object_Authorization.php' @(
    "'include_in_reports'"
    "'exclude_from_reports'"
) 'object authorization'

Assert-Markers 'src\Core\Composition_Root.php' @(
    'new Change_Student_Note_Report_Inclusion('
    'new Student_Note_Report_Inclusion_Rest_Controller('
    'new Student_Note_Report_Inclusion_Rest_Module('
) 'composition root'

if (-not $SkipConstituentChecks) {
    & (Join-Path $PSScriptRoot 'check-student-note-review-approval-g4-imp-05-s03.ps1')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
foreach ($test in @(
    'student-note-report-inclusion-g4-imp-05-s04.php'
    'student-note-report-inclusion-storage-g4-imp-05-s04.php'
    'student-note-report-inclusion-rest-g4-imp-05-s04.php'
)) {
    & $php (Join-Path $root ('tests\Contract\' + $test))
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-05-S04 passed: C41/C42 strict DTO, educator-only authorization, immutable report inclusion toggle, approval metadata preservation, replay, privacy, and rollback are frozen.'
