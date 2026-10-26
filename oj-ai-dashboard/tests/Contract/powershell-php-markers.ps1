$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '../../scripts/php-source-markers.ps1')
$php = if ($env:OJAI_PHP) { $env:OJAI_PHP } else { 'php' }
$source = @'
<?php
private const ROUTE    = '/me/results/example';
private const LIMIT    = 300;
$a = array(
    'state' => 'approved',
    'two  spaces' => true
);
$a == $b;
'@
$cases = @(
    @('private const LIMIT = 300;', $true),
    @('private const LIMIT = 301;', $false),
    @("ROUTE = '/me/results/", $true),
    @("ROUTE = '/admin/", $false),
    @("'state'=>'approved'", $true),
    @("'state'=>'released'", $false),
    @("'two spaces' => true", $false),
    @('$a === $b;', $false)
)
foreach($case in $cases) {
    if ((Test-OjaiPhpMarker -Source $source -Marker $case[0] -Php $php) -ne $case[1]) { throw "Marker boundary failed: $($case[0])" }
}
if (Test-OjaiPhpMarker -Source '<p>two  spaces</p>' -Marker '<p>two spaces</p>' -Php $php) { throw 'HTML text matching was weakened.' }
if (Test-OjaiPhpMarker -Source '<?php /* private const LIMIT   = 300; */' -Marker 'private const LIMIT = 300;' -Php $php) { throw 'Formatted comments must not satisfy a PHP fragment.' }
$savedEncoding = $OutputEncoding
try {
    $OutputEncoding = [Text.Encoding]::Unicode
    $largeSource = $source + "`n/*" + (([string][char]0xD55C) * 100000) + '*/'
    if (-not (Test-OjaiPhpMarker -Source $largeSource -Marker 'private const LIMIT = 300;' -Php $php)) { throw 'Large Unicode source transport was corrupted.' }
} finally {
    $OutputEncoding = $savedEncoding
}
Write-Output 'PowerShell PHP markers PASS: formatting and route prefixes, exact literals/operators, unchanged HTML and comment boundaries.'
Write-Output 'PowerShell PHP stdin PASS: large Unicode source is independent of native pipeline encoding.'
