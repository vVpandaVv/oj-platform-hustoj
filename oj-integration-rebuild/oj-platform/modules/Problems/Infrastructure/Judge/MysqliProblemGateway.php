<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Judge;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemRankingEntry;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemSearch;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemStatistics;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use RuntimeException;

final class MysqliProblemGateway implements ProblemGateway, \CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemListGateway
{
    private const CREATE_LOCK = 'oj-platform.problem.create';

    private const REQUIRED_COLUMNS = [
        'problem_id',
        'title',
        'description',
        'input',
        'output',
        'sample_input',
        'sample_output',
        'hint',
        'source',
        'time_limit',
        'memory_limit',
        'spj',
        'defunct',
        'accepted',
        'submit',
        'bann',
        'front',
        'body',
        'rear',
        'langmask',
    ];

    private const OPTIONAL_COLUMNS = [
        'in_date',
        'credits',
    ];

    /**
     * @var list<string>|null
     */
    private ?array $columnCache = null;

    public function __construct(
        private readonly JudgeConnectionSettingsProvider $settings,
        private readonly JudgeConnector $connector
    ) {
    }

    public function search(ProblemSearch $search): array
    {
        return $this->searchProblems($search, false);
    }

    public function searchList(ProblemSearch $search): array
    {
        return $this->searchProblems($search, true);
    }

    private function searchProblems(ProblemSearch $search, bool $listOnly): array
    {
        $connection = $this->readConnection();
        try {
            $columns = $this->columns($connection);
            [$where, $types, $values] = $this->where($search);
            $sql = 'SELECT ' . $this->selectColumns($columns, $listOnly)
                . ' FROM `problem`' . $where
                . ' ORDER BY `problem_id` ASC LIMIT ? OFFSET ?';
            $types .= 'ii';
            $values[] = $search->limit();
            $values[] = $search->offset();
            $rows = $this->rows($connection, $sql, $types, $values);

            return array_map(
                fn (array $row): JudgeProblem => $this->hydrate($row),
                $this->withCounters($connection, $rows)
            );
        } finally {
            $connection->close();
        }
    }

    public function count(ProblemSearch $search): int
    {
        $connection = $this->readConnection();
        try {
            $this->columns($connection);
            [$where, $types, $values] = $this->where($search);
            $row = $this->row(
                $connection,
                'SELECT COUNT(*) AS `total` FROM `problem`' . $where,
                $types,
                $values
            );

            return (int) ($row['total'] ?? 0);
        } finally {
            $connection->close();
        }
    }

    public function find(ProblemId $problemId): ?JudgeProblem
    {
        $connection = $this->readConnection();
        try {
            $columns = $this->columns($connection);
            $row = $this->row(
                $connection,
                'SELECT ' . $this->selectColumns($columns)
                    . ' FROM `problem` WHERE `problem_id` = ? LIMIT 1',
                'i',
                [$problemId->value()]
            );

            return $row === null ? null : $this->hydrate($this->withCounters($connection, [$row])[0]);
        } finally {
            $connection->close();
        }
    }

    public function findMany(array $problemIds): array
    {
        $orderedIds = [];
        foreach ($problemIds as $problemId) {
            if ($problemId instanceof ProblemId) {
                $orderedIds[$problemId->value()] = $problemId->value();
            }
        }
        if ($orderedIds === []) {
            return [];
        }

        $connection = $this->readConnection();
        try {
            $columns = $this->columns($connection);
            $found = [];
            foreach (array_chunk(array_values($orderedIds), 500) as $chunk) {
                $rows = $this->rows(
                    $connection,
                    'SELECT ' . $this->selectColumns($columns)
                        . ' FROM `problem` WHERE `problem_id` IN ('
                        . implode(', ', array_fill(0, count($chunk), '?'))
                        . ')',
                    str_repeat('i', count($chunk)),
                    $chunk
                );
                foreach ($this->withCounters($connection, $rows) as $row) {
                    $problem = $this->hydrate($row);
                    $found[$problem->id()->value()] = $problem;
                }
            }

            return array_values(array_filter(array_map(
                static fn (int $problemId): ?JudgeProblem =>
                    $found[$problemId] ?? null,
                $orderedIds
            )));
        } finally {
            $connection->close();
        }
    }

    public function nextId(): ProblemId
    {
        $connection = $this->readConnection();
        try {
            $this->columns($connection);
            return $this->nextIdFrom($connection);
        } finally {
            $connection->close();
        }
    }

    public function createNext(ProblemDraft $draft): ProblemId
    {
        $connection = $this->writeConnection();
        $locked = false;
        try {
            $lock = $this->row(
                $connection,
                'SELECT GET_LOCK(?, 10) AS `acquired`',
                's',
                [self::CREATE_LOCK]
            );
            $locked = (int) ($lock['acquired'] ?? 0) === 1;
            if (! $locked) {
                throw new RuntimeException(
                    '새 문제 번호 할당 잠금을 얻지 못했습니다. 잠시 뒤 다시 시도해 주세요.'
                );
            }

            $columns = $this->columns($connection);
            $problemId = $this->nextIdFrom($connection);
            $this->insertProblem($connection, $columns, $problemId, $draft);

            return $problemId;
        } finally {
            if ($locked) {
                try {
                    $this->row(
                        $connection,
                        'SELECT RELEASE_LOCK(?) AS `released`',
                        's',
                        [self::CREATE_LOCK]
                    );
                } catch (\Throwable) {
                    // Closing this connection below releases its named locks.
                }
            }
            $connection->close();
        }
    }

    public function create(ProblemId $problemId, ProblemDraft $draft): void
    {
        $connection = $this->writeConnection();
        try {
            $columns = $this->columns($connection);
            $this->insertProblem($connection, $columns, $problemId, $draft);
        } finally {
            $connection->close();
        }
    }

    public function update(ProblemId $problemId, ProblemDraft $draft): void
    {
        $connection = $this->writeConnection();
        try {
            $columns = $this->columns($connection);
            $data = $this->writeData($draft, $columns);
            $this->updateRow(
                $connection,
                'problem',
                $data,
                '`problem_id` = ?',
                [$problemId->value()]
            );
        } finally {
            $connection->close();
        }
    }

    public function archive(ProblemId $problemId, bool $archived): void
    {
        $connection = $this->writeConnection();
        try {
            $this->columns($connection);
            $this->updateRow(
                $connection,
                'problem',
                ['defunct' => $archived ? 'Y' : 'N'],
                '`problem_id` = ?',
                [$problemId->value()]
            );
        } finally {
            $connection->close();
        }
    }

    public function statistics(ProblemId $problemId): ProblemStatistics
    {
        $connection = $this->readConnection();
        try {
            $this->columns($connection);
            $rows = $this->rows(
                $connection,
                'SELECT `result`, COUNT(*) AS `total` FROM `solution`'
                    . ' WHERE `problem_id` = ? AND `valid` = 1'
                    . ' GROUP BY `result` ORDER BY `result`',
                'i',
                [$problemId->value()]
            );
            $counts = [];
            $total = 0;
            foreach ($rows as $row) {
                $resultCode = (int) ($row['result'] ?? 0);
                $count = (int) ($row['total'] ?? 0);
                $counts[$resultCode] = $count;
                $total += $count;
            }

            return new ProblemStatistics(
                $problemId,
                $total,
                $counts[4] ?? 0,
                $counts
            );
        } finally {
            $connection->close();
        }
    }

    public function ranking(ProblemId $problemId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $connection = $this->readConnection();
        try {
            $this->columns($connection);
            $rows = $this->rows(
                $connection,
                'SELECT `accepted`.`user_id`, `accepted`.`in_date`,'
                    . ' `accepted`.`time`, `accepted`.`memory`,'
                    . ' (SELECT COUNT(*) FROM `solution` AS `attempt`'
                    . ' WHERE `attempt`.`problem_id` = `accepted`.`problem_id`'
                    . ' AND `attempt`.`user_id` = `accepted`.`user_id`'
                    . ' AND `attempt`.`solution_id` <= `accepted`.`solution_id`) AS `attempts`'
                    . ' FROM `solution` AS `accepted`'
                    . ' INNER JOIN (SELECT `user_id`, MIN(`solution_id`) AS `solution_id`'
                    . ' FROM `solution` WHERE `problem_id` = ? AND `result` = 4'
                    . ' GROUP BY `user_id`) AS `first_accept`'
                    . ' ON `first_accept`.`solution_id` = `accepted`.`solution_id`'
                    . ' ORDER BY `accepted`.`solution_id` ASC LIMIT ?',
                'ii',
                [$problemId->value(), $limit]
            );

            return array_map(
                static fn (array $row): ProblemRankingEntry => new ProblemRankingEntry(
                    (string) ($row['user_id'] ?? ''),
                    (int) ($row['attempts'] ?? 0),
                    (string) ($row['in_date'] ?? ''),
                    (int) ($row['time'] ?? 0),
                    (int) ($row['memory'] ?? 0)
                ),
                $rows
            );
        } finally {
            $connection->close();
        }
    }

    public function availableColumns(): array
    {
        $connection = $this->readConnection();
        try {
            return $this->columns($connection);
        } finally {
            $connection->close();
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

    private function writeConnection(): \mysqli
    {
        $settings = $this->settings->current();
        if ($settings === null) {
            throw new RuntimeException('Judge DB 연결 설정을 먼저 완료해 주세요.');
        }

        return $this->connector->write($settings);
    }

    /**
     * @return list<string>
     */
    private function columns(\mysqli $connection): array
    {
        if ($this->columnCache !== null) {
            return $this->columnCache;
        }

        $result = $connection->query('SHOW COLUMNS FROM `problem`');
        if (! $result instanceof \mysqli_result) {
            throw new RuntimeException('Judge 문제 테이블 구조를 확인하지 못했습니다.');
        }

        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[] = $field;
            }
        }
        $result->free();

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));
        if ($missing !== []) {
            throw new RuntimeException(
                'Judge problem 테이블에 필수 열이 없습니다: ' . implode(', ', $missing)
            );
        }

        $this->columnCache = $columns;

        return $columns;
    }

    /**
     * @param list<string> $columns
     */
    private function selectColumns(array $columns, bool $listOnly = false): string
    {
        $selected = array_map(
            static fn (string $column): string => match (true) {
                in_array($column, ['accepted', 'submit'], true) => '0 AS `' . $column . '`',
                $listOnly && in_array($column, ['description', 'input', 'output', 'sample_input', 'sample_output', 'hint', 'source', 'bann', 'front', 'body', 'rear'], true) => "'' AS `" . $column . '`',
                default => '`' . $column . '`',
            },
            self::REQUIRED_COLUMNS
        );
        foreach (self::OPTIONAL_COLUMNS as $column) {
            $selected[] = in_array($column, $columns, true)
                ? '`' . $column . '`'
                : "'' AS `" . $column . '`';
        }

        return implode(', ', $selected);
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function withCounters(\mysqli $connection, array $rows): array
    {
        if ($rows === []) { return []; }
        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['problem_id'], $rows)));
        $stats = $this->rows($connection,
            'SELECT `problem_id`, COUNT(*) AS `submit`, SUM(CASE WHEN `result` = 4 THEN 1 ELSE 0 END) AS `accepted`'
            . ' FROM `solution` WHERE `valid` = 1 AND `problem_id` IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY `problem_id`',
            str_repeat('i', count($ids)), $ids
        );
        $byId = [];
        foreach ($stats as $stat) { $byId[(int) $stat['problem_id']] = $stat; }
        foreach ($rows as &$row) {
            $stat = $byId[(int) $row['problem_id']] ?? [];
            $row['accepted'] = (int) ($stat['accepted'] ?? 0);
            $row['submit'] = (int) ($stat['submit'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    /**
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    private function where(ProblemSearch $search): array
    {
        $clauses = [];
        $types = '';
        $values = [];

        if ($search->visibility() !== null) {
            $clauses[] = '`defunct` = ?';
            $types .= 's';
            $values[] = $search->visibility()->judgeDefunct();
        }
        if ($search->keyword() !== '') {
            if (ctype_digit($search->keyword()) && strlen($search->keyword()) <= 10) {
                $clauses[] = '`problem_id` = ?'; $types .= 'i'; $values[] = (int) $search->keyword();
            } else {
                $clauses[] = '`title` LIKE ?'; $types .= 's'; $values[] = '%' . $search->keyword() . '%';
            }
        }
        if ($search->specialJudge() !== null) {
            $spjEnabled = "UPPER(CAST(COALESCE(`spj`, '0') AS CHAR)) IN ('1', 'Y')";
            $clauses[] = $search->specialJudge()
                ? $spjEnabled
                : 'NOT (' . $spjEnabled . ')';
        }
        if ($search->problemIds() !== null) {
            if ($search->problemIds() === []) {
                $clauses[] = '1 = 0';
            } else {
                $clauses[] = '`problem_id` IN ('
                    . implode(', ', array_fill(0, count($search->problemIds()), '?'))
                    . ')';
                $types .= str_repeat('i', count($search->problemIds()));
                array_push($values, ...$search->problemIds());
            }
        }

        return [
            $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
            $types,
            $values,
        ];
    }

    /**
     * @param list<string> $columns
     * @return array<string, int|string>
     */
    private function writeData(ProblemDraft $draft, array $columns): array
    {
        $data = [
            'title' => $draft->title(),
            'description' => $draft->description(),
            'input' => $draft->input(),
            'output' => $draft->output(),
            'sample_input' => $draft->sampleInput(),
            'sample_output' => $draft->sampleOutput(),
            'hint' => $draft->hint(),
            'source' => $draft->source(),
            'time_limit' => $draft->timeLimitSeconds(),
            'memory_limit' => $draft->memoryLimitMegabytes(),
            'spj' => $draft->specialJudge() ? '1' : '0',
            'defunct' => $draft->visibility()->judgeDefunct(),
            'bann' => $draft->forbiddenCode(),
            'front' => $draft->prefixCode(),
            'body' => $draft->starterCode(),
            'rear' => $draft->suffixCode(),
            'langmask' => $draft->languageMask() ?? ProblemDraft::DEFAULT_LANGUAGE_MASK,
        ];
        if (in_array('credits', $columns, true)) {
            $data['credits'] = $draft->credits();
        }

        return $data;
    }
    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): JudgeProblem
    {
        return new JudgeProblem(
            new ProblemId((int) ($row['problem_id'] ?? 0)),
            (string) ($row['title'] ?? ''),
            (string) ($row['description'] ?? ''),
            (string) ($row['input'] ?? ''),
            (string) ($row['output'] ?? ''),
            (string) ($row['sample_input'] ?? ''),
            (string) ($row['sample_output'] ?? ''),
            (string) ($row['hint'] ?? ''),
            (string) ($row['source'] ?? ''),
            max(1, (int) ($row['time_limit'] ?? 1)),
            max(1, (int) ($row['memory_limit'] ?? 1)),
            in_array((string) ($row['spj'] ?? '0'), ['1', 'Y', 'y'], true),
            ProblemVisibility::fromJudgeDefunct((string) ($row['defunct'] ?? 'N')),
            max(0, (int) ($row['accepted'] ?? 0)),
            max(0, (int) ($row['submit'] ?? 0)),
            (string) ($row['bann'] ?? ''),
            (string) ($row['front'] ?? ''),
            (string) ($row['body'] ?? ''),
            (string) ($row['rear'] ?? ''),
            isset($row['langmask']) ? max(0, (int) $row['langmask']) : null,
            (string) ($row['credits'] ?? '')
        );
    }

    private function nextIdFrom(\mysqli $connection): ProblemId
    {
        $row = $this->row(
            $connection,
            'SELECT COALESCE(MAX(`problem_id`), 999) + 1 AS `next_id` FROM `problem`'
        );

        return new ProblemId((int) ($row['next_id'] ?? 1000));
    }

    /**
     * @param list<string> $columns
     */
    private function insertProblem(
        \mysqli $connection,
        array $columns,
        ProblemId $problemId,
        ProblemDraft $draft
    ): void {
        $data = ['problem_id' => $problemId->value()]
            + $this->writeData($draft, $columns);
        if (in_array('in_date', $columns, true)) {
            $data['in_date'] = gmdate('Y-m-d H:i:s');
        }

        $this->insert($connection, 'problem', $data);
    }

    /**
     * @param list<mixed> $values
     * @return array<string, mixed>|null
     */
    private function row(
        \mysqli $connection,
        string $sql,
        string $types = '',
        array $values = []
    ): ?array {
        $rows = $this->rows($connection, $sql, $types, $values);

        return $rows[0] ?? null;
    }

    /**
     * @param list<mixed> $values
     * @return list<array<string, mixed>>
     */
    private function rows(
        \mysqli $connection,
        string $sql,
        string $types = '',
        array $values = []
    ): array {
        $statement = $this->statement($connection, $sql, $types, $values);
        $result = $statement->get_result();
        if (! $result instanceof \mysqli_result) {
            $statement->close();
            throw new RuntimeException('Judge 문제 데이터를 읽지 못했습니다.');
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $statement->close();

        return $rows;
    }

    /**
     * @param array<string, int|string> $data
     */
    private function insert(\mysqli $connection, string $table, array $data): void
    {
        $columns = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`'
            . implode('`, `', $columns)
            . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $values = array_values($data);
        $types = $this->types($values);
        $statement = $this->statement($connection, $sql, $types, $values);
        $affected = $statement->affected_rows;
        $statement->close();
        if ($affected !== 1) {
            throw new RuntimeException('Judge 문제를 만들지 못했습니다.');
        }
    }

    /**
     * @param array<string, int|string> $data
     * @param list<mixed> $whereValues
     */
    private function updateRow(
        \mysqli $connection,
        string $table,
        array $data,
        string $where,
        array $whereValues
    ): void {
        $sets = array_map(
            static fn (string $column): string => '`' . $column . '` = ?',
            array_keys($data)
        );
        $values = array_merge(array_values($data), $whereValues);
        $statement = $this->statement(
            $connection,
            'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            $this->types($values),
            $values
        );
        $statement->close();
    }

    /**
     * @param list<mixed> $values
     */
    private function statement(
        \mysqli $connection,
        string $sql,
        string $types,
        array $values
    ): \mysqli_stmt {
        $statement = $connection->prepare($sql);
        if (! $statement instanceof \mysqli_stmt) {
            throw new RuntimeException('Judge 문제 명령을 준비하지 못했습니다.');
        }
        if ($values !== []) {
            $references = [];
            foreach ($values as $index => $value) {
                $values[$index] = $value;
                $references[$index] = &$values[$index];
            }
            if (! $statement->bind_param($types, ...$references)) {
                $statement->close();
                throw new RuntimeException('Judge 문제 명령의 값을 연결하지 못했습니다.');
            }
        }
        if (! $statement->execute()) {
            $statement->close();
            throw new RuntimeException('Judge 문제 명령을 실행하지 못했습니다.');
        }

        return $statement;
    }

    /**
     * @param list<mixed> $values
     */
    private function types(array $values): string
    {
        return implode('', array_map(
            static fn (mixed $value): string => is_int($value) ? 'i' : 's',
            $values
        ));
    }
}
