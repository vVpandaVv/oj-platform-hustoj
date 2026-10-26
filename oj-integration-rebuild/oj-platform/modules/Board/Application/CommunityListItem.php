<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;

final class CommunityListItem
{
    public function __construct(
        private readonly CommunityPost $post,
        private readonly string $authorName,
        private readonly int $commentCount,
        private readonly bool $orphaned
    ) {
    }

    public function post(): CommunityPost { return $this->post; }
    public function authorName(): string { return $this->authorName; }
    public function commentCount(): int { return $this->commentCount; }
    public function isAnswered(): bool { return $this->commentCount > 0; }
    public function isOrphaned(): bool { return $this->orphaned; }
}
