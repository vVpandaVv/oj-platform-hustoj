<?php
declare(strict_types=1);
namespace LearningMaterials\Materials { function is_writable(string $path): bool { return \is_dir($path); } }
namespace {
foreach (array('Material', 'Google_Link', 'Preview_Link', 'Material_Storage', 'Preview_Diagnostics') as $class) require_once dirname(__DIR__) . '/src/Materials/' . $class . '.php';
function __(string $text, string $domain): string { return $text; }
function wp_salt(string $scheme): string { return 'test-key'; }
function admin_url(string $path): string { return 'https://school.test/wp-admin/' . $path; }
function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
function wp_parse_url(string $url, int $component): mixed { return parse_url($url, $component); }
class WP_Error {}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code(array $response): int { return $response['code']; }
function wp_remote_retrieve_header(array $response, string $key): string { return $response['headers'][$key] ?? ''; }
function wp_safe_remote_get(string $url, array $options): mixed {
    $GLOBALS['requests'][] = array($url, $options);
    if (($options['cookies'] ?? null) !== array() || $options['redirection'] !== 0 || ! in_array(parse_url($url, PHP_URL_HOST), array('school.test', 'docs.google.com', 'drive.google.com'), true)) {
        throw new RuntimeException('Unsafe diagnostic request');
    }
    return array_shift($GLOBALS['responses']) ?? new WP_Error();
}
use LearningMaterials\Materials\Material;
use LearningMaterials\Materials\Preview_Diagnostics;
$root = dirname(__DIR__, 2) . '/_notes/tmp/lmt-diagnostics-' . bin2hex(random_bytes(8));
mkdir($root . '/learning-materials-private', 0750, true);
define('WP_CONTENT_DIR', $root);
file_put_contents($root . '/learning-materials-private/test.txt', '0123456789');
$base = array('id' => 1, 'storage_filename' => 'test.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'file_size' => 10);
$fileResponse = array('code' => 206, 'headers' => array('content-type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
$html = array('code' => 200, 'headers' => array('content-type' => 'text/html; charset=utf-8'));
$cases = array(
    'native' => array($base, array(), 'ok', 0),
    'missing-file' => array(array_merge($base, array('storage_filename' => 'missing.txt')), array(), 'error', 0),
    'size-mismatch' => array(array_merge($base, array('file_size' => 11)), array(), 'error', 0),
    'office-ok' => array(array_merge($base, array('extension' => 'docx')), array($fileResponse, $html), 'ok', 2),
    'file-login-html' => array(array_merge($base, array('extension' => 'docx')), array($html), 'error', 1),
    'network-error' => array(array_merge($base, array('extension' => 'docx')), array(new WP_Error()), 'error', 1),
    'google-204' => array(array_merge($base, array('extension' => 'docx')), array($fileResponse, array('code' => 204, 'headers' => array('content-type' => 'application/binary'))), 'warning', 2),
    'google-frame-denied' => array(array_merge($base, array('extension' => 'docx')), array($fileResponse, array('code' => 200, 'headers' => array('content-type' => 'text/html', 'x-frame-options' => 'SAMEORIGIN'))), 'error', 2),
    'google-csp-denied' => array(array_merge($base, array('extension' => 'docx')), array($fileResponse, array('code' => 200, 'headers' => array('content-type' => 'text/html', 'content-security-policy' => "frame-ancestors 'none'"))), 'error', 2),
    'shared-google' => array(array('id' => 1, 'source_url' => 'https://docs.google.com/document/d/test_123/edit'), array($html), 'warning', 1),
    'invalid-link' => array(array('id' => 1, 'source_url' => 'https://evil.test/'), array(), 'error', 0),
);
try {
    foreach ($cases as $name => [$row, $responses, $status, $requestCount]) {
        $GLOBALS['responses'] = $responses;
        $GLOBALS['requests'] = array();
        $checks = Preview_Diagnostics::run(Material::from_row($row), array());
        if (end($checks)['status'] !== $status || count($GLOBALS['requests']) !== $requestCount) throw new RuntimeException('Diagnostic case: ' . $name);
        $encoded = json_encode($checks);
        if (str_contains($encoded, 'signature=') || str_contains($encoded, $root)) throw new RuntimeException('Diagnostic result leaked a token/path');
    }
    $checks = Preview_Diagnostics::run(Material::from_row($base), array(), time() - 1);
    if ($checks[1]['status'] !== 'error') throw new RuntimeException('Expired link was not diagnosed');
    echo "12 diagnostics cases passed: local files, expiry, network, HTML redirects, 204, frame policy and Google links.\n";
} finally {
    foreach (array('test.txt', 'index.php', '.htaccess', 'web.config') as $name) {
        $path = $root . '/learning-materials-private/' . $name;
        if (is_file($path)) unlink($path);
    }
    rmdir($root . '/learning-materials-private');
    rmdir($root);
}
}
