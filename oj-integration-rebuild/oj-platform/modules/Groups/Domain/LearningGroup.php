<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Domain;

final class LearningGroup
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $slug,
        private readonly string $description,
        private readonly string $visibility,
        private readonly string $approvalMode,
        private readonly bool $enabled,
        private readonly ?string $blocklyProfileKey,
        private readonly string $evaluationMode,
        private readonly ?string $joinCodeHash,
        private readonly int $ownerUserId,
        private readonly ?string $deletedAt
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
            (string) ($row['approval_mode'] ?? 'manual'),
            (int) ($row['enabled'] ?? 0) === 1,
            self::nullableString($row['blockly_profile_key'] ?? null),
            (string) ($row['evaluation_mode'] ?? 'normal'),
            self::nullableString($row['join_code_hash'] ?? null),
            (int) ($row['owner_user_id'] ?? 0),
            self::nullableString($row['deleted_at'] ?? null)
        );
    }

    public function id(): int { return $this->id; }
    public function name(): string { return $this->name; }
    public function slug(): string { return $this->slug; }
    public function description(): string { return $this->description; }
    public function visibility(): string { return $this->visibility; }
    public function approvalMode(): string { return $this->approvalMode; }
    public function isEnabled(): bool { return $this->enabled; }
    public function blocklyProfileKey(): ?string { return $this->blocklyProfileKey; }
    public function evaluationMode(): string { return $this->evaluationMode; }
    public function ownerUserId(): int { return $this->ownerUserId; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function isPerformanceMode(): bool
    {
        return $this->evaluationMode === 'performance';
    }

    public function acceptsJoinCode(string $joinCode): bool
    {
        return $this->visibility !== 'code'
            || (
                $this->joinCodeHash !== null
                && password_verify($joinCode, $this->joinCodeHash)
            );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === ''
            ? null
            : (string) $value;
    }
}
