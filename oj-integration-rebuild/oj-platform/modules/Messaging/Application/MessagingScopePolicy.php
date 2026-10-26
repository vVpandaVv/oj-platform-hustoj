<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Application;

use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessagingScope;
use CodingPanda\OjPlatform\Shared\Selection\GroupMembershipProvider;

final class MessagingScopePolicy
{
    public function __construct(
        private readonly MessagingSettingsService $settings,
        private readonly GroupMembershipProvider $groupMembership
    ) {
    }

    public function canMessage(int $senderUserId, int $recipientUserId): bool
    {
        if ($this->settings->current()->scope() !== MessagingScope::GroupOnly) {
            return true;
        }

        $senderGroups = $this->groupMembership->activeGroupIdsForUser($senderUserId);
        if ($senderGroups === []) {
            return false;
        }

        $recipientGroups = $this->groupMembership->activeGroupIdsForUser($recipientUserId);

        return array_intersect($senderGroups, $recipientGroups) !== [];
    }
}
