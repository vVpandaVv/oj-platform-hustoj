[CmdletBinding()]
param(
    [switch]$SkipConstituentChecks
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'php-source-markers.ps1')
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

function Invoke-Checked([string]$Command, [string[]]$Arguments) {
    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed ($LASTEXITCODE): $Command $($Arguments -join ' ')"
    }
}

function Assert-Markers([string]$Path, [string[]]$Markers, [string]$Label) {
    $source = Get-Content -LiteralPath (Join-Path $root $Path) -Raw -Encoding UTF8
    foreach ($marker in $Markers) {
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) {
            throw "G5-IMP-08-S06 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-uninstall-data-preservation-g5-imp-08-s01.ps1')
    )
    foreach ($gate in @(
        'check-purge-preview-g5-imp-08-s02.ps1',
        'check-purge-approval-g5-imp-08-s03.ps1',
        'check-purge-execution-g5-imp-08-s04.ps1',
        'check-purge-job-query-g5-imp-08-s05.ps1'
    )) {
        Invoke-Checked 'powershell' @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot $gate), '-SkipConstituentChecks'
        )
    }
}

$phpFiles = @(
    'src\Operations\Purge\Infrastructure\Signed_Purge_Preview_Token.php',
    'src\Operations\Purge\Application\Approve_Purge.php',
    'src\Operations\Purge\Application\Execute_Purge.php',
    'src\Operations\Purge\Application\Read_Purge_Job.php',
    'src\Operations\Purge\Domain\Purge_Target_Catalog.php',
    'src\Operations\Purge\Infrastructure\WordPress_Purge_Operation_Repository.php',
    'tests\Contract\purge-integration-freeze-g5-imp-08-s06.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = Get-ChildItem -LiteralPath (Join-Path $root 'src\Operations\Purge') -Recurse -File -Filter '*.php'
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath $file.FullName -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-08-S06 crossed a frozen table or platform boundary: $($file.FullName) -> $forbidden"
        }
    }
}

Assert-Markers 'src\Operations\Purge\Application\Execute_Purge.php' @(
    '$now_time >= $expires_time'
    '$this->assert_approval( $job, $started_at_utc )'
    '$this->assert_deletion_ready( $stored_preview )'
    '$this->assert_deletion_ready( $current_preview )'
    '$result = $this->repository->start('
) 'C73 exact expiry and revalidation order'
Assert-Markers 'src\Operations\Purge\Application\Read_Purge_Job.php' @(
    '$this->now() >= $expires'
    "'status' =>"
) 'Q36 exact expiry projection'
Assert-Markers 'src\Operations\Purge\Infrastructure\WordPress_Purge_Operation_Repository.php' @(
    "0 === `$postcondition['remaining_rows']"
    "0 === `$postcondition['remaining_files']"
    "0 === `$postcondition['remaining_blob_bytes']"
    "`$terminal_state = `$verified ? 'completed' : 'failed'"
    '$this->purge_private_files('
    '$this->purge_tables('
) 'final zero and private-byte ordering'
Assert-Markers 'tests\Contract\purge-integration-freeze-g5-imp-08-s06.php' @(
    'Expired preview token was accepted at the exact boundary.'
    'Fresh preview token could not enter reapproval.'
    'exact 24-hour boundary'
    'C72 accepted a legal hold introduced after preview.'
    'C73 protected ledger set drifted.'
    'G5-IMP-08-S06 passed:'
) 'integration contract'
Assert-Markers 'docs\ai-assessment\g5-imp-08-s06-purge-integration-freeze.html' @(
    '<title>G5-IMP-08-S06'
    'id="result"'
    'IMP-08 6/6'
    'G5 8/8'
    'check:purge-integration-g5'
    'Platform API change: 0'
    'G6-IMP-01'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-08-s06-purge-integration-freeze.html'
    'IMP-08 6/6'
    'G5 8/8'
    'check:purge-integration-g5'
    'G6-IMP-01'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\design-procedure.html' @(
    'g5-imp-08-s06-purge-integration-freeze.html'
    'IMP-08 6/6'
    'G5 8/8'
) 'design procedure'
Assert-Markers 'docs\ai-assessment\index.html' @(
    'g5-imp-08-s06-purge-integration-freeze.html'
    'check:purge-integration-g5'
    'G5 8/8'
) 'document index'
Assert-Markers 'docs\ai-assessment\a-ds-05-addon-database.html' @(
    'A-DS-05 Amendment 13'
    'A-DS05-AM13-03'
    'A-DS05-AM13-02'
) 'A-DS-05 synchronization'
Assert-Markers 'docs\ai-assessment\a-ds-08-route-rest-command.html' @(
    'A-DS-08 Amendment 25'
    'g5-imp-08-s06-purge-integration-freeze.html'
    'Page 9'
    'GET 38'
    'POST 79'
) 'A-DS-08 synchronization'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g5-imp-08-s06-purge-integration-freeze.html'
    'check:purge-integration-g5'
    'G5 8/8'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-26-R125-OBSERVED'
    'G5-IMP-08-S06'
    'check:purge-integration-g5'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:purge-integration-g5'
    'check-purge-integration-g5-imp-08-s06.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-purge-integration-g5-imp-08-s06.ps1'
    'Purge integration G5-IMP-08-S06 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\purge-integration-freeze-g5-imp-08-s06.php'))

Write-Output 'G5-IMP-08-S06 passed: S01-S05 preservation and purge contracts, exact expiry and backup boundaries, legal holds, protected ledgers, private-byte ordering, and final zero verification are frozen.'
