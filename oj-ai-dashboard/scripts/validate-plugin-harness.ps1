param(
    [switch] $Strict
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$errors = New-Object System.Collections.Generic.List[string]
$warnings = New-Object System.Collections.Generic.List[string]

function Add-ErrorMessage {
    param([string] $Message)
    $errors.Add($Message) | Out-Null
}

function Add-WarningMessage {
    param([string] $Message)
    $warnings.Add($Message) | Out-Null
}

function Get-PhpExecutable {
    if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
        return $env:OJAI_PHP
    }

    $localPhp = 'D:\php\php.exe'
    if (Test-Path -LiteralPath $localPhp) {
        return $localPhp
    }

    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    return $null
}

Write-Host 'OJ AI Assessment design-phase repository harness'
Write-Host 'This gate validates the design baseline, not production readiness.'
Write-Host "Root: $root"
Write-Host ''

$requiredPaths = @(
    'AGENTS.md',
    '_notes\agent-harness.md',
    'docs\ai-assessment\styles.css',
    'docs\ai-assessment\index.html',
    'docs\ai-assessment\design-procedure.html',
    'docs\ai-assessment\redesign.html',
    'docs\ai-assessment\implementation-roadmap.html',
    'docs\ai-assessment\g2-imp-01-corrective-revalidation.html',
    'docs\ai-assessment\g2-imp-02-s01-plate-runtime-entrypoint.html',
    'docs\ai-assessment\g2-imp-02-s02-c02-contract.html',
    'docs\ai-assessment\code-consistency.html',
    'docs\ai-assessment\a-d-001-boundary-dependencies.html',
    'docs\ai-assessment\a-d-002-screen-entrypoints.html',
    'docs\ai-assessment\a-d-003-access-settings.html',
    'docs\ai-assessment\a-d-004-data-storage.html',
    'docs\ai-assessment\a-d-005-worksheet-plate.html',
    'docs\ai-assessment\a-d-006-ai-evaluation-flow.html',
    'docs\ai-assessment\a-d-007-user-workflow-risks.html',
    'docs\ai-assessment\a-d-008-module-data-ownership.html',
    'docs\ai-assessment\a-d-009-baseline-gap-freeze.html',
    'docs\ai-assessment\a-ds-00-scope-baseline.html',
    'docs\ai-assessment\a-ds-01-ui-system.html',
    'docs\ai-assessment\a-ds-01-ui-prototype.html',
    'docs\ai-assessment\a-ds-02-addon-lifecycle.html',
    'docs\ai-assessment\a-ds-02-addon-api-profile.html',
    'docs\ai-assessment\a-ds-02-am-01-onready-adoption.html',
    'docs\ai-assessment\a-ds-03-access-policy.html',
    'docs\ai-assessment\a-ds-04-settings-ai-connection.html',
    'docs\ai-assessment\a-ds-05-addon-database.html',
    'docs\ai-assessment\a-ds-06-platform-ledger-connector.html',
    'docs\ai-assessment\a-ds-07-ai-job-execution.html',
    'docs\ai-assessment\ui-prototype.css',
    'docs\ai-assessment\ui-prototype.js',
    'composer.json',
    'package.json',
    'scripts\check-ai-assessment-design.mjs',
    'scripts\validate-ui-prototype.mjs',
    'tests\Contract\retired-runtime.php',
    'tests\fixtures\retired-runtime.json',
    'scripts\fingerprint-runtime-source.mjs',
    'scripts\run-tests.mjs',
    'scripts\check-worksheet-save-draft-c02.ps1',
    'scripts\check-quality.ps1',
    'scripts\harness-forbidden-runtime-text.txt',
    'src\Database\Table_Names.php',
    'src\Worksheet\Authoring\Application\Save_Worksheet_Draft_Command.php',
    'src\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result.php',
    'tests\Contract\worksheet-save-draft-c02-contract.php'
)

$missingPaths = @()
foreach ($relativePath in $requiredPaths) {
    if (-not (Test-Path -LiteralPath (Join-Path $root $relativePath))) {
        $missingPaths += $relativePath
    }
}
if ($missingPaths.Count -gt 0) {
    foreach ($relativePath in $missingPaths) {
        Add-ErrorMessage "Required path not found: $relativePath"
    }
} else {
    Write-Host "[OK] Required baseline paths: $($requiredPaths.Count)"
}

$git = Get-Command git -ErrorAction SilentlyContinue
$insideWorktree = if ($git) { & $git.Source -C $root rev-parse --is-inside-work-tree 2>$null } else { $null }
if (-not $git -or $LASTEXITCODE -ne 0 -or $insideWorktree -ne 'true') {
    Add-WarningMessage 'Git metadata not found; scoped change verification is unavailable.'
} else {
    Write-Host '[OK] Git repository metadata'
}

$node = Get-Command node -ErrorAction SilentlyContinue
if (-not $node) {
    Add-ErrorMessage 'Node.js is required for the canonical HTML design validation.'
} else {
    & $node.Source (Join-Path $root 'scripts\check-ai-assessment-design.mjs')
    if ($LASTEXITCODE -ne 0) {
        Add-ErrorMessage 'Canonical HTML design validation failed.'
    }
}

$textExtensions = @('.php', '.js', '.mjs', '.ts', '.tsx', '.css', '.html', '.json', '.md', '.ps1', '.xml', '.neon', '.dist', '.txt')
$textFiles = @(
    Get-ChildItem -LiteralPath $root -Recurse -File |
        Where-Object {
            $textExtensions -contains $_.Extension -and
            $_.FullName -notmatch '\\(?:\.git|\.cache|\.phpunit\.cache|node_modules|vendor|build|coverage)\\' -and
            $_.FullName -notmatch '\\resources\\(?:plate\\whole-editor|assets\\builder)\\'
        }
)
$utf8 = [System.Text.UTF8Encoding]::new($false, $true)
foreach ($file in $textFiles) {
    $bytes = [System.IO.File]::ReadAllBytes($file.FullName)
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        Add-ErrorMessage "UTF-8 BOM found: $($file.FullName.Substring($root.Length + 1))"
        continue
    }
    try {
        $text = $utf8.GetString($bytes)
        if ($text.IndexOf([char]0xFFFD) -ge 0) {
            Add-ErrorMessage "Replacement character U+FFFD found: $($file.FullName.Substring($root.Length + 1))"
        }
    } catch {
        Add-ErrorMessage "Invalid UTF-8: $($file.FullName.Substring($root.Length + 1))"
    }
}
Write-Host "[OK] UTF-8 without BOM checked: $($textFiles.Count) files"

$excludedEncodingFiles = New-Object System.Collections.Generic.List[System.IO.FileInfo]
foreach ($relativeRoot in @('resources\plate\whole-editor\src', 'resources\assets\builder')) {
    $encodingRoot = Join-Path $root $relativeRoot
    if (-not (Test-Path -LiteralPath $encodingRoot)) {
        continue
    }
    Get-ChildItem -LiteralPath $encodingRoot -Recurse -File |
        Where-Object { $textExtensions -contains $_.Extension } |
        ForEach-Object { $excludedEncodingFiles.Add($_) | Out-Null }
}

$excludedEncodingDebt = New-Object System.Collections.Generic.List[string]
$generatedBuilderRoot = (Join-Path $root 'resources\assets\builder') + [System.IO.Path]::DirectorySeparatorChar
foreach ($file in $excludedEncodingFiles) {
    $bytes = [System.IO.File]::ReadAllBytes($file.FullName)
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        $excludedEncodingDebt.Add($file.FullName.Substring($root.Length + 1)) | Out-Null
        continue
    }
    try {
        $text = $utf8.GetString($bytes)
        $isGeneratedBuilder = $file.FullName.StartsWith(
            $generatedBuilderRoot,
            [System.StringComparison]::OrdinalIgnoreCase
        )
        if (-not $isGeneratedBuilder -and $text.IndexOf([char]0xFFFD) -ge 0) {
            $excludedEncodingDebt.Add($file.FullName.Substring($root.Length + 1)) | Out-Null
        }
    } catch {
        $excludedEncodingDebt.Add($file.FullName.Substring($root.Length + 1)) | Out-Null
    }
}
if ($excludedEncodingDebt.Count -gt 0) {
    Add-WarningMessage "Imported Plate or generated builder encoding debt remains in $($excludedEncodingDebt.Count) file(s); first: $($excludedEncodingDebt[0])"
} else {
    Write-Host "[OK] Imported Plate and generated builder encoding checked: $($excludedEncodingFiles.Count) files"
}

$tablePath = Join-Path $root 'src\Database\Table_Names.php'
if (Test-Path -LiteralPath $tablePath) {
    $tableErrorCount = $errors.Count
    $tableContent = Get-Content -LiteralPath $tablePath -Raw -Encoding UTF8
    $suffixBlock = [regex]::Match(
        $tableContent,
        'public static function suffixes\(\)\s*\{(?<body>.*?)\n\t\}',
        [System.Text.RegularExpressions.RegexOptions]::Singleline
    )
    if (-not $suffixBlock.Success) {
        Add-ErrorMessage 'Table_Names::suffixes() could not be parsed.'
    } else {
        $physicalNames = @(
            [regex]::Matches($suffixBlock.Groups['body'].Value, "=>\s*'([^']+)'") |
                ForEach-Object { $_.Groups[1].Value }
        )
        if ($physicalNames.Count -ne 35) {
            Add-ErrorMessage "Expected 35 current physical add-on tables, found $($physicalNames.Count)."
        }
        foreach ($name in $physicalNames) {
            if ($name -notmatch '^oj_ai_[a-z0-9_]+$') {
                Add-ErrorMessage "Invalid physical add-on table name: $name"
            }
        }
        if (@($physicalNames | Sort-Object -Unique).Count -ne $physicalNames.Count) {
            Add-ErrorMessage 'Duplicate physical add-on table names found.'
        }
    }

    if ($tableContent.Contains('$wpdb->prefix') -or $tableContent.Contains('wp_oj_ai_')) {
        Add-ErrorMessage 'WordPress-prefixed add-on table logic found in Table_Names.php.'
    }
    if (-not [regex]::IsMatch(
        $tableContent,
        "public static function prefix\(\)\s*\{\s*return '';\s*\}",
        [System.Text.RegularExpressions.RegexOptions]::Singleline
    )) {
        Add-ErrorMessage "Table_Names::prefix() must return an empty string."
    }
    if ($errors.Count -eq $tableErrorCount) {
        Write-Host '[OK] Fixed unprefixed oj_ai_* table contract'
    }
}

$mainFile = Join-Path $root 'oj-ai-dashboard.php'
if (Test-Path -LiteralPath $mainFile) {
    $mainContent = Get-Content -LiteralPath $mainFile -Raw -Encoding UTF8
    if ($mainContent -notmatch 'Plugin Name:\s*OJ AI Dashboard') {
        Add-ErrorMessage 'Main plugin header is missing Plugin Name: OJ AI Dashboard.'
    }
    if ($mainContent -notmatch 'Text Domain:\s*oj-ai-dashboard') {
        Add-ErrorMessage 'Main plugin header is missing Text Domain: oj-ai-dashboard.'
    }
}

$php = Get-PhpExecutable
if (-not $php) {
    Add-WarningMessage 'PHP executable not found. Set OJAI_PHP or install PHP.'
} else {
    $phpFiles = @(
        Get-ChildItem -LiteralPath $root -Recurse -Filter '*.php' -File |
            Where-Object { $_.FullName -notmatch '\\(?:vendor|node_modules|build|\.cache|\.git|_notes|dist)\\' }
    )
    foreach ($file in $phpFiles) {
        $result = & $php -l $file.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            Add-ErrorMessage "PHP lint failed: $($file.FullName.Substring($root.Length + 1)) - $result"
        }
    }
    Write-Host "[OK] PHP syntax checked: $($phpFiles.Count) files"
}

$forbiddenPatternPath = Join-Path $root 'scripts\harness-forbidden-runtime-text.txt'
if (Test-Path -LiteralPath $forbiddenPatternPath) {
    $patterns = @(
        Get-Content -LiteralPath $forbiddenPatternPath -Encoding UTF8 |
            Where-Object { $_ -and -not $_.StartsWith('#') }
    )
    $runtimeTargets = @('oj-ai-dashboard.php', 'src', 'templates', 'resources', 'config', 'database')
    $runtimeFiles = New-Object System.Collections.Generic.List[System.IO.FileInfo]
    foreach ($relativePath in $runtimeTargets) {
        $target = Join-Path $root $relativePath
        if (-not (Test-Path -LiteralPath $target)) {
            continue
        }
        $item = Get-Item -LiteralPath $target
        if ($item.PSIsContainer) {
            Get-ChildItem -LiteralPath $target -Recurse -File |
                Where-Object {
                    $textExtensions -contains $_.Extension -and
                    $_.FullName -notmatch '\\(?:node_modules|vendor|build)\\'
                } |
                ForEach-Object { $runtimeFiles.Add($_) | Out-Null }
        } elseif ($textExtensions -contains $item.Extension) {
            $runtimeFiles.Add($item) | Out-Null
        }
    }

    foreach ($file in $runtimeFiles) {
        $content = Get-Content -LiteralPath $file.FullName -Raw -Encoding UTF8
        foreach ($pattern in $patterns) {
            if ($content.Contains($pattern)) {
                Add-ErrorMessage "Forbidden runtime explanatory text in $($file.FullName.Substring($root.Length + 1)): $pattern"
            }
        }
    }
    Write-Host "[OK] Forbidden runtime text checked: $($runtimeFiles.Count) files"
}

Write-Host ''
if ($warnings.Count -gt 0) {
    Write-Host 'Warnings:'
    foreach ($warning in $warnings) {
        Write-Host " - $warning"
    }
    Write-Host ''
}

if ($errors.Count -gt 0) {
    Write-Host 'Errors:'
    foreach ($errorMessage in $errors) {
        Write-Host " - $errorMessage"
    }
    exit 1
}

if ($Strict -and $warnings.Count -gt 0) {
    Write-Host 'Strict mode failed because warnings were found.'
    exit 1
}

Write-Host 'Design-phase repository harness passed.'
exit 0
