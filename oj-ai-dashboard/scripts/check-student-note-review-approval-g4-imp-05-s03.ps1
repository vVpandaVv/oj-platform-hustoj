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
    'src\Submission\Assessment\Application\Approve_Student_Note.php'
    'src\Submission\Assessment\Application\Approve_Student_Note_Command.php'
    'src\Submission\Assessment\Application\Review_Student_Note.php'
    'src\Submission\Assessment\Application\Review_Student_Note_Command.php'
    'src\Submission\Assessment\Application\Student_Note_Revision_Result.php'
    'src\Submission\Assessment\Port\Student_Note_Approval_Repository_Port_Interface.php'
    'src\Submission\Assessment\Port\Student_Note_Review_Repository_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\Student_Note_Object_Authorization.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Query.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Review_Repository.php'
    'src\Submission\Assessment\Presentation\Student_Note_Approval_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Approval_Rest_Module.php'
    'src\Submission\Assessment\Presentation\Student_Note_Review_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Review_Rest_Module.php'
    'tests\Contract\student-note-review-approval-g4-imp-05-s03.php'
    'tests\Contract\student-note-review-approval-storage-g4-imp-05-s03.php'
    'tests\Contract\student-note-review-approval-rest-g4-imp-05-s03.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $files | Where-Object { $_ -like 'src\*' }
$sourceText = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($sourceText.Contains($forbidden)) { throw "G4-IMP-05-S03 runtime contains forbidden boundary marker: $forbidden" }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G4-IMP-05-S03 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Submission\Assessment\Presentation\Student_Note_Review_Rest_Controller.php' @(
    "ROUTE_REVIEW = '/student-notes/(?P<note_id>"
    "ACTION_REVIEW = 'student_note.review'"
    'Addon_Capabilities::MANAGE_STUDENT_NOTES'
    'Context_Scope::OPERATION_REPORT'
    "Object_Scope::TYPE_STUDENT_NOTE, `$note_id, 'review'"
    "'expected_include_in_reports'"
) 'C39 REST controller'

Assert-Markers 'src\Submission\Assessment\Presentation\Student_Note_Approval_Rest_Controller.php' @(
    "ROUTE_APPROVE = '/student-notes/(?P<note_id>"
    "ACTION_APPROVE = 'student_note.approve'"
    'array() !== $payload'
    "Object_Scope::TYPE_STUDENT_NOTE, `$note_id, 'approve'"
) 'C40 REST controller'

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Review_Repository.php' @(
    "private const TABLE_NOTES       = 'oj_ai_student_note_revisions'"
    "private const REVIEW_EVENT   = 'student_note.reviewed'"
    "private const APPROVAL_EVENT = 'student_note.approved'"
    "private const AUDIT_SCHEMA   = 'oj_ai.student_note_audit'"
    'self::OP_APPROVAL'
    "'approved_by_user_id' => `$approved_by"
    "'include_in_reports' => `$record['include_in_reports'] ? 1 : 0"
) 'literal immutable repository'

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Query.php' @(
    'NOT EXISTS '
    "'current_revision' => `$official_review_hash"
    "'state' => 'reviewed'"
) 'initial object authorization snapshot'

Assert-Markers 'src\Core\Composition_Root.php' @(
    'new WordPress_Student_Note_Review_Repository('
    'new Student_Note_Review_Rest_Module('
    'new Student_Note_Approval_Rest_Module('
) 'composition root'

& $php (Join-Path $root 'tests\Contract\student-note-query-g4-imp-05-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
foreach ($test in @(
    'student-note-review-approval-g4-imp-05-s03.php'
    'student-note-review-approval-storage-g4-imp-05-s03.php'
    'student-note-review-approval-rest-g4-imp-05-s03.php'
)) {
    & $php (Join-Path $root ('tests\Contract\' + $test))
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-05-S03 passed: C39/C40 exact DTO, educator-only authorization, immutable revision append, safety, approval, replay, privacy, and rollback are frozen.'
