<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Contract;

interface MessageNotificationProvider
{
    public function isEnabled(): bool;
    public function unreadCount(int $userId): int;
}
