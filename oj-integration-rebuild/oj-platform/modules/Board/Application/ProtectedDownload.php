<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityAttachment;

final class ProtectedDownload
{
    public function __construct(
        private readonly CommunityAttachment $attachment,
        private readonly string $contents
    ) {
    }

    public function attachment(): CommunityAttachment
    {
        return $this->attachment;
    }

    public function contents(): string
    {
        return $this->contents;
    }
}
