<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\Message;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageDraft;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use RuntimeException;

final class WordPressMessageRepository implements MessageRepository, \CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageInboxStatus
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function save(MessageDraft $draft): int
    {
        $id = $this->database->insert(
            'messages',
            [
                'thread_key' => $draft->threadKeyValue(),
                'sender_user_id' => $draft->senderUserId(),
                'recipient_user_id' => $draft->recipientUserId(),
                'body' => $draft->body(),
                'sent_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%d', '%d', '%s', '%s']
        );

        if ($id < 1) {
            throw new RuntimeException('쪽지를 저장하지 못했습니다.');
        }

        return $id;
    }

    public function find(int $id): ?Message
    {
        $row = $this->database->row(
            'SELECT * FROM messages WHERE id = %d LIMIT 1',
            [$id]
        );

        return $row === null ? null : Message::fromRow($row);
    }

    public function threadMessages(string $threadKey, int $viewerUserId, int $limit, int $offset): array
    {
        $rows = $this->database->rows(
            'SELECT * FROM messages WHERE thread_key = %s AND hidden_at IS NULL'
            . ' AND ((sender_user_id = %d AND sender_deleted_at IS NULL)'
            . ' OR (recipient_user_id = %d AND recipient_deleted_at IS NULL))'
            . ' ORDER BY sent_at ASC, id ASC LIMIT %d OFFSET %d',
            [$threadKey, $viewerUserId, $viewerUserId, $limit, $offset]
        );

        return array_map([Message::class, 'fromRow'], $rows);
    }

    public function inboxThreads(int $userId, int $limit, int $offset): array
    {
        $rows = $this->database->rows(
            'SELECT m.* FROM messages m INNER JOIN ('
            . ' SELECT thread_key, MAX(id) AS max_id FROM messages'
            . ' WHERE ((recipient_user_id = %d AND recipient_deleted_at IS NULL)'
            . ' OR (sender_user_id = %d AND sender_deleted_at IS NULL))'
            . ' AND hidden_at IS NULL GROUP BY thread_key'
            . ' ) latest ON latest.max_id = m.id'
            . ' ORDER BY m.sent_at DESC LIMIT %d OFFSET %d',
            [$userId, $userId, $limit, $offset]
        );

        return array_map([Message::class, 'fromRow'], $rows);
    }

    public function sent(int $userId, int $limit, int $offset): array
    {
        $rows = $this->database->rows(
            'SELECT * FROM messages WHERE sender_user_id = %d AND sender_deleted_at IS NULL'
            . ' AND hidden_at IS NULL ORDER BY sent_at DESC LIMIT %d OFFSET %d',
            [$userId, $limit, $offset]
        );

        return array_map([Message::class, 'fromRow'], $rows);
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->database->value(
            'SELECT COUNT(*) FROM messages WHERE recipient_user_id = %d'
            . ' AND recipient_deleted_at IS NULL AND hidden_at IS NULL AND read_at IS NULL',
            [$userId]
        );
    }

    public function unreadInboxThreads(int $userId, int $limit, int $offset): array
    {
        $rows = $this->database->rows(
            'SELECT m.* FROM messages m INNER JOIN ('
            . 'SELECT thread_key, MAX(id) max_id FROM messages WHERE recipient_user_id = %d'
            . ' AND recipient_deleted_at IS NULL AND hidden_at IS NULL AND read_at IS NULL GROUP BY thread_key'
            . ') unread ON unread.max_id = m.id ORDER BY m.sent_at DESC, m.id DESC LIMIT %d OFFSET %d',
            [$userId, $limit, $offset]
        );
        return array_map([Message::class, 'fromRow'], $rows);
    }

    public function unreadThreadKeys(int $userId, array $threadKeys): array
    {
        if ($threadKeys === []) { return []; }
        $rows = $this->database->rows(
            'SELECT DISTINCT thread_key FROM messages WHERE recipient_user_id = %d'
            . ' AND recipient_deleted_at IS NULL AND hidden_at IS NULL AND read_at IS NULL'
            . ' AND thread_key IN (' . implode(',', array_fill(0, count($threadKeys), '%s')) . ')',
            [$userId, ...$threadKeys]
        );
        return array_values(array_map(static fn (array $row): string => (string) $row['thread_key'], $rows));
    }

    public function markThreadRead(string $threadKey, int $viewerUserId): void
    {
        $this->database->update(
            'messages',
            ['read_at' => gmdate('Y-m-d H:i:s')],
            [
                'thread_key' => $threadKey,
                'recipient_user_id' => $viewerUserId,
            ],
            ['%s'],
            ['%s', '%d']
        );
    }

    public function softDeleteForSender(int $messageId): void
    {
        $this->database->update(
            'messages',
            ['sender_deleted_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $messageId],
            ['%s'],
            ['%d']
        );
    }

    public function softDeleteForRecipient(int $messageId): void
    {
        $this->database->update(
            'messages',
            ['recipient_deleted_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $messageId],
            ['%s'],
            ['%d']
        );
    }

    public function setHidden(int $messageId, bool $hidden, int $actorUserId, string $reason): void
    {
        $this->database->update(
            'messages',
            [
                'hidden_at' => $hidden ? gmdate('Y-m-d H:i:s') : null,
                'hidden_by_user_id' => $hidden ? $actorUserId : null,
                'hidden_reason' => $hidden ? $reason : null,
            ],
            ['id' => $messageId],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    public function adminSearch(
        int $userId,
        string $fromDate,
        string $toDate,
        int $limit,
        int $offset
    ): array {
        $rows = $this->database->rows(
            'SELECT * FROM messages WHERE (%d = 0 OR sender_user_id = %d OR recipient_user_id = %d)'
            . ' AND (%s = \'\' OR sent_at >= %s) AND (%s = \'\' OR sent_at <= %s)'
            . ' ORDER BY sent_at DESC LIMIT %d OFFSET %d',
            [$userId, $userId, $userId, $fromDate, $fromDate, $toDate, $toDate, $limit, $offset]
        );

        return array_map([Message::class, 'fromRow'], $rows);
    }

    public function adminThreadMessages(string $threadKey, int $limit, int $offset): array
    {
        $rows = $this->database->rows(
            'SELECT * FROM messages WHERE thread_key = %s'
            . ' ORDER BY sent_at ASC, id ASC LIMIT %d OFFSET %d',
            [$threadKey, $limit, $offset]
        );

        return array_map([Message::class, 'fromRow'], $rows);
    }
}
