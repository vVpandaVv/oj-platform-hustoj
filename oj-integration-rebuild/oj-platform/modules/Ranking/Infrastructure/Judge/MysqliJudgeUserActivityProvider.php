<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Ranking\Infrastructure\Judge;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\Ranking\Contract\JudgeUserActivity;
use CodingPanda\OjPlatform\Modules\Ranking\Contract\JudgeUserActivityProvider;
use RuntimeException;

/** Aggregates only the submissions eligible for the public learning ranking. */
final class MysqliJudgeUserActivityProvider implements JudgeUserActivityProvider
{
    private const ACCEPTED_RESULT = 4;
    private const FINAL_RESULT_MIN = 4;
    private const FINAL_RESULT_MAX = 11;

    /** @var array<string, list<string>> */
    private const REQUIRED_COLUMNS = [
        'solution' => [
            'user_id',
            'problem_id',
            'result',
            'contest_id',
            'valid',
        ],
        'problem' => ['problem_id', 'defunct'],
    ];

    public function __construct(
        private readonly JudgeConnectionSettingsProvider $settings,
        private readonly JudgeConnector $connector
    ) {
    }

    public function activeUsers(): array
    {
        $connection = $this->readConnection();
        try {
            $this->requireColumns($connection, 'solution');
            $this->requireColumns($connection, 'problem');
            $result = $connection->query(
                'SELECT `s`.`user_id`,'
                    . ' COUNT(*) AS `submission_count`,'
                    . ' SUM(CASE WHEN `s`.`result` = '
                    . self::ACCEPTED_RESULT
                    . ' THEN 1 ELSE 0 END) AS `accepted_submission_count`,'
                    . ' COUNT(DISTINCT CASE WHEN `s`.`result` = '
                    . self::ACCEPTED_RESULT
                    . ' THEN `s`.`problem_id` ELSE NULL END)'
                    . ' AS `solved_problem_count`'
                    . ' FROM `solution` AS `s`'
                    . ' INNER JOIN `problem` AS `p`'
                    . ' ON `p`.`problem_id` = `s`.`problem_id`'
                    . ' WHERE `s`.`valid` = 1'
                    . ' AND `s`.`contest_id` = 0'
                    . " AND `p`.`defunct` = 'N'"
                    . ' AND `s`.`result` BETWEEN '
                    . self::FINAL_RESULT_MIN . ' AND ' . self::FINAL_RESULT_MAX
                    . ' GROUP BY `s`.`user_id`'
                    . ' HAVING `solved_problem_count` > 0'
            );
            if (! $result instanceof \mysqli_result) {
                throw new RuntimeException('Judge 사용자 활동 집계를 읽지 못했습니다.');
            }
            $activity = [];
            while ($row = $result->fetch_assoc()) {
                $userLogin = trim((string) ($row['user_id'] ?? ''));
                if ($userLogin === '') {
                    continue;
                }
                $activity[] = new JudgeUserActivity(
                    $userLogin,
                    max(0, (int) ($row['submission_count'] ?? 0)),
                    max(0, (int) ($row['accepted_submission_count'] ?? 0)),
                    max(0, (int) ($row['solved_problem_count'] ?? 0))
                );
            }
            $result->free();

            return $activity;
        } finally {
            $connection->close();
        }
    }

    private function requireColumns(
        \mysqli $connection,
        string $table
    ): void {
        $result = $connection->query('SHOW COLUMNS FROM `' . $table . '`');
        if (! $result instanceof \mysqli_result) {
            throw new RuntimeException(
                'Judge ' . $table . ' 테이블을 확인하지 못했습니다.'
            );
        }
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[] = $field;
            }
        }
        $result->free();
        $missing = array_values(array_diff(
            self::REQUIRED_COLUMNS[$table] ?? [],
            $columns
        ));
        if ($missing !== []) {
            throw new RuntimeException(
                'Judge ' . $table . ' 테이블에 필요한 열이 없습니다: '
                    . implode(', ', $missing)
            );
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
}
