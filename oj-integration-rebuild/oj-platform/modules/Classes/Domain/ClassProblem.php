<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Domain;

final class ClassProblem
{
    public function __construct(
        private readonly int $problemId,
        private readonly int $sortOrder,
        private readonly bool $visible,
        private readonly ?string $availableFrom,
        private readonly ?string $availableUntil
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['problem_id'] ?? 0),
            (int) ($row['sort_order'] ?? 0),
            (int) ($row['is_visible'] ?? 0) === 1,
            self::nullableString($row['available_from'] ?? null),
            self::nullableString($row['available_until'] ?? null)
        );
    }

    public function problemId(): int
    {
        return $this->problemId;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function availableFrom(): ?string
    {
        return $this->availableFrom;
    }

    public function availableUntil(): ?string
    {
        return $this->availableUntil;
    }
    public function isAvailableAt(string $now): bool
    {
        return $this->availabilityAt($now) === 'available';
    }

    public function availabilityAt(string $now): string
    {
        if (! $this->visible) {
            return 'hidden';
        }
        if ($this->availableFrom !== null && $this->availableFrom > $now) {
            return 'scheduled';
        }
        if ($this->availableUntil !== null && $this->availableUntil < $now) {
            return 'closed';
        }

        return 'available';
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === ''
            ? null
            : (string) $value;
    }
}
