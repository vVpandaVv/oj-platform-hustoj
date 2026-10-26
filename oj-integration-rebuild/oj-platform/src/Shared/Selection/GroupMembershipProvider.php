<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Selection;

interface GroupMembershipProvider
{
    /** @return list<int> */
    public function activeGroupIdsForUser(int $userId): array;

    /**
     * @param list<int> $groupIds
     * @return list<int>
     */
    public function activeUserIdsForGroups(array $groupIds): array;
}
