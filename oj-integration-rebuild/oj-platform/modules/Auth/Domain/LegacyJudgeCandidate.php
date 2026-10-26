<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use InvalidArgumentException;

final class LegacyJudgeCandidate
{
    public function __construct(
        private readonly string $userLogin,
        private readonly string $email,
        private readonly string $displayName,
        private readonly ?string $affiliation,
        private readonly ?string $birthDate
    ) {
        if (trim($this->userLogin) === '') {
            throw new InvalidArgumentException('기존 계정 로그인 아이디는 비어 있을 수 없습니다.');
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

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function affiliation(): ?string
    {
        return $this->affiliation;
    }

    public function birthDate(): ?string
    {
        return $this->birthDate;
    }

    public function fingerprint(): string
    {
        return hash(
            'sha256',
            implode("\0", [
                $this->userLogin,
                $this->email,
                $this->displayName,
                $this->affiliation ?? '',
                $this->birthDate ?? '',
            ])
        );
    }
}
