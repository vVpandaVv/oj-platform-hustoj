<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Application\AccountInputPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ManagedUserNameUpdater;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use DomainException;
use RuntimeException;

final class WordPressManagedUserNameUpdater implements ManagedUserNameUpdater
{
    public function __construct(
        private readonly WordPressUserGateway $users,
        private readonly AccountInputPolicy $inputs
    ) {
    }

    public function update(int $userId, string $name, string $expectedName): string
    {
        $name = $this->inputs->nickname($name);
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new DomainException('사용자를 찾지 못했습니다.');
        }
        $storedName = (string) get_user_meta($userId, 'nickname', true);
        if ($storedName === $name) {
            return $name;
        }
        $currentName = trim($storedName) === '' ? $user->displayName() : trim($storedName);
        if ($currentName !== $expectedName) {
            throw new DomainException('다른 곳에서 관리용 이름을 변경했습니다. 새로고침 후 확인해 주세요.');
        }
        // Only nickname is written; public identity, credentials and roles stay untouched.
        WordPressManagedUserNameWriteScope::run(
            $userId,
            static fn () => update_user_meta($userId, 'nickname', wp_slash($name), $storedName)
        );
        if ((string) get_user_meta($userId, 'nickname', true) !== $name) {
            throw new RuntimeException('관리용 이름을 저장하지 못했습니다.');
        }

        return $name;
    }
}
