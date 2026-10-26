<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$php = [PHP_BINARY];
if (is_string(php_ini_loaded_file())) { array_push($php, '-c', php_ini_loaded_file()); }
$failed = []; $linted = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') { continue; }
    $contents = file_get_contents($file->getPathname());
    if (str_starts_with($contents, "\xEF\xBB\xBF") || preg_match('//u', $contents) !== 1) { $failed[] = $file->getPathname() . ': encoding'; }
    $process = proc_open([...$php, '-l', $file->getPathname()], [0=>STDIN, 1=>['pipe','w'], 2=>STDERR], $pipes, $root);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    if (proc_close($process) !== 0) { $failed[] = $file->getPathname(); fwrite(STDERR, $output); }
    ++$linted;
}
printf("Research PHP syntax/UTF-8: %d files, %d failures\n", $linted, count($failed));
if ($failed !== []) { exit(1); }
$tests = [
    ['Unit/research.php'], ['Unit/research-pilot.php'], ['Unit/research-analysis.php'],
    ['Unit/release-state.php'],
    ['Integration/research-feedback.php'], ['Integration/dependency.php'],
    ['Integration/dependency.php', '1.1.0'], ['Integration/dependency.php', '1.2.0'],
    ['Integration/dependency.php', '1.3.0'], ['Integration/dependency.php', '2.0.0'],
    ['Integration/dependency.php', 'missing-contract'],
    ['Integration/lifecycle.php'], ['Integration/lifecycle.php', 'platform-first'],
    ['E2E/research-ui.php'], ['E2E/research-actions.php'], ['E2E/research-pilot-ui.php'],
];
foreach ($tests as $test) {
    $file = array_shift($test);
    $process = proc_open([...$php, $root . '/tests/' . $file, ...$test], [0=>STDIN, 1=>STDOUT, 2=>STDERR], $pipes, $root);
    if (! is_resource($process) || proc_close($process) !== 0) { $failed[] = $file; }
}
printf("Research test scripts: %d passed, %d failed\n", count($tests)-count($failed), count($failed));
if ($failed !== []) { fwrite(STDERR, implode("\n", $failed) . "\n"); exit(1); }
