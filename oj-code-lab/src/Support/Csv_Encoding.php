<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Support;
use WP_Error;
final class Csv_Encoding {
    /** Only lossless UTF-8/CP949 text is accepted; never drop undecodable bytes. */
    public static function normalize(string $content): string|WP_Error {
        if (str_starts_with($content, "\xEF\xBB\xBF")) { $content = substr($content, 3); }
        if (str_starts_with($content, "PK\x03\x04") || str_starts_with($content, "PK\x05\x06")) {
            return new WP_Error('oj_code_lab_dataset_archive', '중첩된 압축 파일은 지원하지 않습니다. ZIP 안의 CSV 또는 JSONL 파일을 선택해 주세요.', array('status' => 415));
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content) || preg_match('/^\s*(?:<!doctype\s+html|<html|%PDF-)/i', $content)) {
            return self::invalid();
        }
        if (preg_match('//u', $content)) { return $content; }
        if (function_exists('iconv')) {
            $converted = @iconv('CP949', 'UTF-8', $content);
            if (false !== $converted && @iconv('UTF-8', 'CP949', $converted) === $content) { return $converted; }
        }
        if (function_exists('mb_convert_encoding')) {
            $converted = mb_convert_encoding($content, 'UTF-8', 'CP949');
            if (mb_convert_encoding($converted, 'CP949', 'UTF-8') === $content) { return $converted; }
        }
        return self::invalid();
    }
    private static function invalid(): WP_Error {
        return new WP_Error('oj_code_lab_dataset_format', 'UTF-8 또는 CP949 텍스트 CSV·JSONL만 지원합니다. ZIP 이외의 압축 형식은 먼저 풀어 주세요.', array('status' => 415));
    }
}
