param(
    [switch]$SkipConstituentChecks
)

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

if (-not $SkipConstituentChecks) {
    & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'scripts\check-submission-result-withdrawal-g4-imp-06-s03.ps1')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$phpFiles = @(
    'src\Submission\Assessment\Application\Release_Submission_Result.php'
    'src\Submission\Assessment\Application\Release_Submission_Result_Command.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Release_Repository.php'
    'src\Submission\Assessment\Presentation\Submission_Result_Release_Rest_Controller.php'
    'tests\Contract\submission-result-correction-g4-imp-06-s04.php'
    'tests\Contract\submission-result-correction-storage-g4-imp-06-s04.php'
    'tests\Contract\submission-result-correction-rest-g4-imp-06-s04.php'
)

foreach ($file in $phpFiles) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) { throw "G4-IMP-06-S04 runtime contains forbidden boundary marker: $forbidden" }
}
if ($runtimeSource -match '(?i)\b(?:UPDATE|DELETE)\s+(?:FROM\s+)?oj_ai_submission_release_revisions\b') {
    throw 'G4-IMP-06-S04 release revision history is not append-only.'
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G4-IMP-06-S04 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Submission\Assessment\Application\Release_Submission_Result_Command.php' @(
    "array( 'release', 'correction' )"
    "'correction' === `$mode"
    "`$payload['reason_code']"
    "`$payload['reason_note']"
    "'expected_current_release_id'"
    "'expected_current_release_hash'"
    "'data_correction'"
    "'privacy_request'"
) 'conditional correction command'

Assert-Markers 'src\Submission\Assessment\Application\Release_Submission_Result.php' @(
    "'correction' === `$command->mode()"
    "`$state = `$is_correction ? 'corrected' : 'released'"
    "`$previous_release_id = `$is_correction ? `$command->expected_current_release_id() : null"
    "array( 'released', 'corrected', 'withdrawn', 'stale' )"
    "'expected_current_release_id' => `$command->expected_current_release_id()"
    "'expected_current_release_hash' => `$command->expected_current_release_hash()"
    "'previous_release_id' => `$previous_release_id"
    'append_release('
) 'application correction transition'

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Release_Repository.php' @(
    "private const TABLE_RELEASES    = 'oj_ai_submission_release_revisions'"
    "private const EVENT_CORRECTED = 'submission_result.corrected'"
    'START TRANSACTION'
    'FOR UPDATE'
    'insert_release('
    'assert_postcondition('
    "'previous_release_id' => null === `$record['previous_release_id'] ? null : `$snapshot['current_release_pk']"
    'AND current_release_public_id = %s AND current_release_hash = %s'
    "'reason_code' => `$record['reason_code']"
    "array( 'evaluation_id' => `$result->evaluation_id(), 'release_id' => `$result->release_id(), 'release_path' => `$result->release_path(), 'request_digest' => `$record['request_digest'], )"
    "array( 'evaluation_id', 'release_id', 'release_path', 'request_digest' )"
) 'atomic correction repository'

Assert-Markers 'src\Submission\Assessment\Presentation\Submission_Result_Release_Rest_Controller.php' @(
    "public const ROUTE_RELEASE = '/submission-evaluations/"
    "public const ACTION_RELEASE = 'submission.result.release'"
    'Access_Policy_Request::SHELL_CONSOLE'
    'Addon_Capabilities::PUBLISH_FEEDBACK'
    'Context_Scope::OPERATION_EVALUATE'
    "new Object_Scope( Object_Scope::TYPE_EVALUATION, `$id, 'release' )"
    "'correction' === `$mode ? array( 'feedback_visibility', 'mode', 'reason_code', 'reason_note', 'score_visibility' )"
    "'reason_code' => `$reason_code"
    "'reason_note' => `$reason_note"
    '201'
) 'frozen C43 REST and authorization'

Assert-Markers 'tests\Contract\submission-result-correction-g4-imp-06-s04.php' @(
    'Correction must link the exact previous current release.'
    'Changed current release must reject a stale correction.'
    'Feedback from a replaced official review must not be reused.'
    'Exact correction retry must replay before current pointer comparison.'
) 'application contract'

Assert-Markers 'tests\Contract\submission-result-correction-storage-g4-imp-06-s04.php' @(
    "'submission_result.corrected'"
    "array( 'evaluation_id', 'release_id', 'release_path', 'request_digest' )"
    'Correction pointer race must roll back.'
    'Correction release history must remain append-only.'
) 'storage contract'

Assert-Markers 'tests\Contract\submission-result-correction-rest-g4-imp-06-s04.php' @(
    'C43 correction must reuse the one frozen release route.'
    'C43 correction response leaked private content:'
    'Stale correction must return 409.'
    'Permission revocation must deny correction before application execution.'
) 'REST contract'

Assert-Markers 'docs\ai-assessment\g4-imp-06-s04-student-result-correction.html' @(
    'G4-IMP-06-S04 C43'
    'submission_result.corrected'
    'API-AUD-2026-08-24-R77-OBSERVED'
    'G4-IMP-06-S05'
) 'implementation evidence HTML'

Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g4-imp-06-s04-student-result-correction.html'
    'g4-imp-06-s05-student-result-ui-integration.html'
    'IMP-06 5/5 완료'
    'G4-IMP-06-S05 완료'
) 'implementation roadmap'

Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'id="r77-observation"'
    'API-AUD-2026-08-24-R77-OBSERVED'
    'check:submission-result-correction-g4'
    'submission_result.corrected'
    'oj-platform</code> 파일 write 0'
) 'platform API audit'

foreach ($test in @(
    'submission-result-correction-g4-imp-06-s04.php'
    'submission-result-correction-storage-g4-imp-06-s04.php'
    'submission-result-correction-rest-g4-imp-06-s04.php'
)) {
    & $php (Join-Path $root ('tests\Contract\' + $test))
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-06-S04 passed: C43 immutable corrected revision, exact current and official source CAS, approved-feedback isolation, body-free outbox, durable replay, and Console authorization are frozen.'
