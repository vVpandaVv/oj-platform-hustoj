<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Community;

use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContextProvider;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContext;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use Throwable;

final class GroupCommunityContextProvider implements CommunityContextProvider
{
    public function __construct(private readonly GroupService $groups)
    {
    }

    public function type(): string
    {
        return 'group';
    }

    public function resolve(int $targetId, int $userId): ?CommunityContextAccess
    {
        try {
            $group = $this->groups->findVisible($targetId, $userId);
            $member = in_array(
                $userId,
                $this->groups->memberUserIds($targetId, 'active'),
                true
            );
            $manager = in_array(
                $userId,
                $this->groups->managerUserIds($targetId),
                true
            ) || $group->ownerUserId() === $userId;
            if (
                (! $member && ! $manager)
                || ! $group->isEnabled()
                || $group->isDeleted()
            ) {
                return null;
            }

            return new CommunityContextAccess(
                new CommunityContext('group', $targetId, '그룹 · ' . $group->name()),
                true,
                true,
                $manager
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function choices(int $userId, int $limit = 200): array
    {
        $items = [];
        foreach ($this->groups->visibleFor($userId) as $group) {
            $access = $this->resolve($group->id(), $userId);
            if ($access !== null) {
                $items[] = $access;
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }
}
