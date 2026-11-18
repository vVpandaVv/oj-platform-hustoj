param([string]$RuntimeRoot = '', [string]$OutputRoot = '', [string]$Archive = '')
$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
if (-not $RuntimeRoot) { $RuntimeRoot = Join-Path $workspace '_notes\tmp\oj-ml-1.0-integration' }
if (-not $OutputRoot) { $OutputRoot = Join-Path $workspace '_notes\verification\oj-logic-lab-1.0.2' }
$stamp = [guid]::NewGuid().ToString('N')
$runRoot = Join-Path $workspace ('_notes\tmp\logic-rc-wordpress-' + $stamp)
if (Test-Path -LiteralPath $runRoot) { throw 'Refusing existing fixture directory' }
$dbBin = Join-Path $RuntimeRoot 'db\mariadb-11.4.10-winx64\bin'
$php = Join-Path $RuntimeRoot 'php83\php.exe'
$logs = Join-Path $runRoot 'logs'
New-Item -ItemType Directory -Path $logs, $OutputRoot -Force | Out-Null
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
$listener.Start(); $port = $listener.LocalEndpoint.Port; $listener.Stop()
$password = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
$data = Join-Path $runRoot 'database'
$dbProcess = $null
$result = [ordered]@{ isolated = $true; productionDatabasesUsed = $false; fixtureRoot = $runRoot; passed = $false; cases = @() }
try {
    $init = Start-Process -FilePath (Join-Path $dbBin 'mariadb-install-db.exe') -ArgumentList @("--datadir=$data", "--password=$password", "--port=$port") -WindowStyle Hidden -PassThru -Wait -RedirectStandardOutput (Join-Path $logs 'init.out') -RedirectStandardError (Join-Path $logs 'init.err')
    if ($init.ExitCode -ne 0) { throw 'Fresh MariaDB initialization failed' }
    $dbProcess = Start-Process -FilePath (Join-Path $dbBin 'mariadbd.exe') -ArgumentList @('--no-defaults', "--basedir=$(Split-Path -Parent $dbBin)", "--datadir=$data", "--port=$port", '--bind-address=127.0.0.1', '--console') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logs 'db.out') -RedirectStandardError (Join-Path $logs 'db.err')
    $env:MYSQL_PWD = $password
    $ready = $false
    for ($i = 0; $i -lt 50; $i++) { $ping = Start-Process -FilePath (Join-Path $dbBin 'mariadb-admin.exe') -ArgumentList @('--no-defaults','--skip-ssl','--protocol=tcp','--host=127.0.0.1',"--port=$port",'--user=root','--connect-timeout=1','ping') -WindowStyle Hidden -Wait -PassThru -RedirectStandardOutput (Join-Path $logs 'ping.out') -RedirectStandardError (Join-Path $logs 'ping.err'); if ($ping.ExitCode -eq 0) { $ready = $true; break }; Start-Sleep -Milliseconds 200 }
    if (-not $ready) { throw 'Isolated database did not become ready' }
    foreach ($version in @('66', '71')) {
        $source = Join-Path $RuntimeRoot $(if ($version -eq '66') { 'wp\wordpress' } else { 'wp71\wordpress' })
        if (Test-Path -LiteralPath (Join-Path $source 'wp-config.php')) { throw 'Refusing configured WordPress source' }
        $target = Join-Path $runRoot ('wp' + $version)
        & robocopy.exe $source $target /E /COPY:DAT /R:0 /W:0 /MT:8 /NFL /NDL /NJH /NJS *> (Join-Path $logs "copy-$version.log")
        if ($LASTEXITCODE -gt 7) { throw 'WordPress copy failed' }
        & robocopy.exe (Join-Path $workspace 'oj-integration-rebuild\oj-platform') (Join-Path $target 'wp-content\plugins\oj-platform') /E /COPY:DAT /R:0 /W:0 /MT:8 /NFL /NDL /NJH /NJS *> (Join-Path $logs "platform-$version.log")
        if ($LASTEXITCODE -gt 7) { throw 'Platform copy failed' }
        $env:LOGIC_TEST_WP_PATH = $target
        $env:LOGIC_TEST_DB_HOST = "127.0.0.1:$port"
        $env:LOGIC_TEST_DB_PASSWORD = $password
        $env:LOGIC_TEST_DB_NAME = "release_logic_${version}_${stamp}_test"
        $env:LOGIC_TEST_SITE_URL = 'http://127.0.0.1:8793'
        $env:LOGIC_TEST_OLD_ZIP = Join-Path $workspace '_releases\oj-logic-lab\0.5.0\oj-logic-lab-0.5.0.zip'
        $env:LOGIC_TEST_BASE_ZIP = Join-Path $workspace '_releases\oj-logic-lab\1.0.1\oj-logic-lab-1.0.1.zip'
        $env:LOGIC_TEST_NEW_DIR = Join-Path $workspace 'oj-logic-lab'
        $env:LOGIC_TEST_NEW_ZIP = if ($Archive) { [IO.Path]::GetFullPath($Archive) } else { '' }
        $stdout = Join-Path $logs "wp-$version.out"
        $stderr = Join-Path $logs "wp-$version.err"
        $test = Start-Process -FilePath $php -ArgumentList @('-d', "extension_dir=$(Join-Path $RuntimeRoot 'php83\ext')", '-d', 'extension=mysqli', '-d', 'extension=zip', '-d', 'extension=mbstring', '-d', 'log_errors=1', '-d', "error_log=$(Join-Path $logs "wp-$version-php.log")", (Join-Path $workspace 'oj-logic-lab\tests\wordpress.php')) -WindowStyle Hidden -PassThru -Wait -RedirectStandardOutput $stdout -RedirectStandardError $stderr
        $phpLog = Join-Path $logs "wp-$version-php.log"
        if (Test-Path -LiteralPath $phpLog) { Copy-Item -LiteralPath $phpLog -Destination (Join-Path $OutputRoot "wordpress-$version-php.log") } else { [IO.File]::WriteAllText((Join-Path $OutputRoot "wordpress-$version-php.log"), '', [Text.UTF8Encoding]::new($false)) }
        Copy-Item -LiteralPath $stdout -Destination (Join-Path $OutputRoot "wordpress-$version.log")
        Copy-Item -LiteralPath $stderr -Destination (Join-Path $OutputRoot "wordpress-$version-errors.log")
        $text = [IO.File]::ReadAllText($stdout)
        $result.cases += [ordered]@{ version = $version; exitCode = $test.ExitCode; passCount = [regex]::Matches($text, '(?m)^PASS:').Count }
        if ($test.ExitCode -ne 0) { throw "WordPress $version gate failed; inspect retained logs" }
    }
    $result.passed = $true
} catch { $result.failure = $_.Exception.Message }
finally {
    if ($dbProcess -and -not $dbProcess.HasExited) { & (Join-Path $dbBin 'mariadb-admin.exe') --no-defaults --skip-ssl --protocol=tcp --host=127.0.0.1 "--port=$port" --user=root shutdown *> $null; if (-not $dbProcess.WaitForExit(10000)) { Stop-Process -Id $dbProcess.Id } }
    foreach ($name in @('MYSQL_PWD','LOGIC_TEST_WP_PATH','LOGIC_TEST_DB_HOST','LOGIC_TEST_DB_PASSWORD','LOGIC_TEST_DB_NAME','LOGIC_TEST_SITE_URL','LOGIC_TEST_OLD_ZIP','LOGIC_TEST_BASE_ZIP','LOGIC_TEST_NEW_DIR','LOGIC_TEST_NEW_ZIP')) { [Environment]::SetEnvironmentVariable($name, $null, 'Process') }
    [IO.File]::WriteAllText((Join-Path $OutputRoot 'wordpress.json'), ($result | ConvertTo-Json -Depth 6), [Text.UTF8Encoding]::new($false))
    $result | ConvertTo-Json -Depth 6
}
if (-not $result.passed) { exit 1 }
