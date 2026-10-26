<#
Single entry point for packaging and install guides. See _release-kit/README.md.

  release.cmd status                 versions, package state, guide state
  release.cmd package [-Plugin a,b]  build missing packages into _releases/<id>/<version>/, then refresh latest
  release.cmd docs                   regenerate _releases/latest/install-guides/
  release.cmd content                refresh registered content and guides, without rebuilding plugins
  release.cmd all [-Plugin a,b]      package + docs + check
  release.cmd check                  policy gate (versions, encoding, checksums); exit 1 on failure
  release.cmd migrate [-Apply]       move legacy flat release files into _releases/<id>/<version>/

ASCII-only file: Windows PowerShell 5.1 reads BOM-less scripts as ANSI.
#>
param(
    [Parameter(Position = 0)]
    [ValidateSet('status', 'package', 'docs', 'all', 'check', 'migrate', 'latest', 'content')]
    [string]$Command = 'status',
    [string[]]$Plugin = @('all'),
    [string]$Php = '',
    [string]$Python = '',
    [string]$Node = '',
    [switch]$Apply,
    [switch]$KeepGoing
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib/Common.ps1')
. (Join-Path $PSScriptRoot 'lib/Docs.ps1')
. (Join-Path $PSScriptRoot 'lib/UiLint.ps1')
. (Join-Path $PSScriptRoot 'lib/Content.ps1')

# Allow comma-separated ids from release.cmd (-Plugin a,b arrives as one string).
$Plugin = @($Plugin | ForEach-Object { $_ -split ',' } | Where-Object { $_ })

$registry = Get-Registry
$allInfos = @(Select-Plugins $registry @('all') | ForEach-Object { Get-PluginInfo $_ })

function Get-Tools {
    $phpPath = Find-Tool 'php' $Php 'PHP_BINARY' @('D:\php\php.exe', 'C:\php\php.exe')
    Enable-PhpExtensions $phpPath
    return [pscustomobject]@{
        Php    = $phpPath
        Python = (Find-Tool 'python' $Python 'PYTHON_BINARY' @())
        Node   = (Find-Tool 'node' $Node 'NODE_BINARY' @())
    }
}

function Sync-Latest {
    $contentPlan = Get-ContentPlan $registry $allInfos
    $latest = $registry.latestRoot
    Assert-UnderRoot $latest $registry.releasesRoot
    Write-ContentSnapshot $registry $contentPlan
    $packagesDir = Join-Path $latest 'packages'
    Assert-ContentDirectory $packagesDir $latest
    if (Test-Path -LiteralPath $packagesDir) { Remove-Item -LiteralPath $packagesDir -Recurse -Force }
    [IO.Directory]::CreateDirectory($packagesDir) | Out-Null
    $serverDir = Join-Path $latest 'server'
    Assert-ContentDirectory $serverDir $latest
    if (Test-Path -LiteralPath $serverDir) { Remove-Item -LiteralPath $serverDir -Recurse -Force }
    $set = @()
    $sums = @()
    foreach ($info in $allInfos) {
        $files = @(); $hashes = @(); $status = 'ready'
        foreach ($name in $info.Packages) {
            $zip = Join-Path $info.OutDir $name
            if (-not (Test-Path -LiteralPath $zip)) { $status = 'missing'; continue }
            Copy-Item -LiteralPath $zip -Destination $packagesDir
            $hash = Get-Sha256 $zip
            Write-Utf8 (Join-Path $packagesDir "$name.sha256") "$hash  $name`n"
            $files += $name; $hashes += $hash; $sums += "$hash  $name"
        }
        $contents = @()
        $contentHashes = @()
        foreach ($file in @($contentPlan.Files | Where-Object { $_.PluginId -eq $info.Id })) {
            $sums += "$($file.Sha256)  content/$($file.Target)"
            $contents += $file.Target
            $contentHashes += $file.Sha256
        }
        $set += [ordered]@{ id = $info.Id; name = $info.Name; version = $info.Version; status = $status; files = $files; sha256 = $hashes; contentFiles = $contents; contentSha256 = $contentHashes; requiresWp = $info.RequiresWp; requiresPhp = $info.RequiresPhp; platformRequires = $info.PlatformRequires }
        if ($status -ne 'ready') { Write-Warning "$($info.Id) $($info.Version) is not packaged yet; latest set is incomplete." }
    }
    Write-Utf8 (Join-Path $latest 'SHA256SUMS.txt') (($sums -join "`n") + "`n")
    Write-Utf8 (Join-Path $latest 'release-set.json') ([ordered]@{ generatedAt = (Get-Date).ToString('s'); plugins = $set } | ConvertTo-Json -Depth 5)
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'templates/latest-README.md') -Destination (Join-Path $latest 'README.md') -Force
    Write-Host "[latest] $packagesDir"
}

function Sync-ContentLatest {
    $plan = Get-ContentPlan $registry $allInfos
    $setPath = Join-Path $registry.latestRoot 'release-set.json'
    if (-not (Test-Path -LiteralPath $setPath)) {
        Sync-Latest
    } else {
        $set = Read-Utf8 $setPath | ConvertFrom-Json
        foreach ($info in $allInfos) {
            if (-not @($set.plugins | Where-Object { $_.id -eq $info.Id }).Count) {
                throw "Latest release set is missing $($info.Id); run release.cmd latest first."
            }
        }
        Write-ContentSnapshot $registry $plan
        Set-ContentEntries $set $plan
        $set.generatedAt = (Get-Date).ToString('s')
        Write-Utf8 $setPath ($set | ConvertTo-Json -Depth 8)
        $sums = @()
        foreach ($entry in $set.plugins) {
            for ($i = 0; $i -lt @($entry.files).Count; $i++) { $sums += "$($entry.sha256[$i])  $($entry.files[$i])" }
        }
        foreach ($file in $plan.Files) { $sums += "$($file.Sha256)  content/$($file.Target)" }
        Write-Utf8 (Join-Path $registry.latestRoot 'SHA256SUMS.txt') (($sums -join "`n") + "`n")
        Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'templates/latest-README.md') -Destination (Join-Path $registry.latestRoot 'README.md') -Force
    }
    Build-InstallGuides $registry $allInfos
}

function Invoke-Package {
    & (Join-Path $PSScriptRoot 'check-addon-notices.ps1')
    $tools = Get-Tools
    $failed = @()
    foreach ($info in @(Select-Plugins $registry $Plugin | ForEach-Object { Get-PluginInfo $_ })) {
        try {
            Assert-PluginPolicy $info
            [void](Invoke-PluginBuild $info $tools)
        } catch {
            $failed += $info.Id
            Write-Host "[fail] $($info.Id): $($_.Exception.Message)" -ForegroundColor Red
            if (-not $KeepGoing) { break }
        }
    }
    Sync-Latest
    if ($failed.Count -gt 0) { throw "Packaging failed: $($failed -join ', ')" }
}

function Assert-PluginPolicy($Info) {
    if (-not $Info.Version) { throw "$($Info.Id): plugin header has no Version." }
    if ($Info.PackageVersion -and $Info.PackageVersion -ne $Info.Version) {
        throw "$($Info.Id): header Version $($Info.Version) differs from plugin-package.php $($Info.PackageVersion)."
    }
}

function Invoke-Check {
    $errors = @(); $warnings = @()
    try {
        $contentPlan = Get-ContentPlan $registry $allInfos
        $errors += @(Get-ContentChecks $registry $contentPlan)
    } catch { $errors += $_.Exception.Message }
    try { & (Join-Path $PSScriptRoot 'check-addon-notices.ps1') } catch { $errors += $_.Exception.Message }
    foreach ($info in $allInfos) {
        try { Assert-PluginPolicy $info } catch { $errors += $_.Exception.Message }
        $guide = Join-Path $PSScriptRoot $info.Plugin.guide
        if (-not (Test-Path -LiteralPath $guide)) { $errors += "$($info.Id): guide source missing ($($info.Plugin.guide))" }
        if (-not (Test-Path -LiteralPath (Join-Path $info.OutDir $info.Packages[0]))) { $warnings += "$($info.Id) $($info.Version): not packaged yet" }
    }
    foreach ($f in Get-ChildItem -LiteralPath $PSScriptRoot -Recurse -File) {
        $bad = Test-TextEncoding $f.FullName
        if ($bad) { $errors += "$($f.FullName): $bad" }
        if ($f.Extension -in @('.ps1', '.cmd')) {
            $bytes = [IO.File]::ReadAllBytes($f.FullName)
            if (@($bytes | Where-Object { $_ -gt 127 }).Count -gt 0) { $errors += "$($f.Name): scripts must be ASCII-only" }
        }
    }
    $setPath = Join-Path $registry.latestRoot 'release-set.json'
    if (Test-Path -LiteralPath $setPath) {
        $set = Read-Utf8 $setPath | ConvertFrom-Json
        foreach ($entry in $set.plugins) {
            $info = @($allInfos | Where-Object { $_.Id -eq $entry.id })[0]
            if ($info -and $entry.version -ne $info.Version) { $warnings += "$($entry.id): latest set has $($entry.version) but source is $($info.Version); run 'release.cmd package'" }
            for ($k = 0; $k -lt @($entry.files).Count; $k++) {
                $p = Join-Path (Join-Path $registry.latestRoot 'packages') $entry.files[$k]
                if (-not (Test-Path -LiteralPath $p) -or (Get-Sha256 $p) -ne $entry.sha256[$k]) { $errors += "latest/packages/$($entry.files[$k]): checksum mismatch or missing" }
            }
            if ($contentPlan) {
                $expected = @($contentPlan.Files | Where-Object { $_.PluginId -eq $entry.id })
                if (@($entry.contentFiles).Count -ne $expected.Count) { $errors += "$($entry.id): content release-set count mismatch" }
                for ($k = 0; $k -lt $expected.Count; $k++) {
                    $savedHashes = @(Get-Prop $entry 'contentSha256' @())
                    if ($k -ge @($entry.contentFiles).Count -or $k -ge $savedHashes.Count -or
                        $entry.contentFiles[$k] -cne $expected[$k].Target -or $savedHashes[$k] -ne $expected[$k].Sha256) {
                        $errors += "$($entry.id): content release-set is stale: $($expected[$k].Target)"
                    }
                }
            }
        }
        $guides = Join-Path $registry.latestRoot 'install-guides'
        if (-not (Test-Path -LiteralPath $guides) -or (Get-Item -LiteralPath $guides).LastWriteTime -lt (Get-Item -LiteralPath $setPath).LastWriteTime) {
            $warnings += "install guides are older than the latest package set; run 'release.cmd docs'"
        }
    } else {
        $warnings += 'latest set not generated yet'
    }
    $ui = Get-UiLintFindings $registry
    $errors += $ui.Errors
    $warnings += $ui.Warnings
    $stray = @(Get-MigrationPlan | Where-Object { -not (Test-Path -LiteralPath $_.To) })
    if ($stray.Count -gt 0) { $warnings += "$($stray.Count) package file(s) outside _releases/<id>/<version>/; run 'release.cmd migrate' to review" }
    foreach ($w in $warnings) { Write-Warning $w }
    foreach ($e in $errors) { Write-Host "[error] $e" -ForegroundColor Red }
    if ($errors.Count -gt 0) { throw "Policy check failed with $($errors.Count) error(s)." }
    Write-Host "[check] OK ($($warnings.Count) warning(s))"
}

function Show-Status {
    $rows = foreach ($info in $allInfos) {
        $built = Test-Path -LiteralPath (Join-Path $info.OutDir $info.Packages[0])
        [pscustomobject]@{
            Plugin   = $info.Id
            Version  = $info.Version
            PHP      = $info.RequiresPhp
            Platform = $info.PlatformRequires
            Builder  = $info.Plugin.builder.type
            Packaged = $(if ($built) { 'yes' } else { 'NO' })
        }
    }
    $rows | Format-Table -AutoSize | Out-String | Write-Host
    Write-Host "Packages: $($registry.releasesRoot)\<plugin>\<version>\"
    Write-Host "Latest set + install guides: $($registry.latestRoot)"
}

# Finds packages outside _releases/<id>/<version>/ (legacy flat files, plugin dist folders).
function Get-MigrationPlan {
    $root = $registry.releasesRoot
    $targets = @{}
    foreach ($p in $registry.plugins) { $targets[$p.id] = $p.id }
    $targets['oj-code-arena-server'] = 'oj-code-arena'
    $ids = @($targets.Keys | Sort-Object Length -Descending)
    $idPattern = ($ids | ForEach-Object { [regex]::Escape($_) }) -join '|'
    $filePattern = "^(?<id>$idPattern)-(?<ver>\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+?)?)(?:-with-library|-wordpress)?(?<ext>\.zip(?:\.sha256|\.manifest\.json)?|\.manifest\.json|\.verification\.json)$"
    $plan = @()
    foreach ($f in Get-ChildItem -LiteralPath $root -File) {
        $m = [regex]::Match($f.Name, $filePattern)
        if ($m.Success) { $plan += [pscustomobject]@{ Op = 'move'; From = $f.FullName; To = (Join-Path (Join-Path $root $targets[$m.Groups['id'].Value]) (Join-Path $m.Groups['ver'].Value $f.Name)) } }
    }
    foreach ($d in Get-ChildItem -LiteralPath $root -Directory) {
        $m = [regex]::Match($d.Name, '^oj-ml-playgrounds-(?<ver>\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$')
        if ($m.Success) {
            foreach ($f in Get-ChildItem -LiteralPath $d.FullName) {
                $plan += [pscustomobject]@{ Op = 'move'; From = $f.FullName; To = (Join-Path $root "oj-ml-playgrounds\$($m.Groups['ver'].Value)\$($f.Name)") }
            }
        }
    }
    # Packages left in the plugins root folder.
    foreach ($f in Get-ChildItem -LiteralPath $script:PluginsRoot -File) {
        $m = [regex]::Match($f.Name, $filePattern)
        if ($m.Success) { $plan += [pscustomobject]@{ Op = 'move'; From = $f.FullName; To = (Join-Path (Join-Path $root $targets[$m.Groups['id'].Value]) (Join-Path $m.Groups['ver'].Value $f.Name)) } }
    }
    # Release worktrees keep their own dist; copy so both stay valid.
    $distDirs = @(Get-ChildItem -LiteralPath $script:PluginsRoot -Directory -Filter '*-release-*' | ForEach-Object { Join-Path $_.FullName 'dist' })
    foreach ($p in @($registry.plugins | Where-Object { $_.builder.type -eq 'prebuilt' })) { $distDirs += [IO.Path]::GetFullPath((Join-Path (Resolve-KitPath $p.source) '..\dist')) }
    foreach ($dist in $distDirs) {
        if (-not (Test-Path -LiteralPath $dist)) { continue }
        foreach ($f in Get-ChildItem -LiteralPath $dist -File) {
            $m = [regex]::Match($f.Name, $filePattern)
            if ($m.Success) { $plan += [pscustomobject]@{ Op = 'copy'; From = $f.FullName; To = (Join-Path (Join-Path $root $targets[$m.Groups['id'].Value]) (Join-Path $m.Groups['ver'].Value $f.Name)) } }
        }
    }
    $legacyDist = Join-Path $script:PluginsRoot 'dist'
    if (Test-Path -LiteralPath $legacyDist) {
        foreach ($f in Get-ChildItem -LiteralPath $legacyDist -File -Filter 'oj-ml-playgrounds-*.zip') {
            $m = [regex]::Match($f.Name, '^oj-ml-playgrounds-(?<ver>.+)\.zip$')
            $plan += [pscustomobject]@{ Op = 'move'; From = $f.FullName; To = (Join-Path $root "oj-ml-playgrounds\$($m.Groups['ver'].Value)\$($f.Name)") }
        }
    }
    $dashDist = Join-Path $script:PluginsRoot 'oj-ai-dashboard\dist'
    if (Test-Path -LiteralPath $dashDist) {
        foreach ($f in Get-ChildItem -LiteralPath $dashDist -File) {
            $m = [regex]::Match($f.Name, '^oj-ai-dashboard-(?<ver>\d+\.\d+\.\d+)\.zip(\.sha256)?$')
            if ($m.Success) { $plan += [pscustomobject]@{ Op = 'copy'; From = $f.FullName; To = (Join-Path $root "oj-ai-dashboard\$($m.Groups['ver'].Value)\$($f.Name)") } }
        }
    }
    return @($plan)
}

# Idempotent move of stray release files into _releases/<id>/<version>/.
function Invoke-Migrate {
    $root = $registry.releasesRoot
    $plan = @(Get-MigrationPlan)
    $todo = @($plan | Where-Object { -not (Test-Path -LiteralPath $_.To) })
    $skipped = $plan.Count - $todo.Count
    Write-Host "[migrate] $($todo.Count) file(s) to relocate, $skipped already in place."
    if (-not $Apply) {
        $todo | Select-Object -First 30 | ForEach-Object { Write-Host "  $($_.Op): $($_.From.Substring($script:PluginsRoot.Length + 1)) -> $($_.To.Substring($script:PluginsRoot.Length + 1))" }
        if ($todo.Count -gt 30) { Write-Host "  ... and $($todo.Count - 30) more" }
        Write-Host 'Dry run only. Re-run with -Apply to perform it.'
        return
    }
    $log = New-Object System.Collections.Generic.List[string]
    foreach ($item in $todo) {
        [IO.Directory]::CreateDirectory((Split-Path -Parent $item.To)) | Out-Null
        if ($item.Op -eq 'move') { Move-Item -LiteralPath $item.From -Destination $item.To } else { Copy-Item -LiteralPath $item.From -Destination $item.To }
        $log.Add("$((Get-Date).ToString('s'))`t$($item.Op)`t$($item.From)`t$($item.To)")
    }
    foreach ($d in Get-ChildItem -LiteralPath $root -Directory | Where-Object { $_.Name -match '^oj-ml-playgrounds-\d' }) {
        if (@(Get-ChildItem -LiteralPath $d.FullName -Force).Count -eq 0) { Remove-Item -LiteralPath $d.FullName }
    }
    $logPath = Join-Path $root 'MIGRATION-LOG.tsv'
    $previous = ''
    if (Test-Path -LiteralPath $logPath) { $previous = Read-Utf8 $logPath }
    Write-Utf8 $logPath ($previous + (($log -join "`n") + "`n"))
    Write-Host "[migrate] done; log: $logPath"
}

switch ($Command) {
    'status'  { Show-Status }
    'package' { Invoke-Package }
    'latest'  { Sync-Latest }
    'content' { Sync-ContentLatest }
    'docs'    { Build-InstallGuides $registry $allInfos }
    'check'   { Invoke-Check }
    'migrate' { Invoke-Migrate }
    'all'     {
        $packageError = $null
        try { Invoke-Package } catch { $packageError = $_ }
        Build-InstallGuides $registry $allInfos
        Invoke-Check
        if ($packageError) { throw $packageError }
    }
}
