[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$MySqlDumpPath,

    [Parameter(Mandatory = $true)]
    [string]$OutputRoot,

    [Parameter(Mandatory = $true)]
    [string]$WordPressHost,

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 65535)]
    [int]$WordPressPort,

    [Parameter(Mandatory = $true)]
    [string]$WordPressDatabase,

    [Parameter(Mandatory = $true)]
    [string]$WordPressUser,

    [Parameter(Mandatory = $true)]
    [Security.SecureString]$WordPressPassword,

    [Parameter(Mandatory = $true)]
    [string]$JudgeHost,

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 65535)]
    [int]$JudgePort,

    [Parameter(Mandatory = $true)]
    [string]$JudgeDatabase,

    [Parameter(Mandatory = $true)]
    [string]$JudgeUser,

    [Parameter(Mandatory = $true)]
    [Security.SecureString]$JudgePassword,

    [string[]]$ProtectedFilePaths = @(),

    [Parameter(Mandatory = $true)]
    [string]$Operator,

    [Parameter(Mandatory = $true)]
    [switch]$EncryptedStorage
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

function Invoke-DatabaseDump {
    param(
        [string]$ToolPath,
        [string]$HostName,
        [int]$Port,
        [string]$Database,
        [string]$User,
        [Security.SecureString]$Password,
        [string]$Destination,
        [string]$ErrorLog
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
            "--single-transaction",
            "--quick",
            "--routines",
            "--triggers",
            "--events",
            "--hex-blob",
            "--no-tablespaces",
            $Database
        )
        $process = Start-Process `
            -FilePath $ToolPath `
            -ArgumentList $arguments `
            -RedirectStandardOutput $Destination `
            -RedirectStandardError $ErrorLog `
            -WindowStyle Hidden `
            -Wait `
            -PassThru

        if ($process.ExitCode -ne 0) {
            throw "데이터베이스 '$Database' 덤프에 실패했습니다. 비밀값을 제외한 오류 기록을 확인하세요."
        }
    }
    finally {
        [Environment]::SetEnvironmentVariable("MYSQL_PWD", $oldPassword, "Process")
        $plainPassword = $null
    }

    if (-not (Test-Path -LiteralPath $Destination -PathType Leaf)) {
        throw "덤프 파일이 생성되지 않았습니다: $Destination"
    }

    $file = Get-Item -LiteralPath $Destination
    if ($file.Length -le 0) {
        throw "덤프 파일이 비어 있습니다: $Destination"
    }

    if (-not (Select-String -LiteralPath $Destination -SimpleMatch "CREATE TABLE" -Quiet)) {
        throw "덤프 파일에 CREATE TABLE 문이 없습니다: $Destination"
    }

    [ordered]@{
        database = $Database
        host = $HostName
        port = $Port
        filename = $file.Name
        bytes = $file.Length
        sha256 = (Get-FileHash -LiteralPath $Destination -Algorithm SHA256).Hash.ToLowerInvariant()
    }
}

function Backup-ProtectedPath {
    param(
        [string]$SourcePath,
        [string]$Destination,
        [string]$TarPath
    )

    $resolved = (Resolve-Path -LiteralPath $SourcePath).Path
    $parent = Split-Path -Parent $resolved
    $leaf = Split-Path -Leaf $resolved
    $process = Start-Process `
        -FilePath $TarPath `
        -ArgumentList @("-cf", $Destination, "-C", $parent, $leaf) `
        -RedirectStandardError ($Destination + ".stderr.log") `
        -WindowStyle Hidden `
        -Wait `
        -PassThru

    if ($process.ExitCode -ne 0 -or -not (Test-Path -LiteralPath $Destination -PathType Leaf)) {
        throw "보호 파일 백업에 실패했습니다: $SourcePath"
    }

    $file = Get-Item -LiteralPath $Destination
    [ordered]@{
        source = $resolved
        filename = $file.Name
        bytes = $file.Length
        sha256 = (Get-FileHash -LiteralPath $Destination -Algorithm SHA256).Hash.ToLowerInvariant()
    }
}

if (-not $EncryptedStorage) {
    throw "개인정보가 포함될 수 있으므로 암호화된 저장 위치만 사용할 수 있습니다."
}

if (-not (Test-Path -LiteralPath $MySqlDumpPath -PathType Leaf)) {
    throw "mysqldump 또는 mariadb-dump 실행 파일을 찾을 수 없습니다."
}

Assert-DatabaseIdentifier $WordPressDatabase "WordPress DB 이름"
Assert-DatabaseIdentifier $JudgeDatabase "Judge DB 이름"
Assert-ConnectionToken $WordPressHost "WordPress DB 호스트"
Assert-ConnectionToken $JudgeHost "Judge DB 호스트"
Assert-ConnectionToken $WordPressUser "WordPress DB 사용자"
Assert-ConnectionToken $JudgeUser "Judge DB 사용자"

$wordpressSource = "{0}:{1}/{2}" -f $WordPressHost.ToLowerInvariant(), $WordPressPort, $WordPressDatabase.ToLowerInvariant()
$judgeSource = "{0}:{1}/{2}" -f $JudgeHost.ToLowerInvariant(), $JudgePort, $JudgeDatabase.ToLowerInvariant()
if ($wordpressSource -eq $judgeSource) {
    throw "WordPress DB와 외부 Judge DB는 같은 연결·DB 조합을 사용할 수 없습니다."
}

$scriptPath = $MyInvocation.MyCommand.Path
$pluginRoot = (Resolve-Path -LiteralPath (Join-Path (Split-Path -Parent $scriptPath) "..\..")).Path
$repositoryRoot = (Resolve-Path -LiteralPath (Join-Path $pluginRoot "..")).Path
$resolvedOutputRoot = [IO.Path]::GetFullPath($OutputRoot)
$resolvedRepositoryRoot = [IO.Path]::GetFullPath($repositoryRoot)
if ($resolvedOutputRoot.StartsWith($resolvedRepositoryRoot, [StringComparison]::OrdinalIgnoreCase)) {
    throw "백업은 Git 저장소 밖의 암호화된 위치에 생성해야 합니다."
}

$timestamp = (Get-Date).ToUniversalTime().ToString("yyyyMMddTHHmmssZ")
$backupDirectory = Join-Path $resolvedOutputRoot ("oj-platform-i00-" + $timestamp)
if (Test-Path -LiteralPath $backupDirectory) {
    throw "같은 백업 디렉터리가 이미 존재합니다: $backupDirectory"
}
New-Item -ItemType Directory -Path $backupDirectory | Out-Null

$startedAt = Get-Date
$wordpressDump = Join-Path $backupDirectory "wordpress.sql"
$judgeDump = Join-Path $backupDirectory "judge.sql"
$databases = @(
    (Invoke-DatabaseDump `
        -ToolPath $MySqlDumpPath `
        -HostName $WordPressHost `
        -Port $WordPressPort `
        -Database $WordPressDatabase `
        -User $WordPressUser `
        -Password $WordPressPassword `
        -Destination $wordpressDump `
        -ErrorLog (Join-Path $backupDirectory "wordpress.stderr.log")),
    (Invoke-DatabaseDump `
        -ToolPath $MySqlDumpPath `
        -HostName $JudgeHost `
        -Port $JudgePort `
        -Database $JudgeDatabase `
        -User $JudgeUser `
        -Password $JudgePassword `
        -Destination $judgeDump `
        -ErrorLog (Join-Path $backupDirectory "judge.stderr.log"))
)

$protectedFiles = @()
if ($ProtectedFilePaths.Count -gt 0) {
    $tar = Get-Command tar.exe -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -eq $tar) {
        throw "보호 파일 백업에 필요한 tar.exe를 찾을 수 없습니다."
    }

    for ($index = 0; $index -lt $ProtectedFilePaths.Count; $index++) {
        $sourcePath = $ProtectedFilePaths[$index]
        if (-not (Test-Path -LiteralPath $sourcePath)) {
            throw "보호 파일 경로를 찾을 수 없습니다: $sourcePath"
        }

        $archive = Join-Path $backupDirectory ("protected-files-{0:d2}.tar" -f ($index + 1))
        $protectedFiles += Backup-ProtectedPath -SourcePath $sourcePath -Destination $archive -TarPath $tar.Source
    }
}

$completedAt = Get-Date
$manifest = [ordered]@{
    schema_version = 1
    operation = "i00_backup"
    started_at = $startedAt.ToUniversalTime().ToString("o")
    completed_at = $completedAt.ToUniversalTime().ToString("o")
    duration_seconds = [Math]::Round(($completedAt - $startedAt).TotalSeconds, 3)
    operator = $Operator
    storage_encrypted = $true
    backup_directory_identifier = Split-Path -Leaf $backupDirectory
    databases = $databases
    protected_files = $protectedFiles
    secrets_recorded = $false
}
$manifestPath = Join-Path $backupDirectory "backup-manifest.json"
$manifestJson = $manifest | ConvertTo-Json -Depth 10
[IO.File]::WriteAllText(
    $manifestPath,
    $manifestJson + [Environment]::NewLine,
    [Text.UTF8Encoding]::new($false)
)
$manifestHash = (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
Set-Content -LiteralPath (Join-Path $backupDirectory "backup-manifest.sha256") -Value $manifestHash -Encoding ascii

Write-Output "I-00 백업이 완료되었습니다."
Write-Output ("백업 식별자: {0}" -f (Split-Path -Leaf $backupDirectory))
Write-Output ("manifest SHA-256: {0}" -f $manifestHash)
