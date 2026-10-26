# Content sources stay in their authoring projects; this catalog owns distribution.
# Keep scripts ASCII-only for Windows PowerShell 5.1.

function Assert-ContentRelative([string]$Path) {
    if (-not $Path -or [IO.Path]::IsPathRooted($Path) -or
        $Path -match '[:\\]' -or $Path -match '(^|/)\.\.?(/|$)' -or
        $Path -match '(^/|/$|//)') { throw "Unsafe content path: $Path" }
}

function Assert-ContentDirectory([string]$Path, [string]$Root) {
    Assert-UnderRoot $Path $Root
    if ([IO.Path]::GetFullPath($Path).TrimEnd('\') -eq [IO.Path]::GetFullPath($Root).TrimEnd('\')) {
        throw "Content operation cannot target its root: $Path"
    }
    $current = [IO.Path]::GetFullPath($Path)
    while ($current) {
        if (Test-Path -LiteralPath $current) {
            if ((Get-Item -LiteralPath $current -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
                throw "Content operation cannot traverse a link: $current"
            }
        }
        $current = Split-Path -Parent $current
    }
}

function Get-ContentPlan($Registry, $Infos) {
    $files = @(); $collections = @(); $targets = @{}; $ids = @{}
    foreach ($info in $Infos) {
        $catalogPath = Get-Prop $info.Plugin 'contentCatalog' ''
        if ($catalogPath) {
            $catalog = Read-Utf8 (Resolve-KitPath $catalogPath) | ConvertFrom-Json
            if ($catalog.schemaVersion -ne 1) { throw "Unsupported content catalog: $catalogPath" }
            foreach ($group in $catalog.collections) {
                if ($group.id -notmatch '^[a-z0-9]+(?:-[a-z0-9]+)*$' -or $ids.ContainsKey($group.id)) {
                    throw "Invalid or duplicate content collection: $($group.id)"
                }
                $ids[$group.id] = $true
                $sourceRoot = Resolve-KitPath $group.sourceRoot
                if (-not (Test-Path -LiteralPath $sourceRoot -PathType Container)) { throw "Content source missing: $sourceRoot" }
                $collections += [ordered]@{
                    id = $group.id; title = $group.title; status = $group.status; note = $group.note
                    editingRoot = $group.editingRoot; pluginId = $info.Id
                }
                foreach ($file in $group.files) {
                    $source = if ($file -is [string]) { $file } else { $file.source }
                    $target = if ($file -is [string]) { $file } else { $file.target }
                    Assert-ContentRelative $source
                    Assert-ContentRelative $target
                    $files += [pscustomobject]@{
                        PluginId = $info.Id; CollectionId = $group.id
                        Source = [IO.Path]::GetFullPath((Join-Path $sourceRoot $source))
                        Target = "$($group.id)/$target"
                    }
                }
            }
        }
        # Preserve the old flat-file registry contract for other addons.
        foreach ($relative in @(Get-Prop $info.Plugin 'contentFiles' @())) {
            $source = Resolve-KitPath $relative
            $files += [pscustomobject]@{
                PluginId = $info.Id; CollectionId = ''; Source = $source; Target = [IO.Path]::GetFileName($source)
            }
        }
    }
    $latestPrefix = [IO.Path]::GetFullPath($Registry.latestRoot).TrimEnd('\') + '\'
    foreach ($file in $files) {
        Assert-ContentRelative $file.Target
        if ($targets.ContainsKey($file.Target) -or $file.Target -in @('README.md', 'content-index.json', 'SHA256SUMS.txt')) {
            throw "Duplicate or reserved content target: $($file.Target)"
        }
        $targets[$file.Target] = $true
        if ($file.Source.StartsWith($latestPrefix, [StringComparison]::OrdinalIgnoreCase)) {
            throw "Content source cannot be generated latest output: $($file.Source)"
        }
        if (-not (Test-Path -LiteralPath $file.Source -PathType Leaf)) { throw "Content file missing: $($file.Source)" }
        $bad = Test-TextEncoding $file.Source
        if ($bad) { throw "$($file.Source): $bad" }
        $file | Add-Member -NotePropertyName Sha256 -NotePropertyValue (Get-Sha256 $file.Source)
        $file | Add-Member -NotePropertyName Bytes -NotePropertyValue (Get-Item -LiteralPath $file.Source).Length
    }
    return [pscustomobject]@{ Files = @($files); Collections = @($collections) }
}

function Write-ContentSnapshot($Registry, $Plan) {
    $tmpRoot = Resolve-KitPath '_notes/tmp'
    $stage = Join-Path $tmpRoot ("content-stage-" + [guid]::NewGuid().ToString('N'))
    $backup = Join-Path $tmpRoot ("content-backup-" + [guid]::NewGuid().ToString('N'))
    $destination = Join-Path $Registry.latestRoot 'content'
    Assert-ContentDirectory $stage $tmpRoot
    Assert-ContentDirectory $backup $tmpRoot
    Assert-ContentDirectory $destination $Registry.latestRoot
    $movedOld = $false
    try {
        [IO.Directory]::CreateDirectory($stage) | Out-Null
        $entries = @(); $sums = @()
        foreach ($file in $Plan.Files) {
            $target = Join-Path $stage $file.Target
            Assert-UnderRoot $target $stage
            [IO.Directory]::CreateDirectory((Split-Path -Parent $target)) | Out-Null
            Copy-Item -LiteralPath $file.Source -Destination $target
            if ((Get-Sha256 $target) -ne $file.Sha256) { throw "Content changed while copying: $($file.Source)" }
            $entries += [ordered]@{
                pluginId = $file.PluginId; collectionId = $file.CollectionId; path = $file.Target
                sha256 = $file.Sha256; bytes = $file.Bytes
            }
            $sums += "$($file.Sha256)  $($file.Target)"
        }
        $index = [ordered]@{
            schemaVersion = 1; generatedAt = (Get-Date).ToString('s')
            collections = @($Plan.Collections); files = $entries
        }
        Write-Utf8 (Join-Path $stage 'content-index.json') ($index | ConvertTo-Json -Depth 8)
        $readme = Read-Utf8 (Join-Path $script:KitRoot 'templates/content-README.md')
        foreach ($group in $Plan.Collections) {
            $readme += "`n## $($group.title)`n`n$($group.status). $($group.note)`n`n"
            foreach ($file in @($Plan.Files | Where-Object { $_.CollectionId -eq $group.id })) {
                $readme += "- [$($file.Target)]($([Uri]::EscapeUriString($file.Target)))`n"
            }
        }
        Write-Utf8 (Join-Path $stage 'README.md') $readme
        foreach ($name in @('content-index.json', 'README.md')) { $sums += "$(Get-Sha256 (Join-Path $stage $name))  $name" }
        Write-Utf8 (Join-Path $stage 'SHA256SUMS.txt') (($sums -join "`n") + "`n")
        [IO.Directory]::CreateDirectory($Registry.latestRoot) | Out-Null
        if (Test-Path -LiteralPath $destination) {
            Move-Item -LiteralPath $destination -Destination $backup
            $movedOld = $true
        }
        try { Move-Item -LiteralPath $stage -Destination $destination }
        catch {
            if ($movedOld) { Move-Item -LiteralPath $backup -Destination $destination; $movedOld = $false }
            throw
        }
        if ($movedOld) { Remove-Item -LiteralPath $backup -Recurse -Force }
    } finally {
        if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
    }
    Write-Host "[content] $($Plan.Files.Count) files: $destination"
}

function Set-ContentEntries($Set, $Plan) {
    foreach ($entry in $Set.plugins) {
        $files = @($Plan.Files | Where-Object { $_.PluginId -eq $entry.id })
        $entry | Add-Member -NotePropertyName contentFiles -NotePropertyValue @($files | ForEach-Object { $_.Target }) -Force
        $entry | Add-Member -NotePropertyName contentSha256 -NotePropertyValue @($files | ForEach-Object { $_.Sha256 }) -Force
    }
}

function Get-ContentChecks($Registry, $Plan) {
    $directory = Join-Path $Registry.latestRoot 'content'
    $indexPath = Join-Path $directory 'content-index.json'
    if (-not (Test-Path -LiteralPath $indexPath)) { return @('Content index missing; run release.cmd content') }
    $index = Read-Utf8 $indexPath | ConvertFrom-Json
    $errors = @()
    foreach ($file in $Plan.Files) {
        $target = Join-Path $directory $file.Target
        $record = @($index.files | Where-Object { $_.path -ceq $file.Target })
        if ($record.Count -ne 1 -or $record[0].sha256 -ne $file.Sha256) {
            $errors += "Content index is stale: $($file.Target)"
        }
        if (-not (Test-Path -LiteralPath $target -PathType Leaf) -or (Get-Sha256 $target) -ne $file.Sha256) {
            $errors += "Content missing, stale or corrupted: $($file.Target)"
        }
    }
    if (@($index.files).Count -ne @($Plan.Files).Count) { $errors += 'Content index file count differs from catalog' }
    $expected = @($Plan.Files | ForEach-Object { $_.Target }) + @('README.md', 'content-index.json', 'SHA256SUMS.txt')
    foreach ($file in Get-ChildItem -LiteralPath $directory -Recurse -File) {
        $relative = $file.FullName.Substring($directory.Length + 1).Replace('\', '/')
        if ($relative -notin $expected) { $errors += "Unregistered latest content: $relative" }
    }
    $sumPath = Join-Path $directory 'SHA256SUMS.txt'
    if (-not (Test-Path -LiteralPath $sumPath)) { $errors += 'Content SHA256SUMS.txt missing' }
    else {
        $lines = @((Read-Utf8 $sumPath) -split "`n" | Where-Object { $_ })
        if ($lines.Count -ne $expected.Count - 1) { $errors += 'Content checksum count mismatch' }
        foreach ($line in $lines) {
            if ($line -notmatch '^([a-f0-9]{64})  (.+)$') { $errors += 'Invalid content checksum line'; continue }
            $hash = $Matches[1]; $relative = $Matches[2]
            Assert-ContentRelative $relative
            $file = Join-Path $directory $relative
            if ($relative -notin $expected -or -not (Test-Path -LiteralPath $file) -or (Get-Sha256 $file) -ne $hash) {
                $errors += "Content checksum mismatch: $relative"
            }
        }
    }
    return $errors
}
