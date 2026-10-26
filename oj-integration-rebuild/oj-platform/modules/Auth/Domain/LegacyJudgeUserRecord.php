<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use InvalidArgumentException;

final class LegacyJudgeUserRecord
{
    public function __construct(
        private readonly string $userLogin,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly string $displayName,
        private readonly ?string $affiliation,
        private readonly bool $active
    ) {
        if (trim($this->userLogin) === '' || trim($this->passwordHash) === '') {
            throw new InvalidArgumentException('기존 Judge 계정 원본이 올바르지 않습니다.');
        }
    }

    public function userLogin(): string
    {
        return $this->userLogin;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function affiliation(): ?string
    {
        return $this->affiliation;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
