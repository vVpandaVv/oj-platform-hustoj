param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'

$phpFiles = @(
    'src\Submission\Assessment\Domain\Submission_External_Ai_Consent_Record.php',
    'src\Submission\Assessment\Application\Submission_External_Ai_Consent_Failure.php',
    'src\Submission\Assessment\Application\Approve_Submission_External_Ai_Consent_Command.php',
    'src\Submission\Assessment\Application\Revoke_Submission_External_Ai_Consent_Command.php',
    'src\Submission\Assessment\Application\Submission_External_Ai_Consent_Command_Result.php',
    'src\Submission\Assessment\Application\Manage_Submission_External_Ai_Consent.php',
    'src\Submission\Assessment\Port\Submission_External_Ai_Consent_Repository_Port_Interface.php',
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_External_Ai_Consent_Repository.php',
    'src\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Execution_Consent.php',
    'src\Submission\Assessment\Infrastructure\Submission_Evaluation_Worker_Module.php',
    'src\Submission\Assessment\Presentation\Submission_External_Ai_Consent_Rest_Controller.php',
    'src\Submission\Assessment\Presentation\Submission_External_Ai_Consent_Rest_Module.php',
    'src\Platform\External_Ai_Consent_Settings_Page_Handler.php',
    'src\Core\Composition_Root.php',
    'src\Core\Deactivation.php',
    'tests\Contract\submission-external-ai-consent-g4-imp-02-r01.php'
)

foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\submission-external-ai-consent-g4-imp-02-r01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\submission-evaluation-worker-g4-imp-02-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

node (Join-Path $root 'tests\Contract\external-ai-consent-r01.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'tests\Contract\submission-external-ai-consent-g4-imp-02-r01.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
node (Join-Path $root 'scripts\e2e-external-ai-consent-settings.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-02-R01 passed: isolated student submission consent ledger, Q38/C78/C79, dual Console UI, explicit worker loader, activation and deactivation are frozen.'
