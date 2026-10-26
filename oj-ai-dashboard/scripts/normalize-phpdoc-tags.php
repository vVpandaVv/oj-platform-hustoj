<?php
/**
 * Split combined PHPDoc tags without changing executable tokens.
 */

declare(strict_types=1);

function ojai_normalize_phpdoc_tags(string $source): string {
    $result = '';
    $offset = 0;
    $newline = str_contains($source, "\r\n") ? "\r\n" : "\n";
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (is_array($token) && $token[0] === T_DOC_COMMENT) {
            $tags = '@(?:param(?:-out)?|return|throws|var|phpstan-[a-z-]+|template|implements|extends|see|since|deprecated)\b';
            if (preg_match('/' . $tags . '[^\r\n]+[ \t]+' . $tags . '/', $text) === 1) {
                $line_start = strrpos(substr($source, 0, $offset), "\n");
                $prefix = substr($source, $line_start === false ? 0 : $line_start + 1, $offset - ($line_start === false ? 0 : $line_start + 1));
                $indent = preg_match('/^[\t ]*$/D', $prefix) === 1 ? $prefix : '';
                if (!str_contains($text, "\n")) {
                    $body = trim(substr($text, 3, -2));
                    $body = preg_replace('/[ \t]+(?=' . $tags . ')/', $newline . $indent . ' * ', $body);
                    $text = '/**' . $newline . $indent . ' * ' . $body . $newline . $indent . ' */';
                } else {
                    $text = preg_replace('/(' . $tags . '[^\r\n]*?)[ \t]+(?=' . $tags . ')/', '$1' . $newline . $indent . ' * ', $text);
                }
            }
        }
        $result .= $text;
        $offset += strlen(is_array($token) ? $token[1] : $token);
    }
    return $result;
}

function ojai_executable_tokens(string $source): array {
    return array_values(array_filter(token_get_all($source, TOKEN_PARSE), static fn ($token): bool => !is_array($token) || !in_array($token[0], array(T_COMMENT, T_DOC_COMMENT, T_WHITESPACE), true)));
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}
if (!in_array($argv[1] ?? '', array('--check', '--write'), true)) {
    fwrite(STDERR, "Usage: php scripts/normalize-phpdoc-tags.php --check|--write\n");
    exit(2);
}
$root = dirname(__DIR__);
$changed = array();
foreach (array('src', 'tests') as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'php') {
            continue;
        }
        $before = file_get_contents($file->getPathname());
        $after = ojai_normalize_phpdoc_tags($before);
        if ($before === $after) {
            continue;
        }
        // Token line numbers change when comments expand; token identities and contents must not.
        $identity = static fn ($token) => is_array($token) ? array($token[0], $token[1]) : $token;
        if (array_map($identity, ojai_executable_tokens($before)) !== array_map($identity, ojai_executable_tokens($after))) {
            throw new RuntimeException('Executable tokens changed: ' . $file->getPathname());
        }
        if ($argv[1] === '--write') {
            if (file_get_contents($file->getPathname()) !== $before) {
                throw new RuntimeException('Source changed during normalization: ' . $file->getPathname());
            }
            file_put_contents($file->getPathname(), $after);
        }
        $changed[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
echo json_encode(array('mode' => $argv[1], 'files' => $changed, 'count' => count($changed), 'executable_tokens_preserved' => true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
