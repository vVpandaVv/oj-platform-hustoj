<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Contract;

use CodingPanda\OjPlatform\Modules\Messaging\Domain\Message;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageDraft;

interface MessageRepository
{
    public function save(MessageDraft $draft): int;

    public function find(int $id): ?Message;

    /**
     * @return list<Message>
     */
    public function threadMessages(string $threadKey, int $viewerUserId, int $limit, int $offset): array;

    /**
     * @return list<Message>
     */
    public function inboxThreads(int $userId, int $limit, int $offset): array;

    /**
     * @return list<Message>
     */
    public function sent(int $userId, int $limit, int $offset): array;

    public function unreadCount(int $userId): int;

    public function markThreadRead(string $threadKey, int $viewerUserId): void;

    public function softDeleteForSender(int $messageId): void;

    public function softDeleteForRecipient(int $messageId): void;

    public function setHidden(int $messageId, bool $hidden, int $actorUserId, string $reason): void;

    /**
     * @return list<Message>
     */
    public function adminSearch(
        int $userId,
        string $fromDate,
        string $toDate,
        int $limit,
        int $offset
    ): array;

    /**
     * @return list<Message>
     */
    public function adminThreadMessages(string $threadKey, int $limit, int $offset): array;
}
