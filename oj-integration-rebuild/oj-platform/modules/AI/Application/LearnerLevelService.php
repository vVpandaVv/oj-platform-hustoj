<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\AI\Contract\LearnerLevelRefresher;
use CodingPanda\OjPlatform\Modules\AI\Contract\LearnerLevelRepository;
use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\LearningHistoryEligibility;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DomainException;

final class LearnerLevelService implements LearnerLevelRefresher
{
    public function __construct(
        private readonly LearnerLevelRepository $profiles,
        private readonly WordPressUserGateway $users,
        private readonly SubmissionProjectionProvider $submissions,
        private readonly LearningProblemProvider $problems,
        private readonly LearningHistoryEligibility $historyEligibility,
        private readonly LearnerLevelCalculator $calculator,
        private readonly Clock $clock
    ) {
    }

    public function find(int $userId): ?LearnerLevelProfile
    {
        return $this->profiles->find($userId);
    }

    public function refresh(int $userId): LearnerLevelProfile
    {
        $identity = $this->users->findById($userId);
        if ($identity === null) {
            throw new DomainException('학습 수준을 분석할 사용자를 찾을 수 없습니다.');
        }
        $submissions = [];
        $offset = 0;
        do {
            $page = $this->submissions->report(new SubmissionProjectionQuery(
                [$identity->userLogin()],
                [],
                null,
                null,
                null,
                true,
                1000,
                $offset
            ));
            foreach ($page as $submission) {
                if ($this->historyEligibility->includes(
                    $userId,
                    $submission
                )) {
                    $submissions[] = $submission;
                }
            }
            $offset += count($page);
        } while (count($page) === 1000 && $offset < 100000);

        $problems = [];
        foreach ($this->problems->catalogForUser($userId, 100000) as $problem) {
            $problems[$problem->problemId()] = $problem;
        }
        $missingProblemIds = [];
        foreach ($submissions as $submission) {
            $problemId = $submission->problemId();
            if (! isset($problems[$problemId])) {
                $missingProblemIds[$problemId] = $problemId;
            }
        }
        foreach ($this->problems->findManyForUser(
            $userId,
            array_values($missingProblemIds)
        ) as $problemId => $problem) {
            if ($problem instanceof LearningProblem) {
                $problems[$problemId] = $problem;
            }
        }
        $profile = $this->calculator->calculate(
            $userId,
            $submissions,
            $problems,
            $this->clock->now()->format('Y-m-d H:i:s')
        );
        $this->profiles->save($profile);

        return $profile;
    }

    /**
     * @return list<LearnerLevelProfile>
     */
    public function recent(int $limit = 100): array
    {
        return $this->profiles->recent($limit);
    }
}
