<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Contract;

final class HomeNoticeItem
{
    public function __construct(
        private readonly int $noticeId,
        private readonly string $title,
        private readonly string $publishedAt,
        private readonly bool $pinned,
        private readonly bool $important,
        private readonly string $url
    ) {
    }

    public function noticeId(): int { return $this->noticeId; }
    public function title(): string { return $this->title; }
    public function publishedAt(): string { return $this->publishedAt; }
    public function isPinned(): bool { return $this->pinned; }
    public function isImportant(): bool { return $this->important; }
    public function url(): string { return $this->url; }
}
