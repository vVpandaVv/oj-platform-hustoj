<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$strict = in_array('--release', $argv, true);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$php = [PHP_BINARY];
$ini = php_ini_loaded_file();
if (is_string($ini)) {
    array_push($php, '-c', $ini);
}
// Child test processes must use the same explicitly enabled extensions as this runner.
foreach (['extension_dir'] as $setting) {
    array_push($php, '-d', $setting . '=' . ini_get($setting));
}
$missing = [];
foreach (array_keys($composer['require']) as $requirement) {
    if (! str_starts_with($requirement, 'ext-')) {
        continue;
    }
    $extension = substr($requirement, 4);
    if (! extension_loaded($extension)) {
        $missing[] = $extension;
    }
}
if ($strict && $missing !== []) {
    fwrite(STDERR, 'Release tests require PHP extensions: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

$probe = proc_open(
    [...$php, '-r', 'echo json_encode(get_loaded_extensions());'],
    [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR],
    $probePipes,
    $root
);
if (! is_resource($probe)) {
    throw new RuntimeException('Cannot inspect the PHP test environment.');
}
$childExtensions = json_decode(stream_get_contents($probePipes[1]), true, 512, JSON_THROW_ON_ERROR);
fclose($probePipes[1]);
if (proc_close($probe) !== 0) {
    throw new RuntimeException('Cannot inspect the PHP test environment.');
}
foreach (array_diff(get_loaded_extensions(), $childExtensions) as $extension) {
    array_push($php, '-d', 'extension=' . $extension);
}

function testCommands(array $scripts, string $name, array $parents = []): array
{
    if (in_array($name, $parents, true) || ! isset($scripts[$name])) {
        throw new RuntimeException('Invalid test script reference: ' . $name);
    }
    $commands = [];
    foreach ((array) $scripts[$name] as $command) {
        if (str_starts_with($command, '@php ')) {
            $file = substr($command, 5);
            if (preg_match('#^tests/[A-Za-z0-9_./-]+\.php$#D', $file) !== 1) {
                throw new RuntimeException('Unsupported test command: ' . $command);
            }
            $commands[] = $file;
        } elseif (str_starts_with($command, '@')) {
            array_push($commands, ...testCommands($scripts, substr($command, 1), [...$parents, $name]));
        } else {
            throw new RuntimeException('Unsupported test command: ' . $command);
        }
    }
    return array_values(array_unique($commands));
}

$failed = [];
if ($strict) {
    $lintCount = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $process = proc_open([...$php, '-l', $file->getPathname()], [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $root);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start PHP syntax check.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            fwrite(STDERR, $output);
            $failed[] = $file->getPathname();
        }
        ++$lintCount;
    }
    printf("PHP syntax: %d files, %d failures\n", $lintCount, count($failed));
    if ($failed !== []) {
        exit(1);
    }
}
$commands = testCommands($composer['scripts'], 'test:fast');
foreach ($commands as $file) {
    $process = proc_open([...$php, $file], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (! is_resource($process) || proc_close($process) !== 0) {
        $failed[] = $file;
    }
}
printf("Test scripts: %d passed, %d failed\n", count($commands) - count($failed), count($failed));
if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}
