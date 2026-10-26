<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Shared\Selection\GroupMembershipProvider;

final class LearningGroupChoiceBridge implements
    ChoiceProvider,
    GroupMembershipProvider
{
    private ?ChoiceProvider $provider = null;

    public function __construct(private readonly FeatureFlags $features)
    {
    }

    public function attach(ChoiceProvider $provider): void
    {
        $this->provider = $provider;
    }

    public function isAvailable(): bool
    {
        return $this->features->isEnabled('groups')
            && $this->provider !== null;
    }

    public function choicesFor(int $userId, array $includedIds = []): array
    {
        return $this->isAvailable()
            ? $this->provider->choicesFor($userId, $includedIds)
            : [];
    }

    public function activeGroupIdsForUser(int $userId): array
    {
        if (
            ! $this->isAvailable()
            || ! ($this->provider instanceof GroupMembershipProvider)
        ) {
            return [];
        }

        return $this->provider->activeGroupIdsForUser($userId);
    }

    public function activeUserIdsForGroups(array $groupIds): array
    {
        if (
            ! $this->isAvailable()
            || ! ($this->provider instanceof GroupMembershipProvider)
        ) {
            return [];
        }

        return $this->provider->activeUserIdsForGroups($groupIds);
    }
}
