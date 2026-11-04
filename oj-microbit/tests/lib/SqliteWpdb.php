<?php
declare(strict_types=1);

/** Executes real SQL on SQLite; only MySQL-specific syntax and named locks are adapted. */
final class SqliteWpdb
{
    public string $prefix = 'wp_'; public string $last_error = ''; public int $insert_id = 0;
    public PDO $pdo; public bool $denyLock = false; public int $locks = 0; public int $releases = 0; public ?string $fail = null;
    public array $queries = [];
    public mixed $beforeQuery = null;
    public string $engine = 'InnoDB';
    public function __construct(bool $versionTwo = true)
    {
        $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE wp_oj_microbit_exercises (id INTEGER PRIMARY KEY AUTOINCREMENT,owner_id INTEGER NOT NULL,title TEXT NOT NULL,status TEXT NOT NULL,revision INTEGER NOT NULL,archived INTEGER NOT NULL,data_json TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE wp_oj_microbit_projects (exercise_id INTEGER NOT NULL,user_id INTEGER NOT NULL,revision INTEGER NOT NULL,data_json TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(exercise_id,user_id))');
        $this->pdo->exec('CREATE TABLE wp_oj_microbit_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT,exercise_id INTEGER NOT NULL,exercise_revision INTEGER NOT NULL,user_id INTEGER NOT NULL,project_json TEXT NOT NULL,result_json TEXT NOT NULL,created_at TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE wp_oj_microbit_limits (scope_key TEXT PRIMARY KEY,window_start INTEGER NOT NULL,hits INTEGER NOT NULL)');
        if ($versionTwo) { $this->upgrade(); }
    }
    public function upgrade(): void
    {
        $exerciseColumns = array_column($this->pdo->query('PRAGMA table_info(wp_oj_microbit_exercises)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('audience_mode', $exerciseColumns, true)) { $this->pdo->exec("ALTER TABLE wp_oj_microbit_exercises ADD COLUMN audience_mode TEXT NOT NULL DEFAULT 'site'"); }
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wp_oj_microbit_exercise_classes (exercise_id INTEGER NOT NULL,class_id INTEGER NOT NULL,status TEXT NOT NULL,archived INTEGER NOT NULL,PRIMARY KEY(exercise_id,class_id))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wp_oj_microbit_exercise_groups (exercise_id INTEGER NOT NULL,group_id INTEGER NOT NULL,status TEXT NOT NULL,archived INTEGER NOT NULL,PRIMARY KEY(exercise_id,group_id))');
        $columns = array_column($this->pdo->query('PRAGMA table_info(wp_oj_microbit_attempts)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('assignment_id', $columns, true)) { $this->pdo->exec("ALTER TABLE wp_oj_microbit_attempts ADD COLUMN assignment_id INTEGER NOT NULL DEFAULT 0"); }
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wp_oj_microbit_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT,class_id INTEGER NOT NULL,exercise_id INTEGER NOT NULL,exercise_revision INTEGER NOT NULL,title TEXT NOT NULL,owner_id INTEGER NOT NULL,revision INTEGER NOT NULL DEFAULT 1,archived INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,updated_at TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wp_oj_microbit_lesson_projects (assignment_id INTEGER NOT NULL,user_id INTEGER NOT NULL,revision INTEGER NOT NULL,data_json TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(assignment_id,user_id))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wp_oj_microbit_lesson_results (assignment_id INTEGER NOT NULL,user_id INTEGER NOT NULL,attempt_id INTEGER NOT NULL,exercise_revision INTEGER NOT NULL,result_status TEXT NOT NULL,result_passed INTEGER NULL,result_score REAL NULL,updated_at TEXT NOT NULL,PRIMARY KEY(assignment_id,user_id))');
        foreach (['result_status' => "TEXT NOT NULL DEFAULT ''", 'result_passed' => 'INTEGER NULL', 'result_score' => 'REAL NULL'] as $column => $definition) {
            if (!in_array($column, $columns, true)) { $this->pdo->exec("ALTER TABLE wp_oj_microbit_attempts ADD COLUMN {$column} {$definition}"); }
        }
        foreach (\OjMicrobit\Storage\Installer::indexes() as $table => $indexes) {
            foreach ($indexes as $index => $fields) {
                if ($index !== 'PRIMARY') { $this->pdo->exec('CREATE ' . ($index === 'assignment_class_exercise' ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . $index . ' ON wp_oj_microbit_' . $table . '(' . implode(',', $fields) . ')'); }
            }
        }
    }
    public function prepare(string $query, mixed ...$arguments): string
    {
        $index = 0;
        $sql = preg_replace_callback('/%[ds]/', function (array $match) use (&$index, $arguments): string {
            if (!array_key_exists($index, $arguments)) { throw new RuntimeException('Missing SQL argument'); }
            $value = $arguments[$index++]; return $match[0] === '%d' ? (string) (int) $value : $this->pdo->quote((string) $value);
        }, $query);
        if ($index !== count($arguments)) { throw new RuntimeException('Extra SQL arguments'); }
        return $sql;
    }
    private function execute(string $sql): PDOStatement|false
    {
        $this->last_error = '';
        $this->queries[] = $sql;
        if (is_callable($this->beforeQuery)) { ($this->beforeQuery)($sql, $this); }
        if ($this->fail !== null && str_contains($sql, $this->fail)) { $this->last_error = 'Injected database failure'; return false; }
        $sql = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $sql);
        if ($sql === 'START TRANSACTION') { $sql = 'BEGIN TRANSACTION'; }
        // SQLite verifies rollback contents, not MySQL row-level lock behavior.
        if (str_ends_with($sql, ' FOR UPDATE')) { $sql = substr($sql, 0, -11); }
        $sql = preg_replace('/ON DUPLICATE KEY UPDATE hits=IF\(window_start=(\d+),hits\+1,1\),window_start=(\d+)/',
            'ON CONFLICT(scope_key) DO UPDATE SET hits=CASE WHEN window_start=$1 THEN hits+1 ELSE 1 END,window_start=$2', $sql);
        if (str_contains($sql, 'oj_microbit_lesson_results') && str_contains($sql, ' ON DUPLICATE KEY UPDATE ')) {
            $sql = str_replace(' ON DUPLICATE KEY UPDATE ', ' ON CONFLICT(assignment_id,user_id) DO UPDATE SET ', $sql);
            $sql = preg_replace('/VALUES\(([a-z_]+)\)/', 'excluded.$1', $sql);
        }
        try { return $this->pdo->query($sql); }
        catch (PDOException $error) { $this->last_error = $error->getMessage(); return false; }
    }
    public function query(string $sql): int|false { $statement = $this->execute($sql); return $statement === false ? false : $statement->rowCount(); }
    public function get_row(string $sql, mixed $format): ?array { $statement = $this->execute($sql); if ($statement === false) { return null; } $row = $statement->fetch(PDO::FETCH_ASSOC); return $row === false ? null : $row; }
    public function get_charset_collate(): string { return ''; }
    public function get_results(string $sql, mixed $format): ?array {
        if (preg_match("/^SHOW TABLE STATUS WHERE Name='([a-z_]+)'$/", $sql, $match)) {
            $this->last_error = ''; $this->queries[] = $sql;
            if ($this->fail !== null && str_contains($sql, $this->fail)) { $this->last_error = 'Injected database failure'; return null; }
            return [['Name' => $match[1], 'Engine' => $this->engine]];
        }
        if (preg_match('/^SHOW (COLUMNS|INDEX) FROM `([a-z_]+)`$/', $sql, $match)) {
            $this->last_error = ''; $this->queries[] = $sql;
            if ($this->fail !== null && str_contains($sql, $this->fail)) { $this->last_error = 'Injected database failure'; return null; }
            $info = $this->pdo->query('PRAGMA table_info(' . $match[2] . ')')->fetchAll(PDO::FETCH_ASSOC);
            if ($match[1] === 'COLUMNS') { return array_map(static fn ($row) => ['Field' => $row['name']], $info); }
            $rows = [];
            foreach ($info as $row) { if ($row['pk'] > 0) { $rows[] = ['Key_name' => 'PRIMARY', 'Seq_in_index' => $row['pk'], 'Column_name' => $row['name'], 'Non_unique' => 0]; } }
            foreach ($this->pdo->query('PRAGMA index_list(' . $match[2] . ')')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                if ($index['origin'] === 'pk') { continue; }
                foreach ($this->pdo->query('PRAGMA index_info(' . $index['name'] . ')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $rows[] = ['Key_name' => $index['name'], 'Seq_in_index' => $column['seqno'] + 1, 'Column_name' => $column['name'], 'Non_unique' => (int) !$index['unique']];
                }
            }
            return $rows;
        }
        $statement = $this->execute($sql); return $statement === false ? null : $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function get_var(string $sql): mixed {
        $this->last_error = '';
        if (str_starts_with($sql, 'SELECT GET_LOCK')) { if ($this->denyLock) { return '0'; } $this->locks++; return '1'; }
        if (str_starts_with($sql, 'SELECT RELEASE_LOCK')) { $this->releases++; return '1'; }
        $statement = $this->execute($sql); if ($statement === false) { return null; } $value = $statement->fetchColumn(); return $value === false ? null : $value;
    }
    public function insert(string $table, array $data): int|false {
        $columns = implode(',', array_keys($data));
        $values = implode(',', array_map(fn ($value) => $value === null ? 'NULL' : (is_int($value) ? (string) $value : $this->pdo->quote((string) $value)), array_values($data)));
        $result = $this->query("INSERT INTO {$table} ({$columns}) VALUES ({$values})");
        if ($result !== false) { $this->insert_id = (int) $this->pdo->lastInsertId(); }
        return $result;
    }
}
