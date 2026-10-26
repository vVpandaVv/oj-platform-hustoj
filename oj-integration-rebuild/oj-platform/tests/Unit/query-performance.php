<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Judge\MysqliProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemSearch;
use CodingPanda\OjPlatform\Modules\Classes\Infrastructure\WordPress\WordPressClassRepository;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

$root = dirname(__DIR__, 2);
require_once $root . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($root);
$checks = 0;
function queryCheck(bool $condition, string $message): void
{
    global $checks;
    if (! $condition) { throw new RuntimeException($message); }
    ++$checks;
}

// Execute production prepared SQL, using SQLite only as the isolated data fixture.
final class QueryResult extends mysqli_result
{
    private int $position = 0;
    public function __construct(private array $data) {}
    public function fetch_assoc(): array|null|false { return $this->data[$this->position++] ?? null; }
    public function free(): void {}
}
final class QueryStatement extends mysqli_stmt
{
    private array $values = [];
    private array $data = [];
    public function __construct(private PDO $db, private string $sql) {}
    public function bind_param(string $types, mixed &...$vars): bool { $this->values = $vars; return true; }
    public function execute(?array $params = null): bool
    {
        $statement = $this->db->prepare($this->sql);
        $statement->execute($params ?? $this->values);
        $this->data = $statement->fetchAll(PDO::FETCH_ASSOC);
        return true;
    }
    public function get_result(): mysqli_result|false { return new QueryResult($this->data); }
    public function close(): true { return true; }
}
final class QueryConnection extends mysqli
{
    public array $queries = [];
    public function __construct(private PDO $db) {}
    public function prepare(string $query): mysqli_stmt|false
    {
        $this->queries[] = $query;
        return new QueryStatement($this->db, $query);
    }
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->queries[] = $query;
        if (preg_match('/^SHOW COLUMNS FROM `([a-z_]+)`$/', $query, $match) === 1) {
            return new QueryResult(array_map(static fn (array $row): array => ['Field' => $row['name']],
                $this->db->query('PRAGMA table_info(' . $match[1] . ')')->fetchAll(PDO::FETCH_ASSOC)));
        }
        throw new LogicException('Unexpected fixture SQL: ' . $query);
    }
    public function close(): true { return true; }
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE solution(problem_id INTEGER, valid INTEGER, result INTEGER, class_id INTEGER, contest_id INTEGER)');
$pdo->exec('INSERT INTO solution VALUES (1,1,4,NULL,NULL),(1,1,4,7,NULL),(1,1,6,NULL,8),(1,0,4,NULL,NULL),(2,0,4,NULL,NULL),(3,1,4,NULL,NULL)');
$connection = new QueryConnection($pdo);
$reflection = new ReflectionClass(MysqliProblemGateway::class);
$gateway = $reflection->newInstanceWithoutConstructor();
$count = $reflection->getMethod('withCounters');
$rows = $count->invoke($gateway, $connection, [['problem_id' => 1], ['problem_id' => 2]]);
queryCheck($rows[0]['accepted'] === 2 && $rows[0]['submit'] === 3, '일반·수업·대회의 유효 제출을 함께 집계해야 합니다.');
queryCheck($rows[1]['accepted'] === 0 && $rows[1]['submit'] === 0, '유효 제출이 없는 문제는 정답·제출 모두 0이어야 합니다.');
queryCheck(count($connection->queries) === 1, '두 문제 통계를 하나의 묶음 SQL로 조회해야 합니다.');
$page = array_map(static fn (int $id): array => ['problem_id' => $id], range(1, 50));
$count->invoke($gateway, $connection, $page);
queryCheck(count($connection->queries) === 2, '50개 문제도 통계 SQL은 한 번만 실행해야 합니다.');
$count->invoke($gateway, $connection, []);
queryCheck(count($connection->queries) === 2, '빈 목록에는 집계 SQL이 없어야 합니다.');
$select = $reflection->getMethod('selectColumns');
queryCheck(str_contains($select->invoke($gateway, [], true), "'' AS `description`"), '목록에는 긴 본문을 읽지 않아야 합니다.');
queryCheck(! str_contains($select->invoke($gateway, [], false), "'' AS `description`"), '상세 조회 본문은 유지해야 합니다.');
[$where, $types, $values] = $reflection->getMethod('where')->invoke($gateway, new ProblemSearch('1101'));
queryCheck(str_contains($where, '`problem_id` = ?') && ! str_contains($where, 'CAST') && in_array(1101, $values, true), '숫자 검색은 인덱스 사용이 가능한 정확한 ID 검색이어야 합니다.');

final class QueryApplicationDatabase implements ApplicationDatabase
{
    public int $reads = 0;
    public function __construct(public PDO $pdo) {}
    public function rows(string $sql, array $values = []): array
    {
        ++$this->reads;
        $statement = $this->pdo->prepare(preg_replace('/%[ds]/', '?', $sql));
        $statement->execute($values);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function row(string $sql, array $values = []): ?array { return $this->rows($sql, $values)[0] ?? null; }
    public function value(string $sql, array $values = []): mixed { return array_values($this->row($sql, $values) ?? [null])[0]; }
    public function insert(string $table, array $data, array $formats = []): int { throw new LogicException('Read fixture'); }
    public function update(string $table, array $data, array $where, array $formats = [], array $whereFormats = []): int { throw new LogicException('Read fixture'); }
    public function delete(string $table, array $where, array $whereFormats = []): int { throw new LogicException('Read fixture'); }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
}
$pdo->exec('CREATE TABLE classes(id INTEGER PRIMARY KEY, visibility TEXT, owner_user_id INTEGER, enabled INTEGER, deleted_at TEXT, starts_at TEXT, ends_at TEXT)');
$pdo->exec('CREATE TABLE class_enrollments(id INTEGER PRIMARY KEY, class_id INTEGER, user_id INTEGER, status TEXT)');
$pdo->exec('CREATE TABLE class_instructors(id INTEGER PRIMARY KEY, class_id INTEGER, user_id INTEGER, status TEXT)');
$pdo->exec('CREATE TABLE class_group_links(class_id INTEGER, group_id INTEGER)');
$insert = $pdo->prepare('INSERT INTO classes VALUES (?,?,?,1,NULL,NULL,NULL)');
foreach ([[1,'public',9],[2,'private',9],[3,'private',1],[4,'group_only',9],[5,'private',9],[6,'public',9]] as $fixture) { $insert->execute($fixture); }
$pdo->exec("UPDATE classes SET ends_at='2020-01-01 00:00:00' WHERE id=6");
$pdo->exec('INSERT INTO class_group_links VALUES(4,10)');
$pdo->exec("INSERT INTO class_enrollments VALUES(1,5,1,'active')");
$db = new QueryApplicationDatabase($pdo);
$clock = new class implements Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-21'); } };
$classes = new WordPressClassRepository($db, $clock);
$ids = static fn (array $items): array => array_map(static fn ($item): int => $item->id(), $items);
queryCheck($ids($classes->homeFor(1, [10], false, false, '2026-10-21 00:00:00', 8)) === [5,3,1,4], '홈은 직접 참여 공간을 먼저 표시하고 비공개·종료 수업을 제외해야 합니다.');
queryCheck($ids($classes->homeFor(1, [10], false, false, '2026-10-21 00:00:00', 2)) === [5,3], '홈 수업 조회는 필요한 개수만 반환해야 합니다.');
queryCheck($ids($classes->homeFor(1, [], true, false, '2026-10-21 00:00:00', 8)) === [3], '담당 홈은 다른 사람의 수업을 노출하면 안 됩니다.');
queryCheck($ids($classes->homeFor(1, [], true, true, '2026-10-21 00:00:00', 8)) === [5,4,3,2,1], '전체 관리자는 운영 중인 수업을 확인할 수 있어야 합니다.');
queryCheck($db->reads === 4, '홈 수업 목록은 요청마다 한 번만 조회해야 합니다.');
$insert->execute([7, 'password', 9]);
queryCheck(in_array(7, $ids($classes->visibleFor(1)), true), '비밀번호 수업도 학생의 수업 목록에 표시해야 합니다.');
queryCheck(in_array(7, $ids($classes->homeFor(1, [], false, false, '2026-10-21 00:00:00', 8)), true), '홈에서도 비밀번호 수업의 참가 화면을 찾을 수 있어야 합니다.');
$schema = require $root . '/config/messaging-schema.php';
$unread = array_values(array_filter($schema[0]['indexes'], static fn (array $index): bool => ($index['name'] ?? '') === 'ix_messages_unread'))[0];
queryCheck($schema[0]['schema_version'] === '2' && $unread['columns'] === ['recipient_user_id','read_at','recipient_deleted_at','hidden_at','thread_key'], '미읽음 집계 인덱스는 플랫폼 스키마에 포함되어야 합니다.');
// Learner context discovery must not scan visible public classes one by one.
$beforeLearning = $db->reads;
queryCheck($ids($classes->learningFor(1, [10])) === [4,5], '학습 문맥은 실제 참여 또는 연결 그룹 수업만 포함해야 합니다.');
queryCheck($db->reads === $beforeLearning + 1, '학급 수와 무관하게 학습 소속은 SQL 한 번이어야 합니다.');
$pdo->exec("INSERT INTO class_enrollments VALUES(2,2,1,'pending'),(3,1,2,'active')");
queryCheck($ids($classes->learningFor(1, [])) === [5], '대기 참여·다른 학생·공개 학급은 학습 소속이 아니어야 합니다.');
$pdo->exec('UPDATE classes SET enabled=0 WHERE id=5');
queryCheck($ids($classes->learningFor(1, [10])) === [4], '비활성 수업은 학습 소속에서 즉시 제외해야 합니다.');
$pdo->exec('CREATE TABLE learning_groups(id INTEGER PRIMARY KEY, enabled INTEGER, deleted_at TEXT)');
$pdo->exec('CREATE TABLE group_memberships(group_id INTEGER,user_id INTEGER,status TEXT)');
$pdo->exec("INSERT INTO learning_groups VALUES(1,1,NULL),(2,0,NULL),(3,1,'2026-10-01'),(4,1,NULL),(5,1,NULL)");
$pdo->exec("INSERT INTO group_memberships VALUES(1,1,'active'),(2,1,'active'),(3,1,'active'),(4,1,'pending'),(5,2,'active')");
$groups = new \CodingPanda\OjPlatform\Modules\Groups\Infrastructure\WordPress\WordPressGroupRepository($db);
$beforeGroups = $db->reads;
queryCheck($ids($groups->learningFor(1)) === [1], '그룹 소속은 활성 참여·활성 그룹·미삭제 조건을 한 번에 적용해야 합니다.');
queryCheck($db->reads === $beforeGroups + 1, '그룹 소속도 한 번의 SQL로 조회해야 합니다.');

// Exercise the production aggregate with duplicates and scoped time/result conditions.
$pdo->exec('DROP TABLE solution');
$pdo->exec('CREATE TABLE solution(solution_id INTEGER PRIMARY KEY AUTOINCREMENT,problem_id INTEGER,user_id TEXT,time INTEGER,memory INTEGER,in_date TEXT,result INTEGER,language INTEGER,ip TEXT,contest_id INTEGER,valid INTEGER,num INTEGER,code_length INTEGER)');
$pdo->exec("INSERT INTO solution(problem_id,user_id,in_date,result,valid) VALUES
    (1,'alice','2026-10-26 01:00:00',4,1),(1,'alice','2026-10-26 02:00:00',4,1),
    (1,'alice','2026-10-25 01:00:00',4,1),(1,'alice','2026-10-26 00:01:00',4,0),
    (1,'alice','2026-10-26 00:02:00',6,1),(2,'alice','2026-10-26 00:03:00',4,1),
    (1,'bob','2026-10-26 00:04:00',4,1)");
$aggregateConnection = new QueryConnection($pdo);
$judgeSettings = new class implements \CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider {
    public function current(): ?\CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings {
        $credential = new \CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials('fixture', 'fixture');
        return new \CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings('127.0.0.1', 3306, 'jol', $credential, $credential, false, null, true);
    }
};
$judgeConnector = new class($aggregateConnection) implements \CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector {
    public function __construct(private mysqli $connection) {}
    public function read(\CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings $settings): mysqli { return $this->connection; }
    public function write(\CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings $settings): mysqli { throw new LogicException('Read fixture'); }
};
$aggregateGateway = new \CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge\MysqliSubmissionGateway($judgeSettings, $judgeConnector);
queryCheck($aggregateGateway->earliestAcceptedTimes(['alice'], [1], '2026-10-26 00:00:00', '2026-10-26 23:59:59', 5000)
    === [['user_login' => 'alice', 'problem_id' => 1, 'accepted_at' => '2026-10-26 01:00:00']], '최초 정답은 본인·문제·기간·유효 결과를 SQL에서 제한해야 합니다.');
queryCheck(count($aggregateConnection->queries) === 2, '최초 호출은 열 검사 한 번과 집계 SQL 한 번이어야 합니다.');
$insertAccepted = $pdo->prepare("INSERT INTO solution(problem_id,user_id,in_date,result,valid) VALUES(1,'alice','2026-10-26 03:00:00',4,1)");
$pdo->beginTransaction();
for ($i = 0; $i < 5100; ++$i) { $insertAccepted->execute(); }
$pdo->commit();
$beforeAggregate = count($aggregateConnection->queries);
queryCheck(count($aggregateGateway->earliestAcceptedTimes(['alice'], [1], '2026-10-26 00:00:00', null, 5000)) === 1, '중복 정답 5천 건을 학생·문제별 한 행으로 집계해야 합니다.');
queryCheck(count($aggregateConnection->queries) === $beforeAggregate + 1, '정답 제출 수가 늘어도 OFFSET 조회를 반복하면 안 됩니다.');

$pooled = new \CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli\RequestReadConnection();
$pooled->retain();
queryCheck($pooled->close() && $pooled->close(), '저장소의 close는 공유 읽기 연결을 닫지 않아야 합니다.');
$pooled->release();
$pooled->release();
echo 'DB query performance regression: ' . $checks . " checks passed\n";
