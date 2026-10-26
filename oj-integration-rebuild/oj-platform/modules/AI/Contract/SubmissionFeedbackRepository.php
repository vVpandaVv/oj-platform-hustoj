<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Contract;

use CodingPanda\OjPlatform\Modules\AI\Domain\SubmissionFeedback;

interface SubmissionFeedbackRepository
{
    public function find(int $submissionId): ?SubmissionFeedback;

    public function save(SubmissionFeedback $feedback): void;

    /**
     * @return list<SubmissionFeedback>
     */
    public function recent(int $limit = 100): array;
}
