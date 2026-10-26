<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityAttachment;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityComment;

final class CommunityCommentView
{
    /**
     * @param list<CommunityAttachment> $attachments
     */
    public function __construct(
        private readonly CommunityComment $comment,
        private readonly string $authorName,
        private readonly array $attachments
    ) {
    }

    public function comment(): CommunityComment { return $this->comment; }
    public function authorName(): string { return $this->authorName; }
    /** @return list<CommunityAttachment> */
    public function attachments(): array { return $this->attachments; }
}
