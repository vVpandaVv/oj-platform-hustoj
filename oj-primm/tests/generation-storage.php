<?php
declare(strict_types=1);

use OjPrimm\Storage\WpdbGenerationStore;
use OjPrimm\Storage\WpdbRepository;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "PRIMM SQL storage checks require PDO SQLite. Enable pdo_sqlite, for example:\nphp -d extension=pdo_sqlite tests/generation-storage.php\n");
    exit(1);
}
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
OjPrimm\Core\Autoloader::register();
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

/**
 * Executes production wpdb repositories against SQLite, with only SQL dialect
 * adaptation. This tests durable writes and rollback, not MySQL row-lock timing.
 */
final class GenerationSqlWpdb
{
    public string $prefix = 'test_';
    public int $insert_id = 0;
    public string $last_error = '';
    public ?Closure $beforeInsert = null;
    public ?string $failUpdateStatus = null;
    public ?string $failQuery = null;

    public function __construct(public readonly PDO $pdo) {}
    public function prepare(string $sql, mixed ...$arguments): string
    {
        $cursor = 0;
        $prepared = preg_replace_callback('/%([dsf])/', function (array $match) use (&$cursor, $arguments): string {
            if (!array_key_exists($cursor, $arguments)) { throw new LogicException('SQL placeholder argument missing.'); }
            $value = $arguments[$cursor++];
            return match ($match[1]) {
                'd' => (string) (int) $value,
                'f' => (string) (float) $value,
                default => $this->pdo->quote((string) $value),
            };
        }, $sql);
        if (!is_string($prepared) || $cursor !== count($arguments)) { throw new LogicException('SQL placeholder count mismatch.'); }
        return $prepared;
    }
    private function dialect(string $sql): string
    {
        $sql = preg_replace('/\s+FOR UPDATE\b/i', '', $sql) ?? $sql;
        return preg_match('/^\s*START TRANSACTION\s*$/i', $sql) ? 'BEGIN TRANSACTION' : $sql;
    }
    public function query(string $sql): int|false
    {
        if ($this->failQuery !== null && strcasecmp(trim($sql), $this->failQuery) === 0) {
            $this->failQuery = null; $this->last_error = 'Injected SQL failure'; return false;
        }
        try { return $this->pdo->exec($this->dialect($sql)); }
        catch (PDOException $error) { $this->last_error = $error->getMessage(); return false; }
    }
    public function get_row(string $sql, string $format = ARRAY_A): ?array
    {
        $row = $this->pdo->query($this->dialect($sql))->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
    public function get_results(string $sql, string $format = ARRAY_A): array
    {
        return $this->pdo->query($this->dialect($sql))->fetchAll(PDO::FETCH_ASSOC);
    }
    public function get_var(string $sql): mixed
    {
        $value = $this->pdo->query($this->dialect($sql))->fetchColumn();
        return $value === false ? null : $value;
    }
    public function insert(string $table, array $data): int|false
    {
        if ($this->beforeInsert !== null) { $callback = $this->beforeInsert; $this->beforeInsert = null; $callback($table, $data); }
        try {
            $columns = implode(',', array_map(static fn (string $column): string => '`' . $column . '`', array_keys($data)));
            $statement = $this->pdo->prepare('INSERT INTO `' . $table . '` (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')');
            $statement->execute(array_values($data)); $this->insert_id = (int) $this->pdo->lastInsertId();
            return $statement->rowCount();
        } catch (PDOException $error) { $this->last_error = $error->getMessage(); return false; }
    }
    public function update(string $table, array $data, array $where): int|false
    {
        if ($this->failUpdateStatus !== null && ($data['status'] ?? null) === $this->failUpdateStatus) {
            $this->failUpdateStatus = null; $this->last_error = 'Injected completion update failure'; return false;
        }
        $sets = []; $conditions = []; $arguments = array_values($data);
        foreach (array_keys($data) as $column) { $sets[] = '`' . $column . '`=?'; }
        foreach ($where as $column => $value) {
            $conditions[] = '`' . $column . '`' . ($value === null ? ' IS NULL' : '=?');
            if ($value !== null) { $arguments[] = $value; }
        }
        try {
            $statement = $this->pdo->prepare('UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE ' . implode(' AND ', $conditions));
            $statement->execute($arguments); return $statement->rowCount();
        } catch (PDOException $error) { $this->last_error = $error->getMessage(); return false; }
    }
    public function delete(string $table, array $where): int|false
    {
        $conditions = []; foreach (array_keys($where) as $column) { $conditions[] = '`' . $column . '`=?'; }
        try {
            $statement = $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE ' . implode(' AND ', $conditions));
            $statement->execute(array_values($where)); return $statement->rowCount();
        } catch (PDOException $error) { $this->last_error = $error->getMessage(); return false; }
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE test_oj_primm_generations (
    id INTEGER PRIMARY KEY AUTOINCREMENT, creator_user_id INTEGER NOT NULL, fingerprint TEXT NOT NULL,
    status TEXT NOT NULL, activity_id INTEGER NULL, error_message TEXT NOT NULL, lease_token TEXT NOT NULL,
    lease_expires_at TEXT NULL, next_attempt_at TEXT NULL, data_json TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE(creator_user_id,fingerprint))');
$pdo->exec('CREATE TABLE test_oj_primm_generation_control (scope_key TEXT PRIMARY KEY, data_json TEXT NOT NULL, updated_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE test_oj_primm_activities (
    id INTEGER PRIMARY KEY AUTOINCREMENT, creator_user_id INTEGER NOT NULL, context_type TEXT NOT NULL,
    context_id TEXT NOT NULL, title TEXT NOT NULL, data_json TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE test_oj_primm_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, activity_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
    version INTEGER NOT NULL, state_json TEXT NOT NULL, last_request_hash TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE test_oj_primm_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
    stage TEXT NOT NULL, event_type TEXT NOT NULL, payload_json TEXT NOT NULL, created_at TEXT NOT NULL)');
$db = new GenerationSqlWpdb($pdo);
$secondDb = new GenerationSqlWpdb($pdo);
$store = new WpdbGenerationStore($db);
$secondStore = new WpdbGenerationStore($secondDb);
$records = new WpdbRepository($db);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks; if (!$condition) { throw new RuntimeException($message); }
};
$rejects = static function (callable $operation, string $class, string $message) use ($assert): void {
    try { $operation(); } catch (Throwable $error) { $assert($error instanceof $class, $message . ' (exception type)'); return; }
    $assert(false, $message);
};
$jobData = static fn (int $actor, string $name): array => [
    'creator_user_id' => $actor, 'fingerprint' => hash('sha256', $name), 'request_key_hash' => hash('sha256', 'request-' . $name),
    'context_type' => 'class', 'context_id' => 'class:7', 'context_revision' => 'r1',
    'target_problem_id' => 100, 'target_hash' => str_repeat('a', 64), 'language' => 'python', 'support_kind' => 'code_ordering', 'title' => $name,
];
$activityData = static fn (int $actor, int $job): array => [
    'creator_user_id' => $actor, 'context_type' => 'class', 'context_id' => 'class:7',
    'title' => 'Generated lesson', 'generation_id' => $job, 'lesson' => ['version' => 1],
    'created_at' => '2026-10-24 10:00:00', 'updated_at' => '2026-10-24 10:00:00',
];
$countActivities = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM test_oj_primm_activities')->fetchColumn();
$now = gmdate('Y-m-d H:i:s'); $future = gmdate('Y-m-d H:i:s', time() + 300); $past = gmdate('Y-m-d H:i:s', time() - 600);
$leaseA = str_repeat('a', 48); $leaseB = str_repeat('b', 48);

$job = $store->createOrFind($jobData(1, 'unique'));
$assert($job['status'] === 'queued' && $job['creator_user_id'] === 1, 'New jobs are durable queued rows.');
$assert($store->createOrFind($jobData(1, 'unique'))['id'] === $job['id'], 'Same teacher/content fingerprint reuses the durable job.');
$assert($store->createOrFind($jobData(2, 'unique'))['id'] !== $job['id'], 'Different teachers do not share a fingerprint owner.');
$raceData = $jobData(1, 'fingerprint-race'); $winner = null;
$db->beforeInsert = static function (string $table, array $data) use ($secondStore, $raceData, &$winner): void { $winner = $secondStore->createOrFind($raceData); };
$raced = $store->createOrFind($raceData);
$assert($winner !== null && $raced['id'] === $winner['id'], 'Unique-key insert races return the already-created row.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM test_oj_primm_generations WHERE fingerprint='" . $raceData['fingerprint'] . "'")->fetchColumn() === 1, 'The SQL unique constraint prevents duplicate race rows.');
$assert($store->find(999999) === null, 'Missing job reads return null.');

$assert($store->claim($job['id'], $leaseA, $now, $future), 'One queued job may be atomically claimed.');
$assert(!$secondStore->claim($job['id'], $leaseB, $now, $future), 'A second worker cannot claim an unexpired running job.');
$store->fail($job['id'], $leaseB, 'stale failure');
$assert($store->find($job['id'])['status'] === 'running', 'A different lease cannot fail another worker\'s job.');
$called = 0;
$rejects(fn () => $secondStore->complete($job['id'], $leaseB, function () use (&$called): array { ++$called; return ['id' => 1]; }), DomainException::class, 'Mismatched leases cannot complete a job.');
$assert($called === 0 && $countActivities() === 0, 'Rejected completion cannot invoke the activity insertion callback.');
$assert($db->update('test_oj_primm_generations', ['lease_expires_at' => $past], ['id' => $job['id']]) === 1, 'Expired-worker fixture is stored.');
$assert($secondStore->claim($job['id'], $leaseB, $now, $future), 'An expired lease can be taken over by a new worker.');
$rejects(fn () => $store->complete($job['id'], $leaseA, fn (): array => $records->createActivity($activityData(1, $job['id']))), DomainException::class, 'After takeover the old token cannot publish an activity.');
$store->fail($job['id'], $leaseA, 'old worker failed');
$assert($store->find($job['id'])['lease_token'] === $leaseB, 'Old workers cannot clear the successor\'s lease.');
$complete = $secondStore->complete($job['id'], $leaseB, fn (): array => $records->createActivity($activityData(1, $job['id'])));
$assert($complete['status'] === 'ready' && $complete['activity_id'] > 0 && $countActivities() === 1, 'Activity insertion and ready status commit together.');
$assert($complete['lease_token'] === '' && $complete['lease_expires_at'] === null, 'Successful completion clears the execution lease.');
$rejects(fn () => $store->complete($job['id'], $leaseB, fn (): array => $records->createActivity($activityData(1, $job['id']))), DomainException::class, 'Repeated completion is fenced after publication.');
$assert($countActivities() === 1 && $store->retry($job['id'])['status'] === 'ready', 'Ready jobs cannot be retried into duplicate activities.');
$assert($store->retry($job['id']) === $complete, 'Retry leaves every stored field of published work unchanged.');

$expired = $store->createOrFind($jobData(1, 'expired-without-takeover'));
$store->claim($expired['id'], $leaseA, $past, $past);
$completedExpired = $store->complete($expired['id'], $leaseA, fn (): array => $records->createActivity($activityData(1, $expired['id'])));
$assert($completedExpired['status'] === 'ready', 'Expiry permits takeover; the still-current token may finish until takeover occurs.');

$rollback = $store->createOrFind($jobData(1, 'callback-rollback')); $store->claim($rollback['id'], $leaseA, $now, $future);
$before = $countActivities();
$rejects(fn () => $store->complete($rollback['id'], $leaseA, function () use ($records, $activityData, $rollback): array {
    $records->createActivity($activityData(1, $rollback['id'])); throw new DomainException('Abort after activity insert');
}), DomainException::class, 'Callback failures roll back every write in the completion transaction.');
$assert($countActivities() === $before && $store->find($rollback['id'])['status'] === 'running', 'Activity and ready update both remain absent after callback rollback.');
$db->failUpdateStatus = 'ready';
$rejects(fn () => $store->complete($rollback['id'], $leaseA, fn (): array => $records->createActivity($activityData(1, $rollback['id']))), RuntimeException::class, 'Ready-update failures roll back the callback insert.');
$assert($countActivities() === $before && $store->find($rollback['id'])['activity_id'] === null, 'Failed ready updates leave no orphan activity link.');
$db->failQuery = 'COMMIT';
$rejects(fn () => $store->complete($rollback['id'], $leaseA, fn (): array => $records->createActivity($activityData(1, $rollback['id']))), RuntimeException::class, 'Commit failures cause rollback.');
$assert($countActivities() === $before && !$pdo->inTransaction(), 'Commit failure cleanup leaves no inserted activity or open transaction.');
$store->fail($rollback['id'], $leaseA, 'Provider unavailable');
$failed = $store->find($rollback['id']);
$assert($failed['status'] === 'failed' && $failed['error'] === 'Provider unavailable' && $failed['lease_token'] === '', 'Failures persist their safe message and clear the lease.');
$assert($store->createOrFind($jobData(1, 'callback-rollback'))['id'] === $rollback['id'], 'Repeated generation requests keep failed jobs for explicit retry.');
$retried = $store->retry($rollback['id']);
$assert($retried['status'] === 'queued' && $retried['error'] === '' && $retried['lease_expires_at'] === null, 'Explicit retry resets only failed work.');
$assert(($retried['pipeline'] ?? null) === [] && preg_match('/^[a-f0-9]{32}$/D', $retried['run_key'] ?? '') === 1,
    'Retry also initializes fresh pipeline identity for pre-pipeline schema-2 jobs without adding columns.');
$assert($store->claim($rollback['id'], $leaseB, $now, $future), 'Retried work may be claimed again.');
$store->complete($rollback['id'], $leaseB, fn (): array => $records->createActivity($activityData(1, $rollback['id'])));
$assert($countActivities() === $before + 1, 'Successful retry inserts exactly one activity after all rollback cases.');
$rejects(fn () => $store->retry(999999), DomainException::class, 'Retrying missing jobs fails explicitly.');

$pipelineData = ['run_key' => str_repeat('c', 32), 'pipeline' => []] + $jobData(5, 'pipeline-checkpoint');
$pipelineJob = $store->createOrFind($pipelineData);
$pipeline = ['phase' => 'verifying', 'lesson' => ['language' => 'python', 'demo' => ['code' => "print(1)\n"]],
    'repair_attempt' => 1, 'executions' => ['demo' => ['token' => 'private-execution-ticket']],
    'results' => ['demo' => ['verdict' => 'accepted', 'actual_output' => "1\n"]], 'history' => [], 'round_started_at' => time()];
$rejects(fn () => $store->checkpoint($pipelineJob['id'], $leaseA, $pipeline), DomainException::class, 'Unclaimed queued work cannot checkpoint a private pipeline.');
$assert($store->find($pipelineJob['id'])['pipeline'] === [], 'Rejected checkpoints leave the previous pipeline untouched.');
$assert($store->claim($pipelineJob['id'], $leaseA, $now, $future), 'Pipeline generation obtains a processing lease.');
$currentData = json_decode($db->get_var($db->prepare('SELECT data_json FROM test_oj_primm_generations WHERE id=%d', $pipelineJob['id'])), true, 32, JSON_THROW_ON_ERROR);
$currentData['context_revision'] = 'r2'; $currentData['durable_marker'] = 'latest database data';
$db->update('test_oj_primm_generations', ['data_json' => json_encode($currentData, JSON_THROW_ON_ERROR)], ['id' => $pipelineJob['id']]);
$checkpoint = $store->checkpoint($pipelineJob['id'], $leaseA, $pipeline);
$assert($checkpoint['status'] === 'queued' && $checkpoint['lease_token'] === '' && $checkpoint['lease_expires_at'] === null,
    'A checkpoint commits the bounded step and releases the worker lease.');
$assert($checkpoint['pipeline'] === $pipeline && $checkpoint['run_key'] === $pipelineData['run_key'], 'Checkpoints preserve the complete private lesson, execution tickets, results and run identity.');
$assert($checkpoint['context_revision'] === 'r2' && $checkpoint['durable_marker'] === 'latest database data'
    && $checkpoint['fingerprint'] === $pipelineData['fingerprint'] && $checkpoint['target_hash'] === $pipelineData['target_hash'],
    'Pipeline updates merge into the current locked database payload without discarding context or target metadata.');
$assert(!$pdo->inTransaction() && $countActivities() === $before + 1, 'Checkpoint commit leaves no open transaction or premature activity insertion.');
$rejects(fn () => $store->checkpoint($pipelineJob['id'], $leaseA, ['phase' => 'publishing']), DomainException::class, 'The released worker cannot checkpoint again without a fresh claim.');
$assert($store->find($pipelineJob['id'])['pipeline'] === $pipeline, 'Repeated stale checkpoints cannot replace durable execution evidence.');

// Reopen a real SQLite snapshot through a new connection to model process restart.
$snapshotPath = tempnam(sys_get_temp_dir(), 'primm-checkpoint-');
if (!is_string($snapshotPath)) { throw new RuntimeException('Cannot create the durable restart fixture.'); }
$resumePdo = null; $resumeDb = null; $resumeStore = null;
try {
    $pdo->exec('VACUUM INTO ' . $pdo->quote($snapshotPath));
    $resumePdo = new PDO('sqlite:' . $snapshotPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $resumeDb = new GenerationSqlWpdb($resumePdo); $resumeStore = new WpdbGenerationStore($resumeDb);
    $resumed = $resumeStore->find($pipelineJob['id']);
    $assert($resumed !== null && $resumed['pipeline'] === $pipeline && $resumed['run_key'] === $pipelineData['run_key'],
        'A fresh database connection recovers the committed lesson and per-case execution state after restart.');
    $assert($resumeStore->claim($pipelineJob['id'], $leaseB, $now, $future), 'Restarted workers can resume a checkpointed queued step.');
    $assert($resumeStore->find($pipelineJob['id'])['pipeline']['executions']['demo']['token'] === 'private-execution-ticket',
        'Resuming work retains the existing execution ticket instead of requiring duplicate submission.');
} finally {
    $resumeStore = null; $resumeDb = null; $resumePdo = null;
    if (is_file($snapshotPath) && !unlink($snapshotPath)) { throw new RuntimeException('Cannot remove the durable restart fixture.'); }
}

$assert($store->claim($pipelineJob['id'], $leaseA, $now, $future), 'The next pipeline step claims its own current lease.');
$db->failUpdateStatus = 'queued';
$rejects(fn () => $store->checkpoint($pipelineJob['id'], $leaseA, ['phase' => 'repairing']), RuntimeException::class, 'Checkpoint update failure rolls back state and pipeline together.');
$preserved = $store->find($pipelineJob['id']);
$assert($preserved['status'] === 'running' && $preserved['lease_token'] === $leaseA && $preserved['pipeline'] === $pipeline
    && !$pdo->inTransaction(), 'Failed checkpoint updates preserve both the current lease and previous resumable ledger.');
$db->failQuery = 'COMMIT';
$rejects(fn () => $store->checkpoint($pipelineJob['id'], $leaseA, ['phase' => 'repairing']), RuntimeException::class, 'Checkpoint commit failures do not leak partially queued progress.');
$preserved = $store->find($pipelineJob['id']);
$assert($preserved['status'] === 'running' && $preserved['pipeline'] === $pipeline && !$pdo->inTransaction(), 'Checkpoint commit rollback restores the prior pipeline and closes the transaction.');
$rejects(fn () => $store->checkpoint($pipelineJob['id'], $leaseA, ['invalid_utf8' => "\xFF"]), JsonException::class, 'Unserializable pipeline data cannot erase the existing execution ledger.');
$assert($store->find($pipelineJob['id'])['pipeline'] === $pipeline && !$pdo->inTransaction(), 'JSON failures also roll back the pipeline transaction.');
$db->update('test_oj_primm_generations', ['lease_expires_at' => $past], ['id' => $pipelineJob['id']]);
$assert($secondStore->claim($pipelineJob['id'], $leaseB, $now, $future), 'A restarted successor can take over an abandoned step.');
$rejects(fn () => $store->checkpoint($pipelineJob['id'], $leaseA, ['phase' => 'publishing']), DomainException::class, 'An expired old worker cannot overwrite the successor pipeline after takeover.');
$assert($store->find($pipelineJob['id'])['lease_token'] === $leaseB && $store->find($pipelineJob['id'])['pipeline'] === $pipeline,
    'Checkpoint lease fencing protects the successor token and previously committed results.');
$nextPipeline = $pipeline; $nextPipeline['phase'] = 'repairing'; $nextPipeline['results']['modify:0'] = ['verdict' => 'wrong_answer', 'actual_output' => "2\n"];
$checkpoint = $secondStore->checkpoint($pipelineJob['id'], $leaseB, $nextPipeline);
$assert($checkpoint['pipeline'] === $nextPipeline && $checkpoint['status'] === 'queued', 'The current successor may commit the next step including failed judge evidence.');
$unchangedRetry = $store->retry($pipelineJob['id']);
$assert($unchangedRetry['run_key'] === $pipelineData['run_key'] && $unchangedRetry['pipeline'] === $nextPipeline
    && $unchangedRetry['status'] === 'queued', 'Retrying queued work preserves the current run identity and progress.');
$store->claim($pipelineJob['id'], $leaseA, $now, $future);
$unchangedRetry = $store->retry($pipelineJob['id']);
$assert($unchangedRetry['status'] === 'running' && $unchangedRetry['lease_token'] === $leaseA
    && $unchangedRetry['run_key'] === $pipelineData['run_key'] && $unchangedRetry['pipeline'] === $nextPipeline,
    'Retry cannot reset another in-progress run or its execution evidence.');
$store->fail($pipelineJob['id'], $leaseA, 'Repair budget exhausted');
$failedPipeline = $store->find($pipelineJob['id']);
$assert($failedPipeline['pipeline'] === $nextPipeline && $failedPipeline['run_key'] === $pipelineData['run_key'], 'Terminal failure retains private diagnostics for review until explicit retry.');
$db->failUpdateStatus = 'queued';
$rejects(fn () => $store->retry($pipelineJob['id']), RuntimeException::class, 'Failed retry updates roll back run identity and repair state.');
$assert($store->find($pipelineJob['id'])['status'] === 'failed' && $store->find($pipelineJob['id'])['pipeline'] === $nextPipeline
    && $store->find($pipelineJob['id'])['run_key'] === $pipelineData['run_key'] && !$pdo->inTransaction(), 'Retry update failure preserves the original failed run for another deliberate retry.');
$db->failQuery = 'COMMIT';
$rejects(fn () => $store->retry($pipelineJob['id']), RuntimeException::class, 'Retry commit failures roll back a freshly assigned run key.');
$assert($store->find($pipelineJob['id'])['status'] === 'failed' && $store->find($pipelineJob['id'])['pipeline'] === $nextPipeline
    && $store->find($pipelineJob['id'])['run_key'] === $pipelineData['run_key'] && !$pdo->inTransaction(), 'Retry commit rollback preserves prior diagnostics and closes its transaction.');
$freshRun = $store->retry($pipelineJob['id']);
$assert($freshRun['status'] === 'queued' && $freshRun['pipeline'] === [] && $freshRun['error'] === ''
    && $freshRun['lease_token'] === '' && $freshRun['lease_expires_at'] === null, 'Explicit failed-run retry resets pipeline, error and lease for a fresh repair budget.');
$assert(preg_match('/^[a-f0-9]{32}$/D', $freshRun['run_key']) === 1 && $freshRun['run_key'] !== $pipelineData['run_key'],
    'Explicit retry creates a new unpredictable run key for fresh AI and OJ idempotency keys.');
$assert($freshRun['id'] === $pipelineJob['id'] && $freshRun['fingerprint'] === $pipelineData['fingerprint']
    && $freshRun['context_revision'] === 'r2' && $freshRun['durable_marker'] === 'latest database data',
    'Starting a new run preserves the owned job, content fingerprint and latest database metadata.');
$assert($store->retry($pipelineJob['id'])['run_key'] === $freshRun['run_key'], 'Duplicate retry clicks cannot rotate the queued run key twice.');
$rejects(fn () => $store->checkpoint($pipelineJob['id'], $leaseA, $pipeline), DomainException::class, 'A worker from the previous failed run cannot checkpoint into the new queued run.');

$queuedCancel = $store->createOrFind($jobData(3, 'cancel-queued'));
$store->cancelPending($queuedCancel['id'], 'Teacher access removed');
$assert($store->find($queuedCancel['id'])['status'] === 'failed' && !$store->claim($queuedCancel['id'], $leaseA, $now, $future), 'Cancelled queued work cannot be claimed without explicit retry.');
$liveCancel = $store->createOrFind($jobData(3, 'cancel-live'));
$store->claim($liveCancel['id'], $leaseA, $now, $future); $store->cancelPending($liveCancel['id'], 'access removed');
$assert($store->find($liveCancel['id'])['status'] === 'running', 'Pending cleanup cannot cancel a currently owned unexpired lease.');
$db->update('test_oj_primm_generations', ['lease_expires_at' => $past], ['id' => $liveCancel['id']]);
$store->cancelPending($liveCancel['id'], 'Teacher access removed');
$cancelled = $store->find($liveCancel['id']);
$assert($cancelled['status'] === 'failed' && $cancelled['lease_token'] === '' && $cancelled['lease_expires_at'] === null, 'Recovery may cancel an expired unauthorized job.');
$store->cancelPending($job['id'], 'stale cancellation');
$assert($store->find($job['id'])['status'] === 'ready', 'Cancellation never modifies a completed activity.');

$all = $pdo->query('SELECT id FROM test_oj_primm_generations WHERE status="queued"')->fetchAll(PDO::FETCH_COLUMN);
foreach ($all as $id) { $store->cancelPending((int) $id, 'queue test isolation'); }
$queue = [];
for ($index = 0; $index < 12; ++$index) { $queue[] = $store->createOrFind($jobData(4, 'queue-' . $index)); }
$assert(array_column($store->pending(3), 'id') === array_column(array_slice($queue, 0, 3), 'id'), 'Pending reads return the oldest queued work in a bounded batch.');
$assert(count($store->pending(999)) === 10 && count($store->pending(0)) === 1, 'Pending batch limits remain within one to ten rows.');
$store->claim($queue[0]['id'], $leaseA, $now, $future);
$assert(!in_array($queue[0]['id'], array_column($store->pending(10), 'id'), true), 'Live leased jobs are excluded from recovery scans.');
$db->update('test_oj_primm_generations', ['lease_expires_at' => $past], ['id' => $queue[0]['id']]);
$assert((int) $store->pending(1)[0]['id'] === $queue[0]['id'], 'Expired work re-enters the recovery batch.');

$active = $pdo->query("SELECT id FROM test_oj_primm_generations WHERE status IN ('queued','running','retry_wait')")->fetchAll(PDO::FETCH_COLUMN);
foreach ($active as $id) { $store->cancel((int) $id, 'operational test isolation'); }
$limits = ['teacher_active_limit' => 2, 'site_active_limit' => 20, 'teacher_daily_ai_limit' => 3, 'site_daily_ai_limit' => 10, 'job_ai_limit' => 2];
$opA = $store->createLimited($jobData(20, 'op-a') + ['run_key' => 'original-run', 'pipeline' => ['phase' => 'verifying']], $limits);
$opB = $store->createLimited($jobData(20, 'op-b'), $limits);
$assert($store->createLimited($jobData(20, 'op-a'), $limits)['id'] === $opA['id'], 'Repeated content requests remain idempotent even when the teacher reaches the active limit.');
$rejects(fn () => $store->createLimited($jobData(20, 'op-over-limit'), $limits), DomainException::class, 'Atomic admission rejects a teacher above the active-job limit.');
$tightSite = $limits; $tightSite['site_active_limit'] = 2;
$rejects(fn () => $store->createLimited($jobData(21, 'site-over-limit'), $tightSite), DomainException::class, 'Site limits also apply across different teachers.');
$assert($store->cancel($opB['id'], 'teacher canceled')['status'] === 'canceled', 'Teacher cancellation immediately releases admission capacity.');
$opC = $store->createLimited($jobData(20, 'op-c'), $limits);
$assert($opC['status'] === 'queued', 'A teacher can enqueue replacement work after cancellation.');
$assert($store->claim($opA['id'], $leaseA, $now, $future), 'An admitted job claims its lease before reserving an AI call.');
$rejects(fn () => $store->reserveAi($opA['id'], $leaseB, $limits), DomainException::class, 'Stale workers cannot consume another worker\'s AI budget.');
$assert($store->reserveAi($opA['id'], $leaseA, $limits)['ai_calls'] === 1 && $store->reserveAi($opA['id'], $leaseA, $limits)['ai_calls'] === 2, 'AI reservations persist both the per-job call count and shared daily usage.');
$rejects(fn () => $store->reserveAi($opA['id'], $leaseA, $limits), DomainException::class, 'Per-job AI call budgets stop unlimited repairs or repeated requests.');
$store->claim($opC['id'], $leaseA, $now, $future);
$assert($store->reserveAi($opC['id'], $leaseA, $limits)['ai_calls'] === 1, 'Daily usage is shared by the teacher across jobs.');
$rejects(fn () => $store->reserveAi($opC['id'], $leaseA, $limits), \OjPrimm\Generation\TransientGenerationException::class, 'Teacher daily limits defer further AI work without granting a new call.');
$control = json_decode($db->get_var("SELECT data_json FROM test_oj_primm_generation_control WHERE scope_key='queue'"), true, 32, JSON_THROW_ON_ERROR);
$assert($control['ai_calls'] === 3 && $control['teachers'][20] === 3 && $store->find($opC['id'])['usage']['ai_calls'] === 1, 'Rejected reservations roll back shared counters and leave the job usage unchanged.');
$retryAt = gmdate('Y-m-d H:i:s', time() + 300);
$deferred = $store->defer($opA['id'], $leaseA, 'temporary provider failure', $retryAt, ['code' => 'addon_ai_request_pending', 'retryable' => true]);
$assert($deferred['status'] === 'retry_wait' && $deferred['next_attempt_at'] === $retryAt && $deferred['transient_failures'] === 1
    && $deferred['pipeline']['phase'] === 'verifying' && $deferred['last_failure']['code'] === 'addon_ai_request_pending', 'Transient failure preserves the resumable pipeline, typed code and next attempt time.');
$assert(!$store->claim($opA['id'], $leaseB, $now, $future) && !in_array($opA['id'], array_map('intval', array_column($store->pending(10), 'id')), true), 'Backoff is enforced by both admission to a worker and the recovery scan.');
$db->update('test_oj_primm_generations', ['next_attempt_at' => $past], ['id' => $opA['id']]);
$assert($secondStore->claim($opA['id'], $leaseB, $now, $future) && $secondStore->find($opA['id'])['pipeline']['phase'] === 'verifying', 'After backoff expires a successor resumes the saved phase without resetting the run.');
$stopped = $store->cancel($opA['id'], 'administrator stopped', true);
$assert($stopped['status'] === 'stopped' && $stopped['lease_token'] === '' && $stopped['run_key'] === 'original-run', 'Administrative stop fences current workers while preserving the run identity.');
$rejects(fn () => $store->checkpoint($opA['id'], $leaseB, ['phase' => 'publishing']), DomainException::class, 'An in-flight stopped worker cannot later overwrite the stopped job.');
$resumedOp = $store->resume($opA['id']);
$assert($resumedOp['status'] === 'queued' && $resumedOp['run_key'] === 'original-run' && $resumedOp['usage']['ai_calls'] === 2
    && $resumedOp['pipeline']['phase'] === 'verifying', 'Resume retains charged usage, old tickets and repair budget instead of regenerating the lesson.');
$store->claim($opA['id'], $leaseB, $now, $future);
$store->failDetailed($opA['id'], $leaseB, 'uncertain remote outcome', ['code' => 'addon_ai_outcome_uncertain', 'retryable' => false]);
$terminal = $store->find($opA['id']);
$assert($terminal['status'] === 'failed' && !$terminal['last_failure']['retryable'] && $terminal['last_failure']['code'] === 'addon_ai_outcome_uncertain', 'Uncertain non-retryable outcomes retain their code and stop automatically retrying.');
$summary = $store->overview(20);
$assert(count($summary['jobs']) === 3 && $summary['usage']['ai_calls'] === 3 && !array_filter($summary['jobs'], static fn (array $row): bool => $row['creator_user_id'] !== 20), 'Usage summaries include only the requesting teacher\'s jobs.');
$db->failQuery = 'COMMIT';
$rejects(fn () => $store->createLimited($jobData(22, 'rolled-back-admission'), $limits), RuntimeException::class, 'Atomic admission rolls back a new job when the control transaction cannot commit.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM test_oj_primm_generations WHERE creator_user_id=22")->fetchColumn() === 0 && !$pdo->inTransaction(), 'Failed admission leaves no charged slot or orphan job.');
$store->cancel($opC['id'], 'fair queue isolation');
$fair = [];
foreach ([[30, 'first-a'], [30, 'second-a'], [31, 'first-b'], [32, 'first-c']] as [$owner, $name]) { $fair[] = $store->createLimited($jobData($owner, $name), $limits); }
$assert(array_map('intval', array_column($store->pending(3), 'creator_user_id')) === [30, 31, 32], 'Fair queue selects one ready step per teacher before returning to a teacher\'s second job.');

$exported = $records->exportUser(1);
$assert(count($exported['generations']) >= 3 && isset($exported['activities'], $exported['attempts'], $exported['events']), 'Privacy export includes the generation queue alongside learning records.');
$assert(array_filter($exported['generations'], static fn (array $row): bool => (int) $row['creator_user_id'] !== 1) === [], 'Privacy export is scoped to the requesting creator.');
$other = $store->createOrFind($jobData(9, 'other-user'));
$records->eraseUser(1);
$assert($store->find($job['id']) === null && $store->find($other['id']) !== null, 'Personal erasure removes only that teacher\'s generation queue.');
$anonymized = $records->findActivity($complete['activity_id']);
$assert($anonymized !== null && $anonymized['creator_user_id'] === 0, 'Erasure preserves shared activities but anonymizes their creator.');
$rawActivity = $pdo->query('SELECT data_json FROM test_oj_primm_activities WHERE id=' . $complete['activity_id'])->fetchColumn();
$assert(json_decode($rawActivity, true, 32, JSON_THROW_ON_ERROR)['creator_user_id'] === 0, 'Stored activity JSON is anonymized as well as its owner column.');

$oldFailed = $store->createOrFind($jobData(10, 'retention-failed')); $store->cancelPending($oldFailed['id'], 'expired');
$oldReady = $store->createOrFind($jobData(10, 'retention-ready')); $store->claim($oldReady['id'], $leaseA, $now, $future);
$readyActivity = $store->complete($oldReady['id'], $leaseA, fn (): array => $records->createActivity($activityData(10, $oldReady['id'])));
$oldQueued = $store->createOrFind($jobData(10, 'retention-queued'));
$oldRunning = $store->createOrFind($jobData(10, 'retention-running')); $store->claim($oldRunning['id'], $leaseA, $now, $future);
foreach ([$oldFailed, $oldReady, $oldQueued, $oldRunning] as $old) { $db->update('test_oj_primm_generations', ['updated_at' => '2000-01-01 00:00:00'], ['id' => $old['id']]); }
$records->purgeBefore('2020-01-01 00:00:00');
$assert($store->find($oldFailed['id']) === null && $store->find($oldReady['id']) === null, 'Retention removes old terminal generation records.');
$assert($store->find($oldQueued['id']) !== null && $store->find($oldRunning['id']) !== null, 'Retention never deletes queued or running work.');
$assert($records->findActivity($readyActivity['activity_id']) !== null, 'Retention of generation history preserves the published lesson.');

$GLOBALS['primm_generation_storage_delete'] = false; $GLOBALS['primm_generation_storage_options'] = [];
function get_option(string $name, mixed $default = null): mixed { return $name === 'oj_primm_delete_on_uninstall' ? $GLOBALS['primm_generation_storage_delete'] : $default; }
function delete_option(string $name): void { $GLOBALS['primm_generation_storage_options'][] = $name; }
defined('WP_UNINSTALL_PLUGIN') || define('WP_UNINSTALL_PLUGIN', true);
$wpdb = $db;
require dirname(__DIR__) . '/uninstall.php';
$assert($store->find($other['id']) !== null, 'Ordinary plugin removal preserves generation and learning records.');
$GLOBALS['primm_generation_storage_delete'] = true;
require dirname(__DIR__) . '/uninstall.php';
$remaining = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'test_oj_primm_%'")->fetchAll(PDO::FETCH_COLUMN);
$assert($remaining === [], 'Explicit uninstall cleanup drops generation, activity, attempt and event tables.');
$assert(in_array('oj_primm_schema_version', $GLOBALS['primm_generation_storage_options'], true), 'Explicit uninstall cleanup removes the migration marker.');

echo 'PRIMM production SQL storage checks passed: ' . $checks . " (PDO SQLite dialect adapter; MySQL locks require live integration)\n";
