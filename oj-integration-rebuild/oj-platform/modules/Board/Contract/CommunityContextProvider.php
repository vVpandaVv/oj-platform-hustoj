<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Contract;

use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess;

interface CommunityContextProvider
{
    public function type(): string;

    public function resolve(int $targetId, int $userId): ?CommunityContextAccess;

    /**
     * @return list<CommunityContextAccess>
     */
    public function choices(int $userId, int $limit = 200): array;
}
