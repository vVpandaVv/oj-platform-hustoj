[CmdletBinding()]
param(
    [string] $RepositoryRoot = '',
    [string] $OutputDirectory = '',
    [switch] $AllowDirty,
    [string] $PhpBinary = 'php'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Stop-Release {
    param([string] $Message)

    throw "OJ Platform 배포 중단: $Message"
}

function Resolve-ReleaseRoot {
    param([string] $RequestedRoot)

    $candidate = if ([string]::IsNullOrWhiteSpace($RequestedRoot)) {
        Split-Path -Parent $PSScriptRoot
    } else {
        $RequestedRoot
    }

    return [IO.Path]::GetFullPath($candidate)
}

function Read-PluginVersion {
    param([string] $PluginFile)

    $source = [IO.File]::ReadAllText($PluginFile)
    $header = [regex]::Match(
        $source,
        '(?m)^\s*\*\s*Version:\s*([^\r\n]+)$'
    )
    $runtime = [regex]::Match(
        $source,
        "define\('OJ_PLATFORM_VERSION',\s*'([^']+)'\);"
    )
    if (-not $header.Success -or -not $runtime.Success) {
        Stop-Release '플러그인 헤더 또는 OJ_PLATFORM_VERSION을 찾지 못했습니다.'
    }

    $headerVersion = $header.Groups[1].Value.Trim()
    $runtimeVersion = $runtime.Groups[1].Value.Trim()
    if ($headerVersion -ne $runtimeVersion) {
        Stop-Release (
            "플러그인 헤더 버전($headerVersion)과 런타임 버전($runtimeVersion)이 다릅니다."
        )
    }
    if ($headerVersion -notmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$') {
        Stop-Release "버전 형식이 올바르지 않습니다: $headerVersion"
    }

    return $headerVersion
}

function Read-GitState {
    param(
        [string] $Root,
        [bool] $DirtyAllowed
    )

    $commit = 'unavailable'
    $dirty = $true
    $git = Get-Command git -ErrorAction SilentlyContinue
    # The unified plugins repository holds this folder; there is no .git here.
    if ($null -ne $git) {
        Push-Location $Root
        try {
            $inside = & git -c core.excludesFile= rev-parse --is-inside-work-tree 2>$null
            if ($LASTEXITCODE -eq 0 -and ($inside | Select-Object -First 1) -eq 'true') {
                $commit = ((& git -c core.excludesFile= rev-parse HEAD) | Select-Object -First 1).Trim()
                if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($commit)) {
                    Stop-Release '현재 Git 커밋을 확인하지 못했습니다.'
                }
                $status = @(& git -c core.excludesFile= status --porcelain --untracked-files=all -- .)
                if ($LASTEXITCODE -ne 0) {
                    Stop-Release 'Git 작업 상태를 확인하지 못했습니다.'
                }
                $dirty = $status.Count -gt 0
            }
        } finally {
            Pop-Location
        }
    }

    if ($dirty -and -not $DirtyAllowed) {
        $message = '커밋되지 않은 변경이 있습니다. 변경을 커밋한 뒤 다시 실행하세요. '
        $message += '검증용 비공식 빌드만 -AllowDirty를 명시할 수 있습니다.'
        Stop-Release $message
    }

    return [PSCustomObject]@{
        Commit = $commit
        Dirty = $dirty
    }
}

function Find-VersionCollisions {
    param(
        [string] $Root,
        [string] $OutputDirectory,
        [string] $Version
    )

    $locations = @(
        $Root,
        (Split-Path -Parent $Root),
        (Join-Path $Root 'dist'),
        $OutputDirectory
    ) | Select-Object -Unique
    $names = @(
        "oj-platform-$Version.zip",
        "oj-platform-$Version-wordpress.zip"
    )
    $collisions = [Collections.Generic.List[string]]::new()
    foreach ($location in $locations) {
        if (-not (Test-Path -LiteralPath $location -PathType Container)) {
            continue
        }
        foreach ($name in $names) {
            $candidate = Join-Path $location $name
            if (Test-Path -LiteralPath $candidate -PathType Leaf) {
                $collisions.Add([IO.Path]::GetFullPath($candidate))
            }
        }
    }

    return @($collisions | Select-Object -Unique)
}

function Get-PackageFiles {
    param([string] $PluginRoot)

    $files = [Collections.Generic.List[string]]::new()
    foreach ($name in @('composer.json', 'oj-platform.php', 'LICENSE.txt', 'THIRD_PARTY_NOTICES.txt')) {
        $path = Join-Path $PluginRoot $name
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            Stop-Release "필수 파일이 없습니다: $name"
        }
        $files.Add([IO.Path]::GetFullPath($path))
    }
    foreach ($directory in @('config', 'modules', 'resources', 'src')) {
        $path = Join-Path $PluginRoot $directory
        if (-not (Test-Path -LiteralPath $path -PathType Container)) {
            Stop-Release "필수 디렉터리가 없습니다: $directory"
        }
        foreach ($file in Get-ChildItem -LiteralPath $path -File -Recurse) {
            if (($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                Stop-Release "심볼릭 링크 파일은 배포할 수 없습니다: $($file.FullName)"
            }
            $files.Add($file.FullName)
        }
    }

    return @($files | Sort-Object)
}

function New-ReleaseArchive {
    param(
        [string] $PluginRoot,
        [string[]] $Files,
        [string] $ArchivePath
    )

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [IO.Compression.ZipFile]::Open(
        $ArchivePath,
        [IO.Compression.ZipArchiveMode]::Create
    )
    try {
        foreach ($file in $Files) {
            $relative = $file.Substring($PluginRoot.Length).TrimStart('\')
            $relative = $relative.Replace('\', '/')
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $file,
                "oj-platform/$relative",
                [IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        $archive.Dispose()
    }
}

function Test-ReleaseArchive {
    param(
        [string] $ArchivePath,
        [int] $ExpectedEntries,
        [string] $Version
    )

    $archive = [IO.Compression.ZipFile]::OpenRead($ArchivePath)
    try {
        if ($archive.Entries.Count -ne $ExpectedEntries) {
            Stop-Release (
                "ZIP 항목 수가 다릅니다. 예상 $ExpectedEntries, 실제 $($archive.Entries.Count)"
            )
        }
        $names = @($archive.Entries | ForEach-Object { $_.FullName })
        foreach ($required in @(
            'oj-platform/oj-platform.php',
            'oj-platform/THIRD_PARTY_NOTICES.txt',
            'oj-platform/LICENSE.txt',
            'oj-platform/composer.json'
        )) {
            if ($required -notin $names) {
                Stop-Release "ZIP 필수 항목이 없습니다: $required"
            }
        }
        $excluded = @($names | Where-Object {
            $_ -like 'oj-platform/docs/*' -or $_ -like 'oj-platform/tests/*' -or $_ -like '*.patch' -or $_ -like 'oj-platform/dist/*'
        })
        if ($excluded.Count -gt 0) {
            Stop-Release "배포 제외 파일이 ZIP에 포함되었습니다: $($excluded[0])"
        }

        foreach ($entry in $archive.Entries) {
            $stream = $entry.Open()
            try {
                $buffer = New-Object byte[] 8192
                while ($stream.Read($buffer, 0, $buffer.Length) -gt 0) {
                }
            } finally {
                $stream.Dispose()
            }
        }

        $pluginEntry = $archive.GetEntry('oj-platform/oj-platform.php')
        $reader = [IO.StreamReader]::new(
            $pluginEntry.Open(),
            [Text.UTF8Encoding]::new($false, $true)
        )
        try {
            $pluginSource = $reader.ReadToEnd()
        } finally {
            $reader.Dispose()
        }
        if (-not $pluginSource.Contains("Version: $Version")) {
            Stop-Release 'ZIP 내부 플러그인 버전이 원본과 다릅니다.'
        }
    } finally {
        $archive.Dispose()
    }
}

$repositoryRoot = Resolve-ReleaseRoot $RepositoryRoot
$pluginRoot = Join-Path $repositoryRoot 'oj-platform'
$pluginFile = Join-Path $pluginRoot 'oj-platform.php'
if (-not (Test-Path -LiteralPath $pluginFile -PathType Leaf)) {
    Stop-Release "OJ Platform 플러그인을 찾지 못했습니다: $pluginFile"
}

$version = Read-PluginVersion $pluginFile
if ($AllowDirty -and $version -match '^\d+\.\d+\.\d+$') {
    Stop-Release '정식 버전에는 AllowDirty를 사용할 수 없습니다.'
}
$gitState = Read-GitState $repositoryRoot $AllowDirty.IsPresent
if ($version -match '^\d+\.\d+\.\d+$') {
    # Tags are shared by every plugin in the unified repository, so they carry the plugin id.
    $tagCommit = & git -c core.excludesFile= -C $repositoryRoot rev-list -n 1 "oj-platform-v$version" 2>$null
    if ($LASTEXITCODE -ne 0 -or $tagCommit -ne $gitState.Commit) {
        Stop-Release "정식 버전 태그 oj-platform-v$version 이 현재 커밋을 가리켜야 합니다."
    }
}
& $PhpBinary (Join-Path $pluginRoot 'tools/run-tests.php') --release
if ($LASTEXITCODE -ne 0) {
    Stop-Release '전체 코드 검증이 실패했습니다. 패키지를 생성하지 않습니다.'
}
$verifiedGitState = Read-GitState $repositoryRoot $AllowDirty.IsPresent
if ($verifiedGitState.Commit -ne $gitState.Commit -or $verifiedGitState.Dirty -ne $gitState.Dirty) {
    Stop-Release '검증 중 Git 소스 상태가 바뀌었습니다.'
}
$pluginsRoot = Split-Path -Parent $repositoryRoot
$expectedOutputDirectory = [IO.Path]::GetFullPath(
    (Join-Path $pluginsRoot "_releases/oj-platform/$version")
)
$outputDirectory = if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $expectedOutputDirectory
} else {
    [IO.Path]::GetFullPath($OutputDirectory)
}
if (-not $outputDirectory.TrimEnd('\').Equals(
    $expectedOutputDirectory.TrimEnd('\'),
    [StringComparison]::OrdinalIgnoreCase
)) {
    Stop-Release 'OutputDirectory must be _releases/oj-platform/<version>.'
}
$collisions = @(Find-VersionCollisions $repositoryRoot $outputDirectory $version)
if ($collisions.Count -gt 0) {
    $message = "버전 $version 패키지가 이미 존재합니다. 버전을 올린 뒤 다시 실행하세요.`n- "
    $message += $collisions -join "`n- "
    Stop-Release $message
}

New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
$packageName = "oj-platform-$version.zip"
$manifestName = "oj-platform-$version.manifest.json"
$packagePath = Join-Path $outputDirectory $packageName
$manifestPath = Join-Path $outputDirectory $manifestName
$temporaryPackage = Join-Path $outputDirectory (
    ".oj-platform-$version-$PID.tmp.zip"
)
$temporaryManifest = "$temporaryPackage.manifest.json"
$files = @(Get-PackageFiles $pluginRoot)

try {
    New-ReleaseArchive $pluginRoot $files $temporaryPackage
    Test-ReleaseArchive $temporaryPackage $files.Count $version
    $packageItem = Get-Item -LiteralPath $temporaryPackage
    $packageHash = Get-FileHash -LiteralPath $temporaryPackage -Algorithm SHA256
    $sha256 = $packageHash.Hash.ToLowerInvariant()
    $manifest = [ordered]@{
        schema_version = 1
        plugin = 'oj-platform'
        version = $version
        package = $packageName
        sha256 = $sha256
        bytes = $packageItem.Length
        entries = $files.Count
        source_commit = $gitState.Commit
        source_dirty = $gitState.Dirty
        source_tag = if ($version -match '^\d+\.\d+\.\d+$') { "oj-platform-v$version" } else { $null }
        tests = 'test:fast passed; required PHP extensions verified'
        built_at_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
        included = @(
            'THIRD_PARTY_NOTICES.txt',
            'LICENSE.txt',
            'composer.json',
            'oj-platform.php',
            'config/',
            'modules/',
            'resources/',
            'src/'
        )
    }
    [IO.File]::WriteAllText(
        $temporaryManifest,
        ($manifest | ConvertTo-Json -Depth 4),
        [Text.UTF8Encoding]::new($false)
    )

    Move-Item -LiteralPath $temporaryPackage -Destination $packagePath
    try {
        Move-Item -LiteralPath $temporaryManifest -Destination $manifestPath
    } catch {
        Remove-Item -LiteralPath $packagePath -ErrorAction SilentlyContinue
        throw
    }
} finally {
    Remove-Item -LiteralPath $temporaryPackage -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $temporaryManifest -ErrorAction SilentlyContinue
}

Write-Output "배포 패키지: $packagePath"
Write-Output "배포 명세: $manifestPath"
Write-Output "버전: $version"
Write-Output "SHA-256: $sha256"
Write-Output "항목: $($files.Count)개"
