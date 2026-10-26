param()

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

$runtimeFiles = @(
    (Join-Path $root 'src\Reporting\Export\Application\Export_Download.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Job.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Artifact.php')
    (Join-Path $root 'src\Reporting\Export\Application\Export_Access_Guard.php')
    (Join-Path $root 'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php')
    (Join-Path $root 'src\Reporting\Export\Presentation\Export_Rest_Controller.php')
    (Join-Path $root 'src\Reporting\ResultPdf\Domain\Result_Pdf_Request_Snapshot.php')
    (Join-Path $root 'src\Submission\Assessment\Infrastructure\Submission_Result_Object_Authorization.php')
    (Join-Path $root 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Query.php')
)
$contract = Join-Path $root 'tests\Contract\result-pdf-access-g4-imp-07-s03.php'

foreach ($file in @($runtimeFiles + $contract)) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath $_ -Raw }) -join "`n"
$forbiddenRuntimePrefix = '$wpdb' + '->prefix'
$legacyTablePrefix = 'wp_' + 'oj_ai_'
if ($runtimeSource.Contains($forbiddenRuntimePrefix) -or $runtimeSource.Contains($legacyTablePrefix)) {
    throw 'Forbidden add-on table prefix marker found in G4-IMP-07-S03 runtime.'
}
if ($runtimeSource.Contains('CodingPanda\OjPlatform\Kernel\')) {
    throw 'G4-IMP-07-S03 must not depend on OJ Platform internals.'
}

$download = Get-Content -LiteralPath (Join-Path $root 'src\Reporting\Export\Application\Export_Download.php') -Raw
foreach ($marker in @("'result_pdf'", "'application/pdf'", '104_857_600')) {
    if (-not $download.Contains($marker)) { throw "Missing result PDF download profile marker: $marker" }
}

$controller = Get-Content -LiteralPath (Join-Path $root 'src\Reporting\Export\Presentation\Export_Rest_Controller.php') -Raw
foreach ($marker in @("'self_release'", 'same_download', 'verified_handle', 'LOCK_SH', "hash_init( 'sha256' )", 'read_artifact->execute')) {
    if (-not $controller.Contains($marker)) { throw "Missing result PDF pre-stream marker: $marker" }
}
if (([regex]::Matches($controller, 'read_artifact->execute')).Count -lt 2) {
    throw 'Result PDF download must re-read and reauthorize the artifact before stream bytes.'
}

$query = Get-Content -LiteralPath (Join-Path $root 'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php') -Raw
foreach ($marker in @('requested_by_user_id = %d', "'submission_release'", "'result_pdf'", 'Result_Pdf_Request_Snapshot::restore', "'self_release'")) {
    if (-not $query.Contains($marker)) { throw "Missing result PDF Q23/Q24 query marker: $marker" }
}

$objectPolicy = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Infrastructure\Submission_Result_Object_Authorization.php') -Raw
foreach ($marker in @("'export_pdf'", "'self_release'", 'authorization_snapshot')) {
    if (-not $objectPolicy.Contains($marker)) { throw "Missing current result object authorization marker: $marker" }
}

$resultQuery = Get-Content -LiteralPath (Join-Path $root 'src\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Query.php') -Raw
foreach ($marker in @('current_release_public_id = release_row.public_id', 'current_release_hash = release_row.content_hash', 'learner_user_id = %d')) {
    if (-not $resultQuery.Contains($marker)) { throw "Missing current release ownership marker: $marker" }
}

& $php $contract
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'Student result Q23/Q24 revocation and pre-stream integrity G4-IMP-07-S03 passed.'
