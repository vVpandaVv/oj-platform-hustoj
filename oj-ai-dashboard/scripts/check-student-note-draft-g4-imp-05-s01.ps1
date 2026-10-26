$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

$phpFiles = @(
    'src\Core\Composition_Root.php'
    'src\Core\Deactivation.php'
    'src\Submission\Assessment\Application\Submission_Evaluation_Failure.php'
    'src\Submission\Assessment\Infrastructure\Submission_Evaluation_Object_Authorization.php'
    'src\Submission\Assessment\Application\Generate_Student_Note_Draft_Command.php'
    'src\Submission\Assessment\Application\Generate_Student_Note_Draft_Result.php'
    'src\Submission\Assessment\Application\Generate_Student_Note_Draft.php'
    'src\Submission\Assessment\Application\Run_Student_Note_Draft_Result.php'
    'src\Submission\Assessment\Application\Run_Next_Student_Note_Draft.php'
    'src\Submission\Assessment\Domain\Student_Note_Input.php'
    'src\Submission\Assessment\Domain\Student_Note_Input_Builder.php'
    'src\Submission\Assessment\Domain\Student_Note_Normalized_Result.php'
    'src\Submission\Assessment\Domain\Student_Note_Output_Normalizer.php'
    'src\Submission\Assessment\Domain\Student_Note_Work_Item.php'
    'src\Submission\Assessment\Port\Student_Note_Repository_Port_Interface.php'
    'src\Submission\Assessment\Port\Student_Note_Work_Repository_Port_Interface.php'
    'src\Submission\Assessment\Port\Student_Note_Execution_Port_Interface.php'
    'src\Submission\Assessment\Port\Student_Note_Execution_Consent_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\Platform_Student_Note_Execution.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Execution_Consent.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Repository.php'
    'src\Submission\Assessment\Infrastructure\Student_Note_Worker_Module.php'
    'src\Submission\Assessment\Presentation\Student_Note_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Rest_Module.php'
    'tests\Contract\student-note-draft-g4-imp-05-s01.php'
    'tests\Contract\student-note-worker-g4-imp-05-s01.php'
)

foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$contracts = @(
    'tests\Contract\student-note-draft-g4-imp-05-s01.php'
    'tests\Contract\student-note-worker-g4-imp-05-s01.php'
)

foreach ($relative in $contracts) {
    & $php (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-05-S01 passed: non-identifying evidence, strict student-note output, idempotent enqueue, report authorization, consent, routing rechecks, and immutable excluded AI draft append are frozen.'
