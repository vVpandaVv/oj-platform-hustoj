# Install-guide generation. ASCII-only (see Common.ps1).

function ConvertTo-HtmlText([string]$Text) {
    return $Text.Replace('&', '&amp;').Replace('<', '&lt;').Replace('>', '&gt;').Replace('"', '&quot;')
}

function Convert-Inline([string]$Text) {
    $codes = New-Object System.Collections.Generic.List[string]
    $t = [regex]::Replace($Text, '`([^`]+)`', { param($m) $codes.Add($m.Groups[1].Value); "@@CODE$($codes.Count - 1)@@" })
    $t = ConvertTo-HtmlText $t
    $t = [regex]::Replace($t, '\*\*(.+?)\*\*', '<strong>$1</strong>')
    $t = [regex]::Replace($t, '\[([^\]]+)\]\(([^)\s]+)\)', '<a href="$2">$1</a>')
    for ($i = 0; $i -lt $codes.Count; $i++) { $t = $t.Replace("@@CODE$i@@", '<code>' + (ConvertTo-HtmlText $codes[$i]) + '</code>') }
    return $t
}

function Get-Slug([string]$Text) {
    $s = ($Text.ToLowerInvariant() -replace '[^\p{L}\p{N}]+', '-').Trim('-')
    if (-not $s) { $s = 'section' }
    return $s
}

# Small Markdown subset: headings, paragraphs, nested lists, fenced code,
# blockquotes, tables, bold, inline code, links. Enough for install guides.
function Convert-MarkdownToHtml([string]$Markdown, [string]$IdPrefix) {
    $lines = $Markdown.Replace("`r`n", "`n").Split("`n")
    $html = New-Object System.Text.StringBuilder
    $para = New-Object System.Collections.Generic.List[string]
    $listStack = New-Object System.Collections.Generic.List[object]
    $headings = New-Object System.Collections.Generic.List[object]
    $i = 0

    $flushPara = {
        if ($para.Count -gt 0) { [void]$html.Append('<p>' + (Convert-Inline ($para -join ' ')) + "</p>`n"); $para.Clear() }
    }
    $closeLists = {
        param($toDepth)
        while ($listStack.Count -gt $toDepth) {
            $top = $listStack[$listStack.Count - 1]
            [void]$html.Append("</li></$($top.tag)>`n")
            $listStack.RemoveAt($listStack.Count - 1)
        }
    }

    while ($i -lt $lines.Length) {
        $line = $lines[$i]
        if ($line -match '^\s*```') {
            & $flushPara; & $closeLists 0
            $code = New-Object System.Collections.Generic.List[string]
            $i++
            while ($i -lt $lines.Length -and $lines[$i] -notmatch '^\s*```') { $code.Add($lines[$i]); $i++ }
            [void]$html.Append('<pre><code>' + (ConvertTo-HtmlText ($code -join "`n")) + "</code></pre>`n")
            $i++; continue
        }
        if ($line -match '^(#{1,4})\s+(.+)$') {
            & $flushPara; & $closeLists 0
            $level = $Matches[1].Length
            $text = $Matches[2].Trim()
            $id = $IdPrefix + '-' + (Get-Slug $text)
            $headings.Add([pscustomobject]@{ Level = $level; Text = $text; Id = $id })
            [void]$html.Append("<h$level id=`"$id`">" + (Convert-Inline $text) + "</h$level>`n")
            $i++; continue
        }
        if ($line -match '^\s*\|.*\|\s*$') {
            & $flushPara; & $closeLists 0
            $rows = New-Object System.Collections.Generic.List[string]
            while ($i -lt $lines.Length -and $lines[$i] -match '^\s*\|.*\|\s*$') { $rows.Add($lines[$i].Trim()); $i++ }
            [void]$html.Append("<div class=`"table-wrap`"><table>`n")
            $r = 0
            foreach ($row in $rows) {
                $cells = $row.Trim('|').Split('|') | ForEach-Object { $_.Trim() }
                if ($r -eq 1 -and ($cells -join '') -match '^[-: ]+$') { $r++; continue }
                $tag = 'td'; if ($r -eq 0) { $tag = 'th' }
                [void]$html.Append('<tr>' + (($cells | ForEach-Object { "<$tag>" + (Convert-Inline $_) + "</$tag>" }) -join '') + "</tr>`n")
                $r++
            }
            [void]$html.Append("</table></div>`n")
            continue
        }
        if ($line -match '^>\s?(.*)$') {
            & $flushPara; & $closeLists 0
            $quote = New-Object System.Collections.Generic.List[string]
            while ($i -lt $lines.Length -and $lines[$i] -match '^>\s?(.*)$') { $quote.Add($Matches[1]); $i++ }
            [void]$html.Append('<div class="note">' + (Convert-Inline ($quote -join ' ')) + "</div>`n")
            continue
        }
        if ($line -match '^(\s*)([-*]|\d+\.)\s+(.+)$') {
            & $flushPara
            $depth = [math]::Floor($Matches[1].Length / 2) + 1
            $marker = $Matches[2]
            $text = $Matches[3]
            $tag = 'ul'; if ($marker -match '\d') { $tag = 'ol' }
            if ($depth -gt $listStack.Count + 1) { $depth = $listStack.Count + 1 }
            & $closeLists $depth
            if ($listStack.Count -eq $depth) {
                [void]$html.Append("</li>`n<li>")
            } else {
                $start = ''
                if ($tag -eq 'ol' -and [int]($marker.TrimEnd('.')) -gt 1) { $start = " start=`"$($marker.TrimEnd('.'))`"" }
                [void]$html.Append("<$tag$start><li>")
                $listStack.Add([pscustomobject]@{ tag = $tag })
            }
            $item = Convert-Inline $text
            if ($item.StartsWith('[ ] ')) { $item = '&#9744; ' + $item.Substring(4) }
            elseif ($item.StartsWith('[x] ')) { $item = '&#9745; ' + $item.Substring(4) }
            [void]$html.Append($item)
            $i++; continue
        }
        if ($line.Trim() -eq '') {
            & $flushPara
            $next = $i + 1
            while ($next -lt $lines.Length -and $lines[$next].Trim() -eq '') { $next++ }
            if ($next -ge $lines.Length -or $lines[$next] -notmatch '^\s+\S|^\s*([-*]|\d+\.)\s') { & $closeLists 0 }
            $i++; continue
        }
        if ($listStack.Count -gt 0 -and $line -match '^\s{2,}(\S.*)$') {
            [void]$html.Append(' ' + (Convert-Inline $Matches[1]))
            $i++; continue
        }
        & $closeLists 0
        $para.Add($line.Trim())
        $i++
    }
    & $flushPara; & $closeLists 0
    return [pscustomobject]@{ Html = $html.ToString(); Headings = $headings }
}

function Expand-Template([string]$Text, [hashtable]$Vars, [string]$Label) {
    foreach ($key in $Vars.Keys) { $Text = $Text.Replace('{{' + $key + '}}', [string]$Vars[$key]) }
    $left = [regex]::Matches($Text, '\{\{[a-z_]+\}\}') | ForEach-Object { $_.Value } | Select-Object -Unique
    if ($left) { throw "Unknown placeholder(s) in ${Label}: $($left -join ', ')" }
    return $Text
}

function Get-LatestEntry($ReleaseSet, [string]$Id) {
    if (-not $ReleaseSet) { return $null }
    return @($ReleaseSet.plugins | Where-Object { $_.id -eq $Id })[0]
}

function Get-PlatformVar([string]$Range) {
    if (-not $Range) { return '-' }
    return $Range
}

function Build-InstallGuides($Registry, $Infos) {
    $kit = $script:KitRoot
    $outDir = Join-Path $Registry.latestRoot 'install-guides'
    Assert-UnderRoot $outDir $Registry.latestRoot
    if (Test-Path -LiteralPath $outDir) { Remove-Item -LiteralPath $outDir -Recurse -Force }
    [IO.Directory]::CreateDirectory($outDir) | Out-Null

    $setPath = Join-Path $Registry.latestRoot 'release-set.json'
    $releaseSet = $null
    if (Test-Path -LiteralPath $setPath) { $releaseSet = Read-Utf8 $setPath | ConvertFrom-Json }
    $generated = (Get-Date).ToString('yyyy-MM-dd HH:mm')
    $S = Read-Utf8 (Join-Path $kit 'templates/strings.json') | ConvertFrom-Json

    $rows = @($S.tableHeader, $S.tableSeparator)
    $n = 0
    foreach ($info in $Infos) {
        $n++
        $entry = Get-LatestEntry $releaseSet $info.Id
        $file = $S.notPackagedShort
        if ($entry -and $entry.status -eq 'ready') { $file = '`' + ($entry.files -join '`, `') + '`' }
        $rows += "| $n | [$($info.Name)]($('{0:D2}' -f $n)-$($info.Id).md) | $($info.Version) | $($info.RequiresWp)+ | $($info.RequiresPhp)+ | $(Get-PlatformVar $info.PlatformRequires) | $file |"
    }

    $docs = New-Object System.Collections.Generic.List[object]
    $platformPackage = 'oj-platform-x.y.z.zip'
    $platformSet = Get-LatestEntry $releaseSet 'oj-platform'
    if ($platformSet -and @($platformSet.files).Count -gt 0) { $platformPackage = @($platformSet.files)[0] }
    $arenaServer = 'oj-code-arena-server-x.y.z.zip'
    $arenaSet = Get-LatestEntry $releaseSet 'oj-code-arena'
    if ($arenaSet) { $found = @(@($arenaSet.files) | Where-Object { $_ -like 'oj-code-arena-server-*' }); if ($found.Count -gt 0) { $arenaServer = $found[0] } }
    $contentPackage = 'content.zip'
    $rcSet = Get-LatestEntry $releaseSet 'readcontents-library'
    if ($rcSet -and @($rcSet.contentFiles).Count -gt 0) { $contentPackage = @($rcSet.contentFiles)[0] }
    $common = @{ generated_at = $generated; plugin_table = ($rows -join "`n"); platform_package = $platformPackage; arena_server_package = $arenaServer; content_package = $contentPackage }
    $overview = Expand-Template (Read-Utf8 (Join-Path $kit 'guides/_overview.md')) $common '_overview.md'
    Write-Utf8 (Join-Path $outDir '00-overview.md') $overview
    $docs.Add([pscustomobject]@{ Id = 'overview'; Title = $S.overviewTitle; Markdown = $overview })

    $server = Expand-Template (Read-Utf8 (Join-Path $kit 'guides/_server.md')) $common '_server.md'
    Write-Utf8 (Join-Path $outDir '00-server-setup.md') $server
    $docs.Add([pscustomobject]@{ Id = 'server'; Title = $S.serverTitle; Markdown = $server })

    $header = Read-Utf8 (Join-Path $kit 'guides/_plugin-header.md')
    $footer = Read-Utf8 (Join-Path $kit 'guides/_plugin-footer.md')
    $n = 0
    foreach ($info in $Infos) {
        $n++
        $entry = Get-LatestEntry $releaseSet $info.Id
        $packageList = $S.notPackagedLong
        $shaList = '-'
        $mainPackage = "$($info.Id)-$($info.Version).zip"
        $contentList = '-'
        $contentFile = '-'
        if ($entry -and @($entry.contentFiles).Count -gt 0) {
            $contentList = (@($entry.contentFiles) | ForEach-Object { '`content/' + $_ + '`' }) -join ', '
            $contentFile = @($entry.contentFiles)[0]
        }
        if ($entry -and $entry.status -eq 'ready') {
            $packageList = ($entry.files | ForEach-Object { '`packages/' + $_ + '`' }) -join ', '
            $shaList = ($entry.sha256 | ForEach-Object { '`' + $_ + '`' }) -join ' / '
        }
        $vars = @{
            name = $info.Name; id = $info.Id; version = $info.Version; order = $n
            requires_wp = $info.RequiresWp; requires_php = $info.RequiresPhp
            platform_requires = (Get-PlatformVar $info.PlatformRequires)
            package = $mainPackage; package_list = $packageList; sha256 = $shaList; content_files = $contentList; content_file = $contentFile; platform_package = $platformPackage
            generated_at = $generated
        }
        $guidePath = Join-Path $kit $info.Plugin.guide
        if (-not (Test-Path -LiteralPath $guidePath)) { throw "Guide source missing for $($info.Id): $guidePath" }
        $text = (Expand-Template $header $vars '_plugin-header.md') + "`n" + (Expand-Template (Read-Utf8 $guidePath) $vars $info.Plugin.guide) + "`n" + (Expand-Template $footer $vars '_plugin-footer.md')
        $fileName = ('{0:D2}' -f $n) + "-$($info.Id).md"
        Write-Utf8 (Join-Path $outDir $fileName) $text
        $docs.Add([pscustomobject]@{ Id = $info.Id; Title = $info.Name; Markdown = $text })
    }

    # One printable HTML with every guide, for handing to installers.
    $toc = New-Object System.Text.StringBuilder
    $body = New-Object System.Text.StringBuilder
    foreach ($doc in $docs) {
        $converted = Convert-MarkdownToHtml $doc.Markdown $doc.Id
        $first = @($converted.Headings | Where-Object { $_.Level -eq 1 })[0]
        $anchor = $doc.Id; if ($first) { $anchor = $first.Id }
        [void]$toc.Append("<li><a href=`"#$anchor`">" + (ConvertTo-HtmlText $doc.Title) + "</a></li>`n")
        [void]$body.Append("<section class=`"doc`">`n" + $converted.Html + "</section>`n")
    }
    $page = Read-Utf8 (Join-Path $kit 'templates/install-guide.html')
    # The template carries a notice for people who open it directly; drop it from the output.
    $page = [regex]::Replace($page, '(?s)<!--TEMPLATE-ONLY-->.*?<!--/TEMPLATE-ONLY-->
?', '')
    $page = Expand-Template $page @{ generated_at = $generated; toc = $toc.ToString(); content = $body.ToString() } 'install-guide.html'
    Write-Utf8 (Join-Path $outDir 'install-guide.html') $page
    Write-Host "[docs] $($docs.Count) guides -> $outDir"
}
