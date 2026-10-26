<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Contract;

use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageReport;

interface MessageReportRepository
{
    public function save(int $messageId, int $reporterUserId, string $reason): int;

    public function find(int $id): ?MessageReport;

    public function existsForReporter(int $messageId, int $reporterUserId): bool;

    /**
     * @return list<MessageReport>
     */
    public function pendingQueue(int $limit, int $offset): array;

    public function resolve(int $reportId, int $actorUserId, string $action, string $note): void;
}
