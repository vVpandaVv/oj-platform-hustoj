<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Domain;

final class CommunityContextAccess
{
    public function __construct(
        private readonly CommunityContext $context,
        private readonly bool $canView,
        private readonly bool $canPost,
        private readonly bool $canModerate,
        private readonly bool $protected = false,
        private readonly bool $orphaned = false
    ) {
    }

    public function context(): CommunityContext
    {
        return $this->context;
    }

    public function canView(): bool
    {
        return $this->canView;
    }

    public function canPost(): bool
    {
        return $this->canPost;
    }

    public function canModerate(): bool
    {
        return $this->canModerate;
    }

    public function isProtected(): bool
    {
        return $this->protected;
    }

    public function isOrphaned(): bool
    {
        return $this->orphaned;
    }
}
