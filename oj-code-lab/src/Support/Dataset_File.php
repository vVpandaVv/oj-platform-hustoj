<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Support;
use WP_Error;

/** One normalized, bounded data file shared by remote and uploaded sources. */
final class Dataset_File {
    private const MAX_JSONL_LINE_BYTES = 1_048_576;

    public static function supported_name(string $name): bool {
        return 1 === preg_match('/\.(csv|jsonl|ndjson|zip)$/iD', $name);
    }

    /** @return array{content: string, fileName: string, format: string}|WP_Error */
    public static function normalize(string $content, string $file_name): array|WP_Error {
        $limit = Dataset_Limits::max_bytes();
        if (strlen($content) > $limit) { return self::too_large($limit); }
        if (str_starts_with($content, "PK\x03\x04") || str_starts_with($content, "PK\x05\x06") || 1 === preg_match('/\.zip$/iD', $file_name)) {
            $file = Dataset_Archive::unpack($content, $file_name, $limit);
            if (is_wp_error($file)) { return $file; }
            $content = $file['content'];
            $file_name = $file['fileName'];
        }
        $content = Csv_Encoding::normalize($content);
        if (is_wp_error($content)) { return $content; }
        if (strlen($content) > $limit) { return self::too_large($limit); }
        $file_name = basename(str_replace('\\', '/', $file_name));
        $file_name = preg_replace('/[\x00-\x1f\x7f]/', '', $file_name) ?? '';
        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $jsonl = in_array($extension, array('jsonl', 'ndjson'), true)
            || (! in_array($extension, array('csv', 'jsonl', 'ndjson'), true)
                && str_starts_with(ltrim($content), '{'));
        if ($jsonl) {
            $error = self::validate_jsonl($content);
            if (null !== $error) { return $error; }
        }
        $format = $jsonl ? 'jsonl' : 'csv';
        if ('' === $file_name || strlen($file_name) > 240) { $file_name = 'data.' . $format; }
        elseif (! in_array($extension, array('csv', 'jsonl', 'ndjson'), true)) { $file_name .= '.' . $format; }
        return array('content' => $content, 'fileName' => $file_name, 'format' => $format);
    }

    private static function validate_jsonl(string $content): ?WP_Error {
        $offset = 0;
        $line_number = 0;
        $records = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $end = strpos($content, "\n", $offset);
            if (false === $end) { $end = $length; }
            $line_number++;
            if ($end - $offset > self::MAX_JSONL_LINE_BYTES) {
                return new WP_Error('oj_code_lab_jsonl_line_size', sprintf('JSONL %d번째 줄이 너무 큽니다. 한 줄은 최대 1MiB까지 지원합니다.', $line_number), array('status' => 413));
            }
            $line = trim(substr($content, $offset, $end - $offset));
            $offset = $end + 1;
            if ('' === $line) { continue; }
            // Validate a single record at a time, never build the whole dataset in PHP.
            $decoded = json_decode($line, false, 64, JSON_BIGINT_AS_STRING);
            if (JSON_ERROR_NONE !== json_last_error() || (! is_object($decoded) && ! is_array($decoded))) {
                return new WP_Error('oj_code_lab_jsonl_invalid', sprintf('JSONL %d번째 줄을 읽지 못했습니다. 각 줄에 JSON 객체 또는 배열 하나가 있어야 합니다.', $line_number), array('status' => 415));
            }
            unset($decoded);
            $records++;
        }
        return $records > 0 ? null : new WP_Error('oj_code_lab_jsonl_empty', 'JSONL 파일에 읽을 데이터가 없습니다.', array('status' => 415));
    }

    private static function too_large(int $limit): WP_Error {
        return new WP_Error('oj_code_lab_dataset_too_large', Dataset_Limits::too_large_message($limit), array('status' => 413, 'maxBytes' => $limit));
    }
}
