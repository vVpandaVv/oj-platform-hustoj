<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

final class UserLoginRecord
{
    public function __construct(
        private readonly int $id,
        private readonly int $userId,
        private readonly ?string $ipAddress,
        private readonly ?string $userAgent,
        private readonly string $loggedInAt
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['user_id'] ?? 0),
            self::nullableString($row['ip_address'] ?? null),
            self::nullableString($row['user_agent'] ?? null),
            (string) ($row['logged_in_at'] ?? '')
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function loggedInAt(): string
    {
        return $this->loggedInAt;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
