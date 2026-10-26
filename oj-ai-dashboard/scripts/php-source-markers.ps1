function Test-OjaiPhpMarker {
    param([string]$Source, [string]$Marker, [string]$Php)
    if ($Source.Contains($Marker)) { return $true }
    if (-not $Source.TrimStart().StartsWith('<?php')) { return $false }
    $payload = @{ source = $Source; marker = $Marker } | ConvertTo-Json -Compress
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($payload))
    # Isolate native stdin from PowerShell's output encoding and console pipeline.
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo.FileName = $Php
    $process.StartInfo.Arguments = '"' + (Join-Path $PSScriptRoot 'php-source-markers.php') + '"'
    $process.StartInfo.UseShellExecute = $false
    $process.StartInfo.CreateNoWindow = $true
    $process.StartInfo.RedirectStandardInput = $true
    $process.StartInfo.RedirectStandardOutput = $true
    $process.StartInfo.RedirectStandardError = $true
    try {
        if (-not $process.Start()) { throw 'Unable to start PHP source marker inspection.' }
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        $process.StandardInput.WriteLine($encoded)
        $process.StandardInput.Close()
        if (-not $process.WaitForExit(15000)) {
            $process.Kill()
            throw 'PHP source marker inspection timed out.'
        }
        $value = $stdout.GetAwaiter().GetResult().Trim()
        $errorText = $stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) { throw "PHP source marker inspection failed: $errorText" }
    } finally {
        $process.Dispose()
    }
    if ($value -ne 'true' -and $value -ne 'false') { throw 'Invalid PHP source marker inspection result.' }
    return $value -eq 'true'
}
