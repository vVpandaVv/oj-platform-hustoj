$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$php = 'D:\php\php.exe'
$phpFiles = @(
    'src\Assessment\Configuration\Application\Assessment_Configuration_Failure.php',
    'src\Assessment\Configuration\Application\Manage_Assessment_Configuration.php',
    'src\Assessment\Configuration\Domain\Assessment_Configuration_Profile.php',
    'src\Assessment\Configuration\Infrastructure\WordPress_Assessment_Configuration_Repository.php',
    'src\Assessment\Configuration\Port\Assessment_Configuration_Repository_Port_Interface.php',
    'src\Assessment\Configuration\Presentation\Assessment_Configuration_Rest_Controller.php',
    'src\Assessment\Configuration\Presentation\Assessment_Configuration_Rest_Module.php',
    'src\Core\Activation.php',
    'src\Core\Activation_Workflow.php',
    'src\Core\Composition_Root.php',
    'src\Platform\External_Ai_Consent_Settings_Page_Handler.php'
)

foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php (Join-Path $root 'tests\Contract\assessment-configuration-bootstrap-s01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& node (Join-Path $root 'tests\Contract\assessment-configuration-bootstrap-s01.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& node (Join-Path $root 'scripts\e2e-assessment-configuration-settings.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
