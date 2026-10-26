<?php
declare(strict_types=1);
namespace OjPrimmTests;

/** Production repository SQL with SQLite syntax adaptation; not a MySQL locking emulator. */
final class SqlWpdb
{
    public string $prefix = 'test_'; public int $insert_id = 0; public string $last_error = '';
    public function __construct(public readonly \PDO $pdo) {}
    public function prepare(string $sql, mixed ...$args): string
    {
        $i = 0; return preg_replace_callback('/%([ds])/', function (array $m) use (&$i, $args): string {
            $value = $args[$i++]; return $m[1] === 'd' ? (string) (int) $value : $this->pdo->quote((string) $value);
        }, $sql);
    }
    private function sql(string $sql): string { return str_replace(['START TRANSACTION', 'INSERT IGNORE'], ['BEGIN TRANSACTION', 'INSERT OR IGNORE'], preg_replace('/\s+FOR UPDATE\b/i', '', $sql)); }
    public function query(string $sql): int|false
    {
        try { return $this->pdo->exec($this->sql($sql)); } catch (\PDOException $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function get_results(string $sql, mixed $format = null): array { return $this->pdo->query($this->sql($sql))->fetchAll(\PDO::FETCH_ASSOC); }
    public function get_row(string $sql, mixed $format = null): ?array { return $this->get_results($sql)[0] ?? null; }
    public function get_var(string $sql): mixed { $row = $this->get_row($sql); return $row === null ? null : array_values($row)[0]; }
    public function insert(string $table, array $data): int|false
    {
        try {
            $s = $this->pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($data)) . '`) VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')');
            $s->execute(array_values($data)); $this->insert_id = (int) $this->pdo->lastInsertId(); return $s->rowCount();
        } catch (\PDOException $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function update(string $table, array $data, array $where): int|false
    {
        try {
            $sets = array_map(static fn (string $key): string => '`' . $key . '`=?', array_keys($data)); $conditions = []; $args = array_values($data);
            foreach ($where as $key => $value) { $conditions[] = '`' . $key . '`' . ($value === null ? ' IS NULL' : '=?'); if ($value !== null) { $args[] = $value; } }
            $s = $this->pdo->prepare('UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE ' . implode(' AND ', $conditions)); $s->execute($args); return $s->rowCount();
        } catch (\PDOException $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function delete(string $table, array $where): int|false
    {
        $s = $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . implode('`=? AND `', array_keys($where)) . '`=?');
        $s->execute(array_values($where)); return $s->rowCount();
    }
    public static function create(): self
    {
        $db = new self(new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        $db->pdo->exec('CREATE TABLE test_oj_primm_activities(id INTEGER PRIMARY KEY AUTOINCREMENT,creator_user_id INTEGER NOT NULL,context_type TEXT,context_id TEXT,title TEXT,family_id INTEGER DEFAULT 0,parent_activity_id INTEGER NULL,content_version INTEGER DEFAULT 1,revision INTEGER DEFAULT 1,status TEXT DEFAULT "active",request_key_hash TEXT NULL,data_json TEXT,created_at TEXT,updated_at TEXT,UNIQUE(creator_user_id,request_key_hash))');
        $db->pdo->exec('CREATE TABLE test_oj_primm_attempts(id INTEGER PRIMARY KEY AUTOINCREMENT,activity_id INTEGER,user_id INTEGER,version INTEGER,state_json TEXT,last_request_hash TEXT,created_at TEXT,updated_at TEXT,UNIQUE(activity_id,user_id))');
        $db->pdo->exec('CREATE TABLE test_oj_primm_events(id INTEGER PRIMARY KEY AUTOINCREMENT,attempt_id INTEGER,user_id INTEGER,stage TEXT,event_type TEXT,payload_json TEXT,created_at TEXT)');
        $db->pdo->exec('CREATE TABLE test_oj_primm_generations(id INTEGER PRIMARY KEY AUTOINCREMENT,creator_user_id INTEGER,fingerprint TEXT,status TEXT,activity_id INTEGER,error_message TEXT,lease_token TEXT,lease_expires_at TEXT,next_attempt_at TEXT,data_json TEXT,created_at TEXT,updated_at TEXT,UNIQUE(creator_user_id,fingerprint))');
        $db->pdo->exec('CREATE TABLE test_oj_primm_generation_control(scope_key TEXT PRIMARY KEY,data_json TEXT,updated_at TEXT)');
        $db->pdo->exec('CREATE TABLE test_oj_primm_practices(id INTEGER PRIMARY KEY AUTOINCREMENT,activity_id INTEGER,user_id INTEGER,fingerprint TEXT,status TEXT,lease_token TEXT,lease_expires_at TEXT,data_json TEXT,created_at TEXT,updated_at TEXT,UNIQUE(user_id,fingerprint))');
        return $db;
    }
}
