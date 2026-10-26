<?php
declare(strict_types=1);

final class WP_Error {
    public function __construct(private string $code, private string $message, private mixed $data = null) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): mixed { return $this->data; }
}
require dirname(__DIR__) . '/src/Support/Dataset_Archive.php';
use OjCodeLab\Support\Dataset_Archive;

$checks = 0;
function check(bool $condition, string $name): void {
    if (!$condition) { throw new RuntimeException($name); }
    $GLOBALS['checks']++;
    echo 'PASS ' . $name . PHP_EOL;
}
function denied(mixed $result, string $reason): bool {
    return $result instanceof WP_Error && $result->get_error_code() === 'oj_code_lab_dataset_archive_' . $reason;
}
/** Produce real classic ZIP fixtures, including raw headers that ZipArchive would normalize away. */
function archive_bytes(array $files, array $options = array()): string {
    $locals = ''; $central = '';
    foreach ($files as $file) {
        $name = $file['name']; $content = $file['content'] ?? "x,y\n1,2\n";
        $method = $file['method'] ?? 8;
        $data = $file['data'] ?? (0 === $method ? $content : gzdeflate($content));
        $size = $file['size'] ?? strlen($content); $compressed = strlen($data);
        $crc = $file['crc'] ?? crc32($content);
        $flags = $file['flags'] ?? 0x0800;
        $needed = $file['needed'] ?? 20;
        $localName = $file['localName'] ?? $name;
        $localOffset = $file['offset'] ?? strlen($locals);
        $descriptor = ($flags & 8) !== 0;
        $locals .= "PK\x03\x04" . pack('vvvvvVVVvv', $needed, $flags, $method, 0, 0,
            $descriptor ? 0 : $crc, $descriptor ? 0 : $compressed, $descriptor ? 0 : $size, strlen($localName), 0) . $localName . $data;
        if ($descriptor) { $locals .= ($file['descriptorSignature'] ?? true ? "PK\x07\x08" : '') . pack('VVV', $crc, $compressed, $size); }
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 0x0314, $needed, $flags, $method, 0, 0,
            $crc, $compressed, $size, strlen($name), 0, 0, 0, 0, $file['external'] ?? 0, $localOffset) . $name;
    }
    $count = count($files); $comment = $options['comment'] ?? '';
    return $locals . $central . "PK\x05\x06" . pack('vvvvVVv', $options['disk'] ?? 0, 0, $count, $count, strlen($central), strlen($locals), strlen($comment)) . $comment;
}
function unpack_fixture(array $files, string $requested = 'archive.zip', int $limit = 1000000): array|WP_Error {
    return Dataset_Archive::unpack(archive_bytes($files), $requested, $limit);
}

if (!class_exists('ZipArchive')) {
    $result = Dataset_Archive::unpack(archive_bytes(array(array('name' => 'a.csv'))), 'a.csv', 1000000);
    check(denied($result, 'unavailable') && $result->get_error_data()['status'] === 503, 'missing native ZIP extension returns a graceful actionable error');
    echo 'Dataset archive unavailable-extension checks: ' . $checks . PHP_EOL;
    exit(0);
}

$tempsBefore = glob(sys_get_temp_dir() . '/ocl-zip-*') ?: array();
$csv = "이름,값\n판다,3\n";
$result = unpack_fixture(array(array('name' => 'nested/한글.csv', 'content' => $csv)), 'nested/한글.csv');
check(is_array($result) && $result === array('content' => $csv, 'fileName' => '한글.csv'), 'nested UTF-8 CSV selected exactly and basename returned without changing content');
$files = array(array('name' => 'first.csv'), array('name' => 'folder/second.jsonl', 'content' => "{\"이름\":\"판다\"}\n"));
$result = unpack_fixture($files, 'folder/second.jsonl');
check(is_array($result) && $result['fileName'] === 'second.jsonl' && $result['content'] === $files[1]['content'], 'exact JSONL path wins over multiple supported members');
check(denied(unpack_fixture($files), 'ambiguous'), 'multiple supported files without exact requested path need explicit selection');
check(denied(unpack_fixture($files, 'second.jsonl'), 'ambiguous'), 'basename guesses never silently select a nested member from multiple candidates');
$result = unpack_fixture(array(array('name' => 'data.ndjson', 'content' => "{\"v\":1}\n"), array('name' => 'README.txt', 'content' => 'description')));
check(is_array($result) && $result['fileName'] === 'data.ndjson', 'single NDJSON falls back despite irrelevant readme');
$result = unpack_fixture(array(array('name' => 'DATA.CSV')));
check(is_array($result), 'supported extension matching is case insensitive');
check(denied(unpack_fixture(array(array('name' => 'README.txt'))), 'format'), 'archive without CSV or JSONL is rejected');
// Synthetic OOXML-shaped fixtures only; never redistribute the misnamed provider document.
$docx = array(
    array('name' => '[Content_Types].xml', 'content' => '<Types/>'),
    array('name' => 'word/document.xml', 'content' => '<document>PRIVATE_SYNTHETIC_DOCX_TEXT</document>'),
);
$result = unpack_fixture($docx, 'message.jsonl');
check(denied($result, 'office') && $result->get_error_data()['status'] === 415
    && str_contains($result->get_error_message(), 'DOCX') && str_contains($result->get_error_message(), 'UTF-8')
    && !str_contains($result->get_error_message(), 'PRIVATE_SYNTHETIC_DOCX_TEXT'), 'DOCX renamed to JSONL has actionable format guidance without document content');
foreach (array(0, 1) as $marker) {
    check(denied(unpack_fixture(array($docx[$marker]), 'message.jsonl'), 'format'), 'one OOXML marker alone retains the generic unsupported-archive error ' . $marker);
}
foreach (array('data.csv' => $csv, 'records.jsonl' => "{\"value\":1}\n") as $dataName => $dataContent) {
    $withData = array_merge($docx, array(array('name' => $dataName, 'content' => $dataContent)));
    $result = unpack_fixture($withData);
    check(is_array($result) && $result['fileName'] === $dataName && $result['content'] === $dataContent, 'normal data archive containing OOXML markers still imports ' . $dataName);
}
$unsafeDocx = array_merge($docx, array(array('name' => '../hidden.xml', 'content' => '<hidden/>')));
check(denied(unpack_fixture($unsafeDocx, 'message.jsonl'), 'path'), 'unsafe OOXML archive paths take precedence over document-format guidance');
$encryptedDocx = $docx; $encryptedDocx[1]['flags'] = 0x0801;
check(denied(unpack_fixture($encryptedDocx, 'message.jsonl'), 'encrypted'), 'encrypted OOXML members take precedence over document-format guidance');
$highRatioDocx = $docx; $highRatioDocx[1]['content'] = str_repeat('x', 1000000);
check(denied(unpack_fixture($highRatioDocx, 'message.jsonl'), 'limit'), 'OOXML compression ratio limits take precedence over document-format guidance');
check(denied(unpack_fixture(array(array('name' => 'README.txt'), array('name' => 'data.csv')), 'README.txt'), 'format'), 'an exact unsupported request does not fall back to a different file');
$result = unpack_fixture(array(array('name' => 'empty.csv', 'content' => '', 'method' => 0)));
check(is_array($result) && '' === $result['content'], 'empty stored CSV has a valid zero-byte CRC');
$result = unpack_fixture(array(array('name' => 'folder/', 'content' => '', 'method' => 0, 'external' => (0040755 << 16) | 0x10), array('name' => 'folder/data.csv')));
check(is_array($result), 'ordinary directory entries are accepted without extraction');
$result = unpack_fixture(array(array('name' => 'data.csv', 'method' => 0)));
check(is_array($result), 'ordinary stored members are supported');
foreach (array(true, false) as $signature) {
    $result = unpack_fixture(array(array('name' => 'data.csv', 'flags' => 0x0808, 'descriptorSignature' => $signature)));
    check(is_array($result), 'streaming ZIP descriptor is supported ' . ($signature ? 'with signature' : 'without signature'));
}
foreach (array('../data.csv', '/data.csv', 'C:/data.csv', 'C:data.csv', '\\server\\data.csv', 'a\\data.csv', 'a/../data.csv', 'a/./data.csv', 'a//data.csv', "a\0.csv", "a\n.csv", "a\x7f.csv", "a\xE2\x80\xAE.csv", "bad\xff.csv", 'a /data.csv', 'a./data.csv') as $name) {
    check(denied(unpack_fixture(array(array('name' => $name))), 'path'), 'unsafe archive path is rejected: ' . bin2hex($name));
}
check(denied(unpack_fixture(array(array('name' => 'data.csv')), '../data.csv'), 'path'), 'unsafe requested path is rejected');
check(denied(unpack_fixture(array(array('name' => 'data.csv'), array('name' => 'data.csv'))), 'ambiguous'), 'raw duplicate names are rejected before libzip normalization');
check(denied(unpack_fixture(array(array('name' => 'Data.csv'), array('name' => 'data.csv'))), 'ambiguous'), 'case-colliding member names are rejected');
check(denied(unpack_fixture(array(array('name' => 'link.csv', 'external' => 0120777 << 16))), 'path'), 'Unix symbolic link is rejected');
check(denied(unpack_fixture(array(array('name' => 'device.csv', 'external' => 0020600 << 16))), 'path'), 'Unix device file is rejected');
check(denied(unpack_fixture(array(array('name' => 'folder.csv', 'external' => 0040755 << 16))), 'path'), 'mislabelled directory attributes are rejected');
foreach (array(0x0801, 0x0840, 0x2800) as $flags) {
    check(denied(unpack_fixture(array(array('name' => 'data.csv', 'flags' => $flags))), 'encrypted'), 'encryption flag is rejected ' . dechex($flags));
}
check(denied(unpack_fixture(array(array('name' => 'data.csv', 'method' => 12))), 'format'), 'unsupported compression method is rejected');
check(denied(unpack_fixture(array(array('name' => 'data.csv', 'needed' => 45))), 'format'), 'ZIP64-required member is rejected');
$body = archive_bytes(array(array('name' => 'data.csv')), array('disk' => 1));
check(denied(Dataset_Archive::unpack($body, '', 1000000), 'format'), 'multi-disk archive is rejected');
$body = archive_bytes(array(array('name' => 'data.csv')));
check(denied(Dataset_Archive::unpack(substr($body, 0, -1), '', 1000000), 'corrupt'), 'truncated archive is rejected');
check(denied(Dataset_Archive::unpack($body . 'trailer', '', 1000000), 'corrupt'), 'unaccounted trailing bytes are rejected');
check(denied(Dataset_Archive::unpack('not zip', '', 1000000), 'corrupt'), 'non-ZIP input is rejected');
check(denied(Dataset_Archive::unpack($body, '', strlen($body) - 1), 'limit'), 'compressed input exceeding exact limit is rejected');
check(is_array(Dataset_Archive::unpack($body, '', strlen($body))), 'compressed input exactly at the limit is accepted');
check(denied(Dataset_Archive::unpack($body, '', 0), 'limit'), 'nonpositive limits are rejected');
$boundary = str_repeat("0123456789,abcdef\n", 128);
$body = archive_bytes(array(array('name' => 'data.csv', 'content' => $boundary)));
check(is_array(Dataset_Archive::unpack($body, '', strlen($boundary))), 'decompressed output exactly at limit is accepted');
check(denied(Dataset_Archive::unpack($body, '', strlen($boundary) - 1), 'limit'), 'decompressed output one byte over limit is rejected');
check(denied(unpack_fixture(array(array('name' => 'bomb.csv', 'content' => str_repeat('0', 1000000))), '', 2000000), 'limit'), 'high-ratio compressed payload is rejected before inflation');
$many = array();
for ($i = 0; $i < 1001; $i++) { $many[] = array('name' => $i . '.csv', 'content' => '', 'method' => 0); }
check(denied(unpack_fixture($many), 'limit'), 'entry count greater than 1000 is rejected');
$many = array();
for ($i = 0; $i < 9; $i++) { $many[] = array('name' => $i . '.csv', 'content' => str_repeat('row,data\n', 1024)); }
$body = archive_bytes($many);
check(denied(Dataset_Archive::unpack($body, '0.csv', strlen($many[0]['content'])), 'limit'), 'aggregate declared output exceeding eight times the limit is rejected');
check(denied(unpack_fixture(array(array('name' => 'data.csv', 'crc' => 1234))), 'corrupt'), 'payload CRC mismatch is rejected');
check(denied(unpack_fixture(array(array('name' => 'data.csv', 'content' => 'actual text', 'size' => 1))), 'corrupt'), 'forged decompressed size is rejected');
check(denied(unpack_fixture(array(array('name' => 'data.csv', 'localName' => 'evil.csv'))), 'corrupt'), 'local and central member name disagreement is rejected');
check(denied(unpack_fixture(array(array('name' => 'first.csv'), array('name' => 'next.csv', 'offset' => 0)), 'first.csv'), 'corrupt'), 'aliased local offsets are rejected');
check(denied(unpack_fixture(array(array('name' => 'data.csv', 'data' => 'broken deflate bytes'))), 'corrupt'), 'corrupt deflate stream is rejected');

// Also produce a ZIP using the native library, not only hand-built envelope fixtures.
$native = tempnam(sys_get_temp_dir(), 'ocl-native-test-');
try {
    $zip = new ZipArchive();
    if (true !== $zip->open($native, ZipArchive::CREATE | ZipArchive::OVERWRITE)) { throw new RuntimeException('Cannot build native ZIP fixture'); }
    $zip->addFromString('folder/native.csv', $csv);
    $zip->close();
    $result = Dataset_Archive::unpack(file_get_contents($native), 'folder/native.csv', 1000000);
    check(is_array($result) && $result['content'] === $csv, 'real ZipArchive-produced ZIP round-trips selected CSV');
} finally { @unlink($native); }
check((glob(sys_get_temp_dir() . '/ocl-zip-*') ?: array()) === $tempsBefore, 'all success and failure paths remove their temporary archives');
echo 'Dataset archive checks: ' . $checks . PHP_EOL;
