<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttempt;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttemptItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizReapplyMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;

interface QuizReapplyRepository
{
    /**
     * @return list<QuizAttempt>
     */
    public function candidates(
        ProblemId $problemId,
        int $sourceRevisionId,
        QuizReapplyMode $mode
    ): array;

    public function replaceInProgress(
        QuizAttempt $original,
        QuizRevision $target,
        string $attemptKey
    ): QuizAttempt;

    /**
     * @param list<QuizAttemptItem> $items
     */
    public function createRegrade(
        QuizAttempt $original,
        QuizRevision $target,
        string $attemptKey,
        array $items,
        float $score,
        float $maxScore
    ): QuizAttempt;
}
