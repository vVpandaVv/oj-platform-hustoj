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
    'src\Rest\Rest_Permission_Handoff.php'
    'src\Security\Policy\Infrastructure\Evaluation_Object_Authorization_Router.php'
    'src\Submission\Assessment\Application\Read_Submission_Evaluations.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector.php'
    'src\Submission\Assessment\Domain\Submission_Evaluation_Candidate_Projector.php'
    'src\Submission\Assessment\Port\Submission_Evaluation_Query_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\Submission_Evaluation_Object_Authorization.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Query.php'
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Query_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Submission_Evaluation_Query_Rest_Module.php'
    'src\Core\Composition_Root.php'
    'src\Database\Foundation\Schema_Catalog.php'
    'src\Database\Foundation\Migration_Plan.php'
    'src\Database\Foundation\Schema_Installation_Runner.php'
    'tests\Contract\submission-evaluation-query-g4-imp-02-s03.php'
    'tests\Contract\database-foundation.php'
    'tests\Contract\database-infrastructure.php'
    'tests\Contract\runtime-schema-readiness.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $files | Where-Object { $_ -like 'src\*' }
$sourceText = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($sourceText.Contains($forbidden)) { throw "G4-IMP-02-S03 runtime contains forbidden boundary marker: $forbidden" }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G4-IMP-02-S03 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Submission\Assessment\Presentation\Submission_Evaluation_Query_Rest_Controller.php' @(
    "ROUTE_EVALUATIONS = '/submission-evaluations'"
    "ROUTE_EVALUATION  = '/submission-evaluations/(?P<evaluation_id>"
    "ROUTE_CANDIDATES  = '/submission-evaluation-candidates'"
    "ROUTE_CANDIDATE   = '/submission-evaluation-candidates/(?P<submission_id>"
    'Addon_Capabilities::ASSESS_SUBMISSIONS'
    'Addon_Capabilities::REVIEW_ASSESSMENTS'
    '$this->permissions->check_any('
    "'raw_provider_output'=> false"
    "'raw_prompt'         => false"
) 'REST controller'

Assert-Markers 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Query.php' @(
    "private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations'"
    "private const TABLE_RESULTS     = 'oj_ai_submission_evaluation_results'"
    "private const CANDIDATE_CURSOR_KIND = 'q16c'"
    "private const CURSOR_KIND = 'q16'"
    'list_unevaluated_context('
    'NOT EXISTS (SELECT 1 FROM '
    "array( 'f', 'i', 'p', 't' )"
    'HAVING (priority_rank < %d'
    'ORDER BY priority_rank DESC, evaluation_updated_at_utc ASC, evaluation_id ASC'
    '), 0, 32 );'
) 'literal query adapter'

Assert-Markers 'src\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector.php' @(
    "'safety_review'"
    "'abstained'"
    "'low_confidence'"
    '$stored_evidence_items = $this->evidence('
    "'prompt_contract_version'"
    'assert_no_forbidden_keys'
) 'safe projector'

Assert-Markers 'src\Rest\Rest_Permission_Handoff.php' @(
    'public function check_any('
    '403 !== $problem->status()'
    '$this->grants[ $request ] = new Rest_Permission_Grant'
) 'any-of permission handoff'

Assert-Markers 'src\Security\Policy\Infrastructure\Evaluation_Object_Authorization_Router.php' @(
    'null === $context ? $this->worksheet_evaluations : $this->submission_evaluations'
) 'evaluation authorization router'

Assert-Markers 'src\Core\Composition_Root.php' @(
    'new Evaluation_Object_Authorization_Router('
    'new Submission_Evaluation_Query_Rest_Module('
    'new WordPress_Submission_Evaluation_Query('
) 'composition root'

Assert-Markers 'src\Database\Foundation\Schema_Catalog.php' @(
    "SCHEMA_VERSION = '1.0.4'"
    "'prompt_contract_version'         => 'VARCHAR(64) NOT NULL'"
) 'schema 1.0.2 catalog'

Assert-Markers 'src\Database\Foundation\Migration_Plan.php' @(
    '202608230001_expand_submission_prompt_contract'
    "PREVIOUS_SCHEMA_VERSION = '1.0.1'"
    'PREVIOUS_SCHEMA_FINGERPRINT'
    'upgrade_source_tables('
    'VARCHAR(64) NOT NULL'
) 'append-only schema migration'

& $php (Join-Path $root 'tests\Contract\submission-evaluation-query-g4-imp-02-s03.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\database-foundation.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\database-infrastructure.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\runtime-schema-readiness.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-02-S03 Q16/Q17 query, safe projection, signed cursor, any-of authorization, and schema 1.0.2 upgrade contract passed.'
