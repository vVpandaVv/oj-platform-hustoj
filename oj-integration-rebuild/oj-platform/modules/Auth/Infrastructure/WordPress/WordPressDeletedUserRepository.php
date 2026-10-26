<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\DeletedUserRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\DeletedUserRecord;
use RuntimeException;

final class WordPressDeletedUserRepository implements DeletedUserRepository
{
    private const TABLE = 'deleted_users';

    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function findPage(int $limit, int $offset = 0): array
    {
        if (
            ! method_exists($this->database, 'prepare')
            || ! method_exists($this->database, 'get_results')
        ) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sql = $this->database->prepare(
            'SELECT id, user_id, snapshot, deleted_by_user_id, deleted_at, reason'
            . ' FROM `' . self::TABLE . '`'
            . ' ORDER BY deleted_at DESC, id DESC LIMIT %d OFFSET %d',
            $limit,
            $offset
        );
        $rows = $this->database->get_results($sql, 'ARRAY_A');
        if (! is_array($rows)) {
            throw new RuntimeException('삭제 사용자 기록을 읽지 못했습니다.');
        }

        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $userId = (int) ($row['user_id'] ?? 0);
            $snapshot = $this->snapshot($row['snapshot'] ?? null);
            $jobId = isset($snapshot['job_id']) && is_numeric($snapshot['job_id'])
                ? (int) $snapshot['job_id']
                : null;
            $label = isset($snapshot['anonymous_label'])
                && is_string($snapshot['anonymous_label'])
                && trim($snapshot['anonymous_label']) !== ''
                    ? trim($snapshot['anonymous_label'])
                    : '익명 사용자 ' . $userId;

            $records[] = new DeletedUserRecord(
                (int) ($row['id'] ?? 0),
                $userId,
                $label,
                (int) ($row['deleted_by_user_id'] ?? 0),
                (string) ($row['deleted_at'] ?? ''),
                $this->nullableString($row['reason'] ?? null),
                $jobId !== null && $jobId > 0 ? $jobId : null
            );
        }

        return $records;
    }

    public function hasCompleted(int $userId): bool
    {
        if (
            ! method_exists($this->database, 'prepare')
            || ! method_exists($this->database, 'get_var')
        ) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }

        return (int) $this->database->get_var($this->database->prepare(
            'SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE `user_id` = %d',
            $userId
        )) > 0;
    }

    public function recordCompleted(
        int $userId,
        int $actorUserId,
        int $jobId,
        string $reason
    ): void {
        if ($this->hasCompleted($userId)) {
            return;
        }
        if (! method_exists($this->database, 'insert')) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }
        $snapshot = json_encode(
            [
                'anonymous_label' => '익명 사용자 ' . $userId,
                'job_id' => $jobId,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $result = $this->database->insert(
            self::TABLE,
            [
                'user_id' => $userId,
                'snapshot' => $snapshot,
                'deleted_by_user_id' => $actorUserId,
                'deleted_at' => gmdate('Y-m-d H:i:s'),
                'reason' => $reason,
            ],
            ['%d', '%s', '%d', '%s', '%s']
        );
        if ($result === false) {
            throw new RuntimeException('계정 익명화 완료 기록을 저장하지 못했습니다.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
