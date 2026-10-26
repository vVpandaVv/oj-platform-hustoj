<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttempt;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttemptItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizOverview;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;

interface QuizRepository
{
    /**
     * @return list<QuizOverview>
     */
    public function overviews(): array;

    public function draft(ProblemId $problemId): ?QuizRevision;

    public function published(ProblemId $problemId): ?QuizRevision;

    public function revision(int $revisionId): ?QuizRevision;

    /**
     * @return list<QuizRevision>
     */
    public function history(ProblemId $problemId): array;

    public function saveDraft(
        ProblemId $problemId,
        QuizDefinition $definition,
        int $actorUserId
    ): QuizRevision;

    public function publishDraft(
        ProblemId $problemId,
        QuizDefinition $definition,
        int $actorUserId
    ): QuizRevision;

    public function startAttempt(
        QuizRevision $revision,
        int $userId,
        string $attemptKey
    ): QuizAttempt;

    public function activeAttempt(
        ProblemId $problemId,
        int $userId
    ): ?QuizAttempt;

    public function attempt(int $attemptId): ?QuizAttempt;

    /**
     * @return list<QuizAttempt>
     */
    public function attemptsForUser(ProblemId $problemId, int $userId): array;

    /**
     * @param list<QuizAttemptItem> $items
     */
    public function finalizeAttempt(
        int $attemptId,
        int $userId,
        array $items,
        float $score,
        float $maxScore
    ): QuizAttempt;

    /**
     * @return array{
     *     attempt_count:int,
     *     average_score:float,
     *     question_rows:list<array<string, mixed>>,
     *     attempt_rows:list<array<string, mixed>>
     * }
     */
    public function statistics(
        ProblemId $problemId,
        int $revisionId
    ): array;
}
