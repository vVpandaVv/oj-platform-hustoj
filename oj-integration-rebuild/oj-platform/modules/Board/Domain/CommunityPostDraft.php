<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Domain;

use InvalidArgumentException;

final class CommunityPostDraft
{
    public function __construct(
        private readonly string $kind,
        private readonly int $authorUserId,
        private readonly string $title,
        private readonly string $body,
        private readonly string $status,
        private readonly CommunityContext $context,
        private readonly bool $pinned,
        private readonly bool $important,
        private readonly bool $commentsAllowed,
        private readonly ?string $publishAt = null,
        private readonly ?string $endsAt = null,
        private readonly bool $secret = false
    ) {
        $titleLength = function_exists('mb_strlen')
            ? mb_strlen(trim($this->title), 'UTF-8')
            : strlen(trim($this->title));
        $bodyLength = function_exists('mb_strlen')
            ? mb_strlen(trim($this->body), 'UTF-8')
            : strlen(trim($this->body));
        if (! in_array($this->kind, ['board', 'notice'], true)) {
            throw new InvalidArgumentException('게시글 종류가 올바르지 않습니다.');
        }
        if ($this->authorUserId < 1 || $titleLength < 1 || $titleLength > 120) {
            throw new InvalidArgumentException('제목은 1자 이상 120자 이하로 입력해 주세요.');
        }
        if ($bodyLength < 1 || $bodyLength > 20000) {
            throw new InvalidArgumentException('본문은 1자 이상 20,000자 이하로 입력해 주세요.');
        }
        if (! in_array($this->status, ['draft', 'future', 'publish'], true)) {
            throw new InvalidArgumentException('게시 상태가 올바르지 않습니다.');
        }
        if ($this->kind === 'board' && $this->status !== 'publish') {
            throw new InvalidArgumentException('게시판 글은 게시 상태로 저장해야 합니다.');
        }
        if ($this->kind === 'notice' && $this->context->type() !== 'global') {
            throw new InvalidArgumentException('공지는 전체 문맥에만 게시할 수 있습니다.');
        }
        if ($this->kind === 'notice' && $this->secret) {
            throw new InvalidArgumentException('공지는 비밀글로 등록할 수 없습니다.');
        }
    }

    public function kind(): string { return $this->kind; }
    public function authorUserId(): int { return $this->authorUserId; }
    public function title(): string { return trim($this->title); }
    public function body(): string { return trim($this->body); }
    public function status(): string { return $this->status; }
    public function context(): CommunityContext { return $this->context; }
    public function isPinned(): bool { return $this->pinned; }
    public function isImportant(): bool { return $this->important; }
    public function commentsAllowed(): bool { return $this->commentsAllowed; }
    public function publishAt(): ?string { return $this->publishAt; }
    public function endsAt(): ?string { return $this->endsAt; }
    public function isSecret(): bool { return $this->secret; }
}
