<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Domain;

use InvalidArgumentException;

final class CommunityComment
{
    public function __construct(
        private readonly int $id,
        private readonly int $postId,
        private readonly int $authorUserId,
        private readonly string $body,
        private readonly string $status,
        private readonly bool $hidden,
        private readonly string $createdAt
    ) {
        if ($this->id < 1 || $this->postId < 1 || $this->authorUserId < 0) {
            throw new InvalidArgumentException('댓글 식별자가 올바르지 않습니다.');
        }
    }

    public function id(): int { return $this->id; }
    public function postId(): int { return $this->postId; }
    public function authorUserId(): int { return $this->authorUserId; }
    public function body(): string { return $this->body; }
    public function status(): string { return $this->status; }
    public function isHidden(): bool { return $this->hidden; }
    public function createdAt(): string { return $this->createdAt; }
    public function isTrashed(): bool { return $this->status === 'trash'; }
}
