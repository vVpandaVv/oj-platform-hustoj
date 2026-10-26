<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;

final class ProblemSubmissionProvider implements SubmissionProblemProvider
{
    public function __construct(
        private readonly ProblemCatalogService $catalog,
        private readonly ProblemCodePolicyService $policies
    ) {
    }

    public function findVisible(
        int $userId,
        int $problemId,
        ?ProblemAccessContext $context = null
    ): ?SubmissionProblem
    {
        $problem = $this->catalog->findVisible(
            $userId,
            new ProblemId($problemId),
            $context
        );

        return $problem === null ? null : $this->summary($problem);
    }

    public function findVisibleMany(
        int $userId,
        array $problemIds,
        ?ProblemAccessContext $context = null
    ): array
    {
        $ids = array_map(
            static fn (int $problemId): ProblemId => new ProblemId($problemId),
            array_values(array_unique(array_filter(
                $problemIds,
                static fn (mixed $problemId): bool =>
                    is_int($problemId) && $problemId > 0
            )))
        );
        $visible = [];
        foreach ($this->catalog->findVisibleMany($userId, $ids, $context) as $problem) {
            $visible[$problem->id()->value()] = $this->summary($problem);
        }

        return $visible;
    }
    public function listVisible(string $keyword = '', int $limit = 100): array
    {
        return array_map(
            fn (JudgeProblem $problem): SubmissionProblem => $this->summary($problem),
            $this->catalog->publicPage($keyword, max(1, min(100, $limit)), 0)
        );
    }

    public function codePolicy(
        int $userId,
        int $problemId,
        ?ProblemAccessContext $context = null
    ): SubmissionCodePolicy {
        $problem = $this->catalog->findVisible(
            $userId,
            new ProblemId($problemId),
            $context
        );
        if ($problem === null) {
            throw new DomainException('제출할 수 있는 문제를 찾을 수 없습니다.');
        }
        $policy = $this->policies->forProblem($problem->id());

        return new SubmissionCodePolicy(
            $problem->id()->value(),
            $problem->title(),
            $policy->entryMode()->value,
            $policy->forbiddenFragments(),
            $policy->prefixCode(),
            $policy->starterCode(),
            $policy->suffixCode(),
            $policy->languageMask(),
            $policy->completionSchema(),
            $policy->completionHash(),
            $policy->policyVersion(),
            $policy->orderingSchema(),
            $policy->orderingHash()
        );
    }

    private function summary(JudgeProblem $problem): SubmissionProblem
    {
        return new SubmissionProblem(
            $problem->id()->value(),
            $problem->title()
        );
    }
}
