<#
D-010 P4: addon UI rules checked by 'release.cmd check'. ASCII-only file.

Errors (block the check):
  - bare global selectors in addon CSS: :root, html, body, *, or an element
    selector with no class/attribute (button, input, a, h1 ...). Addon CSS must
    stay inside its own root class; html:has(...) and html[data-ojp-...] are allowed.
Warnings:
  - hex colours outside var() fallbacks (use --ojp-* tokens)
  - !important
  - confirm()/alert() in a script that never mentions OJP (use OJP.confirm / OJP.toast)

Compiled bundles and game artwork are listed per plugin in plugins.json "uiLintExclude"
(globs relative to the plugin folder, forward slashes).
#>

$script:UiLintSkip = '[\\/](vendor|node_modules|dist|build|tests?|_notes|docs|tmp|\.cache)[\\/]|\.min\.'
$script:UiLintGlobal = '^(:root|html|body|\*|button|input|select|textarea|a|h[1-6]|p|ul|ol|li|table|th|td|img|label|form|fieldset|legend)(\s|$|:(?!has\()|>|~|\+)'

function Test-UiLintExcluded([string]$Relative, [string[]]$Patterns) {
    foreach ($pattern in $Patterns) {
        if ($Relative -like $pattern) { return $true }
    }
    return $false
}

function Split-CssSelectors([string]$List) {
    # Split a selector list on top-level commas only (not inside :where(...), :not(...)).
    $parts = @(); $depth = 0; $start = 0
    for ($i = 0; $i -lt $List.Length; $i++) {
        $c = $List[$i]
        if ($c -eq '(' -or $c -eq '[') { $depth++ }
        elseif ($c -eq ')' -or $c -eq ']') { $depth-- }
        elseif ($c -eq ',' -and $depth -eq 0) { $parts += $List.Substring($start, $i - $start); $start = $i + 1 }
    }
    $parts += $List.Substring($start)
    return $parts
}

function Get-UiLintFindings($Registry) {
    $errors = @(); $warnings = @()
    foreach ($plugin in $Registry.plugins) {
        if ($plugin.id -eq 'oj-platform') { continue }
        $root = Resolve-KitPath $plugin.source
        if (-not (Test-Path -LiteralPath $root)) { continue }
        $exclude = @()
        if ($plugin.PSObject.Properties.Name -contains 'uiLintExclude') { $exclude = @($plugin.uiLintExclude) }
        $hexTotal = 0; $importantTotal = 0
        foreach ($file in Get-ChildItem -LiteralPath $root -Recurse -File) {
            if ($file.Extension -notin @('.css', '.js')) { continue }
            if ($file.FullName -match $script:UiLintSkip) { continue }
            $relative = $file.FullName.Substring($root.Length).TrimStart('\', '/').Replace('\', '/')
            if (Test-UiLintExcluded $relative $exclude) { continue }
            $text = [IO.File]::ReadAllText($file.FullName, [Text.Encoding]::UTF8)
            if ($file.Extension -eq '.css') {
                $css = [regex]::Replace($text, '/\*[\s\S]*?\*/', '')
                foreach ($m in [regex]::Matches($css, '(?:^|[{}])\s*([^{}@][^{}]*?)\{')) {
                    foreach ($selector in (Split-CssSelectors $m.Groups[1].Value)) {
                        $s = $selector.Trim()
                        if ($s -match $script:UiLintGlobal -and $s -notmatch '^html(:has\(|\[data-ojp)') {
                            $errors += "$($plugin.id)/${relative}: global selector '$s' (scope it under the addon root class)"
                        }
                    }
                }
                $noVar = [regex]::Replace($css, 'var\([^)]*\)', '')
                $hexTotal += [regex]::Matches($noVar, '#[0-9a-fA-F]{3,8}\b').Count
                $importantTotal += [regex]::Matches($css, '!important').Count
            } elseif ($text.Length -lt 400000) {
                if ($text -match '\b(window\.)?(confirm|alert)\s*\(' -and $text -notmatch 'OJP') {
                    $warnings += "$($plugin.id)/${relative}: confirm()/alert() without OJP.confirm / OJP.toast"
                }
            }
        }
        if ($hexTotal -gt 0) { $warnings += "$($plugin.id): $hexTotal hex colour(s) outside var() in addon CSS (use --ojp-* tokens)" }
        if ($importantTotal -gt 0) { $warnings += "$($plugin.id): $importantTotal !important in addon CSS" }
    }
    return [pscustomobject]@{ Errors = $errors; Warnings = $warnings }
}
