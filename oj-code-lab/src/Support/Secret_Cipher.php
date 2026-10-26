<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Support;
use WP_Error;
/** Versioned authenticated encryption; legacy CBC remains readable for existing sites. */
final class Secret_Cipher {
    public static function encrypt(string $plaintext): string|WP_Error {
        if (! function_exists('openssl_encrypt')) { return self::error(); }
        try {
            $iv = random_bytes(12); $tag = '';
            $encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'oj-code-lab:v2');
            if (false === $encrypted) { return self::error(); }
            return 'v2:' . base64_encode($iv . $tag . $encrypted);
        } catch (\Throwable) { return self::error(); }
    }
    public static function decrypt(string $encoded): string {
        if ('' === $encoded || ! function_exists('openssl_decrypt')) { return ''; }
        try {
            if (str_starts_with($encoded, 'v2:')) {
                $raw = base64_decode(substr($encoded, 3), true);
                if (false === $raw || strlen($raw) < 28) { return ''; }
                $value = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'oj-code-lab:v2');
            } else {
                $raw = base64_decode($encoded, true);
                if (false === $raw || strlen($raw) < 32 || strlen($raw) % 16 !== 0) { return ''; }
                $value = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
            }
            return false === $value ? '' : $value;
        } catch (\Throwable) { return ''; }
    }
    private static function key(): string { return hash('sha256', wp_salt('auth'), true); }
    private static function error(): WP_Error { return new WP_Error('oj_code_lab_encryption_failed', 'Kaggle 키를 안전하게 저장하지 못했습니다. OpenSSL 설정을 확인해 주세요.', array('status' => 500)); }
}
