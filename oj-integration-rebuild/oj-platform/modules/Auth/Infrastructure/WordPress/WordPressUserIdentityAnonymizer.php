<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentityAnonymizer;
use RuntimeException;

final class WordPressUserIdentityAnonymizer implements UserIdentityAnonymizer
{
    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function anonymousLogin(int $userId): string
    {
        if ($userId < 1) {
            throw new RuntimeException('익명화할 사용자 번호가 올바르지 않습니다.');
        }

        $readable = 'deleted_' . $userId;
        if (strlen($readable) <= 20) {
            return $readable;
        }

        return 'd' . str_pad((string) $userId, 19, '0', STR_PAD_LEFT);
    }

    public function anonymize(int $userId): void
    {
        if (! function_exists('get_userdata') || ! method_exists($this->database, 'update')) {
            throw new RuntimeException('WordPress 사용자 익명화 기능을 사용할 수 없습니다.');
        }
        $user = get_userdata($userId);
        if (! is_object($user)) {
            throw new RuntimeException('익명화할 WordPress 사용자를 찾을 수 없습니다.');
        }

        $password = bin2hex(random_bytes(32));
        $passwordHash = function_exists('wp_hash_password')
            ? wp_hash_password($password)
            : password_hash($password, PASSWORD_DEFAULT);
        $login = $this->anonymousLogin($userId);
        $table = isset($this->database->users) && is_string($this->database->users)
            ? $this->database->users
            : '';
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('WordPress 사용자 테이블을 확인할 수 없습니다.');
        }

        $updated = $this->database->update(
            $table,
            [
                'user_login' => $login,
                'user_pass' => $passwordHash,
                'user_nicename' => $login,
                'user_email' => $login . '@account.invalid',
                'user_url' => '',
                'user_activation_key' => '',
                'display_name' => '삭제된 사용자',
            ],
            ['ID' => $userId],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        if ($updated === false) {
            throw new RuntimeException('WordPress 사용자 개인정보를 익명화하지 못했습니다.');
        }

        if (class_exists('WP_User')) {
            $wpUser = new \WP_User($userId);
            $wpUser->set_role('');
        }
        foreach (['first_name', 'last_name', 'description', 'locale'] as $metaKey) {
            if (function_exists('delete_user_meta')) {
                delete_user_meta($userId, $metaKey);
            }
        }
        if (function_exists('update_user_meta')) {
            WordPressManagedUserNameWriteScope::run(
                $userId,
                static fn () => update_user_meta($userId, 'nickname', '삭제된 사용자')
            );
        }
        if (class_exists('WP_Session_Tokens')) {
            \WP_Session_Tokens::get_instance($userId)->destroy_all();
        }
        if (function_exists('clean_user_cache')) {
            clean_user_cache($userId);
        }
    }

    public function isAnonymized(int $userId): bool
    {
        if (! function_exists('get_userdata')) {
            throw new RuntimeException('WordPress 사용자 정보를 확인할 수 없습니다.');
        }
        $user = get_userdata($userId);
        $login = $this->anonymousLogin($userId);

        return is_object($user)
            && (string) ($user->user_login ?? '') === $login
            && (string) ($user->user_email ?? '') === $login . '@account.invalid'
            && (string) ($user->display_name ?? '') === '삭제된 사용자';
    }
}
