[CmdletBinding()]
param(
    [string]$OutputPath = "",
    [string]$PhpPath = "D:\php\php.exe",
    [string]$WordPressRoot = "",
    [string]$MySqlPath = "",
    [string]$MySqlDumpPath = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Resolve-ToolPath {
    param(
        [string]$ExplicitPath,
        [string[]]$CommandNames
    )

    if ($ExplicitPath -and (Test-Path -LiteralPath $ExplicitPath -PathType Leaf)) {
        return (Resolve-Path -LiteralPath $ExplicitPath).Path
    }

    foreach ($name in $CommandNames) {
        $command = Get-Command $name -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($null -ne $command) {
            return $command.Source
        }
    }

    return ""
}

function New-Check {
    param(
        [string]$Key,
        [string]$Label,
        [bool]$Ready,
        [string]$Evidence,
        [bool]$Required
    )

    [ordered]@{
        key = $Key
        label = $Label
        ready = $Ready
        evidence = $Evidence
        required = $Required
    }
}

$scriptPath = $MyInvocation.MyCommand.Path
$pluginRoot = (Resolve-Path -LiteralPath (Join-Path (Split-Path -Parent $scriptPath) "..\..")).Path
$repositoryRoot = (Resolve-Path -LiteralPath (Join-Path $pluginRoot "..")).Path

if (-not $OutputPath) {
    $OutputPath = Join-Path $pluginRoot "docs\artifacts\i-00\recovery-readiness.json"
}

$resolvedPhp = Resolve-ToolPath -ExplicitPath $PhpPath -CommandNames @("php")
$resolvedMySql = Resolve-ToolPath -ExplicitPath $MySqlPath -CommandNames @("mysql", "mariadb")
$resolvedMySqlDump = Resolve-ToolPath -ExplicitPath $MySqlDumpPath -CommandNames @("mysqldump", "mariadb-dump")
$resolvedDocker = Resolve-ToolPath -ExplicitPath "" -CommandNames @("docker")
$resolvedNode = Resolve-ToolPath -ExplicitPath "" -CommandNames @("node")
$resolvedNpm = Resolve-ToolPath -ExplicitPath "" -CommandNames @("npm.cmd", "npm")
$wpEnvConfig = Join-Path $repositoryRoot ".wp-env.json"
$wpEnvBinary = Join-Path $repositoryRoot "node_modules\.bin\wp-env.cmd"
$wpConfig = if ($WordPressRoot) { Join-Path $WordPressRoot "wp-config.php" } else { "" }

$pdoDrivers = @()
if ($resolvedPhp) {
    $driverOutput = & $resolvedPhp -r "echo implode(',', PDO::getAvailableDrivers());"
    if ($LASTEXITCODE -eq 0 -and $driverOutput) {
        $pdoDrivers = @($driverOutput -split "," | Where-Object { $_ })
    }
}

$requiredVariables = @(
    "OJ_I00_WP_DB_HOST",
    "OJ_I00_WP_DB_PORT",
    "OJ_I00_WP_DB_NAME",
    "OJ_I00_WP_DB_USER",
    "OJ_I00_JUDGE_DB_HOST",
    "OJ_I00_JUDGE_DB_PORT",
    "OJ_I00_JUDGE_DB_NAME",
    "OJ_I00_JUDGE_DB_USER",
    "OJ_I00_JUDGE_TEST_HOST",
    "OJ_I00_JUDGE_TEST_PORT",
    "OJ_I00_JUDGE_TEST_DB_NAME",
    "OJ_I00_JUDGE_TEST_DB_USER"
)
$configuredVariables = @($requiredVariables | Where-Object { [Environment]::GetEnvironmentVariable($_) })
$missingVariables = @($requiredVariables | Where-Object { -not [Environment]::GetEnvironmentVariable($_) })
$wordpressRuntimeReady = [bool]($wpConfig -and (Test-Path -LiteralPath $wpConfig -PathType Leaf))
$databaseInputEvidence = "설정 {0}개, 미설정 {1}개" -f $configuredVariables.Count, $missingVariables.Count

$checks = @(
    [pscustomobject][ordered]@{ key = "php"; label = "PHP CLI"; ready = [bool]$resolvedPhp; evidence = $resolvedPhp; required = $true }
    [pscustomobject][ordered]@{ key = "php_mysql_driver"; label = "PHP MySQL 드라이버"; ready = $pdoDrivers -contains "mysql"; evidence = (($pdoDrivers -join ",") -replace "^$", "없음"); required = $false }
    [pscustomobject][ordered]@{ key = "node"; label = "Node.js"; ready = [bool]$resolvedNode; evidence = $resolvedNode; required = $true }
    [pscustomobject][ordered]@{ key = "npm"; label = "npm"; ready = [bool]$resolvedNpm; evidence = $resolvedNpm; required = $true }
    [pscustomobject][ordered]@{ key = "docker"; label = "Docker"; ready = [bool]$resolvedDocker; evidence = ($resolvedDocker -replace "^$", "없음"); required = $true }
    [pscustomobject][ordered]@{ key = "wp_env_config"; label = "wp-env 구성"; ready = Test-Path -LiteralPath $wpEnvConfig -PathType Leaf; evidence = $wpEnvConfig; required = $true }
    [pscustomobject][ordered]@{ key = "wp_env_binary"; label = "wp-env 실행 파일"; ready = Test-Path -LiteralPath $wpEnvBinary -PathType Leaf; evidence = $wpEnvBinary; required = $true }
    [pscustomobject][ordered]@{ key = "mysql_client"; label = "MySQL/MariaDB 클라이언트"; ready = [bool]$resolvedMySql; evidence = ($resolvedMySql -replace "^$", "없음"); required = $true }
    [pscustomobject][ordered]@{ key = "mysql_dump"; label = "MySQL/MariaDB 덤프 도구"; ready = [bool]$resolvedMySqlDump; evidence = ($resolvedMySqlDump -replace "^$", "없음"); required = $true }
    [pscustomobject][ordered]@{ key = "wordpress_runtime"; label = "운영 WordPress 경로"; ready = $wordpressRuntimeReady; evidence = ($wpConfig -replace "^$", "지정되지 않음"); required = $true }
    [pscustomobject][ordered]@{ key = "database_inputs"; label = "DB 연결 입력"; ready = $missingVariables.Count -eq 0; evidence = $databaseInputEvidence; required = $true }
)

$requiredChecks = @($checks | Where-Object { $_.required })
$ready = @($requiredChecks | Where-Object { -not $_.ready }).Count -eq 0
$result = [ordered]@{
    schema_version = 1
    checked_at = (Get-Date).ToUniversalTime().ToString("o")
    read_only = $true
    ready_for_backup_and_restore = $ready
    checks = $checks
    pdo_drivers = $pdoDrivers
    configured_environment_variable_names = $configuredVariables
    missing_environment_variable_names = $missingVariables
    secrets_recorded = $false
}

$directory = Split-Path -Parent $OutputPath
if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
    New-Item -ItemType Directory -Path $directory -Force | Out-Null
}

$json = ($result | ConvertTo-Json -Depth 8) -replace "`r`n", "`n"
[IO.File]::WriteAllText(
    $OutputPath,
    $json + "`n",
    [Text.UTF8Encoding]::new($false)
)

if ($ready) {
    Write-Output "I-00 DB 백업·복원 실행 준비가 완료되었습니다."
    exit 0
}

Write-Output "I-00 DB 백업·복원 준비가 완료되지 않았습니다."
$checks | Where-Object { $_.required -and -not $_.ready } | ForEach-Object {
    Write-Output ("- {0}: {1}" -f $_.label, $_.evidence)
}
exit 2
