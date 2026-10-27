<?php

declare(strict_types=1);

namespace OjAddonStarter\Storage;

use WP_Error;

final class NoteRepository
{
    public const META_KEY = '_oj_addon_starter_note';
    public const MAX_BYTES = 4000;

    public static function read_for_current_user(): string
    {
        $userId = get_current_user_id();
        if ($userId < 1) {
            return '';
        }
        $value = get_user_meta($userId, self::META_KEY, true);
        return is_string($value) ? $value : '';
    }

    /** @return array{note: string}|WP_Error */
    public static function save_for_current_user(mixed $note): array|WP_Error
    {
        $userId = get_current_user_id();
        if ($userId < 1) {
            return new WP_Error('starter_authentication_required', '로그인이 필요합니다.', ['status' => 401]);
        }
        if (
            ! is_string($note)
            || strlen($note) > self::MAX_BYTES
            || preg_match('//u', $note) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1
        ) {
            return new WP_Error('starter_invalid_note', '메모는 UTF-8 텍스트 4,000바이트 이내로 입력해 주세요.', ['status' => 400]);
        }
        $note = sanitize_textarea_field($note);
        if ($note !== self::read_for_current_user()) {
            // update_user_meta unslashes input; preserve literal backslashes.
            if (update_user_meta($userId, self::META_KEY, wp_slash($note)) === false) {
                return new WP_Error('starter_save_failed', '메모를 저장하지 못했습니다. 다시 시도해 주세요.', ['status' => 500]);
            }
        }
        return ['note' => $note];
    }
}
