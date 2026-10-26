<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\UserSubmissionProfileProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\UserSolvedProblem;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\UserSubmissionProfile;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DateTimeZone;
use DateTimeImmutable;
use RuntimeException;

final class MysqliUserSubmissionProfileProvider implements UserSubmissionProfileProvider
{
    public function __construct(
        private readonly JudgeConnectionSettingsProvider $settings,
        private readonly JudgeConnector $connector,
        private readonly Clock $clock
    ) {
    }

    public function find(string $userLogin, int $recentLimit = 5): UserSubmissionProfile
    {
        $userLogin = trim($userLogin);
        if ($userLogin === '') {
            throw new \InvalidArgumentException('제출 프로필 로그인 아이디가 비어 있습니다.');
        }
        $recentLimit = max(1, min(10, $recentLimit));
        $now = $this->clock->now();
        $recentCutoff = $now
            ->modify('-30 days')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $today = $now->format('Y-m-d');
        $connection = $this->readConnection();
        try {
            $summary = $this->row(
                $connection,
                'SELECT COUNT(*) AS `total_submissions`'
                    . ', COALESCE(SUM(CASE WHEN `result` = 4 THEN 1 ELSE 0 END), 0)'
                    . ' AS `accepted_submissions`'
                    . ', COUNT(DISTINCT CASE WHEN `result` = 4 THEN `problem_id` END)'
                    . ' AS `solved_problems`'
                    . ', COALESCE(SUM(CASE WHEN `result` BETWEEN 4 AND 11'
                    . ' AND `in_date` >= ? THEN 1 ELSE 0 END), 0)'
                    . ' AS `recent_submissions`'
                    . ', COALESCE(SUM(CASE WHEN `result` = 4 AND `in_date` >= ?'
                    . ' THEN 1 ELSE 0 END), 0)'
                    . ' AS `recent_accepted_submissions`'
                    . ' FROM `solution` WHERE `user_id` = ? AND `valid` = 1',
                'sss',
                [$recentCutoff, $recentCutoff, $userLogin]
            ) ?? [];
            $recentRows = $this->rows(
                $connection,
                'SELECT `problem_id`, MAX(`in_date`) AS `solved_at`'
                    . ' FROM `solution` WHERE `user_id` = ?'
                    . ' AND `valid` = 1 AND `result` = 4'
                    . ' GROUP BY `problem_id` ORDER BY `solved_at` DESC LIMIT ?',
                'si',
                [$userLogin, $recentLimit]
            );
            $acceptedDates = $this->acceptedDates($connection, $userLogin, $now);

            return new UserSubmissionProfile(
                (int) ($summary['total_submissions'] ?? 0),
                (int) ($summary['accepted_submissions'] ?? 0),
                (int) ($summary['solved_problems'] ?? 0),
                (int) ($summary['recent_submissions'] ?? 0),
                (int) ($summary['recent_accepted_submissions'] ?? 0),
                $today,
                array_map(
                    static fn (array $row): UserSolvedProblem => new UserSolvedProblem(
                        (int) ($row['problem_id'] ?? 0),
                        (string) ($row['solved_at'] ?? '')
                    ),
                    $recentRows
                ),
                $acceptedDates
            );
        } finally {
            $connection->close();
        }
    }

    /** @return list<string> */
    private function acceptedDates(\mysqli $connection, string $userLogin, DateTimeImmutable $now): array
    {
        $statement = $connection->prepare(
            'SELECT DISTINCT `in_date` FROM `solution` WHERE `user_id` = ?'
                . ' AND `valid` = 1 AND `result` = 4 ORDER BY `in_date` DESC'
        );
        if (! $statement instanceof \mysqli_stmt) {
            throw new RuntimeException('학습 활동 날짜 조회를 준비하지 못했습니다.');
        }
        try {
            if (! $statement->bind_param('s', $userLogin) || ! $statement->execute()) {
                throw new RuntimeException('학습 활동 날짜를 조회하지 못했습니다.');
            }
            $value = null;
            if (! $statement->bind_result($value)) {
                throw new RuntimeException('학습 활동 날짜 결과를 연결하지 못했습니다.');
            }
            $days = [];
            $utc = new DateTimeZone('UTC');
            // Stream UTC records and use PHP's historical timezone rules, independent of MySQL tz tables.
            while (($fetched = $statement->fetch()) === true) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $value, $utc);
                if ($date !== false && $date->format('Y-m-d H:i:s') === $value) {
                    $days[$date->setTimezone($now->getTimezone())->format('Y-m-d')] = true;
                }
                if (count($days) >= 3660) {
                    break;
                }
            }
            if ($fetched === false) {
                throw new RuntimeException('학습 활동 날짜 결과를 읽지 못했습니다.');
            }
            $result = array_keys($days);
            rsort($result);
            return $result;
        } finally {
            $statement->close();
        }
    }

    private function readConnection(): \mysqli
    {
        $settings = $this->settings->current();
        if ($settings === null) {
            throw new RuntimeException('Judge DB 연결 설정을 먼저 완료해 주세요.');
        }

        return $this->connector->read($settings);
    }

    /** @return array<string, mixed>|null */
    private function row(
        \mysqli $connection,
        string $sql,
        string $types,
        array $values
    ): ?array {
        return $this->rows($connection, $sql, $types, $values)[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function rows(
        \mysqli $connection,
        string $sql,
        string $types,
        array $values
    ): array {
        $statement = $connection->prepare($sql);
        if (! $statement instanceof \mysqli_stmt) {
            throw new RuntimeException('Judge 제출 프로필 조회를 준비하지 못했습니다.');
        }
        if ($values !== []) {
            $references = [];
            foreach ($values as $index => $value) {
                $references[$index] = &$values[$index];
            }
            if (! $statement->bind_param($types, ...$references)) {
                $statement->close();
                throw new RuntimeException('Judge 제출 프로필 조회 값을 연결하지 못했습니다.');
            }
        }
        if (! $statement->execute()) {
            $message = $statement->error;
            $statement->close();
            throw new RuntimeException(
                'Judge 제출 프로필을 조회하지 못했습니다.'
                    . ($message === '' ? '' : ' ' . $message)
            );
        }
        $result = $statement->get_result();
        if (! $result instanceof \mysqli_result) {
            $statement->close();
            throw new RuntimeException('Judge 제출 프로필 결과를 읽지 못했습니다.');
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $statement->close();

        return $rows;
    }
}
