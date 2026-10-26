<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Domain;

final class MessageReport
{
    public function __construct(
        private readonly int $id,
        private readonly int $messageId,
        private readonly int $reporterUserId,
        private readonly string $reason,
        private readonly string $status,
        private readonly string $reportedAt,
        private readonly ?int $resolvedByUserId,
        private readonly ?string $resolvedAt,
        private readonly ?string $resolutionAction,
        private readonly ?string $resolutionNote
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['message_id'] ?? 0),
            (int) ($row['reporter_user_id'] ?? 0),
            (string) ($row['reason'] ?? ''),
            (string) ($row['status'] ?? 'pending'),
            (string) ($row['reported_at'] ?? ''),
            isset($row['resolved_by_user_id']) && $row['resolved_by_user_id'] !== null
                ? (int) $row['resolved_by_user_id']
                : null,
            self::nullableString($row['resolved_at'] ?? null),
            self::nullableString($row['resolution_action'] ?? null),
            self::nullableString($row['resolution_note'] ?? null)
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function messageId(): int
    {
        return $this->messageId;
    }

    public function reporterUserId(): int
    {
        return $this->reporterUserId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function reportedAt(): string
    {
        return $this->reportedAt;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
