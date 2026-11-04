<?php
declare(strict_types=1);
namespace OjMicrobit\Acceptance;

use OjMicrobit\Storage\Installer;
use OjMicrobit\Storage\WpdbRepository;
use OjMicrobit\Storage\WpdbLessonRepository;
use RuntimeException;

final class ReadOnlyDatabase
{
    public array $statements = [];
    public bool $summaryPolicy = false;
    public function __construct(private readonly object $database) {}
    public function __get(string $name): mixed { return $this->database->$name; }
    public function prepare(string $sql, mixed ...$args): string { return $this->database->prepare($sql, ...$args); }
    public static function assertReadOnly(string $sql): void
    {
        if (!preg_match('/\A\s*(?:SELECT|SHOW|EXPLAIN)\b/i', $sql)
            || preg_match('/\b(?:ANALYZE|FOR\s+UPDATE|LOCK|GET_LOCK|RELEASE_LOCK|OUTFILE|DUMPFILE|BENCHMARK|SLEEP)\b|;|\x00/i', $sql)) { throw new RuntimeException('database_probe_failed'); }
    }
    private function read(string $method, string $sql, mixed $format = null): mixed
    {
        self::assertReadOnly($sql);
        if ($this->summaryPolicy && (count($this->statements) >= 2 || preg_match('/\b(?:data_json|project_json|result_json|OFFSET)\b|\bCOUNT\s*\(|SELECT\s+(?:[A-Za-z_]+\.)?\*/i', $sql)
            || !preg_match('/\bLIMIT\s+([0-9]+)\s*\z/i', $sql, $limit) || (int) $limit[1] < 1 || (int) $limit[1] > 2551)) { throw new RuntimeException('summary_query_policy_failed'); }
        $start = hrtime(true);
        $result = $format === null ? $this->database->$method($sql) : $this->database->$method($sql, $format);
        if ((string) $this->database->last_error !== '') { throw new RuntimeException('database_probe_failed'); }
        $this->statements[] = ['sql' => $sql, 'ms' => (hrtime(true) - $start) / 1e6, 'rows' => is_array($result) ? ($method === 'get_row' ? 1 : count($result)) : 0];
        return $result;
    }
    public function get_results(string $sql, mixed $format): mixed { return $this->read('get_results', $sql, $format); }
    public function get_row(string $sql, mixed $format): mixed { return $this->read('get_row', $sql, $format); }
    public function get_var(string $sql): mixed { return $this->read('get_var', $sql); }
    public function query(string $sql): never { throw new RuntimeException('database_probe_failed'); }
    public function insert(mixed ...$args): never { throw new RuntimeException('database_probe_failed'); }
}

final class WordPressDbProbe
{
    public const FORMAT = 'oj-microbit-wp-db-acceptance/v1';
    public function __construct(private readonly object $database) {}
    public function run(array $options, string $pluginVersion): array
    {
        $previous = method_exists($this->database, 'suppress_errors') ? $this->database->suppress_errors(true) : null;
        try { return $this->probe($options, $pluginVersion); }
        finally { if ($previous !== null) { $this->database->suppress_errors($previous); } }
    }
    private function probe(array $options, string $pluginVersion): array
    {
        $iterations = $this->integer($options['iterations'] ?? 5, 1, 10);
        foreach (['ownerId', 'exerciseId', 'learnerId', 'beforeId', 'assignmentId'] as $key) { $options[$key] = $this->integer($options[$key] ?? 0, 0, PHP_INT_MAX); }
        $classIds = $this->scopeIds($options['classIds'] ?? []);
        $groupIds = $this->scopeIds($options['groupIds'] ?? []);
        $proxy = new ReadOnlyDatabase($this->database); $repo = new WpdbRepository($proxy); $lessons = new WpdbLessonRepository($proxy);
        $checks = []; $schemaReady = get_option('oj_microbit_schema_version', '') === Installer::SCHEMA_VERSION;
        $storedVersion = get_option('oj_microbit_schema_version', '');
        $checks[] = ['id' => 'schema-version', 'status' => $schemaReady ? 'pass' : 'fail', 'expected' => Installer::SCHEMA_VERSION, 'actual' => in_array($storedVersion, ['1', '2', '3', '4', '5'], true) ? $storedVersion : 'invalid'];
        $columns = [
            'exercises' => ['id', 'owner_id', 'title', 'status', 'revision', 'archived', 'audience_mode', 'data_json', 'created_at', 'updated_at'],
            'exercise_classes' => ['exercise_id', 'class_id', 'status', 'archived'],
            'exercise_groups' => ['exercise_id', 'group_id', 'status', 'archived'],
            'projects' => ['exercise_id', 'user_id', 'revision', 'data_json', 'updated_at'],
            'attempts' => ['id', 'exercise_id', 'exercise_revision', 'assignment_id', 'user_id', 'project_json', 'result_json', 'result_status', 'result_passed', 'result_score', 'created_at'],
            'assignments' => ['id', 'class_id', 'exercise_id', 'exercise_revision', 'title', 'owner_id', 'revision', 'archived', 'created_at', 'updated_at'],
            'lesson_projects' => ['assignment_id', 'user_id', 'revision', 'data_json', 'updated_at'],
            'lesson_results' => ['assignment_id', 'user_id', 'attempt_id', 'exercise_revision', 'result_status', 'result_passed', 'result_score', 'updated_at'],
            'limits' => ['scope_key', 'window_start', 'hits'],
        ];
        foreach ($columns as $table => $expected) {
            $rows = $proxy->get_results('SHOW COLUMNS FROM ' . $this->table($table), ARRAY_A);
            $missing = is_array($rows) ? array_values(array_diff($expected, array_column($rows, 'Field'))) : $expected;
            $checks[] = ['id' => 'columns-' . $table, 'status' => $missing === [] ? 'pass' : 'fail', 'missing' => $missing];
            $schemaReady = $schemaReady && $missing === [];
            $rows = $proxy->get_results('SHOW INDEX FROM ' . $this->table($table), ARRAY_A); $actual = [];
            foreach (is_array($rows) ? $rows : [] as $row) { $actual[$row['Key_name']][(int) $row['Seq_in_index']] = $row['Column_name']; }
            $missing = [];
            foreach (Installer::indexes()[$table] as $name => $fields) {
                $found = $actual[$name] ?? []; ksort($found);
                if (array_values($found) !== $fields) { $missing[] = $name; }
            }
            $checks[] = ['id' => 'indexes-' . $table, 'status' => $missing === [] ? 'pass' : 'fail', 'missing' => $missing];
            $schemaReady = $schemaReady && $missing === [];
            if ($table === 'assignments') {
                $unique = array_values(array_filter($rows, static fn (array $row): bool => ($row['Key_name'] ?? '') === 'assignment_class_exercise'));
                $valid = count($unique) === 2 && count(array_filter($unique, static fn (array $row): bool => array_key_exists('Non_unique', $row) && (string) $row['Non_unique'] === '0')) === 2;
                $checks[] = ['id' => 'unique-assignment-class-exercise', 'status' => $valid ? 'pass' : 'fail'];
                $schemaReady = $schemaReady && $valid;
            }
            $rows = $proxy->get_results($proxy->prepare('SHOW TABLE STATUS WHERE Name=%s', $this->database->prefix . 'oj_microbit_' . $table), ARRAY_A);
            $engine = is_array($rows) && count($rows) === 1 ? (string) ($rows[0]['Engine'] ?? '') : '';
            $transactional = in_array($table, ['exercises', 'exercise_classes', 'exercise_groups', 'assignments', 'lesson_projects', 'lesson_results', 'attempts'], true);
            $checks[] = ['id' => 'engine-' . $table, 'status' => strcasecmp($engine, 'InnoDB') === 0 ? 'pass' : ($transactional ? 'fail' : 'warning'), 'transactionalRequired' => $transactional, 'engine' => in_array(strtolower($engine), ['innodb', 'myisam'], true) ? strtolower($engine) : 'other'];
            if ($transactional && strcasecmp($engine, 'InnoDB') !== 0) { $schemaReady = false; }
        }
        $active = get_option('active_plugins', []);
        $checks[] = ['id' => 'plugin-active', 'status' => is_array($active) && in_array('oj-microbit/oj-microbit.php', $active, true) ? 'pass' : 'fail'];
        $settings = get_option('oj_microbit_settings', []); $settings = is_array($settings) ? $settings : [];
        $retention = $settings['retentionDays'] ?? 365;
        $checks[] = ['id' => 'retention-policy', 'status' => is_int($retention) && $retention >= 0 && $retention <= 3650 ? 'pass' : 'fail', 'days' => is_int($retention) && $retention >= 0 && $retention <= 3650 ? $retention : null];
        $checks[] = ['id' => 'maintenance-errors', 'status' => get_option('oj_microbit_cleanup_error', false) || get_option('oj_microbit_privacy_cleanup_error', false) ? 'fail' : 'pass'];
        $cron = $this->cron(); $checks[] = ['id' => 'daily-cleanup-scheduled', 'status' => $cron['cleanupScheduled'] ? 'pass' : 'warning'];
        $queryCases = []; $owner = 0; $exercise = 0; $learner = 0;
        if ($schemaReady) {
            $representative = $proxy->get_row('SELECT id,owner_id FROM ' . $this->table('exercises') . ' WHERE archived=0 ORDER BY id DESC LIMIT 1', ARRAY_A);
            $owner = $options['ownerId'] ?: (int) ($representative['owner_id'] ?? 0);
            $exercise = $options['exerciseId'] ?: (int) ($representative['id'] ?? 0);
            $learner = $options['learnerId'];
            if ($exercise > 0 && $learner === 0) {
                $row = $proxy->get_row($proxy->prepare('SELECT user_id FROM ' . $this->table('attempts') . ' WHERE exercise_id=%d ORDER BY id DESC LIMIT 1', $exercise), ARRAY_A);
                $learner = (int) ($row['user_id'] ?? 0);
            }
            $before = $options['beforeId'];
            $cases = [
                'admin-exercises' => fn () => $repo->exercises(null, false, $before, 21),
                'site-exercises' => fn () => $lessons->publicExercises($before, 21),
            ];
            if ($owner > 0) { $cases['owner-exercises'] = fn () => $repo->exercises($owner, false, $before, 21); }
            if ($classIds !== []) { $cases['class-exercises'] = fn () => $repo->learnerExercises($classIds, $before, 21); }
            else { $queryCases[] = ['id' => 'class-exercises', 'status' => 'pending', 'reason' => 'representative_class_ids_not_provided']; }
            if ($groupIds !== []) { $cases['group-exercises'] = fn () => $repo->learnerExercises([], $before, 21, $groupIds); }
            else { $queryCases[] = ['id' => 'group-exercises', 'status' => 'pending', 'reason' => 'representative_group_ids_not_provided']; }
            if ($classIds !== [] && $groupIds !== []) { $cases['mixed-context-exercises'] = fn () => $repo->learnerExercises($classIds, $before, 21, $groupIds); }
            if ($classIds !== []) {
                $cases['lesson-assignments'] = fn () => $lessons->assignments($classIds, $before, 21, false);
                $cases['learner-lesson-assignments'] = fn () => $lessons->assignments($classIds, $before, 21, true);
            }
            if ($exercise > 0) {
                $cases['exercise-summary'] = fn () => $repo->exerciseSummary($exercise);
                $cases['teacher-history'] = fn () => $repo->attempts($exercise, null, $before, 21);
                if ($learner > 0) { $cases['learner-history'] = fn () => $repo->attempts($exercise, $learner, $before, 21); }
            }
            $assignment = $options['assignmentId'];
            if ($assignment === 0) {
                $row = $proxy->get_row('SELECT id FROM ' . $this->table('assignments') . ' WHERE archived=0 ORDER BY id DESC LIMIT 1', ARRAY_A);
                $assignment = (int) ($row['id'] ?? 0);
            }
            if ($assignment > 0) {
                $cases['lesson-assignment-summary'] = fn () => $lessons->assignment($assignment);
                $cases['lesson-teacher-history'] = fn () => $lessons->lessonAttempts($assignment, null, $before, 21);
                if ($learner > 0) {
                    $cases['lesson-learner-history'] = fn () => $lessons->lessonAttempts($assignment, $learner, $before, 21);
                    $cases['lesson-results'] = fn () => $lessons->lessonResults($assignment, [$learner]);
                }
            } else { $queryCases[] = ['id' => 'lesson-results', 'status' => 'pending', 'reason' => 'representative_assignment_not_available']; }
            foreach ($cases as $name => $operation) { $queryCases[] = $this->benchmark($name, $operation, $proxy, $iterations); }
        }
        $failed = in_array('fail', array_column($checks, 'status'), true) || in_array('fail', array_column($queryCases, 'status'), true);
        return ['format' => self::FORMAT, 'ok' => !$failed, 'verdict' => $failed ? 'checks_failed' : 'manual_approval_pending', 'readOnly' => true,
            'environment' => ['phpVersion' => PHP_VERSION, 'wordpressVersion' => $this->safeVersion((string) ($GLOBALS['wp_version'] ?? '')), 'pluginVersion' => $pluginVersion,
                'databaseVersion' => $this->safeVersion((string) $proxy->get_var('SELECT VERSION()'))],
            'checks' => $checks, 'cron' => $cron, 'queryCases' => $queryCases,
            'probeScope' => ['iterations' => $iterations, 'pageLimitIncludingLookahead' => 21, 'midCursorProvided' => $options['beforeId'] > 0, 'classIdCount' => count($classIds), 'groupIdCount' => count($groupIds),
                'assignmentRepresentativeAvailable' => isset($assignment) && $assignment > 0, 'ownerRepresentativeAvailable' => $owner > 0, 'exerciseRepresentativeAvailable' => $exercise > 0, 'learnerRepresentativeAvailable' => $learner > 0,
                'dataRowsPrinted' => false, 'credentialsAccepted' => false],
            'manualPending' => ['live_public_practice_and_two_lessons_nonce_and_hidden_test_isolation', 'installation_upgrade_reboot_and_backup_restore', 'mysql_row_locks_and_failure_recovery', 'representative_classroom_and_hustoj_parallel_load', 'docker_sandbox_and_worker_cleanup', 'cron_execution_and_retention_completion']];
    }
    private function benchmark(string $name, callable $operation, ReadOnlyDatabase $proxy, int $iterations): array
    {
        $times = []; $sql = []; $maxQueries = 0; $maxRows = 0;
        if (function_exists('memory_reset_peak_usage')) { memory_reset_peak_usage(); }
        $memory = memory_get_usage(true);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $proxy->statements = []; $proxy->summaryPolicy = true; $start = hrtime(true);
            try { $result = $operation(); }
            catch (RuntimeException $error) {
                if ($error->getMessage() !== 'summary_query_policy_failed') { throw $error; }
                return ['id' => $name, 'status' => 'fail', 'reason' => 'summary_projection_or_query_budget_violation'];
            } finally { $proxy->summaryPolicy = false; }
            $times[] = (hrtime(true) - $start) / 1e6; unset($result);
            $maxQueries = max($maxQueries, count($proxy->statements));
            foreach ($proxy->statements as $statement) { $sql[$statement['sql']][] = $statement['ms']; $maxRows = max($maxRows, $statement['rows']); }
        }
        $json = false; $count = false; $offset = false; $plans = [];
        foreach ($sql as $statement => $durations) {
            $json = $json || preg_match('/\b(?:data_json|project_json|result_json)\b/i', $statement) === 1;
            $count = $count || preg_match('/\bCOUNT\s*\(/i', $statement) === 1; $offset = $offset || preg_match('/\bOFFSET\b/i', $statement) === 1;
            sort($durations, SORT_NUMERIC); $samples = count($durations);
            $plans[] = ['sqlShape' => $this->shape($statement), 'timingMs' => ['scope' => 'wpdb_query_and_fetch', 'samples' => $samples,
                'p50' => round($durations[(int) ceil($samples * .5) - 1], 3), 'p95' => round($durations[(int) ceil($samples * .95) - 1], 3)], 'plan' => $this->explain($statement, $proxy)];
        }
        sort($times, SORT_NUMERIC);
        return ['id' => $name, 'status' => !$json && !$count && !$offset && $maxQueries <= 2 ? 'pass' : 'fail',
            'queryCountMax' => $maxQueries, 'rowsPerStatementMax' => $maxRows, 'largeJsonSelected' => $json, 'countQuery' => $count, 'offsetQuery' => $offset,
            'timing' => ['scope' => 'repository_call_including_php_projection', 'iterations' => $iterations, 'p50Ms' => round($times[(int) ceil($iterations * .5) - 1], 3), 'p95Ms' => round($times[(int) ceil($iterations * .95) - 1], 3)],
            'peakMemoryDeltaKiB' => max(0, (int) ceil((memory_get_peak_usage(true) - $memory) / 1024)), 'plans' => $plans,
            'performanceApproval' => 'manual_pending_actual_launch_capacity_and_thresholds'];
    }
    private function explain(string $sql, ReadOnlyDatabase $proxy): array
    {
        $rows = $proxy->get_results('EXPLAIN ' . $sql, ARRAY_A);
        if (!is_array($rows)) { throw new RuntimeException('database_probe_failed'); }
        $knownIndexes = ['PRIMARY']; foreach (Installer::indexes() as $indexes) { array_push($knownIndexes, ...array_keys($indexes)); }
        $summary = [];
        foreach ($rows as $row) {
            $extra = strtolower((string) ($row['Extra'] ?? '')); $type = strtolower((string) ($row['type'] ?? ''));
            $summary[] = ['accessType' => in_array($type, ['system', 'const', 'eq_ref', 'ref', 'fulltext', 'ref_or_null', 'index_merge', 'unique_subquery', 'index_subquery', 'range', 'index', 'all'], true) ? $type : 'other',
                'index' => in_array($row['key'] ?? null, $knownIndexes, true) ? $row['key'] : (($row['key'] ?? null) === null ? null : 'other'),
                'estimatedRows' => isset($row['rows']) && is_numeric($row['rows']) ? max(0, (int) $row['rows']) : null,
                'fullScan' => $type === 'all', 'temporary' => str_contains($extra, 'temporary'), 'filesort' => str_contains($extra, 'filesort')];
        }
        return $summary;
    }
    private function shape(string $sql): string
    {
        foreach (array_keys(Installer::indexes()) as $table) { $sql = str_replace($this->database->prefix . 'oj_microbit_' . $table, 'oj_microbit_' . $table, $sql); }
        $sql = preg_replace_callback("/'(?:''|[^'])*'/", static fn ($match) => in_array($match[0], ["'site'", "'classes'", "'groups'", "'published'", "'draft'"], true) ? $match[0] : "'?'", $sql);
        return preg_replace('/(?<![A-Za-z_])[0-9]+(?:\.[0-9]+)?/', '?', $sql);
    }
    private function cron(): array
    {
        $cron = get_option('cron', []); $next = []; $scanned = 0;
        foreach (is_array($cron) ? $cron : [] as $timestamp => $hooks) {
            if (++$scanned > 20000) { break; }
            if (!is_numeric($timestamp) || !is_array($hooks)) { continue; }
            foreach (['oj_microbit_cleanup', 'oj_microbit_cleanup_continue', 'oj_microbit_erase_user'] as $hook) { if (isset($hooks[$hook])) { $next[$hook] = min($next[$hook] ?? PHP_INT_MAX, (int) $timestamp); } }
        }
        return ['cleanupScheduled' => isset($next['oj_microbit_cleanup']), 'continuationScheduled' => isset($next['oj_microbit_cleanup_continue']),
            'erasureRetryScheduled' => isset($next['oj_microbit_erase_user']), 'nextCleanupInSeconds' => isset($next['oj_microbit_cleanup']) ? $next['oj_microbit_cleanup'] - time() : null,
            'inspectionTruncated' => $scanned > 20000, 'executionVerified' => false];
    }
    private function table(string $name): string { return '`' . str_replace('`', '``', $this->database->prefix . 'oj_microbit_' . $name) . '`'; }
    private function scopeIds(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100) { throw new RuntimeException('invalid_probe_options'); }
        $normalized = [];
        foreach ($ids as $id) { $normalized[] = $this->integer($id, 1, PHP_INT_MAX); }
        if (count(array_unique($normalized)) !== count($normalized)) { throw new RuntimeException('invalid_probe_options'); }
        return $normalized;
    }
    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (is_int($value)) { $number = $value; }
        elseif (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)) { $number = filter_var($value, FILTER_VALIDATE_INT); }
        else { throw new RuntimeException('invalid_probe_options'); }
        if (!is_int($number) || $number < $minimum || $number > $maximum) { throw new RuntimeException('invalid_probe_options'); }
        return $number;
    }
    private function safeVersion(string $version): ?string { return preg_match('/\A([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $version, $match) ? $match[1] : null; }
}
