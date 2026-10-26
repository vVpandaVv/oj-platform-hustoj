<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessContext;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessRule;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

final class ProblemJobAccessRule implements JobAccessRule
{
    public function __construct(
        private readonly Authorization $permissions,
        private readonly ProblemAccessPolicy $problems,
        private readonly QuizAccessPolicy $quizzes
    ) {
    }

    public function policyKeys(): array
    {
        return [
            'problems.metadata.generate',
            'problems.authoring.generate',
            'problems.testcases.generate',
            'problems.test_data',
            'problems.change_id',
            'problems.quiz_revision_reapply',
        ];
    }

    public function policyVersion(): string
    {
        return '1';
    }

    public function canView(int $userId, JobAccessContext $job): bool
    {
        return $this->isAllowed($userId, $job);
    }

    public function canControl(int $userId, JobAccessContext $job): bool
    {
        return $this->isAllowed($userId, $job);
    }

    private function isAllowed(int $userId, JobAccessContext $job): bool
    {
        return match ($job->accessPolicyKey()) {
            'problems.metadata.generate' => $this->canUseAiForProblems(
                $userId,
                $this->problemIds($job)
            ),
            'problems.authoring.generate' => $this->canUseAuthoringAi(
                $userId,
                $job
            ),
            'problems.testcases.generate' => $this->canUseTestcaseAi(
                $userId,
                $this->problemId($job)
            ),
            'problems.test_data' => $this->canManageTestData(
                $userId,
                $this->problemId($job)
            ),
            'problems.change_id' => $this->problems->canChangeIds($userId),
            'problems.quiz_revision_reapply' => $this->canReapplyQuiz(
                $userId,
                $job
            ),
            default => false,
        };
    }

    /**
     * @param list<ProblemId> $problemIds
     */
    private function canUseAiForProblems(int $userId, array $problemIds): bool
    {
        if (
            $problemIds === []
            || ! $this->permissions->can(
                $userId,
                'oj_manage_problem_ai_assist'
            )
        ) {
            return false;
        }
        foreach ($problemIds as $problemId) {
            if (! $this->problems->canEdit($userId, $problemId)) {
                return false;
            }
        }

        return true;
    }

    private function canUseAuthoringAi(
        int $userId,
        JobAccessContext $job
    ): bool {
        if (! $this->permissions->can(
            $userId,
            'oj_manage_problem_ai_assist'
        )) {
            return false;
        }
        $summary = $job->targetSummary();
        if (! array_key_exists('problem_id', $summary)) {
            return false;
        }
        if ($summary['problem_id'] === null) {
            return $this->problems->canCreate($userId);
        }
        $problemId = $this->problemId($job);

        return $problemId !== null
            && $this->problems->canEdit($userId, $problemId);
    }

    private function canUseTestcaseAi(
        int $userId,
        ?ProblemId $problemId
    ): bool {
        return $problemId !== null
            && $this->permissions->can(
                $userId,
                'oj_manage_problem_ai_assist'
            )
            && $this->problems->canManageTestData($userId, $problemId);
    }

    private function canManageTestData(
        int $userId,
        ?ProblemId $problemId
    ): bool {
        return $problemId !== null
            && $this->problems->canManageTestData($userId, $problemId);
    }

    private function canReapplyQuiz(
        int $userId,
        JobAccessContext $job
    ): bool
    {
        $problemId = $this->problemId($job);
        if (
            $problemId === null
            || ! $this->quizzes->canManage($userId, $problemId)
        ) {
            return false;
        }
        $summary = $job->targetSummary();
        if (! is_bool($summary['include_graded'] ?? null)) {
            return false;
        }

        return ! $summary['include_graded']
            || $this->quizzes->canRegradeCompleted($userId);
    }

    /**
     * @return list<ProblemId>
     */
    private function problemIds(JobAccessContext $job): array
    {
        $values = $job->targetSummary()['problem_ids'] ?? null;
        if (! is_array($values)) {
            return [];
        }
        $problemIds = [];
        foreach ($values as $value) {
            if (
                ! is_int($value)
                && ! (is_string($value) && ctype_digit($value))
            ) {
                return [];
            }
            $id = (int) $value;
            if ($id < 1) {
                return [];
            }
            $problemIds[] = new ProblemId($id);
        }

        return $problemIds;
    }

    private function problemId(JobAccessContext $job): ?ProblemId
    {
        $value = $job->targetSummary()['problem_id'] ?? null;
        if (
            ! is_int($value)
            && ! (is_string($value) && ctype_digit($value))
        ) {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? new ProblemId($id) : null;
    }
}
