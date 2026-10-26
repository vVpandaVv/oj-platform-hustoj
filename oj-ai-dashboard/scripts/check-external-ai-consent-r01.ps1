param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'

$phpFiles = @(
    'src\Worksheet\Evaluation\Domain\External_Ai_Consent_Record.php',
    'src\Worksheet\Evaluation\Application\External_Ai_Consent_Failure.php',
    'src\Worksheet\Evaluation\Application\Approve_External_Ai_Consent_Command.php',
    'src\Worksheet\Evaluation\Application\Revoke_External_Ai_Consent_Command.php',
    'src\Worksheet\Evaluation\Application\External_Ai_Consent_Command_Result.php',
    'src\Worksheet\Evaluation\Application\Manage_External_Ai_Consent.php',
    'src\Worksheet\Evaluation\Port\External_Ai_Consent_Repository_Port_Interface.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_External_Ai_Consent_Repository.php',
    'src\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Execution_Consent.php',
    'src\Worksheet\Evaluation\Presentation\External_Ai_Consent_Rest_Controller.php',
    'src\Worksheet\Evaluation\Presentation\External_Ai_Consent_Rest_Module.php',
    'src\Platform\External_Ai_Consent_Settings_Page_Handler.php',
    'src\Platform\Addon_Manifest.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\external-ai-consent-r01.php'
)
foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\external-ai-consent-r01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\worksheet-evaluation-worker-s04.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

node (Join-Path $root 'tests\Contract\external-ai-consent-r01.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'tests\Contract\worksheet-evaluation-workbench-s08.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-external-ai-consent-settings.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-worksheet-evaluation-workbench.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
