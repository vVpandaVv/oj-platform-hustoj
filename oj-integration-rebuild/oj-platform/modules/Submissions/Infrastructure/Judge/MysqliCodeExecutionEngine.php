<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine;
use RuntimeException;
use Throwable;

/** Native HUSTOJ custominput runs. No learner account, problem, or context links. */
final class MysqliCodeExecutionEngine implements CodeExecutionEngine, \CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionMaintenance
{
    public function __construct(private readonly JudgeConnectionSettingsProvider $settings,
        private readonly JudgeConnector $connector, private readonly ?\Closure $limits = null) {}

    public function isAvailable(): bool
    {
        try {
            $connection = $this->connection();
            try {
                // custominput is optional for normal judging; require it for this feature.
                $this->row($connection, 'SELECT `solution_id`, `input_text` FROM `custominput` LIMIT 0');
                return true;
            } finally { $connection->close(); }
        } catch (Throwable) { return false; }
    }

    public function submit(string $identity, int $languageId, string $source, string $input): int
    {
        $this->identity($identity);
        $connection = $this->connection();
        $lock = $this->lockName($identity);
        $locked = false;
        try {
            $locked = (int) ($this->row($connection, 'SELECT GET_LOCK(?, 0) AS `locked`', [$lock])['locked'] ?? 0) === 1;
            if (! $locked) { throw new RuntimeException('검증 요청을 처리 중입니다. 잠시 후 다시 확인해 주세요.'); }
            $this->cleanup($connection);
            $row = $this->row($connection, 'SELECT `solution_id`, `result` FROM `solution`'
                . ' WHERE `user_id` = ? AND `problem_id` = 0 AND `valid` = 0 AND `contest_id` = 0 ORDER BY `solution_id` DESC LIMIT 1', [$identity]);
            $id = (int) ($row['solution_id'] ?? 0);
            if ($id > 0 && (int) $row['result'] !== 14) { return $id; }
            if ($id < 1) {
                $admission='ojv:admission';
                if((int)($this->row($connection,'SELECT GET_LOCK(?, 0) AS `locked`',[$admission])['locked']??0)!==1){
                    throw new \CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionBusy('OJ 실습 실행을 준비 중입니다. 잠시 후 다시 시도해 주세요.');
                }
                try {
                    $this->admit($connection,$identity);
                $this->execute($connection, 'INSERT INTO `solution` (`problem_id`,`user_id`,`time`,`memory`,`in_date`,`result`,`language`,`ip`,`contest_id`,`valid`,`num`,`code_length`)'
                    . ' VALUES (0, ?, 0, 0, CURRENT_TIMESTAMP, 14, ?, ?, 0, 0, -1, ?)', [$identity, $languageId, '127.0.0.1', strlen($source)]);
                $id = (int) ($this->row($connection, 'SELECT LAST_INSERT_ID() AS `id`')['id'] ?? 0);
                if ($id < 1) { throw new RuntimeException('검증 실행 번호를 준비하지 못했습니다.'); }
                } finally { $this->row($connection,'SELECT RELEASE_LOCK(?) AS `released`',[$admission]); }
            }
            // A private MC(14) row remains undispatchable even if judged restarts.
            // This also recovers a PHP crash between individual MyISAM writes.
            foreach (['source_code', 'source_code_user', 'custominput'] as $table) {
                $this->execute($connection, 'DELETE FROM `' . $table . '` WHERE `solution_id` = ?', [$id]);
            }
            $this->execute($connection, 'INSERT INTO `source_code` (`solution_id`,`source`) VALUES (?,?)', [$id, $source]);
            $this->execute($connection, 'INSERT INTO `source_code_user` (`solution_id`,`source`) VALUES (?,?)', [$id, $source]);
            $this->execute($connection, 'INSERT INTO `custominput` (`solution_id`,`input_text`) VALUES (?,?)', [$id, $input]);
            $written = $this->row($connection, 'SELECT `s`.`source`, `i`.`input_text` FROM `source_code` AS `s`'
                . ' JOIN `custominput` AS `i` ON `i`.`solution_id` = `s`.`solution_id` WHERE `s`.`solution_id` = ?', [$id]);
            if (($written['source'] ?? null) !== $source || ($written['input_text'] ?? null) !== $input) {
                throw new RuntimeException('검증 코드와 입력의 저장 결과가 일치하지 않습니다.');
            }
            $this->execute($connection, 'UPDATE `solution` SET `result` = 0, `in_date` = CURRENT_TIMESTAMP WHERE `solution_id` = ? AND `result` = 14'
                . ' AND `user_id` = ? AND `problem_id` = 0 AND `valid` = 0', [$id, $identity]);
            $published = $this->row($connection, 'SELECT `solution_id` FROM `solution` WHERE `solution_id` = ?'
                . ' AND `user_id` = ? AND `problem_id` = 0 AND `valid` = 0 AND `result` <> 14', [$id, $identity]);
            if ($published === null) { throw new RuntimeException('검증 실행을 공개하지 못했습니다.'); }
            return $id;
        } finally {
            if ($locked) { try { $this->row($connection, 'SELECT RELEASE_LOCK(?) AS `released`', [$lock]); } catch (Throwable) {} }
            $connection->close();
        }
    }

    public function poll(int $id, string $identity): ?array
    {
        $this->identity($identity);
        $connection = $this->connection();
        try {
            $row = $this->row($connection, 'SELECT `s`.`result`, TIMESTAMPDIFF(SECOND, `s`.`in_date`, CURRENT_TIMESTAMP) AS `age_seconds`,'
                . ' COALESCE(`r`.`error`, \'\') AS `output`, COALESCE(`c`.`error`, \'\') AS `compile_error` FROM `solution` AS `s`'
                . ' LEFT JOIN `runtimeinfo` AS `r` ON `r`.`solution_id` = `s`.`solution_id`'
                . ' LEFT JOIN `compileinfo` AS `c` ON `c`.`solution_id` = `s`.`solution_id`'
                . ' WHERE `s`.`solution_id` = ? AND `s`.`user_id` = ? AND `s`.`problem_id` = 0 AND `s`.`valid` = 0 AND `s`.`contest_id` = 0', [$id, $identity]);
            if ($row === null) { return null; }
            return ['result' => (int) $row['result'], 'age_seconds' => max(0, (int) $row['age_seconds']),
                'output' => (string) $row['output'], 'compile_error' => (string) $row['compile_error']];
        } finally { $connection->close(); }
    }

    public function erase(string $identityPrefix): void
    {
        $this->prefix($identityPrefix);
        $connection = $this->connection();
        try {
            $rows = $this->rows($connection, 'SELECT `solution_id`, `user_id` FROM `solution` WHERE `problem_id` = 0 AND `valid` = 0'
                . ' AND `contest_id` = 0 AND LEFT(`user_id`, 15) = ?', [$identityPrefix]);
            foreach ($rows as $row) {
                if (! $this->delete($connection, (int) $row['solution_id'], (string) $row['user_id'])) {
                    throw new RuntimeException('진행 중인 OJ 예제 검증이 끝난 뒤 계정 삭제를 다시 시도해 주세요.');
                }
            }
        } finally { $connection->close(); }
    }

    public function hasRecords(string $identityPrefix): bool
    {
        $this->prefix($identityPrefix);
        $connection = $this->connection();
        try { return $this->row($connection, 'SELECT `solution_id` FROM `solution` WHERE `problem_id` = 0 AND `valid` = 0'
            . ' AND `contest_id` = 0 AND LEFT(`user_id`, 15) = ? LIMIT 1', [$identityPrefix]) !== null;
        } finally { $connection->close(); }
    }

    private function cleanup(\mysqli $connection): void
    {
        // Only our exact pseudonym shape, non-problem, invalid runs qualify.
        // One-day terminal retention. Pending workers keep their ownership row
        // until they finish so they cannot recreate ownerless runtimeinfo logs.
        $rows = $this->rows($connection, 'SELECT `solution_id`, `user_id` FROM `solution` WHERE `problem_id` = 0 AND `valid` = 0 AND `contest_id` = 0'
            . " AND `user_id` REGEXP '^ojv[a-f0-9]{12}_[a-f0-9]{16}$'"
            . ' AND `result` IN (11,13,14)'
            . ' AND `in_date` < CURRENT_TIMESTAMP - INTERVAL 1 DAY ORDER BY `solution_id` LIMIT 100');
        foreach ($rows as $row) { $this->delete($connection, (int) $row['solution_id'], (string) $row['user_id']); }
    }

    public function housekeeping():array
    {
        $connection=$this->connection();
        try{$this->cleanup($connection);return $this->statusOn($connection);}
        finally{$connection->close();}
    }
    public function status():array
    {
        $connection=$this->connection();
        try{return $this->statusOn($connection);}finally{$connection->close();}
    }
    private function privateWhere():string
    {
        return " `problem_id`=0 AND `valid`=0 AND `contest_id`=0 AND `user_id` REGEXP '^ojv[a-f0-9]{12}_[a-f0-9]{16}$'";
    }
    private function statusOn(\mysqli $connection):array
    {
        $where=$this->privateWhere();
        $total=(int)($this->row($connection,'SELECT COUNT(*) AS n FROM `solution` WHERE'.$where)['n']??0);
        $pending=(int)($this->row($connection,'SELECT COUNT(*) AS n FROM `solution` WHERE'.$where.' AND `result` IN (0,1,2,3,12,14)')['n']??0);
        $stalled=(int)($this->row($connection,'SELECT COUNT(*) AS n FROM `solution` WHERE'.$where.' AND `result` IN (0,1,2,3,12)'
            .' AND `in_date` < CURRENT_TIMESTAMP - INTERVAL 5 MINUTE')['n']??0);
        return ['total'=>$total,'pending'=>$pending,'stalled'=>$stalled,'checked_at'=>gmdate('Y-m-d H:i:s')];
    }
    private function admit(\mysqli $connection,string $identity):void
    {
        if($this->limits===null){return;} // Legacy isolated adapters; production always supplies policy.
        $limits=($this->limits)();
        $where=$this->privateWhere();
        $global=(int)($this->row($connection,'SELECT COUNT(*) AS n FROM `solution` WHERE'.$where.' AND `result` IN (0,1,2,3,12,14)')['n']??0);
        $prefix=substr($identity,0,15);
        $actor=(int)($this->row($connection,'SELECT COUNT(*) AS n FROM `solution` WHERE'.$where.' AND LEFT(`user_id`,15)=? AND `result` IN (0,1,2,3,12,14)',[$prefix])['n']??0);
        $hour=(int)($this->row($connection,'SELECT COUNT(*) AS n FROM `solution` WHERE'.$where.' AND LEFT(`user_id`,15)=?'
            .' AND `in_date` >= CURRENT_TIMESTAMP - INTERVAL 1 HOUR',[$prefix])['n']??0);
        if($global>=($limits['global_pending']??8)||$actor>=($limits['actor_pending']??2)||$hour>=($limits['actor_hourly']??120)){
            throw new \CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionBusy('OJ 실습 실행 한도에 도달했습니다. 진행 중인 실행이 끝나거나 요청 한도가 회복된 뒤 다시 시도해 주세요.');
        }
    }

    private function delete(\mysqli $connection, int $id, string $identity): bool
    {
        $this->identity($identity);
        $lock = $this->lockName($identity);
        if ((int) ($this->row($connection, 'SELECT GET_LOCK(?, 0) AS `locked`', [$lock])['locked'] ?? 0) !== 1) { return false; }
        try {
            $row = $this->row($connection, 'SELECT `result` FROM `solution` WHERE `solution_id` = ? AND `user_id` = ?'
                . ' AND `problem_id` = 0 AND `valid` = 0 AND `contest_id` = 0', [$id, $identity]);
            if ($row === null) { return true; }
            // 14 is our never-dispatched preparation state; every other allowed
            // state is terminal and native log writes precede its result update.
            if (! in_array((int) $row['result'], [11,13,14], true)) { return false; }
            foreach (['source_code', 'source_code_user', 'custominput', 'compileinfo', 'runtimeinfo', 'solution'] as $table) {
                $this->execute($connection, 'DELETE FROM `' . $table . '` WHERE `solution_id` = ?', [$id]);
            }
            return true;
        } finally {
            try { $this->row($connection, 'SELECT RELEASE_LOCK(?) AS `released`', [$lock]); } catch (Throwable) {}
        }
    }

    private function lockName(string $identity): string { return substr('ojv:' . hash('sha256', $identity), 0, 64); }

    private function identity(string $identity): void
    {
        if (preg_match('/^ojv[a-f0-9]{12}_[a-f0-9]{16}$/D', $identity) !== 1) { throw new RuntimeException('Invalid execution identity.'); }
    }
    private function prefix(string $prefix): void
    {
        if (preg_match('/^ojv[a-f0-9]{12}$/D', $prefix) !== 1) { throw new RuntimeException('Invalid execution identity prefix.'); }
    }
    private function connection(): \mysqli
    {
        $settings = $this->settings->current();
        if ($settings === null) { throw new RuntimeException('기존 OJ 채점 연결을 설정해 주세요.'); }
        return $this->connector->write($settings);
    }
    /** @param list<int|string> $values */
    private function execute(\mysqli $connection, string $sql, array $values): void
    {
        $statement = $this->statement($connection, $sql, $values); $statement->close();
    }
    /** @param list<int|string> $values @return array<string,mixed>|null */
    private function row(\mysqli $connection, string $sql, array $values = []): ?array
    {
        return $this->rows($connection, $sql, $values)[0] ?? null;
    }
    /** @param list<int|string> $values @return list<array<string,mixed>> */
    private function rows(\mysqli $connection, string $sql, array $values = []): array
    {
        $statement = $this->statement($connection, $sql, $values);
        try {
            $result = $statement->get_result();
            if (! $result instanceof \mysqli_result) { throw new RuntimeException('검증 결과를 읽지 못했습니다.'); }
            $rows = []; while ($row = $result->fetch_assoc()) { $rows[] = $row; } $result->free(); return $rows;
        } finally { $statement->close(); }
    }
    /** @param list<int|string> $values */
    private function statement(\mysqli $connection, string $sql, array $values): \mysqli_stmt
    {
        $statement = $connection->prepare($sql);
        if (! $statement instanceof \mysqli_stmt) { throw new RuntimeException('검증 명령을 준비하지 못했습니다.'); }
        try {
            if ($values !== []) {
                $types = ''; $refs = [];
                foreach ($values as $index => $value) { $types .= is_int($value) ? 'i' : 's'; $refs[$index] = &$values[$index]; }
                if (! $statement->bind_param($types, ...$refs)) { throw new RuntimeException('검증 값을 연결하지 못했습니다.'); }
            }
            if (! $statement->execute()) { throw new RuntimeException('검증 명령을 실행하지 못했습니다.'); }
            return $statement;
        } catch (Throwable $error) { $statement->close(); throw $error; }
    }
}
