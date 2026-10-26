<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use InvalidArgumentException;

final class UserProfile
{
    public function __construct(
        private readonly int $userId,
        private readonly ?string $affiliation,
        private readonly ?string $birthDate,
        private readonly AccessStatus $accessStatus,
        private readonly bool $mustChangePassword,
        private readonly ?string $bio = null
    ) {
        if ($this->userId < 1) {
            throw new InvalidArgumentException('사용자 번호는 1 이상이어야 합니다.');
        }

        if ($this->birthDate !== null && ! $this->isValidDate($this->birthDate)) {
            throw new InvalidArgumentException('생년은 실제 날짜를 YYYY-MM-DD 형식으로 입력해야 합니다.');
        }
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function affiliation(): ?string
    {
        return $this->affiliation;
    }

    public function birthDate(): ?string
    {
        return $this->birthDate;
    }

    public function accessStatus(): AccessStatus
    {
        return $this->accessStatus;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function bio(): ?string
    {
        return $this->bio;
    }

    public function withAccessStatus(AccessStatus $status): self
    {
        return new self(
            $this->userId,
            $this->affiliation,
            $this->birthDate,
            $status,
            $this->mustChangePassword,
            $this->bio
        );
    }

    public function withPasswordChangeRequired(bool $required): self
    {
        return new self(
            $this->userId,
            $this->affiliation,
            $this->birthDate,
            $this->accessStatus,
            $required,
            $this->bio
        );
    }

    private function isValidDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
