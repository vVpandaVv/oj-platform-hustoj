<?php
/** Format an isolated copy, then accept only unchanged executable-token streams. */
declare(strict_types=1);

$root = dirname(__DIR__);
$snapshot_name = $argv[2] ?? 'quality-format';
if (!preg_match('/^quality-format(?:-[a-z0-9-]+)?$/D', $snapshot_name)) {
    throw new RuntimeException('Invalid formatting snapshot name.');
}
$snapshot = $root . '/.cache/' . $snapshot_name;
$manifest_path = $snapshot . '/manifest.json';
$mode = $argv[1] ?? '';
if (!in_array($mode, array('--prepare', '--apply'), true)) {
    throw new RuntimeException('Use --prepare or --apply.');
}

function ojai_format_tokens(string $source): array {
    $result = array();
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_LINE) {
                throw new RuntimeException('Line-sensitive source must be reviewed manually.');
            }
            if (in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $result[] = array($token[0], $token[1]);
        } else {
            $result[] = $token;
        }
    }
    return $result;
}

if ($mode === '--prepare') {
    if (file_exists($manifest_path)) {
        throw new RuntimeException('Existing formatting snapshot must not be overwritten.');
    }
    $files = array('oj-ai-dashboard.php');
    foreach (array('src', 'tests') as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !$file->isLink() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }
    $manifest = array();
    foreach ($files as $file) {
        $source = file_get_contents($root . '/' . $file);
        $target = $snapshot . '/work/' . $file;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        file_put_contents($target, $source);
        $original = $snapshot . '/original/' . $file;
        if (!is_dir(dirname($original))) {
            mkdir(dirname($original), 0700, true);
        }
        file_put_contents($original, $source);
        $manifest[$file] = hash('sha256', $source);
    }
    file_put_contents($manifest_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    foreach (array('phpcs.xml.dist', 'scripts/phpcs/bootstrap.php', 'scripts/phpcs/OJAI/Sniffs/Files/AutoloadPathSniff.php', 'scripts/phpcs/OJAI/Sniffs/Commenting/ThrowsContractSniff.php', 'scripts/phpcs/OJAI/Sniffs/ControlStructures/LiteralComparisonSniff.php') as $config) {
        $target = $snapshot . '/work/' . $config;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        copy($root . '/' . $config, $target);
    }
    echo 'Prepared isolated formatting copy: ' . count($manifest) . " PHP files.\n";
    exit;
}

$manifest = json_decode(file_get_contents($manifest_path), true, 512, JSON_THROW_ON_ERROR);
$accepted = array();
$skipped = array();
$source_root = realpath($root) . DIRECTORY_SEPARATOR;
$snapshot_root = realpath($snapshot . '/work') . DIRECTORY_SEPARATOR;
foreach ($manifest as $file => $hash) {
    if (str_contains($file, '..') || !preg_match('#^(?:src/|tests/|oj-ai-dashboard\.php$)#D', $file) || !str_ends_with($file, '.php')) {
        throw new RuntimeException('Snapshot path outside the allowed PHP source scope.');
    }
    foreach (array($source_root => $root . '/' . $file, $snapshot_root => $snapshot . '/work/' . $file) as $allowed_root => $path) {
        $resolved = realpath($path);
        if ($resolved === false || !str_starts_with($resolved, $allowed_root) || is_link($path)) {
            throw new RuntimeException('Resolved formatting path outside its verified root.');
        }
    }
    $current = file_get_contents($root . '/' . $file);
    $formatted = file_get_contents($snapshot . '/work/' . $file);
    if ($current === $formatted) {
        continue;
    }
    if (!hash_equals($hash, hash('sha256', $current))) {
        $skipped[$file] = 'Original changed since snapshot; current edits preserved.';
        continue;
    }
    try {
        if (ojai_format_tokens($current) !== ojai_format_tokens($formatted)) {
            $skipped[$file] = 'Executable tokens differ; requires manual review.';
            continue;
        }
    } catch (Throwable $error) {
        $skipped[$file] = $error->getMessage();
        continue;
    }
    $accepted[$file] = $formatted;
}
foreach ($accepted as $file => $formatted) {
    if (!hash_equals($manifest[$file], hash_file('sha256', $root . '/' . $file))) {
        throw new RuntimeException('Concurrent edit detected before applying formatting.');
    }
    file_put_contents($root . '/' . $file, $formatted);
}
echo json_encode(array('applied' => array_keys($accepted), 'skipped' => $skipped, 'executable_tokens_preserved' => true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
