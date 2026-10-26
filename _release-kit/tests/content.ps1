$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '../lib/Common.ps1')
. (Join-Path $PSScriptRoot '../lib/Content.ps1')
$actualRoot = $script:PluginsRoot
$fixture = Join-Path $actualRoot ("_notes/tmp/content-test-" + [guid]::NewGuid().ToString('N'))
Assert-ContentDirectory $fixture (Join-Path $actualRoot '_notes/tmp')
$script:PluginsRoot = $fixture
$registry = [pscustomobject]@{ latestRoot = (Join-Path $fixture '_releases/latest') }
$info = [pscustomobject]@{ Id = 'test'; Plugin = [pscustomobject]@{ contentCatalog = 'catalog.json' } }
$group = [pscustomobject]@{
    id = 'sample'; title = 'Sample'; status = 'draft'; note = 'Test'
    sourceRoot = 'source'; editingRoot = 'source'; files = @('one.txt')
}
$catalog = [pscustomobject]@{ schemaVersion = 1; collections = @($group) }
$script:checks = 0
function Check([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "FAIL: $Message" }
    $script:checks++
}
function Reject([scriptblock]$Action, [string]$Message) {
    $failed = $false
    try { & $Action | Out-Null } catch { $failed = $true }
    Check $failed $Message
}
function Save-Catalog { Write-Utf8 (Join-Path $fixture 'catalog.json') ($catalog | ConvertTo-Json -Depth 8) }
try {
    Write-Utf8 (Join-Path $fixture 'source/one.txt') ("UTF-8 " + [char]0xAC00 + "`n")
    Save-Catalog
    $plan = Get-ContentPlan $registry @($info)
    Write-ContentSnapshot $registry $plan
    Check (@(Get-ContentChecks $registry $plan).Count -eq 0) 'clean snapshot'
    $target = Join-Path $registry.latestRoot 'content/sample/one.txt'
    Check ((Get-Sha256 $target) -eq $plan.Files[0].Sha256) 'byte equality'
    $set = [pscustomobject]@{ plugins = @([pscustomobject]@{ id = 'test'; contentFiles = @() }) }
    Set-ContentEntries $set $plan
    Check ($set.plugins[0].contentFiles[0] -eq 'sample/one.txt') 'release-set path'
    Check ($set.plugins[0].contentSha256[0] -eq $plan.Files[0].Sha256) 'release-set hash'
    Write-ContentSnapshot $registry $plan
    Check (@(Get-ContentChecks $registry $plan).Count -eq 0) 'repeat publication'
    $group.files = @('missing.txt'); Save-Catalog
    Reject { Get-ContentPlan $registry @($info) } 'missing source'
    Check ((Get-Sha256 $target) -eq $plan.Files[0].Sha256) 'failed preflight keeps latest'
    $group.files = @('one.txt', 'one.txt'); Save-Catalog
    Reject { Get-ContentPlan $registry @($info) } 'duplicate target'
    $group.files = @([pscustomobject]@{ source = 'one.txt'; target = '../outside.txt' }); Save-Catalog
    Reject { Get-ContentPlan $registry @($info) } 'target traversal'
    $group.files = @('../source/one.txt'); Save-Catalog
    Reject { Get-ContentPlan $registry @($info) } 'source traversal'
    $group.sourceRoot = '_releases/latest/content/sample'; $group.files = @('one.txt'); Save-Catalog
    Reject { Get-ContentPlan $registry @($info) } 'generated output as source'
    $group.sourceRoot = 'source'; Save-Catalog
    Write-Utf8 (Join-Path $fixture 'source/one.txt') 'changed'
    Reject { Write-ContentSnapshot $registry $plan } 'source changed after preflight'
    Check ((Get-Sha256 $target) -eq $plan.Files[0].Sha256) 'copy failure keeps latest'
    $plan = Get-ContentPlan $registry @($info)
    Check (@(Get-ContentChecks $registry $plan).Count -gt 0) 'stale latest detection'
    Write-ContentSnapshot $registry $plan
    Write-Utf8 $target 'corrupted'
    Check (@(Get-ContentChecks $registry $plan).Count -gt 0) 'corruption detection'
    Write-ContentSnapshot $registry $plan
    Write-Utf8 (Join-Path $registry.latestRoot 'content/extra.txt') 'unexpected'
    Check (@(Get-ContentChecks $registry $plan).Count -gt 0) 'unregistered output'
    Write-ContentSnapshot $registry $plan
    Check (@(Get-ContentChecks $registry $plan).Count -eq 0) 'refresh removes stale outputs'
    $legacy = [pscustomobject]@{ Id = 'old'; Plugin = [pscustomobject]@{ contentFiles = @('source/one.txt') } }
    $legacyPlan = Get-ContentPlan $registry @($legacy)
    Check ($legacyPlan.Files[0].Target -eq 'one.txt') 'legacy contentFiles'
    $group.files = @(); Save-Catalog
    $emptyPlan = Get-ContentPlan $registry @($info)
    Write-ContentSnapshot $registry $emptyPlan
    Check (@(Get-ContentChecks $registry $emptyPlan).Count -eq 0) 'planning-only catalog'
    Check ($emptyPlan.Collections.Count -eq 1) 'planning status retained'
    Reject { Assert-ContentDirectory $fixture $fixture } 'root deletion guard'
    Write-Host "[content-test] $script:checks checks passed"
} finally {
    $script:PluginsRoot = $actualRoot
    Assert-ContentDirectory $fixture (Join-Path $actualRoot '_notes/tmp')
    if (Test-Path -LiteralPath $fixture) { Remove-Item -LiteralPath $fixture -Recurse -Force }
}
