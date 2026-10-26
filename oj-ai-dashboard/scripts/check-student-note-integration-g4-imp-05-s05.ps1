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

$phpFiles = @(
    'src\Core\Composition_Root.php'
    'src\Submission\Assessment\Application\Review_Student_Note.php'
    'src\Submission\Assessment\Application\Approve_Student_Note.php'
    'src\Submission\Assessment\Application\Change_Student_Note_Report_Inclusion.php'
    'src\Submission\Assessment\Domain\Student_Note_Query_Projector.php'
    'src\Submission\Assessment\Infrastructure\Student_Note_Object_Authorization.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Query.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Review_Repository.php'
    'src\Submission\Assessment\Presentation\Student_Note_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Query_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Review_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Approval_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Report_Inclusion_Rest_Controller.php'
    'tests\Contract\student-note-integration-g4-imp-05-s05.php'
    'tests\Contract\student-note-integration-storage-g4-imp-05-s05.php'
)

foreach ($file in $phpFiles) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) { throw "G4-IMP-05-S05 runtime contains forbidden boundary marker: $forbidden" }
}
if ($runtimeSource -match '(?i)\b(?:UPDATE|DELETE)\s+oj_ai_student_note_revisions\b') {
    throw 'G4-IMP-05-S05 student-note revision history is not append-only.'
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G4-IMP-05-S05 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Review_Repository.php' @(
    "private const TABLE_NOTES       = 'oj_ai_student_note_revisions'"
    'START TRANSACTION'
    'FOR UPDATE'
    'assert_locked_source('
    "`$record['expected_revision_id'] !== `$snapshot['latest_revision_id']"
    "`$record['expected_source_run_id'] !== `$snapshot['latest_source_run_id']"
    "`$approved_by = `$snapshot['latest_approved_by']"
    "`$approved_at = `$snapshot['latest_approved_at']"
) 'locked immutable repository'

Assert-Markers 'tests\Contract\student-note-integration-g4-imp-05-s05.php' @(
    "array( 'review', 'approval', 'include', 'exclude' )"
    'C39-C42 revision sequence drifted.'
    'AI run provenance drifted at integrated revision'
    'C41 must preserve the original approving educator.'
    'C42 must preserve the original approving educator.'
    'A completed C39-C42 command must replay before current-source comparison.'
    'Student-note routes must not expose a student/PublicUI endpoint.'
    'Student-note revision history must remain append-only.'
) 'application integration contract'

Assert-Markers 'tests\Contract\student-note-integration-storage-g4-imp-05-s05.php' @(
    'C39 concurrent loser must fail with student_note_source_stale.'
    'C40 concurrent loser must fail with student_note_source_stale.'
    'C41 concurrent loser must fail with student_note_source_stale.'
    'C42 concurrent loser must fail with student_note_source_stale.'
    'C42 must not rewrite historical note revisions.'
    "array( 'notes' => 4, 'audit' => 4, 'outbox' => 4, )"
    'Exact C39-C42 storage retries must use durable receipts.'
) 'storage integration contract'

Assert-Markers 'tests\Contract\student-note-query-g4-imp-05-s02.php' @(
    'student_visible'
    'report_auto_inclusion'
    'zero student routes are frozen'
) 'student non-exposure contract'

Assert-Markers 'tests\Contract\student-note-review-approval-rest-g4-imp-05-s03.php' @(
    'Permission revocation must deny C40 before execution.'
    'Revoked C40 permission must return 403.'
) 'C40 permission revocation contract'

Assert-Markers 'tests\Contract\student-note-report-inclusion-rest-g4-imp-05-s04.php' @(
    'Permission revocation must deny C42 before execution.'
    'Revoked C42 permission must return 403.'
) 'C42 permission revocation contract'

$controllerFiles = @(
    'src\Submission\Assessment\Presentation\Student_Note_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Query_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Review_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Approval_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Report_Inclusion_Rest_Controller.php'
)
foreach ($controller in $controllerFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $controller) -Raw
    if (-not (Test-OjaiPhpMarker -Source $source -Marker 'Access_Policy_Request::SHELL_CONSOLE' -Php $php)) { throw "G4-IMP-05-S05 controller is not Console-only: $controller" }
    if ($source.Contains('SHELL_PUBLIC') -or $source.Contains('/me/')) { throw "G4-IMP-05-S05 controller exposes a student/PublicUI route: $controller" }
}

if (-not $SkipConstituentChecks) {
    $constituentChecks = @(
        @{ Path = 'check-student-note-draft-g4-imp-05-s01.ps1'; Args = @() }
        @{ Path = 'check-student-note-query-g4-imp-05-s02.ps1'; Args = @() }
        @{ Path = 'check-student-note-review-approval-g4-imp-05-s03.ps1'; Args = @() }
        @{ Path = 'check-student-note-report-inclusion-g4-imp-05-s04.ps1'; Args = @('-SkipConstituentChecks') }
    )
    foreach ($check in $constituentChecks) {
        & (Join-Path $PSScriptRoot $check.Path) @($check.Args)
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
}

foreach ($test in @(
    'student-note-integration-g4-imp-05-s05.php'
    'student-note-integration-storage-g4-imp-05-s05.php'
)) {
    & $php (Join-Path $root ('tests\Contract\' + $test))
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-05-S05 passed: C38-Q18/Q19-C39/C40-C41/C42 provenance, Console-only authorization, report default exclusion, single-winner concurrency, durable replay, and immutable history are frozen.'
