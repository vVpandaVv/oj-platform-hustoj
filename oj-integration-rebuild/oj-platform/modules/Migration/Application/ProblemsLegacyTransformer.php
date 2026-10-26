<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicRecord;
use DomainException;

final class ProblemsLegacyTransformer implements LegacyDomainTransformer
{
    private const SUPPORTED = [
        'problem_ownerships',
        'problem_metadata',
        'problem_topic_links',
        'quiz_revisions',
        'quiz_questions',
        'quiz_choices',
        'quiz_attempt_summaries',
        'quiz_attempt_items',
        'problem_book_categories',
        'problem_book_category_items',
        'personal_problem_book_items',
    ];

    public function __construct(
        private readonly LegacyTransformValues $values,
        private readonly ProblemTopicDictionary $topics
    ) {
    }

    public function supports(string $transformer): bool
    {
        return in_array($transformer, self::SUPPORTED, true);
    }

    public function transform(
        MigrationTable $table,
        array $row,
        MigrationPlan $plan
    ): array {
        return match ($table->transformer()) {
            'problem_ownerships' => [$this->ownership($row, $plan)],
            'problem_metadata' => [$this->metadata($row)],
            'problem_topic_links' => $this->topicLinks($row),
            'quiz_revisions' => [$this->quizRevision($row)],
            'quiz_questions' => [$this->quizQuestion($row)],
            'quiz_choices' => [$this->quizChoice($row)],
            'quiz_attempt_summaries' => [$this->quizAttempt($row)],
            'quiz_attempt_items' => [$this->quizAttemptItem($row)],
            'problem_book_categories' => [$this->bookCategory($row)],
            'problem_book_category_items' => [$this->bookCategoryItem($row)],
            'personal_problem_book_items' => [$this->personalBookItem($row)],
            default => throw new DomainException('문제 이전 변환기를 찾지 못했습니다.'),
        };
    }

    /** @param array<string, mixed> $row */
    private function ownership(array $row, MigrationPlan $plan): array
    {
        $problemId = $this->values->positive($row['problem_id'] ?? null, '문제 번호');
        $owner = $this->values->user(
            $row,
            'updated_by_wp_user_id',
            '',
            true
        ) ?? $this->values->assignedOwner($plan, 'problem', $problemId);

        return [
            'problem_id' => $problemId,
            'owner_user_id' => $owner,
            'assigned_by_user_id' => $owner,
            'assigned_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function metadata(array $row): array
    {
        $confidence = match ((string) ($row['difficulty_confidence'] ?? '')) {
            'high' => '0.9000',
            'medium' => '0.7000',
            'low' => '0.4000',
            default => null,
        };
        $targetLevels = $this->values->list($row['target_levels'] ?? null);

        return [
            'problem_id' => $this->values->positive($row['problem_id'] ?? null, '문제 번호'),
            'generation_method' => (string) ($row['metadata_mode'] ?? '') === 'auto'
                ? 'ai'
                : 'manual',
            'difficulty_score' => max(1, min(5, (int) ($row['difficulty_score'] ?? 3))),
            'difficulty_rubric_version' => 'legacy-v1',
            'difficulty_source' => $this->values->text($row['difficulty_source'] ?? null),
            'difficulty_confidence' => $confidence,
            'target_level' => $targetLevels[0]
                ?? $this->values->text($row['difficulty_level'] ?? null),
            'prerequisites' => $this->values->nullableJson($row['prerequisites'] ?? null),
            'learning_objectives' => $this->values->nullableJson(
                $row['learning_objectives'] ?? null
            ),
            'problem_type' => $this->values->text($row['problem_type'] ?? null),
            'estimated_minutes' => $this->values->positiveOrNull(
                $row['estimated_minutes'] ?? null
            ),
            'time_complexity' => null,
            'space_complexity' => null,
            'error_tags' => $this->values->nullableJson($row['common_error_tags'] ?? null),
            'recommendation_links' => $this->values->nullableJson(
                $row['recommendation_links'] ?? null
            ),
            'feedback_guidance' => $this->values->nullableJson(
                $row['feedback_policy'] ?? null
            ),
            'statistics_snapshot' => $this->values->nullableJson(
                $row['stats_snapshot'] ?? null
            ),
            'ai_response_snapshot' => $this->values->nullableJson(
                $row['ai_snapshot'] ?? null
            ),
            'code_entry_mode' => 'full_code',
            'code_completion_schema' => null,
            'code_completion_hash' => null,
            'topic_dictionary_version' => $this->topics->version(),
            'prompt_version' => null,
            'provider_key' => null,
            'model_key' => null,
            'admin_notes' => $this->values->text($row['admin_notes'] ?? null),
            'updated_by_user_id' => $this->values->user(
                $row,
                'updated_by_wp_user_id'
            ),
            'analyzed_at' => $this->values->dateTime($row['analyzed_at'] ?? null),
            'generated_at' => $this->values->dateTime($row['analyzed_at'] ?? null),
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function topicLinks(array $row): array
    {
        $keys = [];
        foreach ($this->values->list($row['topics'] ?? null) as $value) {
            $key = $this->topicKey($value);
            if ($key !== null && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        if (count($keys) > 3) {
            throw new DomainException(
                '문제별 세부 주제는 최대 3개로 정리한 뒤 이전해야 합니다.'
            );
        }

        $problemId = $this->values->positive($row['problem_id'] ?? null, '문제 번호');
        $rows = [];
        foreach ($keys as $index => $key) {
            $rows[] = [
                'problem_id' => $problemId,
                'topic_key' => $key,
                'sort_order' => $index,
                'dictionary_version' => $this->topics->version(),
                'created_at' => $this->values->requiredDateTime(
                    $row['created_at'] ?? null
                ),
                'updated_at' => $this->values->requiredDateTime(
                    $row['updated_at'] ?? null
                ),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function quizRevision(array $row): array
    {
        $problemId = $this->values->positive(
            $row['problem_id'] ?? null,
            '퀴즈 문제 번호'
        );
        $enabled = $this->values->flag($row['enabled'] ?? 0);
        $updated = $this->values->requiredDateTime($row['updated_at'] ?? null);
        $config = $this->values->nullableJson($row['config'] ?? null) ?? '{}';
        $actor = $this->values->user($row, 'updated_by_wp_user_id');

        return [
            'id' => $problemId,
            'problem_id' => $problemId,
            'revision_no' => 1,
            'published_slot' => $enabled ? 1 : null,
            'status' => $enabled ? 'published' : 'draft',
            'enabled' => $enabled ? 1 : 0,
            'config' => $config,
            'content_hash' => hash('sha256', $config),
            'created_by_user_id' => $actor,
            'published_by_user_id' => $enabled ? $actor : null,
            'published_at' => $enabled ? $updated : null,
            'created_at' => $updated,
            'updated_at' => $updated,
        ];
    }

    /** @param array<string, mixed> $row */
    private function quizQuestion(array $row): array
    {
        $id = $this->values->positive($row['id'] ?? null, '퀴즈 문항 번호');

        return [
            'id' => $id,
            'revision_id' => $this->values->positive(
                $row['problem_id'] ?? null,
                '퀴즈 revision 번호'
            ),
            'question_key' => $this->values->uuid('question:' . $id),
            'title' => $this->values->text($row['question_title'] ?? null)
                ?? '문항 ' . $id,
            'body' => (string) ($row['question_text'] ?? ''),
            'question_type' => (string) ($row['question_type'] ?? 'single'),
            'correct_answer' => $this->values->text($row['answer_value'] ?? null),
            'grading_rubric' => null,
            'points' => (string) ($row['points'] ?? '0'),
            'sort_order' => max(0, (int) ($row['sort_order'] ?? 0)),
            'enabled' => $this->values->flag($row['enabled'] ?? 1) ? 1 : 0,
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function quizChoice(array $row): array
    {
        $id = $this->values->positive($row['id'] ?? null, '퀴즈 선택지 번호');

        return [
            'id' => $id,
            'question_id' => $this->values->positive(
                $row['question_id'] ?? null,
                '퀴즈 문항 번호'
            ),
            'choice_key' => $this->values->uuid('choice:' . $id),
            'label' => $this->values->text($row['choice_label'] ?? null)
                ?? (string) $id,
            'body' => (string) ($row['choice_text'] ?? ''),
            'is_correct' => $this->values->flag($row['is_correct'] ?? 0) ? 1 : 0,
            'sort_order' => max(0, (int) ($row['sort_order'] ?? 0)),
            'enabled' => $this->values->flag($row['enabled'] ?? 1) ? 1 : 0,
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function quizAttempt(array $row): array
    {
        $id = $this->values->positive($row['id'] ?? null, '퀴즈 응시 번호');
        $problemId = $this->values->positive(
            $row['problem_id'] ?? null,
            '퀴즈 문제 번호'
        );
        $status = match ((string) ($row['status'] ?? 'started')) {
            'graded', 'completed' => 'graded',
            'submitted' => 'submitted',
            default => 'in_progress',
        };

        return [
            'id' => $id,
            'attempt_key' => hash('sha256', 'legacy-quiz-attempt:' . $id),
            'problem_id' => $problemId,
            'quiz_revision_id' => $problemId,
            'user_id' => $this->values->user($row, 'wp_user_id', 'oj_user_id'),
            'active_slot' => $status === 'in_progress' ? 1 : null,
            'attempt_kind' => 'learner',
            'parent_attempt_id' => null,
            'score' => (string) ($row['score'] ?? '0'),
            'max_score' => (string) ($row['max_score'] ?? '0'),
            'status' => $status,
            'started_at' => $this->values->requiredDateTime($row['started_at'] ?? null),
            'submitted_at' => $this->values->dateTime($row['submitted_at'] ?? null),
            'graded_at' => $this->values->dateTime($row['graded_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function quizAttemptItem(array $row): array
    {
        $created = $this->values->requiredDateTime($row['created_at'] ?? null);

        return [
            'id' => $this->values->positive($row['id'] ?? null, '퀴즈 응답 번호'),
            'attempt_id' => $this->values->positive(
                $row['attempt_id'] ?? null,
                '퀴즈 응시 번호'
            ),
            'question_id' => $this->values->positive(
                $row['question_id'] ?? null,
                '퀴즈 문항 번호'
            ),
            'choice_id' => $this->values->positiveOrNull($row['choice_id'] ?? null),
            'answer_text' => $this->values->text($row['answer_text'] ?? null),
            'is_correct' => $this->values->flag($row['is_correct'] ?? 0) ? 1 : 0,
            'points_awarded' => (string) ($row['points_awarded'] ?? '0'),
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /** @param array<string, mixed> $row */
    private function bookCategory(array $row): array
    {
        return [
            'id' => $this->values->positive($row['id'] ?? null, '문제집 분류 번호'),
            'parent_id' => $this->values->positiveOrNull($row['parent_id'] ?? null),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'description' => $this->values->text($row['description'] ?? null),
            'visibility' => (string) ($row['visibility'] ?? '') === 'public'
                ? 'public'
                : 'members',
            'sort_order' => max(0, (int) ($row['sort_order'] ?? 0)),
            'created_by_user_id' => $this->values->user(
                $row,
                'created_by_wp_user_id'
            ),
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
            'deleted_at' => $this->values->dateTime($row['deleted_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function bookCategoryItem(array $row): array
    {
        return [
            'id' => $this->values->positive($row['id'] ?? null, '문제집 항목 번호'),
            'category_id' => $this->values->positive(
                $row['category_id'] ?? null,
                '문제집 분류 번호'
            ),
            'problem_id' => $this->values->positive($row['problem_id'] ?? null, '문제 번호'),
            'sort_order' => max(0, (int) ($row['sort_order'] ?? 0)),
            'created_by_user_id' => $this->values->user(
                $row,
                'created_by_wp_user_id'
            ),
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function personalBookItem(array $row): array
    {
        return [
            'id' => $this->values->positive($row['id'] ?? null, '개인 문제집 항목 번호'),
            'user_id' => $this->values->user($row, 'wp_user_id'),
            'problem_id' => $this->values->positive($row['problem_id'] ?? null, '문제 번호'),
            'note' => $this->values->text($row['note'] ?? null),
            'sort_order' => max(0, (int) ($row['sort_order'] ?? 0)),
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    private function topicKey(string $value): ?string
    {
        $needle = trim($value);
        if ($needle === '') {
            return null;
        }
        foreach ($this->topics->all() as $topic) {
            if (! $topic instanceof ProblemTopicRecord
                || ! $topic->isActive()
                || $topic->isCategory()) {
                continue;
            }
            foreach (array_merge(
                [$topic->key(), $topic->label()],
                $topic->aliases()
            ) as $name) {
                if (trim((string) $name) === $needle
                    || strtolower(trim((string) $name)) === strtolower($needle)) {
                    return $topic->key();
                }
            }
        }

        throw new DomainException(
            '현재 주제 사전에 없는 기존 문제 주제가 있습니다: ' . $needle
        );
    }
}

