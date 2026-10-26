$ErrorActionPreference = 'Stop'
$workspace = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$tempRoot = [IO.Path]::GetFullPath((Join-Path $workspace '_notes/tmp'))
$fixture = Join-Path $tempRoot ('addon-notices-' + [guid]::NewGuid().ToString('N'))
$utf8 = New-Object System.Text.UTF8Encoding($false)
function Write-Fixture([string]$relative, [string]$content = 'test fixture') {
    $path = Join-Path $fixture $relative
    [IO.Directory]::CreateDirectory((Split-Path $path -Parent)) | Out-Null
    [IO.File]::WriteAllText($path, $content, $utf8)
}
try {
    foreach ($notice in @(
        'readcontents-library/assets/legal/Assistant-OFL.txt',
        'readcontents-library/assets/legal/editor/katex/LICENSE',
        'oj-ai-dashboard/LICENSE.txt',
        'edu-games/assets/vendor/chartjs/color-LICENSE.md',
        'oj-code-lab/docs/Lucide-Feather-LICENSE.txt',
        'oj-code-arena/LICENSE.txt',
        '_shared/oj-realtime-hub/LICENSE.txt',
        'teaching-tools/realtime-server/LICENSE.txt',
        'teaching-tools/resources/vendor/pdfjs/LICENSE',
        'teaching-tools/resources/vendor/pdfjs/README.md'
    )) { Write-Fixture $notice }
    foreach ($directory in @('readcontents-library/assets/legal/editor', 'oj-ai-dashboard/resources/assets/legal/editor')) {
        Write-Fixture "$directory/INDEX.md" '- fixture-LICENSE.txt'
        Write-Fixture "$directory/fixture-LICENSE.txt"
    }
    foreach ($id in @('teaching-tools', 'oj-code-arena')) {
        Write-Fixture "$id/realtime-addon.json" '{"include":["LICENSE.txt"]}'
    }
    Write-Fixture '_release-kit/placeholder.txt'
    $checker = Join-Path $fixture '_release-kit/check-addon-notices.ps1'
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot '../check-addon-notices.ps1') -Destination $checker
    & $checker | Out-Null
    Write-Fixture 'oj-ai-dashboard/resources/assets/builder/dash.all.min.js'
    $rejected = $false
    try { & $checker | Out-Null } catch {
        if ($_.Exception.Message -notlike 'Missing license notice:*dash.all.min.js.LICENSE.txt') { throw }
        $rejected = $true
    }
    if (!$rejected) { throw 'A distributed legacy bundle must require its license notice.' }
    Write-Fixture 'oj-ai-dashboard/resources/assets/builder/dash.all.min.js.LICENSE.txt'
    & $checker | Out-Null
    Write-Output 'PASS: removed bundle, missing notice rejection, and retained bundle with notice.'
} finally {
    $resolved = [IO.Path]::GetFullPath($fixture)
    if (!$resolved.StartsWith($tempRoot + [IO.Path]::DirectorySeparatorChar)) { throw 'Unexpected fixture cleanup path.' }
    if (Test-Path -LiteralPath $resolved) { Remove-Item -LiteralPath $resolved -Recurse -Force }
}
