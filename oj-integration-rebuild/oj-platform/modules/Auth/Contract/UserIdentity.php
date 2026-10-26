<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use InvalidArgumentException;

final class UserIdentity
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly int $userId,
        private readonly string $userLogin,
        private readonly string $email,
        private readonly string $displayName,
        private readonly array $roles = [],
        private readonly ?string $nickname = null,
        private readonly ?string $registeredAt = null
    ) {
        if ($this->userId < 1) {
            throw new InvalidArgumentException('사용자 번호는 1 이상이어야 합니다.');
        }

        if (trim($this->userLogin) === '') {
            throw new InvalidArgumentException('로그인 아이디는 비어 있을 수 없습니다.');
        }
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function userLogin(): string
    {
        return $this->userLogin;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function nickname(): string
    {
        $nickname = trim((string) $this->nickname);

        return $nickname === '' ? $this->displayName : $nickname;
    }

    public function registeredAt(): ?string
    {
        return $this->registeredAt;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->roles;
    }
}
