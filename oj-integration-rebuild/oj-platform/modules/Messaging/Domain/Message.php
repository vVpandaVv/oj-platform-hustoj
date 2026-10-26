<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Domain;

final class Message
{
    public function __construct(
        private readonly int $id,
        private readonly string $threadKey,
        private readonly int $senderUserId,
        private readonly int $recipientUserId,
        private readonly string $body,
        private readonly string $sentAt,
        private readonly ?string $readAt,
        private readonly ?string $senderDeletedAt,
        private readonly ?string $recipientDeletedAt,
        private readonly ?string $hiddenAt,
        private readonly ?int $hiddenByUserId,
        private readonly ?string $hiddenReason
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['thread_key'] ?? ''),
            (int) ($row['sender_user_id'] ?? 0),
            (int) ($row['recipient_user_id'] ?? 0),
            (string) ($row['body'] ?? ''),
            (string) ($row['sent_at'] ?? ''),
            self::nullableString($row['read_at'] ?? null),
            self::nullableString($row['sender_deleted_at'] ?? null),
            self::nullableString($row['recipient_deleted_at'] ?? null),
            self::nullableString($row['hidden_at'] ?? null),
            isset($row['hidden_by_user_id']) && $row['hidden_by_user_id'] !== null
                ? (int) $row['hidden_by_user_id']
                : null,
            self::nullableString($row['hidden_reason'] ?? null)
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function threadKey(): string
    {
        return $this->threadKey;
    }

    public function senderUserId(): int
    {
        return $this->senderUserId;
    }

    public function recipientUserId(): int
    {
        return $this->recipientUserId;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function sentAt(): string
    {
        return $this->sentAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function isDeletedFor(int $userId): bool
    {
        if ($userId === $this->senderUserId) {
            return $this->senderDeletedAt !== null;
        }

        if ($userId === $this->recipientUserId) {
            return $this->recipientDeletedAt !== null;
        }

        return false;
    }

    public function isHidden(): bool
    {
        return $this->hiddenAt !== null;
    }

    public function hiddenReason(): ?string
    {
        return $this->hiddenReason;
    }

    public function isParticipant(int $userId): bool
    {
        return $userId === $this->senderUserId || $userId === $this->recipientUserId;
    }

    public function otherParticipant(int $viewerUserId): int
    {
        return $viewerUserId === $this->senderUserId
            ? $this->recipientUserId
            : $this->senderUserId;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
