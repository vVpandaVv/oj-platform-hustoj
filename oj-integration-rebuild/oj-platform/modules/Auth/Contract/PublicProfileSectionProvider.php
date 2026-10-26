<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileSection;

interface PublicProfileSectionProvider
{
    public function key(): string;

    public function priority(): int;

    public function provide(
        int $viewerUserId,
        int $targetUserId,
        string $targetUserLogin,
        int $limit
    ): ?PublicProfileSection;
}
