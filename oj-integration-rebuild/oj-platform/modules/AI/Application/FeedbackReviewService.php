<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackReviewRepository;
use CodingPanda\OjPlatform\Modules\AI\Contract\SubmissionFeedbackRepository;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionReviewProvider;
use DomainException;

final class FeedbackReviewService
{
    public function __construct(
        private readonly FeedbackReviewRepository $reviews,
        private readonly SubmissionFeedbackRepository $feedback,
        private readonly SubmissionReviewProvider $submissions,
        private readonly Authorization $permissions
    ) {
    }

    public function canReview(int $actor): bool
    {
        return $this->permissions->can($actor, 'oj_manage_ai_feedback');
    }

    public function find(int $actor, string $login, int $submissionId): ?array
    {
        if (! $this->canReview($actor)) {
            return null;
        }
        $submission = $this->submissions->findForReview($actor, $login, $submissionId);
        if ($submission === null) {
            return null;
        }
        $feedback = $this->feedback->find($submissionId);

        return $feedback === null ? null : ['feedback' => $feedback, 'submission' => $submission];
    }

    /** @return array{rows: list<array>, next: int} */
    public function page(int $actor, string $login, string $user, ?int $classId, ?int $problemId, int $before): array
    {
        if (! $this->canReview($actor)) {
            throw new DomainException('AI 피드백 관리 권한이 필요합니다.');
        }
        $rows = [];
        $cursor = max(0, $before);
        do {
            $batch = $this->reviews->reviewBatch($cursor, $classId);
            $visible = $this->submissions->findManyForReview($actor, $login, array_map(
                static fn (array $record): int => $record['feedback']->submissionId(), $batch
            ));
            foreach ($batch as $record) {
                $id = $record['feedback']->submissionId();
                $cursor = $id;
                $submission = $visible[$id] ?? null;
                if ($submission === null) {
                    continue;
                }
                if (($user !== '' && $submission->userLogin() !== $user)
                    || ($problemId !== null && $submission->problemId() !== $problemId)) {
                    continue;
                }
                if (count($rows) === 20) {
                    return ['rows' => $rows, 'next' => $rows[19]['feedback']->submissionId()];
                }
                $rows[] = ['feedback' => $record['feedback'], 'submission' => $submission, 'class_id' => $record['class_id']];
            }
        } while (count($batch) === 100);

        return ['rows' => $rows, 'next' => 0];
    }
}
