<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageReportRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageReport;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use RuntimeException;

final class WordPressMessageReportRepository implements MessageReportRepository
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function save(int $messageId, int $reporterUserId, string $reason): int
    {
        $id = $this->database->insert(
            'message_reports',
            [
                'message_id' => $messageId,
                'reporter_user_id' => $reporterUserId,
                'reason' => $reason,
                'status' => 'pending',
                'reported_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );

        if ($id < 1) {
            throw new RuntimeException('신고를 저장하지 못했습니다.');
        }

        return $id;
    }

    public function find(int $id): ?MessageReport
    {
        $row = $this->database->row(
            'SELECT * FROM message_reports WHERE id = %d LIMIT 1',
            [$id]
        );

        return $row === null ? null : MessageReport::fromRow($row);
    }

    public function existsForReporter(int $messageId, int $reporterUserId): bool
    {
        $row = $this->database->row(
            'SELECT id FROM message_reports WHERE message_id = %d AND reporter_user_id = %d LIMIT 1',
            [$messageId, $reporterUserId]
        );

        return $row !== null;
    }

    public function pendingQueue(int $limit, int $offset): array
    {
        $rows = $this->database->rows(
            'SELECT * FROM message_reports WHERE status = %s'
            . ' ORDER BY reported_at ASC LIMIT %d OFFSET %d',
            ['pending', $limit, $offset]
        );

        return array_map([MessageReport::class, 'fromRow'], $rows);
    }

    public function resolve(int $reportId, int $actorUserId, string $action, string $note): void
    {
        $this->database->update(
            'message_reports',
            [
                'status' => $action === 'none' ? 'dismissed' : 'resolved',
                'resolved_at' => gmdate('Y-m-d H:i:s'),
                'resolved_by_user_id' => $actorUserId,
                'resolution_action' => $action,
                'resolution_note' => $note === '' ? null : $note,
            ],
            ['id' => $reportId],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );
    }
}
