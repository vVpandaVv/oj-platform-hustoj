<?php
declare(strict_types=1);
require_once __DIR__ . '/SqliteWpdb.php';

/** Real SQLite query execution; SHOW/version/EXPLAIN spellings adapt MySQL metadata only. */
final class AcceptanceWpdb
{
    public SqliteWpdb $inner; public array $queries = [];
    public function __construct()
    {
        $this->inner = new SqliteWpdb();
        $db = $this->inner->pdo;
        // Dedicated read-only probe fixtures add lesson tables without mutating shared fixture code.
        $fields = $db->query('PRAGMA table_info(wp_oj_microbit_attempts)')->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('assignment_id', array_column($fields, 'name'), true)) { $db->exec('ALTER TABLE wp_oj_microbit_attempts ADD COLUMN assignment_id INTEGER NOT NULL DEFAULT 0'); }
        $db->exec('CREATE TABLE IF NOT EXISTS wp_oj_microbit_assignments (id INTEGER PRIMARY KEY,class_id INTEGER NOT NULL,exercise_id INTEGER NOT NULL,exercise_revision INTEGER NOT NULL,title TEXT NOT NULL,owner_id INTEGER NOT NULL,revision INTEGER NOT NULL,archived INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS wp_oj_microbit_lesson_projects (assignment_id INTEGER NOT NULL,user_id INTEGER NOT NULL,revision INTEGER NOT NULL,data_json TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(assignment_id,user_id))');
        $db->exec('CREATE TABLE IF NOT EXISTS wp_oj_microbit_lesson_results (assignment_id INTEGER NOT NULL,user_id INTEGER NOT NULL,attempt_id INTEGER NOT NULL,exercise_revision INTEGER NOT NULL,result_status TEXT NOT NULL,result_passed INTEGER,result_score REAL,updated_at TEXT NOT NULL,PRIMARY KEY(assignment_id,user_id))');
        foreach (\OjMicrobit\Storage\Installer::indexes() as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if ($name === 'PRIMARY') { continue; }
                $db->exec('CREATE INDEX IF NOT EXISTS ' . $name . ' ON wp_oj_microbit_' . $table . '(' . implode(',', $columns) . ')');
            }
        }
        $db->exec("INSERT INTO wp_oj_microbit_exercises (id,owner_id,title,status,revision,archived,data_json,created_at,updated_at,audience_mode) VALUES (7,10,'PRIVATE_PERSON_NAME','published',1,0,'PRIVATE_STUDENT_SOURCE','2026-10-28','2026-10-28','classes'),(6,10,'PRIVATE_SITE_TITLE','published',1,0,'PRIVATE_STUDENT_SOURCE','2026-10-28','2026-10-28','site')");
        $db->exec("INSERT INTO wp_oj_microbit_exercise_classes VALUES (7,7,'published',0)");
        $db->exec("INSERT INTO wp_oj_microbit_exercises (id,owner_id,title,status,revision,archived,data_json,created_at,updated_at,audience_mode) VALUES (8,10,'PRIVATE_GROUP_TITLE','published',1,0,'PRIVATE_STUDENT_SOURCE','2026-11-03','2026-11-03','groups')");
        $db->exec("INSERT INTO wp_oj_microbit_exercise_groups VALUES (8,7,'published',0)");
        $db->exec("INSERT INTO wp_oj_microbit_attempts (id,exercise_id,exercise_revision,user_id,project_json,result_json,created_at,result_status,result_passed,result_score) VALUES (9,7,1,20,'PRIVATE_STUDENT_SOURCE','PRIVATE_HIDDEN_TEST','2026-10-28','graded',1,100)");
        $db->exec("INSERT INTO wp_oj_microbit_assignments VALUES (101,7,6,1,'PRIVATE_LESSON_TITLE',10,1,0,'2026-11-03','2026-11-03'),(102,8,6,1,'PRIVATE_OTHER_LESSON_TITLE',10,1,0,'2026-11-03','2026-11-03')");
        $db->exec("INSERT INTO wp_oj_microbit_attempts (id,exercise_id,exercise_revision,assignment_id,user_id,project_json,result_json,created_at,result_status,result_passed,result_score) VALUES (10,6,1,101,20,'PRIVATE_LESSON_SOURCE','PRIVATE_LESSON_HIDDEN','2026-11-03','graded',0,50),(11,6,1,102,20,'PRIVATE_OTHER_SOURCE','PRIVATE_OTHER_HIDDEN','2026-11-03','graded',1,100),(12,6,1,0,20,'PRIVATE_GENERAL_SOURCE','PRIVATE_GENERAL_HIDDEN','2026-11-03','graded',1,75)");
        $db->exec("INSERT INTO wp_oj_microbit_lesson_results VALUES (101,20,10,1,'graded',0,50,'2026-11-03'),(102,20,11,1,'graded',1,100,'2026-11-03')");
    }
    public function __get(string $name): mixed { return $this->inner->$name; }
    public function prepare(string $sql, mixed ...$args): string { return $this->inner->prepare($sql, ...$args); }
    public function get_results(string $sql, mixed $format): ?array
    {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'EXPLAIN ')) {
            $rows = $this->inner->pdo->query('EXPLAIN QUERY PLAN ' . substr($sql, 8))->fetchAll(PDO::FETCH_ASSOC);
            return array_map(static function (array $row): array {
                $detail = $row['detail']; preg_match('/USING (?:COVERING )?INDEX ([a-zA-Z0-9_]+)/', $detail, $match);
                return ['type' => str_contains($detail, 'SEARCH') ? 'ref' : (str_contains($detail, 'SCAN') ? 'ALL' : 'system'), 'key' => $match[1] ?? null,
                    'Extra' => str_contains($detail, 'TEMP B-TREE') ? 'Using temporary; Using filesort' : ''];
            }, $rows);
        }
        return $this->inner->get_results($sql, $format);
    }
    public function get_row(string $sql, mixed $format): ?array { $this->queries[] = $sql; return $this->inner->get_row($sql, $format); }
    public function get_var(string $sql): mixed { $this->queries[] = $sql; return $sql === 'SELECT VERSION()' ? '8.0.36-sqlite-test-adapter' : $this->inner->get_var($sql); }
    public function query(string $sql): never { throw new RuntimeException('Fixture forbids probe writes'); }
}
