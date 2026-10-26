<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Contract;

interface FeedbackReviewRepository
{
    /** @return list<array{feedback: \CodingPanda\OjPlatform\Modules\AI\Domain\SubmissionFeedback, class_id: ?int}> */
    public function reviewBatch(int $beforeId, ?int $classId, int $limit = 100): array;
}
