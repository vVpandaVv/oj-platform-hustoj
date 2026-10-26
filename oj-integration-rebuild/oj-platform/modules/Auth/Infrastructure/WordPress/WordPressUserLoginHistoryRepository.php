<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserLoginHistoryRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserLoginRecord;
use RuntimeException;

final class WordPressUserLoginHistoryRepository implements UserLoginHistoryRepository
{
    private const TABLE = 'user_login_log';

    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function record(int $userId, ?string $ipAddress, ?string $userAgent): void
    {
        $result = $this->database()->insert(
            self::TABLE,
            [
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'logged_in_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%s', '%s', '%s']
        );

        if ($result === false) {
            throw new RuntimeException('로그인 기록을 저장하지 못했습니다.');
        }
    }

    public function findForUser(int $userId, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $database = $this->database();
        $rows = $database->get_results(
            $database->prepare(
                'SELECT id, user_id, ip_address, user_agent, logged_in_at'
                . ' FROM `' . self::TABLE . '` WHERE user_id = %d'
                . ' ORDER BY logged_in_at DESC LIMIT %d OFFSET %d',
                $userId,
                $limit,
                $offset
            ),
            'ARRAY_A'
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_map(
            static fn (array $row): UserLoginRecord => UserLoginRecord::fromRow($row),
            array_values(array_filter($rows, 'is_array'))
        );
    }

    public function countForUser(int $userId): int
    {
        $database = $this->database();
        $count = $database->get_var($database->prepare(
            'SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE user_id = %d',
            $userId
        ));

        return (int) $count;
    }

    private function database(): object
    {
        if (
            ! method_exists($this->database, 'prepare')
            || ! method_exists($this->database, 'get_results')
        ) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }

        return $this->database;
    }
}
