<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttempt;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizOverview;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizResultPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Clock\SystemClock;
use DateTimeImmutable;
use DomainException;

final class QuizService
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly ProblemCatalogService $catalog,
        private readonly QuizAccessPolicy $access,
        private readonly QuizGrader $grader,
        private readonly Clock $clock = new SystemClock()
    ) {
    }

    /**
     * @return list<array{problem:JudgeProblem, quiz:QuizOverview}>
     */
    public function visibleOverviews(int $userId, ?array $overviews = null): array
    {
        $overviews ??= $this->quizzes->overviews();
        $problems = $this->catalog->findVisibleMany($userId, array_map(static fn (QuizOverview $overview): ProblemId => $overview->problemId(), $overviews));
        $items = [];
        foreach ($overviews as $overview) {
            $published = $overview->published();
            if ($published === null || ! $published->definition()->isEnabled()) {
                continue;
            }
            $problem = $problems[$overview->problemId()->value()] ?? null;
            if ($problem !== null) {
                $items[] = ['problem' => $problem, 'quiz' => $overview];
            }
        }

        return $items;
    }

    /**
     * @return list<array{problem:JudgeProblem, quiz:QuizOverview}>
     */
    public function managedOverviews(int $userId, ?array $overviews = null): array
    {
        $overviews ??= $this->quizzes->overviews();
        $problems = $this->catalog->findManagedMany($userId, array_map(static fn (QuizOverview $overview): ProblemId => $overview->problemId(), $overviews));
        $items = [];
        foreach ($overviews as $overview) {
            if (! $this->access->canManage($userId, $overview->problemId())) {
                continue;
            }
            $problem = $problems[$overview->problemId()->value()] ?? null;
            if ($problem !== null) {
                $items[] = ['problem' => $problem, 'quiz' => $overview];
            }
        }

        return $items;
    }

    /** @return array{items:array, has_next:bool} */
    public function overviewPage(int $userId, int $page, bool $managed = false): array
    {
        $offset = (max(1, $page) - 1) * 50;
        $rows = $this->quizzes instanceof \CodingPanda\OjPlatform\Modules\Problems\Contract\QuizOverviewPageRepository
            ? $this->quizzes->overviewPage(51, $offset) : array_slice($this->quizzes->overviews(), $offset, 51);
        $slice = array_slice($rows, 0, 50);
        return ['items' => $managed ? $this->managedOverviews($userId, $slice) : $this->visibleOverviews($userId, $slice), 'has_next' => count($rows) > 50];
    }

    /**
     * @return list<JudgeProblem>
     */
    public function availableProblems(int $userId, string $keyword = ''): array
    {
        $problems = $this->catalog->managedPage(
            $userId,
            $keyword,
            null,
            100,
            0
        );

        return array_values(array_filter(
            $problems,
            fn (JudgeProblem $problem): bool =>
                $this->access->canManage($userId, $problem->id())
        ));
    }

    /**
     * @return array{
     *     problem:JudgeProblem,
     *     revision:QuizRevision,
     *     attempts:list<QuizAttempt>,
     *     active:?QuizAttempt
     * }
     */
    public function visible(
        int $userId,
        ProblemId $problemId,
        ?ProblemAccessContext $context = null
    ): array {
        $problem = $this->catalog->findVisible($userId, $problemId, $context);
        $revision = $this->quizzes->published($problemId);
        if (
            $problem === null
            || $revision === null
            || ! $revision->definition()->isEnabled()
        ) {
            throw new DomainException('퀴즈를 열 수 없습니다.');
        }

        return [
            'problem' => $problem,
            'revision' => $revision,
            'attempts' => $this->quizzes->attemptsForUser($problemId, $userId),
            'active' => $this->quizzes->activeAttempt($problemId, $userId),
        ];
    }

    /**
     * @return array{
     *     problem:JudgeProblem,
     *     draft:?QuizRevision,
     *     published:?QuizRevision,
     *     history:list<QuizRevision>
     * }
     */
    public function managed(int $userId, ProblemId $problemId): array
    {
        $this->assertManage($userId, $problemId);
        $problem = $this->catalog->findManaged($userId, $problemId);
        if ($problem === null) {
            throw new DomainException('퀴즈를 관리할 문제를 찾을 수 없습니다.');
        }

        return [
            'problem' => $problem,
            'draft' => $this->quizzes->draft($problemId),
            'published' => $this->quizzes->published($problemId),
            'history' => $this->quizzes->history($problemId),
        ];
    }

    public function saveDraft(
        int $userId,
        ProblemId $problemId,
        QuizDefinition $definition
    ): QuizRevision {
        $this->assertManage($userId, $problemId);

        return $this->quizzes->saveDraft($problemId, $definition, $userId);
    }

    public function createDraft(
        int $userId,
        ProblemId $problemId,
        QuizDefinition $definition
    ): QuizRevision {
        $this->assertManage($userId, $problemId);
        if (
            $this->quizzes->draft($problemId) !== null
            || $this->quizzes->published($problemId) !== null
        ) {
            throw new DomainException('이 문제에는 이미 퀴즈가 있습니다.');
        }

        return $this->quizzes->saveDraft($problemId, $definition, $userId);
    }

    public function publish(
        int $userId,
        ProblemId $problemId,
        QuizDefinition $definition
    ): QuizRevision {
        $this->assertManage($userId, $problemId);

        return $this->quizzes->publishDraft(
            $problemId,
            $definition,
            $userId
        );
    }

    public function start(
        int $userId,
        ProblemId $problemId,
        string $attemptKey,
        ?ProblemAccessContext $context = null
    ): QuizAttempt {
        if (preg_match('/^[a-f0-9]{64}$/', $attemptKey) !== 1) {
            throw new DomainException('퀴즈 시작 확인 키가 올바르지 않습니다.');
        }
        $visible = $this->visible($userId, $problemId, $context);
        $active = $visible['active'];
        if ($active instanceof QuizAttempt) {
            return $active;
        }

        return $this->quizzes->startAttempt(
            $visible['revision'],
            $userId,
            $attemptKey
        );
    }

    /**
     * @return array{attempt:QuizAttempt, revision:QuizRevision, problem:JudgeProblem}
     */
    public function attempt(
        int $userId,
        ProblemId $problemId,
        ?int $attemptId = null,
        ?ProblemAccessContext $context = null
    ): array {
        $attempt = $attemptId === null
            ? $this->quizzes->activeAttempt($problemId, $userId)
            : $this->quizzes->attempt($attemptId);
        if (
            $attempt === null
            || ! $attempt->problemId()->equals($problemId)
            || ! $this->access->canViewAttempt($userId, $attempt)
        ) {
            throw new DomainException('퀴즈 응시 기록을 열 수 없습니다.');
        }
        $revision = $this->quizzes->revision($attempt->revisionId());
        $problem = $this->catalog->findVisible($userId, $problemId, $context);
        if ($problem === null && ($context === null || $context->isGeneral())) {
            $problem = $this->catalog->findManaged($userId, $problemId);
        }
        if ($revision === null || $problem === null) {
            throw new DomainException('퀴즈 응시 버전을 찾을 수 없습니다.');
        }

        return [
            'attempt' => $attempt,
            'revision' => $revision,
            'problem' => $problem,
        ];
    }

    /**
     * @param array<int, int> $answers
     */
    public function submit(
        int $userId,
        ProblemId $problemId,
        int $attemptId,
        array $answers,
        ?ProblemAccessContext $context = null
    ): QuizAttempt {
        $attemptContext = $this->attempt($userId, $problemId, $attemptId, $context);
        $attempt = $attemptContext['attempt'];
        if ($attempt->userId() !== $userId) {
            throw new DomainException('본인의 퀴즈만 제출할 수 있습니다.');
        }
        if ($attempt->status() === 'graded') {
            return $attempt;
        }
        if ($attempt->status() !== 'in_progress') {
            throw new DomainException('현재 제출할 수 없는 퀴즈 응시입니다.');
        }
        $result = $this->grader->grade($attemptContext['revision'], $answers);

        return $this->quizzes->finalizeAttempt(
            $attempt->id(),
            $userId,
            $result->items(),
            $result->score(),
            $result->maxScore()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function statistics(
        int $userId,
        ProblemId $problemId,
        ?int $revisionId = null,
        ?ProblemAccessContext $context = null
    ): array {
        $problem = $this->catalog->findVisible($userId, $problemId, $context);
        if ($problem === null && ($context === null || $context->isGeneral())) {
            $problem = $this->catalog->findManaged($userId, $problemId);
        }
        $revision = $revisionId === null
            ? $this->quizzes->published($problemId)
            : $this->quizzes->revision($revisionId);
        if (
            $problem === null
            || $revision === null
            || ! $revision->problemId()->equals($problemId)
            || ! $this->access->canViewStatistics($userId, $revision)
        ) {
            throw new DomainException('퀴즈 통계를 열 수 없습니다.');
        }

        $statistics = $this->quizzes->statistics(
            $problemId,
            $revision->id()
        );
        $managed = $this->access->canManage($userId, $problemId);
        if (! $managed) {
            $statistics['attempt_rows'] = array_values(array_filter(
                $statistics['attempt_rows'],
                static fn (array $row): bool =>
                    (int) ($row['user_id'] ?? 0) === $userId
            ));
        }

        return [
            'problem' => $problem,
            'revision' => $revision,
            'history' => $this->quizzes->history($problemId),
            'statistics' => $statistics,
            'managed' => $managed,
        ];
    }

    public function answersVisible(
        QuizRevision $revision,
        ?DateTimeImmutable $now = null
    ): bool {
        $policy = $revision->definition()->resultPolicy();
        if ($policy === QuizResultPolicy::Full) {
            return true;
        }
        if ($policy === QuizResultPolicy::ScoreOnly) {
            return false;
        }
        $availableAt = $revision->definition()->resultsAvailableAt();
        if ($availableAt === null) {
            return false;
        }
        $now ??= $this->clock->now();

        return $now >= new DateTimeImmutable($availableAt, $now->getTimezone());
    }

    public function canRegradeCompleted(int $userId): bool
    {
        return $this->access->canRegradeCompleted($userId);
    }

    private function assertManage(int $userId, ProblemId $problemId): void
    {
        if (! $this->access->canManage($userId, $problemId)) {
            throw new DomainException('이 문제의 퀴즈를 관리할 권한이 없습니다.');
        }
    }
}
