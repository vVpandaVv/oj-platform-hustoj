<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Datasets;
use OjCodeLab\Support\Secret_Cipher;
use WP_Error;
final class Kaggle_Settings {
    private static function credentials(): array {
        $saved = get_option('oj_code_lab_kaggle_credentials');
        return is_array($saved) ? $saved : array('username' => (string) get_option('oj_code_lab_kaggle_username', ''), 'key' => (string) get_option('oj_code_lab_kaggle_key', ''));
    }
    public static function username(): string { return self::credentials()['username']; }
    public static function key(): string { return Secret_Cipher::decrypt(self::credentials()['key']); }
    public static function is_configured(): bool { return '' !== self::username() && '' !== self::key(); }
    public static function save(string $username, string $key): bool|WP_Error {
        $username = trim($username); $key = trim($key);
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $username) || strlen($key) > 1024) {
            return new WP_Error('oj_code_lab_kaggle_invalid', 'Kaggle 사용자 이름과 API 키를 확인해 주세요.', array('status' => 400));
        }
        if ('' === $key) {
            if ($username !== self::username() || '' === self::key()) { return new WP_Error('oj_code_lab_kaggle_key_required', '사용자를 변경하거나 처음 설정할 때 API 키를 입력해 주세요.', array('status' => 400)); }
            $key = self::key();
        }
        $encrypted = Secret_Cipher::encrypt($key);
        if (is_wp_error($encrypted)) { return $encrypted; }
        $value = array('username' => $username, 'key' => $encrypted);
        if (! update_option('oj_code_lab_kaggle_credentials', $value, false) && get_option('oj_code_lab_kaggle_credentials') !== $value) {
            return new WP_Error('oj_code_lab_settings_failed', 'Kaggle 설정 저장에 실패했습니다.', array('status' => 500));
        }
        delete_option('oj_code_lab_kaggle_username'); delete_option('oj_code_lab_kaggle_key');
        do_action('oj_code_lab_audit', 'kaggle_settings_saved', get_current_user_id());
        return true;
    }
    public static function clear(): bool|WP_Error {
        // Empty record masks legacy values even if a legacy option delete fails.
        $empty = array('username' => '', 'key' => '');
        if (! update_option('oj_code_lab_kaggle_credentials', $empty, false) && get_option('oj_code_lab_kaggle_credentials') !== $empty) {
            return new WP_Error('oj_code_lab_settings_failed', 'Kaggle 설정 삭제에 실패했습니다.', array('status' => 500));
        }
        delete_option('oj_code_lab_kaggle_username'); delete_option('oj_code_lab_kaggle_key');
        do_action('oj_code_lab_audit', 'kaggle_settings_cleared', get_current_user_id());
        return true;
    }
}
