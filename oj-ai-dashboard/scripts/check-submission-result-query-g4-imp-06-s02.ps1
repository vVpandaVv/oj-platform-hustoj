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
    'src\Submission\Assessment\Application\Read_Own_Submission_Result.php'
    'src\Submission\Assessment\Application\Submission_Evaluation_Failure.php'
    'src\Submission\Assessment\Domain\Submission_Result_Projection.php'
    'src\Submission\Assessment\Port\Submission_Result_Query_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\Submission_Result_Object_Authorization.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Query.php'
    'src\Submission\Assessment\Presentation\Submission_Result_Query_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Submission_Result_Query_Rest_Module.php'
    'tests\Contract\submission-result-query-g4-imp-06-s02.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $files | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) { throw "G4-IMP-06-S02 runtime contains forbidden boundary marker: $forbidden" }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G4-IMP-06-S02 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Query.php' @(
    "private const TABLE_RELEASES    = 'oj_ai_submission_release_revisions'"
    'submission_row.learner_user_id = %d'
    'target.learner_user_id = %d'
    'submission_row.current_release_public_id = release_row.public_id'
    'submission_row.current_release_hash = release_row.content_hash'
    "array( 'released', 'corrected', 'withdrawn', 'stale' )"
    'assert_projection('
    'release_content_hash('
    'Submission_Result_Projection::MAXIMUM_BYTES'
) 'literal current-release query'

Assert-Markers 'src\Submission\Assessment\Application\Read_Own_Submission_Result.php' @(
    'authorization_snapshot('
    'find_current_for_actor('
    "'result_withdrawn'"
    "'result_updating'"
    "'projection' => `$visible ? `$current['projection'] : null"
) 'owner-only safe application projection'

Assert-Markers 'src\Submission\Assessment\Infrastructure\Submission_Result_Object_Authorization.php' @(
    'Object_Scope::TYPE_RELEASE'
    "'self_view'"
    "'self_release'"
    "`$snapshot['context_revision'] !== `$context->revision()"
) 'release object authorization'

Assert-Markers 'src\Submission\Assessment\Presentation\Submission_Result_Query_Rest_Controller.php' @(
    "public const ROUTE_RESULT = '/me/results/"
    "public const ACTION_VIEW = 'submission.result.view'"
    'Access_Policy_Request::SHELL_PUBLIC'
    'Addon_Capabilities::PARTICIPATE'
    'Context_Scope::OPERATION_PARTICIPATE'
    'Object_Scope::TYPE_RELEASE'
    "'methods' => 'GET'"
    "array( 'result' => `$result )"
) 'Q33 PublicUI REST'

Assert-Markers 'src\Core\Composition_Root.php' @(
    'new WordPress_Submission_Result_Query('
    'new Submission_Result_Object_Authorization('
    'new Read_Own_Submission_Result('
    'new Submission_Result_Query_Rest_Controller('
    'new Submission_Result_Query_Rest_Module('
) 'Composition Root registration'

Assert-Markers 'tests\Contract\submission-result-query-g4-imp-06-s02.php' @(
    'Another learner must receive Q33 404 semantics.'
    'A historical release ID must receive Q33 404 semantics.'
    'Q33 stale result must expose no previous projection.'
    'Q33 withdrawn result must expose no previous projection.'
    'hash-consistent projection outside the exact allowlist'
    'Q33 must require PublicUI login.'
) 'Q33 contract tests'

Assert-Markers 'docs\ai-assessment\g4-imp-06-s02-student-result-query.html' @(
    'G4-IMP-06-S02 Q33'
    'GET /oj-ai/v1/me/results/{release_id}'
    'API-AUD-2026-08-24-R75-OBSERVED'
    'G4-IMP-06-S03 C44'
) 'implementation evidence HTML'

Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g4-imp-06-s02-student-result-query.html'
    'g4-imp-06-s03-student-result-withdrawal.html'
    'g4-imp-06-s05-student-result-ui-integration.html'
    'IMP-06 5/5 완료'
    'G4-IMP-06-S05 완료'
) 'implementation roadmap'

Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'id="r75-observation"'
    'API-AUD-2026-08-24-R75-OBSERVED'
    'check:submission-result-query-g4'
    'Object_Scope::TYPE_RELEASE'
) 'platform API audit'

& $php (Join-Path $root 'tests\Contract\submission-result-query-g4-imp-06-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-06-S02 passed: Q33 owner-only current release, exact projection integrity, stale/withdrawn body suppression, release object authorization, PublicUI REST, and literal tables are frozen.'
