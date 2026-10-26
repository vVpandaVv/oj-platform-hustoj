<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizReapplyRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttempt;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttemptItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizChoice;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizOverview;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizReapplyMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizResultPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;
use DomainException;
use JsonException;
use RuntimeException;

final class WordPressQuizRepository implements QuizRepository, QuizReapplyRepository, \CodingPanda\OjPlatform\Modules\Problems\Contract\QuizOverviewPageRepository
{
    /**
     * @var array<int, QuizRevision>
     */
    private array $revisionCache = [];

    /**
     * @var array<string, QuizRevision>
     */
    private array $hashCache = [];
    private array $questionRowsByRevision = [];

    public function __construct(private readonly ProblemPlatformDatabase $database)
    {
    }

    public function overviews(): array
    {
        return $this->loadOverviews();
    }

    public function overviewPage(int $limit, int $offset): array
    {
        return $this->loadOverviews(max(1, min(100, $limit)), max(0, $offset));
    }

    private function loadOverviews(?int $limit = null, int $offset = 0): array
    {
        $rows = $this->database->rows(
            'SELECT `problem_id`,'
                . ' MAX(CASE WHEN `status` = %s THEN `id` ELSE NULL END) AS `draft_id`,'
                . ' MAX(CASE WHEN `published_slot` = 1 THEN `id` ELSE NULL END) AS `published_id`'
                . ' FROM `quiz_revisions` GROUP BY `problem_id` ORDER BY `problem_id`'
                . ($limit === null ? '' : ' LIMIT %d OFFSET %d'),
            $limit === null ? ['draft'] : ['draft', $limit, $offset]
        );
        if ($rows === []) { return []; }
        $problemIds = array_map(static fn (array $row): int => (int) $row['problem_id'], $rows);
        $attemptRows = $this->database->rows(
            'SELECT `problem_id`, COUNT(*) AS `attempt_count`,'
                . ' COALESCE(AVG(`score`), 0) AS `average_score`'
                . ' FROM `quiz_attempt_summaries`'
                . ' WHERE `status` = %s AND `attempt_kind` = %s'
                . ' AND `problem_id` IN (' . implode(',', array_fill(0, count($problemIds), '%d')) . ')'
                . ' GROUP BY `problem_id`',
            ['graded', 'learner', ...$problemIds]
        );
        $attempts = [];
        foreach ($attemptRows as $row) {
            $attempts[(int) ($row['problem_id'] ?? 0)] = $row;
        }

        $revisionIds = [];
        foreach ($rows as $row) {
            foreach (['draft_id', 'published_id'] as $field) {
                if ((int) ($row[$field] ?? 0) > 0) { $revisionIds[] = (int) $row[$field]; }
            }
        }
        foreach (array_chunk(array_values(array_unique($revisionIds)), 100) as $chunk) {
            $revisions = $this->database->rows('SELECT * FROM `quiz_revisions` WHERE `id` IN ('
                . implode(',', array_fill(0, count($chunk), '%d')) . ')', $chunk);
            $this->loadQuestionRows($chunk);
            foreach ($revisions as $revision) { $this->hydrateRevision($revision); }
        }
        $overviews = [];
        foreach ($rows as $row) {
            $problemIdValue = (int) ($row['problem_id'] ?? 0);
            if ($problemIdValue < 1) {
                continue;
            }
            $stats = $attempts[$problemIdValue] ?? [];
            $overviews[] = new QuizOverview(
                new ProblemId($problemIdValue),
                $this->revisionOrNull((int) ($row['draft_id'] ?? 0)),
                $this->revisionOrNull((int) ($row['published_id'] ?? 0)),
                (int) ($stats['attempt_count'] ?? 0),
                (float) ($stats['average_score'] ?? 0)
            );
        }

        return $overviews;
    }

    public function draft(ProblemId $problemId): ?QuizRevision
    {
        $row = $this->database->row(
            'SELECT `id` FROM `quiz_revisions`'
                . ' WHERE `problem_id` = %d AND `status` = %s AND `revision_no` = 0',
            [$problemId->value(), 'draft']
        );

        return $this->revisionOrNull((int) ($row['id'] ?? 0));
    }

    public function published(ProblemId $problemId): ?QuizRevision
    {
        $row = $this->database->row(
            'SELECT `id` FROM `quiz_revisions`'
                . ' WHERE `problem_id` = %d AND `status` = %s AND `published_slot` = 1',
            [$problemId->value(), 'published']
        );

        return $this->revisionOrNull((int) ($row['id'] ?? 0));
    }

    public function revision(int $revisionId): ?QuizRevision
    {
        if ($revisionId < 1) {
            return null;
        }
        if (isset($this->revisionCache[$revisionId])) {
            return $this->revisionCache[$revisionId];
        }
        $row = $this->database->row(
            'SELECT * FROM `quiz_revisions` WHERE `id` = %d',
            [$revisionId]
        );
        if ($row === null) {
            return null;
        }

        return $this->hydrateRevision($row);
    }

    public function history(ProblemId $problemId): array
    {
        $rows = $this->database->rows(
            'SELECT `id` FROM `quiz_revisions`'
                . ' WHERE `problem_id` = %d AND `status` = %s'
                . ' ORDER BY `revision_no` DESC',
            [$problemId->value(), 'published']
        );
        $history = [];
        foreach ($rows as $row) {
            $revision = $this->revisionOrNull((int) ($row['id'] ?? 0));
            if ($revision !== null) {
                $history[] = $revision;
            }
        }

        return $history;
    }

    public function saveDraft(
        ProblemId $problemId,
        QuizDefinition $definition,
        int $actorUserId
    ): QuizRevision {
        $revisionId = $this->database->transaction(
            fn (): int => $this->saveDraftInside(
                $problemId,
                $definition,
                $actorUserId
            )
        );
        $this->clearRevisionCache();

        return $this->requireRevision($revisionId);
    }

    public function publishDraft(
        ProblemId $problemId,
        QuizDefinition $definition,
        int $actorUserId
    ): QuizRevision {
        $revisionId = $this->database->transaction(function () use (
            $problemId,
            $definition,
            $actorUserId
        ): int {
            $this->saveDraftInside($problemId, $definition, $actorUserId);
            $nextRow = $this->database->row(
                'SELECT COALESCE(MAX(`revision_no`), 0) + 1 AS `next_revision`'
                    . ' FROM `quiz_revisions` WHERE `problem_id` = %d',
                [$problemId->value()]
            );
            $nextRevision = (int) ($nextRow['next_revision'] ?? 1);
            $now = gmdate('Y-m-d H:i:s');
            $this->database->execute(
                'UPDATE `quiz_revisions` SET `published_slot` = NULL, `updated_at` = %s'
                    . ' WHERE `problem_id` = %d AND `published_slot` = 1',
                [$now, $problemId->value()]
            );
            $revisionId = $this->database->insert('quiz_revisions', [
                'problem_id' => $problemId->value(),
                'revision_no' => $nextRevision,
                'published_slot' => 1,
                'status' => 'published',
                'enabled' => $definition->isEnabled() ? 1 : 0,
                'config' => $this->encodeConfig($definition),
                'content_hash' => $definition->contentHash(),
                'created_by_user_id' => $actorUserId,
                'published_by_user_id' => $actorUserId,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($revisionId < 1) {
                throw new RuntimeException('게시 퀴즈 버전 번호를 만들지 못했습니다.');
            }
            $this->insertDefinitionRows($revisionId, $definition, $now);

            return $revisionId;
        });
        $this->clearRevisionCache();

        return $this->requireRevision($revisionId);
    }

    public function startAttempt(
        QuizRevision $revision,
        int $userId,
        string $attemptKey
    ): QuizAttempt {
        if (
            $userId < 1
            || preg_match('/^[a-f0-9]{64}$/', $attemptKey) !== 1
        ) {
            throw new DomainException('퀴즈 시작 정보가 올바르지 않습니다.');
        }

        return $this->database->transaction(function () use (
            $revision,
            $userId,
            $attemptKey
        ): QuizAttempt {
            $this->database->row(
                'SELECT `id` FROM `quiz_revisions`'
                    . ' WHERE `problem_id` = %d AND `revision_no` = 0 FOR UPDATE',
                [$revision->problemId()->value()]
            );
            $lockedRevision = $this->database->row(
                'SELECT `status`, `enabled`, `published_slot`'
                    . ' FROM `quiz_revisions` WHERE `id` = %d FOR UPDATE',
                [$revision->id()]
            );
            if (
                $lockedRevision === null
                || (string) ($lockedRevision['status'] ?? '') !== 'published'
                || (int) ($lockedRevision['enabled'] ?? 0) !== 1
                || (int) ($lockedRevision['published_slot'] ?? 0) !== 1
            ) {
                throw new DomainException('현재 게시된 퀴즈 버전으로 다시 시작해 주세요.');
            }

            $sameKey = $this->database->row(
                'SELECT * FROM `quiz_attempt_summaries`'
                    . ' WHERE `attempt_key` = %s FOR UPDATE',
                [$attemptKey]
            );
            if ($sameKey !== null) {
                if (
                    (int) ($sameKey['user_id'] ?? 0) !== $userId
                    || (int) ($sameKey['problem_id'] ?? 0)
                        !== $revision->problemId()->value()
                ) {
                    throw new DomainException('이미 사용된 퀴즈 시작 확인 키입니다.');
                }

                return $this->hydrateAttempt($sameKey);
            }

            $active = $this->database->row(
                'SELECT * FROM `quiz_attempt_summaries`'
                    . ' WHERE `problem_id` = %d AND `user_id` = %d'
                    . ' AND `active_slot` = 1 FOR UPDATE',
                [$revision->problemId()->value(), $userId]
            );
            if ($active !== null) {
                return $this->hydrateAttempt($active);
            }

            $countRow = $this->database->row(
                'SELECT COUNT(*) AS `attempt_count` FROM `quiz_attempt_summaries`'
                    . ' WHERE `problem_id` = %d AND `user_id` = %d'
                    . ' AND `attempt_kind` = %s AND `status` IN (%s, %s)',
                [
                    $revision->problemId()->value(),
                    $userId,
                    'learner',
                    'in_progress',
                    'graded',
                ]
            );
            if (
                (int) ($countRow['attempt_count'] ?? 0)
                >= $revision->definition()->attemptLimit()
            ) {
                throw new DomainException('이 퀴즈의 응시 횟수를 모두 사용했습니다.');
            }

            $now = gmdate('Y-m-d H:i:s');
            $attemptId = $this->database->insert('quiz_attempt_summaries', [
                'attempt_key' => $attemptKey,
                'problem_id' => $revision->problemId()->value(),
                'quiz_revision_id' => $revision->id(),
                'user_id' => $userId,
                'active_slot' => 1,
                'attempt_kind' => 'learner',
                'parent_attempt_id' => null,
                'score' => 0,
                'max_score' => $revision->definition()->maxScore(),
                'status' => 'in_progress',
                'started_at' => $now,
                'submitted_at' => null,
                'graded_at' => null,
            ]);

            return $this->requireAttempt($attemptId);
        });
    }

    public function activeAttempt(
        ProblemId $problemId,
        int $userId
    ): ?QuizAttempt {
        $row = $this->database->row(
            'SELECT * FROM `quiz_attempt_summaries`'
                . ' WHERE `problem_id` = %d AND `user_id` = %d'
                . ' AND `active_slot` = 1',
            [$problemId->value(), $userId]
        );

        return $row === null ? null : $this->hydrateAttempt($row);
    }

    public function attempt(int $attemptId): ?QuizAttempt
    {
        if ($attemptId < 1) {
            return null;
        }
        $row = $this->database->row(
            'SELECT * FROM `quiz_attempt_summaries` WHERE `id` = %d',
            [$attemptId]
        );

        return $row === null ? null : $this->hydrateAttempt($row);
    }

    public function attemptsForUser(ProblemId $problemId, int $userId): array
    {
        $rows = $this->database->rows(
            'SELECT * FROM `quiz_attempt_summaries`'
                . ' WHERE `problem_id` = %d AND `user_id` = %d'
                . ' AND `attempt_kind` IN (%s, %s)'
                . ' ORDER BY `started_at` DESC, `id` DESC',
            [$problemId->value(), $userId, 'learner', 'replacement']
        );

        return array_map(
            fn (array $row): QuizAttempt => $this->hydrateAttempt($row),
            $rows
        );
    }

    public function finalizeAttempt(
        int $attemptId,
        int $userId,
        array $items,
        float $score,
        float $maxScore
    ): QuizAttempt {
        return $this->database->transaction(function () use (
            $attemptId,
            $userId,
            $items,
            $score,
            $maxScore
        ): QuizAttempt {
            $row = $this->database->row(
                'SELECT * FROM `quiz_attempt_summaries` WHERE `id` = %d FOR UPDATE',
                [$attemptId]
            );
            if ($row === null || (int) ($row['user_id'] ?? 0) !== $userId) {
                throw new DomainException('제출할 퀴즈 응시를 찾을 수 없습니다.');
            }
            if ((string) ($row['status'] ?? '') === 'graded') {
                return $this->hydrateAttempt($row);
            }
            if ((string) ($row['status'] ?? '') !== 'in_progress') {
                throw new DomainException('현재 제출할 수 없는 퀴즈 응시입니다.');
            }
            $revision = $this->requireRevision(
                (int) ($row['quiz_revision_id'] ?? 0)
            );
            $this->assertFinalization(
                $revision,
                $items,
                $score,
                $maxScore
            );
            $now = gmdate('Y-m-d H:i:s');
            foreach ($items as $item) {
                $this->insertAttemptItem($attemptId, $item, $now);
            }
            $this->database->execute(
                'UPDATE `quiz_attempt_summaries`'
                    . ' SET `active_slot` = NULL, `score` = %f, `max_score` = %f,'
                    . ' `status` = %s, `submitted_at` = %s, `graded_at` = %s'
                    . ' WHERE `id` = %d AND `status` = %s',
                [$score, $maxScore, 'graded', $now, $now, $attemptId, 'in_progress']
            );

            return $this->requireAttempt($attemptId);
        });
    }

    public function statistics(
        ProblemId $problemId,
        int $revisionId
    ): array {
        $revision = $this->requireRevision($revisionId);
        if (! $revision->problemId()->equals($problemId)) {
            throw new DomainException('문제와 퀴즈 버전이 일치하지 않습니다.');
        }
        $summary = $this->database->row(
            'SELECT COUNT(*) AS `attempt_count`,'
                . ' COALESCE(AVG(`score`), 0) AS `average_score`'
                . ' FROM `quiz_attempt_summaries`'
                . ' WHERE `quiz_revision_id` = %d AND `status` = %s',
            [$revisionId, 'graded']
        ) ?? [];
        $questionRows = $this->database->rows(
            'SELECT q.`id` AS `question_id`, q.`question_key`, q.`title`, q.`points`,'
                . ' COUNT(a.`id`) AS `answer_count`,'
                . ' COALESCE(SUM(CASE WHEN a.`id` IS NOT NULL'
                . ' AND i.`is_correct` = 1 THEN 1 ELSE 0 END), 0) AS `correct_count`'
                . ' FROM `quiz_questions` q'
                . ' LEFT JOIN `quiz_attempt_items` i ON i.`question_id` = q.`id`'
                . ' LEFT JOIN `quiz_attempt_summaries` a'
                . ' ON a.`id` = i.`attempt_id` AND a.`quiz_revision_id` = %d'
                . ' AND a.`status` = %s'
                . ' WHERE q.`revision_id` = %d AND q.`enabled` = 1'
                . ' GROUP BY q.`id`, q.`question_key`, q.`title`, q.`points`, q.`sort_order`'
                . ' ORDER BY q.`sort_order`',
            [$revisionId, 'graded', $revisionId]
        );
        $attemptRows = $this->database->rows(
            'SELECT a.`id`, a.`user_id`, a.`score`, a.`max_score`,'
                . ' a.`started_at`, a.`submitted_at`,'
                . ' COALESCE(SUM(i.`is_correct`), 0) AS `correct_count`,'
                . ' COUNT(i.`id`) AS `question_count`'
                . ' FROM `quiz_attempt_summaries` a'
                . ' LEFT JOIN `quiz_attempt_items` i ON i.`attempt_id` = a.`id`'
                . ' WHERE a.`quiz_revision_id` = %d AND a.`status` = %s'
                . ' GROUP BY a.`id`, a.`user_id`, a.`score`, a.`max_score`,'
                . ' a.`started_at`, a.`submitted_at`'
                . ' ORDER BY a.`submitted_at` DESC, a.`id` DESC LIMIT 500',
            [$revisionId, 'graded']
        );

        return [
            'attempt_count' => (int) ($summary['attempt_count'] ?? 0),
            'average_score' => (float) ($summary['average_score'] ?? 0),
            'question_rows' => $questionRows,
            'attempt_rows' => $attemptRows,
        ];
    }

    public function candidates(
        ProblemId $problemId,
        int $sourceRevisionId,
        QuizReapplyMode $mode
    ): array {
        $status = $mode === QuizReapplyMode::InProgress
            ? 'in_progress'
            : 'graded';
        $rows = $this->database->rows(
            'SELECT * FROM `quiz_attempt_summaries`'
                . ' WHERE `problem_id` = %d AND `quiz_revision_id` = %d'
                . ' AND `status` = %s'
                . ' AND `attempt_kind` IN (%s, %s, %s)'
                . ($mode === QuizReapplyMode::InProgress
                    ? ' AND `active_slot` = 1'
                    : '')
                . ' ORDER BY `id`',
            [
                $problemId->value(),
                $sourceRevisionId,
                $status,
                'learner',
                'replacement',
                'regrade',
            ]
        );

        return array_map(
            fn (array $row): QuizAttempt => $this->hydrateAttempt($row),
            $rows
        );
    }

    public function replaceInProgress(
        QuizAttempt $original,
        QuizRevision $target,
        string $attemptKey
    ): QuizAttempt {
        return $this->database->transaction(function () use (
            $original,
            $target,
            $attemptKey
        ): QuizAttempt {
            $locked = $this->lockReapplyOriginal($original, $target);
            $existing = $this->existingReapply(
                $original->id(),
                $target->id(),
                'replacement',
                $attemptKey
            );
            if ($existing !== null) {
                return $existing;
            }
            if (
                (string) ($locked['status'] ?? '') !== 'in_progress'
                || (int) ($locked['active_slot'] ?? 0) !== 1
            ) {
                throw new DomainException('진행 중 재적용 대상의 상태가 변경되었습니다.');
            }
            $this->database->execute(
                'UPDATE `quiz_attempt_summaries`'
                    . ' SET `active_slot` = NULL, `status` = %s'
                    . ' WHERE `id` = %d AND `status` = %s',
                ['superseded', $original->id(), 'in_progress']
            );
            $now = gmdate('Y-m-d H:i:s');
            $attemptId = $this->database->insert('quiz_attempt_summaries', [
                'attempt_key' => $attemptKey,
                'problem_id' => $target->problemId()->value(),
                'quiz_revision_id' => $target->id(),
                'user_id' => (int) ($locked['user_id'] ?? 0),
                'active_slot' => 1,
                'attempt_kind' => 'replacement',
                'parent_attempt_id' => $original->id(),
                'score' => 0,
                'max_score' => $target->definition()->maxScore(),
                'status' => 'in_progress',
                'started_at' => $now,
                'submitted_at' => null,
                'graded_at' => null,
            ]);

            return $this->requireAttempt($attemptId);
        });
    }

    public function createRegrade(
        QuizAttempt $original,
        QuizRevision $target,
        string $attemptKey,
        array $items,
        float $score,
        float $maxScore
    ): QuizAttempt {
        return $this->database->transaction(function () use (
            $original,
            $target,
            $attemptKey,
            $items,
            $score,
            $maxScore
        ): QuizAttempt {
            $locked = $this->lockReapplyOriginal($original, $target);
            $existing = $this->existingReapply(
                $original->id(),
                $target->id(),
                'regrade',
                $attemptKey
            );
            if ($existing !== null) {
                return $existing;
            }
            if ((string) ($locked['status'] ?? '') !== 'graded') {
                throw new DomainException('완료 결과 재채점 대상의 상태가 변경되었습니다.');
            }
            $this->assertFinalization($target, $items, $score, $maxScore);
            $now = gmdate('Y-m-d H:i:s');
            $attemptId = $this->database->insert('quiz_attempt_summaries', [
                'attempt_key' => $attemptKey,
                'problem_id' => $target->problemId()->value(),
                'quiz_revision_id' => $target->id(),
                'user_id' => (int) ($locked['user_id'] ?? 0),
                'active_slot' => null,
                'attempt_kind' => 'regrade',
                'parent_attempt_id' => $original->id(),
                'score' => $score,
                'max_score' => $maxScore,
                'status' => 'graded',
                'started_at' => (string) ($locked['started_at'] ?? $now),
                'submitted_at' => $this->nullableString(
                    $locked['submitted_at'] ?? null
                ) ?? $now,
                'graded_at' => $now,
            ]);
            foreach ($items as $item) {
                $this->insertAttemptItem($attemptId, $item, $now);
            }

            return $this->requireAttempt($attemptId);
        });
    }

    private function insertAttemptItem(
        int $attemptId,
        QuizAttemptItem $item,
        string $now
    ): void {
        $itemId = $this->database->insert('quiz_attempt_items', [
            'attempt_id' => $attemptId,
            'question_id' => $item->questionId(),
            'choice_id' => $item->choiceId(),
            'answer_text' => $item->answerText(),
            'is_correct' => $item->isCorrect() ? 1 : 0,
            'points_awarded' => $item->pointsAwarded(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($item->selectedChoiceIds() as $choiceId) {
            $this->database->insert('quiz_attempt_item_choices', [
                'attempt_item_id' => $itemId,
                'choice_id' => $choiceId,
            ]);
        }
    }

    private function saveDraftInside(
        ProblemId $problemId,
        QuizDefinition $definition,
        int $actorUserId
    ): int {
        if ($actorUserId < 1) {
            throw new DomainException('퀴즈 작성 사용자를 확인할 수 없습니다.');
        }
        $existing = $this->database->row(
            'SELECT `id` FROM `quiz_revisions`'
                . ' WHERE `problem_id` = %d AND `revision_no` = 0 FOR UPDATE',
            [$problemId->value()]
        );
        $now = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
            $revisionId = $this->database->insert('quiz_revisions', [
                'problem_id' => $problemId->value(),
                'revision_no' => 0,
                'published_slot' => null,
                'status' => 'draft',
                'enabled' => $definition->isEnabled() ? 1 : 0,
                'config' => $this->encodeConfig($definition),
                'content_hash' => $definition->contentHash(),
                'created_by_user_id' => $actorUserId,
                'published_by_user_id' => null,
                'published_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $revisionId = (int) ($existing['id'] ?? 0);
            $this->database->execute(
                'UPDATE `quiz_revisions` SET `status` = %s, `enabled` = %d,'
                    . ' `config` = %s, `content_hash` = %s, `updated_at` = %s'
                    . ' WHERE `id` = %d',
                [
                    'draft',
                    $definition->isEnabled() ? 1 : 0,
                    $this->encodeConfig($definition),
                    $definition->contentHash(),
                    $now,
                    $revisionId,
                ]
            );
            $this->database->execute(
                'DELETE c FROM `quiz_choices` c'
                    . ' INNER JOIN `quiz_questions` q ON q.`id` = c.`question_id`'
                    . ' WHERE q.`revision_id` = %d',
                [$revisionId]
            );
            $this->database->execute(
                'DELETE FROM `quiz_questions` WHERE `revision_id` = %d',
                [$revisionId]
            );
        }
        if ($revisionId < 1) {
            throw new RuntimeException('퀴즈 초안 번호를 만들지 못했습니다.');
        }
        $this->insertDefinitionRows($revisionId, $definition, $now);

        return $revisionId;
    }

    private function insertDefinitionRows(
        int $revisionId,
        QuizDefinition $definition,
        string $now
    ): void {
        foreach ($definition->questions() as $question) {
            $questionId = $this->database->insert('quiz_questions', [
                'revision_id' => $revisionId,
                'question_key' => $question->key(),
                'title' => $question->title(),
                'body' => $question->body(),
                'question_type' => $question->type(),
                'correct_answer' => $this->encodeAcceptedAnswers($question->acceptedAnswers()),
                'grading_rubric' => $question->explanation(),
                'points' => $question->points(),
                'sort_order' => $question->sortOrder(),
                'enabled' => $question->isEnabled() ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($question->choices() as $choice) {
                $this->database->insert('quiz_choices', [
                    'question_id' => $questionId,
                    'choice_key' => $choice->key(),
                    'label' => $choice->label(),
                    'body' => $choice->body(),
                    'is_correct' => $choice->isCorrect() ? 1 : 0,
                    'sort_order' => $choice->sortOrder(),
                    'enabled' => $choice->isEnabled() ? 1 : 0,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function loadQuestionRows(array $ids): void
    {
        foreach ($ids as $id) { $this->questionRowsByRevision[$id] = []; }
        $rows = $this->database->rows(
            'SELECT q.`revision_id`, q.`id` AS `question_id`, q.`question_key`, q.`title`,'
            . ' q.`body`, q.`question_type`, q.`correct_answer`, q.`grading_rubric`, q.`points`,'
            . ' q.`sort_order` AS `question_order`, q.`enabled` AS `question_enabled`,'
            . ' c.`id` AS `choice_id`, c.`choice_key`, c.`label`, c.`body` AS `choice_body`, c.`is_correct`,'
            . ' c.`sort_order` AS `choice_order`, c.`enabled` AS `choice_enabled`'
            . ' FROM `quiz_questions` q LEFT JOIN `quiz_choices` c ON c.`question_id` = q.`id`'
            . ' WHERE q.`revision_id` IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')'
            . ' ORDER BY q.`revision_id`, q.`sort_order`, q.`id`, c.`sort_order`, c.`id`', $ids
        );
        foreach ($rows as $row) {
            $id = (int) ($row['revision_id'] ?? (count($ids) === 1 ? $ids[0] : 0));
            if (isset($this->questionRowsByRevision[$id])) { $this->questionRowsByRevision[$id][] = $row; }
        }
    }

    private function hydrateRevision(array $row): QuizRevision
    {
        $revisionId = (int) ($row['id'] ?? 0);
        if (isset($this->revisionCache[$revisionId])) {
            return $this->revisionCache[$revisionId];
        }
        $contentHash = (string) ($row['content_hash'] ?? '');
        if (
            $contentHash !== ''
            && isset($this->hashCache[$contentHash])
            && $this->hashCache[$contentHash]->id() === $revisionId
        ) {
            return $this->hashCache[$contentHash];
        }
        $config = $this->decodeConfig((string) ($row['config'] ?? ''));
        if (! array_key_exists($revisionId, $this->questionRowsByRevision)) { $this->loadQuestionRows([$revisionId]); }
        $questionRows = $this->questionRowsByRevision[$revisionId];
        $grouped = [];
        foreach ($questionRows as $questionRow) {
            $questionId = (int) ($questionRow['question_id'] ?? 0);
            if (! isset($grouped[$questionId])) {
                $grouped[$questionId] = [
                    'row' => $questionRow,
                    'choices' => [],
                ];
            }
            $choiceId = (int) ($questionRow['choice_id'] ?? 0);
            if ($choiceId > 0) {
                $grouped[$questionId]['choices'][] = new QuizChoice(
                    $choiceId,
                    (string) ($questionRow['choice_key'] ?? ''),
                    (string) ($questionRow['label'] ?? ''),
                    (string) ($questionRow['choice_body'] ?? ''),
                    (int) ($questionRow['is_correct'] ?? 0) === 1,
                    (int) ($questionRow['choice_order'] ?? 0),
                    (int) ($questionRow['choice_enabled'] ?? 0) === 1
                );
            }
        }
        $questions = [];
        foreach ($grouped as $questionId => $item) {
            $questionRow = $item['row'];
            $explanation = $questionRow['grading_rubric'] ?? null;
            $questions[] = new QuizQuestion(
                (int) $questionId,
                (string) ($questionRow['question_key'] ?? ''),
                (string) ($questionRow['title'] ?? ''),
                (string) ($questionRow['body'] ?? ''),
                (string) ($questionRow['question_type'] ?? ''),
                is_string($explanation) && $explanation !== ''
                    ? $explanation
                    : null,
                (float) ($questionRow['points'] ?? 0),
                (int) ($questionRow['question_order'] ?? 0),
                (int) ($questionRow['question_enabled'] ?? 0) === 1,
                $item['choices'],
                $this->decodeAcceptedAnswers($questionRow['correct_answer'] ?? null)
            );
        }
        $resultPolicy = QuizResultPolicy::tryFrom(
            (string) ($config['result_policy'] ?? '')
        );
        if ($resultPolicy === null) {
            throw new RuntimeException('저장된 퀴즈 결과 공개 정책이 올바르지 않습니다.');
        }
        $availableAt = $config['results_available_at'] ?? null;
        $definition = new QuizDefinition(
            (string) ($config['title'] ?? ''),
            (string) ($config['description'] ?? ''),
            (int) ($config['attempt_limit'] ?? 0),
            $resultPolicy,
            is_string($availableAt) && $availableAt !== '' ? $availableAt : null,
            (bool) ($config['public_statistics'] ?? false),
            (bool) ($config['protect_published'] ?? true),
            (int) ($row['enabled'] ?? 0) === 1,
            $questions
        );
        $revision = new QuizRevision(
            $revisionId,
            new ProblemId((int) ($row['problem_id'] ?? 0)),
            (int) ($row['revision_no'] ?? 0),
            (string) ($row['status'] ?? ''),
            (int) ($row['published_slot'] ?? 0) === 1,
            $definition,
            $contentHash,
            (int) ($row['created_by_user_id'] ?? 0),
            $this->nullablePositiveInt($row['published_by_user_id'] ?? null),
            $this->nullableString($row['published_at'] ?? null)
        );
        $this->revisionCache[$revisionId] = $revision;
        $this->hashCache[$contentHash] = $revision;

        return $revision;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAttempt(array $row): QuizAttempt
    {
        $attemptId = (int) ($row['id'] ?? 0);
        $itemRows = $this->database->rows(
            'SELECT `id`, `question_id`, `choice_id`, `answer_text`,'
                . ' `is_correct`, `points_awarded`'
                . ' FROM `quiz_attempt_items` WHERE `attempt_id` = %d'
                . ' ORDER BY `id`',
            [$attemptId]
        );
        $itemIds = array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $itemRows
        );
        $selectedByItem = [];
        if ($itemIds !== []) {
            $choiceRows = $this->database->rows(
                'SELECT `attempt_item_id`, `choice_id`'
                    . ' FROM `quiz_attempt_item_choices`'
                    . ' WHERE `attempt_item_id` IN ('
                    . implode(', ', array_fill(0, count($itemIds), '%d')) . ')',
                $itemIds
            );
            foreach ($choiceRows as $choiceRow) {
                $selectedByItem[(int) ($choiceRow['attempt_item_id'] ?? 0)][] =
                    (int) ($choiceRow['choice_id'] ?? 0);
            }
        }
        $items = array_map(
            fn (array $item): QuizAttemptItem => new QuizAttemptItem(
                (int) ($item['question_id'] ?? 0),
                $this->nullablePositiveInt($item['choice_id'] ?? null),
                $this->nullableString($item['answer_text'] ?? null),
                (int) ($item['is_correct'] ?? 0) === 1,
                (float) ($item['points_awarded'] ?? 0),
                $selectedByItem[(int) ($item['id'] ?? 0)] ?? []
            ),
            $itemRows
        );

        return new QuizAttempt(
            $attemptId,
            (string) ($row['attempt_key'] ?? ''),
            new ProblemId((int) ($row['problem_id'] ?? 0)),
            (int) ($row['quiz_revision_id'] ?? 0),
            (int) ($row['user_id'] ?? 0),
            (string) ($row['status'] ?? ''),
            (float) ($row['score'] ?? 0),
            (float) ($row['max_score'] ?? 0),
            (string) ($row['started_at'] ?? ''),
            $this->nullableString($row['submitted_at'] ?? null),
            $items,
            (string) ($row['attempt_kind'] ?? 'learner'),
            $this->nullablePositiveInt($row['parent_attempt_id'] ?? null)
        );
    }

    /**
     * @param list<QuizAttemptItem> $items
     */
    private function assertFinalization(
        QuizRevision $revision,
        array $items,
        float $score,
        float $maxScore
    ): void {
        $questions = [];
        foreach ($revision->definition()->questions() as $question) {
            if ($question->isEnabled()) {
                $questions[$question->id()] = $question;
            }
        }
        if (count($items) !== count($questions)) {
            throw new DomainException('퀴즈 전체 문항의 채점 결과가 필요합니다.');
        }
        $seen = [];
        $calculatedScore = 0.0;
        foreach ($items as $item) {
            if (! $item instanceof QuizAttemptItem) {
                throw new DomainException('퀴즈 채점 문항 형식이 올바르지 않습니다.');
            }
            $question = $questions[$item->questionId()] ?? null;
            if ($question === null || isset($seen[$item->questionId()])) {
                throw new DomainException('퀴즈 채점 문항이 중복되었거나 올바르지 않습니다.');
            }
            $seen[$item->questionId()] = true;
            $correct = match ($question->type()) {
                'multi_choice' => $this->recomputeMultiChoiceCorrect(
                    $question,
                    $item->selectedChoiceIds()
                ),
                'short_answer' => $this->recomputeShortAnswerCorrect(
                    $question,
                    $item->answerText()
                ),
                default => $this->recomputeSingleChoiceCorrect(
                    $question,
                    $item->choiceId()
                ),
            };
            $points = $correct ? $question->points() : 0.0;
            if (
                $correct !== $item->isCorrect()
                || abs($points - $item->pointsAwarded()) > 0.001
            ) {
                throw new DomainException('퀴즈 채점 결과를 다시 계산해 주세요.');
            }
            $calculatedScore += $points;
        }
        if (
            abs($calculatedScore - $score) > 0.001
            || abs($revision->definition()->maxScore() - $maxScore) > 0.001
        ) {
            throw new DomainException('퀴즈 총점 계산이 일치하지 않습니다.');
        }
    }

    private function recomputeSingleChoiceCorrect(
        QuizQuestion $question,
        ?int $choiceId
    ): bool {
        if ($choiceId === null) {
            return false;
        }
        foreach ($question->choices() as $choice) {
            if ($choice->id() === $choiceId && $choice->isEnabled()) {
                return $choice->isCorrect();
            }
        }

        throw new DomainException('채점 선택지가 문항과 일치하지 않습니다.');
    }

    /**
     * @param list<int> $selectedChoiceIds
     */
    private function recomputeMultiChoiceCorrect(
        QuizQuestion $question,
        array $selectedChoiceIds
    ): bool {
        $validIds = array_map(
            static fn (QuizChoice $choice): int => $choice->id(),
            array_filter(
                $question->choices(),
                static fn (QuizChoice $choice): bool => $choice->isEnabled()
            )
        );
        foreach ($selectedChoiceIds as $choiceId) {
            if (! in_array($choiceId, $validIds, true)) {
                throw new DomainException('채점 선택지가 문항과 일치하지 않습니다.');
            }
        }
        $correctIds = array_map(
            static fn (QuizChoice $choice): int => $choice->id(),
            $question->correctChoices()
        );
        $selected = $selectedChoiceIds;
        sort($selected);
        sort($correctIds);

        return $selected === $correctIds;
    }

    private function recomputeShortAnswerCorrect(
        QuizQuestion $question,
        ?string $answerText
    ): bool {
        $normalized = $this->normalizeShortAnswer((string) $answerText);
        if ($normalized === '') {
            return false;
        }
        foreach ($question->acceptedAnswers() as $accepted) {
            if ($normalized === $this->normalizeShortAnswer($accepted)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeShortAnswer(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return mb_strtolower($collapsed ?? trim($value), 'UTF-8');
    }

    private function revisionOrNull(int $revisionId): ?QuizRevision
    {
        return $revisionId < 1 ? null : $this->revision($revisionId);
    }

    private function requireRevision(int $revisionId): QuizRevision
    {
        $revision = $this->revision($revisionId);
        if ($revision === null) {
            throw new RuntimeException('퀴즈 버전을 찾을 수 없습니다.');
        }

        return $revision;
    }

    private function requireAttempt(int $attemptId): QuizAttempt
    {
        $attempt = $this->attempt($attemptId);
        if ($attempt === null) {
            throw new RuntimeException('퀴즈 응시 기록을 찾을 수 없습니다.');
        }

        return $attempt;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(string $config): array
    {
        try {
            $decoded = json_decode($config, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('저장된 퀴즈 설정을 읽을 수 없습니다.', 0, $error);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('저장된 퀴즈 설정 형식이 올바르지 않습니다.');
        }

        return $decoded;
    }

    private function encodeConfig(QuizDefinition $definition): string
    {
        try {
            return json_encode(
                $definition->config(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $error) {
            throw new RuntimeException('퀴즈 설정을 저장할 수 없습니다.', 0, $error);
        }
    }

    /**
     * @param list<string> $acceptedAnswers
     */
    private function encodeAcceptedAnswers(array $acceptedAnswers): ?string
    {
        if ($acceptedAnswers === []) {
            return null;
        }
        try {
            return json_encode(
                $acceptedAnswers,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $error) {
            throw new RuntimeException('퀴즈 정답 목록을 저장할 수 없습니다.', 0, $error);
        }
    }

    /**
     * @return list<string>
     */
    private function decodeAcceptedAnswers(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $answer): bool => is_string($answer)
        ));
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function clearRevisionCache(): void
    {
        $this->revisionCache = [];
        $this->hashCache = [];
        $this->questionRowsByRevision = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function lockReapplyOriginal(
        QuizAttempt $original,
        QuizRevision $target
    ): array {
        if (
            preg_match('/^[a-f0-9]{64}$/', $original->attemptKey()) !== 1
            || ! $original->problemId()->equals($target->problemId())
            || ! $target->isCurrentPublished()
            || $target->definition()->protectPublished()
        ) {
            throw new DomainException('퀴즈 재적용 버전이 올바르지 않습니다.');
        }
        $locked = $this->database->row(
            'SELECT * FROM `quiz_attempt_summaries`'
                . ' WHERE `id` = %d FOR UPDATE',
            [$original->id()]
        );
        $lockedTarget = $this->database->row(
            'SELECT `status`, `published_slot`, `content_hash`'
                . ' FROM `quiz_revisions` WHERE `id` = %d FOR UPDATE',
            [$target->id()]
        );
        if (
            $locked === null
            || $lockedTarget === null
            || (int) ($locked['problem_id'] ?? 0)
                !== $original->problemId()->value()
            || (int) ($locked['quiz_revision_id'] ?? 0)
                !== $original->revisionId()
            || (string) ($locked['attempt_key'] ?? '')
                !== $original->attemptKey()
            || (string) ($lockedTarget['status'] ?? '') !== 'published'
            || (int) ($lockedTarget['published_slot'] ?? 0) !== 1
            || ! hash_equals(
                $target->contentHash(),
                (string) ($lockedTarget['content_hash'] ?? '')
            )
        ) {
            throw new DomainException('퀴즈 재적용 대상이 실행 전에 변경되었습니다.');
        }

        return $locked;
    }

    private function existingReapply(
        int $parentAttemptId,
        int $targetRevisionId,
        string $kind,
        string $attemptKey
    ): ?QuizAttempt {
        if (preg_match('/^[a-f0-9]{64}$/', $attemptKey) !== 1) {
            throw new DomainException('퀴즈 재적용 확인 키가 올바르지 않습니다.');
        }
        $row = $this->database->row(
            'SELECT * FROM `quiz_attempt_summaries`'
                . ' WHERE `parent_attempt_id` = %d'
                . ' AND `quiz_revision_id` = %d AND `attempt_kind` = %s'
                . ' FOR UPDATE',
            [$parentAttemptId, $targetRevisionId, $kind]
        );
        if ($row === null) {
            return null;
        }
        if (! hash_equals($attemptKey, (string) ($row['attempt_key'] ?? ''))) {
            throw new DomainException('퀴즈 재적용 중복 기록이 서로 일치하지 않습니다.');
        }

        return $this->hydrateAttempt($row);
    }
}
