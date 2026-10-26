<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Domain;

final class LearningClass
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $slug,
        private readonly string $description,
        private readonly string $visibility,
        private readonly string $progressVisibility,
        private readonly bool $enabled,
        private readonly ?string $startsAt,
        private readonly ?string $endsAt,
        private readonly ?string $passwordHash,
        private readonly ?string $blocklyProfileKey,
        private readonly int $ownerUserId,
        private readonly ?string $deletedAt,
        private readonly bool $approvalRequired = false
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['name'] ?? ''),
            (string) ($row['slug'] ?? ''),
            (string) ($row['description'] ?? ''),
            (string) ($row['visibility'] ?? 'private'),
            (string) ($row['progress_visibility'] ?? 'private'),
            (int) ($row['enabled'] ?? 0) === 1,
            self::nullableString($row['starts_at'] ?? null),
            self::nullableString($row['ends_at'] ?? null),
            self::nullableString($row['password_hash'] ?? null),
            self::nullableString($row['blockly_profile_key'] ?? null),
            (int) ($row['owner_user_id'] ?? 0),
            self::nullableString($row['deleted_at'] ?? null),
            (int) ($row['approval_required'] ?? 0) === 1
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function visibility(): string
    {
        return $this->visibility;
    }

    public function progressVisibility(): string
    {
        return $this->progressVisibility;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function startsAt(): ?string
    {
        return $this->startsAt;
    }

    public function endsAt(): ?string
    {
        return $this->endsAt;
    }

    public function acceptsPassword(string $password): bool
    {
        return $this->visibility !== 'password'
            || (
                $this->passwordHash !== null
                && password_verify($password, $this->passwordHash)
            );
    }

    public function blocklyProfileKey(): ?string
    {
        return $this->blocklyProfileKey;
    }

    public function ownerUserId(): int
    {
        return $this->ownerUserId;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function requiresApproval(): bool { return $this->approvalRequired; }

    public function isOpenAt(string $now): bool
    {
        return $this->enabled
            && ! $this->isDeleted()
            && ($this->startsAt === null || $this->startsAt <= $now)
            && ($this->endsAt === null || $this->endsAt >= $now);
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === ''
            ? null
            : (string) $value;
    }
}
