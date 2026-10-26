<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use InvalidArgumentException;

final class UserImportCandidate
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly int $rowNumber,
        private readonly string $userLogin,
        private readonly string $email,
        private readonly string $password,
        private readonly string $displayName,
        private readonly ?string $affiliation,
        private readonly ?string $birthDate,
        private readonly string $role,
        private readonly array $errors = []
    ) {
        if ($this->rowNumber < 2) {
            throw new InvalidArgumentException('CSV 행 번호가 올바르지 않습니다.');
        }
    }

    public function rowNumber(): int
    {
        return $this->rowNumber;
    }

    public function userLogin(): string
    {
        return $this->userLogin;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function password(): string
    {
        return $this->password;
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

    public function role(): string
    {
        return $this->role;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'user_login' => $this->userLogin,
            'email' => $this->email,
            'password' => $this->password,
            'display_name' => $this->displayName,
            'affiliation' => $this->affiliation,
            'birth_date' => $this->birthDate,
            'role' => $this->role,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            (int) ($payload['row_number'] ?? 0),
            (string) ($payload['user_login'] ?? ''),
            (string) ($payload['email'] ?? ''),
            (string) ($payload['password'] ?? ''),
            (string) ($payload['display_name'] ?? ''),
            self::nullableString($payload['affiliation'] ?? null),
            self::nullableString($payload['birth_date'] ?? null),
            (string) ($payload['role'] ?? 'subscriber')
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
