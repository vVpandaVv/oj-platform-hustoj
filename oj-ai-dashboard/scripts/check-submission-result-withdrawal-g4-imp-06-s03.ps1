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
    & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'scripts\check-submission-result-release-g4-imp-06-s01.ps1')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'scripts\check-submission-result-query-g4-imp-06-s02.ps1')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$phpFiles = @(
    'src\Core\Composition_Root.php'
    'src\Submission\Assessment\Application\Withdraw_Submission_Result.php'
    'src\Submission\Assessment\Application\Withdraw_Submission_Result_Command.php'
    'src\Submission\Assessment\Application\Submission_Result_Withdrawal_Result.php'
    'src\Submission\Assessment\Application\Submission_Evaluation_Failure.php'
    'src\Submission\Assessment\Domain\Submission_Result_Projection.php'
    'src\Submission\Assessment\Port\Submission_Result_Withdrawal_Repository_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\Submission_Evaluation_Object_Authorization.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Withdrawal_Repository.php'
    'src\Submission\Assessment\Presentation\Submission_Result_Withdrawal_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Submission_Result_Withdrawal_Rest_Module.php'
    'tests\Contract\submission-result-withdrawal-g4-imp-06-s03.php'
    'tests\Contract\submission-result-withdrawal-storage-g4-imp-06-s03.php'
    'tests\Contract\submission-result-withdrawal-rest-g4-imp-06-s03.php'
)

foreach ($file in $phpFiles) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) { throw "G4-IMP-06-S03 runtime contains forbidden boundary marker: $forbidden" }
}
if ($runtimeSource -match '(?i)\b(?:UPDATE|DELETE)\s+(?:FROM\s+)?oj_ai_submission_release_revisions\b') {
    throw 'G4-IMP-06-S03 release revision history is not append-only.'
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G4-IMP-06-S03 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Submission\Assessment\Application\Withdraw_Submission_Result_Command.php' @(
    "'data_correction'"
    "'publication_error'"
    "'privacy_request'"
    "'other'"
    "'|submission.result.withdraw|'"
    "'expected_current_release_hash'"
    "'reason_note'"
) 'strict command'

Assert-Markers 'src\Submission\Assessment\Application\Withdraw_Submission_Result.php' @(
    "array( 'released', 'corrected' )"
    "'state' => 'withdrawn'"
    "'previous_release_id' => `$command->expected_current_release_id()"
    "'projection_json' => `$projection->json()"
    'find_withdrawal_receipt('
    'append_withdrawal('
) 'application transition'

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Withdrawal_Repository.php' @(
    "private const TABLE_RELEASES    = 'oj_ai_submission_release_revisions'"
    "private const EVENT = 'submission_result.withdrawn'"
    'START TRANSACTION'
    'FOR UPDATE'
    'assert_locked_source('
    'insert_release('
    'assert_postcondition('
    'current_release_public_id = %s, current_release_hash = %s, lock_version = lock_version + 1'
    'AND current_release_public_id = %s AND current_release_hash = %s'
    "array( 'evaluation_id' => `$result->evaluation_id(), 'release_id' => `$result->release_id(), 'release_path' => `$result->release_path(), 'request_digest' => `$record['request_digest'], )"
) 'atomic withdrawal repository'

Assert-Markers 'src\Submission\Assessment\Presentation\Submission_Result_Withdrawal_Rest_Controller.php' @(
    "public const ROUTE_WITHDRAW = '/submission-evaluations/"
    "public const ACTION_WITHDRAW = 'submission.result.withdraw'"
    'Access_Policy_Request::SHELL_CONSOLE'
    'Addon_Capabilities::PUBLISH_FEEDBACK'
    'Context_Scope::OPERATION_EVALUATE'
    "new Object_Scope( Object_Scope::TYPE_EVALUATION, `$id, 'withdraw_release' )"
    "'reason_code' => `$result->reason_code()"
    '201'
) 'C44 REST and authorization'

Assert-Markers 'src\Submission\Assessment\Infrastructure\Submission_Evaluation_Object_Authorization.php' @(
    "'withdraw_release'"
) 'object policy'

Assert-Markers 'src\Core\Composition_Root.php' @(
    'new WordPress_Submission_Result_Withdrawal_Repository'
    'new Withdraw_Submission_Result('
    'new Submission_Result_Withdrawal_Rest_Controller'
    'new Submission_Result_Withdrawal_Rest_Module'
) 'Composition Root registration'

Assert-Markers 'tests\Contract\submission-result-withdrawal-g4-imp-06-s03.php' @(
    'C44 must copy the prior projection byte for byte.'
    'Exact C44 retry must replay before withdrawn-state comparison.'
    'Stale release must not be withdrawn again.'
) 'application contract'

Assert-Markers 'tests\Contract\submission-result-withdrawal-storage-g4-imp-06-s03.php' @(
    "array( 'START', 'INSERT:oj_ai_submission_release_revisions', 'UPDATE_POINTER', 'INSERT:oj_ai_audit_events', 'INSERT:oj_ai_outbox_events', 'COMMIT' )"
    "array( 'evaluation_id', 'release_id', 'release_path', 'request_digest' )"
    'C44 pointer race must roll back the transaction.'
    'C44 release history must remain append-only.'
) 'storage contract'

Assert-Markers 'tests\Contract\submission-result-withdrawal-rest-g4-imp-06-s03.php' @(
    'C44 command response leaked private data'
    'Unknown C44 payload field must fail.'
    'Permission revocation must deny C44 before application execution.'
) 'REST contract'

Assert-Markers 'tests\Contract\submission-result-query-g4-imp-06-s02.php' @(
    'Q33 withdrawn result must expose no previous projection.'
    "'result_withdrawn'"
) 'Q33 immediate suppression regression'

Assert-Markers 'docs\ai-assessment\g4-imp-06-s03-student-result-withdrawal.html' @(
    'G4-IMP-06-S03 C44'
    'withdraw-release'
    'API-AUD-2026-08-24-R76-OBSERVED'
    'G4-IMP-06-S04'
) 'implementation evidence HTML'

Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g4-imp-06-s03-student-result-withdrawal.html'
    'g4-imp-06-s05-student-result-ui-integration.html'
    'IMP-06 5/5 완료'
    'G4-IMP-06-S05 완료'
) 'implementation roadmap'

Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'id="r76-observation"'
    'API-AUD-2026-08-24-R76-OBSERVED'
    'check:submission-result-withdrawal-g4'
    'submission_result.withdrawn'
) 'platform API audit'

foreach ($test in @(
    'submission-result-withdrawal-g4-imp-06-s03.php'
    'submission-result-withdrawal-storage-g4-imp-06-s03.php'
    'submission-result-withdrawal-rest-g4-imp-06-s03.php'
)) {
    & $php (Join-Path $root ('tests\Contract\' + $test))
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-06-S03 passed: C44 immutable withdrawn revision, exact-copy projection, pointer CAS, body-free outbox, durable replay, Console authorization, and Q33 immediate suppression are frozen.'
