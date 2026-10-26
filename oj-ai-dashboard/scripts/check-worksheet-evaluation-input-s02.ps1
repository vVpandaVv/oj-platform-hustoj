$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'

$files = @(
    'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Failure.php',
    'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input.php',
    'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder.php',
    'tests\Contract\worksheet-evaluation-input-s02.php'
)
foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$builder = Get-Content -LiteralPath (Join-Path $root 'src\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder.php') -Raw
$forbiddenRuntimeMarkers = @(
    '$wpdb',
    'AddonAiTaskGatewayV1',
    'register_rest_route',
    'wp_remote_',
    'curl_exec',
    'file_get_contents'
)
foreach ($marker in $forbiddenRuntimeMarkers) {
    if ($builder.Contains($marker)) {
        throw "S02 P04 builder contains forbidden runtime dependency: $marker"
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-evaluation-input-s02.php')
exit $LASTEXITCODE
