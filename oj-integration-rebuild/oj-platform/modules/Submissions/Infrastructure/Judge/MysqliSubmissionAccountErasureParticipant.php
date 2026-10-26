<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use RuntimeException;
use Throwable;

final class MysqliSubmissionAccountErasureParticipant implements AccountErasureParticipant
{
    /** @var array<int, string> */
    private array $originalLogins = [];

    public function __construct(
        private readonly JudgeConnectionSettingsProvider $settings,
        private readonly JudgeConnector $connector
    ) {
    }

    public function moduleKey(): string
    {
        return 'submissions';
    }

    public function blockers(int $userId): array
    {
        unset($userId);
        try {
            $available = $this->settings->current() !== null;
        } catch (Throwable) {
            $available = false;
        }

        return $available
            ? []
            : [new AccountErasureBlocker(
                'submissions',
                'judge_connection_required',
                'Judge DB 연결 설정',
                1
            )];
    }

    public function anonymize(
        int $userId,
        string $currentUserLogin,
        string $anonymousUserLogin
    ): void {
        $this->originalLogins[$userId] = $currentUserLogin;
        $settings = $this->settings->current();
        if ($settings === null) {
            throw new RuntimeException('Judge DB 연결 설정이 없습니다.');
        }
        $connection = $this->connector->write($settings);
        try {
            $statement = $connection->prepare(
                'UPDATE `solution` SET `user_id` = ? WHERE `user_id` = ?'
            );
            if (! $statement instanceof \mysqli_stmt) {
                throw new RuntimeException('Judge 제출 익명화 명령을 준비하지 못했습니다.');
            }
            try {
                $statement->bind_param('ss', $anonymousUserLogin, $currentUserLogin);
                if (! $statement->execute()) {
                    throw new RuntimeException('Judge 제출 사용자 정보를 익명화하지 못했습니다.');
                }
            } finally {
                $statement->close();
            }
        } finally {
            $connection->close();
        }
    }

    public function verify(int $userId, string $anonymousUserLogin): bool
    {
        $originalLogin = $this->originalLogins[$userId] ?? $anonymousUserLogin;
        if ($originalLogin === $anonymousUserLogin) {
            return true;
        }
        $settings = $this->settings->current();
        if ($settings === null) {
            return false;
        }
        $connection = $this->connector->read($settings);
        try {
            $statement = $connection->prepare(
                'SELECT COUNT(*) FROM `solution` WHERE `user_id` = ?'
            );
            if (! $statement instanceof \mysqli_stmt) {
                return false;
            }
            try {
                $statement->bind_param('s', $originalLogin);
                if (! $statement->execute()) {
                    return false;
                }
                $count = 0;
                $statement->bind_result($count);

                return $statement->fetch() && (int) $count === 0;
            } finally {
                $statement->close();
            }
        } finally {
            $connection->close();
        }
    }
}
