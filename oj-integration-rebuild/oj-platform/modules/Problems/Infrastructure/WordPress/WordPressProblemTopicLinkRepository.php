<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicLinkRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

final class WordPressProblemTopicLinkRepository implements ProblemTopicLinkRepository
{
    private const TABLE = 'problem_topic_links';

    public function __construct(private readonly ProblemPlatformDatabase $database)
    {
    }

    public function keysFor(ProblemId $problemId): array
    {
        $rows = $this->database->rows(
            'SELECT `topic_key` FROM `' . self::TABLE . '`'
                . ' WHERE `problem_id` = %d ORDER BY `sort_order` ASC',
            [$problemId->value()]
        );

        return array_values(array_map(
            static fn (array $row): string => (string) ($row['topic_key'] ?? ''),
            $rows
        ));
    }

    public function keysForMany(array $problemIds): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (ProblemId $problemId): int => $problemId->value(),
            $problemIds
        )));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->database->rows(
            'SELECT `problem_id`, `topic_key` FROM `' . self::TABLE . '`'
                . ' WHERE `problem_id` IN (' . $placeholders . ')'
                . ' ORDER BY `problem_id`, `sort_order`',
            $ids
        );
        $grouped = [];
        foreach ($rows as $row) {
            $problemId = (int) ($row['problem_id'] ?? 0);
            $topicKey = (string) ($row['topic_key'] ?? '');
            if ($problemId > 0 && $topicKey !== '') {
                $grouped[$problemId][] = $topicKey;
            }
        }

        return $grouped;
    }

    public function replace(
        ProblemId $problemId,
        array $topicKeys,
        string $dictionaryVersion
    ): void {
        $this->database->delete(
            self::TABLE,
            ['problem_id' => $problemId->value()],
            ['%d']
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach (array_values($topicKeys) as $order => $topicKey) {
            $this->database->insert(
                self::TABLE,
                [
                    'problem_id' => $problemId->value(),
                    'topic_key' => $topicKey,
                    'sort_order' => $order,
                    'dictionary_version' => $dictionaryVersion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%d', '%s', '%s', '%s']
            );
        }
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
}
