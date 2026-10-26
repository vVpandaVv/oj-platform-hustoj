<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Contract;

interface HomeNoticeProvider
{
    /**
     * @return list<HomeNoticeItem>
     */
    public function current(int $userId, int $limit = 5): array;
}
