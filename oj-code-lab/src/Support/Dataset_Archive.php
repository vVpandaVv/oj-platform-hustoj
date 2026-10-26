<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Support;

use WP_Error;

/** Read one bounded data member; never extract archive paths onto the filesystem. */
final class Dataset_Archive {
    private const MAX_ENTRIES = 1000;
    private const MAX_RATIO = 200;
    private const MAX_TOTAL_BYTES = 134217728;

    /** @return array{content: string, fileName: string}|WP_Error */
    public static function unpack(string $body, string $requestedFile, int $maxBytes): array|WP_Error {
        if ($maxBytes < 1 || strlen($body) > $maxBytes) {
            return self::error('limit', 'ZIP 파일이 허용된 데이터 크기를 초과했습니다.', 413);
        }
        if (!class_exists('ZipArchive')) {
            return self::error('unavailable', '서버에 PHP ZIP 확장이 없습니다. CSV 또는 JSONL 파일로 풀어서 업로드하거나 관리자에게 ZIP 확장 설치를 요청해 주세요.', 503);
        }
        if ('' !== $requestedFile && !self::safe_name($requestedFile, false)) {
            return self::error('path', '선택한 ZIP 내부 파일 경로가 올바르지 않습니다.');
        }
        $entries = self::directory($body, $maxBytes);
        if ($entries instanceof WP_Error) { return $entries; }
        $supported = array_filter($entries, static fn (array $entry): bool => !$entry['directory'] && self::supported($entry['name']));
        $selected = null;
        foreach ($entries as $entry) {
            if ($entry['name'] === $requestedFile) {
                if ($entry['directory'] || !self::supported($entry['name'])) {
                    return self::error('format', 'ZIP에서 CSV, JSONL 또는 NDJSON 파일을 선택해 주세요.');
                }
                $selected = $entry;
                break;
            }
        }
        if (null === $selected) {
            if (0 === count($supported)) {
                $names = array_fill_keys(array_column($entries, 'name'), true);
                if (isset($names['[Content_Types].xml'], $names['word/document.xml'])) {
                    return self::error('office', '이 파일은 CSV/JSONL 데이터가 아니라 Word 문서(DOCX) 형식입니다. 확장자만 변경해서는 가져올 수 없습니다. UTF-8 CSV 또는 JSONL 원본 파일을 사용해 주세요.');
                }
                return self::error('format', 'ZIP 안에 CSV, JSONL 또는 NDJSON 파일이 없습니다.');
            }
            if (1 !== count($supported)) {
                return self::error('ambiguous', 'ZIP 안에 데이터 파일이 여러 개 있습니다. 사용할 CSV 또는 JSONL 파일 하나를 선택하거나 압축을 풀어 업로드해 주세요.');
            }
            $selected = reset($supported);
        }
        if ($selected['size'] > $maxBytes) { return self::error('limit', '압축을 푼 데이터가 허용된 크기를 초과했습니다.', 413); }

        $temp = @tempnam(sys_get_temp_dir(), 'ocl-zip-');
        if (false === $temp) { return self::error('temporary', 'ZIP을 처리할 임시 파일을 만들지 못했습니다.', 500); }
        $zip = new \ZipArchive();
        $opened = false;
        $stream = null;
        try {
            if (@file_put_contents($temp, $body) !== strlen($body)) { return self::error('temporary', 'ZIP 임시 파일을 저장하지 못했습니다.', 500); }
            if (true !== @$zip->open($temp, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS)) { return self::corrupt(); }
            $opened = true;
            if ($zip->numFiles !== count($entries)) { return self::corrupt(); }
            foreach ($entries as $index => $entry) {
                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                if (false === $stat || $stat['name'] !== $entry['name'] || $stat['size'] !== $entry['size']
                    || $stat['comp_size'] !== $entry['compressed'] || $stat['comp_method'] !== $entry['method']
                    || ($stat['encryption_method'] ?? 0) !== 0 || pack('V', $stat['crc']) !== pack('V', $entry['crc'])) {
                    return self::corrupt();
                }
            }
            // getStreamIndex requires PHP 8.2. All names were checked for duplicates first.
            $stream = @$zip->getStream($selected['name']);
            if (!is_resource($stream)) { return self::corrupt(); }
            $content = '';
            $crc = hash_init('crc32b');
            while (!feof($stream)) {
                $remaining = $maxBytes - strlen($content);
                $chunk = @fread($stream, min(8192, $remaining + 1));
                if (false === $chunk || ('' === $chunk && !feof($stream))) { return self::corrupt(); }
                if (strlen($chunk) > $remaining) { return self::error('limit', '압축을 푼 데이터가 허용된 크기를 초과했습니다.', 413); }
                $content .= $chunk;
                hash_update($crc, $chunk);
            }
            if (strlen($content) !== $selected['size'] || hash_final($crc) !== bin2hex(pack('N', $selected['crc']))) { return self::corrupt(); }
            return array('content' => $content, 'fileName' => basename($selected['name']));
        } catch (\Throwable $error) {
            return self::corrupt();
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            if ($opened) { @$zip->close(); }
            @unlink($temp);
        }
    }

    /**
     * Inspect raw names/flags before libzip can normalize names or hide duplicate entries.
     * This is a strict classic-ZIP envelope check, not a decompressor; libzip reads the data.
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private static function directory(string $body, int $maxBytes): array|WP_Error {
        $length = strlen($body);
        if ($length < 22 || (!str_starts_with($body, "PK\x03\x04") && !str_starts_with($body, "PK\x05\x06"))) { return self::corrupt(); }
        $end = strrpos($body, "PK\x05\x06");
        if (false === $end || $end + 22 > $length) { return self::corrupt(); }
        $directory = unpack('vdisk/vstart/vdiskEntries/ventries/Vbytes/Voffset/vcomment', substr($body, $end + 4, 18));
        if ($end + 22 + $directory['comment'] !== $length || $directory['offset'] + $directory['bytes'] !== $end) { return self::corrupt(); }
        if ($directory['disk'] !== 0 || $directory['start'] !== 0 || $directory['diskEntries'] !== $directory['entries']
            || $directory['entries'] === 65535 || $directory['offset'] === 4294967295 || $directory['bytes'] === 4294967295) {
            return self::error('format', '분할 ZIP과 ZIP64는 지원하지 않습니다. 데이터 파일 하나를 일반 ZIP으로 압축하거나 풀어서 업로드해 주세요.');
        }
        if ($directory['entries'] > self::MAX_ENTRIES) { return self::error('limit', 'ZIP 안의 파일 수가 1,000개를 초과했습니다.', 413); }
        $position = $directory['offset'];
        $entries = array();
        $names = array();
        $ranges = array();
        $total = 0;
        $totalLimit = min(self::MAX_TOTAL_BYTES, min($maxBytes, self::MAX_TOTAL_BYTES / 8) * 8);
        for ($index = 0; $index < $directory['entries']; $index++) {
            if ($position + 46 > $end || substr($body, $position, 4) !== "PK\x01\x02") { return self::corrupt(); }
            $entry = unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset', substr($body, $position + 4, 42));
            $recordEnd = $position + 46 + $entry['nameLength'] + $entry['extraLength'] + $entry['commentLength'];
            if ($recordEnd > $end) { return self::corrupt(); }
            $entry['name'] = substr($body, $position + 46, $entry['nameLength']);
            $entry['directory'] = str_ends_with($entry['name'], '/');
            if (!self::safe_name($entry['name'], $entry['directory'])) { return self::error('path', 'ZIP 안에 안전하지 않은 파일 경로가 있습니다.'); }
            $key = function_exists('mb_strtolower') ? mb_strtolower(rtrim($entry['name'], '/'), 'UTF-8') : strtolower(rtrim($entry['name'], '/'));
            if (isset($names[$key])) { return self::error('ambiguous', 'ZIP 안에 중복되거나 구별할 수 없는 파일 이름이 있습니다.'); }
            $names[$key] = true;
            if (($entry['flags'] & 0x2041) !== 0) { return self::error('encrypted', '암호화된 ZIP 파일은 지원하지 않습니다. 암호를 해제한 데이터 파일을 사용해 주세요.'); }
            if ($entry['disk'] !== 0 || $entry['needed'] >= 45 || ($entry['flags'] & ~0x080e) !== 0 || !in_array($entry['method'], array(0, 8), true)) {
                return self::error('format', '일반 ZIP의 저장 또는 Deflate 압축만 지원합니다. ZIP64와 다른 압축 형식은 풀어서 업로드해 주세요.');
            }
            $type = ($entry['external'] >> 16) & 0170000;
            if (!in_array($type, array(0, 0040000, 0100000), true)
                || (0040000 === $type && !$entry['directory']) || (0100000 === $type && $entry['directory'])
                || (($entry['external'] & 0x10) !== 0 && !$entry['directory'])) {
                return self::error('path', 'ZIP 안의 링크 또는 특수 파일은 사용할 수 없습니다.');
            }
            if ($entry['directory'] && $entry['size'] !== 0) { return self::corrupt(); }
            if ($entry['size'] > $totalLimit - $total || $entry['size'] / max(1, $entry['compressed']) > self::MAX_RATIO) {
                return self::error('limit', 'ZIP의 압축 해제 크기 또는 압축률이 안전 한도를 초과했습니다.', 413);
            }
            $total += $entry['size'];
            $local = $entry['offset'];
            if ($local + 30 > $directory['offset'] || substr($body, $local, 4) !== "PK\x03\x04") { return self::corrupt(); }
            $header = unpack('vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', substr($body, $local + 4, 26));
            $start = $local + 30 + $header['nameLength'] + $header['extraLength'];
            $dataEnd = $start + $entry['compressed'];
            if ($start > $directory['offset'] || $dataEnd > $directory['offset']
                || substr($body, $local + 30, $header['nameLength']) !== $entry['name']
                || $header['flags'] !== $entry['flags'] || $header['method'] !== $entry['method'] || $header['needed'] !== $entry['needed']) {
                return self::corrupt();
            }
            if (($entry['flags'] & 8) === 0) {
                if ($header['crc'] !== $entry['crc'] || $header['size'] !== $entry['size'] || $header['compressed'] !== $entry['compressed']) { return self::corrupt(); }
            } else {
                foreach (array('crc', 'size', 'compressed') as $field) {
                    if ($header[$field] !== 0 && $header[$field] !== $entry[$field]) { return self::corrupt(); }
                }
                $descriptor = $dataEnd;
                if (substr($body, $descriptor, 4) === "PK\x07\x08") { $descriptor += 4; }
                if ($descriptor + 12 > $directory['offset']) { return self::corrupt(); }
                $values = unpack('Vcrc/Vcompressed/Vsize', substr($body, $descriptor, 12));
                if ($values['crc'] !== $entry['crc'] || $values['compressed'] !== $entry['compressed'] || $values['size'] !== $entry['size']) { return self::corrupt(); }
                $dataEnd = $descriptor + 12;
            }
            $ranges[] = array($local, $dataEnd);
            $entries[] = $entry;
            $position = $recordEnd;
        }
        if ($position !== $end) { return self::corrupt(); }
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $previous = 0;
        foreach ($ranges as [$start, $stop]) {
            if ($start < $previous) { return self::corrupt(); }
            $previous = $stop;
        }
        return $entries;
    }

    private static function safe_name(string $name, bool $directory): bool {
        if ('' === $name || strlen($name) > 1024 || !preg_match('//u', $name)
            || preg_match('/[\p{Cc}\p{Cf}\\\\:]/u', $name) || str_starts_with($name, '/')) { return false; }
        $parts = explode('/', $directory ? substr($name, 0, -1) : $name);
        foreach ($parts as $part) {
            if ('' === $part || '.' === $part || '..' === $part || strlen($part) > 255 || preg_match('/[. ]$/', $part)) { return false; }
        }
        return true;
    }

    private static function supported(string $name): bool {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), array('csv', 'jsonl', 'ndjson'), true);
    }

    private static function corrupt(): WP_Error { return self::error('corrupt', 'ZIP 파일이 손상되었거나 내부 파일 정보가 일치하지 않습니다.'); }

    private static function error(string $code, string $message, int $status = 415): WP_Error {
        return new WP_Error('oj_code_lab_dataset_archive_' . $code, $message, array('status' => $status));
    }
}
