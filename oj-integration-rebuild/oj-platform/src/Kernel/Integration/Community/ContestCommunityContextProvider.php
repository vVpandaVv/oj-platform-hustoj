<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Community;

use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContextProvider;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContext;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use Throwable;

final class ContestCommunityContextProvider implements CommunityContextProvider
{
    public function __construct(private readonly ContestService $contests)
    {
    }

    public function type(): string
    {
        return 'contest';
    }

    public function resolve(int $targetId, int $userId): ?CommunityContextAccess
    {
        try {
            $contest = $this->contests->findVisible($targetId, $userId);
            $settings = $this->contests->settings($targetId);
            $manager = $this->contests->canConfigure(
                $userId,
                $targetId,
                $settings
            );
            $participant = in_array(
                $userId,
                $this->contests->participantUserIds($targetId, 'active'),
                true
            );
            if (! $manager && ! $participant) {
                return null;
            }
            $stage = $this->contests->currentStage($contest);
            $context = new CommunityContext(
                'contest',
                $targetId,
                '대회·평가 · ' . $contest->title()
            );
            if (
                ! $manager
                && in_array($stage, ['preparation', 'running'], true)
            ) {
                return new CommunityContextAccess(
                    $context,
                    false,
                    false,
                    false,
                    true
                );
            }

            return new CommunityContextAccess(
                $context,
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
        foreach ($this->contests->visibleFor($userId) as $contest) {
            $access = $this->resolve($contest->id(), $userId);
            if ($access !== null && ! $access->isProtected()) {
                $items[] = $access;
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }
}
