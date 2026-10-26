<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Contract;

use CodingPanda\OjPlatform\Modules\Messaging\Domain\Message;

interface MessageInboxStatus
{
    /** @return list<Message> */
    public function unreadInboxThreads(int $userId, int $limit, int $offset): array;
    /** @param list<string> $threadKeys @return list<string> */
    public function unreadThreadKeys(int $userId, array $threadKeys): array;
}
