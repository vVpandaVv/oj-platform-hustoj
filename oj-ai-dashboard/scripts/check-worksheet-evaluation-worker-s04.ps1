$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'php-source-markers.ps1')

$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'
$evaluationRoot = Join-Path $root 'src\Worksheet\Evaluation'

$files = @(
    'src\Core\Composition_Root.php',
    'src\Core\Deactivation.php',
    'src\Platform\Addon_Manifest.php',
    'src\Worksheet\Evaluation\Application\Run_Next_Worksheet_Evaluation.php',
    'src\Worksheet\Evaluation\Application\Run_Worksheet_Evaluation_Result.php',
    'src\Worksheet\Evaluation\Application\Worksheet_Evaluation_Worker_Failure.php',
    'src\Worksheet\Evaluation\Domain\External_Ai_Consent_Record.php',
    'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Gateway_Result.php',
    'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Normalized_Result.php',
    'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Output_Normalizer.php',
    'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Work_Item.php',
    'src\Worksheet\Evaluation\Infrastructure\Platform_Worksheet_Evaluation_Execution.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Execution_Consent.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Work_Repository.php',
    'src\Worksheet\Evaluation\Infrastructure\Worksheet_Evaluation_Worker_Module.php',
    'src\Worksheet\Evaluation\Port\Worksheet_Evaluation_Execution_Consent_Port_Interface.php',
    'src\Worksheet\Evaluation\Port\Worksheet_Evaluation_Execution_Port_Interface.php',
    'src\Worksheet\Evaluation\Port\Worksheet_Evaluation_Work_Repository_Port_Interface.php',
    'tests\Contract\worksheet-evaluation-worker-s04.php'
)
foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$sources = Get-ChildItem -LiteralPath $evaluationRoot -Recurse -File -Filter '*.php'
$sourceText = ($sources | ForEach-Object { Get-Content -LiteralPath $_.FullName -Raw }) -join "`n"
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_')) {
    if ($sourceText.Contains($forbidden)) {
        throw "S04 evaluation runtime contains forbidden table prefix marker: $forbidden"
    }
}

$repository = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Infrastructure\WordPress_Worksheet_Evaluation_Work_Repository.php') -Raw
foreach ($marker in @(
    "'oj_ai_worksheet_evaluations'",
    "'oj_ai_worksheet_evaluation_inputs'",
    "'oj_ai_worksheet_evaluation_results'",
    "'oj_ai_worksheet_evaluation_items'",
    "'oj_ai_evaluation_runs'",
    "'oj_ai_evaluation_attempts'",
    "'oj_ai_audit_events'",
    "'oj_ai_outbox_events'",
    "'START TRANSACTION'",
    "'COMMIT'",
    "'ROLLBACK'",
    'FOR UPDATE',
    'lease_token = %s',
    'lock_version = lock_version + 1',
    'provider_output_digest = NULL',
    "'published_at_utc'     => null",
    'result_exists(',
    'finish_attempt(',
    'insert_items(',
    'insert_audit(',
    'insert_outbox(',
    'assert_success_postcondition('
)) {
    if (-not (Test-OjaiPhpMarker -Source $repository -Marker $marker -Php $php)) {
        throw "S04 atomic worker repository marker is missing: $marker"
    }
}
if ($repository.Contains("'raw_response' =>") -or $repository.Contains("'provider_output_json' =>")) {
    throw 'S04 repository must not persist raw provider responses.'
}

$worker = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Application\Run_Next_Worksheet_Evaluation.php') -Raw
foreach ($marker in @(
    'LEASE_SECONDS = 300',
    'MAXIMUM_ATTEMPTS = 3',
    'deadline_exceeded(',
    'consent->allows(',
    'execution->snapshot(',
    'execution->execute(',
    'normalizer->normalize(',
    'same_assignment(',
    "'routing_changed_during_execute'",
    "'external_processing_not_approved'",
    '? 60 : 300',
    'complete_success(',
    'complete_failure('
)) {
    if (-not (Test-OjaiPhpMarker -Source $worker -Marker $marker -Php $php)) {
        throw "S04 worker lifecycle marker is missing: $marker"
    }
}

$normalizer = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Domain\Worksheet_Evaluation_Output_Normalizer.php') -Raw
foreach ($marker in @(
    'MAXIMUM_OUTPUT_BYTES = 524288',
    'MAXIMUM_FINDINGS = 100',
    'MAXIMUM_EVIDENCE_PER_FINDING = 3',
    'assert_exact_keys(',
    'assert_no_forbidden_keys(',
    'anchor_index(',
    'provider_projection',
    'OUTPUT_PRIVACY_INVALID'
)) {
    if (-not (Test-OjaiPhpMarker -Source $normalizer -Marker $marker -Php $php)) {
        throw "S04 strict normalizer marker is missing: $marker"
    }
}

$execution = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Infrastructure\Platform_Worksheet_Evaluation_Execution.php') -Raw
foreach ($marker in @('AddonApi\V1\AddonAiTaskRequestV1', '->snapshot(', '->execute(', '->routingSnapshot(', '->problem(')) {
    if (-not (Test-OjaiPhpMarker -Source $execution -Marker $marker -Php $php)) {
        throw "S04 public Addon API execution marker is missing: $marker"
    }
}
foreach ($forbidden in @(
    'CodingPanda\OjPlatform\Ai\',
    'CodingPanda\OjPlatform\AI\',
    'CodingPanda\OjPlatform\Infrastructure\',
    'CodingPanda\OjPlatform\Modules\',
    'oj_platform_ai_'
)) {
    if ($execution.Contains($forbidden)) {
        throw "S04 execution adapter crosses the public platform boundary: $forbidden"
    }
}

$consent = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Infrastructure\WordPress_Worksheet_Evaluation_Execution_Consent.php') -Raw
foreach ($marker in @(
    "'oj_ai_dashboard_external_ai_consent'",
    'External_Ai_Consent_Record::from_array(',
    'matches_routing(',
    'catch ( Throwable )'
)) {
    if (-not (Test-OjaiPhpMarker -Source $consent -Marker $marker -Php $php)) {
        throw "S04 external processing consent adapter marker is missing: $marker"
    }
}

$consentRecord = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Domain\External_Ai_Consent_Record.php') -Raw
foreach ($marker in @(
    "'oj_ai.external_ai_processing_consent'",
    "'worksheet_assessment_configuration'",
    "'worksheet_asset_text'",
    "'worksheet_content'",
    "'approved'",
    "'profile_fingerprint'",
    "'consent_hash'",
    'exact_keys(',
    'hash_equals('
)) {
    if (-not (Test-OjaiPhpMarker -Source $consentRecord -Marker $marker -Php $php)) {
        throw "S04 external processing consent record marker is missing: $marker"
    }
}

$module = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Infrastructure\Worksheet_Evaluation_Worker_Module.php') -Raw
foreach ($marker in @('cron_schedules', "'interval' => 60", 'wp_schedule_event(', 'wp_clear_scheduled_hook(', 'run_once()')) {
    if (-not (Test-OjaiPhpMarker -Source $module -Marker $marker -Php $php)) {
        throw "S04 worker scheduler marker is missing: $marker"
    }
}

$v1 = Join-Path $root 'resources\contracts\ai\worksheet-quality-prompt-v1.txt'
$v2 = Join-Path $root 'resources\contracts\ai\worksheet-quality-prompt-v2.txt'
function Get-Sha256Lower([string] $path) {
    $algorithm = [System.Security.Cryptography.SHA256]::Create()
    try {
        $stream = [System.IO.File]::OpenRead($path)
        try {
            return ([System.BitConverter]::ToString($algorithm.ComputeHash($stream))).Replace('-', '').ToLowerInvariant()
        }
        finally {
            $stream.Dispose()
        }
    }
    finally {
        $algorithm.Dispose()
    }
}
if ((Get-Sha256Lower $v1) -ne '20198a7d53f5503cf8b672919b96bf4771c247d7b5287ab166417d2fb8c31887') {
    throw 'S04 historical prompt v1 hash drifted.'
}
if ((Get-Sha256Lower $v2) -ne '5499f44428a9e504e045fb6035c6d650bef8601a8421567095063a231e5db2cd') {
    throw 'S04 prompt v2 hash drifted.'
}

& $php (Join-Path $root 'tests\Contract\worksheet-evaluation-worker-s04.php')
exit $LASTEXITCODE
