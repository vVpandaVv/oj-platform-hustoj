<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\Judge;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyJudgeUserRecordSource;
use CodingPanda\OjPlatform\Modules\Auth\Domain\LegacyJudgeUserRecord;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use RuntimeException;
use Throwable;

final class MysqliLegacyJudgeUserRecordSource implements
    LegacyJudgeUserRecordSource
{
    public function __construct(
        private readonly JudgeConnectionSettingsProvider $settings,
        private readonly JudgeConnector $connections,
        private readonly ErrorReporter $errors
    ) {
    }

    public function findByLogin(string $userLogin): ?LegacyJudgeUserRecord
    {
        $userLogin = trim($userLogin);
        if ($userLogin === '' || strlen($userLogin) > 48) {
            return null;
        }

        $connection = null;
        $statement = null;
        try {
            $settings = $this->settings->current();
            if ($settings === null) {
                return null;
            }

            $connection = $this->connections->read($settings);
            $statement = $connection->prepare(
                'SELECT user_id, email, password, nick, school, defunct '
                . 'FROM users WHERE user_id = ? LIMIT 1'
            );
            if (! $statement instanceof \mysqli_stmt) {
                throw new RuntimeException('Judge 사용자 조회를 준비하지 못했습니다.');
            }
            if (! $statement->bind_param('s', $userLogin) || ! $statement->execute()) {
                throw new RuntimeException('Judge 사용자를 조회하지 못했습니다.');
            }

            $storedLogin = '';
            $email = '';
            $passwordHash = '';
            $displayName = '';
            $affiliation = '';
            $defunct = '';
            if (! $statement->bind_result(
                $storedLogin,
                $email,
                $passwordHash,
                $displayName,
                $affiliation,
                $defunct
            )) {
                throw new RuntimeException('Judge 사용자 조회 결과를 읽지 못했습니다.');
            }
            if (! $statement->fetch()) {
                return null;
            }

            return new LegacyJudgeUserRecord(
                (string) $storedLogin,
                (string) $email,
                (string) $passwordHash,
                (string) $displayName,
                trim((string) $affiliation) !== '' ? (string) $affiliation : null,
                in_array(strtoupper(trim((string) $defunct)), ['', 'N', '0'], true)
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return null;
        } finally {
            if ($statement instanceof \mysqli_stmt) {
                $statement->close();
            }
            if ($connection instanceof \mysqli) {
                $connection->close();
            }
        }
    }
}
