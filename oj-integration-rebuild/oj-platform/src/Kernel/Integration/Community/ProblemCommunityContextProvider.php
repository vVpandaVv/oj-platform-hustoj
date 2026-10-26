<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Community;

use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContextProvider;
use CodingPanda\OjPlatform\Modules\Board\Contract\RecentCommunityContextProvider;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContext;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use Throwable;

final class ProblemCommunityContextProvider implements CommunityContextProvider, RecentCommunityContextProvider
{
    public function __construct(
        private readonly LearningProblemProvider $problems,
        private readonly ContestService $contests
    ) {
    }

    public function type(): string
    {
        return 'problem';
    }

    public function resolve(int $targetId, int $userId): ?CommunityContextAccess
    {
        $problem = $this->problems->findForUser($userId, $targetId);
        if ($problem === null) {
            return null;
        }
        $context = $this->context($problem);
        if ($this->protectedByRunningContest($targetId, $userId)) {
            return new CommunityContextAccess(
                $context,
                false,
                false,
                false,
                true
            );
        }

        return new CommunityContextAccess($context, true, true, false);
    }

    public function choices(int $userId, int $limit = 200): array
    {
        $items = [];
        foreach ($this->problems->catalogForUser(
            $userId,
            max(1, min(500, $limit))
        ) as $problem) {
            $access = $this->resolve($problem->problemId(), $userId);
            if ($access !== null && ! $access->isProtected()) {
                $items[] = $access;
            }
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * @return list<CommunityContextAccess>
     */
    public function recentChoices(int $userId, int $limit = 5): array
    {
        $items = [];
        foreach ($this->problems->recentForUser(
            $userId,
            max(1, min(20, $limit))
        ) as $problem) {
            $access = $this->resolve($problem->problemId(), $userId);
            if ($access !== null && ! $access->isProtected()) {
                $items[] = $access;
            }
        }

        return array_slice($items, 0, $limit);
    }

    private function protectedByRunningContest(
        int $problemId,
        int $userId
    ): bool {
        try {
            foreach ($this->contests->visibleFor($userId) as $contest) {
                if (! in_array(
                    $this->contests->currentStage($contest),
                    ['preparation', 'running'],
                    true
                )) {
                    continue;
                }
                $settings = $this->contests->settings($contest->id());
                if ($this->contests->canConfigure(
                    $userId,
                    $contest->id(),
                    $settings
                )) {
                    continue;
                }
                if (! in_array(
                    $userId,
                    $this->contests->participantUserIds($contest->id(), 'active'),
                    true
                )) {
                    continue;
                }
                foreach ($this->contests->problems($contest->id()) as $problem) {
                    if ($problem->problemId() === $problemId) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    private function context(LearningProblem $problem): CommunityContext
    {
        return new CommunityContext(
            'problem',
            $problem->problemId(),
            '문제 ' . $problem->problemId() . ' · ' . $problem->title()
        );
    }
}
