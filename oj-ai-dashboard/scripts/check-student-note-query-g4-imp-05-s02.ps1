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
    'src\Security\Policy\Object_Scope.php'
    'src\Submission\Assessment\Application\Read_Student_Notes.php'
    'src\Submission\Assessment\Domain\Student_Note_Output_Normalizer.php'
    'src\Submission\Assessment\Domain\Student_Note_Query_Projector.php'
    'src\Submission\Assessment\Port\Student_Note_Query_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\Student_Note_Object_Authorization.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Query.php'
    'src\Submission\Assessment\Presentation\Student_Note_Query_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Query_Rest_Module.php'
    'tests\Contract\student-note-query-g4-imp-05-s02.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $files | Where-Object { $_ -like 'src\*' }
$sourceText = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($sourceText.Contains($forbidden)) { throw "G4-IMP-05-S02 runtime contains forbidden boundary marker: $forbidden" }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G4-IMP-05-S02 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Submission\Assessment\Presentation\Student_Note_Query_Rest_Controller.php' @(
    "ROUTE_NOTES = '/student-notes'"
    "ROUTE_NOTE = '/student-notes/(?P<note_id>"
    'Addon_Capabilities::MANAGE_STUDENT_NOTES'
    'Context_Scope::OPERATION_REPORT'
    'Object_Scope::TYPE_STUDENT_NOTE'
    "'student_visible' => false"
    "'report_auto_inclusion' => false"
) 'REST controller'

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Query.php' @(
    "private const TABLE_NOTES       = 'oj_ai_student_note_revisions'"
    "private const TABLE_AUDIT       = 'oj_ai_audit_events'"
    "private const CURSOR_KIND = 'q18'"
    "array( 'f', 'i', 't' )"
    'note.created_at_utc < %s'
    'ORDER BY note.created_at_utc DESC, note.student_note_public_id ASC'
) 'literal query adapter'

Assert-Markers 'src\Submission\Assessment\Domain\Student_Note_Query_Projector.php' @(
    'validate_stored_profile'
    "'official_review_hash'"
    "'evaluation_run_id'"
    "'student_note_revision_id'"
    'FORBIDDEN_AUDIT_KEYS'
) 'safe projector'

Assert-Markers 'src\Core\Composition_Root.php' @(
    'new WordPress_Student_Note_Query('
    'new Student_Note_Object_Authorization('
    'new Student_Note_Query_Rest_Module('
) 'composition root'

& $php (Join-Path $root 'tests\Contract\student-note-draft-g4-imp-05-s01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\student-note-query-g4-imp-05-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-05-S02 passed: Q18/Q19 teacher-only safe projection, revision evidence, provenance, audit history, signed cursor, literal tables, and zero student routes are frozen.'
