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
            throw "G5-IMP-08-S05 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-purge-execution-g5-imp-08-s04.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\Purge\Application\Read_Purge_Job.php',
    'src\Operations\Purge\Application\Read_Purge_Job_Result.php',
    'src\Operations\Purge\Application\Purge_Failure.php',
    'src\Operations\Purge\Port\Purge_Job_Query_Port_Interface.php',
    'src\Operations\Purge\Infrastructure\WordPress_Purge_Job_Query.php',
    'src\Operations\Purge\Presentation\Purge_Job_Query_Rest_Controller.php',
    'src\Operations\Purge\Presentation\Purge_Job_Query_Rest_Module.php',
    'src\Security\Policy\Access_Policy.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\purge-job-query-g5-imp-08-s05.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -notlike 'tests\*' }
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-08-S05 runtime contains a forbidden table prefix: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-08-S05 runtime references an OJ Platform implementation namespace: $file"
    }
}

$querySource = Get-Content -LiteralPath (Join-Path $root 'src\Operations\Purge\Infrastructure\WordPress_Purge_Job_Query.php') -Raw -Encoding UTF8
foreach ($forbidden in @('INSERT ', 'UPDATE ', 'DELETE ', 'DROP ', 'ALTER ', 'CREATE ', 'TRUNCATE ', 'RENAME ')) {
    if ($querySource.ToUpperInvariant().Contains($forbidden)) {
        throw "G5-IMP-08-S05 Q36 query contains a write marker: $forbidden"
    }
}

Assert-Markers 'src\Operations\Purge\Infrastructure\WordPress_Purge_Job_Query.php' @(
    "TABLE_JOBS = 'oj_ai_purge_jobs'"
    "TABLE_PRIVATE_FILES = 'oj_ai_private_files'"
    'legal_hold_until_utc IS NOT NULL'
    'purged_at_utc IS NULL'
    'WHERE j.public_id = %s LIMIT 1'
    '$db->get_row('
) 'single-statement literal-table query'
Assert-Markers 'src\Operations\Purge\Application\Read_Purge_Job.php' @(
    'ACTION_READ'
    'APPROVAL_TTL_SECONDS'
    'expected_row_count( $preview )'
    "array( 'approved', 'running', 'cancelled', 'completed', 'failed' )"
    'JOB_PROFILE_UNSUPPORTED'
    'JOB_DATA_INVALID'
    "'blocks_execution' =>"
) 'canonical safe projection'
Assert-Markers 'src\Operations\Purge\Presentation\Purge_Job_Query_Rest_Controller.php' @(
    '/purge-jobs/(?P<job_id>[0-9A-HJKMNP-TV-Z]{26})'
    "'methods' => 'GET'"
    'Addon_Capabilities::MANAGE_MIGRATIONS'
    "'mutation_count' => 0"
    "'platform_write_count' => 0"
    "'student_visible' => false"
) 'Q36 REST contract'
if ($querySource.Contains('CORE_DELETE_PLUGINS')) {
    throw 'G5-IMP-08-S05 Q36 unexpectedly requires delete_plugins.'
}
Assert-Markers 'src\Security\Policy\Access_Policy.php' @(
    "'operations.purge.read'"
    'authorize_purge_recovery'
) 'recovery policy'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'Purge_Job_Query_Rest_Controller'
    'Purge_Job_Query_Rest_Module'
    'WordPress_Purge_Job_Query'
    'Read_Purge_Job'
) 'production composition'
Assert-Markers 'tests\Contract\purge-job-query-g5-imp-08-s05.php' @(
    'Q36 must use one consistent read statement.'
    'Q36 accepted checkpoint expectations that differ from the approved preview.'
    'Q36 accepted a truncated database count.'
    'Q36 exposed checkpoint internals:'
    'Q36 must remain GET.'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-08-s05-purge-job-query.html' @(
    'IMP-08 5/6'
    'check:purge-job-query-g5'
    'GET /oj-ai/v1/purge-jobs/{job_id}'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-08-s05-purge-job-query.html'
    'IMP-08 5/6'
    'G5-IMP-08-S06'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'G5-IMP-08-S05 Q36'
    'check:purge-job-query-g5'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\a-ds-08-route-rest-command.html' @(
    'A-DS-08 Amendment 24'
    'g5-imp-08-s05-purge-job-query.html'
    'Page 9'
    'GET 38'
    'POST 79'
) 'A-DS-08 synchronization'
Assert-Markers 'docs\ai-assessment\a-ds-05-addon-database.html' @(
    'A-DS-05 Amendment 12'
    'Q36'
    'A-DS05-AM12-03'
) 'A-DS-05 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R124'
    'G5-IMP-08-S05'
    'check:purge-job-query-g5'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:purge-job-query-g5'
    'check-purge-job-query-g5-imp-08-s05.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-purge-job-query-g5-imp-08-s05.ps1'
    'Purge job Q36 status G5-IMP-08-S05 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\purge-job-query-g5-imp-08-s05.php'))

Write-Output 'G5-IMP-08-S05 passed: Q36 reads one literal-table snapshot, validates preview and checkpoint integrity, exposes approval expiry and current legal holds, and performs zero mutations.'
