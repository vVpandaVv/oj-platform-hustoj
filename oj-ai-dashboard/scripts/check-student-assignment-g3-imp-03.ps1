param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = if (Test-Path -LiteralPath 'D:\php\php.exe') { 'D:\php\php.exe' } else { (Get-Command php -ErrorAction Stop).Source }
$phpFiles = @(
    'src\Submission\Student\Port\Learning_Context_Query_Port_Interface.php',
    'src\Submission\Student\Port\Student_Assignment_Access_Port_Interface.php',
    'src\Submission\Student\Port\Student_Assignment_Repository_Port_Interface.php',
    'src\Submission\Student\Application\Read_Student_Assignments.php',
    'src\Submission\Student\Application\Read_Own_Submission.php',
    'src\Submission\Student\Domain\Submission_Revision_Integrity.php',
    'src\Submission\Student\Domain\Submission_Save_Token.php',
    'src\Submission\Student\Domain\Submission_Attachment_Upload_Profile.php',
    'src\Submission\Student\Domain\Submission_Response_Validator.php',
    'src\Submission\Student\Application\Start_Own_Submission_Command.php',
    'src\Submission\Student\Application\Start_Own_Submission_Result.php',
    'src\Submission\Student\Application\Start_Own_Submission.php',
    'src\Submission\Student\Application\Autosave_Own_Submission_Command.php',
    'src\Submission\Student\Application\Autosave_Own_Submission_Result.php',
    'src\Submission\Student\Application\Autosave_Own_Submission.php',
    'src\Submission\Student\Application\Submit_Own_Submission_Command.php',
    'src\Submission\Student\Application\Submit_Own_Submission_Result.php',
    'src\Submission\Student\Application\Submit_Own_Submission.php',
    'src\Submission\Student\Application\Stage_Own_Attachment_Command.php',
    'src\Submission\Student\Application\Stage_Own_Attachment_Result.php',
    'src\Submission\Student\Application\Stage_Own_Attachment.php',
    'src\Submission\Student\Application\Remove_Own_Staged_Attachment_Command.php',
    'src\Submission\Student\Application\Remove_Own_Staged_Attachment_Result.php',
    'src\Submission\Student\Application\Remove_Own_Staged_Attachment.php',
    'src\Submission\Student\Application\Run_Auto_Submit_Due.php',
    'src\Submission\Student\Application\Run_Auto_Submit_Due_Result.php',
    'src\Submission\Student\Application\Run_Next_Submission_Attachment_Cleanup.php',
    'src\Submission\Student\Application\Run_Next_Submission_Attachment_Scan.php',
    'src\Submission\Student\Application\Run_Submission_Attachment_Cleanup_Result.php',
    'src\Submission\Student\Application\Run_Submission_Attachment_Scan_Result.php',
    'src\Submission\Student\Domain\Auto_Submit_Assignment_Work_Item.php',
    'src\Submission\Student\Domain\Submission_Attachment_Cleanup_Item.php',
    'src\Submission\Student\Domain\Submission_Attachment_Content_Inspector.php',
    'src\Submission\Student\Domain\Submission_Attachment_Content_Verdict.php',
    'src\Submission\Student\Domain\Submission_Attachment_Malware_Verdict.php',
    'src\Submission\Student\Domain\Submission_Attachment_Scan_Item.php',
    'src\Submission\Student\Port\Student_Attachment_Repository_Port_Interface.php',
    'src\Submission\Student\Port\Student_Attachment_Removal_Port_Interface.php',
    'src\Submission\Student\Port\Student_Attachment_Scan_Repository_Port_Interface.php',
    'src\Submission\Student\Port\Auto_Submit_Assignment_Repository_Port_Interface.php',
    'src\Submission\Student\Port\Auto_Submission_Commit_Repository_Port_Interface.php',
    'src\Submission\Student\Port\Student_Submission_Commit_Repository_Port_Interface.php',
    'src\Submission\Student\Port\Student_Attachment_Cleanup_Repository_Port_Interface.php',
    'src\Submission\Student\Port\Submission_Attachment_Malware_Scanner_Port_Interface.php',
    'src\Submission\Student\Infrastructure\Platform_Learning_Context_Query.php',
    'src\Submission\Student\Infrastructure\Clamav_Instream_Submission_Attachment_Scanner.php',
    'src\Submission\Student\Infrastructure\Auto_Submit_Due_Worker_Module.php',
    'src\Submission\Student\Infrastructure\Submission_Attachment_Cleanup_Worker_Module.php',
    'src\Submission\Student\Infrastructure\Submission_Attachment_Scan_Worker_Module.php',
    'src\Submission\Student\Infrastructure\WordPress_Student_Assignment_Repository.php',
    'src\Submission\Student\Infrastructure\WordPress_Auto_Submit_Assignment_Repository.php',
    'src\Submission\Student\Infrastructure\WordPress_Student_Attachment_Cleanup_Repository.php',
    'src\Submission\Student\Infrastructure\WordPress_Student_Attachment_Scan_Repository.php',
    'src\Submission\Student\Infrastructure\WordPress_Student_Submission_Query.php',
    'src\Submission\Student\Infrastructure\WordPress_Student_Submission_Autosave.php',
    'src\Submission\Student\Infrastructure\WordPress_Student_Submission_Commit_Repository.php',
    'src\Submission\Student\Infrastructure\WordPress_Student_Attachment_Repository.php',
    'src\Submission\Student\Infrastructure\Submission_Object_Authorization.php',
    'src\Submission\Student\Presentation\Student_Assignment_Rest_Controller.php',
    'src\Submission\Student\Presentation\Student_Assignment_Rest_Module.php',
    'src\Submission\Teacher\Port\Teacher_Submission_Query_Port_Interface.php',
    'src\Submission\Teacher\Port\Teacher_Submission_Control_Repository_Port_Interface.php',
    'src\Submission\Teacher\Application\Control_Teacher_Submission_Command.php',
    'src\Submission\Teacher\Application\Control_Teacher_Submission_Result.php',
    'src\Submission\Teacher\Application\Control_Teacher_Submission.php',
    'src\Submission\Teacher\Application\Read_Teacher_Submissions.php',
    'src\Submission\Teacher\Infrastructure\WordPress_Teacher_Submission_Control_Repository.php',
    'src\Submission\Teacher\Infrastructure\WordPress_Teacher_Submission_Query.php',
    'src\Submission\Teacher\Presentation\Teacher_Submission_Rest_Controller.php',
    'src\Submission\Teacher\Presentation\Teacher_Submission_Rest_Module.php',
    'src\Submission\Assignment\Infrastructure\Assignment_Object_Authorization.php',
    'src\Platform\Student_Assignment_Page_Handler.php',
    'src\Platform\Assignment_Workbench_Page_Handler.php',
    'src\Platform\Addon_Manifest.php',
    'src\Platform\Addon_Integration.php',
    'src\Storage\Port\Private_File_Staging_Port_Interface.php',
    'src\Storage\Infrastructure\WordPress_Private_File_Staging_Repository.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\student-assignment-g3-imp-03.php',
    'tests\Contract\student-submission-q32-g3-imp-04-s02.php',
    'tests\Contract\student-submission-autosave-g3-imp-04-s03.php',
    'tests\Contract\student-submission-attachment-stage-g3-imp-05-s01.php',
    'tests\Contract\student-submission-attachment-remove-g3-imp-05-s02.php',
    'tests\Contract\student-submission-attachment-scan-g3-imp-05-s03.php',
    'tests\Contract\student-submission-attachment-cleanup-g3-imp-05-s04.php',
    'tests\Contract\student-submission-submit-g3-imp-06-s01.php',
    'tests\Contract\student-auto-submit-due-g3-imp-06-r01.php',
    'tests\Contract\teacher-submission-query-g3-imp-06-s02.php',
    'tests\Contract\teacher-submission-control-g3-imp-06-s03.php',
    'tests\Contract\submission-screen-audit-g3-imp-06-s04.php',
    'tests\Contract\submission-concurrency-regression-g3-imp-06-s05.php'
)

foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $phpFiles | Where-Object { -not $_.StartsWith('tests\') }
$forbiddenRuntimePrefix = '$wpdb' + '->prefix'
$legacyTablePrefix = 'wp_' + 'oj_ai_'
foreach ($relative in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $relative) -Raw
    if ($source.Contains($forbiddenRuntimePrefix) -or $source.Contains($legacyTablePrefix)) {
        throw "Forbidden add-on table prefix marker found in $relative"
    }
    if ($source.Contains('CodingPanda\OjPlatform\Kernel\')) {
        throw "Platform internal namespace found in $relative"
    }
}

& $php (Join-Path $root 'tests\Contract\student-assignment-g3-imp-03.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\student-submission-q32-g3-imp-04-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\student-submission-autosave-g3-imp-04-s03.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\student-submission-attachment-stage-g3-imp-05-s01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\student-submission-attachment-remove-g3-imp-05-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\student-submission-attachment-scan-g3-imp-05-s03.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\student-submission-attachment-cleanup-g3-imp-05-s04.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\student-submission-submit-g3-imp-06-s01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\student-auto-submit-due-g3-imp-06-r01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\teacher-submission-query-g3-imp-06-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\teacher-submission-control-g3-imp-06-s03.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\submission-screen-audit-g3-imp-06-s04.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\submission-concurrency-regression-g3-imp-06-s05.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node --check (Join-Path $root 'resources\js\assignment-workbench.js')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node --check (Join-Path $root 'scripts\e2e-assignment-lifecycle.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node (Join-Path $root 'scripts\e2e-assignment-lifecycle.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node --check (Join-Path $root 'resources\js\student-assignments.js')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node --check (Join-Path $root 'scripts\e2e-student-assignments.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node (Join-Path $root 'scripts\e2e-student-assignments.mjs')
& node --check (Join-Path $root 'resources\js\student-submission-autosave.js')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
exit $LASTEXITCODE
