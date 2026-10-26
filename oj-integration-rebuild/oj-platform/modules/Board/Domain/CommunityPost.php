<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Domain;

use InvalidArgumentException;

final class CommunityPost
{
    public function __construct(
        private readonly int $id,
        private readonly string $kind,
        private readonly int $authorUserId,
        private readonly string $title,
        private readonly string $body,
        private readonly string $status,
        private readonly CommunityContext $context,
        private readonly bool $pinned,
        private readonly bool $important,
        private readonly bool $hidden,
        private readonly bool $secret,
        private readonly bool $commentsAllowed,
        private readonly ?string $publishAt,
        private readonly ?string $endsAt,
        private readonly string $createdAt,
        private readonly string $updatedAt
    ) {
        if ($this->id < 1 || $this->authorUserId < 0) {
            throw new InvalidArgumentException('게시글 식별자가 올바르지 않습니다.');
        }
        if (! in_array($this->kind, ['board', 'notice'], true)) {
            throw new InvalidArgumentException('게시글 종류가 올바르지 않습니다.');
        }
        if (trim($this->title) === '' || trim($this->body) === '') {
            throw new InvalidArgumentException('게시글 제목과 본문이 필요합니다.');
        }
    }

    public function id(): int { return $this->id; }
    public function kind(): string { return $this->kind; }
    public function authorUserId(): int { return $this->authorUserId; }
    public function title(): string { return $this->title; }
    public function body(): string { return $this->body; }
    public function status(): string { return $this->status; }
    public function context(): CommunityContext { return $this->context; }
    public function isPinned(): bool { return $this->pinned; }
    public function isImportant(): bool { return $this->important; }
    public function isHidden(): bool { return $this->hidden; }
    public function isSecret(): bool { return $this->secret; }
    public function commentsAllowed(): bool { return $this->commentsAllowed; }
    public function publishAt(): ?string { return $this->publishAt; }
    public function endsAt(): ?string { return $this->endsAt; }
    public function createdAt(): string { return $this->createdAt; }
    public function updatedAt(): string { return $this->updatedAt; }
    public function isTrashed(): bool { return $this->status === 'trash'; }
}
