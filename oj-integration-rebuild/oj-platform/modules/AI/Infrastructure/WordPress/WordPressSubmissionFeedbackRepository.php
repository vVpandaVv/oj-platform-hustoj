<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\AI\Contract\SubmissionFeedbackRepository;
use CodingPanda\OjPlatform\Modules\AI\Domain\SubmissionFeedback;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;

final class WordPressSubmissionFeedbackRepository implements SubmissionFeedbackRepository, \CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackReviewRepository
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function find(int $submissionId): ?SubmissionFeedback
    {
        $row = $this->database->row(
            'SELECT * FROM submission_ai_feedback'
                . ' WHERE submission_id = %d LIMIT 1',
            [$submissionId]
        );

        return $row === null ? null : SubmissionFeedback::fromRow($row);
    }

    public function save(SubmissionFeedback $feedback): void
    {
        $data = $feedback->toRow();
        if ($this->find($feedback->submissionId()) === null) {
            $this->database->insert('submission_ai_feedback', $data);
            return;
        }
        unset($data['submission_id'], $data['created_at']);
        $this->database->update(
            'submission_ai_feedback',
            $data,
            ['submission_id' => $feedback->submissionId()]
        );
    }

    public function recent(int $limit = 100): array
    {
        $rows = $this->database->rows(
            'SELECT * FROM submission_ai_feedback'
                . ' ORDER BY updated_at DESC, submission_id DESC LIMIT %d',
            [max(1, min(500, $limit))]
        );

        return array_map([SubmissionFeedback::class, 'fromRow'], $rows);
    }

    public function reviewBatch(int $beforeId, ?int $classId, int $limit = 100): array
    {
        $where = [];
        $values = [];
        if ($beforeId > 0) {
            $where[] = 'f.submission_id < %d';
            $values[] = $beforeId;
        }
        if ($classId !== null) {
            $where[] = 'c.class_id = %d';
            $values[] = $classId;
        }
        $values[] = max(1, min(100, $limit));
        $rows = $this->database->rows(
            'SELECT f.*, c.class_id AS review_class_id FROM submission_ai_feedback f'
                . ' LEFT JOIN submission_contexts c ON c.submission_id = f.submission_id'
                . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
                . ' ORDER BY f.submission_id DESC LIMIT %d',
            $values
        );

        return array_map(static fn (array $row): array => [
            'feedback' => SubmissionFeedback::fromRow($row),
            'class_id' => isset($row['review_class_id']) ? (int) $row['review_class_id'] : null,
        ], $rows);
    }
}
