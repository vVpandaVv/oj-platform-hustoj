<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Contract;

use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess;

interface RecentCommunityContextProvider
{
    /**
     * @return list<CommunityContextAccess>
     */
    public function recentChoices(int $userId, int $limit = 5): array;
}
