# Shared helpers for the release kit.
# Keep this file ASCII-only: Windows PowerShell 5.1 reads BOM-less .ps1 files
# with the ANSI code page, so Korean text lives in UTF-8 .md/.json/.html files.

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$script:Utf8 = New-Object System.Text.UTF8Encoding($false, $true)
$script:KitRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$script:PluginsRoot = [IO.Path]::GetFullPath((Join-Path $script:KitRoot '..'))

function Read-Utf8([string]$Path) {
    $bytes = [IO.File]::ReadAllBytes($Path)
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        throw "UTF-8 BOM is not allowed: $Path"
    }
    return $script:Utf8.GetString($bytes)
}

function Write-Utf8([string]$Path, [string]$Text) {
    $dir = Split-Path -Parent $Path
    if ($dir) { [IO.Directory]::CreateDirectory($dir) | Out-Null }
    [IO.File]::WriteAllText($Path, $Text.Replace("`r`n", "`n"), $script:Utf8)
}

function Get-Prop($Object, [string]$Name, $Default = $null) {
    if ($null -ne $Object -and $Object.PSObject.Properties[$Name]) { return $Object.$Name }
    return $Default
}

function Resolve-KitPath([string]$Relative) {
    return [IO.Path]::GetFullPath((Join-Path $script:PluginsRoot $Relative))
}

function Assert-UnderRoot([string]$Path, [string]$Root) {
    $full = [IO.Path]::GetFullPath($Path).TrimEnd('\')
    $base = [IO.Path]::GetFullPath($Root).TrimEnd('\') + '\'
    if (-not ($full + '\').StartsWith($base, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to touch a path outside $Root : $Path"
    }
}

function Get-Registry {
    $registry = Read-Utf8 (Join-Path $script:KitRoot 'plugins.json') | ConvertFrom-Json
    $registry | Add-Member -NotePropertyName releasesRoot -NotePropertyValue (Resolve-KitPath $registry.outputRoot) -Force
    $registry | Add-Member -NotePropertyName latestRoot -NotePropertyValue (Resolve-KitPath $registry.latestDir) -Force
    return $registry
}

function Select-Plugins($Registry, [string[]]$Ids) {
    $all = @($Registry.plugins | Sort-Object order)
    if (-not $Ids -or $Ids.Count -eq 0 -or ($Ids.Count -eq 1 -and $Ids[0] -eq 'all')) { return $all }
    $picked = @()
    foreach ($id in $Ids) {
        $match = @($all | Where-Object { $_.id -eq $id })
        if ($match.Count -eq 0) { throw "Unknown plugin id: $id (see _release-kit/plugins.json)" }
        $picked += $match[0]
    }
    return $picked
}

# Reads the WordPress header and plugin-package.php so docs and packages never
# carry a hand-typed version.
function Get-PluginInfo($Plugin) {
    $sourceDir = Resolve-KitPath $Plugin.source
    $mainPath = Join-Path $sourceDir $Plugin.mainFile
    if (-not (Test-Path -LiteralPath $mainPath)) { throw "Main file missing for $($Plugin.id): $mainPath" }
    $main = Read-Utf8 $mainPath
    $header = @{}
    foreach ($field in @('Plugin Name', 'Version', 'Requires at least', 'Requires PHP', 'Text Domain', 'Description')) {
        $m = [regex]::Match($main, '(?m)^[ \t/*#@]*' + [regex]::Escape($field) + ':\s*(.+?)\s*$')
        if ($m.Success) { $header[$field] = $m.Groups[1].Value } else { $header[$field] = '' }
    }
    $packageVersion = ''
    $platformRequires = ''
    $packagePath = Join-Path $sourceDir 'plugin-package.php'
    if (Test-Path -LiteralPath $packagePath) {
        $pkg = Read-Utf8 $packagePath
        $vm = [regex]::Match($pkg, "'version'\s*=>\s*'([^']+)'")
        if ($vm.Success) { $packageVersion = $vm.Groups[1].Value }
        $rm = [regex]::Match($pkg, "'oj-platform:([^']+)'")
        if ($rm.Success) { $platformRequires = $rm.Groups[1].Value }
    }
    if (-not $platformRequires) { $platformRequires = [string](Get-Prop $Plugin 'platformRequires' '') }
    $version = $header['Version']
    $registry = Get-Registry
    $outDir = Join-Path (Join-Path $registry.releasesRoot $Plugin.id) $version
    $packages = @("$($Plugin.id)-$version.zip")
    foreach ($extra in @(Get-Prop $Plugin 'extraPackages' @())) { $packages += $extra.Replace('{version}', $version) }
    return [pscustomobject]@{
        Id               = $Plugin.id
        Name             = $header['Plugin Name']
        Version          = $version
        RequiresWp       = $header['Requires at least']
        RequiresPhp      = $header['Requires PHP']
        TextDomain       = $header['Text Domain']
        PackageVersion   = $packageVersion
        PlatformRequires = $platformRequires
        SourceDir        = $sourceDir
        OutDir           = $outDir
        Packages         = $packages
        Plugin           = $Plugin
    }
}

function Get-Sha256([string]$Path) {
    $stream = [IO.File]::OpenRead($Path)
    try { return Get-StreamSha256 $stream }
    finally { $stream.Dispose() }
}

function Get-StreamSha256([IO.Stream]$Stream) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($Stream))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function Find-Tool([string]$Name, [string]$Explicit, [string]$EnvName, [string[]]$Fallbacks) {
    if ($Explicit) { return $Explicit }
    $fromEnv = [Environment]::GetEnvironmentVariable($EnvName)
    if ($fromEnv) { return $fromEnv }
    $cmd = Get-Command $Name -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($f in $Fallbacks) { if (Test-Path -LiteralPath $f) { return $f } }
    throw "$Name not found. Pass it explicitly or set $EnvName."
}

# Text files must be UTF-8 without BOM (root AGENT.md rule 1).
$script:TextExtensions = @('.php', '.js', '.mjs', '.css', '.json', '.html', '.md', '.txt', '.svg', '.pot', '.po')

function Test-TextEncoding([string]$Path) {
    if ($script:TextExtensions -notcontains ([IO.Path]::GetExtension($Path).ToLowerInvariant())) { return $null }
    $bytes = [IO.File]::ReadAllBytes($Path)
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) { return 'UTF-8 BOM' }
    try { [void]$script:Utf8.GetString($bytes) } catch { return 'not valid UTF-8' }
    return $null
}

# Packages a plugin that has no release script of its own.
function Invoke-GenericPackage($Info, [string]$OutDir) {
    $plugin = $Info.Plugin
    $root = $Info.SourceDir.TrimEnd('\')
    $zipName = "$($Info.Id)-$($Info.Version).zip"
    $archive = Join-Path $OutDir $zipName
    if (Test-Path -LiteralPath $archive) { throw "Existing release will not be overwritten: $archive" }
    $excluded = '(^|/)(\.git|\.github|\.idea|\.vscode|node_modules|tests?|__pycache__|\.cache)(/|$)|(^|/)(\.DS_Store|Thumbs\.db|\.env(\..*)?|AGENTS\.md|CLAUDE\.md|\.gitignore|\.gitattributes)$|\.(zip|log|pyc|orig|rej|bak)$'
    $forbid = @(Get-Prop $plugin.builder 'forbid' @())

    $files = New-Object System.Collections.Generic.List[object]
    foreach ($item in $plugin.builder.include) {
        $path = Join-Path $root $item
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            $files.Add((Get-Item -LiteralPath $path))
        } elseif (Test-Path -LiteralPath $path -PathType Container) {
            foreach ($entry in Get-ChildItem -LiteralPath $path -Recurse -Force) {
                if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Linked paths are not allowed: $($entry.FullName)" }
                if (-not $entry.PSIsContainer) { $files.Add($entry) }
            }
        } else {
            throw "Missing include for $($Info.Id): $item"
        }
    }

    $entries = New-Object System.Collections.Generic.List[object]
    $problems = @()
    foreach ($file in ($files | Sort-Object FullName)) {
        $relative = $file.FullName.Substring($root.Length + 1).Replace('\', '/')
        if ($relative -cmatch $excluded) { continue }
        foreach ($f in $forbid) { if ($relative.StartsWith($f)) { throw "Forbidden content in package: $relative" } }
        $bad = Test-TextEncoding $file.FullName
        if ($bad) { $problems += "$relative ($bad)"; continue }
        $entries.Add([ordered]@{ path = "$($Info.Id)/$relative"; source = $file.FullName; bytes = $file.Length; sha256 = (Get-Sha256 $file.FullName) })
    }
    foreach ($bundle in @(Get-Prop $plugin.builder 'sharedBundles' @())) {
        $shared = Resolve-KitPath $bundle.source
        Assert-UnderRoot $shared $script:PluginsRoot
        if ($bundle.target -notmatch '^[a-z0-9][a-z0-9-]*$') { throw 'Invalid shared bundle target.' }
        foreach ($directory in Get-ChildItem -LiteralPath $shared -Recurse -Force -Directory) {
            if ($directory.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked shared runtime directory.' }
        }
        foreach ($file in Get-ChildItem -LiteralPath $shared -Recurse -Force -File) {
            if ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked shared runtime file.' }
            $relative = $file.FullName.Substring($shared.Length + 1).Replace('\', '/')
            if ($relative -cmatch $excluded) { continue }
            $bad = Test-TextEncoding $file.FullName
            if ($bad) { $problems += "$relative ($bad)"; continue }
            $entries.Add([ordered]@{ path = "$($Info.Id)/$($bundle.target)/$relative"; source = $file.FullName; bytes = $file.Length; sha256 = (Get-Sha256 $file.FullName) })
        }
    }
    if ($problems.Count -gt 0) { throw "Encoding check failed:`n  " + ($problems -join "`n  ") }
    if (-not ($entries | Where-Object { $_.path -eq "$($Info.Id)/$($plugin.mainFile)" })) { throw 'Main plugin file is not in the package.' }

    $stream = [IO.File]::Open($archive, [IO.FileMode]::CreateNew)
    $zip = New-Object IO.Compression.ZipArchive($stream, [IO.Compression.ZipArchiveMode]::Create, $false, $script:Utf8)
    try {
        foreach ($item in $entries) {
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $item.source, $item.path, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally { $zip.Dispose(); $stream.Dispose() }

    # Verify every archived byte before publishing the checksum.
    $zip = [IO.Compression.ZipFile]::OpenRead($archive)
    try {
        if ($zip.Entries.Count -ne $entries.Count) { throw 'Archive entry count differs.' }
        foreach ($item in $entries) {
            $entry = $zip.GetEntry($item.path)
            if (-not $entry -or $entry.Length -ne $item.bytes) { throw "Archive entry mismatch: $($item.path)" }
            $reader = $entry.Open()
            try { $actual = Get-StreamSha256 $reader } finally { $reader.Dispose() }
            if ($actual -ne $item.sha256) { throw "Archive hash mismatch: $($item.path)" }
        }
    } finally { $zip.Dispose() }

    $hash = Get-Sha256 $archive
    $manifestFiles = @($entries | ForEach-Object { [ordered]@{ path = $_.path; bytes = $_.bytes; sha256 = $_.sha256 } })
    $manifest = [ordered]@{ plugin = $Info.Id; version = $Info.Version; builder = '_release-kit generic'; archive = $zipName; sha256 = $hash; fileCount = $entries.Count; files = $manifestFiles }
    Write-Utf8 (Join-Path $OutDir "$zipName.manifest.json") ($manifest | ConvertTo-Json -Depth 5)
    Write-Utf8 "$archive.sha256" "$hash  $zipName`n"
    Write-Host "  verified $($entries.Count) files -> $zipName"
}

function Invoke-Checked([string]$Label, [scriptblock]$Block) {
    $global:LASTEXITCODE = 0
    & $Block
    if ($LASTEXITCODE -ne 0) { throw "$Label failed with exit code $LASTEXITCODE" }
}

function Invoke-PluginBuild($Info, $Tools) {
    $plugin = $Info.Plugin
    $builder = $plugin.builder
    $out = $Info.OutDir
    $mainZip = Join-Path $out $Info.Packages[0]
    if (Test-Path -LiteralPath $mainZip) {
        Write-Host "[skip] $($Info.Id) $($Info.Version): already packaged (released files are immutable; bump the version to rebuild)"
        foreach ($name in $Info.Packages) {
            $zip = Join-Path $Info.OutDir $name
            if ((Test-Path -LiteralPath $zip) -and -not (Test-Path -LiteralPath "$zip.sha256")) { Write-Utf8 "$zip.sha256" "$(Get-Sha256 $zip)  $name`n" }
        }
        return 'existing'
    }
    $registry = Get-Registry
    Assert-UnderRoot $out $registry.releasesRoot
    $created = -not (Test-Path -LiteralPath $out)
    if (-not $created -and @(Get-ChildItem -LiteralPath $out -Force).Count -gt 0) {
        throw "Output folder exists without the main ZIP; inspect and clean it first: $out"
    }
    [IO.Directory]::CreateDirectory($out) | Out-Null
    Write-Host "[build] $($Info.Id) $($Info.Version) -> $out"
    $expand = { param($s) $s.Replace('{out}', $out).Replace('{version}', $Info.Version).Replace('{php}', $Tools.Php) }
    $args2 = @(@(Get-Prop $builder 'args' @()) | ForEach-Object { & $expand $_ })
    $scriptPath = ''
    if (Get-Prop $builder 'script') { $scriptPath = Join-Path $Info.SourceDir $builder.script }

    # builder.env: paths (relative to the plugins root) a builder needs, e.g. a pinned upstream
    # source. A value already set in the environment wins.
    $envSpec = Get-Prop $builder 'env'
    if ($envSpec) {
        foreach ($prop in $envSpec.PSObject.Properties) {
            if ([Environment]::GetEnvironmentVariable($prop.Name)) { continue }
            $value = Resolve-KitPath $prop.Value
            if (-not (Test-Path -LiteralPath $value)) { throw "$($Info.Id): $($prop.Name) path not found: $value (set it explicitly)" }
            [Environment]::SetEnvironmentVariable($prop.Name, $value, 'Process')
            Write-Host "  $($prop.Name)=$value"
        }
    }
    Push-Location $Info.SourceDir
    try {
        if (Get-Prop $builder 'preScript') {
            $pre = Join-Path $Info.SourceDir $builder.preScript
            Invoke-Checked "$($Info.Id) pre-check" { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $pre -Php $Tools.Php -Node $Tools.Node }
        }
        switch ($builder.type) {
            'generic'    { Invoke-GenericPackage $Info $out }
            'powershell' { Invoke-Checked "$($Info.Id) build" { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $scriptPath @args2 } }
            'python'     { Invoke-Checked "$($Info.Id) build" { & $Tools.Python $scriptPath @args2 } }
            'php'        { Invoke-Checked "$($Info.Id) build" { & $Tools.Php $scriptPath @args2 } }
            'prebuilt'   { Write-Host '  using the package built by the plugin release folder' }
            default      { throw "Unknown builder type: $($builder.type)" }
        }
        foreach ($c in @(Get-Prop $builder 'collect' @())) {
            $src = Join-Path $Info.SourceDir (& $expand $c)
            if (-not (Test-Path -LiteralPath $src)) { throw "Build output missing: $src" }
            Copy-Item -LiteralPath $src -Destination $out
        }
        foreach ($name in $Info.Packages) {
            $zip = Join-Path $out $name
            if (-not (Test-Path -LiteralPath $zip)) { throw "Expected package was not produced: $name" }
            if (-not (Test-Path -LiteralPath "$zip.sha256")) { Write-Utf8 "$zip.sha256" "$(Get-Sha256 $zip)  $name`n" }
        }
    } catch {
        Pop-Location
        if ($created -and (Test-Path -LiteralPath $out)) { Remove-Item -LiteralPath $out -Recurse -Force }
        throw
    }
    Pop-Location
    return 'built'
}

# Release scripts need openssl/zip/gd/intl/sqlite. When the PHP build has them as
# DLLs but its php.ini leaves them off, enable them for this run only through an
# extra scan directory (the user's php.ini is not edited).
function Enable-PhpExtensions([string]$PhpPath) {
    $extDir = Join-Path (Split-Path -Parent $PhpPath) 'ext'
    if (-not (Test-Path -LiteralPath $extDir)) { return }
    $loaded = @(& $PhpPath -m 2>$null | ForEach-Object { $_.Trim().ToLowerInvariant() })
    $wanted = @('openssl', 'zip', 'gd', 'intl', 'fileinfo', 'curl', 'mysqli', 'pdo_mysql', 'pdo_sqlite', 'sqlite3', 'mbstring', 'sodium')
    # Forward slashes: inside a quoted ini value "e" would be read as an escape.
    $lines = @("extension_dir=""" + $extDir.Replace([char]92, [char]47) + """")
    foreach ($name in $wanted) {
        if ($loaded -contains $name) { continue }
        if (Test-Path -LiteralPath (Join-Path $extDir ("php_" + $name + ".dll"))) { $lines += "extension=$name" }
    }
    if ($lines.Count -le 1) { return }
    $scan = Join-Path ([IO.Path]::GetTempPath()) 'oj-release-kit-php'
    New-Item -ItemType Directory -Force -Path $scan | Out-Null
    [IO.File]::WriteAllText((Join-Path $scan 'release-kit.ini'), ($lines -join "`n") + "`n", (New-Object Text.UTF8Encoding $false))
    $env:PHP_INI_SCAN_DIR = $scan
}
