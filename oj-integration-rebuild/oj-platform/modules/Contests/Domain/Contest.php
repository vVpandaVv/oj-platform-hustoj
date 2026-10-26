<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class Contest
{
    public function __construct(
        private readonly int $id,
        private readonly string $title,
        private readonly string $description,
        private readonly string $startsAt,
        private readonly string $endsAt,
        private readonly bool $archived,
        private readonly bool $private
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['contest_id'] ?? 0),
            (string) ($row['title'] ?? ''),
            (string) ($row['description'] ?? ''),
            (string) ($row['start_time'] ?? ''),
            (string) ($row['end_time'] ?? ''),
            strtoupper((string) ($row['defunct'] ?? 'N')) === 'Y',
            in_array(
                strtolower(trim((string) ($row['private'] ?? '0'))),
                ['1', 'y', 'yes', 'true'],
                true
            )
        );
    }

    public function id(): int { return $this->id; }
    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    public function startsAt(): string { return $this->startsAt; }
    public function endsAt(): string { return $this->endsAt; }
    public function isArchived(): bool { return $this->archived; }
    public function isPrivate(): bool { return $this->private; }

    public function stage(string $now): string
    {
        if ($this->archived) {
            return 'archived';
        }
        if ($now < $this->startsAt) {
            return 'preparation';
        }
        if ($now <= $this->endsAt) {
            return 'running';
        }

        return 'ended';
    }

    public function durationSeconds(?DateTimeZone $timezone = null): int
    {
        $timezone ??= new DateTimeZone('UTC');
        $startsAt = new DateTimeImmutable($this->startsAt, $timezone);
        $endsAt = new DateTimeImmutable($this->endsAt, $timezone);

        return max(1, $endsAt->getTimestamp() - $startsAt->getTimestamp());
    }
}
