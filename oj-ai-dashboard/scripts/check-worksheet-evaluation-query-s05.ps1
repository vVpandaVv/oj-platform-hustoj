$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'php-source-markers.ps1')

$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'
$evaluationRoot = Join-Path $root 'src\Worksheet\Evaluation'

$files = @(
    'src\Core\Composition_Root.php',
    'src\Security\Policy\Object_Scope.php',
    'src\Worksheet\Evaluation\Application\Read_Worksheet_Evaluations.php',
    'src\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure.php',
    'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Query_Projector.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Query.php',
    'src\Worksheet\Evaluation\Infrastructure\Worksheet_Evaluation_Object_Authorization.php',
    'src\Worksheet\Evaluation\Port\Worksheet_Evaluation_Query_Port_Interface.php',
    'src\Worksheet\Evaluation\Presentation\Worksheet_Evaluation_Query_Rest_Controller.php',
    'src\Worksheet\Evaluation\Presentation\Worksheet_Evaluation_Query_Rest_Module.php',
    'tests\Contract\worksheet-evaluation-query-s05.php'
)
foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$sources = Get-ChildItem -LiteralPath $evaluationRoot -Recurse -File -Filter '*.php'
$sourceText = ($sources | ForEach-Object { Get-Content -LiteralPath $_.FullName -Raw }) -join "`n"
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_')) {
    if ($sourceText.Contains($forbidden)) {
        throw "S05 evaluation query runtime contains forbidden table prefix marker: $forbidden"
    }
}

$repository = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Infrastructure\WordPress_Worksheet_Evaluation_Query.php') -Raw
foreach ($marker in @(
    "'oj_ai_worksheet_evaluations'",
    "'oj_ai_worksheet_evaluation_results'",
    "'oj_ai_worksheet_evaluation_items'",
    "'oj_ai_worksheet_evaluation_reviews'",
    "'oj_ai_evaluation_runs'",
    "'oj_ai_evaluation_attempts'",
    "'oj_ai_worksheets'",
    "'oj_ai_worksheet_revisions'",
    'WHERE w.owner_user_id = %d',
    'AND w.owner_user_id = %d',
    "decode( `$cursor, 'worksheet_evaluation_list' )",
    "encode(`n`t`t`t`t`t'worksheet_evaluation_list'",
    'scope_hash(',
    "'stale' === `$state",
    'e.state = %s',
    'w.public_id = %s',
    'e.worksheet_revision_public_id = %s',
    'ORDER BY e.updated_at_utc DESC, e.public_id DESC LIMIT %d',
    'authorization_snapshot('
)) {
    if (-not (Test-OjaiPhpMarker -Source $repository -Marker $marker -Php $php)) {
        throw "S05 owner query or signed cursor marker is missing: $marker"
    }
}
foreach ($forbidden in @('INSERT INTO ', 'UPDATE oj_ai_', 'DELETE FROM ', 'REPLACE INTO ', 'START TRANSACTION', 'FOR UPDATE')) {
    if ($repository.Contains($forbidden)) {
        throw "S05 query adapter must remain read-only: $forbidden"
    }
}

$projector = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Domain\Worksheet_Evaluation_Query_Projector.php') -Raw
foreach ($marker in @(
    'STORED_DATA_INVALID',
    'decode_canonical(',
    'normalized_result_hash',
    'provider_output_digest',
    'result_content_hash',
    'item_content_hash',
    'item_decisions_hash',
    'assert_detail_links(',
    "'succeeded' !== `$linked_result['state']",
    "`$this->canonical->encode( `$finding['evidence'] ) !== `$this->canonical->encode( `$payload['evidence'] ?? null )"
)) {
    if (-not (Test-OjaiPhpMarker -Source $projector -Marker $marker -Php $php)) {
        throw "S05 fail-closed projection marker is missing: $marker"
    }
}
foreach ($forbidden in @("'normalized_result_json' =>", "'provider_key' =>", "'model_key' =>", "'routing_revision' =>")) {
    if ($projector.Contains($forbidden)) {
        throw "S05 safe projection exposes a provider or routing field: $forbidden"
    }
}

$controller = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Presentation\Worksheet_Evaluation_Query_Rest_Controller.php') -Raw
foreach ($marker in @(
    "ROUTE_EVALUATIONS = '/worksheet-evaluations'",
    "ROUTE_EVALUATION  = '/worksheet-evaluations/(?P<evaluation_id>",
    "'methods'             => 'GET'",
    'Rest_Permission_Handoff',
    'Addon_Capabilities::ASSESS_WORKSHEETS',
    'Object_Scope::TYPE_EVALUATION',
    "new Object_Scope( Object_Scope::TYPE_EVALUATION, `$evaluation_id, 'view' )",
    "array( 'state', 'worksheet_id', 'revision_id', 'cursor', 'limit', 'view' )",
    "'mutation_count'=> 0",
    "'raw_provider_output'   => false",
    "'raw_prompt'            => false"
)) {
    if (-not (Test-OjaiPhpMarker -Source $controller -Marker $marker -Php $php)) {
        throw "S05 Q09/Q10 REST marker is missing: $marker"
    }
}
foreach ($forbidden in @("'methods'             => 'POST'", "'methods'             => 'PUT'", "'methods'             => 'PATCH'", "'methods'             => 'DELETE'")) {
    if ($controller.Contains($forbidden)) {
        throw "S05 Q09/Q10 controller contains a mutation route: $forbidden"
    }
}
if ([regex]::Matches($controller, "'methods'\s*=>\s*'GET'").Count -ne 2) {
    throw 'S05 Q09/Q10 controller must register exactly two GET operations.'
}

$objectAuthorization = Get-Content -LiteralPath (Join-Path $evaluationRoot 'Infrastructure\Worksheet_Evaluation_Object_Authorization.php') -Raw
foreach ($marker in @('Object_Scope::TYPE_EVALUATION', 'null !== $context', 'authorization_snapshot(', 'Object_Authorization_Result::denied()', 'Object_Authorization_Result::allowed(')) {
    if (-not (Test-OjaiPhpMarker -Source $objectAuthorization -Marker $marker -Php $php)) {
        throw "S05 evaluation object authorization marker is missing: $marker"
    }
}

$objectScope = Get-Content -LiteralPath (Join-Path $root 'src\Security\Policy\Object_Scope.php') -Raw
if (-not (Test-OjaiPhpMarker -Source $objectScope -Marker "self::TYPE_EVALUATION," -Php $php)) {
    throw 'S05 evaluation object scope must be declared context-free for worksheet ownership.'
}

$composition = Get-Content -LiteralPath (Join-Path $root 'src\Core\Composition_Root.php') -Raw
foreach ($marker in @(
    'new WordPress_Worksheet_Evaluation_Query(',
    'new Worksheet_Evaluation_Object_Authorization(',
    'Object_Scope::TYPE_EVALUATION',
    'new Worksheet_Evaluation_Query_Rest_Controller(',
    'new Read_Worksheet_Evaluations(',
    'new Worksheet_Evaluation_Query_Rest_Module('
)) {
    if (-not (Test-OjaiPhpMarker -Source $composition -Marker $marker -Php $php)) {
        throw "S05 composition marker is missing: $marker"
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-evaluation-query-s05.php')
exit $LASTEXITCODE
