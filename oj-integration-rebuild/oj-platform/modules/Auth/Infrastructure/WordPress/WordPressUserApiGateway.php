<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserProfileWriter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSearchProvider;
use RuntimeException;

final class WordPressUserApiGateway implements
    WordPressUserGateway,
    WordPressUserProfileWriter,
    UserSearchProvider,
    \CodingPanda\OjPlatform\Modules\Auth\Contract\UserLoginBatchProvider
{
    public function searchUserIds(string $keyword, int $limit = 500): array
    {
        $keyword = trim($keyword);
        if ($keyword === '' || ! function_exists('get_users')) {
            return [];
        }
        $users = get_users([
            'search' => '*' . $keyword . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'fields' => 'ID',
            'number' => max(1, $limit),
        ]);

        return array_values(array_unique(array_map(
            static fn (mixed $userId): int => (int) $userId,
            is_array($users) ? $users : []
        )));
    }

    public function currentUser(): ?UserIdentity
    {
        if (! function_exists('is_user_logged_in') || ! is_user_logged_in()) {
            return null;
        }

        return $this->fromWordPressUser(wp_get_current_user());
    }

    public function findManyByLogin(array $logins): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($logins)), 500) as $chunk) {
            if (function_exists('get_users')) {
                foreach (get_users(['login__in' => $chunk, 'number' => count($chunk)]) as $user) {
                    $identity = is_object($user) ? $this->fromWordPressUser($user) : null;
                    if ($identity !== null) { $found[$identity->userLogin()] = $identity; }
                }
            } else {
                foreach ($chunk as $login) {
                    $identity = $this->findByLogin($login);
                    if ($identity !== null) { $found[$login] = $identity; }
                }
            }
        }
        return $found;
    }

    public function findById(int $userId): ?UserIdentity
    {
        if ($userId < 1) {
            return null;
        }

        $user = get_user_by('id', $userId);

        return is_object($user) ? $this->fromWordPressUser($user) : null;
    }

    public function findByLogin(string $userLogin): ?UserIdentity
    {
        $user = get_user_by('login', $userLogin);

        return is_object($user) ? $this->fromWordPressUser($user) : null;
    }

    public function findByEmail(string $email): ?UserIdentity
    {
        $user = get_user_by('email', $email);

        return is_object($user) ? $this->fromWordPressUser($user) : null;
    }

    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity
    {
        $user = wp_authenticate($userLogin, $password);
        if (is_wp_error($user) || ! is_object($user)) {
            return null;
        }

        return $this->fromWordPressUser($user);
    }

    public function establishSession(int $userId, bool $remember): void
    {
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, $remember, is_ssl());
    }

    public function createUser(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        string $role
    ): UserIdentity {
        $result = wp_insert_user([
            'user_login' => $userLogin,
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => $displayName,
            'nickname' => $displayName,
            'role' => $role,
        ]);

        if (is_wp_error($result) || ! is_int($result) || $result < 1) {
            throw new RuntimeException('WordPress 사용자를 만들지 못했습니다.');
        }

        $identity = $this->findById($result);
        if ($identity === null) {
            throw new RuntimeException('생성한 WordPress 사용자를 확인하지 못했습니다.');
        }

        return $identity;
    }

    public function updateUser(
        int $userId,
        string $email,
        string $displayName
    ): UserIdentity {
        return $this->writeIdentity($userId, $email, $displayName, null);
    }

    public function updateProfileIdentity(
        int $userId,
        string $email,
        string $displayName,
        string $nickname
    ): UserIdentity {
        return $this->writeIdentity($userId, $email, $displayName, $nickname);
    }

    private function writeIdentity(
        int $userId,
        string $email,
        string $displayName,
        ?string $nickname
    ): UserIdentity {
        $fields = [
            'ID' => $userId,
            'user_email' => $email,
            'display_name' => $displayName,
        ];
        // Self-service updates must not overwrite the administrator-managed student name.
        if ($nickname !== null) {
            $fields['nickname'] = $nickname;
        }
        $result = $nickname === null
            ? wp_update_user($fields)
            : WordPressManagedUserNameWriteScope::run(
                $userId,
                static fn () => wp_update_user($fields)
            );

        if (is_wp_error($result)) {
            throw new RuntimeException('WordPress 사용자 정보를 수정하지 못했습니다.');
        }

        $identity = $this->findById($userId);
        if ($identity === null) {
            throw new RuntimeException('수정한 WordPress 사용자를 확인하지 못했습니다.');
        }

        return $identity;
    }

    public function setRoles(int $userId, array $roles): void
    {
        $user = get_user_by('id', $userId);
        if (! is_object($user) || ! method_exists($user, 'set_role')) {
            throw new RuntimeException('역할을 변경할 WordPress 사용자를 찾지 못했습니다.');
        }

        $roles = array_values(array_unique(array_map('strval', $roles)));
        if ($roles === []) {
            $roles = ['subscriber'];
        }

        $user->set_role(array_shift($roles));
        foreach ($roles as $role) {
            $user->add_role($role);
        }
    }

    public function setPassword(int $userId, string $password): void
    {
        wp_set_password($password, $userId);
    }

    public function invalidateSessions(int $userId): void
    {
        if (! class_exists('WP_Session_Tokens')) {
            throw new RuntimeException('WordPress 세션 관리 기능을 사용할 수 없습니다.');
        }

        \WP_Session_Tokens::get_instance($userId)->destroy_all();
    }

    public function requestPasswordReset(string $userLogin): void
    {
        $user = get_user_by('login', $userLogin);
        if (! is_object($user)) {
            return;
        }

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            throw new RuntimeException('비밀번호 복구 키를 만들지 못했습니다.');
        }

        $email = (string) ($user->user_email ?? '');
        if ($email === '') {
            return;
        }

        $url = add_query_arg(
            [
                'mode' => 'reset',
                'key' => $key,
                'login' => (string) ($user->user_login ?? ''),
            ],
            home_url('/login')
        );
        $sent = wp_mail(
            $email,
            '비밀번호 재설정 안내',
            "아래 주소에서 비밀번호를 다시 설정해 주세요.\n\n" . $url
        );
        if (! $sent) {
            throw new RuntimeException('비밀번호 복구 메일을 보내지 못했습니다.');
        }
    }

    public function resetPasswordWithKey(
        string $userLogin,
        string $resetKey,
        string $newPassword
    ): void {
        if (strlen($newPassword) < 12) {
            throw new RuntimeException('새 비밀번호는 12자 이상이어야 합니다.');
        }

        $user = check_password_reset_key($resetKey, $userLogin);
        if (is_wp_error($user) || ! is_object($user)) {
            throw new RuntimeException('비밀번호 재설정 주소가 만료되었거나 올바르지 않습니다.');
        }

        reset_password($user, $newPassword);
    }

    public function deleteUser(int $userId): void
    {
        if (! function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        if (! wp_delete_user($userId)) {
            throw new RuntimeException('WordPress 사용자 보상 삭제에 실패했습니다.');
        }
    }

    public function findMany(array $userIds): array
    {
        $userIds = array_values(array_filter(
            array_unique(array_map('intval', $userIds)),
            static fn (int $userId): bool => $userId > 0
        ));
        if ($userIds === []) {
            return [];
        }
        if (function_exists('get_users')) {
            $byId = [];
            foreach (array_chunk($userIds, 500) as $chunk) {
                foreach (get_users([
                    'include' => $chunk,
                    'orderby' => 'include',
                ]) as $user) {
                    if (! is_object($user)) {
                        continue;
                    }
                    $identity = $this->fromWordPressUser($user);
                    if ($identity !== null) {
                        $byId[$identity->userId()] = $identity;
                    }
                }
            }

            return array_values(array_filter(array_map(
                static fn (int $userId): ?UserIdentity =>
                    $byId[$userId] ?? null,
                $userIds
            )));
        }
        $identities = [];
        foreach ($userIds as $userId) {
            $identity = $this->findById($userId);
            if ($identity !== null) {
                $identities[] = $identity;
            }
        }

        return $identities;
    }

    private function fromWordPressUser(object $user): ?UserIdentity
    {
        $userId = (int) ($user->ID ?? 0);
        $userLogin = (string) ($user->user_login ?? '');
        if ($userId < 1 || $userLogin === '') {
            return null;
        }

        $roles = is_array($user->roles ?? null)
            ? array_values(array_map('strval', $user->roles))
            : [];
        $nickname = function_exists('get_user_meta')
            ? trim((string) get_user_meta($userId, 'nickname', true))
            : '';
        $registeredAt = trim((string) ($user->user_registered ?? ''));
        if ($registeredAt === '' || str_starts_with($registeredAt, '0000-00-00')) {
            $registeredAt = null;
        }

        return new UserIdentity(
            $userId,
            $userLogin,
            (string) ($user->user_email ?? ''),
            (string) ($user->display_name ?? $userLogin),
            $roles,
            $nickname === '' ? null : $nickname,
            $registeredAt
        );
    }
}
