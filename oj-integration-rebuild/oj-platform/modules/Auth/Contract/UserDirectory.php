<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface UserDirectory
{
    /**
     * @return list<ManagedUser>
     */
    public function page(
        ?AccessStatus $status,
        int $limit = 50,
        int $offset = 0,
        string $keyword = ''
    ): array;

    public function count(?AccessStatus $status, string $keyword = ''): int;

    public function find(int $userId): ?ManagedUser;
}
