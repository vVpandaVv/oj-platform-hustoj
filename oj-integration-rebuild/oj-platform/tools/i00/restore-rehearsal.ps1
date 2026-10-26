[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$MySqlPath,

    [Parameter(Mandatory = $true)]
    [string]$BackupDirectory,

    [Parameter(Mandatory = $true)]
    [string]$TargetHost,

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 65535)]
    [int]$TargetPort,

    [Parameter(Mandatory = $true)]
    [string]$TargetAdminUser,

    [Parameter(Mandatory = $true)]
    [Security.SecureString]$TargetAdminPassword,

    [string]$TargetWordPressDatabase = "oj_platform_wp_restore_test",

    [string]$TargetJudgeDatabase = "judge_test",

    [Parameter(Mandatory = $true)]
    [string]$Operator,

    [Parameter(Mandatory = $true)]
    [switch]$ConfirmedIsolatedTarget
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Assert-DatabaseIdentifier {
    param([string]$Value, [string]$Label)

    if ($Value -notmatch "^[A-Za-z0-9_]+$") {
        throw "$Label 값은 영문자, 숫자, 밑줄만 사용할 수 있습니다."
    }
}

function Assert-ConnectionToken {
    param([string]$Value, [string]$Label)

    if (-not $Value -or $Value -notmatch "^[A-Za-z0-9_.:@-]+$") {
        throw "$Label 값의 형식이 올바르지 않습니다."
    }
}

function ConvertFrom-SecureValue {
    param([Security.SecureString]$Value)

    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    }
    finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

function Invoke-MySqlQuery {
    param(
        [string]$ToolPath,
        [string]$HostName,
        [int]$Port,
        [string]$User,
        [Security.SecureString]$Password,
        [string]$Query,
        [string]$Database = ""
    )

    $plainPassword = ConvertFrom-SecureValue $Password
    $oldPassword = [Environment]::GetEnvironmentVariable("MYSQL_PWD", "Process")

    try {
        [Environment]::SetEnvironmentVariable("MYSQL_PWD", $plainPassword, "Process")
        $arguments = @(
            "--host=$HostName",
            "--port=$Port",
            "--user=$User",
            "--protocol=tcp",
            "--default-character-set=utf8mb4",
            "--batch",
            "--skip-column-names",
            "--execute=$Query"
        )
        if ($Database) {
            $arguments += "--database=$Database"
        }

        $output = & $ToolPath @arguments
        if ($LASTEXITCODE -ne 0) {
            throw "MySQL 조회 또는 명령 실행에 실패했습니다."
        }
        return @($output)
    }
    finally {
        [Environment]::SetEnvironmentVariable("MYSQL_PWD", $oldPassword, "Process")
        $plainPassword = $null
    }
}

function Invoke-MySqlRestore {
    param(
        [string]$ToolPath,
        [string]$HostName,
        [int]$Port,
        [string]$User,
        [Security.SecureString]$Password,
        [string]$Database,
        [string]$SqlPath
    )

    $plainPassword = ConvertFrom-SecureValue $Password
    $oldPassword = [Environment]::GetEnvironmentVariable("MYSQL_PWD", "Process")

    try {
        [Environment]::SetEnvironmentVariable("MYSQL_PWD", $plainPassword, "Process")
        $startInfo = [Diagnostics.ProcessStartInfo]::new()
        $startInfo.FileName = $ToolPath
        $startInfo.Arguments = @(
            "--host=$HostName",
            "--port=$Port",
            "--user=$User",
            "--protocol=tcp",
            "--default-character-set=utf8mb4",
            "--database=$Database"
        ) -join " "
        $startInfo.UseShellExecute = $false
        $startInfo.CreateNoWindow = $true
        $startInfo.RedirectStandardInput = $true
        $startInfo.RedirectStandardError = $true

        $process = [Diagnostics.Process]::new()
        $process.StartInfo = $startInfo
        if (-not $process.Start()) {
            throw "MySQL 복원 프로세스를 시작할 수 없습니다."
        }

        $reader = [IO.StreamReader]::new($SqlPath, [Text.UTF8Encoding]::new($false, $true))
        try {
            $buffer = New-Object char[] 65536
            while (($read = $reader.Read($buffer, 0, $buffer.Length)) -gt 0) {
                $process.StandardInput.Write($buffer, 0, $read)
            }
        }
        finally {
            $reader.Dispose()
            $process.StandardInput.Close()
        }

        $errorText = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        if ($process.ExitCode -ne 0) {
            throw "DB 복원에 실패했습니다: $Database. 비밀값을 제외한 MySQL 오류: $errorText"
        }
    }
    finally {
        [Environment]::SetEnvironmentVariable("MYSQL_PWD", $oldPassword, "Process")
        $plainPassword = $null
    }
}

if (-not $ConfirmedIsolatedTarget) {
    throw "운영과 분리된 복원 대상임을 명시적으로 확인해야 합니다."
}

if (-not (Test-Path -LiteralPath $MySqlPath -PathType Leaf)) {
    throw "mysql 또는 mariadb 클라이언트를 찾을 수 없습니다."
}

$resolvedBackup = (Resolve-Path -LiteralPath $BackupDirectory).Path
$manifestPath = Join-Path $resolvedBackup "backup-manifest.json"
$manifestHashPath = Join-Path $resolvedBackup "backup-manifest.sha256"
$wordpressDump = Join-Path $resolvedBackup "wordpress.sql"
$judgeDump = Join-Path $resolvedBackup "judge.sql"
foreach ($requiredFile in @($manifestPath, $manifestHashPath, $wordpressDump, $judgeDump)) {
    if (-not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw "필수 백업 파일을 찾을 수 없습니다: $requiredFile"
    }
}

$expectedManifestHash = (Get-Content -LiteralPath $manifestHashPath -Encoding ascii -Raw).Trim().ToLowerInvariant()
$actualManifestHash = (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
if ($actualManifestHash -ne $expectedManifestHash) {
    throw "백업 manifest 해시가 일치하지 않습니다."
}

$backupManifest = Get-Content -LiteralPath $manifestPath -Encoding utf8 -Raw | ConvertFrom-Json
if ($backupManifest.operation -ne "i00_backup" -or $backupManifest.schema_version -ne 1) {
    throw "I-00 백업 manifest 형식이 아닙니다."
}
if (-not $backupManifest.storage_encrypted -or $backupManifest.secrets_recorded -ne $false) {
    throw "암호화 저장 증거가 없는 백업은 복원 리허설에 사용할 수 없습니다."
}

$wordpressBackup = @($backupManifest.databases | Where-Object { $_.filename -eq "wordpress.sql" })
$judgeBackup = @($backupManifest.databases | Where-Object { $_.filename -eq "judge.sql" })
if ($wordpressBackup.Count -ne 1 -or $judgeBackup.Count -ne 1) {
    throw "WordPress와 Judge 백업 항목을 manifest에서 각각 하나씩 확인할 수 없습니다."
}

foreach ($backupFile in @(
    [pscustomobject]@{ entry = $wordpressBackup[0]; path = $wordpressDump }
    [pscustomobject]@{ entry = $judgeBackup[0]; path = $judgeDump }
)) {
    $actualHash = (Get-FileHash -LiteralPath $backupFile.path -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedHash = ([string]$backupFile.entry.sha256).ToLowerInvariant()
    $actualBytes = (Get-Item -LiteralPath $backupFile.path).Length
    if ($actualHash -ne $expectedHash -or $actualBytes -ne [long]$backupFile.entry.bytes) {
        throw "백업 파일의 크기 또는 SHA-256이 manifest와 일치하지 않습니다: $($backupFile.entry.filename)"
    }
}

$sourceWordPressHost = [string]$wordpressBackup[0].host
$sourceWordPressPort = [int]$wordpressBackup[0].port
$sourceWordPressDatabase = [string]$wordpressBackup[0].database
$sourceJudgeHost = [string]$judgeBackup[0].host
$sourceJudgePort = [int]$judgeBackup[0].port
$sourceJudgeDatabase = [string]$judgeBackup[0].database

Assert-ConnectionToken $TargetHost "복원 대상 호스트"
Assert-ConnectionToken $TargetAdminUser "복원 대상 사용자"
Assert-ConnectionToken $sourceWordPressHost "WordPress 원본 호스트"
Assert-ConnectionToken $sourceJudgeHost "Judge 원본 호스트"
foreach ($database in @($TargetWordPressDatabase, $TargetJudgeDatabase, $SourceWordPressDatabase, $SourceJudgeDatabase)) {
    Assert-DatabaseIdentifier $database "DB 이름"
}

if ($TargetWordPressDatabase -notmatch "_test$" -or $TargetJudgeDatabase -notmatch "_test$") {
    throw "복원 대상 DB 이름은 모두 _test로 끝나야 합니다."
}
if ($TargetWordPressDatabase -eq $TargetJudgeDatabase) {
    throw "WordPress 복원 DB와 Judge 복원 DB를 분리해야 합니다."
}

$targetServer = "{0}:{1}" -f $TargetHost.ToLowerInvariant(), $TargetPort
$sourceWordPressServer = "{0}:{1}" -f $SourceWordPressHost.ToLowerInvariant(), $SourceWordPressPort
$sourceJudgeServer = "{0}:{1}" -f $SourceJudgeHost.ToLowerInvariant(), $SourceJudgePort
if ($targetServer -eq $sourceWordPressServer -or $targetServer -eq $sourceJudgeServer) {
    throw "복원 대상 서버·포트는 두 운영 원본과 달라야 합니다."
}

$existing = Invoke-MySqlQuery `
    -ToolPath $MySqlPath `
    -HostName $TargetHost `
    -Port $TargetPort `
    -User $TargetAdminUser `
    -Password $TargetAdminPassword `
    -Query ("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ('{0}','{1}')" -f $TargetWordPressDatabase, $TargetJudgeDatabase)
if ($existing.Count -gt 0) {
    throw "복원 대상 DB가 이미 존재합니다. 자동 삭제나 덮어쓰기는 수행하지 않습니다: $($existing -join ', ')"
}

$startedAt = Get-Date
Invoke-MySqlQuery `
    -ToolPath $MySqlPath `
    -HostName $TargetHost `
    -Port $TargetPort `
    -User $TargetAdminUser `
    -Password $TargetAdminPassword `
    -Query ("CREATE DATABASE ``{0}`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE ``{1}`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" -f $TargetWordPressDatabase, $TargetJudgeDatabase) | Out-Null

Invoke-MySqlRestore `
    -ToolPath $MySqlPath `
    -HostName $TargetHost `
    -Port $TargetPort `
    -User $TargetAdminUser `
    -Password $TargetAdminPassword `
    -Database $TargetWordPressDatabase `
    -SqlPath $wordpressDump
Invoke-MySqlRestore `
    -ToolPath $MySqlPath `
    -HostName $TargetHost `
    -Port $TargetPort `
    -User $TargetAdminUser `
    -Password $TargetAdminPassword `
    -Database $TargetJudgeDatabase `
    -SqlPath $judgeDump

$wordpressTables = Invoke-MySqlQuery `
    -ToolPath $MySqlPath `
    -HostName $TargetHost `
    -Port $TargetPort `
    -User $TargetAdminUser `
    -Password $TargetAdminPassword `
    -Database $TargetWordPressDatabase `
    -Query "SHOW TABLES"
$judgeTables = Invoke-MySqlQuery `
    -ToolPath $MySqlPath `
    -HostName $TargetHost `
    -Port $TargetPort `
    -User $TargetAdminUser `
    -Password $TargetAdminPassword `
    -Database $TargetJudgeDatabase `
    -Query "SHOW TABLES"

$hasWordPressUsers = @($wordpressTables | Where-Object { $_ -match "_users$" }).Count -gt 0
$hasWordPressOptions = @($wordpressTables | Where-Object { $_ -match "_options$" }).Count -gt 0
$requiredJudgeTables = @(
    "problem",
    "solution",
    "source_code",
    "source_code_user",
    "compileinfo",
    "runtimeinfo",
    "contest",
    "contest_problem"
)
$missingJudgeTables = @($requiredJudgeTables | Where-Object { $_ -notin $judgeTables })
if (-not $hasWordPressUsers -or -not $hasWordPressOptions -or $missingJudgeTables.Count -gt 0) {
    throw "복원 후 핵심 테이블 검증에 실패했습니다."
}

$completedAt = Get-Date
$evidence = [ordered]@{
    schema_version = 1
    operation = "i00_restore_rehearsal"
    started_at = $startedAt.ToUniversalTime().ToString("o")
    completed_at = $completedAt.ToUniversalTime().ToString("o")
    duration_seconds = [Math]::Round(($completedAt - $startedAt).TotalSeconds, 3)
    operator = $Operator
    isolated_target_confirmed = $true
    source_backup_identifier = Split-Path -Leaf $resolvedBackup
    source_manifest_sha256 = $actualManifestHash
    target = [ordered]@{
        host = $TargetHost
        port = $TargetPort
        wordpress_database = $TargetWordPressDatabase
        judge_database = $TargetJudgeDatabase
    }
    verification = [ordered]@{
        wordpress_table_count = $wordpressTables.Count
        wordpress_users_table_present = $hasWordPressUsers
        wordpress_options_table_present = $hasWordPressOptions
        judge_table_count = $judgeTables.Count
        required_judge_tables = $requiredJudgeTables
        missing_judge_tables = $missingJudgeTables
    }
    secrets_recorded = $false
    automatic_cleanup_performed = $false
}
$evidencePath = Join-Path $resolvedBackup "restore-rehearsal.json"
$evidenceJson = $evidence | ConvertTo-Json -Depth 10
[IO.File]::WriteAllText(
    $evidencePath,
    $evidenceJson + [Environment]::NewLine,
    [Text.UTF8Encoding]::new($false)
)
$evidenceHash = (Get-FileHash -LiteralPath $evidencePath -Algorithm SHA256).Hash.ToLowerInvariant()
Set-Content -LiteralPath (Join-Path $resolvedBackup "restore-rehearsal.sha256") -Value $evidenceHash -Encoding ascii

Write-Output "I-00 격리 복원 리허설이 완료되었습니다."
Write-Output ("복원 증거 SHA-256: {0}" -f $evidenceHash)
Write-Output "검증용 DB는 자동 삭제하지 않았습니다."
