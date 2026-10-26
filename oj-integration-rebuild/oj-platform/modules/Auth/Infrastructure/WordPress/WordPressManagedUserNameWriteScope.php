<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use InvalidArgumentException;

/** Trusted OJ services authorize the actor before opening this target-specific write scope. */
final class WordPressManagedUserNameWriteScope
{
    /** @var array<int, int> */
    private static array $targets = [];

    public static function allows(int $userId): bool
    {
        return (self::$targets[$userId] ?? 0) > 0;
    }

    public static function run(int $userId, callable $write): mixed
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('관리용 이름을 변경할 사용자 번호가 올바르지 않습니다.');
        }
        self::$targets[$userId] = (self::$targets[$userId] ?? 0) + 1;
        try {
            return $write();
        } finally {
            if (--self::$targets[$userId] === 0) {
                unset(self::$targets[$userId]);
            }
        }
    }
}
