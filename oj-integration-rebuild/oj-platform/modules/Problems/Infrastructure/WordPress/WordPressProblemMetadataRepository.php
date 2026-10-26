<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicLinkRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use RuntimeException;

final class WordPressProblemMetadataRepository implements ProblemMetadataRepository
{
    private const TABLE = 'problem_metadata';

    public function __construct(
        private readonly ProblemPlatformDatabase $database,
        private readonly ProblemTopicLinkRepository $topics
    ) {
    }

    public function find(ProblemId $problemId): ?ProblemMetadata
    {
        $row = $this->database->row(
            'SELECT `difficulty_score`, `difficulty_source`,'
                . ' `code_entry_mode`, `code_completion_schema`,'
                . ' `code_completion_hash`, `generation_method`,'
                . ' `admin_notes`, `updated_by_user_id`, `topic_dictionary_version`,'
                . ' `visual_grading_mode`, `ordering_schema`, `ordering_hash`'
                . ' FROM `' . self::TABLE . '` WHERE `problem_id` = %d',
            [$problemId->value()]
        );
        if ($row === null) {
            return null;
        }

        return $this->hydrate(
            $problemId,
            $row,
            $this->topics->keysFor($problemId)
        );
    }

    public function findMany(array $problemIds): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (ProblemId $problemId): int => $problemId->value(),
            $problemIds
        )));
        if ($ids === []) {
            return [];
        }
        $rows = $this->database->rows(
            'SELECT `problem_id`, `difficulty_score`, `difficulty_source`,'
                . ' `code_entry_mode`, `code_completion_schema`,'
                . ' `code_completion_hash`, `generation_method`,'
                . ' `admin_notes`, `updated_by_user_id`, `topic_dictionary_version`,'
                . ' `visual_grading_mode`, `ordering_schema`, `ordering_hash`'
                . ' FROM `' . self::TABLE . '` WHERE `problem_id` IN ('
                . implode(', ', array_fill(0, count($ids), '%d')) . ')',
            $ids
        );
        $topicKeys = $this->topics->keysForMany($problemIds);
        $metadata = [];
        foreach ($rows as $row) {
            $problemId = new ProblemId((int) ($row['problem_id'] ?? 0));
            $metadata[$problemId->value()] = $this->hydrate(
                $problemId,
                $row,
                $topicKeys[$problemId->value()] ?? []
            );
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $topicKeys
     */
    private function hydrate(
        ProblemId $problemId,
        array $row,
        array $topicKeys
    ): ProblemMetadata {
        $mode = CodeEntryMode::tryFrom((string) ($row['code_entry_mode'] ?? ''));
        if ($mode === null) {
            throw new RuntimeException('저장된 코드 작성 방식이 올바르지 않습니다.');
        }
        $difficultyValue = $row['difficulty_score'] ?? null;

        return new ProblemMetadata(
            $problemId,
            $difficultyValue === null || $difficultyValue === ''
                ? null
                : new DifficultyScore((int) $difficultyValue),
            $topicKeys,
            $mode,
            $this->nullableString($row['code_completion_schema'] ?? null),
            $this->nullableString($row['code_completion_hash'] ?? null),
            (string) ($row['generation_method'] ?? 'manual'),
            (string) ($row['admin_notes'] ?? ''),
            (int) ($row['updated_by_user_id'] ?? 0),
            $this->nullableString($row['topic_dictionary_version'] ?? null),
            $this->nullableString($row['difficulty_source'] ?? null),
            VisualGradingMode::fromValue((string) ($row['visual_grading_mode'] ?? '')),
            $this->nullableString($row['ordering_schema'] ?? null),
            $this->nullableString($row['ordering_hash'] ?? null)
        );
    }

    public function save(ProblemMetadata $metadata): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $difficulty = $metadata->difficulty();
        $data = [
            'generation_method' => $metadata->generationMethod(),
            'difficulty_score' => $difficulty?->value(),
            'difficulty_rubric_version' => $difficulty === null
                ? null
                : DifficultyScore::RUBRIC_VERSION,
            'difficulty_source' => $metadata->difficultySource(),
            'code_entry_mode' => $metadata->codeEntryMode()->value,
            'code_completion_schema' => $metadata->completionSchema(),
            'code_completion_hash' => $metadata->completionHash(),
            'ordering_schema' => $metadata->orderingSchema(),
            'ordering_hash' => $metadata->orderingHash(),
            'visual_grading_mode' => $metadata->visualGradingMode()->value,
            'topic_dictionary_version' => $metadata->dictionaryVersion(),
            'admin_notes' => $metadata->adminNotes(),
            'updated_by_user_id' => $metadata->updatedByUserId(),
            'updated_at' => $now,
        ];
        $exists = $this->database->row(
            'SELECT `problem_id` FROM `' . self::TABLE . '` WHERE `problem_id` = %d',
            [$metadata->problemId()->value()]
        ) !== null;
        if ($exists) {
            $this->database->update(
                self::TABLE,
                $data,
                ['problem_id' => $metadata->problemId()->value()]
            );

            return;
        }

        $this->database->insert(
            self::TABLE,
            ['problem_id' => $metadata->problemId()->value()]
                + $data
                + ['created_at' => $now]
        );
    }

    public function move(ProblemId $from, ProblemId $to): void
    {
        $this->database->update(
            self::TABLE,
            ['problem_id' => $to->value(), 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['problem_id' => $from->value()],
            ['%d', '%s'],
            ['%d']
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
