<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface PublicUserSearch
{
    /** @return list<int> Searches only public login and display name. */
    public function search(string $keyword, int $limit, int $offset): array;
}
