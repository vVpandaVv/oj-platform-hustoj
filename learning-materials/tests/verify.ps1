param([string]$Php = 'D:\php\php.exe', [string]$Node = 'node')
$ErrorActionPreference = 'Stop'
$testRoot = $PSScriptRoot
$pluginRoot = Split-Path $testRoot -Parent
$modules = & $Php -m
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect PHP modules' }
$extensionArgs = @()
if ($modules -notcontains 'fileinfo') { $extensionArgs += @('-d', 'extension=php_fileinfo.dll') }
if ($modules -notcontains 'sqlite3') { $extensionArgs += @('-d', 'extension=php_sqlite3.dll') }
foreach ($file in (Get-ChildItem -LiteralPath $pluginRoot -Filter '*.php' -Recurse -File)) {
    $result = & $Php -l $file.FullName
    if ($LASTEXITCODE -ne 0) { throw ($result -join "`n") }
}
Write-Output 'PHP syntax: OK'
foreach ($name in @('group-labels.php', 'preview-link.php', 'google-links.php', 'file-formats.php', 'catalog-settings.php', 'activity-provider.php', 'preview-controller.php', 'preview-diagnostics.php', 'schema-upgrade.php', 'stream-memory.php')) {
    & $Php @extensionArgs (Join-Path $testRoot $name)
    if ($LASTEXITCODE -ne 0) { throw "Failed: $name" }
}
foreach ($name in @('preview-ui.cjs', 'diagnostics-ui.cjs')) {
    & $Node (Join-Path $testRoot $name)
    if ($LASTEXITCODE -ne 0) { throw "Failed: $name" }
}
Write-Output 'Learning Materials verification: OK'
