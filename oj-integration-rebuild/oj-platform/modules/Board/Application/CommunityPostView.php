<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityAttachment;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;

final class CommunityPostView
{
    /**
     * @param list<CommunityCommentView> $comments
     * @param list<CommunityAttachment> $attachments
     */
    public function __construct(
        private readonly CommunityPost $post,
        private readonly string $authorName,
        private readonly array $comments,
        private readonly array $attachments,
        private readonly bool $bodyVisible,
        private readonly bool $canEdit,
        private readonly bool $canComment,
        private readonly bool $canModerate
    ) {
    }

    public function post(): CommunityPost { return $this->post; }
    public function authorName(): string { return $this->authorName; }
    /** @return list<CommunityCommentView> */
    public function comments(): array { return $this->comments; }
    /** @return list<CommunityAttachment> */
    public function attachments(): array { return $this->attachments; }
    public function bodyVisible(): bool { return $this->bodyVisible; }
    public function canEdit(): bool { return $this->canEdit; }
    public function canComment(): bool { return $this->canComment; }
    public function canModerate(): bool { return $this->canModerate; }
}
