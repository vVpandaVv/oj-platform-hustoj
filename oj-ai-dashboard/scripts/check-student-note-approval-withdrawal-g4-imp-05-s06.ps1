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
    'src\Submission\Assessment\Application\Withdraw_Student_Note_Approval_Command.php'
    'src\Submission\Assessment\Application\Withdraw_Student_Note_Approval.php'
    'src\Submission\Assessment\Application\Student_Note_Revision_Result.php'
    'src\Submission\Assessment\Port\Student_Note_Approval_Withdrawal_Repository_Port_Interface.php'
    'src\Submission\Assessment\Infrastructure\WordPress_Student_Note_Review_Repository.php'
    'src\Submission\Assessment\Presentation\Student_Note_Approval_Withdrawal_Rest_Controller.php'
    'src\Submission\Assessment\Presentation\Student_Note_Approval_Withdrawal_Rest_Module.php'
    'tests\Contract\student-note-approval-withdrawal-g4-imp-05-s06.php'
    'tests\Contract\student-note-approval-withdrawal-storage-g4-imp-05-s06.php'
)

foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$contracts = @(
    'tests\Contract\student-note-approval-withdrawal-g4-imp-05-s06.php'
    'tests\Contract\student-note-approval-withdrawal-storage-g4-imp-05-s06.php'
)

foreach ($relative in $contracts) {
    & $php (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Output 'G4-IMP-05-S06 passed: student-note approval withdrawal, immutable append, CAS, provenance, durable replay, privacy, and rollback are frozen.'
