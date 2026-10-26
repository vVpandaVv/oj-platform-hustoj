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
    'src\Submission\Assessment\Application\Release_Submission_Result.php'
    'src\Submission\Assessment\Application\Release_Submission_Result_Command.php'
    'src\Submission\Assessment\Application\Submission_Result_Release_Result.php'
    'src\Submission\Assessment\Application\Submission_Evaluation_Failure.php'
    'src\Submission\Assessment\Domain\Submission_Result_Projection.php'
    'src\Submission\Assessment\Domain\Submission_Result_Projection_Builder.php'
    'src\Submission\Assessment\Port\Submission_Result_Release_Repository_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\Submission_Evaluation_Object_Authorization.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Release_Repository.php'
    'src\Submission\Assessment\Presentation\Submission_Result_Release_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Submission_Result_Release_Rest_Module.php'
    'tests\Contract\submission-result-release-g4-imp-06-s01.php'
    'tests\Contract\submission-result-release-storage-g4-imp-06-s01.php'
    'tests\Contract\submission-result-release-rest-g4-imp-06-s01.php'
)

foreach ($file in $phpFiles) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) { throw "G4-IMP-06-S01 runtime contains forbidden boundary marker: $forbidden" }
}
if ($runtimeSource -match '(?i)\b(?:UPDATE|DELETE)\s+(?:FROM\s+)?oj_ai_submission_release_revisions\b') {
    throw 'G4-IMP-06-S01 release revision history is not append-only.'
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G4-IMP-06-S01 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Release_Repository.php' @(
    "private const TABLE_RELEASES    = 'oj_ai_submission_release_revisions'"
    "private const TABLE_AUDIT       = 'oj_ai_audit_events'"
    "private const TABLE_OUTBOX      = 'oj_ai_outbox_events'"
    'START TRANSACTION'
    'FOR UPDATE'
    'assert_locked_source('
    'insert_release('
    'assert_postcondition('
    "array( 'evaluation_id' => `$result->evaluation_id(), 'release_id' => `$result->release_id(), 'release_path' => `$result->release_path(), 'request_digest' => `$record['request_digest'], )"
    'current_release_public_id = %s, current_release_hash = %s, lock_version = lock_version + 1'
) 'atomic release repository'

Assert-Markers 'src\Submission\Assessment\Domain\Submission_Result_Projection.php' @(
    "public const SCHEMA = 'oj_ai.student_release_projection'"
    'public const VERSION = 1'
    "public const SCHEMA_VERSION = '1.0.0'"
) 'projection profile'

Assert-Markers 'src\Submission\Assessment\Domain\Submission_Result_Projection_Builder.php' @(
    "public const SCORE_VISIBILITIES = array( 'total', 'total_and_criteria', 'hidden' )"
    "public const FEEDBACK_VISIBILITIES = array( 'shown', 'hidden' )"
    "'hidden' === `$score_visibility && 'hidden' === `$feedback_visibility"
    "'criteria' => 'total_and_criteria' === `$score_visibility ? `$score_items : array()"
    "'worksheet' => array( 'title' => trim( `$worksheet_title ) )"
    "'answer', 'answer_key', 'binding_key', 'confidence'"
    "'provider_key', 'reason', 'reason_code', 'reason_note'"
    "'rubric', 'safety_flags', 'student_note', 'teacher_memo'"
) 'student-safe allowlist projection'

Assert-Markers 'src\Submission\Assessment\Presentation\Submission_Result_Release_Rest_Controller.php' @(
    "public const ROUTE_RELEASE = '/submission-evaluations/"
    "public const ACTION_RELEASE = 'submission.result.release'"
    'Access_Policy_Request::SHELL_CONSOLE'
    'Addon_Capabilities::PUBLISH_FEEDBACK'
    'Context_Scope::OPERATION_EVALUATE'
    "new Object_Scope( Object_Scope::TYPE_EVALUATION, `$id, 'release' )"
    "'path' => `$result->release_path()"
    '201'
) 'C43 REST and authorization'

Assert-Markers 'src\Core\Composition_Root.php' @(
    'new WordPress_Submission_Result_Release_Repository'
    'new Submission_Result_Projection_Builder'
    'new Submission_Result_Release_Rest_Controller'
    'new Submission_Result_Release_Rest_Module'
) 'Composition Root registration'

Assert-Markers 'tests\Contract\submission-result-release-g4-imp-06-s01.php' @(
    'Student release projection leaked forbidden data'
    'Replaced official review must block C43.'
    'C43 frozen route, capability, and object action must remain explicit.'
) 'application contract'

Assert-Markers 'tests\Contract\submission-result-release-storage-g4-imp-06-s01.php' @(
    "array( 'START', 'INSERT:oj_ai_submission_release_revisions', 'UPDATE_POINTER', 'INSERT:oj_ai_audit_events', 'INSERT:oj_ai_outbox_events', 'COMMIT' )"
    "array( 'evaluation_id', 'release_id', 'release_path', 'request_digest' )"
    'C43 pointer race must roll back the transaction.'
    'C43 release history must remain append-only.'
) 'storage contract'

Assert-Markers 'tests\Contract\submission-result-release-rest-g4-imp-06-s01.php' @(
    'C43 command response leaked student result body'
    'Unknown C43 payload field must fail.'
    'Permission revocation must deny C43 before application execution.'
) 'REST contract'

Assert-Markers 'docs\ai-assessment\g4-imp-06-s01-student-result-release.html' @(
    'G4-IMP-06-S01 C43'
    'oj_ai.student_release_projection'
    'API-AUD-2026-08-24-R74-OBSERVED'
    'G4-IMP-06-S02 Q33'
) 'implementation evidence HTML'

Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g4-imp-06-s01-student-result-release.html'
    'g4-imp-06-s02-student-result-query.html'
    'g4-imp-06-s03-student-result-withdrawal.html'
    'g4-imp-06-s05-student-result-ui-integration.html'
    'IMP-06 5/5 완료'
    'G4-IMP-06-S05 완료'
) 'implementation roadmap'

Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'id="r74-observation"'
    'API-AUD-2026-08-24-R74-OBSERVED'
    'check:submission-result-release-g4'
    'oj_ai_submission_release_revisions'
) 'platform API audit'

foreach ($test in @(
    'submission-result-release-g4-imp-06-s01.php'
    'submission-result-release-storage-g4-imp-06-s01.php'
    'submission-result-release-rest-g4-imp-06-s01.php'
)) {
    & $php (Join-Path $root ('tests\Contract\' + $test))
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-06-S01 passed: C43 exact official source, approved feedback, student-safe projection, atomic release pointer, body-free outbox, Console authorization, durable replay, and append-only history are frozen.'
