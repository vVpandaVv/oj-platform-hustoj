<?php
/** Restore only this session's proven formatting changes, retaining later edits. */
declare(strict_types=1);

require_once __DIR__ . '/normalize-phpdoc-tags.php';

$root = dirname(__DIR__);
$snapshot = $root . '/.cache/quality-format';
$manifest = json_decode(file_get_contents($snapshot . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$applied = json_decode(file_get_contents($root . '/_notes/production-quality-format-applied.json'), true, 512, JSON_THROW_ON_ERROR)['applied'];
$archives = array();
foreach (array(
    $root . '/dist/validation/oj-ai-dashboard-0.1.110-rc.22.zip' => 'oj-ai-dashboard/',
    $snapshot . '/head.zip' => '',
) as $path => $prefix) {
    $archive = new ZipArchive();
    if ($archive->open($path) !== true) {
        throw new RuntimeException('Cannot open a formatting recovery source.');
    }
    $archives[] = array($archive, $prefix);
}

function ojai_format_parts(string $source): array {
    $parts = array();
    $space = '';
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (is_array($token) && $token[0] === T_WHITESPACE) {
            $space .= $token[1];
            continue;
        }
        $parts[] = array('token' => is_array($token) ? array($token[0], $token[1]) : $token, 'space' => $space);
        $space = '';
    }
    return array($parts, $space);
}

function ojai_format_anchor(array $tokens, int $index): string {
    return hash('sha256', serialize(array_slice($tokens, max(0, $index - 6), 13)));
}

$report = array('restored' => array(), 'partial' => array(), 'skipped' => array());
foreach ($applied as $file) {
    if (str_contains($file, '..') || !preg_match('#^(?:src/|tests/|oj-ai-dashboard\.php$)#D', $file)) {
        throw new RuntimeException('Recovery path outside the formatting scope.');
    }
    $path = $root . '/' . $file;
    $resolved = realpath($path);
    if ($resolved === false || !str_starts_with($resolved, realpath($root) . DIRECTORY_SEPARATOR) || is_link($path)) {
        throw new RuntimeException('Recovery target escaped its verified root.');
    }
    $original = null;
    foreach ($archives as [$archive, $prefix]) {
        $entry = $archive->getFromName($prefix . $file);
        if ($entry === false) {
            continue;
        }
        $lf = str_replace("\r\n", "\n", $entry);
        foreach (array($entry, $lf, str_replace("\n", "\r\n", $lf)) as $candidate) {
            foreach (array($candidate, ojai_normalize_phpdoc_tags($candidate)) as $normalized) {
                if (hash_equals($manifest[$file], hash('sha256', $normalized))) {
                    $original = $normalized;
                    break 3;
                }
            }
        }
    }
    if ($original === null) {
        $report['skipped'][$file] = 'No independently verified pre-format content; retained current edits.';
        continue;
    }
    $current = file_get_contents($path);
    $formatted = file_get_contents($snapshot . '/work/' . $file);
    if ($current === $formatted) {
        $restored = $original;
        $mode = 'restored';
    } else {
        [$before, $before_tail] = ojai_format_parts($original);
        [$after] = ojai_format_parts($formatted);
        [$now, $now_tail] = ojai_format_parts($current);
        $before_tokens = array_column($before, 'token');
        $after_tokens = array_column($after, 'token');
        if ($before_tokens !== $after_tokens) {
            $report['skipped'][$file] = 'Formatting changed comment tokens; manual review retained.';
            continue;
        }
        $anchors = array();
        foreach ($after_tokens as $index => $token) {
            $key = ojai_format_anchor($after_tokens, $index);
            $anchors[$key] = array_key_exists($key, $anchors) ? null : $index;
        }
        $now_tokens = array_column($now, 'token');
        foreach ($now as $index => &$part) {
            $key = ojai_format_anchor($now_tokens, $index);
            $previous = $anchors[$key] ?? null;
            if ($previous !== null && $part['token'] === $after[$previous]['token'] && $part['space'] === $after[$previous]['space']) {
                $part['space'] = $before[$previous]['space'];
            }
        }
        unset($part);
        $restored = '';
        foreach ($now as $part) {
            $restored .= $part['space'] . (is_array($part['token']) ? $part['token'][1] : $part['token']);
        }
        $restored .= $now_tail;
        $mode = 'partial';
    }
    $identity = static fn ($token) => is_array($token) ? array($token[0], $token[1]) : $token;
    if (array_map($identity, ojai_executable_tokens($current)) !== array_map($identity, ojai_executable_tokens($restored))) {
        throw new RuntimeException('Restoration would alter executable tokens: ' . $file);
    }
    if ($current === $restored) {
        continue;
    }
    $backup = $snapshot . '/before-format-restore/' . $file;
    if (!is_dir(dirname($backup))) {
        mkdir(dirname($backup), 0700, true);
    }
    if (file_exists($backup) || file_get_contents($path) !== $current) {
        throw new RuntimeException('Existing backup or concurrent edit; restoration refused.');
    }
    file_put_contents($backup, $current);
    file_put_contents($path, $restored);
    $report[$mode][] = $file;
}
foreach ($archives as [$archive]) {
    $archive->close();
}
$report['executable_tokens_preserved'] = true;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
