<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Contract;

interface LegacyUserResolver
{
    public function resolve(
        ?int $wordpressUserId,
        ?string $loginId,
        bool $allowMissing = false
    ): ?int;
}
