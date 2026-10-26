<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Contract;

use InvalidArgumentException;

final class JudgeCredentials
{
    public function __construct(
        private readonly string $username,
        private readonly string $password
    ) {
        if (trim($this->username) === '') {
            throw new InvalidArgumentException('Judge DB 계정 이름은 비어 있을 수 없습니다.');
        }
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->username, $other->username)
            && hash_equals($this->password, $other->password);
    }
}
